<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);
    $filters = [
        'date_debut' => $_GET['date_debut'] ?? null,
        'date_fin' => $_GET['date_fin'] ?? null,
        'demandeur' => $_GET['demandeur'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'precision' => $_GET['precision'] ?? null,
        'designation' => $_GET['designation'] ?? null,
        'montant_min' => $_GET['montant_min'] ?? null,
        'montant_max' => $_GET['montant_max'] ?? null,
    ];
    $rows = $model->listAcertifier($filters);
    $items = [];
    foreach ($rows as $f) {
        $photo = trim($f['photo_beneficiaire'] ?? '');
        $photoExists = false;
        $photoRel = null;
        if ($photo !== '') {
            // 1. uploads/photo (racine)
            if (file_exists(__DIR__ . '/../../uploads/photo/' . $photo)) {
                $photoExists = true;
                $photoRel = '../uploads/photo/' . rawurlencode($photo);
            }
            // 2. decaissement/uploads/photo (si déplacé)
            elseif (file_exists(__DIR__ . '/../uploads/photo/' . $photo)) {
                $photoExists = true;
                $photoRel = 'uploads/photo/' . rawurlencode($photo);
            }
            // 3. valeur déjà chemin (contient /)
            elseif (strpos($photo, '/') !== false) {
                $clean = ltrim($photo, '/');
                if (file_exists(__DIR__ . '/../../' . $clean)) {
                    $photoExists = true;
                    $photoRel = '../' . str_replace('\\', '/', $clean);
                }
            }
        }
        $items[] = [
            'id_fiche' => $f['id_fiche'] ?? null,
            'contract_id' => isset($f['contract_id']) ? (int)$f['contract_id'] : null,
            'contract_payment_type' => $f['contract_payment_type'] ?? '',
            'num_fiche' => $f['num_fiche'],
            'beficiaire_fiche' => $f['beficiaire_fiche'],
            'montant_fiche' => (float)$f['montant_fiche'],
            'num_piece' => $f['num_piece'] ?? '',
            'facture_reference' => $f['facture_reference'] ?? '',
            'feb_cashflow_need' => $f['feb_cashflow_need'] ?? '',
            'feb_cashflow_need_label' => $f['feb_cashflow_need_label'] ?? '',
            'feb_requires_invoice_before_conformity' => !empty($f['feb_requires_invoice_before_conformity']),
            'conformity_blocker' => $f['conformity_blocker'] ?? '',
            'date_creat_fiche' => $f['date_creat_fiche'],
            'planned_start_date' => $f['planned_start_date'] ?? '',
            'planned_end_date' => $f['planned_end_date'] ?? '',
            'photo' => $photoExists ? $photoRel : null,
            'precision' => $f['precision_fiche'] ?? '',
            'designation' => $f['designation_fiche'] ?? '',
            'ligne_designation' => $f['ligne_designation'] ?? '',
            'ligne_categorie' => $f['ligne_categorie'] ?? '',
            'ligne_debourse_label' => $f['ligne_debourse_label'] ?? build_fiche_ligne_debourse_label($f),
            'ligne_devis_label' => $f['ligne_devis_label'] ?? build_fiche_ligne_devis_label($f),
            'debourse_state' => 'unknown'
        ];
    }

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
