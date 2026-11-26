<?php
declare(strict_types=1);


require_once __DIR__.'/../../model/CompanyExportBranding.php';
require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/Database.php';
require_once __DIR__ . '/../../model/CompanyPdfTemplate.php';
require_once __DIR__ . '/../../services/DailyDisbursementPointService.php';
require_once __DIR__ . '/../../vendor/autoload.php';

if (!(AccessManager::hasPermission('acces_tresorerie')
    || AccessManager::hasPermission('acces_facturation')
    || AccessManager::hasPermission('acces_direction')
    || AccessManager::hasPermission('acces_admin'))) {
    http_response_code(403);
    exit('Accès refusé.');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Sortie manuelle invalide.');
}

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT m.*,c.code chantier_code,c.titre chantier_title,ld.designation ligne_devis_label,
    TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) created_by_name
    FROM treasury_manual_movements m
    LEFT JOIN chantier_banamur c ON c.id=m.chantier_id
    LEFT JOIN ligne_devis_banamur ld ON ld.id=m.ligne_devis_id
    LEFT JOIN user_devis_banamur u ON u.id=m.created_by_user_id
    WHERE m.id=:id AND m.direction='out' AND m.cancelled_at IS NULL LIMIT 1");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Cette sortie manuelle est introuvable.');
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$cashierUserId = (int)($row['created_by_user_id'] ?? 0);
$exporter = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')))
    ?: trim((string)($_SESSION['user_name'] ?? 'Utilisateur FINEA'));
$cashierName = trim((string)($row['created_by_name'] ?? '')) ?: 'Utilisateur ayant enregistré la sortie';
$documentId = 'FIN-SC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT) . '-' . date('YmdHis');
$money = static fn($value): string => number_format((float)$value, 0, ',', ' ') . ' FCFA';
$text = static fn($value, string $fallback = 'Non renseigné'): string => trim((string)$value) !== '' ? trim((string)$value) : $fallback;
$date = static fn($value): string => ($value && strtotime((string)$value)) ? date('d/m/Y', strtotime((string)$value)) : 'Non renseignée';
$assignment = trim(implode(' · ', array_filter([
    (string)($row['chantier_code'] ?? ''),
    (string)($row['chantier_title'] ?? ''),
    (string)($row['ligne_devis_label'] ?? ''),
]))) ?: $text($row['assignment_label'] ?? null, 'Sans affectation spécifique');

class ManualCashOutPdf extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void { $this->drawCompanyPdfHeader(); }
    public function Footer(): void { $this->drawCompanyPdfFooter(); }
}

function cashField(TCPDF $pdf, float $x, float $y, float $w, string $label, string $value, float $height = 18): void
{
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->RoundedRect($x, $y, $w, $height, 2, '1111', 'DF');
    $pdf->SetXY($x + 3, $y + 2.5);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($w - 6, 4, mb_strtoupper($label), 0, 1);
    $pdf->SetXY($x + 3, $y + 7);
    $pdf->SetFont('dejavusans', 'B', 9);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->MultiCell($w - 6, $height - 9, $value, 0, 'L', false, 1, '', '', true, 0, false, true, $height - 9, 'M');
}

$pdf = new ManualCashOutPdf('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->companyPdfTitle = 'Fiche de sortie de caisse';
$pdf->companyPdfType = 'Justificatif de sortie manuelle';
$pdf->companyPdfId = $documentId;
$pdf->companyPdfCode = 'FIN-TRE-SC-01';
$pdf->companyPdfQrPayload = json_encode([
    'document' => $documentId,
    'type' => 'sortie_caisse_manuelle',
    'movement_id' => (int)$row['id'],
    'amount' => (float)$row['amount'],
    'date' => (string)$row['movement_date'],
    'generated_at' => date(DATE_ATOM),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pdf->SetCreator(CompanyExportBranding::creator());
$pdf->SetAuthor($exporter);
$pdf->SetTitle('Fiche de sortie de caisse ' . $documentId);
$pdf->SetMargins(14, 38, 14);
$pdf->SetAutoPageBreak(true, 26);
$pdf->AddPage();

cashField($pdf, 14, 41, 56, 'Date de sortie', $date($row['movement_date'] ?? null));
cashField($pdf, 74, 41, 58, 'Référence', $text($row['reference'] ?? null, 'SC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT)));
cashField($pdf, 136, 41, 60, 'Montant décaissé', $money($row['amount']));
cashField($pdf, 14, 63, 182, 'Objet de la sortie', $text($row['label'] ?? null), 20);
cashField($pdf, 14, 87, 182, 'Affectation', $assignment, 22);
cashField($pdf, 14, 113, 182, 'Motif et observations', $text($row['note'] ?? null), 30);

$pdf->SetXY(14, 149);
$pdf->SetFillColor(238, 242, 255);
$pdf->SetDrawColor(199, 210, 254);
$pdf->SetTextColor(49, 46, 129);
$pdf->SetFont('dejavusans', '', 8);
$pdf->MultiCell(182, 12, 'Nature : sortie de caisse ' . (($row['status'] ?? '') === 'real' ? 'réelle' : 'prévisionnelle') . ' · Enregistrée par : ' . $text($row['created_by_name'] ?? null), 1, 'L', true, 1, '', '', true, 0, false, true, 12, 'M');

$signatureService = new DailyDisbursementPointService($pdo);
$signatureSource = static function (int $ownerId) use ($signatureService): string {
    if ($ownerId <= 0) return '';
    $path = $signatureService->profileSignature($ownerId);
    $absolute = $path !== '' ? realpath(dirname(__DIR__, 2) . '/' . ltrim($path, '/')) : false;
    if ($absolute && is_file($absolute) && is_readable($absolute)) return $absolute;
    $data = $signatureService->profileSignatureData($ownerId);
    if (preg_match('#^data:image/[^;]+;base64,(.+)$#s', $data, $match)) {
        $binary = base64_decode($match[1], true);
        if (is_string($binary) && $binary !== '') return '@' . $binary;
    }
    return '';
};
$exporterSignature = $signatureSource($userId);
$cashierSignature = $signatureSource($cashierUserId);
$boxes = [
    ['ÉTABLI / EXPORTÉ PAR', $exporter . "\n" . date('d/m/Y H:i'), $exporterSignature],
    ['CAISSE / DÉCAISSEMENT', $cashierName . "\nEnregistré le " . date('d/m/Y H:i', strtotime((string)($row['created_at'] ?? 'now'))) . ($cashierSignature === '' ? "\nSignature non enregistrée au profil" : ''), $cashierSignature],
    ['BÉNÉFICIAIRE / RÉCEPTION', 'Nom, date et signature', ''],
];
$x = 14.0;
foreach ($boxes as [$label, $name, $boxSignature]) {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetDrawColor(148, 163, 184);
    $pdf->RoundedRect($x, 169, 58, 47, 2, '1111', 'DF');
    $pdf->SetXY($x + 2, 173);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(54, 4, $label, 0, 1, 'C');
    $pdf->SetXY($x + 2, 179);
    $pdf->SetFont('dejavusans', '', 7);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->MultiCell(54, 8, $name, 0, 'C', false, 1, '', '', true, 0, false, true, 8, 'M');
    if ($boxSignature !== '') {
        $pdf->Image($boxSignature, $x + 16, 189, 26, 14, '', '', '', false, 300);
    } else {
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Line($x + 8, 207, $x + 50, 207);
    }
    $x += 62;
}

$pdf->SetXY(14, 224);
$pdf->SetFont('dejavusans', 'I', 7);
$pdf->SetTextColor(100, 116, 139);
$pdf->MultiCell(182, 10, 'La présente fiche matérialise une sortie manuelle enregistrée dans la trésorerie. La signature du bénéficiaire atteste la réception effective des fonds.', 0, 'L');
$pdf->Output('fiche_sortie_caisse_' . $documentId . '.pdf', 'I');
