<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhLegalRequestController.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../employe/includes/helpers.php';

$pdo = \Database::getConnection();
$rhLegalController = new \RhLegalRequestController($pdo);
$manager = $rhLegalController->legalRequests();
$rangeManager = $rhLegalController->absenceRanges();
$payrollManager = $rhLegalController->payroll();

$actor = [
    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
    'name' => trim((string) ($_SESSION['user_name'] ?? '')),
    'email' => trim((string) ($_SESSION['mail_pro'] ?? '')),
];
if ($actor['name'] === '') {
    $actor['name'] = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
}
$approvalProfile = employeeFindPersonnelProfile($pdo, (string) ($actor['email'] ?? ''));

function rhDetermineApprovalRole(?array $profile): string
{
    $fonction = strtolower(trim((string) ($profile['fonction_label'] ?? '')));
    $hasRh = !empty($_SESSION['permissions']['acces_rh'] ?? 0);
    $hasDirection = !empty($_SESSION['permissions']['acces_direction'] ?? 0) || !empty($_SESSION['permissions']['acces_admin'] ?? 0);

    if ($hasDirection && $fonction !== '') {
        if (strpos($fonction, 'directeur des ressources humaines') !== false || preg_match('/(^|\s)drh(\s|$)/', $fonction) === 1) {
            return 'drh';
        }
        if (strpos($fonction, 'directeur general') !== false || preg_match('/(^|\s)dg(\s|$)/', $fonction) === 1) {
            return 'dg';
        }
    }

    if ($hasRh) {
        return 'rh';
    }

    if ($hasDirection) {
        return 'dg';
    }

    return '';
}

$actor['approval_role'] = rhDetermineApprovalRole($approvalProfile);

function statusBadge(array $request): array
{
    $meta = \EmployeeLegalRequestManager::getRequestStatusMeta($request);
    return [
        'label' => (string) ($meta['label'] ?? '—'),
        'class' => (string) ($meta['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200'),
    ];
}

function approvalRoleLabel(string $role): string
{
    return match (strtolower(trim($role))) {
        'rh' => 'RH',
        'drh' => 'DRH',
        'dg' => 'DG',
        default => 'Validation',
    };
}

function employeeLegalSignatureAsset(int $requestId, string $role, ?string $relativePath): ?string
{
    $relativePath = trim(str_replace('\\', '/', (string) $relativePath));
    if ($requestId <= 0 || $relativePath === '') {
        return null;
    }

    return '../request/view_employee_legal_signature.php?id=' . $requestId . '&role=' . urlencode($role);
}

function findLatestRequestUpdateEvent(array $events): ?array
{
    foreach ($events as $ev) {
        $type = strtolower(trim((string) ($ev['event_type'] ?? '')));
        if ($type === 'updated') {
            return $ev;
        }
    }

    return null;
}

function rhAppBasePath(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') {
        return '';
    }

    $dir = rtrim(dirname($script), '/');
    $root = rtrim(dirname($dir), '/');
    if ($root === '' || $root === '.' || $root === '/') {
        return '';
    }

    return $root;
}

function resolveUploadsHref(string $url): string
{
    $u = str_replace('\\', '/', trim($url));
    if ($u === '') {
        return '';
    }
    if (strpos($u, 'uploads/') === 0) {
        $u = '/' . $u;
    }
    if (substr($u, 0, 9) !== '/uploads/') {
        return '';
    }

    $base = rhAppBasePath();
    $candidates = [$u];
    if ($base !== '') {
        $candidates[] = $base . $u;
    }

    $docroot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), "\\/");
    if ($docroot !== '') {
        foreach ($candidates as $cand) {
            $abs = $docroot . str_replace('/', DIRECTORY_SEPARATOR, $cand);
            if (is_file($abs)) {
                return $cand;
            }
        }
    }

    return $candidates[count($candidates) - 1];
}

$attendanceLabels = [
    'present' => 'Présent',
    'half_day' => 'Demi-journée',
    'mission' => 'Mission',
    'conge' => 'Congé',
    'repos' => 'Repos',
    'absence' => 'Absent',
    'maladie' => 'Maladie',
];

$flash = '';
$flashType = 'info';
$bulkResults = [];
$_SESSION['rh_validation_bulk_csrf'] ??= bin2hex(random_bytes(32));
$bulkCsrf = $_SESSION['rh_validation_bulk_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $rhLegalController->handleValidationPost($_POST, $actor);
    $flash = (string) ($postResult['flash'] ?? '');
    $flashType = (string) ($postResult['flashType'] ?? 'info');
    $bulkResults = $postResult['bulkResults'] ?? [];
}
$validationView = $rhLegalController->validationListView($_GET);
$selectedStatus = $validationView['selectedStatus'];
$statusFilterLabel = $validationView['statusFilterLabel'];
$selectedStatuses = $validationView['selectedStatuses'];
$rows = $validationView['rows'];
$rhScope=(($_GET['scope'] ?? 'administrative')==='financial')?'financial':'administrative';
$rows=array_values(array_filter($rows,static fn(array $r):bool => in_array($r['request_type'],['pret','avance_salaire','reclamation_salariale'],true)===($rhScope==='financial')));
$viewId = $validationView['viewId'];
$view = $validationView['view'];
$viewManagedByPoint = $view && (\RhFinancialPointService::managedRequest($pdo, $view) || !empty($view['payload']['rh_financial_point_id']));
$events = $validationView['events'];

$rhSearch = trim((string)($_GET['q'] ?? ''));
$rhType = trim((string)($_GET['type'] ?? ''));
$rhRequester = trim((string)($_GET['requester'] ?? ''));
$rhDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : '';
$rhDateTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : '';
$rhTypeOptions = [];
$rhRequestTypeDefinitions = $manager->getRequestTypes();
foreach ($rhRequestTypeDefinitions as $typeKey => $typeDefinition) {
    if (($typeDefinition['team'] ?? '') === 'rh') {
        $rhTypeOptions[(string) $typeKey] = (string) ($typeDefinition['label'] ?? $typeKey);
    }
}
$rhRequesterOptions = [];
foreach ($rows as $filterRow) {
    $typeKey = trim((string)($filterRow['request_type'] ?? ''));
    if ($typeKey !== '') $rhTypeOptions[$typeKey] = (string)($filterRow['type_label'] ?? $typeKey);
    $requesterKey = trim((string)($filterRow['requester_name'] ?? ''));
    if ($requesterKey !== '') $rhRequesterOptions[$requesterKey] = $requesterKey;
}
natcasesort($rhTypeOptions);
natcasesort($rhRequesterOptions);
$rows = array_values(array_filter($rows, static function (array $row) use ($rhSearch, $rhType, $rhRequester, $rhDateFrom, $rhDateTo): bool {
    if ($rhType !== '' && (string)($row['request_type'] ?? '') !== $rhType) return false;
    if ($rhRequester !== '' && (string)($row['requester_name'] ?? '') !== $rhRequester) return false;
    $date = substr((string)($row['submitted_at'] ?? ''), 0, 10);
    if ($rhDateFrom !== '' && ($date === '' || $date < $rhDateFrom)) return false;
    if ($rhDateTo !== '' && ($date === '' || $date > $rhDateTo)) return false;
    if ($rhSearch !== '') {
        $haystack = mb_strtolower(implode(' ', [(string)($row['id'] ?? ''),(string)($row['requester_name'] ?? ''),(string)($row['type_label'] ?? $row['request_type'] ?? ''),(string)($row['title'] ?? ''),(string)($row['status'] ?? '')]), 'UTF-8');
        if (!str_contains($haystack, mb_strtolower($rhSearch, 'UTF-8'))) return false;
    }
    return true;
}));
$rhStatusCounts = ['submitted'=>0,'approved'=>0,'rejected'=>0,'cancelled'=>0];
foreach ($rows as $filteredRow) {
    $statusKey = strtolower((string)($filteredRow['status'] ?? ''));
    if ($statusKey === 'details_requested') $statusKey = 'submitted';
    if (isset($rhStatusCounts[$statusKey])) $rhStatusCounts[$statusKey]++;
}
$rhFinancialTypes = ['pret', 'avance_salaire', 'reclamation_salariale'];
$rhShowFinancialTotal = in_array($rhType, $rhFinancialTypes, true);
$rhFinancialTotal = 0.0;
if ($rhShowFinancialTotal) {
    foreach ($rows as $financialRow) {
        $financialType = (string) ($financialRow['request_type'] ?? '');
        if ($financialType !== $rhType) {
            continue;
        }

        $financialPayload = is_array($financialRow['payload'] ?? null) ? (array) $financialRow['payload'] : [];
        $rawAmount = match ($financialType) {
            'pret' => $financialPayload['montant_demande'] ?? 0,
            'avance_salaire' => $financialPayload['montant'] ?? 0,
            'reclamation_salariale' => $financialPayload['montant_manquant'] ?? 0,
            default => 0,
        };
        if (is_string($rawAmount)) {
            $rawAmount = str_replace(["\xc2\xa0", ' ', ','], ['', '', '.'], trim($rawAmount));
        }
        $rhFinancialTotal += is_numeric($rawAmount) ? (float) $rawAmount : 0.0;
    }
}
$rhFinancialLabel = [
    'pret' => 'Total des prêts',
    'avance_salaire' => 'Total des avances',
    'reclamation_salariale' => 'Total réclamé',
][$rhType] ?? 'Montant total';

\AuditTrail::logPageConsultation([
    'module' => 'rh',
    'action' => 'validation_demandes_rh_view',
    'entity_type' => $view ? 'employee_request' : 'employee_request_list',
    'entity_id' => $view ? (string) ($view['id'] ?? 0) : null,
    'entity_label' => $view ? (string) (($view['title'] ?? '') ?: ('request_' . (int) ($view['id'] ?? 0))) : 'validation_demandes_rh',
    'details' => [
        'selected_status' => $selectedStatus,
        'row_count' => count($rows),
        'view_id' => $view ? (int) ($view['id'] ?? 0) : null,
        'event_count' => is_array($events) ? count($events) : 0,
    ],
], $pdo);

$pageTitle = 'Validation demandes RH';
$activeMenu = 'rh';

$exportStatusParam = implode(',', $selectedStatuses);
$rhExportQuery = http_build_query(array_filter(['scope'=>$rhScope,'status'=>$exportStatusParam,'type'=>$rhType,'requester'=>$rhRequester,'q'=>$rhSearch,'date_from'=>$rhDateFrom,'date_to'=>$rhDateTo,'limit'=>500], static fn($value) => $value !== ''));
$baseListQuery = array_filter(['scope'=>$rhScope,'status'=>$selectedStatus,'type'=>$rhType,'requester'=>$rhRequester,'q'=>$rhSearch,'date_from'=>$rhDateFrom,'date_to'=>$rhDateTo], static fn($value) => $value !== '' && $value !== 'all');
$baseListUrl = 'validation_demandes_rh.php' . ($baseListQuery ? ('?' . http_build_query($baseListQuery)) : '');

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<style>
    .signature-pad-shell {
        position: relative;
        border: 1px dashed rgba(148, 163, 184, 0.85);
        border-radius: 1.5rem;
        background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        overflow: hidden;
    }

    .signature-pad-shell canvas {
        width: 100%;
        height: 160px;
        display: block;
        cursor: crosshair;
        touch-action: none;
    }

    .signature-pad-hint {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        color: rgb(100 116 139);
        pointer-events: none;
    }

    .rh-validation-modal {
        position: fixed;
        inset: 0;
        z-index: 200;
        margin: 0 !important;
        padding: clamp(1rem, 3vw, 2.5rem);
        overflow-y: auto;
        align-content: start;
        background: rgb(2 6 23 / .78);
        backdrop-filter: blur(12px);
    }
    .rh-validation-modal > * { max-height: calc(100vh - 5rem); overflow-y: auto; }
    .rh-validation-modal > .rh-modal-heading { max-height:none;overflow:visible;grid-column:1/-1; }
    .rh-approval-flow { width: 100%; max-width: 100%; overflow: hidden; }
    .rh-approval-step {
        display: grid;
        grid-template-columns: 3rem minmax(0, 1fr) minmax(8rem, 11rem);
        align-items: center;
        gap: .9rem;
        min-width: 0;
        padding: .9rem;
        border: 1px solid rgb(226 232 240);
        border-radius: 1.25rem;
        background: linear-gradient(135deg, #fff 0%, rgb(248 250 252) 100%);
        box-shadow: 0 8px 25px rgb(15 23 42 / .05);
    }
    .rh-approval-step.is-complete { border-color: color-mix(in srgb, var(--brand-primary, #059669) 30%, white); }
    .rh-approval-step > * { min-width: 0; }
    .rh-approval-icon {
        display: grid;
        width: 3rem;
        height: 3rem;
        place-items: center;
        border-radius: 1rem;
        color: var(--brand-primary, #059669);
        background: color-mix(in srgb, var(--brand-primary, #059669) 12%, white);
    }
    .rh-approval-step.is-complete .rh-approval-icon { color: white; background: var(--brand-primary, #059669); }
    .rh-approval-copy { overflow-wrap: anywhere; }
    .rh-approval-signature {
        display: flex;
        width: 100%;
        height: 4.5rem;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        border: 1px dashed rgb(203 213 225);
        border-radius: 1rem;
        background: #fff;
        color: rgb(148 163 184);
        font-size: .72rem;
        text-align: center;
    }
    .rh-approval-signature img { display: block; max-width: 100%; max-height: 3.8rem; object-fit: contain; }
    .rh-approval-connector {
        position: relative;
        display: grid;
        width: 3rem;
        height: 2.25rem;
        place-items: end center;
        color: var(--brand-primary, #059669);
    }
    .rh-approval-connector::before {
        position: absolute;
        top: 0;
        bottom: .55rem;
        width: 2px;
        content: '';
        background: color-mix(in srgb, var(--brand-primary, #059669) 45%, white);
    }
    .rh-approval-connector i { position: relative; z-index: 1; font-size: .75rem; }
    @media(max-width:639px) {
        .rh-approval-step { grid-template-columns: 2.75rem minmax(0, 1fr); gap: .75rem; padding: .8rem; }
        .rh-approval-icon { width: 2.75rem; height: 2.75rem; }
        .rh-approval-signature { grid-column: 1 / -1; height: 4rem; }
        .rh-approval-connector { width: 2.75rem; }
    }
    @media(max-width:1023px){.rh-validation-modal > *{max-height:none}.rh-validation-modal{padding:.75rem}}
</style>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-emerald-600 via-slate-900 to-indigo-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">RH</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Demandes employé — Validation RH</h1>
                    <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($statusFilterLabel) ?> — catégorie <?= $rhScope==='financial'?'financière':'administrative' ?>.</p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="../request/export_demandes_rh_pdf.php?<?= htmlspecialchars($rhExportQuery) ?>" target="_blank" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-file-pdf mr-2"></i>Export PDF ISO</a>
                    <a href="../request/export_demandes_rh_excel.php?<?= htmlspecialchars($rhExportQuery) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50"><i class="fas fa-file-excel mr-2"></i>Export Excel</a>
                    <a href="dashboard_rh.php" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">Tableau de bord RH</a>
                </div>
            </div>

            <nav class="mt-4 flex gap-3"><a href="validation_demandes_rh.php?scope=administrative" class="rounded-xl border p-3">Demandes administratives</a><a href="validation_demandes_rh.php?scope=financial" class="rounded-xl border p-3">Demandes financières</a><a href="points_financiers_rh.php" class="rounded-xl bg-slate-900 p-3 text-white">Arrêter un point financier</a></nav>
            <form method="get" class="mt-6 rounded-3xl border border-slate-200 bg-slate-50/80 p-5 shadow-inner">
                <input type="hidden" name="status" value="<?= htmlspecialchars($selectedStatus) ?>">
                <input type="hidden" name="scope" value="<?=htmlspecialchars($rhScope)?>"><div class="flex flex-wrap items-center justify-between gap-3">
                    <div><p class="text-xs font-black uppercase tracking-[.2em] text-slate-500">Recherche avancée</p><p class="mt-1 text-sm text-slate-600">Les indicateurs et les exports utilisent exactement les filtres appliqués.</p></div>
                    <a href="<?= htmlspecialchars($baseListUrl) ?>" class="rounded-xl border bg-white px-3 py-2 text-xs font-bold text-slate-600">Réinitialiser</a>
                </div>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                    <label class="xl:col-span-2"><span class="text-xs font-bold text-slate-600">Recherche libre</span><input name="q" value="<?= htmlspecialchars($rhSearch) ?>" placeholder="Référence, demandeur, titre…" class="mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm"></label>
                    <label><span class="text-xs font-bold text-slate-600">Type de demande</span><select name="type" class="mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm"><option value="">Tous les types</option><?php foreach($rhTypeOptions as $key=>$label):?><option value="<?=htmlspecialchars($key)?>" <?=$rhType===$key?'selected':''?>><?=htmlspecialchars($label)?></option><?php endforeach;?></select></label>
                    <label><span class="text-xs font-bold text-slate-600">Demandeur</span><select name="requester" class="mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm"><option value="">Tous</option><?php foreach($rhRequesterOptions as $option):?><option value="<?=htmlspecialchars($option)?>" <?=$rhRequester===$option?'selected':''?>><?=htmlspecialchars($option)?></option><?php endforeach;?></select></label>
                    <label><span class="text-xs font-bold text-slate-600">Du</span><input type="date" name="date_from" value="<?=htmlspecialchars($rhDateFrom)?>" class="mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm"></label>
                    <label><span class="text-xs font-bold text-slate-600">Au</span><input type="date" name="date_to" value="<?=htmlspecialchars($rhDateTo)?>" class="mt-1 w-full rounded-xl border bg-white px-3 py-2.5 text-sm"></label>
                </div>
                <div class="mt-4 flex justify-end"><button class="rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-black text-white"><i class="fas fa-filter mr-2"></i>Appliquer les filtres</button></div>
            </form>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 <?= $rhShowFinancialTotal ? 'xl:grid-cols-6' : 'xl:grid-cols-5' ?>">
                <?php foreach([['Total',count($rows),'fa-layer-group'],['En attente',$rhStatusCounts['submitted'],'fa-hourglass-half'],['Approuvées',$rhStatusCounts['approved'],'fa-circle-check'],['Refusées',$rhStatusCounts['rejected'],'fa-circle-xmark'],['Annulées',$rhStatusCounts['cancelled'],'fa-ban']] as [$label,$count,$icon]):?>
                    <div class="rounded-2xl border border-slate-200 bg-white p-4"><div class="flex items-center justify-between"><span class="text-xs font-black uppercase tracking-wider text-slate-500"><?=htmlspecialchars($label)?></span><i class="fas <?=htmlspecialchars($icon)?> text-amber-500"></i></div><strong class="mt-2 block text-2xl text-slate-950"><?=(int)$count?></strong></div>
                <?php endforeach;?>
                <?php if ($rhShowFinancialTotal): ?>
                    <div class="relative overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 p-4">
                        <div class="absolute inset-y-0 left-0 w-1 bg-[var(--brand-accent)]"></div>
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-black uppercase tracking-wider text-slate-600"><?= htmlspecialchars($rhFinancialLabel) ?></span>
                            <i class="fas fa-coins text-amber-600"></i>
                        </div>
                        <strong class="mt-2 block whitespace-nowrap text-xl text-slate-950"><?= number_format($rhFinancialTotal, 0, ',', ' ') ?> <span class="text-xs font-bold">FCFA</span></strong>
                        <span class="mt-1 block text-[11px] text-slate-500">Selon les filtres appliqués</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <?php
                $tabs = [
                    'submitted' => 'En attente',
                    'approved' => 'Approuvées',
                    'rejected' => 'Refusées',
                    'cancelled' => 'Annulées',
                    'all' => 'Toutes',
                ];
                foreach ($tabs as $key => $label) {
                    $active = $selectedStatus === $key;
                    $tabQuery = array_filter(['status'=>$key,'type'=>$rhType,'requester'=>$rhRequester,'q'=>$rhSearch,'date_from'=>$rhDateFrom,'date_to'=>$rhDateTo], static fn($value) => $value !== '');
                    $url = 'validation_demandes_rh.php?' . http_build_query($tabQuery);
                    $cls = $active
                        ? 'bg-slate-950 text-white border-slate-950'
                        : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50';
                    echo '<a href="' . htmlspecialchars($url) . '" class="rounded-full border px-4 py-2 text-sm font-semibold transition ' . $cls . '">' . htmlspecialchars($label) . '</a>';
                }
                ?>
            </div>

            <?php if ($flash): ?>
                <?php
                $flashClasses = [
                    'success' => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                    'error' => 'border-rose-200 bg-rose-50 text-rose-900',
                    'info' => 'border-slate-200 bg-slate-50 text-slate-800',
                ];
                ?>
                <div class="mt-5 rounded-2xl border px-4 py-4 text-sm <?= $flashClasses[$flashType] ?? $flashClasses['info'] ?>">
                    <?= htmlspecialchars($flash) ?>
                </div>
            <?php endif; ?>

            <?php if ($bulkResults): ?>
                <ul class="mt-4 space-y-2 text-sm" role="status">
                    <?php foreach ($bulkResults as $result): ?>
                        <li class="rounded-xl border p-3 <?= $result['type'] === 'success' ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50' ?>">#<?= (int) $result['id'] ?> — <?= htmlspecialchars($result['message']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($viewId > 0 && !$view): ?>
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3" role="alert">La demande #<?= (int) $viewId ?> est introuvable ou ne relève pas des RH.</p>
            <?php endif; ?>
            <form id="rhBulkForm" method="post" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($bulkCsrf) ?>">
                <p class="font-semibold"><span id="rhBulkCount">0</span> demande(s) sélectionnée(s)</p>
                <p class="mt-1 text-xs text-slate-600">Sélectionnez jusqu’à 100 demandes dans la liste filtrée. Chaque décision respecte le circuit de validation. Les demandes financières gérées par un point se traitent dans ce point.</p>
                <label class="mt-3 block text-sm">Note commune (optionnelle)<textarea name="note" rows="2" class="mt-1 w-full rounded-xl border p-3"></textarea></label>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="submit" name="action" value="bulk_approve" class="rounded-xl bg-emerald-600 px-4 py-2 font-semibold text-white">Approuver la sélection</button>
                    <button type="submit" name="action" value="bulk_reject" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 font-semibold text-rose-800">Refuser la sélection</button>
                </div>
            </form>
            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-500">
                            <th class="px-3 py-3 font-medium"><input type="checkbox" id="rhBulkAll" aria-label="Sélectionner les demandes traitables de la liste filtrée"></th>
                            <th class="px-3 py-3 font-medium">Réf.</th>
                            <th class="px-3 py-3 font-medium">Demandeur</th>
                            <th class="px-3 py-3 font-medium">Type</th>
                            <th class="px-3 py-3 font-medium">Objet</th>
                            <th class="px-3 py-3 font-medium">Statut</th>
                            <th class="px-3 py-3 font-medium">Date</th>
                            <th class="px-3 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td class="px-3 py-4 text-slate-600" colspan="8">Aucune demande.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php $badge = statusBadge($row); ?>
                                <?php $rowStatus = strtolower(trim((string) ($row['status'] ?? ''))); ?>
                                <?php
                                $rowManagedByPoint = \RhFinancialPointService::managedRequest($pdo, $row) || !empty($row['payload']['rh_financial_point_id']);
                                $rowCanApprove = !$rowManagedByPoint && $manager->canActorApproveRequest($row, $actor);
                                $rowCanReject = !$rowManagedByPoint && $manager->canActorRejectRequest($row, $actor);
                                ?>
                                <?php
                                $rowSubmittedAt = strtotime((string) ($row['submitted_at'] ?? '')) ?: 0;
                                $rowUpdatedAt = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
                                $wasResubmitted = $rowStatus === 'submitted' && $rowUpdatedAt > 0 && $rowSubmittedAt > 0 && $rowUpdatedAt > $rowSubmittedAt;
                                ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-4"><input type="checkbox" form="rhBulkForm" name="request_ids[]" value="<?= (int) ($row['id'] ?? 0) ?>" class="rh-bulk-item" data-can-approve="<?= $rowCanApprove ? '1' : '0' ?>" data-can-reject="<?= $rowCanReject ? '1' : '0' ?>" <?= ($rowCanApprove || $rowCanReject) ? '' : 'disabled' ?> aria-label="Sélectionner la demande #<?= (int) ($row['id'] ?? 0) ?>"></td>
                                    <td class="px-3 py-4 font-semibold text-slate-800">#<?= (int) ($row['id'] ?? 0) ?></td>
                                    <td class="px-3 py-4 text-slate-700"><?= htmlspecialchars((string) ($row['requester_name'] ?? '')) ?></td>
                                    <td class="px-3 py-4 text-slate-700"><?= htmlspecialchars((string) ($row['type_label'] ?? $row['request_type'])) ?></td>
                                    <td class="px-3 py-4 text-slate-700">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span><?= htmlspecialchars((string) ($row['title'] ?? '')) ?></span>
                                            <?php if ($wasResubmitted): ?>
                                                <span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">Renvoyée</span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($wasResubmitted): ?>
                                            <div class="mt-1 text-xs text-sky-700">Dernière modification: <?= htmlspecialchars((string) ($row['updated_at'] ?? '')) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars((string) ($row['submitted_at'] ?? '')) ?></td>
                                    <td class="px-3 py-4 text-right">
                                        <?php $actionLabel = $rowManagedByPoint ? 'Voir / Point RH' : (($rowStatus === 'submitted') ? 'Traiter' : 'Voir'); ?>
                                        <a class="inline-flex items-center rounded-xl bg-slate-950 px-3 py-2 text-sm font-bold text-white" href="validation_demandes_rh.php?<?= htmlspecialchars(http_build_query(array_filter(['id'=>(int)($row['id']??0),'scope'=>$rhScope,'status'=>$selectedStatus,'type'=>$rhType,'requester'=>$rhRequester,'q'=>$rhSearch,'date_from'=>$rhDateFrom,'date_to'=>$rhDateTo],static fn($v)=>$v!==''))) ?>"><i class="fas fa-arrow-up-right-from-square mr-2 text-amber-400"></i><?= htmlspecialchars($actionLabel) ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($view): ?>
            <?php
            $viewStatus = strtolower(trim((string) ($view['status'] ?? '')));
            $canDecide = !$viewManagedByPoint && ($manager->canActorApproveRequest($view, $actor) || $manager->canActorRejectRequest($view, $actor));
            $canSignCurrentStage = false;
            $currentApprovalRole = $manager->getExpectedApprovalRole($view);
            if (!$viewManagedByPoint && in_array((string)($view['request_type'] ?? ''), ['reclamation_salariale','recrutement'], true) && $currentApprovalRole !== '') {
                $canSignCurrentStage = $manager->canActorSaveSignature($view, $currentApprovalRole, $actor);
            }
            $decisionEvent = null;
            if (is_array($events) && $events) {
                foreach ($events as $ev) {
                    $t = strtolower(trim((string) ($ev['event_type'] ?? '')));
                    if (in_array($t, ['approved', 'rejected', 'cancelled', 'rh_approved', 'drh_approved', 'rh_rejected', 'drh_rejected', 'dg_rejected'], true)) {
                        $decisionEvent = $ev;
                        break;
                    }
                }
            }
            $latestUpdateEvent = findLatestRequestUpdateEvent(is_array($events) ? $events : []);
            $badge = statusBadge($view);
            $statusMeta = \EmployeeLegalRequestManager::getRequestStatusMeta($view);
            $employeeSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'employee', $view['employee_signature_path'] ?? '');
            $rhSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'rh', $view['rh_signature_path'] ?? '');
            $drhSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'drh', $view['drh_signature_path'] ?? '');
            $dgSignatureUrl = employeeLegalSignatureAsset((int) ($view['id'] ?? 0), 'dg', $view['dg_signature_path'] ?? '');
            ?>
            <div id="rhValidationModal" class="rh-validation-modal grid gap-4 lg:grid-cols-[1.2fr_0.8fr]" role="dialog" aria-modal="true" aria-labelledby="rhValidationModalTitle">
                <div class="rh-modal-heading flex items-center justify-between rounded-2xl border border-white/15 bg-slate-950 px-5 py-4 text-white shadow-2xl">
                    <div><p class="text-xs font-black uppercase tracking-[.24em] text-amber-400">Traitement RH</p><h2 id="rhValidationModalTitle" class="mt-1 text-xl font-black">Demande #<?= (int)($view['id'] ?? 0) ?> · <?= htmlspecialchars((string)($view['type_label'] ?? $view['request_type'] ?? '')) ?></h2></div>
                    <a href="<?= htmlspecialchars($baseListUrl) ?>" class="grid h-11 w-11 place-items-center rounded-xl bg-white/10 text-xl hover:bg-white/20" aria-label="Fermer"><i class="fas fa-xmark"></i></a>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Détail</p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">#<?= (int) ($view['id'] ?? 0) ?> — <?= htmlspecialchars((string) ($view['type_label'] ?? $view['request_type'])) ?></h2>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                <?php if (is_array($latestUpdateEvent)): ?>
                                    <span class="inline-flex items-center rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold text-sky-700">Demande renvoyée après modification</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            <a href="../request/export_demande_rh_fiche_pdf.php?id=<?= (int) ($view['id'] ?? 0) ?>" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Fiche PDF</a>
                            <a href="<?= htmlspecialchars($baseListUrl) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50">Fermer</a>
                        </div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Demandeur</div>
                            <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($view['requester_name'] ?? '')) ?></div>
                            <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($view['requester_email'] ?? '')) ?></div>
                            <div class="mt-2 text-sm text-slate-700"><span class="font-semibold">Soumise le:</span> <?= htmlspecialchars((string) ($view['submitted_at'] ?? '')) ?></div>
                            <?php if (is_array($latestUpdateEvent)): ?>
                                <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-700">Dernière modification</div>
                                    <div class="mt-2"><span class="font-semibold">Le:</span> <?= htmlspecialchars((string) ($latestUpdateEvent['created_at'] ?? ($view['updated_at'] ?? ''))) ?></div>
                                    <?php if (!empty($latestUpdateEvent['event_by_name'])): ?>
                                        <div class="mt-1"><span class="font-semibold">Par:</span> <?= htmlspecialchars((string) $latestUpdateEvent['event_by_name']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($latestUpdateEvent['event_note'])): ?>
                                        <div class="mt-1 whitespace-pre-line"><?= htmlspecialchars((string) $latestUpdateEvent['event_note']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Contenu</div>
                            <div class="mt-3 space-y-2 text-sm text-slate-700">
                                <?php if (($view['request_type'] ?? '') === 'regularisation_pointage'): ?>
                                    <?php
                                    $payload = (array) ($view['payload'] ?? []);
                                    $attDate = (string) ($payload['attendance_date'] ?? '');
                                    $before = strtolower((string) ($payload['attendance_status_before'] ?? ''));
                                    $requested = strtolower((string) ($payload['requested_status'] ?? ''));
                                    $note = (string) ($payload['note'] ?? '');
                                    $justifs = (array) ($payload['justificatifs'] ?? []);
                                    $absenceStart = (string) ($payload['absence_date_debut'] ?? $attDate);
                                    $absenceEnd = (string) ($payload['absence_date_fin'] ?? $attDate);
                                    $rangeStart = (string) ($payload['date_debut_justificatif'] ?? $attDate);
                                    $rangeEnd = (string) ($payload['date_fin_justificatif'] ?? $rangeStart);
                                    $coveredDays = (int) ($payload['nombre_jours_justifies'] ?? 1);
                                    ?>
                                    <div><span class="font-semibold">Date:</span> <?= htmlspecialchars($attDate) ?></div>
                                    <div><span class="font-semibold">Periode d'absence:</span> <?= htmlspecialchars($absenceStart) ?> - <?= htmlspecialchars($absenceEnd) ?></div>
                                    <div><span class="font-semibold">Plage couverte:</span> <?= htmlspecialchars($rangeStart) ?> - <?= htmlspecialchars($rangeEnd) ?> (<?= (int) $coveredDays ?> j)</div>
                                    <div><span class="font-semibold">Statut initial:</span> <?= htmlspecialchars($attendanceLabels[$before] ?? ($before ?: '—')) ?></div>
                                    <div><span class="font-semibold">Statut demandé:</span> <?= htmlspecialchars($attendanceLabels[$requested] ?? ($requested ?: '—')) ?></div>
                                    <?php if (trim($note) !== ''): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif</div>
                                            <div class="mt-1 whitespace-pre-line text-slate-700"><?= htmlspecialchars($note) ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($justifs): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Justificatifs</div>
                                            <ul class="mt-2 space-y-2">
                                                <?php foreach ($justifs as $j): ?>
                                                    <?php if (!is_array($j)) continue; ?>
                                                    <?php
                                                    $safeUrl = resolveUploadsHref((string) ($j['url'] ?? ''));
                                                    ?>
                                                    <li class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                                        <div>
                                                            <div class="text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($j['name'] ?? '')) ?></div>
                                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) round(((int) ($j['size'] ?? 0)) / 1024)) ?> Ko</div>
                                                        </div>
                                                        <?php if ($safeUrl !== ''): ?>
                                                            <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" href="<?= htmlspecialchars($safeUrl) ?>" target="_blank" rel="noopener">Ouvrir</a>
                                                        <?php else: ?>
                                                            <span class="text-xs text-slate-500">Indisponible</span>
                                                        <?php endif; ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (($view['request_type'] ?? '') === 'permission_absence'): ?>
                                    <?php $payload = (array) ($view['payload'] ?? []); ?>
                                    <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Date de départ:</span> <?= htmlspecialchars((string) ($payload['date_depart'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Nombre de jours:</span> <?= htmlspecialchars((string) ($payload['nombre_jours'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Date de retour:</span> <?= htmlspecialchars((string) ($payload['date_retour'] ?? '')) ?></div>
                                    <?php if (trim((string) ($payload['motif_demande'] ?? '')) !== ''): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif</div>
                                            <div class="mt-1 whitespace-pre-line rounded-2xl border border-sky-100 bg-sky-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif_demande'] ?? '')) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (($view['request_type'] ?? '') === 'conge'): ?>
                                    <?php $payload = (array) ($view['payload'] ?? []); ?>
                                    <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Date de debut:</span> <?= htmlspecialchars((string) ($payload['date_debut'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Date de fin:</span> <?= htmlspecialchars((string) ($payload['date_fin'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Nombre de jours:</span> <?= htmlspecialchars((string) ($payload['nombre_jours'] ?? '')) ?></div>
                                    <?php if (trim((string) ($payload['motif'] ?? '')) !== ''): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif</div>
                                            <div class="mt-1 whitespace-pre-line rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif'] ?? '')) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <?php $leaveCalc = is_array($payload['leave_calculation'] ?? null) ? (array) $payload['leave_calculation'] : []; ?>
                                    <?php if ($leaveCalc): ?>
                                        <?php $leaveSourceLabel = match ((string) ($leaveCalc['source'] ?? '')) {
                                            'opening_balance' => 'Solde initial RH',
                                            'hire_date' => 'Date d\'embauche / prise de service',
                                            default => 'Historique ERP / validation RH speciale',
                                        }; ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Base de calcul congé</div>
                                            <div class="mt-1 rounded-2xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-slate-700">
                                                <div><span class="font-semibold">Source:</span> <?= htmlspecialchars($leaveSourceLabel) ?></div>
                                                <div><span class="font-semibold">Date de référence:</span> <?= htmlspecialchars((string) ($leaveCalc['reference_date'] ?? '')) ?></div>
                                                <div><span class="font-semibold">Solde avant demande:</span> <?= number_format((float) ($leaveCalc['balance_before_request'] ?? 0), 2, ',', ' ') ?> j</div>
                                                <div><span class="font-semibold">Solde estimé après demande:</span> <?= number_format((float) ($leaveCalc['balance_after_request'] ?? 0), 2, ',', ' ') ?> j</div>
                                                <?php if (!empty($leaveCalc['requires_special_validation'])): ?>
                                                    <div class="mt-2 font-semibold text-amber-800">Validation RH spéciale requise: situation initiale non renseignée.</div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (($view['request_type'] ?? '') === 'retard'): ?>
                                    <?php $payload = (array) ($view['payload'] ?? []); ?>
                                    <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($payload['nom'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($payload['matricule'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($payload['fonction'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($payload['service'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Date du retard:</span> <?= htmlspecialchars((string) ($payload['date_retard'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Heure d'arrivee:</span> <?= htmlspecialchars((string) ($payload['heure_arrivee'] ?? '')) ?></div>
                                    <?php if (trim((string) ($payload['duree_retard'] ?? '')) !== ''): ?>
                                        <div><span class="font-semibold">Duree:</span> <?= htmlspecialchars((string) ($payload['duree_retard'] ?? '')) ?></div>
                                    <?php endif; ?>
                                    <?php if (trim((string) ($payload['motif'] ?? '')) !== ''): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Motif</div>
                                            <div class="mt-1 whitespace-pre-line rounded-2xl border border-orange-100 bg-orange-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($payload['motif'] ?? '')) ?></div>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif (($view['request_type'] ?? '') === 'recrutement'): ?>
                                    <?php $payload=(array)($view['payload']??[]);?><div><span class="font-semibold">Poste recherché:</span> <?=htmlspecialchars((string)($payload['poste_recherche']??''))?></div><div><span class="font-semibold">Service demandeur:</span> <?=htmlspecialchars((string)($payload['service_demandeur']??''))?></div><div><span class="font-semibold">Effectif demandé:</span> <?=(int)($payload['nombre_postes']??0)?></div><div><span class="font-semibold">Type de contrat:</span> <?=htmlspecialchars((string)($payload['type_contrat']??''))?></div><div><span class="font-semibold">Date souhaitée:</span> <?=htmlspecialchars((string)($payload['date_souhaitee']??''))?></div>
                                    <div class="grid gap-3 pt-2 sm:grid-cols-3"><div class="rounded-2xl bg-emerald-50 p-4"><small class="font-bold text-emerald-700">Entrant estimé</small><b class="block"><?=number_format((float)($payload['impact_financier_entrant']??0),0,',',' ')?> FCFA</b></div><div class="rounded-2xl bg-rose-50 p-4"><small class="font-bold text-rose-700">Sortant estimé</small><b class="block"><?=number_format((float)($payload['impact_financier_sortant']??0),0,',',' ')?> FCFA</b></div><div class="rounded-2xl bg-indigo-50 p-4"><small class="font-bold text-indigo-700">Impact net</small><b class="block"><?=number_format((float)($payload['impact_financier_net']??0),0,',',' ')?> FCFA</b></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Justification</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-indigo-50 p-4"><?=htmlspecialchars((string)($payload['motif_recrutement']??''))?></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Profil attendu</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-slate-50 p-4"><?=htmlspecialchars((string)($payload['profil_recherche']??''))?></div></div><div class="pt-2"><div class="text-xs font-semibold uppercase tracking-[.2em] text-slate-500">Valeur ajoutée</div><div class="mt-1 whitespace-pre-line rounded-2xl bg-amber-50 p-4"><?=htmlspecialchars((string)($payload['valeur_ajoutee']??''))?></div></div>
                                    <div class="mt-4 rounded-2xl border border-violet-200 bg-violet-50 p-4"><div class="flex items-center justify-between gap-3"><b class="text-violet-950">Circuit signé RH → DG</b><span class="rounded-full bg-white px-3 py-1 text-xs font-bold text-violet-700"><?=htmlspecialchars((string)($statusMeta['label']??''))?></span></div><div class="mt-3 grid gap-3 sm:grid-cols-2"><?php foreach([['RH',$view['rh_signed_by_name']??'',$view['rh_signed_at']??''],['Direction Générale',$view['dg_signed_by_name']??'',$view['dg_signed_at']??'']] as [$stageLabel,$signedName,$signedAt]):?><div class="rounded-xl bg-white p-3"><b><?=htmlspecialchars($stageLabel)?></b><small class="mt-1 block text-slate-500"><?=$signedAt?'Signé le '.htmlspecialchars((string)$signedAt):'En attente de signature'?></small><?php if($signedName):?><span class="mt-1 block text-sm font-semibold text-emerald-700"><?=htmlspecialchars((string)$signedName)?></span><?php endif;?></div><?php endforeach;?></div></div>
                                    <?php if($canSignCurrentStage):?><form method="POST" data-passkey-signature data-passkey-endpoint-base="../request" class="js-signature-pad mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><input type="hidden" name="action" value="save_signature"><input type="hidden" name="request_id" value="<?=(int)($view['id']??0)?>"><input type="hidden" name="signature_role" value="<?=htmlspecialchars($currentApprovalRole)?>"><input type="hidden" name="signature_base64" class="js-signature-value"><b class="text-emerald-950">Signature <?=htmlspecialchars(approvalRoleLabel($currentApprovalRole))?> protégée par Passkey</b><p class="mt-1 text-sm text-emerald-800">Dessinez ou utilisez votre signature, puis confirmez obligatoirement votre identité avec votre Passkey.</p><div class="mt-3 signature-pad-shell"><canvas class="js-signature-canvas"></canvas><div class="signature-pad-hint js-signature-hint">Signez ici avec la souris ou le doigt</div></div><div class="js-signature-status mt-2 text-xs">Aucune signature capturée</div><div class="mt-3 flex gap-2"><button type="button" class="js-signature-clear rounded-xl border bg-white px-4 py-2">Effacer</button><button type="button" class="js-signature-use rounded-xl bg-emerald-100 px-4 py-2">Utiliser la signature</button><button type="submit" class="rounded-xl bg-emerald-700 px-4 py-2 font-bold text-white">Signer avec ma Passkey</button></div></form><?php endif;?>
                                <?php elseif (($view['request_type'] ?? '') === 'reclamation_salariale'): ?>
                                    <?php $claim = (array) ($view['salary_claim_display'] ?? \EmployeeLegalRequestManager::extractSalaryClaimDisplay($view)); ?>
                                    <div><span class="font-semibold">Date de la demande:</span> <?= htmlspecialchars((string) ($claim['date_demande'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Nom:</span> <?= htmlspecialchars((string) ($claim['nom'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Prénoms:</span> <?= htmlspecialchars((string) ($claim['prenoms'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Matricule:</span> <?= htmlspecialchars((string) ($claim['matricule'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Service:</span> <?= htmlspecialchars((string) ($claim['service'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Fonction:</span> <?= htmlspecialchars((string) ($claim['fonction'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Objet:</span> <?= htmlspecialchars((string) ($claim['objet_label'] ?? '')) ?></div>
                                    <div><span class="font-semibold">Mois concerné:</span> <?= htmlspecialchars((string) ($claim['mois_label'] ?? '—')) ?></div>
                                    <div><span class="font-semibold">Montant manquant:</span> <?= htmlspecialchars((string) ($claim['montant_label'] ?? '—')) ?></div>
                                    <?php if (trim((string) ($claim['details_differend'] ?? '')) !== ''): ?>
                                        <div class="pt-2">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Détails du différend</div>
                                            <div class="mt-1 whitespace-pre-line rounded-2xl border border-rose-100 bg-rose-50 px-4 py-3 text-slate-700"><?= htmlspecialchars((string) ($claim['details_differend'] ?? '')) ?></div>
                                        </div>
                                    <?php endif; ?>
                                    <div class="pt-2">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Circuit d’approbation</div>
                                            <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars((string) ($statusMeta['label'] ?? '')) ?></span>
                                        </div>
                                        <p class="mt-2 text-xs text-slate-500">Progression de la demande, du demandeur jusqu’à la Direction Générale.</p>
                                        <?php $signers = [
                                            ['role' => 'Demandeur', 'icon' => 'fa-user-pen', 'display' => trim(((string) ($claim['prenoms'] ?? '')) . ' ' . ((string) ($claim['nom'] ?? ''))), 'signed_at' => (string) ($view['employee_signed_at'] ?? ''), 'url' => $employeeSignatureUrl],
                                            ['role' => 'Ressources humaines', 'icon' => 'fa-users-gear', 'display' => (string) (($view['rh_signed_by_name'] ?? '') ?: ($view['rh_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['rh_signed_at'] ?? ''), 'url' => $rhSignatureUrl],
                                            ['role' => 'Direction RH', 'icon' => 'fa-user-shield', 'display' => (string) (($view['drh_signed_by_name'] ?? '') ?: ($view['drh_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['drh_signed_at'] ?? ''), 'url' => $drhSignatureUrl],
                                            ['role' => 'Direction Générale', 'icon' => 'fa-building-shield', 'display' => (string) (($view['dg_signed_by_name'] ?? '') ?: ($view['dg_approved_by_name'] ?? 'En attente')), 'signed_at' => (string) ($view['dg_signed_at'] ?? ''), 'url' => $dgSignatureUrl],
                                        ]; ?>
                                        <div class="rh-approval-flow mt-3">
                                            <?php foreach ($signers as $signerIndex => $signer): ?>
                                                <?php $signerComplete = !empty($signer['signed_at']) || !empty($signer['url']); ?>
                                                <?php if ($signerIndex > 0): ?>
                                                    <div class="rh-approval-connector" aria-hidden="true"><i class="fas fa-chevron-down"></i></div>
                                                <?php endif; ?>
                                                <div class="rh-approval-step<?= $signerComplete ? ' is-complete' : '' ?>">
                                                    <div class="rh-approval-icon"><i class="fas <?= htmlspecialchars((string) $signer['icon']) ?>"></i></div>
                                                    <div class="rh-approval-copy">
                                                        <div class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">Étape <?= (int) $signerIndex + 1 ?> · <?= htmlspecialchars((string) $signer['role']) ?></div>
                                                        <div class="mt-1 text-sm font-semibold text-slate-900"><?= htmlspecialchars(trim((string) ($signer['display'] ?? '')) !== '' ? (string) $signer['display'] : 'En attente') ?></div>
                                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-xs <?= $signerComplete ? 'text-emerald-700' : 'text-amber-700' ?>">
                                                            <i class="fas <?= $signerComplete ? 'fa-circle-check' : 'fa-clock' ?>"></i>
                                                            <span><?= !empty($signer['signed_at']) ? ('Signé le ' . htmlspecialchars((string) $signer['signed_at'])) : 'En attente de signature' ?></span>
                                                        </div>
                                                    </div>
                                                    <div class="rh-approval-signature">
                                                        <?php if (!empty($signer['url'])): ?>
                                                            <img src="<?= htmlspecialchars((string) $signer['url']) ?>" alt="Signature <?= htmlspecialchars((string) $signer['role']) ?>" style="max-width:100%; max-height:80px; object-fit:contain;">
                                                        <?php else: ?>
                                                            Aucune signature
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($canSignCurrentStage): ?>
                                            <form method="POST" data-passkey-signature data-passkey-endpoint-base="../request" class="js-signature-pad mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-4">
                                                <input type="hidden" name="action" value="save_signature">
                                                <input type="hidden" name="request_id" value="<?= (int) ($view['id'] ?? 0) ?>">
                                                <input type="hidden" name="signature_role" value="<?= htmlspecialchars($currentApprovalRole) ?>">
                                                <input type="hidden" name="signature_base64" value="" class="js-signature-value">
                                                <div class="text-sm font-semibold text-emerald-900">Signature <?= htmlspecialchars(approvalRoleLabel($currentApprovalRole)) ?></div>
                                                <div class="mt-2 text-sm text-emerald-800">La validation de cette étape exige une signature manuscrite avant décision.</div>
                                                <div class="mt-3 rounded-2xl border border-emerald-100 bg-white px-4 py-3 text-xs font-medium text-slate-600 js-signature-status">Aucune signature capturée</div>
                                                <div class="mt-4 signature-pad-shell">
                                                    <canvas class="js-signature-canvas"></canvas>
                                                    <div class="signature-pad-hint js-signature-hint">Signez ici avec la souris ou le doigt</div>
                                                </div>
                                                <div class="mt-4 flex flex-wrap gap-2">
                                                    <button type="button" class="js-signature-clear rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300">Effacer</button>
                                                    <button type="button" class="js-signature-use rounded-2xl border border-emerald-200 bg-emerald-100 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-200">Utiliser la signature</button>
                                                    <button type="submit" class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Enregistrer la signature</button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (($view['payload'] ?? []) as $k => $v): ?>
                                        <?php
                                        $label = ucfirst(str_replace('_', ' ', (string) $k));
                                        $value = is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                                        ?>
                                        <div><span class="font-semibold"><?= htmlspecialchars($label) ?>:</span> <?= htmlspecialchars($value ?? '') ?></div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Décision</div>
                        <?php if ($viewManagedByPoint): ?>
                            <?php
                            $pointKind = ($view['request_type'] ?? '') === 'pret' ? 'loan' : 'advance';
                            $pointId = (int) ($view['payload']['rh_financial_point_id'] ?? 0);
                            $pointUrl = 'points_financiers_rh.php?' . http_build_query($pointId > 0 ? ['id'=>$pointId] : ['kind'=>$pointKind]);
                            ?>
                            <p class="mt-3 text-sm text-slate-700">Cette demande suit le circuit financier RH : récapitulatif RH, décision et signature DG.</p>
                            <a href="<?= htmlspecialchars($pointUrl) ?>" class="mt-3 inline-flex rounded-xl bg-slate-950 px-4 py-2 font-semibold text-white">Ouvrir le point financier RH</a>
                        <?php elseif ($canDecide): ?>
                            <form method="POST" class="mt-3 space-y-3">
                                <input type="hidden" name="request_id" value="<?= (int) ($view['id'] ?? 0) ?>">
                                <?php if (($view['request_type'] ?? '') === 'regularisation_pointage'): ?>
                                    <?php
                                    $payload = (array) ($view['payload'] ?? []);
                                    $desired = strtolower((string) ($payload['requested_status'] ?? ''));
                                    $statuses = $payrollManager->getAttendanceStatuses();
                                    if (!isset($statuses[$desired])) {
                                        $desired = 'conge';
                                    }
                                    ?>
                                    <div>
                                        <label class="text-sm font-semibold text-slate-700">Appliquer le statut</label>
                                        <select name="apply_status" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2">
                                            <?php foreach ($statuses as $k => $def): ?>
                                                <?php $k = (string) $k; ?>
                                                <option value="<?= htmlspecialchars($k) ?>" <?= $k === $desired ? 'selected' : '' ?>><?= htmlspecialchars($attendanceLabels[$k] ?? (string) ($def['label'] ?? $k)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="mt-1 text-xs text-slate-500">Le pointage est mis à jour uniquement si la demande est approuvée.</div>
                                    </div>
                                <?php endif; ?>
                                <div>
                                    <label class="text-sm font-semibold text-slate-700">Note (optionnel)</label>
                                    <textarea name="note" rows="3" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2" placeholder="Commentaire RH..."></textarea>
                                </div>
                                <?php if (($view['request_type'] ?? '') === 'reclamation_salariale' && !$manager->canActorApproveRequest($view, $actor)): ?>
                                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">La validation positive de cette étape reste bloquée tant que la signature <?= htmlspecialchars(approvalRoleLabel($currentApprovalRole)) ?> n’est pas enregistrée.</div>
                                <?php endif; ?>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="submit" name="action" value="reject" class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-2 text-sm font-semibold text-rose-800 hover:bg-rose-100">Refuser</button>
                                    <button type="submit" name="action" value="approve" class="rounded-2xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700"><?= htmlspecialchars(($view['request_type'] ?? '') === 'reclamation_salariale' && ($statusMeta['stage'] ?? '') !== 'approved' ? ('Valider ' . approvalRoleLabel((string) ($actor['approval_role'] ?? ''))) : 'Approuver') ?></button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-sm font-semibold text-slate-900"><?= ($viewStatus === 'submitted' && ($view['request_type'] ?? '') === 'reclamation_salariale') ? 'Étape en attente d’un autre décideur' : 'Décision déjà enregistrée' ?></div>
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= $badge['class'] ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                </div>
                                <?php if ($decisionEvent): ?>
                                    <?php if (!empty($decisionEvent['event_by_name'])): ?>
                                        <div class="mt-2 text-sm text-slate-700"><span class="font-semibold">Par:</span> <?= htmlspecialchars((string) $decisionEvent['event_by_name']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($decisionEvent['created_at'])): ?>
                                        <div class="mt-1 text-sm text-slate-700"><span class="font-semibold">Le:</span> <?= htmlspecialchars((string) $decisionEvent['created_at']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($decisionEvent['event_note'])): ?>
                                        <div class="mt-2 text-sm text-slate-700"><span class="font-semibold">Note:</span> <span class="whitespace-pre-line"><?= htmlspecialchars((string) $decisionEvent['event_note']) ?></span></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="mt-2 text-sm text-slate-700"><?= ($viewStatus === 'submitted' && ($view['request_type'] ?? '') === 'reclamation_salariale') ? ('Cette réclamation attend actuellement la validation ' . htmlspecialchars((string) ($statusMeta['label'] ?? 'suivante')) . '.') : 'Cette demande n\'est plus en attente. Voir l\'historique à droite.' ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Journal</p>
                    <h3 class="mt-2 text-lg font-semibold text-slate-950">Historique</h3>
                    <div class="mt-4 space-y-2 text-sm">
                        <?php if (!$events): ?>
                            <div class="text-slate-600">Aucun événement.</div>
                        <?php else: ?>
                            <?php foreach ($events as $ev): ?>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="font-semibold text-slate-800"><?= htmlspecialchars((string) ($ev['event_type'] ?? '')) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($ev['created_at'] ?? '')) ?></div>
                                    </div>
                                    <?php if (!empty($ev['event_note'])): ?>
                                        <div class="mt-2 whitespace-pre-line text-slate-700"><?= htmlspecialchars((string) $ev['event_note']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($ev['event_by_name'])): ?>
                                        <div class="mt-2 text-xs text-slate-500">Par: <?= htmlspecialchars((string) $ev['event_by_name']) ?></div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($view): ?>
<script>
(() => {
    const modal = document.getElementById('rhValidationModal');
    const closeUrl = <?= json_encode($baseListUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    modal?.addEventListener('click', event => { if (event.target === modal) window.location.href = closeUrl; });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') window.location.href = closeUrl; });
    document.documentElement.style.overflow = 'hidden';
})();
</script>
<?php endif; ?>
<script>
(() => {
    const form = document.getElementById('rhBulkForm');
    const all = document.getElementById('rhBulkAll');
    const items = [...document.querySelectorAll('.rh-bulk-item:not(:disabled)')];
    const selected = () => items.filter(item => item.checked);
    const update = () => {
        const selection = selected();
        document.getElementById('rhBulkCount').textContent = selection.length;
        all.checked = items.length > 0 && selection.length === items.length;
        all.indeterminate = selection.length > 0 && selection.length < items.length;
        for (const button of form.querySelectorAll('button[type="submit"]')) {
            const key = button.value === 'bulk_approve' ? 'canApprove' : 'canReject';
            button.disabled = selection.length === 0 || selection.length > 100 || selection.some(item => item.dataset[key] !== '1');
        }
    };
    all.addEventListener('change', () => { items.forEach(item => { item.checked = all.checked; }); update(); });
    items.forEach(item => item.addEventListener('change', update));
    form.addEventListener('submit', event => {
        const selection = selected();
        const key = event.submitter?.value === 'bulk_approve' ? 'canApprove' : 'canReject';
        const valid = event.submitter && selection.length > 0 && selection.length <= 100 && selection.every(item => item.dataset[key] === '1');
        const label = key === 'canApprove' ? 'Approuver' : 'Refuser';
        if (!valid || !window.confirm(label + ' les ' + selection.length + ' demandes sélectionnées ?')) event.preventDefault();
    });
    update();
})();
</script>
<script src="../js/rh_explanation_signature_pad.js"></script>
<script src="../js/passkeys.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>


