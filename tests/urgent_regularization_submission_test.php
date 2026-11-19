<?php
declare(strict_types=1);
require_once __DIR__.'/../repositories/UrgentDisbursementRepository.php';
if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('pdo_sqlite requis pour ce test.');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));
$pdo->exec(<<<'SQL'
CREATE TABLE urgent_disbursement_requests(id INTEGER PRIMARY KEY,requester_user_id INTEGER,requester_name TEXT,
    chantier_id INTEGER,chantier_code TEXT,status TEXT,regularized_feb_request_id INTEGER,regularization_due_at TEXT,
    decaissed_at TEXT,submitted_at TEXT);
CREATE TABLE employee_legal_requests(id INTEGER PRIMARY KEY,request_type TEXT,status TEXT,payload_json TEXT);
INSERT INTO urgent_disbursement_requests VALUES(1,10,'Demandeur',20,'CH20','decaissed',NULL,'2000-01-01','2000-01-01','2000-01-01');
INSERT INTO urgent_disbursement_requests VALUES(2,11,'Demandeur',21,'CH21','regularization_pending',100,'2000-01-01','2000-01-01','2000-01-01');
INSERT INTO employee_legal_requests VALUES(100,'feb','submitted','{"urgent_decaissement_id":2}');
SQL);
$pdo->beginTransaction();$repo=new UrgentDisbursementRepository($pdo);$pdo->commit();
function urgentCheck(bool $ok,string $text):void{if(!$ok)throw new RuntimeException($text);}
urgentCheck(count($repo->listExpiredUnregularizedForUser(10))===1,'Urgence non soumise débloquée');
urgentCheck($repo->findBlockingForChantier(20)!==null,'Chantier sans FEB débloqué');
foreach(['submitted','approved','rejected'] as $status){
    $pdo->prepare('UPDATE employee_legal_requests SET status=? WHERE id=100')->execute([$status]);
    urgentCheck($repo->listExpiredUnregularizedForUser(11)===[],'Soumission bloquée : '.$status);
    urgentCheck($repo->listOpenUnregularizedForUser(11)===[],'Demandeur encore bloqué : '.$status);
    urgentCheck($repo->findBlockingForChantier(21)===null,'Chantier encore bloqué : '.$status);
    urgentCheck($repo->findActiveUrgencyForChantier(21)===null,'Nouvelle urgence interdite après soumission');
    urgentCheck($pdo->query('SELECT status FROM urgent_disbursement_requests WHERE id=2')->fetchColumn()==='regularization_pending','Déblocage confondu avec régularisation définitive');
}
urgentCheck(count($repo->listBlockedChantiers())===1,'Liste des chantiers bloqués incorrecte');
echo "OK : déblocage dès soumission sans approbation ; statut de régularisation conservé.\n";
