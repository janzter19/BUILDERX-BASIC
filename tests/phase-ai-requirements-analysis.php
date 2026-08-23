<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiRunStore;
use BuilderX\AI\RequirementsAnalysisWorkflow;

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
$draftKeys = [];
$contextKeys = [];

$source = [
    'product_goal' => 'Provide a traceable BuilderX product requirements lifecycle.',
    'users_and_roles' => 'Users access the User Portal. Administrators manage the Administrator Portal.',
    'main_user_journey' => 'A user signs in, completes work, and receives verified feedback.',
    'web_requirements' => 'The User Portal and Administrator Portal must be responsive and accessible.',
    'android_requirements' => 'The Android application synchronizes authorized user data.',
    'database_and_synchronization' => 'MySQL persistence and synchronization require transaction-safe read-back.',
    'security_and_permissions' => 'Authorization, CSRF protection, and least privilege are required.',
    'validation_and_error_handling' => 'Validation failures preserve state and provide recovery guidance.',
    'open_questions' => 'Deployment capacity targets remain to be confirmed.',
];

$insertDraft = static function (string $draftKey, array $snapshot) use ($db): void {
    $saved = $db->Execute(
        'INSERT INTO phase_builder_narrative_draft (draft_key, phase_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$draftKey, ...array_values($snapshot)]
    );
    if ($saved === false) {
        throw new RuntimeException('The disposable Requirements Analysis draft could not be created.');
    }
};

$requestFor = static function (string $draftKey, array $snapshot): array {
    return [
        'schema_version' => 'builderx.requirements-analysis.request.v1',
        'route_key' => 'phases:builder',
        'workflow_key' => RequirementsAnalysisWorkflow::WORKFLOW_KEY,
        'draft_key' => $draftKey,
        'phase_key' => null,
        'source_snapshot' => $snapshot,
        'source_narrative_hash' => RequirementsAnalysisWorkflow::hashObject($snapshot),
        'semantic_chunk_keys' => array_values(array_map(
            static fn (array $configuration): string => $configuration['chunk_key'],
            RequirementsAnalysisWorkflow::CHUNKS
        )),
    ];
};

$chunkResult = static function (array $run, string $stageKey, int $index): array {
    $configuration = RequirementsAnalysisWorkflow::CHUNKS[$stageKey];
    $request = $run['request'];
    $sourceField = $configuration['source_fields'][0];
    return [
        'schemaVersion' => RequirementsAnalysisWorkflow::CHUNK_SCHEMA,
        'workflowKey' => RequirementsAnalysisWorkflow::WORKFLOW_KEY,
        'stageKey' => $stageKey,
        'chunkKey' => $configuration['chunk_key'],
        'source' => ['draftKey' => $run['draft_key'], 'narrativeHash' => $request['source_narrative_hash']],
        'summary' => $configuration['label'] . ' verified test summary.',
        'actors' => $index === 1 ? [['actorId' => 'ACTOR-001', 'name' => 'Verified user', 'role' => 'User', 'sourceReferences' => ['users_and_roles']]] : [],
        'entities' => $index === 2 ? [['entityId' => 'ENTITY-001', 'name' => 'Verified record', 'description' => 'A persisted product record.', 'sourceReferences' => ['database_and_synchronization']]] : [],
        'portals' => in_array($stageKey, ['req_user_portal', 'req_admin_portal', 'req_android_mobile'], true) ? [[
            'portalKey' => $configuration['chunk_key'],
            'label' => $configuration['label'],
            'description' => 'Verified bounded product surface.',
            'sourceReferences' => [$sourceField],
        ]] : [],
        'requirements' => [[
            'requirementId' => $configuration['id_prefix'] . '-001',
            'category' => $configuration['categories'][0],
            'title' => $configuration['label'] . ' requirement',
            'description' => 'The product must satisfy the bounded ' . strtolower($configuration['label']) . ' requirement.',
            'priority' => 'Must',
            'status' => 'Proposed',
            'isSelected' => true,
            'sourceReferences' => [$sourceField],
            'acceptanceCriteria' => ['The bounded requirement has observable verification evidence.'],
            'dependencies' => [],
            'assumptions' => [],
            'risks' => [],
            'verificationMethod' => 'Automated and manual acceptance test',
        ]],
        'missingDetailsOrRisks' => [],
        'assumptions' => [],
        'openQuestions' => [],
    ];
};

try {
    $draftKey = bx_uuid();
    $draftKeys[] = $draftKey;
    $insertDraft($draftKey, $source);
    $request = $requestFor($draftKey, $source);
    $run = $store->start(PhaseAiRunStore::ENGINE_PLANNING, RequirementsAnalysisWorkflow::WORKFLOW_KEY, $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    $runKey = (string) ($run['run_key'] ?? '');
    $runKeys[] = $runKey;
    if (count($run['stages'] ?? []) !== 13 || count($run['chunks'] ?? []) !== 13) {
        throw new RuntimeException('Requirements Analysis did not create the complete persistent 13-stage plan.');
    }

    $contextId = bx_uuid();
    $contextKeys[] = $contextId;
    $context = (new PhaseAiContextStore($testUserKey))->save($contextId, $projectIdentity, ['schema_version' => 'builderx.requirements-test-context.v1', 'source_snapshot' => $source]);
    $orchestrator->begin($runKey, $projectIdentity, 'context', ['operation' => 'test_context']);
    $run = $orchestrator->complete($runKey, $projectIdentity, 'context', [
        'context_id' => $contextId,
        'context_ref' => $context['context_ref'],
        'bytes' => $context['bytes'],
        'sha256' => $context['sha256'],
    ]);

    $semanticCount = 0;
    foreach (array_keys(RequirementsAnalysisWorkflow::CHUNKS) as $stageKey) {
        $semanticCount++;
        $orchestrator->begin($runKey, $projectIdentity, $stageKey, ['chunk_order' => $semanticCount]);
        $run = $orchestrator->complete($runKey, $projectIdentity, $stageKey, $chunkResult($run, $stageKey, $semanticCount));
    }

    $orchestrator->begin($runKey, $projectIdentity, 'merge', ['operation' => 'deterministic_merge']);
    $run = $store->read($runKey, $projectIdentity);
    $merge = RequirementsAnalysisWorkflow::merge($run);
    $tamperedMerge = $merge;
    $tamperedMerge['contractHash'] = str_repeat('0', 64);
    $tamperedMergeRejected = false;
    try {
        RequirementsAnalysisWorkflow::validateMerge($run, $tamperedMerge);
    } catch (Throwable $expected) {
        $tamperedMergeRejected = str_contains($expected->getMessage(), 'does not match');
    }
    if (!$tamperedMergeRejected) {
        throw new RuntimeException('A tampered deterministic merge was accepted.');
    }
    $run = $orchestrator->complete($runKey, $projectIdentity, 'merge', $merge);

    $requirementIds = [];
    foreach (RequirementsAnalysisWorkflow::CATEGORY_KEYS as $categoryKey) {
        foreach (($merge['contract'][$categoryKey] ?? []) as $requirement) {
            $requirementIds[] = (string) $requirement['requirementId'];
        }
    }
    sort($requirementIds);
    $review = [
        'schemaVersion' => RequirementsAnalysisWorkflow::REVIEW_SCHEMA,
        'workflowKey' => RequirementsAnalysisWorkflow::WORKFLOW_KEY,
        'sourceNarrativeHash' => $request['source_narrative_hash'],
        'mergedContractHash' => $merge['contractHash'],
        'status' => 'approved',
        'findings' => [['severity' => 'info', 'message' => 'The verified chunks use consistent terminology and traceability.']],
        'confirmedRequirementIds' => $requirementIds,
    ];
    $orchestrator->begin($runKey, $projectIdentity, 'integration_review', ['contract_hash' => $merge['contractHash']]);
    $run = $orchestrator->complete($runKey, $projectIdentity, 'integration_review', $review);

    $analysisKey = bx_uuid();
    $orchestrator->begin($runKey, $projectIdentity, 'persistence', ['operation' => 'test_read_back']);
    $run = $orchestrator->complete($runKey, $projectIdentity, 'persistence', [
        'schemaVersion' => RequirementsAnalysisWorkflow::PERSISTENCE_SCHEMA,
        'status' => 'created',
        'draftKey' => $draftKey,
        'sourceNarrativeHash' => $request['source_narrative_hash'],
        'analysisKey' => $analysisKey,
        'contractHash' => $merge['contractHash'],
        'readBackVerified' => true,
    ]);
    if (($run['status'] ?? '') !== 'SUCCEEDED' || $semanticCount !== 9 || count($requirementIds) !== 9) {
        throw new RuntimeException('The persistent Requirements Analysis workflow did not complete exactly nine semantic chunks.');
    }
    $cached = $store->start(PhaseAiRunStore::ENGINE_PLANNING, RequirementsAnalysisWorkflow::WORKFLOW_KEY, $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    if (($cached['run_key'] ?? '') !== $runKey || ($cached['status'] ?? '') !== 'SUCCEEDED') {
        throw new RuntimeException('A source-identical completed Requirements Analysis run was not reused.');
    }

    $staleDraftKey = bx_uuid();
    $draftKeys[] = $staleDraftKey;
    $insertDraft($staleDraftKey, $source);
    $staleRequest = $requestFor($staleDraftKey, $source);
    $staleRun = $store->start(PhaseAiRunStore::ENGINE_PLANNING, RequirementsAnalysisWorkflow::WORKFLOW_KEY, $staleDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $staleRequest, $testUserKey);
    $staleRunKey = (string) ($staleRun['run_key'] ?? '');
    $runKeys[] = $staleRunKey;
    $db->Execute('UPDATE phase_builder_narrative_draft SET product_goal = ? WHERE draft_key = ?', ['Changed upstream source.', $staleDraftKey]);
    $staleSourceRejected = false;
    try {
        $orchestrator->next($staleRunKey, $projectIdentity);
    } catch (Throwable $expected) {
        $staleSourceRejected = $expected->getMessage() === 'SOURCE_CHANGED';
    }
    if (!$staleSourceRejected) {
        throw new RuntimeException('A stale Requirements Analysis source was not rejected.');
    }

    echo json_encode([
        'workflow_key' => RequirementsAnalysisWorkflow::WORKFLOW_KEY,
        'engine_type' => $run['engine_type'],
        'stage_count' => count($run['stages']),
        'chunk_count' => count($run['chunks']),
        'semantic_chunk_count' => $semanticCount,
        'requirement_count' => count($requirementIds),
        'deterministic_merge_verified' => true,
        'tampered_merge_rejected' => true,
        'integration_review_verified' => true,
        'immutable_ids_verified' => true,
        'source_cache_reused' => true,
        'stale_source_rejected' => true,
        'terminal_status' => $run['status'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach (array_filter(array_unique($runKeys)) as $cleanupRunKey) {
        $db->BeginTrans();
        try {
            $db->Execute('DELETE FROM phase_builder_ai_run_event WHERE run_key = ?', [$cleanupRunKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_chunk WHERE run_key = ?', [$cleanupRunKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_stage WHERE run_key = ?', [$cleanupRunKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run WHERE run_key = ?', [$cleanupRunKey]);
            $db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$cleanupRunKey]);
            $db->CommitTrans();
        } catch (Throwable $cleanupError) {
            $db->RollbackTrans();
            throw $cleanupError;
        }
    }
    foreach (array_filter(array_unique($draftKeys)) as $cleanupDraftKey) {
        $db->Execute('DELETE FROM phase_builder_narrative_draft WHERE draft_key = ?', [$cleanupDraftKey]);
    }
    foreach (array_filter(array_unique($contextKeys)) as $cleanupContextKey) {
        $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ?', [$cleanupContextKey, $projectIdentity]);
    }
}
