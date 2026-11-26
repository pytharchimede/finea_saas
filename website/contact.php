<?php
declare(strict_types=1);
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/SiteContent.php';
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$db=Database::getConnection();$row=SiteContent::read($db);$data=json_decode($row['published_json']??'',true);
if(empty($data['contact_form'])){http_response_code(403);exit;}
if(!empty($_POST['website'])){http_response_code(400);exit;}
$fields=[];foreach(['name'=>190,'email'=>190,'phone'=>80,'subject'=>190,'message'=>5000] as $k=>$max){$v=trim((string)($_POST[$k]??''));if(($k!=='phone'&&$v==='')||mb_strlen($v)>$max){http_response_code(400);exit('Veuillez vérifier les champs.');}$fields[]=$v;}
if(!filter_var($fields[1],FILTER_VALIDATE_EMAIL)){http_response_code(400);exit('Email invalide.');}
$hash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??''));$q=$db->prepare('SELECT COUNT(*) FROM finea_website_inbox WHERE client_hash=? AND created_at>NOW()-INTERVAL 1 HOUR');$q->execute([$hash]);if((int)$q->fetchColumn()>=5){http_response_code(429);exit('Veuillez réessayer plus tard.');}
$q=$db->prepare('INSERT INTO finea_website_inbox(name,email,phone,subject,message,client_hash) VALUES(?,?,?,?,?,?)');$q->execute([...$fields,$hash]);header('Content-Type: text/html; charset=UTF-8');echo '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Demande reçue</title><h1>Merci pour votre demande.</h1><p>Votre message a été transmis à l’entreprise.</p><a href="public.php">Retour au site</a></html>';
