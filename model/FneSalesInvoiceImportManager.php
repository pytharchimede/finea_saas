<?php

final class FneSalesInvoiceImportManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo=$pdo;
        $this->ensureSchema();
        require_once __DIR__.'/FnePartyLinkManager.php';new FnePartyLinkManager($pdo);
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS fne_sales_invoice_imports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            initial_reference VARCHAR(190) NULL,
            token VARCHAR(190) NULL,
            fne_reference VARCHAR(190) NULL,
            invoice_type VARCHAR(60) NULL,
            invoice_subtype VARCHAR(60) NULL,
            invoice_date DATETIME(3) NULL,
            payment_method VARCHAR(60) NULL,
            stamp_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_ht DECIMAL(18,2) NOT NULL DEFAULT 0,
            discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_vat DECIMAL(18,2) NOT NULL DEFAULT 0,
            total_ttc DECIMAL(18,2) NOT NULL DEFAULT 0,
            other_taxes DECIMAL(18,2) NOT NULL DEFAULT 0,
            net_payable DECIMAL(18,2) NOT NULL DEFAULT 0,
            client_ncc VARCHAR(100) NULL,
            client_name VARCHAR(255) NULL,
            client_phone VARCHAR(100) NULL,
            client_email VARCHAR(190) NULL,
            terminal VARCHAR(120) NULL,
            client_rccm VARCHAR(190) NULL,
            seller_name VARCHAR(190) NULL,
            establishment VARCHAR(190) NULL,
            point_of_sale VARCHAR(190) NULL,
            tax_regime VARCHAR(100) NULL,
            billing_type VARCHAR(60) NULL,
            other_mentions TEXT NULL,
            footer_text TEXT NULL,
            foreign_currency VARCHAR(30) NULL,
            exchange_rate DECIMAL(18,6) NULL,
            is_rne TINYINT(1) NOT NULL DEFAULT 0,
            rne VARCHAR(120) NULL,
            fne_created_at DATETIME(3) NULL,
            fne_updated_at DATETIME(3) NULL,
            finea_invoice_id INT NULL,
            link_method VARCHAR(60) NULL,
            linked_at DATETIME NULL,
            raw_json LONGTEXT NOT NULL,
            search_text LONGTEXT NOT NULL,
            import_batch VARCHAR(80) NOT NULL,
            imported_by_user_id INT NULL,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uniq_fne_sale_token(token),
            UNIQUE KEY uniq_fne_sale_reference(fne_reference),
            KEY idx_fne_sale_finea(finea_invoice_id),
            KEY idx_fne_sale_client(client_name),
            KEY idx_fne_sale_date(invoice_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function importFile(array $file, ?int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Sélectionnez un fichier FNE CSV, XLSX ou XLS valide.');
        }
        if ((int)($file['size'] ?? 0)>20*1024*1024) throw new RuntimeException('Le fichier dépasse 20 Mo.');
        $extension=strtolower(pathinfo((string)($file['name'] ?? ''),PATHINFO_EXTENSION));
        if (!in_array($extension,['csv','xlsx','xls'],true)) throw new RuntimeException('Formats acceptés : CSV, XLSX ou XLS.');
        $rows=$this->readRows((string)$file['tmp_name'],$extension);
        if (count($rows)<2) throw new RuntimeException('Le fichier ne contient aucune facture.');
        $headers=array_map([$this,'normalizeHeader'],array_shift($rows));
        $batch='FNE-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $result=['batch'=>$batch,'read'=>0,'inserted'=>0,'updated'=>0,'skipped'=>0,'linked'=>0,'duplicates'=>0,'errors'=>[]];
        foreach ($rows as $rowNumber=>$values) {
            if (!array_filter($values,static fn($v): bool => trim((string)$v)!=='')) continue;
            $result['read']++;
            try {
                $raw=[];
                foreach ($headers as $index=>$header) if ($header!=='') $raw[$header]=$values[$index] ?? null;
                $mapped=$this->mapRow($raw);
                if ($mapped['token']==='' && $mapped['fne_reference']==='') throw new RuntimeException('Token et référence absents.');
                if($this->containsBanamur((string)($mapped['client_name']??''))){
                    throw new RuntimeException('Facture d’achat détectée : le client/récepteur est BANAMUR. Importez ce fichier depuis « Factures d’achat FNE ».');
                }
                $existing=$this->findExisting($mapped['token'],$mapped['fne_reference']);
                $link=$this->findFineaLink($mapped);
                $rawJson=json_encode($raw,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $effectiveFineaId=(int)($link['id']?:($existing['finea_invoice_id']??0));
                if($effectiveFineaId>0)$this->synchronizeFineaInvoice($effectiveFineaId,$mapped);
                if ($existing && hash_equals((string)($existing['raw_json']??''),(string)$rawJson)
                    && (!empty($existing['finea_invoice_id']) || empty($link['id']))) {
                    $result['duplicates']++;$result['skipped']++;continue;
                }
                $mapped['finea_invoice_id']=$link['id'] ?: ($existing['finea_invoice_id']??null);
                $mapped['link_method']=$link['method'] ?: ($existing['link_method']??null);
                $mapped['raw_json']=$rawJson;
                $mapped['search_text']=$this->normalizeSearch(implode(' ',array_map('strval',$raw)));
                $mapped['batch']=$batch;$mapped['user_id']=$userId;
                $this->upsert($mapped,$existing ? (int)$existing['id'] : null);
                if ($existing) {$result['updated']++;$result['duplicates']++;} else $result['inserted']++;
                if ($link['id']) $result['linked']++;
            } catch (Throwable $e) {
                $result['errors'][]='Ligne '.($rowNumber+2).' : '.$e->getMessage();
            }
        }
        (new FnePartyLinkManager($this->pdo))->autoApplyAll();
        return $result;
    }

    public function search(string $query, int $limit=30): array
    {
        $query=trim($query); if (mb_strlen($query)<2) return [];
        $term='%'.$this->normalizeSearch($query).'%';
        $stmt=$this->pdo->prepare("SELECT i.*,fv.reference_interne finea_reference,fv.chantier_id,
                c.code chantier_code,c.titre chantier_title,cl.nom_client finea_party_name
            FROM fne_sales_invoice_imports i
            LEFT JOIN facture_vente_banamur fv ON fv.id_facture_vente=i.finea_invoice_id
            LEFT JOIN chantier_banamur c ON c.id=fv.chantier_id
            LEFT JOIN client_banamur cl ON cl.id_client=i.finea_client_id
            WHERE i.search_text LIKE :q ORDER BY i.invoice_date DESC,i.id DESC LIMIT ".max(1,min(100,$limit)));
        $stmt->execute(['q'=>$term]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function latest(int $limit=100): array
    {
        return $this->pdo->query("SELECT i.*,fv.reference_interne finea_reference,fv.chantier_id,
                c.code chantier_code,c.titre chantier_title,cl.nom_client finea_party_name FROM fne_sales_invoice_imports i
            LEFT JOIN facture_vente_banamur fv ON fv.id_facture_vente=i.finea_invoice_id
            LEFT JOIN chantier_banamur c ON c.id=fv.chantier_id
            LEFT JOIN client_banamur cl ON cl.id_client=i.finea_client_id
            ORDER BY i.invoice_date DESC,i.id DESC LIMIT ".max(1,min(500,$limit)))->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function reconcileAll(int $limit=500): array
    {
        $rows=$this->pdo->query("SELECT * FROM fne_sales_invoice_imports ORDER BY id DESC LIMIT ".max(1,min(2000,$limit)))->fetchAll(PDO::FETCH_ASSOC)?:[];
        $report=['scanned'=>count($rows),'linked'=>0,'synchronized'=>0,'unmatched'=>0,'errors'=>[]];
        foreach($rows as$row){
            try{
                $link=$this->findFineaLink($row);
                $fineaId=(int)($link['id']?:($row['finea_invoice_id']??0));
                if($fineaId<=0){$report['unmatched']++;continue;}
                $this->synchronizeFineaInvoice($fineaId,$row);$report['synchronized']++;
                if((int)($row['finea_invoice_id']??0)!==$fineaId){
                    $update=$this->pdo->prepare("UPDATE fne_sales_invoice_imports SET finea_invoice_id=:finea,link_method=:method,linked_at=NOW() WHERE id=:id");
                    $update->execute(['finea'=>$fineaId,'method'=>$link['method']?:'existing_link','id'=>$row['id']]);$report['linked']++;
                }
            }catch(Throwable $e){$report['errors'][]='Vente FNE #'.(int)$row['id'].' : '.$e->getMessage();}
        }
        return$report;
    }

    public function getSelectable(int $id): array
    {
        $stmt=$this->pdo->prepare("SELECT * FROM fne_sales_invoice_imports WHERE id=:id LIMIT 1");
        $stmt->execute(['id'=>$id]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('La facture FNE sélectionnée est introuvable.');
        if (!empty($row['finea_invoice_id'])) {
            throw new RuntimeException('Cette facture FNE est déjà liée à une facture Finéa.');
        }
        return $row;
    }

    public function linkToFinea(int $importId,int $fineaInvoiceId,string $method='selection'): void
    {
        if ($importId<=0 || $fineaInvoiceId<=0) throw new RuntimeException('Liaison FNE / Finéa invalide.');
        $row=$this->getSelectable($importId);
        $check=$this->pdo->prepare("SELECT id FROM fne_sales_invoice_imports WHERE finea_invoice_id=:finea_id AND id<>:id LIMIT 1");
        $check->execute(['finea_id'=>$fineaInvoiceId,'id'=>$importId]);
        if ($check->fetchColumn()) throw new RuntimeException('Cette facture Finéa est déjà rattachée à une autre ligne FNE.');
        $stmt=$this->pdo->prepare("UPDATE fne_sales_invoice_imports
            SET finea_invoice_id=:finea_id,link_method=:method,linked_at=NOW()
            WHERE id=:id AND finea_invoice_id IS NULL");
        $stmt->execute(['finea_id'=>$fineaInvoiceId,'method'=>$method,'id'=>$importId]);
        if ($stmt->rowCount()!==1) throw new RuntimeException('La facture FNE vient déjà d’être liée.');
        $this->synchronizeFineaInvoice($fineaInvoiceId,$row);
    }

    private function readRows(string $path,string $extension): array
    {
        if ($extension==='csv') {
            $handle=fopen($path,'rb'); if (!$handle) throw new RuntimeException('Lecture CSV impossible.');
            $first=(string)fgets($handle);rewind($handle);
            $delimiter=substr_count($first,';')>=substr_count($first,',')?';':',';
            $rows=[];while(($row=fgetcsv($handle,0,$delimiter))!==false)$rows[]=$row;fclose($handle);return $rows;
        }
        require_once __DIR__.'/ZipArchiveCompatibility.php';
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) throw new RuntimeException('Lecteur Excel indisponible.');
        $sheet=\PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        return $sheet->toArray(null,true,true,false);
    }

    private function mapRow(array $r): array
    {
        $v=static fn(string $key): string => trim((string)($r[$key] ?? ''));
        return [
            'initial_reference'=>$v('reference_initiale'),'token'=>$v('token'),'fne_reference'=>$v('reference'),
            'invoice_type'=>$v('type_de_facture'),'invoice_subtype'=>$v('sous_type_de_facture'),
            'invoice_date'=>$this->date($v('date')),'payment_method'=>$v('mode_de_paiement'),
            'stamp_amount'=>$this->number($v('timbre_de_quittance')),'total_ht'=>$this->number($v('total_ht')),
            'discount_amount'=>$this->number($v('remise')),'total_vat'=>$this->number($v('total_tva')),
            'total_ttc'=>$this->number($v('total_ttc')),'other_taxes'=>$this->number($v('total_autres_taxes')),
            'net_payable'=>$this->number($v('net_a_payer')),'client_ncc'=>$v('ncc_du_client'),
            'client_name'=>$v('nom_de_la_societe_du_client'),'client_phone'=>$v('telephone_du_client'),
            'client_email'=>$v('email_du_client'),'terminal'=>$v('terminal'),'client_rccm'=>$v('rccm'),
            'seller_name'=>$v('nom_du_vendeur'),'establishment'=>$v('etablissement'),'point_of_sale'=>$v('point_de_vente'),
            'tax_regime'=>$v('regime_d_imposition'),'billing_type'=>$v('type_de_facturation'),
            'other_mentions'=>$v('autres_mentions'),'footer_text'=>$v('pied_de_page'),
            'foreign_currency'=>$v('devises_etrangeres'),'exchange_rate'=>$this->number($v('taux_de_change')) ?: null,
            'is_rne'=>in_array($this->normalizeSearch($v('est_rne')),['vrai','true','oui','1'],true)?1:0,
            'rne'=>$v('rne'),'fne_created_at'=>$this->date($v('cree_a')),'fne_updated_at'=>$this->date($v('mise_a_jour_a')),
        ];
    }

    private function findExisting(string $token,string $reference): ?array
    {
        $stmt=$this->pdo->prepare("SELECT id,raw_json,finea_invoice_id,link_method FROM fne_sales_invoice_imports WHERE (token<>'' AND token=:token) OR (fne_reference<>'' AND fne_reference=:ref) LIMIT 1");
        $stmt->execute(['token'=>$token,'ref'=>$reference]);return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function findFineaLink(array $r): array
    {
        foreach ([['fne_invoice_id',$r['token'],'token'],['fne_verification_code',$r['token'],'verification_token'],['fne_invoice_number',$r['fne_reference'],'reference'],['fne_verification_code',$r['fne_reference'],'verification_reference']] as [$column,$value,$method]) {
            if ($value==='') continue;
            $stmt=$this->pdo->prepare("SELECT id_facture_vente FROM facture_vente_banamur WHERE $column=:v LIMIT 2");
            $stmt->execute(['v'=>$value]);$ids=$stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids)===1) return ['id'=>(int)$ids[0],'method'=>$method];
        }
        return ['id'=>null,'method'=>null];
    }

    private function synchronizeFineaInvoice(int $id,array $r): void
    {
        $stmt=$this->pdo->prepare("UPDATE facture_vente_banamur SET
            fne_invoice_id=COALESCE(NULLIF(:token,''),fne_invoice_id),
            fne_invoice_number=COALESCE(NULLIF(:reference,''),fne_invoice_number),
            fne_verification_code=COALESCE(NULLIF(:token_code,''),fne_verification_code),
            invoice_date=COALESCE(DATE(:invoice_date),invoice_date),
            payment_method=COALESCE(NULLIF(:payment_method,''),payment_method),
            template=COALESCE(NULLIF(:billing_type,''),template),
            client_ncc=COALESCE(NULLIF(:client_ncc,''),client_ncc),
            client_company_name=COALESCE(NULLIF(:client_name,''),client_company_name),
            client_phone=COALESCE(NULLIF(:client_phone,''),client_phone),
            client_email=COALESCE(NULLIF(:client_email,''),client_email),
            point_of_sale=COALESCE(NULLIF(:point_of_sale,''),point_of_sale),
            establishment=COALESCE(NULLIF(:establishment,''),establishment),
            discount=:discount,total_ttc=:net_payable,
            tax_not_invoiced=IF(:total_vat>0,0,1),
            foreign_currency=COALESCE(NULLIF(:currency,''),foreign_currency),
            foreign_currency_rate=COALESCE(:exchange_rate,foreign_currency_rate),
            collected_amount=IF(paiement_statut='payee',:net_payable,collected_amount),
            fne_verified_at=NOW(),fne_verify_ok=1,fne_verify_error=NULL,updated_at=NOW()
            WHERE id_facture_vente=:id");
        $stmt->execute([
            'token'=>$r['token'],'reference'=>$r['fne_reference'],'token_code'=>$r['token'],
            'invoice_date'=>$r['invoice_date'],'payment_method'=>$r['payment_method'],
            'billing_type'=>$r['billing_type'],'client_ncc'=>$r['client_ncc'],'client_name'=>$r['client_name'],
            'client_phone'=>$r['client_phone'],'client_email'=>$r['client_email'],'point_of_sale'=>$r['point_of_sale'],
            'establishment'=>$r['establishment'],'discount'=>$r['discount_amount'],'net_payable'=>$r['net_payable'],
            'total_vat'=>$r['total_vat'],'currency'=>$r['foreign_currency'],'exchange_rate'=>$r['exchange_rate'],'id'=>$id,
        ]);
    }

    private function upsert(array $m,?int $id): void
    {
        if (($m['token'] ?? '')==='') $m['token']=null;
        if (($m['fne_reference'] ?? '')==='') $m['fne_reference']=null;
        $m['linked_at']=!empty($m['finea_invoice_id']) ? date('Y-m-d H:i:s') : null;
        $columns=array_keys($m);
        $dbMap=['batch'=>'import_batch','user_id'=>'imported_by_user_id'];
        $columns=array_map(static fn($c)=>$dbMap[$c]??$c,$columns);
        $values=array_keys($m);
        if ($id===null) {
            $sql='INSERT INTO fne_sales_invoice_imports ('.implode(',',$columns).') VALUES (:'.implode(',:',$values).')';
        } else {
            $sets=[];foreach ($columns as $i=>$column)$sets[]="$column=:".$values[$i];
            $sql='UPDATE fne_sales_invoice_imports SET '.implode(',',$sets).',imported_at=NOW() WHERE id=:row_id';
            $m['row_id']=$id;
        }
        $this->pdo->prepare($sql)->execute($m);
    }

    private function normalizeHeader($value): string
    {
        $value=$this->normalizeSearch((string)$value);
        return trim(preg_replace('/[^a-z0-9]+/','_',$value),'_');
    }
    private function normalizeSearch(string $value): string
    {
        $value=mb_strtolower(trim($value),'UTF-8');$ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        return preg_replace('/\s+/',' ',strtolower($ascii!==false?$ascii:$value));
    }

    private function containsBanamur(string $value):bool
    {
        return str_contains($this->normalizeSearch($value),'banamur');
    }
    private function number(string $value): float
    {
        $value=str_replace(["\xc2\xa0",' '],'',$value);$value=str_replace(',','.',$value);
        return is_numeric($value)?(float)$value:0.0;
    }
    private function date(string $value): ?string
    {
        if ($value==='') return null;
        try {$d=new DateTimeImmutable($value);return $d->format('Y-m-d H:i:s.v');} catch(Throwable $e){return null;}
    }
}
