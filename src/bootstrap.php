<?php

declare(strict_types=1);

/**
 * Bootstrap: load .env, set error handling, initialise autoloader.
 * Must be required before anything else.
 */

// ── Error handling ──────────────────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ── .env loader ─────────────────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';

if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// ── Autoloader ───────────────────────────────────────────────────────────────
// Maps: Middleware\Foo  → src/Foo.php
//       Middleware\Api\Bar → src/api/Bar.php  (custom mapping below)
spl_autoload_register(function (string $class): void {
    $map = [
        'Database'                      => __DIR__ . '/Database.php',
        'Router'                        => __DIR__ . '/Router.php',
        'Adms\\AdmsHandler'             => __DIR__ . '/adms/AdmsHandler.php',
        'Api\\Response'                 => __DIR__ . '/api/Response.php',
        'Api\\Middleware\\ApiKeyAuth'   => __DIR__ . '/api/Middleware/ApiKeyAuth.php',
        'Api\\AttendanceController'     => __DIR__ . '/api/AttendanceController.php',
        'Api\\DeviceController'         => __DIR__ . '/api/DeviceController.php',
    ];

    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Retrieve an env variable with an optional default.
 */
function env(string $key, mixed $default = null): mixed
{
    return $_ENV[$key] ?? $default;
}

/**
 * Write a line to logs/YYYY-MM-DD.log.
 */
function log_request(string $message): void
{
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    $file = $logDir . '/' . date('Y-m-d') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
