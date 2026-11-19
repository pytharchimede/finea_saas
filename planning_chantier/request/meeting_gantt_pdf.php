<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../services/PlanningMeetingGanttPdfService.php';

try {
    if (class_exists('AccessManager')
        && !AccessManager::hasPageAccess('planning_chantier/reunion.php')
        && !AccessManager::hasPageAccess('planning_chantier/dashboard.php')) {
        throw new DomainException('Accès refusé.');
    }
    $preparationId=(int)($_GET['preparation_id']??0);
    $lineId=(int)($_GET['line_id']??0); $sectionId=(int)($_GET['section_id']??0); $chantierId=(int)($_GET['chantier_id']??0);
    $debourseId=(int)($_GET['debourse_id']??0); $pointId=(int)($_GET['point_id']??0); $all=isset($_GET['all']);
    $preparation=(new PlanningMeetingPreparationRepository($pdo))->findForConsultation($preparationId);
    if(!$preparation||(!$all&&$lineId<=0&&$sectionId<=0&&$debourseId<=0&&$pointId<=0)) throw new DomainException('Point de réunion invalide.');
    if($lineId>0){
        $scope=$pdo->prepare("SELECT p.chantier_id FROM planning_chantier_tasks t
            JOIN planning_chantier_projects p ON p.id=t.project_id
            WHERE t.source_type='ligne_devis' AND t.source_id=:line AND t.statut<>'annule' LIMIT 1");
        $scope->execute(['line'=>$lineId]);
        if(!in_array((int)$scope->fetchColumn(),array_map('intval',$preparation['chantier_ids']??[]),true)){
            throw new DomainException('Cette ligne ne fait pas partie de la réunion.');
        }
    }
    if($chantierId>0&&!in_array($chantierId,array_map('intval',$preparation['chantier_ids']??[]),true))throw new DomainException('Ce chantier ne fait pas partie de la réunion.');
    (new PlanningMeetingGanttPdfService($pdo))->output($preparationId,$lineId,$sectionId,$debourseId,$pointId,$all,$chantierId);
} catch(Throwable $e) {
    http_response_code(422); header('Content-Type: text/plain; charset=utf-8'); echo $e->getMessage();
}
