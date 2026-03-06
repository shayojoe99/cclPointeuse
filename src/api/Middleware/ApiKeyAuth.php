<?php

declare(strict_types=1);

namespace Api\Middleware;

use Api\Response;

/**
 * API Key authentication middleware.
 *
 * Reads the raw key from the X-API-Key request header,
 * SHA-256 hashes it, and looks it up in the api_keys table.
 *
 * The static method authenticate() either returns the matched
 * api_key row (as an array) or terminates with a 401/403 response.
 *
 * Permissions are stored as a JSON object, e.g.:
 *   {"attendance": "read", "devices": "read", "keys": "write"}
 *
 * Supported permission values: "read", "write", "admin"
 * "admin" is required for key management endpoints.
 *
 * Special case: if the incoming key matches APP_ADMIN_KEY env variable,
 * full admin access is granted without a DB lookup (bootstrap key).
 */
class ApiKeyAuth
{
    /**
     * Authenticate the request.
     *
     * @param string|null $requiredResource  e.g. 'attendance', 'devices', 'keys'
     * @param string      $requiredLevel     'read' | 'write' | 'admin'
     * @return array The api_key row from DB (or a synthetic admin row)
     */
    public static function authenticate(
        ?string $requiredResource = null,
        string $requiredLevel = 'read'
    ): array {
        $rawKey = self::extractKey();

        if ($rawKey === null) {
            Response::unauthorized('Missing X-API-Key header');
        }

        // Bootstrap admin key (env-only, never stored in DB)
        $adminKey = env('API_ADMIN_KEY', '');
        if ($adminKey !== '' && hash_equals($adminKey, $rawKey)) {
            return [
                'id'           => 0,
                'name'         => 'Admin',
                'project_name' => 'system',
                'permissions'  => ['attendance' => 'admin', 'devices' => 'admin', 'keys' => 'admin'],
                'is_active'    => 1,
            ];
        }

        $hashed = hash('sha256', $rawKey);
        $pdo    = \Database::connection();

        $stmt = $pdo->prepare(
            'SELECT * FROM api_keys WHERE api_key_hash = :hash AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':hash' => $hashed]);
        $row = $stmt->fetch();

        if ($row === false) {
            Response::unauthorized('Invalid or inactive API key');
        }

        // Decode permissions JSON
        $permissions = is_string($row['permissions'])
            ? (json_decode($row['permissions'], true) ?? [])
            : ($row['permissions'] ?? []);
        $row['permissions'] = $permissions;

        // Check resource-level permission
        if ($requiredResource !== null) {
            $level = $permissions[$requiredResource] ?? null;

            $levelMap = ['read' => 1, 'write' => 2, 'admin' => 3];
            $granted  = $levelMap[$level] ?? 0;
            $required = $levelMap[$requiredLevel] ?? 1;

            if ($granted < $required) {
                Response::forbidden("Insufficient permissions for resource '{$requiredResource}'");
            }
        }

        // Update last_used_at (non-blocking best-effort)
        try {
            $pdo->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id')
                ->execute([':id' => $row['id']]);
        } catch (\Throwable) {
            // Ignore — don't let a tracking failure block the request
        }

        return $row;
    }

    private static function extractKey(): ?string
    {
        // Standard header: X-API-Key
        $header = $_SERVER['HTTP_X_API_KEY'] ?? null;

        if ($header !== null) {
            return trim($header);
        }

        // Fallback: Authorization: Bearer <key>
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            return trim(substr($auth, 7));
        }

        return null;
    }
}
