<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Backup\DailyBackupClaimService;
use DateTimeInterface;
use RuntimeException;

final class BackupController
{
    public function __construct(private readonly DailyBackupClaimService $claims = new DailyBackupClaimService())
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function claimDailyLoginBackup(array $payload): array
    {
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];
        if (!$this->isAuthenticated($user)) {
            throw new RuntimeException('backup_claim_login_required');
        }

        $tenantConfig = is_array($payload['tenant'] ?? null) ? $payload['tenant'] : $payload;
        $existingClaims = is_iterable($payload['existing_claims'] ?? null) ? $payload['existing_claims'] : [];
        $loginAt = $payload['login_at'] ?? null;

        return $this->claims->claimForLogin(
            $tenantConfig,
            $loginAt instanceof DateTimeInterface || is_string($loginAt) ? $loginAt : null,
            $existingClaims
        );
    }

    /**
     * @param array<string, mixed> $tenantScope
     * @param iterable<string|array<string, mixed>> $existingClaims
     * @return array<string, mixed>
     */
    public function loginBackupClaim(array $tenantScope, DateTimeInterface|string|null $loginAt = null, iterable $existingClaims = []): array
    {
        return $this->claims->claimForLogin($tenantScope, $loginAt, $existingClaims);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isAuthenticated(array $user): bool
    {
        $userKey = trim((string) ($user['user_key'] ?? $user['userKey'] ?? $user['id'] ?? ''));
        $active = $user['active'] ?? $user['is_active'] ?? $user['isActive'] ?? true;

        return $userKey !== '' && ($active === true || $active === 1 || $active === '1' || $active === 'active');
    }
}
