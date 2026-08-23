<?php
declare(strict_types=1);

namespace BuilderX\AI;

use RuntimeException;

final class PhaseAiWorkflowContract
{
    /** @param array<string, mixed> $result @param array<string, mixed>|null $serverCheckpoint @return array<string, mixed> */
    public static function validatePersistedExecutionEvidence(array $result, ?array $serverCheckpoint = null): array
    {
        return self::validateExecutionEvidence($result, $serverCheckpoint);
    }

    /** @param array<string, mixed> $run */
    public static function codingOverallStatus(array $run): string
    {
        if (!in_array((string) ($run['workflow_key'] ?? ''), ['todo_execution', 'todo_rollback'], true)) {
            throw new RuntimeException('The Coding Engine overall status is unavailable for this workflow.');
        }
        $overallStatus = 'completed';
        foreach (['inspection', 'plan', 'implementation', 'verification', 'evidence'] as $stageKey) {
            $result = self::stageResult($run, $stageKey);
            if (!is_array($result)) {
                throw new RuntimeException('The Coding Engine overall status requires every verified checkpoint.');
            }
            $stageStatus = (string) ($result['status'] ?? '');
            if ($stageStatus === 'failed') {
                return 'failed';
            }
            if ($stageStatus === 'blocked') {
                $overallStatus = 'blocked';
            }
        }
        return $overallStatus;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validate(array $run, string $stageKey, array $result): array
    {
        $workflowKey = (string) ($run['workflow_key'] ?? '');
        if ($stageKey === 'analysis' || $stageKey === 'implementation') {
            return self::validateWorkResult($workflowKey, $run, $result);
        }
        if ($stageKey === 'integration_review') {
            return self::validateIntegrationReview($workflowKey, $run, $result);
        }
        if (in_array($stageKey, ['inspection', 'plan', 'verification', 'evidence', 'git_update'], true)) {
            return self::validateCodingCheckpoint($workflowKey, $stageKey, $run, $result);
        }
        if ($stageKey === 'persistence') {
            return self::validatePersistence($workflowKey, $run, $result);
        }
        throw new RuntimeException('The shared AI workflow stage validator is unavailable.');
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateWorkResult(string $workflowKey, array $run, array $result): array
    {
        return match ($workflowKey) {
            'system_architecture' => self::validateSystemArchitecture($run, $result),
            'ui_ux_design' => self::validateUiUxDesign($run, $result),
            'execution_roadmap' => self::validateExecutionRoadmapChunk($run, $result),
            'todo_consolidation' => self::validateTodoConsolidation($result),
            'todo_execution', 'todo_rollback' => self::validateExecutionEvidence(
                $result,
                is_array($run['request']['server_source_checkpoint'] ?? null) ? $run['request']['server_source_checkpoint'] : null
            ),
            'bridge_diagnostic' => self::validateBridgeDiagnostic($result),
            default => throw new RuntimeException('The shared AI workflow result contract is unavailable.'),
        };
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateSystemArchitecture(array $run, array $result): array
    {
        self::requireExactContract($result, 'builderx.system-architecture.v1', 'builderx.system-architecture');
        self::requireKeys($result, ['source', 'projectBlueprint', 'systemInventory', 'fileManifest', 'implementationChecklist', 'assumptionsOrRisks', 'orchestration']);
        self::requireObject($result, 'source');
        self::requireObject($result, 'projectBlueprint');
        self::requireObject($result, 'systemInventory');
        self::requireObject($result, 'orchestration');
        foreach (['fileManifest', 'implementationChecklist', 'assumptionsOrRisks'] as $key) {
            self::requireList($result, $key);
        }
        self::requireList($result['projectBlueprint'], 'boundaries');
        self::requireList($result['projectBlueprint'], 'dataFlow');
        self::assertSource($run, $result['source'], 'requirements_hash', 'requirementsHash');
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateUiUxDesign(array $run, array $result): array
    {
        self::requireExactContract($result, 'builderx.ui-ux-design.v1', 'builderx.ui-ux-design');
        self::requireKeys($result, ['source', 'designBlueprint', 'screens', 'flowChart', 'responsiveRules', 'accessibilityRules', 'orchestration']);
        foreach (['source', 'designBlueprint', 'orchestration'] as $key) {
            self::requireObject($result, $key);
        }
        foreach (['screens', 'flowChart', 'responsiveRules', 'accessibilityRules'] as $key) {
            self::requireList($result, $key, $key === 'screens' || $key === 'flowChart');
        }
        foreach ($result['screens'] as $screen) {
            if (!is_array($screen) || array_is_list($screen) || trim((string) ($screen['screenId'] ?? '')) === '' || trim((string) ($screen['name'] ?? '')) === '' || trim((string) ($screen['purpose'] ?? '')) === '') {
                throw new RuntimeException('The UI/UX Design contains an invalid screen.');
            }
            self::requireObject($screen, 'renderSpec');
            self::requireList($screen['renderSpec'], 'sections');
        }
        self::assertSource($run, $result['source'], 'architecture_hash', 'architectureHash');
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateExecutionRoadmapChunk(array $run, array $result): array
    {
        $request = is_array($run['request'] ?? null) ? $run['request'] : [];
        $expectedChunk = (string) ($request['semantic_chunk_key'] ?? '');
        $schema = (string) ($result['schemaVersion'] ?? '');
        $contract = (string) ($result['contractType'] ?? '');
        $allowedSchemas = [
            'modules' => 'builderx.execution-roadmap.stage.modules.v1',
            'phases' => 'builderx.execution-roadmap.stage.phases.v1',
            'tasks' => 'builderx.execution-roadmap.stage.tasks.v1',
            'subtasks' => 'builderx.execution-roadmap.stage.subtasks.v1',
            'resources' => 'builderx.execution-roadmap.stage.resources.v1',
        ];
        $baseChunk = explode(':', $expectedChunk, 2)[0];
        if (!isset($allowedSchemas[$baseChunk]) || $schema !== $allowedSchemas[$baseChunk] || $contract !== 'builderx.execution-roadmap-stage' || (string) ($result['stage'] ?? '') !== $baseChunk) {
            throw new RuntimeException('The Execution Roadmap chunk returned the wrong versioned stage contract.');
        }
        self::requireObject($result, 'source');
        self::assertSource($run, $result['source'], 'architecture_hash', 'architectureHash');
        $payloadKey = $baseChunk === 'modules' ? 'modules' : ($baseChunk === 'resources' ? 'resourcePatches' : 'phases');
        self::requireList($result, $payloadKey, true);
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateTodoConsolidation(array $result): array
    {
        self::requireKeys($result, ['summary', 'suggestion', 'suggestedTodoTitle', 'suggestedTodoDescription', 'risks', 'confidence']);
        foreach (['summary', 'suggestion', 'suggestedTodoTitle', 'suggestedTodoDescription'] as $key) {
            if (!is_string($result[$key] ?? null) || trim((string) $result[$key]) === '') {
                throw new RuntimeException('The todo consolidation result is incomplete.');
            }
        }
        self::requireList($result, 'risks');
        if (!is_numeric($result['confidence'] ?? null) || (float) $result['confidence'] < 0 || (float) $result['confidence'] > 1) {
            throw new RuntimeException('The todo consolidation confidence must be between zero and one.');
        }
        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateBridgeDiagnostic(array $result): array
    {
        self::requireKeys($result, ['scope_level', 'execution_mode', 'coordinator_decision', 'specialist_tasks', 'specialist_results', 'reconciliation', 'final_summary']);
        if (($result['scope_level'] ?? '') !== 'project' || ($result['execution_mode'] ?? '') !== 'single_chat_multi_specialist_orchestration' || trim((string) ($result['final_summary'] ?? '')) === '') {
            throw new RuntimeException('The server-owned Bridge diagnostic result is invalid.');
        }
        foreach (['coordinator_decision', 'reconciliation'] as $key) self::requireObject($result, $key);
        foreach (['specialist_tasks', 'specialist_results'] as $key) self::requireList($result, $key, true);
        return $result;
    }

    /** @param array<string, mixed> $result @param array<string, mixed>|null $serverCheckpoint @return array<string, mixed> */
    private static function validateExecutionEvidence(array $result, ?array $serverCheckpoint): array
    {
        self::requireKeys($result, ['status', 'summary', 'recoveryCheckpoints', 'changedFiles', 'databaseChanges', 'androidChanges', 'tests', 'blockers', 'nextSteps']);
        if (!in_array((string) ($result['status'] ?? ''), ['completed', 'blocked', 'failed'], true) || trim((string) ($result['summary'] ?? '')) === '') {
            throw new RuntimeException('The Coding Engine returned an invalid execution status or summary.');
        }
        foreach (['recoveryCheckpoints', 'changedFiles', 'databaseChanges', 'androidChanges', 'tests', 'blockers', 'nextSteps'] as $key) {
            self::requireList($result, $key);
        }
        if (($result['status'] ?? '') === 'completed' && count($result['tests']) === 0) {
            throw new RuntimeException('The Coding Engine cannot report completion without focused verification evidence.');
        }
        $reportedServerCheckpoint = null;
        foreach ($result['recoveryCheckpoints'] as $checkpoint) {
            if (
                !is_array($checkpoint)
                || array_is_list($checkpoint)
                || !in_array((string) ($checkpoint['type'] ?? ''), ['server_source', 'git', 'file_backup', 'android_backup'], true)
                || trim((string) ($checkpoint['reference'] ?? '')) === ''
                || trim((string) ($checkpoint['scope'] ?? '')) === ''
                || ($checkpoint['createdBeforeWrite'] ?? false) !== true
            ) {
                throw new RuntimeException('The Coding Engine returned invalid recoverable checkpoint evidence.');
            }
            if (($checkpoint['type'] ?? '') === 'server_source') {
                if (
                    preg_match('/^[a-f0-9]{64}$/', (string) ($checkpoint['manifestSha256'] ?? '')) !== 1
                    || ($checkpoint['databaseRollbackProtected'] ?? true) !== false
                ) {
                    throw new RuntimeException('The Coding Engine returned invalid server source checkpoint evidence.');
                }
                $reportedServerCheckpoint = $checkpoint;
            }
        }
        if (($result['status'] ?? '') === 'completed') {
            if (!is_array($serverCheckpoint) || array_is_list($serverCheckpoint)) {
                throw new RuntimeException('The Coding Engine cannot report completion without a server-created source checkpoint.');
            }
            $expectedEvidence = PhaseAiSourceCheckpoint::modelEvidence($serverCheckpoint);
            if (
                !is_array($reportedServerCheckpoint)
                || (string) ($reportedServerCheckpoint['reference'] ?? '') !== (string) $expectedEvidence['reference']
                || (string) ($reportedServerCheckpoint['scope'] ?? '') !== (string) $expectedEvidence['scope']
                || (string) ($reportedServerCheckpoint['manifestSha256'] ?? '') !== (string) $expectedEvidence['manifestSha256']
                || ($reportedServerCheckpoint['createdBeforeWrite'] ?? false) !== true
                || ($reportedServerCheckpoint['databaseRollbackProtected'] ?? true) !== false
            ) {
                throw new RuntimeException('The Coding Engine completion report is not bound to the verified server source checkpoint.');
            }
            if (count($result['databaseChanges']) > 0) {
                throw new RuntimeException('Completed database changes require a separately implemented and verified database recovery checkpoint.');
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateIntegrationReview(string $workflowKey, array $run, array $result): array
    {
        $analysis = self::stageResult($run, 'analysis');
        if (!is_array($analysis)) {
            throw new RuntimeException('The integration review has no validated artifact checkpoint.');
        }
        $artifactHash = self::hash($analysis);
        if (
            ($result['schemaVersion'] ?? '') !== 'builderx.ai-integration-review.v1'
            || ($result['workflowKey'] ?? '') !== $workflowKey
            || ($result['artifactHash'] ?? '') !== $artifactHash
            || !in_array((string) ($result['status'] ?? ''), ['approved', 'blocked'], true)
        ) {
            throw new RuntimeException('The AI integration review is not bound to the validated artifact.');
        }
        self::requireList($result, 'findings');
        if (($result['status'] ?? '') !== 'approved') {
            throw new RuntimeException('The AI integration review found a blocking conflict.');
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateCodingCheckpoint(string $workflowKey, string $stageKey, array $run, array $result): array
    {
        if (!in_array($workflowKey, ['todo_execution', 'todo_rollback'], true)) {
            throw new RuntimeException('A Coding Engine checkpoint was used by a Planning workflow.');
        }
        if (($result['schemaVersion'] ?? '') !== 'builderx.coding-checkpoint.v1' || ($result['stage'] ?? '') !== $stageKey || ($result['workflowKey'] ?? '') !== $workflowKey) {
            throw new RuntimeException('The Coding Engine checkpoint contract is invalid.');
        }
        $allowedStatuses = $stageKey === 'git_update'
            ? ['completed', 'skipped', 'blocked']
            : ['completed', 'blocked'];
        if (!in_array((string) ($result['status'] ?? ''), $allowedStatuses, true)) {
            throw new RuntimeException('The Coding Engine checkpoint status is invalid.');
        }
        self::requireList($result, 'evidence');
        if ($result['evidence'] === []) {
            throw new RuntimeException('The Coding Engine checkpoint requires focused evidence.');
        }
        if ($stageKey === 'git_update') {
            $request = is_array($run['request'] ?? null) ? $run['request'] : [];
            $approved = ($request['git_update_approved'] ?? false) === true;
            if (!$approved && ($result['status'] ?? '') !== 'skipped') {
                throw new RuntimeException('The Git Update stage requires explicit approval.');
            }
            if (($result['remoteOperation'] ?? 'none') !== 'none') {
                throw new RuntimeException('Remote Git operations require separate explicit approval and cannot run in this workflow.');
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validatePersistence(string $workflowKey, array $run, array $result): array
    {
        if (($result['schemaVersion'] ?? '') !== 'builderx.ai-persistence.v1' || ($result['workflowKey'] ?? '') !== $workflowKey || ($result['readBackVerified'] ?? false) !== true) {
            throw new RuntimeException('The AI persistence checkpoint is incomplete.');
        }
        if (!in_array((string) ($result['status'] ?? ''), ['created', 'updated', 'already_saved', 'completed', 'blocked', 'failed', 'rolled_back'], true)) {
            throw new RuntimeException('The AI persistence checkpoint status is invalid.');
        }
        if (in_array($workflowKey, ['todo_execution', 'todo_rollback'], true)) {
            $overallStatus = self::codingOverallStatus($run);
            $expectedStatus = $workflowKey === 'todo_rollback' && $overallStatus === 'completed'
                ? 'rolled_back'
                : $overallStatus;
            if (($result['status'] ?? '') !== $expectedStatus) {
                throw new RuntimeException('The Coding Engine persistence status does not match its verified checkpoints.');
            }
        }
        $workStage = in_array($workflowKey, ['todo_execution', 'todo_rollback'], true) ? 'implementation' : 'analysis';
        $artifact = self::stageResult($run, $workStage);
        if (!is_array($artifact) || ($result['artifactHash'] ?? '') !== self::hash($artifact)) {
            throw new RuntimeException('The AI persistence checkpoint does not match the validated result.');
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $source */
    private static function assertSource(array $run, array $source, string $requestHashKey, string $sourceHashKey): void
    {
        $request = is_array($run['request'] ?? null) ? $run['request'] : [];
        $hashes = is_array($request['source_hashes'] ?? null) ? $request['source_hashes'] : [];
        if (($source['draftKey'] ?? '') !== ($run['draft_key'] ?? '') || !is_string($hashes[$requestHashKey] ?? null) || !hash_equals((string) $hashes[$requestHashKey], (string) ($source[$sourceHashKey] ?? ''))) {
            throw new RuntimeException('The AI artifact source identity changed before validation.');
        }
    }

    /** @param array<string, mixed> $result */
    private static function requireExactContract(array $result, string $schemaVersion, string $contractType): void
    {
        if (($result['schemaVersion'] ?? '') !== $schemaVersion || ($result['contractType'] ?? '') !== $contractType) {
            throw new RuntimeException('The AI artifact returned an unsupported contract version.');
        }
    }

    /** @param array<string, mixed> $result @param list<string> $keys */
    private static function requireKeys(array $result, array $keys): void
    {
        if (array_diff($keys, array_keys($result)) !== []) {
            throw new RuntimeException('The AI result is missing required fields.');
        }
    }

    /** @param array<string, mixed> $result */
    private static function requireObject(array $result, string $key): void
    {
        if (!is_array($result[$key] ?? null) || array_is_list($result[$key])) {
            throw new RuntimeException(sprintf('The AI result field %s must be an object.', $key));
        }
    }

    /** @param array<string, mixed> $result */
    private static function requireList(array $result, string $key, bool $nonEmpty = false): void
    {
        if (!is_array($result[$key] ?? null) || !array_is_list($result[$key]) || ($nonEmpty && count($result[$key]) === 0)) {
            throw new RuntimeException(sprintf('The AI result field %s must be %sa list.', $key, $nonEmpty ? 'a non-empty ' : ''));
        }
    }

    /** @param array<string, mixed> $run @return array<string, mixed>|null */
    private static function stageResult(array $run, string $stageKey): ?array
    {
        foreach ($run['stages'] ?? [] as $stage) {
            if (is_array($stage) && ($stage['stage_key'] ?? '') === $stageKey && is_array($stage['result'] ?? null)) {
                return $stage['result'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $value */
    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
