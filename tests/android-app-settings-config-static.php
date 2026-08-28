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
	    'android_welcome_title',
	    'android_welcome_description',
	    'android_current_version_code',
    'android_min_supported_version_code',
    'android_force_update_enabled',
    'android_release_acknowledgement_required',
    'android_geofence_required',
    'android_geofence_latitude',
    'android_geofence_longitude',
    'android_geofence_max_radius_meters',
    'android_update_apk_download_path',
    'android_banner_image_url',
    'android_login_background_image_url',
    'android_splash_screen_image_url_1',
    'android_splash_screen_image_url_2',
    'android_splash_screen_image_url_3',
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
	    "'android_welcome_title'",
	    "'android_welcome_description'",
	    'data-skip-submit-confirmation="true"',
	    "title: 'Confirm system settings sync'",
	    "confirmLabel: 'Save & Sync'",
	    "title: 'Release Gates And Assets'",
    "title: 'Geofencing'",
    "title: 'Offline And Sync'",
    "android: 'Android app tenant bootstrap, release gate, offline queue, and sync defaults.'",
];

foreach ($frontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('System Settings UI is missing Android marker: ' . $marker);
    }
}

foreach (["title: 'Release Gates'", "title: 'Release Assets'"] as $removedMarker) {
    if (str_contains($frontendSource, $removedMarker)) {
        throw new RuntimeException('System Settings UI still has separate Android release section marker: ' . $removedMarker);
    }
}

$adminValidationMarkers = [
	    "'Android version code settings must be positive whole numbers.'",
	    "'Android welcome title must be 1 to 120 characters.'",
	    "'Android welcome description must be 240 characters or less.'",
	    "'Android timing settings must be between 5 and 86400 seconds.'",
    "'Android package name is invalid.'",
    "'Android tenant configuration endpoint must be a valid HTTPS URL, or private network HTTP URL for development.'",
    "'Android geofence latitude must be between -90 and 90.'",
    "'Android geofence longitude must be between -180 and 180.'",
    "'Android geofence max radius must be between 1 and 1000000 meters.'",
    "'Android APK download path must be an HTTP(S) URL or root-relative .apk path.'",
    "'Android image links must be valid HTTP(S) URLs.'",
    "\$androidEndpointIsPrivateHttp = \$androidEndpointScheme === 'http'",
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
    "setting_group NOT IN ('android', 'media')",
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
