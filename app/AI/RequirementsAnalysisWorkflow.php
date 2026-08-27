<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;

final class RequirementsAnalysisWorkflow
{
    public const WORKFLOW_KEY = 'requirements_analysis';
    public const CHUNK_SCHEMA = 'builderx.requirements-analysis.chunk.v1';
    public const MERGE_SCHEMA = 'builderx.requirements-analysis.merge.v1';
    public const REVIEW_SCHEMA = 'builderx.requirements-analysis.review.v1';
    public const PERSISTENCE_SCHEMA = 'builderx.requirements-analysis.persistence.v1';

    /** @var list<string> */
    public const SOURCE_FIELDS = [
        'product_goal',
        'users_and_roles',
        'main_user_journey',
        'web_requirements',
        'android_requirements',
        'database_and_synchronization',
        'security_and_permissions',
        'validation_and_error_handling',
        'open_questions',
    ];

    /** @var array<string, array{chunk_key: string, label: string, id_prefix: string, categories: list<string>, source_fields: list<string>}> */
    public const CHUNKS = [
        'req_actors_roles' => [
            'chunk_key' => 'actors_roles',
            'label' => 'Actors and roles',
            'id_prefix' => 'ACT',
            'categories' => ['functionalRequirements', 'nonFunctionalRequirements'],
            'source_fields' => ['product_goal', 'users_and_roles', 'main_user_journey'],
        ],
        'req_functional' => [
            'chunk_key' => 'functional_requirements',
            'label' => 'Functional requirements',
            'id_prefix' => 'FR',
            'categories' => ['functionalRequirements'],
            'source_fields' => ['product_goal', 'main_user_journey', 'web_requirements'],
        ],
        'req_user_portal' => [
            'chunk_key' => 'user_portal',
            'label' => 'User Portal',
            'id_prefix' => 'USR',
            'categories' => ['functionalRequirements', 'nonFunctionalRequirements', 'accessibilityAndCompatibilityRequirements'],
            'source_fields' => ['users_and_roles', 'main_user_journey', 'web_requirements', 'validation_and_error_handling'],
        ],
        'req_admin_portal' => [
            'chunk_key' => 'administrator_portal',
            'label' => 'Administrator Portal',
            'id_prefix' => 'ADM',
            'categories' => ['functionalRequirements', 'securityAndPrivacyRequirements', 'monitoringAndAuditRequirements'],
            'source_fields' => ['users_and_roles', 'web_requirements', 'security_and_permissions', 'validation_and_error_handling'],
        ],
        'req_android_mobile' => [
            'chunk_key' => 'android_mobile',
            'label' => 'Android and mobile',
            'id_prefix' => 'AND',
            'categories' => ['functionalRequirements', 'nonFunctionalRequirements', 'configurationAndEnvironmentRequirements', 'performanceAndScalabilityRequirements', 'accessibilityAndCompatibilityRequirements'],
            'source_fields' => ['users_and_roles', 'main_user_journey', 'android_requirements', 'database_and_synchronization'],
        ],
        'req_database_sync' => [
            'chunk_key' => 'database_synchronization_persistence',
            'label' => 'Database, synchronization, and persistence',
            'id_prefix' => 'DB',
            'categories' => ['functionalRequirements', 'architectureConstraints', 'dataMigrationAndBackupRequirements', 'performanceAndScalabilityRequirements', 'availabilityAndRecoveryRequirements'],
            'source_fields' => ['web_requirements', 'android_requirements', 'database_and_synchronization', 'validation_and_error_handling'],
        ],
        'req_security' => [
            'chunk_key' => 'security_permissions',
            'label' => 'Security and permissions',
            'id_prefix' => 'SEC',
            'categories' => ['securityAndPrivacyRequirements', 'monitoringAndAuditRequirements'],
            'source_fields' => ['users_and_roles', 'security_and_permissions', 'validation_and_error_handling'],
        ],
        'req_validation_recovery' => [
            'chunk_key' => 'validation_recovery',
            'label' => 'Validation and recovery',
            'id_prefix' => 'VAL',
            'categories' => ['nonFunctionalRequirements', 'availabilityAndRecoveryRequirements', 'testingAndQualityRequirements', 'releaseAndRollbackRequirements'],
            'source_fields' => ['main_user_journey', 'database_and_synchronization', 'validation_and_error_handling', 'open_questions'],
        ],
        'req_deployment_ops' => [
            'chunk_key' => 'deployment_operations',
            'label' => 'Deployment and operations',
            'id_prefix' => 'OPS',
            'categories' => ['architectureConstraints', 'installationAndDeploymentRequirements', 'configurationAndEnvironmentRequirements', 'monitoringAndAuditRequirements', 'maintenanceAndSupportRequirements', 'releaseAndRollbackRequirements'],
            'source_fields' => ['product_goal', 'web_requirements', 'android_requirements', 'database_and_synchronization', 'security_and_permissions', 'validation_and_error_handling'],
        ],
    ];

    /** @var list<string> */
    public const CATEGORY_KEYS = [
        'functionalRequirements',
        'nonFunctionalRequirements',
        'architectureConstraints',
        'securityAndPrivacyRequirements',
        'installationAndDeploymentRequirements',
        'configurationAndEnvironmentRequirements',
        'dataMigrationAndBackupRequirements',
        'performanceAndScalabilityRequirements',
        'availabilityAndRecoveryRequirements',
        'monitoringAndAuditRequirements',
        'accessibilityAndCompatibilityRequirements',
        'testingAndQualityRequirements',
        'maintenanceAndSupportRequirements',
        'releaseAndRollbackRequirements',
    ];

    /** @return list<string> */
    public static function stages(): array
    {
        return ['context', ...array_keys(self::CHUNKS), 'merge', 'integration_review', 'persistence'];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validateChunk(array $run, string $stageKey, array $result): array
    {
        $configuration = self::CHUNKS[$stageKey] ?? null;
        if ($configuration === null) {
            throw new InvalidArgumentException('The Requirements Analysis chunk stage is invalid.');
        }
        $required = ['schemaVersion', 'workflowKey', 'stageKey', 'chunkKey', 'source', 'summary', 'actors', 'entities', 'portals', 'requirements', 'missingDetailsOrRisks', 'assumptions', 'openQuestions'];
        if (array_diff($required, array_keys($result)) !== [] || array_diff(array_keys($result), $required) !== []) {
            throw new RuntimeException('The Requirements Analysis chunk returned an invalid result object.');
        }
        $request = self::runRequest($run);
        $source = $result['source'] ?? null;
        if (
            ($result['schemaVersion'] ?? '') !== self::CHUNK_SCHEMA
            || ($result['workflowKey'] ?? '') !== self::WORKFLOW_KEY
            || ($result['stageKey'] ?? '') !== $stageKey
            || ($result['chunkKey'] ?? '') !== $configuration['chunk_key']
            || !is_array($source)
            || array_is_list($source)
            || (string) ($source['draftKey'] ?? '') !== (string) ($run['draft_key'] ?? '')
            || !hash_equals((string) ($request['source_narrative_hash'] ?? ''), (string) ($source['narrativeHash'] ?? ''))
            || !is_string($result['summary'] ?? null)
            || trim((string) $result['summary']) === ''
            || strlen((string) $result['summary']) > 4000
        ) {
            throw new RuntimeException('The Requirements Analysis chunk source or identity is invalid.');
        }
        foreach (['actors', 'entities', 'portals', 'requirements', 'missingDetailsOrRisks', 'assumptions', 'openQuestions'] as $listKey) {
            if (!is_array($result[$listKey]) || count($result[$listKey]) > 200) {
                throw new RuntimeException('The Requirements Analysis chunk contains an invalid or oversized ' . $listKey . ' list.');
            }
        }
        self::validateStringList($result['missingDetailsOrRisks'], 'missing detail or risk');
        self::validateStringList($result['assumptions'], 'assumption');
        self::validateStringList($result['openQuestions'], 'open question');
        self::validateNamedObjects($result['actors'], 'actorId', 'actor');
        self::validateNamedObjects($result['entities'], 'entityId', 'entity');
        self::validateNamedObjects($result['portals'], 'portalKey', 'portal');

        $seen = [];
        foreach ($result['requirements'] as $requirement) {
            if (!is_array($requirement) || array_is_list($requirement)) {
                throw new RuntimeException('A Requirements Analysis requirement is invalid.');
            }
            $requirementId = trim((string) ($requirement['requirementId'] ?? ''));
            $category = trim((string) ($requirement['category'] ?? ''));
            if (
                preg_match('/^' . preg_quote($configuration['id_prefix'], '/') . '-[0-9]{3}$/', $requirementId) !== 1
                || isset($seen[$requirementId])
                || !in_array($category, $configuration['categories'], true)
            ) {
                throw new RuntimeException('A Requirements Analysis requirement ID or category is invalid for this semantic chunk.');
            }
            $seen[$requirementId] = true;
            foreach (['title', 'description', 'verificationMethod'] as $field) {
                if (!is_string($requirement[$field] ?? null) || trim((string) $requirement[$field]) === '' || strlen((string) $requirement[$field]) > 8000) {
                    throw new RuntimeException('A Requirements Analysis requirement contains an invalid ' . $field . '.');
                }
            }
            if (!in_array((string) ($requirement['priority'] ?? ''), ['Must', 'Should', 'Could'], true) || ($requirement['status'] ?? '') !== 'Proposed' || !is_bool($requirement['isSelected'] ?? null)) {
                throw new RuntimeException('A Requirements Analysis requirement contains an invalid priority, status, or selection state.');
            }
            foreach (['sourceReferences', 'acceptanceCriteria', 'dependencies', 'assumptions', 'risks'] as $field) {
                if (!is_array($requirement[$field] ?? null) || count($requirement[$field]) > 100) {
                    throw new RuntimeException('A Requirements Analysis requirement contains an invalid ' . $field . ' list.');
                }
                self::validateStringList($requirement[$field], $field, $field === 'sourceReferences' || $field === 'acceptanceCriteria');
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    public static function merge(array $run): array
    {
        $request = self::runRequest($run);
        $chunkResults = [];
        foreach (array_keys(self::CHUNKS) as $stageKey) {
            $result = self::stageResult($run, $stageKey);
            if (!is_array($result)) {
                throw new RuntimeException('All nine verified Requirements Analysis chunks are required before merge.');
            }
            $chunkResults[$stageKey] = self::validateChunk($run, $stageKey, $result);
        }

        $contract = [
            'schemaVersion' => 'builderx.requirements-analysis.v2',
            'contractType' => 'builderx.requirements-analysis',
            'source' => [
                'draftKey' => (string) ($run['draft_key'] ?? ''),
                'narrativeHash' => (string) ($request['source_narrative_hash'] ?? ''),
                'sourceSections' => self::SOURCE_FIELDS,
            ],
            'projectAnalysis' => [
                'title' => 'BuilderX Requirements Analysis',
                'summary' => implode("\n\n", array_map(static fn (array $chunk): string => (string) $chunk['summary'], $chunkResults)),
                'problemStatement' => trim((string) (($request['source_snapshot']['product_goal'] ?? ''))),
                'goals' => [trim((string) (($request['source_snapshot']['product_goal'] ?? '')))],
                'exclusions' => ['BuilderX Phase Builder is the control plane and is not part of the generated product requirements.'],
                'exportReadyForSRS' => true,
            ],
            'actors' => [],
            'entities' => [],
            'portals' => [],
            ...array_fill_keys(self::CATEGORY_KEYS, []),
            'missingDetailsOrRisks' => [],
            'assumptions' => [],
            'openQuestions' => [],
            'reviewChecklist' => [
                ['check' => 'nine_semantic_chunks_validated', 'passed' => true],
                ['check' => 'source_hash_verified', 'passed' => true],
                ['check' => 'duplicate_requirement_ids_rejected', 'passed' => true],
            ],
            'traceability' => [],
            'rag' => ['memoryPath' => '', 'sources' => self::SOURCE_FIELDS],
            'orchestration' => [
                'executionMode' => 'persistent_deterministic_semantic_chunks',
                'engineType' => PhaseAiRunStore::ENGINE_PLANNING,
                'selectedSpecialists' => [],
                'additionalSpecialistProposals' => [],
                'chunkKeys' => array_values(array_map(static fn (array $configuration): string => $configuration['chunk_key'], self::CHUNKS)),
            ],
        ];
        $seenRequirementIds = [];
        foreach ($chunkResults as $chunk) {
            foreach (['actors', 'entities', 'portals', 'missingDetailsOrRisks', 'assumptions', 'openQuestions'] as $key) {
                $contract[$key] = self::appendUnique($contract[$key], $chunk[$key]);
            }
            foreach ($chunk['requirements'] as $requirement) {
                $requirementId = (string) $requirement['requirementId'];
                if (isset($seenRequirementIds[$requirementId])) {
                    throw new RuntimeException('The deterministic Requirements Analysis merge found a duplicate requirement ID.');
                }
                $seenRequirementIds[$requirementId] = true;
                $contract[(string) $requirement['category']][] = $requirement;
                $contract['traceability'][] = ['requirementId' => $requirementId, 'sourceReferences' => $requirement['sourceReferences']];
            }
        }
        foreach (self::CATEGORY_KEYS as $categoryKey) {
            usort($contract[$categoryKey], static fn (array $left, array $right): int => strcmp((string) $left['requirementId'], (string) $right['requirementId']));
        }
        usort($contract['traceability'], static fn (array $left, array $right): int => strcmp((string) $left['requirementId'], (string) $right['requirementId']));
        $contractHash = self::hashObject($contract);
        return [
            'schemaVersion' => self::MERGE_SCHEMA,
            'workflowKey' => self::WORKFLOW_KEY,
            'sourceNarrativeHash' => (string) ($request['source_narrative_hash'] ?? ''),
            'chunkKeys' => array_values(array_map(static fn (array $configuration): string => $configuration['chunk_key'], self::CHUNKS)),
            'contractHash' => $contractHash,
            'contract' => $contract,
        ];
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validateMerge(array $run, array $result): array
    {
        $expected = self::merge($run);
        if ($result !== $expected) {
            throw new RuntimeException('The deterministic Requirements Analysis merge does not match the verified chunks.');
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validateReview(array $run, array $result): array
    {
        $required = ['schemaVersion', 'workflowKey', 'sourceNarrativeHash', 'mergedContractHash', 'status', 'findings', 'confirmedRequirementIds'];
        $merge = self::stageResult($run, 'merge');
        if (!is_array($merge) || array_diff($required, array_keys($result)) !== [] || array_diff(array_keys($result), $required) !== []) {
            throw new RuntimeException('The Requirements Analysis integration review is incomplete.');
        }
        $request = self::runRequest($run);
        if (
            ($result['schemaVersion'] ?? '') !== self::REVIEW_SCHEMA
            || ($result['workflowKey'] ?? '') !== self::WORKFLOW_KEY
            || !hash_equals((string) ($request['source_narrative_hash'] ?? ''), (string) ($result['sourceNarrativeHash'] ?? ''))
            || !hash_equals((string) ($merge['contractHash'] ?? ''), (string) ($result['mergedContractHash'] ?? ''))
            || ($result['status'] ?? '') !== 'approved'
            || !is_array($result['findings'] ?? null)
            || !is_array($result['confirmedRequirementIds'] ?? null)
        ) {
            throw new RuntimeException('The Requirements Analysis integration review did not approve the exact merged contract.');
        }
        $expectedIds = self::requirementIds((array) ($merge['contract'] ?? []));
        $confirmedIds = array_values(array_map('strval', $result['confirmedRequirementIds']));
        sort($confirmedIds);
        if ($confirmedIds !== $expectedIds || count($result['findings']) > 100) {
            throw new RuntimeException('The Requirements Analysis integration review did not preserve every immutable requirement ID.');
        }
        foreach ($result['findings'] as $finding) {
            if (!is_array($finding) || !in_array((string) ($finding['severity'] ?? ''), ['info', 'warning'], true) || trim((string) ($finding['message'] ?? '')) === '') {
                throw new RuntimeException('The Requirements Analysis integration review contains an invalid finding.');
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $run @param array<string, mixed> $result @return array<string, mixed> */
    public static function validatePersistence(array $run, array $result): array
    {
        $required = ['schemaVersion', 'status', 'draftKey', 'sourceNarrativeHash', 'analysisKey', 'contractHash', 'readBackVerified'];
        $merge = self::stageResult($run, 'merge');
        if (
            !is_array($merge)
            || array_diff($required, array_keys($result)) !== []
            || array_diff(array_keys($result), $required) !== []
            || ($result['schemaVersion'] ?? '') !== self::PERSISTENCE_SCHEMA
            || !in_array((string) ($result['status'] ?? ''), ['created', 'updated', 'already_saved'], true)
            || (string) ($result['draftKey'] ?? '') !== (string) ($run['draft_key'] ?? '')
            || !hash_equals((string) ($merge['sourceNarrativeHash'] ?? ''), (string) ($result['sourceNarrativeHash'] ?? ''))
            || !hash_equals((string) ($merge['contractHash'] ?? ''), (string) ($result['contractHash'] ?? ''))
            || preg_match('/^[A-Za-z0-9._:-]{1,36}$/', (string) ($result['analysisKey'] ?? '')) !== 1
            || ($result['readBackVerified'] ?? false) !== true
        ) {
            throw new RuntimeException('The Requirements Analysis persistence read-back is invalid.');
        }
        return $result;
    }

    /** @param list<mixed> $values */
    private static function validateStringList(array $values, string $label, bool $required = false): void
    {
        if ($required && $values === []) {
            throw new RuntimeException('A Requirements Analysis ' . $label . ' list must not be empty.');
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 4000) {
                throw new RuntimeException('A Requirements Analysis ' . $label . ' entry is invalid.');
            }
        }
    }

    /** @param list<mixed> $values */
    private static function validateNamedObjects(array $values, string $identityKey, string $label): void
    {
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value) || trim((string) ($value[$identityKey] ?? '')) === '' || trim((string) ($value['name'] ?? $value['label'] ?? '')) === '') {
                throw new RuntimeException('A Requirements Analysis ' . $label . ' entry is invalid.');
            }
        }
    }

    /** @param array<string, mixed> $run @return array<string, mixed> */
    private static function runRequest(array $run): array
    {
        $request = $run['request'] ?? null;
        if (!is_array($request) || ($request['workflow_key'] ?? '') !== self::WORKFLOW_KEY) {
            throw new RuntimeException('The persisted Requirements Analysis request is unavailable.');
        }
        return $request;
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

    /** @param list<mixed> $existing @param list<mixed> $incoming @return list<mixed> */
    private static function appendUnique(array $existing, array $incoming): array
    {
        $seen = [];
        foreach ($existing as $value) {
            $seen[json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)] = true;
        }
        foreach ($incoming as $value) {
            $identity = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (!isset($seen[$identity])) {
                $existing[] = $value;
                $seen[$identity] = true;
            }
        }
        return $existing;
    }

    /** @param array<string, mixed> $contract @return list<string> */
    private static function requirementIds(array $contract): array
    {
        $ids = [];
        foreach (self::CATEGORY_KEYS as $categoryKey) {
            foreach (($contract[$categoryKey] ?? []) as $requirement) {
                if (is_array($requirement)) {
                    $ids[] = (string) ($requirement['requirementId'] ?? '');
                }
            }
        }
        sort($ids);
        return $ids;
    }

    /** @param array<string, mixed> $value */
    public static function hashObject(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
