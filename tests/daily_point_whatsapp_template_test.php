<?php
require_once __DIR__.'/../services/DailyPointWhatsAppTemplateService.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $operation, string $expected): void {
    try { $operation(); } catch (RuntimeException $e) {
        check(str_contains($e->getMessage(), $expected), 'Diagnostic incorrect : '.$e->getMessage());
        return;
    }
    throw new RuntimeException('Le contrôle devait refuser cette opération.');
}
class FakeDailyPointContentApi extends DailyPointWhatsAppTemplateService {
    public array $responses = [];
    public array $calls = [];
    public function __construct() { parent::__construct('', ''); }
    protected function request(string $method, string $path, ?array $payload = null): array {
        $this->calls[] = [$method, $path, $payload];
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) throw $response;
        if (!is_array($response)) throw new RuntimeException('Requête inattendue.');
        return $response;
    }
}
$definition = DailyPointWhatsAppTemplateService::definition('finea_point_signature_test');
check($definition['language']==='fr', 'Langue incorrecte.');
check(array_keys((array)$definition['variables'])===range(1,7), 'Sept variables séquentielles attendues.');
check(str_ends_with($definition['types']['twilio/call-to-action']['actions'][0]['url'], '/{{7}}'), 'Jeton attendu en fin de chemin.');
rejects(fn()=>DailyPointWhatsAppTemplateService::definition('send Daily Recap'), 'Nom');
$sid='HX'.str_repeat('b',32);
$api=new FakeDailyPointContentApi();
$api->responses=[['sid'=>$sid],['status'=>'received']];
check($api->createAndSubmit('finea_point_signature_test')===['sid'=>$sid,'status'=>'received'], 'Création incorrecte.');
check($api->calls[1][2]['category']==='UTILITY', 'Catégorie incorrecte.');
$api->responses=[$definition,['whatsapp'=>['status'=>'approved']]];
$api->assertApproved($sid);
foreach(['pending','rejected','paused',''] as $status){
    $api->responses=[$definition,['whatsapp'=>['status'=>$status]]];
    rejects(fn()=>$api->assertApproved($sid), 'pas encore approuvé');
}
$wrong=$definition;$wrong['types']['twilio/call-to-action']['actions'][0]['url']='https://example.com/{{7}}';
$api->responses=[$wrong];
rejects(fn()=>$api->assertApproved($sid), 'ne correspond pas');
$api->responses=[['sid'=>$sid],new RuntimeException('Erreur HTTP 400')];
rejects(fn()=>$api->createAndSubmit('finea_point_signature_test'), $sid);
rejects(fn()=>$api->assertApproved('HXinvalid'), 'Content SID invalide');
echo "OK : définition, création Utility, approbation, mauvais contenu et échec partiel.\n";
