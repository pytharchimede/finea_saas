<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ContractWhatsAppService.php';
require_once __DIR__.'/../contrats/model/ContratSousTraitance.php';
// Exercise production services against a transient SQL database. SQLite adapter only
// translates MySQL syntax; MariaDB row locking itself requires a staging test.
final class ContractTestPDO extends PDO
{
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));}
    private function sql(string $sql):string{return str_replace([' FOR UPDATE','INSERT IGNORE','DATE_SUB(NOW(),INTERVAL 15 MINUTE)','DATE_SUB(NOW(),INTERVAL 10 MINUTE)','DATE_SUB(NOW(),INTERVAL 5 MINUTE)'],['','INSERT OR IGNORE',"datetime('now','-15 minutes')","datetime('now','-10 minutes')","datetime('now','-5 minutes')"],$sql);}
    public function exec(string $statement):int|false{return parent::exec($this->sql($statement));}
    public function prepare(string $query,array $options=[]):PDOStatement|false{return parent::prepare($this->sql($query),$options);}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{return $fetchMode===null?parent::query($this->sql($query)):parent::query($this->sql($query),$fetchMode,...$fetchModeArgs);}
}
final class ContractFakeApi extends WhatsAppAdminContentService
{
    public int $creates=0;public int $submits=0;public bool $fail=false;public array $approvals=[];
    public function __construct(){}
    public function createContent(array $definition):array{$this->creates++;if($this->fail)throw new RuntimeException('network timeout');return ['sid'=>'HX'.str_pad((string)$this->creates,32,'0',STR_PAD_LEFT)];}
    public function submitContent(string $sid,string $name,string $category):array{$this->submits++;return ['status'=>'pending'];}
    public function messageStatus(string $sid):array{return ['status'=>'delivered','error_code'=>'','error'=>''];}
    public function fetchApproval(string $sid):array{if($this->fail)throw new RuntimeException('network timeout');return $this->approvals[$sid]??['status'=>'approved','category'=>'UTILITY','reason'=>''];}
}
final class ContractFakeChannel extends NotificationWhatsAppChannelService
{
    public int $sends=0;public bool $uncertain=false;
    public function __construct(){}
    public function sendTemplateDetailed(string $to,string $sid,array $variables=[]):array{$this->sends++;return ['ok'=>!$this->uncertain,'message_sid'=>$this->uncertain?'':'SM'.str_repeat('a',32),'status'=>'queued','error'=>'timeout'];}
}
$count=0;
function check(bool $condition,string $message):void{global $count;$count++;if(!$condition)throw new RuntimeException($message);}
function fails(callable $task,string $message):void{try{$task();}catch(Throwable $e){check(true,$message);return;}check(false,$message);}
$pdo=new ContractTestPDO();
$pdo->exec("CREATE TABLE chantier_banamur(id INTEGER PRIMARY KEY,code TEXT); INSERT INTO chantier_banamur VALUES(1,'CH0062');
CREATE TABLE fournisseur_banamur(id_fournisseur INTEGER PRIMARY KEY,telephone TEXT,nom_commercial TEXT,raison_sociale TEXT); INSERT INTO fournisseur_banamur VALUES(1,'0700000000','Prestataire','Entreprise');
CREATE TABLE fonction(id_fonction INTEGER PRIMARY KEY,lib_fonction TEXT); INSERT INTO fonction VALUES(1,'COLLABORATEUR'),(2,'DIRECTEUR GENERAL');
CREATE TABLE personnel(id_personnel_soignant INTEGER PRIMARY KEY,nom_personnel_soignant TEXT,tel_personnel_soignant TEXT,date_sortie TEXT,fonction_id INTEGER);
CREATE TABLE user_devis_banamur(id INTEGER PRIMARY KEY,personnel_id INTEGER,active INTEGER);
CREATE TABLE contrat_sous_traitance(id INTEGER PRIMARY KEY,reference TEXT,objet TEXT,chantier_id INTEGER,fournisseur_id INTEGER,statut TEXT,signataire_banamur_personnel_id INTEGER,signature_banamur TEXT,signature_prestataire TEXT,signe_banamur_at TEXT,signe_prestataire_at TEXT);
INSERT INTO contrat_sous_traitance VALUES(1,'CST-2026-0001','Travaux',1,1,'brouillon',7,NULL,NULL,NULL,NULL);
CREATE TABLE contrat_workflow_etape(id INTEGER PRIMARY KEY,contrat_id INTEGER,etape TEXT,ordre INTEGER,statut TEXT,donnees_json TEXT,signataire_personnel_id INTEGER,signature TEXT,signe_par_user_id INTEGER,signe_at TEXT,updated_at TEXT);
CREATE TABLE contrat_signature_historique(document_type TEXT,document_id INTEGER,action TEXT,signataire_personnel_id INTEGER,acteur_user_id INTEGER,details_json TEXT);
CREATE TABLE contract_whatsapp_settings(id INTEGER PRIMARY KEY,enabled INTEGER,activated_at TEXT,auto_activation INTEGER DEFAULT 1);INSERT INTO contract_whatsapp_settings VALUES(1,1,'2026-01-01',1);
CREATE TABLE contract_whatsapp_templates(template_key TEXT PRIMARY KEY,name TEXT,definition_json TEXT,content_sid TEXT,status TEXT DEFAULT 'prepared',category TEXT DEFAULT 'UTILITY',last_error TEXT,checked_at TEXT);
CREATE TABLE contract_whatsapp_outbox(id INTEGER PRIMARY KEY AUTOINCREMENT,contract_id INTEGER,stage TEXT,recipient_personnel_id INTEGER,fingerprint TEXT,template_key TEXT,status TEXT DEFAULT 'pending',attempts INTEGER DEFAULT 0,message_sid TEXT,provider_status TEXT,last_error TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(contract_id,stage,recipient_personnel_id,fingerprint));");
$pdo->exec("ALTER TABLE contrat_sous_traitance ADD COLUMN montant_ht REAL DEFAULT 100000;ALTER TABLE contrat_sous_traitance ADD COLUMN taux_acompte REAL DEFAULT 0;ALTER TABLE contrat_sous_traitance ADD COLUMN montant_acompte REAL DEFAULT 0;ALTER TABLE contrat_sous_traitance ADD COLUMN retenue_garantie_taux REAL DEFAULT 0;ALTER TABLE contrat_sous_traitance ADD COLUMN clauses_particulieres TEXT;ALTER TABLE contrat_sous_traitance ADD COLUMN date_debut TEXT;ALTER TABLE contrat_sous_traitance ADD COLUMN date_fin TEXT;ALTER TABLE contrat_sous_traitance ADD COLUMN delai_jours INTEGER;");
$stageData=[
'rh'=>['dossier_fournisseur'=>'complet'],
'juridique'=>['parties_donneur'=>'BANAMUR','parties_sous_traitant'=>'Exemple','articles_juridiques'=>'Articles enregistrés'],
 'technique'=>['objet_contrat'=>'Travaux','description_travaux'=>'Description','planning_description'=>'Phase 1','methodologie'=>'Méthode','date_debut'=>'2026-10-16','delai_jours'=>5],
 'logistique'=>['vehicules_engins'=>'Camion','materiaux'=>'Ciment','materiel_outillage'=>'Outils'],
 'qhse'=>['epis'=>'Casques','exigences_qhse'=>'Contrôles'],
 'comptabilite'=>['condition_reglement_taches'=>'Après réception','condition_reglement_acompte'=>'Au démarrage','condition_reglement_retenue'=>'Après garantie','taux_acompte'=>20,'retenue_garantie'=>5]];
foreach(range(1,7) as $i){$pdo->prepare("INSERT INTO personnel VALUES(?,?,'0700000000',NULL,?)")->execute([$i,'Signataire '.$i,$i===7?2:1]);$pdo->prepare('INSERT INTO user_devis_banamur VALUES(?,?,1)')->execute([$i,$i]);}
$i=0;foreach($stageData as $stage=>$data){$i++;$pdo->prepare('INSERT INTO contrat_workflow_etape VALUES(?,1,?,?,?, ?,?,NULL,NULL,NULL,?)')->execute([$i,$stage,$i,$i===1?'en_cours':'en_attente',json_encode($data),$i,'2026-10-16 00:00:00']);}
fails(fn()=>ContractWorkflowPolicy::assertSignatureImage('data:image/png;base64,YWJj'),'Malformed image must fail.');
$api=new ContractFakeApi();$channel=new ContractFakeChannel();$wa=new ContractWhatsAppService($pdo,$api,$channel);
$wa->provision();$wa->provision();check($api->creates===10&&$api->submits===10,'Provision must be idempotent.');
$wa->queue();$wa->dispatch();check($channel->sends===0,'Pending approval must block all sends.');$wa->poll();$wa->queue();$wa->queue();check((int)$pdo->query('SELECT COUNT(*) FROM contract_whatsapp_outbox')->fetchColumn()===1,'Initial notification deduplicates.');
$wa->dispatch();$wa->dispatch();check($channel->sends===1,'A sent notification cannot send twice.');
$ref=new ReflectionClass(ContratSousTraitance::class);$manager=$ref->newInstanceWithoutConstructor();$ref->getProperty('pdo')->setValue($manager,$pdo);
$signature='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGQAAAAyCAYAAACqNX6+AAABiklEQVR4nO2bbbLCIAxFyRv3v+X4440zVcNHIYEL3rMAiz09pFYVVU0Eh7/VCyDvUAgYFAJGuBAR4ZC6gUQO9U8ZqiphBzuEsEJYRh8hQnIyKKkOhzoY7kKsuXGdHaykjKsQnuxx3IRYMq5lsJI2XITUZJB2hoVQhi/uQ70kg9tWnSEh/CTuT7eQ3iuclZTpEsK5EcdtIR4yWEmeW0JYRj+tF95j5CCUUefuDtD8fUjEHdX1NU+Sm5PQ8h6bCuE+34bHll4tJHpu7F7JSA0WxUI4xPNEnZtsITNl7FKJdw0WzXdZyCcqmpkXpymEz6jm1GAe93PLWjU3ULat1XPzrZDVi1nFqhrMtZQKmb2g2cdGvAC/Zoiqiojo6oVFgVSDRehPSXuIqgSxBouhh4vooNdgcaSQXWqwgNuyUurbtnaswWL7QnauwQKykJTKlZxSg8VWhZxWgwVsISmVvxg7TcSLrQo5VcIV6EJS+q/kF0S8gBfya/AvbWBQCBgUAgaFgEEhYFAIGBQCxhMp4QtgdKsAMwAAAABJRU5ErkJggg==';
fails(fn()=>$manager->assignWorkflowSigners(1,array_fill_keys(array_keys(ContractWhatsAppCatalog::STAGES),1),false),'Unauthorized global assignment must fail before writes.');
$pdo->exec("UPDATE contrat_workflow_etape SET signature='old',signe_par_user_id=1 WHERE etape='juridique'");
$signers=array_combine(array_keys(ContractWhatsAppCatalog::STAGES),range(1,7));$signers['juridique']=3;
$manager->assignWorkflowSigners(1,$signers,true);
check($manager->workflow(1)[1]['signature']===null,'Reassignment must remove a previous pre-signature.');
$signers['juridique']=2;$manager->assignWorkflowSigners(1,$signers,true);

$pre=$manager->workflow(1)[5];
$manager->signWorkflowStage(1,'comptabilite',$signature,6,6,false,false,ContractWhatsAppCatalog::stageHash($pre));
check($manager->workflow(1)[5]['statut']==='en_attente'&&!empty($manager->workflow(1)[5]['signature']),'Pre-signature must not bypass preceding stages.');
$hash=ContractWhatsAppCatalog::stageHash($manager->workflow(1)[0]);
fails(fn()=>$manager->signWorkflowStage(1,'rh',$signature,2,2,false,false,$hash),'Wrong signer must fail.');
// A designated signer can adjust their stage, but a stale page can no longer sign it.
$data=$stageData['rh']+['observations'=>'Dossier contrôlé'];$manager->saveWorkflowStage(1,'rh',$data,1,false,1);
fails(fn()=>$manager->signWorkflowStage(1,'rh',$signature,1,1,false,false,$hash),'Stale content must fail.');
$hash=ContractWhatsAppCatalog::stageHash($manager->workflow(1)[0]);
$manager->signWorkflowStage(1,'rh',$signature,1,1,false,false,$hash);
check($manager->workflow(1)[0]['statut']==='validee','RH stage signs successfully.');
$wa->queue();$wa->dispatch();check($channel->sends===2,'Next stage gets its notification.');
// Invalidate a pending message through delegation; the old recipient never gets it.
$pdo->exec("UPDATE contrat_workflow_etape SET signataire_personnel_id=3 WHERE etape='juridique'");$wa->queue();
$pdo->exec("UPDATE contrat_workflow_etape SET signataire_personnel_id=2 WHERE etape='juridique'");$wa->dispatch();
check((int)$pdo->query("SELECT COUNT(*) FROM contract_whatsapp_outbox WHERE status='obsolete'")->fetchColumn()>0,'Delegated stale message is discarded.');
$i=1;foreach(array_slice(array_keys($stageData),1) as $stage){$i++;$row=$manager->workflow(1)[$i-1];$wa->queue();$current=$wa->desired(1)[0];check($current['stage']===$stage,'Only current stage receives an invitation.');$manager->signWorkflowStage(1,$stage,$signature,$i,$i,false,false,ContractWhatsAppCatalog::stageHash($row));}
check($pdo->query('SELECT statut FROM contrat_sous_traitance WHERE id=1')->fetchColumn()==='a_signer','All six stages enable final signature.');
check($wa->desired(1)[0]['stage']==='direction','DG becomes actionable after all six visas.');
$c=$pdo->query('SELECT * FROM contrat_sous_traitance WHERE id=1')->fetch(PDO::FETCH_ASSOC);$hash=ContractWorkflowPolicy::finalHash($c,$manager->workflow(1));
fails(fn()=>$manager->signFinal(1,'banamur',$signature,1,1,true,$hash),'Only designated DG signs.');
fails(fn()=>$manager->signFinal(1,'prestataire',$signature,1,1,true,$hash),'Supplier cannot sign before DG.');
// A modification invalidates own and downstream signatures and the old final proof.
$result=$manager->saveWorkflowStage(1,'technique',$stageData['technique']+['observations'=>'Corrigé'],3,false,3);
check($result['signature_reset']&&count($result['reset_stages'])===4,'Correction resets technique and downstream visas.');
fails(fn()=>$manager->signFinal(1,'banamur',$signature,7,7,false,$hash),'Old DG proof cannot sign modified contract.');
foreach(array_slice($manager->workflow(1),2) as $row){$row=$manager->workflow(1)[(int)$row['ordre']-1];$pid=(int)$row['signataire_personnel_id'];$manager->signWorkflowStage(1,$row['etape'],$signature,$pid,$pid,false,false,ContractWhatsAppCatalog::stageHash($row));}
$c=$pdo->query('SELECT * FROM contrat_sous_traitance WHERE id=1')->fetch(PDO::FETCH_ASSOC);$hash=ContractWorkflowPolicy::finalHash($c,$manager->workflow(1));
$manager->signFinal(1,'banamur',$signature,7,7,false,$hash);check($wa->desired(1)[0]['stage']==='prestataire','Supplier notified only after DG.');
fails(fn()=>$manager->setFinalSigner(1,7,1,true),'Signer frozen after DG signature.');
fails(fn()=>$manager->saveWorkflowStage(1,'rh',$stageData['rh'],1,false,1),'Content frozen after DG.');
$c=$pdo->query('SELECT * FROM contrat_sous_traitance WHERE id=1')->fetch(PDO::FETCH_ASSOC);$hash=ContractWorkflowPolicy::finalHash($c,$manager->workflow(1));
fails(fn()=>$manager->signFinal(1,'prestataire',$signature,2,2,false,$hash),'Supplier capture requires RH/admin.');
$manager->signFinal(1,'prestataire',$signature,1,1,true,$hash);
check($pdo->query('SELECT statut FROM contrat_sous_traitance WHERE id=1')->fetchColumn()==='actif','Supplier signature activates contract.');
check(count($wa->desired(1))===7,'Completion reaches all internal signers.');
$wa->queue();$channel->uncertain=true;$wa->dispatch();$sends=$channel->sends;$wa->dispatch();check($channel->sends===$sends,'Uncertain sends never retry blindly.');
$api->fail=true;$pdo->exec('UPDATE contract_whatsapp_templates SET checked_at=NULL');$wa->poll();check((int)$pdo->query('SELECT COUNT(*) FROM contract_whatsapp_templates WHERE checked_at IS NOT NULL')->fetchColumn()===0,'Failed poll cannot authorize sends.');
$pdo->exec("UPDATE contrat_sous_traitance SET statut='resilie'");check($wa->desired(1)===[],'Cancelled contracts generate no actions.');
check(ContractWhatsAppCatalog::phone('07 00 00 00 00')==='+2250700000000','CI local phone is normalized.');fails(fn()=>ContractWhatsAppCatalog::phone(''), 'Missing phone blocks send.');
foreach(ContractWhatsAppCatalog::inputs() as $input){$definition=WhatsAppAdminContentService::definitionFromInput($input);check($definition['category']==='UTILITY','All templates validate in Utility.');}
$pdo->exec('DELETE FROM contract_whatsapp_templates');$api->fail=true;$wa->provision();$creates=$api->creates;$wa->provision();check($api->creates===$creates,'Uncertain template creation never repeats blindly.');
echo "$count assertions passed (production services; transient SQLite adapter).\n";
