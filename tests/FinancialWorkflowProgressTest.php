<?php
declare(strict_types=1);
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';
function progressCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$dir=sys_get_temp_dir().'/finea-progress-test-'.bin2hex(random_bytes(5));mkdir($dir,0700);
$token=bin2hex(random_bytes(24));
try{
 $progress=new FinancialWorkflowProgress($token,'PHPSESSID','owner-session',$dir);
 $progress->append('Contrôle fournisseur.');$progress->append('Lot traité.',2,5);
 $data=FinancialWorkflowProgress::read($token,['PHPSESSID'=>'owner-session'],$dir);
 progressCheck(count($data['events'])===3&&$data['state']==='running','Real progress events missing');
 progressCheck($data['events'][2]['done']===2&&$data['events'][2]['total']===5,'Real batch count missing');
 progressCheck(!isset($data['owner'],$data['session_name']),'Session metadata exposed');
 progressCheck(FinancialWorkflowProgress::read($token,['PHPSESSID'=>'other-session'],$dir)===null,'Another session can read the log');
 progressCheck(FinancialWorkflowProgress::read($token,[],$dir)===null,'Anonymous progress read allowed');
 progressCheck(FinancialWorkflowProgress::read('../bad',[],$dir)===null,'Unsafe path accepted');
 $progress->finish();progressCheck(FinancialWorkflowProgress::read($token,['PHPSESSID'=>'owner-session'],$dir)['state']==='finished','Finished response not recorded');
 $db=new PDO('sqlite::memory:');$db->exec('CREATE TABLE x(v INT)');
 FinancialWorkflowProgress::begin($db);$db->exec('INSERT INTO x VALUES(1)');FinancialWorkflowProgress::rollback($db);
 progressCheck((int)$db->query('SELECT COUNT(*) FROM x')->fetchColumn()===0,'Progress wrapper changed rollback');
 FinancialWorkflowProgress::begin($db);$db->exec('INSERT INTO x VALUES(1)');FinancialWorkflowProgress::commit($db);
 progressCheck((int)$db->query('SELECT COUNT(*) FROM x')->fetchColumn()===1,'Progress wrapper changed commit');
 echo "FinancialWorkflowProgressTest: OK (private logs, owner session, real batch counts, completion and transaction semantics)\n";
}finally{foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
