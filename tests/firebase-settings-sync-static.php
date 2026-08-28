<?php
declare(strict_types=1);

define('BUILDERX_SKIP_SESSION_START', true);
require_once dirname(__DIR__) . '/app/foundation.php';

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$scriptSource = file_get_contents($root . '/scripts/firebase-settings-sync.mjs');

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($scriptSource)) {
    throw new RuntimeException('Firebase settings sync sources could not be read.');
}

$sourceMarkers = [
    'function bx_settings_firebase_payload',
    'function bx_sync_settings_to_firebase',
    'function bx_firebase_setting_is_public',
    'project_setting_media',
    'project_media',
    'media_count',
    'scripts/firebase-settings-sync.mjs',
    'service_account_path',
    'settings_document',
    "collection('project_setting')",
    "firebase_collection: 'project_setting'",
    'server_synced_at: FieldValue.serverTimestamp()',
    'const readBack = await documentRef.get()',
    'function bx_admin_settings_firebase_step',
    'System settings were not saved; Firebase needs attention.',
    'No direct MySQL settings write was performed; TRAVERSE will project the acknowledged Firebase document.',
    '$firebaseOverrides[$settingName] = $newValue',
];

$combinedSource = $foundationSource . "\n" . $adminSource . "\n" . $scriptSource;
foreach ($sourceMarkers as $marker) {
    if (!str_contains($combinedSource, $marker)) {
        throw new RuntimeException('Missing Firebase settings sync marker: ' . $marker);
    }
}

$settingsActionStart = strpos($adminSource, 'if ($action === \'save_system_settings\')');
$settingsActionEnd = $settingsActionStart === false ? false : strpos($adminSource, 'if ($action === \'resync_project_bed\')', $settingsActionStart);
if ($settingsActionStart === false || $settingsActionEnd === false) {
    throw new RuntimeException('Settings action boundaries could not be located.');
}
$settingsActionSource = substr($adminSource, $settingsActionStart, $settingsActionEnd - $settingsActionStart);
if (preg_match('/(?:INSERT|UPDATE|DELETE)\s+(?:INTO\s+)?(?:builder_system_setting|project_setting_media|project_setting)\b/i', $settingsActionSource)) {
    throw new RuntimeException('Settings action still contains a direct MySQL mutation.');
}

$projectKey = (string) (bx_db()->GetOne("SELECT project_key FROM builder_project WHERE project_status <> 'DELETED' ORDER BY x_id ASC LIMIT 1") ?: '');
$payload = bx_settings_firebase_payload($projectKey);
$encodedPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

foreach (['firebase_service_account_path', 'password_reset_token_minutes', 'template_presets'] as $forbidden) {
    if (str_contains($encodedPayload, $forbidden)) {
        throw new RuntimeException('Firebase settings payload contains secret or excluded setting: ' . $forbidden);
    }
}

if (($payload['summary']['system_count'] ?? 0) < 1 || ($payload['summary']['project_count'] ?? 0) < 1 || ($payload['summary']['media_count'] ?? 0) < 1) {
    throw new RuntimeException('Firebase settings payload must include system, project, and media settings.');
}

if (($payload['project_settings']['groups']['android']['android_tenant_configuration_endpoint_url'] ?? '') === '') {
    throw new RuntimeException('Firebase settings payload must include Android project settings.');
}

if (($payload['project_media']['groups']['media']['media_uploader_target_url'] ?? '') === '') {
    throw new RuntimeException('Firebase settings payload must include project media settings.');
}

echo json_encode([
    'firebase_settings_sync_script_present' => true,
    'firebase_settings_payload_secret_safe' => true,
    'firebase_settings_project_scoped' => true,
    'firebase_settings_media_project_scoped' => true,
    'system_count' => (int) $payload['summary']['system_count'],
    'project_count' => (int) $payload['summary']['project_count'],
    'media_count' => (int) $payload['summary']['media_count'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
