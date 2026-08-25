<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\Backup\DailyBackupClaimService;
use DateTimeInterface;

final class BackupDatabaseJob
{
    public function __construct(private readonly DailyBackupClaimService $claims = new DailyBackupClaimService())
    {
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @param iterable<string|array<string, mixed>> $existingClaims
     * @return array<string, mixed>
     */
    public function claimDailyLoginBackup(array $tenantConfig, DateTimeInterface|string|null $loginAt = null, iterable $existingClaims = []): array
    {
        return $this->claims->claimForLogin($tenantConfig, $loginAt, $existingClaims);
    }
}
