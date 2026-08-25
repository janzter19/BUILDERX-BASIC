<?php
declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Services\Integration\TenantDestinationBinding;

final class FirebaseWebhookController
{
    public function __construct(private readonly TenantDestinationBinding $destinationBinding = new TenantDestinationBinding())
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function verifyDestination(array $payload): array
    {
        $tenantConfig = is_array($payload['tenant'] ?? null) ? $payload['tenant'] : $payload;
        $binding = $this->destinationBinding->bindTenantConfiguration($tenantConfig);

        return [
            'verified' => true,
            'hospital_code' => $binding['hospital_code'],
            'firebase_project_id' => $binding['firebase_destination']['project_id'],
        ];
    }
}
