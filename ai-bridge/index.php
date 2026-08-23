<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/foundation.php';

use BuilderX\AI\BuilderXAiBridgeAdapter;
use BuilderX\AI\PhaseAiBridgeException;

$respond = static function (array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

$user = bx_current_user();
if ($user === null) {
    $respond(['ok' => false, 'message' => 'Authentication is required for the BuilderX AI Bridge.'], 401);
}
if (!bx_is_admin($user)) {
    $respond(['ok' => false, 'message' => 'Administrator access is required for the BuilderX AI Bridge.'], 403);
}

$projectRoot = realpath(dirname(__DIR__));
if (!is_string($projectRoot)) {
    $respond(['ok' => false, 'message' => 'The current installed project root is unavailable.'], 500);
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/ai-bridge/index.php'));
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
$mountPrefix = preg_replace('#/index\.php$#', '', $scriptName) ?: '/ai-bridge';
$path = is_string($requestPath) && str_starts_with($requestPath, $mountPrefix)
    ? '/' . ltrim(substr($requestPath, strlen($mountPrefix)), '/')
    : '/';
$path = preg_replace('#/index\.php/?#', '/', $path) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'POST') {
    $csrf = trim((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($csrf === '' || !hash_equals(bx_csrf_token(), $csrf)) {
        $respond(['ok' => false, 'message' => 'Invalid request token.'], 403);
    }
}

$requestIds = $_SESSION['builderx_ai_bridge_request_ids'] ?? [];
$requestIds = is_array($requestIds) ? $requestIds : [];
$adapter = new BuilderXAiBridgeAdapter($projectRoot);

try {
    if ($method === 'GET' && in_array($path, ['/health', '/keepalive'], true)) {
        $respond($adapter->health(false));
    }
    if ($method === 'GET' && $path === '/result') {
        $requestId = strtolower(trim((string) ($_GET['request_id'] ?? '')));
        if (!isset($requestIds[$requestId])) {
            $respond(['ok' => false, 'message' => 'The Bridge result is not bound to this authenticated session.'], 403);
        }
        $respond($adapter->result($requestId));
    }
    if ($method === 'POST' && $path === '/handoff') {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload) || array_is_list($payload)) {
            $respond(['ok' => false, 'message' => 'The Bridge handoff payload must be a JSON object.'], 422);
        }
        $delivery = $adapter->dispatch((string) ($payload['command'] ?? ''), false);
        $requestId = (string) ($delivery['provider_request_id'] ?? '');
        $requestIds[$requestId] = time();
        foreach ($requestIds as $savedRequestId => $createdAt) {
            if (!is_int($createdAt) || $createdAt < time() - 86400) unset($requestIds[$savedRequestId]);
        }
        $_SESSION['builderx_ai_bridge_request_ids'] = $requestIds;
        $respond(['ok' => true, 'bridge' => 'BuilderX', 'delivery' => $delivery['delivery'] ?? []]);
    }
    if ($method === 'POST' && $path === '/restart') {
        $respond($adapter->restart());
    }
    if (in_array($path, ['/orchestration-run', '/orchestration-test'], true)) {
        $respond([
            'ok' => false,
            'error_code' => 'UNSUPPORTED_AUTONOMOUS_FANOUT',
            'message' => 'Autonomous specialist fan-out was retired. Use the persistent Planning or Coding Engine workflow.',
        ], 410);
    }
    $respond(['ok' => false, 'message' => 'The server-owned BuilderX AI Bridge action was not found.'], 404);
} catch (PhaseAiBridgeException $error) {
    $status = $error->errorCode() === 'PERMISSION_DENIED' ? 403 : ($error->errorCode() === 'LOCK_CONFLICT' ? 409 : 502);
    $respond(['ok' => false, 'error_code' => $error->errorCode(), 'message' => $error->getMessage()], $status);
} catch (Throwable $error) {
    $respond(['ok' => false, 'message' => $error->getMessage()], 422);
}
