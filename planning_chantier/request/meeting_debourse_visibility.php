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
    $debourseId=(int)($input['debourse_id']??0);
    $preparation=(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId);
    if(!$preparation||$debourseId<=0)throw new DomainException('Réunion ou déboursé invalide.');
    $allowed=array_map('intval',$preparation['chantier_ids']??[]);
    $stmt=$pdo->prepare("SELECT p.chantier_id
        FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        JOIN chantier_banamur c ON c.id=p.chantier_id
        WHERE t.source_type='debourse' AND t.source_id=:debourse AND t.statut<>'annule'
          AND c.archived_at IS NULL AND c.closed_date IS NULL LIMIT 1");
    $stmt->execute(['debourse'=>$debourseId]);
    if(!in_array((int)$stmt->fetchColumn(),$allowed,true))throw new DomainException('Ce déboursé ne fait pas partie de la réunion.');
    $pdo->prepare("INSERT INTO planning_chantier_meeting_hidden_debourses
        (preparation_id,debourse_id,hidden_by,hidden_at)
        VALUES(:preparation,:debourse,:user,NOW())
        ON DUPLICATE KEY UPDATE hidden_by=VALUES(hidden_by),hidden_at=NOW()")->execute([
            'preparation'=>$preparationId,
            'debourse'=>$debourseId,
            'user'=>planning_chantier_user_id(),
        ]);
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
