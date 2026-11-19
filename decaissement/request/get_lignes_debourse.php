<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../services/FinancialPlanningSeparationService.php';
require_once __DIR__ . '/../../contrats/model/ContratSousTraitance.php';

header('Content-Type: application/json');

if (!isset($_POST['debourse_id']) || empty($_POST['debourse_id'])) {
    AuditTrail::log('decaissement_lignes_load_failed', [
        'module' => 'decaissement',
        'action' => 'get_lignes_debourse',
        'entity_type' => 'debourse',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'missing_debourse_id'],
    ]);
    echo json_encode(['success' => false, 'message' => 'ID du déboursé requis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$rawDebourseId = (string)$_POST['debourse_id'];
$parts = preg_split('/\s*,\s*/', trim($rawDebourseId), -1, PREG_SPLIT_NO_EMPTY);
$debourseIds = [];
foreach ($parts as $p) {
    $v = (int)$p;
    if ($v > 0) $debourseIds[$v] = true;
}
$debourseIds = array_keys($debourseIds);
sort($debourseIds, SORT_NUMERIC);

if (count($debourseIds) === 0) {
    AuditTrail::log('decaissement_lignes_load_failed', [
        'module' => 'decaissement',
        'action' => 'get_lignes_debourse',
        'entity_type' => 'debourse',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['reason' => 'invalid_debourse_id', 'raw_debourse_id' => $rawDebourseId],
    ]);
    echo json_encode(['success' => false, 'message' => 'ID du déboursé invalide'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $pdo = Database::getConnection();
    new ContratSousTraitance($pdo);
    $officialDebourseSql = FinancialPlanningSeparationService::officialDeboursePredicate('d');
    $contractAvailabilitySql = "NOT EXISTS (SELECT 1 FROM contrat_sous_traitance_ligne csl JOIN contrat_sous_traitance cs ON cs.id=csl.contrat_id WHERE csl.ligne_devis_id=d.ligne_devis_id AND cs.statut NOT IN ('resilie','solde'))";

    // Récupérer les lignes de déboursé pour ce(s) déboursé(s)
    if (count($debourseIds) === 1) {
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.designation,
                l.montant as montant_prevu,
                l.categorie
            FROM ligne_debourse_banamur l
            INNER JOIN debourse_banamur d ON d.id=l.debourse_id
            INNER JOIN chantier_banamur c ON c.devis_id=d.devis_id AND c.archived_at IS NULL
            WHERE l.debourse_id = ?
              AND $officialDebourseSql
              AND $contractAvailabilitySql
            ORDER BY designation
        ");
        $stmt->execute([$debourseIds[0]]);
    } else {
        $placeholders = implode(',', array_fill(0, count($debourseIds), '?'));
        $stmt = $pdo->prepare("
            SELECT 
                l.id,
                l.designation,
                l.montant as montant_prevu,
                l.categorie
            FROM ligne_debourse_banamur l
            INNER JOIN debourse_banamur d ON d.id=l.debourse_id
            INNER JOIN chantier_banamur c ON c.devis_id=d.devis_id AND c.archived_at IS NULL
            WHERE l.debourse_id IN ($placeholders)
              AND $officialDebourseSql
              AND $contractAvailabilitySql
            ORDER BY designation
        ");
        $stmt->execute($debourseIds);
    }
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($lignes) {
        AuditTrail::log('decaissement_lignes_loaded', [
            'module' => 'decaissement',
            'action' => 'get_lignes_debourse',
            'entity_type' => 'debourse',
            'entity_label' => implode(',', array_map('strval', $debourseIds)),
            'details' => [
                'debourse_count' => count($debourseIds),
                'ligne_count' => count($lignes),
            ],
        ], $pdo);
        echo json_encode([
            'success' => true,
            'lignes' => $lignes
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    } else {
        AuditTrail::log('decaissement_lignes_load_failed', [
            'module' => 'decaissement',
            'action' => 'get_lignes_debourse',
            'entity_type' => 'debourse',
            'entity_label' => implode(',', array_map('strval', $debourseIds)),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'no_ligne_found'],
        ], $pdo);
        echo json_encode(['success' => false, 'message' => 'Aucune ligne de déboursé trouvée'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
} catch (Exception $e) {
    AuditTrail::log('decaissement_lignes_load_failed', [
        'module' => 'decaissement',
        'action' => 'get_lignes_debourse',
        'entity_type' => 'debourse',
        'entity_label' => isset($rawDebourseId) ? $rawDebourseId : 'get_lignes_debourse',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
