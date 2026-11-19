<?php
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../../model/AuditTrail.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../../services/FebBeneficiarySyncService.php';
require_once __DIR__ . '/../model/FicheDecaissement.php';
require_once __DIR__ . '/../includes/fiche_label_helper.php';

class PDFWowFiche extends TCPDF
{
    use CompanyPdfTemplate;
    public string $companyName = 'FIDESTCI / BANAMUR';

    public function Header()
    {
        $this->drawCompanyPdfHeader();
    }

    public function Footer()
    {
        $this->drawCompanyPdfFooter();
    }
}

function fiche_public_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
}

function get_public_file_and_path(string $subDir, ?string $fileName): array
{
    if (!$fileName) {
        return [null, null];
    }
    $fsPath = realpath(__DIR__ . '/../uploads/' . $subDir . '/' . $fileName);
    if (!$fsPath || !is_file($fsPath)) {
        return [null, null];
    }
    return [fiche_public_base() . '/uploads/' . $subDir . '/' . rawurlencode($fileName), $fsPath];
}

function resolve_relative_public_file(?string $relativePath): array
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return [null, null];
    }

    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    $fsPath = realpath(__DIR__ . '/../../' . $normalized);
    if (!$fsPath || !is_file($fsPath)) {
        return [null, null];
    }

    return [fiche_public_base() . '/' . str_replace('%2F', '/', rawurlencode($normalized)), $fsPath];
}

function safe_text($value): string
{
    $text = trim((string)$value);
    return $text !== '' ? $text : '—';
}

function format_money($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' FCFA';
}

function format_date_fr(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function image_fit_box(TCPDF $pdf, string $filePath, float $x, float $y, float $w, float $h): void
{
    if (!is_file($filePath)) {
        return;
    }
    [$imgW, $imgH] = @getimagesize($filePath) ?: [0, 0];
    if ($imgW <= 0 || $imgH <= 0) {
        $pdf->Image($filePath, $x, $y, $w, $h, '', '', '', false, 300, '', false, false, 1, false, false, false);
        return;
    }
    $ratio = min($w / $imgW, $h / $imgH);
    $renderW = $imgW * $ratio;
    $renderH = $imgH * $ratio;
    $offsetX = $x + (($w - $renderW) / 2);
    $offsetY = $y + (($h - $renderH) / 2);
    $pdf->Image($filePath, $offsetX, $offsetY, $renderW, $renderH, '', '', '', false, 300, '', false, false, 1, false, false, false);
}

function draw_info_row(TCPDF $pdf, float $x, float $y, float $labelW, float $valueW, string $label, string $value): float
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($labelW, 4.8, $label, 0, 0, 'L');
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $height = max(4.8, $pdf->getStringHeight($valueW, $value));
    $pdf->MultiCell($valueW, $height, $value, 0, 'L', false, 1, '', '', true, 0, false, true, $height, 'M');
    return $height;
}

function draw_compact_info_row(TCPDF $pdf, float $x, float $y, float $width, string $label, string $value): float
{
    $pdf->SetXY($x, $y);
    $pdf->SetFont('dejavusans', 'B', 7.8);
    $pdf->SetTextColor(55, 65, 81);
    $labelHeight = max(3.8, $pdf->getStringHeight($width, $label));
    $pdf->MultiCell($width, $labelHeight, $label, 0, 'L', false, 1, $x, $y, true, 0, false, true, $labelHeight, 'M');

    $valueY = $y + $labelHeight + 0.4;
    $pdf->SetXY($x, $valueY);
    $pdf->SetFont('dejavusans', '', 8.1);
    $pdf->SetTextColor(31, 41, 55);
    $valueHeight = max(4.2, $pdf->getStringHeight($width, $value));
    $pdf->MultiCell($width, $valueHeight, $value, 0, 'L', false, 1, $x, $valueY, true, 0, false, true, $valueHeight, 'M');

    return $labelHeight + $valueHeight + 0.4;
}

function ensure_pdf_space(TCPDF $pdf, float $neededHeight, float $bottomMargin = 20.0): void
{
    if (($pdf->GetY() + $neededHeight) > ($pdf->getPageHeight() - $bottomMargin)) {
        $pdf->AddPage();
    }
}

function shorten_text(string $value, int $limit): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    if ($text === '') {
        return '—';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $limit
            ? rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…'
            : $text;
    }

    return strlen($text) > $limit
        ? rtrim(substr($text, 0, $limit - 3)) . '...'
        : $text;
}

function supplier_document_exists(array $documents, string $label): bool
{
    foreach ($documents as $document) {
        if (strcasecmp((string) ($document['label'] ?? ''), $label) === 0) {
            return true;
        }
    }

    return false;
}

function format_history_event_line(array $event): string
{
    $action = shorten_text((string)($event['action'] ?? ''), 110);
    $user = shorten_text((string)($event['utilisateur'] ?? ''), 28);
    $date = format_date_fr($event['date_action'] ?? null);

    return $action . ' | ' . $user . ' | ' . $date;
}

if (empty($_GET['num_fiche'])) {
    http_response_code(400);
    echo 'Numéro de fiche manquant';
    exit;
}

$numFiche = trim((string)$_GET['num_fiche']);

try {
    $pdo = Database::getConnection();
    $ficheModel = new FicheDecaissement($pdo);
    $fournisseurModel = new FournisseurRepository($pdo);
    $fiche = $ficheModel->getDetailsByNum($numFiche);
    if (!$fiche) {
        throw new RuntimeException('Fiche introuvable');
    }
    $fiche = enrich_fiche_labels($fiche);
    $beneficiaryMode = (int) ($fiche['personnel_id'] ?? 0) > 0 ? 'personnel' : 'external';
    if ((int) ($fiche['feb_request_id'] ?? 0) > 0) {
        $febStmt = $pdo->prepare('SELECT payload_json FROM employee_legal_requests WHERE id=:id LIMIT 1');
        $febStmt->execute(['id' => (int) $fiche['feb_request_id']]);
        $febPayload = json_decode((string) $febStmt->fetchColumn(), true);
        $selection = is_array($febPayload) ? FebBeneficiarySyncService::selected($pdo, $febPayload) : null;
        if ($selection) $beneficiaryMode = $selection['mode'];
    }
    $beneficiaryDetails = [];
    if ((int) ($fiche['personnel_id'] ?? 0) > 0) {
        $personStmt = $pdo->prepare('SELECT p.email_personnel_soignant,p.matricule_personnel_soignant,f.lib_fonction,s.lib_service FROM personnel p LEFT JOIN fonction f ON f.id_fonction=p.fonction_id LEFT JOIN service s ON s.id_service=p.service_id WHERE p.id_personnel_soignant=:id LIMIT 1');
        $personStmt->execute(['id' => (int) $fiche['personnel_id']]);
        $beneficiaryDetails = $personStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    $supplierContext = $fournisseurModel->getSupplierDisplayContext(
        isset($fiche['fournisseur_id']) ? (int) $fiche['fournisseur_id'] : null,
        $fiche
    );
    $supplierDocuments = array_values(array_filter((array) ($supplierContext['supplier_documents'] ?? []), static function ($document): bool {
        return is_array($document) && trim((string) ($document['label'] ?? '')) !== '';
    }));
    $supplierRequiredDocuments = array_values(array_filter((array) ($supplierContext['supplier_required_documents'] ?? []), static function ($label): bool {
        return trim((string) $label) !== '';
    }));
    $supplierMissingDocuments = array_values(array_filter((array) ($supplierContext['supplier_missing_documents'] ?? []), static function ($label): bool {
        return trim((string) $label) !== '';
    }));
    [, $supplierVisualPath] = resolve_relative_public_file($supplierContext['supplier_visual_path'] ?? null);
    [, $supplierCniPath] = resolve_relative_public_file($supplierContext['supplier_cni_path'] ?? null);
    $supplierVisualLabel = ($supplierContext['supplier_type'] ?? null) === FournisseurRepository::TYPE_INDIVIDUAL ? 'Photo fournisseur' : 'Logo fournisseur';
    $hasLiabilityInsurance = supplier_document_exists($supplierDocuments, 'Assurance responsabilité civile');
    $hasRib = supplier_document_exists($supplierDocuments, 'RIB');

    $ligneDebourseId = $fiche['ligne_debourse_id'] ?? null;
    $montantPrevu = (float)($fiche['ligne_montant'] ?? 0);
    $stSum = $pdo->prepare("SELECT SUM(montant_fiche) as total_decaisse FROM fiche_decaissement WHERE ligne_debourse_id=:ligne AND decaisse=1 AND num_fiche<>:num");
    $stSum->execute(['ligne' => $ligneDebourseId, 'num' => $numFiche]);
    $montantDejaDecaisse = (float)($stSum->fetch(PDO::FETCH_ASSOC)['total_decaisse'] ?? 0);
    $montantRestant = $montantPrevu - $montantDejaDecaisse;

    $historiqueStmt = $pdo->prepare("SELECT action, utilisateur, date_action, adresse_ip FROM trace_fiche WHERE num_fiche=:num ORDER BY date_action DESC LIMIT 5");
    $historiqueStmt->execute(['num' => $numFiche]);
    $historique = $historiqueStmt->fetchAll(PDO::FETCH_ASSOC);

    $fichesLiees = [];
    if (!empty($fiche['ligne_debourse_id'])) {
        $linkedStmt = $pdo->prepare("SELECT montant_fiche, approuve, etat_fiche, decaisse FROM fiche_decaissement WHERE ligne_debourse_id=:ligne AND num_fiche<>:num");
        $linkedStmt->execute(['ligne' => $fiche['ligne_debourse_id'], 'num' => $numFiche]);
        $fichesLiees = $linkedStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $fichesLieesStats = [
        'count' => count($fichesLiees),
        'total_decaisse' => 0.0,
        'total_refuse' => 0.0,
    ];
    foreach ($fichesLiees as $linked) {
        $linkedMontant = (float)($linked['montant_fiche'] ?? 0);
        if ((int)($linked['decaisse'] ?? 0) === 1) {
            $fichesLieesStats['total_decaisse'] += $linkedMontant;
        }
        if ((int)($linked['approuve'] ?? 0) === 2 || (int)($linked['etat_fiche'] ?? 0) === 2) {
            $fichesLieesStats['total_refuse'] += $linkedMontant;
        }
    }

    [$photoUrl, $photoPath] = get_public_file_and_path('photo', $fiche['photo_beneficiaire'] ?? null);
    [$cniUrl, $cniPath] = get_public_file_and_path('cni', $fiche['cni_beneficiaire'] ?? null);
    [$signatureUrl, $signaturePath] = get_public_file_and_path('signatures', $fiche['signature_fichier'] ?? null);

    $statusText = 'En attente';
    $statusFill = [100, 116, 139];
    if ((int)($fiche['decaisse'] ?? 0) === 1) {
        $statusText = 'Décaissée';
        $statusFill = [22, 163, 74];
    } elseif ((int)($fiche['otp_valide'] ?? 0) === 1) {
        $statusText = 'OTP validé';
        $statusFill = [2, 132, 199];
    } elseif ((int)($fiche['approuve'] ?? 0) === 2 || (int)($fiche['etat_fiche'] ?? 0) === 2) {
        $statusText = 'Refusée';
        $statusFill = [220, 38, 38];
    } elseif ((int)($fiche['approuve'] ?? 0) === 1) {
        $statusText = 'Approuvée';
        $statusFill = [245, 158, 11];
    } elseif ((int)($fiche['conforme'] ?? 0) === 1) {
        $statusText = 'Conforme';
        $statusFill = [37, 99, 235];
    }

    $pdf = new PDFWowFiche('P', 'mm', 'A4');
    $pdf->SetCreator('Banamur');
    $pdf->SetAuthor('Banamur');
    $pdf->SetTitle('Fiche de décaissement ' . $numFiche);
    $pdf->SetMargins(10, 38, 10);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->companyName = safe_text($fiche['entreprise'] ?? $fiche['chantier_titre'] ?? 'BANAMUR');
    $pdf->companyPdfType = 'Fiche de décaissement';
    $pdf->companyPdfId = $numFiche;
    $pdf->companyPdfCode = 'FIN-DEC-FICHE';
    $pdf->companyPdfTitle = 'Fiche de décaissement';
    $qrText = fiche_public_base() . '/voir_fiche_details.php?num_fiche=' . rawurlencode($numFiche);
    $pdf->companyPdfQrPayload = $qrText;
    $pdf->AddPage();

    $pageWidth = $pdf->getPageWidth() - 20;

    // Résumé propre au document, séparé de l'en-tête institutionnel.
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect(10, 40, $pageWidth, 17, 3, '1111', 'DF');
    $pdf->SetXY(14, 43);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(61, 5, '#' . $numFiche, 0, 0, 'L');
    $pdf->SetTextColor($statusFill[0], $statusFill[1], $statusFill[2]);
    $pdf->Cell(42, 5, $statusText, 0, 0, 'C');
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(48, 5, format_money($fiche['montant_fiche'] ?? 0), 0, 0, 'R');
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY(14, 49);
    $pdf->Cell(0, 4, 'Créée le ' . format_date_fr($fiche['date_creat_fiche'] ?? null), 0, 0, 'L');

    // KPI strip
    $kpiY = 61;
    $kpiGap = 4;
    $kpiWidth = ($pageWidth - ($kpiGap * 3)) / 4;
    $kpis = [
        ['label' => 'Prévu', 'value' => format_money($montantPrevu), 'fill' => [239, 246, 255], 'text' => [29, 78, 216]],
        ['label' => 'Décaissé', 'value' => format_money($montantDejaDecaisse), 'fill' => [240, 253, 244], 'text' => [21, 128, 61]],
        ['label' => 'Restant', 'value' => format_money($montantRestant), 'fill' => [255, 247, 237], 'text' => [194, 65, 12]],
        ['label' => 'Fiches liées', 'value' => (string)$fichesLieesStats['count'], 'fill' => [245, 243, 255], 'text' => [109, 40, 217]],
    ];
    foreach ($kpis as $index => $kpi) {
        $x = 10 + (($kpiWidth + $kpiGap) * $index);
        $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($x, $kpiY, $kpiWidth, 16, 3, '1111', 'DF');
        $pdf->SetXY($x + 3, $kpiY + 2.5);
        $pdf->SetFont('dejavusans', '', 7.2);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($kpiWidth - 6, 3.8, $kpi['label'], 0, 2, 'L');
        $pdf->SetX($x + 3);
        $pdf->SetFont('dejavusans', 'B', 10.5);
        $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
        $pdf->Cell($kpiWidth - 6, 5.5, $kpi['value'], 0, 0, 'L');
    }

    $pdf->SetY(82);
    $sectionX = 10;
    $sectionW = $pageWidth;
    $contentX = $sectionX + 4;
    $contentW = $sectionW - 8;

    $labelW = 33;
    $valueW = $contentW - $labelW;
    $rowsInfo = [
        ['Bénéficiaire', safe_text($fiche['beficiaire_fiche'] ?? '')],
        ['Type bénéficiaire', ['personnel' => 'Membre du personnel', 'supplier' => 'Fournisseur bénéficiaire', 'external' => 'Bénéficiaire externe'][$beneficiaryMode] ?? 'Bénéficiaire'],
        ['Téléphone', safe_text($fiche['tel_beneficiaire_fiche'] ?? '')],
        ['Entreprise', safe_text($fiche['entreprise'] ?? '')],
        ['Paiement', safe_text($fiche['mode_paiement'] ?? '')],
        ['Chantier', safe_text(trim(($fiche['chantier_code'] ?? '') . ' - ' . ($fiche['chantier_titre'] ?? '')))],
        ['Client', safe_text($fiche['nom_client'] ?? '')],
        ['Déboursé', safe_text($fiche['ligne_devis_label'] ?? '')],
        ['Ligne précise', safe_text($fiche['ligne_debourse_label'] ?? '')],
    ];
    foreach (['matricule_personnel_soignant' => 'Matricule bénéficiaire', 'email_personnel_soignant' => 'Email bénéficiaire', 'lib_fonction' => 'Fonction bénéficiaire', 'lib_service' => 'Service bénéficiaire'] as $field => $label) {
        if (!empty($beneficiaryDetails[$field])) $rowsInfo[] = [$label, safe_text($beneficiaryDetails[$field])];
    }
    if (!empty($supplierContext['supplier_name'])) {
        $rowsInfo[] = ['Fournisseur', safe_text($supplierContext['supplier_name'] ?? '')];
        $rowsInfo[] = ['Type fournisseur', safe_text($supplierContext['supplier_type_label'] ?? '')];
        $rowsInfo[] = ['Contact fournisseur', safe_text($supplierContext['supplier_contact'] ?? '')];
        $rowsInfo[] = ['Tél. fournisseur', safe_text($supplierContext['supplier_phone'] ?? '')];
        $rowsInfo[] = ['Email fournisseur', safe_text($supplierContext['supplier_email'] ?? '')];
        $rowsInfo[] = ['Adresse fournisseur', safe_text($supplierContext['supplier_address'] ?? '')];
        $rowsInfo[] = ['Catégories', shorten_text((string) ($supplierContext['supplier_categories_text'] ?? ''), 80)];
    }

    $designationText = safe_text($fiche['designation_fiche'] ?? '');
    $precisionText = safe_text($fiche['precision_fiche'] ?? '');
    $detailsText = safe_text($fiche['details_demande'] ?? '');

    $infoHeight = 14;
    foreach ($rowsInfo as [, $value]) {
        $infoHeight += max(4.8, $pdf->getStringHeight($valueW, $value)) + 1.6;
    }
    $infoHeight += 6 + max(8, $pdf->getStringHeight($contentW, $designationText));
    $infoHeight += 6 + max(8, $pdf->getStringHeight($contentW, $precisionText));
    $infoHeight += 6 + max(16, $pdf->getStringHeight($contentW, $detailsText)) + 6;
    ensure_pdf_space($pdf, $infoHeight);

    $sectionStartY = $pdf->GetY();
    $cursorY = $sectionStartY + 4;
    $pdf->SetXY($contentX, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(80, 5.5, 'Informations détaillées', 0, 1, 'L');
    $cursorY += 8;
    foreach ($rowsInfo as [$label, $value]) {
        $height = draw_info_row($pdf, $contentX, $cursorY, $labelW, $valueW, $label . ' :', $value);
        $cursorY += $height + 1.6;
    }
    $pdf->SetXY($contentX, $cursorY + 1);
    $pdf->SetFont('dejavusans', 'B', 8.5);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(32, 4.5, 'Désignation :', 0, 1, 'L');
    $pdf->SetX($contentX);
    $pdf->SetFont('dejavusans', '', 8.4);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->MultiCell($contentW, max(8, $pdf->getStringHeight($contentW, $designationText)), $designationText, 0, 'L', false, 1);
    $cursorY = $pdf->GetY() + 1;

    $pdf->SetXY($contentX, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 8.5);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(28, 4.5, 'Précision :', 0, 1, 'L');
    $pdf->SetX($contentX);
    $pdf->SetFont('dejavusans', '', 8.4);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->MultiCell($contentW, max(8, $pdf->getStringHeight($contentW, $precisionText)), $precisionText, 0, 'L', false, 1);
    $cursorY = $pdf->GetY() + 1;

    $pdf->SetXY($contentX, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 8.5);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(38, 4.5, 'Détails demande :', 0, 1, 'L');
    $pdf->SetX($contentX);
    $pdf->SetFont('dejavusans', '', 8.4);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->MultiCell($contentW, max(16, $pdf->getStringHeight($contentW, $detailsText)), $detailsText, 0, 'L', false, 1);
    $sectionEndY = $pdf->GetY() + 4;
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($sectionX, $sectionStartY, $sectionW, $sectionEndY - $sectionStartY, 3, '1111', 'D');
    $pdf->SetY($sectionEndY + 4);

    $displayVisualPath = $photoPath ?: ($beneficiaryMode === 'supplier' ? $supplierVisualPath : null);
    $displayIdentityPath = $cniPath ?: ($beneficiaryMode === 'supplier' ? $supplierCniPath : null);
    $displayVisualLabel = $beneficiaryMode === 'supplier' ? 'Photo ou logo bénéficiaire' : 'Photo bénéficiaire';
    $displayIdentityLabel = 'Pièce identité bénéficiaire';

    $validationHeight = ($supplierVisualPath || $supplierCniPath) ? 130 : 90;
    ensure_pdf_space($pdf, $validationHeight);
    $sectionStartY = $pdf->GetY();
    $cursorY = $sectionStartY + 4;
    $pdf->SetXY($contentX, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(90, 5.5, 'Validation et justificatifs', 0, 1, 'L');
    $cursorY += 8;

    $boxGap = 4;
    $imageBoxW = ($contentW - $boxGap) / 2;
    $imageBoxH = 28;
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($contentX, $cursorY, $imageBoxW, $imageBoxH, 2, '1111', 'DF');
    $pdf->RoundedRect($contentX + $imageBoxW + $boxGap, $cursorY, $imageBoxW, $imageBoxH, 2, '1111', 'DF');
    if ($displayVisualPath) {
        image_fit_box($pdf, $displayVisualPath, $contentX + 0.8, $cursorY + 1.2, $imageBoxW - 1.6, $imageBoxH - 3.6);
    }
    if ($displayIdentityPath) {
        image_fit_box($pdf, $displayIdentityPath, $contentX + $imageBoxW + $boxGap + 0.8, $cursorY + 1.2, $imageBoxW - 1.6, $imageBoxH - 3.6);
    }
    $pdf->SetFont('dejavusans', '', 6.8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->SetXY($contentX, $cursorY + $imageBoxH + 0.8);
    $pdf->Cell($imageBoxW, 3.5, $displayVisualLabel, 0, 0, 'C');
    $pdf->Cell($boxGap, 3.5, '', 0, 0);
    $pdf->Cell($imageBoxW, 3.5, $displayIdentityLabel, 0, 1, 'C');
    $cursorY += $imageBoxH + 7;
    if ($supplierVisualPath || $supplierCniPath) {
        $pdf->SetXY($contentX, $cursorY);
        $pdf->SetFont('dejavusans', 'B', 8);
        $pdf->SetTextColor(55, 65, 81);
        $pdf->Cell($contentW, 4, 'Visuels du fournisseur', 0, 1, 'L');
        $cursorY += 5;
        $pdf->RoundedRect($contentX, $cursorY, $imageBoxW, $imageBoxH, 2, '1111', 'D');
        $pdf->RoundedRect($contentX + $imageBoxW + $boxGap, $cursorY, $imageBoxW, $imageBoxH, 2, '1111', 'D');
        if ($supplierVisualPath) image_fit_box($pdf, $supplierVisualPath, $contentX + 0.8, $cursorY + 1.2, $imageBoxW - 1.6, $imageBoxH - 3.6);
        if ($supplierCniPath) image_fit_box($pdf, $supplierCniPath, $contentX + $imageBoxW + $boxGap + 0.8, $cursorY + 1.2, $imageBoxW - 1.6, $imageBoxH - 3.6);
        $pdf->SetFont('dejavusans', '', 6.8);
        $pdf->SetXY($contentX, $cursorY + $imageBoxH + 0.8);
        $pdf->Cell($imageBoxW, 3.5, $supplierVisualLabel, 0, 0, 'C');
        $pdf->Cell($boxGap, 3.5, '', 0, 0);
        $pdf->Cell($imageBoxW, 3.5, 'Pièce identité fournisseur', 0, 1, 'C');
        $cursorY += $imageBoxH + 7;
    }

    $summaryRows = [
        'Statut de la fiche : ' . $statusText,
        'Nombre de fiches liées : ' . $fichesLieesStats['count'],
        'Total décaissé : ' . format_money($fichesLieesStats['total_decaisse']),
        'Total refusé : ' . format_money($fichesLieesStats['total_refuse']),
    ];
    foreach ($summaryRows as $line) {
        $pdf->SetXY($contentX, $cursorY);
        $pdf->SetFont('dejavusans', '', 8.2);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->MultiCell($contentW, max(4.8, $pdf->getStringHeight($contentW, $line)), $line, 0, 'L', false, 1);
        $cursorY = $pdf->GetY() + 1;
    }
    $pdf->SetXY($contentX, $cursorY + 1);
    $pdf->SetFont('dejavusans', 'B', 8.5);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(44, 4.5, 'Historique récent :', 0, 1, 'L');
    $cursorY = $pdf->GetY();
    if ($historique) {
        foreach (array_slice($historique, 0, 3) as $event) {
            $pdf->SetXY($contentX, $cursorY);
            $pdf->SetFont('dejavusans', '', 7.4);
            $pdf->SetTextColor(31, 41, 55);
            $pdf->MultiCell($contentW, max(4.4, $pdf->getStringHeight($contentW, format_history_event_line($event))), format_history_event_line($event), 0, 'L', false, 1);
            $cursorY = $pdf->GetY() + 0.8;
        }
    } else {
        $pdf->SetXY($contentX, $cursorY);
        $pdf->SetFont('dejavusans', '', 7.4);
        $pdf->SetTextColor(100, 116, 139);
        $pdf->Cell($contentW, 4.5, 'Aucun historique disponible.', 0, 1, 'L');
        $cursorY = $pdf->GetY() + 0.8;
    }
    $sectionEndY = $cursorY + 3.2;
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($sectionX, $sectionStartY, $sectionW, $sectionEndY - $sectionStartY, 3, '1111', 'D');
    $pdf->SetY($sectionEndY + 4);

    $metaRows = [
        ['Date création', format_date_fr($fiche['date_creat_fiche'] ?? null)],
        ['Date décaissement', format_date_fr($fiche['date_decaisse'] ?? null)],
        ['Date validation', format_date_fr($fiche['date_valid'] ?? null)],
        ['Téléphone', safe_text($fiche['tel_beneficiaire_fiche'] ?? '')],
        ['Mode paiement', safe_text($fiche['mode_paiement'] ?? '')],
    ];
    if (!empty($supplierContext['supplier_name'])) {
        $metaRows[] = ['Fournisseur', shorten_text((string) ($supplierContext['supplier_name'] ?? ''), 90)];
        $metaRows[] = ['Contact fournisseur', shorten_text((string) ($supplierContext['supplier_contact'] ?? ''), 90)];
        $metaRows[] = ['Complétude', safe_text($supplierContext['supplier_document_completion_label'] ?? '—') . ' (' . (int) ($supplierContext['supplier_document_completion_percent'] ?? 0) . '%)'];
    }
    $metaHeight = 14;
    foreach ($metaRows as [, $value]) {
        $metaHeight += 6 + $pdf->getStringHeight($contentW, $value);
    }
    ensure_pdf_space($pdf, $metaHeight);
    $sectionStartY = $pdf->GetY();
    $cursorY = $sectionStartY + 4;
    $pdf->SetXY($contentX, $cursorY);
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(80, 5.5, 'Repères documentaires', 0, 1, 'L');
    $cursorY += 8;
    foreach ($metaRows as [$label, $value]) {
        $height = draw_compact_info_row($pdf, $contentX, $cursorY, $contentW, $label, $value);
        $cursorY += $height + 1.4;
    }
    $documentSummary = 'QR code intégré pour consultation immédiate de la fiche détaillée depuis le portail.';
    if (!empty($supplierContext['supplier_name'])) {
        $documentSummary = 'Pièces fournisseur clés : Assurance RC ' . ($hasLiabilityInsurance ? 'fournie' : 'non fournie') . ' ; RIB ' . ($hasRib ? 'fourni' : 'non fourni') . '. QR code intégré pour consultation immédiate de la fiche détaillée depuis le portail.';
    }
    $pdf->SetXY($contentX, $cursorY + 1);
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell($contentW, max(8, $pdf->getStringHeight($contentW, $documentSummary)), $documentSummary, 0, 'L', false, 1);
    $sectionEndY = $pdf->GetY() + 4;
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($sectionX, $sectionStartY, $sectionW, $sectionEndY - $sectionStartY, 3, '1111', 'D');
    $pdf->SetY($sectionEndY + 4);

    if (!empty($supplierContext['supplier_name'])) {
        $availableDocumentLabels = array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $supplierDocuments);
        $documentStatusRows = [];
        foreach ($supplierRequiredDocuments as $label) {
            $documentStatusRows[] = [
                'label' => (string) $label,
                'status' => in_array((string) $label, $availableDocumentLabels, true) ? 'Fournie' : 'Manquante',
            ];
        }
        foreach ($supplierDocuments as $document) {
            $label = (string) ($document['label'] ?? '');
            if ($label !== '' && !in_array($label, $supplierRequiredDocuments, true)) {
                $documentStatusRows[] = ['label' => $label, 'status' => 'Disponible'];
            }
        }

        $documentsHeight = 16;
        foreach ($documentStatusRows as $row) {
            $documentsHeight += max(5.5, $pdf->getStringHeight($contentW - 30, (string) $row['label'])) + 1.6;
        }
        ensure_pdf_space($pdf, max(28, $documentsHeight));
        $sectionStartY = $pdf->GetY();
        $cursorY = $sectionStartY + 4;
        $pdf->SetXY($contentX, $cursorY);
        $pdf->SetFont('dejavusans', 'B', 11);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(94, 5.5, 'Pièces fournisseur détaillées', 0, 1, 'L');
        $cursorY += 8;
        if (!$documentStatusRows) {
            $pdf->SetXY($contentX, $cursorY);
            $pdf->SetFont('dejavusans', '', 8.2);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell($contentW, 4.8, 'Aucune pièce fournisseur disponible.', 0, 1, 'L');
            $cursorY = $pdf->GetY() + 1;
        } else {
            foreach ($documentStatusRows as $row) {
                $label = (string) ($row['label'] ?? '');
                $status = (string) ($row['status'] ?? '');
                $pdf->SetXY($contentX, $cursorY);
                $pdf->SetFont('dejavusans', 'B', 8.2);
                $pdf->SetTextColor(55, 65, 81);
                $pdf->Cell(28, 5, $status . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', '', 8.2);
                $pdf->SetTextColor(31, 41, 55);
                $lineHeight = max(5.0, $pdf->getStringHeight($contentW - 28, $label));
                $pdf->MultiCell($contentW - 28, $lineHeight, $label, 0, 'L', false, 1);
                $cursorY = $pdf->GetY() + 1.2;
            }
        }
        if ($supplierMissingDocuments) {
            $missingText = 'Pièces manquantes : ' . implode(', ', $supplierMissingDocuments);
            $pdf->SetXY($contentX, $cursorY);
            $pdf->SetFont('dejavusans', '', 7.4);
            $pdf->SetTextColor(180, 83, 9);
            $pdf->MultiCell($contentW, max(7, $pdf->getStringHeight($contentW, $missingText)), $missingText, 0, 'L', false, 1);
            $cursorY = $pdf->GetY() + 1;
        }
        $sectionEndY = $cursorY + 3;
        $pdf->SetDrawColor(226, 232, 240);
        $pdf->RoundedRect($sectionX, $sectionStartY, $sectionW, $sectionEndY - $sectionStartY, 3, '1111', 'D');
        $pdf->SetY($sectionEndY + 4);
    }

    $fileName = 'fiche_decaissement_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $numFiche) . '.pdf';
    \AuditTrail::logFileEvent('export', [
        'module' => 'decaissement',
        'action' => 'fiche_decaissement_export',
        'entity_type' => 'fiche_decaissement',
        'entity_id' => (string)($fiche['id'] ?? $fiche['id_fiche'] ?? $numFiche),
        'entity_label' => (string)($fiche['num_fiche'] ?? $numFiche),
        'format' => 'pdf',
        'details' => [
            'montant' => $fiche['montant_fiche'] ?? null,
            'beneficiaire' => $fiche['beficiaire_fiche'] ?? null,
            'status' => $statusText,
        ],
    ], $pdo);
    $pdf->Output($fileName, 'I');
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Erreur export PDF : ' . $e->getMessage();
}
