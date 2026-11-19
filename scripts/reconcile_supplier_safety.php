<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')==='--help'){echo "Usage : php scripts/reconcile_supplier_safety.php [--apply]\nSimulation par défaut. --apply remet les fiches non conformes en attente, et désactive les fournisseurs membres du personnel. Aucun paiement ni identité n’est créé ou modifié.\n";exit;}
umask(0077);set_time_limit(0);
$pdo=null;$reportFile=null;
try{
    $apply=in_array('--apply',$argv,true);$live='/home/fidestci/projet.fidest.ci';$state='/home/fidestci/finea-deployments';
    require_once $live.'/model/EnvConfig.php';EnvConfig::loadFromDirectory($live.'/model');
    require_once __DIR__.'/../services/SupplierPaymentGuard.php';
    $config=[];foreach(['HOST','NAME','USER','PASS'] as $key){$config[$key]=EnvConfig::get('PROJECT_DB_'.$key);if($config[$key]===null)throw new RuntimeException('Configuration SQL incomplète.');}
    $pdo=new PDO('mysql:host='.$config['HOST'].';dbname='.$config['NAME'].';charset=utf8mb4',$config['USER'],$config['PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($config);
    $lock=fopen($state.'/supplier-safety.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Un rapprochement fournisseur est déjà en cours.');
    if(!$apply)$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
    $rows=$pdo->query('SELECT * FROM fournisseur_banamur ORDER BY id_fournisseur'.($apply?' FOR UPDATE':''))->fetchAll();
    $changes=[];$issues=[];
    foreach($rows as $row){
        $missing=SupplierPaymentGuard::missing($pdo,$row,$live,$apply);
        if(!$missing)continue;
        $id=(int)$row['id_fournisseur'];$issues[]=['id'=>$id,'reasons'=>$missing];
        $employee=in_array(PersonIdentityGuard::EMPLOYEE_MESSAGE,$missing,true);
        $status=$employee?'rejected':(($row['validation_status']??'')==='approved'?'pending':$row['validation_status']);
        $active=$employee?0:(int)$row['actif'];
        if($status===$row['validation_status']&&$active===(int)$row['actif'])continue;
        $changes[]=['id'=>$id,'before'=>['validation_status'=>$row['validation_status'],'actif'=>(int)$row['actif'],'validation_note'=>$row['validation_note']??null],
            'after'=>['validation_status'=>$status,'actif'=>$active,'validation_note'=>'Contrôle fournisseur : '.implode(' ; ',$missing)]];
    }
    $report=['mode'=>$apply?'apply':'simulation','status'=>'prepared','created_at'=>gmdate('c'),'total'=>count($rows),'issues'=>$issues,'changes'=>$changes];
    $reportFile=$state.'/supplier-safety-'.gmdate('Ymd_His').'-'.bin2hex(random_bytes(3)).'.json';
    $save=static function()use(&$report,$reportFile):void{if(file_put_contents($reportFile,json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))===false)throw new RuntimeException('Sauvegarde du rapprochement impossible.');};
    $save(); // The previous states exist outside the web root before any UPDATE.
    if($apply){
        $q=$pdo->prepare('UPDATE fournisseur_banamur SET validation_status=?,actif=?,validation_note=? WHERE id_fournisseur=?');
        foreach($changes as $change){$v=$change['after'];$q->execute([$v['validation_status'],$v['actif'],$v['validation_note'],$change['id']]);}
        $pdo->commit();$report['status']='committed';
    }else{$pdo->rollBack();$report['status']='simulated';}
    $save();
    echo ($apply?'RAPPROCHEMENT APPLIQUÉ':'SIMULATION — aucune écriture SQL').PHP_EOL;
    echo count($rows).' fournisseurs ; '.count($issues).' dossiers à régulariser ; '.count($changes).' changements de sécurité.'.PHP_EOL.'Rapport et états antérieurs : '.$reportFile.PHP_EOL;
    echo "Aucune fiche fusionnée ou approuvée. Aucun montant, paiement ou rattachement historique modifié.\n";
}catch(Throwable $e){if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e instanceof PDOException?'Rapprochement SQL interrompu (SQLSTATE '.$e->getCode().').'.PHP_EOL:$e->getMessage().PHP_EOL);if($reportFile)fwrite(STDERR,'Vérifier le rapport et l’état de la transaction : '.$reportFile.PHP_EOL);exit(1);}
