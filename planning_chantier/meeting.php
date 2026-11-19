<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';
if(class_exists('AccessManager')
    &&!AccessManager::hasPageAccess('planning_chantier/dashboard.php')
    &&!AccessManager::hasPageAccess('planning_chantier/reunion.php')){
    AccessManager::requirePageAccess('planning_chantier/dashboard.php');
}

$id = (int)($_GET['id'] ?? 0);
$repo = new PlanningMeetingRepository($pdo);
$meeting = $repo->findIncludingArchived($id);
if (!$meeting) {
    pc_flash_set('error', 'Réunion introuvable.');
    pc_redirect('reunion.php');
}
$items = $repo->items($id);
$participants = $repo->participants($id);
$actionPoints=$repo->actionPoints($id);
$performance=$repo->performanceForMeeting($id);
$isOfficeMeeting=(int)($meeting['office_service_line_id']??0)>0;
$groupedPoints=[];$groupOrder=[];
foreach($actionPoints as $point){
    $section=trim((string)($point['meeting_section_title']??''))?:'Point';
    $groupKey=($isOfficeMeeting?'office':(string)($point['chantier_id']??0)).'|'.mb_strtolower($section);
    if(!isset($groupedPoints[$groupKey]))$groupOrder[]=$groupKey;
    $groupedPoints[$groupKey][]=$point;
}
$actionPoints=[];
foreach($groupOrder as $groupKey)foreach($groupedPoints[$groupKey] as $point)$actionPoints[]=$point;
$sectionSpans=[];
for($i=0,$count=count($actionPoints);$i<$count;){
    $section=trim((string)($actionPoints[$i]['meeting_section_title']??''))?:'Point';
    $key=($isOfficeMeeting?'office':(string)($actionPoints[$i]['chantier_id']??0)).'|'.mb_strtolower($section);
    $end=$i+1;
    while($end<$count){
        $nextSection=trim((string)($actionPoints[$end]['meeting_section_title']??''))?:'Point';
        $nextKey=($isOfficeMeeting?'office':(string)($actionPoints[$end]['chantier_id']??0)).'|'.mb_strtolower($nextSection);
        if($nextKey!==$key)break;
        $end++;
    }
    $sectionSpans[$i]=['span'=>$end-$i,'label'=>$isOfficeMeeting?$section:'Point : '.$section];
    for($hidden=$i+1;$hidden<$end;$hidden++)$sectionSpans[$hidden]=null;
    $i=$end;
}

$pageTitle = $isOfficeMeeting?'Détail réunion de service':'Détail réunion chantier';
$activeMenu = 'planning_chantier';
if (!planning_chantier_include_layout('header')) { pc_render_fallback_header($pageTitle); }
planning_chantier_include_layout('navbar');
pc_render_page_start($isOfficeMeeting?'Point de réunion '.mb_strtolower((string)preg_replace('/^Service\s+/iu','',(string)$meeting['project_label'])):'Détail du point de réunion chantier', 'Consultation du compte rendu, des participants et des points décidés.', 'Compte rendu');
?>
<?php if(!empty($meeting['archived_at'])):?><div class="mt-6 rounded-2xl border border-slate-300 bg-slate-100 px-5 py-4 text-sm font-semibold text-slate-700"><i class="fas fa-lock mr-2"></i>Archive complète en lecture seule — aucune donnée de ce compte rendu ne peut être modifiée.</div><?php endif;?>
<section class="pc-reunion-workspace">
    <article class="pc-card-pro">
        <div class="pc-card-head">
            <div>
                <p class="pc-section-kicker"><?= pc_date((string)$meeting['meeting_date']) ?></p>
                <h2><?= pc_e((string)$meeting['titre']) ?></h2>
                <p><strong><?=pc_e((string)($meeting['report_code']??''))?></strong> · <?= pc_badge((string)($meeting['status'] ?? 'draft')) ?> <?=!empty($meeting['archived_at'])?'<span class="pc-badge-slate">Archivé</span>':''?></p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="exports/meeting_pdf.php?id=<?= (int)$meeting['id'] ?>" target="_blank" class="pc-btn pc-btn-primary"><i class="fas fa-file-pdf"></i> Export PDF</a>
                <?php if((int)($meeting['preparation_id']??0)>0):?>
                <a href="request/meeting_gantt_pdf.php?preparation_id=<?=(int)$meeting['preparation_id']?>&amp;all=1" target="_blank" class="pc-btn pc-btn-soft"><i class="fas fa-chart-gantt"></i> Gantt général <?=$isOfficeMeeting?'Bureau':'chantier'?></a>
                <?php endif;?>
                <?php if(empty($meeting['archived_at'])):?><form action="request/archive_meeting_record.php" method="post">
                    <input type="hidden" name="type" value="meeting"><input type="hidden" name="id" value="<?=(int)$meeting['id']?>">
                    <button class="pc-btn pc-btn-soft" data-pc-confirm="Archiver cette réunion ?"><i class="fas fa-box-archive"></i> Archiver</button>
                </form><?php endif;?>
            </div>
        </div>

        <div class="pc-table-wrap mt-4">
            <?php if($actionPoints):?>
            <h3 class="pc-subtitle mb-3">Organisation et planning décidés en réunion</h3>
            <table class="pc-table">
                <colgroup><col style="width:5%"><col style="width:18%"><col style="width:30%"><col style="width:11%"><col style="width:11%"><col style="width:13%"><col style="width:12%"></colgroup>
                <thead><tr><th>#</th><th>Section</th><th>Tâche et checklist</th><th>Pilote</th><th>Copilote</th><th>Échéance</th><th>Avancement</th></tr></thead>
                <tbody>
                <?php foreach($actionPoints as $i=>$point):?>
                    <tr>
                        <td><?=$i+1?></td>
                        <?php if($sectionSpans[$i]!==null):?><td rowspan="<?=(int)$sectionSpans[$i]['span']?>" class="align-middle"><strong class="block break-words text-[15px] font-extrabold leading-5 text-slate-950"><?=pc_e((string)$sectionSpans[$i]['label'])?></strong></td><?php endif;?>
                        <td><strong class="block break-words text-sm font-extrabold leading-5 text-slate-950"><?=nl2br(pc_e((string)($point['task_title']??'')))?></strong><?php if(!empty($point['checklist_text'])):?><div class="mt-2 space-y-1"><?php foreach(preg_split('/\R/',(string)$point['checklist_text'])?:[] as $checkItem):?><?php $isDone=str_starts_with(trim($checkItem),'[x]');$checkLabel=preg_replace('/^\s*\[(?:x| )\]\s*/i','',$checkItem);?><div class="flex items-start gap-2 break-words text-xs font-medium leading-4 <?=$isDone?'text-emerald-700':'text-amber-700'?>"><i class="fas <?=$isDone?'fa-circle-check':'fa-clock'?> mt-0.5 shrink-0" aria-hidden="true"></i><span><?=pc_e((string)$checkLabel)?></span></div><?php endforeach;?></div><?php endif;?></td>
                        <td><?=pc_e((string)($point['pilot_name']??''))?></td>
                        <td><?=pc_e((string)($point['copilot_name']??''))?></td>
                        <td><?=pc_date($point['new_deadline']??null,'-')?></td>
                        <td><strong><?=pc_number((float)($point['progress']??0),1)?>%</strong><?php if((int)($meeting['preparation_id']??0)>0):?><br><a href="request/meeting_gantt_pdf.php?preparation_id=<?=(int)$meeting['preparation_id']?>&amp;<?=!empty($point['custom_section_id'])?'section_id='.(int)$point['custom_section_id']:'point_id='.(int)$point['id']?>" target="_blank" class="text-xs font-semibold text-indigo-700"><i class="fas fa-file-pdf"></i> Gantt du point</a><?php endif;?></td>
                    </tr>
                <?php endforeach;?>
                </tbody>
            </table>
            <?php endif;?>
            <?php if($items):?>
            <h3 class="pc-subtitle mb-3 <?=$actionPoints?'mt-6':''?>">Autres points traités</h3>
            <table class="pc-table">
                <thead><tr><th>#</th><th>Sujet</th><th><?=$isOfficeMeeting?'Service':'Chantier'?></th><th>Constat</th><th>Décision</th><th>Échéance</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($items as $i => $item): ?>
                    <tr><td><?= $i+1 ?></td><td><strong><?= pc_e((string)$item['sujet']) ?></strong></td><td><?= pc_e((string)($item['project_label'] ?? '')) ?></td><td><?= nl2br(pc_e((string)($item['constat'] ?? ''))) ?></td><td><?= nl2br(pc_e((string)($item['decision_text'] ?? ''))) ?></td><td><?= pc_date($item['echeance'] ?? null, '-') ?></td><td><?= pc_badge((string)($item['statut'] ?? 'ouvert')) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif;?>
            <?php if(!$items&&!$actionPoints):?><div class="pc-pilot-note">Aucun point enregistré dans ce compte rendu.</div><?php endif;?>
        </div>
    </article>

    <aside class="pc-card-pro pc-meeting-side">
        <p class="pc-section-kicker">Participants</p>
        <h2>Liste</h2>
        <div class="pc-participant-pills">
            <?php foreach ($participants as $p): ?>
                <div class="pc-participant-pill"><strong><?= pc_e((string)$p['participant_name']) ?></strong><small><?= pc_e((string)($p['participant_email'] ?? '')) ?></small></div>
            <?php endforeach; ?>
            <?php if (!$participants): ?><div class="pc-empty-side">Aucun participant renseigné.</div><?php endif; ?>
        </div>
    </aside>
</section>
<section class="pc-card-pro mt-6">
    <div class="pc-form-panel pc-form-panel-flat">
        <h3 class="pc-subtitle">Résumé de réunion</h3>
        <p class="pc-text-block"><?= nl2br(pc_e((string)($meeting['resume'] ?? ''))) ?></p>
        <h3 class="pc-subtitle mt-4">Décisions générales</h3>
        <p class="pc-text-block"><?= nl2br(pc_e((string)($meeting['decisions'] ?? ''))) ?></p>
        <?php if($performance):?>
            <h3 class="pc-subtitle mt-6">Évaluation des participants</h3>
            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach($performance as $person):?>
                    <?php
                    $rating=max(0,min(5,round((float)($person['stars']??0)*2)/2));
                    $full=(int)floor($rating);
                    $half=($rating-$full)>=.5;
                    ?>
                    <div class="rounded-xl border border-slate-200 bg-white p-3">
                        <strong class="block text-sm text-slate-900"><?=pc_e((string)($person['participant_name']??'Participant'))?></strong>
                        <span class="mt-1 block text-amber-500" title="<?=pc_number($rating,1)?> sur 5"><?=str_repeat('★',$full)?><?=$half?'◐':''?><?=str_repeat('☆',5-$full-($half?1:0))?></span>
                        <small class="text-slate-500"><?=(int)($person['tasks_count']??0)>0?pc_number((float)($person['score']??0),1).'/100 · '.(int)$person['tasks_count'].' tâche(s)':'Non évalué'?></small>
                    </div>
                <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>
</section>
<?php pc_render_page_end(); ?>
