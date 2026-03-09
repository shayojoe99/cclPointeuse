<?php

declare(strict_types=1);

namespace Api;

use Api\Middleware\ApiKeyAuth;

/**
 * Device & API-Key management REST endpoints
 *
 * ── Devices ────────────────────────────────────────────────────────────────
 * GET    /api/devices               list all registered devices
 * POST   /api/devices               register a new device
 * GET    /api/devices/{sn}          get device detail + last seen + today's count
 * PATCH  /api/devices/{sn}          update name / department / location / is_active
 *
 * ── API Keys ───────────────────────────────────────────────────────────────
 * GET    /api/keys                  list all api keys (hash hidden)
 * POST   /api/keys                  generate a new api key for a project
 * DELETE /api/keys/{id}             revoke a key
 */
class DeviceController
{
    // ── Device: LIST ─────────────────────────────────────────────────────────

    public function index(array $params = []): void
    {
        ApiKeyAuth::authenticate('devices', 'read');

        $pdo  = \Database::connection();
        $stmt = $pdo->query(
            "SELECT
                d.*,
                COUNT(a.id)       AS total_records,
                MAX(a.punch_time) AS last_punch
             FROM devices d
             LEFT JOIN attendance_logs a ON a.device_id = d.id
             GROUP BY d.id
             ORDER BY d.department, d.name"
        );

        Response::ok($stmt->fetchAll());
    }

    // ── Device: STORE ────────────────────────────────────────────────────────

    public function store(array $params = []): void
    {
        ApiKeyAuth::authenticate('devices', 'write');

        $body = $this->parseBody();

        $errors = [];
        $sn     = trim($body['serial_number'] ?? '');
        $name   = trim($body['name'] ?? '');

        if ($sn === '') {
            $errors[] = 'serial_number is required';
        } elseif (!preg_match('/^[A-Za-z0-9\-_]{3,50}$/', $sn)) {
            $errors[] = 'serial_number must be 3-50 alphanumeric characters';
        }

        if ($name === '') {
            $errors[] = 'name is required';
        }

        if ($errors !== []) {
            Response::error('Validation failed', 422, $errors);
        }

        $department = substr(trim($body['department'] ?? ''), 0, 100);
        $location   = substr(trim($body['location'] ?? ''), 0, 255);

        $pdo = \Database::connection();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO devices (serial_number, name, department, location)
                 VALUES (:sn, :name, :dept, :loc)'
            );
            $stmt->execute([
                ':sn'   => $sn,
                ':name' => $name,
                ':dept' => $department !== '' ? $department : null,
                ':loc'  => $location  !== '' ? $location  : null,
            ]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                Response::error("Device with serial_number '{$sn}' is already registered", 409);
            }
            Response::serverError('Could not register device');
        }

        $device = $this->findBySn($sn);
        Response::created($device, 'Device registered successfully');
    }

    // ── Device: SHOW ─────────────────────────────────────────────────────────

    public function show(array $params = []): void
    {
        ApiKeyAuth::authenticate('devices', 'read');

        $sn     = $params['sn'] ?? '';
        $device = $this->findBySn($sn);

        if ($device === null) {
            Response::notFound("Device '{$sn}' not found");
        }

        $pdo = \Database::connection();

        // Attach attendance summary
        $stats = $pdo->prepare(
            "SELECT
                COUNT(*)                                        AS total_records,
                SUM(a.status = 0)                              AS total_checkins,
                SUM(a.status = 1)                              AS total_checkouts,
                COUNT(DISTINCT a.user_id)                      AS unique_users,
                COUNT(CASE WHEN DATE(a.punch_time) = CURDATE() THEN 1 END) AS today_records,
                MAX(a.punch_time)                              AS last_punch
             FROM attendance_logs a
             WHERE a.device_id = :id"
        );
        $stats->execute([':id' => $device['id']]);

        $device['stats'] = $stats->fetch();
        Response::ok($device);
    }

    // ── Device: UPDATE ───────────────────────────────────────────────────────

    public function update(array $params = []): void
    {
        ApiKeyAuth::authenticate('devices', 'write');

        $sn     = $params['sn'] ?? '';
        $device = $this->findBySn($sn);

        if ($device === null) {
            Response::notFound("Device '{$sn}' not found");
        }

        $body    = $this->parseBody();
        $updates = [];
        $bind    = [':id' => $device['id']];

        $allowed = ['name', 'department', 'location', 'is_active', 'allowed_ip'];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }
            $value = $body[$field];
            if ($field === 'is_active') {
                $value = (int) (bool) $value;
            } elseif ($field === 'allowed_ip') {
                $value = $value === null || trim((string) $value) === '' ? null : trim((string) $value);
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_IP)) {
                    Response::error("allowed_ip must be a valid IP address or null to remove the restriction", 422);
                }
            } else {
                $value = substr(trim((string) $value), 0, ($field === 'location' ? 255 : 100));
            }
            $updates[] = "{$field} = :{$field}";
            $bind[":{$field}"] = $value;
        }

        if ($updates === []) {
            Response::error('No updatable fields provided. Allowed: name, department, location, is_active, allowed_ip', 422);
        }

        $pdo = \Database::connection();
        $pdo->prepare('UPDATE devices SET ' . implode(', ', $updates) . ' WHERE id = :id')
            ->execute($bind);

        Response::ok($this->findBySn($sn), 'Device updated');
    }

    // ── API Keys: LIST ───────────────────────────────────────────────────────

    public function listKeys(array $params = []): void
    {
        ApiKeyAuth::authenticate('keys', 'admin');

        $pdo  = \Database::connection();
        $stmt = $pdo->query(
            'SELECT id, name, project_name, permissions, is_active, last_used_at, created_at
             FROM api_keys
             ORDER BY created_at DESC'
        );

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['permissions'] = is_string($row['permissions'])
                ? json_decode($row['permissions'], true)
                : $row['permissions'];
        }

        Response::ok($rows);
    }

    // ── API Keys: CREATE ─────────────────────────────────────────────────────

    public function createKey(array $params = []): void
    {
        ApiKeyAuth::authenticate('keys', 'admin');

        $body = $this->parseBody();

        $errors = [];
        $name   = trim($body['name'] ?? '');

        if ($name === '') {
            $errors[] = 'name is required';
        }

        $permissions = $body['permissions'] ?? null;
        if (!is_array($permissions) || $permissions === []) {
            $errors[] = 'permissions must be a non-empty object, e.g. {"attendance":"read","devices":"read"}';
        } else {
            $validResources = ['attendance', 'devices', 'keys'];
            $validLevels    = ['read', 'write', 'admin'];
            foreach ($permissions as $resource => $level) {
                if (!in_array($resource, $validResources, true)) {
                    $errors[] = "Unknown resource '{$resource}'. Valid: " . implode(', ', $validResources);
                }
                if (!in_array($level, $validLevels, true)) {
                    $errors[] = "Invalid level '{$level}' for '{$resource}'. Valid: " . implode(', ', $validLevels);
                }
            }
        }

        if ($errors !== []) {
            Response::error('Validation failed', 422, $errors);
        }

        // Generate a cryptographically random API key (hex, 40 bytes = 80 chars)
        $rawKey  = bin2hex(random_bytes(40));
        $keyHash = hash('sha256', $rawKey);

        $pdo = \Database::connection();
        $pdo->prepare(
            'INSERT INTO api_keys (name, project_name, api_key_hash, permissions)
             VALUES (:name, :project, :hash, :perms)'
        )->execute([
            ':name'    => $name,
            ':project' => substr(trim($body['project_name'] ?? ''), 0, 100) ?: null,
            ':hash'    => $keyHash,
            ':perms'   => json_encode($permissions),
        ]);

        $id  = (int) $pdo->lastInsertId();
        $row = $pdo->prepare('SELECT id, name, project_name, permissions, is_active, created_at FROM api_keys WHERE id = :id');
        $row->execute([':id' => $id]);
        $created = $row->fetch();
        $created['permissions'] = is_string($created['permissions'])
            ? json_decode($created['permissions'], true)
            : $created['permissions'];

        // Return the raw key ONCE — it is not stored and cannot be recovered
        $created['api_key'] = $rawKey;

        Response::created($created, 'API key created. Save the api_key value — it will not be shown again.');
    }

    // ── API Keys: REVOKE ─────────────────────────────────────────────────────

    public function revokeKey(array $params = []): void
    {
        ApiKeyAuth::authenticate('keys', 'admin');

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid key ID');
        }

        $pdo  = \Database::connection();
        $stmt = $pdo->prepare('UPDATE api_keys SET is_active = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() === 0) {
            Response::notFound("API key #{$id} not found");
        }

        Response::ok(null, "API key #{$id} has been revoked");
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function findBySn(string $sn): ?array
    {
        if ($sn === '') {
            return null;
        }
        $pdo  = \Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE serial_number = :sn LIMIT 1');
        $stmt->execute([':sn' => $sn]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function parseBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
