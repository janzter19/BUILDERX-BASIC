import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore, FieldValue } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const references = {
  treatment: {
    collection: 'project_bed_treatment',
    key: 'bed_treatment_key',
    code: 'treatment_code',
    name: 'treatment_name',
    description: 'treatment_description',
    status: 'treatment_status',
    sort: 'treatment_sort_order',
  },
  source: {
    collection: 'project_bed_source',
    key: 'bed_source_key',
    code: 'bed_source_code',
    name: 'bed_source_name',
    description: 'bed_source_description',
    status: 'bed_source_status',
    sort: 'bed_source_sort_order',
  },
}

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

function normalizeRow(reference, row) {
  const documentKey = requireString(row[reference.key], reference.key)
  if (!/^[A-Za-z0-9]{20}$/.test(documentKey)) {
    throw new Error(`${reference.key}_invalid_firebase_document_id`)
  }

  return {
    documentKey,
    data: cleanObject({
      [reference.key]: documentKey,
      [reference.code]: String(row[reference.code] || '').trim(),
      [reference.name]: String(row[reference.name] || '').trim(),
      [reference.description]: String(row[reference.description] || '').trim(),
      [reference.status]: String(row[reference.status] || 'ACTIVE').trim().toUpperCase(),
      [reference.sort]: Number(row[reference.sort] || 0),
      firebase_collection: reference.collection,
      mysql_created_at: String(row.created_at || ''),
      mysql_updated_at: String(row.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

async function commitRows(db, reference, rows) {
  let synced = 0
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeRow(reference, row || {})
      batch.set(db.collection(reference.collection).doc(normalized.documentKey), normalized.data, { merge: true })
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
  const type = String(payload.reference_type || '').trim().toLowerCase()
  const reference = references[type]
  if (!reference) {
    throw new Error('bed_reference_type_invalid')
  }

  const rows = Array.isArray(payload.rows)
    ? payload.rows
    : (payload.row && typeof payload.row === 'object' ? [payload.row] : [])
  if (rows.length < 1) {
    throw new Error('bed_reference_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const synced = await commitRows(db, reference, rows)
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: reference.collection,
    reference_type: type,
    synced,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_bed_reference_sync_failed',
  }))
  process.exitCode = 1
})
