<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

try {
    $_POST['created_by'] = planning_chantier_user_id();
    $preparationId=(int)($_POST['preparation_id']??0);
    $preparationRepo=new PlanningMeetingPreparationRepository($pdo);
    $preparation=$preparationRepo->findReady($preparationId);
    if(!$preparation)throw new DomainException('La salle est fermée : préparation valide introuvable.');
    $_POST['participants']=array_merge(
        is_array($_POST['participants']??null)?$_POST['participants']:[],
        $preparation['participants']??[]
    );
    $_POST['preparation_id']=$preparationId;

    $repo = new PlanningMeetingRepository($pdo);
    $service = new PlanningMeetingService(new PlanningProjectRepository($pdo), new PlanningTaskRepository($pdo), new PlanningBlockageRepository($pdo), $repo);
    $controller = new PlanningMeetingController($service, $repo);
    $meetingId = $controller->save($_POST);

    if (!empty($_POST['close_meeting'])) {
        $repo->performanceForMeeting($meetingId,true);
        $pdfService = new PlanningMeetingPdfService($repo);
        $pdfPath = $pdfService->savePdfOrHtml(
            $meetingId,
            dirname(__DIR__,2).'/uploads/planning_chantier/generated_reports'
        );
        $repo->setPdfPath($meetingId, $pdfPath);
        $repo->archiveSupersededOfficeReports($meetingId,planning_chantier_user_id());

        $notif = new PlanningMeetingNotificationService($pdo, $repo);
        $stats = $notif->notifyClosedMeeting($meetingId, $pdfPath);
        $preparationRepo->markUsed($preparationId,$meetingId);

        pc_flash_set('success', 'Réunion clôturée. Emails envoyés : ' . (int)$stats['email_sent'] . '. Notifications système : ' . (int)$stats['system_sent'] . '.');
        pc_redirect('../exports/meeting_pdf.php?id=' . $meetingId);
    }

    pc_flash_set('success', 'Points de réunion enregistrés en brouillon.');
} catch (Throwable $e) {
    pc_flash_set('error', $e->getMessage());
}
pc_redirect('../reunion.php?preparation_id='.(int)($_POST['preparation_id']??0));
