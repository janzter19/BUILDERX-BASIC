<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$administratorSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$syncScriptSource = file_get_contents($root . '/scripts/firebase-project-task-sync.mjs');
$writerSource = file_get_contents($root . '/scripts/firebase-admin-task-write.mjs');

if (!is_string($foundationSource) || !is_string($administratorSource) || !is_string($frontendSource) || !is_string($syncScriptSource) || !is_string($writerSource)) {
    throw new RuntimeException('Project task Firebase sync sources could not be read.');
}

$requiredFoundationMarkers = [
    'function bx_admin_write_project_task_firebase_first',
    'function bx_admin_heal_project_task_default_stage',
    'function bx_project_task_firebase_rows',
    'function bx_deleted_project_task_firebase_row',
    'function bx_sync_project_task_rows_to_firebase',
    'scripts/firebase-project-task-sync.mjs',
    'Firebase project task sync timed out. Task was saved locally; try manual sync again.',
    'proc_terminate($process)',
    "'stages' =>",
    "'responses' =>",
    "'deleted' => '1'",
    "DATE_FORMAT(t.firebase_created_at",
    "DATE_FORMAT(t.firebase_updated_at",
    "t.firebase_updated_at DESC",
    "t.task_key DESC",
];

foreach ($requiredFoundationMarkers as $marker) {
    if (strpos($foundationSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase foundation marker: ' . $marker);
    }
}

$requiredAdministratorMarkers = [
    'Task Builder mutations are Firebase-first.',
    'Firebase-first healing: a task must have one active initial NEW stage.',
    'mysql_sync_status=PENDING',
    'update_project_task_stage_response_sort_order',
    '\'response\' => $responsePayload',
    '\'firebase_sync\' => $firebaseTasks[0]',
];

foreach ($requiredAdministratorMarkers as $marker) {
    if (strpos($administratorSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase administrator marker: ' . $marker);
    }
}

$requiredFrontendMarkers = [
    'const [confirmation, setConfirmation] = React.useState<ConfirmationState>(null)',
    '<ConfirmationModal confirmation={confirmation} onClose={() => setConfirmation(null)} />',
    "editingTask ? 'Confirm task update' : 'Confirm task creation'",
    "editingStage ? 'Confirm stage update' : 'Confirm stage creation'",
    "editingResponse ? 'Confirm response update' : 'Confirm response creation'",
    "'Confirm stage connection'",
    'form.dataset.taskBuilderConfirmedSubmit = \'true\'',
    "form.dataset.skipSubmitConfirmation === 'true' && form.dataset.taskBuilderConfirmedSubmit !== 'true'",
    'HTMLFormElement.prototype.reportValidity.call(form)',
    'ref={taskFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={stageFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={responseFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={stageConnectionFormRef} method="post" data-skip-submit-confirmation="true"',
    '<Button type="submit">',
    'onClick={() => responseFormRef.current?.requestSubmit()}',
    'data-stage-response-node={responseKey}',
    'openStageResponseManager(item.stage)',
    'editStageResponse(response)',
    'name="action" value="delete_project_task_stage_response"',
    'name="task_stage_response_key" value={responseKey}',
    'const [responseSaveMessage, setResponseSaveMessage]',
    'setResponseSaveMessage(error instanceof Error ? error.message',
    'Response saved. Firebase sync needs attention.',
    'function HeaderLoadingIndicator',
    'function TaskBuilderModalHeaderLoading',
    'active={adminNetworkLoading && activeView !== \'bed-lookup\'} className="absolute inset-x-0 bottom-0 z-30"',
];

foreach ($requiredFrontendMarkers as $marker) {
    if (strpos($frontendSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase frontend marker: ' . $marker);
    }
}

$taskBuilderStart = strpos($frontendSource, 'function TaskBuilderView()');
$taskBuilderEnd = strpos($frontendSource, 'function TemplateCommandView()', $taskBuilderStart === false ? 0 : $taskBuilderStart);
if ($taskBuilderStart === false || $taskBuilderEnd === false) {
    throw new RuntimeException('Task Builder source boundaries could not be located.');
}
$taskBuilderSource = substr($frontendSource, $taskBuilderStart, $taskBuilderEnd - $taskBuilderStart);
if (str_contains($taskBuilderSource, 'data-skip-shell-loading="true"')) {
    throw new RuntimeException('Task Builder forms must participate in the global loading indicator.');
}

foreach (['sync_project_task_firebase', 'Sync tasks to Firebase', 'taskFirebaseSyncFormRef'] as $marker) {
    if (strpos($frontendSource, $marker) !== false) {
        throw new RuntimeException('Obsolete manual task sync artifact remains: ' . $marker);
    }
}

foreach ([
    'CREATE TABLE IF NOT EXISTS project_task',
    'CREATE TABLE IF NOT EXISTS project_task_stage',
    'CREATE TABLE IF NOT EXISTS project_task_stage_response',
    'RENAME TABLE project_task_list TO project_task',
    'bx_add_column_if_missing(\'project_task\'',
    'bx_add_column_if_missing(\'project_task_stage\'',
    'bx_add_column_if_missing(\'project_task_stage_response\'',
] as $marker) {
    if (strpos($foundationSource, $marker) !== false) {
        throw new RuntimeException('Legacy Task Builder schema repair remains: ' . $marker);
    }
}

if (str_contains($administratorSource, 'function bx_admin_project_task_firebase_step')) {
    throw new RuntimeException('Removed orphan Task Builder Firebase step helper remains in Administrator.');
}

$requiredWriterMarkers = [
    "['project_task', 'project_task_stage', 'project_task_stage_response']",
    "data.mysql_sync_status = 'PENDING'",
    'db.collection(collection).doc()',
    'firebase_readback_failed',
    "operation === 'soft_delete'",
    "operation === 'ensure_default_stage'",
    "stage_label: 'NEW'",
    'db.runTransaction',
    'default_stage_collection_invalid',
];
foreach ($requiredWriterMarkers as $marker) {
    if (strpos($writerSource, $marker) === false) {
        throw new RuntimeException('Missing Firebase-first task writer marker: ' . $marker);
    }
}

$forbiddenFrontendMarkers = [
    'title: modalConfirmTitle',
    'title: stageModalConfirmTitle',
    'title: responseModalConfirmTitle',
    "title: 'Confirm stage connection'",
];

foreach ($forbiddenFrontendMarkers as $marker) {
    if (strpos($frontendSource, $marker) !== false) {
        throw new RuntimeException('Task Builder still contains nested submit confirmation marker: ' . $marker);
    }
}

foreach (['setConfirmation(', '<ConfirmationModal'] as $marker) {
    if (strpos($taskBuilderSource, $marker) === false) {
        throw new RuntimeException('Task Builder is missing confirmation UI marker: ' . $marker);
    }
}

$requiredScriptMarkers = [
    "collection: 'project_task'",
    "collection: 'project_task_stage'",
    "collection: 'project_task_stage_response'",
    "firebase_collection: 'project_task'",
    "firebase_collection: 'project_task_stage'",
    "firebase_collection: 'project_task_stage_response'",
    'server_synced_at: FieldValue.serverTimestamp()',
    "collections: ['project_task', 'project_task_stage', 'project_task_stage_response']",
    'synced_responses',
    'stages',
    'responses',
    'deleted: true',
];

foreach ($requiredScriptMarkers as $marker) {
    if (strpos($syncScriptSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase script marker: ' . $marker);
    }
}

echo "Project task Firebase sync static checks passed.\n";
