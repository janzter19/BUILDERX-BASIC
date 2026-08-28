<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$scriptSource = file_get_contents($root . '/scripts/firebase-user-sync.mjs');
$authScriptSource = file_get_contents($root . '/scripts/firebase-auth-user-sync.mjs');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource) || !is_string($scriptSource) || !is_string($authScriptSource)) {
    throw new RuntimeException('Project user auth email sources could not be read.');
}

$requiredFoundationMarkers = [
    'function bx_project_user_auth_username',
    'user_auth_username VARCHAR(40) NULL',
    'user_auth_email VARCHAR(190) NULL',
    "bx_add_column_if_missing('project_user', 'user_auth_username'",
    "bx_add_column_if_missing('project_user', 'user_auth_email'",
    "bx_add_index_if_missing('project_user', 'uq_project_user_auth_username'",
    "bx_add_index_if_missing('project_user', 'uq_project_user_auth_email'",
    "COALESCE(u.user_auth_username, '') AS user_auth_username",
    "COALESCE(u.user_auth_email, '') AS user_auth_email",
    "setting_name = 'project_user_auth_email_domain'",
    'SET user_login = LOWER(TRIM(user_login))',
    'BINARY user_login <> BINARY LOWER(TRIM(user_login))',
    '$authUsername = bx_project_user_auth_username();',
    "SET user_auth_email = CONCAT",
    'function bx_sync_project_user_auth_to_firebase',
    'function bx_sync_project_user_auth_rows_to_firebase',
    'function bx_admin_write_project_user_firebase_first',
    'scripts/firebase-auth-user-sync.mjs',
];

$requiredAdminMarkers = [
    'function bx_admin_normalize_project_username',
    'function bx_admin_project_username_validation_error',
    'function bx_admin_project_user_auth_email_domain',
    'function bx_admin_project_user_auth_email',
    'function bx_admin_project_user_default_password',
    "['project_user_auth_email_domain', 'rbms.app', 'security']",
    '$userLogin = bx_admin_normalize_project_username',
    '$userLoginError = bx_admin_project_username_validation_error($userLogin);',
    "preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', \$userLogin)",
    "preg_match('/[._-]{2,}/', \$userLogin)",
    '$password = bx_admin_project_user_default_password();',
    "if (\$action === 'reset_user_password')",
    '$password = bx_admin_project_user_default_password();',
    "Password reset requires a user.",
    'Password reset in Firebase Auth. The user must change it at next sign-in.',
    '$firebaseReset = bx_admin_write_project_user_firebase_first($firebaseResetProfile, $password);',
    'Firebase Auth did not acknowledge the change.',
    '$userAuthUsername = trim((string) ($existing[\'user_auth_username\'] ?? \'\'));',
    '$userAuthUsername = bx_project_user_auth_username();',
    'user_auth_email = ?',
    'INSERT INTO project_user (user_key, project_key, group_key, user_login, user_auth_username, user_auth_email',
    'Project user Firebase Auth identity read-back verification failed.',
    'function bx_admin_project_user_auth_step',
];

$requiredFrontendMarkers = [
    'function normalizeProjectUsernameInput',
    'function projectUsernameValidationMessage',
    'setUserFormLogin(normalizeProjectUsernameInput',
    'setCustomValidity(projectUsernameValidationMessage',
    'aria-describedby="user_login_help"',
    'id="user_login_help"',
    'Reset ${user.user_login} to the automatic default password and clear failed login attempts?',
    'pattern="[a-z0-9](?:[a-z0-9._-]{1,78}[a-z0-9])?"',
];

$requiredScriptMarkers = [
    "const authEmail = requireString(row.user_auth_email, 'user_auth_email').toLowerCase()",
    'user_auth_username: String(row.user_auth_username || \'\').trim()',
    'user_auth_email: authEmail',
    "collection('project_user')",
];

$requiredAuthScriptMarkers = [
    "import { getAuth } from 'firebase-admin/auth'",
    'async function upsertAuthUser',
    'const createPassword =',
    'const updatePassword =',
    'uid,',
    'email,',
    'password: requireString(createPassword, \'password\')',
    'await auth.createUser',
    'await auth.updateUser',
    'user_key_invalid_firebase_uid',
    'syncAuthorizationClaims',
    'setCustomUserClaims',
    'firebase_auth_claims_readback_failed',
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Missing project user auth email schema marker: ' . $marker);
    }
}

foreach ($requiredAdminMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing project user auth email server marker: ' . $marker);
    }
}

foreach ($requiredFrontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Missing project user auth email UI marker: ' . $marker);
    }
}

foreach ($requiredScriptMarkers as $marker) {
    if (!str_contains($scriptSource, $marker)) {
        throw new RuntimeException('Missing project user auth email Firebase script marker: ' . $marker);
    }
}

foreach ($requiredAuthScriptMarkers as $marker) {
    if (!str_contains($authScriptSource, $marker)) {
        throw new RuntimeException('Missing project user Firebase Auth script marker: ' . $marker);
    }
}

$saveUserStart = strpos($adminSource, "if (\$action === 'save_user')");
$saveUserEnd = strpos($adminSource, "if (\$action === 'set_user_status')", $saveUserStart === false ? 0 : $saveUserStart);
if ($saveUserStart === false || $saveUserEnd === false) {
    throw new RuntimeException('Project user save block could not be located.');
}

$saveUserSource = substr($adminSource, $saveUserStart, $saveUserEnd - $saveUserStart);
if (str_contains($saveUserSource, 'builder_user')) {
    throw new RuntimeException('Project user save must not mutate builder_user.');
}

$userDialogStart = strpos($frontendSource, '<form key={editingUser?.user_key || \'new-user\'}');
$userDialogEnd = strpos($frontendSource, '<DialogFooter', $userDialogStart === false ? 0 : $userDialogStart);
if ($userDialogStart === false || $userDialogEnd === false) {
    throw new RuntimeException('Project user dialog source block could not be located.');
}

$userDialogSource = substr($frontendSource, $userDialogStart, $userDialogEnd - $userDialogStart);
foreach ([
    'name="password"',
    'name="password_confirm"',
    'user_auth_username_preview',
    'user_auth_email_preview',
    'Auth Username',
    'Auth Email',
] as $hiddenMarker) {
    if (str_contains($userDialogSource, $hiddenMarker)) {
        throw new RuntimeException('Project user dialog exposes hidden identity/password field: ' . $hiddenMarker);
    }
}

$resetPasswordStart = strpos($frontendSource, '<form ref={resetFormRef}');
$resetPasswordEnd = strpos($frontendSource, 'function UserStatusButton(', $resetPasswordStart === false ? 0 : $resetPasswordStart);
if ($resetPasswordStart === false || $resetPasswordEnd === false) {
    throw new RuntimeException('Project user reset password form source block could not be located.');
}

$resetPasswordSource = substr($frontendSource, $resetPasswordStart, $resetPasswordEnd - $resetPasswordStart);
foreach ([
    'data-skip-submit-confirmation="true"',
    'name="action" value="reset_user_password"',
] as $resetHiddenMarker) {
    if (str_contains($resetPasswordSource, $resetHiddenMarker)) {
        continue;
    }
    throw new RuntimeException('Project user reset form is missing marker: ' . $resetHiddenMarker);
}

foreach ([
    'name="password"',
    'reset_password',
    'New Password',
] as $resetHiddenMarker) {
    if (str_contains($resetPasswordSource, $resetHiddenMarker)) {
        throw new RuntimeException('Project user reset form exposes manual password field: ' . $resetHiddenMarker);
    }
}

echo json_encode([
    'project_user_lowercase_username_enforced' => true,
    'project_user_auth_email_generated' => true,
    'project_user_auth_username_permanent' => true,
    'project_user_firebase_auth_sync_present' => true,
    'project_user_default_password_preserved' => true,
    'builder_user_untouched_by_project_user_save' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
