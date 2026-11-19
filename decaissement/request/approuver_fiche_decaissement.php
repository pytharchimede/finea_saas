<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../controllers/NotificationController.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../../model/Chantier.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__.'/../../services/SupplierApprovalGuard.php';

try {
    FinancialWorkflowProgress::step('Contrôle de la demande et des droits pour la fiche.');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Méthode invalide');
    if (empty($_POST['num_fiche'])) throw new Exception('num_fiche requis');
    $num = trim($_POST['num_fiche']);
    $pdo = \Database::getConnection();

    // Préparer le schéma chantier AVANT toute transaction.
    // ensureArchiveColumns() peut exécuter ALTER TABLE / CREATE INDEX, opérations
    // qui provoquent un COMMIT implicite sous MySQL/MariaDB.
    Chantier::ensureArchiveColumns($pdo);

    FinancialWorkflowProgress::begin($pdo);
    $stmt = $pdo->prepare("SELECT f.* FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id=f.chantier_id WHERE f.num_fiche = ? AND (f.chantier_id IS NULL OR c.archived_at IS NULL) FOR UPDATE");
    $stmt->execute([$num]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Fiche introuvable');
    if ((int)$row['approuve'] === 2) throw new Exception('Fiche déjà refusée');
    SupplierApprovalGuard::assertFiche($pdo,$row);
    if ((int)$row['approuve'] === 1) {
        FinancialWorkflowProgress::commit($pdo);
        echo json_encode(['ok' => true, 'already' => true]);
        exit;
    }
    if ((int)$row['approuve'] === 2) throw new Exception('Fiche déjà refusée');
    if (!empty($row['decaisse'])) throw new Exception('Fiche déjà décaissée');
    $urgentService = new \UrgentDisbursementService($pdo);
    $urgentId = $urgentService->urgentIdForFebCode((int)$row['chantier_id'], (string)$row['code_autorisation_feb']);
    $urgentService->assertChantierAvailable((int)$row['chantier_id'], $urgentId);
    $secur = !empty($_SESSION['secur_projet']) ? $_SESSION['secur_projet'] : 'SYSTEM';
    $up = $pdo->prepare("UPDATE fiche_decaissement SET approuve=1, date_approbation=NOW(), secur_valid=:s WHERE num_fiche=:n");
    $up->execute(['s' => $secur, 'n' => $num]);
    FinancialWorkflowProgress::commit($pdo);

    // La synchronisation contrat peut exécuter des requêtes DDL/métadonnées (SHOW COLUMNS).
    // Elle doit rester hors de la transaction d'approbation afin d'éviter un commit implicite
    // MySQL/MariaDB suivi de "There is no active transaction".
    (new FicheDecaissement($pdo))->syncContractPayment($num);

    // Notification au demandeur (hors transaction)
    try {
        FinancialWorkflowProgress::step('Préparation et transmission des notifications.');$nm = new \NotificationController($pdo);
        $nm->notifyDecaissementProcessed(
            is_array($row) ? $row : ['num_fiche' => $num],
            'approved',
            [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'name' => (string) ($_SESSION['user_name'] ?? ($_SESSION['secur_projet'] ?? 'SYSTEM')),
            ],
            ''
        );
    } catch (Throwable $e) {
        // Ne pas bloquer l'approbation si la notif échoue.
    }

    \AuditTrail::log('decaissement_fiche_approved', [
        'module' => 'decaissement',
        'action' => 'approve_fiche_decaissement',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string) ($row['num_fiche'] ?? $num),
        'entity_label' => 'Approbation fiche de décaissement ' . $num,
        'result' => 'success',
        'details' => [
            'num_fiche' => $num,
            'beneficiaire' => (string) ($row['beficiaire_fiche'] ?? ''),
            'montant_fiche' => isset($row['montant_fiche']) ? (float) $row['montant_fiche'] : null,
            'designation_fiche' => (string) ($row['designation_fiche'] ?? ''),
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
        ],
    ], $pdo);

    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    if (!empty($pdo) && $pdo->inTransaction()) FinancialWorkflowProgress::rollback($pdo);
    if (!empty($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('decaissement_fiche_approve_failed', [
            'module' => 'decaissement',
            'action' => 'approve_fiche_decaissement',
            'entity_type' => 'fiche_decaissement',
            'entity_id' => isset($num) ? (string) $num : null,
            'entity_label' => 'Approbation fiche de décaissement',
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

