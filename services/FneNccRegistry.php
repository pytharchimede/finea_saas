<?php
declare(strict_types=1);

final class FneNccRegistry
{
    public const ENDPOINT='https://www.services.fne.dgi.gouv.ci/ws/ncc-verification';
    public function __construct(private mixed $transport=null) {}
    public static function normalize(string $ncc): string
    {
        $ncc=strtoupper(preg_replace('/[\s\-]+/u','',trim($ncc)));
        if (!preg_match('/^[A-Z0-9]{4,20}$/D',$ncc)) throw new DomainException('Renseignez un NCC valide (lettres et chiffres).');
        return $ncc;
    }
    public function lookup(string $ncc): array
    {
        $ncc=self::normalize($ncc);
        if ($this->transport) {
            [$status,$raw]=($this->transport)($ncc);
        } else {
            $ch=curl_init(self::ENDPOINT.'?'.http_build_query(['ncc'=>$ncc]));
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_HTTPHEADER=>['Accept: application/json','Cache-Control: no-cache','User-Agent: Banamur-NCC/1.0']]);
            $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        }
        // Le registre répond 404 pour une société absente/non inscrite : ce n'est pas une panne.
        if ($status === 404 && is_string($raw)) {
            $error = json_decode($raw, true);
            if (($error['error'] ?? '') === 'company_not_registered') throw new DomainException('Ce NCC correspond à une entreprise non enregistrée sur la plateforme FNE. Vérifiez le NCC et l’inscription FNE auprès du fournisseur.');
            if (($error['error'] ?? '') === 'company_not_found') throw new DomainException('Aucune entreprise trouvée pour ce NCC dans la FNE. Vérifiez les lettres et chiffres auprès du fournisseur.');
        }
        if ($status === 429) throw new RuntimeException('La FNE reçoit trop de demandes. Patientez un instant avant de réessayer.');
        // No conditional request: 304 without a body cannot verify an identity.
        if ($status!==200 || !is_string($raw)) throw new RuntimeException('Le registre FNE est indisponible. Réessayez ; aucune identité non vérifiée ne sera enregistrée.');
        try {$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);} catch (JsonException $e) {throw new RuntimeException('Réponse FNE illisible. Réessayez ultérieurement.');}
        if (!is_array($data) || ($data['status']??null)!==true) throw new DomainException('Ce NCC n’est pas reconnu comme valide par la FNE.');
        if (self::normalize((string)($data['ncc']??''))!==$ncc || !is_string($data['name']??null) || trim($data['name'])==='' || mb_strlen($data['name'])>255) throw new RuntimeException('L’identité retournée par la FNE ne correspond pas au NCC demandé.');
        $result=['ncc'=>$ncc,'name'=>trim($data['name']),'status'=>true];
        foreach (['taxRegime','statusActivity','statusActivityLabel'] as $key) {
            if (isset($data[$key]) && (!is_string($data[$key]) || mb_strlen($data[$key])>100)) throw new RuntimeException('Données FNE invalides.');
            $result[$key]=$data[$key]??null;
        }
        foreach (['registeredAt','firstRegistrationDate'] as $key) {
            $value=$data[$key]??null;
            if ($value!==null) {
                if (!is_string($value)) throw new RuntimeException('Date FNE invalide.');
                try {new DateTimeImmutable($value);} catch (Throwable $e) {throw new RuntimeException('Date FNE invalide.');}
            }
            $result[$key]=$value;
        }
        return $result;
    }
}
