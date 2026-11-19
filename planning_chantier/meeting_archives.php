<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/includes/ui.php';
if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/dashboard.php');

$reports=(new PlanningMeetingRepository($pdo))->archivedReports(200);
$pageTitle='Archives des comptes rendus';
$activeMenu='planning_chantier';
if(!planning_chantier_include_layout('header'))pc_render_fallback_header($pageTitle);
planning_chantier_include_layout('navbar');
pc_render_page_start(
    'Archives des comptes rendus',
    'Consultez les anciennes versions conservées de chaque réunion de service ou de chantier.',
    'Historique immuable'
);
?>
<section class="mt-8 rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,.08)]">
    <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="pc-section-kicker">Rapports remplacés ou archivés</p>
            <h2 class="mt-1 text-2xl font-semibold text-slate-950"><?=count($reports)?> compte(s) rendu(s)</h2>
            <p class="mt-1 text-sm text-slate-500">Ces documents restent consultables et ne sont jamais supprimés.</p>
        </div>
        <a href="dashboard.php" class="pc-btn pc-btn-soft"><i class="fas fa-arrow-left"></i> Rapports courants</a>
    </header>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach($reports as $report):?>
            <article class="rounded-2xl border border-slate-200 bg-slate-50 p-5">
                <div class="flex items-start justify-between gap-3">
                    <span class="font-mono text-xs font-bold text-indigo-700"><?=pc_e((string)($report['report_code']??('CR-'.$report['id'])))?></span>
                    <div style="display:flex;align-items:center;gap:.4rem">
                        <span class="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-bold text-slate-700">Archivé</span>
                        <a href="meeting.php?id=<?=(int)$report['id']?>" title="Consulter le compte rendu" aria-label="Consulter le compte rendu" style="display:inline-flex;width:2.1rem;height:2.1rem;align-items:center;justify-content:center;border:1px solid #cbd5e1;border-radius:.65rem;background:#fff;color:#1d2b57;text-decoration:none"><i class="fas fa-eye"></i></a>
                        <a target="_blank" rel="noopener" href="exports/meeting_pdf.php?id=<?=(int)$report['id']?>" title="Consulter ou exporter le PDF" aria-label="Consulter ou exporter le PDF" style="display:inline-flex;width:2.1rem;height:2.1rem;align-items:center;justify-content:center;border:1px solid #1d2b57;border-radius:.65rem;background:#1d2b57;color:#fff;text-decoration:none"><i class="fas fa-file-pdf"></i></a>
                    </div>
                </div>
                <h3 class="mt-3 font-semibold text-slate-950"><?=pc_e((string)$report['titre'])?></h3>
                <p class="mt-1 text-sm font-medium text-slate-600"><?=pc_e((string)$report['project_label'])?></p>
                <p class="mt-1 text-xs text-slate-500"><?=pc_date((string)$report['meeting_date'])?> · <?=pc_number($report['points_count'])?> point(s) · <?=pc_number($report['participants_count'])?> participant(s)</p>
                <div class="mt-4 flex gap-2">
                    <a class="pc-btn pc-btn-soft" href="meeting.php?id=<?=(int)$report['id']?>"><i class="fas fa-eye"></i> Consulter</a>
                    <a class="pc-btn pc-btn-soft" target="_blank" href="exports/meeting_pdf.php?id=<?=(int)$report['id']?>"><i class="fas fa-file-pdf"></i> PDF</a>
                    <?php if((int)($report['preparation_id']??0)>0):?><a class="pc-btn pc-btn-soft" target="_blank" href="request/meeting_gantt_pdf.php?preparation_id=<?=(int)$report['preparation_id']?>&amp;all=1"><i class="fas fa-chart-gantt"></i> Gantt</a><?php endif;?>
                </div>
            </article>
        <?php endforeach;?>
        <?php if(!$reports):?>
            <div class="md:col-span-2 xl:col-span-3 rounded-2xl border border-dashed border-slate-200 p-12 text-center text-slate-500">
                <i class="fas fa-box-archive mb-3 block text-3xl"></i>Aucun ancien rapport archivé.
            </div>
        <?php endif;?>
    </div>
</section>
<?php pc_render_page_end();?>
