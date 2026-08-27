import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore, FieldValue } from 'firebase-admin/firestore'

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

function cleanObject(value) {
  return Object.fromEntries(
    Object.entries(value).filter(([, item]) => item !== undefined),
  )
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

function normalizeDocumentKey(value) {
  const key = requireString(value, 'settings_document_key')
  if (!/^[A-Za-z0-9_-]{2,80}$/.test(key)) {
    throw new Error('settings_document_key_invalid')
  }
  return key
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const document = payload.settings_document && typeof payload.settings_document === 'object'
    ? payload.settings_document
    : {}
  const documentKey = normalizeDocumentKey(document.document_key)

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  await db.collection('project_setting').doc(documentKey).set(cleanObject({
    ...document,
    document_key: documentKey,
    firebase_collection: 'project_setting',
    server_synced_at: FieldValue.serverTimestamp(),
  }), { merge: true })

  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_setting',
    document_key: documentKey,
    system_count: Number(document?.summary?.system_count || 0),
    project_count: Number(document?.summary?.project_count || 0),
    media_count: Number(document?.summary?.media_count || 0),
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_settings_sync_failed',
  }))
  process.exitCode = 1
})
