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

function requiredString(value, label) {
  const text = String(value || '').trim()
  if (text === '') throw new Error(`${label}_required`)
  return text
}

function normalizeUid(value) {
  const uid = requiredString(value, 'user_key')
  if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/.test(uid)) throw new Error('user_key_invalid')
  return uid
}

function normalizeList(value) {
  const values = Array.isArray(value) ? value : []
  return [...new Set(values.map((item) => String(item || '').trim()).filter(Boolean))]
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length > 0) return
  initializeApp({
    credential: cert(serviceAccountPath),
    projectId,
  })
}

async function main() {
  const rawInput = await readStdin()
  const input = JSON.parse(rawInput || '{}')
  const projectId = requiredString(input.project_id, 'project_id')
  const serviceAccountPath = requiredString(input.service_account_path, 'service_account_path')
  const uid = normalizeUid(input.user_key)
  const tenantKey = requiredString(input.tenant_key || projectId, 'tenant_key')
  const projects = normalizeList(input.project_keys)
  const groups = normalizeList(input.group_keys)

  initializeFirebase(projectId, serviceAccountPath)
  const token = await getAuth().createCustomToken(uid, {
    user_key: uid,
    tenant_key: tenantKey,
    project_key: projects[0] || '',
    group_key: groups[0] || '',
    projects,
    groups,
  })

  process.stdout.write(JSON.stringify({ ok: true, token, uid, projects, groups }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, message: String(error?.message || 'custom_token_failed') }))
  process.exitCode = 1
})
