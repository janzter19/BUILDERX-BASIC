<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';
require_once __DIR__ . '/../app/phase-roadmap-export.php';

$db = bx_db();
$user = bx_current_user();
$isAdmin = $user !== null && bx_is_admin($user);
$flash = bx_take_flash();
$target = trim((string) ($_GET['target'] ?? '')) === 'builder' ? 'builder' : 'manager';
$projectScriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/phases/index.php'));
$projectMountPath = rtrim(dirname(dirname($projectScriptPath)), '/');
$resolvedProjectRoot = realpath(dirname(__DIR__));
$projectRoot = rtrim(str_replace('\\', '/', is_string($resolvedProjectRoot) ? $resolvedProjectRoot : dirname(__DIR__)), '/');
$projectWorkspaceRoot = $projectRoot;
$webProjectRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/') . $projectMountPath;
$resolvedWebProjectRoot = realpath($webProjectRoot);
if (is_string($resolvedWebProjectRoot) && rtrim(str_replace('\\', '/', $resolvedWebProjectRoot), '/') === $projectRoot) {
    $projectWorkspaceRoot = rtrim(str_replace('\\', '/', $webProjectRoot), '/');
}
$projectIdentity = hash('sha256', $projectRoot);
$isJsonRequest = str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

$respondJson = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$writePhaseCoordinatorContext = static function (string $contextId, array $payload) use ($projectIdentity, $user): array {
    $saved = (new \BuilderX\AI\PhaseAiContextStore((string) ($user['user_key'] ?? '')))->save($contextId, $projectIdentity, $payload);
    return [
        ...$saved,
        // Transitional response alias for the current frontend. This is a MySQL
        // reference, not a filesystem path, and is never opened by the model.
        'context_path' => $saved['context_ref'],
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'coordinator_test_task') {
    if (!$isAdmin || !hash_equals(bx_csrf_token(), (string) ($_GET['csrf'] ?? ''))) {
        $respondJson(['ok' => false, 'message' => 'Administrator access and a valid request token are required.'], 403);
    }
    $remoteAddress = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
        $respondJson(['ok' => false, 'message' => 'This test task is available only from the local machine.'], 403);
    }

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    if ($taskId !== 'phase1-coordinator-test-001') {
        $respondJson(['ok' => false, 'message' => 'Coordinator test task not found.'], 404);
    }

    $respondJson([
        'ok' => true,
        'task_id' => $taskId,
        'task_type' => 'coordinator_routing_test',
        'scope_level' => 'project',
        'project_name' => 'Apartment Management System',
        'target_product_surfaces' => [
            'user_portal' => 'http://127.0.0.1' . $projectMountPath . '/',
            'administrator_portal' => 'http://127.0.0.1' . $projectMountPath . '/administrator/',
        ],
        'builderx_phase_builder' => 'control_plane_only',
        'platform_mutations_allowed' => false,
        'allowed_project_scope' => 'read_only_project_planning',
        'objective' => 'Choose the specialists needed to plan a safe project-level feature and identify which selected tasks are independent.',
        'available_specialists' => [
            ['name' => 'Requirements', 'focus' => 'requirements, assumptions, and acceptance criteria'],
            ['name' => 'Database', 'focus' => 'schema, transactions, data boundaries, and read-back'],
            ['name' => 'UI/UX', 'focus' => 'interface, interaction, accessibility, and responsive behavior'],
        ],
        'constraints' => [
            'read_only',
            'no_file_edits',
            'no_sql',
            'no_database_changes',
            'do_not_modify_builderx_platform',
            'do_not_modify_phase_builder_control_plane',
            'report_platform_or_bridge_blockers_without_editing',
        ],
        'expected_output' => 'coordinator_result_v1',
        'instructions' => [
            'role' => 'Act only as the project-level Coordinator.',
            'read_task_before_action' => true,
            'target_product' => [
            'user_portal' => 'http://127.0.0.1' . $projectMountPath . '/',
            'administrator_portal' => 'http://127.0.0.1' . $projectMountPath . '/administrator/',
            ],
            'control_plane_excluded' => [$projectMountPath . '/phases/'],
            'available_specialists' => ['Requirements', 'Database', 'UI/UX'],
            'specialist_mode' => 'read_only_simulated_findings',
            'parallelism' => 'Identify independent candidates only; do not claim actual parallel execution.',
            'forbidden_actions' => [
                'modify BuilderX development files',
                'call other agents',
                'dispatch child requests',
                'edit files',
                'execute SQL',
                'change project data',
                'use Codex CLI, pollers, workers, or another bridge',
            ],
            'failure_behavior' => 'If any required task detail is unavailable, stop and return a blocked result. Do not infer missing instructions.',
            'response_schema' => [
                'scope_level' => 'project',
                'execution_mode' => 'single_turn_simulated_parallel_plan',
                'coordinator_decision' => [
                    'selected_specialists' => [],
                    'reason' => '',
                ],
                'specialist_tasks' => [[
                    'specialist' => '',
                    'objective' => '',
                    'read_only' => true,
                    'independent' => true,
                ]],
                'specialist_results' => [[
                    'specialist' => '',
                    'status' => 'simulated',
                    'findings' => [],
                    'risks' => [],
                    'next_action' => '',
                ]],
                'reconciliation' => [
                    'summary' => '',
                    'conflicts' => [],
                    'parallel_candidates' => [],
                ],
                'final_summary' => '',
            ],
            'return_rules' => ['Return exactly one JSON object with no markdown fences.'],
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'phase2_coordinator_route') {
    if (!$isAdmin || !hash_equals(bx_csrf_token(), (string) ($_GET['csrf'] ?? ''))) {
        $respondJson(['ok' => false, 'message' => 'Administrator access and a valid request token are required.'], 403);
    }
    $contextId = trim((string) ($_GET['context_id'] ?? ''));
    if (!preg_match('/^phase2-narrative-[a-f0-9]{24}$/', $contextId)) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PHASE2_CONTEXT_UNAVAILABLE';
        exit;
    }
    try {
        $contextRow = (new \BuilderX\AI\PhaseAiContextStore((string) ($user['user_key'] ?? '')))->read($contextId, $projectIdentity);
        $contract = $contextRow['context'] ?? null;
    } catch (Throwable) {
        $contract = null;
    }
    $requiredFields = [
        'context_id',
        'phase_key',
        'workflow',
        'project_scope',
        'objective',
        'target_product_surfaces',
        'rules',
        'source_snapshot',
    ];
    $requiredSnapshotFields = [
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
    $requiredRules = [
        'preserve_meaning',
        'preserve_requirements_urls_and_technical_details',
        'read_only_for_coordinator_and_grammar_specialist',
        'database_write_only_after_database_specialist_approval',
        'no_builderx_platform_edits',
        'no_child_dispatch_from_the_codex_session',
        'if_context_is_missing_or_incomplete_return_only_PHASE2_CONTEXT_UNAVAILABLE',
    ];
    $contractComplete = is_array($contract)
        && array_diff($requiredFields, array_keys($contract)) === []
        && ($contract['workflow'] ?? '') === 'coordinator_to_grammar_to_database'
        && is_array($contract['target_product_surfaces'] ?? null)
        && is_string($contract['context_id'] ?? null)
        && is_string($contract['phase_key'] ?? null)
        && is_string($contract['project_scope'] ?? null)
        && is_string($contract['objective'] ?? null)
        && is_string($contract['target_product_surfaces']['user_portal'] ?? null)
        && is_string($contract['target_product_surfaces']['administrator_portal'] ?? null)
        && is_array($contract['rules'] ?? null)
        && is_array($contract['source_snapshot'] ?? null)
        && array_diff($requiredRules, $contract['rules']) === []
        && array_diff($requiredSnapshotFields, array_keys($contract['source_snapshot'])) === []
        && array_reduce($requiredSnapshotFields, static fn (bool $complete, string $field): bool => $complete && is_string($contract['source_snapshot'][$field]), true);
    if (!$contractComplete) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PHASE2_CONTEXT_UNAVAILABLE';
        exit;
    }

    $respondJson([
        'role' => 'coordinator',
        'status' => 'routed',
        'selected_specialist' => 'narrative-cleanup',
        'next_specialist' => 'database',
        'reason' => 'The complete Phase Builder Narrative & Cleanup contract requires the read-only grammar specialist before Database Specialist validation and persistence approval.',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'load_phase_ai_run') {
    if (!$isAdmin) {
        $respondJson(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    }
    try {
        $runStore = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
        $runKey = trim((string) ($_GET['run_key'] ?? ''));
        $draftKey = trim((string) ($_GET['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
        $workflowKey = strtolower(trim((string) ($_GET['workflow_key'] ?? 'narrative_cleanup')));
        if (!in_array($workflowKey, ['narrative_cleanup', 'requirements_analysis', 'system_architecture', 'ui_ux_design', 'execution_roadmap', 'todo_consolidation', 'todo_execution', 'todo_rollback', 'bridge_diagnostic'], true)) {
            throw new InvalidArgumentException('The requested AI workflow is not available on this route.');
        }
        $run = $runKey !== ''
            ? $runStore->read($runKey, $projectIdentity)
            : $runStore->latest($workflowKey, $draftKey, $projectIdentity);
        $respondJson(['ok' => true, 'data' => ['run' => $run]]);
    } catch (Throwable $error) {
        $respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'phase_ai_bridge') {
    if (!$isAdmin) {
        $respondJson(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    }
    $bridgeAction = strtolower(trim((string) ($_GET['bridge_action'] ?? '')));
    try {
        $adapter = new \BuilderX\AI\BuilderXAiBridgeAdapter($projectRoot, null, $projectWorkspaceRoot);
        if ($bridgeAction === 'health') {
            $respondJson(['ok' => true, 'data' => ['health' => $adapter->health(false)]]);
        }
        if ($bridgeAction === 'capabilities') {
            $respondJson(['ok' => true, 'data' => ['capabilities' => $adapter->capabilities()]]);
        }
        if (in_array($bridgeAction, ['events', 'result'], true)) {
            if (!hash_equals(bx_csrf_token(), (string) ($_GET['csrf'] ?? ''))) {
                $respondJson(['ok' => false, 'message' => 'Invalid request token.'], 403);
            }
            $runKey = trim((string) ($_GET['run_key'] ?? ''));
            $stageKey = strtolower(trim((string) ($_GET['stage_key'] ?? '')));
            $requestId = strtolower(trim((string) ($_GET['request_id'] ?? '')));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')), $adapter);
            $orchestrator->assertBridgeBinding($runKey, $projectIdentity, $stageKey, $requestId);
            if ($bridgeAction === 'result') {
                $respondJson(['ok' => true, 'data' => ['result' => $adapter->result($requestId)]]);
            }
            ignore_user_abort(true);
            set_time_limit(0);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
            try {
                $adapter->streamEvents($requestId, static function (string $chunk): void {
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                });
            } catch (Throwable $streamError) {
                echo "event: failed\ndata: " . json_encode(['message' => $streamError->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            exit;
        }
        $respondJson(['ok' => false, 'message' => 'The shared AI Bridge action is not supported.'], 404);
    } catch (\BuilderX\AI\PhaseAiBridgeException $error) {
        $status = $error->errorCode() === 'PERMISSION_DENIED' ? 403 : ($error->errorCode() === 'LOCK_CONFLICT' ? 409 : 502);
        $respondJson(['ok' => false, 'error_code' => $error->errorCode(), 'message' => $error->getMessage()], $status);
    } catch (Throwable $error) {
        $respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

$redirect = static function (string $phaseKey = '', string $target = ''): never {
    $url = './';
    $query = [];
    if ($phaseKey !== '') {
        $query['phase'] = $phaseKey;
    }
    if ($target === 'builder') {
        $query['target'] = 'builder';
    }
    $phaseView = trim((string) ($_POST['phase_view'] ?? ''));
    if (in_array($phaseView, ['roadmap', 'custom', 'tasks'], true)) {
        $query['phase_view'] = $phaseView;
    }
    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }
    header('Location: ' . $url);
    exit;
};

$fail = static function (string $message, string $details = '') use ($redirect, $isJsonRequest, $respondJson): never {
    if ($isJsonRequest) {
        $respondJson([
            'ok' => false,
            'message' => $message,
            'details' => $details !== '' ? 'The server rejected the request. Check the BuilderX server log for technical details.' : '',
        ], 422);
    }
    bx_flash($message, 'error', $details);
    $redirect((string) ($_POST['phase_key'] ?? ''), (string) ($_POST['target'] ?? ''));
};

$todoChatScope = static function (array $source): array {
    $scope = [];
    foreach (['draft_key', 'phase_id', 'task_id', 'subtask_id', 'todo_id'] as $field) {
        $value = trim((string) ($source[$field] ?? ''));
        if ($field === 'draft_key' && $value === '') $value = bx_phase_builder_current_draft_key();
        if ($value === '' || strlen($value) > 200 || !preg_match('/^[A-Za-z0-9._:-]+$/', $value)) {
            throw new InvalidArgumentException('The selected todo context is incomplete or invalid.');
        }
        $scope[$field] = $value;
    }
    return $scope;
};

$updateRoadmapTodo = static function (array $scope, string $title, string $description) use ($db, $user): array {
    $title = trim($title);
    $description = trim($description);
    if ($title === '' || strlen($title) > 255 || strlen($description) > 5000) {
        throw new InvalidArgumentException('The approved todo title or description is invalid.');
    }
    try {
        $row = $db->GetRow('SELECT roadmap_key, roadmap_json, stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? FOR UPDATE', [$scope['draft_key']]);
        if (!is_array($row)) {
            throw new RuntimeException('The saved execution roadmap was not found.');
        }
        $roadmap = json_decode((string) ($row['roadmap_json'] ?? '{}'), true);
        $stages = json_decode((string) ($row['stages_json'] ?? '{}'), true);
        $roadmap = is_array($roadmap) ? $roadmap : [];
        $stages = is_array($stages) ? $stages : [];
        $changed = false;
        $visit = static function (&$node) use (&$visit, $scope, $title, $description, &$changed): void {
            if (!is_array($node)) return;
            $nodeTodoId = (string) ($node['todoId'] ?? $node['todo_id'] ?? '');
            if (!$changed && $nodeTodoId === $scope['todo_id']) {
                $node['todoTitle'] = $title;
                $node['todoDescription'] = $description;
                $node['todo_title'] = $title;
                $node['todo_description'] = $description;
                $changed = true;
                return;
            }
            foreach ($node as &$child) $visit($child);
            unset($child);
        };
        $visit($roadmap);
        $visit($stages);
        if (!$changed) throw new RuntimeException('The selected todo is no longer present in the saved roadmap.');
        $roadmapJson = json_encode($roadmap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $stagesJson = json_encode($stages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($roadmapJson) || !is_string($stagesJson)) throw new RuntimeException('The updated roadmap could not be encoded.');
        if ($db->Execute('UPDATE phase_builder_execution_roadmap SET roadmap_json = ?, stages_json = ?, updated_by_user_key = ? WHERE roadmap_key = ?', [$roadmapJson, $stagesJson, $user['user_key'] ?? null, $row['roadmap_key']]) === false) {
            throw new RuntimeException('The approved todo update could not be saved.');
        }
        $readBackRoadmap = $db->GetRow('SELECT roadmap_json, stages_json FROM phase_builder_execution_roadmap WHERE roadmap_key = ?', [$row['roadmap_key']]);
        $readBackFound = false;
        $verify = static function ($node) use (&$verify, $scope, $title, &$readBackFound): void {
            if (!is_array($node) || $readBackFound) return;
            if ((string) ($node['todoId'] ?? $node['todo_id'] ?? '') === $scope['todo_id'] && (string) ($node['todoTitle'] ?? $node['todo_title'] ?? '') === $title) { $readBackFound = true; return; }
            foreach ($node as $child) $verify($child);
        };
        if (is_array($readBackRoadmap)) { $verify(json_decode((string) $readBackRoadmap['roadmap_json'], true)); $verify(json_decode((string) $readBackRoadmap['stages_json'], true)); }
        if (!$readBackFound) throw new RuntimeException('The approved todo update could not be read back.');
        bx_audit('update_phase_execution_roadmap_todo', 'phase_builder_execution_roadmap', (string) $row['roadmap_key'], ['todo_id' => $scope['todo_id'], 'todo_title' => $title]);
        return ['todo_id' => $scope['todo_id'], 'todo_title' => $title, 'todo_description' => $description];
    } catch (Throwable $error) {
        throw $error;
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'load_phase_todo_chat') {
    if (!$isAdmin) $respondJson(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    try {
        $scope = $todoChatScope($_GET);
        $messages = $db->GetAll('SELECT message_key, sender, message_text, edited_at, created_at FROM phase_builder_todo_chat_messages WHERE draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND message_status = ? ORDER BY created_at ASC, x_id ASC', [$scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'ACTIVE']);
        foreach ($messages as &$message) {
            $message['attachments'] = $db->GetAll("SELECT attachment_key, original_name, mime_type, byte_size, storage_path, sha256, CONCAT('todo-chat-attachment.php?attachment_key=', attachment_key) AS url FROM phase_builder_todo_chat_attachments WHERE message_key = ? AND attachment_status = ? ORDER BY x_id ASC", [$message['message_key'], 'ACTIVE']);
        }
        unset($message);
        $respondJson(['ok' => true, 'data' => ['messages' => $messages]]);
    } catch (Throwable $error) {
        $respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && trim((string) ($_GET['action'] ?? '')) === 'load_phase_todo_execution_logs') {
    if (!$isAdmin) $respondJson(['ok' => false, 'message' => 'Administrator access is required.'], 403);
    try {
        $scope = $todoChatScope($_GET);
        $logs = $db->GetAll('SELECT execution_key, phase_id, task_id, subtask_id, todo_id, status, rollback_status, result_json, rollback_result_json, created_at, updated_at, completed_at, rolled_back_at FROM phase_builder_todo_execution_logs WHERE draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? ORDER BY created_at DESC, x_id DESC', [$scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
        foreach ($logs as &$log) {
            $log['result'] = json_decode((string) ($log['result_json'] ?? ''), true);
            $log['rollback_result'] = json_decode((string) ($log['rollback_result_json'] ?? ''), true);
            unset($log['result_json'], $log['rollback_result_json']);
        }
        unset($log);
        $respondJson(['ok' => true, 'data' => ['logs' => is_array($logs) ? $logs : []]]);
    } catch (Throwable $error) {
        $respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isJsonRequest && !hash_equals(bx_csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        $respondJson(['ok' => false, 'message' => 'Invalid request token.'], 403);
    }
    bx_verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'phase_login') {
        $login = trim((string) ($_POST['login'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
        $loginTarget = trim((string) ($_POST['target'] ?? '')) === 'builder' ? 'builder' : 'manager';

        if (bx_login($login, $password)) {
            $signedInUser = bx_current_user();
            if (!$signedInUser || !bx_is_admin($signedInUser)) {
                bx_logout();
                bx_flash('Administrator role is required to access Phase Manager.', 'error');
            } else {
                bx_flash('Signed in to Phase Manager.', 'success');
            }
        } else {
            bx_flash('Invalid username or password.', 'error');
        }

        $redirect($phaseKey, $loginTarget);
    }

    if ($action === 'phase_logout') {
        $logoutTarget = trim((string) ($_POST['target'] ?? '')) === 'builder' ? 'builder' : '';
        bx_logout();
        bx_flash('Signed out of Phase Manager.', 'success');
        $redirect('', $logoutTarget);
    }

        if (!$isAdmin) {
            $fail('Administrator access is required to change phases or tasks.');
        }

        try {
        if ($action === 'verify_phase_runtime_permissions') {
            $contextId = 'runtime-permission-check-' . bin2hex(random_bytes(12));
            $payload = [
                'schema_version' => 'builderx.runtime-permission-check.v1',
                'context_id' => $contextId,
                'project_identity' => $projectIdentity,
                'purpose' => 'Verify one directional web-to-AI context publication and read-back.',
            ];
            $materialized = $writePhaseCoordinatorContext($contextId, $payload);
            $readBack = (new \BuilderX\AI\PhaseAiContextStore((string) ($user['user_key'] ?? '')))->verify($contextId, $projectIdentity, (int) $materialized['bytes'], (string) $materialized['sha256']);
            if (($readBack['context'] ?? null) !== $payload) throw new RuntimeException('The MySQL context payload did not match its direct read-back.');
            if ($db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ? AND project_identity = ?', [$contextId, $projectIdentity]) === false || $db->Affected_Rows() !== 1) {
                throw new RuntimeException('The disposable MySQL context could not be removed.');
            }
            $respondJson(['ok' => true, 'data' => [
                'transport' => 'mysql',
                'read_back_verified' => true,
                'disposable_context_removed' => true,
                'materialized_bytes' => $materialized['bytes'],
            ]]);
        }

        if ($action === 'transition_phase_ai_stage') {
            $decodeTransitionObject = static function (string $field): array {
                $raw = trim((string) ($_POST[$field] ?? ''));
                if ($raw === '' || strlen($raw) > 10000000) {
                    throw new InvalidArgumentException('The AI stage payload is missing or too large.');
                }
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new InvalidArgumentException('The AI stage payload must be a JSON object.');
                }
                return $decoded;
            };
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $stageKey = strtolower(trim((string) ($_POST['stage_key'] ?? '')));
            $operation = strtolower(trim((string) ($_POST['operation'] ?? '')));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')));
            $run = match ($operation) {
                'begin' => $orchestrator->begin($runKey, $projectIdentity, $stageKey, $decodeTransitionObject('request_json')),
                'complete' => $orchestrator->complete($runKey, $projectIdentity, $stageKey, $decodeTransitionObject('result_json')),
                'fail' => $orchestrator->fail(
                    $runKey,
                    $projectIdentity,
                    $stageKey,
                    strtoupper(trim((string) ($_POST['error_code'] ?? ''))),
                    trim((string) ($_POST['error_detail'] ?? ''))
                ),
                'cancel' => $orchestrator->cancelBetweenStages($runKey, $projectIdentity, trim((string) ($_POST['reason'] ?? ''))),
                default => throw new InvalidArgumentException('The AI stage transition is invalid.'),
            };
            $respondJson(['ok' => true, 'data' => ['run' => $run]]);
        }

        if ($action === 'restart_phase_ai_bridge') {
            $adapter = new \BuilderX\AI\BuilderXAiBridgeAdapter($projectRoot, null, $projectWorkspaceRoot);
            $restart = $adapter->restart();
            bx_audit('UPDATE', 'phase_ai_bridge', hash('sha256', $projectIdentity), ['action' => 'restart', 'workspace' => $projectRoot]);
            $respondJson(['ok' => true, 'data' => ['restart' => $restart]]);
        }

        if ($action === 'dispatch_phase_ai_stage') {
            try {
                $rawRequest = trim((string) ($_POST['request_json'] ?? ''));
                $request = json_decode($rawRequest, true, 512, JSON_THROW_ON_ERROR);
                if ($rawRequest === '' || strlen($rawRequest) > 10000000 || !is_array($request) || array_is_list($request)) {
                    throw new InvalidArgumentException('The AI Bridge stage request must be a bounded JSON object.');
                }
                $runKey = trim((string) ($_POST['run_key'] ?? ''));
                $stageKey = strtolower(trim((string) ($_POST['stage_key'] ?? '')));
                $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
                $allowSourceChanges = false;
                if ($stageKey === 'implementation') {
                    $run = $store->read($runKey, $projectIdentity);
                    $workflowKey = (string) ($run['workflow_key'] ?? '');
                    if (in_array($workflowKey, ['todo_execution', 'todo_rollback'], true)) {
                        $runRequest = is_array($run['request'] ?? null) ? $run['request'] : [];
                        $executionKey = trim((string) ($runRequest['execution_key'] ?? ''));
                        $serverSourceCheckpoint = $runRequest['server_source_checkpoint'] ?? null;
                        if (!is_array($serverSourceCheckpoint) || array_is_list($serverSourceCheckpoint)) {
                            throw new RuntimeException('The Coding Engine implementation cannot dispatch without a server-created source checkpoint.');
                        }
                        $serverSourceCheckpoint = (new \BuilderX\AI\PhaseAiSourceCheckpoint($projectRoot))->verify(
                            $serverSourceCheckpoint,
                            $executionKey,
                            $runKey,
                            $workflowKey,
                            $projectIdentity
                        );
                        $checkpointColumn = $workflowKey === 'todo_execution' ? 'source_checkpoint_json' : 'rollback_source_checkpoint_json';
                        $savedCheckpointJson = (string) $db->GetOne(
                            "SELECT {$checkpointColumn} FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? LIMIT 1",
                            [$executionKey, $run['draft_key'] ?? null, $run['task_id'] ?? null, $run['subtask_id'] ?? null, $run['todo_id'] ?? null]
                        );
                        $savedCheckpoint = trim($savedCheckpointJson) !== '' ? json_decode($savedCheckpointJson, true) : null;
                        if (!is_array($savedCheckpoint) || array_is_list($savedCheckpoint) || $savedCheckpoint !== $serverSourceCheckpoint) {
                            throw new RuntimeException('The server source checkpoint is not persisted in the saved execution context.');
                        }
                        $request['execution_key'] = $executionKey;
                        $request['server_source_checkpoint'] = $serverSourceCheckpoint;
                        $allowSourceChanges = true;
                    }
                }
                $adapter = new \BuilderX\AI\BuilderXAiBridgeAdapter($projectRoot, null, $projectWorkspaceRoot);
                $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store, $adapter);
                $dispatched = $orchestrator->dispatch(
                    $runKey,
                    $projectIdentity,
                    $stageKey,
                    $request,
                    (string) ($_POST['command'] ?? ''),
                    true,
                    $allowSourceChanges
                );
                $respondJson(['ok' => true, 'data' => $dispatched]);
            } catch (\BuilderX\AI\PhaseAiBridgeException $error) {
                $status = $error->errorCode() === 'PERMISSION_DENIED' ? 403 : ($error->errorCode() === 'LOCK_CONFLICT' ? 409 : 502);
                $respondJson(['ok' => false, 'error_code' => $error->errorCode(), 'message' => $error->getMessage()], $status);
            }
        }

        if ($action === 'prepare_phase_ai_checkpoint_context') {
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $stageKey = strtolower(trim((string) ($_POST['stage_key'] ?? '')));
            $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store);
            $state = $orchestrator->next($runKey, $projectIdentity);
            if (($state['stage']['stage_key'] ?? '') !== $stageKey) {
                throw new RuntimeException('The requested checkpoint context is not the next deterministic AI stage.');
            }
            $workflowKey = (string) ($state['run']['workflow_key'] ?? '');
            if (!in_array($workflowKey, ['system_architecture', 'ui_ux_design', 'execution_roadmap', 'todo_execution', 'todo_rollback'], true)) {
                throw new RuntimeException('This AI workflow does not support a derived checkpoint context.');
            }
            $allowedStages = $workflowKey === 'todo_execution'
                ? ['inspection', 'plan', 'implementation', 'verification', 'evidence', 'git_update']
                : ($workflowKey === 'todo_rollback'
                    ? ['inspection', 'plan', 'implementation', 'verification', 'evidence']
                    : ['integration_review']);
            if (!in_array($stageKey, $allowedStages, true)) {
                throw new RuntimeException('The requested derived checkpoint context is not allowed for this workflow.');
            }
            $serverSourceCheckpoint = null;
            if (in_array($workflowKey, ['todo_execution', 'todo_rollback'], true) && $stageKey === 'implementation') {
                $runRequest = is_array($state['run']['request'] ?? null) ? $state['run']['request'] : [];
                $executionKey = trim((string) ($runRequest['execution_key'] ?? ''));
                $checkpointManager = new \BuilderX\AI\PhaseAiSourceCheckpoint($projectRoot);
                $existingCheckpoint = $runRequest['server_source_checkpoint'] ?? null;
                $createdCheckpoint = false;
                if (is_array($existingCheckpoint) && !array_is_list($existingCheckpoint)) {
                    $serverSourceCheckpoint = $checkpointManager->verify($existingCheckpoint, $executionKey, $runKey, $workflowKey, $projectIdentity);
                } else {
                    $serverSourceCheckpoint = $checkpointManager->create($executionKey, $runKey, $workflowKey, $projectIdentity);
                    $createdCheckpoint = true;
                }
                try {
                    $store->bindServerSourceCheckpoint($runKey, $projectIdentity, $executionKey, $serverSourceCheckpoint);
                } catch (Throwable $checkpointBindingError) {
                    if ($createdCheckpoint) {
                        $checkpointManager->discard($serverSourceCheckpoint, $executionKey, $runKey, $workflowKey, $projectIdentity);
                    }
                    throw $checkpointBindingError;
                }
                $state = $orchestrator->next($runKey, $projectIdentity);
                if (($state['stage']['stage_key'] ?? '') !== 'implementation') {
                    throw new RuntimeException('The implementation stage changed while its server source checkpoint was being bound.');
                }
            }
            $verifiedCheckpoints = [];
            foreach ($state['run']['stages'] ?? [] as $stage) {
                if (!is_array($stage) || ($stage['status'] ?? '') !== 'SUCCEEDED' || !is_array($stage['result'] ?? null)) continue;
                $verifiedCheckpoints[(string) ($stage['stage_key'] ?? '')] = $stage['result'];
            }
            $contextId = 'phase-ai-' . $stageKey . '-' . $runKey;
            $context = [
                'schema_version' => 'builderx.ai-checkpoint-context.v1',
                'context_id' => $contextId,
                'run_key' => $runKey,
                'engine_type' => (string) ($state['run']['engine_type'] ?? ''),
                'workflow_key' => $workflowKey,
                'stage_key' => $stageKey,
                'draft_key' => (string) ($state['run']['draft_key'] ?? ''),
                'phase_key' => (string) ($state['run']['phase_key'] ?? ''),
                'task_id' => (string) ($state['run']['task_id'] ?? ''),
                'subtask_id' => (string) ($state['run']['subtask_id'] ?? ''),
                'todo_id' => (string) ($state['run']['todo_id'] ?? ''),
                'source_hash' => (string) ($state['run']['source_hash'] ?? ''),
                'request' => $state['run']['request'] ?? [],
                'verified_checkpoints' => $verifiedCheckpoints,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::appliesTo($workflowKey)
                    ? \BuilderX\AI\PhaseBuilderPlanningPolicy::context($workflowKey)
                    : null,
                'rules' => [
                    'read_only_for_planning_engine',
                    'coding_engine_changes_only_the_saved_todo_scope',
                    'server_creates_and_hash_verifies_the_private_source_checkpoint_before_implementation_dispatch',
                    'server_source_checkpoint_does_not_protect_database_rollback',
                    'preserve_unrelated_user_changes',
                    'return_only_the_required_versioned_json_object',
                    'do_not_dispatch_another_agent_or_use_codex_cli_or_mcp',
                ],
            ];
            if (is_array($serverSourceCheckpoint)) {
                $context['server_source_checkpoint'] = $serverSourceCheckpoint;
            }
            if ($stageKey === 'integration_review') {
                $analysis = $verifiedCheckpoints['analysis'] ?? null;
                if (!is_array($analysis)) throw new RuntimeException('A validated artifact is required before integration review.');
                $context['artifact_hash'] = \BuilderX\AI\PhaseAiWorkflowContract::hash($analysis);
                $context['required_response'] = [
                    'schemaVersion' => 'builderx.ai-integration-review.v1',
                    'workflowKey' => $workflowKey,
                    'artifactHash' => $context['artifact_hash'],
                    'status' => 'approved | blocked',
                    'findings' => [[
                        'code' => 'One exact blockingPolicy.allowedCodes value when blocked; otherwise a concise suggestion code.',
                        'summary' => 'Required when status is blocked; concise description of one concrete conflict.',
                        'requiredResolution' => 'Specific correction required before persistence.',
                        'relatedArea' => 'Feature or module',
                    ]],
                ];
                if (\BuilderX\AI\PhaseBuilderPlanningPolicy::appliesTo($workflowKey)) {
                    $context['review_instruction'] = 'Block only for a genuine baseline defect represented by an exact allowed blocker code. Report optional improvements as concise findings; the server converts them to non-blocking coding-time suggestions and approves persistence.';
                }
            } else {
                $context['required_response'] = [
                    'schemaVersion' => 'builderx.coding-checkpoint.v1',
                    'workflowKey' => $workflowKey,
                    'stage' => $stageKey,
                    'status' => 'completed | blocked',
                    'evidence' => [],
                ];
                if ($stageKey === 'implementation') {
                    if (!is_array($serverSourceCheckpoint)) {
                        throw new RuntimeException('A verified server source checkpoint is required before implementation context publication.');
                    }
                    $context['required_response'] = [
                        'status' => 'completed | blocked | failed',
                        'summary' => 'Honest implementation summary',
                        'recoveryCheckpoints' => [\BuilderX\AI\PhaseAiSourceCheckpoint::modelEvidence($serverSourceCheckpoint)],
                        'changedFiles' => [],
                        'databaseChanges' => [],
                        'androidChanges' => [],
                        'tests' => [],
                        'blockers' => [],
                        'nextSteps' => [],
                    ];
                }
            }
            $materialized = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'data' => ['context' => ['context_id' => $contextId, ...$materialized], 'run' => $state['run']]]);
        }

        if ($action === 'start_phase_ai_run') {
            $workflowKey = strtolower(trim((string) ($_POST['workflow_key'] ?? '')));
            $engineType = strtoupper(trim((string) ($_POST['engine_type'] ?? '')));
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $phaseKey = trim((string) ($_POST['phase_key'] ?? '')) ?: null;
            $idempotencyKey = strtolower(trim((string) ($_POST['idempotency_key'] ?? '')));
            $sourceSnapshot = json_decode((string) ($_POST['source_snapshot'] ?? '{}'), true);
            $sharedWorkflowConfiguration = [
                'system_architecture' => ['engine' => 'PLANNING', 'route' => 'phases:builder'],
                'ui_ux_design' => ['engine' => 'PLANNING', 'route' => 'phases:builder'],
                'execution_roadmap' => ['engine' => 'PLANNING', 'route' => 'phases:builder'],
                'todo_consolidation' => ['engine' => 'PLANNING', 'route' => 'phases'],
                'todo_execution' => ['engine' => 'CODING', 'route' => 'phases'],
                'todo_rollback' => ['engine' => 'CODING', 'route' => 'phases'],
                'bridge_diagnostic' => ['engine' => 'PLANNING', 'route' => 'phases:builder'],
            ];
            if (isset($sharedWorkflowConfiguration[$workflowKey])) {
                $configuration = $sharedWorkflowConfiguration[$workflowKey];
                if ($engineType !== $configuration['engine'] || !preg_match('/^[A-Za-z0-9._:-]{1,36}$/', $draftKey) || !is_array($sourceSnapshot) || array_is_list($sourceSnapshot)) {
                    $fail('The requested shared AI workflow is invalid.');
                }
                $contextCheckpoint = $sourceSnapshot['context_checkpoint'] ?? null;
                if (!is_array($contextCheckpoint) || array_is_list($contextCheckpoint)) {
                    $fail('A verified server-generated AI context checkpoint is required.');
                }
                $contextId = trim((string) ($contextCheckpoint['context_id'] ?? ''));
                $contextBytes = (int) ($contextCheckpoint['bytes'] ?? 0);
                $contextHash = strtolower(trim((string) ($contextCheckpoint['sha256'] ?? '')));
                try {
                    $contextRow = (new \BuilderX\AI\PhaseAiContextStore((string) ($user['user_key'] ?? '')))->verify($contextId, $projectIdentity, $contextBytes, $contextHash);
                    $contextPayload = $contextRow['context'] ?? null;
                } catch (Throwable $contextError) {
                    $fail('The server-generated MySQL AI context checkpoint failed its size or hash verification.', $contextError->getMessage());
                }
                if (!is_array($contextPayload) || array_is_list($contextPayload)) {
                    $fail('The server-generated MySQL AI context is invalid.');
                }
                $sourceHashes = is_array($sourceSnapshot['source_hashes'] ?? null) && !array_is_list($sourceSnapshot['source_hashes'])
                    ? $sourceSnapshot['source_hashes']
                    : [];
                $expectedHashes = [];
                if ($workflowKey === 'system_architecture') {
                    $json = (string) $db->GetOne('SELECT analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
                    if ($json === '') $fail('Run and save Requirements Analysis before starting System Architecture.');
                    $expectedHashes['requirements_hash'] = hash('sha256', $json);
                } elseif (in_array($workflowKey, ['ui_ux_design', 'execution_roadmap'], true)) {
                    $json = (string) $db->GetOne('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
                    if ($json === '') $fail('Run and save System Architecture before starting this Planning workflow.');
                    $expectedHashes['architecture_hash'] = hash('sha256', $json);
                }
                foreach ($expectedHashes as $hashKey => $expectedHash) {
                    if (!is_string($sourceHashes[$hashKey] ?? null) || !hash_equals($expectedHash, strtolower(trim((string) $sourceHashes[$hashKey])))) {
                        $fail('The saved upstream artifact changed after the AI context was prepared.');
                    }
                }
                $semanticChunkKey = strtolower(trim((string) ($sourceSnapshot['semantic_chunk_key'] ?? '')));
                if ($semanticChunkKey !== '' && preg_match('/^[a-z0-9._:-]{1,80}$/', $semanticChunkKey) !== 1) {
                    $fail('The AI semantic chunk scope is invalid.');
                }
                $taskId = trim((string) ($sourceSnapshot['task_id'] ?? '')) ?: null;
                $subtaskId = trim((string) ($sourceSnapshot['subtask_id'] ?? '')) ?: null;
                $todoId = trim((string) ($sourceSnapshot['todo_id'] ?? '')) ?: null;
                $executionKey = null;
                if (isset($contextPayload['draft_key']) && (string) $contextPayload['draft_key'] !== $draftKey) {
                    $fail('The server-generated AI context belongs to another BuilderX draft.');
                }
                if (in_array($workflowKey, ['todo_consolidation', 'todo_execution', 'todo_rollback'], true)) {
                    $boundContext = $workflowKey === 'todo_rollback'
                        ? ($contextPayload['original_execution_context'] ?? null)
                        : $contextPayload;
                    if (
                        !is_array($boundContext)
                        || (string) ($boundContext['draft_key'] ?? '') !== $draftKey
                        || (string) ($boundContext['task']['id'] ?? '') !== (string) $taskId
                        || (string) ($boundContext['subtask']['id'] ?? '') !== (string) $subtaskId
                        || (string) ($boundContext['todo']['id'] ?? '') !== (string) $todoId
                    ) {
                        $fail('The saved todo scope does not match the server-generated AI context.');
                    }
                    $expectedContextType = match ($workflowKey) {
                        'todo_consolidation' => 'builderx.todo_chat_consolidation.v1',
                        'todo_execution' => 'builderx.todo_execution.v1',
                        'todo_rollback' => 'builderx.todo_execution_rollback.v1',
                    };
                    if ((string) ($contextPayload['context_type'] ?? '') !== $expectedContextType) {
                        $fail('The server-generated AI context type is invalid for this todo workflow.');
                    }
                    if ($workflowKey === 'todo_consolidation') {
                        $consolidationContextRow = $db->GetRow(
                            'SELECT context_json FROM phase_builder_todo_chat_consolidations WHERE draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND approval_status = ? ORDER BY x_id DESC LIMIT 1',
                            [$draftKey, $taskId, $subtaskId, $todoId, 'PENDING']
                        );
                        $savedConsolidationContext = is_array($consolidationContextRow)
                            ? json_decode((string) ($consolidationContextRow['context_json'] ?? ''), true)
                            : null;
                        if (!is_array($savedConsolidationContext) || $savedConsolidationContext !== $contextPayload) {
                            $fail('The Planning Engine consolidation context is not bound to one saved pending approval.');
                        }
                    }
                    if ($workflowKey === 'todo_execution') {
                        $workspaceRoot = realpath((string) ($contextPayload['workspace_root'] ?? ''));
                        $executionKey = trim((string) ($contextPayload['execution_key'] ?? ''));
                        if (!is_string($workspaceRoot) || str_replace('\\', '/', $workspaceRoot) !== $projectRoot) {
                            $fail('The Coding Engine context is not bound to the current project root.');
                        }
                        $executionContextRow = $db->GetRow(
                            'SELECT context_json FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND status = ? LIMIT 1',
                            [$executionKey, $draftKey, $taskId, $subtaskId, $todoId, 'RUNNING']
                        );
                        $savedExecutionContext = is_array($executionContextRow)
                            ? json_decode((string) ($executionContextRow['context_json'] ?? ''), true)
                            : null;
                        if ($executionKey === '' || !is_array($savedExecutionContext) || $savedExecutionContext !== $contextPayload) {
                            $fail('The Coding Engine context is not bound to one saved running todo execution.');
                        }
                    }
                    if ($workflowKey === 'todo_rollback') {
                        $executionKey = trim((string) ($contextPayload['execution_key'] ?? ''));
                        $rollbackRow = $db->GetRow(
                            'SELECT context_json, result_json FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND rollback_status = ? LIMIT 1',
                            [$executionKey, $draftKey, $taskId, $subtaskId, $todoId, 'RUNNING']
                        );
                        $savedOriginalContext = is_array($rollbackRow) ? json_decode((string) ($rollbackRow['context_json'] ?? ''), true) : null;
                        $savedOriginalResult = is_array($rollbackRow) ? json_decode((string) ($rollbackRow['result_json'] ?? ''), true) : null;
                        if (
                            $executionKey === ''
                            || !is_array($rollbackRow)
                            || !is_array($savedOriginalContext)
                            || !is_array($savedOriginalResult)
                            || $savedOriginalContext !== ($contextPayload['original_execution_context'] ?? null)
                            || $savedOriginalResult !== ($contextPayload['original_execution_report'] ?? null)
                        ) {
                            $fail('The rollback context is not bound to one saved running rollback stage.');
                        }
                    }
                }
                $runRequest = [
                    'schema_version' => 'builderx.ai-run.request.v1',
                    'route_key' => $configuration['route'],
                    'workflow_key' => $workflowKey,
                    'draft_key' => $draftKey,
                    'phase_key' => $phaseKey,
                    'task_id' => $taskId,
                    'subtask_id' => $subtaskId,
                    'todo_id' => $todoId,
                    'execution_key' => $executionKey,
                    'semantic_chunk_key' => $semanticChunkKey,
                    'source_hashes' => $expectedHashes !== [] ? $expectedHashes : $sourceHashes,
                    'context_checkpoint' => [
                        'context_id' => $contextId,
                        'context_ref' => 'mysql:phase_builder_ai_context/' . $contextId,
                        'bytes' => $contextBytes,
                        'sha256' => $contextHash,
                    ],
                    'git_update_approved' => false,
                ];
                $run = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->start(
                    $engineType,
                    $workflowKey,
                    $draftKey,
                    $phaseKey,
                    $projectIdentity,
                    $idempotencyKey,
                    $runRequest,
                    (string) ($user['user_key'] ?? '') ?: null
                );
                $respondJson(['ok' => true, 'data' => ['run' => $run]]);
            }
            $sourceFields = [
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
            if (!in_array($workflowKey, ['narrative_cleanup', 'requirements_analysis'], true) || $engineType !== 'PLANNING') {
                $fail('The requested Phase Builder AI run is invalid.');
            }
            $savedDraft = $db->GetRow(
                'SELECT product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                [$draftKey]
            );
            if (!is_array($savedDraft)) {
                $fail('Save the current Narrative & Cleanup draft before starting the Planning Engine workflow.');
            }
            $normalizedSnapshot = [];
            if ($workflowKey === 'narrative_cleanup') {
                if (!is_array($sourceSnapshot) || array_is_list($sourceSnapshot)) {
                    $fail('The complete Narrative & Cleanup source is required before starting the AI run.');
                }
                foreach ($sourceFields as $field) {
                    if (!array_key_exists($field, $sourceSnapshot) || !is_string($sourceSnapshot[$field]) || strlen($sourceSnapshot[$field]) > 1000000) {
                        $fail('The complete Narrative & Cleanup source is required before starting the AI run.');
                    }
                    $normalizedSnapshot[$field] = str_replace(["\r\n", "\r"], "\n", $sourceSnapshot[$field]);
                    if (str_replace(["\r\n", "\r"], "\n", (string) ($savedDraft[$field] ?? '')) !== $normalizedSnapshot[$field]) {
                        $fail('The Narrative & Cleanup editor has unsaved changes. Save the draft before starting its AI run.');
                    }
                }
            } else {
                foreach ($sourceFields as $field) {
                    $value = str_replace(["\r\n", "\r"], "\n", (string) ($savedDraft[$field] ?? ''));
                    if (strlen($value) > 1000000) {
                        $fail('The saved Narrative & Cleanup source is too large for Requirements Analysis.');
                    }
                    $normalizedSnapshot[$field] = $value;
                }
                if (trim(implode('', $normalizedSnapshot)) === '') {
                    $fail('Save Narrative & Cleanup before starting Requirements Analysis.');
                }
            }
            if ($phaseKey !== null) {
                $phaseExists = (int) $db->GetOne("SELECT COUNT(*) FROM builder_phase WHERE phase_key = ? AND phase_status <> 'DELETED'", [$phaseKey]);
                if ($phaseExists !== 1) {
                    $fail('The selected BuilderX phase was not found.');
                }
            }
            $runRequest = [
                'schema_version' => $workflowKey === 'requirements_analysis' ? 'builderx.requirements-analysis.request.v1' : 'builderx.ai-run.request.v1',
                'route_key' => 'phases:builder',
                'workflow_key' => $workflowKey,
                'draft_key' => $draftKey,
                'phase_key' => $phaseKey,
                'source_snapshot' => $normalizedSnapshot,
            ];
            if ($workflowKey === 'requirements_analysis') {
                $runRequest['source_narrative_hash'] = \BuilderX\AI\RequirementsAnalysisWorkflow::hashObject($normalizedSnapshot);
                $runRequest['semantic_chunk_keys'] = array_values(array_map(
                    static fn (array $configuration): string => $configuration['chunk_key'],
                    \BuilderX\AI\RequirementsAnalysisWorkflow::CHUNKS
                ));
            }
            $run = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->start(
                $engineType,
                $workflowKey,
                $draftKey,
                $phaseKey,
                $projectIdentity,
                $idempotencyKey,
                $runRequest,
                (string) ($user['user_key'] ?? '') ?: null
            );
            $respondJson(['ok' => true, 'data' => ['run' => $run]]);
        }

        if ($action === 'prepare_requirements_analysis_run_context') {
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store);
            $run = $store->read($runKey, $projectIdentity);
            if (($run['workflow_key'] ?? '') !== \BuilderX\AI\RequirementsAnalysisWorkflow::WORKFLOW_KEY) {
                throw new InvalidArgumentException('The persistent run is not a Requirements Analysis workflow.');
            }
            $contextStage = null;
            foreach ($run['stages'] ?? [] as $stage) {
                if (is_array($stage) && ($stage['stage_key'] ?? '') === 'context') {
                    $contextStage = $stage;
                    break;
                }
            }
            if (($contextStage['status'] ?? '') === 'SUCCEEDED' && is_array($contextStage['result'] ?? null)) {
                $respondJson(['ok' => true, 'data' => ['run' => $run, 'context' => $contextStage['result']]]);
            }
            try {
                $orchestrator->begin($runKey, $projectIdentity, 'context', ['operation' => 'materialize_bounded_requirements_context']);
                $run = $store->read($runKey, $projectIdentity);
                $request = $run['request'] ?? null;
                if (!is_array($request) || !is_array($request['source_snapshot'] ?? null)) {
                    throw new RuntimeException('The persisted Requirements Analysis source is unavailable.');
                }
                $contextId = 'requirements-run-' . $runKey;
                $context = [
                    'schema_version' => 'builderx.requirements-analysis.context.v1',
                    'run_key' => $runKey,
                    'workflow_key' => \BuilderX\AI\RequirementsAnalysisWorkflow::WORKFLOW_KEY,
                    'engine_type' => \BuilderX\AI\PhaseAiRunStore::ENGINE_PLANNING,
                    'draft_key' => $run['draft_key'],
                    'phase_key' => $run['phase_key'],
                    'project_identity' => $projectIdentity,
                    'route_key' => $run['route_key'],
                    'source_narrative_hash' => $request['source_narrative_hash'],
                    'source_snapshot' => $request['source_snapshot'],
                    'semantic_chunks' => \BuilderX\AI\RequirementsAnalysisWorkflow::CHUNKS,
                    'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('requirements_analysis'),
                    'global_rules' => [
                        'Use only the supplied saved Narrative and Cleanup source.',
                        'Return only requirements directly supported by that source; an empty chunk is valid.',
                        'Merge overlapping statements and do not invent production-readiness requirements to populate categories.',
                        'Return exactly one JSON object to the MySQL job result with no markdown.',
                        'Do not edit files, execute SQL, call another provider, or dispatch another agent.',
                        'Preserve the supplied draft key, narrative hash, stage key, chunk key, and requirement ID prefix.',
                        'Separate confirmed requirements from assumptions and open questions.',
                        'Every requirement must be testable and must cite at least one supplied source field.',
                        'The Phase Builder is the control plane and is not part of the generated product scope.',
                    ],
                    'chunk_response_contract' => [
                        'schemaVersion' => \BuilderX\AI\RequirementsAnalysisWorkflow::CHUNK_SCHEMA,
                        'workflowKey' => \BuilderX\AI\RequirementsAnalysisWorkflow::WORKFLOW_KEY,
                        'stageKey' => 'exact semantic-chunk stage key',
                        'chunkKey' => 'exact semantic chunk key',
                        'source' => ['draftKey' => $run['draft_key'], 'narrativeHash' => $request['source_narrative_hash']],
                        'summary' => 'Bounded summary for this semantic chunk.',
                        'actors' => [['actorId' => 'ACTOR-001', 'name' => 'Actor name', 'role' => 'Actor role', 'sourceReferences' => ['users_and_roles']]],
                        'entities' => [['entityId' => 'ENTITY-001', 'name' => 'Entity name', 'description' => 'Entity purpose', 'sourceReferences' => ['database_and_synchronization']]],
                        'portals' => [['portalKey' => 'user_portal', 'label' => 'User Portal', 'description' => 'Bounded surface scope', 'sourceReferences' => ['web_requirements']]],
                        'requirements' => [[
                            'requirementId' => 'use the configured chunk id_prefix plus a three digit sequence',
                            'category' => 'one category allowlisted for the chunk',
                            'title' => 'Requirement title',
                            'description' => 'Testable requirement statement',
                            'priority' => 'Must | Should | Could',
                            'status' => 'Proposed',
                            'isSelected' => true,
                            'sourceReferences' => ['source field name'],
                            'acceptanceCriteria' => ['Observable acceptance condition'],
                            'dependencies' => [],
                            'assumptions' => [],
                            'risks' => [],
                            'verificationMethod' => 'Review and test',
                        ]],
                        'missingDetailsOrRisks' => [],
                        'assumptions' => [],
                        'openQuestions' => [],
                    ],
                ];
                $materialized = $writePhaseCoordinatorContext($contextId, $context);
                $contextResult = ['context_id' => $contextId, ...$materialized];
                $run = $orchestrator->complete($runKey, $projectIdentity, 'context', $contextResult);
                $respondJson(['ok' => true, 'data' => ['run' => $run, 'context' => $contextResult]]);
            } catch (Throwable $error) {
                try {
                    $state = $orchestrator->next($runKey, $projectIdentity);
                    if (($state['stage']['stage_key'] ?? '') === 'context' && in_array((string) ($state['stage']['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
                        $orchestrator->fail($runKey, $projectIdentity, 'context', 'PERSISTENCE_FAILED', $error->getMessage());
                    }
                } catch (Throwable) {
                    // Preserve the original materialization error.
                }
                throw $error;
            }
        }

        if ($action === 'merge_requirements_analysis_run') {
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store);
            $run = $store->read($runKey, $projectIdentity);
            $mergeStage = null;
            foreach ($run['stages'] ?? [] as $stage) {
                if (is_array($stage) && ($stage['stage_key'] ?? '') === 'merge') {
                    $mergeStage = $stage;
                    break;
                }
            }
            if (($mergeStage['status'] ?? '') === 'SUCCEEDED' && is_array($mergeStage['result'] ?? null)) {
                $respondJson(['ok' => true, 'data' => ['run' => $run, 'merge' => $mergeStage['result']]]);
            }
            $orchestrator->begin($runKey, $projectIdentity, 'merge', ['operation' => 'deterministic_merge', 'semantic_chunk_count' => 9]);
            $run = $store->read($runKey, $projectIdentity);
            $merged = \BuilderX\AI\RequirementsAnalysisWorkflow::merge($run);
            $run = $orchestrator->complete($runKey, $projectIdentity, 'merge', $merged);
            $respondJson(['ok' => true, 'data' => ['run' => $run, 'merge' => $merged]]);
        }

        if ($action === 'prepare_requirements_integration_review') {
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store);
            $state = $orchestrator->next($runKey, $projectIdentity);
            if (($state['stage']['stage_key'] ?? '') !== 'integration_review') {
                throw new RuntimeException('The Requirements Analysis integration review is not the next deterministic stage.');
            }
            $merge = null;
            foreach ($state['run']['stages'] ?? [] as $stage) {
                if (is_array($stage) && ($stage['stage_key'] ?? '') === 'merge') {
                    $merge = $stage['result'] ?? null;
                    break;
                }
            }
            if (!is_array($merge)) {
                throw new RuntimeException('The deterministic Requirements Analysis merge is unavailable.');
            }
            $requirementIds = [];
            foreach (\BuilderX\AI\RequirementsAnalysisWorkflow::CATEGORY_KEYS as $categoryKey) {
                foreach (($merge['contract'][$categoryKey] ?? []) as $requirement) {
                    if (is_array($requirement)) {
                        $requirementIds[] = (string) ($requirement['requirementId'] ?? '');
                    }
                }
            }
            sort($requirementIds);
            $contextId = 'requirements-review-' . $runKey;
            $reviewContext = [
                'schema_version' => 'builderx.requirements-analysis.review-context.v1',
                'run_key' => $runKey,
                'workflow_key' => \BuilderX\AI\RequirementsAnalysisWorkflow::WORKFLOW_KEY,
                'source_narrative_hash' => $merge['sourceNarrativeHash'],
                'merged_contract_hash' => $merge['contractHash'],
                'merged_contract' => $merge['contract'],
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('requirements_analysis'),
                'rules' => [
                    'Review only the supplied merged contract.',
                    'Do not change, omit, or invent requirement IDs.',
                    'Approve the minimum confirmed baseline when its essential meaning is coherent.',
                    'Treat optional completeness, detail, optimization, and future improvements as informational or warning suggestions only.',
                    'Do not edit files, execute SQL, or dispatch another agent.',
                ],
                'required_response' => [
                    'schemaVersion' => \BuilderX\AI\RequirementsAnalysisWorkflow::REVIEW_SCHEMA,
                    'workflowKey' => \BuilderX\AI\RequirementsAnalysisWorkflow::WORKFLOW_KEY,
                    'sourceNarrativeHash' => $merge['sourceNarrativeHash'],
                    'mergedContractHash' => $merge['contractHash'],
                    'status' => 'approved',
                    'findings' => [['severity' => 'info | warning', 'message' => 'Review finding']],
                    'confirmedRequirementIds' => $requirementIds,
                ],
            ];
            $materialized = $writePhaseCoordinatorContext($contextId, $reviewContext);
            $respondJson(['ok' => true, 'data' => ['run' => $state['run'], 'review_context' => ['context_id' => $contextId, ...$materialized]]]);
        }

        if ($action === 'persist_requirements_analysis_run') {
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $store = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, $store);
            $state = $orchestrator->next($runKey, $projectIdentity);
            if (($state['stage']['stage_key'] ?? '') !== 'persistence') {
                throw new RuntimeException('The Requirements Analysis persistence stage is not ready.');
            }
            $merge = null;
            $review = null;
            foreach ($state['run']['stages'] ?? [] as $stage) {
                if (!is_array($stage)) continue;
                if (($stage['stage_key'] ?? '') === 'merge') $merge = $stage['result'] ?? null;
                if (($stage['stage_key'] ?? '') === 'integration_review') $review = $stage['result'] ?? null;
            }
            if (!is_array($merge) || !is_array($review)) {
                throw new RuntimeException('The verified Requirements Analysis merge and integration review are required.');
            }
            \BuilderX\AI\RequirementsAnalysisWorkflow::validateReview($state['run'], $review);
            $orchestrator->begin($runKey, $projectIdentity, 'persistence', ['operation' => 'transactional_requirements_upsert', 'contract_hash' => $merge['contractHash']]);
            $contract = $merge['contract'] ?? null;
            if (!is_array($contract) || array_is_list($contract)) {
                throw new RuntimeException('The merged Requirements Analysis contract is invalid.');
            }
            $canonicalJson = json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $draftKey = (string) ($state['run']['draft_key'] ?? '');
            $sourceHash = (string) ($merge['sourceNarrativeHash'] ?? '');
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                if ($db->BeginTrans() === false) {
                    throw new RuntimeException('The Requirements Analysis transaction could not start.');
                }
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT analysis_key, source_narrative_hash, analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $existingAnalysisKey = is_array($existing) ? trim((string) ($existing['analysis_key'] ?? '')) : '';
                $analysisKey = $existingAnalysisKey !== '' ? $existingAnalysisKey : bx_uuid();
                $status = $existingAnalysisKey === '' ? 'created' : (
                    (string) ($existing['source_narrative_hash'] ?? '') === $sourceHash && (string) ($existing['analysis_json'] ?? '') === $canonicalJson
                        ? 'already_saved'
                        : 'updated'
                );
                $saved = $db->Execute(
                    'INSERT INTO phase_builder_requirements_analysis (analysis_key, draft_key, source_narrative_hash, analysis_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_narrative_hash = VALUES(source_narrative_hash), analysis_json = VALUES(analysis_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                    [$analysisKey, $draftKey, $sourceHash, $canonicalJson, $userKey ?: null, $userKey ?: null]
                );
                if ($saved === false) {
                    throw new RuntimeException('The Requirements Analysis upsert failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT analysis_key, draft_key, source_narrative_hash, analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($readBack) || (string) ($readBack['analysis_key'] ?? '') !== $analysisKey || (string) ($readBack['source_narrative_hash'] ?? '') !== $sourceHash || (string) ($readBack['analysis_json'] ?? '') !== $canonicalJson) {
                    throw new RuntimeException('The Requirements Analysis transaction read-back failed.');
                }
                bx_audit($status === 'created' ? 'CREATE' : 'UPDATE', 'phase_builder_requirements_analysis', $analysisKey, ['draft_key' => $draftKey, 'source_narrative_hash' => $sourceHash, 'run_key' => $runKey, 'contract_hash' => $merge['contractHash']]);
                if ($db->CommitTrans() === false) {
                    throw new RuntimeException('The Requirements Analysis transaction could not commit.');
                }
                $transactionStarted = false;
                $postCommit = $db->GetRow('SELECT analysis_key, source_narrative_hash, analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($postCommit) || (string) ($postCommit['analysis_key'] ?? '') !== $analysisKey || (string) ($postCommit['source_narrative_hash'] ?? '') !== $sourceHash || (string) ($postCommit['analysis_json'] ?? '') !== $canonicalJson) {
                    throw new RuntimeException('The Requirements Analysis post-commit read-back failed.');
                }
                $persistenceResult = [
                    'schemaVersion' => \BuilderX\AI\RequirementsAnalysisWorkflow::PERSISTENCE_SCHEMA,
                    'status' => $status,
                    'draftKey' => $draftKey,
                    'sourceNarrativeHash' => $sourceHash,
                    'analysisKey' => $analysisKey,
                    'contractHash' => (string) $merge['contractHash'],
                    'readBackVerified' => true,
                ];
                $run = $orchestrator->complete($runKey, $projectIdentity, 'persistence', $persistenceResult);
                $respondJson(['ok' => true, 'message' => 'Requirements Analysis saved and verified.', 'data' => ['run' => $run, 'requirements_analysis' => $contract, 'persistence' => $persistenceResult]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                try {
                    $current = $orchestrator->next($runKey, $projectIdentity);
                    if (($current['stage']['stage_key'] ?? '') === 'persistence' && in_array((string) ($current['stage']['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
                        $orchestrator->fail($runKey, $projectIdentity, 'persistence', str_contains(strtolower($error->getMessage()), 'read-back') ? 'READ_BACK_FAILED' : 'PERSISTENCE_FAILED', $error->getMessage());
                    }
                } catch (Throwable) {
                    // Preserve the original persistence error.
                }
                throw $error;
            }
        }

        if ($action === 'checkpoint_phase_ai_run') {
            $decodeCheckpointJson = static function (string $field): ?array {
                $raw = trim((string) ($_POST[$field] ?? ''));
                if ($raw === '') {
                    return null;
                }
                if (strlen($raw) > 10000000) {
                    throw new InvalidArgumentException('An AI checkpoint payload is too large.');
                }
                $decoded = json_decode($raw, true);
                if (!is_array($decoded) || array_is_list($decoded)) {
                    throw new InvalidArgumentException('An AI checkpoint payload is not a valid JSON object.');
                }
                return $decoded;
            };
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $stageKey = trim((string) ($_POST['stage_key'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['status'] ?? '')));
            $orchestrator = new \BuilderX\AI\PhaseAiOrchestrator($projectRoot, new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')));
            $run = match ($status) {
                'RUNNING' => $orchestrator->begin($runKey, $projectIdentity, $stageKey, $decodeCheckpointJson('request_json') ?? []),
                'SUCCEEDED' => $orchestrator->complete($runKey, $projectIdentity, $stageKey, $decodeCheckpointJson('result_json') ?? []),
                'FAILED' => $orchestrator->fail(
                    $runKey,
                    $projectIdentity,
                    $stageKey,
                    trim((string) ($_POST['error_code'] ?? '')),
                    trim((string) ($_POST['error_detail'] ?? ''))
                ),
                default => throw new InvalidArgumentException('Use the deterministic AI stage transition endpoint for this status.'),
            };
            $respondJson(['ok' => true, 'data' => ['run' => $run]]);
        }

        if (in_array($action, ['save_phase_todo_chat_message', 'edit_phase_todo_chat_message', 'delete_phase_todo_chat_message', 'prepare_phase_todo_execution', 'save_phase_todo_execution_log', 'prepare_phase_todo_rollback', 'save_phase_todo_rollback', 'consolidate_phase_todo_chat', 'save_phase_todo_chat_ai_result', 'approve_phase_todo_chat'], true)) {
            $scope = $todoChatScope($_POST);
            $userKey = $user['user_key'] ?? null;
            $verifiedRunStageResult = static function (string $runKey, string $workflowKey, string $stageKey, string $expectedRecordKey = '') use ($user, $projectIdentity, $scope): array {
                if (!preg_match('/^[A-Za-z0-9._:-]{1,36}$/', $runKey)) {
                    throw new InvalidArgumentException('The persistent AI run key is invalid.');
                }
                $run = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->read($runKey, $projectIdentity);
                if (
                    (string) ($run['workflow_key'] ?? '') !== $workflowKey
                    || (string) ($run['draft_key'] ?? '') !== $scope['draft_key']
                    || (string) ($run['task_id'] ?? '') !== $scope['task_id']
                    || (string) ($run['subtask_id'] ?? '') !== $scope['subtask_id']
                    || (string) ($run['todo_id'] ?? '') !== $scope['todo_id']
                ) {
                    throw new RuntimeException('The persistent AI run does not match the saved todo scope.');
                }
                if ($expectedRecordKey !== '') {
                    $request = is_array($run['request'] ?? null) ? $run['request'] : [];
                    $contextKey = (string) ($request['context_checkpoint']['context_id'] ?? '');
                    try {
                        $contextRow = (new \BuilderX\AI\PhaseAiContextStore((string) ($user['user_key'] ?? '')))->read($contextKey, $projectIdentity);
                        $context = $contextRow['context'] ?? null;
                    } catch (Throwable) {
                        $context = null;
                    }
                    $recordField = $workflowKey === 'todo_consolidation' ? 'consolidation_key' : 'execution_key';
                    if (!is_array($context) || (string) ($context[$recordField] ?? '') !== $expectedRecordKey) {
                        throw new RuntimeException('The persistent AI run is not bound to this saved workflow record.');
                    }
                }
                foreach ($run['stages'] ?? [] as $stage) {
                    if (is_array($stage) && ($stage['stage_key'] ?? '') === $stageKey && ($stage['status'] ?? '') === 'SUCCEEDED' && is_array($stage['result'] ?? null)) {
                        return $stage['result'];
                    }
                }
                throw new RuntimeException('The required persistent AI stage result was not verified.');
            };
            $verifiedServerSourceCheckpointForRun = static function (string $runKey, string $workflowKey, string $executionKey) use ($user, $projectIdentity, $projectRoot, $scope, $db): array {
                $run = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->read($runKey, $projectIdentity);
                if (
                    (string) ($run['workflow_key'] ?? '') !== $workflowKey
                    || (string) ($run['draft_key'] ?? '') !== $scope['draft_key']
                    || (string) ($run['task_id'] ?? '') !== $scope['task_id']
                    || (string) ($run['subtask_id'] ?? '') !== $scope['subtask_id']
                    || (string) ($run['todo_id'] ?? '') !== $scope['todo_id']
                ) {
                    throw new RuntimeException('The server source checkpoint run does not match the saved todo scope.');
                }
                $request = is_array($run['request'] ?? null) ? $run['request'] : [];
                $checkpoint = $request['server_source_checkpoint'] ?? null;
                if ((string) ($request['execution_key'] ?? '') !== $executionKey || !is_array($checkpoint) || array_is_list($checkpoint)) {
                    throw new RuntimeException('The Coding Engine run has no server source checkpoint for this execution.');
                }
                $checkpoint = (new \BuilderX\AI\PhaseAiSourceCheckpoint($projectRoot))->verify(
                    $checkpoint,
                    $executionKey,
                    $runKey,
                    $workflowKey,
                    $projectIdentity
                );
                $checkpointColumn = $workflowKey === 'todo_execution' ? 'source_checkpoint_json' : 'rollback_source_checkpoint_json';
                $savedJson = (string) $db->GetOne(
                    "SELECT {$checkpointColumn} FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? LIMIT 1",
                    [$executionKey, $scope['draft_key'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]
                );
                $saved = trim($savedJson) !== '' ? json_decode($savedJson, true) : null;
                if (!is_array($saved) || array_is_list($saved) || $saved !== $checkpoint) {
                    throw new RuntimeException('The verified server source checkpoint is missing from the saved execution context.');
                }
                return $checkpoint;
            };

            if ($action === 'save_phase_todo_chat_message') {
                $message = trim((string) ($_POST['message'] ?? ''));
                if ($message === '' || strlen($message) > 8000) $fail('Chat messages must contain 1 to 8,000 characters.');
                $attachments = json_decode((string) ($_POST['attachments'] ?? '[]'), true);
                if (!is_array($attachments) || count($attachments) > 5) $fail('Attach up to five images per message.');
                $messageKey = bx_uuid();
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo chat message transaction could not start.');
                try {
                    if ($db->Execute('INSERT INTO phase_builder_todo_chat_messages (message_key, draft_key, phase_id, task_id, subtask_id, todo_id, sender, message_text, created_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', [$messageKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'user', $message, $userKey]) === false) {
                        throw new RuntimeException('The todo chat message could not be saved.');
                    }
                    $writtenAttachmentPaths = [];
                    foreach ($attachments as $attachment) {
                        if (!is_array($attachment)) continue;
                        $dataUrl = trim((string) ($attachment['dataUrl'] ?? $attachment['data_url'] ?? ''));
                        $mime = strtolower(trim((string) ($attachment['mimeType'] ?? $attachment['mime_type'] ?? '')));
                        $name = trim((string) ($attachment['name'] ?? $attachment['original_name'] ?? 'image'));
                        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true) || !preg_match('/^data:' . preg_quote($mime, '/') . ';base64,/', $dataUrl)) $fail('Only PNG, JPEG, WEBP, and GIF images are supported.');
                        $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
                        if (!is_string($binary) || strlen($binary) > 5242880) $fail('Each chat image must be 5 MB or smaller.');
                        $attachmentKey = bx_uuid();
                        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime];
                        $relativeDirectory = '_Document/attachments/todo-chat/' . $messageKey;
                        $absoluteDirectory = $projectRoot . '/' . $relativeDirectory;
                        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) throw new RuntimeException('The todo chat attachment directory could not be created.');
                        $relativePath = $relativeDirectory . '/' . $attachmentKey . '.' . $extension;
                        $absolutePath = $projectRoot . '/' . $relativePath;
                        $temporaryPath = $absoluteDirectory . '/.' . $attachmentKey . '.tmp';
                        if (file_put_contents($temporaryPath, $binary, LOCK_EX) !== strlen($binary) || !rename($temporaryPath, $absolutePath)) {
                            @unlink($temporaryPath);
                            throw new RuntimeException('A todo chat attachment could not be written.');
                        }
                        chmod($absolutePath, 0644);
                        $writtenAttachmentPaths[] = $absolutePath;
                        if ($db->Execute('INSERT INTO phase_builder_todo_chat_attachments (attachment_key, message_key, original_name, mime_type, byte_size, storage_path, sha256) VALUES (?, ?, ?, ?, ?, ?, ?)', [$attachmentKey, $messageKey, substr($name !== '' ? $name : 'image', 0, 255), $mime, strlen($binary), $relativePath, hash('sha256', $binary)]) === false) {
                            throw new RuntimeException('A todo chat attachment could not be saved.');
                        }
                    }
                    $saved = $db->GetRow('SELECT message_key, sender, message_text, edited_at, created_at FROM phase_builder_todo_chat_messages WHERE message_key = ?', [$messageKey]);
                    if (!is_array($saved) || (string) ($saved['message_text'] ?? '') !== $message) throw new RuntimeException('The todo chat message could not be read back.');
                    $saved['attachments'] = $db->GetAll("SELECT attachment_key, original_name, mime_type, byte_size, storage_path, sha256, CONCAT('todo-chat-attachment.php?attachment_key=', attachment_key) AS url FROM phase_builder_todo_chat_attachments WHERE message_key = ?", [$messageKey]);
                    bx_audit('CREATE', 'phase_builder_todo_chat_messages', $messageKey, ['todo_id' => $scope['todo_id']]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo chat message transaction could not commit.');
                } catch (Throwable $error) { $db->RollbackTrans(); foreach ($writtenAttachmentPaths ?? [] as $writtenAttachmentPath) @unlink($writtenAttachmentPath); throw $error; }
                $respondJson(['ok' => true, 'data' => ['message' => $saved]]);
            }

            if ($action === 'edit_phase_todo_chat_message') {
                $messageKey = trim((string) ($_POST['message_key'] ?? ''));
                $message = trim((string) ($_POST['message'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $messageKey) || $message === '' || strlen($message) > 8000) $fail('The chat message could not be edited.');
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo chat edit transaction could not start.');
                try {
                    $updated = $db->Execute('UPDATE phase_builder_todo_chat_messages SET message_text = ?, edited_at = CURRENT_TIMESTAMP WHERE message_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND sender = ? AND message_status = ?', [$message, $messageKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'user', 'ACTIVE']);
                    if ($updated === false || $db->Affected_Rows() !== 1) throw new RuntimeException('The chat message was not found or is not editable.');
                    $saved = $db->GetRow('SELECT message_key, sender, message_text, edited_at, created_at FROM phase_builder_todo_chat_messages WHERE message_key = ?', [$messageKey]);
                    if (!is_array($saved) || (string) ($saved['message_text'] ?? '') !== $message) throw new RuntimeException('The edited chat message could not be read back.');
                    $saved['attachments'] = $db->GetAll("SELECT attachment_key, original_name, mime_type, byte_size, storage_path, sha256, CONCAT('todo-chat-attachment.php?attachment_key=', attachment_key) AS url FROM phase_builder_todo_chat_attachments WHERE message_key = ? AND attachment_status = ?", [$messageKey, 'ACTIVE']);
                    bx_audit('UPDATE', 'phase_builder_todo_chat_messages', $messageKey, ['todo_id' => $scope['todo_id']]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo chat edit transaction could not commit.');
                } catch (Throwable $error) { $db->RollbackTrans(); throw $error; }
                $respondJson(['ok' => true, 'data' => ['message' => $saved]]);
            }

            if ($action === 'delete_phase_todo_chat_message') {
                $messageKey = trim((string) ($_POST['message_key'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $messageKey)) $fail('The chat message key is invalid.');
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo chat deletion transaction could not start.');
                try {
                    if ($db->Execute('UPDATE phase_builder_todo_chat_messages SET message_status = ?, deleted_at = CURRENT_TIMESTAMP WHERE message_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND sender = ? AND message_status = ?', ['DELETED', $messageKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'user', 'ACTIVE']) === false) throw new RuntimeException('The chat message could not be deleted.');
                    if ($db->Affected_Rows() !== 1) $fail('The chat message was not found or is not deletable.');
                    if ($db->Execute('UPDATE phase_builder_todo_chat_attachments SET attachment_status = ? WHERE message_key = ?', ['DELETED', $messageKey]) === false) throw new RuntimeException('The chat message attachments could not be deleted.');
                    $deletedReadBack = $db->GetOne('SELECT message_status FROM phase_builder_todo_chat_messages WHERE message_key = ?', [$messageKey]);
                    if ((string) $deletedReadBack !== 'DELETED') throw new RuntimeException('The deleted chat message could not be read back.');
                    bx_audit('DELETE', 'phase_builder_todo_chat_messages', $messageKey, ['todo_id' => $scope['todo_id']]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo chat deletion transaction could not commit.');
                } catch (Throwable $error) { $db->RollbackTrans(); throw $error; }
                $respondJson(['ok' => true, 'data' => ['message_key' => $messageKey]]);
            }

            $contextMeta = static function (string $field): string { return trim((string) ($_POST[$field] ?? '')); };
            if ($action === 'prepare_phase_todo_execution') {
                $roadmapRow = $db->GetRow('SELECT roadmap_json, stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$scope['draft_key']]);
                if (!is_array($roadmapRow)) $fail('The saved Execution Roadmap could not be read.');
                $roadmap = json_decode((string) ($roadmapRow['roadmap_json'] ?? '{}'), true);
                $stages = json_decode((string) ($roadmapRow['stages_json'] ?? '{}'), true);
                if (!is_array($roadmap) || !is_array($stages)) $fail('The saved Execution Roadmap is invalid.');
                $todoFound = false;
                $visit = static function ($node) use (&$visit, &$todoFound, $scope): void {
                    if (!is_array($node) || $todoFound) return;
                    if ((string) ($node['todoId'] ?? $node['todo_id'] ?? '') === $scope['todo_id']) {
                        $todoFound = true;
                        return;
                    }
                    foreach ($node as $child) $visit($child);
                };
                $visit($roadmap);
                $visit($stages);
                if (!$todoFound) $fail('The selected todo is no longer present in the saved Execution Roadmap.');

                $rows = $db->GetAll('SELECT message_key, sender, message_text, created_at FROM phase_builder_todo_chat_messages WHERE draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND message_status = ? ORDER BY created_at ASC, x_id ASC', [$scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'ACTIVE']);
                if (!is_array($rows)) $rows = [];
                foreach ($rows as &$row) $row['attachments'] = $db->GetAll('SELECT attachment_key, original_name, mime_type, byte_size, storage_path, sha256 FROM phase_builder_todo_chat_attachments WHERE message_key = ? AND attachment_status = ?', [$row['message_key'], 'ACTIVE']);
                unset($row);
                $contextId = 'todo-execution-' . bx_uuid();
                $executionKey = bx_uuid();
                $context = [
                    'context_type' => 'builderx.todo_execution.v1',
                    'execution_key' => $executionKey,
                    'draft_key' => $scope['draft_key'],
                    'workspace_root' => $projectRoot,
                    'phase' => ['id' => $scope['phase_id'], 'title' => $contextMeta('phase_title'), 'description' => $contextMeta('phase_description')],
                    'task' => ['id' => $scope['task_id'], 'title' => $contextMeta('task_title'), 'description' => $contextMeta('task_description')],
                    'subtask' => ['id' => $scope['subtask_id'], 'title' => $contextMeta('subtask_title'), 'description' => $contextMeta('subtask_description')],
                    'todo' => ['id' => $scope['todo_id'], 'title' => $contextMeta('todo_title'), 'description' => $contextMeta('todo_description'), 'type' => $contextMeta('todo_type'), 'track' => $contextMeta('task_track')],
                    'chat_messages' => $rows,
                    'execution_contract' => [
                        'objective' => 'Complete the selected todo in the real BuilderX product workspace and verify the resulting behavior.',
                        'allowed_product_surfaces' => ['User Portal', 'Administrator Portal', 'Kotlin Android stockroom surface', 'product MySQL schema and data paths'],
                        'control_plane_excluded' => [$projectMountPath . '/phases/', 'BuilderX Phase Builder control-plane UI and workflows'],
                        'rules' => [
                            'inspect the existing project before editing',
                            'complete the read-only inspection and plan before implementation',
                            'BuilderX creates and hash-verifies a private project-local source checkpoint before the implementation stage is dispatched',
                            'the source checkpoint excludes runtime state, dependencies, generated output, local configuration, and secret material',
                            'the source checkpoint does not provide database rollback protection; this blocks only actual database schema or data writes, not source-only or Android-only work',
                            'preserve all upstream phase, task, sub-task, and todo identifiers and meaning',
                            'make real source or Android changes required by the selected todo; make database changes only when a verified database rollback path is available',
                            'use parameterized SQL, authorization, transactions, and read-back verification for database changes',
                            'run focused tests, lint, or build checks appropriate to the changed surface',
                            'never claim a file, database change, Android change, or test passed unless it was actually verified',
                            'stop and report a blocker when required context, credentials, dependencies, dirty hunks that cannot be preserved or conflict with required edit hunks, database rollback for required database writes, or scope are unavailable',
                        ],
                    ],
                    'instruction' => 'Execute the selected todo in the real project workspace only after BuilderX supplies the verified server source checkpoint. Reference that exact checkpoint in the execution report. Return blocked rather than claim completed database changes because no server-verified database recovery checkpoint exists; source-only and Android-only work may proceed when no database write is required. Do not modify the BuilderX Phase Manager control plane.',
                ];
                $file = $writePhaseCoordinatorContext($contextId, $context);
                $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($contextJson)) $fail('The execution context could not be encoded.');
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo execution transaction could not start.');
                try {
                    $saved = $db->Execute('INSERT INTO phase_builder_todo_execution_logs (execution_key, draft_key, phase_id, task_id, subtask_id, todo_id, context_json, status, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], $contextJson, 'RUNNING', $userKey, $userKey]);
                    if ($saved === false) throw new RuntimeException('The todo execution log could not be created.');
                    $readBack = $db->GetRow('SELECT execution_key, status, rollback_status, context_json FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND todo_id = ? LIMIT 1', [$executionKey, $scope['draft_key'], $scope['todo_id']]);
                    if (!is_array($readBack) || (string) ($readBack['execution_key'] ?? '') !== $executionKey || (string) ($readBack['status'] ?? '') !== 'RUNNING' || (string) ($readBack['context_json'] ?? '') !== $contextJson) throw new RuntimeException('The todo execution log could not be read back.');
                    bx_audit('CREATE', 'phase_builder_todo_execution_logs', $executionKey, ['todo_id' => $scope['todo_id'], 'status' => 'RUNNING']);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo execution transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['execution_key' => $executionKey, 'context_id' => $contextId, ...$file, 'context' => $context]]);
            }
            if ($action === 'save_phase_todo_execution_log') {
                $executionKey = trim((string) ($_POST['execution_key'] ?? ''));
                $runKey = trim((string) ($_POST['run_key'] ?? ''));
                $resultJson = trim((string) ($_POST['result_json'] ?? ''));
                $status = strtoupper(trim((string) ($_POST['status'] ?? '')));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $executionKey) || $resultJson === '' || strlen($resultJson) > 2000000 || !in_array($status, ['COMPLETED', 'BLOCKED', 'FAILED'], true)) $fail('The todo execution report is invalid.');
                $decodedResult = json_decode($resultJson, true);
                if (!is_array($decodedResult) || array_is_list($decodedResult)) $fail('The todo execution report must be valid JSON.');
                if ($runKey !== '') {
                    $verifiedImplementation = $verifiedRunStageResult($runKey, 'todo_execution', 'implementation', $executionKey);
                    $verifiedRunStageResult($runKey, 'todo_execution', 'verification', $executionKey);
                    $verifiedRunStageResult($runKey, 'todo_execution', 'evidence', $executionKey);
                    if ($verifiedImplementation !== $decodedResult) throw new RuntimeException('The todo execution report does not match its verified Coding Engine checkpoint.');
                    $serverSourceCheckpoint = $verifiedServerSourceCheckpointForRun($runKey, 'todo_execution', $executionKey);
                    \BuilderX\AI\PhaseAiWorkflowContract::validatePersistedExecutionEvidence($decodedResult, $serverSourceCheckpoint);
                    $verifiedRun = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->read($runKey, $projectIdentity);
                    $expectedStatus = strtoupper(\BuilderX\AI\PhaseAiWorkflowContract::codingOverallStatus($verifiedRun));
                    if ($status !== $expectedStatus) throw new RuntimeException('The todo execution status does not match its verified Coding Engine checkpoints.');
                } elseif ($status !== 'FAILED' || ($decodedResult['status'] ?? '') !== 'failed' || count($decodedResult['changedFiles'] ?? []) !== 0) {
                    throw new RuntimeException('A non-terminal transport failure requires a verified Coding Engine run.');
                } else {
                    \BuilderX\AI\PhaseAiWorkflowContract::validatePersistedExecutionEvidence($decodedResult);
                }
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo execution report transaction could not start.');
                try {
                    $existing = $db->GetRow('SELECT execution_key, status FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? FOR UPDATE', [$executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    if (!is_array($existing)) throw new RuntimeException('The todo execution log was not found.');
                    $saved = $db->Execute('UPDATE phase_builder_todo_execution_logs SET result_json = ?, status = ?, completed_at = CURRENT_TIMESTAMP, updated_by_user_key = ? WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ?', [$resultJson, $status, $userKey, $executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    if ($saved === false) throw new RuntimeException('The todo execution report could not be saved.');
                    $readBack = $db->GetRow('SELECT execution_key, status, result_json, completed_at FROM phase_builder_todo_execution_logs WHERE execution_key = ? LIMIT 1', [$executionKey]);
                    if (!is_array($readBack) || (string) ($readBack['execution_key'] ?? '') !== $executionKey || (string) ($readBack['status'] ?? '') !== $status || (string) ($readBack['result_json'] ?? '') !== $resultJson) throw new RuntimeException('The todo execution report could not be read back.');
                    bx_audit('UPDATE', 'phase_builder_todo_execution_logs', $executionKey, ['todo_id' => $scope['todo_id'], 'status' => $status]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo execution report transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['execution_key' => $executionKey, 'status' => $status, 'completed_at' => (string) ($readBack['completed_at'] ?? '')]]);
            }
            if ($action === 'prepare_phase_todo_rollback') {
                $executionKey = trim((string) ($_POST['execution_key'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $executionKey)) $fail('The todo execution log key is invalid.');
                $log = $db->GetRow('SELECT execution_key, context_json, result_json, status, rollback_status FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? LIMIT 1', [$executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                if (!is_array($log) || (string) ($log['result_json'] ?? '') === '') $fail('A completed execution report is required before rollback.');
                if (!in_array((string) ($log['status'] ?? ''), ['COMPLETED', 'BLOCKED', 'FAILED'], true) || (string) ($log['rollback_status'] ?? '') === 'RUNNING') $fail('This execution is not ready for a rollback stage.');
                $context = json_decode((string) ($log['context_json'] ?? '{}'), true);
                $result = json_decode((string) ($log['result_json'] ?? '{}'), true);
                if (!is_array($context) || !is_array($result)) $fail('The saved execution context or report is invalid.');
                $contextId = 'todo-rollback-' . bx_uuid();
                $rollbackContext = [
                    'context_type' => 'builderx.todo_execution_rollback.v1',
                    'execution_key' => $executionKey,
                    'original_execution_context' => $context,
                    'original_execution_report' => $result,
                    'rollback_contract' => [
                        'objective' => 'Rollback only the changes attributable to this selected todo execution stage.',
                        'rules' => ['inspect current state before reverting', 'BuilderX creates and hash-verifies a shared cross-process source checkpoint of the current pre-rollback state before the first reversal write', 'the source checkpoint does not provide database rollback protection', 'do not remove unrelated user changes', 'use the saved execution report and actual file history as evidence', 'verify the rollback with focused tests and read-back checks', 'return blocked when safe attribution or reversal is unavailable'],
                    ],
                    'instruction' => 'Rollback the selected todo execution stage safely after BuilderX supplies the verified server source checkpoint. Reference that exact checkpoint and do not claim completed database reversal because no server-verified database recovery checkpoint exists. Return only JSON with keys status, summary, recoveryCheckpoints, changedFiles, databaseChanges, androidChanges, tests, blockers, and nextSteps.',
                ];
                $file = $writePhaseCoordinatorContext($contextId, $rollbackContext);
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo rollback transaction could not start.');
                try {
                    $saved = $db->Execute('UPDATE phase_builder_todo_execution_logs SET rollback_status = ?, updated_by_user_key = ? WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ?', ['RUNNING', $userKey, $executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    if ($saved === false) throw new RuntimeException('The rollback stage could not be started.');
                    $readBack = $db->GetRow('SELECT execution_key, rollback_status FROM phase_builder_todo_execution_logs WHERE execution_key = ? LIMIT 1', [$executionKey]);
                    if (!is_array($readBack) || (string) ($readBack['execution_key'] ?? '') !== $executionKey || (string) ($readBack['rollback_status'] ?? '') !== 'RUNNING') throw new RuntimeException('The rollback stage could not be read back.');
                    bx_audit('UPDATE', 'phase_builder_todo_execution_logs', $executionKey, ['todo_id' => $scope['todo_id'], 'rollback_status' => 'RUNNING']);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo rollback transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['execution_key' => $executionKey, 'context_id' => $contextId, ...$file]]);
            }
            if ($action === 'save_phase_todo_rollback') {
                $executionKey = trim((string) ($_POST['execution_key'] ?? ''));
                $runKey = trim((string) ($_POST['run_key'] ?? ''));
                $resultJson = trim((string) ($_POST['result_json'] ?? ''));
                $rollbackStatus = strtoupper(trim((string) ($_POST['rollback_status'] ?? '')));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $executionKey) || $resultJson === '' || strlen($resultJson) > 2000000 || !in_array($rollbackStatus, ['COMPLETED', 'BLOCKED', 'FAILED'], true)) $fail('The rollback report is invalid.');
                $decodedResult = json_decode($resultJson, true);
                if (!is_array($decodedResult) || array_is_list($decodedResult)) $fail('The rollback report must be valid JSON.');
                if ($runKey !== '') {
                    $verifiedImplementation = $verifiedRunStageResult($runKey, 'todo_rollback', 'implementation', $executionKey);
                    $verifiedRunStageResult($runKey, 'todo_rollback', 'verification', $executionKey);
                    $verifiedRunStageResult($runKey, 'todo_rollback', 'evidence', $executionKey);
                    if ($verifiedImplementation !== $decodedResult) throw new RuntimeException('The rollback report does not match its verified Coding Engine checkpoint.');
                    $serverSourceCheckpoint = $verifiedServerSourceCheckpointForRun($runKey, 'todo_rollback', $executionKey);
                    \BuilderX\AI\PhaseAiWorkflowContract::validatePersistedExecutionEvidence($decodedResult, $serverSourceCheckpoint);
                    $verifiedRun = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->read($runKey, $projectIdentity);
                    $expectedRollbackStatus = strtoupper(\BuilderX\AI\PhaseAiWorkflowContract::codingOverallStatus($verifiedRun));
                    if ($rollbackStatus !== $expectedRollbackStatus) throw new RuntimeException('The todo rollback status does not match its verified Coding Engine checkpoints.');
                } elseif ($rollbackStatus !== 'FAILED' || ($decodedResult['status'] ?? '') !== 'failed' || count($decodedResult['changedFiles'] ?? []) !== 0) {
                    throw new RuntimeException('A non-terminal rollback failure requires a verified Coding Engine run.');
                } else {
                    \BuilderX\AI\PhaseAiWorkflowContract::validatePersistedExecutionEvidence($decodedResult);
                }
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo rollback report transaction could not start.');
                try {
                    $existing = $db->GetRow('SELECT execution_key FROM phase_builder_todo_execution_logs WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? FOR UPDATE', [$executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    if (!is_array($existing)) throw new RuntimeException('The todo execution log was not found.');
                    $executionStatus = $rollbackStatus === 'COMPLETED' ? 'ROLLED_BACK' : null;
                    if ($executionStatus !== null) {
                        $saved = $db->Execute('UPDATE phase_builder_todo_execution_logs SET rollback_result_json = ?, rollback_status = ?, status = ?, rolled_back_at = CURRENT_TIMESTAMP, updated_by_user_key = ? WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ?', [$resultJson, $rollbackStatus, $executionStatus, $userKey, $executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    } else {
                        $saved = $db->Execute('UPDATE phase_builder_todo_execution_logs SET rollback_result_json = ?, rollback_status = ?, updated_by_user_key = ? WHERE execution_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ?', [$resultJson, $rollbackStatus, $userKey, $executionKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    }
                    if ($saved === false) throw new RuntimeException('The rollback report could not be saved.');
                    $readBack = $db->GetRow('SELECT execution_key, status, rollback_status, rollback_result_json, rolled_back_at FROM phase_builder_todo_execution_logs WHERE execution_key = ? LIMIT 1', [$executionKey]);
                    if (!is_array($readBack) || (string) ($readBack['execution_key'] ?? '') !== $executionKey || (string) ($readBack['rollback_status'] ?? '') !== $rollbackStatus || (string) ($readBack['rollback_result_json'] ?? '') !== $resultJson) throw new RuntimeException('The rollback report could not be read back.');
                    bx_audit('UPDATE', 'phase_builder_todo_execution_logs', $executionKey, ['todo_id' => $scope['todo_id'], 'rollback_status' => $rollbackStatus]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo rollback report transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['execution_key' => $executionKey, 'status' => (string) ($readBack['status'] ?? ''), 'rollback_status' => $rollbackStatus, 'rolled_back_at' => (string) ($readBack['rolled_back_at'] ?? '')]]);
            }
            if ($action === 'consolidate_phase_todo_chat') {
                $rows = $db->GetAll('SELECT message_key, sender, message_text, created_at FROM phase_builder_todo_chat_messages WHERE draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND message_status = ? ORDER BY created_at ASC, x_id ASC', [$scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'ACTIVE']);
                if (!is_array($rows) || count($rows) === 0) $fail('Add at least one saved chat message before consolidating.');
                foreach ($rows as &$row) $row['attachments'] = $db->GetAll('SELECT attachment_key, original_name, mime_type, byte_size, storage_path, sha256 FROM phase_builder_todo_chat_attachments WHERE message_key = ? AND attachment_status = ?', [$row['message_key'], 'ACTIVE']);
                unset($row);
                $contextId = 'todo-chat-' . bx_uuid();
                $consolidationKey = bx_uuid();
                $context = ['context_type' => 'builderx.todo_chat_consolidation.v1', 'consolidation_key' => $consolidationKey, 'draft_key' => $scope['draft_key'], 'phase' => ['id' => $scope['phase_id'], 'title' => $contextMeta('phase_title'), 'description' => $contextMeta('phase_description')], 'task' => ['id' => $scope['task_id'], 'title' => $contextMeta('task_title'), 'description' => $contextMeta('task_description')], 'subtask' => ['id' => $scope['subtask_id'], 'title' => $contextMeta('subtask_title'), 'description' => $contextMeta('subtask_description')], 'todo' => ['id' => $scope['todo_id'], 'title' => $contextMeta('todo_title'), 'description' => $contextMeta('todo_description')], 'chat_messages' => $rows, 'instruction' => 'Analyze the selected todo, conversation, and image attachments. Return JSON with summary, suggestion, suggestedTodoTitle, suggestedTodoDescription, risks, and confidence. Do not update data directly.'];
                $file = $writePhaseCoordinatorContext($contextId, $context);
                $contextJson = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($contextJson)) $fail('The todo consolidation context could not be encoded.');
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo consolidation checkpoint transaction could not start.');
                try {
                    $saved = $db->Execute('INSERT INTO phase_builder_todo_chat_consolidations (consolidation_key, draft_key, phase_id, task_id, subtask_id, todo_id, context_json, created_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$consolidationKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], $contextJson, $userKey]);
                    if ($saved === false) throw new RuntimeException('The todo consolidation checkpoint could not be created.');
                    $readBack = $db->GetRow('SELECT consolidation_key, context_json, approval_status FROM phase_builder_todo_chat_consolidations WHERE consolidation_key = ? AND draft_key = ? AND todo_id = ? LIMIT 1', [$consolidationKey, $scope['draft_key'], $scope['todo_id']]);
                    if (!is_array($readBack) || (string) ($readBack['context_json'] ?? '') !== $contextJson || (string) ($readBack['approval_status'] ?? '') !== 'PENDING') throw new RuntimeException('The todo consolidation checkpoint could not be read back.');
                    bx_audit('CREATE', 'phase_builder_todo_chat_consolidations', $consolidationKey, ['draft_key' => $scope['draft_key'], 'todo_id' => $scope['todo_id']]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo consolidation checkpoint transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['consolidation_key' => $consolidationKey, 'context_id' => $contextId, ...$file, 'context' => $context]]);
            }

            if ($action === 'save_phase_todo_chat_ai_result') {
                $consolidationKey = trim((string) ($_POST['consolidation_key'] ?? ''));
                $runKey = trim((string) ($_POST['run_key'] ?? ''));
                $result = trim((string) ($_POST['ai_result'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $consolidationKey) || $result === '' || strlen($result) > 1000000) $fail('The AI result is invalid or empty.');
                $decodedResult = json_decode($result, true);
                if (!is_array($decodedResult) || array_is_list($decodedResult)) $fail('The AI consolidation result must be valid JSON.');
                $verifiedAnalysis = $verifiedRunStageResult($runKey, 'todo_consolidation', 'analysis', $consolidationKey);
                if ($verifiedAnalysis !== $decodedResult) throw new RuntimeException('The todo consolidation result does not match its verified Planning Engine checkpoint.');
                if ($db->BeginTrans() === false) throw new RuntimeException('The AI consolidation transaction could not start.');
                try {
                    $existing = $db->GetRow('SELECT consolidation_key FROM phase_builder_todo_chat_consolidations WHERE consolidation_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? FOR UPDATE', [$consolidationKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id']]);
                    if (!is_array($existing)) throw new RuntimeException('The consolidation record was not found.');
                    $saved = $db->Execute('UPDATE phase_builder_todo_chat_consolidations SET ai_result_json = ?, approval_status = ? WHERE consolidation_key = ?', [$result, 'PENDING', $consolidationKey]);
                    if ($saved === false) throw new RuntimeException('The AI consolidation result could not be saved.');
                    $readBack = $db->GetRow('SELECT ai_result_json, approval_status FROM phase_builder_todo_chat_consolidations WHERE consolidation_key = ? LIMIT 1', [$consolidationKey]);
                    if (!is_array($readBack) || (string) ($readBack['ai_result_json'] ?? '') !== $result || (string) ($readBack['approval_status'] ?? '') !== 'PENDING') throw new RuntimeException('The AI consolidation result could not be read back.');
                    bx_audit('UPDATE', 'phase_builder_todo_chat_consolidations', $consolidationKey, ['approval_status' => 'PENDING']);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The AI consolidation transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['consolidation_key' => $consolidationKey]]);
            }

            if ($action === 'approve_phase_todo_chat') {
                $consolidationKey = trim((string) ($_POST['consolidation_key'] ?? ''));
                if ($db->BeginTrans() === false) throw new RuntimeException('The todo approval transaction could not start.');
                try {
                    $pending = $db->GetRow('SELECT ai_result_json FROM phase_builder_todo_chat_consolidations WHERE consolidation_key = ? AND draft_key = ? AND phase_id = ? AND task_id = ? AND subtask_id = ? AND todo_id = ? AND approval_status = ? FOR UPDATE', [$consolidationKey, $scope['draft_key'], $scope['phase_id'], $scope['task_id'], $scope['subtask_id'], $scope['todo_id'], 'PENDING']);
                    $result = is_array($pending) ? json_decode((string) ($pending['ai_result_json'] ?? ''), true) : null;
                    if (!is_array($result) || array_is_list($result)) throw new RuntimeException('The pending AI result was not found.');
                    $suggestedTitle = trim((string) ($result['suggestedTodoTitle'] ?? ''));
                    $suggestedDescription = trim((string) ($result['suggestedTodoDescription'] ?? ''));
                    if ($suggestedTitle === '') throw new RuntimeException('The AI result did not include a suggested todo title.');
                    $updatedTodo = $updateRoadmapTodo($scope, $suggestedTitle, $suggestedDescription);
                    if ($db->Execute('UPDATE phase_builder_todo_chat_consolidations SET approval_status = ?, approved_by_user_key = ?, approved_at = CURRENT_TIMESTAMP WHERE consolidation_key = ?', ['APPROVED', $userKey, $consolidationKey]) === false) {
                        throw new RuntimeException('The todo approval could not be saved.');
                    }
                    $approvalReadBack = $db->GetRow('SELECT approval_status, approved_by_user_key, approved_at FROM phase_builder_todo_chat_consolidations WHERE consolidation_key = ? LIMIT 1', [$consolidationKey]);
                    if (!is_array($approvalReadBack) || (string) ($approvalReadBack['approval_status'] ?? '') !== 'APPROVED' || (string) ($approvalReadBack['approved_by_user_key'] ?? '') !== (string) $userKey || trim((string) ($approvalReadBack['approved_at'] ?? '')) === '') {
                        throw new RuntimeException('The todo approval could not be read back.');
                    }
                    bx_audit('UPDATE', 'phase_builder_todo_chat_consolidations', $consolidationKey, ['approval_status' => 'APPROVED', 'todo_id' => $scope['todo_id']]);
                    if ($db->CommitTrans() === false) throw new RuntimeException('The todo approval transaction could not commit.');
                } catch (Throwable $error) {
                    $db->RollbackTrans();
                    throw $error;
                }
                $respondJson(['ok' => true, 'data' => ['todo' => $updatedTodo]]);
            }
        }

        if ($action === 'prepare_coordinator_test_context') {
            $contextId = 'phase3-single-chat-specialist-test-001';
            $context = [
                'context_id' => $contextId,
                'task_type' => 'single_chat_multi_specialist_orchestration',
                'scope_level' => 'project',
                'project_name' => 'Apartment Management System',
                'target_product_surfaces' => [
                    'user_portal' => 'http://127.0.0.1' . $projectMountPath . '/',
                    'administrator_portal' => 'http://127.0.0.1' . $projectMountPath . '/administrator/',
                ],
                'builderx_phase_builder' => 'control_plane_only',
                'platform_mutations_allowed' => false,
                'objective' => 'Use one visible Codex Chat request to coordinate relevant read-only specialist analyses for a project-level planning review, then reconcile their findings in the same response.',
                'specialist_test_cases' => [
                    [
                        'specialist' => 'Requirements',
                        'objective' => 'Review the project scope, assumptions, and acceptance criteria for the target product portals.',
                        'read_only' => true,
                        'independent' => true,
                    ],
                    [
                        'specialist' => 'Database',
                        'objective' => 'Review persistence, schema, transaction, data-boundary, and read-back implications without executing SQL or changing data.',
                        'read_only' => true,
                        'independent' => true,
                    ],
                    [
                        'specialist' => 'UI/UX',
                        'objective' => 'Review user and administrator portal flows, accessibility, and responsive concerns without editing files.',
                        'read_only' => true,
                        'independent' => true,
                    ],
                ],
                'constraints' => [
                    'read_only',
                    'no_file_edits',
                    'no_sql',
                    'no_database_changes',
                    'do_not_modify_builderx_platform',
                    'do_not_modify_phase_builder_control_plane',
                    'report_platform_or_bridge_blockers_without_editing',
                    'do_not_use_codex_cli_pollers_workers_or_another_bridge',
                ],
                'failure_behavior' => 'If this context file cannot be read completely, stop immediately and return only TASK_CONTEXT_UNAVAILABLE. Do not infer missing instructions or continue.',
                'parallelism' => 'Specialist objectives may be logically independent, but all specialist analyses run inside this one visible Codex Chat request. Never claim independent task dispatch or true parallel execution.',
                'reporting_rules' => [
                    'Return exactly one JSON object with no markdown fences.',
                    'Report selected and skipped specialists, with a reason for every decision.',
                    'For every specialist report, separate observed evidence from inference.',
                    'Use status simulated for bounded read-only specialist findings produced within this single chat request.',
                    'State that the Coordinator reconciled the specialist findings in one chat turn.',
                    'Include findings, risks, next_action, and execution_mode for every specialist result.',
                ],
                'response_schema' => [
                    'scope_level' => 'project',
                    'execution_mode' => 'single_chat_multi_specialist_orchestration',
                    'coordinator_decision' => [
                        'selected_specialists' => [],
                        'reason' => '',
                    ],
                    'specialist_tasks' => [[
                        'specialist' => '',
                        'objective' => '',
                        'read_only' => true,
                        'independent' => true,
                    ]],
                    'specialist_results' => [[
                        'specialist' => '',
                        'status' => 'simulated',
                        'execution_mode' => 'single_chat_multi_specialist_orchestration',
                        'evidence' => [],
                        'findings' => [],
                        'risks' => [],
                        'next_action' => '',
                    ]],
                    'reconciliation' => [
                        'summary' => '',
                        'conflicts' => [],
                        'parallel_candidates' => [],
                    ],
                    'final_summary' => '',
                ],
            ];

            $materialized = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, ...$materialized]);
        }

        if ($action === 'prepare_phase2_narrative_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $sourceSnapshotJson = (string) ($_POST['source_snapshot'] ?? '{}');
            if ($draftKey === '' || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('The current BuilderX draft was not found.');
            }
            $sourceSnapshot = json_decode($sourceSnapshotJson, true);
            $allowedFields = [
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
            if (!is_array($sourceSnapshot)) {
                $fail('The current Tab 1 context is not valid JSON.');
            }
            $normalizedSnapshot = [];
            foreach ($allowedFields as $field) {
                if (!array_key_exists($field, $sourceSnapshot) || !is_string($sourceSnapshot[$field])) {
                    $fail('The current Tab 1 context is incomplete.');
                }
                if (strlen($sourceSnapshot[$field]) > 1000000) {
                $fail('A Tab 1 section is too large for Phase Builder Narrative & Cleanup.');
                }
                $normalizedSnapshot[$field] = $sourceSnapshot[$field];
            }
            $contextId = 'phase2-narrative-' . substr(hash('sha256', $draftKey), 0, 24);
            $context = [
                'context_id' => $contextId,
                'phase_key' => (string) ($_POST['phase_key'] ?? ''),
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_to_grammar_to_database',
                'project_scope' => 'Only product surfaces and integrations explicitly confirmed in the saved requirements; BuilderX Phase Builder is the control plane and is excluded.',
                'objective' => 'Correct spelling and grammar only, then validate and persist the corrected Tab 1 narrative.',
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('narrative_cleanup'),
                'target_product_surfaces' => [
                    'user_portal' => 'http://127.0.0.1' . $projectMountPath . '/',
                    'administrator_portal' => 'http://127.0.0.1' . $projectMountPath . '/administrator/',
                ],
                'rules' => [
                    'preserve_meaning',
                    'preserve_requirements_urls_and_technical_details',
                    'read_only_for_coordinator_and_grammar_specialist',
                    'database_write_only_after_database_specialist_approval',
                    'no_builderx_platform_edits',
                    'no_child_dispatch_from_the_codex_session',
                    'if_context_is_missing_or_incomplete_return_only_PHASE2_CONTEXT_UNAVAILABLE',
                ],
                'source_snapshot' => $normalizedSnapshot,
            ];
            if ($context['phase_key'] === '') {
                $fail('The current BuilderX phase was not found.');
            }
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, ...$result]);
        }

        if ($action === 'prepare_phase2_database_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $sourceSnapshotJson = (string) ($_POST['source_snapshot'] ?? '{}');
            $grammarReplyJson = (string) ($_POST['grammar_reply'] ?? '');
            if ($draftKey === '' || !preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey) || $grammarReplyJson === '') {
                $fail('Phase Builder Narrative & Cleanup requires a completed grammar response.');
            }
            $sourceSnapshot = json_decode($sourceSnapshotJson, true);
            $grammarReply = json_decode($grammarReplyJson, true);
            if (!is_array($sourceSnapshot) || !is_array($grammarReply) || !is_array($grammarReply['corrected_sections'] ?? null)) {
                $fail('The grammar response or source context is not valid JSON.');
            }
            $contextId = 'phase2-database-' . substr(hash('sha256', $draftKey), 0, 24);
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'database_validation_after_grammar',
                'instructions' => [
                    'read_the_complete_context_before_action',
                    'validate_that_corrected_sections_are_complete',
                    'treat_all_nine_required_keys_as_complete_even_when_an_unchanged_source_value_is_an_empty_string',
                    'approve_persistence_only_if_the_change_is_grammar_and_spelling_only',
                    'do_not_execute_sql_or_modify_files',
                    'return_only_the_semantic_approval_decision_and_reason',
                    'the_server_derives_complete_meaning_preserved_and_write_allowed_from_persisted_checkpoints',
                    'return_exactly_one_json_object_with_no_markdown_fences',
                ],
                'source_snapshot' => $sourceSnapshot,
                'grammar_response' => $grammarReply,
                'required_response' => [
                    'role' => 'database_specialist',
                    'status' => 'approved_or_rejected',
                    'database_specialist_approved' => false,
                    'draft_key' => $draftKey,
                    'reason' => '',
                ],
                'server_owned_validation' => [
                    'complete' => 'derived from the persisted nine-section grammar checkpoint',
                    'meaning_preserved' => 'derived by deterministic source-to-grammar comparison',
                    'write_allowed' => 'derived only after approval and all deterministic checks pass',
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, ...$result]);
        }

        if ($action === 'prepare_requirements_analysis_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('A saved BuilderX draft is required before starting Requirements Analysis.');
            }
            $draft = $db->GetRow(
                'SELECT product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                [$draftKey]
            );
            if (!is_array($draft)) {
                $fail('Save Narrative & Cleanup before running Requirements Analysis.');
            }
            $sourceFields = [
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
            $sourceSnapshot = [];
            foreach ($sourceFields as $field) {
                if (!array_key_exists($field, $draft) || !is_string($draft[$field])) {
                    $fail('The saved Narrative & Cleanup source is incomplete.');
                }
                $sourceSnapshot[$field] = (string) $draft[$field];
            }
            if (trim(implode('', $sourceSnapshot)) === '') {
                $fail('Save Narrative & Cleanup before running Requirements Analysis.');
            }
            $sourceJson = json_encode($sourceSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($sourceJson)) {
                $fail('The saved Narrative & Cleanup source could not be encoded.');
            }
            $sourceHash = hash('sha256', $sourceJson);
            $categoryKeys = [
                'functionalRequirements',
                'nonFunctionalRequirements',
                'architectureConstraints',
                'securityAndPrivacyRequirements',
                'installationAndDeploymentRequirements',
                'configurationAndEnvironmentRequirements',
                'dataMigrationAndBackupRequirements',
                'performanceAndScalabilityRequirements',
                'availabilityAndRecoveryRequirements',
                'monitoringAndAuditRequirements',
                'accessibilityAndCompatibilityRequirements',
                'testingAndQualityRequirements',
                'maintenanceAndSupportRequirements',
                'releaseAndRollbackRequirements',
            ];
            $topLevelKeys = [
                'schemaVersion',
                'contractType',
                'source',
                'projectAnalysis',
                'actors',
                'entities',
                'portals',
                ...$categoryKeys,
                'missingDetailsOrRisks',
                'assumptions',
                'openQuestions',
                'reviewChecklist',
                'traceability',
                'rag',
                'orchestration',
            ];
            $registeredSpecialists = (new \BuilderX\AI\AiSpecialistRegistry())->listAll(100);
            $approvedSpecialists = array_values(array_map(
                static fn (array $specialist): array => [
                    'specialist_key' => $specialist['specialist_key'],
                    'name' => $specialist['name'],
                    'purpose' => $specialist['purpose'],
                    'stages' => $specialist['stages'],
                    'skills' => $specialist['skills'],
                    'write_scope' => $specialist['write_scope'],
                ],
                array_filter($registeredSpecialists, static fn (array $specialist): bool => $specialist['status'] === 'active' && $specialist['review_status'] === 'approved')
            ));
            $contextId = 'requirements-analysis-' . substr(hash('sha256', $draftKey . ':' . $sourceHash), 0, 24);
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_requirements_analysis_from_saved_narrative',
                'project_scope' => 'Only capabilities, actors, surfaces, integrations, and constraints explicitly confirmed in the saved narrative; BuilderX Phase Builder is excluded.',
                'objective' => 'Convert the saved narrative into a concise minimum set of confirmed, testable requirements that can guide Phase Manager.',
                'target_product_surfaces' => [
                    'user_portal' => 'http://127.0.0.1' . $projectMountPath . '/',
                    'administrator_portal' => 'http://127.0.0.1' . $projectMountPath . '/administrator/',
                ],
                'source_narrative_hash' => $sourceHash,
                'source_snapshot' => $sourceSnapshot,
                'available_specialists' => $approvedSpecialists,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('requirements_analysis'),
                'coordinator_rules' => [
                    'select only specialists present in available_specialists',
                    'record selected specialist keys in orchestration.selectedSpecialists',
                    'include_only_requirements_explicitly_supported_by_the_saved_narrative',
                    'do_not_fill_every_category_when_the_narrative_does_not_require_it',
                    'merge_overlapping_statements_instead_of_creating_duplicate_requirements',
                    'record_only_material_missing_capabilities_as_non_blocking_coding_time_suggestions',
                    'proposals_are_recommendations_only_and_never_activate_or_dispatch_a_specialist_automatically',
                    'this is one visible Codex Chat turn and must not claim true parallel execution',
                ],
                'required_response' => [
                    'top_level_keys' => $topLevelKeys,
                    'schemaVersion' => 'builderx.requirements-analysis.v2',
                    'contractType' => 'builderx.requirements-analysis',
                    'source' => ['draftKey' => $draftKey, 'narrativeHash' => $sourceHash, 'sourceSections' => $sourceFields],
                    'category_keys' => $categoryKeys,
                    'item_shape' => [
                        'requirementId' => 'REQ-001',
                        'category' => 'functionalRequirements',
                        'title' => 'Requirement title',
                        'description' => 'A testable statement of the requirement.',
                        'priority' => 'Must',
                        'status' => 'Proposed',
                        'isSelected' => true,
                        'sourceReferences' => ['Narrative & Cleanup: Product Goal'],
                        'acceptanceCriteria' => ['Observable acceptance condition'],
                        'dependencies' => [],
                        'assumptions' => [],
                        'risks' => [],
                        'verificationMethod' => 'Review and test',
                    ],
                    'orchestration' => [
                        'executionMode' => 'single_chat_coordinator_with_specialist_analysis',
                        'selectedSpecialists' => [],
                        'additionalSpecialistProposals' => [[
                            'specialist_key' => 'new-specialist-key',
                            'name' => 'New Specialist',
                            'purpose' => 'Why this specialist is needed.',
                            'stages' => ['Design'],
                            'skills' => ['specific-skill'],
                            'allowed_tools' => ['read_files', 'search_files', 'read_communication'],
                            'write_scope' => 'none',
                            'rag_scopes' => ['project-rules', 'task-contracts'],
                        ]],
                    ],
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, 'source_narrative_hash' => $sourceHash, 'available_specialist_count' => count($approvedSpecialists), ...$result]);
        }

        if ($action === 'prepare_system_architecture_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('A saved BuilderX draft is required before starting System Architecture.');
            }
            $requirementsRow = $db->GetRow('SELECT analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $requirementsJson = is_array($requirementsRow) ? (string) ($requirementsRow['analysis_json'] ?? '') : '';
            $requirements = $requirementsJson !== '' ? json_decode($requirementsJson, true) : null;
            if (!is_array($requirements) || array_is_list($requirements)) {
                $fail('Run and save Requirements Analysis before starting System Architecture.');
            }
            $requirementsHash = hash('sha256', $requirementsJson);
            $previousReviewRows = $db->GetAll(
                "SELECT r.run_key, COALESCE(NULLIF(s.result_json, ''), NULLIF(j.result_json, '')) AS result_json
                 FROM phase_builder_ai_run r
                 LEFT JOIN phase_builder_ai_run_stage s ON s.run_key = r.run_key AND s.stage_key = 'integration_review'
                 LEFT JOIN phase_builder_ai_job j ON j.run_key = r.run_key AND j.stage_key = 'integration_review'
                 WHERE r.project_identity = ? AND r.created_by_user_key = ? AND r.workflow_key = ? AND r.draft_key = ?
                 ORDER BY r.x_id DESC LIMIT 10",
                [$projectIdentity, (string) ($user['user_key'] ?? ''), 'system_architecture', $draftKey]
            );
            $previousReviewRunKeys = [];
            $previousFindings = [];
            $seenPreviousFindings = [];
            foreach (is_array($previousReviewRows) ? $previousReviewRows : [] as $candidateReviewRow) {
                if (!is_array($candidateReviewRow)) continue;
                $candidateReview = json_decode((string) ($candidateReviewRow['result_json'] ?? ''), true);
                if (
                    !is_array($candidateReview)
                    || ($candidateReview['schemaVersion'] ?? '') !== 'builderx.ai-integration-review.v1'
                    || ($candidateReview['workflowKey'] ?? '') !== 'system_architecture'
                    || ($candidateReview['status'] ?? '') !== 'blocked'
                    || !is_array($candidateReview['findings'] ?? null)
                ) {
                    continue;
                }
                $candidateContributed = false;
                foreach ($candidateReview['findings'] as $candidateFinding) {
                    if (count($previousFindings) >= 12) break;
                    if (!is_array($candidateFinding) || array_is_list($candidateFinding)) continue;
                    $summary = trim((string) ($candidateFinding['summary'] ?? $candidateFinding['detail'] ?? ''));
                    if ($summary === '') continue;
                    $code = strtoupper(trim((string) ($candidateFinding['code'] ?? '')));
                    if (!in_array($code, \BuilderX\AI\PhaseBuilderPlanningPolicy::blockingCodes(), true)) continue;
                    $requiredResolution = trim((string) ($candidateFinding['requiredResolution'] ?? ''));
                    $findingIdentity = $code !== ''
                        ? 'code:' . strtoupper($code)
                        : 'content:' . hash('sha256', $summary . "\n" . $requiredResolution);
                    if (isset($seenPreviousFindings[$findingIdentity])) continue;
                    $seenPreviousFindings[$findingIdentity] = true;
                    $previousFindings[] = [
                        'code' => $code,
                        'summary' => $summary,
                        'requiredResolution' => $requiredResolution,
                    ];
                    $candidateContributed = true;
                }
                $candidateRunKey = trim((string) ($candidateReviewRow['run_key'] ?? ''));
                if ($candidateContributed && $candidateRunKey !== '') $previousReviewRunKeys[] = $candidateRunKey;
                if (count($previousFindings) >= 12) break;
            }
            $refinementHash = $previousFindings === []
                ? 'initial'
                : hash('sha256', json_encode($previousFindings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $registeredSpecialists = (new \BuilderX\AI\AiSpecialistRegistry())->listAll(100);
            $approvedSpecialists = array_values(array_map(
                static fn (array $specialist): array => [
                    'specialist_key' => $specialist['specialist_key'],
                    'name' => $specialist['name'],
                    'purpose' => $specialist['purpose'],
                    'stages' => $specialist['stages'],
                    'skills' => $specialist['skills'],
                ],
                array_filter($registeredSpecialists, static fn (array $specialist): bool => $specialist['status'] === 'active' && $specialist['review_status'] === 'approved')
            ));
            $contextId = 'system-architecture-' . substr(hash('sha256', $draftKey . ':' . $requirementsHash . ':' . $refinementHash), 0, 24);
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_system_architecture_from_saved_requirements',
                'project_scope' => 'Only product surfaces and integrations explicitly confirmed by the saved Requirements Analysis; BuilderX Phase Builder is excluded.',
                'objective' => 'Turn the verified Requirements Analysis into the smallest coherent System Architecture guide needed to start implementation in Phase Manager.',
                'source_requirements_hash' => $requirementsHash,
                'approved_specialists' => $approvedSpecialists,
                'requirements_analysis' => $requirements,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('system_architecture'),
                'previous_integration_review' => $previousFindings === [] ? null : [
                    'source_run_key' => (string) ($previousReviewRunKeys[0] ?? ''),
                    'source_run_keys' => $previousReviewRunKeys,
                    'status' => 'blocked',
                    'findings' => $previousFindings,
                    'instruction' => 'Correct these unresolved baseline blockers only. Preserve accepted decisions and do not expand unrelated sections.',
                ],
                'rules' => [
                    'preserve_confirmed_requirement_meaning_and_essential_traceability',
                    'describe_only_required_product_boundaries_owners_and_primary_data_flows',
                    'use_a_small_implementation_checklist_and_file_manifest_as_guidance_not_as_an_exhaustive_build_specification',
                    'do_not_invent_optional_services_tables_apis_roles_screens_infrastructure_or_production_hardening',
                    'when_previous_integration_review_is_present_correct_only_the_supplied_baseline_blockers',
                    'design_only_no_file_edits_no_sql_and_no_database_changes_in_the_codex_turn',
                    'include_only_explicitly_confirmed_web_mobile_sync_or_tenant_boundaries',
                    'put_material_optional_improvements_in_concise_non_blocking_coding_time_suggestions',
                    'stop_with_ARCHITECTURE_CONTEXT_UNAVAILABLE_if_this_file_cannot_be_read_completely',
                ],
                'required_response' => [
                    'schemaVersion' => 'builderx.system-architecture.v1',
                    'contractType' => 'builderx.system-architecture',
                    'source' => ['draftKey' => $draftKey, 'requirementsHash' => $requirementsHash, 'requirementIds' => ['Only confirmed requirement IDs represented by this guide']],
                    'projectBlueprint' => [
                        'title' => 'Architecture title',
                        'summary' => 'Architecture summary',
                        'architecturePattern' => 'Pattern and rationale',
                        'boundaries' => [['boundaryId' => 'BOUNDARY-001', 'name' => 'User Portal', 'owner' => 'Web application', 'responsibility' => '...', 'routes' => [$projectMountPath . '/'], 'requirementIds' => ['REQ-001']]],
                        'dataFlow' => [['flowId' => 'FLOW-001', 'from' => 'Source', 'to' => 'Destination', 'owner' => 'Owning component', 'purpose' => '...', 'consistency' => 'transactional|eventual', 'idempotency' => 'Required rule for mutating flows', 'requirementIds' => ['REQ-001']]],
                        'integrationBoundaries' => [],
                        'securityBoundaries' => [],
                        'synchronizationResponsibilities' => [],
                    ],
                    'systemInventory' => ['portals' => [], 'pages' => [], 'menus' => [], 'forms' => [], 'databaseTables' => []],
                    'fileManifest' => [],
                    'implementationChecklist' => [],
                    'assumptionsOrRisks' => [],
                    'orchestration' => ['selectedSpecialists' => [], 'additionalSpecialistProposals' => []],
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, 'source_requirements_hash' => $requirementsHash, 'available_specialist_count' => count($approvedSpecialists), 'previous_review_finding_count' => count($previousFindings), ...$result]);
        }

        if ($action === 'prepare_ui_ux_design_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('A saved BuilderX draft is required before starting UI/UX Design.');
            }
            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            $architecture = $architectureJson !== '' ? json_decode($architectureJson, true) : null;
            if (!is_array($architecture) || array_is_list($architecture)) {
                $fail('Run and save System Architecture before starting UI/UX Design.');
            }
            $architectureHash = hash('sha256', $architectureJson);
            $registeredSpecialists = (new \BuilderX\AI\AiSpecialistRegistry())->listAll(100);
            $approvedSpecialists = array_values(array_map(
                static fn (array $specialist): array => [
                    'specialist_key' => $specialist['specialist_key'],
                    'name' => $specialist['name'],
                    'purpose' => $specialist['purpose'],
                    'stages' => $specialist['stages'],
                    'skills' => $specialist['skills'],
                    'write_scope' => $specialist['write_scope'],
                ],
                array_filter($registeredSpecialists, static fn (array $specialist): bool => $specialist['status'] === 'active' && $specialist['review_status'] === 'approved')
            ));
            $contextId = 'ui-ux-design-' . substr(hash('sha256', $draftKey . ':' . $architectureHash), 0, 24);
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_ui_ux_design_from_saved_architecture',
                'project_scope' => 'Only product surfaces explicitly confirmed by the saved architecture; BuilderX Phase Builder is the control plane and is excluded.',
                'objective' => 'Create a concise UI/UX guide for the essential confirmed screens and primary journey; Phase Manager owns detailed design during coding.',
                'source_architecture_hash' => $architectureHash,
                'approved_specialists' => $approvedSpecialists,
                'system_architecture' => $architecture,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('ui_ux_design'),
                'rules' => [
                    'preserve_confirmed_architecture_meaning_and_primary_journey_traceability',
                    'use_existing_react_and_shadcn_ui_patterns',
                    'include_only_essential_screens_actions_and_states_needed_for_the_primary_journey',
                    'include_loading_empty_error_success_or_offline_states_only_when_relevant_to_confirmed_behavior',
                    'include_baseline_accessibility_and_responsive_guidance_without_final_pixel_level_specification',
                    'do_not_invent_optional_dashboards_reports_widgets_or_secondary_flows',
                    'design_only_no_file_edits_no_sql_and_no_database_changes_in_the_codex_turn',
                    'do_not_modify_builderx_phase_builder',
                    'put_material_optional_improvements_in_concise_non_blocking_coding_time_suggestions',
                    'stop_with_UI_UX_CONTEXT_UNAVAILABLE_if_this_file_cannot_be_read_completely',
                ],
                'required_response' => [
                    'schemaVersion' => 'builderx.ui-ux-design.v1',
                    'contractType' => 'builderx.ui-ux-design',
                    'source' => ['draftKey' => $draftKey, 'architectureHash' => $architectureHash],
                    'designBlueprint' => [
                        'title' => 'UI/UX design title',
                        'summary' => 'Design summary',
                        'designPrinciples' => [],
                        'navigationModel' => [],
                    ],
                    'screens' => [[
                        'screenId' => 'UI-001',
                        'name' => 'Screen name',
                        'surface' => 'User Portal',
                        'route' => $projectMountPath . '/',
                        'purpose' => 'Screen purpose',
                        'layout' => [],
                        'renderSpec' => [
                            'shell' => 'dashboard',
                            'header' => ['eyebrow' => '', 'title' => 'Screen title', 'description' => '', 'actions' => []],
                            'sections' => [[
                                'sectionId' => 'SECTION-001',
                                'title' => 'Section title',
                                'description' => '',
                                'layout' => 'stack',
                                'components' => [[
                                    'componentId' => 'COMP-001',
                                    'type' => 'text',
                                    'label' => '',
                                    'text' => 'Generated screen content',
                                    'placeholder' => '',
                                    'value' => '',
                                    'control' => 'text',
                                    'options' => [],
                                    'fields' => [],
                                    'columns' => [],
                                    'rows' => [],
                                    'items' => [],
                                    'actions' => [],
                                    'state' => 'default',
                                ]],
                            ]],
                        ],
                        'primaryActions' => [],
                        'states' => [],
                        'dataSources' => [],
                    ]],
                    'flowChart' => [['stepId' => 'FLOW-001', 'from' => 'Start', 'to' => 'Next', 'label' => 'User action', 'condition' => '']],
                    'responsiveRules' => [],
                    'accessibilityRules' => [],
                    'orchestration' => ['selectedSpecialists' => [], 'additionalSpecialistProposals' => []],
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, 'source_architecture_hash' => $architectureHash, 'available_specialist_count' => count($approvedSpecialists), ...$result]);
        }

        if ($action === 'prepare_execution_roadmap_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('A saved BuilderX draft is required before starting Execution Roadmap.');
            }
            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            $architecture = $architectureJson !== '' ? json_decode($architectureJson, true) : null;
            if (!is_array($architecture) || array_is_list($architecture)) {
                $fail('Run and save System Architecture before starting Execution Roadmap.');
            }
            $architectureHash = hash('sha256', $architectureJson);
            $uiUxRow = $db->GetRow('SELECT ui_ux_json FROM phase_builder_ui_ux_design WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $uiUxJson = is_array($uiUxRow) ? trim((string) ($uiUxRow['ui_ux_json'] ?? '')) : '';
            $uiUxDesign = null;
            $uiUxHash = null;
            if ($uiUxJson !== '') {
                $uiUxDesign = json_decode($uiUxJson, true);
                if (!is_array($uiUxDesign) || array_is_list($uiUxDesign)) {
                    $fail('The saved UI/UX Design is invalid. Regenerate and save UI/UX Design before starting Execution Roadmap.');
                }
                $uiUxHash = hash('sha256', $uiUxJson);
            }
            $registeredSpecialists = (new \BuilderX\AI\AiSpecialistRegistry())->listAll(100);
            $approvedSpecialists = array_values(array_map(
                static fn (array $specialist): array => [
                    'specialist_key' => $specialist['specialist_key'],
                    'name' => $specialist['name'],
                    'purpose' => $specialist['purpose'],
                    'stages' => $specialist['stages'],
                    'skills' => $specialist['skills'],
                    'write_scope' => $specialist['write_scope'],
                ],
                array_filter($registeredSpecialists, static fn (array $specialist): bool => $specialist['status'] === 'active' && $specialist['review_status'] === 'approved')
            ));
            $contextId = 'execution-roadmap-' . substr(hash('sha256', $draftKey . ':' . $architectureHash), 0, 24);
            $moduleCatalog = [];
            $roadmapRow = $db->GetRow('SELECT stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $stages = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['stages_json'] ?? '{}'), true) : [];
            if (is_array($stages) && is_array($stages['modules']['stage']['modules'] ?? null)) {
                $moduleCatalog = $stages['modules']['stage']['modules'];
            }
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_execution_roadmap_from_saved_architecture',
                'project_scope' => 'Only confirmed product surfaces and integrations represented by the saved architecture and optional UI/UX guide; BuilderX Phase Builder is excluded.',
                'objective' => 'Create the smallest practical starter roadmap for Phase Manager, using concise modules and work packages without speculative implementation detail.',
                'source_architecture_hash' => $architectureHash,
                'source_ui_ux_hash' => $uiUxHash,
                'module_catalog' => $moduleCatalog,
                'approved_specialists' => $approvedSpecialists,
                'system_architecture' => $architecture,
                'ui_ux_design' => $uiUxDesign,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('execution_roadmap'),
                'rules' => [
                    'preserve_confirmed_architecture_meaning_and_essential_traceability',
                    'use_the_saved_ui_ux_design_as_the_page_flow_and_screen_handoff_when_available',
                    'show_delivery_track_on_each_task_as_web_android_or_shared',
                    'generate_phases_tasks_subtasks_and_resources_in_separate_sequential_passes',
                    'never_overwrite_or_rename_verified_upstream_ids_when_enhancing_a_stage',
                    'include_only_primary_flow_connections_required_to_start_implementation',
                    'use_the_smallest_coherent_module_and_phase_partition',
                    'use_one_concise_subtask_and_todo_when_that_is_enough_to_express_the_work',
                    'indicators_are_allowlisted_icon_slugs_and_have_no_display_labels',
                    'normalize_common_indicator_aliases_to_the_canonical_icon_slugs_before_validation',
                    'include_forms_tables_apis_and_background_resources_only_when_confirmed_scope_requires_them',
                    'suggested_table_names_and_fields_use_lower_snake_case',
                    'put_material_optional_improvements_in_concise_non_blocking_coding_time_suggestions',
                    'planning_only_no_file_edits_no_sql_and_no_product_database_changes_in_the_codex_turn',
                    'do_not_modify_builderx_phase_builder',
                    'stop_with_ROADMAP_CONTEXT_UNAVAILABLE_if_this_file_cannot_be_read_completely',
                ],
                'required_response' => [
                    'stageContracts' => [
                        'modules' => 'builderx.execution-roadmap.stage.modules.v1',
                        'phases' => 'builderx.execution-roadmap.stage.phases.v1',
                        'tasks' => 'builderx.execution-roadmap.stage.tasks.v1',
                        'subtasks' => 'builderx.execution-roadmap.stage.subtasks.v1',
                        'resources' => 'builderx.execution-roadmap.stage.resources.v1',
                    ],
                    'contractType' => 'builderx.execution-roadmap-stage for AI stages 1 to 4; builderx.execution-roadmap.v3 is assembled and saved after stage 4',
                    'source' => ['draftKey' => $draftKey, 'architectureHash' => $architectureHash],
                    'finalHierarchy' => 'phase -> tasks[] -> subTasks[] -> todos[]',
                    'finalResources' => ['forms', 'tables', 'apis', 'backgroundProcesses', 'reports', 'analytics'],
                    'resourcePatch' => 'Stage 4 returns resourcePatches keyed by phaseId. The Phase Builder merges them into the saved Stage 3 phase -> task -> subTasks -> todos hierarchy before final validation and persistence.',
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson(['ok' => true, 'context_id' => $contextId, 'source_architecture_hash' => $architectureHash, 'available_specialist_count' => count($approvedSpecialists), ...$result]);
        }

        if ($action === 'prepare_execution_roadmap_stage_context') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $stageKey = trim((string) ($_POST['stage_key'] ?? ''));
            $phaseId = trim((string) ($_POST['phase_id'] ?? ''));
            $moduleId = trim((string) ($_POST['module_id'] ?? ''));
            $contextArchitectureHash = strtolower(trim((string) ($_POST['context_architecture_hash'] ?? '')));
            $allowedStageKeys = ['modules', 'phases', 'tasks', 'subtasks', 'resources'];
            $previousStageKeys = ['modules' => '', 'phases' => '', 'tasks' => 'phases', 'subtasks' => 'tasks', 'resources' => 'subtasks'];
            $stageContracts = [
                'modules' => 'builderx.execution-roadmap.stage.modules.v1',
                'phases' => 'builderx.execution-roadmap.stage.phases.v1',
                'tasks' => 'builderx.execution-roadmap.stage.tasks.v1',
                'subtasks' => 'builderx.execution-roadmap.stage.subtasks.v1',
                'resources' => 'builderx.execution-roadmap.stage.resources.v1',
            ];
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey) || !in_array($stageKey, $allowedStageKeys, true) || !preg_match('/^[a-f0-9]{64}$/', $contextArchitectureHash)) {
                $fail('A valid Execution Roadmap stage context is required.');
            }
            if ($phaseId !== '' && ($stageKey !== 'resources' || !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $phaseId))) {
                $fail('A valid resource phase scope is required.');
            }
            if ($moduleId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,128}$/', $moduleId)) {
                $fail('A valid product module scope is required.');
            }

            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            $architecture = $architectureJson !== '' ? json_decode($architectureJson, true) : null;
            if (!is_array($architecture) || array_is_list($architecture)) {
                $fail('Run and save System Architecture before preparing an Execution Roadmap stage.');
            }
            $architectureHash = hash('sha256', $architectureJson);
            if (!hash_equals($architectureHash, $contextArchitectureHash)) {
                $fail('Execution Roadmap source verification failed. System Architecture changed after the context was prepared.');
            }
            $uiUxRow = $db->GetRow('SELECT ui_ux_json FROM phase_builder_ui_ux_design WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $uiUxJson = is_array($uiUxRow) ? trim((string) ($uiUxRow['ui_ux_json'] ?? '')) : '';
            $uiUxDesign = null;
            $uiUxHash = null;
            if ($uiUxJson !== '') {
                $uiUxDesign = json_decode($uiUxJson, true);
                if (!is_array($uiUxDesign) || array_is_list($uiUxDesign)) {
                    $fail('The saved UI/UX Design is invalid. Regenerate and save UI/UX Design before preparing this Execution Roadmap stage.');
                }
                $uiUxHash = hash('sha256', $uiUxJson);
            }

            $previousStageKey = $previousStageKeys[$stageKey];
            $previousStage = null;
            $moduleCatalog = [];
            if ($previousStageKey !== '') {
                $roadmapRow = $db->GetRow('SELECT stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                $stages = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['stages_json'] ?? '{}'), true) : [];
                if (!is_array($stages) || array_is_list($stages) || !is_array($stages[$previousStageKey]['stage'] ?? null)) {
                    $fail('Save the previous Execution Roadmap stage before preparing this stage.');
                }
                $previousStage = $stages[$previousStageKey]['stage'];
                $moduleCatalog = is_array($stages['modules']['stage']['modules'] ?? null) ? $stages['modules']['stage']['modules'] : [];
            } else {
                $roadmapRow = $db->GetRow('SELECT stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                $stages = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['stages_json'] ?? '{}'), true) : [];
                $moduleCatalog = is_array($stages['modules']['stage']['modules'] ?? null) ? $stages['modules']['stage']['modules'] : [];
            }

            $moduleScope = null;
            $dependencyInterfaceSummaries = [];
            if ($moduleId !== '') {
                foreach ($moduleCatalog as $module) {
                    if (is_array($module) && trim((string) ($module['moduleId'] ?? '')) === $moduleId) {
                        $moduleScope = $module;
                        break;
                    }
                }
                if (!is_array($moduleScope)) {
                    $fail('The requested product module was not found in the saved module boundary map.');
                }
                $dependencyIds = is_array($moduleScope['dependsOn'] ?? null) ? $moduleScope['dependsOn'] : [];
                foreach ($moduleCatalog as $dependency) {
                    if (!is_array($dependency) || !in_array((string) ($dependency['moduleId'] ?? ''), $dependencyIds, true)) {
                        continue;
                    }
                    $dependencyInterfaceSummaries[] = [
                        'moduleId' => (string) ($dependency['moduleId'] ?? ''),
                        'moduleTitle' => (string) ($dependency['moduleTitle'] ?? ''),
                        'provides' => is_array($dependency['provides'] ?? null) ? $dependency['provides'] : [],
                        'consumes' => is_array($dependency['consumes'] ?? null) ? $dependency['consumes'] : [],
                    ];
                }
                if (is_array($previousStage)) {
                    $previousStage['phases'] = array_values(array_filter(
                        is_array($previousStage['phases'] ?? null) ? $previousStage['phases'] : [],
                        static fn ($phase): bool => is_array($phase) && trim((string) ($phase['moduleId'] ?? '')) === $moduleId
                    ));
                }
            }

            $phaseIndex = null;
            $phaseCount = is_array($previousStage['phases'] ?? null) ? count($previousStage['phases']) : 0;
            if ($stageKey === 'resources' && $phaseId !== '') {
                $scopedPhase = null;
                foreach (($previousStage['phases'] ?? []) as $index => $candidatePhase) {
                    if (is_array($candidatePhase) && trim((string) ($candidatePhase['phaseId'] ?? '')) === $phaseId) {
                        $scopedPhase = $candidatePhase;
                        $phaseIndex = $index;
                        break;
                    }
                }
                if (!is_array($scopedPhase)) {
                    $fail('The requested resource phase was not found in the saved Stage 3 hierarchy.');
                }
                $previousStage = [
                    'schemaVersion' => $previousStage['schemaVersion'] ?? 'builderx.execution-roadmap.stage.subtasks.v1',
                    'contractType' => $previousStage['contractType'] ?? 'builderx.execution-roadmap-stage',
                    'stage' => $previousStage['stage'] ?? 'subtasks',
                    'source' => $previousStage['source'] ?? ['draftKey' => $draftKey, 'architectureHash' => $architectureHash],
                    'phases' => [$scopedPhase],
                ];
            }

            if ($phaseId !== '' && is_array($previousStage['phases'][0] ?? null) && !is_array($moduleScope)) {
                $selectedModuleId = trim((string) ($previousStage['phases'][0]['moduleId'] ?? ''));
                if ($selectedModuleId !== '') {
                    foreach ($moduleCatalog as $module) {
                        if (!is_array($module) || trim((string) ($module['moduleId'] ?? '')) !== $selectedModuleId) {
                            continue;
                        }
                        $moduleScope = $module;
                        $dependencyIds = is_array($module['dependsOn'] ?? null) ? $module['dependsOn'] : [];
                        foreach ($moduleCatalog as $dependency) {
                            if (!is_array($dependency) || !in_array((string) ($dependency['moduleId'] ?? ''), $dependencyIds, true)) {
                                continue;
                            }
                            $dependencyInterfaceSummaries[] = [
                                'moduleId' => (string) ($dependency['moduleId'] ?? ''),
                                'moduleTitle' => (string) ($dependency['moduleTitle'] ?? ''),
                                'provides' => is_array($dependency['provides'] ?? null) ? $dependency['provides'] : [],
                                'consumes' => is_array($dependency['consumes'] ?? null) ? $dependency['consumes'] : [],
                            ];
                        }
                        break;
                    }
                }
            }

            $contextModuleCatalog = $moduleCatalog;
            if (is_array($moduleScope)) {
                $allowedModuleIds = array_values(array_unique(array_merge(
                    [(string) ($moduleScope['moduleId'] ?? '')],
                    is_array($moduleScope['dependsOn'] ?? null) ? array_map('strval', $moduleScope['dependsOn']) : []
                )));
                $contextModuleCatalog = array_values(array_filter($moduleCatalog, static fn ($module): bool => is_array($module) && in_array((string) ($module['moduleId'] ?? ''), $allowedModuleIds, true)));
            }

            $contextId = 'execution-roadmap-stage-' . $stageKey . ($moduleId !== '' ? '-' . strtolower($moduleId) : '') . ($phaseId !== '' ? '-' . strtolower($phaseId) : '') . '-' . substr(hash('sha256', $draftKey . ':' . $architectureHash . ':' . $stageKey . ':' . $moduleId . ':' . $phaseId), 0, 24);
            $context = [
                'context_id' => $contextId,
                'draft_key' => $draftKey,
                'workflow' => 'coordinator_execution_roadmap_' . $stageKey . '_stage',
                'stage_key' => $stageKey,
                'module_id' => $moduleId !== '' ? $moduleId : null,
                'phase_id' => $phaseId !== '' ? $phaseId : null,
                'phase_index' => $phaseIndex !== null ? $phaseIndex + 1 : null,
                'phase_count' => $phaseCount > 0 ? $phaseCount : null,
                'previous_stage_key' => $previousStageKey !== '' ? $previousStageKey : null,
                'source_architecture_hash' => $architectureHash,
                'source_ui_ux_hash' => $uiUxHash,
                'module_catalog' => $contextModuleCatalog,
                'module_scope' => $moduleScope,
                'dependency_interface_summaries' => $dependencyInterfaceSummaries,
                'system_architecture' => $architecture,
                'ui_ux_design' => $uiUxDesign,
                'previous_stage' => $previousStage,
                'phase_builder_policy' => \BuilderX\AI\PhaseBuilderPlanningPolicy::context('execution_roadmap'),
                'rules' => [
                    'this_file_contains_the_verified_previous_stage_result_when_one_is_required',
                    'use_the_saved_ui_ux_design_as_the_page_flow_and_screen_handoff_when_available',
                    'preserve_every_upstream_id_title_description_flow_step_dependency_and_meaning',
                    'enhance_only_the_current_stage',
                    'create_only_the_minimum_items_needed_to_express_confirmed_scope',
                    'do_not_split_work_by_technical_layer_when_one_coherent_work_package_is_clearer',
                    'do_not_invent_optional_resources_or_future_features',
                    'return_only_the_required_json_object_to_the_mysql_job',
                    'planning_only_no_file_edits_no_sql_and_no_product_database_changes_in_the_codex_turn',
                    'do_not_modify_builderx_phase_builder_or_phase_manager',
                    'use_lower_snake_case_for_proposed_database_tables_fields_and_files',
                    'indicators_are_allowlisted_icon_slugs_without_display_labels',
                    'stop_with_ROADMAP_CONTEXT_UNAVAILABLE_if_this_file_cannot_be_read_completely',
                    'when_phase_id_is_present_for_resources_return only the selected phase resource patch and preserve its phase ID',
                    'when_saved_module_catalog_is_present_use_module_scope_and_dependency_interface_summaries_without_reloading_unrelated_modules',
                    'when_module_id_is_present_process only that module and preserve the cumulative upstream checkpoint outside this context',
                ],
                'required_response' => [
                    'stage' => $stageKey,
                    'schemaVersion' => $stageContracts[$stageKey],
                    'contractType' => 'builderx.execution-roadmap-stage',
                    'source' => ['draftKey' => $draftKey, 'architectureHash' => $architectureHash],
                    'hierarchy' => 'phase -> tasks[] -> subTasks[] -> todos[]',
                    'module_schema' => $stageKey === 'modules'
                        ? [
                            'rootField' => 'modules',
                            'requiredFields' => ['moduleId', 'moduleKey', 'moduleTitle', 'moduleDescription', 'moduleType', 'order', 'dependsOn', 'provides', 'consumes', 'uiUxScope', 'phaseCountHint'],
                            'interfaceFields' => ['interfaceId', 'kind', 'contractSummary'],
                            'uiUxScopeFields' => ['routes', 'screens', 'sharedComponents'],
                            'constraints' => ['return the module list only as root field modules, never moduleCatalog or module_catalog', '1 to 30 modules; use one when the confirmed product has one coherent boundary', 'moduleKey uses lower_snake_case', 'dependsOn contains module IDs only', 'provides and consumes are arrays of compact interface objects, not strings', 'do not include phases, tasks, sub-tasks, todos, or resource arrays'],
                        ]
                        : null,
                    'stage_scope' => $stageKey === 'modules'
                        ? 'Generate the smallest coherent product module catalog from confirmed architecture and UI/UX scope. Return it as {"modules":[...]} with structured provides/consumes interface objects. Keep boundaries and dependency summaries concise; do not generate phases or implementation resources.'
                        : ($stageKey === 'phases'
                        ? ($moduleId !== '' ? 'Generate only the essential implementation phases for module ' . $moduleId . ' and its confirmed dependencies. Every returned phase must use moduleId ' . $moduleId . '.' : 'Generate only essential phases grouped by the saved module catalog. Include flow nodes only for confirmed components that actually participate.')
                        : ($stageKey === 'tasks'
                            ? 'Add the minimum practical tasks needed for each confirmed phase. Do not split one coherent task into separate technical-layer tasks merely for completeness.'
                            : ($stageKey === 'subtasks'
                                ? 'Add concise executable sub-tasks and at least one Pending todo only where required by the contract. One is enough when it fully expresses the work.'
                                : 'Return only resource patches keyed by phaseId. Include forms, tables, APIs, background processes, reports, or analytics only when confirmed scope needs them; keep all other resource arrays empty. Do not regenerate the hierarchy or add speculative resources.'))),
                    'resource_patch_schema' => $stageKey === 'resources'
                        ? [
                            'requiredFields' => ['schemaVersion', 'contractType', 'stage', 'source', 'resourcePatches'],
                            'resourcePatchFields' => ['phaseId', 'proposedResources'],
                            'resourceTypes' => ['forms', 'tables', 'apis', 'backgroundProcesses', 'reports', 'analytics'],
                            'resourceItemRules' => [
                                'every item in every resource array must include a stable id and name',
                                'forms must include formId, formName, formAction, purpose, route, and a non-empty fields array',
                                'formAction must be exactly one of add, edit, search, delete, view, or bulk_update',
                                'every form field must include name, label, type, required, and nullable; field names use lower_snake_case',
                                'tables must include tableId, tableName, purpose, and a non-empty fields array',
                                'every table field must include name, type, nullable, and a clear purpose; table and field names use lower_snake_case',
                                'APIs must include apiId, name, method, route, request, response, and error behavior',
                                'backgroundProcesses must include processId, name, trigger, input, output, and retry behavior',
                                'reports and analytics must include an explicit name, source, dimensions or metrics, and access boundary',
                            ],
                            'constraints' => $phaseId !== ''
                                ? ['exactly one patch for phaseId ' . $phaseId, 'preserve the selected phase ID', 'do not include the phase/task/sub-task/todo hierarchy']
                                : ['one patch per saved phase', 'preserve phase IDs', 'do not include the phase/task/sub-task/todo hierarchy'],
                        ]
                        : null,
                    'subtask_schema' => $stageKey === 'subtasks'
                        ? [
                            'requiredTaskFields' => ['taskId', 'taskTitle', 'taskDescription', 'taskType', 'track', 'indicators', 'subTasks'],
                            'requiredSubTaskFields' => ['subtaskId', 'subtaskTitle', 'subtaskDescription', 'acceptanceCriteria', 'dependsOn', 'todos'],
                            'requiredTodoFields' => ['todoId', 'todoTitle', 'todoDescription', 'todoType', 'status'],
                            'constraints' => ['every task must have at least one sub-task', 'every sub-task must have a non-empty description and at least one acceptance criterion', 'every sub-task must have at least one Pending todo', 'preserve every upstream task ID and meaning'],
                        ]
                        : null,
                ],
            ];
            $result = $writePhaseCoordinatorContext($contextId, $context);
            $respondJson([
                'ok' => true,
                'context_id' => $contextId,
                'stage_key' => $stageKey,
                'phase_id' => $phaseId !== '' ? $phaseId : null,
                'module_id' => $moduleId !== '' ? $moduleId : null,
                'phase_index' => $phaseIndex !== null ? $phaseIndex + 1 : null,
                'phase_count' => $phaseCount > 0 ? $phaseCount : null,
                'previous_stage_key' => $previousStageKey,
                'source_architecture_hash' => $architectureHash,
                ...$result,
            ]);
        }

        if ($action === 'clear_phase_builder_execution_roadmap_stages') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey)) {
                $fail('A saved BuilderX draft is required before clearing Execution Roadmap stages.');
            }
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT roadmap_key FROM phase_builder_execution_roadmap WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                if (is_array($existing) && trim((string) ($existing['roadmap_key'] ?? '')) !== '') {
                    $roadmapKey = trim((string) $existing['roadmap_key']);
                    $cleared = $db->Execute('UPDATE phase_builder_execution_roadmap SET roadmap_json = ?, progress_json = ?, stages_json = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_key = ?', ['{}', '{}', '{}', $draftKey]);
                    if ($cleared === false) {
                        throw new RuntimeException('Execution Roadmap stage cleanup failed: ' . trim((string) $db->ErrorMsg()));
                    }
                    $readBack = $db->GetRow('SELECT roadmap_key, roadmap_json, progress_json, stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                    if (!is_array($readBack) || (string) ($readBack['roadmap_key'] ?? '') !== $roadmapKey || (string) ($readBack['roadmap_json'] ?? '') !== '{}' || (string) ($readBack['progress_json'] ?? '') !== '{}' || (string) ($readBack['stages_json'] ?? '') !== '{}') {
                        throw new RuntimeException('Execution Roadmap stage cleanup read-back verification failed.');
                    }
                    bx_audit('UPDATE', 'phase_builder_execution_roadmap', $roadmapKey, ['draft_key' => $draftKey, 'action' => 'clear_staged_results']);
                }
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'Execution Roadmap staged results cleared and verified.', 'data' => ['draft_key' => $draftKey, 'stages' => [], 'execution_roadmap' => (object) [], 'execution_roadmap_progress' => (object) []]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Execution Roadmap staged results could not be cleared.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_execution_roadmap_stage') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $stageKey = trim((string) ($_POST['stage_key'] ?? ''));
            $stageJson = trim((string) ($_POST['stage_json'] ?? ''));
            $contextArchitectureHash = strtolower(trim((string) ($_POST['context_architecture_hash'] ?? '')));
            $allowedStageKeys = ['modules', 'phases', 'tasks', 'subtasks', 'resources'];
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $draftKey) || !in_array($stageKey, $allowedStageKeys, true) || $stageJson === '' || $contextArchitectureHash === '') {
                $fail('A valid Execution Roadmap stage is required.');
            }
            if (strlen($stageJson) > 10000000) {
                $fail('The Execution Roadmap stage response is too large to save.');
            }
            $stage = json_decode($stageJson, true);
            if (!is_array($stage) || array_is_list($stage) || !is_array($stage['source'] ?? null) || ($stage['source']['draftKey'] ?? '') !== $draftKey || trim((string) ($stage['source']['architectureHash'] ?? '')) !== $contextArchitectureHash) {
                $fail('Execution Roadmap stage source verification failed.');
            }
            $hasIntermediateStageContract = strpos((string) ($stage['schemaVersion'] ?? ''), 'builderx.execution-roadmap.stage.') === 0
                && ($stage['contractType'] ?? '') === 'builderx.execution-roadmap-stage';
            $hasFinalResourcesContract = $stageKey === 'resources'
                && ($stage['schemaVersion'] ?? '') === 'builderx.execution-roadmap.v3'
                && ($stage['contractType'] ?? '') === 'builderx.execution-roadmap';
            if (!$hasIntermediateStageContract && !$hasFinalResourcesContract) {
                $fail('Execution Roadmap returned an unsupported stage contract.');
            }
            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            if ($architectureJson === '' || !hash_equals(hash('sha256', $architectureJson), $contextArchitectureHash)) {
                $fail('Execution Roadmap source verification failed. System Architecture changed after the context was prepared.');
            }
            $canonicalStageJson = json_encode($stage, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonicalStageJson)) {
                $fail('Execution Roadmap stage could not be encoded for persistence.');
            }
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT roadmap_key, roadmap_json, progress_json, stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $roadmapKey = is_array($existing) && trim((string) ($existing['roadmap_key'] ?? '')) !== '' ? trim((string) $existing['roadmap_key']) : bx_uuid();
                $stages = is_array($existing) ? json_decode((string) ($existing['stages_json'] ?? '{}'), true) : [];
                if (!is_array($stages) || array_is_list($stages)) {
                    $stages = [];
                }
                $stages[$stageKey] = ['status' => 'completed', 'stage' => $stage, 'savedAt' => date('c')];
                $stagesJson = json_encode($stages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($stagesJson)) {
                    throw new RuntimeException('Execution Roadmap stages could not be encoded.');
                }
                $roadmapJsonForInsert = is_array($existing) ? (string) ($existing['roadmap_json'] ?? '{}') : '{}';
                $progressJsonForInsert = is_array($existing) ? (string) ($existing['progress_json'] ?? '{}') : '{}';
                $writeUserKey = $userKey !== '' ? $userKey : null;
                $saved = $db->Execute('INSERT INTO phase_builder_execution_roadmap (roadmap_key, draft_key, source_architecture_hash, roadmap_json, progress_json, stages_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_architecture_hash = VALUES(source_architecture_hash), stages_json = VALUES(stages_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP', [$roadmapKey, $draftKey, $contextArchitectureHash, $roadmapJsonForInsert, $progressJsonForInsert, $stagesJson, $writeUserKey, $writeUserKey]);
                if ($saved === false) {
                    throw new RuntimeException('Execution Roadmap stage upsert failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT roadmap_key, stages_json, updated_at FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                $readBackStages = is_array($readBack) ? json_decode((string) ($readBack['stages_json'] ?? '{}'), true) : null;
                if (!is_array($readBack) || (string) ($readBack['roadmap_key'] ?? '') !== $roadmapKey || !is_array($readBackStages) || !isset($readBackStages[$stageKey]) || json_encode($readBackStages[$stageKey]['stage'] ?? null, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== $canonicalStageJson) {
                    throw new RuntimeException('Execution Roadmap stage read-back verification failed.');
                }
                bx_audit('UPDATE', 'phase_builder_execution_roadmap', $roadmapKey, ['draft_key' => $draftKey, 'stage' => $stageKey]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'Execution Roadmap stage saved and verified.', 'data' => ['roadmap_key' => $roadmapKey, 'draft_key' => $draftKey, 'source_architecture_hash' => $contextArchitectureHash, 'stage_key' => $stageKey, 'stage' => $stage, 'stages' => $readBackStages, 'updated_at' => (string) ($readBack['updated_at'] ?? '')]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Execution Roadmap stage could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_execution_roadmap') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $roadmapJson = trim((string) ($_POST['roadmap_json'] ?? ''));
            $contextArchitectureHash = strtolower(trim((string) ($_POST['context_architecture_hash'] ?? '')));
            if ($draftKey === '' || $roadmapJson === '' || $contextArchitectureHash === '') {
                $fail('A saved BuilderX draft and completed Execution Roadmap response are required.');
            }
            if (strlen($roadmapJson) > 10000000) {
                $fail('The Execution Roadmap response is too large to save.');
            }
            $roadmap = json_decode($roadmapJson, true);
            if (!is_array($roadmap) || array_is_list($roadmap)) {
                $fail('Execution Roadmap returned an invalid JSON object.');
            }
            if (!isset($roadmap['phaseExecutionOverview']) && isset($roadmap['phases']) && is_array($roadmap['phases'])) {
                $taskCount = 0;
                $subTaskCount = 0;
                foreach ($roadmap['phases'] as $overviewPhase) {
                    if (!is_array($overviewPhase) || !is_array($overviewPhase['tasks'] ?? null)) {
                        continue;
                    }
                    $taskCount += count($overviewPhase['tasks']);
                    foreach ($overviewPhase['tasks'] as $overviewTask) {
                        if (is_array($overviewTask) && is_array($overviewTask['subTasks'] ?? null)) {
                            $subTaskCount += count($overviewTask['subTasks']);
                        }
                    }
                }
                $roadmap['phaseExecutionOverview'] = [
                    'totalPhases' => count($roadmap['phases']),
                    'totalTasks' => $taskCount,
                    'totalSubTasks' => $subTaskCount,
                    'status' => 'Pending',
                ];
            }
            $requiredRoadmapKeys = ['schemaVersion', 'contractType', 'source', 'phaseExecutionOverview', 'phases'];
            foreach ($requiredRoadmapKeys as $requiredKey) {
                if (!array_key_exists($requiredKey, $roadmap)) {
                    $fail('Execution Roadmap is missing the required field: ' . $requiredKey . '.');
                }
            }
            $isRoadmapV3 = $roadmap['schemaVersion'] === 'builderx.execution-roadmap.v3';
            if ((!$isRoadmapV3 && $roadmap['schemaVersion'] !== 'builderx.execution-roadmap.v2') || $roadmap['contractType'] !== 'builderx.execution-roadmap') {
                $fail('Execution Roadmap returned an unsupported contract version.');
            }
            if (!is_array($roadmap['source']) || ($roadmap['source']['draftKey'] ?? '') !== $draftKey || !is_array($roadmap['phaseExecutionOverview']) || !is_array($roadmap['phases']) || count($roadmap['phases']) < 1 || count($roadmap['phases']) > 30) {
                $fail('Execution Roadmap must contain a valid source, overview, and 1 to 30 essential phases.');
            }
            $allowedIndicators = ['api', 'background_process', 'database', 'crud', 'authentication', 'authorization', 'validation', 'frontend', 'backend', 'mobile', 'synchronization', 'queue', 'search', 'reporting', 'audit', 'migration', 'testing', 'accessibility', 'external_integration', 'realtime', 'deployment', 'files', 'notifications', 'cache', 'security', 'forms', 'offline'];
            $indicatorAliases = [
                'sync' => 'synchronization',
                'ui' => 'frontend',
                'ux' => 'frontend',
                'ui_ux' => 'frontend',
                'web' => 'frontend',
                'android' => 'mobile',
                'kotlin' => 'mobile',
                'mysql' => 'database',
                'firestore' => 'external_integration',
                'php' => 'backend',
                'node' => 'backend',
                'worker' => 'background_process',
                'background' => 'background_process',
                'job' => 'background_process',
                'endpoint' => 'api',
                'api_endpoint' => 'api',
                'permissions' => 'authorization',
                'roles' => 'authorization',
                'logging' => 'audit',
                'monitoring' => 'reporting',
                'observability' => 'reporting',
                'retry' => 'queue',
                'offline_queue' => 'queue',
                'websocket' => 'realtime',
                'file_upload' => 'files',
                'file_download' => 'files',
                'import' => 'files',
                'export' => 'files',
                'performance' => 'testing',
                'backup' => 'deployment',
                'rollback' => 'deployment',
                'integration' => 'external_integration',
            ];
            $allowedFormActions = ['add', 'edit', 'search', 'delete', 'view', 'bulk_update'];
            $identifierPattern = '/^[a-z][a-z0-9_]{0,63}$/';
            $nodeTypeAliases = [
                'ui_surface' => 'view',
                'mobile_surface' => 'view',
                'mobile_queue' => 'background',
                'security_boundary' => 'decision',
                'backend_data_plane' => 'database',
                'sync_process' => 'background',
                'background_process' => 'background',
                'api_endpoint' => 'api',
                'reporting' => 'report',
            ];
            if ($isRoadmapV3) {
                foreach ($roadmap['phases'] as $phaseIndex => &$normalizingPhase) {
                    if (!is_array($normalizingPhase)) {
                        continue;
                    }
                    if (!is_array($normalizingPhase['systemFlow'] ?? null) && is_array($normalizingPhase['systemFlowNodes'] ?? null)) {
                        $normalizingPhase['systemFlow'] = $normalizingPhase['systemFlowNodes'];
                    }
                    if (is_array($normalizingPhase['systemFlow'] ?? null)) {
                        foreach ($normalizingPhase['systemFlow'] as $flowIndex => &$flowStep) {
                            if (!is_array($flowStep)) {
                                continue;
                            }
                            $nodeId = trim((string) ($flowStep['nodeId'] ?? $flowStep['flowStepId'] ?? ''));
                            $label = trim((string) ($flowStep['label'] ?? $flowStep['action'] ?? $flowStep['description'] ?? ''));
                            $kind = trim((string) ($flowStep['kind'] ?? $flowStep['nodeType'] ?? 'view'));
                            $flowStep['flowStepId'] = trim((string) ($flowStep['flowStepId'] ?? '')) !== '' ? $flowStep['flowStepId'] : ('FLOW-' . str_pad((string) ($flowIndex + 1), 3, '0', STR_PAD_LEFT));
                            $flowStep['order'] = is_int($flowStep['order'] ?? null) ? $flowStep['order'] : ($flowIndex + 1);
                            $flowStep['from'] = trim((string) ($flowStep['from'] ?? '')) !== '' ? $flowStep['from'] : ($flowIndex === 0 ? 'Start' : ('FLOW-' . str_pad((string) $flowIndex, 3, '0', STR_PAD_LEFT)));
                            $flowStep['action'] = trim((string) ($flowStep['action'] ?? '')) !== '' ? $flowStep['action'] : ($label !== '' ? $label : ($nodeId !== '' ? $nodeId : 'Process confirmed roadmap node'));
                            $flowStep['to'] = trim((string) ($flowStep['to'] ?? '')) !== '' ? $flowStep['to'] : ($nodeId !== '' ? $nodeId : ('FLOW-' . str_pad((string) ($flowIndex + 1), 3, '0', STR_PAD_LEFT)));
                            $flowStep['nodeType'] = $nodeTypeAliases[$kind] ?? (in_array($kind, ['page', 'view', 'form', 'api', 'database', 'background', 'report', 'analytics', 'decision'], true) ? $kind : 'view');
                        }
                        unset($flowStep);
                    }
                }
                unset($normalizingPhase);
            }
            $phaseIds = [];
            foreach ($roadmap['phases'] as $phaseIndex => $roadmapPhase) {
                if (!is_array($roadmapPhase) || trim((string) ($roadmapPhase['phaseId'] ?? '')) === '' || trim((string) ($roadmapPhase['phaseTitle'] ?? '')) === '' || trim((string) ($roadmapPhase['phaseDescription'] ?? '')) === '' || ($roadmapPhase['status'] ?? '') !== 'Pending' || ($isRoadmapV3 && !in_array(($roadmapPhase['phaseType'] ?? ''), ['foundation', 'vertical_slice', 'background_process', 'cross_cutting'], true)) || ($isRoadmapV3 && !is_array($roadmapPhase['systemFlow'] ?? null)) || ($isRoadmapV3 && !is_array($roadmapPhase['proposedResources'] ?? null)) || !is_array($roadmapPhase['tasks'] ?? null) || count($roadmapPhase['tasks']) < 1 || count($roadmapPhase['tasks']) > 16) {
                    $fail('Execution Roadmap phase ' . ((int) $phaseIndex + 1) . ' must include a title, description, Pending status, and 1 to 16 focused tasks.');
                }
                $phaseId = trim((string) $roadmapPhase['phaseId']);
                if (isset($phaseIds[$phaseId])) {
                    $fail('Execution Roadmap phase IDs must be unique.');
                }
                $phaseIds[$phaseId] = true;
                if ($isRoadmapV3) {
                    $flowIds = [];
                    foreach ($roadmapPhase['systemFlow'] as $flowStep) {
                        if (!is_array($flowStep) || trim((string) ($flowStep['flowStepId'] ?? '')) === '' || !is_int($flowStep['order'] ?? null) || trim((string) ($flowStep['from'] ?? '')) === '' || trim((string) ($flowStep['action'] ?? '')) === '' || trim((string) ($flowStep['to'] ?? '')) === '' || !in_array(($flowStep['nodeType'] ?? ''), ['page', 'view', 'form', 'api', 'database', 'background', 'report', 'analytics', 'decision'], true)) {
                            $fail('Execution Roadmap system flow steps must include ordered nodes, actions, and supported node types.');
                        }
                        $flowId = trim((string) $flowStep['flowStepId']);
                        if (isset($flowIds[$flowId])) {
                            $fail('Execution Roadmap flow step IDs must be unique within each phase.');
                        }
                        $flowIds[$flowId] = true;
                    }
                    foreach (['forms', 'tables', 'apis', 'backgroundProcesses', 'reports', 'analytics'] as $resourceType) {
                        if (!is_array($roadmapPhase['proposedResources'][$resourceType] ?? null)) {
                            $fail('Execution Roadmap proposed resources must include ' . $resourceType . '.');
                        }
                        foreach ($roadmapPhase['proposedResources'][$resourceType] as $resource) {
                            if (!is_array($resource) || trim((string) ($resource['id'] ?? $resource['formId'] ?? $resource['tableId'] ?? $resource['apiId'] ?? $resource['processId'] ?? $resource['reportId'] ?? $resource['analyticsId'] ?? '')) === '' || trim((string) ($resource['name'] ?? $resource['formName'] ?? $resource['tableName'] ?? $resource['operation'] ?? '')) === '') {
                                $fail('Execution Roadmap proposed resources must include stable IDs and names.');
                            }
                            if ($resourceType === 'tables') {
                                if (!preg_match($identifierPattern, (string) ($resource['tableName'] ?? '')) || !is_array($resource['fields'] ?? null)) {
                                    $fail('Execution Roadmap proposed table names must use lower_snake_case and include fields.');
                                }
                                $resourceFieldIds = [];
                                foreach ($resource['fields'] as $resourceField) {
                                    if (!is_array($resourceField) || !preg_match($identifierPattern, (string) ($resourceField['name'] ?? '')) || !is_string($resourceField['type'] ?? null) || !is_bool($resourceField['nullable'] ?? null)) {
                                        $fail('Execution Roadmap proposed table fields must use lower_snake_case names, types, and nullable flags.');
                                    }
                                    if (isset($resourceFieldIds[$resourceField['name']])) {
                                        $fail('Execution Roadmap proposed table field names must be unique.');
                                    }
                                    $resourceFieldIds[$resourceField['name']] = true;
                                }
                            }
                            if ($resourceType === 'forms') {
                                if (!in_array(($resource['formAction'] ?? ''), $allowedFormActions, true) || !is_array($resource['fields'] ?? null)) {
                                    $fail('Execution Roadmap proposed forms must include a supported action and fields.');
                                }
                                foreach ($resource['fields'] as $formField) {
                                    if (!is_array($formField) || !preg_match($identifierPattern, (string) ($formField['name'] ?? ''))) {
                                        $fail('Execution Roadmap proposed form fields must use lower_snake_case names.');
                                    }
                                }
                            }
                        }
                    }
                }
                $taskIds = [];
                foreach ($roadmapPhase['tasks'] as $taskIndex => $task) {
                    $taskTypes = ['page', 'form', 'api', 'database', 'background_process', 'report', 'analytics', 'security', 'test', 'integration', 'vertical_slice'];
                    if (!is_array($task) || trim((string) ($task['taskId'] ?? '')) === '' || trim((string) ($task['taskTitle'] ?? '')) === '' || trim((string) ($task['taskDescription'] ?? '')) === '' || ($isRoadmapV3 && !in_array(($task['taskType'] ?? ''), $taskTypes, true)) || !in_array(($task['track'] ?? ''), ['web', 'android', 'shared'], true) || !is_array($task['indicators'] ?? null) || (!$isRoadmapV3 && !is_array($task['suggestions'] ?? null)) || !is_array($task['subTasks'] ?? null) || count($task['subTasks']) < 1 || count($task['subTasks']) > 16) {
                        $fail('Execution Roadmap task ' . ((int) $taskIndex + 1) . ' in ' . $phaseId . ' must include a title, description, track, indicators, suggestions, and 1 to 16 sub-tasks.');
                    }
                    $taskId = trim((string) $task['taskId']);
                    if (isset($taskIds[$taskId])) {
                        $fail('Execution Roadmap task IDs must be unique within each phase.');
                    }
                    $taskIds[$taskId] = true;
                    foreach ($task['indicators'] as $indicatorIndex => $indicator) {
                        if (is_string($indicator) && isset($indicatorAliases[$indicator])) {
                            $roadmap['phases'][$phaseIndex]['tasks'][$taskIndex]['indicators'][$indicatorIndex] = $indicatorAliases[$indicator];
                            $indicator = $indicatorAliases[$indicator];
                        }
                        if (!is_string($indicator) || !in_array($indicator, $allowedIndicators, true)) {
                            $fail('Execution Roadmap task ' . $taskId . ' contains an unsupported indicator: ' . (is_scalar($indicator) ? (string) $indicator : 'invalid') . '.');
                        }
                    }
                    $suggestions = is_array($task['suggestions'] ?? null) ? $task['suggestions'] : ['tableName' => '', 'tableFields' => [], 'relatedFiles' => [], 'forms' => []];
                    foreach (['tableName', 'tableFields', 'relatedFiles', 'forms'] as $suggestionKey) {
                        if ($isRoadmapV3 && !array_key_exists('suggestions', $task)) {
                            break;
                        }
                        if (!array_key_exists($suggestionKey, $suggestions)) {
                            $fail('Execution Roadmap task suggestions are incomplete.');
                        }
                    }
                    if ((!$isRoadmapV3 || array_key_exists('suggestions', $task)) && (!is_string($suggestions['tableName']) || ($suggestions['tableName'] !== '' && !preg_match($identifierPattern, $suggestions['tableName'])) || !is_array($suggestions['tableFields']) || !is_array($suggestions['relatedFiles']) || !is_array($suggestions['forms']))) {
                        $fail('Execution Roadmap database and file suggestions are invalid.');
                    }
                    $fieldNames = [];
                    foreach ($suggestions['tableFields'] as $field) {
                        if (!is_array($field) || !is_string($field['name'] ?? null) || !preg_match($identifierPattern, (string) $field['name']) || !is_string($field['type'] ?? null) || trim((string) $field['type']) === '' || !is_bool($field['nullable'] ?? null)) {
                            $fail('Execution Roadmap table field suggestions must use lower_snake_case names, types, and nullable flags.');
                        }
                        if (isset($fieldNames[$field['name']])) {
                            $fail('Execution Roadmap table field suggestion names must be unique.');
                        }
                        $fieldNames[$field['name']] = true;
                    }
                    foreach ($suggestions['relatedFiles'] as $relatedFile) {
                        if (!is_string($relatedFile) || trim($relatedFile) === '' || strlen($relatedFile) > 500) {
                            $fail('Execution Roadmap related file suggestions are invalid.');
                        }
                    }
                    foreach ($suggestions['forms'] as $form) {
                        if (!is_array($form) || !is_string($form['name'] ?? null) || trim((string) $form['name']) === '' || !in_array(($form['action'] ?? ''), $allowedFormActions, true)) {
                            $fail('Execution Roadmap form suggestions must include a name and supported action.');
                        }
                    }
                    $subtaskIds = [];
                    foreach ($task['subTasks'] as $subtask) {
                        $subtaskKeys = $isRoadmapV3 ? ['subtaskId', 'subtaskTitle', 'subtaskDescription', 'acceptanceCriteria', 'dependsOn', 'todos'] : ['subtaskId', 'subtaskTitle'];
                        if (!is_array($subtask)) {
                            $fail('Execution Roadmap sub-task must be an object.');
                        }
                        $missingSubtaskFields = [];
                        $unexpectedSubtaskFields = array_diff(array_keys($subtask), $subtaskKeys);
                        if (trim((string) ($subtask['subtaskId'] ?? '')) === '') {
                            $missingSubtaskFields[] = 'subtaskId';
                        }
                        if (trim((string) ($subtask['subtaskTitle'] ?? '')) === '') {
                            $missingSubtaskFields[] = 'subtaskTitle';
                        }
                        if ($isRoadmapV3) {
                            if (trim((string) ($subtask['subtaskDescription'] ?? '')) === '') {
                                $missingSubtaskFields[] = 'subtaskDescription';
                            }
                            if (!is_array($subtask['acceptanceCriteria'] ?? null) || count($subtask['acceptanceCriteria']) === 0) {
                                $missingSubtaskFields[] = 'acceptanceCriteria';
                            }
                            if (!is_array($subtask['dependsOn'] ?? null)) {
                                $missingSubtaskFields[] = 'dependsOn';
                            }
                            if (!is_array($subtask['todos'] ?? null) || count($subtask['todos']) === 0) {
                                $missingSubtaskFields[] = 'todos';
                            }
                        }
                        if ($unexpectedSubtaskFields !== []) {
                            $missingSubtaskFields[] = 'unexpected fields: ' . implode(', ', $unexpectedSubtaskFields);
                        }
                        if ($missingSubtaskFields !== []) {
                            $fail('Execution Roadmap sub-task ' . trim((string) ($subtask['subtaskId'] ?? 'unknown')) . ' is incomplete: ' . implode(', ', $missingSubtaskFields) . '.');
                        }
                        $subtaskId = trim((string) $subtask['subtaskId']);
                        if (isset($subtaskIds[$subtaskId])) {
                            $fail('Execution Roadmap sub-task IDs must be unique within each task.');
                        }
                        $subtaskIds[$subtaskId] = true;
                        if ($isRoadmapV3) {
                            $todoIds = [];
                            foreach ($subtask['acceptanceCriteria'] as $criterion) {
                                if (!is_string($criterion) || trim($criterion) === '') {
                                    $fail('Execution Roadmap acceptance criteria must be non-empty text.');
                                }
                            }
                            foreach ($subtask['todos'] as $todo) {
                                if (!is_array($todo) || trim((string) ($todo['todoId'] ?? '')) === '' || trim((string) ($todo['todoTitle'] ?? '')) === '' || trim((string) ($todo['todoDescription'] ?? '')) === '' || trim((string) ($todo['todoType'] ?? '')) === '' || ($todo['status'] ?? '') !== 'Pending') {
                                    $fail('Execution Roadmap todos must include an ID, title, description, type, and Pending status.');
                                }
                                $todoId = trim((string) $todo['todoId']);
                                if (isset($todoIds[$todoId])) {
                                    $fail('Execution Roadmap todo IDs must be unique within each sub-task.');
                                }
                                $todoIds[$todoId] = true;
                            }
                        }
                    }
                }
            }
            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            if ($architectureJson === '') {
                $fail('System Architecture is unavailable for Execution Roadmap verification.');
            }
            $architectureHash = hash('sha256', $architectureJson);
            if (!hash_equals($architectureHash, $contextArchitectureHash)) {
                $fail('Execution Roadmap source verification failed. System Architecture changed after the context was prepared.');
            }
            $roadmap['source']['architectureHash'] = $architectureHash;
            $canonicalJson = json_encode($roadmap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonicalJson)) {
                $fail('Execution Roadmap could not be encoded for persistence.');
            }
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT roadmap_key, progress_json FROM phase_builder_execution_roadmap WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $existingKey = is_array($existing) ? trim((string) ($existing['roadmap_key'] ?? '')) : '';
                $roadmapKey = $existingKey !== '' ? $existingKey : bx_uuid();
                $progress = is_array($existing) ? json_decode((string) ($existing['progress_json'] ?? '{}'), true) : [];
                if (!is_array($progress) || array_is_list($progress)) {
                    $progress = [];
                }
                $validProgress = [];
                foreach ($roadmap['phases'] as $roadmapPhase) {
                    $phaseId = trim((string) $roadmapPhase['phaseId']);
                    foreach ($roadmapPhase['tasks'] as $task) {
                        $taskId = trim((string) $task['taskId']);
                        $taskProgressKeys = [$phaseId . ':' . $taskId];
                        foreach ($task['subTasks'] as $subtask) {
                            $subtaskId = trim((string) $subtask['subtaskId']);
                            $taskProgressKeys[] = $phaseId . ':' . $taskId . ':' . $subtaskId;
                            foreach (($subtask['todos'] ?? []) as $todo) {
                                $taskProgressKeys[] = $phaseId . ':' . $taskId . ':' . $subtaskId . ':' . trim((string) ($todo['todoId'] ?? ''));
                            }
                        }
                        foreach ($taskProgressKeys as $progressKey) {
                            if (($progress[$progressKey] ?? false) === true) {
                                $validProgress[$progressKey] = true;
                            }
                        }
                    }
                }
                $progressJson = json_encode($validProgress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $writeUserKey = $userKey !== '' ? $userKey : null;
                $saved = $db->Execute('INSERT INTO phase_builder_execution_roadmap (roadmap_key, draft_key, source_architecture_hash, roadmap_json, progress_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_architecture_hash = VALUES(source_architecture_hash), roadmap_json = VALUES(roadmap_json), progress_json = VALUES(progress_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP', [$roadmapKey, $draftKey, $architectureHash, $canonicalJson, $progressJson, $writeUserKey, $writeUserKey]);
                if ($saved === false) {
                    throw new RuntimeException('Execution Roadmap upsert failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT roadmap_key, draft_key, source_architecture_hash, roadmap_json, progress_json, updated_at FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($readBack) || (string) ($readBack['roadmap_key'] ?? '') !== $roadmapKey || (string) ($readBack['draft_key'] ?? '') !== $draftKey || (string) ($readBack['source_architecture_hash'] ?? '') !== $architectureHash || (string) ($readBack['roadmap_json'] ?? '') !== $canonicalJson || (string) ($readBack['progress_json'] ?? '') !== $progressJson) {
                    throw new RuntimeException('Execution Roadmap read-back verification failed.');
                }
                bx_audit($existingKey !== '' ? 'UPDATE' : 'CREATE', 'phase_builder_execution_roadmap', $roadmapKey, ['draft_key' => $draftKey, 'source_architecture_hash' => $architectureHash]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'Execution Roadmap saved and verified.', 'data' => ['status' => $existingKey !== '' ? 'updated' : 'created', 'roadmap_key' => $roadmapKey, 'draft_key' => $draftKey, 'source_architecture_hash' => $architectureHash, 'execution_roadmap' => $roadmap, 'execution_roadmap_progress' => $validProgress, 'updated_at' => (string) ($readBack['updated_at'] ?? '')]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Execution Roadmap could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'update_phase_execution_roadmap_task') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $roadmapPhaseId = trim((string) ($_POST['roadmap_phase_id'] ?? ''));
            $taskId = trim((string) ($_POST['task_id'] ?? ''));
            $subtaskId = trim((string) ($_POST['subtask_id'] ?? ''));
            $todoId = trim((string) ($_POST['todo_id'] ?? ''));
            $track = trim((string) ($_POST['track'] ?? ''));
            $taskIndex = filter_var($_POST['task_index'] ?? null, FILTER_VALIDATE_INT);
            $completed = filter_var($_POST['completed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($draftKey === '' || $roadmapPhaseId === '' || $completed === null || ($taskId === '' && (!in_array($track, ['web', 'android'], true) || $taskIndex === false || $taskIndex < 0))) {
                $fail('A valid Execution Roadmap task is required.');
            }
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $row = $db->GetRow('SELECT roadmap_key, roadmap_json, progress_json FROM phase_builder_execution_roadmap WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $roadmap = is_array($row) ? json_decode((string) ($row['roadmap_json'] ?? ''), true) : null;
                $progress = is_array($row) ? json_decode((string) ($row['progress_json'] ?? '{}'), true) : null;
                if (!is_array($row) || !is_array($roadmap) || !is_array($progress)) {
                    $fail('The saved Execution Roadmap could not be read.');
                }
                $found = false;
                $progressKey = '';
                foreach (($roadmap['phases'] ?? []) as $roadmapPhase) {
                    if (!is_array($roadmapPhase) || (string) ($roadmapPhase['phaseId'] ?? '') !== $roadmapPhaseId) {
                        continue;
                    }
                    if (in_array(($roadmap['schemaVersion'] ?? ''), ['builderx.execution-roadmap.v2', 'builderx.execution-roadmap.v3'], true)) {
                        foreach (($roadmapPhase['tasks'] ?? []) as $roadmapTask) {
                            if (!is_array($roadmapTask) || (string) ($roadmapTask['taskId'] ?? '') !== $taskId) {
                                continue;
                            }
                            if ($subtaskId === '' && $todoId === '') {
                                $found = true;
                                $progressKey = $roadmapPhaseId . ':' . $taskId;
                                break;
                            }
                            foreach (($roadmapTask['subTasks'] ?? []) as $subtask) {
                                if (is_array($subtask) && (string) ($subtask['subtaskId'] ?? '') === $subtaskId && $todoId === '') {
                                    $found = true;
                                    $progressKey = $roadmapPhaseId . ':' . $taskId . ':' . $subtaskId;
                                    break 2;
                                }
                                if (is_array($subtask) && (string) ($subtask['subtaskId'] ?? '') === $subtaskId && $todoId !== '') {
                                    foreach (($subtask['todos'] ?? []) as $todo) {
                                        if (is_array($todo) && (string) ($todo['todoId'] ?? '') === $todoId) {
                                            $found = true;
                                            $progressKey = $roadmapPhaseId . ':' . $taskId . ':' . $subtaskId . ':' . $todoId;
                                            break 3;
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        $field = $track === 'web' ? 'webTrackTasks' : 'androidTrackTasks';
                        $found = is_array($roadmapPhase[$field] ?? null) && array_key_exists($taskIndex, $roadmapPhase[$field]);
                        $progressKey = $roadmapPhaseId . ':' . $track . ':' . (int) $taskIndex;
                    }
                    break;
                }
                if (!$found) {
                    $fail('The selected Execution Roadmap task was not found.');
                }
                if ($completed) {
                    $progress[$progressKey] = true;
                } else {
                    unset($progress[$progressKey]);
                }
                $progressJson = json_encode($progress, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($db->Execute('UPDATE phase_builder_execution_roadmap SET progress_json = ?, updated_at = CURRENT_TIMESTAMP WHERE draft_key = ?', [$progressJson, $draftKey]) === false) {
                    throw new RuntimeException('Execution Roadmap task update failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT progress_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$draftKey]);
                $readBackProgress = is_array($readBack) ? json_decode((string) ($readBack['progress_json'] ?? '{}'), true) : null;
                if (!is_array($readBackProgress) || (($readBackProgress[$progressKey] ?? false) === true) !== $completed) {
                    throw new RuntimeException('Execution Roadmap task read-back verification failed.');
                }
                bx_audit('UPDATE', 'phase_builder_execution_roadmap', (string) $row['roadmap_key'], ['draft_key' => $draftKey, 'task' => $progressKey, 'completed' => $completed]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'Execution Roadmap task updated and verified.', 'data' => ['draft_key' => $draftKey, 'execution_roadmap_progress' => $readBackProgress]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Execution Roadmap task could not be updated.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_requirements_analysis') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $analysisJson = trim((string) ($_POST['analysis_json'] ?? ''));
            $contextSourceHash = strtolower(trim((string) ($_POST['context_source_hash'] ?? '')));
            if ($draftKey === '' || $analysisJson === '' || $contextSourceHash === '') {
                $fail('A saved BuilderX draft and completed Requirements Analysis response are required.');
            }
            if (strlen($analysisJson) > 10000000) {
                $fail('The Requirements Analysis response is too large to save.');
            }
            $analysis = json_decode($analysisJson, true);
            if (!is_array($analysis) || array_is_list($analysis)) {
                $fail('Requirements Analysis returned an invalid JSON object.');
            }
            $draft = $db->GetRow(
                'SELECT product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                [$draftKey]
            );
            if (!is_array($draft)) {
                $fail('The saved Narrative & Cleanup source could not be read back.');
            }
            $sourceFields = ['product_goal', 'users_and_roles', 'main_user_journey', 'web_requirements', 'android_requirements', 'database_and_synchronization', 'security_and_permissions', 'validation_and_error_handling', 'open_questions'];
            $sourceSnapshot = [];
            foreach ($sourceFields as $field) {
                if (!array_key_exists($field, $draft) || !is_string($draft[$field])) {
                    $fail('The saved Narrative & Cleanup source is incomplete.');
                }
                $sourceSnapshot[$field] = (string) $draft[$field];
            }
            $sourceJson = json_encode($sourceSnapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $sourceHash = is_string($sourceJson) ? hash('sha256', $sourceJson) : '';
            if (!hash_equals($sourceHash, $contextSourceHash)) {
                $fail('Requirements Analysis source verification failed. The saved narrative changed after the context was prepared.');
            }
            $categoryKeys = ['functionalRequirements', 'nonFunctionalRequirements', 'architectureConstraints', 'securityAndPrivacyRequirements', 'installationAndDeploymentRequirements', 'configurationAndEnvironmentRequirements', 'dataMigrationAndBackupRequirements', 'performanceAndScalabilityRequirements', 'availabilityAndRecoveryRequirements', 'monitoringAndAuditRequirements', 'accessibilityAndCompatibilityRequirements', 'testingAndQualityRequirements', 'maintenanceAndSupportRequirements', 'releaseAndRollbackRequirements'];
            $requiredKeys = ['schemaVersion', 'contractType', 'source', 'projectAnalysis', 'actors', 'entities', 'portals', ...$categoryKeys, 'missingDetailsOrRisks', 'assumptions', 'openQuestions', 'reviewChecklist', 'traceability', 'rag', 'orchestration'];
            foreach ($requiredKeys as $requiredKey) {
                if (!array_key_exists($requiredKey, $analysis)) {
                    $fail('Requirements Analysis is missing the required field: ' . $requiredKey . '.');
                }
            }
            if ($analysis['schemaVersion'] !== 'builderx.requirements-analysis.v2' || $analysis['contractType'] !== 'builderx.requirements-analysis') {
                $fail('Requirements Analysis returned an unsupported contract version.');
            }
            if (!is_array($analysis['source'] ?? null) || ($analysis['source']['draftKey'] ?? '') !== $draftKey) {
                $fail('Requirements Analysis source verification failed. The saved narrative may have changed.');
            }
            // The database-generated source hash is authoritative. Codex may
            // echo a long hash with a transcription typo; normalize metadata
            // only after the trusted context hash has matched the live row.
            $analysis['source']['narrativeHash'] = $sourceHash;
            $analysis['source']['sourceSections'] = $sourceFields;
            if (!is_array($analysis['projectAnalysis'] ?? null) || !is_array($analysis['orchestration'] ?? null) || !is_array($analysis['orchestration']['selectedSpecialists'] ?? null) || !is_array($analysis['orchestration']['additionalSpecialistProposals'] ?? null)) {
                $fail('Requirements Analysis returned an invalid orchestration or project analysis object.');
            }
            $specialistRegistry = new \BuilderX\AI\AiSpecialistRegistry();
            $registeredSpecialists = $specialistRegistry->listAll(100);
            $approvedSpecialistKeys = [];
            foreach ($registeredSpecialists as $specialist) {
                if (($specialist['status'] ?? '') === 'active' && ($specialist['review_status'] ?? '') === 'approved') {
                    $approvedSpecialistKeys[] = (string) ($specialist['specialist_key'] ?? '');
                }
            }
            foreach ($analysis['orchestration']['selectedSpecialists'] as $selectedSpecialist) {
                if (!is_string($selectedSpecialist) || !in_array($selectedSpecialist, $approvedSpecialistKeys, true)) {
                    $fail('Requirements Analysis selected an unavailable or unapproved specialist.');
                }
            }
            $specialistProposals = [];
            foreach ($analysis['orchestration']['additionalSpecialistProposals'] as $proposal) {
                if (!is_array($proposal) || array_is_list($proposal)) {
                    $fail('Requirements Analysis contains an invalid additional specialist proposal.');
                }
                $proposalKey = trim((string) ($proposal['specialist_key'] ?? $proposal['specialistKey'] ?? ''));
                $proposalName = trim((string) ($proposal['name'] ?? $proposal['specialist_name'] ?? ''));
                $proposalPurpose = trim((string) ($proposal['purpose'] ?? ''));
                $proposalStages = $proposal['stages'] ?? [];
                $proposalSkills = $proposal['skills'] ?? [];
                $proposalTools = $proposal['allowed_tools'] ?? $proposal['allowedTools'] ?? ['read_files', 'search_files', 'read_communication'];
                $proposalWriteScope = (string) ($proposal['write_scope'] ?? $proposal['writeScope'] ?? 'none');
                $proposalRagScopes = $proposal['rag_scopes'] ?? $proposal['ragScopes'] ?? ['project-rules', 'task-contracts'];
                if ($proposalKey === '' || $proposalName === '' || $proposalPurpose === '' || !is_array($proposalStages) || !is_array($proposalSkills) || !is_array($proposalTools) || !is_array($proposalRagScopes)) {
                    $fail('Requirements Analysis contains an incomplete additional specialist proposal.');
                }
                $specialistProposals[] = [
                    'key' => $proposalKey,
                    'name' => $proposalName,
                    'purpose' => $proposalPurpose,
                    'stages' => array_values($proposalStages),
                    'skills' => array_values($proposalSkills),
                    'allowed_tools' => array_values($proposalTools),
                    'write_scope' => $proposalWriteScope,
                    'rag_scopes' => array_values($proposalRagScopes),
                ];
            }
            foreach ($categoryKeys as $categoryKey) {
                if (!is_array($analysis[$categoryKey])) {
                    $fail('Requirements Analysis category must be an array: ' . $categoryKey . '.');
                }
            }
            $canonicalJson = json_encode($analysis, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonicalJson)) {
                $fail('Requirements Analysis could not be encoded for persistence.');
            }
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                $db->BeginTrans();
                $transactionStarted = true;
                $persistedSpecialistProposals = [];
                foreach ($specialistProposals as $proposal) {
                    if ($specialistRegistry->find($proposal['key']) !== null) {
                        $persistedSpecialistProposals[] = ['specialist_key' => $proposal['key'], 'status' => 'already_registered'];
                        continue;
                    }
                    $specialistRegistry->propose(
                        $proposal['key'],
                        $proposal['name'],
                        $proposal['purpose'],
                        $proposal['stages'],
                        $proposal['skills'],
                        $proposal['allowed_tools'],
                        $proposal['write_scope'],
                        $proposal['rag_scopes'],
                        true,
                        ['source' => 'requirements_analysis', 'draft_key' => $draftKey]
                    );
                    $persistedSpecialistProposals[] = ['specialist_key' => $proposal['key'], 'status' => 'pending_approval'];
                }
                $existing = $db->GetRow('SELECT analysis_key FROM phase_builder_requirements_analysis WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $existingAnalysisKey = is_array($existing) ? trim((string) ($existing['analysis_key'] ?? '')) : '';
                $analysisKey = $existingAnalysisKey !== '' ? $existingAnalysisKey : bx_uuid();
                $writeUserKey = $userKey !== '' ? $userKey : null;
                $assertExecute($db->Execute(
                    'INSERT INTO phase_builder_requirements_analysis (analysis_key, draft_key, source_narrative_hash, analysis_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_narrative_hash = VALUES(source_narrative_hash), analysis_json = VALUES(analysis_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                    [$analysisKey, $draftKey, $sourceHash, $canonicalJson, $writeUserKey, $writeUserKey]
                ), 'Requirements Analysis upsert');
                $saved = $db->GetRow('SELECT analysis_key, draft_key, source_narrative_hash, analysis_json, updated_at FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($saved) || (string) ($saved['analysis_key'] ?? '') !== $analysisKey || (string) ($saved['draft_key'] ?? '') !== $draftKey || (string) ($saved['source_narrative_hash'] ?? '') !== $sourceHash || (string) ($saved['analysis_json'] ?? '') !== $canonicalJson) {
                    throw new RuntimeException('Requirements Analysis read-back verification failed.');
                }
                bx_audit($existingAnalysisKey !== '' ? 'UPDATE' : 'CREATE', 'phase_builder_requirements_analysis', $analysisKey, ['draft_key' => $draftKey, 'source_narrative_hash' => $sourceHash]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'Requirements Analysis saved and verified.', 'data' => ['status' => $existingAnalysisKey !== '' ? 'updated' : 'created', 'analysis_key' => $analysisKey, 'draft_key' => $draftKey, 'source_narrative_hash' => $sourceHash, 'requirements_analysis' => $analysis, 'specialist_proposals' => $persistedSpecialistProposals, 'updated_at' => (string) ($saved['updated_at'] ?? '')]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Requirements Analysis could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_system_architecture') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $architectureJson = trim((string) ($_POST['architecture_json'] ?? ''));
            $contextRequirementsHash = strtolower(trim((string) ($_POST['context_requirements_hash'] ?? '')));
            if ($draftKey === '' || $architectureJson === '' || $contextRequirementsHash === '') {
                $fail('A saved BuilderX draft and completed System Architecture response are required.');
            }
            if (strlen($architectureJson) > 10000000) {
                $fail('The System Architecture response is too large to save.');
            }
            $architecture = json_decode($architectureJson, true);
            if (!is_array($architecture) || array_is_list($architecture)) {
                $fail('System Architecture returned an invalid JSON object.');
            }
            $requirementsRow = $db->GetRow('SELECT analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $requirementsJson = is_array($requirementsRow) ? (string) ($requirementsRow['analysis_json'] ?? '') : '';
            $requirements = $requirementsJson !== '' ? json_decode($requirementsJson, true) : null;
            if (!is_array($requirements) || array_is_list($requirements)) {
                $fail('Requirements Analysis is unavailable for System Architecture verification.');
            }
            $requirementsHash = hash('sha256', $requirementsJson);
            if (!hash_equals($requirementsHash, $contextRequirementsHash)) {
                $fail('System Architecture source verification failed. Requirements Analysis changed after the context was prepared.');
            }
            foreach (['schemaVersion', 'contractType', 'source', 'projectBlueprint', 'systemInventory', 'fileManifest', 'implementationChecklist', 'assumptionsOrRisks', 'orchestration'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $architecture)) {
                    $fail('System Architecture is missing the required field: ' . $requiredKey . '.');
                }
            }
            if ($architecture['schemaVersion'] !== 'builderx.system-architecture.v1' || $architecture['contractType'] !== 'builderx.system-architecture') {
                $fail('System Architecture returned an unsupported contract version.');
            }
            if (!is_array($architecture['source']) || ($architecture['source']['draftKey'] ?? '') !== $draftKey) {
                $fail('System Architecture source draft verification failed.');
            }
            if (!is_array($architecture['projectBlueprint']) || !is_array($architecture['systemInventory']) || !is_array($architecture['fileManifest']) || !is_array($architecture['implementationChecklist']) || !is_array($architecture['assumptionsOrRisks']) || !is_array($architecture['orchestration'])) {
                $fail('System Architecture returned an invalid contract section.');
            }
            $architecture['source']['requirementsHash'] = $requirementsHash;
            $canonicalJson = json_encode($architecture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonicalJson)) {
                $fail('System Architecture could not be encoded for persistence.');
            }
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT architecture_key FROM phase_builder_system_architecture WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $existingKey = is_array($existing) ? trim((string) ($existing['architecture_key'] ?? '')) : '';
                $architectureKey = $existingKey !== '' ? $existingKey : bx_uuid();
                $writeUserKey = $userKey !== '' ? $userKey : null;
                $saved = $db->Execute(
                    'INSERT INTO phase_builder_system_architecture (architecture_key, draft_key, source_requirements_hash, architecture_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_requirements_hash = VALUES(source_requirements_hash), architecture_json = VALUES(architecture_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                    [$architectureKey, $draftKey, $requirementsHash, $canonicalJson, $writeUserKey, $writeUserKey]
                );
                if ($saved === false) {
                    throw new RuntimeException('System Architecture upsert failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT architecture_key, draft_key, source_requirements_hash, architecture_json, updated_at FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($readBack) || (string) ($readBack['architecture_key'] ?? '') !== $architectureKey || (string) ($readBack['draft_key'] ?? '') !== $draftKey || (string) ($readBack['source_requirements_hash'] ?? '') !== $requirementsHash || (string) ($readBack['architecture_json'] ?? '') !== $canonicalJson) {
                    throw new RuntimeException('System Architecture read-back verification failed.');
                }
                bx_audit($existingKey !== '' ? 'UPDATE' : 'CREATE', 'phase_builder_system_architecture', $architectureKey, ['draft_key' => $draftKey, 'source_requirements_hash' => $requirementsHash]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'System Architecture saved and verified.', 'data' => ['status' => $existingKey !== '' ? 'updated' : 'created', 'architecture_key' => $architectureKey, 'draft_key' => $draftKey, 'source_requirements_hash' => $requirementsHash, 'system_architecture' => $architecture, 'updated_at' => (string) ($readBack['updated_at'] ?? '')]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('System Architecture could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_ui_ux_design') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $uiUxJson = trim((string) ($_POST['ui_ux_json'] ?? ''));
            $contextArchitectureHash = strtolower(trim((string) ($_POST['context_architecture_hash'] ?? '')));
            if ($draftKey === '' || $uiUxJson === '' || $contextArchitectureHash === '') {
                $fail('A saved BuilderX draft and completed UI/UX Design response are required.');
            }
            if (strlen($uiUxJson) > 10000000) {
                $fail('The UI/UX Design response is too large to save.');
            }
            $uiUx = json_decode($uiUxJson, true);
            if (!is_array($uiUx) || array_is_list($uiUx)) {
                $fail('UI/UX Design returned an invalid JSON object.');
            }
            $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$draftKey]);
            $architectureJson = is_array($architectureRow) ? (string) ($architectureRow['architecture_json'] ?? '') : '';
            $architecture = $architectureJson !== '' ? json_decode($architectureJson, true) : null;
            if (!is_array($architecture) || array_is_list($architecture)) {
                $fail('System Architecture is unavailable for UI/UX Design verification.');
            }
            $architectureHash = hash('sha256', $architectureJson);
            if (!hash_equals($architectureHash, $contextArchitectureHash)) {
                $fail('UI/UX Design source verification failed. System Architecture changed after the context was prepared.');
            }
            foreach (['schemaVersion', 'contractType', 'source', 'designBlueprint', 'screens', 'flowChart', 'responsiveRules', 'accessibilityRules', 'orchestration'] as $requiredKey) {
                if (!array_key_exists($requiredKey, $uiUx)) {
                    $fail('UI/UX Design is missing the required field: ' . $requiredKey . '.');
                }
            }
            if ($uiUx['schemaVersion'] !== 'builderx.ui-ux-design.v1' || $uiUx['contractType'] !== 'builderx.ui-ux-design') {
                $fail('UI/UX Design returned an unsupported contract version.');
            }
            if (!is_array($uiUx['source']) || ($uiUx['source']['draftKey'] ?? '') !== $draftKey || !is_array($uiUx['designBlueprint']) || !is_array($uiUx['orchestration'])) {
                $fail('UI/UX Design returned an invalid source draft, blueprint, or orchestration object.');
            }
            foreach (['screens', 'flowChart', 'responsiveRules', 'accessibilityRules'] as $arrayKey) {
                if (!is_array($uiUx[$arrayKey])) {
                    $fail('UI/UX Design field ' . $arrayKey . ' must be an array.');
                }
            }
            if (count($uiUx['screens']) === 0 || count($uiUx['flowChart']) === 0) {
                $fail('UI/UX Design must include at least one screen and one flow-chart step.');
            }
            foreach ($uiUx['screens'] as $screen) {
                if (!is_array($screen) || trim((string) ($screen['name'] ?? '')) === '' || trim((string) ($screen['purpose'] ?? '')) === '') {
                    $fail('UI/UX Design contains a screen without a name or purpose.');
                }
                if (!is_array($screen['renderSpec'] ?? null) || !is_array($screen['renderSpec']['sections'] ?? null)) {
                    $fail('UI/UX Design contains a screen without a structured renderSpec.');
                }
                foreach ($screen['renderSpec']['sections'] as $section) {
                    if (!is_array($section) || !is_array($section['components'] ?? null)) {
                        $fail('UI/UX Design contains an invalid renderSpec section.');
                    }
                    foreach ($section['components'] as $component) {
                        if (!is_array($component) || trim((string) ($component['type'] ?? '')) === '') {
                            $fail('UI/UX Design contains an invalid renderSpec component.');
                        }
                    }
                }
            }
            foreach ($uiUx['flowChart'] as $flow) {
                if (!is_array($flow) || trim((string) ($flow['from'] ?? '')) === '' || trim((string) ($flow['to'] ?? '')) === '' || trim((string) ($flow['label'] ?? '')) === '') {
                    $fail('UI/UX Design contains an incomplete flow-chart step.');
                }
            }
            $uiUx['source']['architectureHash'] = $architectureHash;
            $canonicalJson = json_encode($uiUx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($canonicalJson)) {
                $fail('UI/UX Design could not be encoded for persistence.');
            }
            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $db->GetRow('SELECT ui_ux_key FROM phase_builder_ui_ux_design WHERE draft_key = ? FOR UPDATE', [$draftKey]);
                $existingKey = is_array($existing) ? trim((string) ($existing['ui_ux_key'] ?? '')) : '';
                $uiUxKey = $existingKey !== '' ? $existingKey : bx_uuid();
                $writeUserKey = $userKey !== '' ? $userKey : null;
                $saved = $db->Execute(
                    'INSERT INTO phase_builder_ui_ux_design (ui_ux_key, draft_key, source_architecture_hash, ui_ux_json, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE source_architecture_hash = VALUES(source_architecture_hash), ui_ux_json = VALUES(ui_ux_json), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                    [$uiUxKey, $draftKey, $architectureHash, $canonicalJson, $writeUserKey, $writeUserKey]
                );
                if ($saved === false) {
                    throw new RuntimeException('UI/UX Design upsert failed: ' . trim((string) $db->ErrorMsg()));
                }
                $readBack = $db->GetRow('SELECT ui_ux_key, draft_key, source_architecture_hash, ui_ux_json, updated_at FROM phase_builder_ui_ux_design WHERE draft_key = ? LIMIT 1', [$draftKey]);
                if (!is_array($readBack) || (string) ($readBack['ui_ux_key'] ?? '') !== $uiUxKey || (string) ($readBack['draft_key'] ?? '') !== $draftKey || (string) ($readBack['source_architecture_hash'] ?? '') !== $architectureHash || (string) ($readBack['ui_ux_json'] ?? '') !== $canonicalJson) {
                    throw new RuntimeException('UI/UX Design read-back verification failed.');
                }
                bx_audit($existingKey !== '' ? 'UPDATE' : 'CREATE', 'phase_builder_ui_ux_design', $uiUxKey, ['draft_key' => $draftKey, 'source_architecture_hash' => $architectureHash]);
                $db->CommitTrans();
                $transactionStarted = false;
                $respondJson(['ok' => true, 'message' => 'UI/UX Design saved and verified.', 'data' => ['status' => $existingKey !== '' ? 'updated' : 'created', 'ui_ux_key' => $uiUxKey, 'draft_key' => $draftKey, 'source_architecture_hash' => $architectureHash, 'ui_ux_design' => $uiUx, 'updated_at' => (string) ($readBack['updated_at'] ?? '')]]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('UI/UX Design could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'save_phase2_narrative_cleanup') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $runKey = trim((string) ($_POST['run_key'] ?? ''));
            $databaseReplyJson = trim((string) ($_POST['database_reply'] ?? ''));
            $sourceSnapshotJson = (string) ($_POST['source_snapshot'] ?? '{}');
            if ($databaseReplyJson === '') {
                $fail('Phase Builder Narrative & Cleanup requires a completed Database Specialist response.');
            }
            if (strlen($databaseReplyJson) > 10000000) {
                $fail('The Phase Builder Narrative & Cleanup database response is too large to save.');
            }
            $reply = json_decode($databaseReplyJson, true);
            $sourceSnapshot = json_decode($sourceSnapshotJson, true);
            if (!is_array($reply)) {
                $fail('The Database Specialist returned an invalid response.');
            }
            if (($reply['database_specialist_approved'] ?? false) !== true || ($reply['status'] ?? '') !== 'approved') {
                $fail('The Database Specialist did not approve the corrected Tab 1 data.');
            }
            if ($draftKey === '' || strcasecmp(trim((string) ($reply['draft_key'] ?? '')), $draftKey) !== 0) {
                $fail('The Database Specialist draft key did not match the current BuilderX draft.');
            }
            if (!is_array($sourceSnapshot)) {
                $fail('The original Tab 1 context could not be verified.');
            }
            try {
                if ($runKey !== '') {
                    $persistentRun = (new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? '')))->read($runKey, $projectIdentity);
                    if (($persistentRun['workflow_key'] ?? '') !== 'narrative_cleanup' || ($persistentRun['draft_key'] ?? '') !== $draftKey) {
                        throw new RuntimeException('The persistent Narrative & Cleanup run does not match the current draft.');
                    }
                    $persistentStages = [];
                    foreach (($persistentRun['stages'] ?? []) as $persistentStage) {
                        if (is_array($persistentStage)) {
                            $persistentStages[(string) ($persistentStage['stage_key'] ?? '')] = $persistentStage;
                        }
                    }
                    $grammarCheckpoint = $persistentStages['grammar']['result'] ?? null;
                    $validationCheckpoint = $persistentStages['validation']['result'] ?? null;
                    if (
                        ($persistentStages['grammar']['status'] ?? '') !== 'SUCCEEDED'
                        || ($persistentStages['validation']['status'] ?? '') !== 'SUCCEEDED'
                        || !is_array($grammarCheckpoint)
                        || !is_array($validationCheckpoint)
                        || json_encode($validationCheckpoint, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !== json_encode($reply, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    ) {
                        throw new RuntimeException('The persistent Narrative & Cleanup validation checkpoint is incomplete or does not match the submitted approval.');
                    }
                    $reply = $validationCheckpoint;
                    $reply['corrected_sections'] = \BuilderX\AI\PhaseBuilderNarrativeCleanupStore::validatePersistedApproval(
                        $draftKey,
                        $reply,
                        $sourceSnapshot,
                        $grammarCheckpoint
                    );
                } else {
                    $reply['corrected_sections'] = \BuilderX\AI\PhaseBuilderNarrativeCleanupStore::validateDatabaseApproval(
                        $draftKey,
                        $reply,
                        $sourceSnapshot
                    );
                }
                $saved = (new \BuilderX\AI\PhaseBuilderNarrativeCleanupStore())->persist(
                    $draftKey,
                    $reply,
                    $sourceSnapshot,
                    (string) ($user['user_key'] ?? '')
                );
                $respondJson([
                    'ok' => true,
                    'message' => $saved['status'] === 'already_saved' ? 'Phase Builder Narrative & Cleanup found the corrected draft already saved.' : 'Phase Builder Narrative & Cleanup corrected the draft and saved it.',
                    'data' => $saved,
                ]);
            } catch (Throwable $error) {
                $fail('Phase Builder Narrative & Cleanup could not save the corrected Tab 1 draft.', $error->getMessage());
            }
        }

        if ($action === 'run_bridge_database_test') {
            $respondJson([
                'ok' => true,
                'report' => bx_run_bridge_database_test(),
            ]);
        }

        if ($action === 'save_phase_builder_narrative_draft') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();

            $requiredDraftPostFields = [
                'phase_builder_product_goal',
                'phase_builder_users_and_roles',
                'phase_builder_main_user_journey',
                'phase_builder_web_requirements',
                'phase_builder_android_requirements',
                'phase_builder_database_and_synchronization',
                'phase_builder_security_and_permissions',
                'phase_builder_validation_and_error_handling',
                'phase_builder_open_questions',
            ];
            foreach ($requiredDraftPostFields as $requiredDraftPostField) {
                if (!array_key_exists($requiredDraftPostField, $_POST)) {
                    $fail('The complete Tab 1 draft was not submitted. Reload the Phase Builder and try again.');
                }
            }

            $draftFields = [
                'product_goal' => trim((string) ($_POST['phase_builder_product_goal'] ?? '')),
                'users_and_roles' => trim((string) ($_POST['phase_builder_users_and_roles'] ?? '')),
                'main_user_journey' => trim((string) ($_POST['phase_builder_main_user_journey'] ?? '')),
                'web_requirements' => trim((string) ($_POST['phase_builder_web_requirements'] ?? '')),
                'android_requirements' => trim((string) ($_POST['phase_builder_android_requirements'] ?? '')),
                'database_and_synchronization' => trim((string) ($_POST['phase_builder_database_and_synchronization'] ?? '')),
                'security_and_permissions' => trim((string) ($_POST['phase_builder_security_and_permissions'] ?? '')),
                'validation_and_error_handling' => trim((string) ($_POST['phase_builder_validation_and_error_handling'] ?? '')),
                'open_questions' => trim((string) ($_POST['phase_builder_open_questions'] ?? '')),
            ];
            foreach ($draftFields as $field => $value) {
                if (strlen($value) > 1000000) {
                    $fail('The Tab 1 draft is too large to save.');
                }
            }

            $userKey = (string) ($user['user_key'] ?? '');
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }

                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };

                $db->BeginTrans();
                $transactionStarted = true;
                $existing = $draftKey !== ''
                    ? $db->GetRow('SELECT draft_key FROM phase_builder_narrative_draft WHERE draft_key = ? FOR UPDATE', [$draftKey])
                    : null;
                $existingDraftKey = is_array($existing) ? trim((string) ($existing['draft_key'] ?? '')) : '';
                $draftKey = $existingDraftKey !== '' ? $existingDraftKey : bx_uuid();
                $writeUserKey = $userKey !== '' ? $userKey : null;

                $assertExecute($db->Execute(
                    'INSERT INTO phase_builder_narrative_draft (draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE product_goal = VALUES(product_goal), users_and_roles = VALUES(users_and_roles), main_user_journey = VALUES(main_user_journey), web_requirements = VALUES(web_requirements), android_requirements = VALUES(android_requirements), database_and_synchronization = VALUES(database_and_synchronization), security_and_permissions = VALUES(security_and_permissions), validation_and_error_handling = VALUES(validation_and_error_handling), open_questions = VALUES(open_questions), updated_by_user_key = VALUES(updated_by_user_key), updated_at = CURRENT_TIMESTAMP',
                    [
                        $draftKey,
                        $draftFields['product_goal'],
                        $draftFields['users_and_roles'],
                        $draftFields['main_user_journey'],
                        $draftFields['web_requirements'],
                        $draftFields['android_requirements'],
                        $draftFields['database_and_synchronization'],
                        $draftFields['security_and_permissions'],
                        $draftFields['validation_and_error_handling'],
                        $draftFields['open_questions'],
                        $writeUserKey,
                        $writeUserKey,
                    ]
                ), 'Tab 1 draft upsert');
                $auditAction = $existingDraftKey !== '' ? 'UPDATE' : 'CREATE';

                $saved = $db->GetRow(
                    'SELECT draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1',
                    [$draftKey]
                );
                if (!is_array($saved)) {
                    throw new RuntimeException('Tab 1 draft read-back returned no row.');
                }
                $savedDraftKey = trim((string) ($saved['draft_key'] ?? ''));
                if (strcasecmp($savedDraftKey, trim($draftKey)) !== 0) {
                    throw new RuntimeException('Tab 1 draft key read-back mismatch (expected ' . $draftKey . ', received ' . $savedDraftKey . ').');
                }
                foreach ($draftFields as $field => $value) {
                    if ((string) ($saved[$field] ?? '') !== $value) {
                        $savedLength = strlen((string) ($saved[$field] ?? ''));
                        throw new RuntimeException('Tab 1 draft field read-back mismatch for ' . $field . ' (submitted ' . strlen($value) . ' characters, saved ' . $savedLength . ').');
                    }
                }

                bx_audit($auditAction, 'phase_builder_narrative_draft', $draftKey, ['draft_key' => $draftKey, 'field_count' => count($draftFields)]);
                $db->CommitTrans();
                $transactionStarted = false;
                bx_flash('Tab 1 draft saved.', 'success');
                $redirect('', 'builder');
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Tab 1 draft could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'save_phase_builder_narrative_cleanup') {
            $draftKey = trim((string) ($_POST['draft_key'] ?? '')) ?: bx_phase_builder_current_draft_key();
            $codexReply = trim((string) ($_POST['codex_reply'] ?? ''));
            $sourceSnapshotJson = (string) ($_POST['source_snapshot'] ?? '{}');
            if ($draftKey === '' || $codexReply === '') {
                $fail('A BuilderX draft and completed Narrative & Cleanup response are required.');
            }
            if (strlen($codexReply) > 10000000) {
                $fail('The Narrative & Cleanup response is too large to save.');
            }

            $reply = json_decode($codexReply, true);
            $sourceSnapshot = json_decode($sourceSnapshotJson, true);
            if (!is_array($reply) || !is_array($reply['corrected_sections'] ?? null)) {
                $fail('Narrative & Cleanup returned an invalid response.');
            }
            if (!is_array($sourceSnapshot)) {
                $fail('The original Tab 1 context could not be verified.');
            }

            try {
                $saved = (new \BuilderX\AI\PhaseBuilderNarrativeCleanupStore())->persist(
                    $draftKey,
                    $reply,
                    $sourceSnapshot,
                    (string) ($user['user_key'] ?? '')
                );
                $respondJson([
                    'ok' => true,
                    'message' => $saved['status'] === 'already_saved' ? 'Narrative & Cleanup was already saved.' : 'Narrative & Cleanup completed and saved.',
                    'data' => $saved,
                ]);
            } catch (Throwable $error) {
                $fail('Narrative & Cleanup could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'export_execution_roadmap_to_phase_manager') {
            $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
            $roadmapJson = trim((string) ($_POST['roadmap_json'] ?? ''));
            $selectedPhaseIdsJson = trim((string) ($_POST['selected_phase_ids'] ?? '[]'));
            if ($phaseKey === '' || $roadmapJson === '') {
                $fail('A saved phase and generated Execution Roadmap are required before export.');
            }
            if (strlen($roadmapJson) > 10000000) {
                $fail('The Execution Roadmap is too large to export.');
            }

            $roadmap = json_decode($roadmapJson, true);
            $selectedPhaseIds = json_decode($selectedPhaseIdsJson, true);
            if (!is_array($roadmap) || array_is_list($roadmap) || !is_array($roadmap['phases'] ?? null)) {
                $fail('The Execution Roadmap is not valid JSON.');
            }
            if (!is_array($selectedPhaseIds)) {
                $fail('The selected roadmap milestones are not valid.');
            }
            $selectedPhaseIds = array_values(array_filter(array_map(static fn ($value): string => trim((string) $value), $selectedPhaseIds), static fn (string $value): bool => $value !== ''));
            if ($selectedPhaseIds === []) {
                $fail('Select at least one roadmap milestone before exporting.');
            }

            $roadmapHash = hash('sha256', (string) json_encode($roadmap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $transactionStarted = false;
            $exportedKeys = [];
            try {
                $exportItems = bx_phase_roadmap_export_items($roadmap, $selectedPhaseIds);
                if ($exportItems === []) {
                    throw new RuntimeException('The selected roadmap milestones contained no exportable tasks.');
                }
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };

                if ($db->BeginTrans() === false) {
                    throw new RuntimeException('The Execution Roadmap export transaction could not start.');
                }
                $transactionStarted = true;
                $phase = $db->GetRow(
                    "SELECT phase_key, phase_code, phase_title FROM builder_phase WHERE phase_key = ? AND phase_status <> 'DELETED' LIMIT 1 FOR UPDATE",
                    [$phaseKey]
                );
                if (!is_array($phase)) {
                    throw new RuntimeException('The selected Phase Manager phase was not found.');
                }

                $existingTasks = $db->GetAll(
                    "SELECT task_key, task_code, task_title, task_details, task_reference, is_completed, task_status FROM builder_phase_task WHERE phase_key = ? AND task_status <> 'DELETED' ORDER BY task_sort_order, x_id",
                    [$phaseKey]
                ) ?: [];
                $existingByFingerprint = [];
                $usedTaskCodes = [];
                foreach ($existingTasks as $existingTask) {
                    $usedTaskCodes[(string) ($existingTask['task_code'] ?? '')] = true;
                    $metadata = json_decode((string) ($existingTask['task_reference'] ?? ''), true);
                    if (is_array($metadata) && ($metadata['source'] ?? '') === 'execution_roadmap') {
                        $fingerprint = trim((string) ($metadata['fingerprint'] ?? ''));
                        if ($fingerprint !== '') {
                            $existingByFingerprint[$fingerprint] = $existingTask;
                        }
                        $identityFingerprint = trim((string) ($metadata['identity_fingerprint'] ?? ''));
                        if ($identityFingerprint === '') {
                            $metadataPhaseId = trim((string) ($metadata['roadmap_phase_id'] ?? ''));
                            $metadataTrack = trim((string) ($metadata['track'] ?? ''));
                            $metadataTaskId = trim((string) ($metadata['roadmap_task_id'] ?? ''));
                            if ($metadataTaskId === '' && $metadataPhaseId !== '' && in_array($metadataTrack, ['web', 'android'], true)) {
                                $metadataTaskId = $metadataPhaseId . '-' . strtoupper($metadataTrack) . '-TASK-' . str_pad((string) ((int) ($metadata['task_index'] ?? 0) + 1), 2, '0', STR_PAD_LEFT);
                            }
                            if ($metadataPhaseId !== '' && $metadataTaskId !== '' && in_array($metadataTrack, ['web', 'android', 'shared'], true)) {
                                $identityFingerprint = hash('sha256', implode('|', ['execution_roadmap_v2', $metadataPhaseId, $metadataTrack, $metadataTaskId]));
                            }
                        }
                        if ($identityFingerprint !== '') {
                            $existingByFingerprint[$identityFingerprint] = $existingTask;
                        }
                    }
                }
                $nextSortOrder = (int) $db->GetOne(
                    "SELECT COALESCE(MAX(task_sort_order), 0) + 1 FROM builder_phase_task WHERE phase_key = ? AND task_status <> 'DELETED'",
                    [$phaseKey]
                );
                $written = [];
                foreach ($exportItems as $exportItem) {
                    $phaseIndex = (int) $exportItem['phase_index'];
                    $roadmapPhaseId = (string) $exportItem['roadmap_phase_id'];
                    $phaseName = (string) $exportItem['phase_name'];
                    $track = (string) $exportItem['track'];
                    $trackLabel = (string) $exportItem['track_label'];
                    $taskIndex = (int) $exportItem['task_index'];
                    $taskId = (string) $exportItem['roadmap_task_id'];
                    $taskText = (string) $exportItem['task_title'];
                    $taskDescription = (string) $exportItem['task_description'];
                    $fingerprint = (string) $exportItem['identity_fingerprint'];
                    $metadata = [
                        'source' => 'execution_roadmap',
                        'source_schema_version' => (string) ($roadmap['schemaVersion'] ?? ''),
                        'roadmap_hash' => $roadmapHash,
                        'roadmap_phase_id' => $roadmapPhaseId,
                        'roadmap_phase_name' => $phaseName,
                        'track' => $track,
                        'task_index' => $taskIndex,
                        'roadmap_task_id' => $taskId,
                        'subtask_count' => (int) $exportItem['subtask_count'],
                        'todo_count' => (int) $exportItem['todo_count'],
                        'identity_fingerprint' => $fingerprint,
                        'fingerprint' => $fingerprint,
                        'fingerprint_version' => 2,
                        'roadmap_task' => $exportItem['roadmap_task'],
                    ];
                    $taskTitle = sprintf('[%s] %s: %s', $trackLabel, $phaseName, $taskText);
                    $taskTitle = strlen($taskTitle) > 255 ? substr($taskTitle, 0, 252) . '...' : $taskTitle;
                    $taskDetails = implode("\n", array_filter([
                        'Execution Roadmap milestone: ' . $phaseName,
                        'Track: ' . $trackLabel,
                        'Task: ' . $taskText,
                        $taskDescription !== '' && $taskDescription !== $taskText ? 'Description: ' . $taskDescription : '',
                        'Sub-tasks: ' . (int) $exportItem['subtask_count'],
                        'Todos: ' . (int) $exportItem['todo_count'],
                        'Source hash: ' . $roadmapHash,
                    ], static fn (string $line): bool => $line !== ''));
                    $taskReference = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $existing = $existingByFingerprint[$fingerprint] ?? null;
                    if (is_array($existing)) {
                        $taskKey = trim((string) ($existing['task_key'] ?? ''));
                        $assertExecute($db->Execute(
                            'UPDATE builder_phase_task SET task_title = ?, task_details = ?, task_reference = ?, updated_at = CURRENT_TIMESTAMP WHERE task_key = ? AND phase_key = ?',
                            [$taskTitle, $taskDetails, $taskReference, $taskKey, $phaseKey]
                        ), 'Execution Roadmap task update');
                        $operation = 'updated';
                    } else {
                        $taskKey = bx_uuid();
                        $phaseCode = preg_replace('/[^A-Za-z0-9]/', '', (string) ($phase['phase_code'] ?? 'P')) ?: 'P';
                        $trackCode = $track === 'web' ? 'W' : ($track === 'android' ? 'M' : 'S');
                        $taskCode = 'BX' . substr(hash('sha1', $phaseKey), 0, 5) . '-R' . str_pad((string) ($phaseIndex + 1), 2, '0', STR_PAD_LEFT) . $trackCode . str_pad((string) ($taskIndex + 1), 2, '0', STR_PAD_LEFT);
                        $taskCode = substr($phaseCode, 0, 4) . '-' . $taskCode;
                        $taskCode = substr($taskCode, 0, 30);
                        if (isset($usedTaskCodes[$taskCode])) {
                            $taskCode = substr($taskCode, 0, 23) . '-' . substr($fingerprint, 0, 6);
                        }
                        $usedTaskCodes[$taskCode] = true;
                        $assertExecute($db->Execute(
                            'INSERT INTO builder_phase_task (task_key, phase_key, task_code, task_title, task_details, task_reference, is_completed, task_status, task_sort_order) VALUES (?, ?, ?, ?, ?, ?, 0, \'ACTIVE\', ?)',
                            [$taskKey, $phaseKey, $taskCode, $taskTitle, $taskDetails, $taskReference, $nextSortOrder]
                        ), 'Execution Roadmap task insert');
                        $nextSortOrder++;
                        $operation = 'created';
                    }
                    $exportedKeys[] = $taskKey;
                    $written[] = ['task_key' => $taskKey, 'track' => $track, 'milestone_id' => $roadmapPhaseId, 'roadmap_task_id' => $taskId, 'identity_fingerprint' => $fingerprint, 'operation' => $operation];
                }
                $placeholders = implode(', ', array_fill(0, count($exportedKeys), '?'));
                $readBack = $db->GetAll(
                    "SELECT task_key, phase_key, task_code, task_title, task_details, task_reference, is_completed, task_status FROM builder_phase_task WHERE phase_key = ? AND task_key IN ({$placeholders}) ORDER BY task_sort_order, x_id",
                    array_merge([$phaseKey], $exportedKeys)
                );
                if (!is_array($readBack) || count($readBack) !== count($exportedKeys)) {
                    throw new RuntimeException('Execution Roadmap Phase Manager read-back did not verify every exported task.');
                }
                // Checked direct pre-commit read-back of each stable roadmap task identity.
                $expectedFingerprints = array_fill_keys(array_column($written, 'identity_fingerprint'), true);
                $preCommitSignatures = [];
                foreach ($readBack as $savedTask) {
                    $savedMetadata = json_decode((string) ($savedTask['task_reference'] ?? ''), true);
                    $savedFingerprint = is_array($savedMetadata) ? trim((string) ($savedMetadata['identity_fingerprint'] ?? '')) : '';
                    if ($savedFingerprint === '' || !isset($expectedFingerprints[$savedFingerprint])) {
                        throw new RuntimeException('Execution Roadmap Phase Manager read-back returned mismatched source identity metadata.');
                    }
                    $preCommitSignatures[(string) $savedTask['task_key']] = hash('sha256', json_encode($savedTask, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                }
                bx_audit('EXPORT', 'builder_phase_task', $phaseKey, [
                    'source' => 'execution_roadmap',
                    'roadmap_hash' => $roadmapHash,
                    'task_count' => count($readBack),
                ]);
                if ($db->CommitTrans() === false) {
                    throw new RuntimeException('The Execution Roadmap export transaction could not commit.');
                }
                $transactionStarted = false;
                $durableReadBack = $db->GetAll(
                    "SELECT task_key, phase_key, task_code, task_title, task_details, task_reference, is_completed, task_status FROM builder_phase_task WHERE phase_key = ? AND task_key IN ({$placeholders}) ORDER BY task_sort_order, x_id",
                    array_merge([$phaseKey], $exportedKeys)
                );
                if (!is_array($durableReadBack) || count($durableReadBack) !== count($preCommitSignatures)) {
                    throw new RuntimeException('Execution Roadmap durable post-commit read-back did not verify every exported task.');
                }
                foreach ($durableReadBack as $savedTask) {
                    $taskKey = (string) ($savedTask['task_key'] ?? '');
                    $signature = hash('sha256', json_encode($savedTask, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    if ($taskKey === '' || !isset($preCommitSignatures[$taskKey]) || !hash_equals($preCommitSignatures[$taskKey], $signature)) {
                        throw new RuntimeException('Execution Roadmap durable post-commit read-back changed after commit.');
                    }
                }
                $respondJson([
                    'ok' => true,
                    'message' => 'Execution Roadmap exported to Phase Manager and verified by read-back.',
                    'data' => [
                        'phase' => ['phase_key' => $phaseKey, 'phase_code' => (string) ($phase['phase_code'] ?? ''), 'phase_title' => (string) ($phase['phase_title'] ?? '')],
                        'roadmap_hash' => $roadmapHash,
                        'created_count' => count(array_filter($written, static fn (array $item): bool => $item['operation'] === 'created')),
                        'updated_count' => count(array_filter($written, static fn (array $item): bool => $item['operation'] === 'updated')),
                        'tasks' => $durableReadBack,
                    ],
                ]);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Execution Roadmap could not be exported.', $error->getMessage());
            }
        }

        if ($action === 'create_phase') {
            $title = trim((string) ($_POST['phase_title'] ?? ''));
            $summary = trim((string) ($_POST['phase_summary'] ?? ''));
            $status = trim((string) ($_POST['phase_status'] ?? 'Not Started'));
            $allowedStatuses = ['Not Started', 'In Progress', 'For Review', 'Completed', 'Blocked'];
            if ($title === '' || strlen($title) > 150) {
                $fail('Phase title is required and must be 150 characters or fewer.');
            }
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'Not Started';
            }
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                $db->BeginTrans();
                $transactionStarted = true;
                $nextNumber = (int) $db->GetOne("SELECT COALESCE(MAX(phase_number), 0) + 1 FROM builder_phase");
                $phaseKey = bx_uuid();
                $storedSummary = $summary !== '' ? $summary : null;
                $assertExecute($db->Execute(
                    'INSERT INTO builder_phase (phase_key, phase_number, phase_code, phase_title, phase_summary, phase_status, phase_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$phaseKey, $nextNumber, 'P' . $nextNumber, $title, $storedSummary, $status, $nextNumber]
                ), 'Phase create');
                $readBack = $db->GetRow(
                    'SELECT phase_key, phase_number, phase_code, phase_title, phase_summary, phase_status, phase_sort_order FROM builder_phase WHERE phase_key = ? LIMIT 1',
                    [$phaseKey]
                );
                if (!is_array($readBack)
                    || (string) ($readBack['phase_key'] ?? '') !== $phaseKey
                    || (int) ($readBack['phase_number'] ?? 0) !== $nextNumber
                    || (string) ($readBack['phase_code'] ?? '') !== 'P' . $nextNumber
                    || (string) ($readBack['phase_title'] ?? '') !== $title
                    || (string) ($readBack['phase_summary'] ?? '') !== ($storedSummary ?? '')
                    || (string) ($readBack['phase_status'] ?? '') !== $status
                    || (int) ($readBack['phase_sort_order'] ?? 0) !== $nextNumber
                ) {
                    throw new RuntimeException('Phase create read-back verification failed.');
                }
                bx_audit('CREATE', 'builder_phase', $phaseKey, ['phase_title' => $title, 'phase_status' => $status]);
                $db->CommitTrans();
                $transactionStarted = false;
                bx_flash('Phase created and verified.', 'success');
                $redirect($phaseKey);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Phase could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'update_phase') {
            $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
            $title = trim((string) ($_POST['phase_title'] ?? ''));
            $summary = trim((string) ($_POST['phase_summary'] ?? ''));
            $status = trim((string) ($_POST['phase_status'] ?? 'Not Started'));
            $allowedStatuses = ['Not Started', 'In Progress', 'For Review', 'Completed', 'Blocked'];
            if ($phaseKey === '' || $title === '' || strlen($title) > 150) {
                $fail('A valid phase and phase title are required.');
            }
            if (!in_array($status, $allowedStatuses, true)) {
                $fail('Choose a valid phase status.');
            }
            $storedSummary = $summary !== '' ? $summary : null;
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                if ($db->BeginTrans() === false) {
                    throw new RuntimeException('Phase update transaction could not start.');
                }
                $transactionStarted = true;
                $existingPhase = $db->GetRow(
                    "SELECT phase_key FROM builder_phase WHERE phase_key = ? AND phase_status <> 'DELETED' LIMIT 1 FOR UPDATE",
                    [$phaseKey]
                );
                if (!is_array($existingPhase) || (string) ($existingPhase['phase_key'] ?? '') !== $phaseKey) {
                    throw new RuntimeException('The phase no longer exists or is unavailable for update.');
                }
                $assertExecute($db->Execute(
                    'UPDATE builder_phase SET phase_title = ?, phase_summary = ?, phase_status = ?, updated_at = CURRENT_TIMESTAMP WHERE phase_key = ?',
                    [$title, $storedSummary, $status, $phaseKey]
                ), 'Phase update');
                bx_audit('UPDATE', 'builder_phase', $phaseKey, [
                    'phase_title' => $title,
                    'phase_status' => $status,
                    'phase_summary_sha256' => hash('sha256', $summary),
                ]);
                $readBack = $db->GetRow(
                    'SELECT phase_key, phase_title, phase_summary, phase_status FROM builder_phase WHERE phase_key = ? LIMIT 1',
                    [$phaseKey]
                );
                if (
                    !is_array($readBack)
                    || (string) ($readBack['phase_key'] ?? '') !== $phaseKey
                    || (string) ($readBack['phase_title'] ?? '') !== $title
                    || (($readBack['phase_summary'] ?? null) !== $storedSummary)
                    || (string) ($readBack['phase_status'] ?? '') !== $status
                ) {
                    throw new RuntimeException('Phase update direct read-back did not match the submitted values.');
                }
                if ($db->CommitTrans() === false) {
                    throw new RuntimeException('Phase update transaction could not commit.');
                }
                $transactionStarted = false;
                bx_flash('Phase updated and verified.', 'success');
                $redirect($phaseKey);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Phase could not be updated.', $error->getMessage());
            }
        }

        if ($action === 'delete_phase') {
            $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
            if ($phaseKey === '') {
                $fail('A valid phase is required.');
            }
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                $db->BeginTrans();
                $transactionStarted = true;
                $phase = $db->GetRow(
                    "SELECT phase_key, phase_title, phase_status FROM builder_phase WHERE phase_key = ? LIMIT 1 FOR UPDATE",
                    [$phaseKey]
                );
                if (!is_array($phase) || (string) ($phase['phase_status'] ?? '') === 'DELETED') {
                    throw new RuntimeException('The selected phase was not found.');
                }
                $assertExecute($db->Execute(
                    "UPDATE builder_phase_task SET task_status = 'DELETED', updated_at = CURRENT_TIMESTAMP WHERE phase_key = ? AND task_status <> 'DELETED'",
                    [$phaseKey]
                ), 'Phase task cascade delete');
                $remainingTasks = (int) $db->GetOne(
                    "SELECT COUNT(*) FROM builder_phase_task WHERE phase_key = ? AND task_status <> 'DELETED'",
                    [$phaseKey]
                );
                if ($remainingTasks !== 0) {
                    throw new RuntimeException('Phase task cascade delete read-back verification failed.');
                }
                $deletedTaskCount = (int) $db->GetOne(
                    "SELECT COUNT(*) FROM builder_phase_task WHERE phase_key = ? AND task_status = 'DELETED'",
                    [$phaseKey]
                );
                $assertExecute($db->Execute(
                    "UPDATE builder_phase SET phase_status = 'DELETED', updated_at = CURRENT_TIMESTAMP WHERE phase_key = ? AND phase_status <> 'DELETED'",
                    [$phaseKey]
                ), 'Phase delete');
                $phaseReadBack = $db->GetRow(
                    'SELECT phase_key, phase_status FROM builder_phase WHERE phase_key = ? LIMIT 1',
                    [$phaseKey]
                );
                if (!is_array($phaseReadBack)
                    || (string) ($phaseReadBack['phase_key'] ?? '') !== $phaseKey
                    || (string) ($phaseReadBack['phase_status'] ?? '') !== 'DELETED'
                ) {
                    throw new RuntimeException('Phase delete read-back verification failed.');
                }
                bx_audit('DELETE', 'builder_phase', $phaseKey, [
                    'phase_title' => (string) ($phase['phase_title'] ?? ''),
                    'deleted_task_count' => $deletedTaskCount,
                ]);
                $db->CommitTrans();
                $transactionStarted = false;
                bx_flash('Phase and associated tasks deleted and verified.', 'success');
                $redirect('');
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Phase could not be deleted.', $error->getMessage());
            }
        }

        if ($action === 'create_task') {
            $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
            $title = trim((string) ($_POST['task_title'] ?? ''));
            $details = trim((string) ($_POST['task_details'] ?? ''));
            if ($phaseKey === '' || $title === '' || strlen($title) > 255) {
                $fail('A phase and task title are required.');
            }
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                $db->BeginTrans();
                $transactionStarted = true;
                $phase = $db->GetRow(
                    "SELECT phase_key, phase_code FROM builder_phase WHERE phase_key = ? AND phase_status <> 'DELETED' LIMIT 1 FOR UPDATE",
                    [$phaseKey]
                );
                if (!is_array($phase)) {
                    throw new RuntimeException('The selected phase was not found.');
                }
                $taskNumber = (int) $db->GetOne(
                    "SELECT COALESCE(MAX(task_sort_order), 0) + 1 FROM builder_phase_task WHERE phase_key = ? AND task_status <> 'DELETED'",
                    [$phaseKey]
                );
                $taskKey = bx_uuid();
                $taskCode = trim((string) ($phase['phase_code'] ?? 'P')) . '-T' . $taskNumber;
                $storedDetails = $details !== '' ? $details : null;
                $assertExecute($db->Execute(
                    'INSERT INTO builder_phase_task (task_key, phase_key, task_code, task_title, task_details, is_completed, task_status, task_sort_order) VALUES (?, ?, ?, ?, ?, 0, \'ACTIVE\', ?)',
                    [$taskKey, $phaseKey, $taskCode, $title, $storedDetails, $taskNumber]
                ), 'Task create');
                $readBack = $db->GetRow(
                    "SELECT task_key, phase_key, task_code, task_title, task_details, is_completed, task_status, task_sort_order FROM builder_phase_task WHERE task_key = ? AND phase_key = ? LIMIT 1",
                    [$taskKey, $phaseKey]
                );
                if (!is_array($readBack)
                    || (string) ($readBack['task_key'] ?? '') !== $taskKey
                    || (string) ($readBack['phase_key'] ?? '') !== $phaseKey
                    || (string) ($readBack['task_code'] ?? '') !== $taskCode
                    || (string) ($readBack['task_title'] ?? '') !== $title
                    || (string) ($readBack['task_details'] ?? '') !== ($storedDetails ?? '')
                    || (int) ($readBack['is_completed'] ?? 1) !== 0
                    || (string) ($readBack['task_status'] ?? '') !== 'ACTIVE'
                    || (int) ($readBack['task_sort_order'] ?? 0) !== $taskNumber
                ) {
                    throw new RuntimeException('Task create read-back verification failed.');
                }
                bx_audit('CREATE', 'builder_phase_task', $taskKey, ['phase_key' => $phaseKey, 'task_title' => $title]);
                $db->CommitTrans();
                $transactionStarted = false;
                bx_flash('Task created and verified.', 'success');
                $redirect($phaseKey);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail('Task could not be saved.', $error->getMessage());
            }
        }

        if ($action === 'update_task') {
            $phaseKey = trim((string) ($_POST['phase_key'] ?? ''));
            $taskKey = trim((string) ($_POST['task_key'] ?? ''));
            $title = trim((string) ($_POST['task_title'] ?? ''));
            $details = trim((string) ($_POST['task_details'] ?? ''));
            $status = trim((string) ($_POST['task_status'] ?? 'ACTIVE'));
            if ($phaseKey === '' || $taskKey === '' || $title === '' || strlen($title) > 255) {
                $fail('A valid task and task title are required.');
            }
            if (!in_array($status, ['ACTIVE', 'DELETED'], true)) {
                $fail('Choose a valid task status.');
            }
            $completed = isset($_POST['is_completed']) ? 1 : 0;
            $transactionStarted = false;
            try {
                $assertExecute = static function ($result, string $operation) use ($db): void {
                    if ($result !== false) {
                        return;
                    }
                    $databaseError = trim((string) $db->ErrorMsg());
                    throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
                };
                $db->BeginTrans();
                $transactionStarted = true;
                $existingTask = $db->GetRow(
                    "SELECT task_key, phase_key FROM builder_phase_task WHERE task_key = ? AND phase_key = ? LIMIT 1 FOR UPDATE",
                    [$taskKey, $phaseKey]
                );
                if (!is_array($existingTask)) {
                    throw new RuntimeException('The selected task was not found.');
                }
                $storedDetails = $details !== '' ? $details : null;
                $assertExecute($db->Execute(
                    'UPDATE builder_phase_task SET task_title = ?, task_details = ?, is_completed = ?, task_status = ?, updated_at = CURRENT_TIMESTAMP WHERE task_key = ? AND phase_key = ?',
                    [$title, $storedDetails, $completed, $status, $taskKey, $phaseKey]
                ), 'Task update');
                $readBack = $db->GetRow(
                    'SELECT task_key, phase_key, task_title, task_details, is_completed, task_status FROM builder_phase_task WHERE task_key = ? AND phase_key = ? LIMIT 1',
                    [$taskKey, $phaseKey]
                );
                if (!is_array($readBack)
                    || (string) ($readBack['task_key'] ?? '') !== $taskKey
                    || (string) ($readBack['phase_key'] ?? '') !== $phaseKey
                    || (string) ($readBack['task_title'] ?? '') !== $title
                    || (string) ($readBack['task_details'] ?? '') !== ($storedDetails ?? '')
                    || (int) ($readBack['is_completed'] ?? -1) !== $completed
                    || (string) ($readBack['task_status'] ?? '') !== $status
                ) {
                    throw new RuntimeException('Task update read-back verification failed.');
                }
                bx_audit('UPDATE', 'builder_phase_task', $taskKey, ['task_title' => $title, 'is_completed' => $completed, 'task_status' => $status]);
                $db->CommitTrans();
                $transactionStarted = false;
                bx_flash($status === 'DELETED' ? 'Task deleted and verified.' : 'Task updated and verified.', 'success');
                $redirect($phaseKey);
            } catch (Throwable $error) {
                if ($transactionStarted) {
                    $db->RollbackTrans();
                }
                $fail($status === 'DELETED' ? 'Task could not be deleted.' : 'Task could not be updated.', $error->getMessage());
            }
        }

        $fail('Unknown phase manager action.');
    } catch (Throwable $error) {
        if ($action === 'verify_phase_runtime_permissions') $respondJson(['ok' => false, 'message' => $error->getMessage()], 422);
        $fail('The change could not be saved.', $error->getMessage());
    }
}

$selectedKey = trim((string) ($_GET['phase'] ?? ''));
$phases = [];
$selectedPhase = null;

if ($isAdmin) {
    $phases = $db->GetAll("SELECT phase_key, phase_number, phase_code, phase_title, phase_summary, phase_status, phase_sort_order FROM builder_phase WHERE phase_status <> 'DELETED' ORDER BY phase_sort_order, x_id") ?: [];
    if ($selectedKey !== '' && !array_filter($phases, static fn (array $phase): bool => (string) $phase['phase_key'] === $selectedKey)) {
        $selectedKey = '';
    }
    if ($selectedKey === '') {
        $selectedKey = (string) ($phases[0]['phase_key'] ?? '');
    }
    foreach ($phases as $phase) {
        if ((string) $phase['phase_key'] === $selectedKey) {
            $selectedPhase = $phase;
            break;
        }
    }
}
$tasks = $isAdmin && $selectedKey !== ''
    ? ($db->GetAll("SELECT task_key, phase_key, task_code, task_title, task_details, is_completed, task_status FROM builder_phase_task WHERE phase_key = ? AND task_status <> 'DELETED' ORDER BY task_sort_order, x_id", [$selectedKey]) ?: [])
    : [];
$phaseBuilderDraftKey = $isAdmin ? bx_phase_builder_current_draft_key() : '';
$phaseBuilderNarrativeDraft = $isAdmin && $phaseBuilderDraftKey !== ''
    ? ($db->GetRow('SELECT draft_key, product_goal, users_and_roles, main_user_journey, web_requirements, android_requirements, database_and_synchronization, security_and_permissions, validation_and_error_handling, open_questions, updated_at FROM phase_builder_narrative_draft WHERE draft_key = ? LIMIT 1', [$phaseBuilderDraftKey]) ?: null)
    : null;
$phaseBuilderRequirementsAnalysis = null;
if ($isAdmin && $phaseBuilderDraftKey !== '') {
    $requirementsRow = $db->GetRow('SELECT analysis_json FROM phase_builder_requirements_analysis WHERE draft_key = ? LIMIT 1', [$phaseBuilderDraftKey]);
    $decodedRequirements = is_array($requirementsRow) ? json_decode((string) ($requirementsRow['analysis_json'] ?? ''), true) : null;
    if (is_array($decodedRequirements) && !array_is_list($decodedRequirements)) {
        $phaseBuilderRequirementsAnalysis = $decodedRequirements;
    }
}
$phaseBuilderSystemArchitecture = null;
if ($isAdmin && $phaseBuilderDraftKey !== '') {
    $architectureRow = $db->GetRow('SELECT architecture_json FROM phase_builder_system_architecture WHERE draft_key = ? LIMIT 1', [$phaseBuilderDraftKey]);
    $decodedArchitecture = is_array($architectureRow) ? json_decode((string) ($architectureRow['architecture_json'] ?? ''), true) : null;
    if (is_array($decodedArchitecture) && !array_is_list($decodedArchitecture)) {
        $phaseBuilderSystemArchitecture = $decodedArchitecture;
    }
}
$phaseBuilderUiUxDesign = null;
if ($isAdmin && $phaseBuilderDraftKey !== '') {
    $uiUxRow = $db->GetRow('SELECT ui_ux_json FROM phase_builder_ui_ux_design WHERE draft_key = ? LIMIT 1', [$phaseBuilderDraftKey]);
    $decodedUiUx = is_array($uiUxRow) ? json_decode((string) ($uiUxRow['ui_ux_json'] ?? ''), true) : null;
    if (is_array($decodedUiUx) && !array_is_list($decodedUiUx)) {
        $phaseBuilderUiUxDesign = $decodedUiUx;
    }
}
$phaseBuilderExecutionRoadmap = null;
$phaseBuilderExecutionRoadmapProgress = [];
$phaseBuilderExecutionRoadmapStages = [];
$phaseBuilderLatestAiRun = null;
$phaseBuilderLatestRequirementsAiRun = null;
if ($isAdmin && $phaseBuilderDraftKey !== '') {
    $roadmapRow = $db->GetRow('SELECT roadmap_json, progress_json, stages_json FROM phase_builder_execution_roadmap WHERE draft_key = ? LIMIT 1', [$phaseBuilderDraftKey]);
    $decodedRoadmap = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['roadmap_json'] ?? ''), true) : null;
    $decodedRoadmapProgress = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['progress_json'] ?? '{}'), true) : null;
    if (is_array($decodedRoadmap) && !array_is_list($decodedRoadmap)) {
        $phaseBuilderExecutionRoadmap = $decodedRoadmap;
    }
    if (is_array($decodedRoadmapProgress) && !array_is_list($decodedRoadmapProgress)) {
        $phaseBuilderExecutionRoadmapProgress = $decodedRoadmapProgress;
    }
    $decodedRoadmapStages = is_array($roadmapRow) ? json_decode((string) ($roadmapRow['stages_json'] ?? '{}'), true) : null;
    if (is_array($decodedRoadmapStages) && !array_is_list($decodedRoadmapStages)) {
        $phaseBuilderExecutionRoadmapStages = $decodedRoadmapStages;
    }
    try {
        $phaseAiRunStore = new \BuilderX\AI\PhaseAiRunStore((string) ($user['user_key'] ?? ''));
        $phaseBuilderLatestAiRun = $phaseAiRunStore->latest('narrative_cleanup', $phaseBuilderDraftKey, $projectIdentity);
        $phaseBuilderLatestRequirementsAiRun = $phaseAiRunStore->latest('requirements_analysis', $phaseBuilderDraftKey, $projectIdentity);
    } catch (Throwable) {
        $phaseBuilderLatestAiRun = null;
        $phaseBuilderLatestRequirementsAiRun = null;
    }
}
$flash = $flash ?: bx_take_flash();

$projectBasePath = rtrim(dirname(dirname(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/phases/index.php')))), '/');
$projectBasePath = ($projectBasePath === '' ? '' : $projectBasePath) . '/';
$manifestPath = dirname(__DIR__) . '/frontend/dist/.vite/manifest.json';
$manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
$assetsBase = '../frontend/dist/';
$payload = [
    'csrf' => bx_csrf_token(),
    'softwareName' => bx_setting('software_name', 'BuilderX'),
    'projectBasePath' => $projectBasePath,
    'projectRoot' => $projectRoot,
    'isAdmin' => $isAdmin,
    'isAuthenticated' => $user !== null,
    'requiresLogin' => !$isAdmin,
    'user' => $user ? [
        'user_name' => (string) ($user['user_name'] ?? 'Administrator'),
        'user_email' => (string) ($user['user_email'] ?? 'Administrator'),
    ] : null,
    'activeView' => 'phases',
    'target' => $target,
    'selectedPhaseKey' => $selectedKey,
    'selectedPhase' => $selectedPhase,
    'phases' => $phases,
    'tasks' => $tasks,
    'phaseBuilderDraftKey' => $phaseBuilderDraftKey,
    'phaseBuilderNarrativeDraft' => $phaseBuilderNarrativeDraft,
    'phaseBuilderRequirementsAnalysis' => $phaseBuilderRequirementsAnalysis,
    'phaseBuilderSystemArchitecture' => $phaseBuilderSystemArchitecture,
    'phaseBuilderUiUxDesign' => $phaseBuilderUiUxDesign,
    'phaseBuilderExecutionRoadmap' => $phaseBuilderExecutionRoadmap,
    'phaseBuilderExecutionRoadmapProgress' => $phaseBuilderExecutionRoadmapProgress,
    'phaseBuilderExecutionRoadmapStages' => $phaseBuilderExecutionRoadmapStages,
    'phaseBuilderLatestAiRun' => $phaseBuilderLatestAiRun,
    'phaseBuilderLatestRequirementsAiRun' => $phaseBuilderLatestRequirementsAiRun,
    'flash' => $flash,
];
$payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bx_h((string) ($payload['softwareName'] ?? 'BuilderX')) ?> <?= $target === 'builder' ? 'Phase Builder' : 'Phase Manager' ?></title>
    <?php if (is_array($entry) && !empty($entry['css'])): foreach ($entry['css'] as $css): ?>
        <link rel="stylesheet" href="<?= bx_h($assetsBase . $css) ?>">
    <?php endforeach; endif; ?>
</head>
<body>
<div id="root"><main class="min-h-screen bg-background p-6 text-foreground"><div class="mx-auto max-w-3xl rounded-lg border p-6"><h1 class="text-lg font-semibold">Loading Phase Manager…</h1></div></main></div>
<script>window.__BUILDERX_PHASES__ = <?= $payloadJson ?>;</script>
<?php if (is_array($entry) && !empty($entry['file'])): ?>
    <script type="module" src="<?= bx_h($assetsBase . $entry['file']) ?>"></script>
<?php endif; ?>
</body>
</html>
