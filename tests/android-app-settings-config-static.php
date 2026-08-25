<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource)) {
    throw new RuntimeException('Android app settings sources could not be read.');
}

$settingNames = [
    'android_app_package_name',
    'android_tenant_configuration_endpoint_url',
    'android_current_version_code',
    'android_min_supported_version_code',
    'android_force_update_enabled',
    'android_release_acknowledgement_required',
    'android_geofence_required',
    'android_update_apk_download_path',
    'android_splash_screen_image_url_1',
    'android_splash_screen_image_url_2',
    'android_splash_screen_image_url_3',
    'android_splash_screen_image_url_4',
    'android_offline_queue_enabled',
    'android_offline_retry_interval_seconds',
    'android_dashboard_refresh_seconds',
    'android_media_upload_enabled',
];

foreach ($settingNames as $settingName) {
    if (!str_contains($foundationSource, $settingName)) {
        throw new RuntimeException('Foundation seed is missing Android setting: ' . $settingName);
    }
    if (!str_contains($adminSource, $settingName)) {
        throw new RuntimeException('Administrator save path is missing Android setting: ' . $settingName);
    }
    if (!str_contains($frontendSource, $settingName)) {
        throw new RuntimeException('System Settings UI is missing Android setting: ' . $settingName);
    }
}

$frontendMarkers = [
    "'android'",
    'settingMenuGroupDefinitions',
    "label: 'Mobile'",
    "groups: ['android', 'media', 'firebase']",
    "title: 'Tenant Bootstrap'",
    "title: 'Release Gates'",
    "title: 'Release Assets'",
    "title: 'Offline And Sync'",
    "android: 'Android app tenant bootstrap, release gate, offline queue, and sync defaults.'",
];

foreach ($frontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('System Settings UI is missing Android marker: ' . $marker);
    }
}

$adminValidationMarkers = [
    "'Android version code settings must be positive whole numbers.'",
    "'Android timing settings must be between 5 and 86400 seconds.'",
    "'Android package name is invalid.'",
    "'Android tenant configuration endpoint must be a valid HTTPS URL, or localhost HTTP URL for development.'",
    "'Android APK download path must be an HTTP(S) URL or root-relative .apk path.'",
    "'Android splash image links must be valid HTTP(S) URLs.'",
    "\$androidEndpointIsLocalHttp = \$androidEndpointScheme === 'http'",
];

foreach ($adminValidationMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Administrator validation is missing Android marker: ' . $marker);
    }
}

$projectSettingMarkers = [
    'CREATE TABLE IF NOT EXISTS project_setting',
    'UNIQUE KEY uq_project_setting_name',
    'function bx_android_project_setting_defaults',
    'function bx_seed_android_project_settings',
    'INSERT INTO project_setting',
    'UPDATE project_setting SET setting_value',
    "setting_group <> 'android'",
    "setting_group = 'android'",
    'name="project_key"',
];

foreach ($projectSettingMarkers as $marker) {
    $haystack = str_starts_with($marker, 'name=')
        ? $frontendSource
        : $foundationSource . "\n" . $adminSource;
    if (!str_contains($haystack, $marker)) {
        throw new RuntimeException('Android project_setting path is missing marker: ' . $marker);
    }
}

echo json_encode([
    'android_settings_seeded' => true,
    'android_settings_ui_grouped' => true,
    'android_settings_validated' => true,
    'android_settings_project_scoped' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
