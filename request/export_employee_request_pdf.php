<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

class PDFEmployeeRequest extends \TCPDF
{
    public string $companyName = 'BANAMUR';
    public string $documentTitle = 'Fiche de demande';
    public string $logoPath = '';

    public function Header(): void
    {
        $this->SetFillColor(255, 255, 255);
        $this->Rect(0, 0, $this->getPageWidth(), 28, 'F');
        $this->SetDrawColor(203, 213, 225);
        $this->Line(12, 24.5, $this->getPageWidth() - 12, 24.5);
        $this->SetFillColor(250, 189, 2);
        $this->Rect(12, 24.5, 34, 1.1, 'F');

        if ($this->logoPath !== '' && is_file($this->logoPath)) {
            $this->Image($this->logoPath, 12, 5.8, 15, 0, '', '', '', false, 300);
        }

        $this->SetXY(31, 7.4);
        $this->SetFont('dejavusans', 'B', 15.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 5.8, $this->companyName, 0, 1, 'L');
        $this->SetX(31);
        $this->SetFont('dejavusans', '', 8.5);
        $this->SetTextColor(71, 85, 105);
        $this->Cell(0, 4.4, 'Direction administrative - Document officiel', 0, 1, 'L');

        $this->SetXY($this->getPageWidth() - 80, 8.4);
        $this->SetFont('dejavusans', 'B', 8.6);
        $this->SetTextColor(51, 65, 85);
        $this->Cell(68, 4.5, $this->documentTitle, 0, 1, 'R');
        $this->SetX($this->getPageWidth() - 80);
        $this->SetFont('dejavusans', '', 7.6);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(68, 4.3, 'Version consolidee du dossier', 0, 1, 'R');
    }

    public function Footer(): void
    {
        $this->SetY(-16);
        $this->SetDrawColor(214, 219, 228);
        $this->Line(12, $this->GetY(), $this->getPageWidth() - 12, $this->GetY());
        $this->Ln(2.5);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, 'BANAMUR - Dossier employe securise - Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

if (!function_exists('app_public_base')) {
    function app_public_base(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}

function format_date_fr(?string $value): string
{
    if (!$value) {
        return '—';
    }
    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string) $value;
    }
}

function format_fcfa(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '0 FCFA';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function safe_text($value): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : '—';
}

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userEmail = (string) ($_SESSION['mail_pro'] ?? '');
$personnelProfile = employeeFindPersonnelProfile($pdo, $userEmail);
$actorMatricule = strtoupper(trim((string) ($personnelProfile['matricule'] ?? '')));

$requestId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0) {
    http_response_code(400);
    echo 'ID de demande invalide';
    exit;
}

$row = $manager->getRequestById($requestId);
if (!is_array($row) || !$row) {
    http_response_code(404);
    echo 'Demande introuvable';
    exit;
}

// Vérifier les permissions: propriétaire ou FEB matricule
$isOwner = $userId > 0 && (int) ($row['requester_user_id'] ?? 0) === $userId;
$isOwnerByMatricule = false;
if (!$isOwner && $actorMatricule !== '' && ($row['request_type'] ?? '') === 'feb') {
    $payloadJson = (string) ($row['payload_json'] ?? '');
    $needle = '"matricule":"' . str_replace('"', '\\"', $actorMatricule) . '"';
    $isOwnerByMatricule = $payloadJson !== '' && strpos($payloadJson, $needle) !== false;
}

if (!$isOwner && !$isOwnerByMatricule) {
    http_response_code(403);
    echo 'Accès refusé';
    exit;
}

$requestType = (string) ($row['request_type'] ?? '');
$payload = (array) ($row['payload'] ?? []);
$status = strtolower(trim((string) ($row['status'] ?? '')));

// Déterminer le titre et le type de fiche
$typeLabelMap = [
    'permission_absence' => 'Permission/Absence',
    'conge' => 'Demande de conges',
    'retard' => 'Declaration de retard',
    'heures_supp' => 'Heures supplémentaires',
    'avance_salaire' => 'Avance sur salaire',
    'reclamation_salariale' => 'Réclamation salariale',
    'pret' => 'Prêt',
    'feb' => 'FEB',
];
$typeLabel = $typeLabelMap[$requestType] ?? $requestType;
$statusLabel = match ($status) {
    'submitted' => 'En attente',
    'approved' => 'Approuvée',
    'rejected' => 'Refusée',
    'cancelled' => 'Annulée',
    default => ucfirst($status),
};

// Créer le PDF
$pdf = new PDFEmployeeRequest('P', 'mm', 'A4');
$pdf->SetCreator('Banamur');
$pdf->SetAuthor('Banamur');
$pdf->SetTitle('Fiche de demande #' . $requestId);
$pdf->SetSubject('Fiche de demande');
$pdf->SetMargins(12, 30, 12);
$pdf->SetAutoPageBreak(true, 20);
$pdf->companyName = 'BANAMUR';
$pdf->documentTitle = 'Fiche de demande #' . $requestId;
$pdf->logoPath = __DIR__ . '/../logo/default_logo.jpg';
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 24;

// Hero section
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect(12, 33, $pageWidth, 30, 4, '1111', 'DF');
$pdf->SetFillColor(250, 189, 2);
$pdf->RoundedRect(12, 33, 4, 30, 4, '1101', 'F');
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetXY(19, 37);
$pdf->Cell(120, 7, 'Fiche de demande', 0, 1, 'L');

$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetX(19);
$pdf->Cell(140, 5, 'Référence #' . $requestId . ' — ' . $typeLabel, 0, 1, 'L');

// Status badge
$statusColors = [
    'submitted' => [217, 119, 6],   // Amber
    'approved' => [34, 197, 94],    // Green
    'rejected' => [239, 68, 68],    // Red
    'cancelled' => [107, 114, 128], // Gray
];
$statusColor = $statusColors[$status] ?? [100, 116, 139];

$pdf->SetFont('dejavusans', 'B', 9.5);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetXY(19, 54);
$pdf->SetFillColor($statusColor[0], $statusColor[1], $statusColor[2]);
$pdf->RoundedRect(19, 54, 46, 7, 2, '1111', 'F');
$pdf->Cell(46, 7, $statusLabel, 0, 1, 'C');

// Reference and date
$pdf->SetFont('dejavusans', '', 8.5);
$pdf->SetTextColor(71, 85, 105);
$pdf->SetXY(72, 55);
$pdf->Cell(120, 4, 'Export le ' . date('d/m/Y à H:i'), 0, 1, 'L');

$qrPayload = implode("\n", [
    'BANAMUR - Fiche de demande',
    'Reference: REQ-' . $requestId,
    'Type: ' . $typeLabel,
    'Statut: ' . $statusLabel,
    'Genere le: ' . date('d/m/Y H:i'),
    app_public_base() . '/request/export_employee_request_pdf.php?id=' . $requestId,
]);
$pdf->SetFillColor(255, 255, 255);
$pdf->SetDrawColor(226, 232, 240);
$pdf->RoundedRect(166, 33, 32, 30, 4, '1111', 'DF');
$pdf->SetXY(169, 35.4);
$pdf->SetFont('dejavusans', 'B', 7.2);
$pdf->SetTextColor(71, 85, 105);
$pdf->Cell(26, 4, 'QR DOSSIER', 0, 1, 'C');
$pdf->write2DBarcode($qrPayload, 'QRCODE,H', 172, 40.2, 19, 19, [], 'N');

$pdf->SetY(70);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Informations', 0, 1, 'L');
$pdf->Ln(1);

// Draw info rows
$leftCol = 12;
$rightCol = 107;
$colWidth = 40;
$y = $pdf->GetY();

// Row 1: Date soumise et Statut
$pdf->SetXY($leftCol, $y);
$pdf->SetFont('dejavusans', 'B', 8.3);
$pdf->SetTextColor(55, 65, 81);
$pdf->Cell($colWidth, 4, 'Date de soumission', 0, 0, 'L');
$pdf->SetXY($rightCol, $y);
$pdf->SetFont('dejavusans', 'B', 8.3);
$pdf->Cell($colWidth, 4, 'Type', 0, 1, 'L');

$pdf->SetXY($leftCol, $y + 4);
$pdf->SetFont('dejavusans', '', 8.3);
$pdf->SetTextColor(31, 41, 55);
$pdf->Cell($colWidth, 4.5, format_date_fr((string) ($row['submitted_at'] ?? '')), 0, 0, 'L');
$pdf->SetXY($rightCol, $y + 4);
$pdf->SetFont('dejavusans', '', 8.3);
$pdf->Cell($colWidth, 4.5, $typeLabel, 0, 1, 'L');

$y = $pdf->GetY() + 3;

// Section: Détails de la demande
$pdf->SetY($y);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Détails', 0, 1, 'L');
$pdf->Ln(1);

$y = $pdf->GetY();
$pdf->SetXY($leftCol, $y);

// Ajouter les détails selon le type de demande
if ($requestType === 'permission_absence') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employé', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Date de départ', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Date de retour', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, format_date_fr((string) ($payload['date_depart'] ?? '')), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, format_date_fr((string) ($payload['date_retour'] ?? '')), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Nombre de jours', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Service', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nombre_jours'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['service'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 4;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Motif', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $motifHeight = $pdf->getStringHeight(180, safe_text($payload['motif_demande'] ?? ''));
    $pdf->MultiCell(0, $motifHeight, safe_text($payload['motif_demande'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $motifHeight, 'M');
} elseif ($requestType === 'conge') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employe', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Date de debut', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Date de fin', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, format_date_fr((string) ($payload['date_debut'] ?? '')), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, format_date_fr((string) ($payload['date_fin'] ?? '')), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Nombre de jours', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Service', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nombre_jours'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['service'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 4;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Motif', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $motifHeight = $pdf->getStringHeight(180, safe_text($payload['motif'] ?? ''));
    $pdf->MultiCell(0, $motifHeight, safe_text($payload['motif'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $motifHeight, 'M');
} elseif ($requestType === 'retard') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employe', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Date du retard', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Heure d\'arrivee', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, format_date_fr((string) ($payload['date_retard'] ?? '')), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['heure_arrivee'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Duree', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Service', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['duree_retard'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['service'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 4;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Motif', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $motifHeight = $pdf->getStringHeight(180, safe_text($payload['motif'] ?? ''));
    $pdf->MultiCell(0, $motifHeight, safe_text($payload['motif'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $motifHeight, 'M');
} elseif ($requestType === 'heures_supp') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employé', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Nombre d\'heures', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Fonction', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['heures'] ?? '') . ' h', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['fonction'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    if (!empty($payload['date_effective'])) {
        $pdf->SetXY($leftCol, $y);
        $pdf->SetFont('dejavusans', 'B', 8.3);
        $pdf->SetTextColor(55, 65, 81);
        $pdf->Cell(0, 4, 'Date de prise effective', 0, 1, 'L');

        $pdf->SetXY($leftCol, $y + 4);
        $pdf->SetFont('dejavusans', '', 8.3);
        $pdf->SetTextColor(31, 41, 55);
        $pdf->Cell(0, 4.5, format_date_fr((string) ($payload['date_effective'] ?? '')), 0, 1, 'L');

        $y = $pdf->GetY() + 3;
    }

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Motif', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $motifHeight = $pdf->getStringHeight(180, safe_text($payload['motif'] ?? ''));
    $pdf->MultiCell(0, $motifHeight, safe_text($payload['motif'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $motifHeight, 'M');
} elseif ($requestType === 'avance_salaire') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employé', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Montant demandé', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Fonction', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, format_fcfa((float) ($payload['montant'] ?? 0)), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['fonction'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 4;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Motif', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $motifHeight = $pdf->getStringHeight(180, safe_text($payload['motif'] ?? ''));
    $pdf->MultiCell(0, $motifHeight, safe_text($payload['motif'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $motifHeight, 'M');
} elseif ($requestType === 'reclamation_salariale') {
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Employé', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Matricule', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['nom'] ?? '') . ' ' . safe_text($payload['prenoms'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['matricule'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell($colWidth, 4, 'Objet', 0, 0, 'L');
    $pdf->SetXY($rightCol, $y);
    $pdf->Cell($colWidth, 4, 'Mois concerné', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['objet_reclamation_label'] ?? ''), 0, 0, 'L');
    $pdf->SetXY($rightCol, $y + 4);
    $pdf->Cell($colWidth, 4.5, safe_text($payload['mois_concerne'] ?? ''), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Montant manquant', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $pdf->Cell(0, 4.5, format_fcfa((float) ($payload['montant_manquant'] ?? 0)), 0, 1, 'L');

    $y = $pdf->GetY() + 3;

    $pdf->SetXY($leftCol, $y);
    $pdf->SetFont('dejavusans', 'B', 8.3);
    $pdf->SetTextColor(55, 65, 81);
    $pdf->Cell(0, 4, 'Détails du différend', 0, 1, 'L');

    $pdf->SetXY($leftCol, $y + 4);
    $pdf->SetFont('dejavusans', '', 8.3);
    $pdf->SetTextColor(31, 41, 55);
    $detailsHeight = $pdf->getStringHeight(180, safe_text($payload['details_differend'] ?? ''));
    $pdf->MultiCell(0, $detailsHeight, safe_text($payload['details_differend'] ?? ''), 0, 'L', false, 1, '', '', true, 0, false, true, $detailsHeight, 'M');
}

// Footer avec informations supplémentaires
$pdf->AddPage();
$pdf->SetXY(12, 35);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Historique de la demande', 0, 1, 'L');
$pdf->Ln(1);

// Timeline
$pdf->SetFont('dejavusans', 'B', 8.3);
$pdf->SetTextColor(55, 65, 81);
$pdf->Cell(40, 4, 'Événement', 0, 0, 'L');
$pdf->Cell(70, 4, 'Date', 0, 0, 'L');
$pdf->Cell(50, 4, 'Détails', 0, 1, 'L');

$pdf->SetDrawColor(226, 232, 240);
$pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
$pdf->Ln(1);

// Events
$events = [];
if (!empty($row['submitted_at'])) {
    $events[] = ['Soumise', $row['submitted_at'], 'Demande créée'];
}
if (!empty($row['rh_approved_at'])) {
    $events[] = ['Approuvée RH', $row['rh_approved_at'], (string) ($row['rh_approved_by_name'] ?? '—')];
} elseif (!empty($row['rh_rejected_at'])) {
    $events[] = ['Refusée RH', $row['rh_rejected_at'], (string) ($row['rh_rejected_by_name'] ?? '—')];
}
if (!empty($row['drh_approved_at'])) {
    $events[] = ['Approuvée DRH', $row['drh_approved_at'], (string) ($row['drh_approved_by_name'] ?? '—')];
} elseif (!empty($row['drh_rejected_at'])) {
    $events[] = ['Refusée DRH', $row['drh_rejected_at'], (string) ($row['drh_rejected_by_name'] ?? '—')];
}
if (!empty($row['dg_approved_at'])) {
    $events[] = ['Approuvée DG', $row['dg_approved_at'], (string) ($row['dg_approved_by_name'] ?? '—')];
} elseif (!empty($row['dg_rejected_at'])) {
    $events[] = ['Refusée DG', $row['dg_rejected_at'], (string) ($row['dg_rejected_by_name'] ?? '—')];
}
if (!empty($row['cancelled_at'])) {
    $events[] = ['Annulée', $row['cancelled_at'], (string) ($row['cancelled_reason'] ?? '—')];
}

$pdf->SetFont('dejavusans', '', 8.3);
$pdf->SetTextColor(31, 41, 55);

foreach ($events as $event) {
    $pdf->Cell(40, 5, $event[0], 0, 0, 'L');
    $pdf->Cell(70, 5, format_date_fr($event[1]), 0, 0, 'L');
    $pdf->Cell(50, 5, $event[2], 0, 1, 'L');
}

// Output PDF
$filename = 'Demande_' . $requestId . '.pdf';
$pdf->Output($filename, 'I');
exit;
