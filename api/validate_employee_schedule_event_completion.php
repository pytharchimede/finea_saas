<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';

header('Content-Type: application/json; charset=utf-8');

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

$eventId = (int)($payload['event_id'] ?? ($payload['id'] ?? 0));
if ($eventId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Tâche invalide.']);
    exit;
}

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $actorUserId = (int)($_SESSION['user_id'] ?? 0);
    $row = $schedule->getEventRowById($eventId);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Tâche introuvable.']);
        exit;
    }

    $targetPersonnelId = (int)($row['personnel_id'] ?? 0);
    if (!$schedule->canValidateTaskCompletion($actorUserId, $targetPersonnelId)) {
        \AuditTrail::log('schedule_task_completion_validation_denied', [
            'module' => 'api',
            'action' => 'validate_employee_schedule_event_completion',
            'entity_type' => 'employee_schedule_event',
            'entity_id' => (string)$eventId,
            'entity_label' => (string)($row['title'] ?? 'Tâche'),
            'result' => 'denied',
            'severity' => 'warning',
            'details' => [
                'actor_user_id' => $actorUserId,
                'target_personnel_id' => $targetPersonnelId,
            ],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => 'Validation réservée au N+1, responsable ou directeur habilité.']);
        exit;
    }

    $res = $schedule->updateEventStatus($eventId, $targetPersonnelId, 'termine');
    if (empty($res['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Validation impossible.')]);
        exit;
    }

    \AuditTrail::log('schedule_task_completion_validated', [
        'module' => 'api',
        'action' => 'validate_employee_schedule_event_completion',
        'entity_type' => 'employee_schedule_event',
        'entity_id' => (string)$eventId,
        'entity_label' => (string)($row['title'] ?? 'Tâche'),
        'result' => 'success',
        'details' => [
            'actor_user_id' => $actorUserId,
            'target_personnel_id' => $targetPersonnelId,
        ],
    ], $pdo);

    echo json_encode(['ok' => true, 'event_id' => $eventId, 'status_code' => 'termine']);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
