<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PhaseAiOrchestrator
{
    /** @var list<string> */
    private const NARRATIVE_FIELDS = [
        'product_goal',
        'users_and_roles',
        'main_user_journey',
        'web_requirements',
        'android_requirements',
        'database_and_synchronization',
        'security_and_permissions',
        'validation_and_error_handling',
        'open_questions',
    ];

    private readonly string $projectRoot;

    public function __construct(
        string $projectRoot,
        private readonly PhaseAiRunStore $store,
        private readonly ?BuilderXAiBridgeAdapter $bridge = null
    ) {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException('The current BuilderX project root is unavailable.');
        }
        $this->projectRoot = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    /** @return array{run: array<string, mixed>, stage: array<string, mixed>|null, chunk: array<string, mixed>|null} */
    public function next(string $runKey, string $projectIdentity): array
    {
        $run = $this->store->read($runKey, $projectIdentity);
        $this->assertSourceHash($run);
        $nextStage = null;
        foreach ($run['stages'] ?? [] as $stage) {
            if (!is_array($stage)) {
                throw new RuntimeException('The persisted AI stage plan is invalid.');
            }
            if (($stage['status'] ?? '') !== 'SUCCEEDED') {
                $nextStage = $stage;
                break;
            }
        }
        $nextChunk = null;
        if ($nextStage !== null) {
            foreach ($run['chunks'] ?? [] as $chunk) {
                if (is_array($chunk) && ($chunk['stage_key'] ?? '') === ($nextStage['stage_key'] ?? '')) {
                    $nextChunk = $chunk;
                    break;
                }
            }
            if ($nextChunk === null) {
                throw new RuntimeException('The persisted AI chunk plan is incomplete.');
            }
        }
        return ['run' => $run, 'stage' => $nextStage, 'chunk' => $nextChunk];
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function begin(string $runKey, string $projectIdentity, string $stageKey, array $request): array
    {
        $state = $this->requireNextStage($runKey, $projectIdentity, $stageKey);
        $status = (string) ($state['stage']['status'] ?? '');
        if ($status === 'RUNNING') {
            return $state['run'];
        }
        if (!in_array($status, ['QUEUED', 'FAILED'], true)) {
            throw new RuntimeException('The deterministic AI stage is already in progress or complete.');
        }
        return $this->store->checkpoint($runKey, $projectIdentity, $stageKey, 'RUNNING', $request, null, null, null, null);
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    public function complete(string $runKey, string $projectIdentity, string $stageKey, array $result): array
    {
        $state = $this->requireNextStage($runKey, $projectIdentity, $stageKey);
        if (!in_array((string) ($state['stage']['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
            throw new RuntimeException('The deterministic AI stage must be running before completion.');
        }
        $validated = $this->validateStageResult($state['run'], $stageKey, $result);
        return $this->store->checkpoint($runKey, $projectIdentity, $stageKey, 'SUCCEEDED', null, $validated, null, null, null);
    }

    /** @return array<string, mixed> */
    public function fail(string $runKey, string $projectIdentity, string $stageKey, string $errorCode, string $errorDetail): array
    {
        $state = $this->requireNextStage($runKey, $projectIdentity, $stageKey);
        if (!in_array((string) ($state['stage']['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
            throw new RuntimeException('Only the current running AI stage can fail.');
        }
        return $this->store->checkpoint($runKey, $projectIdentity, $stageKey, 'FAILED', null, null, null, $errorCode, $errorDetail);
    }

    /** @param array<string, mixed> $request @return array<string, mixed> */
    public function dispatch(string $runKey, string $projectIdentity, string $stageKey, array $request, string $command, bool $databaseResult = true, bool $allowSourceChanges = false): array
    {
        if ($this->bridge === null) {
            throw new RuntimeException('The shared BuilderX AI Bridge adapter is unavailable.');
        }
        $state = $this->requireNextStage($runKey, $projectIdentity, $stageKey);
        $savedRequestId = trim((string) ($state['stage']['provider_request_id'] ?? ''));
        if (($state['stage']['status'] ?? '') === 'RUNNING' && $savedRequestId !== '') {
            return [
                'run' => $state['run'],
                'delivery' => [
                    'provider_key' => trim((string) ($state['run']['provider_key'] ?? '')) ?: BuilderXAiBridgeAdapter::PROVIDER_KEY,
                    'model_key' => trim((string) ($state['run']['model_key'] ?? '')) ?: null,
                    'provider_request_id' => $savedRequestId,
                    'thread_id' => '',
                    'storage' => 'mysql',
                    'resumed' => true,
                ],
            ];
        }
        if (($state['stage']['status'] ?? '') === 'RUNNING') {
            throw new PhaseAiBridgeException('LOCK_CONFLICT', 'The active AI stage is waiting for its persisted Bridge request identity.');
        }
        try {
            $this->begin($runKey, $projectIdentity, $stageKey, $request);
        } catch (RuntimeException $error) {
            if (str_starts_with($error->getMessage(), 'LOCK_CONFLICT:')) {
                throw new PhaseAiBridgeException('LOCK_CONFLICT', 'The active AI stage is already claimed by another dispatch.', $error);
            }
            throw $error;
        }
        try {
            $job = (new PhaseAiJobStore($this->projectRoot))->queue($runKey, $projectIdentity, $stageKey, $command, $allowSourceChanges);
            $jobKey = trim((string) ($job['job_key'] ?? ''));
            if ($jobKey === '') {
                throw new RuntimeException('The MySQL AI job did not return its persisted identity.');
            }
            $delivery = $this->bridge->dispatchJob($jobKey, $allowSourceChanges);
            $run = $this->store->checkpoint(
                $runKey,
                $projectIdentity,
                $stageKey,
                'RUNNING',
                null,
                null,
                (string) ($delivery['provider_request_id'] ?? ''),
                null,
                null,
                (string) ($delivery['provider_key'] ?? ''),
                isset($delivery['model_key']) ? (string) $delivery['model_key'] : null
            );
            return ['run' => $run, 'delivery' => $delivery];
        } catch (PhaseAiBridgeException $error) {
            $this->store->checkpoint($runKey, $projectIdentity, $stageKey, 'FAILED', null, null, null, $error->errorCode(), $error->getMessage());
            throw $error;
        } catch (Throwable $error) {
            $this->store->checkpoint($runKey, $projectIdentity, $stageKey, 'FAILED', null, null, null, 'BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge dispatch failed.');
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function cancelBetweenStages(string $runKey, string $projectIdentity, string $reason): array
    {
        $state = $this->next($runKey, $projectIdentity);
        if ($state['stage'] === null) {
            throw new RuntimeException('The AI run has no cancellable stage.');
        }
        if (!in_array((string) ($state['stage']['status'] ?? ''), ['QUEUED', 'FAILED'], true)) {
            throw new RuntimeException('The AI run can be cancelled only between stage or chunk executions.');
        }
        return $this->store->checkpoint(
            $runKey,
            $projectIdentity,
            (string) $state['stage']['stage_key'],
            'CANCELLED',
            null,
            null,
            null,
            null,
            trim($reason) !== '' ? substr(trim($reason), 0, 2000) : 'Cancelled by an authorized administrator.'
        );
    }

    /** @return array<string, mixed> */
    public function assertBridgeBinding(string $runKey, string $projectIdentity, string $stageKey, string $providerRequestId): array
    {
        $run = $this->store->read($runKey, $projectIdentity);
        foreach ($run['stages'] ?? [] as $stage) {
            if (
                is_array($stage)
                && ($stage['stage_key'] ?? '') === $stageKey
                && ($stage['provider_request_id'] ?? '') === $providerRequestId
                && in_array((string) ($stage['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)
            ) {
                return $run;
            }
        }
        throw new RuntimeException('The BuilderX AI Bridge request is not bound to the current persisted stage.');
    }

    /** @return array{run: array<string, mixed>, stage: array<string, mixed>, chunk: array<string, mixed>} */
    private function requireNextStage(string $runKey, string $projectIdentity, string $stageKey): array
    {
        $state = $this->next($runKey, $projectIdentity);
        if ($state['stage'] === null || $state['chunk'] === null || ($state['stage']['stage_key'] ?? '') !== $stageKey) {
            throw new RuntimeException('The requested AI stage is not the next deterministic checkpoint.');
        }
        return ['run' => $state['run'], 'stage' => $state['stage'], 'chunk' => $state['chunk']];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private function validateStageResult(array $run, string $stageKey, array $result): array
    {
        if (in_array((string) ($run['workflow_key'] ?? ''), ['sharingan_user', 'sharingan_admin', 'sharingan_phases'], true)) {
            return match ($stageKey) {
                'context' => $this->validateContextResult($result),
                'analysis' => SharinganSurfaceWorkflow::validateAnalysis($run, $result),
                'persistence' => SharinganSurfaceWorkflow::validatePersistence($run, $result),
                default => throw new RuntimeException('The Sharingan surface stage validator is unavailable.'),
            };
        }
        if (($run['workflow_key'] ?? '') === RequirementsAnalysisWorkflow::WORKFLOW_KEY) {
            if (isset(RequirementsAnalysisWorkflow::CHUNKS[$stageKey])) {
                return RequirementsAnalysisWorkflow::validateChunk($run, $stageKey, $result);
            }
            return match ($stageKey) {
                'context' => $this->validateContextResult($result),
                'merge' => RequirementsAnalysisWorkflow::validateMerge($run, $result),
                'integration_review' => RequirementsAnalysisWorkflow::validateReview($run, $result),
                'persistence' => RequirementsAnalysisWorkflow::validatePersistence($run, $result),
                default => throw new RuntimeException('The Requirements Analysis stage validator is unavailable.'),
            };
        }
        if (in_array((string) ($run['workflow_key'] ?? ''), ['system_architecture', 'ui_ux_design', 'execution_roadmap', 'todo_consolidation', 'todo_execution', 'todo_rollback', 'bridge_diagnostic'], true)) {
            if (
                in_array((string) ($run['workflow_key'] ?? ''), ['todo_execution', 'todo_rollback'], true)
                && in_array($stageKey, ['implementation', 'verification', 'evidence', 'git_update', 'persistence'], true)
            ) {
                $this->verifiedServerSourceCheckpoint($run);
            }
            return $stageKey === 'context'
                ? $this->validateContextResult($result)
                : PhaseAiWorkflowContract::validate($run, $stageKey, $result);
        }
        return match ($stageKey) {
            'context' => $this->validateContextResult($result),
            'routing' => $this->validateRoutingResult($result),
            'grammar' => $this->validateGrammarResult($result),
            'validation' => $this->validateApprovalResult($run, $result),
            'persistence' => $this->validatePersistenceResult($run, $result),
            default => throw new RuntimeException('The deterministic AI stage validator is unavailable.'),
        };
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function validateContextResult(array $result): array
    {
        foreach (['context_id', 'sha256'] as $key) {
            if (!is_string($result[$key] ?? null) || trim((string) $result[$key]) === '') {
                throw new RuntimeException('The Narrative context checkpoint is incomplete.');
            }
        }
        if (preg_match('/^[a-f0-9]{64}$/', (string) $result['sha256']) !== 1) {
            throw new RuntimeException('The Narrative context checkpoint hash is invalid.');
        }
        (new PhaseAiContextStore())->verify(
            (string) $result['context_id'],
            hash('sha256', $this->projectRoot),
            (int) ($result['bytes'] ?? -1),
            (string) $result['sha256']
        );
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function validateRoutingResult(array $result): array
    {
        $required = ['role', 'status', 'selected_specialist', 'next_specialist', 'reason'];
        if (
            array_diff($required, array_keys($result)) !== []
            || ($result['role'] ?? '') !== 'coordinator'
            || ($result['status'] ?? '') !== 'routed'
            || ($result['selected_specialist'] ?? '') !== 'narrative-cleanup'
            || ($result['next_specialist'] ?? '') !== 'database'
            || !is_string($result['reason'] ?? null)
            || trim((string) $result['reason']) === ''
        ) {
            throw new RuntimeException('The deterministic Narrative route is invalid.');
        }
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function validateGrammarResult(array $result): array
    {
        $required = ['role', 'status', 'corrected_sections', 'change_history'];
        if (array_diff($required, array_keys($result)) !== [] || array_diff(array_keys($result), $required) !== []) {
            throw new RuntimeException('The grammar checkpoint returned an invalid result object.');
        }
        if (($result['role'] ?? '') !== 'grammar_specialist' || ($result['status'] ?? '') !== 'completed') {
            throw new RuntimeException('The grammar checkpoint did not complete.');
        }
        $sections = $result['corrected_sections'] ?? null;
        if (!is_array($sections) || array_diff(self::NARRATIVE_FIELDS, array_keys($sections)) !== [] || array_diff(array_keys($sections), self::NARRATIVE_FIELDS) !== []) {
            throw new RuntimeException('The grammar checkpoint did not return all nine sections.');
        }
        foreach (self::NARRATIVE_FIELDS as $field) {
            if (!is_string($sections[$field] ?? null)) {
                throw new RuntimeException('The grammar checkpoint contains an invalid section.');
            }
            $sections[$field] = trim(str_replace(["\r\n", "\r"], "\n", (string) $sections[$field]));
        }
        $history = $result['change_history'] ?? null;
        if (!is_array($history)) {
            throw new RuntimeException('The grammar checkpoint change history is invalid.');
        }
        foreach ($history as $change) {
            if (
                !is_array($change)
                || array_diff(['original_text', 'updated_text', 'category', 'reason'], array_keys($change)) !== []
                || array_diff(array_keys($change), ['original_text', 'updated_text', 'category', 'reason']) !== []
                || !is_string($change['original_text'] ?? null)
                || !is_string($change['updated_text'] ?? null)
                || !is_string($change['category'] ?? null)
                || !is_string($change['reason'] ?? null)
                || trim((string) $change['original_text']) === ''
                || trim((string) $change['updated_text']) === ''
                || trim((string) $change['reason']) === ''
                || !in_array(strtolower(trim((string) ($change['category'] ?? ''))), ['grammar', 'punctuation', 'spelling'], true)
            ) {
                throw new RuntimeException('The grammar checkpoint contains an invalid change history item.');
            }
        }
        $result['corrected_sections'] = $sections;
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private function validateApprovalResult(array $run, array $result): array
    {
        $request = $run['request'] ?? null;
        $source = is_array($request) ? ($request['source_snapshot'] ?? null) : null;
        $grammar = $this->stageResult($run, 'grammar');
        if (!is_array($source) || !is_array($grammar)) {
            throw new RuntimeException('The validation checkpoint upstream context is unavailable.');
        }
        PhaseBuilderNarrativeCleanupStore::validatePersistedApproval(
            (string) ($run['draft_key'] ?? ''),
            $result,
            $source,
            $grammar
        );
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private function validatePersistenceResult(array $run, array $result): array
    {
        $required = ['status', 'draft_key', 'corrected_sections', 'change_history'];
        $grammar = $this->stageResult($run, 'grammar');
        if (
            array_diff($required, array_keys($result)) !== []
            || !in_array((string) ($result['status'] ?? ''), ['saved', 'already_saved'], true)
            || strcasecmp((string) ($result['draft_key'] ?? ''), (string) ($run['draft_key'] ?? '')) !== 0
            || !is_array($result['corrected_sections'] ?? null)
            || !is_array($result['change_history'] ?? null)
            || !is_array($grammar)
            || ($result['corrected_sections'] ?? null) !== ($grammar['corrected_sections'] ?? null)
        ) {
            throw new RuntimeException('The Narrative persistence checkpoint read-back is invalid.');
        }
        return $result;
    }

    /** @param array<string, mixed> $run @return array<string, mixed>|null */
    private function stageResult(array $run, string $stageKey): ?array
    {
        foreach ($run['stages'] ?? [] as $stage) {
            if (is_array($stage) && ($stage['stage_key'] ?? '') === $stageKey && is_array($stage['result'] ?? null)) {
                return $stage['result'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private function verifiedServerSourceCheckpoint(array $run): array
    {
        $workflowKey = (string) ($run['workflow_key'] ?? '');
        $request = is_array($run['request'] ?? null) ? $run['request'] : [];
        $executionKey = trim((string) ($request['execution_key'] ?? ''));
        $checkpoint = $request['server_source_checkpoint'] ?? null;
        if (!is_array($checkpoint) || array_is_list($checkpoint)) {
            throw new RuntimeException('The implementation stage requires a server-created source checkpoint.');
        }
        $verified = (new PhaseAiSourceCheckpoint($this->projectRoot))->verify(
            $checkpoint,
            $executionKey,
            (string) ($run['run_key'] ?? ''),
            $workflowKey,
            (string) ($run['project_identity'] ?? '')
        );
        $checkpointColumn = $workflowKey === 'todo_execution' ? 'source_checkpoint_json' : 'rollback_source_checkpoint_json';
        $savedJson = (string) \bx_db()->GetOne(
            "SELECT {$checkpointColumn} FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? LIMIT 1",
            [$executionKey, $run['draft_key'] ?? null, $run['task_id'] ?? null, $run['subtask_id'] ?? null, $run['todo_id'] ?? null]
        );
        $saved = trim($savedJson) !== '' ? json_decode($savedJson, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($saved) || array_is_list($saved) || $saved !== $verified) {
            throw new RuntimeException('The server source checkpoint is not persisted in the saved execution context.');
        }
        return $verified;
    }

    /** @param array<string, mixed> $run */
    private function assertSourceHash(array $run): void
    {
        $request = $run['request'] ?? null;
        if (!is_array($request)) {
            throw new RuntimeException('The persisted AI request is unavailable.');
        }
        $sourceRequest = $request;
        unset($sourceRequest['server_source_checkpoint']);
        $encoded = json_encode($sourceRequest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (!hash_equals((string) ($run['source_hash'] ?? ''), hash('sha256', $encoded))) {
            throw new RuntimeException('SOURCE_CHANGED');
        }
        if (($run['workflow_key'] ?? '') === RequirementsAnalysisWorkflow::WORKFLOW_KEY) {
            $sourceSnapshot = $request['source_snapshot'] ?? null;
            if (!is_array($sourceSnapshot) || array_is_list($sourceSnapshot)) {
                throw new RuntimeException('SOURCE_CHANGED');
            }
            $normalized = [];
            foreach (RequirementsAnalysisWorkflow::SOURCE_FIELDS as $field) {
                if (!array_key_exists($field, $sourceSnapshot) || !is_string($sourceSnapshot[$field])) {
                    throw new RuntimeException('SOURCE_CHANGED');
                }
                $normalized[$field] = str_replace(["\r\n", "\r"], "\n", $sourceSnapshot[$field]);
            }
            $sourceHash = RequirementsAnalysisWorkflow::hashObject($normalized);
            if (!hash_equals($sourceHash, (string) ($request['source_narrative_hash'] ?? ''))) {
                throw new RuntimeException('SOURCE_CHANGED');
            }
            $saved = \bx_db()->GetRow(
                'SELECT product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                [(string) ($run['draft_key'] ?? '')]
            );
            if (!is_array($saved)) {
                throw new RuntimeException('SOURCE_CHANGED');
            }
            $live = [];
            foreach (RequirementsAnalysisWorkflow::SOURCE_FIELDS as $field) {
                $live[$field] = str_replace(["\r\n", "\r"], "\n", (string) ($saved[$field] ?? ''));
            }
            if (!hash_equals($sourceHash, RequirementsAnalysisWorkflow::hashObject($live))) {
                throw new RuntimeException('SOURCE_CHANGED');
            }
        }
    }
}
