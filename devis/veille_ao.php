<?php

require_once __DIR__ . '/includes/module_bootstrap.php';
\AccessManager::requirePageAccess('veille_ao.php');
require_once dirname(__DIR__) . '/controllers/AoController.php';

$controller = new AoController(\Database::getConnection());
if (empty($_SESSION['ao_csrf'])) {
    $_SESSION['ao_csrf'] = bin2hex(random_bytes(24));
}
$message = '';
$messageType = 'success';
$canManage = \AccessManager::hasPermission('ao_manage') || \AccessManager::hasPermission('ao_admin');
$canDgDecision = \AccessManager::hasPermission('ao_dg_decision') || \AccessManager::hasPermission('ao_admin');
$canMarkInteresting = \AccessManager::hasPermission('ao_mark_interesting') || $canDgDecision;
$canMarkNotInteresting = \AccessManager::hasPermission('ao_mark_not_interested') || $canDgDecision;
$canCreateCrm = \AccessManager::hasPermission('ao_create_crm') || \AccessManager::hasPermission('ao_admin');
$canExport = \AccessManager::hasPermission('ao_export') || \AccessManager::hasPermission('ao_admin');
$canManageDiffusion = \AccessManager::hasPermission('ao_manage_diffusion') || \AccessManager::hasPermission('ao_admin') || \AccessManager::hasPermission('acces_admin');
$canManageAlerts = \AccessManager::hasPermission('ao_manage_alerts') || \AccessManager::hasPermission('ao_admin');
$canAdmin = \AccessManager::hasPermission('ao_admin') || \AccessManager::hasPermission('acces_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['ao_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expiree.');
    }
    try {
        $action = (string) ($_POST['action'] ?? '');
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'sync' && $canAdmin) {
            $stats = $controller->sync();
            $message = sprintf('%d annonce(s) lue(s), %d nouvelle(s), %d doublon(s) ignore(s).', $stats['found'], $stats['new'], $stats['duplicates']);
            if ($stats['errors'] > 0) { $message .= ' '.$stats['errors'].' erreur(s) d’import.'; $messageType='error'; }
        } elseif ($action === 'status' && $id > 0 && $canManage) {
            $controller->updateStatus($id, (string) ($_POST['status'] ?? 'nouveau'));
            $message = 'Statut mis a jour.';
        } elseif ($action === 'mark_interesting' && $id > 0 && $canMarkInteresting) {
            $controller->updateStatus($id, 'interessant');
            $message = 'Appel d offres marque interessant, en attente de decision DG.';
        } elseif ($action === 'mark_not_interesting' && $id > 0 && $canMarkNotInteresting) {
            $controller->updateStatus($id, 'non_interessant');
            $message = 'Appel d offres marque non interessant. La decision DG reste distincte.';
        } elseif ($action === 'dg_decision' && $id > 0 && $canDgDecision) {
            $controller->recordDgDecision($id, (string) ($_POST['decision'] ?? ''), (int) ($_SESSION['user_id'] ?? 0), (string) ($_POST['note'] ?? ''));
            $message = 'Decision DG enregistree et appliquee.';
        } elseif ($action === 'crm' && $id > 0 && $canCreateCrm) {
            $crmId = $controller->createCrmOpportunity($id, (int) ($_SESSION['user_id'] ?? 0));
            $message = 'Opportunite CRM creee ou deja existante (#' . $crmId . ').';
        }
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $messageType = 'error';
    }
}

$filters = [
    'q' => trim((string) ($_GET['q'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'min_score' => trim((string) ($_GET['min_score'] ?? '')),
    'deadline_soon' => !empty($_GET['deadline_soon']),
    'deadline_scope' => in_array((string) ($_GET['deadline_scope'] ?? 'active'), ['active', 'expired', 'undated', 'all'], true)
        ? (string) ($_GET['deadline_scope'] ?? 'active')
        : 'active',
];
$view = $controller->index($filters);
$activeDevisModule = 'ao';
$moduleTitle = 'Veille Appels d Offres';
$moduleSubtitle = 'Veille DGMP et Contenu local, pertinence BTP et bilan collectif des offres ouvertes.';

function ao_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function ao_format_date($value): string
{
    if (!$value) {
        return 'Non communiquée';
    }
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $value;
}

$exportQuery = http_build_query(array_filter([
    'q' => $filters['q'],
    'status' => $filters['status'],
    'min_score' => $filters['min_score'],
    'deadline_scope' => $filters['deadline_scope'],
], static fn($value) => $value !== ''));

ob_start();
?>
<style>
    .ao-hero { background:linear-gradient(115deg,#1d2b57,#173c67 62%,#0f766e); color:#fff; }
    .ao-score { width:3.15rem;height:3.15rem;border-radius:8px;display:grid;place-items:center;font-weight:900;background:#eef2ff;color:#1d2b57; }
    .ao-score.high { background:#fff1f2;color:#9f1239; }
    .ao-score.medium { background:#fffbeb;color:#92400e; }
    .ao-scope-tabs { display:flex; gap:7px; overflow-x:auto; margin-top:18px; padding-bottom:2px; }
    .ao-scope-tab { display:inline-flex; flex:0 0 auto; min-height:37px; align-items:center; gap:7px; padding:8px 11px; border:1px solid #dce4ef; border-radius:8px; background:#fff; color:#526079; font-size:11px; font-weight:800; text-decoration:none; }
    .ao-scope-tab.is-active { border-color:#1d2b57; background:#1d2b57; color:#fff; }
    .ao-date-grid { display:grid; grid-template-columns:repeat(2,minmax(0,220px)); gap:9px; margin-top:14px; }
    .ao-date-box { display:flex; align-items:center; gap:9px; padding:10px 11px; border:1px solid #e1e7ef; border-radius:8px; background:#f8fafc; }
    .ao-date-box i { color:#31589c; }
    .ao-date-box strong,.ao-date-box small { display:block; }
    .ao-date-box small { color:#7a8699; font-size:9px; font-weight:800; text-transform:uppercase; }
    .ao-date-box strong { margin-top:2px; color:#26334a; font-size:12px; }
    .ao-deadline-badge { display:inline-flex; align-items:center; gap:6px; padding:6px 8px; border-radius:7px; background:#ecfdf5; color:#147a55; font-size:10px; font-weight:850; }
    .ao-deadline-badge.is-urgent { background:#fff4df; color:#9a5b00; }
    .ao-deadline-badge.is-expired { background:#fff0f0; color:#a32929; }
    @media(max-width:560px){.ao-date-grid{grid-template-columns:1fr}}
</style>

<section class="ao-hero rounded-lg p-6 shadow-lg">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.2em] text-amber-300">Intelligence commerciale BTP</p>
            <h2 class="mt-2 text-3xl font-black">Veille des marches publics</h2>
            <p class="mt-2 max-w-3xl text-sm text-blue-100">Chaque AO n apparait qu une seule fois, meme s il est retrouve lors de plusieurs synchronisations.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php if ($canManageDiffusion): ?>
                <a href="veille_ao_diffusion.php" class="rounded-lg bg-white px-4 py-2 text-sm font-bold text-[#1d2b57]">Diffusion et destinataires</a><?php endif; ?>
            <?php if ($canManageAlerts): ?><a href="veille_ao_preferences.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-bold text-white"><i class="fa-solid fa-sliders mr-2"></i>Mes alertes</a><?php endif; ?>
            <?php if ($canAdmin): ?><a href="veille_ao_sync.php" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-bold text-white"><i class="fa-solid fa-clock-rotate-left mr-2"></i>Synchronisations</a><?php endif; ?>
            <?php if ($canExport): ?>
                <a href="export_veille_ao.php?format=excel&amp;<?= ao_h($exportQuery) ?>" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-bold text-white"><i class="fa-solid fa-file-excel mr-2"></i>Excel</a>
                <a href="export_veille_ao.php?format=pdf&amp;<?= ao_h($exportQuery) ?>" target="_blank" class="rounded-lg border border-white/20 px-4 py-2 text-sm font-bold text-white"><i class="fa-solid fa-file-pdf mr-2"></i>PDF</a>
            <?php endif; ?>
            <?php if ($canAdmin): ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>">
                    <input type="hidden" name="action" value="sync">
                    <button class="rounded-lg bg-[#fabd02] px-4 py-2 text-sm font-black text-[#1d2b57]"><i class="fa-solid fa-rotate mr-2"></i>Actualiser le catalogue</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<nav class="ao-scope-tabs" aria-label="Périmètre des échéances">
    <?php foreach ([
        'active' => ['AO encore ouverts', 'fa-circle-check'],
        'expired' => ['Archives expirées', 'fa-box-archive'],
        'undated' => ['Date inconnue', 'fa-calendar-question'],
        'all' => ['Tous les avis', 'fa-layer-group'],
    ] as $scope => [$label, $icon]): ?>
        <?php $scopeQuery = $_GET; $scopeQuery['deadline_scope'] = $scope; ?>
        <a class="ao-scope-tab <?= $filters['deadline_scope'] === $scope ? 'is-active' : '' ?>" href="?<?= ao_h(http_build_query($scopeQuery)) ?>">
            <i class="fa-solid <?= ao_h($icon) ?>"></i><?= ao_h($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<?php if ($message !== ''): ?>
    <div class="mt-4 rounded-lg border p-4 text-sm font-bold <?= $messageType === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700' ?>"><?= ao_h($message) ?></div>
<?php endif; ?>

<section class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ([
        [$filters['deadline_scope'] === 'active' ? 'AO encore ouverts' : 'Avis dans cette vue', $view['stats']['total'] ?? 0, 'fa-binoculars'],
        ['Pertinents', $view['stats']['pertinents'] ?? 0, 'fa-bullseye'],
        ['Echeances proches', $view['stats']['proches'] ?? 0, 'fa-hourglass-half'],
        ['En attente decision DG', $view['stats']['attente_dg'] ?? 0, 'fa-user-tie'],
    ] as $stat): ?>
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <i class="fa-solid <?= $stat[2] ?> text-[#1d2b57]"></i>
            <div class="mt-3 text-2xl font-black text-slate-950"><?= (int) $stat[1] ?></div>
            <div class="text-xs font-bold uppercase tracking-wide text-slate-500"><?= ao_h($stat[0]) ?></div>
        </div>
    <?php endforeach; ?>
</section>

<form method="get" class="mt-5 grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-6">
    <input type="hidden" name="deadline_scope" value="<?= ao_h($filters['deadline_scope']) ?>">
    <input name="q" value="<?= ao_h($filters['q']) ?>" placeholder="Reference, objet, autorite..." class="rounded-lg border border-slate-300 px-3 py-2 text-sm md:col-span-2">
    <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Tous les statuts</option>
        <?php foreach (['nouveau' => 'Nouveau', 'a_analyser' => 'A analyser', 'interessant' => 'Interessant', 'non_interessant' => 'Pas interessant', 'soumission' => 'Soumission', 'converti_crm' => 'CRM'] as $value => $label): ?>
            <option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach; ?>
    </select>
    <select name="min_score" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
        <option value="">Tous les scores</option>
        <option value="60" <?= $filters['min_score'] === '60' ? 'selected' : '' ?>>60% et plus</option>
        <option value="80" <?= $filters['min_score'] === '80' ? 'selected' : '' ?>>80% et plus</option>
    </select>
    <label class="flex items-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-xs font-bold text-slate-600">
        <input type="checkbox" name="deadline_soon" value="1" <?= $filters['deadline_soon'] ? 'checked' : '' ?>> Échéance sous 14 jours
    </label>
    <button class="rounded-lg bg-[#1d2b57] px-4 py-2 text-sm font-bold text-white"><i class="fa-solid fa-search mr-2"></i>Filtrer</button>
</form>

<section class="mt-5 space-y-3">
    <?php if (!$view['items']): ?>
        <div class="rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">Aucun appel d’offres ne correspond aux filtres. Consultez tous les avis ou demandez à un administrateur d’actualiser le catalogue central.</div>
    <?php endif; ?>
    <?php foreach ($view['items'] as $item): ?>
        <?php
        $score = (int) $item['score_pertinence'];
        $deadlineTimestamp = !empty($item['date_limite']) ? strtotime((string) $item['date_limite']) : false;
        $todayTimestamp = strtotime(date('Y-m-d'));
        $daysRemaining = $deadlineTimestamp !== false ? (int) floor(($deadlineTimestamp - $todayTimestamp) / 86400) : null;
        $isActionable = $daysRemaining !== null && $daysRemaining >= 0;
        ?>
        <article class="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row">
                <div class="ao-score <?= $score >= 80 ? 'high' : ($score >= 60 ? 'medium' : '') ?>"><?= $score ?>%</div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                        <span><i class="fa-solid fa-hashtag mr-1"></i>AO <?= ao_h(($item['numero_ao'] ?? '') ?: $item['reference'] ?: 'Non communique') ?></span><span>&middot;</span>
                        <span><?= ao_h($item['type_marche'] ?: 'Marche public') ?></span>
                        <span class="rounded-full bg-slate-100 px-2 py-1"><?= ao_h($item['statut_interne']) ?></span>
                        <?php if (($item['dg_decision'] ?? 'pending') !== 'pending'): ?>
                            <span class="rounded-full px-2 py-1 <?= $item['dg_decision'] === 'interested' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' ?>">DG: <?= $item['dg_decision'] === 'interested' ? 'interesse' : 'pas interesse' ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="mt-2 text-lg font-black leading-6 text-slate-950"><?= ao_h($item['titre']) ?></h3>
                    <p class="mt-2 text-sm text-slate-600"><?= ao_h($item['autorite_contractante'] ?: 'Autorite non renseignee') ?></p>
                    <div class="ao-date-grid">
                        <div class="ao-date-box">
                            <i class="fa-regular fa-calendar-plus"></i>
                            <span><small>Date de publication</small><strong><?= ao_h(ao_format_date($item['date_publication'])) ?></strong></span>
                        </div>
                        <div class="ao-date-box">
                            <i class="fa-solid fa-flag-checkered"></i>
                            <span><small>Date limite de dépôt</small><strong><?= ao_h(ao_format_date($item['date_limite'])) ?></strong></span>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <?php if ($daysRemaining !== null): ?>
                            <span class="ao-deadline-badge <?= $daysRemaining < 0 ? 'is-expired' : ($daysRemaining <= 14 ? 'is-urgent' : '') ?>">
                                <i class="fa-solid fa-hourglass-half"></i>
                                <?= $daysRemaining < 0 ? 'Expiré depuis ' . abs($daysRemaining) . ' jour(s)' : ($daysRemaining === 0 ? 'Expire aujourd’hui' : $daysRemaining . ' jour(s) restant(s)') ?>
                            </span>
                        <?php endif; ?>
                        <span class="text-xs font-semibold text-slate-500"><i class="fa-solid fa-database mr-1"></i><?= ao_h($item['source']) ?></span>
                    </div>
                </div>
                <div class="flex flex-wrap items-start gap-2 lg:w-64 lg:justify-end">
                    <a href="veille_ao_detail.php?id=<?= (int) $item['id'] ?>" class="rounded-lg bg-[#1d2b57] px-3 py-2 text-xs font-bold text-white">Ouvrir</a>
                    <?php if ($isActionable && $canDgDecision): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>"><input type="hidden" name="action" value="dg_decision"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="decision" value="interested">
                            <button class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">Interessant</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Marquer cet appel d offres comme non interessant et arreter les alertes ?');">
                            <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>"><input type="hidden" name="action" value="dg_decision"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="decision" value="not_interested">
                            <button class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-bold text-red-700">Pas interessant</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isActionable && !$canDgDecision && $canMarkInteresting): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>"><input type="hidden" name="action" value="mark_interesting"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800">Marquer interessant</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isActionable && !$canDgDecision && $canMarkNotInteresting): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>"><input type="hidden" name="action" value="mark_not_interesting"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">Marquer pas interessant</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($isActionable && $canCreateCrm && empty($item['crm_opportunity_id']) && ($item['dg_decision'] ?? 'pending') === 'interested'): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= ao_h($_SESSION['ao_csrf']) ?>"><input type="hidden" name="action" value="crm"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <button class="rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-800">Creer CRM</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/module_layout.php';

