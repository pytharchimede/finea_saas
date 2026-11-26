<?php
declare(strict_types=1);
$preview=isset($_GET['preview']);
if($preview){require_once __DIR__.'/../auth_check.php';if(!hasPermission('acces_admin')){http_response_code(403);exit;}}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/SiteContent.php';
$id=(string)($_GET['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id)){http_response_code(404);exit;}
$db=Database::getConnection();
try{$row=SiteContent::read($db);$data=json_decode($row[$preview?'draft_json':'published_json']??'',true);if(!$preview&&(!is_array($data)||!in_array($id,SiteContent::imageIds($data),true))){http_response_code(404);exit;}if($asset=BtpDemo::asset($id)){header('Content-Type: image/svg+xml');header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");readfile($asset);exit;}$q=$db->prepare('SELECT mime,content FROM finea_website_media WHERE id=?');$q->execute([$id]);$image=$q->fetch(PDO::FETCH_ASSOC);}catch(PDOException $e){$image=false;}
if(!$image||!in_array($image['mime'],['image/jpeg','image/png','image/webp'],true)){http_response_code(404);exit;}
header('Content-Type: '.$image['mime']);header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');echo $image['content'];
