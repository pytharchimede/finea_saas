<?php
declare(strict_types=1);
require_once __DIR__.'/RhFinancialPointsTest.php';
require_once __DIR__.'/../services/RhFinancialWhatsAppTemplateMonitor.php';
$pdo->sqliteCreateFunction('DATABASE',static fn()=>'rh_test');$pdo->sqliteCreateFunction('GET_LOCK',static fn()=>1);$pdo->sqliteCreateFunction('RELEASE_LOCK',static fn()=>1);
$sql=file_get_contents(__DIR__.'/../database/migrations/20261002_rh_whatsapp_template_monitor.sql');$sql=preg_replace('/\) ENGINE=[^;]+;/m',');',$sql);$sql=preg_replace('/BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY/','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);$sql=preg_replace('/,?\s*KEY \w+\([^)]*\)/','',$sql);$sql=str_replace(' ON UPDATE CURRENT_TIMESTAMP','',$sql);$pdo->exec($sql);
$pdo->exec("CREATE TABLE system_notifications(id INTEGER PRIMARY KEY AUTOINCREMENT,created_at TEXT,event_key TEXT,title TEXT,message TEXT,link_url TEXT,recipient_team TEXT,channels_json TEXT);UPDATE user_devis_banamur SET acces_admin=1 WHERE id=1;UPDATE rh_financial_settings SET advance_sid=NULL,loan_sid=NULL,advance_active_at=NULL,loan_active_at=NULL;");
final class RhTemplateTestApi extends RhFinancialWhatsAppTemplateService
{
    public array $contents=[];public array $approvals=[];public int $creates=0;public int $requests=0;public bool $fail=false;public bool $failSubmission=false;public bool $failCreate=false;public $afterObservation=null;
    public function __construct(){parent::__construct('AC'.str_repeat('a',32),'test-secret');}
    protected function request(string $method,string $path,?array $payload=null):array
    {
        $this->requests++;if($this->fail)throw new RuntimeException('HTTP 503 token=secret');
        if($method==='POST'&&$path==='/Content'){
            if($this->failCreate)throw new RuntimeException('Twilio response uncertain');
            $this->creates++;$sid='HX'.str_pad((string)$this->creates,32,'0',STR_PAD_LEFT);$this->contents[$sid]=$payload;$this->approvals[$sid]=['status'=>'pending','category'=>'UTILITY','rejection_reason'=>''];return ['sid'=>$sid];
        }
        preg_match('#^/Content/(HX[a-f0-9]{32})#',$path,$m);$sid=$m[1]??'';
        if($method==='GET'&&!str_contains($path,'ApprovalRequests'))return $this->contents[$sid];
        if($method==='POST'){if($this->failSubmission)throw new RuntimeException('Approval submission unavailable');return ['status'=>'pending'];}
        if($this->afterObservation){($this->afterObservation)();$this->afterObservation=null;}
        return ['whatsapp'=>$this->approvals[$sid]];
    }
}
$api=new RhTemplateTestApi();$monitor=new RhFinancialWhatsAppTemplateMonitor($pdo,$api,'whatsapp:+2250100000000');
fails(fn()=>$monitor->create('advance',3),'Template creation restricted to administrator');
$advance=$monitor->create('advance',1);$loan=$monitor->create('loan',1);
check($api->creates===2 && $advance['sid']!==$loan['sid'],'Two separate Twilio models created and submitted');
check(str_contains($api->contents[$advance['sid']]['types']['twilio/call-to-action']['body'],'avances') && str_contains($api->contents[$loan['sid']]['types']['twilio/call-to-action']['body'],'prêts'),'Model text corresponds to each financial point');
fails(fn()=>$monitor->create('advance',1),'Repeated creation blocked while Meta approval is pending');
$monitor->poll('advance',true);check(empty($service->settings()['advance_active_at']),'Pending model cannot activate financial cutoff');
$api->approvals[$advance['sid']]['status']='approved';$monitor->poll('advance',true);$cutoff=$service->settings()['advance_active_at'];
check(!empty($cutoff) && $service->settings()['advance_sid']===$advance['sid'],'Approved Utility model activates automatically');
$events=(int)$pdo->query('SELECT COUNT(*) FROM rh_financial_template_events')->fetchColumn();$monitor->poll('advance',true);
check((int)$pdo->query('SELECT COUNT(*) FROM rh_financial_template_events')->fetchColumn()===$events,'Unchanged approval does not duplicate events or alerts');
$requests=$api->requests;$monitor->poll('advance');check($api->requests===$requests,'Passive screen and cron checks are throttled');
$api->fail=true;$failed=$monitor->poll('advance',true);check($failed['status']==='approved' && $service->settings()['advance_active_at']===$cutoff && !str_contains($failed['error'],'secret'),'Network failure preserves active model and masks diagnostic');$api->fail=false;
$api->approvals[$loan['sid']]=['status'=>'approved','category'=>'MARKETING','rejection_reason'=>''];$blocked=$monitor->poll('loan',true);
check(empty($service->settings()['loan_active_at']) && !empty($blocked['activation_error']),'Unexpected Meta category prevents activation');
$api->approvals[$loan['sid']]['category']='UTILITY';$monitor->setAutomatic('loan',false,1);$monitor->poll('loan',true);
check(empty($service->settings()['loan_active_at']),'Automatic activation can be disabled before approval');
$monitor->setAutomatic('loan',true,1);$monitor->poll('loan',true);check(!empty($service->settings()['loan_active_at']),'Enabling automatic activation activates an approved model');
$api->approvals[$advance['sid']]['status']='rejected';$monitor->poll('advance',true);$replacement=$monitor->create('advance',1);
check($service->settings()['advance_sid']===$advance['sid'] && $service->settings()['advance_active_at']===$cutoff,'Pending replacement preserves previous active SID and historical cutoff');
$api->approvals[$replacement['sid']]['status']='approved';$monitor->poll('advance',true);
check($service->settings()['advance_sid']===$replacement['sid'] && $service->settings()['advance_active_at']===$cutoff,'Approved replacement changes SID without changing historical cutoff');
fails(fn()=>$monitor->attach('advance',$loan['sid'],1),'Wrong-kind existing model cannot be attached');
$api->failSubmission=true;fails(fn()=>$monitor->create('salary',1),'Submission failure reported after creation');$salary=$monitor->state('salary');
check($salary['status']==='created' && !empty($salary['sid']),'Created SID retained when Meta submission fails');$count=$api->creates;$api->failSubmission=false;$monitor->submitExisting('salary',1);check($api->creates===$count,'Resubmission reuses existing Content SID');
check(!empty($salary['auto_activate']),'First salary creation enables automatic activation');
$attached=$monitor->attach('salary',$salary['sid'],1);check($attached['name']===$api->contents[$salary['sid']]['friendly_name'],'Attached model recovers its Twilio name');
$api->approvals[$salary['sid']]['status']='approved';$monitor->poll('salary',true);check($service->settings()['salary_sid']===$salary['sid'] && !empty($service->settings()['salary_active_at']),'Salary model activates after Utility approval');
$api->approvals[$salary['sid']]['status']='rejected';$monitor->poll('salary',true);$api->failCreate=true;fails(fn()=>$monitor->create('salary',1),'Ambiguous creation response reported');
check($monitor->state('salary')['status']==='creation_uncertain','Ambiguous creation blocks automatic duplication');fails(fn()=>$monitor->create('salary',1),'Creation uncertainty requires reconciliation');$api->failCreate=false;
// Simulate a manual SID change after the API response but before the activation transaction.
$api->approvals[$replacement['sid']]['status']='rejected';$monitor->poll('advance',true);$candidate=$monitor->create('advance',1);$api->approvals[$candidate['sid']]['status']='approved';$manual='HX'.str_repeat('f',32);
$api->afterObservation=static function()use($pdo,$manual){$pdo->prepare('UPDATE rh_financial_settings SET advance_sid=? WHERE id=1')->execute([$manual]);};
fails(fn()=>$monitor->poll('advance',true),'Concurrent manual configuration cannot be overwritten');check($service->settings()['advance_sid']===$manual,'Manual configuration preserved atomically');
$state=['status'=>'approved','failures'=>0];for($i=0;$i<12;$i++)$state=RhFinancialWhatsAppTemplateMonitor::observation($state,null,'unavailable',1000);
check($state['next_check']===22600 && $state['status']==='approved','Network retry backoff capped at six hours');
echo "All RH WhatsApp monitor checks passed.\n";
