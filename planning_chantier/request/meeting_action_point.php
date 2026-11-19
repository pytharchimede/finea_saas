<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
try{
    if(class_exists('AccessManager')&&!AccessManager::hasPageAccess('planning_chantier/reunion.php'))throw new RuntimeException('Accès refusé.');
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    $repository=new PlanningMeetingActionPointRepository($pdo);
    $action=(string)($input['action']??'save');
    if($action==='toggle')$groups=$repository->toggle($input,planning_chantier_user_id());
    elseif($action==='point_reorder')$groups=$repository->reorderPoints($input,planning_chantier_user_id());
    elseif($action==='checklist_add')$groups=$repository->addChecklistItem($input,planning_chantier_user_id());
    elseif($action==='checklist_toggle')$groups=$repository->toggleChecklistItem($input,planning_chantier_user_id());
    elseif($action==='checklist_progress')$groups=$repository->updateChecklistProgress($input,planning_chantier_user_id());
    elseif($action==='checklist_reorder')$groups=$repository->reorderChecklistItems($input,planning_chantier_user_id());
    else $groups=$repository->save($input,planning_chantier_user_id());
    $lineLookup=$pdo->prepare("SELECT line_task.source_id
        FROM planning_chantier_tasks deb_task
        JOIN planning_chantier_tasks line_task ON line_task.id=deb_task.parent_id
        WHERE deb_task.source_type='debourse' AND deb_task.source_id=:debourse
          AND line_task.source_type='ligne_devis' LIMIT 1");
    $lineLookup->execute(['debourse'=>(int)($input['debourse_id']??0)]);
    $lineId=(int)$lineLookup->fetchColumn();
    $performance=$repository->performance((int)($input['preparation_id']??0),$lineId);
    echo json_encode(['ok'=>true,'groups'=>$groups,'performance'=>$performance],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
