<?php
declare(strict_types=1);
require_once __DIR__.'/FrenchCompanyRegistry.php';

/** Shared public-registry adapters. VAT validity never implies documentary approval. */
final class InternationalBusinessIdentity
{
    public const COUNTRIES = 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW';
    public const EU = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
    public const COLUMNS = [
        'registration_number'=>'VARCHAR(100) NULL', 'registration_type'=>'VARCHAR(30) NULL',
        'identity_method'=>"VARCHAR(20) NOT NULL DEFAULT 'fne'", 'identity_status'=>"VARCHAR(30) NOT NULL DEFAULT 'unverified'",
        'identity_key'=>'VARCHAR(160) NULL', 'identity_evidence'=>'VARCHAR(500) NULL',
        'identity_snapshot'=>'LONGTEXT NULL', 'identity_checked_at'=>'DATETIME NULL',
        'identity_checked_by'=>'INT NULL', 'vat_status'=>"VARCHAR(20) NOT NULL DEFAULT 'unchecked'"
    ];
    public function __construct(private mixed $transport = null, private ?FrenchCompanyRegistry $fr = null) {}
    public static function country(string $country): string
    {
        $country = strtoupper(trim($country));
        if (!in_array($country,explode(' ',self::COUNTRIES),true)) throw new DomainException('Utilisez un code pays ISO de deux lettres (DE, US, IE…).');
        return $country;
    }
    public function resolve(array $input, array $old = [], bool $canApprove = false, int $actor = 0): array
    {
        $country = self::country((string)($input['fiscal_country'] ?? $old['fiscal_country'] ?? 'CI'));
        if ($country === 'CI') throw new DomainException('Utilisez la vérification NCC/FNE pour la Côte d’Ivoire.');
        $method = (string)($input['identity_method'] ?? $old['identity_method'] ?? ($country === 'FR' ? 'fr' : 'manual'));
        // Old FR records created before the international schema default to fne.
        if ($country === 'FR' && $method === 'fne') $method = 'fr';
        if (!in_array($method, ['fr','vies','sec','manual'], true)) throw new DomainException('Méthode de vérification invalide.');
        $number = trim((string)($input['registration_number'] ?? $old['registration_number'] ?? ''));
        $type = strtoupper(trim((string)($input['registration_type'] ?? $old['registration_type'] ?? 'LOCAL')));
        $vat = strtoupper(preg_replace('/[\s.\-]+/u', '', (string)($input['vat_number'] ?? $old['vat_number'] ?? '')));
        $name = trim((string)($input['nom_commercial'] ?? $input['nom_client'] ?? $old['nom_commercial'] ?? $old['nom_client'] ?? ''));
        $address = trim((string)($input['adresse'] ?? $input['localisation_client'] ?? $old['adresse'] ?? $old['localisation_client'] ?? ''));
        $city = trim((string)($input['ville'] ?? $input['commune_client'] ?? $old['ville'] ?? $old['commune_client'] ?? ''));
        $evidence = trim((string)($input['identity_evidence'] ?? $old['identity_evidence'] ?? ''));
        if (mb_strlen($number)>100 || mb_strlen($type)>30 || mb_strlen($vat)>20 || mb_strlen($evidence)>500) throw new DomainException('Identifiant ou référence justificative trop long.');
        $status = 'documentary_pending'; $proof = []; $siret = $siren = null; $vatStatus = 'unchecked';
        if ($method === 'fr') {
            if ($country !== 'FR') throw new DomainException('Le SIRET est réservé à la France.');
            $proof = ($this->fr ?? new FrenchCompanyRegistry())->lookup((string)(($input['siret'] ?? '') ?: ($number ?: ($old['siret'] ?? ''))));
            $name = $proof['name']; $address = $proof['address']; $city = $proof['city'];
            $siret = $number = $proof['siret']; $siren = $proof['siren']; $type = 'SIRET'; $status = 'registry_verified';
            if ($vat !== '' && (!preg_match('/^FR[A-Z0-9]{2}[0-9]{9}$/D', $vat) || substr($vat,4)!==$siren)) throw new DomainException('La TVA française ne correspond pas au SIREN.');
        } elseif ($method === 'vies') {
            if (!in_array($country, self::EU, true)) throw new DomainException('VIES concerne les pays de l’Union européenne.');
            $prefix = $country === 'GR' ? 'EL' : $country;
            if (!preg_match('/^'.$prefix.'[A-Z0-9]{2,12}$/D', $vat)) throw new DomainException('Renseignez une TVA avec le préfixe '.$prefix.'.');
            $proof = $this->request('vies', ['countryCode'=>$prefix, 'vatNumber'=>substr($vat,2)]);
            if (($proof['countryCode'] ?? '') !== $prefix || strtoupper((string)($proof['vatNumber'] ?? '')) !== substr($vat,2)) throw new RuntimeException('Réponse VIES incohérente.');
            if (($proof['isValid'] ?? $proof['valid'] ?? null) !== true || (!empty($proof['userError']) && $proof['userError'] !== 'VALID')) throw new DomainException('TVA non confirmée par VIES. Vérifiez le numéro ou utilisez un contrôle documentaire.');
            $vatStatus = 'valid';
            foreach (['name','address'] as $field) {
                $official=trim((string)($proof[$field]??''));
                if ($official!=='' && $official!=='---') {if ($field==='name') $name=$official; else $address=$official;}
            }
            // VIES can omit names/addresses. Even a valid VAT is not a legal identity approval.
        } elseif ($method === 'sec') {
            if ($country !== 'US') throw new DomainException('La recherche SEC est réservée au circuit américain.');
            if (!preg_match('/^[0-9]{1,10}$/D', $number)) throw new DomainException('Renseignez un CIK SEC de 1 à 10 chiffres.');
            $number = str_pad($number,10,'0',STR_PAD_LEFT); $type = 'CIK';
            $proof = $this->request('sec', ['cik'=>$number]);
            if (str_pad((string)($proof['cik'] ?? ''),10,'0',STR_PAD_LEFT) !== $number || empty($proof['name'])) throw new RuntimeException('Réponse SEC incohérente.');
            $name = (string)$proof['name'];
            $a = $proof['addresses']['business'] ?? [];
            $address = trim(implode(' ', array_filter([$a['street1'] ?? '', $a['street2'] ?? '', $a['city'] ?? '', $a['stateOrCountry'] ?? '', $a['zipCode'] ?? ''])));
            $city = (string)($a['city'] ?? ''); $status = 'registry_verified';
        } else {
            if ($country === 'US' && $type === 'EIN') {
                $number = str_replace([' ','-'], '', $number);
                if (!preg_match('/^[0-9]{9}$/D',$number)) throw new DomainException('Un EIN comporte 9 chiffres.');
            }
            if ($number === '') throw new DomainException('Renseignez un identifiant fiscal ou commercial local.');
        }
        if ($name === '' || $address === '' || mb_strlen($name)>255 || mb_strlen($address)>1000) throw new DomainException('Complétez le nom légal et l’adresse du tiers.');
        $snapshot = json_encode([$country,$method,$type,$number,$vat,$name,$address,$evidence],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $checkedBy = null;
        if ($status === 'documentary_pending') {
            if ($canApprove && $actor > 0 && !empty($input['documentary_approve'])) {
                if ($evidence === '') throw new DomainException('Une référence de justificatif est obligatoire pour approuver l’identité.');
                $status = 'documentary_verified'; $checkedBy = $actor;
            } elseif (($old['identity_status'] ?? '') === 'documentary_verified' && ($old['identity_snapshot'] ?? '') === $snapshot) {
                $status = 'documentary_verified'; $checkedBy = $old['identity_checked_by'] ?? null;
            }
        }
        if ($type === '' || !preg_match('/^[A-Z0-9_]{1,30}$/D',$type)) throw new DomainException('Type d’identifiant invalide.');
        $keyNumber = $method === 'vies' ? $vat : $number;
        return ['fiscal_country'=>$country,'identity_method'=>$method,'identity_status'=>$status,
            'registration_type'=>$type,'registration_number'=>$number ?: null,'identity_key'=>$country.':'.($method==='vies'?'VAT':$type).':'.strtoupper(preg_replace('/\s+/u','',$keyNumber)),
            'identity_evidence'=>$evidence ?: null,'identity_snapshot'=>$snapshot,'identity_checked_at'=>gmdate('Y-m-d H:i:s'), 'identity_checked_by'=>$checkedBy,
            'vat_number'=>$vat ?: null,'vat_status'=>$vatStatus,'siret'=>$siret,'siren'=>$siren,
            'registry_verified_at'=>$status==='registry_verified'?gmdate('Y-m-d H:i:s'):null,
            'registry_response_json'=>json_encode($proof,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            'name'=>$name,'address'=>$address,'city'=>$city];
    }
    private function request(string $service, array $params): array
    {
        if ($this->transport) [$status,$raw] = ($this->transport)($service,$params);
        else {
            $url = $service==='vies' ? 'https://ec.europa.eu/taxation_customs/vies/rest-api/check-vat-number' : 'https://data.sec.gov/submissions/CIK'.$params['cik'].'.json';
            $ch = curl_init($url);
            $options = [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/json','User-Agent: Finea Business Registry admin@fidest.ci']];
            if ($service==='vies') {$options[CURLOPT_POST]=true; $options[CURLOPT_POSTFIELDS]=json_encode($params,JSON_THROW_ON_ERROR);}
            curl_setopt_array($ch,$options); $raw=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        }
        if ($status===429) throw new RuntimeException('Limite du registre atteinte. Patientez avant de réessayer.');
        if ($status!==200 || !is_string($raw)) throw new RuntimeException('Registre indisponible. Réessayez ou utilisez la validation documentaire.');
        try {$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);} catch (JsonException $e) {throw new RuntimeException('Réponse du registre illisible.');}
        if (!is_array($data)) throw new RuntimeException('Réponse du registre invalide.');
        return $data;
    }
}
