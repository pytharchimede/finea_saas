<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/DisbursementRegularizationManager.php';
$manager=new DisbursementRegularizationManager(Database::getConnection());
$num=trim((string)($_GET['num_fiche'] ?? ''));$fiche=$manager->getFiche($num);$record=$manager->find($num);
$uid=(int)($_SESSION['user_id'] ?? 0);
if (!$fiche || !$record || !($manager->isRequester($fiche,$uid)||hasPermission('acces_compta')||hasPermission('acces_tresorerie')||hasPermission('acces_direction')||hasPermission('acces_admin'))) {http_response_code(403);exit;}
$relative=trim((string)$record['payment_proof_path']);
$root=realpath(__DIR__.'/..');$file=realpath($root.'/'.$relative);
if (!$file || strpos($file,$root.'/uploads/disbursement_proofs/')!==0 || !is_file($file)){http_response_code(404);exit('Preuve introuvable.');}
header('Content-Type: '.((string)$record['payment_proof_mime'] ?: 'application/octet-stream'));
header('Content-Length: '.filesize($file));
header('Content-Disposition: inline; filename="'.str_replace('"','',(string)$record['payment_proof_name']).'"');
header('X-Content-Type-Options: nosniff');
readfile($file);
