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
const text = (value) => String(value ?? '').trim()
const required = (value, name) => { const result = text(value); if (!result) throw new Error(`${name}_required`); return result }
const key = (value, name) => { const result = text(value); if (result && !/^[A-Za-z0-9]{20}$/.test(result)) throw new Error(`${name}_invalid_firebase_document_id`); return result }
const status = (value) => { const result = text(value || 'ACTIVE').toUpperCase(); if (!['ACTIVE', 'INACTIVE', 'DELETED'].includes(result)) throw new Error('bed_source_status_invalid'); return result }
const mysqlTimestamp = (value = new Date()) => {
  const date = value?.toDate instanceof Function ? value.toDate() : new Date(value)
  if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid')
  const pad = (n, size = 2) => String(n).padStart(size, '0')
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000`
}
function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length) return
  initializeApp(serviceAccountPath ? { credential: cert(serviceAccountPath), projectId } : { projectId })
}

async function save(db, record) {
  const collection = db.collection('project_bed_source')
  const requestedKey = key(record.bed_source_key, 'bed_source_key')
  const ref = requestedKey ? collection.doc(requestedKey) : collection.doc()
  const previousSnapshot = await ref.get()
  const previous = previousSnapshot.exists ? previousSnapshot.data() || {} : {}
  const code = text(record.bed_source_code || previous.bed_source_code).toUpperCase()
  const name = text(record.bed_source_name || previous.bed_source_name)
  if (!code || !/^[A-Z0-9_-]{2,80}$/.test(code)) throw new Error('bed_source_code_invalid')
  if (!name || name.length > 160) throw new Error('bed_source_name_invalid')
  const sourceStatus = status(record.bed_source_status || previous.bed_source_status)
  const duplicateSnapshot = await collection.where('bed_source_code', '==', code).get()
  if (duplicateSnapshot.docs.some((item) => item.id !== ref.id && text(item.get('bed_source_status')).toUpperCase() !== 'DELETED')) throw new Error('bed_source_code_duplicate')
  const now = new Date()
  const data = {
    bed_source_key: ref.id,
    bed_source_code: code,
    bed_source_name: name,
    bed_source_description: text(record.bed_source_description || previous.bed_source_description),
    bed_source_status: sourceStatus,
    bed_source_sort_order: Math.max(0, Number(record.bed_source_sort_order ?? previous.bed_source_sort_order ?? 0)),
    firebase_collection: 'project_bed_source',
    firebase_created_at: previous.firebase_created_at || now,
    firebase_updated_at: FieldValue.serverTimestamp(),
    firebase_deleted_at: sourceStatus === 'DELETED' ? FieldValue.serverTimestamp() : null,
    mysql_created_at: previous.mysql_created_at ? mysqlTimestamp(previous.mysql_created_at) : mysqlTimestamp(now),
    mysql_updated_at: mysqlTimestamp(now),
    mysql_deleted_at: sourceStatus === 'DELETED' ? mysqlTimestamp(now) : null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
  }
  await ref.set(data)
  const readBack = await ref.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.bed_source_key !== ref.id || actual.firebase_collection !== 'project_bed_source' || actual.mysql_sync_status !== 'PENDING') throw new Error('project_bed_source_firebase_readback_failed')
  return { key: ref.id, action: previousSnapshot.exists ? 'updated' : 'created', status: sourceStatus, code, name }
}

async function reorder(db, keys) {
  if (!Array.isArray(keys) || keys.length === 0) throw new Error('bed_source_order_required')
  const refs = keys.map((value) => db.collection('project_bed_source').doc(key(value, 'bed_source_key')))
  const snapshots = await Promise.all(refs.map((ref) => ref.get()))
  if (snapshots.some((snapshot) => !snapshot.exists)) throw new Error('bed_source_order_document_missing')
  const now = mysqlTimestamp()
  const batch = db.batch()
  refs.forEach((ref, index) => batch.update(ref, { bed_source_sort_order: index + 1, firebase_updated_at: FieldValue.serverTimestamp(), mysql_updated_at: now, mysql_synced_at: null, mysql_sync_status: 'PENDING' }))
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
  process.stdout.write(JSON.stringify({ ok: true, collection: 'project_bed_source', firebase_collection: 'project_bed_source', mysql_sync_status: 'PENDING', ...result }))
}
main().catch((error) => { process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_project_bed_source_write_failed' })); process.exitCode = 1 })
