<?php
declare(strict_types=1);
require_once __DIR__.'/ContractWhatsAppCatalog.php';
final class ContractWorkflowPolicy
{
    public static function assertSignatureImage(string $signature):void
    {
        if(strlen($signature)>1500000||!preg_match('~^data:image/(png|jpeg);base64,([A-Za-z0-9+/=]+)$~D',$signature,$m))throw new DomainException('Signature invalide.');
        $bytes=base64_decode($m[2],true);$size=$bytes!==false?@getimagesizefromstring($bytes):false;
        $expected=$m[1]==='png'?IMAGETYPE_PNG:IMAGETYPE_JPEG;
        if(!$size||$size[2]!==$expected||$size[0]<40||$size[1]<10||$size[0]>2000||$size[1]>1000)throw new DomainException('L’image de signature est invalide.');
    }

    public static function assertComplete(string $stage,array $data):void
    {
        $required=['rh'=>['dossier_fournisseur'],'juridique'=>['parties_donneur','parties_sous_traitant','articles_juridiques'],'technique'=>['objet_contrat','description_travaux','planning_description','methodologie','date_debut','delai_jours'],'logistique'=>['vehicules_engins','materiaux','materiel_outillage'],'qhse'=>['epis','exigences_qhse'],'comptabilite'=>['condition_reglement_taches']];
        if(!isset($required[$stage]))throw new DomainException('Étape inconnue.');
        if($stage==='qhse'&&!empty($data['qhse_file']))return;
        foreach($required[$stage] as $field)if(!isset($data[$field])||(!is_array($data[$field])&&trim((string)$data[$field])==='')||$data[$field]===[])throw new DomainException('Complétez et enregistrez tous les champs obligatoires avant de signer.');
        if($stage==='technique'){$date=DateTimeImmutable::createFromFormat('!Y-m-d',(string)$data['date_debut']);if(!$date||$date->format('Y-m-d')!==$data['date_debut']||(int)$data['delai_jours']<1)throw new DomainException('Date ou délai technique invalide.');}
        if($stage==='comptabilite'){
            foreach(['taux_acompte'=>'condition_reglement_acompte','retenue_garantie'=>'condition_reglement_retenue'] as $rate=>$condition)if((float)($data[$rate]??0)>0&&trim((string)($data[$condition]??''))==='')throw new DomainException('Précisez les conditions de règlement avant de signer.');
        }
        if($stage==='comptabilite')foreach(['taux_acompte','retenue_garantie'] as $field)if(!isset($data[$field])||!is_numeric($data[$field])||(float)$data[$field]<0||(float)$data[$field]>100)throw new DomainException('Pourcentage comptable invalide.');
    }

    public static function assertInternalSigned(array $stages):void
    {
        if(count($stages)!==6)throw new DomainException('Les six étapes internes sont requises.');
        $expected=array_keys(ContractWhatsAppCatalog::STAGES);array_pop($expected);
        foreach($stages as $index=>$s)if(($s['etape']??'')!==$expected[$index]||($s['statut']??'')!=='validee'||empty($s['signature']))throw new DomainException('Les six visas internes doivent être signés avant la signature finale.');
    }

    public static function finalHash(array $contract,array $stages):string
    {
        $keys=['id','reference','chantier_id','fournisseur_id','objet','montant_ht','taux_acompte','retenue_garantie_taux','date_debut','date_fin','delai_jours','clauses_particulieres','signataire_banamur_personnel_id','signature_banamur','signature_prestataire'];$data=[];
        foreach($keys as $key)$data[$key]=(string)($contract[$key]??'');
        foreach($stages as $s)$data['stages'][]=[(string)$s['etape'],(string)$s['statut'],(string)$s['donnees_json'],(int)$s['signataire_personnel_id'],(string)($s['signature']??'')];
        return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
    }
}
