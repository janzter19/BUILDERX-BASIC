<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/app/foundation.php');
if (!is_string($source)) {
    throw new RuntimeException('Portal foundation source is unreadable.');
}

if (!preg_match('/function bx_ensure_portal_authoritative_projection_schema\(\): void\n\{(?<body>.*?)\n\}\n\nfunction bx_phase_builder_current_draft_key/s', $source, $match)) {
    throw new RuntimeException('Authoritative Portal schema function is missing.');
}
$body = $match['body'];

foreach (['CREATE TABLE IF NOT EXISTS project_group', 'CREATE TABLE IF NOT EXISTS project_position',
    'CREATE TABLE project_user_group', 'firebase_uid', 'password_change_required',
    'user_disabled_at', 'firebase_collection', 'mysql_sync_status',
    'PRIMARY KEY (group_key)', 'PRIMARY KEY (position_key)',
    'PRIMARY KEY (assignment_key)',
    'uq_project_user_group_assignment', 'idx_project_user_group_assignment_group_user',
    'uq_project_user_firebase_uid', 'idx_project_user_sync_status'] as $marker) {
    if (!str_contains($body, $marker)) {
        throw new RuntimeException('Missing Portal schema marker: ' . $marker);
    }
}

if (!str_contains($source, 'user_password_hash VARCHAR(255) NULL')) {
    throw new RuntimeException('Nullable Firebase-compatible project_user password column is missing.');
}

$orderedFields = [
    'group_key VARCHAR(255)', 'project_key VARCHAR(255)', 'group_name VARCHAR(120)',
    'firebase_collection VARCHAR(80)', 'mysql_created_at DATETIME(6)',
    'mysql_sync_status ENUM', "\n        created_at DATETIME(6)", "\n        updated_at DATETIME(6)",
];
$last = -1;
foreach ($orderedFields as $field) {
    $offset = strpos($body, $field);
    if ($offset === false || $offset <= $last) {
        throw new RuntimeException('Canonical project_group field order is invalid at: ' . $field);
    }
    $last = $offset;
}

foreach (['DROP TABLE', 'INSERT INTO', 'UPDATE project_user', 'MODIFY user_password_hash',
    'user_created_by_key', 'user_updated_by_key', 'user_deleted_by_key'] as $forbidden) {
    if (str_contains($body, $forbidden)) {
        throw new RuntimeException('Unsafe Portal schema operation found: ' . $forbidden);
    }
}

if (!str_contains($source, 'bx_ensure_portal_authoritative_projection_schema();')) {
    throw new RuntimeException('Portal schema bootstrap call is missing.');
}

$docs = file_get_contents($root . '/docs/project/portal-authoritative-projection-schema.md');
foreach (['Firebase Auth', 'backup-verified', 'password hash is now nullable', 'obsolete audit-owner columns', 'rename, drop, copy, or backfill rows'] as $marker) {
    if (!is_string($docs) || !str_contains($docs, $marker)) {
        throw new RuntimeException('Missing Portal migration gate documentation: ' . $marker);
    }
}

echo "portal authoritative projection schema static checks passed\n";
