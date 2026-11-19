<?php
require_once __DIR__.'/../services/WhatsAppTemplateTestService.php';
function checkTest(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;}
final class TestContentApi extends WhatsAppTemplateTestService{
 public array $paths=[];
 public function __construct(){parent::__construct('', '');}
 protected function request(string $method,string $path,?array $payload=null):array{
  $this->paths[]=$path;
  if(str_contains($path,'ApprovalRequests'))return ['whatsapp'=>['status'=>'approved','category'=>'UTILITY']];
  return ['friendly_name'=>'finea_rh_salary_test','variables'=>['1'=>'DG'], 'types'=>['twilio/call-to-action'=>['body'=>'Bonjour {{1}}, point {{2}}','actions'=>[['url'=>'https://projet.fidest.ci/signature/{{7}}','title'=>'Signer']]]]];
 }
}
$api=new TestContentApi();$model=$api->inspect('HX'.str_repeat('a',32));
checkTest($model['keys']===['1','2','7']&&$model['name']==='finea_rh_salary_test'&&$model['status']==='approved','Content metadata and approval fetched, URL variables included');
$values=WhatsAppTemplateTestService::variables($model,['1'=>' Ulrich ','2'=>'125','7'=>'REAL_TOKEN','9'=>'unexpected']);
checkTest($values===[1=>'Ulrich',2=>'125',7=>str_repeat('a',64)],'Only declared variables sent and signature token always fictitious');
checkTest(WhatsAppTemplateTestService::phone('00225 07 00 00 00 00')==='+2250700000000','International phone normalized');
foreach(['0700000000','+225bad'] as $phone){try{WhatsAppTemplateTestService::phone($phone);throw new LogicException('accepted');}catch(DomainException $e){echo "PASS Invalid phone rejected\n";}}
foreach(['',"bad\nvalue",str_repeat('a',501),['bad']] as $value){try{WhatsAppTemplateTestService::variables($model,['1'=>$value,'2'=>'125']);throw new LogicException('accepted');}catch(DomainException $e){echo "PASS Invalid dynamic value rejected\n";}}
checkTest(WhatsAppTemplateTestService::variables(['keys'=>[]],[])===[],'Static templates send no variables');
echo "All WhatsApp template test checks passed.\n";
