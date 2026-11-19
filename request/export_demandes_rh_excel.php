<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/CompanyBranding.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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
        if (count($items) >= 6) {
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
        if (mb_strlen($value) > 120) {
            $value = mb_substr($value, 0, 117) . '...';
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
$branding=CompanyBranding::get($pdo);
$brandHex=static function(string $key,string $fallback)use($branding):string{$value=strtoupper(trim((string)($branding[$key]??$fallback)));return preg_match('/^#[0-9A-F]{6}$/',$value)?substr($value,1):substr($fallback,1);};
$primaryColor=$brandHex('primary_color','#050505');$accentColor=$brandHex('accent_color','#FFCC20');
$exportCounts=['submitted'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0];foreach($rows as $countRow){$countKey=strtolower((string)($countRow['status']??''));if($countKey==='details_requested')$countKey='submitted';if(isset($exportCounts[$countKey]))$exportCounts[$countKey]++;}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator((string)($branding['company_name']??'Finéa'))->setTitle('Registre des demandes RH')->setSubject('Document maîtrisé ISO 9001')->setDescription('RH-DEM-REG-01');
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Demandes RH');

$metaTitle = 'REGISTRE DES DEMANDES RH · ISO 9001 · RH-DEM-REG-01';
$sheet->setCellValue('A1', $metaTitle);
$sheet->mergeCells('A1:J1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
$sheet->getStyle('A1:J1')->getFill()->setFillType('solid')->getStartColor()->setRGB($primaryColor);
$sheet->getStyle('A1:J1')->getFont()->getColor()->setRGB('FFFFFF');

$sheet->setCellValue('A2', 'Généré le');
$sheet->setCellValue('B2', date('d/m/Y H:i'));
$sheet->setCellValue('A3', 'Statuts');
$sheet->setCellValue('B3', implode(', ', $statuses));
$sheet->setCellValue('A4', 'Type');
$sheet->setCellValue('B4', $type !== '' ? (string) (($manager->getTypeDefinition($type)['label'] ?? '') ?: $type) : 'Tous');
$sheet->setCellValue('A5', 'Total');
$sheet->setCellValue('B5', count($rows));
$sheet->setCellValue('D2','Entreprise');$sheet->setCellValue('E2',(string)($branding['company_name']??''));
$sheet->setCellValue('D3','Période');$sheet->setCellValue('E3',($dateFrom?:'…').' au '.($dateTo?:'…'));
$sheet->setCellValue('D4','Demandeur');$sheet->setCellValue('E4',$requester?:'Tous');
$sheet->setCellValue('D5','Recherche');$sheet->setCellValue('E5',$query?:'Aucune');
$sheet->setCellValue('G2','En attente');$sheet->setCellValue('H2',$exportCounts['submitted']);
$sheet->setCellValue('G3','Approuvées');$sheet->setCellValue('H3',$exportCounts['approved']);
$sheet->setCellValue('G4','Refusées');$sheet->setCellValue('H4',$exportCounts['rejected']);
$sheet->setCellValue('G5','Annulées');$sheet->setCellValue('H5',$exportCounts['cancelled']);

$headerRow = 8;
$headers = [
    'ID',
    'Demandeur',
    'Email',
    'Type',
    'Titre',
    'Statut',
    'Soumise le',
    'Résumé',
    'Matricule',
    'Payload JSON',
];

$col = 'A';
foreach ($headers as $h) {
    $sheet->setCellValue($col . $headerRow, $h);
    $sheet->getStyle($col . $headerRow)->getFont()->setBold(true);
    $col++;
}
$sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getFill()->setFillType('solid')->getStartColor()->setRGB($accentColor);
$sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getFont()->getColor()->setRGB('000000');
$sheet->getStyle('A'.$headerRow.':J'.$headerRow)->getBorders()->getAllBorders()->getColor()->setRGB($primaryColor);

$rowIdx = $headerRow + 1;
foreach ($rows as $row) {
    $payload = (array) ($row['payload'] ?? []);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        $payloadJson = '';
    }

    $sheet->setCellValue('A' . $rowIdx, (int) ($row['id'] ?? 0));
    $sheet->setCellValue('B' . $rowIdx, (string) ($row['requester_name'] ?? ''));
    $sheet->setCellValue('C' . $rowIdx, (string) ($row['requester_email'] ?? ''));
    $sheet->setCellValue('D' . $rowIdx, (string) ($row['type_label'] ?? $row['request_type'] ?? ''));
    $sheet->setCellValue('E' . $rowIdx, (string) ($row['title'] ?? ''));
    $sheet->setCellValue('F' . $rowIdx, request_status_label($row));
    $sheet->setCellValue('G' . $rowIdx, (string) ($row['submitted_at'] ?? ''));
    $sheet->setCellValue('H' . $rowIdx, format_payload_resume($row));
    $sheet->setCellValue('I' . $rowIdx, (string) ($payload['matricule'] ?? ''));
    $sheet->setCellValue('J' . $rowIdx, $payloadJson);

    $rowIdx++;
}

foreach (range('A', 'J') as $c) {
    $sheet->getColumnDimension($c)->setAutoSize(true);
}

$sheet->freezePane('A' . ($headerRow + 1));

$filename = 'demandes_rh_' . date('Ymd_His') . '.xlsx';
\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_demandes_rh_excel',
    'entity_type' => 'employee_legal_request_list',
    'entity_label' => $filename,
    'format' => 'xlsx',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'type' => $type !== '' ? $type : null,
        'statuses' => $statuses,
        'limit' => $limit,
        'row_count' => count($rows),
    ],
], $pdo);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;

