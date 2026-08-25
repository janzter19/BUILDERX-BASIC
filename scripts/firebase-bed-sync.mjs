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

function normalizeBed(row) {
  const bedKey = requireString(row.bed_key, 'bed_key')
  if (!/^[A-Za-z0-9]{20}$/.test(bedKey)) {
    throw new Error('bed_key_invalid_firebase_document_id')
  }

  return {
    bedKey,
    data: cleanObject({
      bed_key: bedKey,
      bed_source_key: String(row.bed_source_key || ''),
      source_table: String(row.source_table || 'RBMS_BedMasterlist'),
      source_id: Number(row.source_id || 0),
      source_pk_psbeds: String(row.source_pk_psbeds || ''),
      bed_no: String(row.bed_no || ''),
      branch_key: String(row.branch_key || ''),
      branch_name: String(row.branch_name || ''),
      building_key: String(row.building_key || ''),
      building_name: String(row.building_name || ''),
      floor_key: String(row.floor_key || ''),
      floor_name: String(row.floor_name || ''),
      nurse_station_key: String(row.nurse_station_key || ''),
      nurse_station_name: String(row.nurse_station_name || ''),
      room_key: String(row.room_key || ''),
      room_class_key: String(row.room_class_key || ''),
      room_class: String(row.room_class || ''),
      source_bed_status_key: String(row.source_bed_status_key || ''),
      source_bed_status: String(row.source_bed_status || ''),
      managed_status: String(row.managed_status || 'ACTIVE').toUpperCase(),
      sync_batch_key: String(row.sync_batch_key || ''),
      firebase_collection: 'project_bed',
      mysql_first_synced_at: String(row.first_synced_at || ''),
      mysql_last_synced_at: String(row.last_synced_at || ''),
      mysql_last_seen_at: String(row.last_seen_at || ''),
      mysql_created_at: String(row.created_at || ''),
      mysql_updated_at: String(row.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

function normalizeAnalytics(row) {
  const analyticsKey = requireString(row.analytics_key, 'analytics_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(analyticsKey)) {
    throw new Error('analytics_key_invalid_firebase_document_id')
  }
  const rows = Array.isArray(row.rows)
    ? row.rows.map((item) => cleanObject({
      item_label: String(item?.item_label || ''),
      total_rows: Number(item?.total_rows || 0),
      active_rows: Number(item?.active_rows || 0),
      inactive_rows: Number(item?.inactive_rows || 0),
      available_rows: Number(item?.available_rows || 0),
      vacant_rows: Number(item?.vacant_rows || 0),
      occupied_rows: Number(item?.occupied_rows || 0),
    }))
    : []

  return {
    analyticsKey,
    data: cleanObject({
      analytics_key: analyticsKey,
      analytics_scope: String(row.analytics_scope || 'GROUP').toUpperCase(),
      group_key: String(row.group_key || ''),
      group_label: String(row.group_label || ''),
      row_count: Number(row.row_count || rows.length),
      rows,
      analytics_status: String(row.analytics_status || 'ACTIVE').toUpperCase(),
      sync_batch_key: String(row.sync_batch_key || ''),
      firebase_collection: 'project_bed_analytics',
      mysql_last_computed_at: String(row.last_computed_at || ''),
      mysql_created_at: String(row.created_at || ''),
      mysql_updated_at: String(row.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

function normalizeFloor(row) {
  const floorGroupKey = requireString(row.floor_group_key, 'floor_group_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(floorGroupKey)) {
    throw new Error('floor_group_key_invalid_firebase_document_id')
  }
  const beds = Array.isArray(row.beds)
    ? row.beds.map((item) => cleanObject({
      bed_key: String(item?.bed_key || ''),
      bed_no: String(item?.bed_no || ''),
      nurse_station_key: String(item?.nurse_station_key || ''),
      nurse_station_name: String(item?.nurse_station_name || ''),
      room_key: String(item?.room_key || ''),
      room_class_key: String(item?.room_class_key || ''),
      room_class: String(item?.room_class || ''),
      source_bed_status_key: String(item?.source_bed_status_key || ''),
      source_bed_status: String(item?.source_bed_status || ''),
      managed_status: String(item?.managed_status || 'ACTIVE').toUpperCase(),
    }))
    : []
  const classRows = Array.isArray(row.class_rows)
    ? row.class_rows.map((item) => cleanObject({
      label: String(item?.label || ''),
      total: Number(item?.total || 0),
    }))
    : []
  const statusRows = Array.isArray(row.status_rows)
    ? row.status_rows.map((item) => cleanObject({
      label: String(item?.label || ''),
      total: Number(item?.total || 0),
    }))
    : []
  const summary = row.summary && typeof row.summary === 'object' ? row.summary : {}

  return {
    floorGroupKey,
    data: cleanObject({
      floor_group_key: floorGroupKey,
      branch: {
        key: String(row.branch_key || ''),
        name: String(row.branch_name || ''),
      },
      building: {
        key: String(row.building_key || ''),
        name: String(row.building_name || ''),
      },
      floor: {
        key: String(row.floor_key || ''),
        name: String(row.floor_name || ''),
      },
      summary: {
        total: Number(summary.total || 0),
        active: Number(summary.active || 0),
        inactive: Number(summary.inactive || 0),
        available: Number(summary.available || 0),
        vacant: Number(summary.vacant || 0),
        occupied: Number(summary.occupied || 0),
      },
      class_rows: classRows,
      status_rows: statusRows,
      beds,
      bed_count: Number(row.bed_count || beds.length),
      floor_group_status: String(row.floor_group_status || 'ACTIVE').toUpperCase(),
      sync_batch_key: String(row.sync_batch_key || ''),
      firebase_collection: 'project_bed_floor',
      mysql_last_synced_at: String(row.last_synced_at || ''),
      mysql_updated_at: String(row.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}

async function commitBeds(db, rows) {
  let synced = 0
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeBed(row || {})
      batch.set(db.collection('project_bed').doc(normalized.bedKey), normalized.data, { merge: true })
      synced++
    }
    await batch.commit()
  }
  return synced
}

async function commitAnalytics(db, rows) {
  let synced = 0
  const keepKeys = new Set()
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeAnalytics(row || {})
      batch.set(db.collection('project_bed_analytics').doc(normalized.analyticsKey), normalized.data, { merge: true })
      keepKeys.add(normalized.analyticsKey)
      synced++
    }
    await batch.commit()
  }
  await deleteStaleAnalytics(db, keepKeys)
  return synced
}

async function deleteStaleAnalytics(db, keepKeys) {
  const snapshot = await db.collection('project_bed_analytics').get()
  let batch = db.batch()
  let pending = 0
  for (const doc of snapshot.docs) {
    if (keepKeys.has(doc.id)) continue
    batch.delete(doc.ref)
    pending++
    if (pending >= 450) {
      await batch.commit()
      batch = db.batch()
      pending = 0
    }
  }
  if (pending > 0) {
    await batch.commit()
  }
}

async function commitFloors(db, rows, replaceAll) {
  let synced = 0
  const keepKeys = new Set()
  for (let index = 0; index < rows.length; index += 450) {
    const batchRows = rows.slice(index, index + 450)
    const batch = db.batch()
    for (const row of batchRows) {
      const normalized = normalizeFloor(row || {})
      batch.set(db.collection('project_bed_floor').doc(normalized.floorGroupKey), normalized.data, { merge: true })
      keepKeys.add(normalized.floorGroupKey)
      synced++
    }
    await batch.commit()
  }
  if (replaceAll) {
    await deleteStaleFloorGroups(db, keepKeys)
  }
  return synced
}

async function deleteStaleFloorGroups(db, keepKeys) {
  const snapshot = await db.collection('project_bed_floor').get()
  let batch = db.batch()
  let pending = 0
  for (const doc of snapshot.docs) {
    if (keepKeys.has(doc.id)) continue
    batch.delete(doc.ref)
    pending++
    if (pending >= 450) {
      await batch.commit()
      batch = db.batch()
      pending = 0
    }
  }
  if (pending > 0) {
    await batch.commit()
  }
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const rows = Array.isArray(payload.rows) ? payload.rows : []
  const analyticsRows = Array.isArray(payload.analytics_rows) ? payload.analytics_rows : []
  const floorRows = Array.isArray(payload.floor_rows) ? payload.floor_rows : []
  const floorReplaceAll = payload.floor_replace_all === true
  if (rows.length < 1) {
    throw new Error('project_bed_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const synced = await commitBeds(db, rows)
  const analyticsSynced = analyticsRows.length > 0 ? await commitAnalytics(db, analyticsRows) : 0
  const floorSynced = floorRows.length > 0 ? await commitFloors(db, floorRows, floorReplaceAll) : 0
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_bed',
    synced,
    analytics_collection: 'project_bed_analytics',
    analytics_synced: analyticsSynced,
    floor_collection: 'project_bed_floor',
    floor_synced: floorSynced,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_bed_sync_failed',
  }))
  process.exitCode = 1
})
