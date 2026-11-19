<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/includes/ui.php';
if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/reunion.php');

$preparationId=(int)($_GET['preparation_id']??0);
$preparationRepo=new PlanningMeetingPreparationRepository($pdo);
$meetingPreparation=$preparationId>0?$preparationRepo->findReady($preparationId):null;
$meetingConflict='';
if($meetingPreparation){
    try{
        $preparationRepo->assertNoConflict(
            array_map('intval',$meetingPreparation['chantier_ids']??[]),
            !empty($meetingPreparation['office_ligne_devis_id'])?(int)$meetingPreparation['office_ligne_devis_id']:null,
            $preparationId
        );
    }catch(Throwable $e){$meetingConflict=$e->getMessage();$meetingPreparation=null;}
}

$roomTitle=$meetingPreparation?(string)($meetingPreparation['titre']??'Réunion quotidienne de 12h'):'Salle de réunion fermée';
$pageTitle=$roomTitle;
$activeMenu='planning_chantier';
if(!planning_chantier_include_layout('header'))pc_render_fallback_header($pageTitle);
planning_chantier_include_layout('navbar');

if(!$meetingPreparation){
    pc_render_page_start('Salle de réunion fermée','Une réunion exige un ordre du jour, des chantiers concernés et des participants avant son ouverture.','Préparation requise');
    ?>
    <section class="mx-auto mt-10 max-w-3xl rounded-[32px] border border-amber-200 bg-white p-10 text-center shadow-xl">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-amber-100 text-3xl text-amber-700"><i class="fas fa-door-closed"></i></span>
        <h2 class="mt-6 text-3xl font-semibold text-slate-950"><?=$meetingConflict?'Réunion déjà ouverte':'Aucun ordre du jour validé'?></h2>
        <p class="mx-auto mt-3 max-w-xl leading-7 text-slate-600"><?=pc_e($meetingConflict?:'La salle ne peut pas être ouverte directement. Préparez la réunion, choisissez les chantiers et indiquez les participants qui recevront le compte rendu.')?></p>
        <a href="<?=$meetingConflict?'dashboard.php':'preparation_reunion.php'?>" class="pc-btn pc-btn-primary mt-7"><i class="fas fa-clipboard-list"></i> <?=$meetingConflict?'Voir les brouillons':'Préparer la réunion'?></a>
    </section>
    <?php pc_render_page_end(); exit;
}

try {
    (new PlanningMeetingStartNotificationService($pdo))->notifyOnce($meetingPreparation);
    $meetingRepo=new PlanningMeetingRepository($pdo);
    $assignmentPeople=$meetingRepo->participantOptions();
    $activeMeetingId=$meetingRepo->ensureDraftForPreparation($meetingPreparation,planning_chantier_user_id());
    $activeMeeting=$meetingRepo->find($activeMeetingId)??[];

    $financialPilot=new PlanningFinancialPilotService($pdo);
    $allowedIds=array_fill_keys(array_map('intval',$meetingPreparation['chantier_ids']??[]),true);
    $financialPilotProjects=array_values(array_filter(
        $financialPilot->projects(),
        static fn(array $project):bool=>isset($allowedIds[(int)$project['id']])
    ));
    $_SESSION['pc_financial_csrf']??=bin2hex(random_bytes(24));
    $financialPilotCsrf=(string)$_SESSION['pc_financial_csrf'];
} catch (Throwable $e) {
    $errorReference='PC-MEETING-'.date('Ymd-His').'-'.$preparationId;
    error_log(sprintf(
        '[%s] %s in %s:%d',
        $errorReference,
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    pc_render_page_start(
        'Impossible d’ouvrir la salle',
        'Une erreur technique a interrompu l’initialisation de cette réunion.',
        'Réunion indisponible'
    );
    ?>
    <section class="mx-auto mt-10 max-w-3xl rounded-[32px] border border-red-200 bg-white p-10 text-center shadow-xl">
        <span class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-red-100 text-3xl text-red-700"><i class="fas fa-triangle-exclamation"></i></span>
        <h2 class="mt-6 text-3xl font-semibold text-slate-950">La salle n’a pas pu être initialisée</h2>
        <p class="mx-auto mt-3 max-w-xl leading-7 text-slate-600">Aucune donnée de réunion n’a été supprimée. Communiquez la référence ci-dessous à l’administrateur pour retrouver l’erreur dans les journaux du serveur.</p>
        <code class="mt-5 inline-flex rounded-xl bg-slate-100 px-4 py-2 font-mono text-sm font-bold text-slate-800"><?=pc_e($errorReference)?></code>
        <div class="mt-7 flex flex-wrap justify-center gap-3">
            <a href="reunion.php?preparation_id=<?=$preparationId?>" class="pc-btn pc-btn-primary"><i class="fas fa-rotate-right"></i> Réessayer</a>
            <a href="dashboard.php" class="pc-btn pc-btn-soft"><i class="fas fa-arrow-left"></i> Retour au dashboard</a>
        </div>
    </section>
    <?php
    pc_render_page_end();
    exit;
}

pc_render_page_start($roomTitle,'Pilotez les catégories, sous-catégories et points de suivi puis clôturez pour diffuser le compte rendu.','Salle de pilotage');
require __DIR__.'/includes/financial_pilot.php';
?>

<form action="request/save_meeting.php" method="post" class="pc-meeting-finish" data-meeting-autosave data-csrf="<?=pc_e($financialPilotCsrf)?>">
    <input type="hidden" name="preparation_id" value="<?=$preparationId?>">
    <input type="hidden" name="close_meeting" value="1">
    <input type="hidden" name="meeting_date" value="<?=pc_e((string)$meetingPreparation['meeting_date'])?>">
    <input type="hidden" name="titre" value="<?=pc_e((string)$meetingPreparation['titre'])?>">
    <div>
        <p class="pc-section-kicker">Fin de séance</p>
        <h2>Terminer la réunion et diffuser le compte rendu</h2>
        <p>Le PDF FINEA sera envoyé à l’éditeur, avec les <?=count($meetingPreparation['participants']??[])?> participant(s) en copie.</p>
    </div>
    <div class="pc-meeting-finish-fields">
        <label>Résumé final<textarea name="resume" required placeholder="Synthèse des constats, avancées et difficultés…"><?=pc_e((string)($activeMeeting['resume']??''))?></textarea></label>
        <label>Décisions générales<textarea name="decisions" required placeholder="Décisions prises, responsables et prochaines actions…"><?=pc_e((string)($activeMeeting['decisions']??''))?></textarea></label>
    </div>
    <button class="pc-btn pc-btn-primary pc-btn-lg" data-pc-confirm="Clôturer définitivement cette réunion et envoyer le compte rendu ?">
        <i class="fas fa-paper-plane"></i> Terminer et envoyer le PDF
    </button>
</form>

<?php pc_render_page_end(); ?>
