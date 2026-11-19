<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/includes/ui.php';
if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/dashboard.php');
$type=(string)($_GET['type']??'site');$id=(int)($_GET['id']??0);
if(!in_array($type,['site','office'],true)||$id<=0)throw new DomainException('Planning invalide.');
$pilot=new PlanningFinancialPilotService($pdo);
if($type==='office'){
    $q=$pdo->prepare("SELECT p.chantier_id
        FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        JOIN chantier_banamur c ON c.id=p.chantier_id
        WHERE t.source_type='ligne_devis' AND t.source_id=:id
          AND t.statut<>'annule' AND c.planning_is_office_hub=1 LIMIT 1");
    $q->execute(['id'=>$id]);$projectId=(int)$q->fetchColumn();
}else $projectId=$id;
$data=$pilot->hierarchy($projectId);
if($type==='office')$data['lines']=array_values(array_filter($data['lines'],static fn(array $line):bool=>(int)$line['id']===$id));
$q=$pdo->query("SELECT m.*,p.chantier_ids_json FROM planning_chantier_meetings m LEFT JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id WHERE m.status='closed' ORDER BY m.meeting_date DESC,m.id DESC");
$latest=null;
foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $meeting){
    if($type==='office'&&(int)($meeting['office_service_line_id']??0)===$id){$latest=$meeting;break;}
    if($type==='site'&&empty($meeting['office_service_line_id'])&&in_array($projectId,array_map('intval',json_decode((string)($meeting['chantier_ids_json']??'[]'),true)?:[]),true)){$latest=$meeting;break;}
}
$points=$latest?(new PlanningMeetingRepository($pdo))->actionPoints((int)$latest['id']):[];
$points=array_values(array_filter($points,static fn(array $point):bool=>$type==='office'
    ?(int)($point['ligne_devis_id']??0)===$id
    :(int)($point['chantier_id']??0)===$projectId));
$title=$type==='office'?(string)($data['lines'][0]['designation']??'Service Bureau'):(string)$data['project']['titre'];
$pageTitle='Planning '.$title;$activeMenu='planning_chantier';if(!planning_chantier_include_layout('header'))pc_render_fallback_header($pageTitle);planning_chantier_include_layout('navbar');
pc_render_page_start($title,'Planning autonome consolidé avec le dernier compte rendu.',$type==='office'?'Service Bureau':'Chantier');
?>
<section class="mt-7 pc-card-pro"><div class="pc-card-head"><div><p class="pc-section-kicker">Planning actuel</p><h2>Organisation complète</h2></div><div class="flex flex-wrap gap-2"><a class="pc-btn pc-btn-soft" href="plannings.php"><i class="fas fa-arrow-left"></i> Tous les plannings</a><?php if($latest):?><a class="pc-btn pc-btn-soft" href="meeting.php?id=<?=(int)$latest['id']?>"><i class="fas fa-file-lines"></i> Dernier CR</a><a class="pc-btn pc-btn-primary" target="_blank" href="request/meeting_gantt_pdf.php?preparation_id=<?=(int)$latest['preparation_id']?>&amp;all=1&amp;<?=$type==='office'?'line_id='.$id:'chantier_id='.$projectId?>"><i class="fas fa-chart-gantt"></i> Exporter le Gantt</a><?php endif;?></div></div>
<?php foreach($data['lines'] as $line):?><div class="mt-5 rounded-2xl border border-slate-200 p-4"><div class="flex justify-between gap-3"><strong><?=pc_e((string)$line['designation'])?></strong><span><?=pc_number((float)$line['progress'],1)?>%</span></div><small class="text-slate-500"><?=pc_date($line['planning_date_debut']??null,'Début non défini')?> → <?=pc_date($line['planning_date_fin']??null,'Fin non définie')?></small>
<div class="mt-3 grid gap-3 md:grid-cols-2"><?php foreach($line['debourses'] as $debourse):?><div class="rounded-xl bg-slate-50 p-3"><strong class="text-sm"><?=pc_e((string)$debourse['title'])?></strong><small class="mt-1 block text-slate-500"><?=pc_number((float)$debourse['progress'],1)?>% · <?=count($debourse['details'])?> tâche(s)</small><?php foreach($debourse['details'] as $detail):?><div class="mt-2 border-t border-slate-200 pt-2 text-xs"><span><?=pc_e((string)$detail['designation'])?></span><span class="float-right"><?=pc_date($detail['date_debut']??null,'—')?> → <?=pc_date($detail['date_fin']??null,'—')?></span></div><?php endforeach;?></div><?php endforeach;?></div></div><?php endforeach;?>
<?php if(!$data['lines']):?><div class="pc-pilot-note mt-5">Aucune ligne de planning disponible.</div><?php endif;?></section>
<section class="mt-7 pc-card-pro"><p class="pc-section-kicker">Dernier compte rendu</p><h2 class="mt-1 text-2xl font-semibold"><?=$latest?pc_e((string)$latest['titre']).' · '.pc_date((string)$latest['meeting_date']):'Aucune réunion clôturée'?></h2><div class="mt-4 grid gap-3"><?php foreach($points as $point):?><div class="rounded-xl border border-slate-200 p-3"><strong><?=pc_e((string)$point['task_title'])?></strong><small class="mt-1 block text-slate-500"><?=pc_e((string)$point['meeting_section_title'])?> · <?=pc_number((float)$point['progress'],1)?>% · échéance <?=pc_date($point['new_deadline']??null,'—')?></small></div><?php endforeach;?><?php if(!$points):?><div class="pc-pilot-note">Aucun point opérationnel dans le dernier CR.</div><?php endif;?></div></section>
<?php pc_render_page_end();?>
