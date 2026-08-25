<?php
declare(strict_types=1);

namespace App\Http\Middleware;

final class CheckBranchAccess
{
    public function handle(mixed $request, callable $next, ?string $branchKey = null): mixed
    {
        $authorization = $this->authorize([
            'requireAuthenticated' => true,
            'branchKeys' => $this->branchKeys($request, $branchKey),
        ]);
        if (!(bool) ($authorization['allowed'] ?? false)) {
            return $this->deny($authorization);
        }

        return $next($request);
    }

    private function branchKeys(mixed $request, ?string $branchKey): array
    {
        $branchKey = trim((string) ($branchKey ?? ''));
        if ($branchKey !== '') {
            return [$branchKey];
        }

        foreach (['branch_key', 'branchKey'] as $key) {
            $value = $this->requestValue($request, $key);
            if ($value !== '') {
                return [$value];
            }
        }

        return [];
    }

    private function requestValue(mixed $request, string $key): string
    {
        if (is_array($request)) {
            return trim((string) ($request[$key] ?? $request['route'][$key] ?? $request['routeParams'][$key] ?? ''));
        }
        if (is_object($request)) {
            foreach (['route', 'parameter', 'input', 'get'] as $method) {
                if (method_exists($request, $method)) {
                    $value = $request->{$method}($key);
                    if ($value !== null && trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                }
            }
        }

        return '';
    }

    private function authorize(array $requirements): array
    {
        if (!function_exists('bx_authorization_guard')) {
            require dirname(__DIR__, 4) . '/app/foundation.php';
        }

        return \bx_authorization_guard($requirements);
    }

    private function deny(array $authorization): array
    {
        return [
            'ok' => false,
            'status' => \bx_authorization_status_code($authorization),
            'message' => (string) ($authorization['message'] ?? 'Request not authorized.'),
        ];
    }
}
