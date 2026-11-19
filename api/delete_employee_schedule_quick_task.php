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

try {
    $pdo = \Database::getConnection();
    $schedule = new \EmployeeSchedule($pdo);
    if (!$schedule->isReady()) {
        echo json_encode(['ok' => false, 'error' => $schedule->getInitError() ?: 'Module indisponible.']);
        exit;
    }

    $quickTaskId = (int)($payload['id'] ?? 0);
    $personnelId = (int)($payload['personnel_id'] ?? ($_SESSION['emploi_du_temps_personnel_id'] ?? 0));
    if (!$schedule->archiveQuickTask($quickTaskId, $personnelId)) {
        echo json_encode(['ok' => false, 'error' => 'Suppression impossible.']);
        exit;
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur.']);
}

