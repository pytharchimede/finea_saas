<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

try {
    if(class_exists('AccessManager')&&!AccessManager::hasPageAccess('planning_chantier/reunion.php'))throw new RuntimeException('Accès refusé.');
    $input=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($input))$input=$_POST;
    if(!hash_equals((string)($_SESSION['pc_financial_csrf']??''),(string)($input['csrf']??'')))throw new RuntimeException('Session expirée.');
    $preparationId=(int)($input['preparation_id']??0);
    $lineId=(int)($input['ligne_devis_id']??0);
    $label=trim((string)($input['label']??''));
    if($label===''||mb_strlen($label)>255)throw new DomainException('Saisissez un libellé de réunion valide.');
    $preparation=(new PlanningMeetingPreparationRepository($pdo))->findReady($preparationId);
    if(!$preparation||!empty($preparation['office_chantier_id']))throw new DomainException('Réunion chantier invalide.');
    $allowed=array_fill_keys(array_map('intval',$preparation['chantier_ids']??[]),true);
    $check=$pdo->prepare("SELECT p.chantier_id FROM planning_chantier_tasks t
        JOIN planning_chantier_projects p ON p.id=t.project_id
        WHERE t.source_type='ligne_devis' AND t.source_id=:line AND t.statut<>'annule' LIMIT 1");
    $check->execute(['line'=>$lineId]);
    if(!isset($allowed[(int)$check->fetchColumn()]))throw new DomainException('Cette ligne ne fait pas partie de la réunion.');
    $pdo->prepare("INSERT INTO planning_chantier_meeting_line_overrides
        (preparation_id,ligne_devis_id,meeting_label,updated_by,updated_at)
        VALUES(:preparation,:line,:label,:user,NOW())
        ON DUPLICATE KEY UPDATE meeting_label=VALUES(meeting_label),updated_by=VALUES(updated_by),updated_at=NOW()")->execute([
            'preparation'=>$preparationId,'line'=>$lineId,'label'=>$label,'user'=>planning_chantier_user_id(),
        ]);
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
