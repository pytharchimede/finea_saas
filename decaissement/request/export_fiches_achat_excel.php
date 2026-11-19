<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';
require_once __DIR__ . '/../../model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

try {
    $pdo = Database::getConnection();
    $model = new FicheDecaissement($pdo);

    // Sélection (POST ids|selected)
    $selected = [];
    if (!empty($_POST['selected'])) {
        $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
    } elseif (!empty($_POST['ids'])) {
        $selected = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids']);
    }
    $selected = array_values(array_filter(array_map('trim', $selected)));

    // Filtres (GET) conformes à liste achat
    $filters = [
        'beneficiaire' => $_GET['beneficiaire'] ?? null,
        'beneficiaire_mode' => $_GET['beneficiaire_mode'] ?? 'include',
        'affectation' => $_GET['affectation'] ?? null,
        'affectation_mode' => $_GET['affectation_mode'] ?? 'include',
        'designation' => $_GET['designation'] ?? null,
        'designation_mode' => $_GET['designation_mode'] ?? 'include',
        'montant_min' => $_GET['montant_min'] ?? null,
        'montant_max' => $_GET['montant_max'] ?? null,
        'date_min' => $_GET['date_min'] ?? null,
        'date_max' => $_GET['date_max'] ?? null,
        'chantier' => $_GET['chantier'] ?? null,
        'chantier_mode' => $_GET['chantier_mode'] ?? 'include',
    ];

    if (!empty($selected)) {
        $rows = [];
        foreach ($selected as $num) {
            $d = $model->getDetailsByNum($num) ?: $model->getByNum($num);
            if ($d) $rows[] = $d;
        }
    } else {
        $rows = $model->listAchatReady($filters);
    }

    // Pré-calcul agrégé (évite N+1) : total décaissé + montant ligne, uniquement pour les lignes présentes dans l'export
    $aggDecaisse = [];
    $ligneMontants = [];
    $ligneIdMap = [];
    foreach ($rows as $f) {
        $lid = $f['ligne_debourse_id'] ?? null;
        if ($lid !== null && $lid !== '') {
            $ligneIdMap[(int)$lid] = true;
        }
    }
    $ligneIds = array_keys($ligneIdMap);
    if (!empty($ligneIds)) {
        $in = implode(',', array_fill(0, count($ligneIds), '?'));

        try {
            $stAgg = $pdo->prepare("SELECT ligne_debourse_id, SUM(montant_fiche) AS total_decaisse_ligne FROM fiche_decaissement WHERE decaisse = 1 AND ligne_debourse_id IN ($in) GROUP BY ligne_debourse_id");
            $stAgg->execute($ligneIds);
            foreach ($stAgg->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $k = (string)($a['ligne_debourse_id'] ?? '');
                if ($k !== '') {
                    $aggDecaisse[$k] = (float)($a['total_decaisse_ligne'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $aggDecaisse = [];
        }

        try {
            $stL = $pdo->prepare("SELECT id, montant FROM ligne_debourse_banamur WHERE id IN ($in)");
            $stL->execute($ligneIds);
            foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                $k = (string)($lr['id'] ?? '');
                if ($k !== '') {
                    $ligneMontants[$k] = (float)($lr['montant'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            $ligneMontants = [];
        }
    }

    $xlsx = new Spreadsheet();
    $sheet = $xlsx->getActiveSheet();
    $r = 1;
    $title = 'Export fiches prêtes achat';
    if (!empty($filters['date_min']) || !empty($filters['date_max'])) {
        $title .= ' (' . ($filters['date_min'] ?: '…') . ' → ' . ($filters['date_max'] ?: '…') . ')';
    }
    $sheet->setCellValue('A' . $r, $title);
    $sheet->mergeCells("A{$r}:L{$r}");
    $sheet->getStyle("A{$r}:L{$r}")->getFont()->setBold(true)->setSize(14);
    $r += 2;

    $headers = ['Num fiche', 'Bénéficiaire', 'Chantier', 'Ligne déboursé', 'Ligne devis', 'Désignation', 'Précision', 'Catégorie', 'Montant (CFA)', 'Créée le', 'Alertes', 'Budget'];
    $cols = range('A', 'L');
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($cols[$i] . $r, $h);
    }
    $sheet->getStyle("A{$r}:L{$r}")->getFont()->setBold(true);
    $sheet->getStyle("A{$r}:L{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
    $r++;

    $dataStartRow = $r;

    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $num = $f['num_fiche'] ?? '';
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $chantier = trim(($f['chantier_titre'] ?? '') . (!empty($f['chantier_code']) ? ' [' . $f['chantier_code'] . ']' : ''));
        $ligneDebourse = $f['ligne_debourse_label'] ?? '';
        $ligneDevis = $f['ligne_devis_label'] ?? '';
        $designation = $f['designation_fiche'] ?? $f['ligne_designation'] ?? '';
        $precision = $f['precision_fiche'] ?? '';
        $categorie = $f['ligne_categorie'] ?? '';
        $montant = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';
        $total += $montant;

        // Décision rapide (textuel, imprimable N/B)
        $alerts = [];
        $ligneId = (string)($f['ligne_debourse_id'] ?? '');
        $ligneMontant = $ligneId !== '' ? (float)($ligneMontants[$ligneId] ?? ($f['ligne_montant'] ?? 0)) : 0.0;
        $totalDecaisseLigne = $ligneId !== '' ? (float)($aggDecaisse[$ligneId] ?? 0) : 0.0;
        $ratio = ($ligneMontant > 0) ? (($totalDecaisseLigne + $montant) / $ligneMontant) : null;
        if ($ratio !== null && is_finite($ratio)) {
            if ($ratio > 1) $alerts[] = 'HB';
            elseif ($ratio >= 0.95) $alerts[] = 'LIM';
        }
        $hasBC = !empty($f['bc_id']) || !empty($f['bc_number']);
        $hasBR = !empty($f['br_id']);
        if ($hasBC && !$hasBR) $alerts[] = 'BR-';
        $cni = trim((string)($f['cni_beneficiaire'] ?? ''));
        $sig = trim((string)($f['signature_beneficiaire'] ?? ''));
        if ($cni === '') $alerts[] = 'CNI-';
        if ($sig === '') $alerts[] = 'SIGN-';
        $alertStr = $alerts ? implode(' ', array_values(array_unique($alerts))) : '-';
        $budStr = '-';
        if ($ratio !== null && is_finite($ratio)) {
            $budStr = ($ratio <= 1 ? 'OK ' : 'HB ') . number_format($ratio * 100, 1, ',', ' ') . '%';
        } elseif ($ligneMontant > 0) {
            $budStr = 'N/A';
        }

        $sheet->setCellValue('A' . $r, $num);
        $sheet->setCellValue('B' . $r, $benef);
        $sheet->setCellValue('C' . $r, $chantier);
        $sheet->setCellValue('D' . $r, $ligneDebourse);
        $sheet->setCellValue('E' . $r, $ligneDevis);
        $sheet->setCellValue('F' . $r, $designation);
        $sheet->setCellValue('G' . $r, $precision);
        $sheet->setCellValue('H' . $r, $categorie);
        $sheet->setCellValue('I' . $r, $montant);
        $sheet->setCellValue('J' . $r, $datec);
        $sheet->setCellValue('K' . $r, $alertStr);
        $sheet->setCellValue('L' . $r, $budStr);
        $r++;
    }

    $dataEndRow = $r - 1;
    if ($dataEndRow >= $dataStartRow) {
        // Gras uniquement sur les colonnes décisionnelles pour les lignes de données
        $sheet->getStyle("G{$dataStartRow}:G{$dataEndRow}")->getFont()->setBold(true); // Précision
        $sheet->getStyle("I{$dataStartRow}:I{$dataEndRow}")->getFont()->setBold(true); // Montant
        $sheet->getStyle("K{$dataStartRow}:K{$dataEndRow}")->getFont()->setBold(true); // Alertes
        $sheet->getStyle("L{$dataStartRow}:L{$dataEndRow}")->getFont()->setBold(true); // Budget
    }

    $sheet->setCellValue('H' . $r, 'TOTAL');
    $sheet->setCellValue('I' . $r, $total);
    $sheet->getStyle("H{$r}:I{$r}")->getFont()->setBold(true);
    $sheet->getStyle("I1:I{$r}")->getNumberFormat()->setFormatCode('#,##0');
    foreach ($cols as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
    $sheet->getStyle("A1:L{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $filename = 'export_fiches_achat_' . date('Ymd_His') . '.xlsx';
    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_achat_export',
        'entity_type' => 'fiche_achat_list',
        'entity_label' => 'Fiches prêtes achat',
        'format' => 'excel',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
        ],
    ], $pdo);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    (new Xlsx($xlsx))->save('php://output');
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
