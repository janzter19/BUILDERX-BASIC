<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    'index' => file_get_contents($root . '/index.php'),
    'foundation' => file_get_contents($root . '/app/foundation.php'),
    'frontend' => file_get_contents($root . '/frontend/src/App.tsx'),
    'rules' => file_get_contents($root . '/firestore.rules'),
    'indexes' => file_get_contents($root . '/firestore.indexes.json'),
    'supervisor' => file_get_contents($root . '/scripts/firebase-sync.mjs'),
    'projectTaskSync' => file_get_contents($root . '/scripts/firebase-project-task-sync.mjs'),
];
foreach ($sources as $name => $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Bed Lookup Firebase-only source could not be read: ' . $name);
    }
}

foreach ([
    "'projectBedTasks' => [],",
    "'projectBedTaskLogs' => [],",
    'bx_project_bed_lookup_rows',
] as $marker) {
    if (!str_contains($sources['index'], $marker)) {
        throw new RuntimeException('Missing PHP Bed Lookup marker: ' . $marker);
    }
}

foreach ([
    'createPortalBedTaskInFirebase',
    "const bedTaskDocument = doc(collection(firebase.db, 'project_bed_task'))",
    "const bedTaskLogDocument = doc(collection(firebase.db, 'project_bed_task_log'))",
    'const bedTaskKey = bedTaskDocument.id',
    'const bedTaskLogKey = bedTaskLogDocument.id',
    'batch.set(bedTaskDocument, taskData)',
    'batch.set(bedTaskLogDocument, logData)',
    "mysql_sync_status: 'PENDING'",
    "where('project_key', '==', projectKey)",
    "where('tenant_key', '==', firebase.tenantKey)",
    "where('bed_key', '==', bedKey)",
    "where('task_key', '==', taskKey)",
    "where('task_group_keys', 'array-contains-any', groupKeys)",
    'const authorizedTaskGroupChunk = authorizedTaskGroups.slice(index, index + 30)',
    "where('task_group_keys', 'array-contains-any', authorizedTaskGroupChunk)",
    'portalBedTaskIsUnfinished',
    "where('project_key', '==', projectKey)",
    "where('task_group_keys', 'array-contains-any', groupKeyChunk)",
    'const querySpecs = projectKeys.flatMap',
    'const groupKeyChunks =',
    'const taskDocsByQuery =',
    'cleanupUnsubscribe.forEach',
] as $marker) {
    if (!str_contains($sources['frontend'], $marker)) {
        throw new RuntimeException('Missing direct Firebase Bed Lookup marker: ' . $marker);
    }
}

if (str_contains($sources['frontend'], 'name="action" value="create_project_bed_task"')) {
    throw new RuntimeException('Bed Lookup task form still posts create_project_bed_task to PHP.');
}
if (str_contains($sources['frontend'], 'send_project_bed_task_notification') || str_contains($sources['index'], 'send_project_bed_task_notification')) {
    throw new RuntimeException('Obsolete Bed Lookup task notification action remains in the UI or PHP entrypoint.');
}
if (preg_match('/onSnapshot\(\s*collection\(firebase\.db, [\'\"]project_bed_task[\'\"]\)/s', $sources['frontend']) === 1) {
    throw new RuntimeException('Bed Lookup still attaches an unfiltered project_bed_task listener.');
}
if (substr_count($sources['frontend'], "where('tenant_key', '==', firebase.tenantKey)") < 3) {
    throw new RuntimeException('Bed Lookup task reads/listeners do not all enforce tenant scoping.');
}
foreach ([
    'portalBedTaskDocumentKey',
    'portalBedTaskLogDocumentKey',
    'crypto.subtle.digest(\'SHA-256\', new TextEncoder().encode(`${projectKey}:${bedKey}:${taskKey}`))',
    "crypto.randomUUID().replaceAll('-', '')",
    "getDoc(doc(firebase.db, 'project_bed_task', bedTaskKey))",
    "batch.set(doc(firebase.db, 'project_bed_task', bedTaskKey), taskData)",
    "batch.set(doc(firebase.db, 'project_bed_task_log', bedTaskLogKey), logData)",
] as $marker) {
    if (str_contains($sources['frontend'], $marker)) {
        throw new RuntimeException('Bed Lookup still derives a Firebase task or log key, or writes with a reconstructed document ID: ' . $marker);
    }
}
if (preg_match('/(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+|FROM\s+)?project_bed_task(?:_log)?/i', $sources['foundation']) === 1) {
    throw new RuntimeException('Foundation still contains a MySQL task mutation.');
}
if (str_contains($sources['supervisor'], 'bed-task-sync-down') || str_contains($sources['projectTaskSync'], 'project_bed_task')) {
    throw new RuntimeException('Firebase supervisor/project-task worker still wires Bed Lookup task synchronization.');
}

foreach ([
    'canCreateBedTask',
    'canCreateBedTaskCreatedLog',
    'taskData.tenant_key == request.auth.token.tenant_key',
    'hasProject(taskData.project_key)',
    'taskData.task_group_keys.hasAny(request.auth.token.groups)',
    'request.resource.data.task_group_keys is list',
    'request.resource.data.bed_task_key == taskKey',
    'request.resource.data.bed_task_log_key == logKey',
    "request.resource.data.mysql_sync_status == 'PENDING'",
    'allow create: if canCreateBedTask(bedTaskKey);',
] as $marker) {
    if (!str_contains($sources['rules'], $marker)) {
        throw new RuntimeException('Missing Firestore Bed Lookup authorization marker: ' . $marker);
    }
}
if (str_contains($sources['rules'], "taskData.keys().hasAny(['tenant_key'])")
    || str_contains($sources['rules'], "taskData.keys().hasAny(['project_key'])")) {
    throw new RuntimeException('Bed Lookup reads retain an optional tenant/project authorization fallback.');
}
if (str_contains($sources['rules'], 'taskData.task_group_keys is list')) {
    throw new RuntimeException('Bed Lookup reads retain a redundant task_group_keys type predicate that breaks array-contains-any queries.');
}

foreach ([
    '/scripts/firebase-bed-task-sync.mjs',
    '/scripts/firebase-bed-task-sync-down.mjs',
    '/scripts/sync-bed-task-pending.php',
    '/deploy/systemd/rbmsv4-firebase-bed-task-sync-down.service',
] as $relativePath) {
    if (is_file($root . $relativePath)) {
        throw new RuntimeException('Removed Bed Lookup sync artifact still exists: ' . $relativePath);
    }
}

$indexes = json_decode($sources['indexes'], true);
if (!is_array($indexes) || !is_array($indexes['indexes'] ?? null)) {
    throw new RuntimeException('Firestore index configuration could not be read.');
}
$bedTaskIndexes = array_values(array_filter($indexes['indexes'], static fn (array $index): bool => ($index['collectionGroup'] ?? '') === 'project_bed_task'));
$normalizedBedTaskIndexes = array_map(static function (array $index): array {
    return array_map(static fn (array $field): array => [$field['fieldPath'] ?? '', $field['arrayConfig'] ?? $field['order'] ?? ''], $index['fields'] ?? []);
}, $bedTaskIndexes);
foreach ([
    [['tenant_key', 'ASCENDING'], ['project_key', 'ASCENDING'], ['task_group_keys', 'CONTAINS']],
    [['tenant_key', 'ASCENDING'], ['project_key', 'ASCENDING'], ['bed_key', 'ASCENDING'], ['task_key', 'ASCENDING'], ['task_group_keys', 'CONTAINS']],
] as $requiredIndex) {
    if (!in_array($requiredIndex, $normalizedBedTaskIndexes, true)) {
        throw new RuntimeException('Missing tenant-aware Bed Lookup Firestore composite index.');
    }
}

echo "Portal Bed Lookup Firebase-only static checks passed.\n";
