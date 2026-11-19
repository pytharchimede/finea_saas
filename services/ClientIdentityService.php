<?php
declare(strict_types=1);
require_once __DIR__.'/FneNccRegistry.php';
require_once __DIR__.'/ClientIdentityMigration.php';
require_once __DIR__.'/PersonalClientIdentity.php';

final class ClientIdentityService
{
    public function __construct(private PDO $pdo,private ?FneNccRegistry $registry=null,private ?InternationalBusinessIdentity $international=null)
    {
        (new ClientIdentityMigration($pdo))->install();
        $this->registry??=new FneNccRegistry();
    }
    public function find(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM client_banamur WHERE id_client=?');$s->execute([$id]);
        $row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Client introuvable.');
        return $row;
    }
    public function preview(string $ncc,int $except=0): array
    {
        $data=$this->registry->lookup($ncc);
        $s=$this->pdo->prepare('SELECT id_client FROM client_banamur WHERE ncc_identity=? AND id_client<>?');
        $s->execute([$data['ncc'],$except]);
        $data['existing_client_id']=($id=$s->fetchColumn())?(int)$id:null;
        return $data;
    }
    public function save(array $input,?int $id=null,int $actor=0,bool $canApprove=false): int
    {
        $old=$id?$this->find($id):null;
        $country=InternationalBusinessIdentity::country((string)($input['fiscal_country']??$old['fiscal_country']??'CI'));
        $type=(string)($input['client_type']??$old['client_type']??'morale');
        if(!in_array($type,['morale','physique'],true))throw new DomainException('Type de client invalide.');
        $personal=$type==='physique';
        $foreign=$personal?PersonalClientIdentity::resolve($input,$old??[],$canApprove,$actor):($country!=='CI'?($this->international??new InternationalBusinessIdentity())->resolve($input,$old??[],$canApprove,$actor):null);
        $fne=$foreign?null:$this->preview((string)($input['ncc']??$old['ncc']??''),$id??0);
        if ($fne && $fne['existing_client_id']) throw new DomainException('Ce NCC appartient déjà au client #'.$fne['existing_client_id'].'. Ouvrez sa fiche existante.');
        $data=['client_type'=>$type];
        if ($foreign) {
            $q=$this->pdo->prepare('SELECT id_client FROM client_banamur WHERE identity_key=? AND id_client<>?');
            $q->execute([$foreign['identity_key'],$id??0]);
            if ($duplicate=$q->fetchColumn()) throw new DomainException('Cet identifiant appartient déjà au client #'.$duplicate.'.');
            foreach ($foreign as $k=>$v) if (!in_array($k,['name','address','city'],true)) $data[$k]=$v;
        } else {
            foreach (InternationalBusinessIdentity::COLUMNS as $k=>$def) $data[$k]=null;
            $data['fiscal_country']='CI'; $data['identity_method']='fne'; $data['identity_status']='registry_verified'; $data['vat_status']='unchecked';
            foreach (['siret','siren','vat_number','registry_verified_at','registry_response_json'] as $k) $data[$k]=null;
        }
        foreach (['telephone_client'=>30,'email_client'=>190,'localisation_client'=>255,'commune_client'=>150,'bp_client'=>100,'pays_client'=>100,'rccm'=>100,'rne'=>100] as $key=>$max) {
            $value=trim((string)($input[$key]??$old[$key]??''));
            if (mb_strlen($value)>$max) throw new DomainException('Le champ '.$key.' est trop long.');
            $data[$key]=$value;
        }
        if ($data['email_client']!=='' && !filter_var($data['email_client'],FILTER_VALIDATE_EMAIL)) throw new DomainException('Adresse email invalide.');
        $data['pays_client']=$data['pays_client']?:'Côte d’Ivoire';
        foreach (['activity_description'=>255,'vat_liability_status'=>100,'vat_last_period'=>150] as $key=>$max) {
            $value=trim((string)($input[$key]??$old[$key]??''));
            if (mb_strlen($value)>$max) throw new DomainException('Le champ '.$key.' est trop long.');
            $data[$key]=$value?:null;
        }
        $startDate=trim((string)($input['activity_start_date']??$old['activity_start_date']??''));
        if ($startDate!=='') {
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$startDate);
            if (!$parsed || $parsed->format('Y-m-d')!==$startDate) throw new DomainException('Date de début d’activité invalide.');
        }
        $data['activity_start_date']=$startDate?:null;
        if ($foreign) {
            if (mb_strlen($foreign['address'])>255 || mb_strlen($foreign['city'])>150) throw new DomainException('Adresse du registre trop longue pour la fiche client.');
            $data['nom_client']=$foreign['name']; $data['localisation_client']=$foreign['address']; $data['commune_client']=$foreign['city']; $data['pays_client']=$country;
            $data+=['ncc'=>null,'tax_regime'=>null,'fiscal_activity_status'=>null,'fiscal_activity_label'=>null,'dgi_status'=>0,'dgi_registered_at'=>null,'dgi_first_registration_at'=>null,'dgi_verified_at'=>null,'dgi_response_json'=>null];
        } else $data+=['nom_client'=>$fne['name'],'ncc'=>$fne['ncc'],'tax_regime'=>$fne['taxRegime'],'fiscal_activity_status'=>$fne['statusActivity'],'fiscal_activity_label'=>$fne['statusActivityLabel'],'dgi_status'=>1,
            'dgi_registered_at'=>self::date($fne['registeredAt']),'dgi_first_registration_at'=>self::date($fne['firstRegistrationDate']),
            'dgi_verified_at'=>gmdate('Y-m-d H:i:s'),'dgi_response_json'=>json_encode($fne,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        $own=!$this->pdo->inTransaction();
        if ($own)$this->pdo->beginTransaction();
        try {
            if ($id) {
                $lock=$this->pdo->prepare('SELECT ncc,identity_snapshot FROM client_banamur WHERE id_client=? FOR UPDATE');$lock->execute([$id]);$current=$lock->fetch(PDO::FETCH_ASSOC);
                if (!$current || (string)$current['ncc']!==(string)$old['ncc'] || (string)$current['identity_snapshot']!==(string)($old['identity_snapshot']??'')) throw new DomainException('La fiche client a changé. Rechargez-la avant de continuer.');
                $sql='UPDATE client_banamur SET '.implode(',',array_map(fn($key)=>"$key=:$key",array_keys($data))).' WHERE id_client=:id';
                $this->pdo->prepare($sql)->execute($data+['id'=>$id]);
            } else {
                $data+=['code_client'=>$foreign?'CL-'.strtoupper(bin2hex(random_bytes(6))):'CL-'.$fne['ncc'],'date_creat_client'=>time(),'logo_client'=>''];
                $this->pdo->prepare('INSERT INTO client_banamur ('.implode(',',array_keys($data)).') VALUES (:'.implode(',:',array_keys($data)).')')->execute($data);
                $id=(int)$this->pdo->lastInsertId();
            }
            $this->pdo->prepare('INSERT INTO client_identity_audit(client_id,user_id,old_identity,new_identity) VALUES (?,?,?,?)')->execute([$id,$actor,json_encode($old?array_intersect_key($old,$data):null,JSON_UNESCAPED_UNICODE),json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
            if ($own)$this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction())$this->pdo->rollBack();
            if ($e instanceof PDOException && ($e->errorInfo[1]??0)===1062) throw new DomainException('Cet identifiant est déjà attribué à un client. Actualisez la liste.');
            throw $e;
        }
    }
    private static function date(?string $v): ?string {return $v?(new DateTimeImmutable($v))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'):null;}
}


