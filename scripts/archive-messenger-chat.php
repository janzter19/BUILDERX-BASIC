<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/foundation.php';

$hotWindow = isset($argv[1]) ? max(20, min(100, (int) $argv[1])) : 50;
$limit = isset($argv[2]) ? max(1, min(1000, (int) $argv[2])) : 500;
$prune = in_array('--prune', $argv, true);

bx_ensure_project_messenger_schema();
$db = bx_db();
$conversationRows = $db->GetAll(
    "SELECT DISTINCT project_key, group_key, conversation_type, COALESCE(direct_recipient_user_key, '') AS direct_recipient_user_key
     FROM project_messenger_chat ORDER BY project_key, group_key, conversation_type, direct_recipient_user_key"
) ?: [];

$summary = ['ok' => true, 'hot_window' => $hotWindow, 'candidate_limit' => $limit, 'archived' => 0, 'pruned' => 0, 'errors' => []];
$remaining = $limit;

foreach ($conversationRows as $conversation) {
    if ($remaining < 1) break;
    $projectKey = (string) ($conversation['project_key'] ?? '');
    $groupKey = (string) ($conversation['group_key'] ?? '');
    $conversationType = (string) ($conversation['conversation_type'] ?? 'group');
    $directRecipient = (string) ($conversation['direct_recipient_user_key'] ?? '');
    if ($projectKey === '' || $groupKey === '') continue;

    $rows = $db->GetAll(
        "SELECT x_id, chat_key, project_key, group_key, conversation_type, direct_recipient_user_key,
            reply_to_chat_key, sender_user_key, sender_name, message_text, message_type, message_status,
            removed_at, removed_by_user_key, created_at, updated_at
         FROM project_messenger_chat
         WHERE project_key = ? AND group_key = ? AND conversation_type = ?
           AND COALESCE(direct_recipient_user_key, '') = ?
         ORDER BY x_id DESC LIMIT {$hotWindow}, {$remaining}",
        [$projectKey, $groupKey, $conversationType, $directRecipient]
    ) ?: [];

    foreach ($rows as $row) {
        $chatKey = trim((string) ($row['chat_key'] ?? ''));
        if ($chatKey === '') continue;
        $saved = $db->Execute(
            "INSERT INTO project_messenger_chat_log (
                log_key, chat_key, project_key, group_key, conversation_type, direct_recipient_user_key,
                reply_to_chat_key, sender_user_key, sender_name, message_text, message_type, message_status,
                removed_at, removed_by_user_key, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                message_text = VALUES(message_text), message_status = VALUES(message_status),
                removed_at = VALUES(removed_at), removed_by_user_key = VALUES(removed_by_user_key),
                updated_at = VALUES(updated_at)",
            [
                bx_unique_firebase_document_key('project_messenger_chat_log', 'log_key'),
                $chatKey,
                (string) ($row['project_key'] ?? ''),
                (string) ($row['group_key'] ?? ''),
                (string) ($row['conversation_type'] ?? 'group'),
                (string) ($row['direct_recipient_user_key'] ?? '') !== '' ? (string) $row['direct_recipient_user_key'] : null,
                (string) ($row['reply_to_chat_key'] ?? '') !== '' ? (string) $row['reply_to_chat_key'] : null,
                (string) ($row['sender_user_key'] ?? ''),
                (string) ($row['sender_name'] ?? ''),
                (string) ($row['message_text'] ?? ''),
                (string) ($row['message_type'] ?? 'text'),
                (string) ($row['message_status'] ?? 'ACTIVE'),
                $row['removed_at'] ?: null,
                (string) ($row['removed_by_user_key'] ?? '') !== '' ? (string) $row['removed_by_user_key'] : null,
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
            ]
        );
        if ($saved === false) {
            $summary['errors'][] = ['chat_key' => $chatKey, 'message' => trim((string) $db->ErrorMsg())];
            continue;
        }

        $summary['archived']++;
        $remaining--;
        if ($prune) {
            $deleted = $db->Execute('DELETE FROM project_messenger_chat WHERE chat_key = ?', [$chatKey]);
            if ($deleted === false) {
                $summary['errors'][] = ['chat_key' => $chatKey, 'message' => trim((string) $db->ErrorMsg())];
            } else {
                $summary['pruned']++;
            }
        }
        if ($remaining < 1) break 2;
    }
}

$summary['ok'] = $summary['errors'] === [];
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
