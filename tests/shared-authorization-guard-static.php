<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundation = (string) file_get_contents($root . '/app/foundation.php');
$portal = (string) file_get_contents($root . '/index.php');
$administrator = (string) file_get_contents($root . '/administrator/index.php');
$bridge = (string) file_get_contents($root . '/ai-bridge/index.php');
$sharingan = (string) file_get_contents($root . '/sharingan.php');
$backendAuthenticate = (string) file_get_contents($root . '/backend/app/Http/Middleware/Authenticate.php');
$backendPermission = (string) file_get_contents($root . '/backend/app/Http/Middleware/CheckPermission.php');
$backendBranch = (string) file_get_contents($root . '/backend/app/Http/Middleware/CheckBranchAccess.php');
$backendProject = (string) file_get_contents($root . '/backend/app/Http/Middleware/CheckProjectAccess.php');

$requiredFoundationMarkers = [
    'function bx_authorization_guard(array $requirements = []): array',
    "JOIN builder_user u ON u.user_key = s.user_key",
    "s.session_token_hash = ?",
    "s.session_status = 'ACTIVE'",
    "s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP",
    "u.user_status = 'ACTIVE'",
    "u.user_deleted_at IS NULL",
    "JOIN builder_group g ON g.group_key = ug.group_key AND g.group_status = 'ACTIVE'",
    "JOIN builder_branch b ON b.branch_key = ub.branch_key AND b.branch_status = 'ACTIVE'",
    "SELECT b.branch_key, b.branch_name, b.branch_code",
    "'branchNames' => \$context['branchNames'] ?? []",
    "'branchCodes' => \$context['branchCodes'] ?? []",
    "\$context['branchNames']",
    "\$context['branchCodes']",
    "JOIN builder_project p ON p.project_key = up.project_key AND p.project_status = 'ACTIVE'",
    "JOIN builder_user_branch pb ON pb.user_key = up.user_key AND pb.branch_key = p.branch_key",
    "'projectBranchKeys' => \$context['projectBranchKeys'] ?? []",
    "return bx_authorization_result(false, 'tenant_required', 'Request not authorized.', \$user, \$context);",
    "return bx_authorization_result(true, 'authorized', 'Authorized.', \$user, \$context);",
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundation, $marker)) {
        throw new RuntimeException('Shared authorization guard marker missing: ' . $marker);
    }
}

$callSiteMarkers = [
    'index.php' => [
        'function bx_portal_require_authorization(array $requirements = [], bool $json = false): array',
        "bx_portal_require_authorization(['requireAdmin' => true], true)",
        "\$currentUserForAction = bx_portal_require_authorization()['user'];",
    ],
    'administrator/index.php' => [
        'function bx_admin_require_authorization(array $requirements = []): array',
        "\$authorization = bx_authorization_guard(['requireAdmin' => true]);",
        "\$currentUser = bx_admin_require_authorization(['requireAdmin' => true])['user'];",
    ],
    'ai-bridge/index.php' => [
        "\$authorization = bx_authorization_guard(['requireAdmin' => true]);",
        "bx_authorization_status_code(\$authorization)",
    ],
    'sharingan.php' => [
        "function bx_sharingan_authorized_surface(array \$source, array \$authorization): array",
        "\$authorization = bx_authorization_guard(['requireAuthenticated' => true]);",
        "bx_authorization_missing(['Administrator']",
    ],
];

foreach ($callSiteMarkers as $file => $markers) {
    $source = match ($file) {
        'index.php' => $portal,
        'administrator/index.php' => $administrator,
        'ai-bridge/index.php' => $bridge,
        'sharingan.php' => $sharingan,
    };
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException($file . ' guard call-site marker missing: ' . $marker);
        }
    }
}

$backendMiddlewareMarkers = [
    'backend/app/Http/Middleware/Authenticate.php' => [
        "'requireAuthenticated' => true",
        'return \bx_authorization_guard($requirements);',
        'bx_authorization_status_code($authorization)',
    ],
    'backend/app/Http/Middleware/CheckPermission.php' => [
        "'permissionCodes' => \$permissionCodes",
        'return \bx_authorization_guard($requirements);',
        'bx_authorization_status_code($authorization)',
    ],
    'backend/app/Http/Middleware/CheckBranchAccess.php' => [
        "'branchKeys' => \$this->branchKeys(\$request, \$branchKey)",
        'return \bx_authorization_guard($requirements);',
        'bx_authorization_status_code($authorization)',
    ],
    'backend/app/Http/Middleware/CheckProjectAccess.php' => [
        "'projectKeys' => \$this->projectKeys(\$request, \$projectKey)",
        'return \bx_authorization_guard($requirements);',
        'bx_authorization_status_code($authorization)',
    ],
];

foreach ($backendMiddlewareMarkers as $file => $markers) {
    $source = match ($file) {
        'backend/app/Http/Middleware/Authenticate.php' => $backendAuthenticate,
        'backend/app/Http/Middleware/CheckPermission.php' => $backendPermission,
        'backend/app/Http/Middleware/CheckBranchAccess.php' => $backendBranch,
        'backend/app/Http/Middleware/CheckProjectAccess.php' => $backendProject,
    };
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException($file . ' authorization middleware marker missing: ' . $marker);
        }
    }
}

echo json_encode([
    'shared_guard_declared' => true,
    'active_token_session_required' => true,
    'active_account_required' => true,
    'tenant_assignment_required' => true,
    'tenant_assignment_reads_parameterized' => true,
    'protected_surfaces_bound' => ['User Portal', 'Administrator Portal', 'AI Bridge', 'Sharingan', 'Backend middleware'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
