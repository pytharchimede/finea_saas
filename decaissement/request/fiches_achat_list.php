<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $filters = [
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'affectation' => $_GET['affectation'] ?? null,
        'designation' => $_GET['designation'] ?? null,
        'montant_min' => $_GET['montant_min'] ?? null,
        'montant_max' => $_GET['montant_max'] ?? null,
        'date_min' => $_GET['date_min'] ?? null,
        'date_max' => $_GET['date_max'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
    ];
    $fiches = $model->listAchatReady($filters);
    $items = array_map(function ($f) {
        $photo = trim($f['photo_beneficiaire'] ?? '');
        $photoUrl = null;
        if ($photo !== '') {
            $cand1Rel = '../uploads/photo/' . $photo; // parent uploads
            $cand1Abs = realpath(__DIR__ . '/../../uploads/photo/' . $photo);
            $cand2Rel = 'uploads/photo/' . $photo;    // decaissement/uploads
            $cand2Abs = realpath(__DIR__ . '/../uploads/photo/' . $photo);
            if ($cand1Abs && is_file($cand1Abs)) {
                $photoUrl = $cand1Rel;
            } elseif ($cand2Abs && is_file($cand2Abs)) {
                $photoUrl = $cand2Rel;
            }
        }
        $f = enrich_fiche_labels($f);
        $ligneDebourseLabel = $f['ligne_debourse_label'] ?? '';
        $ligneDevisLabel = $f['ligne_devis_label'] ?? '';

        return [
            'num_fiche' => $f['num_fiche'],
            'workflow_base_num_fiche' => $f['workflow_base_num_fiche'] ?? $f['num_fiche'],
            'beficiaire_fiche' => $f['beficiaire_fiche'],
            'montant_fiche' => (float)$f['montant_fiche'],
            'date_creat_fiche' => $f['date_creat_fiche'],
            'precision_fiche' => $f['precision_fiche'],
            'designation_fiche' => $f['designation_fiche'],
            'tel_beneficiaire_fiche' => $f['tel_beneficiaire_fiche'],
            'num_piece' => $f['num_piece'],
            'chantier_id' => $f['chantier_id'],
            'serv_bureau_banamur_id' => $f['serv_bureau_banamur_id'],
            'photo_beneficiaire' => $f['photo_beneficiaire'], // brut
            'photo_url' => $photoUrl, // résolu
            'etat_fiche' => isset($f['etat_fiche']) ? (int)$f['etat_fiche'] : 0,
            'date_decaissement_minimum' => $f['date_decaissement_minimum'] ?? null,
            'details_demande' => $f['details_demande'] ?? '',
            'ligne_debourse_id' => isset($f['ligne_debourse_id']) ? (int)$f['ligne_debourse_id'] : null,
            'ligne_designation' => $f['ligne_designation'] ?? '',
            'ligne_categorie' => $f['ligne_categorie'] ?? '',
            'devis_designation' => $f['devis_designation'] ?? '',
            'ligne_debourse_label' => $ligneDebourseLabel,
            'ligne_devis_label' => $ligneDevisLabel,
            'cni_beneficiaire' => $f['cni_beneficiaire'] ?? null,
            'signature_beneficiaire' => $f['signature_beneficiaire'] ?? null,

            // Bon de commande / Bon de réception
            'bc_id' => isset($f['bc_id']) ? (int)$f['bc_id'] : null,
            'bc_number' => $f['bc_number'] ?? null,
            'bc_date' => $f['bc_date'] ?? null,
            'br_id' => isset($f['br_id']) ? (int)$f['br_id'] : null,
        ];
    }, $fiches);

    echo json_encode([
        'ok' => true,
        'count' => count($items),
        'total' => array_sum(array_column($items, 'montant_fiche')),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
