<?php
declare(strict_types=1);

namespace BuilderX\AI;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class PhaseAiSourceCheckpoint
{
    public const SCHEMA_VERSION = 'builderx.server-source-checkpoint.v1';
    public const POLICY_VERSION = 'builderx.safe-source-snapshot.v1';

    private const MAX_FILE_BYTES = 8_388_608;
    private const MAX_TOTAL_BYTES = 268_435_456;
    private const MAX_FILE_COUNT = 20_000;
    private const SHARED_DIRECTORY_MODE = 0777;
    private const SHARED_FILE_MODE = 0666;

    /** @var list<string> */
    private const EXCLUDED_DIRECTORY_SEGMENTS = [
        '.git',
        '.builderx',
        '.codex',
        '.agents',
        '.user-context',
        '.gradle',
        '.idea',
        'node_modules',
        'vendor',
        'storage',
        'generated',
        'dist',
        'build',
        'coverage',
        'cache',
        'caches',
        'logs',
        'tmp',
        'temp',
        'uploads',
    ];

    /** @var list<string> */
    private const SENSITIVE_FILE_NAMES = [
        'config.local.php',
        'local.properties',
        'google-services.json',
        'googleservice-info.plist',
        'credentials.json',
        'secrets.json',
        'id_rsa',
        'id_ed25519',
    ];

    /** @var list<string> */
    private const SENSITIVE_EXTENSIONS = [
        'key',
        'pem',
        'p12',
        'pfx',
        'jks',
        'keystore',
        'der',
        'sqlite',
        'sqlite3',
        'db',
        'sql',
        'dump',
        'log',
    ];

    private readonly string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException('The current BuilderX project root is unavailable for source recovery.');
        }
        $this->projectRoot = rtrim(str_replace('\\', '/', $resolved), '/');
    }

    /** @return array<string, mixed> */
    public function create(
        string $executionKey,
        string $runKey,
        string $workflowKey,
        string $projectIdentity
    ): array {
        $executionKey = trim($executionKey);
        $runKey = trim($runKey);
        $workflowKey = strtolower(trim($workflowKey));
        $projectIdentity = strtolower(trim($projectIdentity));
        $this->assertBinding($executionKey, $runKey, $workflowKey, $projectIdentity);

        $backupRoot = $this->ensureSharedDirectory($this->projectRoot . '/storage/backups');
        $checkpointRoot = $this->ensureSharedDirectory($backupRoot . '/phase-ai-source');
        $executionRoot = $this->ensureSharedDirectory($checkpointRoot . '/' . $executionKey);
        $checkpointKey = self::uuid();
        $finalDirectory = $executionRoot . '/' . $checkpointKey;
        $temporaryDirectory = $executionRoot . '/.' . $checkpointKey . '.tmp-' . bin2hex(random_bytes(6));
        if (!mkdir($temporaryDirectory, self::SHARED_DIRECTORY_MODE) || !chmod($temporaryDirectory, self::SHARED_DIRECTORY_MODE)) {
            throw new RuntimeException('The shared source checkpoint staging directory could not be created.');
        }

        try {
            $snapshotRoot = $temporaryDirectory . '/snapshot';
            if (!mkdir($snapshotRoot, self::SHARED_DIRECTORY_MODE) || !chmod($snapshotRoot, self::SHARED_DIRECTORY_MODE)) {
                throw new RuntimeException('The shared source snapshot directory could not be created.');
            }

            $files = [];
            $excludedCounts = [];
            $totalBytes = 0;
            $sourceIterator = new RecursiveDirectoryIterator($this->projectRoot, FilesystemIterator::SKIP_DOTS);
            $filteredIterator = new RecursiveCallbackFilterIterator(
                $sourceIterator,
                function (SplFileInfo $item) use (&$excludedCounts): bool {
                    $relative = $this->relativePath($item->getPathname());
                    if ($item->isLink()) {
                        throw new RuntimeException('The source checkpoint rejected a symbolic link: ' . $relative);
                    }
                    $reason = $this->exclusionReason($relative, $item->isDir());
                    if ($reason !== null) {
                        $excludedCounts[$reason] = ($excludedCounts[$reason] ?? 0) + 1;
                        return false;
                    }
                    return true;
                }
            );
            $iterator = new RecursiveIteratorIterator($filteredIterator, RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile()) {
                    continue;
                }
                $relative = $this->relativePath($item->getPathname());
                $reason = $this->exclusionReason($relative, false);
                if ($reason !== null) {
                    $excludedCounts[$reason] = ($excludedCounts[$reason] ?? 0) + 1;
                    continue;
                }
                $bytes = $item->getSize();
                if ($bytes < 0 || $bytes > self::MAX_FILE_BYTES) {
                    $excludedCounts['oversized_file'] = ($excludedCounts['oversized_file'] ?? 0) + 1;
                    continue;
                }
                if (count($files) >= self::MAX_FILE_COUNT || $totalBytes + $bytes > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('The bounded source checkpoint exceeded its safe file or byte limit.');
                }
                $sourcePath = str_replace('\\', '/', $item->getPathname());
                $sourceHashBefore = hash_file('sha256', $sourcePath);
                if (!is_string($sourceHashBefore)) {
                    throw new RuntimeException('A source file could not be hashed for recovery: ' . $relative);
                }
                $targetPath = $snapshotRoot . '/' . $relative;
                $targetDirectory = dirname($targetPath);
                $this->ensureSharedDirectory($targetDirectory);
                if (!copy($sourcePath, $targetPath) || !chmod($targetPath, self::SHARED_FILE_MODE)) {
                    throw new RuntimeException('A source file could not be copied into the shared checkpoint: ' . $relative);
                }
                $sourceHashAfter = hash_file('sha256', $sourcePath);
                $snapshotHash = hash_file('sha256', $targetPath);
                if (
                    !is_string($sourceHashAfter)
                    || !is_string($snapshotHash)
                    || !hash_equals($sourceHashBefore, $sourceHashAfter)
                    || !hash_equals($sourceHashBefore, $snapshotHash)
                    || filesize($targetPath) !== $bytes
                ) {
                    throw new RuntimeException('A source file changed while its recovery checkpoint was being created: ' . $relative);
                }
                $files[] = ['path' => $relative, 'bytes' => $bytes, 'sha256' => $snapshotHash];
                $totalBytes += $bytes;
            }

            usort($files, static fn(array $left, array $right): int => strcmp((string) $left['path'], (string) $right['path']));
            ksort($excludedCounts);
            if ($files === []) {
                throw new RuntimeException('The safe project source checkpoint did not contain any recoverable files.');
            }
            $createdAt = gmdate('Y-m-d\TH:i:s\Z');
            $manifest = [
                'schema_version' => self::SCHEMA_VERSION,
                'policy_version' => self::POLICY_VERSION,
                'checkpoint_key' => $checkpointKey,
                'execution_key' => $executionKey,
                'run_key' => $runKey,
                'workflow_key' => $workflowKey,
                'project_identity' => $projectIdentity,
                'scope' => 'project_source_files',
                'created_before_write' => true,
                'created_at' => $createdAt,
                'database_rollback_protected' => false,
                'file_count' => count($files),
                'total_bytes' => $totalBytes,
                'excluded_counts' => $excludedCounts,
                'files' => $files,
            ];
            $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $manifestPath = $temporaryDirectory . '/manifest.json';
            $manifestTemporary = $temporaryDirectory . '/.manifest-' . bin2hex(random_bytes(6));
            if (
                file_put_contents($manifestTemporary, $manifestJson, LOCK_EX) !== strlen($manifestJson)
                || !chmod($manifestTemporary, self::SHARED_FILE_MODE)
                || !rename($manifestTemporary, $manifestPath)
                || !chmod($manifestPath, self::SHARED_FILE_MODE)
            ) {
                throw new RuntimeException('The shared source checkpoint manifest could not be published.');
            }
            $manifestHash = hash_file('sha256', $manifestPath);
            if (!is_string($manifestHash) || !rename($temporaryDirectory, $finalDirectory)) {
                throw new RuntimeException('The shared source checkpoint could not be activated atomically.');
            }
            chmod($finalDirectory, self::SHARED_DIRECTORY_MODE);

            $metadata = [
                'schema_version' => self::SCHEMA_VERSION,
                'policy_version' => self::POLICY_VERSION,
                'checkpoint_key' => $checkpointKey,
                'execution_key' => $executionKey,
                'run_key' => $runKey,
                'workflow_key' => $workflowKey,
                'project_identity' => $projectIdentity,
                'scope' => 'project_source_files',
                'created_before_write' => true,
                'created_at' => $createdAt,
                'manifest_path' => $this->relativePath($finalDirectory . '/manifest.json'),
                'manifest_sha256' => $manifestHash,
                'file_count' => count($files),
                'total_bytes' => $totalBytes,
                'database_rollback_protected' => false,
            ];
            return $this->verify($metadata, $executionKey, $runKey, $workflowKey, $projectIdentity);
        } catch (Throwable $error) {
            if (is_dir($temporaryDirectory)) {
                $this->removeTree($temporaryDirectory);
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public function verify(
        array $metadata,
        string $executionKey,
        string $runKey,
        string $workflowKey,
        string $projectIdentity
    ): array {
        $executionKey = trim($executionKey);
        $runKey = trim($runKey);
        $workflowKey = strtolower(trim($workflowKey));
        $projectIdentity = strtolower(trim($projectIdentity));
        $this->assertBinding($executionKey, $runKey, $workflowKey, $projectIdentity);
        foreach (['checkpoint_key', 'manifest_path', 'manifest_sha256', 'created_at'] as $key) {
            if (!is_string($metadata[$key] ?? null) || trim((string) $metadata[$key]) === '') {
                throw new RuntimeException('The server source checkpoint metadata is incomplete.');
            }
        }
        $checkpointKey = trim((string) $metadata['checkpoint_key']);
        if (!self::validRecordKey($checkpointKey)) {
            throw new RuntimeException('The server source checkpoint key is invalid.');
        }
        if (
            ($metadata['schema_version'] ?? '') !== self::SCHEMA_VERSION
            || ($metadata['policy_version'] ?? '') !== self::POLICY_VERSION
            || ($metadata['execution_key'] ?? '') !== $executionKey
            || ($metadata['run_key'] ?? '') !== $runKey
            || ($metadata['workflow_key'] ?? '') !== $workflowKey
            || ($metadata['project_identity'] ?? '') !== $projectIdentity
            || ($metadata['scope'] ?? '') !== 'project_source_files'
            || ($metadata['created_before_write'] ?? false) !== true
            || ($metadata['database_rollback_protected'] ?? true) !== false
            || preg_match('/^[a-f0-9]{64}$/', (string) $metadata['manifest_sha256']) !== 1
        ) {
            throw new RuntimeException('The server source checkpoint is not bound to this Coding Engine run.');
        }

        $expectedRelativePath = 'storage/backups/phase-ai-source/' . $executionKey . '/' . $checkpointKey . '/manifest.json';
        $relativeManifestPath = trim(str_replace('\\', '/', (string) $metadata['manifest_path']), '/');
        if ($relativeManifestPath !== $expectedRelativePath) {
            throw new RuntimeException('The server source checkpoint manifest path is invalid.');
        }
        $manifestCandidate = $this->projectRoot . '/' . $relativeManifestPath;
        $privateRootCandidate = $this->projectRoot . '/storage/backups/phase-ai-source';
        $executionDirectoryCandidate = $privateRootCandidate . '/' . $executionKey;
        $checkpointDirectoryCandidate = $executionDirectoryCandidate . '/' . $checkpointKey;
        if (
            is_link($manifestCandidate)
            || is_link($privateRootCandidate)
            || is_link($executionDirectoryCandidate)
            || is_link($checkpointDirectoryCandidate)
        ) {
            throw new RuntimeException('The shared source checkpoint contains a symbolic-link substitution.');
        }
        $manifestPath = realpath($manifestCandidate);
        $privateRoot = realpath($privateRootCandidate);
        $executionDirectory = realpath($executionDirectoryCandidate);
        $checkpointDirectory = realpath($checkpointDirectoryCandidate);
        $normalizedManifestPath = is_string($manifestPath) ? str_replace('\\', '/', $manifestPath) : '';
        $normalizedPrivateRoot = is_string($privateRoot) ? rtrim(str_replace('\\', '/', $privateRoot), '/') : '';
        $normalizedExecutionDirectory = is_string($executionDirectory) ? rtrim(str_replace('\\', '/', $executionDirectory), '/') : '';
        $normalizedCheckpointDirectory = is_string($checkpointDirectory) ? rtrim(str_replace('\\', '/', $checkpointDirectory), '/') : '';
        if (
            $normalizedManifestPath === ''
            || $normalizedPrivateRoot === ''
            || $normalizedExecutionDirectory !== str_replace('\\', '/', $executionDirectoryCandidate)
            || $normalizedCheckpointDirectory !== str_replace('\\', '/', $checkpointDirectoryCandidate)
            || !str_starts_with($normalizedManifestPath, $normalizedPrivateRoot . '/')
            || (fileperms($privateRootCandidate) & 0777) !== self::SHARED_DIRECTORY_MODE
            || (fileperms($executionDirectoryCandidate) & 0777) !== self::SHARED_DIRECTORY_MODE
            || (fileperms($checkpointDirectoryCandidate) & 0777) !== self::SHARED_DIRECTORY_MODE
            || (fileperms($normalizedManifestPath) & 0777) !== self::SHARED_FILE_MODE
        ) {
            throw new RuntimeException('The shared source checkpoint manifest is unavailable or has incompatible permissions.');
        }
        $actualManifestHash = hash_file('sha256', $normalizedManifestPath);
        if (!is_string($actualManifestHash) || !hash_equals((string) $metadata['manifest_sha256'], $actualManifestHash)) {
            throw new RuntimeException('The shared source checkpoint manifest hash does not match.');
        }
        $manifestJson = file_get_contents($normalizedManifestPath);
        $manifest = is_string($manifestJson) ? json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new RuntimeException('The shared source checkpoint manifest is invalid.');
        }
        foreach (['schema_version', 'policy_version', 'checkpoint_key', 'execution_key', 'run_key', 'workflow_key', 'project_identity', 'scope', 'created_before_write', 'created_at', 'database_rollback_protected', 'file_count', 'total_bytes'] as $key) {
            if (($manifest[$key] ?? null) !== ($metadata[$key] ?? null)) {
                throw new RuntimeException('The shared source checkpoint metadata does not match its manifest.');
            }
        }
        $files = $manifest['files'] ?? null;
        if (!is_array($files) || !array_is_list($files) || count($files) !== (int) ($manifest['file_count'] ?? -1) || count($files) < 1) {
            throw new RuntimeException('The shared source checkpoint file manifest is incomplete.');
        }
        $snapshotRoot = dirname($normalizedManifestPath) . '/snapshot';
        if (is_link($snapshotRoot)) {
            throw new RuntimeException('The shared source checkpoint snapshot cannot be a symbolic link.');
        }
        $resolvedSnapshotRoot = realpath($snapshotRoot);
        if (!is_string($resolvedSnapshotRoot) || (fileperms($snapshotRoot) & 0777) !== self::SHARED_DIRECTORY_MODE) {
            throw new RuntimeException('The shared source checkpoint snapshot is unavailable or has incompatible permissions.');
        }
        $resolvedSnapshotRoot = rtrim(str_replace('\\', '/', $resolvedSnapshotRoot), '/');
        $seen = [];
        $totalBytes = 0;
        foreach ($files as $file) {
            if (!is_array($file) || array_is_list($file)) {
                throw new RuntimeException('The shared source checkpoint contains an invalid file record.');
            }
            $relative = trim(str_replace('\\', '/', (string) ($file['path'] ?? '')), '/');
            $hash = strtolower(trim((string) ($file['sha256'] ?? '')));
            $bytes = (int) ($file['bytes'] ?? -1);
            if (
                !$this->validRelativePath($relative)
                || $this->exclusionReason($relative, false) !== null
                || isset($seen[$relative])
                || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                || $bytes < 0
                || $bytes > self::MAX_FILE_BYTES
            ) {
                throw new RuntimeException('The shared source checkpoint contains an unsafe file record.');
            }
            $snapshotCandidate = $resolvedSnapshotRoot . '/' . $relative;
            if (is_link($snapshotCandidate)) {
                throw new RuntimeException('A shared source checkpoint file cannot be a symbolic link: ' . $relative);
            }
            $snapshotPath = realpath($snapshotCandidate);
            $normalizedSnapshotPath = is_string($snapshotPath) ? str_replace('\\', '/', $snapshotPath) : '';
            $actualHash = is_string($snapshotPath) ? hash_file('sha256', $snapshotPath) : false;
            if (
                $normalizedSnapshotPath === ''
                || !str_starts_with($normalizedSnapshotPath, $resolvedSnapshotRoot . '/')
                || !is_file($normalizedSnapshotPath)
                || (fileperms($normalizedSnapshotPath) & 0777) !== self::SHARED_FILE_MODE
                || filesize($normalizedSnapshotPath) !== $bytes
                || !is_string($actualHash)
                || !hash_equals($hash, $actualHash)
            ) {
                throw new RuntimeException('A shared source checkpoint file failed hash or permission verification: ' . $relative);
            }
            $seen[$relative] = true;
            $totalBytes += $bytes;
        }
        if ($totalBytes !== (int) ($manifest['total_bytes'] ?? -1)) {
            throw new RuntimeException('The shared source checkpoint byte total does not match its manifest.');
        }
        $actualFileCount = 0;
        $snapshotIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolvedSnapshotRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($snapshotIterator as $snapshotItem) {
            if ($snapshotItem->isLink() || (!$snapshotItem->isDir() && !$snapshotItem->isFile())) {
                throw new RuntimeException('The shared source checkpoint contains an unsafe filesystem entry.');
            }
            if ($snapshotItem->isDir() && (fileperms($snapshotItem->getPathname()) & 0777) !== self::SHARED_DIRECTORY_MODE) {
                throw new RuntimeException('The shared source checkpoint contains an incompatible directory mode.');
            }
            if ($snapshotItem->isFile()) {
                $actualFileCount++;
            }
        }
        if ($actualFileCount !== count($files)) {
            throw new RuntimeException('The shared source checkpoint contains unmanifested files.');
        }
        return $metadata;
    }

    /** @param array<string, mixed> $metadata @return array<string, mixed> */
    public static function modelEvidence(array $metadata): array
    {
        return [
            'type' => 'server_source',
            'reference' => (string) ($metadata['checkpoint_key'] ?? ''),
            'scope' => (string) ($metadata['scope'] ?? ''),
            'manifestSha256' => (string) ($metadata['manifest_sha256'] ?? ''),
            'createdBeforeWrite' => ($metadata['created_before_write'] ?? false) === true,
            'databaseRollbackProtected' => false,
        ];
    }

    /** @param array<string, mixed> $metadata */
    public function discard(
        array $metadata,
        string $executionKey,
        string $runKey,
        string $workflowKey,
        string $projectIdentity
    ): void {
        $verified = $this->verify($metadata, $executionKey, $runKey, $workflowKey, $projectIdentity);
        $manifestPath = realpath($this->projectRoot . '/' . (string) $verified['manifest_path']);
        $checkpointDirectory = is_string($manifestPath) ? str_replace('\\', '/', dirname($manifestPath)) : '';
        $expectedDirectory = $this->projectRoot . '/storage/backups/phase-ai-source/' . $executionKey . '/' . (string) $verified['checkpoint_key'];
        if ($checkpointDirectory === '' || !hash_equals($expectedDirectory, $checkpointDirectory)) {
            throw new RuntimeException('The disposable source checkpoint cleanup binding is invalid.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($checkpointDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $removed = $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
            if (!$removed) {
                throw new RuntimeException('The disposable source checkpoint could not be removed.');
            }
        }
        if (!rmdir($checkpointDirectory)) {
            throw new RuntimeException('The disposable source checkpoint directory could not be removed.');
        }
        $executionDirectory = dirname($checkpointDirectory);
        if (is_dir($executionDirectory) && self::directoryIsEmpty($executionDirectory)) {
            rmdir($executionDirectory);
        }
    }

    private function assertBinding(string $executionKey, string $runKey, string $workflowKey, string $projectIdentity): void
    {
        if (
            !self::validRecordKey($executionKey)
            || !self::validRecordKey($runKey)
            || !in_array($workflowKey, ['todo_execution', 'todo_rollback'], true)
            || preg_match('/^[a-f0-9]{64}$/', $projectIdentity) !== 1
            || !hash_equals(hash('sha256', $this->projectRoot), $projectIdentity)
        ) {
            throw new InvalidArgumentException('The Coding Engine source checkpoint binding is invalid.');
        }
    }

    private function ensureSharedDirectory(string $path): string
    {
        if (is_link($path)) {
            throw new RuntimeException('The shared source checkpoint path cannot be a symbolic link.');
        }
        if (!is_dir($path) && !mkdir($path, self::SHARED_DIRECTORY_MODE, true) && !is_dir($path)) {
            throw new RuntimeException('The shared source checkpoint directory could not be created.');
        }
        $mode = fileperms($path) & 0777;
        if ($mode !== self::SHARED_DIRECTORY_MODE
            && !chmod($path, self::SHARED_DIRECTORY_MODE)) {
            throw new RuntimeException('The shared source checkpoint directory could not enable cross-process writes.');
        }
        clearstatcache(true, $path);
        if ((fileperms($path) & 0777) !== self::SHARED_DIRECTORY_MODE) {
            throw new RuntimeException('The shared source checkpoint directory could not verify cross-process writes.');
        }
        if (!is_writable($path)) {
            throw new RuntimeException('The shared source checkpoint directory is not writable.');
        }
        $resolved = realpath($path);
        $normalized = is_string($resolved) ? rtrim(str_replace('\\', '/', $resolved), '/') : '';
        if ($normalized === '' || !str_starts_with($normalized, $this->projectRoot . '/storage/backups')) {
            throw new RuntimeException('The shared source checkpoint directory escaped the current project.');
        }
        return $normalized;
    }

    private function exclusionReason(string $relative, bool $isDirectory): ?string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        if (!$this->validRelativePath($relative)) {
            return 'unsafe_path';
        }
        $segments = explode('/', strtolower($relative));
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDED_DIRECTORY_SEGMENTS, true)) {
                return 'runtime_dependency_or_generated_directory';
            }
        }
        if ($isDirectory) {
            return null;
        }
        $basename = strtolower(basename($relative));
        if (
            $basename === '.env'
            || str_starts_with($basename, '.env.')
            || in_array($basename, self::SENSITIVE_FILE_NAMES, true)
            || preg_match('/(^|[-_.])(secret|credential|credentials|private[-_]?key)([-_.]|$)/i', $basename) === 1
        ) {
            return 'local_configuration_or_secret';
        }
        $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
        if (in_array($extension, self::SENSITIVE_EXTENSIONS, true)) {
            return 'local_configuration_or_secret';
        }
        if (preg_match('/(?:\.tmp|\.temp|\.bak|\.backup|\.orig|\.rej|\.swp|~)$/i', $basename) === 1) {
            return 'temporary_or_backup_file';
        }
        return null;
    }

    private function relativePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        if (!str_starts_with($normalized, $this->projectRoot . '/')) {
            throw new RuntimeException('A source checkpoint path escaped the current project.');
        }
        return substr($normalized, strlen($this->projectRoot) + 1);
    }

    private function validRelativePath(string $relative): bool
    {
        return $relative !== ''
            && !str_starts_with($relative, '/')
            && !str_contains($relative, "\0")
            && preg_match('#(^|/)\.\.(/|$)#', $relative) !== 1;
    }

    private function removeTree(string $path): void
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $expectedRoot = $this->projectRoot . '/storage/backups/phase-ai-source/';
        if (!str_starts_with($normalized . '/', $expectedRoot) || !str_contains(basename($normalized), '.tmp-')) {
            throw new RuntimeException('The source checkpoint cleanup target is invalid.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($normalized, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('A temporary source checkpoint directory could not be removed.');
                }
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('A temporary source checkpoint file could not be removed.');
            }
        }
        if (!rmdir($normalized)) {
            throw new RuntimeException('The temporary source checkpoint root could not be removed.');
        }
    }

    private static function validRecordKey(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,36}$/', $value) === 1;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private static function directoryIsEmpty(string $path): bool
    {
        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        return !$iterator->valid();
    }
}
