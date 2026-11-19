<?php
declare(strict_types=1);
require_once __DIR__.'/SupplierIdentity.php';
require_once __DIR__.'/PersonIdentityGuard.php';
require_once __DIR__.'/FinancialWorkflowProgress.php';

/** Read-only, fail-closed eligibility check; never constructs a schema-writing repository. */
final class SupplierPaymentGuard
{
    public static function missing(PDO $pdo,array $row,?string $root=null,bool $lock=false):array
    {
        $missing=SupplierIdentity::missing($row);
        try{InternationalBusinessIdentity::country((string)($row['fiscal_country']??''));}catch(DomainException $e){$missing[]='pays fiscal à confirmer';}
        if(!in_array($row['supplier_type']??'',['morale','physique'],true))$missing[]='type fournisseur à régulariser';
        if(!empty($row['archived_at']))$missing[]='fournisseur archivé';
        if((int)($row['actif']??0)!==1)$missing[]='fournisseur inactif';
        if(($row['validation_status']??'')!=='approved')$missing[]='validation fournisseur requise';
        if(($row['supplier_type']??'')==='physique'){
            try{
                $number=PersonIdentityGuard::normalize((string)($row['identity_document_number']??''));
                if(PersonIdentityGuard::employeeExists($pdo,$number,$lock))$missing[]=PersonIdentityGuard::EMPLOYEE_MESSAGE;
                $q=$pdo->prepare("SELECT id_fournisseur FROM fournisseur_banamur WHERE supplier_type='physique' AND REGEXP_REPLACE(UPPER(COALESCE(identity_document_number,'')),'[^A-Z0-9]','')=? AND id_fournisseur<>? LIMIT 1");
                $q->execute([$number,(int)($row['id_fournisseur']??0)]);
                if($q->fetchColumn())$missing[]='numéro de pièce utilisé sur plusieurs fiches fournisseurs';
            }catch(DomainException $e){$missing[]=$e->getMessage();}
            if(!self::attachedFile((string)($row['cni_path']??''),$root))$missing[]='pièce d’identité absente ou inaccessible';
            if(!self::attachedFile((string)($row['photo_path']??''),$root))$missing[]='photo fournisseur absente ou inaccessible';
        }elseif(!SupplierExceptionPolicy::isException($row)){
            if(!self::attachedFile((string)($row['logo_path']??''),$root))$missing[]='logo fournisseur absent ou inaccessible';
            $field=($row['fiscal_country']??'CI')==='CI'?'ncc':(($row['fiscal_country']??'')==='FR'&&!empty($row['siret'])?'siret':'identity_key');
            $value=preg_replace('/[^A-Z0-9]/','',strtoupper((string)($row[$field]??'')));
            if($value!==''){
                $q=$pdo->prepare("SELECT id_fournisseur FROM fournisseur_banamur WHERE REGEXP_REPLACE(UPPER(COALESCE($field,'')),'[^A-Z0-9]','')=? AND fiscal_country=? AND id_fournisseur<>? LIMIT 1");
                $q->execute([$value,$row['fiscal_country']??'CI',(int)($row['id_fournisseur']??0)]);
                if($q->fetchColumn())$missing[]='identifiant fiscal présent sur plusieurs fiches fournisseurs';
            }
        }
        return array_values(array_unique($missing));
    }

    public static function attachedFile(string $path,?string $root=null):bool
    {
        $base=realpath($root??dirname(__DIR__));
        $file=$path!==''&&$base?realpath($base.'/'.ltrim(str_replace('\\','/',$path),'/')):false;
        return $base&&$file&&str_starts_with($file,$base.'/')&&is_file($file)&&is_readable($file)&&filesize($file)>0;
    }

    public static function assertFiche(PDO $pdo,array $fiche,?string $root=null):void
    {
        FinancialWorkflowProgress::step('Contrôle du dossier fournisseur, des justificatifs et du rapprochement RH.');
        if((int)($fiche['decaisse']??0)===1)return; // Historical payments remain idempotent.
        // A signed RH financial FEB follows its signed snapshot, even if an old
        // supplier link remains attached to the FEB. Never bypass the RH checks.
        if((int)($fiche['feb_request_id']??0)>0){
            require_once __DIR__.'/RhFinancialSettlementService.php';
            if(RhFinancialSettlementService::isRhFiche($pdo,$fiche)){
                RhFinancialSettlementService::assertFiche($pdo,$fiche);
                return;
            }
        }
        // Administrative supplier compliance is an upstream gate: it is checked
        // when the FEB enters a daily point. After DG certification, payment
        // relies on the signed snapshot rather than the supplier's live dossier.
        // Never waive the check without an approved FEB and an authentic DG
        // signature, and never waive beneficiary/amount/snapshot integrity.
        $ficheNumber=trim((string)($fiche['num_fiche']??''));
        $requestId=(int)($fiche['feb_request_id']??0);
        if($ficheNumber!==''&&$requestId>0){
            $approved=$pdo->prepare("SELECT 1 FROM employee_legal_requests WHERE id=? AND request_type='feb' AND status='approved' LIMIT 1");
            $approved->execute([$requestId]);
            if($approved->fetchColumn()){
                $signed=$pdo->prepare("SELECT i.*,p.status point_status,p.certified_at, EXISTS(SELECT 1 FROM daily_disbursement_point_signatures s WHERE s.point_id=p.id AND s.role_code='dg' AND COALESCE(s.signature_path,'')<>'') dg_signed FROM daily_disbursement_point_items i JOIN daily_disbursement_points p ON p.id=i.point_id WHERE i.fiche_number=? AND p.status='certified' ORDER BY p.id DESC LIMIT 1");
                $signed->execute([$ficheNumber]);
                $item=$signed->fetch(PDO::FETCH_ASSOC);
                if($item){
                    require_once __DIR__.'/DailyPointPaymentPolicy.php';
                    DailyPointPaymentPolicy::assertItem($item,$fiche,(float)$item['amount']);
                    return;
                }
            }
        }
        $id=(int)($fiche['fournisseur_id']??0);
        if($id<=0){
            $parent=trim((string)(($fiche['parent_num_fiche']??'')?:($fiche['code_parentale']??'')));
            if($parent!==''){
                $q=$pdo->prepare('SELECT fournisseur_id FROM fiche_decaissement WHERE num_fiche=? LIMIT 1');$q->execute([$parent]);$id=(int)$q->fetchColumn();
            }
        }
        if($id<=0){
            // Signed RH requests are checked against their signed snapshot by the caller.
            if((int)($fiche['feb_request_id']??0)>0){
                require_once __DIR__.'/RhFinancialSettlementService.php';
                if(RhFinancialSettlementService::isRhFiche($pdo,$fiche)){RhFinancialSettlementService::assertFiche($pdo,$fiche);return;}
            }
            if(trim((string)($fiche['fournisseur_nom']??''))!==''||trim((string)($fiche['code_autorisation_feb']??''))!==''||(int)($fiche['contract_id']??0)>0||(int)($fiche['feb_request_id']??0)>0)throw new DomainException('Décaissement bloqué : rattachez cette dépense à une fiche fournisseur régularisée.');
            if((int)($fiche['personnel_id']??0)>0){
                $q=$pdo->prepare('SELECT 1 FROM personnel WHERE id_personnel_soignant=? LIMIT 1');$q->execute([(int)$fiche['personnel_id']]);
                if($q->fetchColumn())return; // Identified personnel circuit, not a supplier purchase.
            }
            throw new DomainException('Décaissement bloqué : bénéficiaire sans rattachement vérifiable. Régularisez la fiche fournisseur ou le circuit personnel avant paiement.');
        }
        $q=$pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur=? LIMIT 1'.($pdo->inTransaction()?' FOR UPDATE':''));$q->execute([$id]);$supplier=$q->fetch(PDO::FETCH_ASSOC);
        if(!$supplier)throw new DomainException('Décaissement bloqué : fiche fournisseur introuvable (référence '.$id.').');
        $missing=self::missing($pdo,$supplier,$root,$pdo->inTransaction());
        if($missing){
            $name=trim((string)($supplier['nom_commercial']??''));
            if($name==='')$name=trim((string)($supplier['raison_sociale']??''));
            if($name==='')$name=trim((string)($fiche['fournisseur_nom']??''));
            if($name==='')$name='Fournisseur sans nom renseigné';
            throw new DomainException('Décaissement bloqué — '.$name.' : '.implode(' ; ',$missing).'. Régularisez sa fiche avant paiement.');
        }
    }
}

