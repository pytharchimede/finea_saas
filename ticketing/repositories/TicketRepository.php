<?php
namespace Ticketing\Repositories;

use PDO;
use Throwable;

class TicketRepository
{
    public function __construct(private PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try { $result=$operation(); $this->pdo->commit(); return $result; }
        catch (Throwable $e) { if($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function lock(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM finea_tickets WHERE id=? FOR UPDATE');
        $stmt->execute([$id]);
        $ticket=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) throw new \DomainException('Ticket introuvable.');
        return $ticket;
    }

    public function findRequest(string $key, int $userId): ?int
    {
        $stmt=$this->pdo->prepare('SELECT id FROM finea_tickets WHERE request_key=? AND requester_id=?');
        $stmt->execute([$key,$userId]); return ($id=$stmt->fetchColumn()) ? (int)$id : null;
    }

    public function actor(int $userId): array
    {
        $stmt=$this->pdo->prepare("SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name,COALESCE(s.lib_service,'') service
            FROM user_devis_banamur u LEFT JOIN personnel p ON CONVERT(p.id_personnel_soignant USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT(u.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            LEFT JOIN service s ON s.id_service=p.service_id WHERE u.active=1 AND u.id=?");
        $stmt->execute([$userId]); $actor=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$actor) throw new \DomainException('Utilisateur actif introuvable.');
        $actor['name']=trim($actor['name']) ?: 'Utilisateur #'.$userId;
        return $actor;
    }

    public function managerIds(array $ticket): array
    {
        if(!$this->tableExists('rh_service_hierarchy')) return [];
        $stmt=$this->pdo->prepare('SELECT h.direct_manager_user_id,h.department_head_user_id FROM rh_service_hierarchy h JOIN service s ON s.id_service=h.service_id WHERE TRIM(s.lib_service)=?');
        $stmt->execute([trim((string)($ticket['assigned_service'] ?? ''))]);
        $ids=[]; foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $ids=array_merge($ids,array_values($row));
        return array_values(array_unique(array_filter(array_map('intval',$ids))));
    }

    public function pending(int $userId, string $service): array
    {
        $stmt=$this->pdo->prepare("SELECT t.*,p.name priority_name,p.color priority_color FROM finea_tickets t LEFT JOIN finea_ticket_priorities p ON p.id=t.priority_id
            WHERE t.status NOT IN ('resolu','cloture','rejete') AND ".$this->visibilitySql('t')."
            ORDER BY (t.due_at IS NOT NULL AND t.due_at<NOW()) DESC,COALESCE(t.due_at,'9999-12-31'),t.id DESC");
        $stmt->execute($this->visibilityParams($userId,$service)); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function executors(array $ticket): array
    {
        if (!empty($ticket['assigned_user_id'])) return $this->findUsersByIds([(int)$ticket['assigned_user_id']]);
        $stmt = $this->pdo->prepare("SELECT u.id, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) name
            FROM user_devis_banamur u JOIN personnel p ON CONVERT(p.id_personnel_soignant USING utf8mb4) COLLATE utf8mb4_unicode_ci=CONVERT(u.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
            JOIN service s ON s.id_service=p.service_id
            WHERE u.active=1 AND TRIM(s.lib_service)=:service ORDER BY name,u.id");
        $stmt->execute(['service'=>trim((string)($ticket['assigned_service'] ?? ''))]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function canSetDeadline(array $ticket, int $userId): bool
    {
        if ($userId <= 0) return false;
        if ($this->isServiceManager($ticket, $userId)) return true;
        return in_array($userId, array_map(static fn($u)=>(int)$u['id'], $this->executors($ticket)), true);
    }

    public function isServiceManager(array $ticket, int $userId): bool
    {
        if ($userId <= 0 || !$this->tableExists('rh_service_hierarchy')) return false;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM rh_service_hierarchy h JOIN service s ON s.id_service=h.service_id
            WHERE TRIM(s.lib_service)=:service AND (h.direct_manager_user_id=:manager OR h.department_head_user_id=:head)");
        $stmt->execute(['service'=>trim((string)($ticket['assigned_service'] ?? '')),'manager'=>$userId,'head'=>$userId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function profileSignature(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT signature, signature_data
                 FROM user_devis_banamur
                 WHERE id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $projectRoot = realpath(dirname(__DIR__, 2));

            $signaturePath = trim((string)($row['signature'] ?? ''));

            if ($signaturePath !== '' && $projectRoot) {
                $normalized = ltrim(str_replace('\\', '/', $signaturePath), '/');
                $absolute = realpath($projectRoot . '/' . $normalized);

                if (
                    $absolute &&
                    str_starts_with($absolute, $projectRoot . DIRECTORY_SEPARATOR) &&
                    is_file($absolute) &&
                    is_readable($absolute) &&
                    filesize($absolute) <= 2 * 1024 * 1024
                ) {
                    $info = @getimagesize($absolute);

                    if (
                        is_array($info) &&
                        in_array((string)($info['mime'] ?? ''), ['image/png', 'image/jpeg'], true)
                    ) {
                        $binary = @file_get_contents($absolute);

                        if (is_string($binary) && $binary !== '') {
                            return 'data:' . $info['mime'] . ';base64,' . base64_encode($binary);
                        }
                    }
                }
            }

            $signatureData = trim((string)($row['signature_data'] ?? ''));

            if (
                preg_match(
                    '#^data:image/(png|jpeg);base64,(.+)$#is',
                    $signatureData,
                    $match
                ) === 1
            ) {
                $binary = base64_decode($match[2], true);

                if (
                    $binary !== false &&
                    strlen($binary) > 0 &&
                    strlen($binary) <= 2 * 1024 * 1024
                ) {
                    $info = @getimagesizefromstring($binary);

                    if (
                        is_array($info) &&
                        in_array((string)($info['mime'] ?? ''), ['image/png', 'image/jpeg'], true)
                    ) {
                        return $signatureData;
                    }
                }
            }

            return '';
        } catch (\Throwable $e) {
            error_log(
                '[Ticketing profileSignature] user=' . $userId .
                ' error=' . $e->getMessage() .
                ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            return '';
        }
    }

    public function setDeadline(int $id, string $dueAt, array $actor): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM finea_tickets WHERE id=? FOR UPDATE');
            $stmt->execute([$id]);
            $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ticket || !$this->canSetDeadline($ticket, (int)$actor['id'])) throw new \DomainException('Seuls les exécutants et les responsables du service peuvent fixer le délai.');
            if (in_array($ticket['status'], ['resolu','cloture','rejete'], true)) throw new \DomainException('Ce ticket est terminé.');
            $stmt = $this->pdo->prepare('UPDATE finea_tickets SET due_at=?,deadline_set_by=?,deadline_set_by_name=?,deadline_set_at=NOW() WHERE id=?');
            $stmt->execute([$dueAt,(int)$actor['id'],(string)$actor['name'],$id]);
            $this->addHistory($id,null,'deadline_set',(int)$actor['id'],(string)$actor['name'],'Délai de rendu : '.$dueAt.' (précédent : '.($ticket['due_at'] ?? 'aucun').')');
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function receptionProofs(int $id): array
    {
        $stmt=$this->pdo->prepare('SELECT * FROM finea_ticket_reception_proofs WHERE ticket_id=? ORDER BY id DESC');
        $stmt->execute([$id]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addReceptionProof(int $id, array $file, array $actor): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('INSERT INTO finea_ticket_reception_proofs(ticket_id,stored_name,original_name,mime_type,size_bytes,uploaded_by,uploaded_by_name) VALUES(?,?,?,?,?,?,?)');
            $stmt->execute([$id,$file['stored_name'],$file['original_name'],$file['mime_type'],$file['size_bytes'],(int)$actor['id'],(string)$actor['name']]);
            $this->addHistory($id,null,'reception_proof',(int)$actor['id'],(string)$actor['name'],'Dépôt du bordereau de réception signé');
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function dashboardStats(?int $userId, ?string $service): array
    {
        $stats = [];
        foreach (['nouveau','pris_en_charge','en_cours','en_attente','resolu','cloture','rejete'] as $status) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM finea_tickets t WHERE t.status=:status AND ".$this->visibilitySql('t'));
            $stmt->execute(['status'=>$status]+$this->visibilityParams($userId,(string)$service));
            $stats[$status] = (int)$stmt->fetchColumn();
        }
        $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM finea_tickets t WHERE '.$this->visibilitySql('t',false));
        $stmt->execute($this->visibilityParams($userId,(string)$service,false));
        $stats['received'] = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM finea_tickets t WHERE t.due_at IS NOT NULL AND t.due_at<NOW() AND t.status NOT IN ('resolu','cloture','rejete') AND ".$this->visibilitySql('t'));
        $stmt->execute($this->visibilityParams($userId,(string)$service));
        $stats['overdue'] = (int)$stmt->fetchColumn();
        return $stats;
    }

    public function all(string $scope='all', ?int $userId=null, ?string $service=null): array
    {
        $sql = "SELECT t.*, c.name category_name, c.icon category_icon, c.color category_color,
                       p.name priority_name, p.color priority_color, p.sla_hours
                FROM finea_tickets t
                LEFT JOIN finea_ticket_categories c ON c.id=t.category_id
                LEFT JOIN finea_ticket_priorities p ON p.id=t.priority_id";
        $sql .= ' WHERE '.$this->visibilitySql('t',$scope!=='received');
        $params = $this->visibilityParams($userId,(string)$service,$scope!=='received');
        if ($scope === 'mine') { $sql .= ' AND t.requester_id=:scope_user'; $params['scope_user']=$userId; }
        elseif ($scope === 'service') { $sql .= ' AND t.assigned_service=:scope_service'; $params['scope_service']=(string)$service; }
        $sql .= " ORDER BY FIELD(t.status,'nouveau','pris_en_charge','en_cours','en_attente','resolu','cloture','rejete'), t.created_at DESC LIMIT 300";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT t.*, c.name category_name, c.icon category_icon, c.color category_color,
                       p.name priority_name, p.color priority_color, p.sla_hours
                FROM finea_tickets t
                LEFT JOIN finea_ticket_categories c ON c.id=t.category_id
                LEFT JOIN finea_ticket_priorities p ON p.id=t.priority_id
                WHERE t.id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findVisible(int $id,int $userId,string $service):?array
    {
        $stmt=$this->pdo->prepare("SELECT t.*,c.name category_name,c.icon category_icon,c.color category_color,p.name priority_name,p.color priority_color,p.sla_hours FROM finea_tickets t LEFT JOIN finea_ticket_categories c ON c.id=t.category_id LEFT JOIN finea_ticket_priorities p ON p.id=t.priority_id WHERE t.id=:id AND ".$this->visibilitySql('t').' LIMIT 1');
        $stmt->execute(['id'=>$id]+$this->visibilityParams($userId,$service));
        return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
    }

    private function visibilitySql(string $alias, bool $includeRequester=true):string
    {
        $manager = $this->tableExists('rh_service_hierarchy')
            ? " OR EXISTS(SELECT 1 FROM rh_service_hierarchy vh JOIN service vs ON vs.id_service=vh.service_id WHERE CONVERT(vs.lib_service USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT($alias.assigned_service USING utf8mb4) COLLATE utf8mb4_unicode_ci AND (vh.direct_manager_user_id=:visible_manager OR vh.department_head_user_id=:visible_head))" : '';
        $requester=$includeRequester?"$alias.requester_id=:visible_requester OR ":'';
        return "($requester$alias.assigned_user_id=:visible_assignee OR (COALESCE($alias.assigned_user_id,0)=0 AND :visible_service_check<>'' AND $alias.assigned_service=:visible_service_value) OR EXISTS(SELECT 1 FROM finea_ticket_watchers vw WHERE vw.ticket_id=$alias.id AND vw.user_id=:visible_watcher)$manager)";
    }

    private function visibilityParams(?int $userId,string $service,bool $includeRequester=true):array
    {
        $extra=$this->tableExists('rh_service_hierarchy') ? ['visible_manager'=>$userId,'visible_head'=>$userId] : [];
        if($includeRequester) $extra['visible_requester']=$userId;
        return $extra + ['visible_assignee'=>$userId,'visible_service_check'=>$service,'visible_service_value'=>$service,'visible_watcher'=>$userId];
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO finea_tickets
            (ticket_no,title,description,category_id,priority_id,requester_id,requester_name,requester_service,assigned_service,assigned_user_id,assigned_user_name,related_module,related_reference,related_reference_type,related_reference_id,chantier,chantier_id,due_at,requester_signature_data,requester_signed_at,request_key)
            VALUES (:ticket_no,:title,:description,:category_id,:priority_id,:requester_id,:requester_name,:requester_service,:assigned_service,:assigned_user_id,:assigned_user_name,:related_module,:related_reference,:related_reference_type,:related_reference_id,:chantier,:chantier_id,:due_at,:requester_signature_data,NOW(),:request_key)");
        $stmt->execute($data);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateStatus(int $id, string $status): void
    {
        $resolved = in_array($status,['resolu','cloture'],true) ? ', resolved_at=COALESCE(resolved_at,NOW())' : ', resolved_at=NULL';
        $closed = $status === 'cloture' ? ', closed_at=NOW()' : ', closed_at=NULL';
        $stmt = $this->pdo->prepare("UPDATE finea_tickets SET status=? $resolved $closed WHERE id=?");
        $stmt->execute([$status,$id]);
    }

    public function reassign(int $id, ?string $service, ?int $userId, ?string $userName): void
    {
        $stmt = $this->pdo->prepare("UPDATE finea_tickets SET assigned_service=?, assigned_user_id=?, assigned_user_name=?,status='nouveau',resolved_at=NULL,closed_at=NULL,due_at=NULL,deadline_set_by=NULL,deadline_set_by_name=NULL,deadline_set_at=NULL WHERE id=?");
        $stmt->execute([$service,$userId,$userName,$id]);
    }

    public function categories(): array { return $this->pdo->query("SELECT * FROM finea_ticket_categories WHERE is_active=1 ORDER BY name")->fetchAll(); }
    public function priorities(): array { return $this->pdo->query("SELECT * FROM finea_ticket_priorities ORDER BY level")->fetchAll(); }

    public function messages(int $ticketId, bool $includeInternal = false): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finea_ticket_messages WHERE ticket_id=?".($includeInternal?'':' AND is_internal=0')." ORDER BY created_at ASC,id ASC");
        $stmt->execute([$ticketId]); return $stmt->fetchAll();
    }

    public function addMessage(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO finea_ticket_messages(ticket_id,author_id,author_name,author_service,message,is_internal) VALUES(?,?,?,?,?,?)");
        $stmt->execute([$data['ticket_id'],$data['author_id'],$data['author_name'],$data['author_service'],$data['message'],$data['is_internal']]);
        return (int)$this->pdo->lastInsertId();
    }

    public function addAttachment(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO finea_ticket_attachments(ticket_id,message_id,original_name,stored_name,mime_type,size_bytes,uploaded_by) VALUES(?,?,?,?,?,?,?)");
        $stmt->execute([
            (int)$data['ticket_id'],
            !empty($data['message_id']) ? (int)$data['message_id'] : null,
            (string)$data['original_name'],
            (string)$data['stored_name'],
            (string)($data['mime_type'] ?? ''),
            (int)($data['size_bytes'] ?? 0),
            !empty($data['uploaded_by']) ? (int)$data['uploaded_by'] : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function attachments(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finea_ticket_attachments WHERE ticket_id=? ORDER BY created_at DESC, id DESC');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public function addHistory(int $ticketId, ?string $old, string $new, ?int $by, string $byName, ?string $comment=null): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO finea_ticket_status_history(ticket_id,old_status,new_status,changed_by,changed_by_name,comment) VALUES(?,?,?,?,?,?)");
        $stmt->execute([$ticketId,$old,$new,$by,$byName,$comment]);
    }

    public function history(int $ticketId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM finea_ticket_status_history WHERE ticket_id=? ORDER BY created_at DESC");
        $stmt->execute([$ticketId]); return $stmt->fetchAll();
    }

    public function creationOptions(?int $userId=null,string $service=''): array
    {
        $config = require __DIR__ . '/../config/services.php';
        return [
            'chantiers' => $this->chantierOptions(),
            'services' => $this->serviceOptions($config['services'] ?? []),
            'users' => $this->userOptions(),
            'modules' => $this->moduleOptions($config['modules'] ?? []),
            'references' => $this->referenceOptions($userId,$service),
        ];
    }

    public function serviceOptions(array $fallback = []): array
    {
        $rows = [];
        try {
            if ($this->tableExists('service')) {
                $rows = $this->pdo->query("SELECT id_service AS value, lib_service AS label FROM service WHERE TRIM(COALESCE(lib_service,'')) <> '' ORDER BY lib_service LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        } catch (Throwable $e) {
            $rows = [];
        }

        foreach ($fallback as $service) {
            $service = trim((string)$service);
            if ($service !== '') {
                $rows[] = ['value' => $service, 'label' => $service];
            }
        }

        $options = $this->uniqueOptions($rows, true);
        foreach ($options as &$option) {
            $option['value'] = $option['label'];
        }
        unset($option);
        return $options;
    }

    public function chantierOptions(): array
    {
        if (!$this->tableExists('chantier_banamur')) {
            return [];
        }

        try {
            $where = '';
            $columns = $this->tableColumns('chantier_banamur');
            if (in_array('archived_at', $columns, true)) {
                $where = 'WHERE archived_at IS NULL';
            }
            if ($where === '' && in_array('etat_chantier', $columns, true)) {
                $where = "WHERE (etat_chantier IS NULL OR etat_chantier NOT IN (0, '0', 'clos', 'cloture', 'clôturé', 'termine', 'terminé'))";
            } elseif ($where === '' && in_array('is_active', $columns, true)) {
                $where = 'WHERE is_active = 1';
            } elseif ($where === '' && in_array('active', $columns, true)) {
                $where = 'WHERE active = 1';
            }
            $rows = $this->pdo->query("SELECT id AS value, CONCAT(COALESCE(code,''), CASE WHEN COALESCE(code,'')<>'' THEN ' - ' ELSE '' END, COALESCE(titre,'')) AS label FROM chantier_banamur $where ORDER BY code, titre LIMIT 2500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $this->uniqueOptions($rows);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function userOptions(): array
    {
        if (!$this->tableExists('user_devis_banamur')) {
            return [];
        }
        try {
            $rows = $this->pdo->query("
                SELECT u.id AS value,
                       TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''))) AS name,
                       COALESCE(u.mail_pro,'') AS email,
                       COALESCE(s.lib_service,'') AS service_label
                FROM user_devis_banamur u
                LEFT JOIN personnel p ON CONVERT(p.id_personnel_soignant USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE u.active = 1
                ORDER BY u.prenom, u.nom, u.mail_pro
                LIMIT 1500
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = $this->pdo->query("SELECT id AS value, TRIM(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''))) AS name, COALESCE(mail_pro,'') AS email, '' AS service_label FROM user_devis_banamur WHERE active = 1 ORDER BY prenom, nom LIMIT 1500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $options = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            $service = trim((string)($row['service_label'] ?? ''));
            $label = $name !== '' ? $name : $email;
            if ($label === '') {
                continue;
            }
            $options[] = [
                'value' => (string)(int)$row['value'],
                'label' => $label . ($service !== '' ? ' · ' . $service : '') . ($email !== '' ? ' · ' . $email : ''),
                'name' => $label,
                'email' => $email,
                'service' => $service,
            ];
        }
        return $options;
    }

    public function moduleOptions(array $fallback): array
    {
        $rows = [];
        foreach ($fallback as $module) {
            $module = trim((string)$module);
            if ($module !== '') {
                $rows[] = ['value' => $module, 'label' => $module];
            }
        }
        return $this->uniqueOptions($rows, true);
    }

    public function referenceOptions(?int $userId=null,string $service=''): array
    {
        $out = [];

        if ($this->tableExists('employee_legal_requests')) {
            try {
                $rows = $this->pdo->query("
                    SELECT id, title, requester_name, status, submitted_at
                    FROM employee_legal_requests
                    WHERE request_type = 'feb'
                    ORDER BY submitted_at DESC, id DESC
                    LIMIT 400
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $out[] = [
                        'value' => 'feb:' . (int)$row['id'],
                        'label' => 'FEB #' . (int)$row['id'] . ' · ' . trim((string)$row['title']) . ' · ' . trim((string)$row['requester_name']),
                        'type' => 'feb',
                        'id' => (string)(int)$row['id'],
                    ];
                }
            } catch (Throwable $e) {}
        }

        if ($this->tableExists('fiche_decaissement')) {
            try {
                $rows = $this->pdo->query("
                    SELECT id_fiche, num_fiche, beficiaire_fiche, montant_fiche, date_creat_fiche
                    FROM fiche_decaissement
                    ORDER BY date_creat_fiche DESC, id_fiche DESC
                    LIMIT 400
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $amount = number_format((float)($row['montant_fiche'] ?? 0), 0, ',', ' ');
                    $out[] = [
                        'value' => 'fiche_decaissement:' . (int)$row['id_fiche'],
                        'label' => 'Fiche ' . trim((string)$row['num_fiche']) . ' · ' . trim((string)$row['beficiaire_fiche']) . ' · ' . $amount . ' FCFA',
                        'type' => 'fiche_decaissement',
                        'id' => (string)(int)$row['id_fiche'],
                    ];
                }
            } catch (Throwable $e) {}
        }

        try {
            $ticketSql="
                SELECT id, ticket_no, title, status
                FROM finea_tickets t
                WHERE ".$this->visibilitySql('t')."
                ORDER BY created_at DESC, id DESC
                LIMIT 400
            ";$ticketStmt=$this->pdo->prepare($ticketSql);$ticketStmt->execute($this->visibilityParams($userId,$service));$rows=$ticketStmt->fetchAll(PDO::FETCH_ASSOC)?:[];
            foreach ($rows as $row) {
                $out[] = [
                    'value' => 'ticket:' . (int)$row['id'],
                    'label' => trim((string)$row['ticket_no']) . ' · ' . trim((string)$row['title']) . ' · ' . trim((string)$row['status']),
                    'type' => 'ticket',
                    'id' => (string)(int)$row['id'],
                ];
            }
        } catch (Throwable $e) {}

        return $out;
    }

    public function replaceWatchers(int $ticketId, array $userIds): void
    {
        $ticketId = (int)$ticketId;
        if ($ticketId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM finea_ticket_watchers WHERE ticket_id = ?')->execute([$ticketId]);
        $users = $this->findUsersByIds($userIds);
        if (!$users) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO finea_ticket_watchers(ticket_id,user_id,user_name) VALUES(?,?,?)');
        foreach ($users as $user) {
            $stmt->execute([$ticketId, (int)$user['id'], (string)$user['name']]);
        }
    }

    public function addWatchers(int $ticketId, array $userIds): array
    {
        $ticketId = (int)$ticketId;
        if ($ticketId <= 0) {
            return [];
        }
        $users = $this->findUsersByIds($userIds);
        if (!$users) {
            return [];
        }
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO finea_ticket_watchers(ticket_id,user_id,user_name) VALUES(?,?,?)');
        $added=[];
        foreach ($users as $user) {
            $stmt->execute([$ticketId, (int)$user['id'], (string)$user['name']]);
            if($stmt->rowCount()>0) $added[]=$user;
        }
        return $added;
    }

    public function watchers(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM finea_ticket_watchers WHERE ticket_id=? ORDER BY user_name');
        $stmt->execute([$ticketId]);
        return $stmt->fetchAll();
    }

    public function notificationRecipients(array $ticket): array
    {
        $users = [];
        if (!empty($ticket['assigned_user_id'])) {
            $users = array_merge($users, $this->findUsersByIds([(int)$ticket['assigned_user_id']]));
        }
        // Une affectation collective reste consultable par le service, sans
        // notifier chaque membre. Les rappels sont réservés aux affectations
        // nominatives afin d'éviter les popups diffusés à tout un service.
        $users = array_merge($users, $this->findUsersByIds(array_merge([(int)($ticket['requester_id'] ?? 0)],$this->managerIds($ticket))));
        $watchers = $this->watchers((int)($ticket['id'] ?? 0));
        $users = array_merge($users, $this->findUsersByIds(array_column($watchers, 'user_id')));

        $unique = [];
        foreach ($users as $user) {
            $id = (int)($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $unique[$id] = $user;
        }
        return array_values($unique);
    }

    public function findUsersByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids || !$this->tableExists('user_devis_banamur')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,''))) AS name, mail_pro AS email FROM user_devis_banamur WHERE active=1 AND id IN ($placeholders)");
        $stmt->execute($ids);
        return $this->normalizeUsers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function findUsersByService(string $service): array
    {
        $service = trim($service);
        if ($service === '' || !$this->tableExists('user_devis_banamur')) {
            return [];
        }
        try {
            $stmt = $this->pdo->prepare("
                SELECT u.id, TRIM(CONCAT(COALESCE(u.prenom,''), ' ', COALESCE(u.nom,''))) AS name, u.mail_pro AS email
                FROM user_devis_banamur u
                INNER JOIN personnel p ON CONVERT(p.id_personnel_soignant USING utf8mb4) COLLATE utf8mb4_unicode_ci = CONVERT(u.personnel_id USING utf8mb4) COLLATE utf8mb4_unicode_ci
                INNER JOIN service s ON s.id_service = p.service_id
                WHERE u.active = 1
                  AND (
                    LOWER(TRIM(s.lib_service)) = LOWER(TRIM(:service_exact))
                  )
            ");
            $stmt->execute([
                'service_exact' => $service,
            ]);
            return $this->normalizeUsers($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (Throwable $e) {
            return [];
        }
    }

    public function insertUserNotification(int $userId, string $title, string $message, string $link, array $payload = []): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO system_notifications(created_at,event_key,sub_key,title,message,link_url,recipient_team,recipient_user_id,channels_json,payload_json)
            VALUES(NOW(),'ticketing',:sub_key,:title,:message,:link,NULL,:uid,:channels,:payload)
        ");
        $stmt->execute([
            'sub_key' => (string)($payload['event'] ?? 'created'),
            'title' => $title,
            'message' => $message,
            'link' => $link,
            'uid' => $userId,
            'channels' => json_encode(['system'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function upsertNotificationLog(
        int $ticketId,
        array $recipient,
        string $channel,
        ?int $notificationId,
        ?int $actorId = null,
        ?int $emailLogId = null,
        string $status = 'sent',
        string $errorMessage = ''
    ): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO finea_ticket_notification_logs(
                ticket_id,recipient_user_id,recipient_name,recipient_email,channel,
                notification_id,email_log_id,status,error_message,sent_at,last_sent_at,resent_count,created_by
            )
            VALUES(
                :ticket_id,:uid,:name,:email,:channel,
                :notification_id,:email_log_id,:status,NULLIF(:error_message,''),NOW(),NOW(),0,:created_by
            )
            ON DUPLICATE KEY UPDATE
                recipient_name=VALUES(recipient_name),
                recipient_email=VALUES(recipient_email),
                notification_id=VALUES(notification_id),
                email_log_id=VALUES(email_log_id),
                status=VALUES(status),
                error_message=VALUES(error_message),
                last_sent_at=NOW(),
                resent_count=resent_count+1
        ");
        $stmt->execute([
            'ticket_id' => $ticketId,
            'uid' => (int)($recipient['id'] ?? 0),
            'name' => (string)($recipient['name'] ?? ''),
            'email' => (string)($recipient['email'] ?? ''),
            'channel' => $channel,
            'notification_id' => $notificationId,
            'email_log_id' => $emailLogId,
            'status' => $status,
            'error_message' => mb_substr(trim($errorMessage), 0, 2000),
            'created_by' => $actorId,
        ]);
    }

    public function markTicketViewed(int $ticketId, int $userId): void
    {
        if ($ticketId <= 0 || $userId <= 0) {
            return;
        }
        $stmt = $this->pdo->prepare("
            INSERT INTO finea_ticket_views(ticket_id,user_id,first_viewed_at,last_viewed_at,view_count)
            VALUES(:ticket_id,:user_id,NOW(),NOW(),1)
            ON DUPLICATE KEY UPDATE last_viewed_at=NOW(), view_count=view_count+1
        ");
        $stmt->execute(['ticket_id' => $ticketId, 'user_id' => $userId]);
    }

    public function notificationLogs(int $ticketId, string $ticketNo = ''): array
    {
        $hasReads=$this->tableExists('system_notification_reads');
        $hasEmail=$this->tableExists('email_logs');
        $readSelect=$hasReads?'r.read_at':'NULL';
        $emailSelect=$hasEmail?'el.id matched_email_log_id,el.status email_delivery_status,el.sent_at email_sent_at,el.opened_at email_opened_at,el.error_message email_error_message':'NULL matched_email_log_id,NULL email_delivery_status,NULL email_sent_at,NULL email_opened_at,NULL email_error_message';
        $sql="SELECT l.*,$readSelect system_read_at,v.first_viewed_at ticket_first_viewed_at,v.last_viewed_at ticket_last_viewed_at,v.view_count ticket_view_count,$emailSelect
            FROM finea_ticket_notification_logs l
            LEFT JOIN finea_ticket_views v ON v.ticket_id=l.ticket_id AND v.user_id=l.recipient_user_id";
        if($hasReads) $sql.=' LEFT JOIN system_notification_reads r ON r.notification_id=l.notification_id AND r.user_id=l.recipient_user_id';
        if($hasEmail) $sql.=" LEFT JOIN email_logs el ON el.id=l.email_log_id AND l.channel='email'";
        $stmt=$this->pdo->prepare($sql.' WHERE l.ticket_id=? ORDER BY l.recipient_name,l.channel');
        $stmt->execute([$ticketId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as &$row) {
            if($row['channel']==='system') $row['read_at']=$row['system_read_at'];
            else {
                $row['status']=$row['email_delivery_status'] ?: $row['status'];
                $row['opened_at']=$row['email_opened_at'];
                $row['error_message']=$row['email_error_message'] ?: $row['error_message'];
            }
        }
        unset($row); return $rows;
    }

    private function normalizeUsers(array $rows): array
    {
        $users = [];
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $email = trim((string)($row['email'] ?? ''));
            if ($name === '') {
                $name = $email !== '' ? $email : ('Utilisateur #' . (int)($row['id'] ?? 0));
            }
            $users[] = ['id' => (int)($row['id'] ?? 0), 'name' => $name, 'email' => $email];
        }
        return $users;
    }

    private function uniqueOptions(array $rows, bool $useLabelAsValueIfText = false): array
    {
        $out = [];
        foreach ($rows as $row) {
            $label = trim((string)($row['label'] ?? ''));
            $value = trim((string)($row['value'] ?? ''));
            if ($label === '') {
                continue;
            }
            if ($useLabelAsValueIfText && !ctype_digit($value)) {
                $value = $label;
            }
            if ($value === '') {
                $value = $label;
            }
            $out[$label] = ['value' => $value, 'label' => $label];
        }
        ksort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return array_values($out);
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function tableColumns(string $table): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
