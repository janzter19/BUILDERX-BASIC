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

function cleanObject(value) {
  return Object.fromEntries(
    Object.entries(value).filter(([, item]) => item !== undefined),
  )
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

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const message = payload.message || {}
  const chatKey = requireString(message.chat_key, 'chat_key')

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const batch = db.batch()
  const messageRef = db.collection('project_messenger_chat').doc(chatKey)

  batch.set(messageRef, cleanObject({
    chat_key: chatKey,
    project_key: String(message.project_key || ''),
    group_key: String(message.group_key || ''),
    reply_to_chat_key: String(message.reply_to_chat_key || ''),
    sender_user_key: String(message.sender_user_key || ''),
    sender_name: String(message.sender_name || ''),
    message_text: String(message.message_status || 'ACTIVE') === 'REMOVED' ? '' : String(message.message_text || ''),
    message_type: String(message.message_type || 'text'),
    message_status: String(message.message_status || 'ACTIVE'),
    removed_at: String(message.removed_at || ''),
    firebase_collection: 'project_messenger_chat',
    mysql_created_at: String(message.created_at || ''),
    mysql_updated_at: String(message.updated_at || ''),
    server_synced_at: FieldValue.serverTimestamp(),
  }), { merge: true })

  for (const attachment of Array.isArray(message.attachments) ? message.attachments : []) {
    const attachmentKey = String(attachment.attachment_key || '').trim()
    if (attachmentKey === '') continue
    batch.set(db.collection('project_messenger_chat_attachment').doc(attachmentKey), cleanObject({
      attachment_key: attachmentKey,
      chat_key: chatKey,
      project_key: String(attachment.project_key || message.project_key || ''),
      group_key: String(attachment.group_key || message.group_key || ''),
      uploaded_image_url: String(attachment.uploaded_image_url || ''),
      image_original_name: String(attachment.image_original_name || ''),
      image_mime_type: String(attachment.image_mime_type || ''),
      image_byte_size: Number(attachment.image_byte_size || 0),
      image_sha256: String(attachment.image_sha256 || ''),
      sort_order: Number(attachment.sort_order || 0),
      attachment_status: String(attachment.attachment_status || 'ACTIVE'),
      firebase_collection: 'project_messenger_chat_attachment',
      mysql_created_at: String(attachment.created_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }), { merge: true })
  }

  await batch.commit()
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_messenger_chat',
    chat_key: chatKey,
    attachment_count: Array.isArray(message.attachments) ? message.attachments.length : 0,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_sync_failed',
  }))
  process.exitCode = 1
})
