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

function firebaseValuesEqual(left, right) {
  if (Array.isArray(left) || Array.isArray(right)) {
    return JSON.stringify(left ?? null) === JSON.stringify(right ?? null)
  }
  return left === right || (left == null && right == null)
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

function normalizeDocumentId(value, label) {
  const key = requireString(value, label)
  if (!/^[A-Za-z0-9]{20,40}$/.test(key)) {
    throw new Error(`${label}_invalid_firebase_document_id`)
  }
  return key
}

function normalizeDocumentKey(value) {
  return normalizeDocumentId(value, 'task_key')
}

function normalizeStatus(value, fallback = 'ACTIVE') {
  const status = String(value || fallback).trim().toUpperCase()
  return status === '' ? fallback : status
}

function normalizeBoolean(value) {
  const text = String(value || '').trim().toLowerCase()
  return value === true || text === '1' || text === 'true' || text === 'yes' || text === 'on'
}

function normalizeNumber(value) {
  const number = Number(value || 0)
  return Number.isFinite(number) ? number : 0
}

function parseStringList(value) {
  if (Array.isArray(value)) {
    return value.map((item) => String(item || '').trim()).filter(Boolean)
  }
  const text = String(value || '').trim()
  if (text === '') return []
  try {
    const parsed = JSON.parse(text)
    return Array.isArray(parsed)
      ? parsed.map((item) => String(item || '').trim()).filter(Boolean)
      : []
  } catch {
    return text.split(',').map((item) => item.trim()).filter(Boolean)
  }
}

function normalizeStage(row) {
  const taskStageKey = normalizeDocumentId(row.task_stage_key, 'task_stage_key')
  return cleanObject({
    task_stage_key: taskStageKey,
    task_key: normalizeDocumentKey(row.task_key),
    stage_label: String(row.stage_label || '').trim(),
    stage_description: String(row.stage_description || '').trim(),
    stage_color_hex: String(row.stage_color_hex || '#00000000').trim(),
    stage_status: normalizeStatus(row.stage_status, 'INACTIVE'),
    stage_ends_task: normalizeBoolean(row.stage_ends_task),
    stage_can_run_manually: normalizeBoolean(row.stage_can_run_manually),
    stage_can_run_via_api: normalizeBoolean(row.stage_can_run_via_api),
    connected_task_key: String(row.connected_task_key || '').trim(),
    connected_task_trigger_point: String(row.connected_task_trigger_point || 'CURRENT_STAGE_FINISHED').trim(),
    stage_sort_order: normalizeNumber(row.stage_sort_order),
    mysql_created_at: String(row.created_at || '').trim(),
    mysql_updated_at: String(row.updated_at || '').trim(),
  })
}

function normalizeResponse(row) {
  const taskStageResponseKey = normalizeDocumentId(row.task_stage_response_key, 'task_stage_response_key')
  return cleanObject({
    task_stage_response_key: taskStageResponseKey,
    task_key: normalizeDocumentKey(row.task_key),
    task_stage_key: normalizeDocumentId(row.task_stage_key, 'task_stage_key'),
    response_label: String(row.response_label || '').trim(),
    response_description: String(row.response_description || '').trim(),
    response_color_hex: String(row.response_color_hex || '#00000000').trim(),
    response_status: normalizeStatus(row.response_status, 'ACTIVE'),
    response_sort_order: normalizeNumber(row.response_sort_order),
    mysql_created_at: String(row.created_at || '').trim(),
    mysql_updated_at: String(row.updated_at || '').trim(),
  })
}

function normalizeRow(row) {
  const documentKey = normalizeDocumentKey(row.task_key)
  const deleted = normalizeBoolean(row.deleted) || normalizeStatus(row.task_status, '') === 'DELETED'
  if (deleted) {
    return {
      documentKey,
      data: cleanObject({
        task_key: documentKey,
        task_code: String(row.task_code || '').trim(),
        task_title: String(row.task_title || '').trim(),
        task_type: String(row.task_type || '').trim(),
        task_status: 'DELETED',
        deleted: true,
        firebase_collection: 'project_task',
        server_synced_at: FieldValue.serverTimestamp(),
      }),
    }
  }

  const stages = Array.isArray(row.stages) ? row.stages.map(normalizeStage) : []
  const responses = Array.isArray(row.responses) ? row.responses.map(normalizeResponse) : []

  return {
    documentKey,
    data: cleanObject({
      task_key: documentKey,
      task_code: String(row.task_code || '').trim(),
      task_title: String(row.task_title || '').trim(),
      task_description: String(row.task_description || '').trim(),
      task_group_keys: parseStringList(row.task_group_keys),
      task_bypass_group_keys: parseStringList(row.task_bypass_group_keys),
      task_type: normalizeStatus(row.task_type, 'PRIMARY'),
      task_status: normalizeStatus(row.task_status, 'INACTIVE'),
      task_priority: normalizeStatus(row.task_priority, 'NORMAL'),
      task_color_hex: String(row.task_color_hex || '#00000000').trim(),
      task_can_run_manually: normalizeBoolean(row.task_can_run_manually),
      task_can_run_via_api: normalizeBoolean(row.task_can_run_via_api),
      task_can_run_if_bed_vacant: normalizeBoolean(row.task_can_run_if_bed_vacant),
      task_can_run_if_bed_occupied: normalizeBoolean(row.task_can_run_if_bed_occupied),
      task_requires_bed_treatment: normalizeBoolean(row.task_requires_bed_treatment),
      task_requires_admission_source: normalizeBoolean(row.task_requires_admission_source),
      task_canvas_x: normalizeNumber(row.task_canvas_x),
      task_canvas_y: normalizeNumber(row.task_canvas_y),
      task_sort_order: normalizeNumber(row.task_sort_order),
      stage_count: stages.length,
      response_count: responses.length,
      stages,
      responses,
      deleted: false,
      mysql_created_at: String(row.created_at || '').trim(),
      mysql_updated_at: String(row.updated_at || '').trim(),
      firebase_collection: 'project_task',
      server_synced_at: FieldValue.serverTimestamp(),
    }),
  }
}


async function commitRows(db, rows) {
  const counts = {
    tasks: 0,
    stages: 0,
    responses: 0,
    documents: 0,
  }
  const writes = []
  for (const row of rows) {
    const normalized = normalizeRow(row || {})
    writes.push({
      collection: 'project_task',
      documentKey: normalized.documentKey,
      data: normalized.data,
    })
    counts.tasks++

    if (normalized.data.deleted !== true) {
      for (const stage of normalized.data.stages || []) {
        writes.push({
          collection: 'project_task_stage',
          documentKey: stage.task_stage_key,
          data: cleanObject({
            ...stage,
            deleted: false,
            firebase_collection: 'project_task_stage',
            server_synced_at: FieldValue.serverTimestamp(),
          }),
        })
        counts.stages++
      }
      for (const response of normalized.data.responses || []) {
        writes.push({
          collection: 'project_task_stage_response',
          documentKey: response.task_stage_response_key,
          data: cleanObject({
            ...response,
            deleted: false,
            firebase_collection: 'project_task_stage_response',
            server_synced_at: FieldValue.serverTimestamp(),
          }),
        })
        counts.responses++
      }
    }

  }

  for (let index = 0; index < writes.length; index += 450) {
    const batchRows = writes.slice(index, index + 450)
    const batch = db.batch()
    for (const write of batchRows) {
      batch.set(db.collection(write.collection).doc(write.documentKey), write.data, { merge: true })
      counts.documents++
    }
    await batch.commit()
  }
  return counts
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const projectId = requireString(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const rows = Array.isArray(payload.rows)
    ? payload.rows
    : (payload.row && typeof payload.row === 'object' ? [payload.row] : [])
  if (rows.length < 1) {
    throw new Error('project_task_rows_required')
  }

  initializeFirebase(projectId, serviceAccountPath)
  const db = getFirestore()
  const synced = await commitRows(db, rows)
  process.stdout.write(JSON.stringify({
    ok: true,
    project_id: projectId,
    collection: 'project_task',
    collections: ['project_task', 'project_task_stage', 'project_task_stage_response'],
    synced: synced.tasks,
    synced_stages: synced.stages,
    synced_responses: synced.responses,
    synced_documents: synced.documents,
  }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({
    ok: false,
    message: error instanceof Error ? error.message : 'firebase_project_task_sync_failed',
  }))
  process.exitCode = 1
})
