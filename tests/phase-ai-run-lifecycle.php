<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\PhaseAiRunStore;

$db = bx_db();
$testUserKey = bx_uuid();
$store = new PhaseAiRunStore($testUserKey);
$draftKey = bx_uuid();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    throw new RuntimeException('The test project root could not be resolved.');
}
$projectIdentity = hash('sha256', str_replace('\\', '/', $projectRoot));
$idempotencyKey = bin2hex(random_bytes(16));
$request = [
    'schema_version' => 'builderx.ai-run.request.v1',
    'route_key' => 'phases:builder',
    'workflow_key' => 'narrative_cleanup',
    'draft_key' => $draftKey,
    'phase_key' => null,
    'source_snapshot' => [
        'product_goal' => 'Lifecycle test only.',
        'users_and_roles' => 'Lifecycle test only.',
        'main_user_journey' => 'Lifecycle test only.',
        'web_requirements' => 'Lifecycle test only.',
        'android_requirements' => 'Lifecycle test only.',
        'database_and_synchronization' => 'Lifecycle test only.',
        'security_and_permissions' => 'Lifecycle test only.',
        'validation_and_error_handling' => 'Lifecycle test only.',
        'open_questions' => '',
    ],
];
$runKeys = [];

try {
    $grammarCheckpoint = [
        'role' => 'grammar_specialist',
        'status' => 'completed',
        'corrected_sections' => $request['source_snapshot'],
        'change_history' => [],
    ];
    $approvalCheckpoint = [
        'role' => 'database_specialist',
        'status' => 'approved',
        'database_specialist_approved' => true,
        'draft_key' => $draftKey,
        'reason' => 'Disposable lifecycle validation passed.',
    ];
    $canonicalApproval = BuilderX\AI\PhaseBuilderNarrativeCleanupStore::canonicalizePersistedApproval(
        $draftKey,
        $approvalCheckpoint,
        $request['source_snapshot'],
        $grammarCheckpoint
    );
    if (($canonicalApproval['validation'] ?? null) !== ['complete' => true, 'meaning_preserved' => true, 'write_allowed' => true]) {
        throw new RuntimeException('The server-owned narrative validation contract was not derived from persisted checkpoints.');
    }
    $validatedSections = BuilderX\AI\PhaseBuilderNarrativeCleanupStore::validatePersistedApproval(
        $draftKey,
        $approvalCheckpoint,
        $request['source_snapshot'],
        $grammarCheckpoint
    );
    if ($validatedSections !== $request['source_snapshot']) {
        throw new RuntimeException('Database-backed narrative approval validation failed.');
    }

    $first = $store->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, $idempotencyKey, $request, $testUserKey);
    $runKey = (string) ($first['run_key'] ?? '');
    $runKeys[] = $runKey;
    $second = $store->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, $idempotencyKey, $request, $testUserKey);
    if ($runKey === '' || (string) ($second['run_key'] ?? '') !== $runKey) {
        throw new RuntimeException('Idempotent start did not return the same run.');
    }
    if (count($first['stages'] ?? []) !== 5 || count($first['chunks'] ?? []) !== 5 || count($first['events'] ?? []) !== 1) {
        throw new RuntimeException('Initial run read-back did not include the complete stage, chunk, and event plan.');
    }
    if ((int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ? AND action = 'CREATE'", [$runKey]) !== 1) {
        throw new RuntimeException('Initial run audit read-back failed.');
    }

    $engineBoundaryRejected = false;
    try {
        $store->start('CODING', 'narrative_cleanup', $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    } catch (Throwable $expected) {
        $engineBoundaryRejected = str_contains($expected->getMessage(), 'does not belong');
    }
    if (!$engineBoundaryRejected) {
        throw new RuntimeException('The Planning workflow was accepted by the Coding Engine.');
    }

    $idempotencyConflictRejected = false;
    try {
        $conflictingRequest = $request;
        $conflictingRequest['source_snapshot']['product_goal'] = 'Different lifecycle request.';
        $store->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, $idempotencyKey, $conflictingRequest, $testUserKey);
    } catch (Throwable $expected) {
        $idempotencyConflictRejected = str_contains($expected->getMessage(), 'different request');
    }
    if (!$idempotencyConflictRejected) {
        throw new RuntimeException('Conflicting idempotency-key reuse was not rejected.');
    }

    $directSuccessRejected = false;
    try {
        $store->checkpoint($runKey, $projectIdentity, 'context', 'SUCCEEDED', null, ['invalid' => true], null, null, null);
    } catch (Throwable $expected) {
        $directSuccessRejected = str_contains($expected->getMessage(), 'transition');
    }
    if (!$directSuccessRejected) {
        throw new RuntimeException('A queued stage was allowed to succeed without running.');
    }

    $stageSkipRejected = false;
    try {
        $store->checkpoint($runKey, $projectIdentity, 'grammar', 'RUNNING', null, null, null, null, null);
    } catch (Throwable $expected) {
        $stageSkipRejected = str_contains($expected->getMessage(), 'cannot skip');
    }
    if (!$stageSkipRejected) {
        throw new RuntimeException('Out-of-order stage execution was not rejected.');
    }

    $store->checkpoint($runKey, $projectIdentity, 'context', 'RUNNING', ['stage' => 'context'], null, null, null, null);
    $duplicateRunningClaimRejected = false;
    try {
        $store->checkpoint($runKey, $projectIdentity, 'context', 'RUNNING', ['stage' => 'context'], null, null, null, null);
    } catch (Throwable $expected) {
        $duplicateRunningClaimRejected = str_starts_with($expected->getMessage(), 'LOCK_CONFLICT:');
    }
    if (!$duplicateRunningClaimRejected) {
        throw new RuntimeException('A running stage accepted a second server dispatch claim.');
    }

    foreach (['context', 'routing', 'grammar', 'validation', 'persistence'] as $stage) {
        if ($stage !== 'context') {
            $store->checkpoint($runKey, $projectIdentity, $stage, 'RUNNING', ['stage' => $stage], null, null, null, null);
        }
        if ($stage === 'validation') {
            $store->checkpoint($runKey, $projectIdentity, $stage, 'VALIDATING', null, null, null, null, null);
            $failed = $store->checkpoint($runKey, $projectIdentity, $stage, 'FAILED', null, null, null, 'INVALID_RESULT_SCHEMA', 'Disposable lifecycle retry test.');
            if (($failed['status'] ?? '') !== 'FAILED') {
                throw new RuntimeException('A failed stage did not set the run to FAILED.');
            }
            if (trim((string) ($failed['completed_at'] ?? '')) === '') {
                throw new RuntimeException('A failed run did not record its terminal timestamp.');
            }
            $retried = $store->checkpoint($runKey, $projectIdentity, $stage, 'RUNNING', ['stage' => $stage, 'retry' => true], null, null, null, null);
            if (($retried['status'] ?? '') !== 'RUNNING' || ($retried['completed_at'] ?? null) !== null || ($retried['error_code'] ?? null) !== null) {
                throw new RuntimeException('A failed-stage retry did not clear the run terminal state.');
            }
            $store->checkpoint($runKey, $projectIdentity, $stage, 'VALIDATING', null, null, null, null, null);
        }
        $store->checkpoint($runKey, $projectIdentity, $stage, 'SUCCEEDED', null, ['stage' => $stage, 'verified' => true], 'test-' . $stage, null, null);
    }

    $complete = $store->read($runKey, $projectIdentity);
    if (($complete['status'] ?? '') !== 'SUCCEEDED') {
        throw new RuntimeException('The run did not reach SUCCEEDED.');
    }
    $validationStage = array_values(array_filter($complete['stages'] ?? [], static fn (array $stage): bool => ($stage['stage_key'] ?? '') === 'validation'))[0] ?? null;
    $validationChunk = array_values(array_filter($complete['chunks'] ?? [], static fn (array $chunk): bool => ($chunk['stage_key'] ?? '') === 'validation'))[0] ?? null;
    if (count($complete['stages'] ?? []) !== 5 || count($complete['chunks'] ?? []) !== 5 || count($complete['events'] ?? []) !== 15 || (int) ($validationStage['attempt_count'] ?? 0) !== 2 || (int) ($validationChunk['attempt_count'] ?? 0) !== 2) {
        throw new RuntimeException('Terminal run read-back did not contain the expected stages, chunks, and events.');
    }
    if ((int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$runKey]) !== 15) {
        throw new RuntimeException('Terminal run audit read-back did not contain every persisted transition.');
    }

    $terminalMutationRejected = false;
    try {
        $store->checkpoint($runKey, $projectIdentity, 'persistence', 'RUNNING', null, null, null, null, null);
    } catch (Throwable $expected) {
        $terminalMutationRejected = str_contains($expected->getMessage(), 'terminal state');
    }
    if (!$terminalMutationRejected) {
        throw new RuntimeException('A completed run accepted another checkpoint.');
    }

    $crossProjectRejected = false;
    try {
        $store->read($runKey, str_repeat('0', 64));
    } catch (Throwable $expected) {
        $crossProjectRejected = str_contains($expected->getMessage(), 'current project');
    }
    if (!$crossProjectRejected) {
        throw new RuntimeException('Cross-project run access was not rejected.');
    }

    $crossUserRejected = false;
    try {
        (new PhaseAiRunStore(bx_uuid()))->read($runKey, $projectIdentity);
    } catch (Throwable $expected) {
        $crossUserRejected = str_contains($expected->getMessage(), 'current project');
    }
    if (!$crossUserRejected) {
        throw new RuntimeException('Cross-user run access was not rejected.');
    }
    $crossUserTransitionRejected = false;
    try {
        (new PhaseAiRunStore(bx_uuid()))->checkpoint($runKey, $projectIdentity, 'persistence', 'RUNNING', null, null, null, null, null);
    } catch (Throwable $expected) {
        $crossUserTransitionRejected = str_contains($expected->getMessage(), 'current project');
    }
    if (!$crossUserTransitionRejected) {
        throw new RuntimeException('Cross-user run transition was not rejected.');
    }

    $retryDraftKey = bx_uuid();
    $retryRequest = $request;
    $retryRequest['draft_key'] = $retryDraftKey;
    $retryRequest['source_snapshot']['product_goal'] = 'Retry limit lifecycle test.';
    $retryRun = $store->start('PLANNING', 'narrative_cleanup', $retryDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $retryRequest, $testUserKey);
    $retryRunKey = (string) ($retryRun['run_key'] ?? '');
    $runKeys[] = $retryRunKey;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $store->checkpoint($retryRunKey, $projectIdentity, 'context', 'RUNNING', ['attempt' => $attempt], null, null, null, null);
        $store->checkpoint($retryRunKey, $projectIdentity, 'context', 'FAILED', null, null, null, 'INVALID_RESULT_SCHEMA', 'Disposable retry limit test.');
    }
    $retryLimitRejected = false;
    try {
        $store->checkpoint($retryRunKey, $projectIdentity, 'context', 'RUNNING', ['attempt' => 4], null, null, null, null);
    } catch (Throwable $expected) {
        $retryLimitRejected = str_contains($expected->getMessage(), 'retry limit');
    }
    if (!$retryLimitRejected) {
        throw new RuntimeException('The stage retry limit was not enforced.');
    }
    $replacementRetryRun = $store->start('PLANNING', 'narrative_cleanup', $retryDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $retryRequest, $testUserKey);
    $replacementRetryRunKey = (string) ($replacementRetryRun['run_key'] ?? '');
    $runKeys[] = $replacementRetryRunKey;
    if ($replacementRetryRunKey === '' || $replacementRetryRunKey === $retryRunKey || ($replacementRetryRun['status'] ?? '') !== 'QUEUED') {
        throw new RuntimeException('An exhausted failed run prevented a fresh retry run from being created.');
    }

    $refinementDraftKey = bx_uuid();
    $refinementRequest = [
        'schema_version' => 'builderx.ai-run.request.v1',
        'route_key' => 'phases:builder',
        'workflow_key' => 'system_architecture',
        'draft_key' => $refinementDraftKey,
        'phase_key' => null,
        'semantic_chunk_key' => 'architecture_contract',
        'source_hashes' => ['requirements_hash' => hash('sha256', 'refinement-requirements')],
        'context_checkpoint' => ['context_id' => bx_uuid(), 'context_ref' => 'mysql:test', 'bytes' => 1, 'sha256' => hash('sha256', 'x')],
    ];
    $blockedRun = $store->start('PLANNING', 'system_architecture', $refinementDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $refinementRequest, $testUserKey);
    $blockedRunKey = (string) ($blockedRun['run_key'] ?? '');
    $runKeys[] = $blockedRunKey;
    foreach (['context', 'analysis', 'integration_review', 'persistence'] as $stage) {
        $store->checkpoint($blockedRunKey, $projectIdentity, $stage, 'RUNNING', ['stage' => $stage], null, null, null, null);
        $result = $stage === 'persistence'
            ? ['schemaVersion' => 'builderx.ai-persistence.v1', 'workflowKey' => 'system_architecture', 'status' => 'blocked']
            : ['stage' => $stage, 'verified' => true];
        $store->checkpoint($blockedRunKey, $projectIdentity, $stage, 'SUCCEEDED', null, $result, 'test-' . $stage, null, null);
    }
    $refinementRun = $store->start('PLANNING', 'system_architecture', $refinementDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $refinementRequest, $testUserKey);
    $refinementRunKey = (string) ($refinementRun['run_key'] ?? '');
    $runKeys[] = $refinementRunKey;
    if ($refinementRunKey === '' || $refinementRunKey === $blockedRunKey || ($refinementRun['status'] ?? '') !== 'QUEUED') {
        throw new RuntimeException('A blocked Planning result was cached instead of creating a refinement run.');
    }

    $cancelDraftKey = bx_uuid();
    $cancelRequest = $request;
    $cancelRequest['draft_key'] = $cancelDraftKey;
    $cancelRequest['source_snapshot']['product_goal'] = 'Cancellation lifecycle test.';
    $cancelRun = $store->start('PLANNING', 'narrative_cleanup', $cancelDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $cancelRequest, $testUserKey);
    $cancelRunKey = (string) ($cancelRun['run_key'] ?? '');
    $runKeys[] = $cancelRunKey;
    $cancelled = $store->checkpoint($cancelRunKey, $projectIdentity, 'context', 'CANCELLED', null, null, null, null, 'Disposable cancellation test.');
    if (($cancelled['status'] ?? '') !== 'CANCELLED' || (($cancelled['chunks'][0]['status'] ?? '') !== 'CANCELLED')) {
        throw new RuntimeException('Queued-run cancellation was not persisted across the run and chunk read-back.');
    }

    $expireDraftKey = bx_uuid();
    $expireRequest = $request;
    $expireRequest['draft_key'] = $expireDraftKey;
    $expireRequest['source_snapshot']['product_goal'] = 'Expiration lifecycle test.';
    $expireRun = $store->start('PLANNING', 'narrative_cleanup', $expireDraftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $expireRequest, $testUserKey);
    $expireRunKey = (string) ($expireRun['run_key'] ?? '');
    $runKeys[] = $expireRunKey;
    $leased = $store->checkpoint($expireRunKey, $projectIdentity, 'context', 'RUNNING', ['operation' => 'bounded_timeout_test'], null, '11111111-1111-4111-8111-111111111111', null, null);
    if (
        !str_starts_with((string) ($leased['worker_id'] ?? ''), 'bridge:')
        || trim((string) ($leased['locked_until'] ?? '')) === ''
    ) {
        throw new RuntimeException('A running stage did not persist its Bridge worker lease.');
    }
    if ($db->Execute('UPDATE phase_builder_ai_run SET locked_until = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 SECOND) WHERE run_key = ?', [$expireRunKey]) === false) {
        throw new RuntimeException('The disposable stage lease could not be expired.');
    }
    $expired = $store->read($expireRunKey, $projectIdentity);
    if (
        ($expired['status'] ?? '') !== 'EXPIRED'
        || (($expired['chunks'][0]['error_code'] ?? '') !== 'STAGE_TIMEOUT')
        || ($expired['worker_id'] ?? null) !== null
        || ($expired['locked_until'] ?? null) !== null
    ) {
        throw new RuntimeException('A stale running-stage lease was not expired and cleared with its exact error code.');
    }

    $rollbackRunKey = bx_uuid();
    $rollbackEventKey = bx_uuid();
    if ($db->BeginTrans() === false) {
        throw new RuntimeException('Rollback verification transaction could not start.');
    }
    $rollbackSaved = $db->Execute(
        'INSERT INTO phase_builder_ai_run_event (event_key, run_key, event_type, status, message, payload_json) VALUES (?, ?, ?, ?, ?, ?)',
        [$rollbackEventKey, $rollbackRunKey, 'ROLLBACK_TEST', 'QUEUED', 'Disposable transaction rollback verification.', '{}']
    );
    if ($rollbackSaved === false) {
        $db->RollbackTrans();
        throw new RuntimeException('Rollback verification write failed.');
    }
    $db->RollbackTrans();
    if ((int) $db->GetOne('SELECT COUNT(*) FROM phase_builder_ai_run_event WHERE event_key = ?', [$rollbackEventKey]) !== 0) {
        throw new RuntimeException('The disposable rollback verification record was committed unexpectedly.');
    }

    echo json_encode([
        'idempotent_start' => true,
        'database_canonical_validation' => true,
        'stage_order_rejected' => true,
        'terminal_status' => $complete['status'],
        'stage_count' => count($complete['stages']),
        'chunk_count' => count($complete['chunks']),
        'event_count' => count($complete['events']),
        'audit_count' => 15,
        'failed_stage_retry_count' => (int) ($validationStage['attempt_count'] ?? 0),
        'strict_transition_rejected' => true,
        'duplicate_running_claim_rejected' => true,
        'retry_limit_rejected' => true,
        'exhausted_failure_replaced' => true,
        'blocked_planning_result_refinable' => true,
        'engine_boundary_rejected' => true,
        'idempotency_conflict_rejected' => true,
        'terminal_mutation_rejected' => true,
        'cancelled_state_verified' => true,
        'expired_state_verified' => true,
        'worker_lease_read_back_verified' => true,
        'automatic_stage_timeout_verified' => true,
        'cross_project_rejected' => true,
        'cross_user_rejected' => true,
        'cross_user_transition_rejected' => true,
        'route_binding_verified' => ($complete['route_key'] ?? '') === 'phases:builder',
        'transaction_rollback_verified' => true,
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
}
