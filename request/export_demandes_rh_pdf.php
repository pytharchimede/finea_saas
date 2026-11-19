<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/../model/CompanyPdfTemplate.php';

class PDFWowRhExport extends TCPDF
{
    use CompanyPdfTemplate;
    public function Header(): void {$this->drawCompanyPdfHeader();}
    public function Footer(): void {$this->drawCompanyPdfFooter();}
    public function removeGeneratorCredit(): void {$this->tcpdflink=false;}
}

function app_public_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function normalize_statuses($raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/[^a-z_]+/i', $raw);
    }
    if (!is_array($raw)) {
        return ['submitted'];
    }

    $allowed = ['submitted', 'details_requested', 'approved', 'rejected', 'cancelled'];
    $out = [];
    foreach ($raw as $v) {
        $v = strtolower(trim((string) $v));
        if ($v !== '' && in_array($v, $allowed, true)) {
            $out[] = $v;
        }
    }
    $out = array_values(array_unique($out));

    return $out ?: ['submitted'];
}

function request_status_label(array $row): string
{
    $status = strtolower(trim((string) ($row['status'] ?? '')));

    return match ($status) {
        'submitted' => 'En attente',
        'approved' => 'Approuvée',
        'rejected' => 'Refusée',
        'cancelled' => 'Annulée',
        default => $status !== '' ? $status : '—',
    };
}

function format_fcfa(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '—';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function format_payload_resume(array $row): string
{
    $type = (string) ($row['request_type'] ?? '');
    $payload = (array) ($row['payload'] ?? []);

    if ($type === 'permission_absence') {
        $depart = trim((string) ($payload['date_depart'] ?? ''));
        $retour = trim((string) ($payload['date_retour'] ?? ''));
        $jours = (int) ($payload['nombre_jours'] ?? 0);
        $items = [];
        if ($depart !== '') {
            $items[] = 'Départ: ' . $depart;
        }
        if ($retour !== '') {
            $items[] = 'Retour: ' . $retour;
        }
        if ($jours > 0) {
            $items[] = 'Jours: ' . $jours;
        }
        return implode(' | ', $items);
    }

    if ($type === 'conge') {
        $debut = trim((string) ($payload['date_debut'] ?? ''));
        $fin = trim((string) ($payload['date_fin'] ?? ''));
        $jours = (int) ($payload['nombre_jours'] ?? 0);
        $motif = trim((string) ($payload['motif'] ?? ''));
        $items = [];
        if ($debut !== '') {
            $items[] = 'Debut: ' . $debut;
        }
        if ($fin !== '') {
            $items[] = 'Fin: ' . $fin;
        }
        if ($jours > 0) {
            $items[] = 'Jours: ' . $jours;
        }
        if ($motif !== '') {
            $items[] = 'Motif: ' . $motif;
        }
        return implode(' | ', $items);
    }

    if ($type === 'retard') {
        $date = trim((string) ($payload['date_retard'] ?? ''));
        $heure = trim((string) ($payload['heure_arrivee'] ?? ''));
        $duree = trim((string) ($payload['duree_retard'] ?? ''));
        $motif = trim((string) ($payload['motif'] ?? ''));
        $items = [];
        if ($date !== '') {
            $items[] = 'Date: ' . $date;
        }
        if ($heure !== '') {
            $items[] = 'Heure: ' . $heure;
        }
        if ($duree !== '') {
            $items[] = 'Duree: ' . $duree;
        }
        if ($motif !== '') {
            $items[] = 'Motif: ' . $motif;
        }
        return implode(' | ', $items);
    }

    if ($type === 'pret') {
        $designation = trim((string) ($payload['designation_pret'] ?? ''));
        $montantDemande = (float) ($payload['montant_demande'] ?? 0);
        $recouvrement = (float) ($payload['montant_recouvrement'] ?? 0);
        $dateDebut = trim((string) ($payload['date_debut'] ?? ''));
        $dateFin = trim((string) ($payload['date_fin'] ?? ''));
        $mens = (int) ($payload['nombre_mensualites'] ?? 0);

        $items = [];
        if ($designation !== '') {
            $items[] = 'Désig.: ' . $designation;
        }
        if ($montantDemande > 0) {
            $items[] = 'Montant: ' . format_fcfa($montantDemande);
        }
        if ($recouvrement > 0) {
            $items[] = 'Recouv.: ' . format_fcfa($recouvrement);
        }
        if ($dateDebut !== '') {
            $items[] = 'Début: ' . $dateDebut;
        }
        if ($dateFin !== '') {
            $items[] = 'Fin: ' . $dateFin;
        }
        if ($mens > 0) {
            $items[] = 'Mens.: ' . $mens;
        }

        return implode(' | ', $items);
    }

    if ($type === 'avance_salaire') {
        $montant = (float) ($payload['montant'] ?? 0);
        $motif = trim((string) ($payload['motif'] ?? ''));
        $items = [];
        if ($montant > 0) {
            $items[] = 'Montant: ' . format_fcfa($montant);
        }
        if ($motif !== '') {
            $items[] = 'Motif: ' . $motif;
        }
        return implode(' | ', $items);
    }

    if ($type === 'heures_supp') {
        $heures = (int) ($payload['heures'] ?? 0);
        $motif = trim((string) ($payload['motif'] ?? ''));
        $items = [];
        if ($heures > 0) {
            $items[] = 'Heures: ' . $heures;
        }
        if ($motif !== '') {
            $items[] = 'Motif: ' . $motif;
        }
        return implode(' | ', $items);
    }

    if ($type === 'reclamation_salariale') {
        $claim = \EmployeeLegalRequestManager::extractSalaryClaimDisplay($row);
        $items = [];
        if (!empty($claim['objet_label'])) {
            $items[] = 'Objet: ' . (string) $claim['objet_label'];
        }
        if (!empty($claim['mois_label'])) {
            $items[] = 'Mois: ' . (string) $claim['mois_label'];
        }
        if (!empty($claim['montant_label']) && (string) $claim['montant_label'] !== '—') {
            $items[] = 'Montant: ' . (string) $claim['montant_label'];
        }
        if (!empty($claim['demandeur'])) {
            $items[] = 'Demandeur: ' . (string) $claim['demandeur'];
        }
        return implode(' | ', $items);
    }

    $items = [];
    foreach ($payload as $k => $v) {
        if (count($items) >= 4) {
            break;
        }
        $k = trim((string) $k);
        if ($k === '') {
            continue;
        }
        $value = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        if (mb_strlen($value) > 80) {
            $value = mb_substr($value, 0, 77) . '...';
        }
        $items[] = $k . ': ' . $value;
    }

    return implode(' | ', $items);
}

$pdo = \Database::getConnection();
$manager = new EmployeeLegalRequestManager($pdo);

$type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$type = $type !== '' ? $manager->normalizeType($type) : '';

$statuses = normalize_statuses($_GET['status'] ?? ($_GET['statuses'] ?? 'submitted'));
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 300;
$limit = max(1, min(500, $limit));

$rows = $manager->listRequestsForTeamAdvanced('rh', $statuses, $type !== '' ? $type : null, null, $limit);
$scope=(string)($_GET['scope'] ?? '');
if(in_array($scope,['financial','administrative'],true))$rows=array_values(array_filter($rows,static fn(array $row):bool => in_array($row['request_type'],['pret','avance_salaire','reclamation_salariale'],true)===($scope==='financial')));
$requester = trim((string)($_GET['requester'] ?? ''));
$query = trim((string)($_GET['q'] ?? ''));
$dateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$dateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
$rows = array_values(array_filter($rows, static function(array $row) use($requester,$query,$dateFrom,$dateTo):bool{
    if($requester!==''&&(string)($row['requester_name']??'')!==$requester)return false;
    $date=substr((string)($row['submitted_at']??''),0,10);
    if($dateFrom!==''&&($date===''||$date<$dateFrom))return false;
    if($dateTo!==''&&($date===''||$date>$dateTo))return false;
    if($query!==''){$haystack=mb_strtolower(implode(' ',[(string)($row['id']??''),(string)($row['requester_name']??''),(string)($row['type_label']??$row['request_type']??''),(string)($row['title']??''),(string)($row['status']??'')]),'UTF-8');if(!str_contains($haystack,mb_strtolower($query,'UTF-8')))return false;}
    return true;
}));

$countTotal = count($rows);
$countApproved = 0;
$countPending = 0;
$countRejected = 0;
$countCancelled = 0;
foreach ($rows as $row) {
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    if ($status === 'approved') {
        $countApproved++;
    } elseif (in_array($status, ['submitted', 'details_requested'], true)) {
        $countPending++;
    } elseif ($status === 'rejected') {
        $countRejected++;
    } elseif ($status === 'cancelled') {
        $countCancelled++;
    }
}

$financialTypes = ['pret', 'avance_salaire', 'reclamation_salariale'];
$showFinancialTotal = in_array($type, $financialTypes, true);
$financialTotal = 0.0;
if ($showFinancialTotal) {
    foreach ($rows as $financialRow) {
        $payload = is_array($financialRow['payload'] ?? null) ? (array) $financialRow['payload'] : [];
        $rawAmount = match ($type) {
            'pret' => $payload['montant_demande'] ?? 0,
            'avance_salaire' => $payload['montant'] ?? 0,
            'reclamation_salariale' => $payload['montant_manquant'] ?? 0,
            default => 0,
        };
        if (is_string($rawAmount)) {
            $rawAmount = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim($rawAmount));
        }
        $financialTotal += is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
    }
}
$financialTotalLabel = [
    'pret' => 'MONTANT TOTAL DES PRÊTS',
    'avance_salaire' => 'MONTANT TOTAL DES AVANCES SUR SALAIRE',
    'reclamation_salariale' => 'MONTANT TOTAL DES RÉCLAMATIONS SALARIALES',
][$type] ?? 'MONTANT TOTAL';

$branding = CompanyBranding::get($pdo);
$brandHex = static function(string $key,string $fallback) use($branding):string{$value=strtolower(trim((string)($branding[$key]??$fallback)));return preg_match('/^#[0-9a-f]{6}$/',$value)?$value:$fallback;};
$brandRgb = static fn(string $hex):array=>[hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2))];
$primaryHex=$brandHex('primary_color','#050505');$accentHex=$brandHex('accent_color','#ffcc20');
$primaryRgb=$brandRgb($primaryHex);$accentRgb=$brandRgb($accentHex);

$pdf = new PDFWowRhExport('P', 'mm', 'A4');
$pdf->SetCreator((string)($branding['company_name']??'Finéa'));
$pdf->SetAuthor((string)($branding['company_name']??'Finéa'));
$pdf->SetTitle('Demandes RH');
$pdf->SetMargins(10, 40, 10);$pdf->SetHeaderMargin(0);$pdf->SetFooterMargin(7);
$pdf->SetAutoPageBreak(true, 27);
$pdf->companyPdfTitle='Registre des demandes RH';
$pdf->companyPdfType='Registre RH';
$pdf->companyPdfId='REG-'.date('Ymd-His');
$pdf->companyPdfCode='RH-DEM-REG-01';
$pdf->companyPdfQrPayload=$pdf->companyPdfUrl('rh/validation_demandes_rh.php');
$pdf->removeGeneratorCredit();
$pdf->AddPage();

$pageWidth = $pdf->getPageWidth() - 20;

$actorName = trim((string) ($_SESSION['user_name'] ?? (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? ''))));
$actorName = trim($actorName);

$subtitleParts = [];
$subtitleParts[] = 'Ressources humaines';
if ($type !== '') {
    $def = $manager->getTypeDefinition($type);
    $subtitleParts[] = 'Type: ' . (string) (($def['label'] ?? '') ?: $type);
}
if ($statuses) {
    $subtitleParts[] = 'Statuts: ' . implode(', ', $statuses);
}
if($requester!=='')$subtitleParts[]='Demandeur: '.$requester;
if($dateFrom!==''||$dateTo!=='')$subtitleParts[]='Période: '.($dateFrom?:'…').' au '.($dateTo?:'…');
if($query!=='')$subtitleParts[]='Recherche: '.$query;
$subtitle = implode('  |  ', array_filter(array_map('trim', $subtitleParts), static fn($v) => $v !== ''));
$subtitle = mb_strimwidth($subtitle, 0, 115, '…', 'UTF-8');

// Context under the same institutional header used by the financial recap.
$pdf->SetFont('dejavusans','',8);
$pdf->SetTextColor(15,23,42);
$pdf->writeHTML('<p><b>Export du '.date('d/m/Y H:i').'</b> · '.htmlspecialchars($actorName,ENT_QUOTES,'UTF-8').'<br>'.htmlspecialchars($subtitle,ENT_QUOTES,'UTF-8').'</p>',true,false,true,false,'');

// KPI strip
$kpiY = $pdf->GetY()+2;
$kpiGap = 4;
$kpiWidth = ($pageWidth - ($kpiGap * 3)) / 4;
$kpis = [
    ['label' => 'Total', 'value' => (string) $countTotal, 'fill' => [239, 246, 255], 'text' => [29, 78, 216]],
    ['label' => 'En attente', 'value' => (string) $countPending, 'fill' => [255, 251, 235], 'text' => [146, 64, 14]],
    ['label' => 'Approuvées', 'value' => (string) $countApproved, 'fill' => [240, 253, 244], 'text' => [21, 128, 61]],
    ['label' => 'Refusées', 'value' => (string) $countRejected, 'fill' => [255, 241, 242], 'text' => [190, 18, 60]],
];
foreach ($kpis as $index => $kpi) {
    $x = 10 + (($kpiWidth + $kpiGap) * $index);
    $pdf->SetFillColor($kpi['fill'][0], $kpi['fill'][1], $kpi['fill'][2]);
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->RoundedRect($x, $kpiY, $kpiWidth, 16, 3, '1111', 'DF');
    $pdf->SetXY($x + 3, $kpiY + 2.5);
    $pdf->SetFont('dejavusans', '', 7.2);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell($kpiWidth - 6, 3.8, (string) $kpi['label'], 0, 2, 'L');
    $pdf->SetX($x + 3);
    $pdf->SetFont('dejavusans', 'B', 10.5);
    $pdf->SetTextColor($kpi['text'][0], $kpi['text'][1], $kpi['text'][2]);
    $pdf->Cell($kpiWidth - 6, 5.5, (string) $kpi['value'], 0, 0, 'L');
}

if ($showFinancialTotal) {
    $financialY = $kpiY+20;
    $pdf->SetFillColor(255, 251, 235);
    $pdf->SetDrawColor($accentRgb[0], $accentRgb[1], $accentRgb[2]);
    $pdf->RoundedRect(10, $financialY, $pageWidth, 15, 3, '1111', 'DF');
    $pdf->SetXY(14, $financialY + 2.3);
    $pdf->SetFont('dejavusans', 'B', 7.3);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(112, 4, $financialTotalLabel, 0, 0, 'L');
    $pdf->SetXY(126, $financialY + 2);
    $pdf->SetFont('dejavusans', 'B', 13);
    $pdf->SetTextColor($primaryRgb[0], $primaryRgb[1], $primaryRgb[2]);
    $pdf->Cell(70, 7, number_format($financialTotal, 0, ',', ' ') . ' FCFA', 0, 0, 'R');
    $pdf->SetXY(14, $financialY + 8.2);
    $pdf->SetFont('dejavusans', '', 6.7);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(150, 3.5, 'Total calculé sur les demandes et filtres du présent export.', 0, 0, 'L');
}

// Repositionner le curseur sous la bande KPI (sinon writeHTML() peut démarrer à droite et être coupé)
$pdf->SetXY(10, $showFinancialTotal ? $financialY+19 : $kpiY+21);
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 6, 'Listing', 0, 1, 'L');
$pdf->Ln(1);

$thead = [
    ['label' => 'Réf.', 'w' => '12%'],
    ['label' => 'Demandeur', 'w' => '16%'],
    ['label' => 'Type', 'w' => '14%'],
    ['label' => 'Titre / Détails', 'w' => '30%'],
    ['label' => 'Statut', 'w' => '12%'],
    ['label' => 'Soumise le', 'w' => '16%'],
];

$headerCells = '';
foreach ($thead as $c) {
$headerCells .= '<th style="width:' . $c['w'] . ';padding:6px 6px;font-weight:bold;background-color:' . $accentHex . ';color:#000000;border:1px solid ' . $primaryHex . ';font-size:9px;">' . htmlspecialchars((string) $c['label']) . '</th>';
}

$bodyRows = '';
foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $requester = (string) ($row['requester_name'] ?? '');
    $typeLabel = (string) ($row['type_label'] ?? $row['request_type'] ?? '');
    $title = (string) ($row['title'] ?? '');
    $status = request_status_label($row);
    $date = (string) ($row['submitted_at'] ?? '');

    $resume = format_payload_resume($row);
    $titleBlock = htmlspecialchars($title !== '' ? $title : '—');
    if (trim($resume) !== '') {
        $titleBlock .= '<br><span style="color:#64748b;font-size:8.2px;">' . htmlspecialchars($resume) . '</span>';
    }

    $cells = '';
    $cells .= '<td style="width:12%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.8px;font-weight:bold;">#' . $id . '</td>';
    $cells .= '<td style="width:16%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.8px;">' . htmlspecialchars($requester) . '</td>';
    $cells .= '<td style="width:14%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.8px;">' . htmlspecialchars($typeLabel) . '</td>';
    $cells .= '<td style="width:30%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.6px;">' . $titleBlock . '</td>';
    $cells .= '<td style="width:12%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.8px;">' . htmlspecialchars($status) . '</td>';
    $cells .= '<td style="width:16%;padding:6px 6px;border:1px solid #e2e8f0;font-size:8.8px;">' . htmlspecialchars($date) . '</td>';

    $bodyRows .= '<tr nobr="true">' . $cells . '</tr>';
}

if ($bodyRows === '') {
    $bodyRows = '<tr><td colspan="6" style="padding:10px;border:1px solid #e2e8f0;color:#64748b;">Aucune demande.</td></tr>';
}

$html = '
<table cellpadding="5" cellspacing="0" style="width:100%;border-collapse:collapse;table-layout:fixed;">'
    . '<thead><tr>' . $headerCells . '</tr></thead>'
    . '<tbody>' . $bodyRows . '</tbody>'
    . '</table>';

$pdf->SetFont('dejavusans', '', 9);
$pdf->SetTextColor(15, 23, 42);
$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'demandes_rh_' . date('Ymd_His') . '.pdf';
$pdf->Output($filename, 'D');


