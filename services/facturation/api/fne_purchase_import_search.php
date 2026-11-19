<?php
require_once __DIR__.'/../../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__.'/../../model/Database.php';
require_once __DIR__.'/../../model/FnePurchaseInvoiceImportManager.php';
header('Content-Type: application/json; charset=UTF-8');
try{$items=(new FnePurchaseInvoiceImportManager(Database::getConnection()))->search((string)($_GET['q']??''),40);echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Recherche des achats FNE indisponible.']);}
