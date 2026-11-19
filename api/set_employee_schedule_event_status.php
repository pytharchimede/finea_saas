<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';
require_once __DIR__ . '/../model/Communication.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

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
$statusCode = (string)($payload['status_code'] ?? '');
$applyToGroup = !empty($payload['apply_to_group']);

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

if (trim($statusCode) === '') {
    echo json_encode(['ok' => false, 'error' => 'Statut manquant.']);
    exit;
}

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    $notif = new \NotificationController($pdo);

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
    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $statusCode = $schedule->resolveHierarchicalCompletionStatus($statusCode, $actorUserId, (int)($beforeRow['personnel_id'] ?? $personnelId));

    $pdo->beginTransaction();
    $res = $applyToGroup
        ? $schedule->updateEventStatusGroup($eventId, $personnelId, $statusCode)
        : $schedule->updateEventStatus($eventId, $personnelId, $statusCode);
    if (empty($res['ok'])) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        \AuditTrail::log('schedule_event_status_update_failed', [
            'module' => 'api',
            'action' => 'set_employee_schedule_event_status',
            'entity_type' => 'employee_schedule_event',
            'entity_id' => (string) $eventId,
            'entity_label' => 'Mise à jour statut créneau',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'personnel_id' => $personnelId,
                'status_code' => $statusCode,
                'apply_to_group' => $applyToGroup,
                'event_group_key' => (string)($res['event_group_key'] ?? ''),
                'error_message' => (string)($res['error'] ?? 'Mise à jour impossible.'),
            ],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Mise à jour impossible.')]);
        exit;
    }

    $targets = $applyToGroup ? $beforeGroupRows : ($beforeRow ? [$beforeRow] : []);
    foreach ($targets as $targetRow) {
        $commTaskId = (int)($targetRow['comm_task_id'] ?? 0);
        if ($commTaskId <= 0) {
            continue;
        }

        $taskUpdate = \Communication::updateTaskStatusFromSchedule($pdo, $commTaskId, (int)($_SESSION['user_id'] ?? 0), $statusCode);
        if (empty($taskUpdate['ok'])) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => (string)($taskUpdate['error'] ?? 'Synchronisation de la tâche liée impossible.')]);
            exit;
        }
    }

    if ($pdo->inTransaction()) {
        $pdo->commit();
    }

    \AuditTrail::log('schedule_event_status_updated', [
        'module' => 'api',
        'action' => 'set_employee_schedule_event_status',
        'entity_type' => 'employee_schedule_event',
        'entity_id' => (string) $eventId,
        'entity_label' => 'Mise à jour statut créneau',
        'result' => 'success',
        'details' => [
            'personnel_id' => $personnelId,
            'status_code' => $statusCode,
            'apply_to_group' => $applyToGroup,
            'updated_count' => (int)($res['updated_count'] ?? 1),
            'event_group_key' => (string)($res['event_group_key'] ?? ''),
        ],
    ], $pdo);

    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $actorName = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
    if ($actorName === '') {
        $actorName = trim((string)($_SESSION['mail_pro'] ?? ''));
    }

    $changeType = null;
    if ($applyToGroup && $statusCode === 'annule') {
        $changeType = 'group_cancelled';
    } elseif ($statusCode === 'planifie') {
        if ($applyToGroup) {
            foreach ($beforeGroupRows as $groupRow) {
                if ((string)($groupRow['status_code'] ?? '') === 'annule') {
                    $changeType = 'restored';
                    break;
                }
            }
        } elseif ($beforeRow && (string)($beforeRow['status_code'] ?? '') === 'annule') {
            $changeType = 'restored';
        }
    }

    if ($changeType !== null && $beforeRow) {
        $targets = $applyToGroup ? $beforeGroupRows : [$beforeRow];
        $recipientUserIds = [];
        foreach ($targets as $targetRow) {
            $recipientUserId = $schedule->resolveActiveUserIdByPersonnelId((int)($targetRow['personnel_id'] ?? 0));
            if ($recipientUserId !== null && $recipientUserId > 0) {
                $recipientUserIds[$recipientUserId] = true;
            }
        }

        foreach (array_keys($recipientUserIds) as $recipientUserId) {
            try {
                $notif->notifyScheduleTaskChanged((int)$recipientUserId, $changeType, [
                    'event_id' => $eventId,
                    'title' => (string)($beforeRow['title'] ?? ''),
                    'start_at' => (string)($beforeRow['start_at'] ?? ''),
                    'end_at' => (string)($beforeRow['end_at'] ?? ''),
                    'personnel_id' => $personnelId,
                    'event_group_key' => (string)($beforeRow['event_group_key'] ?? ''),
                    'group_scope' => (string)($beforeRow['group_scope'] ?? ''),
                    'group_scope_label' => (string)($beforeRow['group_scope_label'] ?? 'Affectation individuelle'),
                    'actor_user_id' => $actorUserId,
                    'actor_name' => $actorName,
                ]);
            } catch (Throwable $e) {
                // Non bloquant
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'refresh_required' => $applyToGroup,
        'updated_count' => (int)($res['updated_count'] ?? 1),
        'event_group_key' => (string)($res['event_group_key'] ?? ''),
        'status_code' => $statusCode,
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
