import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore, FieldValue } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

function readStdin() {
  return new Promise((resolve, reject) => {
    let input = ''
    process.stdin.setEncoding('utf8')
    process.stdin.on('data', (chunk) => {
      input += chunk
    })
    process.stdin.on('end', () => resolve(input))
    process.stdin.on('error', reject)
  })
}

function requireString(value, label) {
  const text = String(value || '').trim()
  if (text === '') {
    throw new Error(`${label}_required`)
  }
  return text
}

function cleanObject(value) {
  return Object.fromEntries(
    Object.entries(value).filter(([, item]) => item !== undefined),
  )
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length > 0) return
  if (serviceAccountPath !== '') {
    initializeApp({
      credential: cert(serviceAccountPath),
      projectId,
    })
    return
  }
  initializeApp({ projectId })
}

function normalizeDocumentKey(value) {
  const key = requireString(value, 'group_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(key)) {
    throw new Error('group_key_invalid_firebase_document_id')
  }
  return key
}

function normalizeStatus(value, fallback = 'ACTIVE') {
  const status = String(value || fallback).trim().toUpperCase()
  return status === '' ? fallback : status
}

function normalizeStringList(rows, mapper) {
  return Array.isArray(rows)
    ? rows.filter((row) => row && typeof row === 'object').map(mapper)
    : []
}

function normalizePosition(row) {
  return cleanObject({
    position_key: String(row.position_key || '').trim(),
    project_key: String(row.project_key || '').trim(),
    group_key: String(row.group_key || '').trim(),
    position_code: String(row.position_code || '').trim(),
    position_name: String(row.position_name || '').trim(),
    position_description: String(row.position_description || '').trim(),
    position_status: normalizeStatus(row.position_status),
    mysql_created_at: String(row.position_created_at || '').trim(),
    mysql_updated_at: String(row.position_updated_at || '').trim(),
  })
}

function normalizeMember(row) {
  return cleanObject({
    user_key: String(row.user_key || '').trim(),
    project_key: String(row.project_key || '').trim(),
    group_key: String(row.group_key || '').trim(),
    position_key: String(row.position_key || '').trim(),
    user_login: String(row.user_login || '').trim(),
    user_name: String(row.user_name || '').trim(),
    user_chat_name: String(row.user_chat_name || '').trim(),
    user_mobile_number: String(row.user_mobile_number || '').trim(),
    user_avatar_path: String(row.user_avatar_path || '').trim(),
    user_status: normalizeStatus(row.user_status),
    position_code: String(row.position_code || '').trim(),
    position_name: String(row.position_name || '').trim(),
  })
}

function normalizeRow(row) {
  const documentKey = normalizeDocumentKey(row.group_key)
  const positions = normalizeStringList(row.positions, normalizePosition)
  const members = normalizeStringList(row.members, normalizeMember)

  return {
    documentKey,
    data: cleanObject({
      group_key: documentKey,
      project_key: String(row.project_key || '').trim(),
      project_code: String(row.project_code || '').trim(),
      project_name: String(row.project_name || '').trim(),
      group_name: String(row.group_name || '').trim(),
      group_description: String(row.group_description || '').trim(),
      group_image_path: String(row.group_image_path || '').trim(),
      group_image_original_name: String(row.group_image_original_name || '').trim(),
      group_image_mime_type: String(row.group_image_mime_type || '').trim(),
      group_image_byte_size: Number(row.group_image_byte_size || 0),
      group_image_sha256: String(row.group_image_sha256 || '').trim(),
      group_image_uploaded_at: String(row.group_image_uploaded_at || '').trim(),
      group_status: normalizeStatus(row.group_status),
      position_count: positions.length,
      member_count: members.length,
      positions,
      members,
      mysql_created_at: String(row.group_created_at || '').trim(),
      mysql_updated_at: String(row.group_updated_at || '').trim(),
      firebase_collection: 'project_user_group',
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

async function commitRows(db, rows) {
  let synced = 0
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeRow(row || {})
      batch.set(db.collection('project_user_group').doc(normalized.documentKey), normalized.data, { merge: true })
      synced++
    }
    await batch.commit()
  }
  return synced
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const rows = Array.isArray(payload.rows)
    ? payload.rows
    : (payload.row && typeof payload.row === 'object' ? [payload.row] : [])
  if (rows.length < 1) {
    throw new Error('project_user_group_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const synced = await commitRows(db, rows)
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_user_group',
    synced,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_project_user_group_sync_failed',
  }))
  process.exitCode = 1
})
