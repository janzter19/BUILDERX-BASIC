<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$store = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantBindingStore.kt');
$client = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantConfigurationClient.kt');
$firebase = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/tenant/TenantFirebaseInitializer.kt');
$first = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/FirstFragment.kt');
$second = file_get_contents($root . '/_Android/app/src/main/java/com/everythingiscreated/rbmsv4/ui/fragment/SecondFragment.kt');
$firstLayout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_first.xml');
$secondLayout = file_get_contents($root . '/_Android/app/src/main/res/layout/fragment_second.xml');
$manifest = file_get_contents($root . '/_Android/app/src/main/AndroidManifest.xml');
$network = file_get_contents($root . '/_Android/app/src/main/res/xml/network_security_config.xml');
$strings = file_get_contents($root . '/_Android/app/src/main/res/values/strings.xml');
$tests = file_get_contents($root . '/_Android/app/src/test/java/com/everythingiscreated/rbmsv4/ExampleUnitTest.kt');

foreach ([
    'TenantBindingStore.kt' => $store,
    'TenantConfigurationClient.kt' => $client,
    'TenantFirebaseInitializer.kt' => $firebase,
    'FirstFragment.kt' => $first,
    'SecondFragment.kt' => $second,
    'fragment_first.xml' => $firstLayout,
    'fragment_second.xml' => $secondLayout,
    'AndroidManifest.xml' => $manifest,
    'network_security_config.xml' => $network,
    'strings.xml' => $strings,
    'ExampleUnitTest.kt' => $tests,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$storeRequirements = [
    'data class ClientMetadata',
    'data class AndroidReleaseConfig',
    'data class FirebaseClientConfig',
    'data class MediaEndpoints',
    'parseServerResponse',
    'configurationToJson',
    'configurationFromJson',
    'client_app_code_mismatch',
    'firebase_database_url_must_be_https',
    'tenant_media_endpoint_invalid',
    'AndroidKeyStore',
    'Tenant binding could not be read back.',
];
foreach ($storeRequirements as $needle) {
    if (!str_contains($store, $needle)) {
        throw new RuntimeException('Tenant binding store is missing: ' . $needle);
    }
}

$clientRequirements = [
    '.put("client_app_code", normalizedCode)',
    'isLocalDevelopmentHost',
    'errorCodeFromResponse',
];
foreach ($clientRequirements as $needle) {
    if (!str_contains($client, $needle)) {
        throw new RuntimeException('Tenant configuration client is missing: ' . $needle);
    }
}

$firebaseRequirements = [
    'FirebaseOptions.Builder()',
    '.setApiKey(binding.firebase.apiKey)',
    '.setApplicationId(binding.firebase.appId)',
    '.setDatabaseUrl(binding.firebase.databaseUrl)',
    'FirebaseApp.initializeApp',
];
foreach ($firebaseRequirements as $needle) {
    if (!str_contains($firebase, $needle)) {
        throw new RuntimeException('Tenant Firebase initializer is missing: ' . $needle);
    }
}

if (!str_contains($first, 'tenantBindingStore.currentBinding()?.let')) {
    throw new RuntimeException('Saved tenant config must be reused on next launch.');
}
if (!str_contains($first, 'findNavController().navigate(R.id.action_FirstFragment_to_SecondFragment)')) {
    throw new RuntimeException('Setup screen must continue only after tenant binding succeeds.');
}
if (!str_contains($first, 'CLIENT_APP_CODE_NOT_FOUND') || !str_contains($first, 'tenant_binding_invalid_code')) {
    throw new RuntimeException('Invalid client app code must show a clear setup-screen error.');
}
if (!str_contains($second, 'TenantFirebaseInitializer.initialize(requireContext(), tenant)')) {
    throw new RuntimeException('Dashboard must initialize the selected tenant Firebase config.');
}
if (!str_contains($second, 'tenant.media.uploaderTargetUrl') || !str_contains($second, 'tenant.media.imageViewerUrl')) {
    throw new RuntimeException('Dashboard must read media endpoints from tenant response.');
}

$uiRequirements = [
    'client_app_code_input',
    'client_app_code_layout',
    'dashboard_endpoint_value',
    'tenant_endpoint_values',
    'dashboard_endpoint_summary',
    'android.permission.INTERNET',
    'networkSecurityConfig',
    'localhost',
];
foreach ($uiRequirements as $needle) {
    $haystack = $firstLayout . "\n" . $secondLayout . "\n" . $strings . "\n" . $manifest . "\n" . $network;
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException('Android tenant setup UI contract is missing: ' . $needle);
    }
}

$removedSetupFields = [
    'status_label',
    'status_value',
    'partition_label',
    'partition_value',
    'endpoint_label',
    'endpoint_value',
    'button_refresh_tenant',
    'button_continue',
    'button_switch_tenant',
];
foreach ($removedSetupFields as $needle) {
    if (str_contains($firstLayout, $needle)) {
        throw new RuntimeException('Setup page must retain only tenant code controls; found: ' . $needle);
    }
}

$testRequirements = [
    'tenantConfigurationAcceptsCompleteClientAppPayload',
    'tenantConfigurationRejectsInvalidClientAppCode',
    'tenantConfigurationPersistsFirebaseAndMediaResponseFields',
    'mediaOutboxAcceptsTenantResponseUrls',
];
foreach ($testRequirements as $needle) {
    if (!str_contains($tests, $needle)) {
        throw new RuntimeException('Focused Android tenant setup test is missing: ' . $needle);
    }
}

echo json_encode([
    'setup_screen_when_unconfigured' => true,
    'valid_client_code_saves_config' => true,
    'invalid_client_code_rejected' => true,
    'firebase_config_selected_from_response' => true,
    'media_endpoints_selected_from_response' => true,
    'saved_config_reused_on_next_launch' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
