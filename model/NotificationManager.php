<?php

require_once __DIR__ . '/EnvConfig.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/../services/NotificationWhatsAppChannelService.php';

\EnvConfig::loadFromDirectory(__DIR__);

class NotificationManager
{
    private PDO $pdo;
    private NotificationEmailChannelService $emailChannel;
    private NotificationWhatsAppChannelService $whatsAppChannel;

    private const EXCLUDED_TEAM_ALL_STATUS_CODES = [2];
    private const COMMUNICATION_PUSH_SCOPE_TASKS = 'communication_tasks';

    private const TEAM_RH = 'rh';
    private const TEAM_FINANCE = 'finance';
    private const TEAM_ADMIN = 'admin';
    private const TEAM_LOGISTIQUE = 'logistique';
    private const TEAM_HSE = 'hse';
    private const TEAM_TICKETING = 'ticketing';
    private const TEAM_ALL = 'all';

    private const RECIPIENT_REQUESTER = 'requester';

    private const EVENT_LEGAL_REQUEST_SUBMITTED = 'legal_request_submitted';
    private const EVENT_LEGAL_REQUEST_PROCESSED = 'legal_request_processed';

    private const EVENT_DECAISSEMENT_SUBMITTED = 'decaissement_submitted';
    private const EVENT_DECAISSEMENT_PROCESSED = 'decaissement_processed';

    private const EVENT_SCHEDULE_OCCURRENCE_CREATED = 'schedule_occurrence_created';
    private const EVENT_SCHEDULE_TASK_ASSIGNED = 'schedule_task_assigned';
    private const EVENT_SCHEDULE_TASK_CHANGED = 'schedule_task_changed';

    private const EVENT_CRM_RDV_CREATED = 'crm_rdv_created';
    private const EVENT_CRM_RDV_REMINDER = 'crm_rdv_reminder';

    private const EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER = 'crm_opportunity_next_action_reminder';
    private const EVENT_CRM_PROSPECT_INACTIVE = 'crm_prospect_inactive';
    private const EVENT_BROKEN_DEBOURSE_LINKS_DETECTED = 'broken_debourse_links_detected';
    private const EVENT_RH_HIERARCHY_ASSIGNED = 'rh_hierarchy_assigned';
    private const EVENT_SUPPLIER_WORKFLOW = 'supplier_workflow';
    private const EVENT_LOGISTICS_REQUEST = 'logistics_request';
    private const EVENT_LOGISTICS_ASSET = 'logistics_asset';
    private const EVENT_ATTENDANCE_DEVICE_ALERT = 'attendance_device_alert';
    private const EVENT_TICKETING = 'ticketing';

    private const EVENT_COMMUNICATION_MESSAGE_SENT = 'communication_message_sent';
    private const EVENT_COMMUNICATION_TASK_ASSIGNED = 'communication_task_assigned';
    private const EVENT_COMMUNICATION_TASK_CHANGED = 'communication_task_changed';
    private const EVENT_DOCUMENT_SHARE_CREATED = 'document_share_created';

    private static bool $tablesEnsured = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->emailChannel = new NotificationEmailChannelService();
        $this->whatsAppChannel = new NotificationWhatsAppChannelService();
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
            self::TEAM_TICKETING,
            self::TEAM_ALL,
        ];
    }

    private static function isValidRecipientTarget(string $target): bool
    {
        $target = strtolower(trim($target));
        if ($target === self::RECIPIENT_REQUESTER) {
            return true;
        }
        return in_array($target, self::getKnownTeams(), true);
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

    public static function inferUserTeamsFromSession(): array
    {
        $teams = [];

        if (function_exists('hasPermission') && hasPermission('acces_admin')) {
            $teams[] = self::TEAM_ADMIN;
        }
        if (function_exists('hasPermission') && hasPermission('acces_rh')) {
            $teams[] = self::TEAM_RH;
        }
        if (function_exists('hasPermission') && (hasPermission('acces_compta') || hasPermission('acces_point_financier') || hasPermission('acces_relink_fiches_debourse'))) {
            $teams[] = self::TEAM_FINANCE;
        }
        if (function_exists('hasPermission') && hasPermission('acces_logistique')) {
            $teams[] = self::TEAM_LOGISTIQUE;
        }
        if (function_exists('hasPermission') && hasPermission('acces_hse')) {
            $teams[] = self::TEAM_HSE;
        }
        if (function_exists('hasPermission') && hasPermission('acces_ticketing')) {
            $teams[] = self::TEAM_TICKETING;
        }

        return array_values(array_unique($teams));
    }

    public function countUnread(int $userId, array $teams): int
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return 0;
        }

        $teams = $this->normalizeTeams($teams);

        $conditions = [];
        $params = ['uid' => $userId];

        $conditions[] = '(n.recipient_user_id = :uid)';

        if ($teams) {
            $in = [];
            foreach ($teams as $i => $t) {
                $k = ':t' . $i;
                $in[] = $k;
                $params[$k] = $t;
            }
            $conditions[] = '(n.recipient_user_id IS NULL AND (n.recipient_team IN (' . implode(',', $in) . ') OR n.recipient_team = :team_all))';
            $params['team_all'] = self::TEAM_ALL;
        }

        $where = implode(' OR ', $conditions);

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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listLatestForUser(int $userId, array $teams, int $limit = 10, ?int $sinceId = null): array
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(50, (int) $limit));
        $teams = $this->normalizeTeams($teams);

        $params = ['uid' => $userId];
        $conditions = [];

        $conditions[] = '(n.recipient_user_id = :uid)';

        if ($teams) {
            $in = [];
            foreach ($teams as $i => $t) {
                $k = ':t' . $i;
                $in[] = $k;
                $params[$k] = $t;
            }
            $conditions[] = '(n.recipient_user_id IS NULL AND (n.recipient_team IN (' . implode(',', $in) . ') OR n.recipient_team = :team_all))';
            $params['team_all'] = self::TEAM_ALL;
        }

        $where = '(' . implode(' OR ', $conditions) . ')';

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
            WHERE ' . $where . $andSince . '
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

        $ids = array_values(array_unique(array_filter(array_map('intval', $notificationIds), static function (int $v): bool {
            return $v > 0;
        })));

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

        $teams = $this->normalizeTeams($teams);

        $conditions = [];
        $params = ['uid' => $userId, 'team_all' => self::TEAM_ALL];

        $conditions[] = '(n.recipient_user_id = :uid)';

        if ($teams) {
            $in = [];
            foreach ($teams as $i => $t) {
                $k = ':t' . $i;
                $in[] = $k;
                $params[$k] = $t;
            }
            $conditions[] = '(n.recipient_user_id IS NULL AND (n.recipient_team IN (' . implode(',', $in) . ') OR n.recipient_team = :team_all))';
        }

        $where = implode(' OR ', $conditions);

        $sql = '
            INSERT IGNORE INTO system_notification_reads (notification_id, user_id, read_at)
            SELECT n.id, :uid, NOW()
            FROM system_notifications n
            LEFT JOIN system_notification_reads r
              ON r.notification_id = n.id AND r.user_id = :uid
            WHERE (' . $where . ') AND r.notification_id IS NULL
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->rowCount();
    }

    /**
     * @return array{is_enabled:bool,label:string,recipient_team:string,channels:array<int,string>,title_template:string,message_template:string,excluded_emails:array<int,string>}
     */
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

        $channels = [];
        if ($row && !empty($row['channels_json'])) {
            $decoded = json_decode((string) $row['channels_json'], true);
            if (is_array($decoded)) {
                $channels = array_values(array_filter(array_map('strval', $decoded), static fn($v) => $v !== ''));
            }
        }

        $recipientTeam = (string) ($row['recipient_team'] ?? self::TEAM_RH);
        if (!self::isValidRecipientTarget($recipientTeam)) {
            $recipientTeam = self::TEAM_RH;
        }

        $titleTemplate = (string) ($row['title_template'] ?? '');
        $messageTemplate = (string) ($row['message_template'] ?? '');
        $excludedEmails = $this->decodeExcludedEmails($row['excluded_emails_json'] ?? null);

        return [
            'is_enabled' => (bool) ($row ? (int) ($row['is_enabled'] ?? 0) : 1),
            'event_key' => $eventKey,
            'sub_key' => $subKey,
            'label' => (string) ($row['label'] ?? $eventKey),
            'recipient_team' => $recipientTeam,
            'channels' => $channels ?: ['system'],
            'title_template' => $titleTemplate,
            'message_template' => $messageTemplate,
            'excluded_emails' => $excludedEmails,
        ];
    }

    public function upsertRule(
        string $eventKey,
        ?string $subKey,
        string $label,
        bool $enabled,
        string $recipientTeam,
        array $channels,
        ?string $titleTemplate = null,
        ?string $messageTemplate = null,
        array $excludedEmails = []
    ): void {
        $eventKey = strtolower(trim($eventKey));
        $subKey = $subKey !== null ? strtolower(trim($subKey)) : null;
        $label = trim($label) !== '' ? trim($label) : $eventKey;

        if (!self::isValidRecipientTarget($recipientTeam)) {
            $recipientTeam = self::TEAM_RH;
        }

        $channels = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $channels)), static fn($v) => $v !== '')));
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

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRulesForEvent(string $eventKey): array
    {
        $eventKey = strtolower(trim($eventKey));
        if ($eventKey === '') {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT * FROM system_notification_rules WHERE event_key = :ek ORDER BY (sub_key IS NULL) DESC, sub_key ASC');
        $stmt->execute(['ek' => $eventKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$r) {
            $r['is_enabled'] = (int) ($r['is_enabled'] ?? 0) === 1;
            $channels = [];
            if (!empty($r['channels_json'])) {
                $decoded = json_decode((string) $r['channels_json'], true);
                if (is_array($decoded)) {
                    $channels = array_values(array_filter(array_map('strval', $decoded), static fn($v) => $v !== ''));
                }
            }
            $r['channels'] = $channels;
            $r['title_template'] = (string) ($r['title_template'] ?? '');
            $r['message_template'] = (string) ($r['message_template'] ?? '');
            $r['excluded_emails'] = $this->decodeExcludedEmails($r['excluded_emails_json'] ?? null);
        }
        unset($r);

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
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
            $row['id'] = (int)($row['id'] ?? 0);
            $display = trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? ''));
            if ($display === '') {
                $display = trim((string)($row['mail_pro'] ?? ''));
            }
            if ($display === '') {
                $display = 'Utilisateur #' . (int)($row['id'] ?? 0);
            }
            $row['display'] = $display;
            $row['is_selected'] = (int)($row['is_selected'] ?? 0) === 1;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param array<int,int|string> $userIds
     */
    public function saveCommunicationPushRecipients(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $userId): bool {
            return $userId > 0;
        })));

        $startedTransaction = !$this->pdo->inTransaction();
        if ($startedTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $deleteStmt = $this->pdo->prepare('DELETE FROM communication_push_recipients WHERE scope_key = :scope_key');
            $deleteStmt->execute(['scope_key' => self::COMMUNICATION_PUSH_SCOPE_TASKS]);

            if ($userIds !== []) {
                $insertStmt = $this->pdo->prepare(
                    'INSERT INTO communication_push_recipients (scope_key, user_id, created_at, updated_at)
                     VALUES (:scope_key, :user_id, NOW(), NOW())'
                );
                foreach ($userIds as $userId) {
                    $insertStmt->execute([
                        'scope_key' => self::COMMUNICATION_PUSH_SCOPE_TASKS,
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

    public function ensureDefaultRulesForLegalRequestTypes(array $requestTypes): void
    {
        foreach ($requestTypes as $type => $def) {
            $type = strtolower(trim((string) $type));
            if ($type === '') {
                continue;
            }

            $label = (string) ($def['label'] ?? $type);
            $team = (string) ($def['team'] ?? self::TEAM_RH);
            if (!in_array($team, self::getKnownTeams(), true)) {
                $team = self::TEAM_RH;
            }

            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_LEGAL_REQUEST_SUBMITTED, 'sk' => $type]);
            $exists = (int) $stmt->fetchColumn() > 0;
            if ($exists) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_LEGAL_REQUEST_SUBMITTED,
                $type,
                'Demande de ' . $label,
                true,
                $team,
                ['system'],
                'Demande {{type_label}}',
                '{{nom_expediteur}} a soumis une demande ({{type_label}}) le {{date}}.'
            );
        }
    }

    public function ensureDefaultRulesForLegalRequestProcessed(): void
    {
        $defaults = [
            'approved' => [
                'label' => 'Demande approuvée',
                'title' => 'Demande {{type_label}} {{statut_label}}',
                'message' => 'Votre demande "{{type_label}}" a été {{statut_label}}.',
            ],
            'rejected' => [
                'label' => 'Demande refusée',
                'title' => 'Demande {{type_label}} {{statut_label}}',
                'message' => 'Votre demande "{{type_label}}" a été {{statut_label}}. Motif: {{motif}}',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_LEGAL_REQUEST_PROCESSED, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_LEGAL_REQUEST_PROCESSED,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                self::RECIPIENT_REQUESTER,
                ['system'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForDecaissementSubmitted(): void
    {
        $defaults = [
            'default' => [
                'label' => 'Nouvelle fiche de décaissement',
                'recipient' => self::TEAM_LOGISTIQUE,
                'title' => 'Nouvelle fiche {{num_fiche}}',
                'message' => '{{nom_expediteur}} a soumis une fiche ({{num_fiche}}) — {{montant}} FCFA.',
            ],
            'epi' => [
                'label' => 'EPI : nouvelle fiche',
                'recipient' => self::TEAM_HSE,
                'title' => 'Nouvelle fiche EPI {{num_fiche}}',
                'message' => '{{nom_expediteur}} a soumis une fiche EPI ({{num_fiche}}) — {{montant}} FCFA.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_DECAISSEMENT_SUBMITTED, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $recipient = (string) ($def['recipient'] ?? self::TEAM_LOGISTIQUE);
            if (!in_array($recipient, self::getKnownTeams(), true)) {
                $recipient = self::TEAM_LOGISTIQUE;
            }

            $this->upsertRule(
                self::EVENT_DECAISSEMENT_SUBMITTED,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                $recipient,
                ['system'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForDecaissementProcessed(): void
    {
        $defaults = [
            'approved' => [
                'label' => 'Fiche approuvée',
                'title' => 'Fiche {{num_fiche}} {{statut_label}}',
                'message' => 'Votre fiche ({{num_fiche}}) a été {{statut_label}}.',
            ],
            'rejected' => [
                'label' => 'Fiche refusée',
                'title' => 'Fiche {{num_fiche}} {{statut_label}}',
                'message' => 'Votre fiche ({{num_fiche}}) a été {{statut_label}}. Motif: {{motif}}',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_DECAISSEMENT_PROCESSED, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_DECAISSEMENT_PROCESSED,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                self::RECIPIENT_REQUESTER,
                ['system'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForSupplierWorkflow(): void
    {
        $defaults = [
            'submitted' => [
                'label' => 'Fournisseur à valider',
                'recipient' => self::TEAM_FINANCE,
                'title' => 'Fournisseur à valider : {{supplier_name}}',
                'message' => '{{actor_name}} a soumis le fournisseur {{supplier_name}} pour validation.',
            ],
            'approved' => [
                'label' => 'Fournisseur validé',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Votre fournisseur est validé : {{supplier_name}}',
                'message' => 'Votre fournisseur {{supplier_name}} a été validé.',
            ],
            'rejected' => [
                'label' => 'Fournisseur rejeté',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Votre fournisseur est rejeté : {{supplier_name}}',
                'message' => 'Votre fournisseur {{supplier_name}} a été rejeté. Motif : {{validation_note}}',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_SUPPLIER_WORKFLOW, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_SUPPLIER_WORKFLOW,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                (string) ($def['recipient'] ?? self::TEAM_FINANCE),
                ['system', 'email'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? ''),
                []
            );
        }
    }


    public function ensureDefaultRulesForTicketing(): void
    {
        $defaults = [
            'created' => [
                'label' => 'Nouveau ticket',
                'recipient' => self::TEAM_TICKETING,
                'title' => 'Nouveau ticket {{ticket_no}}',
                'message' => '{{requester_name}} a créé un ticket : {{ticket_title}}.',
            ],
            'assigned' => [
                'label' => 'Ticket affecté',
                'recipient' => self::TEAM_TICKETING,
                'title' => 'Ticket affecté {{ticket_no}}',
                'message' => 'Le ticket {{ticket_no}} est affecté à {{assigned_target}}.',
            ],
            'commented' => [
                'label' => 'Nouveau commentaire ticket',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Nouveau message sur {{ticket_no}}',
                'message' => '{{actor_name}} a ajouté un message sur le ticket {{ticket_no}}.',
            ],
            'status_changed' => [
                'label' => 'Statut ticket modifié',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Ticket {{ticket_no}} : {{ticket_status}}',
                'message' => 'Le statut de votre ticket est maintenant : {{ticket_status}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_TICKETING, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }
            $this->upsertRule(
                self::EVENT_TICKETING,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                (string) ($def['recipient'] ?? self::TEAM_TICKETING),
                ['system'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? ''),
                []
            );
        }
    }

    public function ensureDefaultRulesForLogisticsRequest(): void
    {
        $defaults = [
            'submitted' => [
                'label' => 'Demande logistique a valider',
                'recipient' => self::TEAM_LOGISTIQUE,
                'title' => 'Nouvelle demande logistique {{request_code}}',
                'message' => '{{requester_name}} demande {{asset_label}} pour {{need_type}}.',
            ],
            'approved' => [
                'label' => 'Demande logistique accordee',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Demande accordee : {{request_code}}',
                'message' => 'Votre demande {{request_code}} a ete accordee pour {{asset_label}}.',
            ],
            'rejected' => [
                'label' => 'Demande logistique refusee',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Demande refusee : {{request_code}}',
                'message' => 'Votre demande {{request_code}} a ete refusee.',
            ],
            'details_requested' => [
                'label' => 'Details demandes sur une demande logistique',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Details demandes : {{request_code}}',
                'message' => 'La logistique demande des details supplementaires pour {{request_code}}.',
            ],
            'handover' => [
                'label' => 'Element logistique remis',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Element remis : {{asset_label}}',
                'message' => '{{asset_label}} vous a ete remis pour la demande {{request_code}}.',
            ],
            'recovered' => [
                'label' => 'Element logistique recupere',
                'recipient' => self::RECIPIENT_REQUESTER,
                'title' => 'Element recupere : {{asset_label}}',
                'message' => '{{asset_label}} a ete recupere et remis disponible. Retour : {{return_location}}',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_LOGISTICS_REQUEST, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                if ($subKey === 'submitted') {
                    try {
                        $rule = $this->getRule(self::EVENT_LOGISTICS_REQUEST, $subKey);
                        $channels = array_values(array_unique(array_merge((array)($rule['channels'] ?? []), ['system', 'email'])));
                        $this->upsertRule(
                            self::EVENT_LOGISTICS_REQUEST,
                            $subKey,
                            (string)($rule['label'] ?? $def['label'] ?? $subKey),
                            (bool)($rule['is_enabled'] ?? true),
                            (string)($rule['recipient_team'] ?? $def['recipient'] ?? self::TEAM_LOGISTIQUE),
                            $channels,
                            (string)($rule['title_template'] ?? $def['title'] ?? ''),
                            (string)($rule['message_template'] ?? $def['message'] ?? ''),
                            (array)($rule['excluded_emails'] ?? [])
                        );
                    } catch (Throwable $e) {
                    }
                }
                continue;
            }

            $this->upsertRule(
                self::EVENT_LOGISTICS_REQUEST,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                (string) ($def['recipient'] ?? self::TEAM_LOGISTIQUE),
                $subKey === 'submitted' ? ['system', 'email'] : ['system'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? ''),
                []
            );
        }
    }

    public function ensureDefaultRulesForLogisticsAsset(): void
    {
        $defaults = [
            'asset_created' => 'Element du parc ajoute',
            'driver_created' => 'Chauffeur ajoute',
            'stock_item_created' => 'Article stock ajoute',
            'stock_movement_created' => 'Mouvement stock ajoute',
            'maintenance_created' => 'Maintenance ajoutee',
            'incident_created' => 'Incident declare',
            'document_created' => 'Document ajoute',
            'supplier_created' => 'Fournisseur logistique ajoute',
        ];

        foreach ($defaults as $subKey => $label) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_LOGISTICS_ASSET, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_LOGISTICS_ASSET,
                $subKey,
                $label,
                true,
                self::TEAM_LOGISTIQUE,
                ['system'],
                $label . ' : {{entity_label}}',
                '{{actor_name}} a enregistre {{entity_label}} dans la logistique.',
                []
            );
        }
    }

    public function ensureDefaultRulesForAttendanceDeviceAlert(): void
    {
        $defaults = [
            'api_unreachable' => [
                'label' => 'Badgeuse : API inaccessible',
                'title' => 'Alerte badgeuse : connexion impossible',
                'message' => 'FINEA ne parvient pas a joindre attendance.fidest.ci. Detail : {{detail}}',
            ],
            'offline' => [
                'label' => 'Badgeuse deconnectee',
                'title' => 'Badgeuse deconnectee : {{device_label}}',
                'message' => '{{device_label}} ne communique plus depuis {{last_seen_label}}. Seuil : {{threshold_label}}.',
            ],
            'clock_drift' => [
                'label' => 'Lecture directe horloge badgeuse',
                'title' => 'Heure badgeuse a verifier : {{device_label}}',
                'message' => 'Lecture directe horloge : ecart observe avec GMT+0 {{drift_label}}. Heure badgeuse : {{device_time}}.',
                'enabled' => false,
            ],
            'server_time_mismatch' => [
                'label' => 'Decalage heure serveur / base',
                'title' => 'Alerte heure serveur pointage',
                'message' => 'Ecart detecte entre PHP GMT+0 et MySQL UTC : {{drift_label}}. PHP : {{php_gmt}}. MySQL : {{mysql_utc}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_ATTENDANCE_DEVICE_ALERT, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_ATTENDANCE_DEVICE_ALERT,
                $subKey,
                (string) $def['label'],
                (bool) ($def['enabled'] ?? true),
                self::TEAM_RH,
                ['system', 'email'],
                (string) $def['title'],
                (string) $def['message'],
                []
            );
        }
    }

    public static function getAdminEmailTestScenarios(): array
    {
        return [
            'schedule_occurrence_created_recurrence' => [
                'key' => 'schedule_occurrence_created_recurrence',
                'label' => 'Planning récurrent créé',
                'description' => 'Email envoyé lors de la création d\'une occurrence récurrente dans le planning.',
                'event_key' => self::EVENT_SCHEDULE_OCCURRENCE_CREATED,
                'sub_key' => 'recurrence',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Visite chantier'],
                    ['name' => 'start_at', 'label' => 'Début', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T08:30'],
                    ['name' => 'end_at', 'label' => 'Fin', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T10:00'],
                    ['name' => 'event_id', 'label' => 'ID événement', 'type' => 'number', 'default' => '401'],
                    ['name' => 'personnel_id', 'label' => 'ID personnel', 'type' => 'number', 'default' => '12'],
                    ['name' => 'recurrence_rule_id', 'label' => 'ID récurrence', 'type' => 'number', 'default' => '44'],
                ],
            ],
            'schedule_task_assigned_assigned' => [
                'key' => 'schedule_task_assigned_assigned',
                'label' => 'Planning tâche affectée',
                'description' => 'Email envoyé lors de l\'affectation d\'une tâche planning.',
                'event_key' => self::EVENT_SCHEDULE_TASK_ASSIGNED,
                'sub_key' => 'assigned',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Contrôle sécurité'],
                    ['name' => 'start_at', 'label' => 'Début', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T11:00'],
                    ['name' => 'end_at', 'label' => 'Fin', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T12:00'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Service HSE'],
                    ['name' => 'event_id', 'label' => 'ID événement', 'type' => 'number', 'default' => '402'],
                    ['name' => 'personnel_id', 'label' => 'ID personnel', 'type' => 'number', 'default' => '12'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'schedule-group-001'],
                ],
            ],
            'schedule_task_changed_group_updated' => [
                'key' => 'schedule_task_changed_group_updated',
                'label' => 'Planning tâche modifiée',
                'description' => 'Email envoyé lors d\'une modification de tâche planning.',
                'event_key' => self::EVENT_SCHEDULE_TASK_CHANGED,
                'sub_key' => 'group_updated',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Contrôle sécurité'],
                    ['name' => 'start_at', 'label' => 'Début', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T13:00'],
                    ['name' => 'end_at', 'label' => 'Fin', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-14T14:30'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Équipe chantier'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'event_id', 'label' => 'ID événement', 'type' => 'number', 'default' => '403'],
                    ['name' => 'personnel_id', 'label' => 'ID personnel', 'type' => 'number', 'default' => '12'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'schedule-group-002'],
                ],
            ],
            'schedule_task_changed_group_cancelled' => [
                'key' => 'schedule_task_changed_group_cancelled',
                'label' => 'Planning tâche annulée',
                'description' => 'Email envoyé lors d\'une annulation de tâche planning.',
                'event_key' => self::EVENT_SCHEDULE_TASK_CHANGED,
                'sub_key' => 'group_cancelled',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Inspection hebdo'],
                    ['name' => 'start_at', 'label' => 'Début', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-15T08:00'],
                    ['name' => 'end_at', 'label' => 'Fin', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-15T09:30'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Tout le personnel'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'event_id', 'label' => 'ID événement', 'type' => 'number', 'default' => '404'],
                    ['name' => 'personnel_id', 'label' => 'ID personnel', 'type' => 'number', 'default' => '12'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'schedule-group-003'],
                ],
            ],
            'schedule_task_changed_restored' => [
                'key' => 'schedule_task_changed_restored',
                'label' => 'Planning tâche restaurée',
                'description' => 'Email envoyé lors de la restauration d\'une tâche planning.',
                'event_key' => self::EVENT_SCHEDULE_TASK_CHANGED,
                'sub_key' => 'restored',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Inspection hebdo'],
                    ['name' => 'start_at', 'label' => 'Début', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-15T10:00'],
                    ['name' => 'end_at', 'label' => 'Fin', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-15T11:00'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Service exploitation'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'event_id', 'label' => 'ID événement', 'type' => 'number', 'default' => '405'],
                    ['name' => 'personnel_id', 'label' => 'ID personnel', 'type' => 'number', 'default' => '12'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'schedule-group-004'],
                ],
            ],
            'communication_task_assigned_group_created' => [
                'key' => 'communication_task_assigned_group_created',
                'label' => 'Communication tâche créée',
                'description' => 'Email envoyé lors de la création d\'une série de tâches communication.',
                'event_key' => self::EVENT_COMMUNICATION_TASK_ASSIGNED,
                'sub_key' => 'group_created',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Préparer la réunion sécurité'],
                    ['name' => 'deadline', 'label' => 'Échéance', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-16T17:00'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Service QHSE'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'task_id', 'label' => 'ID tâche', 'type' => 'number', 'default' => '501'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'comm-group-001'],
                ],
            ],
            'communication_task_changed_group_updated' => [
                'key' => 'communication_task_changed_group_updated',
                'label' => 'Communication tâche modifiée',
                'description' => 'Email envoyé lors de la modification d\'une série de tâches communication.',
                'event_key' => self::EVENT_COMMUNICATION_TASK_CHANGED,
                'sub_key' => 'group_updated',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Préparer la réunion sécurité'],
                    ['name' => 'deadline', 'label' => 'Échéance', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-16T18:00'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Service QHSE'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'task_id', 'label' => 'ID tâche', 'type' => 'number', 'default' => '501'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'comm-group-001'],
                ],
            ],
            'communication_task_changed_group_cancelled' => [
                'key' => 'communication_task_changed_group_cancelled',
                'label' => 'Communication tâche annulée',
                'description' => 'Email envoyé lors de l\'annulation d\'une série de tâches communication.',
                'event_key' => self::EVENT_COMMUNICATION_TASK_CHANGED,
                'sub_key' => 'group_cancelled',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'required' => true, 'default' => 'Préparer la réunion sécurité'],
                    ['name' => 'deadline', 'label' => 'Échéance', 'type' => 'datetime-local', 'required' => true, 'default' => '2026-04-16T18:00'],
                    ['name' => 'group_scope_label', 'label' => 'Portée', 'type' => 'text', 'default' => 'Service QHSE'],
                    ['name' => 'actor_name', 'label' => 'Auteur', 'type' => 'text', 'default' => 'Jean Kouassi'],
                    ['name' => 'task_id', 'label' => 'ID tâche', 'type' => 'number', 'default' => '501'],
                    ['name' => 'event_group_key', 'label' => 'Clé de groupe', 'type' => 'text', 'default' => 'comm-group-001'],
                ],
            ],
            'crm_rdv_created_created' => [
                'key' => 'crm_rdv_created_created',
                'label' => 'CRM RDV créé',
                'description' => 'Email envoyé à la création d\'un rendez-vous CRM.',
                'event_key' => self::EVENT_CRM_RDV_CREATED,
                'sub_key' => 'created',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => 'RDV client Horizon'],
                    ['name' => 'date_debut', 'label' => 'Début', 'type' => 'datetime-local', 'default' => '2026-04-17T09:00'],
                    ['name' => 'date_fin', 'label' => 'Fin', 'type' => 'datetime-local', 'default' => '2026-04-17T10:00'],
                    ['name' => 'linked_name', 'label' => 'Élément lié', 'type' => 'text', 'default' => 'Prospect Horizon BTP'],
                    ['name' => 'rdv_id', 'label' => 'ID RDV', 'type' => 'number', 'default' => '610'],
                ],
            ],
            'crm_rdv_reminder_24h' => [
                'key' => 'crm_rdv_reminder_24h',
                'label' => 'CRM rappel RDV 24h',
                'description' => 'Email de rappel CRM 24h avant le rendez-vous.',
                'event_key' => self::EVENT_CRM_RDV_REMINDER,
                'sub_key' => '24h',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => 'RDV client Horizon'],
                    ['name' => 'date_debut', 'label' => 'Début', 'type' => 'datetime-local', 'default' => '2026-04-18T09:00'],
                    ['name' => 'date_fin', 'label' => 'Fin', 'type' => 'datetime-local', 'default' => '2026-04-18T10:00'],
                    ['name' => 'linked_name', 'label' => 'Élément lié', 'type' => 'text', 'default' => 'Prospect Horizon BTP'],
                    ['name' => 'rdv_id', 'label' => 'ID RDV', 'type' => 'number', 'default' => '611'],
                ],
            ],
            'crm_rdv_reminder_1h' => [
                'key' => 'crm_rdv_reminder_1h',
                'label' => 'CRM rappel RDV 1h',
                'description' => 'Email de rappel CRM 1h avant le rendez-vous.',
                'event_key' => self::EVENT_CRM_RDV_REMINDER,
                'sub_key' => '1h',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => 'RDV client Horizon'],
                    ['name' => 'date_debut', 'label' => 'Début', 'type' => 'datetime-local', 'default' => '2026-04-18T14:00'],
                    ['name' => 'date_fin', 'label' => 'Fin', 'type' => 'datetime-local', 'default' => '2026-04-18T15:00'],
                    ['name' => 'linked_name', 'label' => 'Élément lié', 'type' => 'text', 'default' => 'Prospect Horizon BTP'],
                    ['name' => 'rdv_id', 'label' => 'ID RDV', 'type' => 'number', 'default' => '612'],
                ],
            ],
            'crm_opportunity_next_action_reminder_24h' => [
                'key' => 'crm_opportunity_next_action_reminder_24h',
                'label' => 'CRM rappel action 24h',
                'description' => 'Email de rappel de prochaine action opportunité à 24h.',
                'event_key' => self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER,
                'sub_key' => '24h',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => 'Relance devis Horizon'],
                    ['name' => 'date_action', 'label' => 'Date action', 'type' => 'datetime-local', 'default' => '2026-04-19T08:00'],
                    ['name' => 'linked_name', 'label' => 'Élément lié', 'type' => 'text', 'default' => 'Opportunité Horizon BTP'],
                    ['name' => 'opportunite_id', 'label' => 'ID opportunité', 'type' => 'number', 'default' => '710'],
                ],
            ],
            'crm_opportunity_next_action_reminder_1h' => [
                'key' => 'crm_opportunity_next_action_reminder_1h',
                'label' => 'CRM rappel action 1h',
                'description' => 'Email de rappel de prochaine action opportunité à 1h.',
                'event_key' => self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER,
                'sub_key' => '1h',
                'fields' => [
                    ['name' => 'title', 'label' => 'Titre', 'type' => 'text', 'default' => 'Relance devis Horizon'],
                    ['name' => 'date_action', 'label' => 'Date action', 'type' => 'datetime-local', 'default' => '2026-04-19T15:00'],
                    ['name' => 'linked_name', 'label' => 'Élément lié', 'type' => 'text', 'default' => 'Opportunité Horizon BTP'],
                    ['name' => 'opportunite_id', 'label' => 'ID opportunité', 'type' => 'number', 'default' => '711'],
                ],
            ],
            'crm_prospect_inactive_alert' => [
                'key' => 'crm_prospect_inactive_alert',
                'label' => 'CRM prospect inactif',
                'description' => 'Email d\'alerte sur un prospect sans activité récente.',
                'event_key' => self::EVENT_CRM_PROSPECT_INACTIVE,
                'sub_key' => 'alert',
                'fields' => [
                    ['name' => 'prospect_nom', 'label' => 'Prospect', 'type' => 'text', 'default' => 'Horizon BTP'],
                    ['name' => 'societe', 'label' => 'Société', 'type' => 'text', 'default' => 'Finéa Construction'],
                    ['name' => 'last_activity', 'label' => 'Dernière activité', 'type' => 'text', 'default' => '2026-04-02'],
                    ['name' => 'days_inactive', 'label' => 'Jours inactifs', 'type' => 'number', 'default' => '12'],
                    ['name' => 'prospect_id', 'label' => 'ID prospect', 'type' => 'number', 'default' => '810'],
                ],
            ],
            'broken_debourse_links_detected_alert' => [
                'key' => 'broken_debourse_links_detected_alert',
                'label' => 'Décaissement liens cassés',
                'description' => 'Email d\'alerte sur des liens de déboursés cassés.',
                'event_key' => self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED,
                'sub_key' => 'alert',
                'fields' => [
                    ['name' => 'broken_count', 'label' => 'Nombre de fiches', 'type' => 'number', 'default' => '3'],
                    ['name' => 'chantier_code', 'label' => 'Code chantier', 'type' => 'text', 'default' => 'CH-118'],
                    ['name' => 'chantier_titre', 'label' => 'Titre chantier', 'type' => 'text', 'default' => 'Réhabilitation bloc A'],
                    ['name' => 'chantier_label', 'label' => 'Libellé chantier', 'type' => 'text', 'default' => 'CH-118 - Réhabilitation bloc A'],
                ],
            ],
            'rh_hierarchy_assigned_direct_manager' => [
                'key' => 'rh_hierarchy_assigned_direct_manager',
                'label' => 'RH nomination responsable',
                'description' => 'Email envoyé lorsqu’un collaborateur est nommé Responsable d’un service.',
                'event_key' => self::EVENT_RH_HIERARCHY_ASSIGNED,
                'sub_key' => 'direct_manager',
                'fields' => [
                    ['name' => 'nominee_name', 'label' => 'Nom du collaborateur', 'type' => 'text', 'required' => true, 'default' => 'Jean Kouassi'],
                    ['name' => 'department_label', 'label' => 'Département', 'type' => 'text', 'required' => true, 'default' => 'Service exploitation'],
                    ['name' => 'direction_label', 'label' => 'Direction', 'type' => 'text', 'default' => 'Direction des opérations'],
                    ['name' => 'role_label', 'label' => 'Libellé du rôle', 'type' => 'text', 'default' => 'Responsable'],
                ],
            ],
            'rh_hierarchy_assigned_department_head' => [
                'key' => 'rh_hierarchy_assigned_department_head',
                'label' => 'RH nomination directeur de département',
                'description' => 'Email envoyé lorsqu’un collaborateur est nommé Directeur du département.',
                'event_key' => self::EVENT_RH_HIERARCHY_ASSIGNED,
                'sub_key' => 'department_head',
                'fields' => [
                    ['name' => 'nominee_name', 'label' => 'Nom du collaborateur', 'type' => 'text', 'required' => true, 'default' => 'Awa Koné'],
                    ['name' => 'department_label', 'label' => 'Département', 'type' => 'text', 'required' => true, 'default' => 'Service administratif'],
                    ['name' => 'direction_label', 'label' => 'Direction', 'type' => 'text', 'default' => 'Direction générale'],
                    ['name' => 'role_label', 'label' => 'Libellé du rôle', 'type' => 'text', 'default' => 'Directeur du département'],
                ],
            ],
            'decaissement_submitted_default' => [
                'key' => 'decaissement_submitted_default',
                'label' => 'Décaissement soumis',
                'description' => 'Notification email envoyée à l’équipe métier lors d’une nouvelle fiche standard.',
                'event_key' => self::EVENT_DECAISSEMENT_SUBMITTED,
                'sub_key' => 'default',
                'fields' => [
                    ['name' => 'num_fiche', 'label' => 'Numéro de fiche', 'type' => 'text', 'required' => true, 'default' => 'FD-TEST-001'],
                    ['name' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true, 'default' => '125000'],
                    ['name' => 'beneficiaire', 'label' => 'Bénéficiaire', 'type' => 'text', 'default' => 'Jean Test'],
                    ['name' => 'designation', 'label' => 'Désignation', 'type' => 'text', 'default' => 'Achat matériaux'],
                    ['name' => 'precision', 'label' => 'Précision', 'type' => 'text', 'default' => 'Ciment et fer'],
                    ['name' => 'details', 'label' => 'Détails', 'type' => 'textarea', 'default' => 'Demande de paiement pour achat urgent.'],
                    ['name' => 'nom_expediteur', 'label' => 'Nom expéditeur', 'type' => 'text', 'default' => 'Admin Test'],
                ],
            ],
            'decaissement_submitted_epi' => [
                'key' => 'decaissement_submitted_epi',
                'label' => 'Décaissement soumis EPI',
                'description' => 'Variante EPI envoyée vers HSE avec le même rendu que le flux réel.',
                'event_key' => self::EVENT_DECAISSEMENT_SUBMITTED,
                'sub_key' => 'epi',
                'fields' => [
                    ['name' => 'num_fiche', 'label' => 'Numéro de fiche', 'type' => 'text', 'required' => true, 'default' => 'FD-EPI-001'],
                    ['name' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true, 'default' => '45000'],
                    ['name' => 'beneficiaire', 'label' => 'Bénéficiaire', 'type' => 'text', 'default' => 'Equipe chantier A'],
                    ['name' => 'designation', 'label' => 'Désignation', 'type' => 'text', 'default' => 'EPI'],
                    ['name' => 'precision', 'label' => 'Précision', 'type' => 'text', 'default' => 'Casques et gants'],
                    ['name' => 'details', 'label' => 'Détails', 'type' => 'textarea', 'default' => 'Réapprovisionnement EPI.'],
                    ['name' => 'nom_expediteur', 'label' => 'Nom expéditeur', 'type' => 'text', 'default' => 'Admin HSE'],
                ],
            ],
            'decaissement_processed_approved' => [
                'key' => 'decaissement_processed_approved',
                'label' => 'Fiche approuvée',
                'description' => 'Email envoyé au demandeur quand la fiche est approuvée.',
                'event_key' => self::EVENT_DECAISSEMENT_PROCESSED,
                'sub_key' => 'approved',
                'fields' => [
                    ['name' => 'num_fiche', 'label' => 'Numéro de fiche', 'type' => 'text', 'required' => true, 'default' => 'FD-APPRO-001'],
                    ['name' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true, 'default' => '210000'],
                    ['name' => 'beneficiaire', 'label' => 'Bénéficiaire', 'type' => 'text', 'default' => 'Marie Test'],
                    ['name' => 'designation', 'label' => 'Désignation', 'type' => 'text', 'default' => 'Paiement fournisseur'],
                    ['name' => 'precision', 'label' => 'Précision', 'type' => 'text', 'default' => 'Facture proforma'],
                    ['name' => 'nom_traiteur', 'label' => 'Nom du traiteur', 'type' => 'text', 'default' => 'Direction Finance'],
                ],
            ],
            'decaissement_processed_rejected' => [
                'key' => 'decaissement_processed_rejected',
                'label' => 'Fiche refusée',
                'description' => 'Email envoyé au demandeur quand la fiche est refusée.',
                'event_key' => self::EVENT_DECAISSEMENT_PROCESSED,
                'sub_key' => 'rejected',
                'fields' => [
                    ['name' => 'num_fiche', 'label' => 'Numéro de fiche', 'type' => 'text', 'required' => true, 'default' => 'FD-REJET-001'],
                    ['name' => 'montant', 'label' => 'Montant', 'type' => 'number', 'required' => true, 'default' => '98000'],
                    ['name' => 'beneficiaire', 'label' => 'Bénéficiaire', 'type' => 'text', 'default' => 'Paul Test'],
                    ['name' => 'designation', 'label' => 'Désignation', 'type' => 'text', 'default' => 'Transport'],
                    ['name' => 'precision', 'label' => 'Précision', 'type' => 'text', 'default' => 'Mission intérieure'],
                    ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'default' => 'Pièce justificative manquante.'],
                    ['name' => 'nom_traiteur', 'label' => 'Nom du traiteur', 'type' => 'text', 'default' => 'Direction Finance'],
                ],
            ],
            'legal_request_processed_approved' => [
                'key' => 'legal_request_processed_approved',
                'label' => 'Demande légale approuvée',
                'description' => 'Email envoyé au demandeur RH/légal quand sa demande est approuvée.',
                'event_key' => self::EVENT_LEGAL_REQUEST_PROCESSED,
                'sub_key' => 'approved',
                'fields' => [
                    ['name' => 'request_id', 'label' => 'ID demande', 'type' => 'number', 'required' => true, 'default' => '101'],
                    ['name' => 'type', 'label' => 'Code type', 'type' => 'text', 'default' => 'attestation'],
                    ['name' => 'type_label', 'label' => 'Type lisible', 'type' => 'text', 'default' => 'Attestation'],
                    ['name' => 'titre', 'label' => 'Titre', 'type' => 'text', 'default' => 'Attestation de travail'],
                    ['name' => 'nom_demandeur', 'label' => 'Nom demandeur', 'type' => 'text', 'default' => 'Employé Test'],
                    ['name' => 'email_demandeur', 'label' => 'Email demandeur', 'type' => 'email', 'default' => 'employe.test@example.com'],
                    ['name' => 'nom_traiteur', 'label' => 'Nom du traiteur', 'type' => 'text', 'default' => 'Gestion RH'],
                ],
            ],
            'legal_request_processed_rejected' => [
                'key' => 'legal_request_processed_rejected',
                'label' => 'Demande légale refusée',
                'description' => 'Email envoyé au demandeur RH/légal quand sa demande est refusée.',
                'event_key' => self::EVENT_LEGAL_REQUEST_PROCESSED,
                'sub_key' => 'rejected',
                'fields' => [
                    ['name' => 'request_id', 'label' => 'ID demande', 'type' => 'number', 'required' => true, 'default' => '102'],
                    ['name' => 'type', 'label' => 'Code type', 'type' => 'text', 'default' => 'explication'],
                    ['name' => 'type_label', 'label' => 'Type lisible', 'type' => 'text', 'default' => 'Demande d\'explication'],
                    ['name' => 'titre', 'label' => 'Titre', 'type' => 'text', 'default' => 'Réponse disciplinaire'],
                    ['name' => 'motif', 'label' => 'Motif', 'type' => 'textarea', 'default' => 'Informations complémentaires requises.'],
                    ['name' => 'nom_demandeur', 'label' => 'Nom demandeur', 'type' => 'text', 'default' => 'Employé Test'],
                    ['name' => 'email_demandeur', 'label' => 'Email demandeur', 'type' => 'email', 'default' => 'employe.test@example.com'],
                    ['name' => 'nom_traiteur', 'label' => 'Nom du traiteur', 'type' => 'text', 'default' => 'Gestion RH'],
                ],
            ],
        ];
    }

    private function composeLegalRequestProcessedContent(array $request, string $decision, array $actor, string $note = ''): ?array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return null;
        }

        $statutLabel = $decision === 'approved' ? 'approuvée' : 'refusée';
        $requestId = (int) ($request['id'] ?? ($request['request_id'] ?? 0));
        if ($requestId <= 0) {
            return null;
        }

        $this->ensureDefaultRulesForLegalRequestProcessed();
        $rule = $this->getRule(self::EVENT_LEGAL_REQUEST_PROCESSED, $decision);
        if (!$rule['is_enabled']) {
            return null;
        }

        $context = $this->makeBasicContext([
            'id' => $requestId,
            'request_id' => $requestId,
            'type' => (string) ($request['request_type'] ?? ($request['type'] ?? '')),
            'type_label' => (string) ($request['request_type_label'] ?? ($request['type_label'] ?? ($request['request_type'] ?? ($request['type'] ?? '')))),
            'titre' => (string) ($request['title'] ?? ($request['titre'] ?? '')),
            'statut' => $decision,
            'statut_label' => $statutLabel,
            'motif' => trim((string) $note),
            'nom_demandeur' => (string) ($request['requester_name'] ?? ($request['nom_demandeur'] ?? '')),
            'email_demandeur' => (string) ($request['requester_email'] ?? ($request['email_demandeur'] ?? '')),
            'nom_traiteur' => (string) ($actor['name'] ?? ($actor['nom_traiteur'] ?? '')),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = $decision === 'approved' ? 'Demande approuvée' : 'Demande refusée';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = $decision === 'approved'
                ? 'Votre demande a été approuvée.'
                : ('Votre demande a été refusée.' . (trim($note) !== '' ? (' Motif: ' . trim($note)) : ''));
        }

        return [
            'event_key' => self::EVENT_LEGAL_REQUEST_PROCESSED,
            'sub_key' => $decision,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/employe/details_demande_employe.php?id=' . $requestId,
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeDecaissementSubmittedContent(array $data, bool $isEpi, array $actor): ?array
    {
        $this->ensureDefaultRulesForDecaissementSubmitted();

        $subKey = $isEpi ? 'epi' : 'default';
        $rule = $this->getRule(self::EVENT_DECAISSEMENT_SUBMITTED, $subKey);
        if (!$rule['is_enabled']) {
            return null;
        }

        $recipientTeam = (string) ($rule['recipient_team'] ?? self::TEAM_LOGISTIQUE);
        if (!in_array($recipientTeam, self::getKnownTeams(), true)) {
            $recipientTeam = self::TEAM_LOGISTIQUE;
        }

        $numFiche = trim((string) ($data['num_fiche'] ?? ''));
        if ($numFiche === '') {
            return null;
        }

        $context = $this->makeBasicContext([
            'num_fiche' => $numFiche,
            'montant' => (string) ((float) ($data['montant_fiche'] ?? ($data['montant'] ?? 0))),
            'beneficiaire' => (string) ($data['beficiaire_fiche'] ?? ($data['beneficiaire'] ?? '')),
            'designation' => (string) ($data['designation_fiche'] ?? ($data['designation'] ?? '')),
            'precision' => (string) ($data['precision_fiche'] ?? ($data['precision'] ?? '')),
            'details' => (string) ($data['details_demande'] ?? ($data['details'] ?? '')),
            'nom_expediteur' => (string) ($actor['name'] ?? ($actor['nom_expediteur'] ?? '')),
        ]);
        try {
            $context['montant'] = number_format((float) ($data['montant_fiche'] ?? ($data['montant'] ?? 0)), 0, ',', ' ');
        } catch (Throwable $e) {
        }

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        if ($titleTpl === '') {
            $titleTpl = 'Nouvelle fiche de décaissement';
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle fiche de décaissement';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $who = trim((string) ($actor['name'] ?? ($actor['nom_expediteur'] ?? '')));
            $message = ($who !== '' ? ($who . ' a soumis une fiche.') : 'Une nouvelle fiche a été soumise.');
        }

        return [
            'event_key' => self::EVENT_DECAISSEMENT_SUBMITTED,
            'sub_key' => $subKey,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/decaissement/voir_fiche_details.php?num_fiche=' . rawurlencode($numFiche),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
            'recipient_team' => $recipientTeam,
        ];
    }

    private function composeDecaissementProcessedContent(array $fiche, string $decision, array $actor, string $motif = ''): ?array
    {
        $decision = strtolower(trim($decision));
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return null;
        }

        $statutLabel = $decision === 'approved' ? 'approuvée' : 'refusée';
        $numFiche = trim((string) ($fiche['num_fiche'] ?? ''));
        if ($numFiche === '') {
            return null;
        }

        $this->ensureDefaultRulesForDecaissementProcessed();
        $rule = $this->getRule(self::EVENT_DECAISSEMENT_PROCESSED, $decision);
        if (!$rule['is_enabled']) {
            return null;
        }

        $context = $this->makeBasicContext([
            'num_fiche' => $numFiche,
            'montant' => (string) ((float) ($fiche['montant_fiche'] ?? ($fiche['montant'] ?? 0))),
            'beneficiaire' => (string) ($fiche['beficiaire_fiche'] ?? ($fiche['beneficiaire'] ?? '')),
            'designation' => (string) ($fiche['designation_fiche'] ?? ($fiche['designation'] ?? '')),
            'precision' => (string) ($fiche['precision_fiche'] ?? ($fiche['precision'] ?? '')),
            'motif' => trim((string) $motif),
            'statut' => $decision,
            'statut_label' => $statutLabel,
            'nom_traiteur' => (string) ($actor['name'] ?? ($actor['nom_traiteur'] ?? '')),
        ]);
        try {
            $context['montant'] = number_format((float) ($fiche['montant_fiche'] ?? ($fiche['montant'] ?? 0)), 0, ',', ' ');
        } catch (Throwable $e) {
        }

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = $decision === 'approved' ? 'Fiche approuvée' : 'Fiche refusée';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = $decision === 'approved'
                ? ('Votre fiche (' . $numFiche . ') a été approuvée.')
                : ('Votre fiche (' . $numFiche . ') a été refusée.' . (trim($motif) !== '' ? (' Motif: ' . trim($motif)) : ''));
        }

        return [
            'event_key' => self::EVENT_DECAISSEMENT_PROCESSED,
            'sub_key' => $decision,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/employe/details_decaissement_employe.php?num_fiche=' . rawurlencode($numFiche),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function resolveNotificationEmailUrl(string $link): string
    {
        $baseUrl = trim((string) \EnvConfig::get('APP_BASE_URL', ''));
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $baseUrl = $scheme . '://' . $host . $this->resolveApplicationBasePath();
        }

        if ($link === '') {
            return '';
        }

        return (preg_match('#^https?://#i', $link) === 1) ? $link : ($baseUrl . $link);
    }

    private function resolveApplicationBasePath(): string
    {
        $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        $projectRoot = dirname(__DIR__);

        if ($documentRoot === '' || $projectRoot === '') {
            return '';
        }

        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', (string) realpath($documentRoot)), '/');
        $normalizedProjectRoot = rtrim(str_replace('\\', '/', (string) realpath($projectRoot)), '/');

        if ($normalizedDocumentRoot === '' || $normalizedProjectRoot === '') {
            return '';
        }

        if (stripos($normalizedProjectRoot, $normalizedDocumentRoot) !== 0) {
            return '';
        }

        $relativePath = trim(substr($normalizedProjectRoot, strlen($normalizedDocumentRoot)), '/');
        return $relativePath === '' ? '' : ('/' . $relativePath);
    }

    private function resolveNotificationEmailLogoUrl(): string
    {
        return $this->resolveNotificationEmailUrl('/branding_asset.php?type=logo');
    }

    /**
     * @return array<int,string>
     */
    private function splitNotificationMessage(string $message): array
    {
        $message = trim(str_replace(["\r\n", "\r"], "\n", $message));
        if ($message === '') {
            return [];
        }

        $parts = preg_split('/\n{2,}/', $message) ?: [];
        $clean = [];
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/', ' ', (string) $part) ?? '');
            if ($part !== '') {
                $clean[] = $part;
            }
        }

        return $clean;
    }

    private function buildNotificationEmailPlainText(string $subject, string $message, string $link): string
    {
        $url = $this->resolveNotificationEmailUrl($link);
        $lines = [trim($subject), ''];
        foreach ($this->splitNotificationMessage($message) as $paragraph) {
            $lines[] = $paragraph;
            $lines[] = '';
        }
        if ($url !== '') {
            $lines[] = 'Ouvrir : ' . $url;
            $lines[] = '';
        }
        $lines[] = 'Notification automatique Banamur, mise en forme Finéa.';
        return trim(implode("\n", $lines));
    }

    private function buildNotificationEmailHtml(string $subject, string $message, string $link): string
    {
        $url = $this->resolveNotificationEmailUrl($link);
        $logoUrl = $this->resolveNotificationEmailLogoUrl();
        $paragraphs = $this->splitNotificationMessage($message);
        if ($paragraphs === []) {
            $paragraphs = ['Une nouvelle notification est disponible dans votre espace Banamur.'];
        }

        $paragraphHtml = '';
        foreach ($paragraphs as $paragraph) {
            $paragraphHtml .= '<p style="margin:0 0 14px; color:#334155; line-height:1.7; font-size:14px;">' . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $buttonHtml = '';
        if ($url !== '') {
            $buttonHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">'
                . '<tr>'
                . '<td style="border-radius:14px; background:linear-gradient(135deg,#0f172a 0%,#1e293b 100%);">'
                . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" style="display:inline-block; padding:14px 22px; color:#ffffff; text-decoration:none; font-size:14px; font-weight:700;">Ouvrir la notification</a>'
                . '</td>'
                . '</tr>'
                . '</table>';
        }

        return '<!DOCTYPE html>'
            . '<html lang="fr">'
            . '<head>'
            . '  <meta charset="UTF-8" />'
            . '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />'
            . '  <title>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</title>'
            . '</head>'
            . '<body style="margin:0; padding:0; background:#eef2f7; font-family:Arial,sans-serif; color:#0f172a;">'
            . '  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:radial-gradient(circle at top,#f8fafc 0%,#eef2f7 52%,#e2e8f0 100%);">'
            . '    <tr>'
            . '      <td align="center" style="padding:28px 16px;">'
            . '        <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="max-width:680px; width:100%; background:#ffffff; border-radius:26px; overflow:hidden; border:1px solid #dbe4ee; box-shadow:0 20px 55px rgba(15,23,42,0.14);">'
            . '          <tr>'
            . '            <td style="padding:26px 30px; background:linear-gradient(135deg,#020617 0%,#111827 60%,#1d4ed8 100%);">'
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '                <tr>'
            . '                  <td style="vertical-align:top;">'
            . '                    <div style="display:inline-block; padding:7px 12px; border-radius:999px; background:rgba(255,255,255,0.10); border:1px solid rgba(255,255,255,0.18); font-size:11px; letter-spacing:0.16em; text-transform:uppercase; font-weight:700; color:#f8fafc;">Signature Finéa</div>'
            . '                    <h1 style="margin:16px 0 8px; font-size:25px; line-height:1.25; color:#ffffff;">' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '                    <p style="margin:0; color:#cbd5e1; font-size:13px; line-height:1.7;">Notification Banamur harmonisée avec la charte visuelle Finéa pour une lecture plus claire sur ordinateur comme sur mobile.</p>'
            . '                  </td>'
            . '                  <td align="right" style="vertical-align:top; padding-left:18px;">'
            . '                    <img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="Finéa" style="display:block; height:38px; width:auto; max-width:160px;" />'
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            . '            </td>'
            . '          </tr>'
            . '          <tr>'
            . '            <td style="padding:30px;">'
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">'
            . '                <tr>'
            . '                  <td style="padding:14px 16px; border:1px solid #e2e8f0; border-radius:18px; background:#f8fafc;">'
            . '                    <div style="font-size:12px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#7c3aed; margin-bottom:6px;">Repère</div>'
            . '                    <div style="font-size:14px; line-height:1.7; color:#475569;">Vous recevez cet email car une notification système a été émise dans votre espace de travail Banamur.</div>'
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            .                $paragraphHtml
            .                $buttonHtml
            . '              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;">'
            . '                <tr>'
            . '                  <td style="padding-top:18px; border-top:1px solid #e2e8f0; font-size:12px; color:#64748b; line-height:1.7;">'
            . '                    Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br />'
            .                      ($url !== '' ? htmlspecialchars($url, ENT_QUOTES, 'UTF-8') : 'Lien non disponible pour cette notification.')
            . '                  </td>'
            . '                </tr>'
            . '              </table>'
            . '            </td>'
            . '          </tr>'
            . '        </table>'
            . '        <div style="max-width:680px; margin:14px auto 0; font-size:12px; color:#64748b; line-height:1.6;">© ' . date('Y') . ' Banamur. Notification automatique au format Finéa.</div>'
            . '      </td>'
            . '    </tr>'
            . '  </table>'
            . '</body>'
            . '</html>';
    }

    public function buildAdminEmailTestPreview(string $scenarioKey, array $payload): array
    {
        $scenarioKey = strtolower(trim($scenarioKey));
        $scenario = self::getAdminEmailTestScenarios()[$scenarioKey] ?? null;
        if (!$scenario) {
            throw new RuntimeException('Scénario de mail inconnu');
        }

        $payload = array_change_key_case($payload, CASE_LOWER);
        $composed = null;
        switch ($scenarioKey) {
            case 'schedule_occurrence_created_recurrence':
                $composed = $this->composeScheduleOccurrenceCreatedContent($payload);
                break;
            case 'schedule_task_assigned_assigned':
                $composed = $this->composeScheduleTaskAssignedContent($payload);
                break;
            case 'schedule_task_changed_group_updated':
                $composed = $this->composeScheduleTaskChangedContent($payload, 'group_updated');
                break;
            case 'schedule_task_changed_group_cancelled':
                $composed = $this->composeScheduleTaskChangedContent($payload, 'group_cancelled');
                break;
            case 'schedule_task_changed_restored':
                $composed = $this->composeScheduleTaskChangedContent($payload, 'restored');
                break;
            case 'communication_task_assigned_group_created':
                $composed = $this->composeCommunicationTaskAssignedContent($payload);
                break;
            case 'communication_task_changed_group_updated':
                $composed = $this->composeCommunicationTaskChangedContent($payload, 'group_updated');
                break;
            case 'communication_task_changed_group_cancelled':
                $composed = $this->composeCommunicationTaskChangedContent($payload, 'group_cancelled');
                break;
            case 'crm_rdv_created_created':
                $composed = $this->composeCrmRdvCreatedContent($payload);
                break;
            case 'crm_rdv_reminder_24h':
                $composed = $this->composeCrmRdvReminderContent($payload, '24h');
                break;
            case 'crm_rdv_reminder_1h':
                $composed = $this->composeCrmRdvReminderContent($payload, '1h');
                break;
            case 'crm_opportunity_next_action_reminder_24h':
                $composed = $this->composeCrmOpportunityNextActionReminderContent($payload, '24h');
                break;
            case 'crm_opportunity_next_action_reminder_1h':
                $composed = $this->composeCrmOpportunityNextActionReminderContent($payload, '1h');
                break;
            case 'crm_prospect_inactive_alert':
                $composed = $this->composeCrmProspectInactiveContent($payload);
                break;
            case 'broken_debourse_links_detected_alert':
                $composed = $this->composeBrokenDebourseLinksDetectedContent($payload);
                break;
            case 'rh_hierarchy_assigned_direct_manager':
                $composed = $this->composeRhHierarchyAssignedContent($payload, 'direct_manager');
                break;
            case 'rh_hierarchy_assigned_department_head':
                $composed = $this->composeRhHierarchyAssignedContent($payload, 'department_head');
                break;
            case 'decaissement_submitted_default':
                $composed = $this->composeDecaissementSubmittedContent($payload, false, ['name' => (string) ($payload['nom_expediteur'] ?? '')]);
                break;
            case 'decaissement_submitted_epi':
                $composed = $this->composeDecaissementSubmittedContent($payload, true, ['name' => (string) ($payload['nom_expediteur'] ?? '')]);
                break;
            case 'decaissement_processed_approved':
                $composed = $this->composeDecaissementProcessedContent($payload, 'approved', ['name' => (string) ($payload['nom_traiteur'] ?? '')], '');
                break;
            case 'decaissement_processed_rejected':
                $composed = $this->composeDecaissementProcessedContent($payload, 'rejected', ['name' => (string) ($payload['nom_traiteur'] ?? '')], (string) ($payload['motif'] ?? ''));
                break;
            case 'legal_request_processed_approved':
                $composed = $this->composeLegalRequestProcessedContent($payload, 'approved', ['name' => (string) ($payload['nom_traiteur'] ?? '')], '');
                break;
            case 'legal_request_processed_rejected':
                $composed = $this->composeLegalRequestProcessedContent($payload, 'rejected', ['name' => (string) ($payload['nom_traiteur'] ?? '')], (string) ($payload['motif'] ?? ''));
                break;
        }

        if (!$composed) {
            throw new RuntimeException('Impossible de construire l’email pour ce scénario');
        }

        $html = $this->buildNotificationEmailHtml((string) $composed['title'], (string) $composed['message'], (string) $composed['link']);
        $plain = trim((string) $composed['message']) . ((string) ($composed['link'] ?? '') !== '' ? ("\n\n" . $this->resolveNotificationEmailUrl((string) $composed['link'])) : '');

        return [
            'scenario' => [
                'key' => $scenarioKey,
                'label' => (string) ($scenario['label'] ?? $scenarioKey),
                'description' => (string) ($scenario['description'] ?? ''),
                'event_key' => (string) ($scenario['event_key'] ?? ''),
                'sub_key' => (string) ($scenario['sub_key'] ?? ''),
            ],
            'rule' => [
                'label' => (string) ($composed['rule']['label'] ?? ''),
                'recipient_team' => (string) ($composed['rule']['recipient_team'] ?? ''),
                'channels' => array_values((array) ($composed['channels'] ?? [])),
                'title_template' => (string) ($composed['rule']['title_template'] ?? ''),
                'message_template' => (string) ($composed['rule']['message_template'] ?? ''),
            ],
            'subject' => (string) $composed['title'],
            'message' => (string) $composed['message'],
            'link' => (string) ($composed['link'] ?? ''),
            'resolved_url' => $this->resolveNotificationEmailUrl((string) ($composed['link'] ?? '')),
            'html' => $html,
            'plain' => $plain,
            'context' => (array) ($composed['context'] ?? []),
        ];
    }

    public function sendAdminEmailTest(string $scenarioKey, array $payload, string $to, string $toName = ''): array
    {
        $to = trim($to);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse email de test invalide');
        }

        if (!class_exists('Mailer')) {
            $mailerPath = __DIR__ . '/Mailer.php';
            if (is_file($mailerPath)) {
                require_once $mailerPath;
            }
        }

        if (!class_exists('Mailer')) {
            throw new RuntimeException('Mailer indisponible');
        }

        $preview = $this->buildAdminEmailTestPreview($scenarioKey, $payload);
        $name = trim($toName) !== '' ? trim($toName) : $to;
        $sent = $this->emailChannel->send($to, $name, (string) $preview['subject'], (string) $preview['html'], (string) $preview['plain']);

        return [
            'sent' => $sent,
            'preview' => $preview,
            'recipient_email' => $to,
            'recipient_name' => $name,
        ];
    }

    public function buildEmailRenderingPackage(string $subject, string $message, string $link): array
    {
        return [
            'subject' => $subject,
            'message' => $message,
            'link' => $link,
            'resolved_url' => $this->resolveNotificationEmailUrl($link),
            'html' => $this->buildNotificationEmailHtml($subject, $message, $link),
            'plain' => $this->buildNotificationEmailPlainText($subject, $message, $link),
        ];
    }

    public function notifyRhHierarchyAssigned(int $recipientUserId, string $roleKey, array $assignment): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $composed = $this->composeRhHierarchyAssignedContent($assignment, $roleKey);
        if (!$composed) {
            return;
        }

        $channels = (array) ($composed['channels'] ?? ['system']);
        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) (($composed['rule']['recipient_team'] ?? self::RECIPIENT_REQUESTER));
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(
            self::EVENT_RH_HIERARCHY_ASSIGNED,
            (string) $composed['sub_key'],
            (string) $composed['title'],
            (string) $composed['message'],
            (string) $composed['link'],
            $recipientTeam,
            $recipientUser,
            $channels,
            [
                'role_key' => (string) $composed['sub_key'],
                'department_label' => (string) (($composed['context']['department_label'] ?? '')),
            ]
        );

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail((array) ($composed['rule'] ?? []), $recipientUser, $recipientTeam, (string) $composed['title'], (string) $composed['message'], (string) $composed['link']);
        }
    }

    private function composeCommunicationTaskAssignedContent(array $event): ?array
    {
        $this->ensureDefaultRulesForCommunicationTaskAssigned();

        $rule = $this->getRule(self::EVENT_COMMUNICATION_TASK_ASSIGNED, 'group_created');
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($event['title'] ?? $event['titre'] ?? ''));
        $deadlineRaw = trim((string) ($event['deadline'] ?? $event['deadline_raw'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation groupée'));
        $actorName = trim((string) ($event['actor_name'] ?? 'Un collègue'));
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $deadlineDt = null;
        try {
            if ($deadlineRaw !== '') {
                $deadlineDt = new DateTimeImmutable(str_replace('T', ' ', $deadlineRaw));
            }
        } catch (Throwable $e) {
            $deadlineDt = null;
        }

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'deadline' => $deadlineDt ? $deadlineDt->format('Y-m-d H:i') : $deadlineRaw,
            'date_event' => $deadlineDt ? $deadlineDt->format('Y-m-d') : date('Y-m-d'),
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle série de tâches communication';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Une nouvelle série de tâches communication vous a été attribuée.';
        }

        return [
            'event_key' => self::EVENT_COMMUNICATION_TASK_ASSIGNED,
            'sub_key' => 'group_created',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/communication/taches_communication.php?task_id=' . (int) ($event['task_id'] ?? 0) . '&mine=1',
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeCommunicationTaskChangedContent(array $event, string $changeType): ?array
    {
        $changeType = strtolower(trim($changeType));
        if (!in_array($changeType, ['group_updated', 'group_cancelled'], true)) {
            return null;
        }

        $this->ensureDefaultRulesForCommunicationTaskChanged();
        $rule = $this->getRule(self::EVENT_COMMUNICATION_TASK_CHANGED, $changeType);
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($event['title'] ?? $event['titre'] ?? ''));
        $deadlineRaw = trim((string) ($event['deadline'] ?? $event['deadline_raw'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation groupée'));
        $actorName = trim((string) ($event['actor_name'] ?? 'Un collègue'));
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $deadlineDt = null;
        try {
            if ($deadlineRaw !== '') {
                $deadlineDt = new DateTimeImmutable(str_replace('T', ' ', $deadlineRaw));
            }
        } catch (Throwable $e) {
            $deadlineDt = null;
        }

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'deadline' => $deadlineDt ? $deadlineDt->format('Y-m-d H:i') : $deadlineRaw,
            'date_event' => $deadlineDt ? $deadlineDt->format('Y-m-d') : date('Y-m-d'),
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = $changeType === 'group_cancelled' ? 'Tâche communication annulée' : 'Tâche communication modifiée';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = $changeType === 'group_cancelled'
                ? 'Une série de tâches communication a été annulée.'
                : 'Une série de tâches communication a été modifiée.';
        }

        return [
            'event_key' => self::EVENT_COMMUNICATION_TASK_CHANGED,
            'sub_key' => $changeType,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/communication/taches_communication.php?task_id=' . (int) ($event['task_id'] ?? 0) . '&mine=1',
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeScheduleOccurrenceCreatedContent(array $event): ?array
    {
        $this->ensureDefaultRulesForScheduleOccurrenceCreated();
        $rule = $this->getRule(self::EVENT_SCHEDULE_OCCURRENCE_CREATED, 'recurrence');
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));

        $startDt = $startRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $startRaw)) : null;
        $endDt = $endRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $endRaw)) : null;
        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startDt ? $startDt->format('Y-m-d H:i') : '',
            'end' => $endDt ? $endDt->format('Y-m-d H:i') : '',
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'recurrence_rule_id' => (int) ($event['recurrence_rule_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle tâche planning';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Une nouvelle occurrence a été ajoutée à votre planning.';

        return [
            'event_key' => self::EVENT_SCHEDULE_OCCURRENCE_CREATED,
            'sub_key' => 'recurrence',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeScheduleTaskAssignedContent(array $event): ?array
    {
        $this->ensureDefaultRulesForScheduleTaskAssigned();
        $rule = $this->getRule(self::EVENT_SCHEDULE_TASK_ASSIGNED, 'assigned');
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation individuelle'));
        $startDt = $startRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $startRaw)) : null;
        $endDt = $endRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $endRaw)) : null;
        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startDt ? $startDt->format('Y-m-d H:i') : '',
            'end' => $endDt ? $endDt->format('Y-m-d H:i') : '',
            'scope_label' => $scopeLabel,
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle tâche planning';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Une nouvelle tâche a été affectée à votre planning.';

        return [
            'event_key' => self::EVENT_SCHEDULE_TASK_ASSIGNED,
            'sub_key' => 'assigned',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeScheduleTaskChangedContent(array $event, string $changeType): ?array
    {
        $changeType = strtolower(trim($changeType));
        if (!in_array($changeType, ['group_updated', 'group_cancelled', 'restored'], true)) {
            return null;
        }

        $this->ensureDefaultRulesForScheduleTaskChanged();
        $rule = $this->getRule(self::EVENT_SCHEDULE_TASK_CHANGED, $changeType);
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation individuelle'));
        $actorName = trim((string) ($event['actor_name'] ?? 'Un collègue'));
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }
        $startDt = $startRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $startRaw)) : null;
        $endDt = $endRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $endRaw)) : null;
        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startDt ? $startDt->format('Y-m-d H:i') : '',
            'end' => $endDt ? $endDt->format('Y-m-d H:i') : '',
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Mise à jour planning';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Une mise à jour a été appliquée à votre planning.';

        return [
            'event_key' => self::EVENT_SCHEDULE_TASK_CHANGED,
            'sub_key' => $changeType,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeCrmRdvCreatedContent(array $rdv): ?array
    {
        $this->ensureDefaultRulesForCrmRdvCreated();
        $rule = $this->getRule(self::EVENT_CRM_RDV_CREATED, 'created');
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($rdv['titre'] ?? $rdv['title'] ?? ''));
        $startRaw = trim((string) ($rdv['date_debut'] ?? $rdv['start_at'] ?? ''));
        $endRaw = trim((string) ($rdv['date_fin'] ?? $rdv['end_at'] ?? ''));
        $linkedName = trim((string) ($rdv['linked_name'] ?? ''));
        $startDt = $startRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $startRaw)) : null;
        $endDt = $endRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $endRaw)) : null;
        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_debut' => $startDt ? $startDt->format('Y-m-d') : $dateEvent,
            'start' => $startDt ? $startDt->format('Y-m-d H:i') : '',
            'end' => $endDt ? $endDt->format('Y-m-d H:i') : '',
            'linked_name' => $linkedName,
            'linked_label' => $linkedName !== '' ? ('• ' . $linkedName) : '',
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);
        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'RDV CRM créé';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Un RDV a été créé.';
        return [
            'event_key' => self::EVENT_CRM_RDV_CREATED,
            'sub_key' => 'created',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeCrmRdvReminderContent(array $rdv, string $subKey): ?array
    {
        $subKey = strtolower(trim($subKey));
        if (!in_array($subKey, ['24h', '1h'], true)) {
            return null;
        }
        $this->ensureDefaultRulesForCrmRdvReminder();
        $rule = $this->getRule(self::EVENT_CRM_RDV_REMINDER, $subKey);
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($rdv['titre'] ?? $rdv['title'] ?? ''));
        $startRaw = trim((string) ($rdv['date_debut'] ?? $rdv['start_at'] ?? ''));
        $endRaw = trim((string) ($rdv['date_fin'] ?? $rdv['end_at'] ?? ''));
        $linkedName = trim((string) ($rdv['linked_name'] ?? ''));
        $startDt = $startRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $startRaw)) : null;
        $endDt = $endRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $endRaw)) : null;
        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_debut' => $startDt ? $startDt->format('Y-m-d') : $dateEvent,
            'start' => $startDt ? $startDt->format('Y-m-d H:i') : '',
            'end' => $endDt ? $endDt->format('Y-m-d H:i') : '',
            'linked_name' => $linkedName,
            'linked_label' => $linkedName !== '' ? ('• ' . $linkedName) : '',
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);
        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Rappel RDV CRM';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Rappel RDV.';
        return [
            'event_key' => self::EVENT_CRM_RDV_REMINDER,
            'sub_key' => $subKey,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeCrmOpportunityNextActionReminderContent(array $opp, string $subKey): ?array
    {
        $subKey = strtolower(trim($subKey));
        if (!in_array($subKey, ['24h', '1h'], true)) {
            return null;
        }
        $this->ensureDefaultRulesForCrmOpportunityNextActionReminder();
        $rule = $this->getRule(self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER, $subKey);
        if (!$rule['is_enabled']) {
            return null;
        }

        $titleRaw = trim((string) ($opp['titre'] ?? $opp['title'] ?? ''));
        $actionRaw = trim((string) ($opp['next_action_at'] ?? $opp['date_action'] ?? ''));
        $linkedName = trim((string) ($opp['linked_name'] ?? ''));
        $dt = $actionRaw !== '' ? new DateTimeImmutable(str_replace('T', ' ', $actionRaw)) : null;
        $dateEvent = $dt ? $dt->format('Y-m-d') : date('Y-m-d');
        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_action' => $dt ? $dt->format('Y-m-d') : $dateEvent,
            'start' => $dt ? $dt->format('Y-m-d H:i') : '',
            'linked_name' => $linkedName,
            'linked_label' => $linkedName !== '' ? ('• ' . $linkedName) : '',
            'opportunite_id' => (int) ($opp['id'] ?? $opp['opportunite_id'] ?? 0),
        ]);
        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Rappel action opportunité';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Rappel action.';
        return [
            'event_key' => self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER,
            'sub_key' => $subKey,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent),
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeCrmProspectInactiveContent(array $prospect): ?array
    {
        $this->ensureDefaultRulesForCrmProspectInactive();
        $rule = $this->getRule(self::EVENT_CRM_PROSPECT_INACTIVE, 'alert');
        if (!$rule['is_enabled']) {
            return null;
        }

        $prospectNom = trim((string) ($prospect['nom'] ?? $prospect['prospect_nom'] ?? ''));
        $societe = trim((string) ($prospect['societe'] ?? ''));
        $context = $this->makeBasicContext([
            'prospect_nom' => $prospectNom,
            'societe' => $societe,
            'societe_label' => $societe !== '' ? ('• ' . $societe) : '',
            'last_activity' => trim((string) ($prospect['last_activity'] ?? '')),
            'days_inactive' => (string) ((int) ($prospect['days_inactive'] ?? 0)),
            'prospect_id' => (int) ($prospect['id'] ?? $prospect['prospect_id'] ?? 0),
        ]);
        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Prospect inactif';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Prospect inactif.';
        return [
            'event_key' => self::EVENT_CRM_PROSPECT_INACTIVE,
            'sub_key' => 'alert',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/crm/prospects.php',
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeBrokenDebourseLinksDetectedContent(array $contextData): ?array
    {
        $this->ensureDefaultRulesForBrokenDebourseLinksDetected();
        $rule = $this->getRule(self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED, 'alert');
        if (!$rule['is_enabled']) {
            return null;
        }

        $context = $this->makeBasicContext([
            'broken_count' => (string) ((int) ($contextData['broken_count'] ?? 0)),
            'chantier_code' => (string) ($contextData['chantier_code'] ?? ''),
            'chantier_titre' => (string) ($contextData['chantier_titre'] ?? ''),
            'chantier_label' => (string) ($contextData['chantier_label'] ?? ''),
        ]);
        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Alerte liens de déboursés';
        }
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : 'Des liens de déboursés cassés ont été détectés.';
        return [
            'event_key' => self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED,
            'sub_key' => 'alert',
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/admin/relink_fiches_debourse.php',
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    private function composeRhHierarchyAssignedContent(array $assignment, string $roleKey): ?array
    {
        $roleKey = strtolower(trim($roleKey));
        if (!in_array($roleKey, ['direct_manager', 'department_head'], true)) {
            return null;
        }

        $this->ensureDefaultRulesForRhHierarchyAssigned();
        $rule = $this->getRule(self::EVENT_RH_HIERARCHY_ASSIGNED, $roleKey);
        if (!$rule['is_enabled']) {
            return null;
        }

        $roleLabel = trim((string) ($assignment['role_label'] ?? ''));
        if ($roleLabel === '') {
            $roleLabel = $roleKey === 'department_head' ? 'Directeur du département' : 'Responsable';
        }
        $departmentLabel = trim((string) ($assignment['department_label'] ?? $assignment['service_label'] ?? ''));
        $nomineeName = trim((string) ($assignment['nominee_name'] ?? ''));
        $directionLabel = trim((string) ($assignment['direction_label'] ?? ''));

        $context = $this->makeBasicContext([
            'role_key' => $roleKey,
            'role_label' => $roleLabel,
            'department_label' => $departmentLabel,
            'service_label' => $departmentLabel,
            'direction_label' => $directionLabel,
            'nominee_name' => $nomineeName,
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nomination hiérarchique';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Par la présente, nous vous informons de votre nomination aux fonctions de "' . $roleLabel . '" du département ' . ($departmentLabel !== '' ? $departmentLabel : 'concerné') . '.';
        }

        return [
            'event_key' => self::EVENT_RH_HIERARCHY_ASSIGNED,
            'sub_key' => $roleKey,
            'rule' => $rule,
            'title' => $title,
            'message' => $message,
            'link' => '/profil.php',
            'channels' => (array) ($rule['channels'] ?? ['system']),
            'context' => $context,
        ];
    }

    public function ensureDefaultRulesForScheduleOccurrenceCreated(): void
    {
        $subKey = 'recurrence';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_SCHEDULE_OCCURRENCE_CREATED, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_SCHEDULE_OCCURRENCE_CREATED,
            $subKey,
            'Planning : tâche récurrente créée',
            true,
            self::RECIPIENT_REQUESTER,
            ['system', 'email'],
            'Nouvelle tâche : {{titre}}',
            'Une nouvelle occurrence a été ajoutée à votre planning le {{date_event}} ({{start}} → {{end}}).'
        );
    }

    public function ensureDefaultRulesForScheduleTaskAssigned(): void
    {
        $subKey = 'assigned';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_SCHEDULE_TASK_ASSIGNED, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_SCHEDULE_TASK_ASSIGNED,
            $subKey,
            'Planning : tâche affectée',
            true,
            self::RECIPIENT_REQUESTER,
            ['system', 'email'],
            'Nouvelle tâche : {{titre}}',
            'Une tâche vous a été affectée le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.'
        );
    }

    public function ensureDefaultRulesForScheduleTaskChanged(): void
    {
        $defaults = [
            'group_updated' => [
                'label' => 'Planning : groupe modifié',
                'title' => 'Mise à jour planning : {{titre}}',
                'message' => '{{actor_name}} a modifié une tâche groupée prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.',
            ],
            'group_cancelled' => [
                'label' => 'Planning : groupe annulé',
                'title' => 'Tâche annulée : {{titre}}',
                'message' => '{{actor_name}} a annulé une tâche groupée prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.',
            ],
            'restored' => [
                'label' => 'Planning : tâche restaurée',
                'title' => 'Tâche restaurée : {{titre}}',
                'message' => '{{actor_name}} a restauré une tâche prévue le {{date_event}} ({{start}} → {{end}}). Portée : {{scope_label}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_SCHEDULE_TASK_CHANGED, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_SCHEDULE_TASK_CHANGED,
                $subKey,
                (string) $def['label'],
                true,
                self::RECIPIENT_REQUESTER,
                ['system', 'email'],
                (string) $def['title'],
                (string) $def['message']
            );
        }
    }

    public function ensureDefaultRulesForCrmRdvCreated(): void
    {
        $subKey = 'created';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_CRM_RDV_CREATED, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_CRM_RDV_CREATED,
            $subKey,
            'CRM : RDV créé',
            true,
            self::RECIPIENT_REQUESTER,
            ['system', 'email'],
            'RDV créé : {{titre}}',
            'Un RDV a été créé le {{date_debut}} ({{start}} → {{end}}) {{linked_label}}.'
        );
    }

    public function ensureDefaultRulesForCrmRdvReminder(): void
    {
        $defaults = [
            '24h' => [
                'label' => 'CRM : rappel RDV (24h)',
                'title' => 'Rappel RDV (24h) : {{titre}}',
                'message' => 'Rappel : votre RDV est prévu le {{date_debut}} ({{start}} → {{end}}) {{linked_label}}.',
            ],
            '1h' => [
                'label' => 'CRM : rappel RDV (1h)',
                'title' => 'Rappel RDV (1h) : {{titre}}',
                'message' => 'Rappel : votre RDV commence dans ~1h ({{start}}) {{linked_label}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_CRM_RDV_REMINDER, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_CRM_RDV_REMINDER,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                self::RECIPIENT_REQUESTER,
                ['system', 'email'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForCrmOpportunityNextActionReminder(): void
    {
        $defaults = [
            '24h' => [
                'label' => 'CRM : rappel action opportunité (24h)',
                'title' => 'Rappel action (24h) : {{titre}}',
                'message' => 'Rappel : une action est prévue le {{date_action}} à {{start}} {{linked_label}}.',
            ],
            '1h' => [
                'label' => 'CRM : rappel action opportunité (1h)',
                'title' => 'Rappel action (1h) : {{titre}}',
                'message' => 'Rappel : votre action commence dans ~1h ({{start}}) {{linked_label}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                self::RECIPIENT_REQUESTER,
                ['system', 'email'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForCrmProspectInactive(): void
    {
        $subKey = 'alert';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_CRM_PROSPECT_INACTIVE, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_CRM_PROSPECT_INACTIVE,
            $subKey,
            'CRM : prospect inactif',
            true,
            self::RECIPIENT_REQUESTER,
            ['system', 'email'],
            'Prospect inactif : {{prospect_nom}}',
            'Aucun suivi depuis {{days_inactive}} jours : {{prospect_nom}} {{societe_label}}. Dernière activité : {{last_activity}}.'
        );
    }

    public function ensureDefaultRulesForBrokenDebourseLinksDetected(): void
    {
        $subKey = 'alert';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED,
            $subKey,
            'Décaissement : liens cassés détectés',
            true,
            self::TEAM_FINANCE,
            ['system', 'email'],
            'Liens cassés détectés : {{broken_count}} fiche(s)',
            '{{broken_count}} fiche(s) présentent un lien déboursé invalide{{chantier_label}}. Ouvrir l’outil de reliaison pour correction.'
        );
    }

    public function ensureDefaultRulesForRhHierarchyAssigned(): void
    {
        $defaults = [
            'direct_manager' => [
                'label' => 'RH : nomination responsable',
                'title' => 'Décision de nomination : {{role_label}}',
                'message' => 'Par la présente, nous vous informons de votre nomination aux fonctions de "{{role_label}}" du département {{department_label}}.',
            ],
            'department_head' => [
                'label' => 'RH : nomination directeur de département',
                'title' => 'Décision de nomination : {{role_label}}',
                'message' => 'Par la présente, nous vous informons de votre nomination aux fonctions de "{{role_label}}" du département {{department_label}}.',
            ],
        ];

        $legacyTitles = [
            'direct_manager' => 'Nomination : Responsable {{department_label}}',
            'department_head' => 'Nomination : Directeur du département {{department_label}}',
        ];
        $legacyTitlesBySub = [
            'direct_manager' => ['Notification de nomination : {{role_label}}'],
            'department_head' => ['Notification de nomination : {{role_label}}'],
        ];
        $legacyMessages = [
            'Félicitations, vous avez été nommé "{{role_label}}" du département {{department_label}}.',
            'Nous avons le plaisir de vous informer de votre nomination en qualité de "{{role_label}}" du département {{department_label}}.',
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT id, title_template, message_template FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk LIMIT 1');
            $stmt->execute(['ek' => self::EVENT_RH_HIERARCHY_ASSIGNED, 'sk' => $subKey]);
            $existingRule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existingRule !== null) {
                $existingTitle = trim((string) ($existingRule['title_template'] ?? ''));
                $existingMessage = trim((string) ($existingRule['message_template'] ?? ''));
                $legacyTitlesForSubKey = array_merge([$legacyTitles[$subKey] ?? ''], $legacyTitlesBySub[$subKey] ?? []);
                $shouldRefreshTitle = $existingTitle === '' || in_array($existingTitle, $legacyTitlesForSubKey, true);
                $shouldRefreshMessage = $existingMessage === '' || in_array($existingMessage, $legacyMessages, true);

                if ($shouldRefreshTitle || $shouldRefreshMessage) {
                    $updateStmt = $this->pdo->prepare('UPDATE system_notification_rules SET title_template = :title, message_template = :message WHERE id = :id');
                    $updateStmt->execute([
                        'title' => $shouldRefreshTitle ? (string) ($def['title'] ?? '') : $existingTitle,
                        'message' => $shouldRefreshMessage ? (string) ($def['message'] ?? '') : $existingMessage,
                        'id' => (int) ($existingRule['id'] ?? 0),
                    ]);
                }
                continue;
            }

            $this->upsertRule(
                self::EVENT_RH_HIERARCHY_ASSIGNED,
                $subKey,
                (string) ($def['label'] ?? $subKey),
                true,
                self::RECIPIENT_REQUESTER,
                ['system', 'email'],
                (string) ($def['title'] ?? ''),
                (string) ($def['message'] ?? '')
            );
        }
    }

    public function ensureDefaultRulesForCommunicationTaskChanged(): void
    {
        $defaults = [
            'group_updated' => [
                'label' => 'Communication : groupe modifié',
                'title' => 'Tâche communication modifiée : {{titre}}',
                'message' => '{{actor_name}} a modifié une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.',
            ],
            'group_cancelled' => [
                'label' => 'Communication : groupe annulé',
                'title' => 'Tâche communication annulée : {{titre}}',
                'message' => '{{actor_name}} a annulé une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.',
            ],
        ];

        foreach ($defaults as $subKey => $def) {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
            $stmt->execute(['ek' => self::EVENT_COMMUNICATION_TASK_CHANGED, 'sk' => $subKey]);
            if ((int) $stmt->fetchColumn() > 0) {
                continue;
            }

            $this->upsertRule(
                self::EVENT_COMMUNICATION_TASK_CHANGED,
                $subKey,
                (string) $def['label'],
                true,
                self::RECIPIENT_REQUESTER,
                ['system', 'email'],
                (string) $def['title'],
                (string) $def['message']
            );
        }
    }

    public function ensureDefaultRulesForCommunicationTaskAssigned(): void
    {
        $subKey = 'group_created';

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM system_notification_rules WHERE event_key = :ek AND sub_key = :sk');
        $stmt->execute(['ek' => self::EVENT_COMMUNICATION_TASK_ASSIGNED, 'sk' => $subKey]);
        if ((int) $stmt->fetchColumn() > 0) {
            return;
        }

        $this->upsertRule(
            self::EVENT_COMMUNICATION_TASK_ASSIGNED,
            $subKey,
            'Communication : groupe créé',
            true,
            self::RECIPIENT_REQUESTER,
            ['system', 'email'],
            'Nouvelle série de tâches communication : {{titre}}',
            '{{actor_name}} a créé une série de tâches communication. Échéance : {{deadline}}. Portée : {{scope_label}}.'
        );
    }

    /**
     * Notification multi-canal lors de l'envoi d'un message/tâche via le module Communication.
     *
     * NOTE: pour répondre au besoin "pour cette fois", on force les canaux
     * (system + email + whatsapp) sans passer par les règles admin.
     *
     * @param array<int,int|string> $recipientUserIds
     * @param array<string,mixed> $message
     */
    public function notifyCommunicationMessageSent(int $senderUserId, array $recipientUserIds, array $message): void
    {
        $senderUserId = (int) $senderUserId;
        if ($senderUserId <= 0) {
            return;
        }

        $recipients = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), static function (int $v) use ($senderUserId): bool {
            return $v > 0 && $v !== $senderUserId;
        })));

        if (!$recipients) {
            return;
        }

        $messageId = (int) ($message['id'] ?? $message['message_id'] ?? 0);
        $chantierId = (int) ($message['chantier_id'] ?? 0);
        $type = strtolower(trim((string) ($message['message_type'] ?? 'message')));
        if (!in_array($type, ['message', 'task'], true)) {
            $type = 'message';
        }

        $senderName = $this->getUserDisplayName($senderUserId) ?: ('Utilisateur #' . $senderUserId);

        $taskTitle = trim((string) ($message['task_title'] ?? ''));
        $deadlineRaw = trim((string) ($message['deadline'] ?? $message['deadline_sql'] ?? $message['deadline_iso'] ?? ''));
        $bodyRaw = trim((string) ($message['body'] ?? ''));

        $deadlineLabel = '';
        if ($deadlineRaw !== '') {
            try {
                $dt = new DateTimeImmutable($deadlineRaw);
                $deadlineLabel = $dt->format('Y-m-d H:i');
            } catch (Throwable $e) {
                $deadlineLabel = $deadlineRaw;
            }
        }

        $title = $type === 'task'
            ? ('Nouvelle tâche — ' . $senderName)
            : ('Nouveau message — ' . $senderName);

        $messageText = '';
        if ($type === 'task') {
            $messageText = $taskTitle !== '' ? $taskTitle : 'Tâche';
            if ($deadlineLabel !== '') {
                $messageText .= ' (deadline: ' . $deadlineLabel . ')';
            }
            if ($bodyRaw !== '') {
                $messageText .= ' — ' . $this->truncateText($bodyRaw, 160);
            }
        } else {
            $messageText = $bodyRaw !== '' ? $this->truncateText($bodyRaw, 220) : 'Pièce jointe envoyée.';
        }

        // Communication actuelle: discussion unique chantier_id=0 (mais on garde la possibilité de deep-link).
        $link = 'communication/mes_taches_communication.php';
        if ($chantierId > 0) {
            $link .= '?chantier_id=' . rawurlencode((string) $chantierId);
        }

        $channels = ['system', 'email', 'whatsapp'];

        foreach ($recipients as $rid) {
            try {
                $notifId = $this->createNotification(
                    self::EVENT_COMMUNICATION_MESSAGE_SENT,
                    $type,
                    $title,
                    $messageText,
                    $link,
                    null,
                    (int) $rid,
                    $channels,
                    [
                        'message_id' => $messageId,
                        'chantier_id' => $chantierId,
                        'sender_user_id' => $senderUserId,
                        'message_type' => $type,
                    ]
                );

                // Envois externes (email/whatsapp) non bloquants.
                if ($notifId > 0) {
                    $this->sendEmailForUserId((int) $rid, $title, $messageText, $link, [], ['communication_message_sent.' . $type, 'communication_message_sent', 'mailbox']);
                    $this->sendWhatsAppForUserId((int) $rid, $title, $messageText, $link);
                }
            } catch (Throwable $e) {
                // Ne pas bloquer le workflow.
            }
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function notifyCommunicationTaskChanged(int $recipientUserId, string $changeType, array $event): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $changeType = strtolower(trim($changeType));
        if (!in_array($changeType, ['group_updated', 'group_cancelled'], true)) {
            return;
        }

        $this->ensureDefaultRulesForCommunicationTaskChanged();

        $rule = $this->getRule(self::EVENT_COMMUNICATION_TASK_CHANGED, $changeType);
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $deadlineRaw = trim((string) ($event['deadline'] ?? $event['deadline_raw'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation groupée'));
        $actorUserId = (int) ($event['actor_user_id'] ?? 0);
        $actorName = trim((string) ($event['actor_name'] ?? ''));
        if ($actorName === '' && $actorUserId > 0) {
            $actorName = (string) ($this->getUserDisplayName($actorUserId) ?? 'Un collègue');
        }
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $deadlineDt = null;
        try {
            if ($deadlineRaw !== '') {
                $deadlineDt = new DateTimeImmutable($deadlineRaw);
            }
        } catch (Throwable $e) {
            $deadlineDt = null;
        }

        $dateEvent = $deadlineDt ? $deadlineDt->format('Y-m-d') : date('Y-m-d');
        $deadlineLabel = $deadlineDt ? $deadlineDt->format('Y-m-d H:i') : $deadlineRaw;

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'deadline' => $deadlineLabel,
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = $changeType === 'group_cancelled' ? 'Tâche communication annulée' : 'Tâche communication modifiée';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = $changeType === 'group_cancelled'
                ? 'Une série de tâches communication a été annulée.'
                : 'Une série de tâches communication a été modifiée.';
        }

        $link = '/communication/taches_communication.php';
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_COMMUNICATION_TASK_CHANGED, $changeType, $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
            'group_scope' => (string) ($event['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
            'actor_name' => $actorName,
        ]);

        $excludedUserIds = $recipientUser !== null ? [$recipientUser] : $this->listActiveUserIdsForTeam((string) $recipientTeam);
        $this->dispatchCommunicationPushRecipients(
            self::EVENT_COMMUNICATION_TASK_CHANGED,
            $changeType,
            $title,
            $message,
            $link,
            $channels,
            [
                'task_id' => (int) ($event['task_id'] ?? 0),
                'event_group_key' => (string) ($event['event_group_key'] ?? ''),
                'group_scope' => (string) ($event['group_scope'] ?? ''),
                'group_scope_label' => $scopeLabel,
                'actor_name' => $actorName,
            ],
            $excludedUserIds,
            $actorUserId
        );

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function notifyCommunicationTaskAssigned(int $recipientUserId, string $assignType, array $event): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $assignType = strtolower(trim($assignType));
        if ($assignType !== 'group_created') {
            return;
        }

        $this->ensureDefaultRulesForCommunicationTaskAssigned();

        $rule = $this->getRule(self::EVENT_COMMUNICATION_TASK_ASSIGNED, $assignType);
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $deadlineRaw = trim((string) ($event['deadline'] ?? $event['deadline_raw'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation groupée'));
        $actorUserId = (int) ($event['actor_user_id'] ?? 0);
        $actorName = trim((string) ($event['actor_name'] ?? ''));
        if ($actorName === '' && $actorUserId > 0) {
            $actorName = (string) ($this->getUserDisplayName($actorUserId) ?? 'Un collègue');
        }
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $deadlineDt = null;
        try {
            if ($deadlineRaw !== '') {
                $deadlineDt = new DateTimeImmutable($deadlineRaw);
            }
        } catch (Throwable $e) {
            $deadlineDt = null;
        }

        $dateEvent = $deadlineDt ? $deadlineDt->format('Y-m-d') : date('Y-m-d');
        $deadlineLabel = $deadlineDt ? $deadlineDt->format('Y-m-d H:i') : $deadlineRaw;

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'deadline' => $deadlineLabel,
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle série de tâches communication';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Une nouvelle série de tâches communication vous a été attribuée.';
        }

        $link = '/communication/taches_communication.php';
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_COMMUNICATION_TASK_ASSIGNED, $assignType, $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'task_id' => (int) ($event['task_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
            'group_scope' => (string) ($event['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
            'actor_name' => $actorName,
        ]);

        $excludedUserIds = $recipientUser !== null ? [$recipientUser] : $this->listActiveUserIdsForTeam((string) $recipientTeam);
        $this->dispatchCommunicationPushRecipients(
            self::EVENT_COMMUNICATION_TASK_ASSIGNED,
            $assignType,
            $title,
            $message,
            $link,
            $channels,
            [
                'task_id' => (int) ($event['task_id'] ?? 0),
                'event_group_key' => (string) ($event['event_group_key'] ?? ''),
                'group_scope' => (string) ($event['group_scope'] ?? ''),
                'group_scope_label' => $scopeLabel,
                'actor_name' => $actorName,
            ],
            $excludedUserIds,
            $actorUserId
        );

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function notifyScheduleOccurrenceCreated(int $recipientUserId, array $event): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $this->ensureDefaultRulesForScheduleOccurrenceCreated();

        $rule = $this->getRule(self::EVENT_SCHEDULE_OCCURRENCE_CREATED, 'recurrence');
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));

        $startDt = null;
        $endDt = null;
        try {
            if ($startRaw !== '') {
                $startDt = new DateTimeImmutable($startRaw);
            }
            if ($endRaw !== '') {
                $endDt = new DateTimeImmutable($endRaw);
            }
        } catch (Throwable $e) {
            $startDt = null;
            $endDt = null;
        }

        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $startDt ? $startDt->format('Y-m-d H:i') : '';
        $endLabel = $endDt ? $endDt->format('Y-m-d H:i') : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startLabel,
            'end' => $endLabel,
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'recurrence_rule_id' => (int) ($event['recurrence_rule_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle tâche planning';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Une nouvelle occurrence a été ajoutée à votre planning.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_SCHEDULE_OCCURRENCE_CREATED, 'recurrence', $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'recurrence_rule_id' => (int) ($event['recurrence_rule_id'] ?? 0),
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function notifyScheduleTaskAssigned(int $recipientUserId, array $event): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $this->ensureDefaultRulesForScheduleTaskAssigned();

        $rule = $this->getRule(self::EVENT_SCHEDULE_TASK_ASSIGNED, 'assigned');
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation individuelle'));

        $startDt = null;
        $endDt = null;
        try {
            if ($startRaw !== '') {
                $startDt = new DateTimeImmutable($startRaw);
            }
            if ($endRaw !== '') {
                $endDt = new DateTimeImmutable($endRaw);
            }
        } catch (Throwable $e) {
            $startDt = null;
            $endDt = null;
        }

        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $startDt ? $startDt->format('Y-m-d H:i') : '';
        $endLabel = $endDt ? $endDt->format('Y-m-d H:i') : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startLabel,
            'end' => $endLabel,
            'scope_label' => $scopeLabel,
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Nouvelle tâche planning';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Une nouvelle tâche a été affectée à votre planning.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_SCHEDULE_TASK_ASSIGNED, 'assigned', $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
            'group_scope' => (string) ($event['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $event
     */
    public function notifyScheduleTaskChanged(int $recipientUserId, string $changeType, array $event): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $changeType = strtolower(trim($changeType));
        if (!in_array($changeType, ['group_updated', 'group_cancelled', 'restored'], true)) {
            return;
        }

        $this->ensureDefaultRulesForScheduleTaskChanged();

        $rule = $this->getRule(self::EVENT_SCHEDULE_TASK_CHANGED, $changeType);
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($event['title'] ?? ''));
        $startRaw = trim((string) ($event['start_at'] ?? ''));
        $endRaw = trim((string) ($event['end_at'] ?? ''));
        $scopeLabel = trim((string) ($event['group_scope_label'] ?? 'Affectation individuelle'));
        $actorUserId = (int) ($event['actor_user_id'] ?? 0);
        $actorName = trim((string) ($event['actor_name'] ?? ''));
        if ($actorName === '' && $actorUserId > 0) {
            $actorName = (string) ($this->getUserDisplayName($actorUserId) ?? 'Un collègue');
        }
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $startDt = null;
        $endDt = null;
        try {
            if ($startRaw !== '') {
                $startDt = new DateTimeImmutable($startRaw);
            }
            if ($endRaw !== '') {
                $endDt = new DateTimeImmutable($endRaw);
            }
        } catch (Throwable $e) {
            $startDt = null;
            $endDt = null;
        }

        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $startDt ? $startDt->format('Y-m-d H:i') : '';
        $endLabel = $endDt ? $endDt->format('Y-m-d H:i') : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_event' => $dateEvent,
            'start' => $startLabel,
            'end' => $endLabel,
            'scope_label' => $scopeLabel,
            'actor_name' => $actorName,
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Mise à jour planning';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Une mise à jour a été appliquée à votre planning.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_SCHEDULE_TASK_CHANGED, $changeType, $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'event_id' => (int) ($event['event_id'] ?? 0),
            'personnel_id' => (int) ($event['personnel_id'] ?? 0),
            'event_group_key' => (string) ($event['event_group_key'] ?? ''),
            'group_scope' => (string) ($event['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
            'actor_name' => $actorName,
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $rdv
     */
    public function notifyCrmRdvCreated(int $recipientUserId, array $rdv): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $this->ensureDefaultRulesForCrmRdvCreated();

        $rule = $this->getRule(self::EVENT_CRM_RDV_CREATED, 'created');
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($rdv['titre'] ?? $rdv['title'] ?? ''));
        $startRaw = trim((string) ($rdv['date_debut'] ?? $rdv['start_at'] ?? ''));
        $endRaw = trim((string) ($rdv['date_fin'] ?? $rdv['end_at'] ?? ''));
        $linkedName = trim((string) ($rdv['linked_name'] ?? ''));

        $startDt = null;
        $endDt = null;
        try {
            if ($startRaw !== '') {
                $startDt = new DateTimeImmutable($startRaw);
            }
            if ($endRaw !== '') {
                $endDt = new DateTimeImmutable($endRaw);
            }
        } catch (Throwable $e) {
            $startDt = null;
            $endDt = null;
        }

        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $startDt ? $startDt->format('Y-m-d H:i') : '';
        $endLabel = $endDt ? $endDt->format('Y-m-d H:i') : '';
        $linkedLabel = $linkedName !== '' ? ('• ' . $linkedName) : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_debut' => $startDt ? $startDt->format('Y-m-d') : $dateEvent,
            'start' => $startLabel,
            'end' => $endLabel,
            'linked_name' => $linkedName,
            'linked_label' => $linkedLabel,
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'RDV CRM créé';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Un RDV a été créé.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_CRM_RDV_CREATED, 'created', $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $rdv
     */
    public function notifyCrmRdvReminder(int $recipientUserId, string $subKey, array $rdv): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $subKey = strtolower(trim($subKey));
        if (!in_array($subKey, ['24h', '1h'], true)) {
            $subKey = '1h';
        }

        $this->ensureDefaultRulesForCrmRdvReminder();

        $rule = $this->getRule(self::EVENT_CRM_RDV_REMINDER, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($rdv['titre'] ?? $rdv['title'] ?? ''));
        $startRaw = trim((string) ($rdv['date_debut'] ?? $rdv['start_at'] ?? ''));
        $endRaw = trim((string) ($rdv['date_fin'] ?? $rdv['end_at'] ?? ''));
        $linkedName = trim((string) ($rdv['linked_name'] ?? ''));

        $startDt = null;
        $endDt = null;
        try {
            if ($startRaw !== '') {
                $startDt = new DateTimeImmutable($startRaw);
            }
            if ($endRaw !== '') {
                $endDt = new DateTimeImmutable($endRaw);
            }
        } catch (Throwable $e) {
            $startDt = null;
            $endDt = null;
        }

        $dateEvent = $startDt ? $startDt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $startDt ? $startDt->format('Y-m-d H:i') : '';
        $endLabel = $endDt ? $endDt->format('Y-m-d H:i') : '';
        $linkedLabel = $linkedName !== '' ? ('• ' . $linkedName) : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_debut' => $startDt ? $startDt->format('Y-m-d') : $dateEvent,
            'start' => $startLabel,
            'end' => $endLabel,
            'linked_name' => $linkedName,
            'linked_label' => $linkedLabel,
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Rappel RDV CRM';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Rappel RDV.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_CRM_RDV_REMINDER, $subKey, $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'rdv_id' => (int) ($rdv['id'] ?? $rdv['rdv_id'] ?? 0),
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $opp
     */
    public function notifyCrmOpportunityNextActionReminder(int $recipientUserId, string $subKey, array $opp): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $subKey = strtolower(trim($subKey));
        if (!in_array($subKey, ['24h', '1h'], true)) {
            $subKey = '1h';
        }

        $this->ensureDefaultRulesForCrmOpportunityNextActionReminder();

        $rule = $this->getRule(self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $titleRaw = trim((string) ($opp['titre'] ?? $opp['title'] ?? ''));
        $nextActionRaw = trim((string) ($opp['next_action_at'] ?? $opp['date_action'] ?? ''));
        $linkedName = trim((string) ($opp['linked_name'] ?? ''));

        $dt = null;
        try {
            if ($nextActionRaw !== '') {
                $dt = new DateTimeImmutable($nextActionRaw);
            }
        } catch (Throwable $e) {
            $dt = null;
        }

        $dateEvent = $dt ? $dt->format('Y-m-d') : date('Y-m-d');
        $startLabel = $dt ? $dt->format('Y-m-d H:i') : '';
        $linkedLabel = $linkedName !== '' ? ('• ' . $linkedName) : '';

        $context = $this->makeBasicContext([
            'titre' => $titleRaw,
            'date_action' => $dt ? $dt->format('Y-m-d') : $dateEvent,
            'start' => $startLabel,
            'linked_name' => $linkedName,
            'linked_label' => $linkedLabel,
            'opportunite_id' => (int) ($opp['id'] ?? $opp['opportunite_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Rappel action opportunité';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Rappel action.';
        }

        $link = '/emploi_du_temps.php?period=day&date=' . rawurlencode($dateEvent);
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_CRM_OPPORTUNITY_NEXT_ACTION_REMINDER, $subKey, $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'opportunite_id' => (int) ($opp['id'] ?? $opp['opportunite_id'] ?? 0),
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * @param array<string,mixed> $prospect
     */
    public function notifyCrmProspectInactive(int $recipientUserId, array $prospect): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $this->ensureDefaultRulesForCrmProspectInactive();

        $rule = $this->getRule(self::EVENT_CRM_PROSPECT_INACTIVE, 'alert');
        if (!$rule['is_enabled']) {
            return;
        }

        $prospectNom = trim((string) ($prospect['nom'] ?? $prospect['prospect_nom'] ?? ''));
        $societe = trim((string) ($prospect['societe'] ?? ''));
        $lastActivity = trim((string) ($prospect['last_activity'] ?? $prospect['last_activity_at'] ?? ''));
        $daysInactive = (int) ($prospect['days_inactive'] ?? 0);
        $societeLabel = $societe !== '' ? ('• ' . $societe) : '';

        $context = $this->makeBasicContext([
            'prospect_nom' => $prospectNom,
            'societe' => $societe,
            'societe_label' => $societeLabel,
            'last_activity' => $lastActivity,
            'days_inactive' => (string) $daysInactive,
            'prospect_id' => (int) ($prospect['id'] ?? $prospect['prospect_id'] ?? 0),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Prospect inactif';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = 'Prospect inactif.';
        }

        $link = '/crm/prospects.php';
        $channels = (array) ($rule['channels'] ?? ['system']);

        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::RECIPIENT_REQUESTER);
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = $recipientUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(self::EVENT_CRM_PROSPECT_INACTIVE, 'alert', $title, $message, $link, $recipientTeam, $recipientUser, $channels, [
            'prospect_id' => (int) ($prospect['id'] ?? $prospect['prospect_id'] ?? 0),
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    public function notifyDocumentShareCreated(int $recipientUserId, array $shareData): void
    {
        $recipientUserId = (int) $recipientUserId;
        if ($recipientUserId <= 0) {
            return;
        }

        $documentTitle = trim((string) ($shareData['document_title'] ?? 'Document'));
        $shareUrl = trim((string) ($shareData['share_url'] ?? ''));
        $creatorName = trim((string) ($shareData['creator_name'] ?? ($_SESSION['user_name'] ?? 'Un collaborateur')));
        $permissions = $shareData['permissions'] ?? [];
        if (!is_array($permissions)) {
            $permissions = [];
        }

        $granted = ['visualisation'];
        if (!empty($permissions['allow_download'])) {
            $granted[] = 'telechargement';
        }
        if (!empty($permissions['can_edit'])) {
            $granted[] = 'edition';
        }
        if (!empty($permissions['can_reshare'])) {
            $granted[] = 'repartage';
        }

        $title = 'Document partage : ' . ($documentTitle !== '' ? $documentTitle : 'Document');
        $message = $creatorName . ' vous a partage un document avec les droits suivants : ' . implode(', ', $granted) . '.';

        $payload = [
            'document_id' => (int) ($shareData['document_id'] ?? 0),
            'share_token' => (string) ($shareData['token'] ?? ''),
            'permissions' => $permissions,
            'recipient_user_id' => $recipientUserId,
        ];

        $this->createNotification(
            self::EVENT_DOCUMENT_SHARE_CREATED,
            'direct_share',
            $title,
            $message,
            $shareUrl,
            null,
            $recipientUserId,
            ['system'],
            $payload
        );
    }

    private function renderTemplate(string $template, array $context): string
    {
        $template = (string) $template;
        if ($template === '') {
            return '';
        }

        return (string) preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $m) use ($context): string {
            $key = (string) ($m[1] ?? '');
            $val = $context[$key] ?? '';
            if (is_scalar($val) || $val === null) {
                return (string) ($val ?? '');
            }
            return '';
        }, $template);
    }

    private function makeBasicContext(array $extra = []): array
    {
        $base = [
            'date' => date('Y-m-d H:i'),
        ];
        foreach ($extra as $k => $v) {
            if (is_string($k) && $k !== '') {
                $base[$k] = $v;
            }
        }
        return $base;
    }

    public function notifyLegalRequestSubmitted(int $requestId, string $requestType, string $requestTypeLabel, string $assignedTeam, string $requesterName, array $requestData = [], bool $isUpdate = false): void
    {
        $requestType = strtolower(trim($requestType));
        $assignedTeam = strtolower(trim($assignedTeam));

        $rule = $this->getRule(self::EVENT_LEGAL_REQUEST_SUBMITTED, $requestType);
        if (!$rule['is_enabled']) {
            return;
        }

        $recipientTeam = $rule['recipient_team'] ?: $assignedTeam;
        if (!in_array($recipientTeam, self::getKnownTeams(), true)) {
            $recipientTeam = $assignedTeam;
        }

        $payload = isset($requestData['payload']) && is_array($requestData['payload']) ? (array) $requestData['payload'] : [];
        $motifDemande = trim((string) ($payload['motif_demande'] ?? $payload['motif'] ?? $payload['description'] ?? ''));
        $requestTitle = trim((string) ($requestData['title'] ?? ''));

        $context = $this->makeBasicContext([
            'id' => (int) $requestId,
            'request_id' => (int) $requestId,
            'type' => $requestType,
            'type_label' => $requestTypeLabel,
            'assigned_team' => $assignedTeam,
            'nom_expediteur' => (string) $requesterName,
            'titre' => $requestTitle,
            'motif' => $motifDemande,
            'details_demande' => $motifDemande,
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        if ($titleTpl === '') {
            $titleTpl = $isUpdate ? ('Mise à jour demande de ' . $requestTypeLabel) : ('Demande de ' . $requestTypeLabel);
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = $isUpdate ? ('Mise à jour demande de ' . $requestTypeLabel) : ('Demande de ' . $requestTypeLabel);
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        if ($messageTpl !== '') {
            $message = trim($this->renderTemplate($messageTpl, $context));
        } else {
            $message = trim($requesterName) !== ''
                ? (trim($requesterName) . ($isUpdate ? ' a modifié une demande.' : ' a soumis une demande.'))
                : ($isUpdate ? 'Une demande a été modifiée.' : 'Une nouvelle demande a été soumise.');
        }

        if ($requestTitle !== '') {
            $message .= ' Titre: ' . $requestTitle . '.';
        }
        if ($motifDemande !== '') {
            $message .= ' Motif: ' . $motifDemande;
        }

        $link = '';
        if ($recipientTeam === self::TEAM_FINANCE) {
            $link = '/validation_demandes_finances.php?id=' . (int) $requestId;
        } elseif ($recipientTeam === self::TEAM_RH) {
            $link = '/rh/validation_demandes_rh.php?id=' . (int) $requestId;
        } elseif ($recipientTeam === self::TEAM_ADMIN) {
            $link = '/admin/dashboard_admin.php';
        }

        $channels = (array) ($rule['channels'] ?? ['system']);

        $notifId = $this->createNotification(self::EVENT_LEGAL_REQUEST_SUBMITTED, $requestType, $title, $message, $link, $recipientTeam, null, $channels, [
            'request_id' => $requestId,
            'request_type' => $requestType,
        ]);

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, null, $recipientTeam, $title, $message, $link);
        }
    }

    public function notifyLegalRequestProcessed(array $request, string $decision, array $actor, string $note = ''): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        $requesterUserId = (int) ($request['requester_user_id'] ?? 0);
        if ($requestId <= 0 || $requesterUserId <= 0) {
            return;
        }

        $decision = strtolower(trim($decision));
        $composed = $this->composeLegalRequestProcessedContent($request, $decision, $actor, $note);
        if (!$composed) {
            return;
        }

        $notifId = $this->createNotification(
            self::EVENT_LEGAL_REQUEST_PROCESSED,
            $decision,
            (string) $composed['title'],
            (string) $composed['message'],
            (string) $composed['link'],
            null,
            $requesterUserId,
            (array) $composed['channels'],
            [
                'request_id' => $requestId,
                'decision' => $decision,
            ]
        );

        if ($notifId > 0 && in_array('email', (array) $composed['channels'], true)) {
            $this->sendConfiguredRuleEmail((array) ($composed['rule'] ?? []), $requesterUserId, null, (string) $composed['title'], (string) $composed['message'], (string) $composed['link']);
        }
    }

    public function notifyLegalRequestNeedDetails(array $request, array $actor, string $note = ''): void
    {
        $requestId = (int) ($request['id'] ?? 0);
        $requesterUserId = (int) ($request['requester_user_id'] ?? 0);
        if ($requestId <= 0 || $requesterUserId <= 0) {
            return;
        }

        $requestType = strtolower(trim((string) ($request['request_type'] ?? 'demande')));
        $requestTypeLabel = trim((string) ($request['type_label'] ?? $requestType));
        $actorName = trim((string) ($actor['name'] ?? 'La comptabilite'));
        if ($actorName === '') {
            $actorName = 'La comptabilite';
        }

        $title = 'Complement demande sur votre ' . strtoupper($requestTypeLabel !== '' ? $requestTypeLabel : $requestType);
        $message = $actorName . ' a demande des precisions ou des documents complementaires sur votre demande.';
        if (trim((string) ($request['title'] ?? '')) !== '') {
            $message .= ' Reference: ' . trim((string) $request['title']) . '.';
        }
        if (trim($note) !== '') {
            $message .= ' Details demandes: ' . trim($note);
        }
        $link = '/employe/details_demande_employe.php?id=' . $requestId;
        $channels = ['system', 'email'];

        $notifId = $this->createNotification(
            self::EVENT_LEGAL_REQUEST_PROCESSED,
            'details_requested',
            $title,
            $message,
            $link,
            null,
            $requesterUserId,
            $channels,
            [
                'request_id' => $requestId,
                'request_type' => $requestType,
                'decision' => 'details_requested',
            ]
        );

        if ($notifId > 0) {
            $this->sendEmailForUserId(
                $requesterUserId,
                $title,
                $message,
                $link,
                [],
                ['legal_request_processed.details_requested', 'legal_request_processed', 'legal_requests']
            );
        }
    }

    public function notifyLegalRequestModifiedByValidator(array $request, array $actor, string $note = ''): void
    {
        $requestId = (int)($request['id'] ?? 0);
        $requesterUserId = (int)($request['requester_user_id'] ?? 0);
        if ($requestId <= 0 || $requesterUserId <= 0) return;
        $actorName = trim((string)($actor['name'] ?? 'La direction'));
        if ($actorName === '') $actorName = 'La direction';
        $message = $actorName . ' a modifié les informations financières ou le planning de votre FEB #' . $requestId . '.';
        if (trim($note) !== '') $message .= ' ' . trim($note);
        $this->createNotification(
            self::EVENT_LEGAL_REQUEST_PROCESSED,
            'modified_by_validator',
            'Votre FEB a été ajustée',
            $message,
            '/employe/details_demande_employe.php?id=' . $requestId,
            null,
            $requesterUserId,
            ['system', 'email'],
            ['request_id'=>$requestId,'request_type'=>'feb','decision'=>'modified_by_validator']
        );
    }

    public function notifyDecaissementSubmitted(array $data, bool $isEpi, array $actor): void
    {
        $composed = $this->composeDecaissementSubmittedContent($data, $isEpi, $actor);
        if (!$composed) {
            return;
        }

        $notifId = $this->createNotification(
            self::EVENT_DECAISSEMENT_SUBMITTED,
            (string) $composed['sub_key'],
            (string) $composed['title'],
            (string) $composed['message'],
            (string) $composed['link'],
            (string) ($composed['recipient_team'] ?? self::TEAM_LOGISTIQUE),
            null,
            (array) $composed['channels'],
            [
                'num_fiche' => (string) ($data['num_fiche'] ?? ''),
                'is_epi' => $isEpi,
            ]
        );

        if ($notifId > 0 && in_array('email', (array) $composed['channels'], true)) {
            $this->sendConfiguredRuleEmail((array) ($composed['rule'] ?? []), null, (string) ($composed['recipient_team'] ?? self::TEAM_LOGISTIQUE), (string) $composed['title'], (string) $composed['message'], (string) $composed['link']);
        }
    }

    public function notifyRhFinancialLine(int $userId, string $stage, string $label, int $amount, string $num): int
    {
        if ($userId<=0) return 0;
        $title=$stage==='paid'?'Décaissement effectué':'Demande autorisée pour décaissement';
        $message=$label.' — '.number_format($amount,0,',',' ').' FCFA. Fiche '.$num.'. '.($stage==='paid'?'Le décaissement de la fiche est validé.':'Le paiement effectif reste en attente de validation du décaissement.');
        return $this->createNotification('rh_financial_line',$stage,$title,$message,'/employe/mes_demandes_employe.php',null,$userId,['system'],['amount'=>$amount,'label'=>$label,'num_fiche'=>$num]);
    }

    public function notifyDecaissementProcessed(array $fiche, string $decision, array $actor, string $motif = ''): void
    {
        if (strtolower(trim($decision)) === 'approved') {
            require_once __DIR__.'/../services/RhFinancialSettlementService.php';
            require_once __DIR__.'/../services/DisbursementBeneficiaryNotificationService.php';
            (new RhFinancialSettlementService($this->pdo))->sync((string)($fiche['num_fiche'] ?? ''), 'authorized', $this);
            (new DisbursementBeneficiaryNotificationService($this->pdo))->sync((string)($fiche['num_fiche'] ?? ''), 'authorized', $this);
        }
        $decision = strtolower(trim($decision));

        $createdByUserId = (int) ($fiche['created_by_user_id'] ?? 0);
        if ($createdByUserId <= 0) {
            return;
        }

        $composed = $this->composeDecaissementProcessedContent($fiche, $decision, $actor, $motif);
        if (!$composed) {
            return;
        }

        $notifId = $this->createNotification(
            self::EVENT_DECAISSEMENT_PROCESSED,
            $decision,
            (string) $composed['title'],
            (string) $composed['message'],
            (string) $composed['link'],
            null,
            $createdByUserId,
            (array) $composed['channels'],
            [
                'num_fiche' => (string) ($fiche['num_fiche'] ?? ''),
                'decision' => $decision,
            ]
        );

        if ($notifId > 0 && in_array('email', (array) $composed['channels'], true)) {
            $this->sendConfiguredRuleEmail((array) ($composed['rule'] ?? []), $createdByUserId, null, (string) $composed['title'], (string) $composed['message'], (string) $composed['link']);
        }
    }


    public function notifyTicketingEvent(string $subKey, array $ticket, array $actor = []): void
    {
        $subKey = strtolower(trim($subKey));
        if ($subKey === '') {
            return;
        }

        $this->ensureDefaultRulesForTicketing();
        $rule = $this->getRule(self::EVENT_TICKETING, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $ticketId = (int) ($ticket['id'] ?? 0);
        $ticketNo = trim((string) ($ticket['ticket_no'] ?? ($ticketId > 0 ? ('TK-' . $ticketId) : 'Ticket')));
        $titleValue = trim((string) ($ticket['title'] ?? 'Ticket interne'));
        $status = trim((string) ($ticket['status'] ?? 'nouveau'));
        $requesterName = trim((string) ($ticket['requester_name'] ?? 'Demandeur'));
        $assignedTarget = trim((string) ($ticket['assigned_user_name'] ?? ''));
        if ($assignedTarget === '') {
            $assignedTarget = trim((string) ($ticket['assigned_service'] ?? 'Centre de services'));
        }

        $context = $this->makeBasicContext([
            'ticket_id' => (string) $ticketId,
            'ticket_no' => $ticketNo,
            'ticket_title' => $titleValue,
            'ticket_status' => $status,
            'requester_name' => $requesterName,
            'assigned_target' => $assignedTarget,
            'actor_name' => trim((string) ($actor['name'] ?? $actor['actor_name'] ?? 'FINEA')),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? 'Ticket'));
        }
        $notifTitle = trim($this->renderTemplate($titleTpl, $context));
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : $notifTitle;

        $link = '/ticketing/index.php';
        if ($ticketId > 0) {
            $link .= '?action=show&id=' . $ticketId;
        }

        $channels = (array) ($rule['channels'] ?? ['system']);
        $target = strtolower(trim((string) ($rule['recipient_team'] ?? self::TEAM_TICKETING)));
        $recipientTeam = null;
        $recipientUser = null;
        if ($target === self::RECIPIENT_REQUESTER) {
            $recipientUser = (int) ($ticket['requester_id'] ?? 0);
            if ($recipientUser <= 0) {
                $recipientTeam = self::TEAM_TICKETING;
            }
        } elseif (!empty($ticket['assigned_user_id']) && in_array($subKey, ['created', 'assigned'], true)) {
            $recipientUser = (int) $ticket['assigned_user_id'];
        } else {
            $recipientTeam = $target !== '' ? $target : self::TEAM_TICKETING;
        }

        $payload = [
            'ticket_id' => $ticketId > 0 ? $ticketId : null,
            'ticket_no' => $ticketNo,
            'ticket_status' => $status,
            'actor_user_id' => isset($actor['id']) ? (int) $actor['id'] : null,
        ];

        $notifId = $this->createNotification(self::EVENT_TICKETING, $subKey, $notifTitle, $message, $link, $recipientTeam, $recipientUser, $channels, $payload);
        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $notifTitle, $message, $link);
        }
    }

    public function notifyLogisticsRequest(string $subKey, array $request, array $actor = []): void
    {
        $subKey = strtolower(trim($subKey));
        if ($subKey === '') {
            return;
        }

        $this->ensureDefaultRulesForLogisticsRequest();
        $rule = $this->getRule(self::EVENT_LOGISTICS_REQUEST, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $requestId = (int) ($request['id'] ?? $request['request_id'] ?? 0);
        $requestCode = trim((string) ($request['request_code'] ?? ($requestId > 0 ? ('REQ-' . $requestId) : '')));
        $requesterName = trim((string) ($request['requester_name'] ?? ''));
        $assetLabel = trim((string) ($request['asset_label'] ?? $request['designation'] ?? $request['asset_name'] ?? 'Element logistique'));
        $needType = trim((string) ($request['need_type'] ?? $request['type'] ?? 'logistique'));
        $returnLocation = trim((string) ($request['return_location'] ?? $request['site_label'] ?? $request['current_site'] ?? ''));

        $context = $this->makeBasicContext([
            'request_id' => (string) $requestId,
            'request_code' => $requestCode,
            'requester_name' => $requesterName !== '' ? $requesterName : 'Demandeur',
            'asset_label' => $assetLabel,
            'need_type' => $needType,
            'return_location' => $returnLocation !== '' ? $returnLocation : 'Non precise',
            'actor_name' => trim((string) ($actor['actor_name'] ?? $actor['name'] ?? 'Logistique')),
            'status' => trim((string) ($request['status'] ?? $request['statut'] ?? '')),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Notification logistique';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : '';
        if ($message === '') {
            $message = $title;
        }

        $link = '/logi/requests.php';
        if ($requestCode !== '') {
            $link .= '?q=' . rawurlencode($requestCode);
        } elseif ($requestId > 0) {
            $link .= '?q=' . rawurlencode((string) $requestId);
        }

        $channels = (array) ($rule['channels'] ?? ['system']);
        $recipientTeam = null;
        $recipientUser = null;
        $target = strtolower(trim((string) ($rule['recipient_team'] ?? self::TEAM_LOGISTIQUE)));
        if ($target === self::RECIPIENT_REQUESTER) {
            $uid = (int) ($request['user_id'] ?? $request['requester_user_id'] ?? $request['created_by_user_id'] ?? 0);
            if ($uid <= 0) {
                $recipientTeam = self::TEAM_LOGISTIQUE;
            } else {
                $recipientUser = $uid;
            }
        } else {
            $recipientTeam = $target !== '' ? $target : self::TEAM_LOGISTIQUE;
        }

        $payload = [
            'request_id' => $requestId > 0 ? $requestId : null,
            'request_code' => $requestCode !== '' ? $requestCode : null,
            'asset_id' => isset($request['asset_id']) ? (int) $request['asset_id'] : null,
            'asset_label' => $assetLabel,
            'need_type' => $needType,
            'actor_user_id' => isset($actor['user_id']) ? (int) $actor['user_id'] : null,
            'return_location' => $returnLocation !== '' ? $returnLocation : null,
        ];

        $notifId = $this->createNotification(self::EVENT_LOGISTICS_REQUEST, $subKey, $title, $message, $link, $recipientTeam, $recipientUser, $channels, $payload);
        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    public function notifyLogisticsAssetEvent(string $subKey, array $payload, array $actor = []): void
    {
        $subKey = strtolower(trim($subKey));
        if ($subKey === '') {
            return;
        }

        $this->ensureDefaultRulesForLogisticsAsset();
        $rule = $this->getRule(self::EVENT_LOGISTICS_ASSET, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $entityLabel = trim((string) ($payload['entity_label'] ?? $payload['designation'] ?? $payload['name'] ?? $payload['title'] ?? 'Element logistique'));
        $context = $this->makeBasicContext([
            'entity_label' => $entityLabel,
            'entity_type' => trim((string) ($payload['entity_type'] ?? 'logistique')),
            'actor_name' => trim((string) ($actor['actor_name'] ?? $actor['name'] ?? 'Logistique')),
            'site_label' => trim((string) ($payload['site_label'] ?? $payload['current_site'] ?? '')),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $context));
        if ($title === '') {
            $title = 'Action logistique';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $context)) : $title;
        $channels = (array) ($rule['channels'] ?? ['system']);
        $recipientTeam = strtolower(trim((string) ($rule['recipient_team'] ?? self::TEAM_LOGISTIQUE)));
        if ($recipientTeam === self::RECIPIENT_REQUESTER || $recipientTeam === '') {
            $recipientTeam = self::TEAM_LOGISTIQUE;
        }

        $link = '/logi/dashboard_logistique.php';
        $payload['entity_label'] = $entityLabel;
        $payload['actor_user_id'] = isset($actor['user_id']) ? (int) $actor['user_id'] : null;

        $notifId = $this->createNotification(self::EVENT_LOGISTICS_ASSET, $subKey, $title, $message, $link, $recipientTeam, null, $channels, $payload);
        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, null, $recipientTeam, $title, $message, $link);
        }
    }

    public function notifyAttendanceDeviceAlert(string $subKey, array $payload = []): void
    {
        $subKey = strtolower(trim($subKey));
        if (!in_array($subKey, ['api_unreachable', 'offline', 'clock_drift', 'server_time_mismatch'], true)) {
            return;
        }

        $this->ensureDefaultRulesForAttendanceDeviceAlert();
        $rule = $this->getRule(self::EVENT_ATTENDANCE_DEVICE_ALERT, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $deviceLabel = trim((string) ($payload['device_label'] ?? $payload['device_sn'] ?? $payload['collector_name'] ?? 'Badgeuse'));
        $fingerprint = trim((string) ($payload['fingerprint'] ?? ($subKey . ':' . $deviceLabel)));
        if ($fingerprint !== '' && $this->hasRecentNotificationFingerprint(self::EVENT_ATTENDANCE_DEVICE_ALERT, $subKey, $fingerprint, (int) ($payload['dedupe_hours'] ?? 1))) {
            return;
        }

        $context = $this->makeBasicContext([
            'device_label' => $deviceLabel !== '' ? $deviceLabel : 'Badgeuse',
            'device_sn' => trim((string) ($payload['device_sn'] ?? '')),
            'collector_name' => trim((string) ($payload['collector_name'] ?? '')),
            'detail' => trim((string) ($payload['detail'] ?? 'Non precise')),
            'last_seen_label' => trim((string) ($payload['last_seen_label'] ?? $payload['last_seen_at'] ?? 'date inconnue')),
            'threshold_label' => trim((string) ($payload['threshold_label'] ?? 'seuil non precise')),
            'drift_label' => trim((string) ($payload['drift_label'] ?? 'ecart inconnu')),
            'device_time' => trim((string) ($payload['device_time'] ?? 'heure inconnue')),
            'php_gmt' => trim((string) ($payload['php_gmt'] ?? 'heure PHP inconnue')),
            'mysql_utc' => trim((string) ($payload['mysql_utc'] ?? 'heure MySQL inconnue')),
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $title = trim($this->renderTemplate($titleTpl !== '' ? $titleTpl : (string) ($rule['label'] ?? 'Alerte badgeuse'), $context));
        $message = trim($this->renderTemplate($messageTpl !== '' ? $messageTpl : $title, $context));
        if ($title === '') {
            $title = 'Alerte badgeuse';
        }
        if ($message === '') {
            $message = $title;
        }

        $channels = (array) ($rule['channels'] ?? ['system', 'email']);
        $recipientTeam = strtolower(trim((string) ($rule['recipient_team'] ?? self::TEAM_RH)));
        if ($recipientTeam === self::RECIPIENT_REQUESTER || $recipientTeam === '') {
            $recipientTeam = self::TEAM_RH;
        }

        $payload['fingerprint'] = $fingerprint;
        $payload['device_label'] = $deviceLabel;
        $link = '/admin/attendance_control_center.php';

        if ($subKey === 'offline') {
            $this->dispatchAttendanceOfflineAlert($rule, $title, $message, $link, $channels, $payload);
            return;
        }

        $notifId = $this->createNotification(
            self::EVENT_ATTENDANCE_DEVICE_ALERT,
            $subKey,
            $title,
            $message,
            $link,
            $recipientTeam,
            null,
            $channels,
            $payload
        );
        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, null, $recipientTeam, $title, $message, $link);
        }
    }

    /**
     * Informe dans un seul envoi la Direction generale, les RH et l'Informatique.
     * Chaque compte concerne recoit aussi sa propre notification systeme.
     */
    private function dispatchAttendanceOfflineAlert(array $rule, string $title, string $message, string $link, array $channels, array $payload): void
    {
        $recipients = $this->listAttendanceOfflineRecipients();
        if ($recipients === []) {
            // Compatibilite avec les installations dont les services ne sont pas encore renseignes.
            $notifId = $this->createNotification(self::EVENT_ATTENDANCE_DEVICE_ALERT, 'offline', $title, $message, $link, self::TEAM_RH, null, $channels, $payload);
            if ($notifId > 0 && in_array('email', $channels, true)) {
                $this->sendConfiguredRuleEmail($rule, null, self::TEAM_RH, $title, $message, $link);
            }
            return;
        }

        if (in_array('system', $channels, true)) {
            foreach ($recipients as $recipient) {
                $this->createNotification(
                    self::EVENT_ATTENDANCE_DEVICE_ALERT,
                    'offline',
                    $title,
                    $message,
                    $link,
                    null,
                    (int) $recipient['user_id'],
                    ['system'],
                    $payload
                );
            }
        }

        if (!in_array('email', $channels, true)) {
            return;
        }

        $excludedEmails = $this->extractExcludedEmailsFromRule($rule);
        $preferenceKeys = $this->notificationPreferenceKeysFromRule($rule);
        $emailRecipients = [];
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $userId = (int) ($recipient['user_id'] ?? 0);
            if ($email === '' || $this->isEmailExcluded($email, $excludedEmails) || !$this->isEmailAllowedByPreferences($userId, $preferenceKeys)) {
                continue;
            }
            $emailRecipients[strtolower($email)] = $recipient;
        }
        $emailRecipients = array_values($emailRecipients);
        if ($emailRecipients === []) {
            return;
        }

        $primary = array_shift($emailRecipients);
        $cc = array_map(static fn(array $recipient): array => [
            'email' => (string) $recipient['email'],
            'name' => (string) $recipient['name'],
        ], $emailRecipients);
        $html = $this->buildNotificationEmailHtml($title, $message, $link);
        $plain = $this->buildNotificationEmailPlainText($title, $message, $link);

        try {
            $this->emailChannel->send((string) $primary['email'], (string) $primary['name'], $title, $html, $plain, [
                'cc' => $cc,
                'notification_user_id' => (int) $primary['user_id'],
                'notification_preference_keys' => $preferenceKeys,
            ]);
        } catch (Throwable $e) {
            // Une panne SMTP ne doit pas interrompre la supervision de la badgeuse.
        }
    }

    /**
     * @return array<int, array{user_id:int,email:string,name:string}>
     */
    private function listAttendanceOfflineRecipients(): array
    {
        try {
            $rows = $this->pdo->query("
                SELECT DISTINCT u.id, u.prenom, u.nom, u.mail_pro
                FROM user_devis_banamur u
                LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE u.active = 1
                  AND (
                    COALESCE(u.acces_direction, 0) = 1
                    OR COALESCE(u.acces_rh, 0) = 1
                    OR LOWER(COALESCE(s.lib_service, '')) LIKE '%direction generale%'
                    OR LOWER(COALESCE(s.lib_service, '')) LIKE '%direction générale%'
                    OR LOWER(COALESCE(s.lib_service, '')) LIKE '%ressources humaines%'
                    OR LOWER(COALESCE(s.lib_service, '')) LIKE '%ressource humaine%'
                    OR LOWER(COALESCE(s.lib_service, '')) LIKE '%informatique%'
                  )
                ORDER BY u.id ASC
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $recipients = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $email = trim((string) ($row['mail_pro'] ?? ''));
            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $recipients[$userId] = [
                'user_id' => $userId,
                'email' => $email,
                'name' => $name !== '' ? $name : ($email !== '' ? $email : 'Utilisateur #' . $userId),
            ];
        }

        return array_values($recipients);
    }

    public function notifyBrokenDebourseLinksDetected(int $brokenCount, array $context = []): void
    {
        $brokenCount = (int) $brokenCount;
        if ($brokenCount <= 0) {
            return;
        }

        $this->ensureDefaultRulesForBrokenDebourseLinksDetected();

        $subKey = 'alert';
        $rule = $this->getRule(self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED, $subKey);
        if (!$rule['is_enabled']) {
            return;
        }

        $chantierCode = trim((string) ($context['chantier_code'] ?? ''));
        $chantierTitre = trim((string) ($context['chantier_titre'] ?? ''));
        $chantierId = (int) ($context['chantier_id'] ?? 0);
        $search = trim((string) ($context['search'] ?? ''));
        $manualOnly = !empty($context['manual_only']);
        $requesterUserId = (int) ($context['requester_user_id'] ?? 0);

        $chantierLabel = '';
        if ($chantierCode !== '' || $chantierTitre !== '') {
            $chantierLabel = ' sur ' . trim($chantierCode . ($chantierTitre !== '' ? (' - ' . $chantierTitre) : ''));
        }

        $payload = [
            'broken_count' => $brokenCount,
            'chantier_id' => $chantierId > 0 ? $chantierId : null,
            'chantier_code' => $chantierCode !== '' ? $chantierCode : null,
            'chantier_titre' => $chantierTitre !== '' ? $chantierTitre : null,
            'search' => $search !== '' ? $search : null,
            'manual_only' => $manualOnly ? 1 : 0,
        ];

        $fingerprint = sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($this->hasRecentNotificationFingerprint(self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED, $subKey, $fingerprint, 24)) {
            return;
        }
        $payload['fingerprint'] = $fingerprint;

        $templateContext = $this->makeBasicContext([
            'broken_count' => (string) $brokenCount,
            'chantier_code' => $chantierCode,
            'chantier_titre' => $chantierTitre,
            'chantier_label' => $chantierLabel,
            'search' => $search,
        ]);

        $titleTpl = trim((string) ($rule['title_template'] ?? ''));
        if ($titleTpl === '') {
            $titleTpl = trim((string) ($rule['label'] ?? ''));
        }
        $title = trim($this->renderTemplate($titleTpl, $templateContext));
        if ($title === '') {
            $title = 'Liens casses detectes';
        }

        $messageTpl = trim((string) ($rule['message_template'] ?? ''));
        $message = $messageTpl !== '' ? trim($this->renderTemplate($messageTpl, $templateContext)) : '';
        if ($message === '') {
            $message = $brokenCount . ' fiche(s) presentent un lien debourse invalide' . $chantierLabel . '.';
        }

        $query = [];
        if ($chantierId > 0) {
            $query['chantier_id'] = $chantierId;
        }
        if ($search !== '') {
            $query['search'] = $search;
        }
        $query['manual_only'] = $manualOnly ? '1' : '0';

        $link = '/admin/relink_fiches_debourse.php';
        $queryString = http_build_query($query);
        if ($queryString !== '') {
            $link .= '?' . $queryString;
        }

        $channels = (array) ($rule['channels'] ?? ['system']);
        $recipientTeam = null;
        $recipientUser = null;
        $target = (string) ($rule['recipient_team'] ?? self::TEAM_FINANCE);
        if ($target === self::RECIPIENT_REQUESTER) {
            if ($requesterUserId <= 0) {
                return;
            }
            $recipientUser = $requesterUserId;
        } else {
            $recipientTeam = $target;
        }

        $notifId = $this->createNotification(
            self::EVENT_BROKEN_DEBOURSE_LINKS_DETECTED,
            $subKey,
            $title,
            $message,
            $link,
            $recipientTeam,
            $recipientUser,
            $channels,
            $payload
        );

        if ($notifId > 0 && in_array('email', $channels, true)) {
            $this->sendConfiguredRuleEmail($rule, $recipientUser, $recipientTeam, $title, $message, $link);
        }
    }

    private function sendConfiguredRuleEmail(array $rule, ?int $recipientUserId, ?string $recipientTeam, string $subject, string $message, string $link): void
    {
        $excludedEmails = $this->extractExcludedEmailsFromRule($rule);
        $preferenceKeys = $this->notificationPreferenceKeysFromRule($rule);

        if ($recipientUserId !== null && $recipientUserId > 0) {
            $this->sendEmailForUserId($recipientUserId, $subject, $message, $link, $excludedEmails, $preferenceKeys);
            return;
        }

        if ($recipientTeam !== null && trim($recipientTeam) !== '') {
            $this->sendEmailForTeam($recipientTeam, $subject, $message, $link, $excludedEmails, $preferenceKeys);
        }
    }

    private function sendEmailForTeam(string $team, string $subject, string $message, string $link, array $excludedEmails = [], array $preferenceKeys = []): void
    {
        if (!class_exists('Mailer')) {
            $mailerPath = __DIR__ . '/Mailer.php';
            if (is_file($mailerPath)) {
                require_once $mailerPath;
            }
        }

        if (!class_exists('Mailer')) {
            return;
        }

        $team = strtolower(trim($team));

        $recipients = [];
        if ($team === self::TEAM_RH) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_rh');
        } elseif ($team === self::TEAM_ADMIN) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_admin');
        } elseif ($team === self::TEAM_FINANCE) {
            $recipients = $this->listActiveUsersByPermissionColumns(['acces_compta', 'acces_point_financier', 'acces_relink_fiches_debourse']);
        } elseif ($team === self::TEAM_LOGISTIQUE) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_logistique');
        } elseif ($team === self::TEAM_HSE) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_hse');
        } elseif ($team === self::TEAM_ALL) {
            $recipients = $this->listActiveUsersAll();
        }

        if (!$recipients) {
            return;
        }

        $html = $this->buildNotificationEmailHtml($subject, $message, $link);
        $plain = $this->buildNotificationEmailPlainText($subject, $message, $link);

        foreach ($recipients as $r) {
            $to = (string) ($r['email'] ?? '');
            if ($to === '' || $this->isEmailExcluded($to, $excludedEmails)) {
                continue;
            }
            if (!$this->isEmailAllowedByPreferences((int) ($r['user_id'] ?? 0), $preferenceKeys)) {
                continue;
            }
            $name = (string) ($r['name'] ?? $to);
            try {
                $this->emailChannel->send($to, $name, $subject, $html, $plain, [
                    'notification_user_id' => (int) ($r['user_id'] ?? 0),
                    'notification_preference_keys' => $preferenceKeys,
                ]);
            } catch (Throwable $e) {
                // Ne pas bloquer le workflow si l'email echoue.
            }
        }
    }

    private function sendEmailForUserId(int $userId, string $subject, string $message, string $link, array $excludedEmails = [], array $preferenceKeys = []): void
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        if (!class_exists('Mailer')) {
            $mailerPath = __DIR__ . '/Mailer.php';
            if (is_file($mailerPath)) {
                require_once $mailerPath;
            }
        }

        if (!class_exists('Mailer')) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id AND active = 1 LIMIT 1');
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return;
        }

        if (!$row) {
            return;
        }

        if (!$this->isEmailAllowedByPreferences($userId, $preferenceKeys)) {
            return;
        }

        $to = trim((string) ($row['mail_pro'] ?? ''));
        if ($to === '' || $this->isEmailExcluded($to, $excludedEmails)) {
            return;
        }

        $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
        if ($name === '') {
            $name = $to;
        }

        $html = $this->buildNotificationEmailHtml($subject, $message, $link);
        $plain = $this->buildNotificationEmailPlainText($subject, $message, $link);

        try {
            $this->emailChannel->send($to, $name, $subject, $html, $plain, [
                'notification_user_id' => $userId,
                'notification_preference_keys' => $preferenceKeys,
            ]);
        } catch (Throwable $e) {
            // Ne pas bloquer le workflow si l'email échoue.
        }
    }

    /**
     * @return array<int, string>
     */
    private function notificationPreferenceKeysFromRule(array $rule): array
    {
        $eventKey = strtolower(trim((string) ($rule['event_key'] ?? '')));
        $subKey = strtolower(trim((string) ($rule['sub_key'] ?? '')));
        $keys = [];

        if ($eventKey !== '' && $subKey !== '') {
            $keys[] = $eventKey . '.' . $subKey;
        }
        if ($eventKey !== '') {
            $keys[] = $eventKey;
            $family = $this->notificationFamilyPreferenceKey($eventKey);
            if ($family !== '') {
                $keys[] = $family;
            }
        }

        return array_values(array_unique($keys));
    }

    private function notificationFamilyPreferenceKey(string $eventKey): string
    {
        $eventKey = strtolower(trim($eventKey));
        if (in_array($eventKey, ['legal_request_submitted', 'legal_request_processed'], true)) {
            return 'legal_requests';
        }
        if (in_array($eventKey, ['decaissement_submitted', 'decaissement_processed', 'broken_debourse_links_detected'], true)) {
            return 'finance_decaissement';
        }
        if (in_array($eventKey, ['schedule_occurrence_created', 'schedule_task_assigned', 'schedule_task_changed'], true)) {
            return 'planning_operations';
        }
        if (in_array($eventKey, ['employee_absence_marked', 'employee_absence_reminder_24h', 'employee_absence_threshold_3'], true)) {
            return 'employee_absences';
        }
        if ($eventKey === 'rh_hierarchy_assigned') {
            return 'rh_management';
        }
        if ($eventKey === 'supplier_workflow') {
            return 'supplier_portal';
        }
        if (in_array($eventKey, ['logistics_request', 'logistics_asset'], true)) {
            return 'logistics_operations';
        }
        if ($eventKey === 'attendance_device_alert') {
            return 'attendance_devices';
        }
        if (in_array($eventKey, ['communication_message_sent', 'communication_task_assigned', 'communication_task_changed', 'document_share_created'], true)) {
            return 'mailbox';
        }

        return '';
    }

    /**
     * @param array<int, string> $preferenceKeys
     */
    private function isEmailAllowedByPreferences(int $userId, array $preferenceKeys): bool
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        $preferenceKeys = array_values(array_unique(array_filter(array_map(static fn($key): string => strtolower(trim((string) $key)), $preferenceKeys), static fn(string $key): bool => $key !== '')));
        if ($preferenceKeys === []) {
            return true;
        }

        try {
            $placeholders = [];
            $params = ['user_id' => $userId];
            foreach ($preferenceKeys as $index => $key) {
                $param = 'key' . $index;
                $placeholders[] = ':' . $param;
                $params[$param] = $key;
            }

            $stmt = $this->pdo->prepare('
                SELECT event_key, channel_email
                FROM user_notification_preferences
                WHERE user_id = :user_id
                  AND event_key IN (' . implode(',', $placeholders) . ')
            ');
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return true;
        }

        $values = [];
        foreach ($rows as $row) {
            $values[strtolower((string) ($row['event_key'] ?? ''))] = (int) ($row['channel_email'] ?? 0) === 1;
        }

        foreach ($preferenceKeys as $key) {
            if (array_key_exists($key, $values) && $values[$key] === false) {
                return false;
            }
        }

        foreach ($preferenceKeys as $key) {
            if (array_key_exists($key, $values)) {
                return true;
            }
        }

        if (in_array('legal_request_submitted.feb', $preferenceKeys, true) && $this->userHasDirectionAccess($userId)) {
            return false;
        }

        return true;
    }

    private function userHasDirectionAccess(int $userId): bool
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT acces_direction FROM user_devis_banamur WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $userId]);
            return (int) ($stmt->fetchColumn() ?: 0) === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function sendWhatsAppForUserId(int $userId, string $title, string $message, string $link): void
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return;
        }

        $phone = $this->getUserWhatsAppNumber($userId);
        if ($phone === null || $phone === '') {
            return;
        }

        $baseUrl = trim((string) \EnvConfig::get('APP_BASE_URL', ''));
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $baseUrl = $scheme . '://' . $host . $this->resolveApplicationBasePath();
        }

        $url = '';
        if (trim($link) !== '') {
            $url = (preg_match('#^https?://#i', $link) === 1) ? $link : ($baseUrl . $link);
        }

        $text = "*" . $this->truncateText($title, 80) . "*\n" . $this->truncateText($message, 400);
        if ($url !== '') {
            $text .= "\n" . $url;
        }

        $this->whatsAppChannel->send($phone, $text);
    }

    private function getUserDisplayName(int $userId): ?string
    {
        try {
            $stmt = $this->pdo->prepare('SELECT prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => (int) $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        $email = trim((string) ($row['mail_pro'] ?? ''));
        return $email !== '' ? $email : null;
    }

    private function getUserWhatsAppNumber(int $userId): ?string
    {
        // Stratégie: récupérer le téléphone depuis la fiche personnel liée (user_devis_banamur.personnel_id -> personnel.tel_personnel_soignant).
        // L'existence des colonnes peut varier selon les bases, on échoue silencieusement.

        $rawPhone = '';

        // 1) Essai via personnel_id
        try {
            $stmt = $this->pdo->prepare('
                SELECT p.tel_personnel_soignant AS phone
                FROM user_devis_banamur u
                INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                WHERE u.id = :id
                LIMIT 1
            ');
            $stmt->execute(['id' => (int) $userId]);
            $rawPhone = (string) ($stmt->fetchColumn() ?: '');
        } catch (Throwable $e) {
            $rawPhone = '';
        }

        $rawPhone = trim($rawPhone);
        if ($rawPhone === '') {
            return null;
        }

        return $this->normalizeWhatsAppNumber($rawPhone);
    }

    private function normalizeWhatsAppNumber(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Conserver uniquement les chiffres
        $digits = preg_replace('/\D+/', '', $raw);
        $digits = is_string($digits) ? $digits : '';
        if ($digits === '') {
            return null;
        }

        // Côte d'Ivoire: on tolère 8/10 chiffres locaux; sinon on considère déjà international.
        if (strlen($digits) === 8) {
            $digits = '225' . $digits;
        } elseif (strlen($digits) === 10 && strpos($digits, '225') !== 0) {
            $digits = '225' . $digits;
        }

        if (strpos($digits, '00') === 0) {
            $digits = substr($digits, 2);
        }

        if ($digits === '') {
            return null;
        }

        return '+' . $digits;
    }

    private function truncateText(string $text, int $maxLen): string
    {
        $maxLen = max(10, (int) $maxLen);
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text) <= $maxLen) {
                return $text;
            }
            return rtrim(mb_substr($text, 0, $maxLen - 1)) . '…';
        }
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return rtrim(substr($text, 0, $maxLen - 1)) . '…';
    }

    private function extractExcludedEmailsFromRule(array $rule): array
    {
        return $this->normalizeExcludedEmails((array) ($rule['excluded_emails'] ?? []));
    }

    private function decodeExcludedEmails($rawValue): array
    {
        if (is_string($rawValue) && trim($rawValue) !== '') {
            $decoded = json_decode($rawValue, true);
            if (is_array($decoded)) {
                return $this->normalizeExcludedEmails($decoded);
            }
        }

        return [];
    }

    private function normalizeExcludedEmails(array $emails): array
    {
        $normalized = [];
        foreach ($emails as $email) {
            $candidate = strtolower(trim((string) $email));
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    private function isEmailExcluded(string $email, array $excludedEmails): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        return in_array($email, $this->normalizeExcludedEmails($excludedEmails), true);
    }

    /**
     * @return array<int, array{user_id:int,email:string,name:string}>
     */
    private function listActiveUsersAll(): array
    {
        try {
            $excludedStatusCodes = self::EXCLUDED_TEAM_ALL_STATUS_CODES;
            $placeholders = implode(',', array_fill(0, count($excludedStatusCodes), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT u.id, u.prenom, u.nom, u.mail_pro
                 FROM user_devis_banamur u
                 LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                 WHERE u.active = 1
                   AND (p.id_personnel_soignant IS NULL OR p.statut_pers_soignant_code NOT IN (' . $placeholders . '))'
            );
            $stmt->execute($excludedStatusCodes);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $email = trim((string) ($row['mail_pro'] ?? ''));
            if ($id <= 0 || $email === '') {
                continue;
            }
            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $out[] = ['user_id' => $id, 'email' => $email, 'name' => $name !== '' ? $name : $email];
        }

        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function listActiveUserIdsForTeam(string $team): array
    {
        $team = strtolower(trim($team));
        $recipients = [];
        if ($team === self::TEAM_RH) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_rh');
        } elseif ($team === self::TEAM_ADMIN) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_admin');
        } elseif ($team === self::TEAM_FINANCE) {
            $recipients = $this->listActiveUsersByPermissionColumns(['acces_compta', 'acces_point_financier', 'acces_relink_fiches_debourse']);
        } elseif ($team === self::TEAM_LOGISTIQUE) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_logistique');
        } elseif ($team === self::TEAM_HSE) {
            $recipients = $this->listActiveUsersByPermissionColumn('acces_hse');
        } elseif ($team === self::TEAM_ALL) {
            $recipients = $this->listActiveUsersAll();
        }

        $userIds = array_map(static function (array $recipient): int {
            return (int)($recipient['user_id'] ?? 0);
        }, $recipients);

        $userIds = array_filter($userIds, static function (int $userId): bool {
            return $userId > 0;
        });

        return array_values(array_unique($userIds));
    }

    /**
     * @param array<int,string> $channels
     * @param array<string,mixed> $payload
     * @param array<int,int> $excludedUserIds
     */
    private function dispatchCommunicationPushRecipients(string $eventKey, ?string $subKey, string $title, string $message, string $link, array $channels, array $payload, array $excludedUserIds = [], int $actorUserId = 0): void
    {
        if (!in_array('system', $channels, true)) {
            return;
        }

        $recipientUserIds = $this->getCommunicationPushRecipientUserIds();
        if ($recipientUserIds === []) {
            return;
        }

        $excludedUserIds = array_map('intval', $excludedUserIds);
        $excludedUserIds = array_filter($excludedUserIds, static function (int $userId): bool {
            return $userId > 0;
        });
        $excludedUserIds = array_values(array_unique($excludedUserIds));
        if ($actorUserId > 0) {
            $excludedUserIds[] = $actorUserId;
            $excludedUserIds = array_values(array_unique($excludedUserIds));
        }

        foreach ($recipientUserIds as $recipientUserId) {
            if (in_array($recipientUserId, $excludedUserIds, true)) {
                continue;
            }

            try {
                $this->createNotification($eventKey, $subKey, $title, $message, $link, null, $recipientUserId, ['system'], array_merge($payload, [
                    'admin_push_recipient' => true,
                    'admin_push_scope' => self::COMMUNICATION_PUSH_SCOPE_TASKS,
                ]));
            } catch (Throwable $e) {
                // Best effort.
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function getCommunicationPushRecipientUserIds(): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT user_id FROM communication_push_recipients WHERE scope_key = :scope_key ORDER BY user_id ASC');
            $stmt->execute(['scope_key' => self::COMMUNICATION_PUSH_SCOPE_TASKS]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $userIds = array_map('intval', $rows);
        $userIds = array_filter($userIds, static function (int $userId): bool {
            return $userId > 0;
        });

        return array_values(array_unique($userIds));
    }

    /**
     * @return array<int, array{user_id:int,email:string,name:string}>
     */
    private function listActiveUsersByPermissionColumn(string $permissionColumn): array
    {
        $permissionColumn = preg_replace('/[^a-z0-9_]/i', '', $permissionColumn);
        if ($permissionColumn === '') {
            return [];
        }

        $sql = 'SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE active = 1 AND ' . $permissionColumn . ' = 1';
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $email = trim((string) ($row['mail_pro'] ?? ''));
            if ($id <= 0 || $email === '') {
                continue;
            }
            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $out[] = ['user_id' => $id, 'email' => $email, 'name' => $name !== '' ? $name : $email];
        }

        return $out;
    }

    /**
     * @param array<int, string> $permissionColumns
     * @return array<int, array{user_id:int,email:string,name:string}>
     */
    private function listActiveUsersByPermissionColumns(array $permissionColumns): array
    {
        $permissionColumns = array_values(array_unique(array_filter(array_map(static function (string $c): string {
            return preg_replace('/[^a-z0-9_]/i', '', $c);
        }, $permissionColumns), static fn($c) => $c !== '')));

        if (!$permissionColumns) {
            return [];
        }

        $conditions = [];
        foreach ($permissionColumns as $c) {
            $conditions[] = $c . ' = 1';
        }

        $sql = 'SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE active = 1 AND (' . implode(' OR ', $conditions) . ')';
        try {
            $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $email = trim((string) ($row['mail_pro'] ?? ''));
            if ($id <= 0 || $email === '') {
                continue;
            }
            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $out[] = ['user_id' => $id, 'email' => $email, 'name' => $name !== '' ? $name : $email];
        }

        return $out;
    }

    private function createNotification(
        string $eventKey,
        ?string $subKey,
        string $title,
        string $message,
        string $linkUrl,
        ?string $recipientTeam,
        ?int $recipientUserId,
        array $channels,
        array $payload
    ): int {
        $eventKey = strtolower(trim($eventKey));
        $subKey = $subKey !== null ? strtolower(trim($subKey)) : null;
        $title = trim($title);
        $message = trim($message);
        $linkUrl = trim($linkUrl);

        $recipientTeam = $recipientTeam !== null ? strtolower(trim($recipientTeam)) : null;
        if ($recipientTeam !== null && !in_array($recipientTeam, self::getKnownTeams(), true)) {
            $recipientTeam = null;
        }

        $channels = array_values(array_unique(array_filter(array_map('strtolower', array_map('strval', $channels)), static fn($v) => $v !== '')));
        if (!$channels) {
            $channels = ['system'];
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO system_notifications (
                created_at, event_key, sub_key, title, message, link_url,
                recipient_team, recipient_user_id,
                channels_json, payload_json
            ) VALUES (
                NOW(), :ek, :sk, :title, :msg, :link,
                :team, :uid,
                :channels, :payload
            )
        ');

        $stmt->execute([
            'ek' => $eventKey,
            'sk' => $subKey,
            'title' => $title,
            'msg' => $message !== '' ? $message : null,
            'link' => $linkUrl !== '' ? $linkUrl : null,
            'team' => $recipientTeam,
            'uid' => $recipientUserId !== null && $recipientUserId > 0 ? (int) $recipientUserId : null,
            'channels' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function hasRecentNotificationFingerprint(string $eventKey, ?string $subKey, string $fingerprint, int $hours = 24): bool
    {
        $hours = max(1, (int) $hours);
        $cutoff = date('Y-m-d H:i:s', time() - ($hours * 3600));

        try {
            $stmt = $this->pdo->prepare('
                SELECT created_at, payload_json
                FROM system_notifications
                WHERE event_key = :ek
                  AND ((sub_key IS NULL AND :sk IS NULL) OR sub_key = :sk)
                  AND created_at >= :cutoff
                ORDER BY id DESC
                LIMIT 25
            ');
            $stmt->bindValue(':ek', strtolower(trim($eventKey)), PDO::PARAM_STR);
            $stmt->bindValue(':sk', $subKey !== null ? strtolower(trim($subKey)) : null, $subKey !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return false;
        }

        foreach ($rows as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            if (is_array($payload) && (string) ($payload['fingerprint'] ?? '') === $fingerprint) {
                return true;
            }
        }

        return false;
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

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS communication_push_recipients (
                scope_key VARCHAR(64) NOT NULL,
                user_id INT NOT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (scope_key, user_id),
                INDEX idx_communication_push_recipient_user (user_id)
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

        $cols = [
            'title_template' => 'ALTER TABLE system_notification_rules ADD COLUMN title_template VARCHAR(255) NULL',
            'message_template' => 'ALTER TABLE system_notification_rules ADD COLUMN message_template TEXT NULL',
            'excluded_emails_json' => 'ALTER TABLE system_notification_rules ADD COLUMN excluded_emails_json TEXT NULL',
        ];

        foreach ($cols as $col => $sql) {
            try {
                $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND COLUMN_NAME = :c');
                $stmt->execute(['db' => $db, 't' => 'system_notification_rules', 'c' => $col]);
                $exists = (int) $stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                continue;
            }

            if ($exists) {
                continue;
            }

            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function normalizeTeams(array $teams): array
    {
        $teams = array_values(array_unique(array_filter(array_map(static function ($t): string {
            return strtolower(trim((string) $t));
        }, $teams), static fn($t) => $t !== '')));

        $known = array_flip(self::getKnownTeams());
        $teams = array_values(array_filter($teams, static fn($t) => isset($known[$t])));

        return $teams;
    }
}

