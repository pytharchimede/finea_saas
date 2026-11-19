<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

try {
    $pdo = Database::getConnection();

    // Construire la requête d'approbation (même logique que la page)
    $where = ["f.conforme = 1", "f.decaisse = 0"]; // aprouve 0/2 par statut
    $statut = $_GET['statut'] ?? 'attente';
    if ($statut === 'refusees') $where[] = 'f.approuve = 2';
    elseif ($statut === 'tous') $where[] = 'f.approuve IN (0,2)';
    else $where[] = 'f.approuve = 0';

    $params = [];
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

    // Sélection POST (ids/selected) prime sur filtres
    $selected = [];
    if (!empty($_POST['selected'])) $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
    elseif (!empty($_POST['ids'])) $selected = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids']);
    $selected = array_values(array_filter(array_map('trim', $selected)));

    if (!empty($selected)) {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id = f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id WHERE f.num_fiche IN ($in) AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL) ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($selected);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $where[] = '(f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)';
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code, ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie, ld.designation AS devis_designation FROM fiche_decaissement f LEFT JOIN chantier_banamur c ON c.id = f.chantier_id LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id WHERE " . implode(' AND ', $where) . " ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $xlsx = new Spreadsheet();
    $sheet = $xlsx->getActiveSheet();
    $r = 1;
    $sheet->setCellValue('A' . $r, 'Export fiches approbation');
    $sheet->mergeCells("A{$r}:J{$r}");
    $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true)->setSize(14);
    $r += 2;

    $headers = ['Num fiche', 'Bénéficiaire', 'Chantier', 'Ligne déboursé', 'Ligne devis', 'Désignation', 'Précision', 'Catégorie', 'Montant (CFA)', 'Créée le'];
    $cols = range('A', 'J');
    foreach ($headers as $i => $h) {
        $sheet->setCellValue($cols[$i] . $r, $h);
    }
    $sheet->getStyle("A{$r}:J{$r}")->getFont()->setBold(true);
    $sheet->getStyle("A{$r}:J{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
    $r++;

    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $num = $f['num_fiche'] ?? '';
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $chantier = trim(($f['chantier_titre'] ?? '') . (!empty($f['chantier_code']) ? ' [' . $f['chantier_code'] . ']' : ''));
        $ligneDebourse = $f['ligne_debourse_label'] ?? '';
        $ligneDevis = $f['ligne_devis_label'] ?? '';
        $designation = $f['designation_fiche'] ?? '';
        $precision = $f['precision_fiche'] ?? '';
        $categorie = $f['ligne_categorie'] ?? '';
        $montant = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';
        $total += $montant;

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
        $r++;
    }

    $sheet->setCellValue('H' . $r, 'TOTAL');
    $sheet->setCellValue('I' . $r, $total);
    $sheet->getStyle("H{$r}:I{$r}")->getFont()->setBold(true);
    $sheet->getStyle("I1:I{$r}")->getNumberFormat()->setFormatCode('#,##0');
    foreach ($cols as $c) $sheet->getColumnDimension($c)->setAutoSize(true);
    $sheet->getStyle("A1:J{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

    $filename = 'export_fiches_approbation_' . date('Ymd_His') . '.xlsx';
    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_approbation_export',
        'entity_type' => 'fiche_approbation_list',
        'entity_label' => 'Fiches approbation',
        'format' => 'excel',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
            'statut' => $statut,
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
