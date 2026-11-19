<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if(class_exists('AccessManager')&&!AccessManager::hasPageAccess('planning_chantier/reunion.php')){
        throw new RuntimeException('Accès refusé.');
    }
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??''))){
        throw new RuntimeException('Session expirée.');
    }
    $preparationId=(int)($input['preparation_id']??0);
    $preparation=(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId);
    if(!$preparation||!empty($preparation['office_chantier_id'])){
        throw new DomainException('Cette action est réservée aux réunions chantier actives.');
    }
    $allowed=array_fill_keys(array_map('intval',$preparation['chantier_ids']??[]),true);
    $normalize=static fn(mixed $values):array=>array_values(array_unique(array_filter(
        array_map('intval',is_array($values)?$values:[]),
        static fn(int $id):bool=>$id>0
    )));
    $lineIds=$normalize($input['line_ids']??[]);
    $debourseIds=$normalize($input['debourse_ids']??[]);
    $checklistIds=$normalize($input['checklist_ids']??[]);
    if(!$lineIds&&!$debourseIds&&!$checklistIds)throw new DomainException('Sélectionnez au moins un élément.');

    $pdo->beginTransaction();
    $lineCheck=$pdo->prepare("SELECT p.chantier_id FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        WHERE t.source_type='ligne_devis' AND t.source_id=:id AND t.statut<>'annule' LIMIT 1");
    $lineInsert=$pdo->prepare("INSERT INTO planning_chantier_meeting_hidden_lines
        (preparation_id,ligne_devis_id,hidden_by,hidden_at) VALUES(:preparation,:id,:user,NOW())
        ON DUPLICATE KEY UPDATE hidden_by=VALUES(hidden_by),hidden_at=NOW()");
    foreach($lineIds as $id){
        $lineCheck->execute(['id'=>$id]);
        if(!isset($allowed[(int)$lineCheck->fetchColumn()]))throw new DomainException('Une ligne sélectionnée est hors du périmètre de la réunion.');
        $lineInsert->execute(['preparation'=>$preparationId,'id'=>$id,'user'=>planning_chantier_user_id()]);
    }
    $debourseCheck=$pdo->prepare("SELECT p.chantier_id FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        WHERE t.source_type='debourse' AND t.source_id=:id AND t.statut<>'annule' LIMIT 1");
    $debourseInsert=$pdo->prepare("INSERT INTO planning_chantier_meeting_hidden_debourses
        (preparation_id,debourse_id,hidden_by,hidden_at) VALUES(:preparation,:id,:user,NOW())
        ON DUPLICATE KEY UPDATE hidden_by=VALUES(hidden_by),hidden_at=NOW()");
    foreach($debourseIds as $id){
        $debourseCheck->execute(['id'=>$id]);
        if(!isset($allowed[(int)$debourseCheck->fetchColumn()]))throw new DomainException('Un déboursé sélectionné est hors du périmètre de la réunion.');
        $debourseInsert->execute(['preparation'=>$preparationId,'id'=>$id,'user'=>planning_chantier_user_id()]);
    }
    $checklistArchive=$pdo->prepare("UPDATE planning_chantier_meeting_action_point_checklist cl
        JOIN planning_chantier_meeting_action_points ap ON ap.id=cl.action_point_id
        SET cl.archived_at=NOW(),cl.archived_by=:user,cl.updated_at=NOW()
        WHERE cl.id=:id AND ap.preparation_id=:preparation AND cl.archived_at IS NULL AND ap.archived_at IS NULL");
    foreach($checklistIds as $id){
        $checklistArchive->execute(['user'=>planning_chantier_user_id(),'id'=>$id,'preparation'=>$preparationId]);
        if($checklistArchive->rowCount()!==1)throw new DomainException('Un élément de checklist est invalide.');
    }
    $pdo->commit();
    echo json_encode(['ok'=>true,'removed'=>count($lineIds)+count($debourseIds)+count($checklistIds)],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    if(isset($pdo)&&$pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
