<?php
require_once __DIR__.'/passkey_bootstrap.php';
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/AuthDeviceSessionService.php';
passkeyRequirePost();
try{
    $payload=json_decode(passkeyBody(),true,512,JSON_THROW_ON_ERROR);
    if(!hash_equals((string)($_SESSION['passkey_csrf']??''),(string)($payload['csrf_token']??'')))throw new RuntimeException('Session expirée.');
    $userId=(int)$_SESSION['user_id'];$id=(int)($payload['id']??0);$sessions=new AuthDeviceSessionService($passkeyPdo);
    $currentId=$sessions->idForToken($userId,(string)($_SESSION['device_session_token']??''));
    if(($payload['action']??'')==='revoke_others'){
        if($currentId<1)throw new RuntimeException('La session actuelle ne peut pas être identifiée.');
        $count=$sessions->revokeAllExcept($userId,$currentId);
        AuditTrail::log('OTHER_DEVICE_SESSIONS_REVOKED',['module'=>'security','action'=>'other_device_sessions_revoked','user_id'=>$userId,'entity_type'=>'session','result'=>'success','details'=>['revoked_count'=>$count]]);
        $message=$count>0
            ? sprintf('%d autre%s appareil%s déconnecté%s.', $count, $count>1?'s':'', $count>1?'s':'', $count>1?'s':'')
            : 'Aucun autre appareil n’était connecté.';
        passkeyJson(['status'=>'success','message'=>$message,'revoked_count'=>$count]);
    }
    if($id<1)throw new RuntimeException('Appareil connecté invalide.');
    if(!$sessions->revoke($id,$userId))throw new RuntimeException('Appareil connecté introuvable.');
    AuditTrail::log('DEVICE_SESSION_REVOKED',['module'=>'security','action'=>'device_session_revoked','user_id'=>$userId,'entity_type'=>'session','entity_id'=>$id,'result'=>'success']);
    if($id===$currentId){$_SESSION=[];if(ini_get('session.use_cookies')){$params=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']);}session_destroy();passkeyJson(['status'=>'success','message'=>'Cet appareil a été déconnecté.','logout_required'=>true,'redirect'=>'../login.php']);}
    passkeyJson(['status'=>'success','message'=>'Appareil déconnecté. Il sera redirigé vers la connexion sous 15 secondes, même sans aucune action.']);
}catch(Throwable $e){passkeyJson(['status'=>'error','message'=>$e->getMessage()],400);}
