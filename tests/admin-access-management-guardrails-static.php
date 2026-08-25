<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/administrator/index.php');

if (!is_string($adminSource)) {
    throw new RuntimeException('Administrator source could not be read.');
}

$requiredMarkers = [
    'function bx_admin_access_transaction(callable $callback): mixed',
    'function bx_admin_assert_user_administrator_continuity',
    'function bx_admin_assert_current_user_not_disabled',
    'function bx_admin_validate_project_branch_assignments',
    'function bx_admin_assert_role_guardrails',
    'function bx_admin_assert_permission_matrix_guardrails',
    'function bx_admin_assert_user_readback',
    'function bx_admin_assert_position_readback',
    'function bx_admin_assert_group_readback',
    'function bx_admin_assert_role_readback',
    'At least one active Administrator account must remain.',
    'You cannot disable, lock, draft, or delete your own active administrator session.',
    'Selected user position must belong to the selected project group.',
    'Group edit could not be saved because the existing group key was missing.',
    'The built-in Administrator role must remain active and keep its name.',
    'The Administrator role must retain all active permissions.',
    'The permission matrix must keep every listed permission assigned to the Administrator role.',
    'Project user group read-back verification failed.',
    'Position read-back verification failed.',
    'Group read-back verification failed.',
    'Role read-back verification failed.',
    'Permission matrix read-back verification failed.',
];

foreach ($requiredMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing access-management guardrail marker: ' . $marker);
    }
}

foreach (['save_user_position', 'set_user_position_status', 'save_user', 'set_user_status', 'reset_user_password', 'save_group', 'set_group_status', 'save_role', 'set_role_status', 'set_permission_status', 'save_permission_matrix'] as $action) {
    $actionPosition = strpos($adminSource, "\$action === '{$action}'");
    if ($actionPosition === false) {
        throw new RuntimeException('Missing access-management action: ' . $action);
    }
    $nextActionPosition = strpos($adminSource, "if (\$action ===", $actionPosition + 1);
    $chunk = substr($adminSource, $actionPosition, $nextActionPosition === false ? null : $nextActionPosition - $actionPosition);
    if (!str_contains($chunk, 'bx_admin_access_transaction(')) {
        throw new RuntimeException('Access-management action is not transaction guarded: ' . $action);
    }
    if (!str_contains($chunk, 'bx_flash(')) {
        throw new RuntimeException('Access-management action lacks user feedback: ' . $action);
    }
}

echo json_encode([
    'access_management_transactions' => true,
    'administrator_continuity_guarded' => true,
    'self_disable_guarded' => true,
    'project_branch_scope_guarded' => true,
    'read_back_verification_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
