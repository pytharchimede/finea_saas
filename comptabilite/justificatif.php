<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
accountingRequire(accountingCan('compta_consulter_justificatifs') && accountingCan('compta_consulter_ecritures'));
try {
    $document = $accounting->document((int)($_GET['id']??0));
    header('Content-Type: '.$document['mime']);
    header('Content-Length: '.strlen($document['content']));
    $extension=['application/pdf'=>'pdf','image/png'=>'png','image/jpeg'=>'jpg'][$document['mime']];
    header('Content-Disposition: attachment; filename="justificatif-'.(int)$document['id'].'.'.$extension.'"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; sandbox");
    echo $document['content'];
} catch (DomainException $e) {
    http_response_code(404); echo ah($e->getMessage());
} catch (Throwable $e) {
    error_log('[accounting_document] '.$e->getMessage());
    http_response_code(500); echo 'Justificatif indisponible.';
}
