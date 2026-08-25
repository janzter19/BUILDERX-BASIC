<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/foundation.php';

$limit = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 100;
bx_ensure_project_messenger_schema();

$rows = bx_db()->GetAll(
    "SELECT chat_key, group_key
    FROM project_messenger_chat
    WHERE firebase_sync_status IN ('PENDING','FAILED')
    ORDER BY x_id ASC
    LIMIT {$limit}"
) ?: [];

$summary = [
    'ok' => true,
    'attempted' => 0,
    'synced' => 0,
    'failed' => 0,
    'skipped' => 0,
    'items' => [],
];

foreach ($rows as $row) {
    $chatKey = (string) ($row['chat_key'] ?? '');
    $groupKey = (string) ($row['group_key'] ?? '');
    if ($chatKey === '' || $groupKey === '') {
        continue;
    }

    $messages = bx_messenger_messages($groupKey, 100);
    $message = null;
    foreach ($messages as $candidate) {
        if ((string) ($candidate['chat_key'] ?? '') === $chatKey) {
            $message = $candidate;
            break;
        }
    }
    if ($message === null) {
        $summary['failed']++;
        $summary['items'][] = ['chat_key' => $chatKey, 'ok' => false, 'message' => 'message_readback_missing'];
        continue;
    }

    $summary['attempted']++;
    $result = bx_messenger_sync_message_to_firebase($message);
    if (($result['ok'] ?? false) === true) {
        $summary['synced']++;
    } elseif (($result['skipped'] ?? false) === true) {
        $summary['skipped']++;
    } else {
        $summary['failed']++;
    }
    $summary['items'][] = [
        'chat_key' => $chatKey,
        'ok' => (bool) ($result['ok'] ?? false),
        'skipped' => (bool) ($result['skipped'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
    ];
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
