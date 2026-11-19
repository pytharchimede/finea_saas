<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    $preparationId=(int)($input['preparation_id']??0);
    $preparation=(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId);
    if(!$preparation||!empty($preparation['office_chantier_id']))throw new DomainException('Réunion chantier invalide.');
    $action=(string)($input['action']??'create');
    if($action==='create'){
        $title=trim((string)($input['title']??''));
        if($title===''||mb_strlen($title)>255)throw new DomainException('Saisissez un titre valide.');
        $chantierId=(int)($input['chantier_id']??0);
        $lineId=(int)($input['ligne_devis_id']??0);
        $allowed=array_fill_keys(array_map('intval',$preparation['chantier_ids']??[]),true);
        if($chantierId>0&&!isset($allowed[$chantierId]))throw new DomainException('Chantier hors réunion.');
        if($lineId>0){
            $check=$pdo->prepare("SELECT p.chantier_id FROM planning_chantier_tasks t
                JOIN planning_chantier_projects p ON p.id=t.project_id
                WHERE t.source_type='ligne_devis' AND t.source_id=:line AND t.statut<>'annule' LIMIT 1");
            $check->execute(['line'=>$lineId]);$linked=(int)$check->fetchColumn();
            if(!isset($allowed[$linked]))throw new DomainException('Ligne hors réunion.');
            $chantierId=$linked;
        }
        $order=$pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM planning_chantier_meeting_custom_sections WHERE preparation_id=:preparation");
        $order->execute(['preparation'=>$preparationId]);
        $pdo->prepare("INSERT INTO planning_chantier_meeting_custom_sections
            (preparation_id,chantier_id,ligne_devis_id,title,payload_json,sort_order,created_by,created_at)
            VALUES(:preparation,:chantier,:line,:title,'[]',:sort,:user,NOW())")->execute([
                'preparation'=>$preparationId,'chantier'=>$chantierId?:null,'line'=>$lineId?:null,'title'=>$title,
                'sort'=>(int)$order->fetchColumn(),'user'=>planning_chantier_user_id(),
            ]);
        $createdId=(int)$pdo->lastInsertId();
    }elseif($action==='point_save'){
        $sectionId=(int)($input['section_id']??0);
        $title=trim((string)($input['title']??''));
        if($title===''||mb_strlen($title)>255)throw new DomainException('Saisissez un titre valide.');
        $pdo->prepare("UPDATE planning_chantier_meeting_custom_sections
            SET title=:title,responsible_user_id=:responsible,start_date=:start_date,end_date=:end_date,updated_by=:user,updated_at=NOW()
            WHERE id=:id AND preparation_id=:preparation AND archived_at IS NULL")->execute([
                'title'=>$title,'responsible'=>(int)($input['responsible_user_id']??0)?:null,
                'start_date'=>trim((string)($input['start_date']??''))?:null,'end_date'=>trim((string)($input['end_date']??''))?:null,
                'user'=>planning_chantier_user_id(),'id'=>$sectionId,'preparation'=>$preparationId,
            ]);
    }elseif($action==='archive'){
        $pdo->prepare("UPDATE planning_chantier_meeting_custom_sections SET archived_at=NOW(),archived_by=:user,updated_at=NOW()
            WHERE id=:id AND preparation_id=:preparation AND archived_at IS NULL")->execute([
                'user'=>planning_chantier_user_id(),'id'=>(int)($input['id']??0),'preparation'=>$preparationId,
            ]);
    }elseif(in_array($action,['task_save','task_archive','task_reorder','checklist_add','checklist_rename','checklist_toggle','checklist_progress','checklist_archive','checklist_reorder'],true)){
        $sectionId=(int)($input['section_id']??0);
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT payload_json FROM planning_chantier_meeting_custom_sections
            WHERE id=:id AND preparation_id=:preparation AND archived_at IS NULL FOR UPDATE");
        $lock->execute(['id'=>$sectionId,'preparation'=>$preparationId]);
        $payload=$lock->fetchColumn();
        if($payload===false)throw new DomainException('Point de réunion introuvable.');
        $tasks=json_decode((string)$payload,true);
        if(!is_array($tasks))$tasks=[];
        $taskId=trim((string)($input['task_id']??''));
        $taskIndex=null;
        foreach($tasks as $index=>$task)if((string)($task['id']??'')===$taskId)$taskIndex=$index;
        if($action==='task_reorder'){
            $order=is_array($input['task_ids']??null)?array_values(array_unique(array_map('strval',$input['task_ids']))):[];
            $known=array_map(static fn(array $task):string=>(string)($task['id']??''),$tasks);
            sort($known);$expected=$order;sort($expected);
            if(!$order||$known!==$expected)throw new DomainException('Ordre des points invalide.');
            $byId=[];foreach($tasks as $task)$byId[(string)$task['id']]=$task;
            $tasks=array_map(static fn(string $id):array=>$byId[$id],$order);
        }elseif($action==='task_save'){
            $title=trim((string)($input['title']??''));
            if($title==='')throw new DomainException('Saisissez la tâche.');
            if($taskId==='')$taskId=bin2hex(random_bytes(8));
            $taskChecklist=$taskIndex!==null&&is_array($tasks[$taskIndex]['checklist']??null)?$tasks[$taskIndex]['checklist']:[];
            if(array_key_exists('completed',$input)){
                $completed=!empty($input['completed']);
                foreach($taskChecklist as &$item)$item['completed']=$completed;
                unset($item);
            }
            $progressTotal=array_sum(array_map(static fn(array $item):float=>!empty($item['completed'])?100:(float)($item['progress']??0),$taskChecklist));
            $progress=$taskChecklist?round($progressTotal/count($taskChecklist),2):0;
            $task=[
                'id'=>$taskId,'title'=>$title,
                'pilot_user_id'=>(int)($input['pilot_user_id']??0),
                'copilot_user_id'=>(int)($input['copilot_user_id']??0),
                'old_deadline'=>(string)($input['old_deadline']??''),
                'new_deadline'=>(string)($input['new_deadline']??''),
                'progress'=>$progress,
                'checklist'=>$taskChecklist,
            ];
            if($taskIndex===null)$tasks[]=$task;else $tasks[$taskIndex]=$task;
        }elseif($taskIndex===null)throw new DomainException('Tâche introuvable.');
        elseif($action==='task_archive')array_splice($tasks,$taskIndex,1);
        else{
            $checklist=is_array($tasks[$taskIndex]['checklist']??null)?$tasks[$taskIndex]['checklist']:[];
            $itemId=trim((string)($input['item_id']??''));
            $itemIndex=null;
            foreach($checklist as $index=>$item)if((string)($item['id']??'')===$itemId)$itemIndex=$index;
            if($action==='checklist_reorder'){
                $order=is_array($input['checklist_ids']??null)?array_values(array_unique(array_map('strval',$input['checklist_ids']))):[];
                $known=array_map(static fn(array $item):string=>(string)($item['id']??''),$checklist);
                sort($known);$expected=$order;sort($expected);
                if(!$order||$known!==$expected)throw new DomainException('Ordre de checklist invalide.');
                $byId=[];foreach($checklist as $item)$byId[(string)$item['id']]=$item;
                $checklist=array_map(static fn(string $id):array=>$byId[$id],$order);
            }elseif($action==='checklist_add'){
                $label=trim((string)($input['label']??''));
                if($label==='')throw new DomainException('Saisissez la sous-tâche.');
                $checklist[]=['id'=>bin2hex(random_bytes(8)),'label'=>$label,'completed'=>false,'progress'=>0];
            }elseif($itemIndex===null)throw new DomainException('Sous-tâche introuvable.');
            elseif($action==='checklist_rename'){
                $label=trim((string)($input['label']??''));
                if($label===''||mb_strlen($label)>500)throw new DomainException('Saisissez une sous-tâche valide.');
                $checklist[$itemIndex]['label']=$label;
            }
            elseif($action==='checklist_toggle'){$checklist[$itemIndex]['completed']=!empty($input['completed']);$checklist[$itemIndex]['progress']=$checklist[$itemIndex]['completed']?100:0;}
            elseif($action==='checklist_progress'){$progress=(float)($input['progress']??-1);if($progress<0||$progress>100)throw new DomainException('Le pourcentage doit être compris entre 0 et 100.');$checklist[$itemIndex]['progress']=$progress;$checklist[$itemIndex]['completed']=$progress>=100;}
            else array_splice($checklist,$itemIndex,1);
            $tasks[$taskIndex]['checklist']=$checklist;
            if($checklist){
                $progressTotal=array_sum(array_map(static fn(array $item):float=>!empty($item['completed'])?100:(float)($item['progress']??0),$checklist));
                $tasks[$taskIndex]['progress']=round($progressTotal/count($checklist),2);
            }elseif(in_array($action,['checklist_toggle','checklist_archive'],true)){
                $tasks[$taskIndex]['progress']=0;
            }
        }
        $sectionTitle=$action==='task_save'?trim((string)($input['section_title']??'')):'';
        $pdo->prepare("UPDATE planning_chantier_meeting_custom_sections
            SET title=CASE WHEN :title_check <> '' THEN :title_value ELSE title END,payload_json=:payload,updated_by=:user,updated_at=NOW()
            WHERE id=:id")->execute(['title_check'=>$sectionTitle,'title_value'=>$sectionTitle,'payload'=>json_encode($tasks,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'user'=>planning_chantier_user_id(),'id'=>$sectionId]);
        $pdo->commit();
    }else throw new DomainException('Action inconnue.');
    echo json_encode(['ok'=>true,'id'=>$createdId??null,'tasks'=>$tasks??null],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
