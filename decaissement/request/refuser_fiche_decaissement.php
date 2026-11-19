<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    if (empty($_POST['num_fiche'])) throw new Exception('num_fiche requis');
    if (!isset($_POST['motif']) || trim($_POST['motif']) === '') throw new Exception('Motif requis');
    $num = trim($_POST['num_fiche']);
    $motif = trim($_POST['motif']);
    $pdo = \Database::getConnection();
    $pdo->beginTransaction();
    $st = $pdo->prepare("SELECT f.num_fiche, f.approuve, f.decaisse, f.details_demande, f.montant_fiche, f.beficiaire_fiche, f.designation_fiche, f.precision_fiche, f.created_by_user_id FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.num_fiche=? AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL) FOR UPDATE");
    $st->execute([$num]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Fiche introuvable');
    if ((int)$row['approuve'] === 2) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'already' => true]);
        exit;
    }
    if ((int)$row['approuve'] === 1) throw new Exception('Déjà approuvée');
    if (!empty($row['decaisse'])) throw new Exception('Fiche déjà décaissée');
    $secur = !empty($_SESSION['secur_projet']) ? $_SESSION['secur_projet'] : 'SYSTEM';
    $prevDetails = $row['details_demande'] ?? '';
    $motifBlock = "\n[REFUS " . date('Y-m-d H:i') . "] " . $motif;
    $newDetails = trim($prevDetails . $motifBlock);
    $up = $pdo->prepare("UPDATE fiche_decaissement SET approuve=2, date_refuse=NOW(), date_approbation=NULL, secur_valid=:s, details_demande=:d WHERE num_fiche=:n");
    $up->execute(['s' => $secur, 'd' => $newDetails, 'n' => $num]);
    (new FicheDecaissement($pdo))->syncContractPayment($num);
    $pdo->commit();

    // Notification au demandeur (hors transaction)
    try {
        $nm = new \NotificationController($pdo);
        $nm->notifyDecaissementProcessed(
            is_array($row) ? $row : ['num_fiche' => $num],
            'rejected',
            [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'name' => (string) ($_SESSION['user_name'] ?? ($_SESSION['secur_projet'] ?? 'SYSTEM')),
            ],
            $motif
        );
    } catch (Throwable $e) {
        // Ne pas bloquer le refus si la notif échoue.
    }

    \AuditTrail::log('decaissement_fiche_rejected', [
        'module' => 'decaissement',
        'action' => 'reject_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string) ($row['num_fiche'] ?? $num),
        'entity_label' => 'Refus fiche de décaissement ' . $num,
        'result' => 'success',
        'details' => [
            'num_fiche' => $num,
            'motif' => $motif,
            'beneficiaire' => (string) ($row['beficiaire_fiche'] ?? ''),
            'montant_fiche' => isset($row['montant_fiche']) ? (float) $row['montant_fiche'] : null,
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
        ],
    ], $pdo);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if (!empty($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('decaissement_fiche_reject_failed', [
            'module' => 'decaissement',
            'action' => 'reject_fiche_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Refus fiche de décaissement',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'num_fiche' => isset($num) ? (string) $num : null,
                'motif' => isset($motif) ? (string) $motif : null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
