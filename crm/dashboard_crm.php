<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../controllers/CrmController.php';
require_once __DIR__ . '/_crm_ui.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/QuoteBillingAlertService.php';

$pageTitle = 'CRM - Direction commerciale';
$activeMenu = 'crm';
$todayLabel = date('d/m/Y');
$crmController = new CrmController();
$scheduleReady = $crmController->isScheduleReady();
$personnelId = (int)($_SESSION['emploi_du_temps_personnel_id'] ?? 0);
if ($personnelId <= 0) {
    try {
        $personnelId = $crmController->resolvePersonnelIdByEmail((string)($_SESSION['mail_pro'] ?? ''));
        if ($personnelId > 0) $_SESSION['emploi_du_temps_personnel_id'] = $personnelId;
    } catch (Throwable $e) {
    }
}
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$data = $crmController->buildDashboardData($currentUserId, $personnelId, $scheduleReady);
$crmKpis = is_array($data['crmKpis'] ?? null) ? $data['crmKpis'] : [];
$pipelineByStage = is_array($data['pipelineByStage'] ?? null) ? $data['pipelineByStage'] : [];
$prospectsBySource = is_array($data['prospectsBySource'] ?? null) ? $data['prospectsBySource'] : [];
$monthlyConversion = is_array($data['monthlyConversion'] ?? null) ? $data['monthlyConversion'] : [];
$overdueFollowups = is_array($data['overdueFollowups'] ?? null) ? $data['overdueFollowups'] : [];
$upcomingRdv = is_array($data['upcomingRdv'] ?? null) ? $data['upcomingRdv'] : [];
$todoTodayEvents = is_array($data['todoTodayEvents'] ?? null) ? $data['todoTodayEvents'] : [];
$todoTodayOpp = is_array($data['todoTodayOpp'] ?? null) ? $data['todoTodayOpp'] : [];
$todoWeekEvents = is_array($data['todoWeekEvents'] ?? null) ? $data['todoWeekEvents'] : [];
$todoWeekOpp = is_array($data['todoWeekOpp'] ?? null) ? $data['todoWeekOpp'] : [];
$todoInactiveProspects = is_array($data['todoInactiveProspects'] ?? null) ? $data['todoInactiveProspects'] : [];
$billingAlerts = (new QuoteBillingAlertService(Database::getConnection()))->pending();
function crm_xof($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' XOF';
}
function crm_pct($value): string
{
    return number_format((float)$value, 1, ',', ' ') . ' %';
}
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<?= crm_page_open() ?>
<?= crm_hero('Module CRM - ' . $todayLabel, 'Pilotage commercial BANAMUR', 'Une vue direction pour suivre les prospects, le pipeline, les rendez-vous, les relances et les prochaines actions commerciales.', [
    ['href' => 'prospect_form.php', 'label' => 'Nouveau prospect', 'icon' => 'fa-user-plus', 'variant' => 'light'],
    ['href' => 'opportunites.php', 'label' => 'Pipeline', 'icon' => 'fa-chart-line', 'variant' => 'primary'],
    ['href' => 'rdv.php', 'label' => 'Planifier RDV', 'icon' => 'fa-calendar-plus', 'variant' => 'ghost'],
]) ?>
<?= crm_module_tabs('dashboard') ?>
<?php if ($billingAlerts): ?><section class="mb-6 rounded-[26px] border border-amber-200 bg-amber-50 p-5 text-amber-950"><div class="flex flex-wrap items-center justify-between gap-3"><div><div class="text-xs font-bold uppercase tracking-[.18em]">Relances Facturation</div><h2 class="mt-1 text-xl font-extrabold"><?=count($billingAlerts)?> devis à transformer en facture</h2></div><a href="../facturation/factures_vente.php" class="rounded-xl bg-[#1d2b57] px-4 py-2 text-sm font-bold text-white">Ouvrir Facturation</a></div><div class="mt-4 flex flex-wrap gap-2"><?php foreach(array_slice($billingAlerts,0,8) as $alert): ?><span class="rounded-full border border-amber-200 bg-white px-3 py-2 text-xs font-semibold"><?=crm_h((string)($alert['numero_devis'] ?: $alert['reference_offre']))?> · <?=date('d/m/Y',strtotime((string)$alert['billing_date']))?></span><?php endforeach; ?></div></section><?php endif; ?>
<section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
    <?php $cards = [
    ['Prospects', number_format((int)($data['kpiProspects'] ?? 0)), 'Base CRM', 'fa-user-plus', 'prospects.php'],
        ['Clients', number_format((int)($data['kpiClients'] ?? 0)), 'Referentiel', 'fa-users', 'clients.php'],
        ['RDV aujourd hui', number_format((int)($data['kpiRdvToday'] ?? 0)), 'Agenda CRM', 'fa-calendar-day', 'rdv.php'],
        ['RDV 7 jours', number_format((int)($data['kpiRdvNext7'] ?? 0)), 'A venir', 'fa-calendar-check', 'rdv.php'],
    ];
    foreach ($cards as $card): ?>
        <a href="<?= crm_h($card[4]) ?>" class="group rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)] transition hover:-translate-y-0.5 hover:border-orange-200">
            <div class="flex justify-between gap-4">
                <div>
                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500"><?= crm_h($card[0]) ?></div>
                    <div class="mt-3 text-3xl font-bold text-slate-950"><?= crm_h($card[1]) ?></div>
                    <div class="mt-2 text-sm text-slate-600"><?= crm_h($card[2]) ?></div>
                </div><span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-orange-50 text-orange-700 group-hover:bg-orange-600 group-hover:text-white"><i class="fas <?= crm_h($card[3]) ?>"></i></span>
            </div>
        </a>
    <?php endforeach; ?>
</section>
<section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-4 mb-6">
    <?php $kpiCards = [
        ['Opportunites ouvertes', number_format((int)($crmKpis['opportunities_open'] ?? 0)), 'Pipeline actif', 'fa-chart-line'],
        ['Pipeline estime', crm_xof($crmKpis['pipeline_amount'] ?? 0), 'Montant potentiel', 'fa-coins'],
        ['Gagnees', number_format((int)($crmKpis['opportunities_won'] ?? 0)), 'Affaires conclues', 'fa-trophy'],
        ['Perdues', number_format((int)($crmKpis['opportunities_lost'] ?? 0)), 'A analyser', 'fa-circle-xmark'],
        ['Conversion', crm_pct($crmKpis['conversion_rate'] ?? 0), 'Gagnees / closes', 'fa-percent'],
    ];
    foreach ($kpiCards as $card): ?>
        <div class="<?= crm_card_class() ?>">
            <div class="flex justify-between gap-4">
                <div class="min-w-0">
                    <div class="text-xs font-semibold uppercase tracking-wide text-slate-500"><?= crm_h($card[0]) ?></div>
                    <div class="mt-2 truncate text-xl font-extrabold text-[#1d2b57]"><?= crm_h($card[1]) ?></div>
                    <div class="mt-1 text-xs text-slate-500"><?= crm_h($card[2]) ?></div>
                </div><span class="flex h-11 w-11 items-center justify-center rounded-2xl bg-[#fabd02]/15 text-[#1d2b57]"><i class="fas <?= crm_h($card[3]) ?>"></i></span>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<div class="grid gap-6 xl:grid-cols-[1.35fr_0.65fr]">
    <main class="space-y-6">
        <section class="<?= crm_card_class() ?>">
            <div class="flex justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Pipeline</p>
                    <h2 class="mt-1 text-xl font-semibold text-slate-950">Pipeline par statut</h2>
                </div><a href="opportunites.php" class="text-sm font-semibold text-orange-700">Kanban</a>
            </div><?php $maxPipeline = max(1, ...array_map(static fn($r) => (float)($r['amount'] ?? 0), $pipelineByStage ?: [['amount' => 0]])); ?><div class="mt-4 space-y-3"><?php if (!$pipelineByStage): ?><div class="text-sm text-slate-500">Aucune opportunite.</div><?php endif; ?><?php foreach ($pipelineByStage as $stage): ?><?php $amount = (float)($stage['amount'] ?? 0);
                                                                                                                                                                                                                                                                                                                                $pct = min(100, ($amount / $maxPipeline) * 100); ?><div>
                        <div class="flex justify-between text-xs"><span class="font-semibold text-slate-700"><?= crm_h($stage['etape'] ?? 'N/A') ?> (<?= (int)($stage['count_items'] ?? 0) ?>)</span><strong class="text-[#1d2b57]"><?= crm_h(crm_xof($amount)) ?></strong></div>
                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-[#fabd02]" style="width: <?= (float)$pct ?>%"></div>
                        </div>
                    </div><?php endforeach; ?></div>
        </section>
        <section class="grid gap-6 lg:grid-cols-2">
            <article class="<?= crm_card_class() ?>">
                <h2 class="text-lg font-semibold text-slate-950">Prospects par source</h2><?php $maxSource = max(1, ...array_map(static fn($r) => (int)($r['count_items'] ?? 0), $prospectsBySource ?: [['count_items' => 0]])); ?><div class="mt-4 space-y-3"><?php foreach ($prospectsBySource as $source): ?><?php $count = (int)($source['count_items'] ?? 0); ?><div>
                        <div class="flex justify-between text-xs"><span><?= crm_h($source['source'] ?? 'N/A') ?></span><strong><?= $count ?></strong></div>
                        <div class="mt-1 h-2 rounded-full bg-slate-100">
                            <div class="h-2 rounded-full bg-[#1d2b57]" style="width: <?= min(100, ($count / $maxSource) * 100) ?>%"></div>
                        </div>
                    </div><?php endforeach; ?></div>
            </article>
            <article class="<?= crm_card_class() ?>">
                <h2 class="text-lg font-semibold text-slate-950">Conversion mensuelle</h2>
                <div class="mt-4 grid gap-3"><?php if (!$monthlyConversion): ?><div class="text-sm text-slate-500">Pas encore de donnees.</div><?php endif; ?><?php foreach ($monthlyConversion as $month): ?><div class="rounded-xl border border-slate-100 bg-slate-50 p-3"><strong class="text-xs text-slate-700"><?= crm_h($month['month_key'] ?? '') ?></strong>
                            <div class="mt-2 flex gap-2 text-xs"><span class="rounded-full bg-emerald-100 px-2 py-1 text-emerald-700">Gagnees: <?= (int)($month['won'] ?? 0) ?></span><span class="rounded-full bg-rose-100 px-2 py-1 text-rose-700">Perdues: <?= (int)($month['lost'] ?? 0) ?></span></div>
                        </div><?php endforeach; ?></div>
            </article>
        </section>
        <section class="<?= crm_card_class() ?>">
            <div class="flex justify-between">
                <h2 class="text-lg font-semibold text-slate-950">Prochains RDV</h2><a href="rdv.php" class="text-sm font-semibold text-orange-700">Voir tout</a>
            </div>
            <div class="mt-4 divide-y rounded-2xl border border-slate-100 bg-slate-50"><?php if (!$upcomingRdv): ?><div class="p-4 text-sm text-slate-500">Aucun RDV planifie.</div><?php endif; ?><?php foreach (array_slice($upcomingRdv, 0, 8) as $r): ?><div class="p-3">
                        <div class="font-semibold text-slate-900"><?= crm_h($r['titre'] ?? '') ?></div>
                        <div class="text-xs text-slate-600"><?= crm_h($r['date_debut'] ?? '') ?><?php if (!empty($r['linked_name'])): ?> - <?= crm_h($r['linked_name']) ?><?php endif; ?></div>
                    </div><?php endforeach; ?></div>
        </section>
    </main>
    <aside class="<?= crm_card_class() ?> xl:sticky xl:top-6 xl:self-start">
        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-orange-600">Actions</p>
        <h2 class="mt-1 text-xl font-semibold text-slate-950">A traiter</h2>
        <div class="mt-4 space-y-4">
            <div>
                <div class="font-semibold text-slate-800">Aujourd hui</div><?php if (!$todoTodayEvents && !$todoTodayOpp): ?><div class="mt-2 text-sm text-slate-500">Aucune action aujourd hui.</div><?php endif; ?><?php foreach (array_slice($todoTodayEvents, 0, 4) as $ev): ?><div class="mt-2 rounded-xl bg-slate-50 p-3 text-sm"><strong><?= crm_h($ev['title'] ?? '') ?></strong>
                        <div class="text-xs text-slate-500"><?= crm_h($ev['start_at'] ?? '') ?> - <?= crm_h($ev['end_at'] ?? '') ?></div>
                    </div><?php endforeach; ?><?php foreach (array_slice($todoTodayOpp, 0, 4) as $o): ?><div class="mt-2 rounded-xl bg-amber-50 p-3 text-sm"><strong><?= crm_h($o['titre'] ?? '') ?></strong>
                        <div class="text-xs text-slate-500"><?= crm_h($o['next_action_at'] ?? '') ?></div>
                    </div><?php endforeach; ?>
            </div>
            <div>
                <div class="font-semibold text-slate-800">Relances en retard</div><?php if (!$overdueFollowups): ?><div class="mt-2 text-sm text-slate-500">Aucune relance en retard.</div><?php endif; ?><?php foreach (array_slice($overdueFollowups, 0, 6) as $p): ?><a href="prospects.php" class="mt-2 block rounded-xl bg-rose-50 p-3 text-sm"><strong><?= crm_h($p['nom'] ?? 'Prospect') ?></strong>
                        <div class="text-xs text-slate-500"><?= crm_h($p['next_followup_at'] ?? '') ?></div>
                    </a><?php endforeach; ?>
            </div>
            <div class="pt-2"><a class="block rounded-xl bg-[#1d2b57] px-4 py-3 text-center font-semibold text-white" href="/emploi_du_temps/index.php?period=week&date=<?= crm_h(date('Y-m-d')) ?>">Ouvrir l'emploi du temps</a></div>
        </div>
    </aside>
</div>
<?= crm_page_close() ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
