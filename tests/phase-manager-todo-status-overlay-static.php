<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$phaseRoute = file_get_contents($root . '/phases/index.php');
$frontend = file_get_contents($root . '/frontend/src/App.tsx');

foreach ([
    'phases/index.php' => $phaseRoute,
    'frontend/src/App.tsx' => $frontend,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$serverRequirements = [
    'phaseBuilderTodoExecutionStatus',
    'phase_builder_todo_execution_logs',
    'MAX(x_id) AS max_x_id',
    'GROUP BY phase_id, task_id, subtask_id, todo_id',
    '$phaseBuilderTodoExecutionStatus[$statusKey] = $todoStatusRow',
];
foreach ($serverRequirements as $needle) {
    if (!str_contains($phaseRoute, $needle)) {
        throw new RuntimeException('Phase Manager route is missing latest todo status payload support: ' . $needle);
    }
}

$frontendRequirements = [
    'roadmapTodoExecutionStatus',
    'roadmapTodoStatusKey',
    'executionStatusLabel',
    'latestRoadmapTodoExecution',
    'roadmapTaskStatus',
    'todoDisplayStatus(todo, selectedPhaseId, selectedGeneratedTask.taskId, subTask.subtaskId)',
    'todoDisplayStatus(selectedGeneratedTodo.todo, selectedPhaseId, selectedGeneratedTask?.taskId || \'\', selectedGeneratedTodo.subTask.subtaskId)',
];
foreach ($frontendRequirements as $needle) {
    if (!str_contains($frontend, $needle)) {
        throw new RuntimeException('Phase Manager frontend is missing latest todo status overlay wiring: ' . $needle);
    }
}

echo json_encode([
    'latest_execution_status_payload' => true,
    'todo_badges_overlay_execution_logs' => true,
    'phase_status_counts_overlay_execution_logs' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
