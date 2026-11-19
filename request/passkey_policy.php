<?php
require_once __DIR__.'/passkey_bootstrap.php';
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/PasskeyPolicyService.php';
passkeyRequirePost();
if(empty($_SESSION['user_id']))passkeyJson(['status'=>'error','message'=>'Authentification requise.'],401);
try{
 $payload=json_decode(passkeyBody(),true,512,JSON_THROW_ON_ERROR);
 if(!hash_equals((string)($_SESSION['passkey_csrf']??''),(string)($payload['csrf_token']??'')))throw new RuntimeException('Session expirée.');
 $enabled=!empty($payload['enabled']);$policy=new PasskeyPolicyService($passkeyPdo);$userId=(int)$_SESSION['user_id'];
 if($enabled&&!$policy->hasPasskey($userId))throw new RuntimeException('Créez d’abord une Passkey avant d’activer ce mode.');
 $policy->setByUser($userId,$enabled);
 AuditTrail::log('PASSKEY_POLICY_UPDATED',['module'=>'security','action'=>'passkey_policy_updated','user_id'=>$userId,'result'=>'success','details'=>['passkey_only'=>$enabled]]);
 if($enabled){$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}session_destroy();passkeyJson(['status'=>'success','message'=>'Protection activée. Toutes vos sessions ont été déconnectées. Reconnectez-vous avec votre Passkey.','logout_required'=>true,'redirect'=>'../login.php']);}
 passkeyJson(['status'=>'success','message'=>'Connexion par mot de passe de nouveau autorisée.']);
}catch(Throwable $e){passkeyJson(['status'=>'error','message'=>$e->getMessage()],400);}
