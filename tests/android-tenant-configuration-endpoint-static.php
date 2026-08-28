<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$endpointSource = file_get_contents('/var/www/html/rbms.com/api/mobile/tenant-configuration/index.php');
$clientMediaSource = file_get_contents('/var/www/html/rbms.com/_Mobile/client-media.php');
$vrpUploadSource = file_get_contents('/var/www/html/rbms.com/_Mobile/rbmsv4-vrp/upload-image.php');
$vrpViewerSource = file_get_contents('/var/www/html/rbms.com/_Mobile/rbmsv4-vrp/view.php');

if (!is_string($foundationSource) || !is_string($endpointSource) || !is_string($clientMediaSource) || !is_string($vrpUploadSource) || !is_string($vrpViewerSource)) {
    throw new RuntimeException('Android tenant configuration sources could not be read.');
}

$foundationMarkers = [
    'CREATE TABLE IF NOT EXISTS builder_android_client_app',
    'client_app_code VARCHAR(40) NOT NULL UNIQUE',
    'firebase_project_id VARCHAR(120) NOT NULL',
    'firebase_database_url VARCHAR(255) NOT NULL',
    'banner_image_url TEXT NULL',
    'login_background_image_url TEXT NULL',
    'geofence_latitude DECIMAL(10,7) NULL',
    'geofence_longitude DECIMAL(10,7) NULL',
    'geofence_max_radius_meters INT UNSIGNED NOT NULL DEFAULT 100',
    'RBMS-VRP',
    'RBMS-CAB',
    '_Mobile/',
    'splash/splash-1.jpg',
    'http://192.168.1.70/rbms.com/api/mobile/tenant-configuration/',
];

foreach ($foundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Foundation is missing Android tenant marker: ' . $marker);
    }
}

$endpointMarkers = [
    'BUILDERX_SKIP_SESSION_START',
    'Access-Control-Allow-Methods: POST, OPTIONS',
	    'client_app_code',
	    'project_setting',
	    'android_welcome_title',
	    'android_welcome_description',
	    'android_banner_image_url',
    'android_login_background_image_url',
    'android_splash_screen_image_url_1',
    'android_splash_screen_image_url_2',
    'android_splash_screen_image_url_3',
    "'settings' => \$publicSettings",
    'CLIENT_APP_CODE_NOT_FOUND',
    'builder_android_client_app',
	    'splash_images',
	    'welcome_title',
	    'welcome_description',
	    "'welcome' =>",
	    'banner_image_url',
    'login_background_image_url',
    'geofence_latitude',
    'geofence_longitude',
    'geofence_max_radius_meters',
    'firebase',
    'rbms_mobile_login_project_key',
    "'project_key' => \$loginProjectKey",
    'SELECT project_key',
    'apk_download_url',
    'uploader_target_url',
    'image_viewer_url',
];

foreach ($endpointMarkers as $marker) {
    if (!str_contains($endpointSource, $marker)) {
        throw new RuntimeException('Tenant configuration endpoint is missing marker: ' . $marker);
    }
}

$clientMediaMarkers = [
    'rbms_client_media_upload',
    'rbms_client_media_view',
    'Image URL must point to this client media folder.',
    '_Media/',
];

foreach ($clientMediaMarkers as $marker) {
    if (!str_contains($clientMediaSource, $marker)) {
        throw new RuntimeException('Client media handler is missing marker: ' . $marker);
    }
}

foreach (['RBMS_MOBILE_CLIENT_ID', 'rbmsv4-vrp'] as $marker) {
    if (!str_contains($vrpUploadSource, $marker) || !str_contains($vrpViewerSource, $marker)) {
        throw new RuntimeException('VRP client media wrapper is missing marker: ' . $marker);
    }
}

echo json_encode([
    'android_client_app_registry_seeded' => true,
    'tenant_configuration_endpoint_present' => true,
    'client_media_endpoints_present' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
