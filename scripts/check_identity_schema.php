<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(($argv[1]??'')==='--help'){echo "Usage : php scripts/check_identity_schema.php --backup=/home/fidestci/finea-deployments/identity-.../before.sql\nPrépare le schéma fournisseur après vérification d’une sauvegarde existante ; ne copie aucun fichier du site.\n";exit;}
umask(0077);set_time_limit(0);
$live='/home/fidestci/projet.fidest.ci';$state='/home/fidestci/finea-deployments';
try{
    $options=getopt('',['backup:']);$backup=realpath((string)($options['backup']??''));
    if(!$backup||!str_starts_with($backup,$state.'/identity-')||basename($backup)!=='before.sql'||!is_file($backup.'.sha256'))throw new RuntimeException('Indiquez --backup=/home/fidestci/finea-deployments/identity-.../before.sql (sauvegarde complète terminée).');
    $expected=strtok(trim((string)file_get_contents($backup.'.sha256'))," \t\r\n");
    echo "Vérification de la sauvegarde existante…\n";
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)$expected)||!hash_equals((string)$expected,(string)hash_file('sha256',$backup)))throw new RuntimeException('La sauvegarde ne correspond pas à son empreinte.');
    require_once $live.'/model/EnvConfig.php';EnvConfig::loadFromDirectory($live.'/model');$config=[];
    foreach(['HOST','NAME','USER','PASS'] as $key){$config[$key]=EnvConfig::get('PROJECT_DB_'.$key);if($config[$key]===null)throw new RuntimeException('Configuration de connexion incomplète.');}
    $handle=fopen($backup,'rb');$header=$handle?fread($handle,65536):false;if($handle)fclose($handle);
    if(!is_string($header)||!preg_match('/^USE `'.preg_quote(str_replace('`','``',$config['NAME']),'/').'`;\s*$/m',$header))throw new RuntimeException('La sauvegarde ne permet pas de confirmer la base sélectionnée.');
    $pdo=new PDO('mysql:host='.$config['HOST'].';dbname='.$config['NAME'].';charset=utf8mb4',$config['USER'],$config['PASS'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);unset($config);
    $lock=fopen($state.'/identity-release.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Une livraison est encore en cours. Attendez sa fin.');
    echo "Préparation du schéma fournisseur ; aucun fichier du site n’est remplacé.\n";
    require_once __DIR__.'/../repositories/FournisseurRepository.php';$repo=new FournisseurRepository($pdo);
    $diagnostic=$repo->isReady()?['ok'=>true,'message'=>'Schéma fournisseur prêt.']:['ok'=>false]+$repo->getInitDiagnostics();
    if(!$repo->isReady()){
        $diagnostic['schema']=[];
        foreach(['fournisseur_banamur','fournisseur_category_banamur','fournisseur_category_link_banamur','personnel','fiche_decaissement'] as $table){
            try{
                $q=$pdo->prepare('SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);$info=$q->fetch(PDO::FETCH_ASSOC);
                if(!$info){$diagnostic['schema'][$table]=['exists'=>false];continue;}
                $info['columns']=[];
                foreach($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $column)if(in_array($column['Field'],['id_fournisseur','id_category','fournisseur_id','category_id','personnel_id','num_cni','piece_identite_path'],true))$info['columns'][$column['Field']]=$column['Type'];
                $diagnostic['schema'][$table]=$info;
            }catch(Throwable $e){$diagnostic['schema'][$table]=['metadata'=>'indisponibles'];}
        }
    }
    $report=$state.'/supplier-schema-diagnostic-'.gmdate('Ymd_His').'.json';
    $json=json_encode($diagnostic,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(file_put_contents($report,$json)===false)throw new RuntimeException('Journal de diagnostic inaccessible.');
    echo $json.PHP_EOL.'Rapport : '.$report.PHP_EOL;
    exit($repo->isReady()?0:1);
}catch(Throwable $e){fwrite(STDERR,$e instanceof PDOException?'Connexion ou lecture SQL impossible (SQLSTATE '.$e->getCode().').'.PHP_EOL:$e->getMessage().PHP_EOL);exit(1);}
