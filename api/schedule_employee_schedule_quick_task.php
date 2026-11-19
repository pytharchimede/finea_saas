<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';
require_once __DIR__ . '/../model/Communication.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Methode invalide.']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = [];
if (is_string($raw) && trim($raw) !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === []) {
    $payload = $_POST;
}

$buildEventItem = static function (array $row): array {
    $startIso = (new DateTimeImmutable((string)$row['start_at']))->format('Y-m-d\\TH:i:s');
    $endIso = (new DateTimeImmutable((string)$row['end_at']))->format('Y-m-d\\TH:i:s');

    return [
        'id' => 'evt_' . (string)($row['id'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'start' => $startIso,
        'end' => $endIso,
        'allDay' => false,
        'editable' => true,
        'durationEditable' => true,
        'startEditable' => true,
        'extendedProps' => [
            'source' => 'personal',
            'status_code' => (string)($row['status_code'] ?? 'planifie'),
            'description' => (string)($row['description'] ?? ''),
            'location' => (string)($row['location'] ?? ''),
            'assignment_type' => (string)($row['assignment_type'] ?? 'bureau'),
            'assignment_chantier_id' => (int)($row['assignment_chantier_id'] ?? 0),
            'comm_task_id' => (int)($row['comm_task_id'] ?? 0),
            'event_group_key' => (string)($row['event_group_key'] ?? ''),
            'group_scope' => (string)($row['group_scope'] ?? ''),
            'group_scope_label' => (string)($row['group_scope_label'] ?? ''),
        ],
    ];
};

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $quickTaskId = (int)($payload['quick_task_id'] ?? 0);
    $personnelId = (int)($payload['personnel_id'] ?? ($_SESSION['emploi_du_temps_personnel_id'] ?? 0));
    $startAt = (string)($payload['start_at'] ?? '');
    $endAt = (string)($payload['end_at'] ?? '');
    $location = (string)($payload['location'] ?? '');
    $assignmentType = (string)($payload['assignment_type'] ?? 'bureau');
    $assignmentChantierId = (int)($payload['assignment_chantier_id'] ?? 0);
    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $quickTask = $schedule->getQuickTaskRow($quickTaskId, $personnelId);
    if (!$quickTask) {
        echo json_encode(['ok' => false, 'error' => 'Tache rapide introuvable ou deja planifiee.']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $result = $schedule->createEvent(
            $personnelId,
            (string)($quickTask['title'] ?? ''),
            $startAt,
            $endAt,
            'planifie',
            $createdBy,
            (string)($quickTask['description'] ?? ''),
            $location,
            null,
            null,
            [],
            $assignmentType,
            $assignmentChantierId
        );
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Creation du creneau impossible.'));
        }

        $eventId = (int)($result['id'] ?? 0);
        if ($eventId <= 0) {
            throw new RuntimeException('Creation du creneau impossible.');
        }

        $taskResult = \Communication::createTaskFromSchedule($pdo, (int)$createdBy, $personnelId, [
            'title' => (string)($quickTask['title'] ?? ''),
            'description' => (string)($quickTask['description'] ?? ''),
            'location' => $location,
            'assignment_type' => $assignmentType,
            'assignment_chantier_id' => $assignmentChantierId > 0 ? $assignmentChantierId : null,
            'start_at' => str_replace('T', ' ', $startAt),
            'end_at' => str_replace('T', ' ', $endAt),
            'status_code' => 'planifie',
        ]);
        if (empty($taskResult['ok'])) {
            throw new RuntimeException((string)($taskResult['error'] ?? 'Creation de la tache liee impossible.'));
        }

        $taskId = (int)($taskResult['task_id'] ?? 0);
        if ($taskId <= 0 || !$schedule->attachCommunicationTaskId($eventId, $personnelId, $taskId)) {
            throw new RuntimeException('Liaison de la tache liee impossible.');
        }

        if (!$schedule->markQuickTaskScheduled($quickTaskId, $personnelId, $eventId)) {
            throw new RuntimeException('Cloture de la tache rapide impossible.');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Planification impossible.']);
        exit;
    }

    $row = $schedule->getEventRow($eventId, $personnelId);
    if (!$row) {
        echo json_encode(['ok' => true, 'refresh_required' => true]);
        exit;
    }

    $recipientUserId = $schedule->resolveActiveUserIdByPersonnelId($personnelId);
    if ($recipientUserId !== null && $recipientUserId > 0) {
        try {
            (new \NotificationController($pdo))->notifyScheduleTaskAssigned($recipientUserId, [
                'event_id' => $eventId,
                'title' => (string)($row['title'] ?? ''),
                'start_at' => $startAt,
                'end_at' => $endAt,
                'personnel_id' => $personnelId,
                'group_scope' => 'single',
                'group_scope_label' => 'Affectation rapide',
            ]);
        } catch (Throwable $e) {
            // Notification best effort.
        }
    }

    \AuditTrail::log('schedule_quick_task_scheduled', [
        'module' => 'api',
        'action' => 'schedule_employee_schedule_quick_task',
        'entity_type' => 'employee_schedule_quick_task',
        'entity_id' => (string)$quickTaskId,
        'entity_label' => (string)($quickTask['title'] ?? 'Tache rapide'),
        'result' => 'success',
        'details' => [
            'personnel_id' => $personnelId,
            'event_id' => $eventId,
            'start_at' => $startAt,
            'end_at' => $endAt,
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'quick_task_id' => $quickTaskId,
        'item' => $buildEventItem($row),
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
}

