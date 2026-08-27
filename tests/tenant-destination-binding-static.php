<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$validatorPath = $root . '/backend/app/Security/FileSecurityValidator.php';
$bindingPath = $root . '/backend/app/Services/Integration/TenantDestinationBinding.php';
$syncFirebasePath = $root . '/backend/app/Jobs/SyncFirebaseJob.php';
$syncMysqlPath = $root . '/backend/app/Jobs/SyncMysqlJob.php';
$listenerPath = $root . '/backend/app/Listeners/QueueExternalSynchronization.php';
$syncControllerPath = $root . '/backend/app/Http/Controllers/Api/V1/SyncController.php';
$webhookPath = $root . '/backend/app/Http/Controllers/Webhook/FirebaseWebhookController.php';

require_once $validatorPath;
require_once $bindingPath;

$source = (string) file_get_contents($bindingPath);
$placeholders = [
    (string) file_get_contents($syncFirebasePath),
    (string) file_get_contents($syncMysqlPath),
    (string) file_get_contents($listenerPath),
    (string) file_get_contents($syncControllerPath),
    (string) file_get_contents($webhookPath),
];

$requiredMarkers = [
    'final class TenantDestinationBinding',
    'bindForHospitalCode',
    'bindTenantConfiguration',
    'bindSuccessfulMutation',
    'prepareRelativeMediaMetadata',
    'normalizeHospitalCode',
    'activeFirebaseDestination',
    'firebase_database_url_must_be_https',
    'invalid_firebase_api_path',
    'media_path_not_bound_to_tenant',
    '$this->fileSecurity->validateMediaReference($mediaPayload)',
];

foreach ($requiredMarkers as $marker) {
    if (!str_contains($source, $marker)) {
        throw new RuntimeException('Missing tenant destination binding marker: ' . $marker);
    }
}

foreach ($placeholders as $placeholder) {
    if (!str_contains($placeholder, 'use App\Services\Integration\TenantDestinationBinding;')) {
        throw new RuntimeException('Sync/Firebase placeholder does not consume TenantDestinationBinding.');
    }
}

$binding = new App\Services\Integration\TenantDestinationBinding();
$tenantConfig = [
    'hospital_code' => ' hsp 001 ',
    'tenant_key' => 'tenant-hsp-001',
    'branch_key' => '11111111-1111-4111-8111-111111111111',
    'project_key' => '22222222-2222-4222-8222-222222222222',
    'media' => ['base_path' => 'tenant-media/HSP001'],
    'firebase_destinations' => [
        [
            'hospital_code' => 'HSP001',
            'active' => false,
            'project_id' => 'inactive-project',
            'database_url' => 'https://inactive.firebaseio.com',
            'api_path' => '/hospitals/inactive',
        ],
        [
            'hospitalCode' => 'HSP001',
            'active' => true,
            'projectId' => 'active-project',
            'databaseUrl' => 'https://active.firebaseio.com/root/',
            'apiPath' => '/hospitals/HSP001/mutations',
        ],
    ],
];

$resolved = $binding->bindForHospitalCode(' hsp 001 ', [$tenantConfig]);
if ($resolved['hospital_code'] !== 'HSP001') {
    throw new RuntimeException('Hospital Code normalization failed.');
}
if (($resolved['firebase_destination']['project_id'] ?? '') !== 'active-project') {
    throw new RuntimeException('Active Firebase destination was not selected.');
}
if (($resolved['firebase_destination']['database_url'] ?? '') !== 'https://active.firebaseio.com/root') {
    throw new RuntimeException('Firebase database URL was not normalized.');
}

$mutation = $binding->bindSuccessfulMutation($tenantConfig, ['mutation_key' => 'mutation-001'], [[
    'original_name' => 'xray.png',
    'stored_name' => 'xray-001.png',
    'storage_path' => 'tenant-media/HSP001/records/xray-001.png',
    'mime_type' => 'image/png',
    'file_size' => 128,
    'checksum_sha256' => str_repeat('a', 64),
]]);
if (($mutation['media'][0]['storage_path'] ?? '') !== 'tenant-media/HSP001/records/xray-001.png') {
    throw new RuntimeException('Relative tenant media metadata was not preserved.');
}

$blocked = 0;
foreach ([
    static fn (): array => $binding->bindTenantConfiguration($tenantConfig + ['firebase' => ['project_id' => 'x', 'database_url' => 'http://bad.local']], 'OTHER'),
    static fn (): array => $binding->bindTenantConfiguration(array_replace($tenantConfig, ['firebase_destinations' => [['active' => true, 'project_id' => 'bad', 'database_url' => 'http://bad.local', 'api_path' => '/hospitals/HSP001']]])),
    static fn (): array => $binding->bindTenantConfiguration(array_replace($tenantConfig, ['firebase_destinations' => [['active' => true, 'project_id' => 'bad', 'database_url' => 'https://ok.local', 'api_path' => '/hospitals/../secret']]])),
    static fn (): array => $binding->bindSuccessfulMutation($tenantConfig, ['mutation_key' => 'mutation-002'], [['original_name' => 'bad.png', 'storage_path' => 'https://cdn.example/bad.png', 'file_size' => 1]]),
] as $probe) {
    try {
        $probe();
    } catch (RuntimeException) {
        $blocked++;
    }
}
if ($blocked !== 4) {
    throw new RuntimeException('Unsafe tenant destination or media probes were not blocked.');
}

if (preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE TABLE|DROP TABLE|mysqli_query|->query\(|prepare\()/i', $source) === 1) {
    throw new RuntimeException('Tenant destination binding must remain source-only and database-write free.');
}
if (str_contains($source, '/phases/') || str_contains($source, 'PhaseAiJobStore')) {
    throw new RuntimeException('Phase Manager control-plane marker found in tenant destination binding source.');
}

echo json_encode([
    'hospital_code_normalized' => true,
    'active_firebase_destination_selected' => true,
    'firebase_https_required' => true,
    'firebase_api_path_safe' => true,
    'relative_media_metadata_bound_after_validation' => true,
    'source_only_no_database_writes' => true,
    'phase_manager_control_plane_untouched' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
