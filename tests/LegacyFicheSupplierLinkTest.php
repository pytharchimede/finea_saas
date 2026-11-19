<?php
declare(strict_types=1);
require_once __DIR__.'/../decaissement/model/Fiche.php';
final class LegacyFicheSqlite extends PDO {
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        if($query==='SHOW COLUMNS FROM fiche')$query="SELECT name FROM pragma_table_info('fiche')";
        return parent::query($query);
    }
}
$db=new LegacyFicheSqlite('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$names=['beficiaire_fiche','montant_fiche','tel_beneficiaire_fiche','date_creat_fiche','num_fiche','affectation_id','designation_fiche','num_piece','chantier_id','precision_fiche','serv_bureau_banamur_id','code_autorisation_feb','photo_beneficiaire','cni_beneficiaire','entreprise'];
$db->exec('CREATE TABLE fiche('.implode(',',array_map(fn($name)=>$name.' TEXT',$names)).')');
$data=array_fill_keys($names,'');$data=array_replace($data,['beficiaire_fiche'=>'Société','montant_fiche'=>100,'num_fiche'=>'TEST','fournisseur_id'=>17,'feb_request_id'=>42]);
(new Fiche($db))->insertFiche($data);
$row=$db->query('SELECT * FROM fiche')->fetch(PDO::FETCH_ASSOC);
if((int)$row['fournisseur_id']!==17||(int)$row['feb_request_id']!==42||$row['cni_beneficiaire']!=='')throw new RuntimeException('Legacy company linkage lost');
echo "LegacyFicheSupplierLinkTest: OK (authoritative supplier/FEB persisted without company CNI)\n";
