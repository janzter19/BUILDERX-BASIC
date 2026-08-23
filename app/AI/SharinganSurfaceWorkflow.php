<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class SharinganSurfaceWorkflow
{
    public const ANALYSIS_SCHEMA = 'builderx.sharingan.analysis.v1';
    public const PERSISTENCE_SCHEMA = 'builderx.sharingan.persistence.v1';

    /** @var array<string, array{workflow_key: string, route_key: string, draft_key: string, label: string, administrator_required: bool}> */
    public const SURFACES = [
        'user_portal' => [
            'workflow_key' => 'sharingan_user',
            'route_key' => 'user_portal',
            'draft_key' => 'sharingan-user-portal',
            'label' => 'User Portal',
            'administrator_required' => false,
        ],
        'administrator_portal' => [
            'workflow_key' => 'sharingan_admin',
            'route_key' => 'administrator_portal',
            'draft_key' => 'sharingan-administrator',
            'label' => 'Administrator Portal',
            'administrator_required' => true,
        ],
        'phases' => [
            'workflow_key' => 'sharingan_phases',
            'route_key' => 'phases',
            'draft_key' => 'sharingan-phases',
            'label' => 'Phases',
            'administrator_required' => true,
        ],
    ];

    /** @return array{workflow_key: string, route_key: string, draft_key: string, label: string, administrator_required: bool} */
    public static function surface(string $surfaceKey): array
    {
        $surfaceKey = strtolower(trim($surfaceKey));
        if (!isset(self::SURFACES[$surfaceKey])) {
            throw new InvalidArgumentException('The Sharingan surface is invalid.');
        }
        return self::SURFACES[$surfaceKey];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validateAnalysis(array $run, array $result): array
    {
        $required = ['schemaVersion', 'status', 'surface', 'context', 'summary', 'findings', 'proposedChanges', 'risks', 'verificationPlan', 'blockers'];
        if (array_diff($required, array_keys($result)) !== [] || array_diff(array_keys($result), $required) !== []) {
            throw new RuntimeException('The Sharingan analysis returned an invalid result object.');
        }
        $request = $run['request'] ?? null;
        $surface = $result['surface'] ?? null;
        $context = $result['context'] ?? null;
        if (
            !is_array($request)
            || ($result['schemaVersion'] ?? '') !== self::ANALYSIS_SCHEMA
            || !in_array((string) ($result['status'] ?? ''), ['completed', 'blocked'], true)
            || !is_array($surface)
            || !is_array($context)
            || (string) ($surface['surfaceKey'] ?? '') !== (string) ($request['surface_key'] ?? '')
            || (string) ($surface['routeKey'] ?? '') !== (string) ($run['route_key'] ?? '')
            || (string) ($context['contextId'] ?? '') !== (string) ($request['context_id'] ?? '')
            || !hash_equals((string) ($request['context_sha256'] ?? ''), (string) ($context['contextSha256'] ?? ''))
            || !is_string($result['summary'] ?? null)
            || trim((string) $result['summary']) === ''
            || strlen((string) $result['summary']) > 8000
        ) {
            throw new RuntimeException('The Sharingan analysis identity, route, or context binding is invalid.');
        }
        foreach (['findings', 'proposedChanges', 'risks', 'verificationPlan', 'blockers'] as $listKey) {
            if (!is_array($result[$listKey]) || count($result[$listKey]) > 100) {
                throw new RuntimeException('The Sharingan analysis contains an invalid or oversized ' . $listKey . ' list.');
            }
        }
        self::validateStringList($result['risks'], 'risk');
        self::validateStringList($result['verificationPlan'], 'verification step');
        self::validateStringList($result['blockers'], 'blocker');
        foreach ($result['findings'] as $finding) {
            if (!is_array($finding) || array_is_list($finding) || trim((string) ($finding['findingId'] ?? '')) === '' || !in_array((string) ($finding['severity'] ?? ''), ['info', 'warning', 'error'], true) || trim((string) ($finding['description'] ?? '')) === '') {
                throw new RuntimeException('The Sharingan analysis contains an invalid finding.');
            }
        }
        foreach ($result['proposedChanges'] as $change) {
            if (
                !is_array($change)
                || array_is_list($change)
                || preg_match('/^CHG-[0-9]{3}$/', (string) ($change['changeId'] ?? '')) !== 1
                || trim((string) ($change['scope'] ?? '')) === ''
                || trim((string) ($change['description'] ?? '')) === ''
                || !in_array((string) ($change['recommendedEngine'] ?? ''), [PhaseAiRunStore::ENGINE_PLANNING, PhaseAiRunStore::ENGINE_CODING], true)
                || ($change['requiresAdministratorApproval'] ?? null) !== true
            ) {
                throw new RuntimeException('The Sharingan analysis contains an invalid proposed change or attempts to bypass administrator approval.');
            }
        }
        if (($request['surface_key'] ?? '') === 'user_portal') {
            foreach ($result['proposedChanges'] as $change) {
                if (($change['requiresAdministratorApproval'] ?? false) !== true) {
                    throw new RuntimeException('A User Portal Sharingan analysis attempted to grant mutation authority.');
                }
            }
        }
        if (($result['status'] ?? '') === 'blocked' && $result['blockers'] === []) {
            throw new RuntimeException('A blocked Sharingan analysis must identify at least one blocker.');
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validatePersistence(array $run, array $result): array
    {
        $required = ['schemaVersion', 'status', 'analysisHash', 'readBackVerified'];
        $analysis = self::stageResult($run, 'analysis');
        if (
            !is_array($analysis)
            || array_diff($required, array_keys($result)) !== []
            || array_diff(array_keys($result), $required) !== []
            || ($result['schemaVersion'] ?? '') !== self::PERSISTENCE_SCHEMA
            || ($result['status'] ?? '') !== 'saved'
            || !hash_equals(self::hashObject($analysis), (string) ($result['analysisHash'] ?? ''))
            || ($result['readBackVerified'] ?? false) !== true
        ) {
            throw new RuntimeException('The Sharingan analysis persistence read-back is invalid.');
        }
        return $result;
    }

    /** @param list<mixed> $values */
    private static function validateStringList(array $values, string $label): void
    {
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 4000) {
                throw new RuntimeException('The Sharingan analysis contains an invalid ' . $label . '.');
            }
        }
    }

    /** @param array<string, mixed> $run @return array<string, mixed>|null */
    private static function stageResult(array $run, string $stageKey): ?array
    {
        foreach ($run['stages'] ?? [] as $stage) {
            if (is_array($stage) && ($stage['stage_key'] ?? '') === $stageKey && is_array($stage['result'] ?? null)) {
                return $stage['result'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $value */
    public static function hashObject(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
