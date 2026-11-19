<?php
require_once __DIR__ . '/../services/ContenuLocalAoAdapter.php';
$calls=[];
$config=['username'=>'test@example.test','password'=>'test-only'];
$adapter=new ContenuLocalAoAdapter($config, static function(string $url, ?string $form) use (&$calls): array {
    if ($form !== null) { parse_str($form,$fields); if ($fields['client_id']!=='hydro-client') throw new RuntimeException('Client incorrect'); return ['access_token'=>'test-token']; }
    parse_str((string) parse_url($url,PHP_URL_QUERY),$query);
    $page=(int)$query['page'];$calls[]=$page;
    if($query['statuses']!=='PUBLISHED,LAUNCHED' || (int)$query['limit']!==100) throw new RuntimeException('Filtres source incorrects');
    return ['totalPages'=>3,'content'=>[['id'=>$page+1],['id'=>1]]];
});
$rows=$adapter->fetchPublicOpportunities();
if($calls!==[0,1,2] || array_column($rows,'id')!==[1,2,3]) throw new RuntimeException('Pagination complète et déduplication');
$adapter=new ContenuLocalAoAdapter($config, static fn($url,$form) => $form!==null ? ['access_token'=>'test-token'] : ['content'=>[], 'totalPages'=>0]);
if($adapter->fetchPublicOpportunities()!==[]) throw new RuntimeException('Source vide');
$adapter=new ContenuLocalAoAdapter($config, static fn($url,$form) => $form!==null ? ['access_token'=>'test-token'] : ['unexpected'=>[]]);
$blocked=false;try{$adapter->fetchPublicOpportunities();}catch(RuntimeException $e){$blocked=true;}
if(!$blocked) throw new RuntimeException('Réponse incompatible doit échouer');
$adapter=new ContenuLocalAoAdapter($config, static function($url,$form){
    if($form!==null)return ['access_token'=>'test-token'];
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    if((int)$query['page']===1)throw new RuntimeException('HTTP 401 simulé');
    return ['content'=>[['id'=>1]],'totalPages'=>2];
});
$blocked=false;try{$adapter->fetchPublicOpportunities();}catch(RuntimeException $e){$blocked=true;}
if(!$blocked)throw new RuntimeException('Échec intermédiaire ne doit pas renvoyer un lot partiel');
$adapter=new ContenuLocalAoAdapter($config, static fn($url,$form) => $form!==null ? [] : ['content'=>[], 'totalPages'=>0]);
$blocked=false;try{$adapter->fetchPublicOpportunities();}catch(RuntimeException $e){$blocked=true;}
if(!$blocked)throw new RuntimeException('Authentification refusée');
echo "5 vérifications de pagination Contenu local réussies.\n";
