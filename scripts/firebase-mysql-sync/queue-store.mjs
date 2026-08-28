import { COLLECTIONS, quoteIdentifier } from './registry.mjs'

const QUEUE_STATES = new Set(['QUEUED', 'CLAIMED', 'RETRY_WAIT', 'ACK_PENDING', 'ACKED', 'SUPERSEDED', 'DEAD_LETTER'])
const CONTROL_TABLES = [
  `CREATE TABLE IF NOT EXISTS project_traverse_document (xId INT(10) NOT NULL AUTO_INCREMENT, firebase_collection VARCHAR(80) NOT NULL, traverse_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE', PRIMARY KEY (xId), UNIQUE KEY uq_project_traverse_collection (firebase_collection), KEY idx_project_traverse_document_status (traverse_status, firebase_collection)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS project_traverse_runtime (xId TINYINT UNSIGNED NOT NULL, service_name VARCHAR(80) NOT NULL, service_status ENUM('STARTING','RUNNING','STOPPING','STOPPED','ERROR') NOT NULL DEFAULT 'STARTING', process_id BIGINT UNSIGNED NULL, started_at DATETIME(6) NULL, last_heartbeat_at DATETIME(6) NULL, last_event_at DATETIME(6) NULL, firebase_reads_observed BIGINT UNSIGNED NOT NULL DEFAULT 0, pending_count BIGINT UNSIGNED NOT NULL DEFAULT 0, retry_count BIGINT UNSIGNED NOT NULL DEFAULT 0, dead_letter_count BIGINT UNSIGNED NOT NULL DEFAULT 0, last_error_code VARCHAR(120) NULL, last_error_at DATETIME(6) NULL, updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (xId), UNIQUE KEY uq_project_traverse_runtime_service (service_name), KEY idx_project_traverse_runtime_status (service_status, updated_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS project_traverse_log (xId BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, event_type VARCHAR(64) NOT NULL, event_status ENUM('INFO','SUCCESS','WARNING','ERROR') NOT NULL DEFAULT 'INFO', firebase_collection VARCHAR(80) NULL, firebase_document_id VARCHAR(255) NULL, queue_xId BIGINT UNSIGNED NULL, error_code VARCHAR(120) NULL, error_detail VARCHAR(1000) NULL, attempt_count INT UNSIGNED NULL, firebase_reads_observed BIGINT UNSIGNED NULL, duration_ms BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (xId), KEY idx_project_traverse_log_created (created_at), KEY idx_project_traverse_log_status_created (event_status, created_at), KEY idx_project_traverse_log_type_created (event_type, created_at), KEY idx_project_traverse_log_collection_created (firebase_collection, created_at), KEY idx_project_traverse_log_queue_created (queue_xId, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_collection_registry (collection_name VARCHAR(80) PRIMARY KEY, table_name VARCHAR(80) NOT NULL, key_column VARCHAR(80) NOT NULL, enabled TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6)) ENGINE=InnoDB`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_field_registry (collection_name VARCHAR(80) NOT NULL, field_name VARCHAR(80) NOT NULL, ordinal_no INT UNSIGNED NOT NULL, inferred_type VARCHAR(32) NOT NULL, observed_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (collection_name, field_name), UNIQUE KEY uq_firebase_mysql_sync_field_ordinal (collection_name, ordinal_no)) ENGINE=InnoDB`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_queue (x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collection_name VARCHAR(80) NOT NULL, document_id VARCHAR(255) NOT NULL, source_revision VARCHAR(80) NOT NULL, state ENUM('QUEUED','CLAIMED','RETRY_WAIT','ACK_PENDING','ACKED','SUPERSEDED','DEAD_LETTER') NOT NULL DEFAULT 'QUEUED', resume_state VARCHAR(32) NULL, attempt_count INT UNSIGNED NOT NULL DEFAULT 0, lease_key VARCHAR(80) NULL, lease_expires_at DATETIME(6) NULL, next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), last_error_code VARCHAR(120) NULL, payload_fingerprint CHAR(64) NOT NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), UNIQUE KEY uq_firebase_mysql_sync_revision (collection_name, document_id, source_revision), KEY idx_firebase_mysql_sync_ready (state, next_attempt_at, lease_expires_at), KEY idx_firebase_mysql_sync_document (collection_name, document_id)) ENGINE=InnoDB`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_projection_state (collection_name VARCHAR(80) NOT NULL, document_id VARCHAR(255) NOT NULL, source_revision VARCHAR(80) NOT NULL, payload_fingerprint CHAR(64) NOT NULL, projected_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY (collection_name, document_id)) ENGINE=InnoDB`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_migration_history (x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, collection_name VARCHAR(80) NOT NULL, table_name VARCHAR(80) NOT NULL, backup_table_name VARCHAR(100) NOT NULL, repair_kind VARCHAR(40) NOT NULL, status ENUM('BACKED_UP','COMPLETED','FAILED') NOT NULL, source_row_count BIGINT UNSIGNED NOT NULL, backup_row_count BIGINT UNSIGNED NOT NULL, source_checksum VARCHAR(255) NULL, backup_checksum VARCHAR(255) NULL, pre_schema_fingerprint CHAR(64) NOT NULL, post_schema_fingerprint CHAR(64) NULL, error_code VARCHAR(120) NULL, started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), completed_at DATETIME(6) NULL, KEY idx_firebase_mysql_sync_migration_table (table_name, backup_table_name, started_at)) ENGINE=InnoDB`,
  `CREATE TABLE IF NOT EXISTS firebase_mysql_sync_attempt_history (x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, queue_id BIGINT UNSIGNED NOT NULL, from_state VARCHAR(32) NOT NULL, to_state VARCHAR(32) NOT NULL, error_code VARCHAR(120) NULL, created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), KEY idx_firebase_mysql_sync_attempt_queue (queue_id, created_at)) ENGINE=InnoDB`,
]

export async function ensureControlTables(pool, contracts) {
  for (const sql of CONTROL_TABLES) await pool.query(sql)
  for (const [collection, contract] of Object.entries(contracts)) await pool.execute(`INSERT INTO firebase_mysql_sync_collection_registry (collection_name, table_name, key_column) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE table_name = VALUES(table_name), key_column = VALUES(key_column)`, [collection, contract.table, contract.key])
}

export async function recordTraverseEvent(pool, { eventType, eventStatus = 'INFO', collection = null, documentId = null, queueId = null, errorCode = null, errorDetail = null, attemptCount = null, firebaseReads = null, durationMs = null, processId = process.pid, serviceStatus = null, startedAt = null } = {}) {
  const safeDetail = errorDetail === null || errorDetail === undefined ? null : String(errorDetail).replace(/[\r\n\t]+/g, ' ').substring(0, 1000)
  await pool.execute(`INSERT INTO project_traverse_log (event_type, event_status, firebase_collection, firebase_document_id, queue_xId, error_code, error_detail, attempt_count, firebase_reads_observed, duration_ms) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, [String(eventType || 'UNKNOWN').substring(0, 64), ['INFO', 'SUCCESS', 'WARNING', 'ERROR'].includes(eventStatus) ? eventStatus : 'INFO', collection ? String(collection).substring(0, 80) : null, documentId ? String(documentId).substring(0, 255) : null, queueId === null ? null : Number(queueId), errorCode ? String(errorCode).substring(0, 120) : null, safeDetail, attemptCount === null ? null : Number(attemptCount), firebaseReads === null ? null : Number(firebaseReads), durationMs === null ? null : Number(durationMs)])
  await pool.execute(`INSERT INTO project_traverse_runtime (xId, service_name, service_status, process_id, started_at, last_heartbeat_at, last_event_at, firebase_reads_observed, last_error_code, last_error_at) VALUES (1, 'TRAVERSE', COALESCE(?, 'RUNNING'), ?, COALESCE(?, CURRENT_TIMESTAMP(6)), CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6), COALESCE(?, 0), ?, IF(? IS NULL, NULL, CURRENT_TIMESTAMP(6))) ON DUPLICATE KEY UPDATE service_status = COALESCE(VALUES(service_status), service_status), process_id = COALESCE(VALUES(process_id), process_id), started_at = COALESCE(project_traverse_runtime.started_at, VALUES(started_at)), last_heartbeat_at = CURRENT_TIMESTAMP(6), last_event_at = CURRENT_TIMESTAMP(6), firebase_reads_observed = COALESCE(VALUES(firebase_reads_observed), firebase_reads_observed), last_error_code = COALESCE(VALUES(last_error_code), last_error_code), last_error_at = IF(VALUES(last_error_code) IS NULL, last_error_at, CURRENT_TIMESTAMP(6))`, [serviceStatus, processId, startedAt, firebaseReads, errorCode, errorCode])
}

export async function updateTraverseRuntimeQueueCounts(pool) {
  await pool.query(`UPDATE project_traverse_runtime SET pending_count = (SELECT COUNT(*) FROM firebase_mysql_sync_queue WHERE state IN ('QUEUED','CLAIMED','RETRY_WAIT','ACK_PENDING')), retry_count = (SELECT COUNT(*) FROM firebase_mysql_sync_queue WHERE state = 'RETRY_WAIT'), dead_letter_count = (SELECT COUNT(*) FROM firebase_mysql_sync_queue WHERE state = 'DEAD_LETTER'), last_heartbeat_at = CURRENT_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6) WHERE xId = 1`)
}

export async function loadTraverseCollections(pool, contracts) {
  const [rows] = await pool.execute(`SELECT firebase_collection FROM project_traverse_document WHERE traverse_status = 'ACTIVE' ORDER BY xId`)
  const configured = rows.map((row) => String(row.firebase_collection)).filter((collection) => collection !== '')
  for (const collection of configured) if (!contracts[collection]) throw new Error('traverse_collection_contract_missing')
  return configured
}

export async function enqueueRevision(pool, item) {
  const [result] = await pool.execute(`INSERT IGNORE INTO firebase_mysql_sync_queue (collection_name, document_id, source_revision, payload_fingerprint) VALUES (?, ?, ?, ?)`, [item.collection, item.documentId, item.revision, item.fingerprint])
  if (Number(result.affectedRows) === 1) return 'inserted'
  const [rows] = await pool.execute(`SELECT payload_fingerprint FROM firebase_mysql_sync_queue WHERE collection_name = ? AND document_id = ? AND source_revision = ? LIMIT 1`, [item.collection, item.documentId, item.revision])
  if (rows.length !== 1 || rows[0].payload_fingerprint !== item.fingerprint) throw new Error('queue_revision_fingerprint_conflict')
  return 'duplicate'
}

// Read-only operational view. The worker still claims only ready queue rows;
// this helper is intentionally limited to work that has not reached a
// terminal state so dashboards never need to scan Firestore.
export async function loadPendingQueue(pool, { collection = '', limit = 100 } = {}) {
  const safeLimit = Math.max(1, Math.min(500, Number(limit) || 100))
  const filterCollection = String(collection || '').trim()
  const sql = `SELECT x_id, collection_name, document_id, state, attempt_count, next_attempt_at, last_error_code, created_at, updated_at
    FROM firebase_mysql_sync_queue
    WHERE state IN ('QUEUED','CLAIMED','RETRY_WAIT','ACK_PENDING')
      ${filterCollection === '' ? '' : 'AND collection_name = ?'}
    ORDER BY x_id ASC LIMIT ${safeLimit}`
  const [rows] = await pool.execute(sql, filterCollection === '' ? [] : [filterCollection])
  return rows
}

export async function claimReady(pool, workerKey, leaseSeconds, limit, excludedCollections = []) {
  const excluded = new Set(excludedCollections)
  for (const collection of excluded) if (!COLLECTIONS[collection]) throw new Error('collection_not_allowlisted')
  const available = Object.keys(COLLECTIONS).filter((collection) => !excluded.has(collection))
  if (available.length === 0 || limit < 1) return []
  const connection = await pool.getConnection()
  try {
    await connection.beginTransaction()
    const candidates = []
    for (const collection of available) {
      const [rows] = await connection.execute(`SELECT x_id, collection_name, document_id, source_revision, payload_fingerprint, attempt_count, IF(state = 'CLAIMED', resume_state, state) AS prior_state FROM firebase_mysql_sync_queue WHERE collection_name = ? AND ((state IN ('QUEUED','RETRY_WAIT') AND next_attempt_at <= CURRENT_TIMESTAMP(6)) OR (state = 'ACK_PENDING' AND next_attempt_at <= CURRENT_TIMESTAMP(6) AND (lease_key IS NULL OR lease_expires_at < CURRENT_TIMESTAMP(6))) OR (state = 'CLAIMED' AND lease_expires_at < CURRENT_TIMESTAMP(6))) ORDER BY x_id ASC LIMIT 1 FOR UPDATE SKIP LOCKED`, [collection])
      if (rows[0]) candidates.push(rows[0])
    }
    candidates.sort((left, right) => Number(left.x_id) - Number(right.x_id))
    const rows = []
    for (const candidate of candidates) { if (rows.length >= limit) break; rows.push(candidate) }
    for (const row of rows) {
      const [updated] = await connection.execute(`UPDATE firebase_mysql_sync_queue SET resume_state = ?, state = 'CLAIMED', lease_key = ?, lease_expires_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ? SECOND), attempt_count = attempt_count + 1 WHERE x_id = ? AND ((state = ? AND state <> 'CLAIMED') OR (state = 'CLAIMED' AND lease_expires_at < CURRENT_TIMESTAMP(6)))`, [row.prior_state, workerKey, leaseSeconds, row.x_id, row.prior_state])
      if (Number(updated.affectedRows) !== 1) throw new Error('queue_claim_lost')
      await connection.execute(`INSERT INTO firebase_mysql_sync_attempt_history (queue_id, from_state, to_state, error_code) VALUES (?, ?, 'CLAIMED', NULL)`, [row.x_id, row.prior_state])
      row.attempt_count = Number(row.attempt_count) + 1
    }
    await connection.commit()
    return rows
  } catch (error) { await connection.rollback(); throw error } finally { connection.release() }
}

export async function transitionQueue(pool, row, workerKey, toState, errorCode = null, delaySeconds = 0, fromState = 'CLAIMED', releaseLeaseOverride = null) {
  if (!QUEUE_STATES.has(toState) || toState === 'CLAIMED') throw new Error('queue_state_invalid')
  const connection = await pool.getConnection()
  try {
    await connection.beginTransaction()
    const release = releaseLeaseOverride === null ? (toState === 'ACK_PENDING' ? 0 : 1) : (releaseLeaseOverride ? 1 : 0)
    const [updated] = await connection.execute(`UPDATE firebase_mysql_sync_queue SET state = ?, resume_state = NULL, lease_key = IF(? = 1, NULL, lease_key), lease_expires_at = IF(? = 1, NULL, lease_expires_at), last_error_code = ?, next_attempt_at = DATE_ADD(CURRENT_TIMESTAMP(6), INTERVAL ? SECOND) WHERE x_id = ? AND state = ? AND lease_key = ?`, [toState, release, release, errorCode, delaySeconds, row.x_id, fromState, workerKey])
    if (Number(updated.affectedRows) !== 1) throw new Error('queue_transition_lost')
    await connection.execute(`INSERT INTO firebase_mysql_sync_attempt_history (queue_id, from_state, to_state, error_code) VALUES (?, ?, ?, ?)`, [row.x_id, fromState, toState, errorCode])
    await connection.commit()
  } catch (error) { await connection.rollback(); throw error } finally { connection.release() }
}

export function backoffSeconds(attempt, random = Math.random) { const base = Math.min(3600, 2 ** Math.min(11, Math.max(0, Number(attempt)))); return Math.min(3600, base + Math.floor(random() * Math.min(30, Math.max(1, base)))) }
export function failureOutcome(code, attempt, maxAttempts) {
  const terminal = /identifier|mismatch|unsupported|limit|invalid|conflict|unsafe|too_large|incompatible|allowlisted|disabled|deleted|not_pending|required_field|no_default|credential|metadata_mismatch/.test(String(code).toLowerCase()) || Number(attempt) >= Number(maxAttempts)
  return terminal ? 'DEAD_LETTER' : 'RETRY_WAIT'
}
export function projectionDecision(committed, incomingRevision, incomingFingerprint, compare) {
  if (!committed) return 'APPLY'
  const order = compare(incomingRevision, committed.source_revision)
  if (order < 0) return 'SUPERSEDED'
  if (order > 0) return 'APPLY'
  return committed.payload_fingerprint === incomingFingerprint ? 'IDEMPOTENT' : 'REVISION_CONFLICT'
}

export function projectionSql(contract, fieldNames) {
  const key = quoteIdentifier(contract.key)
  const columns = fieldNames.map(quoteIdentifier)
  if (!columns.includes(key)) columns.unshift(key)
  const updates = columns.filter((column) => column !== key).map((column) => `${column} = VALUES(${column})`).join(', ')
  return `INSERT INTO ${quoteIdentifier(contract.table)} (${columns.join(', ')}) VALUES (${columns.map(() => '?').join(', ')}) ON DUPLICATE KEY UPDATE ${updates}`
}
