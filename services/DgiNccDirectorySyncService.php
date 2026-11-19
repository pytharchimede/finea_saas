<?php

final class DgiNccDirectorySyncService
{
    private PDO $pdo;
    private string $endpoint='https://www.services.fne.dgi.gouv.ci/ws/ncc-verification';
    private array $supplierIdentity=[];

    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->ensureSchema();}

    private function ensureSchema():void
    {
        foreach([
            "ALTER TABLE client_banamur ADD tax_regime VARCHAR(100) NULL",
            "ALTER TABLE client_banamur ADD fiscal_activity_status VARCHAR(40) NULL",
            "ALTER TABLE client_banamur ADD dgi_registered_at DATETIME(3) NULL",
            "ALTER TABLE client_banamur ADD dgi_first_registration_at DATETIME(3) NULL",
            "ALTER TABLE client_banamur ADD dgi_verified_at DATETIME NULL",
            "ALTER TABLE fournisseur_banamur ADD tax_regime VARCHAR(100) NULL",
            "ALTER TABLE fournisseur_banamur ADD fiscal_activity_status VARCHAR(40) NULL",
            "ALTER TABLE fournisseur_banamur ADD dgi_registered_at DATETIME(3) NULL",
            "ALTER TABLE fournisseur_banamur ADD dgi_first_registration_at DATETIME(3) NULL",
            "ALTER TABLE fournisseur_banamur ADD dgi_verified_at DATETIME NULL",
        ]as$sql){try{$this->pdo->exec($sql);}catch(Throwable$ignored){}}
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS dgi_ncc_registry_cache(
            ncc VARCHAR(100) NOT NULL,legal_name VARCHAR(255) NULL,tax_regime VARCHAR(100) NULL,
            is_valid TINYINT(1) NOT NULL DEFAULT 0,activity_status VARCHAR(40) NULL,
            activity_status_label VARCHAR(100) NULL,registered_at DATETIME(3) NULL,
            first_registration_at DATETIME(3) NULL,response_json LONGTEXT NULL,
            last_http_status INT NULL,last_error TEXT NULL,verified_at DATETIME NOT NULL,
            PRIMARY KEY(ncc),KEY idx_dgi_ncc_verified(verified_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['client_banamur'=>['uniq_client_ncc'=>'ncc'],'fournisseur_banamur'=>['uniq_supplier_ncc'=>'ncc']]as$table=>$indexes)foreach($indexes as$name=>$column){try{$duplicates=(int)$this->pdo->query("SELECT COUNT(*) FROM(SELECT UPPER(REPLACE(TRIM($column),' ','')) normalized FROM $table WHERE $column IS NOT NULL AND TRIM($column)<>'' GROUP BY normalized HAVING COUNT(*)>1)d")->fetchColumn();if($duplicates===0){$names=$this->pdo->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_COLUMN,2)?:[];if(!in_array($name,$names,true))$this->pdo->exec("ALTER TABLE $table ADD UNIQUE KEY $name ($column)");}}catch(Throwable$ignored){}}
    }

    public function synchronize(int $limit=200,bool $force=false):array
    {
        require_once __DIR__.'/../model/FnePartyLinkManager.php';
        $partyManager=new FnePartyLinkManager($this->pdo);
        $candidates=$this->candidates(max(1,min(1000,$limit)));
        $report=['candidates'=>count($candidates),'api_calls'=>0,'cache_hits'=>0,'clients_created'=>0,'clients_updated'=>0,'suppliers_created'=>0,'suppliers_updated'=>0,'invalid_ncc'=>0,'errors'=>[]];
        foreach($candidates as$candidate){
            $ncc=$this->normalizeNcc((string)$candidate['ncc']);if($ncc==='')continue;
            try{
                $registry=$this->registry($ncc,$force);$report[$registry['_cached']?'cache_hits':'api_calls']++;
                if(empty($registry['status'])){$report['invalid_ncc']++;continue;}
                $this->pdo->beginTransaction();
                foreach(['sale','purchase']as$context){
                    if(empty($candidate['contexts'][$context]))continue;
                    $entity=$this->upsertEntity($context,$registry,$candidate);
                    $report[$context==='sale'?($entity['created']?'clients_created':'clients_updated'):($entity['created']?'suppliers_created':'suppliers_updated')]++;
                    $importId=(int)($candidate['import_ids'][$context]??0);
                    if($importId>0)$partyManager->link($context,$importId,(int)$entity['id'],0);
                }
                $this->pdo->commit();
            }catch(Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();$report['errors'][]=$ncc.' : '.$e->getMessage();}
        }
        $partyManager->autoApplyAll();return$report;
    }

    private function candidates(int$limit):array
    {
        $out=[];
        $add=function(string$ncc,string$context,int$importId=0,array$data=[])use(&$out):void{$ncc=$this->normalizeNcc($ncc);if($ncc==='')return;$out[$ncc]??=['ncc'=>$ncc,'contexts'=>[],'import_ids'=>[],'client_name'=>'','client_phone'=>'','client_email'=>'','client_rccm'=>'','rne'=>''];$out[$ncc]['contexts'][$context]=true;if($importId>0)$out[$ncc]['import_ids'][$context]=$importId;foreach(['client_name','client_phone','client_email','client_rccm','rne']as$key)if(trim((string)($data[$key]??''))!==''&&trim((string)$out[$ncc][$key])==='')$out[$ncc][$key]=$data[$key];};
        foreach($this->pdo->query("SELECT * FROM fne_sales_invoice_imports WHERE client_ncc IS NOT NULL AND TRIM(client_ncc)<>'' ORDER BY invoice_date DESC,id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC)?:[]as$row)$add((string)$row['client_ncc'],'sale',(int)$row['id'],$row);
        foreach($this->pdo->query("SELECT *,supplier_ncc client_ncc,supplier_name client_name,'' client_phone,'' client_email,'' client_rccm FROM fne_purchase_invoice_imports WHERE supplier_ncc IS NOT NULL AND TRIM(supplier_ncc)<>'' ORDER BY invoice_date DESC,id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC)?:[]as$row)$add((string)$row['client_ncc'],'purchase',(int)$row['id'],$row);
        foreach($this->pdo->query("SELECT ncc,nom_client client_name,telephone_client client_phone,email_client client_email,rccm client_rccm,rne FROM client_banamur WHERE ncc IS NOT NULL AND TRIM(ncc)<>'' LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC)?:[]as$row)$add((string)$row['ncc'],'sale',0,$row);
        foreach($this->pdo->query("SELECT ncc,raison_sociale client_name,telephone client_phone,email client_email,'' client_rccm,rne FROM fournisseur_banamur WHERE ncc IS NOT NULL AND TRIM(ncc)<>'' LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC)?:[]as$row)$add((string)$row['ncc'],'purchase',0,$row);
        return array_slice(array_values($out),0,$limit);
    }

    private function registry(string$ncc,bool$force):array
    {
        if(!$force){$s=$this->pdo->prepare("SELECT * FROM dgi_ncc_registry_cache WHERE ncc=:ncc AND verified_at>=DATE_SUB(NOW(),INTERVAL 24 HOUR) AND last_error IS NULL");$s->execute(['ncc'=>$ncc]);if($row=$s->fetch(PDO::FETCH_ASSOC))return['ncc'=>$ncc,'name'=>$row['legal_name'],'taxRegime'=>$row['tax_regime'],'status'=>(bool)$row['is_valid'],'statusActivity'=>$row['activity_status'],'statusActivityLabel'=>$row['activity_status_label'],'registeredAt'=>$row['registered_at'],'firstRegistrationDate'=>$row['first_registration_at'],'_cached'=>true];}
        $url=$this->endpoint.'?'.http_build_query(['ncc'=>$ncc]);$ch=curl_init($url);if($ch===false)throw new RuntimeException('Initialisation HTTP impossible.');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: Finea-NCC-Sync/1.0'],CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw=curl_exec($ch);$error=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        $json=is_string($raw)?json_decode($raw,true):null;
        if($status<200||$status>=300||!is_array($json)){$this->cacheError($ncc,$status,$error?:'Réponse DGI invalide',is_string($raw)?$raw:null);throw new RuntimeException($error?:'Erreur DGI HTTP '.$status);}
        $registered=$this->date($json['registeredAt']??null);$first=$this->date($json['firstRegistrationDate']??null);
        $s=$this->pdo->prepare("INSERT INTO dgi_ncc_registry_cache(ncc,legal_name,tax_regime,is_valid,activity_status,activity_status_label,registered_at,first_registration_at,response_json,last_http_status,last_error,verified_at) VALUES(:ncc,:name,:regime,:valid,:activity,:label,:registered,:first,:json,:http,NULL,NOW()) ON DUPLICATE KEY UPDATE legal_name=VALUES(legal_name),tax_regime=VALUES(tax_regime),is_valid=VALUES(is_valid),activity_status=VALUES(activity_status),activity_status_label=VALUES(activity_status_label),registered_at=VALUES(registered_at),first_registration_at=VALUES(first_registration_at),response_json=VALUES(response_json),last_http_status=VALUES(last_http_status),last_error=NULL,verified_at=NOW()");
        $s->execute(['ncc'=>$ncc,'name'=>$json['name']??null,'regime'=>$json['taxRegime']??null,'valid'=>!empty($json['status'])?1:0,'activity'=>$json['statusActivity']??null,'label'=>$json['statusActivityLabel']??null,'registered'=>$registered,'first'=>$first,'json'=>json_encode($json,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'http'=>$status]);
        $json['_cached']=false;return$json;
    }

    private function upsertEntity(string$context,array$dgi,array$candidate):array
    {
        $ncc=$this->normalizeNcc((string)$dgi['ncc']);$legal=trim((string)($dgi['name']??''));if($legal==='')throw new RuntimeException('Nom légal absent de la réponse DGI.');
        if($context==='purchase')$this->supplierIdentity=$candidate;
        if($context==='sale'){$s=$this->pdo->prepare("SELECT id_client id FROM client_banamur WHERE UPPER(REPLACE(TRIM(ncc),' ',''))=:ncc");$s->execute(['ncc'=>$ncc]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>1)throw new RuntimeException('Plusieurs clients portent déjà ce NCC. Fusion manuelle requise.');$nameIds=$this->uniqueClientByName($legal);if(count($ids)===1&&count($nameIds)===1&&(int)$ids[0]!== (int)$nameIds[0]){$this->clearWrongNcc('sale',(int)$ids[0],$ncc);$ids=$nameIds;}elseif(!$ids)$ids=$nameIds;if($ids){$id=(int)$ids[0];$q=$this->pdo->prepare("UPDATE client_banamur SET nom_client=:name,ncc=:ncc,telephone_client=COALESCE(NULLIF(:phone,''),telephone_client),email_client=COALESCE(NULLIF(:email,''),email_client),rccm=COALESCE(NULLIF(:rccm,''),rccm),rne=COALESCE(NULLIF(:rne,''),rne),tax_regime=:regime,fiscal_activity_status=:activity,dgi_registered_at=:registered,dgi_first_registration_at=:first,dgi_verified_at=NOW() WHERE id_client=:id");$created=false;}else{$code='CL-DGI-'.preg_replace('/[^A-Z0-9]/','',$ncc);$q=$this->pdo->prepare("INSERT INTO client_banamur(code_client,nom_client,localisation_client,commune_client,bp_client,pays_client,date_creat_client,telephone_client,email_client,ncc,rccm,rne,tax_regime,fiscal_activity_status,dgi_registered_at,dgi_first_registration_at,dgi_verified_at) VALUES(:code,:name,'','','','Côte d’Ivoire',:created_at,:phone,:email,:ncc,:rccm,:rne,:regime,:activity,:registered,:first,NOW())");$created=true;}
        }else{$s=$this->pdo->prepare("SELECT id_fournisseur id FROM fournisseur_banamur WHERE UPPER(REPLACE(TRIM(ncc),' ',''))=:ncc");$s->execute(['ncc'=>$ncc]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);if(count($ids)>1)throw new RuntimeException('Plusieurs fournisseurs portent déjà ce NCC. Fusion manuelle requise.');$nameIds=$this->uniqueSupplierByName($legal);if(count($ids)===1&&count($nameIds)===1&&(int)$ids[0]!== (int)$nameIds[0]){$this->clearWrongNcc('purchase',(int)$ids[0],$ncc);$ids=$nameIds;}elseif(!$ids)$ids=$nameIds;if($ids){$id=(int)$ids[0];$q=$this->pdo->prepare("UPDATE fournisseur_banamur SET raison_sociale=:name,ncc=:ncc,telephone=COALESCE(NULLIF(:phone,''),telephone),email=COALESCE(NULLIF(:email,''),email),rne=COALESCE(NULLIF(:rne,''),rne),actif=1,validation_status='approved',validation_note='Validé automatiquement depuis une facture FNE et le registre NCC DGI',validated_at=COALESCE(validated_at,NOW()),tax_regime=:regime,fiscal_activity_status=:activity,dgi_registered_at=:registered,dgi_first_registration_at=:first,dgi_verified_at=NOW(),updated_at=NOW() WHERE id_fournisseur=:id");$created=false;}else{$code='FR-DGI-'.preg_replace('/[^A-Z0-9]/','',$ncc);$q=$this->pdo->prepare("INSERT INTO fournisseur_banamur(code_fournisseur,supplier_type,raison_sociale,ncc,rne,is_rne,email,telephone,pays,actif,validation_status,validation_note,validated_at,tax_regime,fiscal_activity_status,dgi_registered_at,dgi_first_registration_at,dgi_verified_at,created_at,updated_at) VALUES(:code,'morale',:name,:ncc,:rne,IF(NULLIF(:rne_check,'') IS NULL,0,1),:email,:phone,'Côte d’Ivoire',1,'approved','Validé automatiquement depuis une facture FNE et le registre NCC DGI',NOW(),:regime,:activity,:registered,:first,NOW(),NOW(),NOW())");$created=true;}}
        $params=['name'=>$legal,'ncc'=>$ncc,'phone'=>$candidate['client_phone']??'','email'=>$candidate['client_email']??'','rne'=>$candidate['rne']??'','regime'=>$dgi['taxRegime']??null,'activity'=>$dgi['statusActivity']??null,'registered'=>$this->date($dgi['registeredAt']??null),'first'=>$this->date($dgi['firstRegistrationDate']??null)];
        if($context==='sale')$params['rccm']=$candidate['client_rccm']??'';
        if($created){$params['code']=$code;if($context==='sale')$params['created_at']=time();else$params['rne_check']=$candidate['rne']??'';}else$params['id']=$id;
        $q->execute($params);if($created)$id=(int)$this->pdo->lastInsertId();return['id'=>$id,'created'=>$created];
    }

    private function uniqueClientByName(string$name):array{$rows=$this->pdo->query("SELECT id_client,nom_client FROM client_banamur")->fetchAll(PDO::FETCH_ASSOC)?:[];return$this->uniqueNormalized($rows,'id_client','nom_client',$name);}
    private function uniqueSupplierByName(string$name):array
    {
        $rows=$this->pdo->query("SELECT id_fournisseur,raison_sociale,nom_commercial,email,telephone FROM fournisseur_banamur")->fetchAll(PDO::FETCH_ASSOC)?:[];
        $names=array_filter([$name,(string)($this->supplierIdentity['client_name']??'')],fn(string$v):bool=>trim($v)!=='');
        $email=$this->normalizeEmail((string)($this->supplierIdentity['client_email']??''));
        $phone=$this->normalizePhone((string)($this->supplierIdentity['client_phone']??''));
        $ids=[];
        foreach($rows as$row){
            $matched=false;
            foreach($names as$needle)if($this->normalizeName((string)$row['raison_sociale'])===$this->normalizeName($needle)||$this->normalizeName((string)$row['nom_commercial'])===$this->normalizeName($needle)){$matched=true;break;}
            if(!$matched&&$email!==''&&$this->normalizeEmail((string)$row['email'])===$email)$matched=true;
            if(!$matched&&$phone!==''&&$this->normalizePhone((string)$row['telephone'])===$phone)$matched=true;
            if($matched)$ids[]=(int)$row['id_fournisseur'];
        }
        $ids=array_values(array_unique($ids));
        if(count($ids)>1)throw new RuntimeException('Plusieurs fournisseurs correspondent au nom, mail ou téléphone FNE. Fusion requise.');
        return$ids;
    }
    private function uniqueNormalized(array$rows,string$idKey,string$nameKey,string$name):array{$needle=$this->normalizeName($name);$ids=[];foreach($rows as$row)if($this->normalizeName((string)$row[$nameKey])===$needle)$ids[]=(int)$row[$idKey];return count($ids)===1?$ids:[];}
    private function clearWrongNcc(string$context,int$id,string$ncc):void{if($context==='sale'){$s=$this->pdo->prepare("UPDATE client_banamur SET ncc=NULL,dgi_verified_at=NULL WHERE id_client=:id AND UPPER(REPLACE(TRIM(ncc),' ',''))=:ncc");}else{$s=$this->pdo->prepare("UPDATE fournisseur_banamur SET ncc=NULL,dgi_verified_at=NULL,updated_at=NOW() WHERE id_fournisseur=:id AND UPPER(REPLACE(TRIM(ncc),' ',''))=:ncc");}$s->execute(['id'=>$id,'ncc'=>$ncc]);}
    private function normalizeName(string$v):string{$v=mb_strtolower(trim($v),'UTF-8');$a=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);return preg_replace('/[^a-z0-9]+/','',strtolower($a!==false?$a:$v));}
    private function normalizeEmail(string$v):string{return mb_strtolower(trim($v),'UTF-8');}
    private function normalizePhone(string$v):string{return preg_replace('/[^0-9]+/','',$v);}
    private function normalizeNcc(string$v):string{return preg_replace('/[^A-Z0-9]/','',mb_strtoupper(trim($v),'UTF-8'));}
    private function date($v):?string{if(!$v)return null;try{return(new DateTimeImmutable((string)$v))->format('Y-m-d H:i:s.v');}catch(Throwable$e){return null;}}
    private function cacheError(string$ncc,int$status,string$error,?string$raw):void{$s=$this->pdo->prepare("INSERT INTO dgi_ncc_registry_cache(ncc,is_valid,response_json,last_http_status,last_error,verified_at) VALUES(:ncc,0,:json,:http,:error,NOW()) ON DUPLICATE KEY UPDATE response_json=VALUES(response_json),last_http_status=VALUES(last_http_status),last_error=VALUES(last_error),verified_at=NOW()");$s->execute(['ncc'=>$ncc,'json'=>$raw,'http'=>$status,'error'=>$error]);}
}
