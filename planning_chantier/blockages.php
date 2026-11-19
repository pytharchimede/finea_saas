<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/blockages.php');
}

$repo = new PlanningBlockageRepository($pdo);
$rows = $repo->open();

$pageTitle = 'Blocages planning';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) { pc_render_fallback_header($pageTitle); }
planning_chantier_include_layout('navbar');
pc_render_page_start('Blocages ouverts', 'Tous les problèmes qui empêchent l’exécution normale des tâches.', 'Suivi des blocages');
?>
<section class="mt-6 grid gap-6 lg:grid-cols-3">
    <?php foreach ([
        ['Critiques', count(array_filter($rows, fn($r)=>(string)$r['priorite']==='critique')), 'fa-fire', 'bg-red-50 text-red-700'],
        ['Haute priorité', count(array_filter($rows, fn($r)=>(string)$r['priorite']==='haute')), 'fa-triangle-exclamation', 'bg-orange-50 text-orange-700'],
        ['Total ouverts', count($rows), 'fa-lock-open', 'bg-sky-50 text-sky-700'],
    ] as $k): ?>
        <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
            <div class="flex justify-between gap-4">
                <div><div class="text-xs uppercase tracking-[0.22em] text-slate-500"><?= pc_e($k[0]) ?></div><div class="mt-3 text-3xl font-semibold text-slate-900"><?= pc_number($k[1]) ?></div></div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl <?= pc_e($k[3]) ?> text-xl"><i class="fas <?= pc_e($k[2]) ?>"></i></div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="mt-6 rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
    <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div><p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Registre opérationnel</p><h2 class="mt-1 text-2xl font-semibold text-slate-950">Liste des blocages</h2></div>
        <input data-pc-search type="search" placeholder="Rechercher..." class="h-12 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm">
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-separate border-spacing-y-3">
            <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Chantier</th><th>Tâche</th><th>Blocage</th><th>Cause</th><th>Priorité</th><th>Détection</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr data-pc-row class="pc-table-line transition">
                    <td class="rounded-l-2xl px-4 py-4 font-semibold text-slate-900"><?= pc_e((string)$r['project_label']) ?></td>
                    <td><?= pc_e((string)$r['task_title']) ?></td>
                    <td><div class="font-semibold text-slate-800"><?= pc_e((string)$r['titre']) ?></div><div class="line-clamp-2 text-sm text-slate-500"><?= pc_e((string)$r['action_corrective']) ?></div></td>
                    <td class="max-w-md text-sm text-slate-600"><?= pc_e((string)$r['cause']) ?></td>
                    <td><?= pc_badge((string)$r['priorite']) ?></td>
                    <td class="rounded-r-2xl"><?= pc_date($r['date_detection']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr class="bg-white"><td colspan="6" class="rounded-2xl px-4 py-6 text-slate-500">Aucun blocage ouvert.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php pc_render_page_end(); ?>
