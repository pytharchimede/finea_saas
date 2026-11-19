<?php
declare(strict_types=1);
require __DIR__.'/RhFinancialPointsTest.php';

// Boundary doubles persist in the same transaction, exercising rollback and idempotency.
final class NotificationManager
{
    public function __construct(private PDO $pdo){}
    public function notifyRhFinancialLine(int $user,string $stage,string $label,int $amount,string $num):int
    {
        $this->pdo->prepare('INSERT INTO test_notifications(user_id,stage,label,amount,num) VALUES(?,?,?,?,?)')->execute([$user,$stage,$label,$amount,$num]);return (int)$this->pdo->lastInsertId();
    }
}
final class PayrollManager
{
    public function __construct(private PDO $pdo){}
    public function syncDebtFromLegalRequest(array $request,array $actor=[]):bool
    {
        if(empty($request['payload']['rh_financial_paid_at']))throw new RuntimeException('Unpaid debt');
        $amount=$request['payload']['montant'] ?? $request['payload']['montant_demande'];
        $this->pdo->prepare('INSERT INTO test_debts(request_id,amount) VALUES(?,?)')->execute([$request['id'],$amount]);return true;
    }
}
$pdo->exec('CREATE TABLE test_notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INT,stage TEXT,label TEXT,amount INT,num TEXT);CREATE TABLE test_debts(request_id INTEGER PRIMARY KEY,amount INT);');
$id=$service->create('advance','',[2],1,$budget);proof(1,$id,1,'rh');$service->stop($id,1,1);
$token=str_repeat('b',64);$pdo->prepare('UPDATE rh_financial_point_versions SET token_hash=?,token_expires_at=? WHERE point_id=?')->execute([hash('sha256',$token),date('Y-m-d H:i:s',time()+3600),$id]);
proof(2,$id,1,'dg');$feb=$service->sign($id,1,2,['advance_2'=>['amount'=>25000]],$token);
$q=$pdo->prepare('SELECT * FROM employee_legal_requests WHERE id=?');$q->execute([$feb]);$r=$q->fetch(PDO::FETCH_ASSOC);$r['payload']=json_decode($r['payload_json'],true);$r['status']='approved';
$pdo->prepare("UPDATE employee_legal_requests SET status='approved' WHERE id=?")->execute([$feb]);$pdo->beginTransaction();$num=$service->createFiche($r,[]);$pdo->commit();
$settlement=new RhFinancialSettlementService($pdo);$settlement->sync($num,'authorized');
check((int)$pdo->query('SELECT COUNT(*) FROM test_notifications')->fetchColumn()===0,'No authorization alert before fiche approval');
$pdo->prepare('UPDATE fiche_decaissement SET approuve=1 WHERE num_fiche=?')->execute([$num]);
$settlement->sync($num,'authorized');$settlement->sync($num,'authorized');
check((int)$pdo->query('SELECT COUNT(*) FROM test_notifications')->fetchColumn()===1,'Authorization alert emitted once across repeated callbacks');
check((int)$pdo->query('SELECT amount FROM test_notifications')->fetchColumn()===25000,'Beneficiary alert uses DG amount');
check((int)$pdo->query('SELECT COUNT(*) FROM test_debts')->fetchColumn()===0,'Authorized advance does not enter payroll debt');
$settlement->sync($num,'paid');check((int)$pdo->query('SELECT COUNT(*) FROM test_debts')->fetchColumn()===0,'Payment remains ineffective while fiche is not decaissed');
$pdo->prepare('UPDATE fiche_decaissement SET decaisse=1,date_decaisse=NOW() WHERE num_fiche=?')->execute([$num]);
$settlement->sync($num,'paid');$settlement->sync($num,'paid');
check((int)$pdo->query("SELECT COUNT(*) FROM test_notifications WHERE stage='paid'")->fetchColumn()===1,'Effective disbursement alert emitted once');
check((int)$pdo->query('SELECT COUNT(*) FROM test_debts')->fetchColumn()===1 && (int)$pdo->query('SELECT amount FROM test_debts')->fetchColumn()===25000,'Debt created once for exact paid amount');
$source=json_decode($pdo->query('SELECT payload_json FROM employee_legal_requests WHERE id=2')->fetchColumn(),true);check(!empty($source['rh_financial_paid_at']),'Paid state persisted in source request');
$p=$service->get($id);unlink(__DIR__.'/../'.$p['pdf_path']);echo "All RH settlement checks passed.\n";

require_once __DIR__.'/../services/DisbursementBeneficiaryNotificationService.php';
$pdo->exec("INSERT INTO fiche_decaissement(num_fiche,parent_num_fiche,code_parentale,personnel_id,montant_fiche,details_demande,approuve,decaisse) VALUES('FEB-TEST','','',3,15000,'Transport chantier',0,0)");
$generic=new DisbursementBeneficiaryNotificationService($pdo);$generic->sync('FEB-TEST','authorized');
check((int)$pdo->query('SELECT COUNT(*) FROM finea_fiche_beneficiary_alerts')->fetchColumn()===0,'FEB beneficiary waits for authorization');
$pdo->exec("UPDATE fiche_decaissement SET approuve=1 WHERE num_fiche='FEB-TEST'");$generic->sync('FEB-TEST','authorized');$generic->sync('FEB-TEST','authorized');
check((int)$pdo->query('SELECT COUNT(*) FROM finea_fiche_beneficiary_alerts')->fetchColumn()===1,'FEB beneficiary authorization is idempotent');
$pdo->exec("UPDATE fiche_decaissement SET decaisse=1 WHERE num_fiche='FEB-TEST'");$generic->sync('FEB-TEST','paid');$generic->sync('FEB-TEST','paid');
check((int)$pdo->query('SELECT COUNT(*) FROM finea_fiche_beneficiary_alerts')->fetchColumn()===2,'FEB beneficiary payment is idempotent');
echo "All beneficiary checks passed.\n";
