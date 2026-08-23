<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/phases/index.php');

foreach ([
    '$previousReviewRows = $db->GetAll(',
    'ORDER BY r.x_id DESC LIMIT 10',
    '$previousReviewRunKeys = [];',
    '$seenPreviousFindings = [];',
    "'source_run_keys' => \$previousReviewRunKeys",
    '\\BuilderX\\AI\\PhaseBuilderPlanningPolicy::blockingCodes()',
    "'phase_builder_policy' => \\BuilderX\\AI\\PhaseBuilderPlanningPolicy::context('system_architecture')",
    'Correct these unresolved baseline blockers only.',
    'when_previous_integration_review_is_present_correct_only_the_supplied_baseline_blockers',
    'smallest coherent System Architecture guide',
] as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('The System Architecture refinement lookup is missing marker: ' . $marker);
    }
}

foreach ([
    'map_every_saved_requirement_to_at_least_one_owned_implementation_checklist_item_and_one_file_manifest_entry',
    'Correct every accumulated finding in the new architecture contract',
    'count($previousFindings) >= 100',
] as $prohibited) {
    if (str_contains($source, $prohibited)) {
        throw new RuntimeException('System Architecture still contains exhaustive refinement behavior: ' . $prohibited);
    }
}

echo json_encode([
    'latest_valid_blocked_review_selected' => true,
    'interrupted_newer_run_skipped' => true,
    'concrete_findings_required' => true,
    'only_finite_baseline_blockers_retained' => true,
    'optional_review_findings_not_accumulated' => true,
    'minimum_guide_policy_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
