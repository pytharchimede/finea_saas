<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AttendanceSyncService.php';
require_once __DIR__ . '/../model/AttendanceApiClient.php';
require_once __DIR__ . '/../services/AttendanceDeviceAlertService.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = Database::getConnection();
$service = new AttendanceSyncService($pdo);

$action = strtolower(trim((string)($_GET['action'] ?? 'status')));

$actor = [
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? null,
];

try {
    if ($action === 'status') {
        $serverNow = new DateTimeImmutable('now');

        $syncIntervalMinutes = (int) (\EnvConfig::get('ATTENDANCE_SYNC_INTERVAL_MINUTES', '10') ?? '10');
        $syncIntervalMinutes = max(1, min(120, $syncIntervalMinutes));

        $lateThresholdMinutes = (int) (\EnvConfig::get('ATTENDANCE_API_LATE_MINUTES', '30') ?? '30');
        $lateThresholdMinutes = max(1, min(1440, $lateThresholdMinutes));

        $expectedSec = (int) (\EnvConfig::get('ATTENDANCE_CONTROL_EXPECTED_SEC', '') ?? '');
        if ($expectedSec <= 0) {
            $expectedSec = $syncIntervalMinutes * 60;
        }
        $expectedSec = max(30, min(86400, $expectedSec));

        $health = null;
        $healthError = null;
        try {
            $health = $service->health();
        } catch (Throwable $e) {
            $healthError = $e->getMessage();
        }

        $lastRun = null;
        $lastSuccessRun = null;
        $lastDataRun = null;
        $latestEvent = null;

        $remoteControl = null;
        $remoteControlError = null;

        try {
            $stmt = $pdo->query('SELECT id, scope, status, fetched_count, inserted_count, error_message, started_at, finished_at FROM attendance_sync_runs ORDER BY id DESC LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $lastRun = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $lastRun = null;
        }

        try {
            $stmt = $pdo->query('SELECT id, scope, status, fetched_count, inserted_count, error_message, started_at, finished_at FROM attendance_sync_runs WHERE status = "success" ORDER BY finished_at DESC, id DESC LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $lastSuccessRun = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $lastSuccessRun = null;
        }

        try {
            $stmt = $pdo->query('SELECT id, scope, status, fetched_count, inserted_count, error_message, started_at, finished_at FROM attendance_sync_runs WHERE status = "success" AND inserted_count > 0 ORDER BY finished_at DESC, id DESC LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $lastDataRun = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $lastDataRun = null;
        }

        try {
            $stmt = $pdo->query('SELECT occurred_at, occurred_on, employee_code, employee_name, event_code, event_label, inserted_at FROM attendance_events ORDER BY occurred_at DESC, id DESC LIMIT 1');
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            $latestEvent = is_array($row) ? $row : null;
        } catch (Throwable $e) {
            $latestEvent = null;
        }

        try {
            $client = new AttendanceApiClient($service->getBaseUrl());
            $remoteControl = $client->devices([
                'limit' => 50,
                'expected_sec' => $expectedSec,
            ]);
        } catch (Throwable $e) {
            try {
                $client = new AttendanceApiClient($service->getBaseUrl());
                $remoteControl = $client->controlData([
                    'collectors_limit' => 50,
                    'runs_limit' => 50,
                    'expected_sec' => $expectedSec,
                ]);
            } catch (Throwable $fallbackError) {
                $remoteControlError = $e->getMessage() . ' | Fallback logs: ' . $fallbackError->getMessage();
            }
        }

        $deviceAlerts = [];
        try {
            $alertService = new AttendanceDeviceAlertService($pdo);
            $deviceAlerts = array_merge(
                $alertService->inspect(is_array($remoteControl) ? $remoteControl : null, $remoteControlError),
                $alertService->inspectHealth(is_array($health) ? $health : null)
            );
        } catch (Throwable $ignored) {
            $deviceAlerts = [];
        }

        $nextExpectedSyncAt = null;
        if (is_array($lastSuccessRun) && !empty($lastSuccessRun['finished_at'])) {
            try {
                $nextExpectedSyncAt = (new DateTimeImmutable((string) $lastSuccessRun['finished_at']))
                    ->modify('+' . $syncIntervalMinutes . ' minutes')
                    ->format('Y-m-d H:i:s');
            } catch (Throwable $e) {
                $nextExpectedSyncAt = null;
            }
        }

        $latestEventAgeSeconds = null;
        $latestEventIsLate = null;
        if (is_array($latestEvent) && !empty($latestEvent['occurred_at'])) {
            try {
                $eventDt = new DateTimeImmutable((string) $latestEvent['occurred_at']);
                $diffSeconds = $serverNow->getTimestamp() - $eventDt->getTimestamp();
                $latestEventAgeSeconds = max(0, (int) $diffSeconds);
                $latestEventIsLate = $latestEventAgeSeconds > ($lateThresholdMinutes * 60);
            } catch (Throwable $e) {
                $latestEventAgeSeconds = null;
                $latestEventIsLate = null;
            }
        }

        echo json_encode([
            'ok' => true,
            'base_url' => $service->getBaseUrl(),
            'health' => $health,
            'health_error' => $healthError,
            'runs' => $service->listRecentRuns(20),
            'events' => $service->listRecentEvents(30),
            'server_time' => $serverNow->format('Y-m-d H:i:s'),
            'sync_interval_minutes' => $syncIntervalMinutes,
            'late_threshold_minutes' => $lateThresholdMinutes,
            'last_run' => $lastRun,
            'last_success_run' => $lastSuccessRun,
            'last_data_run' => $lastDataRun,
            'next_expected_sync_at' => $nextExpectedSyncAt,
            'latest_event' => $latestEvent,
            'latest_event_age_seconds' => $latestEventAgeSeconds,
            'latest_event_is_late' => $latestEventIsLate,
            'remote_control' => $remoteControl,
            'remote_control_error' => $remoteControlError,
            'remote_expected_sec' => $expectedSec,
            'device_alerts' => $deviceAlerts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'run_sync_today') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $limit = (int)($_POST['limit'] ?? 500);

        $result = $service->syncToday($actor, $limit);

        \AuditTrail::log('attendance_sync_manual', [
            'module' => 'admin',
            'action' => 'run_sync_today',
            'entity_type' => 'attendance_sync',
            'entity_label' => 'Attendance sync today',
            'result' => ($result['ok'] ?? false) ? 'success' : 'failure',
            'details' => $result,
        ], $pdo);

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'link_personnel') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $personnelId = (int)($_POST['personnel_id'] ?? 0);
        $employeeCode = (string)($_POST['employee_code'] ?? '');

        $result = $service->linkPersonnel($personnelId, $employeeCode, $actor);

        \AuditTrail::log('attendance_link_updated', [
            'module' => 'admin',
            'action' => 'link_personnel',
            'entity_type' => 'attendance_personnel_link',
            'entity_label' => 'Personnel #' . $personnelId,
            'result' => 'success',
            'details' => $result,
        ], $pdo);

        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'unlink_personnel') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $personnelId = (int)($_POST['personnel_id'] ?? 0);
        $ok = $service->unlinkPersonnel($personnelId, $actor);

        \AuditTrail::log('attendance_link_removed', [
            'module' => 'admin',
            'action' => 'unlink_personnel',
            'entity_type' => 'attendance_personnel_link',
            'entity_label' => 'Personnel #' . $personnelId,
            'result' => $ok ? 'success' : 'failure',
        ], $pdo);

        echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Action inconnue.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
