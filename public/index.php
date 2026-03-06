<?php

declare(strict_types=1);

/**
 * Front Controller
 *
 * All HTTP traffic enters here via Apache mod_rewrite (see .htaccess).
 *
 * Routing:
 *   /adms          → ADMS handler (ZKTeco device push endpoints)
 *   /api/*         → REST API (requires X-API-Key header)
 *   /dashboard     → HTML attendance viewer
 *   /              → redirect to /dashboard
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

// ── CORS (allow other projects to call the API) ──────────────────────────────
$allowedOrigins = array_filter(
    explode(',', env('CORS_ALLOWED_ORIGINS', '*'))
);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array('*', $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
header('Access-Control-Max-Age: 3600');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Path detection ────────────────────────────────────────────────────────────
$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
$path        = parse_url($requestUri, PHP_URL_PATH);
$path        = '/' . ltrim((string) $path, '/');

// ── Route: ADMS device push ───────────────────────────────────────────────────
if ($path === '/adms' || $path === '/adms/') {
    $handler = new \Adms\AdmsHandler();
    $handler->handle();
    exit;
}

// ── Route: Dashboard (HTML) ───────────────────────────────────────────────────
if ($path === '/dashboard' || $path === '/dashboard/') {
    require_once dirname(__DIR__) . '/dashboard/index.php';
    exit;
}

// ── Route: Root redirect ──────────────────────────────────────────────────────
if ($path === '/') {
    header('Location: /dashboard');
    exit;
}

// ── Route: REST API ───────────────────────────────────────────────────────────
if (str_starts_with($path, '/api')) {
    header('Content-Type: application/json; charset=utf-8');

    $router = new \Router();

    // Attendance
    $router->get('/api/attendance',          [\Api\AttendanceController::class, 'index']);
    $router->get('/api/attendance/{id}',     [\Api\AttendanceController::class, 'show']);

    // Devices
    $router->get('/api/devices',             [\Api\DeviceController::class, 'index']);
    $router->post('/api/devices',            [\Api\DeviceController::class, 'store']);
    $router->get('/api/devices/{sn}',        [\Api\DeviceController::class, 'show']);
    $router->patch('/api/devices/{sn}',      [\Api\DeviceController::class, 'update']);

    // API Key management
    $router->get('/api/keys',                [\Api\DeviceController::class, 'listKeys']);
    $router->post('/api/keys',               [\Api\DeviceController::class, 'createKey']);
    $router->delete('/api/keys/{id}',        [\Api\DeviceController::class, 'revokeKey']);

    // API health check (no auth required)
    $router->get('/api/ping', function (array $params): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'ZKTeco Middleware is running',
            'time'    => date('Y-m-d H:i:s'),
            'version' => '1.0.0',
        ]);
    });

    $router->dispatch();
    exit;
}

// ── 404 catch-all ─────────────────────────────────────────────────────────────
http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Not Found']);
