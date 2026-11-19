<?php
require_once __DIR__ . '/passkey_bootstrap.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../services/PasskeyPolicyService.php';
passkeyRequirePost();
if (empty($_SESSION['user_id'])) passkeyJson(['status'=>'error','message'=>'Authentification requise.'], 401);
try {
    $payload=json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals((string)($_SESSION['passkey_csrf'] ?? ''),(string)($payload['csrf_token'] ?? ''))) throw new RuntimeException('Session expirée.');
    $passkeyId=(int)($payload['id']??0);$userId=(int)$_SESSION['user_id'];
    $policy=(new PasskeyPolicyService($passkeyPdo))->get($userId);
    if(!empty($policy['passkey_only'])&&count($passkeyRepository->activeRecordsForUser($userId))<=1)throw new RuntimeException('Ajoutez une autre Passkey ou désactivez d’abord le mode Passkey uniquement.');
    if (!$passkeyRepository->revoke($passkeyId,$userId)) throw new RuntimeException('Passkey introuvable.');
    AuditTrail::log('PASSKEY_REVOKED',['module'=>'security','action'=>'passkey_revoked','user_id'=>(int)$_SESSION['user_id'],'entity_type'=>'passkey','entity_id'=>(int)$payload['id'],'result'=>'success']);
    if((int)($_SESSION['passkey_id']??0)===$passkeyId){$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}session_destroy();passkeyJson(['status'=>'success','message'=>'Cet appareil a été retiré et sa session déconnectée.','logout_required'=>true,'redirect'=>'../login.php']);}
    passkeyJson(['status'=>'success','message'=>'Appareil retiré. Ses sessions seront automatiquement déconnectées.']);
} catch(Throwable $e) { passkeyJson(['status'=>'error','message'=>$e->getMessage()],400); }
