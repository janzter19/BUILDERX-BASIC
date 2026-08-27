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
  const key = requireString(value, 'user_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(key)) {
    throw new Error('user_key_invalid_firebase_document_id')
  }
  return key
}

function normalizeStatus(value, fallback = 'ACTIVE') {
  const status = String(value || fallback).trim().toUpperCase()
  return status === '' ? fallback : status
}

function normalizeProjectKey(value) {
  const projectKey = requireString(value, 'project_key').toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(projectKey)) {
    throw new Error('project_key_invalid')
  }
  return projectKey
}

function normalizeUsername(value) {
  const username = requireString(value, 'username').toLowerCase()
  if (/\s/.test(username) || !/^[a-z0-9._-]+$/.test(username)) {
    throw new Error('project_username_invalid')
  }
  return username
}

function normalizeRow(row) {
  const documentKey = normalizeDocumentKey(row.user_key)
  const projectKey = normalizeProjectKey(row.project_key)
  const username = normalizeUsername(row.user_login)
  const authEmail = requireString(row.user_auth_email, 'user_auth_email').toLowerCase()
  const status = normalizeStatus(row.user_status)
  const permissions = Array.isArray(row.permissions)
    ? row.permissions.map((value) => String(value || '').trim()).filter(Boolean)
    : []

  return {
    documentKey,
    indexDocumentKey: `${projectKey}_${username}`,
    indexData: {
      project_key: projectKey,
      username,
      auth_email: authEmail,
      status,
      locked: status === 'LOCKED',
      deleted: status === 'DELETED',
    },
    data: cleanObject({
      user_key: documentKey,
      firebase_uid: documentKey,
      project_key: projectKey,
      project_code: String(row.project_code || '').trim(),
      project_name: String(row.project_name || '').trim(),
      user_login: username,
      user_auth_username: String(row.user_auth_username || '').trim(),
      user_auth_email: authEmail,
      user_name: String(row.user_name || '').trim(),
      user_chat_name: String(row.user_chat_name || '').trim(),
      user_mobile_number: String(row.user_mobile_number || '').trim(),
      user_avatar_path: String(row.user_avatar_path || '').trim(),
      user_avatar_original_name: String(row.user_avatar_original_name || '').trim(),
      user_avatar_mime_type: String(row.user_avatar_mime_type || '').trim(),
      user_avatar_byte_size: Number(row.user_avatar_byte_size || 0),
      user_avatar_sha256: String(row.user_avatar_sha256 || '').trim(),
      user_avatar_uploaded_at: String(row.user_avatar_uploaded_at || '').trim(),
      user_status: status,
      user_password_change_required: String(row.user_password_change_required || '').trim() === '1' || row.user_password_change_required === true,
      user_disabled: status !== 'ACTIVE',
      user_deleted: status === 'DELETED',
      user_locked: status === 'LOCKED',
      permissions,
      user_last_login_at: String(row.user_last_login_at || '').trim(),
      user_last_login_ip_address: String(row.user_last_login_ip_address || '').trim(),
      user_last_login_device: String(row.user_last_login_device || '').trim(),
      user_last_logout_at: String(row.user_last_logout_at || '').trim(),
      user_last_logout_ip_address: String(row.user_last_logout_ip_address || '').trim(),
      user_last_logout_device: String(row.user_last_logout_device || '').trim(),
      mysql_created_at: String(row.user_created_at || '').trim(),
      mysql_updated_at: String(row.user_updated_at || '').trim(),
      firebase_collection: 'project_user',
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

function assertLoginIndexReadBack(snapshot, expected) {
  if (!snapshot.exists) {
    throw new Error('project_login_index_new_document_readback_failed')
  }
  const actual = snapshot.data() || {}
  const expectedKeys = Object.keys(expected).sort()
  const actualKeys = Object.keys(actual).sort()
  if (JSON.stringify(actualKeys) !== JSON.stringify(expectedKeys)) {
    throw new Error('project_login_index_field_whitelist_readback_failed')
  }
  for (const key of expectedKeys) {
    if (actual[key] !== expected[key]) {
      throw new Error('project_login_index_new_document_readback_failed')
    }
  }
}

function assertProjectUserReadBack(snapshot, expected) {
  if (!snapshot.exists) {
    throw new Error('project_user_readback_failed')
  }
  const actual = snapshot.data() || {}
  if (actual.user_key !== expected.user_key ||
      actual.project_key !== expected.project_key ||
      actual.user_status !== expected.user_status ||
      actual.user_password_change_required !== expected.user_password_change_required ||
      actual.locked !== expected.locked ||
      actual.deleted !== expected.deleted) {
    throw new Error('project_user_status_readback_failed')
  }
}

async function commitRows(db, rows, previousUsername = '') {
  let synced = 0
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeRow(row || {})
      batch.set(db.collection('project_user').doc(normalized.documentKey), normalized.data, { merge: true })
      const previousUsernameValue = previousUsername === '' ? '' : normalizeUsername(previousUsername)
      const previousIndexDocumentKey = previousUsernameValue !== '' && previousUsernameValue !== normalized.data.user_login
        ? `${normalized.data.project_key}_${previousUsernameValue}`
        : ''
      if (previousIndexDocumentKey !== '') {
        batch.delete(db.collection('project_login_index').doc(previousIndexDocumentKey))
      }
      batch.set(
        db.collection('project_login_index').doc(normalized.indexDocumentKey),
        normalized.indexData,
      )
      synced++
    }
    await batch.commit()
    for (const row of batchRows) {
      const normalized = normalizeRow(row || {})
      const profileSnapshot = await db.collection('project_user').doc(normalized.documentKey).get()
      assertProjectUserReadBack(profileSnapshot, normalized.data)
      const newSnapshot = await db.collection('project_login_index').doc(normalized.indexDocumentKey).get()
      assertLoginIndexReadBack(newSnapshot, normalized.indexData)
      const previousUsernameValue = previousUsername === '' ? '' : normalizeUsername(previousUsername)
      const previousIndexDocumentKey = previousUsernameValue !== '' && previousUsernameValue !== normalized.data.user_login
        ? `${normalized.data.project_key}_${previousUsernameValue}`
        : ''
      if (previousIndexDocumentKey !== '') {
        const oldSnapshot = await db.collection('project_login_index').doc(previousIndexDocumentKey).get()
        if (oldSnapshot.exists) {
          throw new Error('project_login_index_old_document_delete_readback_failed')
        }
      }
    }
  }
  return synced
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const previousUsername = String(payload.previous_username || '').trim()
  const rows = Array.isArray(payload.rows)
    ? payload.rows
    : (payload.row && typeof payload.row === 'object' ? [payload.row] : [])
  if (rows.length < 1) {
    throw new Error('project_user_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const synced = await commitRows(db, rows, previousUsername)
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_user',
    synced,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_project_user_sync_failed',
  }))
  process.exitCode = 1
})
