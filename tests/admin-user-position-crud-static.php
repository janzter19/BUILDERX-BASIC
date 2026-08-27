<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource)) {
    throw new RuntimeException('Administrator position CRUD source could not be read.');
}

$groupCrudStart = strpos($frontendSource, 'function GroupCrudView()');
$groupCrudEnd = strpos($frontendSource, 'function GroupStatusButton(', $groupCrudStart === false ? 0 : $groupCrudStart);
if ($groupCrudStart === false || $groupCrudEnd === false || $groupCrudEnd <= $groupCrudStart) {
    throw new RuntimeException('Group CRUD source boundary could not be found.');
}
$groupCrudSource = substr($frontendSource, $groupCrudStart, $groupCrudEnd - $groupCrudStart);

$requiredFoundationMarkers = [
    'CREATE TABLE IF NOT EXISTS project_user',
    'CREATE TABLE IF NOT EXISTS project_user_group',
    'CREATE TABLE IF NOT EXISTS project_user_position',
    'project_key CHAR(36) NOT NULL',
    'KEY idx_project_user_group_name (project_key, group_name)',
    'ALTER TABLE project_user_group DROP INDEX uq_project_user_group_name',
    'position_key CHAR(36) NOT NULL UNIQUE',
    'group_key CHAR(36) NULL',
    'KEY idx_project_user_position_group (group_key)',
    'KEY idx_project_user_position (position_key)',
];

$requiredAdminMarkers = [
    "if (\$action === 'save_user_position')",
    "if (\$action === 'set_user_position_status')",
    'bx_admin_redirect_position_return',
    'project_user',
    'project_user_group',
    'project_user_position',
    "groupSaveMode === 'edit' && \$groupKey === ''",
    'Group edit could not be saved because the existing group key was missing.',
    '$groupNameChanged = $groupKey === \'\' || strtolower($existingGroupName) !== strtolower($groupName);',
    "SELECT COUNT(*) FROM project_group WHERE project_key = ? AND group_name = ? AND group_status = 'ACTIVE' AND group_key <> ?",
    'Group name already exists in enabled groups.',
    'Position code, name, and group are required.',
    'Position code must be 80 characters or less.',
    'Selected position group must be active.',
    'One or more selected assignments must use an active group.',
    'Deactivated groups only allow restore or delete.',
    'Position is already active.',
    'Position is already inactive.',
    'Position is already deleted.',
    'bx_admin_is_original_administrators_group',
    'Original Administrators group is protected and cannot be edited.',
    'Original Administrators group status is protected.',
    "group_status = 'ACTIVE'",
    'Selected user position must belong to the selected project group.',
    'bx_admin_assert_position_readback',
    "bx_admin_write_project_position_firebase_first([",
    "SELECT * FROM project_position WHERE position_key = ? AND project_key = ? LIMIT 1",
    "INSERT INTO project_user",
    "position_count",
    "'positions' => \$positionProjectionReady ? bx_admin_payload_rows",
    "position_status <> 'DELETED'",
];

$requiredFrontendMarkers = [
    "'positions'",
    'Positions',
    'UserPositionCrudView',
    'PositionStatusButton',
    'Position Form',
    'Save Position',
    'flex flex-wrap justify-end gap-2',
    'initialPositionGroupKey',
    "const visiblePositions = scopedPositions.filter((position) => position.position_status !== 'DELETED')",
    'visiblePositions.map((position)',
    'const positionActive = position.position_status === \'ACTIVE\'',
    'const positionInactive = position.position_status === \'INACTIVE\'',
    'inactivePositionActionReason',
    'activePositionRestoreReason',
    'name="position_key"',
    'name="group_key"',
    'Select group',
    "headers={['Position', 'Group', 'Status', 'Description', 'Actions']}",
    "headers={['Group', 'Status', 'Positions', 'Members', 'Actions']}",
    'position.position_name',
    'position_count',
    'position_names',
    'scopedGroupKey',
    'name="project_key"',
    "activeView === 'positions'",
    'disabled={positionInactive}',
    'disabled={positionActive}',
    'disabledReason={inactivePositionActionReason}',
];

$requiredGroupLayoutMarkers = [
    'open={groupDialogMode !== null}',
    'open={Boolean(assignmentGroup)}',
    'xl:col-span-8',
    'xl:col-span-4',
    'Group Details',
    'Use modal actions for group records',
    'Manage Members',
    'aria-label="New Group"',
    "const visibleGroups = data.groups.filter((group) => group.group_status !== 'DELETED')",
    'visibleGroups.map((group)',
    'name="group_save_mode"',
    "value={editingGroup?.group_key || editingGroupKey}",
    'data-skip-submit-confirmation="true"',
    'aria-label={`Position Management for ${group.group_name}`}',
    'open={Boolean(positionGroup)}',
    'max-w-[95vw]',
    'setPositionGroupKey(group.group_key)',
    '<UserPositionCrudView embedded scopedGroupKey={positionGroup.group_key} />',
    'shadow-[0_0_14px_rgba(16,185,129,0.25)]',
    "const groupActive = group.group_status === 'ACTIVE'",
    'const restoreDisabled = groupActive',
    'activeGroupRestoreReason',
    'const groupDeactivated = group.group_status === \'INACTIVE\'',
    "const originalAdministratorsGroup = group.group_name === 'Administrators'",
    '!originalAdministratorsGroup ? <Tooltip>',
    'disabled={groupDeactivated}',
    'disabledReason={deactivatedGroupActionReason}',
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Missing position schema marker: ' . $marker);
    }
}

foreach ($requiredAdminMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing position backend marker: ' . $marker);
    }
}

foreach ($requiredFrontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Missing position frontend marker: ' . $marker);
    }
}

foreach ($requiredGroupLayoutMarkers as $marker) {
    if (!str_contains($groupCrudSource, $marker)) {
        throw new RuntimeException('Missing group modal layout marker: ' . $marker);
    }
}

foreach ([
    '<Label>Parent Group</Label>',
    'Parent group_key Firebase document id: ${positionGroup.group_key}',
    'group_key Firebase document id: {scopedGroup.group_key}',
    "strtoupper(trim((string) (\$_POST['position_code'] ?? '')))",
    "preg_match('/^[A-Z0-9_-]{2,80}$/', \$positionCode)",
    'Position code must use 2-80 uppercase letters, numbers, underscores, or hyphens.',
    "{ key: 'positions', label: 'Positions' }",
    'positions are managed from the Positions view',
] as $removedPositionMenuMarker) {
    if (str_contains($frontendSource, $removedPositionMenuMarker)) {
        throw new RuntimeException('Removed standalone position menu marker is still present: ' . $removedPositionMenuMarker);
    }
}

foreach ([
    'ViewTabs activeTab={activeTab}',
    'Group Membership GUI',
    '<DashboardPanel title={editingGroup ?',
    'lg:col-span-3',
    'lg:col-span-9',
    'sm:max-w-6xl',
] as $removedGroupLayoutMarker) {
    if (str_contains($groupCrudSource, $removedGroupLayoutMarker)) {
        throw new RuntimeException('Removed inline group layout marker is still present: ' . $removedGroupLayoutMarker);
    }
}

echo json_encode([
    'position_schema_present' => true,
    'position_actions_guarded' => true,
    'position_payload_present' => true,
    'position_user_assignment_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
