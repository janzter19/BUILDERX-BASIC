<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

$user = bx_current_user();
if ($user === null || !bx_is_admin($user)) {
    http_response_code(403);
    exit;
}

$attachmentKey = strtolower(trim((string) ($_GET['attachment_key'] ?? '')));
if (preg_match('/^[0-9a-f-]{36}$/', $attachmentKey) !== 1) {
    http_response_code(404);
    exit;
}

$row = bx_db()->GetRow('SELECT mime_type, byte_size, storage_path, sha256 FROM phase_builder_todo_chat_attachments WHERE attachment_key = ? AND attachment_status = ? LIMIT 1', [$attachmentKey, 'ACTIVE']);
if (!is_array($row)) {
    http_response_code(404);
    exit;
}

$projectRoot = realpath(dirname(__DIR__));
$relativePath = ltrim(str_replace('\\', '/', (string) ($row['storage_path'] ?? '')), '/');
$allowedPrefix = '_Document/attachments/';
$absolutePath = is_string($projectRoot) ? realpath($projectRoot . '/' . $relativePath) : false;
$normalizedRoot = is_string($projectRoot) ? rtrim(str_replace('\\', '/', $projectRoot), '/') : '';
$normalizedPath = is_string($absolutePath) ? str_replace('\\', '/', $absolutePath) : '';
if ($normalizedRoot === '' || !str_starts_with($relativePath, $allowedPrefix) || $normalizedPath === '' || !str_starts_with($normalizedPath, $normalizedRoot . '/' . $allowedPrefix) || !is_file($normalizedPath)) {
    http_response_code(404);
    exit;
}

$bytes = filesize($normalizedPath);
$sha256 = hash_file('sha256', $normalizedPath);
if ($bytes === false || $bytes !== (int) ($row['byte_size'] ?? -1) || !is_string($sha256) || !hash_equals((string) ($row['sha256'] ?? ''), $sha256)) {
    http_response_code(409);
    exit;
}

header('Content-Type: ' . (string) $row['mime_type']);
header('Content-Length: ' . $bytes);
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($normalizedPath);
