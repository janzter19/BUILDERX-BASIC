<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$portal = file_get_contents($root . '/index.php');
$frontend = file_get_contents($root . '/frontend/src/App.tsx');
$foundation = file_get_contents($root . '/app/foundation.php');

foreach ([$portal, $frontend, $foundation] as $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('source_missing');
    }
}
$identityStart = strpos($portal, 'function bx_portal_firebase_identity_context');
$identityEnd = strpos($portal, 'function bx_portal_resolve_firebase_identifier');
$identitySource = $identityStart !== false && $identityEnd !== false ? substr($portal, $identityStart, $identityEnd - $identityStart) : '';
if ($identitySource === '' || strpos($identitySource, 'JOIN builder_user') !== false || strpos($identitySource, 'builder_user_session') !== false) {
    throw new RuntimeException('portal_identity_still_depends_on_builder_user');
}

$requiredPortal = [
    "if (\$action === 'firebase_login_portal')",
    'bx_admin_verify_firebase_id_token',
    'bx_portal_firebase_identity_context',
    'administratorGroupMember',
    "'auth_audience' => 'rbms-portal'",
];
foreach ($requiredPortal as $marker) {
    if (strpos($portal, $marker) === false) {
        throw new RuntimeException('portal_marker_missing:' . $marker);
    }
}
foreach ([
    "function bx_portal_resolve_firebase_identifier",
    "if (\$action === 'portal_resolve_login')",
    'user_auth_username',
    'user_auth_email',
    "portal_administrator_context_denied",
    "portal_administrator_group_denied",
    "COLUMN_NAME = ?",
    "a.assignment_status = 'ACTIVE'",
    "bx_admin_verify_firebase_id_token((string) (\$_POST['firebase_id_token'] ?? ''), false)",
    "SET firebase_uid = ?, mysql_sync_status = 'PENDING'",
    'portal_identity_mapping_readback_failed',
] as $marker) {
    if (strpos($portal, $marker) === false) {
        throw new RuntimeException('login_alias_marker_missing:' . $marker);
    }
}
foreach ([
    "\$requireEmailVerified && (\$result['email_verified'] ?? false) !== true",
] as $marker) {
    if (strpos($foundation, $marker) === false) {
        throw new RuntimeException('firebase_verifier_marker_missing:' . $marker);
    }
}
foreach ([
    "session_name(\$portalSession ? 'RBMS_PORTAL_SESSION' : 'RBMS_ADMIN_SESSION')",
] as $marker) {
    if (strpos($foundation, $marker) === false) {
        throw new RuntimeException('session_boundary_marker_missing:' . $marker);
    }
}
if (preg_match("/if \(\$action === 'login_portal'\).*?bx_login\(/s", $portal) === 1) {
    throw new RuntimeException('portal_mysql_password_login_still_active');
}

foreach ([
    "action: 'portal_resolve_login'",
    "action: 'firebase_login_portal'",
    'signInWithEmailAndPassword(auth, firebaseIdentifier, password)',
    'firebase_id_token: firebaseIdToken',
    "'portal-auth'",
    'Firebase ${code}: ${safeFirebaseMessage',
    '[REDACTED_SECRET]',
] as $marker) {
    if (strpos($frontend, $marker) === false) {
        throw new RuntimeException('frontend_marker_missing:' . $marker);
    }
}

foreach ([
    "'builderx_portal_firebase_uid'",
    "'builderx_portal_administrator_group'",
    "'authEnabled' => true",
] as $marker) {
    if (strpos($portal . $foundation, $marker) === false) {
        throw new RuntimeException('session_or_config_marker_missing:' . $marker);
    }
}

echo "portal firebase login static checks passed\n";
