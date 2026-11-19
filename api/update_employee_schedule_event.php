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
$startAt = (string)($payload['start_at'] ?? '');
$endAt = (string)($payload['end_at'] ?? '');
$title = (string)($payload['title'] ?? '');
$statusCode = (string)($payload['status_code'] ?? '');
$description = array_key_exists('description', $payload) ? (string)($payload['description'] ?? '') : null;
$location = array_key_exists('location', $payload) ? (string)($payload['location'] ?? '') : null;
$assignmentType = array_key_exists('assignment_type', $payload) ? (string)($payload['assignment_type'] ?? 'bureau') : null;
$assignmentChantierId = array_key_exists('assignment_chantier_id', $payload) ? (int)($payload['assignment_chantier_id'] ?? 0) : null;
$transferPersonnelId = (int)($payload['transfer_personnel_id'] ?? 0);
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

if (trim($startAt) === '' || trim($endAt) === '') {
    echo json_encode(['ok' => false, 'error' => 'Dates/heures manquantes.']);
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

    $hasExtra = (
        trim($title) !== ''
        || trim($statusCode) !== ''
        || $description !== null
        || $location !== null
        || $assignmentType !== null
        || $assignmentChantierId !== null
        || $transferPersonnelId > 0
    );
    $beforeRow = $schedule->getEventRow($eventId, $personnelId);
    $beforeGroupRows = [];
    if ($beforeRow && trim((string)($beforeRow['event_group_key'] ?? '')) !== '') {
        $beforeGroupRows = $schedule->listEventGroupRowsByKey((string)$beforeRow['event_group_key']);
    }

    $row = $schedule->getEventRow($eventId, $personnelId);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Créneau introuvable.']);
        exit;
    }

    $finalTitle = trim($title) !== '' ? $title : (string)($row['title'] ?? '');
    $finalStatus = trim($statusCode) !== '' ? $statusCode : (string)($row['status_code'] ?? 'planifie');
    $finalDescription = $description !== null ? $description : (string)($row['description'] ?? '');
    $finalLocation = $location !== null ? $location : (string)($row['location'] ?? '');
    $finalAssignmentType = $assignmentType !== null ? $assignmentType : (string)($row['assignment_type'] ?? 'bureau');
    $finalAssignmentChantierId = $assignmentChantierId !== null ? $assignmentChantierId : (isset($row['assignment_chantier_id']) ? (int)$row['assignment_chantier_id'] : null);
    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $finalStatus = $schedule->resolveHierarchicalCompletionStatus($finalStatus, $actorUserId, (int)($row['personnel_id'] ?? $personnelId));

    $pdo->beginTransaction();
    $effectivePersonnelId = $personnelId;
    if ($transferPersonnelId > 0 && $transferPersonnelId !== $personnelId) {
        if ($applyToGroup) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => 'Le transfert n’est disponible que pour une tâche individuelle.']);
            exit;
        }

        $transfer = $schedule->transferEvent($eventId, $personnelId, $transferPersonnelId);
        if (empty($transfer['ok'])) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => (string)($transfer['error'] ?? 'Transfert impossible.')]);
            exit;
        }
        $effectivePersonnelId = $transferPersonnelId;
    }

    if ($hasExtra) {
        if ($applyToGroup) {
            $res = $schedule->updateEventGroup($eventId, $personnelId, $finalTitle, $startAt, $endAt, $finalStatus, $finalDescription, $finalLocation, $finalAssignmentType, $finalAssignmentChantierId);
        } else {
            $res = $schedule->updateEvent($eventId, $effectivePersonnelId, $finalTitle, $startAt, $endAt, $finalStatus, $finalDescription, $finalLocation, $finalAssignmentType, $finalAssignmentChantierId);
        }
    } else {
        if ($applyToGroup) {
            $res = $schedule->updateEventGroup(
                $eventId,
                $personnelId,
                (string)($row['title'] ?? ''),
                $startAt,
                $endAt,
                (string)($row['status_code'] ?? 'planifie'),
                array_key_exists('description', $row) ? (string)($row['description'] ?? '') : null,
                array_key_exists('location', $row) ? (string)($row['location'] ?? '') : null,
                $finalAssignmentType,
                $finalAssignmentChantierId
            );
        } else {
            $res = $schedule->updateEventDates($eventId, $effectivePersonnelId, $startAt, $endAt);
        }
    }
    if (empty($res['ok'])) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        \AuditTrail::log('schedule_event_update_failed', [
            'module' => 'api',
            'action' => 'update_employee_schedule_event',
            'entity_type' => 'employee_schedule_event',
            'entity_id' => (string) $eventId,
            'entity_label' => trim($title) !== '' ? $title : 'Mise à jour créneau emploi du temps',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'personnel_id' => $personnelId,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status_code' => $statusCode,
                'has_extra' => $hasExtra,
                'apply_to_group' => $applyToGroup,
                'event_group_key' => (string)($res['event_group_key'] ?? ''),
                'error_message' => (string)($res['error'] ?? 'Mise à jour impossible.'),
            ],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Mise à jour impossible.')]);
        exit;
    }

    $targets = $applyToGroup ? $beforeGroupRows : [$beforeRow];
    foreach ($targets as $targetRow) {
        $commTaskId = (int)($targetRow['comm_task_id'] ?? 0);
        $targetPersonnelId = $transferPersonnelId > 0 && !$applyToGroup ? $effectivePersonnelId : (int)($targetRow['personnel_id'] ?? 0);
        if ($commTaskId <= 0 || $targetPersonnelId <= 0) {
            continue;
        }

        $taskUpdate = \Communication::updateTaskFromSchedule($pdo, $commTaskId, (int)($_SESSION['user_id'] ?? 0), $targetPersonnelId, [
            'title' => $finalTitle,
            'description' => $finalDescription,
            'location' => $finalLocation,
            'assignment_type' => $finalAssignmentType,
            'assignment_chantier_id' => $finalAssignmentChantierId,
            'start_at' => str_replace('T', ' ', $startAt),
            'end_at' => str_replace('T', ' ', $endAt),
            'status_code' => $finalStatus,
        ]);
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

    \AuditTrail::log('schedule_event_updated', [
        'module' => 'api',
        'action' => 'update_employee_schedule_event',
        'entity_type' => 'employee_schedule_event',
        'entity_id' => (string) $eventId,
        'entity_label' => trim($title) !== '' ? $title : 'Mise à jour créneau emploi du temps',
        'result' => 'success',
        'details' => [
            'personnel_id' => $personnelId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status_code' => $statusCode,
            'has_extra' => $hasExtra,
            'apply_to_group' => $applyToGroup,
            'updated_count' => (int)($res['updated_count'] ?? 1),
            'skipped_count' => (int)($res['skipped_count'] ?? 0),
            'event_group_key' => (string)($res['event_group_key'] ?? ''),
        ],
    ], $pdo);

    $actorUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $actorName = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
    if ($actorName === '') {
        $actorName = trim((string)($_SESSION['mail_pro'] ?? ''));
    }

    $changeType = null;
    $scopeLabel = (string)($beforeRow['group_scope_label'] ?? 'Affectation individuelle');
    $groupScope = (string)($beforeRow['group_scope'] ?? '');
    $groupKey = (string)($beforeRow['event_group_key'] ?? ($res['event_group_key'] ?? ''));
    $previousStatus = (string)($beforeRow['status_code'] ?? '');
    $finalStatusForNotification = trim($statusCode) !== '' ? $statusCode : $previousStatus;

    if ($applyToGroup) {
        if ($finalStatusForNotification === 'annule') {
            $changeType = 'group_cancelled';
        } elseif ($finalStatusForNotification === 'planifie') {
            foreach ($beforeGroupRows as $groupRow) {
                if ((string)($groupRow['status_code'] ?? '') === 'annule') {
                    $changeType = 'restored';
                    break;
                }
            }
        }
        if ($changeType === null) {
            $changeType = 'group_updated';
        }
    } elseif ($previousStatus === 'annule' && $finalStatusForNotification === 'planifie') {
        $changeType = 'restored';
    }

    if ($changeType !== null) {
        $targets = $applyToGroup ? $beforeGroupRows : ($beforeRow ? [$beforeRow] : []);
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
                    'title' => trim($title) !== '' ? $title : (string)($beforeRow['title'] ?? ''),
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'personnel_id' => $personnelId,
                    'event_group_key' => $groupKey,
                    'group_scope' => $groupScope,
                    'group_scope_label' => $scopeLabel,
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
        'refresh_required' => $applyToGroup || ($transferPersonnelId > 0 && $transferPersonnelId !== $personnelId),
        'updated_count' => (int)($res['updated_count'] ?? 1),
        'skipped_count' => (int)($res['skipped_count'] ?? 0),
        'event_group_key' => (string)($res['event_group_key'] ?? ''),
        'status_code' => $finalStatus,
        'transferred_to_personnel_id' => $transferPersonnelId > 0 ? $effectivePersonnelId : null,
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
