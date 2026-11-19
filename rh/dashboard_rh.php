<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhDashboardController.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$controller = new \RhDashboardController($pdo);
$rhDashboardView = $controller->handle();
$dashboardData = $rhDashboardView['dashboardData'] ?? [];
$stats = $rhDashboardView['stats'] ?? [];
$rhAlerts = $rhDashboardView['alerts'] ?? [];
$rhRecentPending = $rhDashboardView['recentPending'] ?? [];
$rhPendingTotal = (int) ($rhDashboardView['pending_total'] ?? 0);
$rhLegal = $rhDashboardView['legal'] ?? [];
$rhExplanations = $rhDashboardView['explanations'] ?? [];
$rhAnalytics = $rhDashboardView['analytics'] ?? [];
$rhAttendanceAnalytics = $rhAnalytics['attendance'] ?? [];
$rhRequestAnalytics = $rhAnalytics['requests'] ?? [];
$rhScheduleAnalytics = $rhAnalytics['schedule'] ?? [];
$rhOvertimeAnalytics = $rhAnalytics['overtime'] ?? [];
$rhDashboardMode = strtolower(trim((string) ($_GET['view'] ?? 'classic')));
if (!in_array($rhDashboardMode, ['classic', 'statistique', 'analytique'], true)) {
    $rhDashboardMode = 'classic';
}

$attendanceSettings = rhGetAttendanceSettings($pdo);
try {
    $attendanceManagerForTolerance = (new \RhPayrollController($pdo))->service();
    $currentPeriod = date('Y-m');
    $periodStart = new DateTimeImmutable($currentPeriod . '-01');
    $periodEnd = $periodStart->modify('last day of this month');
    $lateRows = 0;
    $overtimeByEmployee = [];
    $dailyToleranceRows = [];

    for ($cursor = $periodStart; $cursor <= $periodEnd; $cursor = $cursor->modify('+1 day')) {
        $dateKey = $cursor->format('Y-m-d');
        $board = $attendanceManagerForTolerance->getAttendanceBoard($dateKey);
        $dailyPresent = 0;
        $dailyAbsence = 0;
        $dailyLate = 0;
        foreach (($board['items'] ?? []) as $item) {
            $employee = is_array($item['employee'] ?? null) ? $item['employee'] : [];
            $attendance = is_array($item['attendance'] ?? null) ? $item['attendance'] : [];
            $status = (string) ($attendance['attendance_status'] ?? 'present');
            if (in_array($status, ['present', 'mission', 'conge'], true)) {
                $dailyPresent++;
            }
            if ($status === 'absence') {
                $dailyAbsence++;
            }
            $metrics = rhEffectiveAttendanceMetrics($attendance, $attendanceSettings);
            if ((int) $metrics['late_minutes'] > 0) {
                $lateRows++;
                $dailyLate++;
            }
            $pid = (int) ($employee['id'] ?? 0);
            $effectiveOt = $metrics['has_time_pair'] ? (float) $metrics['overtime_hours'] : (float) ($attendance['overtime_hours'] ?? 0);
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
        $dailyToleranceRows[] = [
            'date' => $dateKey,
            'label' => $cursor->format('d/m'),
            'present' => $dailyPresent,
            'absence' => $dailyAbsence,
            'late' => $dailyLate,
        ];
    }

    usort($overtimeByEmployee, static fn($a, $b) => ($b['hours_total'] <=> $a['hours_total']));
    $effectiveOtTotal = array_sum(array_map(static fn($row) => (float) ($row['hours_total'] ?? 0), $overtimeByEmployee));
    $rhAttendanceAnalytics['late_rows'] = $lateRows;
    $rhAttendanceAnalytics['late_rate'] = ((int) ($rhAttendanceAnalytics['entries_count'] ?? 0)) > 0 ? ($lateRows / (int) $rhAttendanceAnalytics['entries_count']) * 100 : (float) ($rhAttendanceAnalytics['late_rate'] ?? 0);
    $rhAttendanceAnalytics['daily'] = $dailyToleranceRows;
    $rhOvertimeAnalytics['hours_total'] = $effectiveOtTotal;
    $rhOvertimeAnalytics['employees_count'] = count($overtimeByEmployee);
    $rhOvertimeAnalytics['top_employees'] = array_slice(array_values($overtimeByEmployee), 0, 8);
    $rhAnalytics['attendance'] = $rhAttendanceAnalytics;
    $rhAnalytics['overtime'] = $rhOvertimeAnalytics;
} catch (Throwable $ignored) {
    // En cas d'indisponibilite ponctuelle du service pointage, le dashboard conserve les statistiques initiales.
}
$rhReportServices = [];
$rhReportFonctions = [];
$rhReportStatuts = [];
$rhReportPersonnel = [];
try {
    $rhReportServices = $pdo->query('SELECT id_service AS id, lib_service AS label FROM service ORDER BY lib_service ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rhReportFonctions = $pdo->query('SELECT id_fonction AS id, lib_fonction AS label FROM fonction ORDER BY lib_fonction ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rhReportStatuts = $pdo->query('SELECT code AS id, label FROM rh_statuts WHERE is_active = 1 ORDER BY sort_order ASC, label ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rhReportPersonnel = $pdo->query("SELECT id_personnel_soignant AS id, nom_personnel_soignant AS label, matricule_personnel_soignant AS matricule FROM personnel WHERE date_sortie IS NULL OR date_sortie = '0000-00-00' ORDER BY nom_personnel_soignant ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $ignored) {
    $rhReportServices = [];
    $rhReportFonctions = [];
    $rhReportStatuts = [];
    $rhReportPersonnel = [];
}

$lastDashboardAuditAt = (int) ($_SESSION['rh_dashboard_last_audit_at'] ?? 0);
if ((time() - $lastDashboardAuditAt) >= 60) {
    $_SESSION['rh_dashboard_last_audit_at'] = time();
    \AuditTrail::log('page_view', [
        'module' => 'rh',
        'action' => 'dashboard_rh_view',
        'entity_type' => 'rh_dashboard',
        'entity_label' => 'Tableau de bord RH',
        'page_name' => 'dashboard_rh.php',
        'result' => 'success',
        'details' => [
            'total_personnel' => (int)($stats['total'] ?? 0),
            'active_personnel' => (int)($stats['active'] ?? 0),
            'services' => (int)($stats['services'] ?? 0),
            'pending_total' => $rhPendingTotal,
        ],
    ], $pdo);
}


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

function formatRhDate(?string $date): string
{
    if (empty($date) || $date === '0000-00-00') {
        return 'Non renseignee';
    }

    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y', $timestamp) : 'Non renseignee';
}

function rhDashNumber($value, int $decimals = 0): string
{
    return number_format((float) $value, $decimals, ',', ' ');
}

function rhDashPercent($value): string
{
    return number_format((float) $value, 1, ',', ' ') . '%';
}

function rhDashMax(array $rows, string $key): float
{
    $max = 0.0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $max = max($max, (float) ($row[$key] ?? 0));
        }
    }

    return max(1.0, $max);
}

$pageTitle = 'Tableau de bord RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen w-full max-w-full overflow-x-clip bg-[radial-gradient(circle_at_top_left,_rgba(255,204,32,0.16),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(15,23,42,0.08),_transparent_34%),linear-gradient(180deg,_#ffffff_0%,_#f8fafc_100%)] pb-12">
    <div class="container mx-auto w-full max-w-full px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-yellow-300/40 bg-black px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-24 h-32 w-32 rounded-full bg-[#ffcc20]/25 blur-2xl"></div>
            <div class="absolute bottom-0 left-0 h-1.5 w-2/5 bg-[#ffcc20]"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <div class="mb-3 flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[#ffcc20]">Finea · Ressources humaines</p>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-bell mr-2 text-[#ffcc20]"></i>
                            <?= number_format($rhPendingTotal, 0, ',', ' ') ?> action<?= $rhPendingTotal > 1 ? 's' : '' ?> a verifier
                        </span>
                    </div>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Pilotage centralisé du personnel BANAMUR</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Le module RH consolide les effectifs, les affectations de service, les fonctions et les recrutements recents sans sortir du shell de navigation decaissement.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="liste_personnel_rh.php?scope=all" class="inline-flex items-center rounded-2xl bg-[#ffcc20] px-5 py-3 text-sm font-bold text-black shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-yellow-300">
                        <i class="fas fa-id-badge mr-2"></i>
                        Voir tout le personnel
                    </a>
                    <a href="integration_personnel_rh.php" class="inline-flex items-center rounded-2xl border border-[#ffcc20]/60 bg-[#ffcc20]/10 px-5 py-3 text-sm font-semibold text-[#ffcc20] transition hover:-translate-y-0.5 hover:bg-[#ffcc20]/20">
                        <i class="fas fa-user-plus mr-2"></i>
                        Integrer un collaborateur
                    </a>
                    <a href="ordres_mission_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-clipboard-list mr-2"></i>
                        Ordres de mission
                    </a>
                    <a href="validation_demandes_rh.php?scope=administrative" class="inline-flex items-center rounded-2xl border border-emerald-300/40 bg-emerald-400/15 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-400/25">
                        <i class="fas fa-clipboard-check mr-2"></i>
                        Validations
                        <?php if ((int) ($rhLegal['total'] ?? 0) > 0): ?>
                            <span class="ml-2 rounded-full bg-white px-2 py-0.5 text-xs font-bold text-emerald-700"><?= (int) ($rhLegal['total'] ?? 0) ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="demandes_explication_rh.php?status=open" class="inline-flex items-center rounded-2xl border border-rose-300/40 bg-rose-400/15 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-400/25">
                        <i class="fas fa-comment-dots mr-2"></i>
                        Explications
                        <?php if ((int) ($rhExplanations['pending_response'] ?? 0) > 0): ?>
                            <span class="ml-2 rounded-full bg-white px-2 py-0.5 text-xs font-bold text-rose-700"><?= (int) ($rhExplanations['pending_response'] ?? 0) ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="historique_points_financiers_rh.php" class="inline-flex items-center rounded-2xl bg-amber-300 px-5 py-3 text-sm font-bold text-slate-950">Points RH : historique, états et exports</a>
                    <a href="pointage_journalier_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-user-check mr-2"></i>
                        Pointage journalier
                    </a>
                    <a href="paie_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-file-invoice-dollar mr-2"></i>
                        Paie
                    </a>
                    <a href="contrats_employes_rh.php" class="inline-flex items-center rounded-2xl border border-amber-300/40 bg-amber-400/15 px-5 py-3 text-sm font-semibold text-white transition hover:bg-amber-400/25">
                        <i class="fas fa-file-signature mr-2"></i>
                        Contrats employes
                    </a>
                    <a href="parametrage_rh.php" class="inline-flex items-center rounded-2xl border border-indigo-400/50 bg-indigo-500/20 px-5 py-3 text-sm font-semibold text-indigo-200 transition hover:bg-indigo-500/30">
                        <i class="fas fa-cog mr-2"></i>
                        Paramétrage RH
                    </a>
                    <a href="parametrage_paie_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders-h mr-2"></i>
                        Regles contrats
                    </a>
                    <a href="parametrage_contrat_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-signature mr-2"></i>
                        Signataires RH
                    </a>
                    <a href="feries_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-calendar-times mr-2"></i>
                        Feriés
                    </a>
                    <a href="../selection_portail.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-th-large mr-2"></i>
                        Portail
                    </a>
                </div>
            </div>
        </div>

        <?php
        $dashboardTabs = [
            'classic' => ['label' => 'Tableau de bord classique', 'href' => 'dashboard_rh.php', 'icon' => 'fa-gauge-high', 'description' => 'Acces rapides, alertes et suivi courant RH.'],
            'statistique' => ['label' => 'Tableau de bord statistique', 'href' => 'dashboard_rh.php?view=statistique', 'icon' => 'fa-chart-column', 'description' => 'Graphes assiduite, demandes, taches et heures sup.'],
            'analytique' => ['label' => 'Tableau de bord analytique', 'href' => 'dashboard_rh.php?view=analytique', 'icon' => 'fa-file-export', 'description' => 'Analyse complete et exports de rapports RH.'],
        ];
        ?>
        <section class="mt-6 grid gap-4 lg:grid-cols-3">
            <?php foreach ($dashboardTabs as $key => $tab): ?>
                <a href="<?= htmlspecialchars($tab['href']) ?>" class="rounded-[26px] border p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 <?= $rhDashboardMode === $key ? 'border-slate-950 bg-slate-950 text-white' : 'border-white/80 bg-white/90 text-slate-800 hover:bg-slate-50' ?>">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl <?= $rhDashboardMode === $key ? 'bg-white/15 text-emerald-200' : 'bg-emerald-50 text-emerald-700' ?>">
                            <i class="fas <?= htmlspecialchars($tab['icon']) ?>"></i>
                        </span>
                        <div>
                            <h2 class="text-base font-semibold"><?= htmlspecialchars($tab['label']) ?></h2>
                            <p class="mt-1 text-sm <?= $rhDashboardMode === $key ? 'text-slate-300' : 'text-slate-500' ?>"><?= htmlspecialchars($tab['description']) ?></p>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>

        <section class="mt-6 grid gap-4 lg:grid-cols-4">
            <?php foreach ($rhAlerts as $alert): ?>
                <?php
                $accent = (string) ($alert['accent'] ?? 'emerald');
                $cardClass = match ($accent) {
                    'amber' => 'border-amber-200 bg-amber-50/90 text-amber-800',
                    'rose' => 'border-rose-200 bg-rose-50/90 text-rose-800',
                    'cyan' => 'border-cyan-200 bg-cyan-50/90 text-cyan-800',
                    default => 'border-emerald-200 bg-emerald-50/90 text-emerald-800',
                };
                $iconClass = match ($accent) {
                    'amber' => 'bg-amber-100 text-amber-700',
                    'rose' => 'bg-rose-100 text-rose-700',
                    'cyan' => 'bg-cyan-100 text-cyan-700',
                    default => 'bg-emerald-100 text-emerald-700',
                };
                ?>
                <a href="<?= htmlspecialchars((string) ($alert['href'] ?? '#')) ?>" class="group flex min-h-[148px] flex-col justify-between rounded-[26px] border p-5 shadow-[0_16px_45px_rgba(15,23,42,0.06)] transition hover:-translate-y-0.5 hover:shadow-[0_20px_55px_rgba(15,23,42,0.10)] <?= $cardClass ?>">
                    <div class="flex items-start justify-between gap-4">
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl <?= $iconClass ?>">
                            <i class="fas <?= htmlspecialchars((string) ($alert['icon'] ?? 'fa-bell')) ?>"></i>
                        </span>
                        <span class="rounded-full bg-white px-3 py-1 text-2xl font-bold text-slate-950 shadow-sm"><?= number_format((int) ($alert['count'] ?? 0), 0, ',', ' ') ?></span>
                    </div>
                    <div class="mt-4">
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="text-sm font-bold uppercase tracking-[0.16em]"><?= htmlspecialchars((string) ($alert['label'] ?? 'A verifier')) ?></h2>
                            <i class="fas fa-arrow-right text-xs opacity-60 transition group-hover:translate-x-1"></i>
                        </div>
                        <p class="mt-2 text-sm leading-5 text-slate-600"><?= htmlspecialchars((string) ($alert['description'] ?? '')) ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>

        <?php
        $attendanceByStatus = is_array($rhAttendanceAnalytics['by_status'] ?? null) ? $rhAttendanceAnalytics['by_status'] : [];
        $attendanceDaily = is_array($rhAttendanceAnalytics['daily'] ?? null) ? $rhAttendanceAnalytics['daily'] : [];
        $attendanceMonthly = is_array($rhAttendanceAnalytics['monthly_trend'] ?? null) ? $rhAttendanceAnalytics['monthly_trend'] : [];
        $requestByType = is_array($rhRequestAnalytics['by_type'] ?? null) ? $rhRequestAnalytics['by_type'] : [];
        $requestByStatus = is_array($rhRequestAnalytics['by_status'] ?? null) ? $rhRequestAnalytics['by_status'] : [];
        $scheduleByStatus = is_array($rhScheduleAnalytics['by_status'] ?? null) ? $rhScheduleAnalytics['by_status'] : [];
        $scheduleTopBusy = is_array($rhScheduleAnalytics['top_busy'] ?? null) ? $rhScheduleAnalytics['top_busy'] : [];
        $overtimeTopEmployees = is_array($rhOvertimeAnalytics['top_employees'] ?? null) ? $rhOvertimeAnalytics['top_employees'] : [];
        $maxDailyPresent = rhDashMax($attendanceDaily, 'present');
        $maxMonthlyOvertime = rhDashMax($attendanceMonthly, 'overtime_hours');
        $maxRequestType = rhDashMax($requestByType, 'total');
        $maxScheduleBusy = rhDashMax($scheduleTopBusy, 'hours_count');
        $maxOvertime = rhDashMax($overtimeTopEmployees, 'hours_total');
        $presenceRate = (float) ($rhAttendanceAnalytics['presence_rate'] ?? 0);
        $lateRate = (float) ($rhAttendanceAnalytics['late_rate'] ?? 0);
        $processedRequests = (int) ($rhRequestAnalytics['processed'] ?? 0);
        $overtimeHours = (float) ($rhOvertimeAnalytics['hours_total'] ?? 0);
        $scheduleHours = (float) ($rhScheduleAnalytics['events_hours'] ?? 0);
        ?>
        <?php if ($rhDashboardMode !== 'classic'): ?>
            <section class="mt-8 rounded-[32px] border border-white/80 bg-white/95 p-6 shadow-[0_20px_65px_rgba(15,23,42,0.08)] backdrop-blur">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600"><?= $rhDashboardMode === 'analytique' ? 'Tableau de bord analytique' : 'Tableau de bord statistique' ?></p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950"><?= $rhDashboardMode === 'analytique' ? 'Rapports, analyses et commentaires RH' : 'Lecture operationnelle RH du mois' ?></h2>
                        <p class="mt-2 max-w-3xl text-sm text-slate-500">
                            <?= $rhDashboardMode === 'analytique'
                                ? 'Generez des rapports complets et consolidez les commentaires RH pour les points mensuels, annuels ou periodiques.'
                                : 'Assiduite, demandes traitees, emploi du temps, taches et heures supplementaires sont consolides pour aider les RH a voir vite les points d attention.' ?>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
                        <a href="pointage_journalier_rh.php" class="rounded-full bg-emerald-50 px-3 py-2 text-emerald-700 hover:bg-emerald-100">Pointage</a>
                        <a href="validation_demandes_rh.php" class="rounded-full bg-indigo-50 px-3 py-2 text-indigo-700 hover:bg-indigo-100">Demandes RH</a>
                        <a href="parametrage_horaires_emploi_du_temps.php" class="rounded-full bg-amber-50 px-3 py-2 text-amber-700 hover:bg-amber-100">Horaires</a>
                    </div>
                </div>

                <?php if ($rhDashboardMode === 'analytique'): ?>
                    <?php if (($_GET['report_error'] ?? '') === 'personnel_required'): ?>
                        <div class="mt-6 flex items-start gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800">
                            <i class="fas fa-circle-exclamation mt-0.5"></i>
                            <span>Le rapport analytique est strictement individuel. Sélectionnez obligatoirement un collaborateur avant de continuer.</span>
                        </div>
                    <?php endif; ?>
                    <form method="post" action="rapport_rh.php" class="mt-6 rounded-[32px] border border-slate-200 bg-slate-50/90 p-6 shadow-inner lg:p-7">
                        <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-950">Exporter un rapport RH complet</h3>
                                <p class="mt-1 text-sm text-slate-500">Rapport journalier, mensuel, annuel ou periode libre avec commentaires RH integres au PDF.</p>
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                                <i class="fas fa-eye mr-2 text-emerald-300"></i>
                                Visualiser le rapport
                            </button>
                        </div>
                        <input type="hidden" name="report_scope" value="personnel">
                        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Type de rapport</span>
                                <select name="report_mode" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                                    <option value="monthly">Mensuel</option>
                                    <option value="daily">Journalier</option>
                                    <option value="annual">Annuel</option>
                                    <option value="custom">Periode libre</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Jour</span>
                                <input type="date" name="report_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Mois</span>
                                <input type="month" name="report_month" value="<?= htmlspecialchars(date('Y-m')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Annee</span>
                                <input type="number" name="report_year" value="<?= htmlspecialchars(date('Y')) ?>" min="2000" max="2100" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm">
                            </label>
                            <div class="block md:col-span-2 xl:col-span-4" data-personnel-picker>
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Collaborateur du rapport individuel</span>
                                <input type="hidden" name="personnel_id" value="" data-personnel-value>
                                <div class="relative mt-1">
                                    <div class="flex min-h-[52px] w-full flex-wrap items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm transition focus-within:border-[var(--brand-accent)] focus-within:ring-4 focus-within:ring-amber-100" data-personnel-control>
                                        <div class="contents" data-personnel-badges></div>
                                        <input type="search" autocomplete="off" placeholder="Rechercher un nom ou un matricule…" class="min-w-[220px] flex-1 border-0 bg-transparent px-1 py-1.5 text-sm text-slate-900 outline-none ring-0" data-personnel-search aria-label="Rechercher des collaborateurs" aria-expanded="false">
                                        <i class="fas fa-chevron-down px-2 text-xs text-slate-400"></i>
                                    </div>
                                    <div class="absolute inset-x-0 top-full z-50 mt-2 hidden max-h-72 overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl" data-personnel-options>
                                        <?php foreach ($rhReportPersonnel as $person): ?>
                                            <?php $personLabel = trim((string) ($person['label'] ?? '')) . (!empty($person['matricule']) ? (' - ' . (string) $person['matricule']) : ''); ?>
                                            <button type="button" data-personnel-option data-id="<?= (int) ($person['id'] ?? 0) ?>" data-label="<?= htmlspecialchars($personLabel) ?>" data-search="<?= htmlspecialchars(mb_strtolower($personLabel, 'UTF-8')) ?>" class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left text-sm text-slate-700 transition hover:bg-amber-50 hover:text-slate-950">
                                                <span class="truncate"><?= htmlspecialchars($personLabel) ?></span><i class="fas fa-plus text-xs text-amber-500"></i>
                                            </button>
                                        <?php endforeach; ?>
                                        <div class="hidden px-4 py-6 text-center text-sm text-slate-500" data-personnel-empty>Aucun collaborateur trouvé.</div>
                                    </div>
                                </div>
                                <span class="mt-1.5 block text-[11px] text-slate-500">Une seule personne est incluse. Choisir un autre collaborateur remplace automatiquement la sélection.</span>
                                <span class="mt-1 hidden text-xs font-semibold text-rose-600" data-personnel-error>Veuillez sélectionner le collaborateur concerné avant de générer le rapport.</span>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Debut</span>
                                    <input type="date" name="start_date" value="<?= htmlspecialchars(date('Y-m-01')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                </label>
                                <label class="block">
                                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Fin</span>
                                    <input type="date" name="end_date" value="<?= htmlspecialchars(date('Y-m-t')) ?>" class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-3 py-2 text-sm">
                                </label>
                            </div>
                        </div>
                        <div class="mt-4 grid gap-3 lg:grid-cols-3">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Synthese RH</span>
                                <textarea name="comment_summary" rows="3" placeholder="Conclusion generale, climat social, faits marquants..." class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm"></textarea>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Points d'attention</span>
                                <textarea name="comment_risks" rows="3" placeholder="Retards recurrents, absences, surcharge, contrats a regulariser..." class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm"></textarea>
                            </label>
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Actions prevues</span>
                                <textarea name="comment_actions" rows="3" placeholder="Relances, corrections de pointage, entretiens, actions RH..." class="mt-1 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm"></textarea>
                            </label>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($rhDashboardMode === 'statistique'): ?>
                    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <div class="rounded-3xl border border-emerald-100 bg-emerald-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-emerald-800">Taux de presence</span>
                                <i class="fas fa-user-check text-emerald-600"></i>
                            </div>
                            <div class="mt-4 text-4xl font-bold text-slate-950"><?= htmlspecialchars(rhDashPercent($presenceRate)) ?></div>
                            <div class="mt-3 h-2 rounded-full bg-white">
                                <div class="h-2 rounded-full bg-emerald-500" style="width: <?= max(0, min(100, $presenceRate)) ?>%"></div>
                            </div>
                            <p class="mt-3 text-xs text-slate-600"><?= rhDashNumber((int) ($rhAttendanceAnalytics['present_rows'] ?? 0)) ?> pointages presents / missions / conges</p>
                        </div>
                        <div class="rounded-3xl border border-rose-100 bg-rose-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-rose-800">Retards detectes</span>
                                <i class="fas fa-clock text-rose-600"></i>
                            </div>
                            <div class="mt-4 text-4xl font-bold text-slate-950"><?= rhDashNumber((int) ($rhAttendanceAnalytics['late_rows'] ?? 0)) ?></div>
                            <p class="mt-3 text-xs text-slate-600"><?= htmlspecialchars(rhDashPercent($lateRate)) ?> des lignes de pointage du mois</p>
                        </div>
                        <div class="rounded-3xl border border-indigo-100 bg-indigo-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-indigo-800">Demandes traitees</span>
                                <i class="fas fa-clipboard-check text-indigo-600"></i>
                            </div>
                            <div class="mt-4 text-4xl font-bold text-slate-950"><?= rhDashNumber($processedRequests) ?></div>
                            <p class="mt-3 text-xs text-slate-600"><?= rhDashNumber((int) ($rhRequestAnalytics['approved'] ?? 0)) ?> validees, <?= rhDashNumber((int) ($rhRequestAnalytics['rejected'] ?? 0)) ?> refusees</p>
                        </div>
                        <div class="rounded-3xl border border-amber-100 bg-amber-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-amber-800">Emploi du temps</span>
                                <i class="fas fa-calendar-alt text-amber-600"></i>
                            </div>
                            <div class="mt-4 text-4xl font-bold text-slate-950"><?= rhDashNumber((int) ($rhScheduleAnalytics['events_total'] ?? 0)) ?></div>
                            <p class="mt-3 text-xs text-slate-600"><?= rhDashNumber($scheduleHours, 1) ?> h planifiees ou renseignees</p>
                        </div>
                        <div class="rounded-3xl border border-cyan-100 bg-cyan-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <span class="text-sm font-semibold text-cyan-800">Heures sup</span>
                                <i class="fas fa-business-time text-cyan-600"></i>
                            </div>
                            <div class="mt-4 text-4xl font-bold text-slate-950"><?= rhDashNumber($overtimeHours, 1) ?></div>
                            <p class="mt-3 text-xs text-slate-600"><?= rhDashNumber((int) ($rhOvertimeAnalytics['employees_count'] ?? 0)) ?> collaborateur(s) concernes</p>
                        </div>
                    </div>

                    <div class="mt-6 grid min-w-0 gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <div class="min-w-0 rounded-3xl border border-slate-100 bg-slate-50/80 p-5">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <h3 class="text-lg font-semibold text-slate-950">Assiduite sur les derniers jours</h3>
                                    <p class="mt-1 text-sm text-slate-500">Presence, absences et retards issus du pointage journalier.</p>
                                </div>
                                <a href="pointage_journalier_rh.php" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Corriger</a>
                            </div>
                            <div class="mt-5 grid min-h-[220px] items-end gap-2" style="grid-template-columns: repeat(<?= max(1, min(14, count($attendanceDaily))) ?>, minmax(0, 1fr));">
                                <?php if (empty($attendanceDaily)): ?>
                                    <div class="col-span-full rounded-2xl border border-dashed border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Aucune donnee de pointage sur la periode.</div>
                                <?php endif; ?>
                                <?php foreach ($attendanceDaily as $day): ?>
                                    <?php
                                    $presentHeight = max(8, (int) round(((float) ($day['present'] ?? 0) / $maxDailyPresent) * 150));
                                    $lateHeight = max(0, (int) round(((float) ($day['late'] ?? 0) / $maxDailyPresent) * 150));
                                    $absentHeight = max(0, (int) round(((float) ($day['absent'] ?? 0) / $maxDailyPresent) * 150));
                                    ?>
                                    <div class="flex flex-col items-center justify-end gap-2">
                                        <div class="flex h-[160px] w-full max-w-[34px] items-end justify-center gap-1">
                                            <div class="w-2 rounded-t-full bg-emerald-500" style="height: <?= $presentHeight ?>px"></div>
                                            <div class="w-2 rounded-t-full bg-rose-400" style="height: <?= max(4, $absentHeight) ?>px"></div>
                                            <div class="w-2 rounded-t-full bg-amber-400" style="height: <?= max(4, $lateHeight) ?>px"></div>
                                        </div>
                                        <span class="text-[11px] font-semibold text-slate-500"><?= htmlspecialchars((string) ($day['label'] ?? '')) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-3 text-xs font-semibold text-slate-600">
                                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-emerald-500"></span>Presence</span>
                                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-rose-400"></span>Absence / maladie</span>
                                <span class="inline-flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-full bg-amber-400"></span>Retard</span>
                            </div>
                        </div>

                        <div class="min-w-0 rounded-3xl border border-slate-100 bg-white p-5">
                            <h3 class="text-lg font-semibold text-slate-950">Repartition du pointage</h3>
                            <p class="mt-1 text-sm text-slate-500">Lecture rapide des statuts saisis ce mois.</p>
                            <div class="mt-5 space-y-3">
                                <?php if (empty($attendanceByStatus)): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Aucun statut de pointage a afficher.</div>
                                <?php endif; ?>
                                <?php foreach ($attendanceByStatus as $row): ?>
                                    <?php $width = (int) round(((int) ($row['total'] ?? 0) / max(1, (int) ($rhAttendanceAnalytics['total_rows'] ?? 0))) * 100); ?>
                                    <div>
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="font-semibold text-slate-700"><?= htmlspecialchars((string) ($row['label'] ?? 'Statut')) ?></span>
                                            <span class="text-slate-500"><?= rhDashNumber((int) ($row['total'] ?? 0)) ?></span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-slate-100">
                                            <div class="h-2 rounded-full bg-slate-900" style="width: <?= max(3, min(100, $width)) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 grid gap-5 xl:grid-cols-3">
                        <div class="rounded-3xl border border-indigo-100 bg-indigo-50/70 p-5">
                            <h3 class="text-lg font-semibold text-slate-950">Demandes RH traitees</h3>
                            <p class="mt-1 text-sm text-slate-500">Validation, refus et annulations sur le mois.</p>
                            <div class="mt-5 space-y-3">
                                <?php foreach ($requestByStatus as $row): ?>
                                    <?php $width = (int) round(((int) ($row['total'] ?? 0) / max(1, $processedRequests + (int) ($rhRequestAnalytics['submitted'] ?? 0))) * 100); ?>
                                    <div class="rounded-2xl bg-white px-4 py-3">
                                        <div class="flex items-center justify-between text-sm">
                                            <span class="font-semibold text-slate-800"><?= htmlspecialchars((string) ($row['label'] ?? 'Statut')) ?></span>
                                            <span class="font-bold text-indigo-700"><?= rhDashNumber((int) ($row['total'] ?? 0)) ?></span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-indigo-100">
                                            <div class="h-2 rounded-full bg-indigo-600" style="width: <?= max(4, min(100, $width)) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($requestByStatus)): ?>
                                    <div class="rounded-2xl bg-white p-5 text-sm text-slate-500">Aucune demande RH sur la periode.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="rounded-3xl border border-cyan-100 bg-cyan-50/70 p-5">
                            <h3 class="text-lg font-semibold text-slate-950">Types les plus traites</h3>
                            <p class="mt-1 text-sm text-slate-500">Ce graphe montre ce qui mobilise le plus les RH.</p>
                            <div class="mt-5 space-y-3">
                                <?php foreach ($requestByType as $row): ?>
                                    <?php $width = (int) round(((int) ($row['total'] ?? 0) / $maxRequestType) * 100); ?>
                                    <div>
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="truncate font-semibold text-slate-700"><?= htmlspecialchars((string) ($row['label'] ?? 'Demande')) ?></span>
                                            <span class="text-cyan-700"><?= rhDashNumber((int) ($row['total'] ?? 0)) ?></span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-white">
                                            <div class="h-2 rounded-full bg-cyan-600" style="width: <?= max(4, min(100, $width)) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($requestByType)): ?>
                                    <div class="rounded-2xl bg-white p-5 text-sm text-slate-500">Aucun traitement finalise sur le mois.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="rounded-3xl border border-amber-100 bg-amber-50/70 p-5">
                            <h3 class="text-lg font-semibold text-slate-950">Heures supplementaires</h3>
                            <p class="mt-1 text-sm text-slate-500">Collaborateurs les plus concernes par les surcharges horaires.</p>
                            <div class="mt-5 space-y-3">
                                <?php foreach ($overtimeTopEmployees as $row): ?>
                                    <?php $width = (int) round(((float) ($row['hours_total'] ?? 0) / $maxOvertime) * 100); ?>
                                    <div>
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="truncate font-semibold text-slate-700"><?= htmlspecialchars((string) ($row['employee_name'] ?? 'Collaborateur')) ?></span>
                                            <span class="text-amber-700"><?= rhDashNumber((float) ($row['hours_total'] ?? 0), 1) ?> h</span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-white">
                                            <div class="h-2 rounded-full bg-amber-500" style="width: <?= max(4, min(100, $width)) ?>%"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($overtimeTopEmployees)): ?>
                                    <div class="rounded-2xl bg-white p-5 text-sm text-slate-500">Aucune heure supplementaire renseignee.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 grid min-w-0 gap-5 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.1fr)]">
                        <div class="min-w-0 rounded-3xl border border-slate-100 bg-slate-950 p-5 text-white">
                            <h3 class="text-lg font-semibold">Taches et emploi du temps</h3>
                            <p class="mt-1 text-sm text-slate-300">Evenements planifies, statuts et parametrage horaires.</p>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl bg-white/10 p-4">
                                    <div class="text-3xl font-bold"><?= rhDashNumber((int) ($rhScheduleAnalytics['events_total'] ?? 0)) ?></div>
                                    <div class="mt-1 text-xs text-slate-300">evenements renseignes</div>
                                </div>
                                <div class="rounded-2xl bg-white/10 p-4">
                                    <div class="text-3xl font-bold"><?= rhDashNumber((int) ($rhScheduleAnalytics['employees_with_events'] ?? 0)) ?></div>
                                    <div class="mt-1 text-xs text-slate-300">collaborateurs planifies</div>
                                </div>
                                <div class="rounded-2xl bg-white/10 p-4">
                                    <div class="text-3xl font-bold"><?= rhDashNumber((int) ($rhScheduleAnalytics['work_windows_personnel'] ?? 0)) ?></div>
                                    <div class="mt-1 text-xs text-slate-300">horaires individuels</div>
                                </div>
                                <div class="rounded-2xl bg-white/10 p-4">
                                    <div class="text-3xl font-bold"><?= rhDashNumber((int) ($rhScheduleAnalytics['work_windows_services'] ?? 0)) ?></div>
                                    <div class="mt-1 text-xs text-slate-300">horaires par service</div>
                                </div>
                            </div>
                            <div class="mt-5 space-y-2">
                                <?php foreach ($scheduleByStatus as $row): ?>
                                    <div class="flex items-center justify-between rounded-2xl bg-white/10 px-4 py-3 text-sm">
                                        <span><?= htmlspecialchars((string) ($row['label'] ?? 'Statut')) ?></span>
                                        <strong><?= rhDashNumber((int) ($row['total'] ?? 0)) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="min-w-0 rounded-3xl border border-slate-100 bg-white p-5">
                            <h3 class="text-lg font-semibold text-slate-950">Charge planifiee par collaborateur</h3>
                            <p class="mt-1 text-sm text-slate-500">Aide a detecter les agendas trop charges avant incident.</p>
                            <div class="mt-5 space-y-3">
                                <?php foreach ($scheduleTopBusy as $row): ?>
                                    <?php $width = (int) round(((float) ($row['hours_count'] ?? 0) / $maxScheduleBusy) * 100); ?>
                                    <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3">
                                        <div class="flex items-center justify-between gap-3 text-sm">
                                            <span class="truncate font-semibold text-slate-800"><?= htmlspecialchars((string) ($row['employee_name'] ?? 'Collaborateur')) ?></span>
                                            <span class="text-slate-500"><?= rhDashNumber((float) ($row['hours_count'] ?? 0), 1) ?> h</span>
                                        </div>
                                        <div class="mt-2 h-2 rounded-full bg-white">
                                            <div class="h-2 rounded-full bg-slate-900" style="width: <?= max(4, min(100, $width)) ?>%"></div>
                                        </div>
                                        <div class="mt-2 text-xs text-slate-500"><?= rhDashNumber((int) ($row['events_count'] ?? 0)) ?> evenement(s)</div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($scheduleTopBusy)): ?>
                                    <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Aucune charge planifiee sur le mois.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6 rounded-3xl border border-slate-100 bg-slate-50/80 p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-slate-950">Tendance mensuelle</h3>
                                <p class="mt-1 text-sm text-slate-500">Presence et heures supplementaires sur six mois.</p>
                            </div>
                        </div>
                        <div class="mt-5 grid gap-3 md:grid-cols-6">
                            <?php foreach ($attendanceMonthly as $month): ?>
                                <?php
                                $presence = (float) ($month['presence_rate'] ?? 0);
                                $otHeight = (int) round(((float) ($month['overtime_hours'] ?? 0) / $maxMonthlyOvertime) * 70);
                                ?>
                                <div class="rounded-2xl bg-white p-4">
                                    <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars((string) ($month['label'] ?? '')) ?></div>
                                    <div class="mt-3 flex h-24 items-end gap-2">
                                        <div class="w-1/2 rounded-t-xl bg-emerald-500" style="height: <?= max(6, (int) round($presence)) ?>px"></div>
                                        <div class="w-1/2 rounded-t-xl bg-amber-400" style="height: <?= max(6, $otHeight) ?>px"></div>
                                    </div>
                                    <div class="mt-3 text-xs text-slate-600"><?= htmlspecialchars(rhDashPercent($presence)) ?> presence</div>
                                    <div class="text-xs text-slate-500"><?= rhDashNumber((float) ($month['overtime_hours'] ?? 0), 1) ?> h sup</div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($rhDashboardMode === 'classic'): ?>
            <?php if (!empty($rhRecentPending)): ?>
                <section class="mt-5 rounded-[26px] border border-white/80 bg-white/90 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.06)] backdrop-blur">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-emerald-600">Priorites RH</p>
                            <h2 class="mt-1 text-xl font-semibold text-slate-950">Dernieres demandes a valider</h2>
                        </div>
                        <a href="validation_demandes_rh.php" class="inline-flex items-center text-sm font-semibold text-emerald-700 hover:text-emerald-800">
                            Tout traiter <i class="fas fa-arrow-right ml-2 text-xs"></i>
                        </a>
                    </div>
                    <div class="mt-4 grid gap-3 lg:grid-cols-5">
                        <?php foreach ($rhRecentPending as $pending): ?>
                            <a href="validation_demandes_rh.php?id=<?= (int) ($pending['id'] ?? 0) ?>" class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-3 transition hover:border-emerald-200 hover:bg-emerald-50">
                                <div class="truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($pending['label'] ?? 'Demande RH')) ?></div>
                                <div class="mt-1 truncate text-xs text-slate-500"><?= htmlspecialchars((string) ($pending['requester'] ?? '')) ?></div>
                                <div class="mt-3 flex items-center justify-between gap-2 text-xs">
                                    <span class="rounded-full bg-white px-2 py-1 font-semibold text-emerald-700"><?= htmlspecialchars((string) ($pending['stage'] ?? 'En attente')) ?></span>
                                    <span class="text-slate-400"><?= htmlspecialchars(formatRhDate(substr((string) ($pending['submitted_at'] ?? ''), 0, 10))) ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

            <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-5">
                <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                    <p class="text-sm font-medium text-slate-500">Effectif global</p>
                    <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int) $stats['total'], 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-500">Collaborateurs recenses</p>
                </div>
                <div class="rounded-[28px] border border-emerald-100 bg-gradient-to-br from-emerald-50 to-white p-6 shadow-[0_16px_50px_rgba(16,185,129,0.10)]">
                    <p class="text-sm font-medium text-emerald-700">En poste</p>
                    <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int) $stats['active'], 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-500">Sans date de sortie</p>
                </div>
                <div class="rounded-[28px] border border-amber-100 bg-gradient-to-br from-amber-50 to-white p-6 shadow-[0_16px_50px_rgba(245,158,11,0.10)]">
                    <p class="text-sm font-medium text-amber-700">Sorties</p>
                    <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int) $stats['inactive'], 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-500">Personnel archive</p>
                </div>
                <div class="rounded-[28px] border border-sky-100 bg-gradient-to-br from-sky-50 to-white p-6 shadow-[0_16px_50px_rgba(14,165,233,0.10)]">
                    <p class="text-sm font-medium text-sky-700">Recrutements annee</p>
                    <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int) $stats['currentYearHires'], 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-500">Depuis janvier</p>
                </div>
                <div class="rounded-[28px] border border-indigo-100 bg-gradient-to-br from-indigo-50 to-white p-6 shadow-[0_16px_50px_rgba(99,102,241,0.10)]">
                    <p class="text-sm font-medium text-indigo-700">Services couverts</p>
                    <p class="mt-4 text-4xl font-semibold text-slate-950"><?= number_format((int) $stats['services'], 0, ',', ' ') ?></p>
                    <p class="mt-2 text-sm text-slate-500">Services actifs</p>
                </div>
            </div>

            <div class="mt-8 grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,0.7fr)]">
                <section class="min-w-0 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Repartition</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Services les plus representes</h2>
                        </div>
                        <a href="liste_personnel_rh.php" class="text-sm font-semibold text-emerald-700 hover:text-emerald-800">Voir toute la liste</a>
                    </div>
                    <div class="mt-6 space-y-4">
                        <?php foreach ($dashboardData['services'] as $service): ?>
                            <?php $width = (int) min(100, round(((int) $service['total'] / max(1, (int) $stats['total'])) * 100)); ?>
                            <div>
                                <div class="mb-2 flex items-center justify-between gap-3 text-sm">
                                    <span class="font-medium text-slate-700"><?= htmlspecialchars((string) $service['label']) ?></span>
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600"><?= (int) $service['total'] ?> pers.</span>
                                </div>
                                <div class="h-3 rounded-full bg-slate-100">
                                    <div class="h-3 rounded-full bg-gradient-to-r from-emerald-500 to-cyan-500" style="width: <?= $width ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <aside class="min-w-0 rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-300">Acces rapides</p>
                    <h2 class="mt-2 text-2xl font-semibold">Operations RH</h2>
                    <div class="mt-6 space-y-3">
                        <a href="integration_personnel_rh.php" class="block rounded-2xl border border-emerald-400/20 bg-emerald-500/10 px-4 py-4 transition hover:bg-emerald-500/15">
                            <div class="font-semibold text-emerald-300">Integrer un collaborateur</div>
                            <div class="mt-1 text-sm text-slate-300">Creer un nouveau dossier RH complet avec pieces justificatives</div>
                        </a>
                        <a href="pointage_journalier_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Pointage du jour</div>
                            <div class="mt-1 text-sm text-slate-300">Saisir rapidement les presences, absences, missions et HS</div>
                        </a>
                        <a href="pointage_mensuel_personnel_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Pointage mensuel (1 personne)</div>
                            <div class="mt-1 text-sm text-slate-300">Vue jour par jour pour verifier un salarié et corriger le pointage</div>
                        </a>
                        <a href="validation_demandes_rh.php" class="relative block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <?php if ((int) ($rhLegal['total'] ?? 0) > 0): ?>
                                <span class="absolute right-4 top-4 rounded-full bg-emerald-300 px-2.5 py-1 text-xs font-bold text-slate-950"><?= (int) ($rhLegal['total'] ?? 0) ?></span>
                            <?php endif; ?>
                            <div class="font-semibold">Demandes administratives</div>
                            <div class="mt-1 text-sm text-slate-300">Absences, congés, heures supplémentaires et autres démarches RH</div>
                        </a>
                        <a href="historique_points_financiers_rh.php" class="block rounded-2xl border border-amber-300/30 bg-amber-400/10 px-4 py-4"><div class="font-semibold text-amber-200">Historique des points RH</div><div class="mt-1 text-sm text-slate-300">Retrouver un point, consulter son état, trier et télécharger son PDF</div></a>
                        <a href="points_financiers_rh.php" class="block rounded-2xl border border-amber-300/30 bg-amber-400/10 px-4 py-4"><div class="font-semibold text-amber-200">Points financiers RH</div><div class="mt-1 text-sm text-slate-300">Avances, prêts et salaires : arrêt RH, signature DG, FEB et suivi du décaissement</div></a>
                        <a href="validation_demandes_rh.php?scope=financial" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4"><div class="font-semibold">Demandes financières et historique</div><div class="mt-1 text-sm text-slate-300">Consulter avances, prêts et réclamations salariales</div></a>
                        <a href="conges_initialisation_rh.php" class="relative block rounded-2xl border border-cyan-400/20 bg-cyan-500/10 px-4 py-4 transition hover:bg-cyan-500/15">
                            <?php
                            $leaveMissing = 0;
                            foreach ($rhAlerts as $alert) {
                                if (($alert['key'] ?? '') === 'leave_opening') {
                                    $leaveMissing = (int) ($alert['count'] ?? 0);
                                    break;
                                }
                            }
                            ?>
                            <?php if ($leaveMissing > 0): ?>
                                <span class="absolute right-4 top-4 rounded-full bg-cyan-200 px-2.5 py-1 text-xs font-bold text-slate-950"><?= $leaveMissing ?></span>
                            <?php endif; ?>
                            <div class="font-semibold text-cyan-200">Initialisation conges</div>
                            <div class="mt-1 text-sm text-slate-300">Renseigner les soldes de depart avant calcul ERP</div>
                        </a>
                        <a href="demandes_explication_rh.php" class="relative block rounded-2xl border border-rose-400/20 bg-rose-500/10 px-4 py-4 transition hover:bg-rose-500/15">
                            <?php if ((int) ($rhExplanations['pending_response'] ?? 0) > 0): ?>
                                <span class="absolute right-4 top-4 rounded-full bg-rose-200 px-2.5 py-1 text-xs font-bold text-slate-950"><?= (int) ($rhExplanations['pending_response'] ?? 0) ?></span>
                            <?php endif; ?>
                            <div class="font-semibold text-rose-200">Demandes d’explications</div>
                            <div class="mt-1 text-sm text-slate-300">Créer, notifier, relancer et clôturer les demandes adressées au personnel</div>
                        </a>
                        <a href="paie_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Nouvelle fiche de paie</div>
                            <div class="mt-1 text-sm text-slate-300">Calculer, enregistrer puis imprimer un bulletin professionnel</div>
                        </a>
                        <a href="contrats_employes_rh.php" class="block rounded-2xl border border-amber-400/20 bg-amber-500/10 px-4 py-4 transition hover:bg-amber-500/15">
                            <div class="font-semibold text-amber-200">Contrats employes</div>
                            <div class="mt-1 text-sm text-slate-300">Parametrer salaire, horaires, clauses et avantages avant la paie</div>
                        </a>
                        <a href="parametrage_rh.php" class="block rounded-2xl border border-indigo-400/40 bg-indigo-500/15 px-4 py-4 transition hover:bg-indigo-500/25">
                            <div class="font-semibold text-indigo-200">Paramétrage RH</div>
                            <div class="mt-1 text-sm text-slate-300">Services, fonctions, statuts, sites</div>
                        </a>
                        <a href="parametrage_hierarchie_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Hiérarchie demandes d’explications</div>
                            <div class="mt-1 text-sm text-slate-300">Régler DG, responsables directs, chefs de département et délai de réponse</div>
                        </a>
                        <a href="organigramme_rh.php" class="block rounded-2xl border border-emerald-300/25 bg-emerald-400/10 px-4 py-4 transition hover:bg-emerald-400/15">
                            <div class="font-semibold text-emerald-100"><i class="fas fa-sitemap mr-2"></i>Organigramme general</div>
                            <div class="mt-1 text-sm text-slate-300">Visualiser et exporter l'arbre hierarchique complet de l'entreprise</div>
                        </a>
                        <a href="parametrage_paie_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Regles des contrats</div>
                            <div class="mt-1 text-sm text-slate-300">Administrer les coefficients CDI, CDD, stage et vacataire</div>
                        </a>
                        <a href="parametrage_contrat_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Signataires et filigranes</div>
                            <div class="mt-1 text-sm text-slate-300">Piloter DG, RH, filigrane et numerotation CTRH</div>
                        </a>
                        <a href="feries_rh.php" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Gestion des fériés</div>
                            <div class="mt-1 text-sm text-slate-300">Exclure automatiquement les dates du calcul jours ouvrés</div>
                        </a>
                        <a href="liste_personnel_rh.php?scope=active" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Personnel en poste</div>
                            <div class="mt-1 text-sm text-slate-300">Explorer uniquement les collaborateurs actifs</div>
                        </a>
                        <a href="liste_personnel_rh.php?status_code=1&scope=all" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Contrats CDD</div>
                            <div class="mt-1 text-sm text-slate-300">Filtrer sur les agents declares CDD</div>
                        </a>
                        <a href="liste_personnel_rh.php?scope=inactive" class="block rounded-2xl border border-white/10 bg-white/5 px-4 py-4 transition hover:bg-white/10">
                            <div class="font-semibold">Sorties et archives</div>
                            <div class="mt-1 text-sm text-slate-300">Consulter les dossiers non actifs</div>
                        </a>
                    </div>
                </aside>
            </div>

            <div class="mt-8 grid min-w-0 gap-6 xl:grid-cols-2">
                <section class="min-w-0 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Fonctions</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950">Fonctions dominantes</h2>
                        </div>
                    </div>
                    <div class="mt-6 grid gap-3 sm:grid-cols-2">
                        <?php foreach ($dashboardData['fonctions'] as $fonction): ?>
                            <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                <div class="text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) $fonction['label']) ?></div>
                                <div class="mt-2 text-2xl font-semibold text-indigo-700"><?= (int) $fonction['total'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="min-w-0 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Statuts</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Repartition des statuts</h2>
                    </div>
                    <div class="mt-6 space-y-3">
                        <?php foreach ($dashboardData['statuts'] as $statut): ?>
                            <div class="flex items-center justify-between rounded-2xl border border-amber-100 bg-amber-50/70 px-4 py-4">
                                <span class="font-medium text-slate-800"><?= htmlspecialchars((string) $statut['label']) ?></span>
                                <span class="rounded-full bg-white px-3 py-1 text-sm font-semibold text-amber-700 shadow-sm"><?= (int) $statut['total'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="mt-8 min-w-0 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Recrutements recents</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Dernieres integrations</h2>
                    </div>
                    <a href="liste_personnel_rh.php?scope=all" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800">Consulter tous les profils</a>
                </div>
                <div class="mt-6 w-full max-w-full overflow-x-auto overscroll-x-contain">
                    <table class="min-w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-slate-100 text-slate-500">
                                <th class="px-3 py-3 font-medium">Matricule</th>
                                <th class="px-3 py-3 font-medium">Nom</th>
                                <th class="px-3 py-3 font-medium">Service</th>
                                <th class="px-3 py-3 font-medium">Fonction</th>
                                <th class="px-3 py-3 font-medium">Recrutement</th>
                                <th class="px-3 py-3 font-medium">Statut</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dashboardData['recentHires'] as $employee): ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-4 font-medium text-slate-700"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'Non renseigne')) ?></td>
                                    <td class="px-3 py-4 text-slate-900"><?= htmlspecialchars((string) $employee['full_name']) ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars((string) $employee['service_label']) ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars((string) $employee['fonction_label']) ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars(formatRhDate($employee['hire_date'] ?? null)) ?></td>
                                    <td class="px-3 py-4">
                                        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">
                                            <?= htmlspecialchars((string) $employee['statut_label']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const picker = document.querySelector('[data-personnel-picker]');
    if (!picker) return;

    const value = picker.querySelector('[data-personnel-value]');
    const search = picker.querySelector('[data-personnel-search]');
    const control = picker.querySelector('[data-personnel-control]');
    const panel = picker.querySelector('[data-personnel-options]');
    const badges = picker.querySelector('[data-personnel-badges]');
    const empty = picker.querySelector('[data-personnel-empty]');
    const error = picker.querySelector('[data-personnel-error]');
    const options = Array.from(picker.querySelectorAll('[data-personnel-option]'));
    const form = picker.closest('form');
    let selectedLabel = '';

    const normalized = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
    const openPanel = () => {
        panel.classList.remove('hidden');
        search.setAttribute('aria-expanded', 'true');
    };
    const closePanel = () => {
        panel.classList.add('hidden');
        search.setAttribute('aria-expanded', 'false');
    };
    const filterOptions = () => {
        const needle = normalized(search.value);
        let visible = 0;
        options.forEach((button) => {
            const matches = button.dataset.id !== value.value && normalized(button.dataset.search).includes(needle);
            button.classList.toggle('hidden', !matches);
            if (matches) visible++;
        });
        empty.classList.toggle('hidden', visible > 0);
    };
    const renderBadges = () => {
        badges.replaceChildren();
        if (value.value && selectedLabel) {
            const badge = document.createElement('span');
            badge.className = 'inline-flex max-w-full items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-bold text-slate-800';
            const label = document.createElement('span');
            label.className = 'max-w-[220px] truncate';
            label.textContent = selectedLabel;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'grid h-5 w-5 shrink-0 place-items-center rounded-full bg-white text-slate-500 hover:bg-rose-100 hover:text-rose-700';
            remove.setAttribute('aria-label', 'Retirer ' + selectedLabel);
            remove.innerHTML = '<i class="fas fa-xmark"></i>';
            remove.addEventListener('click', (event) => {
                event.stopPropagation();
                value.value = '';
                selectedLabel = '';
                renderBadges();
                filterOptions();
            });
            badge.append(label, remove);
            badges.appendChild(badge);
        }
    };

    options.forEach((button) => button.addEventListener('click', () => {
        value.value = button.dataset.id;
        selectedLabel = button.dataset.label;
        error.classList.add('hidden');
        search.value = '';
        renderBadges();
        filterOptions();
        closePanel();
    }));
    control.addEventListener('click', () => { openPanel(); search.focus(); filterOptions(); });
    search.addEventListener('focus', () => { openPanel(); filterOptions(); });
    search.addEventListener('input', filterOptions);
    search.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closePanel();
        if (event.key === 'Enter') {
            const first = options.find((button) => !button.classList.contains('hidden'));
            if (first) { event.preventDefault(); first.click(); }
        }
        if (event.key === 'Backspace' && search.value === '') {
            if (value.value) { value.value = ''; selectedLabel = ''; renderBadges(); filterOptions(); }
        }
    });
    form.addEventListener('submit', (event) => {
        if (!value.value) {
            event.preventDefault();
            error.classList.remove('hidden');
            openPanel();
            search.focus();
        }
    });
    document.addEventListener('click', (event) => { if (!picker.contains(event.target)) closePanel(); });
    renderBadges();
    filterOptions();
});
</script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>


