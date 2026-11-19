<?php
require_once __DIR__ . '/../../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../controllers/FournisseurController.php';

class SupplierPdf extends TCPDF
{
    public function Header()
    {
        $this->SetY(8);
        $this->SetFont('dejavusans', 'B', 14);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 6, 'Fiche fournisseur', 0, 1, 'L');
    }

    public function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

function supplier_safe_text($value): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? $text : '—';
}

function supplier_pdf_ensure_space(TCPDF $pdf, float $neededHeight, float $bottomMargin = 18.0): void
{
    if (($pdf->GetY() + $neededHeight) > ($pdf->getPageHeight() - $bottomMargin)) {
        $pdf->AddPage();
    }
}

function supplier_pdf_section(TCPDF $pdf, string $title, array $rows, float $labelWidth = 42.0): void
{
    supplier_pdf_ensure_space($pdf, max(24, 12 + (count($rows) * 9)));
    $x = 10;
    $w = $pdf->getPageWidth() - 20;
    $contentWidth = $w - 8;
    $valueWidth = max(30.0, $contentWidth - $labelWidth);
    $startY = $pdf->GetY();
    $cursorY = $startY + 4;
    $pdf->SetXY($x + 4, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell($w - 8, 5, $title, 0, 1, 'L');
    $cursorY += 8;
    foreach ($rows as [$label, $value]) {
        $labelText = $label . ' :';
        $valueText = (string) $value;
        $labelHeight = max(5.0, $pdf->getStringHeight($labelWidth, $labelText));
        $valueHeight = max(5.0, $pdf->getStringHeight($valueWidth, $valueText));
        $rowHeight = max($labelHeight, $valueHeight);

        $pdf->SetXY($x + 4, $cursorY);
        $pdf->SetFont('dejavusans', 'B', 8.2);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell($labelWidth, $rowHeight, $labelText, 0, 'L', false, 0);

        $pdf->SetXY($x + 4 + $labelWidth, $cursorY);
        $pdf->SetFont('dejavusans', '', 8.2);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->MultiCell($valueWidth, $rowHeight, $valueText, 0, 'L', false, 1);
        $cursorY = $pdf->GetY() + 1.4;
    }
    $endY = $cursorY + 2;
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $startY, $w, $endY - $startY, 3, '1111', 'D');
    $pdf->SetY($endY + 4);
}

$supplierId = (int) ($_GET['id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$isValidator = \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;

if ($supplierId <= 0) {
    http_response_code(400);
    exit('Fournisseur manquant');
}

$pdo = \Database::getConnection();
$controller = new \FournisseurController($pdo);
$viewData = $controller->detailsView($supplierId);
$supplier = $viewData['supplier'];
if (!$supplier) {
    http_response_code(404);
    exit('Fournisseur introuvable');
}

if ($isEmployeeOnly && (int) ($supplier['created_by_user_id'] ?? 0) !== $currentUserId) {
    http_response_code(403);
    exit('Acces refuse');
}

$context = $viewData['context'];
$requiredDocuments = array_values($context['supplier_required_documents'] ?? []);
$missingDocuments = array_values($context['supplier_missing_documents'] ?? []);
$availableDocuments = array_values($context['supplier_documents'] ?? []);
$availableLabels = array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $availableDocuments);

$pdf = new SupplierPdf('P', 'mm', 'A4');
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Fiche fournisseur ' . $supplierId);
$pdf->SetMargins(10, 18, 10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

$pdf->SetFillColor(15, 23, 42);
$pdf->RoundedRect(10, 18, $pdf->getPageWidth() - 20, 24, 3, '1111', 'F');
$pdf->SetXY(14, 22);
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 6, supplier_safe_text($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? $supplier['raison_sociale']), 0, 1, 'L');
$pdf->SetX(14);
$pdf->SetFont('dejavusans', '', 8.4);
$pdf->SetTextColor(226, 232, 240);
$pdf->Cell(0, 5, 'Code ' . supplier_safe_text($supplier['code_fournisseur'] ?? '') . ' | ' . supplier_safe_text($context['supplier_type_label'] ?? ''), 0, 1, 'L');
$pdf->SetY(48);

supplier_pdf_section($pdf, 'Referentiel', [
    ['Fournisseur', supplier_safe_text($context['supplier_name'] ?? '')],
    ['Forme juridique', supplier_safe_text($context['supplier_legal_form'] ?? '')],
    ['Contact', supplier_safe_text($context['supplier_contact'] ?? '')],
    ['Telephone', supplier_safe_text($context['supplier_phone'] ?? '')],
    ['Email', supplier_safe_text($context['supplier_email'] ?? '')],
    ['Adresse', supplier_safe_text($context['supplier_address'] ?? '')],
    ['Categories', supplier_safe_text($context['supplier_categories_text'] ?? '')],
]);

supplier_pdf_section($pdf, 'Completude documentaire', [
    ['Statut', supplier_safe_text($context['supplier_document_completion_label'] ?? '')],
    ['Taux', (int) ($context['supplier_document_completion_percent'] ?? 0) . '%'],
    ['Pieces manquantes', $missingDocuments ? implode(', ', $missingDocuments) : 'Aucune'],
]);

$documentRows = [];
foreach ($requiredDocuments as $label) {
    $documentRows[] = [
        (string) $label,
        in_array((string) $label, $availableLabels, true) ? 'Fournie' : 'Manquante',
    ];
}
foreach ($availableDocuments as $document) {
    $label = (string) ($document['label'] ?? '');
    if ($label === '' || in_array($label, $requiredDocuments, true)) {
        continue;
    }
    $documentRows[] = [$label, 'Disponible'];
}
supplier_pdf_section($pdf, 'Liste detaillee complete des pieces', $documentRows ?: [['Aucune piece', '—']], 68.0);

$pdf->Output('fournisseur_' . $supplierId . '.pdf', 'I');
exit;
