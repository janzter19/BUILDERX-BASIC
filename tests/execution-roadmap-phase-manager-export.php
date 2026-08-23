<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/phase-roadmap-export.php';

$roadmap = [
    'schemaVersion' => 'builderx.execution-roadmap.v3',
    'contractType' => 'builderx.execution-roadmap',
    'phases' => [
        [
            'phaseId' => 'PHASE-01',
            'phaseTitle' => 'Inventory foundation',
            'phaseDescription' => 'Create the initial inventory boundary.',
            'tasks' => [
                [
                    'taskId' => 'PHASE-01-TASK-01',
                    'taskTitle' => 'Create inventory schema',
                    'taskDescription' => 'Create products and categories.',
                    'track' => 'shared',
                    'subTasks' => [[
                        'subtaskId' => 'PHASE-01-TASK-01-SUBTASK-01',
                        'todos' => [
                            ['todoId' => 'PHASE-01-TASK-01-SUBTASK-01-TODO-01'],
                            ['todoId' => 'PHASE-01-TASK-01-SUBTASK-01-TODO-02'],
                        ],
                    ]],
                ],
                [
                    'taskId' => 'PHASE-01-TASK-02',
                    'taskTitle' => 'Render inventory catalog',
                    'taskDescription' => 'Show safe product fields.',
                    'track' => 'web',
                    'subTasks' => [],
                ],
            ],
        ],
        [
            'phaseId' => 'PHASE-02',
            'phaseTitle' => 'Not selected',
            'tasks' => [[
                'taskId' => 'PHASE-02-TASK-01',
                'taskTitle' => 'Must not export',
                'taskDescription' => 'Excluded by selection.',
                'track' => 'android',
                'subTasks' => [],
            ]],
        ],
    ],
];

$items = bx_phase_roadmap_export_items($roadmap, ['PHASE-01']);
if (count($items) !== 2) {
    throw new RuntimeException('The v3 export normalizer did not return the selected phase tasks.');
}
if (
    ($items[0]['roadmap_task_id'] ?? '') !== 'PHASE-01-TASK-01'
    || ($items[0]['track'] ?? '') !== 'shared'
    || (int) ($items[0]['subtask_count'] ?? -1) !== 1
    || (int) ($items[0]['todo_count'] ?? -1) !== 2
    || !is_array($items[0]['roadmap_task'] ?? null)
) {
    throw new RuntimeException('The v3 export normalizer did not preserve the hierarchy and track metadata.');
}

$changedRoadmap = $roadmap;
$changedRoadmap['phases'][0]['tasks'][0]['taskDescription'] = 'A refined description must update the existing export.';
$changedItems = bx_phase_roadmap_export_items($changedRoadmap, ['PHASE-01']);
if (!hash_equals((string) $items[0]['identity_fingerprint'], (string) $changedItems[0]['identity_fingerprint'])) {
    throw new RuntimeException('The export task identity changed when only task content changed.');
}

$legacyItems = bx_phase_roadmap_export_items([
    'schemaVersion' => 'builderx.execution-roadmap.v1',
    'phases' => [[
        'phaseId' => 'LEGACY-01',
        'phaseName' => 'Legacy phase',
        'webTrackTasks' => ['Build the web route.'],
        'androidTrackTasks' => ['Build the mobile screen.'],
    ]],
], ['LEGACY-01']);
if (count($legacyItems) !== 2 || ($legacyItems[0]['track'] ?? '') !== 'web' || ($legacyItems[1]['track'] ?? '') !== 'android') {
    throw new RuntimeException('Legacy roadmap export compatibility regressed.');
}

$duplicateRejected = false;
$duplicateRoadmap = $roadmap;
$duplicateRoadmap['phases'][0]['tasks'][] = $duplicateRoadmap['phases'][0]['tasks'][0];
try {
    bx_phase_roadmap_export_items($duplicateRoadmap, ['PHASE-01']);
} catch (RuntimeException $error) {
    $duplicateRejected = str_contains($error->getMessage(), 'duplicate task identity');
}
if (!$duplicateRejected) {
    throw new RuntimeException('Duplicate current-roadmap task identities were not rejected.');
}

$phaseSource = file_get_contents(__DIR__ . '/../phases/index.php');
if (!is_string($phaseSource)) {
    throw new RuntimeException('The Phase route source could not be read.');
}
$start = strpos($phaseSource, "if (\$action === 'export_execution_roadmap_to_phase_manager')");
$end = strpos($phaseSource, "if (\$action === 'create_phase')", $start === false ? 0 : $start);
if ($start === false || $end === false || $end <= $start) {
    throw new RuntimeException('The Execution Roadmap export route could not be isolated.');
}
$exportSource = substr($phaseSource, $start, $end - $start);
foreach ([
    'bx_phase_roadmap_export_items',
    'BeginTrans() === false',
    'FOR UPDATE',
    'identity_fingerprint',
    "bx_audit('EXPORT', 'builder_phase_task'",
    'direct pre-commit',
    'CommitTrans() === false',
    'durable post-commit read-back',
    'RollbackTrans()',
] as $marker) {
    if (!str_contains($exportSource, $marker)) {
        throw new RuntimeException('The export route is missing the required persistence marker: ' . $marker . '.');
    }
}

echo json_encode([
    'v3_tasks_normalized' => true,
    'legacy_tasks_normalized' => true,
    'stable_identity_verified' => true,
    'duplicate_identity_rejected' => true,
    'checked_transaction_markers_verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
