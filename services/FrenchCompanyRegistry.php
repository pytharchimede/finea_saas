<?php
declare(strict_types=1);

/** Public DINUM data. No API key; an identity match is not a VAT validation. */
final class FrenchCompanyRegistry
{
    public const ENDPOINT = 'https://recherche-entreprises.api.gouv.fr/search';
    public function __construct(private mixed $transport = null) {}

    public static function normalize(string $value): string
    {
        $value = preg_replace('/\s+/u', '', trim($value));
        if (!preg_match('/^(?:[0-9]{9}|[0-9]{14})$/D', $value)) {
            throw new DomainException('Saisissez un SIREN de 9 chiffres ou un SIRET de 14 chiffres.');
        }
        return $value;
    }

    public function lookup(string $value): array
    {
        $value = self::normalize($value);
        if ($this->transport) {
            [$status, $raw] = ($this->transport)($value);
        } else {
            $ch = curl_init(self::ENDPOINT . '?' . http_build_query(['q'=>$value, 'per_page'=>1]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_TIMEOUT=>15, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPHEADER=>['Accept: application/json', 'User-Agent: Finea-Suppliers/1.0']]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        if ($status === 429) throw new RuntimeException('Le registre français est sollicité. Patientez avant de réessayer.');
        if ($status !== 200 || !is_string($raw)) throw new RuntimeException('Registre français indisponible. Réessayez ultérieurement.');
        try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new RuntimeException('Réponse du registre français illisible.'); }
        $company = $data['results'][0] ?? null;
        if (!is_array($company)) throw new DomainException('Entreprise introuvable ou non diffusible dans le registre public.');
        $siren = (string)($company['siren'] ?? '');
        if ($siren !== substr($value, 0, 9)) throw new RuntimeException('Le registre retourne une autre entreprise.');
        $establishment = null;
        foreach (array_merge([$company['siege'] ?? []], $company['matching_etablissements'] ?? []) as $candidate) {
            if (is_array($candidate) && (strlen($value) === 9 || ($candidate['siret'] ?? '') === $value)) {
                $establishment = $candidate;
                break;
            }
        }
        if (!$establishment) throw new DomainException('Le SIRET demandé ne figure pas dans les établissements retournés.');
        if (($company['etat_administratif'] ?? '') !== 'A' || ($establishment['etat_administratif'] ?? '') !== 'A') {
            throw new DomainException('Cette entreprise ou cet établissement est fermé. Utilisez un établissement actif.');
        }
        $name = trim((string)($company['nom_raison_sociale'] ?? $company['nom_complet'] ?? ''));
        $siret = (string)($establishment['siret'] ?? '');
        if ($name === '' || strlen($name) > 1020 || !preg_match('/^[0-9]{14}$/D', $siret) || substr($siret, 0, 9) !== $siren) {
            throw new RuntimeException('Identité française incomplète.');
        }
        return ['name'=>$name, 'siren'=>$siren, 'siret'=>$siret,
            'address'=>trim((string)($establishment['adresse'] ?? '')),
            'city'=>trim((string)($establishment['libelle_commune'] ?? '')),
            'postal_code'=>(string)($establishment['code_postal'] ?? ''),
            'legal_form'=>(string)($company['nature_juridique'] ?? ''),
            'activity'=>(string)($establishment['activite_principale'] ?? $company['activite_principale'] ?? ''),
            'status'=>'A', 'source'=>self::ENDPOINT];
    }
}
