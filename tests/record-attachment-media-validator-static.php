<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$validatorPath = $root . '/backend/app/Security/FileSecurityValidator.php';
$servicePath = $root . '/backend/app/Services/Record/RecordAttachmentService.php';
$validator = (string) file_get_contents($validatorPath);
$service = (string) file_get_contents($servicePath);

$requiredValidatorMarkers = [
    'final class FileSecurityValidator',
    'validateMediaReference',
    'assertStoredReference',
    'ALLOWED_MIME_TYPES',
    'unsupported_media_type',
    'invalid_media_path',
    'invalid_media_size',
    'invalid_media_checksum',
    'str_starts_with($path, \'/\')',
    "preg_match('/^[a-z][a-z0-9+.-]*:/i'",
    "preg_match('/^[a-f0-9]{64}$/'",
];

foreach ($requiredValidatorMarkers as $marker) {
    if (!str_contains($validator, $marker)) {
        throw new RuntimeException('Missing media validator marker: ' . $marker);
    }
}

$requiredServiceMarkers = [
    'use App\Security\FileSecurityValidator;',
    'private readonly FileSecurityValidator $fileSecurity = new FileSecurityValidator()',
    '$mediaPayload[\'stored_name\'] = $mediaPayload[\'stored_name\'] ?? $attachmentKey;',
    '$media = $this->fileSecurity->validateMediaReference($mediaPayload);',
    '$this->fileSecurity->assertStoredReference($attachment);',
    'foreach ($attachments as $attachment) {',
    '$mimeType = $media[\'mime_type\'];',
    '$checksum = $media[\'checksum_sha256\'];',
    'attachment_storage_unavailable',
];

foreach ($requiredServiceMarkers as $marker) {
    if (!str_contains($service, $marker)) {
        throw new RuntimeException('Missing attachment service marker: ' . $marker);
    }
}

if (preg_match('/Attachment size exceeds|storage path are required|\\.\\.\\)|\\$this->db->error/', $service) === 1) {
    throw new RuntimeException('RecordAttachmentService still contains legacy ad hoc media validation.');
}

echo "record attachment media validator static checks passed\n";
