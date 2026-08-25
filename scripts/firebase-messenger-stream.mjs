import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import dotenv from 'dotenv'
import mysql from 'mysql2/promise'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore, FieldValue } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const allowedStatuses = new Set(['ACTIVE', 'REMOVED'])
const allowedTypes = new Set(['text', 'image', 'mixed'])
const builderxDatabaseConfig = loadBuilderXDatabaseConfig()

function loadBuilderXDatabaseConfig() {
  const configPath = path.join(rootDir, 'phases', 'config.local.php')
  const phpCode = [
    `$config = require ${JSON.stringify(configPath)};`,
    'echo json_encode([',
    '"host" => (string)($config["db_host"] ?? ""),',
    '"port" => (int)($config["db_port"] ?? 3306),',
    '"database" => (string)($config["db_name"] ?? ""),',
    '"user" => (string)($config["db_user"] ?? ""),',
    '"password" => (string)($config["db_pass"] ?? ""),',
    ']);',
  ].join('')

  try {
    const output = execFileSync('php', ['-r', phpCode], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'ignore'],
      timeout: 5000,
    })
    const parsed = JSON.parse(output)
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    return {}
  }
}

function requiredEnv(name, fallback = '') {
  const value = String(process.env[name] || fallback).trim()
  if (value === '') throw new Error(`${name}_required`)
  return value
}

function optionalEnv(name, fallback = '') {
  return String(process.env[name] || fallback).trim()
}

function safeString(value, maxLength, fallback = '') {
  return String(value ?? fallback).trim().slice(0, maxLength)
}

function mysqlTimestamp(value) {
  const text = String(value || '').trim()
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(text)) return text
  if (/^\d{4}-\d{2}-\d{2}T/.test(text)) {
    const date = new Date(text)
    if (!Number.isNaN(date.getTime())) return date.toISOString().slice(0, 19).replace('T', ' ')
  }
  return new Date().toISOString().slice(0, 19).replace('T', ' ')
}

function initializeFirebase() {
  if (getApps().length > 0) return
  const projectId = requiredEnv('FIREBASE_PROJECT_ID', 'rbmsv4-vrp')
  const serviceAccountPath = optionalEnv('GOOGLE_APPLICATION_CREDENTIALS', optionalEnv('FIREBASE_SERVICE_ACCOUNT_PATH'))
  if (serviceAccountPath === '') throw new Error('GOOGLE_APPLICATION_CREDENTIALS_required')
  initializeApp({
    credential: cert(serviceAccountPath),
    projectId,
  })
}

function mysqlConfig() {
  return {
    host: requiredEnv('DB_HOST', String(builderxDatabaseConfig.host || 'localhost')),
    port: Number(process.env.DB_PORT || builderxDatabaseConfig.port || 3306),
    user: requiredEnv('DB_USERNAME', process.env.DB_USER || String(builderxDatabaseConfig.user || '')),
    password: String(process.env.DB_PASSWORD || process.env.DB_PASS || builderxDatabaseConfig.password || ''),
    database: requiredEnv('DB_DATABASE', process.env.DB_NAME || String(builderxDatabaseConfig.database || '')),
    waitForConnections: true,
    connectionLimit: Number(process.env.FIREBASE_STREAM_MYSQL_CONNECTIONS || 4),
    charset: 'utf8mb4',
  }
}

async function activeGroup(pool, groupKey, projectKey) {
  const [rows] = await pool.execute(
    "SELECT group_key, project_key FROM project_user_group WHERE group_key = ? AND project_key = ? AND group_status = 'ACTIVE' LIMIT 1",
    [groupKey, projectKey],
  )
  return Array.isArray(rows) && rows.length === 1
}

async function knownSender(pool, senderUserKey, projectKey) {
  if (senderUserKey.startsWith('portal_')) return true
  const [projectRows] = await pool.execute(
    "SELECT user_key FROM project_user WHERE user_key = ? AND project_key = ? AND user_status = 'ACTIVE' LIMIT 1",
    [senderUserKey, projectKey],
  )
  if (Array.isArray(projectRows) && projectRows.length === 1) return true
  const [builderRows] = await pool.execute(
    "SELECT user_key FROM builder_user WHERE user_key = ? AND user_status = 'ACTIVE' LIMIT 1",
    [senderUserKey],
  )
  return Array.isArray(builderRows) && builderRows.length === 1
}

function normalizeMessage(snapshot) {
  const data = snapshot.data() || {}
  const chatKey = safeString(data.chat_key || snapshot.id, 40)
  const projectKey = safeString(data.project_key, 80)
  const groupKey = safeString(data.group_key, 40)
  const senderUserKey = safeString(data.sender_user_key, 80)
  const senderName = safeString(data.sender_name, 160, 'Firebase User')
  const messageStatus = allowedStatuses.has(String(data.message_status || 'ACTIVE')) ? String(data.message_status || 'ACTIVE') : 'ACTIVE'
  const messageType = allowedTypes.has(String(data.message_type || 'text')) ? String(data.message_type || 'text') : 'text'

  return {
    chatKey,
    projectKey,
    groupKey,
    replyToChatKey: safeString(data.reply_to_chat_key, 40),
    senderUserKey,
    senderName,
    messageText: messageStatus === 'REMOVED' ? '' : String(data.message_text || '').slice(0, 8000),
    messageType,
    messageStatus,
    removedAt: messageStatus === 'REMOVED' ? mysqlTimestamp(data.removed_at || data.updated_at) : null,
    createdAt: mysqlTimestamp(data.mysql_created_at || data.created_at),
  }
}

function normalizeAttachment(snapshot) {
  const data = snapshot.data() || {}
  return {
    attachmentKey: safeString(data.attachment_key || snapshot.id, 40),
    chatKey: safeString(data.chat_key, 40),
    projectKey: safeString(data.project_key, 80),
    groupKey: safeString(data.group_key, 40),
    uploadedImageUrl: safeString(data.uploaded_image_url, 500),
    imageOriginalName: safeString(data.image_original_name, 255),
    imageMimeType: safeString(data.image_mime_type, 100),
    imageByteSize: Math.max(0, Number(data.image_byte_size || 0)),
    imageSha256: safeString(data.image_sha256, 128),
    sortOrder: Math.max(0, Number(data.sort_order || 0)),
    attachmentStatus: String(data.attachment_status || 'ACTIVE') === 'REMOVED' ? 'REMOVED' : 'ACTIVE',
    createdAt: mysqlTimestamp(data.mysql_created_at || data.created_at),
  }
}

function alreadySyncedToMysql(snapshot) {
  const data = snapshot.data() || {}
  return String(data.mysql_sync_status || '').toUpperCase() === 'SYNCED'
}

function syncedDocumentKey(snapshot, fieldName) {
  const data = snapshot.data() || {}
  const key = safeString(data[fieldName] || snapshot.id, 40)
  if (!/^[A-Za-z0-9]{1,40}$/.test(key)) {
    throw new Error('messenger_removed_document_invalid_key')
  }
  return key
}

async function upsertMessage(pool, snapshot) {
  const message = normalizeMessage(snapshot)
  if (message.chatKey === '' || message.projectKey === '' || message.groupKey === '' || message.senderUserKey === '') {
    throw new Error('messenger_document_missing_required_fields')
  }
  if (!/^[A-Za-z0-9]{1,40}$/.test(message.chatKey) || !/^[A-Za-z0-9-]{1,80}$/.test(message.projectKey) || !/^[A-Za-z0-9]{1,40}$/.test(message.groupKey)) {
    throw new Error('messenger_document_invalid_keys')
  }
  if (!(await activeGroup(pool, message.groupKey, message.projectKey))) {
    throw new Error('messenger_document_group_not_active')
  }
  if (!(await knownSender(pool, message.senderUserKey, message.projectKey))) {
    throw new Error('messenger_document_sender_not_active')
  }

  await pool.execute(
    `INSERT INTO project_messenger_chat (
      chat_key, project_key, group_key, reply_to_chat_key, sender_user_key, sender_name,
      message_text, message_type, message_status, removed_at, removed_by_user_key,
      firebase_collection, firebase_sync_status, firebase_synced_at, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'project_messenger_chat', 'SYNCED', CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
      project_key = VALUES(project_key),
      group_key = VALUES(group_key),
      reply_to_chat_key = VALUES(reply_to_chat_key),
      sender_user_key = VALUES(sender_user_key),
      sender_name = VALUES(sender_name),
      message_text = VALUES(message_text),
      message_type = VALUES(message_type),
      message_status = VALUES(message_status),
      removed_at = VALUES(removed_at),
      removed_by_user_key = VALUES(removed_by_user_key),
      firebase_sync_status = 'SYNCED',
      firebase_synced_at = CURRENT_TIMESTAMP,
      updated_at = CURRENT_TIMESTAMP`,
    [
      message.chatKey,
      message.projectKey,
      message.groupKey,
      message.replyToChatKey || null,
      message.senderUserKey,
      message.senderName,
      message.messageText,
      message.messageType,
      message.messageStatus,
      message.removedAt,
      message.messageStatus === 'REMOVED' ? message.senderUserKey : null,
      message.createdAt,
    ],
  )

  await snapshot.ref.set({
    mysql_sync_status: 'SYNCED',
    mysql_synced_at: FieldValue.serverTimestamp(),
    mysql_sync_error: FieldValue.delete(),
  }, { merge: true })

  return message
}

async function removeMessageFromMysql(pool, snapshot) {
  const data = snapshot.data() || {}
  const chatKey = syncedDocumentKey(snapshot, 'chat_key')
  const removedBy = safeString(data.removed_by_user_key || data.sender_user_key || 'firebase_delete', 80)
  const connection = await pool.getConnection()

  try {
    await connection.beginTransaction()
    const [result] = await connection.execute(
      `UPDATE project_messenger_chat
      SET message_status = 'REMOVED',
        message_text = '',
        removed_at = COALESCE(removed_at, CURRENT_TIMESTAMP),
        removed_by_user_key = COALESCE(removed_by_user_key, ?),
        firebase_sync_status = 'SYNCED',
        firebase_synced_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
      WHERE chat_key = ?`,
      [removedBy, chatKey],
    )

    await connection.execute(
      `UPDATE project_messenger_chat_attachment
      SET attachment_status = 'REMOVED',
        firebase_sync_status = 'SYNCED',
        firebase_synced_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
      WHERE chat_key = ?`,
      [chatKey],
    )

    if (!result || Number(result.affectedRows || 0) < 1) {
      throw new Error('messenger_removed_document_not_found')
    }

    await connection.commit()
  } catch (error) {
    await connection.rollback().catch(() => {})
    throw error
  } finally {
    connection.release()
  }

  return { chatKey }
}

async function upsertAttachment(pool, snapshot) {
  const attachment = normalizeAttachment(snapshot)
  if (attachment.attachmentKey === '' || attachment.chatKey === '' || attachment.projectKey === '' || attachment.groupKey === '' || attachment.uploadedImageUrl === '') {
    throw new Error('messenger_attachment_missing_required_fields')
  }
  if (!/^[A-Za-z0-9]{1,40}$/.test(attachment.attachmentKey) || !/^[A-Za-z0-9]{1,40}$/.test(attachment.chatKey)) {
    throw new Error('messenger_attachment_invalid_keys')
  }
  if (!/^https?:\/\/[^\s]+$/i.test(attachment.uploadedImageUrl)) {
    throw new Error('messenger_attachment_invalid_url')
  }
  if (!(await activeGroup(pool, attachment.groupKey, attachment.projectKey))) {
    throw new Error('messenger_attachment_group_not_active')
  }
  const [messageRows] = await pool.execute(
    'SELECT chat_key FROM project_messenger_chat WHERE chat_key = ? AND group_key = ? AND project_key = ? LIMIT 1',
    [attachment.chatKey, attachment.groupKey, attachment.projectKey],
  )
  if (!Array.isArray(messageRows) || messageRows.length !== 1) {
    throw new Error('messenger_attachment_chat_not_synced')
  }

  await pool.execute(
    `INSERT INTO project_messenger_chat_attachment (
      attachment_key, chat_key, project_key, group_key, uploaded_image_url,
      image_original_name, image_mime_type, image_byte_size, image_sha256,
      sort_order, attachment_status, firebase_collection, firebase_sync_status,
      firebase_synced_at, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'project_messenger_chat_attachment', 'SYNCED', CURRENT_TIMESTAMP, ?, CURRENT_TIMESTAMP)
    ON DUPLICATE KEY UPDATE
      chat_key = VALUES(chat_key),
      project_key = VALUES(project_key),
      group_key = VALUES(group_key),
      uploaded_image_url = VALUES(uploaded_image_url),
      image_original_name = VALUES(image_original_name),
      image_mime_type = VALUES(image_mime_type),
      image_byte_size = VALUES(image_byte_size),
      image_sha256 = VALUES(image_sha256),
      sort_order = VALUES(sort_order),
      attachment_status = VALUES(attachment_status),
      firebase_sync_status = 'SYNCED',
      firebase_synced_at = CURRENT_TIMESTAMP,
      updated_at = CURRENT_TIMESTAMP`,
    [
      attachment.attachmentKey,
      attachment.chatKey,
      attachment.projectKey,
      attachment.groupKey,
      attachment.uploadedImageUrl,
      attachment.imageOriginalName,
      attachment.imageMimeType,
      attachment.imageByteSize,
      attachment.imageSha256,
      attachment.sortOrder,
      attachment.attachmentStatus,
      attachment.createdAt,
    ],
  )

  await snapshot.ref.set({
    mysql_sync_status: 'SYNCED',
    mysql_synced_at: FieldValue.serverTimestamp(),
    mysql_sync_error: FieldValue.delete(),
  }, { merge: true })

  return attachment
}

async function removeAttachmentFromMysql(pool, snapshot) {
  const attachmentKey = syncedDocumentKey(snapshot, 'attachment_key')
  const connection = await pool.getConnection()

  try {
    await connection.beginTransaction()
    const [result] = await connection.execute(
      `UPDATE project_messenger_chat_attachment
      SET attachment_status = 'REMOVED',
        firebase_sync_status = 'SYNCED',
        firebase_synced_at = CURRENT_TIMESTAMP,
        updated_at = CURRENT_TIMESTAMP
      WHERE attachment_key = ?`,
      [attachmentKey],
    )

    if (!result || Number(result.affectedRows || 0) < 1) {
      throw new Error('messenger_removed_attachment_not_found')
    }

    await connection.commit()
  } catch (error) {
    await connection.rollback().catch(() => {})
    throw error
  } finally {
    connection.release()
  }

  return { attachmentKey }
}

async function markFailed(snapshot, error) {
  await snapshot.ref.set({
    mysql_sync_status: 'FAILED',
    mysql_sync_error: error instanceof Error ? error.message : 'mysql_sync_failed',
    mysql_synced_at: FieldValue.serverTimestamp(),
  }, { merge: true })
}

async function main() {
  initializeFirebase()
  const pool = mysql.createPool(mysqlConfig())
  const db = getFirestore()
  const collectionName = optionalEnv('FIREBASE_MESSENGER_COLLECTION', 'project_messenger_chat')
  const attachmentCollectionName = optionalEnv('FIREBASE_MESSENGER_ATTACHMENT_COLLECTION', 'project_messenger_chat_attachment')

  console.log(JSON.stringify({ ok: true, status: 'listening', collections: [collectionName, attachmentCollectionName] }))
  const unsubscribeMessages = db.collection(collectionName).onSnapshot((snapshot) => {
    for (const change of snapshot.docChanges()) {
      if (change.type === 'removed') {
        void removeMessageFromMysql(pool, change.doc)
          .then((message) => {
            console.log(JSON.stringify({ ok: true, removed: message.chatKey }))
          })
          .catch((error) => {
            console.error(JSON.stringify({ ok: false, document: change.doc.id, message: error instanceof Error ? error.message : 'mysql_remove_sync_failed' }))
          })
        continue
      }
      if (alreadySyncedToMysql(change.doc)) continue
      void upsertMessage(pool, change.doc)
        .then((message) => {
          console.log(JSON.stringify({ ok: true, synced: message.chatKey, group_key: message.groupKey }))
        })
        .catch((error) => {
          console.error(JSON.stringify({ ok: false, document: change.doc.id, message: error instanceof Error ? error.message : 'mysql_sync_failed' }))
          void markFailed(change.doc, error).catch(() => {})
        })
    }
  }, (error) => {
    console.error(JSON.stringify({ ok: false, listener: collectionName, message: error.message }))
    process.exitCode = 1
    void pool.end().finally(() => process.exit(1))
  })

  const unsubscribeAttachments = db.collection(attachmentCollectionName).onSnapshot((snapshot) => {
    for (const change of snapshot.docChanges()) {
      if (change.type === 'removed') {
        void removeAttachmentFromMysql(pool, change.doc)
          .then((attachment) => {
            console.log(JSON.stringify({ ok: true, removed_attachment: attachment.attachmentKey }))
          })
          .catch((error) => {
            console.error(JSON.stringify({ ok: false, document: change.doc.id, message: error instanceof Error ? error.message : 'mysql_attachment_remove_sync_failed' }))
          })
        continue
      }
      if (alreadySyncedToMysql(change.doc)) continue
      void upsertAttachment(pool, change.doc)
        .then((attachment) => {
          console.log(JSON.stringify({ ok: true, synced_attachment: attachment.attachmentKey, chat_key: attachment.chatKey }))
        })
        .catch((error) => {
          console.error(JSON.stringify({ ok: false, document: change.doc.id, message: error instanceof Error ? error.message : 'mysql_attachment_sync_failed' }))
          void markFailed(change.doc, error).catch(() => {})
        })
    }
  }, (error) => {
    console.error(JSON.stringify({ ok: false, listener: attachmentCollectionName, message: error.message }))
    process.exitCode = 1
    void pool.end().finally(() => process.exit(1))
  })

  const shutdown = async () => {
    unsubscribeMessages()
    unsubscribeAttachments()
    await pool.end()
    process.exit(0)
  }
  process.on('SIGINT', () => void shutdown())
  process.on('SIGTERM', () => void shutdown())
}

main().catch((error) => {
  console.error(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_stream_start_failed' }))
  process.exit(1)
})
