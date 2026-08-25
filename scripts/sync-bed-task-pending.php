<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/foundation.php';

$limit = isset($argv[1]) ? max(1, min(500, (int) $argv[1])) : 100;
bx_ensure_project_bed_task_schema();

$rows = bx_db()->GetAll(
    "SELECT bed_task_key
    FROM project_bed_task
    WHERE firebase_sync_status IN ('PENDING','FAILED')
    ORDER BY x_id ASC
    LIMIT {$limit}"
) ?: [];

$summary = [
    'ok' => true,
    'attempted' => 0,
    'synced' => 0,
    'failed' => 0,
    'items' => [],
];

foreach ($rows as $row) {
    $bedTaskKey = (string) ($row['bed_task_key'] ?? '');
    if ($bedTaskKey === '') {
        continue;
    }

    $summary['attempted']++;
    $result = bx_sync_project_bed_task_to_firebase($bedTaskKey);
    if (($result['ok'] ?? false) === true) {
        $summary['synced']++;
    } else {
        $summary['failed']++;
    }
    $summary['items'][] = [
        'bed_task_key' => $bedTaskKey,
        'ok' => (bool) ($result['ok'] ?? false),
        'message' => (string) ($result['message'] ?? ''),
    ];
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
