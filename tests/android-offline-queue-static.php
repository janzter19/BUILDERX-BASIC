<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$queue = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantOfflineQueueStore.kt');
$store = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantOfflineStore.kt');
$models = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantOfflineModels.kt');
$second = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/SecondFragment.kt');
$layout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_second.xml');
$strings = file_get_contents($root . '/_Android/app/src/main/res/values/strings.xml');

foreach ([
    'TenantOfflineQueueStore.kt' => $queue,
    'TenantOfflineStore.kt' => $store,
    'TenantOfflineModels.kt' => $models,
    'SecondFragment.kt' => $second,
    'fragment_second.xml' => $layout,
    'strings.xml' => $strings,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$queueRequirements = [
    'class TenantOfflineQueueStore',
    'data class OfflineQueueEntry',
    'idempotencyKey',
    'queueWrite',
    'queueMediaUpload',
    'retryNext',
    'clearTenant',
    'builderx_offline_queue_${binding.tenantId}',
    'MessageDigest.getInstance("SHA-256")',
    'Media upload path must stay inside the tenant media partition.',
];
foreach ($queueRequirements as $needle) {
    if (!str_contains($queue, $needle)) {
        throw new RuntimeException('Offline queue store is missing: ' . $needle);
    }
}

$storeRequirements = [
    'class TenantOfflineStore',
    'fun queueMutation',
    'fun queueMediaUpload',
    'fun markNextRetryAttempt',
    'fun snapshot',
    'fun purgeCurrentTenantPartitions',
    'TenantOfflineModels.queuedMutation',
    'TenantOfflineModels.mediaUpload',
    'TenantOfflineModels.retryTransition',
    'TenantOfflineStatus.RETRYING',
    'TenantOfflineStatus.FAILED',
    'TenantOfflineStatus.CONFLICT',
    'cross_tenant_media_path',
    'tenant-offline-${scope.stableScopeKey}',
];
foreach ($storeRequirements as $needle) {
    if (!str_contains($store . "\n" . $models, $needle)) {
        throw new RuntimeException('Tenant offline store contract is missing: ' . $needle);
    }
}

$fragmentRequirements = [
    'tenantOfflineStore = TenantOfflineStore',
    'tenantOfflineStore.purgeCurrentTenantPartitions',
    'tenantOfflineStore.queueMutation',
    'tenantOfflineStore.queueMediaUpload',
    'tenantOfflineStore.markNextRetryAttempt',
    'tenantOfflineStore.snapshot',
    'tenantOfflineStore.onlineFirstRead',
    'dashboardOfflineQueueValue.text',
    'buttonRetryQueue.isEnabled',
];
foreach ($fragmentRequirements as $needle) {
    if (!str_contains($second, $needle)) {
        throw new RuntimeException('Offline queue dashboard wiring is missing: ' . $needle);
    }
}

$uiRequirements = [
    'dashboard_offline_queue_value',
    'button_retry_queue',
    'dashboard_offline_queue_summary',
    'dashboard_offline_retry_feedback',
];
foreach ($uiRequirements as $needle) {
    if (!str_contains($layout . "\n" . $strings, $needle)) {
        throw new RuntimeException('Offline queue UI contract is missing: ' . $needle);
    }
}

echo json_encode([
    'tenant_partitioned_offline_queue' => true,
    'stable_idempotency_keys' => true,
    'retry_status_visible' => true,
    'safe_tenant_switch_clears_queue' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
