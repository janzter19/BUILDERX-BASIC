<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

$db = bx_db();
$phaseManagerSource = (string) file_get_contents(dirname(__DIR__) . '/phases/index.php');
$updatePhaseStart = strpos($phaseManagerSource, "if (\$action === 'update_phase')");
$deletePhaseStart = strpos($phaseManagerSource, "if (\$action === 'delete_phase')");
if ($updatePhaseStart === false || $deletePhaseStart === false || $deletePhaseStart <= $updatePhaseStart) {
    throw new RuntimeException('The Phase update route could not be isolated for transaction verification.');
}
$updatePhaseSource = substr($phaseManagerSource, $updatePhaseStart, $deletePhaseStart - $updatePhaseStart);
foreach (['BeginTrans()', 'FOR UPDATE', "bx_audit('UPDATE', 'builder_phase'", 'direct read-back', 'CommitTrans()', 'RollbackTrans()'] as $requiredMarker) {
    if (!str_contains($updatePhaseSource, $requiredMarker)) {
        throw new RuntimeException('The Phase update route is missing persistence marker: ' . $requiredMarker . '.');
    }
}
$requiredColumns = [
    'builder_phase' => ['phase_key', 'phase_number', 'phase_code', 'phase_title', 'phase_summary', 'phase_status', 'phase_sort_order'],
    'builder_phase_task' => ['task_key', 'phase_key', 'task_code', 'task_title', 'task_details', 'task_reference', 'task_scope', 'task_acceptance_checklist', 'task_exclusions', 'task_notes', 'is_completed', 'task_status', 'task_sort_order'],
    'builder_phase_task_checklist' => ['checklist_key', 'task_key', 'checklist_text', 'is_done', 'checklist_status', 'checklist_sort_order'],
    'phase_builder_todo_execution_logs' => ['execution_key', 'draft_key', 'task_id', 'subtask_id', 'todo_id', 'context_json', 'source_checkpoint_json', 'result_json', 'status', 'rollback_status', 'rollback_source_checkpoint_json', 'rollback_result_json'],
];

foreach ($requiredColumns as $table => $columns) {
    $actual = $db->GetCol(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
        [BUILDERX_DB_NAME, $table]
    );
    if (!is_array($actual)) {
        throw new RuntimeException('Phase Manager schema inspection failed for ' . $table . '.');
    }
    foreach ($columns as $column) {
        if (!in_array($column, $actual, true)) {
            throw new RuntimeException('Phase Manager schema is missing ' . $table . '.' . $column . '.');
        }
    }
}

foreach (['checklist_title', 'is_completed'] as $legacyColumn) {
    $exists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'builder_phase_task_checklist', $legacyColumn]
    );
    if ($exists !== 0) {
        throw new RuntimeException('Legacy checklist column remains active: ' . $legacyColumn . '.');
    }
}

$phaseKey = bx_uuid();
$taskKey = bx_uuid();
$checklistKey = bx_uuid();
$token = substr(str_replace('-', '', $phaseKey), 0, 8);
$transactionStarted = false;

try {
    if ($db->BeginTrans() === false) {
        throw new RuntimeException('Phase Manager schema test transaction could not start.');
    }
    $transactionStarted = true;
    $phaseNumber = (int) $db->GetOne('SELECT COALESCE(MAX(phase_number), 0) + 1 FROM builder_phase');
    $phaseSort = (int) $db->GetOne('SELECT COALESCE(MAX(phase_sort_order), 0) + 1 FROM builder_phase');
    if ($db->Execute(
        'INSERT INTO builder_phase (phase_key, phase_number, phase_code, phase_title, phase_summary, phase_status, phase_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$phaseKey, $phaseNumber, 'TS-' . $token, 'Disposable schema verification', 'Rolled back by the test.', 'In Progress', $phaseSort]
    ) === false) {
        throw new RuntimeException('Phase Manager phase insert failed: ' . $db->ErrorMsg());
    }
    if ($db->Execute(
        'INSERT INTO builder_phase_task (task_key, phase_key, task_code, task_title, task_details, task_reference, task_scope, task_acceptance_checklist, task_exclusions, task_notes, is_completed, task_status, task_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 1)',
        [$taskKey, $phaseKey, 'TT-' . $token, 'Disposable task', 'Schema test.', 'Reference', 'Current project only.', 'Direct read-back.', 'No product mutation.', 'Rolled back.', 'ACTIVE']
    ) === false) {
        throw new RuntimeException('Phase Manager task insert failed: ' . $db->ErrorMsg());
    }
    if ($db->Execute(
        'INSERT INTO builder_phase_task_checklist (checklist_key, task_key, checklist_text, is_done, checklist_status, checklist_sort_order) VALUES (?, ?, ?, 0, ?, 1)',
        [$checklistKey, $taskKey, 'Verify the reconciled checklist schema.', 'Pending']
    ) === false) {
        throw new RuntimeException('Phase Manager checklist insert failed: ' . $db->ErrorMsg());
    }
    if ($db->Execute(
        "UPDATE builder_phase_task_checklist SET is_done = 1, checklist_status = 'Completed', updated_at = CURRENT_TIMESTAMP WHERE checklist_key = ? AND task_key = ?",
        [$checklistKey, $taskKey]
    ) === false) {
        throw new RuntimeException('Phase Manager checklist update failed: ' . $db->ErrorMsg());
    }
    bx_audit('VERIFY', 'builder_phase_task_checklist', $checklistKey, [
        'phase_key' => $phaseKey,
        'task_key' => $taskKey,
        'is_done' => true,
    ], 'Disposable Phase Manager schema transaction verification.');
    $readBack = $db->GetRow(
        'SELECT c.checklist_text, c.is_done, c.checklist_status, c.checklist_sort_order, t.task_scope, t.task_acceptance_checklist, p.phase_status FROM builder_phase_task_checklist c INNER JOIN builder_phase_task t ON t.task_key = c.task_key INNER JOIN builder_phase p ON p.phase_key = t.phase_key WHERE c.checklist_key = ? LIMIT 1',
        [$checklistKey]
    );
    if (
        !is_array($readBack)
        || (string) ($readBack['checklist_text'] ?? '') !== 'Verify the reconciled checklist schema.'
        || (int) ($readBack['is_done'] ?? 0) !== 1
        || (string) ($readBack['checklist_status'] ?? '') !== 'Completed'
        || (int) ($readBack['checklist_sort_order'] ?? 0) !== 1
        || (string) ($readBack['task_scope'] ?? '') !== 'Current project only.'
        || (string) ($readBack['task_acceptance_checklist'] ?? '') !== 'Direct read-back.'
        || (string) ($readBack['phase_status'] ?? '') !== 'In Progress'
    ) {
        throw new RuntimeException('Phase Manager schema transaction read-back did not match the written values.');
    }
    if ($db->RollbackTrans() === false) {
        throw new RuntimeException('Phase Manager schema test rollback failed.');
    }
    $transactionStarted = false;
} catch (Throwable $error) {
    if ($transactionStarted) {
        $db->RollbackTrans();
    }
    throw $error;
}

if (
    (int) $db->GetOne('SELECT COUNT(*) FROM builder_phase WHERE phase_key = ?', [$phaseKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM builder_phase_task WHERE task_key = ?', [$taskKey]) !== 0
    || (int) $db->GetOne('SELECT COUNT(*) FROM builder_phase_task_checklist WHERE checklist_key = ?', [$checklistKey]) !== 0
    || (int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'builder_phase_task_checklist' AND record_key = ?", [$checklistKey]) !== 0
) {
    throw new RuntimeException('Phase Manager schema test rollback left disposable records behind.');
}

echo json_encode([
    'phase_schema_verified' => true,
    'task_extended_fields_verified' => true,
    'checklist_schema_reconciled' => true,
    'source_checkpoint_columns_verified' => true,
    'phase_update_transaction_contract_verified' => true,
    'transaction_read_back_verified' => true,
    'rollback_verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
