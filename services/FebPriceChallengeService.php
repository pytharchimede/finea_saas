<?php
require_once __DIR__ . '/FebCategoryMigrationService.php';
final class FebPriceChallengeService
{
    private const PROCESS_START = '2026-07-01 00:00:00';
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; $this->ensureSchema(); }
    private function ensureSchema(): void
    {
        $sql = file_get_contents(__DIR__ . '/../Database/add_feb_price_challenges.sql');
        foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $statement) $this->pdo->exec($statement);
        $this->ensureColumn('feb_price_challenges', 'proposal_note', 'TEXT NULL AFTER proposed_supplier_name');
        $this->ensureColumn('feb_price_challenges', 'response_type', 'ENUM("proposal","none") NOT NULL DEFAULT "proposal" AFTER proposal_note');
        $this->ensureColumn('feb_price_challenges', 'proposed_total_amount', 'DECIMAL(18,2) NULL AFTER proposed_unit_price');
        $this->ensureColumn('feb_price_challenges', 'original_unit_price', 'DECIMAL(18,2) NULL AFTER category');
        $this->ensureColumn('feb_price_challenges', 'original_supplier_id', 'INT NULL AFTER original_unit_price');
        $this->ensureColumn('feb_price_challenges', 'original_supplier_name', 'VARCHAR(255) NULL AFTER original_unit_price');
        $this->ensureColumn('feb_price_challenges', 'original_justification_path', 'VARCHAR(500) NULL AFTER original_supplier_name');
        $this->ensureColumn('feb_price_challenges', 'archived_at', 'DATETIME NULL AFTER retained_by');
        $this->ensureColumn('feb_price_challenges', 'archive_reason', 'VARCHAR(120) NULL AFTER archived_at');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS feb_price_challenge_followups (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            challenge_id BIGINT NOT NULL,
            assigned_to_user_id INT NOT NULL,
            created_by INT NULL,
            note VARCHAR(500) NULL,
            status ENUM("open","closed") NOT NULL DEFAULT "open",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            KEY idx_fpc_followup (challenge_id,status),
            KEY idx_fpc_assignee (assigned_to_user_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS feb_price_challenge_migrations (
            migration_key VARCHAR(100) PRIMARY KEY,
            executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->migrateLegacyProposalAmounts();
        foreach (['proposed_start_date', 'proposed_end_date'] as $obsoleteColumn) {
            $column = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="feb_price_challenges" AND COLUMN_NAME=:column');
            $column->execute(['column' => $obsoleteColumn]);
            if ((int)$column->fetchColumn() > 0) $this->pdo->exec('ALTER TABLE feb_price_challenges DROP COLUMN `' . $obsoleteColumn . '`');
        }
        FebCategoryMigrationService::ensure($this->pdo);
        $this->reconcileRedundantRequests();
        $this->backfillConfiguredUsersOnce();
    }
    public function assignForFeb(int $requestId, array $payload): int
    {
        $count = 0;
        $requestStmt=$this->pdo->prepare('SELECT requester_user_id,status,request_type,created_at FROM employee_legal_requests WHERE id=:id LIMIT 1');
        $requestStmt->execute(['id'=>$requestId]);$requestMeta=$requestStmt->fetch(PDO::FETCH_ASSOC)?:[];
        if((string)($requestMeta['status']??'')!=='submitted'||(string)($requestMeta['request_type']??'')!=='feb'||(string)($requestMeta['created_at']??'')<self::PROCESS_START)return 0;
        $requesterUserId=(int)($requestMeta['requester_user_id']??0);
        $settings = $this->pdo->query('
            SELECT s.category, s.user_id
            FROM feb_price_challenger_settings s
            INNER JOIN user_devis_banamur u ON u.id = s.user_id AND u.active = 1
            INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id AND p.service_id = s.service_id
            WHERE s.active = 1
        ');
        $byCategory = [];
        foreach ($settings->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byCategory[$this->categoryKey((string) $row['category'])][] = (int) $row['user_id'];
        }
        $insert = $this->pdo->prepare('INSERT INTO feb_price_challenges (feb_request_id,line_index,category,original_unit_price,original_supplier_id,original_supplier_name,original_justification_path,challenger_user_id)
            VALUES (:request,:line,:category,:original_price,:original_supplier_id,:original_supplier,:original_proof,:user)
            ON DUPLICATE KEY UPDATE category=VALUES(category),
              original_unit_price=COALESCE(original_unit_price,VALUES(original_unit_price)),
              original_supplier_id=COALESCE(original_supplier_id,VALUES(original_supplier_id)),
              original_supplier_name=COALESCE(original_supplier_name,VALUES(original_supplier_name)),
              original_justification_path=COALESCE(original_justification_path,VALUES(original_justification_path)),
              archived_at=IF(archive_reason="feb_resynchronisee",NULL,archived_at),
              archive_reason=IF(archive_reason="feb_resynchronisee",NULL,archive_reason)');
        foreach ((array)($payload['besoins'] ?? []) as $index=>$line) {
            $category = FebCategoryMigrationService::normalize((string)($line['categorie'] ?? ''));
            $originalSupplier=(array)($line['fournisseur']??[]);
            $originalProof=(array)($line['justificatif_devis']??[]);
            foreach ($byCategory[$this->categoryKey($category)] ?? [] as $userId) {
                if($userId===$requesterUserId)continue;
                $insert->execute(['request'=>$requestId,'line'=>$index,'category'=>$category,
                    'original_price'=>(float)($originalSupplier['prix_unitaire']??0)?:null,
                    'original_supplier_id'=>(int)($originalSupplier['id']??$originalSupplier['id_fournisseur']??0)?:null,
                    'original_supplier'=>trim((string)($originalSupplier['nom']??''))?:null,
                    'original_proof'=>trim((string)($originalProof['url']??''))?:null,'user'=>$userId]);
                if ($insert->rowCount() > 0) {
                    $count++;
                    try {
                        $notify=$this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json) VALUES(NOW(),"feb_price_challenge",:sub,"Consultation de prix FEB",:message,"/employe/contestations_prix_feb.php",NULL,:user,"[\"system\",\"push\",\"in_app\"]",:payload)');
                        $notify->execute(['sub'=>$requestId.'_'.$index,'message'=>'Une consultation anonyme de prix '.$category.' vous est assignée.','user'=>$userId,'payload'=>json_encode(['feb_request_id'=>$requestId,'line_index'=>$index,'category'=>$category])]);
                    } catch(Throwable $e) {}
                }
            }
        }
        $this->reconcileRedundantRequests();
        return $count;
    }
    public function syncForFeb(int $requestId, array $payload): int
    {
        if(!$this->usesContradictoryProcess($requestId))return 0;
        $this->pdo->prepare('UPDATE feb_price_challenges SET archived_at=NOW(),archive_reason="feb_resynchronisee" WHERE feb_request_id=:id AND status="pending" AND archived_at IS NULL')->execute(['id'=>$requestId]);
        return $this->assignForFeb($requestId,$payload);
    }
    public function inbox(int $userId): array
    {
        $this->provisionExistingFebsForUser($userId);
        $stmt=$this->pdo->prepare('SELECT c.*,r.payload_json FROM feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id WHERE c.challenger_user_id=:user AND c.archived_at IS NULL AND c.challenger_user_id<>COALESCE(r.requester_user_id,0) AND c.status IN ("pending","submitted","retained","dismissed") AND (c.status<>"pending" OR r.status="submitted") AND NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED) AND ca.archived_at IS NOT NULL) ORDER BY c.created_at DESC');
        $stmt->execute(['user'=>$userId]); $out=[];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $payload=json_decode((string)$row['payload_json'],true) ?: []; $line=(array)(($payload['besoins'] ?? [])[(int)$row['line_index']] ?? []);
            $chantierId=(int)($payload['chantier_id']??0);$chantierLabel='Chantier indisponible';if($chantierId>0){$q=$this->pdo->prepare('SELECT CONCAT(COALESCE(code,"")," - ",COALESCE(titre,"")) FROM chantier_banamur WHERE id=:id AND archived_at IS NULL');$q->execute(['id'=>$chantierId]);$chantierLabel=trim((string)$q->fetchColumn())?:$chantierLabel;}
            $out[]=['id'=>(int)$row['id'],'status'=>$row['status'],'response_type'=>(string)($row['response_type']??'proposal'),'category'=>$row['category'],'chantier_id'=>$chantierId,'chantier_label'=>$chantierLabel,'quote_title'=>(string)($payload['feb_quote_title']??''),'designation'=>(string)($line['libelle']??''),'type'=>(string)($line['type']??''),'quantity'=>(float)($line['quantite']??0),'planned_start_date'=>(string)($payload['planned_start_date']??''),'planned_end_date'=>(string)($payload['planned_end_date']??''),'proposed_total_amount'=>$row['proposed_total_amount'],'proposed_unit_price'=>$row['proposed_unit_price'],'proposed_supplier_id'=>$row['proposed_supplier_id'],'proposed_supplier_name'=>$row['proposed_supplier_name'],'proposal_note'=>$row['proposal_note'],'justification_path'=>$row['justification_path'],'submitted_at'=>$row['submitted_at']];
        } return $out;
    }
    public function submit(int $challengeId,int $userId,float $price,int $supplierId,string $supplierName,string $path,string $startDate='',string $endDate='',string $note=''): bool
    {
        if ($challengeId <= 0 || $userId <= 0 || $price <= 0 || $supplierId <= 0 || trim($supplierName) === '' || trim($path) === '') {
            throw new DomainException('Prix, fournisseur validé et justificatif sont obligatoires pour cette ligne.');
        }
        $quantityStmt=$this->pdo->prepare('SELECT c.line_index,r.payload_json FROM feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id WHERE c.id=:id AND c.challenger_user_id=:user AND c.challenger_user_id<>COALESCE(r.requester_user_id,0) AND r.status="submitted" AND c.archived_at IS NULL LIMIT 1');
        $quantityStmt->execute(['id'=>$challengeId,'user'=>$userId]);$source=$quantityStmt->fetch(PDO::FETCH_ASSOC)?:[];
        $sourcePayload=json_decode((string)($source['payload_json']??''),true)?:[];
        $quantity=max(1.0,(float)(($sourcePayload['besoins']??[])[(int)($source['line_index']??-1)]['quantite']??1));
        $unitPrice=$price/$quantity;
        $stmt=$this->pdo->prepare('UPDATE feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id SET c.proposed_total_amount=:total,c.proposed_unit_price=:unit_price,c.proposed_supplier_id=:supplier,c.proposed_supplier_name=:name,c.proposal_note=:note,c.response_type="proposal",c.justification_path=:path,c.status="submitted",c.submitted_at=NOW() WHERE c.id=:id AND c.challenger_user_id=:user AND c.challenger_user_id<>COALESCE(r.requester_user_id,0) AND r.status="submitted" AND c.archived_at IS NULL AND c.status IN ("pending","submitted") AND NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED) AND ca.archived_at IS NOT NULL)');
        $ok = $stmt->execute(['total'=>$price,'unit_price'=>$unitPrice,'supplier'=>$supplierId,'name'=>trim($supplierName),'note'=>trim($note)!==''?trim($note):null,'path'=>trim($path),'id'=>$challengeId,'user'=>$userId]) && $stmt->rowCount()>0;
        if ($ok) {
            $this->pdo->prepare('UPDATE feb_price_challenge_followups SET status="closed",closed_at=NOW() WHERE challenge_id=:id AND status="open"')->execute(['id'=>$challengeId]);
            $challenge = $this->pdo->prepare('SELECT feb_request_id,line_index,category FROM feb_price_challenges WHERE id=:id');
            $challenge->execute(['id' => $challengeId]);
            $row = $challenge->fetch(PDO::FETCH_ASSOC) ?: [];
            $this->archiveRedundantPending((int)($row['feb_request_id']??0),(int)($row['line_index']??0),$challengeId);
            try {
                $notify = $this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,channels_json,payload_json) VALUES(NOW(),"feb_price_challenge_submitted",:sub,"Proposition contradictoire reçue",:message,:url,"finance","[\"in_app\"]",:payload)');
                $notify->execute([
                    'sub' => $challengeId,
                    'message' => 'Une proposition contradictoire avec justificatif a été reçue pour la ligne #' . ((int) ($row['line_index'] ?? 0) + 1) . ' (' . (string) ($row['category'] ?? '') . ').',
                    'url' => '/validation_feb_detail.php?id=' . (int) ($row['feb_request_id'] ?? 0),
                    'payload' => json_encode(['challenge_id' => $challengeId, 'feb_request_id' => (int) ($row['feb_request_id'] ?? 0)]),
                ]);
            } catch (Throwable $e) {
            }
        }
        return $ok;
    }

    public function submitNoProposal(int $challengeId, int $userId, string $note): bool
    {
        $note=trim($note);
        if($challengeId<=0||$userId<=0||$note==='')throw new DomainException('Indiquez brièvement pourquoi aucune proposition ne peut être fournie.');
        $stmt=$this->pdo->prepare('UPDATE feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id SET c.proposed_total_amount=NULL,c.proposed_unit_price=NULL,c.proposed_supplier_id=NULL,c.proposed_supplier_name=NULL,c.justification_path=NULL,c.proposal_note=:note,c.response_type="none",c.status="submitted",c.submitted_at=NOW() WHERE c.id=:id AND c.challenger_user_id=:user AND c.challenger_user_id<>COALESCE(r.requester_user_id,0) AND r.status="submitted" AND c.archived_at IS NULL AND c.status IN ("pending","submitted") AND NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED) AND ca.archived_at IS NOT NULL)');
        $ok=$stmt->execute(['note'=>$note,'id'=>$challengeId,'user'=>$userId])&&$stmt->rowCount()>0;
        if($ok){
            $this->pdo->prepare('UPDATE feb_price_challenge_followups SET status="closed",closed_at=NOW() WHERE challenge_id=:id AND status="open"')->execute(['id'=>$challengeId]);
            $q=$this->pdo->prepare('SELECT feb_request_id,line_index,category FROM feb_price_challenges WHERE id=:id');$q->execute(['id'=>$challengeId]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];
            try{$this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,channels_json,payload_json) VALUES(NOW(),"feb_price_challenge_no_proposal",:sub,"Consultation FEB sans proposition",:message,:url,"finance","[\"in_app\"]",:payload)')->execute(['sub'=>$challengeId,'message'=>'Le challenger a indiqué ne disposer d’aucune proposition pour la ligne #'.((int)($row['line_index']??0)+1).' ('.(string)($row['category']??'').').','url'=>'/validation_feb_detail.php?id='.(int)($row['feb_request_id']??0),'payload'=>json_encode(['challenge_id'=>$challengeId,'feb_request_id'=>(int)($row['feb_request_id']??0),'response_type'=>'none'])]);}catch(Throwable $e){}
        }
        return $ok;
    }
    public function reviewForFeb(int $requestId): array
    {
        $stmt=$this->pdo->prepare('SELECT c.*,u.nom,u.prenom FROM feb_price_challenges c LEFT JOIN user_devis_banamur u ON u.id=c.challenger_user_id WHERE c.feb_request_id=:id AND c.archived_at IS NULL AND c.status IN ("pending","submitted","retained","dismissed") ORDER BY c.line_index, FIELD(c.status,"retained","submitted","dismissed","pending"), c.submitted_at'); $stmt->execute(['id'=>$requestId]); return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function supervision(array $filters=[]): array
    {
        $where=['c.archived_at IS NULL','NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED) AND ca.archived_at IS NOT NULL)'];
        $params=[];
        if(!empty($filters['status'])){$where[]='c.status=:status';$params['status']=$filters['status'];}
        if(!empty($filters['category'])){$where[]='c.category=:category';$params['category']=$filters['category'];}
        if(!empty($filters['challenger'])){$where[]='c.challenger_user_id=:challenger';$params['challenger']=(int)$filters['challenger'];}
        if(!empty($filters['date_from'])){$where[]='DATE(c.created_at)>=:date_from';$params['date_from']=$filters['date_from'];}
        if(!empty($filters['date_to'])){$where[]='DATE(c.created_at)<=:date_to';$params['date_to']=$filters['date_to'];}
        $sql='SELECT c.*,r.payload_json,r.status feb_status,
              TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) challenger_name,
              sv.lib_service challenger_service,
              ca.id resolved_chantier_id,CONCAT(COALESCE(ca.code,"")," — ",COALESCE(ca.titre,"")) resolved_chantier_label,
              (SELECT COUNT(*) FROM feb_price_challenge_followups f WHERE f.challenge_id=c.id AND f.status="open") open_tickets
            FROM feb_price_challenges c
            JOIN employee_legal_requests r ON r.id=c.feb_request_id
            LEFT JOIN user_devis_banamur u ON u.id=c.challenger_user_id
            LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id
            LEFT JOIN service sv ON sv.id_service=p.service_id
            LEFT JOIN chantier_banamur ca ON ca.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,"$.chantier_id")) AS UNSIGNED)
            WHERE '.implode(' AND ',$where).' ORDER BY FIELD(c.status,"pending","submitted","retained","dismissed"),c.created_at DESC LIMIT 1000';
        $stmt=$this->pdo->prepare($sql);$stmt->execute($params);$rows=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $payload=json_decode((string)$row['payload_json'],true)?:[];$line=(array)(($payload['besoins']??[])[(int)$row['line_index']]??[]);
            $supplier=(array)($line['fournisseur']??[]);$chantierId=(int)($payload['chantier_id']??0);
            $rows[]=$row+[
                'designation'=>(string)($line['libelle']??$line['designation']??'Besoin non libellé'),
                'quantity'=>(float)($line['quantite']??0),
                'initial_unit_price'=>(float)($supplier['prix_unitaire']??0),
                'initial_total_amount'=>(float)($supplier['prix_unitaire']??0)*(float)($line['quantite']??0),
                'initial_supplier'=>(string)($supplier['nom']??''),
                'chantier_id'=>(int)($row['resolved_chantier_id']??$chantierId),
                'chantier_label'=>trim((string)($row['resolved_chantier_label']??''))?:((string)($payload['chantier_label']??$payload['chantier_titre']??('Chantier #'.$chantierId))),
                // Compatibilité d'affichage : ces valeurs viennent de la FEB globale,
                // plus jamais de la contre-proposition ou de la ligne.
                'proposed_start_date'=>(string)($payload['planned_start_date']??''),
                'proposed_end_date'=>(string)($payload['planned_end_date']??''),
            ];
        }
        return $rows;
    }

    public function createFollowup(int $challengeId,int $actorId,string $note=''): void
    {
        $q=$this->pdo->prepare('SELECT c.id,c.feb_request_id,c.line_index,c.challenger_user_id,c.status FROM feb_price_challenges c WHERE c.id=:id LIMIT 1');
        $q->execute(['id'=>$challengeId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!$row||$row['status']!=='pending')throw new DomainException('La relance concerne uniquement une proposition encore en attente.');
        $this->pdo->beginTransaction();
        try{
            $this->pdo->prepare('INSERT INTO feb_price_challenge_followups(challenge_id,assigned_to_user_id,created_by,note) VALUES(:challenge,:user,:actor,:note)')
                ->execute(['challenge'=>$challengeId,'user'=>(int)$row['challenger_user_id'],'actor'=>$actorId?:null,'note'=>trim($note)!==''?trim($note):null]);
            $this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json)
                VALUES(NOW(),"feb_price_challenge_followup",:sub,"Relance · proposition FEB attendue",:message,"/employe/contestations_prix_feb.php",:user,"[\"system\",\"push\",\"in_app\"]",:payload)')
                ->execute(['sub'=>$challengeId.'_'.date('YmdHis'),'message'=>'Votre proposition contradictoire pour la FEB #'.(int)$row['feb_request_id'].' est attendue. '.trim($note),'user'=>(int)$row['challenger_user_id'],'payload'=>json_encode(['challenge_id'=>$challengeId,'feb_request_id'=>(int)$row['feb_request_id']])]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function syncConfiguredUsersForExistingFebs(): int
    {
        $users=$this->pdo->query('SELECT DISTINCT user_id FROM feb_price_challenger_settings WHERE active=1')->fetchAll(PDO::FETCH_COLUMN)?:[];
        $created=0;
        foreach($users as $userId)$created+=$this->provisionExistingFebsForUser((int)$userId,false);
        $this->reconcileRedundantRequests();
        return $created;
    }

    public function coverageForFeb(int $requestId, int $lineCount): array
    {
        $stmt = $this->pdo->prepare('SELECT line_index, COUNT(*) assigned_count, SUM(status IN ("submitted","retained")) submitted_count FROM feb_price_challenges WHERE feb_request_id=:id AND archived_at IS NULL AND status<>"dismissed" GROUP BY line_index');
        $stmt->execute(['id' => $requestId]);
        $indexed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $indexed[(int) $row['line_index']] = ['assigned' => (int) $row['assigned_count'], 'submitted' => (int) $row['submitted_count']];
        }
        $result = [];
        for ($index = 0; $index < $lineCount; $index++) {
            $result[$index] = $indexed[$index] ?? ['assigned' => 0, 'submitted' => 0];
        }
        return $result;
    }
    public function retain(int $challengeId,int $actorId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('
                SELECT c.*, r.payload_json, r.requester_user_id,
                       u.personnel_id AS challenger_personnel_id,
                       TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) challenger_name
                FROM feb_price_challenges c
                INNER JOIN employee_legal_requests r ON r.id=c.feb_request_id
                LEFT JOIN user_devis_banamur u ON u.id=c.challenger_user_id
                WHERE c.id=:id AND c.status IN ("submitted","retained","dismissed")
                FOR UPDATE
            ');
            $q->execute(['id'=>$challengeId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if(!$row) throw new RuntimeException('Proposition introuvable.');
            if((string)($row['response_type']??'proposal')==='none'||(float)($row['proposed_total_amount']??0)<=0)throw new RuntimeException('Cette réponse ne contient aucune proposition à retenir.');

            $requestId=(int)$row['feb_request_id'];
            $lineIndex=(int)$row['line_index'];
            $payload=json_decode((string)$row['payload_json'],true);
            if(!is_array($payload) || !isset($payload['besoins'][$lineIndex])) {
                throw new RuntimeException('La ligne FEB liée à cette proposition est introuvable.');
            }
            $line=(array)$payload['besoins'][$lineIndex];
            $quantity=max(1.0,(float)($line['quantite']??1));
            $proposedTotal=(float)($row['proposed_total_amount']??0);
            $proposedUnitPrice=(float)($row['proposed_unit_price']??0);
            if($proposedTotal<=0)$proposedTotal=$proposedUnitPrice*$quantity;
            if($proposedUnitPrice<=0)$proposedUnitPrice=$proposedTotal/$quantity;
            $line['fournisseur']=[
                'id'=>(int)$row['proposed_supplier_id'],
                'nom'=>(string)$row['proposed_supplier_name'],
                'prix_unitaire'=>$proposedUnitPrice,
                'montant_total_propose'=>$proposedTotal,
                'telephone'=>(string)(($line['fournisseur']??[])['telephone']??''),
            ];
            $line['proposition_retenue']=[
                'type'=>'contradictoire',
                'challenge_id'=>$challengeId,
                'retained_at'=>date('c'),
                'retained_by'=>$actorId,
                'justification_path'=>(string)$row['justification_path'],
            ];
            $payload['besoins'][$lineIndex]=$line;
            $total=0.0;
            foreach((array)$payload['besoins'] as $need) {
                $supplier=(array)($need['fournisseur']??[]);
                $total+=(float)($need['quantite']??0)*(float)($supplier['prix_unitaire']??0);
            }
            $payload['montant']=$total;
            $payload['provisional_amount']=$total;
            $lineAmount=$proposedTotal;
            $draft=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
            $draft['beneficiary_mode']='personnel';
            $draft['personnel_id']=(int)($row['challenger_personnel_id']??0);
            $draft['beneficiaire_fiche']=trim((string)($row['challenger_name']??''));
            $draft['beneficiary_name']=$draft['beneficiaire_fiche'];
            $draft['fournisseur_id']=(int)$row['proposed_supplier_id'];
            $draft['fournisseur_nom']=(string)$row['proposed_supplier_name'];
            $draft['montant_fiche']=$lineAmount;
            $draft['details_demande']=(string)($line['libelle']??'').' — proposition retenue de '.$draft['beneficiaire_fiche'];
            $draft['date_decaissement_minimum']=(string)($payload['planned_start_date']??$draft['date_decaissement_minimum']??'');
            $draft['selected_price_challenge_id']=$challengeId;
            $draft['selected_price_challenge_at']=date('c');
            $draft['selected_price_challenge_by']=$actorId;
            $payload['provisional_decaissement']=$draft;

            $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload,updated_at=NOW() WHERE id=:id')
                ->execute(['payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$requestId]);
            $this->pdo->prepare('UPDATE feb_quote_lines SET unit_price=:price,supplier_id=:supplier,supplier_name_snapshot=:name WHERE feb_request_id=:request AND line_index=:line')
                ->execute(['price'=>$proposedUnitPrice,'supplier'=>(int)$row['proposed_supplier_id'],'name'=>(string)$row['proposed_supplier_name'],'request'=>$requestId,'line'=>$lineIndex]);
            $this->pdo->prepare('UPDATE feb_price_challenges SET status="dismissed",retained_at=NULL,retained_by=NULL WHERE feb_request_id=:r AND line_index=:l AND id<>:id AND status IN ("submitted","retained")')
                ->execute(['r'=>$requestId,'l'=>$lineIndex,'id'=>$challengeId]);
            $this->pdo->prepare('UPDATE feb_price_challenges SET status="retained",retained_at=NOW(),retained_by=:actor WHERE id=:id')
                ->execute(['actor'=>$actorId,'id'=>$challengeId]);
            $this->archiveAllPendingForLine($requestId,$lineIndex,'proposition_selectionnee');
            $this->pdo->commit();
            $this->notifyDecision($requestId,$lineIndex,$challengeId,(int)$row['requester_user_id'],[
                'need'=>(string)($line['libelle']??'Besoin FEB'),'amount'=>$lineAmount,
                'supplier'=>(string)$row['proposed_supplier_name'],'start'=>(string)($payload['planned_start_date']??''),
                'end'=>(string)($payload['planned_end_date']??''),'winner'=>(string)($row['challenger_name']??''),
            ]);
            return true;
        } catch(Throwable $e) {
            if($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function retainInitial(int $requestId,int $lineIndex,int $actorId): void
    {
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT r.payload_json,r.requester_user_id,u.personnel_id requester_personnel_id,
                TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) requester_name
                FROM employee_legal_requests r LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id WHERE r.id=:id FOR UPDATE');
            $q->execute(['id'=>$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);
            $payload=json_decode((string)($request['payload_json']??''),true);
            if(!$request||!is_array($payload)||!isset($payload['besoins'][$lineIndex]))throw new DomainException('La proposition initiale est introuvable.');
            $initial=$this->pdo->prepare('SELECT original_unit_price,original_supplier_id,original_supplier_name,original_justification_path FROM feb_price_challenges WHERE feb_request_id=:request AND line_index=:line AND original_unit_price IS NOT NULL ORDER BY id LIMIT 1');
            $initial->execute(['request'=>$requestId,'line'=>$lineIndex]);$snapshot=$initial->fetch(PDO::FETCH_ASSOC)?:[];
            if($snapshot){
                $payload['besoins'][$lineIndex]['fournisseur']['prix_unitaire']=(float)$snapshot['original_unit_price'];
                $payload['besoins'][$lineIndex]['fournisseur']['nom']=(string)$snapshot['original_supplier_name'];
                if(!empty($snapshot['original_justification_path']))$payload['besoins'][$lineIndex]['justificatif_devis']['url']=(string)$snapshot['original_justification_path'];
                $this->pdo->prepare('UPDATE feb_quote_lines SET unit_price=:price,supplier_name_snapshot=:supplier WHERE feb_request_id=:request AND line_index=:line')
                    ->execute(['price'=>(float)$snapshot['original_unit_price'],'supplier'=>(string)$snapshot['original_supplier_name'],'request'=>$requestId,'line'=>$lineIndex]);
            }
            $payload['besoins'][$lineIndex]['proposition_retenue']=['type'=>'initiale','retained_at'=>date('c'),'retained_by'=>$actorId];
            $total=0.0;foreach((array)$payload['besoins'] as $need)$total+=(float)($need['quantite']??0)*(float)(($need['fournisseur']??[])['prix_unitaire']??0);
            $payload['montant']=$total;$payload['provisional_amount']=$total;
            $line=(array)$payload['besoins'][$lineIndex];$lineAmount=(float)($line['quantite']??0)*(float)(($line['fournisseur']??[])['prix_unitaire']??0);
            $draft=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
            $draft['beneficiary_mode']='personnel';$draft['personnel_id']=(int)($request['requester_personnel_id']??0);
            $draft['beneficiaire_fiche']=trim((string)($request['requester_name']??''));$draft['beneficiary_name']=$draft['beneficiaire_fiche'];
            if(!empty($snapshot['original_supplier_id']))$draft['fournisseur_id']=(int)$snapshot['original_supplier_id'];
            $draft['fournisseur_nom']=(string)($snapshot['original_supplier_name']??'');$draft['montant_fiche']=$lineAmount;
            $draft['details_demande']=(string)($line['libelle']??'').' — proposition initiale maintenue';
            $draft['selected_price_challenge_id']=null;$draft['selected_price_challenge_at']=date('c');$draft['selected_price_challenge_by']=$actorId;
            $payload['provisional_decaissement']=$draft;
            $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload,updated_at=NOW() WHERE id=:id')->execute([
                'payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$requestId
            ]);
            $this->pdo->prepare('UPDATE feb_price_challenges SET status=IF(status="retained","submitted",status),retained_at=NULL,retained_by=NULL WHERE feb_request_id=:request AND line_index=:line')
                ->execute(['request'=>$requestId,'line'=>$lineIndex]);
            $this->archiveAllPendingForLine($requestId,$lineIndex,'proposition_initiale_selectionnee');
            $this->pdo->commit();
            $this->notifyDecision($requestId,$lineIndex,0,(int)($request['requester_user_id']??0));
        } catch(Throwable $e) {
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Retient en une seule décision toutes les propositions initiales d'une FEB.
     * La méthode respecte une transaction déjà ouverte par le workflow
     * d'approbation afin que le choix de prix et l'approbation restent atomiques.
     */
    public function closeForCurrentApproval(int $requestId): void
    {
        if (!$this->pdo->inTransaction()) throw new LogicException('La clôture des consultations doit être transactionnelle.');
        $q=$this->pdo->prepare('SELECT status,payload_json FROM employee_legal_requests WHERE id=:id AND request_type="feb" FOR UPDATE');
        $q->execute(['id'=>$requestId]);
        $request=$q->fetch(PDO::FETCH_ASSOC);
        if (!$request || !in_array($request['status'],['submitted','details_requested'],true)) throw new DomainException('Cette FEB est déjà traitée ou indisponible.');
        $payload=json_decode((string)$request['payload_json'],true);
        if (!is_array($payload) || !is_finite((float)($payload['montant']??0)) || (float)($payload['montant']??0)<=0) throw new DomainException('Le montant final enregistré de la FEB est invalide.');
        // Close consultations only. Never rebuild the FEB from historical price snapshots.
        $this->pdo->prepare('UPDATE feb_price_challenges
            SET status=CASE WHEN status IN ("pending","submitted") THEN "dismissed" ELSE status END,
                archived_at=COALESCE(archived_at,NOW()),archive_reason="approbation_valeurs_courantes"
            WHERE feb_request_id=:request AND status<>"retained"')
            ->execute(['request'=>$requestId]);
        $this->pdo->prepare('UPDATE feb_price_challenge_followups f
            JOIN feb_price_challenges c ON c.id=f.challenge_id
            SET f.status="closed",f.closed_at=NOW()
            WHERE c.feb_request_id=:request AND f.status="open"')
            ->execute(['request'=>$requestId]);
    }

    /** Legacy explicit restoration helper; never invoked by the approval workflow. */
    public function retainAllInitial(int $requestId,int $actorId): void
    {
        $ownsTransaction=!$this->pdo->inTransaction();
        if($ownsTransaction)$this->pdo->beginTransaction();
        try{
            $q=$this->pdo->prepare('SELECT r.payload_json,r.requester_user_id,u.personnel_id requester_personnel_id,
                TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) requester_name
                FROM employee_legal_requests r
                LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id
                WHERE r.id=:id AND r.request_type="feb" FOR UPDATE');
            $q->execute(['id'=>$requestId]);$request=$q->fetch(PDO::FETCH_ASSOC);
            $payload=json_decode((string)($request['payload_json']??''),true);
            $needs=is_array($payload['besoins']??null)?array_values($payload['besoins']):[];
            if(!$request||!is_array($payload)||!$needs)throw new DomainException('Les propositions initiales de cette FEB sont introuvables.');

            $snapshots=$this->pdo->prepare('SELECT line_index,original_unit_price,original_supplier_id,original_supplier_name,original_justification_path
                FROM feb_price_challenges
                WHERE feb_request_id=:request AND original_unit_price IS NOT NULL
                ORDER BY id');
            $snapshots->execute(['request'=>$requestId]);$byLine=[];
            foreach($snapshots->fetchAll(PDO::FETCH_ASSOC)?:[] as $snapshot){
                $index=(int)$snapshot['line_index'];
                if(!isset($byLine[$index]))$byLine[$index]=$snapshot;
            }

            $quoteUpdate=$this->pdo->prepare('UPDATE feb_quote_lines
                SET unit_price=:price,supplier_id=:supplier_id,supplier_name_snapshot=:supplier
                WHERE feb_request_id=:request AND line_index=:line');
            $total=0.0;$firstSupplierId=0;$firstSupplierName='';
            foreach($needs as $lineIndex=>&$line){
                $line=is_array($line)?$line:[];
                $snapshot=(array)($byLine[$lineIndex]??[]);
                $supplier=is_array($line['fournisseur']??null)?(array)$line['fournisseur']:[];
                $unitPrice=(float)($snapshot['original_unit_price']??$supplier['prix_unitaire']??0);
                $supplierId=(int)($snapshot['original_supplier_id']??$supplier['id']??$supplier['id_fournisseur']??0);
                $supplierName=trim((string)($snapshot['original_supplier_name']??$supplier['nom']??$supplier['raison_sociale']??''));
                if($unitPrice<=0)throw new DomainException('Le prix initial de la ligne FEB #'.($lineIndex+1).' est invalide.');
                $supplier['prix_unitaire']=$unitPrice;
                if($supplierId>0)$supplier['id']=$supplierId;
                if($supplierName!=='')$supplier['nom']=$supplierName;
                $line['fournisseur']=$supplier;
                if(!empty($snapshot['original_justification_path']))$line['justificatif_devis']['url']=(string)$snapshot['original_justification_path'];
                $line['proposition_retenue']=[
                    'type'=>'initiale',
                    'decision'=>'approbation_sans_contradiction',
                    'retained_at'=>date('c'),
                    'retained_by'=>$actorId,
                ];
                $quantity=max(0,(float)($line['quantite']??0));
                $total+=$quantity*$unitPrice;
                if($firstSupplierId<=0&&$supplierId>0){$firstSupplierId=$supplierId;$firstSupplierName=$supplierName;}
                $quoteUpdate->execute([
                    'price'=>$unitPrice,'supplier_id'=>$supplierId?:null,'supplier'=>$supplierName,
                    'request'=>$requestId,'line'=>$lineIndex,
                ]);
            }
            unset($line);
            $payload['besoins']=$needs;
            $payload['montant']=$total;
            $payload['provisional_amount']=$total;
            $payload['price_selection']=[
                'type'=>'initiale',
                'scope'=>'all_lines',
                'reason'=>'approbation_sans_proposition_contradictoire',
                'selected_at'=>date('c'),
                'selected_by'=>$actorId,
            ];
            $draft=is_array($payload['provisional_decaissement']??null)?(array)$payload['provisional_decaissement']:[];
            // Retaining prices must not replace a beneficiary explicitly saved by Finance.
            // Historical line suppliers remain in besoins and quote snapshots for traceability.
            if (!in_array((string)($draft['beneficiary_mode']??''), ['supplier','personnel','external'], true)) {
                $draft['beneficiary_mode']='personnel';
                $draft['personnel_id']=(int)($request['requester_personnel_id']??0);
                $draft['beneficiaire_fiche']=trim((string)($request['requester_name']??''));
                $draft['beneficiary_name']=$draft['beneficiaire_fiche'];
                if($firstSupplierId>0)$draft['fournisseur_id']=$firstSupplierId;
                if($firstSupplierName!=='')$draft['fournisseur_nom']=$firstSupplierName;
            }
            $draft['montant_fiche']=$total;
            $draft['selected_price_challenge_id']=null;
            $draft['selected_price_challenge_at']=date('c');
            $draft['selected_price_challenge_by']=$actorId;
            $draft['price_selection_mode']='initial_requester_price';
            $payload['provisional_decaissement']=$draft;

            $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload,updated_at=NOW() WHERE id=:id')
                ->execute(['payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'id'=>$requestId]);
            $this->pdo->prepare('UPDATE feb_price_challenges
                SET status=CASE WHEN status IN ("pending","submitted","retained") THEN "dismissed" ELSE status END,
                    retained_at=NULL,retained_by=NULL,
                    archived_at=COALESCE(archived_at,NOW()),
                    archive_reason="approbation_prix_initial_sans_contradiction"
                WHERE feb_request_id=:request')
                ->execute(['request'=>$requestId]);
            $this->pdo->prepare('UPDATE feb_price_challenge_followups f
                JOIN feb_price_challenges c ON c.id=f.challenge_id
                SET f.status="closed",f.closed_at=NOW()
                WHERE c.feb_request_id=:request AND f.status="open"')
                ->execute(['request'=>$requestId]);
            foreach(array_keys($needs) as $lineIndex){
                $this->notifyDecision($requestId,(int)$lineIndex,0,(int)($request['requester_user_id']??0));
            }
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $e){
            if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private function notifyDecision(int $requestId,int $lineIndex,int $winnerId,int $requesterId,array $context=[]): void
    {
        try {
            $stmt=$this->pdo->prepare('SELECT id,challenger_user_id,status FROM feb_price_challenges WHERE feb_request_id=:request AND line_index=:line AND proposed_total_amount>0 AND status IN ("submitted","retained","dismissed")');
            $stmt->execute(['request'=>$requestId,'line'=>$lineIndex]);
            $insert=$this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),"feb_price_challenge_decision",:sub,:title,:message,"/employe/contestations_prix_feb.php",:user,"[\"in_app\"]",:payload)');
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $proposal) {
                $won=(int)$proposal['id']===$winnerId;
                $amount=number_format((float)($context['amount']??0),0,',',' ');
                $winnerMessage='Votre proposition a été retenue pour « '.(string)($context['need']??'la ligne FEB').' »'
                    .($amount!=='0'?' pour '.$amount.' FCFA':'')
                    .(!empty($context['supplier'])?', fournisseur : '.$context['supplier']:'')
                    .(!empty($context['start'])?', période prévue : '.$context['start'].' au '.($context['end']?:$context['start']):'')
                    .'. Préparez le devis original, la facture ou pièce fournisseur, les coordonnées de paiement et les justificatifs nécessaires. Consultez la FEB avant le décaissement.';
                $insert->execute([
                    'sub'=>$requestId.'_'.$lineIndex.'_'.(int)$proposal['id'],
                    'title'=>$won?'Votre proposition a été retenue':'Décision sur votre proposition FEB',
                    'message'=>$won
                        ? $winnerMessage
                        : ($winnerId>0?'Une autre proposition a été retenue pour cette ligne FEB. Votre proposition n’a pas été retenue. Merci pour votre participation.':'La proposition initiale du demandeur a été maintenue. Votre contre-proposition n’a pas été retenue. Merci pour votre participation.'),
                    'user'=>(int)$proposal['challenger_user_id'],
                    'payload'=>json_encode(['feb_request_id'=>$requestId,'line_index'=>$lineIndex,'challenge_id'=>(int)$proposal['id'],'retained'=>$won]),
                ]);
            }
            if($requesterId>0) {
                $insert->execute([
                    'sub'=>$requestId.'_'.$lineIndex.'_requester',
                    'title'=>'Devis retenu pour votre FEB',
                    'message'=>'La proposition financière de la ligne #'.($lineIndex+1).' a été arrêtée par les validateurs'
                        .($winnerId>0?' en faveur de '.(string)($context['winner']??'un soumissionnaire interne'):' : votre proposition initiale est maintenue')
                        .'. Le montant, le fournisseur, le bénéficiaire et le planning de la future fiche ont été synchronisés.',
                    'user'=>$requesterId,
                    'payload'=>json_encode(['feb_request_id'=>$requestId,'line_index'=>$lineIndex,'challenge_id'=>$winnerId,'retained'=>false]),
                ]);
            }
        } catch(Throwable $e) {
        }
    }

    private function archiveRedundantPending(int $requestId,int $lineIndex,int $submittedChallengeId): void
    {
        if($requestId<=0||$submittedChallengeId<=0)return;
        $service=$this->pdo->prepare('SELECT p.service_id FROM feb_price_challenges c JOIN user_devis_banamur u ON u.id=c.challenger_user_id JOIN personnel p ON p.id_personnel_soignant=u.personnel_id WHERE c.id=:id LIMIT 1');
        $service->execute(['id'=>$submittedChallengeId]);$serviceId=(int)$service->fetchColumn();
        if($serviceId>0){
            $this->pdo->prepare('UPDATE feb_price_challenges c JOIN user_devis_banamur u ON u.id=c.challenger_user_id JOIN personnel p ON p.id_personnel_soignant=u.personnel_id SET c.archived_at=NOW(),c.archive_reason="reponse_service_recue" WHERE c.feb_request_id=:request AND c.line_index=:line AND c.id<>:id AND c.status="pending" AND c.archived_at IS NULL AND p.service_id=:service')
                ->execute(['request'=>$requestId,'line'=>$lineIndex,'id'=>$submittedChallengeId,'service'=>$serviceId]);
        }
        $count=$this->pdo->prepare('SELECT COUNT(*) FROM feb_price_challenges WHERE feb_request_id=:request AND line_index=:line AND archived_at IS NULL AND proposed_total_amount>0 AND status IN ("submitted","retained","dismissed")');
        $count->execute(['request'=>$requestId,'line'=>$lineIndex]);
        if((int)$count->fetchColumn()>=2)$this->archiveAllPendingForLine($requestId,$lineIndex,'quota_deux_propositions_atteint');
    }

    private function archiveAllPendingForLine(int $requestId,int $lineIndex,string $reason): void
    {
        $this->pdo->prepare('UPDATE feb_price_challenges SET archived_at=NOW(),archive_reason=:reason WHERE feb_request_id=:request AND line_index=:line AND status="pending" AND archived_at IS NULL')
            ->execute(['reason'=>$reason,'request'=>$requestId,'line'=>$lineIndex]);
    }

    private function reconcileRedundantRequests(): void
    {
        $this->pdo->exec('UPDATE feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id SET c.archived_at=NOW(),c.archive_reason="propre_demande_du_challenger" WHERE c.archived_at IS NULL AND c.challenger_user_id=COALESCE(r.requester_user_id,0)');
        $this->pdo->exec('UPDATE feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id SET c.archived_at=NOW(),c.archive_reason="feb_deja_traitee" WHERE c.archived_at IS NULL AND c.status="pending" AND r.status<>"submitted"');
        $answered=$this->pdo->query('SELECT id,feb_request_id,line_index FROM feb_price_challenges WHERE archived_at IS NULL AND proposed_total_amount>0 AND status IN ("submitted","retained","dismissed") ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($answered as $row)$this->archiveRedundantPending((int)$row['feb_request_id'],(int)$row['line_index'],(int)$row['id']);
        $selected=$this->pdo->query('SELECT DISTINCT feb_request_id,line_index FROM feb_price_challenges WHERE status="retained"')->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($selected as $row)$this->archiveAllPendingForLine((int)$row['feb_request_id'],(int)$row['line_index'],'proposition_selectionnee');
        $requests=$this->pdo->query('SELECT DISTINCT r.id,r.payload_json FROM employee_legal_requests r JOIN feb_price_challenges c ON c.feb_request_id=r.id AND c.status="pending" AND c.archived_at IS NULL WHERE r.payload_json IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($requests as $request){
            $payload=json_decode((string)$request['payload_json'],true)?:[];
            foreach((array)($payload['besoins']??[]) as $lineIndex=>$line){
                if(!empty($line['proposition_retenue']))$this->archiveAllPendingForLine((int)$request['id'],(int)$lineIndex,'proposition_selectionnee');
            }
        }
    }

    private function provisionExistingFebsForUser(int $userId,bool $reconcile=true): int
    {
        if($userId<=0)return 0;
        $settings=$this->pdo->prepare('SELECT DISTINCT s.category,s.service_id FROM feb_price_challenger_settings s JOIN user_devis_banamur u ON u.id=s.user_id AND u.active=1 JOIN personnel p ON p.id_personnel_soignant=u.personnel_id AND p.service_id=s.service_id WHERE s.user_id=:user AND s.active=1');
        $settings->execute(['user'=>$userId]);$allowed=[];
        foreach($settings->fetchAll(PDO::FETCH_ASSOC)?:[] as $setting)$allowed[$this->categoryKey((string)$setting['category'])]=(int)$setting['service_id'];
        if(!$allowed)return 0;
        $requests=$this->pdo->query('SELECT id,payload_json,requester_user_id FROM employee_legal_requests WHERE request_type="feb" AND status="submitted" AND created_at>="'.self::PROCESS_START.'" ORDER BY id DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $check=$this->pdo->prepare('SELECT
            EXISTS(SELECT 1 FROM feb_price_challenges WHERE feb_request_id=:request AND line_index=:line AND status="retained") selected,
            (SELECT COUNT(*) FROM feb_price_challenges WHERE feb_request_id=:request2 AND line_index=:line2 AND archived_at IS NULL AND proposed_total_amount>0 AND status IN ("submitted","retained","dismissed")) proposal_count,
            EXISTS(SELECT 1 FROM feb_price_challenges c JOIN user_devis_banamur u ON u.id=c.challenger_user_id JOIN personnel p ON p.id_personnel_soignant=u.personnel_id WHERE c.feb_request_id=:request3 AND c.line_index=:line3 AND c.archived_at IS NULL AND c.proposed_total_amount>0 AND c.status IN ("submitted","retained","dismissed") AND p.service_id=:service) service_answered');
        $insert=$this->pdo->prepare('INSERT INTO feb_price_challenges(feb_request_id,line_index,category,original_unit_price,original_supplier_id,original_supplier_name,original_justification_path,challenger_user_id) VALUES(:request,:line,:category,:price,:supplier_id,:supplier,:proof,:user) ON DUPLICATE KEY UPDATE category=VALUES(category),archived_at=IF(archive_reason="feb_resynchronisee",NULL,archived_at),archive_reason=IF(archive_reason="feb_resynchronisee",NULL,archive_reason)');
        $notify=$this->pdo->prepare('INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_user_id,channels_json,payload_json) VALUES(NOW(),"feb_price_challenge",:sub,"Consultation de prix FEB",:message,"/employe/contestations_prix_feb.php",:user,"[\"system\",\"push\",\"in_app\"]",:payload)');
        $created=0;
        foreach($requests as $request){
            if((int)($request['requester_user_id']??0)===$userId)continue;
            $payload=json_decode((string)$request['payload_json'],true)?:[];
            foreach((array)($payload['besoins']??[]) as $index=>$line){
                $category=FebCategoryMigrationService::normalize((string)($line['categorie']??''));$key=$this->categoryKey($category);
                if(!isset($allowed[$key]))continue;
                if(!empty($line['proposition_retenue']))continue;
                $check->execute(['request'=>(int)$request['id'],'line'=>$index,'request2'=>(int)$request['id'],'line2'=>$index,'request3'=>(int)$request['id'],'line3'=>$index,'service'=>$allowed[$key]]);
                $state=$check->fetch(PDO::FETCH_ASSOC)?:[];
                if(!empty($state['selected'])||(int)($state['proposal_count']??0)>=2||!empty($state['service_answered']))continue;
                $supplier=(array)($line['fournisseur']??[]);$proof=(array)($line['justificatif_devis']??[]);
                $insert->execute(['request'=>(int)$request['id'],'line'=>$index,'category'=>$category,'price'=>(float)($supplier['prix_unitaire']??0)?:null,'supplier_id'=>(int)($supplier['id']??$supplier['id_fournisseur']??0)?:null,'supplier'=>trim((string)($supplier['nom']??''))?:null,'proof'=>trim((string)($proof['url']??''))?:null,'user'=>$userId]);
                if($insert->rowCount()>0){
                    $created++;
                    try{$notify->execute(['sub'=>(int)$request['id'].'_'.$index.'_late_'.$userId,'message'=>'Une consultation anonyme de prix '.$category.' déjà en cours est désormais accessible avec votre nouvelle autorisation.','user'=>$userId,'payload'=>json_encode(['feb_request_id'=>(int)$request['id'],'line_index'=>$index,'category'=>$category])]);}catch(Throwable $e){}
                }
            }
        }
        if($reconcile)$this->reconcileRedundantRequests();
        return $created;
    }

    private function backfillConfiguredUsersOnce(): void
    {
        $key='backfill_existing_feb_challengers_v1';
        $check=$this->pdo->prepare('SELECT 1 FROM feb_price_challenge_migrations WHERE migration_key=:key');
        $check->execute(['key'=>$key]);
        if($check->fetchColumn())return;
        $this->syncConfiguredUsersForExistingFebs();
        $this->pdo->prepare('INSERT IGNORE INTO feb_price_challenge_migrations(migration_key) VALUES(:key)')->execute(['key'=>$key]);
    }

    private function usesContradictoryProcess(int $requestId): bool
    {
        $stmt=$this->pdo->prepare('SELECT 1 FROM employee_legal_requests WHERE id=:id AND request_type="feb" AND created_at>=:start LIMIT 1');
        $stmt->execute(['id'=>$requestId,'start'=>self::PROCESS_START]);
        return (bool)$stmt->fetchColumn();
    }

    private function categoryKey(string $category): string
    {
        $category = FebCategoryMigrationService::normalize($category);
        return function_exists('mb_strtolower') ? mb_strtolower($category, 'UTF-8') : strtolower($category);
    }

    private function ensureColumn(string $table, string $column, string $definition): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column');
        $stmt->execute(['table' => $table, 'column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {$this->pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);return true;}
        return false;
    }

    private function migrateLegacyProposalAmounts(): void
    {
        $rows=$this->pdo->query('SELECT c.id,c.line_index,c.proposed_unit_price,r.payload_json FROM feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id WHERE c.proposed_unit_price IS NOT NULL AND c.proposed_total_amount IS NULL')->fetchAll(PDO::FETCH_ASSOC)?:[];
        $update=$this->pdo->prepare('UPDATE feb_price_challenges SET proposed_total_amount=:total,proposed_unit_price=:unit WHERE id=:id');
        foreach($rows as $row){$payload=json_decode((string)$row['payload_json'],true)?:[];$quantity=max(1.0,(float)(($payload['besoins']??[])[(int)$row['line_index']]['quantite']??1));$total=(float)$row['proposed_unit_price'];$update->execute(['total'=>$total,'unit'=>$total/$quantity,'id'=>(int)$row['id']]);}
    }
}

