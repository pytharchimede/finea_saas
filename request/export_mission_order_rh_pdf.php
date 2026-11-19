<?php
require_once __DIR__.'/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../controllers/RhMissionController.php';
require_once __DIR__.'/../services/MissionOrderPdfService.php';
$id=max(0,(int)($_GET['id']??0));if(!$id){http_response_code(400);exit('ID invalide.');}
$manager=(new RhMissionController(Database::getConnection()))->missions();$mission=$manager->getMission($id);if(!$mission){http_response_code(404);exit('Ordre de mission introuvable.');}
MissionOrderPdfService::render($mission,$manager->getMissionExpenses($id));
