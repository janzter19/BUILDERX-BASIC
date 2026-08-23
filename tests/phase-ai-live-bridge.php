<?php
declare(strict_types=1);

if (getenv('BUILDERX_LIVE_BRIDGE_TEST') !== '1') {
    fwrite(STDERR, "Set BUILDERX_LIVE_BRIDGE_TEST=1 to run the active Codex AI Chat lifecycle test.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\BuilderXAiBridgeAdapter;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiRunStore;

$db = bx_db();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    throw new RuntimeException('The live Bridge test project root could not be resolved.');
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$adapter = new BuilderXAiBridgeAdapter($projectRoot);
$testUserKey = bx_uuid();
$store = new PhaseAiRunStore($testUserKey);
$orchestrator = new PhaseAiOrchestrator($projectRoot, $store, $adapter);
$runKey = '';
$contextId = '';

$source = [
    'product_goal' => 'Live Bridge lifecycle test.',
    'users_and_roles' => 'Administrator.',
    'main_user_journey' => 'Submit and verify one bounded result.',
    'web_requirements' => 'No product change.',
    'android_requirements' => 'No Android change.',
    'database_and_synchronization' => 'Disposable run records only.',
    'security_and_permissions' => 'Current project only.',
    'validation_and_error_handling' => 'Return the exact JSON object.',
    'open_questions' => '',
];
$grammar = [
    'role' => 'grammar_specialist',
    'status' => 'completed',
    'corrected_sections' => $source,
    'change_history' => [],
];
$draftKey = bx_uuid();
$request = [
    'schema_version' => 'builderx.ai-run.request.v1',
    'route_key' => 'phases:builder',
    'workflow_key' => 'narrative_cleanup',
    'draft_key' => $draftKey,
    'phase_key' => null,
    'source_snapshot' => $source,
];

try {
    $health = $adapter->health(true);
    $run = $store->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    $runKey = (string) ($run['run_key'] ?? '');
    $contextId = bx_uuid();
    $context = (new PhaseAiContextStore($testUserKey))->save($contextId, $projectIdentity, ['schema_version' => 'builderx.live-bridge-context.v1', 'source_snapshot' => $source]);
    $orchestrator->begin($runKey, $projectIdentity, 'context', ['live_bridge_test' => true]);
    $orchestrator->complete($runKey, $projectIdentity, 'context', [
        'context_id' => $contextId,
        'context_ref' => $context['context_ref'],
        'bytes' => $context['bytes'],
        'sha256' => $context['sha256'],
    ]);
    $orchestrator->begin($runKey, $projectIdentity, 'routing', ['live_bridge_test' => true]);
    $orchestrator->complete($runKey, $projectIdentity, 'routing', [
        'role' => 'coordinator',
        'status' => 'routed',
        'selected_specialist' => 'narrative-cleanup',
        'next_specialist' => 'database',
        'reason' => 'Run one bounded live transport verification.',
    ]);
    $command = 'BuilderX live MySQL transport verification. Do not edit source code, configuration, or product data. Return exactly this JSON object through the supplied MySQL job completion command: '
        . json_encode($grammar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $dispatched = $orchestrator->dispatch($runKey, $projectIdentity, 'grammar', ['live_bridge_test' => true], $command, true);
    $delivery = $dispatched['delivery'] ?? [];
    $requestId = (string) ($delivery['provider_request_id'] ?? '');
    $deadline = microtime(true) + 240;
    $result = null;
    while (microtime(true) < $deadline) {
        $candidate = $adapter->result($requestId);
        if (($candidate['status'] ?? '') === 'completed') {
            $result = $candidate;
            break;
        }
        if (($candidate['status'] ?? '') === 'failed') {
            throw new RuntimeException((string) ($candidate['message'] ?? 'The live Codex AI Chat task failed.'));
        }
        usleep(500000);
    }
    if (!is_array($result) || ($result['result_json'] ?? null) !== $grammar) {
        throw new RuntimeException('The live BuilderX AI Bridge result did not match the exact JSON contract.');
    }
    $completed = $orchestrator->complete($runKey, $projectIdentity, 'grammar', $result['result_json']);
    $databaseRun = $db->GetRow('SELECT project_identity, provider_key, provider_request_id, status, stage_key FROM phase_builder_ai_run WHERE run_key = ?', [$runKey]);
    $databaseStage = $db->GetRow('SELECT stage_key, status, provider_request_id, result_json FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_key = ?', [$runKey, 'grammar']);
    if (
        !is_array($databaseRun)
        || !is_array($databaseStage)
        || ($databaseRun['project_identity'] ?? '') !== $projectIdentity
        || ($databaseRun['provider_key'] ?? '') !== BuilderXAiBridgeAdapter::PROVIDER_KEY
        || ($databaseRun['provider_request_id'] ?? '') !== $requestId
        || ($databaseRun['status'] ?? '') !== 'RUNNING'
        || ($databaseRun['stage_key'] ?? '') !== 'grammar'
        || ($databaseStage['status'] ?? '') !== 'SUCCEEDED'
        || ($databaseStage['provider_request_id'] ?? '') !== $requestId
        || json_decode((string) ($databaseStage['result_json'] ?? ''), true) !== $grammar
    ) {
        throw new RuntimeException('The live BuilderX AI Bridge database read-back failed.');
    }
    echo json_encode([
        'live_bridge_health' => true,
        'active_chat_ready' => ($health['active_thread_ready'] ?? false) === true,
        'bridge_acknowledged' => true,
        'mysql_result_verified' => true,
        'provider_request_id_persisted' => true,
        'database_read_back_verified' => true,
        'run_status' => $completed['status'] ?? null,
        'stage_status' => $databaseStage['status'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    if ($runKey !== '') {
        try {
            $run = $store->read($runKey, $projectIdentity);
            $stage = array_values(array_filter($run['stages'] ?? [], static fn (array $row): bool => ($row['stage_key'] ?? '') === ($run['stage_key'] ?? '')))[0] ?? null;
            if (is_array($stage) && in_array((string) ($stage['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
                $orchestrator->fail($runKey, $projectIdentity, (string) $stage['stage_key'], 'PROVIDER_UNAVAILABLE', substr($error->getMessage(), 0, 2000));
            }
        } catch (Throwable) {
        }
    }
    throw $error;
} finally {
    if ($runKey !== '') {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Live Bridge test cleanup transaction could not start.');
        }
        try {
            foreach (['phase_builder_ai_run_event', 'phase_builder_ai_run_chunk', 'phase_builder_ai_run_stage', 'phase_builder_ai_run'] as $table) {
                if ($db->Execute("DELETE FROM {$table} WHERE run_key = ?", [$runKey]) === false) {
                    throw new RuntimeException('Live Bridge test cleanup failed.');
                }
            }
            if ($db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$runKey]) === false) {
                throw new RuntimeException('Live Bridge test audit cleanup failed.');
            }
            if ($db->CommitTrans() === false) {
                throw new RuntimeException('Live Bridge test cleanup transaction could not commit.');
            }
        } catch (Throwable $cleanupError) {
            $db->RollbackTrans();
            throw $cleanupError;
        }
    }
    if ($runKey !== '') $db->Execute('DELETE FROM phase_builder_ai_job WHERE run_key = ?', [$runKey]);
    if ($contextId !== '') $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ?', [$contextId, $projectIdentity]);
}
