<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/foundation.php';

$sourceUser = [
    'x_id' => 42,
    'user_key' => '11111111-2222-3333-4444-555555555555',
    'user_login' => 'private-login',
    'user_password_hash' => 'private-password-hash',
    'user_name' => 'Portal User',
    'user_email' => 'private@example.invalid',
    'user_status' => 'ACTIVE',
    'user_failed_login_count' => 3,
    'user_two_factor_required' => 1,
];

$projection = bx_user_public_projection($sourceUser);
$expected = [
    'user_key' => '11111111-2222-3333-4444-555555555555',
    'user_name' => 'Portal User',
];

if ($projection !== $expected) {
    throw new RuntimeException('The User Portal identity projection is not the exact public allow-list.');
}
if (bx_user_public_projection(null) !== null) {
    throw new RuntimeException('An anonymous User Portal identity must remain null.');
}

$indexSource = file_get_contents(dirname(__DIR__) . '/index.php');
if (!is_string($indexSource) || !str_contains($indexSource, "'currentUser' => bx_user_public_projection(\$currentUser)")) {
    throw new RuntimeException('The User Portal boot payload does not use the public identity projection.');
}

echo json_encode([
    'public_keys' => array_keys($projection),
    'sensitive_fields_excluded' => true,
    'anonymous_projection_null' => true,
    'boot_payload_projection_bound' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
