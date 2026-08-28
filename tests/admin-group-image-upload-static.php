<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$groupWriterSource = file_get_contents($root . '/scripts/firebase-admin-project-group-write.mjs');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$mediaRulesSource = file_get_contents($root . '/docs/project/ui-ux-media.md');
$mediaSkillSource = file_get_contents($root . '/.agents/skills/ui-ux-media/SKILL.md');
$classicUploaderPath = '/var/www/html/rbms.com/image-upload.php';
$classicViewerPath = '/var/www/html/rbms.com/image-view.php';
$classicUploaderSource = is_file($classicUploaderPath) ? file_get_contents($classicUploaderPath) : '';
$classicViewerSource = is_file($classicViewerPath) ? file_get_contents($classicViewerPath) : '';

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($groupWriterSource) || !is_string($frontendSource) || !is_string($mediaRulesSource) || !is_string($mediaSkillSource) || !is_string($classicUploaderSource) || !is_string($classicViewerSource)) {
    throw new RuntimeException('Administrator group image upload source could not be read.');
}

$requiredFoundationMarkers = [
    'group_image_path VARCHAR(500) NULL',
    'group_image_original_name VARCHAR(255) NULL',
    'group_image_mime_type VARCHAR(120) NULL',
    'group_image_byte_size BIGINT UNSIGNED NULL',
    'group_image_sha256 CHAR(64) NULL',
    'group_image_uploaded_at TIMESTAMP NULL',
    "bx_add_column_if_missing('project_user_group', 'group_image_path'",
    'CREATE TABLE IF NOT EXISTS project_setting_media',
    'function bx_seed_media_project_settings',
    'function bx_media_url_has_loopback_host',
    'function bx_project_media_uploaded_url',
    'INSERT INTO project_setting_media',
];

$requiredAdminMarkers = [
    'UPDATE project_setting_media SET setting_value',
    "'project_setting_media' AS setting_source",
    'Group image source must be a full uploaded image URL.',
    'bx_project_media_uploaded_url($projectKey, $groupImageUrl)',
    'Media URLs must use the configured public media host, not localhost or loopback.',
    "'group_image_path' => " . '$groupImageUrl',
    "'group_image_sha256' => " . '$groupImageSha256',
    "COALESCE(g.group_image_path, '') AS group_image_path",
    "COALESCE(g.group_image_sha256, '') AS group_image_sha256",
];

$requiredFrontendMarkers = [
    'adminSettingValue',
    'uploadedImageViewerUrl',
    'mediaUploadMaxLongSide = 1024',
    'resizeImageForMediaUpload',
    'imageDimensions',
    'longestSide <= mediaUploadMaxLongSide',
    'canvas.toBlob',
    'createImageBitmap',
    'GroupImageUploadField',
    'media_uploader_target_url',
    'media_image_viewer_url',
    'name="group_image_url"',
    'encType="multipart/form-data"',
    'accept="image/png,image/jpeg,image/webp,image/gif"',
    'Paste Image',
    'navigator.clipboard.read',
    'onPaste={handlePaste}',
    'uploadedImageSourceUrl',
    'groupImageViewerSizes',
    'imageViewerGroupKey',
    'imageViewerSize',
    'openGroupImageViewer',
    'groupImageViewerUrl(group, \'XS\')',
    'groupImageViewerUrl(selectedGroup, \'L\')',
    'setImageViewerSize(size)',
    'key={`${imageViewerGroup.group_key}-${imageViewerSize}`}',
    'src={groupImageViewerUrl(imageViewerGroup, imageViewerSize)}',
    'viewerUrl.includes(\'?\') ? \'&\' : \'?\'',
    'url=${encodedImageUrl}',
    'const previewUrl = pendingPreview || existingPreviewUrl',
    'Pending group image preview',
    'encodeURIComponent(imageUrl)',
    "body.append('source_table', 'project_group')",
    'Upload endpoint did not return a full uploaded image URL.',
    'rendered through the PHP image viewer',
];

$requiredMediaRuleMarkers = [
    'UI/UX Media Rules',
    'media_uploader_target_url',
    'media_image_viewer_url',
    'Local development must configure `media_uploader_target_url` and `media_image_viewer_url` in `project_setting_media`',
    'runtime code must not seed or fall back to a hardcoded host',
    '1024px',
    'upload the original file unchanged',
    'resize the image locally before upload',
    'longest side is exactly `1024px`',
    'immediate preview',
    'without exposing raw `_Media` URLs in visible UI',
    'render through the configured PHP viewer URL',
    'open an in-app image viewer modal',
    'clickable `XS`, `S`, `M`, `L`, and `XL` size controls',
    'return actual image bytes for a single requested size token',
    'append `d=<size>` and URL-encode the uploaded image URL',
    'configured viewer URL with `d=XS`',
];

$requiredMediaSkillMarkers = [
    'name: ui-ux-media',
    'project_setting_media',
    'media_uploader_target_url',
    'media_image_viewer_url',
    'Do not hardcode media hosts, paths, client folders, upload URLs, viewer URLs, LAN IP addresses, `localhost`, or `_Media` paths in UI logic.',
    'If `media_uploader_target_url` is empty, disable upload controls',
    'If `media_image_viewer_url` is empty, show the saved uploaded image URL directly.',
    'the longest side is exactly `1024px`',
    'The endpoint must return a full uploaded image URL',
    'Never persist only a filename, relative path, data URL, or base64 value.',
    'always URL-encoded',
    'Standard size tokens are `XS`, `S`, `M`, `L`, and `XL`.',
    'also apply `database-transaction`',
];

$requiredClassicUploaderMarkers = [
    'Content-Type: application/json',
    "\$_FILES['image']",
    '$sourceTable',
    "'_Media/' . \$sourceTable",
    "date('Y')",
    "date('m')",
    "date('d')",
    'move_uploaded_file',
    "'url' => \$url",
    "'uploaded_image_url' => \$url",
    'Upload host could not be resolved.',
];

$requiredClassicViewerMarkers = [
    "\$_GET['url']",
    'FILTER_VALIDATE_URL',
    'renderResizedImage',
    'imagecopyresampled',
    'Content-Type: image/png',
    'Content-Type: image/jpeg',
    'Only this configured media host can be viewed.',
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Missing group image schema marker: ' . $marker);
    }
}

foreach ($requiredAdminMarkers as $marker) {
    if (!str_contains($adminSource, $marker)) {
        throw new RuntimeException('Missing group image backend marker: ' . $marker);
    }
}

foreach ([
    "const imagePath = String(record.group_image_path || '').trim()",
    'group_image_path: imagePath',
    'group_image_original_name: imageOriginalName',
    'group_image_mime_type: imageMimeType',
    'group_image_byte_size: imageByteSize',
    'group_image_sha256: imageSha256',
    "group_image_uploaded_at: imagePath !== '' ? now : null",
    'project_group_firebase_readback_failed',
] as $marker) {
    if (!str_contains($groupWriterSource, $marker)) {
        throw new RuntimeException('Missing Firebase group image writer marker: ' . $marker);
    }
}

foreach ($requiredFrontendMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Missing group image frontend marker: ' . $marker);
    }
}

foreach ($requiredMediaRuleMarkers as $marker) {
    if (!str_contains($mediaRulesSource, $marker)) {
        throw new RuntimeException('Missing media rule marker: ' . $marker);
    }
}

foreach ($requiredMediaSkillMarkers as $marker) {
    if (!str_contains($mediaSkillSource, $marker)) {
        throw new RuntimeException('Missing media skill marker: ' . $marker);
    }
}

foreach ($requiredClassicUploaderMarkers as $marker) {
    if (!str_contains($classicUploaderSource, $marker)) {
        throw new RuntimeException('Missing classic uploader marker: ' . $marker);
    }
}

foreach ($requiredClassicViewerMarkers as $marker) {
    if (!str_contains($classicViewerSource, $marker)) {
        throw new RuntimeException('Missing classic viewer marker: ' . $marker);
    }
}

$frontendViewerStart = strpos($frontendSource, 'function mediaImageViewerBaseUrl');
$frontendViewerEnd = strpos($frontendSource, 'function uploadedImageSourceUrl', $frontendViewerStart === false ? 0 : $frontendViewerStart);
if ($frontendViewerStart === false || $frontendViewerEnd === false) {
    throw new RuntimeException('Could not locate mediaImageViewerBaseUrl block.');
}
$frontendViewerSource = substr($frontendSource, $frontendViewerStart, $frontendViewerEnd - $frontendViewerStart);
if (str_contains($frontendViewerSource, 'localhost') || str_contains($frontendViewerSource, '_Media')) {
    throw new RuntimeException('mediaImageViewerBaseUrl must not contain hardcoded localhost or _Media fallbacks.');
}

$mediaDefaultsStart = strpos($foundationSource, 'function bx_media_project_setting_defaults');
$mediaDefaultsEnd = strpos($foundationSource, 'function bx_seed_media_project_settings', $mediaDefaultsStart === false ? 0 : $mediaDefaultsStart);
$mediaLookupStart = strpos($foundationSource, 'function bx_project_media_setting');
$mediaLookupEnd = strpos($foundationSource, 'function bx_audit', $mediaLookupStart === false ? 0 : $mediaLookupStart);
if ($mediaDefaultsStart === false || $mediaDefaultsEnd === false || $mediaLookupStart === false || $mediaLookupEnd === false) {
    throw new RuntimeException('Could not locate project media setting blocks.');
}
$mediaRuntimeSource = substr($foundationSource, $mediaDefaultsStart, $mediaDefaultsEnd - $mediaDefaultsStart)
    . "\n" . substr($foundationSource, $mediaLookupStart, $mediaLookupEnd - $mediaLookupStart);
if (str_contains($mediaRuntimeSource, 'localhost') || str_contains($mediaRuntimeSource, '192.168') || str_contains($mediaRuntimeSource, '_Media')) {
    throw new RuntimeException('Project media setting runtime blocks must not contain hardcoded media hosts or _Media paths.');
}
if (str_contains($mediaRuntimeSource, 'return bx_setting($name, $default);')) {
    throw new RuntimeException('Project media setting lookup must not fall back to builder_system_setting.');
}

if (str_contains($classicUploaderSource, '192.168') || str_contains($classicUploaderSource, 'localhost') || str_contains($classicUploaderSource, '/rbms.com/image-upload.php')) {
    throw new RuntimeException('Classic uploader must not invent a hardcoded public media host or script path.');
}

echo json_encode([
    'group_image_schema_present' => true,
    'group_image_settings_contract_present' => true,
    'group_image_local_resize_present' => true,
    'group_image_ui_present' => true,
    'ui_ux_media_rules_present' => true,
    'classic_media_endpoint_present' => true,
], JSON_THROW_ON_ERROR) . PHP_EOL;
