<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');

if (!is_string($foundationSource) || !is_string($adminSource)) {
    throw new RuntimeException('User Firebase key sources could not be read.');
}

$requiredFoundationMarkers = [
    'function bx_unique_firebase_document_key(string $table, string $column, int $length = 20): string',
    "preg_match('/^[A-Za-z0-9_]+$/', \$table)",
    '$candidate = bx_firebase_document_id($length);',
    "SELECT COUNT(*) FROM {\$table} WHERE {\$column} = ?",
    "\$userKey = bx_unique_firebase_document_key('builder_user', 'user_key');",
    'UPDATE project_user SET user_failed_login_count = ?, user_status = ? WHERE user_key = ?',
    'bx_project_user_firebase_telemetry((string) $user[\'user_key\']',
    'firebase-admin-project-user-history-write.mjs',
    'firebase-admin-project-user-telemetry-write.mjs',
    'CREATE TABLE IF NOT EXISTS project_user_login_history',
    'function bx_project_user_device_label',
    'user_last_logout_device',
    'bx_project_user_activity_history',
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Missing user Firebase key foundation marker: ' . $marker);
    }
}

$saveUserPosition = strpos($adminSource, "\$action === 'save_user'");
$setUserStatusPosition = strpos($adminSource, "\$action === 'set_user_status'", $saveUserPosition === false ? 0 : $saveUserPosition);
if ($saveUserPosition === false || $setUserStatusPosition === false) {
    throw new RuntimeException('Could not locate save_user action chunk.');
}

$saveUserChunk = substr($adminSource, $saveUserPosition, $setUserStatusPosition - $saveUserPosition);

foreach ([
    'bx_admin_write_project_user_firebase_first($firebaseProfile, $password)',
    "'mysql_sync_status' => 'PENDING'",
    "'firebase_ok' => true",
] as $marker) {
    if (!str_contains($saveUserChunk, $marker)) {
        throw new RuntimeException('Missing Firebase-first user write marker: ' . $marker);
    }
}

$firebaseFirstPosition = strpos($saveUserChunk, '$firebaseWrite = bx_admin_write_project_user_firebase_first');
$legacyPosition = strpos($saveUserChunk, '$wasCreatingUser = $userKey === \'\';');
if ($firebaseFirstPosition === false || $legacyPosition === false || $legacyPosition <= $firebaseFirstPosition) {
    throw new RuntimeException('Could not locate Firebase-first save boundary.');
}
$firebaseFirstPath = substr($saveUserChunk, $firebaseFirstPosition, $legacyPosition - $firebaseFirstPosition);
foreach (['INSERT INTO project_user', 'UPDATE project_user SET', 'bx_admin_access_transaction('] as $forbiddenWrite) {
    if (str_contains($firebaseFirstPath, $forbiddenWrite)) {
        throw new RuntimeException('Firebase-first user path still mutates MySQL: ' . $forbiddenWrite);
    }
}

$builderUserSchemaPosition = strpos($foundationSource, 'CREATE TABLE IF NOT EXISTS builder_user (');
$builderGroupSchemaPosition = strpos($foundationSource, 'CREATE TABLE IF NOT EXISTS builder_group (', $builderUserSchemaPosition === false ? 0 : $builderUserSchemaPosition);
if ($builderUserSchemaPosition === false || $builderGroupSchemaPosition === false) {
    throw new RuntimeException('Could not locate builder_user schema chunk.');
}

$builderUserSchema = substr($foundationSource, $builderUserSchemaPosition, $builderGroupSchemaPosition - $builderUserSchemaPosition);

foreach (['firebase_document_id', 'document_id', 'firebase_user_key'] as $forbiddenUserColumn) {
    if (stripos($builderUserSchema, $forbiddenUserColumn) !== false || stripos($saveUserChunk, $forbiddenUserColumn) !== false) {
        throw new RuntimeException('Forbidden separate Firebase document field found for builder_user: ' . $forbiddenUserColumn);
    }
}

foreach ([
    'function bx_admin_sync_project_user_login_identity',
    'bx_admin_sync_project_user_login_identity(',
    'bx_admin_assert_builder_user_login_available',
    'bx_admin_project_user_builder_position_key',
    'bx_admin_project_user_builder_group_key',
    'INSERT INTO builder_user (',
    'UPDATE builder_user SET',
    'INSERT IGNORE INTO builder_user_project',
    'INSERT IGNORE INTO builder_user_branch',
    "UPDATE builder_user_session SET session_status = 'REVOKED'",
    'builder_user_role WHERE user_key = ?',
] as $forbiddenMarker) {
    if (str_contains($adminSource, $forbiddenMarker)) {
        throw new RuntimeException('Project user CRUD must not touch builder_user: ' . $forbiddenMarker);
    }
}

echo json_encode([
    'project_user_key_uses_firebase_document_id' => true,
    'project_user_crud_isolated_from_builder_user' => true,
    'separate_user_firebase_document_field_absent' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
