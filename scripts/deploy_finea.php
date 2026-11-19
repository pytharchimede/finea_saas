<?php
/** Deploiement manuel du code, sans charger l'application ni se connecter a la BDD. */
final class FineaDeployment
{
    public function __construct(private string $repo, private string $target, private string $state)
    {
        foreach ([$repo,$target] as $path) if (!is_dir($path) || is_link($path)) throw new RuntimeException('Dossier absent ou lien symbolique : '.$path);
        $this->repo=(string)realpath($repo);$this->target=(string)realpath($target);
        if(!is_dir($state)&&!mkdir($state,0700,true))throw new RuntimeException('Création du dossier de déploiement impossible.');
        if(is_link($state))throw new RuntimeException('Le dossier de suivi ne doit pas être un lien.');
        $this->state=(string)realpath($state);
        foreach([$this->repo,$this->state] as $private)if($private===$this->target||str_starts_with($private,$this->target.'/')||str_starts_with($this->target,$private.'/'))throw new RuntimeException('Le dépôt, le suivi et le site doivent être dans des dossiers séparés.');
        chmod($this->state,0700);
    }
    public static function command(array $args):string
    {
        $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['file','php://stderr','w']],$pipes);
        if(!is_resource($p))throw new RuntimeException('Impossible de lancer '.basename($args[0]));
        fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);
        if(proc_close($p)!==0)throw new RuntimeException('Commande échouée : '.basename($args[0]));
        return $out;
    }
    private function git(array $args):string{return self::command(array_merge(['git','-C',$this->repo],$args));}
    public static function eligible(string $path):bool
    {
        if($path===''||str_starts_with($path,'/')||preg_match('/[\x00-\x1F\x7F]/',$path))return false;
        foreach(explode('/',$path) as $part){
            if($part===''||$part==='.'||$part==='..'||str_starts_with($part,'.'))return false;
            if(preg_match('/^(uploads?|storage|attachments?|private|documents?|exports?|backups?|logs?|cache|tmp|temp|sessions?|old|vendor|node_modules|config|database|tests|docs|scripts|tools|img|img_demande|photo|photos|logo|signatures|qrCodeFile|ressources|fpdf|fpdf186|phpqrcode|phpmailer|twilio)$/i',$part))return false;
        }
        if ($path === 'model/AppConfig.php') return false;
        $name=basename($path);
        if(preg_match('/(^database(?:app)?\.php$|\.local\.|\.example\.|^config[_.]|[_.]config\.php$|^composer\.|^__?tmp|^debug[_\.])/i',$name))return false;
        // Les médias, archives, SQL et JSON locaux ne sont jamais remplacés automatiquement.
        return in_array(strtolower(pathinfo($path,PATHINFO_EXTENSION)),['php','js','mjs','css','html','htm','webmanifest'],true);
    }
    private function targetPath(string $path):string
    {
        if(!self::eligible($path))throw new RuntimeException('Chemin protégé : '.$path);
        $full=$this->target;
        $parts=explode('/',$path);
        foreach($parts as $i=>$part){$full.='/'.$part;if(is_link($full))throw new RuntimeException('Lien symbolique protégé : '.$path);if(file_exists($full)&&$i<count($parts)-1&&!is_dir($full))throw new RuntimeException('Parent non répertoire : '.$path);}
        if(file_exists($full)&&!is_file($full))throw new RuntimeException('Destination non régulière : '.$path);
        return $full;
    }
    private static function fingerprint(string $path):?string
    {
        if(!file_exists($path))return null;
        $hash=hash_file('sha256',$path);if($hash===false)throw new RuntimeException('Lecture impossible : '.$path);return $hash;
    }
    private static function save(string $path,array $data):void
    {
        $tmp=$path.'.tmp';$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if(file_put_contents($tmp,$json)===false||!rename($tmp,$path))throw new RuntimeException('Enregistrement impossible : '.$path);
        chmod($path,0600);
    }
    private function lock(callable $action):mixed
    {
        $handle=fopen($this->state.'/deploy.lock','c');if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('Un déploiement est déjà en cours.');
        try{return $action();}finally{flock($handle,LOCK_UN);fclose($handle);}
    }
    public function plan(string $branch,bool $fetch=true):string
    {
        if(!in_array($branch,['main','develop'],true))throw new RuntimeException('Branche autorisée : main ou develop.');
        return $this->lock(function()use($branch,$fetch){
            if($fetch)$this->git(['fetch','origin','refs/heads/'.$branch.':refs/remotes/origin/'.$branch]);
            $sha=trim($this->git(['rev-parse','refs/remotes/origin/'.$branch.'^{commit}']));
            $dir=$this->state.'/'.gmdate('Ymd_His').'-'.$branch.'-'.bin2hex(random_bytes(3));
            mkdir($dir,0700);mkdir($dir.'/source',0700);
            $this->git(['archive','--format=tar','--output='.$dir.'/source.tar',$sha]);
            self::command(['tar','-xf',$dir.'/source.tar','-C',$dir.'/source']);
            $changes=[];$protected=[];
            foreach(explode("\0",$this->git(['ls-tree','-r','-z',$sha])) as $record){
                if($record==='')continue;
                [$meta,$path]=explode("\t",$record,2);[$mode,$type]=explode(' ',$meta);
                if(!self::eligible($path)||$type!=='blob'||!in_array($mode,['100644','100755'],true)){$protected[]=$path;continue;}
                $dest=$this->targetPath($path);$source=$dir.'/source/'.$path;
                $old=self::fingerprint($dest);$new=self::fingerprint($source);
                if($new===null)throw new RuntimeException('Fichier absent de l’archive Git : '.$path);
                if($old!==$new)$changes[]=['path'=>$path,'before'=>$old,'after'=>$new,'mode'=>$old===null?0644:(fileperms($dest)&0777)];
            }
            $blocked=[];
            if(is_file($this->target.'/cron/monitor_whatsapp_daily_point_template.php')&&!is_file($dir.'/source/cron/monitor_whatsapp_daily_point_template.php'))$blocked[]='Cette branche ne contient pas le suivi WhatsApp déjà déployé. Intégrez la PR avant de déployer cette branche.';
            $plan=['branch'=>$branch,'commit'=>$sha,'target'=>$this->target,'created_at'=>gmdate('c'),'changes'=>$changes,'blocked'=>$blocked];
            self::save($dir.'/plan.json',$plan);file_put_contents($dir.'/protected.txt',implode("\n",$protected)."\n");
            $lines=['Branche : '.$branch,'Commit : '.$sha,'Site : '.$this->target,'Changements : '.count($changes),'Fichiers protégés/traités séparément : '.count($protected)];
            foreach($blocked as $reason)$lines[]='BLOCAGE : '.$reason;
            foreach($changes as $change)$lines[]=($change['before']===null?'AJOUT ':'MODIF ').$change['path'];
            file_put_contents($dir.'/preview.txt',implode("\n",$lines)."\n");
            echo implode("\n",array_slice($lines,0,6)).PHP_EOL.'Rapport : '.$dir.'/preview.txt'.PHP_EOL.'Plan : '.$dir.'/plan.json'.PHP_EOL;
            return $dir.'/plan.json';
        });
    }
    private function loadPlan(string $file):array
    {
        $real=realpath($file);
        if(!$real||is_link($file)||!str_starts_with($real,$this->state.'/')||basename($real)!=='plan.json')throw new RuntimeException('Plan hors du dossier de suivi.');
        $plan=json_decode((string)file_get_contents($real),true,512,JSON_THROW_ON_ERROR);
        if(($plan['target']??'')!==$this->target)throw new RuntimeException('Le plan vise un autre site.');
        return [$plan,dirname($real)];
    }
    private static function replaceFile(string $source,string $dest,int $mode):void
    {
        $missing=[];
        for($parent=dirname($dest);!is_dir($parent);$parent=dirname($parent))$missing[]=$parent;
        foreach(array_reverse($missing) as $directory){
            if(!mkdir($directory,0755)||!chmod($directory,0755))throw new RuntimeException('Création du dossier impossible.');
        }
        $tmp=tempnam(dirname($dest),'.finea-deploy-');
        if($tmp===false)throw new RuntimeException('Fichier temporaire impossible.');
        try{if(!copy($source,$tmp)||!chmod($tmp,$mode)||!rename($tmp,$dest))throw new RuntimeException('Copie impossible : '.$dest);}
        finally{if(is_file($tmp))unlink($tmp);}
    }
    public function apply(string $file):void
    {
        $this->lock(function()use($file){
            [$plan,$dir]=$this->loadPlan($file);
            if($plan['blocked'])throw new RuntimeException(implode(' ',$plan['blocked']));
            if(is_file($dir.'/execution.json'))throw new RuntimeException('Plan déjà exécuté ou interrompu. Consultez son journal et créez un nouveau plan.');
            // Tout vérifier et sauvegarder avant le premier remplacement.
            foreach($plan['changes'] as $change){
                $dest=$this->targetPath($change['path']);$source=$dir.'/source/'.$change['path'];
                if(is_link($source)||self::fingerprint($source)!==$change['after']||self::fingerprint($dest)!==$change['before'])throw new RuntimeException('Fichier changé depuis la simulation : '.$change['path']);
                if(str_ends_with($change['path'],'.php'))self::command([PHP_BINARY,'-l',$source]);
            }
            foreach($plan['changes'] as $change)if($change['before']!==null){
                $backup=$dir.'/backup/'.$change['path'];if(!is_dir(dirname($backup)))mkdir(dirname($backup),0700,true);
                if(!copy($this->targetPath($change['path']),$backup)||self::fingerprint($backup)!==$change['before'])throw new RuntimeException('Sauvegarde invalide : '.$change['path']);
                chmod($backup,0600);
            }
            $journal=['status'=>'in_progress','started_at'=>gmdate('c'),'written'=>[]];self::save($dir.'/execution.json',$journal);
            try{
                foreach($plan['changes'] as $change){
                    $dest=$this->targetPath($change['path']);
                    if(self::fingerprint($dest)!==$change['before'])throw new RuntimeException('Modification concurrente : '.$change['path']);
                    self::replaceFile($dir.'/source/'.$change['path'],$dest,$change['mode']);
                    if(self::fingerprint($dest)!==$change['after'])throw new RuntimeException('Vérification après copie échouée : '.$change['path']);
                    $journal['written'][]=$change['path'];self::save($dir.'/execution.json',$journal);
                }
                $journal['status']='completed';$journal['finished_at']=gmdate('c');self::save($dir.'/execution.json',$journal);
                self::save($this->state.'/last-deployment.json',['branch'=>$plan['branch'],'commit'=>$plan['commit'],'plan'=>$file,'finished_at'=>$journal['finished_at']]);
                echo 'Déploiement terminé : '.$plan['branch'].' '.$plan['commit'].PHP_EOL.'Sauvegarde et journal : '.$dir.PHP_EOL;
            }catch(Throwable $e){$journal['status']='interrupted';$journal['error']=$e->getMessage();self::save($dir.'/execution.json',$journal);throw $e;}
        });
    }
}

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    umask(0077);
    try{
        $deploy=new FineaDeployment('/home/fidestci/repositories/banamur_planification','/home/fidestci/projet.fidest.ci','/home/fidestci/finea-deployments');
        $action=$argv[1]??'help';$value=$argv[2]??($action==='plan'?'main':'');
        if($action==='plan')$deploy->plan($value);
        elseif($action==='apply')$deploy->apply($value);
        else echo "Usage : php deploy_finea.php plan main|develop\n        php deploy_finea.php apply /home/fidestci/finea-deployments/.../plan.json\n";
    }catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
}
