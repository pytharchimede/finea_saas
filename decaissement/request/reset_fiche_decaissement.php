<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    if (empty($_POST['num_fiche'])) throw new Exception('num_fiche requis');
    $num = trim($_POST['num_fiche']);
    $pdo = Database::getConnection();
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT approuve, decaisse, details_demande FROM fiche_decaissement WHERE num_fiche=? FOR UPDATE");
    $st->execute([$num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Fiche introuvable');
    if ((int)$row['approuve'] === 1) throw new Exception('Déjà approuvée (non réinitialisable)');
    if (!empty($row['decaisse'])) throw new Exception('Déjà décaissée');
    if ((int)$row['approuve'] === 0) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'already' => true]);
        exit;
    }
    // On passe de refusée (2) à en attente (0) et on journalise l'action dans details_demande
    $secur = !empty($_SESSION['secur_projet']) ? $_SESSION['secur_projet'] : 'SYSTEM';
    $prev = $row['details_demande'] ?? '';
    $log = "\n[RESET " . date('Y-m-d H:i') . "] Reprise du traitement par " . $secur;
    $newDetails = trim($prev . $log);
    $up = $pdo->prepare("UPDATE fiche_decaissement SET approuve=0, date_approbation=NULL, secur_valid=NULL, details_demande=:d WHERE num_fiche=:n");
    $up->execute(['d' => $newDetails, 'n' => $num]);
    $pdo->commit();
    AuditTrail::log('decaissement_fiche_reset', [
        'module' => 'decaissement',
        'action' => 'reset_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => $num,
        'entity_label' => 'Réinitialisation fiche décaissement ' . $num,
        'result' => 'success',
        'details' => [
            'num_fiche' => $num,
            'secur_projet' => $secur,
        ],
    ], $pdo);
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($pdo) && $pdo instanceof PDO) {
        AuditTrail::log('decaissement_fiche_reset_failed', [
            'module' => 'decaissement',
            'action' => 'reset_fiche_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Réinitialisation fiche décaissement',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'num_fiche' => isset($num) ? (string) $num : null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
