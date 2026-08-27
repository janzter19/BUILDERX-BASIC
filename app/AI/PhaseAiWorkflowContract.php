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
        $payloadKey = $baseChunk === 'modules' ? 'modules' : ($baseChunk === 'resources' ? 'resourcePatches' : 'phases');
        $result = self::canonicalizeExecutionRoadmapChunk($run, $baseChunk, $payloadKey, $allowedSchemas[$baseChunk] ?? '', $result);
        $result = self::normalizeExecutionRoadmapChunkPayload($baseChunk, $result);
        $schema = (string) ($result['schemaVersion'] ?? '');
        $contract = (string) ($result['contractType'] ?? '');
        if (!isset($allowedSchemas[$baseChunk]) || $schema !== $allowedSchemas[$baseChunk] || $contract !== 'builderx.execution-roadmap-stage' || (string) ($result['stage'] ?? '') !== $baseChunk) {
            throw new RuntimeException('The Execution Roadmap chunk returned the wrong versioned stage contract.');
        }
        self::requireObject($result, 'source');
        self::assertSource($run, $result['source'], 'architecture_hash', 'architectureHash');
        self::requireList($result, $payloadKey, true);
        if ($baseChunk === 'modules') {
            self::validateExecutionRoadmapModules($result['modules']);
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function canonicalizeExecutionRoadmapChunk(array $run, string $stage, string $payloadKey, string $schemaVersion, array $result): array
    {
        if (isset($result['schemaVersion'], $result['contractType'], $result['stage'], $result['source'])) {
            return $result;
        }
        if ($stage === '' || $payloadKey === '' || $schemaVersion === '' || !is_array($result[$payloadKey] ?? null) || !array_is_list($result[$payloadKey]) || $result[$payloadKey] === []) {
            return $result;
        }
        $request = is_array($run['request'] ?? null) ? $run['request'] : [];
        $hashes = is_array($request['source_hashes'] ?? null) ? $request['source_hashes'] : [];
        $architectureHash = is_string($hashes['architecture_hash'] ?? null) ? (string) $hashes['architecture_hash'] : '';
        $draftKey = is_string($run['draft_key'] ?? null) ? (string) $run['draft_key'] : '';
        if ($draftKey === '' || !preg_match('/^[a-f0-9]{64}$/', $architectureHash)) {
            return $result;
        }
        return [
            'schemaVersion' => $schemaVersion,
            'contractType' => 'builderx.execution-roadmap-stage',
            'stage' => $stage,
            'source' => ['draftKey' => $draftKey, 'architectureHash' => $architectureHash],
            $payloadKey => $result[$payloadKey],
        ];
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private static function normalizeExecutionRoadmapChunkPayload(string $stage, array $result): array
    {
        if (in_array($stage, ['phases', 'tasks', 'subtasks'], true) && is_array($result['phases'] ?? null) && array_is_list($result['phases'])) {
            $result['phases'] = array_map(static function (mixed $phase): mixed {
                if (!is_array($phase) || array_is_list($phase)) {
                    return $phase;
                }
                if (!isset($phase['status']) && isset($phase['phaseStatus'])) {
                    $phase['status'] = $phase['phaseStatus'];
                }
                if (!isset($phase['status'])) {
                    $phase['status'] = 'Pending';
                }
                if (!isset($phase['moduleId'])) {
                    if (is_string($phase['module_id'] ?? null) && trim((string) $phase['module_id']) !== '') {
                        $phase['moduleId'] = (string) $phase['module_id'];
                    } elseif (is_array($phase['moduleIds'] ?? null)) {
                        foreach ($phase['moduleIds'] as $moduleId) {
                            if (is_string($moduleId) && trim($moduleId) !== '') {
                                $phase['moduleId'] = $moduleId;
                                break;
                            }
                        }
                    }
                }
                if (!isset($phase['systemFlow']) && is_array($phase['systemFlowNodes'] ?? null)) {
                    $phase['systemFlow'] = $phase['systemFlowNodes'];
                }
                if (is_array($phase['tasks'] ?? null) && array_is_list($phase['tasks'])) {
                    $phase['tasks'] = array_map([self::class, 'normalizeExecutionRoadmapTask'], $phase['tasks']);
                }
                return $phase;
            }, $result['phases']);
        }
        if ($stage === 'resources' && is_array($result['resourcePatches'] ?? null) && array_is_list($result['resourcePatches'])) {
            $result['resourcePatches'] = array_map(static function (mixed $patch): mixed {
                if (!is_array($patch) || array_is_list($patch)) {
                    return $patch;
                }
                if (is_array($patch['proposedResources'] ?? null) && !array_is_list($patch['proposedResources'])) {
                    foreach (['forms', 'tables', 'apis', 'backgroundProcesses', 'reports', 'analytics'] as $resourceType) {
                        if (!isset($patch['proposedResources'][$resourceType])) {
                            $patch['proposedResources'][$resourceType] = [];
                        }
                    }
                }
                return $patch;
            }, $result['resourcePatches']);
        }
        return $result;
    }

    /** @param mixed $task @return mixed */
    private static function normalizeExecutionRoadmapTask(mixed $task): mixed
    {
        if (!is_array($task) || array_is_list($task)) {
            return $task;
        }
        if (!isset($task['taskId'])) {
            foreach (['task_id', 'taskKey', 'id'] as $key) {
                if (is_string($task[$key] ?? null) && trim((string) $task[$key]) !== '') {
                    $task['taskId'] = (string) $task[$key];
                    break;
                }
            }
        }
        if (!isset($task['taskTitle'])) {
            foreach (['task_title', 'title', 'name'] as $key) {
                if (is_string($task[$key] ?? null) && trim((string) $task[$key]) !== '') {
                    $task['taskTitle'] = (string) $task[$key];
                    break;
                }
            }
        }
        if (!isset($task['taskDescription'])) {
            foreach (['task_description', 'description', 'summary'] as $key) {
                if (is_string($task[$key] ?? null) && trim((string) $task[$key]) !== '') {
                    $task['taskDescription'] = (string) $task[$key];
                    break;
                }
            }
        }
        if (!isset($task['subTasks']) && is_array($task['subtasks'] ?? null)) {
            $task['subTasks'] = $task['subtasks'];
        }
        if (is_array($task['subTasks'] ?? null) && array_is_list($task['subTasks'])) {
            $task['subTasks'] = array_map([self::class, 'normalizeExecutionRoadmapSubtask'], $task['subTasks']);
        }
        return $task;
    }

    /** @param mixed $subtask @return mixed */
    private static function normalizeExecutionRoadmapSubtask(mixed $subtask): mixed
    {
        if (!is_array($subtask) || array_is_list($subtask)) {
            return $subtask;
        }
        if (!isset($subtask['subtaskId'])) {
            foreach (['subtask_id', 'subTaskId', 'id'] as $key) {
                if (is_string($subtask[$key] ?? null) && trim((string) $subtask[$key]) !== '') {
                    $subtask['subtaskId'] = (string) $subtask[$key];
                    break;
                }
            }
        }
        if (!isset($subtask['subtaskTitle'])) {
            foreach (['subtask_title', 'subTaskTitle', 'title', 'name'] as $key) {
                if (is_string($subtask[$key] ?? null) && trim((string) $subtask[$key]) !== '') {
                    $subtask['subtaskTitle'] = (string) $subtask[$key];
                    break;
                }
            }
        }
        if (!isset($subtask['subtaskDescription'])) {
            foreach (['subtask_description', 'subTaskDescription', 'description', 'summary'] as $key) {
                if (is_string($subtask[$key] ?? null) && trim((string) $subtask[$key]) !== '') {
                    $subtask['subtaskDescription'] = (string) $subtask[$key];
                    break;
                }
            }
        }
        if (!isset($subtask['acceptanceCriteria']) && is_array($subtask['acceptance_criteria'] ?? null)) {
            $subtask['acceptanceCriteria'] = $subtask['acceptance_criteria'];
        }
        if (!isset($subtask['dependsOn'])) {
            $subtask['dependsOn'] = [];
        }
        return $subtask;
    }

    /** @param list<mixed> $modules */
    private static function validateExecutionRoadmapModules(array $modules): void
    {
        $moduleIds = [];
        $moduleKeys = [];
        foreach ($modules as $index => $module) {
            if (!is_array($module) || array_is_list($module)) {
                throw new RuntimeException('The Execution Roadmap module catalog contains an invalid module object.');
            }
            foreach (['moduleId', 'moduleKey', 'moduleTitle', 'moduleDescription', 'moduleType'] as $key) {
                if (!is_string($module[$key] ?? null) || trim((string) $module[$key]) === '') {
                    throw new RuntimeException(sprintf('Execution Roadmap module %d is missing %s.', $index + 1, $key));
                }
            }
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', (string) $module['moduleKey'])) {
                throw new RuntimeException('Execution Roadmap module keys must use lower_snake_case.');
            }
            if (!is_int($module['order'] ?? null) || (int) $module['order'] < 1) {
                throw new RuntimeException('Execution Roadmap modules require a positive integer order.');
            }
            if (!is_int($module['phaseCountHint'] ?? null) || (int) $module['phaseCountHint'] < 1) {
                throw new RuntimeException('Execution Roadmap modules require a positive phaseCountHint.');
            }
            foreach (['dependsOn', 'provides', 'consumes'] as $key) {
                self::requireList($module, $key);
            }
            self::requireObject($module, 'uiUxScope');
            foreach (['routes', 'screens', 'sharedComponents'] as $key) {
                self::requireList($module['uiUxScope'], $key);
            }
            foreach (['provides', 'consumes'] as $key) {
                foreach ($module[$key] as $interface) {
                    if (!is_array($interface) || array_is_list($interface)
                        || trim((string) ($interface['interfaceId'] ?? $interface['name'] ?? '')) === ''
                        || trim((string) ($interface['kind'] ?? '')) === ''
                        || trim((string) ($interface['contractSummary'] ?? '')) === ''
                    ) {
                        throw new RuntimeException('Execution Roadmap module interfaces must be structured objects with interfaceId, kind, and contractSummary.');
                    }
                }
            }
            $moduleId = (string) $module['moduleId'];
            $moduleKey = (string) $module['moduleKey'];
            if (isset($moduleIds[$moduleId]) || isset($moduleKeys[$moduleKey])) {
                throw new RuntimeException('Execution Roadmap module IDs and keys must be unique.');
            }
            $moduleIds[$moduleId] = true;
            $moduleKeys[$moduleKey] = true;
        }
        foreach ($modules as $module) {
            foreach ($module['dependsOn'] as $dependencyId) {
                if (!is_string($dependencyId) || !isset($moduleIds[$dependencyId]) || $dependencyId === (string) $module['moduleId']) {
                    throw new RuntimeException('Execution Roadmap modules contain an invalid dependency reference.');
                }
            }
        }
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
        $result = self::normalizeExecutionEvidence($result, $serverCheckpoint);
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
            if (self::hasMeaningfulDatabaseChanges($result['databaseChanges'])) {
                throw new RuntimeException('Completed database changes require a separately implemented and verified database recovery checkpoint.');
            }
        }
        return $result;
    }

    /** @param array<int, mixed> $changes */
    private static function hasMeaningfulDatabaseChanges(array $changes): bool
    {
        foreach ($changes as $change) {
            if (is_string($change)) {
                if (trim($change) !== '') {
                    return true;
                }
                continue;
            }
            if (!is_array($change)) {
                if ($change !== null && $change !== false) {
                    return true;
                }
                continue;
            }
            if ($change === []) {
                continue;
            }
            $schemaChanged = $change['schemaChanged'] ?? $change['schemaChanges'] ?? $change['schema_changed'] ?? $change['schema_changes'] ?? null;
            $dataChanged = $change['dataChanged'] ?? $change['dataChanges'] ?? $change['data_changed'] ?? $change['data_changes'] ?? null;
            $changed = $change['changed'] ?? $change['writesMade'] ?? $change['writes_made'] ?? null;
            $required = $change['required'] ?? null;
            if ($schemaChanged === true || $dataChanged === true || $changed === true) {
                return true;
            }
            if (($required === false || $required === 0 || $required === 'false')
                && ($changed === false || $changed === 0 || $changed === 'false')) {
                continue;
            }
            if (($schemaChanged === false || $schemaChanged === 0 || $schemaChanged === 'false')
                && ($dataChanged === false || $dataChanged === 0 || $dataChanged === 'false')
                && ($changed === false || $changed === 0 || $changed === 'false' || $changed === null)) {
                continue;
            }
            if (($schemaChanged === false || $schemaChanged === 0 || $schemaChanged === 'false')
                && ($dataChanged === false || $dataChanged === 0 || $dataChanged === 'false')) {
                continue;
            }
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $result @param array<string, mixed>|null $serverCheckpoint @return array<string, mixed> */
    private static function normalizeExecutionEvidence(array $result, ?array $serverCheckpoint): array
    {
        foreach (['databaseChanges', 'androidChanges'] as $key) {
            if (is_array($result[$key] ?? null) && !array_is_list($result[$key])) {
                $result[$key] = [$result[$key]];
            }
        }
        if (is_array($result['recoveryCheckpoints'] ?? null) && !array_is_list($result['recoveryCheckpoints'])) {
            $result['recoveryCheckpoints'] = array_values($result['recoveryCheckpoints']);
        }
        if (is_array($result['recoveryCheckpoints'] ?? null) && array_is_list($result['recoveryCheckpoints'])) {
            $expectedServerEvidence = is_array($serverCheckpoint) && !array_is_list($serverCheckpoint)
                ? PhaseAiSourceCheckpoint::modelEvidence($serverCheckpoint)
                : null;
            $result['recoveryCheckpoints'] = array_map(static function (mixed $checkpoint) use ($expectedServerEvidence): mixed {
                if (!is_array($checkpoint) || array_is_list($checkpoint)) {
                    return $checkpoint;
                }
                if (!isset($checkpoint['createdBeforeWrite']) && isset($checkpoint['created_before_write'])) {
                    $checkpoint['createdBeforeWrite'] = $checkpoint['created_before_write'];
                }
                if (!isset($checkpoint['databaseRollbackProtected']) && isset($checkpoint['database_rollback_protected'])) {
                    $checkpoint['databaseRollbackProtected'] = $checkpoint['database_rollback_protected'];
                }
                if (in_array((string) ($checkpoint['type'] ?? ''), ['server_source_checkpoint', 'source_checkpoint'], true)) {
                    $checkpoint['type'] = 'server_source';
                }
                if (!isset($checkpoint['manifestSha256'])) {
                    foreach (['manifest_sha256', 'manifestHash', 'manifest_hash'] as $key) {
                        if (is_string($checkpoint[$key] ?? null) && trim((string) $checkpoint[$key]) !== '') {
                            $checkpoint['manifestSha256'] = $checkpoint[$key];
                            break;
                        }
                    }
                }
                if (($checkpoint['type'] ?? '') === 'server_source') {
                    if (!isset($checkpoint['reference'])) {
                        foreach (['checkpointKey', 'checkpoint_key', 'checkpointId', 'checkpoint_id'] as $key) {
                            if (is_string($checkpoint[$key] ?? null) && trim((string) $checkpoint[$key]) !== '') {
                                $checkpoint['reference'] = $checkpoint[$key];
                                break;
                            }
                        }
                    }
                    if (!isset($checkpoint['scope']) || trim((string) $checkpoint['scope']) === '') {
                        $checkpoint['scope'] = is_array($expectedServerEvidence)
                            ? (string) ($expectedServerEvidence['scope'] ?? 'project_source_files')
                            : 'project_source_files';
                    }
                }
                return $checkpoint;
            }, $result['recoveryCheckpoints']);
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
            || !in_array((string) ($result['status'] ?? ''), ['approved', 'blocked'], true)
        ) {
            throw new RuntimeException('The AI integration review contract is invalid.');
        }
        $rawFindings = [];
        if (array_key_exists('findings', $result)) {
            self::requireList($result, 'findings');
            $rawFindings = $result['findings'];
        } elseif (array_key_exists('blockingFindings', $result)) {
            self::requireList($result, 'blockingFindings');
            $rawFindings = $result['blockingFindings'];
        }
        $rawSuggestions = [];
        if (array_key_exists('nonBlockingSuggestions', $result)) {
            self::requireList($result, 'nonBlockingSuggestions');
            $rawSuggestions = $result['nonBlockingSuggestions'];
        }
        $canonicalizeFinding = static function (mixed $finding): array {
            if (!is_array($finding) || array_is_list($finding)) {
                throw new RuntimeException('The AI integration review contains an invalid finding.');
            }
            $summary = '';
            foreach (['summary', 'detail', 'reason', 'title'] as $summaryKey) {
                $summary = trim((string) ($finding[$summaryKey] ?? ''));
                if ($summary !== '') break;
            }
            if ($summary === '') {
                throw new RuntimeException('The AI integration review contains an invalid finding.');
            }
            $canonicalFinding = ['summary' => $summary];
            foreach (['code', 'relatedArea'] as $optionalKey) {
                $optionalValue = trim((string) ($finding[$optionalKey] ?? ''));
                if ($optionalValue !== '') $canonicalFinding[$optionalKey] = $optionalValue;
            }
            $requiredResolution = trim((string) ($finding['requiredResolution'] ?? $finding['resolution'] ?? ''));
            if ($requiredResolution !== '') $canonicalFinding['requiredResolution'] = $requiredResolution;
            return $canonicalFinding;
        };
        $findings = [];
        foreach ($rawFindings as $finding) {
            $findings[] = $canonicalizeFinding($finding);
        }
        $suggestionFindings = [];
        foreach ($rawSuggestions as $suggestion) {
            $suggestionFindings[] = $canonicalizeFinding($suggestion);
        }
        $requestedStatus = (string) $result['status'];
        if (!array_key_exists('findings', $result) && $findings !== []) {
            $requestedStatus = 'blocked';
        }
        if ($requestedStatus === 'blocked' && $findings === []) {
            throw new RuntimeException('A blocked AI integration review must explain its findings.');
        }
        $canonicalReview = [
            'schemaVersion' => 'builderx.ai-integration-review.v1',
            'workflowKey' => $workflowKey,
            'artifactHash' => $artifactHash,
            'status' => $requestedStatus,
            'findings' => $findings,
        ];
        if (PhaseBuilderPlanningPolicy::appliesTo($workflowKey)) {
            $normalized = PhaseBuilderPlanningPolicy::normalizeReview($workflowKey, $requestedStatus, $findings);
            $normalizedSuggestions = PhaseBuilderPlanningPolicy::normalizeReview($workflowKey, 'approved', $suggestionFindings);
            $canonicalReview['status'] = $normalized['status'];
            $canonicalReview['findings'] = $normalized['findings'];
            $canonicalReview['suggestions'] = array_slice(array_merge($normalized['suggestions'], $normalizedSuggestions['suggestions']), 0, 8);
            $canonicalReview['planningPolicyVersion'] = PhaseBuilderPlanningPolicy::VERSION;
        }
        return $canonicalReview;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    private static function validateCodingCheckpoint(string $workflowKey, string $stageKey, array $run, array $result): array
    {
        if (!in_array($workflowKey, ['todo_execution', 'todo_rollback'], true)) {
            throw new RuntimeException('A Coding Engine checkpoint was used by a Planning workflow.');
        }
        $result = self::normalizeCodingCheckpoint($workflowKey, $stageKey, $result);
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

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private static function normalizeCodingCheckpoint(string $workflowKey, string $stageKey, array $result): array
    {
        if (!isset($result['schemaVersion']) && is_string($result['schema_version'] ?? null)) {
            $result['schemaVersion'] = $result['schema_version'];
        }
        if (!isset($result['workflowKey'])) {
            if (is_string($result['workflow_key'] ?? null) && trim((string) $result['workflow_key']) !== '') {
                $result['workflowKey'] = $result['workflow_key'];
            } else {
                $result['workflowKey'] = $workflowKey;
            }
        }
        if (!isset($result['stage'])) {
            if (is_string($result['stage_key'] ?? null) && trim((string) $result['stage_key']) !== '') {
                $result['stage'] = $result['stage_key'];
            } else {
                $result['stage'] = $stageKey;
            }
        }
        if (!isset($result['remoteOperation']) && is_string($result['remote_operation'] ?? null)) {
            $result['remoteOperation'] = $result['remote_operation'];
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
        if (in_array($workflowKey, ['system_architecture', 'ui_ux_design', 'execution_roadmap'], true)) {
            $review = self::stageResult($run, 'integration_review');
            $reviewBlocked = is_array($review) && ($review['status'] ?? '') === 'blocked';
            if ($reviewBlocked !== (($result['status'] ?? '') === 'blocked')) {
                throw new RuntimeException('The Planning persistence status does not match its verified integration review.');
            }
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
