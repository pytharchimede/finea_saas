<?php
require_once __DIR__.'/../scripts/deploy_finea.php';
umask(0077);
function check(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
function refuses(callable $action,string $message):void {
    try {$action();}catch(RuntimeException $e){check(str_contains($e->getMessage(),$message),$e->getMessage());return;}
    throw new RuntimeException('Refus attendu : '.$message);
}
$root=sys_get_temp_dir().'/finea-deploy-test-'.bin2hex(random_bytes(6));
mkdir($root,0700);mkdir($root.'/repo');mkdir($root.'/site');
$git=fn(array $args)=>FineaDeployment::command(array_merge(['git','-C',$root.'/repo'],$args));
try {
    $git(['init','-q']);$git(['config','user.email','test@example.invalid']);$git(['config','user.name','Deployment test']);
    file_put_contents($root.'/repo/index.php','<?php echo "new";');
    mkdir($root.'/repo/new');file_put_contents($root.'/repo/new/page.php','<?php echo "page";');
    mkdir($root.'/repo/model');file_put_contents($root.'/repo/model/AppConfig.php','<?php // secret');
    $git(['add','.']);$git(['commit','-qm','Fixture']);$git(['update-ref','refs/remotes/origin/develop','HEAD']);$git(['update-ref','refs/remotes/origin/main','HEAD']);
    file_put_contents($root.'/site/index.php','<?php echo "old";');
    mkdir($root.'/site/model');file_put_contents($root.'/site/model/AppConfig.php','production config');
    mkdir($root.'/site/uploads');file_put_contents($root.'/site/uploads/keep.php','attachment');
    file_put_contents($root.'/site/.htaccess','production rules');
    $deploy=new FineaDeployment($root.'/repo',$root.'/site',$root.'/state');
    $plan=$deploy->plan('develop',false);
    check(file_get_contents($root.'/site/index.php')==='<?php echo "old";','Simulation modifie le site');
    $deploy->apply($plan);
    check(file_get_contents($root.'/site/index.php')==='<?php echo "new";','Code non copié');
    check(file_get_contents(dirname($plan).'/backup/index.php')==='<?php echo "old";','Sauvegarde absente');
    check(file_get_contents($root.'/site/model/AppConfig.php')==='production config','Configuration remplacée');
    check(file_get_contents($root.'/site/uploads/keep.php')==='attachment','Pièce jointe modifiée');
    check(file_get_contents($root.'/site/.htaccess')==='production rules','Htaccess modifié');
    check((fileperms($root.'/site/new')&0777)===0755,'Dossier inaccessible au serveur web');
    check((fileperms($root.'/site/new/page.php')&0777)===0644,'Permissions du fichier incorrectes');
    refuses(fn()=>$deploy->apply($plan),'déjà exécuté');
    file_put_contents($root.'/site/index.php','before plan');
    $stale=$deploy->plan('develop',false);file_put_contents($root.'/site/index.php','local change');
    refuses(fn()=>$deploy->apply($stale),'changé depuis');
    check(file_get_contents($root.'/site/index.php')==='local change','Changement local écrasé');
    mkdir($root.'/site/cron');file_put_contents($root.'/site/cron/monitor_whatsapp_daily_point_template.php','<?php');
    $blocked=$deploy->plan('main',false);refuses(fn()=>$deploy->apply($blocked),'suivi WhatsApp');
    unlink($root.'/site/cron/monitor_whatsapp_daily_point_template.php');
    unlink($root.'/site/index.php');symlink($root.'/site/.htaccess',$root.'/site/index.php');
    refuses(fn()=>$deploy->plan('develop',false),'Lien symbolique');
    unlink($root.'/site/index.php');file_put_contents($root.'/site/index.php','original');
    file_put_contents($root.'/repo/index.php','<?php this is invalid syntax !!!');
    $git(['add','.']);$git(['commit','-qm','Invalid PHP']);$git(['update-ref','refs/remotes/origin/develop','HEAD']);
    $invalid=$deploy->plan('develop',false);refuses(fn()=>$deploy->apply($invalid),'Commande échouée');
    check(file_get_contents($root.'/site/index.php')==='original','Copie avant validation de syntaxe');
    echo "OK : simulation, copie, sauvegarde, exclusions, permissions, plan périmé, WhatsApp, liens et syntaxe.\n";
} finally {
    FineaDeployment::command(['rm','-rf','--',$root]);
}
