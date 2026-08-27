<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/AI/PhaseAiSourceCheckpoint.php';

use BuilderX\AI\PhaseAiSourceCheckpoint;

$testRoot = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/builderx-source-checkpoint-test-' . bin2hex(random_bytes(8));
$removeTree = static function (string $path) use (&$removeTree, $testRoot): void {
    $normalized = rtrim(str_replace('\\', '/', $path), '/');
    if ($normalized === '' || !str_starts_with($normalized . '/', $testRoot . '/')) {
        throw new RuntimeException('The source checkpoint test cleanup target is invalid.');
    }
    if (!is_dir($normalized)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $removed = $item->isDir() && !$item->isLink()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
        if (!$removed) {
            throw new RuntimeException('The source checkpoint test cleanup failed.');
        }
    }
    if (!rmdir($normalized)) {
        throw new RuntimeException('The source checkpoint test root could not be removed.');
    }
};
$write = static function (string $relative, string $contents) use ($testRoot): void {
    $path = $testRoot . '/' . $relative;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('A source checkpoint fixture directory could not be created.');
    }
    if (file_put_contents($path, $contents) !== strlen($contents)) {
        throw new RuntimeException('A source checkpoint fixture could not be written.');
    }
};

if (!mkdir($testRoot, 0755, true)) {
    throw new RuntimeException('The source checkpoint test root could not be created.');
}

try {
    $write('app/example.php', "<?php\nreturn 'safe-source';\n");
    $write('frontend/src/example.ts', "export const safe = true\n");
    $write('android/app/src/main/java/Example.kt', "class Example\n");
    $write('.git/config', "unsafe git state\n");
    $write('.builderx/runtime/tasks/request.json', "{}\n");
    $write('storage/logs/runtime.log', "runtime state\n");
    $write('frontend/node_modules/example/index.js', "dependency\n");
    $write('frontend/dist/generated.js', "generated\n");
    $write('backend/database/generated/schema.json', "{}\n");
    $write('phases/config.local.php', "<?php return ['password' => 'fixture'];\n");
    $write('.env', "SECRET=fixture\n");
    $write('android/local.properties', "sdk.dir=fixture\n");
    $write('certificates/private.key', "fixture\n");

    $resolvedRoot = realpath($testRoot);
    if (!is_string($resolvedRoot)) {
        throw new RuntimeException('The source checkpoint test root could not be resolved.');
    }
    $resolvedRoot = rtrim(str_replace('\\', '/', $resolvedRoot), '/');
    $projectIdentity = hash('sha256', $resolvedRoot);
    $executionKey = '11111111-1111-4111-8111-111111111111';
    $runKey = '22222222-2222-4222-8222-222222222222';
    $checkpointManager = new PhaseAiSourceCheckpoint($resolvedRoot);
    $checkpoint = $checkpointManager->create($executionKey, $runKey, 'todo_execution', $projectIdentity);
    $verified = $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    $manifestPath = $resolvedRoot . '/' . $verified['manifest_path'];
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $manifestPaths = array_map(static fn(array $file): string => (string) ($file['path'] ?? ''), $manifest['files'] ?? []);
    $expectedSafe = ['android/app/src/main/java/Example.kt', 'app/example.php', 'frontend/src/example.ts'];
    sort($expectedSafe);
    sort($manifestPaths);
    if ($manifestPaths !== $expectedSafe) {
        throw new RuntimeException('The source checkpoint safe/excluded file policy did not match the fixture.');
    }
    $checkpointDirectory = dirname($manifestPath);
    $snapshotFile = $checkpointDirectory . '/snapshot/app/example.php';
    if (
        (fileperms($manifestPath) & 0777) !== 0666
        || (fileperms($checkpointDirectory) & 0777) !== 0777
        || (fileperms($snapshotFile) & 0777) !== 0666
        || ($verified['database_rollback_protected'] ?? true) !== false
    ) {
        throw new RuntimeException('The source checkpoint shared permissions or database boundary are invalid.');
    }
    $modelEvidence = PhaseAiSourceCheckpoint::modelEvidence($verified);
    if (
        ($modelEvidence['type'] ?? '') !== 'server_source'
        || ($modelEvidence['reference'] ?? '') !== ($verified['checkpoint_key'] ?? '')
        || ($modelEvidence['manifestSha256'] ?? '') !== ($verified['manifest_sha256'] ?? '')
        || ($modelEvidence['databaseRollbackProtected'] ?? true) !== false
    ) {
        throw new RuntimeException('The model-facing source checkpoint evidence is not bound to the verified manifest.');
    }

    $safeContents = (string) file_get_contents($snapshotFile);
    file_put_contents($snapshotFile, $safeContents . "tampered\n");
    chmod($snapshotFile, 0666);
    $tamperRejected = false;
    try {
        $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    } catch (RuntimeException $expected) {
        $tamperRejected = str_contains($expected->getMessage(), 'hash or permission verification');
    }
    if (!$tamperRejected) {
        throw new RuntimeException('A tampered shared source checkpoint file was accepted.');
    }
    file_put_contents($snapshotFile, $safeContents);
    chmod($snapshotFile, 0666);
    $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);

    chmod($checkpointDirectory, 0770);
    $incompatibleDirectoryRejected = false;
    try {
        $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    } catch (RuntimeException $expected) {
        $incompatibleDirectoryRejected = str_contains($expected->getMessage(), 'incompatible permissions');
    }
    chmod($checkpointDirectory, 0777);
    if (!$incompatibleDirectoryRejected) {
        throw new RuntimeException('A cross-process-incompatible source checkpoint directory was accepted.');
    }

    $realSnapshotFile = $snapshotFile . '.real';
    if (!rename($snapshotFile, $realSnapshotFile) || !symlink($realSnapshotFile, $snapshotFile)) {
        throw new RuntimeException('The source checkpoint symlink-substitution fixture could not be created.');
    }
    $symlinkSubstitutionRejected = false;
    try {
        $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    } catch (RuntimeException $expected) {
        $symlinkSubstitutionRejected = str_contains($expected->getMessage(), 'cannot be a symbolic link');
    }
    if (!unlink($snapshotFile) || !rename($realSnapshotFile, $snapshotFile) || !chmod($snapshotFile, 0666)) {
        throw new RuntimeException('The source checkpoint symlink-substitution fixture could not be restored.');
    }
    if (!$symlinkSubstitutionRejected) {
        throw new RuntimeException('A source checkpoint file symlink substitution was accepted.');
    }
    $checkpointManager->verify($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    $checkpointManager->discard($checkpoint, $executionKey, $runKey, 'todo_execution', $projectIdentity);
    if (is_dir($checkpointDirectory)) {
        throw new RuntimeException('The disposable shared source checkpoint was not removed.');
    }

    echo json_encode([
        'server_checkpoint_created_before_write' => true,
        'safe_source_files_copied' => count($expectedSafe),
        'runtime_dependencies_generated_and_secrets_excluded' => true,
        'cross_process_permissions_verified' => true,
        'manifest_hash_verified' => true,
        'snapshot_tamper_rejected' => true,
        'incompatible_permission_drift_rejected' => true,
        'symlink_substitution_rejected' => true,
        'database_rollback_protected' => false,
        'disposable_checkpoint_removed' => true,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    if (is_dir($testRoot)) {
        $removeTree($testRoot);
    }
}
