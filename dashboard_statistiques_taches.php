<?php
ob_start();

require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess('dashboard_statistiques_taches.php');

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/EmployeeSchedule.php';

$pdo = \Database::getConnection();
$schedule = new \EmployeeSchedule($pdo);
$action = (string)($_GET['action'] ?? '');

$period = (string)($_GET['period'] ?? 'week');
if (!in_array($period, ['day', 'week', 'month', 'year'], true)) {
    $period = 'week';
}

$selectedDate = (string)($_GET['date'] ?? date('Y-m-d'));
$dateObj = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDate) ?: new DateTimeImmutable('today');
switch ($period) {
    case 'day':
        $rangeStart = $dateObj->setTime(0, 0, 0);
        $rangeEnd = $dateObj->setTime(23, 59, 59);
        break;
    case 'month':
        $rangeStart = $dateObj->modify('first day of this month')->setTime(0, 0, 0);
        $rangeEnd = $dateObj->modify('last day of this month')->setTime(23, 59, 59);
        break;
    case 'year':
        $rangeStart = $dateObj->setDate((int)$dateObj->format('Y'), 1, 1)->setTime(0, 0, 0);
        $rangeEnd = $dateObj->setDate((int)$dateObj->format('Y'), 12, 31)->setTime(23, 59, 59);
        break;
    case 'week':
    default:
        $rangeStart = $dateObj->modify('monday this week')->setTime(0, 0, 0);
        $rangeEnd = $dateObj->modify('sunday this week')->setTime(23, 59, 59);
        break;
}

$serviceOptions = [];
try {
    $serviceOptions = $schedule->listSelectableServices();
} catch (Throwable $e) {
    $serviceOptions = [];
}

$recapServiceId = max(0, (int)($_GET['recap_service_id'] ?? $_GET['service_id'] ?? 0));
$recapServiceLabel = 'Toute l entreprise';
foreach ($serviceOptions as $serviceOption) {
    if ((int)($serviceOption['id'] ?? 0) === $recapServiceId) {
        $recapServiceLabel = (string)($serviceOption['label'] ?? $recapServiceLabel);
        break;
    }
}

function task_stats_rows(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, int $serviceId = 0): array
{
    $where = ['e.start_at < :end_at', 'e.end_at > :start_at'];
    $params = ['start_at' => $start->format('Y-m-d H:i:s'), 'end_at' => $end->format('Y-m-d H:i:s')];
    if ($serviceId > 0) {
        $where[] = 'p.service_id = :service_id';
        $params['service_id'] = $serviceId;
    }

    $stmt = $pdo->prepare('
        SELECT e.id, e.personnel_id, e.title, e.description, e.location, e.start_at, e.end_at, e.status_code,
               p.nom_personnel_soignant AS personnel_name,
               p.matricule_personnel_soignant AS personnel_matricule,
               p.service_id,
               COALESCE(s.lib_service, "") AS service_label
        FROM employee_schedule_event e
        INNER JOIN personnel p ON p.id_personnel_soignant = e.personnel_id
        LEFT JOIN service s ON s.id_service = p.service_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY COALESCE(s.lib_service, ""), p.nom_personnel_soignant ASC, e.start_at ASC
    ');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function task_stats_kpis(array $rows, ?DateTimeImmutable $now = null): array
{
    $now = $now ?: new DateTimeImmutable('now');
    $planned = count($rows);
    $done = 0;
    $late = 0;
    foreach ($rows as $row) {
        $status = (string)($row['status_code'] ?? 'planifie');
        if ($status === 'termine') {
            $done++;
            continue;
        }
        if ($status === 'annule') {
            continue;
        }
        try {
            $endAt = trim((string)($row['end_at'] ?? ''));
            if ($endAt !== '' && new DateTimeImmutable($endAt) < $now) {
                $late++;
            }
        } catch (Throwable $e) {
        }
    }

    return [
        'planned' => $planned,
        'done' => $done,
        'late' => $late,
        'realization_rate' => $planned > 0 ? round(($done / $planned) * 100, 1) : null,
        'delay_rate' => $planned > 0 ? round(($late / $planned) * 100, 1) : null,
    ];
}

function task_stats_group(array $rows, string $keyField, string $labelField): array
{
    $groups = [];
    foreach ($rows as $row) {
        $key = trim((string)($row[$keyField] ?? '')) ?: '0';
        if (!isset($groups[$key])) {
            $label = trim((string)($row[$labelField] ?? ''));
            $groups[$key] = ['label' => $label !== '' ? $label : 'Non renseigne', 'rows' => []];
        }
        $groups[$key]['rows'][] = $row;
    }
    foreach ($groups as &$group) {
        $group['kpis'] = task_stats_kpis($group['rows']);
        unset($group['rows']);
    }
    unset($group);
    uasort($groups, static fn(array $a, array $b): int => strcmp((string)$a['label'], (string)$b['label']));
    return $groups;
}

function task_presence_rows(PDO $pdo, DateTimeInterface $start, DateTimeInterface $end, int $serviceId = 0): array
{
    $where = [
        'a.attendance_date BETWEEN :start_date AND :end_date',
        'a.attendance_status IN ("present", "half_day", "mission")',
    ];
    $params = ['start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d')];
    if ($serviceId > 0) {
        $where[] = 'p.service_id = :service_id';
        $params['service_id'] = $serviceId;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT a.personnel_id, a.attendance_date, a.attendance_status, a.check_in_time, a.check_out_time,
                   p.nom_personnel_soignant AS personnel_name,
                   p.matricule_personnel_soignant AS personnel_matricule,
                   p.service_id,
                   COALESCE(s.lib_service, "") AS service_label,
                   COUNT(e.id) AS task_count
            FROM rh_attendance_daily a
            INNER JOIN personnel p ON p.id_personnel_soignant = a.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN employee_schedule_event e ON e.personnel_id = a.personnel_id
                AND e.status_code <> "annule"
                AND DATE(e.start_at) <= a.attendance_date
                AND DATE(e.end_at) >= a.attendance_date
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY a.personnel_id, a.attendance_date, a.attendance_status, a.check_in_time, a.check_out_time,
                     p.nom_personnel_soignant, p.matricule_personnel_soignant, p.service_id, s.lib_service
            ORDER BY COALESCE(s.lib_service, ""), p.nom_personnel_soignant ASC, a.attendance_date ASC
        ');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function task_presence_stats(array $rows): array
{
    $personnel = [];
    $services = [];
    $presentDays = 0;
    $gapDays = 0;
    foreach ($rows as $row) {
        $presentDays++;
        $isGap = (int)($row['task_count'] ?? 0) <= 0;
        if ($isGap) {
            $gapDays++;
        }

        $personKey = (string)((int)($row['personnel_id'] ?? 0));
        $serviceKey = (string)((int)($row['service_id'] ?? 0));
        if (!isset($personnel[$personKey])) {
            $personnel[$personKey] = [
                'label' => trim((string)($row['personnel_name'] ?? '')) ?: 'Non renseigne',
                'matricule' => (string)($row['personnel_matricule'] ?? ''),
                'service_label' => (string)($row['service_label'] ?? ''),
                'present_days' => 0,
                'gap_days' => 0,
                'dates' => [],
            ];
        }
        if (!isset($services[$serviceKey])) {
            $services[$serviceKey] = ['label' => trim((string)($row['service_label'] ?? '')) ?: 'Non renseigne', 'present_days' => 0, 'gap_days' => 0];
        }
        $personnel[$personKey]['present_days']++;
        $services[$serviceKey]['present_days']++;
        if ($isGap) {
            $personnel[$personKey]['gap_days']++;
            $services[$serviceKey]['gap_days']++;
            $personnel[$personKey]['dates'][] = (string)($row['attendance_date'] ?? '');
        }
    }

    foreach ($personnel as &$stat) {
        $stat['gap_rate'] = $stat['present_days'] > 0 ? round(($stat['gap_days'] / $stat['present_days']) * 100, 1) : 0.0;
    }
    unset($stat);
    foreach ($services as &$stat) {
        $stat['gap_rate'] = $stat['present_days'] > 0 ? round(($stat['gap_days'] / $stat['present_days']) * 100, 1) : 0.0;
    }
    unset($stat);
    uasort($personnel, static fn(array $a, array $b): int => ($b['gap_rate'] <=> $a['gap_rate']) ?: strcmp((string)$a['label'], (string)$b['label']));
    uasort($services, static fn(array $a, array $b): int => ($b['gap_rate'] <=> $a['gap_rate']) ?: strcmp((string)$a['label'], (string)$b['label']));

    return [
        'kpis' => ['present_days' => $presentDays, 'gap_days' => $gapDays, 'gap_rate' => $presentDays > 0 ? round(($gapDays / $presentDays) * 100, 1) : null],
        'personnel' => $personnel,
        'services' => $services,
        'gap_rows' => array_values(array_filter($rows, static fn(array $row): bool => (int)($row['task_count'] ?? 0) <= 0)),
    ];
}

$recapRows = task_stats_rows($pdo, $rangeStart, $rangeEnd, $recapServiceId);
$recapKpis = task_stats_kpis($recapRows);
$serviceStats = task_stats_group($recapRows, 'service_id', 'service_label');
$personnelStats = task_stats_group($recapRows, 'personnel_id', 'personnel_name');
$presenceRows = task_presence_rows($pdo, $rangeStart, $rangeEnd, $recapServiceId);
$presenceStats = task_presence_stats($presenceRows);
$pendingCompletionValidations = $schedule->listPendingCompletionValidations((int)($_SESSION['user_id'] ?? 0), $rangeStart, $rangeEnd, $recapServiceId);
$statusLabelsForExport = $schedule->allowedStatusCodes();

if (in_array($action, ['export_recap_xlsx', 'export_recap_pdf'], true)) {
    if (ob_get_level() > 0) {
        @ob_clean();
    }
    require_once __DIR__ . '/vendor/autoload.php';
    $fileBase = 'statistiques_taches_' . ($recapServiceId > 0 ? ('service_' . $recapServiceId) : 'entreprise') . '_' . $rangeStart->format('Ymd') . '-' . $rangeEnd->format('Ymd');

    if ($action === 'export_recap_xlsx') {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('FINEA')->setTitle('Statistiques des taches');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthese');
        $sheet->fromArray([
            ['FINEA - Dashboard statistique des taches'],
            ['Perimetre', $recapServiceLabel],
            ['Periode', $rangeStart->format('d/m/Y') . ' - ' . $rangeEnd->format('d/m/Y')],
            [],
            ['Indicateur', 'Valeur'],
            ['Taches planifiees', (int)$recapKpis['planned']],
            ['Taches terminees validees', (int)$recapKpis['done']],
            ['Realisation', $recapKpis['realization_rate'] !== null ? ((float)$recapKpis['realization_rate'] / 100) : null],
            ['Presences sans tache', (int)$presenceStats['kpis']['gap_days']],
            ['Frequence presence sans tache', $presenceStats['kpis']['gap_rate'] !== null ? ((float)$presenceStats['kpis']['gap_rate'] / 100) : null],
        ], null, 'A1');
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F172A');
        $sheet->getStyle('A5:B5')->getFont()->setBold(true);
        $sheet->getStyle('B8:B10')->getNumberFormat()->setFormatCode('0.0%');

        $byPerson = $spreadsheet->createSheet();
        $byPerson->setTitle('Frequence individu');
        $byPerson->fromArray(['Service', 'Personnel', 'Matricule', 'Jours presents', 'Jours sans tache', 'Frequence', 'Dates trous'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['personnel'] as $stat) {
            $byPerson->fromArray([(string)$stat['service_label'], (string)$stat['label'], (string)$stat['matricule'], (int)$stat['present_days'], (int)$stat['gap_days'], ((float)$stat['gap_rate'] / 100), implode(', ', array_filter((array)$stat['dates']))], null, 'A' . $r++);
        }

        $byService = $spreadsheet->createSheet();
        $byService->setTitle('Frequence service');
        $byService->fromArray(['Service', 'Jours presents', 'Jours sans tache', 'Frequence'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['services'] as $stat) {
            $byService->fromArray([(string)$stat['label'], (int)$stat['present_days'], (int)$stat['gap_days'], ((float)$stat['gap_rate'] / 100)], null, 'A' . $r++);
        }

        $holes = $spreadsheet->createSheet();
        $holes->setTitle('Trous feuille temps');
        $holes->fromArray(['Date', 'Service', 'Personnel', 'Matricule', 'Statut presence', 'Entree', 'Sortie', 'Taches renseignees'], null, 'A1');
        $r = 2;
        foreach ($presenceStats['gap_rows'] as $row) {
            $holes->fromArray([(string)($row['attendance_date'] ?? ''), (string)($row['service_label'] ?? ''), (string)($row['personnel_name'] ?? ''), (string)($row['personnel_matricule'] ?? ''), (string)($row['attendance_status'] ?? ''), substr((string)($row['check_in_time'] ?? ''), 0, 5), substr((string)($row['check_out_time'] ?? ''), 0, 5), 0], null, 'A' . $r++);
        }

        $tasks = $spreadsheet->createSheet();
        $tasks->setTitle('Listing taches');
        $tasks->fromArray(['Service', 'Personnel', 'Matricule', 'Tache', 'Debut', 'Fin', 'Statut', 'Lieu', 'Description'], null, 'A1');
        $r = 2;
        foreach ($recapRows as $row) {
            $statusCode = (string)($row['status_code'] ?? '');
            $tasks->fromArray([(string)($row['service_label'] ?? ''), (string)($row['personnel_name'] ?? ''), (string)($row['personnel_matricule'] ?? ''), (string)($row['title'] ?? ''), (string)($row['start_at'] ?? ''), (string)($row['end_at'] ?? ''), (string)($statusLabelsForExport[$statusCode] ?? $statusCode), (string)($row['location'] ?? ''), (string)($row['description'] ?? '')], null, 'A' . $r++);
        }

        foreach ($spreadsheet->getAllSheets() as $ws) {
            $highestColumn = $ws->getHighestColumn();
            $highestRow = max(1, $ws->getHighestRow());
            $ws->getStyle('A1:' . $highestColumn . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $ws->getStyle('A1:' . $highestColumn . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('0F172A');
            $ws->getStyle('A1:' . $highestColumn . $highestRow)->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
            $ws->getStyle('A1:' . $highestColumn . $highestRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('CBD5E1');
            $ws->freezePane('A2');
            foreach (range('A', $highestColumn) as $col) {
                $ws->getColumnDimension($col)->setAutoSize(true);
            }
        }
        $byPerson->getStyle('F2:F' . max(2, $byPerson->getHighestRow()))->getNumberFormat()->setFormatCode('0.0%');
        $byService->getStyle('D2:D' . max(2, $byService->getHighestRow()))->getNumberFormat()->setFormatCode('0.0%');
        $spreadsheet->setActiveSheetIndex(0);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xlsx"');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('FINEA');
    $pdf->SetAuthor('FINEA');
    $pdf->SetTitle('Dashboard statistique des taches');
    $pdf->SetMargins(8, 18, 8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('dejavusans', 'B', 15);
    $pdf->Cell(0, 10, 'FINEA - Dashboard statistique des taches', 0, 1, 'L', true);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetFont('dejavusans', '', 8.5);
    $pdf->MultiCell(0, 5, 'Perimetre : ' . $recapServiceLabel . ' | Periode : ' . $rangeStart->format('d/m/Y') . ' - ' . $rangeEnd->format('d/m/Y') . ' | Export : ' . date('d/m/Y H:i'), 0, 'L');
    $pdf->Ln(2);
    $drawTable = static function (\TCPDF $pdf, string $title, array $headers, array $rows, array $widths): void {
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(0, 7, $title, 0, 1, 'L');
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetFillColor(226, 232, 240);
        foreach ($headers as $i => $header) {
            $pdf->MultiCell($widths[$i], 8, $header, 1, 'C', true, 0, null, null, true, 0, false, true, 8, 'M');
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 6.7);
        foreach ($rows as $row) {
            $heights = [];
            foreach ($row as $i => $value) {
                $heights[] = $pdf->getStringHeight($widths[$i], (string)$value);
            }
            $height = max(7, min(22, max($heights) + 2));
            if ($pdf->GetY() + $height > $pdf->getPageHeight() - 12) {
                $pdf->AddPage();
            }
            foreach ($row as $i => $value) {
                $pdf->MultiCell($widths[$i], $height, (string)$value, 1, 'L', false, 0, null, null, true, 0, false, true, $height, 'M');
            }
            $pdf->Ln();
        }
        $pdf->Ln(3);
    };
    $serviceGapRows = [];
    foreach (array_slice($presenceStats['services'], 0, 30, true) as $stat) {
        $serviceGapRows[] = [(string)$stat['label'], (string)$stat['present_days'], (string)$stat['gap_days'], number_format((float)$stat['gap_rate'], 1, ',', ' ') . '%'];
    }
    $drawTable($pdf, 'Frequence du comportement par service', ['Service', 'Jours presents', 'Sans tache', 'Frequence'], $serviceGapRows ?: [['-', '0', '0', '0%']], [120, 35, 35, 35]);
    $personRows = [];
    foreach (array_slice($presenceStats['personnel'], 0, 40, true) as $stat) {
        $personRows[] = [(string)$stat['service_label'], (string)$stat['label'], (string)$stat['matricule'], (string)$stat['present_days'], (string)$stat['gap_days'], number_format((float)$stat['gap_rate'], 1, ',', ' ') . '%', implode(', ', array_filter((array)$stat['dates']))];
    }
    $drawTable($pdf, 'Frequence du comportement par individu', ['Service', 'Personnel', 'Mat.', 'Pres.', 'Sans tache', '%', 'Jours concernes'], $personRows ?: [['-', '-', '-', '0', '0', '0%', '-']], [42, 50, 23, 15, 22, 16, 105]);
    $gapRows = [];
    foreach (array_slice($presenceStats['gap_rows'], 0, 80) as $row) {
        $gapRows[] = [(string)($row['attendance_date'] ?? ''), (string)($row['service_label'] ?? ''), (string)($row['personnel_name'] ?? ''), (string)($row['personnel_matricule'] ?? ''), substr((string)($row['check_in_time'] ?? ''), 0, 5), substr((string)($row['check_out_time'] ?? ''), 0, 5)];
    }
    $drawTable($pdf, 'Trous visibles sur feuille de temps', ['Date', 'Service', 'Personnel', 'Mat.', 'Entree', 'Sortie'], $gapRows ?: [['-', '-', '-', '-', '-', '-']], [25, 58, 75, 30, 25, 25]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileBase . '.pdf"');
    echo $pdf->Output($fileBase . '.pdf', 'S');
    exit;
}

$pageTitle = 'Dashboard statistiques des taches';
$activeMenu = '';
require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50 px-4 py-8">
    <div class="mx-auto max-w-[1600px]">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Dashboard statistiques</p>
                    <h1 class="mt-2 text-2xl font-bold text-slate-950">Statistiques des taches</h1>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($recapServiceLabel) ?> - <?= htmlspecialchars($rangeStart->format('d/m/Y')) ?> au <?= htmlspecialchars($rangeEnd->format('d/m/Y')) ?></p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="emploi_du_temps.php?period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50">Retour emploi du temps</a>
                    <a href="dashboard_statistiques_taches.php?action=export_recap_xlsx&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int)$recapServiceId ?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100"><i class="fa-solid fa-file-excel mr-2"></i>Excel</a>
                    <a href="dashboard_statistiques_taches.php?action=export_recap_pdf&amp;period=<?= htmlspecialchars($period) ?>&amp;date=<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>&amp;service_id=<?= (int)$recapServiceId ?>" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100"><i class="fa-solid fa-file-pdf mr-2"></i>PDF FINEA</a>
                </div>
            </div>
            <form method="get" class="mt-5 flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-500">Periode</label>
                    <select name="period" class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <?php foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'year' => 'Annee'] as $key => $label): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500">Date</label>
                    <input type="date" name="date" value="<?= htmlspecialchars($dateObj->format('Y-m-d')) ?>" class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500">Service</label>
                    <select name="recap_service_id" class="mt-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <option value="0">Toute l'entreprise</option>
                        <?php foreach ($serviceOptions as $serviceOption): ?>
                            <option value="<?= (int)($serviceOption['id'] ?? 0) ?>" <?= $recapServiceId === (int)($serviceOption['id'] ?? 0) ? 'selected' : '' ?>><?= htmlspecialchars((string)($serviceOption['label'] ?? 'Service')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Afficher</button>
            </form>
        </div>

        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <?php
            $cards = [
                ['Taches planifiees', (int)$recapKpis['planned'], 'border-slate-200 bg-white text-slate-950'],
                ['Taches terminees', (int)$recapKpis['done'], 'border-emerald-200 bg-emerald-50 text-emerald-900'],
                ['Realisation', $recapKpis['realization_rate'] !== null ? number_format((float)$recapKpis['realization_rate'], 1, ',', ' ') . '%' : 'n/d', 'border-sky-200 bg-sky-50 text-sky-900'],
                ['Taux de retard', $recapKpis['delay_rate'] !== null ? number_format((float)$recapKpis['delay_rate'], 1, ',', ' ') . '%' : 'n/d', 'border-rose-200 bg-rose-50 text-rose-900'],
                ['Present sans tache', (int)$presenceStats['kpis']['gap_days'], 'border-amber-200 bg-amber-50 text-amber-900'],
            ];
            ?>
            <?php foreach ($cards as $card): ?>
                <div class="rounded-2xl border p-5 shadow-sm <?= htmlspecialchars($card[2]) ?>">
                    <div class="text-xs font-semibold uppercase tracking-wide opacity-70"><?= htmlspecialchars((string)$card[0]) ?></div>
                    <div class="mt-2 text-3xl font-bold"><?= htmlspecialchars((string)$card[1]) ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="mt-5 rounded-2xl border border-amber-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-slate-950">Validations N+1 en attente</h2>
                    <p class="mt-1 text-sm text-slate-600">Taches marquees terminees par le personnel, en attente de validation hierarchique.</p>
                </div>
                <span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-bold text-amber-800"><?= count($pendingCompletionValidations) ?> attente(s)</span>
            </div>
            <div id="edt-validation-error" class="mt-3 hidden rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400"><tr><th class="py-2 pr-3">Tache</th><th class="py-2 pr-3">Personnel</th><th class="py-2 pr-3">Service</th><th class="py-2 pr-3">Periode</th><th class="py-2 text-right">Action</th></tr></thead>
                    <tbody id="edt-pending-validations">
                        <?php foreach (array_slice($pendingCompletionValidations, 0, 50) as $pendingValidation): ?>
                            <tr class="border-t border-slate-100" data-validation-event-id="<?= (int)($pendingValidation['id'] ?? 0) ?>">
                                <td class="py-3 pr-3 font-semibold text-slate-900"><?= htmlspecialchars((string)($pendingValidation['title'] ?? 'Tache')) ?></td>
                                <td class="py-3 pr-3 text-slate-700"><?= htmlspecialchars((string)($pendingValidation['personnel_name'] ?? '')) ?></td>
                                <td class="py-3 pr-3 text-slate-600"><?= htmlspecialchars((string)($pendingValidation['service_label'] ?? '')) ?></td>
                                <td class="py-3 pr-3 text-slate-600"><?= htmlspecialchars(substr((string)($pendingValidation['start_at'] ?? ''), 0, 16)) ?> - <?= htmlspecialchars(substr((string)($pendingValidation['end_at'] ?? ''), 0, 16)) ?></td>
                                <td class="py-3 text-right"><button type="button" class="edt-validate-task-btn rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700" data-event-id="<?= (int)($pendingValidation['id'] ?? 0) ?>">Valider termine</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$pendingCompletionValidations): ?>
                            <tr><td colspan="5" class="py-5 text-center text-slate-500">Aucune tache a valider pour votre perimetre N+1.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Frequence present sans tache par individu</h2>
                <div class="mt-4 max-h-96 overflow-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead class="text-xs uppercase text-slate-400"><tr><th class="py-2">Personnel</th><th class="py-2">Service</th><th class="py-2 text-right">Trous</th><th class="py-2 text-right">Freq.</th><th class="py-2">Jours</th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($presenceStats['personnel'], 0, 100, true) as $stat): ?>
                                <tr class="border-t border-slate-100"><td class="py-2 font-semibold"><?= htmlspecialchars((string)$stat['label']) ?></td><td class="py-2"><?= htmlspecialchars((string)$stat['service_label']) ?></td><td class="py-2 text-right"><?= (int)$stat['gap_days'] ?></td><td class="py-2 text-right"><?= htmlspecialchars(number_format((float)$stat['gap_rate'], 1, ',', ' ')) ?>%</td><td class="py-2 text-xs text-slate-500"><?= htmlspecialchars(implode(', ', array_slice(array_filter((array)$stat['dates']), 0, 12))) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-lg font-bold text-slate-950">Frequence par service</h2>
                <div class="mt-4 space-y-3">
                    <?php foreach ($presenceStats['services'] as $stat): ?>
                        <div>
                            <div class="flex justify-between gap-3 text-sm"><span class="font-semibold text-slate-800"><?= htmlspecialchars((string)$stat['label']) ?></span><span class="text-slate-500"><?= htmlspecialchars(number_format((float)$stat['gap_rate'], 1, ',', ' ')) ?>%</span></div>
                            <div class="mt-1 h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-amber-500" style="width: <?= max(0, min(100, (float)$stat['gap_rate'])) ?>%"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-bold text-slate-950">Trous visibles sur feuille de temps</h2>
            <div class="mt-4 max-h-[520px] overflow-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400"><tr><th class="py-2">Date</th><th class="py-2">Service</th><th class="py-2">Personnel</th><th class="py-2">Matricule</th><th class="py-2">Entree</th><th class="py-2">Sortie</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($presenceStats['gap_rows'], 0, 200) as $row): ?>
                            <tr class="border-t border-slate-100"><td class="py-2"><?= htmlspecialchars((string)($row['attendance_date'] ?? '')) ?></td><td class="py-2"><?= htmlspecialchars((string)($row['service_label'] ?? '')) ?></td><td class="py-2 font-semibold"><?= htmlspecialchars((string)($row['personnel_name'] ?? '')) ?></td><td class="py-2"><?= htmlspecialchars((string)($row['personnel_matricule'] ?? '')) ?></td><td class="py-2"><?= htmlspecialchars(substr((string)($row['check_in_time'] ?? ''), 0, 5)) ?></td><td class="py-2"><?= htmlspecialchars(substr((string)($row['check_out_time'] ?? ''), 0, 5)) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (!$presenceStats['gap_rows']): ?>
                            <tr><td colspan="6" class="py-5 text-center text-slate-500">Aucun trou detecte sur la periode.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
document.querySelectorAll('.edt-validate-task-btn').forEach(function(button) {
    button.addEventListener('click', async function() {
        const eventId = parseInt(button.dataset.eventId || '0', 10);
        const errorBox = document.getElementById('edt-validation-error');
        if (!eventId) return;
        button.disabled = true;
        button.textContent = 'Validation...';
        try {
            const response = await fetch('api/validate_employee_schedule_event_completion.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                body: JSON.stringify({event_id: eventId})
            });
            const data = await response.json();
            if (!data.ok) throw new Error(data.error || 'Validation impossible');
            const row = button.closest('[data-validation-event-id]');
            if (row) row.remove();
            if (errorBox) errorBox.classList.add('hidden');
        } catch (error) {
            if (errorBox) {
                errorBox.textContent = error.message || 'Validation impossible';
                errorBox.classList.remove('hidden');
            }
            button.disabled = false;
            button.textContent = 'Valider termine';
        }
    });
});
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>
