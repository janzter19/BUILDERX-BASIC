<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\PhaseAiJobStore;
use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiRunStore;

$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) throw new RuntimeException('The test project root is unavailable.');
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$testUserKey = bx_uuid();
$draftKey = bx_uuid();
$contextKey = 'mysql-transport-' . bx_uuid();
$runKey = '';
$jobKey = '';
$db = bx_db();
$source = [
    'product_goal' => 'Verify direct MySQL AI transport.',
    'users_and_roles' => '',
    'main_user_journey' => '',
    'web_requirements' => '',
    'android_requirements' => '',
    'database_and_synchronization' => '',
    'security_and_permissions' => '',
    'validation_and_error_handling' => '',
    'open_questions' => '',
];
$runHelper = static function (array $arguments) use ($projectRoot): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $projectRoot . '/tools/builderx-ai-job.php'], $arguments),
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        $projectRoot
    );
    if (!is_resource($process)) {
        throw new RuntimeException('The direct MySQL helper process could not start.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $payload = json_decode(trim((string) ($exitCode === 0 ? $stdout : $stderr)), true);
    if ($exitCode !== 0 || !is_array($payload) || ($payload['ok'] ?? false) !== true) {
        throw new RuntimeException('The direct MySQL helper command failed.');
    }
    return $payload;
};

try {
    $context = ['context_type' => 'builderx.mysql-transport-test.v1', 'context_id' => $contextKey, 'draft_key' => $draftKey, 'source_snapshot' => $source];
    $contextMeta = (new PhaseAiContextStore($testUserKey))->save($contextKey, $projectIdentity, $context);
    $runStore = new PhaseAiRunStore($testUserKey);
    $run = $runStore->start('PLANNING', 'narrative_cleanup', $draftKey, null, $projectIdentity, bin2hex(random_bytes(16)), [
        'schema_version' => 'builderx.ai-run.request.v1',
        'route_key' => 'phases:builder',
        'workflow_key' => 'narrative_cleanup',
        'draft_key' => $draftKey,
        'source_snapshot' => $source,
        'context_checkpoint' => $contextMeta,
    ], $testUserKey);
    $runKey = (string) $run['run_key'];
    $orchestrator = new PhaseAiOrchestrator($projectRoot, $runStore);
    $orchestrator->begin($runKey, $projectIdentity, 'context', ['context_id' => $contextKey]);
    $orchestrator->complete($runKey, $projectIdentity, 'context', $contextMeta);
    $orchestrator->begin($runKey, $projectIdentity, 'routing', ['context_id' => $contextKey]);
    $orchestrator->complete($runKey, $projectIdentity, 'routing', [
        'role' => 'coordinator',
        'status' => 'routed',
        'selected_specialist' => 'narrative-cleanup',
        'next_specialist' => 'database',
        'reason' => 'The database transport test uses the deterministic Narrative route.',
    ]);
    $orchestrator->begin($runKey, $projectIdentity, 'grammar', ['context_id' => $contextKey, 'operation' => 'mysql_transport_test']);
    $jobStore = new PhaseAiJobStore($projectRoot, $testUserKey);
    $job = $jobStore->queue($runKey, $projectIdentity, 'grammar', 'Return the complete Narrative grammar JSON object.', false);
    $jobKey = (string) $job['job_key'];
    $claimed = $jobStore->claim($jobKey);
    if (($claimed['status'] ?? '') !== 'running'
        || !str_contains((string) ($claimed['prompt'] ?? ''), 'BUILDERX_MYSQL_JOB_CONTEXT')
        || !str_contains((string) ($claimed['prompt'] ?? ''), $contextKey)
        || !str_contains((string) ($claimed['prompt'] ?? ''), "complete {$jobKey} '<JSON_OBJECT>'")
        || !str_contains((string) ($claimed['prompt'] ?? ''), 'Run the helper directly without a pipeline or shell wrapper.')) {
        throw new RuntimeException('The MySQL AI job claim did not contain its persisted context.');
    }
    $grammar = ['role' => 'grammar_specialist', 'status' => 'completed', 'corrected_sections' => $source, 'change_history' => []];
    $helperResult = $runHelper(['complete', $jobKey, json_encode($grammar, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    if (($helperResult['status'] ?? '') !== 'completed') {
        throw new RuntimeException('The direct MySQL helper did not persist a terminal result.');
    }
    $completedJob = $jobStore->result($jobKey, $projectIdentity);
    if (($completedJob['status'] ?? '') !== 'completed' || ($completedJob['result_json'] ?? null) !== $grammar || ($completedJob['storage'] ?? '') !== 'mysql') {
        throw new RuntimeException('The MySQL AI job result did not pass direct read-back.');
    }
    $orchestrator->complete($runKey, $projectIdentity, 'grammar', $grammar);
    $databaseJob = $db->GetRow('SELECT status, result_json, worker_id FROM phase_builder_ai_job WHERE job_key = ? AND run_key = ? AND stage_key = ?', [$jobKey, $runKey, 'grammar']);
    if (!is_array($databaseJob) || ($databaseJob['status'] ?? '') !== 'SUCCEEDED' || json_decode((string) ($databaseJob['result_json'] ?? ''), true) !== $grammar || !str_starts_with((string) ($databaseJob['worker_id'] ?? ''), 'vscode:')) {
        throw new RuntimeException('The MySQL AI job database row did not match the completed result.');
    }
    echo json_encode([
        'schema' => 'builderx.mysql-ai-transport-test.v1',
        'context_saved_and_verified' => true,
        'job_queued_and_claimed' => true,
        'prior_stage_results_in_prompt' => str_contains((string) $claimed['prompt'], 'routing'),
        'result_saved_and_read_back' => true,
        'direct_argument_helper_verified' => true,
        'cli_session_dependency' => false,
        'filesystem_transport_used' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if ($db->BeginTrans() === false) throw new RuntimeException('The MySQL transport test cleanup could not start.');
    try {
        if ($jobKey !== '') $db->Execute('DELETE FROM phase_builder_ai_job WHERE job_key = ?', [$jobKey]);
        if ($runKey !== '') {
            foreach (['phase_builder_ai_run_event', 'phase_builder_ai_run_chunk', 'phase_builder_ai_run_stage', 'phase_builder_ai_run'] as $table) $db->Execute("DELETE FROM {$table} WHERE run_key = ?", [$runKey]);
        }
        $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ?', [$contextKey]);
        $db->Execute("DELETE FROM builder_audit_log WHERE (module = 'phase_builder_ai_job' AND record_key = ?) OR (module = 'phase_builder_ai_context' AND record_key = ?) OR (module = 'phase_builder_ai_run' AND record_key = ?)", [$jobKey, substr(hash('sha256', $contextKey), 0, 36), $runKey]);
        if ($db->CommitTrans() === false) throw new RuntimeException('The MySQL transport test cleanup could not commit.');
    } catch (Throwable $error) {
        $db->RollbackTrans();
        throw $error;
    }
}
