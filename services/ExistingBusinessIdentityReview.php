<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierIdentity.php';
require_once __DIR__.'/ClientBusinessPolicy.php';

/** Produces proposals only. Never guesses a country, verifies an identity or merges rows. */
final class ExistingBusinessIdentityReview
{
    public static function review(array $row, bool $supplier): array
    {
        $reasons=[];
        $country=(string)($row['fiscal_country']??'');
        if (!in_array(strtoupper(trim($country)),explode(' ',InternationalBusinessIdentity::COUNTRIES),true)) $reasons[]='pays_fiscal_a_confirmer';
        if ($country!==strtoupper(trim($country))) $reasons[]='format_pays_a_regulariser';
        $type=$row[$supplier?'supplier_type':'client_type']??'';
        if (!in_array($type,['morale','physique'],true)) $reasons[]='type_a_confirmer';
        if ($supplier) {
            $reasons=array_merge($reasons,SupplierIdentity::missing($row));
            if (($row['validation_status']??'')!=='approved') $reasons[]='validation_interne_en_attente';
        } elseif (!ClientBusinessPolicy::usable($row)) $reasons[]='identite_client_a_regulariser';
        if (($country!=='CI'||$type==='physique') && trim((string)($row['ncc']??''))!=='') $reasons[]='ncc_hors_profil_a_examiner';
        $name=(string)($row[$supplier?'nom_commercial':'nom_client']??'');
        if ($name!==trim($name)) $reasons[]='espaces_denomination';
        return array_values(array_unique($reasons));
    }

    public static function duplicateKeys(array $row): array
    {
        $keys=[];$country=strtoupper(trim((string)($row['fiscal_country']??'')));
        foreach (['identity_key','ncc','siret','identity_document_number'] as $field) {
            $value=strtoupper(preg_replace('/[^A-Za-z0-9]/','',(string)($row[$field]??''))??'');
            if ($value==='') continue;
            // Tax identifiers are scoped to their jurisdiction; PERSON keys stay internal.
            if ($field==='ncc' && $country!=='CI') continue;
            if ($field==='siret' && $country!=='FR') continue;
            $keys[]=$field.':'.$country.':'.$value;
        }
        return $keys;
    }
}
