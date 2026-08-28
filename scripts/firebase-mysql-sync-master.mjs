import crypto from 'node:crypto'
import { realpathSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import mysql from 'mysql2/promise'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { getFirestore } from 'firebase-admin/firestore'
import { loadConfig } from './firebase-mysql-sync/config.mjs'
import { COLLECTIONS } from './firebase-mysql-sync/registry.mjs'
import { claimReady, ensureControlTables, loadTraverseCollections, recordTraverseEvent, updateTraverseRuntimeQueueCounts } from './firebase-mysql-sync/queue-store.mjs'
import { discoverSnapshot, processClaimed } from './firebase-mysql-sync/worker.mjs'
import { safeErrorCode, telemetry } from './firebase-mysql-sync/telemetry.mjs'

export function isMainModule({ invocationPath = process.argv[1], moduleUrl = import.meta.url, canonicalize = realpathSync } = {}) {
  if (typeof invocationPath !== 'string' || invocationPath.length === 0) return false
  try {
    return canonicalize(invocationPath) === canonicalize(fileURLToPath(moduleUrl))
  } catch {
    return false
  }
}

export async function scanPendingPages({ db, pool, collection, config, shouldStop = () => false, onRead = () => {} }) {
  let cursor = null; let discovered = 0
  do {
    let query = db.collection(collection).where('mysql_sync_status', '==', 'PENDING').orderBy('__name__').limit(config.scanPageSize)
    if (cursor) query = query.startAfter(cursor)
    const snapshot = await query.get()
    onRead(snapshot.size, collection, 'startup_recovery_scan')
    for (const original of snapshot.docs) {
      if (shouldStop()) break
      await discoverSnapshot(pool, collection, original, config); discovered++
    }
    cursor = snapshot.docs.at(-1) || null
    if (snapshot.size < config.scanPageSize) break
  } while (!shouldStop())
  return discovered
}

export function attachPendingListeners({ db, pool, config, collections = Object.keys(COLLECTIONS), track = (promise) => promise, isStopping = () => false, discover = discoverSnapshot, onRead = () => {}, setupFailures = [] }) {
  const unsubscribers = []
  for (const collection of collections) {
    try {
      const source = db.collection(collection).where('mysql_sync_status', '==', 'PENDING')
      const unsubscribe = source.onSnapshot((snapshot) => {
        if (isStopping()) return
        onRead(snapshot.docChanges().filter((change) => change.type !== 'removed').length, collection, 'listener')
        for (const change of snapshot.docChanges()) if (change.type !== 'removed') track((async () => {
          await discover(pool, collection, change.doc, config)
        })().catch((error) => telemetry('discovery_failed', { collection, document_id: change.doc.id, code: safeErrorCode(error, 'discovery_failed') })))
      }, (error) => telemetry('listener_failed', { collection, code: safeErrorCode(error, 'listener_failed') }))
      unsubscribers.push(unsubscribe)
    } catch (error) { setupFailures.push(collection); telemetry('listener_setup_failed', { collection, code: safeErrorCode(error, 'listener_setup_failed') }) }
  }
  return unsubscribers
}

export async function runService({ config = loadConfig(), mysqlModule = mysql, firebase = null } = {}) {
  const workerKey = crypto.randomUUID()
  const pool = mysqlModule.createPool({ ...config.database, waitForConnections: true, connectionLimit: config.workerConcurrency + 4, charset: 'utf8mb4', dateStrings: true, supportBigNumbers: true, bigNumberStrings: true })
  if (!firebase) {
    if (getApps().length === 0) initializeApp({ credential: cert(config.serviceAccountPath), projectId: config.firebaseProjectId })
    firebase = getFirestore()
    firebase.settings({ ignoreUndefinedProperties: false, useBigInt: true })
  }
  let stopping = false
  let firebaseReadCount = 0
  let lastRuntimeUpdateAt = 0
  const unsubscribers = []; const active = new Set(); const activeCollections = new Set(); const waits = new Map()
  const logEvent = (eventType, fields = {}) => recordTraverseEvent(pool, { eventType, ...fields }).catch((error) => telemetry('traverse_log_write_failed', { event: eventType, code: safeErrorCode(error, 'traverse_log_write_failed') }))
  const recordFirebaseReads = (count, collection, source) => {
    const reads = Number.isFinite(count) && count > 0 ? count : 0
    firebaseReadCount += reads
    if (reads > 0) {
      telemetry('firebase_reads_observed', { collection, source, reads, total: firebaseReadCount })
      logEvent('FIREBASE_READ', { eventStatus: 'INFO', collection, firebaseReads: firebaseReadCount })
    }
  }
  const delay = (milliseconds) => new Promise((resolve) => {
    const timer = setTimeout(() => { waits.delete(timer); resolve() }, milliseconds)
    waits.set(timer, resolve)
  })
  const track = (promise) => { active.add(promise); promise.finally(() => active.delete(promise)); return promise }
  const stop = () => {
    if (stopping) return
    stopping = true
    for (const unsubscribe of unsubscribers) unsubscribe()
    for (const [timer, resolve] of waits) { clearTimeout(timer); resolve() }
    waits.clear()
  }

  async function workerLoop() {
    while (!stopping) {
      try {
        if (Date.now() - lastRuntimeUpdateAt >= 5000) {
          lastRuntimeUpdateAt = Date.now()
          await updateTraverseRuntimeQueueCounts(pool)
        }
        const capacity = config.workerConcurrency - activeCollections.size
        if (capacity <= 0) { await delay(config.workPollMs); continue }
        const rows = await claimReady(pool, workerKey, config.leaseSeconds, capacity, [...activeCollections])
        for (const row of rows) {
          activeCollections.add(row.collection_name)
          const work = processClaimed({ pool, db: firebase, row, workerKey, config }).finally(() => activeCollections.delete(row.collection_name))
          track(work)
        }
        if (rows.length === 0) await delay(config.workPollMs)
      } catch (error) { const code = safeErrorCode(error, 'worker_poll_failed'); telemetry('worker_poll_failed', { code }); logEvent('WORKER_POLL_FAILED', { eventStatus: 'ERROR', errorCode: code, errorDetail: error instanceof Error ? error.message : null, serviceStatus: 'ERROR' }); if (!stopping) await delay(config.workPollMs) }
    }
  }

  await ensureControlTables(pool, COLLECTIONS)
  await recordTraverseEvent(pool, { eventType: 'SERVICE_START', eventStatus: 'SUCCESS', serviceStatus: 'RUNNING', startedAt: new Date() })
  const collections = await loadTraverseCollections(pool, COLLECTIONS)
  telemetry('traverse_collections_loaded', { collections })
  const listenerSetupFailures = []
  unsubscribers.push(...attachPendingListeners({ db: firebase, pool, config, collections, track, isStopping: () => stopping, onRead: recordFirebaseReads, setupFailures: listenerSetupFailures }))
  const startupRecovery = await Promise.all(listenerSetupFailures.map(async (collection) => {
    try { return [collection, await scanPendingPages({ db: firebase, pool, collection, config, shouldStop: () => stopping, onRead: recordFirebaseReads })] }
    catch (error) { telemetry('startup_scan_failed', { collection, code: safeErrorCode(error, 'startup_scan_failed') }); return [collection, 0] }
  }))
  telemetry('startup_backlog_scanned', { collections: Object.fromEntries(startupRecovery), mode: listenerSetupFailures.length > 0 ? 'listener_failure_recovery_only' : 'listener_initial_snapshot' })
  process.once('SIGINT', stop); process.once('SIGTERM', stop)
  telemetry('started', { collections, worker_key: workerKey, firebase_reads_observed: firebaseReadCount })
  await recordTraverseEvent(pool, { eventType: 'LISTENERS_ATTACHED', eventStatus: listenerSetupFailures.length === 0 ? 'SUCCESS' : 'WARNING', firebaseReads: firebaseReadCount })
  await workerLoop()
  await Promise.allSettled([...active])
  await updateTraverseRuntimeQueueCounts(pool).catch((error) => telemetry('traverse_runtime_update_failed', { code: safeErrorCode(error, 'traverse_runtime_update_failed') }))
  await recordTraverseEvent(pool, { eventType: 'SERVICE_STOP', eventStatus: 'INFO', serviceStatus: 'STOPPED', firebaseReads: firebaseReadCount })
  await pool.end()
  telemetry('stopped', { firebase_reads_observed: firebaseReadCount })
}

if (isMainModule()) runService().catch((error) => { telemetry('startup_failed', { code: safeErrorCode(error, 'startup_failed') }); process.exitCode = 1 })
