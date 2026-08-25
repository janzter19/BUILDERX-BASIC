<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

$db = bx_db();

bx_ensure_project_task_stage_schema();
bx_ensure_project_task_stage_response_schema();
bx_ensure_project_bed_reference_schema();

$tableExists = (int) $db->GetOne(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [BUILDERX_DB_NAME, 'project_task']
);
if ($tableExists !== 1) {
    throw new RuntimeException('project_task table was not created.');
}
$stageTableExists = (int) $db->GetOne(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [BUILDERX_DB_NAME, 'project_task_stage']
);
if ($stageTableExists !== 1) {
    throw new RuntimeException('project_task_stage table was not created.');
}
$responseTableExists = (int) $db->GetOne(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [BUILDERX_DB_NAME, 'project_task_stage_response']
);
if ($responseTableExists !== 1) {
    throw new RuntimeException('project_task_stage_response table was not created.');
}
$bedTreatmentTableExists = (int) $db->GetOne(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [BUILDERX_DB_NAME, 'project_bed_treatment']
);
if ($bedTreatmentTableExists !== 1) {
    throw new RuntimeException('project_bed_treatment table was not created.');
}
$admissionSourceTableExists = (int) $db->GetOne(
    'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [BUILDERX_DB_NAME, 'project_bed_source']
);
if ($admissionSourceTableExists !== 1) {
    throw new RuntimeException('project_bed_source table was not created.');
}

$requiredColumns = [
    'x_id',
    'task_key',
    'task_code',
    'task_title',
    'task_description',
    'task_group_keys',
    'task_bypass_group_keys',
    'task_type',
    'task_status',
    'task_priority',
    'task_color_hex',
    'task_can_run_manually',
    'task_can_run_via_api',
    'task_can_run_if_bed_vacant',
    'task_can_run_if_bed_occupied',
    'task_requires_bed_treatment',
    'task_requires_admission_source',
    'task_canvas_x',
    'task_canvas_y',
    'task_sort_order',
    'created_by_user_key',
    'updated_by_user_key',
    'created_at',
    'updated_at',
];
$actualColumns = $db->GetCol(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [BUILDERX_DB_NAME, 'project_task']
);
if (!is_array($actualColumns)) {
    throw new RuntimeException('project_task column inspection failed.');
}
foreach ($requiredColumns as $column) {
    if (!in_array($column, $actualColumns, true)) {
        throw new RuntimeException('project_task is missing column: ' . $column . '.');
    }
}
foreach (['project_key', 'assigned_user_key', 'due_at', 'completed_at'] as $removedColumn) {
    if (in_array($removedColumn, $actualColumns, true)) {
        throw new RuntimeException('project_task still has removed column: ' . $removedColumn . '.');
    }
}
$taskColorColumn = $db->GetRow(
    'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [BUILDERX_DB_NAME, 'project_task', 'task_color_hex']
);
$taskColorDefault = is_array($taskColorColumn) ? trim((string) ($taskColorColumn['column_default'] ?? ''), "'") : '';
if (
    !is_array($taskColorColumn)
    || strtolower((string) ($taskColorColumn['column_type'] ?? '')) !== 'char(9)'
    || $taskColorDefault !== '#00000000'
) {
    throw new RuntimeException('project_task task_color_hex is not configured for transparent alpha hex defaults.');
}

$requiredStageColumns = [
    'x_id',
    'task_stage_key',
    'task_key',
    'stage_label',
    'stage_description',
    'stage_color_hex',
    'stage_status',
    'stage_ends_task',
    'stage_can_run_manually',
    'stage_can_run_via_api',
    'connected_task_key',
    'connected_task_trigger_point',
    'stage_sort_order',
    'created_by_user_key',
    'updated_by_user_key',
    'created_at',
    'updated_at',
];
$actualStageColumns = $db->GetCol(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [BUILDERX_DB_NAME, 'project_task_stage']
);
if (!is_array($actualStageColumns)) {
    throw new RuntimeException('project_task_stage column inspection failed.');
}
foreach ($requiredStageColumns as $column) {
    if (!in_array($column, $actualStageColumns, true)) {
        throw new RuntimeException('project_task_stage is missing column: ' . $column . '.');
    }
}
$stageColorColumn = $db->GetRow(
    'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [BUILDERX_DB_NAME, 'project_task_stage', 'stage_color_hex']
);
$stageColorDefault = is_array($stageColorColumn) ? trim((string) ($stageColorColumn['column_default'] ?? ''), "'") : '';
if (
    !is_array($stageColorColumn)
    || strtolower((string) ($stageColorColumn['column_type'] ?? '')) !== 'char(9)'
    || $stageColorDefault !== '#00000000'
) {
    throw new RuntimeException('project_task_stage stage_color_hex is not configured for transparent alpha hex defaults.');
}
$stageTriggerColumn = $db->GetRow(
    'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [BUILDERX_DB_NAME, 'project_task_stage', 'connected_task_trigger_point']
);
$stageTriggerDefault = is_array($stageTriggerColumn) ? trim((string) ($stageTriggerColumn['column_default'] ?? ''), "'") : '';
if (
    !is_array($stageTriggerColumn)
    || strtoupper((string) ($stageTriggerColumn['column_type'] ?? '')) !== "ENUM('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED')"
    || $stageTriggerDefault !== 'CURRENT_STAGE_FINISHED'
) {
    throw new RuntimeException('project_task_stage connected_task_trigger_point is not configured with the expected trigger enum default.');
}

$requiredResponseColumns = [
    'x_id',
    'task_stage_response_key',
    'task_key',
    'task_stage_key',
    'response_label',
    'response_description',
    'response_color_hex',
    'response_status',
    'response_sort_order',
    'created_by_user_key',
    'updated_by_user_key',
    'created_at',
    'updated_at',
];
$actualResponseColumns = $db->GetCol(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [BUILDERX_DB_NAME, 'project_task_stage_response']
);
if (!is_array($actualResponseColumns)) {
    throw new RuntimeException('project_task_stage_response column inspection failed.');
}
foreach ($requiredResponseColumns as $column) {
    if (!in_array($column, $actualResponseColumns, true)) {
        throw new RuntimeException('project_task_stage_response is missing column: ' . $column . '.');
    }
}
$responseColorColumn = $db->GetRow(
    'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [BUILDERX_DB_NAME, 'project_task_stage_response', 'response_color_hex']
);
$responseColorDefault = is_array($responseColorColumn) ? trim((string) ($responseColorColumn['column_default'] ?? ''), "'") : '';
if (
    !is_array($responseColorColumn)
    || strtolower((string) ($responseColorColumn['column_type'] ?? '')) !== 'char(9)'
    || $responseColorDefault !== '#00000000'
) {
    throw new RuntimeException('project_task_stage_response response_color_hex is not configured for transparent alpha hex defaults.');
}
$responseStatusColumn = $db->GetRow(
    'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
    [BUILDERX_DB_NAME, 'project_task_stage_response', 'response_status']
);
$responseStatusDefault = is_array($responseStatusColumn) ? trim((string) ($responseStatusColumn['column_default'] ?? ''), "'") : '';
if (
    !is_array($responseStatusColumn)
    || strtoupper((string) ($responseStatusColumn['column_type'] ?? '')) !== "ENUM('ACTIVE','INACTIVE')"
    || $responseStatusDefault !== 'ACTIVE'
) {
    throw new RuntimeException('project_task_stage_response response_status is not configured with the expected status enum default.');
}

$requiredIndexes = [
    'task_key',
    'idx_project_task_type',
    'idx_project_task_status',
    'idx_project_task_priority',
    'idx_project_task_code',
];
foreach ($requiredIndexes as $index) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task', $index]
    );
    if ($exists < 1) {
        throw new RuntimeException('project_task is missing index: ' . $index . '.');
    }
}

$requiredStageIndexes = [
    'task_stage_key',
    'idx_project_task_stage_task',
    'idx_project_task_stage_status',
    'idx_project_task_stage_connected',
];
foreach ($requiredStageIndexes as $index) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage', $index]
    );
    if ($exists < 1) {
        throw new RuntimeException('project_task_stage is missing index: ' . $index . '.');
    }
}

$requiredResponseIndexes = [
    'task_stage_response_key',
    'idx_project_task_stage_response_stage',
    'idx_project_task_stage_response_task',
    'idx_project_task_stage_response_status',
];
foreach ($requiredResponseIndexes as $index) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage_response', $index]
    );
    if ($exists < 1) {
        throw new RuntimeException('project_task_stage_response is missing index: ' . $index . '.');
    }
}

$requiredBedTreatmentColumns = [
    'x_id',
    'bed_treatment_key',
    'treatment_code',
    'treatment_name',
    'treatment_description',
    'treatment_status',
    'treatment_sort_order',
    'created_by_user_key',
    'updated_by_user_key',
    'created_at',
    'updated_at',
];
$actualBedTreatmentColumns = $db->GetCol(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [BUILDERX_DB_NAME, 'project_bed_treatment']
);
foreach ($requiredBedTreatmentColumns as $column) {
    if (!is_array($actualBedTreatmentColumns) || !in_array($column, $actualBedTreatmentColumns, true)) {
        throw new RuntimeException('project_bed_treatment is missing column: ' . $column . '.');
    }
}
foreach (['bed_treatment_key', 'uq_project_bed_treatment_code', 'idx_project_bed_treatment_status'] as $index) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'project_bed_treatment', $index]
    );
    if ($exists < 1) {
        throw new RuntimeException('project_bed_treatment is missing index: ' . $index . '.');
    }
}

$requiredBedSourceColumns = [
    'x_id',
    'bed_source_key',
    'bed_source_code',
    'bed_source_name',
    'bed_source_description',
    'bed_source_status',
    'bed_source_sort_order',
    'created_by_user_key',
    'updated_by_user_key',
    'created_at',
    'updated_at',
];
$actualBedSourceColumns = $db->GetCol(
    'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
    [BUILDERX_DB_NAME, 'project_bed_source']
);
foreach ($requiredBedSourceColumns as $column) {
    if (!is_array($actualBedSourceColumns) || !in_array($column, $actualBedSourceColumns, true)) {
        throw new RuntimeException('project_bed_source is missing column: ' . $column . '.');
    }
}
foreach (['bed_source_key', 'uq_project_bed_source_code', 'idx_project_bed_source_status'] as $index) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'project_bed_source', $index]
    );
    if ($exists < 1) {
        throw new RuntimeException('project_bed_source is missing index: ' . $index . '.');
    }
}

$cleanupResult = $db->Execute(
    'DELETE FROM project_task_stage_response WHERE task_key IN (SELECT task_key FROM project_task WHERE task_title = ? AND task_description = ?)',
    ['Disposable task builder row', 'Created only for project_task schema verification.']
);
if ($cleanupResult === false) {
    throw new RuntimeException('project_task_stage_response disposable-row cleanup failed: ' . $db->ErrorMsg());
}

$cleanupResult = $db->Execute(
    'DELETE FROM project_task_stage WHERE task_key IN (SELECT task_key FROM project_task WHERE task_title = ? AND task_description = ?)',
    ['Disposable task builder row', 'Created only for project_task schema verification.']
);
if ($cleanupResult === false) {
    throw new RuntimeException('project_task_stage disposable-row cleanup failed: ' . $db->ErrorMsg());
}
$cleanupResult = $db->Execute(
    'DELETE FROM project_task WHERE task_title = ? AND task_description = ?',
    ['Disposable task builder row', 'Created only for project_task schema verification.']
);
if ($cleanupResult === false) {
    throw new RuntimeException('project_task disposable-row cleanup failed: ' . $db->ErrorMsg());
}

$db->Execute('DELETE FROM project_bed_treatment WHERE treatment_code IN (?, ?)', ['TEST-TREAT', 'TEST-TREAT-UPD']);
$db->Execute('DELETE FROM project_bed_source WHERE bed_source_code IN (?, ?)', ['TEST-ADM', 'TEST-ADM-UPD']);

$savedTreatment = bx_save_project_bed_treatment([
    'treatment_code' => 'TEST-TREAT',
    'treatment_name' => 'Disposable Treatment',
    'treatment_description' => 'Schema test treatment.',
    'treatment_status' => 'ACTIVE',
    'treatment_sort_order' => '7',
], bx_uuid());
$savedTreatmentKey = (string) ($savedTreatment['bed_treatment_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $savedTreatmentKey) || (string) ($savedTreatment['treatment_status'] ?? '') !== 'ACTIVE') {
    throw new RuntimeException('project_bed_treatment helper did not create and read back an active row.');
}
$updatedTreatment = bx_save_project_bed_treatment([
    'bed_treatment_key' => $savedTreatmentKey,
    'treatment_code' => 'TEST-TREAT-UPD',
    'treatment_name' => 'Disposable Treatment Updated',
    'treatment_description' => 'Schema test treatment updated.',
    'treatment_status' => 'INACTIVE',
    'treatment_sort_order' => '8',
], bx_uuid());
if ((string) ($updatedTreatment['treatment_code'] ?? '') !== 'TEST-TREAT-UPD' || (string) ($updatedTreatment['treatment_status'] ?? '') !== 'INACTIVE') {
    throw new RuntimeException('project_bed_treatment helper did not update and read back the row.');
}
$deletedTreatment = bx_set_project_bed_treatment_status([
    'bed_treatment_key' => $savedTreatmentKey,
    'treatment_status' => 'DELETED',
], bx_uuid());
if ((string) ($deletedTreatment['treatment_status'] ?? '') !== 'DELETED') {
    throw new RuntimeException('project_bed_treatment status helper did not soft-delete the row.');
}

$savedSource = bx_save_project_bed_source([
    'bed_source_code' => 'TEST-ADM',
    'bed_source_name' => 'Disposable Admission',
    'bed_source_description' => 'Schema test admission source.',
    'bed_source_status' => 'ACTIVE',
    'bed_source_sort_order' => '5',
], bx_uuid());
$savedSourceKey = (string) ($savedSource['bed_source_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $savedSourceKey) || (string) ($savedSource['bed_source_status'] ?? '') !== 'ACTIVE') {
    throw new RuntimeException('project_bed_source helper did not create and read back an active row.');
}
$updatedSource = bx_save_project_bed_source([
    'bed_source_key' => $savedSourceKey,
    'bed_source_code' => 'TEST-ADM-UPD',
    'bed_source_name' => 'Disposable Admission Updated',
    'bed_source_description' => 'Schema test admission source updated.',
    'bed_source_status' => 'INACTIVE',
    'bed_source_sort_order' => '6',
], bx_uuid());
if ((string) ($updatedSource['bed_source_code'] ?? '') !== 'TEST-ADM-UPD' || (string) ($updatedSource['bed_source_status'] ?? '') !== 'INACTIVE') {
    throw new RuntimeException('project_bed_source helper did not update and read back the row.');
}
$deletedSource = bx_set_project_bed_source_status([
    'bed_source_key' => $savedSourceKey,
    'bed_source_status' => 'DELETED',
], bx_uuid());
if ((string) ($deletedSource['bed_source_status'] ?? '') !== 'DELETED') {
    throw new RuntimeException('project_bed_source status helper did not soft-delete the row.');
}

$payloadRows = bx_project_task_rows(1);
if (!is_array($payloadRows)) {
    throw new RuntimeException('project_task payload helper did not return an array.');
}
$availableGroupKey = (string) ($db->GetOne("SELECT group_key FROM project_user_group WHERE group_status <> 'DELETED' ORDER BY group_name ASC LIMIT 1") ?: '');
$taskGroupKeys = $availableGroupKey !== '' ? [$availableGroupKey] : [];
$taskBypassGroupKeys = $taskGroupKeys;

$createdTask = bx_create_project_task([
    'task_code' => 'PT-CREATE',
    'task_title' => 'Disposable task builder row',
    'task_description' => 'Created only for project_task schema verification.',
    'task_group_keys' => $taskGroupKeys,
    'task_bypass_group_keys' => $taskBypassGroupKeys,
    'task_type' => 'SECONDARY',
    'task_priority' => 'HIGH',
    'task_can_run_manually' => '1',
    'task_can_run_via_api' => '0',
    'task_can_run_if_bed_vacant' => '1',
    'task_can_run_if_bed_occupied' => '0',
    'task_requires_bed_treatment' => '1',
    'task_requires_admission_source' => '0',
], bx_uuid());
$createdTaskKey = (string) ($createdTask['task_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $createdTaskKey)) {
    throw new RuntimeException('project_task helper did not create a Firestore-style task_key.');
}
if ((string) ($createdTask['task_type'] ?? '') !== 'SECONDARY') {
    throw new RuntimeException('project_task helper did not read back the requested task_type.');
}
if ((string) ($createdTask['task_status'] ?? '') !== 'INACTIVE') {
    throw new RuntimeException('project_task helper did not default new tasks to INACTIVE.');
}
if ((string) ($createdTask['task_color_hex'] ?? '') !== '#00000000') {
    throw new RuntimeException('project_task helper did not default task_color_hex to transparent.');
}
if ((int) ($createdTask['task_can_run_manually'] ?? 0) !== 1 || (int) ($createdTask['task_can_run_via_api'] ?? 0) !== 0) {
    throw new RuntimeException('project_task helper did not read back task run-mode values.');
}
if ((int) ($createdTask['task_can_run_if_bed_vacant'] ?? 0) !== 1 || (int) ($createdTask['task_can_run_if_bed_occupied'] ?? 0) !== 0) {
    throw new RuntimeException('project_task helper did not read back task bed-state run-mode values.');
}
if ((int) ($createdTask['task_requires_bed_treatment'] ?? 0) !== 1 || (int) ($createdTask['task_requires_admission_source'] ?? 0) !== 0) {
    throw new RuntimeException('project_task helper did not read back required submission selections.');
}
if ((string) ($createdTask['task_group_keys'] ?? '[]') !== json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES)) {
    throw new RuntimeException('project_task helper did not read back the selected task groups.');
}
if ((string) ($createdTask['task_bypass_group_keys'] ?? '[]') !== json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES)) {
    throw new RuntimeException('project_task helper did not read back the selected bypass groups.');
}
$connectedTargetTask = bx_create_project_task([
    'task_code' => 'PT-CONNECT',
    'task_title' => 'Disposable task builder row',
    'task_description' => 'Created only for project_task schema verification.',
    'task_type' => 'SECONDARY',
    'task_priority' => 'NORMAL',
], bx_uuid());
$connectedTargetTaskKey = (string) ($connectedTargetTask['task_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $connectedTargetTaskKey) || (string) ($connectedTargetTask['task_type'] ?? '') !== 'SECONDARY') {
    throw new RuntimeException('project_task helper did not create a secondary target task for stage connection verification.');
}
$defaultStage = $db->GetRow(
    'SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, \'\') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, \'\') AS connected_task_key, connected_task_trigger_point, stage_sort_order FROM project_task_stage WHERE task_key = ? AND stage_label = ? LIMIT 1',
    [$createdTaskKey, 'NEW']
);
if (
    !is_array($defaultStage)
    || !preg_match('/^[A-Za-z0-9]{20}$/', (string) ($defaultStage['task_stage_key'] ?? ''))
    || (string) ($defaultStage['task_key'] ?? '') !== $createdTaskKey
    || (string) ($defaultStage['stage_description'] ?? '') !== 'Default starting stage.'
    || (string) ($defaultStage['stage_color_hex'] ?? '') !== '#00000000'
    || (string) ($defaultStage['stage_status'] ?? '') !== 'ACTIVE'
    || (int) ($defaultStage['stage_ends_task'] ?? 0) !== 0
    || (int) ($defaultStage['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($defaultStage['stage_can_run_via_api'] ?? 0) !== 0
    || (string) ($defaultStage['connected_task_key'] ?? '') !== ''
    || (string) ($defaultStage['connected_task_trigger_point'] ?? '') !== 'CURRENT_STAGE_FINISHED'
    || (int) ($defaultStage['stage_sort_order'] ?? 0) !== 1
) {
    throw new RuntimeException('project_task helper did not auto-create the default NEW stage.');
}
$payloadRows = bx_project_task_rows(5);
$payloadRow = null;
foreach ($payloadRows as $row) {
    if ((string) ($row['task_key'] ?? '') === $createdTaskKey) {
        $payloadRow = $row;
        break;
    }
}
if (
    !is_array($payloadRow)
    || (string) ($payloadRow['task_title'] ?? '') !== 'Disposable task builder row'
    || (string) ($payloadRow['task_type'] ?? '') !== 'SECONDARY'
    || (string) ($payloadRow['task_status'] ?? '') !== 'INACTIVE'
    || (string) ($payloadRow['task_group_keys'] ?? '[]') !== json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES)
    || (string) ($payloadRow['task_bypass_group_keys'] ?? '[]') !== json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES)
    || (string) ($payloadRow['task_color_hex'] ?? '') !== '#00000000'
    || (int) ($payloadRow['task_can_run_manually'] ?? 0) !== 1
    || (int) ($payloadRow['task_can_run_via_api'] ?? 0) !== 0
    || (int) ($payloadRow['task_can_run_if_bed_vacant'] ?? 0) !== 1
    || (int) ($payloadRow['task_can_run_if_bed_occupied'] ?? 0) !== 0
    || (int) ($payloadRow['task_requires_bed_treatment'] ?? 0) !== 1
    || (int) ($payloadRow['task_requires_admission_source'] ?? 0) !== 0
    || (int) ($payloadRow['task_canvas_x'] ?? 0) !== 24
    || (int) ($payloadRow['task_canvas_y'] ?? 0) !== 24
) {
    throw new RuntimeException('project_task payload helper did not return the created helper row.');
}
$movedTask = bx_update_project_task_canvas_position([
    'task_key' => $createdTaskKey,
    'task_canvas_x' => '144',
    'task_canvas_y' => '108',
], bx_uuid());
if (
    (string) ($movedTask['task_key'] ?? '') !== $createdTaskKey
    || (int) ($movedTask['task_canvas_x'] ?? 0) !== 144
    || (int) ($movedTask['task_canvas_y'] ?? 0) !== 108
) {
    throw new RuntimeException('project_task canvas position helper did not read back the moved task values.');
}
$deletedDefaultStages = $db->Execute('DELETE FROM project_task_stage WHERE task_key = ?', [$createdTaskKey]);
if ($deletedDefaultStages === false) {
    throw new RuntimeException('project_task_stage default-stage delete failed: ' . $db->ErrorMsg());
}
$repairedStages = bx_repair_project_task_default_stages();
if ($repairedStages < 1) {
    throw new RuntimeException('project_task_stage repair did not create a missing default NEW stage.');
}
$repairedDefaultStage = $db->GetRow(
    'SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, \'\') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, \'\') AS connected_task_key, connected_task_trigger_point, stage_sort_order FROM project_task_stage WHERE task_key = ? AND stage_label = ? LIMIT 1',
    [$createdTaskKey, 'NEW']
);
if (
    !is_array($repairedDefaultStage)
    || !preg_match('/^[A-Za-z0-9]{20}$/', (string) ($repairedDefaultStage['task_stage_key'] ?? ''))
    || (string) ($repairedDefaultStage['task_key'] ?? '') !== $createdTaskKey
    || (string) ($repairedDefaultStage['stage_description'] ?? '') !== 'Default starting stage.'
    || (string) ($repairedDefaultStage['stage_color_hex'] ?? '') !== '#00000000'
    || (string) ($repairedDefaultStage['stage_status'] ?? '') !== 'ACTIVE'
    || (int) ($repairedDefaultStage['stage_ends_task'] ?? 0) !== 0
    || (int) ($repairedDefaultStage['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($repairedDefaultStage['stage_can_run_via_api'] ?? 0) !== 0
    || (string) ($repairedDefaultStage['connected_task_key'] ?? '') !== ''
    || (string) ($repairedDefaultStage['connected_task_trigger_point'] ?? '') !== 'CURRENT_STAGE_FINISHED'
    || (int) ($repairedDefaultStage['stage_sort_order'] ?? 0) !== 1
) {
    throw new RuntimeException('project_task_stage repair did not read back the default NEW stage.');
}
$createdStage = bx_create_project_task_stage([
    'task_key' => $createdTaskKey,
    'stage_label' => 'Disposable verification stage',
    'stage_description' => 'Created only for project_task_stage schema verification.',
    'stage_ends_task' => '1',
], bx_uuid());
$createdStageKey = (string) ($createdStage['task_stage_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $createdStageKey)) {
    throw new RuntimeException('project_task_stage helper did not create a Firestore-style task_stage_key.');
}
if (
    (string) ($createdStage['task_key'] ?? '') !== $createdTaskKey
    || (string) ($createdStage['stage_label'] ?? '') !== 'Disposable verification stage'
    || (string) ($createdStage['stage_description'] ?? '') !== 'Created only for project_task_stage schema verification.'
    || (string) ($createdStage['stage_color_hex'] ?? '') !== '#00000000'
    || (string) ($createdStage['stage_status'] ?? '') !== 'INACTIVE'
    || (int) ($createdStage['stage_ends_task'] ?? 0) !== 1
    || (int) ($createdStage['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($createdStage['stage_can_run_via_api'] ?? 0) !== 0
) {
    throw new RuntimeException('project_task_stage helper did not read back the default stage values.');
}
$createdStageTwo = bx_create_project_task_stage([
    'task_key' => $createdTaskKey,
    'stage_label' => 'Disposable second stage',
    'stage_description' => 'Created only for project_task_stage sort verification.',
], bx_uuid());
$createdStageTwoKey = (string) ($createdStageTwo['task_stage_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $createdStageTwoKey)) {
    throw new RuntimeException('project_task_stage helper did not create a second Firestore-style task_stage_key.');
}
$reorderedStages = bx_update_project_task_stage_sort_order([
    'task_key' => $createdTaskKey,
    'stage_order_keys' => [
        $createdStageTwoKey,
        (string) ($repairedDefaultStage['task_stage_key'] ?? ''),
        $createdStageKey,
    ],
], bx_uuid());
if (
    count($reorderedStages) < 3
    || (string) ($reorderedStages[0]['stage_label'] ?? '') !== 'NEW'
    || (int) ($reorderedStages[0]['stage_sort_order'] ?? 0) !== 1
    || (string) ($reorderedStages[1]['task_stage_key'] ?? '') !== $createdStageTwoKey
    || (int) ($reorderedStages[1]['stage_sort_order'] ?? 0) !== 2
    || (string) ($reorderedStages[2]['task_stage_key'] ?? '') !== $createdStageKey
    || (int) ($reorderedStages[2]['stage_sort_order'] ?? 0) !== 3
) {
    throw new RuntimeException('project_task_stage sort helper did not keep NEW first and reorder movable stages.');
}
$stagePayloadRows = bx_project_task_stage_rows(20);
$stagePayloadRow = null;
foreach ($stagePayloadRows as $row) {
    if ((string) ($row['task_stage_key'] ?? '') === $createdStageKey) {
        $stagePayloadRow = $row;
        break;
    }
}
if (
    !is_array($stagePayloadRow)
    || (string) ($stagePayloadRow['task_key'] ?? '') !== $createdTaskKey
    || (string) ($stagePayloadRow['stage_label'] ?? '') !== 'Disposable verification stage'
    || (string) ($stagePayloadRow['stage_description'] ?? '') !== 'Created only for project_task_stage schema verification.'
    || (string) ($stagePayloadRow['stage_color_hex'] ?? '') !== '#00000000'
    || (string) ($stagePayloadRow['stage_status'] ?? '') !== 'INACTIVE'
    || (int) ($stagePayloadRow['stage_ends_task'] ?? 0) !== 1
    || (int) ($stagePayloadRow['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($stagePayloadRow['stage_can_run_via_api'] ?? 0) !== 0
    || (string) ($stagePayloadRow['connected_task_key'] ?? '') !== ''
    || (string) ($stagePayloadRow['connected_task_trigger_point'] ?? '') !== 'CURRENT_STAGE_FINISHED'
) {
    throw new RuntimeException('project_task_stage payload helper did not return the created stage row.');
}
$createdResponse = bx_create_project_task_stage_response([
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
    'response_label' => 'Disposable response',
    'response_description' => 'Created only for project_task_stage_response schema verification.',
    'response_color_hex' => '#14B8A680',
    'response_status' => 'ACTIVE',
], bx_uuid());
$createdResponseKey = (string) ($createdResponse['task_stage_response_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $createdResponseKey)) {
    throw new RuntimeException('project_task_stage_response helper did not create a Firestore-style task_stage_response_key.');
}
if (
    (string) ($createdResponse['task_key'] ?? '') !== $createdTaskKey
    || (string) ($createdResponse['task_stage_key'] ?? '') !== $createdStageKey
    || (string) ($createdResponse['response_label'] ?? '') !== 'Disposable response'
    || (string) ($createdResponse['response_description'] ?? '') !== 'Created only for project_task_stage_response schema verification.'
    || (string) ($createdResponse['response_color_hex'] ?? '') !== '#14B8A680'
    || (string) ($createdResponse['response_status'] ?? '') !== 'ACTIVE'
    || (int) ($createdResponse['response_sort_order'] ?? 0) !== 1
) {
    throw new RuntimeException('project_task_stage_response helper did not read back the created response values.');
}
$responsePayloadRows = bx_project_task_stage_response_rows(20);
$responsePayloadRow = null;
foreach ($responsePayloadRows as $row) {
    if ((string) ($row['task_stage_response_key'] ?? '') === $createdResponseKey) {
        $responsePayloadRow = $row;
        break;
    }
}
if (
    !is_array($responsePayloadRow)
    || (string) ($responsePayloadRow['task_key'] ?? '') !== $createdTaskKey
    || (string) ($responsePayloadRow['task_stage_key'] ?? '') !== $createdStageKey
    || (string) ($responsePayloadRow['response_label'] ?? '') !== 'Disposable response'
    || (string) ($responsePayloadRow['response_color_hex'] ?? '') !== '#14B8A680'
    || (string) ($responsePayloadRow['response_status'] ?? '') !== 'ACTIVE'
) {
    throw new RuntimeException('project_task_stage_response payload helper did not return the created response row.');
}
$updatedResponse = bx_update_project_task_stage_response([
    'task_stage_response_key' => $createdResponseKey,
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
    'response_label' => 'Disposable response updated',
    'response_description' => 'Updated only for project_task_stage_response schema verification.',
    'response_color_hex' => '#0EA5E9',
    'response_status' => 'INACTIVE',
], bx_uuid());
if (
    (string) ($updatedResponse['task_stage_response_key'] ?? '') !== $createdResponseKey
    || (string) ($updatedResponse['response_label'] ?? '') !== 'Disposable response updated'
    || (string) ($updatedResponse['response_description'] ?? '') !== 'Updated only for project_task_stage_response schema verification.'
    || (string) ($updatedResponse['response_color_hex'] ?? '') !== '#0EA5E9'
    || (string) ($updatedResponse['response_status'] ?? '') !== 'INACTIVE'
) {
    throw new RuntimeException('project_task_stage_response update helper did not read back the updated response values.');
}
$secondResponse = bx_create_project_task_stage_response([
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
    'response_label' => 'Disposable response second',
    'response_description' => 'Created only for project_task_stage_response sort verification.',
    'response_status' => 'ACTIVE',
], bx_uuid());
$secondResponseKey = (string) ($secondResponse['task_stage_response_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $secondResponseKey)) {
    throw new RuntimeException('project_task_stage_response helper did not create a second Firestore-style response key.');
}
$reorderedResponses = bx_update_project_task_stage_response_sort_order([
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
    'response_order_keys' => [$secondResponseKey, $createdResponseKey],
], bx_uuid());
if (
    count($reorderedResponses) < 2
    || (string) ($reorderedResponses[0]['task_stage_response_key'] ?? '') !== $secondResponseKey
    || (int) ($reorderedResponses[0]['response_sort_order'] ?? 0) !== 1
    || (string) ($reorderedResponses[1]['task_stage_response_key'] ?? '') !== $createdResponseKey
    || (int) ($reorderedResponses[1]['response_sort_order'] ?? 0) !== 2
) {
    throw new RuntimeException('project_task_stage_response sort helper did not read back the requested response order.');
}
$deletedResponse = bx_delete_project_task_stage_response([
    'task_stage_response_key' => $createdResponseKey,
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
], bx_uuid());
if (
    (string) ($deletedResponse['task_stage_response_key'] ?? '') !== $createdResponseKey
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$createdResponseKey]) !== 0
) {
    throw new RuntimeException('project_task_stage_response delete helper did not remove the selected response.');
}
$deletedSecondResponse = bx_delete_project_task_stage_response([
    'task_stage_response_key' => $secondResponseKey,
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageKey,
], bx_uuid());
if (
    (string) ($deletedSecondResponse['task_stage_response_key'] ?? '') !== $secondResponseKey
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$secondResponseKey]) !== 0
) {
    throw new RuntimeException('project_task_stage_response delete helper did not remove the second selected response.');
}
$updatedStage = bx_update_project_task_stage([
    'task_stage_key' => $createdStageKey,
    'task_key' => $createdTaskKey,
    'stage_label' => 'Disposable verification stage updated',
    'stage_description' => 'Updated only for project_task_stage schema verification.',
    'stage_color_hex' => '#2563EB',
    'stage_status' => 'ACTIVE',
    'stage_ends_task' => '1',
], bx_uuid());
if (
    (string) ($updatedStage['task_stage_key'] ?? '') !== $createdStageKey
    || (string) ($updatedStage['stage_label'] ?? '') !== 'Disposable verification stage updated'
    || (string) ($updatedStage['stage_description'] ?? '') !== 'Updated only for project_task_stage schema verification.'
    || (string) ($updatedStage['stage_color_hex'] ?? '') !== '#2563EB'
    || (string) ($updatedStage['stage_status'] ?? '') !== 'ACTIVE'
    || (int) ($updatedStage['stage_ends_task'] ?? 0) !== 1
    || (int) ($updatedStage['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($updatedStage['stage_can_run_via_api'] ?? 0) !== 0
) {
    throw new RuntimeException('project_task_stage update helper did not read back the updated stage values.');
}
$connectedStage = bx_update_project_task_stage_connection([
    'task_stage_key' => $createdStageKey,
    'task_key' => $createdTaskKey,
    'connected_task_key' => $connectedTargetTaskKey,
    'connected_task_trigger_point' => 'PREVIOUS_STAGE_FINISHED',
], bx_uuid());
if (
    (string) ($connectedStage['task_stage_key'] ?? '') !== $createdStageKey
    || (string) ($connectedStage['connected_task_key'] ?? '') !== $connectedTargetTaskKey
    || (string) ($connectedStage['connected_task_trigger_point'] ?? '') !== 'PREVIOUS_STAGE_FINISHED'
) {
    throw new RuntimeException('project_task_stage connection helper did not read back the secondary task connection trigger.');
}
$clearedConnectedStage = bx_update_project_task_stage_connection([
    'task_stage_key' => $createdStageKey,
    'task_key' => $createdTaskKey,
    'connected_task_key' => '',
], bx_uuid());
if (
    (string) ($clearedConnectedStage['connected_task_key'] ?? '') !== ''
    || (string) ($clearedConnectedStage['connected_task_trigger_point'] ?? '') !== 'CURRENT_STAGE_FINISHED'
) {
    throw new RuntimeException('project_task_stage connection helper did not clear the connected task.');
}
$updatedDefaultStage = bx_update_project_task_stage([
    'task_stage_key' => (string) ($repairedDefaultStage['task_stage_key'] ?? ''),
    'task_key' => $createdTaskKey,
    'stage_label' => 'RENAMED',
    'stage_description' => 'Updated default stage information.',
    'stage_color_hex' => '#16A34A',
    'stage_status' => 'ACTIVE',
    'stage_ends_task' => '0',
], bx_uuid());
if (
    (string) ($updatedDefaultStage['stage_label'] ?? '') !== 'NEW'
    || (string) ($updatedDefaultStage['stage_description'] ?? '') !== 'Updated default stage information.'
    || (string) ($updatedDefaultStage['stage_color_hex'] ?? '') !== '#16A34A'
    || (string) ($updatedDefaultStage['stage_status'] ?? '') !== 'ACTIVE'
    || (int) ($updatedDefaultStage['stage_ends_task'] ?? 0) !== 0
    || (int) ($updatedDefaultStage['stage_can_run_manually'] ?? 0) !== 0
    || (int) ($updatedDefaultStage['stage_can_run_via_api'] ?? 0) !== 0
) {
    throw new RuntimeException('project_task_stage update helper did not lock the default NEW label.');
}
$blockedNewRename = false;
try {
    bx_update_project_task_stage([
        'task_stage_key' => $createdStageKey,
        'task_key' => $createdTaskKey,
        'stage_label' => 'NEW',
        'stage_description' => 'This rename must be rejected.',
        'stage_color_hex' => '#2563EB',
        'stage_status' => 'ACTIVE',
        'stage_ends_task' => '0',
    ], bx_uuid());
} catch (RuntimeException $error) {
    $blockedNewRename = true;
}
if (!$blockedNewRename) {
    throw new RuntimeException('project_task_stage update helper allowed a non-default stage to use NEW.');
}
$blockedDefaultStageDelete = false;
try {
    bx_delete_project_task_stage([
        'task_stage_key' => (string) ($repairedDefaultStage['task_stage_key'] ?? ''),
        'task_key' => $createdTaskKey,
    ], bx_uuid());
} catch (RuntimeException $error) {
    $blockedDefaultStageDelete = true;
}
if (!$blockedDefaultStageDelete) {
    throw new RuntimeException('project_task_stage delete helper allowed the default NEW stage to be deleted.');
}
$deletedStage = bx_delete_project_task_stage([
    'task_stage_key' => $createdStageKey,
    'task_key' => $createdTaskKey,
], bx_uuid());
if (
    (string) ($deletedStage['task_stage_key'] ?? '') !== $createdStageKey
    || (string) ($deletedStage['task_key'] ?? '') !== $createdTaskKey
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_stage_key = ?', [$createdStageKey]) !== 0
) {
    throw new RuntimeException('project_task_stage delete helper did not remove the selected non-default stage.');
}
$cascadeResponse = bx_create_project_task_stage_response([
    'task_key' => $createdTaskKey,
    'task_stage_key' => $createdStageTwoKey,
    'response_label' => 'Disposable cascade response',
    'response_description' => 'Created only for project_task cascade verification.',
    'response_color_hex' => '#00000000',
    'response_status' => 'ACTIVE',
], bx_uuid());
$cascadeResponseKey = (string) ($cascadeResponse['task_stage_response_key'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{20}$/', $cascadeResponseKey)) {
    throw new RuntimeException('project_task_stage_response helper did not create a cascade test response key.');
}
$updatedTask = bx_update_project_task([
    'task_key' => $createdTaskKey,
    'task_code' => 'PT-UPDATED',
    'task_title' => 'Disposable task builder row',
    'task_description' => 'Created only for project_task schema verification.',
    'task_group_keys' => $taskGroupKeys,
    'task_bypass_group_keys' => $taskBypassGroupKeys,
    'task_type' => 'PRIMARY',
    'task_status' => 'ACTIVE',
    'task_priority' => 'URGENT',
    'task_color_hex' => '#0F172A80',
    'task_can_run_manually' => '0',
    'task_can_run_via_api' => '1',
    'task_can_run_if_bed_vacant' => '0',
    'task_can_run_if_bed_occupied' => '1',
    'task_requires_bed_treatment' => '0',
    'task_requires_admission_source' => '1',
], bx_uuid());
if (
    (string) ($updatedTask['task_key'] ?? '') !== $createdTaskKey
    || (string) ($updatedTask['task_type'] ?? '') !== 'PRIMARY'
    || (string) ($updatedTask['task_status'] ?? '') !== 'ACTIVE'
    || (string) ($updatedTask['task_priority'] ?? '') !== 'URGENT'
    || (string) ($updatedTask['task_group_keys'] ?? '[]') !== json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES)
    || (string) ($updatedTask['task_bypass_group_keys'] ?? '[]') !== json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES)
    || (string) ($updatedTask['task_color_hex'] ?? '') !== '#0F172A80'
    || (int) ($updatedTask['task_can_run_manually'] ?? 0) !== 0
    || (int) ($updatedTask['task_can_run_via_api'] ?? 0) !== 1
    || (int) ($updatedTask['task_can_run_if_bed_vacant'] ?? 0) !== 0
    || (int) ($updatedTask['task_can_run_if_bed_occupied'] ?? 0) !== 1
    || (int) ($updatedTask['task_requires_bed_treatment'] ?? 0) !== 0
    || (int) ($updatedTask['task_requires_admission_source'] ?? 0) !== 1
) {
    throw new RuntimeException('project_task update helper did not read back the updated task values.');
}
$deletedTask = bx_delete_project_task(['task_key' => $createdTaskKey], bx_uuid());
$deletedConnectedTargetTask = bx_delete_project_task(['task_key' => $connectedTargetTaskKey], bx_uuid());
if (
    (string) ($deletedTask['task_key'] ?? '') !== $createdTaskKey
    || (string) ($deletedConnectedTargetTask['task_key'] ?? '') !== $connectedTargetTaskKey
    || (int) ($deletedTask['deleted_stage_count'] ?? 0) < 2
    || (int) ($deletedTask['deleted_response_count'] ?? 0) < 1
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$createdTaskKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$connectedTargetTaskKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_key = ?', [$createdTaskKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_key = ?', [$connectedTargetTaskKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$cascadeResponseKey]) !== 0
) {
    throw new RuntimeException('project_task delete helper did not remove the task and related stages/responses.');
}
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_task' AND record_key = ?", [$createdTaskKey]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_task' AND record_key = ?", [$connectedTargetTaskKey]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_task_stage' AND record_key IN (?, ?, ?, ?)", [
    $createdStageKey,
    $createdStageTwoKey,
    (string) ($defaultStage['task_stage_key'] ?? ''),
    (string) ($repairedDefaultStage['task_stage_key'] ?? ''),
]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_task_stage_response' AND record_key IN (?, ?)", [
    $createdResponseKey,
    $cascadeResponseKey,
]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_task_stage' AND record_key = ?", [$createdTaskKey]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_bed_treatment' AND record_key = ?", [$savedTreatmentKey]);
$db->Execute("DELETE FROM builder_audit_log WHERE module = 'project_bed_source' AND record_key = ?", [$savedSourceKey]);
$db->Execute('DELETE FROM project_bed_treatment WHERE bed_treatment_key = ?', [$savedTreatmentKey]);
$db->Execute('DELETE FROM project_bed_source WHERE bed_source_key = ?', [$savedSourceKey]);

$taskKey = bx_firebase_document_id();
if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
    throw new RuntimeException('Generated rollback test task_key is not Firestore-style.');
}
$stageKey = bx_firebase_document_id();
if (!preg_match('/^[A-Za-z0-9]{20}$/', $stageKey)) {
    throw new RuntimeException('Generated rollback test task_stage_key is not Firestore-style.');
}
$responseKey = bx_firebase_document_id();
if (!preg_match('/^[A-Za-z0-9]{20}$/', $responseKey)) {
    throw new RuntimeException('Generated rollback test task_stage_response_key is not Firestore-style.');
}
$userKey = bx_uuid();
$token = substr($taskKey, 0, 8);
$transactionStarted = false;

try {
    if ($db->BeginTrans() === false) {
        throw new RuntimeException('project_task test transaction could not start.');
    }
    $transactionStarted = true;

    $saved = $db->Execute(
        'INSERT INTO project_task (task_key, task_code, task_title, task_description, task_group_keys, task_bypass_group_keys, task_type, task_status, task_priority, task_color_hex, task_can_run_manually, task_can_run_via_api, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_canvas_x, task_canvas_y, task_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $taskKey,
            'PT-' . $token,
            'Disposable task builder row',
            'Created only for project_task schema verification.',
            json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES),
            json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES),
            'PRIMARY',
            'ACTIVE',
            'HIGH',
            '#7C3AED80',
            1,
            1,
            1,
            0,
            64,
            96,
            10,
            $userKey,
            $userKey,
        ]
    );
    if ($saved === false) {
        throw new RuntimeException('project_task insert failed: ' . $db->ErrorMsg());
    }

    $stageSaved = $db->Execute(
        'INSERT INTO project_task_stage (task_stage_key, task_key, stage_label, stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, stage_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $stageKey,
            $taskKey,
            'Rollback verification stage',
            'Created only inside the rollback verification transaction.',
            '#BE123C80',
            'ACTIVE',
            1,
            0,
            0,
            2,
            $userKey,
            $userKey,
        ]
    );
    if ($stageSaved === false) {
        throw new RuntimeException('project_task_stage insert failed: ' . $db->ErrorMsg());
    }

    $responseSaved = $db->Execute(
        'INSERT INTO project_task_stage_response (task_stage_response_key, task_key, task_stage_key, response_label, response_description, response_color_hex, response_status, response_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $responseKey,
            $taskKey,
            $stageKey,
            'Rollback verification response',
            'Created only inside the rollback verification transaction.',
            '#14B8A680',
            'ACTIVE',
            1,
            $userKey,
            $userKey,
        ]
    );
    if ($responseSaved === false) {
        throw new RuntimeException('project_task_stage_response insert failed: ' . $db->ErrorMsg());
    }

    $readBack = $db->GetRow(
        "SELECT task_code, task_title, COALESCE(task_group_keys, '[]') AS task_group_keys, COALESCE(task_bypass_group_keys, '[]') AS task_bypass_group_keys, task_type, task_status, task_priority, task_color_hex, task_can_run_manually, task_can_run_via_api, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_canvas_x, task_canvas_y, task_sort_order FROM project_task WHERE task_key = ? LIMIT 1",
        [$taskKey]
    );
    if (
        !is_array($readBack)
        || (string) ($readBack['task_code'] ?? '') !== 'PT-' . $token
        || (string) ($readBack['task_title'] ?? '') !== 'Disposable task builder row'
        || (string) ($readBack['task_group_keys'] ?? '[]') !== json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES)
        || (string) ($readBack['task_bypass_group_keys'] ?? '[]') !== json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES)
        || (string) ($readBack['task_type'] ?? '') !== 'PRIMARY'
        || (string) ($readBack['task_status'] ?? '') !== 'ACTIVE'
        || (string) ($readBack['task_priority'] ?? '') !== 'HIGH'
        || (string) ($readBack['task_color_hex'] ?? '') !== '#7C3AED80'
        || (int) ($readBack['task_can_run_manually'] ?? 0) !== 1
        || (int) ($readBack['task_can_run_via_api'] ?? 0) !== 1
        || (int) ($readBack['task_can_run_if_bed_vacant'] ?? 0) !== 1
        || (int) ($readBack['task_can_run_if_bed_occupied'] ?? 0) !== 0
        || (int) ($readBack['task_canvas_x'] ?? 0) !== 64
        || (int) ($readBack['task_canvas_y'] ?? 0) !== 96
        || (int) ($readBack['task_sort_order'] ?? 0) !== 10
    ) {
        throw new RuntimeException('project_task read-back did not match the written task values.');
    }

    $stageReadBack = $db->GetRow(
        'SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, \'\') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, connected_task_trigger_point, stage_sort_order FROM project_task_stage WHERE task_stage_key = ? LIMIT 1',
        [$stageKey]
    );
    if (
        !is_array($stageReadBack)
        || (string) ($stageReadBack['task_stage_key'] ?? '') !== $stageKey
        || (string) ($stageReadBack['task_key'] ?? '') !== $taskKey
        || (string) ($stageReadBack['stage_label'] ?? '') !== 'Rollback verification stage'
        || (string) ($stageReadBack['stage_description'] ?? '') !== 'Created only inside the rollback verification transaction.'
        || (string) ($stageReadBack['stage_color_hex'] ?? '') !== '#BE123C80'
        || (string) ($stageReadBack['stage_status'] ?? '') !== 'ACTIVE'
        || (int) ($stageReadBack['stage_ends_task'] ?? 0) !== 1
        || (int) ($stageReadBack['stage_can_run_manually'] ?? 0) !== 0
        || (int) ($stageReadBack['stage_can_run_via_api'] ?? 0) !== 0
        || (string) ($stageReadBack['connected_task_trigger_point'] ?? '') !== 'CURRENT_STAGE_FINISHED'
        || (int) ($stageReadBack['stage_sort_order'] ?? 0) !== 2
    ) {
        throw new RuntimeException('project_task_stage read-back did not match the written stage values.');
    }

    $responseReadBack = $db->GetRow(
        'SELECT task_stage_response_key, task_key, task_stage_key, response_label, COALESCE(response_description, \'\') AS response_description, response_color_hex, response_status, response_sort_order FROM project_task_stage_response WHERE task_stage_response_key = ? LIMIT 1',
        [$responseKey]
    );
    if (
        !is_array($responseReadBack)
        || (string) ($responseReadBack['task_stage_response_key'] ?? '') !== $responseKey
        || (string) ($responseReadBack['task_key'] ?? '') !== $taskKey
        || (string) ($responseReadBack['task_stage_key'] ?? '') !== $stageKey
        || (string) ($responseReadBack['response_label'] ?? '') !== 'Rollback verification response'
        || (string) ($responseReadBack['response_description'] ?? '') !== 'Created only inside the rollback verification transaction.'
        || (string) ($responseReadBack['response_color_hex'] ?? '') !== '#14B8A680'
        || (string) ($responseReadBack['response_status'] ?? '') !== 'ACTIVE'
        || (int) ($responseReadBack['response_sort_order'] ?? 0) !== 1
    ) {
        throw new RuntimeException('project_task_stage_response read-back did not match the written response values.');
    }

    if ($db->RollbackTrans() === false) {
        throw new RuntimeException('project_task test rollback failed.');
    }
    $transactionStarted = false;
} catch (Throwable $error) {
    if ($transactionStarted) {
        $db->RollbackTrans();
    }
    throw $error;
}

if ((int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$taskKey]) !== 0) {
    throw new RuntimeException('project_task test rollback left a disposable row behind.');
}
if ((int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_stage_key = ?', [$stageKey]) !== 0) {
    throw new RuntimeException('project_task_stage test rollback left a disposable row behind.');
}
if ((int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$responseKey]) !== 0) {
    throw new RuntimeException('project_task_stage_response test rollback left a disposable row behind.');
}

echo json_encode([
    'project_task_table_created' => true,
    'project_task_stage_table_created' => true,
    'project_task_stage_response_table_created' => true,
    'project_bed_treatment_table_created' => true,
    'project_bed_source_table_created' => true,
    'columns_verified' => true,
    'stage_columns_verified' => true,
    'response_columns_verified' => true,
    'bed_treatment_columns_verified' => true,
    'bed_source_columns_verified' => true,
    'indexes_verified' => true,
    'stage_indexes_verified' => true,
    'response_indexes_verified' => true,
    'bed_treatment_indexes_verified' => true,
    'bed_source_indexes_verified' => true,
    'firebase_document_id_task_key_verified' => true,
    'firebase_document_id_task_stage_key_verified' => true,
    'firebase_document_id_task_stage_response_key_verified' => true,
    'task_type_verified' => true,
    'task_bypass_group_keys_verified' => true,
    'task_color_hex_verified' => true,
    'task_run_modes_verified' => true,
    'task_bed_state_run_modes_verified' => true,
    'task_required_submission_selections_verified' => true,
    'bed_treatment_crud_helper_verified' => true,
    'bed_source_crud_helper_verified' => true,
    'stage_color_hex_verified' => true,
    'response_color_hex_verified' => true,
    'stage_ends_task_verified' => true,
    'stage_run_modes_disabled_for_stages_verified' => true,
    'default_stage_auto_create_verified' => true,
    'default_stage_repair_verified' => true,
    'task_canvas_position_verified' => true,
    'task_inactive_default_verified' => true,
    'create_helper_verified' => true,
    'stage_create_helper_verified' => true,
    'stage_update_helper_verified' => true,
    'response_create_helper_verified' => true,
    'response_update_helper_verified' => true,
    'response_delete_helper_verified' => true,
    'response_sort_order_verified' => true,
    'stage_connection_helper_verified' => true,
    'stage_connection_trigger_point_verified' => true,
    'stage_default_label_lock_verified' => true,
    'stage_delete_helper_verified' => true,
    'stage_sort_order_verified' => true,
    'update_helper_verified' => true,
    'delete_helper_verified' => true,
    'response_cascade_delete_verified' => true,
    'canvas_position_helper_verified' => true,
    'payload_helper_verified' => true,
    'stage_payload_helper_verified' => true,
    'response_payload_helper_verified' => true,
    'transaction_read_back_verified' => true,
    'stage_transaction_read_back_verified' => true,
    'response_transaction_read_back_verified' => true,
    'rollback_verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
