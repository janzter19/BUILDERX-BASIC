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
  process.stdin.on('data', chunk => { input += chunk })
  process.stdin.on('end', () => resolve(input))
  process.stdin.on('error', reject)
})
const required = (value, code) => {
  const text = String(value ?? '').trim()
  if (!text) throw new Error(code)
  return text
}
const clean = value => String(value ?? '').trim() || null
const mysqlTimestamp = (value = new Date()) => { const date = value instanceof Date ? value : new Date(value); if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid'); const pad = (number, length = 2) => String(number).padStart(length, '0'); return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000` }
const validProject = value => {
  const result = required(value, 'project_key_required').toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(result)) throw new Error('project_key_invalid')
  return result
}
const validAction = value => {
  const result = required(value, 'user_action_required').toUpperCase()
  if (!['LOGIN', 'LOGOUT', 'CREATE', 'EDIT', 'RESET_PASSWORD', 'ACTIVATE', 'DEACTIVATE', 'LOCK', 'DELETE', 'RESTORE'].includes(result)) throw new Error('user_action_invalid')
  return result
}
const validStatus = value => {
  const result = required(value, 'user_action_status_required').toUpperCase()
  if (!['SUCCESS', 'FAILED'].includes(result)) throw new Error('user_action_status_invalid')
  return result
}
function init(projectId, serviceAccountPath) {
  if (getApps().length === 0) initializeApp({ credential: cert(serviceAccountPath), projectId })
}
async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id_required')
  const serviceAccountPath = required(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH, 'firebase_service_account_required')
  const event = payload.event && typeof payload.event === 'object' ? payload.event : {}
  const projectKey = validProject(event.project_key)
  const action = validAction(event.user_action)
  const actionStatus = validStatus(event.user_action_status || 'SUCCESS')
  init(projectId, serviceAccountPath)
  const db = getFirestore()
  const ref = db.collection('project_user_login_history').doc()
  const now = mysqlTimestamp()
  const data = {
    user_login_history_key: ref.id,
    project_key: projectKey,
    user_key: clean(event.user_key),
    user_login: clean(event.user_login),
    user_action: action,
    user_action_status: actionStatus,
    user_action_at: FieldValue.serverTimestamp(),
    user_previous_status: clean(event.user_previous_status),
    user_new_status: clean(event.user_new_status),
    user_action_reason: clean(event.user_action_reason),
    user_performed_by_key: clean(event.user_performed_by_key),
    user_ip_address: clean(event.user_ip_address),
    user_device: clean(event.user_device),
    firebase_collection: 'project_user_login_history',
    mysql_created_at: now,
    mysql_updated_at: now,
    mysql_deleted_at: null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
    firebase_created_at: FieldValue.serverTimestamp(),
    firebase_updated_at: FieldValue.serverTimestamp(),
    firebase_deleted_at: null,
  }
  await ref.set(data)
  const readBack = await ref.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.user_login_history_key !== ref.id || actual.project_key !== projectKey || actual.user_action !== action || actual.mysql_sync_status !== 'PENDING' || actual.mysql_synced_at !== null || !actual.firebase_created_at || !actual.firebase_updated_at || actual.firebase_deleted_at !== null) throw new Error('project_user_login_history_firebase_readback_failed')
  process.stdout.write(JSON.stringify({ ok: true, user_login_history_key: ref.id, firebase_collection: 'project_user_login_history', mysql_sync_status: 'PENDING' }))
}
main().catch(error => {
  const code = error instanceof Error && /^[a-z0-9_]+$/.test(error.message) ? error.message : 'project_user_login_history_firebase_write_failed'
  process.stdout.write(JSON.stringify({ ok: false, code }))
  process.exitCode = 1
})
