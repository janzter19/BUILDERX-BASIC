<?php
declare(strict_types=1);

define('BUILDERX_SKIP_SESSION_START', true);
require_once dirname(__DIR__) . '/app/foundation.php';

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$scriptSource = file_get_contents($root . '/scripts/firebase-user-sync.mjs');
$firestoreRules = file_get_contents($root . '/firestore.rules');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource) || !is_string($scriptSource) || !is_string($firestoreRules)) {
    throw new RuntimeException('Project user Firebase sync sources could not be read.');
}

$combinedSource = $foundationSource . "\n" . $adminSource . "\n" . $frontendSource . "\n" . $scriptSource . "\n" . $firestoreRules;
$requiredMarkers = [
    'function bx_admin_write_project_user_firebase_first',
    'firebase-admin-project-user-history-write.mjs',
    'firebase-admin-project-user-telemetry-write.mjs',
    'scripts/firebase-admin-project-user-write.mjs',
    "'mysql_sync_status' => 'PENDING'",
    "'firebase_ok' => true",
    "COALESCE(u.user_auth_username, '') AS user_auth_username",
    "COALESCE(u.user_auth_email, '') AS user_auth_email",
    "user_auth_username: String(row.user_auth_username || '').trim()",
    'user_auth_email: authEmail',
    'project_login_index',
    'user_password_change_required',
    'match /project_user/{userKey}',
    'user_last_login_ip_address',
    'user_last_logout_device',
    'mysqlTimestamp',
];

foreach ($requiredMarkers as $marker) {
    if (!str_contains($combinedSource, $marker)) {
        throw new RuntimeException('Missing project user Firebase sync marker: ' . $marker);
    }
}

foreach ([
    'sync_project_user_firebase',
    'userFirebaseSyncFormRef',
    'Sync users to Firebase',
    'Sync Users To Firebase',
    'function bx_admin_project_user_firebase_step',
] as $removedMarker) {
    if (str_contains($adminSource, $removedMarker) || str_contains($frontendSource, $removedMarker)) {
        throw new RuntimeException('Removed project user Firebase sync artifact remains: ' . $removedMarker);
    }
}

foreach ([
    'function canReadPortalProjectUser',
    'request.auth.token.user_key == request.auth.uid',
    'request.auth.token.tenant_key is string',
    'resourceData.firebase_uid == request.auth.uid',
    'hasProject(resourceData.project_key)',
    "resourceData.user_status == 'ACTIVE'",
    'resourceData.user_password_change_required is bool',
    "resourceData.firebase_collection == 'project_user'",
    "'group_key', 'position_key', 'user_password_hash', 'password', 'user_password'",
    'function canReadPortalProjectGroup',
    "resourceData.group_status == 'ACTIVE'",
    'function canReadPortalProjectPosition',
    "resourceData.position_status == 'ACTIVE'",
    'match /project_user_group/{assignmentKey}',
    'match /project_group/{groupKey}',
    'match /project_position/{positionKey}',
    'allow create, update, delete: if false;',
] as $marker) {
    if (!str_contains($firestoreRules, $marker)) {
        throw new RuntimeException('Missing Portal Firestore identity or safe-migration boundary: ' . $marker);
    }
}

$payloadFunctionStart = strpos($foundationSource, 'function bx_project_user_firebase_rows');
$payloadFunctionEnd = strpos($foundationSource, 'function bx_sync_project_user_rows_to_firebase', $payloadFunctionStart === false ? 0 : $payloadFunctionStart);
if ($payloadFunctionStart === false || $payloadFunctionEnd === false) {
    throw new RuntimeException('Project user Firebase payload helper block could not be located.');
}

$payloadFunctionSource = substr($foundationSource, $payloadFunctionStart, $payloadFunctionEnd - $payloadFunctionStart);
foreach (['user_password_hash', 'user_email'] as $forbiddenMarker) {
    if (str_contains($payloadFunctionSource, $forbiddenMarker)) {
        throw new RuntimeException('Project user Firebase payload helper exposes forbidden field: ' . $forbiddenMarker);
    }
}

if (str_contains($scriptSource, 'user_password_hash') || str_contains($scriptSource, 'user_email')) {
    throw new RuntimeException('Project user Firebase script must not write password hash or email fields.');
}

if (str_contains($scriptSource, 'row.project_code || row.project_key')) {
    throw new RuntimeException('Project user Firebase sync must not use project_code as a project_key substitute.');
}

$loginIndexStart = strpos($scriptSource, 'indexData: {');
$loginIndexEnd = strpos($scriptSource, "},\n    data:", $loginIndexStart === false ? 0 : $loginIndexStart);
if ($loginIndexStart === false || $loginIndexEnd === false) {
    throw new RuntimeException('Login index payload block could not be located.');
}
$loginIndexSource = substr($scriptSource, $loginIndexStart, $loginIndexEnd - $loginIndexStart);
foreach (['user_key', 'firebase_uid', 'user_mobile_number', 'user_name', 'group_key', 'position_key', 'permissions'] as $forbiddenLoginIndexField) {
    if (str_contains($loginIndexSource, $forbiddenLoginIndexField)) {
        throw new RuntimeException('Login index exposes forbidden field: ' . $forbiddenLoginIndexField);
    }
}

$projectKey = (string) (bx_db()->GetOne("SELECT project_key FROM builder_project WHERE project_status <> 'DELETED' ORDER BY x_id ASC LIMIT 1") ?: '');
if ($projectKey !== '') {
    $rows = bx_project_user_firebase_rows($projectKey);
    $encodedRows = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (['user_password_hash', 'user_email'] as $forbiddenField) {
        if (str_contains($encodedRows, $forbiddenField)) {
            throw new RuntimeException('Project user Firebase rows contain forbidden field: ' . $forbiddenField);
        }
    }
} else {
    $rows = [];
}

echo json_encode([
    'project_user_firebase_sync_action_present' => true,
    'project_user_firebase_script_present' => true,
    'project_user_firebase_payload_secret_safe' => true,
    'project_user_firebase_button_posts' => true,
    'project_user_save_syncs_after_submit' => true,
    'payload_row_count' => count($rows),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
