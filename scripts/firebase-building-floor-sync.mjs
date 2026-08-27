import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

function readStdin() {
  return new Promise((resolve, reject) => {
    let input = ''
    process.stdin.setEncoding('utf8')
    process.stdin.on('data', (chunk) => { input += chunk })
    process.stdin.on('end', () => resolve(input))
    process.stdin.on('error', reject)
  })
}

function required(value, label) {
  const text = String(value || '').trim()
  if (text === '') throw new Error(`${label}_required`)
  return text
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length > 0) return
  if (serviceAccountPath !== '') {
    initializeApp({ credential: cert(serviceAccountPath), projectId })
    return
  }
  initializeApp({ projectId })
}

function normalize(row) {
  const buildingFloorKey = required(row?.building_floor_key, 'building_floor_key')
  if (!/^[A-Za-z0-9]{20,40}$/.test(buildingFloorKey)) throw new Error('building_floor_key_invalid_firebase_document_id')
  const floorSortOrder = Number(row?.floor_sort_order || 0)
  return {
    key: buildingFloorKey,
    data: {
      building_floor_key: buildingFloorKey,
      branch_key: String(row?.branch_key || ''),
      branch_name: String(row?.branch_name || ''),
      building_key: String(row?.building_key || ''),
      building_name: String(row?.building_name || ''),
      floor_key: String(row?.floor_key || ''),
      floor_name: String(row?.floor_name || ''),
      building_sort_order: Number(row?.building_sort_order || 0),
      floor_sort_order: floorSortOrder,
      sort_order: floorSortOrder,
      floor_status: String(row?.floor_status || 'ACTIVE').toUpperCase(),
      firebase_collection: 'project_building_floor',
      mysql_updated_at: String(row?.updated_at || ''),
      server_synced_at: FieldValue.serverTimestamp(),
    },
  }
}

async function deleteStaleDocuments(db, keepKeys) {
  const snapshot = await db.collection('project_building_floor').get()
  let batch = db.batch()
  let pending = 0
  for (const document of snapshot.docs) {
    if (keepKeys.has(document.id)) continue
    batch.delete(document.ref)
    pending++
    if (pending >= 450) {
      await batch.commit()
      batch = db.batch()
      pending = 0
    }
  }
  if (pending > 0) await batch.commit()
}

async function commitRows(db, rows) {
  const keepKeys = new Set()
  let synced = 0
  for (let index = 0; index < rows.length; index += 450) {
    const batch = db.batch()
    for (const row of rows.slice(index, index + 450)) {
      const normalized = normalize(row || {})
      keepKeys.add(normalized.key)
      batch.set(db.collection('project_building_floor').doc(normalized.key), normalized.data, { merge: true })
      synced++
    }
    if (synced > 0) await batch.commit()
  }
  await deleteStaleDocuments(db, keepKeys)
  const readBack = await db.collection('project_building_floor').get()
  if (readBack.size !== keepKeys.size) throw new Error('firebase_building_floor_read_back_mismatch')
  return { synced, readBack: readBack.size }
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const rows = Array.isArray(payload.rows) ? payload.rows : []
  initializeFirebase(projectId, serviceAccountPath)
  const result = await commitRows(getFirestore(), rows)
  process.stdout.write(JSON.stringify({ ok: true, project_id: projectId, collection: 'project_building_floor', synced: result.synced, read_back: result.readBack }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_building_floor_sync_failed' }))
  process.exitCode = 1
})
