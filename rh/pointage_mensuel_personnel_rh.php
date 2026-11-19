<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';
require_once __DIR__ . '/../model/RhAttendancePeriodManager.php';
require_once __DIR__ . '/../services/RhAttendanceClosingNotificationService.php';

$pdo = \Database::getConnection();
$controller = new \RhPayrollController($pdo);
$manager = $controller->service();
$periodManager = new \RhAttendancePeriodManager($pdo);
$requestedPeriod = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['period'] ?? $_POST['period'] ?? ''))
    ? (string) ($_GET['period'] ?? $_POST['period'])
    : date('Y-m');
if (empty($_SESSION['rh_attendance_period_csrf'])) {
    $_SESSION['rh_attendance_period_csrf'] = bin2hex(random_bytes(24));
}
$periodFlash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['period_action'])) {
    if (!hash_equals((string) $_SESSION['rh_attendance_period_csrf'], (string) ($_POST['_csrf'] ?? ''))) {
        $periodFlash = 'Jeton de sécurité invalide.';
    } else {
        try {
            if ((string) $_POST['period_action'] === 'delete_cutoff') {
                $periodManager->delete($requestedPeriod);
                $_SESSION['attendance_closure_delivery_flash'] = 'La clôture a été retirée. Aucun nouvel envoi n’a été effectué.';
            } else {
                $periodManager->save($requestedPeriod, (string) ($_POST['cutoff_date'] ?? ''), [
                    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                    'user_name' => trim((string) ($_SESSION['prenom'] ?? '') . ' ' . (string) ($_SESSION['nom'] ?? '')),
                ]);
                @set_time_limit(0);
                $closedRange = $periodManager->resolve($requestedPeriod);
                $delivery = (new \RhAttendanceClosingNotificationService($pdo))->distribute(
                    $requestedPeriod,
                    (string) $closedRange['start_date'],
                    (string) $closedRange['end_date']
                );
                $_SESSION['attendance_closure_delivery_flash'] = sprintf(
                    'Clôture enregistrée : %d email(s), %d notification(s) système, %d déjà envoyé(s), %d échec(s).',
                    (int) ($delivery['email_sent'] ?? 0),
                    (int) ($delivery['system_sent'] ?? 0),
                    (int) ($delivery['skipped'] ?? 0),
                    (int) ($delivery['failed'] ?? 0)
                );
            }
            header('Location: pointage_mensuel_personnel_rh.php?period=' . urlencode($requestedPeriod)
                . '&personnel_id=' . (int) ($_POST['personnel_id'] ?? 0) . '&period_saved=1');
            exit;
        } catch (Throwable $e) {
            $periodFlash = $e->getMessage();
        }
    }
}
$range = $periodManager->resolve($requestedPeriod, $_GET['date_from'] ?? null, $_GET['date_to'] ?? null);
$deliveryFlash = (string) ($_SESSION['attendance_closure_delivery_flash'] ?? '');
unset($_SESSION['attendance_closure_delivery_flash']);
$viewRequest = array_merge($_GET, [
    'period' => $requestedPeriod,
    'date_from' => $range['start_date'],
    'date_to' => $range['end_date'],
]);
$viewData = $controller->monthlyAttendanceView($viewRequest);
$period = $viewData['period'];
$selectedPersonnelId = $viewData['selectedPersonnelId'];
$employees = $viewData['employees'];
$statuses = $viewData['statuses'];
$startDate = $viewData['startDate'];
$endDate = $viewData['endDate'];
$employee = $viewData['employee'];
$holidaySet = $viewData['holidaySet'];
$attendanceRows = $viewData['attendanceRows'];
$attendanceSummary = $viewData['attendanceSummary'];
$attendanceSettings = rhGetAttendanceSettings($pdo);
$days = $viewData['days'];


function rhEnsureAttendanceSettingsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_attendance_settings (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        setting_key VARCHAR(80) NOT NULL,
        setting_value VARCHAR(120) NOT NULL,
        setting_label VARCHAR(190) DEFAULT NULL,
        updated_by INT DEFAULT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_rh_attendance_setting_key (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rhGetAttendanceSettings(PDO $pdo): array
{
    rhEnsureAttendanceSettingsTable($pdo);
    $settings = [
        'daily_work_target_hours' => '10.00',
        'late_reference_time' => '08:40',
        'arrival_tolerance_minutes' => '10',
        'departure_tolerance_minutes' => '10',
        'overtime_tolerance_minutes' => '15',
    ];

    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM rh_attendance_settings');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (array_key_exists($key, $settings)) {
                $settings[$key] = (string) ($row['setting_value'] ?? $settings[$key]);
            }
        }
    } catch (Throwable $ignored) {
        // Les valeurs par defaut restent utilisables meme si la table vient juste d'etre creee.
    }

    return $settings;
}

function rhTimeToMinutes(?string $time): ?int
{
    $time = trim((string) $time);
    if ($time === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
        return null;
    }
    $hour = max(0, min(23, (int) $matches[1]));
    $minute = max(0, min(59, (int) $matches[2]));
    return ($hour * 60) + $minute;
}

function rhRoundQuarterHour(float $hours): float
{
    return round($hours * 4) / 4;
}

function rhEffectiveAttendanceMetrics(array $attendance, array $settings): array
{
    $status = (string) ($attendance['attendance_status'] ?? 'present');
    $activeStatuses = ['present', 'half_day'];

    $targetHours = max(0.0, (float) str_replace(',', '.', (string) ($settings['daily_work_target_hours'] ?? '10.00')));
    $referenceMinutes = rhTimeToMinutes((string) ($settings['late_reference_time'] ?? '08:40')) ?? (8 * 60 + 40);
    $arrivalTolerance = max(0, (int) ($settings['arrival_tolerance_minutes'] ?? 10));
    $departureTolerance = max(0, (int) ($settings['departure_tolerance_minutes'] ?? 10));
    $overtimeTolerance = max(0, (int) ($settings['overtime_tolerance_minutes'] ?? 15));

    $in = rhTimeToMinutes((string) ($attendance['check_in_time'] ?? ''));
    $out = rhTimeToMinutes((string) ($attendance['check_out_time'] ?? ''));
    $workedMinutes = null;
    if ($in !== null && $out !== null) {
        if ($out < $in) {
            $out += 1440;
        }
        $workedMinutes = max(0, $out - $in);
    }

    $lateMinutes = 0;
    if (in_array($status, $activeStatuses, true) && $in !== null && $in > ($referenceMinutes + $arrivalTolerance)) {
        $lateMinutes = $in - $referenceMinutes;
    }

    $targetMinutes = (int) round($targetHours * 60);
    $rawBalanceMinutes = $workedMinutes !== null ? ($workedMinutes - $targetMinutes) : 0;
    $deficitMinutes = $rawBalanceMinutes < (-1 * $departureTolerance) ? abs($rawBalanceMinutes) : 0;
    $overtimeMinutes = $rawBalanceMinutes > $overtimeTolerance ? $rawBalanceMinutes : 0;
    $calculatedOvertime = $overtimeMinutes > 0 ? rhRoundQuarterHour($overtimeMinutes / 60) : 0.0;

    return [
        'worked_minutes' => $workedMinutes,
        'late_minutes' => $lateMinutes,
        'deficit_minutes' => $deficitMinutes,
        'overtime_hours' => $calculatedOvertime,
        'has_time_pair' => $workedMinutes !== null,
    ];
}

$weekdays = [
    1 => 'Lundi',
    2 => 'Mardi',
    3 => 'Mercredi',
    4 => 'Jeudi',
    5 => 'Vendredi',
    6 => 'Samedi',
    7 => 'Dimanche',
];

$referenceWorkingDays = 0;
foreach ($days as $referenceDate) {
    $referenceDay = new DateTimeImmutable($referenceDate);
    if ((int) $referenceDay->format('N') < 6 && !isset($holidaySet[$referenceDate])) {
        $referenceWorkingDays++;
    }
}

$extraDates = [];
if (is_array($attendanceSummary)) {
    $workingDates = $manager->listWorkingDatesForPeriod($period);
    $workingSet = array_fill_keys($workingDates, true);
    foreach (array_keys($attendanceRows) as $date) {
        if (!isset($workingSet[$date])) {
            $extraDates[] = $date;
        }
    }
}


if (is_array($attendanceSummary)) {
    $effectiveMonthlyOvertime = 0.0;
    $effectiveMonthlyWorkedHours = 0.0;
    foreach ($attendanceRows as $date => $row) {
        if (!is_array($row)) {
            continue;
        }
        $metrics = rhEffectiveAttendanceMetrics($row, $attendanceSettings);
        if ($metrics['has_time_pair']) {
            $attendanceRows[$date]['overtime_hours'] = (float) $metrics['overtime_hours'];
            $attendanceRows[$date]['late_minutes'] = (int) $metrics['late_minutes'];
            $attendanceRows[$date]['deficit_minutes'] = (int) $metrics['deficit_minutes'];
        }
        if ($metrics['worked_minutes'] !== null) {
            $attendanceRows[$date]['worked_hours'] = round((int) $metrics['worked_minutes'] / 60, 2);
            $effectiveMonthlyWorkedHours += (float) $attendanceRows[$date]['worked_hours'];
        }
        $effectiveMonthlyOvertime += (float) ($attendanceRows[$date]['overtime_hours'] ?? 0);
    }
    $attendanceSummary['overtime_hours'] = $effectiveMonthlyOvertime;
}

\AuditTrail::logPageConsultation([
    'module' => 'rh',
    'action' => 'pointage_mensuel_personnel_view',
    'entity_type' => 'attendance_monthly_page',
    'entity_id' => $selectedPersonnelId > 0 ? (string) $selectedPersonnelId : null,
    'entity_label' => $employee['full_name'] ?? 'pointage_mensuel_personnel_rh',
    'details' => [
        'period' => $period,
        'selected_personnel_id' => $selectedPersonnelId > 0 ? $selectedPersonnelId : null,
        'has_summary' => is_array($attendanceSummary),
        'attendance_row_count' => count($attendanceRows),
        'extra_date_count' => count($extraDates),
    ],
], $pdo);

\AuditTrail::log('attendance_monthly_consulted', [
    'module' => 'rh',
    'action' => 'pointage_mensuel_personnel_consult',
    'entity_type' => 'attendance_monthly_page',
    'entity_id' => $selectedPersonnelId > 0 ? (string) $selectedPersonnelId : null,
    'entity_label' => $employee['full_name'] ?? 'pointage_mensuel_personnel_rh',
    'details' => [
        'period' => $period,
        'selected_personnel_id' => $selectedPersonnelId > 0 ? $selectedPersonnelId : null,
        'has_summary' => is_array($attendanceSummary),
        'attendance_row_count' => count($attendanceRows),
        'extra_date_count' => count($extraDates),
    ],
], $pdo);

$pageTitle = 'Pointage mensuel (1 personne)';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-emerald-100 bg-gradient-to-r from-slate-950 via-slate-900 to-emerald-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-emerald-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Pointage mensuel (1 personne)</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Visualisez le pointage jour par jour pour un collaborateur (jours saisis + jours non saisis) afin d'eviter les erreurs avant la paie.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="pointage_journalier_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-emerald-50">
                        <i class="fas fa-user-check mr-2 text-emerald-600"></i>
                        Pointage journalier
                    </a>
                    <a href="feries_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-times mr-2"></i>
                        Feries
                    </a>
                </div>
            </div>
        </div>

        <form method="get" class="mt-8 rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Salarie</label>
                    <select name="personnel_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white" required>
                        <option value="">Selectionner un collaborateur</option>
                        <?php foreach ($employees as $row): ?>
                            <option value="<?= (int) $row['id'] ?>" <?= (int) ($row['id'] ?? 0) === (int) $selectedPersonnelId ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $row['full_name']) ?> - <?= htmlspecialchars((string) (($row['matricule'] ?? '') ?: 'Sans matricule')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Periode</label>
                    <input type="month" name="period" value="<?= htmlspecialchars($period) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white" required>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date début</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($startDate) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Date fin (optionnelle)</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($endDate) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                </div>
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                    <i class="fas fa-search mr-2"></i>
                    Afficher
                </button>
                <?php if ($selectedPersonnelId > 0): ?>
                    <?php $exportQuery = http_build_query(['mode' => 'mensuel', 'period' => $period, 'date_from' => $startDate, 'date_to' => $endDate, 'personnel_id' => $selectedPersonnelId]); ?>
                    <a target="_blank" href="export_pointage_pdf.php?<?= htmlspecialchars($exportQuery) ?>" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700"><i class="fas fa-file-pdf mr-2"></i>Exporter PDF</a>
                    <a target="_blank" href="export_pointage_excel.php?<?= htmlspecialchars($exportQuery) ?>" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700"><i class="fas fa-file-excel mr-2"></i>Exporter Excel</a>
                <?php endif; ?>
            </div>
        </form>

        <?php if (\AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_rh')): ?>
            <form method="post" class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $_SESSION['rh_attendance_period_csrf']) ?>">
                <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                <input type="hidden" name="personnel_id" value="<?= (int) $selectedPersonnelId ?>">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-amber-900">Arrêter le pointage de <?= htmlspecialchars($period) ?> à cette date</label>
                        <input type="date" name="cutoff_date" min="<?= htmlspecialchars($period . '-01') ?>" max="<?= htmlspecialchars(date('Y-m-t', strtotime($period . '-01'))) ?>" value="<?= htmlspecialchars((string) ($range['cutoff_date'] ?? date('Y-m-t', strtotime($period . '-01')))) ?>" class="rounded-xl border border-amber-300 bg-white px-4 py-3 text-sm">
                        <p class="mt-2 text-xs text-amber-800">Les jours suivants seront automatiquement inclus au début de la période de pointage suivante.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button name="period_action" value="save_cutoff" class="rounded-xl bg-amber-700 px-4 py-3 text-sm font-semibold text-white">Enregistrer la clôture</button>
                        <?php if (!empty($range['cutoff_date'])): ?><button name="period_action" value="delete_cutoff" class="rounded-xl border border-amber-300 bg-white px-4 py-3 text-sm font-semibold text-amber-900">Revenir au mois civil</button><?php endif; ?>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <?php if ($periodFlash !== ''): ?><div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"><?= htmlspecialchars($periodFlash) ?></div><?php endif; ?>
        <?php if ($deliveryFlash !== ''): ?><div class="mt-4 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm font-semibold text-indigo-800"><?= htmlspecialchars($deliveryFlash) ?></div><?php endif; ?>

        <?php if ($selectedPersonnelId > 0 && $employee !== null && is_array($attendanceSummary)): ?>
            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                    <p class="text-sm font-medium text-slate-500">Jours ouvrés (reference)</p>
                    <p class="mt-3 text-4xl font-semibold text-slate-950"><?= (int) $referenceWorkingDays ?></p>
                </div>
                <div class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                    <p class="text-sm font-medium text-slate-500">Lignes saisies</p>
                    <p class="mt-3 text-4xl font-semibold text-slate-950"><?= (int) ($attendanceSummary['entries_count'] ?? 0) ?></p>
                </div>
                <div class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                    <p class="text-sm font-medium text-slate-500">Jours (total saisi)</p>
                    <p class="mt-3 text-4xl font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) ($attendanceSummary['worked_days'] ?? 0), 2, ',', ' ')) ?></p>
                </div>
                <div class="rounded-[26px] border border-white/80 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                    <p class="text-sm font-medium text-slate-500">HS (total saisies)</p>
                    <p class="mt-3 text-4xl font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) ($attendanceSummary['overtime_hours'] ?? 0), 2, ',', ' ')) ?> h</p>
                </div>
                <div class="rounded-[26px] border border-cyan-100 bg-cyan-50/80 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)]">
                    <p class="text-sm font-medium text-cyan-700">Heures travaillées</p>
                    <p class="mt-3 text-4xl font-semibold text-slate-950"><?= htmlspecialchars(number_format((float) ($effectiveMonthlyWorkedHours ?? 0), 2, ',', ' ')) ?> h</p>
                </div>
            </div>

            <?php if (count($extraDates) > 0): ?>
                <div class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                    Pointage en dehors des jours ouvrés detecte sur :
                    <span class="font-semibold"><?= htmlspecialchars(implode(', ', array_map(static fn($d) => date('d/m/Y', strtotime($d)), $extraDates))) ?></span>.
                    Ces jours ne doivent pas augmenter les jours payes (ils servent surtout a la saisie des HS).
                </div>
            <?php endif; ?>

            <section class="mt-8 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Details</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Jour par jour — <?= htmlspecialchars((string) $employee['full_name']) ?></h2>
                        <p class="mt-3 text-sm text-slate-500"><?= htmlspecialchars($startDate) ?> → <?= htmlspecialchars($endDate) ?></p>
                    </div>
                    <a href="pointage_journalier_rh.php" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-900 transition hover:bg-slate-50">
                        <i class="fas fa-pen mr-2 text-emerald-600"></i>
                        Corriger via le journalier
                    </a>
                </div>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-slate-500">
                                <th class="px-3 py-3 font-medium">Date</th>
                                <th class="px-3 py-3 font-medium">Jour</th>
                                <th class="px-3 py-3 font-medium">Type</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                                <th class="px-3 py-3 font-medium">Jours</th>
                                <th class="px-3 py-3 font-medium">HS</th>
                                <th class="px-3 py-3 font-medium">Retard</th>
                                <th class="px-3 py-3 font-medium">Deficit</th>
                                <th class="px-3 py-3 font-medium">Entree</th>
                                <th class="px-3 py-3 font-medium">Sortie</th>
                                <th class="px-3 py-3 font-medium">Heures travaillées</th>
                                <th class="px-3 py-3 font-medium">Notes</th>
                                <th class="px-3 py-3 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($days as $date): ?>
                                <?php
                                $dt = new DateTimeImmutable($date);
                                $dow = (int) $dt->format('N');
                                $isWeekend = $dow >= 6;
                                $isHoliday = isset($holidaySet[$date]);

                                $row = $attendanceRows[$date] ?? null;
                                $isSaved = is_array($row);

                                $typeLabel = $isHoliday ? 'Ferie' : ($isWeekend ? 'Week-end' : 'Ouvre');
                                $typeClass = $isHoliday ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : ($isWeekend ? 'bg-slate-50 text-slate-700 border-slate-200' : 'bg-emerald-50 text-emerald-700 border-emerald-200');

                                $statusKey = $isSaved ? (string) ($row['attendance_status'] ?? 'present') : '';
                                $statusLabel = $isSaved ? (string) (($statuses[$statusKey]['label'] ?? $statusKey)) : 'Non saisi';
                                $workedDays = $isSaved ? (float) ($row['worked_days'] ?? 0) : 0.0;
                                $overtime = $isSaved ? (float) ($row['overtime_hours'] ?? 0) : 0.0;
                                $lateMinutes = $isSaved ? (int) ($row['late_minutes'] ?? 0) : 0;
                                $deficitMinutes = $isSaved ? (int) ($row['deficit_minutes'] ?? 0) : 0;

                                $rowClass = (!$isSaved && !$isWeekend && !$isHoliday)
                                    ? 'bg-rose-50/40'
                                    : 'bg-white';
                                ?>
                                <tr class="border-b border-slate-100 last:border-b-0 <?= htmlspecialchars($rowClass) ?>">
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars($dt->format('d/m/Y')) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars($weekdays[$dow] ?? '') ?></td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= htmlspecialchars($typeClass) ?>">
                                            <?= htmlspecialchars($typeLabel) ?>
                                            <?php if ($isHoliday && trim((string) $holidaySet[$date]) !== ''): ?>
                                                <span class="ml-2 text-[11px] font-medium opacity-80"><?= htmlspecialchars((string) $holidaySet[$date]) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3">
                                        <span class="font-semibold <?= $isSaved ? 'text-slate-900' : 'text-rose-700' ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                    </td>
                                    <td class="px-3 py-3 text-slate-900"><?= htmlspecialchars((string) number_format($workedDays, 2, ',', ' ')) ?></td>
                                    <td class="px-3 py-3 text-slate-900"><?= htmlspecialchars((string) number_format($overtime, 2, ',', ' ')) ?></td>
                                    <td class="px-3 py-3 <?= $lateMinutes > 0 ? 'font-semibold text-rose-700' : 'text-slate-500' ?>"><?= $lateMinutes > 0 ? htmlspecialchars((string) $lateMinutes . ' min') : '-' ?></td>
                                    <td class="px-3 py-3 <?= $deficitMinutes > 0 ? 'font-semibold text-amber-700' : 'text-slate-500' ?>"><?= $deficitMinutes > 0 ? htmlspecialchars((string) $deficitMinutes . ' min') : '-' ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars((string) ($row['check_in_time'] ?? '')) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars((string) ($row['check_out_time'] ?? '')) ?></td>
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= $isSaved && isset($row['worked_hours']) ? htmlspecialchars(number_format((float) $row['worked_hours'], 2, ',', ' ')) . ' h' : '-' ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars((string) ($row['notes'] ?? '')) ?></td>
                                    <td class="px-3 py-3">
                                        <a class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 transition hover:bg-slate-50" href="pointage_journalier_rh.php?date=<?= urlencode($date) ?>">
                                            <i class="fas fa-arrow-right mr-2 text-emerald-600"></i>
                                            Ouvrir
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php elseif ($selectedPersonnelId > 0 && $employee === null): ?>
            <div class="mt-8 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700">
                Le salarie selectionne est introuvable (ou n'est plus actif).
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
