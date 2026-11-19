<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if (class_exists('AccessManager')) {
    AccessManager::requirePageAccess('planning_chantier/task_form.php');
}

$projectRepo = new PlanningProjectRepository($pdo);
$projects = $projectRepo->active();
$taskRepo = new PlanningTaskRepository($pdo);
$parentTasks = $taskRepo->allWithProject();
$selectedProject = (int)($_GET['project_id'] ?? 0);

$pageTitle = 'Nouvelle tâche planning';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) { pc_render_fallback_header($pageTitle); }
planning_chantier_include_layout('navbar');
pc_render_page_start('Nouvelle tâche planning', 'Créer une tâche simple, mesurable et facile à mettre à jour pendant la réunion de chantier.', 'Formulaire planning');
?>
<section class="mt-6 grid gap-6 xl:grid-cols-[0.75fr_1.25fr]">
    <aside class="rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
        <div class="flex h-16 w-16 items-center justify-center rounded-3xl bg-orange-50 text-2xl text-orange-700"><i class="fas fa-list-check"></i></div>
        <h2 class="mt-5 text-2xl font-semibold text-slate-950">Saisie terrain simple</h2>
        <p class="mt-3 text-sm leading-7 text-slate-600">
            Une tâche doit être courte, claire et mesurable. Elle doit permettre de répondre rapidement : quoi faire, où, quand, combien est prévu et combien est réellement exécuté.
        </p>
        <div class="mt-5 rounded-2xl bg-slate-50 p-4 text-sm leading-6 text-slate-600">
            <strong class="text-slate-900">Astuce :</strong> utilisez le champ de recherche dans la liste des chantiers pour trouver rapidement le bon chantier.
        </div>
        <div class="mt-4 rounded-2xl border border-orange-100 bg-orange-50/70 p-4 text-sm leading-6 text-orange-900">
            <strong>Obligatoire :</strong> les champs marqués par une étoile rouge doivent être remplis avant validation.
        </div>
    </aside>

    <article class="rounded-[30px] border border-white/70 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.08)]">
        <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Saisie planning</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">Informations de la tâche</h2>

        <form class="mt-6 pc-form-modern" action="request/save_task.php" method="post">
            <div class="pc-form-panel">
                <div class="pc-form-section-title"><i class="fas fa-location-dot"></i><span>Chantier et identification</span></div>
                <div class="pc-form-grid-2">
                    <div class="pc-field">
                        <label><span class="pc-label-text">Chantier <span class="pc-required">*</span></span></label>
                        <select name="project_id" required data-pc-select-search data-placeholder="Taper le nom ou le code du chantier...">
                            <option value="">Sélectionner un chantier</option>
                            <?php foreach ($projects as $project): ?>
                                <option value="<?= (int)$project['id'] ?>" <?= $selectedProject === (int)$project['id'] ? 'selected' : '' ?>><?= pc_e((string)$project['libelle']) ?><?= !empty($project['code']) ? ' - ' . pc_e((string)$project['code']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="pc-help">Commencez à saisir le nom ou le code pour filtrer la liste.</div>
                    </div>
                    <div class="pc-field">
                        <label><span class="pc-label-text">Ordre d’affichage</span><span class="pc-optional">facultatif</span></label>
                        <input type="number" name="ordre" value="0" placeholder="Ex: 1">
                        <div class="pc-help">Permet de classer les tâches dans l’ordre logique d’exécution.</div>
                    </div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Titre de la tâche <span class="pc-required">*</span></span></label>
                    <input name="titre" required placeholder="Ex: Coulage dalle RDC, Terrassement zone A, Pose ferraillage...">
                    <div class="pc-help">Soyez précis et court : action + zone concernée.</div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Tâche parente</span><span class="pc-optional">facultatif — crée une sous-tâche</span></label>
                    <select name="parent_id" id="parentTaskSelect" data-pc-select-search data-placeholder="Sélectionner la tâche principale…">
                        <option value="">Aucune — tâche principale</option>
                        <?php foreach($parentTasks as $parentTask): ?>
                            <option value="<?=(int)$parentTask['id']?>" data-project-id="<?=(int)$parentTask['project_id']?>"><?=pc_e((string)$parentTask['project_label'].' › '.(string)$parentTask['titre'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="pc-help">Une sous-tâche reste rattachée au même chantier et apparaît sous sa tâche principale.</div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Description</span><span class="pc-optional">facultatif</span></label>
                    <textarea class="pc-textarea-lg" name="description" placeholder="Décrire les détails utiles : localisation, quantité, contraintes, matériel nécessaire, dépendances..."></textarea>
                    <div class="pc-help">Cette zone est plus confortable pour les détails lus pendant les réunions de 12h.</div>
                </div>
            </div>

            <div class="pc-form-panel">
                <div class="pc-form-section-title"><i class="fas fa-calendar-days"></i><span>Dates et avancement</span></div>
                <div class="pc-form-grid-2">
                    <div class="pc-field">
                        <label><span class="pc-label-text">Date début prévue</span><span class="pc-optional">facultatif</span></label>
                        <input type="date" name="date_debut_prevue">
                    </div>
                    <div class="pc-field">
                        <label><span class="pc-label-text">Date fin prévue</span><span class="pc-optional">facultatif</span></label>
                        <input type="date" name="date_fin_prevue">
                    </div>
                </div>
                <div class="pc-form-grid-3 mt-4">
                    <div class="pc-field">
                        <label><span class="pc-label-text">Poids <span class="pc-required">*</span></span></label>
                        <input type="number" step="0.01" min="0.01" name="poids" value="1" required>
                        <div class="pc-help">Importance de la tâche dans le calcul global.</div>
                    </div>
                    <div class="pc-field">
                        <label><span class="pc-label-text">Avancement prévu <span class="pc-required">*</span></span></label>
                        <div class="pc-input-prefix"><input type="number" min="0" max="100" step="1" name="avancement_prevu" value="0" required><span class="pc-suffix">%</span></div>
                    </div>
                    <div class="pc-field">
                        <label><span class="pc-label-text">Avancement réel <span class="pc-required">*</span></span></label>
                        <div class="pc-input-prefix"><input type="number" min="0" max="100" step="1" name="avancement_reel" value="0" required><span class="pc-suffix">%</span></div>
                    </div>
                </div>
                <div class="pc-field mt-4">
                    <label><span class="pc-label-text">Statut <span class="pc-required">*</span></span></label>
                    <select name="statut" required data-pc-select-search data-placeholder="Rechercher un statut...">
                        <option value="a_faire">À faire</option>
                        <option value="en_cours">En cours</option>
                        <option value="bloque">Bloqué</option>
                        <option value="termine">Terminé</option>
                        <option value="annule">Annulé</option>
                    </select>
                </div>
            </div>

            <div class="pc-form-footer">
                <div class="pc-form-required-note"><span class="pc-required">*</span> Champs obligatoires</div>
                <div class="pc-form-actions">
                    <a href="dashboard.php" class="pc-btn pc-btn-soft pc-btn-lg"><i class="fas fa-xmark"></i> Annuler</a>
                    <button class="pc-btn pc-btn-success pc-btn-lg" type="submit"><i class="fas fa-check"></i> Enregistrer la tâche</button>
                </div>
            </div>
        </form>
    </article>
</section>
<script>(()=>{const project=document.querySelector('[name="project_id"]'),parent=document.getElementById('parentTaskSelect');if(!project||!parent)return;const sync=()=>{const selected=project.value;for(const option of parent.options){if(!option.dataset.projectId)continue;option.hidden=option.dataset.projectId!==selected;option.disabled=option.hidden}if(parent.selectedOptions[0]?.disabled)parent.value=''};project.addEventListener('change',sync);sync()})();</script>
<?php pc_render_page_end(); ?>
