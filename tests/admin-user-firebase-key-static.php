<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundationSource = file_get_contents($root . '/app/foundation.php');
$adminSource = file_get_contents($root . '/administrator/index.php');

if (!is_string($foundationSource) || !is_string($adminSource)) {
    throw new RuntimeException('User Firebase key sources could not be read.');
}

$requiredFoundationMarkers = [
    'function bx_unique_firebase_document_key(string $table, string $column, int $length = 20): string',
    "preg_match('/^[A-Za-z0-9_]+$/', \$table)",
    '$candidate = bx_firebase_document_id($length);',
    "SELECT COUNT(*) FROM {\$table} WHERE {\$column} = ?",
    "\$userKey = bx_unique_firebase_document_key('builder_user', 'user_key');",
];

foreach ($requiredFoundationMarkers as $marker) {
    if (!str_contains($foundationSource, $marker)) {
        throw new RuntimeException('Missing user Firebase key foundation marker: ' . $marker);
    }
}

$saveUserPosition = strpos($adminSource, "\$action === 'save_user'");
$setUserStatusPosition = strpos($adminSource, "\$action === 'set_user_status'", $saveUserPosition === false ? 0 : $saveUserPosition);
if ($saveUserPosition === false || $setUserStatusPosition === false) {
    throw new RuntimeException('Could not locate save_user action chunk.');
}

$saveUserChunk = substr($adminSource, $saveUserPosition, $setUserStatusPosition - $saveUserPosition);

foreach ([
    "\$userKey = bx_unique_firebase_document_key('builder_user', 'user_key');",
    "preg_match('/^[A-Za-z0-9]{20}$/', \$userKey)",
    'Generated user key is not a Firebase document id.',
] as $marker) {
    if (!str_contains($saveUserChunk, $marker)) {
        throw new RuntimeException('Missing user Firebase key create marker: ' . $marker);
    }
}

$builderUserSchemaPosition = strpos($foundationSource, 'CREATE TABLE IF NOT EXISTS builder_user (');
$builderGroupSchemaPosition = strpos($foundationSource, 'CREATE TABLE IF NOT EXISTS builder_group (', $builderUserSchemaPosition === false ? 0 : $builderUserSchemaPosition);
if ($builderUserSchemaPosition === false || $builderGroupSchemaPosition === false) {
    throw new RuntimeException('Could not locate builder_user schema chunk.');
}

$builderUserSchema = substr($foundationSource, $builderUserSchemaPosition, $builderGroupSchemaPosition - $builderUserSchemaPosition);

foreach (['firebase_document_id', 'document_id', 'firebase_user_key'] as $forbiddenUserColumn) {
    if (stripos($builderUserSchema, $forbiddenUserColumn) !== false || stripos($saveUserChunk, $forbiddenUserColumn) !== false) {
        throw new RuntimeException('Forbidden separate Firebase document field found for builder_user: ' . $forbiddenUserColumn);
    }
}

echo json_encode([
    'builder_user_key_uses_firebase_document_id' => true,
    'separate_user_firebase_document_field_absent' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
