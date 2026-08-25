<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$validatorPath = $root . '/backend/app/Security/FileSecurityValidator.php';
$bindingPath = $root . '/backend/app/Services/Integration/TenantDestinationBinding.php';
$syncContractPath = $root . '/backend/app/Services/Synchronization/TenantSyncRetryContract.php';
$backupClaimPath = $root . '/backend/app/Services/Backup/DailyBackupClaimService.php';
$backupJobPath = $root . '/backend/app/Jobs/BackupDatabaseJob.php';
$backupControllerPath = $root . '/backend/app/Http/Controllers/Api/V1/BackupController.php';

require_once $validatorPath;
require_once $bindingPath;
require_once $syncContractPath;
require_once $backupClaimPath;
require_once $backupJobPath;
require_once $backupControllerPath;

$tenantConfig = [
    'hospital_code' => ' hsp 006 ',
    'tenant_key' => 'tenant-hsp-006',
    'branch_key' => '11111111-1111-4111-8111-111111111116',
    'project_key' => '22222222-2222-4222-8222-222222222226',
    'timezone' => 'Asia/Manila',
    'firebase' => [
        'project_id' => 'rbms-hsp-006',
        'database_url' => 'https://hsp006.firebaseio.com',
        'api_path' => '/hospitals/HSP006/sync',
    ],
];

$sync = new App\Services\Synchronization\TenantSyncRetryContract();
$syncResults = $sync->evaluate($tenantConfig, [
    ['mutation_key' => 'queued-001', 'status' => 'queued'],
    ['mutation_key' => 'retry-001', 'status' => 'failed', 'attempts' => 1],
    ['mutation_key' => 'failed-001', 'status' => 'failed', 'attempts' => 3],
    ['mutation_key' => 'conflict-001', 'source_version' => 'mysql-2', 'destination_version' => 'firebase-1'],
    ['mutation_key' => 'done-001', 'status' => 'completed'],
    ['mutation_key' => 'queued-001', 'status' => 'queued'],
], 3);

$statuses = array_column($syncResults, 'status');
foreach (['queued', 'retry', 'failed', 'conflict', 'completed'] as $expectedStatus) {
    if (!in_array($expectedStatus, $statuses, true)) {
        throw new RuntimeException('Missing visible sync status: ' . $expectedStatus);
    }
}
if (($syncResults[0]['tenant']['hospital_code'] ?? '') !== 'HSP006') {
    throw new RuntimeException('Tenant scope was not preserved on sync retry result.');
}
if (($syncResults[1]['next_attempt_allowed'] ?? false) !== true || ($syncResults[2]['next_attempt_allowed'] ?? true) !== false) {
    throw new RuntimeException('Retry bounds were not enforced.');
}
if (($syncResults[5]['reason'] ?? '') !== 'duplicate_queue_entry') {
    throw new RuntimeException('Duplicate queued mutation was not resolved as a conflict.');
}

$claims = new App\Services\Backup\DailyBackupClaimService();
$firstClaim = $claims->claimForLogin($tenantConfig, '2026-08-23T07:00:00+00:00');
$secondClaim = $claims->claimForLogin($tenantConfig, '2026-08-23T11:00:00+00:00', [$firstClaim]);
if (($firstClaim['backup_claim_key'] ?? '') !== 'daily-backup:HSP006:tenant-hsp-006:2026-08-23') {
    throw new RuntimeException('Daily backup claim key is not tenant/date deterministic.');
}
if (($firstClaim['status'] ?? '') !== 'claimed' || ($secondClaim['status'] ?? '') !== 'already_claimed') {
    throw new RuntimeException('Daily backup duplicate claim behavior failed.');
}

$job = new App\Jobs\BackupDatabaseJob();
$jobClaim = $job->claimDailyLoginBackup($tenantConfig, '2026-08-23T00:30:00+00:00', [$firstClaim['backup_claim_key']]);
if (($jobClaim['status'] ?? '') !== 'already_claimed') {
    throw new RuntimeException('Backup job does not consume daily claim service.');
}

$controller = new App\Http\Controllers\Api\V1\BackupController();
$controllerClaim = $controller->claimDailyLoginBackup([
    'user' => ['user_key' => 'user-001', 'active' => true],
    'tenant' => $tenantConfig,
    'login_at' => '2026-08-24T00:00:00+08:00',
]);
if (($controllerClaim['status'] ?? '') !== 'claimed') {
    throw new RuntimeException('Backup controller did not authorize and claim login backup.');
}

$authBlocked = false;
try {
    $controller->claimDailyLoginBackup(['tenant' => $tenantConfig]);
} catch (RuntimeException $error) {
    $authBlocked = $error->getMessage() === 'backup_claim_login_required';
}
if (!$authBlocked) {
    throw new RuntimeException('Backup controller did not require an authenticated login.');
}

foreach ([$syncContractPath, $backupClaimPath, $backupJobPath, $backupControllerPath] as $path) {
    $source = (string) file_get_contents($path);
    if (preg_match('/\b(INSERT|UPDATE|DELETE|ALTER|CREATE TABLE|DROP TABLE|mysqli_query|->query\(|prepare\()/i', $source) === 1) {
        throw new RuntimeException('Source-only TODO introduced database write marker in ' . basename($path));
    }
    if (str_contains($source, '/phases/') || str_contains($source, 'PhaseAiJobStore')) {
        throw new RuntimeException('Phase Manager control-plane marker found in ' . basename($path));
    }
}

echo json_encode([
    'tenant_sync_retry_visible_statuses' => true,
    'retry_bounds_enforced' => true,
    'duplicate_queued_mutation_conflict' => true,
    'daily_backup_claim_key_once_per_tenant_date' => true,
    'duplicate_login_claim_already_claimed' => true,
    'backup_controller_authorization_required' => true,
    'source_only_no_database_writes' => true,
    'phase_manager_control_plane_untouched' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
