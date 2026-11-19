<?php

declare(strict_types=1);

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AttendanceApiClient.php';

$code = (string)($argv[1] ?? '');
$code = trim($code);
if ($code === '') {
    fwrite(STDERR, "Usage: php tools/debug_attendance_code.php <employee_code> [YYYY-MM-DD]\n");
    exit(2);
}

$date = (string)($argv[2] ?? date('Y-m-d'));
$date = trim($date);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Invalid date format (expected YYYY-MM-DD)\n");
    exit(2);
}

$pdo = Database::getConnection();
$client = new AttendanceApiClient();

echo "Base URL: ", $client->getBaseUrl(), "\n";
echo "Target employee_code=", $code, " date=", $date, "\n\n";

// 1) API today.php
try {
    $apiToday = $client->today(['employee_code' => $code, 'limit' => 200]);
    $records = $apiToday['records'] ?? null;
    $count = is_array($records) ? count($records) : 0;
    echo "API /today.php records=", $count, "\n";
    if (is_array($records)) {
        foreach (array_slice($records, 0, 10) as $r) {
            echo "  - ", ($r['occurred_at'] ?? ''), " | ", ($r['event_code'] ?? ''), " | ", ($r['event_uid'] ?? ''), "\n";
        }
        if ($count > 0) {
            $last = $records[0];
            echo "  Top occurred_at=", ($last['occurred_at'] ?? ''), "\n";
        }
    }
} catch (Throwable $e) {
    echo "API /today.php ERROR: ", $e->getMessage(), "\n";
}

echo "\n";

// 2) API records.php for the date window
try {
    $from = $date . ' 00:00:00';
    $to = $date . ' 23:59:59';
    $apiRecords = $client->records(['employee_code' => $code, 'from' => $from, 'to' => $to, 'limit' => 500]);
    $records = $apiRecords['records'] ?? null;
    $count = is_array($records) ? count($records) : 0;
    echo "API /records.php records=", $count, " (from=", $from, " to=", $to, ")\n";
    if (is_array($records)) {
        foreach (array_slice($records, 0, 10) as $r) {
            echo "  - ", ($r['occurred_at'] ?? ''), " | ", ($r['event_code'] ?? ''), " | ", ($r['event_uid'] ?? ''), "\n";
        }
        if ($count > 0) {
            $last = $records[0];
            echo "  Top occurred_at=", ($last['occurred_at'] ?? ''), "\n";
        }
    }
} catch (Throwable $e) {
    echo "API /records.php ERROR: ", $e->getMessage(), "\n";
}

echo "\n";

// 3) DB: attendance_events
$stmt = $pdo->prepare('SELECT occurred_at, event_code, event_uid FROM attendance_events WHERE employee_code = :code AND occurred_on = :d ORDER BY occurred_at DESC LIMIT 20');
$stmt->execute([':code' => $code, ':d' => $date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "DB attendance_events rows=", count($rows), "\n";
foreach (array_slice($rows, 0, 10) as $r) {
    echo "  - ", (string)$r['occurred_at'], " | ", (string)($r['event_code'] ?? ''), " | ", (string)$r['event_uid'], "\n";
}
if (!empty($rows)) {
    echo "  Top occurred_at=", (string)$rows[0]['occurred_at'], "\n";
}

echo "\n";

// 4) DB: rh_attendance_daily via link
$stmt = $pdo->prepare('SELECT personnel_id FROM attendance_personnel_links WHERE employee_code = :code LIMIT 1');
$stmt->execute([':code' => $code]);
$pid = (int)($stmt->fetchColumn() ?: 0);

echo "DB attendance_personnel_links personnel_id=", $pid ?: 'NULL', "\n";
if ($pid > 0) {
    $stmt = $pdo->prepare('SELECT personnel_id, attendance_date, check_in_time, check_out_time, notes, updated_at FROM rh_attendance_daily WHERE personnel_id = :pid AND attendance_date = :d');
    $stmt->execute([':pid' => $pid, ':d' => $date]);
    $daily = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    echo "DB rh_attendance_daily=", $daily ? json_encode($daily, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null', "\n";
}
