<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    if (class_exists('AccessManager')
        && !AccessManager::hasPageAccess('planning_chantier/dashboard.php')
        && !AccessManager::hasPageAccess('planning_chantier/reunion.php')) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Accès refusé au pilotage du chantier.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_SERVER['REQUEST_METHOD']==='GET'?$_GET:$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    $preparationId=(int)($input['preparation_id']??0);
    $requestPreparation=$preparationId>0?(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId):null;
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'&&$requestPreparation&&empty($requestPreparation['office_chantier_id'])){
        throw new DomainException('Les changements de cette réunion restent limités à ses tâches, checklists et pièces jointes.');
    }
    $service=new PlanningFinancialPilotService($pdo);
    if(($_SERVER['REQUEST_METHOD']??'GET')==='GET'){
        $chantierId=(int)($_GET['chantier_id']??0);
        $result=$requestPreparation&&empty($requestPreparation['office_chantier_id'])
            ?$service->hierarchy($chantierId)
            :$service->load($chantierId,planning_chantier_user_id());
    }
    else $result=$service->update($input,planning_chantier_user_id());
    if($preparationId>0){
        $preparation=(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId);
        $projectId=(int)($result['project']['id']??0);
        $allowedProjects=array_map('intval',$preparation['chantier_ids']??[]);
        if(!$preparation||!in_array($projectId,$allowedProjects,true))throw new DomainException('Ce chantier ne fait pas partie de la réunion.');
        $officeLineId=(int)($preparation['office_ligne_devis_id']??0);
        if($officeLineId>0){
            if((int)($preparation['office_chantier_id']??0)!==$projectId)throw new DomainException('Cette préparation ne correspond pas à la réunion Bureau.');
            $result['lines']=array_values(array_filter($result['lines'],static fn($line)=>(int)$line['id']===$officeLineId));
        }
        // Un élément retiré d'une réunion reste retiré des réunions suivantes.
        // La préparation courante est fondée sur l'état de la précédente : ne
        // pas limiter ce filtre au seul brouillon courant, sinon les anciens
        // points masqués réapparaissent subitement.
        $hiddenLineStmt=$pdo->prepare("SELECT DISTINCT h.ligne_devis_id
            FROM planning_chantier_meeting_hidden_lines h
            JOIN planning_chantier_tasks line_task
              ON line_task.source_type='ligne_devis' AND line_task.source_id=h.ligne_devis_id
            JOIN planning_chantier_projects project ON project.id=line_task.project_id
            WHERE project.chantier_id=:chantier");
        $hiddenLineStmt->execute(['chantier'=>$projectId]);
        $hiddenLines=array_fill_keys(array_map('intval',$hiddenLineStmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
        if($hiddenLines)$result['lines']=array_values(array_filter(
            $result['lines'],
            static fn(array $line):bool=>!isset($hiddenLines[(int)$line['id']])
        ));
        $overrideStmt=$pdo->prepare("SELECT ligne_devis_id,meeting_label
            FROM planning_chantier_meeting_line_overrides WHERE preparation_id=:preparation");
        $overrideStmt->execute(['preparation'=>$preparationId]);
        $lineLabels=[];
        foreach($overrideStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $override){
            $lineLabels[(int)$override['ligne_devis_id']]=(string)$override['meeting_label'];
        }
        if($lineLabels){
            foreach($result['lines'] as &$labelledLine){
                if(isset($lineLabels[(int)$labelledLine['id']]))$labelledLine['designation']=$lineLabels[(int)$labelledLine['id']];
            }
            unset($labelledLine);
        }
        $hiddenStmt=$pdo->prepare("SELECT DISTINCT h.debourse_id
            FROM planning_chantier_meeting_hidden_debourses h
            JOIN planning_chantier_tasks debourse_task
              ON debourse_task.source_type='debourse' AND debourse_task.source_id=h.debourse_id
            JOIN planning_chantier_projects project ON project.id=debourse_task.project_id
            WHERE project.chantier_id=:chantier");
        $hiddenStmt->execute(['chantier'=>$projectId]);
        $hidden=array_fill_keys(array_map('intval',$hiddenStmt->fetchAll(PDO::FETCH_COLUMN)?:[]),true);
        if($hidden){
            foreach($result['lines'] as &$visibleLine){
                $visibleLine['debourses']=array_values(array_filter(
                    $visibleLine['debourses'],
                    static fn(array $debourse):bool=>!isset($hidden[(int)$debourse['id']])
                ));
            }
            unset($visibleLine);
        }
        $actionPoints=new PlanningMeetingActionPointRepository($pdo);
        foreach($result['lines'] as $meetingLine){
            $actionPoints->seedOpenPointsFromPrevious($preparationId,(int)$meetingLine['id'],planning_chantier_user_id());
        }
        $groups=$actionPoints->grouped($preparationId,(int)$result['project']['id']);
        $attachmentStmt=$pdo->prepare("SELECT id,original_name,mime_type,file_size,created_at
            FROM planning_chantier_office_attachments
            WHERE preparation_id=:preparation AND ligne_debourse_id=:detail AND archived_at IS NULL ORDER BY id DESC");
        foreach($result['lines'] as &$line){
            $linePointCount=0;
            $lineCompletedCount=0;
            $lineProgressSum=0.0;
            foreach($line['debourses'] as &$debourse){
                $deboursePointCount=0;
                $debourseCompletedCount=0;
                $debourseProgressSum=0.0;
                foreach($debourse['details'] as &$detail){
                    $detail['meeting_points']=$groups[(int)$debourse['id']][(int)$detail['id']]??[];
                    $attachmentStmt->execute(['preparation'=>$preparationId,'detail'=>(int)$detail['id']]);
                    $detail['attachments']=$attachmentStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
                    $detail['meeting_point_count']=count($detail['meeting_points']);
                    $detail['meeting_completed_count']=count(array_filter(
                        $detail['meeting_points'],
                        static fn(array $point):bool=>(float)($point['progress']??0)>=100
                    ));
                    $detail['meeting_progress']=$detail['meeting_point_count']>0
                        ?round(array_sum(array_map(static fn(array $point):float=>(float)($point['progress']??0),$detail['meeting_points']))/$detail['meeting_point_count'],1)
                        :0;
                    $detail['meeting_completed']=$detail['meeting_point_count']>0
                        &&$detail['meeting_completed_count']===$detail['meeting_point_count'];
                    $deboursePointCount+=$detail['meeting_point_count'];
                    $debourseCompletedCount+=$detail['meeting_completed_count'];
                    $debourseProgressSum+=array_sum(array_map(static fn(array $point):float=>(float)($point['progress']??0),$detail['meeting_points']));
                }
                unset($detail);
                $debourse['meeting_point_count']=$deboursePointCount;
                $debourse['meeting_completed_count']=$debourseCompletedCount;
                $debourse['progress']=$deboursePointCount>0?round($debourseProgressSum/$deboursePointCount,1):0;
                $debourse['completed']=$deboursePointCount>0&&$debourseCompletedCount===$deboursePointCount;
                $linePointCount+=$deboursePointCount;
                $lineCompletedCount+=$debourseCompletedCount;
                $lineProgressSum+=$debourseProgressSum;
            }
            unset($debourse);
            $line['meeting_point_count']=$linePointCount;
            $line['meeting_completed_count']=$lineCompletedCount;
            $line['progress']=$linePointCount>0?round($lineProgressSum/$linePointCount,1):0;
            $line['completed']=$linePointCount>0&&$lineCompletedCount===$linePointCount;
        }
        unset($line);
        $linePointCount=array_sum(array_map(static fn(array $line):int=>(int)($line['meeting_point_count']??0),$result['lines']));
        $lineCompletedCount=array_sum(array_map(static fn(array $line):int=>(int)($line['meeting_completed_count']??0),$result['lines']));
        $resultProgressSum=array_sum(array_map(static fn(array $line):float=>(float)($line['progress']??0)*(int)($line['meeting_point_count']??0),$result['lines']));
        $result['progress']=$linePointCount>0?round($resultProgressSum/$linePointCount,1):0;
        $performanceLineId=$officeLineId?:((int)($result['lines'][0]['id']??0));
        $result['staff_performance']=$performanceLineId>0?$actionPoints->performance($preparationId,$performanceLineId):[];
        if($officeLineId<=0)(new PlanningMeetingCustomSectionCarryForwardService($pdo))->seed(
            $preparationId,$projectId,planning_chantier_user_id()
        );
        $customStmt=$pdo->prepare("SELECT id,chantier_id,ligne_devis_id,title,responsible_user_id,start_date,end_date,payload_json,sort_order
            FROM planning_chantier_meeting_custom_sections
            WHERE preparation_id=:preparation AND archived_at IS NULL
              AND (chantier_id IS NULL OR chantier_id=:chantier)
            ORDER BY sort_order,id");
        $customStmt->execute(['preparation'=>$preparationId,'chantier'=>$projectId]);
        $result['meeting_sections']=$customStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        if($result['meeting_sections']){
            $sectionIds=array_map(static fn(array $section):int=>(int)$section['id'],$result['meeting_sections']);
            $placeholders=implode(',',array_fill(0,count($sectionIds),'?'));
            $customFiles=$pdo->prepare("SELECT id,meeting_section_id,original_name,mime_type,file_size,created_at
                FROM planning_chantier_office_attachments
                WHERE preparation_id=? AND meeting_section_id IN ($placeholders) AND archived_at IS NULL ORDER BY id DESC");
            $customFiles->execute(array_merge([$preparationId],$sectionIds));
            $filesBySection=[];
            foreach($customFiles->fetchAll(PDO::FETCH_ASSOC)?:[] as $file)$filesBySection[(int)$file['meeting_section_id']][]=$file;
            foreach($result['meeting_sections'] as &$section)$section['attachments']=$filesBySection[(int)$section['id']]??[];
            unset($section);
        }
        if($officeLineId<=0){
            // Une réunion chantier n'affiche que les points réellement saisis
            // ou repris de la réunion précédente. Les lignes et déboursés
            // financiers ne constituent jamais, à eux seuls, des points.
            $sectionLineIds=[];
            foreach($result['meeting_sections'] as $section){
                $sectionLineId=(int)($section['ligne_devis_id']??0);
                if($sectionLineId>0)$sectionLineIds[$sectionLineId]=true;
            }
            foreach($result['lines'] as &$meetingLine){
                $meetingLine['debourses']=array_values(array_filter(
                    $meetingLine['debourses'],
                    static fn(array $debourse):bool=>(int)($debourse['meeting_point_count']??0)>0
                ));
            }
            unset($meetingLine);
            $result['lines']=array_values(array_filter(
                $result['lines'],
                static fn(array $line):bool=>!empty($line['debourses'])
                    ||isset($sectionLineIds[(int)$line['id']])
            ));
            $result['excluded_debourses']=0;
        }
        $customProgressSum=0.0;$customTaskCount=0;
        foreach($result['meeting_sections'] as $section){$tasks=json_decode((string)($section['payload_json']??'[]'),true);foreach(is_array($tasks)?$tasks:[] as $task){$customProgressSum+=(float)($task['progress']??0);$customTaskCount++;}}
        if($customTaskCount>0)$result['progress']=round(($resultProgressSum+$customProgressSum)/($linePointCount+$customTaskCount),1);
    }
    echo json_encode(['ok'=>true,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
