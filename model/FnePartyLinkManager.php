<?php
require_once __DIR__.'/../services/SupplierReferenceService.php';
require_once __DIR__.'/../services/ClientBusinessPolicy.php';
require_once __DIR__.'/../services/ClientIdentityMigration.php';

final class FnePartyLinkManager
{
    private PDO $pdo;
    private SupplierReferenceService $references;
    public function __construct(PDO $pdo){$this->pdo=$pdo;$this->ensureSchema();(new ClientIdentityMigration($pdo))->install();$this->references=new SupplierReferenceService($pdo);}

    private function ensureSchema():void
    {
        foreach([
            "ALTER TABLE client_banamur ADD ncc VARCHAR(80) NULL",
            "ALTER TABLE client_banamur ADD rccm VARCHAR(190) NULL",
            "ALTER TABLE client_banamur ADD rne VARCHAR(120) NULL",
            "ALTER TABLE fne_sales_invoice_imports ADD finea_client_id INT NULL",
            "ALTER TABLE fne_purchase_invoice_imports ADD finea_supplier_id INT NULL",
            "ALTER TABLE facture_vente_banamur ADD client_id INT NULL",
        ]as$sql){try{$this->pdo->exec($sql);}catch(Throwable$ignored){}}
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS fne_party_links(
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,context ENUM('sale','purchase') NOT NULL,
            party_key VARCHAR(255) NOT NULL,fne_ncc VARCHAR(100) NULL,fne_name VARCHAR(255) NULL,
            finea_client_id INT NULL,finea_supplier_id INT NULL,created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),UNIQUE KEY uniq_fne_party_context_key(context,party_key),
            KEY idx_fne_party_client(finea_client_id),KEY idx_fne_party_supplier(finea_supplier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function searchEntities(string $context,string $query,int $limit=30):array
    {
        $query=trim($query);if(mb_strlen($query)<1)return[];
        $limit=max(1,min(100,$limit));$like='%'.$query.'%';
        if($context==='sale'){
            $s=$this->pdo->prepare("SELECT id_client id,nom_client name,code_client code,ncc,COALESCE(registration_number,siret,vat_number,ncc) identity_number,telephone_client phone,email_client email FROM client_banamur WHERE (nom_client LIKE :q OR code_client LIKE :q OR ncc LIKE :q OR telephone_client LIKE :q OR email_client LIKE :q OR siret LIKE :q OR vat_number LIKE :q OR registration_number LIKE :q) AND ".ClientBusinessPolicy::sqlUsable()." ORDER BY nom_client LIMIT $limit");
        }else{
            $s=$this->pdo->prepare("SELECT id_fournisseur id,COALESCE(NULLIF(nom_commercial,''),raison_sociale) name,code_fournisseur code,ncc,COALESCE(identity_document_number,registration_number,siret,vat_number,ncc) identity_number,telephone phone,email FROM fournisseur_banamur WHERE (raison_sociale LIKE :q OR nom_commercial LIKE :q OR code_fournisseur LIKE :q OR ncc LIKE :q OR telephone LIKE :q OR email LIKE :q OR siret LIKE :q OR vat_number LIKE :q OR registration_number LIKE :q OR identity_document_number LIKE :q) AND actif=1 AND validation_status='approved' AND archived_at IS NULL ORDER BY name LIMIT $limit");
        }
        $s->execute(['q'=>$like]);$rows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
        return array_values(array_filter($rows,function(array $row)use($context):bool{try{$this->assertEntity($context,(int)$row['id']);return true;}catch(DomainException $e){return false;}}));
    }

    public function link(string $context,int $importId,int $entityId,int $userId):array
    {
        $party=$this->importedParty($context,$importId);$this->assertEntity($context,$entityId);
        $key=$this->importedPartyKey($party);
        $ownsTransaction=!$this->pdo->inTransaction();if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            if($context==='sale'){
                $sql="INSERT INTO fne_party_links(context,party_key,fne_ncc,fne_name,finea_client_id,created_by_user_id) VALUES('sale',:party_key,:ncc,:name,:entity,:user) ON DUPLICATE KEY UPDATE finea_client_id=VALUES(finea_client_id),fne_ncc=VALUES(fne_ncc),fne_name=VALUES(fne_name),updated_at=NOW()";
            }else{
                $sql="INSERT INTO fne_party_links(context,party_key,fne_ncc,fne_name,finea_supplier_id,created_by_user_id) VALUES('purchase',:party_key,:ncc,:name,:entity,:user) ON DUPLICATE KEY UPDATE finea_supplier_id=VALUES(finea_supplier_id),fne_ncc=VALUES(fne_ncc),fne_name=VALUES(fne_name),updated_at=NOW()";
            }
            $this->pdo->prepare($sql)->execute(['party_key'=>$key,'ncc'=>$party['client_ncc']?:null,'name'=>$party['client_name']?:null,'entity'=>$entityId,'user'=>$userId?:null]);
            $affected=$this->applyToAllMatchingImports($context,$party,$entityId);
            $this->enrichEntity($context,$entityId,$party);
            if($ownsTransaction)$this->pdo->commit();return['entity_id'=>$entityId,'affected_invoices'=>$affected,'created'=>false];
        }catch(Throwable$e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function createAndLink(string $context,int $importId,int $userId):array
    {
        throw new DomainException('Créez le tiers depuis le formulaire commun avec son pays fiscal et ses justificatifs, puis associez sa fiche validée à la facture FNE.');
    }

    public function autoApplyAll(int $limit=2000):int
    {
        $links=$this->pdo->query("SELECT * FROM fne_party_links ORDER BY id DESC LIMIT ".max(1,min(5000,$limit)))->fetchAll(PDO::FETCH_ASSOC)?:[];$count=0;
        foreach($links as$link){$context=(string)$link['context'];$table=$context==='sale'?'fne_sales_invoice_imports':'fne_purchase_invoice_imports';$rows=$this->pdo->query("SELECT * FROM $table ORDER BY id DESC LIMIT 5000")->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($rows as$row){$row=$this->normalizeImportedParty($context,$row);if($this->importedPartyKey($row)!==(string)$link['party_key'])continue;$entity=(int)($context==='sale'?$link['finea_client_id']:$link['finea_supplier_id']);if($entity>0){try{$this->assertEntity($context,$entity);$count+=$this->applyToAllMatchingImports($context,$row,$entity);}catch(DomainException $e){continue;}}}}
        return$count;
    }

    private function importedParty(string $context,int $id):array{$table=$context==='sale'?'fne_sales_invoice_imports':'fne_purchase_invoice_imports';$s=$this->pdo->prepare("SELECT * FROM $table WHERE id=:id LIMIT 1");$s->execute(['id'=>$id]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new RuntimeException('Facture FNE introuvable.');return$this->normalizeImportedParty($context,$row);}
    private function assertEntity(string $context,int $id):void
    {
        if($context==='sale')ClientBusinessPolicy::requireClient($this->pdo,$id);
        else $this->references->resolve($id);
    }
    private function importedPartyKey(array $party):string
    {
        $ncc=preg_replace('/[^A-Z0-9]/','',mb_strtoupper(trim((string)($party['client_ncc']??'')),'UTF-8'));
        return $ncc!==''?'ncc:'.$ncc:'invoice:'.(int)($party['id']??0);
    }
    private function applyToAllMatchingImports(string$context,array$party,int$entity):int{$table=$context==='sale'?'fne_sales_invoice_imports':'fne_purchase_invoice_imports';$column=$context==='sale'?'finea_client_id':'finea_supplier_id';$rows=$this->pdo->query("SELECT * FROM $table")->fetchAll(PDO::FETCH_ASSOC)?:[];$key=$this->importedPartyKey($party);$ids=[];foreach($rows as$row){$row=$this->normalizeImportedParty($context,$row);if($this->importedPartyKey($row)===$key)$ids[]=(int)$row['id'];}if(!$ids)return 0;$affected=$this->pdo->exec("UPDATE $table SET $column=".(int)$entity." WHERE id IN(".implode(',',$ids).")");if($context==='sale'){$this->pdo->exec("UPDATE facture_vente_banamur fv JOIN fne_sales_invoice_imports i ON i.finea_invoice_id=fv.id_facture_vente SET fv.client_id=".(int)$entity." WHERE i.id IN(".implode(',',$ids).")");}else{$this->pdo->exec("UPDATE facture_achat_banamur fa JOIN fne_purchase_invoice_imports i ON i.finea_invoice_id=fa.id_facture_achat SET fa.fournisseur_id=".(int)$entity." WHERE i.id IN(".implode(',',$ids).")");}return$affected;}
    private function normalizeImportedParty(string$context,array$row):array{if($context==='purchase'){$row['client_ncc']=$row['supplier_ncc']??'';$row['client_name']=$row['supplier_name']??'';$row['client_phone']='';$row['client_email']='';$row['client_rccm']='';}return$row;}
    private function enrichEntity(string $context,int $id,array $p):void
    {
        $table=$context==='sale'?'client_banamur':'fournisseur_banamur';
        $phone=$context==='sale'?'telephone_client':'telephone';$email=$context==='sale'?'email_client':'email';$key=$context==='sale'?'id_client':'id_fournisseur';
        $s=$this->pdo->prepare("UPDATE $table SET $phone=COALESCE(NULLIF(TRIM($phone),''),NULLIF(:phone,'')),$email=COALESCE(NULLIF(TRIM($email),''),NULLIF(:email,'')) WHERE $key=:id");
        $s->execute(['phone'=>$p['client_phone']??'','email'=>$p['client_email']??'','id'=>$id]);
    }
}
