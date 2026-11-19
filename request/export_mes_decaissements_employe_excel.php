<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../employe/includes/helpers.php';
require_once __DIR__ . '/../model/AuditTrail.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function parseDateYmd(?string $raw): string
{
    $raw = trim((string) $raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
}

function decaissementStatusKey(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'date_report' => ''];
    }

    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['key' => 'reportee', 'label' => 'Reportée', 'date_report' => $dateReport];
    }

    if ($approuve === 2 || $etat === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'date_report' => ''];
    }

    if ($otp === 1) {
        return ['key' => 'otp', 'label' => 'OTP validé', 'date_report' => ''];
    }

    if ($approuve === 1) {
        return ['key' => 'acceptee', 'label' => 'Acceptée', 'date_report' => ''];
    }

    if ($conforme === 1) {
        return ['key' => 'conforme', 'label' => 'Conforme', 'date_report' => ''];
    }

    return ['key' => 'attente', 'label' => 'En attente', 'date_report' => ''];
}

$todayYmd = date('Y-m-d');

$connectedName = employeeCurrentUserName();
$connectedNameLower = employeeLower($connectedName);
$connectedUserId = (int) ($_SESSION['user_id'] ?? 0);

$filterQuery = trim((string) ($_GET['q'] ?? ''));
$filterStatusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$filterDateFrom = parseDateYmd($_GET['date_from'] ?? null);
$filterDateTo = parseDateYmd($_GET['date_to'] ?? null);

$allowedStatuses = ['attente', 'acceptee', 'refusee', 'reportee', 'decaissee', 'conforme', 'otp'];
$filterStatus = in_array($filterStatusRaw, $allowedStatuses, true) ? $filterStatusRaw : '';

$pdo = \Database::getConnection();

$where = [];
$params = [];

$tokens = preg_split('/\s+/u', $connectedNameLower) ?: [];
$tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), static function (string $t): bool {
    return $t !== '' && (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) >= 3;
}));

$beneficiaryScope = [];
if (!$tokens) {
    $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE :benef_all';
    $params['benef_all'] = '%' . $connectedNameLower . '%';
} else {
    foreach ($tokens as $idx => $token) {
        $key = ':benef_t' . $idx;
        $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE ' . $key;
        $params[ltrim($key, ':')] = '%' . $token . '%';
    }
}
$scopeSql = '(' . implode(' AND ', $beneficiaryScope) . ')';
if ($connectedUserId > 0) {
    $scopeSql = '(' . $scopeSql . ' OR EXISTS (SELECT 1 FROM employee_legal_requests elr WHERE elr.id=f.feb_request_id AND elr.requester_user_id=:connected_user_id AND elr.request_type="feb"))';
    $params['connected_user_id'] = $connectedUserId;
}
$where[] = $scopeSql;

if ($filterDateFrom !== '') {
    $where[] = 'f.date_creat_fiche >= :date_from';
    $params['date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = 'f.date_creat_fiche <= :date_to';
    $params['date_to'] = $filterDateTo . ' 23:59:59';
}

if ($filterQuery !== '') {
    $where[] = '(
        f.num_fiche LIKE :q
        OR f.beficiaire_fiche LIKE :q
        OR f.designation_fiche LIKE :q
        OR f.precision_fiche LIKE :q
        OR f.details_demande LIKE :q
        OR f.mode_paiement LIKE :q
        OR f.num_piece LIKE :q
        OR f.entreprise LIKE :q
        OR f.code_autorisation_feb LIKE :q
        OR c.titre LIKE :q
        OR c.code LIKE :q
    )';
    $params['q'] = '%' . $filterQuery . '%';
}

$sql = '
    SELECT
        f.num_fiche,
        f.montant_fiche,
        f.date_creat_fiche,
        f.designation_fiche,
        f.precision_fiche,
        f.mode_paiement,
        f.approuve,
        f.conforme,
        f.decaisse,
        f.otp_valide,
        f.etat_fiche,
        f.date_decaissement_minimum,
        c.code AS chantier_code,
        c.titre AS chantier_titre
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY f.date_creat_fiche DESC
    LIMIT 2000
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$dbRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = [];
foreach ($dbRows as $r) {
    $status = decaissementStatusKey($r, $todayYmd);
    if ($filterStatus !== '' && ($status['key'] ?? '') !== $filterStatus) {
        continue;
    }
    $r['_status'] = $status;
    $rows[] = $r;
}

$xlsx = new Spreadsheet();
$sheet = $xlsx->getActiveSheet();
$sheet->setTitle('Mes décaissements');

$r = 1;
$sheet->setCellValue('A' . $r, 'Mes décaissements — Export');
$sheet->mergeCells("A{$r}:H{$r}");
$sheet->getStyle("A{$r}:H{$r}")->getFont()->setBold(true)->setSize(16);
$sheet->getStyle("A{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$r += 2;

$sheet->setCellValue('A' . $r, 'Bénéficiaire');
$sheet->setCellValue('B' . $r, $connectedName);
$r++;
$sheet->setCellValue('A' . $r, 'Généré le');
$sheet->setCellValue('B' . $r, date('d/m/Y H:i'));
$r++;
$sheet->setCellValue('A' . $r, 'Filtres');
$filtersText = trim(
    'q=' . $filterQuery
        . ' | status=' . $filterStatus
        . ' | du=' . $filterDateFrom
        . ' | au=' . $filterDateTo
);
$sheet->setCellValue('B' . $r, $filtersText);
$r += 2;

$headerRow = $r;
$headers = ['N° fiche', 'Date création', 'Chantier', 'Désignation', 'Précision', 'Mode paiement', 'Statut', 'Montant'];
foreach ($headers as $i => $h) {
    $col = chr(ord('A') + $i);
    $sheet->setCellValue($col . $r, $h);
}

$sheet->getStyle("A{$r}:H{$r}")->getFont()->setBold(true);
$sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2FF');
$sheet->getStyle("A{$r}:H{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
$r++;

foreach ($rows as $row) {
    $status = (array) ($row['_status'] ?? []);

    $sheet->setCellValue('A' . $r, (string) ($row['num_fiche'] ?? ''));
    $sheet->setCellValue('B' . $r, (string) ($row['date_creat_fiche'] ?? ''));
    $chantier = trim((string) (($row['chantier_code'] ?? '') . ' ' . ($row['chantier_titre'] ?? '')));
    $sheet->setCellValue('C' . $r, $chantier);
    $sheet->setCellValue('D' . $r, (string) ($row['designation_fiche'] ?? ''));
    $sheet->setCellValue('E' . $r, (string) ($row['precision_fiche'] ?? ''));
    $sheet->setCellValue('F' . $r, (string) ($row['mode_paiement'] ?? ''));

    $statusLabel = (string) ($status['label'] ?? '');
    $dateReport = (string) ($status['date_report'] ?? '');
    if (($status['key'] ?? '') === 'reportee' && $dateReport !== '') {
        $statusLabel .= ' (au ' . $dateReport . ')';
    }
    $sheet->setCellValue('G' . $r, $statusLabel);
    $sheet->setCellValue('H' . $r, (float) ($row['montant_fiche'] ?? 0));
    $r++;
}

$lastDataRow = $r - 1;
if ($lastDataRow >= $headerRow) {
    $sheet->getStyle("A{$headerRow}:H{$lastDataRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('E2E8F0');
    $sheet->getStyle("H" . ($headerRow + 1) . ":H{$lastDataRow}")->getNumberFormat()->setFormatCode('#,##0');
}

foreach (['A' => 14, 'B' => 20, 'C' => 30, 'D' => 32, 'E' => 28, 'F' => 16, 'G' => 16, 'H' => 14] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$fileName = 'mes_decaissements_' . date('Ymd_His') . '.xlsx';

\AuditTrail::logFileEvent('export', [
    'module' => 'request',
    'action' => 'export_mes_decaissements_employe_excel',
    'entity_type' => 'employee_decaissement_list',
    'entity_label' => $fileName,
    'format' => 'xlsx',
    'user_id' => $_SESSION['user_id'] ?? null,
    'user_name' => $_SESSION['user_name'] ?? $_SESSION['username'] ?? null,
    'details' => [
        'beneficiary' => $connectedName,
        'query' => $filterQuery !== '' ? $filterQuery : null,
        'status' => $filterStatus !== '' ? $filterStatus : null,
        'date_from' => $filterDateFrom !== '' ? $filterDateFrom : null,
        'date_to' => $filterDateTo !== '' ? $filterDateTo : null,
        'row_count' => count($rows),
    ],
], $pdo);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($xlsx);
$writer->save('php://output');
exit;
