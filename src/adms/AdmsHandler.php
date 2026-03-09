<?php

declare(strict_types=1);

namespace Adms;

/**
 * ADMS Protocol Handler
 *
 * Handles the two endpoints that ZKTeco devices call:
 *   GET  /adms?action=getrequest&SN={serial}  → heartbeat / keep-alive
 *   POST /adms?action=devicecmd&SN={serial}   → attendance data push (ATTLOG)
 *
 * Any device serial number must be pre-registered in the `devices` table.
 * Unknown serials are rejected with HTTP 403.
 */
class AdmsHandler
{
    public function handle(): void
    {
        $action = $_GET['action'] ?? '';
        $sn     = trim($_GET['SN'] ?? '');

        log_request(sprintf(
            'ADMS action=%s SN=%s IP=%s METHOD=%s',
            $action,
            $sn,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['REQUEST_METHOD'] ?? 'GET'
        ));

        if ($sn === '') {
            $this->refuse(400, 'Missing SN parameter');
            return;
        }

        $device = $this->findDevice($sn);

        if ($device === null) {
            log_request("ADMS REJECTED — unknown SN: {$sn}");
            $this->refuse(403, 'Device not registered');
            return;
        }

        if (!(bool) $device['is_active']) {
            log_request("ADMS REJECTED — inactive device SN: {$sn}");
            $this->refuse(403, 'Device is disabled');
            return;
        }

        $allowedIp = $device['allowed_ip'] ?? null;
        if ($allowedIp !== null && $allowedIp !== '') {
            $clientIp = $this->resolveClientIp();
            if ($clientIp !== $allowedIp) {
                log_request("ADMS REJECTED — IP mismatch SN={$sn} expected={$allowedIp} got={$clientIp}");
                $this->refuse(403, 'Source IP not allowed');
                return;
            }
        }

        match ($action) {
            'getrequest' => $this->handleGetRequest($device),
            'devicecmd'  => $this->handleDeviceCmd($device),
            default      => $this->refuse(400, "Unknown action: {$action}"),
        };
    }

    // ── Action: getrequest ───────────────────────────────────────────────────

    /**
     * Device heartbeat / polling request.
     * We reply "OK" and update last_seen_at.
     */
    private function handleGetRequest(array $device): void
    {
        $this->touchDevice((int) $device['id']);
        header('Content-Type: text/plain');
        echo 'OK';
    }

    // ── Action: devicecmd ────────────────────────────────────────────────────

    /**
     * Device is pushing attendance records (ATTLOG).
     * Body example (each line):
     *   1\t2024-03-06 08:30:00\t0\t1\t0
     *   (UserID  DateTime  Status  Verify  WorkCode)
     */
    private function handleDeviceCmd(array $device): void
    {
        $body = file_get_contents('php://input');

        if ($body === false || trim($body) === '') {
            $this->touchDevice((int) $device['id']);
            header('Content-Type: text/plain');
            echo 'OK';
            return;
        }

        $inserted = 0;
        $skipped  = 0;
        $pdo      = \Database::connection();

        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO attendance_logs
                (device_id, device_sn, user_id, punch_time, status, verify_type, work_code, raw_line)
             VALUES
                (:device_id, :device_sn, :user_id, :punch_time, :status, :verify_type, :work_code, :raw_line)'
        );

        $lines = explode("\n", trim($body));

        foreach ($lines as $rawLine) {
            $rawLine = trim($rawLine);
            if ($rawLine === '') {
                continue;
            }

            // Support both tab-separated and space-separated
            $parts = preg_split('/[\t ]+/', $rawLine);

            // Minimum: UserID + DateTime (date + time = 2 parts) → at least 3 tokens
            if (count($parts) < 3) {
                log_request("ADMS SKIP malformed line from SN={$device['serial_number']}: {$rawLine}");
                $skipped++;
                continue;
            }

            $userId    = $this->sanitiseUserId($parts[0]);
            $punchTime = $this->parsePunchTime($parts[1], $parts[2] ?? '');
            $status    = isset($parts[3]) ? (int) $parts[3] : 0;
            $verify    = isset($parts[4]) ? (int) $parts[4] : 0;
            $workCode  = isset($parts[5]) ? $parts[5] : '0';

            if ($userId === '' || $punchTime === null) {
                log_request("ADMS SKIP invalid data from SN={$device['serial_number']}: {$rawLine}");
                $skipped++;
                continue;
            }

            try {
                $stmt->execute([
                    ':device_id'   => $device['id'],
                    ':device_sn'   => $device['serial_number'],
                    ':user_id'     => $userId,
                    ':punch_time'  => $punchTime,
                    ':status'      => $status,
                    ':verify_type' => $verify,
                    ':work_code'   => $workCode,
                    ':raw_line'    => $rawLine,
                ]);
                $inserted += $stmt->rowCount();
            } catch (\PDOException $e) {
                log_request("ADMS DB ERROR SN={$device['serial_number']}: " . $e->getMessage());
                $skipped++;
            }
        }

        $this->touchDevice((int) $device['id']);

        log_request(
            "ADMS ATTLOG SN={$device['serial_number']} inserted={$inserted} skipped={$skipped}"
        );

        header('Content-Type: text/plain');
        echo 'OK';
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function findDevice(string $sn): ?array
    {
        $pdo  = \Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM devices WHERE serial_number = :sn LIMIT 1');
        $stmt->execute([':sn' => $sn]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    private function touchDevice(int $deviceId): void
    {
        $pdo  = \Database::connection();
        $stmt = $pdo->prepare(
            'UPDATE devices SET last_seen_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $deviceId]);
    }

    private function sanitiseUserId(string $raw): string
    {
        // Allow only alphanumeric + dash + underscore, max 50 chars
        return substr(preg_replace('/[^a-zA-Z0-9_\-]/', '', $raw), 0, 50);
    }

    /**
     * Parse punch time. Device may send:
     *   "2024-03-06 08:30:00"  (single token, date+time together)
     *   "2024-03-06" "08:30:00" (two tokens)
     */
    private function parsePunchTime(string $dateOrFull, string $time): ?string
    {
        // Try combined "YYYY-MM-DD HH:MM:SS" in first token
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $dateOrFull)) {
            return $dateOrFull;
        }

        // Try separate date + time tokens
        $combined = $dateOrFull . ' ' . $time;
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $combined)) {
            return $combined;
        }

        return null;
    }

    /**
     * Return the real client IP.
     * Prefers X-Forwarded-For (first hop) when the immediate peer is a
     * private/loopback address (i.e. a local reverse-proxy or Docker ingress).
     * Falls back to REMOTE_ADDR otherwise.
     */
    private function resolveClientIp(): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff !== '' && $this->isPrivateOrLoopback($remoteAddr)) {
            // Take only the first (leftmost) entry — that is the original client.
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return $remoteAddr;
    }

    private function isPrivateOrLoopback(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    private function refuse(int $code, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/plain');
        echo $message;
    }
}
