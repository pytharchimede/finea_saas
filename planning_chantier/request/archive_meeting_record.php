<?php
declare(strict_types=1);
require_once __DIR__.'/../bootstrap.php';
try{
    if(class_exists('AccessManager'))AccessManager::requirePageAccess('planning_chantier/reunion.php');
    $id=(int)($_POST['id']??0);
    $type=(string)($_POST['type']??'');
    if($id<=0)throw new DomainException('Élément invalide.');
    if($type==='preparation')(new PlanningMeetingPreparationRepository($pdo))->archive($id,planning_chantier_user_id());
    elseif($type==='meeting')(new PlanningMeetingRepository($pdo))->archive($id,planning_chantier_user_id());
    else throw new DomainException('Type d’archive invalide.');
    pc_flash_set('success','Élément archivé.');
}catch(Throwable $e){pc_flash_set('error',$e->getMessage());}
pc_redirect('../dashboard.php');
