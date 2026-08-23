<?php
declare(strict_types=1);

require_once __DIR__ . '/app/foundation.php';

use BuilderX\AI\BuilderXAiBridgeAdapter;
use BuilderX\AI\PhaseAiBridgeException;
use BuilderX\AI\PhaseAiOrchestrator;
use BuilderX\AI\PhaseAiRunStore;
use BuilderX\AI\PhaseAiContextStore;
use BuilderX\AI\SharinganSurfaceWorkflow;

function bx_sharingan_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function bx_sharingan_normalize_route(string $value): string
{
    $path = parse_url(trim($value), PHP_URL_PATH);
    $path = is_string($path) ? str_replace('\\', '/', $path) : '';
    $path = preg_replace('#/+#', '/', $path) ?: '';
    if (str_ends_with($path, '/index.php')) {
        $path = substr($path, 0, -10);
    }
    return rtrim($path, '/') ?: '/';
}

/** @return array{surface_key: string, workflow_key: string, route_key: string, draft_key: string, label: string, administrator_required: bool, route_path: string} */
function bx_sharingan_authorized_surface(array $source, ?array $user): array
{
    if ($user === null) {
        bx_sharingan_json(['ok' => false, 'message' => 'Sign in before using Sharingan.'], 403);
    }
    $surfaceKey = strtolower(trim((string) ($source['surface_key'] ?? '')));
    try {
        $surface = SharinganSurfaceWorkflow::surface($surfaceKey);
    } catch (Throwable $error) {
        bx_sharingan_json(['ok' => false, 'message' => $error->getMessage()], 422);
    }
    if ($surface['administrator_required'] && !bx_is_admin($user)) {
        bx_sharingan_json(['ok' => false, 'message' => 'Administrator access is required for Sharingan on this surface.'], 403);
    }
    $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/sharingan.php'));
    $projectBasePath = rtrim(dirname($scriptPath), '/');
    $expectedRoute = match ($surfaceKey) {
        'user_portal' => $projectBasePath,
        'administrator_portal' => $projectBasePath . '/administrator',
        'phases' => $projectBasePath . '/phases',
    };
    $routePath = bx_sharingan_normalize_route((string) ($source['route_path'] ?? ''));
    if ($routePath !== (bx_sharingan_normalize_route($expectedRoute))) {
        bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan route does not match the authorized product surface.'], 403);
    }
    $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($referer !== '' && bx_sharingan_normalize_route($referer) !== $routePath) {
        bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan request origin does not match its captured route.'], 403);
    }
    return ['surface_key' => $surfaceKey, ...$surface, 'route_path' => $routePath];
}

function bx_sharingan_require_csrf(array $source): void
{
    $token = (string) ($source['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!hash_equals(bx_csrf_token(), $token)) {
        bx_sharingan_json(['ok' => false, 'message' => 'Invalid request token.'], 403);
    }
}

/** @param array<string, mixed> $file @return array<string, mixed> */
function bx_sharingan_save_image(array $file, string $directory, string $baseName, string $projectRoot): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('A required Sharingan image could not be uploaded.');
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($temporaryPath === '' || $size < 1 || $size > 8 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Sharingan images must be valid uploads up to 8 MB.');
    }
    $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException('Sharingan accepts only PNG, JPG, WEBP, or GIF images.');
    }
    $fileName = $baseName . '.' . $extensions[$mimeType];
    $destination = $directory . '/' . $fileName;
    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException('A Sharingan image could not be stored in the current project.');
    }
    chmod($destination, 0644);
    $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
    $normalizedDestination = str_replace('\\', '/', $destination);
    if (!str_starts_with($normalizedDestination, $normalizedRoot . '/_Document/attachments/')) {
        @unlink($destination);
        throw new RuntimeException('The Sharingan image path is outside the project document store.');
    }
    return [
        'storage_path' => ltrim(substr($normalizedDestination, strlen($normalizedRoot)), '/'),
        'name' => $fileName,
        'mime_type' => $mimeType,
        'byte_size' => $size,
        'sha256' => hash_file('sha256', $destination),
    ];
}

/** @param array<string, mixed> $metadata @return array<string, mixed> */
function bx_sharingan_sanitize_metadata(array $metadata, string $routePath): array
{
    $pagePath = bx_sharingan_normalize_route((string) ($metadata['page'] ?? ''));
    if ($pagePath !== $routePath) {
        throw new InvalidArgumentException('The captured Sharingan page does not match the authorized route.');
    }
    $selected = $metadata['selected_element'] ?? null;
    if ($selected !== null && (!is_array($selected) || array_is_list($selected))) {
        throw new InvalidArgumentException('The selected Sharingan element is invalid.');
    }
    if (is_array($selected)) {
        foreach (['outerHtml', 'parentOuterHtml'] as $htmlKey) {
            if (isset($selected[$htmlKey]) && (!is_string($selected[$htmlKey]) || strlen($selected[$htmlKey]) > 120000)) {
                throw new InvalidArgumentException('The selected Sharingan element markup is too large.');
            }
        }
        $attributes = $selected['attributes'] ?? [];
        if (!is_array($attributes) || array_is_list($attributes)) {
            throw new InvalidArgumentException('The selected Sharingan element attributes are invalid.');
        }
        foreach (array_keys($attributes) as $attributeName) {
            if (preg_match('/(?:value|password|secret|token|credential|authorization|cookie)/i', (string) $attributeName) === 1) {
                unset($attributes[$attributeName]);
            }
        }
        $selected['attributes'] = $attributes;
        $metadata['selected_element'] = $selected;
    }
    $annotations = $metadata['annotations'] ?? [];
    if (!is_array($annotations) || count($annotations) > 100) {
        throw new InvalidArgumentException('The Sharingan annotations are invalid or too large.');
    }
    $metadata['page'] = $routePath;
    $metadata['active_view'] = substr(trim((string) ($metadata['active_view'] ?? '')), 0, 200);
    return $metadata;
}

$projectRoot = realpath(__DIR__);
if (!is_string($projectRoot)) {
    bx_sharingan_json(['ok' => false, 'message' => 'The current BuilderX project root is unavailable.'], 500);
}
$projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
$projectIdentity = hash('sha256', $projectRoot);
$projectWorkspaceRoot = $projectRoot;
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/sharingan.php'));
$projectMountPath = rtrim(dirname($scriptPath), '/');
$webProjectRoot = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/') . $projectMountPath;
$resolvedWebProjectRoot = realpath($webProjectRoot);
if (is_string($resolvedWebProjectRoot) && rtrim(str_replace('\\', '/', $resolvedWebProjectRoot), '/') === $projectRoot) {
    $projectWorkspaceRoot = rtrim(str_replace('\\', '/', $webProjectRoot), '/');
}
$user = bx_current_user();
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $surface = bx_sharingan_authorized_surface($_GET, $user);
    $action = strtolower(trim((string) ($_GET['action'] ?? '')));
    try {
        $adapter = new BuilderXAiBridgeAdapter($projectRoot, null, $projectWorkspaceRoot);
        $store = new PhaseAiRunStore((string) ($user['user_key'] ?? ''));
        if ($action === 'health') {
            $health = $adapter->health(false);
            bx_sharingan_json(['ok' => true, 'surface_key' => $surface['surface_key'], ...$health]);
        }
        if ($action === 'load') {
            $run = $store->latest($surface['workflow_key'], $surface['draft_key'], $projectIdentity);
            bx_sharingan_json(['ok' => true, 'data' => ['run' => $run]]);
        }
        if (in_array($action, ['events', 'result'], true)) {
            bx_sharingan_require_csrf($_GET);
            $runKey = trim((string) ($_GET['run_key'] ?? ''));
            $requestId = trim((string) ($_GET['request_id'] ?? ''));
            $run = $store->read($runKey, $projectIdentity);
            if (($run['workflow_key'] ?? '') !== $surface['workflow_key'] || ($run['route_key'] ?? '') !== $surface['route_key']) {
                bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan run is not bound to this surface.'], 403);
            }
            $orchestrator = new PhaseAiOrchestrator($projectRoot, $store, $adapter);
            $orchestrator->assertBridgeBinding($runKey, $projectIdentity, 'analysis', $requestId);
            if ($action === 'result') {
                bx_sharingan_json(['ok' => true, 'data' => ['result' => $adapter->result($requestId)]]);
            }
            ignore_user_abort(true);
            set_time_limit(0);
            header('Content-Type: text/event-stream; charset=utf-8');
            header('Cache-Control: no-cache, no-store');
            header('X-Accel-Buffering: no');
            try {
                $adapter->streamEvents($requestId, static function (string $chunk): void {
                    echo $chunk;
                    if (ob_get_level() > 0) @ob_flush();
                    flush();
                });
            } catch (Throwable $error) {
                echo "event: failed\ndata: " . json_encode(['message' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }
            exit;
        }
        bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan action is not supported.'], 404);
    } catch (PhaseAiBridgeException $error) {
        bx_sharingan_json(['ok' => false, 'error_code' => $error->errorCode(), 'message' => $error->getMessage()], $error->errorCode() === 'PERMISSION_DENIED' ? 403 : 502);
    } catch (Throwable $error) {
        bx_sharingan_json(['ok' => false, 'message' => $error->getMessage()], 422);
    }
}

if ($method !== 'POST') {
    bx_sharingan_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
}

bx_sharingan_require_csrf($_POST);
$surface = bx_sharingan_authorized_surface($_POST, $user);
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$store = new PhaseAiRunStore((string) ($user['user_key'] ?? ''));

try {
    if ($action === 'save_sharingan_context') {
        $instruction = trim((string) ($_POST['instruction'] ?? ''));
        $metadataRaw = (string) ($_POST['metadata'] ?? '{}');
        if ($instruction === '' || strlen($instruction) > 8000 || strlen($metadataRaw) > 250000) {
            throw new InvalidArgumentException('The Sharingan instruction or captured metadata is missing or too large.');
        }
        $metadata = json_decode($metadataRaw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($metadata) || array_is_list($metadata)) {
            throw new InvalidArgumentException('The Sharingan captured metadata must be a JSON object.');
        }
        $metadata = bx_sharingan_sanitize_metadata($metadata, $surface['route_path']);
        $contextId = 'sharingan-' . bx_uuid();
        $contextDirectory = $projectRoot . '/_Document/attachments/sharingan/' . $contextId;
        if (!is_dir($contextDirectory) && !mkdir($contextDirectory, 0775, true) && !is_dir($contextDirectory)) {
            throw new RuntimeException('The Sharingan context directory could not be created.');
        }
        chmod($contextDirectory, 0775);
        $screenshotFile = $_FILES['screenshot'] ?? null;
        if (!is_array($screenshotFile)) {
            throw new InvalidArgumentException('The current Sharingan screenshot is required.');
        }
        $screenshot = bx_sharingan_save_image($screenshotFile, $contextDirectory, 'current-screen', $projectRoot);
        $attachments = [];
        $attachmentFiles = $_FILES['attachments'] ?? null;
        if (is_array($attachmentFiles) && is_array($attachmentFiles['name'] ?? null)) {
            $count = min(count($attachmentFiles['name']), 5);
            for ($index = 0; $index < $count; $index++) {
                $attachments[] = bx_sharingan_save_image([
                    'name' => $attachmentFiles['name'][$index] ?? '',
                    'tmp_name' => $attachmentFiles['tmp_name'][$index] ?? '',
                    'error' => $attachmentFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                    'size' => $attachmentFiles['size'][$index] ?? 0,
                ], $contextDirectory, 'attachment-' . ($index + 1), $projectRoot);
            }
        }
        $idempotencyKey = strtolower(trim((string) ($_POST['idempotency_key'] ?? '')));
        $contextBindingHash = SharinganSurfaceWorkflow::hashObject([
            'surface_key' => $surface['surface_key'],
            'route_path' => $surface['route_path'],
            'instruction' => $instruction,
            'metadata' => $metadata,
            'screenshot_sha256' => $screenshot['sha256'],
            'attachment_sha256' => array_values(array_map(static fn (array $attachment): string => (string) $attachment['sha256'], $attachments)),
        ]);
        $request = [
            'schema_version' => 'builderx.sharingan.request.v1',
            'route_key' => $surface['route_key'],
            'workflow_key' => $surface['workflow_key'],
            'surface_key' => $surface['surface_key'],
            'surface_label' => $surface['label'],
            'route_path' => $surface['route_path'],
            'context_id' => $contextId,
            'context_sha256' => $contextBindingHash,
            'instruction' => $instruction,
            'metadata' => $metadata,
            'screenshot' => $screenshot,
            'attachments' => $attachments,
        ];
        $run = $store->start(
            PhaseAiRunStore::ENGINE_PLANNING,
            $surface['workflow_key'],
            $surface['draft_key'],
            null,
            $projectIdentity,
            $idempotencyKey,
            $request,
            (string) ($user['user_key'] ?? '')
        );
        $runKey = (string) ($run['run_key'] ?? '');
        $context = [
            'schema_version' => 'builderx.sharingan.context.v1',
            'run_key' => $runKey,
            'engine_type' => PhaseAiRunStore::ENGINE_PLANNING,
            'surface' => ['surface_key' => $surface['surface_key'], 'label' => $surface['label'], 'route_key' => $surface['route_key'], 'route_path' => $surface['route_path']],
            'instruction' => $instruction,
            'metadata' => $metadata,
            'screenshot' => $screenshot,
            'attachments' => $attachments,
            'context_binding_sha256' => $contextBindingHash,
            'rules' => [
                'Analyze the captured product surface without editing source files or product database records.',
                'Sharingan is a surface feature using the Planning Engine, not another engine, provider, or agent.',
                'Propose bounded changes and the engine appropriate for later approved work.',
                'Every proposed change requires Administrator approval before a Coding Engine mutation.',
                'A User Portal session has no Administrator or Coding Engine authority.',
                'Return exactly one JSON object to the MySQL job with no markdown.',
            ],
            'required_response' => [
                'schemaVersion' => SharinganSurfaceWorkflow::ANALYSIS_SCHEMA,
                'status' => 'completed | blocked',
                'surface' => ['surfaceKey' => $surface['surface_key'], 'routeKey' => $surface['route_key']],
                'context' => ['contextId' => $contextId, 'contextSha256' => $contextBindingHash],
                'summary' => 'Bounded analysis summary',
                'findings' => [['findingId' => 'FND-001', 'severity' => 'info | warning | error', 'description' => 'Observed issue or constraint']],
                'proposedChanges' => [['changeId' => 'CHG-001', 'scope' => 'product surface', 'description' => 'Proposed bounded change', 'recommendedEngine' => 'PLANNING | CODING', 'requiresAdministratorApproval' => true]],
                'risks' => [],
                'verificationPlan' => [],
                'blockers' => [],
            ],
        ];
        $contextMeta = (new PhaseAiContextStore((string) ($user['user_key'] ?? '')))->save($contextId, $projectIdentity, $context);
        $contextHash = (string) $contextMeta['sha256'];
        $orchestrator = new PhaseAiOrchestrator($projectRoot, $store);
        $orchestrator->begin($runKey, $projectIdentity, 'context', ['operation' => 'materialize_sharingan_context']);
        $run = $orchestrator->complete($runKey, $projectIdentity, 'context', [
            'context_id' => $contextId,
            'context_ref' => $contextMeta['context_ref'],
            'bytes' => $contextMeta['bytes'],
            'sha256' => $contextHash,
        ]);
        bx_sharingan_json(['ok' => true, 'data' => ['run' => $run, 'context_id' => $contextId, 'context_ref' => $contextMeta['context_ref'], 'context_path' => $contextMeta['context_ref'], 'context_sha256' => $contextHash, 'attachment_count' => count($attachments)]]);
    }

    if ($action === 'handoff') {
        $runKey = trim((string) ($_POST['run_key'] ?? ''));
        $run = $store->read($runKey, $projectIdentity);
        if (($run['workflow_key'] ?? '') !== $surface['workflow_key'] || ($run['route_key'] ?? '') !== $surface['route_key']) {
            bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan run is not bound to this surface.'], 403);
        }
        $contextStage = null;
        foreach ($run['stages'] ?? [] as $stage) {
            if (is_array($stage) && ($stage['stage_key'] ?? '') === 'context') $contextStage = $stage;
        }
        $contextId = (string) ($contextStage['result']['context_id'] ?? '');
        if ($contextId === '') {
            throw new RuntimeException('The verified Sharingan visual context is unavailable.');
        }
        $command = implode("\n", [
            'BuilderX Sharingan persistent Planning Engine analysis.',
            'Use the complete visual context delivered from MySQL with this job.',
            'Analyze only the captured route, selected element, annotations, instruction, screenshot, and attachments.',
            'Return exactly the required response JSON to the MySQL job.',
            'Do not edit files, execute SQL, mutate product data, call another provider, or dispatch another agent.',
            'All proposed changes require later Administrator approval and the authorized engine lifecycle.',
        ]);
        $adapter = new BuilderXAiBridgeAdapter($projectRoot, null, $projectWorkspaceRoot);
        $orchestrator = new PhaseAiOrchestrator($projectRoot, $store, $adapter);
        $dispatched = $orchestrator->dispatch($runKey, $projectIdentity, 'analysis', ['context_id' => $contextId, 'context_sha256' => $run['request']['context_sha256'] ?? ''], $command, true);
        bx_sharingan_json(['ok' => true, 'data' => $dispatched]);
    }

    if ($action === 'complete') {
        $runKey = trim((string) ($_POST['run_key'] ?? ''));
        $rawResult = trim((string) ($_POST['result_json'] ?? ''));
        if ($rawResult === '' || strlen($rawResult) > 1000000) {
            throw new InvalidArgumentException('The Sharingan result is missing or too large.');
        }
        $result = json_decode($rawResult, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result) || array_is_list($result)) {
            throw new InvalidArgumentException('The Sharingan result must be a JSON object.');
        }
        $run = $store->read($runKey, $projectIdentity);
        if (($run['workflow_key'] ?? '') !== $surface['workflow_key'] || ($run['route_key'] ?? '') !== $surface['route_key']) {
            bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan run is not bound to this surface.'], 403);
        }
        $orchestrator = new PhaseAiOrchestrator($projectRoot, $store);
        try {
            $run = $orchestrator->complete($runKey, $projectIdentity, 'analysis', $result);
        } catch (Throwable $error) {
            try {
                $state = $orchestrator->next($runKey, $projectIdentity);
                if (($state['stage']['stage_key'] ?? '') === 'analysis' && in_array((string) ($state['stage']['status'] ?? ''), ['RUNNING', 'VALIDATING'], true)) {
                    $orchestrator->fail($runKey, $projectIdentity, 'analysis', 'INVALID_RESULT_SCHEMA', $error->getMessage());
                }
            } catch (Throwable) {
                // Preserve the original schema error.
            }
            throw $error;
        }
        $analysisHash = SharinganSurfaceWorkflow::hashObject($result);
        $orchestrator->begin($runKey, $projectIdentity, 'persistence', ['operation' => 'persist_validated_sharingan_analysis', 'analysis_hash' => $analysisHash]);
        $run = $orchestrator->complete($runKey, $projectIdentity, 'persistence', [
            'schemaVersion' => SharinganSurfaceWorkflow::PERSISTENCE_SCHEMA,
            'status' => 'saved',
            'analysisHash' => $analysisHash,
            'readBackVerified' => true,
        ]);
        $readBack = $store->read($runKey, $projectIdentity);
        if (($readBack['status'] ?? '') !== 'SUCCEEDED') {
            throw new RuntimeException('The Sharingan run completion could not be read back.');
        }
        bx_sharingan_json(['ok' => true, 'message' => 'Sharingan analysis validated and saved for approval.', 'data' => ['run' => $readBack, 'analysis' => $result, 'analysis_hash' => $analysisHash]]);
    }

    bx_sharingan_json(['ok' => false, 'message' => 'The Sharingan action is not supported.'], 404);
} catch (PhaseAiBridgeException $error) {
    bx_sharingan_json(['ok' => false, 'error_code' => $error->errorCode(), 'message' => $error->getMessage()], $error->errorCode() === 'PERMISSION_DENIED' ? 403 : ($error->errorCode() === 'LOCK_CONFLICT' ? 409 : 502));
} catch (Throwable $error) {
    bx_sharingan_json(['ok' => false, 'message' => $error->getMessage()], 422);
}
