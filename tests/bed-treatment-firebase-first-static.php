<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$foundation = file_get_contents($root . '/app/foundation.php');
$writer = file_get_contents($root . '/scripts/firebase-admin-project-bed-treatment-write.mjs');
$registry = file_get_contents($root . '/scripts/firebase-mysql-sync/registry.mjs');
foreach ([$foundation, $writer, $registry] as $content) {
    if (!is_string($content)) throw new RuntimeException('Unable to read Bed Treatment source files.');
}

foreach ([
    'function bx_admin_write_project_bed_treatment_firebase_first',
    'function bx_save_project_bed_treatment',
    'function bx_set_project_bed_treatment_status',
    "collection = db.collection('project_bed_treatment')",
    "mysql_sync_status: 'PENDING'",
    'project_bed_treatment: portalContract',
] as $marker) {
    if (!str_contains($foundation . $writer . $registry, $marker)) {
        throw new RuntimeException('Missing Bed Treatment Firebase-first marker: ' . $marker);
    }
}

if (preg_match('/function bx_save_project_bed_treatment\(.*?function bx_set_project_bed_treatment_status/s', $foundation, $match) === 1 && preg_match('/INSERT INTO project_bed_treatment|UPDATE project_bed_treatment SET|DELETE FROM project_bed_treatment/', $match[0]) === 1) {
    throw new RuntimeException('Active Bed Treatment save path still contains direct MySQL mutation.');
}
if (preg_match('/function bx_set_project_bed_treatment_status\(.*?function bx_legacy_save_project_bed_source/s', $foundation, $match) === 1 && preg_match('/INSERT INTO project_bed_treatment|UPDATE project_bed_treatment SET|DELETE FROM project_bed_treatment/', $match[0]) === 1) {
    throw new RuntimeException('Active Bed Treatment status path still contains direct MySQL mutation.');
}

echo "bed_treatment_firebase_first_static:ok\n";
