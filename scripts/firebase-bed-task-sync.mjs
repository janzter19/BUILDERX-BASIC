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

function cleanObject(value) {
  return Object.fromEntries(
    Object.entries(value).filter(([, item]) => item !== undefined),
  )
}

function requireString(value, label) {
  const text = String(value || '').trim()
  if (text === '') {
    throw new Error(`${label}_required`)
  }
  return text
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

function normalizeTask(row) {
  const bedTaskKey = requireString(row.bed_task_key, 'bed_task_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(bedTaskKey)) {
    throw new Error('bed_task_key_invalid_firebase_document_id')
  }

  return {
    documentKey: bedTaskKey,
    data: cleanObject({
      bed_task_key: bedTaskKey,
      bed_key: String(row.bed_key || ''),
      bed_source_key: String(row.bed_source_key || ''),
      source_pk_psbeds: String(row.source_pk_psbeds || ''),
      bed_no: String(row.bed_no || ''),
      task_key: String(row.task_key || ''),
      task_code: String(row.task_code || ''),
      task_title: String(row.task_title || ''),
      task_type: String(row.task_type || 'PRIMARY'),
      task_status: String(row.task_status || 'PENDING'),
      bed_status_at_request: String(row.bed_status_at_request || ''),
      bed_class: String(row.bed_class || ''),
      bed_treatment_key: String(row.bed_treatment_key || ''),
      bed_treatment_name: String(row.bed_treatment_name || ''),
      bed_source_option_key: String(row.bed_source_option_key || ''),
      bed_source_option_name: String(row.bed_source_option_name || ''),
      remarks: String(row.remarks || ''),
      requester_user_key: String(row.requester_user_key || ''),
      requester_fullname: String(row.requester_fullname || ''),
      firebase_collection: 'project_bed_task',
      mysql_created_at: String(row.created_at || ''),
      mysql_updated_at: String(row.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

function normalizeLog(row) {
  const bedTaskLogKey = requireString(row.bed_task_log_key, 'bed_task_log_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(bedTaskLogKey)) {
    throw new Error('bed_task_log_key_invalid_firebase_document_id')
  }

  return {
    documentKey: bedTaskLogKey,
    data: cleanObject({
      bed_task_log_key: bedTaskLogKey,
      bed_task_key: String(row.bed_task_key || ''),
      bed_key: String(row.bed_key || ''),
      bed_source_key: String(row.bed_source_key || ''),
      source_pk_psbeds: String(row.source_pk_psbeds || ''),
      bed_no: String(row.bed_no || ''),
      task_key: String(row.task_key || ''),
      task_code: String(row.task_code || ''),
      task_title: String(row.task_title || ''),
      task_type: String(row.task_type || 'PRIMARY'),
      event_type: String(row.event_type || 'UPDATED'),
      status_from: String(row.status_from || ''),
      status_to: String(row.status_to || ''),
      bed_status_at_request: String(row.bed_status_at_request || ''),
      bed_class: String(row.bed_class || ''),
      bed_treatment_key: String(row.bed_treatment_key || ''),
      bed_treatment_name: String(row.bed_treatment_name || ''),
      bed_source_option_key: String(row.bed_source_option_key || ''),
      bed_source_option_name: String(row.bed_source_option_name || ''),
      remarks: String(row.remarks || ''),
      requester_user_key: String(row.requester_user_key || ''),
      requester_fullname: String(row.requester_fullname || ''),
      actor_user_key: String(row.actor_user_key || ''),
      actor_fullname: String(row.actor_fullname || ''),
      firebase_collection: 'project_bed_task_log',
      mysql_created_at: String(row.created_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const taskRows = Array.isArray(payload.rows)
    ? payload.rows
    : (payload.task && typeof payload.task === 'object' ? [payload.task] : [])
  const logRows = Array.isArray(payload.logs) ? payload.logs : []

  if (taskRows.length < 1 && logRows.length < 1) {
    throw new Error('bed_task_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const batch = db.batch()
  let synced = 0
  let logSynced = 0

  for (const row of taskRows) {
    const normalized = normalizeTask(row || {})
    batch.set(db.collection('project_bed_task').doc(normalized.documentKey), normalized.data, { merge: true })
    synced++
  }

  for (const row of logRows) {
    const normalized = normalizeLog(row || {})
    batch.set(db.collection('project_bed_task_log').doc(normalized.documentKey), normalized.data, { merge: true })
    logSynced++
  }

  await batch.commit()
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_bed_task',
    log_collection: 'project_bed_task_log',
    synced,
    log_synced: logSynced,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_bed_task_sync_failed',
  }))
  process.exitCode = 1
})
