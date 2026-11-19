<?php
declare(strict_types=1);

/** Side-channel progress only: never changes a workflow result or transaction. */
final class FinancialWorkflowProgress
{
    private static ?self $active=null;
    private array $record;
    private string $file;
    public static function directory():string{return sys_get_temp_dir().'/finea-workflow-progress-v1';}
    public static function supported(string $path):bool
    {
        return (bool)preg_match('~/(?:services/)?validation_demandes_finances\.php$|/decaissement/(?:request|api)/[^/]+\.php$|/decaissement/(?:bon_commande|bon_reception|point_decaissement_jour|paiement_points_decaissement)\.php$|/contrats/[^/]+\.php$~',$path);
    }
    public function __construct(string $token,string $sessionName,string $sessionId,?string $directory=null)
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token)||$sessionId==='')throw new InvalidArgumentException('Progress token invalid');
        $directory??=self::directory();
        if(is_link($directory))throw new RuntimeException('Progress directory invalid');
        if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Progress unavailable');
        @chmod($directory,0700);$this->file=$directory.'/'.$token.'.json';
        if(random_int(1,20)===1){$scanned=0;foreach(new DirectoryIterator($directory) as $entry){if(++$scanned>500)break;if($entry->isFile()&&!$entry->isLink()&&preg_match('/^[a-f0-9]{48}\.json$/D',$entry->getFilename())&&time()-$entry->getMTime()>7200)@unlink($entry->getPathname());}}
        if(file_exists($this->file))throw new RuntimeException('Progress token already used');
        $this->record=['session_name'=>$sessionName,'owner'=>hash('sha256',$sessionId),'created'=>time(),'state'=>'running','sequence'=>0,'events'=>[]];
        $this->append('Demande reçue par le serveur.');
    }
    public function append(string $message,?int $done=null,?int $total=null):void
    {
        $this->record['events'][]=['id'=>++$this->record['sequence'],'time'=>gmdate('H:i:s'),'message'=>$message,'done'=>$done,'total'=>$total];
        $this->record['events']=array_slice($this->record['events'],-120);
        $temp=$this->file.'.tmp';
        if(@file_put_contents($temp,json_encode($this->record,JSON_THROW_ON_ERROR))===false||!@rename($temp,$this->file))throw new RuntimeException('Progress write failed');
        @chmod($this->file,0600);
    }
    public function finish(bool $failed=false):void
    {
        $this->record['state']=$failed?'failed':'finished';
        $this->append($failed?'Traitement interrompu. Consultez le message de résultat.':'Traitement serveur terminé. Résultat dans la réponse.');
    }
    public static function start():void
    {
        if(self::$active||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!self::supported((string)parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)))return;
        $token=(string)($_SERVER['HTTP_X_FINEA_PROGRESS']??$_POST['_finea_progress']??'');
        $name=session_name();$owner=session_id()?:($_COOKIE[$name]??'');
        if(!preg_match('/^[a-f0-9]{48}$/D',$token)||$owner==='')return;
        try{
            self::$active=new self($token,$name,(string)$owner);
            register_shutdown_function(static function():void{
                $error=error_get_last();$fatal=$error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true);
                try{self::$active?->finish($fatal||http_response_code()>=400);}catch(Throwable $ignored){}
            });
        }catch(Throwable $ignored){}
    }
    public static function step(string $message,?int $done=null,?int $total=null):void
    {
        try{self::$active?->append($message,$done,$total);}catch(Throwable $ignored){}
    }
    public static function begin(PDO $pdo):bool{self::step('Ouverture de la transaction.');return $pdo->beginTransaction();}
    public static function commit(PDO $pdo):bool{$ok=$pdo->commit();if($ok)self::step('Transaction confirmée.');return $ok;}
    public static function rollback(PDO $pdo):bool{$ok=$pdo->rollBack();if($ok)self::step('Transaction annulée.');return $ok;}
    public static function read(string $token,array $cookies,?string $directory=null):?array
    {
        if(!preg_match('/^[a-f0-9]{48}$/D',$token))return null;
        $file=($directory??self::directory()).'/'.$token.'.json';
        if(!is_file($file)||is_link($file)||filesize($file)>262144)return null;
        $r=json_decode((string)@file_get_contents($file),true);
        if(!is_array($r)||!is_string($r['session_name']??null))return null;
        $cookie=$cookies[$r['session_name']]??null;
        if(time()-(int)($r['created']??0)>7200||!is_string($cookie)||$cookie===''||!hash_equals((string)($r['owner']??''),hash('sha256',$cookie)))return null;
        unset($r['owner'],$r['session_name']);return $r;
    }
}
