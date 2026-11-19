<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/includes/ui.php';
if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/dashboard.php');

$pilotService=new PlanningFinancialPilotService($pdo);
$projects=$pilotService->projects();
foreach($projects as $project){
    if(!empty($project['planning_is_office_hub']))$pilotService->hierarchy((int)$project['id']);
}
$meetings=$pdo->query("SELECT m.id,m.preparation_id,m.meeting_date,m.titre,m.report_code,m.office_service_line_id,
        p.chantier_ids_json
    FROM planning_chantier_meetings m
    LEFT JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id
    WHERE m.status='closed' ORDER BY m.meeting_date DESC,m.id DESC")->fetchAll(PDO::FETCH_ASSOC)?:[];
$latestByProject=[];$latestByService=[];
foreach($meetings as $meeting){
    $serviceId=(int)($meeting['office_service_line_id']??0);
    if($serviceId>0){$latestByService[$serviceId]??=$meeting;continue;}
    foreach(array_map('intval',json_decode((string)($meeting['chantier_ids_json']??'[]'),true)?:[]) as $projectId)$latestByProject[$projectId]??=$meeting;
}
$officeProjectIds=array_fill_keys(array_map(static fn(array $p):int=>(int)$p['id'],array_filter($projects,static fn(array $p):bool=>!empty($p['planning_is_office_hub']))),true);
$sites=array_values(array_filter($projects,static fn(array $p):bool=>!isset($officeProjectIds[(int)$p['id']])));
$services=$pdo->query("SELECT p.chantier_id,t.source_id ligne_id,c.code,t.titre designation,
        t.date_debut_prevue planning_date_debut,t.date_fin_prevue planning_date_fin,
        IF(t.statut='termine',COALESCE(t.updated_at,t.created_at),NULL) planning_completed_at
    FROM planning_chantier_tasks t
    JOIN planning_chantier_projects p ON p.id=t.project_id
    JOIN chantier_banamur c ON c.id=p.chantier_id
    WHERE t.source_type='ligne_devis' AND t.statut<>'annule'
      AND c.planning_is_office_hub=1 AND c.archived_at IS NULL AND c.closed_date IS NULL
    ORDER BY t.titre")->fetchAll(PDO::FETCH_ASSOC)?:[];

$pageTitle='Plannings et derniers comptes rendus';$activeMenu='planning_chantier';
if(!planning_chantier_include_layout('header'))pc_render_fallback_header($pageTitle);
planning_chantier_include_layout('navbar');
pc_render_page_start('Plannings & derniers CR','Consultez à tout moment l’organisation actuelle de chaque chantier et de chaque service Bureau.','Consultation permanente');
?>
<section class="mt-8 grid gap-7 xl:grid-cols-2">
<?php foreach([['title'=>'Chantiers','icon'=>'fa-helmet-safety','rows'=>$sites,'office'=>false],['title'=>'Services Bureau','icon'=>'fa-building-user','rows'=>$services,'office'=>true]] as $group):?>
<article class="pc-card-pro">
    <div class="pc-card-head"><div><p class="pc-section-kicker">Organisation</p><h2><i class="fas <?=$group['icon']?> mr-2 text-indigo-700"></i><?=$group['title']?></h2></div><span class="pc-badge-slate"><?=count($group['rows'])?> élément(s)</span></div>
    <div class="mt-5 grid gap-3">
    <?php foreach($group['rows'] as $row):?>
        <?php $entityId=(int)($group['office']?$row['ligne_id']:$row['id']);$latest=$group['office']?($latestByService[$entityId]??null):($latestByProject[$entityId]??null);?>
        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3"><div><strong class="text-slate-950"><?=pc_e((string)($group['office']?$row['designation']:$row['titre']))?></strong><small class="mt-1 block text-slate-500"><?=pc_e((string)($row['code']??''))?><?=$latest?' · Dernier CR le '.pc_date((string)$latest['meeting_date']):' · Aucun CR antérieur'?></small></div>
            <div class="flex flex-wrap gap-2"><a class="pc-btn pc-btn-soft" href="planning_view.php?type=<?=$group['office']?'office':'site'?>&amp;id=<?=$entityId?>"><i class="fas fa-eye"></i> Planning complet</a>
            <?php if($latest):?><a class="pc-btn pc-btn-soft" href="meeting.php?id=<?=(int)$latest['id']?>"><i class="fas fa-clock-rotate-left"></i> Dernier CR</a><a class="pc-btn pc-btn-primary" target="_blank" href="request/meeting_gantt_pdf.php?preparation_id=<?=(int)$latest['preparation_id']?>&amp;all=1&amp;<?=$group['office']?'line_id='.$entityId:'chantier_id='.$entityId?>"><i class="fas fa-file-pdf"></i> Gantt</a><?php endif;?></div></div>
        </div>
    <?php endforeach;?>
    <?php if(!$group['rows']):?><div class="pc-pilot-note">Aucun élément actif.</div><?php endif;?>
    </div>
</article>
<?php endforeach;?>
</section>
<?php pc_render_page_end();?>
