<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/ProjectPlanningImport.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

header('Content-Type: application/json; charset=UTF-8');

function assignment_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function assignment_user_name(): string
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
        $taskId = (int)($_GET['task_id'] ?? 0);
        if ($taskId <= 0) {
            assignment_json_response(422, ['ok' => false, 'error' => 'task_id invalide']);
        }

        $assignment = $projectPlanningModel->getTaskAssignmentIds($taskId);
        assignment_json_response(200, ['ok' => true, 'assignment' => $assignment]);
    }

    if ($method === 'POST') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        if ($taskId <= 0) {
            assignment_json_response(422, ['ok' => false, 'error' => 'task_id invalide']);
        }

        $serviceIds = $_POST['service_ids'] ?? $_POST['service_ids[]'] ?? [];
        $personnelIds = $_POST['personnel_ids'] ?? $_POST['personnel_ids[]'] ?? [];

        $assignment = $projectPlanningModel->saveTaskAssignment($taskId, [
            'service_ids' => $serviceIds,
            'personnel_ids' => $personnelIds,
            'updated_by' => assignment_user_name(),
        ]);

        AuditTrail::log('project_planning_assignment_saved', [
            'module' => 'decaissement',
            'action' => 'project_planning_assignment',
            'entity_type' => 'project_planning_task',
            'entity_id' => (string) $taskId,
            'entity_label' => 'Affectation tâche planning ' . $taskId,
            'result' => 'success',
            'details' => [
                'task_id' => $taskId,
                'service_count' => is_array($serviceIds) ? count($serviceIds) : 0,
                'personnel_count' => is_array($personnelIds) ? count($personnelIds) : 0,
            ],
        ], $pdo);

        assignment_json_response(200, ['ok' => true, 'assignment' => $assignment]);
    }

    assignment_json_response(405, ['ok' => false, 'error' => 'Methode non autorisee']);
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('project_planning_assignment_failed', [
            'module' => 'decaissement',
            'action' => 'project_planning_assignment',
            'entity_type' => 'project_planning_task',
            'entity_id' => isset($taskId) ? (string) $taskId : null,
            'entity_label' => 'Affectation tâche planning',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'task_id' => $taskId ?? null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    assignment_json_response(500, [
        'ok' => false,
        'error' => $e->getMessage(),
    ]);
}
