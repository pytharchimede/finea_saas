<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/includes/ui.php';
if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/reunion.php');

$pilotService=new PlanningFinancialPilotService($pdo);
$pilotProjects=$pilotService->projects();
$participants=(new PlanningMeetingRepository($pdo))->participantOptions();
foreach($pilotProjects as $pilotProject){
    if(!empty($pilotProject['planning_is_office_hub']))$pilotService->hierarchy((int)$pilotProject['id']);
}
$officeServices=$pdo->query("SELECT p.chantier_id,t.source_id ligne_id,c.code,t.titre designation
    FROM planning_chantier_tasks t
    JOIN planning_chantier_projects p ON p.id=t.project_id
    JOIN chantier_banamur c ON c.id=p.chantier_id
    WHERE t.source_type='ligne_devis' AND t.statut<>'annule'
      AND p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
      AND c.planning_is_office_hub=1
    ORDER BY c.titre,t.ordre,t.id")->fetchAll(PDO::FETCH_ASSOC)?:[];
$officeChantierIds=array_fill_keys(array_map(static fn(array $row):int=>(int)$row['chantier_id'],$officeServices),true);
$pilotProjects=array_values(array_filter($pilotProjects,static fn(array $project):bool=>!isset($officeChantierIds[(int)$project['id']])));
$editing=(new PlanningMeetingPreparationRepository($pdo))->findEditable((int)($_GET['id']??0));
$meetingType=$editing?(!empty($editing['office_chantier_id'])?'office':'site'):(string)($_GET['type']??'');
$defaultMeetingTitle=$meetingType==='office'?'Réunion hebdomadaire':'Réunion quotidienne de 12h';
$selectedChantiers=array_map('intval',$editing['chantier_ids']??[]);
$selectedUsers=[];
$freeEmails=[];
foreach($editing['participants']??[] as $person){
    if(!empty($person['user_id']))$selectedUsers[]=(int)$person['user_id'];
    elseif(!empty($person['email']))$freeEmails[]=(string)$person['email'];
}
$pageTitle='Préparation de la réunion de 12h';
$activeMenu='planning_chantier';
if(!planning_chantier_include_layout('header'))pc_render_fallback_header($pageTitle);
planning_chantier_include_layout('navbar');
pc_render_page_start('Préparer la réunion de 12h','Définissez l’ordre du jour, les chantiers concernés et les destinataires avant d’ouvrir la salle.','Préparation obligatoire');
?>
<?php if(!in_array($meetingType,['office','site'],true)):?>
<section class="mx-auto mt-10 max-w-5xl">
    <div class="text-center"><p class="pc-section-kicker">Première étape</p><h2 class="mt-2 text-3xl font-semibold text-slate-950">Quel type de réunion préparez-vous ?</h2></div>
    <div class="mt-8 grid gap-6 md:grid-cols-2">
        <a href="preparation_reunion.php?type=site" class="pc-meeting-type-card">
            <i class="fas fa-helmet-safety"></i><strong>Réunion chantier</strong>
            <span>Sélectionner un ou plusieurs chantiers actifs et préparer l’ordre du jour classique.</span>
        </a>
        <a href="preparation_reunion.php?type=office" class="pc-meeting-type-card">
            <i class="fas fa-building-user"></i><strong>Réunion Bureau</strong>
            <span>Choisir directement le service concerné, puis les participants de toute l’entreprise.</span>
        </a>
    </div>
</section>
<?php pc_render_page_end();exit;endif;?>
<form action="request/save_meeting_preparation.php" method="post" class="mt-8 grid gap-6 xl:grid-cols-[1fr_.8fr]">
    <input type="hidden" name="id" value="<?=(int)($editing['id']??0)?>">
    <input type="hidden" name="meeting_type" value="<?=pc_e($meetingType)?>">
    <section class="pc-card-pro">
        <p class="pc-section-kicker">Cadre de la réunion</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">Ordre du jour</h2>
        <div class="pc-form-grid-2 mt-5">
            <div class="pc-field"><label>Date</label><input type="date" name="meeting_date" value="<?=pc_e((string)($editing['meeting_date']??date('Y-m-d')))?>" required></div>
            <div class="pc-field"><label>Titre</label><input name="titre" value="<?=pc_e((string)($editing['titre']??$defaultMeetingTitle))?>" required></div>
        </div>
        <div class="pc-field mt-4">
            <label>Ordre du jour détaillé</label>
            <textarea name="agenda" class="pc-textarea-lg" required placeholder="Points à examiner, objectifs, arbitrages attendus et informations à préparer…"><?=pc_e((string)($editing['agenda']??''))?></textarea>
        </div>

        <?php if($meetingType==='site'):?>
            <div class="mt-6 mb-3 flex items-center justify-between gap-3">
                <div><p class="pc-section-kicker">Périmètre chantier</p><h3 class="pc-subtitle">Chantiers concernés</h3></div>
                <button type="button" class="pc-btn pc-btn-soft" data-select-all-meeting-projects aria-pressed="false"><i class="fas fa-check-double"></i><span>Tout sélectionner</span></button>
            </div>
            <div class="pc-preparation-project-grid">
                <?php foreach($pilotProjects as $project):?>
                    <label class="pc-preparation-project-card">
                        <input type="checkbox" name="chantier_ids[]" value="<?=(int)$project['id']?>" <?=in_array((int)$project['id'],$selectedChantiers,true)?'checked':''?>>
                        <span><i class="fas fa-building"></i><strong><?=pc_e((string)$project['code'].' - '.(string)$project['titre'])?></strong></span>
                    </label>
                <?php endforeach;?>
            </div>
        <?php else:?>
            <div class="mt-6">
                <p class="pc-section-kicker">Périmètre Bureau</p>
                <h3 class="pc-subtitle">Service concerné</h3>
                <p class="mt-1 mb-3 text-sm text-slate-500">Cliquez sur le service dont les points seront traités pendant la réunion.</p>
                <div class="pc-preparation-project-grid pc-office-service-grid">
                <?php foreach($officeServices as $service):?>
                    <label class="pc-preparation-project-card pc-office-service-card">
                        <input type="radio" name="office_ligne_devis_id" value="<?=(int)$service['ligne_id']?>" required <?=((int)($editing['office_ligne_devis_id']??0)===(int)$service['ligne_id'])?'checked':''?>>
                        <span><i class="fas fa-briefcase"></i><strong><?=pc_e((string)$service['designation'])?></strong><i class="fas fa-circle-check pc-service-check"></i></span>
                    </label>
                <?php endforeach;?>
                </div>
                <small class="mt-3 block text-slate-500">Le chantier Bureau est défini dans l’administration système. Les participants peuvent venir de n’importe quel service.</small>
                <?php if(!$officeServices):?><div class="pc-data-warning mt-3">Aucun chantier Bureau configuré. Contactez un administrateur système.</div><?php endif;?>
            </div>
        <?php endif;?>
    </section>

    <aside class="pc-card-pro">
        <p class="pc-section-kicker">Diffusion du compte rendu</p>
        <h2 class="mt-1 text-2xl font-semibold text-slate-950">Participants et destinataires</h2>
        <p class="mt-2 text-sm text-slate-500">L’éditeur recevra le mail principal. Les personnes choisies seront mises en copie.</p>
        <div class="pc-preparation-people mt-5">
            <?php foreach($participants as $person):?>
                <label>
                    <input type="checkbox" name="participant_user_ids[]" value="<?=(int)$person['user_id']?>" <?=in_array((int)$person['user_id'],$selectedUsers,true)?'checked':''?>>
                    <span><strong><?=pc_e((string)$person['name'])?></strong><small><?=pc_e((string)$person['email'])?></small></span>
                </label>
            <?php endforeach;?>
        </div>
        <div class="pc-field mt-5">
            <label>Autres participants ou adresses en copie</label>
            <textarea name="participant_emails" class="pc-textarea-sm" placeholder="Une adresse e-mail par ligne"><?=pc_e(implode("\n",$freeEmails))?></textarea>
        </div>
        <div class="mt-6 flex flex-wrap justify-end gap-3">
            <button name="status" value="draft" formnovalidate class="pc-btn pc-btn-soft"><i class="fas fa-save"></i> Enregistrer le brouillon</button>
            <button name="status" value="ready" class="pc-btn pc-btn-primary"><i class="fas fa-check-circle"></i> Terminer et ouvrir la salle</button>
        </div>
    </aside>
</form>
<?php pc_render_page_end(); ?>
