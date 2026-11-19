<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ManualTreasuryService.php';
require_once __DIR__.'/../scripts/repair_vat_snapshot_discounts.php';

function vatCheck(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function vatNear(float $actual,float $expected,string $label):void { vatCheck(abs($actual-$expected)<0.005,"$label : $actual au lieu de $expected"); }
function vatRefuses(callable $action,string $text):void {
    try{$action();}catch(Throwable $e){vatCheck(str_contains($e->getMessage(),$text),$e->getMessage());return;}
    throw new RuntimeException('Échec attendu : '.$text);
}
$invoice=['items_json'=>json_encode([['quantity'=>2,'amount'=>100,'tax_rate'=>0.18]]),
    'discount'=>100,'total_ttc'=>118,'collected_amount'=>118,'tax_not_invoiced'=>0];
$amounts=ManualTreasuryService::vatSnapshotAmounts($invoice);
vatNear($amounts['ht'],100,'HT remisé');vatNear($amounts['vat'],18,'TVA remisée');
$partial=ManualTreasuryService::vatSnapshotAmounts(array_replace($invoice,['collected_amount'=>59]));
vatNear($partial['ht'],50,'HT encaissé partiellement');vatNear($partial['vat'],9,'TVA encaissée partiellement');
$mixed=ManualTreasuryService::vatSnapshotAmounts(array_replace($invoice,['items_json'=>json_encode(['items'=>[
    ['amount'=>100,'tax_rate'=>0.18],['amount'=>100,'tax_rate'=>0.09]]]),'total_ttc'=>113.5,'collected_amount'=>113.5]));
vatNear($mixed['vat'],13.5,'Taux mixtes');
vatNear(ManualTreasuryService::vatSnapshotAmounts(array_replace($invoice,['tax_not_invoiced'=>1]))['vat'],0,'Exonération');
vatNear(ManualTreasuryService::vatSnapshotAmounts(array_replace($invoice,['discount'=>999]))['vat'],0,'Remise plafonnée');
vatNear(ManualTreasuryService::vatSnapshotAmounts(array_replace($invoice,['discount'=>-1]))['vat'],36,'Remise négative neutralisée');
vatNear(ManualTreasuryService::vatSnapshotAmounts(['items_json'=>'{}','total_ttc'=>0])['vat'],0,'Facture vide');
$code=$invoice;$code['items_json']=json_encode([['amount'=>200,'taxes'=>['A']]]);
vatNear(ManualTreasuryService::vatSnapshotAmounts($code,['A'=>0.18])['vat'],18,'Taux configuré');

$snapshot=array_merge($invoice,['id'=>7,'invoice_id'=>10,'snapshot_date'=>'2026-09-30','paid_at'=>'2026-09-30 12:00:00',
    'source_items_json'=>$invoice['items_json'],'snapshot_tax_not_invoiced'=>0,
    'amount_ht'=>100,'amount_vat'=>36,'amount_ttc'=>118,'snapshot_collected_amount'=>118]);
vatCheck(fineaVatDiscountCorrection($snapshot)['after_vat']===18.0,'Ancien instantané non corrigé');
vatCheck(fineaVatDiscountCorrection(array_replace($snapshot,['amount_vat'=>18]))===null,'Correction répétée');
vatCheck(fineaVatDiscountCorrection(array_replace($snapshot,['amount_vat'=>30]))===null,'Montant inexpliqué modifié');
vatCheck(fineaVatDiscountCorrection(array_replace($snapshot,['source_items_json'=>'[]']))===null,'Source modifiée acceptée');
vatCheck(fineaVatDiscountCorrection(array_replace($snapshot,['discount'=>50]))===null,'Remise modifiée acceptée');
vatCheck(fineaVatDiscountCorrection(array_replace($snapshot,['items_json'=>$code['items_json'],'source_items_json'=>$code['items_json']]))===null,'Taux non figé accepté');

// Exécuter le SQL financier réel dans SQLite. Seules l'introspection MySQL,
// les clauses FOR UPDATE et les modules BTP hors de ce scénario sont adaptés.
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite requis pour les tests (pas pour la production).');
final class VatTestDatabase extends PDO
{
    public bool $failRepayment=false;
    public function __construct(){
        parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $this->sqliteCreateFunction('CONCAT',static fn(...$parts)=>implode('',$parts));
        $this->sqliteCreateFunction('LEAST',static fn($a,$b)=>min($a,$b));
        $this->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));
        $this->sqliteCreateFunction('CURDATE',static fn()=>date('Y-m-d'));
    }
    private function adapt(string $sql):string {
        if(str_starts_with($sql,'SHOW '))return 'SELECT NULL WHERE 0';
        if(preg_match('/FROM (fiche_decaissement|employee_legal_requests|ligne_debourse_banamur|finea_recurring_need_generations)\b/',$sql)){
            preg_match_all('/:[a-z_]+/i',$sql,$matches);
            return 'SELECT '.($matches[0]?implode(',',array_unique($matches[0])):'NULL').' WHERE 0';
        }
        if(str_contains($sql,'FROM facture_vente_banamur f '))return 'SELECT :from,:to WHERE 0';
        return str_replace(' FOR UPDATE','',$sql);
    }
    public function prepare(string $query,array $options=[]):PDOStatement|false {
        if($this->failRepayment&&str_starts_with($query,'UPDATE treasury_loans'))throw new RuntimeException('Échec simulé de restitution');
        return parent::prepare($this->adapt($query),$options);
    }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
        $sql=$this->adapt($query);
        return $fetchMode===null?parent::query($sql):parent::query($sql,$fetchMode,...$fetchModeArgs);
    }
}
$db=new VatTestDatabase();
$db->exec(<<<'SQL'
CREATE TABLE treasury_opening_balances(id INTEGER PRIMARY KEY,effective_date TEXT,amount NUMERIC);
CREATE TABLE treasury_loans(id INTEGER PRIMARY KEY,loan_type TEXT,reference TEXT,principal_amount NUMERIC,repaid_amount NUMERIC,
    received_date TEXT,due_date TEXT,vat_repayment_due_date TEXT,status TEXT,vat_repayment_status TEXT,vat_repaid_at TEXT,vat_repaid_by INTEGER,note TEXT,updated_at TEXT);
CREATE TABLE treasury_manual_movements(id INTEGER PRIMARY KEY,amount NUMERIC,direction TEXT,status TEXT,movement_date TEXT,
    cancelled_at TEXT,chantier_id INTEGER,ligne_devis_id INTEGER,created_by_user_id INTEGER);
CREATE TABLE treasury_fund_transfers(id INTEGER PRIMARY KEY,reference TEXT,note TEXT,amount NUMERIC,transfer_date TEXT,transfer_type TEXT,created_by_user_id INTEGER,cancelled_at TEXT);
CREATE TABLE treasury_vat_remittances(amount NUMERIC);
CREATE TABLE treasury_vat_fund_allocations(amount NUMERIC,allocation_type TEXT,allocation_date TEXT);
CREATE TABLE treasury_vat_invoice_snapshots(id INTEGER PRIMARY KEY,invoice_id INTEGER,amount_vat NUMERIC,snapshot_date TEXT);
CREATE TABLE facture_vente_banamur(id_facture_vente INTEGER,reference_interne TEXT,client_company_name TEXT,chantier_id INTEGER,
    paiement_statut TEXT,paid_at TEXT,collected_amount NUMERIC,total_ttc NUMERIC,discount NUMERIC,tax_not_invoiced INTEGER,items_json TEXT);
CREATE TABLE chantier_banamur(id INTEGER,code TEXT,titre TEXT);
CREATE TABLE ligne_devis_banamur(id INTEGER,designation TEXT);
CREATE TABLE user_devis_banamur(id INTEGER,prenom TEXT,nom TEXT,photo TEXT,personnel_id INTEGER);
CREATE TABLE personnel(id_personnel_soignant INTEGER,photo_personnel_soignant TEXT,tel_personnel_soignant TEXT);
INSERT INTO treasury_opening_balances VALUES(1,'2000-01-01',1000);
INSERT INTO treasury_loans(id,loan_type,reference,principal_amount,repaid_amount,received_date,status,vat_repayment_status)
    VALUES(1,'vat_credit','TVA-test',100,0,'2026-01-01','active','pending');
INSERT INTO treasury_fund_transfers(id,amount,transfer_date,transfer_type) VALUES(1,500,'2026-01-01','to_available');
SQL);
$db->beginTransaction();$service=new ManualTreasuryService($db);$db->commit();
$from='2000-01-01';$to='2099-12-31';
$before=$service->dashboard($from,$to);vatNear($before['available'],500,'Disponible avant restitution');
$service->repayVatLoan(1,2);
$after=$service->dashboard($from,$to);
vatNear($after['available'],400,'Disponible après restitution');
vatNear($after['realEntriesBalance'],$before['realEntriesBalance'],'Pas de seconde déduction sur les entrées');
vatNear($after['totals']['manual_real_out'],100,'Restitution visible dans les sorties');
vatNear($after['vat']['vat_outstanding'],0,'Dette TVA soldée');
vatRefuses(fn()=>$service->repayVatLoan(1,2),'plus à restituer');
vatNear($service->dashboard($from,$to)['available'],400,'Double clic sans double débit');
vatCheck(count(array_filter($after['manual'],static fn($r)=>$r['movement_source']==='vat_repayment'))===1,'Restitution absente ou doublonnée');
$old=new ReflectionMethod(ManualTreasuryService::class,'vatLoanRepaymentRows');
vatCheck($old->invoke($service,'2000-01-01',date('Y-m-d',strtotime('-1 day')))===[],'Date de restitution ignorée');
vatRefuses(fn()=>$service->returnToRealEntries(450,date('Y-m-d'),'','',2),'disponible réel');
$db->exec("INSERT INTO treasury_loans(id,loan_type,principal_amount,repaid_amount,received_date,status,vat_repayment_status) VALUES(2,'vat_credit',450,0,'2026-01-01','active','pending')");
vatRefuses(fn()=>$service->repayVatLoan(2,2),'insuffisant');
vatCheck($db->query('SELECT status FROM treasury_loans WHERE id=2')->fetchColumn()==='active','Refus sans rollback');
$db->exec('UPDATE treasury_loans SET principal_amount=50 WHERE id=2');$db->failRepayment=true;
vatRefuses(fn()=>$service->repayVatLoan(2,2),'Échec simulé');$db->failRepayment=false;
vatCheck(!$db->inTransaction(),'Transaction non annulée');
vatNear($service->dashboard($from,$to)['available'],400,'Échec sans effet financier');
// Un prêt déjà soldé par l'ancienne version doit aussi être pris en compte.
$db->exec("UPDATE treasury_loans SET status='repaid',repaid_amount=50,vat_repayment_status='repaid',vat_repaid_at=NOW() WHERE id=2");
vatNear($service->dashboard($from,$to)['available'],350,'Restitution historique reconnue');
$db->exec("INSERT INTO treasury_loans(id,loan_type,principal_amount,repaid_amount,received_date,status,vat_repayment_status,vat_repaid_at) VALUES(3,'external',900,900,'2026-01-01','repaid','repaid',NOW()),(4,'vat_credit',800,800,'2026-01-01','cancelled','repaid',NOW())");
vatNear($service->dashboard($from,$to)['available'],350,'Prêts externes et annulés exclus');
echo "OK : remise, taux mixtes, exonération, encaissement partiel, audit historique, disponible, dette, double clic, dates et rollback.\n";
