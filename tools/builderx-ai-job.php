<?php
declare(strict_types=1);

use BuilderX\AI\PhaseAiJobStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('BUILDERX_SKIP_SESSION_START', true);
require dirname(__DIR__) . '/app/foundation.php';

$respond = static function (array $payload, int $status = 0): never {
    fwrite($status === 0 ? STDOUT : STDERR, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
    exit($status);
};

$readInput = static function (int $limit = 10_000_000): string {
    $stream = fopen('php://stdin', 'rb');
    if ($stream === false) {
        throw new RuntimeException('BuilderX could not open standard input.');
    }
    $input = stream_get_contents($stream, $limit + 1);
    fclose($stream);
    if (!is_string($input) || strlen($input) > $limit) {
        throw new RuntimeException('BuilderX standard input exceeded the supported size.');
    }
    return trim($input);
};

$readPayload = static function (int $limit = 10_000_000) use ($argv, $readInput): string {
    if (array_key_exists(3, $argv)) {
        $input = trim((string) $argv[3]);
        if (strlen($input) > $limit) {
            throw new RuntimeException('BuilderX command input exceeded the supported size.');
        }
        return $input;
    }
    return $readInput($limit);
};

try {
    $operation = strtolower(trim((string) ($argv[1] ?? '')));
    $jobKey = strtolower(trim((string) ($argv[2] ?? '')));
    $projectRoot = realpath(dirname(__DIR__));
    if (!is_string($projectRoot) || !is_dir($projectRoot)) {
        throw new RuntimeException('The BuilderX project root is unavailable.');
    }
    $store = new PhaseAiJobStore($projectRoot);
    if ($operation === 'health') {
        $respond([
            'ok' => true,
            'transport' => 'mysql',
            'workspace' => rtrim(str_replace('\\', '/', $projectRoot), '/'),
            'job_table_ready' => (int) bx_db()->GetOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [BUILDERX_DB_NAME, 'phase_builder_ai_job']) === 1,
            'context_table_ready' => (int) bx_db()->GetOne('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [BUILDERX_DB_NAME, 'phase_builder_ai_context']) === 1,
        ]);
    }
    if ($jobKey === '') {
        throw new InvalidArgumentException('A BuilderX MySQL job key is required.');
    }
    if ($operation === 'claim') {
        $respond($store->claim($jobKey));
    }
    if ($operation === 'status') {
        $respond($store->result($jobKey, hash('sha256', rtrim(str_replace('\\', '/', $projectRoot), '/'))));
    }
    if ($operation === 'complete') {
        $input = $readPayload();
        try {
            $result = json_decode($input, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('The BuilderX MySQL job result is invalid JSON.', 0, $error);
        }
        if (!is_array($result) || array_is_list($result)) {
            throw new InvalidArgumentException('The BuilderX MySQL job result must be one JSON object.');
        }
        $respond($store->complete($jobKey, $result));
    }
    if ($operation === 'fail') {
        $respond($store->fail($jobKey, $readPayload(2_000)));
    }
    throw new InvalidArgumentException('Supported BuilderX MySQL job operations are health, claim, status, complete, and fail.');
} catch (Throwable $error) {
    $respond(['ok' => false, 'message' => $error->getMessage()], 1);
}
