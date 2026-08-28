import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const text = (value) => String(value ?? '').trim()
const required = (value, name) => {
  const result = text(value)
  if (!result) throw new Error(`${name}_required`)
  return result
}
const key = (value, name) => {
  const result = text(value)
  if (result && !/^[A-Za-z0-9]{20}$/.test(result)) throw new Error(`${name}_invalid_firebase_document_id`)
  return result
}
const status = (value) => {
  const result = text(value || 'ACTIVE').toUpperCase()
  if (!['ACTIVE', 'INACTIVE', 'DELETED'].includes(result)) throw new Error('bed_treatment_status_invalid')
  return result
}
const mysqlTimestamp = (value = new Date()) => {
  const date = value?.toDate instanceof Function ? value.toDate() : new Date(value)
  if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid')
  const pad = (n, size = 2) => String(n).padStart(size, '0')
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000`
}

const readStdin = () => new Promise((resolve, reject) => {
  let input = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', (chunk) => { input += chunk })
  process.stdin.on('end', () => resolve(input))
  process.stdin.on('error', reject)
})

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length) return
  initializeApp(serviceAccountPath ? { credential: cert(serviceAccountPath), projectId } : { projectId })
}

async function save(db, record) {
  const collection = db.collection('project_bed_treatment')
  const requestedKey = key(record.bed_treatment_key, 'bed_treatment_key')
  const ref = requestedKey ? collection.doc(requestedKey) : collection.doc()
  const previousSnapshot = await ref.get()
  const previous = previousSnapshot.exists ? previousSnapshot.data() || {} : {}
  const code = text(record.treatment_code || previous.treatment_code).toUpperCase()
  const name = text(record.treatment_name || previous.treatment_name)
  if (!code || !/^[A-Z0-9_-]{2,80}$/.test(code)) throw new Error('treatment_code_invalid')
  if (!name || name.length > 160) throw new Error('treatment_name_invalid')
  const treatmentStatus = status(record.treatment_status || previous.treatment_status)
  const duplicateSnapshot = await collection.where('treatment_code', '==', code).get()
  if (duplicateSnapshot.docs.some((item) => item.id !== ref.id && text(item.get('treatment_status')).toUpperCase() !== 'DELETED')) throw new Error('treatment_code_duplicate')
  let sortOrder = Number(record.treatment_sort_order ?? previous.treatment_sort_order ?? 0)
  if (!Number.isInteger(sortOrder) || sortOrder < 0) throw new Error('treatment_sort_order_invalid')
  if (!previousSnapshot.exists && sortOrder === 0) {
    const snapshot = await collection.orderBy('treatment_sort_order', 'desc').limit(1).get()
    sortOrder = snapshot.empty ? 1 : Number(snapshot.docs[0].get('treatment_sort_order') || 0) + 1
  }
  const now = new Date()
  const data = {
    bed_treatment_key: ref.id,
    treatment_code: code,
    treatment_name: name,
    treatment_description: text(record.treatment_description || previous.treatment_description),
    treatment_status: treatmentStatus,
    treatment_sort_order: sortOrder,
    firebase_collection: 'project_bed_treatment',
    firebase_created_at: previous.firebase_created_at || now,
    firebase_updated_at: FieldValue.serverTimestamp(),
    firebase_deleted_at: treatmentStatus === 'DELETED' ? FieldValue.serverTimestamp() : null,
    mysql_created_at: previous.mysql_created_at ? mysqlTimestamp(previous.mysql_created_at) : mysqlTimestamp(now),
    mysql_updated_at: mysqlTimestamp(now),
    mysql_deleted_at: treatmentStatus === 'DELETED' ? mysqlTimestamp(now) : null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
  }
  await ref.set(data)
  const readBack = await ref.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.bed_treatment_key !== ref.id || actual.firebase_collection !== 'project_bed_treatment' || actual.mysql_sync_status !== 'PENDING') throw new Error('project_bed_treatment_firebase_readback_failed')
  return { key: ref.id, action: previousSnapshot.exists ? 'updated' : 'created', status: treatmentStatus, code, name }
}

async function reorder(db, keys) {
  if (!Array.isArray(keys) || keys.length === 0) throw new Error('bed_treatment_order_required')
  const refs = keys.map((value) => db.collection('project_bed_treatment').doc(key(value, 'bed_treatment_key')))
  const snapshots = await Promise.all(refs.map((ref) => ref.get()))
  if (snapshots.some((snapshot) => !snapshot.exists)) throw new Error('bed_treatment_order_document_missing')
  const now = mysqlTimestamp()
  const batch = db.batch()
  refs.forEach((ref, index) => batch.update(ref, { treatment_sort_order: index + 1, firebase_updated_at: FieldValue.serverTimestamp(), mysql_updated_at: now, mysql_synced_at: null, mysql_sync_status: 'PENDING' }))
  await batch.commit()
  return { key_count: keys.length, action: 'reordered', mysql_sync_status: 'PENDING' }
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = text(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH)
  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const result = payload.operation === 'reorder' ? await reorder(db, payload.order_keys) : await save(db, payload.record && typeof payload.record === 'object' ? payload.record : {})
  process.stdout.write(JSON.stringify({ ok: true, collection: 'project_bed_treatment', firebase_collection: 'project_bed_treatment', mysql_sync_status: 'PENDING', ...result }))
}

main().catch((error) => { process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_project_bed_treatment_write_failed' })); process.exitCode = 1 })
