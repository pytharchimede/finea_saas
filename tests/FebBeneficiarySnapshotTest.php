<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FebBeneficiarySyncService.php';
final class SnapshotSqlite extends PDO {
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        if(str_starts_with($query,'SHOW TABLES'))$query="SELECT name FROM sqlite_master WHERE type='table' AND name='daily_disbursement_points'";
        return parent::query($query);
    }
}
$db=new SnapshotSqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE employee_legal_requests(id INT,request_type TEXT,status TEXT,payload_json TEXT)');
$db->exec('CREATE TABLE fournisseur_banamur(id_fournisseur INT,nom_commercial TEXT,raison_sociale TEXT,contact_nom TEXT,telephone TEXT)');
$db->exec('CREATE TABLE fiche_decaissement(num_fiche TEXT,feb_request_id INT,decaisse INT,beficiaire_fiche TEXT,tel_beneficiaire_fiche TEXT,personnel_id INT)');
$db->exec('CREATE TABLE daily_disbursement_points(id INT,status TEXT)');
$db->exec('CREATE TABLE daily_disbursement_point_items(point_id INT,fiche_number TEXT)');
$db->exec("INSERT INTO fournisseur_banamur VALUES(1,'Nouveau nom','SARL','Gérant','0102030405')");
$db->prepare("INSERT INTO employee_legal_requests VALUES(1,'feb','approved',?)")->execute([json_encode(['provisional_decaissement'=>['beneficiary_mode'=>'supplier','fournisseur_id'=>1]])]);
foreach(['draft','stopped','certified','paid','rejected'] as $i=>$state){
$db->prepare('INSERT INTO fiche_decaissement VALUES(?,1,?,?,?,NULL)')->execute([$state,$state==='paid'?1:0,'Ancien nom','0000000000']);
if($state!=='draft'){$db->prepare('INSERT INTO daily_disbursement_points VALUES(?,?)')->execute([$i,$state]);$db->prepare('INSERT INTO daily_disbursement_point_items VALUES(?,?)')->execute([$i,$state]);}
}
FebBeneficiarySyncService::syncFiche($db,1);
foreach($db->query('SELECT * FROM fiche_decaissement')->fetchAll(PDO::FETCH_ASSOC) as $row){
$expected=in_array($row['num_fiche'],['draft','rejected'],true)?'Nouveau nom':'Ancien nom';
if($row['beficiaire_fiche']!==$expected)throw new RuntimeException('Frozen beneficiary overwritten: '.$row['num_fiche']);
}
echo "FebBeneficiarySnapshotTest: OK (paid and DG-reserved identities frozen, unfrozen drafts updated)\n";
