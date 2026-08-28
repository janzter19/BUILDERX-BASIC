import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const readStdin = () => new Promise((resolve, reject) => {
  let input = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', (chunk) => { input += chunk })
  process.stdin.on('end', () => resolve(input))
  process.stdin.on('error', reject)
})
const required = (value, name) => {
  const text = String(value ?? '').trim()
  if (text === '') throw new Error(`${name}_required`)
  return text
}
const projectKey = (value) => {
  const key = required(value, 'project_key').toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(key)) throw new Error('project_key_invalid')
  return key
}
const documentKey = (value, name) => {
  const key = String(value || '').trim()
  if (key !== '' && !/^[A-Za-z0-9]{20,40}$/.test(key)) throw new Error(`${name}_invalid_firebase_document_id`)
  return key
}
const status = (value) => {
  const result = String(value || 'ACTIVE').trim().toUpperCase()
  if (!['ACTIVE', 'INACTIVE', 'DELETED'].includes(result)) throw new Error('position_status_invalid')
  return result
}
const mysqlTimestamp = (value = new Date()) => { const date = value instanceof Date ? value : new Date(value); if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid'); const pad = (number, length = 2) => String(number).padStart(length, '0'); return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000` }

function initializeFirebase(firebaseProjectId, serviceAccountPath) {
  if (getApps().length > 0) return
  initializeApp(serviceAccountPath === ''
    ? { projectId: firebaseProjectId }
    : { credential: cert(serviceAccountPath), projectId: firebaseProjectId })
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const firebaseProjectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const record = payload.record && typeof payload.record === 'object' ? payload.record : {}
  const collection = String(payload.collection || '').trim()
  if (collection !== 'project_position') throw new Error('reference_collection_not_allowed')
  const existingKey = documentKey(record.position_key, 'position_key')
  const project = projectKey(record.project_key)
  const group = documentKey(record.group_key, 'group_key')
  if (group === '') throw new Error('group_key_required')
  const positionCode = required(record.position_code, 'position_code')
  const positionName = required(record.position_name, 'position_name')
  const positionStatus = status(record.position_status)

  initializeFirebase(firebaseProjectId, serviceAccountPath)
  const db = getFirestore()
  const collectionRef = db.collection(collection)
  const ref = existingKey === '' ? collectionRef.doc() : collectionRef.doc(existingKey)
  const previous = await ref.get()
  const now = mysqlTimestamp()
  const data = {
    position_key: ref.id,
    project_key: project,
    group_key: group,
    position_code: positionCode,
    position_name: positionName,
    position_description: String(record.position_description || '').trim(),
    position_status: positionStatus,
    firebase_collection: collection,
    mysql_created_at: previous.exists ? mysqlTimestamp(previous.get('mysql_created_at') || previous.get('created_at') || now) : now,
    mysql_updated_at: now,
    mysql_deleted_at: positionStatus === 'DELETED' ? now : null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
    created_at: previous.exists ? (previous.get('created_at') || now) : now,
    updated_at: now,
  }
  await ref.set(data)
  const readBack = await ref.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.position_key !== ref.id || actual.project_key !== project || actual.group_key !== group || actual.position_status !== positionStatus || actual.mysql_sync_status !== 'PENDING') {
    throw new Error('project_position_firebase_readback_failed')
  }
  process.stdout.write(JSON.stringify({ ok: true, position_key: ref.id, firebase_collection: collection, mysql_sync_status: 'PENDING', action: previous.exists ? 'updated' : 'created' }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_project_position_write_failed' }))
})
