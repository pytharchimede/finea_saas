<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';
require_once __DIR__ . '/../model/EmployeeScheduleRecurrence.php';
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

$title = (string)($payload['title'] ?? '');
$startAt = (string)($payload['start_at'] ?? '');
$endAt = (string)($payload['end_at'] ?? '');
$statusCode = (string)($payload['status_code'] ?? 'planifie');
$description = (string)($payload['description'] ?? '');
$location = (string)($payload['location'] ?? '');
$assignmentType = (string)($payload['assignment_type'] ?? 'bureau');
$assignmentChantierId = (int)($payload['assignment_chantier_id'] ?? 0);
$targetScope = strtolower(trim((string)($payload['target_scope'] ?? 'single')));
$targetScopeLabel = trim((string)($payload['target_scope_label'] ?? ''));
$targetPersonnelIdsRaw = $payload['target_personnel_ids'] ?? [];
$targetServiceId = (int)($payload['target_service_id'] ?? 0);

$recurrence = $payload['recurrence'] ?? null;
$isRecurring = is_array($recurrence) && !empty($recurrence['enabled']);

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    $ruleId = 0;

    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $personnelId = $resolveSchedulePersonnelId($schedule);
    if ($personnelId <= 0 && $targetScope === 'single') {
        echo json_encode(['ok' => false, 'error' => 'Matricule non sélectionné.']);
        exit;
    }

    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $notif = new \NotificationController($pdo);

    $targetPersonnelIds = [];
    if ($targetScope === 'selected' && is_array($targetPersonnelIdsRaw)) {
        $targetPersonnelIds = $schedule->normalizeTargetPersonnelIds($targetPersonnelIdsRaw);
        $targetScopeLabel = $targetScopeLabel !== '' ? $targetScopeLabel : 'Sélection manuelle';
    } elseif ($targetScope === 'service') {
        $targetPersonnelIds = $schedule->listAssignablePersonnelIdsByService($targetServiceId);
        if ($targetScopeLabel === '' && $targetServiceId > 0) {
            foreach ($schedule->listSelectableServices() as $serviceRow) {
                if ((int)($serviceRow['id'] ?? 0) === $targetServiceId) {
                    $targetScopeLabel = 'Service : ' . trim((string)($serviceRow['label'] ?? ''));
                    break;
                }
            }
        }
    } elseif ($targetScope === 'team') {
        $targetPersonnelIds = $schedule->listAssignablePersonnelIdsForWholeTeam();
        $targetScopeLabel = $targetScopeLabel !== '' ? $targetScopeLabel : 'Toute l\'équipe';
    }

    if ($targetScope === 'single') {
        $targetPersonnelIds = $personnelId > 0 ? [$personnelId] : [];
        $targetScopeLabel = $targetScopeLabel !== '' ? $targetScopeLabel : 'Affectation individuelle';
    }

    $targetPersonnelIds = $schedule->normalizeTargetPersonnelIds($targetPersonnelIds);
    if ($targetPersonnelIds === []) {
        echo json_encode(['ok' => false, 'error' => 'Aucune cible valide pour cette tâche.']);
        exit;
    }

    if ($isRecurring && count($targetPersonnelIds) > 1) {
        echo json_encode(['ok' => false, 'error' => 'La répétition n\'est disponible que pour une tâche individuelle.']);
        exit;
    }

    $res = null;
    $groupMeta = [
        'group_scope' => count($targetPersonnelIds) > 1 ? $targetScope : null,
        'group_scope_label' => count($targetPersonnelIds) > 1 ? $targetScopeLabel : null,
    ];

    if ($isRecurring) {
        $recur = new \EmployeeScheduleRecurrence($pdo);
        if (!$recur->isReady()) {
            echo json_encode(['ok' => false, 'error' => $recur->getInitError() ?: 'Module récurrence indisponible.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $ruleRes = $recur->createRuleFromCreatePayload($personnelId, $title, $startAt, $endAt, $statusCode, $createdBy, $description, $location, is_array($recurrence) ? $recurrence : [], $assignmentType, $assignmentChantierId);
            if (empty($ruleRes['ok'])) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => (string)($ruleRes['error'] ?? 'Création de la répétition impossible.')]);
                exit;
            }

            $ruleId = (int)($ruleRes['id'] ?? 0);
            if ($ruleId <= 0) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => 'Création de la répétition impossible.']);
                exit;
            }

            $res = $schedule->createEvent($personnelId, $title, $startAt, $endAt, $statusCode, $createdBy, $description, $location, $ruleId, null, $groupMeta, $assignmentType, $assignmentChantierId);
            if (empty($res['ok'])) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Création impossible.')]);
                exit;
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
            exit;
        }
    } else {
        $created = [];
        $skipped = [];
        $groupKey = count($targetPersonnelIds) > 1 ? ('grp_' . bin2hex(random_bytes(8))) : '';
        if ($groupKey !== '') {
            $groupMeta['event_group_key'] = $groupKey;
        }

        foreach ($targetPersonnelIds as $targetPersonnelId) {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            try {
                $eventResult = $schedule->createEvent($targetPersonnelId, $title, $startAt, $endAt, $statusCode, $createdBy, $description, $location, null, null, $groupMeta, $assignmentType, $assignmentChantierId);
                if (empty($eventResult['ok'])) {
                    throw new RuntimeException((string)($eventResult['error'] ?? 'Création impossible.'));
                }

                $createdEventId = (int)($eventResult['id'] ?? 0);
                if ($createdEventId <= 0) {
                    throw new RuntimeException('Création du créneau impossible.');
                }

                $taskResult = \Communication::createTaskFromSchedule($pdo, (int)$createdBy, $targetPersonnelId, [
                    'title' => $title,
                    'description' => $description,
                    'location' => $location,
                    'assignment_type' => $assignmentType,
                    'assignment_chantier_id' => $assignmentChantierId > 0 ? $assignmentChantierId : null,
                    'start_at' => str_replace('T', ' ', $startAt),
                    'end_at' => str_replace('T', ' ', $endAt),
                    'status_code' => $statusCode,
                ]);
                if (empty($taskResult['ok'])) {
                    throw new RuntimeException((string)($taskResult['error'] ?? 'Création de la tâche liée impossible.'));
                }

                $taskId = (int)($taskResult['task_id'] ?? 0);
                if ($taskId <= 0 || !$schedule->attachCommunicationTaskId($createdEventId, $targetPersonnelId, $taskId)) {
                    throw new RuntimeException('Liaison de la tâche liée impossible.');
                }

                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->commit();
                }

                $created[] = [
                    'personnel_id' => $targetPersonnelId,
                    'event_id' => $createdEventId,
                ];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $skipped[] = [
                    'personnel_id' => $targetPersonnelId,
                    'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Création impossible.',
                ];
            }
        }

        $res = [
            'ok' => $created !== [],
            'created' => $created,
            'skipped' => $skipped,
            'event_group_key' => $groupKey,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'error' => $created === [] ? 'Aucun créneau n\'a pu être créé.' : null,
        ];

        if (count($targetPersonnelIds) === 1 && !empty($created[0]['event_id'])) {
            $res['id'] = (int)$created[0]['event_id'];
        }
    }

    if (empty($res['ok'])) {
        \AuditTrail::log('schedule_event_create_failed', [
            'module' => 'api',
            'action' => 'create_employee_schedule_event',
            'entity_type' => 'employee_schedule_event',
            'entity_label' => $title !== '' ? $title : 'Création créneau emploi du temps',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'personnel_id' => $personnelId,
                'target_scope' => $targetScope,
                'target_count' => count($targetPersonnelIds),
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status_code' => $statusCode,
                'recurring' => $isRecurring,
                'error_message' => (string)($res['error'] ?? 'Création impossible.'),
            ],
        ], $pdo);
        echo json_encode(['ok' => false, 'error' => (string)($res['error'] ?? 'Création impossible.')]);
        exit;
    }

    $eventId = 0;
    $row = null;
    if (!empty($res['id'])) {
        $eventId = (int)$res['id'];
        $row = $eventId > 0 ? $schedule->getEventRow($eventId, $targetPersonnelIds[0]) : null;
    } elseif (!empty($res['created'][0]['event_id']) && !empty($res['created'][0]['personnel_id'])) {
        $eventId = (int)$res['created'][0]['event_id'];
        $row = $schedule->getEventRow($eventId, (int)$res['created'][0]['personnel_id']);
    }

    $createdRows = [];
    foreach ((array)($res['created'] ?? []) as $createdRow) {
        $createdPersonnelId = (int)($createdRow['personnel_id'] ?? 0);
        $createdEventId = (int)($createdRow['event_id'] ?? 0);
        if ($createdPersonnelId <= 0 || $createdEventId <= 0) {
            continue;
        }

        $createdRows[] = ['personnel_id' => $createdPersonnelId, 'event_id' => $createdEventId];
        $recipientUserId = $schedule->resolveActiveUserIdByPersonnelId($createdPersonnelId);
        if ($recipientUserId !== null && $recipientUserId > 0) {
            try {
                $notif->notifyScheduleTaskAssigned($recipientUserId, [
                    'event_id' => $createdEventId,
                    'title' => $title,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'personnel_id' => $createdPersonnelId,
                    'event_group_key' => (string)($res['event_group_key'] ?? ''),
                    'group_scope' => count($targetPersonnelIds) > 1 ? $targetScope : 'single',
                    'group_scope_label' => $targetScopeLabel,
                ]);
            } catch (Throwable $e) {
                // Notification best effort.
            }
        }
    }

    if ($createdRows === [] && $eventId > 0 && !empty($targetPersonnelIds[0])) {
        $createdRows[] = ['personnel_id' => (int)$targetPersonnelIds[0], 'event_id' => $eventId];
        $recipientUserId = $schedule->resolveActiveUserIdByPersonnelId((int)$targetPersonnelIds[0]);
        if ($recipientUserId !== null && $recipientUserId > 0) {
            try {
                $notif->notifyScheduleTaskAssigned($recipientUserId, [
                    'event_id' => $eventId,
                    'title' => $title,
                    'start_at' => $startAt,
                    'end_at' => $endAt,
                    'personnel_id' => (int)$targetPersonnelIds[0],
                    'event_group_key' => (string)($res['event_group_key'] ?? ''),
                    'group_scope' => count($targetPersonnelIds) > 1 ? $targetScope : 'single',
                    'group_scope_label' => $targetScopeLabel,
                ]);
            } catch (Throwable $e) {
                // Notification best effort.
            }
        }
    }

    $startIso = null;
    $endIso = null;
    if ($row && !empty($row['start_at'])) {
        try {
            $startIso = (new DateTimeImmutable((string)$row['start_at']))->format('Y-m-d\\TH:i:s');
        } catch (Throwable $e) {
            $startIso = null;
        }
    }
    if ($row && !empty($row['end_at'])) {
        try {
            $endIso = (new DateTimeImmutable((string)$row['end_at']))->format('Y-m-d\\TH:i:s');
        } catch (Throwable $e) {
            $endIso = null;
        }
    }

    if (!$row || !$startIso || !$endIso) {
        // Retour minimal si on ne peut pas reconstruire un item fiable
        echo json_encode(['ok' => true, 'id' => 'evt_' . $eventId]);
        exit;
    }

    $item = [
        'id' => 'evt_' . (string)$eventId,
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

    \AuditTrail::log('schedule_event_created', [
        'module' => 'api',
        'action' => 'create_employee_schedule_event',
        'entity_type' => 'employee_schedule_event',
        'entity_id' => (string) $eventId,
        'entity_label' => $title !== '' ? $title : ((string) ($row['title'] ?? 'Créneau emploi du temps')),
        'result' => 'success',
        'details' => [
            'personnel_id' => $personnelId,
            'target_scope' => $targetScope,
            'target_count' => count($targetPersonnelIds),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status_code' => $statusCode,
            'recurring' => $isRecurring,
            'recurrence_rule_id' => $ruleId > 0 ? $ruleId : null,
            'event_group_key' => (string)($res['event_group_key'] ?? ''),
        ],
    ], $pdo);

    echo json_encode([
        'ok' => true,
        'item' => count($targetPersonnelIds) === 1 ? $item : null,
        'created_count' => count($createdRows),
        'skipped_count' => (int)($res['skipped_count'] ?? 0),
        'refresh_required' => count($targetPersonnelIds) > 1,
        'message' => count($targetPersonnelIds) > 1
            ? ('Tâche groupée créée pour ' . count($createdRows) . ' personne(s)' . ((int)($res['skipped_count'] ?? 0) > 0 ? (' • ' . (int)($res['skipped_count'] ?? 0) . ' ignorée(s)') : '') . '.')
            : 'Tâche créée.',
    ]);
    exit;
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
    exit;
}
