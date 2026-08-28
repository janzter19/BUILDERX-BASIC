<?php
declare(strict_types=1);

define('BUILDERX_PORTAL_SESSION', true);
require_once __DIR__ . '/app/foundation.php';

function bx_portal_redirect(): void
{
    header('Location: ./');
    exit;
}

function bx_portal_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bx_portal_require_authorization(array $requirements = [], bool $json = false): array
{
    $authorization = bx_portal_authorization_guard($requirements);
    if ($authorization['allowed']) {
        return $authorization;
    }

    if ($json) {
        bx_portal_json_response(['ok' => false, 'message' => (string) $authorization['message']], bx_authorization_status_code($authorization));
    }

    bx_flash((string) $authorization['message'], 'error');
    bx_portal_redirect();
}

function bx_portal_group_context_for_user(array $user): array
{
    $projectKey = trim((string) ($user['project_key'] ?? ''));
    $userKey = trim((string) ($user['user_key'] ?? ''));
    $rows = [];
    if ($projectKey !== '' && $userKey !== '') {
        $hasAssignmentKey = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'project_user_group', 'assignment_key']
        ) === 1;
        if ($hasAssignmentKey) {
            $rows = bx_db()->GetAll(
                "SELECT g.group_key, g.group_name
                 FROM project_user_group a
                 JOIN project_group g ON g.group_key = a.group_key AND g.project_key = a.project_key AND g.group_status = 'ACTIVE'
                 WHERE a.user_key = ? AND a.project_key = ? AND a.assignment_status = 'ACTIVE'
                 ORDER BY g.group_name, g.group_key",
                [$userKey, $projectKey]
            ) ?: [];
        }
        if ($rows === [] && trim((string) ($user['group_key'] ?? '')) !== '') {
            $groupKey = trim((string) $user['group_key']);
            $rows = bx_db()->GetAll(
                "SELECT group_key, group_name
                 FROM project_group
                 WHERE group_key = ? AND project_key = ? AND group_status = 'ACTIVE'
                 LIMIT 1",
                [$groupKey, $projectKey]
            ) ?: [];
            if ($rows === [] && (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [BUILDERX_DB_NAME, 'project_user_group', 'group_name']
            ) === 1) {
                $rows = bx_db()->GetAll(
                    "SELECT group_key, group_name
                     FROM project_user_group
                     WHERE group_key = ? AND project_key = ? AND group_status = 'ACTIVE'
                     LIMIT 1",
                    [$groupKey, $projectKey]
                ) ?: [];
            }
        }
    }

    return [
        'groupKeys' => array_values(array_unique(array_filter(array_map(static fn (array $row): string => trim((string) ($row['group_key'] ?? '')), $rows)))),
        'groupNames' => array_values(array_unique(array_filter(array_map(static fn (array $row): string => trim((string) ($row['group_name'] ?? '')), $rows)))),
    ];
}

function bx_portal_authorization_guard(array $requirements = []): array
{
    $requireAuthenticated = (bool) ($requirements['requireAuthenticated'] ?? true);
    $userKey = trim((string) ($_SESSION['builderx_portal_user_key'] ?? ''));
    $sessionKey = trim((string) ($_SESSION['builderx_portal_session_key'] ?? ''));
    $firebaseUid = trim((string) ($_SESSION['builderx_portal_firebase_uid'] ?? ''));
    if ($userKey === '' || $sessionKey === '' || $firebaseUid === '') {
        return $requireAuthenticated
            ? bx_authorization_result(false, 'authentication_required', 'Sign in before continuing.')
            : bx_authorization_result(true, 'anonymous_allowed', 'Anonymous access allowed.');
    }
    if ((string) ($_SESSION['builderx_auth_audience'] ?? '') !== 'rbms-portal') {
        return bx_authorization_result(false, 'session_invalid', 'Sign in before continuing.');
    }

    $user = bx_db()->GetRow(
        "SELECT * FROM project_user
         WHERE user_key = ? AND firebase_uid = ?
           AND user_status = 'ACTIVE' AND (user_deleted = 0 OR user_deleted IS NULL)
         LIMIT 1",
        [$userKey, $firebaseUid]
    );
    if (!$user) {
        return bx_authorization_result(false, 'session_invalid', 'Sign in before continuing.');
    }

    $groupContext = bx_portal_group_context_for_user($user);
    $administratorGroup = bx_authorization_missing(['Administrators'], $groupContext['groupNames'], true) === null;
    if ($administratorGroup || !empty($_SESSION['builderx_admin_auth_marker']) || (string) ($_SESSION['builderx_auth_audience'] ?? '') === 'rbms-administrator') {
        return bx_authorization_result(false, 'administrator_context_denied', 'This account is not available in the User Portal.');
    }

    $projectKeys = [];
    $projectKey = trim((string) ($user['project_key'] ?? ''));
    if ($projectKey !== '') {
        $projectKeys[] = $projectKey;
    }
    $context = [
        'sessionKey' => $sessionKey,
        'roleNames' => [],
        'roleKeys' => [],
        'permissionCodes' => [],
        'groupKeys' => $groupContext['groupKeys'],
        'groupNames' => $groupContext['groupNames'],
        'branchKeys' => [],
        'branchNames' => [],
        'branchCodes' => [],
        'projectKeys' => $projectKeys,
        'projectBranchKeys' => [],
    ];
    if (!empty($requirements['requireAdmin'])) {
        return bx_authorization_result(false, 'administrator_required', 'Administrator access is not available in the User Portal.', $user, $context);
    }
    if (($requirements['requireTenant'] ?? $requireAuthenticated) && $projectKeys === []) {
        return bx_authorization_result(false, 'tenant_required', 'Request not authorized.', $user, $context);
    }

    foreach ([
        'groupKeys' => ['values' => $context['groupKeys'], 'reason' => 'group_required'],
        'groupNames' => ['values' => $context['groupNames'], 'reason' => 'group_required'],
        'projectKeys' => ['values' => $context['projectKeys'], 'reason' => 'project_required'],
    ] as $key => $constraint) {
        $missing = bx_authorization_missing(bx_authorization_list($requirements[$key] ?? []), $constraint['values'], true);
        if ($missing !== null) {
            return bx_authorization_result(false, $constraint['reason'], 'Request not authorized.', $user, $context);
        }
    }

    return bx_authorization_result(true, 'authorized', 'Authorized.', $user, $context);
}

function bx_portal_current_user(): ?array
{
    $authorization = bx_portal_authorization_guard(['requireAuthenticated' => false, 'requireTenant' => false]);
    return $authorization['allowed'] && is_array($authorization['user'] ?? null) ? $authorization['user'] : null;
}

function bx_portal_logout(): void
{
    $userKey = trim((string) ($_SESSION['builderx_portal_user_key'] ?? ''));
    $userLogin = trim((string) ($_SESSION['builderx_portal_user_login'] ?? ''));
    if ($userKey !== '') {
        bx_project_user_firebase_telemetry($userKey, [
            'user_last_logout_at' => gmdate('c'), 'user_last_logout_ip_address' => bx_client_ip(), 'user_last_logout_device' => bx_project_user_device_label(),
        ]);
        bx_project_user_activity_history($userKey, $userLogin !== '' ? $userLogin : null, 'LOGOUT', 'SUCCESS');
    }
    bx_audit('LOGOUT', 'authentication', $userKey !== '' ? $userKey : null);
    unset($_SESSION['builderx_portal_user_key'], $_SESSION['builderx_portal_user_login'], $_SESSION['builderx_portal_session_key'], $_SESSION['builderx_portal_firebase_uid'], $_SESSION['builderx_portal_administrator_group'], $_SESSION['builderx_auth_audience']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Resolve a Firebase-authenticated Portal identity to the existing Portal
 * session principal. MySQL is used here only for profile, lifecycle, and
 * tenant/group authorization; no password hash is read or verified.
 */
function bx_portal_firebase_identity_context(array $identity): array
{
    $firebaseUid = trim((string) ($identity['uid'] ?? ''));
    if ($firebaseUid === '') {
        throw new RuntimeException('firebase_identity_invalid');
    }
    $sessionAudience = trim((string) ($_SESSION['builderx_auth_audience'] ?? ''));
    $adminSessionMarker = trim((string) ($_SESSION['builderx_admin_auth_marker'] ?? ''));
    if ($sessionAudience === 'rbms-administrator' || $adminSessionMarker === 'RBMS_ADMINISTRATOR') {
        throw new RuntimeException('portal_administrator_context_denied');
    }

    $user = bx_db()->GetRow(
        "SELECT pu.*
         FROM project_user pu
         WHERE pu.firebase_uid = ?
           AND pu.user_status = 'ACTIVE'
           AND (pu.user_deleted = 0 OR pu.user_deleted IS NULL)
         ORDER BY pu.project_key
         LIMIT 1",
        [$firebaseUid]
    );
    if (!$user) {
        $email = strtolower(trim((string) ($identity['email'] ?? '')));
        $emailMatches = $email !== '' ? (bx_db()->GetAll(
            "SELECT pu.*
             FROM project_user pu
             WHERE LOWER(pu.user_auth_email) = ?
               AND pu.firebase_uid IS NULL
               AND pu.user_status = 'ACTIVE'
               AND (pu.user_deleted = 0 OR pu.user_deleted IS NULL)
             ORDER BY pu.project_key",
            [$email]
        ) ?: []) : [];
        if (count($emailMatches) !== 1) {
            throw new RuntimeException(count($emailMatches) > 1 ? 'portal_identity_mapping_ambiguous' : 'portal_identity_not_authorized');
        }
        $candidate = $emailMatches[0];
        $bound = bx_db()->Execute(
            "UPDATE project_user
             SET firebase_uid = ?, mysql_sync_status = 'PENDING', mysql_updated_at = CURRENT_TIMESTAMP(6)
             WHERE user_key = ? AND firebase_uid IS NULL AND user_status = 'ACTIVE' AND (user_deleted = 0 OR user_deleted IS NULL)",
            [$firebaseUid, $candidate['user_key']]
        );
        if ($bound === false) {
            throw new RuntimeException('portal_identity_mapping_failed');
        }
        $user = bx_db()->GetRow(
            "SELECT * FROM project_user WHERE user_key = ? AND firebase_uid = ? AND user_status = 'ACTIVE' AND (user_deleted = 0 OR user_deleted IS NULL) LIMIT 1",
            [$candidate['user_key'], $firebaseUid]
        );
        if (!$user) {
            throw new RuntimeException('portal_identity_mapping_readback_failed');
        }
        bx_audit('UPDATE', 'project_user', (string) $candidate['user_key'], [
            'firebase_uid_bound' => true,
            'mysql_sync_status' => 'PENDING',
        ], 'Portal bound the verified Firebase Auth UID to the matching project_user email.');
    }
    if (!$user) {
        throw new RuntimeException('portal_identity_not_authorized');
    }


    $projectKey = trim((string) ($user['project_key'] ?? ''));
    $groupRows = [];
    $groupKey = trim((string) ($user['group_key'] ?? ''));
    if ($projectKey !== '') {
        $hasAssignmentKey = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'project_user_group', 'assignment_key']
        ) === 1;
        if ($hasAssignmentKey) {
            $groupRows = bx_db()->GetAll(
                "SELECT g.group_key, g.group_name, g.group_status
                 FROM project_user_group a
                 JOIN project_group g ON g.group_key = a.group_key AND g.project_key = a.project_key AND g.group_status = 'ACTIVE'
                 WHERE a.user_key = ? AND a.project_key = ? AND a.assignment_status = 'ACTIVE'
                 LIMIT 20",
                [$user['user_key'], $projectKey]
            ) ?: [];
        }
    }
    if ($groupRows === [] && $groupKey !== '' && $projectKey !== '') {
        // The target contract stores group definitions in project_group. Keep
        // the legacy lookup only as a migration fallback; never treat the
        // assignment-shaped project_user_group table as a definition table.
        $groupRows = bx_db()->GetAll(
            "SELECT group_key, group_name, group_status
             FROM project_group
             WHERE group_key = ? AND project_key = ? AND group_status = 'ACTIVE'
             LIMIT 1",
            [$groupKey, $projectKey]
        ) ?: [];
        if ($groupRows === []) {
            $hasLegacyGroupName = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [BUILDERX_DB_NAME, 'project_user_group', 'group_name']
            ) === 1;
            if ($hasLegacyGroupName) {
                $groupRows = bx_db()->GetAll(
                    "SELECT group_key, group_name, group_status
                     FROM project_user_group
                     WHERE group_key = ? AND project_key = ? AND group_status = 'ACTIVE'
                     LIMIT 1",
                    [$groupKey, $projectKey]
                ) ?: [];
            }
        }
    }

    $administratorGroupMember = false;
    foreach ($groupRows as $groupRow) {
        $groupName = strtolower(trim((string) ($groupRow['group_name'] ?? '')));
        if (in_array($groupName, ['administrator', 'administrators'], true)) {
            $administratorGroupMember = true;
            break;
        }
    }

    if ($administratorGroupMember) {
        throw new RuntimeException('portal_administrator_group_denied');
    }

    return [
        'user' => $user,
        'administratorGroupMember' => $administratorGroupMember,
        'firebaseUid' => $firebaseUid,
        'projectKey' => $projectKey,
    ];
}

/**
 * Resolve the Portal login alias before Firebase email/password sign-in.
 * Only the public auth identifier is returned; passwords never reach this
 * resolver and are sent directly to Firebase Auth by the browser.
 */
function bx_portal_resolve_firebase_identifier(string $login): string
{
    $login = trim($login);
    if ($login === '' || strlen($login) > 190 || preg_match('/[[:cntrl:]]/', $login) === 1) {
        throw new RuntimeException('portal_identity_not_authorized');
    }

    $row = bx_db()->GetRow(
        "SELECT user_auth_username, user_auth_email
         FROM project_user
         WHERE user_login = ?
           AND user_status = 'ACTIVE'
           AND (user_deleted = 0 OR user_deleted IS NULL)
         LIMIT 1",
        [$login]
    );
    if (!$row) {
        throw new RuntimeException('portal_identity_not_authorized');
    }

    $identifier = strtolower(trim((string) ($row['user_auth_email'] ?? '')));
    if ($identifier === '') {
        $username = strtolower(trim((string) ($row['user_auth_username'] ?? '')));
        if (filter_var($username, FILTER_VALIDATE_EMAIL)) {
            $identifier = $username;
        } else {
            $domain = strtolower(trim((string) bx_setting('project_user_auth_email_domain', '')));
            if ($username !== '' && preg_match('/^[a-z0-9][a-z0-9._-]{0,39}$/', $username) === 1
                && preg_match('/^[a-z0-9.-]{3,190}$/', $domain) === 1) {
                $identifier = $username . '@' . $domain;
            }
        }
    }

    if (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('portal_identity_not_authorized');
    }

    return $identifier;
}

function bx_portal_login_with_firebase_identity(array $context, array $identity): void
{
    $user = $context['user'] ?? [];
    $userKey = trim((string) ($user['user_key'] ?? ''));
    $firebaseUid = trim((string) ($identity['uid'] ?? ''));
    if ($userKey === '' || $firebaseUid === '') {
        throw new RuntimeException('firebase_identity_invalid');
    }

    session_regenerate_id(true);
    $sessionKey = bx_uuid();
    $_SESSION['builderx_portal_user_key'] = $userKey;
    $_SESSION['builderx_portal_session_key'] = $sessionKey;
    $_SESSION['builderx_auth_audience'] = 'rbms-portal';
    $_SESSION['builderx_portal_firebase_uid'] = $firebaseUid;
    $_SESSION['builderx_portal_administrator_group'] = !empty($context['administratorGroupMember']);
    $_SESSION['builderx_portal_user_login'] = (string) ($user['user_login'] ?? '');
    unset($_SESSION['builderx_admin_auth_marker'], $_SESSION['builderx_admin_firebase_uid']);

    bx_project_user_firebase_telemetry($userKey, [
        'user_last_login_at' => gmdate('c'), 'user_last_login_ip_address' => bx_client_ip(), 'user_last_login_device' => bx_project_user_device_label(),
    ]);
    bx_project_user_activity_history($userKey, (string) ($user['user_login'] ?? ''), 'LOGIN', 'SUCCESS', null, null, null, null, (string) ($user['project_key'] ?? ''));

    bx_audit('LOGIN', 'authentication', $userKey, [
        'authentication_method' => 'firebase_email_password',
        'auth_audience' => 'rbms-portal',
        'firebase_uid' => $firebaseUid,
        'administrator_group_member' => !empty($context['administratorGroupMember']),
    ], 'Portal signed in with Firebase Auth using project_user.');
    bx_persist_portal_session();
}

function bx_portal_bed_lookup_payload(array $filters): array
{
    $bedLookupRows = bx_project_bed_lookup_rows($filters);
    return [
        'bedLookupOptions' => bx_project_bed_lookup_options($filters),
        'bedLookupRows' => $bedLookupRows,
        // Bed task records and logs are Firebase-only; the browser hydrates them
        // after authenticating with the existing Firebase client.
        'projectBedTasks' => [],
        'projectBedTaskLogs' => [],
    ];
}

/**
 * Read and reconcile one task through the same server-side path used by the
 * normal JSON status endpoint and the SSE status stream.
 *
 * @return array<string, mixed>|null
 */
function bx_ai_task_status_read(
    string $taskId,
    string $userKey,
    \BuilderX\AI\AiTaskStore $taskStore,
    \BuilderX\AI\CommunicationMessageStore $communication,
    bool $allowPhasePersistence = false
): ?array {
    $task = $taskStore->find($taskId, $userKey);
    if ($task === null) {
        return null;
    }

    $task = (new \BuilderX\AI\AiTaskResultReconciler(
        $communication,
        $taskStore
    ))->reconcile((string) $task['task_id']);
    if ($task === null) {
        return null;
    }

    if ($allowPhasePersistence && ($task['status'] ?? '') === 'completed') {
        $input = is_array($task['input'] ?? null) ? $task['input'] : [];
        $sourceSnapshot = is_array($input['source_snapshot'] ?? null) ? $input['source_snapshot'] : [];
        $phaseKey = trim((string) ($input['phase_key'] ?? ''));
        if ($phaseKey === '' && is_array($input['context_refs'] ?? null)) {
            foreach ($input['context_refs'] as $reference) {
                if (is_string($reference) && str_starts_with($reference, 'phase:')) {
                    $phaseKey = trim(substr($reference, 6));
                    break;
                }
            }
        }
        if ($sourceSnapshot === [] && is_string($input['text'] ?? null)) {
            $marker = 'Tab 1 context JSON:';
            $markerPosition = strpos($input['text'], $marker);
            if ($markerPosition !== false) {
                $legacySnapshot = json_decode(trim(substr($input['text'], $markerPosition + strlen($marker))), true);
                if (is_array($legacySnapshot)) {
                    $sourceSnapshot = $legacySnapshot;
                }
            }
        }
        if ($phaseKey !== '' && is_array($task['output'] ?? null)) {
            try {
                $persistence = (new \BuilderX\AI\PhaseBuilderNarrativeCleanupStore())->persist(
                    $phaseKey,
                    $task['output'],
                    $sourceSnapshot,
                    $userKey
                );
                $task['phase_builder_persistence'] = $persistence;
            } catch (Throwable $error) {
                $task['phase_builder_persistence'] = [
                    'status' => 'failed',
                    'message' => 'The Desktop result was received, but the phase draft was not changed.',
                    'details' => $error->getMessage(),
                ];
            }
        }
    }

    $task['delivery_status'] = 'queued';
    foreach ([
        'inbox' => 'queued',
        'locks' => 'received',
        'processed' => 'processed',
        'failed' => 'failed',
    ] as $folder => $deliveryStatus) {
        if ($communication->read((string) $task['task_id'], $folder) !== null) {
            $task['delivery_status'] = $deliveryStatus;
            break;
        }
    }
    if ((string) ($task['status'] ?? '') === 'running') {
        $task['delivery_status'] = 'received';
    }

    return $task;
}

function bx_portal_clean_text(string $value, int $maxLength): string
{
    return substr(trim(preg_replace('/\s+/', ' ', $value) ?: ''), 0, $maxLength);
}

/**
 * Firebase web config contains public client identifiers only. Never expose
 * service account JSON or private keys through this payload.
 *
 * @return array<string, string|bool>
 */
function bx_portal_firebase_web_config(): array
{
    $value = static function (string $settingName, string $envName, string $fallback = ''): string {
        $settingValue = trim(bx_setting($settingName, ''));
        if ($settingValue !== '') {
            return $settingValue;
        }

        $envValue = getenv($envName);
        return is_string($envValue) && trim($envValue) !== '' ? trim($envValue) : $fallback;
    };

    $clientStreamSetting = strtolower(trim((string) bx_setting('firebase_client_stream_enabled', '')));
    $clientStreamEnv = strtolower(trim((string) getenv('FIREBASE_CLIENT_STREAM_ENABLED')));
    $clientWriteEnv = strtolower(trim((string) getenv('FIREBASE_CLIENT_WRITE_ENABLED')));
    $clientStreamEnabled = in_array($clientStreamSetting, ['1', 'true', 'yes', 'enabled'], true)
        || in_array($clientStreamEnv, ['1', 'true', 'yes', 'enabled'], true);
    $config = [
        'apiKey' => $value('firebase_web_api_key', 'FIREBASE_WEB_API_KEY'),
        'authDomain' => $value('firebase_web_auth_domain', 'FIREBASE_WEB_AUTH_DOMAIN'),
        'projectId' => $value('firebase_web_project_id', 'FIREBASE_WEB_PROJECT_ID', bx_messenger_firebase_project_id()),
        'storageBucket' => $value('firebase_web_storage_bucket', 'FIREBASE_WEB_STORAGE_BUCKET'),
        'messagingSenderId' => $value('firebase_web_messaging_sender_id', 'FIREBASE_WEB_MESSAGING_SENDER_ID'),
        'appId' => $value('firebase_web_app_id', 'FIREBASE_WEB_APP_ID'),
        'measurementId' => $value('firebase_web_measurement_id', 'FIREBASE_WEB_MEASUREMENT_ID'),
        'clientStreamEnabled' => $clientStreamEnabled,
        'clientWriteEnabled' => in_array(strtolower(trim((string) bx_setting('firebase_client_write_enabled', '0'))), ['1', 'true', 'yes', 'enabled'], true)
            || in_array($clientWriteEnv, ['1', 'true', 'yes', 'enabled'], true),
        'mode' => 'firebase_auth',
        'authEnabled' => true,
    ];

    return array_filter($config, static fn ($item): bool => is_string($item) ? $item !== '' : true) + [
        'enabled' => $config['projectId'] !== '' && $config['apiKey'] !== '',
        'authEnabled' => $config['projectId'] !== '' && $config['apiKey'] !== '',
    ];
}

function bx_portal_firebase_custom_token(array $authorization): string
{
    $userKey = trim((string) ($authorization['user']['user_key'] ?? ''));
    if ($userKey === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/', $userKey)) {
        throw new RuntimeException('Firebase portal authentication is unavailable.');
    }

    $projectId = bx_messenger_firebase_project_id();
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    $scriptPath = __DIR__ . '/scripts/firebase-auth-custom-token.mjs';
    if ($projectId === '' || $serviceAccountPath === '' || !is_readable($serviceAccountPath) || !is_readable($scriptPath)) {
        throw new RuntimeException('Firebase portal authentication is unavailable.');
    }

    $projectKeys = array_values(array_filter(array_map('strval', is_array($authorization['projectKeys'] ?? null) ? $authorization['projectKeys'] : [])));
    $firebaseGroupKeys = array_values(array_unique(array_filter(array_map(
        'strval',
        is_array($authorization['groupKeys'] ?? null) ? $authorization['groupKeys'] : []
    ))));

    $payload = json_encode([
        'project_id' => $projectId,
        'service_account_path' => $serviceAccountPath,
        'user_key' => $userKey,
        'tenant_key' => $projectId,
        'project_keys' => $projectKeys,
        'group_keys' => $firebaseGroupKeys,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        throw new RuntimeException('Firebase portal authentication is unavailable.');
    }

    $process = proc_open(
        'node ' . escapeshellarg($scriptPath),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Firebase portal authentication is unavailable.');
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if ($exitCode !== 0 || !is_array($result) || ($result['ok'] ?? false) !== true || trim((string) ($result['token'] ?? '')) === '') {
        error_log('RBMS portal Firebase custom token failed: ' . trim((string) $stderr));
        throw new RuntimeException('Firebase portal authentication is unavailable.');
    }

    return trim((string) $result['token']);
}

function bx_portal_date_or_null(string $value, array &$errors, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $errors[] = "{$label} must use YYYY-MM-DD format.";
        return null;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $value));
    if (!checkdate($month, $day, $year)) {
        $errors[] = "{$label} is not a valid date.";
        return null;
    }

    return $value;
}

function bx_portal_array_rows(string $key): array
{
    $rows = $_POST[$key] ?? [];
    return is_array($rows) ? array_values($rows) : [];
}

function bx_portal_family_payload_from_post(): array
{
    $errors = [];
    $member = [
        'first_name' => bx_portal_clean_text((string) ($_POST['first_name'] ?? ''), 80),
        'middle_name' => bx_portal_clean_text((string) ($_POST['middle_name'] ?? ''), 80),
        'last_name' => bx_portal_clean_text((string) ($_POST['last_name'] ?? ''), 80),
        'suffix' => bx_portal_clean_text((string) ($_POST['suffix'] ?? ''), 40),
        'birth_date' => bx_portal_date_or_null((string) ($_POST['birth_date'] ?? ''), $errors, 'Birth date'),
        'relationship_to_user' => bx_portal_clean_text((string) ($_POST['relationship_to_user'] ?? ''), 80),
        'contact_email' => bx_portal_clean_text((string) ($_POST['contact_email'] ?? ''), 190),
        'contact_phone' => bx_portal_clean_text((string) ($_POST['contact_phone'] ?? ''), 40),
        'consent_privacy' => isset($_POST['consent_privacy']) ? 1 : 0,
        'consent_contact' => isset($_POST['consent_contact']) ? 1 : 0,
    ];

    if ($member['first_name'] === '') {
        $errors[] = 'First name is required.';
    }
    if ($member['last_name'] === '') {
        $errors[] = 'Last name is required.';
    }
    if ($member['relationship_to_user'] === '') {
        $errors[] = 'Relationship is required.';
    }
    if ($member['contact_email'] !== '' && !filter_var($member['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email is invalid.';
    }
    if ($member['contact_phone'] !== '' && !preg_match('/^[0-9+().\-\s]{3,40}$/', $member['contact_phone'])) {
        $errors[] = 'Contact phone contains unsupported characters.';
    }
    if ($member['consent_privacy'] !== 1) {
        $errors[] = 'Privacy consent is required before saving a family member.';
    }

    $vehicles = [];
    $plates = [];
    foreach (bx_portal_array_rows('vehicles') as $index => $row) {
        if (!is_array($row)) {
            continue;
        }

        $vehicle = [
            'plate_number' => strtoupper(bx_portal_clean_text((string) ($row['plate_number'] ?? ''), 40)),
            'make' => bx_portal_clean_text((string) ($row['make'] ?? ''), 80),
            'model' => bx_portal_clean_text((string) ($row['model'] ?? ''), 80),
            'model_year' => trim((string) ($row['model_year'] ?? '')),
            'color' => bx_portal_clean_text((string) ($row['color'] ?? ''), 60),
            'ownership_type' => bx_portal_clean_text((string) ($row['ownership_type'] ?? ''), 80),
            'registration_status' => bx_portal_clean_text((string) ($row['registration_status'] ?? ''), 80),
        ];
        if (implode('', $vehicle) === '') {
            continue;
        }
        if ($vehicle['plate_number'] === '') {
            $errors[] = 'Vehicle plate number is required when adding a vehicle.';
        }
        if ($vehicle['ownership_type'] === '') {
            $errors[] = 'Vehicle ownership type is required when adding a vehicle.';
        }
        if ($vehicle['model_year'] !== '') {
            if (!ctype_digit($vehicle['model_year']) || (int) $vehicle['model_year'] < 1900 || (int) $vehicle['model_year'] > ((int) date('Y') + 1)) {
                $errors[] = 'Vehicle model year is outside the allowed range.';
            } else {
                $vehicle['model_year'] = (string) (int) $vehicle['model_year'];
            }
        } else {
            $vehicle['model_year'] = null;
        }
        $plateKey = strtolower($vehicle['plate_number']);
        if ($plateKey !== '' && isset($plates[$plateKey])) {
            $errors[] = 'Duplicate vehicle plate numbers are not allowed in one registration.';
        }
        $plates[$plateKey] = true;
        $vehicles[] = $vehicle;
    }

    $educationRows = [];
    $educationDuplicates = [];
    foreach (bx_portal_array_rows('education') as $row) {
        if (!is_array($row)) {
            continue;
        }

        $dateErrors = [];
        $education = [
            'education_level' => bx_portal_clean_text((string) ($row['education_level'] ?? ''), 80),
            'institution_name' => bx_portal_clean_text((string) ($row['institution_name'] ?? ''), 190),
            'program_name' => bx_portal_clean_text((string) ($row['program_name'] ?? ''), 190),
            'date_started' => bx_portal_date_or_null((string) ($row['date_started'] ?? ''), $dateErrors, 'Education start date'),
            'date_completed' => bx_portal_date_or_null((string) ($row['date_completed'] ?? ''), $dateErrors, 'Education completion date'),
            'completion_status' => bx_portal_clean_text((string) ($row['completion_status'] ?? ''), 80),
        ];
        $errors = array_merge($errors, $dateErrors);
        if (implode('', array_map(static fn ($item): string => (string) $item, $education)) === '') {
            continue;
        }
        if ($education['education_level'] === '') {
            $errors[] = 'Education level is required when adding education history.';
        }
        if ($education['institution_name'] === '') {
            $errors[] = 'Institution name is required when adding education history.';
        }
        if ($education['completion_status'] === '') {
            $errors[] = 'Completion status is required when adding education history.';
        }
        if ($education['date_started'] && $education['date_completed'] && strcmp($education['date_started'], $education['date_completed']) > 0) {
            $errors[] = 'Education start date cannot be after completion date.';
        }
        $duplicateKey = strtolower($education['education_level'] . '|' . $education['institution_name'] . '|' . $education['program_name'] . '|' . (string) $education['date_started']);
        if (isset($educationDuplicates[$duplicateKey])) {
            $errors[] = 'Duplicate education history rows are not allowed in one registration.';
        }
        $educationDuplicates[$duplicateKey] = true;
        $educationRows[] = $education;
    }

    return ['member' => $member, 'vehicles' => $vehicles, 'education' => $educationRows, 'errors' => $errors];
}

function bx_portal_family_member_read_back(string $memberKey, string $ownerKey): array
{
    $member = bx_db()->GetRow(
        "SELECT
            member_key, owner_user_key, first_name, middle_name, last_name, suffix, birth_date,
            relationship_to_user, contact_email, contact_phone, consent_privacy, consent_contact,
            member_status, member_created_at, member_updated_at
        FROM builder_family_member
        WHERE member_key = ? AND owner_user_key = ? AND member_status <> 'DELETED'",
        [$memberKey, $ownerKey]
    ) ?: [];
    if ($member === []) {
        return [];
    }

    $member['vehicles'] = bx_db()->GetAll(
        "SELECT vehicle_key, plate_number, make, model, model_year, color, ownership_type, registration_status
        FROM builder_family_member_vehicle
        WHERE member_key = ? AND owner_user_key = ? AND vehicle_status <> 'DELETED'
        ORDER BY x_id ASC",
        [$memberKey, $ownerKey]
    ) ?: [];
    $member['education'] = bx_db()->GetAll(
        "SELECT education_key, education_level, institution_name, program_name, date_started, date_completed, completion_status
        FROM builder_family_member_education
        WHERE member_key = ? AND owner_user_key = ? AND education_status <> 'DELETED'
        ORDER BY COALESCE(date_started, '9999-12-31') ASC, x_id ASC",
        [$memberKey, $ownerKey]
    ) ?: [];

    return $member;
}

function bx_portal_save_family_member(array $user): bool
{
    $memberKey = trim((string) ($_POST['member_key'] ?? ''));
    $payload = bx_portal_family_payload_from_post();
    $member = $payload['member'];
    $errors = $payload['errors'];
    $ownerKey = (string) $user['user_key'];

    if ($memberKey !== '') {
        $existing = bx_db()->GetRow(
            "SELECT member_key FROM builder_family_member WHERE member_key = ? AND owner_user_key = ? AND member_status <> 'DELETED'",
            [$memberKey, $ownerKey]
        );
        if (!$existing) {
            bx_audit('UNAUTHORIZED', 'builder_family_member', null, ['requested_member_key' => $memberKey], 'Portal update rejected because the member is not owned by the signed-in user.');
            bx_mutation_lifecycle_flash('You are not authorized to edit that family member.', 'error', [
                ['label' => 'Authorization', 'status' => 'blocked', 'detail' => 'The signed-in account does not own this active record.'],
                ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No database mutation was attempted.'],
            ]);
            return false;
        }
    }

    $duplicateParams = [
        $ownerKey,
        $member['first_name'],
        $member['last_name'],
        $member['relationship_to_user'],
        $member['birth_date'],
        $member['birth_date'],
    ];
    $duplicateWhere = "owner_user_key = ? AND member_status <> 'DELETED' AND LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND LOWER(relationship_to_user) = LOWER(?) AND ((birth_date IS NULL AND ? IS NULL) OR birth_date = ?)";
    if ($memberKey !== '') {
        $duplicateWhere .= ' AND member_key <> ?';
        $duplicateParams[] = $memberKey;
    }
    if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member WHERE {$duplicateWhere}", $duplicateParams) > 0) {
        $errors[] = 'A matching family member already exists for your account.';
    }

    foreach ($payload['vehicles'] as $vehicle) {
        if ($vehicle['plate_number'] === '') {
            continue;
        }
        $vehicleParams = [$ownerKey, $vehicle['plate_number']];
        $vehicleWhere = "owner_user_key = ? AND vehicle_status <> 'DELETED' AND LOWER(plate_number) = LOWER(?)";
        if ($memberKey !== '') {
            $vehicleWhere .= ' AND member_key <> ?';
            $vehicleParams[] = $memberKey;
        }
        if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_family_member_vehicle WHERE {$vehicleWhere}", $vehicleParams) > 0) {
            $errors[] = 'Vehicle plate ' . $vehicle['plate_number'] . ' is already assigned to another family member in your account.';
        }
    }

    if ($errors) {
        bx_mutation_lifecycle_flash(implode(' ', array_unique($errors)), 'error', [
            ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'The signed-in account passed the portal guard.'],
            ['label' => 'Validation', 'status' => 'blocked', 'detail' => 'The submitted values need correction before persistence.'],
            ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No database mutation was attempted.'],
        ]);
        return false;
    }

    $db = bx_db();
    $db->StartTrans();
    try {
        $isUpdate = $memberKey !== '';
        if (!$isUpdate) {
            $memberKey = bx_uuid();
            $db->Execute(
                "INSERT INTO builder_family_member (
                    member_key, owner_user_key, first_name, middle_name, last_name, suffix, birth_date,
                    relationship_to_user, contact_email, contact_phone, consent_privacy, consent_contact,
                    member_created_by_key, member_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $memberKey,
                    $ownerKey,
                    $member['first_name'],
                    $member['middle_name'] ?: null,
                    $member['last_name'],
                    $member['suffix'] ?: null,
                    $member['birth_date'],
                    $member['relationship_to_user'],
                    $member['contact_email'] ?: null,
                    $member['contact_phone'] ?: null,
                    $member['consent_privacy'],
                    $member['consent_contact'],
                    $ownerKey,
                    $ownerKey,
                ]
            );
        } else {
            $db->Execute(
                "UPDATE builder_family_member
                SET first_name = ?, middle_name = ?, last_name = ?, suffix = ?, birth_date = ?,
                    relationship_to_user = ?, contact_email = ?, contact_phone = ?, consent_privacy = ?,
                    consent_contact = ?, member_updated_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND member_status <> 'DELETED'",
                [
                    $member['first_name'],
                    $member['middle_name'] ?: null,
                    $member['last_name'],
                    $member['suffix'] ?: null,
                    $member['birth_date'],
                    $member['relationship_to_user'],
                    $member['contact_email'] ?: null,
                    $member['contact_phone'] ?: null,
                    $member['consent_privacy'],
                    $member['consent_contact'],
                    $ownerKey,
                    $memberKey,
                    $ownerKey,
                ]
            );
            $db->Execute(
                "UPDATE builder_family_member_vehicle
                SET vehicle_status = 'DELETED', vehicle_deleted_at = CURRENT_TIMESTAMP, vehicle_deleted_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND vehicle_status <> 'DELETED'",
                [$ownerKey, $memberKey, $ownerKey]
            );
            $db->Execute(
                "UPDATE builder_family_member_education
                SET education_status = 'DELETED', education_deleted_at = CURRENT_TIMESTAMP, education_deleted_by_key = ?
                WHERE member_key = ? AND owner_user_key = ? AND education_status <> 'DELETED'",
                [$ownerKey, $memberKey, $ownerKey]
            );
        }

        foreach ($payload['vehicles'] as $vehicle) {
            $db->Execute(
                "INSERT INTO builder_family_member_vehicle (
                    vehicle_key, member_key, owner_user_key, plate_number, make, model, model_year,
                    color, ownership_type, registration_status, vehicle_created_by_key, vehicle_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    bx_uuid(),
                    $memberKey,
                    $ownerKey,
                    $vehicle['plate_number'],
                    $vehicle['make'] ?: null,
                    $vehicle['model'] ?: null,
                    $vehicle['model_year'],
                    $vehicle['color'] ?: null,
                    $vehicle['ownership_type'],
                    $vehicle['registration_status'] ?: null,
                    $ownerKey,
                    $ownerKey,
                ]
            );
        }

        foreach ($payload['education'] as $education) {
            $db->Execute(
                "INSERT INTO builder_family_member_education (
                    education_key, member_key, owner_user_key, education_level, institution_name,
                    program_name, date_started, date_completed, completion_status, education_created_by_key, education_updated_by_key
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    bx_uuid(),
                    $memberKey,
                    $ownerKey,
                    $education['education_level'],
                    $education['institution_name'],
                    $education['program_name'] ?: null,
                    $education['date_started'],
                    $education['date_completed'],
                    $education['completion_status'],
                    $ownerKey,
                    $ownerKey,
                ]
            );
        }

        bx_audit($isUpdate ? 'UPDATE' : 'CREATE', 'builder_family_member', $memberKey, [
            'vehicle_count' => count($payload['vehicles']),
            'education_count' => count($payload['education']),
        ], $isUpdate ? 'User Portal family member updated.' : 'User Portal family member created.');
    } catch (Throwable $exception) {
        $db->FailTrans();
        $db->CompleteTrans();
        bx_mutation_lifecycle_flash('Family member could not be saved. Please review the form and try again.', 'error', [
            ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'The signed-in account passed the portal guard.'],
            ['label' => 'Persistence', 'status' => 'rolled_back', 'detail' => 'The transaction was marked failed before completion.'],
            ['label' => 'Read-back', 'status' => 'not_started', 'detail' => 'No committed record was reported.'],
        ]);
        bx_audit('ERROR', 'builder_family_member', $memberKey ?: null, ['error' => $exception->getMessage()], 'User Portal family member save failed.');
        return false;
    }

    if ($db->CompleteTrans() === false) {
        bx_mutation_lifecycle_flash('Family member could not be saved. Please review the form and try again.', 'error', [
            ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'The signed-in account passed the portal guard.'],
            ['label' => 'Persistence', 'status' => 'failed', 'detail' => 'The transaction did not complete successfully.'],
            ['label' => 'Read-back', 'status' => 'not_started', 'detail' => 'No committed record was reported.'],
        ]);
        bx_audit('ERROR', 'builder_family_member', $memberKey ?: null, ['error' => 'transaction_complete_failed'], 'User Portal family member transaction failed.');
        return false;
    }

    $readBack = bx_portal_family_member_read_back($memberKey, $ownerKey);
    if ($readBack === []) {
        bx_mutation_lifecycle_flash('Family member could not be verified after save. Please refresh and try again.', 'error', [
            ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'The signed-in account passed the portal guard.'],
            ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'The transaction completed.'],
            ['label' => 'Read-back', 'status' => 'blocked', 'detail' => 'The committed owner-scoped member row was not found.'],
        ]);
        bx_audit('ERROR', 'builder_family_member', $memberKey, ['error' => 'committed_read_back_missing'], 'User Portal family member read-back failed after commit.');
        return false;
    }

    $vehicleCount = count(is_array($readBack['vehicles'] ?? null) ? $readBack['vehicles'] : []);
    $educationCount = count(is_array($readBack['education'] ?? null) ? $readBack['education'] : []);
    $readBackDetails = 'Committed read-back verified for this owner-scoped member with ' . $vehicleCount . ' active vehicle row(s) and ' . $educationCount . ' active education row(s).';
    bx_mutation_lifecycle_flash(
        $member['first_name'] . ' ' . $member['last_name'] . ' was saved.',
        'success',
        [
            ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'The signed-in account owns the saved record.'],
            ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'The transaction completed before feedback was created.'],
            ['label' => 'Read-back', 'status' => 'complete', 'detail' => $readBackDetails],
            ['label' => 'Realtime sync', 'status' => 'queued', 'detail' => 'Downstream streams may publish only after committed read-back.'],
        ],
        $readBackDetails
    );
    return true;
}

function bx_portal_family_members(?array $user): array
{
    if (!$user) {
        return [];
    }

    $ownerKey = (string) $user['user_key'];
    $members = bx_db()->GetAll(
        "SELECT
            member_key, first_name, middle_name, last_name, suffix, birth_date, relationship_to_user,
            contact_email, contact_phone, consent_privacy, consent_contact, member_status,
            member_created_at, member_updated_at,
            (SELECT COUNT(*) FROM builder_family_member_vehicle v WHERE v.member_key = m.member_key AND v.owner_user_key = m.owner_user_key AND v.vehicle_status <> 'DELETED') AS vehicle_count,
            (SELECT COUNT(*) FROM builder_family_member_education e WHERE e.member_key = m.member_key AND e.owner_user_key = m.owner_user_key AND e.education_status <> 'DELETED') AS education_count
        FROM builder_family_member m
        WHERE owner_user_key = ? AND member_status <> 'DELETED'
        ORDER BY member_updated_at DESC, x_id DESC",
        [$ownerKey]
    ) ?: [];

    foreach ($members as &$member) {
        $readBack = bx_portal_family_member_read_back((string) $member['member_key'], $ownerKey);
        $member['vehicles'] = is_array($readBack['vehicles'] ?? null) ? $readBack['vehicles'] : [];
        $member['education'] = is_array($readBack['education'] ?? null) ? $readBack['education'] : [];
    }
    unset($member);

    return $members;
}

function bx_portal_table_exists(string $tableName): bool
{
    return (int) bx_db()->GetOne(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$tableName]
    ) > 0;
}

function bx_portal_table_count(string $tableName, string $where = '1=1'): int
{
    if (!bx_portal_table_exists($tableName)) {
        return 0;
    }

    return (int) bx_db()->GetOne('SELECT COUNT(*) FROM `' . str_replace('`', '``', $tableName) . '` WHERE ' . $where);
}

function bx_portal_operational_workspace(?array $user, array $members): array
{
    if (!$user) {
        return [
            'tenant' => ['floorName' => 'Unassigned floor', 'projectName' => 'No active project'],
            'sources' => [],
            'metrics' => [],
            'bedStatus' => [],
            'residenceCoverage' => [],
            'assignedTasks' => [],
            'notifications' => [],
            'reports' => [],
            'canCreateCommonTask' => false,
            'writeActionsAvailable' => false,
        ];
    }

    $authorization = bx_portal_authorization_guard(['requireAuthenticated' => true]);
    $branchKey = (string) (($authorization['branchKeys'][0] ?? '') ?: '');
    $projectKey = (string) (($authorization['projectKeys'][0] ?? '') ?: '');
    $branch = $branchKey !== ''
        ? (bx_db()->GetRow('SELECT branch_name, branch_code FROM builder_branch WHERE branch_key = ?', [$branchKey]) ?: [])
        : [];
    $project = $projectKey !== ''
        ? (bx_db()->GetRow('SELECT project_name, project_code FROM builder_project WHERE project_key = ?', [$projectKey]) ?: [])
        : [];
    $floorName = trim((string) ($branch['branch_name'] ?? '')) !== '' ? (string) $branch['branch_name'] : 'Assigned floor';

    $sourceTables = [
        'px_for_hras' => 'Patient residence',
        'RBMS_BedMasterlist' => 'Bed information',
        'RBMS_CheckBedStatus' => 'Bed status',
    ];
    $sources = [];
    foreach ($sourceTables as $tableName => $label) {
        $sources[] = [
            'table' => $tableName,
            'label' => $label,
            'available' => bx_portal_table_exists($tableName),
        ];
    }

    $totalBeds = bx_portal_table_count('RBMS_BedMasterlist');
    $trackedStatuses = bx_portal_table_count('RBMS_CheckBedStatus');
    $patientResidenceRows = bx_portal_table_count('px_for_hras', "`zStatus` <> 'DELETED'");
    $occupiedBeds = bx_portal_table_count('RBMS_CheckBedStatus', "LOWER(COALESCE(`BedStatus`, '')) NOT IN ('', 'vacant', 'available')");
    $vacantBeds = bx_portal_table_count('RBMS_CheckBedStatus', "LOWER(COALESCE(`BedStatus`, '')) IN ('vacant', 'available')");

    $bedStatusRows = bx_portal_table_exists('RBMS_CheckBedStatus')
        ? (bx_db()->GetAll(
            "SELECT COALESCE(NULLIF(TRIM(`BedStatus`), ''), 'Unspecified') AS status_label, COUNT(*) AS total
            FROM `RBMS_CheckBedStatus`
            GROUP BY status_label
            ORDER BY total DESC, status_label ASC
            LIMIT 8"
        ) ?: [])
        : [];
    $residenceRows = bx_portal_table_exists('px_for_hras')
        ? (bx_db()->GetAll(
            "SELECT COALESCE(NULLIF(TRIM(`Province`), ''), 'Unspecified') AS province, COUNT(*) AS total
            FROM `px_for_hras`
            WHERE `zStatus` <> 'DELETED'
            GROUP BY province
            ORDER BY total DESC, province ASC
            LIMIT 8"
        ) ?: [])
        : [];

    $assignedTasks = [];
    if ($totalBeds > $trackedStatuses) {
        $assignedTasks[] = [
            'taskKey' => 'portal-bed-status-review',
            'title' => 'Review bed status coverage',
            'stage' => 'Bed status',
            'priority' => 'High',
            'detail' => 'Some RBMS_BedMasterlist beds do not have matching RBMS_CheckBedStatus rows.',
            'source' => 'RBMS_BedMasterlist + RBMS_CheckBedStatus',
            'count' => $totalBeds - $trackedStatuses,
        ];
    }
    $assignedTasks[] = [
        'taskKey' => 'portal-vacancy-followup',
        'title' => 'Confirm vacant bed queue',
        'stage' => 'Availability',
        'priority' => $vacantBeds > 0 ? 'Normal' : 'Blocked',
        'detail' => 'RBMS_CheckBedStatus is the vacancy source for portal availability decisions.',
        'source' => 'RBMS_CheckBedStatus',
        'count' => $vacantBeds,
    ];
    $assignedTasks[] = [
        'taskKey' => 'portal-residence-coverage',
        'title' => 'Validate patient residence coverage',
        'stage' => 'Residence',
        'priority' => $patientResidenceRows > 0 ? 'Normal' : 'High',
        'detail' => 'px_for_hras provides patient residence fields for non-sensitive residence summaries.',
        'source' => 'px_for_hras',
        'count' => $patientResidenceRows,
    ];

    $familyTaskCount = 0;
    foreach ($members as $member) {
        if ($familyTaskCount >= 3) {
            break;
        }
        $familyTaskCount++;
        $assignedTasks[] = [
            'taskKey' => (string) ($member['member_key'] ?? ('family-member-' . $familyTaskCount)) . '-profile',
            'title' => 'Verify owner-scoped family profile',
            'stage' => 'Profile',
            'priority' => ((int) ($member['vehicle_count'] ?? 0) + (int) ($member['education_count'] ?? 0)) > 1 ? 'Normal' : 'Low',
            'detail' => 'Owner-scoped family profile is available for user-managed updates.',
            'source' => 'builder_family_member',
            'count' => (int) ($member['vehicle_count'] ?? 0) + (int) ($member['education_count'] ?? 0),
        ];
    }
    $previewImages = array_map(static fn (array $source): array => [
        'label' => (string) $source['label'],
        'meta' => !empty($source['available']) ? 'Available source' : 'Source table unavailable',
    ], array_slice($sources, 0, 4));
    while (count($previewImages) < 4) {
        $previewImages[] = ['label' => 'Image slot ' . (count($previewImages) + 1), 'meta' => 'Awaiting source metadata'];
    }
    $activeBeds = array_map(static fn (array $row, int $index): array => [
        'bedKey' => 'bed-status-' . ($index + 1),
        'bedLabel' => (string) ($row['status_label'] ?? 'Unspecified'),
        'floorName' => $floorName,
        'status' => (string) ($row['status_label'] ?? 'Unspecified'),
        'patientName' => 'Bed status group',
        'taskCount' => (int) ($row['total'] ?? 0),
        'previewImages' => $previewImages,
    ], $bedStatusRows, array_keys($bedStatusRows));

    return [
        'tenant' => [
            'floorName' => $floorName,
            'branchCode' => (string) ($branch['branch_code'] ?? ''),
            'projectName' => (string) ($project['project_name'] ?? 'Current project'),
            'projectCode' => (string) ($project['project_code'] ?? ''),
        ],
        'activeBeds' => $activeBeds,
        'sources' => $sources,
        'metrics' => [
            ['label' => 'Beds', 'value' => $totalBeds],
            ['label' => 'Status rows', 'value' => $trackedStatuses],
            ['label' => 'Occupied', 'value' => $occupiedBeds],
            ['label' => 'Vacant', 'value' => $vacantBeds],
            ['label' => 'Residence rows', 'value' => $patientResidenceRows],
        ],
        'bedStatus' => array_map(static fn (array $row): array => [
            'label' => (string) ($row['status_label'] ?? 'Unspecified'),
            'total' => (int) ($row['total'] ?? 0),
        ], $bedStatusRows),
        'residenceCoverage' => array_map(static fn (array $row): array => [
            'label' => (string) ($row['province'] ?? 'Unspecified'),
            'total' => (int) ($row['total'] ?? 0),
        ], $residenceRows),
        'assignedTasks' => $assignedTasks,
        'notifications' => [
            ['level' => 'info', 'message' => 'Workspace is read from secured tenant-scoped hospital and owner records.'],
            ['level' => 'warning', 'message' => 'Common Task creation, stage progression, and chat writes require database rollback protection before enablement.'],
        ],
        'reports' => [
            ['label' => 'Tracked beds', 'value' => $trackedStatuses],
            ['label' => 'Assigned tasks', 'value' => count($assignedTasks)],
            ['label' => 'Source tables', 'value' => count(array_filter($sources, static fn (array $source): bool => (bool) $source['available']))],
        ],
        'canCreateCommonTask' => false,
        'writeActionsAvailable' => false,
    ];
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($requestMethod === 'POST') {
    bx_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'firebase_login_portal') {
        try {
            $identity = bx_admin_verify_firebase_id_token((string) ($_POST['firebase_id_token'] ?? ''), false);
            $context = bx_portal_firebase_identity_context($identity);
            bx_portal_login_with_firebase_identity($context, $identity);
            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'administrator_group_member' => !empty($context['administratorGroupMember']),
                    'auth_audience' => 'rbms-portal',
                ],
                'message' => 'Signed in to the User Portal.',
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => 'Portal sign-in could not be completed.'], 401);
        }
    }

    if ($action === 'portal_resolve_login') {
        try {
            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'firebase_identifier' => bx_portal_resolve_firebase_identifier((string) ($_POST['login'] ?? '')),
                ],
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => 'Portal sign-in could not be completed.'], 401);
        }
    }

    if ($action === 'record_portal_login_failure') {
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $reason = trim((string) ($_POST['reason'] ?? 'firebase_auth_failed'));
        if ($login !== '' && preg_match('/^[a-z0-9_\/-]{1,80}$/', $reason) === 1) {
            bx_project_user_activity_history(null, $login, 'LOGIN', 'FAILED', $reason);
        }
        bx_portal_json_response(['ok' => true]);
    }

    if ($action === 'login_portal') {
        bx_flash('Portal sign-in now uses Firebase Authentication. Please use the Firebase sign-in form.', 'error');
        bx_portal_redirect();
    }

    if ($action === 'logout_portal') {
        bx_portal_logout();
        bx_flash('Signed out of the User Portal.', 'success');
        bx_portal_redirect();
    }

    if ($action === 'save_portal_task_notification_sound') {
        try {
            $authorization = bx_portal_require_authorization([], true);
            $taskSoundKey = trim((string) ($_POST['task_sound_key'] ?? $_POST['sound_key'] ?? ''));
            $messengerSoundKey = trim((string) ($_POST['messenger_sound_key'] ?? ''));
            if (!preg_match('/^ding_sound_(0[1-9]|1[0-2])$/', $taskSoundKey)) {
                throw new RuntimeException('Invalid task notification sound.');
            }
            if (!preg_match('/^ding_sound_(0[1-9]|1[0-2])$/', $messengerSoundKey)) {
                throw new RuntimeException('Invalid Messenger notification sound.');
            }
            $taskVolumePercent = (int) ($_POST['task_volume_percent'] ?? $_POST['volume_percent'] ?? 100);
            $messengerVolumePercent = (int) ($_POST['messenger_volume_percent'] ?? 100);
            if (!in_array($taskVolumePercent, [25, 50, 75, 100], true)) {
                throw new RuntimeException('Invalid task notification volume.');
            }
            if (!in_array($messengerVolumePercent, [25, 50, 75, 100], true)) {
                throw new RuntimeException('Invalid Messenger notification volume.');
            }

            $userKey = trim((string) ($authorization['user']['user_key'] ?? ''));
            bx_db()->Execute(
                'UPDATE project_user SET user_task_notification_sound = ?, user_task_notification_volume = ?, user_messenger_notification_sound = ?, user_messenger_notification_volume = ?, user_updated_at = CURRENT_TIMESTAMP WHERE user_key = ? AND user_status = \'ACTIVE\'',
                [$taskSoundKey, $taskVolumePercent, $messengerSoundKey, $messengerVolumePercent, $userKey, $userKey]
            );
            $savedPreference = bx_db()->GetRow(
                'SELECT user_task_notification_sound, user_task_notification_volume, user_messenger_notification_sound, user_messenger_notification_volume FROM project_user WHERE user_key = ? AND user_status = \'ACTIVE\' LIMIT 1',
                [$userKey]
            );
            $savedTaskSoundKey = (string) ($savedPreference['user_task_notification_sound'] ?? '');
            $savedTaskVolumePercent = (int) ($savedPreference['user_task_notification_volume'] ?? 0);
            $savedMessengerSoundKey = (string) ($savedPreference['user_messenger_notification_sound'] ?? '');
            $savedMessengerVolumePercent = (int) ($savedPreference['user_messenger_notification_volume'] ?? 0);
            if ($savedTaskSoundKey !== $taskSoundKey || $savedTaskVolumePercent !== $taskVolumePercent || $savedMessengerSoundKey !== $messengerSoundKey || $savedMessengerVolumePercent !== $messengerVolumePercent) {
                throw new RuntimeException('The notification preferences could not be read back after saving.');
            }

            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'task_notification_sound' => $savedTaskSoundKey,
                    'task_notification_volume' => $savedTaskVolumePercent,
                    'messenger_notification_sound' => $savedMessengerSoundKey,
                    'messenger_notification_volume' => $savedMessengerVolumePercent,
                ],
                'message' => 'Notification preferences saved.',
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    if ($action === 'portal_firebase_custom_token') {
        try {
            $authorization = bx_portal_require_authorization([], true);
            $firebaseConfig = bx_portal_firebase_web_config();
            if (($firebaseConfig['enabled'] ?? false) !== true || ($firebaseConfig['clientStreamEnabled'] ?? false) !== true) {
                throw new RuntimeException('Firebase portal streaming is disabled.');
            }

            bx_portal_json_response([
                'ok' => true,
                'data' => ['custom_token' => bx_portal_firebase_custom_token($authorization)],
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    if ($action === 'messenger_load_members') {
        $groupKey = trim((string) ($_POST['group_key'] ?? ''));
        try {
            $members = bx_messenger_group_users($groupKey);
            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'members' => $members,
                ],
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    if ($action === 'messenger_stream_status') {
        try {
            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'stream_status' => bx_messenger_stream_service_status(),
                ],
            ]);
        } catch (Throwable $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        }
    }

    if ($action === 'create_ai_task') {
        $currentUserForAction = bx_portal_require_authorization([], true)['user'];

        $task = null;
        try {
            $text = trim((string) ($_POST['text'] ?? ''));
            $taskStore = new \BuilderX\AI\AiTaskStore();
            $route = (new \BuilderX\AI\CoordinatorRouter(new \BuilderX\AI\AiSpecialistRegistry()))->route('rephrase_text', 'Validate', 'grammar');
            if (($route['route_status'] ?? '') !== 'routed') {
                bx_portal_json_response(['ok' => false, 'message' => 'No approved rephrase specialist is available.', 'data' => ['registration_proposal' => $route['registration_proposal'] ?? null]], 409);
            }
            $task = $taskStore->create(
                'rephrase_text',
                'Validate',
                (string) $route['specialist_key'],
                ['text' => $text, 'style_profile' => 'clear-and-correct', 'context_refs' => [], 'target_chat_id' => builderxConfigValue('codex_chat_id')],
                ['write_scope' => 'communication_only', 'allowed_paths' => []],
                null,
                null,
                (string) $currentUserForAction['user_key']
            );

            $communication = new \BuilderX\AI\CommunicationMessageStore(
                __DIR__ . '/storage/codex-communication'
            );
            $communication->write([
                'message_id' => (string) $task['task_id'],
                'correlation_id' => (string) $task['correlation_id'],
                'message_type' => 'ai_task',
                'direction' => 'builderx_to_codex',
                'sender' => 'builderx',
                'recipient' => 'codex_desktop',
                'status' => 'queued',
                'payload' => [
                    'task_id' => $task['task_id'],
                    'correlation_id' => $task['correlation_id'],
                    'action' => $task['action'],
                    'stage' => $task['stage'],
                    'specialist' => $task['specialist'],
                    'status' => $task['status'],
                    'input' => $task['input'],
                    'permissions' => $task['permissions'],
                    'target_chat_id' => $task['input']['target_chat_id'] ?? null,
                    'attempt' => $task['attempt'],
                ],
            ], 'inbox');

            bx_portal_json_response([
                'ok' => true,
                'data' => [
                    'task_id' => $task['task_id'],
                    'status' => $task['status'],
                ],
            ], 202);
        } catch (Throwable $exception) {
            if (is_array($task) && !empty($task['task_id'])) {
                try {
                    $taskStore->transition((string) $task['task_id'], 'failed', null, [
                        'code' => 'dispatch_failed',
                        'message' => 'The task could not be dispatched to Codex Desktop.',
                        'retryable' => true,
                    ]);
                } catch (Throwable) {
                    // Preserve the original safe response if failure persistence also fails.
                }
            }
            bx_portal_json_response(['ok' => false, 'message' => 'The AI task could not be queued.'], 503);
        }
    }

    if (in_array($action, ['propose_specialist', 'approve_specialist', 'propose_memory', 'approve_memory'], true)) {
        $currentUserForAction = bx_portal_require_authorization(['requireAdmin' => true], true)['user'];

        try {
            if ($action === 'propose_specialist') {
                $csv = static function (string $value): array {
                    return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
                };
                $proposal = (new \BuilderX\AI\AiSpecialistRegistry())->propose(
                    (string) ($_POST['specialist_key'] ?? ''),
                    (string) ($_POST['specialist_name'] ?? ''),
                    (string) ($_POST['specialist_purpose'] ?? ''),
                    $csv((string) ($_POST['specialist_stages'] ?? 'Validate')),
                    $csv((string) ($_POST['specialist_skills'] ?? '')),
                    $csv((string) ($_POST['specialist_tools'] ?? 'read_files')),
                    (string) ($_POST['specialist_write_scope'] ?? 'none'),
                    $csv((string) ($_POST['specialist_rag_scopes'] ?? '')),
                    isset($_POST['specialist_temporary']),
                    ['submitted_via' => 'coordinator-management'],
                    (string) $currentUserForAction['user_key']
                );
                bx_portal_json_response(['ok' => true, 'data' => ['specialist' => $proposal]], 201);
            }

            if ($action === 'approve_specialist') {
                $approved = (new \BuilderX\AI\AiSpecialistRegistry())->approve((string) ($_POST['specialist_key'] ?? ''), 'phase-manager-ui-' . (string) $currentUserForAction['user_key']);
                bx_portal_json_response(['ok' => true, 'data' => ['specialist' => $approved]]);
            }

            if ($action === 'propose_memory') {
                $memory = (new \BuilderX\AI\MemoryStore())->propose(
                    (string) ($_POST['memory_title'] ?? ''),
                    (string) ($_POST['memory_content'] ?? ''),
                    (string) ($_POST['memory_type'] ?? 'instruction'),
                    ['keyword', 'hybrid', 'metadata'],
                    array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['memory_tags'] ?? ''))), static fn (string $item): bool => $item !== '')),
                    ['submitted_via' => 'coordinator-management', 'project' => 'current'],
                    'coordinator-management',
                    null,
                    (string) $currentUserForAction['user_key']
                );
                bx_portal_json_response(['ok' => true, 'data' => ['memory' => $memory]], 201);
            }

            $approvedMemory = (new \BuilderX\AI\MemoryStore())->approve((string) ($_POST['memory_id'] ?? ''), (string) $currentUserForAction['user_key']);
            bx_portal_json_response(['ok' => true, 'data' => ['memory' => $approvedMemory]]);
        } catch (InvalidArgumentException $error) {
            bx_portal_json_response(['ok' => false, 'message' => $error->getMessage()], 422);
        } catch (Throwable) {
            bx_portal_json_response(['ok' => false, 'message' => 'The Coordinator management action could not be completed.'], 500);
        }
    }

    if ($action === 'save_family_member') {
        $currentUserForAction = bx_portal_require_authorization()['user'];

    bx_portal_save_family_member($currentUserForAction);
    bx_portal_redirect();
    }
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_task_status') {
    $currentUserForStatus = bx_portal_require_authorization([], true)['user'];

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    try {
        $taskStore = new \BuilderX\AI\AiTaskStore();
        $communication = new \BuilderX\AI\CommunicationMessageStore(__DIR__ . '/storage/codex-communication');
        $task = bx_ai_task_status_read($taskId, (string) $currentUserForStatus['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStatus));
    } catch (Throwable) {
        $task = null;
    }
    if (!$task) {
        bx_portal_json_response(['ok' => false, 'message' => 'The AI task was not found.'], 404);
    }

    bx_portal_json_response(['ok' => true, 'data' => ['task' => $task]]);
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_task_status_stream') {
    $currentUserForStream = bx_portal_require_authorization([], true)['user'];

    $taskId = trim((string) ($_GET['task_id'] ?? ''));
    $taskStore = new \BuilderX\AI\AiTaskStore();
    $communication = new \BuilderX\AI\CommunicationMessageStore(__DIR__ . '/storage/codex-communication');
    try {
        // The owner check happens before the stream starts, so an invalid task
        // never becomes a long-lived authenticated connection.
        $task = bx_ai_task_status_read($taskId, (string) $currentUserForStream['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStream));
    } catch (Throwable) {
        $task = null;
    }
    if ($task === null) {
        bx_portal_json_response(['ok' => false, 'message' => 'The AI task was not found.'], 404);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    set_time_limit(30);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    ob_implicit_flush(true);

    $lastFingerprint = '';
    $lastHeartbeatAt = microtime(true);
    $deadline = microtime(true) + 25.0;
    while (microtime(true) < $deadline) {
        try {
            $nextTask = bx_ai_task_status_read($taskId, (string) $currentUserForStream['user_key'], $taskStore, $communication, bx_is_admin($currentUserForStream));
        } catch (Throwable) {
            $nextTask = null;
        }

        if ($nextTask === null) {
            echo "event: error\ndata: {\"message\":\"The AI task status could not be read.\"}\n\n";
            flush();
            break;
        }

        $fingerprint = hash('sha256', (string) json_encode([
            'status' => $nextTask['status'] ?? null,
            'delivery_status' => $nextTask['delivery_status'] ?? null,
            'output' => $nextTask['output'] ?? null,
            'error' => $nextTask['error'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($fingerprint !== $lastFingerprint) {
            echo "event: task\ndata: " . json_encode($nextTask, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
            flush();
            $lastFingerprint = $fingerprint;
        }

        if (in_array((string) ($nextTask['status'] ?? ''), ['completed', 'failed', 'cancelled'], true)) {
            break;
        }
        if (microtime(true) - $lastHeartbeatAt >= 5.0) {
            echo ": keepalive\n\n";
            flush();
            $lastHeartbeatAt = microtime(true);
        }
        if (connection_aborted()) {
            break;
        }
        usleep(250000);
    }
    exit;
}

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'ai_specialists') {
    bx_portal_require_authorization(['requireAdmin' => true], true);
    bx_portal_json_response([
        'ok' => true,
        'data' => [
            'specialists' => (new \BuilderX\AI\AiSpecialistRegistry())->listAll(),
            'memories' => (new \BuilderX\AI\MemoryStore())->listRecent(),
        ],
    ]);
}

$softwareName = bx_setting('software_name', 'BuilderX');
$hasAdministrator = bx_count('builder_user') > 0;
    $currentUser = bx_portal_current_user();
    $isAdmin = false;
    $portalAuthorization = bx_portal_authorization_guard(['requireAuthenticated' => false, 'requireTenant' => false]);
$portalMediaProjectKey = (string) (($portalAuthorization['projectKeys'][0] ?? '') ?: '');
$projectBasePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$projectBasePath = ($projectBasePath === '' ? '' : $projectBasePath) . '/';
$portalMode = builderxConfigValue('portal_mode');
$localConfigPath = __DIR__ . '/phases/config.local.php';
$localConfig = is_file($localConfigPath) ? require $localConfigPath : [];
if (!is_array($localConfig) || !array_key_exists('portal_mode', $localConfig)) {
    $portalMode = $projectBasePath === '/my_builderx_project24/' ? 'product' : 'starter';
}
if ($portalMode === '') {
    $portalMode = 'starter';
}
$requestedPortalView = (string) ($_GET['portal_view'] ?? 'dashboard');
$portalView = in_array($requestedPortalView, ['dashboard', 'bed-management', 'signin'], true) ? $requestedPortalView : 'dashboard';
$bedLookupFilters = bx_bed_lookup_filters_from_request();

if ($requestMethod === 'GET' && (string) ($_GET['action'] ?? '') === 'portal_bed_lookup_refresh') {
    bx_portal_require_authorization([], true);
    bx_portal_json_response([
        'ok' => true,
        'data' => bx_portal_bed_lookup_payload($bedLookupFilters),
    ]);
}

$flash = bx_take_flash();

$manifestPath = __DIR__ . '/frontend/dist/.vite/manifest.json';
$manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
$assetsBase = './frontend/dist/';
bx_ensure_project_messenger_schema();
$projectGroups = bx_db()->GetAll("
    SELECT
        g.group_key,
        g.project_key,
        g.group_name,
        g.group_description,
        g.group_status,
        COALESCE(g.group_image_path, '') AS group_image_path,
        COALESCE(g.group_image_original_name, '') AS group_image_original_name,
        COALESCE(g.group_image_mime_type, '') AS group_image_mime_type,
        COALESCE(g.group_image_byte_size, 0) AS group_image_byte_size,
        COALESCE(g.group_image_sha256, '') AS group_image_sha256,
        COALESCE(DATE_FORMAT(g.group_image_uploaded_at, '%Y-%m-%d %H:%i:%s'), '') AS group_image_uploaded_at,
        COALESCE(p.project_code, '') AS project_code,
        COALESCE(p.project_name, '') AS project_name,
        COALESCE(DATE_FORMAT(latest.latest_message_at, '%Y-%m-%d %H:%i:%s'), '') AS latest_message_at
    FROM project_user_group g
    LEFT JOIN builder_project p ON p.project_key = g.project_key
    LEFT JOIN (
        SELECT group_key, MAX(created_at) AS latest_message_at
        FROM project_messenger_chat
        GROUP BY group_key
    ) latest ON latest.group_key = g.group_key
    WHERE g.group_status = 'ACTIVE'
    ORDER BY p.project_code ASC, g.group_name ASC
");
bx_ensure_project_task_schema();
$portalProjectTasks = bx_db()->GetAll("
    SELECT
        task_key,
        COALESCE(task_code, '') AS task_code,
        task_title,
        COALESCE(task_description, '') AS task_description,
        task_type,
        task_status,
        task_color_hex,
        task_can_run_manually,
        task_can_run_if_bed_vacant,
        task_can_run_if_bed_occupied,
        task_requires_bed_treatment,
        task_requires_admission_source,
        task_priority,
        COALESCE(task_group_keys, '[]') AS task_group_keys,
        COALESCE(task_color_hex, '#00000000') AS task_color_hex,
        COALESCE(task_sort_order, 0) AS task_sort_order
    FROM project_task
    WHERE task_type IN ('PRIMARY', 'SECONDARY')
      AND task_status = 'ACTIVE'
      AND task_can_run_manually = 1
    ORDER BY task_sort_order ASC, updated_at DESC, x_id DESC
");
$portalProjectTasks = array_map(static function (array $task): array {
    $firstStage = bx_project_task_first_active_stage((string) ($task['task_key'] ?? ''));
    return $task + [
        'current_task_stage_key' => (string) ($firstStage['task_stage_key'] ?? ''),
        'current_stage_label' => (string) ($firstStage['stage_label'] ?? ''),
        'current_stage_color_hex' => (string) ($firstStage['stage_color_hex'] ?? '#00000000'),
    ];
}, is_array($portalProjectTasks) ? $portalProjectTasks : []);
$portalBedLookupPayload = bx_portal_bed_lookup_payload($bedLookupFilters);

$payload = [
    'csrf' => bx_csrf_token(),
    'softwareName' => $softwareName,
    'projectBasePath' => $projectBasePath,
    'portalMode' => $portalMode !== '' ? $portalMode : 'product',
    'portalView' => $portalView,
    'hasAdministrator' => $hasAdministrator,
    'isAdmin' => $isAdmin,
    'sharinganEnabled' => bx_setting('sharingan_enabled', '0') === '1',
    'bedMasterListSummary' => bx_bed_master_list_summary(),
    'bedLookupFilters' => $bedLookupFilters,
    'bedLookupOptions' => $portalBedLookupPayload['bedLookupOptions'],
    'bedLookupRows' => $portalBedLookupPayload['bedLookupRows'],
    'projectBedTasks' => $portalBedLookupPayload['projectBedTasks'],
    'projectBedTaskLogs' => $portalBedLookupPayload['projectBedTaskLogs'],
    'bedTreatments' => bx_project_bed_treatment_rows(true),
    'bedSources' => bx_project_bed_source_rows(true),
    'projectTasks' => is_array($portalProjectTasks) ? $portalProjectTasks : [],
    'projectGroups' => is_array($projectGroups) ? $projectGroups : [],
    'messengerSenderKey' => bx_messenger_sender_key($currentUser ?: null),
    'firebaseConfig' => bx_portal_firebase_web_config(),
    'mediaUploaderTargetUrl' => bx_project_media_setting($portalMediaProjectKey, 'media_uploader_target_url', ''),
    'mediaImageViewerUrl' => bx_project_media_setting($portalMediaProjectKey, 'media_image_viewer_url', ''),
    'flash' => $flash,
    'currentUser' => bx_user_public_projection($currentUser),
    'familyMembers' => bx_portal_family_members($currentUser ?: null),
];
$payload['operationalWorkspace'] = bx_portal_operational_workspace($currentUser ?: null, $payload['familyMembers']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bx_h($softwareName) ?></title>
    <?php if ($entry && !empty($entry['css'])): ?>
        <?php foreach ($entry['css'] as $css): ?>
            <link rel="stylesheet" href="<?= bx_h($assetsBase . $css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
        window.__BUILDERX_PORTAL__ = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>
<body>
    <div id="root">
        <?php if (!$entry): ?>
            <main style="max-width: 760px; margin: 40px auto; font-family: Arial, Helvetica, sans-serif;">
                <h1><?= bx_h($softwareName) ?></h1>
                <p>The shared React frontend is not built yet. Run <code>npm run build</code> in <code>frontend</code>.</p>
            </main>
        <?php endif; ?>
    </div>
    <?php if ($entry): ?>
        <script type="module" src="<?= bx_h($assetsBase . $entry['file']) ?>"></script>
    <?php endif; ?>
</body>
</html>
