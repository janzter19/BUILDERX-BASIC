<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$foundation = file_get_contents($root . '/app/foundation.php');
$portal = file_get_contents($root . '/index.php');
$administrator = file_get_contents($root . '/administrator/index.php');
$frontend = file_get_contents($root . '/frontend/src/App.tsx');

foreach ([
    'foundation source' => $foundation,
    'portal source' => $portal,
    'administrator source' => $administrator,
    'frontend source' => $frontend,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException($label . ' is not readable.');
    }
}

$requiredFoundation = [
    'function bx_flash(string $message, string $type = \'info\', ?string $details = null, array $lifecycle = [])',
    '\'lifecycleStatus\'',
    '\'lifecycleSteps\'',
    'function bx_mutation_lifecycle_flash',
    '\'committed_read_back\'',
    '\'action_required\'',
];
foreach ($requiredFoundation as $needle) {
    if (!str_contains($foundation, $needle)) {
        throw new RuntimeException('Foundation lifecycle flash contract is missing: ' . $needle);
    }
}

$requiredPortal = [
    'function bx_portal_family_member_read_back',
    'bx_portal_family_member_read_back($memberKey, $ownerKey)',
    'bx_mutation_lifecycle_flash(',
    '\'Realtime sync\'',
    'Committed read-back verified for this owner-scoped member',
];
foreach ($requiredPortal as $needle) {
    if (!str_contains($portal, $needle)) {
        throw new RuntimeException('Portal mutation lifecycle path is missing: ' . $needle);
    }
}

$requiredAdministrator = [
    'bx_admin_require_authorization([\'requireAdmin\' => true])',
    'SELECT branch_code, branch_name, branch_status FROM builder_branch WHERE branch_key = ?',
    'SELECT branch_code, branch_status FROM builder_branch WHERE branch_key = ?',
    'bx_mutation_lifecycle_flash(\'Branch updated.\'',
    'bx_mutation_lifecycle_flash(\'Branch created.\'',
    'bx_mutation_lifecycle_flash(\'Branch status updated.\'',
];
foreach ($requiredAdministrator as $needle) {
    if (!str_contains($administrator, $needle)) {
        throw new RuntimeException('Administrator lifecycle feedback path is missing: ' . $needle);
    }
}

$requiredFrontend = [
    'lifecycleStatus?: string',
    'lifecycleSteps?: Array',
    'const lifecycleSteps = Array.isArray',
    'role="status"',
    'aria-live="polite"',
    'role="alert"',
    'aria-live="assertive"',
    'Clock3',
];
foreach ($requiredFrontend as $needle) {
    if (!str_contains($frontend, $needle)) {
        throw new RuntimeException('Frontend lifecycle feedback rendering is missing: ' . $needle);
    }
}

echo json_encode([
    'lifecycle_flash_contract' => true,
    'portal_committed_read_back_feedback' => true,
    'administrator_branch_read_back_feedback' => true,
    'accessible_frontend_feedback' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
