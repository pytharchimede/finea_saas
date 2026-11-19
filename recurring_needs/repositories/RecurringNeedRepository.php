<?php

require_once __DIR__ . '/../model/RecurringNeedMigration.php';

class RecurringNeedRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        RecurringNeedMigration::migrate($this->pdo);
    }

    public function stats(?int $userId = null, bool $all = false): array
    {
        $where = $all || !$userId ? '1=1' : 'requester_user_id = :uid';
        $stmt = $this->pdo->prepare("SELECT
            COUNT(*) total,
            SUM(status = 'active') active_count,
            SUM(status = 'pending_validation') pending_count,
            SUM(status = 'suspended') suspended_count,
            SUM(next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND status = 'active') upcoming_count,
            COALESCE(SUM(CASE WHEN status = 'active' THEN amount_estimated ELSE 0 END),0) active_amount
            FROM finea_recurring_needs WHERE {$where}");
        if (!$all && $userId) {
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['user_id']) && empty($filters['all'])) {
            $where[] = 'requester_user_id = :user_id';
            $params['user_id'] = (int)$filters['user_id'];
        }
        if (!empty($filters['status'])) {
            $value=(string)$filters['status'];$exclude=str_starts_with($value,'!');$where[] = 'n.status '.($exclude?'<>':'=').' :status';
            $params['status'] = ltrim($value,'!');
        } elseif (!empty($filters['archive_mode'])) {
            $where[] = "n.status IN ('cancelled','expired')";
        } else {
            $where[] = "n.status NOT IN ('cancelled','expired')";
        }
        if (!empty($filters['q'])) {
            $where[] = ((string)($filters['q_mode']??'include')==='exclude'?'NOT ':'').'(n.title LIKE :q OR n.reference LIKE :q OR n.fournisseur_name LIKE :q OR n.requester_name LIKE :q OR n.departement_label LIKE :q OR c.code LIKE :q OR c.titre LIKE :q)';
            $params['q'] = '%' . trim((string)$filters['q']) . '%';
        }
        $addMulti=function(string$filter,string$expression)use(&$where,&$params,$filters):void{$values=array_values(array_unique(array_filter(array_map('trim',(array)($filters[$filter]??[])),static fn($v)=>$v!=='')));if(!$values)return;$holders=[];foreach($values as$i=>$value){$key=$filter.'_'.$i;$holders[]=':'.$key;$params[$key]=$value;}$negative=(string)($filters[$filter.'_mode']??'include')==='exclude';$where[]=$expression.' '.($negative?'NOT IN':'IN').' ('.implode(',',$holders).')';};
        $addMulti('requester','n.requester_name');
        $addMulti('beneficiary',"COALESCE(NULLIF(n.fournisseur_name,''),n.requester_name)");
        $assignments=array_values(array_unique(array_filter(array_map('trim',(array)($filters['assignment']??[])),static fn($v)=>$v!=='')));if($assignments){$departmentHolders=[];$siteHolders=[];foreach($assignments as$i=>$value){$departmentHolders[]=':assignment_department_'.$i;$siteHolders[]=':assignment_site_'.$i;$params['assignment_department_'.$i]=$value;$params['assignment_site_'.$i]=$value;}$match='(n.departement_label IN ('.implode(',',$departmentHolders).") OR CONCAT_WS(' — ',c.code,c.titre) IN (".implode(',',$siteHolders).'))';$where[]=((string)($filters['assignment_mode']??'include')==='exclude'?'NOT ':'').$match;}
        if (!empty($filters['frequency_type'])) {
            $value=(string)$filters['frequency_type'];$exclude=str_starts_with($value,'!');$where[] = 'n.frequency_type '.($exclude?'<>':'=').' :frequency_type';
            $params['frequency_type'] = ltrim($value,'!');
        }
        if (!empty($filters['priority'])) {
            $value=(string)$filters['priority'];$exclude=str_starts_with($value,'!');$where[] = 'n.priority '.($exclude?'<>':'=').' :priority';
            $params['priority'] = ltrim($value,'!');
        }
        if (!empty($filters['due_from'])) {
            $where[] = 'next_due_date >= :due_from';
            $params['due_from'] = (string)$filters['due_from'];
        }
        if (!empty($filters['due_to'])) {
            $where[] = 'next_due_date <= :due_to';
            $params['due_to'] = (string)$filters['due_to'];
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $stmt = $this->pdo->prepare("SELECT n.*,c.code chantier_code,c.titre chantier_title FROM finea_recurring_needs n LEFT JOIN chantier_banamur c ON c.id=n.chantier_id {$sqlWhere} ORDER BY FIELD(n.status,'pending_validation','active','suspended','expired','cancelled','draft'), n.next_due_date ASC, n.id DESC LIMIT 300");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function filterOptions(?int $userId=null,bool $all=false): array
    {
        $scope=$all||!$userId?'1=1':'n.requester_user_id=:uid';$stmt=$this->pdo->prepare("SELECT n.requester_name,COALESCE(NULLIF(n.fournisseur_name,''),n.requester_name) beneficiary,n.departement_label department,CONCAT_WS(' — ',c.code,c.titre) site FROM finea_recurring_needs n LEFT JOIN chantier_banamur c ON c.id=n.chantier_id WHERE $scope ORDER BY n.id DESC LIMIT 1000");if(!$all&&$userId)$stmt->execute(['uid'=>$userId]);else$stmt->execute();$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];$unique=static function(array$values):array{$values=array_values(array_unique(array_filter(array_map('trim',$values),static fn($v)=>$v!=='')));sort($values,SORT_NATURAL|SORT_FLAG_CASE);return $values;};return ['requesters'=>$unique(array_column($rows,'requester_name')),'beneficiaries'=>$unique(array_column($rows,'beneficiary')),'assignments'=>$unique(array_merge(array_column($rows,'department'),array_column($rows,'site')))];
    }

    public function recentGenerations(?int $userId = null, bool $all = false, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $where = $all || !$userId ? '1=1' : 'n.requester_user_id = :user_id';
        $stmt = $this->pdo->prepare("
            SELECT g.*, n.reference, n.title, n.requester_user_id
            FROM finea_recurring_need_generations g
            INNER JOIN finea_recurring_needs n ON n.id = g.recurring_need_id
            WHERE {$where}
            ORDER BY g.created_at DESC, g.id DESC
            LIMIT {$limit}
        ");
        if (!$all && $userId) {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finea_recurring_needs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $reference = $this->nextReference();
        $stmt = $this->pdo->prepare('INSERT INTO finea_recurring_needs
            (reference,title,description,requester_user_id,requester_name,requester_email,source_feb_request_id,departement_id,departement_label,chantier_id,fournisseur_id,fournisseur_name,need_type,financial_mode,amount_estimated,currency,priority,frequency_type,frequency_interval,day_of_month,day_of_week,start_date,end_date,next_due_date,generate_days_before,reminder_days_before,max_occurrences,status,feb_template_json,created_by)
            VALUES
            (:reference,:title,:description,:requester_user_id,:requester_name,:requester_email,:source_feb_request_id,:departement_id,:departement_label,:chantier_id,:fournisseur_id,:fournisseur_name,:need_type,:financial_mode,:amount_estimated,:currency,:priority,:frequency_type,:frequency_interval,:day_of_month,:day_of_week,:start_date,:end_date,:next_due_date,:generate_days_before,:reminder_days_before,:max_occurrences,:status,:feb_template_json,:created_by)');
        $stmt->execute([
            'reference' => $reference,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'requester_user_id' => (int)$data['requester_user_id'],
            'requester_name' => $data['requester_name'] ?? null,
            'requester_email' => $data['requester_email'] ?? null,
            'source_feb_request_id' => self::nullableInt($data['source_feb_request_id'] ?? null),
            'departement_id' => self::nullableInt($data['departement_id'] ?? null),
            'departement_label' => $data['departement_label'] ?? null,
            'chantier_id' => self::nullableInt($data['chantier_id'] ?? null),
            'fournisseur_id' => self::nullableInt($data['fournisseur_id'] ?? null),
            'fournisseur_name' => $data['fournisseur_name'] ?? null,
            'need_type' => $data['need_type'],
            'financial_mode' => $data['financial_mode'],
            'amount_estimated' => (float)$data['amount_estimated'],
            'currency' => $data['currency'] ?? 'XOF',
            'priority' => $data['priority'],
            'frequency_type' => $data['frequency_type'],
            'frequency_interval' => max(1, (int)$data['frequency_interval']),
            'day_of_month' => self::nullableInt($data['day_of_month'] ?? null),
            'day_of_week' => self::nullableInt($data['day_of_week'] ?? null),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?: null,
            'next_due_date' => $data['next_due_date'],
            'generate_days_before' => max(0, (int)$data['generate_days_before']),
            'reminder_days_before' => max(0, (int)$data['reminder_days_before']),
            'max_occurrences' => self::nullableInt($data['max_occurrences'] ?? null),
            'status' => $data['status'] ?? 'pending_validation',
            'feb_template_json' => json_encode($data['feb_template'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_by' => (int)$data['created_by'],
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findBySourceFebRequestId(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finea_recurring_needs WHERE source_feb_request_id = :request_id LIMIT 1');
        $stmt->execute(['request_id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function update(int $id, array $data, int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE finea_recurring_needs SET
            title = :title,
            description = :description,
            departement_id = :departement_id,
            departement_label = :departement_label,
            chantier_id = :chantier_id,
            fournisseur_id = :fournisseur_id,
            fournisseur_name = :fournisseur_name,
            need_type = :need_type,
            financial_mode = :financial_mode,
            amount_estimated = :amount_estimated,
            currency = :currency,
            priority = :priority,
            frequency_type = :frequency_type,
            frequency_interval = :frequency_interval,
            day_of_month = :day_of_month,
            day_of_week = :day_of_week,
            start_date = :start_date,
            end_date = :end_date,
            next_due_date = :next_due_date,
            generate_days_before = :generate_days_before,
            reminder_days_before = :reminder_days_before,
            max_occurrences = :max_occurrences,
            status = :status,
            feb_template_json = :feb_template_json,
            updated_by = :updated_by,
            updated_at = NOW()
            WHERE id = :id');

        $stmt->execute([
            'id' => $id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'departement_id' => self::nullableInt($data['departement_id'] ?? null),
            'departement_label' => $data['departement_label'] ?? null,
            'chantier_id' => self::nullableInt($data['chantier_id'] ?? null),
            'fournisseur_id' => self::nullableInt($data['fournisseur_id'] ?? null),
            'fournisseur_name' => $data['fournisseur_name'] ?? null,
            'need_type' => $data['need_type'],
            'financial_mode' => $data['financial_mode'],
            'amount_estimated' => (float)$data['amount_estimated'],
            'currency' => $data['currency'] ?? 'XOF',
            'priority' => $data['priority'],
            'frequency_type' => $data['frequency_type'],
            'frequency_interval' => max(1, (int)$data['frequency_interval']),
            'day_of_month' => self::nullableInt($data['day_of_month'] ?? null),
            'day_of_week' => self::nullableInt($data['day_of_week'] ?? null),
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?: null,
            'next_due_date' => $data['next_due_date'],
            'generate_days_before' => max(0, (int)$data['generate_days_before']),
            'reminder_days_before' => max(0, (int)$data['reminder_days_before']),
            'max_occurrences' => self::nullableInt($data['max_occurrences'] ?? null),
            'status' => $data['status'] ?? 'pending_validation',
            'feb_template_json' => json_encode($data['feb_template'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by' => $userId,
        ]);
    }


    public function updateStatus(int $id, string $status, int $userId, ?string $reason = null): void
    {
        $extra = '';
        if ($status === 'active') {
            $extra = ', validated_by = :actor, validated_at = NOW()';
        } elseif ($status === 'suspended') {
            $extra = ', suspended_by = :actor, suspended_at = NOW(), suspension_reason = :reason';
        }
        $sql = "UPDATE finea_recurring_needs SET status = :status, updated_by = :actor {$extra} WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $params = ['status' => $status, 'actor' => $userId, 'id' => $id];
        if ($status === 'suspended') {
            $params['reason'] = $reason;
        }
        $stmt->execute($params);
    }



    public function dueForReminder(DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finea_recurring_needs
            WHERE status = 'active'
              AND reminder_days_before > generate_days_before
              AND DATE_SUB(next_due_date, INTERVAL reminder_days_before DAY) <= :today
              AND DATE_SUB(next_due_date, INTERVAL generate_days_before DAY) > :today
              AND (end_date IS NULL OR end_date >= next_due_date)
              AND (max_occurrences IS NULL OR generated_count < max_occurrences)
            ORDER BY next_due_date ASC LIMIT 200");
        $stmt->execute(['today' => $today->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function reminderSentToday(int $needId, string $dueDate, DateTimeImmutable $today): bool
    {
        $needle = '%échéance ' . $dueDate . '%';
        $stmt = $this->pdo->prepare("SELECT id FROM finea_recurring_need_notifications
            WHERE recurring_need_id = :need_id
              AND event_type = 'reminder_due'
              AND message LIKE :needle
              AND DATE(created_at) = :today
            LIMIT 1");
        $stmt->execute([
            'need_id' => $needId,
            'needle' => $needle,
            'today' => $today->format('Y-m-d'),
        ]);
        return (bool)$stmt->fetchColumn();
    }

    public function dueForGeneration(DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finea_recurring_needs
            WHERE status = 'active'
              AND DATE_SUB(next_due_date, INTERVAL generate_days_before DAY) <= :today
              AND (end_date IS NULL OR end_date >= next_due_date)
              AND (max_occurrences IS NULL OR generated_count < max_occurrences)
            ORDER BY next_due_date ASC LIMIT 100");
        $stmt->execute(['today' => $today->format('Y-m-d')]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function generationExists(int $needId, string $dueDate): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM finea_recurring_need_generations WHERE recurring_need_id = :id AND scheduled_due_date = :due LIMIT 1');
        $stmt->execute(['id' => $needId, 'due' => $dueDate]);
        return (bool)$stmt->fetchColumn();
    }

    public function logGeneration(int $needId, string $dueDate, string $generationDate, string $status, ?int $febId, string $message, array $payload = []): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO finea_recurring_need_generations
            (recurring_need_id, scheduled_due_date, generation_date, generated_feb_id, status, message, payload_json, generated_at)
            VALUES (:need_id,:due,:generation_date,:feb_id,:status,:message,:payload,NOW())
            ON DUPLICATE KEY UPDATE generated_feb_id = VALUES(generated_feb_id), status = VALUES(status), message = VALUES(message), payload_json = VALUES(payload_json), generated_at = NOW()');
        $stmt->execute([
            'need_id' => $needId,
            'due' => $dueDate,
            'generation_date' => $generationDate,
            'feb_id' => $febId,
            'status' => $status,
            'message' => $message,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function markGenerated(int $needId, string $nextDueDate): void
    {
        $stmt = $this->pdo->prepare('UPDATE finea_recurring_needs SET generated_count = generated_count + 1, last_generated_at = NOW(), next_due_date = :next_due WHERE id = :id');
        $stmt->execute(['next_due' => $nextDueDate, 'id' => $needId]);
    }

    public function createNotification(int $needId, ?int $generationId, ?int $userId, string $eventType, string $title, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO finea_recurring_need_notifications (recurring_need_id,generation_id,user_id,event_type,title,message,sent_at) VALUES (:need_id,:generation_id,:user_id,:event_type,:title,:message,NOW())');
        $stmt->execute([
            'need_id' => $needId,
            'generation_id' => $generationId,
            'user_id' => $userId,
            'event_type' => $eventType,
            'title' => $title,
            'message' => $message,
        ]);
    }

    private function nextReference(): string
    {
        return 'BR-' . date('Ym') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private static function nullableInt($value): ?int
    {
        $value = trim((string)$value);
        return $value === '' || $value === '0' ? null : (int)$value;
    }
}
