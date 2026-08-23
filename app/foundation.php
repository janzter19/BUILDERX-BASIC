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

function bx_flash(string $message, string $type = 'info', ?string $details = null): void
{
    $_SESSION['builderx_flash'] = ['message' => $message, 'type' => $type];
    if ($details !== null && trim($details) !== '') {
        $_SESSION['builderx_flash']['details'] = substr(trim($details), 0, 4000);
    }
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
        CREATE TABLE IF NOT EXISTS builder_user (
            x_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_key CHAR(36) NOT NULL UNIQUE,
            user_login VARCHAR(80) NOT NULL UNIQUE,
            user_password_hash VARCHAR(255) NOT NULL,
            user_name VARCHAR(160) NOT NULL,
            user_email VARCHAR(190) NOT NULL UNIQUE,
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
        ['codex_chat_id', builderxConfigValue('codex_chat_id'), 'ai'],
    ];

    foreach ($settings as $setting) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_system_setting WHERE setting_name = ?', [$setting[0]]) === 0) {
            bx_db()->Execute(
                'INSERT INTO builder_system_setting (setting_key, setting_name, setting_value, setting_group) VALUES (?, ?, ?, ?)',
                [bx_uuid(), $setting[0], $setting[1], $setting[2]]
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

    $adminRole = (string) bx_db()->GetOne('SELECT role_key FROM builder_role WHERE role_name = ?', ['Administrator']);
    $permissions = bx_db()->GetAll('SELECT permission_key FROM builder_permission');
    foreach ($permissions as $permission) {
        if ((int) bx_db()->GetOne('SELECT COUNT(*) FROM builder_role_permission WHERE role_key = ? AND permission_key = ?', [$adminRole, $permission['permission_key']]) === 0) {
            bx_db()->Execute('INSERT INTO builder_role_permission (role_key, permission_key) VALUES (?, ?)', [$adminRole, $permission['permission_key']]);
        }
    }

    (new \BuilderX\AI\AiSpecialistRegistry())->ensureSystemSpecialists();
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
        "SELECT b.branch_key
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

    $userKey = bx_uuid();
    bx_db()->Execute(
        "INSERT INTO builder_user (user_key, user_login, user_password_hash, user_name, user_email, user_status, user_password_changed_at)
        VALUES (?, ?, ?, ?, ?, 'ACTIVE', CURRENT_TIMESTAMP)",
        [$userKey, $input['login'], bx_password_hash($input['password']), $input['name'], $input['email']]
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
