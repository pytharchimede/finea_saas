<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/chantier.php');
}

$id = (int)($_GET['id'] ?? 0);
$projectRepo = new PlanningProjectRepository($pdo);
$taskRepo = new PlanningTaskRepository($pdo);
$blockRepo = new PlanningBlockageRepository($pdo);
$dashboardService = new PlanningDashboardService($projectRepo, $taskRepo, $blockRepo);
$data = (new PlanningChantierController($projectRepo, $taskRepo, $blockRepo, $dashboardService))->show($id);

$pageTitle = 'Détail Planning Chantier';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) { pc_render_fallback_header($pageTitle); }
planning_chantier_include_layout('navbar');
pc_render_page_start((string)$data['project']['libelle'], 'Suivi détaillé des tâches, écarts, blocages et corrections terrain.', 'Détail chantier');
$stats = $data['stats'];
$kpis = [
    ['label'=>'Prévu','value'=>pc_percent($stats['planned']),'hint'=>'Avancement planning','icon'=>'fa-calendar-check','tone'=>'bg-sky-50 text-sky-700'],
    ['label'=>'Réel','value'=>pc_percent($stats['real']),'hint'=>'Avancement terrain','icon'=>'fa-person-digging','tone'=>'bg-emerald-50 text-emerald-700'],
    ['label'=>'Écart','value'=>pc_percent($stats['ecart']),'hint'=>'Réel - prévu','icon'=>'fa-chart-line','tone'=>((float)$stats['ecart'] < -5 ? 'bg-red-50 text-red-700' : 'bg-indigo-50 text-indigo-700')],
    ['label'=>'Blocages','value'=>pc_number(count(array_filter($data['blockages'], fn($b) => in_array($b['statut'], ['ouvert','en_traitement'], true)))),'hint'=>'Ouverts','icon'=>'fa-triangle-exclamation','tone'=>'bg-orange-50 text-orange-700'],
];
?>
<section class="mt-6 grid grid-cols-1 gap-5 md:grid-cols-4">
    <?php foreach ($kpis as $kpi): ?>
        <article class="rounded-3xl border border-white/60 bg-white/90 p-6 shadow-sm">
            <div class="flex justify-between gap-4">
                <div><div class="text-xs uppercase tracking-[0.22em] text-slate-500"><?= pc_e($kpi['label']) ?></div><div class="mt-3 text-3xl font-semibold text-slate-900"><?= pc_e($kpi['value']) ?></div><p class="mt-2 text-sm text-slate-600"><?= pc_e($kpi['hint']) ?></p></div>
                <div class="flex h-12 w-12 items-center justify-center rounded-2xl <?= pc_e($kpi['tone']) ?> text-xl"><i class="fas <?= pc_e($kpi['icon']) ?>"></i></div>
            </div>
        </article>
    <?php endforeach; ?>
</section>

<section class="mt-8 grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
    <article class="rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
        <div class="mb-5 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Planning opérationnel</p>
                <h2 class="mt-1 text-2xl font-semibold text-slate-950">Tâches du chantier</h2>
            </div>
            <a href="task_form.php?project_id=<?= (int)$data['project']['id'] ?>" class="pc-btn pc-btn-primary"><i class="fas fa-plus"></i> Ajouter une tâche</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-separate border-spacing-y-3">
                <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Tâche</th><th>Période</th><th>Prévu</th><th>Réel</th><th>Statut</th><th class="text-right">Mise à jour</th></tr></thead>
                <tbody>
                <?php foreach ($data['tasks'] as $task): ?>
                    <tr class="pc-table-line transition">
                        <td class="rounded-l-2xl px-4 py-4">
                            <div class="font-semibold text-slate-900"><?= pc_e((string)$task['titre']) ?></div>
                            <div class="line-clamp-2 text-sm text-slate-500"><?= pc_e((string)$task['description']) ?></div>
                        </td>
                        <td><div class="text-sm font-semibold text-slate-700"><?= pc_date($task['date_debut_prevue']) ?></div><div class="text-sm text-slate-500"><?= pc_date($task['date_fin_prevue']) ?></div></td>
                        <td class="min-w-[120px]"><div class="mb-1 text-sm font-semibold"><?= pc_percent($task['avancement_prevu']) ?></div><div class="pc-progress-track"><div class="pc-progress-bar-blue" style="width:<?= max(0,min(100,(float)$task['avancement_prevu'])) ?>%"></div></div></td>
                        <td class="min-w-[120px]"><div class="mb-1 text-sm font-semibold"><?= pc_percent($task['avancement_reel']) ?></div><div class="pc-progress-track"><div class="pc-progress-bar" style="width:<?= max(0,min(100,(float)$task['avancement_reel'])) ?>%"></div></div></td>
                        <td><?= pc_badge((string)$task['statut']) ?></td>
                        <td class="rounded-r-2xl py-4 pr-4">
                            <form action="request/save_progress.php" method="post" class="pc-inline-progress-form">
                                <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                <input type="hidden" name="redirect" value="../chantier.php?id=<?= (int)$data['project']['id'] ?>">
                                <div class="pc-mini-row">
                                    <label class="sr-only">Avancement réel</label>
                                    <input name="avancement_reel" type="number" min="0" max="100" step="1" value="<?= (float)$task['avancement_reel'] ?>" aria-label="Avancement réel en pourcentage">
                                    <button class="pc-btn pc-btn-yellow" type="submit"><i class="fas fa-check"></i> Valider</button>
                                </div>
                                <input name="commentaire" type="text" placeholder="Commentaire rapide (facultatif)">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$data['tasks']): ?><tr class="bg-white"><td colspan="6" class="rounded-2xl px-4 py-6 text-slate-500">Aucune tâche créée pour ce chantier.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>

    <aside class="rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Correction rapide</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">Déclarer un blocage</h2>
        <?php if (!$data['tasks']): ?>
            <div class="mt-5 pc-empty-help">
                <strong>Aucune tâche disponible.</strong><br>
                Vous devez d'abord créer au moins une tâche planning avant de pouvoir déclarer un blocage.
                <div class="mt-4"><a href="task_form.php?project_id=<?= (int)$data['project']['id'] ?>" class="pc-btn pc-btn-primary"><i class="fas fa-plus"></i> Créer une tâche</a></div>
            </div>
        <?php else: ?>
        <form class="mt-5 pc-form-modern" action="request/save_blockage.php" method="post">
            <input type="hidden" name="project_id" value="<?= (int)$data['project']['id'] ?>">
            <input type="hidden" name="redirect" value="../chantier.php?id=<?= (int)$data['project']['id'] ?>">

            <div class="pc-form-panel">
                <div class="pc-form-section-title"><i class="fas fa-triangle-exclamation"></i><span>Problème constaté</span></div>
                <div class="pc-field">
                    <label><span class="pc-label-text">Tâche concernée <span class="pc-required">*</span></span></label>
                    <select name="task_id" required data-pc-select-search data-placeholder="Rechercher une tâche du chantier...">
                        <?php foreach ($data['tasks'] as $task): ?><option value="<?= (int)$task['id'] ?>"><?= pc_e((string)$task['titre']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="pc-help">Sélectionnez la tâche qui n'a pas pu être exécutée normalement.</div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Titre du blocage <span class="pc-required">*</span></span></label>
                    <input name="titre" required placeholder="Ex: Attente livraison ciment">
                    <div class="pc-help">Résumé court du problème pour lecture rapide en réunion.</div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Cause <span class="pc-required">*</span></span></label>
                    <textarea class="pc-textarea-lg" name="cause" required placeholder="Expliquez clairement pourquoi la tâche n'a pas été exécutée : manque de matériel, absence équipe, pluie, panne, attente validation..."></textarea>
                </div>
            </div>

            <div class="pc-form-panel">
                <div class="pc-form-section-title"><i class="fas fa-screwdriver-wrench"></i><span>Correction proposée</span></div>
                <div class="pc-field">
                    <label><span class="pc-label-text">Action corrective</span><span class="pc-optional">facultatif</span></label>
                    <textarea name="action_corrective" placeholder="Décision / correction proposée : responsable, action à mener, délai attendu..."></textarea>
                </div>
                <div class="pc-form-grid-2 mt-4">
                    <div class="pc-field">
                        <label><span class="pc-label-text">Priorité <span class="pc-required">*</span></span></label>
                        <select name="priorite" required>
                            <option value="normale">Normale</option>
                            <option value="haute">Haute</option>
                            <option value="critique">Critique</option>
                            <option value="basse">Basse</option>
                        </select>
                    </div>
                    <div class="pc-field">
                        <label><span class="pc-label-text">Date de détection <span class="pc-required">*</span></span></label>
                        <input name="date_detection" type="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
            </div>

            <div class="pc-form-footer">
                <div class="pc-form-required-note"><span class="pc-required">*</span> Champs obligatoires</div>
                <div class="pc-form-actions">
                    <button class="pc-btn pc-btn-danger pc-btn-lg" type="submit"><i class="fas fa-triangle-exclamation"></i> Déclarer le blocage</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </aside>
</section>
<?php pc_render_page_end(); ?>
