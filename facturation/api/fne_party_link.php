<?php
require_once __DIR__.'/../../auth_check.php';\AccessManager::requirePageAccess();
require_once __DIR__.'/../../model/Database.php';require_once __DIR__.'/../../model/FnePartyLinkManager.php';
header('Content-Type: application/json; charset=UTF-8');$_SESSION['fne_party_csrf']??=bin2hex(random_bytes(24));
try{
    $manager=new FnePartyLinkManager(Database::getConnection());$context=(string)($_GET['context']??$_POST['context']??'');
    if(!in_array($context,['sale','purchase'],true))throw new RuntimeException('Contexte FNE invalide.');
    if($_SERVER['REQUEST_METHOD']==='GET'){echo json_encode(['ok'=>true,'items'=>$manager->searchEntities($context,(string)($_GET['q']??''),40),'csrf'=>$_SESSION['fne_party_csrf']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
    if(!hash_equals((string)$_SESSION['fne_party_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Session expirée. Rechargez la page.');
    $importId=(int)($_POST['import_id']??0);$action=(string)($_POST['action']??'link');$userId=(int)($_SESSION['user_id']??0);
    $result=$action==='create'?$manager->createAndLink($context,$importId,$userId):$manager->link($context,$importId,(int)($_POST['entity_id']??0),$userId);
    echo json_encode(['ok'=>true,'result'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable$e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
