<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\BuilderXAiBridgeAdapter;
use BuilderX\AI\BuilderXAiBridgeTransport;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiRunStore;
use BuilderX\AI\PhaseAiSourceCheckpoint;
use BuilderX\AI\PhaseAiWorkflowContract;

final class SharedWorkflowBridgeTransport implements BuilderXAiBridgeTransport
{
    public int $handoffCount = 0;
    public string $workspace;

    public function __construct(string $workspace)
    {
        $this->workspace = $workspace;
    }

    public function request(string $method, string $path, ?array $payload = null, int $timeoutSeconds = 30): array
    {
        if (str_starts_with($path, '/health?')) {
            return [
                'ok' => true,
                'bridge' => 'BuilderX',
                'workspace' => $this->workspace,
                'ready_to_send' => true,
                'active_thread_ready' => true,
                'active_thread_busy' => false,
                'active_thread_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'model_key' => 'codex-shared-workflow-test',
            ];
        }
        if (in_array($path, ['/handoff', '/handoff-result'], true)) {
            $this->handoffCount++;
            $requestId = (string) ($payload['job_key'] ?? '');
            return [
                'ok' => true,
                'bridge' => 'BuilderX',
                'delivery' => [
                    'request_id' => $requestId,
                    'acknowledged' => true,
                    'state' => 'submitted',
                    'thread_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'storage' => 'mysql',
                ],
            ];
        }
        throw new RuntimeException('Unexpected shared-workflow Bridge request: ' . $path);
    }

    public function stream(string $path, callable $onChunk, int $timeoutSeconds = 3600): void
    {
        $onChunk("event: completed\ndata: {}\n\n");
    }
}

$db = bx_db();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) throw new RuntimeException('The shared-workflow project root is unavailable.');
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$contextId = bx_uuid();
$contextPayload = ['schema_version' => 'builderx.shared-workflow-context.v1', 'purpose' => 'Verify every shared Planning and Coding workflow.'];
$contextMaterialized = (new PhaseAiContextStore())->save($contextId, $projectIdentity, $contextPayload);
$context = [
    'context_id' => $contextId,
    'context_ref' => $contextMaterialized['context_ref'],
    'bytes' => $contextMaterialized['bytes'],
    'sha256' => $contextMaterialized['sha256'],
];
$testUserKey = bx_uuid();
$store = new PhaseAiRunStore($testUserKey);
$transport = new SharedWorkflowBridgeTransport($projectRoot);
$adapter = new BuilderXAiBridgeAdapter($projectRoot, $transport);
$orchestrator = new PhaseAiOrchestrator($projectRoot, $store, $adapter);
$runKeys = [];
$executionKeys = [];
$sourceCheckpoints = [];
$backupRootExisted = is_dir($projectRoot . '/storage/backups');
$sourceCheckpointRootExisted = is_dir($projectRoot . '/storage/backups/phase-ai-source');

$start = static function (string $engine, string $workflow, string $route, string $semanticKey, array $sourceHashes = []) use ($db, $store, $testUserKey, $projectIdentity, $context, &$runKeys, &$executionKeys): array {
    $draftKey = bx_uuid();
    $executionKey = in_array($workflow, ['todo_execution', 'todo_rollback'], true) ? bx_uuid() : null;
    $request = [
        'schema_version' => 'builderx.ai-run.request.v1',
        'route_key' => $route,
        'workflow_key' => $workflow,
        'draft_key' => $draftKey,
        'phase_key' => null,
        'task_id' => str_starts_with($workflow, 'todo_') ? 'TASK-001' : null,
        'subtask_id' => str_starts_with($workflow, 'todo_') ? 'SUBTASK-001' : null,
        'todo_id' => str_starts_with($workflow, 'todo_') ? 'TODO-001' : null,
        'execution_key' => $executionKey,
        'semantic_chunk_key' => $semanticKey,
        'source_hashes' => $sourceHashes,
        'context_checkpoint' => $context,
        'git_update_approved' => false,
    ];
    if ($executionKey !== null) {
        $status = $workflow === 'todo_execution' ? 'RUNNING' : 'COMPLETED';
        $rollbackStatus = $workflow === 'todo_rollback' ? 'RUNNING' : 'NOT_REQUESTED';
        $contextJson = json_encode(['execution_key' => $executionKey, 'test' => true], JSON_THROW_ON_ERROR);
        $saved = $db->Execute(
            'INSERT INTO phase_builder_todo_execution_logs (execution_key, draft_key, phase_id, task_id, subtask_id, todo_id, context_json, status, rollback_status, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$executionKey, $draftKey, 'PHASE-001', 'TASK-001', 'SUBTASK-001', 'TODO-001', $contextJson, $status, $rollbackStatus, $testUserKey, $testUserKey]
        );
        if ($saved === false) throw new RuntimeException('The shared-workflow execution context fixture could not be created.');
        $executionKeys[] = $executionKey;
    }
    $run = $store->start($engine, $workflow, $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    $runKeys[] = (string) $run['run_key'];
    return $run;
};

$completeContext = static function (array $run) use ($orchestrator, $projectIdentity, $context): array {
    $runKey = (string) $run['run_key'];
    $orchestrator->begin($runKey, $projectIdentity, 'context', ['operation' => 'shared_workflow_test']);
    return $orchestrator->complete($runKey, $projectIdentity, 'context', $context);
};

$completeStage = static function (array $run, string $stageKey, array $result) use ($orchestrator, $projectIdentity): array {
    $runKey = (string) $run['run_key'];
    $orchestrator->begin($runKey, $projectIdentity, $stageKey, ['stage' => $stageKey]);
    return $orchestrator->complete($runKey, $projectIdentity, $stageKey, $result);
};

try {
    $requirementsHash = hash('sha256', 'requirements');
    $architecture = $start('PLANNING', 'system_architecture', 'phases:builder', 'system-boundaries', ['requirements_hash' => $requirementsHash]);
    $architecture = $completeContext($architecture);
    $architectureDispatch = $orchestrator->dispatch((string) $architecture['run_key'], $projectIdentity, 'analysis', ['context_id' => $context['context_id']], 'Return the System Architecture contract.', true);
    $architectureDispatchAfterReload = $orchestrator->dispatch((string) $architecture['run_key'], $projectIdentity, 'analysis', ['context_id' => $context['context_id']], 'Return the System Architecture contract.', true);
    if (
        ($architectureDispatchAfterReload['delivery']['resumed'] ?? false) !== true
        || ($architectureDispatchAfterReload['delivery']['provider_request_id'] ?? '') !== ($architectureDispatch['delivery']['provider_request_id'] ?? '')
        || $transport->handoffCount !== 1
    ) {
        throw new RuntimeException('A reloaded persistent stage dispatched a duplicate Bridge request.');
    }
    $architectureResult = [
        'schemaVersion' => 'builderx.system-architecture.v1',
        'contractType' => 'builderx.system-architecture',
        'source' => ['draftKey' => $architecture['draft_key'], 'requirementsHash' => $requirementsHash],
        'projectBlueprint' => ['boundaries' => [], 'dataFlow' => []],
        'systemInventory' => ['surfaces' => []],
        'fileManifest' => [],
        'implementationChecklist' => [],
        'assumptionsOrRisks' => [],
        'orchestration' => ['mode' => 'single_chat'],
    ];
    $architecture = $orchestrator->complete((string) $architecture['run_key'], $projectIdentity, 'analysis', $architectureResult);
    $architecture = $completeStage($architecture, 'integration_review', [
        'schemaVersion' => 'builderx.ai-integration-review.v1',
        'workflowKey' => 'system_architecture',
        'artifactHash' => PhaseAiWorkflowContract::hash($architectureResult),
        'status' => 'approved',
        'findings' => [],
    ]);
    $architecture = $completeStage($architecture, 'persistence', [
        'schemaVersion' => 'builderx.ai-persistence.v1',
        'workflowKey' => 'system_architecture',
        'status' => 'created',
        'artifactHash' => PhaseAiWorkflowContract::hash($architectureResult),
        'recordKey' => bx_uuid(),
        'readBackVerified' => true,
    ]);

    $architectureHash = hash('sha256', 'architecture');
    $ui = $start('PLANNING', 'ui_ux_design', 'phases:builder', 'product-surfaces', ['architecture_hash' => $architectureHash]);
    $ui = $completeContext($ui);
    $uiResult = [
        'schemaVersion' => 'builderx.ui-ux-design.v1',
        'contractType' => 'builderx.ui-ux-design',
        'source' => ['draftKey' => $ui['draft_key'], 'architectureHash' => $architectureHash],
        'designBlueprint' => ['theme' => 'BuilderX'],
        'screens' => [['screenId' => 'SCREEN-001', 'name' => 'Dashboard', 'purpose' => 'Verified dashboard.', 'renderSpec' => ['sections' => []]]],
        'flowChart' => [['from' => 'SCREEN-001', 'to' => 'SCREEN-001']],
        'responsiveRules' => [],
        'accessibilityRules' => [],
        'orchestration' => ['mode' => 'single_chat'],
    ];
    $ui = $completeStage($ui, 'analysis', $uiResult);
    $ui = $completeStage($ui, 'integration_review', ['schemaVersion' => 'builderx.ai-integration-review.v1', 'workflowKey' => 'ui_ux_design', 'artifactHash' => PhaseAiWorkflowContract::hash($uiResult), 'status' => 'approved', 'findings' => []]);
    $ui = $completeStage($ui, 'persistence', ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => 'ui_ux_design', 'status' => 'updated', 'artifactHash' => PhaseAiWorkflowContract::hash($uiResult), 'recordKey' => bx_uuid(), 'readBackVerified' => true]);

    $roadmap = $start('PLANNING', 'execution_roadmap', 'phases:builder', 'modules', ['architecture_hash' => $architectureHash]);
    $roadmap = $completeContext($roadmap);
    $roadmapResult = [
        'schemaVersion' => 'builderx.execution-roadmap.stage.modules.v1',
        'contractType' => 'builderx.execution-roadmap-stage',
        'stage' => 'modules',
        'source' => ['draftKey' => $roadmap['draft_key'], 'architectureHash' => $architectureHash],
        'modules' => [['moduleId' => 'MOD-001', 'moduleTitle' => 'Core']],
    ];
    $roadmap = $completeStage($roadmap, 'analysis', $roadmapResult);
    $roadmap = $completeStage($roadmap, 'integration_review', ['schemaVersion' => 'builderx.ai-integration-review.v1', 'workflowKey' => 'execution_roadmap', 'artifactHash' => PhaseAiWorkflowContract::hash($roadmapResult), 'status' => 'approved', 'findings' => []]);
    $roadmap = $completeStage($roadmap, 'persistence', ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => 'execution_roadmap', 'status' => 'updated', 'artifactHash' => PhaseAiWorkflowContract::hash($roadmapResult), 'recordKey' => bx_uuid(), 'readBackVerified' => true]);

    $consolidation = $start('PLANNING', 'todo_consolidation', 'phases', 'todo:todo-001');
    $consolidation = $completeContext($consolidation);
    $consolidationResult = ['summary' => 'Summary', 'suggestion' => 'Suggestion', 'suggestedTodoTitle' => 'Verified todo', 'suggestedTodoDescription' => 'Verified description', 'risks' => [], 'confidence' => 0.9];
    $consolidation = $completeStage($consolidation, 'analysis', $consolidationResult);
    $consolidation = $completeStage($consolidation, 'persistence', ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => 'todo_consolidation', 'status' => 'updated', 'artifactHash' => PhaseAiWorkflowContract::hash($consolidationResult), 'recordKey' => bx_uuid(), 'readBackVerified' => true]);

    $diagnostic = $start('PLANNING', 'bridge_diagnostic', 'phases:builder', 'single-chat');
    $diagnostic = $completeContext($diagnostic);
    $diagnosticResult = ['scope_level' => 'project', 'execution_mode' => 'single_chat_multi_specialist_orchestration', 'coordinator_decision' => ['selected' => ['requirements']], 'specialist_tasks' => [['specialist' => 'requirements']], 'specialist_results' => [['status' => 'simulated']], 'reconciliation' => ['conflicts' => []], 'final_summary' => 'One visible chat reconciled the bounded perspectives.'];
    $diagnostic = $completeStage($diagnostic, 'analysis', $diagnosticResult);
    $diagnostic = $completeStage($diagnostic, 'persistence', ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => 'bridge_diagnostic', 'status' => 'completed', 'artifactHash' => PhaseAiWorkflowContract::hash($diagnosticResult), 'recordKey' => bx_uuid(), 'readBackVerified' => true]);

    $codingResults = [];
    foreach (['todo_execution', 'todo_rollback'] as $workflowKey) {
        $coding = $start('CODING', $workflowKey, 'phases', $workflowKey === 'todo_execution' ? 'todo:todo-001' : 'rollback:test');
        $coding = $completeContext($coding);
        foreach (['inspection', 'plan'] as $stageKey) {
            if ($stageKey === 'inspection' && $workflowKey === 'todo_execution') {
                $dispatch = $orchestrator->dispatch((string) $coding['run_key'], $projectIdentity, $stageKey, ['context_id' => $context['context_id']], 'Return the bounded Coding checkpoint.', true);
                if (($dispatch['delivery']['provider_key'] ?? '') !== BuilderXAiBridgeAdapter::PROVIDER_KEY) throw new RuntimeException('The Coding workflow did not use the shared Bridge adapter.');
                $coding = $orchestrator->complete((string) $coding['run_key'], $projectIdentity, $stageKey, ['schemaVersion' => 'builderx.coding-checkpoint.v1', 'workflowKey' => $workflowKey, 'stage' => $stageKey, 'status' => 'completed', 'evidence' => ['Current project inspected.']]);
                continue;
            }
            $coding = $completeStage($coding, $stageKey, ['schemaVersion' => 'builderx.coding-checkpoint.v1', 'workflowKey' => $workflowKey, 'stage' => $stageKey, 'status' => 'completed', 'evidence' => ['Bounded checkpoint verified.']]);
        }
        $executionKey = (string) ($coding['request']['execution_key'] ?? '');
        $checkpointManager = new PhaseAiSourceCheckpoint($projectRoot);
        $serverSourceCheckpoint = $checkpointManager->create($executionKey, (string) $coding['run_key'], $workflowKey, $projectIdentity);
        $sourceCheckpoints[] = ['metadata' => $serverSourceCheckpoint, 'execution_key' => $executionKey, 'run_key' => (string) $coding['run_key'], 'workflow_key' => $workflowKey];
        $coding = $store->bindServerSourceCheckpoint((string) $coding['run_key'], $projectIdentity, $executionKey, $serverSourceCheckpoint);
        $checkpointColumn = $workflowKey === 'todo_execution' ? 'source_checkpoint_json' : 'rollback_source_checkpoint_json';
        $savedCheckpointJson = (string) $db->GetOne("SELECT {$checkpointColumn} FROM phase_builder_todo_execution_logs WHERE execution_key = ?", [$executionKey]);
        $savedCheckpoint = json_decode($savedCheckpointJson, true);
        if (
            ($coding['request']['server_source_checkpoint'] ?? null) !== $serverSourceCheckpoint
            || $savedCheckpoint !== $serverSourceCheckpoint
            || (int) $db->GetOne("SELECT COUNT(*) FROM phase_builder_ai_run_event WHERE run_key = ? AND event_type = 'SERVER_SOURCE_CHECKPOINT_BOUND'", [(string) $coding['run_key']]) !== 1
        ) {
            throw new RuntimeException('The server source checkpoint was not persisted and read back across both bindings.');
        }
        $implementation = ['status' => 'completed', 'summary' => 'Bounded implementation verified.', 'recoveryCheckpoints' => [PhaseAiSourceCheckpoint::modelEvidence($serverSourceCheckpoint)], 'changedFiles' => ['app/example.php'], 'databaseChanges' => [], 'androidChanges' => [], 'tests' => ['php -l passed'], 'blockers' => [], 'nextSteps' => []];
        $coding = $completeStage($coding, 'implementation', $implementation);
        foreach (['verification', 'evidence'] as $stageKey) {
            $coding = $completeStage($coding, $stageKey, ['schemaVersion' => 'builderx.coding-checkpoint.v1', 'workflowKey' => $workflowKey, 'stage' => $stageKey, 'status' => 'completed', 'evidence' => ['Focused verification passed.']]);
        }
        if ($workflowKey === 'todo_execution') {
            $coding = $completeStage($coding, 'git_update', ['schemaVersion' => 'builderx.coding-checkpoint.v1', 'workflowKey' => $workflowKey, 'stage' => 'git_update', 'status' => 'skipped', 'evidence' => ['No Git update approval supplied.'], 'remoteOperation' => 'none']);
        }
        $coding = $completeStage($coding, 'persistence', ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => $workflowKey, 'status' => $workflowKey === 'todo_rollback' ? 'rolled_back' : 'completed', 'artifactHash' => PhaseAiWorkflowContract::hash($implementation), 'recordKey' => bx_uuid(), 'readBackVerified' => true]);
        $codingResults[$workflowKey] = $coding['status'];
    }

    $planningProvider = (string) ($architecture['provider_key'] ?? '');
    $codingRun = $store->read($runKeys[count($runKeys) - 2], $projectIdentity);
    $semanticEvent = $db->GetOne("SELECT chunk_key FROM phase_builder_ai_run_event WHERE run_key = ? AND stage_key = 'analysis' AND status = 'SUCCEEDED' ORDER BY x_id DESC LIMIT 1", [(string) $architecture['run_key']]);
    if ($planningProvider !== BuilderXAiBridgeAdapter::PROVIDER_KEY || ($codingRun['provider_key'] ?? '') !== BuilderXAiBridgeAdapter::PROVIDER_KEY || $semanticEvent !== 'analysis-system-boundaries') {
        throw new RuntimeException('The Planning/Coding shared adapter or semantic event identity read-back failed.');
    }
    $missingRecoveryCheckpointRejected = false;
    try {
        PhaseAiWorkflowContract::validatePersistedExecutionEvidence([
            'status' => 'completed',
            'summary' => 'Invalid completed change without a checkpoint.',
            'recoveryCheckpoints' => [],
            'changedFiles' => ['app/unsafe.php'],
            'databaseChanges' => [],
            'androidChanges' => [],
            'tests' => ['php -l passed'],
            'blockers' => [],
            'nextSteps' => [],
        ]);
    } catch (RuntimeException) {
        $missingRecoveryCheckpointRejected = true;
    }
    if (!$missingRecoveryCheckpointRejected) {
        throw new RuntimeException('Completed Coding changes were accepted without the server source checkpoint.');
    }
    $forgedServerCheckpointRejected = false;
    try {
        $forgedEvidence = PhaseAiSourceCheckpoint::modelEvidence($serverSourceCheckpoint);
        $forgedEvidence['manifestSha256'] = str_repeat('0', 64);
        PhaseAiWorkflowContract::validatePersistedExecutionEvidence([
            'status' => 'completed',
            'summary' => 'Forged source checkpoint evidence.',
            'recoveryCheckpoints' => [$forgedEvidence],
            'changedFiles' => ['app/unsafe.php'],
            'databaseChanges' => [],
            'androidChanges' => [],
            'tests' => ['php -l passed'],
            'blockers' => [],
            'nextSteps' => [],
        ], $serverSourceCheckpoint);
    } catch (RuntimeException) {
        $forgedServerCheckpointRejected = true;
    }
    if (!$forgedServerCheckpointRejected) {
        throw new RuntimeException('A forged server source checkpoint hash was accepted.');
    }
    $unprotectedDatabaseCompletionRejected = false;
    try {
        PhaseAiWorkflowContract::validatePersistedExecutionEvidence([
            'status' => 'completed',
            'summary' => 'Invalid completed database write.',
            'recoveryCheckpoints' => [PhaseAiSourceCheckpoint::modelEvidence($serverSourceCheckpoint)],
            'changedFiles' => [],
            'databaseChanges' => ['ALTER TABLE unsafe'],
            'androidChanges' => [],
            'tests' => ['database read-back passed'],
            'blockers' => [],
            'nextSteps' => [],
        ], $serverSourceCheckpoint);
    } catch (RuntimeException) {
        $unprotectedDatabaseCompletionRejected = true;
    }
    if (!$unprotectedDatabaseCompletionRejected) {
        throw new RuntimeException('Completed database changes were accepted without server-verified database recovery.');
    }
    if (PhaseAiWorkflowContract::codingOverallStatus($codingRun) !== 'completed') {
        throw new RuntimeException('The completed Coding Engine checkpoints did not derive a completed overall status.');
    }
    $blockedCodingRun = $codingRun;
    foreach ($blockedCodingRun['stages'] as &$blockedStage) {
        if (($blockedStage['stage_key'] ?? '') === 'verification') {
            $blockedStage['result']['status'] = 'blocked';
        }
    }
    unset($blockedStage);
    if (PhaseAiWorkflowContract::codingOverallStatus($blockedCodingRun) !== 'blocked') {
        throw new RuntimeException('A blocked verification checkpoint did not block the Coding Engine overall status.');
    }
    $skippedVerificationRejected = false;
    try {
        PhaseAiWorkflowContract::validate($codingRun, 'verification', [
            'schemaVersion' => 'builderx.coding-checkpoint.v1',
            'workflowKey' => (string) $codingRun['workflow_key'],
            'stage' => 'verification',
            'status' => 'skipped',
            'evidence' => ['Verification was not executed.'],
        ]);
    } catch (RuntimeException) {
        $skippedVerificationRejected = true;
    }
    if (!$skippedVerificationRejected) {
        throw new RuntimeException('A non-Git Coding verification checkpoint was allowed to skip.');
    }
    $mismatchedPersistenceStatusRejected = false;
    try {
        $blockedImplementation = null;
        foreach ($blockedCodingRun['stages'] as $blockedStage) {
            if (($blockedStage['stage_key'] ?? '') === 'implementation') {
                $blockedImplementation = $blockedStage['result'] ?? null;
                break;
            }
        }
        PhaseAiWorkflowContract::validate($blockedCodingRun, 'persistence', [
            'schemaVersion' => 'builderx.ai-persistence.v1',
            'workflowKey' => (string) $blockedCodingRun['workflow_key'],
            'status' => 'completed',
            'artifactHash' => PhaseAiWorkflowContract::hash(is_array($blockedImplementation) ? $blockedImplementation : []),
            'recordKey' => bx_uuid(),
            'readBackVerified' => true,
        ]);
    } catch (RuntimeException) {
        $mismatchedPersistenceStatusRejected = true;
    }
    if (!$mismatchedPersistenceStatusRejected) {
        throw new RuntimeException('A completed persistence status was accepted for blocked Coding checkpoints.');
    }

    echo json_encode([
        'planning_workflows_verified' => ['system_architecture', 'ui_ux_design', 'execution_roadmap', 'todo_consolidation', 'bridge_diagnostic'],
        'coding_workflows_verified' => $codingResults,
        'same_bridge_adapter_verified' => true,
        'reload_resumed_existing_bridge_request' => true,
        'semantic_event_key_verified' => true,
        'git_without_approval_skipped' => true,
        'server_source_checkpoint_bound_and_read_back' => true,
        'missing_recovery_checkpoint_rejected' => true,
        'forged_server_checkpoint_rejected' => true,
        'unprotected_database_completion_rejected' => true,
        'coding_overall_status_derived_from_checkpoints' => true,
        'skipped_non_git_verification_rejected' => true,
        'mismatched_coding_persistence_status_rejected' => true,
        'terminal_statuses_verified' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach ($sourceCheckpoints as $sourceCheckpoint) {
        (new PhaseAiSourceCheckpoint($projectRoot))->discard(
            $sourceCheckpoint['metadata'],
            $sourceCheckpoint['execution_key'],
            $sourceCheckpoint['run_key'],
            $sourceCheckpoint['workflow_key'],
            $projectIdentity
        );
    }
    foreach (array_filter(array_unique($runKeys)) as $runKey) {
        if ($db->BeginTrans() === false) throw new RuntimeException('Shared-workflow cleanup transaction could not start.');
        try {
            foreach (['phase_builder_ai_run_event', 'phase_builder_ai_run_chunk', 'phase_builder_ai_run_stage', 'phase_builder_ai_run'] as $table) {
                if ($db->Execute("DELETE FROM {$table} WHERE run_key = ?", [$runKey]) === false) throw new RuntimeException('Shared-workflow cleanup failed.');
            }
            if ($db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$runKey]) === false) throw new RuntimeException('Shared-workflow audit cleanup failed.');
            if ($db->CommitTrans() === false) throw new RuntimeException('Shared-workflow cleanup transaction could not commit.');
        } catch (Throwable $error) {
            $db->RollbackTrans();
            throw $error;
        }
    }
    $db->Execute('DELETE FROM phase_builder_ai_job WHERE project_identity = ? AND run_key IN (' . implode(', ', array_fill(0, count($runKeys), '?')) . ')', [$projectIdentity, ...$runKeys]);
    $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ?', [$contextId, $projectIdentity]);
    foreach (array_filter(array_unique($executionKeys)) as $executionKey) {
        if (
            $db->Execute('DELETE FROM phase_builder_todo_execution_logs WHERE execution_key = ?', [$executionKey]) === false
            || $db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_todo_execution_logs' AND record_key = ?", [$executionKey]) === false
        ) {
            throw new RuntimeException('Shared-workflow execution context cleanup failed.');
        }
    }
    $directoryIsEmpty = static function (string $path): bool {
        $entries = is_dir($path) ? scandir($path) : false;
        return is_array($entries) && count($entries) === 2;
    };
    $sourceCheckpointRoot = $projectRoot . '/storage/backups/phase-ai-source';
    if (!$sourceCheckpointRootExisted && $directoryIsEmpty($sourceCheckpointRoot)) {
        rmdir($sourceCheckpointRoot);
    }
    $backupRoot = $projectRoot . '/storage/backups';
    if (!$backupRootExisted && $directoryIsEmpty($backupRoot)) {
        rmdir($backupRoot);
    }
}
