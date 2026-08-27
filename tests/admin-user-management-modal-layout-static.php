<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$adminSource = file_get_contents($root . '/administrator/index.php');

if (!is_string($foundationSource) || !is_string($frontendSource) || !is_string($adminSource)) {
    throw new RuntimeException('Administrator frontend source could not be read.');
}

$userCrudStart = strpos($frontendSource, 'function UserCrudView()');
$userCrudEnd = strpos($frontendSource, 'function UserStatusButton(', $userCrudStart === false ? 0 : $userCrudStart);
if ($userCrudStart === false || $userCrudEnd === false) {
    throw new RuntimeException('UserCrudView source block could not be located.');
}

$userCrudSource = substr($frontendSource, $userCrudStart, $userCrudEnd - $userCrudStart);

$requiredMarkers = [
    'open={userDialogMode !== null}',
    'open={Boolean(assignmentUser)}',
    'Use modal actions for project user records',
    'action={',
    "headers={['User', 'Status', 'Assignments', 'Actions']}",
    'xl:grid-cols-12',
    'xl:col-span-8',
    'xl:col-span-4',
    'Project Assignment',
    'Reset Password',
    "title: 'Confirm password reset'",
    'data-skip-submit-confirmation="true"',
    'name="action" value="reset_user_password"',
    'Reset ${user.user_login} to the automatic default password and clear failed login attempts?',
    'History',
    'justify-end gap-1.5',
    'Manage Access',
    'View Login History',
    'Mobile Number',
    '<Label htmlFor="user_chat_name">Chat Name</Label>',
    'name="user_chat_name"',
    'user_mobile_number',
    'Manage group and position assignments separately through Manage Access.',
    'normalizeProjectMobileNumberInput',
    'setUserFormMobileNumber',
    'placeholder="+639171234567"',
    'pattern="\\+[1-9][0-9]{7,14}"',
    'bg-blue-500/10',
    'bg-emerald-500/10',
    'bg-amber-500/10',
    'bg-sky-500/10',
    'size="icon-sm"',
    "<TooltipContent>{userRestricted ? userRestrictedReason : 'Edit User'}</TooltipContent>",
    'userInitials(user)',
    'aria-label={`${user.user_name || user.user_login || \'User\'} avatar`}',
    '<Avatar className="size-14 border border-emerald-500/30 bg-emerald-500/10 text-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.12)]"',
    '<AvatarImage src={emptyUserAvatarUrl} alt="" className="p-2 opacity-90" />',
    '<AvatarFallback className="bg-transparent text-sm font-semibold text-emerald-500 dark:text-emerald-300">{userInitials(user)}</AvatarFallback>',
    '<strong className="block truncate">{user.user_name}</strong>',
    'formatLoginHistoryDate(entry.created_at)',
    "const userInactive = userStatus === 'INACTIVE'",
    "const userLocked = userStatus === 'LOCKED'",
    "const userRestricted = userInactive || userLocked || userDeleted",
    'Inactive User Requires Activation',
    'Locked User Requires Activation',
    'disabled={userRestricted}',
    "disabled={userActive || userDeleted}",
    "disabled={!userActive}",
    'disabled:cursor-not-allowed disabled:opacity-40',
    'avatarViewerUserKey',
    'Avatar Viewer',
    'setAvatarViewerSize(size)',
    'userAvatarViewerUrl(avatarViewerUser, avatarViewerSize)',
    'UserAvatarUploadField user={editingUser}',
    'sm:max-w-5xl',
    'grid items-start gap-6 lg:grid-cols-12',
    'self-start border-b border-border/70 pb-5 lg:col-span-3 lg:border-b-0 lg:border-r lg:pb-0 lg:pr-6',
    'grid content-start gap-4 lg:col-span-9 lg:pl-1',
    'grid gap-4 sm:grid-cols-2 xl:grid-cols-3',
    '<Separator />',
    'encType="multipart/form-data"',
    'async function refreshUserPayload()',
    "url.searchParams.set('format', 'json')",
    "headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }",
    'async function submitModalForm(event: React.FormEvent<HTMLFormElement>)',
    'onSubmit={(event) => void submitModalForm(event)}',
    "setModalSaveState('saving')",
    'Saving and reading back the user...',
];

foreach ([
    'user_avatar_path VARCHAR(500) NULL',
    'user_chat_name VARCHAR(160) NULL',
    'user_avatar_original_name VARCHAR(255) NULL',
    "bx_add_column_if_missing('project_user', 'user_chat_name'",
    "bx_add_column_if_missing('project_user', 'user_avatar_path'",
] as $avatarFoundationMarker) {
    if (!str_contains($foundationSource, $avatarFoundationMarker)) {
        throw new RuntimeException('Missing user avatar schema marker: ' . $avatarFoundationMarker);
    }
}

foreach ([
    'User avatar source must be a full uploaded image URL.',
    'bx_project_media_uploaded_url($projectKey, $userAvatarUrl)',
    'UPDATE project_user SET user_avatar_path = ?',
    'User avatar read-back verification failed.',
    'Administrator saved user avatar URL.',
    "COALESCE(u.user_chat_name, '') AS user_chat_name",
    "COALESCE(u.user_avatar_path, '') AS user_avatar_path",
] as $avatarAdminMarker) {
    if (!str_contains($adminSource, $avatarAdminMarker)) {
        throw new RuntimeException('Missing user avatar persistence marker: ' . $avatarAdminMarker);
    }
}

foreach ([
    'function UserAvatarUploadField',
    'function imageFileFromClipboardData',
    'imageFileFromClipboardData(event.clipboardData, \'user-avatar\')',
    '<div className="grid gap-3" onPaste={handlePaste}>',
    "body.append('source_table', 'project_user')",
    "body.append('source_field', 'user_avatar')",
    'Upload Avatar',
    'View Avatar',
    'name="user_avatar_url"',
    'Images larger than 1024px are resized locally before upload.',
] as $avatarFrontendMarker) {
    if (!str_contains($frontendSource, $avatarFrontendMarker)) {
        throw new RuntimeException('Missing user avatar frontend marker: ' . $avatarFrontendMarker);
    }
}

foreach ([
    'action?: React.ReactNode',
    'flex items-start justify-between gap-3 border-b px-4 py-3',
    '{action ? <div className="shrink-0">{action}</div> : null}',
] as $panelActionMarker) {
    if (!str_contains($frontendSource, $panelActionMarker)) {
        throw new RuntimeException('Missing DashboardPanel action marker: ' . $panelActionMarker);
    }
}

if (!str_contains($frontendSource, '<TooltipContent>{tooltipText}</TooltipContent>')) {
    throw new RuntimeException('Missing icon-only user status tooltip marker.');
}

if (!str_contains($frontendSource, "import emptyUserAvatarUrl from '@/assets/user-empty-avatar.svg'")) {
    throw new RuntimeException('Missing empty user avatar asset import.');
}

foreach (["'JAN', 'FEB', 'MAR'", "hour24 >= 12 ? 'PM' : 'AM'", "padStart(2, '0')"] as $dateFormatMarker) {
    if (!str_contains($frontendSource, $dateFormatMarker)) {
        throw new RuntimeException('Missing login history date format marker: ' . $dateFormatMarker);
    }
}

foreach (['bg-violet-500/10', 'bg-red-500/10'] as $statusColorMarker) {
    if (!str_contains($frontendSource, $statusColorMarker)) {
        throw new RuntimeException('Missing user status action color marker: ' . $statusColorMarker);
    }
}

foreach ([
    'function bx_admin_normalize_mobile_number',
    "preg_match('/^\\+[1-9][0-9]{7,14}$/', \$userMobileNumber)",
    'Mobile number must start with a country code, for example +639171234567.',
] as $backendMobileMarker) {
    if (!str_contains($adminSource, $backendMobileMarker)) {
        throw new RuntimeException('Missing project user mobile validation marker: ' . $backendMobileMarker);
    }
}

foreach ($requiredMarkers as $marker) {
    if (!str_contains($userCrudSource, $marker)) {
        throw new RuntimeException('Missing modal-only user management marker: ' . $marker);
    }
}

if (str_contains($frontendSource, 'Close form after save') || str_contains($frontendSource, 'closeFormAfterConfirm')) {
    throw new RuntimeException('Obsolete close-form-after-save checkbox artifact remains.');
}

if (substr_count($userCrudSource, '<Separator />') < 1) {
    throw new RuntimeException('User form must keep a separator before assignment fields.');
}

$userProfileDialogStart = strpos($userCrudSource, 'open={userDialogMode !== null}');
$assignmentDialogStart = strpos($userCrudSource, '<Dialog open={Boolean(assignmentUser)}', $userProfileDialogStart === false ? 0 : $userProfileDialogStart);
if ($userProfileDialogStart === false || $assignmentDialogStart === false) {
    throw new RuntimeException('User profile and assignment dialog boundaries could not be located.');
}
$userProfileDialogSource = substr($userCrudSource, $userProfileDialogStart, $assignmentDialogStart - $userProfileDialogStart);
foreach (['<Label htmlFor="group_keys">', '<Label htmlFor="position_key">', 'name="group_keys[]"', 'name="position_key"'] as $removedAssignmentField) {
    if (str_contains($userProfileDialogSource, $removedAssignmentField)) {
        throw new RuntimeException('Group or position field remains in the user profile dialog: ' . $removedAssignmentField);
    }
}

$forbiddenMarkers = [
    'ViewTabs activeTab',
    'User Assignment GUI',
    '<DashboardPanel title={editingUser ?',
    '<DashboardPanel title="Reset Password"',
    '>Edit</button>',
    '>Access</button>',
    '>Reset</button>',
    '>History</button>',
    '{label}</Button>',
    '<TooltipContent>Edit user</TooltipContent>',
    '<TooltipContent>Manage access</TooltipContent>',
    '<TooltipContent>Reset password</TooltipContent>',
    '<TooltipContent>View login history</TooltipContent>',
    "headers={['User', 'Status', 'Assignments', 'Last Login', 'Actions']}",
    "{user.user_last_login_at || 'Never'}",
    '<Label htmlFor="user_email">Email</Label>',
    '<Label htmlFor="project_keys">Project</Label>',
    '<Label htmlFor="reset_password">New Password</Label>',
    'id="reset_password"',
    'AssignmentCheckboxGrid name="project_keys[]"',
    '<DialogClose render={<Button type="button" variant="ghost" />}>Cancel</DialogClose>',
];

foreach ($forbiddenMarkers as $marker) {
    if (str_contains($userCrudSource, $marker)) {
        throw new RuntimeException('Inline user CRUD layout marker is still present: ' . $marker);
    }
}

echo json_encode([
    'user_crud_forms_modal_only' => true,
    'user_body_table_first' => true,
    'user_access_modal_present' => true,
    'user_reset_confirmation_modal_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
