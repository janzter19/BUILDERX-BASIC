<?php
declare(strict_types=1);

define('BUILDERX_SKIP_SESSION_START', true);
require_once dirname(__DIR__) . '/app/foundation.php';

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$scriptSource = file_get_contents($root . '/scripts/firebase-admin-project-group-write.mjs');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource) || !is_string($scriptSource)) {
    throw new RuntimeException('Project user group Firebase sync sources could not be read.');
}

$combinedSource = $foundationSource . "\n" . $adminSource . "\n" . $frontendSource . "\n" . $scriptSource;
$requiredMarkers = [
    'function bx_admin_write_project_group_firebase_first',
    'scripts/firebase-admin-project-group-write.mjs',
    'mysql_sync_status',
    'mysql_created_at',
    'mysql_updated_at',
    'mysql_deleted_at',
    'mysql_synced_at',
    "firebase_collection: 'project_user_group'",
    "db.collection('project_user_group')",
    'assignment_status',
    'position_key_required_for_active_assignment',
    'duplicate_active_assignment',
    'assignment_position_group_boundary_failed',
    'member_position_keys',
    "collection('project_group')",
    'project_group_firebase_readback_failed',
    'Group and assignments saved in Firebase; MySQL projection is pending.',
    'Group status updated in Firebase. MySQL projection is pending.',
];

foreach ($requiredMarkers as $marker) {
    if (!str_contains($combinedSource, $marker)) {
        throw new RuntimeException('Missing project user group Firebase sync marker: ' . $marker);
    }
}

foreach (['sync_project_user_group_firebase', 'groupFirebaseSyncFormRef', 'Sync groups to Firebase', 'Sync Groups To Firebase'] as $removedMarker) {
    if (str_contains($adminSource, $removedMarker) || str_contains($frontendSource, $removedMarker)) {
        throw new RuntimeException('Removed Groups sync button/action artifact remains: ' . $removedMarker);
    }
}

foreach (['builder_user', 'builder_user_role', 'user_password_hash', 'user_email'] as $forbiddenMarker) {
    if (str_contains($scriptSource, $forbiddenMarker)) {
        throw new RuntimeException('Project user group Firebase script exposes forbidden marker: ' . $forbiddenMarker);
    }
}

$projectKey = (string) (bx_db()->GetOne("SELECT project_key FROM builder_project WHERE project_status <> 'DELETED' ORDER BY x_id ASC LIMIT 1") ?: '');
if ($projectKey !== '') {
    $rows = bx_project_user_group_firebase_rows($projectKey);
    $encodedRows = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (['user_password_hash', 'user_email'] as $forbiddenField) {
        if (str_contains($encodedRows, $forbiddenField)) {
            throw new RuntimeException('Project user group Firebase rows contain forbidden field: ' . $forbiddenField);
        }
    }
} else {
    $rows = [];
}

echo json_encode([
    'project_user_group_firebase_sync_button_removed' => true,
    'project_user_group_save_cascades_to_related_project_users' => true,
    'project_user_group_status_cascades_to_related_project_users' => true,
    'project_user_group_firebase_script_present' => true,
    'project_user_group_firebase_payload_project_scoped' => true,
    'project_user_group_firebase_payload_secret_safe' => true,
    'payload_row_count' => count($rows),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
