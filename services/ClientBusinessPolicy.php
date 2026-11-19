<?php
declare(strict_types=1);

/** One interpretation of client identity for project and sales workflows. */
final class ClientBusinessPolicy
{
    public static function sqlUsable(string $alias=''): string
    {
        if($alias!==''&&!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D',$alias))throw new InvalidArgumentException('Alias SQL invalide.');
        $p=$alias!==''?$alias.'.':'';
        return "(({$p}client_type='morale' AND {$p}fiscal_country='CI' AND NULLIF(TRIM({$p}ncc),'') IS NOT NULL AND {$p}dgi_status=1 AND {$p}dgi_verified_at IS NOT NULL) OR (({$p}fiscal_country<>'CI' OR {$p}client_type='physique') AND NULLIF(TRIM({$p}identity_key),'') IS NOT NULL AND COALESCE({$p}identity_status,'') IN ('registry_verified','documentary_verified')))";
    }
    public static function usable(array $client): bool
    {
        $country=strtoupper(trim((string)($client['fiscal_country']??'CI')));
        if (($client['client_type']??'morale')==='morale' && $country==='CI') return trim((string)($client['ncc']??''))!=='' && (int)($client['dgi_status']??0)===1 && !empty($client['dgi_verified_at']);
        return trim((string)($client['identity_key']??''))!=='' && in_array($client['identity_status']??'', ['registry_verified','documentary_verified'],true);
    }
    public static function displayIdentifier(array $client):array
    {
        if(($client['client_type']??'morale')==='physique')return ['label'=>'Particulier · référence client','value'=>$client['code_client']??''];
        if(($client['fiscal_country']??'CI')==='CI')return ['label'=>'NCC officiel','value'=>$client['ncc']??''];
        return ['label'=>($client['registration_type']??'')?:'Identifiant international','value'=>($client['registration_number']??'')?:($client['siret']??'')?:($client['vat_number']??'')];
    }
    public static function requireClient(PDO $pdo,int $id): array
    {
        $q=$pdo->prepare('SELECT * FROM client_banamur WHERE id_client=? LIMIT 1');$q->execute([$id]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row || !self::usable($row)) throw new DomainException('Régularisez l’identité du client : NCC/FNE pour une entreprise ivoirienne, registre pour une entreprise étrangère, contrôle documentaire pour un particulier.');
        return $row;
    }
    public static function billing(array $client,array $input=[]): array
    {
        if (!self::usable($client)) throw new DomainException('L’identité du client doit être validée avant facturation.');
        $country=strtoupper((string)($client['fiscal_country']??'CI'));
        $foreign=$country!=='CI';
        $personal=($client['client_type']??'morale')==='physique';
        $template=$foreign?'B2F':($personal?'B2C':(strtoupper((string)($input['template']??'B2B'))==='B2G'?'B2G':'B2B'));
        return array_replace($input,[
            'client_id'=>(int)$client['id_client'], 'client_fiscal_country'=>$country,
            'client_identity_type'=>$personal?'PERSON':($foreign?(string)(($client['registration_type']??'')?:($client['identity_method']??'')):'NCC'),
            'client_identity_number'=>$personal?null:($foreign?(string)(($client['registration_number']??'')?:(($client['siret']??'')?:($client['vat_number']??''))):(string)$client['ncc']),
            'client_company_name'=>(string)$client['nom_client'],
            'client_phone'=>(string)($client['telephone_client']??''), 'client_email'=>(string)($client['email_client']??''),
            'template'=>$template, 'client_ncc'=>($foreign||$personal)?null:$client['ncc'],
            'is_rne'=>($foreign||$personal)?0:(!empty($input['is_rne'])?1:0), 'rne'=>($foreign||$personal)?null:($input['rne']??null),
        ]);
    }
    public static function forChantier(PDO $pdo,array $input): array
    {
        $q=$pdo->prepare('SELECT client_id FROM chantier_banamur WHERE id=? LIMIT 1');$q->execute([(int)($input['chantier_id']??0)]);
        $id=(int)$q->fetchColumn();
        if ($id<1) throw new DomainException('Le chantier doit être rattaché à une fiche client vérifiée.');
        if (!empty($input['client_id']) && (int)$input['client_id']!==$id) throw new DomainException('Le client sélectionné ne correspond pas au chantier.');
        return self::billing(self::requireClient($pdo,$id),$input);
    }
}
