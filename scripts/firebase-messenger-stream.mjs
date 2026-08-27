import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import crypto from 'node:crypto'
import dotenv from 'dotenv'
import mysql from 'mysql2/promise'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore, FieldValue } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const pollMs = Math.max(500, Number(process.env.FIREBASE_MESSENGER_QUEUE_POLL_MS || 2000))
const batchSize = Math.max(1, Math.min(100, Number(process.env.FIREBASE_MESSENGER_QUEUE_BATCH_SIZE || 25)))
const claimTimeoutMinutes = Math.max(5, Number(process.env.FIREBASE_MESSENGER_QUEUE_CLAIM_TIMEOUT_MINUTES || 10))
const workerKey = `firebase_queue_${process.pid}_${crypto.randomBytes(8).toString('hex')}`
const builderxDatabaseConfig = loadBuilderXDatabaseConfig()
let shuttingDown = false

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

function mysqlConfig() {
  return {
    host: requiredEnv('DB_HOST', String(builderxDatabaseConfig.host || 'localhost')),
    port: Number(process.env.DB_PORT || builderxDatabaseConfig.port || 3306),
    user: requiredEnv('DB_USERNAME', process.env.DB_USER || String(builderxDatabaseConfig.user || '')),
    password: String(process.env.DB_PASSWORD || process.env.DB_PASS || builderxDatabaseConfig.password || ''),
    database: requiredEnv('DB_DATABASE', process.env.DB_NAME || String(builderxDatabaseConfig.database || '')),
    waitForConnections: true,
    connectionLimit: Number(process.env.FIREBASE_QUEUE_MYSQL_CONNECTIONS || 4),
    charset: 'utf8mb4',
  }
}

function initializeFirebase() {
  if (getApps().length > 0) return
  const projectId = requiredEnv('FIREBASE_PROJECT_ID', 'rbmsv4-vrp')
  const serviceAccountPath = requiredEnv(
    'GOOGLE_APPLICATION_CREDENTIALS',
    String(process.env.FIREBASE_SERVICE_ACCOUNT_PATH || ''),
  )
  initializeApp({ credential: cert(serviceAccountPath), projectId })
}

function safeString(value, maxLength, fallback = '') {
  return String(value ?? fallback).trim().slice(0, maxLength)
}

function cleanObject(value) {
  return Object.fromEntries(Object.entries(value).filter(([, item]) => item !== undefined))
}

function eventPayload(event) {
  try {
    const value = JSON.parse(String(event.payload_json || '{}'))
    if (!value || typeof value !== 'object') throw new Error('payload_not_object')
    return value
  } catch (error) {
    throw new Error(error instanceof Error ? `messenger_event_payload_invalid:${error.message}` : 'messenger_event_payload_invalid')
  }
}

function messageDocument(message) {
  const chatKey = safeString(message.chat_key, 40)
  if (chatKey === '') throw new Error('messenger_event_chat_key_required')
  const status = String(message.message_status || 'ACTIVE') === 'REMOVED' ? 'REMOVED' : 'ACTIVE'
  return {
    collection: 'project_messenger_chat',
    id: chatKey,
    data: cleanObject({
      chat_key: chatKey,
      project_key: safeString(message.project_key, 80),
      group_key: safeString(message.group_key, 40),
      conversation_type: safeString(message.conversation_type, 20, 'group'),
      direct_recipient_user_key: safeString(message.direct_recipient_user_key, 80),
      reply_to_chat_key: safeString(message.reply_to_chat_key, 40),
      sender_user_key: safeString(message.sender_user_key, 80),
      sender_name: safeString(message.sender_name, 160, 'Portal User'),
      message_text: status === 'REMOVED' ? '' : String(message.message_text || '').slice(0, 8000),
      message_type: safeString(message.message_type, 20, 'text'),
      message_status: status,
      removed_at: safeString(message.removed_at, 32),
      removed_by_user_key: safeString(message.removed_by_user_key, 80),
      firebase_collection: 'project_messenger_chat',
      mysql_created_at: safeString(message.created_at, 32),
      mysql_updated_at: safeString(message.updated_at, 32),
      mysql_sync_status: 'PENDING',
      mysql_synced_at: null,
      mysql_deleted_at: status === 'REMOVED' ? safeString(message.removed_at, 32) : null,
      mysql_sync_error: FieldValue.delete(),
      server_synced_at: FieldValue.serverTimestamp(),
      created_at: safeString(message.created_at, 32),
      updated_at: safeString(message.updated_at, 32),
    }),
  }
}

function attachmentDocument(attachment) {
  const attachmentKey = safeString(attachment.attachment_key, 40)
  if (attachmentKey === '') throw new Error('messenger_event_attachment_key_required')
  return {
    collection: 'project_messenger_chat_attachment',
    id: attachmentKey,
    data: cleanObject({
      attachment_key: attachmentKey,
      chat_key: safeString(attachment.chat_key, 40),
      project_key: safeString(attachment.project_key, 80),
      group_key: safeString(attachment.group_key, 40),
      uploaded_image_url: safeString(attachment.uploaded_image_url, 500),
      image_original_name: safeString(attachment.image_original_name, 255),
      image_mime_type: safeString(attachment.image_mime_type, 100),
      image_byte_size: Math.max(0, Number(attachment.image_byte_size || 0)),
      image_sha256: safeString(attachment.image_sha256, 128),
      sort_order: Math.max(0, Number(attachment.sort_order || 0)),
      attachment_status: String(attachment.attachment_status || 'ACTIVE') === 'REMOVED' ? 'REMOVED' : 'ACTIVE',
      firebase_collection: 'project_messenger_chat_attachment',
      mysql_created_at: safeString(attachment.created_at, 32),
      mysql_updated_at: safeString(attachment.updated_at, 32),
      mysql_sync_status: 'PENDING',
      mysql_synced_at: null,
      mysql_deleted_at: String(attachment.attachment_status || 'ACTIVE') === 'REMOVED' ? safeString(attachment.updated_at, 32) : null,
      mysql_sync_error: FieldValue.delete(),
      server_synced_at: FieldValue.serverTimestamp(),
      created_at: safeString(attachment.created_at, 32),
    }),
  }
}

function reactionDocument(reaction) {
  const reactionKey = safeString(reaction.reaction_key, 40)
  if (reactionKey === '') throw new Error('messenger_event_reaction_key_required')
  return {
    collection: 'project_messenger_chat_reaction',
    id: reactionKey,
    data: cleanObject({
      reaction_key: reactionKey,
      chat_key: safeString(reaction.chat_key, 40),
      project_key: safeString(reaction.project_key, 80),
      group_key: safeString(reaction.group_key, 40),
      user_key: safeString(reaction.user_key, 80),
      reaction_value: safeString(reaction.reaction_value, 40),
      reaction_status: String(reaction.reaction_status || 'REMOVED') === 'ACTIVE' ? 'ACTIVE' : 'REMOVED',
      firebase_collection: 'project_messenger_chat_reaction',
      mysql_created_at: safeString(reaction.created_at, 32),
      mysql_updated_at: safeString(reaction.updated_at, 32),
      mysql_sync_status: 'PENDING',
      mysql_synced_at: null,
      mysql_deleted_at: String(reaction.reaction_status || 'REMOVED') === 'REMOVED' ? safeString(reaction.updated_at, 32) : null,
      mysql_sync_error: FieldValue.delete(),
      server_synced_at: FieldValue.serverTimestamp(),
      created_at: safeString(reaction.created_at, 32),
      updated_at: safeString(reaction.updated_at, 32),
    }),
  }
}

function documentsForEvent(event) {
  const payload = eventPayload(event)
  const eventType = String(event.event_type || '')
  if (eventType === 'MESSAGE_CREATED' || eventType === 'MESSAGE_REMOVED') {
    const documents = [messageDocument(payload.message || {})]
    for (const attachment of Array.isArray(payload.attachments) ? payload.attachments : []) {
      documents.push(attachmentDocument(attachment))
    }
    return documents
  }
  if (eventType === 'REACTION_CHANGED') {
    return (Array.isArray(payload.reactions) ? payload.reactions : []).map(reactionDocument)
  }
  throw new Error(`messenger_event_type_unsupported:${eventType}`)
}

async function claimEvents(pool) {
  const connection = await pool.getConnection()
  try {
    await connection.beginTransaction()
    await connection.execute(
      `UPDATE project_messenger_sync_event
       SET status = 'FAILED', claim_key = NULL, available_at = CURRENT_TIMESTAMP,
           last_error = 'worker_recovered_stale_claim'
       WHERE target = 'firebase' AND status = 'PROCESSING'
         AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ${claimTimeoutMinutes} MINUTE)`,
    )
    const [rows] = await connection.query(
      `SELECT x_id, event_key, event_type, project_key, group_key, chat_key, payload_json,
          status, attempt_count
       FROM project_messenger_sync_event
       WHERE target = 'firebase'
         AND status IN ('PENDING','FAILED')
         AND available_at <= CURRENT_TIMESTAMP
       ORDER BY x_id ASC
       LIMIT ${batchSize}
       FOR UPDATE`,
    )
    if (Array.isArray(rows) && rows.length > 0) {
      const ids = rows.map((row) => Number(row.x_id)).filter((value) => Number.isInteger(value) && value > 0)
      const placeholders = ids.map(() => '?').join(',')
      await connection.execute(
        `UPDATE project_messenger_sync_event
         SET status = 'PROCESSING', claim_key = ?, attempt_count = attempt_count + 1,
             last_error = NULL, updated_at = CURRENT_TIMESTAMP
         WHERE x_id IN (${placeholders})`,
        [workerKey, ...ids],
      )
      await connection.commit()
      return rows
    }
    await connection.commit()
    return []
  } catch (error) {
    await connection.rollback().catch(() => {})
    throw error
  } finally {
    connection.release()
  }
}

async function markSynced(pool, event) {
  const connection = await pool.getConnection()
  try {
    await connection.beginTransaction()
    await connection.execute(
      `UPDATE project_messenger_sync_event
       SET status = 'SYNCED', processed_at = CURRENT_TIMESTAMP, claim_key = NULL,
           last_error = NULL, updated_at = CURRENT_TIMESTAMP
       WHERE x_id = ? AND claim_key = ?`,
      [event.x_id, workerKey],
    )
    if (event.chat_key) {
      await connection.execute(
        `UPDATE project_messenger_chat
         SET firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP
         WHERE chat_key = ?`,
        [event.chat_key],
      )
      await connection.execute(
        `UPDATE project_messenger_chat_attachment
         SET firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP
         WHERE chat_key = ?`,
        [event.chat_key],
      )
      if (String(event.event_type || '') === 'REACTION_CHANGED') {
        await connection.execute(
          `UPDATE project_messenger_chat_reaction
           SET firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP
           WHERE chat_key = ?`,
          [event.chat_key],
        )
      }
    }
    await connection.commit()
  } catch (error) {
    await connection.rollback().catch(() => {})
    throw error
  } finally {
    connection.release()
  }
}

async function markFailed(pool, event, error) {
  const attempt = Math.max(1, Number(event.attempt_count || 1) + 1)
  const delaySeconds = Math.min(3600, 5 * (2 ** Math.min(attempt, 8)))
  const message = String(error instanceof Error ? error.message : 'firebase_sync_failed').slice(0, 1000)
  await pool.execute(
    `UPDATE project_messenger_sync_event
     SET status = 'FAILED', claim_key = NULL, last_error = ?,
         available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ${delaySeconds} SECOND),
         updated_at = CURRENT_TIMESTAMP
     WHERE x_id = ? AND claim_key = ?`,
    [message, event.x_id, workerKey],
  )
  if (event.chat_key) {
    await pool.execute(
      'UPDATE project_messenger_chat SET firebase_sync_status = \'FAILED\' WHERE chat_key = ?',
      [event.chat_key],
    )
  }
}

async function writeEvent(db, event) {
  const documents = documentsForEvent(event)
  const batch = db.batch()
  for (const document of documents) {
    batch.set(db.collection(document.collection).doc(document.id), document.data, { merge: true })
  }
  await batch.commit()
  return documents.length
}

function sleep(milliseconds) {
  return new Promise((resolve) => setTimeout(resolve, milliseconds))
}

async function main() {
  initializeFirebase()
  const pool = mysql.createPool(mysqlConfig())
  const db = getFirestore()
  console.log(JSON.stringify({ ok: true, status: 'queue_listening', target: 'firebase', batch_size: batchSize, poll_ms: pollMs }))

  const shutdown = async () => {
    if (shuttingDown) return
    shuttingDown = true
    await pool.end()
    process.exit(0)
  }
  process.on('SIGINT', () => void shutdown())
  process.on('SIGTERM', () => void shutdown())

  while (!shuttingDown) {
    try {
      const events = await claimEvents(pool)
      if (events.length === 0) {
        await sleep(pollMs)
        continue
      }
      for (const event of events) {
        try {
          const documentCount = await writeEvent(db, event)
          await markSynced(pool, event)
          console.log(JSON.stringify({ ok: true, synced: event.event_key, event_type: event.event_type, documents: documentCount }))
        } catch (error) {
          await markFailed(pool, event, error).catch(() => {})
          console.error(JSON.stringify({ ok: false, event: event.event_key, event_type: event.event_type, message: error instanceof Error ? error.message : 'firebase_event_sync_failed' }))
        }
      }
    } catch (error) {
      console.error(JSON.stringify({ ok: false, worker: 'firebase_queue', message: error instanceof Error ? error.message : 'queue_poll_failed' }))
      await sleep(Math.max(pollMs, 5000))
    }
  }
}

main().catch((error) => {
  console.error(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_queue_start_failed' }))
  process.exit(1)
})
