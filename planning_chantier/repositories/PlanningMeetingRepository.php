<?php
declare(strict_types=1);

final class PlanningMeetingRepository
{
    public function __construct(private PDO $pdo) {}

    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare("SELECT m.*, COALESCE(line_task.titre,p.libelle) project_label,
                ((SELECT COUNT(*) FROM planning_chantier_meeting_items mi WHERE mi.meeting_id=m.id)
                 +(SELECT COUNT(*) FROM planning_chantier_meeting_action_points ap
                    WHERE ap.preparation_id=m.preparation_id AND ap.archived_at IS NULL)
                 +(SELECT COUNT(*) FROM planning_chantier_meeting_custom_sections cs
                    WHERE cs.preparation_id=m.preparation_id AND cs.archived_at IS NULL)) AS points_count,
                (SELECT COUNT(*) FROM planning_chantier_meeting_participants mp WHERE mp.meeting_id = m.id) AS participants_count
            FROM planning_chantier_meetings m
            LEFT JOIN planning_chantier_projects p ON p.id = m.project_id
            LEFT JOIN chantier_banamur c ON c.id=p.chantier_id
            LEFT JOIN planning_chantier_tasks line_task
              ON line_task.source_type='ligne_devis' AND line_task.source_id=m.office_service_line_id
            WHERE m.archived_at IS NULL AND (m.project_id IS NULL OR (
                p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
            ))
            ORDER BY m.meeting_date DESC, m.id DESC
            LIMIT :limit");
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function dashboardSummary(): array
    {
        $row = $this->pdo->query("SELECT
                COUNT(*) meetings_total,
                SUM(m.meeting_date >= DATE_FORMAT(CURRENT_DATE(), '%Y-%m-01')) meetings_month,
                SUM(m.status='closed') meetings_closed,
                SUM(m.status='draft') meetings_draft,
                COALESCE(SUM((SELECT COUNT(*) FROM planning_chantier_meeting_items mi WHERE mi.meeting_id=m.id)),0) points_total,
                COALESCE(SUM((SELECT COUNT(*) FROM planning_chantier_meeting_participants mp WHERE mp.meeting_id=m.id AND mp.attendance_status='present')),0) presences_total
            FROM planning_chantier_meetings m
            LEFT JOIN planning_chantier_projects p ON p.id=m.project_id
            LEFT JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE m.archived_at IS NULL AND (m.project_id IS NULL OR (
                p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
            ))")->fetch(PDO::FETCH_ASSOC) ?: [];

        $pointRow = $this->pdo->query("SELECT
                COUNT(*) points,
                SUM(mi.statut='traite') points_closed,
                SUM(mi.statut IN ('ouvert','en_cours')) points_open
            FROM planning_chantier_meeting_items mi
            INNER JOIN planning_chantier_projects p ON p.id=mi.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL")->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'meetings_total' => (int)($row['meetings_total'] ?? 0),
            'meetings_month' => (int)($row['meetings_month'] ?? 0),
            'meetings_closed' => (int)($row['meetings_closed'] ?? 0),
            'meetings_draft' => (int)($row['meetings_draft'] ?? 0),
            'points_total' => (int)($pointRow['points'] ?? $row['points_total'] ?? 0),
            'points_closed' => (int)($pointRow['points_closed'] ?? 0),
            'points_open' => (int)($pointRow['points_open'] ?? 0),
            'presences_total' => (int)($row['presences_total'] ?? 0),
        ];
    }

    public function find(int $meetingId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT m.*, p.libelle project_label
            FROM planning_chantier_meetings m
            LEFT JOIN planning_chantier_projects p ON p.id = m.project_id
            LEFT JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE m.id=:id AND m.archived_at IS NULL AND (m.project_id IS NULL OR (
                p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
            )) LIMIT 1");
        $stmt->execute(['id' => $meetingId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findIncludingArchived(int $meetingId):?array
    {
        $stmt=$this->pdo->prepare("SELECT m.*,COALESCE(line_task.titre,p.libelle) project_label
            FROM planning_chantier_meetings m
            LEFT JOIN planning_chantier_projects p ON p.id=m.project_id
            LEFT JOIN planning_chantier_tasks line_task
              ON line_task.source_type='ligne_devis' AND line_task.source_id=m.office_service_line_id
            WHERE m.id=:id LIMIT 1");
        $stmt->execute(['id'=>$meetingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }

    public function archivedReports(int $limit=100):array
    {
        $stmt=$this->pdo->prepare("SELECT m.*,COALESCE(line_task.titre,p.libelle,'Réunion multi-chantiers') project_label,
                ((SELECT COUNT(*) FROM planning_chantier_meeting_items mi WHERE mi.meeting_id=m.id)
                 +(SELECT COUNT(*) FROM planning_chantier_meeting_action_points ap WHERE ap.preparation_id=m.preparation_id AND ap.archived_at IS NULL)
                 +(SELECT COUNT(*) FROM planning_chantier_meeting_custom_sections cs WHERE cs.preparation_id=m.preparation_id AND cs.archived_at IS NULL)) points_count,
                (SELECT COUNT(*) FROM planning_chantier_meeting_participants mp WHERE mp.meeting_id=m.id) participants_count
            FROM planning_chantier_meetings m
            LEFT JOIN planning_chantier_projects p ON p.id=m.project_id
            LEFT JOIN planning_chantier_tasks line_task
              ON line_task.source_type='ligne_devis' AND line_task.source_id=m.office_service_line_id
            WHERE m.archived_at IS NOT NULL AND m.status='closed'
            ORDER BY m.meeting_date DESC,m.id DESC LIMIT :limit");
        $stmt->bindValue('limit',$limit,PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function items(int $meetingId): array
    {
        $stmt = $this->pdo->prepare("SELECT mi.*, p.libelle project_label, p.code project_code, t.titre task_title
            FROM planning_chantier_meeting_items mi
            LEFT JOIN planning_chantier_projects p ON p.id = mi.project_id
            LEFT JOIN planning_chantier_tasks t ON t.id = mi.task_id
            LEFT JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE mi.meeting_id=:meeting_id
            ORDER BY mi.id ASC");
        $stmt->execute(['meeting_id' => $meetingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function participants(int $meetingId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM planning_chantier_meeting_participants WHERE meeting_id = :meeting_id ORDER BY id ASC");
        $stmt->execute(['meeting_id' => $meetingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function actionPoints(int $meetingId):array
    {
        return (new PlanningMeetingActionPointRepository($this->pdo))->forMeeting($meetingId);
    }

    /** Changements de libellé et suppressions depuis la dernière réunion de même périmètre. */
    public function actionPointChangesFromPrevious(int $meetingId): array
    {
        $current=$this->pdo->prepare('SELECT m.id,m.preparation_id,m.meeting_date,m.office_service_line_id,p.chantier_ids_json
            FROM planning_chantier_meetings m JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id WHERE m.id=:id LIMIT 1');
        $current->execute(['id'=>$meetingId]);$meeting=$current->fetch(PDO::FETCH_ASSOC)?:null;
        if(!$meeting)return ['previous'=>null,'removed'=>[],'renamed'=>[]];
        $currentSites=array_map('intval',json_decode((string)($meeting['chantier_ids_json']??'[]'),true)?:[]);
        $candidates=$this->pdo->prepare("SELECT m.id,m.preparation_id,m.meeting_date,m.office_service_line_id,p.chantier_ids_json
            FROM planning_chantier_meetings m JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id
            WHERE m.status='closed' AND m.id<>:id AND (m.meeting_date<:date OR (m.meeting_date=:date AND m.id<:id))
            ORDER BY m.meeting_date DESC,m.id DESC LIMIT 100");
        $candidates->execute(['id'=>$meetingId,'date'=>$meeting['meeting_date']]);
        $previous=null;
        foreach($candidates->fetchAll(PDO::FETCH_ASSOC)?:[] as $candidate){
            if((int)$meeting['office_service_line_id']>0){
                if((int)$candidate['office_service_line_id']===(int)$meeting['office_service_line_id']){$previous=$candidate;break;}
            }else{
                $sites=array_map('intval',json_decode((string)($candidate['chantier_ids_json']??'[]'),true)?:[]);
                if(array_intersect($currentSites,$sites)){$previous=$candidate;break;}
            }
        }
        if(!$previous)return ['previous'=>null,'removed'=>[],'renamed'=>[]];
        $load=$this->pdo->prepare("SELECT ap.chantier_id,ap.ligne_devis_id,ap.debourse_id,ap.ligne_debourse_id,ap.sort_order,ap.task_title,
            COALESCE(NULLIF(TRIM(ap.section_title),''),'Point') AS section_title
            FROM planning_chantier_meeting_action_points ap WHERE ap.preparation_id=:preparation AND ap.archived_at IS NULL ORDER BY ap.id");
        $load->execute(['preparation'=>(int)$previous['preparation_id']]);$old=$load->fetchAll(PDO::FETCH_ASSOC)?:[];
        $load->execute(['preparation'=>(int)$meeting['preparation_id']]);$now=$load->fetchAll(PDO::FETCH_ASSOC)?:[];
        $key=static fn(array $row):string=>implode('|',[(int)$row['chantier_id'],(int)$row['ligne_devis_id'],(int)$row['debourse_id'],(int)$row['ligne_debourse_id'],(int)$row['sort_order']]);
        $currentByKey=[];foreach($now as $row)$currentByKey[$key($row)]=$row;
        $removed=[];$renamed=[];
        foreach($old as $row){
            $match=$currentByKey[$key($row)]??null;
            if(!$match){$removed[]=$row;continue;}
            if(trim((string)$row['task_title'])!==trim((string)$match['task_title']))$renamed[]=['section_title'=>$match['section_title']?:$row['section_title'],'old_title'=>$row['task_title'],'new_title'=>$match['task_title']];
        }
        // Les « points libres » suivent la même traçabilité, leur position dans
        // l'ordre de réunion servant de clé stable lors de la reprise suivante.
        $custom=$this->pdo->prepare('SELECT chantier_id,ligne_devis_id,sort_order,title FROM planning_chantier_meeting_custom_sections WHERE preparation_id=:preparation AND archived_at IS NULL ORDER BY sort_order,id');
        $custom->execute(['preparation'=>(int)$previous['preparation_id']]);$oldCustom=$custom->fetchAll(PDO::FETCH_ASSOC)?:[];
        $custom->execute(['preparation'=>(int)$meeting['preparation_id']]);$nowCustom=$custom->fetchAll(PDO::FETCH_ASSOC)?:[];
        $customKey=static fn(array $row):string=>implode('|',[(int)($row['chantier_id']??0),(int)($row['ligne_devis_id']??0),(int)$row['sort_order']]);
        $nowCustomByKey=[];foreach($nowCustom as $row)$nowCustomByKey[$customKey($row)]=$row;
        foreach($oldCustom as $row){
            $match=$nowCustomByKey[$customKey($row)]??null;
            if(!$match){$removed[]=['section_title'=>'Point libre','task_title'=>$row['title']];continue;}
            if(trim((string)$row['title'])!==trim((string)$match['title']))$renamed[]=['section_title'=>'Point libre','old_title'=>$row['title'],'new_title'=>$match['title']];
        }
        return ['previous'=>$previous,'removed'=>$removed,'renamed'=>$renamed];
    }

    public function archive(int $meetingId,?int $userId):void
    {
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT preparation_id FROM planning_chantier_meetings WHERE id=:id LIMIT 1");
            $stmt->execute(['id'=>$meetingId]);$preparationId=(int)($stmt->fetchColumn()?:0);
            $this->pdo->prepare("UPDATE planning_chantier_meetings SET archived_at=NOW(),archived_by=:user,updated_at=NOW() WHERE id=:id")
                ->execute(['id'=>$meetingId,'user'=>$userId]);
            if($preparationId>0)$this->pdo->prepare("UPDATE planning_chantier_meeting_preparations SET
                status=IF(status='used',status,'cancelled'),archived_at=NOW(),archived_by=:user,updated_at=NOW() WHERE id=:id")
                ->execute(['id'=>$preparationId,'user'=>$userId]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function participantOptions(): array
    {
        $options = [];

        if ($this->tableExists('user_devis_banamur')) {
            $columns = $this->columns('user_devis_banamur');
            $nameExpr = "CONCAT('Utilisateur #', u.id)";
            foreach (['nom','name','username','login'] as $col) {
                if (in_array($col, $columns, true)) {
                    $nameExpr = "NULLIF(TRIM(u.`$col`), '')";
                    break;
                }
            }
            if (in_array('personnel_id', $columns, true) && $this->tableExists('personnel')) {
                $pcols = $this->columns('personnel');
                $pname = [];
                foreach (['nom_personnel_soignant','prenom_personnel_soignant','nom','prenom'] as $col) {
                    if (in_array($col, $pcols, true)) $pname[] = "p.`$col`";
                }
                if ($pname) {
                    $nameExpr = "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', " . implode(',', $pname) . ")), ''), $nameExpr)";
                }
            }
            $emailExpr = in_array('mail_pro', $columns, true) ? "u.mail_pro" : (in_array('email', $columns, true) ? "u.email" : "NULL");
            $join = (in_array('personnel_id', $columns, true) && $this->tableExists('personnel')) ? "LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id" : "";
            try {
                $rows = $this->pdo->query("SELECT u.id user_id, $nameExpr participant_name, $emailExpr participant_email FROM user_devis_banamur u $join ORDER BY participant_name ASC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $name = trim((string)($row['participant_name'] ?? ''));
                    if ($name === '') $name = 'Utilisateur #' . (int)($row['user_id'] ?? 0);
                    $options[] = [
                        'user_id' => (int)($row['user_id'] ?? 0),
                        'name' => $name,
                        'email' => trim((string)($row['participant_email'] ?? '')),
                    ];
                }
            } catch (Throwable) {}
        }

        return $options;
    }

    public function save(array $data): int
    {
        $this->pdo->beginTransaction();
        try {
            $isClosing = !empty($data['close_meeting']);
            $preparationId=(int)($data['preparation_id']??0);
            $existingId=0;
            if($preparationId>0){
                $find=$this->pdo->prepare("SELECT id FROM planning_chantier_meetings WHERE preparation_id=:preparation AND status='draft' AND archived_at IS NULL LIMIT 1 FOR UPDATE");
                $find->execute(['preparation'=>$preparationId]);$existingId=(int)($find->fetchColumn()?:0);
            }
            $params=[
                'project_id' => !empty($data['project_id']) ? (int)$data['project_id'] : null,
                'preparation_id' => $preparationId?:null,
                'meeting_date' => $data['meeting_date'] ?: date('Y-m-d'),
                'titre' => $data['titre'] ?: 'Réunion quotidienne de 12h',
                'resume' => trim((string)($data['resume'] ?? '')),
                'decisions' => trim((string)($data['decisions'] ?? '')),
                'participants_text' => trim((string)($data['participants_text'] ?? '')),
                'status' => $isClosing ? 'closed' : 'draft',
                'closed_at' => $isClosing ? date('Y-m-d H:i:s') : null,
                'closed_by' => $isClosing ? ($data['created_by'] ?? null) : null,
                'created_by' => $data['created_by'] ?? null,
            ];
            if($existingId>0){
                $updateParams=$params;
                unset($updateParams['created_by']);
                $updateParams['id']=$existingId;
                $stmt=$this->pdo->prepare("UPDATE planning_chantier_meetings SET
                    project_id=:project_id,meeting_date=:meeting_date,titre=:titre,resume=:resume,
                    decisions=:decisions,participants_text=:participants_text,status=:status,
                    closed_at=:closed_at,closed_by=:closed_by,updated_at=NOW()
                    WHERE id=:id AND preparation_id=:preparation_id");
                $stmt->execute($updateParams);
                $meetingId=$existingId;
                $this->pdo->prepare("DELETE FROM planning_chantier_meeting_items WHERE meeting_id=:id")->execute(['id'=>$meetingId]);
                $this->pdo->prepare("DELETE FROM planning_chantier_meeting_participants WHERE meeting_id=:id")->execute(['id'=>$meetingId]);
            }else{
                $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_meetings
                (project_id,preparation_id,meeting_date,titre,resume,decisions,participants_text,status,closed_at,closed_by,created_by,created_at)
                VALUES (:project_id,:preparation_id,:meeting_date,:titre,:resume,:decisions,:participants_text,:status,:closed_at,:closed_by,:created_by,NOW())");
                $stmt->execute($params);
                $meetingId=(int)$this->pdo->lastInsertId();
            }
            $this->saveItems($meetingId, $data);
            $this->saveParticipants($meetingId, $data);
            $this->assignReportIdentity($meetingId);
            $this->pdo->commit();
            return $meetingId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function ensureDraftForPreparation(array $preparation,?int $userId):int
    {
        $preparationId=(int)($preparation['id']??0);
        $find=$this->pdo->prepare("SELECT id FROM planning_chantier_meetings WHERE preparation_id=:id AND status='draft' AND archived_at IS NULL LIMIT 1");
        $find->execute(['id'=>$preparationId]);$id=(int)($find->fetchColumn()?:0);
        if($id>0)return $id;
        return $this->save([
            'preparation_id'=>$preparationId,
            'meeting_date'=>$preparation['meeting_date']??date('Y-m-d'),
            'titre'=>$preparation['titre']??'Réunion quotidienne de 12h',
            'resume'=>'','decisions'=>'','participants'=>$preparation['participants']??[],
            'created_by'=>$userId,'close_meeting'=>0,
        ]);
    }

    public function autosaveDraft(int $preparationId,string $resume,string $decisions):void
    {
        $this->pdo->prepare("UPDATE planning_chantier_meetings SET resume=:resume,decisions=:decisions,updated_at=NOW()
            WHERE preparation_id=:preparation AND status='draft' AND archived_at IS NULL")
            ->execute(['resume'=>trim($resume),'decisions'=>trim($decisions),'preparation'=>$preparationId]);
    }

    public function setPdfPath(int $meetingId, string $path): void
    {
        $stmt = $this->pdo->prepare("UPDATE planning_chantier_meetings SET pdf_path = :pdf_path WHERE id = :id");
        $stmt->execute(['pdf_path' => $path, 'id' => $meetingId]);
    }

    public function archiveSupersededOfficeReports(int $meetingId,?int $userId):int
    {
        $this->assignReportIdentity($meetingId);
        $stmt=$this->pdo->prepare("UPDATE planning_chantier_meetings previous
            JOIN planning_chantier_meetings current ON current.id=:current
            SET previous.archived_at=NOW(),previous.archived_by=:user,previous.updated_at=NOW()
            WHERE current.status='closed' AND current.office_service_line_id IS NOT NULL
              AND previous.office_service_line_id=current.office_service_line_id
              AND previous.id<>current.id AND previous.status='closed'
              AND previous.archived_at IS NULL");
        $stmt->execute(['current'=>$meetingId,'user'=>$userId]);
        return $stmt->rowCount();
    }

    public function performanceForMeeting(int $meetingId,bool $refresh=false):array
    {
        $stmt=$this->pdo->prepare("SELECT preparation_id,office_service_line_id,performance_json
            FROM planning_chantier_meetings WHERE id=:id LIMIT 1");
        $stmt->execute(['id'=>$meetingId]);
        $meeting=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$meeting)return [];
        $preparationId=(int)($meeting['preparation_id']??0);
        if($preparationId<=0)return [];
        $tasks=$this->pdo->prepare("SELECT pilot_user_id,pilot_name,copilot_user_id,copilot_name,progress,new_deadline
            FROM planning_chantier_meeting_action_points
            WHERE preparation_id=:preparation AND archived_at IS NULL AND status<>'cancelled'");
        $tasks->execute(['preparation'=>$preparationId]);
        $people=[];
        $add=static function(array &$people,int $id,string $name,float $progress,?string $deadline):void{
            if($id<=0)return;
            if(!isset($people[$id]))$people[$id]=['user_id'=>$id,'participant_name'=>$name?:('Utilisateur #'.$id),'tasks_count'=>0,'done_count'=>0,'in_progress_count'=>0,'late_count'=>0,'score_sum'=>0.0];
            $late=$deadline!==null&&$deadline!==''&&$deadline<date('Y-m-d')&&$progress<100;
            $score=$late?$progress*0.60:$progress;
            $people[$id]['tasks_count']++;$people[$id]['score_sum']+=$score;
            if($progress>=100)$people[$id]['done_count']++;
            elseif($late)$people[$id]['late_count']++;
            else $people[$id]['in_progress_count']++;
        };
        foreach($tasks->fetchAll(PDO::FETCH_ASSOC)?:[] as $task){
            $progress=(float)($task['progress']??0);$deadline=trim((string)($task['new_deadline']??''))?:null;
            $add($people,(int)($task['pilot_user_id']??0),(string)($task['pilot_name']??''),$progress,$deadline);
            $add($people,(int)($task['copilot_user_id']??0),(string)($task['copilot_name']??''),$progress,$deadline);
        }
        // Les tâches des points libres sont enregistrées en JSON mais font partie
        // intégrante de la réunion : elles doivent donc peser dans l'évaluation.
        $custom=$this->pdo->prepare('SELECT payload_json FROM planning_chantier_meeting_custom_sections WHERE preparation_id=:preparation AND archived_at IS NULL');
        $custom->execute(['preparation'=>$preparationId]);
        $names=[];
        foreach($custom->fetchAll(PDO::FETCH_COLUMN)?:[] as $json)foreach(json_decode((string)$json,true)?:[] as $task){
            $progress=(float)($task['progress']??0);$deadline=trim((string)($task['new_deadline']??''))?:null;
            foreach(['pilot_user_id','copilot_user_id'] as $field)if((int)($task[$field]??0)>0)$names[(int)$task[$field]]=true;
            $add($people,(int)($task['pilot_user_id']??0),'',$progress,$deadline);
            $add($people,(int)($task['copilot_user_id']??0),'',$progress,$deadline);
        }
        if($names){
            $in=implode(',',array_fill(0,count($names),'?'));
            $who=$this->pdo->prepare("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) name FROM user_devis_banamur WHERE id IN ($in)");
            $who->execute(array_keys($names));foreach($who->fetchAll(PDO::FETCH_ASSOC)?:[] as $user)if(isset($people[(int)$user['id']])&&trim((string)$user['name'])!=='')$people[(int)$user['id']]['participant_name']=$user['name'];
        }
        $performance=[];
        foreach($people as $person){$person['score']=$person['tasks_count']?round($person['score_sum']/$person['tasks_count'],1):0;$person['stars']=round($person['score']/20,1);unset($person['score_sum']);$performance[]=$person;}
        usort($performance,static fn(array $a,array $b):int=>($b['score']<=>$a['score'])?:strcmp((string)$a['participant_name'],(string)$b['participant_name']));
        $this->pdo->prepare("UPDATE planning_chantier_meetings SET performance_json=:performance WHERE id=:id")
            ->execute(['performance'=>json_encode($performance,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$meetingId]);
        return $performance;
    }

    private function assignReportIdentity(int $meetingId):void
    {
        $stmt=$this->pdo->prepare("UPDATE planning_chantier_meetings m
            LEFT JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id
            SET m.office_service_line_id=COALESCE(m.office_service_line_id,p.office_ligne_devis_id),
                m.report_code=CONCAT(IF(p.office_ligne_devis_id IS NULL,'CR-CH-','CR-BUR-'),
                    DATE_FORMAT(m.meeting_date,'%Y%m%d'),'-',
                    IF(p.office_ligne_devis_id IS NULL,'',CONCAT('S',p.office_ligne_devis_id,'-')),
                    LPAD(m.id,6,'0'))
            WHERE m.id=:id");
        $stmt->execute(['id'=>$meetingId]);
    }

    private function saveItems(int $meetingId, array $data): void
    {
        $points = $data['points'] ?? [];
        if (!is_array($points)) return;

        $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_meeting_items
            (meeting_id, project_id, task_id, sujet, constat, decision_text, responsable_id, echeance, statut, created_at)
            VALUES (:meeting_id, :project_id, :task_id, :sujet, :constat, :decision_text, :responsable_id, :echeance, :statut, NOW())");

        foreach ($points as $point) {
            if (!is_array($point)) continue;
            $sujet = trim((string)($point['sujet'] ?? ''));
            $constat = trim((string)($point['constat'] ?? ''));
            $decision = trim((string)($point['decision_text'] ?? ''));
            if ($sujet === '' && $constat === '' && $decision === '') continue;

            $projectId = (int)($point['project_id'] ?? 0);
            if ($projectId <= 0) $projectId = !empty($data['project_id']) ? (int)$data['project_id'] : 0;
            if ($projectId <= 0) continue;

            $stmt->execute([
                'meeting_id' => $meetingId,
                'project_id' => $projectId,
                'task_id' => !empty($point['task_id']) ? (int)$point['task_id'] : null,
                'sujet' => $sujet !== '' ? $sujet : 'Point de réunion',
                'constat' => $constat,
                'decision_text' => $decision,
                'responsable_id' => !empty($point['responsable_id']) ? (int)$point['responsable_id'] : null,
                'echeance' => !empty($point['echeance']) ? $point['echeance'] : null,
                'statut' => $point['statut'] ?? 'ouvert',
            ]);
        }
    }

    private function saveParticipants(int $meetingId, array $data): void
    {
        $participants = $data['participants'] ?? [];
        if (!is_array($participants)) $participants = [];

        $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_meeting_participants
            (meeting_id, user_id, participant_name, participant_email, role_label, attendance_status, notify_email, created_at)
            VALUES (:meeting_id, :user_id, :participant_name, :participant_email, :role_label, :attendance_status, :notify_email, NOW())");

        $seen = [];
        foreach ($participants as $participant) {
            if (!is_array($participant)) continue;
            $name = trim((string)($participant['name'] ?? ''));
            $email = trim((string)($participant['email'] ?? ''));
            if ($name === '' && $email === '') continue;
            $key = strtolower($email !== '' ? $email : $name);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $stmt->execute([
                'meeting_id' => $meetingId,
                'user_id' => !empty($participant['user_id']) ? (int)$participant['user_id'] : null,
                'participant_name' => $name !== '' ? $name : $email,
                'participant_email' => $email !== '' ? $email : null,
                'role_label' => trim((string)($participant['role_label'] ?? '')),
                'attendance_status' => $participant['attendance_status'] ?? 'present',
                'notify_email' => !empty($participant['notify_email']) ? 1 : 0,
            ]);
        }

        $freeText = trim((string)($data['participants_text'] ?? ''));
        if ($freeText !== '') {
            foreach (preg_split('/\R+|,|;/', $freeText) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $key = strtolower($line);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $email = filter_var($line, FILTER_VALIDATE_EMAIL) ? $line : null;
                $stmt->execute([
                    'meeting_id' => $meetingId,
                    'user_id' => null,
                    'participant_name' => $email ? $line : $line,
                    'participant_email' => $email,
                    'role_label' => '',
                    'attendance_status' => 'present',
                    'notify_email' => $email ? 1 : 0,
                ]);
            }
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE :table");
            $stmt->execute(['table' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function columns(string $table): array
    {
        try {
            return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
