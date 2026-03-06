<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// ── Query parameters ─────────────────────────────────────────────────────────
$page       = max(1, (int) ($_GET['page']       ?? 1));
$limit      = 50;
$offset     = ($page - 1) * $limit;
$filterSn   = trim($_GET['device_sn']   ?? '');
$filterDept = trim($_GET['department']  ?? '');
$filterUser = trim($_GET['user_id']     ?? '');
$filterDate = trim($_GET['date']        ?? date('Y-m-d'));
$filterStatus = $_GET['status'] ?? '';

// ── Database ──────────────────────────────────────────────────────────────────
try {
    $pdo = Database::connection();

    // Sidebar: device list with last seen
    $devicesStmt = $pdo->query(
        "SELECT serial_number, name, department, location, is_active, last_seen_at
         FROM devices ORDER BY department, name"
    );
    $devices = $devicesStmt->fetchAll();

    // Department list for filter dropdown
    $deptStmt = $pdo->query(
        "SELECT DISTINCT department FROM devices WHERE department IS NOT NULL ORDER BY department"
    );
    $departments = array_column($deptStmt->fetchAll(), 'department');

    // Build WHERE clause
    $where = ['DATE(a.punch_time) = :date'];
    $bind  = [':date' => $filterDate];

    if ($filterSn !== '') {
        $where[] = 'a.device_sn = :sn';
        $bind[':sn'] = $filterSn;
    }
    if ($filterDept !== '') {
        $where[] = 'd.department = :dept';
        $bind[':dept'] = $filterDept;
    }
    if ($filterUser !== '') {
        $where[] = 'a.user_id LIKE :uid';
        $bind[':uid'] = '%' . $filterUser . '%';
    }
    if ($filterStatus !== '') {
        $where[] = 'a.status = :status';
        $bind[':status'] = (int) $filterStatus;
    }

    $whereClause = implode(' AND ', $where);

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM attendance_logs a
         LEFT JOIN devices d ON a.device_id = d.id
         WHERE {$whereClause}"
    );
    $countStmt->execute($bind);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $limit));

    $recordsStmt = $pdo->prepare(
        "SELECT
            a.id,
            a.user_id,
            a.punch_time,
            a.status,
            a.verify_type,
            a.device_sn,
            d.name       AS device_name,
            d.department,
            d.location
         FROM attendance_logs a
         LEFT JOIN devices d ON a.device_id = d.id
         WHERE {$whereClause}
         ORDER BY a.punch_time DESC
         LIMIT :lim OFFSET :off"
    );
    foreach ($bind as $k => $v) {
        $recordsStmt->bindValue($k, $v);
    }
    $recordsStmt->bindValue(':lim',  $limit,  PDO::PARAM_INT);
    $recordsStmt->bindValue(':off',  $offset, PDO::PARAM_INT);
    $recordsStmt->execute();
    $records = $recordsStmt->fetchAll();

    // Today's summary stats (for the selected date)
    $statsStmt = $pdo->prepare(
        "SELECT
            COUNT(*)          AS total,
            SUM(a.status = 0) AS checkins,
            SUM(a.status = 1) AS checkouts,
            COUNT(DISTINCT a.user_id) AS unique_users
         FROM attendance_logs a
         LEFT JOIN devices d ON a.device_id = d.id
         WHERE {$whereClause}"
    );
    $statsStmt->execute($bind);
    $stats = $statsStmt->fetch();

    $dbError = null;
} catch (PDOException $e) {
    $devices     = [];
    $departments = [];
    $records     = [];
    $total       = 0;
    $totalPages  = 1;
    $stats       = ['total' => 0, 'checkins' => 0, 'checkouts' => 0, 'unique_users' => 0];
    $dbError     = $e->getMessage();
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function statusBadge(int $status): string
{
    return match ($status) {
        0 => '<span class="badge badge-in">Check-in</span>',
        1 => '<span class="badge badge-out">Check-out</span>',
        4 => '<span class="badge badge-ot-in">OT In</span>',
        5 => '<span class="badge badge-ot-out">OT Out</span>',
        default => '<span class="badge badge-unknown">Unknown</span>',
    };
}

function deviceOnline(?string $lastSeen): bool
{
    if ($lastSeen === null) return false;
    return (time() - strtotime($lastSeen)) < 300; // 5 minutes
}

function esc(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function buildUrl(array $overrides = []): string
{
    $params = array_merge([
        'date'       => $_GET['date']       ?? date('Y-m-d'),
        'device_sn'  => $_GET['device_sn']  ?? '',
        'department' => $_GET['department']  ?? '',
        'user_id'    => $_GET['user_id']    ?? '',
        'status'     => $_GET['status']     ?? '',
        'page'       => 1,
    ], $overrides);
    return '/dashboard?' . http_build_query(array_filter($params, fn($v) => $v !== ''));
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZKTeco Attendance — Dashboard</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg:        #0f1117;
            --surface:   #1a1d27;
            --border:    #2a2d3e;
            --accent:    #4f7cff;
            --accent2:   #22d3ee;
            --text:      #e2e8f0;
            --muted:     #64748b;
            --green:     #22c55e;
            --red:       #ef4444;
            --orange:    #f97316;
            --radius:    8px;
            --sidebar-w: 260px;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: var(--bg);
            color: var(--text);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ── Sidebar ──────────────────────────────────────────────── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar-header {
            padding: 20px 16px 14px;
            border-bottom: 1px solid var(--border);
        }

        .sidebar-header h1 {
            font-size: 15px;
            font-weight: 700;
            letter-spacing: .4px;
            color: var(--accent);
        }

        .sidebar-header p {
            font-size: 11px;
            color: var(--muted);
            margin-top: 2px;
        }

        .sidebar-section {
            padding: 10px 12px 6px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1px;
            color: var(--muted);
            text-transform: uppercase;
        }

        .device-list { overflow-y: auto; flex: 1; }

        .device-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 16px;
            cursor: pointer;
            border-left: 3px solid transparent;
            transition: background .15s, border-color .15s;
            text-decoration: none;
            color: inherit;
        }

        .device-item:hover { background: rgba(79,124,255,.08); }

        .device-item.active {
            border-left-color: var(--accent);
            background: rgba(79,124,255,.12);
        }

        .device-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        .dot-online  { background: var(--green); box-shadow: 0 0 6px var(--green); }
        .dot-offline { background: var(--muted); }
        .dot-inactive { background: var(--red); }

        .device-info { min-width: 0; }
        .device-info strong { display: block; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .device-info span   { font-size: 10px; color: var(--muted); }

        /* ── Main ─────────────────────────────────────────────────── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Topbar */
        .topbar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            background: var(--surface);
            flex-wrap: wrap;
        }

        .topbar form { display: contents; }

        .topbar input, .topbar select {
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 7px 10px;
            border-radius: var(--radius);
            font-size: 13px;
            outline: none;
            transition: border-color .2s;
        }
        .topbar input:focus, .topbar select:focus {
            border-color: var(--accent);
        }

        .topbar input[type=date] { width: 148px; }
        .topbar input[type=text] { width: 130px; }
        .topbar select           { width: 140px; }

        .btn {
            padding: 7px 16px;
            border-radius: var(--radius);
            border: none;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            transition: opacity .15s;
        }
        .btn:hover { opacity: .85; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-ghost   { background: var(--border); color: var(--text); }

        /* Stats bar */
        .stats-bar {
            display: flex;
            gap: 12px;
            padding: 10px 20px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 10px 18px;
            min-width: 110px;
        }
        .stat-card .val { font-size: 22px; font-weight: 700; }
        .stat-card .lbl { font-size: 11px; color: var(--muted); margin-top: 2px; }
        .stat-card.accent .val { color: var(--accent); }
        .stat-card.green  .val { color: var(--green);  }
        .stat-card.red    .val { color: var(--red);    }
        .stat-card.cyan   .val { color: var(--accent2); }

        /* Table */
        .table-wrap {
            flex: 1;
            overflow-y: auto;
            padding: 0 20px 20px;
        }

        table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }

        thead th {
            text-align: left;
            padding: 10px 12px;
            background: var(--surface);
            border-bottom: 2px solid var(--border);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .5px;
            text-transform: uppercase;
            color: var(--muted);
            position: sticky;
            top: 0;
        }

        tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; }
        tbody tr:hover { background: rgba(255,255,255,.03); }
        tbody td { padding: 10px 12px; }

        /* Badges */
        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .3px;
        }
        .badge-in      { background: rgba(34,197,94,.15); color: var(--green); border: 1px solid rgba(34,197,94,.3); }
        .badge-out     { background: rgba(239,68,68,.15);  color: var(--red);   border: 1px solid rgba(239,68,68,.3); }
        .badge-ot-in   { background: rgba(249,115,22,.15); color: var(--orange); border: 1px solid rgba(249,115,22,.3); }
        .badge-ot-out  { background: rgba(249,115,22,.15); color: var(--orange); border: 1px solid rgba(249,115,22,.3); }
        .badge-unknown { background: rgba(100,116,139,.15); color: var(--muted); border: 1px solid var(--border); }

        /* Pagination */
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            border-top: 1px solid var(--border);
            font-size: 12px;
            color: var(--muted);
        }
        .pagination .pages { display: flex; gap: 6px; }
        .page-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--text);
            border: 1px solid var(--border);
            font-size: 12px;
            transition: background .15s, border-color .15s;
        }
        .page-btn:hover, .page-btn.active {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .page-btn.disabled {
            opacity: .35;
            pointer-events: none;
        }

        /* Error */
        .alert {
            margin: 16px 20px 0;
            padding: 12px 16px;
            border-radius: var(--radius);
            font-size: 13px;
            border: 1px solid rgba(239,68,68,.4);
            background: rgba(239,68,68,.1);
            color: #fca5a5;
        }

        .no-data { text-align: center; padding: 60px 0; color: var(--muted); font-size: 14px; }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
    </style>
</head>
<body>

<!-- ── Sidebar ──────────────────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="sidebar-header">
        <h1>ZKTeco Middleware</h1>
        <p>Attendance Dashboard</p>
    </div>
    <div class="sidebar-section">Devices (<?= count($devices) ?>)</div>
    <nav class="device-list">
        <a href="<?= esc(buildUrl(['device_sn' => ''])) ?>"
           class="device-item <?= $filterSn === '' ? 'active' : '' ?>">
            <span class="device-dot dot-online"></span>
            <div class="device-info">
                <strong>All Devices</strong>
                <span>Show all records</span>
            </div>
        </a>
        <?php foreach ($devices as $dev): ?>
            <?php
            $isActive  = (bool) $dev['is_active'];
            $isOnline  = $isActive && deviceOnline($dev['last_seen_at']);
            $dotClass  = !$isActive ? 'dot-inactive' : ($isOnline ? 'dot-online' : 'dot-offline');
            $isSelected = $filterSn === $dev['serial_number'];
            ?>
            <a href="<?= esc(buildUrl(['device_sn' => $dev['serial_number'], 'page' => 1])) ?>"
               class="device-item <?= $isSelected ? 'active' : '' ?>">
                <span class="device-dot <?= $dotClass ?>"></span>
                <div class="device-info">
                    <strong><?= esc($dev['name']) ?></strong>
                    <span>
                        <?= esc($dev['department'] ?? 'No dept') ?> &middot;
                        <?= $isOnline ? 'Online' : ($isActive ? 'Offline' : 'Disabled') ?>
                    </span>
                </div>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>

<!-- ── Main ─────────────────────────────────────────────────────────────── -->
<div class="main">

    <!-- Topbar / Filters -->
    <div class="topbar">
        <form method="GET" action="/dashboard">
            <?php if ($filterSn !== ''): ?>
                <input type="hidden" name="device_sn" value="<?= esc($filterSn) ?>">
            <?php endif; ?>

            <input type="date" name="date" value="<?= esc($filterDate) ?>">

            <select name="department">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= esc($dept) ?>" <?= $filterDept === $dept ? 'selected' : '' ?>>
                        <?= esc($dept) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="user_id" placeholder="User ID…" value="<?= esc($filterUser) ?>">

            <select name="status">
                <option value="">All Status</option>
                <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Check-in</option>
                <option value="1" <?= $filterStatus === '1' ? 'selected' : '' ?>>Check-out</option>
                <option value="4" <?= $filterStatus === '4' ? 'selected' : '' ?>>OT In</option>
                <option value="5" <?= $filterStatus === '5' ? 'selected' : '' ?>>OT Out</option>
            </select>

            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="/dashboard" class="btn btn-ghost">Reset</a>
        </form>
    </div>

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat-card accent">
            <div class="val"><?= number_format((int) $stats['total']) ?></div>
            <div class="lbl">Total Punches</div>
        </div>
        <div class="stat-card green">
            <div class="val"><?= number_format((int) $stats['checkins']) ?></div>
            <div class="lbl">Check-ins</div>
        </div>
        <div class="stat-card red">
            <div class="val"><?= number_format((int) $stats['checkouts']) ?></div>
            <div class="lbl">Check-outs</div>
        </div>
        <div class="stat-card cyan">
            <div class="val"><?= number_format((int) $stats['unique_users']) ?></div>
            <div class="lbl">Unique Users</div>
        </div>
        <div class="stat-card" style="margin-left:auto; background:transparent; border-color:transparent;">
            <div class="val" style="font-size:14px; color:var(--muted)">
                <?= esc(date('l, F j, Y', strtotime($filterDate))) ?>
            </div>
            <div class="lbl">Selected Date</div>
        </div>
    </div>

    <?php if ($dbError !== null): ?>
        <div class="alert">Database error: <?= esc($dbError) ?></div>
    <?php endif; ?>

    <!-- Records table -->
    <div class="table-wrap">
        <?php if ($records === []): ?>
            <p class="no-data">No attendance records found for the selected filters.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>User ID</th>
                    <th>Punch Time</th>
                    <th>Status</th>
                    <th>Device</th>
                    <th>Department</th>
                    <th>Location</th>
                    <th>Verify</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                <tr>
                    <td style="color:var(--muted); font-size:11px"><?= esc($rec['id']) ?></td>
                    <td><strong><?= esc($rec['user_id']) ?></strong></td>
                    <td><?= esc(date('H:i:s', strtotime($rec['punch_time']))) ?></td>
                    <td><?= statusBadge((int) $rec['status']) ?></td>
                    <td style="font-size:12px">
                        <?= esc($rec['device_name'] ?? $rec['device_sn']) ?><br>
                        <span style="color:var(--muted); font-size:11px"><?= esc($rec['device_sn']) ?></span>
                    </td>
                    <td><?= esc($rec['department'] ?? '—') ?></td>
                    <td style="font-size:12px; color:var(--muted)"><?= esc($rec['location'] ?? '—') ?></td>
                    <td style="font-size:11px; color:var(--muted)"><?= esc($rec['verify_type']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <span><?= number_format($total) ?> records &middot; Page <?= $page ?> of <?= $totalPages ?></span>
        <div class="pages">
            <a href="<?= esc(buildUrl(['page' => max(1, $page - 1)])) ?>"
               class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&#8592;</a>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            for ($p = $start; $p <= $end; $p++):
            ?>
            <a href="<?= esc(buildUrl(['page' => $p])) ?>"
               class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="<?= esc(buildUrl(['page' => min($totalPages, $page + 1)])) ?>"
               class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">&#8594;</a>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /main -->

</body>
</html>
