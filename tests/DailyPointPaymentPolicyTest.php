<?php
declare(strict_types=1);
require_once __DIR__.'/../services/DailyPointPaymentPolicy.php';
function pointCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pointReject(array $item, array $fiche, float $amount): void {
    try { DailyPointPaymentPolicy::assertItem($item,$fiche,$amount); }
    catch (DomainException $e) { return; }
    throw new RuntimeException('Unauthorized payment accepted');
}
$fiche=['num_fiche'=>'TEST','montant_fiche'=>100,'fournisseur_id'=>1,'personnel_id'=>0,'feb_request_id'=>5,'beficiaire_fiche'=>'Société','tel_beneficiaire_fiche'=>'0102030405','chantier_id'=>2,'ligne_debourse_id'=>3,'code_autorisation_feb'=>'BCI-TEST'];
$item=['point_status'=>'certified','certified_at'=>'2026-10-26 10:00:00','dg_signed'=>1,'amount'=>100,'snapshot_json'=>json_encode($fiche)];
DailyPointPaymentPolicy::assertItem($item,$fiche,100);
foreach ([['point_status'=>'stopped'],['certified_at'=>null],['dg_signed'=>0],['snapshot_json'=>'broken'],['amount'=>0],['amount'=>101]] as $change) pointReject(array_replace($item,$change),$fiche,100);
foreach ([['fournisseur_id'=>2],['personnel_id'=>7],['chantier_id'=>8],['ligne_debourse_id'=>9],['montant_fiche'=>50],['beficiaire_fiche'=>'Autre'],['tel_beneficiaire_fiche'=>'0000000000']] as $change) pointReject($item,array_replace($fiche,$change),100);
foreach ([0,-1,50,101,INF,NAN] as $amount) pointReject($item,$fiche,$amount);
$partial=array_replace($item,['amount'=>50]);
DailyPointPaymentPolicy::assertItem($partial,$fiche,50);
pointReject($partial,$fiche,100);
echo "DailyPointPaymentPolicyTest: OK (DG signature, exact amount, frozen beneficiary and budget, partial amount)\n";
