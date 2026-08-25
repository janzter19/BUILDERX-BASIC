<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');

if (!is_string($adminSource) || !is_string($frontendSource)) {
    throw new RuntimeException('Administrator management source could not be read.');
}

$requiredAdminMarkers = [
    "'managementReadiness' =>",
    "'active_records' => bx_count('project_user'",
    "'active_records' => bx_count('project_user_position'",
    "'active_records' => bx_count('project_user_group'",
    "'active_records' => bx_count('builder_role'",
    "'active_records' => bx_count('builder_permission'",
    "'active_records' => bx_count('builder_branch'",
    "'active_records' => bx_count('builder_project'",
    "'active_records' => bx_count('builder_system_setting'",
    "user_status <> 'DELETED'",
    "position_status <> 'DELETED'",
    "branch_status <> 'DELETED'",
    "project_status <> 'DELETED'",
    "group_status <> 'DELETED'",
    "role_status <> 'DELETED'",
    "permission_status <> 'DELETED'",
    "setting_status <> 'DELETED'",
    "bx_audit(\$userStatus === 'DELETED' ? 'DELETE' : 'STATUS'",
    "bx_audit(\$branchStatus === 'DELETED' ? 'DELETE' : 'STATUS'",
    "bx_audit(\$projectStatus === 'DELETED' ? 'DELETE' : 'STATUS'",
];

foreach ($requiredAdminMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing Administrator source marker: ' . $marker);
    }
}

$requiredFrontendMarkers = [
    "const adminViewKeys = ['dashboard', 'users', 'positions', 'groups', 'roles', 'permissions', 'branches', 'projects', 'settings', 'bed-management', 'bed-lookup', 'task-builder', 'audit', 'health', 'template']",
    'managementReadiness?: Array<Record<string, any>>',
    'positions: Array<Record<string, string>>',
    'Management Readiness',
    'ConfirmationModal',
    'BranchCrudView',
    'ProjectCrudView',
    'UserCrudView',
    'UserPositionCrudView',
    'GroupCrudView',
    'RoleCrudView',
    'PermissionMatrixView',
    'SettingsView',
    'BedManagementView',
    'Bed Management',
    'AuditLogView',
    "href={projectUrl(`administrator/?tab=\${phaseField(item, 'route', 'dashboard')}`)}",
];

foreach ($requiredFrontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Missing Administrator frontend marker: ' . $marker);
    }
}

$removedFamilyReportMarkers = [
    'family-reports',
    'FamilyReportsView',
    'familyReport',
    'family_report_export',
    'bx_family_report_data',
];

foreach ($removedFamilyReportMarkers as $marker) {
    if (str_contains($adminSource, $marker) || str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Removed Administrator Family Reports marker is still present: ' . $marker);
    }
}

echo json_encode([
    'administrator_payload_readiness' => true,
    'management_views_present' => true,
    'active_record_filters_present' => true,
    'soft_delete_confirmations_present' => true,
    'family_reports_removed' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
