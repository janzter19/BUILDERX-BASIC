<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PhaseAiContextStore
{
    public function __construct(private readonly ?string $ownerUserKey = null)
    {
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function save(string $contextKey, string $projectIdentity, array $context): array
    {
        self::assertContextKey($contextKey);
        self::assertProjectIdentity($projectIdentity);
        if (array_is_list($context)) {
            throw new InvalidArgumentException('The AI context must be a JSON object.');
        }
        $json = self::encode($context, 'AI context');
        if (strlen($json) < 2 || strlen($json) > 10_000_000) {
            throw new InvalidArgumentException('The AI context must contain 2 to 10,000,000 bytes.');
        }
        $contextType = trim((string) ($context['context_type'] ?? $context['schemaVersion'] ?? $context['schema_version'] ?? 'builderx.ai-context.v1'));
        $contextType = substr($contextType !== '' ? $contextType : 'builderx.ai-context.v1', 0, 120);
        $sha256 = hash('sha256', $json);
        $db = \bx_db();
        $transactionStarted = false;
        try {
            if ($db->BeginTrans() === false) {
                throw new RuntimeException('The AI context transaction could not start.');
            }
            $transactionStarted = true;
            $existing = $db->GetRow('SELECT project_identity FROM phase_builder_ai_context WHERE context_key = ? FOR UPDATE', [$contextKey]);
            $hasExisting = is_array($existing) && $existing !== [];
            if ($hasExisting && !hash_equals((string) ($existing['project_identity'] ?? ''), $projectIdentity)) {
                throw new RuntimeException('The AI context identity belongs to another project.');
            }
            $saved = $hasExisting
                ? $db->Execute('UPDATE phase_builder_ai_context SET context_type = ?, context_json = ?, byte_size = ?, sha256 = ?, created_by_user_key = COALESCE(created_by_user_key, ?) WHERE context_key = ? AND project_identity = ?', [$contextType, $json, strlen($json), $sha256, $this->owner(), $contextKey, $projectIdentity])
                : $db->Execute('INSERT INTO phase_builder_ai_context (context_key, project_identity, context_type, context_json, byte_size, sha256, created_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?)', [$contextKey, $projectIdentity, $contextType, $json, strlen($json), $sha256, $this->owner()]);
            self::assertSaved($saved, 'AI context persistence');
            \bx_audit($hasExisting ? 'UPDATE' : 'CREATE', 'phase_builder_ai_context', substr(hash('sha256', $contextKey), 0, 36), ['context_key' => $contextKey, 'project_identity' => $projectIdentity, 'sha256' => $sha256, 'byte_size' => strlen($json)]);
            $readBack = $db->GetRow('SELECT context_key, project_identity, context_type, byte_size, sha256 FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ? FOR UPDATE', [$contextKey, $projectIdentity]);
            if (!is_array($readBack) || (int) ($readBack['byte_size'] ?? 0) !== strlen($json) || !hash_equals($sha256, (string) ($readBack['sha256'] ?? ''))) {
                throw new RuntimeException('The AI context read-back did not match the saved context.');
            }
            if ($db->CommitTrans() === false) {
                throw new RuntimeException('The AI context transaction could not commit.');
            }
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
        return [
            'context_id' => $contextKey,
            'context_ref' => 'mysql:phase_builder_ai_context/' . $contextKey,
            'bytes' => strlen($json),
            'sha256' => $sha256,
        ];
    }

    /** @return array<string, mixed> */
    public function read(string $contextKey, string $projectIdentity): array
    {
        self::assertContextKey($contextKey);
        self::assertProjectIdentity($projectIdentity);
        $row = \bx_db()->GetRow('SELECT context_key, project_identity, context_type, context_json, byte_size, sha256, created_at, updated_at FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ? LIMIT 1', [$contextKey, $projectIdentity]);
        if (!is_array($row) || $row === []) {
            throw new RuntimeException('The MySQL AI context was not found in the current project.');
        }
        $json = (string) ($row['context_json'] ?? '');
        if ((int) ($row['byte_size'] ?? -1) !== strlen($json) || !hash_equals((string) ($row['sha256'] ?? ''), hash('sha256', $json))) {
            throw new RuntimeException('The MySQL AI context failed its size or hash read-back.');
        }
        $row['context'] = self::decode($json, 'AI context');
        unset($row['context_json']);
        return $row;
    }

    /** @return array<string, mixed> */
    public function verify(string $contextKey, string $projectIdentity, int $bytes, string $sha256): array
    {
        $row = $this->read($contextKey, $projectIdentity);
        if ($bytes < 2 || $bytes !== (int) ($row['byte_size'] ?? 0) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !hash_equals($sha256, (string) ($row['sha256'] ?? ''))) {
            throw new RuntimeException('The MySQL AI context checkpoint does not match its saved size and hash.');
        }
        return $row;
    }

    private function owner(): ?string
    {
        $owner = trim((string) $this->ownerUserKey);
        return $owner !== '' ? $owner : null;
    }

    private static function assertContextKey(string $value): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,160}$/', trim($value)) !== 1) {
            throw new InvalidArgumentException('The AI context identity is invalid.');
        }
    }

    private static function assertProjectIdentity(string $value): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', strtolower(trim($value))) !== 1) {
            throw new InvalidArgumentException('The AI project identity is invalid.');
        }
    }

    /** @param array<string, mixed> $value */
    private static function encode(array $value, string $label): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new InvalidArgumentException($label . ' could not be encoded.', 0, $error);
        }
    }

    /** @return array<string, mixed> */
    private static function decode(string $value, string $label): array
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException($label . ' is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($label . ' must be a JSON object.');
        }
        return $decoded;
    }

    private static function assertSaved(mixed $result, string $operation): void
    {
        if ($result === false) {
            $message = trim((string) \bx_db()->ErrorMsg());
            throw new RuntimeException($operation . ' failed' . ($message !== '' ? ': ' . $message : '.'));
        }
    }
}

final class PhaseAiJobStore
{
    private const ACTIVE = ['QUEUED', 'RUNNING'];
    private const TERMINAL = ['SUCCEEDED', 'FAILED', 'CANCELLED', 'EXPIRED'];

    public function __construct(private readonly string $projectRoot, private readonly ?string $ownerUserKey = null)
    {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException('The AI job project root is unavailable.');
        }
    }

    /** @return array<string, mixed> */
    public function queue(string $runKey, string $projectIdentity, string $stageKey, string $instruction, bool $allowSourceChanges): array
    {
        self::assertJobScope($runKey, $projectIdentity, $stageKey);
        $instruction = trim($instruction);
        if ($instruction === '' || strlen($instruction) > 20_000) {
            throw new InvalidArgumentException('The AI job instruction must contain 1 to 20,000 bytes.');
        }
        $db = \bx_db();
        $transactionStarted = false;
        try {
            if ($db->BeginTrans() === false) {
                throw new RuntimeException('The AI job transaction could not start.');
            }
            $transactionStarted = true;
            $run = $db->GetRow('SELECT engine_type, workflow_key, created_by_user_key FROM phase_builder_ai_run WHERE run_key = ? AND project_identity = ? FOR UPDATE', [$runKey, $projectIdentity]);
            $stage = $db->GetRow('SELECT status FROM phase_builder_ai_run_stage WHERE run_key = ? AND stage_key = ? FOR UPDATE', [$runKey, $stageKey]);
            if (!is_array($run) || $run === [] || !is_array($stage) || $stage === [] || !in_array((string) ($stage['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
                throw new RuntimeException('The AI job is not bound to a running persisted stage.');
            }
            $owner = trim((string) $this->ownerUserKey);
            if ($owner !== '' && !hash_equals($owner, (string) ($run['created_by_user_key'] ?? ''))) {
                throw new RuntimeException('The AI job run belongs to another administrator.');
            }
            $existing = $db->GetRow('SELECT job_key, status FROM phase_builder_ai_job WHERE run_key = ? AND stage_key = ? FOR UPDATE', [$runKey, $stageKey]);
            if (is_array($existing) && preg_match('/^[0-9a-f-]{36}$/', (string) ($existing['job_key'] ?? '')) === 1) {
                if (in_array((string) ($existing['status'] ?? ''), self::TERMINAL, true)) {
                    self::assertSaved($db->Execute('UPDATE phase_builder_ai_job SET execution_mode = ?, instruction_text = ?, status = ?, claim_count = 0, worker_id = NULL, result_json = NULL, error_code = NULL, error_detail = NULL, claimed_at = NULL, heartbeat_at = NULL, completed_at = NULL WHERE job_key = ? AND project_identity = ?', [$allowSourceChanges ? 'coding_implementation' : 'read_only', $instruction, 'QUEUED', $existing['job_key'], $projectIdentity]), 'AI job retry');
                    \bx_audit('UPDATE', 'phase_builder_ai_job', (string) $existing['job_key'], ['status' => 'QUEUED', 'retry' => true]);
                }
                if ($db->CommitTrans() === false) {
                    throw new RuntimeException('The AI job transaction could not commit.');
                }
                $transactionStarted = false;
                return $this->read((string) $existing['job_key'], $projectIdentity);
            }
            $jobKey = \bx_uuid();
            $saved = $db->Execute(
                'INSERT INTO phase_builder_ai_job (job_key, run_key, stage_key, project_identity, engine_type, workflow_key, execution_mode, instruction_text, status, created_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$jobKey, $runKey, $stageKey, $projectIdentity, $run['engine_type'], $run['workflow_key'], $allowSourceChanges ? 'coding_implementation' : 'read_only', $instruction, 'QUEUED', $owner !== '' ? $owner : ($run['created_by_user_key'] ?? null)]
            );
            self::assertSaved($saved, 'AI job creation');
            \bx_audit('CREATE', 'phase_builder_ai_job', $jobKey, ['run_key' => $runKey, 'stage_key' => $stageKey, 'execution_mode' => $allowSourceChanges ? 'coding_implementation' : 'read_only']);
            $readBack = $db->GetRow('SELECT job_key, status FROM phase_builder_ai_job WHERE job_key = ? AND project_identity = ? FOR UPDATE', [$jobKey, $projectIdentity]);
            if (!is_array($readBack) || (string) ($readBack['status'] ?? '') !== 'QUEUED') {
                throw new RuntimeException('The queued AI job could not be read back.');
            }
            if ($db->CommitTrans() === false) {
                throw new RuntimeException('The AI job transaction could not commit.');
            }
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
        return $this->read($jobKey, $projectIdentity);
    }

    /** @return array<string, mixed> */
    public function claim(string $jobKey): array
    {
        self::assertJobKey($jobKey);
        $projectIdentity = hash('sha256', self::normalizedRoot($this->projectRoot));
        $db = \bx_db();
        $transactionStarted = false;
        try {
            if ($db->BeginTrans() === false) {
                throw new RuntimeException('The AI job claim transaction could not start.');
            }
            $transactionStarted = true;
            $row = $db->GetRow(
                'SELECT j.job_key, j.run_key, j.stage_key, j.project_identity, j.engine_type, j.workflow_key, j.execution_mode, j.instruction_text, j.status, r.request_json AS run_request_json, s.request_json AS stage_request_json FROM phase_builder_ai_job j JOIN phase_builder_ai_run r ON r.run_key = j.run_key JOIN phase_builder_ai_run_stage s ON s.run_key = j.run_key AND s.stage_key = j.stage_key WHERE j.job_key = ? AND j.project_identity = ? FOR UPDATE',
                [$jobKey, $projectIdentity]
            );
            if (!is_array($row) || $row === []) {
                throw new RuntimeException('The MySQL AI job was not found for this workspace.');
            }
            if (in_array((string) ($row['status'] ?? ''), self::TERMINAL, true)) {
                throw new RuntimeException('The MySQL AI job is already complete.');
            }
            $workerId = 'vscode:' . substr(hash('sha256', self::normalizedRoot($this->projectRoot)), 0, 24);
            self::assertSaved($db->Execute('UPDATE phase_builder_ai_job SET status = ?, claim_count = claim_count + 1, worker_id = ?, claimed_at = COALESCE(claimed_at, CURRENT_TIMESTAMP), heartbeat_at = CURRENT_TIMESTAMP WHERE job_key = ? AND project_identity = ?', ['RUNNING', $workerId, $jobKey, $projectIdentity]), 'AI job claim');
            \bx_audit('UPDATE', 'phase_builder_ai_job', $jobKey, ['status' => 'RUNNING', 'worker_id' => $workerId]);
            $readStatus = (string) $db->GetOne('SELECT status FROM phase_builder_ai_job WHERE job_key = ? AND project_identity = ? FOR UPDATE', [$jobKey, $projectIdentity]);
            if ($readStatus !== 'RUNNING') {
                throw new RuntimeException('The claimed AI job could not be read back.');
            }
            if ($db->CommitTrans() === false) {
                throw new RuntimeException('The AI job claim transaction could not commit.');
            }
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
        $runRequest = self::decodeObject((string) ($row['run_request_json'] ?? ''), 'AI run request');
        $stageRequest = self::decodeObject((string) ($row['stage_request_json'] ?? ''), 'AI stage request');
        $priorRows = $db->GetAll('SELECT stage_key, result_json FROM phase_builder_ai_run_stage WHERE run_key = ? AND status = ? ORDER BY stage_order, x_id', [(string) $row['run_key'], 'SUCCEEDED']);
        if (!is_array($priorRows)) {
            throw new RuntimeException('The prior MySQL AI stage results could not be read.');
        }
        $priorStageResults = [];
        foreach ($priorRows as $priorRow) {
            $priorStageResults[(string) ($priorRow['stage_key'] ?? '')] = self::decodeObject((string) ($priorRow['result_json'] ?? ''), 'Prior AI stage result');
        }
        $contexts = $this->loadContexts($runRequest, $stageRequest, $priorStageResults, $projectIdentity);
        $promptPayload = [
            'schemaVersion' => 'builderx.mysql-ai-job.v1',
            'jobKey' => $jobKey,
            'engineType' => $row['engine_type'],
            'workflowKey' => $row['workflow_key'],
            'stageKey' => $row['stage_key'],
            'executionMode' => $row['execution_mode'],
            'runRequest' => $runRequest,
            'stageRequest' => $stageRequest,
            'priorStageResults' => $priorStageResults,
            'contexts' => $contexts,
        ];
        $workflowKey = (string) ($row['workflow_key'] ?? '');
        $planningPolicyPrompt = PhaseBuilderPlanningPolicy::prompt($workflowKey);
        if ($planningPolicyPrompt !== '') {
            $promptPayload['phaseBuilderPolicy'] = PhaseBuilderPlanningPolicy::context($workflowKey);
        }
        $stageContractPrompt = (string) ($row['stage_key'] ?? '') === 'integration_review'
            ? implode("\n", [
                'BUILDERX_INTEGRATION_REVIEW_RESULT_CONTRACT',
                'Return exactly: {"schemaVersion":"builderx.ai-integration-review.v1","status":"approved|blocked","findings":[]}.',
                'Each finding may contain only code, summary, requiredResolution, and relatedArea.',
                'Use findings for both genuine blockers and optional observations; the server applies the finite blocker policy and converts optional observations into coding-time suggestions.',
                'Do not rename findings to blockingFindings or nonBlockingSuggestions.',
            ])
            : '';
        $executionMode = (string) ($row['execution_mode'] ?? '');
        $engineType = strtoupper((string) ($row['engine_type'] ?? ''));
        $isCodingWorkflow = $engineType === 'CODING' || in_array($workflowKey, ['todo_execution', 'todo_rollback'], true);
        $workspacePolicyPrompt = $isCodingWorkflow
            ? implode("\n", [
                'BuilderX supplied the complete, authoritative, server-read-back scope context below from MySQL. Every run key, stage result, context value, and artifact hash in this payload is already bound and verified by BuilderX.',
                'For this Coding Engine stage, use the supplied context as the scope authority, then inspect the current project workspace, source tree, database paths, Android paths, logs, and focused test/build commands only as required by the requested stage.',
                'For inspection, plan, verification, evidence, and git_update checkpoints, return exact camelCase keys: schemaVersion, workflowKey, stage, status, evidence. Do not use schema_version, workflow_key, job_key, tests_run, or other snake_case contract keys.',
                $executionMode === 'coding_implementation'
                    ? 'Source or Android edits are allowed only inside the selected todo scope and only when the stage instruction requires them. Preserve unrelated changes and do not modify BuilderX Phase Manager control-plane files.'
                    : 'This is a read-only Coding Engine stage: do not edit files, write product data, change the database, run migrations, install dependencies, or make Git changes.',
                'Classify blockers narrowly. Dirty worktree changes block only when they cannot be preserved, conflict with the required edit hunk, or prevent safe attribution after reading the current diff; same-file overlap alone is not a blocker. Otherwise preserve and list dirty changes as non-blocking evidence. Missing database rollback protection blocks only when actual schema or data writes are required; do not block source-only work because database impact is merely possible.',
                'If you discover a confirmed product, scope, permission, rollback, dependency, or verification blocker, do not use the fail helper. Return the requested JSON through the complete helper with status "blocked" and evidence explaining the blocker.',
                'Return exactly one JSON object matching the requested stage contract. When complete, pass the JSON object as the final quoted argument to:',
                'php tools/builderx-ai-job.php complete ' . $jobKey . " '<JSON_OBJECT>'",
                'Use the fail helper only for transport or helper execution failures where no valid JSON stage result can be produced. If that happens, pass a short failure reason as the final quoted argument to:',
                'php tools/builderx-ai-job.php fail ' . $jobKey . " '<FAILURE_REASON>'",
                'Run the complete or fail helper once after the stage work is finished, without sudo, sandbox escalation, a pipeline, or a shell wrapper. Keep the JSON object inside one shell-quoted argument, and do not add backslashes before its double quotes merely because the outer shell quotes are single quotes. After the helper returns ok:true, do not run another command.',
                'Do not create a BuilderX result JSON file.',
            ])
            : implode("\n", [
                'BuilderX supplied the complete, authoritative, server-read-back context below from MySQL. Every run key, stage result, context value, and artifact hash in this payload is already bound and verified by BuilderX.',
                'Do not independently re-read or verify this payload. Do not inspect the database, source tree, helper implementation, skills, memory, logs, or any workspace file. Do not run php -r, mysql, rg, grep, find, cat, or any exploratory command. A secondary lookup is an error, not additional verification.',
                'Return exactly one JSON object matching the requested stage contract. When complete, pass the JSON object as the final quoted argument to:',
                'php tools/builderx-ai-job.php complete ' . $jobKey . " '<JSON_OBJECT>'",
                'If the stage cannot be completed, pass a short failure reason as the final quoted argument to:',
                'php tools/builderx-ai-job.php fail ' . $jobKey . " '<FAILURE_REASON>'",
                'Use no command or tool before the final complete or fail helper. Run that helper once as an ordinary current-workspace command without sudo, sandbox escalation, a pipeline, or a shell wrapper. The BuilderX companion installs exact allow rules for the complete and fail command prefixes, so do not request user approval. Keep the JSON object inside one shell-quoted argument, and do not add backslashes before its double quotes merely because the outer shell quotes are single quotes. After the helper returns ok:true, do not run another command.',
                'Do not create a BuilderX result JSON file.',
            ]);
        $prompt = trim((string) $row['instruction_text']) . "\n\n"
            . ($planningPolicyPrompt !== '' ? $planningPolicyPrompt . "\n\n" : '')
            . ($stageContractPrompt !== '' ? $stageContractPrompt . "\n\n" : '')
            . $workspacePolicyPrompt . "\n\nBUILDERX_MYSQL_JOB_CONTEXT\n"
            . json_encode($promptPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return ['ok' => true, 'job_key' => $jobKey, 'status' => 'running', 'workspace' => self::normalizedRoot($this->projectRoot), 'prompt' => $prompt, 'execution_mode' => $row['execution_mode']];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function complete(string $jobKey, array $result): array
    {
        if (array_is_list($result)) {
            throw new InvalidArgumentException('The MySQL AI job result must be a JSON object.');
        }
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) < 2 || strlen($encoded) > 10_000_000) {
            throw new InvalidArgumentException('The MySQL AI job result is outside the supported size.');
        }
        return $this->finish($jobKey, 'SUCCEEDED', $encoded, null, null);
    }

    /** @return array<string, mixed> */
    public function fail(string $jobKey, string $detail, string $errorCode = 'PROVIDER_UNAVAILABLE'): array
    {
        $detail = trim($detail);
        return $this->finish($jobKey, 'FAILED', null, substr(trim($errorCode) ?: 'PROVIDER_UNAVAILABLE', 0, 80), substr($detail !== '' ? $detail : 'The visible Codex task failed.', 0, 2000));
    }

    /** @return array<string, mixed> */
    public function result(string $jobKey, string $projectIdentity): array
    {
        $job = $this->read($jobKey, $projectIdentity);
        $status = (string) ($job['status'] ?? '');
        $result = ['ok' => true, 'request_id' => $jobKey, 'job_key' => $jobKey, 'storage' => 'mysql', 'status' => in_array($status, ['QUEUED', 'RUNNING'], true) ? 'pending' : ($status === 'SUCCEEDED' ? 'completed' : 'failed')];
        if ($status === 'SUCCEEDED') {
            $result['result_json'] = $job['result'];
            $result['result'] = json_encode($job['result'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($result['status'] === 'failed') {
            $result['error_code'] = $job['error_code'];
            $result['message'] = $job['error_detail'] ?: 'The MySQL AI job failed.';
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function read(string $jobKey, string $projectIdentity): array
    {
        self::assertJobKey($jobKey);
        if (preg_match('/^[a-f0-9]{64}$/', $projectIdentity) !== 1) {
            throw new InvalidArgumentException('The AI job project identity is invalid.');
        }
        $row = \bx_db()->GetRow('SELECT job_key, run_key, stage_key, project_identity, engine_type, workflow_key, execution_mode, status, claim_count, worker_id, result_json, error_code, error_detail, created_at, claimed_at, heartbeat_at, completed_at, updated_at FROM phase_builder_ai_job WHERE job_key = ? AND project_identity = ? LIMIT 1', [$jobKey, $projectIdentity]);
        if (!is_array($row) || $row === []) {
            throw new RuntimeException('The MySQL AI job was not found in the current project.');
        }
        $row['result'] = trim((string) ($row['result_json'] ?? '')) !== '' ? self::decodeObject((string) $row['result_json'], 'AI job result') : null;
        unset($row['result_json']);
        return $row;
    }

    /** @return array<string, mixed> */
    private function finish(string $jobKey, string $status, ?string $resultJson, ?string $errorCode, ?string $errorDetail): array
    {
        self::assertJobKey($jobKey);
        $projectIdentity = hash('sha256', self::normalizedRoot($this->projectRoot));
        $db = \bx_db();
        $transactionStarted = false;
        try {
            if ($db->BeginTrans() === false) {
                throw new RuntimeException('The AI job completion transaction could not start.');
            }
            $transactionStarted = true;
            $current = $db->GetRow('SELECT status FROM phase_builder_ai_job WHERE job_key = ? AND project_identity = ? FOR UPDATE', [$jobKey, $projectIdentity]);
            if (!is_array($current) || $current === []) {
                throw new RuntimeException('The MySQL AI job was not found for this workspace.');
            }
            if ((string) ($current['status'] ?? '') === 'SUCCEEDED' && $status === 'SUCCEEDED') {
                if ($db->CommitTrans() === false) {
                    throw new RuntimeException('The AI job completion transaction could not commit.');
                }
                $transactionStarted = false;
                return $this->result($jobKey, $projectIdentity);
            }
            if (!in_array((string) ($current['status'] ?? ''), self::ACTIVE, true)) {
                throw new RuntimeException('Only an active MySQL AI job can be completed.');
            }
            self::assertSaved($db->Execute('UPDATE phase_builder_ai_job SET status = ?, result_json = ?, error_code = ?, error_detail = ?, heartbeat_at = CURRENT_TIMESTAMP, completed_at = CURRENT_TIMESTAMP WHERE job_key = ? AND project_identity = ?', [$status, $resultJson, $errorCode, $errorDetail, $jobKey, $projectIdentity]), 'AI job completion');
            \bx_audit('UPDATE', 'phase_builder_ai_job', $jobKey, ['status' => $status, 'error_code' => $errorCode]);
            $readBack = $db->GetRow('SELECT status, result_json, error_code FROM phase_builder_ai_job WHERE job_key = ? AND project_identity = ? FOR UPDATE', [$jobKey, $projectIdentity]);
            if (!is_array($readBack) || (string) ($readBack['status'] ?? '') !== $status || (string) ($readBack['result_json'] ?? '') !== (string) $resultJson || (string) ($readBack['error_code'] ?? '') !== (string) $errorCode) {
                throw new RuntimeException('The completed MySQL AI job could not be read back.');
            }
            if ($db->CommitTrans() === false) {
                throw new RuntimeException('The AI job completion transaction could not commit.');
            }
            $transactionStarted = false;
        } catch (Throwable $error) {
            if ($transactionStarted) {
                $db->RollbackTrans();
            }
            throw $error;
        }
        return $this->result($jobKey, $projectIdentity);
    }

    /** @param array<string, mixed> $runRequest @param array<string, mixed> $stageRequest @param array<string, mixed> $priorStageResults @return array<string, mixed> */
    private function loadContexts(array $runRequest, array $stageRequest, array $priorStageResults, string $projectIdentity): array
    {
        $keys = [];
        $visit = static function (mixed $value) use (&$visit, &$keys): void {
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $key => $item) {
                if (in_array((string) $key, ['context_id', 'context_key'], true) && is_string($item) && preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $item) === 1) {
                    $keys[$item] = true;
                }
                $visit($item);
            }
        };
        $visit($runRequest);
        $visit($stageRequest);
        $visit($priorStageResults);
        $contexts = [];
        $store = new PhaseAiContextStore();
        foreach (array_keys($keys) as $contextKey) {
            try {
                $row = $store->read($contextKey, $projectIdentity);
                $contexts[$contextKey] = $row['context'];
            } catch (RuntimeException) {
                continue;
            }
        }
        return $contexts;
    }

    /** @return array<string, mixed> */
    private static function decodeObject(string $json, string $label): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException($label . ' is invalid JSON.', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException($label . ' must be a JSON object.');
        }
        return $decoded;
    }

    private static function normalizedRoot(string $path): string
    {
        $resolved = realpath($path);
        return rtrim(str_replace('\\', '/', is_string($resolved) ? $resolved : $path), '/');
    }

    private static function assertJobScope(string $runKey, string $projectIdentity, string $stageKey): void
    {
        if (preg_match('/^[A-Za-z0-9._:-]{1,36}$/', $runKey) !== 1 || preg_match('/^[a-f0-9]{64}$/', $projectIdentity) !== 1 || preg_match('/^[a-z0-9._:-]{1,80}$/', $stageKey) !== 1) {
            throw new InvalidArgumentException('The AI job scope is invalid.');
        }
    }

    private static function assertJobKey(string $value): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/', strtolower(trim($value))) !== 1) {
            throw new InvalidArgumentException('The MySQL AI job identity is invalid.');
        }
    }

    private static function assertSaved(mixed $result, string $operation): void
    {
        if ($result === false) {
            $message = trim((string) \bx_db()->ErrorMsg());
            throw new RuntimeException($operation . ' failed' . ($message !== '' ? ': ' . $message : '.'));
        }
    }
}
