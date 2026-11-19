<?php
declare(strict_types=1);
// Deliberately do not open the PHP session: the long request holds its lock.
// A random per-request capability AND the exact owning session cookie are required.
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$record=FinancialWorkflowProgress::read((string)($_GET['token']??''),$_COOKIE);
if(!$record){http_response_code(404);echo json_encode(['ok'=>false]);exit;}
echo json_encode(['ok'=>true]+$record,JSON_UNESCAPED_UNICODE);
