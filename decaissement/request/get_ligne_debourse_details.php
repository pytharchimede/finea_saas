<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

header('Content-Type: application/json');

$ligne_debourse_id = $_POST['ligne_debourse_id'] ?? null;
$num_fiche = trim((string)($_GET['num_fiche'] ?? ''));

try {
    $pdo = \Database::getConnection();
    $cols = $pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array('parent_num_fiche', $cols, true) && in_array('code_parentale', $cols, true)) {
        $parentExpr = 'COALESCE(f.parent_num_fiche, f.code_parentale)';
    } elseif (in_array('parent_num_fiche', $cols, true)) {
        $parentExpr = 'f.parent_num_fiche';
    } elseif (in_array('code_parentale', $cols, true)) {
        $parentExpr = 'f.code_parentale';
    } else {
        $parentExpr = 'NULL';
    }

    $ficheContexte = null;
    if ((empty($ligne_debourse_id) || (int)$ligne_debourse_id <= 0) && $num_fiche !== '') {
        $stmt = $pdo->prepare("SELECT f.*, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation
            FROM fiche_decaissement f
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
            LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE f.num_fiche = ? AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL) LIMIT 1");
        $stmt->execute([$num_fiche]);
        $ficheContexte = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $ligne_debourse_id = $ficheContexte['ligne_debourse_id'] ?? null;
    }

    if (empty($ligne_debourse_id) || (int)$ligne_debourse_id <= 0) {
        AuditTrail::log('decaissement_ligne_details_failed', [
            'module' => 'decaissement',
            'action' => 'get_ligne_debourse_details',
            'entity_type' => 'ligne_debourse',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => ['reason' => 'missing_ligne_debourse_id', 'num_fiche' => $num_fiche !== '' ? $num_fiche : null],
        ], $pdo);
        echo json_encode(['success' => false, 'ok' => false, 'message' => 'ID de la ligne de déboursé requis'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $ligne_debourse_id = (int)$ligne_debourse_id;

    // Récupérer les infos de la ligne de déboursé sélectionnée
    $stmt = $pdo->prepare("SELECT l.* FROM ligne_debourse_banamur l INNER JOIN debourse_banamur d ON d.id=l.debourse_id WHERE l.id = ? AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))");
    $stmt->execute([$ligne_debourse_id]);
    $ligne = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$ligne) {
        if ($ficheContexte === null && $num_fiche !== '') {
            $stmt = $pdo->prepare("SELECT f.*, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation
                FROM fiche_decaissement f
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
                WHERE f.num_fiche = ? AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL) LIMIT 1");
            $stmt->execute([$num_fiche]);
            $ficheContexte = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if (!$ficheContexte) {
            AuditTrail::log('decaissement_ligne_details_failed', [
                'module' => 'decaissement',
                'action' => 'get_ligne_debourse_details',
                'entity_type' => 'ligne_debourse',
                'entity_id' => (string)$ligne_debourse_id,
                'entity_label' => 'ligne_debourse_' . $ligne_debourse_id,
                'result' => 'failure',
                'severity' => 'warning',
                'details' => ['reason' => 'ligne_not_found'],
            ], $pdo);
            echo json_encode(['success' => false, 'ok' => false, 'message' => 'Ligne de déboursé non trouvée'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }

        $ficheContexte = enrich_fiche_labels($ficheContexte);
        $ligne = [
            'id' => $ligne_debourse_id,
            'designation' => $ficheContexte['ligne_debourse_base'] !== '' ? $ficheContexte['ligne_debourse_base'] : ('Réf. ligne supprimée #' . $ligne_debourse_id),
            'categorie' => $ficheContexte['ligne_categorie'] ?? '',
            'devis_designation' => $ficheContexte['ligne_devis_label'] ?? '',
            'montant' => null,
            'missing_reference' => 1,
            'historical_label' => $ficheContexte['ligne_debourse_label'] ?? '',
        ];
    }

    // Récupérer les fiches déjà décaissées pour cette même ligne de déboursé
    $stmt = $pdo->prepare("
        SELECT 
            f.id_fiche,
            f.beficiaire_fiche,
            f.montant_fiche,
            f.date_creat_fiche as date_creation
        FROM fiche_decaissement f
        WHERE f.ligne_debourse_id = ? AND f.decaisse = 1
        ORDER BY f.date_creat_fiche DESC
    ");
    $stmt->execute([$ligne_debourse_id]);
    $fiches_existantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer toutes les fiches (tous statuts) pour cette ligne
    $stmt = $pdo->prepare("
            SELECT 
                f.id_fiche,
                f.num_fiche,
                f.beficiaire_fiche,
                f.montant_fiche,
                f.date_creat_fiche AS date_creation,
                f.approuve, f.conforme, f.otp_valide, f.decaisse, f.signature_fichier,
                f.etat_fiche, f.date_decaissement_minimum, f.date_decaisse,
                {$parentExpr} AS parent_num_fiche
            FROM fiche_decaissement f
            WHERE f.ligne_debourse_id = ?
            ORDER BY f.date_creat_fiche DESC
        ");
    $stmt->execute([$ligne_debourse_id]);
    $fiches_existantes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculer totaux par statut
    $montant_decaisse = 0;
    $montant_attente = 0;
    $montant_otp = 0;
    $montant_approuve = 0;
    $montant_conforme = 0;
    foreach ($fiches_existantes as $fiche) {
        $val = floatval($fiche['montant_fiche']);
        if ($fiche['decaisse']) $montant_decaisse += $val;
        else $montant_attente += $val; // en attente si pas décaissée
        if ($fiche['otp_valide']) $montant_otp += $val;
        if ($fiche['approuve']) $montant_approuve += $val;
        if ($fiche['conforme']) $montant_conforme += $val;
    }

    // Calculer le montant restant
    $montant_prevu = isset($ligne['montant']) && $ligne['montant'] !== null ? floatval($ligne['montant']) : 0.0;
    $montant_restant = $montant_prevu - $montant_decaisse;

    echo json_encode([
        'success' => true,
        'ok' => true,
        'ligne' => $ligne,
        'ligne_label' => !empty($ficheContexte) ? build_fiche_ligne_debourse_label($ficheContexte) : build_fiche_ligne_debourse_label($ligne),
        'devis_label' => !empty($ficheContexte) ? build_fiche_ligne_devis_label($ficheContexte) : build_fiche_ligne_devis_label($ligne),
        'montant_prevu' => number_format($montant_prevu, 0, ',', ' '),
        'montant_decaisse' => number_format($montant_decaisse, 0, ',', ' '),
        'montant_restant' => number_format($montant_restant, 0, ',', ' '),
        'montant_attente' => number_format($montant_attente, 0, ',', ' '),
        'montant_otp' => number_format($montant_otp, 0, ',', ' '),
        'montant_approuve' => number_format($montant_approuve, 0, ',', ' '),
        'montant_conforme' => number_format($montant_conforme, 0, ',', ' '),
        'fiches_existantes' => $fiches_existantes,
        'fiches' => $fiches_existantes
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Exception $e) {
    AuditTrail::log('decaissement_ligne_details_failed', [
        'module' => 'decaissement',
        'action' => 'get_ligne_debourse_details',
        'entity_type' => 'ligne_debourse',
        'entity_id' => isset($ligne_debourse_id) && (int)$ligne_debourse_id > 0 ? (string)((int)$ligne_debourse_id) : null,
        'entity_label' => isset($ligne_debourse_id) && (int)$ligne_debourse_id > 0 ? ('ligne_debourse_' . (int)$ligne_debourse_id) : 'get_ligne_debourse_details',
        'result' => 'failure',
        'severity' => 'warning',
        'details' => ['error' => $e->getMessage()],
    ], isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    http_response_code(500);
    echo json_encode(['success' => false, 'ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

if (isset($ligne_debourse_id) && (int)$ligne_debourse_id > 0 && isset($pdo) && $pdo instanceof PDO) {
    AuditTrail::log('decaissement_ligne_details_loaded', [
        'module' => 'decaissement',
        'action' => 'get_ligne_debourse_details',
        'entity_type' => 'ligne_debourse',
        'entity_id' => (string)((int)$ligne_debourse_id),
        'entity_label' => 'ligne_debourse_' . (int)$ligne_debourse_id,
        'details' => [
            'fiche_count' => isset($fiches_existantes) && is_array($fiches_existantes) ? count($fiches_existantes) : 0,
            'num_fiche' => $num_fiche !== '' ? $num_fiche : null,
        ],
    ], $pdo);
}
