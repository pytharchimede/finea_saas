<?php
declare(strict_types=1);
require_once __DIR__.'/../services/WhatsAppAdminConsoleService.php';
require_once __DIR__.'/../services/RhFinancialSchema.php';
final class ConsolePdo extends PDO{
 public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->sqliteCreateFunction('DATABASE',fn()=>'console_test');$this->sqliteCreateFunction('GET_LOCK',fn()=>1);$this->sqliteCreateFunction('RELEASE_LOCK',fn()=>1);}
 private function sql(string $sql):string{return str_replace('information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME','sqlite_master WHERE type=\'table\' AND name',$sql);}
 public function prepare(string $q,array $o=[]):PDOStatement|false{return parent::prepare($this->sql($q),$o);}
 public function query(string $q,?int $m=null,mixed ...$a):PDOStatement|false{return $m===null?parent::query($this->sql($q)):parent::query($this->sql($q),$m,...$a);}
}
function ok(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
function rejects(callable $f,string $label):void{try{$f();}catch(DomainException|RuntimeException $e){echo "PASS $label\n";return;}throw new LogicException('Expected failure: '.$label);}
function installConsole(PDO $pdo):void{foreach(RhFinancialSchema::statements(file_get_contents(__DIR__.'/../database/migrations/20261003_whatsapp_admin_console.sql')) as $sql){$sql=preg_replace('/\) ENGINE=.+$/s',')',$sql);$sql=str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);$sql=preg_replace('/UNIQUE KEY \w+\(/','UNIQUE(',$sql);$sql=preg_replace('/,?\s*KEY \w+\([^)]*\)/','',$sql);$pdo->exec($sql);}}
class ConsoleApi extends WhatsAppAdminContentService{
 public array $contents=[];public array $approvals=[];public int $creates=0;public bool $failCreate=false;public bool $failSubmit=false;public bool $failRead=false;public string $delivery='delivered';
 public function __construct(){parent::__construct('AC'.str_repeat('a',32),'test');}
 protected function request(string $method,string $path,?array $payload=null):array{
  if($this->failRead&&$method==='GET')throw new RuntimeException('Unavailable token=secret');
  if(str_starts_with($path,'/Content?'))return ['contents'=>array_values($this->contents),'meta'=>['next_page_url'=>null]];
  if($path==='/Content'&&$method==='POST'){if($this->failCreate)throw new RuntimeException('Network uncertain');$sid='HX'.str_pad((string)++$this->creates,32,'0',STR_PAD_LEFT);$this->contents[$sid]=['sid'=>$sid]+$payload;$this->approvals[$sid]=['status'=>'unknown','category'=>'UTILITY','rejection_reason'=>''];return ['sid'=>$sid];}
  preg_match('#^/Content/(HX[a-f0-9]{32})#',$path,$m);$sid=$m[1];
  if(str_contains($path,'ApprovalRequests')){if($method==='POST'){if($this->failSubmit)throw new RuntimeException('Submission unavailable');$this->approvals[$sid]['status']='pending';$this->approvals[$sid]['category']=$payload['category'];return ['status'=>'pending'];}return ['whatsapp'=>$this->approvals[$sid]];}
  return $this->contents[$sid];
 }
 protected function messageRequest(string $sid):array{return ['status'=>$this->delivery,'error_code'=>'','error'=>''];}
}
class ConsoleChannel extends NotificationWhatsAppChannelService{
 public array $calls=[];public function sendTemplateDetailed(string $to,string $sid,array $vars=[]):array{$this->calls[]=[$to,$sid,$vars];return ['ok'=>true,'message_sid'=>'SM'.str_repeat('b',32),'status'=>'queued'];}
}
$pdo=new ConsolePdo();ok(!WhatsAppAdminConsoleService::ready($pdo),'Missing migration detected without writes');installConsole($pdo);ok(WhatsAppAdminConsoleService::ready($pdo),'Installer creates console schema');
$pdo->exec("CREATE TABLE user_devis_banamur(id INT,active INT,acces_admin INT);INSERT INTO user_devis_banamur VALUES(1,1,1),(2,1,0),(3,0,1);CREATE TABLE rh_financial_settings(id INT,advance_sid TEXT,advance_active_at TEXT,loan_sid TEXT,loan_active_at TEXT,salary_sid TEXT,salary_active_at TEXT);INSERT INTO rh_financial_settings VALUES(1,'HXffffffffffffffffffffffffffffffff','2026-10-02',NULL,NULL,NULL,NULL);");
$original=$pdo->query('SELECT * FROM rh_financial_settings')->fetchAll(PDO::FETCH_ASSOC);$api=new ConsoleApi();$channel=new ConsoleChannel();$s=new WhatsAppAdminConsoleService($pdo,$api,$channel);
$input=['name'=>'finea_test','body'=>'Bonjour {{1}}, votre point est prêt. Merci.','samples'=>['1'=>'Ulrich']];
rejects(fn()=>$s->create($input,2),'Non-admin creation rejected');rejects(fn()=>$s->create($input,3),'Inactive administrator rejected');
$id=$s->create($input,1);$r=$s->template($id);ok($r['status']==='pending'&&$r['content_sid']&&$api->creates===1,'Creation stores SID and submits Meta');
rejects(fn()=>$s->sendTest($id,'+2250700000000',['1'=>'Ulrich'],1),'Pending template cannot be sent');ok(count($channel->calls)===0,'Blocked sends have no delivery side effect');
$api->approvals[$r['content_sid']]['status']='approved';$s->poll($id,1);ok($s->template($id)['status']==='approved','Approval status persisted');
$s->sendTest($id,'+2250700000000',['1'=>'<b>Ulrich</b>'],1);ok($channel->calls[0][2]===[1=>'<b>Ulrich</b>'],'Generic dynamic variables passed to Twilio');$events=$s->history();$event=$events[0];ok($event['action']==='test'&&$event['status']==='accepted'&&$event['actor_user_id']==1&&$event['recipient_phone']==='+2250700000000','Tests have durable actor recipient and message SID audit');
$s->checkDelivery((int)$event['id'],1);ok($pdo->query("SELECT status FROM whatsapp_admin_events WHERE action='test'")->fetchColumn()==='delivered','Actual delivery can be refreshed independently');
$count=count($s->history());$s->poll($id,1);ok(count($s->history())===$count,'Unchanged status creates no duplicate log');
$api->failRead=true;rejects(fn()=>$s->poll($id,1),'API error reported');$failed=$s->template($id);ok($failed['status']==='approved'&&!str_contains($failed['last_error'],'secret'),'API error preserves approval and redacts secrets');$api->failRead=false;
$s->syncInventory('',1);$s->syncInventory('',1);ok(count($s->templates())===1,'Repeated inventory synchronization does not duplicate models');
$api->failSubmit=true;rejects(fn()=>$s->create($input,1),'Submission failure reported');$failedId=(int)$pdo->query('SELECT MAX(id) FROM whatsapp_admin_templates')->fetchColumn();ok($s->template($failedId)['status']==='created'&&$s->template($failedId)['content_sid'],'Submission failure keeps recoverable SID');$s->poll($failedId,1);ok($s->template($failedId)['status']==='created','Unknown approval keeps unsent model eligible for submission');$api->failSubmit=false;$before=$api->creates;$s->submit($failedId,1);ok($api->creates===$before,'Submission retry never creates a new Content');
$api->failCreate=true;rejects(fn()=>$s->create($input,1),'Ambiguous creation recorded');$uncertain=(int)$pdo->query('SELECT MAX(id) FROM whatsapp_admin_templates')->fetchColumn();ok($s->template($uncertain)['status']==='creation_uncertain','Ambiguous result requires SID reconciliation');rejects(fn()=>$s->submit($uncertain,1),'Uncertain creation cannot be blindly submitted');$api->failCreate=false;
$uncertainRow=$s->template($uncertain);$recoveredSid='HX'.str_repeat('c',32);$recovered=json_decode($uncertainRow['definition_json'],true);$api->contents[$recoveredSid]=['sid'=>$recoveredSid]+$recovered;$api->approvals[$recoveredSid]=['status'=>'unknown','category'=>'UTILITY','rejection_reason'=>''];
rejects(fn()=>$s->reconcile($uncertain,$r['content_sid'],1),'Wrong model cannot reconcile an uncertain creation');
$s->reconcile($uncertain,$recoveredSid,1);ok($s->template($uncertain)['content_sid']===$recoveredSid&&$s->template($uncertain)['status']==='created','Matching SID recovers uncertain creation without recreation');
$signature=['name'=>'finea_signature','body'=>'Bonjour {{1}}, votre point est prêt. Merci.','button_title'=>'Signer','button_url'=>'https://projet.fidest.ci/rh/validation_point_financier.php?token={{2}}','samples'=>['1'=>'DG','2'=>'token']];
$custom=$s->create(['name'=>'custom_seven','body'=>'Bonjour {{1}}, {{2}} et {{3}} sont prêts avec {{4}}, {{5}}, {{6}} et {{7}}. Merci.','samples'=>array_fill(1,7,'exemple')],1);$cr=$s->template($custom);$api->approvals[$cr['content_sid']]['status']='approved';$s->sendTest($custom,'+2250700000000',array_fill(1,7,'custom'),1);ok(end($channel->calls)[2][7]==='custom','Generic variable seven remains editable');
$signature['button_url']='https://projet.fidest.ci/rh/validation_point_financier.php?token={{7}}';$signature['body']='Bonjour {{1}}, point {{2}} du {{3}} : {{4}} lignes et {{5}} FCFA. Votre accès expire le {{6}}. Merci.';$signature['samples']=array_fill(1,7,'test');$sig=$s->create($signature,1);$sr=$s->template($sig);$api->approvals[$sr['content_sid']]['status']='approved';$s->sendTest($sig,'+2250700000000',array_fill(1,7,'REAL'),1);ok(end($channel->calls)[2][7]===str_repeat('a',64),'FINEA signature uses fictitious token even for imported old versions');
installConsole($pdo);ok($pdo->query('SELECT * FROM rh_financial_settings')->fetchAll(PDO::FETCH_ASSOC)===$original,'Console operations and reinstallation preserve business settings and activation dates');
foreach([
 ['name'=>'BadName','body'=>'Hello'],['name'=>'valid','body'=>'Hello {{2}}.','samples'=>['2'=>'x']],['name'=>'valid','body'=>'Hello {{1}}.'],['name'=>'valid','body'=>'Hello','button_title'=>'Open','button_url'=>'http://example.test'],['name'=>'valid','body'=>'Hello','category'=>'AUTHENTICATION']
] as $bad)rejects(fn()=>WhatsAppAdminContentService::definitionFromInput($bad),'Invalid template definition rejected');
ok(WhatsAppAdminContentService::definitionFromInput(['name'=>'static','body'=>'Bonjour, le point est arrêté.'])['definition']['types']['twilio/text']['body']==='Bonjour, le point est arrêté.','Static text model supported');
echo "All central WhatsApp console checks passed.\n";
