<?php
require_once __DIR__.'/passkey_bootstrap.php';
require_once __DIR__.'/../auth_check.php';
passkeyRequirePost();
try{
    $payload=json_decode(passkeyBody(),true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals((string)($_SESSION['passkey_csrf']??''),(string)($payload['csrf_token']??'')))throw new RuntimeException('Session expirée.');
    $id=(int)($payload['id']??0);$userId=(int)($_SESSION['user_id']??0);
    if($id<1||$userId<1||!$mobilePasskeyRepository->revokeScanner($id,$userId))throw new RuntimeException('Téléphone autorisé introuvable.');
    AuditTrail::log('MOBILE_SCANNER_REVOKED',['module'=>'security','action'=>'mobile_scanner_revoked','user_id'=>$userId,'entity_type'=>'mobile_scanner','entity_id'=>$id,'result'=>'success']);
    passkeyJson(['status'=>'success','message'=>'Téléphone révoqué. Il ne pourra plus valider de QR code FINEA.']);
}catch(Throwable $e){passkeyJson(['status'=>'error','message'=>$e->getMessage()],400);}
