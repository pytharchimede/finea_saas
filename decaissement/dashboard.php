<?php
// Sécurité: inclure auth_check (depuis racine) pour appliquer séparation des espaces
require_once __DIR__ . '/../auth_check.php';
// Connexions & modèles internes
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/../model/CompanyBranding.php';
require_once __DIR__ . '/model/Dashboard.php';
require_once __DIR__ . '/model/Fiche.php';
require_once __DIR__ . '/model/Chantier.php';
// Synthèses transverses (DG)
require_once __DIR__ . '/model/ProjectPlanningImport.php';
require_once __DIR__ . '/includes/planning_dashboard_shared.php';
require_once __DIR__ . '/model/PersonnelDirectory.php';
require_once __DIR__ . '/../model/LogisticsDashboard.php';

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

$donneesDashboard = $dashboardModel->getDonneesDashboard();
$chartsData = $dashboardModel->getChartsData();
$alertes = $dashboardModel->getAlertes();

// ======================= Synthèse DG (planning / RH / logistique / finance) =======================
$formatMoney = static function ($value): string {
    return number_format((float) $value, 0, ',', ' ') . ' F';
};
$formatPct = static function ($value): string {
    return number_format((float) $value, 1, ',', ' ') . '%';
};

$canSeeDgPlanning = function_exists('hasPermission')
    ? (hasPermission('acces_direction') || hasPermission('acces_budgets_chantiers') || hasPermission('acces_dec_chantiers') || hasPermission('acces_admin'))
    : false;
$canSeeDgRh = function_exists('hasPermission')
    ? (hasPermission('acces_rh') || hasPermission('acces_admin'))
    : false;
$canSeeDgLogistics = function_exists('hasPermission')
    ? (hasPermission('acces_logistique') || hasPermission('acces_admin'))
    : false;

$dgHasAnyModule = $canSeeDgPlanning || $canSeeDgRh || $canSeeDgLogistics;

$planningOverview = null;
$planningHotspots = [];
$planningStatusConfig = function_exists('planningDashboardStatusConfig') ? planningDashboardStatusConfig() : [];
$planningError = null;

if ($canSeeDgPlanning) {
    try {
        $planningModel = new \ProjectPlanningImport($pdo);
        $planningRows = $planningModel->getPlanningDashboardSummaries([]);
        $planningOverview = function_exists('planningDashboardComputeOverview')
            ? planningDashboardComputeOverview($planningRows)
            : ['total_chantiers' => count($planningRows)];

        $hotRows = array_values(array_filter($planningRows, static function (array $row): bool {
            $status = (string)($row['health_status'] ?? '');
            return in_array($status, ['retard', 'bloque'], true);
        }));

        usort($hotRows, static function (array $a, array $b): int {
            $aGap = (float)($a['planned_progress'] ?? 0) - (float)($a['actual_progress'] ?? 0);
            $bGap = (float)($b['planned_progress'] ?? 0) - (float)($b['actual_progress'] ?? 0);

            if ($bGap !== $aGap) {
                return $bGap <=> $aGap;
            }

            $aBudgetGap = (float)($a['budget_consumption_percent'] ?? 0) - (float)($a['actual_progress'] ?? 0);
            $bBudgetGap = (float)($b['budget_consumption_percent'] ?? 0) - (float)($b['actual_progress'] ?? 0);
            return $bBudgetGap <=> $aBudgetGap;
        });

        $planningHotspots = array_slice($hotRows, 0, 6);
    } catch (Throwable $e) {
        $planningError = 'Données planning indisponibles.';
        $planningOverview = null;
        $planningHotspots = [];
    }
}

$rhStats = null;
$rhRecent = [];
$rhServices = [];
$rhError = null;

if ($canSeeDgRh) {
    try {
        $directory = new \PersonnelDirectory($pdo);
        $rhData = $directory->getDashboardData();
        $rhStats = $rhData['stats'] ?? null;
        $rhServices = array_slice((array)($rhData['services'] ?? []), 0, 4);
        $rhRecent = array_slice((array)($rhData['recentHires'] ?? []), 0, 6);
    } catch (Throwable $e) {
        $rhError = 'Données RH indisponibles.';
        $rhStats = null;
        $rhRecent = [];
        $rhServices = [];
    }
}

$logisticsSummary = null;
$logisticsAlerts = [];
$logisticsRecentFuel = [];
$logisticsError = null;

if ($canSeeDgLogistics) {
    try {
        $logisticsDashboard = new \LogisticsDashboard($pdo);
        $logData = $logisticsDashboard->getDashboardData();
        $logisticsSummary = $logData['summary'] ?? null;
        $logisticsAlerts = array_slice((array)($logData['alerts'] ?? []), 0, 4);
        $logisticsRecentFuel = array_slice((array)($logData['recent_fuel_requests'] ?? []), 0, 6);
    } catch (Throwable $e) {
        $logisticsError = 'Données logistique indisponibles.';
        $logisticsSummary = null;
        $logisticsAlerts = [];
        $logisticsRecentFuel = [];
    }
}

// Réutiliser le calcul partagé du modèle pour rester cohérent avec les autres écrans
foreach ($donneesDashboard['chantiers_budgets'] as &$cDash) {
    $bi = (float)($cDash['budget_interne_calc'] ?? $cDash['budget_total'] ?? 0);
    $cDash['budget_interne_calc'] = $bi;
    if ($bi > 0 && isset($cDash['total_depenses'])) {
        $cDash['pourcentage_consomme'] = ((float)$cDash['total_depenses'] / max($bi, 1)) * 100;
    }
}
unset($cDash);

// ======================= KPI Finance DG (marge estimée / surconsommation / backlog) =======================
$financeOverview = null;
$financeTopOverspends = [];
$financeBacklog = null;

try {
    $budgetTotal = 0.0;
    $depensesTotal = 0.0;

    $overs = [];
    foreach ((array)($donneesDashboard['chantiers_budgets'] ?? []) as $cDash) {
        $budgetRef = (float)($cDash['budget_interne_calc'] ?? 0);
        if ($budgetRef <= 0) {
            $budgetRef = (float)($cDash['budget_total'] ?? 0);
        }
        if ($budgetRef <= 0) {
            $budgetRef = (float)($cDash['budget_devis_total'] ?? 0);
        }

        $dep = (float)($cDash['total_depenses'] ?? 0);
        $budgetTotal += max($budgetRef, 0);
        $depensesTotal += max($dep, 0);

        if ($budgetRef > 0) {
            $delta = $dep - $budgetRef;
            if ($delta > 0) {
                $overs[] = [
                    'id_chantier' => (int)($cDash['id_chantier'] ?? 0),
                    'code' => (string)($cDash['num_chantier'] ?? ''),
                    'titre' => (string)($cDash['lib_chantier'] ?? ''),
                    'delta' => $delta,
                    'pct' => ($dep / $budgetRef) * 100,
                ];
            }
        }
    }

    $marge = $budgetTotal - $depensesTotal;
    $margePct = $budgetTotal > 0 ? ($marge / $budgetTotal) * 100 : null;
    $financeOverview = [
        'budget_total' => $budgetTotal,
        'depenses_total' => $depensesTotal,
        'marge' => $marge,
        'marge_pct' => $margePct,
    ];

    usort($overs, static function (array $a, array $b): int {
        if ($b['delta'] !== $a['delta']) {
            return $b['delta'] <=> $a['delta'];
        }
        return $b['pct'] <=> $a['pct'];
    });
    $financeTopOverspends = array_slice($overs, 0, 3);
} catch (Throwable $e) {
    $financeOverview = null;
    $financeTopOverspends = [];
}

try {
    $cols = $pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN, 0);
    $hasDateMin = in_array('date_decaissement_minimum', $cols, true);
    $hasEtat = in_array('etat_fiche', $cols, true);

    $achatDateCond = $hasDateMin ? " AND (f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())" : '';

    $sql = "SELECT
                SUM(CASE WHEN f.conforme = 1 AND f.decaisse = 0 AND COALESCE(f.approuve, 0) = 0 THEN 1 ELSE 0 END) AS backlog_approbation,
                SUM(CASE WHEN f.conforme = 1 AND COALESCE(f.approuve, 0) = 1 AND f.decaisse = 0" . ($hasEtat ? " AND COALESCE(f.etat_fiche, 0) = 0" : '') . $achatDateCond . " THEN 1 ELSE 0 END) AS backlog_achat,
                SUM(CASE WHEN f.conforme = 1 AND COALESCE(f.approuve, 0) = 1 AND f.decaisse = 0" . ($hasEtat ? " AND COALESCE(f.etat_fiche, 0) = 1" : '') . $achatDateCond . " THEN 1 ELSE 0 END) AS backlog_compta
            FROM fiche_decaissement f LEFT JOIN chantier_banamur c_scope ON c_scope.id=f.chantier_id
            WHERE (f.chantier_id IS NULL OR c_scope.archived_at IS NULL)";

    $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $financeBacklog = [
        'approbation' => (int)($row['backlog_approbation'] ?? 0),
        'achat' => (int)($row['backlog_achat'] ?? 0),
        'compta' => (int)($row['backlog_compta'] ?? 0),
    ];
} catch (Throwable $e) {
    $financeBacklog = null;
}


// ======================= Widgets vivants transverses =======================
if (!function_exists('dashboardLayoutRelativeRoot')) {
    function dashboardLayoutRelativeRoot(): string
    {
        $projectRoot = realpath(dirname(__DIR__, 1));
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
        if (!$projectRoot || !$scriptFilename) {
            return '../';
        }
        $scriptDir = realpath(dirname((string)$scriptFilename)) ?: dirname((string)$scriptFilename);
        $relative = trim(str_replace('\\', '/', str_replace($projectRoot, '', $scriptDir)), '/');
        return $relative === '' ? '' : str_repeat('../', substr_count($relative, '/') + 1);
    }
}
if (!function_exists('dashboardResolveUserPhotoUrl')) {
    function dashboardResolveUserPhotoUrl(string $raw, string $layoutRootBase): string
    {
        $raw = trim(str_replace('\\', '/', $raw));
        if ($raw === '') {
            $mail = trim((string)($_SESSION['mail_pro'] ?? $_SESSION['email'] ?? ''));
            if ($mail !== '') {
                return 'https://www.gravatar.com/avatar/' . md5(strtolower($mail)) . '?d=mp&s=240';
            }
            return $layoutRootBase . 'photo/ma_photo.jpg';
        }
        if (preg_match('~^(https?:|data:)~i', $raw)) return $raw;
        if (strpos($raw, '/') === 0) return $raw;
        return $layoutRootBase . ltrim($raw, '/');
    }
}
$dashboardLayoutRootBase = dashboardLayoutRelativeRoot();
$dashUserName = trim((string)(($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
$dashUserName = $_SESSION['user_name'] ?? ($dashUserName !== '' ? $dashUserName : ($_SESSION['login'] ?? 'Utilisateur FINEA'));
$dashUserRole = $_SESSION['role_raw'] ?? $_SESSION['role'] ?? $_SESSION['fonction'] ?? $_SESSION['poste'] ?? 'Collaborateur';
$dashUserService = $_SESSION['service'] ?? $_SESSION['departement'] ?? (string)CompanyBranding::get()['company_name'];
$dashUserPhotoRaw = (string)($_SESSION['photo'] ?? $_SESSION['avatar'] ?? $_SESSION['photo_profil'] ?? $_SESSION['profile_photo'] ?? $_SESSION['image'] ?? '');
$dashUserPhoto = dashboardResolveUserPhotoUrl($dashUserPhotoRaw, $dashboardLayoutRootBase);
$dashValidationsTotal = is_array($financeBacklog ?? null) ? (int)array_sum($financeBacklog) : 0;

$pageTitle = 'Tableau de Bord - FINEA';
$activeMenu = 'dashboard';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<style>
    .xdash-premium {
        position: relative;
        margin-bottom: 34px;
        color: #0f172a;
    }

    .xdash-hero {
        position: relative;
        overflow: hidden;
        border-radius: 34px;
        padding: 30px;
        background:
            radial-gradient(circle at 12% 18%, rgba(250, 189, 2, .35), transparent 18rem),
            radial-gradient(circle at 90% 15%, rgba(96, 165, 250, .24), transparent 24rem),
            linear-gradient(135deg, #101a39 0%, #1d2b57 52%, #314685 100%);
        color: #fff;
        box-shadow: 0 30px 90px rgba(29, 43, 87, .28);
    }

    .xdash-hero::before {
        content: "";
        position: absolute;
        inset: -55%;
        background: conic-gradient(from 90deg, transparent, rgba(255, 255, 255, .12), transparent, rgba(250, 189, 2, .16), transparent);
        animation: xdashOrbit 18s linear infinite;
    }

    @keyframes xdashOrbit {
        to {
            transform: rotate(360deg)
        }
    }

    .xdash-hero-inner {
        position: relative;
        z-index: 1;
        display: grid;
        grid-template-columns: 1.35fr .65fr;
        gap: 22px;
        align-items: stretch;
    }

    .xdash-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(250, 189, 2, .16);
        border: 1px solid rgba(250, 189, 2, .38);
        font-weight: 900;
        font-size: 12px;
    }

    .xdash-title {
        font-size: clamp(34px, 5vw, 62px);
        line-height: .96;
        font-weight: 950;
        letter-spacing: -.07em;
        margin: 18px 0 10px;
    }

    .xdash-sub {
        max-width: 760px;
        color: rgba(255, 255, 255, .76);
        font-size: 17px
    }

    .xdash-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 22px
    }

    .xdash-actions a,
    .xdash-actions button {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        border: 1px solid rgba(255, 255, 255, .18);
        background: rgba(255, 255, 255, .12);
        color: #fff;
        border-radius: 16px;
        padding: 12px 14px;
        font-weight: 900;
        transition: .18s;
        backdrop-filter: blur(10px)
    }

    .xdash-actions a:hover,
    .xdash-actions button:hover {
        background: rgba(255, 255, 255, .22);
        transform: translateY(-2px)
    }

    .xdash-profile {
        border: 1px solid rgba(255, 255, 255, .22);
        background: rgba(255, 255, 255, .12);
        backdrop-filter: blur(18px);
        border-radius: 26px;
        padding: 20px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, .18);
    }

    .xdash-avatar {
        width: 74px;
        height: 74px;
        border-radius: 25px;
        object-fit: cover;
        border: 2px solid rgba(255, 255, 255, .74);
        box-shadow: 0 18px 35px rgba(0, 0, 0, .18)
    }

    .xdash-mini-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 18px
    }

    .xdash-mini {
        border-radius: 18px;
        padding: 13px;
        background: rgba(255, 255, 255, .12);
        border: 1px solid rgba(255, 255, 255, .16)
    }

    .xdash-mini strong {
        display: block;
        font-size: 25px;
        line-height: 1;
        font-weight: 950
    }

    .xdash-mini small {
        color: rgba(255, 255, 255, .72)
    }

    .xdash-grid {
        margin-top: 20px;
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 18px;
    }

    .xdash-widget {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        min-height: 154px;
        padding: 21px;
        background: rgba(255, 255, 255, .92);
        border: 1px solid rgba(226, 232, 240, .92);
        box-shadow: 0 20px 55px rgba(29, 43, 87, .10);
        transition: .2s;
        isolation: isolate;
    }

    .xdash-widget:hover {
        transform: translateY(-3px);
        box-shadow: 0 28px 70px rgba(29, 43, 87, .16)
    }

    .xdash-widget::after {
        content: "";
        position: absolute;
        right: -70px;
        bottom: -90px;
        width: 210px;
        height: 210px;
        background: radial-gradient(circle, rgba(250, 189, 2, .13), transparent 62%);
        z-index: -1
    }

    .xdash-span-3 {
        grid-column: span 3
    }

    .xdash-span-4 {
        grid-column: span 4
    }

    .xdash-span-5 {
        grid-column: span 5
    }

    .xdash-span-6 {
        grid-column: span 6
    }

    .xdash-span-8 {
        grid-column: span 8
    }

    .xdash-span-12 {
        grid-column: span 12
    }

    .xdash-widget-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 14px
    }

    .xdash-widget-head h3 {
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
        font-weight: 950;
        color: #172554
    }

    .xdash-widget-head h3 i {
        color: #1d2b57
    }

    .xdash-toolbar {
        display: flex;
        align-items: center;
        gap: 8px
    }

    .xdash-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 950;
        background: #eef2ff;
        color: #263875
    }

    .xdash-close {
        width: 32px;
        height: 32px;
        border: 0;
        border-radius: 14px;
        background: rgba(239, 68, 68, .09);
        color: #b91c1c;
        display: grid;
        place-items: center;
        cursor: pointer;
        transition: .16s
    }

    .xdash-close:hover {
        background: #ef4444;
        color: #fff;
        transform: rotate(6deg) scale(1.04)
    }

    .xdash-metric {
        font-size: 38px;
        font-weight: 950;
        letter-spacing: -.05em;
        line-height: 1;
        color: #0f172a
    }

    .xdash-muted {
        color: #64748b;
        font-size: 13px
    }

    .xdash-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 11px 12px;
        border-radius: 16px;
        background: #f8fafc;
        border: 1px solid #edf2f7;
        margin-top: 9px
    }

    .xdash-weather {
        min-height: 300px;
        background: linear-gradient(160deg, #f8fbff 0%, #dff1ff 48%, #fff7dd 100%);
        color: #10234d
    }

    .xdash-sky {
        position: absolute;
        inset: 0;
        overflow: hidden;
        opacity: .72;
        pointer-events: none
    }

    .xdash-cloud {
        position: absolute;
        width: 145px;
        height: 44px;
        background: rgba(255, 255, 255, .82);
        border-radius: 999px;
        animation: xdashCloud 22s linear infinite
    }

    .xdash-cloud:before,
    .xdash-cloud:after {
        content: "";
        position: absolute;
        background: inherit;
        border-radius: 50%
    }

    .xdash-cloud:before {
        width: 68px;
        height: 68px;
        left: 25px;
        top: -34px
    }

    .xdash-cloud:after {
        width: 54px;
        height: 54px;
        right: 28px;
        top: -24px
    }

    .xdash-cloud.c1 {
        top: 48px;
        left: -180px
    }

    .xdash-cloud.c2 {
        top: 105px;
        left: -260px;
        transform: scale(.72);
        animation-duration: 30s;
        opacity: .65
    }

    @keyframes xdashCloud {
        to {
            transform: translateX(calc(100vw + 330px))
        }
    }

    .xdash-rain {
        position: absolute;
        inset: 0;
        background-image: linear-gradient(180deg, rgba(75, 128, 180, .35) 0 45%, transparent 45%);
        background-size: 2px 22px;
        animation: xdashRain .42s linear infinite;
        transform: skewX(-12deg);
        opacity: .34
    }

    @keyframes xdashRain {
        to {
            background-position: 0 22px
        }
    }

    .xdash-temp {
        font-size: 60px;
        font-weight: 950;
        letter-spacing: -.08em;
        line-height: .9;
        color: #10234d
    }

    .xdash-weather-mini {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 9px;
        margin-top: 17px
    }

    .xdash-weather-mini div {
        padding: 10px;
        border-radius: 16px;
        background: rgba(255, 255, 255, .68);
        border: 1px solid rgba(255, 255, 255, .85)
    }

    .xdash-sticky {
        background: radial-gradient(circle at 12% 10%, rgba(250, 189, 2, .26), transparent 20rem), linear-gradient(145deg, #fff8bf 0%, #fff3a8 42%, #fff9d7 100%);
        border-color: rgba(234, 179, 8, .35)
    }

    .xdash-sticky-app {
        display: grid;
        grid-template-columns: 160px 1fr;
        gap: 12px;
        min-height: 310px
    }

    .xdash-sticky-list {
        display: grid;
        align-content: start;
        gap: 8px;
        max-height: 360px;
        overflow: auto;
        padding-right: 4px
    }

    .xdash-note-tab {
        border: 0;
        border-radius: 16px;
        padding: 10px;
        text-align: left;
        background: rgba(255, 255, 255, .55);
        color: #533f03;
        cursor: pointer
    }

    .xdash-note-tab.active {
        background: #fff;
        box-shadow: 0 12px 24px rgba(120, 82, 0, .13), inset 0 0 0 2px rgba(29, 43, 87, .18)
    }

    .xdash-note-tab strong {
        display: block;
        color: #172554;
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .xdash-note-tab small {
        font-size: 11px;
        color: #7c6a19;
        font-weight: 800
    }

    .xdash-add-note {
        border: 0;
        border-radius: 999px;
        background: #1d2b57;
        color: #fff;
        font-weight: 950;
        padding: 8px 11px;
        display: inline-flex;
        align-items: center;
        gap: 7px
    }

    .xdash-editor {
        border-radius: 22px;
        background: rgba(255, 255, 255, .62);
        border: 1px solid rgba(120, 82, 0, .12);
        overflow: hidden
    }

    .xdash-note-title {
        width: 100%;
        border: 0;
        outline: 0;
        background: transparent;
        padding: 14px 16px 8px;
        font-size: 18px;
        font-weight: 950;
        color: #172554
    }

    .xdash-tools {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        padding: 8px 12px;
        border-top: 1px solid rgba(120, 82, 0, .1);
        border-bottom: 1px solid rgba(120, 82, 0, .1);
        background: rgba(255, 255, 255, .34)
    }

    .xdash-tools button {
        width: 33px;
        height: 33px;
        border: 0;
        border-radius: 12px;
        display: grid;
        place-items: center;
        cursor: pointer;
        background: rgba(255, 255, 255, .72);
        color: #1d2b57
    }

    .xdash-tools button:hover {
        background: #1d2b57;
        color: #fff
    }

    .xdash-note-body {
        min-height: 205px;
        max-height: 300px;
        overflow: auto;
        padding: 15px 18px 20px;
        outline: none;
        color: #1f2937;
        font-size: 14px;
        line-height: 1.65
    }

    .xdash-note-body:empty::before {
        content: attr(data-placeholder);
        color: rgba(83, 63, 3, .55)
    }

    .xdash-calc-display {
        width: 100%;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        border-radius: 18px;
        padding: 14px;
        font-size: 25px;
        font-weight: 950;
        text-align: right;
        outline: 0
    }

    .xdash-calc-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 8px;
        margin-top: 10px
    }

    .xdash-calc-grid button {
        border: 0;
        border-radius: 14px;
        padding: 12px 10px;
        font-weight: 950;
        background: #eef2f7;
        color: #0f172a;
        cursor: pointer
    }

    .xdash-calc-grid button:hover {
        background: #e0e7ff
    }

    .xdash-calc-grid .op {
        background: #1d2b57;
        color: #fff
    }

    .xdash-calc-grid .accent {
        background: #fabd02;
        color: #1d2b57
    }

    .xdash-calendar-head {
        display: flex;
        align-items: center;
        justify-content: space-between
    }

    .xdash-cal-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 6px;
        text-align: center;
        margin-top: 12px
    }

    .xdash-cal-grid span {
        font-size: 12px;
        font-weight: 900;
        color: #64748b
    }

    .xdash-cal-grid button {
        border: 0;
        border-radius: 12px;
        padding: 9px 0;
        background: #f8fafc;
        font-weight: 900
    }

    .xdash-cal-grid button.today {
        background: #1d2b57;
        color: #fff;
        box-shadow: 0 8px 20px rgba(29, 43, 87, .24)
    }

    .xdash-panel {
        position: fixed;
        inset: 0;
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 18px;
        background: rgba(15, 23, 42, .58);
        backdrop-filter: blur(10px)
    }

    .xdash-panel.show {
        display: flex
    }

    .xdash-panel-card {
        width: min(780px, 100%);
        max-height: 86vh;
        overflow: auto;
        border-radius: 28px;
        background: #fff;
        padding: 24px;
        box-shadow: 0 34px 90px rgba(15, 23, 42, .28)
    }

    .xdash-settings-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 13px 14px;
        border: 1px solid #eef2f7;
        background: #f8fafc;
        border-radius: 16px;
        margin-bottom: 10px
    }

    .xdash-settings-row button {
        border: 0;
        border-radius: 12px;
        background: #1d2b57;
        color: #fff;
        padding: 8px 12px;
        font-weight: 900
    }

    .xdash-widget.is-hidden {
        display: none !important
    }

    .xdash-widget.is-dragging {
        opacity: .55;
        transform: scale(.985)
    }

    @media(max-width:1100px) {
        .xdash-hero-inner {
            grid-template-columns: 1fr
        }

        .xdash-span-3,
        .xdash-span-4,
        .xdash-span-5,
        .xdash-span-6,
        .xdash-span-8 {
            grid-column: span 12
        }
    }

    @media(max-width:720px) {
        .xdash-hero {
            padding: 22px;
            border-radius: 26px
        }

        .xdash-grid {
            gap: 12px
        }

        .xdash-widget {
            padding: 17px;
            border-radius: 22px
        }

        .xdash-sticky-app {
            grid-template-columns: 1fr
        }

        .xdash-sticky-list {
            display: flex;
            overflow-x: auto;
            max-height: none
        }

        .xdash-note-tab {
            min-width: 130px
        }

        .xdash-actions a,
        .xdash-actions button {
            width: 100%;
            justify-content: center
        }
    }
</style>


<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">

        <section class="xdash-premium" id="xdashPremium">
            <div class="xdash-hero" data-tour="true" data-tour-title="Dashboard vivant" data-tour-content="Vue transversale accessible à tous : météo GPS, pense-bête, calculatrice, calendrier, validations, infos à la une et widgets personnalisables." data-tour-order="1">
                <div class="xdash-hero-inner">
                    <div>
                        <span class="xdash-eyebrow"><i class="fas fa-bolt"></i> Centre de pilotage FINEA</span>
                        <h2 class="xdash-title">Bonjour, <?= htmlspecialchars((string)$dashUserName, ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="xdash-sub">Un espace vivant pour suivre rapidement la journée : priorités, météo chantier, outils rapides, notes, calendrier et informations importantes.</p>
                        <div class="xdash-actions">
                            <a href="formulaire_demande_decaissement.php"><i class="fas fa-plus-circle"></i> Nouvelle FEB</a>
                            <a href="details_chantiers.php"><i class="fas fa-building"></i> Chantiers</a>
                            <a href="liste_fiches_approbation.php"><i class="fas fa-check-circle"></i> Approbations</a>
                            <a href="../selection_portail.php"><i class="fas fa-grip"></i> Portail modules</a>
                            <button type="button" id="xdashOpenSettings"><i class="fas fa-sliders"></i> Widgets</button>
                        </div>
                    </div>
                    <aside class="xdash-profile">
                        <div style="display:flex;align-items:center;gap:16px">
                            <img class="xdash-avatar" src="<?= htmlspecialchars((string)$dashUserPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="Photo utilisateur" onerror="this.onerror=null;this.src='<?= htmlspecialchars((string)($dashboardLayoutRootBase . 'photo/ma_photo.jpg'), ENT_QUOTES, 'UTF-8') ?>';">
                            <div>
                                <div style="font-weight:950;font-size:20px"><?= htmlspecialchars((string)$dashUserRole, ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="color:rgba(255,255,255,.72);font-size:13px"><?= htmlspecialchars((string)$dashUserService, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <div class="xdash-mini-grid">
                            <div class="xdash-mini"><strong><?= number_format((int)$dashValidationsTotal, 0, ',', ' ') ?></strong><small>validations</small></div>
                            <div class="xdash-mini"><strong id="xdashClock">--:--</strong><small>heure locale</small></div>
                        </div>
                    </aside>
                </div>
            </div>

            <div class="xdash-grid" id="xdashGrid">
                <article id="xdash-weather" data-widget-title="Météo GPS chantier" draggable="true" class="xdash-widget xdash-weather xdash-span-4">
                    <div class="xdash-sky">
                        <div class="xdash-cloud c1"></div>
                        <div class="xdash-cloud c2"></div>
                        <div class="xdash-rain" id="xdashRain"></div>
                    </div>
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-cloud-sun-rain"></i> Météo GPS chantier</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill" id="xdashGps">GPS</span></div>
                    </div>
                    <div style="position:relative;z-index:1">
                        <div class="xdash-temp" id="xdashTemp">--°</div>
                        <div style="font-weight:950;color:#1d2b57" id="xdashLocation">Localisation...</div>
                        <div class="xdash-muted" id="xdashWeatherDesc">Chargement météo précise...</div>
                        <div class="xdash-weather-mini">
                            <div><strong id="xdashRainProb">--%</strong><br><small>Pluie</small></div>
                            <div><strong id="xdashWind">--</strong><br><small>Vent</small></div>
                            <div><strong id="xdashHumidity">--%</strong><br><small>Humidité</small></div>
                        </div>
                    </div>
                </article>

                <article id="xdash-headlines" data-widget-title="Infos à la une" draggable="true" class="xdash-widget xdash-span-4">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-bullhorn"></i> Infos à la une</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill">Interne</span></div>
                    </div>
                    <div class="xdash-row"><span><strong>Procédures FEB</strong><br><small class="xdash-muted">Pensez à joindre les justificatifs lisibles.</small></span><i class="fas fa-chevron-right"></i></div>
                    <div class="xdash-row"><span><strong>Sécurité chantier</strong><br><small class="xdash-muted">Vérifier les EPI avant toute intervention.</small></span><i class="fas fa-chevron-right"></i></div>
                    <div class="xdash-row"><span><strong>Support FINEA</strong><br><small class="xdash-muted">Utilisez le bouton Aide pour les parcours guidés.</small></span><i class="fas fa-chevron-right"></i></div>
                </article>

                <article id="xdash-validations" data-widget-title="Validations en attente" draggable="true" class="xdash-widget xdash-span-4">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-clipboard-check"></i> Validations en attente</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill">Action</span></div>
                    </div>
                    <div class="xdash-metric"><?= number_format((int)$dashValidationsTotal, 0, ',', ' ') ?></div>
                    <p class="xdash-muted">Éléments à traiter selon vos accès.</p>
                    <div class="xdash-row"><span>Approbation</span><strong><?= number_format((int)($financeBacklog['approbation'] ?? 0), 0, ',', ' ') ?></strong></div>
                    <div class="xdash-row"><span>Achat / conformité</span><strong><?= number_format((int)($financeBacklog['achat'] ?? 0), 0, ',', ' ') ?></strong></div>
                    <div class="xdash-row"><span>Comptabilité</span><strong><?= number_format((int)($financeBacklog['compta'] ?? 0), 0, ',', ' ') ?></strong></div>
                </article>

                <article id="xdash-sticky" data-widget-title="Pense-bête" draggable="true" class="xdash-widget xdash-sticky xdash-span-6">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-note-sticky"></i> Pense-bête</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill" id="xdashNoteStatus">Local</span><button type="button" class="xdash-add-note" id="xdashAddNote"><i class="fas fa-plus"></i> Note</button></div>
                    </div>
                    <div class="xdash-sticky-app">
                        <aside class="xdash-sticky-list" id="xdashNoteList"></aside>
                        <section class="xdash-editor">
                            <input class="xdash-note-title" id="xdashNoteTitle" placeholder="Titre du post-it">
                            <div class="xdash-tools">
                                <button type="button" data-xcmd="bold" title="Gras"><i class="fas fa-bold"></i></button>
                                <button type="button" data-xcmd="italic" title="Italique"><i class="fas fa-italic"></i></button>
                                <button type="button" data-xcmd="underline" title="Souligné"><i class="fas fa-underline"></i></button>
                                <button type="button" data-xcmd="insertUnorderedList" title="Liste"><i class="fas fa-list-ul"></i></button>
                                <button type="button" data-xcmd="insertOrderedList" title="Liste numérotée"><i class="fas fa-list-ol"></i></button>
                                <button type="button" data-xcmd="formatBlock" data-xvalue="h3" title="Titre"><i class="fas fa-heading"></i></button>
                                <button type="button" data-xcmd="removeFormat" title="Nettoyer"><i class="fas fa-eraser"></i></button>
                            </div>
                            <div id="xdashNoteBody" class="xdash-note-body" contenteditable="true" spellcheck="true" data-placeholder="Écrivez vos priorités, tâches, idées, numéros, rappels..."></div>
                        </section>
                    </div>
                </article>

                <article id="xdash-calculator" data-widget-title="Calculatrice" draggable="true" class="xdash-widget xdash-span-3">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-calculator"></i> Calculatrice</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill">Rapide</span></div>
                    </div>
                    <input id="xdashCalcDisplay" class="xdash-calc-display" value="0" inputmode="decimal">
                    <div id="xdashCalcGrid" class="xdash-calc-grid">
                        <?php foreach (['C', '(', ')', '÷', '7', '8', '9', '×', '4', '5', '6', '-', '1', '2', '3', '+', '0', '.', '%', '='] as $k): ?>
                            <button type="button" data-calc="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" class="<?= in_array($k, ['÷', '×', '-', '+', '='], true) ? 'op' : ($k === 'C' ? 'accent' : '') ?>"><?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?></button>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article id="xdash-calendar" data-widget-title="Calendrier" draggable="true" class="xdash-widget xdash-span-3">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-calendar-days"></i> Calendrier</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill" id="xdashMonthLabel">Mois</span></div>
                    </div>
                    <div class="xdash-calendar-head">
                        <button type="button" class="xdash-pill" id="xdashPrevMonth"><i class="fas fa-chevron-left"></i></button>
                        <strong id="xdashCalTitle"></strong>
                        <button type="button" class="xdash-pill" id="xdashNextMonth"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <div class="xdash-cal-grid" id="xdashCalGrid"></div>
                </article>

                <article id="xdash-assistant" data-widget-title="Assistant rapide" draggable="true" class="xdash-widget xdash-span-3" style="background:linear-gradient(135deg,#101827,#1d2b57);color:#fff">
                    <div class="xdash-widget-head">
                        <h3 style="color:#fff"><i class="fas fa-wand-magic-sparkles" style="color:#fabd02"></i> Assistant rapide</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill">IA</span></div>
                    </div>
                    <p style="color:rgba(255,255,255,.75)">Accès rapide aux actions courantes.</p>
                    <a class="xdash-row" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.12)" href="../ai_assistant/index.php"><span>Ouvrir l’assistant</span><i class="fas fa-arrow-right"></i></a>
                    <a class="xdash-row" style="background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.12)" href="formulaire_demande_decaissement.php"><span>Créer une FEB</span><i class="fas fa-plus"></i></a>
                </article>

                <article id="xdash-system" data-widget-title="État système" draggable="true" class="xdash-widget xdash-span-3">
                    <div class="xdash-widget-head">
                        <h3><i class="fas fa-server"></i> État système</h3>
                        <div class="xdash-toolbar"><span class="xdash-pill">Live</span></div>
                    </div>
                    <div class="xdash-row"><span>Application</span><strong style="color:#059669">OK</strong></div>
                    <div class="xdash-row"><span>Base de données</span><strong style="color:#059669">OK</strong></div>
                    <div class="xdash-row"><span>Session</span><strong style="color:#059669">Active</strong></div>
                </article>
            </div>
        </section>

        <div id="xdashSettings" class="xdash-panel" aria-hidden="true">
            <div class="xdash-panel-card">
                <div style="display:flex;justify-content:space-between;gap:14px;align-items:center;margin-bottom:16px">
                    <div>
                        <h3 style="font-size:24px;font-weight:950;color:#172554;margin:0">Paramétrer les widgets</h3>
                        <p class="xdash-muted">Masquez, réaffichez ou réinitialisez les cartes. Le repositionnement se fait par glisser-déposer.</p>
                    </div>
                    <button type="button" id="xdashCloseSettings" class="xdash-close"><i class="fas fa-times"></i></button>
                </div>
                <div id="xdashSettingsList"></div>
                <button type="button" id="xdashShowAll" class="xdash-pill" style="border:0;margin-right:8px">Tout afficher</button>
                <button type="button" id="xdashReset" class="xdash-pill" style="border:0;background:#fee2e2;color:#991b1b">Réinitialiser</button>
            </div>
        </div>


    </div>



</div>

<!-- Scripts Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Configuration des couleurs
    const colors = {
        primary: 'rgb(79, 70, 229)',
        secondary: 'rgb(147, 51, 234)',
        success: 'rgb(34, 197, 94)',
        warning: 'rgb(245, 158, 11)',
        danger: 'rgb(239, 68, 68)',
        info: 'rgb(59, 130, 246)'
    };

    // Données des graphiques
    const chartsData = <?= json_encode($chartsData) ?>;

    // Graphique évolution des montants (dépenses vs entrées)
    const ctxEvolutionMontants = document.getElementById('evolutionMontantsChart').getContext('2d');
    new Chart(ctxEvolutionMontants, {
        type: 'line',
        data: {
            labels: chartsData.evolution_montants.labels,
            datasets: [{
                label: 'Dépenses (Fiches)',
                data: chartsData.evolution_montants.depenses,
                borderColor: colors.danger,
                backgroundColor: colors.danger + '20',
                fill: false,
                tension: 0.4,
                pointBackgroundColor: colors.danger,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }, {
                label: 'Entrées (Devis Validés)',
                data: chartsData.evolution_montants.entrees,
                borderColor: colors.success,
                backgroundColor: colors.success + '20',
                fill: false,
                tension: 0.4,
                pointBackgroundColor: colors.success,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 5
            }]
        },
        options: {
            responsive: true,
            interaction: {
                intersect: false,
                mode: 'index'
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString() + ' F CFA';
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + ' F';
                        }
                    }
                }
            }
        }
    });

    // Graphique répartition par chantier
    const ctxRepartitionChantier = document.getElementById('repartitionChantierChart').getContext('2d');
    new Chart(ctxRepartitionChantier, {
        type: 'doughnut',
        data: {
            labels: chartsData.repartition_chantiers.labels,
            datasets: [{
                data: chartsData.repartition_chantiers.data,
                backgroundColor: [
                    colors.primary,
                    colors.secondary,
                    colors.success,
                    colors.warning,
                    colors.danger,
                    colors.info,
                    '#8B5CF6',
                    '#EC4899',
                    '#10B981',
                    '#F59E0B'
                ],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // Graphique top bénéficiaires
    const ctxTopBeneficiaires = document.getElementById('topBeneficiairesChart').getContext('2d');
    new Chart(ctxTopBeneficiaires, {
        type: 'bar',
        data: {
            labels: chartsData.top_beneficiaires.labels,
            datasets: [{
                label: 'Montant (F CFA)',
                data: chartsData.top_beneficiaires.data,
                backgroundColor: colors.success + '80',
                borderColor: colors.success,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + ' F';
                        }
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 45
                    }
                }
            }
        }
    });

    // Graphique évolution des chantiers
    const ctxEvolutionChantiers = document.getElementById('evolutionChantiersChart').getContext('2d');
    new Chart(ctxEvolutionChantiers, {
        type: 'bar',
        data: {
            labels: chartsData.evolution_chantiers.labels,
            datasets: [{
                label: 'Nombre de chantiers',
                data: chartsData.evolution_chantiers.data,
                backgroundColor: colors.warning + '80',
                borderColor: colors.warning,
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    // Fonction pour voir les détails d'un chantier
    function voirDetailsChantier(chantierId) {
        window.location.href = 'details_chantiers.php?chantier_id=' + chantierId;
    }

    // Fonctions d'export des graphiques
    function exportGraphique(type) {
        let chartElement;
        let filename;

        switch (type) {
            case 'evolution':
                chartElement = document.getElementById('evolutionMontantsChart');
                filename = 'evolution_depenses_fiches.png';
                break;
            case 'repartition':
                chartElement = document.getElementById('repartitionChantierChart');
                filename = 'repartition_chantiers.png';
                break;
            case 'beneficiaires':
                chartElement = document.getElementById('topBeneficiairesChart');
                filename = 'top_beneficiaires.png';
                break;
            case 'chantiers':
                chartElement = document.getElementById('evolutionChantiersChart');
                filename = 'evolution_chantiers.png';
                break;
        }

        if (chartElement) {
            const link = document.createElement('a');
            link.download = filename;
            link.href = chartElement.toDataURL();
            link.click();
        }
    }

    // Export global du dashboard
    function exportDashboard() {
        const confirmation = confirm('Voulez-vous exporter un rapport complet du tableau de bord ?');
        if (confirmation) {
            // Redirection vers une page d'export dédiée
            window.location.href = 'request/export_dashboard.php';
        }
    }

    // Accès aux budgets détaillés
    function voirTousLesBudgets() {
        window.location.href = 'selection_budgets_chantiers.php';
    }

    // Rafraîchissement automatique des données (toutes les 5 minutes)
    setInterval(function() {
        refreshDashboardData();
    }, 300000); // 5 minutes

    function refreshDashboardData() {
        fetch('request/dashboard_api.php?endpoint=refresh')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    console.error('Erreur lors du rafraîchissement:', data.error);
                } else {
                    console.log('Données du dashboard rafraîchies automatiquement');
                    // Ici, on pourrait mettre à jour les graphiques sans recharger la page
                }
            })
            .catch(error => {
                console.error('Erreur réseau:', error);
            });
    }

    // Filtrage Top N chantiers (par Entrées devis ou budget si absence devis)
    const selectTop = document.getElementById('topChantiers');
    const resetBtn = document.getElementById('resetTop');
    const tbody = document.getElementById('tbodyChantiersBudgets');
    const originalRows = Array.from(tbody ? tbody.querySelectorAll('tr') : []);

    function parseNumber(txt) {
        if (!txt) return 0;
        return parseFloat(txt.replace(/[^0-9.-]/g, '')) || 0;
    }

    function applyTopFilter() {
        if (!tbody) return;
        const val = selectTop.value;
        tbody.innerHTML = '';
        let rows = [...originalRows];
        // Trier par Entrées (devis) si colonne présente sinon par Budget
        rows.sort((a, b) => {
            const aCells = a.querySelectorAll('td');
            const bCells = b.querySelectorAll('td');
            // Colonnes: 0 chantier, 1 budget, 2 entrées, 3 dépensé, 4 marge, ...
            const aVal = aCells[2] ? parseNumber(aCells[2].innerText) : 0;
            const bVal = bCells[2] ? parseNumber(bCells[2].innerText) : 0;
            return bVal - aVal;
        });
        if (val !== 'all') rows = rows.slice(0, parseInt(val, 10));
        rows.forEach(r => tbody.appendChild(r));
    }

    if (selectTop) {
        selectTop.addEventListener('change', applyTopFilter);
    }
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            selectTop.value = 'all';
            applyTopFilter();
        });
    }
    // Initial pass (no filter)
    applyTopFilter();

    // Animation d'apparition progressive
    document.addEventListener('DOMContentLoaded', function() {
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Appliquer l'animation à tous les éléments avec la classe .animate-fade-in
        document.querySelectorAll('.bg-white').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });
    });
</script>


<script>
    (function() {
        const grid = document.getElementById('xdashGrid');
        const storageKey = 'finea_dashboard_premium_widgets_v1';
        const widgets = () => Array.from(document.querySelectorAll('#xdashGrid .xdash-widget'));
        const esc = (v) => String(v ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [c]));

        function tick() {
            const el = document.getElementById('xdashClock');
            if (el) el.textContent = new Date().toLocaleTimeString('fr-FR', {
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        tick();
        setInterval(tick, 30000);

        // Bouton X sur chaque widget
        function state() {
            try {
                return JSON.parse(localStorage.getItem(storageKey) || '{}')
            } catch (e) {
                return {}
            }
        }

        function save(st) {
            localStorage.setItem(storageKey, JSON.stringify(st))
        }

        function saveLayout() {
            const st = state();
            st.order = widgets().map(w => w.id);
            st.hidden = widgets().filter(w => w.classList.contains('is-hidden')).map(w => w.id);
            save(st);
        }

        function addCloseButtons() {
            widgets().forEach(w => {
                if (w.querySelector('.xdash-close')) return;
                const head = w.querySelector('.xdash-widget-head');
                const toolbar = head?.querySelector('.xdash-toolbar') || head;
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'xdash-close';
                btn.title = 'Supprimer ce widget';
                btn.innerHTML = '<i class="fas fa-times"></i>';
                btn.onclick = (e) => {
                    e.stopPropagation();
                    w.classList.add('is-hidden');
                    saveLayout();
                    renderSettings();
                };
                toolbar?.appendChild(btn);
            });
        }

        function applyLayout() {
            const st = state();
            if (Array.isArray(st.order)) st.order.forEach(id => {
                const el = document.getElementById(id);
                if (el) grid.appendChild(el);
            });
            if (Array.isArray(st.hidden)) st.hidden.forEach(id => document.getElementById(id)?.classList.add('is-hidden'));
        }

        function renderSettings() {
            const list = document.getElementById('xdashSettingsList');
            if (!list) return;
            list.innerHTML = widgets().map(w => `<div class="xdash-settings-row"><div><strong>${esc(w.dataset.widgetTitle||w.id)}</strong><br><small class="xdash-muted">${esc(w.id)}</small></div><button type="button" data-xshow="${w.id}">${w.classList.contains('is-hidden')?'Afficher':'Masquer'}</button></div>`).join('');
        }
        addCloseButtons();
        applyLayout();
        document.getElementById('xdashOpenSettings')?.addEventListener('click', () => {
            renderSettings();
            document.getElementById('xdashSettings')?.classList.add('show')
        });
        document.getElementById('xdashCloseSettings')?.addEventListener('click', () => document.getElementById('xdashSettings')?.classList.remove('show'));
        document.getElementById('xdashSettings')?.addEventListener('click', e => {
            if (e.target.id === 'xdashSettings') e.currentTarget.classList.remove('show')
        });
        document.getElementById('xdashSettingsList')?.addEventListener('click', e => {
            const btn = e.target.closest('[data-xshow]');
            if (!btn) return;
            document.getElementById(btn.dataset.xshow)?.classList.toggle('is-hidden');
            saveLayout();
            renderSettings();
        });
        document.getElementById('xdashShowAll')?.addEventListener('click', () => {
            widgets().forEach(w => w.classList.remove('is-hidden'));
            saveLayout();
            renderSettings()
        });
        document.getElementById('xdashReset')?.addEventListener('click', () => {
            localStorage.removeItem(storageKey);
            location.reload()
        });
        let dragged = null;
        widgets().forEach(w => {
            w.addEventListener('dragstart', e => {
                dragged = w;
                w.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move'
            });
            w.addEventListener('dragend', () => {
                dragged = null;
                widgets().forEach(x => x.classList.remove('is-dragging'));
                saveLayout()
            });
        });
        grid?.addEventListener('dragover', e => {
            e.preventDefault();
            if (!dragged) return;
            const after = widgets().filter(w => w !== dragged && !w.classList.contains('is-hidden')).reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = e.clientY - box.top - box.height / 2;
                return offset < 0 && offset > closest.offset ? {
                    offset,
                    element: child
                } : closest;
            }, {
                offset: Number.NEGATIVE_INFINITY,
                element: null
            }).element;
            after ? grid.insertBefore(dragged, after) : grid.appendChild(dragged);
        });

        // Météo GPS précise
        const WMO = {
            0: 'Ciel clair',
            1: 'Principalement clair',
            2: 'Partiellement nuageux',
            3: 'Couvert',
            45: 'Brouillard',
            48: 'Brouillard givrant',
            51: 'Bruine légère',
            53: 'Bruine',
            55: 'Bruine forte',
            61: 'Pluie faible',
            63: 'Pluie',
            65: 'Forte pluie',
            80: 'Averses faibles',
            81: 'Averses',
            82: 'Fortes averses',
            95: 'Orage'
        };
        async function reverse(lat, lon) {
            try {
                const r = await fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lon}&zoom=18&addressdetails=1`, {
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const j = await r.json();
                const a = j.address || {};
                return [a.house_number && a.road ? `${a.road} ${a.house_number}` : a.road, a.neighbourhood || a.suburb || a.quarter, a.city_district || a.town || a.city || a.county, a.country].filter(Boolean).join(', ') || 'Votre position';
            } catch (e) {
                return 'Votre position précise'
            }
        }
        async function weather(lat, lon, label) {
            try {
                const r = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,relative_humidity_2m,weather_code,wind_speed_10m&hourly=precipitation_probability&timezone=auto`);
                const j = await r.json(),
                    c = j.current || {};
                document.getElementById('xdashTemp').textContent = Math.round(c.temperature_2m ?? 0) + '°';
                document.getElementById('xdashLocation').textContent = label;
                document.getElementById('xdashWeatherDesc').textContent = WMO[c.weather_code] || 'Conditions météo locales';
                document.getElementById('xdashWind').textContent = Math.round(c.wind_speed_10m ?? 0) + ' km/h';
                document.getElementById('xdashHumidity').textContent = Math.round(c.relative_humidity_2m ?? 0) + '%';
                document.getElementById('xdashRainProb').textContent = ((j.hourly?.precipitation_probability || [])[0] ?? 0) + '%';
                document.getElementById('xdashRain').style.display = (c.weather_code >= 51 || ((j.hourly?.precipitation_probability || [])[0] ?? 0) > 45) ? 'block' : 'none';
            } catch (e) {
                document.getElementById('xdashWeatherDesc').textContent = 'Météo indisponible';
            }
        }

        function fallback() {
            weather(5.35995, -4.00826, 'Abidjan, Côte d’Ivoire');
            document.getElementById('xdashGps').textContent = 'Abidjan'
        }
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(async pos => {
                const {
                    latitude,
                    longitude,
                    accuracy
                } = pos.coords;
                document.getElementById('xdashGps').textContent = Math.round(accuracy) + ' m';
                weather(latitude, longitude, await reverse(latitude, longitude));
            }, fallback, {
                enableHighAccuracy: true,
                timeout: 13000,
                maximumAge: 180000
            });
        } else fallback();

        // Sticky Notes
        const noteKey = 'finea_dashboard_sticky_notes_v3';
        let notes = [],
            active = null;
        const list = document.getElementById('xdashNoteList'),
            title = document.getElementById('xdashNoteTitle'),
            body = document.getElementById('xdashNoteBody'),
            status = document.getElementById('xdashNoteStatus');

        function loadNotes() {
            try {
                notes = JSON.parse(localStorage.getItem(noteKey) || '[]')
            } catch (e) {
                notes = []
            }
            if (!notes.length) notes = [{
                id: 'n' + Date.now(),
                title: 'Priorités du jour',
                body: '<ul><li>À traiter aujourd’hui</li><li>Points à suivre</li></ul>',
                updatedAt: Date.now()
            }];
            active = notes[0].id;
        }

        function saveNotes() {
            localStorage.setItem(noteKey, JSON.stringify(notes));
            if (status) {
                status.textContent = 'Enregistré';
                setTimeout(() => status.textContent = notes.length + ' note' + (notes.length > 1 ? 's' : ''), 900)
            }
        }

        function current() {
            return notes.find(n => n.id === active) || notes[0]
        }

        function renderList() {
            if (!list) return;
            list.innerHTML = notes.map(n => `<button type="button" class="xdash-note-tab ${n.id===active?'active':''}" data-note="${n.id}"><strong>${esc(n.title||'Sans titre')}</strong><small>${esc((n.body||'').replace(/<[^>]+>/g,' ').replace(/\s+/g,' ').trim().slice(0,40)||'Nouvelle note')}</small></button>`).join('');
            if (status) status.textContent = notes.length + ' note' + (notes.length > 1 ? 's' : '');
        }

        function renderNote() {
            const n = current();
            if (!n || !title || !body) return;
            active = n.id;
            title.value = n.title || '';
            body.innerHTML = n.body || '';
            renderList();
        }

        function updateNote() {
            const n = current();
            if (!n) return;
            n.title = title.value || 'Sans titre';
            n.body = body.innerHTML || '';
            n.updatedAt = Date.now();
            saveNotes();
            renderList();
        }
        document.getElementById('xdashAddNote')?.addEventListener('click', () => {
            const n = {
                id: 'n' + Date.now(),
                title: 'Nouvelle note',
                body: '<ul><li></li></ul>',
                updatedAt: Date.now()
            };
            notes.unshift(n);
            active = n.id;
            saveNotes();
            renderNote();
            title.focus();
            title.select()
        });
        list?.addEventListener('click', e => {
            const b = e.target.closest('[data-note]');
            if (!b) return;
            active = b.dataset.note;
            renderNote()
        });
        title?.addEventListener('input', updateNote);
        body?.addEventListener('input', updateNote);
        document.querySelectorAll('[data-xcmd]').forEach(btn => btn.addEventListener('click', () => {
            body.focus();
            document.execCommand(btn.dataset.xcmd, false, btn.dataset.xvalue || null);
            updateNote()
        }));
        if (list && title && body) {
            loadNotes();
            renderNote();
        }

        // Calculatrice
        const display = document.getElementById('xdashCalcDisplay'),
            calcGrid = document.getElementById('xdashCalcGrid');

        function safeCalc(expr) {
            expr = String(expr || '').replace(/,/g, '.').replace(/÷/g, '/').replace(/×/g, '*').replace(/[^0-9+\-*/%.() ]/g, '');
            try {
                const r = Function('"use strict";return (' + expr + ')')();
                return Number.isFinite(r) ? String(Math.round(r * 100000000) / 100000000) : 'Erreur'
            } catch (e) {
                return 'Erreur'
            }
        }
        calcGrid?.addEventListener('click', e => {
            const b = e.target.closest('[data-calc]');
            if (!b) return;
            const v = b.dataset.calc;
            if (v === 'C') display.value = '0';
            else if (v === '=') display.value = safeCalc(display.value);
            else display.value = (display.value === '0' ? '' : display.value) + v
        });

        // Calendrier
        let calDate = new Date();

        function renderCal() {
            const grid = document.getElementById('xdashCalGrid'),
                title = document.getElementById('xdashCalTitle'),
                label = document.getElementById('xdashMonthLabel');
            if (!grid) return;
            const y = calDate.getFullYear(),
                m = calDate.getMonth();
            const first = new Date(y, m, 1);
            const last = new Date(y, m + 1, 0);
            const today = new Date();
            const month = calDate.toLocaleDateString('fr-FR', {
                month: 'long',
                year: 'numeric'
            });
            title.textContent = month;
            if (label) label.textContent = calDate.toLocaleDateString('fr-FR', {
                month: 'short'
            });
            let html = ['L', 'M', 'M', 'J', 'V', 'S', 'D'].map(d => `<span>${d}</span>`).join('');
            const offset = (first.getDay() + 6) % 7;
            for (let i = 0; i < offset; i++) html += '<span></span>';
            for (let d = 1; d <= last.getDate(); d++) {
                const isToday = d === today.getDate() && m === today.getMonth() && y === today.getFullYear();
                html += `<button type="button" class="${isToday?'today':''}">${d}</button>`
            }
            grid.innerHTML = html;
        }
        document.getElementById('xdashPrevMonth')?.addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() - 1);
            renderCal()
        });
        document.getElementById('xdashNextMonth')?.addEventListener('click', () => {
            calDate.setMonth(calDate.getMonth() + 1);
            renderCal()
        });
        renderCal();
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
