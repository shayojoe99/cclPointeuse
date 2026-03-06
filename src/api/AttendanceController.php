<?php

declare(strict_types=1);

namespace Api;

use Api\Middleware\ApiKeyAuth;

/**
 * Attendance REST endpoints
 *
 * GET /api/attendance
 *   Query params:
 *     device_sn   string   filter by device serial number
 *     department  string   filter by device department
 *     user_id     string   filter by employee / user ID
 *     status      int      0=check-in, 1=check-out
 *     date_from   string   YYYY-MM-DD  (inclusive)
 *     date_to     string   YYYY-MM-DD  (inclusive)
 *     page        int      default 1
 *     limit       int      default 50, max 200
 *
 * GET /api/attendance/{id}
 *   Returns a single attendance log record.
 */
class AttendanceController
{
    public function index(array $params = []): void
    {
        ApiKeyAuth::authenticate('attendance', 'read');

        $pdo = \Database::connection();

        $where  = ['1=1'];
        $bind   = [];

        // ── Filters ──────────────────────────────────────────────────────────

        $deviceSn = trim($_GET['device_sn'] ?? '');
        if ($deviceSn !== '') {
            $where[] = 'a.device_sn = :device_sn';
            $bind[':device_sn'] = $deviceSn;
        }

        $department = trim($_GET['department'] ?? '');
        if ($department !== '') {
            $where[] = 'd.department = :department';
            $bind[':department'] = $department;
        }

        $userId = trim($_GET['user_id'] ?? '');
        if ($userId !== '') {
            $where[] = 'a.user_id = :user_id';
            $bind[':user_id'] = $userId;
        }

        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where[] = 'a.status = :status';
            $bind[':status'] = (int) $_GET['status'];
        }

        $dateFrom = trim($_GET['date_from'] ?? '');
        if ($dateFrom !== '' && $this->isValidDate($dateFrom)) {
            $where[] = 'a.punch_time >= :date_from';
            $bind[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim($_GET['date_to'] ?? '');
        if ($dateTo !== '' && $this->isValidDate($dateTo)) {
            $where[] = 'a.punch_time <= :date_to';
            $bind[':date_to'] = $dateTo . ' 23:59:59';
        }

        // ── Pagination ────────────────────────────────────────────────────────

        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));
        $offset = ($page - 1) * $limit;

        $whereClause = implode(' AND ', $where);

        // Count total (for pagination metadata)
        $countSql = "SELECT COUNT(*) FROM attendance_logs a
                     LEFT JOIN devices d ON a.device_id = d.id
                     WHERE {$whereClause}";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        // Fetch records
        $sql = "SELECT
                    a.id,
                    a.device_sn,
                    d.name       AS device_name,
                    d.department,
                    d.location,
                    a.user_id,
                    a.punch_time,
                    a.status,
                    CASE a.status
                        WHEN 0 THEN 'check-in'
                        WHEN 1 THEN 'check-out'
                        WHEN 4 THEN 'overtime-in'
                        WHEN 5 THEN 'overtime-out'
                        ELSE 'unknown'
                    END          AS status_label,
                    a.verify_type,
                    a.work_code,
                    a.created_at
                FROM attendance_logs a
                LEFT JOIN devices d ON a.device_id = d.id
                WHERE {$whereClause}
                ORDER BY a.punch_time DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($bind as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit',  $limit,  \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $records = $stmt->fetchAll();

        Response::paginated($records, $total, $page, $limit);
    }

    public function show(array $params = []): void
    {
        ApiKeyAuth::authenticate('attendance', 'read');

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid ID');
        }

        $pdo  = \Database::connection();
        $stmt = $pdo->prepare(
            "SELECT
                a.*,
                CASE a.status
                    WHEN 0 THEN 'check-in'
                    WHEN 1 THEN 'check-out'
                    WHEN 4 THEN 'overtime-in'
                    WHEN 5 THEN 'overtime-out'
                    ELSE 'unknown'
                END          AS status_label,
                d.name       AS device_name,
                d.department,
                d.location
             FROM attendance_logs a
             LEFT JOIN devices d ON a.device_id = d.id
             WHERE a.id = :id
             LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $record = $stmt->fetch();

        if ($record === false) {
            Response::notFound("Attendance record #{$id} not found");
        }

        Response::ok($record);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function isValidDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
