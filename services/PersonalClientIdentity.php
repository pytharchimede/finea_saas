<?php
declare(strict_types=1);
/** A private customer has no business tax identifier; validation is documentary, never a registry claim. */
final class PersonalClientIdentity
{
    public static function resolve(array $input,array $old,bool $canApprove,int $actor):array
    {
        $name=trim((string)($input['nom_client']??$old['nom_client']??''));
        $address=trim((string)($input['localisation_client']??$old['localisation_client']??''));
        $country=InternationalBusinessIdentity::country((string)($input['fiscal_country']??$old['fiscal_country']??'CI'));
        $phone=trim((string)($input['telephone_client']??$old['telephone_client']??''));
        $email=trim((string)($input['email_client']??$old['email_client']??''));
        $evidence=trim((string)($input['identity_evidence']??$old['identity_evidence']??''));
        if($name===''||mb_strlen($name)>255||$address===''||($phone===''&&$email===''))throw new DomainException('Pour un particulier, renseignez le nom complet, l’adresse et au moins un téléphone ou un email.');
        if(mb_strlen($evidence)>500)throw new DomainException('La référence du justificatif est trop longue.');
        $snapshot=hash('sha256',json_encode([$name,$country,$address,$phone,$email,$evidence],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        $same=($old['client_type']??'morale')==='physique'&&hash_equals((string)($old['identity_snapshot']??''),$snapshot);
        $approved=$same&&($old['identity_status']??'')==='documentary_verified';
        if(!empty($input['documentary_approve'])&&$canApprove&&$actor>0){
            if($country==='CI'&&($phone===''||!filter_var($email,FILTER_VALIDATE_EMAIL)))throw new DomainException('Pour valider un particulier ivoirien, renseignez le téléphone et un email valide nécessaires à la facturation FNE B2C.');
            if(mb_strlen($evidence)<10)throw new DomainException('Indiquez la référence du contrôle documentaire effectué pour approuver ce particulier.');
            $approved=true;
        }
        $result=array_fill_keys(array_keys(InternationalBusinessIdentity::COLUMNS),null);
        return array_replace($result,[
            'fiscal_country'=>$country,'identity_method'=>'personal','identity_status'=>$approved?'documentary_verified':'documentary_pending',
            'identity_key'=>($old['client_type']??'morale')==='physique'&&!empty($old['identity_key'])?$old['identity_key']:$country.':PERSON:'.bin2hex(random_bytes(16)),
            'identity_evidence'=>$evidence?:null,'identity_snapshot'=>$snapshot,'identity_checked_at'=>$approved?($same?($old['identity_checked_at']??gmdate('Y-m-d H:i:s')):gmdate('Y-m-d H:i:s')):null,
            'identity_checked_by'=>$approved?($same?(int)($old['identity_checked_by']??$actor):$actor):null,
            'vat_status'=>'unchecked','siret'=>null,'siren'=>null,'vat_number'=>null,'registry_verified_at'=>null,'registry_response_json'=>null,
            'name'=>$name,'address'=>$address,'city'=>trim((string)($input['commune_client']??$old['commune_client']??'')),
        ]);
    }
}
