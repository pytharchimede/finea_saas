<?php

final class FnePurchaseInvoiceImportManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo=$pdo;
        require_once __DIR__.'/../repositories/FactureAchatRepository.php';
        new FactureAchatRepository($pdo);
        $this->ensureSchema();
        require_once __DIR__.'/FnePartyLinkManager.php';new FnePartyLinkManager($pdo);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS fne_purchase_invoice_imports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            initial_reference VARCHAR(190) NULL, token VARCHAR(190) NULL, fne_reference VARCHAR(190) NULL,
            invoice_type VARCHAR(60) NULL, invoice_subtype VARCHAR(60) NULL, invoice_date DATETIME(3) NULL,
            payment_method VARCHAR(60) NULL, stamp_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_ht DECIMAL(18,2) NOT NULL DEFAULT 0, discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_vat DECIMAL(18,2) NOT NULL DEFAULT 0, total_ttc DECIMAL(18,2) NOT NULL DEFAULT 0,
            other_taxes DECIMAL(18,2) NOT NULL DEFAULT 0, net_payable DECIMAL(18,2) NOT NULL DEFAULT 0,
            client_ncc VARCHAR(100) NULL, client_name VARCHAR(255) NULL, client_phone VARCHAR(100) NULL,
            client_email VARCHAR(190) NULL, terminal VARCHAR(120) NULL, client_rccm VARCHAR(190) NULL,
            supplier_ncc VARCHAR(100) NULL, supplier_name VARCHAR(255) NULL,
            seller_name VARCHAR(190) NULL, establishment VARCHAR(190) NULL, point_of_sale VARCHAR(190) NULL,
            tax_regime VARCHAR(100) NULL, billing_type VARCHAR(60) NULL, other_mentions TEXT NULL,
            footer_text TEXT NULL, foreign_currency VARCHAR(30) NULL, exchange_rate DECIMAL(18,6) NULL,
            is_rne TINYINT(1) NOT NULL DEFAULT 0, rne VARCHAR(120) NULL,
            fne_created_at DATETIME(3) NULL, fne_updated_at DATETIME(3) NULL,
            finea_invoice_id INT NULL, link_method VARCHAR(60) NULL, linked_at DATETIME NULL,
            raw_json LONGTEXT NOT NULL, search_text LONGTEXT NOT NULL, import_batch VARCHAR(80) NOT NULL,
            imported_by_user_id INT NULL, imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            supplier_mapping_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY(id), UNIQUE KEY uniq_fne_purchase_token(token),
            UNIQUE KEY uniq_fne_purchase_reference(fne_reference), KEY idx_fne_purchase_finea(finea_invoice_id),
            KEY idx_fne_purchase_client(client_name), KEY idx_fne_purchase_date(invoice_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach([
            "ALTER TABLE fne_purchase_invoice_imports ADD supplier_ncc VARCHAR(100) NULL AFTER client_rccm",
            "ALTER TABLE fne_purchase_invoice_imports ADD supplier_name VARCHAR(255) NULL AFTER supplier_ncc",
            "ALTER TABLE fne_purchase_invoice_imports ADD supplier_mapping_version TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER imported_at",
            "ALTER TABLE fne_purchase_invoice_imports ADD finea_supplier_id INT NULL",
        ]as$sql){try{$this->pdo->exec($sql);}catch(Throwable$ignored){}}
        try {
            $this->pdo->exec("UPDATE fne_purchase_invoice_imports SET
                supplier_ncc=COALESCE(NULLIF(TRIM(supplier_ncc),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json,'$.ncc_de_l_entreprise')),'')),
                supplier_name=COALESCE(NULLIF(TRIM(supplier_name),''),NULLIF(JSON_UNQUOTE(JSON_EXTRACT(raw_json,'$.entreprise')),'')
                WHERE supplier_mapping_version=0");
            $this->pdo->exec("UPDATE fne_purchase_invoice_imports SET finea_supplier_id=NULL,supplier_mapping_version=1 WHERE supplier_mapping_version=0");
        } catch (Throwable $ignored) {
            // Une migration de données ne doit pas empêcher l'ouverture de la page.
        }
        try{$this->pdo->exec("ALTER TABLE fne_purchase_invoice_imports MODIFY supplier_mapping_version TINYINT UNSIGNED NOT NULL DEFAULT 1");}catch(Throwable$ignored){}
    }

    public function importFile(array $file,?int $userId): array
    {
        if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name']??''))) throw new RuntimeException('Sélectionnez un fichier FNE CSV, XLSX ou XLS valide.');
        if ((int)($file['size']??0)>20*1024*1024) throw new RuntimeException('Le fichier dépasse 20 Mo.');
        $ext=strtolower(pathinfo((string)($file['name']??''),PATHINFO_EXTENSION));
        if (!in_array($ext,['csv','xlsx','xls'],true)) throw new RuntimeException('Formats acceptés : CSV, XLSX ou XLS.');
        $rows=$this->readRows((string)$file['tmp_name'],$ext);
        if (count($rows)<2) throw new RuntimeException('Le fichier ne contient aucune facture.');
        $headers=array_map([$this,'normalizeHeader'],array_shift($rows));
        $batch='FNE-ACHAT-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $report=['batch'=>$batch,'read'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0,'duplicates'=>0,'linked'=>0,'errors'=>[]];
        foreach($rows as $rowIndex=>$values) {
            if (!array_filter($values,static fn($v):bool=>trim((string)$v)!=='')) continue;
            $report['read']++;
            try {
                $raw=[];foreach($headers as $i=>$header)if($header!=='')$raw[$header]=$values[$i]??null;
                $m=$this->mapRow($raw);
                if ($m['token']===''&&$m['fne_reference']==='') throw new RuntimeException('Token et référence absents.');
                if($this->isBanamurIssuedInvoice($m)){
                    throw new RuntimeException('Facture de vente détectée : l’établissement émetteur est BANAMUR. Importez ce fichier depuis « Factures de vente FNE ».');
                }
                $existing=$this->findExisting($m['token'],$m['fne_reference']);
                $link=$this->findFineaLink($m);$rawJson=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $effectiveFineaId=(int)($link['id']?:($existing['finea_invoice_id']??0));if($effectiveFineaId>0)$this->synchronizeFineaInvoice($effectiveFineaId,$m);
                if($existing&&hash_equals((string)($existing['raw_json']??''),(string)$rawJson)&&(!empty($existing['finea_invoice_id'])||empty($link['id']))){$report['duplicates']++;$report['skipped']++;continue;}
                $m['finea_invoice_id']=$link['id']?:($existing['finea_invoice_id']??null);$m['link_method']=$link['method']?:($existing['link_method']??null);
                $m['raw_json']=$rawJson;
                $m['search_text']=$this->normalizeSearch(implode(' ',array_map('strval',$raw)));
                $m['batch']=$batch;$m['user_id']=$userId;$this->upsert($m,$existing?(int)$existing['id']:null);
                if($existing){$report['updated']++;$report['duplicates']++;}else$report['inserted']++;
                if($link['id'])$report['linked']++;
            } catch(Throwable $e){$report['errors'][]='Ligne '.($rowIndex+2).' : '.$e->getMessage();}
        }
        (new FnePartyLinkManager($this->pdo))->autoApplyAll();
        return $report;
    }

    public function search(string $query,int $limit=40): array
    {
        if(mb_strlen(trim($query))<2)return[];
        $stmt=$this->pdo->prepare("SELECT i.*,fa.reference_interne finea_reference,fa.chantier_id,c.code chantier_code,c.titre chantier_title,COALESCE(NULLIF(f.nom_commercial,''),f.raison_sociale) finea_party_name
            FROM fne_purchase_invoice_imports i
            LEFT JOIN facture_achat_banamur fa ON fa.id_facture_achat=i.finea_invoice_id
            LEFT JOIN chantier_banamur c ON c.id=fa.chantier_id
            LEFT JOIN fournisseur_banamur f ON f.id_fournisseur=i.finea_supplier_id
            WHERE i.search_text LIKE :q ORDER BY i.invoice_date DESC,i.id DESC LIMIT ".max(1,min(100,$limit)));
        $stmt->execute(['q'=>'%'.$this->normalizeSearch($query).'%']);return$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function latest(int $limit=300): array
    {
        return $this->pdo->query("SELECT i.*,fa.reference_interne finea_reference,fa.chantier_id,c.code chantier_code,c.titre chantier_title,COALESCE(NULLIF(f.nom_commercial,''),f.raison_sociale) finea_party_name
            FROM fne_purchase_invoice_imports i LEFT JOIN facture_achat_banamur fa ON fa.id_facture_achat=i.finea_invoice_id
            LEFT JOIN chantier_banamur c ON c.id=fa.chantier_id
            LEFT JOIN fournisseur_banamur f ON f.id_fournisseur=i.finea_supplier_id
            ORDER BY i.invoice_date DESC,i.id DESC LIMIT ".max(1,min(500,$limit)))->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function reconcileAll(int $limit=500):array
    {
        $rows=$this->pdo->query("SELECT * FROM fne_purchase_invoice_imports ORDER BY id DESC LIMIT ".max(1,min(2000,$limit)))->fetchAll(PDO::FETCH_ASSOC)?:[];
        $report=['scanned'=>count($rows),'linked'=>0,'synchronized'=>0,'unmatched'=>0,'errors'=>[]];
        foreach($rows as$row){try{$link=$this->findFineaLink($row);$fineaId=(int)($link['id']?:($row['finea_invoice_id']??0));if($fineaId<=0){$report['unmatched']++;continue;}$this->synchronizeFineaInvoice($fineaId,$row);$report['synchronized']++;if((int)($row['finea_invoice_id']??0)!==$fineaId){$s=$this->pdo->prepare("UPDATE fne_purchase_invoice_imports SET finea_invoice_id=:finea,link_method=:method,linked_at=NOW() WHERE id=:id");$s->execute(['finea'=>$fineaId,'method'=>$link['method']?:'existing_link','id'=>$row['id']]);$report['linked']++;}}catch(Throwable$e){$report['errors'][]='Achat FNE #'.(int)$row['id'].' : '.$e->getMessage();}}
        return$report;
    }

    public function getSelectable(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM fne_purchase_invoice_imports WHERE id=:id LIMIT 1');$stmt->execute(['id'=>$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row)throw new RuntimeException('La facture FNE sélectionnée est introuvable.');
        if(!empty($row['finea_invoice_id']))throw new RuntimeException('Cette facture FNE est déjà liée à une facture d’achat Finéa.');
        return$row;
    }

    public function linkToFinea(int $importId,int $fineaId): void
    {
        $row=$this->getSelectable($importId);
        $stmt=$this->pdo->prepare("UPDATE fne_purchase_invoice_imports SET finea_invoice_id=:finea,link_method='creation_select_search',linked_at=NOW() WHERE id=:id AND finea_invoice_id IS NULL");
        $stmt->execute(['finea'=>$fineaId,'id'=>$importId]);
        if($stmt->rowCount()!==1)throw new RuntimeException('La ligne FNE vient déjà d’être rapprochée.');
        $this->synchronizeFineaInvoice($fineaId,$row);
    }

    private function readRows(string $path,string $ext): array
    {
        if($ext==='csv'){$h=fopen($path,'rb');if(!$h)throw new RuntimeException('Lecture CSV impossible.');$first=(string)fgets($h);rewind($h);$delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';$rows=[];while(($row=fgetcsv($h,0,$delimiter))!==false)$rows[]=$row;fclose($h);return$rows;}
        require_once __DIR__.'/ZipArchiveCompatibility.php';
        if(!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class))throw new RuntimeException('Lecteur Excel indisponible.');
        return \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null,true,true,false);
    }

    private function mapRow(array $r): array
    {
        $v=static fn(string $key):string=>trim((string)($r[$key]??''));
        return[
            'initial_reference'=>$v('reference_initiale'),'token'=>$v('token'),'fne_reference'=>$v('reference'),
            'invoice_type'=>$v('type_de_facture'),'invoice_subtype'=>$v('sous_type_de_facture'),'invoice_date'=>$this->date($v('date')),
            'payment_method'=>$v('mode_de_paiement'),'stamp_amount'=>$this->number($v('timbre_de_quittance')),
            'total_ht'=>$this->number($v('total_ht')),'discount_amount'=>$this->number($v('remise')),'total_vat'=>$this->number($v('total_tva')),
            'total_ttc'=>$this->number($v('total_ttc')),'other_taxes'=>$this->number($v('total_autres_taxes')),'net_payable'=>$this->number($v('net_a_payer')),
            'client_ncc'=>$v('ncc_du_client'),'client_name'=>$v('nom_de_la_societe_du_client'),'client_phone'=>$v('telephone_du_client'),
            'client_email'=>$v('email_du_client'),'terminal'=>$v('terminal'),'client_rccm'=>$v('rccm'),'seller_name'=>$v('nom_du_vendeur'),
            'supplier_ncc'=>$v('ncc_de_l_entreprise'),'supplier_name'=>$v('entreprise'),
            'establishment'=>$v('etablissement'),'point_of_sale'=>$v('point_de_vente'),'tax_regime'=>$v('regime_d_imposition'),
            'billing_type'=>$v('type_de_facturation'),'other_mentions'=>$v('autres_mentions'),'footer_text'=>$v('pied_de_page'),
            'foreign_currency'=>$v('devises_etrangeres'),'exchange_rate'=>$this->number($v('taux_de_change'))?:null,
            'is_rne'=>in_array($this->normalizeSearch($v('est_rne')),['vrai','true','oui','1'],true)?1:0,'rne'=>$v('rne'),
            'fne_created_at'=>$this->date($v('cree_a')),'fne_updated_at'=>$this->date($v('mise_a_jour_a'))
        ];
    }

    private function findExisting(string $token,string $reference):?array{$s=$this->pdo->prepare("SELECT id,raw_json,finea_invoice_id,link_method FROM fne_purchase_invoice_imports WHERE (token<>'' AND token=:token) OR (fne_reference<>'' AND fne_reference=:ref) LIMIT 1");$s->execute(['token'=>$token,'ref'=>$reference]);return$s->fetch(PDO::FETCH_ASSOC)?:null;}
    private function findFineaLink(array $m):array{foreach([['fne_token',$m['token'],'token'],['verification_code',$m['token'],'verification_token'],['invoice_number',$m['fne_reference'],'reference'],['verification_code',$m['fne_reference'],'verification_reference']]as[$column,$value,$method]){if($value==='')continue;$s=$this->pdo->prepare("SELECT id_facture_achat FROM facture_achat_banamur WHERE $column=:value LIMIT 2");$s->execute(['value'=>$value]);$ids=$s->fetchAll(PDO::FETCH_COLUMN);if(count($ids)===1)return['id'=>(int)$ids[0],'method'=>$method];}return['id'=>null,'method'=>null];}
    private function synchronizeFineaInvoice(int $id,array $m):void{$s=$this->pdo->prepare("UPDATE facture_achat_banamur SET fne_token=COALESCE(NULLIF(:token,''),fne_token),invoice_number=COALESCE(NULLIF(:reference,''),invoice_number),verification_code=COALESCE(NULLIF(:token_code,''),verification_code),invoice_date=COALESCE(DATE(:invoice_date),invoice_date),payment_method=COALESCE(NULLIF(:payment_method,''),payment_method),amount_total=:net_payable,total_ht=:total_ht,total_vat=:total_vat,vat_applicable=IF(:total_vat_check>0,1,0),devise=COALESCE(NULLIF(:currency,''),devise),fne_last_verify_at=NOW(),fne_last_verify_ok=1,fne_last_verify_amount=:net_payable_verify,fne_last_verify_amount_ok=1,updated_at=NOW() WHERE id_facture_achat=:id");$s->execute(['token'=>$m['token'],'reference'=>$m['fne_reference'],'token_code'=>$m['token'],'invoice_date'=>$m['invoice_date'],'payment_method'=>$m['payment_method'],'net_payable'=>$m['net_payable'],'total_ht'=>$m['total_ht'],'total_vat'=>$m['total_vat'],'total_vat_check'=>$m['total_vat'],'currency'=>$m['foreign_currency']?:'FCFA','net_payable_verify'=>$m['net_payable'],'id'=>$id]);}
    private function upsert(array $m,?int $id):void{$m['token']=$m['token']?:null;$m['fne_reference']=$m['fne_reference']?:null;$m['linked_at']=$m['finea_invoice_id']?date('Y-m-d H:i:s'):null;$keys=array_keys($m);$cols=array_map(static fn($k)=>['batch'=>'import_batch','user_id'=>'imported_by_user_id'][$k]??$k,$keys);if($id===null)$sql='INSERT INTO fne_purchase_invoice_imports ('.implode(',',$cols).') VALUES (:'.implode(',:',$keys).')';else{$sets=[];foreach($cols as$i=>$col)$sets[]=$col.'=:'.$keys[$i];$sql='UPDATE fne_purchase_invoice_imports SET '.implode(',',$sets).',imported_at=NOW() WHERE id=:row_id';$m['row_id']=$id;}$this->pdo->prepare($sql)->execute($m);}
    private function normalizeHeader($v):string{return trim(preg_replace('/[^a-z0-9]+/','_',$this->normalizeSearch((string)$v)),'_');}
    private function normalizeSearch(string $v):string{$v=mb_strtolower(trim($v),'UTF-8');$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v);return preg_replace('/\s+/',' ',strtolower($ascii!==false?$ascii:$v));}
    private function isBanamurIssuedInvoice(array $row):bool
    {
        $establishment=$this->normalizeSearch((string)($row['establishment']??''));
        return $establishment!==''&&str_contains($establishment,'banamur');
    }
    private function number(string $v):float{$v=str_replace(["\xc2\xa0",' '],'',$v);$v=str_replace(',','.',$v);return is_numeric($v)?(float)$v:0.0;}
    private function date(string $v):?string{if($v==='')return null;try{return(new DateTimeImmutable($v))->format('Y-m-d H:i:s.v');}catch(Throwable $e){return null;}}
}
