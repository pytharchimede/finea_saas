<?php
require_once __DIR__.'/model/Database.php';require_once __DIR__.'/../services/DailyPointExternalSignatureService.php';
if(session_status()!==PHP_SESSION_ACTIVE)session_start();header('Cache-Control: no-store, private, max-age=0');header('Referrer-Policy: no-referrer');header('X-Content-Type-Options: nosniff');
try{
    $token=(string)($_GET['token']??'');$service=new DailyPointExternalSignatureService(Database::getConnection());$link=$service->lookup($token);$service->event((int)$link['id'],($_GET['mode']??'inline')==='download'?'pdf_downloaded':'pdf_viewed');
    define('DAILY_POINT_CRON_EXPORT',true);$_GET['id']=(int)$link['point_id'];require __DIR__.'/request/export_daily_point_pdf.php';$content=(string)($dailyPointPdfReport['content']??'');if($content==='')throw new RuntimeException('Document indisponible.');
    $download=($_GET['mode']??'inline')==='download';header('Content-Type: application/pdf');header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.preg_replace('/[^a-zA-Z0-9._-]/','_',($dailyPointPdfReport['fileName']??'point.pdf')).'"');header('Content-Length: '.strlen($content));echo$content;
}catch(Throwable $e){http_response_code(403);header('Content-Type: text/plain; charset=UTF-8');echo'Accès au document refusé : '.$e->getMessage();}
