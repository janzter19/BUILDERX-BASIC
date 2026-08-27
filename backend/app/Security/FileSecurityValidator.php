<?php
declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class FileSecurityValidator
{
    private const MAX_MEDIA_BYTES = 52_428_800;

    /** @var array<string, true> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => true,
        'image/png' => true,
        'image/webp' => true,
        'image/gif' => true,
        'application/pdf' => true,
        'text/plain' => true,
    ];

    /**
     * @param array<string, mixed> $payload
     * @return array{original_name:string,stored_name:string,storage_path:string,mime_type:?string,file_size:int,checksum_sha256:?string}
     */
    public function validateMediaReference(array $payload): array
    {
        $originalName = $this->safeFileName((string) ($payload['original_name'] ?? ''));
        $storedName = $this->safeFileName((string) ($payload['stored_name'] ?? $originalName));
        $storagePath = $this->safeRelativePath((string) ($payload['storage_path'] ?? ''));
        $mimeType = $this->safeMimeType($payload['mime_type'] ?? null);
        $fileSize = $this->safeFileSize($payload['file_size'] ?? 0);
        $checksum = $this->safeChecksum($payload['checksum_sha256'] ?? null);

        if ($originalName === '' || $storedName === '' || $storagePath === '') {
            throw new RuntimeException('invalid_media_reference');
        }

        return [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'checksum_sha256' => $checksum,
        ];
    }

    public function assertStoredReference(array $attachment): void
    {
        $this->validateMediaReference([
            'original_name' => $attachment['original_name'] ?? '',
            'stored_name' => $attachment['stored_name'] ?? '',
            'storage_path' => $attachment['storage_path'] ?? '',
            'mime_type' => $attachment['mime_type'] ?? null,
            'file_size' => $attachment['file_size'] ?? 0,
            'checksum_sha256' => $attachment['checksum_sha256'] ?? null,
        ]);
    }

    private function safeFileName(string $value): string
    {
        $name = basename(str_replace('\\', '/', trim($value)));
        $name = preg_replace('/[^\w.\- ]+/', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");
        return substr($name, 0, 255);
    }

    private function safeRelativePath(string $value): string
    {
        $path = trim(str_replace('\\', '/', $value));
        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new RuntimeException('invalid_media_path');
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new RuntimeException('invalid_media_path');
            }
        }
        return implode('/', $segments);
    }

    private function safeMimeType(mixed $value): ?string
    {
        $mimeType = strtolower(trim((string) $value));
        if ($mimeType === '') {
            return null;
        }
        if (!isset(self::ALLOWED_MIME_TYPES[$mimeType])) {
            throw new RuntimeException('unsupported_media_type');
        }
        return $mimeType;
    }

    private function safeFileSize(mixed $value): int
    {
        $fileSize = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (!is_int($fileSize) || $fileSize > self::MAX_MEDIA_BYTES) {
            throw new RuntimeException('invalid_media_size');
        }
        return $fileSize;
    }

    private function safeChecksum(mixed $value): ?string
    {
        $checksum = strtolower(trim((string) $value));
        if ($checksum === '') {
            return null;
        }
        if (preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
            throw new RuntimeException('invalid_media_checksum');
        }
        return $checksum;
    }
}
