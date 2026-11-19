<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/tasks.php');
}

$taskRepo = new PlanningTaskRepository($pdo);
$rows = $taskRepo->allWithProject();

$pageTitle = 'Tâches planning';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) { pc_render_fallback_header($pageTitle); }
planning_chantier_include_layout('navbar');
pc_render_page_start('Toutes les tâches planning', 'Vue consolidée de toutes les tâches de chantier.');
?>
<section class="mt-6 rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div><p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Portefeuille planning</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Liste des tâches</h2></div>
        <div class="flex flex-wrap gap-3"><input data-pc-search type="search" placeholder="Rechercher..." class="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm"><a href="task_form.php" class="pc-btn pc-btn-primary"><i class="fas fa-plus"></i> Ajouter</a></div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full border-separate border-spacing-y-3">
            <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Chantier</th><th>Tâche</th><th>Prévu</th><th>Réel</th><th>Écart</th><th>Statut</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $ecart=(float)$r['avancement_reel']-(float)$r['avancement_prevu']; ?>
                <tr data-pc-row class="pc-table-line transition">
                    <td class="rounded-l-2xl px-4 py-4"><div class="font-semibold text-slate-900"><?= pc_e((string)$r['project_label']) ?></div><div class="text-sm text-slate-500"><?= pc_e((string)($r['project_code'] ?? '')) ?></div></td>
                    <td><div class="font-semibold text-slate-800"><?php if(!empty($r['parent_id'])):?><span class="mr-2 rounded-full bg-indigo-50 px-2 py-1 text-[10px] font-black uppercase text-indigo-700"><i class="fas fa-turn-up rotate-90 mr-1"></i>Sous-tâche</span><?php endif;?><?= pc_e((string)$r['titre']) ?></div><?php if(!empty($r['parent_title'])):?><div class="mt-1 text-xs font-semibold text-indigo-600">Sous <?=pc_e((string)$r['parent_title'])?></div><?php endif;?><div class="line-clamp-2 text-sm text-slate-500"><?= pc_e((string)$r['description']) ?></div></td>
                    <td class="min-w-[120px]"><div class="mb-1 font-semibold"><?= pc_percent($r['avancement_prevu']) ?></div><div class="pc-progress-track"><div class="pc-progress-bar-blue" style="width:<?= max(0,min(100,(float)$r['avancement_prevu'])) ?>%"></div></div></td>
                    <td class="min-w-[120px]"><div class="mb-1 font-semibold"><?= pc_percent($r['avancement_reel']) ?></div><div class="pc-progress-track"><div class="pc-progress-bar" style="width:<?= max(0,min(100,(float)$r['avancement_reel'])) ?>%"></div></div></td>
                    <td><strong class="<?= $ecart < -5 ? 'text-red-700' : 'text-emerald-700' ?>"><?= pc_percent($ecart) ?></strong></td>
                    <td class="rounded-r-2xl"><?= pc_badge((string)$r['statut']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr class="bg-white"><td colspan="6" class="rounded-2xl px-4 py-6 text-slate-500">Aucune tâche trouvée.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php pc_render_page_end(); ?>
