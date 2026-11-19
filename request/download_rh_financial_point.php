<?php
declare(strict_types=1);
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialFebDocumentService.php';
try {
    $id=(int)($_GET['point_id'] ?? 0);$revision=(int)($_GET['revision'] ?? 0);
    $file=(new RhFinancialFebDocumentService(Database::getConnection()))->file($id,$revision,(int)($_SESSION['user_id'] ?? 0),($_GET['original'] ?? '')==='1');
    header('Content-Type: application/pdf');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: '.(($_GET['download'] ?? '')==='1'?'attachment':'inline').'; filename="point-rh-'.$id.'-v'.$revision.'.pdf"');
    readfile($file);
} catch(Throwable $e) {http_response_code(403);echo htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8');}
