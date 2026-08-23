<?php
declare(strict_types=1);

$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) throw new RuntimeException('The installed project root is unavailable.');
$lifecycleEntry = trim((string) getenv('BUILDERX_LIFECYCLE_ENTRY')) === 'phases' ? 'phases' : 'portal';
$relative = $lifecycleEntry === 'phases' ? 'phases/index.php' : 'index.php';
$scriptName = '/' . basename($projectRoot) . '/' . $relative;

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = $scriptName;
$_SERVER['REQUEST_URI'] = $lifecycleEntry === 'phases' ? '/' . basename($projectRoot) . '/phases/' : '/' . basename($projectRoot) . '/';
$_SERVER['DOCUMENT_ROOT'] = dirname($projectRoot);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET = [];
$_POST = [];
$_REQUEST = [];

chdir($projectRoot);
ob_start();
require $projectRoot . '/' . $relative;
$html = (string) ob_get_clean();
if (strlen($html) < 500 || !str_contains($html, '<!doctype html>')) {
    throw new RuntimeException('The installed ' . $lifecycleEntry . ' web lifecycle did not render BuilderX HTML.');
}
echo json_encode(['entry' => $lifecycleEntry, 'rendered_bytes' => strlen($html)], JSON_THROW_ON_ERROR) . PHP_EOL;
