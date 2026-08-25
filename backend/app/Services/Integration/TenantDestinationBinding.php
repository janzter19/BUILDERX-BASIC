<?php
declare(strict_types=1);

namespace App\Services\Integration;

use App\Security\FileSecurityValidator;
use RuntimeException;

final class TenantDestinationBinding
{
    public function __construct(private readonly FileSecurityValidator $fileSecurity = new FileSecurityValidator())
    {
    }

    /**
     * @param iterable<array<string, mixed>> $registryRows
     * @return array<string, mixed>
     */
    public function bindForHospitalCode(string $hospitalCode, iterable $registryRows): array
    {
        $normalizedCode = self::normalizeHospitalCode($hospitalCode);
        foreach ($registryRows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (self::normalizeHospitalCode($this->firstString($row, ['hospital_code', 'hospitalCode', 'code'])) === $normalizedCode) {
                return $this->bindTenantConfiguration($row, $normalizedCode);
            }
        }

        throw new RuntimeException('tenant_configuration_not_found');
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @return array<string, mixed>
     */
    public function bindTenantConfiguration(array $tenantConfig, ?string $expectedHospitalCode = null): array
    {
        $hospitalCode = self::normalizeHospitalCode($this->firstString($tenantConfig, ['hospital_code', 'hospitalCode', 'code']));
        if ($hospitalCode === '') {
            throw new RuntimeException('hospital_code_required');
        }
        if ($expectedHospitalCode !== null && $hospitalCode !== self::normalizeHospitalCode($expectedHospitalCode)) {
            throw new RuntimeException('hospital_code_mismatch');
        }
        if (!$this->isActive($tenantConfig)) {
            throw new RuntimeException('tenant_configuration_inactive');
        }

        $tenantKey = $this->firstString($tenantConfig, ['tenant_key', 'tenantKey', 'tenant_id', 'tenantId']);
        $branchKey = $this->firstString($tenantConfig, ['branch_key', 'branchKey']);
        $projectKey = $this->firstString($tenantConfig, ['project_key', 'projectKey']);
        if ($tenantKey === '' || $branchKey === '' || $projectKey === '') {
            throw new RuntimeException('tenant_configuration_incomplete');
        }

        $mediaConfig = is_array($tenantConfig['media'] ?? null) ? $tenantConfig['media'] : [];

        return [
            'hospital_code' => $hospitalCode,
            'tenant_key' => $tenantKey,
            'branch_key' => $branchKey,
            'project_key' => $projectKey,
            'firebase_destination' => $this->activeFirebaseDestination($tenantConfig, $hospitalCode),
            'media_base_path' => $this->safeRelativePath($this->firstString($mediaConfig, ['base_path', 'basePath', 'storage_path', 'storagePath'], 'tenant-media/' . $hospitalCode)),
        ];
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @param array<string, mixed> $mutation
     * @param list<array<string, mixed>> $mediaReferences
     * @return array<string, mixed>
     */
    public function bindSuccessfulMutation(array $tenantConfig, array $mutation, array $mediaReferences = []): array
    {
        $binding = $this->bindTenantConfiguration($tenantConfig);
        $mutationKey = trim((string) ($mutation['mutation_key'] ?? $mutation['mutationKey'] ?? $mutation['record_key'] ?? $mutation['recordKey'] ?? ''));
        if ($mutationKey === '') {
            throw new RuntimeException('mutation_key_required');
        }

        $media = [];
        foreach ($mediaReferences as $reference) {
            $media[] = $this->prepareRelativeMediaMetadata($binding, $reference);
        }

        return [
            'tenant' => [
                'hospital_code' => $binding['hospital_code'],
                'tenant_key' => $binding['tenant_key'],
                'branch_key' => $binding['branch_key'],
                'project_key' => $binding['project_key'],
            ],
            'firebase_destination' => $binding['firebase_destination'],
            'mutation' => [
                'mutation_key' => $mutationKey,
                'record_key' => trim((string) ($mutation['record_key'] ?? $mutation['recordKey'] ?? '')),
                'operation' => trim((string) ($mutation['operation'] ?? 'upsert')),
            ],
            'media' => $media,
        ];
    }

    /**
     * @param array<string, mixed> $binding
     * @param array<string, mixed> $mediaPayload
     * @return array<string, mixed>
     */
    public function prepareRelativeMediaMetadata(array $binding, array $mediaPayload): array
    {
        $media = $this->fileSecurity->validateMediaReference($mediaPayload);
        $basePath = $this->safeRelativePath((string) ($binding['media_base_path'] ?? 'tenant-media/' . (string) ($binding['hospital_code'] ?? '')));
        if (!str_starts_with($media['storage_path'], rtrim($basePath, '/') . '/')) {
            throw new RuntimeException('media_path_not_bound_to_tenant');
        }

        return [
            'hospital_code' => (string) ($binding['hospital_code'] ?? ''),
            'tenant_key' => (string) ($binding['tenant_key'] ?? ''),
            'storage_path' => $media['storage_path'],
            'original_name' => $media['original_name'],
            'stored_name' => $media['stored_name'],
            'mime_type' => $media['mime_type'],
            'file_size' => $media['file_size'],
            'checksum_sha256' => $media['checksum_sha256'],
        ];
    }

    public static function normalizeHospitalCode(string $hospitalCode): string
    {
        return preg_replace('/\s+/', '', strtoupper(trim($hospitalCode))) ?? '';
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @return array<string, mixed>
     */
    private function activeFirebaseDestination(array $tenantConfig, string $hospitalCode): array
    {
        $candidates = [];
        foreach (['firebase_destinations', 'firebaseDestinations', 'firebase_projects', 'firebaseProjects'] as $key) {
            if (is_array($tenantConfig[$key] ?? null)) {
                $candidates = $tenantConfig[$key];
                break;
            }
        }
        if ($candidates === [] && is_array($tenantConfig['firebase'] ?? null)) {
            $candidates = [$tenantConfig['firebase']];
        }

        foreach ($candidates as $candidate) {
            if (!is_array($candidate) || !$this->isActive($candidate)) {
                continue;
            }
            $candidateHospitalCode = $this->firstString($candidate, ['hospital_code', 'hospitalCode', 'code'], $hospitalCode);
            if (self::normalizeHospitalCode($candidateHospitalCode) === $hospitalCode) {
                return $this->validatedFirebaseDestination($candidate);
            }
        }

        throw new RuntimeException('active_firebase_destination_not_found');
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function validatedFirebaseDestination(array $candidate): array
    {
        $projectId = $this->firstString($candidate, ['project_id', 'projectId', 'firebase_project_id', 'firebaseProjectId']);
        $databaseUrl = $this->firstString($candidate, ['database_url', 'databaseUrl', 'firebase_database_url', 'firebaseDatabaseUrl']);
        $apiPath = $this->safeApiPath($this->firstString($candidate, ['api_path', 'apiPath', 'collection_path', 'collectionPath'], '/hospitals'));
        if ($projectId === '' || $databaseUrl === '') {
            throw new RuntimeException('firebase_destination_incomplete');
        }
        if (preg_match('/^https:\/\/[a-z0-9.-]+(?:\/.*)?$/i', $databaseUrl) !== 1) {
            throw new RuntimeException('firebase_database_url_must_be_https');
        }

        return [
            'project_id' => $projectId,
            'database_url' => rtrim($databaseUrl, '/'),
            'api_path' => $apiPath,
        ];
    }

    private function safeApiPath(string $value): string
    {
        $rawPath = trim(str_replace('\\', '/', $value));
        $path = '/' . ltrim($rawPath, '/');
        if (
            $path === '/'
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $rawPath) === 1
            || str_contains($path, ':')
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/(?:^|\/)\.{1,2}(?:\/|$)/', $path) === 1
        ) {
            throw new RuntimeException('invalid_firebase_api_path');
        }

        return $path;
    }

    private function safeRelativePath(string $value): string
    {
        $path = trim(str_replace('\\', '/', $value));
        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
            || preg_match('/(?:^|\/)\.{1,2}(?:\/|$)/', $path) === 1
        ) {
            throw new RuntimeException('invalid_tenant_media_path');
        }

        return implode('/', array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== '')));
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    private function firstString(array $payload, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return trim((string) $payload[$key]);
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isActive(array $payload): bool
    {
        $active = $payload['active'] ?? $payload['is_active'] ?? $payload['isActive'] ?? true;
        if (is_bool($active)) {
            return $active;
        }
        if (is_numeric($active)) {
            return (int) $active === 1;
        }

        return in_array(strtolower(trim((string) $active)), ['active', 'enabled', 'true', 'yes'], true);
    }
}
