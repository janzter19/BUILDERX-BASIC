<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    'scripts/firebase-mysql-sync-master.mjs',
    'scripts/firebase-mysql-sync/config.mjs',
    'scripts/firebase-mysql-sync/registry.mjs',
    'scripts/firebase-mysql-sync/types.mjs',
    'scripts/firebase-mysql-sync/queue-store.mjs',
    'scripts/firebase-mysql-sync/schema-parity.mjs',
    'scripts/firebase-mysql-sync/backup.mjs',
    'scripts/firebase-mysql-sync/worker.mjs',
    'scripts/firebase-mysql-sync/acknowledger.mjs',
    'scripts/firebase-mysql-sync/telemetry.mjs',
    'deploy/systemd/rbmsv4-firebase-mysql-sync.service',
    'docs/project/firebase-mysql-sync-master.md',
];
foreach ($required as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Required inbound sync file missing: ' . $file);
    }
}
$source = '';
foreach (glob($root . '/scripts/firebase-mysql-sync/*.mjs') ?: [] as $file) {
    $source .= file_get_contents($file) . "\n";
}
$source .= file_get_contents($root . '/scripts/firebase-mysql-sync-master.mjs');
$rules = file_get_contents($root . '/firestore.rules');
foreach (['phases/', 'config.local.php', 'test.js', '.slice(', 'toMillis(', 'SUBSTRING(', 'TRUNCATE ', 'DROP TABLE', 'project_bed_task_summary'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        throw new RuntimeException('Forbidden inbound sync source marker: ' . $forbidden);
    }
}
foreach (['project_bed_task', 'project_bed_task_log', 'project_messenger_chat', 'project_messenger_chat_attachment', 'project_messenger_chat_reaction', 'project_group', 'project_position', 'project_user_group', 'project_user', 'project_user_login_history', 'firebase_created_at', 'firebase_updated_at', 'user_last_login_ip_address', 'user_last_logout_device', 'authIdentityField', 'firestore_auth_uid_document_id_mismatch', 'firestore_user_disabled_or_deleted', 'strictFields', 'requirePending', 'ACK_PENDING', 'SUPERSEDED', 'DEAD_LETTER', 'firebase_mysql_sync_projection_state', 'GET_LOCK', 'RELEASE_LOCK', 'STRICT_ALL_TABLES', 'useBigInt: true', 'export function isMainModule', 'realpathSync', 'if (isMainModule()) runService()'] as $requiredMarker) {
    if (!str_contains($source, $requiredMarker)) {
        throw new RuntimeException('Missing inbound sync source marker: ' . $requiredMarker);
    }
}
if (substr_count($rules, 'match /project_user/{userKey}') !== 1
    || !str_contains($rules, 'function canReadPortalProjectAssignment')
    || !str_contains($rules, "resourceData.assignment_status == 'ACTIVE'")
    || !str_contains($rules, 'match /project_user_group/{assignmentKey}')) {
    throw new RuntimeException('Portal Firestore rule contract is not strict or assignment-aware');
}
$prototype = $root . '/test.js';
if (!is_file($prototype) || hash_file('sha256', $prototype) !== 'ca4c6131100e0d08091d3a9b2cba81541af2773a9a758f83c8ffc305bd9f8f8c') {
    throw new RuntimeException('Working prototype test.js is missing or changed');
}
echo "firebase-mysql-sync static checks passed\n";
