<?php
require_once __DIR__.'/../../auth_check.php';
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../../services/DailyDisbursementPointService.php';

try {
    $paymentId=max(0,(int)($_GET['payment_id']??0));
    if($paymentId<=0)throw new RuntimeException('Preuve de paiement introuvable.');
    $pdo=Database::getConnection();
    $stmt=$pdo->prepare('SELECT p.id,p.point_id,p.proof_path FROM daily_disbursement_point_payments p WHERE p.id=:id LIMIT 1');
    $stmt->execute(['id'=>$paymentId]);
    $payment=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$payment||trim((string)$payment['proof_path'])==='')throw new RuntimeException('Aucune preuve n’est rattachée à ce paiement.');
    $service=new DailyDisbursementPointService($pdo);$point=$service->find((int)$payment['point_id']);
    if(!$point)throw new RuntimeException('Point de décaissement introuvable.');
    $userId=(int)($_SESSION['user_id']??0);$all=AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_finance')||AccessManager::hasPermission('acces_achat')||AccessManager::hasPermission('acces_tresorerie')||AccessManager::hasPermission('acces_decaisser_point_jour');
    if(!$service->canView($point,$userId,$all)){http_response_code(403);exit('Accès refusé à cette preuve de paiement.');}
    $root=realpath(dirname(__DIR__,2).'/uploads/disbursement_proofs/daily_point_payments');$file=realpath(dirname(__DIR__,2).'/'.ltrim((string)$payment['proof_path'],'/'));
    if(!$root||!$file||!str_starts_with($file,$root.'/')||!is_file($file)||!is_readable($file))throw new RuntimeException('Le fichier de preuve est indisponible.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream';if(!in_array($mime,['application/pdf','image/png','image/jpeg'],true))throw new RuntimeException('Format de preuve refusé.');
    header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Content-Disposition: inline; filename="'.basename($file).'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');readfile($file);exit;
} catch(Throwable $e) {
    http_response_code(404);header('Content-Type: text/plain; charset=UTF-8');echo $e->getMessage();
}
