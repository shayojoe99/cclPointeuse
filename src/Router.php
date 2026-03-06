<?php

declare(strict_types=1);

/**
 * Lightweight regex-based front router.
 *
 * Usage:
 *   $router = new Router();
 *   $router->get('/api/attendance',         [AttendanceController::class, 'index']);
 *   $router->get('/api/attendance/{id}',    [AttendanceController::class, 'show']);
 *   $router->post('/api/devices',           [DeviceController::class, 'store']);
 *   $router->dispatch();
 */
class Router
{
    /** @var array<int, array{method: string, pattern: string, handler: callable|array}> */
    private array $routes = [];

    // ── Registration helpers ─────────────────────────────────────────────────

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function patch(string $path, callable|array $handler): void
    {
        $this->add('PATCH', $path, $handler);
    }

    public function delete(string $path, callable|array $handler): void
    {
        $this->add('DELETE', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->toRegex($path),
            'handler' => $handler,
        ];
    }

    // ── Dispatch ─────────────────────────────────────────────────────────────

    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // Support X-HTTP-Method-Override for clients that can only send POST
        if ($method === 'POST' && isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            $method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = '/' . ltrim((string) $path, '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (preg_match($route['pattern'], $path, $matches)) {
                // Named captures become the $params array
                $params = array_filter(
                    $matches,
                    fn($k) => is_string($k),
                    ARRAY_FILTER_USE_KEY
                );

                $this->call($route['handler'], $params);
                return;
            }
        }

        // No route matched
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not Found', 'path' => $path]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * Convert a route path like /api/devices/{sn} to a named-capture regex.
     */
    private function toRegex(string $path): string
    {
        $pattern = preg_replace('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', '(?P<$1>[^/]+)', $path);
        return '#^' . $pattern . '$#';
    }

    private function call(callable|array $handler, array $params): void
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0])) {
            [$class, $method] = $handler;
            $instance = new $class();
            $instance->$method($params);
        } else {
            ($handler)($params);
        }
    }
}
