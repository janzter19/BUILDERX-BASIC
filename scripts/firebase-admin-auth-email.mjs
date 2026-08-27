import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getAuth } from 'firebase-admin/auth'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })
const input = await new Promise((resolve, reject) => {
  let value = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', chunk => { value += chunk })
  process.stdin.on('end', () => resolve(value))
  process.stdin.on('error', reject)
})

try {
  const payload = JSON.parse(input)
  const uid = String(payload.uid || '').trim()
  const projectId = String(payload.project_id || process.env.FIREBASE_PROJECT_ID || '').trim()
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || '').trim()
  if (!uid || !projectId || !serviceAccountPath) throw new Error('firebase_auth_lookup_unavailable')
  if (getApps().length === 0) initializeApp({ credential: cert(serviceAccountPath), projectId })
  const user = await getAuth().getUser(uid)
  const email = String(user.email || '').trim().toLowerCase()
  if (!email || !email.includes('@')) throw new Error('firebase_auth_email_missing')
  process.stdout.write(JSON.stringify({ ok: true, email, disabled: user.disabled === true }))
} catch (error) {
  const code = error?.code === 'auth/user-not-found' ? 'firebase_auth_user_not_found' : (error instanceof Error && /^[a-z0-9_]+$/.test(error.message) ? error.message : 'firebase_auth_lookup_failed')
  process.stdout.write(JSON.stringify({ ok: false, code }))
  process.exitCode = 1
}
