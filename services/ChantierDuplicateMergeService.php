<?php
declare(strict_types=1);
require_once __DIR__.'/DevisFinancialTransferService.php';
require_once __DIR__.'/CommercialProjectFinanceMigrationService.php';
require_once __DIR__.'/DevisFinancialTransferMigrationService.php';
require_once __DIR__.'/../repositories/CommercialProjectFinanceRepository.php';
require_once __DIR__.'/../repositories/DevisOfferProjectRepository.php';

final class ChantierDuplicateMergeService
{
    public function __construct(private PDO $pdo){$this->ensureSchema();}
    public function preview(string $sourceCode,string $targetCode,string $sourceQuote,string $targetQuote):array
    {
        $siteSql="SELECT c.id,c.code,c.titre,c.client_id,c.devis_id,d.numero_devis AS current_numero_devis,d.total_ttc AS current_total_ttc FROM chantier_banamur c LEFT JOIN devis_banamur d ON d.id=c.devis_id WHERE UPPER(TRIM(c.code))=UPPER(TRIM(:code)) AND c.archived_at IS NULL LIMIT 1";
        $q=$this->pdo->prepare($siteSql);$q->execute(['code'=>$sourceCode]);$source=$q->fetch(PDO::FETCH_ASSOC);$q->execute(['code'=>$targetCode]);$target=$q->fetch(PDO::FETCH_ASSOC);
        $quoteSql='SELECT id,numero_devis,client_id,offre_id,total_ttc FROM devis_banamur WHERE UPPER(TRIM(numero_devis))=UPPER(TRIM(:number)) LIMIT 1';
        $quoteQuery=$this->pdo->prepare($quoteSql);$quoteQuery->execute(['number'=>$sourceQuote]);$sourceQuoteRow=$quoteQuery->fetch(PDO::FETCH_ASSOC);$quoteQuery->execute(['number'=>$targetQuote]);$targetQuoteRow=$quoteQuery->fetch(PDO::FETCH_ASSOC);
        if(!$source||!$target||!$sourceQuoteRow||!$targetQuoteRow)throw new DomainException('Un chantier ou un devis sélectionné est introuvable sur cette base.');
        if((int)$source['id']===(int)$target['id']||(int)$sourceQuoteRow['id']===(int)$targetQuoteRow['id'])throw new DomainException('Sélectionnez deux chantiers et deux devis différents.');
        if((int)$source['client_id']!==(int)$target['client_id'])throw new DomainException('Les deux chantiers ne concernent pas le même client.');
        if((int)$sourceQuoteRow['offre_id']!==(int)$targetQuoteRow['offre_id'])throw new DomainException('Les deux devis ne concernent pas le même projet.');
        if($this->tableExists('facture_vente_banamur')){
            $invoice=$this->pdo->prepare('SELECT COUNT(*) FROM facture_vente_banamur WHERE devis_id=:id');
            $invoice->execute(['id'=>(int)$sourceQuoteRow['id']]);
            if((int)$invoice->fetchColumn()>0)throw new DomainException('Ce devis possède des factures. Régularisez leur rattachement avant de fusionner les chantiers.');
        }
        if((int)$target['devis_id']!==(int)$targetQuoteRow['id'])throw new DomainException('Le chantier cible n’est pas rattaché au devis à conserver.');
        $financialTransferDone=(int)$source['devis_id']===(int)$targetQuoteRow['id'];
        if(!$financialTransferDone&&(int)$source['devis_id']!==(int)$sourceQuoteRow['id'])throw new DomainException('Le chantier source est rattaché à un devis inattendu.');
        $source['numero_devis']=$sourceQuoteRow['numero_devis'];$source['total_ttc']=$sourceQuoteRow['total_ttc'];$source['source_devis_id']=$sourceQuoteRow['id'];
        $target['numero_devis']=$targetQuoteRow['numero_devis'];$target['total_ttc']=$targetQuoteRow['total_ttc'];$target['target_devis_id']=$targetQuoteRow['id'];
        $tables=$this->chantierTables();$counts=[];foreach($tables as $table){if(in_array($table,['chantier_banamur','chantier_archive_scopes'],true))continue;$s=$this->pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE chantier_id=:id");$s->execute(['id'=>$source['id']]);$n=(int)$s->fetchColumn();if($n)$counts[$table]=$n;}
        $details=(new DevisFinancialTransferService($this->pdo))->describe((int)$sourceQuoteRow['id']);
        $linkedFiches=$this->linkedFicheIds((int)$sourceQuoteRow['id']);
        $targetDetails=(new DevisFinancialTransferService($this->pdo))->describe((int)$targetQuoteRow['id']);
        return compact('source','target','counts','financialTransferDone')+['quote_lines'=>count($details['lines']),'debourses'=>count($details['debourses']),'linked_fiches'=>count($linkedFiches),'source_lines'=>$details['lines'],'target_lines'=>$targetDetails['lines']];
    }
    public function execute(int $userId,string $confirmation):array
    {
        if(trim($confirmation)!=='FUSIONNER CH0063 VERS CH0064')throw new DomainException('La phrase de confirmation est incorrecte.');
        return $this->executePair('CH0063','CH0064','BAN-DEV-PAB-79','BAN-DEV-PAB-81',$userId);
    }
    public function executePair(string $sourceCode,string $targetCode,string $sourceQuote,string $targetQuote,int $userId,array $lineMap=[],array $debourseMap=[]):array
    {
        $preview=$this->preview($sourceCode,$targetCode,$sourceQuote,$targetQuote);$source=$preview['source'];$target=$preview['target'];
        ActiveChantierEditPermissionService::assertCanEdit($this->pdo, (int) $source['source_devis_id']);
        ActiveChantierEditPermissionService::assertCanEdit($this->pdo, (int) $target['target_devis_id']);
        $linkedFicheIds=$this->linkedFicheIds((int)$source['source_devis_id']);
        $this->pdo->beginTransaction();
        try{
            $sourceDevisId=(int)$source['source_devis_id'];$targetDevisId=(int)$target['target_devis_id'];
            if(empty($preview['financialTransferDone']))(new DevisFinancialTransferService($this->pdo))->transfer($sourceDevisId,$targetDevisId,$lineMap,$debourseMap,true,'Fusion du doublon '.$sourceCode.' dans '.$targetCode,(int)$userId);
            $sourceId=(int)$source['id'];$targetId=(int)$target['id'];
            // Une seule commande client peut être active par chantier; la pièce source reste conservée dans son registre.
            $this->pdo->prepare('DELETE FROM chantier_client_purchase_orders WHERE chantier_id=:id')->execute(['id'=>$sourceId]);
            foreach($this->chantierTables() as $table){if(in_array($table,['chantier_banamur','chantier_archive_scopes','chantier_client_purchase_orders'],true))continue;$q=$this->pdo->prepare("UPDATE `$table` SET chantier_id=:target WHERE chantier_id=:source");$q->execute(['target'=>$targetId,'source'=>$sourceId]);}
            if($this->tableExists('employee_legal_requests')){$q=$this->pdo->prepare("UPDATE employee_legal_requests SET payload_json=JSON_SET(payload_json,'$.chantier_id',:target) WHERE JSON_VALID(payload_json) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.chantier_id')) AS UNSIGNED)=:source");$q->execute(['target'=>$targetId,'source'=>$sourceId]);}
            if($this->tableExists('chantier_archive_scopes'))$this->pdo->prepare('DELETE FROM chantier_archive_scopes WHERE chantier_id=:id')->execute(['id'=>$sourceId]);
            $delete=$this->pdo->prepare('DELETE FROM chantier_banamur WHERE id=:id AND code=:code');$delete->execute(['id'=>$sourceId,'code'=>$sourceCode]);
            (new CommercialProjectFinanceRepository($this->pdo))->archive($sourceDevisId,$userId,'Devis absorbé par '.$targetQuote.' lors de la fusion '.$sourceCode.' vers '.$targetCode,['source_chantier'=>$sourceCode,'target_chantier'=>$targetCode]);
            $this->pdo->prepare('UPDATE devis_banamur SET masque=1 WHERE id=:id')->execute(['id'=>$sourceDevisId]);
            $this->finalizeQuoteClassification($sourceDevisId,$targetDevisId,$userId);
            $check=$this->pdo->prepare('SELECT COUNT(*) FROM chantier_banamur WHERE code=:source OR (code=:target AND devis_id<>:devis)');$check->execute(['source'=>$sourceCode,'target'=>$targetCode,'devis'=>$targetDevisId]);if((int)$check->fetchColumn()!==0)throw new RuntimeException('Le contrôle final du chantier ou du devis conservé a échoué.');
            $this->assertFichesMoved($linkedFicheIds,$targetDevisId,$targetId);
            \AuditTrail::log('chantier_duplicate_merged',['module'=>'chantiers','action'=>'merge','entity_type'=>'chantier','entity_id'=>(string)$targetId,'entity_label'=>$sourceCode.' vers '.$targetCode,'result'=>'success','details'=>$preview],$this->pdo);
            $this->pdo->commit();return $preview;
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
    private function chantierTables():array{$q=$this->pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='chantier_id' AND TABLE_NAME<>'chantier_banamur' ORDER BY TABLE_NAME");return $q->fetchAll(PDO::FETCH_COLUMN)?:[];}
    private function linkedFicheIds(int $devisId):array
    {
        if(!$this->tableExists('fiche_decaissement')||!$this->tableExists('ligne_debourse_banamur')||!$this->tableExists('debourse_banamur'))return[];
        $q=$this->pdo->prepare('SELECT DISTINCT f.id_fiche FROM fiche_decaissement f JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE db.devis_id=:devis ORDER BY f.id_fiche');$q->execute(['devis'=>$devisId]);return array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
    }
    private function assertFichesMoved(array $ids,int $targetDevisId,int $targetChantierId):void
    {
        if(!$ids)return;$marks=implode(',',array_fill(0,count($ids),'?'));$q=$this->pdo->prepare("SELECT COUNT(DISTINCT f.id_fiche) FROM fiche_decaissement f JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id JOIN debourse_banamur db ON db.id=ldb.debourse_id WHERE f.id_fiche IN ($marks) AND db.devis_id=? AND f.chantier_id=?");$q->execute([...$ids,$targetDevisId,$targetChantierId]);if((int)$q->fetchColumn()!==count($ids))throw new RuntimeException('Contrôle refusé : une ou plusieurs fiches liées n’ont pas été correctement déplacées vers le devis et le chantier conservés.');
    }
    private function finalizeQuoteClassification(int $sourceDevisId,int $targetDevisId,int $userId):void
    {
        $quote=$this->pdo->prepare('SELECT id,offre_id FROM devis_banamur WHERE id IN (:source,:target) ORDER BY id');
        $quote->execute(['source'=>$sourceDevisId,'target'=>$targetDevisId]);
        $offers=[];foreach($quote->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$offers[(int)$row['id']]=(int)($row['offre_id']??0);
        $targetOfferId=(int)($offers[$targetDevisId]??0);
        $this->pdo->prepare('DELETE FROM devis_offer_classifications WHERE devis_id=:id')->execute(['id'=>$sourceDevisId]);
        if($targetOfferId<=0)return;
        $repository=new DevisOfferProjectRepository($this->pdo);
        $repository->ensureSettings($targetOfferId,$userId);
        $repository->demoteOtherPrincipal($targetOfferId,$targetDevisId,$userId);
        $repository->upsertClassification($targetDevisId,$targetOfferId,'PRINCIPAL',$userId);
        $repository->setPrincipal($targetOfferId,$targetDevisId,$userId);
    }
    private function tableExists(string $table):bool{$q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');$q->execute(['table'=>$table]);return(bool)$q->fetchColumn();}
    private function ensureSchema():void
    {
        (new CommercialProjectFinanceMigrationService($this->pdo))->ensureSchema();
        (new DevisFinancialTransferMigrationService($this->pdo))->ensureSchema();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chantier_archive_scopes (
            chantier_id INT NOT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_by INT NULL, scope_json LONGTEXT NOT NULL, restored_at DATETIME NULL,
            PRIMARY KEY(chantier_id), KEY idx_chantier_archive_scope_active(restored_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if($this->tableExists('chantier_banamur')){
            $this->addColumn('chantier_banamur','archived_at','DATETIME NULL');
            $this->addColumn('chantier_banamur','archived_by','INT NULL');
            $this->addColumn('chantier_banamur','archived_note','TEXT NULL');
            $this->addColumn('chantier_banamur','closed_date','DATE NULL');
        }
        if($this->tableExists('devis_banamur'))$this->addColumn('devis_banamur','masque','TINYINT(1) NOT NULL DEFAULT 0');
    }
    private function addColumn(string $table,string $column,string $definition):void
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column');
        $q->execute(['table'=>$table,'column'=>$column]);
        if(!(int)$q->fetchColumn())$this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}
