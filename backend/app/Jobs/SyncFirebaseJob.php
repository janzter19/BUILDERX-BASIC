<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Services\Integration\TenantDestinationBinding;

final class SyncFirebaseJob
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @param array<string, mixed> $mutation
     * @param list<array<string, mixed>> $mediaReferences
     * @return array<string, mixed>
     */
    public function destinationPayload(array $tenantConfig, array $mutation, array $mediaReferences = []): array
    {
        return $this->destinationBinding->bindSuccessfulMutation($tenantConfig, $mutation, $mediaReferences);
    }

    /**
     * @param array<string, mixed> $tenantConfig
     * @return array<string, mixed>
     */
    public function destinationForMutation(array $tenantConfig, string $mutationName): array
    {
        return $this->destinationPayload($tenantConfig, ['mutation_key' => $mutationName, 'operation' => $mutationName]);
    }
}
