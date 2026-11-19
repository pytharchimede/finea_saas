<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../services/FebHumanDocumentReviewService.php';
$uid=(int)($_SESSION['user_id']??0);$row=(new FebHumanDocumentReviewService(Database::getConnection()))->fileFor((int)($_GET['id']??0),$uid);session_write_close();
if(!$row){http_response_code(404);exit('Document introuvable.');}$root=realpath(__DIR__.'/../storage');$path=realpath(__DIR__.'/../storage/'.ltrim((string)$row['stored_path'],'/'));
if(!$root||!$path||!str_starts_with($path,$root)||!is_file($path)){http_response_code(404);exit('Document introuvable.');}
header('Content-Type: '.((string)$row['mime_type']?:'application/octet-stream'));header('Content-Disposition: inline; filename="'.rawurlencode((string)$row['original_name']).'"');readfile($path);
