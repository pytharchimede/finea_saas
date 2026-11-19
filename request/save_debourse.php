<?php
header('Content-Type: application/json');
session_start();
require_once '../model/Database.php';
require_once '../model/Devis.php'; // adapte le chemin si besoin
require_once '../model/AuditTrail.php';
require_once '../auth_check.php';
require_once '../services/ActiveChantierEditPermissionService.php';

// Vérification de la session utilisateur
if (!isset($_SESSION['user_id'])) {
    AuditTrail::log('debourse_bulk_save_failed', [
        'module' => 'request',
        'action' => 'save_debourse',
        'entity_type' => 'debourse',
        'entity_label' => 'Enregistrement debourse',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'session_expired',
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'Session expirée.']);
    exit;
}

// Vérification de la méthode POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    AuditTrail::log('debourse_bulk_save_failed', [
        'module' => 'request',
        'action' => 'save_debourse',
        'entity_type' => 'debourse',
        'entity_label' => 'Enregistrement debourse',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'invalid_method',
            'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
        ],
    ]);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

// Initialisation de la connexion à la base de données
$pdo = Database::getConnection();




try {
    $devisId = intval($_POST['devis_id'] ?? 0);
    if (!$devisId) throw new Exception("Devis invalide.");
    if (empty($_SESSION['permissions']['modifier_debourse'])) throw new DomainException('Droit de modification des déboursés requis.');
    ActiveChantierEditPermissionService::assertCanEdit($pdo, $devisId);

    $devisModel = new Devis($pdo); // $pdo doit être défini ou inclus
    $pdo->beginTransaction();

    $ligneCount = 0;
    $sousLigneCount = 0;
    $syncStats = [];

    // Parcours des lignes de devis
    foreach ($_POST['montant_debourse'] as $ligneId => $montant) {
        $ligneCount++;
        $responsable = $_POST['responsable_id'][$ligneId] ?? null;
        $date_debut = $_POST['date_debut'][$ligneId] ?? null;
        $date_fin = $_POST['date_fin'][$ligneId] ?? null;

        // Sauvegarde ou update du déboursé principal
        $devisModel->saveDebourseLigne($devisId, $ligneId, $montant, $responsable, $date_debut, $date_fin);

        $categories = $_POST['categorie'][$ligneId] ?? [];
        $designations = $_POST['designation'][$ligneId] ?? [];
        $montantsSous = $_POST['montant_sous_ligne'][$ligneId] ?? [];
        $datesDebutSous = $_POST['date_debut_sous_ligne'][$ligneId] ?? [];
        $datesFinSous = $_POST['date_fin_sous_ligne'][$ligneId] ?? [];
        $idsSous = $_POST['sous_ligne_id'][$ligneId] ?? [];

        $maxItems = max(
            is_array($categories) ? count($categories) : 0,
            is_array($designations) ? count($designations) : 0,
            is_array($montantsSous) ? count($montantsSous) : 0,
            is_array($datesDebutSous) ? count($datesDebutSous) : 0,
            is_array($datesFinSous) ? count($datesFinSous) : 0,
            is_array($idsSous) ? count($idsSous) : 0
        );

        $sousLignes = [];
        for ($k = 0; $k < $maxItems; $k++) {
            $sousLignes[] = [
                'id' => $idsSous[$k] ?? 0,
                'categorie' => $categories[$k] ?? '',
                'designation' => $designations[$k] ?? '',
                'montant' => $montantsSous[$k] ?? 0,
                'date_debut' => $datesDebutSous[$k] ?? null,
                'date_fin' => $datesFinSous[$k] ?? null,
            ];
        }

        $sousLigneCount += count($sousLignes);
        $syncStats[(string)$ligneId] = $devisModel->syncSousLignesDebourse($devisId, $ligneId, $sousLignes);
    }

    $pdo->commit();

    AuditTrail::log('debourse_bulk_saved', [
        'module' => 'request',
        'action' => 'save_debourse',
        'entity_type' => 'debourse',
        'entity_id' => (string)$devisId,
        'entity_label' => 'Enregistrement debourse devis ' . $devisId,
        'result' => 'success',
        'details' => [
            'devis_id' => $devisId,
            'ligne_count' => $ligneCount,
            'sous_ligne_count' => $sousLigneCount,
            'sync' => $syncStats,
        ],
    ], $pdo);

    echo json_encode(['success' => true, 'message' => 'Déboursé enregistré avec succès.']);
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    AuditTrail::log('debourse_bulk_save_failed', [
        'module' => 'request',
        'action' => 'save_debourse',
        'entity_type' => 'debourse',
        'entity_id' => isset($devisId) && $devisId ? (string)$devisId : null,
        'entity_label' => 'Enregistrement debourse',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'error_message' => $e->getMessage(),
        ],
    ], $pdo);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'line_id' => (int)($ligneId ?? 0), 'needs_refresh' => str_contains($e->getMessage(), 'ne fait plus partie de la ligne de devis')]);
}
