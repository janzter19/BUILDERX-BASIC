import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'
import mysql from 'mysql2/promise'

const rootDir = path.dirname(fileURLToPath(import.meta.url))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const collectionName = String(process.env.FIREBASE_MESSENGER_COLLECTION || 'project_messenger_chat').trim()
const tableName = 'project_messenger_chat'
const pendingStatus = 'PENDING'
let stopping = false
let processing = Promise.resolve()

function printLog(payload, error = false) {
  const output = `${JSON.stringify(payload)}\n${'-'.repeat(80)}\n`
  if (error) {
    process.stderr.write(output)
    return
  }
  process.stdout.write(output)
}

function requiredEnv(name, fallback = '') {
  const value = String(process.env[name] || fallback).trim()
  if (value === '') throw new Error(`${name}_required`)
  return value
}

function loadProjectDatabaseConfig() {
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
      cwd: rootDir,
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

const projectDatabaseConfig = loadProjectDatabaseConfig()

function mysqlConfig() {
  return {
    host: requiredEnv('DB_HOST', String(projectDatabaseConfig.host || 'localhost')),
    port: Number(process.env.DB_PORT || projectDatabaseConfig.port || 3306),
    user: requiredEnv('DB_USERNAME', String(projectDatabaseConfig.user || '')),
    password: String(process.env.DB_PASSWORD || projectDatabaseConfig.password || ''),
    database: requiredEnv('DB_DATABASE', String(projectDatabaseConfig.database || '')),
    waitForConnections: true,
    connectionLimit: 2,
    charset: 'utf8mb4',
    dateStrings: true,
  }
}

function initializeFirebase() {
  if (getApps().length > 0) return
  const projectId = requiredEnv('FIREBASE_PROJECT_ID')
  const serviceAccountPath = requiredEnv(
    'GOOGLE_APPLICATION_CREDENTIALS',
    process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '',
  )
  initializeApp({ credential: cert(serviceAccountPath), projectId })
}

function stringValue(value, maxLength, fallback = '') {
  return String(value ?? fallback).trim().slice(0, maxLength)
}

function timestampValue(value, fallback = new Date()) {
  if (value && typeof value.toDate === 'function') value = value.toDate()
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return value.toISOString().slice(0, 19).replace('T', ' ')
  }
  const text = String(value ?? '').trim()
  if (text === '') return timestampValue(fallback)

  const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(text)
  if (!hasTimezone && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/.test(text)) {
    return text.slice(0, 19).replace('T', ' ')
  }

  const parsed = new Date(text)
  return Number.isNaN(parsed.getTime())
    ? text.slice(0, 19).replace('T', ' ')
    : parsed.toISOString().slice(0, 19).replace('T', ' ')
}

function enumValue(value, allowed, fallback) {
  const normalized = String(value ?? '').trim()
  return allowed.includes(normalized) ? normalized : fallback
}

function messageFromDocument(document) {
  const data = document.data() || {}
  const chatKey = stringValue(data.chat_key || document.id, 40)
  const projectKey = stringValue(data.project_key, 80)
  const groupKey = stringValue(data.group_key, 40)
  const senderUserKey = stringValue(data.sender_user_key, 80)
  const senderName = stringValue(data.sender_name, 160)

  if (chatKey === '') throw new Error('chat_key_required')
  if (projectKey === '') throw new Error('project_key_required')
  if (groupKey === '') throw new Error('group_key_required')
  if (senderUserKey === '') throw new Error('sender_user_key_required')
  if (senderName === '') throw new Error('sender_name_required')

  return {
    chatKey,
    projectKey,
    groupKey,
    conversationType: enumValue(data.conversation_type, ['group', 'direct'], 'group'),
    directRecipientUserKey: stringValue(data.direct_recipient_user_key, 80) || null,
    replyToChatKey: stringValue(data.reply_to_chat_key, 40) || null,
    senderUserKey,
    senderName,
    messageText: data.message_text == null ? null : String(data.message_text).slice(0, 8000),
    messageType: enumValue(data.message_type, ['text', 'image', 'mixed'], 'text'),
    messageStatus: enumValue(data.message_status, ['ACTIVE', 'REMOVED'], 'ACTIVE'),
    removedAt: data.removed_at ? timestampValue(data.removed_at) : null,
    removedByUserKey: stringValue(data.removed_by_user_key, 80) || null,
    createdAt: timestampValue(data.created_at),
    updatedAt: timestampValue(data.updated_at),
  }
}

async function upsertMySQL(pool, message) {
  await pool.execute(
    `INSERT INTO ${tableName} (
       chat_key, project_key, group_key, conversation_type,
       direct_recipient_user_key, reply_to_chat_key, sender_user_key,
       sender_name, message_text, message_type, message_status, removed_at,
       removed_by_user_key, firebase_collection, firebase_sync_status,
       firebase_synced_at, created_at, updated_at
     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'SYNCED', CURRENT_TIMESTAMP, ?, ?)
     ON DUPLICATE KEY UPDATE
       project_key = VALUES(project_key),
       group_key = VALUES(group_key),
       conversation_type = VALUES(conversation_type),
       direct_recipient_user_key = VALUES(direct_recipient_user_key),
       reply_to_chat_key = VALUES(reply_to_chat_key),
       sender_user_key = VALUES(sender_user_key),
       sender_name = VALUES(sender_name),
       message_text = VALUES(message_text),
       message_type = VALUES(message_type),
       message_status = VALUES(message_status),
       removed_at = VALUES(removed_at),
       removed_by_user_key = VALUES(removed_by_user_key),
       firebase_collection = VALUES(firebase_collection),
       firebase_sync_status = 'SYNCED',
       firebase_synced_at = CURRENT_TIMESTAMP,
       updated_at = VALUES(updated_at)`,
    [
      message.chatKey,
      message.projectKey,
      message.groupKey,
      message.conversationType,
      message.directRecipientUserKey,
      message.replyToChatKey,
      message.senderUserKey,
      message.senderName,
      message.messageText,
      message.messageType,
      message.messageStatus,
      message.removedAt,
      message.removedByUserKey,
      collectionName,
      message.createdAt,
      message.updatedAt,
    ],
  )
}

async function markFirebaseSynced(db, document) {
  const reference = db.collection(collectionName).doc(document.id)
  return db.runTransaction(async (transaction) => {
    const latest = await transaction.get(reference)
    if (!latest.exists) return 'document_missing'
    if (latest.get('mysql_sync_status') !== pendingStatus) return 'status_changed'

    if (document.updateTime && latest.updateTime) {
      const originalTime = document.updateTime.toMillis()
      const latestTime = latest.updateTime.toMillis()
      if (originalTime !== latestTime) return 'document_changed'
    }

    transaction.update(reference, {
      mysql_sync_status: 'SYNCED',
      mysql_synced_at: FieldValue.serverTimestamp(),
      mysql_sync_error: FieldValue.delete(),
    })
    return 'synced'
  })
}

async function markMySQLRemoved(pool, chatKey) {
  const [result] = await pool.execute(
    `UPDATE ${tableName}
     SET message_status = 'REMOVED', removed_at = CURRENT_TIMESTAMP,
         firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP
     WHERE chat_key = ?`,
    [chatKey],
  )
  return Number(result.affectedRows || 0)
}

function logEvent(event, document, details = {}) {
  printLog({
    ok: true,
    collection: collectionName,
    event,
    key: document.id,
    detected_at: new Date().toISOString(),
    ...details,
    data: document.exists ? document.data() : undefined,
  })
}

function enqueue(task) {
  processing = processing.then(task).catch((error) => {
    printLog({
      ok: false,
      collection: collectionName,
      error: error instanceof Error ? error.message : 'change_processing_failed',
    }, true)
  })
}

async function processChange(db, pool, change) {
  const event = change.type.toUpperCase()

  if (change.type === 'removed') {
    if (change.doc.exists) {
      logEvent(event, change.doc, { reason: 'document_left_pending_filter' })
      return
    }

    const chatKey = stringValue(change.doc.id, 40)
    const affectedRows = chatKey === '' ? 0 : await markMySQLRemoved(pool, chatKey)
    logEvent(event, change.doc, { reason: 'firebase_document_deleted', mysql_rows_updated: affectedRows })
    return
  }

  const message = messageFromDocument(change.doc)
  await upsertMySQL(pool, message)
  const syncResult = await markFirebaseSynced(db, change.doc)
  logEvent(event, change.doc, { mysql_upserted: true, firebase_status: syncResult })
}

async function main() {
  initializeFirebase()
  const db = getFirestore()
  const pool = mysql.createPool(mysqlConfig())
  await pool.query(`SELECT 1 FROM ${tableName} LIMIT 1`)

  let initialSnapshot = true

  printLog({
    ok: true,
    status: 'monitoring',
    collection: collectionName,
    source: 'firestore_onSnapshot',
    filter: { field: 'mysql_sync_status', operator: '==', value: pendingStatus },
    mysql_table: tableName,
    note: 'The initial snapshot establishes a baseline and is not processed.',
  })

  const pendingQuery = db.collection(collectionName).where('mysql_sync_status', '==', pendingStatus)
  const unsubscribe = pendingQuery.onSnapshot((snapshot) => {
    if (initialSnapshot) {
      initialSnapshot = false
      printLog({
        ok: true,
        status: 'baseline_ready',
        collection: collectionName,
        documents: snapshot.size,
      })
      return
    }

    for (const change of snapshot.docChanges()) {
      enqueue(() => processChange(db, pool, change))
    }
  }, (error) => {
    printLog({
      ok: false,
      collection: collectionName,
      error: error instanceof Error ? error.message : 'firestore_listener_failed',
    }, true)
  })

  const shutdown = async () => {
    if (stopping) return
    stopping = true
    unsubscribe()
    await processing
    await pool.end()
    printLog({ ok: true, status: 'stopped', collection: collectionName })
  }

  process.on('SIGINT', () => void shutdown().then(() => process.exit(0)))
  process.on('SIGTERM', () => void shutdown().then(() => process.exit(0)))
}

main().catch((error) => {
  printLog({
    ok: false,
    collection: collectionName,
    error: error instanceof Error ? error.message : 'firestore_mysql_monitor_start_failed',
  }, true)
  process.exit(1)
})
