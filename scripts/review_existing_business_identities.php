<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
if (($argv[1]??'')==='--help') {echo "Usage : php scripts/review_existing_business_identities.php\nAudit SQL en lecture seule ; aucun appel API, aucune normalisation ni fusion automatique.\n";exit;}
umask(0077);set_time_limit(0);
try {
    $live='/home/fidestci/projet.fidest.ci';$state='/home/fidestci/finea-deployments';
    require_once $live.'/model/EnvConfig.php';EnvConfig::loadFromDirectory($live.'/model');
    require_once __DIR__.'/../services/ExistingBusinessIdentityReview.php';
    require_once __DIR__.'/../services/PersonIdentityGuard.php';
    $config=[];
    foreach (['HOST','NAME','USER','PASS'] as $key) {$config[$key]=EnvConfig::get('PROJECT_DB_'.$key);if($config[$key]===null)throw new RuntimeException('Configuration SQL incomplète.');}
    $pdo=new PDO('mysql:host='.$config['HOST'].';dbname='.$config['NAME'].';charset=utf8mb4',$config['USER'],$config['PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($config);
    $pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $report=['created_at'=>gmdate('c'),'mode'=>'read_only','entities'=>[],'links'=>[]];
    foreach (['suppliers'=>['fournisseur_banamur','id_fournisseur'],'clients'=>['client_banamur','id_client']] as $kind=>[$table,$idColumn]) {
        $supplier=$kind==='suppliers';$result=['total'=>0,'to_review'=>0,'reason_counts'=>[],'records'=>[],'possible_duplicates'=>[]];$keys=[];
        $statement=$pdo->query('SELECT * FROM '.$table.' ORDER BY '.$idColumn);
        while ($row=$statement->fetch()) {
            if($supplier && ($row['supplier_type']??'')==='physique' && trim((string)($row['identity_document_number']??''))!=='')$row['personnel_identity_conflict']=PersonIdentityGuard::employeeExists($pdo,(string)$row['identity_document_number']);
            $result['total']++;$id=(int)$row[$idColumn];$reasons=ExistingBusinessIdentityReview::review($row,$supplier);
            if ($reasons) {$result['to_review']++;$result['records'][]=['id'=>$id,'archived'=>!empty($row['archived_at']),'reasons'=>$reasons];foreach($reasons as $reason)$result['reason_counts'][$reason]=($result['reason_counts'][$reason]??0)+1;}
            foreach (ExistingBusinessIdentityReview::duplicateKeys($row) as $key) $keys[$key][]=$id;
        }
        foreach ($keys as $key=>$ids) if(count($ids)>1)$result['possible_duplicates'][]=['field'=>strtok($key,':'),'ids'=>$ids];
        $report['entities'][$kind]=$result;
    }
    // Relationships are counted, never reassigned. Missing legacy columns are explicit.
    $columns=[];
    foreach ($pdo->query('SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()') as $column) $columns[$column['TABLE_NAME']][$column['COLUMN_NAME']]=true;
    $links=[['fiche_decaissement','fournisseur_id','fournisseur_banamur','id_fournisseur'],['chantier_banamur','client_id','client_banamur','id_client'],['devis_banamur','client_id','client_banamur','id_client'],['contrat_sous_traitance','fournisseur_id','fournisseur_banamur','id_fournisseur'],['logistics_suppliers','master_supplier_id','fournisseur_banamur','id_fournisseur'],['fournisseurs_transport','master_supplier_id','fournisseur_banamur','id_fournisseur']];
    foreach ($links as [$table,$column,$target,$primary]) {
        $label=$table.'.'.$column;
        if(!isset($columns[$table][$column],$columns[$target][$primary])){$report['links'][$label]=['status'=>'schema_absent'];continue;}
        $report['links'][$label]=['status'=>'checked','missing_reference'=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table.' s LEFT JOIN '.$target.' t ON t.'.$primary.'=s.'.$column.' WHERE s.'.$column.'>0 AND t.'.$primary.' IS NULL')->fetchColumn(),'without_link'=>(int)$pdo->query('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.' IS NULL OR '.$column.'=0')->fetchColumn()];
    }
    $pdo->rollBack();
    if(!is_dir($state))throw new RuntimeException('Dossier de suivi absent.');
    $path=$state.'/identity-review-'.gmdate('Ymd_His').'-'.bin2hex(random_bytes(3)).'.json';
    if(file_put_contents($path,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Rapport inaccessible.');
    foreach ($report['entities'] as $kind=>$result) echo $kind.' : '.$result['total'].' fiches, '.$result['to_review'].' à examiner, '.count($result['possible_duplicates']).' groupes de doublons possibles.'.PHP_EOL;
    echo json_encode($report['links'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL.'Rapport : '.$path.PHP_EOL.'Aucune donnée modifiée ; aucune identité ni validation accordée.'.PHP_EOL;
} catch (Throwable $e) {
    if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,$e instanceof PDOException?'Audit SQL interrompu (SQLSTATE '.$e->getCode().').'.PHP_EOL:$e->getMessage().PHP_EOL);exit(1);
}
