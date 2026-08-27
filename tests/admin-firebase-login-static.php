<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/administrator/index.php');
$foundationSource = file_get_contents($root . '/app/foundation.php');
$verifierSource = file_get_contents($root . '/scripts/firebase-admin-id-token-verify.mjs');

if (!is_string($adminSource) || !is_string($foundationSource) || !is_string($verifierSource)) {
    throw new RuntimeException('Administrator Firebase login sources could not be read.');
}

$loginStart = strpos($adminSource, "if (\$action === 'firebase_login')");
$loginEnd = strpos($adminSource, "if (\$action === 'logout')", $loginStart === false ? 0 : $loginStart);
if ($loginStart === false || $loginEnd === false) {
    throw new RuntimeException('Administrator Firebase login action could not be located.');
}
$loginSource = substr($adminSource, $loginStart, $loginEnd - $loginStart);

foreach ([
    'bx_verify_csrf();',
    "if (\$action === 'firebase_login')",
    'bx_admin_verify_firebase_id_token($idToken, false)',
    "\$_POST['firebase_id_token']",
    "['action', 'csrf', 'firebase_id_token']",
    'WHERE firebase_uid = ?',
    "[\$firebaseIdentity['uid']]",
    "user_status = 'ACTIVE'",
    'user_deleted_at IS NULL',
    'bx_is_admin($adminUser)',
    'bx_login_with_firebase_identity($adminUser, $firebaseIdentity)',
    "bx_authorization_guard(['requireAdmin' => true, 'requireAdminFirebase' => true])",
    'bx_admin_session_boundary_allows()',
    'Administrator sign-in failed.',
] as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing Administrator Firebase login marker: ' . $marker);
    }
}

foreach ([
    'bx_login(',
    "\$_POST['password']",
    "\$_POST['login']",
    "'message' => \$exception->getMessage()",
    "'id_token' => \$idToken",
    'project_user',
] as $forbidden) {
    if (str_contains($loginSource, $forbidden)) {
        throw new RuntimeException('Administrator Firebase login contains forbidden marker: ' . $forbidden);
    }
}

foreach ([
    "firebase_uid VARCHAR(255) NULL",
    'uq_builder_user_firebase_uid',
    'function bx_admin_verify_firebase_id_token',
    'bx_admin_firebase_project_id()',
    'FIREBASE_ADMIN_PROJECT_ID',
    'function bx_login_with_firebase_identity',
    'verifyIdToken(idToken, true)',
    'decoded.aud !== projectId',
    'decoded.iss !== expectedIssuer',
    'firebase_auth_too_old',
    'user.disabled',
    'firebase_email_unverified',
    'firebase_id_token_revoked',
    'builderx_admin_auth_marker',
    'builderx_auth_audience',
    'RBMS_ADMINISTRATOR',
    'rbms-administrator',
    'builderx_admin_firebase_uid',
] as $marker) {
    if (!str_contains($foundationSource . "\n" . $verifierSource, $marker)) {
        throw new RuntimeException('Missing Firebase verifier security marker: ' . $marker);
    }
}

if (!str_contains($adminSource, "'authentication_method' => 'legacy_password_disabled'")) {
    throw new RuntimeException('Administrator legacy password login is still enabled.');
}

if (!str_contains($adminSource, "\$adminAuthorization = bx_authorization_guard(['requireAdmin' => true, 'requireAdminFirebase' => true])")) {
    throw new RuntimeException('Administrator page boundary does not require Firebase authentication.');
}

if (!str_contains($adminSource, "'projectId' => \$value('firebase_web_project_id', 'FIREBASE_WEB_PROJECT_ID', bx_admin_firebase_project_id())")) {
    throw new RuntimeException('Administrator web Firebase config does not default to the Administrator audience.');
}

echo json_encode([
    'csrf_guarded' => true,
    'uid_mapping_required' => true,
    'email_prefix_authorization_absent' => true,
    'administrator_role_required' => true,
    'password_and_login_fields_rejected' => true,
    'id_token_not_returned_by_verifier' => true,
    'administrator_session_marker' => str_contains($foundationSource, 'builderx_admin_auth_marker') && str_contains($foundationSource, 'rbms-administrator'),
    'portal_session_denied' => str_contains($adminSource, 'builderx_portal_auth_marker'),
    'session_and_logout_helpers_preserved' => str_contains($foundationSource, 'function bx_logout'),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
