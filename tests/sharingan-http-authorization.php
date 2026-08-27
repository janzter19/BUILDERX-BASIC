<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

$db = bx_db();
$baseUrl = rtrim((string) (getenv('BUILDERX_TEST_BASE_URL') ?: 'http://127.0.0.1/developer'), '/');
$baseRoute = rtrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
$baseRoute = $baseRoute === '' ? '' : $baseRoute;
$testUserKey = bx_uuid();
$testLogin = 'sharingan-test-' . substr(str_replace('-', '', $testUserKey), 0, 12);
$testEmail = $testLogin . '@example.invalid';
$testPassword = bin2hex(random_bytes(16));
$curl = null;

/** @return array{status: int, body: string} */
$request = static function (CurlHandle $handle, string $url, string $method = 'GET', ?array $fields = null, ?string $referer = null): array {
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Requested-With: XMLHttpRequest'],
        CURLOPT_TIMEOUT => 15,
    ];
    if ($referer !== null) {
        $options[CURLOPT_REFERER] = $referer;
    }
    if ($fields !== null) {
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    if (!is_string($body)) {
        throw new RuntimeException('The Sharingan HTTP authorization test request failed.');
    }
    return ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body];
};

try {
    $saved = $db->Execute(
        "INSERT INTO builder_user (user_key, user_login, user_password_hash, user_name, user_email, user_status, user_password_changed_at, user_email_verified_at) VALUES (?, ?, ?, ?, ?, 'ACTIVE', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        [$testUserKey, $testLogin, password_hash($testPassword, PASSWORD_DEFAULT), 'Sharingan Authorization Test', $testEmail]
    );
    if ($saved === false) {
        throw new RuntimeException('The disposable Sharingan authorization user could not be created.');
    }

    $curl = curl_init();
    if (!$curl instanceof CurlHandle) {
        throw new RuntimeException('The HTTP test client could not start.');
    }
    curl_setopt($curl, CURLOPT_COOKIEFILE, '');

    $unauthorized = $request($curl, $baseUrl . '/sharingan.php?action=health&surface_key=user_portal&route_path=' . rawurlencode($baseRoute . '/'), 'GET', null, $baseUrl . '/');
    if ($unauthorized['status'] !== 403) {
        throw new RuntimeException('An anonymous Sharingan health request was not rejected.');
    }
    $anonymousBridge = $request($curl, $baseUrl . '/ai-bridge/index.php/health', 'GET', null, $baseUrl . '/');
    $anonymousPhaseAi = $request($curl, $baseUrl . '/phases/?action=load_phase_ai_run&workflow_key=requirements_analysis', 'GET', null, $baseUrl . '/phases/');
    if ($anonymousBridge['status'] !== 401 || $anonymousPhaseAi['status'] !== 403) {
        throw new RuntimeException('An anonymous AI Bridge or persistent-run request was not rejected.');
    }

    $portalPage = $request($curl, $baseUrl . '/', 'GET', null, $baseUrl . '/');
    if (preg_match('/"csrf":"([^"]+)"/', $portalPage['body'], $match) !== 1) {
        throw new RuntimeException('The User Portal CSRF token could not be read for the authorization test.');
    }
    $csrf = $match[1];
    $login = $request($curl, $baseUrl . '/', 'POST', [
        'csrf' => $csrf,
        'action' => 'login_portal',
        'login' => $testLogin,
        'password' => $testPassword,
    ], $baseUrl . '/');
    if ($login['status'] !== 302) {
        throw new RuntimeException('The disposable User Portal session could not sign in.');
    }

    $userAllowed = $request($curl, $baseUrl . '/sharingan.php?action=health&surface_key=user_portal&route_path=' . rawurlencode($baseRoute . '/'), 'GET', null, $baseUrl . '/');
    $administratorDenied = $request($curl, $baseUrl . '/sharingan.php?action=health&surface_key=administrator_portal&route_path=' . rawurlencode($baseRoute . '/administrator/'), 'GET', null, $baseUrl . '/administrator/');
    $phasesDenied = $request($curl, $baseUrl . '/sharingan.php?action=health&surface_key=phases&route_path=' . rawurlencode($baseRoute . '/phases/'), 'GET', null, $baseUrl . '/phases/');
    $routeMismatch = $request($curl, $baseUrl . '/sharingan.php?action=health&surface_key=user_portal&route_path=' . rawurlencode($baseRoute . '/administrator/'), 'GET', null, $baseUrl . '/administrator/');
    $invalidCsrf = $request($curl, $baseUrl . '/sharingan.php', 'POST', [
        'csrf' => 'invalid',
        'action' => 'handoff',
        'surface_key' => 'user_portal',
        'route_path' => $baseRoute . '/',
        'run_key' => 'invalid',
    ], $baseUrl . '/');
    $nonAdminBridge = $request($curl, $baseUrl . '/ai-bridge/index.php/health', 'GET', null, $baseUrl . '/');
    $nonAdminPhaseAi = $request($curl, $baseUrl . '/phases/?action=load_phase_ai_run&workflow_key=requirements_analysis', 'GET', null, $baseUrl . '/phases/');
    $userAllowedPayload = json_decode($userAllowed['body'], true);
    $workspaceMismatchAfterAuthorization = $userAllowed['status'] === 403
        && is_array($userAllowedPayload)
        && ($userAllowedPayload['error_code'] ?? '') === 'PERMISSION_DENIED'
        && str_contains((string) ($userAllowedPayload['message'] ?? ''), 'different workspace');
    $userSurfaceAuthorized = !in_array($userAllowed['status'], [401, 403], true)
        || $workspaceMismatchAfterAuthorization;
    if (!$userSurfaceAuthorized || $administratorDenied['status'] !== 403 || $phasesDenied['status'] !== 403 || $routeMismatch['status'] !== 403 || $invalidCsrf['status'] !== 403 || $nonAdminBridge['status'] !== 403 || $nonAdminPhaseAi['status'] !== 403) {
        throw new RuntimeException('The Sharingan HTTP authorization matrix did not enforce least privilege: ' . json_encode([
            'user_allowed' => $userAllowed['status'],
            'administrator_denied' => $administratorDenied['status'],
            'phases_denied' => $phasesDenied['status'],
            'route_mismatch' => $routeMismatch['status'],
            'invalid_csrf' => $invalidCsrf['status'],
            'non_admin_bridge' => $nonAdminBridge['status'],
            'non_admin_phase_ai' => $nonAdminPhaseAi['status'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    $administratorRoleKey = (string) $db->GetOne("SELECT role_key FROM builder_role WHERE role_name = 'Administrator' AND role_status = 'ACTIVE' LIMIT 1");
    if ($administratorRoleKey === '' || $db->Execute('INSERT INTO builder_user_role (user_key, role_key) VALUES (?, ?)', [$testUserKey, $administratorRoleKey]) === false) {
        throw new RuntimeException('The disposable authorization user could not be promoted for the administrator allow-path test.');
    }
    $adminBridge = $request($curl, $baseUrl . '/ai-bridge/index.php/health', 'GET', null, $baseUrl . '/phases/');
    $adminPhaseAi = $request($curl, $baseUrl . '/phases/?action=load_phase_ai_run&workflow_key=requirements_analysis', 'GET', null, $baseUrl . '/phases/');
    $adminInvalidCoordinatorCsrf = $request($curl, $baseUrl . '/phases/?action=phase2_coordinator_route&context_id=phase2-narrative-000000000000000000000000&csrf=invalid', 'GET', null, $baseUrl . '/phases/');
    $runtimePermissions = $request($curl, $baseUrl . '/phases/', 'POST', [
        'csrf' => $csrf,
        'action' => 'verify_phase_runtime_permissions',
    ], $baseUrl . '/phases/');
    $adminBridgePayload = json_decode($adminBridge['body'], true);
    $adminBridgeWorkspaceMismatch = $adminBridge['status'] === 403
        && is_array($adminBridgePayload)
        && ($adminBridgePayload['error_code'] ?? '') === 'PERMISSION_DENIED'
        && str_contains((string) ($adminBridgePayload['message'] ?? ''), 'different workspace');
    $runtimePermissionsJson = json_decode($runtimePermissions['body'], true);
    if (
        (in_array($adminBridge['status'], [401, 403, 404], true) && !$adminBridgeWorkspaceMismatch)
        || $adminPhaseAi['status'] !== 200
        || $adminInvalidCoordinatorCsrf['status'] !== 403
        || $runtimePermissions['status'] !== 200
        || !is_array($runtimePermissionsJson)
        || ($runtimePermissionsJson['ok'] ?? false) !== true
        || ($runtimePermissionsJson['data']['transport'] ?? '') !== 'mysql'
        || ($runtimePermissionsJson['data']['read_back_verified'] ?? false) !== true
        || ($runtimePermissionsJson['data']['disposable_context_removed'] ?? false) !== true
    ) {
        throw new RuntimeException('The administrator AI Bridge or persistent-run allow path failed.');
    }

    echo json_encode([
        'anonymous_user_portal' => $unauthorized['status'],
        'authenticated_user_portal' => $userAllowed['status'],
        'non_admin_administrator_portal' => $administratorDenied['status'],
        'non_admin_phases' => $phasesDenied['status'],
        'route_mismatch' => $routeMismatch['status'],
        'invalid_csrf' => $invalidCsrf['status'],
        'anonymous_ai_bridge' => $anonymousBridge['status'],
        'anonymous_phase_ai' => $anonymousPhaseAi['status'],
        'non_admin_ai_bridge' => $nonAdminBridge['status'],
        'non_admin_phase_ai' => $nonAdminPhaseAi['status'],
        'administrator_ai_bridge' => $adminBridge['status'],
        'administrator_phase_ai' => $adminPhaseAi['status'],
        'administrator_invalid_coordinator_csrf' => $adminInvalidCoordinatorCsrf['status'],
        'administrator_mysql_transport' => true,
        'user_portal_coding_privilege_granted' => false,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if ($curl instanceof CurlHandle) {
        curl_close($curl);
    }
    $db->BeginTrans();
    try {
        $db->Execute('DELETE FROM builder_user_session WHERE user_key = ?', [$testUserKey]);
        $db->Execute('DELETE FROM builder_user_login_history WHERE user_key = ?', [$testUserKey]);
        $db->Execute("DELETE FROM builder_audit_log WHERE module = 'authentication' AND record_key = ?", [$testUserKey]);
        $db->Execute('DELETE FROM builder_user_role WHERE user_key = ?', [$testUserKey]);
        $db->Execute('DELETE FROM builder_user WHERE user_key = ?', [$testUserKey]);
        $db->CommitTrans();
    } catch (Throwable $cleanupError) {
        $db->RollbackTrans();
        throw $cleanupError;
    }
}
