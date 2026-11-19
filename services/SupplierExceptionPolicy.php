<?php
declare(strict_types=1);

/** A supplier access permission alone never grants the right to waive controls. */
final class SupplierExceptionPolicy
{
    public static function allowed(): bool
    {
        if ((int)($_SESSION['user_id']??0)<=0 || !class_exists('AccessManager')) return false;
        if (AccessManager::hasPermission('acces_admin') || AccessManager::hasPermission('acces_compta')) return true;
        $role=mb_strtolower(trim((string)($_SESSION['role_norm']??$_SESSION['role_raw']??'')),'UTF-8');
        return in_array($role,['dg','directeur general','directeur général'],true);
    }
    public static function requireAllowed(?int $actor=null): void
    {
        if (!self::allowed() || ($actor!==null && $actor!==(int)($_SESSION['user_id']??0))) {
            throw new DomainException('Les fournisseurs dérogatoires sont réservés à la comptabilité, au DG et aux administrateurs.');
        }
    }
    public static function isException(array $row): bool
    {
        return ($row['identity_method']??'')==='exception';
    }
    public static function apply(array $input, string $type, array $current=[]): array
    {
        self::requireAllowed();
        $token=$input['_supplier_exception_csrf']??null;
        if (!is_string($token) || empty($_SESSION['supplier_exception_csrf']) || !hash_equals((string)$_SESSION['supplier_exception_csrf'],$token)) {
            throw new DomainException('Session expirée pour la dérogation. Rechargez le formulaire.');
        }
        $name=trim((string)($input['nom_commercial']??$current['nom_commercial']??''));
        if ($type==='physique' && $name==='') $name=trim((string)($input['person_last_name']??'').' '.(string)($input['person_first_name']??''));
        $reason=trim((string)($input['exception_reason']??$input['identity_evidence']??''));
        if ($name==='' || mb_strlen($name)>255) throw new DomainException('Renseignez le nom du fournisseur (255 caractères maximum).');
        if (mb_strlen($reason)<10 || mb_strlen($reason)>500) throw new DomainException('Expliquez le motif de dérogation (10 à 500 caractères).');
        foreach (['ncc','ncc_identity','rne','siret','siren','vat_number','registration_number','registration_type','identity_key','dgi_verified_at','dgi_response_json','registry_verified_at','registry_response_json'] as $field) $input[$field]=null;
        $input['ncc']=$input['rne']='';
        $input['is_rne']=0;
        $input['nom_commercial']=$name;
        $input['raison_sociale']=trim((string)($input['raison_sociale']??$current['raison_sociale']??'')) ?: ($type==='physique'?'Personne physique':'Non précisée');
        $input['identity_method']='exception'; $input['identity_status']='exception_approved';
        $input['vat_status']='unchecked'; $input['identity_evidence']=$reason;
        $input['identity_checked_by']=(int)$_SESSION['user_id'];
        $input['identity_checked_at']=gmdate('Y-m-d H:i:s');
        $input['identity_snapshot']=json_encode(['mode'=>'exception','name'=>$name,'reason'=>$reason,'country'=>$input['fiscal_country']??'CI','actor'=>$input['identity_checked_by']],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        return $input;
    }
}
