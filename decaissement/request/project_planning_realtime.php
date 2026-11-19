<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/ProjectPlanningImport.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=UTF-8');

function realtime_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function realtime_user_name(): string
{
    $userName = trim((string)($_SESSION['user_name'] ?? $_SESSION['username'] ?? ($_SESSION['prenom'] ?? '')));
    $nom = trim((string)($_SESSION['nom'] ?? ''));
    if ($userName === '' && $nom !== '') {
        $userName = $nom;
    }
    return $userName !== '' ? $userName : 'SYSTEM';
}

try {
    $pdo = Database::getConnection();
    $projectPlanningModel = new ProjectPlanningImport($pdo);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        $importId = (int)($_GET['import_id'] ?? 0);
        if ($importId <= 0) {
            realtime_json_response(422, ['ok' => false, 'error' => 'import_id invalide']);
        }

        $snapshot = $projectPlanningModel->getRealtimeSnapshotByImport($importId, 20);
        realtime_json_response(200, ['ok' => true] + $snapshot);
    }

    if ($method === 'POST') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            realtime_json_response(422, ['ok' => false, 'error' => 'task_id invalide']);
        }

        $update = $projectPlanningModel->saveTaskRealtimeUpdate($taskId, [
            'progress' => $_POST['progress'] ?? 0,
            'status' => $_POST['status'] ?? 'en_cours',
            'meeting_date' => $_POST['meeting_date'] ?? date('Y-m-d'),
            'comment' => $_POST['comment'] ?? '',
            'updated_by' => realtime_user_name(),
        ]);

        AuditTrail::log('project_planning_realtime_saved', [
            'module' => 'decaissement',
            'action' => 'project_planning_realtime',
            'entity_type' => 'project_planning_task',
            'entity_id' => (string) $taskId,
            'entity_label' => 'Mise à jour temps réel planning ' . $taskId,
            'result' => 'success',
            'details' => [
                'task_id' => $taskId,
                'progress' => $_POST['progress'] ?? 0,
                'status' => $_POST['status'] ?? 'en_cours',
            ],
        ], $pdo);

        realtime_json_response(200, [
            'ok' => true,
            'update' => $update,
        ]);
    }

    realtime_json_response(405, ['ok' => false, 'error' => 'Methode non autorisee']);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('project_planning_realtime_failed', [
            'module' => 'decaissement',
            'action' => 'project_planning_realtime',
            'entity_type' => 'project_planning_task',
            'entity_id' => isset($taskId) ? (string) $taskId : null,
            'entity_label' => 'Mise à jour temps réel planning',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'task_id' => $taskId ?? null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    realtime_json_response(500, [
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
