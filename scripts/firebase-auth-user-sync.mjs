import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getAuth } from 'firebase-admin/auth'

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

function normalizeUid(value) {
  const uid = requireString(value, 'user_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(uid)) {
    throw new Error('user_key_invalid_firebase_uid')
  }
  return uid
}

function normalizeEmail(value) {
  const email = requireString(value, 'user_auth_email').toLowerCase()
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    throw new Error('user_auth_email_invalid')
  }
  return email
}

function normalizeProjectKey(value) {
  const projectKey = requireString(value, 'project_key').toLowerCase()
  if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(projectKey)) {
    throw new Error('project_key_invalid')
  }
  return projectKey
}

function normalizeGroupKey(value) {
  const groupKey = String(value || '').trim()
  if (groupKey !== '' && !/^[A-Za-z0-9]{20,40}$/.test(groupKey)) {
    throw new Error('group_key_invalid_firebase_claim')
  }
  return groupKey
}

async function syncAuthorizationClaims(auth, row, uid) {
  const projectKey = normalizeProjectKey(row.project_key)
  const groupKey = normalizeGroupKey(row.group_key)
  const claims = {
    user_key: uid,
    project_key: projectKey,
    group_key: groupKey,
    projects: [projectKey],
    groups: groupKey === '' ? [] : [groupKey],
  }
  await auth.setCustomUserClaims(uid, claims)
  const readBack = await auth.getUser(uid)
  const readBackClaims = readBack.customClaims || {}
  if (readBackClaims.user_key !== uid ||
      readBackClaims.project_key !== projectKey ||
      readBackClaims.group_key !== groupKey ||
      JSON.stringify(readBackClaims.projects || []) !== JSON.stringify(claims.projects) ||
      JSON.stringify(readBackClaims.groups || []) !== JSON.stringify(claims.groups)) {
    throw new Error('firebase_auth_claims_readback_failed')
  }
}

async function upsertAuthUser(auth, row, createPassword, updatePassword) {
  const uid = normalizeUid(row.user_key)
  const email = normalizeEmail(row.user_auth_email)
  const displayName = String(row.user_name || row.user_login || row.user_auth_username || uid).trim()
  const disabled = String(row.user_status || '').trim().toUpperCase() !== 'ACTIVE'

  try {
    await auth.getUser(uid)
    const update = {
      email,
      displayName,
      disabled,
    }
    if (updatePassword !== '') {
      update.password = updatePassword
    }
    await auth.updateUser(uid, update)
    const readBack = await auth.getUser(uid)
    if (readBack.uid !== uid || readBack.email !== email || readBack.disabled !== disabled) {
      throw new Error('firebase_auth_user_readback_failed')
    }
    return 'updated'
  } catch (error) {
    if (error?.code !== 'auth/user-not-found') {
      throw error
    }
    await auth.createUser({
      uid,
      email,
      password: requireString(createPassword, 'password'),
      displayName,
      disabled,
    })
    const readBack = await auth.getUser(uid)
    if (readBack.uid !== uid || readBack.email !== email || readBack.disabled !== disabled) {
      throw new Error('firebase_auth_user_readback_failed')
    }
    return 'created'
  }
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const row = payload.row && typeof payload.row === 'object' ? payload.row : {}
  const createPassword = String(payload.create_password || payload.password || '').trim()
  const updatePassword = String(payload.update_password || '').trim()

  initializeFirebase(projectId, serviceAccountPath)
  const auth = getAuth()
  const uid = normalizeUid(row.user_key)
  const action = await upsertAuthUser(auth, row, createPassword, updatePassword)
  await syncAuthorizationClaims(auth, row, uid)
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    uid,
    action,
    claims_synced: true,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_auth_user_sync_failed',
  }))
})
