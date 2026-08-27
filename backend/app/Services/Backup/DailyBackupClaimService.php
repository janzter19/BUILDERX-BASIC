<?php
declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\Integration\TenantDestinationBinding;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RuntimeException;

final class DailyBackupClaimService
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @param iterable<string|array<string, mixed>> $existingClaims
     * @return array<string, mixed>
     */
    public function claimForLogin(array $tenantConfig, DateTimeInterface|string|null $loginAt = null, iterable $existingClaims = []): array
    {
        $binding = $this->destinationBinding->bindTenantConfiguration($tenantConfig);
        $tenant = [
            'hospital_code' => $binding['hospital_code'],
            'tenant_key' => $binding['tenant_key'],
            'branch_key' => $binding['branch_key'],
            'project_key' => $binding['project_key'],
        ];
        $localDate = $this->localDate($loginAt, $this->firstString($tenantConfig, ['timezone', 'time_zone', 'timeZone'], 'UTC'));
        $claimKey = $this->claimKey($tenant, $localDate);

        foreach ($existingClaims as $claim) {
            if ($this->claimReference($claim) === $claimKey) {
                return [
                    'tenant' => $tenant,
                    'backup_claim_key' => $claimKey,
                    'backup_date' => $localDate,
                    'status' => 'already_claimed',
                    'reason' => 'daily_login_backup_claim_exists',
                ];
            }
        }

        return [
            'tenant' => $tenant,
            'backup_claim_key' => $claimKey,
            'backup_date' => $localDate,
            'status' => 'claimed',
            'reason' => 'daily_login_backup_claim_available',
        ];
    }

    /**
     * @param array<string, string> $tenant
     */
    public function claimKey(array $tenant, string $localDate): string
    {
        $hospitalCode = TenantDestinationBinding::normalizeHospitalCode((string) ($tenant['hospital_code'] ?? ''));
        $tenantKey = trim((string) ($tenant['tenant_key'] ?? ''));
        if ($hospitalCode === '' || $tenantKey === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $localDate) !== 1) {
            throw new RuntimeException('backup_claim_key_scope_invalid');
        }

        return implode(':', ['daily-backup', $hospitalCode, $tenantKey, $localDate]);
    }

    private function localDate(DateTimeInterface|string|null $loginAt, string $timezone): string
    {
        $zone = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        if ($loginAt instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($loginAt)->setTimezone($zone)->format('Y-m-d');
        }
        if (is_string($loginAt) && trim($loginAt) !== '') {
            return (new DateTimeImmutable($loginAt))->setTimezone($zone)->format('Y-m-d');
        }

        return (new DateTimeImmutable('now', $zone))->format('Y-m-d');
    }

    /**
     * @param string|array<string, mixed> $claim
     */
    private function claimReference(string|array $claim): string
    {
        if (is_string($claim)) {
            return trim($claim);
        }

        return $this->firstString($claim, ['backup_claim_key', 'backupClaimKey', 'claim_key', 'claimKey']);
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
}
