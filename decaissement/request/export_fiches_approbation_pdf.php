<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

function recap_supplier_label(array $row): string
{
    $supplierName = trim((string) ($row['fournisseur_nom'] ?? ''));
    if ($supplierName !== '') {
        return 'FOU: ' . $supplierName;
    }

    $supplierId = (int) ($row['fournisseur_id'] ?? 0);
    if ($supplierId > 0) {
        return 'FOU: ajouté en BDD';
    }

    return '';
}

class PDFAppr extends TCPDF
{
    use CompanyPdfTemplate;
    public string $reportTitle = '';
    public function Header()
    {
        $this->drawCompanyPdfHeader();
        return;
        $banner = __DIR__ . '/../../img/entete_fiche.jpg';
        if (@file_exists($banner)) {
            $this->Image($banner, 10, 6, $this->getPageWidth() - 20, 0, '', '', '', false, 300);
            $this->SetY(24);
        } else {
            $logo = __DIR__ . '/../../logo/logo.png';
            if (!@file_exists($logo)) {
                $logo = __DIR__ . '/../../img/logo_veritas.jpg';
            }
            if (@file_exists($logo)) {
                $this->Image($logo, 10, 6, 24, 0, '', '', '', false, 300);
            }
            $this->SetFont('dejavusans', 'B', 14);
            $this->SetTextColor(33, 37, 41);
            $this->Cell(0, 8, $this->reportTitle ?: 'Export fiches approbation', 0, 1, 'C');
        }
        $this->SetDrawColor(22, 119, 255);
        $this->SetLineWidth(0.5);
        $this->Line(10, 18, $this->getPageWidth() - 10, 18);
        $this->Ln(2);
    }
    public function Footer()
    {
        $this->drawCompanyPdfFooter();
        return;
        $this->SetY(-24);
        $this->SetDrawColor(230, 230, 230);
        $this->Line(10, $this->GetY(), $this->getPageWidth() - 10, $this->GetY());
        $this->Ln(2);
        $logo1 = __DIR__ . '/../../logo/logo.png';
        if (!@file_exists($logo1)) {
            $logo1 = __DIR__ . '/../../img/logo_veritas.jpg';
        }
        $logo2 = $logo1;
        $logoW = 20;
        $yLogo = $this->GetY() + 1;
        if (@file_exists($logo1)) {
            $this->Image($logo1, 10, $yLogo, $logoW);
            $this->Image($logo2, $this->getPageWidth() - 10 - $logoW, $yLogo, $logoW);
        }
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(60, 60, 60);
        $this->Cell(0, 3.5, 'FOURNITURES INDUSTRIELLES, DEPANNAGE ET TRAVAUX PUBLIQUES', 0, 1, 'C');
        $this->Cell(0, 3.5, 'Au capital de 10 000 000 F CFA - Siège Social : Abidjan, Koumassi, Zone industrielle', 0, 1, 'C');
        $this->Cell(0, 3.5, '01 BP 1642 Abidjan 01 - Téléphone : (+225) 27-21-36-27-27  -  Email : info@fidest.org', 0, 1, 'C');
        $this->Cell(0, 3.5, 'RCCM : CI-ABJ-2017-B-20163  -  N° CC : 010274200088', 0, 1, 'C');
        $this->Ln(1);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 100, 100);
        $this->Cell(0, 6, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

try {
    $pdo = Database::getConnection();

    $where = ["f.conforme = 1", "f.decaisse = 0"];
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

    $selected = [];
    if (!empty($_POST['selected'])) $selected = is_array($_POST['selected']) ? $_POST['selected'] : explode(',', (string)$_POST['selected']);
    elseif (!empty($_POST['ids'])) $selected = is_array($_POST['ids']) ? $_POST['ids'] : explode(',', (string)$_POST['ids']);
    $selected = array_values(array_filter(array_map('trim', $selected)));

    if (!empty($selected)) {
        $in = implode(',', array_fill(0, count($selected), '?'));
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
                       ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
                       ld.designation AS devis_designation
                FROM fiche_decaissement f
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                WHERE f.num_fiche IN ($in)
                  AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($selected);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT f.*, c.titre AS chantier_titre, c.code AS chantier_code,
                       ldb.designation AS ligne_designation, ldb.categorie AS ligne_categorie,
                       ld.designation AS devis_designation
                FROM fiche_decaissement f
                LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = f.ligne_debourse_id
                LEFT JOIN debourse_banamur db ON db.id = ldb.debourse_id
                LEFT JOIN ligne_devis_banamur ld ON ld.id = db.ligne_devis_id
                WHERE (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
                  AND " . implode(' AND ', $where) . "
                ORDER BY f.date_creat_fiche DESC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    // PDF paysage + style
    $pdf = new PDFAppr('L', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Export fiches approbation');
    $pdf->SetMargins(10, 38, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $title = 'Fiches d\'approbation';
    $pdf->reportTitle = 'BANAMUR — ' . $title;
    $pdf->companyPdfTitle = $title;
    $pdf->companyPdfType = 'Liste d’approbation';
    $pdf->companyPdfId = 'FIN-APPR-' . date('Ymd-His');
    $pdf->companyPdfCode = 'FIN-DEC-APPR';
    $pdf->companyPdfQrPayload = json_encode(['document'=>$pdf->companyPdfId,'type'=>'fiches_approbation','statut'=>$statut,'selection'=>$selected], JSON_UNESCAPED_UNICODE);
    $pdf->AddPage();


    $columns = [
        ['key' => 'num',   'label' => 'Num fiche',       'w' => 22, 'align' => 'L'],
        ['key' => 'benef', 'label' => 'Bénéficiaire',    'w' => 30, 'align' => 'L'],
        ['key' => 'chant', 'label' => 'Chantier',        'w' => 35, 'align' => 'L'],
        ['key' => 'ldb',   'label' => 'Ligne déboursé',  'w' => 35, 'align' => 'L'],
        ['key' => 'ldv',   'label' => 'Ligne devis',     'w' => 35, 'align' => 'L'],
        ['key' => 'prec',  'label' => 'Précision',       'w' => 35, 'align' => 'L'],
        ['key' => 'det',   'label' => 'Détails demande', 'w' => 40, 'align' => 'L'], // 👈 AJOUT
        ['key' => 'mnt',   'label' => 'Montant(CFA)',   'w' => 22, 'align' => 'R'],
        ['key' => 'date',  'label' => 'Créée le',        'w' => 23, 'align' => 'C'],
    ];

    $pdf->SetFillColor(245, 247, 250);
    $pdf->SetDrawColor(220, 225, 230);
    $pdf->SetTextColor(33, 37, 41);
    $pdf->SetFont('dejavusans', 'B', 9);
    foreach ($columns as $col) {
        $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
    }
    $pdf->Ln();

    $drawRow = function ($pdf, $columns, $data) {
        $lineHeights = [];
        foreach ($columns as $col) {
            $lineHeights[] = $pdf->getStringHeight($col['w'], (string)($data[$col['key']] ?? ''));
        }
        $h = max($lineHeights);
        if ($pdf->GetY() + $h > ($pdf->getPageHeight() - 26)) {
            $pdf->AddPage();
            $pdf->SetFillColor(245, 247, 250);
            $pdf->SetDrawColor(220, 225, 230);
            $pdf->SetTextColor(33, 37, 41);
            $pdf->SetFont('dejavusans', 'B', 9);
            foreach ($columns as $col) {
                $pdf->Cell($col['w'], 8, $col['label'], 1, 0, 'C', true);
            }
            $pdf->Ln();
            $pdf->SetFont('dejavusans', '', 9);
        }
        foreach ($columns as $col) {
            $pdf->MultiCell($col['w'], $h, (string)($data[$col['key']] ?? ''), 1, $col['align'], false, 0, '', '', true, 0, false, true, $h, 'M');
        }
        $pdf->Ln();
    };

    $pdf->SetFont('dejavusans', '', 9);
    $pdf->SetTextColor(55, 65, 81);
    $total = 0.0;
    foreach ($rows as $f) {
        $f = enrich_fiche_labels($f);
        $num   = $f['num_fiche'] ?? '';
        $benef = $f['beficiaire_fiche'] ?? $f['beneficiaire_fiche'] ?? '';
        $supplierLabel = recap_supplier_label($f);
        if ($supplierLabel !== '') {
            $benef = trim($benef) !== '' ? ($benef . "\n" . $supplierLabel) : $supplierLabel;
        }
        $chant = trim(($f['chantier_titre'] ?? '') . (!empty($f['chantier_code']) ? ' [' . $f['chantier_code'] . ']' : ''));
        $ldb   = (string)($f['ligne_debourse_label'] ?? '');
        $ldv   = (string)($f['ligne_devis_label'] ?? '');
        $details = (string)($f['details_demande'] ?? '');
        $prec  = (string)($f['precision_fiche'] ?? '');
        $mont  = (float)($f['montant_fiche'] ?? 0);
        $datec = isset($f['date_creat_fiche']) ? date('Y-m-d', strtotime($f['date_creat_fiche'])) : '';
        $total += $mont;
        $drawRow($pdf, $columns, [
            'num' => $num,
            'benef' => $benef,
            'chant' => $chant,
            'ldb' => $ldb,
            'ldv' => $ldv,
            'det' => $details,
            'prec' => $prec,
            'mnt' => number_format($mont, 0, ',', ' '),
            'date' => $datec,
        ]);
    }

    $pdf->Ln(1);
    $pdf->SetFont('dejavusans', 'B', 10);
    $pdf->SetTextColor(33, 37, 41);
    $leftWidth = 0;
    for ($i = 0; $i < count($columns) - 2; $i++) {
        $leftWidth += $columns[$i]['w'];
    }
    $pdf->Cell($leftWidth, 10, 'TOTAL', 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 2]['w'], 10, number_format($total, 0, ',', ' '), 1, 0, 'R');
    $pdf->Cell($columns[count($columns) - 1]['w'], 10, '', 1, 1, 'C');

    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiches_approbation_export',
        'entity_type' => 'fiche_approbation_list',
        'entity_label' => 'Fiches approbation',
        'format' => 'pdf',
        'details' => [
            'selection_count' => is_countable($selected ?? null) ? count($selected) : 0,
            'row_count' => is_countable($rows ?? null) ? count($rows) : null,
            'total' => $total,
            'statut' => $statut,
        ],
    ], $pdo);
    $pdf->Output('export_fiches_approbation_' . date('Ymd_His') . '.pdf', 'I');
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
