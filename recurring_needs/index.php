<?php

/**
 * Module FINEA — Besoins récurrents
 * Dashboard + création + suivi des FEB automatiques.
 */
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/controllers/RecurringNeedController.php';
require_once __DIR__ . '/_recurring_needs_common.php';

$pdo = \Database::getConnection();
$controller = new RecurringNeedController($pdo);
$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash = isset($_POST['action']) ? $controller->action() : $controller->store();
}

$view = $controller->index();
$stats = $view['stats'] ?? [];
$items = $view['items'] ?? [];
$generations = $view['generations'] ?? [];
$filterOptions = $view['filter_options'] ?? ['requesters'=>[],'beneficiaries'=>[],'assignments'=>[]];
$exposure=$view['exposure']??[];
$computed = rn_build_stats($items);
$canValidate = !empty($_SESSION['permissions']['valider_besoin_recurrent']) || !empty($_SESSION['permissions']['acces_admin']);
$currentStatus = trim((string)($_GET['status'] ?? ''));
$archiveMode=!empty($_GET['archives']);
$activeTab = $currentStatus === 'pending_validation' ? 'pending' : ($currentStatus === 'active' ? 'active' : 'dashboard');
$nextItems = array_values(array_filter($items, fn($i) => in_array(($i['status'] ?? ''), ['active', 'pending_validation'], true)));
usort($nextItems, fn($a, $b) => strcmp((string)($a['next_due_date'] ?? ''), (string)($b['next_due_date'] ?? '')));
$nextItems = array_slice($nextItems, 0, 5);

$alerts = [];
if ($computed['pending'] > 0) {
    $alerts[] = ['title' => 'Validation requise', 'message' => $computed['pending'] . ' besoin(s) récurrent(s) attendent une décision.', 'icon' => 'fa-user-check'];
}
if ($computed['upcoming'] > 0) {
    $alerts[] = ['title' => 'Échéances proches', 'message' => $computed['upcoming'] . ' génération(s) prévues dans les 14 prochains jours.', 'icon' => 'fa-calendar-day'];
}
if ($computed['suspended'] > 0) {
    $alerts[] = ['title' => 'Récurrences suspendues', 'message' => $computed['suspended'] . ' modèle(s) sont suspendus.', 'icon' => 'fa-pause'];
}
if (!$alerts) {
    $alerts[] = ['title' => 'Situation maîtrisée', 'message' => 'Aucune alerte critique sur les besoins récurrents.', 'icon' => 'fa-shield-check'];
}

$kpis = $archiveMode ? [
    ['label' => 'Récurrences archivées', 'value' => rn_number($computed['total']), 'hint' => 'annulées ou arrivées à terme', 'icon' => 'fa-box-archive', 'href' => 'index.php?archives=1'],
] : [
    ['label' => 'Besoins configurés', 'value' => rn_number($computed['total']), 'hint' => 'modèles enregistrés', 'icon' => 'fa-layer-group', 'href' => 'index.php'],
    ['label' => 'Actifs', 'value' => rn_number($computed['active']), 'hint' => 'génération automatique prête', 'icon' => 'fa-arrows-rotate', 'href' => 'index.php?status=active'],
    ['label' => 'À valider', 'value' => rn_number($computed['pending']), 'hint' => 'en attente de validation', 'icon' => 'fa-user-check', 'href' => 'index.php?status=pending_validation'],
];
if(!$archiveMode){$exposureCards=[
    ['global','Exposition globale','fa-chart-pie','montant d’une occurrence de chaque besoin actif'],
    ['today','Aujourd’hui','fa-calendar-day','occurrences prévues aujourd’hui'],
    ['week','Semaine en cours','fa-calendar-week','occurrences de la semaine'],
    ['month','Mois en cours','fa-calendar','occurrences du mois'],
    ['quarter','Trimestre en cours','fa-chart-column','occurrences du trimestre'],
    ['year','Année en cours','fa-calendar-check','occurrences de l’année'],
];foreach($exposureCards as[$key,$label,$icon,$hint]){$data=$exposure[$key]??['amount'=>0,'count'=>0,'from'=>'','to'=>''];$query=$key==='global'?'status=active':http_build_query(['status'=>'active','due_from'=>$data['from'],'due_to'=>$data['to']]);$kpis[]=['label'=>$label,'value'=>rn_money($data['amount']??0),'hint'=>rn_number($data['count']??0).' '.$hint,'icon'=>$icon,'href'=>'index.php?'.$query];}}

$sideLinks = [
    ['href' => 'add_form.php', 'icon' => 'fa-plus', 'label' => 'Créer un besoin', 'hint' => 'Programmer une FEB automatique'],
    ['href' => 'index.php?status=pending_validation', 'icon' => 'fa-user-check', 'label' => 'Validations', 'hint' => $computed['pending'] . ' élément(s) à contrôler'],
    ['href' => 'index.php?status=active', 'icon' => 'fa-arrows-rotate', 'label' => 'Récurrences actives', 'hint' => 'Suivre les prochaines générations'],
    ['href' => 'index.php?archives=1', 'icon' => 'fa-box-archive', 'label' => 'Archives', 'hint' => 'Récurrences annulées ou terminées'],
    ['href' => '../employe/dashboard_employe.php', 'icon' => 'fa-user-tie', 'label' => 'Espace employé', 'hint' => 'Retour aux demandes internes'],
    ['href' => '../fiches_decaissement_list.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'Fiches décaissement', 'hint' => 'Consulter les fiches générées'],
    ['href' => 'cron_generate.php', 'icon' => 'fa-gears', 'label' => 'Tester la génération', 'hint' => 'Lancer le contrôle manuel'],
];

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<style>
.rn-workspace{background:var(--finea-surface,#f8fafc);color:var(--finea-text,#172033)}
.rn-workspace .rn-hero,.rn-workspace .rn-quick{background:linear-gradient(120deg,var(--finea-primary,#1d2b57),#101827)!important;color:white}
.rn-workspace .rn-primary{background:var(--finea-accent,#fabd02)!important;color:#111827}
.finea-dark-mode .rn-workspace{background:#0f172a;color:#e2e8f0;color-scheme:dark}
.finea-dark-mode .rn-workspace :is([class~="bg-white"],[class~="bg-white/95"],[class~="bg-slate-50"],[class~="bg-slate-100"]){background:#1e293b!important}
.finea-dark-mode .rn-workspace :is([class~="text-slate-950"],[class~="text-slate-900"],[class~="text-slate-800"],[class~="text-slate-700"],[class~="text-slate-600"],[class~="text-slate-500"]){color:#cbd5e1!important}
.finea-dark-mode .rn-workspace :is([class~="border-white/80"],[class~="border-slate-100"],[class~="border-slate-200"],[class~="border-slate-300"]){border-color:#475569!important}
.finea-dark-mode .rn-workspace :is(input:not([type=checkbox]):not([type=hidden]),select,textarea){background:#172033;color:#e2e8f0;border-color:#475569}
.finea-dark-mode .rn-workspace :is([class~="bg-indigo-50"],[class~="bg-indigo-100"],[class~="bg-blue-50"]){background:#243451!important;color:#c7d2fe!important}
.finea-dark-mode .rn-workspace :is([class~="bg-orange-50"],[class~="bg-amber-50"]){background:#422d18!important;color:#fed7aa!important}
.finea-dark-mode .rn-workspace :is([class~="bg-emerald-50"],[class~="bg-rose-50"]){background:#263448!important}
.finea-dark-mode .rn-workspace :is([class~="text-emerald-700"],[class~="text-emerald-800"]){color:#86efac!important}
.finea-dark-mode .rn-workspace :is([class~="text-rose-700"],[class~="text-rose-800"],[class~="text-rose-600"]){color:#fda4af!important}
.finea-dark-mode .rn-workspace :is([class~="text-orange-700"],[class~="text-orange-600"]){color:#fdba74!important}
.finea-dark-mode .rn-workspace :is([class~="hover:bg-slate-50/70"],[class~="hover:bg-indigo-50"]):hover{background:#334155!important}
.finea-dark-mode .rn-workspace [class~="divide-slate-100"]>*+*{border-color:#334155!important}
/* Quick links must retain contrast regardless of the global anchor theme. */
.rn-workspace .rn-quick a{color:#f8fafc!important;background:rgba(255,255,255,.08)!important;border-color:rgba(255,255,255,.22)!important}
.rn-workspace .rn-quick a span{color:inherit!important}
.rn-workspace .rn-quick a span.text-sm{color:#cbd5e1!important}
.rn-workspace .rn-quick a i{color:#fde68a!important}
.rn-workspace .rn-quick a:hover,.rn-workspace .rn-quick a:focus-visible{background:rgba(255,255,255,.18)!important;color:#fff!important}
.rn-workspace .rn-quick a:focus-visible{outline:2px solid #fde68a;outline-offset:2px}
</style>
<div class="rn-workspace min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8"><div class="mb-4 flex justify-end"><button type="button" id="rnThemeToggle" class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700">Mode sombre</button></div>
        <?php if ($flash): ?>
            <div class="mb-5 rounded-2xl border <?= !empty($flash['ok']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-5 py-4 font-semibold shadow-sm">
                <i class="fas <?= !empty($flash['ok']) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mr-2"></i><?= rn_h($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="rn-hero relative overflow-hidden rounded-[34px] border border-slate-900/10 bg-gradient-to-r from-slate-950 via-[#1d2b57] to-slate-900 px-7 py-9 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)] md:px-9 md:py-11">
            <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[#fabd02]/25 blur-2xl"></div>
            <div class="absolute -bottom-20 left-20 h-48 w-48 rounded-full bg-white/10 blur-3xl"></div>
            <div class="relative grid gap-8 lg:grid-cols-[1.3fr_0.7fr] lg:items-end">
                <div>
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200"><?= rn_h($layoutCompanyBranding['company_name'] ?? 'FINEA') ?> · Automatisation FEB</p>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-bell mr-2 text-[#fabd02]"></i><?= rn_number(count($alerts)) ?> point(s) à surveiller
                        </span>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-calendar-check mr-2 text-[#fabd02]"></i>Création automatique J-7
                        </span>
                    </div>
                    <h1 class="max-w-4xl text-3xl font-semibold tracking-tight md:text-5xl">Pilotage des besoins récurrents</h1>
                    <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-200 md:text-base">
                        Centralisez les besoins périodiques, validez les modèles, déclenchez les rappels et préparez automatiquement les FEB avant l’échéance.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <a href="add_form.php" class="rn-primary inline-flex items-center justify-center rounded-2xl bg-[#fabd02] px-5 py-3 text-sm font-bold text-slate-950 shadow-lg shadow-amber-500/20 transition hover:-translate-y-0.5 hover:bg-amber-300">
                        <i class="fas fa-plus mr-2"></i>Nouveau besoin
                    </a>
                    <a href="index.php?status=pending_validation" class="inline-flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-bold text-slate-950 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-amber-50">
                        <i class="fas fa-user-check mr-2 text-orange-600"></i>Valider les modèles
                    </a>
                    <a href="index.php?archives=1" class="inline-flex items-center justify-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-box-archive mr-2"></i>Archives</a>
                    <a href="../selection_portail.php" class="inline-flex items-center justify-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-table-cells-large mr-2"></i>Retour portail
                    </a>
                </div>
            </div>
        </section>

        <?= rn_dashboard_tabs($activeTab) ?>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($kpis as $kpi): ?>
                <a href="<?= rn_h($kpi['href']) ?>" class="group block rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-[0_20px_55px_rgba(15,23,42,0.10)]">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500"><?= rn_h($kpi['label']) ?></p>
                            <div class="mt-3 text-2xl font-black text-slate-950 md:text-3xl"><?= rn_h($kpi['value']) ?></div>
                            <p class="mt-2 text-sm text-slate-600"><?= rn_h($kpi['hint']) ?></p>
                        </div>
                        <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 text-orange-700 transition group-hover:bg-orange-600 group-hover:text-white"><i class="fas <?= rn_h($kpi['icon']) ?>"></i></span>
                    </div>
                    <div class="mt-4 text-sm font-semibold text-orange-700 opacity-0 transition group-hover:opacity-100">Ouvrir <i class="fas fa-arrow-right ml-1"></i></div>
                </a>
            <?php endforeach; ?>
        </section>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.45fr_0.55fr]">
            <main class="space-y-6">
                <section class="rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-orange-600">Portefeuille</p>
                            <h2 class="mt-2 text-2xl font-bold text-slate-950"><?= $archiveMode?'Archives des besoins récurrents':'Besoins récurrents enregistrés' ?></h2>
                        </div>
                        <form method="get" id="recurringFiltersForm" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div><div class="mb-1 flex justify-between gap-2"><span class="text-xs font-bold text-slate-600">Recherche globale</span><select name="q_mode" class="rounded-lg border-0 bg-slate-100 px-2 py-1 text-[11px] font-bold"><option value="include" <?=($_GET['q_mode']??'include')==='include'?'selected':''?>>Inclure</option><option value="exclude" <?=($_GET['q_mode']??'')==='exclude'?'selected':''?>>Exclure</option></select></div><input name="q" value="<?= rn_h($_GET['q'] ?? '') ?>" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#1d2b57]" placeholder="Nom, référence, chantier…"></div>
                            <?php foreach ([['requester','Demandeurs','requesters'],['beneficiary','Bénéficiaires','beneficiaries'],['assignment','Affectations / chantiers','assignments']] as [$filterKey,$filterLabel,$optionKey]): $selectedValues=array_values(array_filter(array_map('strval',(array)($_GET[$filterKey]??[])))); ?>
                                <div data-multi-filter="<?= rn_h($filterKey) ?>" data-options='<?= rn_h(json_encode($filterOptions[$optionKey]??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>' class="relative min-w-0">
                                    <div class="mb-1 flex items-center justify-between gap-2"><span class="text-xs font-bold text-slate-600"><?= rn_h($filterLabel) ?></span><select name="<?= rn_h($filterKey) ?>_mode" class="rounded-lg border-0 bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-700"><option value="include" <?= ($_GET[$filterKey.'_mode']??'include')==='include'?'selected':'' ?>>Inclure</option><option value="exclude" <?= ($_GET[$filterKey.'_mode']??'')==='exclude'?'selected':'' ?>>Exclure</option></select></div>
                                    <div data-multi-control class="flex min-h-[48px] cursor-text flex-wrap items-center gap-1.5 rounded-2xl border border-slate-200 bg-white px-3 py-2 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-100">
                                        <span data-multi-badges class="contents"></span><input data-multi-search autocomplete="off" class="min-w-[120px] flex-1 border-0 bg-transparent px-1 py-1 text-sm outline-none" placeholder="Saisir pour rechercher…">
                                    </div>
                                    <div data-multi-inputs><?php foreach($selectedValues as$value): ?><input type="hidden" name="<?= rn_h($filterKey) ?>[]" value="<?= rn_h($value) ?>"><?php endforeach; ?></div>
                                    <div data-multi-results class="absolute z-50 mt-1 hidden max-h-64 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"></div>
                                </div>
                            <?php endforeach; ?>
                            <select name="status" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#1d2b57]">
                                <option value="">Tous statuts</option>
                                <?php foreach ($archiveMode?['cancelled'=>'Annulé','expired'=>'Arrivé à terme']:['pending_validation' => 'À valider', 'active' => 'Actif', 'suspended' => 'Suspendu', 'draft' => 'Brouillon'] as $s => $label): ?>
                                    <option value="<?= rn_h($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>><?= rn_h($label) ?></option>
                                    <option value="!<?= rn_h($s) ?>" <?= $currentStatus === '!'.$s ? 'selected' : '' ?>>Tous sauf : <?= rn_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="frequency_type" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#1d2b57]">
                                <option value="">Toutes fréquences</option>
                                <?php foreach (['daily'=>'Quotidienne','weekly'=>'Hebdomadaire','monthly'=>'Mensuelle','quarterly'=>'Trimestrielle','semesterly'=>'Semestrielle','yearly'=>'Annuelle','custom'=>'Personnalisée'] as $value=>$label): ?>
                                    <option value="<?= rn_h($value) ?>" <?= (string)($_GET['frequency_type'] ?? '') === $value ? 'selected' : '' ?>><?= rn_h($label) ?></option>
                                    <option value="!<?= rn_h($value) ?>" <?= (string)($_GET['frequency_type'] ?? '') === '!'.$value ? 'selected' : '' ?>>Toutes sauf : <?= rn_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="priority" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[#1d2b57]">
                                <option value="">Toutes priorités</option>
                                <?php foreach (['basse'=>'Basse','normale'=>'Normale','haute'=>'Haute','critique'=>'Critique'] as $value=>$label): ?>
                                    <option value="<?= rn_h($value) ?>" <?= (string)($_GET['priority'] ?? '') === $value ? 'selected' : '' ?>><?= rn_h($label) ?></option>
                                    <option value="!<?= rn_h($value) ?>" <?= (string)($_GET['priority'] ?? '') === '!'.$value ? 'selected' : '' ?>>Toutes sauf : <?= rn_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="text-xs font-semibold text-slate-500">Échéance du<input type="date" name="due_from" value="<?= rn_h($_GET['due_from'] ?? '') ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"></label>
                            <label class="text-xs font-semibold text-slate-500">Échéance au<input type="date" name="due_to" value="<?= rn_h($_GET['due_to'] ?? '') ?>" class="mt-1 w-full rounded-2xl border border-slate-200 px-4 py-2 text-sm"></label>
                            <button class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white"><i class="fas fa-filter mr-2"></i>Filtrer</button>
                            <?php if($archiveMode): ?><input type="hidden" name="archives" value="1"><?php endif; ?>
                            <a href="<?= $archiveMode?'index.php?archives=1':'index.php' ?>" class="rounded-2xl border border-slate-200 px-5 py-3 text-center text-sm font-semibold text-slate-700">Réinitialiser</a>
                        </form>
                    </div>
                    <?php $recurringExportQuery=http_build_query(array_filter($_GET,static fn($value)=>$value!==''&&$value!==null)); ?>
                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        <a data-filtered-export="export_recap_pdf.php" target="_blank" href="export_recap_pdf.php<?= $recurringExportQuery!==''?'?'.rn_h($recurringExportQuery):'' ?>" class="rounded-xl bg-rose-50 px-4 py-2 text-sm font-bold text-rose-700 ring-1 ring-rose-100"><i class="fas fa-file-pdf mr-2"></i>Planning PDF</a>
                        <a data-filtered-export="export_recap_excel.php" href="export_recap_excel.php<?= $recurringExportQuery!==''?'?'.rn_h($recurringExportQuery):'' ?>" class="rounded-xl bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-700 ring-1 ring-emerald-100"><i class="fas fa-file-excel mr-2"></i>Planning Excel</a>
                    </div>
                    <form method="post" id="recurringBulkForm" class="mt-6 hidden flex-wrap items-center gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                        <div class="mr-auto"><strong data-bulk-count class="text-indigo-950">0 sélection</strong><p class="text-xs text-indigo-700">Traitez les modèles cochés en une seule opération.</p></div>
                        <?php if ($archiveMode): ?><button name="action" value="restore" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white"><i class="fas fa-rotate-left mr-2"></i>Restaurer</button><?php else: ?>
                            <?php if ($canValidate): ?><button name="action" value="approve" class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-bold text-white"><i class="fas fa-play mr-2"></i>Activer</button><?php endif; ?>
                            <button name="action" value="suspend" class="rounded-xl bg-slate-700 px-4 py-2 text-sm font-bold text-white"><i class="fas fa-pause mr-2"></i>Suspendre</button>
                            <button name="action" value="cancel" data-bulk-cancel class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-bold text-white"><i class="fas fa-ban mr-2"></i>Annuler</button>
                        <?php endif; ?>
                        <span data-bulk-inputs></span>
                    </form>
                    <div class="mt-6 overflow-hidden rounded-3xl border border-slate-100">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-100 text-sm">
                                <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="w-14 px-4 py-4"><label class="flex cursor-pointer items-center justify-center" title="Tout sélectionner"><input type="checkbox" data-recurring-select-all class="h-5 w-5 rounded-md border-2 border-slate-300 text-indigo-600 focus:ring-indigo-500"></label></th>
                                        <th class="px-5 py-4">Besoin</th>
                                        <th class="px-5 py-4">Fréquence</th>
                                        <th class="px-5 py-4">Échéance</th>
                                        <th class="px-5 py-4">Montant</th>
                                        <th class="px-5 py-4">Statut</th>
                                        <th class="px-5 py-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 bg-white">
                                    <?php if (!$items): ?>
                                        <tr><td colspan="7" class="px-6 py-12 text-center"><i class="fas fa-calendar-plus text-3xl text-slate-300"></i><p class="mt-3 font-bold text-slate-800">Aucun besoin récurrent enregistré</p><p class="mt-1 text-sm text-slate-500">Créez-en un ici ou choisissez une fréquence périodique lors de la création d’une FEB.</p></td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($items as $item): ?>
                                        <?php $days = rn_days_until($item['next_due_date'] ?? null); ?>
                                        <tr class="hover:bg-slate-50/70">
                                            <td class="px-4 py-4"><label class="flex cursor-pointer items-center justify-center"><input type="checkbox" value="<?= (int)$item['id'] ?>" data-recurring-select class="h-5 w-5 rounded-md border-2 border-slate-300 text-indigo-600 focus:ring-indigo-500"></label></td>
                                            <td class="px-5 py-4">
                                                <div class="font-bold text-slate-950"><?= rn_h($item['title'] ?? '') ?></div>
                                                <div class="mt-1 text-xs text-slate-500"><?= rn_h($item['reference'] ?? '') ?> · <?= rn_h($item['requester_name'] ?? '') ?></div>
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    <span class="rounded-full border <?= rn_priority_badge_class($item['priority'] ?? '') ?> px-2.5 py-1 text-xs font-semibold"><?= rn_h(rn_priority_label($item['priority'] ?? '')) ?></span>
                                                    <span class="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-semibold text-slate-600"><?= rn_h($item['fournisseur_name'] ?: 'Sans fournisseur') ?></span>
                                                </div>
                                            </td>
                                            <td class="px-5 py-4 text-slate-700">
                                                <strong><?= rn_h(rn_frequency_label($item['frequency_type'] ?? '')) ?></strong>
                                                <div class="text-xs text-slate-500">Intervalle : <?= rn_number($item['frequency_interval'] ?? 1) ?></div>
                                            </td>
                                            <td class="px-5 py-4">
                                                <div class="font-semibold text-slate-950"><?= rn_h(rn_short_date($item['next_due_date'] ?? null)) ?></div>
                                                <?php if($archiveMode): ?><div class="text-xs font-semibold text-slate-500">Récurrence archivée · aucune génération attendue</div><?php else: ?><div class="text-xs <?= $days < 0 ? 'text-rose-600' : ($days <= 7 ? 'text-orange-600' : 'text-slate-500') ?>"><?= $days < 0 ? 'En retard de ' . abs($days) . ' j' : 'Dans ' . $days . ' j' ?> · génération J-<?= rn_number($item['generate_days_before'] ?? 7) ?></div><?php endif; ?>
                                            </td>
                                            <td class="px-5 py-4 font-bold text-slate-950"><?= rn_money($item['amount_estimated'] ?? 0) ?></td>
                                            <td class="px-5 py-4"><span class="rounded-full border <?= rn_status_badge_class($item['status'] ?? '') ?> px-3 py-1 text-xs font-bold"><?= rn_h(rn_status_label($item['status'] ?? '')) ?></span></td>
                                            <td class="px-5 py-4 text-right">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                        <a href="add_form.php?id=<?= (int)$item['id'] ?>" class="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-[#1d2b57] ring-1 ring-blue-100 hover:bg-blue-100">
                                                            <i class="fas fa-pen mr-1"></i>Modifier
                                                        </a>

                                                        <?php if ($canValidate && ($item['status'] ?? '') === 'pending_validation'): ?>
                                                            <form method="post" class="inline"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="approve" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Activer</button></form>
                                                        <?php endif; ?>

                                                        <?php if (in_array(($item['status'] ?? ''), ['active', 'pending_validation'], true)): ?>
                                                            <form method="post" class="inline"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="suspend" class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-700">Suspendre</button></form>
                                                        <?php elseif (($item['status'] ?? '') === 'suspended'): ?>
                                                            <form method="post" class="inline"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="resume" class="rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 ring-1 ring-emerald-100">Réactiver</button></form>
                                                        <?php endif; ?>
                                                    <?php if (!in_array(($item['status'] ?? ''), ['cancelled', 'expired'], true)): ?>
                                                        <form method="post" class="inline" onsubmit="return confirm('Annuler définitivement cette récurrence ?');"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="cancel" class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 ring-1 ring-rose-100">Annuler</button></form>
                                                    <?php endif; ?>
                                                    <?php if($archiveMode&&in_array(($item['status']??''),['cancelled','expired'],true)): ?><form method="post" class="inline"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="restore" class="rounded-xl bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 ring-1 ring-indigo-100"><i class="fas fa-rotate-left mr-1"></i>Restaurer</button></form><?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section id="historique-generations" class="rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.25em] text-orange-600">Production FEB</p>
                        <h2 class="mt-2 text-2xl font-bold text-slate-950">Historique des générations</h2>
                        <p class="mt-2 text-sm text-slate-600">Chaque ligne correspond à une vraie demande FEB créée dans le circuit employé.</p>
                    </div>
                    <div class="mt-5 overflow-x-auto rounded-2xl border border-slate-100">
                        <table class="min-w-full divide-y divide-slate-100 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-bold uppercase text-slate-500"><tr><th class="px-4 py-3">Besoin</th><th class="px-4 py-3">Échéance</th><th class="px-4 py-3">Génération</th><th class="px-4 py-3">Résultat</th><th class="px-4 py-3 text-right">FEB</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (!$generations): ?><tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">Aucune FEB automatique générée pour le moment.</td></tr><?php endif; ?>
                                <?php foreach ($generations as $generation): ?>
                                    <tr>
                                        <td class="px-4 py-3"><strong class="text-slate-900"><?= rn_h($generation['title'] ?? '') ?></strong><div class="text-xs text-slate-500"><?= rn_h($generation['reference'] ?? '') ?></div></td>
                                        <td class="px-4 py-3"><?= rn_h(rn_short_date($generation['scheduled_due_date'] ?? null)) ?></td>
                                        <td class="px-4 py-3"><?= rn_h(rn_short_date($generation['generation_date'] ?? null)) ?></td>
                                        <td class="px-4 py-3"><span class="rounded-full px-2.5 py-1 text-xs font-bold <?= ($generation['status'] ?? '') === 'generated' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' ?>"><?= rn_h(($generation['status'] ?? '') === 'generated' ? 'Créée' : 'Échec') ?></span></td>
                                        <td class="px-4 py-3 text-right"><?php if (!empty($generation['generated_feb_id'])): ?><a href="../employe/details_demande_employe.php?id=<?= (int)$generation['generated_feb_id'] ?>" class="inline-flex rounded-xl bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100">Ouvrir #<?= (int)$generation['generated_feb_id'] ?></a><?php else: ?><span class="text-xs text-slate-400">Non créée</span><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </main>

            <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
                <section class="rn-quick rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
                    <div class="mb-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-200">Accès rapides</p>
                        <h2 class="mt-2 text-2xl font-semibold">Besoins récurrents</h2>
                        <p class="mt-2 text-sm text-slate-300">Les actions fréquentes du module.</p>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($sideLinks as $index => $link): ?>
                            <a href="<?= rn_h($link['href']) ?>" class="relative block rounded-2xl border <?= $index < 2 ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10' ?> px-4 py-4 transition">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-[#fabd02]"><i class="fas <?= rn_h($link['icon']) ?>"></i></span>
                                    <span><span class="block font-semibold"><?= rn_h($link['label']) ?></span><span class="text-sm text-slate-400"><?= rn_h($link['hint']) ?></span></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-orange-600">Alertes</p>
                    <h2 class="mt-2 text-xl font-bold text-slate-950">Surveillance</h2>
                    <div class="mt-5 space-y-3">
                        <?php foreach ($alerts as $alert): ?>
                            <div class="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                                <div class="flex gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-orange-600"><i class="fas <?= rn_h($alert['icon']) ?>"></i></span>
                                    <div><strong class="text-slate-950"><?= rn_h($alert['title']) ?></strong>
                                        <p class="mt-1 text-sm text-slate-600"><?= rn_h($alert['message']) ?></p>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <p class="text-xs font-semibold uppercase tracking-[0.25em] text-orange-600">Calendrier</p>
                    <h2 class="mt-2 text-xl font-bold text-slate-950">Prochaines générations</h2>
                    <div class="mt-5 space-y-3">
                        <?php foreach ($nextItems as $item): ?>
                            <?php $days = rn_days_until($item['next_due_date'] ?? null); ?>
                            <div class="flex items-start gap-3 rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                                <div class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-2xl bg-slate-950 text-white"><span class="text-xs">J</span><strong><?= $days >= 0 ? '-' . $days : '+' . abs($days) ?></strong></div>
                                <div>
                                    <strong class="text-sm text-slate-950"><?= rn_h($item['title'] ?? '') ?></strong>
                                    <p class="mt-1 text-xs text-slate-500"><?= rn_h(rn_short_date($item['next_due_date'] ?? null)) ?> · <?= rn_h(rn_frequency_label($item['frequency_type'] ?? '')) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const themeButton=document.getElementById('rnThemeToggle');
    const applyTheme=dark=>{document.documentElement.classList.toggle('finea-dark-mode',dark);document.body.classList.toggle('finea-dark-mode',dark);themeButton.textContent=dark?'☀ Mode clair':'☾ Mode sombre';themeButton.setAttribute('aria-pressed',String(dark));};
    applyTheme(document.documentElement.classList.contains('finea-dark-mode'));
    themeButton.addEventListener('click',()=>{const dark=!document.documentElement.classList.contains('finea-dark-mode');applyTheme(dark);try{['finea.app.theme','finea.portal.theme'].forEach(k=>localStorage.setItem(k,dark?'dark':'light'));}catch(e){}});
    window.addEventListener('storage',e=>{if(['finea.app.theme','finea.portal.theme'].includes(e.key))applyTheme(e.newValue==='dark');});
    document.querySelectorAll('[data-multi-filter]').forEach(root => {
        const key=root.dataset.multiFilter, options=JSON.parse(root.dataset.options||'[]'), search=root.querySelector('[data-multi-search]'), results=root.querySelector('[data-multi-results]'), badges=root.querySelector('[data-multi-badges]'), inputs=root.querySelector('[data-multi-inputs]');
        let selected=Array.from(inputs.querySelectorAll('input')).map(input=>input.value);
        const esc=value=>String(value).replace(/[&<>'"]/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[char]));
        const sync=()=>{inputs.innerHTML=selected.map(value=>`<input type="hidden" name="${esc(key)}[]" value="${esc(value)}">`).join('');badges.innerHTML=selected.map((value,index)=>`<span class="inline-flex max-w-full items-center gap-1 rounded-lg bg-indigo-100 px-2 py-1 text-xs font-bold text-indigo-800"><span class="truncate">${esc(value)}</span><button type="button" data-remove-index="${index}" class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full hover:bg-indigo-200" aria-label="Retirer">×</button></span>`).join('');badges.querySelectorAll('[data-remove-index]').forEach(button=>button.addEventListener('click',()=>{selected.splice(Number(button.dataset.removeIndex),1);sync();show();}));};
        const show=()=>{const query=search.value.trim().toLocaleLowerCase('fr');const available=options.filter(value=>!selected.includes(value)&&String(value).toLocaleLowerCase('fr').includes(query)).slice(0,40);results.innerHTML=available.length?available.map(value=>`<button type="button" data-option="${esc(value)}" class="block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold text-slate-700 hover:bg-indigo-50">${esc(value)}</button>`).join(''):'<div class="px-3 py-3 text-sm text-slate-500">Aucun autre résultat.</div>';results.classList.remove('hidden');results.querySelectorAll('[data-option]').forEach(button=>button.addEventListener('click',()=>{selected.push(button.dataset.option);search.value='';sync();show();search.focus();}));};
        root.querySelector('[data-multi-control]')?.addEventListener('click',()=>search.focus());search.addEventListener('focus',show);search.addEventListener('input',show);search.addEventListener('keydown',event=>{if(event.key==='Escape'){results.classList.add('hidden');search.blur();}if(event.key==='Backspace'&&!search.value&&selected.length){selected.pop();sync();}});root.addEventListener('focusout',()=>setTimeout(()=>{if(!root.contains(document.activeElement))results.classList.add('hidden');},120));sync();
    });
    const filtersForm=document.getElementById('recurringFiltersForm');
    document.querySelectorAll('[data-filtered-export]').forEach(link=>link.addEventListener('click',()=>{if(!filtersForm)return;link.href=link.dataset.filteredExport+'?'+new URLSearchParams(new FormData(filtersForm)).toString();}));
    const all = document.querySelector('[data-recurring-select-all]');
    const boxes = Array.from(document.querySelectorAll('[data-recurring-select]'));
    const form = document.getElementById('recurringBulkForm');
    const count = form?.querySelector('[data-bulk-count]');
    const inputs = form?.querySelector('[data-bulk-inputs]');
    const sync = () => {
        const selected = boxes.filter(box => box.checked);
        form?.classList.toggle('hidden', selected.length === 0);
        form?.classList.toggle('flex', selected.length > 0);
        if (count) count.textContent = selected.length + ' sélection' + (selected.length > 1 ? 's' : '');
        if (inputs) inputs.innerHTML = selected.map(box => '<input type="hidden" name="selected_ids[]" value="' + Number(box.value) + '">').join('');
        if (all) {
            all.checked = boxes.length > 0 && selected.length === boxes.length;
            all.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
    };
    all?.addEventListener('change', () => { boxes.forEach(box => { box.checked = all.checked; }); sync(); });
    boxes.forEach(box => box.addEventListener('change', sync));
    form?.addEventListener('submit', event => {
        const submitter = event.submitter;
        if (submitter?.value === 'cancel' && !window.confirm('Annuler définitivement tous les besoins récurrents sélectionnés ?')) event.preventDefault();
    });
    sync();
});
</script>
