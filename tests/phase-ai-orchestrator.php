<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\BuilderXAiBridgeAdapter;
use BuilderX\AI\BuilderXAiBridgeTransport;
use BuilderX\AI\PhaseAiBridgeException;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiJobStore;
use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiRunStore;

final class FakeMysqlCompanionTransport implements BuilderXAiBridgeTransport
{
    /** @var list<array{method: string, path: string, payload: array<string, mixed>|null}> */
    public array $calls = [];
    public bool $ready = true;
    public string $version = '2.0.5';

    public function __construct(public readonly string $workspace)
    {
    }

    public function request(string $method, string $path, ?array $payload = null, int $timeoutSeconds = 30): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
        if (str_starts_with($path, '/health?')) {
            return [
                'ok' => true,
                'bridge' => 'BuilderX',
                'version' => $this->version,
                'companion_extension_version' => $this->version,
                'workspace' => $this->workspace,
                'extension_version_ready' => true,
                'ready_to_send' => $this->ready,
                'active_thread_ready' => $this->ready,
                'active_thread_busy' => false,
                'active_thread_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                'model_key' => 'codex-test-model',
            ];
        }
        if ($path === '/capabilities') {
            return ['ok' => true, 'bridge' => 'BuilderX', 'version' => '2.0.0', 'transport' => 'mysql', 'parallel_execution' => ['supported' => false, 'task_channels' => 1]];
        }
        if ($path === '/handoff-result') {
            if (!$this->ready) {
                throw new PhaseAiBridgeException('CODEX_CHAT_NOT_READY', 'An active Codex AI Chat for the current project is required.');
            }
            $jobKey = (string) ($payload['job_key'] ?? '');
            return [
                'ok' => true,
                'bridge' => 'BuilderX',
                'model_key' => 'codex-test-model',
                'delivery' => [
                    'request_id' => $jobKey,
                    'acknowledged' => true,
                    'state' => 'submitted',
                    'thread_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
                    'storage' => 'mysql',
                ],
            ];
        }
        throw new RuntimeException('Unexpected fake companion request: ' . $path);
    }

    public function stream(string $path, callable $onChunk, int $timeoutSeconds = 3600): void
    {
        throw new RuntimeException('MySQL progress is read directly and does not use companion HTTP streaming.');
    }
}

$db = bx_db();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    throw new RuntimeException('The test project root could not be resolved.');
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$testUserKey = bx_uuid();
$runStore = new PhaseAiRunStore($testUserKey);
$jobStore = new PhaseAiJobStore($projectRoot, $testUserKey);
$contextStore = new PhaseAiContextStore($testUserKey);
$transport = new FakeMysqlCompanionTransport($projectRoot);
$adapter = new BuilderXAiBridgeAdapter($projectRoot, $transport);
$orchestrator = new PhaseAiOrchestrator($projectRoot, $runStore, $adapter);
$runKeys = [];
$contextKeys = [];

$source = [
    'product_goal' => 'Build a verified MySQL lifecycle.',
    'users_and_roles' => '',
    'main_user_journey' => '',
    'web_requirements' => '',
    'android_requirements' => '',
    'database_and_synchronization' => '',
    'security_and_permissions' => '',
    'validation_and_error_handling' => '',
    'open_questions' => '',
];

try {
    $health = $adapter->health(true);
    $capabilities = $adapter->capabilities();
    if (($health['workspace'] ?? '') !== $projectRoot || ($capabilities['transport'] ?? '') !== 'mysql') {
        throw new RuntimeException('The companion readiness or MySQL capability validation failed.');
    }

    $staleTransport = new FakeMysqlCompanionTransport($projectRoot);
    $staleTransport->version = '2.0.4';
    $staleAdapter = new BuilderXAiBridgeAdapter($projectRoot, $staleTransport);
    $staleHealth = $staleAdapter->health(false);
    $staleRejected = ($staleHealth['ready_to_send'] ?? true) === false
        && ($staleHealth['extension_version_ready'] ?? true) === false
        && str_contains((string) ($staleHealth['extension_probe_message'] ?? ''), '2.0.5');
    try {
        $staleAdapter->health(true);
    } catch (PhaseAiBridgeException $expected) {
        $staleRejected = $staleRejected && $expected->errorCode() === 'BRIDGE_UNAVAILABLE';
    }
    if (!$staleRejected) {
        throw new RuntimeException('The adapter accepted a stale BuilderX companion version.');
    }

    $draftKey = bx_uuid();
    $request = [
        'schema_version' => 'builderx.ai-run.request.v1',
        'route_key' => 'phases:builder',
        'workflow_key' => 'narrative_cleanup',
        'draft_key' => $draftKey,
        'phase_key' => null,
        'source_snapshot' => $source,
    ];
    $run = $runStore->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), $request, $testUserKey);
    $runKey = (string) $run['run_key'];
    $runKeys[] = $runKey;

    $outOfOrderRejected = false;
    try {
        $orchestrator->begin($runKey, $projectIdentity, 'routing', ['invalid' => true]);
    } catch (RuntimeException $expected) {
        $outOfOrderRejected = str_contains($expected->getMessage(), 'next deterministic');
    }
    if (!$outOfOrderRejected) {
        throw new RuntimeException('The orchestrator accepted an out-of-order stage.');
    }

    $contextId = bx_uuid();
    $contextKeys[] = $contextId;
    $contextPayload = ['schema_version' => 'builderx.test-context.v1', 'source_snapshot' => $source];
    $context = $contextStore->save($contextId, $projectIdentity, $contextPayload);
    $orchestrator->begin($runKey, $projectIdentity, 'context', ['draft_key' => $draftKey]);
    $orchestrator->complete($runKey, $projectIdentity, 'context', [
        'context_id' => $contextId,
        'context_ref' => $context['context_ref'],
        'bytes' => $context['bytes'],
        'sha256' => $context['sha256'],
    ]);
    $orchestrator->begin($runKey, $projectIdentity, 'routing', ['context_id' => $contextId]);
    $orchestrator->complete($runKey, $projectIdentity, 'routing', [
        'role' => 'coordinator',
        'status' => 'routed',
        'selected_specialist' => 'narrative-cleanup',
        'next_specialist' => 'database',
        'reason' => 'The Planning Engine uses the persisted MySQL context.',
    ]);

    $grammarDispatch = $orchestrator->dispatch($runKey, $projectIdentity, 'grammar', ['context_id' => $contextId], 'Return the bounded Narrative grammar JSON result.', true);
    $grammarJobKey = (string) ($grammarDispatch['delivery']['provider_request_id'] ?? '');
    $handoff = $transport->calls[array_key_last($transport->calls)] ?? null;
    if (($handoff['path'] ?? '') !== '/handoff-result' || ($handoff['payload']['job_key'] ?? '') !== $grammarJobKey || ($handoff['payload']['mode'] ?? '') !== 'read_only') {
        throw new RuntimeException('The orchestrator did not dispatch the persisted MySQL job identity.');
    }
    $jobStore->claim($grammarJobKey);
    $grammar = ['role' => 'grammar_specialist', 'status' => 'completed', 'corrected_sections' => $source, 'change_history' => []];
    $jobStore->complete($grammarJobKey, $grammar);
    $bridgeResult = $adapter->result($grammarJobKey);
    if (($bridgeResult['result_json'] ?? null) !== $grammar) {
        throw new RuntimeException('The direct MySQL result read-back failed.');
    }
    $stream = '';
    $adapter->streamEvents($grammarJobKey, static function (string $chunk) use (&$stream): void { $stream .= $chunk; });
    if (!str_contains($stream, 'event: completed') || !str_contains($stream, '"storage":"mysql"')) {
        throw new RuntimeException('The direct MySQL progress stream failed.');
    }
    $orchestrator->assertBridgeBinding($runKey, $projectIdentity, 'grammar', $grammarJobKey);
    $orchestrator->complete($runKey, $projectIdentity, 'grammar', $grammar);

    $validationDispatch = $orchestrator->dispatch($runKey, $projectIdentity, 'validation', ['draft_key' => $draftKey], 'Return the bounded validation JSON result.', true);
    $validationJobKey = (string) ($validationDispatch['delivery']['provider_request_id'] ?? '');
    $approval = [
        'role' => 'database_specialist',
        'status' => 'approved',
        'database_specialist_approved' => true,
        'draft_key' => $draftKey,
        'validation' => ['sections_complete' => true, 'meaning_preserved' => true, 'write_allowed' => true],
        'reason' => 'The bounded result is complete and preserves meaning.',
    ];
    $jobStore->claim($validationJobKey);
    $jobStore->complete($validationJobKey, $approval);
    $orchestrator->assertBridgeBinding($runKey, $projectIdentity, 'validation', $validationJobKey);
    $validationRun = $orchestrator->complete($runKey, $projectIdentity, 'validation', $approval);
    $validationStage = array_values(array_filter(
        $validationRun['stages'] ?? [],
        static fn(mixed $stage): bool => is_array($stage) && ($stage['stage_key'] ?? '') === 'validation'
    ))[0] ?? null;
    $canonicalApproval = is_array($validationStage) ? ($validationStage['result'] ?? null) : null;
    if (
        !is_array($canonicalApproval)
        || array_keys($canonicalApproval) !== ['role', 'status', 'database_specialist_approved', 'draft_key', 'validation', 'reason']
        || ($canonicalApproval['validation'] ?? null) !== ['complete' => true, 'meaning_preserved' => true, 'write_allowed' => true]
    ) {
        throw new RuntimeException('The server did not replace the drifted validation schema with its canonical approval contract.');
    }
    $orchestrator->begin($runKey, $projectIdentity, 'persistence', ['draft_key' => $draftKey, 'approved' => true]);
    $complete = $orchestrator->complete($runKey, $projectIdentity, 'persistence', ['status' => 'saved', 'draft_key' => $draftKey, 'corrected_sections' => $source, 'change_history' => []]);
    if (($complete['status'] ?? '') !== 'SUCCEEDED') {
        throw new RuntimeException('The deterministic MySQL-backed run did not complete.');
    }

    $databaseRun = $db->GetRow('SELECT status, provider_key, model_key, provider_request_id FROM phase_builder_ai_run WHERE run_key = ?', [$runKey]);
    $databaseStages = $db->GetAll('SELECT stage_key, status FROM phase_builder_ai_run_stage WHERE run_key = ? ORDER BY stage_order', [$runKey]);
    $databaseJobs = $db->GetAll('SELECT job_key, status, result_json FROM phase_builder_ai_job WHERE run_key = ? ORDER BY x_id', [$runKey]);
    if (
        ($databaseRun['status'] ?? '') !== 'SUCCEEDED'
        || ($databaseRun['provider_key'] ?? '') !== BuilderXAiBridgeAdapter::PROVIDER_KEY
        || ($databaseRun['model_key'] ?? '') !== 'codex-test-model'
        || ($databaseRun['provider_request_id'] ?? '') !== $validationJobKey
        || count($databaseStages ?: []) !== 5
        || count($databaseJobs ?: []) !== 2
        || array_filter($databaseJobs ?: [], static fn(array $row): bool => ($row['status'] ?? '') !== 'SUCCEEDED') !== []
    ) {
        throw new RuntimeException('The MySQL orchestrator read-back did not match the verified run.');
    }

    $adapter->dispatchJob(bx_uuid(), true);
    $codingHandoff = $transport->calls[array_key_last($transport->calls)] ?? null;
    if (($codingHandoff['payload']['mode'] ?? '') !== 'coding_implementation') {
        throw new RuntimeException('Coding source-change authorization was not bound to the dispatch mode.');
    }

    $extensionSource = (string) file_get_contents($projectRoot . '/tools/builderx-bridge/extension/extension.js');
    if (
        !str_contains($extensionSource, "transport: 'mysql'")
        || !str_contains($extensionSource, 'currentWorkspace()')
        || !str_contains($extensionSource, "runHelper(root, ['claim', jobKey])")
        || str_contains($extensionSource, '.builderx/runtime/tasks')
        || str_contains($extensionSource, 'result.json')
    ) {
        throw new RuntimeException('The BuilderX companion is not a workspace-dynamic MySQL transport.');
    }

    echo json_encode([
        'adapter_health_verified' => true,
        'mysql_transport_verified' => true,
        'deterministic_order_verified' => true,
        'job_identity_dispatch_verified' => true,
        'result_read_back_verified' => true,
        'progress_read_back_verified' => true,
        'companion_version_mismatch_rejected' => true,
        'server_owned_validation_verified' => true,
        'coding_mode_verified' => true,
        'manual_workspace_path_required' => false,
        'filesystem_result_transport_used' => false,
        'terminal_status' => $complete['status'],
        'stage_count' => count($databaseStages),
        'job_count' => count($databaseJobs),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    foreach (array_filter(array_unique($runKeys)) as $cleanupRunKey) {
        $db->Execute('DELETE FROM phase_builder_ai_job WHERE run_key = ?', [$cleanupRunKey]);
        foreach (['phase_builder_ai_run_event', 'phase_builder_ai_run_chunk', 'phase_builder_ai_run_stage', 'phase_builder_ai_run'] as $table) {
            $db->Execute("DELETE FROM {$table} WHERE run_key = ?", [$cleanupRunKey]);
        }
        $db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$cleanupRunKey]);
    }
    foreach (array_filter(array_unique($contextKeys)) as $contextKey) {
        $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ?', [$contextKey]);
    }
}
