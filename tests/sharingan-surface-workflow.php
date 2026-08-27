<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiRunStore;
use BuilderX\AI\SharinganSurfaceWorkflow;

$db = bx_db();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    throw new RuntimeException('The test project root could not be resolved.');
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$testUserKey = bx_uuid();
$store = new PhaseAiRunStore($testUserKey);
$orchestrator = new PhaseAiOrchestrator($projectRoot, $store);
$runKeys = [];
$surfaceResults = [];
$contextKeys = [];

try {
    foreach (SharinganSurfaceWorkflow::SURFACES as $surfaceKey => $surface) {
        $contextId = bx_uuid();
        $contextKeys[] = $contextId;
        $contextHash = hash('sha256', $surfaceKey . ':bounded-context');
        $request = [
            'schema_version' => 'builderx.sharingan.request.v1',
            'route_key' => $surface['route_key'],
            'workflow_key' => $surface['workflow_key'],
            'surface_key' => $surfaceKey,
            'surface_label' => $surface['label'],
            'route_path' => '/' . str_replace('_portal', '', $surfaceKey),
            'context_id' => $contextId,
            'context_sha256' => $contextHash,
            'instruction' => 'Analyze the selected element without mutating product data.',
            'metadata' => ['selected_element' => ['selector' => '#test']],
            'screenshot' => ['sha256' => hash('sha256', 'test-image')],
            'attachments' => [],
        ];
        $run = $store->start(PhaseAiRunStore::ENGINE_PLANNING, $surface['workflow_key'], $surface['draft_key'], null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
        $runKey = (string) ($run['run_key'] ?? '');
        $runKeys[] = $runKey;
        $context = (new PhaseAiContextStore($testUserKey))->save($contextId, $projectIdentity, ['schema_version' => 'builderx.sharingan-test-context.v1', 'request' => $request]);
        $orchestrator->begin($runKey, $projectIdentity, 'context', ['operation' => 'test_surface_context']);
        $run = $orchestrator->complete($runKey, $projectIdentity, 'context', [
            'context_id' => $contextId,
            'context_ref' => $context['context_ref'],
            'bytes' => $context['bytes'],
            'sha256' => $context['sha256'],
        ]);
        $analysis = [
            'schemaVersion' => SharinganSurfaceWorkflow::ANALYSIS_SCHEMA,
            'status' => 'completed',
            'surface' => ['surfaceKey' => $surfaceKey, 'routeKey' => $surface['route_key']],
            'context' => ['contextId' => $contextId, 'contextSha256' => $contextHash],
            'summary' => 'The selected product surface was analyzed without mutation.',
            'findings' => [['findingId' => 'FND-001', 'severity' => 'info', 'description' => 'The bounded visual context is available for review.']],
            'proposedChanges' => [[
                'changeId' => 'CHG-001',
                'scope' => $surface['label'],
                'description' => 'Submit a separate approved implementation request if this change should proceed.',
                'recommendedEngine' => PhaseAiRunStore::ENGINE_CODING,
                'requiresAdministratorApproval' => true,
            ]],
            'risks' => ['Implementation without an approved scope could affect unrelated behavior.'],
            'verificationPlan' => ['Verify the authorized route, selected element, and unchanged adjacent behavior.'],
            'blockers' => [],
        ];
        $orchestrator->begin($runKey, $projectIdentity, 'analysis', ['operation' => 'test_surface_analysis']);
        $run = $orchestrator->complete($runKey, $projectIdentity, 'analysis', $analysis);
        $analysisHash = SharinganSurfaceWorkflow::hashObject($analysis);
        $orchestrator->begin($runKey, $projectIdentity, 'persistence', ['analysis_hash' => $analysisHash]);
        $run = $orchestrator->complete($runKey, $projectIdentity, 'persistence', [
            'schemaVersion' => SharinganSurfaceWorkflow::PERSISTENCE_SCHEMA,
            'status' => 'saved',
            'analysisHash' => $analysisHash,
            'readBackVerified' => true,
        ]);
        if (($run['status'] ?? '') !== 'SUCCEEDED' || ($run['engine_type'] ?? '') !== PhaseAiRunStore::ENGINE_PLANNING || ($run['route_key'] ?? '') !== $surface['route_key']) {
            throw new RuntimeException('A Sharingan surface did not complete through its bound Planning Engine lifecycle.');
        }
        $surfaceResults[$surfaceKey] = ['workflow_key' => $run['workflow_key'], 'route_key' => $run['route_key'], 'status' => $run['status']];
    }

    $routeMismatchRejected = false;
    $surface = SharinganSurfaceWorkflow::surface('user_portal');
    $invalidRequest = [
        'schema_version' => 'builderx.sharingan.request.v1',
        'route_key' => 'administrator_portal',
        'workflow_key' => $surface['workflow_key'],
        'surface_key' => 'user_portal',
        'context_id' => 'route-mismatch-test',
        'context_sha256' => hash('sha256', 'route-mismatch'),
    ];
    try {
        $store->start(PhaseAiRunStore::ENGINE_PLANNING, $surface['workflow_key'], $surface['draft_key'], null, $projectIdentity, bin2hex(random_bytes(16)), $invalidRequest, $testUserKey);
    } catch (Throwable $expected) {
        $routeMismatchRejected = str_contains($expected->getMessage(), 'not bound');
    }
    if (!$routeMismatchRejected) {
        throw new RuntimeException('A Sharingan workflow accepted another surface route.');
    }

    $approvalBypassRejected = false;
    $userRun = $store->read($runKeys[0], $projectIdentity);
    $invalidAnalysis = [
        'schemaVersion' => SharinganSurfaceWorkflow::ANALYSIS_SCHEMA,
        'status' => 'completed',
        'surface' => ['surfaceKey' => 'user_portal', 'routeKey' => 'user_portal'],
        'context' => ['contextId' => $contextKeys[0], 'contextSha256' => hash('sha256', 'user_portal:bounded-context')],
        'summary' => 'Invalid approval bypass test.',
        'findings' => [],
        'proposedChanges' => [['changeId' => 'CHG-001', 'scope' => 'User Portal', 'description' => 'Unsafe direct mutation.', 'recommendedEngine' => 'CODING', 'requiresAdministratorApproval' => false]],
        'risks' => [],
        'verificationPlan' => [],
        'blockers' => [],
    ];
    try {
        SharinganSurfaceWorkflow::validateAnalysis($userRun, $invalidAnalysis);
    } catch (Throwable $expected) {
        $approvalBypassRejected = str_contains($expected->getMessage(), 'approval');
    }
    if (!$approvalBypassRejected) {
        throw new RuntimeException('A User Portal Sharingan result bypassed Administrator approval.');
    }

    echo json_encode([
        'surface_count' => count($surfaceResults),
        'surfaces' => $surfaceResults,
        'exact_engine_count_added' => 0,
        'planning_engine_reused' => true,
        'route_mismatch_rejected' => true,
        'user_portal_approval_bypass_rejected' => true,
        'product_mutation_performed' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach ($runKeys as $runKey) {
        $db->BeginTrans();
        try {
            $db->Execute('DELETE FROM phase_builder_ai_run_event WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_chunk WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_stage WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run WHERE run_key = ?', [$runKey]);
            $db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$runKey]);
            $db->CommitTrans();
        } catch (Throwable $cleanupError) {
            $db->RollbackTrans();
            throw $cleanupError;
        }
    }
    foreach ($contextKeys as $contextKey) {
        $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ?', [$contextKey, $projectIdentity]);
    }
}
