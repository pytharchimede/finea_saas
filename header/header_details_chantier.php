<?php

require_once __DIR__ . '/../auth_check.php';

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../model/Client.php';
require_once __DIR__ . '/../model/Offre.php';
require_once __DIR__ . '/../model/Devis.php';
require_once __DIR__ . '/../model/Operation.php';
require_once __DIR__ . '/../model/Designation.php';
require_once __DIR__ . '/../model/Precision.php';
require_once __DIR__ . '/../services/ChantierLifecycleService.php';
require_once __DIR__ . '/../services/ChantierWorkPeriodService.php';
require_once __DIR__ . '/../services/ChantierFinancialComplianceService.php';

$pdo = Database::getConnection();

// Standardisation archivage chantier (auto-migration) si le modèle le supporte.
try {
    if (is_callable(['Chantier', 'ensureArchiveColumns'])) {
        Chantier::ensureArchiveColumns($pdo);
    }
} catch (Throwable $e) {
    // Tolérer: la page doit rester consultable même sans droits ALTER.
}

$chantierId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($chantierId <= 0) {
    die("ID de chantier invalide.");
}



$chantierModel = new Chantier($pdo);
$chantier = $chantierModel->getByIdIncludingArchived($chantierId);
if (!$chantier) {
    die("Chantier introuvable.");
}

$clientModel = new Client($pdo);
$client = $clientModel->getClientById($chantier['client_id']);
if (!$client) {
    $client = ['nom_client' => '—'];
}

$devisModel = new Devis($pdo);
$devis = $devisModel->getDevisById($chantier['devis_id']);
if (!$devis) {
    $devis = ['id' => 0, 'numero_devis' => '—', 'offre_id' => 0];
}

$offreModel = new Offre($pdo);
$offre = null;
if (!empty($devis['offre_id'])) {
    $offre = $offreModel->getOffreById($devis['offre_id']);
}
if (!$offre) {
    $offre = ['id_offre' => 0, 'num_offre' => '—'];
}

$fichiersOffre = [];
if (!empty($offre['id_offre'])) {
    $fichiersOffre = $offreModel->getFichiersByOffre($offre['id_offre']);
    if (!$fichiersOffre) {
        $fichiersOffre = [];
    }
}


$operationModel = new Operation($pdo);
$operations = $operationModel->getByChantier($chantierId);
if (!$operations) {
    $operations = [];
}

$designationModel = new Designation($pdo);
$designations = $designationModel->getByChantier($chantierId);
if (!$designations) {
    $designations = [];
}

$precisionModel = new PrecisionFiche($pdo);
$precisions = $precisionModel->getByChantier($chantierId);
if (!$precisions) {
    $precisions = [];
}

// Permissions pour terminer/archiver (permission dédiée + anciens raccourcis direction/planning)
$roleNorm = strtolower(trim((string)($_SESSION['role_norm'] ?? '')));
$canArchiveChantier = function_exists('hasPermission')
    ? (hasPermission('valider_archivage_chantier') || hasPermission('acces_direction') || hasPermission('acces_admin') || hasPermission('acces_planning'))
    : false;

if (!$canArchiveChantier) {
    $canArchiveChantier = in_array($roleNorm, ['administrateur', 'admin', 'directeur general', 'directeur général'], true);
}
$_SESSION['chantier_lifecycle_csrf'] ??= bin2hex(random_bytes(32));
$canEditWorkDates = !empty($_SESSION['permissions'][ChantierWorkPeriodService::PERMISSION]);

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['work_period_action'])) {
    try {
        if (!$canEditWorkDates || !empty($chantier['archived_at'])) {
            throw new DomainException('Vous ne pouvez pas modifier les dates des travaux de ce chantier.');
        }
        if (!hash_equals((string) $_SESSION['chantier_lifecycle_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
            throw new DomainException('Session expirée. Rechargez la page.');
        }
        $pdo->beginTransaction();
        ChantierWorkPeriodService::save($pdo, (int) ($devis['id'] ?? 0), (string) ($_POST['project_start_date'] ?? ''), (string) ($_POST['project_end_date'] ?? ''));
        $assessment = (new ChantierFinancialComplianceService($pdo))->auditOne($chantierId, (int) ($devis['id'] ?? 0));
        $pdo->commit();
        $_SESSION['flash_success'] = 'Dates des travaux enregistrées. Ajustez les déboursés si nécessaire.';
        if (($assessment['status'] ?? '') === 'incomplete') {
            $_SESSION['flash_success'] .= ' Contrôle du chantier : ' . (string) ($assessment['issue'] ?? 'données à régulariser.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: details_chantier.php?id=' . $chantierId . '#dates-travaux');
    exit;
}

// Actions POST: terminer/archiver ou restaurer
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['archive_action'])) {
    $action = (string)$_POST['archive_action'];

    if (!$canArchiveChantier) {
        $_SESSION['flash_error'] = "Vous n'avez pas l'autorisation de terminer/archiver ce chantier.";
        header('Location: details_chantier.php?id=' . (int)$chantierId);
        exit;
    }

    try {
        if (!hash_equals((string) $_SESSION['chantier_lifecycle_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
            throw new DomainException('Session expirée. Rechargez la page.');
        }
        if (is_callable(['Chantier', 'ensureArchiveColumns'])) {
            Chantier::ensureArchiveColumns($pdo);
        }

        if ($action === 'archive') {
            $note = trim((string)($_POST['archived_note'] ?? ''));
            $closedDate = trim((string)($_POST['closed_date'] ?? ''));
            $closedDateSql = null;
            if ($closedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $closedDate)) {
                $closedDateSql = $closedDate;
            }

            $scope = (new ChantierLifecycleService($pdo))->archive((int)$chantierId, (int)($_SESSION['user_id'] ?? 0), $closedDateSql, $note);
            $_SESSION['flash_success'] = sprintf('Chantier archivé avec son devis, %d déboursé(s), %d fiche(s), %d FEB et %d élément(s) de planning.', $scope['debourses'], $scope['fiches_decaissement'], $scope['feb'], $scope['planning']);
        } elseif ($action === 'restore') {
            (new ChantierLifecycleService($pdo))->restore((int)$chantierId, (int)($_SESSION['user_id'] ?? 0));
            $_SESSION['flash_success'] = 'Chantier restauré (réactivé).';
        } elseif ($action === 'pause') {
            (new ChantierLifecycleService($pdo))->pause((int)$chantierId, (int)($_SESSION['user_id'] ?? 0), (string) ($_POST['pause_note'] ?? ''));
            $_SESSION['flash_success'] = 'Chantier mis en pause.';
        } elseif ($action === 'resume') {
            (new ChantierLifecycleService($pdo))->resume((int)$chantierId);
            $_SESSION['flash_success'] = 'Chantier repris.';
        } else {
            throw new DomainException('Action inconnue.');
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Erreur archivage chantier: ' . $e->getMessage();
    }

    header('Location: details_chantier.php?id=' . (int)$chantierId);
    exit;
}
