<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PhaseAiRunStore
{
    public const ENGINE_PLANNING = 'PLANNING';
    public const ENGINE_CODING = 'CODING';
    private const STAGE_LEASE_SECONDS = 3900;

    /** @var list<string> */
    private const STATUSES = ['QUEUED', 'RUNNING', 'VALIDATING', 'SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'];

    /** @var list<string> */
    private const ERROR_CODES = [
        'RUN_TIMEOUT',
        'STAGE_TIMEOUT',
        'CHUNK_TIMEOUT',
        'PROVIDER_UNAVAILABLE',
        'MODEL_UNAVAILABLE',
        'BRIDGE_UNAVAILABLE',
        'CODEX_CHAT_NOT_READY',
        'INVALID_RESULT_SCHEMA',
        'SOURCE_CHANGED',
        'CONTEXT_LIMIT_EXCEEDED',
        'LOCK_CONFLICT',
        'PERMISSION_DENIED',
        'PERSISTENCE_FAILED',
        'READ_BACK_FAILED',
        'GIT_NOT_AVAILABLE',
        'GIT_DIRTY_CONFLICT',
        'GIT_UPDATE_FAILED',
    ];

    /** @var array<string, array{engine: string, route: string, stages: list<string>}> */
    private const WORKFLOWS = [
        'narrative_cleanup' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => ['context', 'routing', 'grammar', 'validation', 'persistence'],
        ],
        'requirements_analysis' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => [
                'context',
                'req_actors_roles',
                'req_functional',
                'req_user_portal',
                'req_admin_portal',
                'req_android_mobile',
                'req_database_sync',
                'req_security',
                'req_validation_recovery',
                'req_deployment_ops',
                'merge',
                'integration_review',
                'persistence',
            ],
        ],
        'system_architecture' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => ['context', 'analysis', 'integration_review', 'persistence'],
        ],
        'ui_ux_design' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => ['context', 'analysis', 'integration_review', 'persistence'],
        ],
        'execution_roadmap' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => ['context', 'analysis', 'integration_review', 'persistence'],
        ],
        'todo_consolidation' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases',
            'stages' => ['context', 'analysis', 'persistence'],
        ],
        'todo_execution' => [
            'engine' => self::ENGINE_CODING,
            'route' => 'phases',
            'stages' => ['context', 'inspection', 'plan', 'implementation', 'verification', 'evidence', 'git_update', 'persistence'],
        ],
        'todo_rollback' => [
            'engine' => self::ENGINE_CODING,
            'route' => 'phases',
            'stages' => ['context', 'inspection', 'plan', 'implementation', 'verification', 'evidence', 'persistence'],
        ],
        'bridge_diagnostic' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases:builder',
            'stages' => ['context', 'analysis', 'persistence'],
        ],
        'sharingan_user' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'user_portal',
            'stages' => ['context', 'analysis', 'persistence'],
        ],
        'sharingan_admin' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'administrator_portal',
            'stages' => ['context', 'analysis', 'persistence'],
        ],
        'sharingan_phases' => [
            'engine' => self::ENGINE_PLANNING,
            'route' => 'phases',
            'stages' => ['context', 'analysis', 'persistence'],
        ],
    ];

    /** @return list<string> */
    public static function workflowStages(string $workflowKey): array
    {
        $workflowKey = strtolower(trim($workflowKey));
        if (!isset(self::WORKFLOWS[$workflowKey])) {
            throw new InvalidArgumentException('The requested AI workflow is not enabled.');
        }
        return self::WORKFLOWS[$workflowKey]['stages'];
    }

    private readonly ?string $ownerUserKey;

    public function __construct(?string $ownerUserKey = null)
    {
        $ownerUserKey = trim((string) $ownerUserKey) ?: null;
        if ($ownerUserKey !== null && !self::validRecordKey($ownerUserKey)) {
            throw new InvalidArgumentException('The AI run owner identity is invalid.');
        }
        $this->ownerUserKey = $ownerUserKey;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function start(
        string $engineType,
        string $workflowKey,
        string $draftKey,
        ?string $phaseKey,
        string $projectIdentity,
        string $idempotencyKey,
        array $request,
        ?string $userKey
    ): array {
        $engineType = strtoupper(trim($engineType));
        $workflowKey = strtolower(trim($workflowKey));
        $draftKey = trim($draftKey);
        $phaseKey = trim((string) $phaseKey) ?: null;
        $projectIdentity = strtolower(trim($projectIdentity));
        $idempotencyKey = strtolower(trim($idempotencyKey));

        if (!in_array($engineType, [self::ENGINE_PLANNING, self::ENGINE_CODING], true)) {
            throw new InvalidArgumentException('The AI engine type is invalid.');
        }
        if (!isset(self::WORKFLOWS[$workflowKey])) {
            throw new InvalidArgumentException('The requested AI workflow is not enabled.');
        }
        if (self::WORKFLOWS[$workflowKey]['engine'] !== $engineType) {
            throw new InvalidArgumentException('The requested AI workflow does not belong to this engine.');
        }
        if (!self::validRecordKey($draftKey) || ($phaseKey !== null && !self::validRecordKey($phaseKey))) {
            throw new InvalidArgumentException('The selected BuilderX draft or phase is invalid.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $projectIdentity)) {
            throw new InvalidArgumentException('The current project identity is invalid.');
        }
        if (!preg_match('/^[a-f0-9-]{16,64}$/', $idempotencyKey)) {
            throw new InvalidArgumentException('The AI run idempotency key is invalid.');
        }
        $userKey = trim((string) $userKey);
        if (!self::validRecordKey($userKey)) {
            throw new InvalidArgumentException('The AI run user identity is invalid.');
        }
        if ($this->ownerUserKey !== null && !hash_equals($this->ownerUserKey, $userKey)) {
            throw new InvalidArgumentException('The AI run owner does not match the authenticated user.');
        }
        $routeKey = strtolower(trim((string) ($request['route_key'] ?? '')));
        if ($routeKey !== self::WORKFLOWS[$workflowKey]['route']) {
            throw new InvalidArgumentException('The requested AI workflow is not bound to this application route.');
        }

        $requestJson = self::encodeJson($request, 'AI run request');
        $sourceHash = hash('sha256', $requestJson);
        $db = \bx_db();
        $resumable = $db->GetRow(
            "SELECT run_key FROM phase_builder_ai_run WHERE project_identity = ? AND created_by_user_key = ? AND route_key = ? AND engine_type = ? AND workflow_key = ? AND draft_key = ? AND source_hash = ? AND status IN ('QUEUED','RUNNING','VALIDATING','FAILED','SUCCEEDED') ORDER BY x_id DESC LIMIT 1",
            [$projectIdentity, $userKey, $routeKey, $engineType, $workflowKey, $draftKey, $sourceHash]
        );
        if (is_array($resumable) && self::validRecordKey((string) ($resumable['run_key'] ?? ''))) {
            return $this->read((string) $resumable['run_key'], $projectIdentity);
        }
        $existing = $db->GetRow(
            'SELECT run_key, engine_type, workflow_key, route_key, draft_key, phase_key, source_hash, created_by_user_key FROM phase_builder_ai_run WHERE project_identity = ? AND idempotency_key = ? LIMIT 1',
            [$projectIdentity, $idempotencyKey]
        );
        if (is_array($existing) && self::validRecordKey((string) ($existing['run_key'] ?? ''))) {
            $this->assertIdempotentMatch($existing, $engineType, $workflowKey, $routeKey, $draftKey, $phaseKey, $sourceHash, $userKey);
            return $this->read((string) $existing['run_key'], $projectIdentity);
        }

        $runKey = \bx_uuid();
        self::beginTransaction($db, 'AI run creation');
        $transactionStarted = true;
        try {
            $taskId = self::optionalScopeId($request['task_id'] ?? null, 'task');
            $subtaskId = self::optionalScopeId($request['subtask_id'] ?? null, 'sub-task');
            $todoId = self::optionalScopeId($request['todo_id'] ?? null, 'todo');
            $saved = $db->Execute(
                'INSERT INTO phase_builder_ai_run (run_key, project_identity, engine_type, workflow_key, route_key, stage_key, draft_key, phase_key, task_id, subtask_id, todo_id, source_hash, request_version, idempotency_key, status, attempt, max_attempts, request_json, created_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$runKey, $projectIdentity, $engineType, $workflowKey, $routeKey, self::WORKFLOWS[$workflowKey]['stages'][0], $draftKey, $phaseKey, $taskId, $subtaskId, $todoId, $sourceHash, 'builderx.ai-run.v1', $idempotencyKey, 'QUEUED', 0, 3, $requestJson, $userKey]
            );
            self::assertSaved($saved, 'AI run creation');

            foreach (self::WORKFLOWS[$workflowKey]['stages'] as $index => $stageKey) {
                $stageRecordKey = \bx_uuid();
                self::assertSaved($db->Execute(
                    'INSERT INTO phase_builder_ai_run_stage (stage_record_key, run_key, stage_key, stage_order, status, max_attempts, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$stageRecordKey, $runKey, $stageKey, $index + 1, 'QUEUED', 3, $sourceHash]
                ), 'AI run stage creation');
                $chunkKey = self::chunkKey($workflowKey, $stageKey, $request);
                self::assertSaved($db->Execute(
                    'INSERT INTO phase_builder_ai_run_chunk (chunk_record_key, chunk_key, run_key, stage_key, chunk_type, chunk_order, source_hash, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [\bx_uuid(), $chunkKey, $runKey, $stageKey, 'semantic', 1, $sourceHash, 'QUEUED']
                ), 'AI run chunk creation');
            }

            $this->insertEvent($runKey, null, null, 'RUN_CREATED', 'QUEUED', 'Persistent AI run created before bridge execution.', [
                'engine_type' => $engineType,
                'workflow_key' => $workflowKey,
                'source_hash' => $sourceHash,
            ]);
            \bx_audit('CREATE', 'phase_builder_ai_run', $runKey, [
                'engine_type' => $engineType,
                'workflow_key' => $workflowKey,
                'draft_key' => $draftKey,
                'source_hash' => $sourceHash,
            ]);
            $this->assertCreatedReadBack($runKey, $projectIdentity, $workflowKey, $routeKey, $sourceHash, $userKey);
            self::commitTransaction($db, 'AI run creation');
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            $replayed = $db->GetRow(
                'SELECT run_key, engine_type, workflow_key, route_key, draft_key, phase_key, source_hash, created_by_user_key FROM phase_builder_ai_run WHERE project_identity = ? AND idempotency_key = ? LIMIT 1',
                [$projectIdentity, $idempotencyKey]
            );
            if (is_array($replayed) && self::validRecordKey((string) ($replayed['run_key'] ?? ''))) {
                $this->assertIdempotentMatch($replayed, $engineType, $workflowKey, $routeKey, $draftKey, $phaseKey, $sourceHash, $userKey);
                return $this->read((string) $replayed['run_key'], $projectIdentity);
            }
            throw $error;
        }

        return $this->read($runKey, $projectIdentity);
    }

    /**
     * Bind one server-created source checkpoint to the still-queued implementation stage.
     * The dynamic attestation is deliberately excluded from source_hash; that hash continues
     * to represent the immutable user request that selected this run.
     *
     * @param array<string, mixed> $checkpoint
     * @return array<string, mixed>
     */
    public function bindServerSourceCheckpoint(
        string $runKey,
        string $projectIdentity,
        string $executionKey,
        array $checkpoint
    ): array {
        $runKey = trim($runKey);
        $projectIdentity = strtolower(trim($projectIdentity));
        $executionKey = trim($executionKey);
        if (!self::validRecordKey($runKey) || !self::validRecordKey($executionKey) || preg_match('/^[a-f0-9]{64}$/', $projectIdentity) !== 1) {
            throw new InvalidArgumentException('The server source checkpoint binding is invalid.');
        }
        if (
            ($checkpoint['schema_version'] ?? '') !== PhaseAiSourceCheckpoint::SCHEMA_VERSION
            || ($checkpoint['execution_key'] ?? '') !== $executionKey
            || ($checkpoint['run_key'] ?? '') !== $runKey
            || ($checkpoint['project_identity'] ?? '') !== $projectIdentity
            || ($checkpoint['created_before_write'] ?? false) !== true
            || ($checkpoint['database_rollback_protected'] ?? true) !== false
        ) {
            throw new InvalidArgumentException('The server source checkpoint metadata does not match this run.');
        }
        $checkpointJson = self::encodeJson($checkpoint, 'Server source checkpoint');
        $db = \bx_db();
        self::beginTransaction($db, 'Server source checkpoint binding');
        $transactionStarted = true;
        try {
            $run = $db->GetRow(
                'SELECT run_key, workflow_key, draft_key, phase_key, task_id, subtask_id, todo_id, status, request_json FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ?' . $this->ownerSql() . ' FOR UPDATE',
                $this->ownerParams([$runKey, $projectIdentity])
            );
            if (!is_array($run) || !self::validRecordKey((string) ($run['run_key'] ?? ''))) {
                throw new RuntimeException('The persistent Coding Engine run was not found.');
            }
            $workflowKey = (string) ($run['workflow_key'] ?? '');
            if (!in_array($workflowKey, ['todo_execution', 'todo_rollback'], true) || ($checkpoint['workflow_key'] ?? '') !== $workflowKey) {
                throw new RuntimeException('The server source checkpoint belongs to another Coding Engine workflow.');
            }
            $request = self::decodeJson((string) ($run['request_json'] ?? ''), 'Coding Engine run request');
            if (!is_array($request) || (string) ($request['execution_key'] ?? '') !== $executionKey) {
                throw new RuntimeException('The Coding Engine run is not bound to this execution record.');
            }
            $existingRunCheckpoint = $request['server_source_checkpoint'] ?? null;
            $checkpointColumn = $workflowKey === 'todo_execution' ? 'source_checkpoint_json' : 'rollback_source_checkpoint_json';
            $requiredExecutionStatusSql = $workflowKey === 'todo_execution' ? 'status = ?' : 'rollback_status = ?';
            $execution = $db->GetRow(
                "SELECT execution_key, source_checkpoint_json, rollback_source_checkpoint_json FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND {$requiredExecutionStatusSql} FOR UPDATE",
                [$executionKey, $run['draft_key'], $run['task_id'], $run['subtask_id'], $run['todo_id'], 'RUNNING']
            );
            if (!is_array($execution) || (string) ($execution['execution_key'] ?? '') !== $executionKey) {
                throw new RuntimeException('The saved todo execution context is not ready for a source checkpoint.');
            }
            $existingExecutionJson = trim((string) ($execution[$checkpointColumn] ?? ''));
            $existingExecutionCheckpoint = $existingExecutionJson !== ''
                ? self::decodeJson($existingExecutionJson, 'Saved server source checkpoint')
                : null;
            if ($existingRunCheckpoint !== null || $existingExecutionCheckpoint !== null) {
                if ($existingRunCheckpoint !== $checkpoint || $existingExecutionCheckpoint !== $checkpoint) {
                    throw new RuntimeException('A different server source checkpoint is already bound to this execution.');
                }
                self::commitTransaction($db, 'Server source checkpoint binding');
                $transactionStarted = false;
                return $this->read($runKey, $projectIdentity);
            }

            $implementationStage = $db->GetRow(
                'SELECT stage_order, status FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_key = ? FOR UPDATE',
                [$runKey, 'implementation']
            );
            if (!is_array($implementationStage) || (string) ($implementationStage['status'] ?? '') !== 'QUEUED') {
                throw new RuntimeException('The implementation stage must still be queued before its server source checkpoint is created.');
            }
            $incompleteEarlierStages = (int) $db->GetOne(
                'SELECT COUNT(*) FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_order < ? AND status <> ?',
                [$runKey, (int) ($implementationStage['stage_order'] ?? 0), 'SUCCEEDED']
            );
            if ($incompleteEarlierStages !== 0) {
                throw new RuntimeException('The server source checkpoint cannot be bound before the read-only Coding Engine stages succeed.');
            }

            $request['server_source_checkpoint'] = $checkpoint;
            $requestJson = self::encodeJson($request, 'Coding Engine run request');
            self::assertSaved($db->Execute(
                'UPDATE phase_builder_ai_run SET request_json = ?, heartbeat_at = CURRENT_TIMESTAMP WHERE run_key = ? AND project_identity = ?',
                [$requestJson, $runKey, $projectIdentity]
            ), 'Coding Engine run source checkpoint binding');
            self::assertSaved($db->Execute(
                "UPDATE phase_builder_todo_execution_logs SET {$checkpointColumn} = ?, updated_at = CURRENT_TIMESTAMP WHERE execution_key = ?",
                [$checkpointJson, $executionKey]
            ), 'Todo execution source checkpoint binding');

            $this->insertEvent($runKey, 'implementation', null, 'SERVER_SOURCE_CHECKPOINT_BOUND', 'QUEUED', 'A private server source checkpoint was created before implementation dispatch.', [
                'checkpoint_key' => (string) ($checkpoint['checkpoint_key'] ?? ''),
                'manifest_sha256' => (string) ($checkpoint['manifest_sha256'] ?? ''),
                'execution_key' => $executionKey,
                'database_rollback_protected' => false,
            ]);
            \bx_audit('UPDATE', 'phase_builder_ai_run', $runKey, [
                'action' => 'bind_server_source_checkpoint',
                'checkpoint_key' => (string) ($checkpoint['checkpoint_key'] ?? ''),
                'execution_key' => $executionKey,
                'database_rollback_protected' => false,
            ]);
            \bx_audit('UPDATE', 'phase_builder_todo_execution_logs', $executionKey, [
                'action' => 'bind_server_source_checkpoint',
                'workflow_key' => $workflowKey,
                'checkpoint_key' => (string) ($checkpoint['checkpoint_key'] ?? ''),
                'run_key' => $runKey,
                'database_rollback_protected' => false,
            ]);
            $runReadBackJson = (string) $db->GetOne('SELECT request_json FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ? FOR UPDATE', [$runKey, $projectIdentity]);
            $executionReadBackJson = (string) $db->GetOne("SELECT {$checkpointColumn} FROM phase_builder_todo_execution_logs WHERE execution_key = ? FOR UPDATE", [$executionKey]);
            $runReadBack = self::decodeJson($runReadBackJson, 'Coding Engine run request');
            $eventCount = (int) $db->GetOne("SELECT COUNT(*) FROM phase_builder_ai_run_event WHERE run_key = ? AND event_type = 'SERVER_SOURCE_CHECKPOINT_BOUND' AND stage_key = 'implementation'", [$runKey]);
            $auditCount = (int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ? AND action = 'UPDATE'", [$runKey]);
            $executionAuditCount = (int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'phase_builder_todo_execution_logs' AND record_key = ? AND action = 'UPDATE'", [$executionKey]);
            if (
                !is_array($runReadBack)
                || ($runReadBack['server_source_checkpoint'] ?? null) !== $checkpoint
                || $executionReadBackJson !== $checkpointJson
                || $eventCount < 1
                || $auditCount < 1
                || $executionAuditCount < 1
            ) {
                throw new RuntimeException('The server source checkpoint binding could not be read back.');
            }
            self::commitTransaction($db, 'Server source checkpoint binding');
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }

        return $this->read($runKey, $projectIdentity);
    }

    /**
     * @param array<string, mixed>|null $request
     * @param array<string, mixed>|null $result
     * @return array<string, mixed>
     */
    public function checkpoint(
        string $runKey,
        string $projectIdentity,
        string $stageKey,
        string $status,
        ?array $request,
        ?array $result,
        ?string $providerRequestId,
        ?string $errorCode,
        ?string $errorDetail,
        ?string $providerKey = null,
        ?string $modelKey = null
    ): array {
        $runKey = trim($runKey);
        $projectIdentity = strtolower(trim($projectIdentity));
        $stageKey = strtolower(trim($stageKey));
        $status = strtoupper(trim($status));
        $providerRequestId = trim((string) $providerRequestId) ?: null;
        $errorCode = strtoupper(trim((string) $errorCode)) ?: null;
        $errorDetail = trim((string) $errorDetail) ?: null;
        $providerKey = strtolower(trim((string) $providerKey)) ?: null;
        $modelKey = trim((string) $modelKey) ?: null;

        if (!self::validRecordKey($runKey) || !preg_match('/^[a-f0-9]{64}$/', $projectIdentity)) {
            throw new InvalidArgumentException('The AI run identity is invalid.');
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('The AI checkpoint status is invalid.');
        }
        if ($errorCode !== null && !in_array($errorCode, self::ERROR_CODES, true)) {
            throw new InvalidArgumentException('The AI checkpoint error code is invalid.');
        }
        if ($errorDetail !== null && strlen($errorDetail) > 2000) {
            throw new InvalidArgumentException('The AI checkpoint error detail is too large.');
        }
        if ($providerRequestId !== null && strlen($providerRequestId) > 200) {
            throw new InvalidArgumentException('The AI Bridge request identity is too large.');
        }
        if ($providerKey !== null && (strlen($providerKey) > 80 || preg_match('/^[a-z0-9._-]+$/', $providerKey) !== 1)) {
            throw new InvalidArgumentException('The AI provider identity is invalid.');
        }
        if ($modelKey !== null && (strlen($modelKey) > 120 || preg_match('/^[A-Za-z0-9._:\/-]+$/', $modelKey) !== 1)) {
            throw new InvalidArgumentException('The AI model identity is invalid.');
        }
        if (in_array($status, ['FAILED', 'EXPIRED'], true) && $errorCode === null) {
            throw new InvalidArgumentException('A failed or expired AI checkpoint requires an exact error code.');
        }
        if (!in_array($status, ['FAILED', 'CANCELLED', 'EXPIRED'], true) && ($errorCode !== null || $errorDetail !== null)) {
            throw new InvalidArgumentException('A successful AI checkpoint cannot contain an error.');
        }

        $requestJson = $request !== null ? self::encodeJson($request, 'AI stage request') : null;
        $resultJson = $result !== null ? self::encodeJson($result, 'AI stage result') : null;
        $db = \bx_db();
        self::beginTransaction($db, 'AI checkpoint');
        $transactionStarted = true;
        try {
            $run = $db->GetRow(
                'SELECT run_key, workflow_key, source_hash, status, worker_id FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ?' . $this->ownerSql() . ' FOR UPDATE',
                $this->ownerParams([$runKey, $projectIdentity])
            );
            if (!is_array($run) || !self::validRecordKey((string) ($run['run_key'] ?? ''))) {
                throw new RuntimeException('The persistent AI run was not found in the current project.');
            }
            $allowedStages = self::WORKFLOWS[(string) $run['workflow_key']]['stages'] ?? [];
            if (!in_array($stageKey, $allowedStages, true)) {
                throw new InvalidArgumentException('The AI run stage is invalid for this workflow.');
            }
            if (in_array((string) $run['status'], ['SUCCEEDED', 'CANCELLED', 'EXPIRED'], true)) {
                throw new RuntimeException('The AI run is already in a terminal state.');
            }

            $stage = $db->GetRow(
                'SELECT stage_record_key, status, attempt_count, max_attempts FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_key = ? FOR UPDATE',
                [$runKey, $stageKey]
            );
            if (!is_array($stage) || !self::validRecordKey((string) ($stage['stage_record_key'] ?? ''))) {
                throw new RuntimeException('The persistent AI stage was not found.');
            }
            $stageIndex = array_search($stageKey, $allowedStages, true);
            if (is_int($stageIndex) && $stageIndex > 0) {
                $incompleteEarlierStages = (int) $db->GetOne(
                    'SELECT COUNT(*) FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_order < ? AND status <> ?',
                    [$runKey, $stageIndex + 1, 'SUCCEEDED']
                );
                if ($incompleteEarlierStages > 0) {
                    throw new RuntimeException('The AI workflow cannot skip an incomplete earlier stage.');
                }
            }
            $currentStageStatus = (string) ($stage['status'] ?? '');
            if ($status === 'RUNNING' && $currentStageStatus === 'RUNNING' && $providerRequestId === null) {
                throw new RuntimeException('LOCK_CONFLICT: The active AI stage already has a server lease.');
            }
            self::assertStageTransition($currentStageStatus, $status);
            $attemptCount = (int) ($stage['attempt_count'] ?? 0);
            if ($status === 'RUNNING' && $currentStageStatus !== 'RUNNING') {
                if ($attemptCount >= (int) ($stage['max_attempts'] ?? 0)) {
                    throw new RuntimeException('The AI stage retry limit has been reached.');
                }
                $attemptCount++;
            }

            self::assertSaved($db->Execute(
                'UPDATE phase_builder_ai_run_stage SET status = ?, attempt_count = ?, request_json = COALESCE(?, request_json), result_json = COALESCE(?, result_json), provider_request_id = COALESCE(?, provider_request_id), error_code = ?, error_detail = ?, started_at = CASE WHEN ? = \'RUNNING\' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END, heartbeat_at = CURRENT_TIMESTAMP, completed_at = CASE WHEN ? IN (\'RUNNING\',\'VALIDATING\') THEN NULL WHEN ? IN (\'SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'EXPIRED\') THEN CURRENT_TIMESTAMP ELSE completed_at END WHERE run_key = ? AND stage_key = ?',
                [$status, $attemptCount, $requestJson, $resultJson, $providerRequestId, $errorCode, $errorDetail, $status, $status, $status, $runKey, $stageKey]
            ), 'AI stage checkpoint');

            self::assertSaved($db->Execute(
                'UPDATE phase_builder_ai_run_chunk SET status = ?, attempt_count = ?, request_json = COALESCE(?, request_json), result_json = COALESCE(?, result_json), error_code = ?, started_at = CASE WHEN ? = \'RUNNING\' AND started_at IS NULL THEN CURRENT_TIMESTAMP ELSE started_at END, completed_at = CASE WHEN ? IN (\'RUNNING\',\'VALIDATING\') THEN NULL WHEN ? IN (\'SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'EXPIRED\') THEN CURRENT_TIMESTAMP ELSE completed_at END WHERE run_key = ? AND stage_key = ? AND chunk_order = 1',
                [$status, $attemptCount, $requestJson, $resultJson, $errorCode, $status, $status, $status, $runKey, $stageKey]
            ), 'AI chunk checkpoint');
            $chunkKey = (string) $db->GetOne(
                'SELECT chunk_key FROM phase_builder_ai_run_chunk WHERE run_key = ? AND stage_key = ? AND chunk_order = 1',
                [$runKey, $stageKey]
            );
            if ($chunkKey === '') {
                throw new RuntimeException('The persistent AI chunk identity could not be read back.');
            }

            $lastStage = end($allowedStages);
            $runStatus = $status === 'FAILED' ? 'FAILED' : ($status === 'CANCELLED' ? 'CANCELLED' : ($status === 'EXPIRED' ? 'EXPIRED' : ($stageKey === $lastStage && $status === 'SUCCEEDED' ? 'SUCCEEDED' : 'RUNNING')));
            $workerId = in_array($status, ['RUNNING', 'VALIDATING'], true)
                ? ($providerRequestId !== null
                    ? 'bridge:' . $providerRequestId
                    : (trim((string) ($run['worker_id'] ?? '')) ?: 'stage:' . substr(hash('sha256', $runKey . ':' . $stageKey), 0, 64)))
                : null;
            self::assertSaved($db->Execute(
                'UPDATE phase_builder_ai_run SET stage_key = ?, status = ?, attempt = GREATEST(attempt, ?), provider_key = COALESCE(?, provider_key), model_key = COALESCE(?, model_key), provider_request_id = COALESCE(?, provider_request_id), worker_id = ?, locked_until = CASE WHEN ? IN (\'RUNNING\',\'VALIDATING\') THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL ' . self::STAGE_LEASE_SECONDS . ' SECOND) ELSE NULL END, result_json = CASE WHEN ? = \'SUCCEEDED\' AND ? = ? THEN COALESCE(?, result_json) ELSE result_json END, error_code = ?, error_detail = ?, started_at = COALESCE(started_at, CURRENT_TIMESTAMP), heartbeat_at = CURRENT_TIMESTAMP, completed_at = CASE WHEN ? = \'RUNNING\' THEN NULL WHEN ? IN (\'SUCCEEDED\',\'FAILED\',\'CANCELLED\',\'EXPIRED\') THEN CURRENT_TIMESTAMP ELSE completed_at END WHERE run_key = ? AND project_identity = ?',
                [$stageKey, $runStatus, $attemptCount, $providerKey, $modelKey, $providerRequestId, $workerId, $status, $status, $stageKey, $lastStage, $resultJson, $errorCode, $errorDetail, $runStatus, $runStatus, $runKey, $projectIdentity]
            ), 'AI run checkpoint');

            $this->insertEvent($runKey, $stageKey, $chunkKey, 'STAGE_CHECKPOINT', $status, self::eventMessage($stageKey, $status, $errorCode), [
                'attempt' => $attemptCount,
                'provider_request_id' => $providerRequestId,
                'provider_key' => $providerKey,
                'model_key' => $modelKey,
                'error_code' => $errorCode,
            ]);
            \bx_audit('UPDATE', 'phase_builder_ai_run', $runKey, [
                'stage_key' => $stageKey,
                'stage_status' => $status,
                'run_status' => $runStatus,
                'attempt' => $attemptCount,
                'provider_key' => $providerKey,
                'model_key' => $modelKey,
                'error_code' => $errorCode,
            ]);
            $this->assertCheckpointReadBack($runKey, $projectIdentity, $stageKey, $status, $runStatus, $attemptCount, $errorCode, $providerKey, $modelKey, $providerRequestId, $workerId);
            self::commitTransaction($db, 'AI checkpoint');
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }

        return $this->read($runKey, $projectIdentity);
    }

    /** @return array<string, mixed> */
    public function read(string $runKey, string $projectIdentity): array
    {
        if (!self::validRecordKey(trim($runKey)) || preg_match('/^[a-f0-9]{64}$/', strtolower(trim($projectIdentity))) !== 1) {
            throw new InvalidArgumentException('The AI run identity is invalid.');
        }
        $runKey = trim($runKey);
        $projectIdentity = strtolower(trim($projectIdentity));
        $this->expireTimedOutStage($runKey, $projectIdentity);
        $db = \bx_db();
        $run = $db->GetRow(
            'SELECT run_key, project_identity, engine_type, workflow_key, route_key, stage_key, draft_key, phase_key, task_id, subtask_id, todo_id, source_hash, request_version, status, attempt, max_attempts, provider_key, model_key, provider_request_id, worker_id, locked_until, request_json, result_json, error_code, error_detail, created_by_user_key, created_at, started_at, heartbeat_at, completed_at FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ?' . $this->ownerSql() . ' LIMIT 1',
            $this->ownerParams([$runKey, $projectIdentity])
        );
        if (!is_array($run) || !self::validRecordKey((string) ($run['run_key'] ?? ''))) {
            throw new RuntimeException('The persistent AI run was not found in the current project.');
        }
        $stages = $db->GetAll(
            'SELECT stage_key, stage_order, status, attempt_count, max_attempts, request_json, result_json, provider_request_id, error_code, error_detail, started_at, heartbeat_at, completed_at FROM phase_builder_ai_run_stage WHERE run_key = ? ORDER BY stage_order, x_id',
            [$runKey]
        );
        if (!is_array($stages)) {
            throw new RuntimeException('The persistent AI stage read-back failed.');
        }
        $chunks = $db->GetAll(
            'SELECT chunk_key, stage_key, chunk_type, chunk_order, source_hash, status, attempt_count, request_json, result_json, error_code, started_at, completed_at FROM phase_builder_ai_run_chunk WHERE run_key = ? ORDER BY chunk_order, x_id',
            [$runKey]
        );
        if (!is_array($chunks)) {
            throw new RuntimeException('The persistent AI chunk read-back failed.');
        }
        $events = $db->GetAll(
            'SELECT event_key, stage_key, chunk_key, event_type, status, message, payload_json, created_at FROM phase_builder_ai_run_event WHERE run_key = ? ORDER BY x_id',
            [$runKey]
        );
        if (!is_array($events)) {
            throw new RuntimeException('The persistent AI event read-back failed.');
        }
        $run['request'] = self::decodeJson((string) ($run['request_json'] ?? ''));
        $run['result'] = self::decodeJson((string) ($run['result_json'] ?? ''));
        unset($run['request_json'], $run['result_json']);
        foreach ($stages as &$stage) {
            $stage['request'] = self::decodeJson((string) ($stage['request_json'] ?? ''));
            $stage['result'] = self::decodeJson((string) ($stage['result_json'] ?? ''));
            unset($stage['request_json'], $stage['result_json']);
        }
        unset($stage);
        foreach ($chunks as &$chunk) {
            $chunk['request'] = self::decodeJson((string) ($chunk['request_json'] ?? ''), 'AI chunk request');
            $chunk['result'] = self::decodeJson((string) ($chunk['result_json'] ?? ''), 'AI chunk result');
            unset($chunk['request_json'], $chunk['result_json']);
        }
        unset($chunk);
        foreach ($events as &$event) {
            $event['payload'] = self::decodeJson((string) ($event['payload_json'] ?? ''));
            unset($event['payload_json']);
        }
        unset($event);
        $run['stages'] = $stages;
        $run['chunks'] = $chunks;
        $run['events'] = $events;
        return $run;
    }

    /** @return array<string, mixed>|null */
    public function latest(string $workflowKey, string $draftKey, string $projectIdentity): ?array
    {
        $row = \bx_db()->GetRow(
            'SELECT run_key FROM phase_builder_ai_run WHERE workflow_key = ? AND draft_key = ? AND project_identity = ?' . $this->ownerSql() . ' ORDER BY x_id DESC LIMIT 1',
            $this->ownerParams([$workflowKey, $draftKey, $projectIdentity])
        );
        return is_array($row) && self::validRecordKey((string) ($row['run_key'] ?? ''))
            ? $this->read((string) $row['run_key'], $projectIdentity)
            : null;
    }

    /** @param array<string, mixed> $payload */
    private function insertEvent(string $runKey, ?string $stageKey, ?string $chunkKey, string $eventType, string $status, string $message, array $payload): void
    {
        self::assertSaved(\bx_db()->Execute(
            'INSERT INTO phase_builder_ai_run_event (event_key, run_key, stage_key, chunk_key, event_type, status, message, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [\bx_uuid(), $runKey, $stageKey, $chunkKey, $eventType, $status, $message, self::encodeJson($payload, 'AI event payload')]
        ), 'AI run event creation');
    }

    private static function eventMessage(string $stageKey, string $status, ?string $errorCode): string
    {
        if ($status === 'FAILED') {
            return sprintf('%s failed with %s.', $stageKey, $errorCode ?: 'PERSISTENCE_FAILED');
        }
        return sprintf('%s checkpoint saved as %s.', $stageKey, $status);
    }

    private static function validRecordKey(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,36}$/', $value) === 1;
    }

    private static function optionalScopeId(mixed $value, string $label): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 200 || preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('The AI run %s scope is invalid.', $label));
        }
        return $value;
    }

    /** @param array<string, mixed> $request */
    private static function chunkKey(string $workflowKey, string $stageKey, array $request): string
    {
        $semanticKey = strtolower(trim((string) ($request['semantic_chunk_key'] ?? '')));
        if ($semanticKey !== '' && preg_match('/^[a-z0-9._:-]{1,80}$/', $semanticKey) !== 1) {
            throw new InvalidArgumentException('The AI run semantic chunk identity is invalid.');
        }
        if ($semanticKey === '' || !in_array($workflowKey, ['system_architecture', 'ui_ux_design', 'execution_roadmap', 'todo_consolidation', 'todo_execution', 'todo_rollback', 'bridge_diagnostic'], true)) {
            return $stageKey . '-001';
        }
        return $stageKey . '-' . $semanticKey;
    }

    /** @param array<string, mixed> $value */
    private static function encodeJson(array $value, string $label): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 10_000_000) {
            throw new InvalidArgumentException($label . ' is too large.');
        }
        return $json;
    }

    /** @return array<string, mixed>|null */
    private static function decodeJson(string $json, string $label = 'AI JSON'): ?array
    {
        if (trim($json) === '') {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException($label . ' read-back is invalid.', 0, $error);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException($label . ' read-back is invalid.');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $existing */
    private function assertIdempotentMatch(array $existing, string $engineType, string $workflowKey, string $routeKey, string $draftKey, ?string $phaseKey, string $sourceHash, string $userKey): void
    {
        if (
            (string) ($existing['engine_type'] ?? '') !== $engineType
            || (string) ($existing['workflow_key'] ?? '') !== $workflowKey
            || (string) ($existing['route_key'] ?? '') !== $routeKey
            || (string) ($existing['draft_key'] ?? '') !== $draftKey
            || ((string) ($existing['phase_key'] ?? '') ?: null) !== $phaseKey
            || (string) ($existing['source_hash'] ?? '') !== $sourceHash
            || (string) ($existing['created_by_user_key'] ?? '') !== $userKey
        ) {
            throw new RuntimeException('The AI run idempotency key was already used for a different request.');
        }
    }

    private static function assertStageTransition(string $from, string $to): void
    {
        $allowed = [
            'QUEUED' => ['RUNNING', 'CANCELLED', 'EXPIRED'],
            'RUNNING' => ['RUNNING', 'VALIDATING', 'SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'],
            'VALIDATING' => ['VALIDATING', 'SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'],
            'FAILED' => ['RUNNING', 'CANCELLED', 'EXPIRED'],
        ];
        if (!in_array($to, $allowed[$from] ?? [], true)) {
            throw new RuntimeException(sprintf('The AI stage transition from %s to %s is not allowed.', $from, $to));
        }
    }

    private function assertCreatedReadBack(string $runKey, string $projectIdentity, string $workflowKey, string $routeKey, string $sourceHash, string $userKey): void
    {
        $db = \bx_db();
        $run = $db->GetRow('SELECT run_key, project_identity, workflow_key, route_key, source_hash, status, created_by_user_key FROM phase_builder_ai_run WHERE run_key = ? FOR UPDATE', [$runKey]);
        $stageCount = (int) $db->GetOne('SELECT COUNT(*) FROM phase_builder_ai_run_stage WHERE run_key = ?', [$runKey]);
        $chunkCount = (int) $db->GetOne('SELECT COUNT(*) FROM phase_builder_ai_run_chunk WHERE run_key = ?', [$runKey]);
        $eventCount = (int) $db->GetOne('SELECT COUNT(*) FROM phase_builder_ai_run_event WHERE run_key = ?', [$runKey]);
        $auditCount = (int) $db->GetOne("SELECT COUNT(*) FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ? AND action = 'CREATE'", [$runKey]);
        $expectedCount = count(self::WORKFLOWS[$workflowKey]['stages']);
        if (
            !is_array($run)
            || (string) ($run['run_key'] ?? '') !== $runKey
            || (string) ($run['project_identity'] ?? '') !== $projectIdentity
            || (string) ($run['route_key'] ?? '') !== $routeKey
            || (string) ($run['source_hash'] ?? '') !== $sourceHash
            || (string) ($run['created_by_user_key'] ?? '') !== $userKey
            || (string) ($run['status'] ?? '') !== 'QUEUED'
            || $stageCount !== $expectedCount
            || $chunkCount !== $expectedCount
            || $eventCount !== 1
            || $auditCount !== 1
        ) {
            throw new RuntimeException('Persistent AI run creation read-back failed.');
        }
    }

    private function assertCheckpointReadBack(string $runKey, string $projectIdentity, string $stageKey, string $stageStatus, string $runStatus, int $attemptCount, ?string $errorCode, ?string $providerKey, ?string $modelKey, ?string $providerRequestId, ?string $workerId): void
    {
        $db = \bx_db();
        $run = $db->GetRow('SELECT status, stage_key, project_identity, provider_key, model_key, provider_request_id, worker_id, locked_until, CASE WHEN locked_until > CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS lock_active, error_code FROM phase_builder_ai_run WHERE run_key = ? FOR UPDATE', [$runKey]);
        $stage = $db->GetRow('SELECT status, attempt_count, provider_request_id, error_code FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_key = ? FOR UPDATE', [$runKey, $stageKey]);
        $chunk = $db->GetRow('SELECT status, attempt_count, error_code FROM phase_builder_ai_run_chunk WHERE run_key = ? AND stage_key = ? AND chunk_order = 1 FOR UPDATE', [$runKey, $stageKey]);
        $event = $db->GetRow('SELECT status, stage_key FROM phase_builder_ai_run_event WHERE run_key = ? ORDER BY x_id DESC LIMIT 1', [$runKey]);
        $audit = $db->GetRow("SELECT action, module, record_key FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ? ORDER BY x_id DESC LIMIT 1", [$runKey]);
        if (
            !is_array($run)
            || !is_array($stage)
            || !is_array($chunk)
            || !is_array($event)
            || !is_array($audit)
            || (string) ($run['project_identity'] ?? '') !== $projectIdentity
            || (string) ($run['stage_key'] ?? '') !== $stageKey
            || (string) ($run['status'] ?? '') !== $runStatus
            || ((string) ($run['error_code'] ?? '') ?: null) !== $errorCode
            || ($providerKey !== null && (string) ($run['provider_key'] ?? '') !== $providerKey)
            || ($modelKey !== null && (string) ($run['model_key'] ?? '') !== $modelKey)
            || ($providerRequestId !== null && (string) ($run['provider_request_id'] ?? '') !== $providerRequestId)
            || ($workerId !== null && ((string) ($run['worker_id'] ?? '') !== $workerId || (int) ($run['lock_active'] ?? 0) !== 1))
            || ($workerId === null && (((string) ($run['worker_id'] ?? '')) !== '' || $run['locked_until'] !== null))
            || (string) ($stage['status'] ?? '') !== $stageStatus
            || (int) ($stage['attempt_count'] ?? -1) !== $attemptCount
            || ((string) ($stage['error_code'] ?? '') ?: null) !== $errorCode
            || ($providerRequestId !== null && (string) ($stage['provider_request_id'] ?? '') !== $providerRequestId)
            || (string) ($chunk['status'] ?? '') !== $stageStatus
            || (int) ($chunk['attempt_count'] ?? -1) !== $attemptCount
            || ((string) ($chunk['error_code'] ?? '') ?: null) !== $errorCode
            || (string) ($event['stage_key'] ?? '') !== $stageKey
            || (string) ($event['status'] ?? '') !== $stageStatus
            || (string) ($audit['action'] ?? '') !== 'UPDATE'
            || (string) ($audit['record_key'] ?? '') !== $runKey
        ) {
            throw new RuntimeException('Persistent AI checkpoint read-back failed.');
        }
    }

    private function expireTimedOutStage(string $runKey, string $projectIdentity): void
    {
        $row = \bx_db()->GetRow(
            'SELECT stage_key, status, CASE WHEN locked_until IS NOT NULL AND locked_until <= CURRENT_TIMESTAMP THEN 1 ELSE 0 END AS timed_out FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ?' . $this->ownerSql() . ' LIMIT 1',
            $this->ownerParams([$runKey, $projectIdentity])
        );
        if (
            !is_array($row)
            || (int) ($row['timed_out'] ?? 0) !== 1
            || !in_array((string) ($row['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)
        ) {
            return;
        }
        try {
            $this->checkpoint(
                $runKey,
                $projectIdentity,
                (string) ($row['stage_key'] ?? ''),
                'EXPIRED',
                null,
                null,
                null,
                'STAGE_TIMEOUT',
                'The active AI stage exceeded its bounded server lease.'
            );
        } catch (RuntimeException $error) {
            $current = \bx_db()->GetRow(
                'SELECT status, locked_until FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ?' . $this->ownerSql() . ' LIMIT 1',
                $this->ownerParams([$runKey, $projectIdentity])
            );
            if (
                is_array($current)
                && (!in_array((string) ($current['status'] ?? ''), ['RUNNING', 'VALIDATING'], true) || $current['locked_until'] === null)
            ) {
                return;
            }
            throw $error;
        }
    }

    private static function beginTransaction(object $db, string $operation): void
    {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException($operation . ' transaction could not start.');
        }
    }

    private static function commitTransaction(object $db, string $operation): void
    {
        if ($db->CommitTrans() === false) {
            throw new RuntimeException($operation . ' transaction could not commit.');
        }
    }

    private static function assertSaved(mixed $result, string $operation): void
    {
        if ($result !== false) {
            return;
        }
        $error = trim((string) \bx_db()->ErrorMsg());
        throw new RuntimeException($operation . ' failed' . ($error !== '' ? ': ' . $error : '.'));
    }

    private function ownerSql(): string
    {
        return $this->ownerUserKey !== null ? ' AND created_by_user_key = ?' : '';
    }

    /** @param list<mixed> $params @return list<mixed> */
    private function ownerParams(array $params): array
    {
        if ($this->ownerUserKey !== null) {
            $params[] = $this->ownerUserKey;
        }
        return $params;
    }
}
