<?php
declare(strict_types=1);

namespace BuilderX\AI;

/**
 * One server-owned policy for every Phase Builder Planning workflow.
 *
 * Phase Builder produces the smallest coherent implementation guide. Optional
 * improvements are retained as coding-time suggestions and cannot block the
 * handoff to Phase Manager.
 */
final class PhaseBuilderPlanningPolicy
{
    public const VERSION = 'builderx.phase-builder.minimum-guide.v1';

    /** @var list<string> */
    private const WORKFLOWS = [
        'narrative_cleanup',
        'requirements_analysis',
        'system_architecture',
        'ui_ux_design',
        'execution_roadmap',
    ];

    /** @var array<string, string> */
    private const BLOCKING_CODES = [
        'CONFIRMED_REQUIREMENT_OMITTED' => 'A confirmed required capability is completely absent.',
        'CONFIRMED_REQUIREMENTS_CONFLICT' => 'Two confirmed requirements cannot both be implemented as written.',
        'ESSENTIAL_USER_JOURNEY_BROKEN' => 'A confirmed primary user journey has no viable completion path.',
        'REQUIRED_INTEGRATION_UNUSABLE' => 'A confirmed required integration has no viable connection.',
        'CORE_DATA_OWNERSHIP_CONFLICT' => 'Essential persisted data has contradictory primary ownership.',
        'REQUIRED_REQUEST_WITHOUT_RESPONSE' => 'A confirmed required operation has no viable response path.',
        'REQUIRED_DATA_WITHOUT_STORAGE' => 'Confirmed persisted data has no storage destination.',
        'ESSENTIAL_SECURITY_BOUNDARY_MISSING' => 'A confirmed essential access boundary is completely absent.',
        'ESSENTIAL_DATA_INTEGRITY_GAP' => 'The baseline would permit corruption of confirmed essential data.',
    ];

    public static function appliesTo(string $workflowKey): bool
    {
        return in_array(strtolower(trim($workflowKey)), self::WORKFLOWS, true);
    }

    /** @return list<string> */
    public static function blockingCodes(): array
    {
        return array_keys(self::BLOCKING_CODES);
    }

    /** @return array<string, mixed> */
    public static function context(string $workflowKey): array
    {
        return [
            'schemaVersion' => self::VERSION,
            'workflowKey' => strtolower(trim($workflowKey)),
            'phaseBuilderRole' => 'Create a concise minimum viable implementation guide.',
            'phaseManagerRole' => 'Expand, revise, schedule, and implement details over time.',
            'rules' => [
                'use_only_explicitly_confirmed_user_scope',
                'choose_the_simplest_coherent_design',
                'do_not_invent_optional_features_services_roles_tables_apis_screens_or_infrastructure',
                'do_not_require_final_production_completeness',
                'preserve_essential_security_validation_and_data_integrity_requirements',
                'record_uncertainty_as_a_non_blocking_assumption_or_coding_time_suggestion',
                'refine_only_the_affected_sections_and_preserve_accepted_decisions',
                'approve_when_the_confirmed_minimum_foundation_is_coherent',
                'leave_detailed_decomposition_and_future_improvements_to_phase_manager',
            ],
            'blockingPolicy' => [
                'allowedCodes' => self::BLOCKING_CODES,
                'allOtherFindings' => 'Convert to a non-blocking coding-time suggestion.',
                'approvalRule' => 'Optional recommendations never prevent persistence or progression to Phase Manager.',
            ],
            'suggestionPolicy' => [
                'maximumSuggestions' => 8,
                'shape' => [
                    'title' => 'Short title',
                    'relatedArea' => 'Feature or module',
                    'reason' => 'One concise sentence',
                    'status' => 'non_blocking_coding_time',
                ],
                'rules' => [
                    'suggest_only_when_materially_useful',
                    'one_sentence_reason_only',
                    'do_not_expand_into_requirements_architecture_or_tasks',
                    'do_not_change_confirmed_scope',
                    'evaluate_during_related_phase_manager_coding_work',
                ],
            ],
        ];
    }

    public static function prompt(string $workflowKey): string
    {
        if (!self::appliesTo($workflowKey)) {
            return '';
        }
        return implode("\n", [
            'BUILDERX_PHASE_BUILDER_MINIMUM_GUIDE_POLICY',
            'Phase Builder is a concise starting guide. Phase Manager owns later expansion and coding detail.',
            'Use only confirmed user requirements and choose the simplest coherent design.',
            'Do not invent optional features, services, roles, tables, APIs, screens, infrastructure, or production hardening.',
            'Preserve essential security, validation, and data-integrity requirements that the user explicitly confirmed.',
            'A finding may block only when it uses one of the server supplied blockingPolicy.allowedCodes and genuinely prevents the confirmed baseline from working.',
            'Every other improvement is a short non-blocking coding-time suggestion and must not stop approval, persistence, or progression to Phase Manager.',
            'Do not regenerate accepted sections merely to make the plan more comprehensive. Refine only the affected scope.',
            'Return no suggestion when none is materially useful.',
        ]);
    }

    /**
     * Convert open-ended model review output into the finite server policy.
     *
     * @param list<array<string, string>> $findings
     * @return array{status: string, findings: list<array<string, string>>, suggestions: list<array<string, string>>}
     */
    public static function normalizeReview(string $workflowKey, string $requestedStatus, array $findings): array
    {
        $blockers = [];
        $suggestions = [];
        foreach ($findings as $finding) {
            $code = strtoupper(trim((string) ($finding['code'] ?? '')));
            $summary = trim((string) ($finding['summary'] ?? ''));
            $requiredResolution = trim((string) ($finding['requiredResolution'] ?? ''));
            if ($summary === '') {
                continue;
            }
            if ($requestedStatus === 'blocked' && isset(self::BLOCKING_CODES[$code])) {
                $blocker = ['code' => $code, 'summary' => self::limit($summary, 1000)];
                if ($requiredResolution !== '') {
                    $blocker['requiredResolution'] = self::limit($requiredResolution, 1000);
                }
                $blockers[] = $blocker;
                continue;
            }
            if (count($suggestions) >= 8) {
                continue;
            }
            $relatedArea = trim((string) ($finding['relatedArea'] ?? ''));
            $suggestions[] = [
                'title' => self::suggestionTitle($code, $summary),
                'relatedArea' => self::limit($relatedArea !== '' ? $relatedArea : self::workflowLabel($workflowKey), 120),
                'reason' => self::oneSentence($summary),
                'status' => 'non_blocking_coding_time',
            ];
        }
        return [
            'status' => $blockers === [] ? 'approved' : 'blocked',
            'findings' => $blockers,
            'suggestions' => $suggestions,
        ];
    }

    private static function suggestionTitle(string $code, string $summary): string
    {
        if ($code !== '') {
            return self::limit(ucwords(strtolower(str_replace('_', ' ', $code))), 100);
        }
        return self::limit(self::oneSentence($summary), 100);
    }

    private static function workflowLabel(string $workflowKey): string
    {
        return match ($workflowKey) {
            'requirements_analysis' => 'Requirements Analysis',
            'system_architecture' => 'System Architecture',
            'ui_ux_design' => 'UI/UX Design',
            'execution_roadmap' => 'Execution Roadmap',
            default => 'Phase Builder',
        };
    }

    private static function oneSentence(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        if (preg_match('/^(.+?[.!?])(?:\s|$)/u', $value, $match) === 1) {
            $value = $match[1];
        }
        return self::limit($value, 500);
    }

    private static function limit(string $value, int $length): string
    {
        $value = trim($value);
        return strlen($value) <= $length ? $value : rtrim(substr($value, 0, $length - 1)) . '…';
    }
}
