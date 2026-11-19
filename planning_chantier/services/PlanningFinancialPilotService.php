<?php
declare(strict_types=1);

/**
 * Pilotage 100 % autonome.
 *
 * Les lignes financières ne sont lues qu'une seule fois lors de la création du
 * snapshot initial d'un chantier. Toutes les lectures et écritures suivantes
 * utilisent exclusivement planning_chantier_projects et
 * planning_chantier_tasks.
 */
final class PlanningFinancialPilotService
{
    private const SNAPSHOT_FORMAT='visible_planning_v2';

    public function __construct(private PDO $pdo) {}

    public function projects():array
    {
        (new PlanningProjectRepository($this->pdo))->syncFromChantiers();
        return $this->pdo->query("SELECT c.id,c.code,c.titre,c.planning_is_office_hub
            FROM planning_chantier_projects p
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
            ORDER BY c.titre,c.code")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function hierarchy(int $chantierId):array
    {
        $project=$this->project($chantierId);
        $this->ensureSnapshot($project,null);
        $projectId=(int)$project['planning_project_id'];
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_tasks
            WHERE project_id=:project AND source_type='ligne_devis' AND statut<>'annule'
            ORDER BY ordre,id");
        $stmt->execute(['project'=>$projectId]);
        $lines=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $lineTask){
            $debStmt=$this->pdo->prepare("SELECT * FROM planning_chantier_tasks
                WHERE project_id=:project AND parent_id=:parent
                  AND source_type='debourse' AND statut<>'annule'
                ORDER BY ordre,id");
            $debStmt->execute(['project'=>$projectId,'parent'=>(int)$lineTask['id']]);
            $debourses=[];
            foreach($debStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $debTask){
                $detailStmt=$this->pdo->prepare("SELECT * FROM planning_chantier_tasks
                    WHERE project_id=:project AND parent_id=:parent
                      AND source_type='ligne_debourse' AND statut<>'annule'
                    ORDER BY ordre,id");
                $detailStmt->execute(['project'=>$projectId,'parent'=>(int)$debTask['id']]);
                $details=[];
                foreach($detailStmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $detailTask){
                    $details[]=[
                        'id'=>(int)$detailTask['source_id'],
                        'categorie'=>(string)($detailTask['snapshot_category']??''),
                        'designation'=>(string)$detailTask['titre'],
                        'montant'=>(float)$detailTask['poids'],
                        'date_debut'=>(string)($detailTask['date_debut_prevue']??''),
                        'date_fin'=>(string)($detailTask['date_fin_prevue']??''),
                        'planning_completed_at'=>$detailTask['statut']==='termine'?($detailTask['updated_at']?:$detailTask['created_at']):null,
                        'progress'=>(float)$detailTask['avancement_reel'],
                        'completed'=>$detailTask['statut']==='termine',
                    ];
                }
                $progress=$this->weightedProgress($details);
                $debourses[]=[
                    'id'=>(int)$debTask['source_id'],
                    'ligne_devis_id'=>(int)$lineTask['source_id'],
                    'montant_debourse'=>(float)$debTask['poids'],
                    'date_debut'=>(string)($debTask['date_debut_prevue']??''),
                    'date_fin'=>(string)($debTask['date_fin_prevue']??''),
                    'planning_comment'=>(string)($debTask['planning_comment']??''),
                    'title'=>(string)$debTask['titre'],
                    'details'=>$details,
                    'progress'=>$progress,
                    'completed'=>$progress>=100,
                ];
            }
            $lineProgress=$this->weightedProgress($debourses,'montant_debourse');
            $lines[]=[
                'id'=>(int)$lineTask['source_id'],
                'designation'=>(string)$lineTask['titre'],
                'total'=>(float)$lineTask['poids'],
                'planning_date_debut'=>(string)($lineTask['date_debut_prevue']??''),
                'planning_date_fin'=>(string)($lineTask['date_fin_prevue']??''),
                'planning_completed_at'=>$lineTask['statut']==='termine'?($lineTask['updated_at']?:$lineTask['created_at']):null,
                'debourses'=>$debourses,
                'progress'=>$lineProgress,
                'completed'=>$lineProgress>=100,
            ];
        }
        $excludedStmt=$this->pdo->prepare("SELECT COUNT(*) FROM planning_chantier_tasks
            WHERE project_id=:project AND source_type='debourse' AND statut='annule'");
        $excludedStmt->execute(['project'=>$projectId]);
        return [
            'project'=>$project,
            'lines'=>$lines,
            'progress'=>$this->weightedProgress($lines,'total'),
            'excluded_debourses'=>(int)$excludedStmt->fetchColumn(),
            'autonomous_snapshot'=>true,
        ];
    }

    public function load(int $chantierId,?int $userId):array
    {
        $project=$this->project($chantierId);
        $this->ensureSnapshot($project,$userId);
        return $this->hierarchy($chantierId);
    }

    public function update(array $input,?int $userId):array
    {
        $type=(string)($input['type']??'');
        $sourceId=(int)($input['id']??0);
        $sourceType=$this->sourceType($type);
        if($sourceId<=0||$sourceType==='')throw new DomainException('Élément de planning invalide.');
        $task=$this->task($sourceType,$sourceId);
        $this->pdo->beginTransaction();
        try{
            if($type==='detail')$this->toggleTask((int)$task['id'],!empty($input['completed']),$userId,true);
            elseif($type==='debourse')$this->toggleBranch((int)$task['id'],!empty($input['completed']),$userId);
            elseif($type==='line'){
                if(array_key_exists('completed',$input))$this->toggleBranch((int)$task['id'],!empty($input['completed']),$userId);
                if(isset($input['start_date'])||isset($input['end_date']))$this->updateLineDates($task,(string)($input['start_date']??''),(string)($input['end_date']??''));
            }elseif($type==='debourse_dates')$this->updateDebourseDates($task,(string)($input['start_date']??''),(string)($input['end_date']??''));
            elseif($type==='debourse_comment')$this->updateDebourseComment($task,(string)($input['comment']??''),$userId);
            else throw new DomainException('Action de planning inconnue.');
            $chantierId=$this->chantierIdForTask($task);
            $this->pdo->commit();
            return $this->hierarchy($chantierId);
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureSnapshot(array $project,?int $userId):void
    {
        $projectId=(int)$project['planning_project_id'];
        $check=$this->pdo->prepare("SELECT source_counts_json
            FROM planning_chantier_structure_snapshots WHERE project_id=:project LIMIT 1");
        $check->execute(['project'=>$projectId]);
        $existingSnapshot=$check->fetchColumn();
        if($existingSnapshot!==false){
            $metadata=json_decode((string)$existingSnapshot,true);
            if(is_array($metadata)&&($metadata['format']??null)===self::SNAPSHOT_FORMAT)return;
        }
        $upgradingLegacySnapshot=$existingSnapshot!==false;

        $chantierStmt=$this->pdo->prepare("SELECT devis_id FROM chantier_banamur WHERE id=:id AND archived_at IS NULL LIMIT 1");
        $chantierStmt->execute(['id'=>(int)$project['id']]);
        $devisId=(int)$chantierStmt->fetchColumn();
        if($devisId<=0){
            $this->recordEmptySnapshot($projectId,(int)$project['id'],$userId);
            return;
        }

        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $hasLinePlanningDates=$this->hasColumn('ligne_devis_banamur','planning_date_debut')
                &&$this->hasColumn('ligne_devis_banamur','planning_date_fin');
            $hasLineCompletion=$this->hasColumn('ligne_devis_banamur','planning_completed_at');
            $hasDebourseCompletion=$this->hasColumn('debourse_banamur','planning_completed_at');
            $hasDebourseComment=$this->hasColumn('debourse_banamur','planning_comment');
            $hasDebourseArchived=$this->hasColumn('debourse_banamur','planning_archived_at');
            $hasDetailCompletion=$this->hasColumn('ligne_debourse_banamur','planning_completed_at');
            $hasDetailArchived=$this->hasColumn('ligne_debourse_banamur','planning_archived_at');
            $activeDebourseJoin=$hasDebourseArchived?' AND db.planning_archived_at IS NULL':'';
            $lineStartExpr=$hasLinePlanningDates
                ?'COALESCE(ld.planning_date_debut,MIN(db.date_debut))'
                :'MIN(db.date_debut)';
            $lineEndExpr=$hasLinePlanningDates
                ?'COALESCE(ld.planning_date_fin,MAX(db.date_fin))'
                :'MAX(db.date_fin)';
            $lineProgressExpr=$hasLineCompletion
                ?"CASE WHEN ld.planning_completed_at IS NOT NULL THEN 100 ELSE 0 END"
                :'0';
            $lineStmt=$this->pdo->prepare("SELECT ld.id,ld.designation,
                    COALESCE(ld.total,ld.prix*ld.quantite,ld.prix,0) total,
                    $lineStartExpr date_debut,$lineEndExpr date_fin,
                    $lineProgressExpr initial_progress
                FROM ligne_devis_banamur ld
                LEFT JOIN debourse_banamur db
                  ON db.ligne_devis_id=ld.id AND db.devis_id=ld.devis_id$activeDebourseJoin
                WHERE ld.devis_id=:devis
                GROUP BY ld.id,ld.designation,ld.total,ld.prix,ld.quantite"
                .($hasLinePlanningDates?',ld.planning_date_debut,ld.planning_date_fin':'')
                .($hasLineCompletion?',ld.planning_completed_at':'')."
                ORDER BY ld.id");
            $lineStmt->execute(['devis'=>$devisId]);
            $lines=$lineStmt->fetchAll(PDO::FETCH_ASSOC)?:[];

            $debProgressExpr=$hasDebourseCompletion
                ?"CASE WHEN db.planning_completed_at IS NOT NULL THEN 100 ELSE 0 END"
                :'0';
            $debCommentExpr=$hasDebourseComment?'db.planning_comment':"''";
            $debStmt=$this->pdo->prepare("SELECT db.id,db.ligne_devis_id,db.montant_debourse,
                    db.date_debut,db.date_fin,db.motif_modif,ld.designation line_title,
                    $debProgressExpr initial_progress,$debCommentExpr initial_comment,
                    COUNT(ldb.id) child_count,
                    CASE WHEN db.date_debut IS NOT NULL AND db.date_fin IS NOT NULL THEN 1 ELSE 0 END has_dates,
                    CASE WHEN db.montant_debourse>0 THEN 1 ELSE 0 END has_amount
                FROM debourse_banamur db
                LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
                LEFT JOIN ligne_debourse_banamur ldb ON ldb.debourse_id=db.id
                WHERE db.devis_id=:devis"
                .($hasDebourseArchived?' AND db.planning_archived_at IS NULL':'')."
                GROUP BY db.id,db.ligne_devis_id,db.montant_debourse,db.date_debut,db.date_fin,db.motif_modif,ld.designation"
                .($hasDebourseCompletion?',db.planning_completed_at':'')
                .($hasDebourseComment?',db.planning_comment':'')."
                ORDER BY db.ligne_devis_id,child_count DESC,has_dates DESC,has_amount DESC,db.id DESC");
            $debStmt->execute(['devis'=>$devisId]);
            $debourseRows=$debStmt->fetchAll(PDO::FETCH_ASSOC)?:[];

            $lineTaskIds=[];$debTaskIds=[];$detailCount=0;
            foreach($lines as $index=>$line){
                $lineTaskIds[(int)$line['id']]=$this->insertSnapshotTask([
                    'project_id'=>$projectId,'parent_id'=>null,'titre'=>(string)$line['designation'],
                    'ordre'=>$index,'start'=>$line['date_debut'],'end'=>$line['date_fin'],
                    'weight'=>(float)$line['total'],'source_type'=>'ligne_devis','source_id'=>(int)$line['id'],
                    'category'=>null,'user'=>$userId,'progress'=>(float)($line['initial_progress']??0),'comment'=>null,
                ]);
            }
            foreach($debourseRows as $index=>$debourse){
                $lineId=(int)$debourse['ligne_devis_id'];
                if(!isset($lineTaskIds[$lineId]))continue;
                $title=$this->debourseSnapshotTitle($debourse);
                $debTaskId=$this->insertSnapshotTask([
                    'project_id'=>$projectId,'parent_id'=>$lineTaskIds[$lineId],'titre'=>$title,
                    'ordre'=>$index,'start'=>$debourse['date_debut'],'end'=>$debourse['date_fin'],
                    'weight'=>(float)$debourse['montant_debourse'],'source_type'=>'debourse',
                    'source_id'=>(int)$debourse['id'],'category'=>null,'user'=>$userId,
                    'progress'=>(float)($debourse['initial_progress']??0),
                    'comment'=>(string)($debourse['initial_comment']??''),
                ]);
                $debTaskIds[(int)$debourse['id']]=$debTaskId;
                $detailProgressExpr=$hasDetailCompletion
                    ?"CASE WHEN planning_completed_at IS NOT NULL THEN 100 ELSE 0 END"
                    :'0';
                $details=$this->pdo->prepare("SELECT id,categorie,designation,montant,date_debut,date_fin,
                        $detailProgressExpr initial_progress
                    FROM ligne_debourse_banamur WHERE debourse_id=:debourse"
                    .($hasDetailArchived?' AND planning_archived_at IS NULL':'')."
                    ORDER BY id");
                $details->execute(['debourse'=>(int)$debourse['id']]);
                foreach($details->fetchAll(PDO::FETCH_ASSOC)?:[] as $detailIndex=>$detail){
                    $this->insertSnapshotTask([
                        'project_id'=>$projectId,'parent_id'=>$debTaskId,'titre'=>(string)$detail['designation'],
                        'ordre'=>$detailIndex,'start'=>$detail['date_debut']?:$debourse['date_debut'],
                        'end'=>$detail['date_fin']?:$debourse['date_fin'],'weight'=>(float)$detail['montant'],
                        'source_type'=>'ligne_debourse','source_id'=>(int)$detail['id'],
                        'category'=>(string)$detail['categorie'],'user'=>$userId,
                        'progress'=>(float)($detail['initial_progress']??0),'comment'=>null,
                    ]);
                    $detailCount++;
                }
            }
            if($upgradingLegacySnapshot&&$hasDebourseArchived){
                $this->pdo->prepare("UPDATE planning_chantier_tasks task
                    JOIN debourse_banamur db
                      ON task.source_type='debourse' AND task.source_id=db.id
                    SET task.statut='annule',task.updated_at=NOW()
                    WHERE task.project_id=:project AND db.planning_archived_at IS NOT NULL")
                    ->execute(['project'=>$projectId]);
            }
            if($upgradingLegacySnapshot&&$hasDetailArchived){
                $this->pdo->prepare("UPDATE planning_chantier_tasks task
                    JOIN ligne_debourse_banamur detail
                      ON task.source_type='ligne_debourse' AND task.source_id=detail.id
                    SET task.statut='annule',task.updated_at=NOW()
                    WHERE task.project_id=:project AND detail.planning_archived_at IS NOT NULL")
                    ->execute(['project'=>$projectId]);
            }
            $counts=[
                'format'=>self::SNAPSHOT_FORMAT,
                'lines'=>count($lineTaskIds),
                'debourses'=>count($debTaskIds),
                'details'=>$detailCount,
            ];
            $snapshot=$this->pdo->prepare("INSERT INTO planning_chantier_structure_snapshots
                (project_id,chantier_id,source_devis_id,captured_by,source_counts_json)
                VALUES(:project,:chantier,:devis,:user,:counts)
                ON DUPLICATE KEY UPDATE
                    source_devis_id=VALUES(source_devis_id),
                    captured_at=NOW(),
                    captured_by=COALESCE(captured_by,VALUES(captured_by)),
                    source_counts_json=VALUES(source_counts_json)");
            $snapshot->execute([
                'project'=>$projectId,'chantier'=>(int)$project['id'],'devis'=>$devisId,
                'user'=>$userId,'counts'=>json_encode($counts,JSON_UNESCAPED_UNICODE),
            ]);
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function insertSnapshotTask(array $row):int
    {
        $find=$this->pdo->prepare("SELECT id FROM planning_chantier_tasks
            WHERE source_type=:type AND source_id=:source LIMIT 1");
        $find->execute(['type'=>$row['source_type'],'source'=>$row['source_id']]);
        $existing=(int)$find->fetchColumn();
        if($existing>0){
            $this->pdo->prepare("UPDATE planning_chantier_tasks SET
                    parent_id=COALESCE(parent_id,:parent),
                    snapshot_category=COALESCE(snapshot_category,:category),
                    planning_comment=COALESCE(planning_comment,NULLIF(:comment,''))
                WHERE id=:id")->execute([
                    'parent'=>$row['parent_id'],'category'=>$row['category'],
                    'comment'=>(string)($row['comment']??''),'id'=>$existing,
                ]);
            return $existing;
        }
        $progress=max(0,min(100,(float)($row['progress']??0)));
        $status=$progress>=100?'termine':($progress>0?'en_cours':'a_faire');
        $stmt=$this->pdo->prepare("INSERT INTO planning_chantier_tasks
            (project_id,parent_id,titre,ordre,date_debut_prevue,date_fin_prevue,poids,avancement_prevu,
             avancement_reel,statut,responsable_id,created_by,source_type,source_id,snapshot_category,
             planning_comment,created_at)
            VALUES(:project,:parent,:title,:ordre,:start,:end,:weight,0,:progress,:status,NULL,:user,
                   :type,:source,:category,NULLIF(:comment,''),NOW())");
        $stmt->execute([
            'project'=>$row['project_id'],'parent'=>$row['parent_id'],'title'=>$row['titre'],
            'ordre'=>$row['ordre'],'start'=>$row['start']?:null,'end'=>$row['end']?:null,
            'weight'=>max(0,(float)$row['weight']),'user'=>$row['user'],'type'=>$row['source_type'],
            'source'=>$row['source_id'],'category'=>$row['category'],'progress'=>$progress,
            'status'=>$status,'comment'=>(string)($row['comment']??''),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function recordEmptySnapshot(int $projectId,int $chantierId,?int $userId):void
    {
        $stmt=$this->pdo->prepare("INSERT INTO planning_chantier_structure_snapshots
            (project_id,chantier_id,captured_by,source_counts_json)
            VALUES(:project,:chantier,:user,:counts)
            ON DUPLICATE KEY UPDATE source_counts_json=VALUES(source_counts_json)");
        $stmt->execute([
            'project'=>$projectId,
            'chantier'=>$chantierId,
            'user'=>$userId,
            'counts'=>json_encode(['format'=>self::SNAPSHOT_FORMAT,'lines'=>0,'debourses'=>0,'details'=>0]),
        ]);
    }

    private function debourseSnapshotTitle(array $debourse):string
    {
        $motif=trim((string)($debourse['motif_modif']??''));
        if($motif!==''&&!preg_match('/^(FEB #|Modifi|Mise à jour|Créé)/iu',$motif))return $motif;
        return trim((string)($debourse['line_title']??''))?:('Déboursé #'.(int)$debourse['id']);
    }

    private function sourceType(string $type):string
    {
        return match($type){
            'detail'=>'ligne_debourse',
            'debourse','debourse_dates','debourse_comment'=>'debourse',
            'line'=>'ligne_devis',
            default=>'',
        };
    }

    private function task(string $type,int $sourceId):array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM planning_chantier_tasks
            WHERE source_type=:type AND source_id=:source AND statut<>'annule' LIMIT 1");
        $stmt->execute(['type'=>$type,'source'=>$sourceId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new DomainException('Élément autonome du planning introuvable.');
        return $row;
    }

    private function toggleTask(int $taskId,bool $completed,?int $userId,bool $refreshParents=false):void
    {
        $this->pdo->prepare("UPDATE planning_chantier_tasks
            SET avancement_reel=:progress,statut=:status,responsable_id=COALESCE(responsable_id,:user),updated_at=NOW()
            WHERE id=:id")->execute([
                'progress'=>$completed?100:0,'status'=>$completed?'termine':'a_faire','user'=>$userId,'id'=>$taskId,
            ]);
        if($refreshParents)$this->refreshParents($taskId);
    }

    private function toggleBranch(int $taskId,bool $completed,?int $userId):void
    {
        $children=$this->pdo->prepare("SELECT id FROM planning_chantier_tasks WHERE parent_id=:parent AND statut<>'annule'");
        $children->execute(['parent'=>$taskId]);
        foreach($children->fetchAll(PDO::FETCH_COLUMN)?:[] as $childId)$this->toggleBranch((int)$childId,$completed,$userId);
        $this->toggleTask($taskId,$completed,$userId,false);
        $this->refreshParents($taskId);
    }

    private function refreshParents(int $taskId):void
    {
        $parentStmt=$this->pdo->prepare("SELECT parent_id FROM planning_chantier_tasks WHERE id=:id");
        $parentStmt->execute(['id'=>$taskId]);
        $parentId=(int)$parentStmt->fetchColumn();
        if($parentId<=0)return;
        $children=$this->pdo->prepare("SELECT poids,avancement_reel FROM planning_chantier_tasks
            WHERE parent_id=:parent AND statut<>'annule'");
        $children->execute(['parent'=>$parentId]);
        $rows=$children->fetchAll(PDO::FETCH_ASSOC)?:[];
        $progress=$this->weightedProgress($rows,'poids','avancement_reel');
        $status=$progress>=100?'termine':($progress>0?'en_cours':'a_faire');
        $this->pdo->prepare("UPDATE planning_chantier_tasks SET avancement_reel=:progress,statut=:status,updated_at=NOW()
            WHERE id=:id")->execute(['progress'=>$progress,'status'=>$status,'id'=>$parentId]);
        $this->refreshParents($parentId);
    }

    private function updateLineDates(array $task,string $start,string $end):void
    {
        $this->assertDates($start,$end);
        $this->pdo->prepare("UPDATE planning_chantier_tasks
            SET date_debut_prevue=:start,date_fin_prevue=:end,updated_at=NOW() WHERE id=:id")
            ->execute(['start'=>$start,'end'=>$end,'id'=>(int)$task['id']]);
        $projectId=(int)$task['project_id'];
        $this->pdo->prepare("UPDATE planning_chantier_projects SET
                date_debut=(SELECT MIN(date_debut_prevue) FROM planning_chantier_tasks
                    WHERE project_id=planning_chantier_projects.id AND source_type='ligne_devis' AND statut<>'annule'),
                date_fin_prevue=(SELECT MAX(date_fin_prevue) FROM planning_chantier_tasks
                    WHERE project_id=planning_chantier_projects.id AND source_type='ligne_devis' AND statut<>'annule'),
                updated_at=NOW()
            WHERE id=:project")->execute(['project'=>$projectId]);
    }

    private function updateDebourseDates(array $task,string $start,string $end):void
    {
        $this->assertDates($start,$end);
        $parent=$this->pdo->prepare("SELECT date_debut_prevue,date_fin_prevue FROM planning_chantier_tasks WHERE id=:id");
        $parent->execute(['id'=>(int)$task['parent_id']]);
        $line=$parent->fetch(PDO::FETCH_ASSOC)?:[];
        if((!empty($line['date_debut_prevue'])&&$start<$line['date_debut_prevue'])
            ||(!empty($line['date_fin_prevue'])&&$end>$line['date_fin_prevue'])){
            throw new DomainException('Le point doit rester dans les dates de sa section de planning.');
        }
        $this->pdo->prepare("UPDATE planning_chantier_tasks SET
            date_debut_prevue=:start,date_fin_prevue=:end,updated_at=NOW()
            WHERE id=:task_id OR parent_id=:parent_task_id")->execute([
                'start'=>$start,'end'=>$end,
                'task_id'=>(int)$task['id'],'parent_task_id'=>(int)$task['id'],
            ]);
    }

    private function updateDebourseComment(array $task,string $comment,?int $userId):void
    {
        $comment=trim($comment);
        if(mb_strlen($comment)>5000)throw new DomainException('Le commentaire est trop long.');
        $this->pdo->prepare("UPDATE planning_chantier_tasks SET
            planning_comment=NULLIF(:comment,''),planning_comment_updated_at=NOW(),
            planning_comment_updated_by=:user,updated_at=NOW() WHERE id=:id")
            ->execute(['comment'=>$comment,'user'=>$userId,'id'=>(int)$task['id']]);
    }

    private function assertDates(string $start,string $end):void
    {
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$start)
            ||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$end)||$end<$start){
            throw new DomainException('Intervalle de dates invalide.');
        }
    }

    private function weightedProgress(array $rows,string $weightKey='montant',string $progressKey='progress'):float
    {
        if(!$rows)return 0.0;
        $total=0.0;$done=0.0;
        foreach($rows as $row){
            $weight=max(.01,(float)($row[$weightKey]??0));
            $progress=(float)($row[$progressKey]??(!empty($row['completed'])?100:0));
            $total+=$weight;$done+=$weight*$progress/100;
        }
        return $total>0?round($done/$total*100,2):0.0;
    }

    private function project(int $chantierId):array
    {
        $repository=new PlanningProjectRepository($this->pdo);
        $repository->syncFromChantiers();
        $stmt=$this->pdo->prepare("SELECT c.id,c.code,c.titre,c.planning_is_office_hub is_office,
                p.id planning_project_id,p.date_debut project_start_date,p.date_fin_prevue project_end_date
            FROM planning_chantier_projects p
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE c.id=:id AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL LIMIT 1");
        $stmt->execute(['id'=>$chantierId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('Chantier de planning introuvable.');
        return $row;
    }

    private function chantierIdForTask(array $task):int
    {
        $stmt=$this->pdo->prepare("SELECT chantier_id FROM planning_chantier_projects WHERE id=:project LIMIT 1");
        $stmt->execute(['project'=>(int)$task['project_id']]);
        $chantierId=(int)$stmt->fetchColumn();
        if($chantierId<=0)throw new RuntimeException('Chantier autonome lié introuvable.');
        return $chantierId;
    }

    private function hasColumn(string $table,string $column):bool
    {
        $stmt=$this->pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column");
        $stmt->execute(['table'=>$table,'column'=>$column]);
        return (int)$stmt->fetchColumn()>0;
    }
}
