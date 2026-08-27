<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Integration\TenantDestinationBinding;

final class SyncController
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function destination(array $payload): array
    {
        $tenantConfig = is_array($payload['tenant'] ?? null) ? $payload['tenant'] : $payload;
        return $this->destinationBinding->bindTenantConfiguration($tenantConfig);
    }

    /**
     * @param array<string, mixed> $tenantRow
     * @return array<string, mixed>
     */
    public function verifyHospitalCode(array $tenantRow, string $hospitalCode): array
    {
        return $this->destinationBinding->bindForHospitalCode($hospitalCode, [$tenantRow]);
    }
}
