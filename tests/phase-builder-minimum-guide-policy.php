<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

use BuilderX\AI\PhaseBuilderPlanningPolicy;

$workflows = ['narrative_cleanup', 'requirements_analysis', 'system_architecture', 'ui_ux_design', 'execution_roadmap'];
foreach ($workflows as $workflow) {
    if (!PhaseBuilderPlanningPolicy::appliesTo($workflow)) {
        throw new RuntimeException('The minimum-guide policy is missing workflow ' . $workflow . '.');
    }
    $context = PhaseBuilderPlanningPolicy::context($workflow);
    if (($context['schemaVersion'] ?? '') !== PhaseBuilderPlanningPolicy::VERSION || !is_array($context['blockingPolicy']['allowedCodes'] ?? null)) {
        throw new RuntimeException('The minimum-guide context is incomplete for ' . $workflow . '.');
    }
}

$optional = PhaseBuilderPlanningPolicy::normalizeReview('system_architecture', 'blocked', [[
    'code' => 'OPTIONAL_OBSERVABILITY_DETAIL',
    'summary' => 'Consider adding deeper metrics during implementation. This is not required now.',
    'relatedArea' => 'Operations',
]]);
if (($optional['status'] ?? '') !== 'approved' || ($optional['findings'] ?? null) !== [] || count($optional['suggestions'] ?? []) !== 1) {
    throw new RuntimeException('Optional findings must approve the minimum guide and become suggestions.');
}

$blocking = PhaseBuilderPlanningPolicy::normalizeReview('system_architecture', 'blocked', [[
    'code' => 'REQUIRED_DATA_WITHOUT_STORAGE',
    'summary' => 'The confirmed patient record has no persistence destination.',
    'requiredResolution' => 'Declare the owning persistence component.',
]]);
if (($blocking['status'] ?? '') !== 'blocked' || count($blocking['findings'] ?? []) !== 1 || ($blocking['suggestions'] ?? null) !== []) {
    throw new RuntimeException('A finite baseline defect must remain blocking.');
}

$source = (string) file_get_contents(dirname(__DIR__) . '/phases/index.php');
$frontend = (string) file_get_contents(dirname(__DIR__) . '/frontend/src/App.tsx');
$transport = (string) file_get_contents(dirname(__DIR__) . '/app/AI/PhaseAiDatabaseTransport.php');
foreach ([
    'smallest coherent System Architecture guide',
    'smallest practical starter roadmap',
    "count(\$roadmap['phases']) < 1",
    "PhaseBuilderPlanningPolicy::context('execution_roadmap')",
] as $marker) {
    if (!str_contains($source, $marker)) throw new RuntimeException('The server minimum-guide marker is missing: ' . $marker);
}
foreach ([
    'smallest coherent architecture guide',
    'smallest practical starter roadmap',
    'non-blocking coding-time suggestions',
    'catalog.modules.length < 1',
    'canonicalStageResult = persistentPhaseAiStage(completed.run, options.stageKey)?.result',
] as $marker) {
    if (!str_contains($frontend, $marker)) throw new RuntimeException('The client minimum-guide marker is missing: ' . $marker);
}
foreach (['5 to 9 milestones', 'catalog.modules.length < 2', 'map every requirement to at least one owned implementationChecklist item'] as $prohibited) {
    if (str_contains($source, $prohibited) || str_contains($frontend, $prohibited)) {
        throw new RuntimeException('An over-engineering requirement remains: ' . $prohibited);
    }
}
foreach (['BUILDERX_INTEGRATION_REVIEW_RESULT_CONTRACT', 'Do not rename findings to blockingFindings or nonBlockingSuggestions.'] as $marker) {
    if (!str_contains($transport, $marker)) throw new RuntimeException('The integration-review transport contract is missing: ' . $marker);
}

echo json_encode([
    'schema' => PhaseBuilderPlanningPolicy::VERSION,
    'all_phase_builder_workflows_covered' => true,
    'optional_findings_are_non_blocking' => true,
    'finite_baseline_defects_still_block' => true,
    'integration_review_shape_explicit' => true,
    'roadmap_minimums_reduced' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
