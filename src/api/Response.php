<?php

declare(strict_types=1);

namespace Api;

/**
 * JSON response helper.
 * All methods send headers + body and terminate execution.
 */
class Response
{
    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, string $message = 'OK'): never
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], 200);
    }

    public static function created(mixed $data = null, string $message = 'Created'): never
    {
        self::json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], 201);
    }

    public static function paginated(array $items, int $total, int $page, int $limit): never
    {
        self::json([
            'success'    => true,
            'data'       => $items,
            'pagination' => [
                'total'        => $total,
                'page'         => $page,
                'limit'        => $limit,
                'total_pages'  => (int) ceil($total / max(1, $limit)),
            ],
        ], 200);
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $body = ['success' => false, 'message' => $message];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        self::json($body, $status);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error($message, 404);
    }

    public static function unauthorized(string $message = 'Unauthorized'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    public static function serverError(string $message = 'Internal server error'): never
    {
        self::error($message, 500);
    }
}
