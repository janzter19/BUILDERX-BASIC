<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$extension = (string) file_get_contents($root . '/tools/builderx-bridge/extension/extension.js');

foreach ([
    "url.pathname === '/restart'",
    'The global companion follows the active workspace automatically.',
    'currentWorkspace()',
    "transport: 'mysql'",
] as $marker) {
    if (!str_contains($extension, $marker)) {
        throw new RuntimeException('The global companion restart contract is missing marker: ' . $marker);
    }
}
foreach (['bridge-state', 'result.json', 'request.json', 'systemctl', 'builderx-bridge.service'] as $retiredMarker) {
    if (str_contains($extension, $retiredMarker)) {
        throw new RuntimeException('The global companion restart contract contains retired state: ' . $retiredMarker);
    }
}

echo json_encode([
    'workspace_follows_vscode' => true,
    'restart_state_files' => false,
    'systemd_service' => false,
    'mysql_transport' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
