<?php
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../../services/DailyDisbursementPointService.php';
header('Content-Type: application/json; charset=UTF-8');

try {
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Méthode refusée.');
    if(empty($_SESSION['daily_point_csrf'])||!hash_equals((string)$_SESSION['daily_point_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');
    $service=new DailyDisbursementPointService(Database::getConnection());
    $action=(string)($_POST['action']??'');
    $userId=(int)($_SESSION['user_id']??0);
    $name=(string)($_SESSION['user_name']??$_SESSION['user_login']??'Utilisateur');
    $right=$servicePermission=false;try{$check=Database::getConnection()->prepare('SELECT acces_arreter_point_jour FROM user_devis_banamur WHERE id=:id AND active=1');$check->execute(['id'=>$userId]);$servicePermission=(bool)$check->fetchColumn();}catch(Throwable $e){}$canStop=AccessManager::hasPermission('acces_arreter_point_jour')||$servicePermission;
    if($action==='stop'){if(!$canStop)throw new RuntimeException('Le droit « Arrêter le point du jour » est requis.');$plans=json_decode((string)($_POST['plans']??'{}'),true);$result=$service->stop(explode(',',(string)($_POST['selected']??'')),$userId,$name,is_array($plans)?$plans:[]);$invitation=(array)($result['_signature_invitation']??[]);$pointId=(int)($result['id']??0);}
    elseif($action==='signature_link'){$pointId=(int)($_POST['point_id']??0);$point=$service->find($pointId);$canManageLink=$canStop||AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_finance')||AccessManager::hasPermission('acces_achat')||(int)($point['stopped_by_user_id']??0)===$userId;if(!$canManageLink)throw new RuntimeException('Vous ne pouvez pas recréer le lien de signature.');if(!$point||(string)$point['status']!=='stopped')throw new RuntimeException('Ce point n’attend plus la signature du DG.');$service->provisionConfiguredSigners($pointId,$userId);require_once __DIR__.'/../../services/DailyPointExternalSignatureService.php';$invitation=(new DailyPointExternalSignatureService(Database::getConnection()))->invite($pointId,$userId);}else throw new RuntimeException('Action inconnue.');
    echo json_encode(['ok'=>true,'point_id'=>$pointId,'signature_url'=>$invitation['url']??null,'signature_expires_at'=>$invitation['expires_at']??null,'whatsapp_sent'=>!empty($invitation['sent'])],JSON_UNESCAPED_UNICODE);
} catch(Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
