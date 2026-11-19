<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/FebHumanDocumentReviewService.php';
require_once __DIR__.'/../model/FebOcrSettings.php';
header('Content-Type: application/json; charset=utf-8');
try{
 $uid=(int)($_SESSION['user_id']??0);if($uid<=0)throw new RuntimeException('Session expirée.');
 $method=(string)($_SERVER['REQUEST_METHOD']??'GET');$csrf=(string)($_SESSION['feb_proof_upload_csrf']??'');session_write_close();
 if($method==='GET'&&($_GET['action']??'')==='mode'){echo json_encode(['ok'=>true,'mode'=>(new FebOcrSettings())->effectiveMode()],JSON_UNESCAPED_UNICODE);exit;}
 $service=new FebHumanDocumentReviewService(Database::getConnection());
 if($method==='POST'){
  if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Session expirée. Rechargez le formulaire.');
  if((new FebOcrSettings())->effectiveMode()!=='human')throw new RuntimeException('Le mode de vérification a changé. Rechargez le formulaire.');
  $result=$service->submit($uid,(array)($_FILES['document']??[]),trim((string)($_POST['supplier']??'')),(float)($_POST['amount']??0),(string)($_POST['lines']??'[]'));
 }else{$result=$service->status(trim((string)($_GET['token']??'')),$uid);}
 echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
