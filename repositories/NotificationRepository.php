<?php

class NotificationRepository
{
    private PDO $pdo;
    private static bool $tablesEnsured = false;

    private const TEAM_RH = 'rh';
    private const TEAM_FINANCE = 'finance';
    private const TEAM_ADMIN = 'admin';
    private const TEAM_LOGISTIQUE = 'logistique';
    private const TEAM_HSE = 'hse';
    private const TEAM_ALL = 'all';
    private const RECIPIENT_REQUESTER = 'requester';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public static function getKnownTeams(): array
    {
        return [
            self::TEAM_RH,
            self::TEAM_FINANCE,
            self::TEAM_ADMIN,
            self::TEAM_LOGISTIQUE,
            self::TEAM_HSE,
            self::TEAM_ALL,
        ];
    }

    public static function isValidRecipientTarget(string $target): bool
    {
        $target = strtolower(trim($target));
        if ($target === self::RECIPIENT_REQUESTER) {
            return true;
        }
        return in_array($target, self::getKnownTeams(), true);
    }

    public function countUnread(int $userId, array $teams): int
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }

        [$where, $params] = $this->buildRecipientWhere($userId, $teams);
        $preferenceEventSql = self::preferenceEventSql('n.event_key');
        $exactPreferenceSql = self::exactPreferenceEventSql('n.event_key', 'n.sub_key');

        $stmt = $this->pdo->prepare('
            SELECT COUNT(*)
            FROM system_notifications n
            LEFT JOIN system_notification_reads r
              ON r.notification_id = n.id AND r.user_id = :uid
            LEFT JOIN user_notification_preferences up_exact
              ON up_exact.user_id = :uid AND up_exact.event_key COLLATE utf8mb4_unicode_ci = ' . $exactPreferenceSql . '
            LEFT JOIN user_notification_preferences up_family
              ON up_family.user_id = :uid AND up_family.event_key COLLATE utf8mb4_unicode_ci = ' . $preferenceEventSql . '
            WHERE (' . $where . ') AND r.notification_id IS NULL
              AND COALESCE(up_exact.channel_system, up_family.channel_system, 1) = 1
        ');

        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function listLatestForUser(int $userId, array $teams, int $limit = 10, ?int $sinceId = null): array
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(50, (int) $limit));
        [$where, $params] = $this->buildRecipientWhere($userId, $teams);

        $andSince = '';
        if ($sinceId !== null && $sinceId > 0) {
            $andSince = ' AND n.id > :since_id ';
            $params['since_id'] = (int) $sinceId;
        }

        $preferenceEventSql = self::preferenceEventSql('n.event_key');
        $exactPreferenceSql = self::exactPreferenceEventSql('n.event_key', 'n.sub_key');

        $stmt = $this->pdo->prepare('
            SELECT n.id, n.created_at, n.event_key, n.sub_key, n.title, n.message, n.link_url,
                   (r.notification_id IS NULL) AS is_unread
            FROM system_notifications n
            LEFT JOIN system_notification_reads r
              ON r.notification_id = n.id AND r.user_id = :uid
            LEFT JOIN user_notification_preferences up_exact
              ON up_exact.user_id = :uid AND up_exact.event_key COLLATE utf8mb4_unicode_ci = ' . $exactPreferenceSql . '
            LEFT JOIN user_notification_preferences up_family
              ON up_family.user_id = :uid AND up_family.event_key COLLATE utf8mb4_unicode_ci = ' . $preferenceEventSql . '
            WHERE (' . $where . ')' . $andSince . '
              AND COALESCE(up_exact.channel_system, up_family.channel_system, 1) = 1
            ORDER BY n.id DESC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $row['is_unread'] = !empty($row['is_unread']);
        }
        unset($row);

        return $rows;
    }

    public function markRead(int $userId, array $notificationIds): int
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $notificationIds), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return 0;
        }

        $stmt = $this->pdo->prepare('INSERT IGNORE INTO system_notification_reads (notification_id, user_id, read_at) VALUES (:nid, :uid, NOW())');
        $count = 0;
        foreach ($ids as $id) {
            $stmt->execute(['nid' => (int) $id, 'uid' => $userId]);
            $count += (int) $stmt->rowCount();
        }

        return $count;
    }

    public function markAllRead(int $userId, array $teams): int
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }

        [$where, $params] = $this->buildRecipientWhere($userId, $teams);

        $stmt = $this->pdo->prepare('
            INSERT IGNORE INTO system_notification_reads (notification_id, user_id, read_at)
            SELECT n.id, :uid, NOW()
            FROM system_notifications n
            LEFT JOIN system_notification_reads r
              ON r.notification_id = n.id AND r.user_id = :uid
            WHERE (' . $where . ') AND r.notification_id IS NULL
        ');
        $stmt->execute($params);

        return (int) $stmt->rowCount();
    }

    public function getRule(string $eventKey, ?string $subKey = null): array
    {
        $eventKey = strtolower(trim($eventKey));
        $subKey = $subKey !== null ? strtolower(trim($subKey)) : null;

        $stmt = $this->pdo->prepare('SELECT * FROM system_notification_rules WHERE event_key = :ek AND ((sub_key IS NULL AND :sk IS NULL) OR sub_key = :sk) LIMIT 1');
        $stmt->execute(['ek' => $eventKey, 'sk' => $subKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row && $subKey !== null) {
            $stmt = $this->pdo->prepare('SELECT * FROM system_notification_rules WHERE event_key = :ek AND sub_key IS NULL LIMIT 1');
            $stmt->execute(['ek' => $eventKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $recipientTeam = (string) ($row['recipient_team'] ?? self::TEAM_RH);
        if (!self::isValidRecipientTarget($recipientTeam)) {
            $recipientTeam = self::TEAM_RH;
        }

        return [
            'is_enabled' => (bool) ($row ? (int) ($row['is_enabled'] ?? 0) : 1),
            'label' => (string) ($row['label'] ?? $eventKey),
            'recipient_team' => $recipientTeam,
            'channels' => $this->decodeStringList($row['channels_json'] ?? null) ?: ['system'],
            'title_template' => (string) ($row['title_template'] ?? ''),
            'message_template' => (string) ($row['message_template'] ?? ''),
            'excluded_emails' => $this->decodeExcludedEmails($row['excluded_emails_json'] ?? null),
        ];
    }

    public function upsertRule(string $eventKey, ?string $subKey, string $label, bool $enabled, string $recipientTeam, array $channels, ?string $titleTemplate = null, ?string $messageTemplate = null, array $excludedEmails = []): void
    {
        $eventKey = strtolower(trim($eventKey));
        $subKey = $subKey !== null ? strtolower(trim($subKey)) : null;
        $label = trim($label) !== '' ? trim($label) : $eventKey;

        if (!self::isValidRecipientTarget($recipientTeam)) {
            $recipientTeam = self::TEAM_RH;
        }

        $channels = $this->normalizeStringList($channels);
        if (!$channels) {
            $channels = ['system'];
        }

        $excludedEmails = $this->normalizeExcludedEmails($excludedEmails);
        $titleTemplate = $titleTemplate !== null ? trim((string) $titleTemplate) : null;
        $messageTemplate = $messageTemplate !== null ? trim((string) $messageTemplate) : null;

        $stmt = $this->pdo->prepare('
            INSERT INTO system_notification_rules (event_key, sub_key, label, title_template, message_template, is_enabled, recipient_team, channels_json, excluded_emails_json, updated_at)
            VALUES (:ek, :sk, :label, :title_tpl, :msg_tpl, :en, :team, :ch, :excluded_emails, NOW())
            ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                title_template = VALUES(title_template),
                message_template = VALUES(message_template),
                is_enabled = VALUES(is_enabled),
                recipient_team = VALUES(recipient_team),
                channels_json = VALUES(channels_json),
                excluded_emails_json = VALUES(excluded_emails_json),
                updated_at = NOW()
        ');

        $stmt->execute([
            'ek' => $eventKey,
            'sk' => $subKey,
            'label' => $label,
            'title_tpl' => ($titleTemplate !== null && $titleTemplate !== '') ? $titleTemplate : null,
            'msg_tpl' => ($messageTemplate !== null && $messageTemplate !== '') ? $messageTemplate : null,
            'en' => $enabled ? 1 : 0,
            'team' => $recipientTeam,
            'ch' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'excluded_emails' => $excludedEmails !== [] ? json_encode($excludedEmails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    public function listRulesForEvent(string $eventKey): array
    {
        $eventKey = strtolower(trim($eventKey));
        if ($eventKey === '') {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM system_notification_rules WHERE event_key = :ek ORDER BY (sub_key IS NULL) DESC, sub_key ASC');
        $stmt->execute(['ek' => $eventKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row['is_enabled'] = (int) ($row['is_enabled'] ?? 0) === 1;
            $row['channels'] = $this->decodeStringList($row['channels_json'] ?? null);
            $row['title_template'] = (string) ($row['title_template'] ?? '');
            $row['message_template'] = (string) ($row['message_template'] ?? '');
            $row['excluded_emails'] = $this->decodeExcludedEmails($row['excluded_emails_json'] ?? null);
        }
        unset($row);

        return $rows;
    }

    public function listCommunicationPushRecipients(): array
    {
        try {
            $rows = $this->pdo->query(
                "SELECT
                    u.id,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                    COALESCE(s.lib_service, '') AS service_label,
                    COALESCE(rst.label,
                        CASE
                            WHEN p.statut_pers_soignant_code IS NULL THEN ''
                            ELSE CONCAT('Statut ', p.statut_pers_soignant_code)
                        END
                    ) AS status_label,
                    CASE WHEN cpr.user_id IS NULL THEN 0 ELSE 1 END AS is_selected
                 FROM user_devis_banamur u
                 LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                 LEFT JOIN service s ON s.id_service = p.service_id
                 LEFT JOIN rh_statuts rst ON rst.code = p.statut_pers_soignant_code
                 LEFT JOIN communication_push_recipients cpr
                   ON cpr.user_id = u.id
                  AND cpr.scope_key = 'communication_tasks'
                 WHERE u.active = 1
                 ORDER BY u.prenom ASC, u.nom ASC, u.id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['id'] = (int) ($row['id'] ?? 0);
            $display = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? ''));
            if ($display === '') {
                $display = trim((string) ($row['mail_pro'] ?? ''));
            }
            if ($display === '') {
                $display = 'Utilisateur #' . (int) ($row['id'] ?? 0);
            }
            $row['display'] = $display;
            $row['is_selected'] = (int) ($row['is_selected'] ?? 0) === 1;
        }
        unset($row);

        return $rows;
    }

    public function saveCommunicationPushRecipients(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $userId): bool => $userId > 0)));

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM communication_push_recipients WHERE scope_key = :scope_key');
            $deleteStmt->execute(['scope_key' => 'communication_tasks']);

            if ($userIds !== []) {
                $insertStmt = $this->pdo->prepare(
                    'INSERT INTO communication_push_recipients (scope_key, user_id, created_at, updated_at)
                     VALUES (:scope_key, :user_id, NOW(), NOW())'
                );
                foreach ($userIds as $userId) {
                    $insertStmt->execute([
                        'scope_key' => 'communication_tasks',
                        'user_id' => $userId,
                    ]);
                }
            }

            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function buildRecipientWhere(int $userId, array $teams): array
    {
        $teams = $this->normalizeTeams($teams);
        $conditions = ['(n.recipient_user_id = :uid)'];
        $params = ['uid' => $userId];

        if ($teams) {
            $in = [];
            foreach ($teams as $index => $team) {
                $key = ':team' . $index;
                $in[] = $key;
                $params[$key] = $team;
            }
            $conditions[] = '(n.recipient_user_id IS NULL AND (n.recipient_team IN (' . implode(',', $in) . ') OR n.recipient_team = :team_all))';
            $params['team_all'] = self::TEAM_ALL;
        }

        return [implode(' OR ', $conditions), $params];
    }

    private static function preferenceEventSql(string $eventColumn): string
    {
        return "(CASE
            WHEN {$eventColumn} IN ('legal_request_submitted', 'legal_request_processed') THEN 'legal_requests'
            WHEN {$eventColumn} IN ('decaissement_submitted', 'decaissement_processed', 'broken_debourse_links_detected') THEN 'finance_decaissement'
            WHEN {$eventColumn} IN ('schedule_occurrence_created', 'schedule_task_assigned', 'schedule_task_changed') THEN 'planning_operations'
            WHEN {$eventColumn} IN ('employee_absence_marked', 'employee_absence_reminder_24h', 'employee_absence_threshold_3') THEN 'employee_absences'
            WHEN {$eventColumn} IN ('rh_hierarchy_assigned') THEN 'rh_management'
            WHEN {$eventColumn} IN ('supplier_workflow') THEN 'supplier_portal'
            WHEN {$eventColumn} IN ('logistics_request', 'logistics_asset') THEN 'logistics_operations'
            WHEN {$eventColumn} IN ('attendance_device_alert') THEN 'attendance_devices'
            WHEN {$eventColumn} IN ('communication_message_sent', 'communication_task_assigned', 'communication_task_changed', 'document_share_created') THEN 'mailbox'
            ELSE NULL
        END) COLLATE utf8mb4_unicode_ci";
    }

    private static function exactPreferenceEventSql(string $eventColumn, string $subKeyColumn): string
    {
        return "(CASE
            WHEN {$subKeyColumn} IS NULL OR {$subKeyColumn} = '' THEN {$eventColumn}
            ELSE CONCAT({$eventColumn}, '.', {$subKeyColumn})
        END) COLLATE utf8mb4_unicode_ci";
    }

    private function normalizeTeams(array $teams): array
    {
        $known = array_flip(self::getKnownTeams());
        return array_values(array_unique(array_filter(array_map(static fn($team): string => strtolower(trim((string) $team)), $teams), static fn(string $team): bool => isset($known[$team]))));
    }

    private function normalizeStringList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(static fn($value): string => strtolower(trim((string) $value)), $values), static fn(string $value): bool => $value !== '')));
    }

    private function decodeStringList($rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }
        $decoded = json_decode((string) $rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $this->normalizeStringList($decoded);
    }

    private function decodeExcludedEmails($rawValue): array
    {
        if ($rawValue === null || $rawValue === '') {
            return [];
        }
        $decoded = json_decode((string) $rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $this->normalizeExcludedEmails($decoded);
    }

    private function normalizeExcludedEmails(array $emails): array
    {
        return array_values(array_unique(array_filter(array_map(static fn($email): string => strtolower(trim((string) $email)), $emails), static fn(string $email): bool => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false)));
    }

    private function ensureTables(): void
    {
        if (self::$tablesEnsured) {
            return;
        }

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS system_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                created_at DATETIME NOT NULL,
                event_key VARCHAR(64) NOT NULL,
                sub_key VARCHAR(64) NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NULL,
                link_url VARCHAR(255) NULL,
                recipient_team VARCHAR(32) NULL,
                recipient_user_id INT NULL,
                channels_json TEXT NULL,
                payload_json MEDIUMTEXT NULL,
                INDEX idx_created_at (created_at),
                INDEX idx_recipient_team (recipient_team),
                INDEX idx_recipient_user (recipient_user_id),
                INDEX idx_event (event_key, sub_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS system_notification_reads (
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                read_at DATETIME NOT NULL,
                PRIMARY KEY (notification_id, user_id),
                INDEX idx_user (user_id),
                CONSTRAINT fk_notif_reads_notif FOREIGN KEY (notification_id) REFERENCES system_notifications(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS user_notification_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                channel_email TINYINT(1) NOT NULL DEFAULT 0,
                channel_system TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_event (user_id, event_key),
                KEY idx_user_notification_preferences_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS system_notification_rules (
                id INT AUTO_INCREMENT PRIMARY KEY,
                event_key VARCHAR(64) NOT NULL,
                sub_key VARCHAR(64) NULL,
                label VARCHAR(255) NOT NULL,
                title_template VARCHAR(255) NULL,
                message_template TEXT NULL,
                is_enabled TINYINT(1) NOT NULL DEFAULT 1,
                recipient_team VARCHAR(32) NOT NULL DEFAULT "rh",
                channels_json TEXT NULL,
                excluded_emails_json TEXT NULL,
                updated_at DATETIME NULL,
                UNIQUE KEY uq_rule (event_key, sub_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        $this->ensureRulesTableTemplateColumns();
        self::$tablesEnsured = true;
    }

    private function ensureRulesTableTemplateColumns(): void
    {
        try {
            $db = (string) ($this->pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        } catch (Throwable $e) {
            return;
        }

        if ($db === '') {
            return;
        }

        $columns = [
            'title_template' => 'ALTER TABLE system_notification_rules ADD COLUMN title_template VARCHAR(255) NULL',
            'message_template' => 'ALTER TABLE system_notification_rules ADD COLUMN message_template TEXT NULL',
            'excluded_emails_json' => 'ALTER TABLE system_notification_rules ADD COLUMN excluded_emails_json TEXT NULL',
        ];

        foreach ($columns as $column => $sql) {
            try {
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
                $stmt->execute(['db' => $db, 'table_name' => 'system_notification_rules', 'column_name' => $column]);
                if ((int) $stmt->fetchColumn() > 0) {
                    continue;
                }
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
            }
        }
    }
}
