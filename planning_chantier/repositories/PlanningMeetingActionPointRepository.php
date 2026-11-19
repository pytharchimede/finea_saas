<?php
declare(strict_types=1);

final class PlanningMeetingActionPointRepository
{
    public function __construct(private PDO $pdo) {}

    public function grouped(int $preparationId,int $chantierId):array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_meeting_action_points
            WHERE preparation_id=:preparation AND chantier_id=:chantier AND archived_at IS NULL
            ORDER BY debourse_id,sort_order,id");
        $stmt->execute(['preparation'=>$preparationId,'chantier'=>$chantierId]);
        $groups=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $row['checklist']=[];
            $groups[(int)$row['debourse_id']][(int)($row['ligne_debourse_id']??0)][]=$row;
        }
        $checklist=$this->pdo->prepare("SELECT cl.*
            FROM planning_chantier_meeting_action_point_checklist cl
            JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
            WHERE ap.preparation_id=:preparation AND ap.chantier_id=:chantier
              AND ap.archived_at IS NULL AND cl.archived_at IS NULL
            ORDER BY cl.sort_order,cl.id");
        $checklist->execute(['preparation'=>$preparationId,'chantier'=>$chantierId]);
        $byPoint=[];
        foreach($checklist->fetchAll(PDO::FETCH_ASSOC)?:[] as $item)$byPoint[(int)$item['action_point_id']][]=$item;
        foreach($groups as &$details)foreach($details as &$points)foreach($points as &$point)$point['checklist']=$byPoint[(int)$point['id']]??[];
        unset($details,$points,$point);
        return $groups;
    }

    public function performance(int $preparationId,int $lineId):array
    {
        $stmt=$this->pdo->prepare("SELECT user_id,
                MAX(participant_name) participant_name,COUNT(*) tasks_count,
                ROUND(AVG(CASE
                    WHEN progress>=100 THEN 100
                    WHEN new_deadline IS NOT NULL AND new_deadline<CURRENT_DATE() THEN progress*0.60
                    ELSE progress END),1) score
            FROM (
                SELECT pilot_user_id user_id,pilot_name participant_name,progress,new_deadline
                FROM planning_chantier_meeting_action_points
                WHERE preparation_id=:prep1 AND ligne_devis_id=:line1 AND pilot_user_id IS NOT NULL AND status<>'cancelled' AND archived_at IS NULL
                UNION ALL
                SELECT copilot_user_id user_id,copilot_name participant_name,progress,new_deadline
                FROM planning_chantier_meeting_action_points
                WHERE preparation_id=:prep2 AND ligne_devis_id=:line2 AND copilot_user_id IS NOT NULL AND status<>'cancelled' AND archived_at IS NULL
            ) assignments
            GROUP BY user_id ORDER BY score DESC,participant_name");
        $stmt->execute(['prep1'=>$preparationId,'line1'=>$lineId,'prep2'=>$preparationId,'line2'=>$lineId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)$row['stars']=round(((float)$row['score'])/20,1);
        unset($row);
        return $rows;
    }

    public function seedOpenPointsFromPrevious(int $preparationId,int $lineId,?int $userId):void
    {
        $this->pdo->beginTransaction();
        try{
        $reserve=$this->pdo->prepare("INSERT IGNORE INTO planning_chantier_meeting_action_point_seeds
            (preparation_id,ligne_devis_id,source_preparation_id,seeded_by,seeded_at)
            VALUES(:preparation,:line,NULL,:user,NOW())");
        $reserve->execute(['preparation'=>$preparationId,'line'=>$lineId,'user'=>$userId]);
        if($reserve->rowCount()===0){$this->pdo->commit();return;}
        $count=$this->pdo->prepare("SELECT COUNT(*) FROM planning_chantier_meeting_action_points
            WHERE preparation_id=:preparation AND ligne_devis_id=:line");
        $count->execute(['preparation'=>$preparationId,'line'=>$lineId]);
        if((int)$count->fetchColumn()>0){$this->pdo->commit();return;}
        $previous=$this->pdo->prepare("SELECT history.preparation_id
            FROM (
                SELECT preparation_id,ligne_devis_id FROM planning_chantier_meeting_action_points
                UNION
                SELECT preparation_id,ligne_devis_id FROM planning_chantier_meeting_action_point_seeds
            ) history
            JOIN planning_chantier_meeting_preparations old_p ON old_p.id=history.preparation_id
            JOIN planning_chantier_meeting_preparations current_p ON current_p.id=:preparation
            WHERE history.ligne_devis_id=:line AND history.preparation_id<>:current AND old_p.status='used'
              AND (old_p.meeting_date<current_p.meeting_date
                   OR (old_p.meeting_date=current_p.meeting_date AND old_p.id<current_p.id))
            ORDER BY old_p.meeting_date DESC,history.preparation_id DESC LIMIT 1");
        $previous->execute(['line'=>$lineId,'preparation'=>$preparationId,'current'=>$preparationId]);
        $previousId=(int)$previous->fetchColumn();
        if($previousId<=0){$this->pdo->commit();return;}
        $copy=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_points
            (preparation_id,chantier_id,ligne_devis_id,debourse_id,ligne_debourse_id,section_title,task_title,
             pilot_user_id,pilot_name,copilot_user_id,copilot_name,old_deadline,new_deadline,progress,status,
             sort_order,created_by,created_at)
            SELECT :current,ap.chantier_id,ap.ligne_devis_id,ap.debourse_id,ap.ligne_debourse_id,ap.section_title,ap.task_title,
                   ap.pilot_user_id,ap.pilot_name,ap.copilot_user_id,ap.copilot_name,ap.old_deadline,ap.new_deadline,
                   ap.progress,ap.status,ap.sort_order,:user,NOW()
            FROM planning_chantier_meeting_action_points ap
            WHERE ap.preparation_id=:previous AND ap.ligne_devis_id=:line AND ap.status<>'cancelled'
              AND ap.archived_at IS NULL");
        $copy->execute(['current'=>$preparationId,'user'=>$userId,'previous'=>$previousId,'line'=>$lineId]);
        $oldIds=$this->pdo->prepare("SELECT id FROM planning_chantier_meeting_action_points WHERE preparation_id=:preparation AND ligne_devis_id=:line AND status<>'cancelled' AND archived_at IS NULL ORDER BY id");
        $oldIds->execute(['preparation'=>$previousId,'line'=>$lineId]);
        $newIds=$this->pdo->prepare("SELECT id FROM planning_chantier_meeting_action_points WHERE preparation_id=:preparation AND ligne_devis_id=:line AND archived_at IS NULL ORDER BY id");
        $newIds->execute(['preparation'=>$preparationId,'line'=>$lineId]);
        $old=$oldIds->fetchAll(PDO::FETCH_COLUMN)?:[];
        $new=$newIds->fetchAll(PDO::FETCH_COLUMN)?:[];
        $copyChecklist=$this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_point_checklist
            (action_point_id,label,completed_at,completed_by,progress_percent,sort_order,created_by,created_at)
            SELECT :new,label,completed_at,completed_by,progress_percent,sort_order,:user,NOW()
            FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:old AND archived_at IS NULL ORDER BY sort_order,id");
        $copyWork=$this->pdo->prepare("INSERT INTO planning_chantier_work_progress(action_point_id,unit_label,planned_quantity,executed_quantity,client_billed_amount,executed_billed_amount,observation,updated_by,updated_at) SELECT :new,unit_label,planned_quantity,executed_quantity,client_billed_amount,executed_billed_amount,observation,:user,NOW() FROM planning_chantier_work_progress WHERE action_point_id=:old ON DUPLICATE KEY UPDATE unit_label=VALUES(unit_label),planned_quantity=VALUES(planned_quantity),client_billed_amount=VALUES(client_billed_amount),observation=VALUES(observation),updated_by=VALUES(updated_by),updated_at=NOW()");
        $checkIds=$this->pdo->prepare("SELECT id FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:point AND archived_at IS NULL ORDER BY sort_order,id");$copySubWork=$this->pdo->prepare("INSERT INTO planning_chantier_work_progress_checklist(checklist_id,unit_label,planned_quantity,client_billed_amount,observation,updated_by,updated_at) SELECT :new,unit_label,planned_quantity,client_billed_amount,observation,:user,NOW() FROM planning_chantier_work_progress_checklist WHERE checklist_id=:old ON DUPLICATE KEY UPDATE unit_label=VALUES(unit_label),planned_quantity=VALUES(planned_quantity),client_billed_amount=VALUES(client_billed_amount),observation=VALUES(observation),updated_by=VALUES(updated_by),updated_at=NOW()");
        foreach($old as$index=>$oldId)if(isset($new[$index])){$newId=(int)$new[$index];$copyChecklist->execute(['new'=>$newId,'user'=>$userId,'old'=>(int)$oldId]);$copyWork->execute(['new'=>$newId,'user'=>$userId,'old'=>(int)$oldId]);$checkIds->execute(['point'=>(int)$oldId]);$oldChecks=$checkIds->fetchAll(PDO::FETCH_COLUMN)?:[];$checkIds->execute(['point'=>$newId]);$newChecks=$checkIds->fetchAll(PDO::FETCH_COLUMN)?:[];foreach($oldChecks as$subIndex=>$oldCheck)if(isset($newChecks[$subIndex]))$copySubWork->execute(['new'=>(int)$newChecks[$subIndex],'user'=>$userId,'old'=>(int)$oldCheck]);}
        $this->pdo->prepare("INSERT INTO planning_chantier_work_progress_groups(preparation_id,chantier_id,section_title,client_billed_amount,observation,updated_by,updated_at) SELECT :current,chantier_id,section_title,client_billed_amount,observation,:user,NOW() FROM planning_chantier_work_progress_groups WHERE preparation_id=:previous ON DUPLICATE KEY UPDATE client_billed_amount=VALUES(client_billed_amount),observation=VALUES(observation),updated_by=VALUES(updated_by),updated_at=NOW()")->execute(['current'=>$preparationId,'previous'=>$previousId,'user'=>$userId]);
        $copyAttachments=$this->pdo->prepare("INSERT INTO planning_chantier_office_attachments
            (preparation_id,ligne_debourse_id,action_point_id,meeting_section_id,original_name,stored_name,mime_type,file_size,uploaded_by,created_at)
            SELECT DISTINCT :current,a.ligne_debourse_id,NULL,NULL,a.original_name,a.stored_name,a.mime_type,a.file_size,:user,NOW()
            FROM planning_chantier_office_attachments a
            JOIN planning_chantier_meeting_action_points ap
              ON ap.preparation_id=a.preparation_id AND ap.ligne_debourse_id=a.ligne_debourse_id
            WHERE a.preparation_id=:previous AND ap.ligne_devis_id=:line
              AND a.meeting_section_id IS NULL AND a.archived_at IS NULL");
        $copyAttachments->execute(['current'=>$preparationId,'user'=>$userId,'previous'=>$previousId,'line'=>$lineId]);
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_seeds SET source_preparation_id=:source
            WHERE preparation_id=:preparation AND ligne_devis_id=:line")
            ->execute(['source'=>$previousId,'preparation'=>$preparationId,'line'=>$lineId]);
        $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    public function forMeeting(int $meetingId):array
    {
        $stmt=$this->pdo->prepare("SELECT ap.*,c.code chantier_code,c.titre chantier_title,
                line_task.titre service_title,
                COALESCE(NULLIF(TRIM(ap.section_title),''),NULLIF(TRIM(deb_task.titre),''),MIN(NULLIF(TRIM(detail_task.titre),'')),'Section') meeting_section_title,
                GROUP_CONCAT(
                    CONCAT(CASE WHEN cl.completed_at IS NOT NULL THEN '[x] ' ELSE '[ ] ' END,cl.label,CASE WHEN cl.progress_percent>0 THEN CONCAT(' (',ROUND(cl.progress_percent,0),'%)') ELSE '' END)
                    ORDER BY cl.sort_order,cl.id SEPARATOR '\n'
                ) checklist_text
            FROM planning_chantier_meeting_action_points ap
            INNER JOIN planning_chantier_meetings m ON m.preparation_id=ap.preparation_id
            INNER JOIN chantier_banamur c ON c.id=ap.chantier_id
            INNER JOIN planning_chantier_projects project ON project.chantier_id=c.id
            INNER JOIN planning_chantier_tasks line_task
              ON line_task.project_id=project.id AND line_task.source_type='ligne_devis' AND line_task.source_id=ap.ligne_devis_id
            LEFT JOIN planning_chantier_tasks deb_task
              ON deb_task.project_id=project.id AND deb_task.source_type='debourse' AND deb_task.source_id=ap.debourse_id
            LEFT JOIN planning_chantier_tasks detail_task
              ON detail_task.project_id=project.id AND detail_task.source_type='ligne_debourse' AND detail_task.source_id=ap.ligne_debourse_id
            LEFT JOIN planning_chantier_meeting_action_point_checklist cl ON cl.action_point_id=ap.id AND cl.archived_at IS NULL
            WHERE m.id=:meeting AND ap.archived_at IS NULL
            GROUP BY ap.id
            ORDER BY line_task.ordre,deb_task.ordre,ap.sort_order,ap.id");
        $stmt->execute(['meeting'=>$meetingId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];

        // Les points libres d'une réunion chantier sont stockés avec leur arbre de
        // tâches dans custom_sections. Ils doivent faire partie du compte rendu au
        // même titre que les points issus des déboursés.
        $custom=$this->pdo->prepare("SELECT s.*,c.titre chantier_title,line_task.titre service_title,
                CONCAT_WS(' ',u.prenom,u.nom) responsible_name
            FROM planning_chantier_meeting_custom_sections s
            INNER JOIN planning_chantier_meetings m ON m.preparation_id=s.preparation_id
            LEFT JOIN chantier_banamur c ON c.id=s.chantier_id
            LEFT JOIN planning_chantier_projects project ON project.chantier_id=s.chantier_id
            LEFT JOIN planning_chantier_tasks line_task
              ON line_task.project_id=project.id AND line_task.source_type='ligne_devis' AND line_task.source_id=s.ligne_devis_id
            LEFT JOIN user_devis_banamur u ON u.id=s.responsible_user_id
            WHERE m.id=:meeting AND s.archived_at IS NULL ORDER BY s.sort_order,s.id");
        $custom->execute(['meeting'=>$meetingId]);
        foreach($custom->fetchAll(PDO::FETCH_ASSOC)?:[] as $section){
            $tasks=json_decode((string)($section['payload_json']??'[]'),true);
            if(!is_array($tasks)||!$tasks){
                $tasks=[['title'=>$section['title'],'progress'=>0,'checklist'=>[]]];
            }
            foreach($tasks as $task){
                $checklist=[];
                foreach(is_array($task['checklist']??null)?$task['checklist']:[] as $item){
                    $itemProgress=!empty($item['completed'])?100:(float)($item['progress']??0);
                    $checklist[]=(!empty($item['completed'])?'[x] ':'[ ] ').(string)($item['label']??'').($itemProgress>0?' ('.number_format($itemProgress,0,',','').'%)':'');
                }
                $rows[]=[
                    'id'=>0,'custom_section_id'=>(int)$section['id'],
                    'chantier_id'=>(int)($section['chantier_id']??0),'ligne_devis_id'=>(int)($section['ligne_devis_id']??0),
                    'chantier_title'=>(string)($section['chantier_title']??''),
                    'service_title'=>(string)($section['service_title']?:$section['chantier_title']?:'Point libre'),
                    'meeting_section_title'=>(string)$section['title'],
                    'task_title'=>(string)($task['title']??$section['title']),
                    'pilot_name'=>(string)($section['responsible_name']??''),'copilot_name'=>'',
                    'old_deadline'=>(string)($task['old_deadline']??$section['start_date']??''),
                    'new_deadline'=>(string)($task['new_deadline']??$section['end_date']??''),
                    'progress'=>(float)($task['progress']??0),
                    'checklist_text'=>implode("\n",$checklist),
                ];
            }
        }
        return $rows;
    }

    public function save(array $data,?int $userId):array
    {
        $preparationId=(int)($data['preparation_id']??0);
        $debourseId=(int)($data['debourse_id']??0);
        $detailId=(int)($data['ligne_debourse_id']??0);
        $id=(int)($data['id']??0);
        $context=$this->context($preparationId,$debourseId,$detailId);
        $title=trim((string)($data['task_title']??''));
        if($title==='')throw new DomainException('Le point ou la tâche est obligatoire.');
        $people=[];
        foreach((new PlanningMeetingRepository($this->pdo))->participantOptions() as $participant){
            $participantId=(int)($participant['user_id']??0);
            if($participantId>0)$people[$participantId]=$participant;
        }
        $pilotUserId=(int)($data['pilot_user_id']??0);
        $copilotUserId=(int)($data['copilot_user_id']??0);
        if($pilotUserId<=0||!isset($people[$pilotUserId]))throw new DomainException('Choisissez un pilote valide.');
        if($copilotUserId>0&&!isset($people[$copilotUserId]))throw new DomainException('Choisissez un copilote valide.');
        $progress=0.0;
        if($id>0){
            $calculated=$this->pdo->prepare("SELECT CASE WHEN COUNT(*)=0 THEN 0 ELSE ROUND(SUM(CASE WHEN completed_at IS NOT NULL THEN 100 ELSE progress_percent END)/COUNT(*),2) END FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:id AND archived_at IS NULL");
            $calculated->execute(['id'=>$id]);$progress=(float)$calculated->fetchColumn();
        }
        $payload=[
            'task_title'=>$title,
            'section_title'=>trim((string)($data['section_title']??''))?:null,
            'pilot_user_id'=>$pilotUserId,
            'pilot_name'=>(string)($people[$pilotUserId]['name']??''),
            'copilot_user_id'=>$copilotUserId?:null,
            'copilot_name'=>$copilotUserId>0?(string)($people[$copilotUserId]['name']??''):null,
            'old_deadline'=>$this->dateOrNull($data['old_deadline']??null),
            'new_deadline'=>$this->dateOrNull($data['new_deadline']??null),
            'progress'=>$progress,
            'status'=>$progress>=100?'done':($progress>0?'in_progress':'open'),
            'user'=>$userId,
        ];
        if($id>0){
            $payload['id']=$id;$payload['preparation']=$preparationId;$payload['debourse']=$debourseId;$payload['detail']=$detailId;
            $this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET
                ligne_debourse_id=:detail,section_title=:section_title,task_title=:task_title,pilot_user_id=:pilot_user_id,pilot_name=:pilot_name,
                copilot_user_id=:copilot_user_id,copilot_name=:copilot_name,
                old_deadline=:old_deadline,new_deadline=:new_deadline,progress=:progress,
                status=:status,updated_by=:user,updated_at=NOW()
                WHERE id=:id AND preparation_id=:preparation AND debourse_id=:debourse")->execute($payload);
        }else{
            $payload+=['preparation'=>$preparationId,'chantier'=>$context['chantier_id'],'line'=>$context['ligne_devis_id'],'debourse'=>$debourseId,'detail'=>$detailId];
            $order=$this->pdo->prepare('SELECT COALESCE(MAX(sort_order),-1)+1 FROM planning_chantier_meeting_action_points WHERE preparation_id=:preparation AND ligne_debourse_id=:detail AND archived_at IS NULL');
            $order->execute(['preparation'=>$preparationId,'detail'=>$detailId]);
            $payload['sort_order']=(int)$order->fetchColumn();
            $this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_points
                (preparation_id,chantier_id,ligne_devis_id,debourse_id,ligne_debourse_id,section_title,task_title,pilot_user_id,pilot_name,copilot_user_id,copilot_name,
                old_deadline,new_deadline,progress,status,sort_order,created_by,created_at)
                VALUES(:preparation,:chantier,:line,:debourse,:detail,:section_title,:task_title,:pilot_user_id,:pilot_name,:copilot_user_id,:copilot_name,
                :old_deadline,:new_deadline,:progress,:status,:sort_order,:user,NOW())")->execute($payload);
        }
        return $this->grouped($preparationId,(int)$context['chantier_id']);
    }

    public function reorderPoints(array $data,?int $userId):array
    {
        $preparationId=(int)($data['preparation_id']??0);
        $detailId=(int)($data['ligne_debourse_id']??0);
        $ids=array_values(array_unique(array_map('intval',is_array($data['point_ids']??null)?$data['point_ids']:[])));
        if($preparationId<=0||$detailId<=0||!$ids)throw new DomainException('Ordre des points invalide.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare("SELECT id,chantier_id FROM planning_chantier_meeting_action_points WHERE preparation_id=? AND ligne_debourse_id=? AND archived_at IS NULL AND id IN ($marks)");
        $stmt->execute(array_merge([$preparationId,$detailId],$ids));$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $existing=array_map('intval',array_column($rows,'id'));sort($existing);$expected=$ids;sort($expected);
        if($existing!==$expected)throw new DomainException('Certains points ne font pas partie de cette section.');
        $chantierId=(int)($rows[0]['chantier_id']??0);
        $this->pdo->beginTransaction();
        try{
            $update=$this->pdo->prepare('UPDATE planning_chantier_meeting_action_points SET sort_order=:sort,updated_by=:user,updated_at=NOW() WHERE id=:id');
            foreach($ids as $sort=>$id)$update->execute(['sort'=>$sort,'user'=>$userId,'id'=>$id]);
            $groups=$this->grouped($preparationId,$chantierId);$this->pdo->commit();return $groups;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function toggle(array $data,?int $userId):array
    {
        $preparationId=(int)($data['preparation_id']??0);
        $scope=(string)($data['scope']??'point');
        $id=(int)($data['id']??0);
        $completed=!empty($data['completed']);
        if($preparationId<=0||$id<=0||!in_array($scope,['point','detail','debourse'],true))throw new DomainException('Point de suivi invalide.');
        $column=$scope==='point'?'ap.id':($scope==='detail'?'ap.ligne_debourse_id':'ap.debourse_id');
        $target=$this->scopeContext($preparationId,$scope,$id);
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
        $params=[
            'progress'=>$completed?100:0,
            'status'=>$completed?'done':'open',
            'user'=>$userId,
            'preparation'=>$preparationId,
            'id'=>$id,
            'chantier'=>(int)$target['chantier_id'],
            'line'=>(int)$target['ligne_devis_id'],
        ];
        $stmt=$this->pdo->prepare("UPDATE planning_chantier_meeting_action_points ap SET
                ap.progress=:progress,ap.status=:status,ap.updated_by=:user,ap.updated_at=NOW()
            WHERE ap.preparation_id=:preparation AND $column=:id AND ap.archived_at IS NULL
              AND ap.chantier_id=:chantier AND ap.ligne_devis_id=:line AND ap.status<>'cancelled'");
        $checklist=$this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist cl
            JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
            SET cl.completed_at=".($completed?'NOW()':'NULL').",cl.completed_by=:user,cl.updated_at=NOW()
            WHERE ap.preparation_id=:preparation AND $column=:id
              AND ap.chantier_id=:chantier AND ap.ligne_devis_id=:line AND ap.status<>'cancelled'
              AND ap.archived_at IS NULL AND cl.archived_at IS NULL");
        $checklist->execute([
            'user'=>$completed?$userId:null,'preparation'=>$preparationId,'id'=>$id,
            'chantier'=>(int)$target['chantier_id'],'line'=>(int)$target['ligne_devis_id'],
        ]);
        $stmt->execute($params);
        $affected=$this->pdo->prepare("SELECT ap.id FROM planning_chantier_meeting_action_points ap
            WHERE ap.preparation_id=:preparation AND $column=:id AND ap.archived_at IS NULL
              AND ap.chantier_id=:chantier AND ap.ligne_devis_id=:line AND ap.status<>'cancelled'");
        $affected->execute(['preparation'=>$preparationId,'id'=>$id,'chantier'=>(int)$target['chantier_id'],'line'=>(int)$target['ligne_devis_id']]);
        foreach($affected->fetchAll(PDO::FETCH_COLUMN)?:[] as $pointId)$this->refreshActionPoint((int)$pointId,$userId);
        $groups=$this->grouped($preparationId,(int)$target['chantier_id']);
        if($ownsTransaction)$this->pdo->commit();
        return $groups;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function addChecklistItem(array $data,?int $userId):array
    {
        $point=$this->checklistContext((int)($data['preparation_id']??0),(int)($data['action_point_id']??0));
        $label=trim((string)($data['label']??''));
        if($label===''||mb_strlen($label)>500)throw new DomainException('Saisissez une sous-tâche valide.');
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
        $order=$this->pdo->prepare("SELECT COALESCE(MAX(sort_order),-1)+1 FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:point AND archived_at IS NULL");
        $order->execute(['point'=>(int)$point['point_id']]);
        $this->pdo->prepare("INSERT INTO planning_chantier_meeting_action_point_checklist
            (action_point_id,label,sort_order,created_by,created_at) VALUES(:point,:label,:sort_order,:user,NOW())")->execute([
                'point'=>(int)$point['point_id'],'label'=>$label,'sort_order'=>(int)$order->fetchColumn(),'user'=>$userId,
            ]);
        $this->refreshActionPoint((int)$point['point_id'],$userId);
        $groups=$this->grouped((int)$point['preparation_id'],(int)$point['chantier_id']);if($ownsTransaction)$this->pdo->commit();return $groups;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function toggleChecklistItem(array $data,?int $userId):array
    {
        $preparationId=(int)($data['preparation_id']??0);
        $itemId=(int)($data['checklist_id']??0);
        $stmt=$this->pdo->prepare("SELECT cl.action_point_id
            FROM planning_chantier_meeting_action_point_checklist cl
            JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
            JOIN planning_chantier_meeting_preparations p ON p.id=ap.preparation_id
            WHERE cl.id=:item AND cl.archived_at IS NULL AND ap.archived_at IS NULL AND ap.preparation_id=:preparation AND p.status='ready'
            LIMIT 1");
        $stmt->execute(['item'=>$itemId,'preparation'=>$preparationId]);
        $pointId=(int)$stmt->fetchColumn();
        if($pointId<=0)throw new DomainException('Sous-tâche invalide.');
        $point=$this->checklistContext($preparationId,$pointId);
        $completed=!empty($data['completed']);
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist SET
            completed_at=".($completed?'NOW()':'NULL').",completed_by=:user,progress_percent=".($completed?'100':'0').",updated_at=NOW() WHERE id=:id")->execute([
                'user'=>$completed?$userId:null,'id'=>$itemId,
        ]);
        $this->refreshActionPoint($pointId,$userId);
        $groups=$this->grouped($preparationId,(int)$point['chantier_id']);if($ownsTransaction)$this->pdo->commit();return $groups;
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function updateChecklistProgress(array $data,?int $userId):array
    {
        $preparationId=(int)($data['preparation_id']??0);$itemId=(int)($data['checklist_id']??0);
        $progress=(float)($data['progress']??-1);
        if($progress<0||$progress>100)throw new DomainException('Le pourcentage doit être compris entre 0 et 100.');
        $stmt=$this->pdo->prepare("SELECT cl.action_point_id FROM planning_chantier_meeting_action_point_checklist cl JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id JOIN planning_chantier_meeting_preparations p ON p.id=ap.preparation_id WHERE cl.id=:item AND cl.archived_at IS NULL AND ap.archived_at IS NULL AND ap.preparation_id=:preparation AND p.status='ready' LIMIT 1");
        $stmt->execute(['item'=>$itemId,'preparation'=>$preparationId]);$pointId=(int)$stmt->fetchColumn();
        if($pointId<=0)throw new DomainException('Sous-tâche invalide.');
        $point=$this->checklistContext($preparationId,$pointId);
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist SET progress_percent=:progress,completed_at=".($progress>=100?'NOW()':'NULL').",completed_by=:user,updated_at=NOW() WHERE id=:id")->execute(['progress'=>$progress,'user'=>$progress>=100?$userId:null,'id'=>$itemId]);
        $this->refreshActionPoint($pointId,$userId);
        return $this->grouped($preparationId,(int)$point['chantier_id']);
    }

    public function reorderChecklistItems(array $data,?int $userId):array
    {
        $point=$this->checklistContext((int)($data['preparation_id']??0),(int)($data['action_point_id']??0));
        $ids=array_values(array_unique(array_map('intval',is_array($data['checklist_ids']??null)?$data['checklist_ids']:[])));
        if(!$ids)throw new DomainException('Ordre de checklist invalide.');
        $marks=implode(',',array_fill(0,count($ids),'?'));
        $stmt=$this->pdo->prepare("SELECT id FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=? AND archived_at IS NULL AND id IN ($marks) ORDER BY sort_order,id");
        $stmt->execute(array_merge([(int)$point['point_id']],$ids));
        $existing=array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN)?:[]);
        sort($existing);$expected=$ids;sort($expected);
        if($existing!==$expected)throw new DomainException('Certaines sous-tâches ne font pas partie de cette checklist.');
        $this->pdo->beginTransaction();
        try{
            $update=$this->pdo->prepare('UPDATE planning_chantier_meeting_action_point_checklist SET sort_order=:order,updated_at=NOW() WHERE id=:id');
            foreach($ids as $index=>$id)$update->execute(['order'=>$index,'id'=>$id]);
            $groups=$this->grouped((int)$point['preparation_id'],(int)$point['chantier_id']);
            $this->pdo->commit();
            return $groups;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    private function checklistContext(int $preparationId,int $pointId):array
    {
        $stmt=$this->pdo->prepare("SELECT ap.id point_id,ap.preparation_id,ap.chantier_id,ap.ligne_devis_id,
                p.chantier_ids_json,p.office_chantier_id,p.office_ligne_devis_id
            FROM planning_chantier_meeting_action_points ap
            JOIN planning_chantier_meeting_preparations p ON p.id=ap.preparation_id
            WHERE ap.id=:point AND ap.preparation_id=:preparation AND ap.archived_at IS NULL AND p.status='ready'
            LIMIT 1");
        $stmt->execute(['point'=>$pointId,'preparation'=>$preparationId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||!$this->preparationAllows($row,(int)$row['chantier_id'],(int)$row['ligne_devis_id']))throw new DomainException('Tâche hors du périmètre de la réunion.');
        return $row;
    }

    private function refreshActionPoint(int $pointId,?int $userId):void
    {
        $count=$this->pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN completed_at IS NOT NULL THEN 100 ELSE progress_percent END) progress_total
            FROM planning_chantier_meeting_action_point_checklist WHERE action_point_id=:point AND archived_at IS NULL");
        $count->execute(['point'=>$pointId]);
        $row=$count->fetch(PDO::FETCH_ASSOC)?:['total'=>0,'progress_total'=>0];
        $progress=(int)$row['total']>0?round(((float)$row['progress_total']/(int)$row['total']),2):0;
        $status=$progress>=100?'done':($progress>0?'in_progress':'open');
        $this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET progress=:progress,status=:status,
            updated_by=:user,updated_at=NOW() WHERE id=:id")->execute([
                'progress'=>$progress,'status'=>$status,'user'=>$userId,'id'=>$pointId,
            ]);
    }

    private function context(int $preparationId,int $debourseId,int $detailId):array
    {
        $prep=$this->pdo->prepare("SELECT chantier_ids_json,participants_json,office_chantier_id,office_ligne_devis_id
            FROM planning_chantier_meeting_preparations WHERE id=:id AND status='ready' LIMIT 1");
        $prep->execute(['id'=>$preparationId]);
        $preparation=$prep->fetch(PDO::FETCH_ASSOC)?:[];
        $allowed=array_map('intval',json_decode((string)($preparation['chantier_ids_json']??'[]'),true)?:[]);
        $stmt=$this->pdo->prepare("SELECT p.chantier_id,line_task.source_id ligne_devis_id,
                detail_task.source_id ligne_debourse_id
            FROM planning_chantier_tasks detail_task
            JOIN planning_chantier_tasks deb_task
              ON deb_task.id=detail_task.parent_id AND deb_task.source_type='debourse' AND deb_task.source_id=:debourse
            JOIN planning_chantier_tasks line_task
              ON line_task.id=deb_task.parent_id AND line_task.source_type='ligne_devis'
            JOIN planning_chantier_projects p ON p.id=line_task.project_id
            JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE detail_task.source_type='ligne_debourse' AND detail_task.source_id=:detail
              AND detail_task.statut<>'annule' AND deb_task.statut<>'annule' AND line_task.statut<>'annule'
              AND c.archived_at IS NULL AND c.closed_date IS NULL LIMIT 1");
        $stmt->execute(['debourse'=>$debourseId,'detail'=>$detailId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||!in_array((int)$row['chantier_id'],$allowed,true)
            ||(!$this->preparationAllows($preparation,(int)$row['chantier_id'],(int)$row['ligne_devis_id'])))throw new DomainException('Chantier ou préparation invalide.');
        $row['participants']=json_decode((string)($preparation['participants_json']??'[]'),true)?:[];
        return $row;
    }

    private function scopeContext(int $preparationId,string $scope,int $id):array
    {
        $column=$scope==='point'?'ap.id':($scope==='detail'?'ap.ligne_debourse_id':'ap.debourse_id');
        $stmt=$this->pdo->prepare("SELECT ap.chantier_id,ap.ligne_devis_id,
                p.chantier_ids_json,p.office_chantier_id,p.office_ligne_devis_id
            FROM planning_chantier_meeting_action_points ap
            JOIN planning_chantier_meeting_preparations p ON p.id=ap.preparation_id
            WHERE ap.preparation_id=:preparation AND $column=:id
              AND ap.archived_at IS NULL AND ap.status<>'cancelled' AND p.status='ready' LIMIT 1");
        $stmt->execute(['preparation'=>$preparationId,'id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row||!$this->preparationAllows($row,(int)$row['chantier_id'],(int)$row['ligne_devis_id'])){
            throw new DomainException('Point hors du périmètre de la réunion.');
        }
        return $row;
    }

    private function preparationAllows(array $preparation,int $chantierId,int $lineId):bool
    {
        $allowed=array_map('intval',json_decode((string)($preparation['chantier_ids_json']??'[]'),true)?:[]);
        if(!in_array($chantierId,$allowed,true))return false;
        $officeChantierId=(int)($preparation['office_chantier_id']??0);
        $officeLineId=(int)($preparation['office_ligne_devis_id']??0);
        return $officeChantierId<=0||($officeChantierId===$chantierId&&$officeLineId===$lineId);
    }

    private function dateOrNull(mixed $value):?string
    {
        $value=trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)?$value:null;
    }
}
