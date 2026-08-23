<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$app = (string) file_get_contents($root . '/frontend/src/App.tsx');
$extension = (string) file_get_contents($root . '/tools/builderx-bridge/extension/extension.js');

foreach ([
    'BuilderX companion is not ready',
    'No project command, service setup, or workspace path is required.',
    'The companion follows the active workspace automatically.',
    'There is no saved override or project-specific bridge path.',
    'Verify MySQL transport',
] as $marker) {
    if (!str_contains($app, $marker)) {
        throw new RuntimeException('The automatic BuilderX reconnect guidance is missing marker: ' . $marker);
    }
}

foreach ([
    'install-user-service.sh',
    'Save workspace',
    'workspaceOverride',
    'Copy fixer command',
    'Copy cd command',
] as $retiredMarker) {
    if (str_contains($app, $retiredMarker)) {
        throw new RuntimeException('The Phase Builder still contains retired manual bridge setup: ' . $retiredMarker);
    }
}

foreach (['currentWorkspace()', 'runHelper(root', "transport: 'mysql'", 'workspacePathsMatch', 'ensureDesktopGitRepository', 'ensureCodexHelperRules', 'codex_helper_rule_ready', 'tools/builderx-ai-job.php", "complete"', 'tools/builderx-ai-job.php", "fail"', "['init', '--template=', '-b', 'main']", "['config', '--global', '--add', 'safe.directory', safeRoot]", "['add', '--all']", "'Initialize BuilderX workspace'"] as $extensionMarker) {
    if (!str_contains($extension, $extensionMarker)) {
        throw new RuntimeException('The global companion is missing automatic workspace transport: ' . $extensionMarker);
    }
}
if (preg_match('/\brbmsv[0-9]+\b/i', $app . $extension) === 1) {
    throw new RuntimeException('The automatic bridge flow must not hardcode an installed project name.');
}

echo json_encode([
    'automatic_workspace' => true,
    'manual_path_required' => false,
    'project_service_required' => false,
    'mysql_transport' => true,
    'hardcoded_project_paths' => false,
    'desktop_owned_git_automatic' => true,
    'desktop_git_safe_directory_automatic' => true,
    'desktop_git_initial_commit_automatic' => true,
    'codex_helper_rules_automatic' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
