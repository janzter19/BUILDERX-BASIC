import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getAuth } from 'firebase-admin/auth'
import { FieldValue, Timestamp, getFirestore } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const readStdin = () => new Promise((resolve, reject) => {
  let input = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', (chunk) => {
    input += chunk
    if (input.length > 30000) reject(new Error('firebase_project_user_payload_too_large'))
  })
  process.stdin.on('end', () => resolve(input))
  process.stdin.on('error', reject)
})

const required = (value, code) => {
  const text = String(value ?? '').trim()
  if (text === '') throw new Error(code)
  return text
}
const validProjectKey = (value) => {
  const key = required(value, 'project_key_required').toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(key)) throw new Error('project_key_invalid')
  return key
}
const validDocumentKey = (value) => {
  const key = String(value ?? '').trim()
  if (key !== '' && !/^[A-Za-z0-9]{20,40}$/.test(key)) throw new Error('user_key_invalid_firebase_document_id')
  return key
}
const validUsername = (value) => {
  const username = required(value, 'user_login_required').toLowerCase()
  if (username.length > 80 || !/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/.test(username)) throw new Error('user_login_invalid')
  return username
}
const validEmail = (value) => {
  const result = required(value, 'user_auth_email_required').toLowerCase()
  if (result.length > 190 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(result)) throw new Error('user_auth_email_invalid')
  return result
}
const validStatus = (value) => {
  const result = String(value || 'ACTIVE').trim().toUpperCase()
  if (!['DRAFT', 'ACTIVE', 'INACTIVE', 'LOCKED', 'DELETED'].includes(result)) throw new Error('user_status_invalid')
  return result
}
const nullable = (value) => String(value ?? '').trim() || null
const nullableTimestamp = (value) => {
  if (value === null || value === undefined || String(value).trim() === '') return null
  if (value?.toDate instanceof Function) return value
  const date = new Date(String(value).trim())
  if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid')
  return Timestamp.fromDate(date)
}
const mysqlTimestamp = (value = new Date()) => {
  const date = value?.toDate instanceof Function ? value.toDate() : value instanceof Date ? value : new Date(value)
  if (Number.isNaN(date.getTime())) throw new Error('timestamp_invalid')
  const pad = (number, length = 2) => String(number).padStart(length, '0')
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())} ${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}.${pad(date.getUTCMilliseconds(), 3)}000`
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length > 0) return
  initializeApp({ credential: cert(serviceAccountPath), projectId })
}

function safeErrorCode(error) {
  const candidate = String(error?.code || error?.message || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '')
  return candidate !== '' && candidate.length <= 80 ? candidate : 'firebase_project_user_write_failed'
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id_required')
  const serviceAccountPath = required(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH, 'firebase_service_account_required')
  const profile = payload.profile && typeof payload.profile === 'object' ? payload.profile : {}
  const operation = String(payload.operation || 'update').trim().toLowerCase()
  const suppliedKey = validDocumentKey(profile.user_key)
  if (!['create', 'update'].includes(operation)) throw new Error('project_user_operation_invalid')
  if (operation === 'create' && suppliedKey !== '') throw new Error('project_user_create_key_must_be_empty')
  if (operation === 'update' && suppliedKey === '') throw new Error('project_user_update_key_required')

  const projectKeyValue = validProjectKey(profile.project_key)
  const username = validUsername(profile.user_login)
  const authEmail = validEmail(profile.user_auth_email)
  const userName = required(profile.user_name, 'user_name_required')
  const userStatus = validStatus(profile.user_status)
  const avatarByteSize = Number(profile.user_avatar_byte_size || 0)
  if (!Number.isSafeInteger(avatarByteSize) || avatarByteSize < 0) throw new Error('user_avatar_byte_size_invalid')
  const password = String(payload.password || '')
  if (operation === 'create' && password.length < 8) throw new Error('password_required')
  if (password !== '' && password.length < 8) throw new Error('password_too_short')

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const auth = getAuth()
  const reference = suppliedKey === '' ? db.collection('project_user').doc() : db.collection('project_user').doc(suppliedKey)
  const previous = await reference.get()
  const existing = previous.exists ? previous.data() || {} : {}
  const now = mysqlTimestamp()
  const firebaseUpdatedAt = FieldValue.serverTimestamp()
  const createdAt = previous.exists ? mysqlTimestamp(existing.mysql_created_at || existing.created_at || now) : now
  const previousStatus = String(existing.user_status || '').trim().toUpperCase()
  const statusChangedTo = (status) => userStatus === status && (!previous.exists || previousStatus !== status)
  const transitionTimestamp = (existingValue, shouldSet) => shouldSet ? FieldValue.serverTimestamp() : nullableTimestamp(existingValue)
  const deletedAt = statusChangedTo('DELETED') ? FieldValue.serverTimestamp() : nullableTimestamp(existing.user_deleted_at)
  const data = {
    user_key: reference.id,
    firebase_uid: reference.id,
    project_key: projectKeyValue,
    user_login: username,
    user_auth_username: String(profile.user_auth_username || '').trim(),
    user_auth_email: authEmail,
    user_name: userName,
    user_chat_name: String(profile.user_chat_name || '').trim(),
    user_mobile_number: String(profile.user_mobile_number || '').trim(),
    user_avatar_path: String(profile.user_avatar_path || '').trim(),
    user_avatar_original_name: String(profile.user_avatar_original_name || '').trim(),
    user_avatar_mime_type: String(profile.user_avatar_mime_type || '').trim(),
    user_avatar_byte_size: avatarByteSize,
    user_avatar_sha256: String(profile.user_avatar_sha256 || '').trim(),
    user_avatar_uploaded_at: nullableTimestamp(profile.user_avatar_uploaded_at),
    user_status: userStatus,
    user_password_change_required: profile.user_password_change_required === true || profile.user_password_change_required === '1',
    user_disabled: userStatus !== 'ACTIVE',
    user_deleted: userStatus === 'DELETED',
    user_locked: userStatus === 'LOCKED',
    user_last_login_at: nullableTimestamp(profile.user_last_login_at),
    user_last_login_ip_address: nullable(profile.user_last_login_ip_address ?? existing.user_last_login_ip_address),
    user_last_login_device: nullable(profile.user_last_login_device ?? existing.user_last_login_device),
    user_last_logout_at: nullableTimestamp(profile.user_last_logout_at ?? existing.user_last_logout_at),
    user_last_logout_ip_address: nullable(profile.user_last_logout_ip_address ?? existing.user_last_logout_ip_address),
    user_last_logout_device: nullable(profile.user_last_logout_device ?? existing.user_last_logout_device),
    user_password_reset_at: nullableTimestamp(profile.user_password_reset_at ?? existing.user_password_reset_at),
    user_activated_at: transitionTimestamp(existing.user_activated_at, statusChangedTo('ACTIVE')),
    user_deactivated_at: transitionTimestamp(existing.user_deactivated_at, statusChangedTo('INACTIVE')),
    user_locked_at: transitionTimestamp(existing.user_locked_at, statusChangedTo('LOCKED')),
    user_deleted_at: deletedAt,
    firebase_collection: 'project_user',
    mysql_created_at: createdAt,
    mysql_updated_at: now,
    mysql_deleted_at: userStatus === 'DELETED' ? now : null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
    firebase_created_at: previous.exists && existing.firebase_created_at ? nullableTimestamp(existing.firebase_created_at) : FieldValue.serverTimestamp(),
    firebase_updated_at: firebaseUpdatedAt,
    firebase_deleted_at: statusChangedTo('DELETED') ? FieldValue.serverTimestamp() : nullableTimestamp(existing.firebase_deleted_at),
  }

  await reference.set(data)
  const readBack = await reference.get()
  const actual = readBack.data() || {}
  if (!readBack.exists || actual.user_key !== reference.id || actual.firebase_uid !== reference.id || actual.project_key !== projectKeyValue || actual.user_login !== username || actual.user_status !== userStatus || actual.firebase_collection !== 'project_user' || actual.mysql_sync_status !== 'PENDING' || actual.mysql_synced_at !== null || actual.user_disabled !== (userStatus !== 'ACTIVE') || actual.user_deleted !== (userStatus === 'DELETED') || actual.user_locked !== (userStatus === 'LOCKED') || !actual.firebase_created_at || !actual.firebase_updated_at || (userStatus === 'DELETED' && !actual.user_deleted_at)) throw new Error('project_user_firebase_readback_failed')

  let authAction = 'updated'
  try {
    const authUser = await auth.getUser(reference.id)
    await auth.updateUser(reference.id, { email: authEmail, displayName: userName, disabled: userStatus !== 'ACTIVE', ...(password === '' ? {} : { password }) })
    if (authUser.uid !== reference.id) throw new Error('firebase_auth_uid_mismatch')
  } catch (error) {
    if (error?.code !== 'auth/user-not-found') throw error
    await auth.createUser({ uid: reference.id, email: authEmail, password, displayName: userName, disabled: userStatus !== 'ACTIVE' })
    authAction = 'created'
  }
  const authReadBack = await auth.getUser(reference.id)
  if (authReadBack.uid !== reference.id || authReadBack.email !== authEmail || authReadBack.disabled !== (userStatus !== 'ACTIVE')) throw new Error('firebase_auth_user_readback_failed')
  process.stdout.write(JSON.stringify({ ok: true, user_key: reference.id, auth_action: authAction, firebase_collection: 'project_user', mysql_sync_status: 'PENDING' }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, code: safeErrorCode(error) }))
  process.exitCode = 1
})
