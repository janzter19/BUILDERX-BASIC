<?php
declare(strict_types=1);

if (getenv('BUILDERX_LIVE_SHARINGAN_TEST') !== '1') {
    fwrite(STDERR, "Set BUILDERX_LIVE_SHARINGAN_TEST=1 to run the three-surface live Sharingan lifecycle test.\n");
    exit(2);
}

require dirname(__DIR__) . '/app/foundation.php';

$adminLogin = trim((string) getenv('BUILDERX_TEST_ADMIN_LOGIN'));
$adminPassword = (string) getenv('BUILDERX_TEST_ADMIN_PASSWORD');
if ($adminLogin === '') {
    $adminLogin = trim((string) fgets(STDIN));
}
if ($adminPassword === '') {
    $adminPassword = rtrim((string) fgets(STDIN), "\r\n");
}
if ($adminLogin === '' || $adminPassword === '') {
    throw new RuntimeException('Administrator test credentials are required through the process environment or standard input.');
}

$db = bx_db();
$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    throw new RuntimeException('The live Sharingan test project root could not be resolved.');
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$baseUrl = rtrim((string) (getenv('BUILDERX_TEST_BASE_URL') ?: 'http://127.0.0.1/developer'), '/');
$baseRoute = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
$screenshotPath = $projectRoot . '/frontend/src/assets/hero.png';
if (!is_file($screenshotPath)) {
    throw new RuntimeException('The bounded Sharingan test screenshot is unavailable.');
}

$curl = curl_init();
if (!$curl instanceof CurlHandle) {
    throw new RuntimeException('The live Sharingan HTTP client could not start.');
}
curl_setopt($curl, CURLOPT_COOKIEFILE, '');
$runKeys = [];
$contextKeys = [];
$imageDirectories = [];

/** @return array{status: int, body: string, json: array<string, mixed>|null} */
$request = static function (CurlHandle $handle, string $url, string $method = 'GET', array|string|null $fields = null, ?string $referer = null, string $accept = 'application/json', string $csrfHeader = ''): array {
    $headers = ['Accept: ' . $accept, 'X-Requested-With: XMLHttpRequest'];
    if ($csrfHeader !== '') $headers[] = 'X-CSRF-Token: ' . $csrfHeader;
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_POSTFIELDS => null,
    ];
    if ($referer !== null) $options[CURLOPT_REFERER] = $referer;
    if ($fields !== null) $options[CURLOPT_POSTFIELDS] = $fields;
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new RuntimeException('A live Sharingan HTTP request failed: ' . curl_error($handle));
    }
    $decoded = json_decode($body, true);
    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
};

$removeTree = static function (string $path, string $allowedRoot) use (&$removeTree): void {
    $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
    $normalizedRoot = rtrim(str_replace('\\', '/', $allowedRoot), '/');
    if ($normalizedPath === '' || $normalizedPath === $normalizedRoot || !str_starts_with($normalizedPath, $normalizedRoot . '/')) {
        throw new RuntimeException('Refusing to remove a live Sharingan test artifact outside its exact runtime root.');
    }
    if (!file_exists($path) && !is_link($path)) return;
    if (is_dir($path) && !is_link($path)) {
        $children = scandir($path);
        if (!is_array($children)) throw new RuntimeException('A live Sharingan test artifact could not be enumerated.');
        foreach ($children as $child) {
            if ($child === '.' || $child === '..') continue;
            $removeTree($path . '/' . $child, $allowedRoot);
        }
        if (!rmdir($path)) throw new RuntimeException('A live Sharingan test directory could not be removed.');
        return;
    }
    if (!unlink($path)) throw new RuntimeException('A live Sharingan test file could not be removed.');
};

$waitForReady = static function (string $surfaceKey, array $surface) use ($request, $curl, $baseUrl): void {
    $deadline = microtime(true) + 600;
    while (microtime(true) < $deadline) {
        $health = $request(
            $curl,
            $baseUrl . '/sharingan.php?' . http_build_query(['action' => 'health', 'surface_key' => $surfaceKey, 'route_path' => $surface['route']]),
            'GET',
            null,
            $surface['url']
        );
        if (
            $health['status'] === 200
            && ($health['json']['ok'] ?? false) === true
            && ($health['json']['ready_to_send'] ?? false) === true
            && ($health['json']['active_thread_busy'] ?? true) === false
        ) {
            return;
        }
        usleep(1000000);
    }
    throw new RuntimeException('The shared BuilderX AI Bridge did not become ready before the bounded live-test timeout.');
};

try {
    $portal = $request($curl, $baseUrl . '/', 'GET', null, $baseUrl . '/');
    if (preg_match('/"csrf":"([^"]+)"/', $portal['body'], $match) !== 1) {
        throw new RuntimeException('The live Sharingan test could not read the CSRF token.');
    }
    $csrf = $match[1];
    $login = $request($curl, $baseUrl . '/', 'POST', http_build_query([
        'csrf' => $csrf,
        'action' => 'login_portal',
        'login' => $adminLogin,
        'password' => $adminPassword,
    ]), $baseUrl . '/');
    if ($login['status'] !== 302) {
        throw new RuntimeException('The live Sharingan test could not create the authorized session.');
    }

    $surfaces = [
        'user_portal' => ['route' => $baseRoute . '/', 'url' => $baseUrl . '/', 'expected_route_key' => 'user_portal'],
        'administrator_portal' => ['route' => $baseRoute . '/administrator/', 'url' => $baseUrl . '/administrator/', 'expected_route_key' => 'administrator_portal'],
        'phases' => ['route' => $baseRoute . '/phases/', 'url' => $baseUrl . '/phases/', 'expected_route_key' => 'phases'],
    ];
    $verified = [];
    foreach ($surfaces as $surfaceKey => $surface) {
        $metadata = [
            'page' => $surface['url'],
            'active_view' => 'live_sharingan_verification',
            'phase' => ['id' => '', 'title' => ''],
            'selected_element' => [
                'selector' => '#live-sharingan-test',
                'tag' => 'button',
                'label' => 'Live Sharingan test element',
                'ariaLabel' => 'Live Sharingan test element',
                'text' => 'Analyze this bounded element.',
                'rect' => ['x' => 10, 'y' => 10, 'width' => 120, 'height' => 40],
                'elementId' => 'live-sharingan-test',
                'className' => 'test-only',
                'attributes' => ['aria-label' => 'Live Sharingan test element'],
                'computedStyles' => ['display' => 'inline-flex'],
                'outerHtml' => '<button id="live-sharingan-test">Analyze this bounded element.</button>',
                'parentOuterHtml' => '<div><button id="live-sharingan-test">Analyze this bounded element.</button></div>',
            ],
            'annotations' => [],
        ];
        $context = $request($curl, $baseUrl . '/sharingan.php', 'POST', [
            'csrf' => $csrf,
            'action' => 'save_sharingan_context',
            'surface_key' => $surfaceKey,
            'route_path' => $surface['route'],
            'idempotency_key' => bin2hex(random_bytes(16)),
            'instruction' => 'Perform a read-only live lifecycle verification. Return one informational finding and one proposed change that requires Administrator approval. Do not modify files or product data.',
            'surface_scope' => $surfaceKey === 'phases' ? 'system' : 'project',
            'surface_label' => $surfaceKey,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'screenshot' => new CURLFile($screenshotPath, 'image/png', 'live-sharingan-test.png'),
        ], $surface['url']);
        $runKey = (string) ($context['json']['data']['run']['run_key'] ?? '');
        $contextId = (string) ($context['json']['data']['context_id'] ?? '');
        $contextRef = (string) ($context['json']['data']['context_ref'] ?? '');
        if ($context['status'] !== 200 || ($context['json']['ok'] ?? false) !== true || $runKey === '' || $contextId === '' || !str_starts_with($contextRef, 'mysql:phase_builder_ai_context/')) {
            throw new RuntimeException((string) ($context['json']['message'] ?? 'A live Sharingan context could not be persisted.'));
        }
        $runKeys[] = $runKey;
        $contextKeys[] = $contextId;
        $imageDirectories[] = $projectRoot . '/_Document/attachments/sharingan/' . $contextId;

        $waitForReady($surfaceKey, $surface);
        $handoff = $request($curl, $baseUrl . '/sharingan.php', 'POST', [
            'csrf' => $csrf,
            'action' => 'handoff',
            'surface_key' => $surfaceKey,
            'route_path' => $surface['route'],
            'run_key' => $runKey,
        ], $surface['url']);
        $requestId = (string) ($handoff['json']['data']['delivery']['provider_request_id'] ?? '');
        if ($handoff['status'] !== 200 || ($handoff['json']['ok'] ?? false) !== true || $requestId === '') {
            throw new RuntimeException((string) ($handoff['json']['message'] ?? 'A live Sharingan Bridge handoff was not accepted.'));
        }
        $bridgeResult = null;
        $deadline = microtime(true) + 300;
        while (microtime(true) < $deadline) {
            $result = $request(
                $curl,
                $baseUrl . '/sharingan.php?' . http_build_query(['action' => 'result', 'surface_key' => $surfaceKey, 'route_path' => $surface['route'], 'run_key' => $runKey, 'request_id' => $requestId]),
                'GET',
                null,
                $surface['url'],
                'application/json',
                $csrf
            );
            $status = (string) ($result['json']['data']['result']['status'] ?? '');
            if ($status === 'completed') {
                $bridgeResult = $result['json']['data']['result']['result_json'] ?? null;
                break;
            }
            if ($status === 'failed') {
                throw new RuntimeException((string) ($result['json']['data']['result']['message'] ?? 'A live Sharingan Bridge request failed.'));
            }
            usleep(500000);
        }
        if (!is_array($bridgeResult) || array_is_list($bridgeResult)) {
            throw new RuntimeException('A live Sharingan request did not return its MySQL JSON result before timeout.');
        }
        $completed = $request($curl, $baseUrl . '/sharingan.php', 'POST', [
            'csrf' => $csrf,
            'action' => 'complete',
            'surface_key' => $surfaceKey,
            'route_path' => $surface['route'],
            'run_key' => $runKey,
            'result_json' => json_encode($bridgeResult, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ], $surface['url']);
        $analysis = $completed['json']['data']['analysis'] ?? null;
        $run = $completed['json']['data']['run'] ?? null;
        if (
            $completed['status'] !== 200
            || ($completed['json']['ok'] ?? false) !== true
            || !is_array($analysis)
            || !is_array($run)
            || ($run['status'] ?? '') !== 'SUCCEEDED'
            || ($run['engine_type'] ?? '') !== 'PLANNING'
            || ($run['route_key'] ?? '') !== $surface['expected_route_key']
            || ($analysis['surface']['surfaceKey'] ?? '') !== $surfaceKey
            || !array_reduce($analysis['proposedChanges'] ?? [], static fn (bool $valid, mixed $change): bool => $valid && is_array($change) && ($change['requiresAdministratorApproval'] ?? false) === true, true)
        ) {
            throw new RuntimeException((string) ($completed['json']['message'] ?? 'A live Sharingan result failed validation or persistence read-back.'));
        }
        $verified[$surfaceKey] = ['run_status' => $run['status'], 'engine_type' => $run['engine_type'], 'route_key' => $run['route_key'], 'request_id' => $requestId, 'analysis_status' => $analysis['status']];
    }

    echo json_encode([
        'surface_count' => count($verified),
        'surfaces' => $verified,
        'capture_context_bridge_result_persistence' => true,
        'shared_planning_engine_only' => true,
        'administrator_approval_required' => true,
        'product_mutation_performed' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    $adminPassword = str_repeat("\0", strlen($adminPassword));
    curl_close($curl);
    foreach ($runKeys as $runKey) {
        $db->BeginTrans();
        try {
            $db->Execute('DELETE FROM phase_builder_ai_job WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_event WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_chunk WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run_stage WHERE run_key = ?', [$runKey]);
            $db->Execute('DELETE FROM phase_builder_ai_run WHERE run_key = ?', [$runKey]);
            $db->Execute("DELETE FROM builder_audit_log WHERE module = 'phase_builder_ai_run' AND record_key = ?", [$runKey]);
            $db->CommitTrans();
        } catch (Throwable $cleanupError) {
            $db->RollbackTrans();
            throw $cleanupError;
        }
    }
    foreach ($contextKeys as $contextKey) {
        $db->Execute('DELETE FROM phase_builder_ai_context WHERE context_key = ?', [$contextKey]);
    }
    foreach ($imageDirectories as $directory) {
        $removeTree($directory, $projectRoot . '/_Document/attachments/sharingan');
    }
}
