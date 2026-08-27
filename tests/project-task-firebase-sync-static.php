<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$administratorSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$syncScriptSource = file_get_contents($root . '/scripts/firebase-project-task-sync.mjs');

if (!is_string($foundationSource) || !is_string($administratorSource) || !is_string($frontendSource) || !is_string($syncScriptSource)) {
    throw new RuntimeException('Project task Firebase sync sources could not be read.');
}

$requiredFoundationMarkers = [
    'function bx_project_task_firebase_rows',
    'function bx_deleted_project_task_firebase_row',
    'function bx_sync_project_task_rows_to_firebase',
    'scripts/firebase-project-task-sync.mjs',
    'Firebase project task sync timed out. Task was saved locally; try manual sync again.',
    'proc_terminate($process)',
    "'stages' =>",
    "'responses' =>",
    "'deleted' => '1'",
];

foreach ($requiredFoundationMarkers as $marker) {
    if (strpos($foundationSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase foundation marker: ' . $marker);
    }
}

$requiredAdministratorMarkers = [
    'function bx_admin_project_task_firebase_step',
    'sync_project_task_firebase',
    'Project tasks synced.',
    'Task created and synced.',
    'Task updated and synced.',
    'Task deleted and synced.',
    'Task stage created and synced.',
    'Stage response created and synced.',
    'update_project_task_stage_response_sort_order',
    "'message' => \$firebaseResult['ok'] ? 'Stage response updated.' : 'Stage response updated; Firebase sync needs attention.'",
    "'firebase_collection' => 'project_task'",
];

foreach ($requiredAdministratorMarkers as $marker) {
    if (strpos($administratorSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase administrator marker: ' . $marker);
    }
}

$requiredFrontendMarkers = [
    'taskFirebaseSyncFormRef',
    'name="action" value="sync_project_task_firebase"',
    'Sync tasks to Firebase',
    'onClick={() => taskFirebaseSyncFormRef.current?.requestSubmit()}',
    'const [confirmation, setConfirmation] = React.useState<ConfirmationState>(null)',
    '<ConfirmationModal confirmation={confirmation} onClose={() => setConfirmation(null)} />',
    "editingTask ? 'Confirm task update' : 'Confirm task creation'",
    "editingStage ? 'Confirm stage update' : 'Confirm stage creation'",
    "editingResponse ? 'Confirm response update' : 'Confirm response creation'",
    "'Confirm stage connection'",
    'form.dataset.taskBuilderConfirmedSubmit = \'true\'',
    'HTMLFormElement.prototype.reportValidity.call(form)',
    'ref={taskFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={taskFormRef} method="post" data-skip-submit-confirmation="true" data-skip-shell-loading="true"',
    'ref={stageFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={stageFormRef} method="post" data-skip-submit-confirmation="true" data-skip-shell-loading="true"',
    'ref={responseFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={responseFormRef} method="post" data-skip-submit-confirmation="true" data-skip-shell-loading="true"',
    'ref={stageConnectionFormRef} method="post" data-skip-submit-confirmation="true"',
    'ref={stageConnectionFormRef} method="post" data-skip-submit-confirmation="true" data-skip-shell-loading="true"',
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
];

foreach ($requiredFrontendMarkers as $marker) {
    if (strpos($frontendSource, $marker) === false) {
        throw new RuntimeException('Missing project task Firebase frontend marker: ' . $marker);
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

$taskBuilderStart = strpos($frontendSource, 'function TaskBuilderView()');
$taskBuilderEnd = strpos($frontendSource, 'function TemplateCommandView()', $taskBuilderStart === false ? 0 : $taskBuilderStart);
if ($taskBuilderStart === false || $taskBuilderEnd === false) {
    throw new RuntimeException('Task Builder source boundaries could not be located.');
}
$taskBuilderSource = substr($frontendSource, $taskBuilderStart, $taskBuilderEnd - $taskBuilderStart);
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
