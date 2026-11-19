<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/AuditTrail.php';

class AccessRequestSupportSettings
{
    private const IT_SERVICE_ID = 2;
    private const IT_SERVICE_LABEL = 'INFORMATIQUE';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public static function connect(): self
    {
        return new self(\Database::getConnection());
    }

    public function getItCandidates(): array
    {
        $sql = '
            SELECT
                u.id,
                u.prenom,
                u.nom,
                u.mail_pro,
                u.personnel_id,
                p.service_id,
                COALESCE(NULLIF(TRIM(s.lib_service), ""), "") AS service_label
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            WHERE u.active = 1
              AND COALESCE(NULLIF(TRIM(u.mail_pro), ""), "") <> ""
            ORDER BY s.lib_service ASC, u.prenom ASC, u.nom ASC, u.id ASC
        ';

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $items = [];
        foreach ($rows as $row) {
            $serviceId = isset($row['service_id']) ? (int) $row['service_id'] : null;
            $serviceLabel = trim((string)($row['service_label'] ?? ''));
            if (!$this->isItService($serviceId, $serviceLabel)) {
                continue;
            }

            $name = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            if ($name === '') {
                $name = 'Utilisateur #' . (int)($row['id'] ?? 0);
            }

            $items[] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => $name,
                'email' => trim((string)($row['mail_pro'] ?? '')),
                'service_id' => $serviceId,
                'service_label' => $serviceLabel,
                'personnel_id' => isset($row['personnel_id']) ? (int)$row['personnel_id'] : null,
            ];
        }

        return $items;
    }

    public function getSelectedUserIds(): array
    {
        $stmt = $this->pdo->query('SELECT user_id FROM access_request_support_recipient ORDER BY sort_order ASC, user_id ASC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
        return array_values(array_unique(array_map('intval', $rows)));
    }

    public function getSelectedRecipients(): array
    {
        $candidates = $this->getItCandidates();
        $byId = [];
        foreach ($candidates as $candidate) {
            $byId[(int)$candidate['id']] = $candidate;
        }

        $selected = [];
        foreach ($this->getSelectedUserIds() as $userId) {
            if (isset($byId[$userId])) {
                $selected[] = $byId[$userId];
            }
        }

        if ($selected !== []) {
            return $selected;
        }

        return $candidates;
    }

    public function saveSelection(array $userIds, array $actor = []): void
    {
        $allowed = [];
        foreach ($this->getItCandidates() as $candidate) {
            $allowed[(int)$candidate['id']] = $candidate;
        }

        $normalized = [];
        foreach ($userIds as $userId) {
            $userId = (int)$userId;
            if ($userId <= 0 || !isset($allowed[$userId]) || isset($normalized[$userId])) {
                continue;
            }
            $normalized[$userId] = count($normalized) + 1;
        }

        if ($normalized === []) {
            throw new RuntimeException('Sélectionnez au moins un employé du service informatique.');
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('DELETE FROM access_request_support_recipient');
            $stmt = $this->pdo->prepare('INSERT INTO access_request_support_recipient (user_id, sort_order, created_at, updated_at) VALUES (:user_id, :sort_order, NOW(), NOW())');
            foreach ($normalized as $userId => $sortOrder) {
                $stmt->execute([
                    ':user_id' => $userId,
                    ':sort_order' => $sortOrder,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        \AuditTrail::log('access_request_support_settings_updated', [
            'module' => 'admin',
            'action' => 'access_request_support_settings',
            'entity_type' => 'access_request_support_settings',
            'entity_label' => 'support_it_recipients',
            'user_id' => isset($actor['user_id']) ? (int)$actor['user_id'] : null,
            'user_name' => isset($actor['user_name']) ? (string)$actor['user_name'] : null,
            'result' => 'success',
            'details' => [
                'selected_user_ids' => array_keys($normalized),
                'selected_count' => count($normalized),
            ],
        ], $this->pdo);
    }

    public function getRecentRequests(int $limit = 25): array
    {
        $this->ensureAccessRequestsTable();

        $stmt = $this->pdo->prepare('
            SELECT id, requester_name, requester_email, requested_page, primary_permission_label, status, created_at, support_email_sent_at, decided_at, admin_user_name
            FROM access_requests
            ORDER BY created_at DESC, id DESC
            LIMIT :limit
        ');
        $stmt->bindValue(':limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function isItService(?int $serviceId, string $label): bool
    {
        if ($serviceId !== null && $serviceId === self::IT_SERVICE_ID) {
            return true;
        }

        $label = function_exists('mb_strtolower') ? mb_strtolower(trim($label), 'UTF-8') : strtolower(trim($label));
        if ($label === '') {
            return false;
        }

        $exactReference = function_exists('mb_strtolower')
            ? mb_strtolower(self::IT_SERVICE_LABEL, 'UTF-8')
            : strtolower(self::IT_SERVICE_LABEL);
        if ($label === $exactReference) {
            return true;
        }

        $keywords = [
            'informatique',
            'support informatique',
            'it',
            'dsi',
            'systeme d information',
            'système d information',
            'reseau',
            'réseau',
        ];

        foreach ($keywords as $keyword) {
            if ($label === $keyword || str_contains($label, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function ensureTables(): void
    {
        $this->ensureSupportRecipientsTable();
        $this->ensureAccessRequestsTable();
    }

    private function ensureSupportRecipientsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS access_request_support_recipient (
                user_id INT NOT NULL PRIMARY KEY,
                sort_order INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_access_request_support_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function ensureAccessRequestsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS access_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                requester_user_id INT NOT NULL,
                requester_name VARCHAR(190) NOT NULL,
                requester_email VARCHAR(190) NOT NULL,
                requested_page VARCHAR(255) NOT NULL,
                requested_page_url TEXT NULL,
                origin_url TEXT NULL,
                primary_permission VARCHAR(120) NOT NULL,
                primary_permission_label VARCHAR(190) NOT NULL,
                alternative_permissions_json LONGTEXT NULL,
                access_type_label VARCHAR(120) NULL,
                module_label VARCHAR(190) NULL,
                status VARCHAR(30) NOT NULL DEFAULT "pending",
                granted_permission VARCHAR(120) NULL,
                decision_message TEXT NULL,
                admin_token_hash CHAR(64) NOT NULL,
                admin_token_expires_at DATETIME NULL,
                support_email_sent_at DATETIME NULL,
                decided_at DATETIME NULL,
                admin_user_id INT NULL,
                admin_user_name VARCHAR(190) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_requester_user_id (requester_user_id),
                INDEX idx_status (status),
                INDEX idx_admin_token_hash (admin_token_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
