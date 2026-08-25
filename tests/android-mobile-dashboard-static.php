<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$second = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/SecondFragment.kt');
$layout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_second.xml');
$strings = file_get_contents($root . '/_Android/app/src/main/res/values/strings.xml');
$runtime = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantRuntimeBinding.kt');
$offlineModels = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantOfflineModels.kt');
$offlineStore = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/TenantOfflineStore.kt');

foreach ([
    'SecondFragment.kt' => $second,
    'fragment_second.xml' => $layout,
    'strings.xml' => $strings,
    'TenantRuntimeBinding.kt' => $runtime,
    'TenantOfflineModels.kt' => $offlineModels,
    'TenantOfflineStore.kt' => $offlineStore,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$fragmentRequirements = [
    'TenantRuntimeBinding.apply(tenant)',
    'tenantBindingStore.tenantCacheKey("assigned_tasks")',
    'dashboardCommonTaskValue.text',
    'tenantBindingStore.tenantQueueKey("stage_responses")',
    'tenantBindingStore.tenantQueueKey("chat_media_account")',
    'tenant.mediaBasePath',
    'renderQueuedActionFeedback',
    'renderQueuedMediaFeedback',
    'retryPendingOfflineWork',
    'tenantOfflineStore.purgeCurrentTenantPartitions()',
    'findNavController().navigate(R.id.action_SecondFragment_to_FirstFragment)',
];
foreach ($fragmentRequirements as $needle) {
    if (!str_contains($second, $needle)) {
        throw new RuntimeException('Android dashboard fragment is missing: ' . $needle);
    }
}

$layoutRequirements = [
    'dashboard_assignments_value',
    'button_common_task',
    'dashboard_stage_value',
    'button_stage_done',
    'button_stage_blocked',
    'dashboard_engagement_value',
    'button_chat',
    'button_media',
    'button_account',
    'button_release_prompt',
    'button_geofence',
    'dashboard_action_feedback',
];
foreach ($layoutRequirements as $needle) {
    if (!str_contains($layout, $needle)) {
        throw new RuntimeException('Android dashboard layout is missing: ' . $needle);
    }
}

$stringRequirements = [
    'dashboard_common_task_gate',
    'dashboard_stage_done_feedback',
    'dashboard_stage_blocked_feedback',
    'dashboard_chat_feedback',
    'dashboard_media_feedback',
    'dashboard_account_feedback',
    'dashboard_release_prompt_feedback',
    'dashboard_geofence_feedback',
    'dashboard_offline_queue_summary',
    'dashboard_offline_retry_feedback',
];
foreach ($stringRequirements as $needle) {
    if (!str_contains($strings, $needle)) {
        throw new RuntimeException('Android dashboard string is missing: ' . $needle);
    }
}

if (!str_contains($runtime, 'private var currentBinding: TenantBindingStore.TenantBinding?')) {
    throw new RuntimeException('Runtime tenant binding must keep post-login actions tenant-scoped.');
}

$offlineRequirements = [
    'enum class TenantOfflineStatus',
    'TenantOfflineStatus.QUEUED',
    'TenantOfflineStatus.RETRYING',
    'TenantOfflineStatus.COMPLETED',
    'TenantOfflineStatus.FAILED',
    'TenantOfflineStatus.CONFLICT',
    'fun idempotencyKey(',
    'fun retryTransition(',
    'cross_tenant_media_path',
    'fun onlineFirstRead(',
    'fun queueMutation(',
    'fun queueMediaUpload(',
    'fun markNextRetryAttempt(',
    'fun purgeCurrentTenantPartitions()',
];
foreach ($offlineRequirements as $needle) {
    if (!str_contains($offlineModels . $offlineStore, $needle)) {
        throw new RuntimeException('Android offline implementation is missing: ' . $needle);
    }
}

echo json_encode([
    'tenant_dashboard_bound' => true,
    'assigned_task_queue_visible' => true,
    'stage_chat_media_account_actions_present' => true,
    'release_and_geofence_feedback_present' => true,
    'offline_queue_retry_and_media_outbox_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
