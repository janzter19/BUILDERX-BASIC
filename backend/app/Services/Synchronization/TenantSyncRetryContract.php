<?php
declare(strict_types=1);

namespace App\Services\Synchronization;

use App\Services\Integration\TenantDestinationBinding;
use RuntimeException;

final class TenantSyncRetryContract
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @param iterable<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public function evaluate(array $tenantConfig, iterable $items, int $maxAttempts = 3): array
    {
        if ($maxAttempts < 1) {
            throw new RuntimeException('sync_retry_max_attempts_invalid');
        }

        $binding = $this->destinationBinding->bindTenantConfiguration($tenantConfig);
        $tenant = [
            'hospital_code' => $binding['hospital_code'],
            'tenant_key' => $binding['tenant_key'],
            'branch_key' => $binding['branch_key'],
            'project_key' => $binding['project_key'],
        ];

        $seenMutationKeys = [];
        $results = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || !$this->isActive($item)) {
                continue;
            }

            $itemTenantCode = TenantDestinationBinding::normalizeHospitalCode($this->firstString($item, ['hospital_code', 'hospitalCode'], (string) $tenant['hospital_code']));
            if ($itemTenantCode !== $tenant['hospital_code']) {
                throw new RuntimeException('sync_item_hospital_code_mismatch');
            }

            $mutationKey = $this->firstString($item, ['mutation_key', 'mutationKey', 'record_key', 'recordKey']);
            if ($mutationKey === '') {
                throw new RuntimeException('sync_item_mutation_key_required');
            }

            $attempts = max(0, (int) ($item['attempts'] ?? $item['retry_count'] ?? $item['retryCount'] ?? 0));
            $status = $this->visibleStatus($item, $attempts, $maxAttempts, array_key_exists($mutationKey, $seenMutationKeys));
            $seenMutationKeys[$mutationKey] = true;

            $results[] = [
                'tenant' => $tenant,
                'mutation_key' => $mutationKey,
                'queue_position' => (int) $index,
                'status' => $status['status'],
                'reason' => $status['reason'],
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'next_attempt_allowed' => $status['status'] === 'retry',
            ];
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{status: string, reason: string}
     */
    private function visibleStatus(array $item, int $attempts, int $maxAttempts, bool $duplicate): array
    {
        if ($duplicate) {
            return ['status' => 'conflict', 'reason' => 'duplicate_queue_entry'];
        }

        $sourceVersion = $this->firstString($item, ['source_version', 'sourceVersion', 'mysql_version', 'mysqlVersion']);
        $destinationVersion = $this->firstString($item, ['destination_version', 'destinationVersion', 'firebase_version', 'firebaseVersion']);
        if ($sourceVersion !== '' && $destinationVersion !== '' && $sourceVersion !== $destinationVersion) {
            return ['status' => 'conflict', 'reason' => 'version_mismatch'];
        }

        $sourceChecksum = $this->firstString($item, ['source_checksum', 'sourceChecksum', 'mysql_checksum', 'mysqlChecksum']);
        $destinationChecksum = $this->firstString($item, ['destination_checksum', 'destinationChecksum', 'firebase_checksum', 'firebaseChecksum']);
        if ($sourceChecksum !== '' && $destinationChecksum !== '' && $sourceChecksum !== $destinationChecksum) {
            return ['status' => 'conflict', 'reason' => 'checksum_mismatch'];
        }

        $rawStatus = strtolower($this->firstString($item, ['status', 'sync_status', 'syncStatus'], 'queued'));
        if (in_array($rawStatus, ['completed', 'complete', 'synced', 'success'], true)) {
            return ['status' => 'completed', 'reason' => 'already_synced'];
        }

        if ($attempts >= $maxAttempts) {
            return ['status' => 'failed', 'reason' => 'retry_limit_reached'];
        }

        if (in_array($rawStatus, ['failed', 'retry', 'retrying', 'error', 'timeout'], true)) {
            return ['status' => 'retry', 'reason' => 'retry_available'];
        }

        return ['status' => 'queued', 'reason' => 'awaiting_processing'];
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
