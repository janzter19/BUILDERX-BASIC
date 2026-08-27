<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integration\TenantDestinationBinding;

final class SyncMysqlJob
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @return array<string, mixed>
     */
    public function tenantScope(array $tenantConfig): array
    {
        $binding = $this->destinationBinding->bindTenantConfiguration($tenantConfig);

        return [
            'hospital_code' => $binding['hospital_code'],
            'tenant_key' => $binding['tenant_key'],
            'branch_key' => $binding['branch_key'],
            'project_key' => $binding['project_key'],
        ];
    }
}
