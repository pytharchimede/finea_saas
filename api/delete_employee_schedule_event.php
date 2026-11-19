<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';
require_once __DIR__ . '/../model/Communication.php';

header('Content-Type: application/json; charset=utf-8');

$resolveSchedulePersonnelId = static function (\EmployeeSchedule $schedule): int {
    $personnelId = (int)($_SESSION['emploi_du_temps_personnel_id'] ?? 0);
    if ($personnelId > 0) {
        return $personnelId;
    }

    $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($userId > 0) {
        $personnelId = (int)($schedule->resolvePersonnelIdByUserId($userId) ?? 0);
    }

    if ($personnelId <= 0) {
        $email = (string)($_SESSION['mail_pro'] ?? '');
        $personnelId = (int)($schedule->resolvePersonnelIdByEmail($email) ?? 0);
    }

    if ($personnelId > 0) {
        $_SESSION['emploi_du_temps_personnel_id'] = $personnelId;
    }

    return $personnelId;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Méthode invalide.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = null;
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if (!is_array($payload)) {
    $payload = $_POST;
}

$idRaw = (string)($payload['id'] ?? ($payload['event_id'] ?? ''));
$applyToGroup = !empty($payload['apply_to_group']);
$applyToRecurrence = !empty($payload['apply_to_recurrence']);

$eventId = 0;
if (preg_match('/^evt_(\d+)$/', $idRaw, $m)) {
    $eventId = (int)$m[1];
} elseif (ctype_digit($idRaw)) {
    $eventId = (int)$idRaw;
}

if ($eventId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Identifiant créneau invalide.']);
    exit;
}

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);

    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $personnelId = $resolveSchedulePersonnelId($schedule);
    if ($personnelId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Matricule non sélectionné.']);
        exit;
    }

    $beforeRow = $schedule->getEventRow($eventId, $personnelId);
    $beforeGroupRows = [];
    if ($beforeRow && trim((string)($beforeRow['event_group_key'] ?? '')) !== '') {
        $beforeGroupRows = $schedule->listEventGroupRowsByKey((string)$beforeRow['event_group_key']);
    }

    $beforeRecurrenceRows = [];
    if ($beforeRow && $applyToRecurrence) {
        $ruleId = (int)($beforeRow['recurrence_rule_id'] ?? 0);
        if ($ruleId > 0) {
            $beforeRecurrenceRows = $schedule->listRecurringRowsByRuleId($ruleId, $personnelId);
        }
    }

    $targets = $applyToRecurrence ? $beforeRecurrenceRows : ($applyToGroup ? $beforeGroupRows : ($beforeRow ? [$beforeRow] : []));

    $pdo->beginTransaction();
    foreach ($targets as $targetRow) {
        $commTaskId = (int)($targetRow['comm_task_id'] ?? 0);
        if ($commTaskId <= 0) {
            continue;
        }

        $taskDelete = \Communication::deleteTask($pdo, $commTaskId, (int)($_SESSION['user_id'] ?? 0), true, false);
        if (empty($taskDelete['ok'])) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => (string)($taskDelete['error'] ?? 'Suppression de la tâche liée impossible.')]);
            exit;
        }
    }

    if ($applyToRecurrence) {
        $result = $schedule->deleteRecurringSeries($eventId, $personnelId);
        $result['event_group_key'] = '';
    } else {
        $result = $applyToGroup
            ? $schedule->deleteEventGroup($eventId, $personnelId)
            : ['ok' => $schedule->deleteEvent($eventId, $personnelId), 'deleted_count' => 1, 'event_group_key' => ''];
    }

    if (empty($result['ok'])) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        \AuditTrail::log('schedule_event_delete_failed', [
            'module' => 'api',
            'action' => 'delete_employee_schedule_event',
            'entity_type' => 'employee_schedule_event',
            'entity_id' => (string)$eventId,
            'entity_label' => 'Suppression créneau emploi du temps',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'personnel_id' => $personnelId,
                'apply_to_group' => $applyToGroup,
                'apply_to_recurrence' => $applyToRecurrence,
                'event_group_key' => (string)($result['event_group_key'] ?? ''),
                'error_message' => (string)($result['error'] ?? 'Suppression impossible.'),
            ],
        ], $pdo);

        echo json_encode(['ok' => false, 'error' => (string)($result['error'] ?? 'Suppression impossible.')]);
        exit;
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    \AuditTrail::log('schedule_event_deleted', [
        'module' => 'api',
        'action' => 'delete_employee_schedule_event',
        'entity_type' => 'employee_schedule_event',
        'entity_id' => (string)$eventId,
        'entity_label' => 'Suppression créneau emploi du temps',
        'result' => 'success',
        'details' => [
            'personnel_id' => $personnelId,
            'apply_to_group' => $applyToGroup,
            'apply_to_recurrence' => $applyToRecurrence,
            'deleted_count' => (int)($result['deleted_count'] ?? 1),
            'event_group_key' => (string)($result['event_group_key'] ?? ''),
            'recurrence_rule_id' => (int)($result['recurrence_rule_id'] ?? 0),
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'refresh_required' => $applyToGroup || $applyToRecurrence,
        'deleted_count' => (int)($result['deleted_count'] ?? 1),
        'event_group_key' => (string)($result['event_group_key'] ?? ''),
        'recurrence_rule_id' => (int)($result['recurrence_rule_id'] ?? 0),
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
