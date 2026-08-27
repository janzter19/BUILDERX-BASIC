<?php
declare(strict_types=1);

$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot) || !is_dir($projectRoot)) {
    throw new RuntimeException('The installed BuilderX project root is unavailable.');
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$forbiddenRoots = array_values(array_unique(array_filter([
    '/var/www/html/developer',
    trim((string) getenv('BUILDERX_FORBIDDEN_SOURCE_ROOT')),
], static fn (string $value): bool => $value !== '')));

foreach ($forbiddenRoots as $forbiddenRoot) {
    $normalized = rtrim(str_replace('\\', '/', $forbiddenRoot), '/');
    if ($projectRoot === $normalized || str_starts_with($projectRoot, $normalized . '/')) {
        throw new RuntimeException('The isolation test must run from a different installed-project directory.');
    }
}

$runtimeExtensions = ['php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'json', 'html', 'css', 'sh', 'service', 'ini'];
$runtimeNames = ['.htaccess', '.user.ini'];
$scanRoots = ['app', 'administrator', 'ai-bridge', 'backend', 'bin', 'deploy', 'frontend/dist', 'frontend/src', 'phases', 'scripts', 'sharingan.php', 'tools', 'index.php', 'forgot-password.php', 'reset-password.php'];
$scannedFiles = 0;
$symlinkCount = 0;

$inspectPath = static function (string $path) use ($projectRoot, $forbiddenRoots, $runtimeExtensions, $runtimeNames, &$scannedFiles, &$symlinkCount): void {
    if (is_link($path)) {
        $symlinkCount++;
        $target = readlink($path);
        if (!is_string($target) || $target === '' || str_starts_with($target, '/')) {
            throw new RuntimeException('Installed projects cannot contain an absolute or unreadable symlink: ' . substr($path, strlen($projectRoot) + 1));
        }
        $resolved = realpath(dirname($path) . '/' . $target);
        $normalized = is_string($resolved) ? str_replace('\\', '/', $resolved) : '';
        if ($normalized === '' || !str_starts_with($normalized, $projectRoot . '/')) {
            throw new RuntimeException('An installed-project symlink escapes the current project: ' . substr($path, strlen($projectRoot) + 1));
        }
        return;
    }
    if (!is_file($path)) return;
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, $runtimeExtensions, true) && !in_array(basename($path), $runtimeNames, true)) return;
    $contents = file_get_contents($path);
    if (!is_string($contents)) throw new RuntimeException('An installed runtime file could not be read.');
    $scannedFiles++;
    foreach ($forbiddenRoots as $forbiddenRoot) {
        if (str_contains($contents, rtrim(str_replace('\\', '/', $forbiddenRoot), '/'))) {
            throw new RuntimeException('An installed runtime file references the forbidden source checkout: ' . substr($path, strlen($projectRoot) + 1));
        }
    }
};

foreach ($scanRoots as $relativeRoot) {
    $absoluteRoot = $projectRoot . '/' . $relativeRoot;
    if (is_file($absoluteRoot) || is_link($absoluteRoot)) {
        $inspectPath($absoluteRoot);
        continue;
    }
    if (!is_dir($absoluteRoot)) continue;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) $inspectPath($item->getPathname());
}

$runtimeDirectories = [
    'backend/database/generated',
    'storage/ai-jobs',
    'storage/ai-memory',
    'storage/ai-operations',
    'storage/audit',
    'storage/backups',
    'storage/exports',
    'storage/imports',
    'storage/logs',
    'storage/phase-note-attachments',
    'storage/queue',
    'storage/reports',
    'storage/synchronization',
    'storage/uploads',
    '_Document/attachments/todo-chat',
    '_Document/attachments/sharingan',
];
$runtimeFiles = [];
foreach ($runtimeDirectories as $relativeDirectory) {
    $directory = $projectRoot . '/' . $relativeDirectory;
    if (!is_dir($directory)) continue;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item->isFile() && !in_array($item->getFilename(), ['.gitkeep', '.htaccess'], true)) {
            $runtimeFiles[] = substr(str_replace('\\', '/', $item->getPathname()), strlen($projectRoot) + 1);
        }
    }
}
if (getenv('BUILDERX_EXPECT_FRESH') === '1' && $runtimeFiles !== []) {
    throw new RuntimeException('A fresh installation contains generated runtime files: ' . implode(', ', array_slice($runtimeFiles, 0, 10)));
}

$freshPhaseBuilderDemoMarkers = [
    'phaseBuilderDraftDefaults',
    'dual-platform computer-parts inventory management system',
];
$freshPhaseBuilderFiles = [$projectRoot . '/frontend/src/App.tsx'];
$builtAssets = glob($projectRoot . '/frontend/dist/assets/*.js');
if (is_array($builtAssets)) {
    $freshPhaseBuilderFiles = array_merge($freshPhaseBuilderFiles, $builtAssets);
}
if (getenv('BUILDERX_EXPECT_FRESH') === '1') {
    foreach ($freshPhaseBuilderFiles as $freshPhaseBuilderFile) {
        if (!is_file($freshPhaseBuilderFile)) continue;
        $freshPhaseBuilderContents = file_get_contents($freshPhaseBuilderFile);
        if (!is_string($freshPhaseBuilderContents)) {
            throw new RuntimeException('A fresh-install Phase Builder asset could not be read.');
        }
        foreach ($freshPhaseBuilderDemoMarkers as $freshPhaseBuilderDemoMarker) {
            if (str_contains($freshPhaseBuilderContents, $freshPhaseBuilderDemoMarker)) {
                throw new RuntimeException('A fresh installation contains Phase Builder demo narrative content.');
            }
        }
    }
}

$stateTables = [
    'phase_builder_ai_context',
    'phase_builder_ai_job',
    'phase_builder_ai_run',
    'phase_builder_ai_run_stage',
    'phase_builder_ai_run_chunk',
    'phase_builder_ai_run_event',
    'phase_builder_todo_chat_messages',
    'phase_builder_todo_chat_attachments',
    'phase_builder_todo_chat_consolidations',
    'phase_builder_todo_execution_logs',
    'phase_builder_narrative_draft',
    'phase_builder_narrative_draft_backup',
    'phase_builder_requirements_analysis',
    'phase_builder_system_architecture',
    'phase_builder_ui_ux_design',
    'phase_builder_execution_roadmap',
];
$stateCounts = [];
if (getenv('BUILDERX_SKIP_DATABASE') !== '1') {
    require $projectRoot . '/app/foundation.php';
    $db = bx_db();
    foreach ($stateTables as $table) {
        $exists = (int) $db->GetOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [BUILDERX_DB_NAME, $table]);
        if ($exists !== 1) throw new RuntimeException('The installed database is missing required state table ' . $table . '.');
        $stateCounts[$table] = (int) $db->GetOne('SELECT COUNT(*) FROM `' . $table . '`');
        if (getenv('BUILDERX_EXPECT_FRESH') === '1' && $stateCounts[$table] !== 0) {
            throw new RuntimeException('A fresh installation inherited rows in ' . $table . '.');
        }
    }

    $imageBlobColumnCount = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'phase_builder_todo_chat_attachments', 'data_url']
    );
    if ($imageBlobColumnCount !== 0) {
        throw new RuntimeException('The installed database still contains the retired image blob column.');
    }

    $textColumns = $db->GetAll("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')", [BUILDERX_DB_NAME]);
    foreach (is_array($textColumns) ? $textColumns : [] as $column) {
        $table = (string) ($column['TABLE_NAME'] ?? $column['table_name'] ?? '');
        $name = (string) ($column['COLUMN_NAME'] ?? $column['column_name'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) continue;
        foreach ($forbiddenRoots as $forbiddenRoot) {
            $matches = (int) $db->GetOne('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $name . '` LIKE ?', ['%' . $forbiddenRoot . '%']);
            if ($matches > 0) throw new RuntimeException('The installed database contains a forbidden source-checkout path.');
        }
    }
}

echo json_encode([
    'project_root' => $projectRoot,
    'different_name_installation' => true,
    'runtime_files_scanned' => $scannedFiles,
    'symlinks_verified' => $symlinkCount,
    'generated_runtime_file_count' => count($runtimeFiles),
    'fresh_phase_builder_demo_marker_count' => 0,
    'fresh_state_counts' => $stateCounts,
    'database_image_blob_columns' => 0,
    'database_forbidden_path_scan' => getenv('BUILDERX_SKIP_DATABASE') !== '1',
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
