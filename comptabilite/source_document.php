<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
accountingRequire(accountingCan('compta_consulter_justificatifs') && accountingCan('compta_consulter_sources'));
try {
    $document=$sourceBridge->file((int)($_GET['id']??0),(int)($_GET['file']??-1));
    header('Content-Type: '.$document['mime']);
    header('Content-Length: '.strlen($document['content']));
    header('Content-Disposition: inline; filename="piece-source"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    echo $document['content'];
} catch (DomainException $e) { http_response_code(404); echo ah($e->getMessage()); }
catch (Throwable $e) { error_log('[accounting_source_document] '.$e->getMessage()); http_response_code(500); echo 'Pièce indisponible.'; }
