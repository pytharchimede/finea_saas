<?php
declare(strict_types=1);
require_once __DIR__.'/../../auth_check.php';AccessManager::requirePageAccess('fne_verify_invoice.php');require_once __DIR__.'/../../services/FnePdfExtractionService.php';
header('Content-Type: application/json; charset=utf-8');
try{if($_SERVER['REQUEST_METHOD']!=='POST')throw new DomainException('Méthode non autorisée.');$token=(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'');if(empty($_SESSION['fne_lookup_csrf'])||!hash_equals((string)$_SESSION['fne_lookup_csrf'],$token))throw new RuntimeException('Jeton CSRF invalide.');$result=(new FnePdfExtractionService())->extract($_FILES['invoice_pdf']??[]);echo json_encode(['ok'=>true,'data'=>$result],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}catch(Throwable $e){http_response_code($e instanceof DomainException?422:500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);}
