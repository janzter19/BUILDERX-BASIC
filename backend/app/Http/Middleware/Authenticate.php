<?php
declare(strict_types=1);

namespace App\Http\Middleware;

final class Authenticate
{
    public function handle(mixed $request, callable $next): mixed
    {
        $authorization = $this->authorize(['requireAuthenticated' => true]);
        if (!(bool) ($authorization['allowed'] ?? false)) {
            return $this->deny($authorization);
        }

        return $next($request);
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
