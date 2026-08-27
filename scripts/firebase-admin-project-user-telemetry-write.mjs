import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'
const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })
const readStdin = () => new Promise((resolve, reject) => { let input = ''; process.stdin.setEncoding('utf8'); process.stdin.on('data', chunk => { input += chunk }); process.stdin.on('end', () => resolve(input)); process.stdin.on('error', reject) })
const required = (value, code) => { const result = String(value ?? '').trim(); if (!result) throw new Error(code); return result }
const nullable = value => String(value ?? '').trim() || null
const mysqlTimestamp = (value = new Date()) => { const date = value instanceof Date ? value : new Date(value); if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid'); const pad = (number, length = 2) => String(number).padStart(length, '0'); return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000` }
async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id_required')
  const serviceAccountPath = required(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH, 'firebase_service_account_required')
  const userKey = required(payload.user_key, 'user_key_required')
  if (!/^[A-Za-z0-9]{20,40}$/.test(userKey)) throw new Error('user_key_invalid_firebase_document_id')
  const telemetry = payload.telemetry && typeof payload.telemetry === 'object' ? payload.telemetry : {}
  if (getApps().length === 0) initializeApp({ credential: cert(serviceAccountPath), projectId })
  const ref = getFirestore().collection('project_user').doc(userKey)
  const existing = await ref.get()
  if (!existing.exists) throw new Error('project_user_firebase_document_not_found')
  const now = mysqlTimestamp()
  const data = {
    user_last_login_at: nullable(telemetry.user_last_login_at),
    user_last_login_ip_address: nullable(telemetry.user_last_login_ip_address),
    user_last_login_device: nullable(telemetry.user_last_login_device),
    user_last_logout_at: nullable(telemetry.user_last_logout_at),
    user_last_logout_ip_address: nullable(telemetry.user_last_logout_ip_address),
    user_last_logout_device: nullable(telemetry.user_last_logout_device),
    mysql_updated_at: now,
    mysql_deleted_at: null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
    firebase_updated_at: FieldValue.serverTimestamp(),
  }
  await ref.update(data)
  const readBack = await ref.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.user_key !== userKey || actual.mysql_sync_status !== 'PENDING' || actual.mysql_synced_at !== null) throw new Error('project_user_telemetry_firebase_readback_failed')
  process.stdout.write(JSON.stringify({ ok: true, user_key: userKey, firebase_collection: 'project_user', mysql_sync_status: 'PENDING' }))
}
main().catch(error => { const code = error instanceof Error && /^[a-z0-9_]+$/.test(error.message) ? error.message : 'project_user_telemetry_firebase_write_failed'; process.stdout.write(JSON.stringify({ ok: false, code })); process.exitCode = 1 })
