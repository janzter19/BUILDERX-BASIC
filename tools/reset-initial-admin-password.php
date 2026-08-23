<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "POSIX identity support is required.\n");
    exit(1);
}

$osAccount = posix_getpwuid(posix_geteuid());
$osUser = is_array($osAccount) ? (string) ($osAccount['name'] ?? '') : '';
if (!in_array($osUser, ['root', 'www-data'], true)) {
    fwrite(STDERR, "Run this recovery tool as the project web identity.\n");
    exit(1);
}

$login = trim((string) ($argv[1] ?? 'admin'));
if (preg_match('/^[A-Za-z0-9_.@-]{1,80}$/', $login) !== 1) {
    fwrite(STDERR, "The administrator login is invalid.\n");
    exit(1);
}

/** Read a password from the interactive terminal without echoing it. */
function builderxReadHiddenPassword(string $prompt): string
{
    if (!stream_isatty(STDIN)) {
        throw new RuntimeException('An interactive terminal is required.');
    }

    $terminalMode = trim((string) shell_exec('stty -g 2>/dev/null'));
    if ($terminalMode === '' || preg_match('/^[0-9a-f:]+$/i', $terminalMode) !== 1) {
        throw new RuntimeException('The terminal mode could not be protected.');
    }

    fwrite(STDOUT, $prompt);
    shell_exec('stty -echo 2>/dev/null');
    try {
        $value = fgets(STDIN);
    } finally {
        shell_exec('stty ' . escapeshellarg($terminalMode) . ' 2>/dev/null');
        fwrite(STDOUT, PHP_EOL);
    }

    if ($value === false) {
        throw new RuntimeException('The password could not be read.');
    }

    return rtrim($value, "\r\n");
}

try {
    require dirname(__DIR__) . '/app/foundation.php';

    $password = builderxReadHiddenPassword('New administrator password: ');
    $confirmation = builderxReadHiddenPassword('Confirm administrator password: ');
    $minimumLength = (int) bx_setting('password_min_length', '10');
    if (strlen($password) < $minimumLength) {
        throw new RuntimeException('The password is shorter than the configured minimum length.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new RuntimeException('The password confirmation does not match.');
    }

    $db = bx_db();
    $user = $db->GetRow(
        'SELECT user_key FROM builder_user WHERE user_login = ? LIMIT 1',
        [$login]
    );
    $userKey = (string) ($user['user_key'] ?? '');
    $administratorRoleCount = $userKey !== '' ? (int) $db->GetOne(
        "SELECT COUNT(*) FROM builder_user_role ur JOIN builder_role r ON r.role_key = ur.role_key WHERE ur.user_key = ? AND r.role_name = 'Administrator' AND r.role_status = 'ACTIVE'",
        [$userKey]
    ) : 0;
    if ($userKey === '' || $administratorRoleCount !== 1) {
        throw new RuntimeException('The active Administrator account was not found.');
    }

    $db->StartTrans();
    try {
        $newHash = bx_password_hash($password);
        $saved = $db->Execute(
            "UPDATE builder_user
             SET user_password_hash = ?, user_password_changed_at = CURRENT_TIMESTAMP,
                 user_failed_login_count = 0, user_status = 'ACTIVE'
             WHERE user_key = ?",
            [$newHash, $userKey]
        );
        if ($saved === false) {
            throw new RuntimeException('The administrator password update failed.');
        }

        bx_audit(
            'PASSWORD_RESET',
            'authentication',
            $userKey,
            ['user_login' => $login],
            'Local CLI administrator password recovery.'
        );

        $readBack = $db->GetRow(
            'SELECT user_password_hash, user_status, user_failed_login_count FROM builder_user WHERE user_key = ? FOR UPDATE',
            [$userKey]
        );
        if (!is_array($readBack)
            || !password_verify($password, (string) ($readBack['user_password_hash'] ?? ''))
            || !hash_equals('ACTIVE', (string) ($readBack['user_status'] ?? ''))
            || (int) ($readBack['user_failed_login_count'] ?? -1) !== 0
            || $db->HasFailedTrans()) {
            throw new RuntimeException('The administrator password transaction read-back failed.');
        }

        if ($db->CompleteTrans() === false) {
            throw new RuntimeException('The administrator password transaction commit failed.');
        }

        $postCommit = $db->GetRow(
            'SELECT user_password_hash, user_status, user_failed_login_count FROM builder_user WHERE user_key = ?',
            [$userKey]
        );
        if (!is_array($postCommit)
            || !password_verify($password, (string) ($postCommit['user_password_hash'] ?? ''))
            || !hash_equals('ACTIVE', (string) ($postCommit['user_status'] ?? ''))
            || (int) ($postCommit['user_failed_login_count'] ?? -1) !== 0) {
            throw new RuntimeException('The administrator password durable read-back failed.');
        }
    } catch (Throwable $error) {
        if ($db->transCnt > 0) {
            $db->FailTrans();
            $db->CompleteTrans();
        }
        throw $error;
    } finally {
        unset($newHash);
    }

    unset($password, $confirmation);
    fwrite(STDOUT, "Administrator password reset and verified.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Administrator password recovery failed: " . $error->getMessage() . PHP_EOL);
    exit(1);
}
