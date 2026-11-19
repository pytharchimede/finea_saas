<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/dashboard.php');
}

$meetingRepo = new PlanningMeetingRepository($pdo);
$summary = $meetingRepo->dashboardSummary();
$recentMeetings = $meetingRepo->recent(12);
$openPreparations = (new PlanningMeetingPreparationRepository($pdo))->openDrafts(12);

$pageTitle = 'Tableau de bord des réunions';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) {
    pc_render_fallback_header($pageTitle);
}
planning_chantier_include_layout('navbar');
pc_render_page_start(
    'Tableau de bord des réunions chantier',
    'Retrouvez les réunions passées, leurs participants et l’état des points décidés.',
    'Suivi des réunions'
);

$completionRate = $summary['points_total'] > 0
    ? round($summary['points_closed'] / $summary['points_total'] * 100, 1)
    : 0;
$cards = [
    ['label' => 'Réunions enregistrées', 'value' => $summary['meetings_total'], 'hint' => $summary['meetings_month'] . ' ce mois', 'icon' => 'fa-people-group', 'tone' => 'bg-indigo-50 text-indigo-700'],
    ['label' => 'Réunions clôturées', 'value' => $summary['meetings_closed'], 'hint' => $summary['meetings_draft'] . ' brouillon(s)', 'icon' => 'fa-circle-check', 'tone' => 'bg-emerald-50 text-emerald-700'],
    ['label' => 'Points de réunion', 'value' => $summary['points_total'], 'hint' => $summary['points_open'] . ' encore à suivre', 'icon' => 'fa-list-check', 'tone' => 'bg-orange-50 text-orange-700'],
    ['label' => 'Points traités', 'value' => pc_percent($completionRate), 'hint' => $summary['points_closed'] . ' point(s) terminé(s)', 'icon' => 'fa-chart-pie', 'tone' => 'bg-sky-50 text-sky-700'],
];
?>

<section class="mt-8 grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($cards as $card): ?>
        <article class="rounded-3xl border border-white/70 bg-white/95 p-6 shadow-[0_16px_45px_rgba(15,23,42,.07)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[.18em] text-slate-500"><?= pc_e($card['label']) ?></p>
                    <strong class="mt-3 block text-3xl text-slate-950"><?= pc_e((string)$card['value']) ?></strong>
                    <span class="mt-2 block text-sm text-slate-500"><?= pc_e($card['hint']) ?></span>
                </div>
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl <?= $card['tone'] ?> text-xl">
                    <i class="fas <?= $card['icon'] ?>"></i>
                </span>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="pc-dashboard-main-grid mt-8">
    <div class="pc-dashboard-action-column" role="complementary" aria-label="Actions et participation">
        <article class="rounded-[30px] bg-gradient-to-br from-slate-950 via-[#1d2b57] to-indigo-950 p-7 text-white shadow-[0_22px_60px_rgba(15,23,42,.24)]">
            <p class="text-xs font-semibold uppercase tracking-[.22em] text-amber-200">Prochaine action</p>
            <h2 class="mt-2 text-2xl font-semibold">Préparer la prochaine réunion</h2>
            <p class="mt-3 text-sm leading-6 text-slate-200">Choisissez une réunion Bureau ou chantier, définissez l’ordre du jour, les éléments concernés et les participants.</p>
            <a href="preparation_reunion.php" class="mt-6 inline-flex items-center rounded-2xl bg-[#fabd02] px-5 py-3 text-sm font-bold text-slate-950 transition hover:bg-amber-300">
                Commencer la préparation <i class="fas fa-arrow-right ml-2"></i>
            </a>
        </article>

        <article class="rounded-[30px] border border-white/70 bg-white/95 p-7 shadow-[0_18px_55px_rgba(15,23,42,.08)]">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="pc-section-kicker">Participation</p>
                    <strong class="mt-2 block text-4xl text-slate-950"><?= pc_number($summary['presences_total']) ?></strong>
                    <p class="mt-2 text-sm text-slate-500">Présences enregistrées dans l’ensemble des réunions.</p>
                </div>
                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-xl text-indigo-700"><i class="fas fa-user-check"></i></span>
            </div>
        </article>
        <a href="work_progress.php" class="block rounded-[30px] border border-indigo-200 bg-indigo-50 p-7 shadow-[0_18px_55px_rgba(15,23,42,.08)] transition hover:-translate-y-0.5 hover:bg-white">
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-700 text-xl text-white"><i class="fas fa-chart-column"></i></span>
            <strong class="mt-4 block text-xl text-slate-950">Taux d’avancement des travaux</strong>
            <p class="mt-2 text-sm leading-6 text-slate-600">Renseigner les quantités et montants facturés, puis calculer les avancements technique et financier.</p>
        </a>
    </div>

    <article class="pc-dashboard-history rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,.08)]">
        <header class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="pc-section-kicker">Historique</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-950">Dernières réunions</h2>
                <p class="mt-1 text-sm text-slate-500">Les comptes rendus les plus récents et leurs éléments de suivi.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="meeting_archives.php" class="pc-btn pc-btn-soft"><i class="fas fa-box-archive"></i> Archives</a>
                <a href="reunion.php" class="pc-btn pc-btn-primary"><i class="fas fa-plus"></i> Nouvelle réunion</a>
            </div>
        </header>

        <div class="grid gap-4 md:grid-cols-2">
            <?php foreach ($recentMeetings as $meeting): ?>
                <?php $closed = ($meeting['status'] ?? 'draft') === 'closed'; ?>
                <article class="group rounded-3xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:border-orange-200 hover:bg-white hover:shadow-lg">
                    <div class="flex items-start justify-between gap-3">
                        <span class="rounded-xl bg-white px-3 py-2 text-sm font-bold text-slate-700 shadow-sm">
                            <i class="fas fa-calendar-day mr-2 text-orange-600"></i><?= pc_e(date('d/m/Y', strtotime((string)$meeting['meeting_date']))) ?>
                        </span>
                        <div style="display:flex;align-items:center;justify-content:flex-end;gap:.45rem;flex-wrap:wrap">
                            <span class="rounded-full px-3 py-1 text-xs font-bold <?= $closed ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                <?= $closed ? 'Clôturée' : 'Brouillon' ?>
                            </span>
                            <a href="meeting.php?id=<?= (int)$meeting['id'] ?>"
                                title="Consulter le compte rendu"
                                aria-label="Consulter le compte rendu"
                                style="display:inline-flex;width:2.25rem;height:2.25rem;align-items:center;justify-content:center;border:1px solid #cbd5e1;border-radius:.7rem;background:#fff;color:#1d2b57;text-decoration:none">
                                <i class="fas fa-eye"></i>
                            </a>
                            <a href="exports/meeting_pdf.php?id=<?= (int)$meeting['id'] ?>"
                                target="_blank"
                                rel="noopener"
                                title="Consulter ou exporter le PDF"
                                aria-label="Consulter ou exporter le PDF"
                                style="display:inline-flex;width:2.25rem;height:2.25rem;align-items:center;justify-content:center;border:1px solid #1d2b57;border-radius:.7rem;background:#1d2b57;color:#fff;text-decoration:none">
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        </div>
                    </div>
                    <h3 class="mt-4 line-clamp-2 font-semibold text-slate-950 group-hover:text-orange-700"><a href="meeting.php?id=<?= (int)$meeting['id'] ?>"><?= pc_e((string)$meeting['titre']) ?></a></h3>
                    <small class="mt-1 block font-mono text-[11px] font-bold text-indigo-700"><?= pc_e((string)($meeting['report_code'] ?? '')) ?></small>
                    <?php $meetingContext = trim((string)($meeting['project_label'] ?? '')); ?>
                    <p class="mt-2 text-sm font-semibold text-slate-700">
                        <i class="fas <?= !empty($meeting['office_service_line_id']) ? 'fa-briefcase' : 'fa-helmet-safety' ?> mr-1.5 text-indigo-600"></i>
                        <?= pc_e(!empty($meeting['office_service_line_id'])
                            ? 'Service concerné : ' . ($meetingContext ?: 'Non précisé')
                            : ($meetingContext ?: 'Réunion multi-chantiers')) ?>
                    </p>
                    <div class="mt-4 flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
                        <span class="rounded-lg bg-white px-2.5 py-1.5"><i class="fas fa-thumbtack mr-1 text-orange-500"></i><?= pc_number($meeting['points_count']) ?> point(s)</span>
                        <span class="rounded-lg bg-white px-2.5 py-1.5"><i class="fas fa-users mr-1 text-indigo-500"></i><?= pc_number($meeting['participants_count']) ?> participant(s)</span>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2 border-t border-slate-200 pt-4">
                        <a href="meeting.php?id=<?= (int)$meeting['id'] ?>" class="pc-btn pc-btn-soft"><i class="fas fa-eye"></i> Consulter le rapport</a>
                        <a href="exports/meeting_pdf.php?id=<?= (int)$meeting['id'] ?>" target="_blank" class="pc-btn pc-btn-primary"><i class="fas fa-file-pdf"></i> Consulter le PDF</a>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$recentMeetings): ?>
                <div class="md:col-span-2 rounded-3xl border border-dashed border-slate-200 p-10 text-center text-slate-500">
                    <i class="fas fa-calendar-plus mb-3 block text-3xl text-orange-400"></i>
                    Aucune réunion enregistrée pour le moment.
                </div>
            <?php endif; ?>
        </div>
    </article>
</section>

<?php if ($openPreparations): ?>
    <section class="mt-8 rounded-[30px] border border-amber-200 bg-amber-50/90 p-6">
        <p class="pc-section-kicker">À reprendre avant toute nouvelle réunion</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">Brouillons et salles préparées</h2>
        <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <?php foreach ($openPreparations as $prep): ?>
                <?php
                $serviceLabel = trim((string)($prep['service_label'] ?? ''));
                $chantierLabel = trim((string)($prep['chantier_label'] ?? ''));
                $agenda = trim((string)($prep['agenda'] ?? ''));
                $participantCount = count(json_decode((string)($prep['participants_json'] ?? '[]'), true) ?: []);
                ?>
                <article class="rounded-2xl border border-amber-100 bg-white p-4">
                    <strong class="block text-slate-950"><?= pc_e((string)$prep['titre']) ?></strong>
                    <small class="mt-1 block text-slate-500"><?= pc_date((string)$prep['meeting_date']) ?> · <?= pc_e((string)$prep['status']) ?></small>
                    <p class="mt-3 text-sm font-semibold text-slate-800">
                        <i class="fas <?= $serviceLabel !== '' ? 'fa-briefcase' : 'fa-helmet-safety' ?> mr-1.5 text-indigo-600"></i>
                        <?= pc_e($serviceLabel !== '' ? 'Service concerné : ' . $serviceLabel : ($chantierLabel ?: 'Chantiers à consulter dans le brouillon')) ?>
                    </p>
                    <?php if ($agenda !== ''): ?>
                        <p class="mt-2 line-clamp-2 text-sm text-slate-600"><strong>Ordre du jour :</strong> <?= pc_e($agenda) ?></p>
                    <?php endif; ?>
                    <p class="mt-2 text-xs font-semibold text-slate-500"><i class="fas fa-users mr-1"></i><?= pc_number($participantCount) ?> participant(s)</p>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a class="pc-btn pc-btn-soft" href="<?= $prep['status'] === 'ready' ? 'reunion.php?preparation_id=' . (int)$prep['id'] : 'preparation_reunion.php?id=' . (int)$prep['id'] ?>"><i class="fas fa-rotate-right"></i> Reprendre</a>
                        <form action="request/archive_meeting_record.php" method="post"><input type="hidden" name="type" value="preparation"><input type="hidden" name="id" value="<?= (int)$prep['id'] ?>"><button class="pc-btn pc-btn-soft" data-pc-confirm="Archiver ce brouillon ?"><i class="fas fa-box-archive"></i></button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<?php pc_render_page_end(); ?>
