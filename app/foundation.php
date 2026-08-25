<?php
declare(strict_types=1);

if ((!defined('BUILDERX_SKIP_SESSION_START') || BUILDERX_SKIP_SESSION_START !== true)
    && session_status() !== PHP_SESSION_ACTIVE) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/../phases/config.php';
require_once __DIR__ . '/../adodb/adodb.inc.php';
require_once __DIR__ . '/AI/AiTaskStore.php';
require_once __DIR__ . '/AI/CommunicationMessageStore.php';
require_once __DIR__ . '/AI/AiTaskResultReconciler.php';
require_once __DIR__ . '/AI/PhaseBuilderNarrativeCleanupStore.php';
require_once __DIR__ . '/AI/PhaseAiRunStore.php';
require_once __DIR__ . '/AI/PhaseBuilderPlanningPolicy.php';
require_once __DIR__ . '/AI/PhaseAiDatabaseTransport.php';
require_once __DIR__ . '/AI/BuilderXAiBridgeAdapter.php';
require_once __DIR__ . '/AI/RequirementsAnalysisWorkflow.php';
require_once __DIR__ . '/AI/SharinganSurfaceWorkflow.php';
require_once __DIR__ . '/AI/PhaseAiSourceCheckpoint.php';
require_once __DIR__ . '/AI/PhaseAiWorkflowContract.php';
require_once __DIR__ . '/AI/PhaseAiOrchestrator.php';
require_once __DIR__ . '/AI/AiSpecialistRegistry.php';
require_once __DIR__ . '/AI/ApprovalStore.php';
require_once __DIR__ . '/AI/MemoryStore.php';
require_once __DIR__ . '/AI/CoordinatorRouter.php';

builderxDefineDatabaseConstants();
if (!builderxIsConfigured()) {
    builderxRenderMissingConfigPage();
}

define('BUILDERX_DB_DRIVER', DB_DRIVER);
define('BUILDERX_DB_HOST', builderxDatabaseHost());
define('BUILDERX_DB_USER', DB_USER);
define('BUILDERX_DB_PASS', DB_PASS);
define('BUILDERX_DB_NAME', DB_NAME);

function bx_load_project_env(string $path): void
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name) || getenv($name) !== false) {
            continue;
        }

        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

bx_load_project_env(dirname(__DIR__) . '/.env');

function bx_db(): ADOConnection
{
    static $db = null;
    if ($db instanceof ADOConnection) {
        return $db;
    }

    $db = ADONewConnection(BUILDERX_DB_DRIVER);
    $db->Connect(BUILDERX_DB_HOST, BUILDERX_DB_USER, BUILDERX_DB_PASS, BUILDERX_DB_NAME);
    $db->SetFetchMode(ADODB_FETCH_ASSOC);
    $db->Execute("SET NAMES 'utf8mb4'");
    $db->debug = false;

    return $db;
}

function bx_run_bridge_database_test(): array
{
    $db = bx_db();
    $table = 'phase_builder_codex_bridge_test';
    $transactionStarted = false;
    $assertExecute = static function ($result, string $operation) use ($db): void {
        if ($result !== false) {
            return;
        }

        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException($operation . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    };

    try {
        $db->BeginTrans();
        $transactionStarted = true;

        $assertExecute($db->Execute(
            'CREATE TABLE IF NOT EXISTS phase_builder_codex_bridge_test (
                test_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                test_code VARCHAR(40) NOT NULL,
                test_label VARCHAR(120) NOT NULL,
                test_value VARCHAR(120) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ), 'Bridge test table creation');

        $insertedIds = [];
        $expectedRows = [];
        for ($index = 1; $index <= 10; $index++) {
            $code = sprintf('TEST-%02d', $index);
            $label = sprintf('Bridge Test %02d', $index);
            $value = sprintf('Value %02d', $index);
            $assertExecute($db->Execute(
                'INSERT INTO phase_builder_codex_bridge_test (test_code, test_label, test_value) VALUES (?, ?, ?)',
                [$code, $label, $value]
            ), 'Bridge test row insertion');

            $testId = (int) $db->Insert_ID();
            if ($testId < 1) {
                throw new RuntimeException('Bridge test row insertion did not return a valid test_id.');
            }
            $insertedIds[] = $testId;
            $expectedRows[$testId] = [
                'test_code' => $code,
                'test_label' => $label,
                'test_value' => $value,
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($insertedIds), '?'));
        $insertedRows = $db->GetAll(
            "SELECT test_id, test_code, test_label, test_value, created_at FROM {$table} WHERE test_id IN ({$placeholders}) ORDER BY test_id",
            $insertedIds
        );
        if (!is_array($insertedRows)) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Bridge test read-back failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        if (count($insertedRows) !== 10) {
            throw new RuntimeException('Bridge test read-back returned ' . count($insertedRows) . ' rows; expected 10.');
        }

        foreach ($insertedRows as $row) {
            $testId = (int) ($row['test_id'] ?? 0);
            $expected = $expectedRows[$testId] ?? null;
            if ($expected === null
                || (string) ($row['test_code'] ?? '') !== $expected['test_code']
                || (string) ($row['test_label'] ?? '') !== $expected['test_label']
                || (string) ($row['test_value'] ?? '') !== $expected['test_value']
                || trim((string) ($row['created_at'] ?? '')) === '') {
                throw new RuntimeException('Bridge test read-back validation failed for test_id ' . $testId . '.');
            }
        }

        $structure = $db->GetAll(
            'SELECT COLUMN_NAME AS column_name, COLUMN_TYPE AS column_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, COLUMN_KEY AS column_key, EXTRA AS extra FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [BUILDERX_DB_NAME, $table]
        );
        if (!is_array($structure)) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Bridge test table structure read failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $committed = $db->CommitTrans();
        $transactionStarted = false;
        if ($committed === false) {
            throw new RuntimeException('Bridge test transaction commit failed.');
        }

        return [
            'table' => $table,
            'structure' => $structure,
            'inserted_rows' => $insertedRows,
            'count' => count($insertedRows),
            'transaction' => 'committed',
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function bx_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function bx_firebase_document_id(int $length = 20): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $alphabetMax = strlen($alphabet) - 1;
    $value = '';
    for ($index = 0; $index < $length; $index++) {
        $value .= $alphabet[random_int(0, $alphabetMax)];
    }

    return $value;
}

function bx_unique_firebase_document_key(string $table, string $column, int $length = 20): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        throw new InvalidArgumentException('Invalid Firebase document key target.');
    }

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = bx_firebase_document_id($length);
        $exists = (int) bx_db()->GetOne("SELECT COUNT(*) FROM {$table} WHERE {$column} = ?", [$candidate]);
        if ($exists === 0) {
            return $candidate;
        }
    }

    throw new RuntimeException('Firebase document key generation failed.');
}

function bx_messenger_sender_key(?array $user): string
{
    if (is_array($user) && trim((string) ($user['user_key'] ?? '')) !== '') {
        return (string) $user['user_key'];
    }

    $sessionKey = preg_replace('/[^A-Za-z0-9]/', '', session_id()) ?: '';
    return 'portal_' . substr($sessionKey !== '' ? $sessionKey : sha1((string) microtime(true)), 0, 32);
}

function bx_messenger_sender_name(?array $user, string $projectKey = ''): string
{
    $name = trim((string) ($user['user_chat_name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $userKey = is_array($user) ? trim((string) ($user['user_key'] ?? '')) : '';
    $projectKey = trim($projectKey);
    if ($userKey !== '' && $projectKey !== '') {
        $projectName = bx_db()->GetOne(
            "SELECT COALESCE(NULLIF(pu.user_chat_name, ''), NULLIF(pu.user_name, ''), pu.user_login)
            FROM project_user pu
            LEFT JOIN builder_user bu
                ON bu.user_key = ?
            LEFT JOIN project_user_group pug
                ON pug.project_key = pu.project_key
                AND pug.group_key = pu.group_key
                AND pug.group_status = 'ACTIVE'
            LEFT JOIN builder_user_group bug
                ON bug.user_key = ?
            LEFT JOIN builder_group bg
                ON bg.group_key = bug.group_key
                AND bg.group_status = 'ACTIVE'
            WHERE pu.project_key = ?
                AND pu.user_status = 'ACTIVE'
                AND (
                    pu.user_key = ?
                    OR (bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu.user_login = bu.user_login)
                    OR (bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu.user_email = bu.user_email)
                    OR (bg.group_name IS NOT NULL AND bg.group_name <> '' AND pug.group_name = bg.group_name)
                )
            ORDER BY
                CASE
                    WHEN pu.user_key = ? THEN 0
                    WHEN bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu.user_login = bu.user_login THEN 1
                    WHEN bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu.user_email = bu.user_email THEN 2
                    WHEN bg.group_name IS NOT NULL AND bg.group_name <> '' AND pug.group_name = bg.group_name THEN 3
                    ELSE 9
                END,
                pu.x_id ASC
            LIMIT 1",
            [$userKey, $userKey, $projectKey, $userKey, $userKey]
        );
        $projectName = trim((string) ($projectName ?: ''));
        if ($projectName !== '') {
            return $projectName;
        }
    }

    $name = trim((string) ($user['user_name'] ?? ''));
    return $name !== '' ? $name : 'Portal User';
}

function bx_messenger_allowed_reactions(): array
{
    return ['👍', '❤️', '😂', '😮', '😢', '🙏'];
}

function bx_ensure_project_messenger_schema(): void
{
    $db = bx_db();

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_messenger_chat (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            chat_key VARCHAR(40) NOT NULL UNIQUE,
            project_key VARCHAR(80) NOT NULL,
            group_key VARCHAR(40) NOT NULL,
            conversation_type ENUM('group','direct') NOT NULL DEFAULT 'group',
            direct_recipient_user_key VARCHAR(80) NULL,
            reply_to_chat_key VARCHAR(40) NULL,
            sender_user_key VARCHAR(80) NOT NULL,
            sender_name VARCHAR(160) NOT NULL,
            message_text TEXT NULL,
            message_type ENUM('text','image','mixed') NOT NULL DEFAULT 'text',
            message_status ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
            removed_at TIMESTAMP NULL,
            removed_by_user_key VARCHAR(80) NULL,
            firebase_collection VARCHAR(80) NOT NULL DEFAULT 'project_messenger_chat',
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_messenger_chat_group (group_key, x_id),
            INDEX idx_project_messenger_chat_direct (group_key, conversation_type, direct_recipient_user_key, sender_user_key, x_id),
            INDEX idx_project_messenger_chat_project (project_key, group_key),
            INDEX idx_project_messenger_chat_sender (sender_user_key),
            INDEX idx_project_messenger_chat_reply (reply_to_chat_key),
            INDEX idx_project_messenger_chat_firebase (firebase_collection, firebase_sync_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        throw new RuntimeException('Messenger chat schema setup failed: ' . trim((string) $db->ErrorMsg()));
    }
    bx_add_column_if_missing('project_messenger_chat', 'conversation_type', "ENUM('group','direct') NOT NULL DEFAULT 'group' AFTER group_key");
    bx_add_column_if_missing('project_messenger_chat', 'direct_recipient_user_key', 'VARCHAR(80) NULL AFTER conversation_type');
    bx_add_index_if_missing('project_messenger_chat', 'idx_project_messenger_chat_direct', 'INDEX idx_project_messenger_chat_direct (group_key, conversation_type, direct_recipient_user_key, sender_user_key, x_id)');

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_messenger_chat_attachment (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_key VARCHAR(40) NOT NULL UNIQUE,
            chat_key VARCHAR(40) NOT NULL,
            project_key VARCHAR(80) NOT NULL,
            group_key VARCHAR(40) NOT NULL,
            uploaded_image_url VARCHAR(500) NOT NULL,
            image_original_name VARCHAR(255) NULL,
            image_mime_type VARCHAR(100) NULL,
            image_byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            image_sha256 VARCHAR(128) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            attachment_status ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
            firebase_collection VARCHAR(80) NOT NULL DEFAULT 'project_messenger_chat_attachment',
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_messenger_attachment_chat (chat_key, sort_order),
            INDEX idx_project_messenger_attachment_group (group_key, chat_key),
            INDEX idx_project_messenger_attachment_firebase (firebase_collection, firebase_sync_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        throw new RuntimeException('Messenger attachment schema setup failed: ' . trim((string) $db->ErrorMsg()));
    }

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_messenger_chat_read (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            read_key VARCHAR(40) NOT NULL UNIQUE,
            chat_key VARCHAR(40) NOT NULL,
            project_key VARCHAR(80) NOT NULL,
            group_key VARCHAR(40) NOT NULL,
            user_key VARCHAR(80) NOT NULL,
            read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            firebase_collection VARCHAR(80) NOT NULL DEFAULT 'project_messenger_chat_read',
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL,
            UNIQUE KEY uq_project_messenger_read_user (chat_key, user_key),
            INDEX idx_project_messenger_read_group (group_key, user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        throw new RuntimeException('Messenger read schema setup failed: ' . trim((string) $db->ErrorMsg()));
    }

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_messenger_chat_reaction (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reaction_key VARCHAR(40) NOT NULL UNIQUE,
            chat_key VARCHAR(40) NOT NULL,
            project_key VARCHAR(80) NOT NULL,
            group_key VARCHAR(40) NOT NULL,
            user_key VARCHAR(80) NOT NULL,
            reaction_value VARCHAR(40) NOT NULL,
            reaction_status ENUM('ACTIVE','REMOVED') NOT NULL DEFAULT 'ACTIVE',
            firebase_collection VARCHAR(80) NOT NULL DEFAULT 'project_messenger_chat_reaction',
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_messenger_reaction_user (chat_key, user_key, reaction_value),
            INDEX idx_project_messenger_reaction_group (group_key, chat_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        throw new RuntimeException('Messenger reaction schema setup failed: ' . trim((string) $db->ErrorMsg()));
    }
}

function bx_messenger_firebase_sync_enabled(): bool
{
    $setting = strtolower(trim((string) bx_setting('firebase_messenger_server_sync_enabled', '0')));
    $env = strtolower(trim((string) getenv('FIREBASE_MESSENGER_SERVER_SYNC_ENABLED')));
    return in_array($setting, ['1', 'true', 'yes', 'enabled'], true)
        || in_array($env, ['1', 'true', 'yes', 'enabled'], true);
}

function bx_messenger_firebase_project_id(): string
{
    $setting = trim((string) bx_setting('firebase_project_id', ''));
    if ($setting !== '') {
        return $setting;
    }
    $env = getenv('FIREBASE_PROJECT_ID');
    return is_string($env) && trim($env) !== '' ? trim($env) : 'rbmsv4-vrp';
}

function bx_messenger_firebase_service_account_path(): string
{
    $setting = trim((string) bx_setting('firebase_service_account_path', ''));
    if ($setting !== '') {
        return $setting;
    }
    foreach (['GOOGLE_APPLICATION_CREDENTIALS', 'FIREBASE_SERVICE_ACCOUNT_PATH'] as $envName) {
        $env = getenv($envName);
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
    }
    return '';
}

function bx_messenger_stream_service_status(): array
{
    $unitName = 'rbmsv4-firebase-messenger-stream.service';
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    $configured = bx_messenger_firebase_sync_enabled()
        && bx_messenger_firebase_project_id() !== ''
        && $serviceAccountPath !== ''
        && is_readable($serviceAccountPath);
    $unitInstalled = is_file('/etc/systemd/system/' . $unitName)
        || is_file('/lib/systemd/system/' . $unitName)
        || is_file('/usr/lib/systemd/system/' . $unitName);

    $runCommand = static function (string $command): array {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
        if (!is_resource($process)) {
            return ['exit_code' => 127, 'output' => '', 'error' => 'command_unavailable'];
        }

        $output = trim(stream_get_contents($pipes[1]) ?: '');
        $error = trim(stream_get_contents($pipes[2]) ?: '');
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => (int) $exitCode, 'output' => $output, 'error' => $error];
    };

    $systemctl = $runCommand('command -v systemctl');
    if (($systemctl['exit_code'] ?? 1) === 0) {
        $active = $runCommand('systemctl is-active ' . escapeshellarg($unitName) . ' 2>/dev/null');
        if (($active['exit_code'] ?? 1) === 0 && trim((string) ($active['output'] ?? '')) === 'active') {
            return [
                'running' => true,
                'status' => 'running',
                'label' => 'Firebase stream running',
                'detail' => 'The Firebase-to-MySQL stream service is active.',
                'service' => $unitName,
                'configured' => $configured,
                'unit_installed' => $unitInstalled,
                'checked_at' => date('Y-m-d H:i:s'),
            ];
        }

        $statusText = trim((string) ($active['output'] ?? ''));
        if ($statusText === '') {
            $statusText = $unitInstalled ? 'inactive' : 'not installed';
        }

        $process = $runCommand("pgrep -f 'firebase-messenger-stream\\.mjs' 2>/dev/null | head -1");
        $processRunning = ($process['exit_code'] ?? 1) === 0 && trim((string) ($process['output'] ?? '')) !== '';
        if ($processRunning) {
            return [
                'running' => true,
                'status' => 'running',
                'label' => 'Firebase stream running',
                'detail' => $unitInstalled
                    ? 'A Firebase-to-MySQL stream process is running.'
                    : 'A Firebase-to-MySQL stream process is running outside systemd.',
                'service' => $unitName,
                'configured' => $configured,
                'unit_installed' => $unitInstalled,
                'checked_at' => date('Y-m-d H:i:s'),
            ];
        }

        return [
            'running' => false,
            'status' => $unitInstalled ? $statusText : 'not_installed',
            'label' => $unitInstalled ? 'Firebase stream stopped' : 'Firebase stream not installed',
            'detail' => $configured
                ? ($unitInstalled ? 'The Firebase-to-MySQL stream service is not active.' : 'Install and start the systemd service to receive Firebase-origin messages.')
                : 'Firebase server sync is not fully configured.',
            'service' => $unitName,
            'configured' => $configured,
            'unit_installed' => $unitInstalled,
            'checked_at' => date('Y-m-d H:i:s'),
        ];
    }

    $process = $runCommand("pgrep -f 'firebase-messenger-stream\\.mjs' 2>/dev/null | head -1");
    $running = ($process['exit_code'] ?? 1) === 0 && trim((string) ($process['output'] ?? '')) !== '';

    return [
        'running' => $running,
        'status' => $running ? 'running' : 'stopped',
        'label' => $running ? 'Firebase stream running' : 'Firebase stream stopped',
        'detail' => $running
            ? 'A Firebase-to-MySQL stream process is running.'
            : ($configured ? 'No Firebase-to-MySQL stream process was detected.' : 'Firebase server sync is not fully configured.'),
        'service' => $unitName,
        'configured' => $configured,
        'unit_installed' => $unitInstalled,
        'checked_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * @return array<string, mixed>
 */
function bx_messenger_sync_message_to_firebase(array $message): array
{
    bx_ensure_project_messenger_schema();
    if (!bx_messenger_firebase_sync_enabled()) {
        return ['ok' => false, 'skipped' => true, 'message' => 'Firebase server sync is disabled.'];
    }

    $projectId = bx_messenger_firebase_project_id();
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    if ($serviceAccountPath === '' || !is_readable($serviceAccountPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase service account path is not configured or readable.'];
    }

    $scriptPath = dirname(__DIR__) . '/scripts/firebase-messenger-sync.mjs';
    if (!is_readable($scriptPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase Messenger sync script is missing.'];
    }

    $payload = json_encode([
        'project_id' => $projectId,
        'service_account_path' => $serviceAccountPath,
        'message' => $message,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase sync payload could not be encoded.'];
    }

    $command = 'node ' . escapeshellarg($scriptPath);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase sync process could not start.'];
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if (!is_array($result)) {
        $result = [
            'ok' => false,
            'message' => trim((string) $stderr) !== '' ? trim((string) $stderr) : 'Firebase sync returned an invalid response.',
        ];
    }

    $chatKey = (string) ($message['chat_key'] ?? '');
    if ($chatKey !== '') {
        if ($exitCode === 0 && ($result['ok'] ?? false) === true) {
            bx_db()->Execute(
                "UPDATE project_messenger_chat SET firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP WHERE chat_key = ?",
                [$chatKey]
            );
            bx_db()->Execute(
                "UPDATE project_messenger_chat_attachment SET firebase_sync_status = 'SYNCED', firebase_synced_at = CURRENT_TIMESTAMP WHERE chat_key = ? AND attachment_status = 'ACTIVE'",
                [$chatKey]
            );
        } else {
            bx_db()->Execute(
                "UPDATE project_messenger_chat SET firebase_sync_status = 'FAILED' WHERE chat_key = ?",
                [$chatKey]
            );
            bx_db()->Execute(
                "UPDATE project_messenger_chat_attachment SET firebase_sync_status = 'FAILED' WHERE chat_key = ? AND attachment_status = 'ACTIVE'",
                [$chatKey]
            );
        }
    }

    return $result + [
        'exit_code' => $exitCode,
        'stderr' => trim((string) $stderr) !== '' ? '[REDACTED]' : '',
    ];
}

function bx_sync_project_bed_reference_rows_to_firebase(string $type, array $rows): array
{
    $type = strtolower(trim($type));
    if (!in_array($type, ['treatment', 'source'], true)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Invalid bed reference type.'];
    }

    $rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
    if ($rows === []) {
        return ['ok' => false, 'skipped' => false, 'message' => 'No bed reference row is available for Firebase sync.'];
    }

    $projectId = bx_messenger_firebase_project_id();
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    if ($projectId === '') {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase project id is not configured.'];
    }
    if ($serviceAccountPath === '' || !is_readable($serviceAccountPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase service account path is not configured or readable.'];
    }

    $scriptPath = dirname(__DIR__) . '/scripts/firebase-bed-reference-sync.mjs';
    if (!is_readable($scriptPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase bed reference sync script is missing.'];
    }

    $payload = json_encode([
        'project_id' => $projectId,
        'service_account_path' => $serviceAccountPath,
        'reference_type' => $type,
        'rows' => $rows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase bed reference payload could not be encoded.'];
    }

    $command = 'node ' . escapeshellarg($scriptPath);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase bed reference sync process could not start.'];
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if (!is_array($result)) {
        $result = [
            'ok' => false,
            'message' => trim((string) $stderr) !== '' ? 'Firebase bed reference sync failed.' : 'Firebase bed reference sync returned an invalid response.',
        ];
    }

    return $result + [
        'exit_code' => $exitCode,
        'stderr' => trim((string) $stderr) !== '' ? '[REDACTED]' : '',
    ];
}

function bx_sync_project_bed_reference_to_firebase(string $type, array $row): array
{
    return bx_sync_project_bed_reference_rows_to_firebase($type, [$row]);
}

function bx_project_bed_firebase_rows(?string $batchKey = null, ?string $bedKey = null, int $limit = 10000): array
{
    bx_ensure_bed_master_list_schema();
    $where = [];
    $params = [];

    if ($batchKey !== null && trim($batchKey) !== '') {
        $batchKey = trim($batchKey);
        if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $batchKey)) {
            throw new RuntimeException('Invalid project bed sync batch key.');
        }
        $where[] = 'sync_batch_key = ?';
        $params[] = $batchKey;
    }

    if ($bedKey !== null && trim($bedKey) !== '') {
        $bedKey = trim($bedKey);
        if (!preg_match('/^[A-Za-z0-9]{20}$/', $bedKey)) {
            throw new RuntimeException('Invalid project bed key.');
        }
        $where[] = 'bed_key = ?';
        $params[] = $bedKey;
    }

    $limit = max(1, min(10000, $limit));
    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = bx_db()->GetAll(
        "SELECT
            bed_key,
            bed_source_key,
            source_table,
            source_id,
            COALESCE(source_pk_psbeds, '') AS source_pk_psbeds,
            COALESCE(bed_no, '') AS bed_no,
            COALESCE(branch_key, '') AS branch_key,
            COALESCE(branch_name, '') AS branch_name,
            COALESCE(building_key, '') AS building_key,
            COALESCE(building_name, '') AS building_name,
            COALESCE(floor_key, '') AS floor_key,
            COALESCE(floor_name, '') AS floor_name,
            COALESCE(nurse_station_key, '') AS nurse_station_key,
            COALESCE(nurse_station_name, '') AS nurse_station_name,
            COALESCE(room_key, '') AS room_key,
            COALESCE(room_class_key, '') AS room_class_key,
            COALESCE(room_class, '') AS room_class,
            COALESCE(source_bed_status_key, '') AS source_bed_status_key,
            COALESCE(source_bed_status, '') AS source_bed_status,
            managed_status,
            COALESCE(sync_batch_key, '') AS sync_batch_key,
            DATE_FORMAT(first_synced_at, '%Y-%m-%d %H:%i:%s') AS first_synced_at,
            DATE_FORMAT(last_synced_at, '%Y-%m-%d %H:%i:%s') AS last_synced_at,
            DATE_FORMAT(last_seen_at, '%Y-%m-%d %H:%i:%s') AS last_seen_at,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_bed
        {$whereSql}
        ORDER BY managed_status ASC, bed_no ASC, x_id ASC
        LIMIT {$limit}",
        $params
    );

    return is_array($rows) ? $rows : [];
}

function bx_project_bed_floor_group_key(array $row): string
{
    $parts = [
        (string) ($row['branch_key'] ?? ''),
        (string) ($row['branch_name'] ?? ''),
        (string) ($row['building_key'] ?? ''),
        (string) ($row['building_name'] ?? ''),
        (string) ($row['floor_key'] ?? ''),
        (string) ($row['floor_name'] ?? ''),
    ];

    return substr(sha1(implode('|', array_map(static fn (string $value): string => strtolower(trim($value)), $parts))), 0, 20);
}

function bx_project_bed_floor_documents(?string $batchKey = null, ?array $floorGroupKeys = null, array $seedRows = []): array
{
    bx_ensure_bed_master_list_schema();
    $batchKey = $batchKey !== null ? trim($batchKey) : null;
    if ($batchKey !== null && $batchKey !== '' && !preg_match('/^[A-Fa-f0-9-]{36}$/', $batchKey)) {
        throw new RuntimeException('Invalid project bed floor batch key.');
    }

    $where = [];
    $params = [];
    if ($batchKey !== null && $batchKey !== '') {
        $where[] = 'sync_batch_key = ?';
        $params[] = $batchKey;
    }
    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = bx_db()->GetAll(
        "SELECT
            bed_key,
            COALESCE(bed_no, '') AS bed_no,
            COALESCE(branch_key, '') AS branch_key,
            COALESCE(branch_name, '') AS branch_name,
            COALESCE(building_key, '') AS building_key,
            COALESCE(building_name, '') AS building_name,
            COALESCE(floor_key, '') AS floor_key,
            COALESCE(floor_name, '') AS floor_name,
            COALESCE(nurse_station_key, '') AS nurse_station_key,
            COALESCE(nurse_station_name, '') AS nurse_station_name,
            COALESCE(room_key, '') AS room_key,
            COALESCE(room_class_key, '') AS room_class_key,
            COALESCE(room_class, '') AS room_class,
            COALESCE(source_bed_status_key, '') AS source_bed_status_key,
            COALESCE(source_bed_status, '') AS source_bed_status,
            managed_status,
            COALESCE(sync_batch_key, '') AS sync_batch_key,
            DATE_FORMAT(last_synced_at, '%Y-%m-%d %H:%i:%s') AS last_synced_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_bed
        {$whereSql}
        ORDER BY COALESCE(branch_name, '') ASC, COALESCE(building_name, '') ASC, COALESCE(floor_name, '') ASC, COALESCE(bed_no, '') ASC, x_id ASC",
        $params
    ) ?: [];

    $requestedKeys = $floorGroupKeys === null ? null : array_values(array_unique(array_filter(array_map(
        static fn ($value): string => trim((string) $value),
        $floorGroupKeys
    ), static fn (string $value): bool => preg_match('/^[A-Za-z0-9]{20,40}$/', $value) === 1)));

    $groups = [];
    foreach ($rows as $row) {
        $key = bx_project_bed_floor_group_key($row);
        if ($requestedKeys !== null && !in_array($key, $requestedKeys, true)) {
            continue;
        }
        $groups[$key][] = $row;
    }

    $seedByKey = [];
    foreach ($seedRows as $seedRow) {
        if (!is_array($seedRow)) {
            continue;
        }
        $seedByKey[bx_project_bed_floor_group_key($seedRow)] = $seedRow;
    }

    $documentKeys = $requestedKeys ?? array_keys($groups);
    sort($documentKeys);
    $documents = [];
    foreach ($documentKeys as $floorGroupKey) {
        $groupRows = $groups[$floorGroupKey] ?? [];
        $baseRow = $groupRows[0] ?? ($seedByKey[$floorGroupKey] ?? []);
        $summary = [
            'total' => count($groupRows),
            'active' => 0,
            'inactive' => 0,
            'available' => 0,
            'vacant' => 0,
            'occupied' => 0,
        ];
        $classCounts = [];
        $statusCounts = [];
        $beds = [];
        foreach ($groupRows as $bedRow) {
            $recordStatus = strtoupper((string) ($bedRow['managed_status'] ?? 'ACTIVE'));
            $bedStatus = (string) ($bedRow['source_bed_status'] ?? '');
            $classLabel = trim((string) ($bedRow['room_class'] ?? '')) !== '' ? trim((string) $bedRow['room_class']) : 'Unspecified';
            $statusLabel = trim($bedStatus) !== '' ? trim($bedStatus) : 'Unspecified';
            $summary[$recordStatus === 'ACTIVE' ? 'active' : 'inactive']++;
            if ($bedStatus === 'Available') {
                $summary['available']++;
            }
            if (in_array($bedStatus, ['Available', 'Vacant'], true)) {
                $summary['vacant']++;
            }
            if ($bedStatus === 'Occupied') {
                $summary['occupied']++;
            }
            $classCounts[$classLabel] = ($classCounts[$classLabel] ?? 0) + 1;
            $statusCounts[$statusLabel] = ($statusCounts[$statusLabel] ?? 0) + 1;
            $beds[] = [
                'bed_key' => (string) ($bedRow['bed_key'] ?? ''),
                'bed_no' => (string) ($bedRow['bed_no'] ?? ''),
                'nurse_station_key' => (string) ($bedRow['nurse_station_key'] ?? ''),
                'nurse_station_name' => (string) ($bedRow['nurse_station_name'] ?? ''),
                'room_key' => (string) ($bedRow['room_key'] ?? ''),
                'room_class_key' => (string) ($bedRow['room_class_key'] ?? ''),
                'room_class' => (string) ($bedRow['room_class'] ?? ''),
                'source_bed_status_key' => (string) ($bedRow['source_bed_status_key'] ?? ''),
                'source_bed_status' => $bedStatus,
                'managed_status' => $recordStatus,
            ];
        }
        $classRows = [];
        foreach ($classCounts as $label => $total) {
            $classRows[] = ['label' => $label, 'total' => (int) $total];
        }
        $statusRows = [];
        foreach ($statusCounts as $label => $total) {
            $statusRows[] = ['label' => $label, 'total' => (int) $total];
        }

        $documents[] = [
            'floor_group_key' => $floorGroupKey,
            'branch_key' => (string) ($baseRow['branch_key'] ?? ''),
            'branch_name' => (string) ($baseRow['branch_name'] ?? ''),
            'building_key' => (string) ($baseRow['building_key'] ?? ''),
            'building_name' => (string) ($baseRow['building_name'] ?? ''),
            'floor_key' => (string) ($baseRow['floor_key'] ?? ''),
            'floor_name' => (string) ($baseRow['floor_name'] ?? ''),
            'summary' => $summary,
            'class_rows' => $classRows,
            'status_rows' => $statusRows,
            'beds' => $beds,
            'bed_count' => count($beds),
            'floor_group_status' => $beds === [] ? 'INACTIVE' : 'ACTIVE',
            'sync_batch_key' => (string) ($batchKey ?? ($baseRow['sync_batch_key'] ?? '')),
            'last_synced_at' => (string) ($baseRow['last_synced_at'] ?? ''),
            'updated_at' => (string) ($baseRow['updated_at'] ?? ''),
        ];
    }

    return $documents;
}

function bx_sync_project_bed_rows_to_firebase(array $rows, ?array $analyticsRows = null, ?array $floorRows = null, bool $replaceFloorRows = false): array
{
    $rows = array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
    if ($rows === []) {
        return ['ok' => false, 'skipped' => false, 'message' => 'No project bed row is available for Firebase sync.'];
    }
    $analyticsRows = $analyticsRows === null ? null : array_values(array_filter($analyticsRows, static fn ($row): bool => is_array($row)));
    $floorRows = $floorRows === null ? null : array_values(array_filter($floorRows, static fn ($row): bool => is_array($row)));

    $projectId = bx_messenger_firebase_project_id();
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    if ($projectId === '') {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase project id is not configured.'];
    }
    if ($serviceAccountPath === '' || !is_readable($serviceAccountPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase service account path is not configured or readable.'];
    }

    $scriptPath = dirname(__DIR__) . '/scripts/firebase-bed-sync.mjs';
    if (!is_readable($scriptPath)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase project bed sync script is missing.'];
    }

    $payload = json_encode([
        'project_id' => $projectId,
        'service_account_path' => $serviceAccountPath,
        'rows' => $rows,
        'analytics_rows' => $analyticsRows,
        'floor_rows' => $floorRows,
        'floor_replace_all' => $replaceFloorRows,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase project bed payload could not be encoded.'];
    }

    $command = 'node ' . escapeshellarg($scriptPath);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['ok' => false, 'skipped' => false, 'message' => 'Firebase project bed sync process could not start.'];
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if (!is_array($result)) {
        $result = [
            'ok' => false,
            'message' => trim((string) $stderr) !== '' ? 'Firebase project bed sync failed.' : 'Firebase project bed sync returned an invalid response.',
        ];
    }

    return $result + [
        'exit_code' => $exitCode,
        'stderr' => trim((string) $stderr) !== '' ? '[REDACTED]' : '',
    ];
}

function bx_sync_project_bed_to_firebase(string $bedKey): array
{
    $bedRows = bx_project_bed_firebase_rows(null, $bedKey, 1);
    $floorKeys = array_values(array_unique(array_map(static fn (array $row): string => bx_project_bed_floor_group_key($row), $bedRows)));

    return bx_sync_project_bed_rows_to_firebase(
        $bedRows,
        bx_project_bed_analytics_documents(),
        bx_project_bed_floor_documents(null, $floorKeys, $bedRows),
        false
    );
}

function bx_messenger_group_row(string $groupKey): ?array
{
    bx_ensure_project_messenger_schema();
    if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $groupKey)) {
        return null;
    }

    $row = bx_db()->GetRow(
        "SELECT group_key, project_key, group_name FROM project_user_group WHERE group_key = ? AND group_status = 'ACTIVE' LIMIT 1",
        [$groupKey]
    );

    return is_array($row) ? $row : null;
}

function bx_messenger_group_users(string $groupKey): array
{
    bx_ensure_project_messenger_schema();
    $group = bx_messenger_group_row($groupKey);
    if ($group === null) {
        return [];
    }

    $rows = bx_db()->GetAll(
        "SELECT
            user_key,
            project_key,
            group_key,
            user_login,
            user_name,
            COALESCE(user_chat_name, '') AS user_chat_name,
            COALESCE(user_avatar_path, '') AS user_avatar_path,
            COALESCE(user_avatar_original_name, '') AS user_avatar_original_name,
            COALESCE(user_avatar_mime_type, '') AS user_avatar_mime_type,
            COALESCE(user_avatar_byte_size, 0) AS user_avatar_byte_size,
            COALESCE(user_avatar_sha256, '') AS user_avatar_sha256,
            user_status
        FROM project_user
        WHERE project_key = ?
            AND group_key = ?
            AND user_status = 'ACTIVE'
        ORDER BY user_name ASC, user_chat_name ASC, user_login ASC",
        [(string) ($group['project_key'] ?? ''), $groupKey]
    ) ?: [];

    return array_map(static fn (array $row): array => [
        'user_key' => (string) ($row['user_key'] ?? ''),
        'project_key' => (string) ($row['project_key'] ?? ''),
        'group_key' => (string) ($row['group_key'] ?? ''),
        'user_login' => (string) ($row['user_login'] ?? ''),
        'user_name' => (string) ($row['user_name'] ?? ''),
        'user_chat_name' => (string) ($row['user_chat_name'] ?? ''),
        'user_avatar_path' => (string) ($row['user_avatar_path'] ?? ''),
        'user_avatar_original_name' => (string) ($row['user_avatar_original_name'] ?? ''),
        'user_avatar_mime_type' => (string) ($row['user_avatar_mime_type'] ?? ''),
        'user_avatar_byte_size' => (int) ($row['user_avatar_byte_size'] ?? 0),
        'user_avatar_sha256' => (string) ($row['user_avatar_sha256'] ?? ''),
        'user_status' => (string) ($row['user_status'] ?? 'ACTIVE'),
    ], $rows);
}

function bx_messenger_direct_member_row(string $groupKey, string $userKey): ?array
{
    $userKey = trim($userKey);
    if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/', $userKey)) {
        return null;
    }

    foreach (bx_messenger_group_users($groupKey) as $member) {
        if ((string) ($member['user_key'] ?? '') === $userKey) {
            return $member;
        }
    }

    return null;
}

function bx_messenger_sender_keys(?array $user, string $projectKey = ''): array
{
    $senderKey = bx_messenger_sender_key($user);
    $keys = [$senderKey];
    $projectKey = trim($projectKey);

    if ($senderKey !== '' && $projectKey !== '') {
        $rows = bx_db()->GetAll(
            "SELECT DISTINCT pu.user_key
            FROM project_user pu
            LEFT JOIN builder_user bu
                ON bu.user_key = ?
            LEFT JOIN project_user_group pug
                ON pug.project_key = pu.project_key
                AND pug.group_key = pu.group_key
                AND pug.group_status = 'ACTIVE'
            LEFT JOIN builder_user_group bug
                ON bug.user_key = ?
            LEFT JOIN builder_group bg
                ON bg.group_key = bug.group_key
                AND bg.group_status = 'ACTIVE'
            WHERE pu.project_key = ?
                AND pu.user_status = 'ACTIVE'
                AND (
                    pu.user_key = ?
                    OR (bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu.user_login = bu.user_login)
                    OR (bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu.user_email = bu.user_email)
                    OR (bg.group_name IS NOT NULL AND bg.group_name <> '' AND pug.group_name = bg.group_name)
                )",
            [$senderKey, $senderKey, $projectKey, $senderKey]
        ) ?: [];

        foreach ($rows as $row) {
            $projectUserKey = trim((string) ($row['user_key'] ?? ''));
            if ($projectUserKey !== '') {
                $keys[] = $projectUserKey;
            }
        }
    }

    return array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
}

function bx_messenger_message_public(array $row, array $attachments = [], ?array $reply = null, ?string $viewerBaseUrl = null, array $reactions = []): array
{
    $status = (string) ($row['message_status'] ?? 'ACTIVE');
    $removed = $status === 'REMOVED';

    return [
        'chat_key' => (string) ($row['chat_key'] ?? ''),
        'project_key' => (string) ($row['project_key'] ?? ''),
        'group_key' => (string) ($row['group_key'] ?? ''),
        'conversation_type' => (string) ($row['conversation_type'] ?? 'group'),
        'direct_recipient_user_key' => (string) ($row['direct_recipient_user_key'] ?? ''),
        'reply_to_chat_key' => (string) ($row['reply_to_chat_key'] ?? ''),
        'sender_user_key' => (string) ($row['sender_user_key'] ?? ''),
        'sender_name' => (string) ($row['sender_display_name'] ?? $row['sender_name'] ?? ''),
        'message_text' => $removed ? '' : (string) ($row['message_text'] ?? ''),
        'message_type' => (string) ($row['message_type'] ?? 'text'),
        'message_status' => $status,
        'removed_at' => (string) ($row['removed_at'] ?? ''),
        'firebase_collection' => (string) ($row['firebase_collection'] ?? 'project_messenger_chat'),
        'firebase_sync_status' => (string) ($row['firebase_sync_status'] ?? 'PENDING'),
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
        'attachments' => $removed ? [] : array_values($attachments),
        'reply' => $reply,
        'reactions' => $removed ? [] : array_values($reactions),
        'viewer_base_url' => $viewerBaseUrl ?? bx_setting('media_image_viewer_url', 'http://localhost/rbms.com/view.php'),
    ];
}

function bx_messenger_message_select_sql(string $whereSql): string
{
    return "SELECT
            c.chat_key,
            c.project_key,
            c.group_key,
            c.conversation_type,
            c.direct_recipient_user_key,
            c.reply_to_chat_key,
            c.sender_user_key,
            c.sender_name,
            COALESCE(
                NULLIF(pu_direct.user_chat_name, ''),
                NULLIF(pu_builder.user_chat_name, ''),
                NULLIF(pu_direct.user_name, ''),
                NULLIF(pu_builder.user_name, ''),
                NULLIF(c.sender_name, ''),
                NULLIF(pu_single.single_user_display_name, ''),
                NULLIF(pu_project_single.single_user_display_name, ''),
                c.sender_name
            ) AS sender_display_name,
            c.message_text,
            c.message_type,
            c.message_status,
            DATE_FORMAT(c.removed_at, '%Y-%m-%d %H:%i:%s') AS removed_at,
            c.firebase_collection,
            c.firebase_sync_status,
            DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(c.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_messenger_chat c
        LEFT JOIN project_user pu_direct
            ON pu_direct.project_key = c.project_key
            AND pu_direct.group_key = c.group_key
            AND pu_direct.user_key = c.sender_user_key
            AND pu_direct.user_status = 'ACTIVE'
        LEFT JOIN builder_user bu
            ON bu.user_key = c.sender_user_key
        LEFT JOIN project_user pu_builder
            ON pu_builder.project_key = c.project_key
            AND pu_builder.group_key = c.group_key
            AND pu_builder.user_status = 'ACTIVE'
            AND (
                (bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu_builder.user_login = bu.user_login)
                OR (bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu_builder.user_email = bu.user_email)
            )
        LEFT JOIN (
            SELECT
                project_key,
                group_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key, group_key
        ) pu_single
            ON pu_single.project_key = c.project_key
            AND pu_single.group_key = c.group_key
        LEFT JOIN (
            SELECT
                project_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key
        ) pu_project_single
            ON pu_project_single.project_key = c.project_key
        {$whereSql}";
}

function bx_messenger_messages_page(string $groupKey, int $limit = 20, string $beforeChatKey = '', ?array $user = null, string $directUserKey = ''): array
{
    bx_ensure_project_messenger_schema();
    $group = bx_messenger_group_row($groupKey);
    if ($group === null) {
        throw new RuntimeException('The selected Messenger group is not active.');
    }

    $limit = max(1, min(50, $limit));
    $fetchLimit = $limit + 1;
    $params = [$groupKey];
    $directUserKey = trim($directUserKey);
    $conversationSql = " AND c.conversation_type = 'group'";
    if ($directUserKey !== '') {
        if (!is_array($user) || trim((string) ($user['user_key'] ?? '')) === '') {
            throw new RuntimeException('Sign in before opening direct messages.');
        }
        if (bx_messenger_direct_member_row($groupKey, $directUserKey) === null) {
            throw new RuntimeException('Direct message user was not found in this group.');
        }
        $senderKeys = bx_messenger_sender_keys($user, (string) ($group['project_key'] ?? ''));
        if ($senderKeys === []) {
            throw new RuntimeException('Direct message sender could not be resolved.');
        }
        $senderPlaceholders = implode(',', array_fill(0, count($senderKeys), '?'));
        $conversationSql = " AND c.conversation_type = 'direct'
            AND (
                (c.sender_user_key IN ({$senderPlaceholders}) AND c.direct_recipient_user_key = ?)
                OR (c.sender_user_key = ? AND c.direct_recipient_user_key IN ({$senderPlaceholders}))
            )";
        $params = array_merge([$groupKey], $senderKeys, [$directUserKey, $directUserKey], $senderKeys);
    }
    $cursorSql = '';
    $beforeChatKey = trim($beforeChatKey);

    if ($beforeChatKey !== '') {
        if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $beforeChatKey)) {
            throw new RuntimeException('Messenger page cursor is invalid.');
        }
        $cursorId = bx_db()->GetOne(
            'SELECT x_id FROM project_messenger_chat WHERE group_key = ? AND chat_key = ? LIMIT 1',
            [$groupKey, $beforeChatKey]
        );
        if ($cursorId === false || $cursorId === null || (string) $cursorId === '') {
            throw new RuntimeException('Messenger page cursor was not found.');
        }
        $cursorSql = ' AND c.x_id < ?';
        $params[] = (int) $cursorId;
    }

    $rows = bx_db()->GetAll(
        "SELECT
            c.chat_key,
            c.project_key,
            c.group_key,
            c.conversation_type,
            c.direct_recipient_user_key,
            c.reply_to_chat_key,
            c.sender_user_key,
            c.sender_name,
            COALESCE(
                NULLIF(pu_direct.user_chat_name, ''),
                NULLIF(pu_builder.user_chat_name, ''),
                NULLIF(pu_direct.user_name, ''),
                NULLIF(pu_builder.user_name, ''),
                NULLIF(c.sender_name, ''),
                NULLIF(pu_single.single_user_display_name, ''),
                NULLIF(pu_project_single.single_user_display_name, ''),
                c.sender_name
            ) AS sender_display_name,
            c.message_text,
            c.message_type,
            c.message_status,
            DATE_FORMAT(c.removed_at, '%Y-%m-%d %H:%i:%s') AS removed_at,
            c.firebase_collection,
            c.firebase_sync_status,
            DATE_FORMAT(c.created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(c.updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_messenger_chat c
        LEFT JOIN project_user pu_direct
            ON pu_direct.project_key = c.project_key
            AND pu_direct.group_key = c.group_key
            AND pu_direct.user_key = c.sender_user_key
            AND pu_direct.user_status = 'ACTIVE'
        LEFT JOIN builder_user bu
            ON bu.user_key = c.sender_user_key
        LEFT JOIN project_user pu_builder
            ON pu_builder.project_key = c.project_key
            AND pu_builder.group_key = c.group_key
            AND pu_builder.user_status = 'ACTIVE'
            AND (
                (bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu_builder.user_login = bu.user_login)
                OR (bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu_builder.user_email = bu.user_email)
            )
        LEFT JOIN (
            SELECT
                project_key,
                group_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key, group_key
        ) pu_single
            ON pu_single.project_key = c.project_key
            AND pu_single.group_key = c.group_key
        LEFT JOIN (
            SELECT
                project_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key
        ) pu_project_single
            ON pu_project_single.project_key = c.project_key
        WHERE c.group_key = ?{$conversationSql}{$cursorSql}
        ORDER BY c.x_id DESC
        LIMIT {$fetchLimit}",
        $params
    ) ?: [];

    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }
    $rows = array_reverse($rows);
    $chatKeys = array_map(static fn (array $row): string => (string) ($row['chat_key'] ?? ''), $rows);
    $attachments = bx_messenger_attachments_for_chat_keys($chatKeys);
    $replies = bx_messenger_reply_rows($rows);
    $reactions = bx_messenger_reactions_for_chat_keys($chatKeys, $user);
    $viewerBaseUrl = bx_setting('media_image_viewer_url', 'http://localhost/rbms.com/view.php');

    $messages = array_map(
        static fn (array $row): array => bx_messenger_message_public(
            $row,
            $attachments[(string) ($row['chat_key'] ?? '')] ?? [],
            $replies[(string) ($row['reply_to_chat_key'] ?? '')] ?? null,
            $viewerBaseUrl,
            $reactions[(string) ($row['chat_key'] ?? '')] ?? []
        ),
        $rows
    );

    return [
        'messages' => $messages,
        'pagination' => [
            'limit' => $limit,
            'has_more' => $hasMore,
            'before_chat_key' => $beforeChatKey,
            'oldest_chat_key' => (string) ($messages[0]['chat_key'] ?? ''),
        ],
    ];
}

function bx_messenger_attachments_for_chat_keys(array $chatKeys): array
{
    $chatKeys = array_values(array_filter(array_unique(array_map('strval', $chatKeys)), static fn (string $key): bool => $key !== ''));
    if ($chatKeys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($chatKeys), '?'));
    $rows = bx_db()->GetAll(
        "SELECT attachment_key, chat_key, project_key, group_key, uploaded_image_url, image_original_name, image_mime_type, image_byte_size, image_sha256, sort_order, attachment_status, firebase_collection, firebase_sync_status, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
        FROM project_messenger_chat_attachment
        WHERE chat_key IN ({$placeholders}) AND attachment_status = 'ACTIVE'
        ORDER BY sort_order ASC, x_id ASC",
        $chatKeys
    ) ?: [];

    $grouped = [];
    foreach ($rows as $row) {
        $chatKey = (string) ($row['chat_key'] ?? '');
        if ($chatKey === '') {
            continue;
        }
        $grouped[$chatKey][] = [
            'attachment_key' => (string) ($row['attachment_key'] ?? ''),
            'chat_key' => $chatKey,
            'project_key' => (string) ($row['project_key'] ?? ''),
            'group_key' => (string) ($row['group_key'] ?? ''),
            'uploaded_image_url' => (string) ($row['uploaded_image_url'] ?? ''),
            'image_original_name' => (string) ($row['image_original_name'] ?? ''),
            'image_mime_type' => (string) ($row['image_mime_type'] ?? ''),
            'image_byte_size' => (int) ($row['image_byte_size'] ?? 0),
            'image_sha256' => (string) ($row['image_sha256'] ?? ''),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'attachment_status' => (string) ($row['attachment_status'] ?? 'ACTIVE'),
            'firebase_collection' => (string) ($row['firebase_collection'] ?? 'project_messenger_chat_attachment'),
            'firebase_sync_status' => (string) ($row['firebase_sync_status'] ?? 'PENDING'),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    return $grouped;
}

function bx_messenger_reactions_for_chat_keys(array $chatKeys, ?array $user = null): array
{
    $chatKeys = array_values(array_filter(array_unique(array_map('strval', $chatKeys)), static fn (string $key): bool => $key !== ''));
    if ($chatKeys === []) {
        return [];
    }

    $currentUserKey = bx_messenger_sender_key($user);
    $placeholders = implode(',', array_fill(0, count($chatKeys), '?'));
    $rows = bx_db()->GetAll(
        "SELECT chat_key, reaction_value, COUNT(*) AS reaction_count, SUM(CASE WHEN user_key = ? THEN 1 ELSE 0 END) AS reacted_by_me
        FROM project_messenger_chat_reaction
        WHERE chat_key IN ({$placeholders})
            AND reaction_status = 'ACTIVE'
        GROUP BY chat_key, reaction_value
        ORDER BY MIN(x_id) ASC",
        array_merge([$currentUserKey], $chatKeys)
    ) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $chatKey = (string) ($row['chat_key'] ?? '');
        $reactionValue = (string) ($row['reaction_value'] ?? '');
        if ($chatKey === '' || $reactionValue === '') {
            continue;
        }
        $result[$chatKey][] = [
            'reaction_value' => $reactionValue,
            'reaction_count' => (int) ($row['reaction_count'] ?? 0),
            'reacted_by_me' => (int) ($row['reacted_by_me'] ?? 0) > 0,
        ];
    }

    return $result;
}

function bx_messenger_reply_rows(array $messages): array
{
    $replyKeys = [];
    foreach ($messages as $row) {
        $replyKey = trim((string) ($row['reply_to_chat_key'] ?? ''));
        if ($replyKey !== '') {
            $replyKeys[] = $replyKey;
        }
    }
    $replyKeys = array_values(array_unique($replyKeys));
    if ($replyKeys === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($replyKeys), '?'));
    $rows = bx_db()->GetAll(
        "SELECT
            c.chat_key,
            c.sender_name,
            COALESCE(
                NULLIF(pu_direct.user_chat_name, ''),
                NULLIF(pu_builder.user_chat_name, ''),
                NULLIF(pu_direct.user_name, ''),
                NULLIF(pu_builder.user_name, ''),
                NULLIF(c.sender_name, ''),
                NULLIF(pu_single.single_user_display_name, ''),
                NULLIF(pu_project_single.single_user_display_name, ''),
                c.sender_name
            ) AS sender_display_name,
            c.message_text,
            c.message_type,
            c.message_status
        FROM project_messenger_chat c
        LEFT JOIN project_user pu_direct
            ON pu_direct.project_key = c.project_key
            AND pu_direct.group_key = c.group_key
            AND pu_direct.user_key = c.sender_user_key
            AND pu_direct.user_status = 'ACTIVE'
        LEFT JOIN builder_user bu
            ON bu.user_key = c.sender_user_key
        LEFT JOIN project_user pu_builder
            ON pu_builder.project_key = c.project_key
            AND pu_builder.group_key = c.group_key
            AND pu_builder.user_status = 'ACTIVE'
            AND (
                (bu.user_login IS NOT NULL AND bu.user_login <> '' AND pu_builder.user_login = bu.user_login)
                OR (bu.user_email IS NOT NULL AND bu.user_email <> '' AND pu_builder.user_email = bu.user_email)
            )
        LEFT JOIN (
            SELECT
                project_key,
                group_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key, group_key
        ) pu_single
            ON pu_single.project_key = c.project_key
            AND pu_single.group_key = c.group_key
        LEFT JOIN (
            SELECT
                project_key,
                CASE
                    WHEN COUNT(*) = 1 THEN MAX(COALESCE(NULLIF(user_chat_name, ''), NULLIF(user_name, ''), user_login))
                    ELSE ''
                END AS single_user_display_name
            FROM project_user
            WHERE user_status = 'ACTIVE'
            GROUP BY project_key
        ) pu_project_single
            ON pu_project_single.project_key = c.project_key
        WHERE c.chat_key IN ({$placeholders})",
        $replyKeys
    ) ?: [];

    $result = [];
    foreach ($rows as $row) {
        $chatKey = (string) ($row['chat_key'] ?? '');
        $removed = (string) ($row['message_status'] ?? '') === 'REMOVED';
        if ($chatKey === '') {
            continue;
        }
        $result[$chatKey] = [
            'chat_key' => $chatKey,
            'sender_name' => (string) ($row['sender_display_name'] ?? $row['sender_name'] ?? ''),
            'message_text' => $removed ? 'Original message removed' : substr((string) ($row['message_text'] ?? ''), 0, 160),
            'message_type' => (string) ($row['message_type'] ?? 'text'),
            'message_status' => (string) ($row['message_status'] ?? 'ACTIVE'),
        ];
    }

    return $result;
}

function bx_messenger_messages(string $groupKey, int $limit = 50, ?array $user = null, string $directUserKey = ''): array
{
    return bx_messenger_messages_page($groupKey, $limit, '', $user, $directUserKey)['messages'];
}

function bx_messenger_message_by_chat_key(string $chatKey, ?array $user = null): array
{
    bx_ensure_project_messenger_schema();
    if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $chatKey)) {
        throw new RuntimeException('Message key is invalid.');
    }

    $row = bx_db()->GetRow(
        bx_messenger_message_select_sql('WHERE c.chat_key = ? LIMIT 1'),
        [$chatKey]
    );
    if (!is_array($row) || trim((string) ($row['chat_key'] ?? '')) === '') {
        throw new RuntimeException('Message was not found.');
    }

    $attachments = bx_messenger_attachments_for_chat_keys([$chatKey]);
    $replies = bx_messenger_reply_rows([$row]);
    $reactions = bx_messenger_reactions_for_chat_keys([$chatKey], $user);
    $viewerBaseUrl = bx_setting('media_image_viewer_url', 'http://localhost/rbms.com/view.php');

    return bx_messenger_message_public(
        $row,
        $attachments[$chatKey] ?? [],
        $replies[(string) ($row['reply_to_chat_key'] ?? '')] ?? null,
        $viewerBaseUrl,
        $reactions[$chatKey] ?? []
    );
}

function bx_messenger_toggle_reaction(string $chatKey, string $reactionValue, ?array $user = null): array
{
    bx_ensure_project_messenger_schema();
    if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $chatKey)) {
        throw new RuntimeException('Message key is invalid.');
    }

    $reactionValue = trim($reactionValue);
    if (!in_array($reactionValue, bx_messenger_allowed_reactions(), true)) {
        throw new RuntimeException('Reaction is not available.');
    }

    $userKey = bx_messenger_sender_key($user);
    $db = bx_db();
    $transactionStarted = false;

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Reaction transaction could not start.');
        }
        $transactionStarted = true;

        $message = $db->GetRow(
            "SELECT chat_key, project_key, group_key, message_status
            FROM project_messenger_chat
            WHERE chat_key = ? FOR UPDATE",
            [$chatKey]
        );
        if (!is_array($message) || trim((string) ($message['chat_key'] ?? '')) === '') {
            throw new RuntimeException('Message was not found.');
        }
        if ((string) ($message['message_status'] ?? '') === 'REMOVED') {
            throw new RuntimeException('Removed messages cannot be reacted to.');
        }

        $selectedActive = $db->GetRow(
            "SELECT reaction_key
            FROM project_messenger_chat_reaction
            WHERE chat_key = ?
                AND user_key = ?
                AND reaction_value = ?
                AND reaction_status = 'ACTIVE'
            LIMIT 1 FOR UPDATE",
            [$chatKey, $userKey, $reactionValue]
        );
        $selectedActiveKey = is_array($selectedActive) ? trim((string) ($selectedActive['reaction_key'] ?? '')) : '';

        $cleared = $db->Execute(
            "UPDATE project_messenger_chat_reaction
            SET reaction_status = 'REMOVED',
                firebase_sync_status = 'PENDING',
                updated_at = CURRENT_TIMESTAMP
            WHERE chat_key = ?
                AND user_key = ?
                AND reaction_status = 'ACTIVE'",
            [$chatKey, $userKey]
        );
        if ($cleared === false) {
            throw new RuntimeException('Reaction replace failed: ' . trim((string) $db->ErrorMsg()));
        }

        if ($selectedActiveKey !== '') {
            $nextStatus = 'REMOVED';
            $saved = $cleared;
        } else {
            $existing = $db->GetRow(
                "SELECT reaction_key
                FROM project_messenger_chat_reaction
                WHERE chat_key = ? AND user_key = ? AND reaction_value = ?
                LIMIT 1 FOR UPDATE",
                [$chatKey, $userKey, $reactionValue]
            );
            $existingReactionKey = is_array($existing) ? trim((string) ($existing['reaction_key'] ?? '')) : '';
            if ($existingReactionKey !== '') {
                $saved = $db->Execute(
                    "UPDATE project_messenger_chat_reaction
                    SET reaction_status = 'ACTIVE',
                        firebase_sync_status = 'PENDING',
                        updated_at = CURRENT_TIMESTAMP
                    WHERE reaction_key = ?",
                    [$existingReactionKey]
                );
            } else {
                $saved = $db->Execute(
                    "INSERT INTO project_messenger_chat_reaction (
                        reaction_key, chat_key, project_key, group_key, user_key, reaction_value,
                        reaction_status, firebase_collection, firebase_sync_status
                    ) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', 'project_messenger_chat_reaction', 'PENDING')",
                    [
                        bx_unique_firebase_document_key('project_messenger_chat_reaction', 'reaction_key'),
                        $chatKey,
                        (string) ($message['project_key'] ?? ''),
                        (string) ($message['group_key'] ?? ''),
                        $userKey,
                        $reactionValue,
                    ]
                );
            }
            $nextStatus = 'ACTIVE';
        }

        if ($saved === false) {
            throw new RuntimeException('Reaction save failed: ' . trim((string) $db->ErrorMsg()));
        }
        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Reaction transaction could not commit.');
        }
        $transactionStarted = false;

        bx_audit('UPDATE', 'project_messenger_chat_reaction', $chatKey, [
            'chat_key' => $chatKey,
            'reaction_value' => $reactionValue,
            'reaction_status' => $nextStatus,
            'firebase_collection' => 'project_messenger_chat_reaction',
            'firebase_sync_status' => 'PENDING',
        ], 'Messenger reaction toggled.');

        return bx_messenger_message_by_chat_key($chatKey, $user);
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_messenger_send_message(string $groupKey, string $messageText, string $replyToChatKey, array $attachments, ?array $user = null, string $directUserKey = ''): array
{
    bx_ensure_project_messenger_schema();
    if (!is_array($user) || trim((string) ($user['user_key'] ?? '')) === '') {
        throw new RuntimeException('Sign in before sending Messenger messages.');
    }

    $group = bx_messenger_group_row($groupKey);
    if ($group === null) {
        throw new RuntimeException('The selected Messenger group is not active.');
    }

    $messageText = trim($messageText);
    if (strlen($messageText) > 8000) {
        throw new RuntimeException('Message is too long.');
    }

    $cleanAttachments = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $url = trim((string) ($attachment['uploaded_image_url'] ?? $attachment['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (!preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
            throw new RuntimeException('Attachment upload did not return a valid image URL.');
        }
        $cleanAttachments[] = [
            'uploaded_image_url' => $url,
            'image_original_name' => substr(trim((string) ($attachment['image_original_name'] ?? $attachment['original_name'] ?? 'image')), 0, 255),
            'image_mime_type' => substr(trim((string) ($attachment['image_mime_type'] ?? $attachment['mime_type'] ?? '')), 0, 100),
            'image_byte_size' => max(0, (int) ($attachment['image_byte_size'] ?? $attachment['byte_size'] ?? 0)),
            'image_sha256' => substr(trim((string) ($attachment['image_sha256'] ?? $attachment['sha256'] ?? '')), 0, 128),
        ];
    }

    if ($messageText === '' && $cleanAttachments === []) {
        throw new RuntimeException('Type a message or attach at least one image.');
    }
    if (count($cleanAttachments) > 10) {
        throw new RuntimeException('Send up to 10 images per message.');
    }

    $directUserKey = trim($directUserKey);
    $directMember = null;
    if ($directUserKey !== '') {
        $directMember = bx_messenger_direct_member_row($groupKey, $directUserKey);
        if ($directMember === null) {
            throw new RuntimeException('Direct message user was not found in this group.');
        }
    }
    $conversationType = $directMember !== null ? 'direct' : 'group';

    $replyToChatKey = trim($replyToChatKey);
    if ($replyToChatKey !== '') {
        if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $replyToChatKey)) {
            throw new RuntimeException('Reply target is invalid.');
        }
        $replyExists = (int) bx_db()->GetOne(
            'SELECT COUNT(*) FROM project_messenger_chat WHERE chat_key = ? AND group_key = ? AND conversation_type = ?',
            [$replyToChatKey, $groupKey, $conversationType]
        );
        if ($replyExists !== 1) {
            throw new RuntimeException('Reply target was not found in this conversation.');
        }
    }

    $chatKey = bx_unique_firebase_document_key('project_messenger_chat', 'chat_key');
    $senderKey = bx_messenger_sender_key($user);
    $messageType = $messageText !== '' && $cleanAttachments !== [] ? 'mixed' : ($cleanAttachments !== [] ? 'image' : 'text');
    $projectKey = (string) ($group['project_key'] ?? '');
    $senderName = bx_messenger_sender_name($user, $projectKey);
    $db = bx_db();
    $transactionStarted = false;

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Messenger transaction could not start.');
        }
        $transactionStarted = true;

        $saved = $db->Execute(
            "INSERT INTO project_messenger_chat (chat_key, project_key, group_key, conversation_type, direct_recipient_user_key, reply_to_chat_key, sender_user_key, sender_name, message_text, message_type, message_status, firebase_collection, firebase_sync_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', 'project_messenger_chat', 'PENDING')",
            [$chatKey, $projectKey, $groupKey, $conversationType, $directUserKey !== '' ? $directUserKey : null, $replyToChatKey !== '' ? $replyToChatKey : null, $senderKey, $senderName, $messageText, $messageType]
        );
        if ($saved === false) {
            throw new RuntimeException('Messenger message save failed: ' . trim((string) $db->ErrorMsg()));
        }

        foreach ($cleanAttachments as $index => $attachment) {
            $attachmentKey = bx_unique_firebase_document_key('project_messenger_chat_attachment', 'attachment_key');
            $saved = $db->Execute(
                "INSERT INTO project_messenger_chat_attachment (attachment_key, chat_key, project_key, group_key, uploaded_image_url, image_original_name, image_mime_type, image_byte_size, image_sha256, sort_order, attachment_status, firebase_collection, firebase_sync_status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', 'project_messenger_chat_attachment', 'PENDING')",
                [
                    $attachmentKey,
                    $chatKey,
                    $projectKey,
                    $groupKey,
                    $attachment['uploaded_image_url'],
                    $attachment['image_original_name'],
                    $attachment['image_mime_type'],
                    $attachment['image_byte_size'],
                    $attachment['image_sha256'],
                    $index + 1,
                ]
            );
            if ($saved === false) {
                throw new RuntimeException('Messenger attachment save failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        $readBack = $db->GetRow(
            "SELECT chat_key, project_key, group_key, conversation_type, direct_recipient_user_key, reply_to_chat_key, sender_user_key, sender_name, message_text, message_type, message_status, DATE_FORMAT(removed_at, '%Y-%m-%d %H:%i:%s') AS removed_at, firebase_collection, firebase_sync_status, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
            FROM project_messenger_chat
            WHERE chat_key = ? FOR UPDATE",
            [$chatKey]
        );
        if (!is_array($readBack) || (string) ($readBack['chat_key'] ?? '') !== $chatKey) {
            throw new RuntimeException('Messenger read-back failed after save.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Messenger transaction could not commit.');
        }
        $transactionStarted = false;

        bx_audit('CREATE', 'project_messenger_chat', $chatKey, [
            'project_key' => $projectKey,
            'group_key' => $groupKey,
            'conversation_type' => $conversationType,
            'direct_recipient_user_key' => $directUserKey,
            'attachment_count' => (string) count($cleanAttachments),
            'firebase_collection' => 'project_messenger_chat',
            'firebase_sync_status' => 'PENDING',
        ], 'Messenger message saved for Firebase collection project_messenger_chat.');

        $message = bx_messenger_messages($groupKey, 100, $user, $directUserKey);
        foreach ($message as $item) {
            if ((string) ($item['chat_key'] ?? '') === $chatKey) {
                return $item;
            }
        }

        return bx_messenger_message_public($readBack, [], null);
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_messenger_remove_message(string $chatKey, ?array $user = null): array
{
    bx_ensure_project_messenger_schema();
    if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $chatKey)) {
        throw new RuntimeException('Message key is invalid.');
    }

    $db = bx_db();
    $senderKey = bx_messenger_sender_key($user);
    $isAdmin = is_array($user) ? bx_is_admin($user) : false;
    $transactionStarted = false;

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Messenger delete transaction could not start.');
        }
        $transactionStarted = true;

        $row = $db->GetRow('SELECT chat_key, group_key, sender_user_key, message_status FROM project_messenger_chat WHERE chat_key = ? FOR UPDATE', [$chatKey]);
        if (!is_array($row)) {
            throw new RuntimeException('Message was not found.');
        }
        if (!$isAdmin && (string) ($row['sender_user_key'] ?? '') !== $senderKey) {
            throw new RuntimeException('Only the sender can remove this message.');
        }

        $saved = $db->Execute(
            "UPDATE project_messenger_chat
            SET message_status = 'REMOVED',
                message_text = '',
                removed_at = CURRENT_TIMESTAMP,
                removed_by_user_key = ?,
                firebase_sync_status = 'PENDING'
            WHERE chat_key = ?",
            [$senderKey, $chatKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Message remove failed: ' . trim((string) $db->ErrorMsg()));
        }

        $saved = $db->Execute(
            "UPDATE project_messenger_chat_attachment
            SET attachment_status = 'REMOVED',
                firebase_sync_status = 'PENDING'
            WHERE chat_key = ?",
            [$chatKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Message attachment remove failed: ' . trim((string) $db->ErrorMsg()));
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Messenger delete transaction could not commit.');
        }
        $transactionStarted = false;

        bx_audit('UPDATE', 'project_messenger_chat', $chatKey, [
            'message_status' => 'REMOVED',
            'firebase_collection' => 'project_messenger_chat',
            'firebase_sync_status' => 'PENDING',
        ], 'Messenger message soft-removed.');

        $messages = bx_messenger_messages((string) ($row['group_key'] ?? ''), 100);
        foreach ($messages as $message) {
            if ((string) ($message['chat_key'] ?? '') === $chatKey) {
                return $message;
            }
        }
        throw new RuntimeException('Message read-back failed after remove.');
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_csrf_token(): string
{
    if (empty($_SESSION['builderx_csrf'])) {
        $_SESSION['builderx_csrf'] = bin2hex(random_bytes(24));
    }

    return $_SESSION['builderx_csrf'];
}

function bx_verify_csrf(): void
{
    $token = (string) ($_POST['csrf'] ?? '');
    if (!hash_equals(bx_csrf_token(), $token)) {
        bx_flash('Invalid request token.', 'error');
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? './'));
        exit;
    }
}

function bx_flash(string $message, string $type = 'info', ?string $details = null, array $lifecycle = []): void
{
    $_SESSION['builderx_flash'] = ['message' => $message, 'type' => $type];
    if ($details !== null && trim($details) !== '') {
        $_SESSION['builderx_flash']['details'] = substr(trim($details), 0, 4000);
    }
    $status = trim((string) ($lifecycle['status'] ?? ''));
    if ($status !== '') {
        $_SESSION['builderx_flash']['lifecycleStatus'] = substr($status, 0, 80);
    }
    if (is_array($lifecycle['steps'] ?? null)) {
        $steps = [];
        foreach ($lifecycle['steps'] as $step) {
            if (!is_array($step)) {
                continue;
            }
            $label = trim((string) ($step['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $steps[] = [
                'label' => substr($label, 0, 120),
                'status' => substr(trim((string) ($step['status'] ?? 'complete')), 0, 40),
                'detail' => substr(trim((string) ($step['detail'] ?? '')), 0, 240),
            ];
        }
        if ($steps !== []) {
            $_SESSION['builderx_flash']['lifecycleSteps'] = $steps;
        }
    }
}

function bx_mutation_lifecycle_flash(string $message, string $type, array $steps, ?string $details = null): void
{
    bx_flash($message, $type, $details, [
        'status' => $type === 'success' ? 'committed_read_back' : 'action_required',
        'steps' => $steps,
    ]);
}

function bx_take_flash(): ?array
{
    $flash = $_SESSION['builderx_flash'] ?? null;
    unset($_SESSION['builderx_flash']);

    return $flash;
}

function bx_password_hash(string $password): string
{
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    return password_hash($password, $algo);
}

function bx_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

function bx_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli'), 0, 255);
}

function bx_add_column_if_missing(string $table, string $column, string $definition): void
{
    $exists = bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, $table, $column]
    );

    if ((int) $exists === 0) {
        bx_db()->Execute("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function bx_add_index_if_missing(string $table, string $index, string $definition): void
{
    $exists = bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, $table, $index]
    );

    if ((int) $exists === 0) {
        bx_db()->Execute("ALTER TABLE {$table} ADD {$definition}");
    }
}

function bx_add_unique_index_if_missing(string $table, string $column, string $index, string $definition): void
{
    $db = bx_db();
    $namedExists = $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
        [BUILDERX_DB_NAME, $table, $index]
    );
    if ((int) $namedExists > 0) {
        return;
    }

    $columnIsUnique = $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0',
        [BUILDERX_DB_NAME, $table, $column]
    );
    if ((int) $columnIsUnique === 0) {
        $db->Execute("ALTER TABLE {$table} ADD {$definition}");
    }
}

function bx_phase_builder_current_draft_key(): string
{
    return trim((string) bx_db()->GetOne(
        'SELECT draft_key FROM phase_builder_narrative_draft ORDER BY updated_at DESC, x_id DESC LIMIT 1'
    ));
}

function bx_backup_phase_builder_narrative_draft(): void
{
    $db = bx_db();
    $missingRows = $db->GetAll(
        'SELECT source.x_id FROM phase_builder_narrative_draft source LEFT JOIN phase_builder_narrative_draft_backup backup ON backup.x_id = source.x_id WHERE backup.x_id IS NULL ORDER BY source.x_id'
    );
    if (!is_array($missingRows) || $missingRows === []) {
        return;
    }

    $db->BeginTrans();
    $transactionStarted = true;
    try {
        $copied = $db->Execute('INSERT IGNORE INTO phase_builder_narrative_draft_backup SELECT * FROM phase_builder_narrative_draft');
        if ($copied === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Tab 1 backup copy failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $fields = [
            'x_id',
            'draft_key',
            'phase_key',
            'product_goal',
            'users_and_roles',
            'main_user_journey',
            'web_requirements',
            'android_requirements',
            'database_and_synchronization',
            'security_and_permissions',
            'validation_and_error_handling',
            'open_questions',
            'created_by_user_key',
            'updated_by_user_key',
            'created_at',
            'updated_at',
        ];
        $selectFields = implode(', ', $fields);
        foreach ($missingRows as $missingRow) {
            $rowKey = (int) ($missingRow['x_id'] ?? 0);
            if ($rowKey < 1) {
                throw new RuntimeException('Tab 1 backup copy returned an invalid source key.');
            }

            $source = $db->GetRow("SELECT {$selectFields} FROM phase_builder_narrative_draft WHERE x_id = ? LIMIT 1", [$rowKey]);
            $backup = $db->GetRow("SELECT {$selectFields} FROM phase_builder_narrative_draft_backup WHERE x_id = ? LIMIT 1", [$rowKey]);
            if (!is_array($source) || !is_array($backup)) {
                throw new RuntimeException('Tab 1 backup read-back returned no matching row.');
            }
            foreach ($fields as $field) {
                if ((string) ($source[$field] ?? '') !== (string) ($backup[$field] ?? '')) {
                    throw new RuntimeException('Tab 1 backup read-back mismatch for ' . $field . '.');
                }
            }
        }

        $db->CommitTrans();
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_schema(): void
{
    $db = bx_db();
    $assertAiSchema = static function (mixed $result, string $table) use ($db): void {
        if ($result !== false) {
            return;
        }
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('AI run schema setup failed for ' . $table . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    };
    $schemaTableExists = static function (string $table) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, $table]
        ) === 1;
    };

    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_run (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_key CHAR(36) NOT NULL UNIQUE,
        project_identity CHAR(64) NOT NULL,
        engine_type VARCHAR(20) NOT NULL,
        workflow_key VARCHAR(80) NOT NULL,
        route_key VARCHAR(80) NOT NULL,
        stage_key VARCHAR(80) NOT NULL,
        draft_key CHAR(36) NULL,
        phase_key CHAR(36) NULL,
        task_id VARCHAR(200) NULL,
        subtask_id VARCHAR(200) NULL,
        todo_id VARCHAR(200) NULL,
        source_hash CHAR(64) NOT NULL,
        request_version VARCHAR(80) NOT NULL,
        idempotency_key VARCHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
        attempt SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
        provider_key VARCHAR(80) NULL,
        model_key VARCHAR(120) NULL,
        provider_request_id VARCHAR(200) NULL,
        worker_id VARCHAR(120) NULL,
        locked_until DATETIME NULL,
        request_json LONGTEXT NOT NULL,
        result_json LONGTEXT NULL,
        error_code VARCHAR(80) NULL,
        error_detail TEXT NULL,
        created_by_user_key CHAR(36) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at TIMESTAMP NULL,
        heartbeat_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        UNIQUE KEY uq_phase_ai_run_project_idempotency (project_identity, idempotency_key),
        KEY idx_phase_ai_run_scope (project_identity, workflow_key, draft_key, x_id),
        KEY idx_phase_ai_run_owner_scope (project_identity, created_by_user_key, route_key, workflow_key, x_id),
        KEY idx_phase_ai_run_status (status, locked_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_run');
    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_run_stage (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        stage_record_key CHAR(36) NOT NULL UNIQUE,
        run_key CHAR(36) NOT NULL,
        stage_key VARCHAR(80) NOT NULL,
        stage_order SMALLINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
        source_hash CHAR(64) NOT NULL,
        request_json LONGTEXT NULL,
        result_json LONGTEXT NULL,
        provider_request_id VARCHAR(200) NULL,
        error_code VARCHAR(80) NULL,
        error_detail TEXT NULL,
        started_at TIMESTAMP NULL,
        heartbeat_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        UNIQUE KEY uq_phase_ai_run_stage (run_key, stage_key),
        KEY idx_phase_ai_run_stage_status (run_key, status, stage_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_run_stage');
    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_run_chunk (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        chunk_record_key CHAR(36) NOT NULL UNIQUE,
        chunk_key VARCHAR(160) NOT NULL,
        run_key CHAR(36) NOT NULL,
        stage_key VARCHAR(80) NOT NULL,
        chunk_type VARCHAR(40) NOT NULL,
        chunk_order SMALLINT UNSIGNED NOT NULL,
        source_hash CHAR(64) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        request_json LONGTEXT NULL,
        result_json LONGTEXT NULL,
        error_code VARCHAR(80) NULL,
        started_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        UNIQUE KEY uq_phase_ai_run_chunk (run_key, stage_key, chunk_key),
        KEY idx_phase_ai_run_chunk_status (run_key, status, chunk_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_run_chunk');
    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_run_event (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_key CHAR(36) NOT NULL UNIQUE,
        run_key CHAR(36) NOT NULL,
        stage_key VARCHAR(80) NULL,
        chunk_key VARCHAR(160) NULL,
        event_type VARCHAR(80) NOT NULL,
        status VARCHAR(24) NOT NULL,
        message VARCHAR(500) NOT NULL,
        payload_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_phase_ai_run_event (run_key, x_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_run_event');

    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_context (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        context_key VARCHAR(160) NOT NULL UNIQUE,
        project_identity CHAR(64) NOT NULL,
        context_type VARCHAR(120) NOT NULL,
        context_json LONGTEXT NOT NULL,
        byte_size BIGINT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        created_by_user_key CHAR(36) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_phase_ai_context_project (project_identity, created_at),
        KEY idx_phase_ai_context_type (project_identity, context_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_context');

    $assertAiSchema($db->Execute("CREATE TABLE IF NOT EXISTS phase_builder_ai_job (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_key CHAR(36) NOT NULL UNIQUE,
        run_key CHAR(36) NOT NULL,
        stage_key VARCHAR(80) NOT NULL,
        project_identity CHAR(64) NOT NULL,
        engine_type VARCHAR(20) NOT NULL,
        workflow_key VARCHAR(80) NOT NULL,
        execution_mode VARCHAR(40) NOT NULL DEFAULT 'read_only',
        instruction_text LONGTEXT NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'QUEUED',
        claim_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        worker_id VARCHAR(160) NULL,
        result_json LONGTEXT NULL,
        error_code VARCHAR(80) NULL,
        error_detail TEXT NULL,
        created_by_user_key CHAR(36) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        claimed_at TIMESTAMP NULL,
        heartbeat_at TIMESTAMP NULL,
        completed_at TIMESTAMP NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_phase_ai_job_stage (run_key, stage_key),
        KEY idx_phase_ai_job_project_status (project_identity, status, x_id),
        KEY idx_phase_ai_job_run (run_key, stage_key, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'phase_builder_ai_job');

    $routeColumnExists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'phase_builder_ai_run', 'route_key']
    );
    if ($routeColumnExists === 0) {
        $assertAiSchema($db->Execute(
            "ALTER TABLE phase_builder_ai_run ADD COLUMN route_key VARCHAR(80) NOT NULL DEFAULT 'phases:builder' AFTER workflow_key"
        ), 'phase_builder_ai_run.route_key');
    }
    $ownerScopeIndexExists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
        [BUILDERX_DB_NAME, 'phase_builder_ai_run', 'idx_phase_ai_run_owner_scope']
    );
    if ($ownerScopeIndexExists === 0) {
        $assertAiSchema($db->Execute(
            'ALTER TABLE phase_builder_ai_run ADD KEY idx_phase_ai_run_owner_scope (project_identity, created_by_user_key, route_key, workflow_key, x_id)'
        ), 'phase_builder_ai_run.idx_phase_ai_run_owner_scope');
    }

    $assertPhaseSchema = static function (mixed $result, string $operation) use ($db): void {
        if ($result !== false) {
            return;
        }
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Phase Manager schema setup failed for ' . $operation . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    };
    $phaseColumn = static function (string $table, string $column) use ($db): ?array {
        $row = $db->GetRow(
            'SELECT COLUMN_NAME, COLUMN_TYPE, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [BUILDERX_DB_NAME, $table, $column]
        );
        return is_array($row) && $row !== [] ? $row : null;
    };
    $phaseIndexExists = static function (string $table, string $index) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [BUILDERX_DB_NAME, $table, $index]
        ) > 0;
    };

    $assertPhaseSchema($db->Execute("CREATE TABLE IF NOT EXISTS builder_phase (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        phase_key CHAR(36) NOT NULL UNIQUE,
        phase_number INT UNSIGNED NOT NULL,
        phase_code VARCHAR(20) NULL,
        phase_title VARCHAR(150) NOT NULL,
        phase_summary TEXT NULL,
        phase_status VARCHAR(30) NOT NULL DEFAULT 'Not Started',
        phase_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_builder_phase_code (phase_code),
        KEY idx_builder_phase_status (phase_status),
        KEY idx_builder_phase_sort (phase_sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'builder_phase');
    $assertPhaseSchema($db->Execute("CREATE TABLE IF NOT EXISTS builder_phase_task (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        task_key CHAR(36) NOT NULL UNIQUE,
        phase_key CHAR(36) NOT NULL,
        task_code VARCHAR(30) NULL,
        task_title VARCHAR(255) NOT NULL,
        task_details TEXT NULL,
        task_reference MEDIUMTEXT NULL,
        task_scope MEDIUMTEXT NULL,
        task_acceptance_checklist MEDIUMTEXT NULL,
        task_exclusions MEDIUMTEXT NULL,
        task_notes TEXT NULL,
        is_completed TINYINT(1) NOT NULL DEFAULT 0,
        task_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        task_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_builder_phase_task_code (task_code),
        UNIQUE KEY uq_builder_phase_task_order (phase_key, task_sort_order),
        KEY idx_builder_phase_task_phase (phase_key),
        KEY idx_builder_phase_task_status (task_status),
        KEY idx_builder_phase_task_completed (is_completed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'builder_phase_task');
    $assertPhaseSchema($db->Execute("CREATE TABLE IF NOT EXISTS builder_phase_task_checklist (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        checklist_key CHAR(36) NOT NULL UNIQUE,
        task_key CHAR(36) NOT NULL,
        checklist_text TEXT NOT NULL,
        is_done TINYINT(1) NOT NULL DEFAULT 0,
        checklist_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        checklist_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_builder_phase_task_checklist_task (task_key),
        KEY idx_builder_phase_task_checklist_status (checklist_status),
        KEY idx_builder_phase_task_checklist_done (is_done)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"), 'builder_phase_task_checklist');

    $phaseStatusColumn = $phaseColumn('builder_phase', 'phase_status');
    if (is_array($phaseStatusColumn) && strtolower((string) ($phaseStatusColumn['DATA_TYPE'] ?? '')) !== 'varchar') {
        $assertPhaseSchema(
            $db->Execute("ALTER TABLE builder_phase MODIFY phase_status VARCHAR(30) NOT NULL DEFAULT 'Not Started'"),
            'builder_phase.phase_status'
        );
        $assertPhaseSchema($db->Execute("UPDATE builder_phase SET phase_status = CASE phase_status WHEN 'DRAFT' THEN 'Not Started' WHEN 'ACTIVE' THEN 'In Progress' WHEN 'COMPLETED' THEN 'Completed' ELSE phase_status END"), 'builder_phase.phase_status values');
    }

    foreach ([
        'task_scope' => 'MEDIUMTEXT NULL AFTER task_reference',
        'task_acceptance_checklist' => 'MEDIUMTEXT NULL AFTER task_scope',
        'task_exclusions' => 'MEDIUMTEXT NULL AFTER task_acceptance_checklist',
        'task_notes' => 'TEXT NULL AFTER task_exclusions',
    ] as $column => $definition) {
        if ($phaseColumn('builder_phase_task', $column) === null) {
            $assertPhaseSchema($db->Execute("ALTER TABLE builder_phase_task ADD COLUMN {$column} {$definition}"), 'builder_phase_task.' . $column);
        }
    }
    $taskReferenceColumn = $phaseColumn('builder_phase_task', 'task_reference');
    if (is_array($taskReferenceColumn) && strtolower((string) ($taskReferenceColumn['DATA_TYPE'] ?? '')) !== 'mediumtext') {
        $assertPhaseSchema($db->Execute('ALTER TABLE builder_phase_task MODIFY task_reference MEDIUMTEXT NULL'), 'builder_phase_task.task_reference');
    }
    $taskStatusColumn = $phaseColumn('builder_phase_task', 'task_status');
    if (is_array($taskStatusColumn) && strtolower((string) ($taskStatusColumn['DATA_TYPE'] ?? '')) !== 'varchar') {
        $assertPhaseSchema($db->Execute("ALTER TABLE builder_phase_task MODIFY task_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'"), 'builder_phase_task.task_status');
    }

    if ($phaseColumn('builder_phase_task_checklist', 'checklist_text') === null) {
        if ($phaseColumn('builder_phase_task_checklist', 'checklist_title') === null) {
            throw new RuntimeException('Phase Manager schema setup cannot find a checklist text column to migrate.');
        }
        $assertPhaseSchema($db->Execute('ALTER TABLE builder_phase_task_checklist CHANGE COLUMN checklist_title checklist_text TEXT NOT NULL'), 'builder_phase_task_checklist.checklist_text');
    }
    if ($phaseColumn('builder_phase_task_checklist', 'is_done') === null) {
        if ($phaseColumn('builder_phase_task_checklist', 'is_completed') === null) {
            throw new RuntimeException('Phase Manager schema setup cannot find a checklist completion column to migrate.');
        }
        $assertPhaseSchema($db->Execute('ALTER TABLE builder_phase_task_checklist CHANGE COLUMN is_completed is_done TINYINT(1) NOT NULL DEFAULT 0'), 'builder_phase_task_checklist.is_done');
    }
    if ($phaseColumn('builder_phase_task_checklist', 'checklist_sort_order') === null) {
        $assertPhaseSchema($db->Execute('ALTER TABLE builder_phase_task_checklist ADD COLUMN checklist_sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER checklist_status'), 'builder_phase_task_checklist.checklist_sort_order');
    }
    $checklistStatusColumn = $phaseColumn('builder_phase_task_checklist', 'checklist_status');
    if (is_array($checklistStatusColumn) && strtolower((string) ($checklistStatusColumn['DATA_TYPE'] ?? '')) !== 'varchar') {
        $assertPhaseSchema($db->Execute("ALTER TABLE builder_phase_task_checklist MODIFY checklist_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'"), 'builder_phase_task_checklist.checklist_status');
    }
    foreach ([
        'idx_builder_phase_task_checklist_task' => 'KEY idx_builder_phase_task_checklist_task (task_key)',
        'idx_builder_phase_task_checklist_status' => 'KEY idx_builder_phase_task_checklist_status (checklist_status)',
        'idx_builder_phase_task_checklist_done' => 'KEY idx_builder_phase_task_checklist_done (is_done)',
    ] as $index => $definition) {
        if (!$phaseIndexExists('builder_phase_task_checklist', $index)) {
            $assertPhaseSchema($db->Execute("ALTER TABLE builder_phase_task_checklist ADD {$definition}"), 'builder_phase_task_checklist.' . $index);
        }
    }
    $db->Execute("CREATE TABLE IF NOT EXISTS builder_phase_task_note (
        x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        note_key CHAR(36) NOT NULL UNIQUE,
        task_key CHAR(36) NOT NULL,
        note_body TEXT NOT NULL,
        note_status ENUM('ACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_builder_phase_note_task (task_key, note_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_system_setting (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key CHAR(36) NOT NULL UNIQUE,
            setting_name VARCHAR(120) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            setting_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    bx_add_column_if_missing('builder_system_setting', 'is_secret', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER setting_group');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS project_setting (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_key CHAR(36) NOT NULL UNIQUE,
            project_key CHAR(36) NOT NULL,
            setting_name VARCHAR(120) NOT NULL,
            setting_value TEXT NULL,
            setting_group VARCHAR(80) NOT NULL DEFAULT 'general',
            is_secret TINYINT(1) NOT NULL DEFAULT 0,
            setting_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_setting_name (project_key, setting_name),
            KEY idx_project_setting_group (project_key, setting_group, setting_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_android_client_app (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            client_app_key CHAR(36) NOT NULL UNIQUE,
            client_app_code VARCHAR(40) NOT NULL UNIQUE,
            client_name VARCHAR(160) NOT NULL,
            firebase_project_id VARCHAR(120) NOT NULL,
            firebase_database_url VARCHAR(255) NOT NULL,
            firebase_firestore_database_id VARCHAR(120) NOT NULL DEFAULT '(default)',
            firebase_api_key VARCHAR(180) NOT NULL DEFAULT '',
            firebase_app_id VARCHAR(180) NOT NULL DEFAULT '',
            firebase_messaging_sender_id VARCHAR(80) NOT NULL DEFAULT '',
            firebase_storage_bucket VARCHAR(180) NOT NULL DEFAULT '',
            android_package_name VARCHAR(160) NOT NULL,
            apk_download_path VARCHAR(500) NOT NULL DEFAULT '',
            splash_screen_image_url_1 TEXT NULL,
            splash_screen_image_url_2 TEXT NULL,
            splash_screen_image_url_3 TEXT NULL,
            splash_screen_image_url_4 TEXT NULL,
            current_version_code INT UNSIGNED NOT NULL DEFAULT 1,
            min_supported_version_code INT UNSIGNED NOT NULL DEFAULT 1,
            force_update_enabled TINYINT(1) NOT NULL DEFAULT 0,
            release_acknowledgement_required TINYINT(1) NOT NULL DEFAULT 1,
            geofence_required TINYINT(1) NOT NULL DEFAULT 0,
            offline_queue_enabled TINYINT(1) NOT NULL DEFAULT 1,
            offline_retry_interval_seconds INT UNSIGNED NOT NULL DEFAULT 300,
            dashboard_refresh_seconds INT UNSIGNED NOT NULL DEFAULT 60,
            media_upload_enabled TINYINT(1) NOT NULL DEFAULT 0,
            client_app_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_android_client_app_status (client_app_status, client_app_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_position (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            position_key CHAR(36) NOT NULL UNIQUE,
            position_code VARCHAR(80) NOT NULL UNIQUE,
            position_name VARCHAR(160) NOT NULL UNIQUE,
            group_key CHAR(36) NULL,
            position_description TEXT NULL,
            position_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_user_position_group (group_key),
            INDEX idx_builder_user_position_status (position_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    bx_add_column_if_missing('builder_user_position', 'group_key', 'CHAR(36) NULL AFTER position_name');
    bx_add_index_if_missing('builder_user_position', 'idx_builder_user_position_group', 'INDEX idx_builder_user_position_group (group_key)');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL UNIQUE,
            user_login VARCHAR(80) NOT NULL UNIQUE,
            user_password_hash VARCHAR(255) NOT NULL,
            user_name VARCHAR(160) NOT NULL,
            user_email VARCHAR(190) NOT NULL UNIQUE,
            position_key CHAR(36) NULL,
            user_status ENUM('DRAFT','ACTIVE','INACTIVE','LOCKED','DELETED') NOT NULL DEFAULT 'DRAFT',
            user_failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
            user_password_changed_at TIMESTAMP NULL,
            user_password_expires_at TIMESTAMP NULL,
            user_email_verified_at TIMESTAMP NULL,
            user_two_factor_required TINYINT(1) NOT NULL DEFAULT 0,
            user_recovery_codes_enabled TINYINT(1) NOT NULL DEFAULT 0,
            user_last_login_at TIMESTAMP NULL,
            server_timestamp TIMESTAMP NULL,
            user_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_created_by_key CHAR(36) NULL,
            user_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            user_updated_by_key CHAR(36) NULL,
            user_deleted_at TIMESTAMP NULL,
            user_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_user_status (user_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    bx_add_column_if_missing('builder_user', 'user_password_expires_at', 'TIMESTAMP NULL AFTER user_password_changed_at');
    bx_add_column_if_missing('builder_user', 'user_email_verified_at', 'TIMESTAMP NULL AFTER user_password_expires_at');
    bx_add_column_if_missing('builder_user', 'user_two_factor_required', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER user_email_verified_at');
    bx_add_column_if_missing('builder_user', 'user_recovery_codes_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER user_two_factor_required');
    bx_add_column_if_missing('builder_user', 'position_key', 'CHAR(36) NULL AFTER user_email');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_group (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_key CHAR(36) NOT NULL UNIQUE,
            group_name VARCHAR(120) NOT NULL UNIQUE,
            group_description TEXT NULL,
            group_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS project_user_group (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            group_key CHAR(36) NOT NULL UNIQUE,
            project_key CHAR(36) NOT NULL,
            group_name VARCHAR(120) NOT NULL,
            group_description TEXT NULL,
            group_image_path VARCHAR(500) NULL,
            group_image_original_name VARCHAR(255) NULL,
            group_image_mime_type VARCHAR(120) NULL,
            group_image_byte_size BIGINT UNSIGNED NULL,
            group_image_sha256 CHAR(64) NULL,
            group_image_uploaded_at TIMESTAMP NULL,
            group_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_project_user_group_name (project_key, group_name),
            KEY idx_project_user_group_project (project_key),
            KEY idx_project_user_group_status (group_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ((int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
        [BUILDERX_DB_NAME, 'project_user_group', 'uq_project_user_group_name']
    ) > 0) {
        $db->Execute('ALTER TABLE project_user_group DROP INDEX uq_project_user_group_name');
    }
    bx_add_index_if_missing('project_user_group', 'idx_project_user_group_name', 'INDEX idx_project_user_group_name (project_key, group_name)');
    bx_add_column_if_missing('project_user_group', 'group_image_path', 'VARCHAR(500) NULL AFTER group_description');
    bx_add_column_if_missing('project_user_group', 'group_image_original_name', 'VARCHAR(255) NULL AFTER group_image_path');
    bx_add_column_if_missing('project_user_group', 'group_image_mime_type', 'VARCHAR(120) NULL AFTER group_image_original_name');
    bx_add_column_if_missing('project_user_group', 'group_image_byte_size', 'BIGINT UNSIGNED NULL AFTER group_image_mime_type');
    bx_add_column_if_missing('project_user_group', 'group_image_sha256', 'CHAR(64) NULL AFTER group_image_byte_size');
    bx_add_column_if_missing('project_user_group', 'group_image_uploaded_at', 'TIMESTAMP NULL AFTER group_image_sha256');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS project_user_position (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            position_key CHAR(36) NOT NULL UNIQUE,
            project_key CHAR(36) NOT NULL,
            group_key CHAR(36) NOT NULL,
            position_code VARCHAR(80) NOT NULL,
            position_name VARCHAR(160) NOT NULL,
            position_description TEXT NULL,
            position_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_user_position_code (project_key, position_code),
            UNIQUE KEY uq_project_user_position_name (project_key, position_name),
            KEY idx_project_user_position_project (project_key),
            KEY idx_project_user_position_group (group_key),
            KEY idx_project_user_position_status (position_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS project_user (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL UNIQUE,
            project_key CHAR(36) NOT NULL,
            group_key CHAR(36) NULL,
            position_key CHAR(36) NULL,
            user_login VARCHAR(80) NOT NULL,
            user_password_hash VARCHAR(255) NOT NULL,
            user_name VARCHAR(160) NOT NULL,
            user_chat_name VARCHAR(160) NULL,
            user_email VARCHAR(190) NULL,
            user_mobile_number VARCHAR(40) NULL,
            user_avatar_path VARCHAR(500) NULL,
            user_avatar_original_name VARCHAR(255) NULL,
            user_avatar_mime_type VARCHAR(120) NULL,
            user_avatar_byte_size BIGINT UNSIGNED NULL,
            user_avatar_sha256 CHAR(64) NULL,
            user_avatar_uploaded_at TIMESTAMP NULL,
            user_status ENUM('DRAFT','ACTIVE','INACTIVE','LOCKED','DELETED') NOT NULL DEFAULT 'DRAFT',
            user_failed_login_count INT UNSIGNED NOT NULL DEFAULT 0,
            user_password_changed_at TIMESTAMP NULL,
            user_last_login_at TIMESTAMP NULL,
            user_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_created_by_key CHAR(36) NULL,
            user_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            user_updated_by_key CHAR(36) NULL,
            user_deleted_at TIMESTAMP NULL,
            user_deleted_by_key CHAR(36) NULL,
            UNIQUE KEY uq_project_user_login (project_key, user_login),
            UNIQUE KEY uq_project_user_email (project_key, user_email),
            UNIQUE KEY uq_project_user_mobile (project_key, user_mobile_number),
            KEY idx_project_user_project (project_key),
            KEY idx_project_user_group (group_key),
            KEY idx_project_user_position (position_key),
            KEY idx_project_user_status (user_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    bx_add_column_if_missing('project_user', 'user_chat_name', 'VARCHAR(160) NULL AFTER user_name');
    bx_add_column_if_missing('project_user', 'user_mobile_number', 'VARCHAR(40) NULL AFTER user_email');
    bx_add_column_if_missing('project_user', 'user_avatar_path', 'VARCHAR(500) NULL AFTER user_mobile_number');
    bx_add_column_if_missing('project_user', 'user_avatar_original_name', 'VARCHAR(255) NULL AFTER user_avatar_path');
    bx_add_column_if_missing('project_user', 'user_avatar_mime_type', 'VARCHAR(120) NULL AFTER user_avatar_original_name');
    bx_add_column_if_missing('project_user', 'user_avatar_byte_size', 'BIGINT UNSIGNED NULL AFTER user_avatar_mime_type');
    bx_add_column_if_missing('project_user', 'user_avatar_sha256', 'CHAR(64) NULL AFTER user_avatar_byte_size');
    bx_add_column_if_missing('project_user', 'user_avatar_uploaded_at', 'TIMESTAMP NULL AFTER user_avatar_sha256');
    $db->Execute('ALTER TABLE project_user MODIFY user_email VARCHAR(190) NULL');
    bx_add_index_if_missing('project_user', 'uq_project_user_mobile', 'UNIQUE KEY uq_project_user_mobile (project_key, user_mobile_number)');

    if ($schemaTableExists('project_group')) {
        $db->Execute("
            INSERT IGNORE INTO project_user_group (
                group_key,
                project_key,
                group_name,
                group_description,
                group_status,
                created_at,
                updated_at
            )
            SELECT
                group_key,
                project_key,
                group_name,
                group_description,
                group_status,
                created_at,
                updated_at
            FROM project_group
        ");
    }

    if ($schemaTableExists('project_position')) {
        $db->Execute("
            INSERT IGNORE INTO project_user_position (
                position_key,
                project_key,
                group_key,
                position_code,
                position_name,
                position_description,
                position_status,
                created_at,
                updated_at
            )
            SELECT
                position_key,
                project_key,
                group_key,
                position_code,
                position_name,
                position_description,
                position_status,
                created_at,
                updated_at
            FROM project_position
        ");
    }

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_role (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_key CHAR(36) NOT NULL UNIQUE,
            role_name VARCHAR(120) NOT NULL UNIQUE,
            role_description TEXT NULL,
            role_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_permission (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            permission_key CHAR(36) NOT NULL UNIQUE,
            permission_code VARCHAR(120) NOT NULL UNIQUE,
            permission_name VARCHAR(160) NOT NULL,
            permission_scope VARCHAR(60) NOT NULL,
            permission_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_group (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            group_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_group (user_key, group_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_role (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            role_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_role (user_key, role_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_role_permission (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_key CHAR(36) NOT NULL,
            permission_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_role_permission (role_key, permission_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_branch (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            branch_key CHAR(36) NOT NULL UNIQUE,
            branch_name VARCHAR(160) NOT NULL,
            branch_code VARCHAR(40) NOT NULL UNIQUE,
            branch_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
            branch_address TEXT NULL,
            branch_contact TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_branch_status (branch_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_project (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            project_key CHAR(36) NOT NULL UNIQUE,
            branch_key CHAR(36) NOT NULL,
            project_name VARCHAR(160) NOT NULL,
            project_code VARCHAR(40) NOT NULL UNIQUE,
            project_status ENUM('DRAFT','ACTIVE','INACTIVE','ARCHIVED','DELETED') NOT NULL DEFAULT 'ACTIVE',
            project_description TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_project_branch (branch_key),
            INDEX idx_builder_project_status (project_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_branch (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            branch_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_branch (user_key, branch_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_project (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL,
            project_key CHAR(36) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_builder_user_project (user_key, project_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_session (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            session_token_hash CHAR(64) NOT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            session_status ENUM('ACTIVE','REVOKED','EXPIRED') NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            INDEX idx_builder_user_session_user (user_key),
            INDEX idx_builder_user_session_status (session_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_password_reset (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reset_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            reset_token_hash CHAR(64) NOT NULL UNIQUE,
            reset_status ENUM('PENDING','USED','EXPIRED','REVOKED') NOT NULL DEFAULT 'PENDING',
            requested_ip VARCHAR(80) NULL,
            requested_user_agent VARCHAR(255) NULL,
            used_ip VARCHAR(80) NULL,
            used_user_agent VARCHAR(255) NULL,
            email_delivery_status ENUM('PENDING','QUEUED','SENT','FAILED','PLACEHOLDER') NOT NULL DEFAULT 'PLACEHOLDER',
            email_verification_required TINYINT(1) NOT NULL DEFAULT 1,
            two_factor_required TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            used_at TIMESTAMP NULL,
            INDEX idx_builder_password_reset_user (user_key),
            INDEX idx_builder_password_reset_status (reset_status),
            INDEX idx_builder_password_reset_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_password_history (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            history_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            changed_by_key CHAR(36) NULL,
            change_reason VARCHAR(120) NOT NULL DEFAULT 'password-reset',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_password_history_user (user_key),
            INDEX idx_builder_password_history_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_user_login_history (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            login_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NULL,
            user_login VARCHAR(120) NULL,
            login_status ENUM('SUCCESS','FAILED','LOCKED') NOT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            failure_reason VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_login_user (user_key),
            INDEX idx_builder_login_status (login_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_audit_log (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            audit_key CHAR(36) NOT NULL UNIQUE,
            user_key CHAR(36) NULL,
            action VARCHAR(80) NOT NULL,
            module VARCHAR(80) NOT NULL,
            record_key CHAR(36) NULL,
            previous_values LONGTEXT NULL,
            new_values LONGTEXT NULL,
            ip_address VARCHAR(80) NULL,
            user_agent VARCHAR(255) NULL,
            reason TEXT NULL,
            branch_key CHAR(36) NULL,
            project_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_builder_audit_user (user_key),
            INDEX idx_builder_audit_action (action),
            INDEX idx_builder_audit_module (module),
            INDEX idx_builder_audit_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_key CHAR(36) NOT NULL UNIQUE,
            owner_user_key CHAR(36) NOT NULL,
            first_name VARCHAR(80) NOT NULL,
            middle_name VARCHAR(80) NULL,
            last_name VARCHAR(80) NOT NULL,
            suffix VARCHAR(40) NULL,
            birth_date DATE NULL,
            relationship_to_user VARCHAR(80) NOT NULL,
            contact_email VARCHAR(190) NULL,
            contact_phone VARCHAR(40) NULL,
            consent_privacy TINYINT(1) NOT NULL DEFAULT 0,
            consent_contact TINYINT(1) NOT NULL DEFAULT 0,
            member_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            member_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            member_created_by_key CHAR(36) NULL,
            member_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            member_updated_by_key CHAR(36) NULL,
            member_deleted_at TIMESTAMP NULL,
            member_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_member_owner (owner_user_key),
            INDEX idx_builder_family_member_status (member_status),
            INDEX idx_builder_family_member_lookup (owner_user_key, last_name, first_name, birth_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member_vehicle (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_key CHAR(36) NOT NULL UNIQUE,
            member_key CHAR(36) NOT NULL,
            owner_user_key CHAR(36) NOT NULL,
            plate_number VARCHAR(40) NOT NULL,
            make VARCHAR(80) NULL,
            model VARCHAR(80) NULL,
            model_year SMALLINT UNSIGNED NULL,
            color VARCHAR(60) NULL,
            ownership_type VARCHAR(80) NOT NULL,
            registration_status VARCHAR(80) NULL,
            vehicle_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            vehicle_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            vehicle_created_by_key CHAR(36) NULL,
            vehicle_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            vehicle_updated_by_key CHAR(36) NULL,
            vehicle_deleted_at TIMESTAMP NULL,
            vehicle_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_vehicle_member (member_key),
            INDEX idx_builder_family_vehicle_owner (owner_user_key),
            INDEX idx_builder_family_vehicle_plate (owner_user_key, plate_number),
            INDEX idx_builder_family_vehicle_status (vehicle_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_family_member_education (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            education_key CHAR(36) NOT NULL UNIQUE,
            member_key CHAR(36) NOT NULL,
            owner_user_key CHAR(36) NOT NULL,
            education_level VARCHAR(80) NOT NULL,
            institution_name VARCHAR(190) NOT NULL,
            program_name VARCHAR(190) NULL,
            date_started DATE NULL,
            date_completed DATE NULL,
            completion_status VARCHAR(80) NOT NULL,
            education_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            education_created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            education_created_by_key CHAR(36) NULL,
            education_updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            education_updated_by_key CHAR(36) NULL,
            education_deleted_at TIMESTAMP NULL,
            education_deleted_by_key CHAR(36) NULL,
            INDEX idx_builder_family_education_member (member_key),
            INDEX idx_builder_family_education_owner (owner_user_key),
            INDEX idx_builder_family_education_lookup (owner_user_key, education_level, institution_name),
            INDEX idx_builder_family_education_status (education_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_task (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(128) NOT NULL UNIQUE,
            correlation_id VARCHAR(128) NOT NULL,
            parent_task_key VARCHAR(128) NULL,
            action VARCHAR(80) NOT NULL,
            stage ENUM('Think','Design','Build','Validate','Document','Preserve') NOT NULL,
            specialist VARCHAR(80) NOT NULL,
            task_status ENUM('queued','running','awaiting_approval','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
            input_json LONGTEXT NOT NULL,
            output_json LONGTEXT NULL,
            error_json LONGTEXT NULL,
            permissions_json TEXT NOT NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_by_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_task_correlation (correlation_id),
            INDEX idx_builder_ai_task_status (task_status),
            INDEX idx_builder_ai_task_owner (created_by_key),
            INDEX idx_builder_ai_task_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_specialist (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            specialist_key VARCHAR(128) NOT NULL UNIQUE,
            specialist_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
            specialist_name VARCHAR(120) NOT NULL,
            purpose TEXT NOT NULL,
            stages_json TEXT NOT NULL,
            skills_json TEXT NOT NULL,
            allowed_tools_json TEXT NOT NULL,
            write_scope ENUM('none','communication_only','build_allowlist','phase_manager_approval') NOT NULL DEFAULT 'none',
            rag_scopes_json TEXT NOT NULL,
            specialist_status ENUM('pending_approval','active','inactive','retired') NOT NULL DEFAULT 'pending_approval',
            review_status ENUM('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
            approval_reference VARCHAR(128) NULL,
            is_temporary TINYINT(1) NOT NULL DEFAULT 0,
            owner_user_key CHAR(36) NULL,
            evidence_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_specialist_status (specialist_status),
            INDEX idx_builder_ai_specialist_review (review_status),
            INDEX idx_builder_ai_specialist_owner (owner_user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_approval (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            approval_key VARCHAR(128) NOT NULL UNIQUE,
            operation ENUM('delete','move','database','backup','audit') NOT NULL,
            target VARCHAR(1024) NOT NULL,
            target_hash VARCHAR(128) NOT NULL,
            actor_user_key CHAR(36) NULL,
            approval_status ENUM('pending','approved','consumed','expired','rejected') NOT NULL DEFAULT 'pending',
            approved_by_key CHAR(36) NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            consumed_at TIMESTAMP NULL,
            INDEX idx_builder_ai_approval_status (approval_status),
            INDEX idx_builder_ai_approval_expiry (expires_at),
            INDEX idx_builder_ai_approval_actor (actor_user_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute('ALTER TABLE builder_ai_approval MODIFY COLUMN expires_at DATETIME NOT NULL');

    $db->Execute("
        CREATE TABLE IF NOT EXISTS builder_ai_memory (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            memory_key VARCHAR(128) NOT NULL UNIQUE,
            memory_version INT UNSIGNED NOT NULL DEFAULT 1,
            title VARCHAR(240) NOT NULL,
            content LONGTEXT NOT NULL,
            memory_type ENUM('brand_rule','decision','instruction','example','task_result','reference') NOT NULL,
            retrieval_types_json TEXT NOT NULL,
            tags_json TEXT NOT NULL,
            metadata_json LONGTEXT NOT NULL,
            source_reference VARCHAR(512) NULL,
            parent_memory_key VARCHAR(128) NULL,
            memory_status ENUM('pending_approval','approved','archived','rejected') NOT NULL DEFAULT 'pending_approval',
            review_status ENUM('unreviewed','approved','rejected','needs_revision') NOT NULL DEFAULT 'unreviewed',
            vault_path VARCHAR(512) NULL,
            owner_user_key CHAR(36) NULL,
            approved_by_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_builder_ai_memory_status (memory_status),
            INDEX idx_builder_ai_memory_type (memory_type),
            INDEX idx_builder_ai_memory_parent (parent_memory_key),
            INDEX idx_builder_ai_memory_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_narrative_draft (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            draft_key CHAR(36) NOT NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            product_goal LONGTEXT NOT NULL,
            users_and_roles LONGTEXT NOT NULL,
            main_user_journey LONGTEXT NOT NULL,
            web_requirements LONGTEXT NOT NULL,
            android_requirements LONGTEXT NOT NULL,
            database_and_synchronization LONGTEXT NOT NULL,
            security_and_permissions LONGTEXT NOT NULL,
            validation_and_error_handling LONGTEXT NOT NULL,
            open_questions LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_narrative_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $backupTableCreated = $db->Execute('CREATE TABLE IF NOT EXISTS phase_builder_narrative_draft_backup LIKE phase_builder_narrative_draft');
    if ($backupTableCreated === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Tab 1 backup table setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    foreach (['phase_builder_narrative_draft', 'phase_builder_narrative_draft_backup'] as $narrativeTable) {
        bx_add_column_if_missing($narrativeTable, 'draft_key', 'CHAR(36) NULL');
        $legacyDraftRows = $db->GetAll(
            "SELECT x_id FROM {$narrativeTable} WHERE draft_key IS NULL OR draft_key = '' ORDER BY x_id"
        );
        foreach ($legacyDraftRows as $legacyDraftRow) {
            $backfilledDraftKey = bx_uuid();
            $backfilled = $db->Execute(
                "UPDATE {$narrativeTable} SET draft_key = ? WHERE x_id = ?",
                [$backfilledDraftKey, (int) $legacyDraftRow['x_id']]
            );
            if ($backfilled === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Builder draft identity backfill failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }
        }
        $draftKeyRequired = $db->Execute("ALTER TABLE {$narrativeTable} MODIFY draft_key CHAR(36) NOT NULL");
        if ($draftKeyRequired === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Builder draft identity schema update failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        $phaseKeyNullable = (string) $db->GetOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, $narrativeTable, 'phase_key']
        );
        if ($phaseKeyNullable === 'NO') {
            $madeNullable = $db->Execute("ALTER TABLE {$narrativeTable} MODIFY phase_key CHAR(36) NULL");
            if ($madeNullable === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Tab 1 standalone draft schema update failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }
        }
        $normalizedEmptyKeys = $db->Execute("UPDATE {$narrativeTable} SET phase_key = NULL WHERE phase_key = ''");
        if ($normalizedEmptyKeys === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Tab 1 standalone draft key normalization failed for ' . $narrativeTable . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        bx_add_unique_index_if_missing($narrativeTable, 'draft_key', "uq_{$narrativeTable}_draft_key", "UNIQUE KEY uq_{$narrativeTable}_draft_key (draft_key)");
    }

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_requirements_analysis (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            analysis_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_narrative_hash CHAR(64) NOT NULL,
            analysis_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_requirements_updated (updated_at),
            INDEX idx_phase_builder_requirements_source (source_narrative_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_system_architecture (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            architecture_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_requirements_hash CHAR(64) NOT NULL,
            architecture_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_architecture_updated (updated_at),
            INDEX idx_phase_builder_architecture_source (source_requirements_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_ui_ux_design (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ui_ux_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_architecture_hash CHAR(64) NOT NULL,
            ui_ux_json LONGTEXT NOT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_ui_ux_updated (updated_at),
            INDEX idx_phase_builder_ui_ux_source (source_architecture_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_execution_roadmap (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            roadmap_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NULL UNIQUE,
            phase_key CHAR(36) NULL UNIQUE,
            source_architecture_hash CHAR(64) NOT NULL,
            roadmap_json LONGTEXT NOT NULL,
            progress_json LONGTEXT NOT NULL,
            stages_json LONGTEXT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_roadmap_updated (updated_at),
            INDEX idx_phase_builder_roadmap_source (source_architecture_hash)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_messages (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            message_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            sender VARCHAR(20) NOT NULL DEFAULT 'user',
            message_text TEXT NOT NULL,
            message_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            edited_at TIMESTAMP NULL,
            deleted_at TIMESTAMP NULL,
            created_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_todo_chat_scope (draft_key, todo_id, message_status),
            INDEX idx_phase_builder_todo_chat_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_attachments (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            attachment_key CHAR(36) NOT NULL UNIQUE,
            message_key CHAR(36) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL,
            byte_size INT UNSIGNED NOT NULL,
            storage_path VARCHAR(500) NULL,
            sha256 CHAR(64) NULL,
            attachment_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phase_builder_todo_chat_attachment_message (message_key, attachment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    foreach ([
        'storage_path' => 'ALTER TABLE phase_builder_todo_chat_attachments ADD COLUMN storage_path VARCHAR(500) NULL AFTER byte_size',
        'sha256' => 'ALTER TABLE phase_builder_todo_chat_attachments ADD COLUMN sha256 CHAR(64) NULL AFTER storage_path',
    ] as $attachmentColumn => $attachmentAlter) {
        $attachmentColumnExists = (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'phase_builder_todo_chat_attachments', $attachmentColumn]
        );
        if ($attachmentColumnExists === 0) {
            $assertAiSchema($db->Execute($attachmentAlter), 'phase_builder_todo_chat_attachments.' . $attachmentColumn);
        }
    }
    $attachmentDataUrlExists = (int) $db->GetOne(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'phase_builder_todo_chat_attachments', 'data_url']
    );
    if ($attachmentDataUrlExists === 1) {
        $legacyAttachments = $db->GetAll(
            "SELECT attachment_key, message_key, mime_type, data_url FROM phase_builder_todo_chat_attachments WHERE data_url IS NOT NULL AND TRIM(data_url) <> '' AND (storage_path IS NULL OR TRIM(storage_path) = '')"
        );
        foreach ($legacyAttachments as $legacyAttachment) {
            $attachmentKey = trim((string) ($legacyAttachment['attachment_key'] ?? ''));
            $messageKey = trim((string) ($legacyAttachment['message_key'] ?? ''));
            $mimeType = strtolower(trim((string) ($legacyAttachment['mime_type'] ?? '')));
            $dataUrl = trim((string) ($legacyAttachment['data_url'] ?? ''));
            $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (
                preg_match('/^[A-Za-z0-9-]{1,64}$/', $attachmentKey) !== 1
                || preg_match('/^[A-Za-z0-9-]{1,64}$/', $messageKey) !== 1
                || !isset($extensions[$mimeType])
                || preg_match('/^data:' . preg_quote($mimeType, '/') . ';base64,/', $dataUrl) !== 1
            ) {
                throw new RuntimeException('A legacy todo chat image could not be migrated from MySQL.');
            }
            $binary = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
            if (!is_string($binary) || $binary === '' || strlen($binary) > 5242880) {
                throw new RuntimeException('A legacy todo chat image has invalid content.');
            }
            $relativeDirectory = '_Document/attachments/todo-chat/' . $messageKey;
            $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
            if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
                throw new RuntimeException('The legacy todo chat image directory could not be created.');
            }
            $relativePath = $relativeDirectory . '/' . $attachmentKey . '.' . $extensions[$mimeType];
            $absolutePath = dirname(__DIR__) . '/' . $relativePath;
            $temporaryPath = $absoluteDirectory . '/.' . $attachmentKey . '.migration';
            if (file_put_contents($temporaryPath, $binary, LOCK_EX) !== strlen($binary) || !rename($temporaryPath, $absolutePath)) {
                @unlink($temporaryPath);
                throw new RuntimeException('A legacy todo chat image could not be written to _Document.');
            }
            chmod($absolutePath, 0644);
            $assertAiSchema(
                $db->Execute('UPDATE phase_builder_todo_chat_attachments SET storage_path = ?, sha256 = ? WHERE attachment_key = ?', [$relativePath, hash('sha256', $binary), $attachmentKey]),
                'phase_builder_todo_chat_attachments legacy image migration'
            );
        }
        $unmigratedAttachmentCount = (int) $db->GetOne(
            "SELECT COUNT(*) FROM phase_builder_todo_chat_attachments WHERE data_url IS NOT NULL AND TRIM(data_url) <> '' AND (storage_path IS NULL OR TRIM(storage_path) = '')"
        );
        if ($unmigratedAttachmentCount !== 0) {
            throw new RuntimeException('Legacy todo chat images remain in MySQL after migration.');
        }
        $assertAiSchema($db->Execute('ALTER TABLE phase_builder_todo_chat_attachments DROP COLUMN data_url'), 'phase_builder_todo_chat_attachments.data_url removal');
    }
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_chat_consolidations (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            consolidation_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            context_json LONGTEXT NOT NULL,
            ai_result_json LONGTEXT NULL,
            approval_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
            created_by_user_key CHAR(36) NULL,
            approved_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            approved_at TIMESTAMP NULL,
            INDEX idx_phase_builder_todo_consolidation_scope (draft_key, todo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->Execute("
        CREATE TABLE IF NOT EXISTS phase_builder_todo_execution_logs (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_key CHAR(36) NOT NULL UNIQUE,
            draft_key CHAR(36) NOT NULL,
            phase_id VARCHAR(160) NOT NULL,
            task_id VARCHAR(200) NOT NULL,
            subtask_id VARCHAR(200) NOT NULL,
            todo_id VARCHAR(200) NOT NULL,
            context_json LONGTEXT NOT NULL,
            source_checkpoint_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'RUNNING',
            rollback_status VARCHAR(20) NOT NULL DEFAULT 'NOT_REQUESTED',
            rollback_source_checkpoint_json LONGTEXT NULL,
            rollback_result_json LONGTEXT NULL,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            rolled_back_at TIMESTAMP NULL,
            INDEX idx_phase_builder_todo_exec_scope (draft_key, todo_id, created_at),
            INDEX idx_phase_builder_todo_exec_status (status, rollback_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    bx_add_column_if_missing('phase_builder_todo_execution_logs', 'source_checkpoint_json', 'LONGTEXT NULL AFTER context_json');
    bx_add_column_if_missing('phase_builder_todo_execution_logs', 'rollback_source_checkpoint_json', 'LONGTEXT NULL AFTER rollback_status');
    bx_ensure_bed_master_list_schema();
    bx_ensure_project_task_schema();
    bx_ensure_project_task_stage_schema();

    foreach (['phase_builder_requirements_analysis', 'phase_builder_system_architecture', 'phase_builder_ui_ux_design', 'phase_builder_execution_roadmap'] as $artifactTable) {
        bx_add_column_if_missing($artifactTable, 'draft_key', 'CHAR(36) NULL');
        $phaseKeyNullable = (string) $db->GetOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, $artifactTable, 'phase_key']
        );
        if ($phaseKeyNullable === 'NO') {
            $db->Execute("ALTER TABLE {$artifactTable} MODIFY phase_key CHAR(36) NULL");
        }
        $db->Execute("UPDATE {$artifactTable} artifact INNER JOIN phase_builder_narrative_draft draft ON draft.phase_key = artifact.phase_key SET artifact.draft_key = draft.draft_key WHERE artifact.draft_key IS NULL AND artifact.phase_key IS NOT NULL");
        bx_add_unique_index_if_missing($artifactTable, 'draft_key', "uq_{$artifactTable}_draft_key", "UNIQUE KEY uq_{$artifactTable}_draft_key (draft_key)");
    }
    bx_add_column_if_missing('phase_builder_execution_roadmap', 'stages_json', 'LONGTEXT NULL');
    bx_backup_phase_builder_narrative_draft();

    bx_seed_foundation();
}

function bx_android_project_setting_defaults(): array
{
    return [
        ['android_app_package_name', 'com.everythingiscreated.rbmsv4', 'android'],
        ['android_tenant_configuration_endpoint_url', 'http://localhost/rbms.com/api/mobile/tenant-configuration/', 'android'],
        ['android_current_version_code', '1', 'android'],
        ['android_min_supported_version_code', '1', 'android'],
        ['android_force_update_enabled', '0', 'android'],
        ['android_release_acknowledgement_required', '1', 'android'],
        ['android_geofence_required', '0', 'android'],
        ['android_update_apk_download_path', '/downloads/rbmsv4-latest.apk', 'android'],
        ['android_splash_screen_image_url_1', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/splash/splash-1.jpg', 'android'],
        ['android_splash_screen_image_url_2', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/splash/splash-2.jpg', 'android'],
        ['android_splash_screen_image_url_3', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/splash/splash-3.jpg', 'android'],
        ['android_splash_screen_image_url_4', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/splash/splash-4.jpg', 'android'],
        ['android_offline_queue_enabled', '1', 'android'],
        ['android_offline_retry_interval_seconds', '300', 'android'],
        ['android_dashboard_refresh_seconds', '60', 'android'],
        ['android_media_upload_enabled', '0', 'android'],
    ];
}

function bx_seed_android_project_settings(): void
{
    $db = bx_db();
    $legacyRows = $db->GetAll("SELECT setting_name, setting_value FROM builder_system_setting WHERE setting_group = 'android'") ?: [];
    $legacyValues = [];
    foreach ($legacyRows as $legacyRow) {
        $legacyValues[(string) ($legacyRow['setting_name'] ?? '')] = (string) ($legacyRow['setting_value'] ?? '');
    }

    $projects = $db->GetAll("SELECT project_key FROM builder_project WHERE project_status <> 'DELETED'") ?: [];
    foreach ($projects as $project) {
        $projectKey = (string) ($project['project_key'] ?? '');
        if ($projectKey === '') {
            continue;
        }

        foreach (bx_android_project_setting_defaults() as $setting) {
            $settingName = (string) $setting[0];
            if ((int) $db->GetOne('SELECT COUNT(*) FROM project_setting WHERE project_key = ? AND setting_name = ?', [$projectKey, $settingName]) > 0) {
                continue;
            }

            $settingValue = array_key_exists($settingName, $legacyValues) ? $legacyValues[$settingName] : (string) $setting[1];
            $db->Execute(
                'INSERT INTO project_setting (setting_key, project_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?, ?)',
                [bx_uuid(), $projectKey, $settingName, $settingValue, (string) $setting[2]]
            );
        }
    }
}

function bx_seed_foundation(): void
{
    $db = bx_db();
    if ((int) $db->GetOne('SELECT COUNT(*) FROM builder_phase') === 0) {
        $db->Execute(
            'INSERT INTO builder_phase (phase_key, phase_number, phase_code, phase_title, phase_summary, phase_status, phase_sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [bx_uuid(), 1, 'P1', 'Current Project', 'Initial project workspace phase.', 'In Progress', 1]
        );
    }
    $settings = [
        ['software_name', 'BuilderX', 'general'],
        ['software_description', 'Dynamic Enterprise Form, Workflow, Reporting, and Accounting Builder', 'general'],
        ['version', '0.1.0-foundation', 'general'],
        ['default_time_zone', 'Asia/Manila', 'localization'],
        ['default_language', 'en', 'localization'],
        ['default_currency', 'PHP', 'localization'],
        ['session_timeout_minutes', '120', 'security'],
        ['password_min_length', '10', 'security'],
        ['password_reset_token_minutes', '30', 'security'],
        ['password_history_count', '3', 'security'],
        ['password_expiration_days', '90', 'security'],
        ['account_recovery_email_delivery', 'placeholder', 'security'],
        ['account_recovery_2fa_policy', 'optional-planned', 'security'],
        ['media_uploader_target_url', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/upload-image.php', 'media'],
        ['media_image_viewer_url', 'http://localhost/rbms.com/_Mobile/rbmsv4-vrp/view.php', 'media'],
        ['firebase_project_id', 'rbmsv4-vrp', 'firebase'],
        ['firebase_messenger_server_sync_enabled', '0', 'firebase'],
        ['firebase_client_stream_enabled', '0', 'firebase'],
        ['firebase_client_write_enabled', '0', 'firebase'],
        ['firebase_service_account_path', '', 'firebase'],
        ['sharingan_enabled', '0', 'interface'],
        ['codex_chat_id', builderxConfigValue('codex_chat_id'), 'ai'],
    ];

    foreach ($settings as $setting) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = ?', [$setting[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group, is_secret) VALUES (?, ?, ?, ?, ?)',
                [bx_uuid(), $setting[0], $setting[1], $setting[2], $setting[0] === 'firebase_service_account_path' ? 1 : 0]
            );
        }
    }

    $androidClientApps = [
        [
            'RBMS-VRP',
            'RBMS VRP Demo',
            'rbmsv4-vrp',
            'https://rbmsv4-vrp-default-rtdb.asia-southeast1.firebasedatabase.app',
            '(default)',
            'sample-vrp-api-key',
            '1:100000000001:android:rbmsv4vrp001',
            '100000000001',
            'rbmsv4-vrp.appspot.com',
        ],
        [
            'RBMS-CAB',
            'RBMS CAB Demo',
            'rbmsv4-cab',
            'https://rbmsv4-cab-default-rtdb.asia-southeast1.firebasedatabase.app',
            '(default)',
            'sample-cab-api-key',
            '1:100000000002:android:rbmsv4cab002',
            '100000000002',
            'rbmsv4-cab.appspot.com',
        ],
    ];
    foreach ($androidClientApps as $clientApp) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_android_client_app WHERE client_app_code = ?', [$clientApp[0]]) === 0) {
            bx_db()->Execute(
                "INSERT INTO builder_android_client_app (
                    client_app_key,
                    client_app_code,
                    client_name,
                    firebase_project_id,
                    firebase_database_url,
                    firebase_firestore_database_id,
                    firebase_api_key,
                    firebase_app_id,
                    firebase_messaging_sender_id,
                    firebase_storage_bucket,
                    android_package_name,
                    apk_download_path,
                    splash_screen_image_url_1,
                    splash_screen_image_url_2,
                    splash_screen_image_url_3,
                    splash_screen_image_url_4
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    bx_uuid(),
                    $clientApp[0],
                    $clientApp[1],
                    $clientApp[2],
                    $clientApp[3],
                    $clientApp[4],
                    $clientApp[5],
                    $clientApp[6],
                    $clientApp[7],
                    $clientApp[8],
                    'com.everythingiscreated.rbmsv4',
                    '/downloads/rbmsv4-latest.apk',
                    'http://localhost/rbms.com/_Mobile/' . $clientApp[2] . '/splash/splash-1.jpg',
                    'http://localhost/rbms.com/_Mobile/' . $clientApp[2] . '/splash/splash-2.jpg',
                    'http://localhost/rbms.com/_Mobile/' . $clientApp[2] . '/splash/splash-3.jpg',
                    'http://localhost/rbms.com/_Mobile/' . $clientApp[2] . '/splash/splash-4.jpg',
                ]
            );
        }
    }

    foreach ([
        ['Administrators', 'Full system administration group.'],
        ['Encoders', 'Data entry group.'],
        ['Auditors', 'Read-only audit and report review group.'],
    ] as $group) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_group WHERE group_name = ?', [$group[0]]) === 0) {
            bx_db()->Execute('INSERT INTO builder_group (group_key, group_name, group_description) VALUES (?, ?, ?)', [bx_uuid(), $group[0], $group[1]]);
        }
    }

    foreach ([
        ['Administrator', 'Full system administration role.'],
        ['Branch Manager', 'Branch-level management role.'],
        ['Project User', 'Project-level application user role.'],
        ['Auditor', 'Audit and report review role.'],
    ] as $role) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role WHERE role_name = ?', [$role[0]]) === 0) {
            bx_db()->Execute('INSERT INTO builder_role (role_key, role_name, role_description) VALUES (?, ?, ?)', [bx_uuid(), $role[0], $role[1]]);
        }
    }

    foreach ([
        ['system.manage', 'Manage System', 'system'],
        ['settings.manage', 'Manage Settings', 'system'],
        ['audit.view', 'View Audit Logs', 'system'],
        ['users.manage', 'Manage Users', 'system'],
        ['permissions.manage', 'Manage Permissions', 'system'],
        ['branches.manage', 'Manage Branches', 'branch'],
        ['projects.manage', 'Manage Projects', 'project'],
        ['forms.manage', 'Manage Forms', 'form'],
        ['records.view', 'View Records', 'record'],
        ['records.create', 'Create Records', 'record'],
        ['records.update', 'Update Records', 'record'],
        ['records.delete', 'Soft Delete Records', 'record'],
        ['records.restore', 'Restore Records', 'record'],
        ['reports.manage', 'Manage Reports', 'report'],
        ['family_members.report', 'View Family Member Reports', 'report'],
        ['exports.run', 'Run Exports', 'action'],
    ] as $permission) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_permission WHERE permission_code = ?', [$permission[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_permission (permission_key, permission_code, permission_name, permission_scope) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $permission[0], $permission[1], $permission[2]]
            );
        }
    }

    if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_branch WHERE branch_code = ?', ['HO']) === 0) {
        bx_db()->Execute(
            'INSERT INTO builder_branch (branch_key, branch_name, branch_code, branch_address, branch_contact) VALUES (?, ?, ?, ?, ?)',
            [bx_uuid(), 'Head Office', 'HO', 'Default head office branch.', '']
        );
    }

    if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_project WHERE project_code = ?', ['CORE']) === 0) {
        $branchKey = (string) bx_db()->GetOne('SELECT branch_key FROM builder_branch WHERE branch_code = ?', ['HO']);
        bx_db()->Execute(
            'INSERT INTO builder_project (project_key, branch_key, project_name, project_code, project_description) VALUES (?, ?, ?, ?, ?)',
            [bx_uuid(), $branchKey, 'Core Platform', 'CORE', 'Default project for BuilderX foundation modules.']
        );
    }

    bx_seed_android_project_settings();

    $adminRole = (string) bx_db()->GetOne('SELECT role_key FROM builder_role WHERE role_name = ?', ['Administrator']);
    $permissions = bx_db()->GetAll('SELECT permission_key FROM builder_permission');
    foreach ($permissions as $permission) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ? AND permission_key = ?', [$adminRole, $permission['permission_key']]) === 0) {
            bx_db()->Execute('INSERT INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)', [$adminRole, $permission['permission_key']]);
        }
    }

    (new \BuilderX\AI\AiSpecialistRegistry())->ensureSystemSpecialists();
}

function bx_ensure_bed_master_list_schema(): void
{
    $db = bx_db();
    $tableExists = static function (string $tableName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, $tableName]
        ) === 1;
    };
    $columnExists = static function (string $columnName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'project_bed', $columnName]
        ) === 1;
    };

    if (!$tableExists('project_bed') && $tableExists('project_bed_list')) {
        $renamed = $db->Execute('RENAME TABLE project_bed_list TO project_bed');
        if ($renamed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Managed project bed table migration failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    } elseif (!$tableExists('project_bed') && $tableExists('bed_master_list')) {
        $renamed = $db->Execute('RENAME TABLE bed_master_list TO project_bed');
        if ($renamed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Managed project bed table migration failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bed_key VARCHAR(40) NOT NULL UNIQUE,
            bed_source_key VARCHAR(160) NOT NULL,
            source_table VARCHAR(64) NOT NULL DEFAULT 'RBMS_BedMasterlist',
            source_id INT UNSIGNED NULL,
            source_pk_psbeds VARCHAR(100) NULL,
            bed_no VARCHAR(100) NULL,
            branch_key VARCHAR(100) NULL,
            branch_name VARCHAR(100) NULL,
            building_key VARCHAR(100) NULL,
            building_name VARCHAR(100) NULL,
            floor_key VARCHAR(100) NULL,
            floor_name VARCHAR(100) NULL,
            nurse_station_key VARCHAR(100) NULL,
            nurse_station_name VARCHAR(100) NULL,
            room_key VARCHAR(100) NULL,
            room_class_key VARCHAR(100) NULL,
            room_class VARCHAR(100) NULL,
            source_bed_status_key VARCHAR(100) NULL,
            source_bed_status VARCHAR(100) NULL,
            managed_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
            sync_batch_key CHAR(36) NULL,
            first_synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_synced_at TIMESTAMP NULL,
            last_seen_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_bed_source (bed_source_key),
            INDEX idx_project_bed_status (managed_status),
            INDEX idx_project_bed_bed_no (bed_no),
            INDEX idx_project_bed_floor (branch_key, building_key, floor_key),
            INDEX idx_project_bed_sync (sync_batch_key, last_synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Managed project bed list schema setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    bx_add_index_if_missing('project_bed', 'idx_project_bed_source_pk_psbeds', 'INDEX idx_project_bed_source_pk_psbeds (source_pk_psbeds)');
    bx_add_index_if_missing('project_bed', 'idx_project_bed_source_status', 'INDEX idx_project_bed_source_status (source_bed_status, managed_status)');
    bx_add_index_if_missing('project_bed', 'idx_project_bed_location_lookup', 'INDEX idx_project_bed_location_lookup (branch_name, building_name, floor_name, nurse_station_name, room_key, room_class)');

    if ($columnExists('firebase_document_id')) {
        $indexes = $db->GetAll(
            'SELECT DISTINCT INDEX_NAME AS index_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'project_bed', 'firebase_document_id']
        ) ?: [];
        foreach ($indexes as $indexRow) {
            $indexName = (string) ($indexRow['index_name'] ?? '');
            if ($indexName === '' || $indexName === 'PRIMARY') {
                continue;
            }
            $droppedIndex = $db->Execute("ALTER TABLE project_bed DROP INDEX {$indexName}");
            if ($droppedIndex === false) {
                $databaseError = trim((string) $db->ErrorMsg());
                throw new RuntimeException('Managed project bed Firebase document id index cleanup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
            }
        }

        $droppedColumn = $db->Execute('ALTER TABLE project_bed DROP COLUMN firebase_document_id');
        if ($droppedColumn === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Managed project bed Firebase document id column cleanup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $bedKeyColumn = $db->GetRow(
        'SELECT DATA_TYPE AS data_type, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [BUILDERX_DB_NAME, 'project_bed', 'bed_key']
    );
    if (is_array($bedKeyColumn)
        && ((string) ($bedKeyColumn['data_type'] ?? '') !== 'varchar' || (int) ($bedKeyColumn['max_length'] ?? 0) < 40)) {
        $modifiedBedKey = $db->Execute('ALTER TABLE project_bed MODIFY bed_key VARCHAR(40) NOT NULL');
        if ($modifiedBedKey === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Managed project bed key storage setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $legacyRows = $db->GetAll("SELECT x_id FROM project_bed WHERE TRIM(bed_key) NOT REGEXP '^[A-Za-z0-9]{20}$' ORDER BY x_id ASC") ?: [];
    foreach ($legacyRows as $legacyRow) {
        $xId = (int) ($legacyRow['x_id'] ?? 0);
        if ($xId < 1) {
            continue;
        }
        $newBedKey = bx_unique_firebase_document_key('project_bed', 'bed_key');
        $updated = $db->Execute('UPDATE project_bed SET bed_key = ? WHERE x_id = ?', [$newBedKey, $xId]);
        if ($updated === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Managed project bed key Firebase document id migration failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    bx_ensure_project_bed_analytics_schema();
}

function bx_ensure_project_bed_analytics_schema(): void
{
    $db = bx_db();
    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed_analytics (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            analytics_key VARCHAR(40) NOT NULL UNIQUE,
            analytics_scope ENUM('SUMMARY','GROUP') NOT NULL DEFAULT 'GROUP',
            group_key VARCHAR(100) NOT NULL DEFAULT '',
            group_label VARCHAR(120) NOT NULL DEFAULT '',
            item_label VARCHAR(160) NOT NULL DEFAULT '',
            total_rows INT UNSIGNED NOT NULL DEFAULT 0,
            active_rows INT UNSIGNED NOT NULL DEFAULT 0,
            inactive_rows INT UNSIGNED NOT NULL DEFAULT 0,
            available_rows INT UNSIGNED NOT NULL DEFAULT 0,
            vacant_rows INT UNSIGNED NOT NULL DEFAULT 0,
            occupied_rows INT UNSIGNED NOT NULL DEFAULT 0,
            analytics_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
            sync_batch_key CHAR(36) NULL,
            last_computed_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_bed_analytics_scope (analytics_scope, group_key, analytics_status),
            INDEX idx_project_bed_analytics_sync (sync_batch_key, analytics_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project bed analytics schema setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }
}

function bx_project_bed_analytics_key(string $scope, string $groupKey, string $itemLabel): string
{
    return substr(sha1(strtoupper($scope) . '|' . $groupKey . '|' . $itemLabel), 0, 20);
}

function bx_project_bed_analytics_group_definitions(): array
{
    return [
        ['key' => 'managed_status', 'label' => 'Managed status', 'column' => 'managed_status'],
        ['key' => 'branch_name', 'label' => 'Branch', 'column' => 'branch_name'],
        ['key' => 'building_name', 'label' => 'Building', 'column' => 'building_name'],
        ['key' => 'floor_name', 'label' => 'Floor', 'column' => 'floor_name'],
        ['key' => 'nurse_station_name', 'label' => 'Nurse station', 'column' => 'nurse_station_name'],
        ['key' => 'room_key', 'label' => 'Room', 'column' => 'room_key'],
        ['key' => 'room_class', 'label' => 'Room class', 'column' => 'room_class'],
        ['key' => 'source_bed_status', 'label' => 'Bed status', 'column' => 'source_bed_status'],
    ];
}

function bx_upsert_project_bed_analytics_row(array $row): void
{
    $db = bx_db();
    $saved = $db->Execute(
        "
        INSERT INTO project_bed_analytics (
            analytics_key,
            analytics_scope,
            group_key,
            group_label,
            item_label,
            total_rows,
            active_rows,
            inactive_rows,
            available_rows,
            vacant_rows,
            occupied_rows,
            analytics_status,
            sync_batch_key,
            last_computed_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?, CURRENT_TIMESTAMP)
        ON DUPLICATE KEY UPDATE
            analytics_scope = VALUES(analytics_scope),
            group_key = VALUES(group_key),
            group_label = VALUES(group_label),
            item_label = VALUES(item_label),
            total_rows = VALUES(total_rows),
            active_rows = VALUES(active_rows),
            inactive_rows = VALUES(inactive_rows),
            available_rows = VALUES(available_rows),
            vacant_rows = VALUES(vacant_rows),
            occupied_rows = VALUES(occupied_rows),
            analytics_status = 'ACTIVE',
            sync_batch_key = VALUES(sync_batch_key),
            last_computed_at = CURRENT_TIMESTAMP
        ",
        [
            (string) ($row['analytics_key'] ?? ''),
            (string) ($row['analytics_scope'] ?? 'GROUP'),
            (string) ($row['group_key'] ?? ''),
            (string) ($row['group_label'] ?? ''),
            (string) ($row['item_label'] ?? ''),
            max(0, (int) ($row['total_rows'] ?? 0)),
            max(0, (int) ($row['active_rows'] ?? 0)),
            max(0, (int) ($row['inactive_rows'] ?? 0)),
            max(0, (int) ($row['available_rows'] ?? 0)),
            max(0, (int) ($row['vacant_rows'] ?? 0)),
            max(0, (int) ($row['occupied_rows'] ?? 0)),
            (string) ($row['sync_batch_key'] ?? ''),
        ]
    );
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project bed analytics refresh failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }
}

function bx_refresh_project_bed_analytics(string $batchKey, ?string $userKey = null): array
{
    bx_ensure_bed_master_list_schema();
    $batchKey = trim($batchKey);
    if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $batchKey)) {
        throw new RuntimeException('Invalid project bed analytics sync batch key.');
    }

    $db = bx_db();
    $inactive = $db->Execute(
        "UPDATE project_bed_analytics
        SET analytics_status = 'INACTIVE',
            sync_batch_key = ?,
            last_computed_at = CURRENT_TIMESTAMP",
        [$batchKey]
    );
    if ($inactive === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project bed analytics inactive refresh failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    $summary = $db->GetRow(
        "SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN managed_status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_rows,
            SUM(CASE WHEN managed_status = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive_rows,
            SUM(CASE WHEN source_bed_status = 'Available' THEN 1 ELSE 0 END) AS available_rows,
            SUM(CASE WHEN source_bed_status IN ('Available', 'Vacant') THEN 1 ELSE 0 END) AS vacant_rows,
            SUM(CASE WHEN source_bed_status = 'Occupied' THEN 1 ELSE 0 END) AS occupied_rows
        FROM project_bed"
    ) ?: [];
    bx_upsert_project_bed_analytics_row([
        'analytics_key' => bx_project_bed_analytics_key('SUMMARY', 'summary', 'all'),
        'analytics_scope' => 'SUMMARY',
        'group_key' => 'summary',
        'group_label' => 'Summary',
        'item_label' => 'All beds',
        'total_rows' => (int) ($summary['total_rows'] ?? 0),
        'active_rows' => (int) ($summary['active_rows'] ?? 0),
        'inactive_rows' => (int) ($summary['inactive_rows'] ?? 0),
        'available_rows' => (int) ($summary['available_rows'] ?? 0),
        'vacant_rows' => (int) ($summary['vacant_rows'] ?? 0),
        'occupied_rows' => (int) ($summary['occupied_rows'] ?? 0),
        'sync_batch_key' => $batchKey,
    ]);

    foreach (bx_project_bed_analytics_group_definitions() as $group) {
        $column = (string) ($group['column'] ?? '');
        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        $labelSql = "COALESCE(NULLIF(TRIM(CAST({$columnSql} AS CHAR)), ''), 'Unspecified')";
        $rows = $db->GetAll(
            "SELECT
                {$labelSql} AS item_label,
                COUNT(*) AS total_rows,
                SUM(CASE WHEN managed_status = 'ACTIVE' THEN 1 ELSE 0 END) AS active_rows,
                SUM(CASE WHEN managed_status = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive_rows,
                SUM(CASE WHEN source_bed_status = 'Available' THEN 1 ELSE 0 END) AS available_rows,
                SUM(CASE WHEN source_bed_status IN ('Available', 'Vacant') THEN 1 ELSE 0 END) AS vacant_rows,
                SUM(CASE WHEN source_bed_status = 'Occupied' THEN 1 ELSE 0 END) AS occupied_rows
            FROM project_bed
            GROUP BY {$labelSql}
            ORDER BY total_rows DESC, item_label ASC"
        ) ?: [];

        foreach ($rows as $analyticsRow) {
            $itemLabel = (string) ($analyticsRow['item_label'] ?? 'Unspecified');
            bx_upsert_project_bed_analytics_row([
                'analytics_key' => bx_project_bed_analytics_key('GROUP', (string) $group['key'], $itemLabel),
                'analytics_scope' => 'GROUP',
                'group_key' => (string) $group['key'],
                'group_label' => (string) $group['label'],
                'item_label' => $itemLabel,
                'total_rows' => (int) ($analyticsRow['total_rows'] ?? 0),
                'active_rows' => (int) ($analyticsRow['active_rows'] ?? 0),
                'inactive_rows' => (int) ($analyticsRow['inactive_rows'] ?? 0),
                'available_rows' => (int) ($analyticsRow['available_rows'] ?? 0),
                'vacant_rows' => (int) ($analyticsRow['vacant_rows'] ?? 0),
                'occupied_rows' => (int) ($analyticsRow['occupied_rows'] ?? 0),
                'sync_batch_key' => $batchKey,
            ]);
        }
    }

    $activeRows = (int) $db->GetOne("SELECT COUNT(*) FROM project_bed_analytics WHERE analytics_status = 'ACTIVE'");
    bx_audit('SYNC', 'project_bed_analytics', $batchKey, [
        'analytics_rows' => (string) $activeRows,
        'sync_batch_key' => $batchKey,
        'user_key' => (string) ($userKey ?? ''),
    ], 'Administrator refreshed project_bed_analytics for Firebase and mobile read-only analytics.');

    return [
        'batchKey' => $batchKey,
        'activeRows' => $activeRows,
        'inactiveRows' => (int) $db->GetOne("SELECT COUNT(*) FROM project_bed_analytics WHERE analytics_status = 'INACTIVE'"),
        'rows' => bx_project_bed_analytics_rows($batchKey),
        'documents' => bx_project_bed_analytics_documents($batchKey),
    ];
}

function bx_project_bed_analytics_documents(?string $batchKey = null): array
{
    bx_ensure_project_bed_analytics_schema();
    $where = ["analytics_scope = 'GROUP'", "analytics_status = 'ACTIVE'"];
    $params = [];

    if ($batchKey !== null && trim($batchKey) !== '') {
        $batchKey = trim($batchKey);
        if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $batchKey)) {
            throw new RuntimeException('Invalid project bed analytics document batch key.');
        }
        $where[] = 'sync_batch_key = ?';
        $params[] = $batchKey;
    }

    $rows = bx_db()->GetAll(
        "SELECT
            group_key,
            group_label,
            item_label,
            total_rows,
            active_rows,
            inactive_rows,
            available_rows,
            vacant_rows,
            occupied_rows,
            analytics_status,
            COALESCE(sync_batch_key, '') AS sync_batch_key,
            DATE_FORMAT(last_computed_at, '%Y-%m-%d %H:%i:%s') AS last_computed_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_bed_analytics
        WHERE " . implode(' AND ', $where) . "
        ORDER BY group_key ASC, total_rows DESC, item_label ASC",
        $params
    ) ?: [];

    $groupedRows = [];
    foreach ($rows as $row) {
        $groupedRows[(string) ($row['group_key'] ?? '')][] = $row;
    }

    $documents = [];
    foreach (bx_project_bed_analytics_group_definitions() as $definition) {
        $groupKey = (string) $definition['key'];
        $groupRows = $groupedRows[$groupKey] ?? [];
        $documents[] = [
            'analytics_key' => bx_project_bed_analytics_key('GROUP_DOCUMENT', $groupKey, 'rows'),
            'analytics_scope' => 'GROUP',
            'group_key' => $groupKey,
            'group_label' => (string) $definition['label'],
            'analytics_status' => 'ACTIVE',
            'sync_batch_key' => (string) ($batchKey ?? ($groupRows[0]['sync_batch_key'] ?? '')),
            'row_count' => count($groupRows),
            'rows' => array_map(static fn (array $row): array => [
                'item_label' => (string) ($row['item_label'] ?? ''),
                'total_rows' => (int) ($row['total_rows'] ?? 0),
                'active_rows' => (int) ($row['active_rows'] ?? 0),
                'inactive_rows' => (int) ($row['inactive_rows'] ?? 0),
                'available_rows' => (int) ($row['available_rows'] ?? 0),
                'vacant_rows' => (int) ($row['vacant_rows'] ?? 0),
                'occupied_rows' => (int) ($row['occupied_rows'] ?? 0),
            ], $groupRows),
            'last_computed_at' => (string) ($groupRows[0]['last_computed_at'] ?? ''),
            'updated_at' => (string) ($groupRows[0]['updated_at'] ?? ''),
        ];
    }

    return $documents;
}

function bx_project_bed_analytics_rows(?string $batchKey = null, int $limit = 2000): array
{
    bx_ensure_project_bed_analytics_schema();
    $where = [];
    $params = [];

    if ($batchKey !== null && trim($batchKey) !== '') {
        $batchKey = trim($batchKey);
        if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $batchKey)) {
            throw new RuntimeException('Invalid project bed analytics batch key.');
        }
        $where[] = 'sync_batch_key = ?';
        $params[] = $batchKey;
    }

    $limit = max(1, min(5000, $limit));
    $whereSql = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = bx_db()->GetAll(
        "SELECT
            analytics_key,
            analytics_scope,
            group_key,
            group_label,
            item_label,
            total_rows,
            active_rows,
            inactive_rows,
            available_rows,
            vacant_rows,
            occupied_rows,
            analytics_status,
            COALESCE(sync_batch_key, '') AS sync_batch_key,
            DATE_FORMAT(last_computed_at, '%Y-%m-%d %H:%i:%s') AS last_computed_at,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_bed_analytics
        {$whereSql}
        ORDER BY analytics_scope ASC, group_key ASC, total_rows DESC, item_label ASC
        LIMIT {$limit}",
        $params
    );

    return is_array($rows) ? $rows : [];
}

function bx_ensure_project_task_schema(): void
{
    $db = bx_db();
    $tableExists = static function (string $tableName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, $tableName]
        ) === 1;
    };
    $indexExists = static function (string $indexName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [BUILDERX_DB_NAME, 'project_task', $indexName]
        ) > 0;
    };
    $columnExists = static function (string $columnName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, 'project_task', $columnName]
        ) > 0;
    };

    if (!$tableExists('project_task') && $tableExists('project_task_list')) {
        $renamed = $db->Execute('RENAME TABLE project_task_list TO project_task');
        if ($renamed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task table rename failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_task (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_key VARCHAR(40) NOT NULL UNIQUE,
            task_code VARCHAR(80) NULL,
            task_title VARCHAR(255) NOT NULL,
            task_description MEDIUMTEXT NULL,
            task_group_keys TEXT NULL,
            task_bypass_group_keys TEXT NULL,
            task_type ENUM('PRIMARY','SECONDARY') NOT NULL DEFAULT 'PRIMARY',
            task_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE',
            task_priority ENUM('LOW','NORMAL','HIGH','URGENT') NOT NULL DEFAULT 'NORMAL',
            task_color_hex CHAR(9) NOT NULL DEFAULT '#00000000',
            task_can_run_manually TINYINT(1) NOT NULL DEFAULT 0,
            task_can_run_via_api TINYINT(1) NOT NULL DEFAULT 0,
            task_can_run_if_bed_vacant TINYINT(1) NOT NULL DEFAULT 1,
            task_can_run_if_bed_occupied TINYINT(1) NOT NULL DEFAULT 1,
            task_requires_bed_treatment TINYINT(1) NOT NULL DEFAULT 1,
            task_requires_admission_source TINYINT(1) NOT NULL DEFAULT 1,
            task_canvas_x INT UNSIGNED NOT NULL DEFAULT 24,
            task_canvas_y INT UNSIGNED NOT NULL DEFAULT 24,
            task_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_task_type (task_type, task_status, task_sort_order),
            INDEX idx_project_task_status (task_status),
            INDEX idx_project_task_priority (task_priority),
            INDEX idx_project_task_code (task_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task schema setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    $taskKeyColumnType = strtolower((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task', 'task_key']
    ));
    if ($taskKeyColumnType !== 'varchar(40)') {
        $keyAltered = $db->Execute('ALTER TABLE project_task MODIFY task_key VARCHAR(40) NOT NULL');
        if ($keyAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task key column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    bx_add_column_if_missing('project_task', 'task_type', "ENUM('PRIMARY','SECONDARY') NOT NULL DEFAULT 'PRIMARY' AFTER task_description");
    bx_add_column_if_missing('project_task', 'task_group_keys', 'TEXT NULL AFTER task_description');
    bx_add_column_if_missing('project_task', 'task_bypass_group_keys', 'TEXT NULL AFTER task_group_keys');
    bx_add_column_if_missing('project_task', 'task_color_hex', "CHAR(9) NOT NULL DEFAULT '#00000000' AFTER task_priority");
    bx_add_column_if_missing('project_task', 'task_can_run_manually', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER task_color_hex');
    bx_add_column_if_missing('project_task', 'task_can_run_via_api', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER task_can_run_manually');
    bx_add_column_if_missing('project_task', 'task_can_run_if_bed_vacant', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER task_can_run_via_api');
    bx_add_column_if_missing('project_task', 'task_can_run_if_bed_occupied', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER task_can_run_if_bed_vacant');
    bx_add_column_if_missing('project_task', 'task_requires_bed_treatment', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER task_can_run_if_bed_occupied');
    bx_add_column_if_missing('project_task', 'task_requires_admission_source', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER task_requires_bed_treatment');
    bx_add_column_if_missing('project_task', 'task_canvas_x', 'INT UNSIGNED NOT NULL DEFAULT 24 AFTER task_requires_admission_source');
    bx_add_column_if_missing('project_task', 'task_canvas_y', 'INT UNSIGNED NOT NULL DEFAULT 24 AFTER task_canvas_x');

    $taskColorColumn = $db->GetRow(
        'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [BUILDERX_DB_NAME, 'project_task', 'task_color_hex']
    );
    $taskColorDefault = is_array($taskColorColumn) ? trim((string) ($taskColorColumn['column_default'] ?? ''), "'") : '';
    if (
        is_array($taskColorColumn)
        && (strtolower((string) ($taskColorColumn['column_type'] ?? '')) !== 'char(9)' || $taskColorDefault !== '#00000000')
    ) {
        $taskColorAltered = $db->Execute("ALTER TABLE project_task MODIFY task_color_hex CHAR(9) NOT NULL DEFAULT '#00000000'");
        if ($taskColorAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task color column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $colorNormalized = $db->Execute("UPDATE project_task SET task_color_hex = '#00000000' WHERE task_color_hex IS NULL OR task_color_hex NOT REGEXP '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$'");
    if ($colorNormalized === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task color normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    foreach ([
        'idx_project_task_project',
        'idx_project_task_list_project',
        'idx_project_task_assignee',
        'idx_project_task_list_assignee',
        'idx_project_task_due',
        'idx_project_task_list_due',
    ] as $obsoleteIndexName) {
        if (!$indexExists($obsoleteIndexName)) {
            continue;
        }
        $dropped = $db->Execute("ALTER TABLE project_task DROP INDEX {$obsoleteIndexName}");
        if ($dropped === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task obsolete index removal failed for ' . $obsoleteIndexName . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    foreach (['project_key', 'assigned_user_key', 'due_at', 'completed_at'] as $obsoleteColumnName) {
        if (!$columnExists($obsoleteColumnName)) {
            continue;
        }
        $dropped = $db->Execute("ALTER TABLE project_task DROP COLUMN {$obsoleteColumnName}");
        if ($dropped === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task obsolete column removal failed for ' . $obsoleteColumnName . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $statusColumnType = strtoupper((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task', 'task_status']
    ));
    if ($statusColumnType !== "ENUM('ACTIVE','INACTIVE')") {
        $statusExpanded = $db->Execute("ALTER TABLE project_task MODIFY task_status ENUM('ACTIVE','INACTIVE','BACKLOG','READY','IN_PROGRESS','BLOCKED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'INACTIVE'");
        if ($statusExpanded === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task status transition setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $normalized = $db->Execute("UPDATE project_task SET task_status = CASE WHEN task_status = 'ACTIVE' THEN 'ACTIVE' ELSE 'INACTIVE' END");
        if ($normalized === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task status normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }

        $statusAltered = $db->Execute("ALTER TABLE project_task MODIFY task_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE'");
        if ($statusAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task status column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    foreach ([
        'idx_project_task_type' => ['old' => '', 'definition' => 'INDEX idx_project_task_type (task_type, task_status, task_sort_order)'],
        'idx_project_task_status' => ['old' => 'idx_project_task_list_status', 'definition' => 'INDEX idx_project_task_status (task_status)'],
        'idx_project_task_priority' => ['old' => 'idx_project_task_list_priority', 'definition' => 'INDEX idx_project_task_priority (task_priority)'],
        'idx_project_task_code' => ['old' => 'idx_project_task_list_code', 'definition' => 'INDEX idx_project_task_code (task_code)'],
        'idx_project_task_portal_run' => ['old' => '', 'definition' => 'INDEX idx_project_task_portal_run (task_type, task_status, task_can_run_manually, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_sort_order)'],
    ] as $indexName => $indexSpec) {
        if ($indexExists($indexName)) {
            continue;
        }
        $oldIndexName = (string) $indexSpec['old'];
        $definition = (string) $indexSpec['definition'];
        $indexSql = $oldIndexName !== '' && $indexExists($oldIndexName)
            ? "ALTER TABLE project_task DROP INDEX {$oldIndexName}, ADD {$definition}"
            : "ALTER TABLE project_task ADD {$definition}";
        $indexed = $db->Execute($indexSql);
        if ($indexed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task index setup failed for ' . $indexName . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }
}

function bx_ensure_project_bed_task_schema(): void
{
    bx_ensure_bed_master_list_schema();
    bx_ensure_project_task_schema();
    bx_ensure_project_bed_reference_schema();

    $db = bx_db();
    $assert = static function (mixed $result, string $label) use ($db): void {
        if ($result === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException($label . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    };

    $assert($db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed_task (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bed_task_key VARCHAR(40) NOT NULL UNIQUE,
            bed_key VARCHAR(40) NOT NULL,
            bed_source_key VARCHAR(160) NOT NULL DEFAULT '',
            source_pk_psbeds VARCHAR(100) NULL,
            bed_no VARCHAR(100) NOT NULL DEFAULT '',
            task_key VARCHAR(40) NOT NULL,
            task_code VARCHAR(80) NULL,
            task_title VARCHAR(255) NOT NULL DEFAULT '',
            task_type ENUM('PRIMARY','SECONDARY') NOT NULL,
            task_status ENUM('PENDING','IN_PROGRESS','ON_HOLD','FAILED') NOT NULL DEFAULT 'PENDING',
            bed_status_at_request VARCHAR(100) NOT NULL DEFAULT '',
            bed_class VARCHAR(100) NOT NULL DEFAULT '',
            bed_treatment_key VARCHAR(40) NULL,
            bed_treatment_name VARCHAR(160) NOT NULL DEFAULT '',
            bed_source_option_key VARCHAR(40) NULL,
            bed_source_option_name VARCHAR(160) NOT NULL DEFAULT '',
            remarks TEXT NULL,
            requester_user_key CHAR(36) NOT NULL,
            requester_fullname VARCHAR(160) NOT NULL,
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_bed_task_bed (bed_key, task_status, created_at),
            INDEX idx_project_bed_task_bed_type (bed_key, task_type, task_status, created_at),
            INDEX idx_project_bed_task_task (task_key, task_status),
            INDEX idx_project_bed_task_requester (requester_user_key, created_at),
            INDEX idx_project_bed_task_status (task_status, created_at),
            INDEX idx_project_bed_task_firebase (firebase_sync_status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "), 'Project bed task schema setup');

    bx_add_index_if_missing('project_bed_task', 'idx_project_bed_task_bed_type', 'INDEX idx_project_bed_task_bed_type (bed_key, task_type, task_status, created_at)');

    $assert($db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed_task_log (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bed_task_log_key VARCHAR(40) NOT NULL UNIQUE,
            bed_task_key VARCHAR(40) NOT NULL,
            bed_key VARCHAR(40) NOT NULL,
            bed_source_key VARCHAR(160) NOT NULL DEFAULT '',
            source_pk_psbeds VARCHAR(100) NULL,
            bed_no VARCHAR(100) NOT NULL DEFAULT '',
            task_key VARCHAR(40) NOT NULL,
            task_code VARCHAR(80) NULL,
            task_title VARCHAR(255) NOT NULL DEFAULT '',
            task_type ENUM('PRIMARY','SECONDARY') NOT NULL,
            event_type ENUM('CREATED','ASSIGNED','STARTED','UPDATED','COMPLETED','CANCELLED','FAILED') NOT NULL,
            status_from VARCHAR(40) NULL,
            status_to VARCHAR(40) NOT NULL,
            bed_status_at_request VARCHAR(100) NOT NULL DEFAULT '',
            bed_class VARCHAR(100) NOT NULL DEFAULT '',
            bed_treatment_key VARCHAR(40) NULL,
            bed_treatment_name VARCHAR(160) NOT NULL DEFAULT '',
            bed_source_option_key VARCHAR(40) NULL,
            bed_source_option_name VARCHAR(160) NOT NULL DEFAULT '',
            remarks TEXT NULL,
            requester_user_key CHAR(36) NOT NULL,
            requester_fullname VARCHAR(160) NOT NULL,
            actor_user_key CHAR(36) NOT NULL,
            actor_fullname VARCHAR(160) NOT NULL,
            firebase_sync_status ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
            firebase_synced_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_project_bed_task_log_task (bed_task_key, created_at),
            INDEX idx_project_bed_task_log_event (event_type, created_at),
            INDEX idx_project_bed_task_log_actor (actor_user_key, created_at),
            INDEX idx_project_bed_task_log_requester (requester_user_key, created_at),
            INDEX idx_project_bed_task_log_firebase (firebase_sync_status, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "), 'Project bed task log schema setup');

    bx_add_column_if_missing('project_bed_task_log', 'firebase_sync_status', "ENUM('PENDING','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING' AFTER actor_fullname");
    bx_add_column_if_missing('project_bed_task_log', 'firebase_synced_at', 'TIMESTAMP NULL DEFAULT NULL AFTER firebase_sync_status');
    bx_add_index_if_missing('project_bed_task_log', 'idx_project_bed_task_log_firebase', 'INDEX idx_project_bed_task_log_firebase (firebase_sync_status, created_at)');
}

function bx_project_bed_task_trim(mixed $value, int $maxLength): string
{
    $text = trim((string) $value);
    if ($maxLength > 0 && strlen($text) > $maxLength) {
        $text = substr($text, 0, $maxLength);
    }

    return $text;
}

function bx_project_bed_task_status_bucket(string $bedStatus): string
{
    $status = strtolower(trim($bedStatus));
    if ($status === 'occupied') {
        return 'occupied';
    }
    if (in_array($status, ['available', 'vacant'], true)) {
        return 'vacant';
    }

    return '';
}

function bx_create_project_bed_task(array $input, array $user): array
{
    bx_ensure_project_bed_task_schema();

    $db = bx_db();
    $bedKey = bx_project_bed_task_trim($input['bed_key'] ?? '', 40);
    $taskKey = bx_project_bed_task_trim($input['task_key'] ?? '', 40);
    $selectedBedClass = bx_project_bed_task_trim($input['room_class'] ?? '', 100);
    $bedTreatmentKey = bx_project_bed_task_trim($input['bed_treatment_key'] ?? '', 40);
    $bedSourceOptionKey = bx_project_bed_task_trim($input['bed_source_key'] ?? '', 40);
    $remarks = bx_project_bed_task_trim($input['remarks'] ?? '', 4000);
    $requesterUserKey = bx_project_bed_task_trim($user['user_key'] ?? '', 36);
    $requesterFullname = bx_project_bed_task_trim($user['user_name'] ?? '', 160);

    if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $bedKey)) {
        throw new RuntimeException('Invalid bed key.');
    }
    if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if (!preg_match('/^[A-Fa-f0-9-]{36}$/', $requesterUserKey)) {
        throw new RuntimeException('A valid signed-in requester is required.');
    }
    if ($requesterFullname === '') {
        $requesterFullname = 'Portal User';
    }

    $bed = $db->GetRow(
        "SELECT
            bed_key,
            bed_source_key,
            COALESCE(source_pk_psbeds, '') AS source_pk_psbeds,
            COALESCE(bed_no, '') AS bed_no,
            COALESCE(room_class, '') AS room_class,
            COALESCE(source_bed_status, '') AS source_bed_status,
            managed_status
        FROM project_bed
        WHERE bed_key = ?
        LIMIT 1",
        [$bedKey]
    );
    if (!is_array($bed)) {
        throw new RuntimeException('Bed record was not found.');
    }
    if ((string) ($bed['managed_status'] ?? '') !== 'ACTIVE') {
        throw new RuntimeException('Only active managed beds can receive task requests.');
    }

    $task = $db->GetRow(
        "SELECT
            task_key,
            COALESCE(task_code, '') AS task_code,
            task_title,
            task_type,
            task_status,
            task_can_run_manually,
            task_can_run_if_bed_vacant,
            task_can_run_if_bed_occupied,
            task_requires_bed_treatment,
            task_requires_admission_source
        FROM project_task
        WHERE task_key = ?
          AND task_type IN ('PRIMARY', 'SECONDARY')
          AND task_status = 'ACTIVE'
        LIMIT 1",
        [$taskKey]
    );
    if (!is_array($task)) {
        throw new RuntimeException('Active primary or secondary task was not found.');
    }
    if ((int) ($task['task_can_run_manually'] ?? 0) !== 1) {
        throw new RuntimeException('This task is not allowed for manual portal requests.');
    }
    $taskType = strtoupper((string) ($task['task_type'] ?? 'PRIMARY'));
    if (!in_array($taskType, ['PRIMARY', 'SECONDARY'], true)) {
        throw new RuntimeException('Invalid task type.');
    }

    $duplicateTask = $db->GetRow(
        "SELECT bed_task_key, task_key, task_title, task_type, task_status
        FROM project_bed_task
        WHERE bed_key = ?
          AND task_key = ?
        ORDER BY x_id DESC
        LIMIT 1",
        [$bedKey, $taskKey]
    );
    if (is_array($duplicateTask)) {
        throw new RuntimeException('This bed already has an unfinished request for the selected task.');
    }

    $bedStatus = bx_project_bed_task_trim($bed['source_bed_status'] ?? '', 100);
    $bedStatusBucket = bx_project_bed_task_status_bucket($bedStatus);
    if ($bedStatusBucket === 'occupied' && (int) ($task['task_can_run_if_bed_occupied'] ?? 0) !== 1) {
        throw new RuntimeException('This task is not allowed for occupied beds.');
    }
    if ($bedStatusBucket === 'vacant' && (int) ($task['task_can_run_if_bed_vacant'] ?? 0) !== 1) {
        throw new RuntimeException('This task is not allowed for vacant beds.');
    }
    if ($bedStatusBucket === '') {
        throw new RuntimeException('This bed status is not allowed for manual task requests.');
    }

    $bedClass = $selectedBedClass !== '' ? $selectedBedClass : bx_project_bed_task_trim($bed['room_class'] ?? '', 100);
    $bedTreatmentName = '';
    if ($bedTreatmentKey !== '') {
        if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $bedTreatmentKey)) {
            throw new RuntimeException('Invalid bed treatment key.');
        }
        $treatment = $db->GetRow(
            "SELECT bed_treatment_key, treatment_name FROM project_bed_treatment WHERE bed_treatment_key = ? AND treatment_status = 'ACTIVE' LIMIT 1",
            [$bedTreatmentKey]
        );
        if (!is_array($treatment)) {
            throw new RuntimeException('Active bed treatment was not found.');
        }
        $bedTreatmentName = bx_project_bed_task_trim($treatment['treatment_name'] ?? '', 160);
    } elseif ((int) ($task['task_requires_bed_treatment'] ?? 0) === 1) {
        throw new RuntimeException('Bed treatment is required for this task.');
    }

    $bedSourceOptionName = '';
    if ($bedSourceOptionKey !== '') {
        if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $bedSourceOptionKey)) {
            throw new RuntimeException('Invalid bed source key.');
        }
        $sourceOption = $db->GetRow(
            "SELECT bed_source_key, bed_source_name FROM project_bed_source WHERE bed_source_key = ? AND bed_source_status = 'ACTIVE' LIMIT 1",
            [$bedSourceOptionKey]
        );
        if (!is_array($sourceOption)) {
            throw new RuntimeException('Active bed source was not found.');
        }
        $bedSourceOptionName = bx_project_bed_task_trim($sourceOption['bed_source_name'] ?? '', 160);
    } elseif ((int) ($task['task_requires_admission_source'] ?? 0) === 1) {
        throw new RuntimeException('Bed source is required for this task.');
    }

    $bedTaskKey = bx_unique_firebase_document_key('project_bed_task', 'bed_task_key');
    $bedTaskLogKey = bx_unique_firebase_document_key('project_bed_task_log', 'bed_task_log_key');
    $taskCode = bx_project_bed_task_trim($task['task_code'] ?? '', 80);
    $taskTitle = bx_project_bed_task_trim($task['task_title'] ?? '', 255);
    $activeStatus = 'PENDING';
    $transactionStarted = false;

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Bed task request transaction could not start.');
        }
        $transactionStarted = true;

        $saved = $db->Execute(
            'INSERT INTO project_bed_task (
                bed_task_key, bed_key, bed_source_key, source_pk_psbeds, bed_no,
                task_key, task_code, task_title, task_type, task_status,
                bed_status_at_request, bed_class, bed_treatment_key, bed_treatment_name,
                bed_source_option_key, bed_source_option_name, remarks,
                requester_user_key, requester_fullname, firebase_sync_status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $bedTaskKey,
                $bedKey,
                bx_project_bed_task_trim($bed['bed_source_key'] ?? '', 160),
                bx_project_bed_task_trim($bed['source_pk_psbeds'] ?? '', 100) ?: null,
                bx_project_bed_task_trim($bed['bed_no'] ?? '', 100),
                $taskKey,
                $taskCode !== '' ? $taskCode : null,
                $taskTitle,
                $taskType,
                $activeStatus,
                $bedStatus,
                $bedClass,
                $bedTreatmentKey !== '' ? $bedTreatmentKey : null,
                $bedTreatmentName,
                $bedSourceOptionKey !== '' ? $bedSourceOptionKey : null,
                $bedSourceOptionName,
                $remarks !== '' ? $remarks : null,
                $requesterUserKey,
                $requesterFullname,
                'PENDING',
            ]
        );
        if ($saved === false) {
            throw new RuntimeException('Bed task request save failed: ' . trim((string) $db->ErrorMsg()));
        }

        $logged = $db->Execute(
            'INSERT INTO project_bed_task_log (
                bed_task_log_key, bed_task_key, bed_key, bed_source_key, source_pk_psbeds, bed_no,
                task_key, task_code, task_title, task_type, event_type, status_from, status_to,
                bed_status_at_request, bed_class, bed_treatment_key, bed_treatment_name,
                bed_source_option_key, bed_source_option_name, remarks,
                requester_user_key, requester_fullname, actor_user_key, actor_fullname
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $bedTaskLogKey,
                $bedTaskKey,
                $bedKey,
                bx_project_bed_task_trim($bed['bed_source_key'] ?? '', 160),
                bx_project_bed_task_trim($bed['source_pk_psbeds'] ?? '', 100) ?: null,
                bx_project_bed_task_trim($bed['bed_no'] ?? '', 100),
                $taskKey,
                $taskCode !== '' ? $taskCode : null,
                $taskTitle,
                $taskType,
                'CREATED',
                null,
                $activeStatus,
                $bedStatus,
                $bedClass,
                $bedTreatmentKey !== '' ? $bedTreatmentKey : null,
                $bedTreatmentName,
                $bedSourceOptionKey !== '' ? $bedSourceOptionKey : null,
                $bedSourceOptionName,
                $remarks !== '' ? $remarks : null,
                $requesterUserKey,
                $requesterFullname,
                $requesterUserKey,
                $requesterFullname,
            ]
        );
        if ($logged === false) {
            throw new RuntimeException('Bed task request log failed: ' . trim((string) $db->ErrorMsg()));
        }

        $readBack = $db->GetRow(
            "SELECT bed_task_key, bed_key, task_key, task_status, requester_user_key, requester_fullname FROM project_bed_task WHERE bed_task_key = ? LIMIT 1",
            [$bedTaskKey]
        );
        $logReadBack = (int) $db->GetOne(
            "SELECT COUNT(*) FROM project_bed_task_log WHERE bed_task_key = ? AND event_type = 'CREATED'",
            [$bedTaskKey]
        );
        if (
            !is_array($readBack)
            || (string) ($readBack['bed_key'] ?? '') !== $bedKey
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['requester_user_key'] ?? '') !== $requesterUserKey
            || $logReadBack < 1
        ) {
            throw new RuntimeException('Bed task request read-back did not match the saved values.');
        }

        bx_audit('CREATE', 'project_bed_task', $bedTaskKey, [
            'bed_key' => $bedKey,
            'task_key' => $taskKey,
            'task_status' => $activeStatus,
            'requester_user_key' => $requesterUserKey,
            'requester_fullname' => $requesterFullname,
        ], 'Portal submitted a bed task request.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Bed task request transaction could not commit.');
        }
        $transactionStarted = false;

        return [
            'bed_task_key' => $bedTaskKey,
            'bed_task_log_key' => $bedTaskLogKey,
            'bed_key' => $bedKey,
            'task_key' => $taskKey,
            'task_status' => $activeStatus,
            'requester_user_key' => $requesterUserKey,
            'requester_fullname' => $requesterFullname,
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->FailTrans();
            $db->CompleteTrans();
        }
        throw $error;
    }
}

function bx_project_bed_task_firebase_payload(string $bedTaskKey): array
{
    bx_ensure_project_bed_task_schema();
    if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $bedTaskKey)) {
        throw new RuntimeException('Invalid bed task key.');
    }

    $task = bx_db()->GetRow(
        "SELECT
            bed_task_key,
            bed_key,
            bed_source_key,
            COALESCE(source_pk_psbeds, '') AS source_pk_psbeds,
            bed_no,
            task_key,
            COALESCE(task_code, '') AS task_code,
            task_title,
            task_type,
            task_status,
            bed_status_at_request,
            bed_class,
            COALESCE(bed_treatment_key, '') AS bed_treatment_key,
            bed_treatment_name,
            COALESCE(bed_source_option_key, '') AS bed_source_option_key,
            bed_source_option_name,
            COALESCE(remarks, '') AS remarks,
            requester_user_key,
            requester_fullname,
            firebase_sync_status,
            COALESCE(DATE_FORMAT(firebase_synced_at, '%Y-%m-%d %H:%i:%s'), '') AS firebase_synced_at,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at
        FROM project_bed_task
        WHERE bed_task_key = ?
        LIMIT 1",
        [$bedTaskKey]
    );
    $logs = bx_db()->GetAll(
        "SELECT
            bed_task_log_key,
            bed_task_key,
            bed_key,
            bed_source_key,
            COALESCE(source_pk_psbeds, '') AS source_pk_psbeds,
            bed_no,
            task_key,
            COALESCE(task_code, '') AS task_code,
            task_title,
            task_type,
            event_type,
            COALESCE(status_from, '') AS status_from,
            status_to,
            bed_status_at_request,
            bed_class,
            COALESCE(bed_treatment_key, '') AS bed_treatment_key,
            bed_treatment_name,
            COALESCE(bed_source_option_key, '') AS bed_source_option_key,
            bed_source_option_name,
            COALESCE(remarks, '') AS remarks,
            requester_user_key,
            requester_fullname,
            actor_user_key,
            actor_fullname,
            firebase_sync_status,
            COALESCE(DATE_FORMAT(firebase_synced_at, '%Y-%m-%d %H:%i:%s'), '') AS firebase_synced_at,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
        FROM project_bed_task_log
        WHERE bed_task_key = ?
        ORDER BY x_id ASC",
        [$bedTaskKey]
    ) ?: [];

    if (!is_array($task) && $logs === []) {
        throw new RuntimeException('Bed task request was not found for Firebase sync.');
    }

    return [
        'task' => is_array($task) ? $task : null,
        'logs' => $logs,
    ];
}

function bx_mark_project_bed_task_firebase_sync(string $bedTaskKey, string $status): void
{
    $status = strtoupper(trim($status));
    if (!in_array($status, ['SYNCED', 'FAILED'], true)) {
        throw new RuntimeException('Invalid bed task Firebase sync status.');
    }

    $syncedAtSql = $status === 'SYNCED' ? ', firebase_synced_at = CURRENT_TIMESTAMP' : '';
    bx_db()->Execute(
        "UPDATE project_bed_task SET firebase_sync_status = ? {$syncedAtSql} WHERE bed_task_key = ?",
        [$status, $bedTaskKey]
    );
    bx_db()->Execute(
        "UPDATE project_bed_task_log SET firebase_sync_status = ? {$syncedAtSql} WHERE bed_task_key = ?",
        [$status, $bedTaskKey]
    );
}

function bx_sync_project_bed_task_to_firebase(string $bedTaskKey): array
{
    try {
        $payloadRows = bx_project_bed_task_firebase_payload($bedTaskKey);
    } catch (Throwable $error) {
        return ['ok' => false, 'skipped' => false, 'message' => $error->getMessage()];
    }

    $projectId = bx_messenger_firebase_project_id();
    $serviceAccountPath = bx_messenger_firebase_service_account_path();
    $fail = static function (string $message) use ($bedTaskKey): array {
        bx_mark_project_bed_task_firebase_sync($bedTaskKey, 'FAILED');
        return ['ok' => false, 'skipped' => false, 'message' => $message];
    };
    if ($projectId === '') {
        return $fail('Firebase project id is not configured.');
    }
    if ($serviceAccountPath === '' || !is_readable($serviceAccountPath)) {
        return $fail('Firebase service account path is not configured or readable.');
    }

    $scriptPath = dirname(__DIR__) . '/scripts/firebase-bed-task-sync.mjs';
    if (!is_readable($scriptPath)) {
        return $fail('Firebase bed task sync script is missing.');
    }

    $payload = json_encode([
        'project_id' => $projectId,
        'service_account_path' => $serviceAccountPath,
        'task' => $payloadRows['task'],
        'logs' => $payloadRows['logs'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return $fail('Firebase bed task payload could not be encoded.');
    }

    $command = 'node ' . escapeshellarg($scriptPath);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return $fail('Firebase bed task sync process could not start.');
    }

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $result = json_decode((string) $stdout, true);
    if (!is_array($result)) {
        $result = [
            'ok' => false,
            'message' => trim((string) $stderr) !== '' ? trim((string) $stderr) : 'Firebase bed task sync returned an invalid response.',
        ];
    }

    if ($exitCode === 0 && ($result['ok'] ?? false) === true) {
        bx_mark_project_bed_task_firebase_sync($bedTaskKey, 'SYNCED');
    } else {
        bx_mark_project_bed_task_firebase_sync($bedTaskKey, 'FAILED');
    }

    return $result + [
        'exit_code' => $exitCode,
        'stderr' => trim((string) $stderr) !== '' ? '[REDACTED]' : '',
    ];
}

function bx_ensure_project_bed_reference_schema(): void
{
    $db = bx_db();
    $assert = static function (mixed $result, string $label) use ($db): void {
        if ($result === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException($label . ' failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    };
    $tableExists = static function (string $table) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [BUILDERX_DB_NAME, $table]
        ) > 0;
    };
    $columnExists = static function (string $table, string $column) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [BUILDERX_DB_NAME, $table, $column]
        ) > 0;
    };
    $renameColumn = static function (string $table, string $from, string $to, string $definition) use ($db, $assert, $columnExists): void {
        if (!$columnExists($table, $from) || $columnExists($table, $to)) {
            return;
        }
        $assert($db->Execute("ALTER TABLE {$table} CHANGE COLUMN {$from} {$to} {$definition}"), "Project bed source column {$from} rename");
    };

    $assert($db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed_treatment (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bed_treatment_key VARCHAR(40) NOT NULL UNIQUE,
            treatment_code VARCHAR(80) NOT NULL,
            treatment_name VARCHAR(160) NOT NULL,
            treatment_description TEXT NULL,
            treatment_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            treatment_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_bed_treatment_code (treatment_code),
            INDEX idx_project_bed_treatment_status (treatment_status, treatment_sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "), 'Project bed treatment schema setup');

    if ($tableExists('project_admission_source') && !$tableExists('project_bed_source')) {
        $assert($db->Execute('RENAME TABLE project_admission_source TO project_bed_source'), 'Project bed source table rename');
    }

    $assert($db->Execute("
        CREATE TABLE IF NOT EXISTS project_bed_source (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            bed_source_key VARCHAR(40) NOT NULL UNIQUE,
            bed_source_code VARCHAR(80) NOT NULL,
            bed_source_name VARCHAR(160) NOT NULL,
            bed_source_description TEXT NULL,
            bed_source_status ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE',
            bed_source_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_project_bed_source_code (bed_source_code),
            INDEX idx_project_bed_source_status (bed_source_status, bed_source_sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    "), 'Project bed source schema setup');

    $renameColumn('project_bed_source', 'admission_source_key', 'bed_source_key', 'VARCHAR(40) NOT NULL');
    $renameColumn('project_bed_source', 'admission_source_code', 'bed_source_code', 'VARCHAR(80) NOT NULL');
    $renameColumn('project_bed_source', 'admission_source_name', 'bed_source_name', 'VARCHAR(160) NOT NULL');
    $renameColumn('project_bed_source', 'admission_source_description', 'bed_source_description', 'TEXT NULL');
    $renameColumn('project_bed_source', 'admission_source_status', 'bed_source_status', "ENUM('ACTIVE','INACTIVE','DELETED') NOT NULL DEFAULT 'ACTIVE'");
    $renameColumn('project_bed_source', 'admission_source_sort_order', 'bed_source_sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0');

    bx_add_column_if_missing('project_bed_treatment', 'treatment_description', 'TEXT NULL AFTER treatment_name');
    bx_add_column_if_missing('project_bed_treatment', 'treatment_sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER treatment_status');
    bx_add_column_if_missing('project_bed_source', 'bed_source_description', 'TEXT NULL AFTER bed_source_name');
    bx_add_column_if_missing('project_bed_source', 'bed_source_sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER bed_source_status');
    bx_add_index_if_missing('project_bed_treatment', 'idx_project_bed_treatment_status', 'INDEX idx_project_bed_treatment_status (treatment_status, treatment_sort_order)');
    bx_add_index_if_missing('project_bed_source', 'bed_source_key', 'UNIQUE KEY bed_source_key (bed_source_key)');
    bx_add_index_if_missing('project_bed_source', 'uq_project_bed_source_code', 'UNIQUE KEY uq_project_bed_source_code (bed_source_code)');
    bx_add_index_if_missing('project_bed_source', 'idx_project_bed_source_status', 'INDEX idx_project_bed_source_status (bed_source_status, bed_source_sort_order)');
}

function bx_project_bed_treatment_rows(bool $activeOnly = false): array
{
    bx_ensure_project_bed_reference_schema();
    $where = $activeOnly ? "WHERE treatment_status = 'ACTIVE'" : "WHERE treatment_status <> 'DELETED'";
    $rows = bx_db()->GetAll("
        SELECT
            bed_treatment_key,
            treatment_code,
            treatment_name,
            COALESCE(treatment_description, '') AS treatment_description,
            treatment_status,
            treatment_sort_order,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') AS updated_at
        FROM project_bed_treatment
        {$where}
        ORDER BY treatment_sort_order ASC, treatment_status ASC, treatment_name ASC, x_id ASC
    ");

    return is_array($rows) ? $rows : [];
}

function bx_project_bed_source_rows(bool $activeOnly = false): array
{
    bx_ensure_project_bed_reference_schema();
    $where = $activeOnly ? "WHERE bed_source_status = 'ACTIVE'" : "WHERE bed_source_status <> 'DELETED'";
    $rows = bx_db()->GetAll("
        SELECT
            bed_source_key,
            bed_source_code,
            bed_source_name,
            COALESCE(bed_source_description, '') AS bed_source_description,
            bed_source_status,
            bed_source_sort_order,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') AS updated_at
        FROM project_bed_source
        {$where}
        ORDER BY bed_source_sort_order ASC, bed_source_status ASC, bed_source_name ASC, x_id ASC
    ");

    return is_array($rows) ? $rows : [];
}

function bx_project_bed_reference_order_input(array $input, string $fieldName = 'order_keys'): array
{
    $raw = $input[$fieldName] ?? $input[$fieldName . '[]'] ?? [];
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
    }
    if (!is_array($raw)) {
        return [];
    }

    $ordered = [];
    foreach ($raw as $key) {
        $key = trim((string) $key);
        if (!preg_match('/^[A-Za-z0-9]{20}$/', $key) || in_array($key, $ordered, true)) {
            continue;
        }
        $ordered[] = $key;
    }

    return $ordered;
}

function bx_update_project_bed_reference_sort_order(array $input, ?string $userKey = null): array
{
    bx_ensure_project_bed_reference_schema();

    $type = strtolower(trim((string) ($input['reference_type'] ?? '')));
    $orderedKeys = bx_project_bed_reference_order_input($input);
    $reference = match ($type) {
        'treatment' => [
            'table' => 'project_bed_treatment',
            'key' => 'bed_treatment_key',
            'sort' => 'treatment_sort_order',
            'status' => 'treatment_status',
            'audit_module' => 'project_bed_treatment',
            'label' => 'Bed treatment',
            'rows' => static fn (): array => bx_project_bed_treatment_rows(),
        ],
        'source' => [
            'table' => 'project_bed_source',
            'key' => 'bed_source_key',
            'sort' => 'bed_source_sort_order',
            'status' => 'bed_source_status',
            'audit_module' => 'project_bed_source',
            'label' => 'Bed source',
            'rows' => static fn (): array => bx_project_bed_source_rows(),
        ],
        default => null,
    };

    if ($reference === null) {
        throw new RuntimeException('Invalid bed reference type.');
    }
    if ($orderedKeys === []) {
        throw new RuntimeException('Bed reference order is required.');
    }

    $db = bx_db();
    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException($reference['label'] . ' sort transaction could not start.');
        }
        $transactionStarted = true;

        $existingRows = $db->GetAll(
            sprintf(
                'SELECT %s AS reference_key FROM %s WHERE %s <> ? ORDER BY %s ASC, %s ASC, x_id ASC',
                $reference['key'],
                $reference['table'],
                $reference['status'],
                $reference['sort'],
                $reference['key']
            ),
            ['DELETED']
        ) ?: [];
        if ($existingRows === []) {
            throw new RuntimeException($reference['label'] . ' rows were not found.');
        }

        $existingByKey = [];
        foreach ($existingRows as $row) {
            $existingKey = (string) ($row['reference_key'] ?? '');
            if ($existingKey !== '') {
                $existingByKey[$existingKey] = $existingKey;
            }
        }

        $nextKeys = [];
        foreach ($orderedKeys as $key) {
            if (isset($existingByKey[$key])) {
                $nextKeys[] = $key;
                unset($existingByKey[$key]);
            }
        }
        foreach ($existingRows as $row) {
            $existingKey = (string) ($row['reference_key'] ?? '');
            if (isset($existingByKey[$existingKey])) {
                $nextKeys[] = $existingKey;
            }
        }

        foreach ($nextKeys as $index => $key) {
            $saved = $db->Execute(
                sprintf('UPDATE %s SET %s = ?, updated_by_user_key = ? WHERE %s = ?', $reference['table'], $reference['sort'], $reference['key']),
                [$index + 1, $userKey ?: null, $key]
            );
            if ($saved === false) {
                throw new RuntimeException($reference['label'] . ' sort update failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        bx_audit('SORT', $reference['audit_module'], $type, [
            'reference_type' => $type,
            'order_keys' => $nextKeys,
        ], 'Administrator sorted bed reference rows.');

        $rows = $reference['rows']();
        if ($rows === []) {
            throw new RuntimeException($reference['label'] . ' sort read-back returned no rows.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException($reference['label'] . ' sort transaction could not commit.');
        }
        $transactionStarted = false;

        return $rows;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_validate_bed_reference_status(string $status): string
{
    $status = strtoupper(trim($status));
    if (!in_array($status, ['ACTIVE', 'INACTIVE', 'DELETED'], true)) {
        throw new RuntimeException('Invalid reference status.');
    }

    return $status;
}

function bx_save_project_bed_treatment(array $input, ?string $userKey = null): array
{
    bx_ensure_project_bed_reference_schema();
    $db = bx_db();
    $treatmentKey = trim((string) ($input['bed_treatment_key'] ?? ''));
    $code = strtoupper(trim((string) ($input['treatment_code'] ?? '')));
    $name = trim((string) ($input['treatment_name'] ?? ''));
    $description = trim((string) ($input['treatment_description'] ?? ''));
    $status = bx_validate_bed_reference_status((string) ($input['treatment_status'] ?? 'ACTIVE'));
    $sortOrder = max(0, (int) ($input['treatment_sort_order'] ?? 0));

    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,80}$/', $code)) {
        throw new RuntimeException('Treatment code must use 2-80 uppercase letters, numbers, underscores, or hyphens.');
    }
    if ($name === '' || strlen($name) > 160) {
        throw new RuntimeException('Treatment name is required and must be 160 characters or fewer.');
    }
    if ($treatmentKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $treatmentKey)) {
        throw new RuntimeException('Invalid bed treatment key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Bed treatment transaction could not start.');
        }
        $transactionStarted = true;

        $duplicate = (int) $db->GetOne('SELECT COUNT(*) FROM project_bed_treatment WHERE treatment_code = ? AND bed_treatment_key <> ?', [$code, $treatmentKey]);
        if ($duplicate > 0) {
            throw new RuntimeException('Treatment code already exists.');
        }

        if ($treatmentKey === '') {
            $treatmentKey = bx_unique_firebase_document_key('project_bed_treatment', 'bed_treatment_key');
            if ($sortOrder === 0) {
                $sortOrder = (int) $db->GetOne('SELECT COALESCE(MAX(treatment_sort_order), 0) + 1 FROM project_bed_treatment');
            }
            $saved = $db->Execute(
                'INSERT INTO project_bed_treatment (bed_treatment_key, treatment_code, treatment_name, treatment_description, treatment_status, treatment_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$treatmentKey, $code, $name, $description === '' ? null : $description, $status, $sortOrder, $userKey ?: null, $userKey ?: null]
            );
            $auditAction = 'CREATE';
        } else {
            $saved = $db->Execute(
                'UPDATE project_bed_treatment SET treatment_code = ?, treatment_name = ?, treatment_description = ?, treatment_status = ?, treatment_sort_order = ?, updated_by_user_key = ? WHERE bed_treatment_key = ?',
                [$code, $name, $description === '' ? null : $description, $status, $sortOrder, $userKey ?: null, $treatmentKey]
            );
            $auditAction = 'UPDATE';
        }
        if ($saved === false) {
            throw new RuntimeException('Bed treatment save failed: ' . trim((string) $db->ErrorMsg()));
        }

        $readBack = $db->GetRow("SELECT bed_treatment_key, treatment_code, treatment_name, COALESCE(treatment_description, '') AS treatment_description, treatment_status, treatment_sort_order, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at FROM project_bed_treatment WHERE bed_treatment_key = ? LIMIT 1", [$treatmentKey]);
        if (
            !is_array($readBack)
            || (string) ($readBack['treatment_code'] ?? '') !== $code
            || (string) ($readBack['treatment_name'] ?? '') !== $name
            || (string) ($readBack['treatment_status'] ?? '') !== $status
        ) {
            throw new RuntimeException('Bed treatment read-back did not match the saved values.');
        }

        bx_audit($auditAction, 'project_bed_treatment', $treatmentKey, [
            'treatment_code' => $code,
            'treatment_name' => $name,
            'treatment_status' => $status,
        ], 'Administrator saved bed treatment reference.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Bed treatment transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_set_project_bed_treatment_status(array $input, ?string $userKey = null): array
{
    bx_ensure_project_bed_reference_schema();
    $db = bx_db();
    $treatmentKey = trim((string) ($input['bed_treatment_key'] ?? ''));
    $status = bx_validate_bed_reference_status((string) ($input['treatment_status'] ?? 'INACTIVE'));
    if (!preg_match('/^[A-Za-z0-9]{20}$/', $treatmentKey)) {
        throw new RuntimeException('Invalid bed treatment key.');
    }

    $saved = $db->Execute('UPDATE project_bed_treatment SET treatment_status = ?, updated_by_user_key = ? WHERE bed_treatment_key = ?', [$status, $userKey ?: null, $treatmentKey]);
    if ($saved === false) {
        throw new RuntimeException('Bed treatment status update failed: ' . trim((string) $db->ErrorMsg()));
    }
    $readBack = $db->GetRow("SELECT bed_treatment_key, treatment_code, treatment_name, COALESCE(treatment_description, '') AS treatment_description, treatment_status, treatment_sort_order, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at FROM project_bed_treatment WHERE bed_treatment_key = ? LIMIT 1", [$treatmentKey]);
    if (!is_array($readBack) || (string) ($readBack['treatment_status'] ?? '') !== $status) {
        throw new RuntimeException('Bed treatment status read-back did not match.');
    }
    bx_audit($status === 'DELETED' ? 'DELETE' : 'STATUS', 'project_bed_treatment', $treatmentKey, ['treatment_status' => $status], 'Administrator changed bed treatment status.');

    return $readBack;
}

function bx_save_project_bed_source(array $input, ?string $userKey = null): array
{
    bx_ensure_project_bed_reference_schema();
    $db = bx_db();
    $sourceKey = trim((string) ($input['bed_source_key'] ?? ''));
    $code = strtoupper(trim((string) ($input['bed_source_code'] ?? '')));
    $name = trim((string) ($input['bed_source_name'] ?? ''));
    $description = trim((string) ($input['bed_source_description'] ?? ''));
    $status = bx_validate_bed_reference_status((string) ($input['bed_source_status'] ?? 'ACTIVE'));
    $sortOrder = max(0, (int) ($input['bed_source_sort_order'] ?? 0));

    if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,80}$/', $code)) {
        throw new RuntimeException('Admission source code must use 2-80 uppercase letters, numbers, underscores, or hyphens.');
    }
    if ($name === '' || strlen($name) > 160) {
        throw new RuntimeException('Admission source name is required and must be 160 characters or fewer.');
    }
    if ($sourceKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $sourceKey)) {
        throw new RuntimeException('Invalid admission source key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Admission source transaction could not start.');
        }
        $transactionStarted = true;

        $duplicate = (int) $db->GetOne('SELECT COUNT(*) FROM project_bed_source WHERE bed_source_code = ? AND bed_source_key <> ?', [$code, $sourceKey]);
        if ($duplicate > 0) {
            throw new RuntimeException('Admission source code already exists.');
        }

        if ($sourceKey === '') {
            $sourceKey = bx_unique_firebase_document_key('project_bed_source', 'bed_source_key');
            if ($sortOrder === 0) {
                $sortOrder = (int) $db->GetOne('SELECT COALESCE(MAX(bed_source_sort_order), 0) + 1 FROM project_bed_source');
            }
            $saved = $db->Execute(
                'INSERT INTO project_bed_source (bed_source_key, bed_source_code, bed_source_name, bed_source_description, bed_source_status, bed_source_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$sourceKey, $code, $name, $description === '' ? null : $description, $status, $sortOrder, $userKey ?: null, $userKey ?: null]
            );
            $auditAction = 'CREATE';
        } else {
            $saved = $db->Execute(
                'UPDATE project_bed_source SET bed_source_code = ?, bed_source_name = ?, bed_source_description = ?, bed_source_status = ?, bed_source_sort_order = ?, updated_by_user_key = ? WHERE bed_source_key = ?',
                [$code, $name, $description === '' ? null : $description, $status, $sortOrder, $userKey ?: null, $sourceKey]
            );
            $auditAction = 'UPDATE';
        }
        if ($saved === false) {
            throw new RuntimeException('Admission source save failed: ' . trim((string) $db->ErrorMsg()));
        }

        $readBack = $db->GetRow("SELECT bed_source_key, bed_source_code, bed_source_name, COALESCE(bed_source_description, '') AS bed_source_description, bed_source_status, bed_source_sort_order, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at FROM project_bed_source WHERE bed_source_key = ? LIMIT 1", [$sourceKey]);
        if (
            !is_array($readBack)
            || (string) ($readBack['bed_source_code'] ?? '') !== $code
            || (string) ($readBack['bed_source_name'] ?? '') !== $name
            || (string) ($readBack['bed_source_status'] ?? '') !== $status
        ) {
            throw new RuntimeException('Admission source read-back did not match the saved values.');
        }

        bx_audit($auditAction, 'project_bed_source', $sourceKey, [
            'bed_source_code' => $code,
            'bed_source_name' => $name,
            'bed_source_status' => $status,
        ], 'Administrator saved admission source reference.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Admission source transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_set_project_bed_source_status(array $input, ?string $userKey = null): array
{
    bx_ensure_project_bed_reference_schema();
    $db = bx_db();
    $sourceKey = trim((string) ($input['bed_source_key'] ?? ''));
    $status = bx_validate_bed_reference_status((string) ($input['bed_source_status'] ?? 'INACTIVE'));
    if (!preg_match('/^[A-Za-z0-9]{20}$/', $sourceKey)) {
        throw new RuntimeException('Invalid admission source key.');
    }

    $saved = $db->Execute('UPDATE project_bed_source SET bed_source_status = ?, updated_by_user_key = ? WHERE bed_source_key = ?', [$status, $userKey ?: null, $sourceKey]);
    if ($saved === false) {
        throw new RuntimeException('Admission source status update failed: ' . trim((string) $db->ErrorMsg()));
    }
    $readBack = $db->GetRow("SELECT bed_source_key, bed_source_code, bed_source_name, COALESCE(bed_source_description, '') AS bed_source_description, bed_source_status, bed_source_sort_order, DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at, DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') AS updated_at FROM project_bed_source WHERE bed_source_key = ? LIMIT 1", [$sourceKey]);
    if (!is_array($readBack) || (string) ($readBack['bed_source_status'] ?? '') !== $status) {
        throw new RuntimeException('Admission source status read-back did not match.');
    }
    bx_audit($status === 'DELETED' ? 'DELETE' : 'STATUS', 'project_bed_source', $sourceKey, ['bed_source_status' => $status], 'Administrator changed admission source status.');

    return $readBack;
}

function bx_project_task_rows(int $limit = 30): array
{
    bx_ensure_project_task_schema();
    $limit = max(1, min(100, $limit));
    $rows = bx_db()->GetAll(
        "SELECT
            t.task_key,
            COALESCE(t.task_code, '') AS task_code,
            t.task_title,
            COALESCE(t.task_description, '') AS task_description,
            COALESCE(t.task_group_keys, '[]') AS task_group_keys,
            COALESCE(t.task_bypass_group_keys, '[]') AS task_bypass_group_keys,
            t.task_type,
            t.task_status,
            t.task_priority,
            t.task_color_hex,
            t.task_can_run_manually,
            t.task_can_run_via_api,
            t.task_can_run_if_bed_vacant,
            t.task_can_run_if_bed_occupied,
            t.task_requires_bed_treatment,
            t.task_requires_admission_source,
            t.task_canvas_x,
            t.task_canvas_y,
            t.task_sort_order,
            DATE_FORMAT(t.created_at, '%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(t.updated_at, '%Y-%m-%d %H:%i') AS updated_at
        FROM project_task t
        ORDER BY
            CASE t.task_type
                WHEN 'PRIMARY' THEN 1
                WHEN 'SECONDARY' THEN 2
                ELSE 3
            END,
            CASE t.task_status
                WHEN 'ACTIVE' THEN 1
                ELSE 2
            END,
            t.task_sort_order ASC,
            t.updated_at DESC,
            t.x_id DESC
        LIMIT {$limit}"
    );

    return is_array($rows) ? $rows : [];
}

function bx_project_task_color_hex(array $input): string
{
    $color = strtoupper(trim((string) ($input['task_color_hex'] ?? '#00000000')));
    if ($color === '') {
        return '#00000000';
    }
    if (preg_match('/^[0-9A-F]{6}([0-9A-F]{2})?$/', $color) === 1) {
        $color = '#' . $color;
    }
    if (preg_match('/^#[0-9A-F]{6}([0-9A-F]{2})?$/', $color) !== 1) {
        throw new RuntimeException('Task color must be a valid hex color.');
    }

    return $color;
}

function bx_project_task_canvas_coordinate(array $input, string $key): int
{
    $value = trim((string) ($input[$key] ?? '24'));
    if (!preg_match('/^\d+$/', $value)) {
        throw new RuntimeException('Task canvas position must be a whole number.');
    }

    return min(10000, max(0, (int) $value));
}

function bx_project_task_checkbox_value(array $input, string $key): int
{
    $value = $input[$key] ?? '0';
    if (is_array($value)) {
        $value = end($value);
    }
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function bx_project_task_group_keys(array $input): array
{
    $raw = $input['task_group_keys'] ?? $input['task_group_keys[]'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
    }
    if (!is_array($raw)) {
        return [];
    }

    $groupKeys = [];
    foreach ($raw as $groupKey) {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '' || !preg_match('/^(?:[A-Za-z0-9]{20}|[A-Fa-f0-9-]{36})$/', $groupKey) || in_array($groupKey, $groupKeys, true)) {
            continue;
        }
        $exists = (int) bx_db()->GetOne(
            "SELECT COUNT(*) FROM project_user_group WHERE group_key = ? AND group_status <> 'DELETED'",
            [$groupKey]
        );
        if ($exists !== 1) {
            throw new RuntimeException('Selected user group was not found.');
        }
        $groupKeys[] = $groupKey;
    }

    return $groupKeys;
}

function bx_project_task_bypass_group_keys(array $input, array $taskGroupKeys): array
{
    $raw = $input['task_bypass_group_keys'] ?? $input['task_bypass_group_keys[]'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
    }
    if (!is_array($raw)) {
        return [];
    }

    $selectedLookup = array_fill_keys($taskGroupKeys, true);
    $bypassGroupKeys = [];
    foreach ($raw as $groupKey) {
        $groupKey = trim((string) $groupKey);
        if ($groupKey === '' || !preg_match('/^(?:[A-Za-z0-9]{20}|[A-Fa-f0-9-]{36})$/', $groupKey) || in_array($groupKey, $bypassGroupKeys, true)) {
            continue;
        }
        if (!isset($selectedLookup[$groupKey])) {
            throw new RuntimeException('Bypass group must also be selected for this task.');
        }
        $bypassGroupKeys[] = $groupKey;
    }

    return $bypassGroupKeys;
}

function bx_project_task_stage_color_hex(array $input): string
{
    $color = strtoupper(trim((string) ($input['stage_color_hex'] ?? '#00000000')));
    if ($color === '') {
        return '#00000000';
    }
    if (preg_match('/^[0-9A-F]{6}([0-9A-F]{2})?$/', $color) === 1) {
        $color = '#' . $color;
    }
    if (preg_match('/^#[0-9A-F]{6}([0-9A-F]{2})?$/', $color) !== 1) {
        throw new RuntimeException('Stage color must be a valid hex color.');
    }

    return $color;
}

function bx_project_task_stage_connection_trigger_point(array $input): string
{
    $value = strtoupper(trim((string) ($input['connected_task_trigger_point'] ?? 'CURRENT_STAGE_FINISHED')));
    $allowed = ['PREVIOUS_STAGE_FINISHED', 'CURRENT_STAGE_FINISHED'];

    return in_array($value, $allowed, true) ? $value : 'CURRENT_STAGE_FINISHED';
}

function bx_ensure_project_task_stage_schema(): void
{
    bx_ensure_project_task_schema();

    $db = bx_db();
    $indexExists = static function (string $indexName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [BUILDERX_DB_NAME, 'project_task_stage', $indexName]
        ) > 0;
    };

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_task_stage (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_stage_key VARCHAR(40) NOT NULL UNIQUE,
            task_key VARCHAR(40) NOT NULL,
            stage_label VARCHAR(160) NOT NULL DEFAULT '',
            stage_description TEXT NULL,
            stage_color_hex CHAR(9) NOT NULL DEFAULT '#00000000',
            stage_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE',
            stage_ends_task TINYINT(1) NOT NULL DEFAULT 0,
            stage_can_run_manually TINYINT(1) NOT NULL DEFAULT 0,
            stage_can_run_via_api TINYINT(1) NOT NULL DEFAULT 0,
            connected_task_key VARCHAR(40) NULL,
            connected_task_trigger_point ENUM('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED') NOT NULL DEFAULT 'CURRENT_STAGE_FINISHED',
            stage_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_task_stage_task (task_key, stage_status, stage_sort_order),
            INDEX idx_project_task_stage_status (stage_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task stage schema setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    bx_add_column_if_missing('project_task_stage', 'stage_label', "VARCHAR(160) NOT NULL DEFAULT '' AFTER task_key");
    bx_add_column_if_missing('project_task_stage', 'stage_description', 'TEXT NULL AFTER stage_label');
    bx_add_column_if_missing('project_task_stage', 'stage_color_hex', "CHAR(9) NOT NULL DEFAULT '#00000000' AFTER stage_description");
    bx_add_column_if_missing('project_task_stage', 'stage_status', "ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE' AFTER stage_color_hex");
    bx_add_column_if_missing('project_task_stage', 'stage_ends_task', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_status');
    bx_add_column_if_missing('project_task_stage', 'stage_can_run_manually', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_ends_task');
    bx_add_column_if_missing('project_task_stage', 'stage_can_run_via_api', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER stage_can_run_manually');
    bx_add_column_if_missing('project_task_stage', 'connected_task_key', 'VARCHAR(40) NULL AFTER stage_can_run_via_api');
    bx_add_column_if_missing('project_task_stage', 'connected_task_trigger_point', "ENUM('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED') NOT NULL DEFAULT 'CURRENT_STAGE_FINISHED' AFTER connected_task_key");
    bx_add_column_if_missing('project_task_stage', 'stage_sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER connected_task_trigger_point');
    bx_add_column_if_missing('project_task_stage', 'created_by_user_key', 'CHAR(36) NULL AFTER stage_sort_order');
    bx_add_column_if_missing('project_task_stage', 'updated_by_user_key', 'CHAR(36) NULL AFTER created_by_user_key');

    $stageKeyColumnType = strtolower((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage', 'task_stage_key']
    ));
    if ($stageKeyColumnType !== 'varchar(40)') {
        $keyAltered = $db->Execute('ALTER TABLE project_task_stage MODIFY task_stage_key VARCHAR(40) NOT NULL');
        if ($keyAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage key column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $statusColumnType = strtoupper((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage', 'stage_status']
    ));
    if ($statusColumnType !== "ENUM('ACTIVE','INACTIVE')") {
        $normalized = $db->Execute("UPDATE project_task_stage SET stage_status = CASE WHEN stage_status = 'ACTIVE' THEN 'ACTIVE' ELSE 'INACTIVE' END");
        if ($normalized === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage status normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        $statusAltered = $db->Execute("ALTER TABLE project_task_stage MODIFY stage_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'INACTIVE'");
        if ($statusAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage status column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $stageColorColumn = $db->GetRow(
        'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [BUILDERX_DB_NAME, 'project_task_stage', 'stage_color_hex']
    );
    $stageColorDefault = is_array($stageColorColumn) ? trim((string) ($stageColorColumn['column_default'] ?? ''), "'") : '';
    if (
        is_array($stageColorColumn)
        && (strtolower((string) ($stageColorColumn['column_type'] ?? '')) !== 'char(9)' || $stageColorDefault !== '#00000000')
    ) {
        $stageColorAltered = $db->Execute("ALTER TABLE project_task_stage MODIFY stage_color_hex CHAR(9) NOT NULL DEFAULT '#00000000'");
        if ($stageColorAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage color column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $colorNormalized = $db->Execute("UPDATE project_task_stage SET stage_color_hex = '#00000000' WHERE stage_color_hex IS NULL OR stage_color_hex NOT REGEXP '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$'");
    if ($colorNormalized === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task stage color normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    $triggerNormalized = $db->Execute("UPDATE project_task_stage SET connected_task_trigger_point = 'CURRENT_STAGE_FINISHED' WHERE connected_task_trigger_point IS NULL OR connected_task_trigger_point NOT IN ('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED') OR connected_task_key IS NULL");
    if ($triggerNormalized === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task stage trigger normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }
    $triggerColumnType = strtoupper((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage', 'connected_task_trigger_point']
    ));
    if ($triggerColumnType !== "ENUM('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED')") {
        $triggerAltered = $db->Execute("ALTER TABLE project_task_stage MODIFY connected_task_trigger_point ENUM('PREVIOUS_STAGE_FINISHED','CURRENT_STAGE_FINISHED') NOT NULL DEFAULT 'CURRENT_STAGE_FINISHED'");
        if ($triggerAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage trigger column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    foreach ([
	        'idx_project_task_stage_task' => 'INDEX idx_project_task_stage_task (task_key, stage_status, stage_sort_order)',
	        'idx_project_task_stage_status' => 'INDEX idx_project_task_stage_status (stage_status)',
	        'idx_project_task_stage_connected' => 'INDEX idx_project_task_stage_connected (connected_task_key)',
	    ] as $indexName => $definition) {
        if ($indexExists($indexName)) {
            continue;
        }
        $indexed = $db->Execute("ALTER TABLE project_task_stage ADD {$definition}");
        if ($indexed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage index setup failed for ' . $indexName . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }
}

function bx_project_task_stage_rows(int $limit = 200): array
{
    bx_ensure_project_task_stage_schema();
    bx_repair_project_task_default_stages();
    $limit = max(1, min(500, $limit));
    $rows = bx_db()->GetAll(
        "SELECT
            task_stage_key,
            task_key,
            stage_label,
            COALESCE(stage_description, '') AS stage_description,
            stage_color_hex,
            stage_status,
            stage_ends_task,
            stage_can_run_manually,
            stage_can_run_via_api,
            COALESCE(connected_task_key, '') AS connected_task_key,
            connected_task_trigger_point,
            stage_sort_order,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') AS updated_at
        FROM project_task_stage
        ORDER BY task_key ASC, stage_sort_order ASC, updated_at DESC, x_id DESC
        LIMIT {$limit}"
    );

    return is_array($rows) ? $rows : [];
}

function bx_ensure_project_task_stage_response_schema(): void
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $indexExists = static function (string $indexName) use ($db): bool {
        return (int) $db->GetOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [BUILDERX_DB_NAME, 'project_task_stage_response', $indexName]
        ) > 0;
    };

    $saved = $db->Execute("
        CREATE TABLE IF NOT EXISTS project_task_stage_response (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_stage_response_key VARCHAR(40) NOT NULL UNIQUE,
            task_key VARCHAR(40) NOT NULL,
            task_stage_key VARCHAR(40) NOT NULL,
            response_label VARCHAR(160) NOT NULL,
            response_description TEXT NULL,
            response_color_hex CHAR(9) NOT NULL DEFAULT '#00000000',
            response_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
            response_sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            created_by_user_key CHAR(36) NULL,
            updated_by_user_key CHAR(36) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_task_stage_response_stage (task_stage_key, response_status, response_sort_order),
            INDEX idx_project_task_stage_response_task (task_key, response_status, response_sort_order),
            INDEX idx_project_task_stage_response_status (response_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    if ($saved === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task stage response schema setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    bx_add_column_if_missing('project_task_stage_response', 'task_key', 'VARCHAR(40) NOT NULL AFTER task_stage_response_key');
    bx_add_column_if_missing('project_task_stage_response', 'task_stage_key', 'VARCHAR(40) NOT NULL AFTER task_key');
    bx_add_column_if_missing('project_task_stage_response', 'response_label', "VARCHAR(160) NOT NULL DEFAULT '' AFTER task_stage_key");
    bx_add_column_if_missing('project_task_stage_response', 'response_description', 'TEXT NULL AFTER response_label');
    bx_add_column_if_missing('project_task_stage_response', 'response_color_hex', "CHAR(9) NOT NULL DEFAULT '#00000000' AFTER response_description");
    bx_add_column_if_missing('project_task_stage_response', 'response_status', "ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE' AFTER response_color_hex");
    bx_add_column_if_missing('project_task_stage_response', 'response_sort_order', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER response_status');
    bx_add_column_if_missing('project_task_stage_response', 'created_by_user_key', 'CHAR(36) NULL AFTER response_sort_order');
    bx_add_column_if_missing('project_task_stage_response', 'updated_by_user_key', 'CHAR(36) NULL AFTER created_by_user_key');

    $responseKeyColumnType = strtolower((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage_response', 'task_stage_response_key']
    ));
    if ($responseKeyColumnType !== 'varchar(40)') {
        $keyAltered = $db->Execute('ALTER TABLE project_task_stage_response MODIFY task_stage_response_key VARCHAR(40) NOT NULL');
        if ($keyAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response key column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $statusColumnType = strtoupper((string) $db->GetOne(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
        [BUILDERX_DB_NAME, 'project_task_stage_response', 'response_status']
    ));
    if ($statusColumnType !== "ENUM('ACTIVE','INACTIVE')") {
        $normalized = $db->Execute("UPDATE project_task_stage_response SET response_status = CASE WHEN response_status = 'ACTIVE' THEN 'ACTIVE' ELSE 'INACTIVE' END");
        if ($normalized === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response status normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
        $statusAltered = $db->Execute("ALTER TABLE project_task_stage_response MODIFY response_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'");
        if ($statusAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response status column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }
    $statusColumn = $db->GetRow(
        'SELECT COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [BUILDERX_DB_NAME, 'project_task_stage_response', 'response_status']
    );
    $responseStatusDefault = is_array($statusColumn) ? trim((string) ($statusColumn['column_default'] ?? ''), "'") : '';
    if ($responseStatusDefault !== 'ACTIVE') {
        $statusDefaultAltered = $db->Execute("ALTER TABLE project_task_stage_response MODIFY response_status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE'");
        if ($statusDefaultAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response status default setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $responseColorColumn = $db->GetRow(
        'SELECT COLUMN_TYPE AS column_type, COLUMN_DEFAULT AS column_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
        [BUILDERX_DB_NAME, 'project_task_stage_response', 'response_color_hex']
    );
    $responseColorDefault = is_array($responseColorColumn) ? trim((string) ($responseColorColumn['column_default'] ?? ''), "'") : '';
    if (
        is_array($responseColorColumn)
        && (strtolower((string) ($responseColorColumn['column_type'] ?? '')) !== 'char(9)' || $responseColorDefault !== '#00000000')
    ) {
        $responseColorAltered = $db->Execute("ALTER TABLE project_task_stage_response MODIFY response_color_hex CHAR(9) NOT NULL DEFAULT '#00000000'");
        if ($responseColorAltered === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response color column setup failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }

    $colorNormalized = $db->Execute("UPDATE project_task_stage_response SET response_color_hex = '#00000000' WHERE response_color_hex IS NULL OR response_color_hex NOT REGEXP '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$'");
    if ($colorNormalized === false) {
        $databaseError = trim((string) $db->ErrorMsg());
        throw new RuntimeException('Project task stage response color normalization failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }

    foreach ([
        'idx_project_task_stage_response_stage' => 'INDEX idx_project_task_stage_response_stage (task_stage_key, response_status, response_sort_order)',
        'idx_project_task_stage_response_task' => 'INDEX idx_project_task_stage_response_task (task_key, response_status, response_sort_order)',
        'idx_project_task_stage_response_status' => 'INDEX idx_project_task_stage_response_status (response_status)',
    ] as $indexName => $definition) {
        if ($indexExists($indexName)) {
            continue;
        }
        $indexed = $db->Execute("ALTER TABLE project_task_stage_response ADD {$definition}");
        if ($indexed === false) {
            $databaseError = trim((string) $db->ErrorMsg());
            throw new RuntimeException('Project task stage response index setup failed for ' . $indexName . ($databaseError !== '' ? ': ' . $databaseError : '.'));
        }
    }
}

function bx_project_task_stage_response_rows(int $limit = 500): array
{
    bx_ensure_project_task_stage_response_schema();
    $limit = max(1, min(1000, $limit));
    $rows = bx_db()->GetAll(
        "SELECT
            task_stage_response_key,
            task_key,
            task_stage_key,
            response_label,
            COALESCE(response_description, '') AS response_description,
            response_color_hex,
            response_status,
            response_sort_order,
            DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') AS created_at,
            DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i') AS updated_at
        FROM project_task_stage_response
        ORDER BY task_key ASC, task_stage_key ASC, response_sort_order ASC, updated_at DESC, x_id DESC
        LIMIT {$limit}"
    );

    return is_array($rows) ? $rows : [];
}

function bx_project_task_stage_response_color_hex(array $input): string
{
    $color = strtoupper(trim((string) ($input['response_color_hex'] ?? '#00000000')));
    if ($color === '' || $color === 'TRANSPARENT') {
        return '#00000000';
    }
    if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
        return $color;
    }
    if (preg_match('/^#[0-9A-F]{8}$/', $color)) {
        return $color;
    }

    throw new RuntimeException('Response color must be a valid hex color.');
}

function bx_project_task_stage_ends_task_value(array $input): int
{
    return bx_project_task_stage_checkbox_value($input, 'stage_ends_task');
}

function bx_project_task_stage_checkbox_value(array $input, string $key): int
{
    $value = $input[$key] ?? '0';
    if (is_array($value)) {
        $value = end($value);
    }
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
}

function bx_insert_project_task_stage_row($db, string $taskKey, string $stageLabel, string $stageDescription, string $stageColorHex, string $stageStatus, int $stageEndsTask, int $stageCanRunManually, int $stageCanRunViaApi, int $sortOrder, ?string $userKey, string $auditAction, string $auditMessage): array
{
    $taskStageKey = '';
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = bx_firebase_document_id();
        $exists = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_stage_key = ?', [$candidate]);
        if ($exists === 0) {
            $taskStageKey = $candidate;
            break;
        }
    }
    if ($taskStageKey === '' || !preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Project task stage key generation failed.');
    }

    if ($sortOrder === 0) {
        $sortOrder = (int) $db->GetOne(
            'SELECT COALESCE(MAX(stage_sort_order), 0) + 1 FROM project_task_stage WHERE task_key = ?',
            [$taskKey]
        );
    }

    $saved = $db->Execute(
        'INSERT INTO project_task_stage (task_stage_key, task_key, stage_label, stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, stage_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$taskStageKey, $taskKey, $stageLabel, $stageDescription, $stageColorHex, $stageStatus, $stageEndsTask, $stageCanRunManually, $stageCanRunViaApi, $sortOrder, $userKey ?: null, $userKey ?: null]
    );
    if ($saved === false) {
        throw new RuntimeException('Project task stage insert failed: ' . trim((string) $db->ErrorMsg()));
    }

    bx_audit($auditAction, 'project_task_stage', $taskStageKey, [
        'task_stage_key' => $taskStageKey,
        'task_key' => $taskKey,
        'stage_label' => $stageLabel,
        'stage_color_hex' => $stageColorHex,
        'stage_status' => $stageStatus,
        'stage_ends_task' => $stageEndsTask,
        'stage_can_run_manually' => $stageCanRunManually,
        'stage_can_run_via_api' => $stageCanRunViaApi,
    ], $auditMessage);

    $readBack = $db->GetRow('SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, \'\') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, \'\') AS connected_task_key, connected_task_trigger_point, stage_sort_order FROM project_task_stage WHERE task_stage_key = ? LIMIT 1', [$taskStageKey]);
    if (
        !is_array($readBack)
        || (string) ($readBack['task_stage_key'] ?? '') !== $taskStageKey
        || (string) ($readBack['task_key'] ?? '') !== $taskKey
        || (string) ($readBack['stage_label'] ?? '') !== $stageLabel
        || (string) ($readBack['stage_description'] ?? '') !== $stageDescription
        || (string) ($readBack['stage_color_hex'] ?? '') !== $stageColorHex
        || (string) ($readBack['stage_status'] ?? '') !== $stageStatus
        || (int) ($readBack['stage_ends_task'] ?? 0) !== $stageEndsTask
        || (int) ($readBack['stage_can_run_manually'] ?? 0) !== $stageCanRunManually
        || (int) ($readBack['stage_can_run_via_api'] ?? 0) !== $stageCanRunViaApi
    ) {
        throw new RuntimeException('Project task stage read-back did not match the written values.');
    }

    return $readBack;
}

function bx_project_task_default_stage_input(string $taskKey): array
{
    return [
        'task_key' => $taskKey,
        'stage_label' => 'NEW',
        'stage_description' => 'Default starting stage.',
        'stage_color_hex' => '#00000000',
        'stage_status' => 'ACTIVE',
        'stage_ends_task' => 0,
        'stage_can_run_manually' => 0,
        'stage_can_run_via_api' => 0,
        'stage_sort_order' => 1,
    ];
}

function bx_repair_project_task_default_stages(?string $userKey = null): int
{
    bx_ensure_project_task_stage_schema();
    $db = bx_db();
    $missingTasks = $db->GetAll(
        'SELECT t.task_key
        FROM project_task t
        LEFT JOIN project_task_stage s ON s.task_key = t.task_key
        GROUP BY t.task_key
        HAVING COUNT(s.x_id) = 0'
    ) ?: [];

    if ($missingTasks === []) {
        return 0;
    }

    $created = 0;
    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task default stage repair transaction could not start.');
        }
        $transactionStarted = true;

        foreach ($missingTasks as $row) {
            $taskKey = (string) ($row['task_key'] ?? '');
            if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
                continue;
            }
            $defaultStage = bx_project_task_default_stage_input($taskKey);
            bx_insert_project_task_stage_row(
                $db,
                $taskKey,
                (string) $defaultStage['stage_label'],
                (string) $defaultStage['stage_description'],
                (string) $defaultStage['stage_color_hex'],
                (string) $defaultStage['stage_status'],
                (int) $defaultStage['stage_ends_task'],
                (int) $defaultStage['stage_can_run_manually'],
                (int) $defaultStage['stage_can_run_via_api'],
                (int) $defaultStage['stage_sort_order'],
                $userKey,
                'AUTO_REPAIR',
                'BuilderX auto-created missing default NEW task stage.'
            );
            $created++;
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task default stage repair transaction could not commit.');
        }
        $transactionStarted = false;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }

    return $created;
}

function bx_create_project_task_stage(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $stageLabel = trim((string) ($input['stage_label'] ?? ''));
    $stageDescription = trim((string) ($input['stage_description'] ?? ''));
    $stageColorHex = bx_project_task_stage_color_hex($input);
    $stageStatus = strtoupper(trim((string) ($input['stage_status'] ?? 'INACTIVE')));
    $stageEndsTask = bx_project_task_stage_ends_task_value($input);
    $stageCanRunManually = 0;
    $stageCanRunViaApi = 0;
    $sortOrder = max(0, (int) ($input['stage_sort_order'] ?? 0));
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($stageLabel === '') {
        throw new RuntimeException('Stage label is required.');
    }
    if (strlen($stageLabel) > 160) {
        throw new RuntimeException('Stage label must be 160 characters or fewer.');
    }
    if (strlen($stageDescription) > 2000) {
        throw new RuntimeException('Stage description must be 2000 characters or fewer.');
    }
    if (!in_array($stageStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid stage status.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage transaction could not start.');
        }
        $transactionStarted = true;

        $taskExists = (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$taskKey]);
        if ($taskExists !== 1) {
            throw new RuntimeException('Selected task was not found.');
        }
        if (strtoupper($stageLabel) === 'NEW') {
            throw new RuntimeException('Default NEW stage already exists and cannot be created manually.');
        }

        $readBack = bx_insert_project_task_stage_row(
            $db,
            $taskKey,
            $stageLabel,
            $stageDescription,
            $stageColorHex,
            $stageStatus,
            $stageEndsTask,
            $stageCanRunManually,
            $stageCanRunViaApi,
            $sortOrder,
            $userKey,
            'CREATE',
            'Administrator created project task stage.'
        );

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_update_project_task_stage(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $taskStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $postedTaskKey = trim((string) ($input['task_key'] ?? ''));
    $stageLabel = trim((string) ($input['stage_label'] ?? ''));
    $stageDescription = trim((string) ($input['stage_description'] ?? ''));
    $stageColorHex = bx_project_task_stage_color_hex($input);
    $stageStatus = strtoupper(trim((string) ($input['stage_status'] ?? 'INACTIVE')));
    $stageEndsTask = bx_project_task_stage_ends_task_value($input);
    $stageCanRunManually = 0;
    $stageCanRunViaApi = 0;
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($postedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedTaskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if (strlen($stageDescription) > 2000) {
        throw new RuntimeException('Stage description must be 2000 characters or fewer.');
    }
    if (!in_array($stageStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid stage status.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage update transaction could not start.');
        }
        $transactionStarted = true;

        $existing = $db->GetRow(
            'SELECT task_stage_key, task_key, stage_label, stage_sort_order FROM project_task_stage WHERE task_stage_key = ? LIMIT 1',
            [$taskStageKey]
        );
        if (!is_array($existing)) {
            throw new RuntimeException('Task stage was not found.');
        }

        $taskKey = (string) ($existing['task_key'] ?? '');
        if ($postedTaskKey !== '' && $postedTaskKey !== $taskKey) {
            throw new RuntimeException('Task stage does not belong to the selected task.');
        }

        $isDefaultStage = strtoupper(trim((string) ($existing['stage_label'] ?? ''))) === 'NEW';
        if ($isDefaultStage) {
            $stageLabel = 'NEW';
        } else {
            if ($stageLabel === '') {
                throw new RuntimeException('Stage label is required.');
            }
            if (strlen($stageLabel) > 160) {
                throw new RuntimeException('Stage label must be 160 characters or fewer.');
            }
            if (strtoupper($stageLabel) === 'NEW') {
                throw new RuntimeException('Only the default stage can use the NEW label.');
            }
        }

        $saved = $db->Execute(
            'UPDATE project_task_stage SET stage_label = ?, stage_description = ?, stage_color_hex = ?, stage_status = ?, stage_ends_task = ?, stage_can_run_manually = ?, stage_can_run_via_api = ?, updated_by_user_key = ? WHERE task_stage_key = ?',
            [$stageLabel, $stageDescription, $stageColorHex, $stageStatus, $stageEndsTask, $stageCanRunManually, $stageCanRunViaApi, $userKey ?: null, $taskStageKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task stage update failed: ' . trim((string) $db->ErrorMsg()));
        }

        bx_audit('UPDATE', 'project_task_stage', $taskStageKey, [
            'task_stage_key' => $taskStageKey,
            'task_key' => $taskKey,
            'stage_label' => $stageLabel,
            'stage_color_hex' => $stageColorHex,
            'stage_status' => $stageStatus,
            'stage_ends_task' => $stageEndsTask,
            'stage_can_run_manually' => $stageCanRunManually,
            'stage_can_run_via_api' => $stageCanRunViaApi,
        ], 'Administrator updated project task stage.');

        $readBack = $db->GetRow(
            "SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, '') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, '') AS connected_task_key, connected_task_trigger_point, stage_sort_order
            FROM project_task_stage
            WHERE task_stage_key = ?
            LIMIT 1",
            [$taskStageKey]
        );
        if (
            !is_array($readBack)
            || (string) ($readBack['task_stage_key'] ?? '') !== $taskStageKey
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['stage_label'] ?? '') !== $stageLabel
            || (string) ($readBack['stage_description'] ?? '') !== $stageDescription
            || (string) ($readBack['stage_color_hex'] ?? '') !== $stageColorHex
            || (string) ($readBack['stage_status'] ?? '') !== $stageStatus
            || (int) ($readBack['stage_ends_task'] ?? 0) !== $stageEndsTask
            || (int) ($readBack['stage_can_run_manually'] ?? 0) !== $stageCanRunManually
            || (int) ($readBack['stage_can_run_via_api'] ?? 0) !== $stageCanRunViaApi
        ) {
            throw new RuntimeException('Project task stage update read-back did not match the saved values.');
        }

	        if ($db->CommitTrans() === false) {
	            throw new RuntimeException('Project task stage update transaction could not commit.');
	        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
	}
}

function bx_update_project_task_stage_connection(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $taskStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $postedTaskKey = trim((string) ($input['task_key'] ?? ''));
    $connectedTaskKey = trim((string) ($input['connected_task_key'] ?? ''));
    $connectionTriggerPoint = bx_project_task_stage_connection_trigger_point($input);

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($postedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedTaskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($connectedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $connectedTaskKey)) {
        throw new RuntimeException('Invalid connected task key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage connection transaction could not start.');
        }
        $transactionStarted = true;

        $existing = $db->GetRow(
            'SELECT task_stage_key, task_key, stage_sort_order FROM project_task_stage WHERE task_stage_key = ? LIMIT 1',
            [$taskStageKey]
        );
        if (!is_array($existing)) {
            throw new RuntimeException('Task stage was not found.');
        }

        $taskKey = (string) ($existing['task_key'] ?? '');
        if ($postedTaskKey !== '' && $postedTaskKey !== $taskKey) {
            throw new RuntimeException('Task stage does not belong to the selected task.');
        }
        if ($connectedTaskKey === $taskKey) {
            throw new RuntimeException('A stage must connect to another task.');
        }

        if ($connectedTaskKey !== '') {
            $targetTask = $db->GetRow(
                'SELECT task_key, task_type FROM project_task WHERE task_key = ? LIMIT 1',
                [$connectedTaskKey]
            );
            if (!is_array($targetTask)) {
                throw new RuntimeException('Connected task was not found.');
            }
            if (strtoupper(trim((string) ($targetTask['task_type'] ?? ''))) !== 'SECONDARY') {
                throw new RuntimeException('Stages can only connect to secondary tasks.');
            }
            if ($connectionTriggerPoint === 'PREVIOUS_STAGE_FINISHED') {
                $previousStageCount = (int) $db->GetOne(
                    'SELECT COUNT(*) FROM project_task_stage WHERE task_key = ? AND stage_sort_order < ?',
                    [$taskKey, max(1, (int) ($existing['stage_sort_order'] ?? 0))]
                );
                if ($previousStageCount < 1) {
                    $connectionTriggerPoint = 'CURRENT_STAGE_FINISHED';
                }
            }
        } else {
            $connectionTriggerPoint = 'CURRENT_STAGE_FINISHED';
        }

        $saved = $db->Execute(
            'UPDATE project_task_stage SET connected_task_key = ?, connected_task_trigger_point = ?, updated_by_user_key = ? WHERE task_stage_key = ?',
            [$connectedTaskKey === '' ? null : $connectedTaskKey, $connectionTriggerPoint, $userKey ?: null, $taskStageKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task stage connection update failed: ' . trim((string) $db->ErrorMsg()));
        }

        bx_audit('CONNECT', 'project_task_stage', $taskStageKey, [
            'task_stage_key' => $taskStageKey,
            'task_key' => $taskKey,
            'connected_task_key' => $connectedTaskKey,
            'connected_task_trigger_point' => $connectionTriggerPoint,
        ], $connectedTaskKey === '' ? 'Administrator cleared project task stage connection.' : 'Administrator connected project task stage to a secondary task.');

        $readBack = $db->GetRow(
            "SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, '') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, '') AS connected_task_key, connected_task_trigger_point, stage_sort_order
            FROM project_task_stage
            WHERE task_stage_key = ?
            LIMIT 1",
            [$taskStageKey]
        );
        if (
            !is_array($readBack)
            || (string) ($readBack['task_stage_key'] ?? '') !== $taskStageKey
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['connected_task_key'] ?? '') !== $connectedTaskKey
            || (string) ($readBack['connected_task_trigger_point'] ?? '') !== $connectionTriggerPoint
        ) {
            throw new RuntimeException('Project task stage connection read-back did not match the saved values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage connection transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_delete_project_task_stage(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $taskStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $postedTaskKey = trim((string) ($input['task_key'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($postedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedTaskKey)) {
        throw new RuntimeException('Invalid task key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage delete transaction could not start.');
        }
        $transactionStarted = true;

        $stage = $db->GetRow(
            'SELECT task_stage_key, task_key, stage_label, stage_sort_order FROM project_task_stage WHERE task_stage_key = ? LIMIT 1',
            [$taskStageKey]
        );
        if (!is_array($stage)) {
            throw new RuntimeException('Task stage was not found.');
        }

        $taskKey = (string) ($stage['task_key'] ?? '');
        $stageLabel = (string) ($stage['stage_label'] ?? '');
        if ($postedTaskKey !== '' && $postedTaskKey !== $taskKey) {
            throw new RuntimeException('Task stage does not belong to the selected task.');
        }
        if (strtoupper(trim($stageLabel)) === 'NEW') {
            throw new RuntimeException('The default NEW stage cannot be deleted.');
        }

        $responseCount = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_key = ?', [$taskStageKey]);
        $responsesDeleted = $db->Execute('DELETE FROM project_task_stage_response WHERE task_stage_key = ? AND task_key = ?', [$taskStageKey, $taskKey]);
        if ($responsesDeleted === false) {
            throw new RuntimeException('Project task stage response cleanup failed: ' . trim((string) $db->ErrorMsg()));
        }

        $deleted = $db->Execute('DELETE FROM project_task_stage WHERE task_stage_key = ? AND task_key = ?', [$taskStageKey, $taskKey]);
        if ($deleted === false) {
            throw new RuntimeException('Project task stage delete failed: ' . trim((string) $db->ErrorMsg()));
        }

        $remaining = $db->GetAll(
            "SELECT task_stage_key, stage_label
            FROM project_task_stage
            WHERE task_key = ?
            ORDER BY CASE WHEN UPPER(stage_label) = 'NEW' THEN 0 ELSE 1 END, stage_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskKey]
        ) ?: [];
        foreach ($remaining as $index => $row) {
            $savedOrder = $db->Execute(
                'UPDATE project_task_stage SET stage_sort_order = ?, updated_by_user_key = ? WHERE task_stage_key = ? AND task_key = ?',
                [$index + 1, $userKey ?: null, (string) ($row['task_stage_key'] ?? ''), $taskKey]
            );
            if ($savedOrder === false) {
                throw new RuntimeException('Project task stage order cleanup failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        $remainingStage = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_stage_key = ?', [$taskStageKey]);
        $remainingResponses = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_key = ?', [$taskStageKey]);
        if ($remainingStage !== 0 || $remainingResponses !== 0) {
            throw new RuntimeException('Project task stage delete read-back still found the stage.');
        }

        bx_audit('DELETE', 'project_task_stage', $taskStageKey, [
            'task_stage_key' => $taskStageKey,
            'task_key' => $taskKey,
            'stage_label' => $stageLabel,
            'deleted_response_count' => $responseCount,
            'remaining_stage_count' => count($remaining),
        ], 'Administrator deleted project task stage.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage delete transaction could not commit.');
        }
        $transactionStarted = false;

        return [
            'task_stage_key' => $taskStageKey,
            'task_key' => $taskKey,
            'stage_label' => $stageLabel,
            'deleted_response_count' => $responseCount,
            'remaining_stage_count' => count($remaining),
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_create_project_task_stage_response(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $taskStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $responseLabel = trim((string) ($input['response_label'] ?? ''));
    $responseDescription = trim((string) ($input['response_description'] ?? ''));
    $responseColorHex = bx_project_task_stage_response_color_hex($input);
    $responseStatus = strtoupper(trim((string) ($input['response_status'] ?? 'ACTIVE')));
    $sortOrder = max(0, (int) ($input['response_sort_order'] ?? 0));
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($responseLabel === '') {
        throw new RuntimeException('Response label is required.');
    }
    if (strlen($responseLabel) > 160) {
        throw new RuntimeException('Response label must be 160 characters or fewer.');
    }
    if (strlen($responseDescription) > 2000) {
        throw new RuntimeException('Response description must be 2000 characters or fewer.');
    }
    if (!in_array($responseStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid response status.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage response transaction could not start.');
        }
        $transactionStarted = true;

        $stage = $db->GetRow(
            'SELECT task_stage_key, task_key FROM project_task_stage WHERE task_stage_key = ? LIMIT 1',
            [$taskStageKey]
        );
        if (!is_array($stage) || (string) ($stage['task_key'] ?? '') !== $taskKey) {
            throw new RuntimeException('Task stage was not found for the selected task.');
        }

        $responseKey = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = bx_firebase_document_id();
            $exists = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$candidate]);
            if ($exists === 0) {
                $responseKey = $candidate;
                break;
            }
        }
        if ($responseKey === '' || !preg_match('/^[A-Za-z0-9]{20}$/', $responseKey)) {
            throw new RuntimeException('Project task stage response key generation failed.');
        }

        if ($sortOrder === 0) {
            $sortOrder = (int) $db->GetOne(
                'SELECT COALESCE(MAX(response_sort_order), 0) + 1 FROM project_task_stage_response WHERE task_stage_key = ?',
                [$taskStageKey]
            );
        }

        $saved = $db->Execute(
            'INSERT INTO project_task_stage_response (task_stage_response_key, task_key, task_stage_key, response_label, response_description, response_color_hex, response_status, response_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$responseKey, $taskKey, $taskStageKey, $responseLabel, $responseDescription === '' ? null : $responseDescription, $responseColorHex, $responseStatus, $sortOrder, $userKey ?: null, $userKey ?: null]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task stage response insert failed: ' . trim((string) $db->ErrorMsg()));
        }

        bx_audit('CREATE', 'project_task_stage_response', $responseKey, [
            'task_stage_response_key' => $responseKey,
            'task_key' => $taskKey,
            'task_stage_key' => $taskStageKey,
            'response_label' => $responseLabel,
            'response_color_hex' => $responseColorHex,
            'response_status' => $responseStatus,
            'response_sort_order' => $sortOrder,
        ], 'Administrator created project task stage response.');

        $readBack = $db->GetRow(
            "SELECT task_stage_response_key, task_key, task_stage_key, response_label, COALESCE(response_description, '') AS response_description, response_color_hex, response_status, response_sort_order
            FROM project_task_stage_response
            WHERE task_stage_response_key = ?
            LIMIT 1",
            [$responseKey]
        );
        if (
            !is_array($readBack)
            || (string) ($readBack['task_stage_response_key'] ?? '') !== $responseKey
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['task_stage_key'] ?? '') !== $taskStageKey
            || (string) ($readBack['response_label'] ?? '') !== $responseLabel
            || (string) ($readBack['response_description'] ?? '') !== $responseDescription
            || (string) ($readBack['response_color_hex'] ?? '') !== $responseColorHex
            || (string) ($readBack['response_status'] ?? '') !== $responseStatus
            || (int) ($readBack['response_sort_order'] ?? 0) !== $sortOrder
        ) {
            throw new RuntimeException('Project task stage response read-back did not match the saved values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage response transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_update_project_task_stage_response(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $responseKey = trim((string) ($input['task_stage_response_key'] ?? ''));
    $postedTaskKey = trim((string) ($input['task_key'] ?? ''));
    $postedStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $responseLabel = trim((string) ($input['response_label'] ?? ''));
    $responseDescription = trim((string) ($input['response_description'] ?? ''));
    $responseColorHex = bx_project_task_stage_response_color_hex($input);
    $responseStatus = strtoupper(trim((string) ($input['response_status'] ?? 'INACTIVE')));
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $responseKey)) {
        throw new RuntimeException('Invalid task stage response key.');
    }
    if ($postedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedTaskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($postedStageKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($responseLabel === '') {
        throw new RuntimeException('Response label is required.');
    }
    if (strlen($responseLabel) > 160) {
        throw new RuntimeException('Response label must be 160 characters or fewer.');
    }
    if (strlen($responseDescription) > 2000) {
        throw new RuntimeException('Response description must be 2000 characters or fewer.');
    }
    if (!in_array($responseStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid response status.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage response update transaction could not start.');
        }
        $transactionStarted = true;

        $existing = $db->GetRow(
            'SELECT task_stage_response_key, task_key, task_stage_key, response_sort_order FROM project_task_stage_response WHERE task_stage_response_key = ? LIMIT 1',
            [$responseKey]
        );
        if (!is_array($existing)) {
            throw new RuntimeException('Task stage response was not found.');
        }
        $taskKey = (string) ($existing['task_key'] ?? '');
        $taskStageKey = (string) ($existing['task_stage_key'] ?? '');
        if ($postedTaskKey !== '' && $postedTaskKey !== $taskKey) {
            throw new RuntimeException('Task stage response does not belong to the selected task.');
        }
        if ($postedStageKey !== '' && $postedStageKey !== $taskStageKey) {
            throw new RuntimeException('Task stage response does not belong to the selected stage.');
        }

        $saved = $db->Execute(
            'UPDATE project_task_stage_response SET response_label = ?, response_description = ?, response_color_hex = ?, response_status = ?, updated_by_user_key = ? WHERE task_stage_response_key = ?',
            [$responseLabel, $responseDescription === '' ? null : $responseDescription, $responseColorHex, $responseStatus, $userKey ?: null, $responseKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task stage response update failed: ' . trim((string) $db->ErrorMsg()));
        }

        bx_audit('UPDATE', 'project_task_stage_response', $responseKey, [
            'task_stage_response_key' => $responseKey,
            'task_key' => $taskKey,
            'task_stage_key' => $taskStageKey,
            'response_label' => $responseLabel,
            'response_color_hex' => $responseColorHex,
            'response_status' => $responseStatus,
        ], 'Administrator updated project task stage response.');

        $readBack = $db->GetRow(
            "SELECT task_stage_response_key, task_key, task_stage_key, response_label, COALESCE(response_description, '') AS response_description, response_color_hex, response_status, response_sort_order
            FROM project_task_stage_response
            WHERE task_stage_response_key = ?
            LIMIT 1",
            [$responseKey]
        );
        if (
            !is_array($readBack)
            || (string) ($readBack['task_stage_response_key'] ?? '') !== $responseKey
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['task_stage_key'] ?? '') !== $taskStageKey
            || (string) ($readBack['response_label'] ?? '') !== $responseLabel
            || (string) ($readBack['response_description'] ?? '') !== $responseDescription
            || (string) ($readBack['response_color_hex'] ?? '') !== $responseColorHex
            || (string) ($readBack['response_status'] ?? '') !== $responseStatus
        ) {
            throw new RuntimeException('Project task stage response update read-back did not match the saved values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage response update transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_delete_project_task_stage_response(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $responseKey = trim((string) ($input['task_stage_response_key'] ?? ''));
    $postedTaskKey = trim((string) ($input['task_key'] ?? ''));
    $postedStageKey = trim((string) ($input['task_stage_key'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $responseKey)) {
        throw new RuntimeException('Invalid task stage response key.');
    }
    if ($postedTaskKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedTaskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($postedStageKey !== '' && !preg_match('/^[A-Za-z0-9]{20}$/', $postedStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage response delete transaction could not start.');
        }
        $transactionStarted = true;

        $response = $db->GetRow(
            'SELECT task_stage_response_key, task_key, task_stage_key, response_label FROM project_task_stage_response WHERE task_stage_response_key = ? LIMIT 1',
            [$responseKey]
        );
        if (!is_array($response)) {
            throw new RuntimeException('Task stage response was not found.');
        }
        $taskKey = (string) ($response['task_key'] ?? '');
        $taskStageKey = (string) ($response['task_stage_key'] ?? '');
        if ($postedTaskKey !== '' && $postedTaskKey !== $taskKey) {
            throw new RuntimeException('Task stage response does not belong to the selected task.');
        }
        if ($postedStageKey !== '' && $postedStageKey !== $taskStageKey) {
            throw new RuntimeException('Task stage response does not belong to the selected stage.');
        }

        $deleted = $db->Execute('DELETE FROM project_task_stage_response WHERE task_stage_response_key = ? AND task_stage_key = ?', [$responseKey, $taskStageKey]);
        if ($deleted === false) {
            throw new RuntimeException('Project task stage response delete failed: ' . trim((string) $db->ErrorMsg()));
        }

        $remaining = $db->GetAll(
            "SELECT task_stage_response_key
            FROM project_task_stage_response
            WHERE task_stage_key = ?
            ORDER BY response_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskStageKey]
        ) ?: [];
        foreach ($remaining as $index => $row) {
            $savedOrder = $db->Execute(
                'UPDATE project_task_stage_response SET response_sort_order = ?, updated_by_user_key = ? WHERE task_stage_response_key = ? AND task_stage_key = ?',
                [$index + 1, $userKey ?: null, (string) ($row['task_stage_response_key'] ?? ''), $taskStageKey]
            );
            if ($savedOrder === false) {
                throw new RuntimeException('Project task stage response order cleanup failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        $remainingResponse = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_stage_response_key = ?', [$responseKey]);
        if ($remainingResponse !== 0) {
            throw new RuntimeException('Project task stage response delete read-back still found the response.');
        }

        bx_audit('DELETE', 'project_task_stage_response', $responseKey, [
            'task_stage_response_key' => $responseKey,
            'task_key' => $taskKey,
            'task_stage_key' => $taskStageKey,
            'response_label' => (string) ($response['response_label'] ?? ''),
            'remaining_response_count' => count($remaining),
        ], 'Administrator deleted project task stage response.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage response delete transaction could not commit.');
        }
        $transactionStarted = false;

        return [
            'task_stage_response_key' => $responseKey,
            'task_key' => $taskKey,
            'task_stage_key' => $taskStageKey,
            'response_label' => (string) ($response['response_label'] ?? ''),
            'remaining_response_count' => count($remaining),
        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_project_task_stage_order_input(array $input): array
{
    $raw = $input['stage_order_keys'] ?? $input['stage_order_keys[]'] ?? [];
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
    }
    if (!is_array($raw)) {
        return [];
    }

    $ordered = [];
    foreach ($raw as $key) {
        $key = trim((string) $key);
        if (!preg_match('/^[A-Za-z0-9]{20}$/', $key) || in_array($key, $ordered, true)) {
            continue;
        }
        $ordered[] = $key;
    }

    return $ordered;
}

function bx_project_task_stage_response_order_input(array $input): array
{
    $raw = $input['response_order_keys'] ?? $input['response_order_keys[]'] ?? [];
    if (is_string($raw)) {
        $raw = array_filter(array_map('trim', explode(',', $raw)), static fn (string $value): bool => $value !== '');
    }
    if (!is_array($raw)) {
        return [];
    }

    $ordered = [];
    foreach ($raw as $key) {
        $key = trim((string) $key);
        if (!preg_match('/^[A-Za-z0-9]{20}$/', $key) || in_array($key, $ordered, true)) {
            continue;
        }
        $ordered[] = $key;
    }

    return $ordered;
}

function bx_update_project_task_stage_sort_order(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $orderedStageKeys = bx_project_task_stage_order_input($input);

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($orderedStageKeys === []) {
        throw new RuntimeException('Stage order is required.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage sort transaction could not start.');
        }
        $transactionStarted = true;

        $stages = $db->GetAll(
            "SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, '') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, '') AS connected_task_key, connected_task_trigger_point, stage_sort_order
            FROM project_task_stage
            WHERE task_key = ?
            ORDER BY CASE WHEN UPPER(stage_label) = 'NEW' THEN 0 ELSE 1 END, stage_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskKey]
        ) ?: [];
        if ($stages === []) {
            throw new RuntimeException('No task stages were found.');
        }

        $newStages = [];
        $movableStages = [];
        $movableByKey = [];
        foreach ($stages as $stage) {
            $stageKey = (string) ($stage['task_stage_key'] ?? '');
            $stageLabel = strtoupper(trim((string) ($stage['stage_label'] ?? '')));
            if ($stageLabel === 'NEW') {
                $newStages[] = $stage;
                continue;
            }
            $movableStages[] = $stage;
            $movableByKey[$stageKey] = $stage;
        }
        if ($newStages === []) {
            throw new RuntimeException('Default NEW stage is missing.');
        }

        $nextMovableStages = [];
        foreach ($orderedStageKeys as $stageKey) {
            if (isset($movableByKey[$stageKey])) {
                $nextMovableStages[] = $movableByKey[$stageKey];
                unset($movableByKey[$stageKey]);
            }
        }
        foreach ($movableStages as $stage) {
            $stageKey = (string) ($stage['task_stage_key'] ?? '');
            if (isset($movableByKey[$stageKey])) {
                $nextMovableStages[] = $stage;
            }
        }

        $nextStages = array_merge($newStages, $nextMovableStages);
        foreach ($nextStages as $index => $stage) {
            $stageKey = (string) ($stage['task_stage_key'] ?? '');
            $sortOrder = $index + 1;
            $saved = $db->Execute(
                'UPDATE project_task_stage SET stage_sort_order = ?, updated_by_user_key = ? WHERE task_stage_key = ? AND task_key = ?',
                [$sortOrder, $userKey ?: null, $stageKey, $taskKey]
            );
            if ($saved === false) {
                throw new RuntimeException('Project task stage sort update failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        bx_audit('SORT', 'project_task_stage', $taskKey, [
            'task_key' => $taskKey,
            'stage_order_keys' => array_map(static fn (array $stage): string => (string) ($stage['task_stage_key'] ?? ''), $nextStages),
        ], 'Administrator sorted project task stages.');

        $readBack = $db->GetAll(
            "SELECT task_stage_key, task_key, stage_label, COALESCE(stage_description, '') AS stage_description, stage_color_hex, stage_status, stage_ends_task, stage_can_run_manually, stage_can_run_via_api, COALESCE(connected_task_key, '') AS connected_task_key, connected_task_trigger_point, stage_sort_order
            FROM project_task_stage
            WHERE task_key = ?
            ORDER BY stage_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskKey]
        ) ?: [];
        if ($readBack === [] || strtoupper(trim((string) ($readBack[0]['stage_label'] ?? ''))) !== 'NEW' || (int) ($readBack[0]['stage_sort_order'] ?? 0) !== 1) {
            throw new RuntimeException('Project task stage sort read-back did not keep NEW first.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage sort transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_update_project_task_stage_response_sort_order(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $taskStageKey = trim((string) ($input['task_stage_key'] ?? ''));
    $orderedResponseKeys = bx_project_task_stage_response_order_input($input);

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskStageKey)) {
        throw new RuntimeException('Invalid task stage key.');
    }
    if ($orderedResponseKeys === []) {
        throw new RuntimeException('Response order is required.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task stage response sort transaction could not start.');
        }
        $transactionStarted = true;

        $stage = $db->GetRow(
            'SELECT task_stage_key, task_key FROM project_task_stage WHERE task_stage_key = ? AND task_key = ? LIMIT 1',
            [$taskStageKey, $taskKey]
        );
        if (!is_array($stage) || (string) ($stage['task_stage_key'] ?? '') !== $taskStageKey) {
            throw new RuntimeException('Task stage was not found for this task.');
        }

        $responses = $db->GetAll(
            "SELECT task_stage_response_key, task_key, task_stage_key, response_label, COALESCE(response_description, '') AS response_description, response_color_hex, response_status, response_sort_order
            FROM project_task_stage_response
            WHERE task_key = ? AND task_stage_key = ?
            ORDER BY response_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskKey, $taskStageKey]
        ) ?: [];
        if ($responses === []) {
            throw new RuntimeException('No stage responses were found.');
        }

        $responsesByKey = [];
        foreach ($responses as $response) {
            $responsesByKey[(string) ($response['task_stage_response_key'] ?? '')] = $response;
        }

        $nextResponses = [];
        foreach ($orderedResponseKeys as $responseKey) {
            if (isset($responsesByKey[$responseKey])) {
                $nextResponses[] = $responsesByKey[$responseKey];
                unset($responsesByKey[$responseKey]);
            }
        }
        foreach ($responses as $response) {
            $responseKey = (string) ($response['task_stage_response_key'] ?? '');
            if (isset($responsesByKey[$responseKey])) {
                $nextResponses[] = $response;
            }
        }

        foreach ($nextResponses as $index => $response) {
            $responseKey = (string) ($response['task_stage_response_key'] ?? '');
            $sortOrder = $index + 1;
            $saved = $db->Execute(
                'UPDATE project_task_stage_response SET response_sort_order = ?, updated_by_user_key = ? WHERE task_stage_response_key = ? AND task_stage_key = ? AND task_key = ?',
                [$sortOrder, $userKey ?: null, $responseKey, $taskStageKey, $taskKey]
            );
            if ($saved === false) {
                throw new RuntimeException('Project task stage response sort update failed: ' . trim((string) $db->ErrorMsg()));
            }
        }

        bx_audit('SORT', 'project_task_stage_response', $taskStageKey, [
            'task_key' => $taskKey,
            'task_stage_key' => $taskStageKey,
            'response_order_keys' => array_map(static fn (array $response): string => (string) ($response['task_stage_response_key'] ?? ''), $nextResponses),
        ], 'Administrator sorted project task stage responses.');

        $readBack = $db->GetAll(
            "SELECT task_stage_response_key, task_key, task_stage_key, response_label, COALESCE(response_description, '') AS response_description, response_color_hex, response_status, response_sort_order
            FROM project_task_stage_response
            WHERE task_key = ? AND task_stage_key = ?
            ORDER BY response_sort_order ASC, updated_at DESC, x_id DESC",
            [$taskKey, $taskStageKey]
        ) ?: [];
        if (count($readBack) !== count($responses)) {
            throw new RuntimeException('Project task stage response sort read-back count did not match.');
        }
        foreach ($readBack as $index => $response) {
            if ((int) ($response['response_sort_order'] ?? 0) !== $index + 1) {
                throw new RuntimeException('Project task stage response sort read-back sequence did not match.');
            }
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task stage response sort transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_create_project_task(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_schema();

    $db = bx_db();
    $taskCode = strtoupper(trim((string) ($input['task_code'] ?? '')));
    $taskTitle = trim((string) ($input['task_title'] ?? ''));
    $taskDescription = trim((string) ($input['task_description'] ?? ''));
    $taskType = strtoupper(trim((string) ($input['task_type'] ?? 'PRIMARY')));
    $taskStatus = strtoupper(trim((string) ($input['task_status'] ?? 'INACTIVE')));
    $taskPriority = strtoupper(trim((string) ($input['task_priority'] ?? 'NORMAL')));
    $taskColorHex = bx_project_task_color_hex($input);
    $taskCanRunManually = bx_project_task_checkbox_value($input, 'task_can_run_manually');
    $taskCanRunViaApi = bx_project_task_checkbox_value($input, 'task_can_run_via_api');
    $taskCanRunIfBedVacant = array_key_exists('task_can_run_if_bed_vacant', $input) ? bx_project_task_checkbox_value($input, 'task_can_run_if_bed_vacant') : 1;
    $taskCanRunIfBedOccupied = array_key_exists('task_can_run_if_bed_occupied', $input) ? bx_project_task_checkbox_value($input, 'task_can_run_if_bed_occupied') : 1;
    $taskRequiresBedTreatment = array_key_exists('task_requires_bed_treatment', $input) ? bx_project_task_checkbox_value($input, 'task_requires_bed_treatment') : 1;
    $taskRequiresAdmissionSource = array_key_exists('task_requires_admission_source', $input) ? bx_project_task_checkbox_value($input, 'task_requires_admission_source') : 1;
    $taskGroupKeys = bx_project_task_group_keys($input);
    $taskBypassGroupKeys = bx_project_task_bypass_group_keys($input, $taskGroupKeys);
    $taskGroupKeysJson = json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES);
    $taskBypassGroupKeysJson = json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES);
    $sortOrder = max(0, (int) ($input['task_sort_order'] ?? 0));
    $allowedTypes = ['PRIMARY', 'SECONDARY'];
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];
    $allowedPriorities = ['LOW', 'NORMAL', 'HIGH', 'URGENT'];

    if ($taskTitle === '') {
        throw new RuntimeException('Task title is required.');
    }
    if (strlen($taskTitle) > 255) {
        throw new RuntimeException('Task title must be 255 characters or fewer.');
    }
    if ($taskCode !== '' && !preg_match('/^[A-Z0-9_-]{2,80}$/', $taskCode)) {
        throw new RuntimeException('Task code must use 2-80 uppercase letters, numbers, underscores, or hyphens.');
    }
    if (!in_array($taskType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid task type.');
    }
    if (!in_array($taskStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid task status.');
    }
    if (!in_array($taskPriority, $allowedPriorities, true)) {
        throw new RuntimeException('Invalid task priority.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task transaction could not start.');
        }
        $transactionStarted = true;

        $taskKey = '';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = bx_firebase_document_id();
            $exists = (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$candidate]);
            if ($exists === 0) {
                $taskKey = $candidate;
                break;
            }
        }
        if ($taskKey === '' || !preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
            throw new RuntimeException('Project task key generation failed.');
        }

        if ($sortOrder === 0) {
            $sortOrder = (int) $db->GetOne(
                'SELECT COALESCE(MAX(task_sort_order), 0) + 1 FROM project_task WHERE task_type = ?',
                [$taskType]
            );
        }

        $saved = $db->Execute(
            'INSERT INTO project_task (task_key, task_code, task_title, task_description, task_group_keys, task_bypass_group_keys, task_type, task_status, task_priority, task_color_hex, task_can_run_manually, task_can_run_via_api, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_requires_bed_treatment, task_requires_admission_source, task_sort_order, created_by_user_key, updated_by_user_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $taskKey,
                $taskCode === '' ? null : $taskCode,
                $taskTitle,
                $taskDescription === '' ? null : $taskDescription,
                $taskGroupKeysJson,
                $taskBypassGroupKeysJson,
                $taskType,
                $taskStatus,
                $taskPriority,
                $taskColorHex,
                $taskCanRunManually,
                $taskCanRunViaApi,
                $taskCanRunIfBedVacant,
                $taskCanRunIfBedOccupied,
                $taskRequiresBedTreatment,
                $taskRequiresAdmissionSource,
                $sortOrder,
                $userKey ?: null,
                $userKey ?: null,
            ]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task insert failed: ' . trim((string) $db->ErrorMsg()));
        }

        $defaultStage = bx_project_task_default_stage_input($taskKey);
        $defaultStageReadBack = bx_insert_project_task_stage_row(
            $db,
            $taskKey,
            (string) $defaultStage['stage_label'],
            (string) $defaultStage['stage_description'],
            (string) $defaultStage['stage_color_hex'],
            (string) $defaultStage['stage_status'],
            (int) $defaultStage['stage_ends_task'],
            (int) $defaultStage['stage_can_run_manually'],
            (int) $defaultStage['stage_can_run_via_api'],
            (int) $defaultStage['stage_sort_order'],
            $userKey,
            'AUTO_CREATE',
            'BuilderX auto-created default NEW task stage.'
        );

        bx_audit('CREATE', 'project_task', $taskKey, [
            'task_key' => $taskKey,
            'task_code' => $taskCode,
            'task_title' => $taskTitle,
            'task_type' => $taskType,
            'task_status' => $taskStatus,
            'task_priority' => $taskPriority,
            'task_color_hex' => $taskColorHex,
            'task_can_run_manually' => $taskCanRunManually,
            'task_can_run_via_api' => $taskCanRunViaApi,
            'task_can_run_if_bed_vacant' => $taskCanRunIfBedVacant,
            'task_can_run_if_bed_occupied' => $taskCanRunIfBedOccupied,
            'task_requires_bed_treatment' => $taskRequiresBedTreatment,
            'task_requires_admission_source' => $taskRequiresAdmissionSource,
            'task_group_keys' => $taskGroupKeys,
            'task_bypass_group_keys' => $taskBypassGroupKeys,
            'default_stage_key' => (string) ($defaultStageReadBack['task_stage_key'] ?? ''),
        ], 'Administrator created project task.');

        $readBack = $db->GetRow("SELECT task_key, task_title, COALESCE(task_group_keys, '[]') AS task_group_keys, COALESCE(task_bypass_group_keys, '[]') AS task_bypass_group_keys, task_type, task_status, task_priority, task_color_hex, task_can_run_manually, task_can_run_via_api, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_requires_bed_treatment, task_requires_admission_source FROM project_task WHERE task_key = ? LIMIT 1", [$taskKey]);
        if (
            !is_array($readBack)
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['task_title'] ?? '') !== $taskTitle
            || (string) ($readBack['task_type'] ?? '') !== $taskType
            || (string) ($readBack['task_status'] ?? '') !== $taskStatus
            || (string) ($readBack['task_priority'] ?? '') !== $taskPriority
            || (string) ($readBack['task_group_keys'] ?? '[]') !== $taskGroupKeysJson
            || (string) ($readBack['task_bypass_group_keys'] ?? '[]') !== $taskBypassGroupKeysJson
            || (string) ($readBack['task_color_hex'] ?? '') !== $taskColorHex
            || (int) ($readBack['task_can_run_manually'] ?? 0) !== $taskCanRunManually
            || (int) ($readBack['task_can_run_via_api'] ?? 0) !== $taskCanRunViaApi
            || (int) ($readBack['task_can_run_if_bed_vacant'] ?? 0) !== $taskCanRunIfBedVacant
            || (int) ($readBack['task_can_run_if_bed_occupied'] ?? 0) !== $taskCanRunIfBedOccupied
            || (int) ($readBack['task_requires_bed_treatment'] ?? 0) !== $taskRequiresBedTreatment
            || (int) ($readBack['task_requires_admission_source'] ?? 0) !== $taskRequiresAdmissionSource
        ) {
            throw new RuntimeException('Project task read-back did not match the written values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_update_project_task_canvas_position(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $canvasX = bx_project_task_canvas_coordinate($input, 'task_canvas_x');
    $canvasY = bx_project_task_canvas_coordinate($input, 'task_canvas_y');

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task canvas transaction could not start.');
        }
        $transactionStarted = true;

        $saved = $db->Execute(
            'UPDATE project_task SET task_canvas_x = ?, task_canvas_y = ?, updated_by_user_key = ? WHERE task_key = ?',
            [$canvasX, $canvasY, $userKey ?: null, $taskKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task canvas position update failed: ' . trim((string) $db->ErrorMsg()));
        }
        if ((int) $db->Affected_Rows() < 1) {
            $exists = (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$taskKey]);
            if ($exists !== 1) {
                throw new RuntimeException('Task was not found.');
            }
        }

        bx_audit('MOVE', 'project_task', $taskKey, [
            'task_key' => $taskKey,
            'task_canvas_x' => $canvasX,
            'task_canvas_y' => $canvasY,
        ], 'Administrator moved project task on canvas.');

        $readBack = $db->GetRow('SELECT task_key, task_canvas_x, task_canvas_y FROM project_task WHERE task_key = ? LIMIT 1', [$taskKey]);
        if (
            !is_array($readBack)
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (int) ($readBack['task_canvas_x'] ?? -1) !== $canvasX
            || (int) ($readBack['task_canvas_y'] ?? -1) !== $canvasY
        ) {
            throw new RuntimeException('Project task canvas position read-back did not match the saved values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task canvas transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_delete_project_task(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_stage_response_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task delete transaction could not start.');
        }
        $transactionStarted = true;

        $task = $db->GetRow(
            'SELECT task_key, task_code, task_title, task_type, task_status FROM project_task WHERE task_key = ? LIMIT 1',
            [$taskKey]
        );
        if (!is_array($task)) {
            throw new RuntimeException('Task was not found.');
        }

	        $stageCount = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_key = ?', [$taskKey]);
	        $responseCount = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_key = ?', [$taskKey]);
		        $connectionsCleared = $db->Execute("UPDATE project_task_stage SET connected_task_key = NULL, connected_task_trigger_point = 'CURRENT_STAGE_FINISHED', updated_by_user_key = ? WHERE connected_task_key = ?", [$userKey ?: null, $taskKey]);
	        if ($connectionsCleared === false) {
	            throw new RuntimeException('Project task stage connection cleanup failed: ' . trim((string) $db->ErrorMsg()));
	        }
	        $responsesDeleted = $db->Execute('DELETE FROM project_task_stage_response WHERE task_key = ?', [$taskKey]);
	        if ($responsesDeleted === false) {
	            throw new RuntimeException('Project task stage response delete failed: ' . trim((string) $db->ErrorMsg()));
	        }
	        $stagesDeleted = $db->Execute('DELETE FROM project_task_stage WHERE task_key = ?', [$taskKey]);
        if ($stagesDeleted === false) {
            throw new RuntimeException('Project task stage delete failed: ' . trim((string) $db->ErrorMsg()));
        }

        $taskDeleted = $db->Execute('DELETE FROM project_task WHERE task_key = ?', [$taskKey]);
        if ($taskDeleted === false) {
            throw new RuntimeException('Project task delete failed: ' . trim((string) $db->ErrorMsg()));
        }

		        $taskRemaining = (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$taskKey]);
		        $stageRemaining = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE task_key = ?', [$taskKey]);
		        $responseRemaining = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage_response WHERE task_key = ?', [$taskKey]);
		        $connectionRemaining = (int) $db->GetOne('SELECT COUNT(*) FROM project_task_stage WHERE connected_task_key = ?', [$taskKey]);
		        if ($taskRemaining !== 0 || $stageRemaining !== 0 || $responseRemaining !== 0 || $connectionRemaining !== 0) {
		            throw new RuntimeException('Project task delete read-back still found task data.');
		        }

        bx_audit('DELETE', 'project_task', $taskKey, [
            'task_key' => $taskKey,
            'task_code' => (string) ($task['task_code'] ?? ''),
            'task_title' => (string) ($task['task_title'] ?? ''),
	            'task_type' => (string) ($task['task_type'] ?? ''),
	            'task_status' => (string) ($task['task_status'] ?? ''),
	            'deleted_stage_count' => $stageCount,
	            'deleted_response_count' => $responseCount,
	        ], 'Administrator deleted project task and related stages.');

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task delete transaction could not commit.');
        }
        $transactionStarted = false;

        return [
	            'task_key' => $taskKey,
	            'task_title' => (string) ($task['task_title'] ?? ''),
	            'deleted_stage_count' => $stageCount,
	            'deleted_response_count' => $responseCount,
	        ];
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_update_project_task(array $input, ?string $userKey = null): array
{
    bx_ensure_project_task_schema();

    $db = bx_db();
    $taskKey = trim((string) ($input['task_key'] ?? ''));
    $taskCode = strtoupper(trim((string) ($input['task_code'] ?? '')));
    $taskTitle = trim((string) ($input['task_title'] ?? ''));
    $taskDescription = trim((string) ($input['task_description'] ?? ''));
    $taskType = strtoupper(trim((string) ($input['task_type'] ?? 'PRIMARY')));
    $taskStatus = strtoupper(trim((string) ($input['task_status'] ?? 'INACTIVE')));
    $taskPriority = strtoupper(trim((string) ($input['task_priority'] ?? 'NORMAL')));
    $taskColorHex = bx_project_task_color_hex($input);
    $taskCanRunManually = bx_project_task_checkbox_value($input, 'task_can_run_manually');
    $taskCanRunViaApi = bx_project_task_checkbox_value($input, 'task_can_run_via_api');
    $taskCanRunIfBedVacant = bx_project_task_checkbox_value($input, 'task_can_run_if_bed_vacant');
    $taskCanRunIfBedOccupied = bx_project_task_checkbox_value($input, 'task_can_run_if_bed_occupied');
    $taskRequiresBedTreatment = bx_project_task_checkbox_value($input, 'task_requires_bed_treatment');
    $taskRequiresAdmissionSource = bx_project_task_checkbox_value($input, 'task_requires_admission_source');
    $taskGroupKeys = bx_project_task_group_keys($input);
    $taskBypassGroupKeys = bx_project_task_bypass_group_keys($input, $taskGroupKeys);
    $taskGroupKeysJson = json_encode($taskGroupKeys, JSON_UNESCAPED_SLASHES);
    $taskBypassGroupKeysJson = json_encode($taskBypassGroupKeys, JSON_UNESCAPED_SLASHES);
    $allowedTypes = ['PRIMARY', 'SECONDARY'];
    $allowedStatuses = ['ACTIVE', 'INACTIVE'];
    $allowedPriorities = ['LOW', 'NORMAL', 'HIGH', 'URGENT'];

    if (!preg_match('/^[A-Za-z0-9]{20}$/', $taskKey)) {
        throw new RuntimeException('Invalid task key.');
    }
    if ($taskTitle === '') {
        throw new RuntimeException('Task title is required.');
    }
    if (strlen($taskTitle) > 255) {
        throw new RuntimeException('Task title must be 255 characters or fewer.');
    }
    if ($taskCode !== '' && !preg_match('/^[A-Z0-9_-]{2,80}$/', $taskCode)) {
        throw new RuntimeException('Task code must use 2-80 uppercase letters, numbers, underscores, or hyphens.');
    }
    if (!in_array($taskType, $allowedTypes, true)) {
        throw new RuntimeException('Invalid task type.');
    }
    if (!in_array($taskStatus, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid task status.');
    }
    if (!in_array($taskPriority, $allowedPriorities, true)) {
        throw new RuntimeException('Invalid task priority.');
    }

    $transactionStarted = false;
    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project task transaction could not start.');
        }
        $transactionStarted = true;

        $existing = (int) $db->GetOne('SELECT COUNT(*) FROM project_task WHERE task_key = ?', [$taskKey]);
        if ($existing !== 1) {
            throw new RuntimeException('Task was not found.');
        }

        $saved = $db->Execute(
            'UPDATE project_task SET task_code = ?, task_title = ?, task_description = ?, task_group_keys = ?, task_bypass_group_keys = ?, task_type = ?, task_status = ?, task_priority = ?, task_color_hex = ?, task_can_run_manually = ?, task_can_run_via_api = ?, task_can_run_if_bed_vacant = ?, task_can_run_if_bed_occupied = ?, task_requires_bed_treatment = ?, task_requires_admission_source = ?, updated_by_user_key = ? WHERE task_key = ?',
            [
                $taskCode === '' ? null : $taskCode,
                $taskTitle,
                $taskDescription === '' ? null : $taskDescription,
                $taskGroupKeysJson,
                $taskBypassGroupKeysJson,
                $taskType,
                $taskStatus,
                $taskPriority,
                $taskColorHex,
                $taskCanRunManually,
                $taskCanRunViaApi,
                $taskCanRunIfBedVacant,
                $taskCanRunIfBedOccupied,
                $taskRequiresBedTreatment,
                $taskRequiresAdmissionSource,
                $userKey ?: null,
                $taskKey,
            ]
        );
        if ($saved === false) {
            throw new RuntimeException('Project task update failed: ' . trim((string) $db->ErrorMsg()));
        }

        bx_audit('UPDATE', 'project_task', $taskKey, [
            'task_key' => $taskKey,
            'task_code' => $taskCode,
            'task_title' => $taskTitle,
            'task_type' => $taskType,
            'task_status' => $taskStatus,
            'task_priority' => $taskPriority,
            'task_color_hex' => $taskColorHex,
            'task_can_run_manually' => $taskCanRunManually,
            'task_can_run_via_api' => $taskCanRunViaApi,
            'task_can_run_if_bed_vacant' => $taskCanRunIfBedVacant,
            'task_can_run_if_bed_occupied' => $taskCanRunIfBedOccupied,
            'task_requires_bed_treatment' => $taskRequiresBedTreatment,
            'task_requires_admission_source' => $taskRequiresAdmissionSource,
            'task_group_keys' => $taskGroupKeys,
            'task_bypass_group_keys' => $taskBypassGroupKeys,
        ], 'Administrator updated project task.');

        $readBack = $db->GetRow("SELECT task_key, task_title, COALESCE(task_group_keys, '[]') AS task_group_keys, COALESCE(task_bypass_group_keys, '[]') AS task_bypass_group_keys, task_type, task_status, task_priority, task_color_hex, task_can_run_manually, task_can_run_via_api, task_can_run_if_bed_vacant, task_can_run_if_bed_occupied, task_requires_bed_treatment, task_requires_admission_source FROM project_task WHERE task_key = ? LIMIT 1", [$taskKey]);
        if (
            !is_array($readBack)
            || (string) ($readBack['task_key'] ?? '') !== $taskKey
            || (string) ($readBack['task_title'] ?? '') !== $taskTitle
            || (string) ($readBack['task_type'] ?? '') !== $taskType
            || (string) ($readBack['task_status'] ?? '') !== $taskStatus
            || (string) ($readBack['task_priority'] ?? '') !== $taskPriority
            || (string) ($readBack['task_group_keys'] ?? '[]') !== $taskGroupKeysJson
            || (string) ($readBack['task_bypass_group_keys'] ?? '[]') !== $taskBypassGroupKeysJson
            || (string) ($readBack['task_color_hex'] ?? '') !== $taskColorHex
            || (int) ($readBack['task_can_run_manually'] ?? 0) !== $taskCanRunManually
            || (int) ($readBack['task_can_run_via_api'] ?? 0) !== $taskCanRunViaApi
            || (int) ($readBack['task_can_run_if_bed_vacant'] ?? 0) !== $taskCanRunIfBedVacant
            || (int) ($readBack['task_can_run_if_bed_occupied'] ?? 0) !== $taskCanRunIfBedOccupied
            || (int) ($readBack['task_requires_bed_treatment'] ?? 0) !== $taskRequiresBedTreatment
            || (int) ($readBack['task_requires_admission_source'] ?? 0) !== $taskRequiresAdmissionSource
        ) {
            throw new RuntimeException('Project task read-back did not match the updated values.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project task transaction could not commit.');
        }
        $transactionStarted = false;

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_bed_master_list_source_exists(): bool
{
    return (int) bx_db()->GetOne(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [BUILDERX_DB_NAME, 'RBMS_BedMasterlist']
    ) === 1;
}

function bx_bed_master_list_summary(): array
{
    bx_ensure_bed_master_list_schema();
    $sourceExists = bx_bed_master_list_source_exists();

    return [
        'managedTable' => 'project_bed',
        'sourceTable' => 'RBMS_BedMasterlist',
        'firebaseDocumentIdFormat' => 'bed_key',
        'sourceExists' => $sourceExists,
        'sourceRows' => $sourceExists ? (int) bx_db()->GetOne('SELECT COUNT(*) FROM `RBMS_BedMasterlist`') : 0,
        'managedRows' => (int) bx_db()->GetOne('SELECT COUNT(*) FROM project_bed'),
        'activeRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE managed_status = 'ACTIVE'"),
        'inactiveRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE managed_status = 'INACTIVE'"),
        'availableRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE source_bed_status = 'Available'"),
        'vacantRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE source_bed_status IN ('Available', 'Vacant')"),
        'occupiedRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE source_bed_status = 'Occupied'"),
        'firebaseDocumentRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed WHERE TRIM(bed_key) REGEXP '^[A-Za-z0-9]{20}$'"),
        'analyticsRows' => (int) bx_db()->GetOne("SELECT COUNT(*) FROM project_bed_analytics WHERE analytics_status = 'ACTIVE'"),
        'analyticsLastComputedAt' => (string) (bx_db()->GetOne('SELECT MAX(last_computed_at) FROM project_bed_analytics') ?: ''),
        'lastSyncedAt' => (string) (bx_db()->GetOne('SELECT MAX(last_synced_at) FROM project_bed') ?: ''),
        'groupCounts' => bx_bed_master_list_group_counts(),
    ];
}

function bx_bed_lookup_filters_from_array(array $input): array
{
    $filters = [];
    foreach ([
        'bed_lookup_search',
        'bed_lookup_status',
        'bed_lookup_branch',
        'bed_lookup_building',
        'bed_lookup_floor',
        'bed_lookup_nurse_station',
        'bed_lookup_room',
        'bed_lookup_room_class',
        'bed_lookup_bed_status',
    ] as $key) {
        $filters[$key] = substr(trim((string) ($input[$key] ?? '')), 0, 120);
    }

    if (!in_array($filters['bed_lookup_status'], ['', 'ACTIVE', 'INACTIVE'], true)) {
        $filters['bed_lookup_status'] = '';
    }

    return $filters;
}

function bx_bed_lookup_filters_from_request(): array
{
    return bx_bed_lookup_filters_from_array($_GET);
}

function bx_bed_lookup_unspecified_value(): string
{
    return '__UNSPECIFIED__';
}

function bx_bed_lookup_filter_columns(): array
{
    return [
        'bed_lookup_status' => 'managed_status',
        'bed_lookup_branch' => 'branch_name',
        'bed_lookup_building' => 'building_name',
        'bed_lookup_floor' => 'floor_name',
        'bed_lookup_nurse_station' => 'nurse_station_name',
        'bed_lookup_room' => 'room_key',
        'bed_lookup_room_class' => 'room_class',
        'bed_lookup_bed_status' => 'source_bed_status',
    ];
}

function bx_project_bed_lookup_where(array $filters, ?string $skipFilter = null): array
{
    $where = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['bed_lookup_search'] ?? ''));
    if ($search !== '') {
        $where[] = "CONCAT_WS(' ', bed_key, bed_source_key, source_pk_psbeds, bed_no, branch_name, building_name, floor_name, nurse_station_name, room_key, room_class, source_bed_status) LIKE ?";
        $params[] = '%' . $search . '%';
    }

    foreach (bx_bed_lookup_filter_columns() as $filterKey => $column) {
        if ($skipFilter === $filterKey) {
            continue;
        }

        $value = trim((string) ($filters[$filterKey] ?? ''));
        if ($value === '') {
            continue;
        }

        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        if ($value === bx_bed_lookup_unspecified_value()) {
            $where[] = "NULLIF(TRIM(CAST({$columnSql} AS CHAR)), '') IS NULL";
            continue;
        }

        $where[] = "NULLIF(TRIM(CAST({$columnSql} AS CHAR)), '') = ?";
        $params[] = $value;
    }

    return [$where, $params];
}

function bx_project_bed_lookup_options(array $filters): array
{
    bx_ensure_bed_master_list_schema();

    $definitions = [
        'bed_lookup_status' => ['label' => 'Managed status', 'column' => 'managed_status'],
        'bed_lookup_branch' => ['label' => 'Branch', 'column' => 'branch_name'],
        'bed_lookup_building' => ['label' => 'Building', 'column' => 'building_name'],
        'bed_lookup_floor' => ['label' => 'Floor', 'column' => 'floor_name'],
        'bed_lookup_nurse_station' => ['label' => 'Nurse station', 'column' => 'nurse_station_name'],
        'bed_lookup_room' => ['label' => 'Room', 'column' => 'room_key'],
        'bed_lookup_room_class' => ['label' => 'Room class', 'column' => 'room_class'],
        'bed_lookup_bed_status' => ['label' => 'Bed status', 'column' => 'source_bed_status'],
    ];

    $options = [];
    foreach ($definitions as $filterKey => $definition) {
        [$where, $params] = bx_project_bed_lookup_where($filters, $filterKey);
        $column = (string) $definition['column'];
        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        $valueSql = "COALESCE(NULLIF(TRIM(CAST({$columnSql} AS CHAR)), ''), '" . bx_bed_lookup_unspecified_value() . "')";
        $labelSql = "COALESCE(NULLIF(TRIM(CAST({$columnSql} AS CHAR)), ''), 'Unspecified')";
        $rows = bx_db()->GetAll(
            "SELECT
                {$valueSql} AS value,
                {$labelSql} AS label,
                COUNT(*) AS total
            FROM project_bed
            WHERE " . implode(' AND ', $where) . "
            GROUP BY {$valueSql}, {$labelSql}
            ORDER BY CASE WHEN {$valueSql} = '" . bx_bed_lookup_unspecified_value() . "' THEN 1 ELSE 0 END, {$labelSql} ASC
            LIMIT 200",
            $params
        ) ?: [];

        $options[$filterKey] = [
            'label' => (string) $definition['label'],
            'rows' => array_map(static fn (array $row): array => [
                'value' => (string) ($row['value'] ?? ''),
                'label' => (string) ($row['label'] ?? ''),
                'total' => (int) ($row['total'] ?? 0),
            ], $rows),
        ];
    }

    return $options;
}

function bx_project_bed_lookup_rows(array $filters, int $limit = 24): array
{
    bx_ensure_bed_master_list_schema();
    bx_ensure_project_bed_task_schema();
    $limit = max(1, min(60, $limit));
    [$where, $params] = bx_project_bed_lookup_where($filters);

    $whereSql = implode(' AND ', $where);
    $rows = bx_db()->GetAll(
        "SELECT
            bed_key,
            bed_source_key,
            COALESCE(source_pk_psbeds, '') AS source_pk_psbeds,
            COALESCE(bed_no, '') AS bed_no,
            COALESCE(branch_name, '') AS branch_name,
            COALESCE(building_name, '') AS building_name,
            COALESCE(floor_name, '') AS floor_name,
            COALESCE(nurse_station_name, '') AS nurse_station_name,
            COALESCE(room_key, '') AS room_key,
            COALESCE(room_class, '') AS room_class,
            COALESCE(source_bed_status, '') AS source_bed_status,
            COALESCE(NULLIF(TRIM(CAST(check_status.`BedStatus` AS CHAR)), ''), '') AS rbms_check_bed_status,
            COALESCE(existing_task_count.existing_task_count, 0) AS existing_task_count,
            COALESCE(existing_task_count.existing_primary_task_count, 0) AS existing_primary_task_count,
            COALESCE(existing_task_count.existing_secondary_task_count, 0) AS existing_secondary_task_count,
            COALESCE(existing_task_count.existing_task_keys, '') AS existing_task_keys,
            COALESCE(existing_task_count.existing_task_types, '') AS existing_task_types,
            COALESCE(existing_task.bed_task_key, '') AS existing_task_key,
            COALESCE(existing_task.task_key, '') AS existing_task_task_key,
            COALESCE(existing_task.task_title, '') AS existing_task_title,
            COALESCE(existing_task.task_status, '') AS existing_task_status,
            COALESCE(existing_task.task_color_hex, '#00000000') AS existing_task_color_hex,
            CASE
                WHEN NULLIF(TRIM(CAST(project_bed.`source_pk_psbeds` AS CHAR)), '') IS NULL THEN 0
                WHEN check_status.`PK_psBeds` IS NULL THEN 1
                WHEN LOWER(TRIM(COALESCE(project_bed.`source_bed_status`, ''))) COLLATE utf8mb4_unicode_ci <> LOWER(TRIM(COALESCE(check_status.`BedStatus`, ''))) COLLATE utf8mb4_unicode_ci THEN 1
                ELSE 0
            END AS bed_status_mismatch,
            managed_status,
            COALESCE(DATE_FORMAT(last_synced_at, '%Y-%m-%d %H:%i'), '') AS last_synced_at
        FROM project_bed
        LEFT JOIN `RBMS_CheckBedStatus` check_status
            ON NULLIF(TRIM(CAST(project_bed.`source_pk_psbeds` AS CHAR)), '') COLLATE utf8mb4_unicode_ci = NULLIF(TRIM(CAST(check_status.`PK_psBeds` AS CHAR)), '') COLLATE utf8mb4_unicode_ci
        LEFT JOIN (
            SELECT
                pbt.bed_key AS task_bed_key,
                pbt.bed_task_key,
                pbt.task_key,
                pbt.task_title,
                pbt.task_status,
                CASE
                    WHEN COALESCE(pt.task_color_hex, '#00000000') REGEXP '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$' THEN UPPER(COALESCE(pt.task_color_hex, '#00000000'))
                    ELSE '#00000000'
                END AS task_color_hex
            FROM project_bed_task pbt
            INNER JOIN (
                SELECT bed_key, MAX(x_id) AS latest_task_x_id
                FROM project_bed_task
                GROUP BY bed_key
            ) latest_task ON latest_task.latest_task_x_id = pbt.x_id
            LEFT JOIN project_task pt ON pt.task_key = pbt.task_key
        ) existing_task
            ON NULLIF(TRIM(CAST(existing_task.task_bed_key AS CHAR)), '') COLLATE utf8mb4_unicode_ci = NULLIF(TRIM(CAST(project_bed.`bed_key` AS CHAR)), '') COLLATE utf8mb4_unicode_ci
        LEFT JOIN (
            SELECT
                bed_key AS task_count_bed_key,
                COUNT(*) AS existing_task_count,
                SUM(CASE WHEN task_type = 'PRIMARY' THEN 1 ELSE 0 END) AS existing_primary_task_count,
                SUM(CASE WHEN task_type = 'SECONDARY' THEN 1 ELSE 0 END) AS existing_secondary_task_count,
                GROUP_CONCAT(DISTINCT task_key ORDER BY x_id SEPARATOR ',') AS existing_task_keys,
                GROUP_CONCAT(DISTINCT task_type ORDER BY task_type SEPARATOR ',') AS existing_task_types
            FROM project_bed_task
            GROUP BY bed_key
        ) existing_task_count
            ON NULLIF(TRIM(CAST(existing_task_count.task_count_bed_key AS CHAR)), '') COLLATE utf8mb4_unicode_ci = NULLIF(TRIM(CAST(project_bed.`bed_key` AS CHAR)), '') COLLATE utf8mb4_unicode_ci
        WHERE {$whereSql}
        ORDER BY
            CASE managed_status WHEN 'ACTIVE' THEN 1 ELSE 2 END,
            COALESCE(branch_name, '') ASC,
            COALESCE(building_name, '') ASC,
            COALESCE(floor_name, '') ASC,
            COALESCE(bed_no, '') ASC,
            x_id ASC
        LIMIT {$limit}",
        $params
    );

    return is_array($rows) ? $rows : [];
}

function bx_bed_master_list_group_counts(): array
{
    bx_ensure_bed_master_list_schema();

    $groups = [
        ['key' => 'managed_status', 'label' => 'Managed status', 'column' => 'managed_status'],
        ['key' => 'branch_name', 'label' => 'Branch', 'column' => 'branch_name'],
        ['key' => 'building_name', 'label' => 'Building', 'column' => 'building_name'],
        ['key' => 'floor_name', 'label' => 'Floor', 'column' => 'floor_name'],
        ['key' => 'nurse_station_name', 'label' => 'Nurse station', 'column' => 'nurse_station_name'],
        ['key' => 'room_key', 'label' => 'Room', 'column' => 'room_key'],
        ['key' => 'room_class', 'label' => 'Room class', 'column' => 'room_class'],
        ['key' => 'source_bed_status', 'label' => 'Bed status', 'column' => 'source_bed_status'],
    ];

    $result = [];
    foreach ($groups as $group) {
        $column = $group['column'];
        $columnSql = '`' . str_replace('`', '``', $column) . '`';
        $rows = bx_db()->GetAll(
            "SELECT
                COALESCE(NULLIF({$columnSql}, ''), 'Unspecified') AS label,
                COUNT(*) AS total,
                SUM(CASE WHEN source_bed_status = 'Available' THEN 1 ELSE 0 END) AS available,
                SUM(CASE WHEN source_bed_status IN ('Available', 'Vacant') THEN 1 ELSE 0 END) AS vacant
            FROM project_bed
            GROUP BY COALESCE(NULLIF({$columnSql}, ''), 'Unspecified')
            ORDER BY total DESC, label ASC"
        ) ?: [];
        $result[] = [
            'key' => $group['key'],
            'label' => $group['label'],
            'totalGroups' => count($rows),
            'rows' => array_map(static fn (array $row): array => [
                'label' => (string) ($row['label'] ?? 'Unspecified'),
                'total' => (int) ($row['total'] ?? 0),
                'available' => (int) ($row['available'] ?? 0),
                'vacant' => (int) ($row['vacant'] ?? 0),
            ], $rows),
        ];
    }

    return $result;
}

function bx_project_bed_source_row(string $bedSourceKey): ?array
{
    $db = bx_db();
    $cleanSource = static function (string $column): string {
        return "NULLIF(TRIM(CAST(source.`{$column}` AS CHAR)), '')";
    };
    $sourceKeySql = "CONCAT('RBMS_BedMasterlist:', COALESCE({$cleanSource('PK_psBeds')}, CONCAT('id:', source.`id`)))";
    $row = $db->GetRow(
        "SELECT
            source.`id` AS source_id,
            {$cleanSource('PK_psBeds')} AS source_pk_psbeds,
            {$cleanSource('bedno')} AS bed_no,
            {$cleanSource('PK_mscBranches')} AS branch_key,
            {$cleanSource('branchname')} AS branch_name,
            {$cleanSource('PK_mscBldgs')} AS building_key,
            {$cleanSource('bldgname')} AS building_name,
            {$cleanSource('PK_mscBldgFloors')} AS floor_key,
            {$cleanSource('floorname')} AS floor_name,
            {$cleanSource('PK_mscNrstation')} AS nurse_station_key,
            {$cleanSource('Nrstation')} AS nurse_station_name,
            {$cleanSource('PK_psRooms')} AS room_key,
            {$cleanSource('PK_mscRoomClass')} AS room_class_key,
            {$cleanSource('RoomClass')} AS room_class,
            {$cleanSource('PK_mscBedStatus')} AS source_bed_status_key,
            {$cleanSource('BedStatus')} AS source_bed_status
        FROM `RBMS_BedMasterlist` source
        WHERE source.`id` IS NOT NULL
            AND {$sourceKeySql} = ?
        LIMIT 1",
        [$bedSourceKey]
    );

    return is_array($row) ? $row : null;
}

function bx_resync_project_bed(string $bedKey, ?string $userKey = null): array
{
    bx_ensure_bed_master_list_schema();
    if (!preg_match('/^[A-Za-z0-9]{20,40}$/', $bedKey)) {
        throw new RuntimeException('Selected bed key is invalid.');
    }
    if (!bx_bed_master_list_source_exists()) {
        throw new RuntimeException('Source table RBMS_BedMasterlist was not found.');
    }

    $db = bx_db();
    $batchKey = bx_uuid();
    $transactionStarted = false;

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Project bed sync transaction could not start.');
        }
        $transactionStarted = true;

        $managed = $db->GetRow(
            "SELECT
                bed_key,
                bed_source_key,
                managed_status,
                COALESCE(branch_key, '') AS branch_key,
                COALESCE(branch_name, '') AS branch_name,
                COALESCE(building_key, '') AS building_key,
                COALESCE(building_name, '') AS building_name,
                COALESCE(floor_key, '') AS floor_key,
                COALESCE(floor_name, '') AS floor_name
            FROM project_bed
            WHERE bed_key = ?
            FOR UPDATE",
            [$bedKey]
        );
        if (!is_array($managed)) {
            throw new RuntimeException('Selected project bed was not found.');
        }
        $oldFloorGroupKey = bx_project_bed_floor_group_key($managed);

        $source = bx_project_bed_source_row((string) ($managed['bed_source_key'] ?? ''));
        if ($source !== null) {
            $saved = $db->Execute(
                'UPDATE project_bed
                SET source_id = ?,
                    source_pk_psbeds = ?,
                    bed_no = ?,
                    branch_key = ?,
                    branch_name = ?,
                    building_key = ?,
                    building_name = ?,
                    floor_key = ?,
                    floor_name = ?,
                    nurse_station_key = ?,
                    nurse_station_name = ?,
                    room_key = ?,
                    room_class_key = ?,
                    room_class = ?,
                    source_bed_status_key = ?,
                    source_bed_status = ?,
                    managed_status = ?,
                    sync_batch_key = ?,
                    last_synced_at = CURRENT_TIMESTAMP,
                    last_seen_at = CURRENT_TIMESTAMP
                WHERE bed_key = ?',
                [
                    (int) ($source['source_id'] ?? 0),
                    $source['source_pk_psbeds'] ?? null,
                    $source['bed_no'] ?? null,
                    $source['branch_key'] ?? null,
                    $source['branch_name'] ?? null,
                    $source['building_key'] ?? null,
                    $source['building_name'] ?? null,
                    $source['floor_key'] ?? null,
                    $source['floor_name'] ?? null,
                    $source['nurse_station_key'] ?? null,
                    $source['nurse_station_name'] ?? null,
                    $source['room_key'] ?? null,
                    $source['room_class_key'] ?? null,
                    $source['room_class'] ?? null,
                    $source['source_bed_status_key'] ?? null,
                    $source['source_bed_status'] ?? null,
                    'ACTIVE',
                    $batchKey,
                    $bedKey,
                ]
            );
        } else {
            $saved = $db->Execute(
                "UPDATE project_bed
                SET managed_status = 'INACTIVE',
                    sync_batch_key = ?,
                    last_synced_at = CURRENT_TIMESTAMP
                WHERE bed_key = ?",
                [$batchKey, $bedKey]
            );
        }
        if ($saved === false) {
            throw new RuntimeException('Project bed row sync failed: ' . trim((string) $db->ErrorMsg()));
        }

        $readBack = $db->GetRow(
            "SELECT bed_key, bed_source_key, bed_no, managed_status, source_bed_status, DATE_FORMAT(last_synced_at, '%Y-%m-%d %H:%i') AS last_synced_at FROM project_bed WHERE bed_key = ? FOR UPDATE",
            [$bedKey]
        );
        if (!is_array($readBack) || (string) ($readBack['bed_key'] ?? '') !== $bedKey) {
            throw new RuntimeException('Project bed read-back failed after sync.');
        }
        if (!preg_match('/^[A-Za-z0-9]{20}$/', (string) ($readBack['bed_key'] ?? ''))) {
            throw new RuntimeException('Project bed_key is not a Firebase document id after sync.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Project bed sync transaction could not commit.');
        }
        $transactionStarted = false;

        bx_audit('SYNC', 'project_bed', $bedKey, [
            'source_table' => 'RBMS_BedMasterlist',
            'source_found' => $source !== null ? '1' : '0',
            'sync_batch_key' => $batchKey,
            'firebase_document_key_format' => 'bed_key',
            'user_key' => (string) ($userKey ?? ''),
        ], 'Administrator re-synced one project_bed row from RBMS_BedMasterlist.');

        $analytics = bx_refresh_project_bed_analytics($batchKey, $userKey);
        $bedRows = bx_project_bed_firebase_rows(null, $bedKey, 1);
        $floorKeys = [$oldFloorGroupKey];
        foreach ($bedRows as $bedRow) {
            $floorKeys[] = bx_project_bed_floor_group_key($bedRow);
        }
        $floorKeys = array_values(array_unique(array_filter($floorKeys)));
        $readBack['sourceFound'] = $source !== null;
        $readBack['batchKey'] = $batchKey;
        $readBack['analyticsRows'] = (int) ($analytics['activeRows'] ?? 0);
        $readBack['firebaseSync'] = bx_sync_project_bed_rows_to_firebase(
            $bedRows,
            is_array($analytics['documents'] ?? null) ? $analytics['documents'] : bx_project_bed_analytics_documents($batchKey),
            bx_project_bed_floor_documents(null, $floorKeys, array_merge([$managed], $bedRows)),
            false
        );

        return $readBack;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_resync_bed_master_list(?string $userKey = null): array
{
    bx_ensure_bed_master_list_schema();
    if (!bx_bed_master_list_source_exists()) {
        throw new RuntimeException('Source table RBMS_BedMasterlist was not found.');
    }

    $db = bx_db();
    $batchKey = bx_uuid();
    $transactionStarted = false;

    $cleanSource = static function (string $column): string {
        return "NULLIF(TRIM(CAST(source.`{$column}` AS CHAR)), '')";
    };

    try {
        if ($db->BeginTrans() === false) {
            throw new RuntimeException('Bed master list sync transaction could not start.');
        }
        $transactionStarted = true;

        $sourceRows = (int) $db->GetOne('SELECT COUNT(*) FROM `RBMS_BedMasterlist`');
        $beforeManagedRows = (int) $db->GetOne('SELECT COUNT(*) FROM project_bed');
        $sourceKeySql = "CONCAT('RBMS_BedMasterlist:', COALESCE({$cleanSource('PK_psBeds')}, CONCAT('id:', source.`id`)))";
        $saved = $db->Execute(
            "
            INSERT INTO project_bed (
                bed_key, bed_source_key, source_table, source_id, source_pk_psbeds, bed_no,
                branch_key, branch_name, building_key, building_name, floor_key, floor_name,
                nurse_station_key, nurse_station_name, room_key, room_class_key, room_class,
                source_bed_status_key, source_bed_status, managed_status, sync_batch_key,
                last_synced_at, last_seen_at
            )
            SELECT
                source.generated_bed_key,
                {$sourceKeySql},
                'RBMS_BedMasterlist',
                source.`id`,
                {$cleanSource('PK_psBeds')},
                {$cleanSource('bedno')},
                {$cleanSource('PK_mscBranches')},
                {$cleanSource('branchname')},
                {$cleanSource('PK_mscBldgs')},
                {$cleanSource('bldgname')},
                {$cleanSource('PK_mscBldgFloors')},
                {$cleanSource('floorname')},
                {$cleanSource('PK_mscNrstation')},
                {$cleanSource('Nrstation')},
                {$cleanSource('PK_psRooms')},
                {$cleanSource('PK_mscRoomClass')},
                {$cleanSource('RoomClass')},
                {$cleanSource('PK_mscBedStatus')},
                {$cleanSource('BedStatus')},
                'ACTIVE',
                ?,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            FROM (
                SELECT LEFT(REPLACE(UUID(), '-', ''), 20) AS generated_bed_key, source.*
                FROM `RBMS_BedMasterlist` source
                WHERE source.`id` IS NOT NULL
            ) source
            ON DUPLICATE KEY UPDATE
                source_id = VALUES(source_id),
                source_pk_psbeds = VALUES(source_pk_psbeds),
                bed_no = VALUES(bed_no),
                branch_key = VALUES(branch_key),
                branch_name = VALUES(branch_name),
                building_key = VALUES(building_key),
                building_name = VALUES(building_name),
                floor_key = VALUES(floor_key),
                floor_name = VALUES(floor_name),
                nurse_station_key = VALUES(nurse_station_key),
                nurse_station_name = VALUES(nurse_station_name),
                room_key = VALUES(room_key),
                room_class_key = VALUES(room_class_key),
                room_class = VALUES(room_class),
                source_bed_status_key = VALUES(source_bed_status_key),
                source_bed_status = VALUES(source_bed_status),
                managed_status = 'ACTIVE',
                sync_batch_key = VALUES(sync_batch_key),
                last_synced_at = CURRENT_TIMESTAMP,
                last_seen_at = CURRENT_TIMESTAMP
            ",
            [$batchKey]
        );
        if ($saved === false) {
            throw new RuntimeException('Bed master list upsert failed: ' . trim((string) $db->ErrorMsg()));
        }

        $inactive = $db->Execute(
            "
            UPDATE project_bed managed
            LEFT JOIN `RBMS_BedMasterlist` source
                ON managed.bed_source_key COLLATE utf8mb4_unicode_ci = CONCAT('RBMS_BedMasterlist:', COALESCE(NULLIF(TRIM(CAST(source.`PK_psBeds` AS CHAR)), ''), CONCAT('id:', source.`id`))) COLLATE utf8mb4_unicode_ci
            SET managed.managed_status = 'INACTIVE',
                managed.sync_batch_key = ?,
                managed.last_synced_at = CURRENT_TIMESTAMP
            WHERE managed.source_table = 'RBMS_BedMasterlist'
                AND source.`id` IS NULL
            ",
            [$batchKey]
        );
        if ($inactive === false) {
            throw new RuntimeException('Bed master list inactive reconciliation failed: ' . trim((string) $db->ErrorMsg()));
        }

        $activeRows = (int) $db->GetOne("SELECT COUNT(*) FROM project_bed WHERE managed_status = 'ACTIVE'");
        $managedRows = (int) $db->GetOne('SELECT COUNT(*) FROM project_bed');
        $firebaseDocumentRows = (int) $db->GetOne("SELECT COUNT(*) FROM project_bed WHERE TRIM(bed_key) REGEXP '^[A-Za-z0-9]{20}$'");
        $lastSyncedAt = (string) ($db->GetOne('SELECT MAX(last_synced_at) FROM project_bed WHERE sync_batch_key = ?', [$batchKey]) ?: '');
        if ($activeRows !== $sourceRows) {
            throw new RuntimeException('Bed master list read-back mismatch after sync.');
        }
        if ($firebaseDocumentRows !== $managedRows) {
            throw new RuntimeException('Project bed_key Firebase document id read-back mismatch after sync.');
        }

        if ($db->CommitTrans() === false) {
            throw new RuntimeException('Bed master list sync transaction could not commit.');
        }
        $transactionStarted = false;

        $analytics = bx_refresh_project_bed_analytics($batchKey, $userKey);
        $summary = bx_bed_master_list_summary();
        $summary['batchKey'] = $batchKey;
        $summary['beforeManagedRows'] = $beforeManagedRows;
        $summary['lastSyncedAt'] = $lastSyncedAt ?: $summary['lastSyncedAt'];
        $summary['analyticsRows'] = (int) ($analytics['activeRows'] ?? 0);
        $summary['analyticsBatchKey'] = $batchKey;
        $floorDocuments = bx_project_bed_floor_documents($batchKey);
        $summary['firebaseSync'] = bx_sync_project_bed_rows_to_firebase(
            bx_project_bed_firebase_rows($batchKey, null, max(1, $managedRows)),
            is_array($analytics['documents'] ?? null) ? $analytics['documents'] : bx_project_bed_analytics_documents($batchKey),
            $floorDocuments,
            true
        );
        $summary['floorGroups'] = count($floorDocuments);

        bx_audit('SYNC', 'project_bed', $batchKey, [
            'source_table' => 'RBMS_BedMasterlist',
            'source_rows' => (string) $sourceRows,
            'managed_rows' => (string) $managedRows,
            'active_rows' => (string) $activeRows,
            'analytics_rows' => (string) ($summary['analyticsRows'] ?? 0),
            'floor_groups' => (string) ($summary['floorGroups'] ?? 0),
            'firebase_document_key_format' => 'bed_key',
            'user_key' => (string) ($userKey ?? ''),
        ], 'Administrator re-synced project_bed from RBMS_BedMasterlist with bed_key Firebase document ids.');

        return $summary;
    } catch (Throwable $error) {
        if ($transactionStarted) {
            $db->RollbackTrans();
        }
        throw $error;
    }
}

function bx_setting(string $name, ?string $default = null): ?string
{
    $value = bx_db()->GetOne(
        "SELECT setting_value FROM builder_system_setting WHERE setting_name = ? AND setting_status = 'ACTIVE'",
        [$name]
    );

    return $value === false || $value === null ? $default : (string) $value;
}

function bx_audit(string $action, string $module, ?string $recordKey = null, array $newValues = [], ?string $reason = null): void
{
    $encodedValues = $newValues
        ? json_encode($newValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        : null;
    $saved = bx_db()->Execute(
        'INSERT INTO builder_audit_log (audit_key, user_key, action, module, record_key, new_values, ip_address, user_agent, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            bx_uuid(),
            $_SESSION['builderx_user_key'] ?? null,
            $action,
            $module,
            $recordKey,
            $encodedValues,
            bx_client_ip(),
            bx_user_agent(),
            $reason,
        ]
    );
    if ($saved === false) {
        $databaseError = trim((string) bx_db()->ErrorMsg());
        throw new RuntimeException('Audit event persistence failed' . ($databaseError !== '' ? ': ' . $databaseError : '.'));
    }
}

function bx_user_has_permission(?array $user, string $permissionCode): bool
{
    if (!$user || $permissionCode === '') {
        return false;
    }

    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_status = 'ACTIVE'
        JOIN builder_role_permission rp ON rp.role_key = r.role_key
        JOIN builder_permission p ON p.permission_key = rp.permission_key AND p.permission_status = 'ACTIVE'
        WHERE ur.user_key = ? AND p.permission_code = ?",
        [$user['user_key'], $permissionCode]
    ) > 0;
}

function bx_authorization_list(mixed $value): array
{
    if ($value === null || $value === false || $value === '') {
        return [];
    }
    if (!is_array($value)) {
        $value = [$value];
    }

    return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value), static fn (string $item): bool => $item !== ''));
}

function bx_authorization_missing(array $required, array $actual, bool $caseInsensitive = false): ?string
{
    if ($required === []) {
        return null;
    }

    $actualLookup = [];
    foreach ($actual as $item) {
        $key = $caseInsensitive ? strtolower((string) $item) : (string) $item;
        $actualLookup[$key] = true;
    }

    foreach ($required as $item) {
        $key = $caseInsensitive ? strtolower((string) $item) : (string) $item;
        if (!isset($actualLookup[$key])) {
            return (string) $item;
        }
    }

    return null;
}

function bx_authorization_result(bool $allowed, string $reasonCode, string $message, ?array $user = null, array $context = []): array
{
    return [
        'allowed' => $allowed,
        'reasonCode' => $reasonCode,
        'message' => $message,
        'user' => $user,
        'sessionKey' => (string) ($context['sessionKey'] ?? ''),
        'roleNames' => $context['roleNames'] ?? [],
        'roleKeys' => $context['roleKeys'] ?? [],
        'permissionCodes' => $context['permissionCodes'] ?? [],
        'groupKeys' => $context['groupKeys'] ?? [],
        'groupNames' => $context['groupNames'] ?? [],
        'branchKeys' => $context['branchKeys'] ?? [],
        'branchNames' => $context['branchNames'] ?? [],
        'branchCodes' => $context['branchCodes'] ?? [],
        'projectKeys' => $context['projectKeys'] ?? [],
        'projectBranchKeys' => $context['projectBranchKeys'] ?? [],
    ];
}

function bx_authorization_status_code(array $authorization): int
{
    return in_array((string) ($authorization['reasonCode'] ?? ''), ['authentication_required', 'session_required', 'session_invalid', 'account_inactive'], true) ? 401 : 403;
}

function bx_authorization_guard(array $requirements = []): array
{
    $requireAuthenticated = (bool) ($requirements['requireAuthenticated'] ?? true);
    $userKey = trim((string) ($_SESSION['builderx_user_key'] ?? ''));
    $sessionKey = trim((string) ($_SESSION['builderx_session_key'] ?? ''));
    if ($userKey === '' || $sessionKey === '') {
        return $requireAuthenticated
            ? bx_authorization_result(false, $userKey === '' ? 'authentication_required' : 'session_required', 'Sign in before continuing.')
            : bx_authorization_result(true, 'anonymous_allowed', 'Anonymous access allowed.');
    }

    $user = bx_db()->GetRow(
        "SELECT u.*
        FROM builder_user_session s
        JOIN builder_user u ON u.user_key = s.user_key
        WHERE s.session_key = ?
            AND s.user_key = ?
            AND s.session_token_hash = ?
            AND s.session_status = 'ACTIVE'
            AND (s.expires_at IS NULL OR s.expires_at > CURRENT_TIMESTAMP)
            AND u.user_status = 'ACTIVE'
            AND u.user_deleted_at IS NULL
        LIMIT 1",
        [$sessionKey, $userKey, hash('sha256', session_id())]
    );
    if (!$user) {
        return bx_authorization_result(false, 'session_invalid', 'Sign in before continuing.');
    }

    $roleRows = bx_db()->GetAll(
        "SELECT r.role_key, r.role_name
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_status = 'ACTIVE'
        WHERE ur.user_key = ?",
        [$userKey]
    ) ?: [];
    $permissionRows = bx_db()->GetAll(
        "SELECT DISTINCT p.permission_code
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key AND r.role_status = 'ACTIVE'
        JOIN builder_role_permission rp ON rp.role_key = r.role_key
        JOIN builder_permission p ON p.permission_key = rp.permission_key AND p.permission_status = 'ACTIVE'
        WHERE ur.user_key = ?",
        [$userKey]
    ) ?: [];
    $groupRows = bx_db()->GetAll(
        "SELECT g.group_key, g.group_name
        FROM builder_user_group ug
        JOIN builder_group g ON g.group_key = ug.group_key AND g.group_status = 'ACTIVE'
        WHERE ug.user_key = ?",
        [$userKey]
    ) ?: [];
    $branchRows = bx_db()->GetAll(
        "SELECT b.branch_key, b.branch_name, b.branch_code
        FROM builder_user_branch ub
        JOIN builder_branch b ON b.branch_key = ub.branch_key AND b.branch_status = 'ACTIVE'
        WHERE ub.user_key = ?",
        [$userKey]
    ) ?: [];
    $projectRows = bx_db()->GetAll(
        "SELECT p.project_key, p.branch_key
        FROM builder_user_project up
        JOIN builder_project p ON p.project_key = up.project_key AND p.project_status = 'ACTIVE'
        JOIN builder_user_branch pb ON pb.user_key = up.user_key AND pb.branch_key = p.branch_key
        JOIN builder_branch b ON b.branch_key = p.branch_key AND b.branch_status = 'ACTIVE'
        WHERE up.user_key = ?",
        [$userKey]
    ) ?: [];

    $context = [
        'sessionKey' => $sessionKey,
        'roleNames' => array_values(array_map(static fn (array $row): string => (string) $row['role_name'], $roleRows)),
        'roleKeys' => array_values(array_map(static fn (array $row): string => (string) $row['role_key'], $roleRows)),
        'permissionCodes' => array_values(array_map(static fn (array $row): string => (string) $row['permission_code'], $permissionRows)),
        'groupKeys' => array_values(array_map(static fn (array $row): string => (string) $row['group_key'], $groupRows)),
        'groupNames' => array_values(array_map(static fn (array $row): string => (string) $row['group_name'], $groupRows)),
        'branchKeys' => array_values(array_map(static fn (array $row): string => (string) $row['branch_key'], $branchRows)),
        'branchNames' => array_values(array_map(static fn (array $row): string => (string) $row['branch_name'], $branchRows)),
        'branchCodes' => array_values(array_map(static fn (array $row): string => (string) $row['branch_code'], $branchRows)),
        'projectKeys' => array_values(array_map(static fn (array $row): string => (string) $row['project_key'], $projectRows)),
        'projectBranchKeys' => array_values(array_unique(array_map(static fn (array $row): string => (string) $row['branch_key'], $projectRows))),
    ];

    $requireTenant = (bool) ($requirements['requireTenant'] ?? $requireAuthenticated);
    if ($requireTenant && ($context['branchKeys'] === [] || $context['projectKeys'] === [])) {
        return bx_authorization_result(false, 'tenant_required', 'Request not authorized.', $user, $context);
    }

    if (!empty($requirements['requireAdmin']) && bx_authorization_missing(['Administrator'], $context['roleNames'], true) !== null) {
        return bx_authorization_result(false, 'administrator_required', 'Administrator access is required.', $user, $context);
    }

    foreach ([
        'roleNames' => ['values' => $context['roleNames'], 'reason' => 'role_required'],
        'roleKeys' => ['values' => $context['roleKeys'], 'reason' => 'role_required'],
        'permissions' => ['values' => $context['permissionCodes'], 'reason' => 'permission_required'],
        'permissionCodes' => ['values' => $context['permissionCodes'], 'reason' => 'permission_required'],
        'groupKeys' => ['values' => $context['groupKeys'], 'reason' => 'group_required'],
        'groupNames' => ['values' => $context['groupNames'], 'reason' => 'group_required'],
        'branchKeys' => ['values' => $context['branchKeys'], 'reason' => 'branch_required'],
        'projectKeys' => ['values' => $context['projectKeys'], 'reason' => 'project_required'],
    ] as $key => $constraint) {
        $missing = bx_authorization_missing(bx_authorization_list($requirements[$key] ?? []), $constraint['values'], $key !== 'permissions' && $key !== 'permissionCodes');
        if ($missing !== null) {
            return bx_authorization_result(false, $constraint['reason'], 'Request not authorized.', $user, $context);
        }
    }

    foreach ([
        'floor' => 'requireFloor',
        'taskStage' => 'requireTaskStage',
        'trigger' => 'requireTrigger',
        'bypass' => 'requireBypass',
    ] as $key => $requiredKey) {
        $expected = bx_authorization_list($requirements[$key] ?? []);
        $required = !empty($requirements[$requiredKey]) || $expected !== [];
        if (!$required) {
            continue;
        }
        $trustedValues = is_array($requirements['trustedValues'] ?? null) ? $requirements['trustedValues'] : [];
        $actual = bx_authorization_list($trustedValues[$key] ?? $requirements['trusted' . ucfirst($key)] ?? []);
        if ($key === 'floor' && $actual === []) {
            $actual = array_values(array_unique(array_merge(
                $context['branchKeys'],
                $context['branchNames'],
                $context['branchCodes']
            )));
        }
        if ($actual === []) {
            return bx_authorization_result(false, $key . '_untrusted', 'Request not authorized.', $user, $context);
        }
        if ($expected !== [] && bx_authorization_missing($expected, $actual, true) !== null) {
            return bx_authorization_result(false, $key . '_required', 'Request not authorized.', $user, $context);
        }
    }

    return bx_authorization_result(true, 'authorized', 'Authorized.', $user, $context);
}

function bx_mask_email(?string $email): string
{
    $email = trim((string) $email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $prefix = substr($local, 0, 1);

    return $prefix . '***@' . $domain;
}

function bx_mask_phone(?string $phone): string
{
    $phone = trim((string) $phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone) ?: '';
    if (strlen($digits) <= 4) {
        return '***';
    }

    return '***' . substr($digits, -4);
}

function bx_count(string $table, string $where = '1=1'): int
{
    return (int) bx_db()->GetOne("SELECT COUNT(*) FROM {$table} WHERE {$where}");
}

function bx_current_user(): ?array
{
    $authorization = bx_authorization_guard(['requireAuthenticated' => false]);

    return $authorization['allowed'] && is_array($authorization['user'] ?? null) ? $authorization['user'] : null;
}

/**
 * Return only the authenticated identity fields required by the User Portal.
 * Authentication and account-security columns must remain server-side.
 *
 * @param array<string, mixed>|null $user
 * @return array{user_key: string, user_name: string}|null
 */
function bx_user_public_projection(?array $user): ?array
{
    if ($user === null) {
        return null;
    }

    return [
        'user_key' => (string) ($user['user_key'] ?? ''),
        'user_name' => (string) ($user['user_name'] ?? ''),
    ];
}

function bx_is_admin(array $user): bool
{
    return (int) bx_db()->GetOne(
        "SELECT COUNT(*)
        FROM builder_user_role ur
        JOIN builder_role r ON r.role_key = ur.role_key
        WHERE ur.user_key = ? AND r.role_name = 'Administrator' AND r.role_status = 'ACTIVE'",
        [$user['user_key']]
    ) > 0;
}

function bx_login(string $login, string $password): bool
{
    $user = bx_db()->GetRow(
        "SELECT * FROM builder_user WHERE (user_login = ? OR user_email = ?) AND user_status IN ('ACTIVE','LOCKED')",
        [$login, $login]
    );

    if (!$user) {
        bx_login_history(null, $login, 'FAILED', 'User not found.');
        return false;
    }

    if ($user['user_status'] === 'LOCKED') {
        bx_login_history($user['user_key'], $login, 'LOCKED', 'Account is locked.');
        return false;
    }

    if (!password_verify($password, $user['user_password_hash'])) {
        $failed = (int) $user['user_failed_login_count'] + 1;
        $status = $failed >= 5 ? 'LOCKED' : 'ACTIVE';
        bx_db()->Execute('UPDATE builder_user SET user_failed_login_count = ?, user_status = ? WHERE user_key = ?', [$failed, $status, $user['user_key']]);
        bx_login_history($user['user_key'], $login, $status === 'LOCKED' ? 'LOCKED' : 'FAILED', 'Invalid password.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['builderx_user_key'] = $user['user_key'];
    $_SESSION['builderx_user_name'] = $user['user_name'];
    $_SESSION['builderx_session_key'] = bx_uuid();

    bx_db()->Execute('UPDATE builder_user SET user_failed_login_count = 0, user_last_login_at = CURRENT_TIMESTAMP WHERE user_key = ?', [$user['user_key']]);
    bx_db()->Execute(
        'INSERT INTO builder_user_session (session_key, user_key, session_token_hash, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [$_SESSION['builderx_session_key'], $user['user_key'], hash('sha256', session_id()), bx_client_ip(), bx_user_agent(), (int) bx_setting('session_timeout_minutes', '120')]
    );
    bx_login_history($user['user_key'], $login, 'SUCCESS', null);
    bx_audit('LOGIN', 'authentication', $user['user_key']);

    return true;
}

function bx_login_history(?string $userKey, string $login, string $status, ?string $reason): void
{
    bx_db()->Execute(
        'INSERT INTO builder_user_login_history (login_key, user_key, user_login, login_status, ip_address, user_agent, failure_reason) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [bx_uuid(), $userKey, $login, $status, bx_client_ip(), bx_user_agent(), $reason]
    );
}

function bx_recovery_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function bx_request_password_reset(string $login): ?string
{
    $identity = trim($login);
    if ($identity === '') {
        bx_flash('Enter your username or email address.', 'error');
        return null;
    }

    $user = bx_db()->GetRow(
        "SELECT * FROM builder_user WHERE (user_login = ? OR user_email = ?) AND user_status IN ('ACTIVE','LOCKED')",
        [$identity, $identity]
    );

    if (!$user) {
        bx_audit('PASSWORD_RESET_REQUEST_UNKNOWN', 'authentication', null, ['identity' => $identity], 'Password reset requested for an unknown account.');
        bx_flash('If the account exists, a recovery link is ready for email delivery.', 'success');
        return null;
    }

    bx_db()->Execute(
        "UPDATE builder_user_password_reset
        SET reset_status = 'REVOKED'
        WHERE user_key = ? AND reset_status = 'PENDING'",
        [$user['user_key']]
    );

    $token = bin2hex(random_bytes(32));
    $tokenMinutes = max(5, (int) bx_setting('password_reset_token_minutes', '30'));
    bx_db()->Execute(
        "INSERT INTO builder_user_password_reset (
            reset_key,
            user_key,
            reset_token_hash,
            requested_ip,
            requested_user_agent,
            email_delivery_status,
            email_verification_required,
            two_factor_required,
            expires_at
        ) VALUES (?, ?, ?, ?, ?, 'PLACEHOLDER', ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))",
        [
            bx_uuid(),
            $user['user_key'],
            bx_recovery_token_hash($token),
            bx_client_ip(),
            bx_user_agent(),
            empty($user['user_email_verified_at']) ? 1 : 0,
            (int) $user['user_two_factor_required'] === 1 ? 1 : 0,
            $tokenMinutes,
        ]
    );

    bx_audit('PASSWORD_RESET_REQUEST', 'authentication', $user['user_key'], [
        'user_login' => $user['user_login'],
        'email_delivery_status' => 'PLACEHOLDER',
        'expires_in_minutes' => $tokenMinutes,
    ], 'Password reset link generated.');
    bx_flash('If the account exists, a recovery link is ready for email delivery.', 'success');

    return $token;
}

function bx_password_was_recently_used(string $userKey, string $password): bool
{
    $historyCount = max(0, (int) bx_setting('password_history_count', '3'));
    if ($historyCount === 0) {
        return false;
    }

    $rows = bx_db()->GetAll(
        'SELECT password_hash FROM builder_user_password_history WHERE user_key = ? ORDER BY created_at DESC, x_id DESC LIMIT ' . $historyCount,
        [$userKey]
    );

    foreach ($rows as $row) {
        if (password_verify($password, (string) $row['password_hash'])) {
            return true;
        }
    }

    $currentHash = (string) bx_db()->GetOne('SELECT user_password_hash FROM builder_user WHERE user_key = ?', [$userKey]);
    return $currentHash !== '' && password_verify($password, $currentHash);
}

function bx_remember_password_history(string $userKey, string $passwordHash, string $reason): void
{
    bx_db()->Execute(
        'INSERT INTO builder_user_password_history (history_key, user_key, password_hash, changed_by_key, change_reason) VALUES (?, ?, ?, ?, ?)',
        [bx_uuid(), $userKey, $passwordHash, $_SESSION['builderx_user_key'] ?? null, $reason]
    );
}

function bx_reset_password_with_token(string $token, string $password, string $passwordConfirm): bool
{
    $token = trim($token);
    if ($token === '') {
        bx_flash('Password reset token is required.', 'error');
        return false;
    }

    if (strlen($password) < (int) bx_setting('password_min_length', '10')) {
        bx_flash('Password is shorter than the configured minimum length.', 'error');
        return false;
    }

    if ($password !== $passwordConfirm) {
        bx_flash('Password confirmation does not match.', 'error');
        return false;
    }

    $reset = bx_db()->GetRow(
        "SELECT r.*, u.user_login, u.user_status
        FROM builder_user_password_reset r
        JOIN builder_user u ON u.user_key = r.user_key
        WHERE r.reset_token_hash = ?",
        [bx_recovery_token_hash($token)]
    );

    if (!$reset || $reset['reset_status'] !== 'PENDING') {
        bx_flash('Password reset link is invalid or already used.', 'error');
        return false;
    }

    if (strtotime((string) $reset['expires_at']) < time()) {
        bx_db()->Execute(
            "UPDATE builder_user_password_reset SET reset_status = 'EXPIRED' WHERE reset_key = ?",
            [$reset['reset_key']]
        );
        bx_audit('PASSWORD_RESET_EXPIRED', 'authentication', $reset['user_key'], ['user_login' => $reset['user_login']], 'Expired password reset link used.');
        bx_flash('Password reset link has expired. Request a new recovery link.', 'error');
        return false;
    }

    if (bx_password_was_recently_used((string) $reset['user_key'], $password)) {
        bx_audit('PASSWORD_RESET_REJECTED', 'authentication', $reset['user_key'], ['reason' => 'password-history'], 'Password reset rejected by history policy.');
        bx_flash('Choose a password that was not used recently.', 'error');
        return false;
    }

    $passwordHash = bx_password_hash($password);
    $expirationDays = max(0, (int) bx_setting('password_expiration_days', '90'));
    $expiresSql = $expirationDays > 0 ? 'DATE_ADD(NOW(), INTERVAL ' . $expirationDays . ' DAY)' : 'NULL';

    bx_db()->Execute(
        "UPDATE builder_user
        SET user_password_hash = ?,
            user_password_changed_at = CURRENT_TIMESTAMP,
            user_password_expires_at = {$expiresSql},
            user_failed_login_count = 0,
            user_status = CASE WHEN user_status = 'LOCKED' THEN 'ACTIVE' ELSE user_status END
        WHERE user_key = ?",
        [$passwordHash, $reset['user_key']]
    );
    bx_remember_password_history((string) $reset['user_key'], $passwordHash, 'account-recovery');

    bx_db()->Execute(
        "UPDATE builder_user_password_reset
        SET reset_status = 'USED',
            used_ip = ?,
            used_user_agent = ?,
            used_at = CURRENT_TIMESTAMP
        WHERE reset_key = ?",
        [bx_client_ip(), bx_user_agent(), $reset['reset_key']]
    );
    bx_db()->Execute(
        "UPDATE builder_user_password_reset SET reset_status = 'REVOKED' WHERE user_key = ? AND reset_status = 'PENDING'",
        [$reset['user_key']]
    );

    bx_audit('PASSWORD_RESET_COMPLETE', 'authentication', $reset['user_key'], [
        'user_login' => $reset['user_login'],
        'password_expires_in_days' => $expirationDays,
    ], 'Password reset completed through account recovery.');
    bx_flash('Password reset complete. You can sign in with the new password.', 'success');

    return true;
}

function bx_logout(): void
{
    if (!empty($_SESSION['builderx_session_key'])) {
        bx_db()->Execute(
            "UPDATE builder_user_session SET session_status = 'REVOKED', revoked_at = CURRENT_TIMESTAMP WHERE session_key = ?",
            [$_SESSION['builderx_session_key']]
        );
    }

    bx_audit('LOGOUT', 'authentication', $_SESSION['builderx_user_key'] ?? null);
    unset($_SESSION['builderx_user_key'], $_SESSION['builderx_user_name'], $_SESSION['builderx_session_key']);
}

function bx_create_initial_admin(array $input): bool
{
    if (bx_count('builder_user') > 0) {
        bx_flash('Initial administrator already exists.', 'error');
        return false;
    }

    if (strlen($input['password']) < (int) bx_setting('password_min_length', '10')) {
        bx_flash('Password is shorter than the configured minimum length.', 'error');
        return false;
    }

    if ($input['password'] !== $input['password_confirm']) {
        bx_flash('Password confirmation does not match.', 'error');
        return false;
    }

    $userKey = bx_unique_firebase_document_key('builder_user', 'user_key');
    $positionKey = (string) (bx_db()->GetOne('SELECT position_key FROM builder_user_position WHERE position_code = ?', ['ADMINISTRATOR']) ?: '');
    bx_db()->Execute(
        "INSERT INTO builder_user (user_key, user_login, user_password_hash, user_name, user_email, position_key, user_status, user_password_changed_at)
        VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', CURRENT_TIMESTAMP)",
        [$userKey, $input['login'], bx_password_hash($input['password']), $input['name'], $input['email'], $positionKey === '' ? null : $positionKey]
    );

    foreach (['Administrator'] as $roleName) {
        $roleKey = (string) bx_db()->GetOne('SELECT role_key FROM builder_role WHERE role_name = ?', [$roleName]);
        bx_db()->Execute('INSERT IGNORE INTO builder_user_role (user_key, role_key) VALUES (?, ?)', [$userKey, $roleKey]);
    }

    $groupKey = (string) bx_db()->GetOne('SELECT group_key FROM builder_group WHERE group_name = ?', ['Administrators']);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_group (user_key, group_key) VALUES (?, ?)', [$userKey, $groupKey]);

    $branchKey = (string) bx_db()->GetOne('SELECT branch_key FROM builder_branch WHERE branch_code = ?', ['HO']);
    $projectKey = (string) bx_db()->GetOne('SELECT project_key FROM builder_project WHERE project_code = ?', ['CORE']);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_branch (user_key, branch_key) VALUES (?, ?)', [$userKey, $branchKey]);
    bx_db()->Execute('INSERT IGNORE INTO builder_user_project (user_key, project_key) VALUES (?, ?)', [$userKey, $projectKey]);

    bx_audit('CREATE', 'builder_user', $userKey, ['user_login' => $input['login'], 'role' => 'Administrator'], 'Initial administrator created.');
    bx_flash('Initial administrator created. You can now sign in.', 'success');

    return true;
}

bx_schema();
