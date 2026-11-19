<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../services/FinancialPlanningSeparationService.php';

header('Content-Type: application/json');

if (!isset($_REQUEST['chantier_id']) || empty($_REQUEST['chantier_id'])) {
    echo json_encode(['success' => false, 'message' => 'ID du chantier requis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$chantier_id = $_REQUEST['chantier_id'];

try {
    $pdo = Database::getConnection();

    // Récupérer le devis_id du chantier
    $stmt = $pdo->prepare("SELECT devis_id FROM chantier_banamur WHERE id = ? AND archived_at IS NULL");
    $stmt->execute([$chantier_id]);
    $chantier = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$chantier) {
        echo json_encode(['success' => false, 'message' => 'Chantier non trouvé'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $devis_id = $chantier['devis_id'];
    $officialDebourseSql = FinancialPlanningSeparationService::officialDeboursePredicate('d');

    // Une ligne de devis représente le service / la section budgétaire.
    // Des doublons de déboursé peuvent exister historiquement pour une même ligne.
    // On conserve uniquement le déboursé financier canonique afin de ne jamais
    // mélanger ses catégories avec des structures créées par les réunions.
    $stmt = $pdo->prepare("
        SELECT
            d.id,
            d.ligne_devis_id,
            ld.designation AS titre,
            COUNT(ldb.id) AS sous_lignes,
            CASE WHEN d.date_debut IS NOT NULL AND d.date_fin IS NOT NULL THEN 1 ELSE 0 END AS has_dates,
            CASE WHEN d.montant_debourse > 0 THEN 1 ELSE 0 END AS has_amount
        FROM debourse_banamur d
        INNER JOIN ligne_devis_banamur ld ON d.ligne_devis_id = ld.id
        LEFT JOIN ligne_debourse_banamur ldb ON ldb.debourse_id = d.id
        WHERE d.devis_id = ?
          AND $officialDebourseSql
        GROUP BY d.id, d.ligne_devis_id, ld.designation, d.date_debut, d.date_fin, d.montant_debourse
        ORDER BY d.ligne_devis_id, sous_lignes DESC, has_dates DESC, has_amount DESC, d.id DESC
    ");
    $stmt->execute([$devis_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $canonicalByService = [];
    foreach ($rows as $row) {
        $serviceId = (int)($row['ligne_devis_id'] ?? 0);
        if ($serviceId > 0 && !isset($canonicalByService[$serviceId])) {
            $canonicalByService[$serviceId] = [
                'id' => (int)$row['id'],
                'ligne_devis_id' => $serviceId,
                'titre' => (string)($row['titre'] ?? ('Service #' . $serviceId)),
            ];
        }
    }
    $debourses = array_values($canonicalByService);
    usort($debourses, static fn(array $a, array $b): int =>
        strnatcasecmp((string)$a['titre'], (string)$b['titre'])
    );

    if ($debourses) {
        echo json_encode([
            'success' => true,
            'debourses' => $debourses
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        echo json_encode(['success' => false, 'message' => 'Aucun déboursé trouvé pour ce chantier'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
