<?php
declare(strict_types=1);

namespace App\Listeners;

use App\Services\Integration\TenantDestinationBinding;

final class QueueExternalSynchronization
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
    public function payloadForSuccessfulMutation(array $tenantConfig, array $mutation, array $mediaReferences = []): array
    {
        return $this->destinationBinding->bindSuccessfulMutation($tenantConfig, $mutation, $mediaReferences);
    }
}
