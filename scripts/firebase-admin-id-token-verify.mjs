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
      if (input.length > 20000) reject(new Error('request_too_large'))
    })
    process.stdin.on('end', () => resolve(input))
    process.stdin.on('error', reject)
  })
}

function requiredString(value, code) {
  const text = String(value || '').trim()
  if (text === '') throw new Error(code)
  return text
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length > 0) return
  initializeApp({
    credential: cert(serviceAccountPath),
    projectId,
  })
}

function safeErrorCode(error) {
  const code = String(error?.code || '').toLowerCase()
  if (code === 'auth/id-token-expired') return 'firebase_id_token_expired'
  if (code === 'auth/id-token-revoked') return 'firebase_id_token_revoked'
  if (code === 'auth/user-disabled') return 'firebase_user_disabled'
  if (code === 'auth/argument-error' || code === 'auth/invalid-id-token') return 'firebase_id_token_invalid'
  if (error instanceof Error && /^[a-z0-9_]+$/.test(error.message)) return error.message
  return 'firebase_id_token_invalid'
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requiredString(payload.project_id, 'firebase_project_id_required')
  const serviceAccountPath = requiredString(payload.service_account_path, 'firebase_service_account_required')
  const idToken = requiredString(payload.id_token, 'firebase_id_token_required')
  const requireEmailVerified = payload.require_email_verified !== false
  if (idToken.length > 16384 || !/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(idToken)) {
    throw new Error('firebase_id_token_invalid')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const auth = getAuth()
  const decoded = await auth.verifyIdToken(idToken, true)
  const expectedIssuer = `https://securetoken.google.com/${projectId}`
  if (decoded.aud !== projectId || decoded.iss !== expectedIssuer) {
    throw new Error('firebase_id_token_invalid')
  }
  const now = Math.floor(Date.now() / 1000)
  if (!Number.isInteger(decoded.auth_time) || decoded.auth_time < now - 600 || decoded.auth_time > now + 60) {
    throw new Error('firebase_auth_too_old')
  }

  const user = await auth.getUser(decoded.uid)
  if (user.disabled) throw new Error('firebase_user_disabled')
  if (typeof decoded.email !== 'string' || decoded.email.trim() === '' || (requireEmailVerified && decoded.email_verified !== true)) {
    throw new Error('firebase_email_unverified')
  }

  process.stdout.write(JSON.stringify({
    ok: true,
    uid: String(decoded.uid),
    email: decoded.email.trim().toLowerCase(),
    email_verified: decoded.email_verified === true,
    auth_time: Number(decoded.auth_time || 0),
    issued_at: Number(decoded.iat || 0),
    expires_at: Number(decoded.exp || 0),
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, code: safeErrorCode(error) }))
})
