<?php
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../controllers/EmailController.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../decaissement/config/mail_config.php';
require_once __DIR__.'/../decaissement/model/EmailFetcher.php';
header('Content-Type: application/json; charset=UTF-8');
function mailResult(bool $ok,string $message,int $code=200): never {http_response_code($code);echo json_encode(compact('ok','message'));exit;}
$p=json_decode(file_get_contents('php://input'),true);
if ($_SERVER['REQUEST_METHOD']!=='POST'||!is_array($p)||empty($_SESSION['finea_mail_csrf'])||!is_string($p['csrf']??null)||!hash_equals($_SESSION['finea_mail_csrf'],$p['csrf'])) mailResult(false,'Requête non autorisée.',403);
$action=$p['action']??'';
if(!in_array($action,['read','unread','flag','unflag','move','archive','trash','create'],true))mailResult(false,'Action invalide.',400);
$folder=$p['folder']??'INBOX';$target=$p['target']??'';
foreach([$folder,$target] as $name)if(!is_string($name)||strlen($name)>255||preg_match('/[\x00-\x1f{}]/',$name))mailResult(false,'Dossier invalide.',400);
$id=(int)($_SESSION['user_id']??0);$user=(string)($_SESSION['mail_pro']??'');
$c=new EmailController();$c->hydrateMailboxPasswordFromStorage($id);
$pass=(string)($_SESSION['personnel_email_password']??'');
if($id<=0||$user===''||$pass==='')mailResult(false,'Connexion requise.',401);
$uids=$p['uids']??[];if(!is_array($uids)||count($uids)>200)mailResult(false,'Sélection invalide.',400);
foreach($uids as $uid)if(!is_int($uid)||$uid<=0)mailResult(false,'UID invalide.',400);
$f=new EmailFetcher(IMAP_HOSTNAME,$user,$pass);
if(!$f->isConnected())mailResult(false,'Connexion IMAP impossible.',502);
$boxes=array_column($f->listMailboxes(),'name');
if($action==='create'){$ok=$target!==''&&$f->createMailbox($target);$f->closeConnection();$c->cache()->clearUserCache($id,null);mailResult($ok,$ok?'Dossier créé.':'Création impossible.');}
if(!$uids||!in_array($folder,$boxes,true)||!$f->reopenMailbox($folder))mailResult(false,'Dossier ou sélection invalide.',400);
if(in_array($action,['move','archive','trash'],true)&&($target===$folder||!in_array($target,$boxes,true)))mailResult(false,'Destination invalide.',400);
$n=0;foreach(array_unique($uids) as $uid){
$ok=match($action){'read'=>$f->setMessageFlag($uid,'\\Seen',true),'unread'=>$f->setMessageFlag($uid,'\\Seen',false),'flag'=>$f->setMessageFlag($uid,'\\Flagged',true),'unflag'=>$f->setMessageFlag($uid,'\\Flagged',false),default=>$f->moveEmailByUid($uid,$target)};
if($ok)$n++;
}
$f->closeConnection();$c->cache()->clearUserCache($id,null);unset($_SESSION['mailbox_unread_cache_count'],$_SESSION['mailbox_unread_cache_ts']);
mailResult($n===count(array_unique($uids)),sprintf('%d / %d messages traités.',$n,count(array_unique($uids))));
