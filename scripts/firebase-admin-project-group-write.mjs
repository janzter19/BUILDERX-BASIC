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
const required = (value, name) => {
  const text = String(value ?? '').trim()
  if (text === '') throw new Error(`${name}_required`)
  return text
}
const projectKey = (value) => required(value, 'project_key')
const documentKey = (value, name) => {
  const key = String(value || '').trim()
  if (key !== '' && !/^[A-Za-z0-9]{20,40}$/.test(key)) throw new Error(`${name}_invalid_firebase_document_id`)
  return key
}
const status = (value, name) => {
  const result = String(value || 'ACTIVE').trim().toUpperCase()
  if (!['ACTIVE', 'INACTIVE', 'DELETED'].includes(result)) throw new Error(`${name}_invalid`)
  return result
}

function initializeFirebase(firebaseProjectId, serviceAccountPath) {
  if (getApps().length > 0) return
  initializeApp(serviceAccountPath === ''
    ? { projectId: firebaseProjectId }
    : { credential: cert(serviceAccountPath), projectId: firebaseProjectId })
}

async function main() {
  const payload = JSON.parse(await readStdin())
  const firebaseProjectId = required(payload.project_id || process.env.FIREBASE_PROJECT_ID, 'firebase_project_id')
  const serviceAccountPath = String(payload.service_account_path || process.env.GOOGLE_APPLICATION_CREDENTIALS || process.env.FIREBASE_SERVICE_ACCOUNT_PATH || '').trim()
  const record = payload.record && typeof payload.record === 'object' ? payload.record : {}
  const project = projectKey(record.project_key)
  const groupKeyInput = documentKey(record.group_key, 'group_key')
  const groupName = required(record.group_name, 'group_name')
  const groupStatus = status(record.group_status, 'group_status')
  const imagePath = String(record.group_image_path || '').trim()
  const imageOriginalName = String(record.group_image_original_name || '').trim().slice(0, 255)
  const imageMimeType = String(record.group_image_mime_type || '').trim().slice(0, 120)
  const imageByteSize = Math.max(0, Number(record.group_image_byte_size || 0))
  const imageSha256 = String(record.group_image_sha256 || '').trim().toLowerCase()
  if (imagePath !== '' && imagePath.length > 500) throw new Error('group_image_path_too_long')
  if (imageMimeType !== '' && !['image/png', 'image/jpeg', 'image/webp', 'image/gif'].includes(imageMimeType)) throw new Error('group_image_mime_type_invalid')
  if (imageSha256 !== '' && !/^[a-f0-9]{64}$/.test(imageSha256)) throw new Error('group_image_sha256_invalid')
  if (!Number.isSafeInteger(imageByteSize) || imageByteSize > 4_294_967_295) throw new Error('group_image_byte_size_invalid')
  const assignments = Array.isArray(payload.assignments) ? payload.assignments : []
  const syncAssignments = payload.sync_assignments !== false

  initializeFirebase(firebaseProjectId, serviceAccountPath)
  const db = getFirestore()
  const groupRef = groupKeyInput === '' ? db.collection('project_group').doc() : db.collection('project_group').doc(groupKeyInput)
  const previous = await groupRef.get()
  if (previous.exists && String(previous.get('project_key') || '') !== project) throw new Error('group_project_boundary_failed')
  const now = FieldValue.serverTimestamp()
  const groupData = {
    group_key: groupRef.id,
    project_key: project,
    group_name: groupName,
    group_description: String(record.group_description || '').trim(),
    group_status: groupStatus,
    group_image_path: imagePath,
    group_image_original_name: imageOriginalName,
    group_image_mime_type: imageMimeType,
    group_image_byte_size: imageByteSize,
    group_image_sha256: imageSha256,
    group_image_uploaded_at: imagePath !== '' ? now : null,
    firebase_collection: 'project_group',
    mysql_created_at: previous.exists ? (previous.get('mysql_created_at') || previous.get('created_at') || now) : now,
    mysql_updated_at: now,
    mysql_deleted_at: groupStatus === 'DELETED' ? now : null,
    mysql_synced_at: null,
    mysql_sync_status: 'PENDING',
    created_at: previous.exists ? (previous.get('created_at') || now) : now,
    updated_at: now,
  }
  await groupRef.set(groupData)

  if (!syncAssignments) {
    const groupReadBack = await groupRef.get()
    const groupActual = groupReadBack.data() || {}
    if (!groupReadBack.exists || groupActual.group_key !== groupRef.id || groupActual.project_key !== project || groupActual.group_status !== groupStatus || groupActual.mysql_sync_status !== 'PENDING') {
      throw new Error('project_group_firebase_readback_failed')
    }
    process.stdout.write(JSON.stringify({ ok: true, group_key: groupRef.id, assignment_count: 0, firebase_collection: 'project_group', mysql_sync_status: 'PENDING', action: previous.exists ? 'updated' : 'created' }))
    return
  }

  const assignmentCollection = db.collection('project_user_group')
  const existingSnapshot = await assignmentCollection.where('project_key', '==', project).where('group_key', '==', groupRef.id).get()
  const existingByUser = new Map(existingSnapshot.docs.map((doc) => [String(doc.get('user_key') || ''), doc]))
  const selectedUsers = new Set()
  for (const item of assignments) {
    const userKey = required(item.user_key, 'user_key')
    const positionKey = documentKey(item.position_key, 'position_key')
    if (positionKey === '') throw new Error('position_key_required_for_active_assignment')
    if (selectedUsers.has(userKey)) throw new Error('duplicate_active_assignment')
    const [userSnapshot, positionSnapshot] = await Promise.all([
      db.collection('project_user').doc(userKey).get(),
      db.collection('project_position').doc(positionKey).get(),
    ])
    if (!userSnapshot.exists || String(userSnapshot.get('project_key') || '') !== project || String(userSnapshot.get('user_status') || '').toUpperCase() === 'DELETED') throw new Error('assignment_user_project_boundary_failed')
    if (!positionSnapshot.exists || String(positionSnapshot.get('project_key') || '') !== project || String(positionSnapshot.get('group_key') || '') !== groupRef.id || String(positionSnapshot.get('position_status') || '').toUpperCase() === 'DELETED') throw new Error('assignment_position_group_boundary_failed')
    selectedUsers.add(userKey)
    const existing = existingByUser.get(userKey)
    const ref = existing ? existing.ref : assignmentCollection.doc()
    const prior = existing ? existing.data() : {}
    const assignmentData = {
      assignment_key: ref.id,
      project_key: project,
      group_key: groupRef.id,
      user_key: userKey,
      position_key: positionKey,
      assignment_status: 'ACTIVE',
      firebase_collection: 'project_user_group',
      mysql_created_at: prior.mysql_created_at || prior.created_at || now,
      mysql_updated_at: now,
      mysql_deleted_at: null,
      mysql_synced_at: null,
      mysql_sync_status: 'PENDING',
      created_at: prior.created_at || now,
      updated_at: now,
    }
    await ref.set(assignmentData)
  }
  for (const doc of existingSnapshot.docs) {
    const userKey = String(doc.get('user_key') || '')
    if (selectedUsers.has(userKey)) continue
    const prior = doc.data()
    await doc.ref.set({
      ...prior,
      assignment_key: doc.id,
      assignment_status: 'DELETED',
      mysql_updated_at: now,
      mysql_deleted_at: now,
      mysql_synced_at: null,
      mysql_sync_status: 'PENDING',
      updated_at: now,
    })
  }
  const groupReadBack = await groupRef.get()
  const groupActual = groupReadBack.data() || {}
  if (!groupReadBack.exists || groupActual.group_key !== groupRef.id || groupActual.project_key !== project || groupActual.group_status !== groupStatus || groupActual.mysql_sync_status !== 'PENDING') {
    throw new Error('project_group_firebase_readback_failed')
  }
  process.stdout.write(JSON.stringify({ ok: true, group_key: groupRef.id, assignment_count: assignments.length, firebase_collection: 'project_group', mysql_sync_status: 'PENDING', action: previous.exists ? 'updated' : 'created' }))
}

main().catch((error) => {
  process.stdout.write(JSON.stringify({ ok: false, message: error instanceof Error ? error.message : 'firebase_project_group_write_failed' }))
})
