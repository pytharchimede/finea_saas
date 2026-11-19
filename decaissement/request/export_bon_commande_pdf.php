<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../model/BonCommande.php';

$numFiche = trim((string)($_GET['num_fiche'] ?? ''));
if ($numFiche === '') {
    http_response_code(400);
    echo 'Paramètre num_fiche manquant.';
    exit;
}

$pdo = \Database::getConnection();
$bcModel = new \BonCommande($pdo);
$ficheModel = new \FicheDecaissement($pdo);
$fournisseurModel = new \FournisseurRepository($pdo);

if (!$bcModel->isReady()) {
    http_response_code(503);
    echo 'Bon de commande indisponible.';
    exit;
}

$bc = $bcModel->getByNumFiche($numFiche);
if (!$bc) {
    http_response_code(404);
    echo 'Bon de commande introuvable. Enregistrez-le d\'abord.';
    exit;
}

$fiche = $ficheModel->getDetailsByNum($numFiche) ?: [];
$supplierContext = $fournisseurModel->getSupplierDisplayContext(
    isset($fiche['fournisseur_id']) ? (int) $fiche['fournisseur_id'] : null,
    array_merge($fiche, $bc)
);

$quantite = (float)($bc['article_quantite'] ?? 1);
$prixUnit = (float)($bc['article_prix_unitaire'] ?? 0);
$total = $quantite * $prixUnit;

function bc_public_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
}

function safe_text($value): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : '—';
}

function format_money_fcfa($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' FCFA';
}

class PDFWowBonCommande extends \TCPDF
{
    use CompanyPdfTemplate;
    public string $companyName = 'FIDESTCI / BANAMUR';

    public function Header(): void
    {
        $this->drawCompanyPdfHeader();
    }

    public function Footer(): void
    {
        $this->drawCompanyPdfFooter();
    }
}

$pdf = new PDFWowBonCommande('P', 'mm', 'A4');
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Bon de commande ' . (string)$bc['bc_number']);
$pdf->SetMargins(10, 38, 10);
$pdf->SetAutoPageBreak(true, 25);
$pdf->companyPdfType = 'Bon de commande interne';
$pdf->companyPdfTitle = 'Bon de commande interne';
$pdf->companyPdfId = (string)$bc['bc_number'];
$pdf->companyPdfCode = 'FIN-ACH-BCI';
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 20;

$logo = __DIR__ . '/../../logo/logo.png';
if (!@file_exists($logo)) {
    $logo = __DIR__ . '/../../img/logo_veritas.jpg';
}

// Bandeau titre
$headerX = 10;
$headerY = 39;
$headerH = 30;
$pdf->SetFillColor(31, 41, 55);
$pdf->RoundedRect($headerX, $headerY, $pageWidth, $headerH, 4, '1111', 'F');

if (@file_exists($logo)) {
    $pdf->Image($logo, $headerX + 2.5, $headerY + 3.5, 12, 0, '', '', '', false, 300);
}

$pdf->SetFont('dejavusans', 'B', 15.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY($headerX + 17, $headerY + 4);
$pdf->Cell(120, 7, 'BON DE COMMANDE', 0, 1, 'L');

$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(226, 232, 240);
$pdf->SetX($headerX + 17);
$pdf->Cell(120, 5, 'N° ' . safe_text($bc['bc_number'] ?? '') . '  |  Date ' . safe_text($bc['bc_date'] ?? ''), 0, 1, 'L');
$pdf->SetX($headerX + 17);
$pdf->Cell(120, 5, 'Réf. fiche : ' . safe_text($bc['num_fiche'] ?? ''), 0, 1, 'L');

// QR code (lien vers la page bon de commande)
$qrText = bc_public_base() . '/bon_commande.php?num_fiche=' . rawurlencode($numFiche);
$pdf->companyPdfQrPayload = $qrText;
$qrSize = 18;
$qrBox = $qrSize + 6;
$qrX = $headerX + $pageWidth - $qrBox - 3;
$qrY = $headerY + 5;
$pdf->SetFillColor(255, 255, 255);
$pdf->RoundedRect($qrX, $qrY, $qrBox, $qrBox, 3, '1111', 'F');
$qrStyle = ['border' => 0, 'padding' => 0, 'fgcolor' => [31, 41, 55], 'bgcolor' => false];
$pdf->write2DBarcode($qrText, 'QRCODE,H', $qrX + 3, $qrY + 3, $qrSize, $qrSize, $qrStyle, 'N');

// Blocs infos
$pdf->SetTextColor(31, 41, 55);
$pdf->SetFillColor(245, 246, 250);
$pdf->SetDrawColor(226, 232, 240);
$pdf->SetFont('dejavusans', '', 9);

$yInfo = $headerY + $headerH + 6;
$gap = 4;
$wLeft = 92;
$wRight = $pageWidth - $wLeft - $gap;

$left = "Acheteur : Banamur\n";
$left .= "Document : Bon de commande\n";
$left .= "N° : " . safe_text($bc['bc_number'] ?? '') . "\n";
$left .= "Date : " . safe_text($bc['bc_date'] ?? '') . "\n";
$left .= "Réf. fiche : " . safe_text($bc['num_fiche'] ?? '') . "\n";

$right = "Fournisseur : " . safe_text($bc['fournisseur_nom'] ?? ($supplierContext['supplier_name'] ?? '')) . "\n";
if (!empty($fiche['feb_request_id'])) {
    $right .= "Bénéficiaire FEB : " . safe_text($fiche['beficiaire_fiche'] ?? '') . "\n";
}
if (!empty($supplierContext['supplier_type_label'])) {
    $right .= "Type : " . safe_text($supplierContext['supplier_type_label']) . "\n";
}
if (!empty($supplierContext['supplier_contact'])) {
    $right .= "Contact : " . safe_text($supplierContext['supplier_contact']) . "\n";
}
$right .= "Tél : " . safe_text($bc['fournisseur_tel'] ?? ($supplierContext['supplier_phone'] ?? '')) . "\n";
if (!empty($bc['fournisseur_email']) || !empty($supplierContext['supplier_email'])) {
    $right .= "Email : " . safe_text($bc['fournisseur_email'] ?? $supplierContext['supplier_email'] ?? '') . "\n";
}
if (!empty($bc['fournisseur_adresse']) || !empty($supplierContext['supplier_address'])) {
    $right .= "Adresse : " . safe_text($bc['fournisseur_adresse'] ?? $supplierContext['supplier_address'] ?? '') . "\n";
}
if (!empty($supplierContext['supplier_categories_text'])) {
    $right .= "Catégories : " . safe_text($supplierContext['supplier_categories_text']) . "\n";
}

$leftH = max(28, $pdf->getStringHeight($wLeft, $left) + 4);
$rightH = max(28, $pdf->getStringHeight($wRight, $right) + 4);
$boxH = max($leftH, $rightH);

$pdf->SetXY(10, $yInfo);
$pdf->MultiCell($wLeft, $boxH, $left, 1, 'L', true, 0, null, null, true, 0, false, true, $boxH, 'T', true);
$pdf->SetXY(10 + $wLeft + $gap, $yInfo);
$pdf->MultiCell($wRight, $boxH, $right, 1, 'L', true, 1, null, null, true, 0, false, true, $boxH, 'T', true);

$pdf->Ln(4);

// Bandeau total
$pdf->SetFillColor(240, 253, 244);
$pdf->SetDrawColor(187, 247, 208);
$pdf->RoundedRect(10, $pdf->GetY(), $pageWidth, 12, 3, '1111', 'DF');
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->SetTextColor(21, 128, 61);
$pdf->SetXY(14, $pdf->GetY() + 3);
$pdf->Cell(80, 6, 'Total commande', 0, 0, 'L');
$pdf->Cell($pageWidth - 8, 6, format_money_fcfa($total), 0, 1, 'R');
$pdf->Ln(4);

// Objet
if (!empty($bc['objet'])) {
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell(0, 6, 'Objet', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 0, (string)$bc['objet'], 1, 'L', false, 1);
    $pdf->Ln(3);
}

// Table ligne
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Détails de la commande', 0, 1, 'L');

$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetFillColor(230, 233, 240);
$pdf->Cell(90, 7, 'Description', 1, 0, 'L', true);
$pdf->Cell(22, 7, 'Qté', 1, 0, 'R', true);
$pdf->Cell(18, 7, 'Unité', 1, 0, 'C', true);
$pdf->Cell(30, 7, 'PU (FCFA)', 1, 0, 'R', true);
$pdf->Cell(0, 7, 'Total', 1, 1, 'R', true);

$pdf->SetFont('dejavusans', '', 9.5);
$desc = (string)($bc['article_description'] ?? '');
$descHeight = $pdf->getStringHeight(90, $desc);
$rowH = max(14, $descHeight + 4);

// Si la ligne risque de tomber en bas de page, créer une nouvelle page avant (propreté)
if ($pdf->GetY() + $rowH + 25 > ($pdf->getPageHeight() - 18)) {
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(0, 6, 'Détails de la commande (suite)', 0, 1, 'L');
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetFillColor(230, 233, 240);
    $pdf->Cell(90, 7, 'Description', 1, 0, 'L', true);
    $pdf->Cell(22, 7, 'Qté', 1, 0, 'R', true);
    $pdf->Cell(18, 7, 'Unité', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'PU (FCFA)', 1, 0, 'R', true);
    $pdf->Cell(0, 7, 'Total', 1, 1, 'R', true);
    $pdf->SetFont('dejavusans', '', 9.5);
}

$x = $pdf->GetX();
$y = $pdf->GetY();
$pdf->MultiCell(90, $rowH, $desc, 1, 'L', false, 0, $x, $y, true, 0, false, true, $rowH, 'T', true);
$pdf->Cell(22, $rowH, number_format($quantite, 2, ',', ' '), 1, 0, 'R');
$pdf->Cell(18, $rowH, safe_text($bc['article_unite'] ?? ''), 1, 0, 'C');
$pdf->Cell(30, $rowH, number_format($prixUnit, 2, ',', ' '), 1, 0, 'R');
$pdf->Cell(0, $rowH, number_format($total, 0, ',', ' ') . ' FCFA', 1, 1, 'R');

$pdf->Ln(3);

// Conditions
$info = [];
if (!empty($bc['lieu_livraison'])) {
    $info[] = 'Lieu de livraison : ' . (string)$bc['lieu_livraison'];
}
if (!empty($bc['delai_livraison'])) {
    $info[] = 'Délai de livraison : ' . (string)$bc['delai_livraison'];
}
if (!empty($bc['conditions_paiement'])) {
    $info[] = 'Conditions de paiement : ' . (string)$bc['conditions_paiement'];
}
if (!empty($info)) {
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->MultiCell(0, 0, implode("\n", $info), 1, 'L', false, 1);
    $pdf->Ln(3);
}

if (!empty($bc['notes'])) {
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(0, 6, 'Notes', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 9.5);
    $pdf->MultiCell(0, 0, (string)$bc['notes'], 1, 'L', false, 1);
    $pdf->Ln(3);
}

// Signatures
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Signatures', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 9.5);
$pdf->SetFillColor(245, 246, 250);
$pdf->MultiCell(95, 30, "Banamur\n\nNom : " . (string)($bc['sign_acheteur_nom'] ?? ''), 1, 'L', true, 0);
$pdf->MultiCell(0, 30, "Fournisseur\n\nNom : " . (string)($bc['sign_fournisseur_nom'] ?? ''), 1, 'L', true, 1);

$filename = 'bon_commande_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($bc['bc_number'] ?? $numFiche)) . '.pdf';
\AuditTrail::logFileEvent('export', [
    'module' => 'decaissement',
    'action' => 'bon_commande_export',
    'entity_type' => 'bon_commande',
    'entity_id' => (string)($bc['id'] ?? $numFiche),
    'entity_label' => (string)($bc['bc_number'] ?? $numFiche),
    'format' => 'pdf',
    'details' => [
        'num_fiche' => $numFiche,
        'fournisseur' => $bc['fournisseur_nom'] ?? null,
        'montant_total' => $total,
    ],
], $pdo);
$pdf->Output($filename, 'I');
