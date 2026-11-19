<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/RhDashboardRepository.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$repository = new RhDashboardRepository($pdo);


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
    }
    return $settings;
}
function rhTimeToMinutes(?string $time): ?int
{
    $time = trim((string) $time);
    if ($time === '' || !preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
        return null;
    }
    return (max(0, min(23, (int) $m[1])) * 60) + max(0, min(59, (int) $m[2]));
}
function rhRoundQuarterHour(float $hours): float
{
    return round($hours * 4) / 4;
}
function rhEffectiveAttendanceMetrics(array $attendance, array $settings): array
{
    $status = (string) ($attendance['attendance_status'] ?? 'present');
    $targetHours = max(0.0, (float) str_replace(',', '.', (string) ($settings['daily_work_target_hours'] ?? '10.00')));
    $referenceMinutes = rhTimeToMinutes((string) ($settings['late_reference_time'] ?? '08:40')) ?? 520;
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
    if (in_array($status, ['present', 'half_day'], true) && $in !== null && $in > ($referenceMinutes + $arrivalTolerance)) {
        $lateMinutes = $in - $referenceMinutes;
    }
    $rawBalanceMinutes = $workedMinutes !== null ? ($workedMinutes - (int) round($targetHours * 60)) : 0;
    return [
        'late_minutes' => $lateMinutes,
        'deficit_minutes' => $rawBalanceMinutes < (-1 * $departureTolerance) ? abs($rawBalanceMinutes) : 0,
        'overtime_hours' => $rawBalanceMinutes > $overtimeTolerance ? rhRoundQuarterHour($rawBalanceMinutes / 60) : 0.0,
        'has_time_pair' => $workedMinutes !== null,
    ];
}

function rh_report_param(string $key, string $default = ''): string
{
    return trim((string) ($_POST[$key] ?? $_GET[$key] ?? $default));
}

function rh_report_param_ids(string $key): array
{
    $raw = $_POST[$key] ?? $_GET[$key] ?? [];
    if (!is_array($raw)) {
        $raw = preg_split('/[^0-9]+/', (string) $raw) ?: [];
    }
    return array_values(array_unique(array_filter(array_map('intval', $raw), static fn(int $id): bool => $id > 0)));
}

function rh_report_preview_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function rh_report_preview_number($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', ' ');
}

function rh_report_preview_date(string $date): string
{
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : $date;
}

$mode = strtolower(rh_report_param('report_mode', 'monthly'));
$today = date('Y-m-d');
$startDate = $today;
$endDate = $today;
$periodLabel = 'Journalier';

if ($mode === 'annual') {
    $year = max(2000, min(2100, (int) rh_report_param('report_year', date('Y'))));
    $startDate = sprintf('%04d-01-01', $year);
    $endDate = sprintf('%04d-12-31', $year);
    $periodLabel = 'Annuel ' . $year;
} elseif ($mode === 'custom') {
    $startDate = rh_report_param('start_date', $today);
    $endDate = rh_report_param('end_date', $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = $today;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $startDate;
    }
    $periodLabel = 'Periode libre';
} elseif ($mode === 'daily') {
    $date = rh_report_param('report_date', $today);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = $today;
    }
    $startDate = $date;
    $endDate = $date;
    $periodLabel = 'Journalier ' . rh_report_preview_date($date);
} else {
    $mode = 'monthly';
    $month = rh_report_param('report_month', date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }
    $start = new DateTimeImmutable($month . '-01');
    $startDate = $start->format('Y-m-d');
    $endDate = $start->modify('last day of this month')->format('Y-m-d');
    $periodLabel = 'Mensuel ' . $start->format('m/Y');
}
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

$requestedPersonnelIds = rh_report_param_ids('personnel_ids');
$legacyRequestedPersonnelId = max(0, (int) rh_report_param('personnel_id', '0'));
if (!$requestedPersonnelIds && $legacyRequestedPersonnelId > 0) $requestedPersonnelIds = [$legacyRequestedPersonnelId];
$requestedPersonnelIds = array_slice($requestedPersonnelIds, 0, 1);
if (!$requestedPersonnelIds) {
    header('Location: dashboard_rh.php?view=analytique&report_error=personnel_required');
    exit;
}
$_SESSION['rh_report_personnel_id'] = (int) $requestedPersonnelIds[0];
$_SESSION['rh_report_personnel_selected_at'] = time();
$scope = $requestedPersonnelIds ? 'personnel' : strtolower(rh_report_param('report_scope', 'all'));
if (!in_array($scope, ['all', 'personnel', 'service', 'fonction', 'statut'], true)) {
    $scope = 'all';
}
$scopeFilters = ['report_scope' => $scope];
$scopeLabel = 'General';
if ($scope === 'personnel') {
    $selectedPersonnelIds = $requestedPersonnelIds;
    $scopeFilters['personnel_ids'] = $selectedPersonnelIds;
    if ($selectedPersonnelIds) {
        $placeholders = implode(',', array_fill(0, count($selectedPersonnelIds), '?'));
        $person = $pdo->prepare('SELECT nom_personnel_soignant FROM personnel WHERE id_personnel_soignant IN (' . $placeholders . ') ORDER BY nom_personnel_soignant');
        $person->execute($selectedPersonnelIds);
        $names = array_values(array_filter(array_map('trim', $person->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        $scopeLabel = count($names) === 1 ? ('Personne : ' . $names[0]) : ('Sélection : ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? (' +' . (count($names) - 6)) : ''));
    } else {
        $scopeLabel = 'Aucune personne sélectionnée';
    }
} elseif ($scope === 'service') {
    $scopeFilters['service_id'] = max(0, (int) rh_report_param('service_id', '0'));
    $stmt = $pdo->prepare('SELECT lib_service FROM service WHERE id_service = :id LIMIT 1');
    $stmt->execute(['id' => $scopeFilters['service_id']]);
    $scopeLabel = 'Service : ' . (string) ($stmt->fetchColumn() ?: ('#' . $scopeFilters['service_id']));
} elseif ($scope === 'fonction') {
    $scopeFilters['fonction_id'] = max(0, (int) rh_report_param('fonction_id', '0'));
    $stmt = $pdo->prepare('SELECT lib_fonction FROM fonction WHERE id_fonction = :id LIMIT 1');
    $stmt->execute(['id' => $scopeFilters['fonction_id']]);
    $scopeLabel = 'Fonction : ' . (string) ($stmt->fetchColumn() ?: ('#' . $scopeFilters['fonction_id']));
} elseif ($scope === 'statut') {
    $scopeFilters['status_code'] = max(0, (int) rh_report_param('status_code', '0'));
    $stmt = $pdo->prepare('SELECT label FROM rh_statuts WHERE code = :code LIMIT 1');
    $stmt->execute(['code' => $scopeFilters['status_code']]);
    $scopeLabel = 'Statut : ' . (string) ($stmt->fetchColumn() ?: ('#' . $scopeFilters['status_code']));
}

$comments = [
    'comment_summary' => rh_report_param('comment_summary'),
    'comment_risks' => rh_report_param('comment_risks'),
    'comment_actions' => rh_report_param('comment_actions'),
];

$analytics = $repository->getOperationalAnalyticsForRange($startDate, $endDate, $periodLabel, $scopeFilters);
$attendance = (array) ($analytics['attendance'] ?? []);
$requests = (array) ($analytics['requests'] ?? []);
$schedule = (array) ($analytics['schedule'] ?? []);
$overtime = (array) ($analytics['overtime'] ?? []);
$personnelBreakdown = $repository->getPersonnelAnalyticsBreakdown($startDate, $endDate, $scopeFilters);
$resolvedPersonnelScope = $analytics['personnel_scope'] ?? null;
$personnelCount = count($personnelBreakdown);

$attendanceSettings = rhGetAttendanceSettings($pdo);
try {
    $attendanceManagerForTolerance = (new \RhPayrollController($pdo))->service();
    $scopePersonnelIds = is_array($resolvedPersonnelScope) ? array_map('intval', $resolvedPersonnelScope) : null;
    $lateRows = 0;
    $lateMinutesTotal = 0;
    $entryRows = 0;
    $overtimeByEmployee = [];
    $startLoop = new DateTimeImmutable($startDate);
    $endLoop = new DateTimeImmutable($endDate);
    for ($cursor = $startLoop; $cursor <= $endLoop; $cursor = $cursor->modify('+1 day')) {
        $board = $attendanceManagerForTolerance->getAttendanceBoard($cursor->format('Y-m-d'));
        foreach (($board['items'] ?? []) as $item) {
            $employee = is_array($item['employee'] ?? null) ? $item['employee'] : [];
            $pid = (int) ($employee['id'] ?? 0);
            if (is_array($scopePersonnelIds) && !in_array($pid, $scopePersonnelIds, true)) {
                continue;
            }
            $attendanceRow = is_array($item['attendance'] ?? null) ? $item['attendance'] : [];
            $entryRows++;
            $metrics = rhEffectiveAttendanceMetrics($attendanceRow, $attendanceSettings);
            if ((int) $metrics['late_minutes'] > 0) {
                $lateRows++;
                $lateMinutesTotal += (int) $metrics['late_minutes'];
            }
            $effectiveOt = $metrics['has_time_pair'] ? (float) $metrics['overtime_hours'] : (float) ($attendanceRow['overtime_hours'] ?? 0);
            if ($pid > 0 && $effectiveOt > 0) {
                if (!isset($overtimeByEmployee[$pid])) {
                    $overtimeByEmployee[$pid] = [
                        'employee_id' => $pid,
                        'employee_name' => (string) ($employee['full_name'] ?? ('#' . $pid)),
                        'hours_total' => 0.0,
                    ];
                }
                $overtimeByEmployee[$pid]['hours_total'] += $effectiveOt;
            }
        }
    }
    if ($scopePersonnelIds === null || is_array($scopePersonnelIds)) {
        usort($overtimeByEmployee, static fn($a, $b) => ($b['hours_total'] <=> $a['hours_total']));
        $attendance['late_rows'] = $lateRows;
        $attendanceDenominator = (int) ($attendance['total_rows'] ?? 0);
        $attendance['late_rate'] = $attendanceDenominator > 0 ? ($lateRows / $attendanceDenominator) * 100 : 0;
        $attendance['absence_rate'] = $attendanceDenominator > 0 ? (((int) ($attendance['absence_rows'] ?? 0) / $attendanceDenominator) * 100) : 0;
        $attendance['average_late_minutes'] = $lateRows > 0 ? ($lateMinutesTotal / $lateRows) : 0;
        $overtime['hours_total'] = array_sum(array_map(static fn($row) => (float) ($row['hours_total'] ?? 0), $overtimeByEmployee));
        $overtime['employees_count'] = count($overtimeByEmployee);
        $overtime['top_employees'] = array_slice(array_values($overtimeByEmployee), 0, 10);
    }
} catch (Throwable $ignored) {
    // Le rapport conserve les donnees initiales si le recalcul temps reel echoue.
}

$lateRate = (float) ($attendance['late_rate'] ?? 0);
$absenceRate = (float) ($attendance['absence_rate'] ?? (((int) ($attendance['total_rows'] ?? 0)) > 0 ? (((int) ($attendance['absence_rows'] ?? 0) / (int) $attendance['total_rows']) * 100) : 0));
$presenceRate = (float) ($attendance['presence_rate'] ?? 0);
$overtimeHours = (float) ($overtime['hours_total'] ?? 0);
$rhSuggestions = [];
$attentionLevel = 'Suivi normal';
$attentionClass = 'border-emerald-200 bg-emerald-50 text-emerald-900';
if ((int) ($attendance['total_rows'] ?? 0) === 0) {
    $attentionLevel = 'Données insuffisantes';
    $attentionClass = 'border-slate-200 bg-slate-50 text-slate-800';
    $rhSuggestions[] = 'Vérifier la complétude du pointage avant toute appréciation individuelle.';
} else {
    if ($lateRate >= 15) {
        $attentionLevel = 'Attention renforcée';
        $attentionClass = 'border-rose-200 bg-rose-50 text-rose-900';
        $rhSuggestions[] = 'Analyser les retards avec le collaborateur lors d’un entretien factuel et vérifier l’adéquation des horaires ou contraintes opérationnelles.';
    } elseif ($lateRate >= 5) {
        $attentionLevel = 'À surveiller';
        $attentionClass = 'border-amber-200 bg-amber-50 text-amber-900';
        $rhSuggestions[] = 'Suivre l’évolution des retards sur la période suivante et rappeler les horaires applicables.';
    }
    if ($absenceRate >= 10) {
        $attentionLevel = 'Attention renforcée';
        $attentionClass = 'border-rose-200 bg-rose-50 text-rose-900';
        $rhSuggestions[] = 'Examiner séparément les absences justifiées et non justifiées, puis échanger avec la personne avant toute décision RH.';
    } elseif ($absenceRate >= 5) {
        if ($attentionLevel === 'Suivi normal') {
            $attentionLevel = 'À surveiller';
            $attentionClass = 'border-amber-200 bg-amber-50 text-amber-900';
        }
        $rhSuggestions[] = 'Contrôler les justificatifs et la récurrence des absences sans tirer de conclusion à partir du seul taux.';
    }
    if ($overtimeHours >= 20) {
        $rhSuggestions[] = 'Évaluer la charge de travail et envisager une redistribution, une récupération ou un ajustement de planification.';
    }
    if ($presenceRate >= 95 && $lateRate < 5 && $absenceRate < 5) {
        $rhSuggestions[] = 'Les indicateurs d’assiduité sont favorables sur cette période ; maintenir le suivi périodique.';
    }
}
if (!$rhSuggestions) {
    $rhSuggestions[] = 'Aucun signal quantitatif majeur ; compléter l’analyse avec les objectifs, le contexte du poste et les échanges avec le collaborateur.';
}

$pageTitle = 'Apercu rapport RH';
$activeMenu = 'rh';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[30px] bg-slate-950 p-8 text-white shadow-xl">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-300">Apercu avant export</p>
            <h1 class="mt-3 text-3xl font-semibold">Rapport RH - <?= rh_report_preview_h($periodLabel) ?></h1>
            <p class="mt-2 text-sm text-slate-300"><?= rh_report_preview_h($scopeLabel) ?> | du <?= rh_report_preview_h(rh_report_preview_date($startDate)) ?> au <?= rh_report_preview_h(rh_report_preview_date($endDate)) ?></p>
            <div class="mt-5 flex flex-wrap gap-3">
                <form method="post" action="export_rapport_rh_pdf.php" target="_blank">
                    <?php foreach (array_merge($_POST, $_GET) as $key => $value): ?>
                        <?php if (is_scalar($value)): ?>
                            <input type="hidden" name="<?= rh_report_preview_h((string) $key) ?>" value="<?= rh_report_preview_h((string) $value) ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ((array) ($scopeFilters['personnel_ids'] ?? []) as $selectedPersonnelId): ?>
                        <input type="hidden" name="personnel_ids[]" value="<?= (int) $selectedPersonnelId ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="personnel_id" value="<?= (int) (($scopeFilters['personnel_ids'][0] ?? 0)) ?>">
                    <input type="hidden" name="computed_late_rate" value="<?= rh_report_preview_h((string) $lateRate) ?>">
                    <input type="hidden" name="computed_absence_rate" value="<?= rh_report_preview_h((string) $absenceRate) ?>">
                    <input type="hidden" name="computed_average_late_minutes" value="<?= rh_report_preview_h((string) ($attendance['average_late_minutes'] ?? 0)) ?>">
                    <button class="rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-emerald-50" type="submit">
                        Exporter en PDF
                    </button>
                </form>
                <a href="dashboard_rh.php?view=analytique" class="rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">Modifier les criteres</a>
            </div>
        </div>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <?php
            $kpis = [
                ['Presence', rh_report_preview_number($attendance['presence_rate'] ?? 0, 1) . '%', 'bg-emerald-50 text-emerald-800'],
                ['Taux de retard', rh_report_preview_number($lateRate, 1) . '%', 'bg-rose-50 text-rose-800'],
                ['Absentéisme', rh_report_preview_number($absenceRate, 1) . '%', 'bg-orange-50 text-orange-800'],
                ['Demandes traitees', rh_report_preview_number($requests['processed'] ?? 0), 'bg-indigo-50 text-indigo-800'],
                ['Heures planifiees', rh_report_preview_number($schedule['events_hours'] ?? 0, 1), 'bg-amber-50 text-amber-800'],
                ['Heures sup', rh_report_preview_number($overtime['hours_total'] ?? 0, 1), 'bg-cyan-50 text-cyan-800'],
            ];
            ?>
            <?php foreach ($kpis as $kpi): ?>
                <div class="relative min-h-[154px] overflow-hidden rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_45px_rgba(15,23,42,.07)]">
                    <div class="absolute inset-x-6 top-0 h-1 rounded-b-full bg-[var(--brand-accent)]"></div>
                    <div class="inline-flex rounded-full px-3.5 py-1.5 text-xs font-bold uppercase tracking-[0.16em] <?= $kpi[2] ?>"><?= rh_report_preview_h($kpi[0]) ?></div>
                    <div class="mt-5 break-words text-3xl font-black leading-none text-slate-950"><?= rh_report_preview_h($kpi[1]) ?></div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="mt-6 rounded-3xl border p-6 shadow-sm <?= rh_report_preview_h($attentionClass) ?>">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-black uppercase tracking-[.2em] opacity-70">Aide à la décision RH</p>
                    <h2 class="mt-2 text-xl font-bold"><?= rh_report_preview_h($attentionLevel) ?></h2>
                    <p class="mt-1 text-sm opacity-75">Retard moyen constaté : <?= rh_report_preview_number($attendance['average_late_minutes'] ?? 0, 0) ?> minute(s).</p>
                </div>
                <i class="fas fa-chart-line text-3xl opacity-40"></i>
            </div>
            <div class="mt-4 grid gap-3 lg:grid-cols-2">
                <?php foreach ($rhSuggestions as $suggestion): ?>
                    <div class="rounded-2xl border border-current/10 bg-white/70 px-4 py-3 text-sm leading-6"><i class="fas fa-arrow-right mr-2 opacity-60"></i><?= rh_report_preview_h($suggestion) ?></div>
                <?php endforeach; ?>
            </div>
            <p class="mt-4 border-t border-current/10 pt-3 text-xs opacity-70">Ces suggestions sont des points d’analyse explicables. Toute décision reste humaine et doit tenir compte des justificatifs, du contexte professionnel et du droit applicable.</p>
        </section>

        <section class="mt-6 grid gap-5 xl:grid-cols-2">
            <div class="rounded-3xl bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-950">Perimetre strict</h2>
                <p class="mt-2 text-sm text-slate-500"><?= rh_report_preview_h($scopeLabel) ?></p>
                <p class="mt-1 text-sm text-slate-500">Collaborateurs inclus dans les calculs pointage/planning/HS/demandes : <?= rh_report_preview_number($personnelCount) ?></p>
                <p class="mt-3 rounded-2xl bg-emerald-50 px-4 py-3 text-xs font-semibold text-emerald-800">Tolérance pointage appliquee : arrivée <?= rh_report_preview_number($attendanceSettings['arrival_tolerance_minutes'] ?? 0) ?> min, déficit <?= rh_report_preview_number($attendanceSettings['departure_tolerance_minutes'] ?? 0) ?> min, HS <?= rh_report_preview_number($attendanceSettings['overtime_tolerance_minutes'] ?? 0) ?> min.</p>
            </div>
            <div class="rounded-3xl bg-white p-6 shadow-sm">
                <h2 class="text-xl font-semibold text-slate-950">Commentaires RH</h2>
                <p class="mt-2 text-sm text-slate-600"><strong>Synthese :</strong> <?= rh_report_preview_h($comments['comment_summary'] ?: 'Non renseigne') ?></p>
                <p class="mt-2 text-sm text-slate-600"><strong>Points d'attention :</strong> <?= rh_report_preview_h($comments['comment_risks'] ?: 'Non renseigne') ?></p>
                <p class="mt-2 text-sm text-slate-600"><strong>Actions :</strong> <?= rh_report_preview_h($comments['comment_actions'] ?: 'Non renseigne') ?></p>
            </div>
        </section>

        <section class="mt-6 rounded-3xl bg-white p-6 shadow-sm">
            <h2 class="text-xl font-semibold text-slate-950">Details du rapport</h2>
            <div class="mt-5 grid gap-5 xl:grid-cols-3">
                <div>
                    <h3 class="font-semibold text-slate-800">Pointage</h3>
                    <div class="mt-3 space-y-2 text-sm">
                        <?php foreach ((array) ($attendance['by_status'] ?? []) as $row): ?>
                            <div class="flex justify-between rounded-2xl bg-slate-50 px-4 py-2"><span><?= rh_report_preview_h($row['label'] ?? '') ?></span><strong><?= rh_report_preview_number($row['total'] ?? 0) ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Demandes RH</h3>
                    <div class="mt-3 space-y-2 text-sm">
                        <?php foreach ((array) ($requests['by_type'] ?? []) as $row): ?>
                            <div class="flex justify-between rounded-2xl bg-slate-50 px-4 py-2"><span><?= rh_report_preview_h($row['label'] ?? '') ?></span><strong><?= rh_report_preview_number($row['total'] ?? 0) ?></strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-800">Heures supplementaires</h3>
                    <div class="mt-3 space-y-2 text-sm">
                        <?php foreach ((array) ($overtime['top_employees'] ?? []) as $row): ?>
                            <div class="flex justify-between rounded-2xl bg-slate-50 px-4 py-2"><span><?= rh_report_preview_h($row['employee_name'] ?? '') ?></span><strong><?= rh_report_preview_number($row['hours_total'] ?? 0, 1) ?> h</strong></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
