<?php

class UrgentDisbursementRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // CREATE TABLE provoque un COMMIT implicite sous MySQL, même avec
        // IF NOT EXISTS. approveRequest() instancie ce dépôt au milieu de la
        // transaction atomique FEB + fiche : ne jamais y exécuter de DDL.
        // Le service est déjà instancié une première fois avant toute
        // transaction sur les pages concernées, ce qui prépare la table.
        if (!$pdo->inTransaction()) {
            $this->ensureTable();
        }
    }

    public function create(array $data): int
    {
        $code = $this->nextCode();
        $stmt = $this->pdo->prepare('
            INSERT INTO urgent_disbursement_requests (
                request_code, requester_user_id, requester_name, requester_email, requester_personnel_id,
                chantier_id, chantier_code, chantier_label, libelle, details, amount,
                justification_path, justification_name, justification_mime, justification_size,
                status, submitted_at, created_at, updated_at
            ) VALUES (
                :request_code, :requester_user_id, :requester_name, :requester_email, :requester_personnel_id,
                :chantier_id, :chantier_code, :chantier_label, :libelle, :details, :amount,
                :justification_path, :justification_name, :justification_mime, :justification_size,
                "submitted", NOW(), NOW(), NOW()
            )
        ');
        $stmt->execute([
            'request_code' => $code,
            'requester_user_id' => (int)($data['requester_user_id'] ?? 0),
            'requester_name' => (string)($data['requester_name'] ?? ''),
            'requester_email' => (string)($data['requester_email'] ?? ''),
            'requester_personnel_id' => (int)($data['requester_personnel_id'] ?? 0) ?: null,
            'chantier_id' => (int)($data['chantier_id'] ?? 0),
            'chantier_code' => (string)($data['chantier_code'] ?? ''),
            'chantier_label' => (string)($data['chantier_label'] ?? ''),
            'libelle' => (string)($data['libelle'] ?? ''),
            'details' => (string)($data['details'] ?? ''),
            'amount' => (float)($data['amount'] ?? 0),
            'justification_path' => (string)($data['justification_path'] ?? ''),
            'justification_name' => (string)($data['justification_name'] ?? ''),
            'justification_mime' => (string)($data['justification_mime'] ?? ''),
            'justification_size' => (int)($data['justification_size'] ?? 0),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM urgent_disbursement_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function list(array $filters = []): array
    {
        $where = [];
        $params = [];
        $ids = $filters['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = explode(',', (string)$ids);
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        if ($ids) {
            $placeholders = [];
            foreach ($ids as $i => $id) {
                $key = 'id_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = $id;
            }
            $where[] = 'u.id IN (' . implode(',', $placeholders) . ')';
        }
        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'u.status = :status';
            $params['status'] = $status;
        }
        $search = trim((string)($filters['q'] ?? ''));
        if ($search !== '') {
            $where[] = '(u.request_code LIKE :q OR u.requester_name LIKE :q OR u.chantier_label LIKE :q OR u.libelle LIKE :q OR u.details LIKE :q OR u.regularized_fiche_num LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $dateFrom = trim((string)($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 'DATE(u.submitted_at) >= :date_from';
            $params['date_from'] = $dateFrom;
        }
        $dateTo = trim((string)($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 'DATE(u.submitted_at) <= :date_to';
            $params['date_to'] = $dateTo;
        }
        $chantier = trim((string)($filters['chantier'] ?? ''));
        if ($chantier !== '') {
            $where[] = '(u.chantier_code LIKE :chantier OR u.chantier_label LIKE :chantier)';
            $params['chantier'] = '%' . $chantier . '%';
        }
        $amountMin = trim((string)($filters['amount_min'] ?? ''));
        if ($amountMin !== '' && is_numeric(str_replace(',', '.', $amountMin))) {
            $where[] = 'u.amount >= :amount_min';
            $params['amount_min'] = (float)str_replace(',', '.', $amountMin);
        }
        $amountMax = trim((string)($filters['amount_max'] ?? ''));
        if ($amountMax !== '' && is_numeric(str_replace(',', '.', $amountMax))) {
            $where[] = 'u.amount <= :amount_max';
            $params['amount_max'] = (float)str_replace(',', '.', $amountMax);
        }
        $sql = '
            SELECT u.*,
                   p.photo_personnel_soignant AS requester_personnel_photo,
                   p.tel_personnel_soignant AS requester_phone,
                   p.matricule_personnel_soignant AS requester_matricule,
                   sv.lib_service AS requester_service,
                   fn.lib_fonction AS requester_function,
                   usr.photo AS requester_user_photo
            FROM urgent_disbursement_requests u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.requester_personnel_id
            LEFT JOIN service sv ON sv.id_service = p.service_id
            LEFT JOIN fonction fn ON fn.id_fonction = p.fonction_id
            LEFT JOIN user_devis_banamur usr ON usr.id = u.requester_user_id
        ';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY FIELD(u.status, "submitted", "decaissed", "regularization_pending", "regularized", "rejected"), u.submitted_at DESC, u.id DESC LIMIT 2000';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function summarize(array $rows): array
    {
        $summary = [
            'count' => count($rows),
            'total' => 0.0,
            'by_status' => [],
        ];
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (!isset($summary['by_status'][$status])) {
                $summary['by_status'][$status] = ['count' => 0, 'total' => 0.0];
            }
            $amount = (float)($row['amount'] ?? 0);
            $summary['total'] += $amount;
            $summary['by_status'][$status]['count']++;
            $summary['by_status'][$status]['total'] += $amount;
        }
        return $summary;
    }

    public function listForRequester(int $userId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM urgent_disbursement_requests
            WHERE requester_user_id = :uid
            ORDER BY submitted_at DESC, id DESC
            LIMIT ' . max(1, min(200, $limit))
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markDecaissed(int $id, int $actorUserId, string $actorName, string $note = ''): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE urgent_disbursement_requests
            SET status = "decaissed",
                decaissed_at = NOW(),
                decaissed_by_user_id = :actor_id,
                decaissed_by_name = :actor_name,
                regularization_due_at = DATE_ADD(NOW(), INTERVAL 3 DAY),
                decision_note = :note,
                updated_at = NOW()
            WHERE id = :id
              AND status IN ("submitted", "regularization_pending")
            LIMIT 1
        ');
        $stmt->execute([
            'id' => $id,
            'actor_id' => $actorUserId,
            'actor_name' => $actorName,
            'note' => $note,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function reject(int $id, int $actorUserId, string $actorName, string $note = ''): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE urgent_disbursement_requests
            SET status = "rejected", decaissed_by_user_id = :actor_id, decaissed_by_name = :actor_name,
                decision_note = :note, updated_at = NOW()
            WHERE id = :id AND status = "submitted"
            LIMIT 1
        ');
        $stmt->execute(['id' => $id, 'actor_id' => $actorUserId, 'actor_name' => $actorName, 'note' => $note]);
        return $stmt->rowCount() > 0;
    }

    public function markRegularized(int $id, int $febRequestId, string $ficheNum = ''): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE urgent_disbursement_requests
            SET status = "regularized",
                regularized_feb_request_id = :feb_id,
                regularized_fiche_num = :fiche_num,
                regularized_at = NOW(),
                updated_at = NOW()
            WHERE id = :id
            LIMIT 1
        ');
        $stmt->execute(['id' => $id, 'feb_id' => $febRequestId, 'fiche_num' => $ficheNum]);
        return $stmt->rowCount() > 0;
    }

    public function setRegularizationPending(int $id, int $febRequestId): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE urgent_disbursement_requests
            SET status = IF(status = "decaissed", "regularization_pending", status),
                regularized_feb_request_id = :feb_id,
                updated_at = NOW()
            WHERE id = :id
              AND status IN ("decaissed", "regularization_pending")
              AND (regularized_feb_request_id IS NULL OR regularized_feb_request_id = :existing_feb_id)
            LIMIT 1
        ');
        $stmt->execute(['id' => $id, 'feb_id' => $febRequestId, 'existing_feb_id' => $febRequestId]);
        return $stmt->rowCount() > 0;
    }

    public function listExpiredUnregularizedForUser(int $userId): array
    {
        $this->refreshRegularizationLinks();
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM urgent_disbursement_requests
            WHERE requester_user_id = :uid
              AND status IN ("decaissed", "regularization_pending")
               AND regularized_feb_request_id IS NULL
              AND regularization_due_at IS NOT NULL
              AND regularization_due_at < NOW()
            ORDER BY regularization_due_at ASC, id ASC
        ');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listOpenUnregularizedForUser(int $userId): array
    {
        $this->refreshRegularizationLinks();
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM urgent_disbursement_requests
            WHERE requester_user_id = :uid
              AND status IN ("decaissed", "regularization_pending")
               AND regularized_feb_request_id IS NULL
            ORDER BY decaissed_at ASC, id ASC
        ');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listBlockedChantiers(): array
    {
        $this->refreshRegularizationLinks();
        $stmt = $this->pdo->query('
            SELECT *
            FROM urgent_disbursement_requests
            WHERE status IN ("decaissed", "regularization_pending")
              AND regularized_feb_request_id IS NULL
              AND regularization_due_at IS NOT NULL
              AND regularization_due_at < NOW()
            ORDER BY chantier_code ASC, requester_name ASC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findBlockingForChantier(int $chantierId, int $exceptUrgentId = 0): ?array
    {
        if ($chantierId <= 0) {
            return null;
        }

        $this->refreshRegularizationLinks();
        $sql = '
            SELECT *
            FROM urgent_disbursement_requests
            WHERE chantier_id = :chantier_id
              AND status IN ("decaissed", "regularization_pending")
               AND regularized_feb_request_id IS NULL
              AND regularization_due_at IS NOT NULL
              AND regularization_due_at < NOW()
        ';
        $params = ['chantier_id' => $chantierId];
        if ($exceptUrgentId > 0) {
            $sql .= ' AND id <> :except_id';
            $params['except_id'] = $exceptUrgentId;
        }
        $sql .= ' ORDER BY regularization_due_at ASC, id ASC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function findActiveUrgencyForChantier(int $chantierId): ?array
    {
        if ($chantierId <= 0) {
            return null;
        }

        $this->refreshRegularizationLinks();
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM urgent_disbursement_requests
            WHERE chantier_id = :chantier_id
              AND status IN ("submitted", "decaissed", "regularization_pending")
              AND (status = "submitted" OR regularized_feb_request_id IS NULL)
            ORDER BY submitted_at DESC, id DESC
            LIMIT 1
        ');
        $stmt->execute(['chantier_id' => $chantierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return is_array($row) ? $row : null;
    }

    public function listChantiers(): array
    {
        try {
            $stmt = $this->pdo->query('
                SELECT id, code, titre, devis_id
                FROM chantier_banamur
                WHERE archived_at IS NULL
                ORDER BY code, titre
                LIMIT 2500
            ');
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $stmt = $this->pdo->query('SELECT id, code, titre, devis_id FROM chantier_banamur WHERE archived_at IS NULL ORDER BY code, titre LIMIT 2500');
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    }

    private function nextCode(): string
    {
        return 'URG-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }

    private function refreshRegularizationLinks(): void
    {
        try {
            $rows = $this->pdo->query('
                SELECT id, regularized_feb_request_id
                FROM urgent_disbursement_requests
                WHERE status IN ("decaissed", "regularization_pending")
                LIMIT 200
            ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $urgentId = (int)($row['id'] ?? 0);
            if ($urgentId <= 0) {
                continue;
            }
            $febId = (int)($row['regularized_feb_request_id'] ?? 0);
            $feb = null;
            try {
                if ($febId > 0) {
                    $stmt = $this->pdo->prepare('SELECT id, payload_json FROM employee_legal_requests WHERE id = :id LIMIT 1');
                    $stmt->execute(['id' => $febId]);
                    $feb = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                } else {
                    $stmt = $this->pdo->prepare('
                        SELECT id, payload_json
                        FROM employee_legal_requests
                        WHERE request_type = "feb"
                          AND payload_json LIKE :needle
                        ORDER BY id DESC
                        LIMIT 1
                    ');
                    $stmt->execute(['needle' => '%"urgent_decaissement_id":' . $urgentId . '%']);
                    $feb = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($feb) {
                        $febId = (int)$feb['id'];
                        $this->setRegularizationPending($urgentId, $febId);
                    }
                }
            } catch (Throwable $e) {
                continue;
            }

            if (!$feb || $febId <= 0) {
                continue;
            }

            $payload = json_decode((string)($feb['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                continue;
            }
            $code = trim((string)($payload['numero_bon_commande_interne'] ?? $payload['code_autorisation_feb'] ?? $payload['bci_number'] ?? ''));
            if ($code === '') {
                continue;
            }

            try {
                $stmt = $this->pdo->prepare('
                    SELECT num_fiche
                    FROM fiche_decaissement
                    WHERE code_autorisation_feb = :code
                    ORDER BY id_fiche DESC
                    LIMIT 1
                ');
                $stmt->execute(['code' => $code]);
                $ficheNum = trim((string)($stmt->fetchColumn() ?: ''));
                if ($ficheNum !== '') {
                    $this->markRegularized($urgentId, $febId, $ficheNum);
                }
            } catch (Throwable $e) {
            }
        }
    }

    private function ensureTable(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS urgent_disbursement_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_code VARCHAR(40) NOT NULL UNIQUE,
                requester_user_id INT NOT NULL,
                requester_name VARCHAR(190) NOT NULL DEFAULT "",
                requester_email VARCHAR(190) NOT NULL DEFAULT "",
                requester_personnel_id INT NULL,
                chantier_id INT NOT NULL,
                chantier_code VARCHAR(80) NOT NULL DEFAULT "",
                chantier_label VARCHAR(255) NOT NULL DEFAULT "",
                libelle VARCHAR(255) NOT NULL DEFAULT "",
                details TEXT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                justification_path VARCHAR(255) NOT NULL DEFAULT "",
                justification_name VARCHAR(255) NOT NULL DEFAULT "",
                justification_mime VARCHAR(120) NOT NULL DEFAULT "",
                justification_size INT NOT NULL DEFAULT 0,
                status VARCHAR(40) NOT NULL DEFAULT "submitted",
                submitted_at DATETIME NOT NULL,
                decaissed_at DATETIME NULL,
                decaissed_by_user_id INT NULL,
                decaissed_by_name VARCHAR(190) NOT NULL DEFAULT "",
                regularization_due_at DATETIME NULL,
                regularized_feb_request_id INT NULL,
                regularized_fiche_num VARCHAR(80) NOT NULL DEFAULT "",
                regularized_at DATETIME NULL,
                decision_note TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NULL,
                KEY idx_urgent_status (status),
                KEY idx_urgent_requester (requester_user_id),
                KEY idx_urgent_chantier (chantier_id),
                KEY idx_urgent_due (regularization_due_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
