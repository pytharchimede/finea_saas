<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../model/Database.php';
require_once '../model/Chantier.php';
require_once '../model/Operation.php';
require_once '../model/Designation.php';
require_once '../model/Precision.php';
require_once '../model/Devis.php';
require_once '../model/AuditTrail.php';
require_once '../controllers/CommercialProjectFinanceController.php';
require_once '../services/ChantierCreationGuardService.php';
require_once '../services/ChantierFinancialComplianceService.php';
require_once '../services/DevisOfferProjectMigrationService.php';

header('Content-Type: application/json; charset=utf-8');
session_start();

$response = ['success' => false, 'message' => ''];
try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        \AuditTrail::log('chantier_create_failed', [
            'module' => 'request',
            'action' => 'creer_chantier',
            'entity_type' => 'chantier',
            'entity_label' => 'Creation chantier',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'invalid_method',
                'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
            ],
        ]);
        throw new Exception('Méthode non autorisée');
    }
    $titre = trim($_POST['titre'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $devisId = (int)($_POST['devis_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    $clientPoNumber = trim((string) ($_POST['client_po_number'] ?? ''));
    $clientPoAmount = (float) ($_POST['client_po_amount'] ?? 0);
    $clientPoDate = trim((string) ($_POST['client_po_date'] ?? ''));
    $projectStartDate = trim((string) ($_POST['project_start_date'] ?? ''));
    $projectEndDate = trim((string) ($_POST['project_end_date'] ?? ''));
    $billingSchedule = json_decode((string) ($_POST['billing_schedule_json'] ?? '[]'), true);
    if (!is_array($billingSchedule)) {
        $billingSchedule = [];
    }

    if ($titre === '' || $code === '' || $devisId <= 0 || $clientId <= 0
        || $clientPoNumber === '' || $clientPoAmount <= 0 || $clientPoDate === '') {
        \AuditTrail::log('chantier_create_failed', [
            'module' => 'request',
            'action' => 'creer_chantier',
            'entity_type' => 'chantier',
            'entity_label' => $titre !== '' ? $titre : 'Creation chantier',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'missing_required_fields',
                'code' => $code,
                'devis_id' => $devisId,
                'client_id' => $clientId,
            ],
        ]);
        throw new Exception('Champs requis manquants, notamment le bon de commande client.');
    }

    $pdo = Database::getConnection();
    (new DevisOfferProjectMigrationService($pdo))->ensureSchema();
    $tsCheck=$pdo->prepare("SELECT COUNT(*) FROM devis_offer_classifications WHERE devis_id=:id AND devis_type='TS'");$tsCheck->execute(['id'=>$devisId]);
    if((int)$tsCheck->fetchColumn()>0)throw new Exception('Un devis TS ne peut pas créer un nouveau chantier. Il est automatiquement rattaché au chantier de son devis principal.');
    $commercialController = new CommercialProjectFinanceController($pdo);
    $creationGuard = new ChantierCreationGuardService($pdo);
    $pdo->beginTransaction();
    // Vérifier unicité code
    $check = $pdo->prepare('SELECT id FROM chantier_banamur WHERE code = :c LIMIT 1');
    $check->execute(['c' => $code]);
    if ($check->fetchColumn()) {
        \AuditTrail::log('chantier_create_failed', [
            'module' => 'request',
            'action' => 'creer_chantier',
            'entity_type' => 'chantier',
            'entity_label' => $titre,
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'reason' => 'duplicate_code',
                'code' => $code,
                'devis_id' => $devisId,
            ],
        ], $pdo);
        throw new Exception('Ce code chantier existe déjà');
    }

    $creationGuard->savePreparation($devisId, $projectStartDate, $projectEndDate, $billingSchedule);
    $eligibility = $creationGuard->validate($devisId);
    if ((int) ($eligibility['quote']['client_id'] ?? 0) !== $clientId) {
        throw new Exception('Le client du chantier doit être celui du devis sélectionné.');
    }

    $sql = 'INSERT INTO chantier_banamur (titre, code, devis_id, client_id, date_creation, entreprise, montant_devis) VALUES (:t, :c, :d, :cl, NOW(), :ent, :mdevis)';
    // Optionnel: récupérer montant devis si dispo dans table devis
    $quoteAmountStatement = $pdo->prepare('SELECT total_ttc FROM devis_banamur WHERE id = :id LIMIT 1');
    $quoteAmountStatement->execute(['id' => $devisId]);
    $montantDevis = (float) ($quoteAmountStatement->fetchColumn() ?: 0);

    $purchaseOrder = $commercialController->createPurchaseOrder([
        'devis_id' => $devisId,
        'order_number' => $clientPoNumber,
        'amount' => $clientPoAmount,
        'order_date' => $clientPoDate,
        'expected_start_date' => trim((string) ($_POST['expected_start_date'] ?? '')) ?: $projectStartDate,
        'currency' => 'XOF',
    ], (int) ($_SESSION['user_id'] ?? 0));

    $stmt = $pdo->prepare($sql);
    $ok = $stmt->execute([
        't' => $titre,
        'c' => $code,
        'd' => $devisId,
        'cl' => $clientId,
        'ent' => 'banamur',
        'mdevis' => $montantDevis,
    ]);
    if (!$ok) {
        throw new Exception('Insertion échouée');
    }
    $chantierId = (int)$pdo->lastInsertId();
    $workDateColumns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='chantier_banamur' AND COLUMN_NAME IN ('date_debut','date_fin_prevue')")->fetchAll(PDO::FETCH_COLUMN);
    if (count($workDateColumns) === 2) {
        $pdo->prepare('UPDATE chantier_banamur SET date_debut=:start,date_fin_prevue=:end WHERE id=:id')->execute(['start' => $projectStartDate, 'end' => $projectEndDate, 'id' => $chantierId]);
    }
    $pdo->prepare("INSERT INTO devis_chantier_links(devis_id,chantier_id,link_type) SELECT dc.devis_id,:chantier,'TS' FROM devis_banamur principal INNER JOIN devis_offer_classifications dc ON dc.offre_id=principal.offre_id AND dc.devis_type='TS' WHERE principal.id=:principal ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id),link_type='TS'")->execute(['chantier'=>$chantierId,'principal'=>$devisId]);
    $commercialController->linkPurchaseOrder(
        $chantierId,
        (int) $purchaseOrder['id'],
        (int) ($_SESSION['user_id'] ?? 0)
    );

    // Insérer les opérations liées aux lignes du devis
    $lignes = $pdo->prepare('SELECT id, designation, prix, quantite, unite_id, total FROM ligne_devis_banamur WHERE devis_id = :devisId');
    $lignes->execute(['devisId' => $devisId]);
    $rows = $lignes->fetchAll(PDO::FETCH_ASSOC);
    $mapLigneToOp = [];
    if ($rows) {
        $insOp = $pdo->prepare('INSERT INTO operation_banamur (chantier_id, ligne_devis_id, designation, quantite, unite_id, prix, total) VALUES (:chantier_id, :ligne_devis_id, :designation, :quantite, :unite_id, :prix, :total)');
        foreach ($rows as $r) {
            $insOp->execute([
                'chantier_id' => $chantierId,
                'ligne_devis_id' => (int)$r['id'],
                'designation' => (string)$r['designation'],
                'quantite' => (float)$r['quantite'],
                'unite_id' => (int)$r['unite_id'],
                'prix' => (float)$r['prix'],
                'total' => (float)$r['total'],
            ]);
            $opId = (int)$pdo->lastInsertId();
            $mapLigneToOp[(int)$r['id']] = $opId;
        }
    }

    // Préparer les montants déboursés par ligne_devis pour créer les désignations
    $debStmt = $pdo->prepare('SELECT d.ligne_devis_id, SUM(d.montant_debourse) AS montant FROM debourse_banamur d JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id WHERE d.devis_id = :devisId GROUP BY d.ligne_devis_id');
    $debStmt->execute(['devisId' => $devisId]);
    $sumDebourse = [];
    foreach ($debStmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $sumDebourse[(int)$d['ligne_devis_id']] = (float)$d['montant'];
    }

    // Insérer les désignations pour chaque opération existante ayant un déboursé
    $mapLigneToDesig = [];
    if ($mapLigneToOp) {
        $insDes = $pdo->prepare('INSERT INTO designation_banamur (chantier_id, operation_id, designation, montant, qte, prix, fourniture_debourse, main_doeuvre_debourse) VALUES (:chantier_id, :operation_id, :designation, :montant, NULL, NULL, NULL, NULL)');
        // Récupérer libellé de l'opération pour réutiliser comme désignation
        $opLabelStmt = $pdo->prepare('SELECT designation FROM operation_banamur WHERE id = :id');
        foreach ($mapLigneToOp as $ligneId => $opId) {
            $montant = $sumDebourse[$ligneId] ?? null;
            if ($montant === null) continue; // pas de déboursé -> pas de désignation
            $opLabelStmt->execute(['id' => $opId]);
            $lib = (string)$opLabelStmt->fetchColumn();
            $insDes->execute([
                'chantier_id' => $chantierId,
                'operation_id' => $opId,
                'designation' => $lib,
                'montant' => $montant,
            ]);
            $mapLigneToDesig[$ligneId] = (int)$pdo->lastInsertId();
        }
    }

    // Insérer les précisions (lignes de déboursé) mappées vers chaque désignation
    if ($mapLigneToDesig) {
        $precStmt = $pdo->prepare('SELECT d.ligne_devis_id, l.designation AS libelle, l.montant FROM ligne_debourse_banamur l JOIN debourse_banamur d ON d.id = l.debourse_id JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id AND ld.devis_id=d.devis_id WHERE d.devis_id = :devisId');
        $precStmt->execute(['devisId' => $devisId]);
        $insPrec = $pdo->prepare('INSERT INTO precision_fiche_banamur (chantier_id, designation_id, libelle, montant) VALUES (:chantier_id, :designation_id, :libelle, :montant)');
        while ($pr = $precStmt->fetch(PDO::FETCH_ASSOC)) {
            $ligneId = (int)$pr['ligne_devis_id'];
            if (!isset($mapLigneToDesig[$ligneId])) continue;
            $insPrec->execute([
                'chantier_id' => $chantierId,
                'designation_id' => $mapLigneToDesig[$ligneId],
                'libelle' => (string)$pr['libelle'],
                'montant' => (float)$pr['montant'],
            ]);
        }
    }

    $pdo->commit();
    // Le chantier est déjà enregistré : un contrôle secondaire ne doit pas
    // faire croire au navigateur que la création a échoué.
    try {
        (new ChantierFinancialComplianceService($pdo))->auditOne($chantierId, $devisId);
    } catch (Throwable $auditError) {
        error_log('Chantier financial audit failed for chantier ' . $chantierId . ': ' . $auditError->getMessage());
    }

    \AuditTrail::log('chantier_created', [
        'module' => 'request',
        'action' => 'creer_chantier',
        'entity_type' => 'chantier',
        'entity_id' => (string)$chantierId,
        'entity_label' => $titre,
        'result' => 'success',
        'details' => [
            'code' => $code,
            'devis_id' => $devisId,
            'client_id' => $clientId,
            'operations_created' => count($mapLigneToOp),
            'designations_created' => count($mapLigneToDesig),
        ],
    ], $pdo);

    $response['success'] = true;
    $response['chantierId'] = $chantierId;
    $response['amountWarning'] = $purchaseOrder['warning'] ?? null;
    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $response['message'] = $e->getMessage();
    if (isset($pdo) && $pdo instanceof PDO) {
        \AuditTrail::log('chantier_create_failed', [
            'module' => 'request',
            'action' => 'creer_chantier',
            'entity_type' => 'chantier',
            'entity_label' => isset($titre) && $titre !== '' ? $titre : 'Creation chantier',
            'result' => 'failed',
            'severity' => 'warning',
            'details' => [
                'code' => $code ?? null,
                'devis_id' => $devisId ?? null,
                'client_id' => $clientId ?? null,
                'error_message' => $e->getMessage(),
            ],
        ], $pdo);
    }
    echo json_encode($response);
}
