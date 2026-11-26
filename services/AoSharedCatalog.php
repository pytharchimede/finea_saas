<?php
declare(strict_types=1);
require_once __DIR__.'/../model/EnvConfig.php';
require_once __DIR__.'/SigomapAoAdapter.php';
require_once __DIR__.'/ContenuLocalAoAdapter.php';
require_once __DIR__.'/AoScoringService.php';
require_once __DIR__.'/../repositories/AoOpportunityRepository.php';

final class AoSharedCatalog {
    private const FIELDS=['source','numero_ao','reference','titre','autorite_contractante','type_marche','secteur','date_publication','date_limite','lieu_execution','resume','url_source','details_source','documents_source'];
    public static function path(): string {
        return (string) EnvConfig::get('FINEA_AO_CATALOG_FILE', dirname(__DIR__,2).'/.finea/ao-catalog.json');
    }
    public static function publish(): array {
        if(PHP_SAPI!=='cli') throw new RuntimeException('Collecte centrale réservée au CLI.');
        $path=self::path();$dir=dirname($path);
        if(!is_dir($dir)&&!mkdir($dir,0750,true)&&!is_dir($dir)) throw new RuntimeException('Répertoire catalogue indisponible.');
        $lock=fopen($path.'.lock','c');
        if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) throw new RuntimeException('Collecte déjà en cours.');
        try {
            $adapters=[new SigomapAoAdapter()];
            if(ContenuLocalAoAdapter::isConfigured()) $adapters[]=new ContenuLocalAoAdapter();
            $sources=[];$items=[];$errors=0;
            if(is_file($path)) {
                $previous=self::read();
                foreach($previous['items'] as $item) $items[$item['source']][]=$item;
            }
            foreach($adapters as $adapter){
                $name=$adapter->getSourceName();
                try {
                    $rows=[];
                    foreach($adapter->fetchPublicOpportunities() as $raw){
                        $item=array_intersect_key($adapter->normalizeOpportunity($raw),array_flip(self::FIELDS));
                        if(trim((string)($item['titre']??''))!=='')$rows[]=$item;
                    }
                    $items[$name]=$rows;$sources[$name]=['found'=>count($rows)];
                }catch(Throwable $e){$errors++;$sources[$name]=['error'=>'Collecte indisponible'];}
            }
            if($errors===count($adapters))throw new RuntimeException('Toutes les sources sont indisponibles. Catalogue précédent conservé.');
            $flat=array_merge(...array_values($items));
            $payload=json_encode(['version'=>1,'generated_at'=>gmdate(DATE_ATOM),'sources'=>$sources,'items'=>$flat],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $tmp=tempnam($dir,'ao-');
            if($tmp===false)throw new RuntimeException('Fichier temporaire indisponible.');
            try{if(file_put_contents($tmp,$payload)!==strlen($payload)||!chmod($tmp,0640)||!rename($tmp,$path))throw new RuntimeException('Publication impossible.');}finally{if(is_file($tmp))unlink($tmp);}
            return ['found'=>count($flat),'errors'=>$errors,'sources'=>$sources];
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function read(): array {
        $path=self::path();
        if(!is_file($path)||!is_readable($path)||filesize($path)>100*1024*1024)throw new RuntimeException('Catalogue central indisponible. Lancez la collecte centrale.');
        $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
        if(($data['version']??null)!==1||!is_array($data['items']??null))throw new RuntimeException('Catalogue incompatible.');
        return $data;
    }
    public static function import(PDO $pdo): array {
        $data=self::read();$repo=new AoOpportunityRepository($pdo);$scoring=new AoScoringService();
        $stats=['found'=>count($data['items']),'new'=>0,'updated'=>0,'duplicates'=>0,'errors'=>0,'sources'=>$data['sources']??[]];
        foreach($data['items'] as $item){
            try{$item=array_intersect_key($item,array_flip(self::FIELDS));$item['score_pertinence']=$scoring->score($item);$result=$repo->upsert($item);$stats[$result['is_new']?'new':($result['is_updated']?'updated':'duplicates')]++;}catch(Throwable $e){$stats['errors']++;}
        }
        return $stats;
    }
}
