<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$frontendSource = file_get_contents($root . '/frontend/src/App.tsx');

if (!is_string($frontendSource)) {
    throw new RuntimeException('Administrator frontend source could not be read.');
}

$requiredMarkers = [
    "const groupDeactivated = group.group_status === 'INACTIVE'",
    'const restoreDisabled = groupActive',
    "const disabledGroupActionClass = 'disabled:cursor-not-allowed",
    'disabled:border-border disabled:bg-muted/20 disabled:text-muted-foreground disabled:shadow-none disabled:opacity-35',
    'disabled={groupDeactivated}',
    'disabled={restoreDisabled}',
    'disabledReason={restoreDisabledReason}',
    'disabledReason={deactivatedGroupActionReason}',
    'if (groupDeactivated) return',
];

foreach ($requiredMarkers as $marker) {
    if (!str_contains($frontendSource, $marker)) {
        throw new RuntimeException('Missing group action-state marker: ' . $marker);
    }
}

echo json_encode([
    'group_inactive_actions_disabled' => true,
    'group_active_restore_disabled' => true,
    'group_disabled_actions_visually_muted' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
