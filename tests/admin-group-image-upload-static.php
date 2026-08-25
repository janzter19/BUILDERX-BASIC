<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');
$mediaRulesSource = file_get_contents($root . '/docs/project/ui-ux-media.md');
$classicUploaderPath = '/var/www/html/rbms.com/upload-image.php';
$classicViewerPath = '/var/www/html/rbms.com/view.php';
$classicUploaderSource = is_file($classicUploaderPath) ? file_get_contents($classicUploaderPath) : '';
$classicViewerSource = is_file($classicViewerPath) ? file_get_contents($classicViewerPath) : '';

if (!is_string($foundationSource) || !is_string($adminSource) || !is_string($frontendSource) || !is_string($mediaRulesSource) || !is_string($classicUploaderSource) || !is_string($classicViewerSource)) {
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
];

$requiredAdminMarkers = [
    "['media_uploader_target_url', 'http://localhost/rbms.com/upload-image.php', 'media']",
    "['media_image_viewer_url', 'http://localhost/rbms.com/view.php', 'media']",
    'Group image source must be a full uploaded image URL.',
    'UPDATE project_user_group SET group_image_path = ?',
    'Group image read-back verification failed.',
    'Administrator saved group image URL.',
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
    'base PHP viewer URL `http://localhost/rbms.com/view.php`',
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
    'view.php?d=XS&url=http%3A%2F%2Flocalhost%2Frbms.com%2F_Media%2Fproject_group',
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
];

$requiredClassicViewerMarkers = [
    "\$_GET['url']",
    'FILTER_VALIDATE_URL',
    'renderResizedImage',
    'imagecopyresampled',
    'Content-Type: image/png',
    'Content-Type: image/jpeg',
    'Only local RBMS media URLs can be viewed.',
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

echo json_encode([
    'group_image_schema_present' => true,
    'group_image_settings_contract_present' => true,
    'group_image_local_resize_present' => true,
    'group_image_ui_present' => true,
    'ui_ux_media_rules_present' => true,
    'classic_media_endpoint_present' => true,
], JSON_THROW_ON_ERROR) . PHP_EOL;
