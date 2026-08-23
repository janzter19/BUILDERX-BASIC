<?php
declare(strict_types=1);

namespace BuilderX\AI;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

interface BuilderXAiBridgeTransport
{
    /** @return array<string, mixed> */
    public function request(string $method, string $path, ?array $payload = null, int $timeoutSeconds = 30): array;

    /** @param callable(string): void $onChunk */
    public function stream(string $path, callable $onChunk, int $timeoutSeconds = 3600): void;
}

final class PhaseAiBridgeException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

final class CurlBuilderXAiBridgeTransport implements BuilderXAiBridgeTransport
{
    private readonly string $baseUrl;

    public function __construct(?string $baseUrl = null)
    {
        $candidate = rtrim(trim((string) ($baseUrl ?: getenv('BUILDERX_AI_BRIDGE_URL') ?: 'http://127.0.0.1:43127')), '/');
        $parts = parse_url($candidate);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (
            !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http'
            || !in_array($host, ['127.0.0.1', 'localhost'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ((string) ($parts['path'] ?? '') !== '' && (string) ($parts['path'] ?? '') !== '/')
        ) {
            throw new InvalidArgumentException('The BuilderX AI Bridge endpoint must be a loopback HTTP origin.');
        }
        $port = (int) ($parts['port'] ?? 80);
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The BuilderX AI Bridge port is invalid.');
        }
        $this->baseUrl = $candidate;
    }

    public function request(string $method, string $path, ?array $payload = null, int $timeoutSeconds = 30): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['GET', 'POST'], true) || !str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The BuilderX AI Bridge request is invalid.');
        }
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge transport could not start.');
        }
        $headers = ['Accept: application/json'];
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(1, min($timeoutSeconds, 300)),
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($payload !== null) {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $options[CURLOPT_POSTFIELDS] = $encoded;
            $options[CURLOPT_HTTPHEADER] = [...$headers, 'Content-Type: application/json'];
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $transportError = trim(curl_error($handle));
        curl_close($handle);
        if ($body === false || $transportError !== '') {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge did not respond.');
        }
        try {
            $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge returned invalid JSON.', $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge returned an invalid response object.');
        }
        if ($status < 200 || $status >= 300) {
            $message = trim((string) ($decoded['message'] ?? '')) ?: 'The BuilderX AI Bridge rejected the request.';
            throw new PhaseAiBridgeException(BuilderXAiBridgeAdapter::errorCodeForMessage($message), $message);
        }
        return $decoded;
    }

    public function stream(string $path, callable $onChunk, int $timeoutSeconds = 3600): void
    {
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException('The BuilderX AI Bridge stream path is invalid.');
        }
        $handle = curl_init($this->baseUrl . $path);
        if ($handle === false) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge stream could not start.');
        }
        $httpStatus = 0;
        $errorBody = '';
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(1, min($timeoutSeconds, 3600)),
            CURLOPT_HTTPHEADER => ['Accept: text/event-stream'],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$httpStatus): int {
                if (preg_match('/^HTTP\/\S+\s+(\d{3})/', trim($header), $match) === 1) {
                    $httpStatus = (int) $match[1];
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$httpStatus, &$errorBody, $onChunk): int {
                if ($httpStatus >= 200 && $httpStatus < 300) {
                    $onChunk($chunk);
                } else {
                    $errorBody .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        $completed = curl_exec($handle);
        $transportError = trim(curl_error($handle));
        curl_close($handle);
        if ($completed === false || $transportError !== '') {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge progress stream failed.');
        }
        if ($httpStatus < 200 || $httpStatus >= 300) {
            $decoded = json_decode($errorBody, true);
            $message = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';
            $message = $message !== '' ? $message : 'The BuilderX AI Bridge progress stream was rejected.';
            throw new PhaseAiBridgeException(BuilderXAiBridgeAdapter::errorCodeForMessage($message), $message);
        }
    }
}

final class BuilderXAiBridgeAdapter
{
    public const PROVIDER_KEY = 'builderx_ai_bridge';

    private readonly string $projectRoot;
    private readonly string $bridgeWorkspaceRoot;
    private readonly BuilderXAiBridgeTransport $transport;

    public function __construct(string $projectRoot, ?BuilderXAiBridgeTransport $transport = null, ?string $bridgeWorkspaceRoot = null)
    {
        $resolved = realpath($projectRoot);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException('The current BuilderX project root is unavailable.');
        }
        $this->projectRoot = self::normalizePath($resolved);
        $workspaceCandidate = self::normalizePath(trim((string) $bridgeWorkspaceRoot) ?: $this->projectRoot);
        $resolvedWorkspace = realpath($workspaceCandidate);
        if (!is_string($resolvedWorkspace) || self::normalizePath($resolvedWorkspace) !== $this->projectRoot) {
            throw new InvalidArgumentException('The BuilderX AI Bridge workspace does not resolve to the current installed project.');
        }
        $this->bridgeWorkspaceRoot = $workspaceCandidate;
        $this->transport = $transport ?? new CurlBuilderXAiBridgeTransport();
    }

    /** @return array<string, mixed> */
    public function health(bool $requireReady = false): array
    {
        $health = $this->transport->request('GET', '/health?workspace_root=' . rawurlencode($this->bridgeWorkspaceRoot), null, 15);
        $workspace = realpath((string) ($health['workspace'] ?? ''));
        if (($health['ok'] ?? false) !== true || !is_string($workspace) || self::normalizePath($workspace) !== $this->projectRoot) {
            throw new PhaseAiBridgeException('PERMISSION_DENIED', 'The BuilderX AI Bridge is not bound to the current installed project.');
        }
        $expectedVersion = $this->expectedCompanionVersion();
        $actualVersion = trim((string) ($health['companion_extension_version'] ?? $health['version'] ?? ''));
        if ($expectedVersion !== '' && $actualVersion !== $expectedVersion) {
            $health['extension_version_ready'] = false;
            $health['ready_to_send'] = false;
            $health['extension_probe_state'] = 'version_mismatch';
            $health['extension_probe_message'] = 'Install or reload BuilderX companion ' . $expectedVersion . ' before sending this AI job. Active companion version: ' . ($actualVersion !== '' ? $actualVersion : 'unknown') . '.';
        }
        if ($requireReady) {
            if (($health['extension_version_ready'] ?? false) !== true) {
                $message = trim((string) ($health['extension_probe_message'] ?? '')) ?: 'The BuilderX AI Bridge companion version is not ready.';
                throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', $message);
            }
            if (($health['active_thread_ready'] ?? false) !== true) {
                throw new PhaseAiBridgeException('CODEX_CHAT_NOT_READY', 'An active Codex AI Chat for the current project is required.');
            }
            if (($health['active_thread_busy'] ?? false) === true) {
                throw new PhaseAiBridgeException('LOCK_CONFLICT', 'The active Codex AI Chat is busy.');
            }
            if (($health['ready_to_send'] ?? false) !== true) {
                $message = trim((string) ($health['extension_probe_message'] ?? '')) ?: 'The BuilderX AI Bridge is not ready to send.';
                throw new PhaseAiBridgeException(self::errorCodeForMessage($message), $message);
            }
        }
        return $health;
    }

    private function expectedCompanionVersion(): string
    {
        $manifestPath = $this->projectRoot . '/tools/builderx-bridge/builderx-companion.manifest.json';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return '';
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        if (!is_array($manifest)) {
            return '';
        }
        return trim((string) ($manifest['version'] ?? $manifest['companion_version'] ?? ''));
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        $this->health(false);
        $capabilities = $this->transport->request('GET', '/capabilities', null, 10);
        if (($capabilities['ok'] ?? false) !== true || ($capabilities['bridge'] ?? '') !== 'BuilderX') {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge capabilities are invalid.');
        }
        return $capabilities;
    }

    /** @return array<string, mixed> */
    public function restart(): array
    {
        $this->health(false);
        $restart = $this->transport->request('POST', '/restart', ['workspace_root' => $this->bridgeWorkspaceRoot], 20);
        if (($restart['ok'] ?? false) !== true || ($restart['bridge'] ?? '') !== 'BuilderX') {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge restart was not acknowledged.');
        }
        return $restart;
    }

    /** @return array<string, mixed> */
    public function dispatchJob(string $jobKey, bool $allowSourceChanges = false): array
    {
        self::assertRequestId($jobKey);
        $health = $this->health(true);
        $response = $this->transport->request(
            'POST',
            '/handoff-result',
            [
                'workspace_root' => $this->bridgeWorkspaceRoot,
                'job_key' => $jobKey,
                'mode' => $allowSourceChanges ? 'coding_implementation' : 'read_only',
            ],
            30
        );
        $delivery = $response['delivery'] ?? null;
        $requestId = is_array($delivery) ? trim((string) ($delivery['request_id'] ?? '')) : '';
        if (
            ($response['ok'] ?? false) !== true
            || ($response['bridge'] ?? '') !== 'BuilderX'
            || !is_array($delivery)
            || !hash_equals($jobKey, $requestId)
            || ($delivery['acknowledged'] ?? false) !== true
            || ($delivery['storage'] ?? '') !== 'mysql'
        ) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX companion did not acknowledge the MySQL AI job.');
        }
        return [
            'provider_key' => self::PROVIDER_KEY,
            'model_key' => trim((string) ($response['model_key'] ?? $health['model_key'] ?? '')) ?: null,
            'provider_request_id' => $requestId,
            'thread_id' => trim((string) ($delivery['thread_id'] ?? $health['active_thread_id'] ?? '')),
            'storage' => 'mysql',
            'delivery' => $delivery,
        ];
    }

    /** @return array<string, mixed> */
    public function result(string $requestId): array
    {
        self::assertRequestId($requestId);
        $result = (new PhaseAiJobStore($this->projectRoot))->result($requestId, hash('sha256', $this->projectRoot));
        if (($result['ok'] ?? false) !== true || (string) ($result['request_id'] ?? '') !== $requestId) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge result identity did not match.');
        }
        $status = strtolower(trim((string) ($result['status'] ?? '')));
        if (!in_array($status, ['pending', 'completed', 'failed'], true)) {
            throw new PhaseAiBridgeException('BRIDGE_UNAVAILABLE', 'The BuilderX AI Bridge returned an invalid result status.');
        }
        if (isset($result['result_json']) && (!is_array($result['result_json']) || array_is_list($result['result_json']))) {
            throw new PhaseAiBridgeException('INVALID_RESULT_SCHEMA', 'The BuilderX MySQL AI result is not a JSON object.');
        }
        return $result;
    }

    /** @param callable(string): void $onChunk */
    public function streamEvents(string $requestId, callable $onChunk): void
    {
        self::assertRequestId($requestId);
        $store = new PhaseAiJobStore($this->projectRoot);
        $projectIdentity = hash('sha256', $this->projectRoot);
        $deadline = time() + 3600;
        $lastStatus = '';
        while (time() < $deadline) {
            $result = $store->result($requestId, $projectIdentity);
            $status = (string) ($result['status'] ?? 'pending');
            if ($status !== $lastStatus) {
                $lastStatus = $status;
                $onChunk("event: status\ndata: " . json_encode(['status' => $status, 'storage' => 'mysql', 'message' => $status === 'pending' ? 'The visible Codex Chat is processing the MySQL job.' : 'The MySQL job reached a terminal state.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n");
            }
            if ($status === 'completed') {
                $onChunk("event: completed\ndata: " . json_encode(['status' => 'completed', 'storage' => 'mysql', 'result_json' => $result['result_json'] ?? null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n");
                return;
            }
            if ($status === 'failed') {
                $onChunk("event: failed\ndata: " . json_encode(['status' => 'failed', 'storage' => 'mysql', 'message' => $result['message'] ?? 'The MySQL AI job failed.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n\n");
                return;
            }
            usleep(500_000);
        }
        throw new PhaseAiBridgeException('RUN_TIMEOUT', 'The MySQL AI job did not finish before its progress stream expired.');
    }

    public function projectRoot(): string
    {
        return $this->projectRoot;
    }

    public static function errorCodeForMessage(string $message): string
    {
        $normalized = strtolower($message);
        if (str_contains($normalized, 'busy')) {
            return 'LOCK_CONFLICT';
        }
        if (str_contains($normalized, 'different workspace') || str_contains($normalized, 'current installed project')) {
            return 'PERMISSION_DENIED';
        }
        if (str_contains($normalized, 'active codex') || str_contains($normalized, 'codex chat') || str_contains($normalized, 'send command')) {
            return 'CODEX_CHAT_NOT_READY';
        }
        if (str_contains($normalized, 'model')) {
            return 'MODEL_UNAVAILABLE';
        }
        return 'BRIDGE_UNAVAILABLE';
    }

    private static function assertRequestId(string $requestId): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/', $requestId) !== 1) {
            throw new InvalidArgumentException('The BuilderX AI Bridge request identity is invalid.');
        }
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }
}
