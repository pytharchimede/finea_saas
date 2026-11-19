<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialSettlementService.php';
$pdo=Database::getConnection();$service=new RhFinancialSettlementService($pdo);
$q=$pdo->query("SELECT f.num_fiche,f.decaisse FROM fiche_decaissement f JOIN rh_financial_points p ON p.feb_request_id=f.feb_request_id WHERE f.approuve=1 AND p.status='dg_signed' ORDER BY f.id_fiche");
$failed=0;
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){try{$service->sync($r['num_fiche'],'authorized');if((int)$r['decaisse']===1)$service->sync($r['num_fiche'],'paid');}catch(Throwable $e){$failed++;error_log('RH settlement '.$r['num_fiche'].': '.$e->getMessage());}}
require_once __DIR__.'/../services/DisbursementBeneficiaryNotificationService.php';
$beneficiaries=new DisbursementBeneficiaryNotificationService($pdo);
$q=$pdo->query("SELECT num_fiche,decaisse FROM fiche_decaissement WHERE approuve=1 AND personnel_id IS NOT NULL ORDER BY id_fiche");
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){try{$beneficiaries->sync($r['num_fiche'],'authorized');if((int)$r['decaisse']===1)$beneficiaries->sync($r['num_fiche'],'paid');}catch(Throwable $e){$failed++;error_log('Beneficiary settlement '.$r['num_fiche'].': '.$e->getMessage());}}
exit($failed?1:0);
