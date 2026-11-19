<?php
declare(strict_types=1);

require_once __DIR__.'/../model/AuditTrail.php';
require_once __DIR__.'/../model/DisbursementRegularizationManager.php';

final class SupplierDisbursementUnblockService
{
    public function __construct(private PDO $pdo)
    {
        // Toutes les migrations sont exécutées avant les transactions de bypass.
        new DisbursementRegularizationManager($pdo, false);
        AuditTrail::ensureSchema($pdo);
        AuditTrail::runMaintenance($pdo);
    }

    public function unblock(int $supplierId,int $userId,string $userName,string $reason):array
    {
        if($supplierId<=0)throw new DomainException('Fournisseur invalide.');
        $reason=trim($reason);
        if($userId<=0||trim($userName)==='')throw new DomainException('L’auteur du bypass ne peut pas être identifié.');
        if(mb_strlen($reason)<8)throw new DomainException('Indiquez un motif de bypass suffisamment précis (8 caractères minimum).');
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $supplier=$this->pdo->prepare("SELECT id_fournisseur,COALESCE(NULLIF(TRIM(nom_commercial),''),NULLIF(TRIM(raison_sociale),''),CONCAT('Fournisseur #',id_fournisseur)) label,disbursement_selection_blocked,disbursement_block_reason,disbursement_block_fiches FROM fournisseur_banamur WHERE id_fournisseur=:id FOR UPDATE");
            $supplier->execute(['id'=>$supplierId]);$row=$supplier->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new DomainException('Fournisseur introuvable.');
            $cancel=$this->pdo->prepare("UPDATE disbursement_regularizations r
                JOIN fiche_decaissement f ON BINARY f.num_fiche=BINARY r.num_fiche
                SET r.cancelled_at=NOW(),r.cancelled_by=:cancel_user,r.cancelled_by_name=:cancel_name,
                    r.cancellation_reason=CONCAT('Régularisation sans preuve par bypass : ',:cancellation_reason),
                    r.bypassed_without_proof_at=NOW(),r.bypassed_without_proof_by=:bypass_user,r.bypassed_without_proof_by_name=:bypass_name,r.bypass_reason=:bypass_reason,
                    r.access_blocked_at=NULL,r.access_blocked_by=NULL,r.access_blocked_by_name=NULL,r.access_block_reason=NULL,
                    r.accounting_status='approved',r.submitted_at=COALESCE(r.submitted_at,NOW()),
                    r.accounting_validated_by=:validator_user,r.accounting_validator_name=:validator_name,r.accounting_validated_at=NOW(),
                    r.accounting_note=CONCAT('RÉGULARISÉ SANS PREUVE — bypass par ',:note_name,' — ',:note_reason)
                WHERE f.fournisseur_id=:supplier AND r.cancelled_at IS NULL AND (COALESCE(r.payment_proof_path,'')='' OR COALESCE(f.facture_reference,'')='')");
            $cancel->execute(['cancel_user'=>$userId,'cancel_name'=>$userName,'cancellation_reason'=>$reason,'bypass_user'=>$userId,'bypass_name'=>$userName,'bypass_reason'=>$reason,'validator_user'=>$userId,'validator_name'=>$userName,'note_name'=>$userName,'note_reason'=>$reason,'supplier'=>$supplierId]);$cancelled=$cancel->rowCount();
            $update=$this->pdo->prepare('UPDATE fournisseur_banamur SET disbursement_selection_blocked=0,disbursement_block_reason=NULL,disbursement_block_fiches=NULL WHERE id_fournisseur=:id');
            $update->execute(['id'=>$supplierId]);
            AuditTrail::log('supplier_regularized_without_proof',['module'=>'finance','action'=>'supplier_regularization_bypass','entity_type'=>'supplier','entity_id'=>(string)$supplierId,'entity_label'=>(string)$row['label'],'user_id'=>$userId,'user_name'=>$userName,'severity'=>'warning','comment'=>$reason,'details'=>['bypassed_by'=>$userName,'bypassed_regularizations'=>$cancelled,'previous_reason'=>$row['disbursement_block_reason']??null,'previous_fiches'=>$row['disbursement_block_fiches']??null]],$this->pdo);
            if($ownsTransaction)$this->pdo->commit();
            return ['supplier_id'=>$supplierId,'supplier'=>(string)$row['label'],'cancelled_regularizations'=>$cancelled];
        }catch(Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /** Validation explicite du fournisseur, avec pièce facultative et bypass des pièces restantes. */
    public function regularizeSupplier(string $numFiche, int $userId, string $userName, string $reason, ?array $file = null, string $documentType = 'payment_proof'): array
    {
        if (trim($numFiche) === '' || $userId <= 0 || trim($userName) === '' || mb_strlen(trim($reason)) < 8) {
            throw new DomainException('Fiche, auteur et motif précis de régularisation requis.');
        }
        $manager = new DisbursementRegularizationManager($this->pdo, $file !== null);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT fournisseur_id FROM fiche_decaissement WHERE BINARY num_fiche=BINARY :num');
            $stmt->execute(['num'=>trim($numFiche)]);
            $supplierId = (int)$stmt->fetchColumn();
            if ($supplierId <= 0) throw new DomainException('Fournisseur de la fiche introuvable.');
            $lock = $this->pdo->prepare('SELECT id_fournisseur FROM fournisseur_banamur WHERE id_fournisseur=:id FOR UPDATE');
            $lock->execute(['id'=>$supplierId]);
            if (!$lock->fetchColumn()) throw new DomainException('Fournisseur introuvable.');
            $active = $this->pdo->prepare('SELECT id FROM disbursement_regularizations WHERE BINARY num_fiche=BINARY :num AND cancelled_at IS NULL FOR UPDATE');
            $active->execute(['num'=>trim($numFiche)]);
            if (!$active->fetchColumn()) throw new DomainException('Régularisation active introuvable.');
            if ($file !== null) {
                $manager->attachPaymentProof($numFiche, $userId, $userName, $file, $documentType);
                // La pièce peut compléter le dossier : valider aussi ce cas sans le marquer sans preuve.
                $approve = $this->pdo->prepare("UPDATE disbursement_regularizations SET accounting_status='approved',
                    submitted_at=COALESCE(submitted_at,NOW()),accounting_validated_at=NOW(),
                    accounting_validated_by=:uid,accounting_validator_name=:name,
                    access_blocked_at=NULL,access_blocked_by=NULL,access_blocked_by_name=NULL,access_block_reason=NULL
                    WHERE BINARY num_fiche=BINARY :num AND cancelled_at IS NULL");
                $approve->execute(['uid'=>$userId,'name'=>$userName,'num'=>trim($numFiche)]);
            }
            $result = $this->unblock($supplierId, $userId, $userName, $reason);
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function bypassOne(string $numFiche,int $userId,string $userName,string $reason):array
    {
        $numFiche=trim($numFiche);$reason=trim($reason);
        if($numFiche===''||$userId<=0||trim($userName)==='')throw new DomainException('Fiche ou auteur du bypass invalide.');
        if(mb_strlen($reason)<8)throw new DomainException('Le motif doit contenir au moins 8 caractères.');
        $this->pdo->beginTransaction();
        try{
            $stmt=$this->pdo->prepare("SELECT r.id,f.fournisseur_id FROM disbursement_regularizations r JOIN fiche_decaissement f ON BINARY f.num_fiche=BINARY r.num_fiche WHERE BINARY r.num_fiche=BINARY :num AND r.cancelled_at IS NULL FOR UPDATE");
            $stmt->execute(['num'=>$numFiche]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$row)throw new DomainException('Régularisation active introuvable.');
            $update=$this->pdo->prepare("UPDATE disbursement_regularizations SET cancelled_at=NOW(),cancelled_by=:uid,cancelled_by_name=:uname,
                cancellation_reason=CONCAT('Régularisation sans preuve par bypass : ',:reason1),bypassed_without_proof_at=NOW(),
                bypassed_without_proof_by=:buid,bypassed_without_proof_by_name=:bname,bypass_reason=:reason2,
                access_blocked_at=NULL,access_blocked_by=NULL,access_blocked_by_name=NULL,access_block_reason=NULL,
                accounting_status='approved',submitted_at=COALESCE(submitted_at,NOW()),accounting_validated_by=:vuid,
                accounting_validator_name=:vname,accounting_validated_at=NOW(),accounting_note=CONCAT('RÉGULARISÉ SANS PREUVE — bypass par ',:nname,' — ',:nreason) WHERE id=:id");
            $update->execute(['uid'=>$userId,'uname'=>$userName,'reason1'=>$reason,'buid'=>$userId,'bname'=>$userName,'reason2'=>$reason,'vuid'=>$userId,'vname'=>$userName,'nname'=>$userName,'nreason'=>$reason,'id'=>(int)$row['id']]);
            $remaining=$this->pdo->prepare("SELECT COUNT(*) FROM disbursement_regularizations r JOIN fiche_decaissement f ON BINARY f.num_fiche=BINARY r.num_fiche WHERE f.fournisseur_id=:supplier AND r.cancelled_at IS NULL AND (COALESCE(r.payment_proof_path,'')='' OR COALESCE(f.facture_reference,'')='')");$remaining->execute(['supplier'=>(int)$row['fournisseur_id']]);$left=(int)$remaining->fetchColumn();
            if($left===0)$this->pdo->prepare('UPDATE fournisseur_banamur SET disbursement_selection_blocked=0,disbursement_block_reason=NULL,disbursement_block_fiches=NULL WHERE id_fournisseur=:id')->execute(['id'=>(int)$row['fournisseur_id']]);
            AuditTrail::log('supplier_regularization_bypassed_individually',['module'=>'finance','action'=>'regularization_bypass_one','entity_type'=>'fiche_decaissement','entity_id'=>$numFiche,'entity_label'=>$numFiche,'user_id'=>$userId,'user_name'=>$userName,'severity'=>'warning','comment'=>$reason,'details'=>['supplier_id'=>(int)$row['fournisseur_id'],'remaining_blocking_regularizations'=>$left]],$this->pdo);
            $this->pdo->commit();return ['num_fiche'=>$numFiche,'supplier_id'=>(int)$row['fournisseur_id'],'remaining'=>$left];
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

}
