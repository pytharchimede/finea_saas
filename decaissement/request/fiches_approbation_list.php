<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Precision.php';
require_once __DIR__ . '/../model/Designation.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__.'/../../services/SupplierApprovalGuard.php';

try {
    $pdo = Database::getConnection();
    $precisionObj = new Precision($pdo);
    $designationObj = new Designation($pdo);

    $where = ["f.conforme = 1"];
    $params = [];
    $statut = $_GET['statut'] ?? 'attente';
    $canAdjustBeneficiarySupplier = AccessManager::hasPermission('ajuster_beneficiaire_fournisseur_fiche');
    if ($statut !== 'decaissees') $where[] = '(f.chantier_id IS NULL OR c.archived_at IS NULL)';
    if ($statut === 'decaissees' && $canAdjustBeneficiarySupplier) {
        $where[] = 'f.decaisse = 1';
        $where[] = 'f.approuve = 1';
    } elseif ($statut === 'refusees') {
        $where[] = 'f.decaisse = 0';
        $where[] = 'f.approuve = 2';
    } elseif ($statut === 'approuvees' && $canAdjustBeneficiarySupplier) {
        $where[] = 'f.decaisse = 0';
        $where[] = 'f.approuve = 1';
    } elseif ($statut === 'tous') {
        $where[] = 'f.decaisse = 0';
        $where[] = 'f.approuve IN (0,2)';
    } else {
        $where[] = 'f.decaisse = 0';
        $where[] = 'f.approuve = 0';
    }
    if (!empty($_GET['num_fiche'])) {
        $where[] = 'f.num_fiche LIKE :num_fiche';
        $params['num_fiche'] = '%' . $_GET['num_fiche'] . '%';
    }
    if (!empty($_GET['beficiaire_fiche'])) {
        $where[] = 'f.beficiaire_fiche LIKE :benef';
        $params['benef'] = '%' . $_GET['beficiaire_fiche'] . '%';
    }
    if (!empty($_GET['designation'])) {
        $where[] = 'f.designation_fiche LIKE :designation';
        $params['designation'] = '%' . $_GET['designation'] . '%';
    }
    if (!empty($_GET['precision'])) {
        $where[] = 'f.precision_fiche LIKE :precision';
        $params['precision'] = '%' . $_GET['precision'] . '%';
    }
    if (!empty($_GET['montant_min'])) {
        $where[] = 'f.montant_fiche >= :montant_min';
        $params['montant_min'] = (float)$_GET['montant_min'];
    }
    if (!empty($_GET['montant_max'])) {
        $where[] = 'f.montant_fiche <= :montant_max';
        $params['montant_max'] = (float)$_GET['montant_max'];
    }
    if (!empty($_GET['date_min'])) {
        $where[] = 'f.date_creat_fiche >= :date_min';
        $params['date_min'] = $_GET['date_min'] . ' 00:00:00';
    }
    if (!empty($_GET['date_max'])) {
        $where[] = 'f.date_creat_fiche <= :date_max';
        $params['date_max'] = $_GET['date_max'] . ' 23:59:59';
    }
    if (!empty($_GET['chantier'])) {
        $where[] = '(c.titre LIKE :chantier OR c.code LIKE :chantier)';
        $params['chantier'] = '%' . $_GET['chantier'] . '%';
    }
    if (!empty($_GET['affectation'])) {
        if ($_GET['affectation'] === 'chantier') $where[] = 'f.chantier_id IS NOT NULL';
        elseif ($_GET['affectation'] === 'bureau') $where[] = 'f.serv_bureau_banamur_id IS NOT NULL';
        elseif ($_GET['affectation'] === 'autre') $where[] = '(f.chantier_id IS NULL AND f.serv_bureau_banamur_id IS NULL)';
    }

    $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
            ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
            ld.designation AS devis_designation
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
            LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
            WHERE " . implode(' AND ', $where) . " ORDER BY f.date_creat_fiche DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $fiches = $st->fetchAll(PDO::FETCH_ASSOC);

    $ficheModel = new FicheDecaissement($pdo);
    $data = array_map(function ($fiche) use ($ficheModel,$pdo) {
        $fiche = enrich_fiche_labels($fiche);
        $photo = trim((string)($fiche['beneficiary_visual_path'] ?? $fiche['photo_beneficiaire'] ?? ''));
        $isChantier = !empty($fiche['chantier_id']);
        $isBureau = !empty($fiche['serv_bureau_banamur_id']);
        $type = $isChantier ? 'chantier' : ($isBureau ? 'bureau' : 'autre');
        $precision = $fiche['precision_fiche'] ?? '';
        $designation = $fiche['designation_fiche'] ?? '';

        $photoUrl = null;
        if ($photo !== '') {
            $basename = basename($photo);
            $candidates = [
                ['abs' => __DIR__ . '/../../uploads/photo/' . $basename, 'rel' => '../uploads/photo/' . rawurlencode($basename)],
                ['abs' => __DIR__ . '/../uploads/photo/' . $basename, 'rel' => 'uploads/photo/' . rawurlencode($basename)],
                ['abs' => __DIR__ . '/../../' . ltrim($photo, '/'), 'rel' => '../' . ltrim($photo, '/')],
            ];
            foreach ($candidates as $candidate) {
                if (is_file($candidate['abs'])) {
                    $photoUrl = $candidate['rel'];
                    break;
                }
            }
        }

        $advanceAmortization = $ficheModel->getAdvanceAmortizationSuggestion((string)($fiche['num_fiche'] ?? ''));
        return [
            'supplier_approval_error'=>SupplierApprovalGuard::error($pdo,$fiche),
            'id_fiche' => $fiche['id_fiche'],
            'num_fiche' => $fiche['num_fiche'],
            'beficiaire_fiche' => $fiche['beficiaire_fiche'],
            'beneficiary_display_name' => $fiche['beneficiary_display_name'] ?? $fiche['beficiaire_fiche'],
            'beneficiary_display_phone' => $fiche['beneficiary_display_phone'] ?? $fiche['tel_beneficiaire_fiche'] ?? '',
            'beneficiary_email' => $fiche['beneficiary_email'] ?? '',
            'beneficiary_address' => $fiche['beneficiary_address'] ?? '',
            'beneficiary_type' => $fiche['beneficiary_type'] ?? 'historique',
            'montant_fiche' => (float)$fiche['montant_fiche'],
            'date_creat_fiche' => $fiche['date_creat_fiche'],
            'type' => $type,
            'photo' => $photoUrl,
            'photo_url' => $photoUrl,
            'precision' => $precision,
            'designation' => $designation,
            'ligne_debourse_label' => $fiche['ligne_debourse_label'] ?? '',
            'ligne_devis_label' => $fiche['ligne_devis_label'] ?? '',
            'approuve' => (int)$fiche['approuve'],
            'details_demande' => $fiche['details_demande'] ?? '',
            'advance_amortization' => $advanceAmortization,
        ];
    }, $fiches);

    echo json_encode([
        'ok' => true,
        'count' => count($data),
        'total' => array_sum(array_column($data, 'montant_fiche')),
        'items' => $data,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
