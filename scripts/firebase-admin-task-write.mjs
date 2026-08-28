import process from 'node:process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import dotenv from 'dotenv'
import { cert, getApps, initializeApp } from 'firebase-admin/app'
import { FieldValue, getFirestore } from 'firebase-admin/firestore'

const rootDir = path.dirname(path.dirname(fileURLToPath(import.meta.url)))
dotenv.config({ path: path.join(rootDir, '.env'), quiet: true })

const readStdin = () => new Promise((resolve, reject) => {
  let input = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', (chunk) => { input += chunk })
  process.stdin.on('end', () => resolve(input))
  process.stdin.on('error', reject)
})

const requireString = (value, label) => {
  const text = String(value ?? '').trim()
  if (!text) throw new Error(`${label}_required`)
  return text
}

const validId = (value) => /^[A-Za-z0-9]{20,40}$/.test(String(value || '').trim())
const clean = (value) => Object.fromEntries(Object.entries(value).filter(([, item]) => item !== undefined))
const booleanFields = new Set([
  'task_can_run_manually', 'task_can_run_via_api', 'task_can_run_if_bed_vacant',
  'task_can_run_if_bed_occupied', 'task_requires_bed_treatment', 'task_requires_admission_source',
  'stage_ends_task', 'stage_can_run_manually', 'stage_can_run_via_api',
])
const integerFields = new Set([
  'task_canvas_x', 'task_canvas_y', 'task_sort_order', 'stage_count', 'response_count',
  'stage_sort_order', 'response_sort_order',
])
const normalizeBoolean = (value) => ['1', 'true', 'yes', 'on'].includes(String(value ?? '').trim().toLowerCase())
const sqlTimestamp = () => {
  const date = new Date()
  const iso = date.toISOString()
  return iso.slice(0, 19).replace('T', ' ') + '.' + iso.slice(20, 23) + '000'
}

function initializeFirebase(projectId, serviceAccountPath) {
  if (getApps().length) return
  initializeApp(serviceAccountPath ? { credential: cert(serviceAccountPath), projectId } : { projectId })
}

function normalizeData(collection, documentKey, input, operation) {
  const now = sqlTimestamp()
  const existing = input && typeof input === 'object' ? input : {}
  const data = {}
  for (const [name, value] of Object.entries(existing)) {
    if (['action', 'collection', 'project_id', 'service_account_path', 'csrf', 'csrf_token', 'return_tab', 'selected_task_key', 'submit', 'stage_order_keys', 'response_order_keys'].includes(name)) continue
    if (name === 'created_at' || name === 'updated_at' || name === 'deleted_at') continue
    if (name.startsWith('mysql_') || name.startsWith('firebase_')) continue
    if (booleanFields.has(name)) data[name] = typeof value === 'boolean' ? value : normalizeBoolean(value)
    else if (integerFields.has(name)) data[name] = Number.isFinite(Number(value)) ? Number(value) : 0
    else data[name] = value
  }
  data[collection === 'project_task' ? 'task_key' : collection === 'project_task_stage' ? 'task_stage_key' : 'task_stage_response_key'] = documentKey
  data.firebase_collection = collection
  data.firebase_updated_at = FieldValue.serverTimestamp()
  data.mysql_updated_at = now
  data.mysql_sync_status = 'PENDING'
  data.mysql_synced_at = null
  data.mysql_deleted_at = operation === 'soft_delete' ? now : null
  data.firebase_deleted_at = operation === 'soft_delete' ? FieldValue.serverTimestamp() : null
  if (operation === 'soft_delete') {
    if (collection === 'project_task') data.task_status = 'DELETED'
    if (collection === 'project_task_stage') data.stage_status = 'DELETED'
    if (collection === 'project_task_stage_response') data.response_status = 'DELETED'
    data.deleted = true
  }
  if (operation === 'create') {
    data.firebase_created_at = FieldValue.serverTimestamp()
    data.mysql_created_at = now
  }
  if (collection === 'project_task') {
    data.task_canvas_x ??= 24
    data.task_canvas_y ??= 24
    data.task_sort_order ??= 0
    data.stage_count ??= 0
    data.response_count ??= 0
  }
  return clean(data)
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const collection = requireString(payload.collection, 'collection')
  if (!['project_task', 'project_task_stage', 'project_task_stage_response'].includes(collection)) throw new Error('task_collection_not_allowed')
  const operation = String(payload.operation || 'update').trim()
  if (!['create', 'update', 'soft_delete', 'ensure_default_stage'].includes(operation)) throw new Error('task_operation_not_allowed')
  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  if (operation === 'ensure_default_stage') {
    if (collection !== 'project_task_stage') throw new Error('default_stage_collection_invalid')
    const taskKey = requireString(payload.record?.task_key, 'task_key')
    let result = null
    await db.runTransaction(async (transaction) => {
      const stageQuery = db.collection(collection).where('task_key', '==', taskKey)
      const stageSnapshot = await transaction.get(stageQuery)
      const existing = stageSnapshot.docs.find((doc) => String(doc.get('stage_label') || '').trim().toUpperCase() === 'NEW')
      if (existing) {
        result = { document_key: existing.id, created: false, restored: String(existing.get('stage_status') || '').toUpperCase() === 'DELETED' }
        if (result.restored) {
          transaction.set(existing.ref, {
            stage_label: 'NEW',
            stage_status: 'ACTIVE',
            stage_description: String(existing.get('stage_description') || 'Default starting stage.'),
            stage_color_hex: String(existing.get('stage_color_hex') || '#00000000'),
            stage_ends_task: Boolean(existing.get('stage_ends_task') || false),
            stage_can_run_manually: Boolean(existing.get('stage_can_run_manually') || false),
            stage_can_run_via_api: Boolean(existing.get('stage_can_run_via_api') || false),
            connected_task_key: existing.get('connected_task_key') ?? null,
            connected_task_trigger_point: String(existing.get('connected_task_trigger_point') || 'CURRENT_STAGE_FINISHED'),
            stage_sort_order: Number(existing.get('stage_sort_order') || 1),
            firebase_collection: collection,
            firebase_updated_at: FieldValue.serverTimestamp(),
            firebase_deleted_at: null,
            mysql_updated_at: sqlTimestamp(),
            mysql_deleted_at: null,
            mysql_synced_at: null,
            mysql_sync_status: 'PENDING',
          }, { merge: true })
        }
        return
      }
      const ref = db.collection(collection).doc()
      result = { document_key: ref.id, created: true, restored: false }
      transaction.set(ref, normalizeData(collection, ref.id, {
        task_key: taskKey,
        stage_label: 'NEW',
        stage_description: 'Default starting stage.',
        stage_color_hex: '#00000000',
        stage_status: 'ACTIVE',
        stage_ends_task: false,
        stage_can_run_manually: false,
        stage_can_run_via_api: false,
        connected_task_key: null,
        connected_task_trigger_point: 'CURRENT_STAGE_FINISHED',
        stage_sort_order: 1,
      }, 'create'), { merge: true })
    })
    const snapshot = await db.collection(collection).doc(result.document_key).get()
    if (!snapshot.exists || snapshot.id !== result.document_key) throw new Error('firebase_readback_failed')
    process.stdout.write(JSON.stringify({ ok: true, collection, operation, ...result }))
    return
  }
  const documentKey = operation === 'create' ? db.collection(collection).doc().id : requireString(payload.document_key, 'document_key')
  if (!validId(documentKey)) throw new Error('firebase_document_id_invalid')
  const ref = db.collection(collection).doc(documentKey)
  await ref.set(normalizeData(collection, documentKey, payload.record, operation), { merge: true })
  const snapshot = await ref.get()
  if (!snapshot.exists || String(snapshot.id) !== documentKey) throw new Error('firebase_readback_failed')
  process.stdout.write(JSON.stringify({ ok: true, collection, document_key: documentKey, operation }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_task_write_failed' }))
  process.exitCode = 1
})
