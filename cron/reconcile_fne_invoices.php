<?php
if (PHP_SAPI!=='cli'){http_response_code(403);exit('CLI uniquement.');}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/FneSalesInvoiceImportManager.php';
require_once __DIR__.'/../model/FnePurchaseInvoiceImportManager.php';
require_once __DIR__.'/../services/DgiNccDirectorySyncService.php';
require_once __DIR__.'/../services/FneInvoiceDirectionCleaner.php';
$pdo=Database::getConnection();
$lock=(int)$pdo->query("SELECT GET_LOCK('finea_fne_reconciliation',0)")->fetchColumn();
if($lock!==1){echo json_encode(['executed_at'=>date(DATE_ATOM),'skipped'=>'Une autre synchronisation FNE est en cours.'],JSON_PRETTY_PRINT).PHP_EOL;exit;}
try{
    $salesManager=new FneSalesInvoiceImportManager($pdo);
    $purchaseManager=new FnePurchaseInvoiceImportManager($pdo);
    $directions=(new FneInvoiceDirectionCleaner($pdo))->clean();
    $sales=$salesManager->reconcileAll(2000);
    $purchases=$purchaseManager->reconcileAll(2000);
    $ncc=(new DgiNccDirectorySyncService($pdo))->synchronize(300);
    echo json_encode(['executed_at'=>date(DATE_ATOM),'direction_cleanup'=>$directions,'sales'=>$sales,'purchases'=>$purchases,'dgi_ncc'=>$ncc],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
}finally{$pdo->query("SELECT RELEASE_LOCK('finea_fne_reconciliation')");}
