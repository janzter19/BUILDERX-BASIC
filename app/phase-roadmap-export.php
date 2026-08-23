<?php
declare(strict_types=1);

/**
 * Normalize legacy and current Execution Roadmap phases into Phase Manager tasks.
 *
 * The returned identity fingerprint is intentionally independent of the roadmap
 * content hash. Regenerating a roadmap can therefore update the same exported
 * task without duplicating it, while roadmap_hash still records the exact source
 * version used for the latest write.
 *
 * @param array<string, mixed> $roadmap
 * @param list<string> $selectedPhaseIds
 * @return list<array<string, mixed>>
 */
function bx_phase_roadmap_export_items(array $roadmap, array $selectedPhaseIds): array
{
    $schemaVersion = trim((string) ($roadmap['schemaVersion'] ?? ''));
    if (!in_array($schemaVersion, [
        'builderx.execution-roadmap.v1',
        'builderx.execution-roadmap.v2',
        'builderx.execution-roadmap.v3',
    ], true) || !is_array($roadmap['phases'] ?? null)) {
        throw new RuntimeException('The Execution Roadmap uses an unsupported export contract.');
    }

    $selectedLookup = array_fill_keys($selectedPhaseIds, true);
    $items = [];
    foreach ($roadmap['phases'] as $phaseIndex => $roadmapPhase) {
        if (!is_array($roadmapPhase)) {
            continue;
        }
        $roadmapPhaseId = trim((string) ($roadmapPhase['phaseId'] ?? $roadmapPhase['phase_id'] ?? ''));
        if ($roadmapPhaseId === '' || !isset($selectedLookup[$roadmapPhaseId])) {
            continue;
        }
        $phaseName = trim((string) (
            $roadmapPhase['phaseTitle']
            ?? $roadmapPhase['phaseName']
            ?? $roadmapPhase['phase_title']
            ?? $roadmapPhase['phase_name']
            ?? 'Milestone ' . ((int) $phaseIndex + 1)
        ));
        if ($phaseName === '') {
            $phaseName = 'Milestone ' . ((int) $phaseIndex + 1);
        }

        if (array_key_exists('tasks', $roadmapPhase)) {
            if (!is_array($roadmapPhase['tasks'])) {
                throw new RuntimeException('Every selected current-roadmap phase must contain a task list.');
            }
            foreach ($roadmapPhase['tasks'] as $taskIndex => $rawTask) {
                if (!is_array($rawTask)) {
                    throw new RuntimeException('Every current-roadmap task must be a JSON object.');
                }
                $taskId = trim((string) ($rawTask['taskId'] ?? ''));
                $taskTitle = trim((string) ($rawTask['taskTitle'] ?? ''));
                $taskDescription = trim((string) ($rawTask['taskDescription'] ?? ''));
                $track = trim(strtolower((string) ($rawTask['track'] ?? 'shared')));
                if ($taskId === '' || $taskTitle === '' || !in_array($track, ['web', 'android', 'shared'], true)) {
                    throw new RuntimeException('Every current-roadmap task requires an ID, title, and web, android, or shared track.');
                }
                if (strlen($taskTitle) > 10000 || strlen($taskDescription) > 100000) {
                    throw new RuntimeException('A current-roadmap task exceeds the Phase Manager export limit.');
                }
                $subTasks = is_array($rawTask['subTasks'] ?? null) ? $rawTask['subTasks'] : [];
                $todoCount = 0;
                foreach ($subTasks as $subTask) {
                    if (is_array($subTask) && is_array($subTask['todos'] ?? null)) {
                        $todoCount += count($subTask['todos']);
                    }
                }
                $identityFingerprint = hash('sha256', implode('|', [
                    'execution_roadmap_v2',
                    $roadmapPhaseId,
                    $track,
                    $taskId,
                ]));
                $items[] = [
                    'phase_index' => (int) $phaseIndex,
                    'roadmap_phase_id' => $roadmapPhaseId,
                    'phase_name' => $phaseName,
                    'track' => $track,
                    'track_label' => $track === 'web' ? 'Web' : ($track === 'android' ? 'Mobile' : 'Shared'),
                    'task_index' => (int) $taskIndex,
                    'roadmap_task_id' => $taskId,
                    'task_title' => $taskTitle,
                    'task_description' => $taskDescription,
                    'subtask_count' => count($subTasks),
                    'todo_count' => $todoCount,
                    'identity_fingerprint' => $identityFingerprint,
                    'roadmap_task' => $rawTask,
                ];
            }
            continue;
        }

        foreach ([
            'web' => $roadmapPhase['webTrackTasks'] ?? [],
            'android' => $roadmapPhase['androidTrackTasks'] ?? [],
        ] as $track => $trackTasks) {
            if (!is_array($trackTasks)) {
                throw new RuntimeException('Every selected legacy-roadmap track must contain a task list.');
            }
            foreach ($trackTasks as $taskIndex => $rawTask) {
                $taskDescription = trim((string) $rawTask);
                if ($taskDescription === '' || strlen($taskDescription) > 10000) {
                    throw new RuntimeException('Every legacy-roadmap task must contain non-empty text of 10,000 characters or fewer.');
                }
                $taskId = $roadmapPhaseId . '-' . strtoupper($track) . '-TASK-' . str_pad((string) ((int) $taskIndex + 1), 2, '0', STR_PAD_LEFT);
                $identityFingerprint = hash('sha256', implode('|', [
                    'execution_roadmap_v2',
                    $roadmapPhaseId,
                    $track,
                    $taskId,
                ]));
                $items[] = [
                    'phase_index' => (int) $phaseIndex,
                    'roadmap_phase_id' => $roadmapPhaseId,
                    'phase_name' => $phaseName,
                    'track' => $track,
                    'track_label' => $track === 'web' ? 'Web' : 'Mobile',
                    'task_index' => (int) $taskIndex,
                    'roadmap_task_id' => $taskId,
                    'task_title' => $taskDescription,
                    'task_description' => $taskDescription,
                    'subtask_count' => 0,
                    'todo_count' => 0,
                    'identity_fingerprint' => $identityFingerprint,
                    'roadmap_task' => $rawTask,
                ];
            }
        }
    }

    $seenFingerprints = [];
    foreach ($items as $item) {
        $fingerprint = (string) ($item['identity_fingerprint'] ?? '');
        if ($fingerprint === '' || isset($seenFingerprints[$fingerprint])) {
            throw new RuntimeException('The selected roadmap contains a duplicate task identity.');
        }
        $seenFingerprints[$fingerprint] = true;
    }

    return $items;
}
