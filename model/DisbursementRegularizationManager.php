<?php

require_once __DIR__ . '/NotificationManager.php';

final class DisbursementRegularizationManager
{
    private const FEB_REGULARIZATION_START = '2026-07-01 00:00:00';
    private PDO $pdo;
    private static ?WeakMap $schemaEnsured = null;

    public function __construct(PDO $pdo, bool $prepareProofStorage = true)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
        if ($prepareProofStorage) $this->ensureProofStorage();
    }

    private function ensureTable(): void
    {
        self::$schemaEnsured ??= new WeakMap();
        if (isset(self::$schemaEnsured[$this->pdo])) return;
        $this->ensureFebSourceSchema();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS disbursement_regularizations (
            id INT NOT NULL AUTO_INCREMENT,
            num_fiche VARCHAR(60) NOT NULL,
            requester_user_id INT NOT NULL,
            reception_kind ENUM('travaux','fourniture','prestation','autre') NOT NULL DEFAULT 'travaux',
            reception_status ENUM('without_reserve','with_reserve','not_received','pending') NOT NULL DEFAULT 'pending',
            project_name VARCHAR(255) NOT NULL,
            project_location VARCHAR(255) NULL,
            owner_name VARCHAR(255) NULL,
            executing_company VARCHAR(255) NULL,
            reception_date DATE NULL,
            effect_date DATE NULL,
            observations TEXT NULL,
            reserves TEXT NULL,
            reserve_deadline_days INT NULL,
            remaining_actions TEXT NULL,
            validated_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            signature_data MEDIUMTEXT NULL,
            signer_name VARCHAR(255) NULL,
            signed_at DATETIME NULL,
            payment_proof_path VARCHAR(500) NULL,
            payment_proof_name VARCHAR(255) NULL,
            payment_proof_mime VARCHAR(120) NULL,
            payment_proof_size INT NULL,
            submitted_at DATETIME NULL,
            accounting_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            accounting_note TEXT NULL,
            accounting_validated_by INT NULL,
            accounting_validator_name VARCHAR(255) NULL,
            accounting_validated_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancelled_by INT NULL,
            cancelled_by_name VARCHAR(190) NULL,
            cancellation_reason VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_regularization_fiche (num_fiche),
            KEY idx_regularization_requester (requester_user_id),
            KEY idx_regularization_status (accounting_status, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $columns = $this->pdo->query("SHOW COLUMNS FROM disbursement_regularizations")->fetchAll(PDO::FETCH_COLUMN, 0);
        $alter = [];
        if (!in_array('access_blocked_at', $columns, true)) $alter[] = 'ADD COLUMN access_blocked_at DATETIME NULL AFTER accounting_validated_at';
        if (!in_array('access_blocked_by', $columns, true)) $alter[] = 'ADD COLUMN access_blocked_by INT NULL AFTER access_blocked_at';
        if (!in_array('access_blocked_by_name', $columns, true)) $alter[] = 'ADD COLUMN access_blocked_by_name VARCHAR(255) NULL AFTER access_blocked_by';
        if (!in_array('access_block_reason', $columns, true)) $alter[] = 'ADD COLUMN access_block_reason TEXT NULL AFTER access_blocked_by_name';
        if (!in_array('signer_user_id', $columns, true)) $alter[] = 'ADD COLUMN signer_user_id INT NULL AFTER signer_name';
        if (!in_array('signer_mode', $columns, true)) $alter[] = "ADD COLUMN signer_mode VARCHAR(30) NULL AFTER signer_user_id";
        if (!in_array('signer_snapshot_json', $columns, true)) $alter[] = 'ADD COLUMN signer_snapshot_json TEXT NULL AFTER signer_mode';
        if (!in_array('completion_lines_json', $columns, true)) $alter[] = 'ADD COLUMN completion_lines_json LONGTEXT NULL AFTER signer_snapshot_json';
        if (!in_array('completion_percentage', $columns, true)) $alter[] = 'ADD COLUMN completion_percentage DECIMAL(6,2) NOT NULL DEFAULT 0 AFTER completion_lines_json';
        if (!in_array('beneficiary_user_id', $columns, true)) $alter[] = 'ADD COLUMN beneficiary_user_id INT NULL AFTER requester_user_id';
        if (!in_array('cancelled_at', $columns, true)) $alter[] = 'ADD COLUMN cancelled_at DATETIME NULL AFTER accounting_validated_at';
        if (!in_array('cancelled_by', $columns, true)) $alter[] = 'ADD COLUMN cancelled_by INT NULL AFTER cancelled_at';
        if (!in_array('cancelled_by_name', $columns, true)) $alter[] = 'ADD COLUMN cancelled_by_name VARCHAR(190) NULL AFTER cancelled_by';
        if (!in_array('cancellation_reason', $columns, true)) $alter[] = 'ADD COLUMN cancellation_reason VARCHAR(500) NULL AFTER cancelled_by_name';
        if (!in_array('bypassed_without_proof_at', $columns, true)) $alter[] = 'ADD COLUMN bypassed_without_proof_at DATETIME NULL';
        if (!in_array('bypassed_without_proof_by', $columns, true)) $alter[] = 'ADD COLUMN bypassed_without_proof_by INT NULL';
        if (!in_array('bypassed_without_proof_by_name', $columns, true)) $alter[] = 'ADD COLUMN bypassed_without_proof_by_name VARCHAR(190) NULL';
        if (!in_array('bypass_reason', $columns, true)) $alter[] = 'ADD COLUMN bypass_reason VARCHAR(500) NULL';
        if ($alter) $this->pdo->exec('ALTER TABLE disbursement_regularizations '.implode(', ', $alter));
        $this->alignRegularizationCollation();
        self::$schemaEnsured[$this->pdo] = true;
    }

    /**
     * Les anciennes installations ont créé les numéros de fiche avec
     * utf8mb4_general_ci alors que la régularisation utilise unicode_ci.
     * L'alignement est idempotent et s'applique au premier accès après mise en ligne.
     */
    private function alignRegularizationCollation(): void
    {
        try {
            $stmt=$this->pdo->query("SELECT TABLE_NAME,COLUMN_TYPE,CHARACTER_SET_NAME,COLLATION_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='num_fiche'
                  AND TABLE_NAME IN ('fiche_decaissement','disbursement_regularizations')");
            $rows=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row){
                if(is_array($row)&&isset($row['TABLE_NAME']))$rows[$row['TABLE_NAME']]=$row;
            }
            $source=$rows['fiche_decaissement']??null;$target=$rows['disbursement_regularizations']??null;
            if(!$source||!$target||empty($source['COLLATION_NAME'])||$source['COLLATION_NAME']===$target['COLLATION_NAME'])return;
            $charset=(string)$source['CHARACTER_SET_NAME'];$collation=(string)$source['COLLATION_NAME'];
            if(!preg_match('/^[a-z0-9_]+$/D',$charset)||!preg_match('/^[a-z0-9_]+$/D',$collation))return;
            $this->pdo->exec("ALTER TABLE disbursement_regularizations MODIFY num_fiche VARCHAR(60) CHARACTER SET $charset COLLATE $collation NOT NULL");
        }catch(Throwable $e){
            // Les comparaisons BINARY du flux restent fonctionnelles si le compte SQL
            // n'a pas le droit ALTER; l'échec est journalisé sans bloquer l'utilisateur.
            error_log('Regularization collation alignment: '.$e->getMessage());
        }
    }

    /**
     * Migration idempotente des champs utilisés par le circuit FEB.
     * Elle permet à la page de fonctionner sans script SQL manuel préalable.
     */
    private function ensureFebSourceSchema(): void
    {
        $columns=$this->pdo->query("SHOW COLUMNS FROM fiche_decaissement")->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('feb_request_id',$columns,true)){
            $this->pdo->exec("ALTER TABLE fiche_decaissement ADD COLUMN feb_request_id INT NULL AFTER code_autorisation_feb");
        }
        $indexes=$this->pdo->query("SHOW INDEX FROM fiche_decaissement")->fetchAll(PDO::FETCH_ASSOC);
        $indexNames=array_values(array_unique(array_map(static fn(array $index):string=>(string)($index['Key_name']??''),$indexes)));
        if(!in_array('idx_fiche_feb_request',$indexNames,true)){
            $this->pdo->exec("ALTER TABLE fiche_decaissement ADD INDEX idx_fiche_feb_request (feb_request_id)");
        }
    }

    public function getFiche(string $numFiche): ?array
    {
        $stmt = $this->pdo->prepare("SELECT f.*, c.titre chantier_titre, c.code chantier_code,
            c.entreprise chantier_entreprise, cl.nom_client
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            LEFT JOIN client_banamur cl ON cl.id_client=c.client_id
            WHERE BINARY f.num_fiche=BINARY :num LIMIT 1");
        $stmt->execute(['num' => trim($numFiche)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Les FEB antérieures au nouveau processus ne sont pas concernées. */
    public function isEligibleFiche(array $fiche): bool
    {
        $requestId=(int)($fiche['feb_request_id']??0);
        if($requestId>0){
            $stmt=$this->pdo->prepare("SELECT 1 FROM employee_legal_requests WHERE id=:id AND request_type='feb' AND created_at>=:start LIMIT 1");
            $stmt->execute(['id'=>$requestId,'start'=>self::FEB_REGULARIZATION_START]);
            return (bool)$stmt->fetchColumn();
        }
        $code=trim((string)($fiche['code_autorisation_feb']??''));
        if($code==='')return false;
        $stmt=$this->pdo->prepare("SELECT 1 FROM employee_legal_requests WHERE request_type='feb' AND created_at>=:start AND payload_json LIKE :code LIMIT 1");
        $stmt->execute(['start'=>self::FEB_REGULARIZATION_START,'code'=>'%'.str_replace(['%','_'],['\\%','\\_'],$code).'%']);
        return (bool)$stmt->fetchColumn();
    }

    private function eligibleFebClause(string $ficheAlias='f'): string
    {
        return "EXISTS (SELECT 1 FROM employee_legal_requests feb WHERE feb.id={$ficheAlias}.feb_request_id AND feb.request_type='feb' AND feb.created_at>='".self::FEB_REGULARIZATION_START."')";
    }

    public function resolveRequesterUserId(array $fiche): int
    {
        $febRequestId = (int)($fiche['feb_request_id'] ?? 0);
        if ($febRequestId > 0) {
            $stmt = $this->pdo->prepare("SELECT requester_user_id FROM employee_legal_requests WHERE id=:id LIMIT 1");
            $stmt->execute(['id'=>$febRequestId]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }
        $code = trim((string)($fiche['code_autorisation_feb'] ?? ''));
        if ($code !== '') {
            $stmt = $this->pdo->prepare("SELECT requester_user_id FROM employee_legal_requests
                WHERE request_type='feb' AND payload_json LIKE :code
                ORDER BY id DESC LIMIT 1");
            $stmt->execute(['code' => '%'.str_replace(['%', '_'], ['\\%', '\\_'], $code).'%']);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }
        return 0;
    }

    public function find(string $numFiche): ?array
    {
        $this->syncUpstreamPaymentProof(trim($numFiche));
        $stmt = $this->pdo->prepare('SELECT * FROM disbursement_regularizations WHERE BINARY num_fiche=BINARY :num LIMIT 1');
        $stmt->execute(['num' => trim($numFiche)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getFebLines(array $fiche): array
    {
        $requestId=(int)($fiche['feb_request_id'] ?? 0);
        if ($requestId <= 0) {
            $code=trim((string)($fiche['code_autorisation_feb'] ?? ''));
            if ($code !== '') {
                $stmt=$this->pdo->prepare("SELECT id FROM employee_legal_requests
                    WHERE request_type='feb' AND payload_json LIKE :code ORDER BY id DESC LIMIT 1");
                $stmt->execute(['code'=>'%'.str_replace(['%','_'],['\\%','\\_'],$code).'%']);
                $requestId=(int)$stmt->fetchColumn();
            }
        }
        $needs=[];
        if ($requestId > 0) {
            $stmt=$this->pdo->prepare("SELECT payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' LIMIT 1");
            $stmt->execute(['id'=>$requestId]);
            $payload=json_decode((string)$stmt->fetchColumn(),true);
            $needs=is_array($payload) ? (array)($payload['besoins'] ?? []) : [];
        }
        $lines=[];
        foreach ($needs as $index=>$need) {
            if (!is_array($need)) continue;
            $supplier=is_array($need['fournisseur'] ?? null) ? (array)$need['fournisseur'] : [];
            $quantity=max(0,(float)($need['quantite'] ?? 0));
            $unitPrice=max(0,(float)($supplier['prix_unitaire'] ?? 0));
            $amount=round($quantity*$unitPrice,2);
            $label=trim((string)($need['libelle'] ?? ''));
            if ($label === '') $label='Ligne FEB '.((int)$index+1);
            $lines[]=[
                'key'=>'feb-'.$requestId.'-'.(int)$index,
                'index'=>(int)$index+1,
                'label'=>$label,
                'type'=>trim((string)($need['type'] ?? '')),
                'category'=>trim((string)($need['categorie'] ?? '')),
                'quantity'=>$quantity,
                'unit_price'=>$unitPrice,
                'amount'=>$amount,
                'supplier'=>trim((string)($supplier['nom'] ?? $supplier['raison_sociale'] ?? '')),
            ];
        }
        if ($lines === []) {
            $label=trim((string)($fiche['details_demande'] ?? $fiche['designation_fiche'] ?? 'Décaissement'));
            $lines[]=[
                'key'=>'fiche-'.preg_replace('/[^A-Za-z0-9_-]/','',(string)($fiche['num_fiche'] ?? '')),
                'index'=>1,'label'=>$label !== '' ? $label : 'Objet de la fiche',
                'type'=>'Autre','category'=>'Fiche de décaissement','quantity'=>1,
                'unit_price'=>(float)($fiche['montant_fiche'] ?? 0),
                'amount'=>(float)($fiche['montant_fiche'] ?? 0),'supplier'=>'',
            ];
        }
        $total=array_sum(array_map(static fn(array $line): float => max(1,(float)$line['amount']),$lines));
        foreach ($lines as &$line) {
            $base=(float)$line['amount'];
            if ($base <= 0) $base=1;
            $line['weight']=round(($base/$total)*100,2);
        }
        unset($line);
        return $lines;
    }

    private function resolveCompletion(array $fiche, array $completedKeys, array $pendingStartDates): array
    {
        $allowed=array_fill_keys(array_map('strval',$completedKeys),true);
        $lines=$this->getFebLines($fiche);
        $doneAmount=0.0;
        $totalAmount=0.0;
        foreach ($lines as &$line) {
            $line['completed']=isset($allowed[(string)$line['key']]);
            $date=trim((string)($pendingStartDates[(string)$line['key']] ?? ''));
            $line['pending_start_date']=$line['completed'] ? null : $this->dateOrNull($date);
            if (!$line['completed'] && $line['pending_start_date'] === null) {
                throw new RuntimeException('Indiquez la date de début prévue pour chaque ligne restant en attente.');
            }
            $amount=(float)$line['amount'];
            if ($amount <= 0) $amount=1;
            $totalAmount+=$amount;
            if ($line['completed']) $doneAmount+=$amount;
        }
        unset($line);
        $percentage=$totalAmount > 0 ? round(($doneAmount/$totalAmount)*100,2) : 0.0;
        return ['lines'=>$lines,'percentage'=>$percentage];
    }

    public function isRequester(array $fiche, int $userId): bool
    {
        if ($userId <= 0) return false;
        $record = $this->find((string)$fiche['num_fiche']);
        $owner = (int)($record['beneficiary_user_id'] ?? 0);
        if ($owner <= 0) $owner = $this->resolveBeneficiaryUserId($fiche);
        return $owner === $userId;
    }

    public function resolveBeneficiaryUserId(array $fiche): int
    {
        $personnelId=(int)($fiche['personnel_id'] ?? 0);
        if ($personnelId <= 0) return 0;
        $stmt=$this->pdo->prepare("SELECT id FROM user_devis_banamur WHERE personnel_id=:personnel AND active=1 ORDER BY id DESC LIMIT 1");
        $stmt->execute(['personnel'=>$personnelId]);
        return (int)$stmt->fetchColumn();
    }

    public function saveByRequester(array $fiche, int $userId, array $data, array $proof): array
    {
        if ((int)($fiche['decaisse'] ?? 0) !== 1) throw new RuntimeException('La fiche doit être décaissée avant sa régularisation.');
        if (!$this->isRequester($fiche, $userId)) throw new RuntimeException('Seul le bénéficiaire de la fiche peut renseigner et signer ce PV.');

        $identity = $this->getUserIdentity($userId);
        $signatureMode = (string)($data['signature_mode'] ?? 'drawn');
        $signature = $this->resolveSignatureData($userId, $signatureMode, (string)($data['signature_data'] ?? ''));
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\\s]+$#', $signature)) {
            throw new RuntimeException('La signature du demandeur est obligatoire.');
        }
        $existing = $this->find((string)$fiche['num_fiche']);
        $proofData = $this->storeProof($proof, (string)$fiche['num_fiche'], $existing);
        if (empty($proofData['path'])) throw new RuntimeException('La preuve de paiement est obligatoire.');

        $completion=$this->resolveCompletion($fiche,(array)($data['completed_lines'] ?? []),(array)($data['pending_start_dates'] ?? []));
        $status=$completion['percentage'] >= 100 ? 'without_reserve' : 'pending';
        $kind = (string)($data['reception_kind'] ?? 'travaux');
        if (!in_array($kind, ['travaux','fourniture','prestation','autre'], true)) $kind = 'autre';
        $requesterId = $this->resolveRequesterUserId($fiche) ?: $userId;
        $payload = [
            'num' => (string)$fiche['num_fiche'], 'uid' => $requesterId, 'beneficiary_uid' => $this->resolveBeneficiaryUserId($fiche) ?: $userId, 'kind' => $kind, 'status' => $status,
            'project' => trim((string)($data['project_name'] ?? '')), 'location' => trim((string)($data['project_location'] ?? '')),
            'owner' => trim((string)($data['owner_name'] ?? '')), 'company' => trim((string)($data['executing_company'] ?? '')),
            'reception_date' => $this->dateOrNull($data['reception_date'] ?? null), 'effect_date' => $this->dateOrNull($data['effect_date'] ?? null),
            'observations' => trim((string)($data['observations'] ?? '')), 'reserves' => trim((string)($data['reserves'] ?? '')),
            'days' => max(0, (int)($data['reserve_deadline_days'] ?? 0)) ?: null,
            'remaining' => trim((string)($data['remaining_actions'] ?? '')),
            'amount' => max(0, (float)($data['validated_amount'] ?? $fiche['montant_fiche'] ?? 0)),
            'signature' => $signature, 'signer' => trim((string)($data['signer_name'] ?? '')),
            'signer_user_id' => $userId,
            'signer_mode' => $signatureMode === 'profile' ? 'profile' : 'drawn',
            'signer_snapshot' => json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completion_lines' => json_encode($completion['lines'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completion_percentage' => $completion['percentage'],
            'proof_path' => $proofData['path'], 'proof_name' => $proofData['name'],
            'proof_mime' => $proofData['mime'], 'proof_size' => $proofData['size'],
        ];
        if ($payload['project'] === '' || $payload['signer'] === '') throw new RuntimeException('Le projet et le nom du signataire sont obligatoires.');

        $sql = "INSERT INTO disbursement_regularizations
            (num_fiche,requester_user_id,beneficiary_user_id,reception_kind,reception_status,project_name,project_location,owner_name,executing_company,
             reception_date,effect_date,observations,reserves,reserve_deadline_days,remaining_actions,validated_amount,signature_data,
             signer_name,signer_user_id,signer_mode,signer_snapshot_json,completion_lines_json,completion_percentage,signed_at,payment_proof_path,payment_proof_name,payment_proof_mime,payment_proof_size,submitted_at,accounting_status)
            VALUES (:num,:uid,:beneficiary_uid,:kind,:status,:project,:location,:owner,:company,:reception_date,:effect_date,:observations,:reserves,:days,
             :remaining,:amount,:signature,:signer,:signer_user_id,:signer_mode,:signer_snapshot,:completion_lines,:completion_percentage,NOW(),:proof_path,:proof_name,:proof_mime,:proof_size,NOW(),'pending')
            ON DUPLICATE KEY UPDATE requester_user_id=VALUES(requester_user_id),beneficiary_user_id=VALUES(beneficiary_user_id),reception_kind=VALUES(reception_kind),
             reception_status=VALUES(reception_status),project_name=VALUES(project_name),project_location=VALUES(project_location),
             owner_name=VALUES(owner_name),executing_company=VALUES(executing_company),reception_date=VALUES(reception_date),
             effect_date=VALUES(effect_date),observations=VALUES(observations),reserves=VALUES(reserves),
             reserve_deadline_days=VALUES(reserve_deadline_days),remaining_actions=VALUES(remaining_actions),
             validated_amount=VALUES(validated_amount),signature_data=VALUES(signature_data),signer_name=VALUES(signer_name),
             signer_user_id=VALUES(signer_user_id),signer_mode=VALUES(signer_mode),signer_snapshot_json=VALUES(signer_snapshot_json),
             completion_lines_json=VALUES(completion_lines_json),completion_percentage=VALUES(completion_percentage),
             signed_at=NOW(),payment_proof_path=VALUES(payment_proof_path),payment_proof_name=VALUES(payment_proof_name),
             payment_proof_mime=VALUES(payment_proof_mime),payment_proof_size=VALUES(payment_proof_size),submitted_at=NOW(),
             accounting_status='pending',accounting_note=NULL,accounting_validated_by=NULL,accounting_validator_name=NULL,accounting_validated_at=NULL";
        $this->pdo->prepare($sql)->execute($payload);
        $this->clearAccessBlock((string)$fiche['num_fiche']);
        $this->notifyTeam('finance', 'Régularisation à valider — '.$fiche['num_fiche'],
            $payload['signer'].' a transmis le PV signé et la preuve de paiement.', '/decaissement/regularisation_decaissement.php?num_fiche='.rawurlencode((string)$fiche['num_fiche']));
        return $this->find((string)$fiche['num_fiche']) ?? [];
    }

    public function validate(string $numFiche, int $userId, string $validatorName, string $decision, string $note): void
    {
        if (!in_array($decision, ['approved','rejected'], true)) throw new RuntimeException('Décision invalide.');
        if ($this->pdo->inTransaction()) throw new LogicException('Le contrôle comptable doit démarrer hors transaction.');
        $this->pdo->beginTransaction();
        try {
            $read = $this->pdo->prepare('SELECT * FROM disbursement_regularizations WHERE BINARY num_fiche=BINARY :num FOR UPDATE');
            $read->execute(['num'=>$numFiche]);
            $record = $read->fetch(PDO::FETCH_ASSOC);
            if (!$record || empty($record['submitted_at'])) throw new RuntimeException('Le demandeur n’a pas encore transmis les documents.');
            if (!empty($record['cancelled_at']) || ($record['accounting_status'] ?? '') !== 'pending') throw new RuntimeException('Cette régularisation est déjà traitée ou annulée. Actualisez la fiche.');
            if ($decision === 'approved' && (empty($record['signature_data']) || empty($record['payment_proof_path']))) throw new RuntimeException('Le PV signé et la preuve de paiement sont obligatoires pour valider.');
            $stmt = $this->pdo->prepare("UPDATE disbursement_regularizations SET accounting_status=:status,
                accounting_note=:note,accounting_validated_by=:uid,accounting_validator_name=:name,accounting_validated_at=NOW()
                WHERE BINARY num_fiche=BINARY :num AND accounting_status='pending' AND cancelled_at IS NULL");
            $stmt->execute(['status'=>$decision,'note'=>trim($note),'uid'=>$userId,'name'=>$validatorName,'num'=>$numFiche]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Le dossier a changé. Rechargez sa fiche.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        $title = $decision === 'approved' ? 'Régularisation comptable validée' : 'Régularisation à corriger';
        try {
        $this->notifyUser((int)($record['beneficiary_user_id'] ?? 0), $title.' — '.$numFiche,
            $decision === 'approved' ? 'Le PV et la preuve de paiement ont été validés par la comptabilité.' : 'La comptabilité demande une correction. '.$note,
            '/employe/details_decaissement_employe.php?num_fiche='.rawurlencode($numFiche));
        } catch (Throwable $notificationError) {
            error_log('[regularization] Notification après validation indisponible.');
        }
    }

    public function updateByAccounting(array $fiche, int $userId, string $accountantName, array $data, array $proof): array
    {
        $numFiche = trim((string)($fiche['num_fiche'] ?? ''));
        $existing = $this->find($numFiche);
        if (!$existing) throw new RuntimeException('Le dossier de régularisation est introuvable.');
        $completion=$this->resolveCompletion($fiche,(array)($data['completed_lines'] ?? []),(array)($data['pending_start_dates'] ?? []));
        $status=$completion['percentage'] >= 100 ? 'without_reserve' : 'pending';
        $kind = (string)($data['reception_kind'] ?? 'travaux');
        if (!in_array($kind, ['travaux','fourniture','prestation','autre'], true)) $kind = 'autre';
        $proofData = $this->storeProof($proof, $numFiche, $existing);
        if (empty($proofData['path'])) throw new RuntimeException('La preuve de paiement est obligatoire.');
        $project = trim((string)($data['project_name'] ?? ''));
        if ($project === '') throw new RuntimeException('Le projet est obligatoire.');

        $submitPo = !empty($data['submit_po']);
        $poIdentity = $submitPo ? $this->getUserIdentity($userId) : [];
        $poSignature = $submitPo ? $this->resolveSignatureData($userId, (string)($data['signature_mode'] ?? 'drawn'), (string)($data['signature_data'] ?? '')) : '';
        $submitPoSql = $submitPo ? '1' : '0';
        $stmt = $this->pdo->prepare("UPDATE disbursement_regularizations SET
            reception_kind=:kind,reception_status=:status,project_name=:project,project_location=:location,
            owner_name=:owner,executing_company=:company,reception_date=:reception_date,effect_date=:effect_date,
            observations=:observations,reserves=:reserves,reserve_deadline_days=:days,remaining_actions=:remaining,
            validated_amount=:amount,payment_proof_path=:proof_path,payment_proof_name=:proof_name,
            payment_proof_mime=:proof_mime,payment_proof_size=:proof_size,accounting_status='pending',
            accounting_note=:note,accounting_validated_by=NULL,accounting_validator_name=NULL,accounting_validated_at=NULL,
            completion_lines_json=:completion_lines,completion_percentage=:completion_percentage,
            signature_data=IF({$submitPoSql}=1,:po_signature,signature_data),
            signer_name=IF({$submitPoSql}=1,:po_name,signer_name),signer_user_id=IF({$submitPoSql}=1,:po_user,signer_user_id),
            signer_mode=IF({$submitPoSql}=1,'accounting_po',signer_mode),signer_snapshot_json=IF({$submitPoSql}=1,:po_snapshot,signer_snapshot_json),
            signed_at=IF({$submitPoSql}=1,NOW(),signed_at),submitted_at=IF({$submitPoSql}=1,NOW(),submitted_at)
            WHERE BINARY num_fiche=BINARY :num");
        $stmt->execute([
            'kind'=>$kind,'status'=>$status,'project'=>$project,
            'location'=>trim((string)($data['project_location'] ?? '')),
            'owner'=>trim((string)($data['owner_name'] ?? '')),
            'company'=>trim((string)($data['executing_company'] ?? '')),
            'reception_date'=>$this->dateOrNull($data['reception_date'] ?? null),
            'effect_date'=>$this->dateOrNull($data['effect_date'] ?? null),
            'observations'=>trim((string)($data['observations'] ?? '')),
            'reserves'=>trim((string)($data['reserves'] ?? '')),
            'days'=>max(0,(int)($data['reserve_deadline_days'] ?? 0)) ?: null,
            'remaining'=>trim((string)($data['remaining_actions'] ?? '')),
            'amount'=>max(0,(float)($data['validated_amount'] ?? 0)),
            'proof_path'=>$proofData['path'],'proof_name'=>$proofData['name'],
            'proof_mime'=>$proofData['mime'],'proof_size'=>$proofData['size'],
            'note'=>'Documents mis à jour par la comptabilité : '.$accountantName,
            'completion_lines'=>json_encode($completion['lines'],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'completion_percentage'=>$completion['percentage'],
            'po_signature'=>$poSignature,
            'po_name'=>(string)($poIdentity['full_name'] ?? $accountantName),'po_user'=>$userId,
            'po_snapshot'=>json_encode($poIdentity, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'num'=>$numFiche,
        ]);
        if ($submitPo) $this->clearAccessBlock($numFiche);
        $message = $submitPo
            ? 'La comptabilité a renseigné et signé le PV pour ordre. Les documents sont disponibles pour contrôle.'
            : (!empty($existing['signature_data'])
            ? 'La comptabilité a mis à jour le PV ou la preuve de paiement. Votre signature d’origine a été conservée.'
            : 'La comptabilité a préparé le PV ou ajouté la preuve de paiement. Veuillez vérifier, signer et soumettre la régularisation.');
        $this->notifyUser((int)($existing['beneficiary_user_id'] ?? 0), 'Régularisation mise à jour — '.$numFiche,
            $message, '/decaissement/regularisation_decaissement.php?num_fiche='.rawurlencode($numFiche));
        return $this->find($numFiche) ?? [];
    }

    public function setAccessBlock(string $numFiche, int $actorUserId, string $actorName, string $reason): void
    {
        $record = $this->find($numFiche);
        if (!$record || (int)($record['beneficiary_user_id'] ?? 0) <= 0) {
            throw new RuntimeException('Le compte du bénéficiaire ne peut pas être identifié.');
        }
        if (!empty($record['submitted_at'])) {
            throw new RuntimeException('Les documents ont déjà été soumis : le blocage n’est plus nécessaire.');
        }
        $reason = trim($reason);
        if ($reason === '') $reason = 'PV de réception et preuve de paiement non transmis.';
        $stmt = $this->pdo->prepare("UPDATE disbursement_regularizations SET access_blocked_at=NOW(),
            access_blocked_by=:actor,access_blocked_by_name=:name,access_block_reason=:reason WHERE BINARY num_fiche=BINARY :num");
        $stmt->execute(['actor'=>$actorUserId,'name'=>$actorName,'reason'=>$reason,'num'=>$numFiche]);
        $this->notifyUser((int)$record['beneficiary_user_id'], 'Régularisation obligatoire — '.$numFiche,
            'Votre accès est limité à cette régularisation jusqu’à la transmission du PV signé et de la preuve de paiement.',
            '/decaissement/regularisation_decaissement.php?num_fiche='.rawurlencode($numFiche));
    }

    public function clearAccessBlock(string $numFiche): void
    {
        $stmt = $this->pdo->prepare("UPDATE disbursement_regularizations SET access_blocked_at=NULL,
            access_blocked_by=NULL,access_blocked_by_name=NULL,access_block_reason=NULL WHERE BINARY num_fiche=BINARY :num");
        $stmt->execute(['num'=>$numFiche]);
    }

    public function getActiveAccessBlockForUser(int $userId): ?array
    {
        if ($userId <= 0) return null;
        $stmt = $this->pdo->prepare("SELECT num_fiche,access_block_reason,access_blocked_at
            FROM disbursement_regularizations
            WHERE beneficiary_user_id=:uid AND access_blocked_at IS NOT NULL AND submitted_at IS NULL AND cancelled_at IS NULL
            ORDER BY access_blocked_at ASC LIMIT 1");
        $stmt->execute(['uid'=>$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Prochaine fiche décaissée dont les justificatifs n'ont pas encore été soumis. */
    public function getNextUnsubmittedForUser(int $userId, string $excludeNumFiche = ''): ?array
    {
        if ($userId <= 0) return null;
        $stmt = $this->pdo->prepare("SELECT f.num_fiche,r.access_block_reason,r.access_blocked_at,
                f.designation_fiche,f.montant_fiche,COALESCE(f.date_decaisse,f.date_decaissement) AS date_decaissement
            FROM fiche_decaissement f
            LEFT JOIN disbursement_regularizations r
              ON BINARY f.num_fiche=BINARY r.num_fiche
            WHERE f.decaisse=1 AND ".$this->eligibleFebClause('f')." AND r.submitted_at IS NULL AND r.cancelled_at IS NULL
              AND BINARY f.num_fiche<>BINARY :excluded
              AND r.beneficiary_user_id=:uid
            ORDER BY CASE WHEN r.access_blocked_at IS NOT NULL THEN 0 ELSE 1 END,
                r.access_blocked_at ASC,
                COALESCE(f.date_decaisse,f.date_decaissement) ASC,
                r.id ASC
            LIMIT 1");
        $stmt->execute(['uid'=>$userId, 'excluded'=>trim($excludeNumFiche)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function pendingForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare("SELECT f.num_fiche,f.designation_fiche,f.montant_fiche,f.date_decaisse,
            r.accounting_status,r.submitted_at
            FROM fiche_decaissement f
            LEFT JOIN disbursement_regularizations r
              ON BINARY r.num_fiche=BINARY f.num_fiche
            WHERE f.decaisse=1 AND ".$this->eligibleFebClause('f')."
              AND r.beneficiary_user_id=:uid
              AND (r.id IS NULL OR (r.accounting_status<>'approved' AND r.cancelled_at IS NULL))
            ORDER BY COALESCE(f.date_decaisse,f.date_decaissement) DESC");
        $stmt->execute(['uid'=>$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listForViewer(int $userId, bool $canViewAll, string $status = ''): array
    {
        $where = ['f.decaisse=1', $this->eligibleFebClause('f'), 'r.cancelled_at IS NULL'];
        $params = [];
        if (!$canViewAll) {
            $where[] = 'r.beneficiary_user_id=:uid';
            $params['uid'] = $userId;
        }
        if (in_array($status, ['not_submitted','pending','approved','rejected'], true)) {
            if ($status === 'not_submitted') {
                $where[] = '(r.id IS NULL OR r.submitted_at IS NULL)';
            } else {
                $where[] = 'r.accounting_status=:status AND r.submitted_at IS NOT NULL';
                $params['status'] = $status;
            }
        }
        $stmt = $this->pdo->prepare("SELECT f.num_fiche,f.designation_fiche,f.beficiaire_fiche,f.montant_fiche,
            f.mode_paiement,COALESCE(f.date_decaisse,f.date_decaissement) date_decaissement,
            c.titre chantier_titre,c.code chantier_code,r.requester_user_id,r.beneficiary_user_id,r.submitted_at,
            r.accounting_status,r.signer_name,r.accounting_validated_at
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            LEFT JOIN disbursement_regularizations r
              ON BINARY r.num_fiche=BINARY f.num_fiche
            WHERE ".implode(' AND ', $where)."
            ORDER BY CASE WHEN r.submitted_at IS NULL THEN 0 WHEN r.accounting_status='pending' THEN 1
                WHEN r.accounting_status='rejected' THEN 2 ELSE 3 END,
                COALESCE(f.date_decaisse,f.date_decaissement) DESC");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countsForViewer(int $userId, bool $canViewAll): array
    {
        $where=['f.decaisse=1',$this->eligibleFebClause('f'),'r.cancelled_at IS NULL'];$params=[];
        if(!$canViewAll){
            $where[]='r.beneficiary_user_id=:uid';
            $params['uid']=$userId;
        }
        $stmt=$this->pdo->prepare("SELECT
            SUM(CASE WHEN r.id IS NULL OR r.submitted_at IS NULL THEN 1 ELSE 0 END) not_submitted,
            SUM(CASE WHEN r.submitted_at IS NOT NULL AND r.accounting_status='pending' THEN 1 ELSE 0 END) pending,
            SUM(CASE WHEN r.submitted_at IS NOT NULL AND r.accounting_status='approved' THEN 1 ELSE 0 END) approved,
            SUM(CASE WHEN r.submitted_at IS NOT NULL AND r.accounting_status='rejected' THEN 1 ELSE 0 END) rejected
            FROM fiche_decaissement f LEFT JOIN disbursement_regularizations r ON BINARY r.num_fiche=BINARY f.num_fiche
            WHERE ".implode(' AND ',$where));
        $stmt->execute($params);
        $row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];
        return array_map('intval',['not_submitted'=>$row['not_submitted']??0,'pending'=>$row['pending']??0,'approved'=>$row['approved']??0,'rejected'=>$row['rejected']??0]);
    }

    public function bulkApprove(array $numbers,int $userId,string $validatorName,string $note,bool $signForOrder,string $signatureMode='',string $signatureData=''): int
    {
        $numbers=array_values(array_unique(array_filter(array_map(static fn($num):string=>trim((string)$num),$numbers))));
        if(!$numbers)throw new RuntimeException('Sélectionnez au moins une régularisation à approuver.');
        $identity=$signForOrder?$this->getUserIdentity($userId):[];
        $signature=$signForOrder?$this->resolveSignatureData($userId,$signatureMode,$signatureData):'';
        $in=implode(',',array_fill(0,count($numbers),'?'));
        $this->pdo->beginTransaction();
        try{
            $eligible=$this->pdo->prepare("SELECT r.num_fiche,r.requester_user_id,r.beneficiary_user_id FROM disbursement_regularizations r JOIN fiche_decaissement f ON BINARY f.num_fiche=BINARY r.num_fiche WHERE r.num_fiche IN ($in) AND r.submitted_at IS NOT NULL AND r.accounting_status='pending' AND r.cancelled_at IS NULL AND ".$this->eligibleFebClause('f')." FOR UPDATE");
            $eligible->execute($numbers);$rows=$eligible->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(!$rows)throw new RuntimeException('Aucune fiche sélectionnée n’est en attente de validation.');
            $update=$this->pdo->prepare("UPDATE disbursement_regularizations SET accounting_status='approved',accounting_note=:note,accounting_validated_by=:user,accounting_validator_name=:name,accounting_validated_at=NOW(),
                signature_data=IF(:po=1,:signature,signature_data),signer_name=IF(:po=1,:signer,signer_name),signer_user_id=IF(:po=1,:user,signer_user_id),signer_mode=IF(:po=1,'accounting_po',signer_mode),signer_snapshot_json=IF(:po=1,:snapshot,signer_snapshot_json),signed_at=IF(:po=1,NOW(),signed_at)
                WHERE BINARY num_fiche=BINARY :num");
            foreach($rows as $row)$update->execute(['note'=>trim($note),'user'=>$userId,'name'=>$validatorName,'po'=>$signForOrder?1:0,'signature'=>$signature,'signer'=>(string)($identity['full_name']??$validatorName),'snapshot'=>json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'num'=>$row['num_fiche']]);
            $this->pdo->commit();
            foreach($rows as $row)$this->notifyUser((int)($row['beneficiary_user_id'] ?? 0),'Régularisation comptable validée — '.$row['num_fiche'],$signForOrder?'Le PV a été validé et signé pour ordre par la comptabilité.':'Le PV et la preuve de paiement ont été validés par la comptabilité.','/employe/details_decaissement_employe.php?num_fiche='.rawurlencode((string)$row['num_fiche']));
            return count($rows);
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    public function openAfterDisbursement(string $numFiche): void
    {
        $fiche = $this->getFiche($numFiche);
        if (!$fiche || (int)($fiche['decaisse'] ?? 0) !== 1 || !$this->isEligibleFiche($fiche)) return;
        $requesterId = $this->resolveRequesterUserId($fiche);
        $alreadyOpened = $this->find($numFiche) !== null;
        $project = trim((string)($fiche['chantier_titre'] ?? $fiche['designation_fiche'] ?? $numFiche));
        $stmt = $this->pdo->prepare("INSERT INTO disbursement_regularizations
          (num_fiche,requester_user_id,beneficiary_user_id,project_name,project_location,owner_name,executing_company,validated_amount)
          VALUES (:num,:uid,:beneficiary,:project,:location,:owner,:company,:amount)
          ON DUPLICATE KEY UPDATE requester_user_id=VALUES(requester_user_id),beneficiary_user_id=VALUES(beneficiary_user_id)");
        $stmt->execute([
            'num'=>$numFiche,'uid'=>$requesterId,'beneficiary'=>$this->resolveBeneficiaryUserId($fiche) ?: null,'project'=>$project ?: $numFiche,
            'location'=>(string)($fiche['chantier_code'] ?? ''),'owner'=>(string)($fiche['nom_client'] ?? ''),
            'company'=>(string)($fiche['chantier_entreprise'] ?? $fiche['entreprise'] ?? ''),
            'amount'=>(float)($fiche['montant_fiche'] ?? 0),
        ]);
        $this->syncUpstreamPaymentProof($numFiche);
        if ($alreadyOpened) return;
        $beneficiaryId=$this->resolveBeneficiaryUserId($fiche);
        if ($beneficiaryId > 0) {
            $this->notifyUser($beneficiaryId, 'Documents requis après décaissement — '.$numFiche,
                'Votre fiche a été décaissée. Veuillez fournir le PV signé et la preuve de paiement pour régularisation comptable.',
                '/decaissement/regularisation_decaissement.php?num_fiche='.rawurlencode($numFiche));
        }
    }

    /**
     * Réutilise automatiquement la preuve déjà déposée lors du paiement du point.
     * La copie est logique : le fichier reste dans son stockage privé d'origine.
     */
    private function syncUpstreamPaymentProof(string $numFiche): void
    {
        if ($numFiche === '') return;
        try {
            $regularization = $this->pdo->prepare("SELECT id,payment_proof_path FROM disbursement_regularizations WHERE BINARY num_fiche=BINARY :num LIMIT 1");
            $regularization->execute(['num'=>$numFiche]);
            $current = $regularization->fetch(PDO::FETCH_ASSOC);
            if (!$current || trim((string)($current['payment_proof_path'] ?? '')) !== '') return;

            $proof = $this->pdo->prepare("SELECT p.proof_path
                FROM daily_disbursement_point_payment_items i
                INNER JOIN daily_disbursement_point_payments p ON p.id=i.payment_id
                WHERE i.fiche_number=:num AND COALESCE(p.proof_path,'')<>''
                ORDER BY p.paid_at DESC,p.id DESC LIMIT 1");
            $proof->execute(['num'=>$numFiche]);
            $relative = ltrim(trim((string)$proof->fetchColumn()), '/');
            if ($relative === '' || strpos($relative, 'uploads/disbursement_proofs/') !== 0) return;

            $root = realpath(dirname(__DIR__));
            $absolute = realpath(dirname(__DIR__).'/'.$relative);
            if (!$root || !$absolute || strpos($absolute, $root.'/uploads/disbursement_proofs/') !== 0 || !is_file($absolute)) return;

            $mime = 'application/octet-stream';
            if (class_exists('finfo')) {
                $detected=@(new finfo(FILEINFO_MIME_TYPE))->file($absolute);
                if (is_string($detected) && $detected !== '') $mime=$detected;
            }
            $update=$this->pdo->prepare("UPDATE disbursement_regularizations SET
                payment_proof_path=:path,payment_proof_name=:name,payment_proof_mime=:mime,payment_proof_size=:size
                WHERE id=:id AND COALESCE(payment_proof_path,'')='' ");
            $update->execute(['path'=>$relative,'name'=>basename($absolute),'mime'=>$mime,'size'=>(int)filesize($absolute),'id'=>(int)$current['id']]);
        } catch (Throwable $ignored) {
            // Compatibilité avec les installations où le circuit des points n'a pas encore été initialisé.
        }
    }

    private function storeProof(array $file, string $numFiche, ?array $existing): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['path'=>$existing['payment_proof_path'] ?? '', 'name'=>$existing['payment_proof_name'] ?? '',
                'mime'=>$existing['payment_proof_mime'] ?? '', 'size'=>(int)($existing['payment_proof_size'] ?? 0)];
        }
        if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('Échec du téléversement de la preuve.');
        if ((int)$file['size'] > 12 * 1024 * 1024) throw new RuntimeException('La preuve ne doit pas dépasser 12 Mo.');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file((string)$file['tmp_name']);
        $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic'];
        if (!isset($allowed[$mime])) throw new RuntimeException('Format accepté : PDF, JPG, PNG, WEBP ou HEIC.');
        $relativeDir='uploads/disbursement_proofs/'.date('Y/m');
        $dir=dirname(__DIR__).'/'.$relativeDir;
        $this->ensureWritableDirectory($dir);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $numFiche).'-'.bin2hex(random_bytes(8)).'.'.$allowed[$mime];
        if (!move_uploaded_file((string)$file['tmp_name'], $dir.'/'.$name)) throw new RuntimeException('Impossible d’enregistrer la preuve.');
        return ['path'=>$relativeDir.'/'.$name,'name'=>basename((string)$file['name']),'mime'=>$mime,'size'=>(int)$file['size']];
    }

    public function attachPaymentProof(string $numFiche, int $actorUserId, string $actorName, array $file, string $documentType='payment_proof'): array
    {
        $numFiche = trim($numFiche);
        if ($actorUserId <= 0 || trim($actorName) === '') throw new DomainException('Auteur du justificatif invalide.');
        if (!in_array($documentType, ['payment_proof', 'fne'], true)) throw new DomainException('Type de justificatif invalide.');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Sélectionnez un justificatif.');
        $existing = $this->find($numFiche);
        if (!$existing || !empty($existing['cancelled_at'])) throw new RuntimeException('Régularisation active introuvable.');
        $proofData = $this->storeProof($file, $numFiche, $existing);
        if (empty($proofData['path'])) throw new RuntimeException('Sélectionnez un justificatif.');
        if($documentType==='fne'){
            $stmt=$this->pdo->prepare('UPDATE fiche_decaissement SET facture_reference=:path WHERE BINARY num_fiche=BINARY :num');
            $stmt->execute(['path'=>$proofData['path'],'num'=>$numFiche]);
        }else{
            $documentType='payment_proof';
            $stmt=$this->pdo->prepare("UPDATE disbursement_regularizations SET payment_proof_path=:path,payment_proof_name=:name,
                payment_proof_mime=:mime,payment_proof_size=:size,access_blocked_at=NULL,access_blocked_by=NULL,
                access_blocked_by_name=NULL,access_block_reason=NULL,accounting_note=CONCAT(COALESCE(accounting_note,''),:note)
                WHERE BINARY num_fiche=BINARY :num AND cancelled_at IS NULL");
            $stmt->execute(['path'=>$proofData['path'],'name'=>$proofData['name'],'mime'=>$proofData['mime'],'size'=>$proofData['size'],
                'note'=>"\nJustificatif associé par {$actorName} depuis la gestion fournisseurs.",'num'=>$numFiche]);
        }
        $supplierStmt=$this->pdo->prepare("SELECT f.fournisseur_id FROM fiche_decaissement f WHERE BINARY f.num_fiche=BINARY :num LIMIT 1");
        $supplierStmt->execute(['num'=>$numFiche]);$supplierId=(int)$supplierStmt->fetchColumn();
        if($supplierId>0){
            $remaining=$this->pdo->prepare("SELECT COUNT(*) FROM disbursement_regularizations r JOIN fiche_decaissement f ON BINARY f.num_fiche=BINARY r.num_fiche WHERE f.fournisseur_id=:supplier AND r.cancelled_at IS NULL AND (COALESCE(r.payment_proof_path,'')='' OR COALESCE(f.facture_reference,'')='')");
            $remaining->execute(['supplier'=>$supplierId]);
            if((int)$remaining->fetchColumn()===0)$this->pdo->prepare('UPDATE fournisseur_banamur SET disbursement_selection_blocked=0,disbursement_block_reason=NULL,disbursement_block_fiches=NULL WHERE id_fournisseur=:id')->execute(['id'=>$supplierId]);
        }
        AuditTrail::log('supplier_regularization_proof_attached',['module'=>'finance','action'=>'regularization_attach_proof','entity_type'=>'fiche_decaissement','entity_id'=>$numFiche,'entity_label'=>$numFiche,'user_id'=>$actorUserId,'user_name'=>$actorName,'result'=>'success','details'=>['document_type'=>$documentType,'file_name'=>$proofData['name'],'file_mime'=>$proofData['mime']]],$this->pdo);
        return $this->find($numFiche) ?? [];
    }

    private function ensureProofStorage(): void
    {
        $this->ensureWritableDirectory(dirname(__DIR__).'/uploads/disbursement_proofs');
    }

    private function ensureWritableDirectory(string $directory): void
    {
        $created=false;
        if (!is_dir($directory)) {
            $previousUmask=umask(0002);
            $created=@mkdir($directory,0775,true);
            umask($previousUmask);
            if (!$created && !is_dir($directory)) {
                throw new RuntimeException('Stockage de la preuve indisponible : création automatique du dossier impossible.');
            }
        }
        if ($created) @chmod($directory,0775);
        if (!is_writable($directory)) {
            throw new RuntimeException('Stockage de la preuve indisponible : le serveur web ne peut pas écrire dans le dossier.');
        }
    }

    private function dateOrNull($value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }

    public function getUserIdentity(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) full_name,
          u.mail_pro,COALESCE(NULLIF(TRIM(p.photo_personnel_soignant),''),NULLIF(TRIM(u.photo),'')) photo,u.signature,p.matricule_personnel_soignant matricule,
          COALESCE(NULLIF(s.lib_service,''),'Service non renseigné') service,
          COALESCE(NULLIF(f.lib_fonction,''),'Fonction non renseignée') fonction
          FROM user_devis_banamur u LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id
          LEFT JOIN service s ON s.id_service=p.service_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id
          WHERE u.id=:id LIMIT 1");
        $stmt->execute(['id'=>$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function resolveSignatureData(int $userId, string $mode, string $drawn): string
    {
        if ($mode === 'profile') {
            $identity=$this->getUserIdentity($userId);
            $relative=trim((string)($identity['signature'] ?? ''));
            $root=realpath(dirname(__DIR__));
            $file=false;
            foreach (array_unique([$relative, 'uploads/user_signatures/'.$relative]) as $candidate) {
                if ($candidate === '' || !$root) continue;
                $resolved=realpath($root.'/'.ltrim($candidate,'/'));
                if ($resolved && strpos($resolved,$root.'/uploads/user_signatures/')===0 && is_file($resolved)) {
                    $file=$resolved;
                    break;
                }
            }
            if (!$file || strpos($file,$root.'/uploads/user_signatures/')!==0 || !is_file($file)) {
                throw new RuntimeException('Aucune signature de profil valide n’est disponible.');
            }
            $binary=file_get_contents($file);
            return 'data:image/png;base64,'.base64_encode((string)$binary);
        }
        $drawn=trim($drawn);
        if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=\\s]+$#',$drawn)) {
            throw new RuntimeException('La signature manuscrite est obligatoire.');
        }
        return $drawn;
    }

    private function notifyUser(int $userId, string $title, string $message, string $link): void
    {
        if ($userId <= 0) return;
        $this->insertNotification($title, $message, $link, $userId, null);
    }

    private function notifyTeam(string $team, string $title, string $message, string $link): void
    {
        $this->insertNotification($title, $message, $link, null, $team);
    }

    private function insertNotification(string $title, string $message, string $link, ?int $userId, ?string $team): void
    {
        new NotificationManager($this->pdo);
        $stmt=$this->pdo->prepare("INSERT INTO system_notifications
          (event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json,created_at)
          VALUES ('decaissement_processed','regularization',:title,:message,:link,:team,:uid,'[\"system\"]','{}',NOW())");
        $stmt->execute(['title'=>$title,'message'=>$message,'link'=>$link,'team'=>$team,'uid'=>$userId]);
    }
}
