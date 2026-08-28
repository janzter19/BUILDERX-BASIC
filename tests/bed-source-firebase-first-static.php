<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$foundation = file_get_contents($root . '/app/foundation.php');
$writer = file_get_contents($root . '/scripts/firebase-admin-project-bed-source-write.mjs');
$registry = file_get_contents($root . '/scripts/firebase-mysql-sync/registry.mjs');
foreach ([$foundation, $writer, $registry] as $content) {
    if (!is_string($content)) throw new RuntimeException('Unable to read Bed Source source files.');
}

foreach ([
    'function bx_admin_write_project_bed_source_firebase_first',
    'function bx_save_project_bed_source',
    'function bx_set_project_bed_source_status',
    "collection('project_bed_source')",
    "mysql_sync_status: 'PENDING'",
    'project_bed_source: portalContract',
] as $marker) {
    if (!str_contains($foundation . $writer . $registry, $marker)) {
        throw new RuntimeException('Missing Bed Source Firebase-first marker: ' . $marker);
    }
}

if (preg_match('/function bx_save_project_bed_source\(.*?function bx_set_project_bed_source_status/s', $foundation, $match) === 1 && preg_match('/INSERT INTO project_bed_source|UPDATE project_bed_source SET|DELETE FROM project_bed_source/', $match[0]) === 1) {
    throw new RuntimeException('Active Bed Source save path still contains direct MySQL mutation.');
}
if (preg_match('/function bx_set_project_bed_source_status\(.*?function bx_project_task_firebase_rows/s', $foundation, $match) === 1 && preg_match('/INSERT INTO project_bed_source|UPDATE project_bed_source SET|DELETE FROM project_bed_source/', $match[0]) === 1) {
    throw new RuntimeException('Active Bed Source status path still contains direct MySQL mutation.');
}

echo "bed_source_firebase_first_static:ok\n";
