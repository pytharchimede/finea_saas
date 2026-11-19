<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Session expirée.']);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Ligne de déboursé invalide.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare('SELECT l.id,l.debourse_id,l.date_debut,l.date_fin
        FROM ligne_debourse_banamur l
        INNER JOIN debourse_banamur d ON d.id=l.debourse_id
        INNER JOIN chantier_banamur c ON c.devis_id=d.devis_id AND c.archived_at IS NULL
        WHERE l.id=:id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Ligne introuvable.']);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'id' => (int)$row['id'],
        'debourse_id' => (int)$row['debourse_id'],
        'date_debut' => (string)($row['date_debut'] ?? ''),
        'date_fin' => (string)($row['date_fin'] ?? ''),
        'complete' => !empty($row['date_debut']) && !empty($row['date_fin']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Planning temporairement indisponible.']);
}
