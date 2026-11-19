<?php
declare(strict_types=1);
require_once __DIR__.'/FebBeneficiarySyncService.php';

/** Save the explicit recipient without approving the FEB or its supplier. */
final class FebAssignmentBeneficiary
{
    public static function supplierId(array $payload):int
    {
        $draft=(array)($payload['provisional_decaissement']??[]);
        if(isset($draft['beneficiary_mode'])&&in_array($draft['beneficiary_mode'],['personnel','external'],true))return 0;
        if(array_key_exists('fournisseur_id',$draft))return max(0,(int)$draft['fournisseur_id']);
        foreach([$payload['global_fournisseur_id']??0,$payload['fournisseur_id']??0,$payload['besoins'][0]['fournisseur']['id']??$payload['besoins'][0]['fournisseur']['id_fournisseur']??0]as$id)
            if((int)$id>0)return (int)$id;
        return 0;
    }
    public static function assertBudget(PDO $pdo,array $draft):void
    {
        $ids=[];foreach(['chantier_id','ligne_devis_id','debourse_id','ligne_debourse_id']as$key){
            $ids[$key]=(int)($draft[$key]??0);
            if($ids[$key]<=0)throw new DomainException('Complétez le chantier et les lignes budgétaires de la nouvelle affectation.');
        }
        require_once __DIR__.'/FinancialPlanningSeparationService.php';
        $official=FinancialPlanningSeparationService::officialDeboursePredicate('db');
        $q=$pdo->prepare("SELECT c.id FROM chantier_banamur c
            JOIN ligne_devis_banamur ld ON ld.id=:ligne_devis_id
            JOIN debourse_banamur db ON db.id=:debourse_id AND db.ligne_devis_id=ld.id AND $official
            JOIN ligne_debourse_banamur ldb ON ldb.id=:ligne_debourse_id AND ldb.debourse_id=db.id
            WHERE c.id=:chantier_id AND c.archived_at IS NULL AND (c.devis_id=db.devis_id OR EXISTS(
                SELECT 1 FROM devis_banamur cd JOIN devis_banamur td ON td.id=db.devis_id
                WHERE cd.id=c.devis_id AND cd.offre_id IS NOT NULL AND cd.offre_id=td.offre_id)) LIMIT 1");
        $q->execute($ids);
        if(!$q->fetchColumn())throw new DomainException('Affectation budgétaire incohérente. Vérifiez le chantier et les lignes sélectionnées.');
    }
    public static function apply(PDO $pdo,array $payload,array $post):array
    {
        if(!array_key_exists('fd_beneficiary_mode',$post))return $payload;
        $mode=trim((string)$post['fd_beneficiary_mode']);
        if(!in_array($mode,['supplier','personnel','external'],true))throw new DomainException('Type de bénéficiaire invalide.');
        $draft=(array)($payload['provisional_decaissement']??[]);
        $draft['beneficiary_mode']=$mode;
        $draft['fournisseur_id']=$mode==='supplier'?(int)($post['fd_fournisseur_id']??0):0;
        $draft['personnel_id']=$mode==='personnel'?(int)($post['fd_personnel_id']??0):0;
        if($mode==='supplier'&&$draft['fournisseur_id']<=0)throw new DomainException('Sélectionnez un fournisseur.');
        if($mode==='personnel'&&$draft['personnel_id']<=0)throw new DomainException('Sélectionnez un membre du personnel.');
        if($mode==='external'){
            $draft['beneficiaire_fiche']=trim((string)($post['fd_beneficiaire_fiche']??$draft['beneficiaire_fiche']??''));
            $draft['tel_beneficiaire_fiche']=trim((string)($post['fd_tel_beneficiaire_fiche']??$draft['tel_beneficiaire_fiche']??''));
        }
        $candidate=$payload;$candidate['provisional_decaissement']=$draft;
        $selected=FebBeneficiarySyncService::selected($pdo,$candidate);
        if(!$selected)throw new DomainException('Bénéficiaire introuvable. Sélectionnez une fiche existante.');
        $draft['beneficiaire_fiche']=$selected['name'];
        $draft['tel_beneficiaire_fiche']=$selected['phone'];
        $draft['beneficiary_choice']=$mode==='supplier'?'supplier':($mode==='personnel'?'requester':'external');
        $payload['provisional_decaissement']=$draft;
        return $payload;
    }
}
