<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

function bx_admin_redirect(string $tab): void
{
    if (function_exists('bx_admin_wants_json') && bx_admin_wants_json()) {
        $flash = bx_take_flash();
        bx_admin_json_response([
            'ok' => false,
            'message' => (string) ($flash['message'] ?? 'Action failed.'),
        ], 422);
    }

    header('Location: ./?tab=' . rawurlencode($tab));
    exit;
}

function bx_admin_redirect_settings(string $group = ''): void
{
    $params = ['tab' => 'settings'];
    $group = trim($group);
    if ($group !== '' && preg_match('/^[A-Za-z0-9_-]{2,80}$/', $group)) {
        $params['settings_group'] = $group;
    }

    header('Location: ./?' . http_build_query($params));
    exit;
}

function bx_admin_redirect_with_state(string $tab, array $state): void
{
    $_SESSION['builderx_admin_state'] = $state;
    bx_admin_redirect($tab);
}

function bx_admin_redirect_position_return(string $tab, string $groupKey): void
{
    if ($tab === 'groups' && $groupKey !== '') {
        bx_admin_redirect_with_state('groups', [
            'activeGroupKey' => $groupKey,
            'positionGroupKey' => $groupKey,
        ]);
    }

    bx_admin_redirect($tab);
}

function bx_admin_redirect_bed_lookup(array $filters): void
{
    $params = ['tab' => 'bed-lookup'];
    foreach ($filters as $key => $value) {
        $value = trim((string) $value);
        if ($value !== '') {
            $params[$key] = $value;
        }
    }

    header('Location: ./?' . http_build_query($params));
    exit;
}

function bx_admin_project_key(string $postedProjectKey = ''): string
{
    $postedProjectKey = trim($postedProjectKey);
    if ($postedProjectKey !== '') {
        $projectExists = (int) bx_db()->GetOne(
            "SELECT COUNT(*) FROM builder_project WHERE project_key = ? AND project_status <> 'DELETED'",
            [$postedProjectKey]
        );
        if ($projectExists > 0) {
            return $postedProjectKey;
        }
    }

    return (string) (bx_db()->GetOne("SELECT project_key FROM builder_project WHERE project_status <> 'DELETED' ORDER BY x_id ASC LIMIT 1") ?: '');
}

function bx_admin_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function bx_admin_wants_json(): bool
{
    $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));
    $accept = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT'] ?? '')));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function bx_admin_bed_reference_firebase_step(array $sync): array
{
    if (($sync['ok'] ?? false) === true) {
        $synced = max(1, (int) ($sync['synced'] ?? 1));
        return [
            'label' => 'Sync',
            'status' => 'complete',
            'detail' => $synced . ' option' . ($synced === 1 ? '' : 's') . ' updated for connected users.',
        ];
    }

    return [
        'label' => 'Sync',
        'status' => 'failed',
        'detail' => 'Sync not completed. Please check the service status and try again.',
    ];
}

function bx_admin_project_user_auth_step(array $sync): array
{
    if (($sync['ok'] ?? false) === true) {
        $action = trim((string) ($sync['action'] ?? 'synced'));
        return [
            'label' => 'Firebase Auth',
            'status' => 'complete',
            'detail' => 'Project user Auth account ' . ($action !== '' ? $action : 'synced') . ' with the project user document id.',
        ];
    }

    $message = trim((string) ($sync['message'] ?? 'Project user Auth sync did not complete.'));
    return [
        'label' => 'Firebase Auth',
        'status' => ($sync['skipped'] ?? false) === true ? 'blocked' : 'failed',
        'detail' => $message !== '' ? $message : 'Project user Auth sync did not complete.',
    ];
}

function bx_admin_project_user_group_firebase_step(array $sync): array
{
    if (($sync['ok'] ?? false) === true) {
        $synced = max(1, (int) ($sync['synced'] ?? 1));
        return [
            'label' => 'Firebase',
            'status' => 'complete',
            'detail' => $synced . ' project user group' . ($synced === 1 ? '' : 's') . ' synced to project_user_group.',
        ];
    }

    $message = trim((string) ($sync['message'] ?? 'Project user group sync did not complete.'));
    return [
        'label' => 'Firebase',
        'status' => ($sync['skipped'] ?? false) === true ? 'blocked' : 'failed',
        'detail' => $message !== '' ? $message : 'Project user group sync did not complete.',
    ];
}

function bx_admin_project_user_group_related_user_firebase_step(array $sync, int $rowCount): array
{
    if ($rowCount < 1) {
        return [
            'label' => 'User refresh',
            'status' => 'complete',
            'detail' => 'No related project users needed a group refresh.',
        ];
    }

    if (($sync['ok'] ?? false) === true) {
        $synced = max(0, (int) ($sync['synced'] ?? 0));
        return [
            'label' => 'User refresh',
            'status' => 'complete',
            'detail' => $synced . ' related project user' . ($synced === 1 ? '' : 's') . ' refreshed with current group information.',
        ];
    }

    $message = trim((string) ($sync['message'] ?? 'Related project user refresh did not complete.'));
    return [
        'label' => 'User refresh',
        'status' => ($sync['skipped'] ?? false) === true ? 'blocked' : 'failed',
        'detail' => $message !== '' ? $message : 'Related project user refresh did not complete.',
    ];
}

function bx_admin_sync_project_user_group_and_members_to_firebase(string $projectKey, string $groupKey, array $affectedUserKeys): array
{
    $groupRows = bx_project_user_group_firebase_rows($projectKey, $groupKey, 1);
    $groupSync = $groupRows !== []
        ? bx_sync_project_user_group_rows_to_firebase($groupRows)
        : ['ok' => false, 'skipped' => true, 'message' => 'No project user group row is available for Firebase sync.'];
    $groupStep = bx_admin_project_user_group_firebase_step($groupSync);

    $userRows = bx_project_user_firebase_rows_by_keys($projectKey, $affectedUserKeys);
    $userSync = $userRows !== []
        ? bx_sync_project_user_rows_to_firebase($userRows)
        : ['ok' => true, 'synced' => 0, 'skipped' => true];
    $userStep = bx_admin_project_user_group_related_user_firebase_step($userSync, count($userRows));

    return [
        'ok' => $groupStep['status'] === 'complete' && $userStep['status'] === 'complete',
        'group_rows' => $groupRows,
        'user_rows' => $userRows,
        'group_sync' => $groupSync,
        'user_sync' => $userSync,
        'steps' => [$groupStep, $userStep],
    ];
}

function bx_admin_settings_firebase_step(array $sync): array
{
    if (($sync['ok'] ?? false) === true) {
        $systemCount = (int) ($sync['system_count'] ?? 0);
        $projectCount = (int) ($sync['project_count'] ?? 0);
        $mediaCount = (int) ($sync['media_count'] ?? 0);
        $documentKey = (string) ($sync['document_key'] ?? 'current');

        return [
            'label' => 'Firebase',
            'status' => 'complete',
            'detail' => 'Synced ' . (string) ($systemCount + $projectCount + $mediaCount) . ' setting value(s), including ' . (string) $mediaCount . ' media setting(s), to project_setting/' . $documentKey . '.',
        ];
    }

    $message = trim((string) ($sync['message'] ?? 'Firebase settings sync did not complete.'));
    return [
        'label' => 'Firebase',
        'status' => ($sync['skipped'] ?? false) === true ? 'blocked' : 'failed',
        'detail' => $message !== '' ? $message : 'Firebase settings sync did not complete.',
    ];
}

function bx_admin_require_authorization(array $requirements = []): array
{
    $authorization = bx_authorization_guard($requirements);
    if ($authorization['allowed'] && !bx_admin_session_boundary_allows()) {
        bx_logout();
        bx_flash('Administrator Firebase sign-in is required.', 'error');
        header('Location: ./?tab=login');
        exit;
    }
    if ($authorization['allowed']) {
        return $authorization;
    }

    bx_flash((string) $authorization['message'], 'error');
    header('Location: ./');
    exit;
}

function bx_admin_session_boundary_allows(): bool
{
    $marker = (string) ($_SESSION['builderx_admin_auth_marker'] ?? '');
    $audience = (string) ($_SESSION['builderx_auth_audience'] ?? '');
    $portalMarker = trim((string) ($_SESSION['builderx_portal_auth_marker'] ?? ''));

    return hash_equals('RBMS_ADMINISTRATOR', $marker)
        && hash_equals('rbms-administrator', $audience)
        && $portalMarker === '';
}

function bx_project_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $basePath = rtrim(dirname(dirname($scriptName)), '/');

    return ($basePath === '' ? '' : $basePath) . '/';
}

function bx_template_default_presets(): array
{
    return [
        [
            'label' => 'Base b0',
            'preset_arg' => '--preset b0',
            'preset' => 'b0',
            'template' => 'next',
        ],
        [
            'label' => 'Preset b5rR41Mtnc',
            'preset_arg' => '--preset b5rR41Mtnc',
            'preset' => 'b5rR41Mtnc',
            'template' => 'next',
        ],
    ];
}

function bx_template_preset_code_from_arg(string $presetArg): string
{
    $presetArg = trim($presetArg);
    if (!preg_match('/^--preset\s+([A-Za-z0-9_-]{1,64})$/', $presetArg, $matches)) {
        throw new InvalidArgumentException('Preset argument must use this format: --preset b5rR41Mtnc');
    }

    return $matches[1];
}

function bx_template_normalize_template(string $template): string
{
    $template = trim($template);
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $template)) {
        throw new InvalidArgumentException('Template must use letters, numbers, underscores, or hyphens only.');
    }

    return $template;
}

function bx_template_normalize_preset(array $preset): ?array
{
    try {
        $presetArg = trim((string) ($preset['preset_arg'] ?? ''));
        if ($presetArg === '' && isset($preset['preset'])) {
            $presetArg = '--preset ' . trim((string) $preset['preset']);
        }
        $presetCode = bx_template_preset_code_from_arg($presetArg);
        $template = bx_template_normalize_template((string) ($preset['template'] ?? 'next'));
    } catch (Throwable) {
        return null;
    }

    $label = trim((string) ($preset['label'] ?? ''));
    if ($label === '') {
        $label = $presetCode . ' / ' . $template;
    }

    return [
        'label' => substr($label, 0, 80),
        'preset_arg' => '--preset ' . $presetCode,
        'preset' => $presetCode,
        'template' => $template,
        'command' => sprintf('npx shadcn@latest init --preset %s --template %s', $presetCode, $template),
    ];
}

function bx_template_presets(): array
{
    $raw = (string) bx_db()->GetOne(
        "SELECT setting_value FROM builder_system_setting WHERE setting_name = 'template_presets' AND setting_status = 'ACTIVE'"
    );
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : bx_template_default_presets();
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $preset = bx_template_normalize_preset($item);
        if ($preset) {
            $normalized[$preset['preset'] . ':' . $preset['template']] = $preset;
        }
    }

    foreach (bx_template_default_presets() as $item) {
        $preset = bx_template_normalize_preset($item);
        if ($preset) {
            $normalized[$preset['preset'] . ':' . $preset['template']] = $normalized[$preset['preset'] . ':' . $preset['template']] ?? $preset;
        }
    }

    return array_values($normalized);
}

function bx_template_save_presets(array $presets): void
{
    $json = json_encode(array_values($presets), JSON_UNESCAPED_SLASHES);
    if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = 'template_presets'") === 0) {
        bx_db()->Execute(
            'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
            [bx_uuid(), 'template_presets', $json, 'template']
        );
        return;
    }

    bx_db()->Execute(
        "UPDATE builder_system_setting SET setting_value = ?, setting_group = 'template', setting_status = 'ACTIVE' WHERE setting_name = 'template_presets'",
        [$json]
    );
}

function bx_template_store_preset(string $label, string $presetArg, string $template): array
{
    $preset = bx_template_normalize_preset([
        'label' => $label,
        'preset_arg' => $presetArg,
        'template' => $template,
    ]);
    if (!$preset) {
        throw new InvalidArgumentException('Template preset is invalid.');
    }

    $presets = bx_template_presets();
    $stored = [];
    foreach ($presets as $existing) {
        $stored[$existing['preset'] . ':' . $existing['template']] = $existing;
    }
    $stored[$preset['preset'] . ':' . $preset['template']] = $preset;
    bx_template_save_presets(array_values($stored));

    return $preset;
}

function bx_template_run_shell_step(string $workingDirectory, string $displayCommand, string $command): array
{
    if (!is_dir($workingDirectory) || !is_writable($workingDirectory)) {
        throw new RuntimeException("Working directory is not writable by the web server: {$workingDirectory}");
    }

    $shellCommand = sprintf(
        'cd %s && timeout 240s env HOME=/tmp XDG_CONFIG_HOME=/tmp NPM_CONFIG_CACHE=/tmp/builderx-npm-cache %s 2>&1',
        escapeshellarg($workingDirectory),
        $command
    );

    $startedAt = microtime(true);
    $output = [];
    $exitCode = 0;
    exec($shellCommand, $output, $exitCode);

    return [
        'command' => $displayCommand,
        'root_path' => $workingDirectory,
        'exit_code' => $exitCode,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'output' => implode("\n", $output),
    ];
}

function bx_template_shadcn_targets(string $projectRoot): array
{
    $candidates = [
        $projectRoot . '/frontend',
    ];
    $targets = [];

    foreach ($candidates as $candidate) {
        $realPath = realpath($candidate);
        if ($realPath && bx_template_is_buildable_shadcn_target($realPath)) {
            $targets[$realPath] = $realPath;
        }
    }

    return array_values($targets);
}

function bx_template_is_buildable_shadcn_target(string $workingDirectory): bool
{
    $componentsFile = $workingDirectory . '/components.json';
    if (!is_file($componentsFile) || !bx_template_has_npm_build($workingDirectory)) {
        return false;
    }

    $components = json_decode((string) file_get_contents($componentsFile), true);
    $tailwindCss = is_array($components) ? (string) ($components['tailwind']['css'] ?? '') : '';
    if ($tailwindCss === '') {
        return false;
    }

    return is_file($workingDirectory . '/' . ltrim($tailwindCss, '/'));
}

function bx_template_has_npm_build(string $workingDirectory): bool
{
    $packageFile = $workingDirectory . '/package.json';
    if (!is_file($packageFile)) {
        return false;
    }

    $package = json_decode((string) file_get_contents($packageFile), true);
    return is_array($package)
        && isset($package['scripts'])
        && is_array($package['scripts'])
        && isset($package['scripts']['build'])
        && trim((string) $package['scripts']['build']) !== '';
}

function bx_template_cleanup_generated_ui_imports(string $workingDirectory): array
{
    $uiDirectory = $workingDirectory . '/src/components/ui';
    if (!is_dir($uiDirectory)) {
        return [];
    }

    $changed = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uiDirectory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'tsx') {
            continue;
        }

        $path = $file->getPathname();
        $contents = (string) file_get_contents($path);
        if (!str_contains($contents, 'React.')) {
            $updated = preg_replace('/^import \* as React from ["\']react["\']\R/m', '', $contents);
            if (is_string($updated) && $updated !== $contents) {
                file_put_contents($path, $updated);
                $changed[] = substr($path, strlen($workingDirectory) + 1);
            }
        }
    }

    return $changed;
}

function bx_admin_run_template_command(string $presetArg, string $template): array
{
    $preset = bx_template_preset_code_from_arg($presetArg);
    $template = bx_template_normalize_template($template);
    $projectRoot = dirname(__DIR__);
    $sharedFrontend = realpath($projectRoot . '/frontend') ?: $projectRoot . '/frontend';

    if (!is_dir($projectRoot) || !is_writable($projectRoot)) {
        throw new RuntimeException('Project root is not writable by the web server.');
    }

    $displayCommand = sprintf('npx shadcn@latest init --preset %s --template %s', $preset, $template);
    $startedAt = microtime(true);
    $steps = [];
    $steps[] = [
        'command' => $displayCommand,
        'root_path' => $projectRoot,
        'exit_code' => 0,
        'duration_seconds' => 0,
        'output' => 'BuilderX root is a PHP project. The validated preset is applied to detected buildable shadcn frontend targets that share this project template.',
    ];
    $appliedTargets = [];
    $targets = bx_template_shadcn_targets($projectRoot);
    if ($targets === []) {
        $steps[] = [
            'command' => 'Detect buildable shadcn targets',
            'root_path' => $projectRoot,
            'exit_code' => 1,
            'duration_seconds' => 0,
            'output' => 'No buildable shadcn frontend target was found. Expected frontend with components.json, package.json build script, and configured Tailwind CSS file.',
        ];
    }

    foreach ($targets as $target) {
        if (!is_dir($target . '/node_modules')) {
            $installStep = bx_template_run_shell_step($target, 'npm install', 'npm install');
            $steps[] = $installStep;

            if ((int) $installStep['exit_code'] !== 0) {
                continue;
            }
        }

        $applyCommand = sprintf('npx shadcn@latest apply %s --yes', $preset);
        $step = bx_template_run_shell_step(
            $target,
            $applyCommand,
            sprintf('npx -y shadcn@latest apply %s --yes', escapeshellarg($preset))
        );
        $steps[] = $step;

        if ((int) $step['exit_code'] !== 0) {
            continue;
        }

        $appliedTargets[] = $target;
        $cleanedFiles = bx_template_cleanup_generated_ui_imports($target);
        if ($cleanedFiles !== []) {
            $steps[] = [
                'command' => 'Clean generated shadcn imports',
                'root_path' => $target,
                'exit_code' => 0,
                'duration_seconds' => 0,
                'output' => 'Removed unused React imports from: ' . implode(', ', $cleanedFiles),
            ];
        }

        if ($target === $sharedFrontend) {
            $steps[] = bx_template_run_shell_step(
                $target,
                'npm run build',
                'npm run build'
            );
        }
    }

    $exitCode = 0;
    foreach ($steps as $step) {
        if ((int) $step['exit_code'] !== 0) {
            $exitCode = (int) $step['exit_code'];
            break;
        }
    }

    $combinedOutput = [];
    foreach ($steps as $index => $step) {
        $combinedOutput[] = sprintf(
            "[%d] %s\nPath: %s\nExit code: %s\nDuration: %ss\n%s",
            $index + 1,
            $step['command'],
            $step['root_path'],
            (string) $step['exit_code'],
            (string) $step['duration_seconds'],
            $step['output'] !== '' ? $step['output'] : '(No command output)'
        );
    }

    $sharedFrontendWasApplied = in_array($sharedFrontend, $appliedTargets, true);

    return [
        'command' => $displayCommand,
        'root_path' => $projectRoot,
        'exit_code' => $exitCode,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'output' => implode("\n\n", $combinedOutput),
        'steps' => $steps,
        'applied_targets' => $appliedTargets,
        'administrator_target' => $sharedFrontend,
        'administrator_applied' => $sharedFrontendWasApplied,
        'refresh_administrator' => $sharedFrontendWasApplied && $exitCode === 0,
    ];
}

function bx_post_array(string $key): array
{
    $value = $_POST[$key] ?? [];
    if (!is_array($value)) {
        return [];
    }

    return array_values(array_filter(array_map(static fn ($item): string => trim((string) $item), $value)));
}

function bx_replace_user_links(string $table, string $userKey, string $targetColumn, array $targetKeys): void
{
    $allowed = [
        'builder_user_role' => 'role_key',
        'builder_user_group' => 'group_key',
        'builder_user_branch' => 'branch_key',
        'builder_user_project' => 'project_key',
    ];
    if (($allowed[$table] ?? '') !== $targetColumn) {
        throw new RuntimeException('Invalid access assignment target.');
    }

    $deleted = bx_db()->Execute("DELETE FROM {$table} WHERE user_key = ?", [$userKey]);
    if ($deleted === false) {
        throw new RuntimeException('Existing access assignments could not be replaced.');
    }

    foreach (array_unique($targetKeys) as $targetKey) {
        if ($targetKey === '') {
            continue;
        }

        $saved = bx_db()->Execute(
            "INSERT IGNORE INTO {$table} (user_key, {$targetColumn}) VALUES (?, ?)",
            [$userKey, $targetKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Access assignment could not be saved.');
        }
    }
}

function bx_validate_existing_keys(string $table, string $keyColumn, array $keys, string $statusColumn): bool
{
    foreach (array_unique($keys) as $key) {
        if ($key === '') {
            continue;
        }

        $exists = (int) bx_db()->GetOne(
            "SELECT COUNT(*) FROM {$table} WHERE {$keyColumn} = ? AND {$statusColumn} <> 'DELETED'",
            [$key]
        );

        if ($exists === 0) {
            return false;
        }
    }

    return true;
}

function bx_admin_access_transaction(callable $callback): mixed
{
    $db = bx_db();
    $transactionStarted = false;
    if ($db->BeginTrans() === false) {
        throw new RuntimeException('Access management transaction could not start.');
    }
    $transactionStarted = true;

    try {
        $result = $callback($db);
        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Access management transaction could not commit.');
        }
        $transactionStarted = false;

        return $result;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $exception;
    }
}

function bx_admin_unique_keys(array $keys): array
{
    return array_values(array_unique(array_filter(array_map(static fn ($key): string => trim((string) $key), $keys), static fn (string $key): bool => $key !== '')));
}

function bx_admin_role_key_by_name(string $roleName): string
{
    return (string) (bx_db()->GetOne("SELECT role_key FROM builder_role WHERE role_name = ? AND role_status <> 'DELETED' LIMIT 1", [$roleName]) ?: '');
}

function bx_admin_role_name(string $roleKey): string
{
    return (string) (bx_db()->GetOne('SELECT role_name FROM builder_role WHERE role_key = ? LIMIT 1', [$roleKey]) ?: '');
}

function bx_admin_active_permission_keys(): array
{
    $rows = bx_db()->GetAll("SELECT permission_key FROM builder_permission WHERE permission_status = 'ACTIVE' ORDER BY permission_key ASC") ?: [];

    return array_values(array_map(static fn (array $row): string => (string) $row['permission_key'], $rows));
}

function bx_admin_selected_roles_include_administrator(array $roleKeys): bool
{
    $adminRoleKey = bx_admin_role_key_by_name('Administrator');

    return $adminRoleKey !== '' && in_array($adminRoleKey, bx_admin_unique_keys($roleKeys), true);
}

function bx_admin_user_has_active_administrator_role(string $userKey): bool
{
    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
        FROM builder_user u
        JOIN builder_user_role ur ON ur.user_key = u.user_key
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_name = 'Administrator' AND r.role_status = 'ACTIVE'
        WHERE u.user_key = ? AND u.user_status = 'ACTIVE' AND u.user_deleted_at IS NULL",
        [$userKey]
    ) > 0;
}

function bx_admin_active_administrator_count(?string $excludeUserKey = null): int
{
    $params = [];
    $excludeSql = '';
    if ($excludeUserKey !== null && $excludeUserKey !== '') {
        $excludeSql = ' AND u.user_key <> ?';
        $params[] = $excludeUserKey;
    }

    return (int) bx_db()->GetOne(
        "SELECT COUNT(DISTINCT u.user_key)
        FROM builder_user u
        JOIN builder_user_role ur ON ur.user_key = u.user_key
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_name = 'Administrator' AND r.role_status = 'ACTIVE'
        WHERE u.user_status = 'ACTIVE' AND u.user_deleted_at IS NULL{$excludeSql}",
        $params
    );
}

function bx_admin_assert_user_administrator_continuity(string $userKey, string $nextStatus, array $nextRoleKeys): void
{
    if ($userKey === '' || !bx_admin_user_has_active_administrator_role($userKey)) {
        return;
    }

    $targetRemainsActiveAdministrator = $nextStatus === 'ACTIVE' && bx_admin_selected_roles_include_administrator($nextRoleKeys);
    if (!$targetRemainsActiveAdministrator && bx_admin_active_administrator_count($userKey) < 1) {
        throw new RuntimeException('At least one active Administrator account must remain.');
    }
}

function bx_admin_assert_current_user_not_disabled(string $targetUserKey, string $nextStatus, string $currentUserKey): void
{
    if ($targetUserKey === $currentUserKey && in_array($nextStatus, ['DRAFT', 'INACTIVE', 'LOCKED', 'DELETED'], true)) {
        throw new RuntimeException('You cannot disable, lock, draft, or delete your own active administrator session.');
    }
}

function bx_admin_validate_project_branch_assignments(array $projectKeys, array $branchKeys): bool
{
    $branchLookup = array_fill_keys(bx_admin_unique_keys($branchKeys), true);
    foreach (bx_admin_unique_keys($projectKeys) as $projectKey) {
        $branchKey = (string) (bx_db()->GetOne("SELECT branch_key FROM builder_project WHERE project_key = ? AND project_status <> 'DELETED'", [$projectKey]) ?: '');
        if ($branchKey === '' || !isset($branchLookup[$branchKey])) {
            return false;
        }
    }

    return true;
}

function bx_admin_assert_role_guardrails(string $existingRoleKey, string $nextRoleName, string $nextRoleStatus, array $permissionKeys): void
{
    if ($existingRoleKey === '' || bx_admin_role_name($existingRoleKey) !== 'Administrator') {
        return;
    }

    if ($nextRoleName !== 'Administrator' || $nextRoleStatus !== 'ACTIVE') {
        throw new RuntimeException('The built-in Administrator role must remain active and keep its name.');
    }

    $missingPermissions = array_diff(bx_admin_active_permission_keys(), bx_admin_unique_keys($permissionKeys));
    if ($missingPermissions !== []) {
        throw new RuntimeException('The Administrator role must retain all active permissions.');
    }
}

function bx_admin_assert_permission_matrix_guardrails(array $roleKeys, array $permissionKeys, array $matrix): void
{
    $adminRoleKey = bx_admin_role_key_by_name('Administrator');
    if ($adminRoleKey === '' || !in_array($adminRoleKey, bx_admin_unique_keys($roleKeys), true)) {
        return;
    }

    $selected = $matrix[$adminRoleKey] ?? [];
    if (!is_array($selected)) {
        $selected = [];
    }

    $missingPermissions = array_diff(bx_admin_unique_keys($permissionKeys), bx_admin_unique_keys($selected));
    if ($missingPermissions !== []) {
        throw new RuntimeException('The permission matrix must keep every listed permission assigned to the Administrator role.');
    }
}

function bx_admin_link_count(string $table, string $userKey, string $targetColumn): int
{
    $allowed = [
        'builder_user_role' => 'role_key',
        'builder_user_group' => 'group_key',
        'builder_user_branch' => 'branch_key',
        'builder_user_project' => 'project_key',
    ];
    if (($allowed[$table] ?? '') !== $targetColumn) {
        throw new RuntimeException('Invalid access read-back target.');
    }

    return (int) bx_db()->GetOne("SELECT COUNT(*) FROM {$table} WHERE user_key = ?", [$userKey]);
}

function bx_admin_assert_user_readback(string $userKey, string $userLogin, string $userChatName, string $userMobileNumber, string $positionKey, string $userStatus, array $roleKeys, array $groupKeys, array $branchKeys, array $projectKeys): void
{
    $row = bx_db()->GetRow('SELECT user_login, COALESCE(user_chat_name, \'\') AS user_chat_name, user_mobile_number, group_key, position_key, user_status FROM project_user WHERE user_key = ? LIMIT 1', [$userKey]);
    if (!is_array($row)
        || (string) $row['user_login'] !== $userLogin
        || (string) ($row['user_chat_name'] ?? '') !== $userChatName
        || (string) ($row['user_mobile_number'] ?? '') !== $userMobileNumber
        || (string) ($row['position_key'] ?? '') !== $positionKey
        || (string) $row['user_status'] !== $userStatus
    ) {
        throw new RuntimeException('User read-back verification failed.');
    }

    unset($roleKeys, $branchKeys, $projectKeys);
    $groupKey = bx_admin_unique_keys($groupKeys)[0] ?? '';
    $savedGroupKey = (string) ($row['group_key'] ?? '');
    if ($groupKey !== '' && $savedGroupKey !== $groupKey) {
        throw new RuntimeException('Project user group read-back verification failed.');
    }
}

function bx_admin_normalize_project_username(string $userLogin): string
{
    $normalized = strtolower(trim($userLogin));
    $normalized = preg_replace('/\s+/', '', $normalized);
    return is_string($normalized) ? $normalized : '';
}

function bx_admin_project_username_validation_error(string $userLogin): string
{
    if ($userLogin === '') {
        return 'Username is required.';
    }
    if (strlen($userLogin) < 3 || strlen($userLogin) > 80) {
        return 'Username must use 3-80 characters.';
    }
    if ($userLogin !== strtolower($userLogin) || preg_match('/\s/', $userLogin)) {
        return 'Username must be lowercase and contain no spaces.';
    }
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/', $userLogin)) {
        return 'Username must start and end with a letter or number.';
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $userLogin)) {
        return 'Username may only use lowercase letters, numbers, dots, underscores, or hyphens.';
    }
    if (preg_match('/[._-]{2,}/', $userLogin)) {
        return 'Username must not use repeated separators.';
    }

    return '';
}

function bx_admin_project_user_auth_email_domain(): string
{
    $domain = strtolower(trim((string) (bx_db()->GetOne(
        "SELECT setting_value FROM builder_system_setting WHERE setting_name = 'project_user_auth_email_domain' AND setting_status = 'ACTIVE' LIMIT 1"
    ) ?: '')));
    $domain = preg_replace('/^@+/', '', $domain);
    $domain = is_string($domain) ? trim($domain) : '';
    if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
        return 'rbms.app';
    }

    return $domain;
}

function bx_admin_project_user_auth_email(string $authUsername, ?string $domain = null): string
{
    $username = bx_admin_normalize_project_username($authUsername);
    $emailDomain = $domain === null ? bx_admin_project_user_auth_email_domain() : strtolower(trim($domain));
    $emailDomain = preg_replace('/^@+/', '', $emailDomain);
    $emailDomain = is_string($emailDomain) && $emailDomain !== '' ? $emailDomain : 'rbms.app';
    return $username . '@' . $emailDomain;
}

function bx_admin_project_user_default_password(): string
{
    $password = getenv('BX_ADMIN_DEFAULT_PASSWORD');
    if (!is_string($password) || $password === '') {
        throw new RuntimeException('BX_ADMIN_DEFAULT_PASSWORD is required for administrator account provisioning.');
    }
    return $password;
}

function bx_admin_normalize_mobile_number(string $mobileNumber): string
{
    $normalized = preg_replace('/[^\d+]/', '', trim($mobileNumber));
    if (!is_string($normalized) || $normalized === '') {
        return '';
    }
    if (str_starts_with($normalized, '00')) {
        $normalized = '+' . substr($normalized, 2);
    } elseif ($normalized[0] !== '+') {
        $digits = ltrim($normalized, '+');
        if (str_starts_with($digits, '0')) {
            $digits = '63' . ltrim($digits, '0');
        }
        $normalized = '+' . $digits;
    }

    return $normalized;
}

function bx_admin_assert_position_readback(string $positionKey, string $positionCode, string $positionName, string $groupKey, string $positionStatus): void
{
    $row = bx_db()->GetRow('SELECT position_code, position_name, group_key, position_status FROM project_position WHERE position_key = ? LIMIT 1', [$positionKey]);
    if (!is_array($row)
        || (string) $row['position_code'] !== $positionCode
        || (string) $row['position_name'] !== $positionName
        || (string) ($row['group_key'] ?? '') !== $groupKey
        || (string) $row['position_status'] !== $positionStatus
    ) {
        throw new RuntimeException('Position read-back verification failed.');
    }
}

function bx_admin_assert_group_readback(string $groupKey, string $groupName, string $groupStatus, array $memberUserKeys): void
{
    $row = bx_db()->GetRow('SELECT group_name, group_status FROM project_group WHERE group_key = ? LIMIT 1', [$groupKey]);
    $memberCount = (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_user_group assignment INNER JOIN project_user u ON u.user_key = assignment.user_key AND u.project_key = assignment.project_key WHERE assignment.group_key = ? AND assignment.assignment_status = 'ACTIVE' AND u.user_status <> 'DELETED'", [$groupKey]);
    if (!is_array($row)
        || (string) $row['group_name'] !== $groupName
        || (string) $row['group_status'] !== $groupStatus
        || $memberCount !== count(bx_admin_unique_keys($memberUserKeys))
    ) {
        throw new RuntimeException('Group read-back verification failed.');
    }
}

function bx_admin_is_original_administrators_group(string $groupKey): bool
{
    if ($groupKey === '') {
        return false;
    }

    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
         FROM project_group g
         INNER JOIN project_position p ON p.group_key = g.group_key
         WHERE g.group_key = ?
           AND g.group_name = 'Administrators'
           AND g.group_status <> 'DELETED'
           AND p.position_code = 'ADMINISTRATOR'
           AND p.position_status <> 'DELETED'",
        [$groupKey]
    ) > 0;
}

function bx_admin_assert_role_readback(string $roleKey, string $roleName, string $roleStatus, array $permissionKeys): void
{
    $row = bx_db()->GetRow('SELECT role_name, role_status FROM builder_role WHERE role_key = ? LIMIT 1', [$roleKey]);
    $permissionCount = (int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
    if (!is_array($row)
        || (string) $row['role_name'] !== $roleName
        || (string) $row['role_status'] !== $roleStatus
        || $permissionCount !== count(bx_admin_unique_keys($permissionKeys))
    ) {
        throw new RuntimeException('Role read-back verification failed.');
    }
}

function bx_ini_bytes(string $value): int
{
    $raw = trim($value);
    if ($raw === '-1') {
        return PHP_INT_MAX;
    }
    if ($raw === '') {
        return 0;
    }

    $unit = strtolower(substr($raw, -1));
    $number = (float) $raw;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function bx_format_bytes(int $bytes): string
{
    if ($bytes === PHP_INT_MAX) {
        return 'unlimited';
    }

    if ($bytes >= 1024 * 1024 * 1024) {
        return number_format($bytes / 1024 / 1024 / 1024, 1) . ' GB';
    }

    if ($bytes >= 1024 * 1024) {
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    return number_format(max(0, $bytes) / 1024, 1) . ' KB';
}

function bx_command_version(string $command): string
{
    $allowed = [
        'php' => 'php -v 2>&1',
        'composer' => 'composer --version 2>&1',
        'node' => 'node --version 2>&1',
        'npm' => 'npm --version 2>&1',
        'git' => 'git --version 2>&1',
        'apache' => 'apache2 -v 2>&1',
        'nginx' => 'nginx -v 2>&1',
        'mysql' => 'mysql --version 2>&1',
        'smartctl' => 'smartctl --version 2>&1',
        'sensors' => 'sensors 2>&1',
    ];

    if (!isset($allowed[$command]) || !function_exists('shell_exec')) {
        return 'Not available';
    }

    $output = trim((string) shell_exec($allowed[$command]));
    if ($output === '') {
        return 'Not available';
    }

    return strtok($output, "\n") ?: $output;
}

function bx_systemd_service_health(string $serviceName): array
{
    $allowedServices = [
        'rbmsv4-firebase-messenger-stream.service' => [
            'label' => 'Firebase Messenger Stream',
            'unitFile' => '/etc/systemd/system/rbmsv4-firebase-messenger-stream.service',
            'sourceUnitFile' => dirname(__DIR__) . '/deploy/systemd/rbmsv4-firebase-messenger-stream.service',
        ],
        'traverse.service' => [
            'label' => 'TRAVERSE Firebase-to-MySQL Sync',
            'unitFile' => '/etc/systemd/system/traverse.service',
            'sourceUnitFile' => dirname(__DIR__) . '/deploy/systemd/traverse.service',
        ],
    ];

    if (!isset($allowedServices[$serviceName])) {
        return [
            'service' => $serviceName,
            'label' => $serviceName,
            'status' => 'Unavailable',
            'activeState' => 'unknown',
            'enabledState' => 'unknown',
            'loadState' => 'unknown',
            'detail' => 'Service check is not allow-listed.',
        ];
    }

    $definition = $allowedServices[$serviceName];
    $result = [
        'service' => $serviceName,
        'label' => $definition['label'],
        'status' => 'Unavailable',
        'activeState' => 'unknown',
        'enabledState' => 'unknown',
        'loadState' => 'unknown',
        'subState' => 'unknown',
        'unitFileState' => 'unknown',
        'mainPid' => '',
        'restartCount' => '',
        'execMainStatus' => '',
        'unitFile' => $definition['unitFile'],
        'sourceUnitFile' => $definition['sourceUnitFile'],
        'unitFileInstalled' => is_file((string) $definition['unitFile']),
        'sourceUnitFileExists' => is_file((string) $definition['sourceUnitFile']),
        'detail' => 'systemctl is not available to PHP.',
    ];

    if (!function_exists('shell_exec')) {
        return $result;
    }

    $unit = escapeshellarg($serviceName);
    $activeState = trim((string) @shell_exec('systemctl is-active ' . $unit . ' 2>/dev/null'));
    $enabledState = trim((string) @shell_exec('systemctl is-enabled ' . $unit . ' 2>/dev/null'));
    $showOutput = trim((string) @shell_exec('systemctl show ' . $unit . ' --property=LoadState,ActiveState,SubState,UnitFileState,MainPID,ExecMainStatus,NRestarts --no-pager 2>/dev/null'));
    $properties = [];

    foreach (explode("\n", $showOutput) as $line) {
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $properties[$key] = $value;
    }

    $loadState = (string) ($properties['LoadState'] ?? 'unknown');
    $activeState = $activeState !== '' ? $activeState : (string) ($properties['ActiveState'] ?? 'unknown');
    $enabledState = $enabledState !== '' ? $enabledState : (string) ($properties['UnitFileState'] ?? 'unknown');
    $isRunning = $activeState === 'active';
    $isLoaded = in_array($loadState, ['loaded', 'masked'], true);
    $isEnabled = in_array($enabledState, ['enabled', 'linked', 'static'], true);

    $result['activeState'] = $activeState;
    $result['enabledState'] = $enabledState;
    $result['loadState'] = $loadState;
    $result['subState'] = (string) ($properties['SubState'] ?? 'unknown');
    $result['unitFileState'] = (string) ($properties['UnitFileState'] ?? $enabledState);
    $result['mainPid'] = (string) ($properties['MainPID'] ?? '');
    $result['restartCount'] = (string) ($properties['NRestarts'] ?? '');
    $result['execMainStatus'] = (string) ($properties['ExecMainStatus'] ?? '');
    $result['status'] = $isRunning && $isLoaded && $isEnabled ? 'OK' : 'Needs Attention';
    $result['detail'] = $isRunning
        ? 'Service is active.'
        : 'Service is not running or not installed. Install and enable the unit, then reload Runtime Health.';

    return $result;
}

function bx_restart_traverse_service(): array
{
    if (!function_exists('exec')) {
        return ['ok' => false, 'exitCode' => 127, 'message' => 'system_control_unavailable'];
    }

    $output = [];
    $exitCode = 0;
    exec('sudo -n /usr/bin/systemctl restart traverse.service 2>&1', $output, $exitCode);
    return [
        'ok' => $exitCode === 0,
        'exitCode' => $exitCode,
        'message' => $exitCode === 0 ? 'restart_requested' : 'restart_failed',
    ];
}

function bx_traverse_pending_queue_snapshot(int $limit = 100): array
{
    $safeLimit = max(1, min(500, $limit));
    try {
        $tableExists = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, 'firebase_mysql_sync_queue']
        ) === 1;
        if (!$tableExists) {
            return ['status' => 'NOT_READY', 'total' => 0, 'byState' => [], 'rows' => [], 'message' => 'TRAVERSE queue table is not ready.'];
        }

        $rows = bx_db()->GetAll(
            "SELECT x_id, collection_name, document_id, state, attempt_count,
                    DATE_FORMAT(next_attempt_at, '%Y-%m-%d %H:%i:%s') AS next_attempt_at,
                    last_error_code,
                    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
             FROM firebase_mysql_sync_queue
             WHERE state IN ('QUEUED', 'CLAIMED', 'RETRY_WAIT', 'ACK_PENDING')
             ORDER BY x_id ASC
             LIMIT {$safeLimit}"
        ) ?: [];
        $counts = bx_db()->GetAll(
            "SELECT state, COUNT(*) AS total
             FROM firebase_mysql_sync_queue
             WHERE state IN ('QUEUED', 'CLAIMED', 'RETRY_WAIT', 'ACK_PENDING')
             GROUP BY state ORDER BY state"
        ) ?: [];

        return [
            'status' => 'OK',
            'total' => array_sum(array_map(static fn (array $row): int => (int) ($row['total'] ?? 0), $counts)),
            'byState' => array_values(array_map(static fn (array $row): array => ['state' => (string) ($row['state'] ?? ''), 'total' => (int) ($row['total'] ?? 0)], $counts)),
            'rows' => array_values(array_map(static fn (array $row): array => [
                'id' => (int) ($row['x_id'] ?? 0),
                'collection' => (string) ($row['collection_name'] ?? ''),
                'document' => (string) ($row['document_id'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'attempts' => (int) ($row['attempt_count'] ?? 0),
                'next_attempt_at' => (string) ($row['next_attempt_at'] ?? ''),
                'error' => (string) ($row['last_error_code'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ], $rows)),
            'message' => 'Only non-terminal queue work is shown. ACKED, SUPERSEDED, and DEAD_LETTER are excluded.',
        ];
    } catch (Throwable $error) {
        return ['status' => 'ERROR', 'total' => 0, 'byState' => [], 'rows' => [], 'message' => 'TRAVERSE queue status could not be read.'];
    }
}

function bx_traverse_dashboard_snapshot(int $limit = 50): array
{
    $safeLimit = max(1, min(200, $limit));
    try {
        $tables = bx_db()->GetAll(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('project_traverse_runtime', 'project_traverse_log')",
            [BUILDERX_DB_NAME]
        ) ?: [];
        $available = array_fill_keys(array_map(static fn (array $row): string => (string) ($row['TABLE_NAME'] ?? ''), $tables), true);
        if (!isset($available['project_traverse_runtime'], $available['project_traverse_log'])) {
            return ['status' => 'NOT_READY', 'runtime' => null, 'events' => [], 'message' => 'TRAVERSE report tables are not ready. Restart TRAVERSE once to create them.'];
        }

        $runtime = bx_db()->GetRow(
            "SELECT service_name, service_status, process_id,
                    DATE_FORMAT(started_at, '%Y-%m-%d %H:%i:%s') AS started_at,
                    DATE_FORMAT(last_heartbeat_at, '%Y-%m-%d %H:%i:%s') AS last_heartbeat_at,
                    firebase_reads_observed, pending_count, retry_count, dead_letter_count,
                    last_error_code, DATE_FORMAT(last_error_at, '%Y-%m-%d %H:%i:%s') AS last_error_at,
                    DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
             FROM project_traverse_runtime WHERE xId = 1 LIMIT 1"
        ) ?: null;
        $events = bx_db()->GetAll(
            "SELECT event_type, event_status, firebase_collection, firebase_document_id,
                    queue_xId, error_code, error_detail, attempt_count, firebase_reads_observed,
                    duration_ms, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
             FROM project_traverse_log ORDER BY xId DESC LIMIT {$safeLimit}"
        ) ?: [];
        return [
            'status' => $runtime ? 'OK' : 'NOT_STARTED',
            'runtime' => $runtime ? [
                'service' => (string) ($runtime['service_name'] ?? 'TRAVERSE'),
                'status' => (string) ($runtime['service_status'] ?? 'UNKNOWN'),
                'pid' => (string) ($runtime['process_id'] ?? ''),
                'started_at' => (string) ($runtime['started_at'] ?? ''),
                'heartbeat' => (string) ($runtime['last_heartbeat_at'] ?? ''),
                'reads' => (int) ($runtime['firebase_reads_observed'] ?? 0),
                'pending' => (int) ($runtime['pending_count'] ?? 0),
                'retry' => (int) ($runtime['retry_count'] ?? 0),
                'dead_letter' => (int) ($runtime['dead_letter_count'] ?? 0),
                'last_error' => (string) ($runtime['last_error_code'] ?? ''),
                'last_error_at' => (string) ($runtime['last_error_at'] ?? ''),
                'updated_at' => (string) ($runtime['updated_at'] ?? ''),
            ] : null,
            'events' => array_values(array_map(static fn (array $row): array => [
                'type' => (string) ($row['event_type'] ?? ''),
                'status' => (string) ($row['event_status'] ?? ''),
                'collection' => (string) ($row['firebase_collection'] ?? ''),
                'document' => (string) ($row['firebase_document_id'] ?? ''),
                'queue_id' => (int) ($row['queue_xId'] ?? 0),
                'error_code' => (string) ($row['error_code'] ?? ''),
                'error_detail' => (string) ($row['error_detail'] ?? ''),
                'attempts' => (int) ($row['attempt_count'] ?? 0),
                'reads' => (int) ($row['firebase_reads_observed'] ?? 0),
                'duration_ms' => (int) ($row['duration_ms'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ], $events)),
            'message' => 'MySQL-only report. Viewing or refreshing this page does not query Firebase.',
        ];
    } catch (Throwable) {
        return ['status' => 'ERROR', 'runtime' => null, 'events' => [], 'message' => 'TRAVERSE report could not be read from MySQL.'];
    }
}

function bx_traverse_queue_snapshot(int $limit = 100): array
{
    $safeLimit = max(1, min(500, $limit));
    try {
        $exists = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, 'firebase_mysql_sync_queue']
        ) === 1;
        if (!$exists) {
            return ['status' => 'NOT_READY', 'rows' => [], 'message' => 'TRAVERSE queue table is not ready.'];
        }
        $rows = bx_db()->GetAll(
            "SELECT q.x_id, q.collection_name, q.document_id, q.state, q.attempt_count, q.last_error_code,
                    COALESCE((SELECT l.error_detail FROM project_traverse_log l WHERE l.queue_xId = q.x_id AND l.error_detail IS NOT NULL ORDER BY l.xId DESC LIMIT 1), '') AS error_detail,
                    DATE_FORMAT(q.next_attempt_at, '%Y-%m-%d %H:%i:%s') AS next_attempt_at,
                    DATE_FORMAT(q.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
             FROM firebase_mysql_sync_queue q ORDER BY q.x_id DESC LIMIT {$safeLimit}"
        ) ?: [];
        return [
            'status' => 'OK',
            'rows' => array_values(array_map(static fn (array $row): array => [
                'id' => (int) ($row['x_id'] ?? 0),
                'collection' => (string) ($row['collection_name'] ?? ''),
                'document' => (string) ($row['document_id'] ?? ''),
                'state' => (string) ($row['state'] ?? ''),
                'attempts' => (int) ($row['attempt_count'] ?? 0),
                'error' => (string) ($row['last_error_code'] ?? ''),
                'error_detail' => (string) ($row['error_detail'] ?? ''),
                'next_attempt_at' => (string) ($row['next_attempt_at'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ], $rows)),
            'message' => 'Latest queue records read from MySQL. No Firebase reads are performed.',
        ];
    } catch (Throwable) {
        return ['status' => 'ERROR', 'rows' => [], 'message' => 'TRAVERSE queue records could not be read from MySQL.'];
    }
}

function bx_traverse_document_registry_snapshot(int $limit = 500): array
{
    $safeLimit = max(1, min(1000, $limit));
    try {
        $exists = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, 'project_traverse_document']
        ) === 1;
        if (!$exists) {
            return ['status' => 'NOT_READY', 'rows' => [], 'message' => 'TRAVERSE document registry table is not ready.'];
        }
        $rows = bx_db()->GetAll(
            "SELECT xId, firebase_collection, traverse_status
             FROM project_traverse_document ORDER BY xId ASC LIMIT {$safeLimit}"
        ) ?: [];
        return [
            'status' => 'OK',
            'rows' => array_values(array_map(static fn (array $row): array => [
                'id' => (int) ($row['xId'] ?? 0),
                'collection' => (string) ($row['firebase_collection'] ?? ''),
                'status' => (string) ($row['traverse_status'] ?? ''),
            ], $rows)),
            'message' => 'MySQL-only TRAVERSE registry. Restart TRAVERSE after registry changes.',
        ];
    } catch (Throwable) {
        return ['status' => 'ERROR', 'rows' => [], 'message' => 'TRAVERSE document registry could not be read from MySQL.'];
    }
}

function bx_system_memory(): array
{
    $info = ['total' => 0, 'available' => 0, 'free' => 0, 'swap_total' => 0, 'swap_free' => 0];
    if (!is_readable('/proc/meminfo')) {
        return $info;
    }

    foreach (file('/proc/meminfo') ?: [] as $line) {
        if (!preg_match('/^([A-Za-z_()]+):\s+(\d+)/', $line, $match)) {
            continue;
        }
        $bytes = (int) $match[2] * 1024;
        match ($match[1]) {
            'MemTotal' => $info['total'] = $bytes,
            'MemAvailable' => $info['available'] = $bytes,
            'MemFree' => $info['free'] = $bytes,
            'SwapTotal' => $info['swap_total'] = $bytes,
            'SwapFree' => $info['swap_free'] = $bytes,
            default => null,
        };
    }

    return $info;
}

function bx_mount_usage(): array
{
    $mounts = [];
    if (!is_readable('/proc/mounts')) {
        return [['mount' => '/', 'total' => @disk_total_space('/') ?: 0, 'free' => @disk_free_space('/') ?: 0]];
    }

    $seen = [];
    foreach (file('/proc/mounts') ?: [] as $line) {
        $parts = explode(' ', $line);
        $mount = str_replace('\\040', ' ', $parts[1] ?? '');
        $type = $parts[2] ?? '';
        if ($mount === '' || isset($seen[$mount]) || in_array($type, ['proc', 'sysfs', 'tmpfs', 'devtmpfs', 'devpts', 'cgroup', 'cgroup2', 'overlay', 'squashfs'], true)) {
            continue;
        }
        $total = @disk_total_space($mount);
        $free = @disk_free_space($mount);
        if ($total === false || $free === false || $total <= 0) {
            continue;
        }
        $seen[$mount] = true;
        $mounts[] = ['mount' => $mount, 'type' => $type, 'total' => (int) $total, 'free' => (int) $free, 'used' => (int) ($total - $free)];
    }

    return $mounts;
}

function bx_temperatures(): array
{
    $items = [];
    foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
        $raw = trim((string) @file_get_contents($path));
        if ($raw === '' || !is_numeric($raw)) {
            continue;
        }
        $labelPath = dirname($path) . '/type';
        $items[] = [
            'label' => is_readable($labelPath) ? trim((string) file_get_contents($labelPath)) : basename(dirname($path)),
            'value' => number_format(((float) $raw) / 1000, 1) . ' C',
        ];
    }

    return $items;
}

function bx_path_owner_label(string $path): string
{
    if (!file_exists($path)) {
        return 'missing';
    }

    $owner = @fileowner($path);
    $group = @filegroup($path);
    $ownerLabel = (string) $owner;
    $groupLabel = (string) $group;

    if ($owner !== false && function_exists('posix_getpwuid')) {
        $ownerInfo = @posix_getpwuid($owner);
        $ownerLabel = is_array($ownerInfo) && isset($ownerInfo['name']) ? (string) $ownerInfo['name'] : (string) $owner;
    }

    if ($group !== false && function_exists('posix_getgrgid')) {
        $groupInfo = @posix_getgrgid($group);
        $groupLabel = is_array($groupInfo) && isset($groupInfo['name']) ? (string) $groupInfo['name'] : (string) $group;
    }

    return $ownerLabel . ':' . $groupLabel;
}

function bx_permission_mode(string $path): string
{
    if (!file_exists($path)) {
        return 'missing';
    }

    $perms = @fileperms($path);
    return $perms === false ? 'unknown' : substr(sprintf('%o', $perms), -4);
}

function bx_required_folder_checks(): array
{
    $root = dirname(__DIR__);
    $folders = [
        ['label' => 'Storage root', 'path' => $root . '/storage', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Phase note attachments', 'path' => $root . '/storage/phase-note-attachments', 'required' => '0755 directory, uploaded images 0644'],
        ['label' => 'Uploads', 'path' => $root . '/storage/uploads', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Backups', 'path' => $root . '/storage/backups', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Logs', 'path' => $root . '/storage/logs', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Imports', 'path' => $root . '/storage/imports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Exports', 'path' => $root . '/storage/exports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Reports', 'path' => $root . '/storage/reports', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Queue', 'path' => $root . '/storage/queue', 'required' => 'Readable, writable, traversable'],
        ['label' => 'Shared frontend build', 'path' => $root . '/frontend/dist', 'required' => 'Readable and traversable'],
    ];

    return array_map(static function (array $folder): array {
        $path = $folder['path'];
        $exists = is_dir($path);
        $readable = $exists && is_readable($path);
        $writable = $exists && is_writable($path);
        $traversable = $exists && is_executable($path);
        $requiresWrite = $folder['label'] !== 'Shared frontend build';
        $ok = $exists && $readable && $traversable && (!$requiresWrite || $writable);

        return [
            'label' => $folder['label'],
            'path' => $path,
            'required' => $folder['required'],
            'exists' => $exists,
            'readable' => $readable,
            'writable' => $writable,
            'traversable' => $traversable,
            'mode' => bx_permission_mode($path),
            'owner' => bx_path_owner_label($path),
            'status' => $ok ? 'OK' : 'Needs Attention',
        ];
    }, $folders);
}

function bx_tail_file_lines(string $path, int $bytes = 65536): array
{
    if (!is_readable($path) || !is_file($path)) {
        return [];
    }

    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return [];
    }

    $size = @filesize($path) ?: 0;
    if ($size > $bytes) {
        fseek($handle, -$bytes, SEEK_END);
    }

    $content = stream_get_contents($handle);
    fclose($handle);

    return array_values(array_filter(array_map('trim', explode("\n", (string) $content))));
}

function bx_recent_error_log_entries(): array
{
    $root = dirname(__DIR__);
    $paths = [
        '/var/log/apache2/error.log',
        '/var/log/apache2/builderX-error.log',
    ];

    foreach (glob($root . '/storage/logs/*/*.log') ?: [] as $path) {
        $paths[] = $path;
    }

    $entries = [];
    foreach (array_unique($paths) as $path) {
        if (!file_exists($path)) {
            continue;
        }
        if (!is_readable($path)) {
            $entries[] = ['source' => $path, 'message' => 'Log file exists but is not readable by the web server process.', 'level' => 'warning'];
            continue;
        }

        foreach (array_slice(array_reverse(bx_tail_file_lines($path)), 0, 80) as $line) {
            if (!preg_match('/(fatal|warning|error|permission denied|not found|404|phase-note-attachments|note-attachment|upload_note_attachment|failed)/i', $line)) {
                continue;
            }
            $entries[] = [
                'source' => $path,
                'message' => substr($line, 0, 500),
                'level' => preg_match('/(fatal|permission denied)/i', $line) ? 'error' : 'warning',
            ];
            if (count($entries) >= 25) {
                return $entries;
            }
        }
    }

    return $entries;
}

function bx_attachment_storage_check(): array
{
    $issues = [];
    $checked = 0;
    $active = 0;

    try {
        $rows = bx_db()->GetAll(
            "SELECT attachment_key, original_name, storage_path FROM builder_phase_task_note_attachment WHERE attachment_status = 'ACTIVE' ORDER BY x_id DESC LIMIT 100"
        ) ?: [];
        $active = (int) bx_db()->GetOne("SELECT COUNT(*) FROM builder_phase_task_note_attachment WHERE attachment_status = 'ACTIVE'");
    } catch (Throwable $exception) {
        return [
            'status' => 'Needs Attention',
            'checked' => 0,
            'active' => 0,
            'issues' => [['message' => 'Attachment metadata could not be checked: ' . $exception->getMessage()]],
        ];
    }

    foreach ($rows as $row) {
        $checked++;
        $path = (string) ($row['storage_path'] ?? '');
        $label = (string) ($row['original_name'] ?? $row['attachment_key'] ?? 'attachment');
        if ($path === '' || !is_file($path)) {
            $issues[] = ['message' => $label . ' is missing from disk.'];
            continue;
        }
        if (!is_readable($path)) {
            $issues[] = ['message' => $label . ' exists but is not readable.'];
        }
        if (bx_permission_mode($path) !== '0644') {
            $issues[] = ['message' => $label . ' has mode ' . bx_permission_mode($path) . '; expected 0644 for web thumbnails.'];
        }
        if (count($issues) >= 10) {
            break;
        }
    }

    return [
        'status' => count($issues) === 0 ? 'OK' : 'Needs Attention',
        'checked' => $checked,
        'active' => $active,
        'issues' => $issues,
    ];
}

function bx_runtime_health_snapshot(): array
{
    $requiredUpload = 1073741824;
    $memory = bx_system_memory();
    $safeMemory = $memory['total'] > 0 ? (int) floor($memory['total'] * 0.75) : 0;
    $phpSettings = [];
    foreach (['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars'] as $key) {
        $raw = (string) ini_get($key);
        $bytes = in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? bx_ini_bytes($raw) : 0;
        $target = in_array($key, ['upload_max_filesize', 'post_max_size'], true) ? $requiredUpload : ($key === 'memory_limit' ? min($requiredUpload, $safeMemory ?: $requiredUpload) : ($key === 'max_input_vars' ? 10000 : 300));
        $ok = in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? $bytes >= $target : ((int) $raw === 0 || (int) $raw >= $target);
        $phpSettings[] = [
            'name' => $key,
            'current' => $raw,
            'recommended' => in_array($key, ['upload_max_filesize', 'post_max_size', 'memory_limit'], true) ? bx_format_bytes($target) : (string) $target,
            'status' => $ok ? 'OK' : 'Upgrade Available',
        ];
    }

    $mysqlVariables = [];
    foreach (['version', 'max_allowed_packet', 'innodb_buffer_pool_size', 'max_connections', 'wait_timeout', 'interactive_timeout'] as $name) {
        try {
            $row = bx_db()->GetRow("SHOW VARIABLES LIKE '" . str_replace("'", "''", $name) . "'");
            if ($row) {
                $mysqlVariables[] = ['name' => $name, 'value' => (string) ($row['Value'] ?? $row['value'] ?? '')];
            }
        } catch (Throwable) {
            $mysqlVariables[] = ['name' => $name, 'value' => 'Permission unavailable'];
        }
    }

    $internet = 'Not checked';
    $latencyMs = '';
    $start = microtime(true);
    $connection = @fsockopen('1.1.1.1', 53, $errno, $errstr, 1.5);
    if (is_resource($connection)) {
        fclose($connection);
        $latencyMs = number_format((microtime(true) - $start) * 1000, 0) . ' ms';
        $internet = 'Reachable';
    } else {
        $internet = 'Unavailable';
    }

    $configFiles = [
        ['path' => realpath(__DIR__ . '/../.user.ini') ?: __DIR__ . '/../.user.ini', 'writable' => is_writable(__DIR__ . '/../.user.ini')],
        ['path' => realpath(__DIR__ . '/../.htaccess') ?: __DIR__ . '/../.htaccess', 'writable' => is_writable(__DIR__ . '/../.htaccess')],
        ['path' => realpath(__DIR__ . '/../deployment/php/php.ini') ?: __DIR__ . '/../deployment/php/php.ini', 'writable' => is_writable(__DIR__ . '/../deployment/php/php.ini')],
        ['path' => realpath(__DIR__ . '/../deployment/php/php-fpm.conf') ?: __DIR__ . '/../deployment/php/php-fpm.conf', 'writable' => is_writable(__DIR__ . '/../deployment/php/php-fpm.conf')],
        ['path' => realpath(__DIR__ . '/../deployment/mysql/my.cnf') ?: __DIR__ . '/../deployment/mysql/my.cnf', 'writable' => is_writable(__DIR__ . '/../deployment/mysql/my.cnf')],
    ];
    $requiredFolders = bx_required_folder_checks();
    $errorLogs = bx_recent_error_log_entries();
    $attachmentStorage = bx_attachment_storage_check();
    $systemServices = [
        bx_systemd_service_health('rbmsv4-firebase-messenger-stream.service'),
        bx_systemd_service_health('traverse.service'),
    ];
    $traversePending = bx_traverse_pending_queue_snapshot();
    $traverseQueue = bx_traverse_queue_snapshot();
    $traverseDashboard = bx_traverse_dashboard_snapshot();
    $traverseDocuments = bx_traverse_document_registry_snapshot();
    $runtimeAlerts = [];

    foreach ($requiredFolders as $folder) {
        if ($folder['status'] !== 'OK') {
            $runtimeAlerts[] = [
                'level' => 'error',
                'message' => $folder['label'] . ' permission check needs attention.',
            ];
        }
    }

    if ($attachmentStorage['status'] !== 'OK') {
        $runtimeAlerts[] = [
            'level' => 'error',
            'message' => 'Phase note attachment storage has unreadable or missing files.',
        ];
    }

    foreach ($systemServices as $service) {
        if (($service['status'] ?? '') !== 'OK') {
            $runtimeAlerts[] = [
                'level' => 'warning',
                'message' => (string) ($service['service'] ?? 'System service') . ' is not running. ' . (string) ($service['detail'] ?? ''),
                'fixSteps' => [
                    'sudo install -m 0644 /var/www/html/rbmsv4/deploy/systemd/rbmsv4-firebase-messenger-stream.service /etc/systemd/system/rbmsv4-firebase-messenger-stream.service',
                    'sudo systemctl daemon-reload',
                    'sudo systemctl enable --now rbmsv4-firebase-messenger-stream.service',
                    'sudo systemctl status rbmsv4-firebase-messenger-stream.service',
                ],
            ];
        }
    }

    foreach (array_slice($errorLogs, 0, 5) as $entry) {
        $runtimeAlerts[] = [
            'level' => (string) ($entry['level'] ?? 'warning'),
            'message' => 'Recent log notification: ' . (string) ($entry['message'] ?? ''),
        ];
    }

    return [
        'generatedAt' => date('Y-m-d H:i:s'),
        'versions' => [
            ['name' => 'PHP', 'value' => PHP_VERSION],
            ['name' => 'PHP SAPI', 'value' => PHP_SAPI],
            ['name' => 'MySQL/MariaDB', 'value' => (string) bx_db()->GetOne('SELECT VERSION()')],
            ['name' => 'Web Server', 'value' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI/unknown')],
            ['name' => 'OS', 'value' => php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m')],
            ['name' => 'Composer', 'value' => bx_command_version('composer')],
            ['name' => 'Node', 'value' => bx_command_version('node')],
            ['name' => 'npm', 'value' => bx_command_version('npm')],
            ['name' => 'Git', 'value' => bx_command_version('git')],
            ['name' => 'MySQL Client', 'value' => bx_command_version('mysql')],
        ],
        'phpSettings' => $phpSettings,
        'mysqlSettings' => $mysqlVariables,
        'hardware' => [
            'cpu' => ['cores' => function_exists('shell_exec') ? trim((string) shell_exec('nproc 2>/dev/null')) : 'Unknown', 'load' => implode(', ', sys_getloadavg() ?: [])],
            'memory' => [
                'total' => bx_format_bytes($memory['total']),
                'available' => bx_format_bytes($memory['available']),
                'safe75' => $safeMemory > 0 ? bx_format_bytes($safeMemory) : 'Unknown',
                'swap' => bx_format_bytes(max(0, $memory['swap_total'] - $memory['swap_free'])) . ' used / ' . bx_format_bytes($memory['swap_total']),
            ],
            'disks' => bx_mount_usage(),
            'temperatures' => bx_temperatures(),
        ],
        'network' => ['internet' => $internet, 'latency' => $latencyMs, 'dns' => gethostbyname('example.com') !== 'example.com' ? 'OK' : 'Failed'],
        'configFiles' => $configFiles,
        'requiredFolders' => $requiredFolders,
        'attachmentStorage' => $attachmentStorage,
        'systemServices' => $systemServices,
        'errorLogs' => $errorLogs,
        'runtimeAlerts' => $runtimeAlerts,
        'recommendations' => [
            'PHP upload/post should be 1G, memory limit should be at least 1G when hardware allows, and execution/input timeouts should be 300 seconds.',
            'Tune PHP-FPM pm.max_children from available RAM divided by average PHP worker memory.',
            'Tune MySQL innodb_buffer_pool_size up to roughly 75% of database-dedicated memory on a database-only server, lower when PHP and MySQL share the host.',
        ],
        'traversePending' => $traversePending,
        'traverseQueue' => $traverseQueue,
        'traverseDashboard' => $traverseDashboard,
        'traverseDocuments' => $traverseDocuments,
    ];
}

function bx_write_runtime_project_config(): void
{
    $root = dirname(__DIR__);
    $files = [
        $root . '/.user.ini' => "upload_max_filesize = 1G\npost_max_size = 1G\nmemory_limit = 1G\nmax_execution_time = 300\nmax_input_time = 300\nmax_input_vars = 10000\n",
        $root . '/.htaccess' => "<IfModule mod_php.c>\n    php_value upload_max_filesize 1G\n    php_value post_max_size 1G\n    php_value memory_limit 1G\n    php_value max_execution_time 300\n    php_value max_input_time 300\n    php_value max_input_vars 10000\n</IfModule>\n",
        $root . '/deployment/php/php.ini' => "; BuilderX production PHP baseline.\nfile_uploads = On\nupload_max_filesize = 1G\npost_max_size = 1G\nmax_file_uploads = 100\nmemory_limit = 1G\nmax_execution_time = 300\nmax_input_time = 300\nmax_input_vars = 10000\nopcache.enable = 1\nopcache.memory_consumption = 256\nopcache.interned_strings_buffer = 32\nopcache.max_accelerated_files = 30000\nopcache.validate_timestamps = 0\nopcache.save_comments = 1\n",
    ];

    foreach ($files as $path => $content) {
        if (file_exists($path) && !is_writable($path)) {
            throw new RuntimeException($path . ' is not writable.');
        }
        file_put_contents($path, $content);
    }
}

function bx_safe_layout_schema(array $schema): array
{
    $encoded = json_encode($schema, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return [];
    }

    $blockedPatterns = [
        '/<\s*script/i',
        '/javascript\s*:/i',
        '/on[a-z]+\s*=/i',
        '/expression\s*\(/i',
        '/behavior\s*:/i',
        '/@import/i',
        '/url\s*\(/i',
    ];

    foreach ($blockedPatterns as $pattern) {
        if (preg_match($pattern, $encoded)) {
            return [];
        }
    }

    return $schema;
}

function bx_admin_seed_settings(): void
{
    $softwareName = bx_setting('software_name', 'BuilderX');
    $settings = [
        ['software_name', 'BuilderX', 'general'],
        ['software_description', 'Dynamic Enterprise Form, Workflow, Reporting, and Accounting Builder', 'general'],
        ['version', '0.1.0-foundation', 'general'],
        ['default_language', 'en', 'localization'],
        ['default_time_zone', 'Asia/Manila', 'localization'],
        ['default_currency', 'PHP', 'localization'],
        ['session_timeout_minutes', '120', 'security'],
        ['password_min_length', '10', 'security'],
        ['password_expiration_days', '90', 'security'],
        ['password_history_count', '3', 'security'],
        ['password_reset_token_minutes', '30', 'security'],
        ['account_recovery_2fa_policy', 'optional-planned', 'security'],
        ['account_recovery_email_delivery', 'placeholder', 'security'],
        ['project_user_auth_email_domain', 'rbms.app', 'security'],
        ['debug_enabled', '0', 'debug'],
        ['debug_show_queries', '0', 'debug'],
        ['debug_show_files', '1', 'debug'],
        ['debug_show_phase_task', '1', 'debug'],
        ['debug_log_traces', '0', 'debug'],
        ['debug_allowed_roles', 'administrator', 'debug'],
        ['debug_trace_retention_days', '7', 'debug'],
        ['app_url', 'http://192.168.1.70/builderX', 'application'],
        ['public_path', '/', 'application'],
        ['admin_path', '/administrator', 'application'],
        ['system_path', '/phases', 'application'],
        ['contact_name', '', 'contact'],
        ['contact_email', '', 'contact'],
        ['contact_phone', '', 'contact'],
        ['contact_address', '', 'contact'],
        ['admin_default_tab', 'dashboard', 'interface'],
        ['sharingan_enabled', '0', 'interface'],
        ['login_header_title', $softwareName, 'login'],
        ['login_header_subtitle', 'Administrator Portal', 'login'],
        ['login_badge_label', 'Administrator Portal', 'login'],
        ['login_title', $softwareName, 'login'],
        ['login_description', 'Manage users, roles, branches, projects, settings, audit logs, and runtime health from one operational workspace.', 'login'],
        ['login_feature_1_title', 'Protected Portal', 'login'],
        ['login_feature_1_description', 'Administrator role is required for access.', 'login'],
        ['login_feature_2_title', 'Session Tracking', 'login'],
        ['login_feature_2_description', 'Login history and active sessions are recorded.', 'login'],
        ['login_feature_3_title', 'Phase Manager', 'login'],
        ['login_feature_3_description', 'Open the project control surface when planning work.', 'login'],
        ['login_setup_feature_1_title', 'First Administrator', 'login'],
        ['login_setup_feature_1_description', 'Create the initial account for this project.', 'login'],
        ['login_setup_feature_2_title', 'No Shared Default', 'login'],
        ['login_setup_feature_2_description', 'Use a project-specific password before continuing.', 'login'],
        ['login_setup_feature_3_title', 'Phase Manager', 'login'],
        ['login_setup_feature_3_description', 'Review phase notes and build targets anytime.', 'login'],
        ['login_form_title', 'Administrator Login', 'login'],
        ['login_form_description', 'Administrator role is required to access this portal.', 'login'],
        ['login_username_label', 'Username or Email', 'login'],
        ['login_password_label', 'Password', 'login'],
        ['login_submit_label', 'Login', 'login'],
        ['login_setup_form_title', 'Create Initial Administrator', 'login'],
        ['login_setup_form_description', 'Define the first administrator before opening protected screens.', 'login'],
        ['login_setup_full_name_label', 'Full Name', 'login'],
        ['login_setup_email_label', 'Email', 'login'],
        ['login_setup_username_label', 'Username', 'login'],
        ['login_setup_password_label', 'Password', 'login'],
        ['login_setup_password_confirm_label', 'Confirm Password', 'login'],
        ['login_setup_submit_label', 'Create Administrator', 'login'],
        ['login_user_portal_label', 'User Portal', 'login'],
        ['login_phase_manager_label', 'Phase Manager', 'login'],
        ['template_presets', json_encode(bx_template_default_presets(), JSON_UNESCAPED_SLASHES), 'template'],
    ];

    foreach ($settings as $setting) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = ?', [$setting[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $setting[0], $setting[1], $setting[2]]
            );
        }
    }

    $positions = [
        ['ADMINISTRATOR', 'Administrator', 'Full administrative operator position.', 'Administrators'],
        ['NURSE', 'Nurse', 'Clinical ward or station user position.', 'Encoders'],
        ['ENCODER', 'Encoder', 'Data entry and record maintenance position.', 'Encoders'],
        ['AUDITOR', 'Auditor', 'Read-back and audit review position.', 'Auditors'],
    ];
    foreach ($positions as $position) {
        $groupKey = (string) (bx_db()->GetOne('SELECT group_key FROM builder_group WHERE group_name = ? LIMIT 1', [$position[3]]) ?: '');
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_user_position WHERE position_code = ?', [$position[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_user_position (position_key, position_code, position_name, group_key, position_description) VALUES (?, ?, ?, ?, ?)',
                [bx_uuid(), $position[0], $position[1], $groupKey === '' ? null : $groupKey, $position[2]]
            );
        } elseif ($groupKey !== '') {
            bx_db()->Execute(
                "UPDATE builder_user_position SET group_key = ? WHERE position_code = ? AND (group_key IS NULL OR group_key = '')",
                [$groupKey, $position[0]]
            );
        }
    }

    bx_seed_android_project_settings();
    bx_seed_media_project_settings();
}

function bx_audit_filters_from_request(): array
{
    $filterKeys = [
        'audit_user',
        'audit_action',
        'audit_module',
        'audit_record',
        'audit_ip',
        'audit_reason',
        'audit_date_from',
        'audit_date_to',
    ];
    $filters = [];

    foreach ($filterKeys as $key) {
        $filters[$key] = trim((string) ($_GET[$key] ?? ''));
    }

    foreach (['audit_date_from', 'audit_date_to'] as $dateKey) {
        if ($filters[$dateKey] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateKey])) {
            $filters[$dateKey] = '';
        }
    }

    return $filters;
}

function bx_audit_rows(array $filters, int $limit = 250): array
{
    $where = ['1 = 1'];
    $params = [];

    $likeFilters = [
        'audit_user' => ["CONCAT(COALESCE(u.user_login, ''), ' ', COALESCE(u.user_name, ''), ' ', COALESCE(u.user_email, ''))"],
        'audit_action' => ['a.action'],
        'audit_module' => ['a.module'],
        'audit_record' => ['a.record_key'],
        'audit_ip' => ['a.ip_address'],
        'audit_reason' => ['a.reason'],
    ];

    foreach ($likeFilters as $filterKey => $columns) {
        if (($filters[$filterKey] ?? '') === '') {
            continue;
        }

        $or = [];
        foreach ($columns as $column) {
            $or[] = "{$column} LIKE ?";
            $params[] = '%' . $filters[$filterKey] . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    if (($filters['audit_date_from'] ?? '') !== '') {
        $where[] = 'a.created_at >= ?';
        $params[] = $filters['audit_date_from'] . ' 00:00:00';
    }

    if (($filters['audit_date_to'] ?? '') !== '') {
        $where[] = 'a.created_at <= ?';
        $params[] = $filters['audit_date_to'] . ' 23:59:59';
    }

    $limit = max(1, min($limit, 1000));

    return bx_db()->GetAll("
        SELECT
            a.created_at,
            a.action,
            a.module,
            a.record_key,
            a.ip_address,
            a.user_agent,
            a.reason,
            a.branch_key,
            a.project_key,
            u.user_login,
            u.user_name,
            u.user_email
        FROM builder_audit_log a
        LEFT JOIN builder_user u ON u.user_key = a.user_key
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.x_id DESC
        LIMIT {$limit}
    ", $params);
}

function bx_audit_export_csv(array $rows): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="builderx-audit-log.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['created_at', 'user', 'email', 'action', 'module', 'record_key', 'branch_key', 'project_key', 'ip_address', 'user_agent', 'reason']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['created_at'] ?? '',
            $row['user_name'] ?: ($row['user_login'] ?? ''),
            $row['user_email'] ?? '',
            $row['action'] ?? '',
            $row['module'] ?? '',
            $row['record_key'] ?? '',
            $row['branch_key'] ?? '',
            $row['project_key'] ?? '',
            $row['ip_address'] ?? '',
            $row['user_agent'] ?? '',
            $row['reason'] ?? '',
        ]);
    }

    exit;
}

function bx_admin_table_exists(string $table): bool
{
    return (int) bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [BUILDERX_DB_NAME, $table]
    ) > 0;
}

function bx_admin_column_exists(string $table, string $column): bool
{
    return (int) bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, $table, $column]
    ) > 0;
}

function bx_admin_assignment_projection_ready(): bool
{
    return bx_admin_table_exists('project_group')
        && bx_admin_table_exists('project_position')
        && bx_admin_column_exists('project_user_group', 'assignment_key')
        && bx_admin_column_exists('project_user_group', 'assignment_status')
        && bx_admin_column_exists('project_user_group', 'position_key');
}

function bx_admin_group_projection_ready(): bool
{
    return bx_admin_table_exists('project_group');
}

function bx_admin_position_projection_ready(): bool
{
    return bx_admin_table_exists('project_group') && bx_admin_table_exists('project_position');
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    bx_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_admin') {
        $adminInput = [
            'login' => trim((string) $_POST['login']),
            'email' => trim((string) $_POST['email']),
            'name' => trim((string) $_POST['name']),
            'password' => (string) $_POST['password'],
            'password_confirm' => (string) $_POST['password_confirm'],
        ];
        if (!bx_create_initial_admin($adminInput)) {
            bx_admin_redirect_with_state('overview', [
                'initialAdmin' => [
                    'name' => $adminInput['name'],
                    'email' => $adminInput['email'],
                    'login' => $adminInput['login'],
                ],
            ]);
        }
        header('Location: ./');
        exit;
    }

    if ($action === 'login') {
        bx_audit('LOGIN_REJECTED', 'authentication', null, [
            'authentication_method' => 'legacy_password_disabled',
        ], 'Administrator password sign-in is disabled.');
        bx_flash('Administrator sign-in must use Firebase Auth.', 'error');
        header('Location: ./');
        exit;
    }

    if ($action === 'resolve_admin_login') {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        try {
            foreach (array_keys($_POST) as $fieldName) {
                if (!in_array((string) $fieldName, ['action', 'csrf', 'login'], true)) {
                    throw new InvalidArgumentException('admin_login_payload_invalid');
                }
            }
            $login = strtolower(trim((string) ($_POST['login'] ?? '')));
            if ($login === '' || strlen($login) > 120) {
                throw new InvalidArgumentException('admin_login_required');
            }
            $identity = null;
            if (bx_admin_table_exists('project_user')
                && bx_admin_column_exists('project_user', 'user_auth_username')
                && bx_admin_column_exists('project_user', 'user_auth_email')) {
                $identity = bx_db()->GetRow(
                    "SELECT user_auth_username, user_auth_email
                     FROM project_user
                     WHERE user_login = ? AND user_status = 'ACTIVE' AND (user_deleted = 0 OR user_deleted IS NULL)
                     LIMIT 1",
                    [$login]
                );
            }
            $firebaseIdentifier = strtolower(trim((string) ($identity['user_auth_email'] ?? '')));
            if (!filter_var($firebaseIdentifier, FILTER_VALIDATE_EMAIL)) {
                $firebaseIdentifier = strtolower(trim((string) ($identity['user_auth_username'] ?? '')));
            }
            // The profile email can be syntactically valid but stale. Resolve
            // the actual Firebase Auth email from the explicit Administrator
            // UID mapping before sending credentials to Firebase.
            $administratorIdentity = bx_db()->GetRow(
                "SELECT user_email, firebase_uid
                 FROM builder_user
                 WHERE user_login = ? AND user_status = 'ACTIVE' AND user_deleted_at IS NULL
                 LIMIT 1",
                [$login]
            );
            $firebaseAuthEmail = bx_admin_firebase_email_for_uid(trim((string) ($administratorIdentity['firebase_uid'] ?? '')));
            if (filter_var($firebaseAuthEmail, FILTER_VALIDATE_EMAIL)) {
                $firebaseIdentifier = $firebaseAuthEmail;
            }
            // Recovery compatibility: project_user may be empty while the
            // already-authorized Administrator identity still exists.
            if (!filter_var($firebaseIdentifier, FILTER_VALIDATE_EMAIL)) {
                $firebaseIdentifier = strtolower(trim((string) ($administratorIdentity['user_email'] ?? '')));
            }
            if (!filter_var($firebaseIdentifier, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('firebase_auth_identity_missing');
            }
            bx_admin_json_response([
                'ok' => true,
                'data' => ['firebase_identifier' => $firebaseIdentifier],
            ]);
        } catch (Throwable $exception) {
            $errorCode = preg_match('/^[a-z0-9_]+$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'admin_login_resolution_failed';
            bx_admin_json_response([
                'ok' => false,
                'message' => 'Administrator identity could not be resolved.',
                'code' => $errorCode,
            ], 401);
        }
    }

    if ($action === 'record_admin_login_failure') {
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $reason = trim((string) ($_POST['reason'] ?? 'firebase_auth_failed'));
        if ($login !== '' && preg_match('/^[a-z0-9_\/-]{1,80}$/', $reason) === 1) {
            bx_project_user_activity_history(null, $login, 'LOGIN', 'FAILED', $reason);
        }
        bx_admin_json_response(['ok' => true]);
    }

    if ($action === 'firebase_login') {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        try {
            foreach (array_keys($_POST) as $fieldName) {
                if (!in_array((string) $fieldName, ['action', 'csrf', 'firebase_id_token'], true)) {
                    throw new InvalidArgumentException('firebase_login_payload_invalid');
                }
            }
            foreach (['login', 'password', 'password_confirm', 'email', 'user_key', 'firebase_uid'] as $forbiddenField) {
                if (array_key_exists($forbiddenField, $_POST)) {
                    throw new InvalidArgumentException('firebase_login_payload_invalid');
                }
            }

            $idToken = trim((string) ($_POST['firebase_id_token'] ?? ''));
            if ($idToken === '') {
                throw new InvalidArgumentException('firebase_id_token_required');
            }

            // This internal RBMS installation does not require email verification.
            // Firebase Auth password/token validation and explicit Administrator
            // mapping/role checks remain mandatory.
            $firebaseIdentity = bx_admin_verify_firebase_id_token($idToken, false);
            $adminUser = bx_db()->GetRow(
                "SELECT * FROM builder_user
                 WHERE firebase_uid = ? AND user_status = 'ACTIVE' AND user_deleted_at IS NULL
                LIMIT 1",
                [$firebaseIdentity['uid']]
            );
            if (!$adminUser || !bx_is_admin($adminUser)) {
                throw new RuntimeException('firebase_admin_not_authorized');
            }

            bx_login_with_firebase_identity($adminUser, $firebaseIdentity);
            $authorization = bx_authorization_guard(['requireAdmin' => true, 'requireAdminFirebase' => true]);
            if (!$authorization['allowed'] || !bx_admin_session_boundary_allows()) {
                bx_logout();
                throw new RuntimeException('firebase_admin_not_authorized');
            }

            bx_admin_json_response([
                'ok' => true,
                'message' => 'Signed in to administrator portal.',
            ]);
        } catch (Throwable $exception) {
            $errorCode = preg_match('/^[a-z0-9_]+$/', $exception->getMessage()) === 1
                ? $exception->getMessage()
                : 'firebase_admin_login_failed';
            error_log('RBMS administrator Firebase login rejected: ' . $errorCode);
            $status = in_array($errorCode, ['firebase_auth_unavailable', 'firebase_session_unavailable'], true) ? 503 : 401;
            bx_admin_json_response([
                'ok' => false,
                'message' => 'Administrator sign-in failed.',
                'code' => $errorCode,
            ], $status);
        }
    }

    if ($action === 'logout') {
        bx_logout();
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: ./?tab=login', true, 303);
        exit;
    }

    if (in_array($action, ['save_branch', 'set_branch_status', 'save_project', 'set_project_status', 'save_user_position', 'set_user_position_status', 'save_user', 'set_user_status', 'reset_user_password', 'save_group', 'set_group_status', 'save_role', 'set_role_status', 'set_permission_status', 'save_permission_matrix', 'save_form', 'set_form_status', 'clone_form', 'publish_form', 'unpublish_form', 'import_form_json', 'export_form_json', 'save_form_field', 'set_form_field_status', 'move_form_field', 'save_form_layout', 'set_form_layout_status', 'save_system_settings', 'set_sharingan_enabled', 'restart_traverse', 'create_traverse_document', 'update_traverse_document', 'delete_traverse_document', 'set_traverse_document_status', 'save_bed_treatment', 'set_bed_treatment_status', 'save_bed_source', 'set_bed_source_status', 'update_bed_reference_sort_order', 'sync_project_building_floor', 'set_project_building_floor_status', 'update_project_building_floor_sort_order', 'resync_project_bed', 'create_project_task', 'update_project_task', 'delete_project_task', 'update_project_task_canvas_position', 'create_project_task_stage', 'update_project_task_stage', 'delete_project_task_stage', 'create_project_task_stage_response', 'update_project_task_stage_response', 'delete_project_task_stage_response', 'update_project_task_stage_connection', 'update_project_task_stage_sort_order', 'update_project_task_stage_response_sort_order', 'apply_runtime_project_config', 'save_template_preset', 'run_template_command'], true)) {
        $currentUser = bx_admin_require_authorization(['requireAdmin' => true, 'requireAdminFirebase' => true])['user'];

        if ($action === 'restart_traverse') {
            $result = bx_restart_traverse_service();
            bx_audit($result['ok'] ? 'RESTART' : 'ERROR', 'traverse.service', 'traverse', [
                'exit_code' => (int) $result['exitCode'],
            ], $result['ok'] ? 'Administrator requested a TRAVERSE restart.' : 'TRAVERSE restart request failed.');
            if (bx_admin_wants_json()) {
                bx_admin_json_response([
                    'ok' => $result['ok'],
                    'message' => $result['ok'] ? 'TRAVERSE restart requested.' : 'TRAVERSE could not be restarted.',
                    'code' => $result['message'],
                ], $result['ok'] ? 200 : 503);
            }
            bx_flash($result['ok'] ? 'TRAVERSE restart requested. Refresh the report to verify the new heartbeat.' : 'TRAVERSE could not be restarted. Check the service permission and system journal.', $result['ok'] ? 'success' : 'error');
            bx_admin_redirect_settings('traverse');
        }

        if (in_array($action, ['create_traverse_document', 'update_traverse_document', 'delete_traverse_document', 'set_traverse_document_status'], true)) {
            $registryId = (int) ($_POST['xId'] ?? 0);
            $collection = trim((string) ($_POST['firebase_collection'] ?? ''));
            $status = strtoupper(trim((string) ($_POST['traverse_status'] ?? 'ACTIVE')));
            $registryError = '';
            if ($action !== 'delete_traverse_document' && ($collection === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/', $collection))) {
                $registryError = 'Collection must start with a letter and contain only letters, numbers, or underscores (maximum 80 characters).';
            } elseif ($action !== 'delete_traverse_document' && !in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
                $registryError = 'TRAVERSE status must be ACTIVE or INACTIVE.';
            } elseif ($action !== 'create_traverse_document' && $registryId < 1) {
                $registryError = 'A valid TRAVERSE registry ID is required.';
            }
            if ($registryError === '' && (int) bx_db()->GetOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [BUILDERX_DB_NAME, 'project_traverse_document']) !== 1) {
                $registryError = 'TRAVERSE document registry table is not ready.';
            }
            if ($registryError === '') {
                try {
                    if ($action === 'create_traverse_document') {
                        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM project_traverse_document WHERE firebase_collection = ?', [$collection]) > 0) throw new RuntimeException('This Firebase collection is already registered.');
                        bx_db()->Execute('INSERT INTO project_traverse_document (firebase_collection, traverse_status) VALUES (?, ?)', [$collection, $status]);
                        $registryId = (int) bx_db()->GetOne('SELECT LAST_INSERT_ID()');
                        bx_audit('CREATE', 'project_traverse_document', (string) $registryId, ['firebase_collection' => $collection, 'traverse_status' => $status], 'Administrator added a TRAVERSE registry collection.');
                    } elseif ($action === 'update_traverse_document') {
                        if (!bx_db()->GetRow('SELECT xId FROM project_traverse_document WHERE xId = ? LIMIT 1', [$registryId])) throw new RuntimeException('TRAVERSE registry entry was not found.');
                        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM project_traverse_document WHERE firebase_collection = ? AND xId <> ?', [$collection, $registryId]) > 0) throw new RuntimeException('This Firebase collection is already registered.');
                        bx_db()->Execute('UPDATE project_traverse_document SET firebase_collection = ?, traverse_status = ? WHERE xId = ?', [$collection, $status, $registryId]);
                        bx_audit('UPDATE', 'project_traverse_document', (string) $registryId, ['firebase_collection' => $collection, 'traverse_status' => $status], 'Administrator updated a TRAVERSE registry collection.');
                    } elseif ($action === 'set_traverse_document_status') {
                        if (!bx_db()->GetRow('SELECT xId FROM project_traverse_document WHERE xId = ? LIMIT 1', [$registryId])) throw new RuntimeException('TRAVERSE registry entry was not found.');
                        bx_db()->Execute('UPDATE project_traverse_document SET traverse_status = ? WHERE xId = ?', [$status, $registryId]);
                        bx_audit('UPDATE', 'project_traverse_document', (string) $registryId, ['traverse_status' => $status], 'Administrator changed a TRAVERSE registry status.');
                    } else {
                        $existing = bx_db()->GetRow('SELECT xId, firebase_collection FROM project_traverse_document WHERE xId = ? LIMIT 1', [$registryId]);
                        if (!$existing) throw new RuntimeException('TRAVERSE registry entry was not found.');
                        bx_db()->Execute('DELETE FROM project_traverse_document WHERE xId = ?', [$registryId]);
                        if (bx_db()->GetRow('SELECT xId FROM project_traverse_document WHERE xId = ? LIMIT 1', [$registryId])) throw new RuntimeException('TRAVERSE registry entry could not be deleted.');
                        bx_audit('DELETE', 'project_traverse_document', (string) $registryId, ['firebase_collection' => (string) ($existing['firebase_collection'] ?? '')], 'Administrator deleted a TRAVERSE registry collection.');
                    }
                    if ($action !== 'delete_traverse_document') {
                        $readBack = bx_db()->GetRow('SELECT firebase_collection, traverse_status FROM project_traverse_document WHERE xId = ? LIMIT 1', [$registryId]);
                        if (!$readBack || ($action !== 'set_traverse_document_status' && (string) $readBack['firebase_collection'] !== $collection) || (string) $readBack['traverse_status'] !== $status) throw new RuntimeException('TRAVERSE registry read-back did not match the submitted values.');
                    }
                    bx_flash('TRAVERSE registry saved. Restart TRAVERSE to apply this registry change.', 'success');
                } catch (Throwable $error) {
                    $registryError = $error->getMessage();
                }
            }
            if ($registryError !== '') bx_flash('TRAVERSE registry action failed: ' . $registryError, 'error');
            if (bx_admin_wants_json()) bx_admin_json_response(['ok' => $registryError === '', 'message' => $registryError === '' ? 'TRAVERSE registry saved.' : $registryError], $registryError === '' ? 200 : 422);
            bx_admin_redirect_settings('traverse');
        }

        if ($action === 'set_sharingan_enabled') {
            $newValue = (string) ($_POST['enabled'] ?? '');
            if (!in_array($newValue, ['0', '1'], true)) {
                bx_admin_json_response(['ok' => false, 'message' => 'Sharingan must be on or off.'], 422);
            }

            $settingKey = (string) bx_db()->GetOne(
                "SELECT setting_key FROM builder_system_setting WHERE setting_name = 'sharingan_enabled' AND setting_status = 'ACTIVE'"
            );
            if ($settingKey === '') {
                $settingKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
                    [$settingKey, 'sharingan_enabled', '0', 'interface']
                );
            }

            $previousValue = (string) bx_setting('sharingan_enabled', '0');
            if ($newValue !== $previousValue) {
                bx_db()->Execute(
                    "UPDATE builder_system_setting SET setting_value = ?, setting_group = 'interface', setting_status = 'ACTIVE' WHERE setting_key = ?",
                    [$newValue, $settingKey]
                );
                bx_audit('UPDATE', 'builder_system_setting', $settingKey, [
                    'setting_name' => 'sharingan_enabled',
                    'enabled' => $newValue === '1',
                ], 'Administrator changed Sharingan availability.');
            }

            bx_admin_json_response([
                'ok' => true,
                'message' => $newValue === '1' ? 'Sharingan is enabled for allowed product surfaces.' : 'Sharingan is disabled outside Administrator control.',
                'sharinganEnabled' => $newValue === '1',
            ]);
        }

        $bedReferenceReturnTab = static function (string $fallback): string {
            $returnTab = trim((string) ($_POST['return_tab'] ?? $fallback));
            return in_array($returnTab, ['bed-management', 'bed-treatment', 'bed-source', 'floor-management'], true) ? $returnTab : $fallback;
        };

        // Task Builder mutations are Firebase-first. TRAVERSE owns the later
        // projection; the legacy MySQL CRUD branches below are intentionally
        // not reachable for these actions.
        $taskFirebaseFirstActions = [
            'create_project_task', 'update_project_task', 'delete_project_task',
            'update_project_task_canvas_position', 'create_project_task_stage',
            'update_project_task_stage', 'delete_project_task_stage',
            'create_project_task_stage_response', 'update_project_task_stage_response',
            'delete_project_task_stage_response', 'update_project_task_stage_connection',
            'update_project_task_stage_sort_order', 'update_project_task_stage_response_sort_order',
        ];
        if (in_array($action, $taskFirebaseFirstActions, true)) {
            $taskBuilderSelectedTaskKey = trim((string) ($_POST['task_key'] ?? $_POST['selected_task_key'] ?? ''));
            $taskCollection = str_contains($action, 'stage_response') ? 'project_task_stage_response' : (str_contains($action, 'stage') ? 'project_task_stage' : 'project_task');
            $taskDocumentKey = $taskCollection === 'project_task'
                ? trim((string) ($_POST['task_key'] ?? ''))
                : trim((string) ($_POST[$taskCollection === 'project_task_stage' ? 'task_stage_key' : 'task_stage_response_key'] ?? ''));
            $taskOperation = str_starts_with($action, 'create_') ? 'create' : (str_starts_with($action, 'delete_') ? 'soft_delete' : 'update');
            $taskRecord = $_POST;
            $taskRecord['stage_count'] = (int) ($taskRecord['stage_count'] ?? 0);
            $taskRecord['response_count'] = (int) ($taskRecord['response_count'] ?? 0);
            try {
                $firebaseTasks = [];
                if ($action === 'update_project_task_stage_sort_order') {
                    foreach (array_values((array) ($_POST['stage_order_keys'] ?? [])) as $index => $stageKey) {
                        $firebaseTasks[] = bx_admin_write_project_task_firebase_first('project_task_stage', [
                            'task_key' => $taskBuilderSelectedTaskKey,
                            'task_stage_key' => (string) $stageKey,
                            'stage_sort_order' => $index + 1,
                        ], 'update', (string) $stageKey);
                    }
                } elseif ($action === 'update_project_task_stage_response_sort_order') {
                    foreach (array_values((array) ($_POST['response_order_keys'] ?? [])) as $index => $responseKey) {
                        $firebaseTasks[] = bx_admin_write_project_task_firebase_first('project_task_stage_response', [
                            'task_key' => $taskBuilderSelectedTaskKey,
                            'task_stage_key' => (string) ($_POST['task_stage_key'] ?? ''),
                            'task_stage_response_key' => (string) $responseKey,
                            'response_sort_order' => $index + 1,
                        ], 'update', (string) $responseKey);
                    }
                } else {
                    $firebaseTasks[] = bx_admin_write_project_task_firebase_first($taskCollection, $taskRecord, $taskOperation, $taskDocumentKey);
                }
                foreach ($firebaseTasks as $firebaseTask) {
                    if (($firebaseTask['ok'] ?? false) !== true) {
                        throw new RuntimeException((string) ($firebaseTask['message'] ?? 'Firebase Task Builder write failed.'));
                    }
                }
                $savedKey = (string) (($firebaseTasks[0]['document_key'] ?? '') ?: $taskDocumentKey);
                if ($taskCollection === 'project_task') $taskBuilderSelectedTaskKey = $savedKey;
                bx_mutation_lifecycle_flash('Task Builder change saved to Firebase.', 'success', [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Task Builder fields were accepted.'],
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => $taskCollection . ' acknowledged with document ID ' . $savedKey . '.'],
                    ['label' => 'TRAVERSE', 'status' => 'pending', 'detail' => 'mysql_sync_status=PENDING; MySQL projection will be processed by TRAVERSE.'],
                ]);
                if (bx_admin_wants_json()) {
                    $responsePayload = null;
                    if ($taskCollection === 'project_task_stage_response') {
                        $responsePayload = $taskRecord;
                        $responsePayload['task_stage_response_key'] = $savedKey;
                        $responsePayload['response_status'] = $taskOperation === 'soft_delete' ? 'DELETED' : (string) ($taskRecord['response_status'] ?? 'ACTIVE');
                    }
                    $taskPayload = null;
                    if ($taskCollection === 'project_task') {
                        $taskPayload = $taskRecord;
                        $taskPayload['task_key'] = $savedKey;
                        $taskPayload['task_status'] = $taskOperation === 'soft_delete' ? 'DELETED' : (string) ($taskRecord['task_status'] ?? 'INACTIVE');
                    }
                    bx_admin_json_response([
                        'ok' => true,
                        'message' => 'Task Builder change saved to Firebase.',
                        'document_key' => $savedKey,
                        'task' => $taskPayload,
                        'response' => $responsePayload,
                        'firebase_sync' => $firebaseTasks[0] ?? ['ok' => true],
                    ]);
                }
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Task Builder change was not saved.', 'error', [
                    ['label' => 'Validation', 'status' => 'action_required', 'detail' => $error->getMessage()],
                    ['label' => 'Firebase', 'status' => 'not_started', 'detail' => 'No acknowledged Firebase write was completed.'],
                ], $error->getMessage());
                if (bx_admin_wants_json()) {
                    bx_admin_json_response([
                        'ok' => false,
                        'message' => $error->getMessage(),
                        'firebase_sync' => ['ok' => false],
                    ], 422);
                }
            }
            header('Location: ./?tab=task-builder' . (preg_match('/^[A-Za-z0-9]{20}$/', $taskBuilderSelectedTaskKey) ? '&selected_task_key=' . rawurlencode($taskBuilderSelectedTaskKey) : ''));
            exit;
        }

        if ($action === 'save_bed_treatment') {
            try {
                $treatment = bx_save_project_bed_treatment($_POST, (string) ($currentUser['user_key'] ?? ''));
                $firebaseSync = $treatment['_firebase'] ?? ['ok' => false, 'message' => 'Firebase acknowledgement was not returned.'];
                bx_mutation_lifecycle_flash('Bed treatment saved.', 'success', [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Treatment fields accepted.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'Bed treatment option was saved.'],
                    ['label' => 'Read-back', 'status' => 'complete', 'detail' => 'Bed treatment option was verified after save.'],
                    bx_admin_bed_reference_firebase_step($firebaseSync),
                ]);
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Bed treatment was not saved.', 'error', [
                    ['label' => 'Validation', 'status' => 'action_required', 'detail' => $error->getMessage()],
                    ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No verified bed treatment save was completed.'],
                ], $error->getMessage());
            }
            bx_admin_redirect($bedReferenceReturnTab('bed-treatment'));
        }

        if ($action === 'set_bed_treatment_status') {
            try {
                $treatment = bx_set_project_bed_treatment_status($_POST, (string) ($currentUser['user_key'] ?? ''));
                $firebaseSync = $treatment['_firebase'] ?? ['ok' => false, 'message' => 'Firebase acknowledgement was not returned.'];
                bx_mutation_lifecycle_flash('Bed treatment status updated.', 'success', [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Treatment status accepted.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'Bed treatment status is ' . (string) ($treatment['treatment_status'] ?? '') . '.'],
                    ['label' => 'Read-back', 'status' => 'complete', 'detail' => 'Bed treatment status was verified after update.'],
                    bx_admin_bed_reference_firebase_step($firebaseSync),
                ]);
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Bed treatment status was not updated.', 'error', [
                    ['label' => 'Validation', 'status' => 'action_required', 'detail' => $error->getMessage()],
                    ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No verified bed treatment status update was completed.'],
                ], $error->getMessage());
            }
            bx_admin_redirect($bedReferenceReturnTab('bed-treatment'));
        }

        if ($action === 'save_bed_source') {
            try {
                $source = bx_save_project_bed_source($_POST, (string) ($currentUser['user_key'] ?? ''));
                $firebaseSync = $source['_firebase'] ?? ['ok' => false, 'message' => 'Firebase acknowledgement was not returned.'];
                bx_mutation_lifecycle_flash('Admission source saved.', 'success', [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Admission source fields accepted.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'Admission source option was saved.'],
                    ['label' => 'Read-back', 'status' => 'complete', 'detail' => 'Admission source option was verified after save.'],
                    bx_admin_bed_reference_firebase_step($firebaseSync),
                ]);
                if (bx_admin_wants_json()) {
                    bx_admin_json_response(['ok' => true, 'bed_source_key' => (string) ($source['bed_source_key'] ?? ''), 'firebase_sync' => $firebaseSync]);
                }
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Admission source was not saved.', 'error', [
                    ['label' => 'Validation', 'status' => 'action_required', 'detail' => $error->getMessage()],
                    ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No verified admission source save was completed.'],
                ], $error->getMessage());
            }
            bx_admin_redirect($bedReferenceReturnTab('bed-source'));
        }

        if ($action === 'set_bed_source_status') {
            try {
                $source = bx_set_project_bed_source_status($_POST, (string) ($currentUser['user_key'] ?? ''));
                $firebaseSync = $source['_firebase'] ?? ['ok' => false, 'message' => 'Firebase acknowledgement was not returned.'];
                bx_mutation_lifecycle_flash('Admission source status updated.', 'success', [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Admission source status accepted.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'Admission source status is ' . (string) ($source['bed_source_status'] ?? '') . '.'],
                    ['label' => 'Read-back', 'status' => 'complete', 'detail' => 'Admission source status was verified after update.'],
                    bx_admin_bed_reference_firebase_step($firebaseSync),
                ]);
                if (bx_admin_wants_json()) {
                    bx_admin_json_response(['ok' => true, 'bed_source_key' => (string) ($source['bed_source_key'] ?? ''), 'firebase_sync' => $firebaseSync]);
                }
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Admission source status was not updated.', 'error', [
                    ['label' => 'Validation', 'status' => 'action_required', 'detail' => $error->getMessage()],
                    ['label' => 'Persistence', 'status' => 'not_started', 'detail' => 'No verified admission source status update was completed.'],
                ], $error->getMessage());
            }
            bx_admin_redirect($bedReferenceReturnTab('bed-source'));
        }

        if ($action === 'update_bed_reference_sort_order') {
            try {
                $rows = bx_update_project_bed_reference_sort_order($_POST, (string) ($currentUser['user_key'] ?? ''));
                $referenceType = strtolower(trim((string) ($_POST['reference_type'] ?? '')));
                bx_admin_json_response([
                    'ok' => true,
                    'message' => 'Bed reference order saved.',
                    'rows' => $rows,
                    'firebase_sync' => ['ok' => true, 'collection' => $referenceType === 'source' ? 'project_bed_source' : $referenceType, 'mysql_sync_status' => 'PENDING'],
                ]);
            } catch (Throwable $error) {
                bx_admin_json_response([
                    'ok' => false,
                    'message' => $error->getMessage(),
                ], 422);
            }
        }


        if ($action === 'save_branch') {
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $branchCode = strtoupper(trim((string) ($_POST['branch_code'] ?? '')));
            $branchName = trim((string) ($_POST['branch_name'] ?? ''));
            $branchAddress = trim((string) ($_POST['branch_address'] ?? ''));
            $branchContact = trim((string) ($_POST['branch_contact'] ?? ''));
            $branchStatus = trim((string) ($_POST['branch_status'] ?? 'ACTIVE'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchCode === '' || $branchName === '') {
                bx_flash('Branch code and branch name are required.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $branchCode)) {
                bx_flash('Branch code must use 2-40 uppercase letters, numbers, underscores, or hyphens.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if (!in_array($branchStatus, $allowedStatuses, true)) {
                bx_flash('Invalid branch status.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_branch WHERE branch_code = ? AND branch_key <> ?',
                [$branchCode, $branchKey ?: '__new__']
            );

            if ($duplicate > 0) {
                bx_flash('Branch code already exists.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            if ($branchKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_branch WHERE branch_key = ?', [$branchKey]);
                if (!$existing) {
                    bx_flash('Branch was not found.', 'error');
                    header('Location: ./?tab=branches');
                    exit;
                }

                bx_db()->Execute(
                    'UPDATE builder_branch SET branch_code = ?, branch_name = ?, branch_status = ?, branch_address = ?, branch_contact = ? WHERE branch_key = ?',
                    [$branchCode, $branchName, $branchStatus, $branchAddress, $branchContact, $branchKey]
                );
                bx_audit('UPDATE', 'builder_branch', $branchKey, [
                    'branch_code' => $branchCode,
                    'branch_name' => $branchName,
                    'branch_status' => $branchStatus,
                ], 'Administrator updated branch.');
                $readBack = bx_db()->GetRow('SELECT branch_code, branch_name, branch_status FROM builder_branch WHERE branch_key = ?', [$branchKey]) ?: [];
                bx_mutation_lifecycle_flash('Branch updated.', 'success', [
                    ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'The branch update statement completed.'],
                    ['label' => 'Read-back', 'status' => $readBack ? 'complete' : 'blocked', 'detail' => $readBack ? 'Saved branch read back as ' . (string) $readBack['branch_code'] . ' / ' . (string) $readBack['branch_status'] . '.' : 'The saved branch row was not found.'],
                ]);
            } else {
                $branchKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_branch (branch_key, branch_code, branch_name, branch_status, branch_address, branch_contact) VALUES (?, ?, ?, ?, ?, ?)',
                    [$branchKey, $branchCode, $branchName, $branchStatus, $branchAddress, $branchContact]
                );
                bx_audit('CREATE', 'builder_branch', $branchKey, [
                    'branch_code' => $branchCode,
                    'branch_name' => $branchName,
                    'branch_status' => $branchStatus,
                ], 'Administrator created branch.');
                $readBack = bx_db()->GetRow('SELECT branch_code, branch_name, branch_status FROM builder_branch WHERE branch_key = ?', [$branchKey]) ?: [];
                bx_mutation_lifecycle_flash('Branch created.', 'success', [
                    ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                    ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'The branch insert statement completed.'],
                    ['label' => 'Read-back', 'status' => $readBack ? 'complete' : 'blocked', 'detail' => $readBack ? 'Saved branch read back as ' . (string) $readBack['branch_code'] . ' / ' . (string) $readBack['branch_status'] . '.' : 'The saved branch row was not found.'],
                ]);
            }

            header('Location: ./?tab=branches');
            exit;
        }

        if ($action === 'set_branch_status') {
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $branchStatus = trim((string) ($_POST['branch_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || !in_array($branchStatus, $allowedStatuses, true)) {
                bx_flash('Invalid branch status request.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_branch WHERE branch_key = ?', [$branchKey]);
            if (!$existing) {
                bx_flash('Branch was not found.', 'error');
                header('Location: ./?tab=branches');
                exit;
            }

            bx_db()->Execute('UPDATE builder_branch SET branch_status = ? WHERE branch_key = ?', [$branchStatus, $branchKey]);
            bx_audit($branchStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_branch', $branchKey, [
                'branch_code' => $existing['branch_code'],
                'branch_status' => $branchStatus,
            ], 'Administrator changed branch status.');
            $readBack = bx_db()->GetRow('SELECT branch_code, branch_status FROM builder_branch WHERE branch_key = ?', [$branchKey]) ?: [];
            bx_mutation_lifecycle_flash('Branch status updated.', 'success', [
                ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                ['label' => 'Persistence', 'status' => 'complete', 'detail' => 'The branch status update completed.'],
                ['label' => 'Read-back', 'status' => $readBack ? 'complete' : 'blocked', 'detail' => $readBack ? 'Saved branch read back as ' . (string) $readBack['branch_code'] . ' / ' . (string) $readBack['branch_status'] . '.' : 'The saved branch row was not found.'],
            ]);
            header('Location: ./?tab=branches');
            exit;
        }

        if ($action === 'save_project') {
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $projectCode = strtoupper(trim((string) ($_POST['project_code'] ?? '')));
            $projectName = trim((string) ($_POST['project_name'] ?? ''));
            $projectDescription = trim((string) ($_POST['project_description'] ?? ''));
            $projectStatus = trim((string) ($_POST['project_status'] ?? 'ACTIVE'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || $projectCode === '' || $projectName === '') {
                bx_flash('Branch, project code, and project name are required.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $projectCode)) {
                bx_flash('Project code must use 2-40 uppercase letters, numbers, underscores, or hyphens.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if (!in_array($projectStatus, $allowedStatuses, true)) {
                bx_flash('Invalid project status.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $branchExists = (int) bx_db()->GetOne(
                "SELECT COUNT(*) FROM builder_branch WHERE branch_key = ? AND branch_status <> 'DELETED'",
                [$branchKey]
            );
            if ($branchExists === 0) {
                bx_flash('Selected branch was not found or is deleted.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_project WHERE project_code = ? AND project_key <> ?',
                [$projectCode, $projectKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Project code already exists.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            if ($projectKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_project WHERE project_key = ?', [$projectKey]);
                if (!$existing) {
                    bx_flash('Project was not found.', 'error');
                    header('Location: ./?tab=projects');
                    exit;
                }

                bx_db()->Execute(
                    'UPDATE builder_project SET branch_key = ?, project_code = ?, project_name = ?, project_status = ?, project_description = ? WHERE project_key = ?',
                    [$branchKey, $projectCode, $projectName, $projectStatus, $projectDescription, $projectKey]
                );
                bx_audit('UPDATE', 'builder_project', $projectKey, [
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'project_status' => $projectStatus,
                    'branch_key' => $branchKey,
                ], 'Administrator updated project.');
                bx_flash('Project updated.', 'success');
            } else {
                $projectKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_project (project_key, branch_key, project_name, project_code, project_status, project_description) VALUES (?, ?, ?, ?, ?, ?)',
                    [$projectKey, $branchKey, $projectName, $projectCode, $projectStatus, $projectDescription]
                );
                bx_seed_android_project_settings();
                bx_seed_media_project_settings();
                bx_audit('CREATE', 'builder_project', $projectKey, [
                    'project_code' => $projectCode,
                    'project_name' => $projectName,
                    'project_status' => $projectStatus,
                    'branch_key' => $branchKey,
                ], 'Administrator created project.');
                bx_flash('Project created.', 'success');
            }

            header('Location: ./?tab=projects');
            exit;
        }

        if ($action === 'set_project_status') {
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $projectStatus = trim((string) ($_POST['project_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($projectKey === '' || !in_array($projectStatus, $allowedStatuses, true)) {
                bx_flash('Invalid project status request.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_project WHERE project_key = ?', [$projectKey]);
            if (!$existing) {
                bx_flash('Project was not found.', 'error');
                header('Location: ./?tab=projects');
                exit;
            }

            bx_db()->Execute('UPDATE builder_project SET project_status = ? WHERE project_key = ?', [$projectStatus, $projectKey]);
            bx_audit($projectStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_project', $projectKey, [
                'project_code' => $existing['project_code'],
                'project_status' => $projectStatus,
            ], 'Administrator changed project status.');
            bx_flash('Project status updated.', 'success');
            header('Location: ./?tab=projects');
            exit;
        }

        if ($action === 'save_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $branchKey = trim((string) ($_POST['branch_key'] ?? ''));
            $projectKey = trim((string) ($_POST['project_key'] ?? ''));
            $formCode = strtoupper(trim((string) ($_POST['form_code'] ?? '')));
            $formName = trim((string) ($_POST['form_name'] ?? ''));
            $formDescription = trim((string) ($_POST['form_description'] ?? ''));
            $formStatus = trim((string) ($_POST['form_status'] ?? 'DRAFT'));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];

            if ($branchKey === '' || $projectKey === '' || $formCode === '' || $formName === '') {
                bx_flash('Branch, project, form code, and form name are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[A-Z0-9_-]{2,80}$/', $formCode)) {
                bx_flash('Form code must use 2-80 uppercase letters, numbers, underscores, or hyphens.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($formStatus, $allowedStatuses, true)) {
                bx_flash('Invalid form status.', 'error');
                bx_admin_redirect('forms');
            }

            $project = bx_db()->GetRow(
                "SELECT project_key FROM builder_project WHERE project_key = ? AND branch_key = ? AND project_status <> 'DELETED'",
                [$projectKey, $branchKey]
            );
            if (!$project) {
                bx_flash('Selected project was not found under the selected branch.', 'error');
                bx_admin_redirect('forms');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form WHERE form_code = ? AND form_key <> ?',
                [$formCode, $formKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Form code already exists.', 'error');
                bx_admin_redirect('forms');
            }

            if ($formKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
                if (!$existing) {
                    bx_flash('Form was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form SET branch_key = ?, project_key = ?, form_code = ?, form_name = ?, form_description = ?, form_status = ?, form_updated_by_key = ? WHERE form_key = ?',
                    [$branchKey, $projectKey, $formCode, $formName, $formDescription, $formStatus, $currentUser['user_key'], $formKey]
                );
                bx_audit('UPDATE', 'builder_form', $formKey, [
                    'form_code' => $formCode,
                    'form_status' => $formStatus,
                    'branch_key' => $branchKey,
                    'project_key' => $projectKey,
                ], 'Administrator updated form.');
                bx_flash('Form updated.', 'success');
            } else {
                $formKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$formKey, $branchKey, $projectKey, $formCode, $formName, $formDescription, $formStatus, $currentUser['user_key'], $currentUser['user_key']]
                );
                bx_audit('CREATE', 'builder_form', $formKey, [
                    'form_code' => $formCode,
                    'form_status' => $formStatus,
                    'branch_key' => $branchKey,
                    'project_key' => $projectKey,
                ], 'Administrator created form.');
                bx_flash('Form created.', 'success');
            }

            bx_admin_redirect('forms');
        }

        if ($action === 'set_form_status') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $formStatus = trim((string) ($_POST['form_status'] ?? ''));
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'ARCHIVED', 'DELETED'];
            if ($formKey === '' || !in_array($formStatus, $allowedStatuses, true)) {
                bx_flash('Invalid form status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            $deletedSql = $formStatus === 'DELETED' ? ', form_deleted_at = CURRENT_TIMESTAMP, form_deleted_by_key = ?' : '';
            $params = $formStatus === 'DELETED'
                ? [$formStatus, $currentUser['user_key'], $currentUser['user_key'], $formKey]
                : [$formStatus, $currentUser['user_key'], $formKey];
            bx_db()->Execute(
                "UPDATE builder_form SET form_status = ?{$deletedSql}, form_updated_by_key = ? WHERE form_key = ?",
                $params
            );
            bx_audit($formStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form', $formKey, [
                'form_code' => $existing['form_code'],
                'form_status' => $formStatus,
            ], 'Administrator changed form status.');
            bx_flash('Form status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'clone_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            $cloneKey = bx_uuid();
            $baseCode = substr((string) $existing['form_code'], 0, 68);
            $cloneCode = $baseCode . '_COPY';
            $suffix = 2;
            while ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form WHERE form_code = ?', [$cloneCode]) > 0) {
                $cloneCode = $baseCode . '_COPY_' . $suffix;
                $suffix++;
            }

            bx_db()->Execute(
                'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_settings, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$cloneKey, $existing['branch_key'], $existing['project_key'], $cloneCode, $existing['form_name'] . ' Copy', $existing['form_description'], 'DRAFT', $existing['form_settings'], $currentUser['user_key'], $currentUser['user_key']]
            );
            bx_audit('CLONE', 'builder_form', $cloneKey, [
                'source_form_key' => $formKey,
                'form_code' => $cloneCode,
            ], 'Administrator cloned form.');
            bx_flash('Form cloned as draft.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'publish_form' || $action === 'unpublish_form') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$existing) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            if ($action === 'publish_form') {
                $versionNumber = ((int) bx_db()->GetOne('SELECT COALESCE(MAX(version_number), 0) FROM builder_form_version WHERE form_key = ?', [$formKey])) + 1;
                bx_db()->Execute(
                    'INSERT INTO builder_form_version (version_key, form_key, version_number, version_status, schema_snapshot, published_at, created_by_key) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)',
                    [bx_uuid(), $formKey, $versionNumber, 'PUBLISHED', json_encode($existing, JSON_UNESCAPED_SLASHES), $currentUser['user_key']]
                );
                bx_db()->Execute('UPDATE builder_form SET form_status = ?, form_schema_version = ?, form_updated_by_key = ? WHERE form_key = ?', ['ACTIVE', $versionNumber, $currentUser['user_key'], $formKey]);
                bx_audit('PUBLISH', 'builder_form', $formKey, ['version_number' => $versionNumber], 'Administrator published form.');
                bx_flash('Form published.', 'success');
            } else {
                bx_db()->Execute('UPDATE builder_form SET form_status = ?, form_updated_by_key = ? WHERE form_key = ?', ['INACTIVE', $currentUser['user_key'], $formKey]);
                bx_audit('UNPUBLISH', 'builder_form', $formKey, ['form_code' => $existing['form_code']], 'Administrator unpublished form.');
                bx_flash('Form unpublished.', 'success');
            }
            bx_admin_redirect('forms');
        }

        if ($action === 'import_form_json') {
            $rawJson = trim((string) ($_POST['form_json'] ?? ''));
            $import = json_decode($rawJson, true);
            if (!is_array($import)) {
                bx_flash('Import requires valid JSON.', 'error');
                bx_admin_redirect('forms');
            }
            $form = is_array($import['form'] ?? null) ? $import['form'] : $import;

            $branchKey = trim((string) ($form['branch_key'] ?? ''));
            $projectKey = trim((string) ($form['project_key'] ?? ''));
            $formCode = strtoupper(trim((string) ($form['form_code'] ?? '')));
            $formName = trim((string) ($form['form_name'] ?? ''));
            $formDescription = trim((string) ($form['form_description'] ?? ''));

            if ($branchKey === '' || $projectKey === '' || $formCode === '' || $formName === '') {
                bx_flash('Imported JSON must include branch_key, project_key, form_code, and form_name.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[A-Z0-9_-]{2,80}$/', $formCode)) {
                bx_flash('Imported form code is invalid.', 'error');
                bx_admin_redirect('forms');
            }

            $project = bx_db()->GetRow(
                "SELECT project_key FROM builder_project WHERE project_key = ? AND branch_key = ? AND project_status <> 'DELETED'",
                [$projectKey, $branchKey]
            );
            if (!$project) {
                bx_flash('Imported project was not found under the imported branch.', 'error');
                bx_admin_redirect('forms');
            }

            $baseCode = substr($formCode, 0, 68);
            $importCode = $baseCode;
            $suffix = 2;
            while ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form WHERE form_code = ?', [$importCode]) > 0) {
                $importCode = $baseCode . '_IMPORT_' . $suffix;
                $suffix++;
            }

            $formKey = bx_uuid();
            bx_db()->Execute(
                'INSERT INTO builder_form (form_key, branch_key, project_key, form_code, form_name, form_description, form_status, form_settings, form_created_by_key, form_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$formKey, $branchKey, $projectKey, $importCode, $formName, $formDescription, 'DRAFT', json_encode($form, JSON_UNESCAPED_SLASHES), $currentUser['user_key'], $currentUser['user_key']]
            );
            bx_audit('IMPORT', 'builder_form', $formKey, ['form_code' => $importCode], 'Administrator imported form JSON.');
            bx_flash('Form imported as draft.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'export_form_json') {
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $form = bx_db()->GetRow('SELECT * FROM builder_form WHERE form_key = ?', [$formKey]);
            if (!$form) {
                bx_flash('Form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $form['form_code']) . '.json"');
            echo json_encode(['form' => $form], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'save_form_field') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $fieldCode = strtolower(trim((string) ($_POST['field_code'] ?? '')));
            $fieldName = trim((string) ($_POST['field_name'] ?? ''));
            $fieldLabel = trim((string) ($_POST['field_label'] ?? ''));
            $fieldType = trim((string) ($_POST['field_type'] ?? 'text'));
            $dataType = trim((string) ($_POST['data_type'] ?? 'string'));
            $databaseColumnName = strtolower(trim((string) ($_POST['database_column_name'] ?? $fieldCode)));
            $fieldSortOrder = max(0, (int) ($_POST['field_sort_order'] ?? 0));
            $fieldStatus = trim((string) ($_POST['field_status'] ?? 'ACTIVE'));
            $defaultValue = trim((string) ($_POST['default_value'] ?? ''));
            $formulaExpression = trim((string) ($_POST['formula_expression'] ?? ''));
            $validationRaw = trim((string) ($_POST['validation_rules'] ?? ''));
            $optionRaw = trim((string) ($_POST['option_source'] ?? ''));
            $visibilityRule = trim((string) ($_POST['visibility_rule'] ?? ''));
            $editableRule = trim((string) ($_POST['editable_rule'] ?? ''));
            $rolePermission = trim((string) ($_POST['role_permission'] ?? ''));
            $gridWidth = max(60, min(600, (int) ($_POST['grid_width'] ?? 160)));
            $allowedFieldTypes = ['text', 'textarea', 'number', 'currency', 'date', 'datetime', 'select', 'checkbox', 'file', 'signature', 'lookup', 'formula', 'child_table', 'section'];
            $allowedDataTypes = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 'json'];
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'DELETED'];

            if ($formKey === '' || $fieldCode === '' || $fieldName === '' || $fieldLabel === '' || $databaseColumnName === '') {
                bx_flash('Form, field code, name, label, and database column are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!preg_match('/^[a-z][a-z0-9_]{1,79}$/', $fieldCode) || !preg_match('/^[a-z][a-z0-9_]{1,99}$/', $databaseColumnName)) {
                bx_flash('Field code and database column must use lowercase snake_case.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($fieldType, $allowedFieldTypes, true) || !in_array($dataType, $allowedDataTypes, true) || !in_array($fieldStatus, $allowedStatuses, true)) {
                bx_flash('Invalid field type, data type, or status.', 'error');
                bx_admin_redirect('forms');
            }

            $form = bx_db()->GetRow("SELECT form_key FROM builder_form WHERE form_key = ? AND form_status <> 'DELETED'", [$formKey]);
            if (!$form) {
                bx_flash('Selected form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            foreach (['validation_rules' => $validationRaw, 'option_source' => $optionRaw] as $jsonLabel => $jsonValue) {
                if ($jsonValue !== '' && json_decode($jsonValue, true) === null && json_last_error() !== JSON_ERROR_NONE) {
                    bx_flash(str_replace('_', ' ', ucfirst($jsonLabel)) . ' must be valid JSON.', 'error');
                    bx_admin_redirect('forms');
                }
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form_field WHERE form_key = ? AND (field_code = ? OR database_column_name = ?) AND field_key <> ?',
                [$formKey, $fieldCode, $databaseColumnName, $fieldKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Field code or database column already exists for this form.', 'error');
                bx_admin_redirect('forms');
            }

            $fieldSettings = [
                'visibility_rule' => $visibilityRule,
                'editable_rule' => $editableRule,
                'role_permission' => $rolePermission,
                'grid_width' => $gridWidth,
            ];
            $validationJson = $validationRaw === '' ? null : $validationRaw;
            $optionJson = $optionRaw === '' ? null : $optionRaw;
            $settingsJson = json_encode($fieldSettings, JSON_UNESCAPED_SLASHES);
            $isRequired = isset($_POST['is_required']) ? 1 : 0;
            $isUnique = isset($_POST['is_unique']) ? 1 : 0;
            $isSearchable = isset($_POST['is_searchable']) ? 1 : 0;
            $isSortable = isset($_POST['is_sortable']) ? 1 : 0;

            if ($fieldKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
                if (!$existing) {
                    bx_flash('Field was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form_field SET form_key = ?, field_code = ?, field_name = ?, field_label = ?, field_type = ?, data_type = ?, database_column_name = ?, field_sort_order = ?, field_status = ?, is_required = ?, is_unique = ?, is_searchable = ?, is_sortable = ?, default_value = ?, validation_rules = ?, option_source = ?, formula_expression = ?, field_settings = ? WHERE field_key = ?',
                    [$formKey, $fieldCode, $fieldName, $fieldLabel, $fieldType, $dataType, $databaseColumnName, $fieldSortOrder, $fieldStatus, $isRequired, $isUnique, $isSearchable, $isSortable, $defaultValue, $validationJson, $optionJson, $formulaExpression, $settingsJson, $fieldKey]
                );
                bx_audit('UPDATE', 'builder_form_field', $fieldKey, ['field_code' => $fieldCode, 'form_key' => $formKey], 'Administrator updated form field.');
                bx_flash('Form field updated.', 'success');
            } else {
                $fieldKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form_field (field_key, form_key, field_code, field_name, field_label, field_type, data_type, database_column_name, field_sort_order, field_status, is_required, is_unique, is_searchable, is_sortable, default_value, validation_rules, option_source, formula_expression, field_settings) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$fieldKey, $formKey, $fieldCode, $fieldName, $fieldLabel, $fieldType, $dataType, $databaseColumnName, $fieldSortOrder, $fieldStatus, $isRequired, $isUnique, $isSearchable, $isSortable, $defaultValue, $validationJson, $optionJson, $formulaExpression, $settingsJson]
                );
                bx_audit('CREATE', 'builder_form_field', $fieldKey, ['field_code' => $fieldCode, 'form_key' => $formKey], 'Administrator created form field.');
                bx_flash('Form field created.', 'success');
            }

            bx_admin_redirect('forms');
        }

        if ($action === 'set_form_field_status') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $fieldStatus = trim((string) ($_POST['field_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
            if ($fieldKey === '' || !in_array($fieldStatus, $allowedStatuses, true)) {
                bx_flash('Invalid field status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
            if (!$existing) {
                bx_flash('Field was not found.', 'error');
                bx_admin_redirect('forms');
            }

            bx_db()->Execute('UPDATE builder_form_field SET field_status = ? WHERE field_key = ?', [$fieldStatus, $fieldKey]);
            bx_audit($fieldStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form_field', $fieldKey, ['field_code' => $existing['field_code'], 'field_status' => $fieldStatus], 'Administrator changed form field status.');
            bx_flash('Form field status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'move_form_field') {
            $fieldKey = trim((string) ($_POST['field_key'] ?? ''));
            $direction = trim((string) ($_POST['direction'] ?? ''));
            $existing = bx_db()->GetRow('SELECT * FROM builder_form_field WHERE field_key = ?', [$fieldKey]);
            if (!$existing || !in_array($direction, ['up', 'down'], true)) {
                bx_flash('Invalid field reorder request.', 'error');
                bx_admin_redirect('forms');
            }

            $operator = $direction === 'up' ? '<' : '>';
            $order = $direction === 'up' ? 'DESC' : 'ASC';
            $neighbor = bx_db()->GetRow(
                "SELECT * FROM builder_form_field WHERE form_key = ? AND field_status <> 'DELETED' AND field_sort_order {$operator} ? ORDER BY field_sort_order {$order}, x_id {$order} LIMIT 1",
                [$existing['form_key'], $existing['field_sort_order']]
            );
            if ($neighbor) {
                bx_db()->Execute('UPDATE builder_form_field SET field_sort_order = ? WHERE field_key = ?', [$neighbor['field_sort_order'], $fieldKey]);
                bx_db()->Execute('UPDATE builder_form_field SET field_sort_order = ? WHERE field_key = ?', [$existing['field_sort_order'], $neighbor['field_key']]);
                bx_audit('REORDER', 'builder_form_field', $fieldKey, ['direction' => $direction], 'Administrator reordered form field.');
            }
            bx_flash('Form field order updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'save_form_layout') {
            $layoutKey = trim((string) ($_POST['layout_key'] ?? ''));
            $formKey = trim((string) ($_POST['form_key'] ?? ''));
            $versionKey = trim((string) ($_POST['version_key'] ?? ''));
            $layoutName = trim((string) ($_POST['layout_name'] ?? ''));
            $layoutType = trim((string) ($_POST['layout_type'] ?? 'FORM'));
            $layoutStatus = trim((string) ($_POST['layout_status'] ?? 'DRAFT'));
            $layoutSortOrder = max(0, (int) ($_POST['layout_sort_order'] ?? 0));
            $schemaRaw = trim((string) ($_POST['layout_schema'] ?? ''));
            $customCss = '';
            $allowedTypes = ['FORM', 'TABLE', 'DETAIL', 'PRINT', 'MOBILE'];
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'DELETED'];

            if ($formKey === '' || $layoutName === '') {
                bx_flash('Form and layout name are required.', 'error');
                bx_admin_redirect('forms');
            }

            if (!in_array($layoutType, $allowedTypes, true) || !in_array($layoutStatus, $allowedStatuses, true)) {
                bx_flash('Invalid layout type or status.', 'error');
                bx_admin_redirect('forms');
            }

            $form = bx_db()->GetRow("SELECT form_key FROM builder_form WHERE form_key = ? AND form_status <> 'DELETED'", [$formKey]);
            if (!$form) {
                bx_flash('Selected form was not found.', 'error');
                bx_admin_redirect('forms');
            }

            if ($versionKey !== '' && (int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_form_version WHERE version_key = ? AND form_key = ?', [$versionKey, $formKey]) === 0) {
                bx_flash('Selected version does not belong to the form.', 'error');
                bx_admin_redirect('forms');
            }

            $decodedSchema = $schemaRaw === '' ? [] : json_decode($schemaRaw, true);
            if (!is_array($decodedSchema)) {
                bx_flash('Layout schema must be valid JSON.', 'error');
                bx_admin_redirect('forms');
            }

            $schema = [
                'mode' => trim((string) ($_POST['layout_mode'] ?? 'create_edit')),
                'responsive' => [
                    'desktop_columns' => max(1, min(6, (int) ($_POST['desktop_columns'] ?? 2))),
                    'tablet_columns' => max(1, min(4, (int) ($_POST['tablet_columns'] ?? 2))),
                    'mobile_columns' => max(1, min(2, (int) ($_POST['mobile_columns'] ?? 1))),
                ],
                'components' => bx_post_array('layout_components'),
                'field_order' => bx_post_array('layout_field_order'),
                'custom_css' => '',
                'schema' => $decodedSchema,
                'restrictions' => [
                    'javascript' => 'blocked',
                    'remote_imports' => 'blocked',
                    'inline_events' => 'blocked',
                ],
            ];
            $schema = bx_safe_layout_schema($schema);
            if (empty($schema)) {
                bx_flash('Layout schema or custom CSS contains blocked unsafe content.', 'error');
                bx_admin_redirect('forms');
            }

            $schemaJson = json_encode($schema, JSON_UNESCAPED_SLASHES);
            $duplicateLayout = bx_db()->GetRow(
                'SELECT layout_key FROM builder_form_layout WHERE form_key = ? AND layout_name = ? AND layout_type = ? AND layout_key <> ? ORDER BY layout_status = ? DESC, updated_at DESC, x_id DESC LIMIT 1',
                [$formKey, $layoutName, $layoutType, $layoutKey ?: '__new__', 'ACTIVE']
            );
            if ($duplicateLayout && $layoutKey === '') {
                $layoutKey = (string) $duplicateLayout['layout_key'];
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_form_layout WHERE form_key = ? AND layout_name = ? AND layout_type = ? AND layout_key <> ?',
                [$formKey, $layoutName, $layoutType, $layoutKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Layout name already exists for this form and type.', 'error');
                bx_admin_redirect('forms');
            }

            if ($layoutKey !== '') {
                $existing = bx_db()->GetRow('SELECT * FROM builder_form_layout WHERE layout_key = ?', [$layoutKey]);
                if (!$existing) {
                    bx_flash('Layout was not found.', 'error');
                    bx_admin_redirect('forms');
                }

                bx_db()->Execute(
                    'UPDATE builder_form_layout SET form_key = ?, version_key = ?, layout_name = ?, layout_type = ?, layout_status = ?, layout_schema = ?, layout_sort_order = ? WHERE layout_key = ?',
                    [$formKey, $versionKey === '' ? null : $versionKey, $layoutName, $layoutType, $layoutStatus, $schemaJson, $layoutSortOrder, $layoutKey]
                );
                bx_audit('UPDATE', 'builder_form_layout', $layoutKey, ['form_key' => $formKey, 'layout_name' => $layoutName, 'layout_type' => $layoutType], 'Administrator updated form layout.');
                bx_flash('Form layout updated.', 'success');
            } else {
                $layoutKey = bx_uuid();
                bx_db()->Execute(
                    'INSERT INTO builder_form_layout (layout_key, form_key, version_key, layout_name, layout_type, layout_status, layout_schema, layout_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$layoutKey, $formKey, $versionKey === '' ? null : $versionKey, $layoutName, $layoutType, $layoutStatus, $schemaJson, $layoutSortOrder]
                );
                bx_audit('CREATE', 'builder_form_layout', $layoutKey, ['form_key' => $formKey, 'layout_name' => $layoutName, 'layout_type' => $layoutType], 'Administrator created form layout.');
                bx_flash('Form layout created.', 'success');
            }

            bx_admin_redirect_with_state('forms', [
                'formsSubTab' => 'layouts',
                'designerFormKey' => $formKey,
                'editingLayoutKey' => $layoutKey,
            ]);
        }

        if ($action === 'set_form_layout_status') {
            $layoutKey = trim((string) ($_POST['layout_key'] ?? ''));
            $layoutStatus = trim((string) ($_POST['layout_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
            if ($layoutKey === '' || !in_array($layoutStatus, $allowedStatuses, true)) {
                bx_flash('Invalid layout status request.', 'error');
                bx_admin_redirect('forms');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_form_layout WHERE layout_key = ?', [$layoutKey]);
            if (!$existing) {
                bx_flash('Layout was not found.', 'error');
                bx_admin_redirect('forms');
            }

            bx_db()->Execute('UPDATE builder_form_layout SET layout_status = ? WHERE layout_key = ?', [$layoutStatus, $layoutKey]);
            bx_audit($layoutStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_form_layout', $layoutKey, ['layout_name' => $existing['layout_name'], 'layout_status' => $layoutStatus], 'Administrator changed form layout status.');
            bx_flash('Form layout status updated.', 'success');
            bx_admin_redirect('forms');
        }

        if ($action === 'save_user_position') {
            if (!bx_admin_position_projection_ready()) {
                bx_flash('Position projection schema is not ready. Portal Master Sync support is required before this action.', 'error');
                bx_admin_redirect('positions');
            }
            $positionKey = trim((string) ($_POST['position_key'] ?? ''));
            $projectKey = bx_admin_project_key((string) ($_POST['project_key'] ?? ''));
            $positionCode = trim((string) ($_POST['position_code'] ?? ''));
            $positionName = trim((string) ($_POST['position_name'] ?? ''));
            $groupKey = trim((string) ($_POST['group_key'] ?? ''));
            $positionDescription = trim((string) ($_POST['position_description'] ?? ''));
            $positionStatus = trim((string) ($_POST['position_status'] ?? 'ACTIVE'));
            $returnTab = in_array((string) ($_POST['return_tab'] ?? 'positions'), ['groups', 'positions'], true) ? (string) $_POST['return_tab'] : 'positions';
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($projectKey === '' || $positionCode === '' || $positionName === '' || $groupKey === '') {
                bx_flash('Position code, name, and group are required.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            if (strlen($positionCode) > 80) {
                bx_flash('Position code must be 80 characters or less.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            if (strlen($positionName) > 160) {
                bx_flash('Position name must be 160 characters or less.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            if (!in_array($positionStatus, $allowedStatuses, true)) {
                bx_flash('Invalid position status.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM project_group WHERE project_key = ? AND group_key = ? AND group_status = 'ACTIVE'", [$projectKey, $groupKey]) === 0) {
                bx_flash('Selected position group must be active.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM project_position WHERE project_key = ? AND (position_code = ? OR position_name = ?) AND position_key <> ?',
                [$projectKey, $positionCode, $positionName, $positionKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Position code or name already exists.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }

            $existingPosition = $positionKey === '' ? [] : (bx_db()->GetRow(
                'SELECT * FROM project_position WHERE position_key = ? AND project_key = ? LIMIT 1',
                [$positionKey, $projectKey]
            ) ?: []);
            if ($positionKey !== '' && $existingPosition === []) {
                bx_flash('Position was not found.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }
            $firebasePosition = bx_admin_write_project_position_firebase_first([
                'position_key' => $positionKey,
                'project_key' => $projectKey,
                'group_key' => $groupKey,
                'position_code' => $positionCode,
                'position_name' => $positionName,
                'position_description' => $positionDescription,
                'position_status' => $positionStatus,
            ]);
            if (($firebasePosition['ok'] ?? false) !== true) {
                bx_flash('Position was not saved. Firebase did not acknowledge the write.', 'error');
                bx_admin_redirect_position_return($returnTab, $groupKey);
            }
            $positionKey = (string) ($firebasePosition['position_key'] ?? $positionKey);
            bx_mutation_lifecycle_flash(
                $existingPosition === [] ? 'Position created in Firebase; MySQL projection is pending.' : 'Position saved in Firebase; MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'Position fields and group boundary were accepted.'],
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => 'project_position write was acknowledged.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the PENDING position.'],
                ]
            );
            bx_admin_redirect_position_return($returnTab, $groupKey);

        }

        if ($action === 'set_user_position_status') {
            if (!bx_admin_position_projection_ready()) {
                bx_flash('Position projection schema is not ready. Portal Master Sync support is required before this action.', 'error');
                bx_admin_redirect('positions');
            }
            $positionKey = trim((string) ($_POST['position_key'] ?? ''));
            $positionStatus = trim((string) ($_POST['position_status'] ?? ''));
            $returnTab = in_array((string) ($_POST['return_tab'] ?? 'positions'), ['groups', 'positions'], true) ? (string) $_POST['return_tab'] : 'positions';
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($positionKey === '' || !in_array($positionStatus, $allowedStatuses, true)) {
                bx_flash('Invalid position status request.', 'error');
                bx_admin_redirect($returnTab);
            }

            $existing = bx_db()->GetRow('SELECT * FROM project_position WHERE position_key = ?', [$positionKey]);
            if (!$existing) {
                bx_flash('Position was not found.', 'error');
                bx_admin_redirect($returnTab);
            }
            $returnGroupKey = (string) ($existing['group_key'] ?? '');
            $currentPositionStatus = (string) ($existing['position_status'] ?? '');

            if ($currentPositionStatus === 'ACTIVE' && $positionStatus === 'ACTIVE') {
                bx_flash('Position is already active.', 'error');
                bx_admin_redirect_position_return($returnTab, $returnGroupKey);
            }

            if ($currentPositionStatus === 'INACTIVE' && $positionStatus === 'INACTIVE') {
                bx_flash('Position is already inactive.', 'error');
                bx_admin_redirect_position_return($returnTab, $returnGroupKey);
            }

            if ($currentPositionStatus === 'DELETED' && $positionStatus === 'DELETED') {
                bx_flash('Position is already deleted.', 'error');
                bx_admin_redirect_position_return($returnTab, $returnGroupKey);
            }

            $firebasePositionStatus = bx_admin_write_project_position_firebase_first([
                'position_key' => $positionKey,
                'project_key' => (string) ($existing['project_key'] ?? ''),
                'group_key' => (string) ($existing['group_key'] ?? ''),
                'position_code' => (string) ($existing['position_code'] ?? ''),
                'position_name' => (string) ($existing['position_name'] ?? ''),
                'position_description' => (string) ($existing['position_description'] ?? ''),
                'position_status' => $positionStatus,
            ]);
            if (($firebasePositionStatus['ok'] ?? false) !== true) {
                bx_flash('Position status was not changed. Firebase did not acknowledge the update.', 'error');
                bx_admin_redirect_position_return($returnTab, $returnGroupKey);
            }
            bx_mutation_lifecycle_flash(
                'Position status updated in Firebase. MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => 'project_position status was acknowledged.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the PENDING status change.'],
                ]
            );
            bx_admin_redirect_position_return($returnTab, $returnGroupKey);

        }

        if ($action === 'save_user') {
            $userKey = trim((string) ($_POST['user_key'] ?? ''));
            $projectKeys = bx_post_array('project_keys');
            $projectKey = bx_admin_project_key($projectKeys[0] ?? (string) ($_POST['project_key'] ?? ''));
            $userLogin = bx_admin_normalize_project_username((string) ($_POST['user_login'] ?? ''));
            $userName = trim((string) ($_POST['user_name'] ?? ''));
            $userChatName = substr(trim((string) ($_POST['user_chat_name'] ?? '')), 0, 160);
            $userMobileNumber = bx_admin_normalize_mobile_number((string) ($_POST['user_mobile_number'] ?? ''));
            $userEmail = trim((string) ($_POST['user_email'] ?? ''));
            $positionKey = trim((string) ($_POST['position_key'] ?? ''));
            $userStatus = trim((string) ($_POST['user_status'] ?? 'ACTIVE'));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
            $roleKeys = bx_post_array('role_keys');
            $groupKeys = bx_post_array('group_keys');
            $groupKey = bx_admin_unique_keys($groupKeys)[0] ?? '';
            $branchKeys = bx_post_array('branch_keys');
            $allowedStatuses = ['DRAFT', 'ACTIVE', 'INACTIVE', 'LOCKED', 'DELETED'];
            $userAvatarFieldsSubmitted = array_key_exists('user_avatar_url', $_POST);
            $userAvatarUrl = trim((string) ($_POST['user_avatar_url'] ?? ''));
            $userAvatarOriginalName = substr(trim((string) ($_POST['user_avatar_original_name'] ?? '')), 0, 255);
            $userAvatarMimeType = substr(trim((string) ($_POST['user_avatar_mime_type'] ?? '')), 0, 120);
            $userAvatarByteSize = max(0, (int) ($_POST['user_avatar_byte_size'] ?? 0));
            $userAvatarSha256 = trim((string) ($_POST['user_avatar_sha256'] ?? ''));

            if ($projectKey === '' || $userLogin === '' || $userName === '' || $userMobileNumber === '') {
                bx_flash('Username, full name, and mobile number are required.', 'error');
                bx_admin_redirect('users');
            }

            $userLoginError = bx_admin_project_username_validation_error($userLogin);
            if ($userLoginError !== '') {
                bx_flash($userLoginError, 'error');
                bx_admin_redirect('users');
            }

            if ($userEmail !== '' && !filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                bx_flash('Valid email is required.', 'error');
                bx_admin_redirect('users');
            }

            if (!preg_match('/^\+[1-9][0-9]{7,14}$/', $userMobileNumber)) {
                bx_flash('Mobile number must start with a country code, for example +639171234567.', 'error');
                bx_admin_redirect('users');
            }

            if (!in_array($userStatus, $allowedStatuses, true)) {
                bx_flash('Invalid user status.', 'error');
                bx_admin_redirect('users');
            }

            if ($userKey === '' && $password === '') {
                $password = bx_admin_project_user_default_password();
                $passwordConfirm = $password;
            }

            if ($userKey === '' && strlen($password) < 8) {
                bx_flash('New users require a password with at least 8 characters.', 'error');
                bx_admin_redirect('users');
            }

            if ($password !== '' && strlen($password) < 8) {
                bx_flash('Password must use at least 8 characters.', 'error');
                bx_admin_redirect('users');
            }

            if ($password !== '' && $password !== $passwordConfirm) {
                bx_flash('Password confirmation does not match.', 'error');
                bx_admin_redirect('users');
            }

            if ($userAvatarFieldsSubmitted && $userAvatarUrl !== '' && !filter_var($userAvatarUrl, FILTER_VALIDATE_URL)) {
                bx_flash('User avatar source must be a full uploaded image URL.', 'error');
                bx_admin_redirect('users');
            }
            if ($userAvatarFieldsSubmitted && $userAvatarUrl !== '') {
                $userAvatarUrl = bx_project_media_uploaded_url($projectKey, $userAvatarUrl);
            }

            if ($userAvatarFieldsSubmitted && $userAvatarSha256 !== '' && !preg_match('/^[a-f0-9]{64}$/i', $userAvatarSha256)) {
                bx_flash('User avatar checksum is invalid.', 'error');
                bx_admin_redirect('users');
            }

            if ($positionKey !== '' && (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_user_position WHERE project_key = ? AND position_key = ? AND position_status <> 'DELETED'", [$projectKey, $positionKey]) === 0) {
                bx_flash('Selected position is invalid or deleted.', 'error');
                bx_admin_redirect('users');
            }

            if ($positionKey !== '') {
                $positionGroupKey = (string) (bx_db()->GetOne('SELECT group_key FROM project_user_position WHERE project_key = ? AND position_key = ? AND position_status <> \'DELETED\' LIMIT 1', [$projectKey, $positionKey]) ?: '');
                if ($positionGroupKey !== '' && $groupKey !== '' && $positionGroupKey !== $groupKey) {
                    bx_flash('Selected user position must belong to the selected project group.', 'error');
                    bx_admin_redirect('users');
                }
                if ($positionGroupKey !== '' && $groupKey === '') {
                    $groupKey = $positionGroupKey;
                    $groupKeys = [$groupKey];
                }
            }

            if ($groupKey !== '' && (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_user_group WHERE project_key = ? AND group_key = ? AND group_status = 'ACTIVE'", [$projectKey, $groupKey]) === 0) {
                bx_flash('One or more selected assignments must use an active group.', 'error');
                bx_admin_redirect('users');
            }

            unset($roleKeys, $branchKeys);
            $projectKeys = [$projectKey];

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM project_user WHERE project_key = ? AND (user_login = ? OR user_mobile_number = ?) AND user_key <> ?',
                [$projectKey, $userLogin, $userMobileNumber, $userKey ?: '__new__']
            );

            if ($duplicate > 0) {
                bx_flash('Username or mobile number already exists.', 'error');
                bx_admin_redirect('users');
            }

            // Firebase/Auth is the write authority.  Do not fall through to
            // the legacy MySQL-first transaction for the user profile form.
            $existingUser = $userKey === '' ? [] : (bx_db()->GetRow(
                'SELECT * FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1',
                [$userKey, $projectKey]
            ) ?: []);
            if ($userKey !== '' && $existingUser === []) {
                bx_flash('User was not found.', 'error');
                bx_admin_redirect('users');
            }
            $userAuthUsername = trim((string) ($existingUser['user_auth_username'] ?? ''));
            if ($userAuthUsername === '') {
                $userAuthUsername = bx_project_user_auth_username();
            }
            $userAuthEmail = trim((string) ($existingUser['user_auth_email'] ?? ''));
            if ($userAuthEmail === '') {
                $userAuthEmail = bx_admin_project_user_auth_email($userAuthUsername);
            }
            $firebaseProfile = [
                'user_key' => $userKey,
                'project_key' => $projectKey,
                'user_login' => $userLogin,
                'user_auth_username' => $userAuthUsername,
                'user_auth_email' => $userAuthEmail,
                'user_name' => $userName,
                'user_chat_name' => $userChatName,
                'user_mobile_number' => $userMobileNumber,
                'user_avatar_path' => $userAvatarFieldsSubmitted ? $userAvatarUrl : ($existingUser['user_avatar_path'] ?? ''),
                'user_avatar_original_name' => $userAvatarFieldsSubmitted ? $userAvatarOriginalName : ($existingUser['user_avatar_original_name'] ?? ''),
                'user_avatar_mime_type' => $userAvatarFieldsSubmitted ? $userAvatarMimeType : ($existingUser['user_avatar_mime_type'] ?? ''),
                'user_avatar_byte_size' => $userAvatarFieldsSubmitted ? $userAvatarByteSize : ($existingUser['user_avatar_byte_size'] ?? 0),
                'user_avatar_sha256' => $userAvatarFieldsSubmitted ? strtolower($userAvatarSha256) : ($existingUser['user_avatar_sha256'] ?? ''),
                'user_avatar_uploaded_at' => $existingUser['user_avatar_uploaded_at'] ?? '',
                'user_status' => $userStatus,
                'user_password_change_required' => (int) ($existingUser['user_password_change_required'] ?? 0) === 1,
                'user_last_login_at' => $existingUser['user_last_login_at'] ?? '',
                'user_last_login_ip_address' => $existingUser['user_last_login_ip_address'] ?? '',
                'user_last_login_device' => $existingUser['user_last_login_device'] ?? '',
                'user_last_logout_at' => $existingUser['user_last_logout_at'] ?? '',
                'user_last_logout_ip_address' => $existingUser['user_last_logout_ip_address'] ?? '',
                'user_last_logout_device' => $existingUser['user_last_logout_device'] ?? '',
                'user_password_reset_at' => $existingUser['user_password_reset_at'] ?? '',
                // Status transition timestamps are generated by Firestore's
                // server clock in the Firebase writer. Do not send PHP dates.
                'user_activated_at' => $existingUser['user_activated_at'] ?? '',
                'user_deactivated_at' => $existingUser['user_deactivated_at'] ?? '',
                'user_locked_at' => $existingUser['user_locked_at'] ?? '',
                'user_deleted_at' => $existingUser['user_deleted_at'] ?? '',
            ];
            $firebaseWrite = bx_admin_write_project_user_firebase_first($firebaseProfile, $password);
            if (($firebaseWrite['ok'] ?? false) !== true) {
                $firebaseErrorCode = preg_match('/^[a-z0-9_]+$/', (string) ($firebaseWrite['code'] ?? '')) === 1 ? (string) $firebaseWrite['code'] : 'firebase_project_user_write_failed';
                bx_flash('User was not saved. Firebase error: ' . $firebaseErrorCode . '.', 'error');
                bx_admin_redirect('users');
            }
            $userKey = (string) ($firebaseWrite['user_key'] ?? $userKey);
            bx_project_user_activity_history(
                $userKey,
                $userLogin,
                $existingUser === [] ? 'CREATE' : 'EDIT',
                'SUCCESS',
                null,
                (string) ($existingUser['user_status'] ?? null),
                $userStatus,
                (string) ($currentUser['user_key'] ?? null),
                $projectKey
            );
            bx_mutation_lifecycle_flash(
                $wasCreatingUser = $existingUser === [] ? 'User created in Firebase; MySQL projection is pending.' : 'User saved in Firebase; MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Validation', 'status' => 'complete', 'detail' => 'User profile fields were accepted.'],
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => 'Firebase Auth and project_user profile were acknowledged.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the PENDING profile to MySQL.'],
                ]
            );
            if (bx_admin_wants_json()) {
                bx_admin_json_response([
                    'ok' => true,
                    'message' => 'User saved in Firebase; MySQL projection is pending.',
                    'user_key' => $userKey,
                    'firebase_ok' => true,
                    'mysql_sync_status' => 'PENDING',
                ]);
            }
            bx_admin_redirect('users');

            $wasCreatingUser = $userKey === '';
            $existingUserForFirebase = $userKey === ''
                ? []
                : (bx_db()->GetRow('SELECT * FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1', [$userKey, $projectKey]) ?: []);
            if ($userKey !== '' && $existingUserForFirebase === []) {
                throw new RuntimeException('User was not found.');
            }
            $userAuthUsername = trim((string) ($existingUserForFirebase['user_auth_username'] ?? ''));
            if ($userAuthUsername === '') {
                $userAuthUsername = bx_project_user_auth_username();
            }
            $userAuthEmail = trim((string) ($existingUserForFirebase['user_auth_email'] ?? ''));
            if ($userAuthEmail === '') {
                $userAuthEmail = bx_admin_project_user_auth_email($userAuthUsername);
            }
            $firebaseRow = [
                'user_key' => $userKey,
                'project_key' => $projectKey,
                'user_login' => $userLogin,
                'user_auth_username' => $userAuthUsername,
                'user_auth_email' => $userAuthEmail,
                'user_name' => $userName,
                'user_chat_name' => $userChatName,
                'user_mobile_number' => $userMobileNumber,
                'user_avatar_path' => $userAvatarUrl,
                'user_avatar_original_name' => $userAvatarOriginalName,
                'user_avatar_mime_type' => $userAvatarMimeType,
                'user_avatar_byte_size' => $userAvatarByteSize,
                'user_avatar_sha256' => $userAvatarSha256,
                'user_avatar_uploaded_at' => $existingUserForFirebase['user_avatar_uploaded_at'] ?? '',
                'user_status' => $userStatus,
                'user_last_login_at' => $existingUserForFirebase['user_last_login_at'] ?? '',
                'user_password_change_required' => $wasCreatingUser || empty($existingUserForFirebase['user_password_changed_at']),
            ];
            try {
                $firebaseWrite = bx_admin_write_project_user_to_firebase($firebaseRow, $wasCreatingUser || $password !== '' ? ($password !== '' ? $password : bx_admin_project_user_default_password()) : '');
                if (($firebaseWrite['ok'] ?? false) !== true) {
                    throw new RuntimeException((string) ($firebaseWrite['message'] ?? 'Firebase project user write failed.'));
                }
                $userKey = trim((string) ($firebaseWrite['user_key'] ?? ''));
                if ($userKey === '') {
                    throw new RuntimeException('Firebase project user document id was not returned.');
                }
                $firebaseRow['user_key'] = $userKey;
                $authStep = bx_admin_project_user_auth_step([
                    'ok' => true,
                    'action' => (string) ($firebaseWrite['auth_action'] ?? 'updated'),
                ]);
                $firebaseStep = [
                    'label' => 'Firebase',
                    'status' => 'complete',
                    'detail' => 'Profile write acknowledged with mysql_sync_status=PENDING; Master Sync will project it to MySQL.',
                ];
                bx_admin_access_transaction(function () use (&$userKey, $wasCreatingUser, $projectKey, $groupKey, $userLogin, $userName, $userChatName, $userMobileNumber, $userEmail, $positionKey, $userStatus, $password, $groupKeys, $projectKeys, $currentUser, $userAvatarFieldsSubmitted, $userAvatarUrl, $userAvatarOriginalName, $userAvatarMimeType, $userAvatarByteSize, $userAvatarSha256): void {
                    $storedPositionKey = $positionKey === '' ? null : $positionKey;
                    $storedGroupKey = $groupKey === '' ? null : $groupKey;
                    $storedChatName = $userChatName === '' ? null : $userChatName;
                    $storedEmail = $userEmail === '' ? null : $userEmail;
                    $storedAvatarUrl = $userAvatarUrl === '' ? null : $userAvatarUrl;
                    $storedAvatarOriginalName = $userAvatarOriginalName === '' ? null : $userAvatarOriginalName;
                    $storedAvatarMimeType = $userAvatarMimeType === '' ? null : $userAvatarMimeType;
                    $storedAvatarSha256 = $userAvatarSha256 === '' ? null : strtolower($userAvatarSha256);
                    if (!$wasCreatingUser) {
                        $existing = bx_db()->GetRow('SELECT * FROM project_user WHERE user_key = ? AND project_key = ?', [$userKey, $projectKey]);
                        if (!$existing) {
                            throw new RuntimeException('User was not found.');
                        }
                        $userAuthUsername = trim((string) ($existing['user_auth_username'] ?? ''));
                        if ($userAuthUsername === '') {
                            $userAuthUsername = bx_project_user_auth_username();
                        }
                        $userAuthEmail = trim((string) ($existing['user_auth_email'] ?? ''));
                        if ($userAuthEmail === '') {
                            $userAuthEmail = bx_admin_project_user_auth_email($userAuthUsername);
                        }

                        if ($password !== '') {
                            $saved = bx_db()->Execute(
                                'UPDATE project_user SET user_login = ?, user_auth_username = ?, user_auth_email = ?, user_name = ?, user_chat_name = ?, user_email = ?, user_mobile_number = ?, group_key = ?, position_key = ?, user_status = ?, user_password_hash = ?, user_password_changed_at = NULL, user_updated_by_key = ? WHERE user_key = ? AND project_key = ?',
                                [$userLogin, $userAuthUsername, $userAuthEmail, $userName, $storedChatName, $storedEmail, $userMobileNumber, $storedGroupKey, $storedPositionKey, $userStatus, bx_password_hash($password), $currentUser['user_key'], $userKey, $projectKey]
                            );
                        } else {
                            $saved = bx_db()->Execute(
                                'UPDATE project_user SET user_login = ?, user_auth_username = ?, user_auth_email = ?, user_name = ?, user_chat_name = ?, user_email = ?, user_mobile_number = ?, group_key = ?, position_key = ?, user_status = ?, user_updated_by_key = ? WHERE user_key = ? AND project_key = ?',
                                [$userLogin, $userAuthUsername, $userAuthEmail, $userName, $storedChatName, $storedEmail, $userMobileNumber, $storedGroupKey, $storedPositionKey, $userStatus, $currentUser['user_key'], $userKey, $projectKey]
                            );
                        }

                        if ($saved === false) {
                            throw new RuntimeException('User update failed.');
                        }

                        if ($userAvatarFieldsSubmitted) {
                            $avatarSaved = bx_db()->Execute(
                                'UPDATE project_user SET user_avatar_path = ?, user_avatar_original_name = ?, user_avatar_mime_type = ?, user_avatar_byte_size = ?, user_avatar_sha256 = ?, user_avatar_uploaded_at = CASE WHEN ? IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END WHERE user_key = ? AND project_key = ?',
                                [$storedAvatarUrl, $storedAvatarOriginalName, $storedAvatarMimeType, $userAvatarByteSize, $storedAvatarSha256, $storedAvatarUrl, $userKey, $projectKey]
                            );
                            if ($avatarSaved === false) {
                                throw new RuntimeException('User avatar update failed.');
                            }
                            $readBackAvatarPath = (string) bx_db()->GetOne('SELECT COALESCE(user_avatar_path, \'\') FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1', [$userKey, $projectKey]);
                            if ($readBackAvatarPath !== $userAvatarUrl) {
                                throw new RuntimeException('User avatar read-back verification failed.');
                            }
                            bx_audit('UPLOAD', 'project_user', $userKey, [
                                'project_key' => $projectKey,
                                'user_login' => $userLogin,
                                'user_avatar_path' => $userAvatarUrl,
                                'user_avatar_mime_type' => $userAvatarMimeType,
                                'user_avatar_byte_size' => $userAvatarByteSize,
                            ], 'Administrator saved user avatar URL.');
                        }

                        bx_audit('UPDATE', 'project_user', $userKey, [
                            'project_key' => $projectKey,
                            'user_login' => $userLogin,
                            'user_auth_username' => $userAuthUsername,
                            'user_auth_email' => $userAuthEmail,
                            'user_chat_name' => $userChatName,
                            'user_mobile_number' => $userMobileNumber,
                            'group_key' => $groupKey,
                            'position_key' => $positionKey,
                            'user_status' => $userStatus,
                        ], 'Administrator updated user.');
                    } else {
                        if (!preg_match('/^[A-Za-z0-9]{20}$/', $userKey)) {
                            throw new RuntimeException('Firebase user document id is invalid.');
                        }
                        $userAuthUsername = bx_project_user_auth_username();
                        $userAuthEmail = bx_admin_project_user_auth_email($userAuthUsername);
                        $saved = bx_db()->Execute(
                            'INSERT INTO project_user (user_key, project_key, group_key, user_login, user_auth_username, user_auth_email, user_password_hash, user_name, user_chat_name, user_email, user_mobile_number, user_avatar_path, user_avatar_original_name, user_avatar_mime_type, user_avatar_byte_size, user_avatar_sha256, user_avatar_uploaded_at, position_key, user_status, user_created_by_key, user_updated_by_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? IS NULL THEN NULL ELSE CURRENT_TIMESTAMP END, ?, ?, ?, ?)',
                            [$userKey, $projectKey, $storedGroupKey, $userLogin, $userAuthUsername, $userAuthEmail, bx_password_hash($password), $userName, $storedChatName, $storedEmail, $userMobileNumber, $storedAvatarUrl, $storedAvatarOriginalName, $storedAvatarMimeType, $userAvatarByteSize, $storedAvatarSha256, $storedAvatarUrl, $storedPositionKey, $userStatus, $currentUser['user_key'], $currentUser['user_key']]
                        );
                        if ($saved === false) {
                            throw new RuntimeException('User creation failed.');
                        }
                        if ($userAvatarFieldsSubmitted) {
                            $readBackAvatarPath = (string) bx_db()->GetOne('SELECT COALESCE(user_avatar_path, \'\') FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1', [$userKey, $projectKey]);
                            if ($readBackAvatarPath !== $userAvatarUrl) {
                                throw new RuntimeException('User avatar read-back verification failed.');
                            }
                        }
                        bx_audit('CREATE', 'project_user', $userKey, [
                            'project_key' => $projectKey,
                            'user_login' => $userLogin,
                            'user_auth_username' => $userAuthUsername,
                            'user_auth_email' => $userAuthEmail,
                            'user_chat_name' => $userChatName,
                            'user_mobile_number' => $userMobileNumber,
                            'group_key' => $groupKey,
                            'position_key' => $positionKey,
                            'user_status' => $userStatus,
                        ], 'Administrator created user.');
                    }

                    bx_admin_assert_user_readback($userKey, $userLogin, $userChatName, $userMobileNumber, $positionKey, $userStatus, [], $groupKeys, [], $projectKeys);
                    $readBackAuthUsername = (string) bx_db()->GetOne('SELECT COALESCE(user_auth_username, \'\') FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1', [$userKey, $projectKey]);
                    $readBackAuthEmail = (string) bx_db()->GetOne('SELECT COALESCE(user_auth_email, \'\') FROM project_user WHERE user_key = ? AND project_key = ? LIMIT 1', [$userKey, $projectKey]);
                    if ($readBackAuthUsername !== $userAuthUsername || $readBackAuthEmail !== $userAuthEmail) {
                        throw new RuntimeException('Project user Firebase Auth identity read-back verification failed.');
                    }
                });

                $firebaseOk = $authStep['status'] === 'complete' && $firebaseStep['status'] === 'complete';
                bx_mutation_lifecycle_flash(
                    $firebaseOk
                        ? ($wasCreatingUser ? 'User created; Firebase write acknowledged.' : 'User saved; Firebase write acknowledged.')
                        : ($wasCreatingUser ? 'User created; Firebase write needs attention.' : 'User saved; Firebase write needs attention.'),
                    $firebaseOk ? 'success' : 'error',
                    [
                        ['label' => 'Validation', 'status' => 'complete', 'detail' => 'User form fields and assignments were accepted.'],
                        ['label' => 'Database', 'status' => 'complete', 'detail' => $wasCreatingUser ? 'User was created and verified by read-back.' : 'User was updated and verified by read-back.'],
                        $authStep,
                        $firebaseStep,
                    ],
                    $firebaseOk ? null : (string) ($firebaseWrite['message'] ?? 'Firebase write did not complete.')
                );
                if (bx_admin_wants_json()) {
                    bx_admin_json_response([
                        'ok' => true,
                        'message' => $firebaseOk
                            ? ($wasCreatingUser ? 'User created; Firebase write acknowledged.' : 'User saved; Firebase write acknowledged.')
                            : ($wasCreatingUser ? 'User created; Firebase write needs attention.' : 'User saved; Firebase write needs attention.'),
                        'user_key' => $userKey,
                        'firebase_ok' => $firebaseOk,
                    ]);
                }
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
                if (bx_admin_wants_json()) {
                    $flash = bx_take_flash();
                    bx_admin_json_response([
                        'ok' => false,
                        'message' => (string) ($flash['message'] ?? $exception->getMessage()),
                    ], 422);
                }
            }

            bx_admin_redirect('users');
        }

        if ($action === 'set_user_status') {
            $targetUserKey = trim((string) ($_POST['user_key'] ?? ''));
            $userStatus = trim((string) ($_POST['user_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'LOCKED', 'DELETED'];

            if ($targetUserKey === '' || !in_array($userStatus, $allowedStatuses, true)) {
                bx_flash('Invalid user status request.', 'error');
                bx_admin_redirect('users');
            }

            $existing = bx_db()->GetRow('SELECT * FROM project_user WHERE user_key = ?', [$targetUserKey]);
            if (!$existing) {
                bx_flash('User was not found.', 'error');
                bx_admin_redirect('users');
            }

            // Firebase Auth and Firestore are authoritative for status. The
            // resulting project_user document remains PENDING for projection.
            $firebaseStatusProfile = [
                'user_key' => $targetUserKey,
                'project_key' => (string) ($existing['project_key'] ?? ''),
                'user_login' => (string) ($existing['user_login'] ?? ''),
                'user_auth_username' => (string) ($existing['user_auth_username'] ?? ''),
                'user_auth_email' => (string) ($existing['user_auth_email'] ?? ''),
                'user_name' => (string) ($existing['user_name'] ?? ''),
                'user_chat_name' => (string) ($existing['user_chat_name'] ?? ''),
                'user_mobile_number' => (string) ($existing['user_mobile_number'] ?? ''),
                'user_avatar_path' => (string) ($existing['user_avatar_path'] ?? ''),
                'user_avatar_original_name' => (string) ($existing['user_avatar_original_name'] ?? ''),
                'user_avatar_mime_type' => (string) ($existing['user_avatar_mime_type'] ?? ''),
                'user_avatar_byte_size' => (int) ($existing['user_avatar_byte_size'] ?? 0),
                'user_avatar_sha256' => (string) ($existing['user_avatar_sha256'] ?? ''),
                'user_avatar_uploaded_at' => (string) ($existing['user_avatar_uploaded_at'] ?? ''),
                'user_status' => $userStatus,
                'user_password_change_required' => empty($existing['user_password_changed_at']),
                'user_last_login_at' => (string) ($existing['user_last_login_at'] ?? ''),
                'user_last_login_ip_address' => (string) ($existing['user_last_login_ip_address'] ?? ''),
                'user_last_login_device' => (string) ($existing['user_last_login_device'] ?? ''),
                'user_last_logout_at' => (string) ($existing['user_last_logout_at'] ?? ''),
                'user_last_logout_ip_address' => (string) ($existing['user_last_logout_ip_address'] ?? ''),
                'user_last_logout_device' => (string) ($existing['user_last_logout_device'] ?? ''),
                'user_password_reset_at' => (string) ($existing['user_password_reset_at'] ?? ''),
                'user_activated_at' => $userStatus === 'ACTIVE' ? gmdate('c') : (string) ($existing['user_activated_at'] ?? ''),
                'user_deactivated_at' => $userStatus === 'INACTIVE' ? gmdate('c') : (string) ($existing['user_deactivated_at'] ?? ''),
                'user_locked_at' => $userStatus === 'LOCKED' ? gmdate('c') : (string) ($existing['user_locked_at'] ?? ''),
            ];
            $firebaseStatus = bx_admin_write_project_user_firebase_first($firebaseStatusProfile);
            if (($firebaseStatus['ok'] ?? false) !== true) {
                $firebaseErrorCode = preg_match('/^[a-z0-9_]+$/', (string) ($firebaseStatus['code'] ?? '')) === 1 ? (string) $firebaseStatus['code'] : 'firebase_project_user_write_failed';
                bx_flash('User status was not changed. Firebase error: ' . $firebaseErrorCode . '.', 'error');
                bx_admin_redirect('users');
            }
            $statusAction = $userStatus === 'ACTIVE' ? 'ACTIVATE' : ($userStatus === 'INACTIVE' ? 'DEACTIVATE' : ($userStatus === 'LOCKED' ? 'LOCK' : 'DELETE'));
            bx_project_user_activity_history(
                $targetUserKey,
                (string) ($existing['user_login'] ?? ''),
                $statusAction,
                'SUCCESS',
                null,
                (string) ($existing['user_status'] ?? null),
                $userStatus,
                (string) ($currentUser['user_key'] ?? null),
                (string) ($existing['project_key'] ?? '')
            );
            bx_mutation_lifecycle_flash(
                'User status updated in Firebase. MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Firebase Auth', 'status' => 'complete', 'detail' => 'Sign-in access status was updated.'],
                    ['label' => 'User profile', 'status' => 'complete', 'detail' => 'The project_user profile was marked PENDING.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the status change.'],
                ]
            );
            bx_admin_redirect('users');

            try {
                bx_admin_access_transaction(function () use ($targetUserKey, $userStatus, $currentUser, $existing): void {
                    if ($userStatus === 'DELETED') {
                        $saved = bx_db()->Execute(
                            'UPDATE project_user SET user_status = ?, user_deleted_at = CURRENT_TIMESTAMP, user_deleted_by_key = ?, user_updated_by_key = ? WHERE user_key = ?',
                            [$userStatus, $currentUser['user_key'], $currentUser['user_key'], $targetUserKey]
                        );
                    } else {
                        $saved = bx_db()->Execute(
                            'UPDATE project_user SET user_status = ?, user_failed_login_count = 0, user_updated_by_key = ? WHERE user_key = ?',
                            [$userStatus, $currentUser['user_key'], $targetUserKey]
                        );
                    }
                    if ($saved === false) {
                        throw new RuntimeException('User status update failed.');
                    }
                    $readBackStatus = (string) bx_db()->GetOne('SELECT user_status FROM project_user WHERE user_key = ? LIMIT 1', [$targetUserKey]);
                    if ($readBackStatus !== $userStatus) {
                        throw new RuntimeException('User status read-back verification failed.');
                    }
                    bx_audit($userStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'project_user', $targetUserKey, [
                        'project_key' => $existing['project_key'] ?? '',
                        'user_login' => $existing['user_login'],
                        'user_status' => $userStatus,
                    ], 'Administrator changed user status.');
                });
                bx_flash('User status updated and verified.', 'success');
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }
            bx_admin_redirect('users');
        }

        if ($action === 'reset_user_password') {
            $targetUserKey = trim((string) ($_POST['user_key'] ?? ''));
            $password = bx_admin_project_user_default_password();

            if ($targetUserKey === '') {
                bx_flash('Password reset requires a user.', 'error');
                bx_admin_redirect('users');
            }

            $existing = bx_db()->GetRow('SELECT * FROM project_user WHERE user_key = ?', [$targetUserKey]);
            if (!$existing) {
                bx_flash('User was not found.', 'error');
                bx_admin_redirect('users');
            }

            // Reset the Firebase Auth credential first. The profile document
            // is marked PENDING for Master Sync; MySQL remains projection-only.
            $firebaseResetProfile = [
                'user_key' => $targetUserKey,
                'project_key' => (string) ($existing['project_key'] ?? ''),
                'user_login' => (string) ($existing['user_login'] ?? ''),
                'user_auth_username' => (string) ($existing['user_auth_username'] ?? ''),
                'user_auth_email' => (string) ($existing['user_auth_email'] ?? ''),
                'user_name' => (string) ($existing['user_name'] ?? ''),
                'user_chat_name' => (string) ($existing['user_chat_name'] ?? ''),
                'user_mobile_number' => (string) ($existing['user_mobile_number'] ?? ''),
                'user_avatar_path' => (string) ($existing['user_avatar_path'] ?? ''),
                'user_avatar_original_name' => (string) ($existing['user_avatar_original_name'] ?? ''),
                'user_avatar_mime_type' => (string) ($existing['user_avatar_mime_type'] ?? ''),
                'user_avatar_byte_size' => (int) ($existing['user_avatar_byte_size'] ?? 0),
                'user_avatar_sha256' => (string) ($existing['user_avatar_sha256'] ?? ''),
                'user_avatar_uploaded_at' => (string) ($existing['user_avatar_uploaded_at'] ?? ''),
                'user_status' => (string) ($existing['user_status'] ?? 'ACTIVE'),
                'user_password_change_required' => true,
                'user_last_login_at' => (string) ($existing['user_last_login_at'] ?? ''),
                'user_last_login_ip_address' => (string) ($existing['user_last_login_ip_address'] ?? ''),
                'user_last_login_device' => (string) ($existing['user_last_login_device'] ?? ''),
                'user_last_logout_at' => (string) ($existing['user_last_logout_at'] ?? ''),
                'user_last_logout_ip_address' => (string) ($existing['user_last_logout_ip_address'] ?? ''),
                'user_last_logout_device' => (string) ($existing['user_last_logout_device'] ?? ''),
                'user_password_reset_at' => gmdate('c'),
                'user_activated_at' => (string) ($existing['user_activated_at'] ?? ''),
                'user_deactivated_at' => (string) ($existing['user_deactivated_at'] ?? ''),
                'user_locked_at' => (string) ($existing['user_locked_at'] ?? ''),
            ];
            $firebaseReset = bx_admin_write_project_user_firebase_first($firebaseResetProfile, $password);
            if (($firebaseReset['ok'] ?? false) !== true) {
                $firebaseErrorCode = preg_match('/^[a-z0-9_]+$/', (string) ($firebaseReset['code'] ?? '')) === 1 ? (string) $firebaseReset['code'] : 'firebase_project_user_write_failed';
                bx_flash('Password reset failed. Firebase error: ' . $firebaseErrorCode . '.', 'error');
                bx_admin_redirect('users');
            }
            bx_project_user_activity_history(
                $targetUserKey,
                (string) ($existing['user_login'] ?? ''),
                'RESET_PASSWORD',
                'SUCCESS',
                'Password reset through Firebase Auth.',
                (string) ($existing['user_status'] ?? null),
                (string) ($existing['user_status'] ?? null),
                (string) ($currentUser['user_key'] ?? null),
                (string) ($existing['project_key'] ?? '')
            );
            bx_mutation_lifecycle_flash(
                'Password reset in Firebase Auth. The user must change it at next sign-in.',
                'success',
                [
                    ['label' => 'Firebase Auth', 'status' => 'complete', 'detail' => 'Credential reset was acknowledged.'],
                    ['label' => 'User profile', 'status' => 'complete', 'detail' => 'user_password_change_required was set and the profile was marked PENDING.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the pending profile update.'],
                ]
            );
            bx_admin_redirect('users');

            try {
                bx_admin_access_transaction(function () use ($password, $currentUser, $targetUserKey, $existing): void {
                    $saved = bx_db()->Execute(
                        'UPDATE project_user SET user_password_hash = ?, user_password_changed_at = NULL, user_failed_login_count = 0, user_updated_by_key = ? WHERE user_key = ?',
                        [bx_password_hash($password), $currentUser['user_key'], $targetUserKey]
                    );
                    if ($saved === false) {
                        throw new RuntimeException('User password reset failed.');
                    }
                    $readBack = bx_db()->GetRow('SELECT user_failed_login_count, user_password_changed_at FROM project_user WHERE user_key = ? LIMIT 1', [$targetUserKey]);
                    if (!is_array($readBack) || (int) $readBack['user_failed_login_count'] !== 0 || $readBack['user_password_changed_at'] !== null) {
                        throw new RuntimeException('Password reset read-back verification failed.');
                    }
                    bx_audit('PASSWORD_RESET', 'project_user', $targetUserKey, [
                        'project_key' => $existing['project_key'] ?? '',
                        'user_login' => $existing['user_login'],
                    ], 'Administrator reset user password.');
                });
                $projectKey = (string) ($existing['project_key'] ?? '');
                $userFirebaseRows = bx_project_user_firebase_rows($projectKey, $targetUserKey, 1);
                $userFirebaseRow = $userFirebaseRows[0] ?? [];
                $authSync = bx_sync_project_user_auth_to_firebase($userFirebaseRow, $password, $password);
                if (($authSync['ok'] ?? false) === true) {
                    bx_flash('User password reset to the automatic default and verified. Require the user to change it at next sign-in.', 'success');
                } else {
                    bx_flash('User password reset locally; Firebase Auth sync needs attention: ' . (string) ($authSync['message'] ?? 'Firebase Auth sync did not complete.'), 'error');
                }
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }
            bx_admin_redirect('users');
        }

        if ($action === 'save_group') {
            if (!bx_admin_group_projection_ready()) {
                bx_flash('Group projection schema is not ready. Portal Master Sync support is required before this action.', 'error');
                bx_admin_redirect('groups');
            }
            $groupKey = trim((string) ($_POST['group_key'] ?? ''));
            $groupSaveMode = trim((string) ($_POST['group_save_mode'] ?? ($groupKey === '' ? 'create' : 'edit')));
            $projectKey = bx_admin_project_key((string) ($_POST['project_key'] ?? ''));
            $groupName = trim((string) ($_POST['group_name'] ?? ''));
            $groupDescription = trim((string) ($_POST['group_description'] ?? ''));
            $groupStatus = trim((string) ($_POST['group_status'] ?? 'ACTIVE'));
            $memberUserKeys = bx_post_array('member_user_keys');
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];
            $groupImageFieldsSubmitted = array_key_exists('group_image_url', $_POST);
            $groupImageUrl = trim((string) ($_POST['group_image_url'] ?? ''));
            $groupImageOriginalName = substr(trim((string) ($_POST['group_image_original_name'] ?? '')), 0, 255);
            $groupImageMimeType = substr(trim((string) ($_POST['group_image_mime_type'] ?? '')), 0, 120);
            $groupImageByteSize = max(0, (int) ($_POST['group_image_byte_size'] ?? 0));
            $groupImageSha256 = trim((string) ($_POST['group_image_sha256'] ?? ''));

            if ($projectKey === '' || $groupName === '') {
                bx_flash('Group name is required.', 'error');
                bx_admin_redirect('groups');
            }

            if ($groupSaveMode === 'edit' && $groupKey === '') {
                bx_flash('Group edit could not be saved because the existing group key was missing. Reopen the group and try again.', 'error');
                bx_admin_redirect('groups');
            }

            if (strlen($groupName) > 120) {
                bx_flash('Group name must be 120 characters or less.', 'error');
                bx_admin_redirect('groups');
            }

            if (!in_array($groupStatus, $allowedStatuses, true)) {
                bx_flash('Invalid group status.', 'error');
                bx_admin_redirect('groups');
            }

            if ($groupImageFieldsSubmitted) {
                if ($groupImageUrl !== '' && !filter_var($groupImageUrl, FILTER_VALIDATE_URL)) {
                    bx_flash('Group image source must be a full uploaded image URL.', 'error');
                    bx_admin_redirect('groups');
                }
                if ($groupImageUrl !== '') {
                    $groupImageUrl = bx_project_media_uploaded_url($projectKey, $groupImageUrl);
                }
                if ($groupImageMimeType !== '' && !in_array($groupImageMimeType, ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
                    bx_flash('Group image metadata must use a supported image MIME type.', 'error');
                    bx_admin_redirect('groups');
                }
                if ($groupImageSha256 !== '' && !preg_match('/^[a-f0-9]{64}$/i', $groupImageSha256)) {
                    bx_flash('Group image metadata hash is invalid.', 'error');
                    bx_admin_redirect('groups');
                }
            }

            foreach (bx_admin_unique_keys($memberUserKeys) as $memberUserKey) {
                if ((int) bx_db()->GetOne("SELECT COUNT(*) FROM project_user WHERE project_key = ? AND user_key = ? AND user_status <> 'DELETED'", [$projectKey, $memberUserKey]) === 0) {
                    bx_flash('One or more selected users are invalid or deleted.', 'error');
                    bx_admin_redirect('groups');
                }
            }

            if ($groupKey !== '' && (int) bx_db()->GetOne('SELECT COUNT(*) FROM project_group WHERE project_key = ? AND group_key = ?', [$projectKey, $groupKey]) === 0) {
                bx_flash('Group was not found for this project.', 'error');
                bx_admin_redirect('groups');
            }

            $existingGroupName = '';
            $existingGroupDescription = '';
            $existingGroupImageUrl = '';
            $existingMemberUserKeys = [];
            if ($groupKey !== '') {
                $existingGroup = bx_db()->GetRow('SELECT group_name, group_description, group_status, group_image_path FROM project_group WHERE project_key = ? AND group_key = ? LIMIT 1', [$projectKey, $groupKey]);
                $existingGroupName = is_array($existingGroup) ? (string) ($existingGroup['group_name'] ?? '') : '';
                $existingGroupDescription = is_array($existingGroup) ? (string) ($existingGroup['group_description'] ?? '') : '';
                $existingGroupStatus = is_array($existingGroup) ? (string) ($existingGroup['group_status'] ?? '') : '';
                $existingGroupImageUrl = is_array($existingGroup) ? (string) ($existingGroup['group_image_path'] ?? '') : '';
                $existingMemberRows = $assignmentProjectionReady
                    ? (bx_db()->GetAll("SELECT assignment.user_key FROM project_user_group assignment INNER JOIN project_user u ON u.user_key = assignment.user_key AND u.project_key = assignment.project_key WHERE assignment.project_key = ? AND assignment.group_key = ? AND assignment.assignment_status = 'ACTIVE' AND u.user_status <> 'DELETED'", [$projectKey, $groupKey]) ?: [])
                    : [];
                $existingMemberUserKeys = array_values(array_filter(array_map(
                    static fn (array $row): string => (string) ($row['user_key'] ?? ''),
                    $existingMemberRows
                )));
                if ($existingGroupStatus === 'INACTIVE') {
                    bx_flash('Deactivated groups only allow restore or delete.', 'error');
                    bx_admin_redirect('groups');
                }
                if (bx_admin_is_original_administrators_group($groupKey)
                    && (
                        $existingGroupName !== $groupName
                        || $existingGroupDescription !== $groupDescription
                        || $existingGroupStatus !== $groupStatus
                        || ($groupImageFieldsSubmitted && $groupImageUrl !== $existingGroupImageUrl)
                    )
                ) {
                    bx_flash('Original Administrators group is protected and cannot be edited.', 'error');
                    bx_admin_redirect('groups');
                }
            }

            if ($groupKey === '' && count(bx_admin_unique_keys($memberUserKeys)) === 0) {
                // no-op: groups can be created before members exist
            }

            if (!bx_validate_existing_keys('builder_project', 'project_key', [$projectKey], 'project_status')) {
                bx_flash('One or more selected users are invalid or deleted.', 'error');
                bx_admin_redirect('groups');
            }

            $groupNameChanged = $groupKey === '' || strtolower($existingGroupName) !== strtolower($groupName);
            if ($groupNameChanged) {
                $duplicate = (int) bx_db()->GetOne(
                    "SELECT COUNT(*) FROM project_group WHERE project_key = ? AND group_name = ? AND group_status = 'ACTIVE' AND group_key <> ?",
                    [$projectKey, $groupName, $groupKey ?: '__new__']
                );
                if ($duplicate > 0) {
                    bx_flash('Group name already exists in enabled groups.', 'error');
                    bx_admin_redirect('groups');
                }
            }

            $memberPositionKeys = is_array($_POST['member_position_keys'] ?? null) ? $_POST['member_position_keys'] : [];
            $assignmentRows = [];
            foreach (bx_admin_unique_keys($memberUserKeys) as $memberUserKey) {
                $memberPositionKey = trim((string) ($memberPositionKeys[$memberUserKey] ?? ''));
                if ($memberPositionKey === '') {
                    bx_flash('Every selected member must have a position.', 'error');
                    bx_admin_redirect('groups');
                }
                $assignmentRows[] = ['user_key' => $memberUserKey, 'position_key' => $memberPositionKey];
            }

            $wasCreatingGroup = $groupKey === '';
            $firebaseGroup = bx_admin_write_project_group_firebase_first([
                'group_key' => $groupKey,
                'project_key' => $projectKey,
                'group_name' => $groupName,
                'group_description' => $groupDescription,
                'group_status' => $groupStatus,
                'group_image_path' => $groupImageUrl,
                'group_image_original_name' => $groupImageOriginalName,
                'group_image_mime_type' => $groupImageMimeType,
                'group_image_byte_size' => $groupImageByteSize,
                'group_image_sha256' => $groupImageSha256,
            ], $assignmentRows);
            if (($firebaseGroup['ok'] ?? false) !== true) {
                bx_flash('Group was not saved. Firebase did not acknowledge the group and assignment write.', 'error');
                bx_admin_redirect('groups');
            }
            bx_mutation_lifecycle_flash(
                $wasCreatingGroup ? 'Group and assignments saved in Firebase; MySQL projection is pending.' : 'Group and assignments updated in Firebase; MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => 'project_group and project_user_group writes were acknowledged.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the PENDING group and assignments.'],
                ]
            );
            bx_admin_redirect('groups');

        }

        if ($action === 'set_group_status') {
            if (!bx_admin_group_projection_ready()) {
                bx_flash('Group projection schema is not ready. Portal Master Sync support is required before this action.', 'error');
                bx_admin_redirect('groups');
            }
            $groupKey = trim((string) ($_POST['group_key'] ?? ''));
            $groupStatus = trim((string) ($_POST['group_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($groupKey === '' || !in_array($groupStatus, $allowedStatuses, true)) {
                bx_flash('Invalid group status request.', 'error');
                bx_admin_redirect('groups');
            }

            $existing = bx_db()->GetRow('SELECT * FROM project_group WHERE group_key = ?', [$groupKey]);
            if (!$existing) {
                bx_flash('Group was not found.', 'error');
                bx_admin_redirect('groups');
            }

            if (bx_admin_is_original_administrators_group($groupKey)) {
                bx_flash('Original Administrators group status is protected.', 'error');
                bx_admin_redirect('groups');
            }

            if ((string) ($existing['group_status'] ?? '') === 'INACTIVE' && !in_array($groupStatus, ['ACTIVE', 'DELETED'], true)) {
                bx_flash('Deactivated groups only allow restore or delete.', 'error');
                bx_admin_redirect('groups');
            }

            if ((string) ($existing['group_status'] ?? '') === 'ACTIVE' && $groupStatus === 'ACTIVE') {
                bx_flash('Group is already active.', 'error');
                bx_admin_redirect('groups');
            }

            if ($groupStatus === 'ACTIVE') {
                $activeDuplicate = (int) bx_db()->GetOne(
                    "SELECT COUNT(*) FROM project_group WHERE project_key = ? AND group_name = ? AND group_status = 'ACTIVE' AND group_key <> ?",
                    [(string) ($existing['project_key'] ?? ''), (string) ($existing['group_name'] ?? ''), $groupKey]
                );
                if ($activeDuplicate > 0) {
                    bx_flash('Group name already exists in enabled groups.', 'error');
                    bx_admin_redirect('groups');
                }
            }

            $firebaseGroupStatus = bx_admin_write_project_group_firebase_first([
                'group_key' => $groupKey,
                'project_key' => (string) ($existing['project_key'] ?? ''),
                'group_name' => (string) ($existing['group_name'] ?? ''),
                'group_description' => (string) ($existing['group_description'] ?? ''),
                'group_status' => $groupStatus,
            ], [], false);
            if (($firebaseGroupStatus['ok'] ?? false) !== true) {
                bx_flash('Group status was not changed. Firebase did not acknowledge the update.', 'error');
                bx_admin_redirect('groups');
            }
            bx_mutation_lifecycle_flash(
                'Group status updated in Firebase. MySQL projection is pending.',
                'success',
                [
                    ['label' => 'Firebase', 'status' => 'complete', 'detail' => 'project_group status was acknowledged.'],
                    ['label' => 'MySQL projection', 'status' => 'pending', 'detail' => 'Master Sync must project the PENDING status change.'],
                ]
            );
            bx_admin_redirect('groups');

        }

        if ($action === 'save_role') {
            $roleKey = trim((string) ($_POST['role_key'] ?? ''));
            $roleName = trim((string) ($_POST['role_name'] ?? ''));
            $roleDescription = trim((string) ($_POST['role_description'] ?? ''));
            $roleStatus = trim((string) ($_POST['role_status'] ?? 'ACTIVE'));
            $permissionKeys = bx_post_array('permission_keys');
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($roleName === '') {
                bx_flash('Role name is required.', 'error');
                bx_admin_redirect('roles');
            }

            if (strlen($roleName) > 120) {
                bx_flash('Role name must be 120 characters or less.', 'error');
                bx_admin_redirect('roles');
            }

            if (!in_array($roleStatus, $allowedStatuses, true)) {
                bx_flash('Invalid role status.', 'error');
                bx_admin_redirect('roles');
            }

            if (!bx_validate_existing_keys('builder_permission', 'permission_key', $permissionKeys, 'permission_status')) {
                bx_flash('One or more selected permissions are invalid or deleted.', 'error');
                bx_admin_redirect('roles');
            }

            $duplicate = (int) bx_db()->GetOne(
                'SELECT COUNT(*) FROM builder_role WHERE role_name = ? AND role_key <> ?',
                [$roleName, $roleKey ?: '__new__']
            );
            if ($duplicate > 0) {
                bx_flash('Role name already exists.', 'error');
                bx_admin_redirect('roles');
            }

            $wasCreatingRole = $roleKey === '';
            try {
                bx_admin_assert_role_guardrails($roleKey, $roleName, $roleStatus, $permissionKeys);
                bx_admin_access_transaction(function () use (&$roleKey, $roleName, $roleDescription, $roleStatus, $permissionKeys): void {
                    if ($roleKey !== '') {
                        $existing = bx_db()->GetRow('SELECT * FROM builder_role WHERE role_key = ?', [$roleKey]);
                        if (!$existing) {
                            throw new RuntimeException('Role was not found.');
                        }

                        $saved = bx_db()->Execute(
                            'UPDATE builder_role SET role_name = ?, role_description = ?, role_status = ? WHERE role_key = ?',
                            [$roleName, $roleDescription, $roleStatus, $roleKey]
                        );
                        if ($saved === false) {
                            throw new RuntimeException('Role update failed.');
                        }
                        bx_audit('UPDATE', 'builder_role', $roleKey, [
                            'role_name' => $roleName,
                            'role_status' => $roleStatus,
                            'permission_count' => count(bx_admin_unique_keys($permissionKeys)),
                        ], 'Administrator updated role.');
                    } else {
                        $roleKey = bx_uuid();
                        $saved = bx_db()->Execute(
                            'INSERT INTO builder_role (role_key, role_name, role_description, role_status) VALUES (?, ?, ?, ?)',
                            [$roleKey, $roleName, $roleDescription, $roleStatus]
                        );
                        if ($saved === false) {
                            throw new RuntimeException('Role creation failed.');
                        }
                        bx_audit('CREATE', 'builder_role', $roleKey, [
                            'role_name' => $roleName,
                            'role_status' => $roleStatus,
                            'permission_count' => count(bx_admin_unique_keys($permissionKeys)),
                        ], 'Administrator created role.');
                    }

                    $deleted = bx_db()->Execute('DELETE FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
                    if ($deleted === false) {
                        throw new RuntimeException('Existing role permissions could not be replaced.');
                    }
                    foreach (bx_admin_unique_keys($permissionKeys) as $permissionKey) {
                        $saved = bx_db()->Execute(
                            'INSERT IGNORE INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)',
                            [$roleKey, $permissionKey]
                        );
                        if ($saved === false) {
                            throw new RuntimeException('Role permission assignment could not be saved.');
                        }
                    }

                    bx_admin_assert_role_readback($roleKey, $roleName, $roleStatus, $permissionKeys);
                });

                bx_flash($wasCreatingRole ? 'Role created and verified.' : 'Role saved and verified.', 'success');
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }

            bx_admin_redirect('roles');
        }

        if ($action === 'set_role_status') {
            $roleKey = trim((string) ($_POST['role_key'] ?? ''));
            $roleStatus = trim((string) ($_POST['role_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE', 'DELETED'];

            if ($roleKey === '' || !in_array($roleStatus, $allowedStatuses, true)) {
                bx_flash('Invalid role status request.', 'error');
                bx_admin_redirect('roles');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_role WHERE role_key = ?', [$roleKey]);
            if (!$existing) {
                bx_flash('Role was not found.', 'error');
                bx_admin_redirect('roles');
            }

            try {
                if ((string) ($existing['role_name'] ?? '') === 'Administrator' && $roleStatus !== 'ACTIVE') {
                    throw new RuntimeException('The built-in Administrator role must remain active.');
                }
                bx_admin_access_transaction(function () use ($roleStatus, $roleKey, $existing): void {
                    $saved = bx_db()->Execute('UPDATE builder_role SET role_status = ? WHERE role_key = ?', [$roleStatus, $roleKey]);
                    if ($saved === false) {
                        throw new RuntimeException('Role status update failed.');
                    }
                    $readBackStatus = (string) bx_db()->GetOne('SELECT role_status FROM builder_role WHERE role_key = ? LIMIT 1', [$roleKey]);
                    if ($readBackStatus !== $roleStatus) {
                        throw new RuntimeException('Role status read-back verification failed.');
                    }
                    bx_audit($roleStatus === 'DELETED' ? 'DELETE' : 'STATUS', 'builder_role', $roleKey, [
                        'role_name' => $existing['role_name'],
                        'role_status' => $roleStatus,
                    ], 'Administrator changed role status.');
                });
                bx_flash('Role status updated and verified.', 'success');
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }
            bx_admin_redirect('roles');
        }

        if ($action === 'set_permission_status') {
            $permissionKey = trim((string) ($_POST['permission_key'] ?? ''));
            $permissionStatus = trim((string) ($_POST['permission_status'] ?? ''));
            $allowedStatuses = ['ACTIVE', 'INACTIVE'];

            if ($permissionKey === '' || !in_array($permissionStatus, $allowedStatuses, true)) {
                bx_flash('Invalid permission status request.', 'error');
                bx_admin_redirect('permissions');
            }

            $existing = bx_db()->GetRow('SELECT * FROM builder_permission WHERE permission_key = ?', [$permissionKey]);
            if (!$existing) {
                bx_flash('Permission was not found.', 'error');
                bx_admin_redirect('permissions');
            }

            try {
                $adminRoleKey = bx_admin_role_key_by_name('Administrator');
                if ($permissionStatus !== 'ACTIVE' && $adminRoleKey !== '' && (int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ? AND permission_key = ?', [$adminRoleKey, $permissionKey]) > 0) {
                    throw new RuntimeException('Permissions assigned to the Administrator role must remain active.');
                }
                bx_admin_access_transaction(function () use ($permissionStatus, $permissionKey, $existing): void {
                    $saved = bx_db()->Execute('UPDATE builder_permission SET permission_status = ? WHERE permission_key = ?', [$permissionStatus, $permissionKey]);
                    if ($saved === false) {
                        throw new RuntimeException('Permission status update failed.');
                    }
                    $readBackStatus = (string) bx_db()->GetOne('SELECT permission_status FROM builder_permission WHERE permission_key = ? LIMIT 1', [$permissionKey]);
                    if ($readBackStatus !== $permissionStatus) {
                        throw new RuntimeException('Permission status read-back verification failed.');
                    }
                    bx_audit('STATUS', 'builder_permission', $permissionKey, [
                        'permission_code' => $existing['permission_code'],
                        'permission_status' => $permissionStatus,
                    ], 'Administrator changed permission status.');
                });
                bx_flash('Permission status updated and verified.', 'success');
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }
            bx_admin_redirect('permissions');
        }

        if ($action === 'save_permission_matrix') {
            $roleKeys = bx_post_array('matrix_role_keys');
            $permissionKeys = bx_post_array('matrix_permission_keys');
            $matrix = $_POST['role_permissions'] ?? [];

            if (!is_array($matrix)) {
                $matrix = [];
            }

            if (!bx_validate_existing_keys('builder_role', 'role_key', $roleKeys, 'role_status')
                || !bx_validate_existing_keys('builder_permission', 'permission_key', $permissionKeys, 'permission_status')) {
                bx_flash('One or more matrix roles or permissions are invalid or deleted.', 'error');
                bx_admin_redirect('permissions');
            }

            try {
                bx_admin_assert_permission_matrix_guardrails($roleKeys, $permissionKeys, $matrix);
                bx_admin_access_transaction(function () use ($roleKeys, $permissionKeys, $matrix): void {
                    foreach (bx_admin_unique_keys($roleKeys) as $roleKey) {
                        $deleted = bx_db()->Execute('DELETE FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
                        if ($deleted === false) {
                            throw new RuntimeException('Existing permission matrix rows could not be replaced.');
                        }
                        $selectedPermissions = $matrix[$roleKey] ?? [];
                        if (!is_array($selectedPermissions)) {
                            $selectedPermissions = [];
                        }

                        foreach (bx_admin_unique_keys($selectedPermissions) as $permissionKey) {
                            if ($permissionKey !== '' && in_array($permissionKey, $permissionKeys, true)) {
                                $saved = bx_db()->Execute(
                                    'INSERT IGNORE INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)',
                                    [$roleKey, $permissionKey]
                                );
                                if ($saved === false) {
                                    throw new RuntimeException('Permission matrix assignment could not be saved.');
                                }
                            }
                        }

                        $selectedCount = count(array_values(array_filter(bx_admin_unique_keys($selectedPermissions), static fn (string $permissionKey): bool => in_array($permissionKey, $permissionKeys, true))));
                        $readBackCount = (int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ?', [$roleKey]);
                        if ($readBackCount !== $selectedCount) {
                            throw new RuntimeException('Permission matrix read-back verification failed.');
                        }
                    }

                    bx_audit('UPDATE', 'builder_role_permission', 'permission-matrix', [
                        'role_count' => count(bx_admin_unique_keys($roleKeys)),
                        'permission_count' => count(bx_admin_unique_keys($permissionKeys)),
                    ], 'Administrator updated permission matrix.');
                });
                bx_flash('Permission matrix updated and verified.', 'success');
            } catch (Throwable $exception) {
                bx_flash($exception->getMessage(), 'error');
            }
            bx_admin_redirect('permissions');
        }

        if ($action === 'save_system_settings') {
            $settingValues = $_POST['setting_values'] ?? [];
            $settingsGroup = trim((string) ($_POST['settings_group'] ?? ''));
            if (!is_array($settingValues)) {
                bx_flash('Invalid settings request.', 'error');
                bx_admin_redirect_settings($settingsGroup);
            }

            $settingsProjectKey = bx_admin_project_key((string) ($_POST['project_key'] ?? $_GET['project_key'] ?? ''));
            $systemSettings = bx_db()->GetAll("SELECT setting_key, setting_name, setting_value, is_secret, setting_group, 'builder_system_setting' AS setting_source FROM builder_system_setting WHERE setting_status = 'ACTIVE' AND setting_group NOT IN ('android', 'media')") ?: [];
            $projectSettings = $settingsProjectKey !== ''
                ? (bx_db()->GetAll("SELECT setting_key, setting_name, setting_value, is_secret, setting_group, 'project_setting' AS setting_source FROM project_setting WHERE project_key = ? AND setting_status = 'ACTIVE' AND setting_group = 'android'", [$settingsProjectKey]) ?: [])
                : [];
            $mediaSettings = $settingsProjectKey !== ''
                ? (bx_db()->GetAll("SELECT setting_key, setting_name, setting_value, is_secret, setting_group, 'project_setting_media' AS setting_source FROM project_setting_media WHERE project_key = ? AND setting_status = 'ACTIVE' AND setting_group = 'media'", [$settingsProjectKey]) ?: [])
                : [];
            $settings = array_merge($systemSettings, $projectSettings, $mediaSettings);
            $changed = 0;
            $firebaseOverrides = [];

            foreach ($settings as $setting) {
                $settingKey = (string) $setting['setting_key'];
                if (!array_key_exists($settingKey, $settingValues)) {
                    continue;
                }

                $settingName = (string) $setting['setting_name'];
                if (str_starts_with($settingName, 'ui_')) {
                    continue;
                }

                if ((int) ($setting['is_secret'] ?? 0) === 1) {
                    continue;
                }

                if (!preg_match('/^[A-Za-z0-9_]{2,120}$/', $settingName)) {
                    bx_flash('Invalid setting name found.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                $newValue = trim((string) $settingValues[$settingKey]);
                if (strlen($newValue) > 2000) {
                    bx_flash('Setting values must be 2000 characters or less.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if (in_array($settingName, ['session_timeout_minutes', 'password_min_length'], true)) {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 1) {
                        bx_flash('Security numeric settings must be positive whole numbers.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if (in_array($settingName, ['password_expiration_days', 'password_history_count', 'password_reset_token_minutes'], true)) {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 0) {
                        bx_flash('Numeric settings must be zero or positive whole numbers.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if (in_array($settingName, ['debug_enabled', 'debug_show_queries', 'debug_show_files', 'debug_show_phase_task', 'debug_log_traces', 'sharingan_enabled', 'android_force_update_enabled', 'android_release_acknowledgement_required', 'android_geofence_required', 'android_offline_queue_enabled', 'android_media_upload_enabled'], true) && !in_array($newValue, ['0', '1'], true)) {
                    bx_flash('Switch settings must be on or off.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

	                if (in_array($settingName, ['android_current_version_code', 'android_min_supported_version_code'], true)) {
	                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 1) {
	                        bx_flash('Android version code settings must be positive whole numbers.', 'error');
	                        bx_admin_redirect_settings($settingsGroup);
	                    }
	                }

	                if ($settingName === 'android_welcome_title') {
	                    if ($newValue === '' || strlen($newValue) > 120) {
	                        bx_flash('Android welcome title must be 1 to 120 characters.', 'error');
	                        bx_admin_redirect_settings($settingsGroup);
	                    }
	                }

	                if ($settingName === 'android_welcome_description' && strlen($newValue) > 240) {
	                    bx_flash('Android welcome description must be 240 characters or less.', 'error');
	                    bx_admin_redirect_settings($settingsGroup);
	                }

	                if (in_array($settingName, ['android_offline_retry_interval_seconds', 'android_dashboard_refresh_seconds'], true)) {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 5 || (int) $newValue > 86400) {
                        bx_flash('Android timing settings must be between 5 and 86400 seconds.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'android_geofence_latitude' && $newValue !== '') {
                    if (!is_numeric($newValue) || (float) $newValue < -90 || (float) $newValue > 90) {
                        bx_flash('Android geofence latitude must be between -90 and 90.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'android_geofence_longitude' && $newValue !== '') {
                    if (!is_numeric($newValue) || (float) $newValue < -180 || (float) $newValue > 180) {
                        bx_flash('Android geofence longitude must be between -180 and 180.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'android_geofence_max_radius_meters') {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 1 || (int) $newValue > 1000000) {
                        bx_flash('Android geofence max radius must be between 1 and 1000000 meters.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'android_app_package_name' && !preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $newValue)) {
                    bx_flash('Android package name is invalid.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if ($settingName === 'android_tenant_configuration_endpoint_url') {
                    $androidUrlParts = parse_url($newValue);
                    $androidEndpointScheme = is_array($androidUrlParts) ? strtolower((string) ($androidUrlParts['scheme'] ?? '')) : '';
                    $androidEndpointHost = is_array($androidUrlParts) ? strtolower((string) ($androidUrlParts['host'] ?? '')) : '';
                    $androidEndpointIsPrivateHttp = $androidEndpointScheme === 'http' && (
                        in_array($androidEndpointHost, ['localhost', '127.0.0.1'], true)
                        || preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/', $androidEndpointHost)
                        || preg_match('/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $androidEndpointHost)
                        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $androidEndpointHost)
                    );
                    if ($newValue === '' || !filter_var($newValue, FILTER_VALIDATE_URL) || !is_array($androidUrlParts) || ($androidEndpointScheme !== 'https' && !$androidEndpointIsPrivateHttp)) {
                        bx_flash('Android tenant configuration endpoint must be a valid HTTPS URL, or private network HTTP URL for development.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'android_update_apk_download_path') {
                    $androidApkParts = parse_url($newValue);
                    $androidApkPath = is_array($androidApkParts) ? (string) ($androidApkParts['path'] ?? '') : '';
                    $androidApkScheme = is_array($androidApkParts) ? strtolower((string) ($androidApkParts['scheme'] ?? '')) : '';
                    $androidApkHasUrl = $androidApkScheme !== '';
                    $androidApkPathIsValid = $newValue !== ''
                        && !str_contains($newValue, '..')
                        && preg_match('/^\/[A-Za-z0-9._~\/%+-]+\.apk$/i', $newValue);
                    $androidApkUrlIsValid = $androidApkHasUrl
                        && in_array($androidApkScheme, ['http', 'https'], true)
                        && filter_var($newValue, FILTER_VALIDATE_URL)
                        && str_ends_with(strtolower($androidApkPath), '.apk');

                    if (!$androidApkPathIsValid && !$androidApkUrlIsValid) {
                        bx_flash('Android APK download path must be an HTTP(S) URL or root-relative .apk path.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if (in_array($settingName, ['android_banner_image_url', 'android_login_background_image_url', 'android_splash_screen_image_url_1', 'android_splash_screen_image_url_2', 'android_splash_screen_image_url_3'], true) && $newValue !== '') {
                    $androidSplashUrlParts = parse_url($newValue);
                    if (!filter_var($newValue, FILTER_VALIDATE_URL) || !is_array($androidSplashUrlParts) || !in_array(strtolower((string) ($androidSplashUrlParts['scheme'] ?? '')), ['http', 'https'], true)) {
                        bx_flash('Android image links must be valid HTTP(S) URLs.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'debug_trace_retention_days') {
                    if (!preg_match('/^[0-9]+$/', $newValue) || (int) $newValue < 0 || (int) $newValue > 365) {
                        bx_flash('Debug trace retention must be between 0 and 365 days.', 'error');
                        bx_admin_redirect_settings($settingsGroup);
                    }
                }

                if ($settingName === 'debug_allowed_roles' && !preg_match('/^[A-Za-z0-9_, -]+$/', $newValue)) {
                    bx_flash('Debug allowed roles may only contain letters, numbers, spaces, commas, hyphens, and underscores.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if ($settingName === 'contact_email' && $newValue !== '' && !filter_var($newValue, FILTER_VALIDATE_EMAIL)) {
                    bx_flash('Contact email must be valid.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if (in_array($settingName, ['media_uploader_target_url', 'media_image_viewer_url'], true) && $newValue !== '' && !filter_var($newValue, FILTER_VALIDATE_URL)) {
                    bx_flash('Media URLs must be valid full URLs.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if (in_array($settingName, ['media_uploader_target_url', 'media_image_viewer_url'], true) && $newValue !== '' && bx_media_url_has_loopback_host($newValue)) {
                    bx_flash('Media URLs must use the configured public media host, not localhost or loopback.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if ($settingName === 'admin_default_tab' && !in_array($newValue, ['dashboard', 'users', 'positions', 'groups', 'roles', 'permissions', 'branches', 'projects', 'settings', 'bed-management', 'bed-lookup', 'bed-treatment', 'bed-source', 'task-builder', 'audit', 'forms', 'health', 'template'], true)) {
                    bx_flash('Administrator default tab is invalid.', 'error');
                    bx_admin_redirect_settings($settingsGroup);
                }

                if ($newValue !== (string) $setting['setting_value']) {
                    $firebaseOverrides[$settingName] = $newValue;
                    $changed++;
                }
            }

            $firebaseSync = bx_sync_settings_to_firebase($settingsProjectKey, $firebaseOverrides);
            $firebaseStep = bx_admin_settings_firebase_step($firebaseSync);
            $firebaseOk = $firebaseStep['status'] === 'complete';
            bx_mutation_lifecycle_flash($firebaseOk ? 'System settings saved to Firebase.' : 'System settings were not saved; Firebase needs attention.', $firebaseOk ? 'success' : 'error', [
                ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                ['label' => 'Firebase', 'status' => $firebaseOk ? 'complete' : 'failed', 'detail' => $changed > 0 ? (string) $changed . ' setting value(s) submitted to Firebase first.' : 'No setting changes were submitted.'],
                ['label' => 'MySQL', 'status' => 'pending', 'detail' => 'No direct MySQL settings write was performed; TRAVERSE will project the acknowledged Firebase document.'],
                $firebaseStep,
            ], $firebaseOk ? '' : (string) ($firebaseSync['message'] ?? 'Firebase settings sync did not complete.'));
            bx_admin_redirect_settings($settingsGroup);
        }

        if ($action === 'resync_project_bed') {
            $filters = bx_bed_lookup_filters_from_array($_POST);
            $bedKey = trim((string) ($_POST['bed_key'] ?? ''));
            try {
                $bed = bx_resync_project_bed($bedKey, (string) ($currentUser['user_key'] ?? ''));
                bx_mutation_lifecycle_flash('Bed row refreshed.', 'success', [
                    ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                    ['label' => 'Latest list', 'status' => ((bool) ($bed['sourceFound'] ?? false)) ? 'complete' : 'blocked', 'detail' => ((bool) ($bed['sourceFound'] ?? false)) ? 'Selected bed matched the latest hospital list.' : 'Selected bed was not found in the latest list and was marked inactive.'],
                    ['label' => 'Saved bed', 'status' => 'complete', 'detail' => 'Selected bed was updated.'],
                    ['label' => 'History', 'status' => 'complete', 'detail' => 'Existing task links and history were kept.'],
                ]);
            } catch (Throwable $error) {
                bx_mutation_lifecycle_flash('Bed row refresh failed.', 'error', [
                    ['label' => 'Authorization', 'status' => 'complete', 'detail' => 'Administrator authorization and CSRF checks passed.'],
                    ['label' => 'Latest list', 'status' => 'blocked', 'detail' => $error->getMessage()],
                    ['label' => 'Saved bed', 'status' => 'not_started', 'detail' => 'No verified bed update was completed.'],
                ]);
            }
            bx_admin_redirect_bed_lookup($filters);
        }

        if ($action === 'apply_runtime_project_config') {
            try {
                bx_write_runtime_project_config();
                bx_audit('UPDATE', 'runtime_health', 'project-config', [
                    'upload_max_filesize' => '1G',
                    'post_max_size' => '1G',
                    'memory_limit' => '1G',
                    'max_execution_time' => '300',
                ], 'Administrator applied BuilderX project-level runtime config baseline.');
                bx_flash('Project-level runtime config files were updated. Restart/reload PHP or the web server if your host requires it.', 'success');
            } catch (Throwable $error) {
                bx_flash('Runtime config update failed: ' . $error->getMessage(), 'error');
            }
            bx_admin_redirect('health');
        }

        if ($action === 'run_template_command') {
            $presetArg = trim((string) ($_POST['preset_arg'] ?? '--preset b0'));
            $template = trim((string) ($_POST['template'] ?? 'next'));
            $label = trim((string) ($_POST['label'] ?? ''));
            $confirmation = strtoupper(trim((string) ($_POST['confirmation'] ?? '')));

            if ($confirmation !== 'RUN') {
                bx_admin_json_response([
                    'ok' => false,
                    'message' => 'Type RUN before applying the template command.',
                ], 422);
            }

            try {
                $preset = bx_template_store_preset($label, $presetArg, $template);
                $result = bx_admin_run_template_command($preset['preset_arg'], $preset['template']);
                bx_audit('RUN', 'template_command', 'shadcn-init', [
                    'command' => $result['command'],
                    'root_path' => $result['root_path'],
                    'exit_code' => (string) $result['exit_code'],
                    'duration_seconds' => (string) $result['duration_seconds'],
                ], 'Administrator ran shadcn template command.');

                bx_admin_json_response([
                    'ok' => ((int) $result['exit_code']) === 0,
                    'message' => ((int) $result['exit_code']) === 0 ? 'Template command completed.' : 'Template command exited with an error.',
                    'result' => $result,
                    'refreshAdministrator' => (bool) ($result['refresh_administrator'] ?? false),
                    'templatePresets' => bx_template_presets(),
                ], ((int) $result['exit_code']) === 0 ? 200 : 500);
            } catch (Throwable $error) {
                bx_audit('ERROR', 'template_command', 'shadcn-init', [
                    'preset_arg' => $presetArg,
                    'template' => $template,
                    'error' => $error->getMessage(),
                ], 'Administrator template command failed before execution.');

                bx_admin_json_response([
                    'ok' => false,
                    'message' => $error->getMessage(),
                ], 500);
            }
        }

        if ($action === 'save_template_preset') {
            $presetArg = trim((string) ($_POST['preset_arg'] ?? '--preset b0'));
            $template = trim((string) ($_POST['template'] ?? 'next'));
            $label = trim((string) ($_POST['label'] ?? ''));

            try {
                $preset = bx_template_store_preset($label, $presetArg, $template);
                bx_audit('UPDATE', 'template_command', 'template-presets', [
                    'preset_arg' => $preset['preset_arg'],
                    'template' => $preset['template'],
                ], 'Administrator saved shadcn template preset.');

                bx_admin_json_response([
                    'ok' => true,
                    'message' => 'Template preset saved.',
                    'preset' => $preset,
                    'templatePresets' => bx_template_presets(),
                ]);
            } catch (Throwable $error) {
                bx_admin_json_response([
                    'ok' => false,
                    'message' => $error->getMessage(),
                ], 422);
            }
        }
    }
}

bx_admin_seed_settings();
$flash = bx_take_flash();
$user = bx_current_user();
$hasUsers = bx_count('builder_user') > 0;
$adminAuthorization = bx_authorization_guard(['requireAdmin' => true, 'requireAdminFirebase' => true]);
$isAdmin = $adminAuthorization['allowed'] && bx_admin_session_boundary_allows();
if ($isAdmin && is_array($adminAuthorization['user'] ?? null)) {
    $user = $adminAuthorization['user'];
}
$administratorDisplayLogin = strtolower(trim((string) ($user['user_login'] ?? '')));
$administratorDisplayEmail = trim((string) ($user['user_email'] ?? ''));
if ($isAdmin && $administratorDisplayLogin !== '') {
    $projectUserAuthEmail = trim((string) (bx_db()->GetOne(
        'SELECT user_auth_email FROM project_user WHERE user_login = ? AND user_status <> \'DELETED\' LIMIT 1',
        [$administratorDisplayLogin]
    ) ?: ''));
    $authEmailAt = strrpos($projectUserAuthEmail, '@');
    $authEmailDomain = $authEmailAt === false ? '' : strtolower(trim(substr($projectUserAuthEmail, $authEmailAt + 1)));
    if ($authEmailDomain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $authEmailDomain)) {
        $administratorDisplayEmail = $administratorDisplayLogin . '@' . $authEmailDomain;
    }
}
$softwareName = bx_setting('software_name', 'BuilderX');
$manifestPath = dirname(__DIR__) . '/frontend/dist/.vite/manifest.json';
$manifest = file_exists($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : [];
$entry = $manifest['index.html'] ?? null;
$assetsBase = '../frontend/dist/';
$auditFilters = bx_audit_filters_from_request();
$audits = $isAdmin ? bx_audit_rows($auditFilters, (string) ($_GET['audit_export'] ?? '') === 'csv' ? 1000 : 250) : [];

if ($isAdmin && (string) ($_GET['audit_export'] ?? '') === 'csv') {
    bx_audit_export_csv($audits);
}

$adminState = $_SESSION['builderx_admin_state'] ?? [];
unset($_SESSION['builderx_admin_state']);
if (!is_array($adminState)) {
    $adminState = [];
}

function bx_admin_payload_rows(mixed $rows): array
{
    return is_array($rows) ? $rows : [];
}

/**
 * Return Firebase web client identifiers only. Service-account credentials and
 * private keys must never be included in the Administrator payload.
 *
 * @return array<string, string|bool>
 */
function bx_admin_firebase_web_config(): array
{
    $value = static function (string $settingName, string $envName, string $fallback = ''): string {
        $settingValue = trim((string) bx_setting($settingName, ''));
        if ($settingValue !== '') {
            return $settingValue;
        }

        $envValue = getenv($envName);
        return is_string($envValue) && trim($envValue) !== '' ? trim($envValue) : $fallback;
    };

    $config = [
        'apiKey' => $value('firebase_web_api_key', 'FIREBASE_WEB_API_KEY'),
        'authDomain' => $value('firebase_web_auth_domain', 'FIREBASE_WEB_AUTH_DOMAIN'),
        'projectId' => $value('firebase_web_project_id', 'FIREBASE_WEB_PROJECT_ID', bx_admin_firebase_project_id()),
        'storageBucket' => $value('firebase_web_storage_bucket', 'FIREBASE_WEB_STORAGE_BUCKET'),
        'messagingSenderId' => $value('firebase_web_messaging_sender_id', 'FIREBASE_WEB_MESSAGING_SENDER_ID'),
        'appId' => $value('firebase_web_app_id', 'FIREBASE_WEB_APP_ID'),
        'measurementId' => $value('firebase_web_measurement_id', 'FIREBASE_WEB_MEASUREMENT_ID'),
    ];

    return array_filter($config, static fn (string $item): bool => $item !== '') + [
        'enabled' => $config['apiKey'] !== '' && $config['projectId'] !== '',
        'mode' => 'administrator_id_token_handoff',
    ];
}

/**
 * Resolve the Administrator form's friendly login alias without exposing
 * credentials or any project-user fields beyond the Firebase Auth identity.
 *
 * @return array<string, array{username: string, email: string}>
 */
function bx_admin_firebase_login_aliases(): array
{
    $row = bx_db()->GetRow(
        "SELECT user_login, user_auth_username, user_auth_email
         FROM project_user
         WHERE user_login = 'admin' AND user_status = 'ACTIVE' AND user_deleted_at IS NULL
         LIMIT 1"
    );
    if (!is_array($row)) {
        return [];
    }

    $login = strtolower(trim((string) ($row['user_login'] ?? '')));
    $username = strtolower(trim((string) ($row['user_auth_username'] ?? '')));
    $email = strtolower(trim((string) ($row['user_auth_email'] ?? '')));
    if ($login === 'admin' && ($email !== '' || $username !== '')) {
        return ['admin' => ['username' => $username, 'email' => $email]];
    }

    return [];
}

$settingsProjectKey = $isAdmin ? bx_admin_project_key((string) ($_GET['project_key'] ?? '')) : '';
$settingsForPayload = [];
if ($isAdmin) {
    bx_seed_media_project_settings();
    $systemSettingsForPayload = bx_admin_payload_rows(bx_db()->GetAll("SELECT setting_key, setting_group, setting_name, setting_value, setting_status, is_secret FROM builder_system_setting WHERE setting_status = 'ACTIVE' AND setting_group NOT IN ('android', 'media') AND setting_name NOT LIKE 'ui\\_%' AND setting_name <> 'template_presets' ORDER BY setting_group ASC, setting_name ASC"));
    $projectSettingsForPayload = $settingsProjectKey !== ''
        ? bx_admin_payload_rows(bx_db()->GetAll("SELECT setting_key, setting_group, setting_name, setting_value, setting_status, is_secret FROM project_setting WHERE project_key = ? AND setting_status = 'ACTIVE' AND setting_group = 'android' ORDER BY setting_group ASC, setting_name ASC", [$settingsProjectKey]))
        : [];
    $mediaSettingsForPayload = $settingsProjectKey !== ''
        ? bx_admin_payload_rows(bx_db()->GetAll("SELECT setting_key, setting_group, setting_name, setting_value, setting_status, is_secret FROM project_setting_media WHERE project_key = ? AND setting_status = 'ACTIVE' AND setting_group = 'media' ORDER BY setting_group ASC, setting_name ASC", [$settingsProjectKey]))
        : [];
    $settingsForPayload = array_merge($systemSettingsForPayload, $projectSettingsForPayload, $mediaSettingsForPayload);
}
foreach ($settingsForPayload as &$settingForPayload) {
    $settingName = (string) ($settingForPayload['setting_name'] ?? '');
    if ((int) ($settingForPayload['is_secret'] ?? 0) === 1) {
        $settingForPayload['setting_value'] = '';
        $settingForPayload['is_secret'] = '1';
    }
}
unset($settingForPayload);

$initialTab = (string) ($_GET['tab'] ?? 'overview');
$removedReportTab = 'family' . '-reports';
if ($initialTab === $removedReportTab) {
    $initialTab = 'dashboard';
}

$bedLookupFilters = $isAdmin ? bx_bed_lookup_filters_from_request() : [];
$taskBuilderSelectedTaskKey = trim((string) ($_GET['selected_task_key'] ?? ''));
if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskBuilderSelectedTaskKey)) {
    $taskBuilderSelectedTaskKey = '';
}

// Firebase-first healing: a task must have one active initial NEW stage.
// This is idempotent and does not alter TRAVERSE; TRAVERSE will project the
// acknowledged Firebase document to MySQL on its normal queue pass.
if ($isAdmin && $initialTab === 'task-builder' && $taskBuilderSelectedTaskKey !== '') {
    $taskStageHealing = bx_admin_heal_project_task_default_stage($taskBuilderSelectedTaskKey);
    if (($taskStageHealing['ok'] ?? false) !== true) {
        $flash = [
            'type' => 'error',
            'message' => 'The task initial stage could not be healed in Firebase.',
            'details' => (string) ($taskStageHealing['message'] ?? 'Firebase stage healing failed.'),
        ];
    }
}
$assignmentProjectionReady = $isAdmin && bx_admin_assignment_projection_ready();
$positionProjectionReady = $isAdmin && bx_admin_position_projection_ready();
$groupMemberUserKeysSelect = "'' AS member_user_keys, '' AS member_names, 0 AS member_count, '' AS member_position_map";
if ($assignmentProjectionReady) {
    $groupMemberUserKeysSelect = "
            COALESCE((SELECT GROUP_CONCAT(DISTINCT assignment.user_key ORDER BY assignment.user_key SEPARATOR ',')
                FROM project_user_group assignment
                WHERE assignment.project_key = g.project_key
                  AND assignment.group_key = g.group_key
                  AND assignment.assignment_status = 'ACTIVE'), '') AS member_user_keys,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT u.user_name ORDER BY u.user_name SEPARATOR ', ')
                FROM project_user_group assignment
                INNER JOIN project_user u ON u.user_key = assignment.user_key AND u.project_key = assignment.project_key
                WHERE assignment.project_key = g.project_key
                  AND assignment.group_key = g.group_key
                  AND assignment.assignment_status = 'ACTIVE'
                  AND u.user_status <> 'DELETED'), '') AS member_names,
            (SELECT COUNT(*)
                FROM project_user_group assignment
                INNER JOIN project_user u ON u.user_key = assignment.user_key AND u.project_key = assignment.project_key
                WHERE assignment.project_key = g.project_key
                  AND assignment.group_key = g.group_key
                  AND assignment.assignment_status = 'ACTIVE'
                  AND u.user_status <> 'DELETED') AS member_count,
            COALESCE((SELECT GROUP_CONCAT(CONCAT(assignment.user_key, '=', assignment.position_key) ORDER BY assignment.user_key SEPARATOR ',')
                FROM project_user_group assignment
                WHERE assignment.project_key = g.project_key
                  AND assignment.group_key = g.group_key
                  AND assignment.assignment_status = 'ACTIVE'), '') AS member_position_map";
}

$payload = [
    'csrf' => bx_csrf_token(),
    'firebaseConfig' => bx_admin_firebase_web_config(),
    'firebaseLoginAliases' => bx_admin_firebase_login_aliases(),
    'softwareName' => $softwareName,
    'projectBasePath' => bx_project_base_path(),
    'projectRoot' => $isAdmin ? dirname(__DIR__) : '',
    'sharinganEnabled' => bx_setting('sharingan_enabled', '0') === '1',
    'templatePresets' => $isAdmin ? bx_template_presets() : [],
    'bedMasterListSummary' => $isAdmin ? bx_bed_master_list_summary() : null,
    'bedLookupFilters' => $bedLookupFilters,
    'bedLookupOptions' => $isAdmin ? bx_project_bed_lookup_options($bedLookupFilters) : [],
    'bedLookupRows' => $isAdmin ? bx_project_bed_lookup_rows($bedLookupFilters) : [],
    'bedTreatments' => $isAdmin ? bx_project_bed_treatment_rows() : [],
    'bedSources' => $isAdmin ? bx_project_bed_source_rows() : [],
    'buildingFloors' => $isAdmin ? bx_project_building_floor_rows() : [],
    'projectTasks' => $isAdmin ? bx_project_task_rows() : [],
    'projectTaskStages' => $isAdmin ? bx_project_task_stage_rows() : [],
    'projectTaskStageResponses' => $isAdmin ? bx_project_task_stage_response_rows() : [],
    'taskBuilderSelectedTaskKey' => $isAdmin ? $taskBuilderSelectedTaskKey : '',
    'flash' => $flash,
    'hasUsers' => $hasUsers,
    'isSignedIn' => (bool) $user,
    'isAdmin' => $isAdmin,
    'initialTab' => $initialTab,
    'initialState' => $adminState,
    'user' => $user ? [
        'key' => $user['user_key'],
        'name' => $user['user_name'],
        'login' => $administratorDisplayLogin,
        'email' => $administratorDisplayEmail,
    ] : null,
    'metrics' => $isAdmin ? [
        'Users' => bx_count('project_user', "user_status <> 'DELETED'"),
        'Positions' => bx_count('project_user_position', "position_status <> 'DELETED'"),
        'Branches' => bx_count('builder_branch', "branch_status <> 'DELETED'"),
        'Projects' => bx_count('builder_project', "project_status <> 'DELETED'"),
        'Forms' => bx_count('builder_form', "form_status <> 'DELETED'"),
        'Roles' => bx_count('builder_role', "role_status <> 'DELETED'"),
        'Permissions' => bx_count('builder_permission', "permission_status <> 'DELETED'"),
        'Audit Logs' => bx_count('builder_audit_log'),
    ] : [],
    'managementReadiness' => $isAdmin ? [
        ['key' => 'users', 'label' => 'Project Accounts', 'route' => 'users', 'active_records' => bx_count('project_user', "user_status <> 'DELETED'"), 'confirmation' => 'status_change'],
        ['key' => 'positions', 'label' => 'Project Positions', 'route' => 'positions', 'active_records' => bx_count('project_user_position', "position_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'groups', 'label' => 'Project Groups', 'route' => 'groups', 'active_records' => bx_count('project_user_group', "group_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'roles', 'label' => 'Roles', 'route' => 'roles', 'active_records' => bx_count('builder_role', "role_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'permissions', 'label' => 'Permissions', 'route' => 'permissions', 'active_records' => bx_count('builder_permission', "permission_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'branches', 'label' => 'Branches', 'route' => 'branches', 'active_records' => bx_count('builder_branch', "branch_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'projects', 'label' => 'Projects', 'route' => 'projects', 'active_records' => bx_count('builder_project', "project_status <> 'DELETED'"), 'confirmation' => 'soft_delete'],
        ['key' => 'settings', 'label' => 'Configuration', 'route' => 'settings', 'active_records' => bx_count('builder_system_setting', "setting_status <> 'DELETED'"), 'confirmation' => 'save_confirmation'],
        ['key' => 'audit', 'label' => 'Audit', 'route' => 'audit', 'active_records' => bx_count('builder_audit_log'), 'confirmation' => 'read_only'],
    ] : [],
    // Profile rows remain visible even while the assignment projection is
    // unavailable. Assignment fields stay blank until project_user_group is
    // migrated; this keeps the Users view from hiding valid Firebase profiles.
    'users' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            u.user_key,
            u.project_key,
            u.user_login,
            COALESCE(u.user_auth_username, '') AS user_auth_username,
            COALESCE(u.user_auth_email, '') AS user_auth_email,
            u.user_name,
            COALESCE(u.user_chat_name, '') AS user_chat_name,
            '' AS user_email,
            COALESCE(u.user_mobile_number, '') AS user_mobile_number,
            COALESCE(u.user_avatar_path, '') AS user_avatar_path,
            COALESCE(u.user_avatar_original_name, '') AS user_avatar_original_name,
            COALESCE(u.user_avatar_mime_type, '') AS user_avatar_mime_type,
            COALESCE(u.user_avatar_byte_size, 0) AS user_avatar_byte_size,
            COALESCE(u.user_avatar_sha256, '') AS user_avatar_sha256,
            COALESCE(DATE_FORMAT(u.user_avatar_uploaded_at, '%Y-%m-%d %H:%i:%s'), '') AS user_avatar_uploaded_at,
            '' AS group_key,
            '' AS position_key,
            '' AS position_code,
            '' AS position_name,
            '' AS position_group_key,
            '' AS position_group_name,
            u.user_status,
            0 AS user_failed_login_count,
            COALESCE(DATE_FORMAT(u.user_last_login_at, '%Y-%m-%d %H:%i:%s'), '') AS user_last_login_at,
            COALESCE(u.user_last_login_ip_address, '') AS user_last_login_ip_address,
            COALESCE(u.user_last_login_device, '') AS user_last_login_device,
            COALESCE(DATE_FORMAT(u.user_last_logout_at, '%Y-%m-%d %H:%i:%s'), '') AS user_last_logout_at,
            COALESCE(u.user_last_logout_ip_address, '') AS user_last_logout_ip_address,
            COALESCE(u.user_last_logout_device, '') AS user_last_logout_device,
            '' AS role_keys,
            '' AS group_keys,
            '' AS branch_keys,
            u.project_key AS project_keys,
            '' AS role_names,
            '' AS group_names,
            '' AS branch_codes,
            COALESCE(p.project_code, '') AS project_codes
        FROM project_user u
        LEFT JOIN builder_project p ON p.project_key = u.project_key
        ORDER BY p.project_code ASC, u.user_name ASC
    ")) : [],
    'positions' => $positionProjectionReady ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            pos.position_key,
            pos.project_key,
            COALESCE(pos.group_key, '') AS group_key,
            pos.position_code,
            pos.position_name,
            pos.position_description,
            pos.position_status,
            COALESCE(g.group_name, '') AS group_name,
            COALESCE(g.group_status, '') AS group_status,
            COALESCE(p.project_code, '') AS project_code,
            COALESCE(p.project_name, '') AS project_name
        FROM project_position pos
        LEFT JOIN project_group g ON g.group_key = pos.group_key
        LEFT JOIN builder_project p ON p.project_key = pos.project_key
        ORDER BY p.project_code ASC, g.group_name ASC, pos.position_name ASC
    ")) : [],
    'branches' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll('SELECT branch_key, branch_code, branch_name, branch_status, branch_address, branch_contact FROM builder_branch ORDER BY branch_name ASC')) : [],
    'projects' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll('SELECT p.project_key, p.branch_key, p.project_code, p.project_name, p.project_status, p.project_description, b.branch_code, b.branch_name FROM builder_project p LEFT JOIN builder_branch b ON b.branch_key = p.branch_key ORDER BY b.branch_name ASC, p.project_name ASC')) : [],
    'forms' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            f.form_key,
            f.branch_key,
            f.project_key,
            f.form_code,
            f.form_name,
            f.form_description,
            f.form_table_name,
            f.form_schema_version,
            f.form_status,
            f.form_created_at,
            f.form_updated_at,
            b.branch_code,
            b.branch_name,
            p.project_code,
            p.project_name,
            (SELECT COUNT(*) FROM builder_form_version v WHERE v.form_key = f.form_key) AS version_count,
            (SELECT MAX(version_number) FROM builder_form_version v WHERE v.form_key = f.form_key) AS latest_version
        FROM builder_form f
        LEFT JOIN builder_branch b ON b.branch_key = f.branch_key
        LEFT JOIN builder_project p ON p.project_key = f.project_key
        ORDER BY b.branch_name ASC, p.project_name ASC, f.form_name ASC
    ")) : [],
    'formVersions' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT version_key, form_key, version_number, version_status, published_at, created_at
        FROM builder_form_version
        ORDER BY form_key ASC, version_number DESC
    ")) : [],
    'formFields' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            field_key,
            form_key,
            field_code,
            field_name,
            field_label,
            field_type,
            data_type,
            database_column_name,
            field_sort_order,
            field_status,
            is_required,
            is_unique,
            is_searchable,
            is_sortable,
            default_value,
            validation_rules,
            option_source,
            formula_expression,
            field_settings,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.visibility_rule')) AS visibility_rule,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.editable_rule')) AS editable_rule,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.role_permission')) AS role_permission,
            JSON_UNQUOTE(JSON_EXTRACT(field_settings, '$.grid_width')) AS grid_width
        FROM builder_form_field
        ORDER BY form_key ASC, field_sort_order ASC, x_id ASC
    ")) : [],
    'formLayouts' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            l.layout_key,
            l.form_key,
            l.version_key,
            l.layout_name,
            l.layout_type,
            l.layout_status,
            l.layout_schema,
            l.layout_sort_order,
            l.created_at,
            l.updated_at,
            f.form_code,
            f.form_name,
            v.version_number
        FROM builder_form_layout l
        LEFT JOIN builder_form f ON f.form_key = l.form_key
        LEFT JOIN builder_form_version v ON v.version_key = l.version_key
        ORDER BY f.form_code ASC, l.layout_sort_order ASC, l.x_id ASC
    ")) : [],
    'groups' => $isAdmin && bx_admin_group_projection_ready() ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            g.group_key,
            g.project_key,
            g.group_name,
            g.group_description,
            COALESCE(g.group_image_path, '') AS group_image_path,
            COALESCE(g.group_image_original_name, '') AS group_image_original_name,
            COALESCE(g.group_image_mime_type, '') AS group_image_mime_type,
            COALESCE(g.group_image_byte_size, 0) AS group_image_byte_size,
            COALESCE(g.group_image_sha256, '') AS group_image_sha256,
            COALESCE(DATE_FORMAT(g.group_image_uploaded_at, '%Y-%m-%d %H:%i:%s'), '') AS group_image_uploaded_at,
            g.group_status,
            COALESCE(p.project_code, '') AS project_code,
            COALESCE(p.project_name, '') AS project_name,
            {$groupMemberUserKeysSelect},
            COALESCE((SELECT GROUP_CONCAT(pos.position_name ORDER BY pos.position_name SEPARATOR ', ') FROM project_position pos WHERE pos.group_key = g.group_key AND pos.project_key = g.project_key AND pos.position_status <> 'DELETED'), '') AS position_names,
            (SELECT COUNT(*) FROM project_position pos WHERE pos.group_key = g.group_key AND pos.project_key = g.project_key AND pos.position_status <> 'DELETED') AS position_count
        FROM project_group g
        LEFT JOIN builder_project p ON p.project_key = g.project_key
        ORDER BY p.project_code ASC, g.group_name ASC
    ")) : [],
    'roles' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            r.role_key,
            r.role_name,
            r.role_description,
            r.role_status,
            COALESCE((SELECT GROUP_CONCAT(permission_key ORDER BY permission_key SEPARATOR ',') FROM builder_role_permission WHERE role_key = r.role_key), '') AS permission_keys,
            COALESCE((SELECT GROUP_CONCAT(p.permission_code ORDER BY p.permission_scope, p.permission_code SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_permission p ON p.permission_key = rp.permission_key WHERE rp.role_key = r.role_key), '') AS permission_codes,
            COALESCE((SELECT GROUP_CONCAT(DISTINCT p.permission_scope ORDER BY p.permission_scope SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_permission p ON p.permission_key = rp.permission_key WHERE rp.role_key = r.role_key), '') AS permission_scopes,
            (SELECT COUNT(*) FROM builder_role_permission rp WHERE rp.role_key = r.role_key) AS permission_count
        FROM builder_role r
        ORDER BY r.role_name ASC
    ")) : [],
    'permissions' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("
        SELECT
            p.permission_key,
            p.permission_code,
            p.permission_name,
            p.permission_scope,
            p.permission_status,
            COALESCE((SELECT GROUP_CONCAT(role_key ORDER BY role_key SEPARATOR ',') FROM builder_role_permission WHERE permission_key = p.permission_key), '') AS role_keys,
            COALESCE((SELECT GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ', ') FROM builder_role_permission rp JOIN builder_role r ON r.role_key = rp.role_key WHERE rp.permission_key = p.permission_key), '') AS role_names
        FROM builder_permission p
        ORDER BY p.permission_scope ASC, p.permission_code ASC
    ")) : [],
    'settings' => $settingsForPayload,
    'auditFilters' => $auditFilters,
    'audits' => $audits,
    'loginHistory' => $isAdmin ? bx_admin_payload_rows(bx_db()->GetAll("SELECT user_login_history_key AS login_key, user_key, user_login, user_action, user_action_status AS login_status, user_ip_address AS ip_address, user_device, user_action_reason AS failure_reason, user_action_at AS created_at FROM project_user_login_history ORDER BY user_action_at DESC LIMIT 80")) : [],
    'runtimeHealth' => $isAdmin ? bx_runtime_health_snapshot() : null,
    'traverseDocuments' => $isAdmin ? bx_traverse_document_registry_snapshot() : null,
];

if ($isAdmin && (string) ($_GET['format'] ?? '') === 'json') {
    bx_admin_json_response(['ok' => true, 'data' => $payload]);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bx_h($softwareName) ?> Administrator</title>
    <?php if ($entry && !empty($entry['css'])): ?>
        <?php foreach ($entry['css'] as $css): ?>
            <link rel="stylesheet" href="<?= bx_h($assetsBase . $css) ?>">
        <?php endforeach; ?>
    <?php endif; ?>
    <script>
        window.__BUILDERX_ADMIN__ = <?= json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
</head>
<body>
    <div id="root">
        <?php if (!$entry): ?>
            <main style="max-width: 760px; margin: 40px auto; font-family: Arial, Helvetica, sans-serif;">
                <h1><?= bx_h($softwareName) ?> Administrator</h1>
                <p>The shared React frontend is not built yet. Run <code>npm run build</code> in <code>frontend</code>.</p>
            </main>
        <?php endif; ?>
    </div>
    <?php if ($entry): ?>
        <script type="module" src="<?= bx_h($assetsBase . $entry['file']) ?>"></script>
    <?php endif; ?>
</body>
</html>
