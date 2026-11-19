<?php
declare(strict_types=1);
// Isolated fixture tree: this test never connects to a database or writes real uploads.
$root=dirname(__DIR__);$sandbox=sys_get_temp_dir().'/finea-rh-doc-test-'.bin2hex(random_bytes(6));
mkdir($sandbox.'/model',0700,true);copy($root.'/model/FebValidationPolicy.php',$sandbox.'/model/FebValidationPolicy.php');mkdir($sandbox.'/services',0700,true);mkdir($sandbox.'/uploads/rh_financial_points',0700,true);
foreach(['RhFinancialPointService','RhFinancialPointPolicy','RhFinancialSignature','RhFinancialFebDocumentService'] as $name)copy($root.'/services/'.$name.'.php',$sandbox.'/services/'.$name.'.php');
file_put_contents($sandbox.'/services/PasskeySignatureGuard.php', '<?php final class PasskeySignatureGuard {public static bool $allow=false;public static function requireVerified($user,$context){if(!self::$allow)throw new RuntimeException("Proof required");}}');
file_put_contents($sandbox.'/services/RhFinancialPointPdfBuilder.php', '<?php final class RhFinancialPointPdfBuilder {public static array $last=[];public function store($point,$snapshot):string {self::$last=[$point,$snapshot];$path="uploads/rh_financial_points/copy-".bin2hex(random_bytes(5)).".pdf";file_put_contents(dirname(__DIR__)."/".$path,"%PDF fixture");return $path;}}');
file_put_contents($sandbox.'/services/FinancialPlanningSeparationService.php','<?php final class FinancialPlanningSeparationService {public static function officialDeboursePredicate($alias){return "1=1";}}');file_put_contents($sandbox.'/services/ChantierPauseService.php','<?php final class ChantierPauseService {public static function storageExists($pdo){return false;}}');
require $sandbox.'/services/RhFinancialPointService.php';require $sandbox.'/services/RhFinancialFebDocumentService.php';
function expect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function rejects(callable $callback,string $message):void {try{$callback();}catch(Throwable $e){return;}throw new RuntimeException('Expected rejection: '.$message);}
class RepairTestDb extends PDO {
    public array $point,$request,$writes=[],$events=[];public bool $tx=false,$admin=true,$rh=true,$finance=true,$active=true,$failUpdate=false;public string $image,$services='3';private array $backup=[];
    public function __construct(string $sandbox) {
        $this->image='data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX2kAAAAASUVORK5CYII=';
        $lines=[];foreach([['approved',100000],['approved',75000],['deferred',75000],['rejected',75000]] as $i=>[$decision,$amount])$lines[]=['key'=>'advance_'.($i+1),'source_id'=>$i+1,'source_kind'=>'advance','name'=>'Bénéficiaire '.($i+1),'label'=>'Avance','initial_amount'=>$amount,'amount'=>$amount,'decision'=>$decision,'note'=>'','deferred_until'=>''];
        $snapshot=['lines'=>$lines,'budget'=>['service_id'=>3,'chantier_id'=>4,'ligne_debourse_id'=>5,'debourse_id'=>6,'mode_paiement'=>'Espèces','date'=>'2026-10-14'],'rh_signature'=>['method'=>'passkey','user_id'=>1],'dg_signature'=>['method'=>'passkey','user_id'=>2]];
        $json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $this->point=['id'=>1,'revision'=>1,'kind'=>'advance','period'=>null,'status'=>'dg_signed','stopped_by'=>1,'stopped_name'=>'DRH','stopped_email'=>'rh@test','stopped_personnel_id'=>8,'stopped_phone'=>'','dg_user_id'=>2,'dg_name'=>'DG','rh_signed_at'=>'2026-10-14 10:00:00','dg_signed_at'=>'2026-10-14 11:00:00','feb_request_id'=>13573,'snapshot_json'=>$json,'snapshot_hash'=>hash('sha256',$json),'pdf_path'=>'uploads/rh_financial_points/original.pdf'];
        file_put_contents($sandbox.'/'.$this->point['pdf_path'],'%PDF ORIGINAL UNCHANGED');
        $payload=['rh_financial_point_id'=>1,'rh_financial_revision'=>1,'rh_financial_snapshot_hash'=>$this->point['snapshot_hash'],'montant'=>175000,'chantier_id'=>4,'departement_id'=>3,'justificatifs'=>[['path'=>$this->point['pdf_path'],'url'=>'/rh/export_point_financier_pdf.php?id=1&revision=1']], 'provisional_decaissement'=>['personnel_id'=>8,'ligne_debourse_id'=>5,'mode_paiement'=>'Espèces','date_decaissement_minimum'=>'2026-10-14','details_demande'=>RhFinancialPointPolicy::details('Point test',$lines)]];
        $this->request=['id'=>13573,'requester_user_id'=>1,'meta_service_id'=>3,'request_type'=>'feb','title'=>'Point test','status'=>'approved','payload_json'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    }
    public function prepare(string $query,array $options=[]):PDOStatement{return new RepairTestStmt($this,$query);}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement{return new RepairTestStmt($this,$query);}
    public function beginTransaction():bool {$this->backup=[$this->request,$this->events,$this->point];$this->tx=true;return true;}
    public function inTransaction():bool{return $this->tx;}
    public function commit():bool{$this->tx=false;return true;}
    public function rollBack():bool{[$this->request,$this->events,$this->point]=$this->backup;$this->tx=false;return true;}
    public function lastInsertId(?string $name=null):string|false{return '13574';}
}
class RepairTestStmt extends PDOStatement {
    private array $params=[];
    public function __construct(private RepairTestDb $db,private string $query){}
    public function execute(?array $params=null):bool {
        $this->params=$params??[];
        if(str_starts_with($this->query,'INSERT')||str_starts_with($this->query,'UPDATE'))$this->db->writes[]=[$this->query,$params];
        if(str_starts_with($this->query,'INSERT INTO rh_financial_events'))$this->db->events[]=['event'=>$params[2],'json'=>$params[4]];
        if(str_starts_with($this->query,'UPDATE employee_legal_requests')) {if($this->db->failUpdate)throw new RuntimeException('Fixture write failure');$this->db->request['payload_json']=$params[0];}
        if(str_starts_with($this->query,'UPDATE rh_financial_point_versions SET rh_signed_at')){$this->db->point['snapshot_json']=$params[0];$this->db->point['snapshot_hash']=$params[1];}
        return true;
    }
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {
        if(str_contains($this->query,'SELECT signature,signature_data'))return ['signature'=>'','signature_data'=>$this->db->image];
        if(str_contains($this->query,'FROM user_devis_banamur'))return ['active'=>$this->db->active?1:0,'acces_admin'=>$this->db->admin?1:0,'acces_rh'=>$this->db->rh?1:0,'acces_feb'=>$this->db->finance?1:0,'acces_direction'=>1,'email'=>'dg@example.test'];
        if(str_contains($this->query,'FROM employee_legal_requests'))return $this->db->request;
        if(str_contains($this->query,'FROM rh_financial_settings'))return ['dg_user_id'=>2];
        if(str_contains($this->query,'FROM ligne_debourse_banamur'))return ['id'=>5,'debourse_id'=>6,'chantier_id'=>4];
        return $this->db->point;
    }
    public function fetchColumn(int $column=0):mixed {if(str_contains($this->query,'feb_validation_services'))return $this->db->services;if(str_contains($this->query,'SELECT p.id')||str_contains($this->query,'SELECT 1 FROM service')||str_contains($this->query,'SELECT 1 FROM debourse'))return 1;if(str_contains($this->query,'nom_personnel_soignant'))return 'DG Test';foreach(array_reverse($this->db->events) as $event)if($event['event']==='profile_signatures_backfilled')return $event['json'];return false;}
}
try {
    $db=new RepairTestDb($sandbox);$service=new RhFinancialPointService($db);$point=$db->point;$original=file_get_contents($sandbox.'/'.$point['pdf_path']);
    $plan=$service->repairFebDocuments(1,9);expect($plan['status']==='planned'&&$plan['lines']===2&&$plan['amount']===175000,'Preview selects only DG approved final amounts');expect(!$db->writes,'Preview never writes');
    $result=$service->repairFebDocuments(1,9,true);expect($result['status']==='repaired','Repair applies');
    foreach($db->writes as [$sql])expect(str_starts_with($sql,'INSERT INTO rh_financial_events')||str_starts_with($sql,'UPDATE employee_legal_requests SET payload_json'),'Repair only writes documents and audit, never financial tables');
    $payload=json_decode($db->request['payload_json'],true);expect(count($payload['besoins'])===2&&$payload['besoins'][0]['fournisseur']['prix_unitaire']===100000&&$payload['besoins'][1]['fournisseur']['prix_unitaire']===75000,'Each approved request becomes one need');
    expect($payload['montant']===175000&&$db->request['status']==='approved'&&$db->point===$point,'Amount, paid/approval state, signed JSON and hash unchanged');
    expect(file_get_contents($sandbox.'/'.$point['pdf_path'])===$original&&$payload['justificatifs'][0]['original_path']===$point['pdf_path'],'Original preserved');
    expect($payload['global_justificatif']===$payload['justificatifs'][0]&&$payload['besoins'][0]['justificatif_devis']===$payload['justificatifs'][0],'Canonical attachment on FEB and every need');
    $request=$db->request;$request['payload']=$payload;$service->assertSignedFeb($request);
    $saved=$service->supplementalSignatures(1,1);expect(isset($saved['dg_signature']['backfilled_at'],$saved['rh_signature']['backfilled_at']),'Legacy additions explicitly dated');
    $before=count($db->writes);$db->image='';expect($service->repairFebDocuments(1,9,true)['status']==='unchanged'&&count($db->writes)===$before,'Repeat is idempotent and does not reload a changed profile');expect($service->supplementalSignatures(1,1)===$saved,'Captured signatures frozen');
    $request['payload']['besoins'][0]['fournisseur']['prix_unitaire']=1;rejects(fn()=>$service->assertSignedFeb($request),'Tampered need');
    $db->admin=false;rejects(fn()=>$service->repairFebDocuments(1,9,true),'Non admin');$db->admin=true;
    $db=new RepairTestDb($sandbox);$db->point['snapshot_hash']='bad';rejects(fn()=>(new RhFinancialPointService($db))->repairFebDocuments(1,9,true),'Altered snapshot');expect(!$db->writes,'Altered snapshot causes no writes');
    $db=new RepairTestDb($sandbox);$db->failUpdate=true;$before=glob($sandbox.'/uploads/rh_financial_points/*');$request=$db->request;rejects(fn()=>(new RhFinancialPointService($db))->repairFebDocuments(1,9,true),'Write failure');expect(!$db->tx&&$db->request===$request&&$db->events===[]&&glob($sandbox.'/uploads/rh_financial_points/*')===$before,'Rollback and orphan PDF cleanup');
    $db=new RepairTestDb($sandbox);$db->image='';$result=(new RhFinancialPointService($db))->repairFebDocuments(1,9,true);expect(count($result['missing_profiles'])===2,'Missing profile is reported, never invented');
    $db=new RepairTestDb($sandbox);$sig=RhFinancialSignature::profile($db,2);$frozen=RhFinancialSignature::imageBytes($sig);$db->image='';expect(RhFinancialSignature::imageBytes($sig)===$frozen,'Profile snapshot independent of current user');$sig['image_hash']='wrong';rejects(fn()=>RhFinancialSignature::imageBytes($sig),'Image hash mismatch');rejects(fn()=>RhFinancialSignature::raster('<svg/>'),'Uploaded SVG rejected');
    $db=new RepairTestDb($sandbox);$db->point['status']='draft';$service=new RhFinancialPointService($db);rejects(fn()=>$service->stop(1,1,1),'Passkey still required');expect(!$db->writes,'No signed write without proof');PasskeySignatureGuard::$allow=true;$service->stop(1,1,1);$snapshot=json_decode($db->point['snapshot_json'],true);expect(!empty($snapshot['rh_signature']['image_data'])&&$snapshot['rh_signature']['user_id']===1,'New RH Passkey signature binds captured raster to signer');
    $db=new RepairTestDb($sandbox);$p=$db->point;$p['snapshot']=json_decode($p['snapshot_json'],true);$method=new ReflectionMethod(RhFinancialPointService::class,'createFeb');$method->invoke(new RhFinancialPointService($db),$p,$p['snapshot'],$p['pdf_path']);$insert=array_values(array_filter($db->writes,fn($w)=>str_starts_with($w[0],'INSERT INTO employee_legal_requests')))[0];$payload=json_decode($insert[1][4],true);expect(count($payload['besoins'])===2&&str_starts_with($payload['justificatifs'][0]['url'],'/request/download_rh_financial_point.php?'),'New FEB canonical needs and protected attachment');
    $db=new RepairTestDb($sandbox);$db->point['status']='rh_signed';$db->point['pdf_path']=null;$db->point['token_hash']=hash('sha256',str_repeat('a',64));$db->point['token_expires_at']=date('Y-m-d H:i:s',time()+600);$db->point['token_used_at']=null;$snapshot=json_decode($db->point['snapshot_json'],true);$snapshot['lines']=array_slice($snapshot['lines'],0,2);unset($snapshot['dg_signature']);$db->point['snapshot_json']=json_encode($snapshot);PasskeySignatureGuard::$allow=true;$feb=(new RhFinancialPointService($db))->sign(1,1,2,[],str_repeat('a',64));expect($feb===13574&&RhFinancialPointPdfBuilder::$last[1]['dg_signature']['user_id']===2&&!empty(RhFinancialPointPdfBuilder::$last[1]['dg_signature']['image_data']),'New DG Passkey captures profile and creates FEB');
    $db=new RepairTestDb($sandbox);$db->admin=false;$db->rh=false;$download=new RhFinancialFebDocumentService($db);expect($download->file(1,1,9)===realpath($sandbox.'/'.$db->point['pdf_path']),'Finance scoped to correct service can download without RH rights');
    $db->services='99';rejects(fn()=>$download->file(1,1,9),'Finance wrong service');$db->services='';rejects(fn()=>$download->file(1,1,9),'Finance unconfigured services');$db->services='3';$db->finance=false;rejects(fn()=>$download->file(1,1,9),'Unrelated employee');expect($download->file(1,1,1)!==''&&$download->file(1,1,2)!=='','RH owner and historical DG can access');$db->active=false;rejects(fn()=>$download->file(1,1,2),'Inactive historical signer');$db->active=true;$db->admin=true;
    $payload=json_decode($db->request['payload_json'],true);$payload['rh_financial_revision']=2;$db->request['payload_json']=json_encode($payload);rejects(fn()=>$download->file(1,1,9),'FEB from other version');$payload['rh_financial_revision']=1;$payload['justificatifs'][0]['original_path']=$db->point['pdf_path'];$payload['justificatifs'][0]['path']='outside.pdf';file_put_contents($sandbox.'/outside.pdf','PRIVATE');$db->request['payload_json']=json_encode($payload);rejects(fn()=>$download->file(1,1,9),'File outside protected directory');expect($download->file(1,1,9,true)===realpath($sandbox.'/'.$db->point['pdf_path']),'Explicit original remains accessible separately');
    echo "RH FEB document repair, dry-run, idempotency, signatures, immutable originals, integrity checks and rollback: OK\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($files as $file){$file->isDir()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($sandbox);
}
