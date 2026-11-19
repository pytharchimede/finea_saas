<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';

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

$formatQuickTask = static function (array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'personnel_id' => (int)($row['personnel_id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'status_code' => (string)($row['status_code'] ?? 'planifie'),
        'created_at' => (string)($row['created_at'] ?? ''),
        'personnel_name' => (string)($row['personnel_name'] ?? ''),
        'personnel_matricule' => (string)($row['personnel_matricule'] ?? ''),
        'service_label' => (string)($row['service_label'] ?? ''),
    ];
};

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $personnelId = (int)($payload['personnel_id'] ?? ($_SESSION['emploi_du_temps_personnel_id'] ?? 0));
    $title = (string)($payload['title'] ?? '');
    $description = (string)($payload['description'] ?? '');
    $createdBy = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $result = $schedule->createQuickTask($personnelId, $title, $createdBy, $description);
    if (empty($result['ok'])) {
        echo json_encode(['ok' => false, 'error' => (string)($result['error'] ?? 'Creation rapide impossible.')]);
        exit;
    }

    $row = $schedule->getQuickTaskRow((int)$result['id'], $personnelId);
    echo json_encode([
        'ok' => true,
        'item' => $row ? $formatQuickTask($row) : [
            'id' => (int)$result['id'],
            'personnel_id' => $personnelId,
            'title' => trim($title),
        ],
    ]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
}

