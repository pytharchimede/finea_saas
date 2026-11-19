<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';

class PDFDepensesPlanifiees extends TCPDF
{
    use CompanyPdfTemplate;
    public string $reportTitle = 'Dépenses Planifiées';
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
            $this->Cell(0, 8, $this->reportTitle, 0, 1, 'C');
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
        $this->SetY(-15);
        $this->SetFont('dejavusans', 'I', 8);
        $this->SetTextColor(128);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdo = Database::getConnection();

$filters = [
    'date_debut' => $_GET['date_debut'] ?? null,
    'date_fin' => $_GET['date_fin'] ?? null,
    'num_fiche' => $_GET['num_fiche'] ?? null,
    'montant_min' => $_GET['montant_min'] ?? null,
    'montant_max' => $_GET['montant_max'] ?? null,
    'chantier' => $_GET['chantier'] ?? null,
    'beneficiaire' => $_GET['beneficiaire'] ?? null,
    'date_creat_fiche' => $_GET['date_creat_fiche'] ?? null,
];

$query = "
    SELECT f.*, c.titre as chantier_banamur
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON f.chantier_id = c.id
    WHERE f.date_decaissement_minimum IS NOT NULL
      AND (f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL)
    AND f.decaisse = 0
    AND f.etat_fiche != 2
";

$params = [];

if ($filters['date_debut']) {
    $query .= " AND f.date_decaissement_minimum >= ?";
    $params[] = $filters['date_debut'];
}

if ($filters['date_fin']) {
    $query .= " AND f.date_decaissement_minimum <= ?";
    $params[] = $filters['date_fin'];
}

if ($filters['num_fiche']) {
    $query .= " AND f.num_fiche LIKE ?";
    $params[] = '%' . $filters['num_fiche'] . '%';
}

if ($filters['montant_min']) {
    $query .= " AND f.montant_fiche >= ?";
    $params[] = $filters['montant_min'];
}

if ($filters['montant_max']) {
    $query .= " AND f.montant_fiche <= ?";
    $params[] = $filters['montant_max'];
}

if ($filters['chantier']) {
    $query .= " AND c.titre LIKE ?";
    $params[] = '%' . $filters['chantier'] . '%';
}

if ($filters['beneficiaire']) {
    $query .= " AND f.beficiaire_fiche LIKE ?";
    $params[] = '%' . $filters['beneficiaire'] . '%';
}

if ($filters['date_creat_fiche']) {
    $query .= " AND DATE(f.date_creation) = ?";
    $params[] = $filters['date_creat_fiche'];
}

$query .= " ORDER BY f.date_decaissement_minimum ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$fiches = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pdf = new PDFDepensesPlanifiees('L', 'mm', 'A4');
$pdf->companyPdfTitle = 'Depenses planifiees';
$pdf->companyPdfType = 'Etat financier';
$pdf->companyPdfId = 'FIN-DEP-' . date('Ymd-His');
$pdf->companyPdfCode = 'FIN-DEP-01';
$pdf->companyPdfQrPayload = $pdf->companyPdfId;
$pdf->SetCreator('FINEA');
$pdf->SetAuthor('FINEA');
$pdf->SetTitle('Dépenses Planifiées');
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$pdf->SetFont('dejavusans', 'B', 12);
$pdf->Cell(0, 10, 'Dépenses Planifiées - ' . date('d/m/Y'), 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(30, 8, 'N° Fiche', 1, 0, 'C');
$pdf->Cell(40, 8, 'Montant', 1, 0, 'C');
$pdf->Cell(40, 8, 'Date Décaissement', 1, 0, 'C');
$pdf->Cell(50, 8, 'Chantier', 1, 0, 'C');
$pdf->Cell(50, 8, 'Bénéficiaire', 1, 0, 'C');
$pdf->Cell(30, 8, 'Statut', 1, 1, 'C');

$pdf->SetFont('dejavusans', '', 9);
foreach ($fiches as $fiche) {
    $statut = $fiche['date_decaissement_minimum'] < date('Y-m-d') ? 'En retard' : 'Planifié';
    $pdf->Cell(30, 8, $fiche['num_fiche'], 1, 0, 'L');
    $pdf->Cell(40, 8, number_format($fiche['montant_fiche'], 0, ',', ' ') . ' CFA', 1, 0, 'R');
    $pdf->Cell(40, 8, date('d/m/Y', strtotime($fiche['date_decaissement_minimum'])), 1, 0, 'C');
    $pdf->Cell(50, 8, $fiche['chantier'] ?: '', 1, 0, 'L');
    $pdf->Cell(50, 8, $fiche['beficiaire_fiche'] ?: '', 1, 0, 'L');
    $pdf->Cell(30, 8, $statut, 1, 1, 'C');
}

$pdf->Output('depenses_planifiees_' . date('Y-m-d') . '.pdf', 'D');
