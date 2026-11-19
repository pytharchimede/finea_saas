<?php
declare(strict_types=1);
$financialPilotProjects = $financialPilotProjects ?? [];
$financialPilotCsrf = $financialPilotCsrf ?? '';
$meetingPreparation = $meetingPreparation ?? null;
$assignmentPeople = $assignmentPeople ?? ($meetingPreparation['participants']??[]);
$isOfficeMeeting=!empty($meetingPreparation['office_ligne_devis_id']);
$officeServiceLabel='';
if($isOfficeMeeting&&isset($pdo)){
    $officeServiceStmt=$pdo->prepare("SELECT t.titre
        FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        WHERE t.source_type='ligne_devis' AND t.source_id=:line
          AND p.chantier_id=:chantier AND t.statut<>'annule' LIMIT 1");
    $officeServiceStmt->execute([
        'line'=>(int)$meetingPreparation['office_ligne_devis_id'],
        'chantier'=>(int)$meetingPreparation['office_chantier_id'],
    ]);
    $officeServiceLabel=trim((string)($officeServiceStmt->fetchColumn()?:''));
}
?>
<section class="pc-financial-pilot mt-8" data-financial-pilot data-office-meeting="<?=$isOfficeMeeting?'1':'0'?>" data-unified-render="1" data-csrf="<?=pc_e($financialPilotCsrf)?>" data-preparation-id="<?=(int)($meetingPreparation['id']??0)?>" data-participants="<?=pc_e(json_encode($meetingPreparation['participants']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>" data-assignees="<?=pc_e(json_encode($assignmentPeople,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>">
    <div class="pc-financial-toolbar">
        <div class="pc-pilot-heading">
            <p class="pc-section-kicker">Pilotage devis · déboursés · planning</p>
            <h2>Avancement financier et opérationnel</h2>
            <p><?=$isOfficeMeeting
                ?'Pilotez les catégories, les tâches et les décisions du service pendant la réunion.'
                :'Sélectionnez un chantier, puis cochez les réalisations. Les pourcentages sont pondérés par les montants.'?></p>
            <?php if($meetingPreparation):?>
                <div class="pc-agenda-in-toolbar">
                    <div>
                        <span>Ordre du jour validé</span>
                        <strong><?=pc_e((string)$meetingPreparation['titre'])?></strong>
                        <p><?=nl2br(pc_e((string)$meetingPreparation['agenda']))?></p>
                    </div>
                    <div class="pc-agenda-counts">
                        <strong><?=$isOfficeMeeting?'1':count($financialPilotProjects)?></strong><span><?=$isOfficeMeeting?'service':'chantier(s)'?></span>
                        <strong><?=count($meetingPreparation['participants']??[])?></strong><span>participant(s)</span>
                    </div>
                </div>
            <?php endif;?>
        </div>
        <div class="pc-pilot-project-select pc-office-toolbar-side">
            <div class="pc-office-service-banner">
                <span class="pc-office-service-icon"><i class="fas <?=$isOfficeMeeting?'fa-briefcase':'fa-helmet-safety'?>"></i></span>
                <span>
                    <small><?=$isOfficeMeeting?'Service en réunion':'Chantiers en réunion'?></small>
                    <strong><?=$isOfficeMeeting
                        ?pc_e($officeServiceLabel?:'Service non renseigné')
                        :pc_number(count($financialPilotProjects)).' chantier(s) concerné(s)'?></strong>
                </span>
            </div>
            <select data-financial-project hidden aria-hidden="true">
                <option value=""></option>
                <?php foreach($financialPilotProjects as $project):?>
                    <option value="<?=(int)$project['id']?>"><?=pc_e(trim((string)(($project['code']??'').' · '.($project['titre']??'')),' ·'))?></option>
                <?php endforeach;?>
            </select>
            <?php if(!$isOfficeMeeting):?>
                <span class="pc-site-project-prompt"><i class="fas fa-hand-pointer"></i> Cliquez sur un chantier pour charger ses points</span>
            <?php endif;?>
            <div class="pc-pilot-project-badges" <?=$isOfficeMeeting?'hidden':''?>>
                <?php foreach($financialPilotProjects as $project):?>
                    <button type="button" data-financial-project-badge="<?=(int)$project['id']?>" title="<?=pc_e((string)($project['titre']??''))?>">
                        <i class="fas fa-building"></i>
                        <span><?=pc_e(
                            (string)(($project['code']??'') ?: 'CH-'.$project['id'])
                            . ' - '
                            . (string)(($project['titre']??'') ?: 'Chantier sans titre')
                        )?></span>
                    </button>
                <?php endforeach;?>
            </div>
            <div class="pc-office-toolbar-actions">
                <?php if(!$isOfficeMeeting):?>
                    <button type="button" class="pc-presentation-button" data-add-free-meeting-section>
                        <i class="fas fa-plus"></i><span>Point libre</span>
                    </button>
                    <button type="button" class="pc-presentation-button" data-bulk-selection-toggle>
                        <i class="fas fa-list-check"></i><span>Sélection multiple</span>
                    </button>
                <?php endif;?>
                <button type="button" class="pc-presentation-button" data-office-presentation>
                    <i class="fas fa-expand"></i><span>Mode présentation</span>
                </button>
            </div>
        </div>
        <div class="pc-office-performance-banner" data-office-performance-banner hidden></div>
    </div>
    <?php if(!$isOfficeMeeting):?>
        <div class="pc-bulk-selection-bar" data-bulk-selection-bar hidden>
            <strong><span data-bulk-selection-count>0</span> élément(s) sélectionné(s)</strong>
            <span>Les lignes et déboursés seront exclus des prochaines réunions. Les checklists seront retirées de cette réunion.</span>
            <button type="button" data-bulk-selection-apply disabled><i class="fas fa-eye-slash"></i> Retirer la sélection</button>
            <button type="button" data-bulk-selection-cancel><i class="fas fa-xmark"></i> Annuler</button>
        </div>
    <?php endif;?>
    <div class="pc-financial-summary" data-financial-summary hidden></div>
    <div class="pc-financial-layout">
        <aside class="pc-financial-lines" data-financial-lines>
            <div class="pc-pilot-empty"><i class="fas fa-hand-pointer"></i><strong>Choisissez un chantier</strong><span>Ses lignes de devis apparaîtront ici.</span></div>
        </aside>
        <article class="pc-financial-detail" data-financial-detail>
            <div class="pc-pilot-empty"><i class="fas fa-layer-group"></i><strong>Sélectionnez une ligne de devis</strong><span>Vous verrez ses déboursés, sous-lignes et son planning.</span></div>
        </article>
    </div>
    <div class="pc-pilot-toast" data-financial-toast hidden></div>
</section>
