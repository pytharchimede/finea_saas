<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
try{
    if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/reunion.php');
    $pilotService=new PlanningFinancialPilotService($pdo);
    $active=$pilotService->projects();
    $allowed=array_fill_keys(array_map(static fn($p)=>(int)$p['id'],$active),true);
    $chantierIds=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['chantier_ids']??[])),static fn($id)=>isset($allowed[$id]))));

    $options=(new PlanningMeetingRepository($pdo))->participantOptions();
    $byId=[];
    foreach($options as $person)$byId[(int)$person['user_id']]=$person;
    $participants=[];
    foreach(array_unique(array_map('intval',(array)($_POST['participant_user_ids']??[]))) as $id){
        if(!isset($byId[$id]))continue;
        $participants[]=['user_id'=>$id,'name'=>$byId[$id]['name'],'email'=>$byId[$id]['email'],'attendance_status'=>'present','notify_email'=>1];
    }
    foreach(preg_split('/[\r\n,;]+/',(string)($_POST['participant_emails']??''))?:[] as $email){
        $email=trim($email);
        if(filter_var($email,FILTER_VALIDATE_EMAIL))$participants[]=['user_id'=>null,'name'=>$email,'email'=>$email,'attendance_status'=>'copie','notify_email'=>1];
    }
    $status=($_POST['status']??'draft')==='ready'?'ready':'draft';
    $editingId=(int)($_POST['id']??0);
    $meetingType=(string)($_POST['meeting_type']??'site');
    $officeChantierId=0;
    $officeLineId=(int)($_POST['office_ligne_devis_id']??0);
    if($meetingType==='office'){
        $officeChantierId=(int)$pdo->query("SELECT id FROM chantier_banamur WHERE planning_is_office_hub=1 AND archived_at IS NULL AND closed_date IS NULL LIMIT 1")->fetchColumn();
        if($officeChantierId<=0)throw new DomainException('Aucun chantier Bureau n’est configuré dans l’administration système.');
        $chantierIds=[$officeChantierId];
        if($status==='ready'&&$officeLineId<=0)throw new DomainException('Choisissez le service concerné par la réunion Bureau.');
        if($officeLineId>0){
            $pilotService->hierarchy($officeChantierId);
            $check=$pdo->prepare("SELECT COUNT(*) FROM planning_chantier_tasks t
                JOIN planning_chantier_projects p ON p.id=t.project_id
                WHERE p.chantier_id=:chantier AND t.source_type='ligne_devis'
                  AND t.source_id=:line AND t.statut<>'annule'");
            $check->execute(['chantier'=>$officeChantierId,'line'=>$officeLineId]);
            if(!(int)$check->fetchColumn())throw new DomainException('Le service ne correspond pas au chantier Bureau.');
        }
    }else{
        $officeChantierId=0;$officeLineId=0;
    }
    $preparationRepo=new PlanningMeetingPreparationRepository($pdo);
    $preparationRepo->assertNoConflict($chantierIds,$officeLineId?:null,$editingId);
    $id=$preparationRepo->save([
        'meeting_date'=>$_POST['meeting_date']??date('Y-m-d'),
        'titre'=>$_POST['titre']??'Réunion quotidienne de 12h',
        'agenda'=>$_POST['agenda']??'',
        'chantier_ids'=>$chantierIds,
        'participants'=>$participants,
        'office_chantier_id'=>$officeChantierId,
        'office_ligne_devis_id'=>$officeLineId,
        'status'=>$status,
        'created_by'=>planning_chantier_user_id(),
    ],$editingId?:null);
    if($status==='ready'){
        // Une nouvelle réunion repart toujours du dernier état connu : points,
        // checklists, délais et points libres du précédent compte rendu.
        $actionPoints=new PlanningMeetingActionPointRepository($pdo);
        $carryForward=new PlanningMeetingCustomSectionCarryForwardService($pdo);
        foreach($chantierIds as $chantierId){
            $pilotService->hierarchy((int)$chantierId);
            $carryForward->seed($id,(int)$chantierId,planning_chantier_user_id());
            $lines=$pdo->prepare("SELECT t.source_id FROM planning_chantier_tasks t
                JOIN planning_chantier_projects p ON p.id=t.project_id
                WHERE p.chantier_id=:chantier AND t.source_type='ligne_devis' AND t.statut<>'annule'");
            $lines->execute(['chantier'=>(int)$chantierId]);
            foreach($lines->fetchAll(PDO::FETCH_COLUMN)?:[] as $lineId){
                if($officeLineId>0&&(int)$lineId!==$officeLineId)continue;
                $actionPoints->seedOpenPointsFromPrevious($id,(int)$lineId,planning_chantier_user_id());
            }
        }
        pc_redirect('../reunion.php?preparation_id='.$id);
    }
    pc_flash_set('success','Préparation enregistrée en brouillon.');
    pc_redirect('../preparation_reunion.php?id='.$id);
}catch(Throwable $e){
    pc_flash_set('error',$e->getMessage());
    $type=(string)($_POST['meeting_type']??'');
    pc_redirect('../preparation_reunion.php'.(in_array($type,['site','office'],true)?'?type='.$type:''));
}
