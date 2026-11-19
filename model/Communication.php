<?php

require_once __DIR__ . '/EmployeeSchedule.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

class Communication
{
    private const EXCLUDED_TEAM_SCOPE_STATUS_CODES = [2];
    private const SCHEDULE_SYNC_SOFT_ERRORS = [
        'Le collaborateur assigne doit avoir un email professionnel pour etre place dans l\'emploi du temps.',
        'Aucun profil personnel lie a cet email dans l\'emploi du temps.',
    ];

    private const LEGACY_TABLES = [
        'comm_message_reads',
        'comm_message_attachments',
        'comm_message_recipients',
        'comm_typing_status',
        'comm_messages',
    ];

    public static function ensureTables(PDO $pdo): void
    {
        self::cleanupLegacySchema($pdo);
        (new \EmployeeSchedule($pdo))->isReady();

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comm_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                assignee_user_id INT NOT NULL,
                created_by_user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                deadline DATETIME NOT NULL,
                duration_minutes INT NOT NULL DEFAULT 60,
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                status VARCHAR(20) NOT NULL DEFAULT 'todo',
                completed_at DATETIME NULL,
                archived_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_comm_tasks_assignee (assignee_user_id, status, deadline),
                INDEX idx_comm_tasks_creator (created_by_user_id, created_at),
                INDEX idx_comm_tasks_status (status, deadline),
                INDEX idx_comm_tasks_deadline (deadline)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        self::ensureTaskColumns($pdo);

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comm_task_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                author_user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_comm_task_comments_task (task_id, created_at),
                CONSTRAINT fk_comm_task_comments_task FOREIGN KEY (task_id) REFERENCES comm_tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comm_task_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                uploaded_by_user_id INT NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_comm_task_attachments_task (task_id, created_at),
                CONSTRAINT fk_comm_task_attachments_task FOREIGN KEY (task_id) REFERENCES comm_tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comm_task_user_preferences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                task_id INT NOT NULL,
                is_pinned TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_comm_task_user_preferences (user_id, task_id),
                KEY idx_comm_task_user_preferences_sort (user_id, is_pinned, sort_order),
                CONSTRAINT fk_comm_task_user_preferences_task FOREIGN KEY (task_id) REFERENCES comm_tasks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    private static function ensureTaskColumns(PDO $pdo): void
    {
        $columns = [];
        $stmt = $pdo->query('SHOW COLUMNS FROM comm_tasks');
        foreach ($stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $field = strtolower((string)($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }

        $alter = [];
        if (!isset($columns['duration_minutes'])) {
            $alter[] = 'ADD COLUMN duration_minutes INT NOT NULL DEFAULT 60 AFTER deadline';
        }
        if (!isset($columns['event_group_key'])) {
            $alter[] = 'ADD COLUMN event_group_key VARCHAR(64) NULL AFTER archived_at';
        }
        if (!isset($columns['group_scope'])) {
            $alter[] = 'ADD COLUMN group_scope VARCHAR(20) NULL AFTER event_group_key';
        }
        if (!isset($columns['group_scope_label'])) {
            $alter[] = 'ADD COLUMN group_scope_label VARCHAR(190) NULL AFTER group_scope';
        }

        if ($alter) {
            $pdo->exec('ALTER TABLE comm_tasks ' . implode(', ', $alter));
        }
    }

    private static function cleanupLegacySchema(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        foreach (self::LEGACY_TABLES as $table) {
            try {
                $pdo->exec('DROP TABLE IF EXISTS ' . $table);
            } catch (Throwable $e) {
            }
        }
    }

    public static function getPriorities(): array
    {
        return [
            'low' => 'Faible',
            'normal' => 'Normale',
            'high' => 'Haute',
            'urgent' => 'Urgente',
        ];
    }

    public static function getStatuses(): array
    {
        return [
            'todo' => 'À faire',
            'in_progress' => 'En cours',
            'done' => 'Terminée',
            'blocked' => 'Bloquée',
            'cancelled' => 'Annulée',
        ];
    }

    public static function listUsers(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT
                u.id,
                COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                p.id_personnel_soignant AS personnel_id,
                p.statut_pers_soignant_code AS status_code,
                p.service_id AS service_id,
                COALESCE(s.lib_service, '') AS service_label
            FROM user_devis_banamur u
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            WHERE u.active = 1
            ORDER BY u.prenom ASC, u.nom ASC, u.id ASC"
        );

        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as &$row) {
            $display = trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? ''));
            if ($display === '') {
                $display = trim((string)($row['mail_pro'] ?? ''));
            }
            if ($display === '') {
                $display = 'Utilisateur #' . (int)($row['id'] ?? 0);
            }
            $row['display'] = $display;
            $row['service_id'] = (int)($row['service_id'] ?? 0);
            $row['personnel_id'] = (int)($row['personnel_id'] ?? 0);
            $row['status_code'] = (int)($row['status_code'] ?? 0);
        }
        unset($row);

        return $rows;
    }

    public static function getTaskDashboard(PDO $pdo, int $viewerUserId): array
    {
        $stmt = $pdo->query(
            "SELECT
                COUNT(*) AS total_tasks,
                SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) AS todo_tasks,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress_tasks,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done_tasks,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_tasks,
                SUM(CASE WHEN status NOT IN ('done', 'cancelled') AND deadline < NOW() THEN 1 ELSE 0 END) AS late_tasks
            FROM comm_tasks
            WHERE archived_at IS NULL"
        );
        $stats = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $topStmt = $pdo->query(
            "SELECT
                t.assignee_user_id,
                COUNT(*) AS total,
                COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro
            FROM comm_tasks t
            INNER JOIN user_devis_banamur u ON u.id = t.assignee_user_id
            WHERE t.archived_at IS NULL
            GROUP BY t.assignee_user_id, u.prenom, u.nom, u.mail_pro
            ORDER BY total DESC, u.prenom ASC, u.nom ASC
            LIMIT 8"
        );
        $topAssignees = $topStmt ? ($topStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($topAssignees as &$assignee) {
            $display = trim((string)($assignee['prenom'] ?? '') . ' ' . (string)($assignee['nom'] ?? ''));
            if ($display === '') {
                $display = trim((string)($assignee['mail_pro'] ?? ''));
            }
            if ($display === '') {
                $display = 'Utilisateur #' . (int)($assignee['assignee_user_id'] ?? 0);
            }
            $assignee['display'] = $display;
        }
        unset($assignee);

        return [
            'stats' => [
                'total_tasks' => (int)($stats['total_tasks'] ?? 0),
                'todo_tasks' => (int)($stats['todo_tasks'] ?? 0),
                'in_progress_tasks' => (int)($stats['in_progress_tasks'] ?? 0),
                'done_tasks' => (int)($stats['done_tasks'] ?? 0),
                'cancelled_tasks' => (int)($stats['cancelled_tasks'] ?? 0),
                'late_tasks' => (int)($stats['late_tasks'] ?? 0),
            ],
            'top_assignees' => $topAssignees,
            'viewer_user_id' => $viewerUserId,
        ];
    }

    public static function createTasksForTargets(PDO $pdo, int $creatorUserId, array $payload, array $attachments = [], bool $syncSchedule = true): array
    {
        $assigneeUserIds = self::resolveTargetAssigneeUserIds($pdo, $payload);
        if ($assigneeUserIds === []) {
            return ['ok' => false, 'error' => 'Aucun collaborateur actif ne correspond au ciblage choisi.'];
        }

        if (count($assigneeUserIds) > 1) {
            $payload['event_group_key'] = trim((string)($payload['event_group_key'] ?? '')) !== ''
                ? trim((string)$payload['event_group_key'])
                : ('comm-group-' . bin2hex(random_bytes(8)));
            $payload['group_scope'] = trim((string)($payload['target_scope'] ?? 'selected'));
            $payload['group_scope_label'] = trim((string)($payload['target_scope_label'] ?? '')) !== ''
                ? trim((string)$payload['target_scope_label'])
                : self::defaultGroupScopeLabel((string)($payload['group_scope'] ?? 'selected'));
        }

        if (count($assigneeUserIds) === 1) {
            $singlePayload = $payload;
            $singlePayload['assignee_user_id'] = $assigneeUserIds[0];
            return self::createTask($pdo, $creatorUserId, $singlePayload, $attachments, $syncSchedule);
        }

        $duplicatedStoredPaths = [];
        $createdTaskIds = [];
        $scheduleWarningNames = [];
        $pendingCalendarInvites = [];

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            foreach ($assigneeUserIds as $index => $assigneeUserId) {
                $taskPayload = $payload;
                $taskPayload['assignee_user_id'] = $assigneeUserId;
                $taskAttachments = $index === 0 ? $attachments : self::duplicateStoredAttachments($attachments, $duplicatedStoredPaths);
                $created = self::createTask($pdo, $creatorUserId, $taskPayload, $taskAttachments, $syncSchedule);
                if (empty($created['ok'])) {
                    throw new RuntimeException((string)($created['error'] ?? 'Creation groupee impossible.'));
                }
                $createdTaskIds[] = (int)($created['task_id'] ?? 0);
                if (!empty($created['schedule_sync_skipped'])) {
                    foreach ((array)($created['warning_users'] ?? []) as $warningUser) {
                        $warningUser = trim((string)$warningUser);
                        if ($warningUser !== '') {
                            $scheduleWarningNames[$warningUser] = true;
                        }
                    }
                }
                foreach ((array)($created['calendar_invites'] ?? []) as $calendarInvite) {
                    if (is_array($calendarInvite)) {
                        $pendingCalendarInvites[] = $calendarInvite;
                    }
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $recipientCount = self::countGroupedRecipientUsers($createdTaskIds ? self::listTasksByIds($pdo, $createdTaskIds) : []);
            self::notifyGroupedTaskAssigned($pdo, self::listTasksByIds($pdo, $createdTaskIds), $creatorUserId, [
                'title' => trim((string)($payload['title'] ?? '')),
                'deadline' => self::normalizeDeadlineForNotification((string)($payload['deadline'] ?? '')),
            ]);
            $calendarInviteResult = self::dispatchTaskCalendarInvites($pdo, $pendingCalendarInvites);

            return [
                'ok' => true,
                'task_id' => $createdTaskIds[0] ?? 0,
                'task_ids' => $createdTaskIds,
                'created_count' => count($createdTaskIds),
                'applied_to_group' => true,
                'grouped_notification_emitted' => $recipientCount > 0,
                'grouped_notification_message' => $recipientCount > 0 ? ('Notification groupée envoyée à ' . $recipientCount . ' collaborateur(s).') : '',
                'schedule_sync_skipped' => $scheduleWarningNames !== [],
                'warning_users' => array_keys($scheduleWarningNames),
                'warning_message' => self::formatScheduleWarningMessage(array_keys($scheduleWarningNames)),
                'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::cleanupStoredFiles($duplicatedStoredPaths);
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function listTasks(PDO $pdo, array $filters = []): array
    {
        $search = trim((string)($filters['search'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $priority = trim((string)($filters['priority'] ?? ''));
        $assigneeUserId = (int)($filters['assignee_user_id'] ?? 0);
        $viewerUserId = (int)($filters['viewer_user_id'] ?? 0);
        $limit = max(1, min(300, (int)($filters['limit'] ?? 200)));

        $where = ['t.archived_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $where[] = '(t.title LIKE :search OR t.description LIKE :search OR assignee.prenom LIKE :search OR assignee.nom LIKE :search OR creator.prenom LIKE :search OR creator.nom LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($status !== '' && isset(self::getStatuses()[$status])) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }
        if ($priority !== '' && isset(self::getPriorities()[$priority])) {
            $where[] = 't.priority = :priority';
            $params['priority'] = $priority;
        }
        if ($assigneeUserId > 0) {
            $where[] = 't.assignee_user_id = :assignee_user_id';
            $params['assignee_user_id'] = $assigneeUserId;
        }

        $sql = "SELECT
                    t.id,
                    t.assignee_user_id,
                    t.created_by_user_id,
                    t.title,
                    t.description,
                    t.duration_minutes,
                    t.priority,
                    t.status,
                    COALESCE(t.event_group_key, '') AS event_group_key,
                    COALESCE(t.group_scope, '') AS group_scope,
                    COALESCE(t.group_scope_label, '') AS group_scope_label,
                    DATE_FORMAT(t.deadline, '%Y-%m-%dT%H:%i:%s') AS deadline_iso,
                    DATE_FORMAT(t.deadline, '%Y-%m-%d %H:%i:%s') AS deadline_raw,
                    DATE_FORMAT(t.created_at, '%Y-%m-%dT%H:%i:%s') AS created_at_iso,
                    DATE_FORMAT(t.updated_at, '%Y-%m-%dT%H:%i:%s') AS updated_at_iso,
                    DATE_FORMAT(t.completed_at, '%Y-%m-%dT%H:%i:%s') AS completed_at_iso,
                    COALESCE(pref.is_pinned, 0) AS is_pinned,
                    pref.sort_order,
                    COALESCE(NULLIF(TRIM(assignee.prenom), ''), '') AS assignee_prenom,
                    COALESCE(NULLIF(TRIM(assignee.nom), ''), '') AS assignee_nom,
                    COALESCE(NULLIF(TRIM(assignee.mail_pro), ''), '') AS assignee_mail_pro,
                    COALESCE(NULLIF(TRIM(creator.prenom), ''), '') AS creator_prenom,
                    COALESCE(NULLIF(TRIM(creator.nom), ''), '') AS creator_nom,
                    COALESCE(NULLIF(TRIM(creator.mail_pro), ''), '') AS creator_mail_pro,
                    ese.id AS schedule_event_id,
                    COALESCE(ese.status_code, '') AS schedule_status_code,
                    (SELECT COUNT(*) FROM comm_task_comments tc WHERE tc.task_id = t.id) AS comments_count,
                    (SELECT COUNT(*) FROM comm_task_attachments ta WHERE ta.task_id = t.id) AS attachments_count,
                    (CASE WHEN COALESCE(t.event_group_key, '') = '' THEN 0 ELSE (SELECT COUNT(*) FROM comm_tasks tg WHERE tg.event_group_key = t.event_group_key AND tg.archived_at IS NULL) END) AS grouped_task_count
                FROM comm_tasks t
                INNER JOIN user_devis_banamur assignee ON assignee.id = t.assignee_user_id
                INNER JOIN user_devis_banamur creator ON creator.id = t.created_by_user_id
                LEFT JOIN comm_task_user_preferences pref ON pref.task_id = t.id AND pref.user_id = :viewer_user_id
                LEFT JOIN employee_schedule_event ese ON ese.comm_task_id = t.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY COALESCE(pref.is_pinned, 0) DESC, COALESCE(pref.sort_order, 999999) ASC, t.deadline ASC, t.id DESC
                LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':viewer_user_id', $viewerUserId, PDO::PARAM_INT);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $taskIds = self::normalizePositiveIds(array_column($tasks, 'id'));
        $attachmentsByTask = self::mapAttachmentsByTaskIds($pdo, $taskIds, 4);
        $statuses = self::getStatuses();
        $priorities = self::getPriorities();

        foreach ($tasks as &$task) {
            $assigneeDisplay = trim((string)($task['assignee_prenom'] ?? '') . ' ' . (string)($task['assignee_nom'] ?? ''));
            $creatorDisplay = trim((string)($task['creator_prenom'] ?? '') . ' ' . (string)($task['creator_nom'] ?? ''));
            $task['assignee_display'] = $assigneeDisplay !== '' ? $assigneeDisplay : ((string)($task['assignee_mail_pro'] ?? '') !== '' ? (string)$task['assignee_mail_pro'] : ('Utilisateur #' . (int)($task['assignee_user_id'] ?? 0)));
            $task['creator_display'] = $creatorDisplay !== '' ? $creatorDisplay : ((string)($task['creator_mail_pro'] ?? '') !== '' ? (string)$task['creator_mail_pro'] : ('Utilisateur #' . (int)($task['created_by_user_id'] ?? 0)));
            $task['status_label'] = $statuses[(string)($task['status'] ?? '')] ?? (string)($task['status'] ?? '');
            $task['priority_label'] = $priorities[(string)($task['priority'] ?? '')] ?? (string)($task['priority'] ?? '');
            $task['is_late'] = (!empty($task['deadline_iso']) && !in_array((string)$task['status'], ['done', 'cancelled'], true) && strtotime((string)$task['deadline_iso']) < time());
            $task['is_pinned'] = !empty($task['is_pinned']);
            $task['duration_minutes'] = max(15, (int)($task['duration_minutes'] ?? 60));
            $task['grouped_task_count'] = (int)($task['grouped_task_count'] ?? 0);
            $task['schedule_status_label'] = self::mapScheduleStatusLabel((string)($task['schedule_status_code'] ?? ''));
            $task['attachments'] = $attachmentsByTask[(int)($task['id'] ?? 0)] ?? [];
        }
        unset($task);

        $summaryStmt = $pdo->prepare(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) AS todo,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS done,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
            FROM comm_tasks t
            WHERE " . implode(' AND ', $where)
        );
        foreach ($params as $name => $value) {
            $summaryStmt->bindValue(':' . $name, $value);
        }
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'tasks' => $tasks,
            'summary' => [
                'total' => (int)($summary['total'] ?? 0),
                'todo' => (int)($summary['todo'] ?? 0),
                'in_progress' => (int)($summary['in_progress'] ?? 0),
                'done' => (int)($summary['done'] ?? 0),
                'cancelled' => (int)($summary['cancelled'] ?? 0),
            ],
        ];
    }

    public static function listComments(PDO $pdo, array $filters = []): array
    {
        $taskId = (int)($filters['task_id'] ?? 0);
        $assigneeUserId = (int)($filters['assignee_user_id'] ?? 0);
        $search = trim((string)($filters['search'] ?? ''));
        $limit = max(1, min(250, (int)($filters['limit'] ?? 120)));

        $where = ['1 = 1'];
        $params = [];
        if ($taskId > 0) {
            $where[] = 'c.task_id = :task_id';
            $params['task_id'] = $taskId;
        }
        if ($assigneeUserId > 0) {
            $where[] = 't.assignee_user_id = :assignee_user_id';
            $params['assignee_user_id'] = $assigneeUserId;
        }
        if ($search !== '') {
            $where[] = '(c.body LIKE :search OR t.title LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = "SELECT
                    c.id,
                    c.task_id,
                    c.author_user_id,
                    c.body,
                    DATE_FORMAT(c.created_at, '%Y-%m-%dT%H:%i:%s') AS created_at_iso,
                    t.title AS task_title,
                    COALESCE(NULLIF(TRIM(author.prenom), ''), '') AS author_prenom,
                    COALESCE(NULLIF(TRIM(author.nom), ''), '') AS author_nom,
                    COALESCE(NULLIF(TRIM(author.mail_pro), ''), '') AS author_mail_pro
                FROM comm_task_comments c
                INNER JOIN comm_tasks t ON t.id = c.task_id
                INNER JOIN user_devis_banamur author ON author.id = c.author_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY c.created_at DESC
                LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($comments as &$comment) {
            $display = trim((string)($comment['author_prenom'] ?? '') . ' ' . (string)($comment['author_nom'] ?? ''));
            if ($display === '') {
                $display = trim((string)($comment['author_mail_pro'] ?? ''));
            }
            $comment['author_display'] = $display !== '' ? $display : ('Utilisateur #' . (int)($comment['author_user_id'] ?? 0));
        }
        unset($comment);

        return $comments;
    }

    public static function listAttachments(PDO $pdo, array $filters = []): array
    {
        $search = trim((string)($filters['search'] ?? ''));
        $taskId = (int)($filters['task_id'] ?? 0);
        $assigneeUserId = (int)($filters['assignee_user_id'] ?? 0);
        $limit = max(1, min(300, (int)($filters['limit'] ?? 150)));

        $where = ['1 = 1'];
        $params = [];
        if ($taskId > 0) {
            $where[] = 'a.task_id = :task_id';
            $params['task_id'] = $taskId;
        }
        if ($assigneeUserId > 0) {
            $where[] = 't.assignee_user_id = :assignee_user_id';
            $params['assignee_user_id'] = $assigneeUserId;
        }
        if ($search !== '') {
            $where[] = '(a.original_name LIKE :search OR t.title LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = "SELECT
                    a.id,
                    a.task_id,
                    a.original_name,
                    a.mime_type,
                    a.file_size,
                    a.stored_path,
                    DATE_FORMAT(a.created_at, '%Y-%m-%dT%H:%i:%s') AS created_at_iso,
                    t.title AS task_title,
                    t.status AS task_status,
                    COALESCE(NULLIF(TRIM(assignee.prenom), ''), '') AS assignee_prenom,
                    COALESCE(NULLIF(TRIM(assignee.nom), ''), '') AS assignee_nom,
                    COALESCE(NULLIF(TRIM(assignee.mail_pro), ''), '') AS assignee_mail_pro,
                    COALESCE(NULLIF(TRIM(uploader.prenom), ''), '') AS uploader_prenom,
                    COALESCE(NULLIF(TRIM(uploader.nom), ''), '') AS uploader_nom,
                    COALESCE(NULLIF(TRIM(uploader.mail_pro), ''), '') AS uploader_mail_pro
                FROM comm_task_attachments a
                INNER JOIN comm_tasks t ON t.id = a.task_id
                INNER JOIN user_devis_banamur assignee ON assignee.id = t.assignee_user_id
                INNER JOIN user_devis_banamur uploader ON uploader.id = a.uploaded_by_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC
                LIMIT :limit";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($attachments as &$attachment) {
            self::hydrateAttachmentOutput($attachment);
        }
        unset($attachment);

        return $attachments;
    }

    public static function createTask(PDO $pdo, int $creatorUserId, array $payload, array $attachments = [], bool $syncSchedule = true): array
    {
        $assigneeUserId = (int)($payload['assignee_user_id'] ?? 0);
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $deadline = trim((string)($payload['deadline'] ?? ''));
        $durationMinutes = max(15, (int)($payload['duration_minutes'] ?? 60));
        $priority = trim((string)($payload['priority'] ?? 'normal'));
        $status = trim((string)($payload['status'] ?? 'todo'));
        $eventGroupKey = trim((string)($payload['event_group_key'] ?? ''));
        $groupScope = trim((string)($payload['group_scope'] ?? ''));
        $groupScopeLabel = trim((string)($payload['group_scope_label'] ?? ''));

        if ($creatorUserId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }
        if ($assigneeUserId <= 0) {
            return ['ok' => false, 'error' => 'Selectionnez un collaborateur.'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre de la tache est obligatoire.'];
        }
        if ($deadline === '') {
            return ['ok' => false, 'error' => 'La deadline est obligatoire.'];
        }
        if (!isset(self::getPriorities()[$priority])) {
            $priority = 'normal';
        }
        if (!isset(self::getStatuses()[$status])) {
            $status = 'todo';
        }

        $deadlineSql = str_replace('T', ' ', $deadline);
        if (strlen($deadlineSql) === 16) {
            $deadlineSql .= ':00';
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $stmt = $pdo->prepare(
                "INSERT INTO comm_tasks (assignee_user_id, created_by_user_id, title, description, deadline, duration_minutes, priority, status, completed_at, event_group_key, group_scope, group_scope_label)
                 VALUES (:assignee_user_id, :created_by_user_id, :title, :description, :deadline, :duration_minutes, :priority, :status, :completed_at, :event_group_key, :group_scope, :group_scope_label)"
            );
            $stmt->execute([
                'assignee_user_id' => $assigneeUserId,
                'created_by_user_id' => $creatorUserId,
                'title' => mb_substr($title, 0, 255),
                'description' => $description !== '' ? $description : null,
                'deadline' => $deadlineSql,
                'duration_minutes' => $durationMinutes,
                'priority' => $priority,
                'status' => $status,
                'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null,
                'event_group_key' => $eventGroupKey !== '' ? $eventGroupKey : null,
                'group_scope' => $groupScope !== '' ? $groupScope : null,
                'group_scope_label' => $groupScopeLabel !== '' ? mb_substr($groupScopeLabel, 0, 190) : null,
            ]);
            $taskId = (int)$pdo->lastInsertId();

            if ($description !== '') {
                self::addComment($pdo, $taskId, $creatorUserId, 'Creation de la tache : ' . $description);
            }
            if ($attachments) {
                self::storeAttachments($pdo, $taskId, $creatorUserId, $attachments);
            }

            $scheduleWarning = null;
            if ($syncSchedule) {
                $scheduleWarning = self::trySyncTaskSchedule($pdo, $taskId, $creatorUserId);
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $createdTask = self::getTaskById($pdo, $taskId);
            $calendarInvites = $createdTask ? self::buildTaskCalendarInvites($createdTask, 'create') : [];
            $calendarInviteResult = $startedTransaction ? self::dispatchTaskCalendarInvites($pdo, $calendarInvites) : self::emptyCalendarInviteDispatchResult();

            $warningUsers = [];
            if ($scheduleWarning !== null) {
                $warningDisplay = self::resolveUserDisplayName($pdo, $assigneeUserId);
                if ($warningDisplay === '') {
                    $warningDisplay = 'Utilisateur #' . $assigneeUserId;
                }
                $warningUsers[] = $warningDisplay;
            }

            return [
                'ok' => true,
                'task_id' => $taskId,
                'schedule_sync_skipped' => $scheduleWarning !== null,
                'warning_users' => $warningUsers,
                'warning_message' => self::formatScheduleWarningMessage($warningUsers),
                'calendar_invites' => $calendarInvites,
                'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function updateTask(PDO $pdo, int $taskId, int $actorUserId, array $payload, array $attachments = [], bool $canManageAll = false, bool $syncSchedule = true): array
    {
        if ($taskId <= 0) {
            return ['ok' => false, 'error' => 'Tache invalide.'];
        }
        if ($actorUserId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }

        $existing = self::getTaskById($pdo, $taskId);
        if (!$existing) {
            return ['ok' => false, 'error' => 'Tache introuvable.'];
        }
        if (!$canManageAll && !in_array($actorUserId, [(int)$existing['created_by_user_id'], (int)$existing['assignee_user_id']], true)) {
            return ['ok' => false, 'error' => 'Modification non autorisee.'];
        }

        if (!empty($payload['apply_to_group']) && trim((string)($existing['event_group_key'] ?? '')) !== '') {
            $groupTaskIds = self::listTaskIdsByGroupKey($pdo, (string)$existing['event_group_key']);
            if (count($groupTaskIds) > 1) {
                $groupTasks = self::listTasksByIds($pdo, $groupTaskIds);
                $duplicatedStoredPaths = [];
                $pendingCalendarInvites = [];
                try {
                    $startedTransaction = !$pdo->inTransaction();
                    if ($startedTransaction) {
                        $pdo->beginTransaction();
                    }

                    $groupPayload = $payload;
                    $groupPayload['apply_to_group'] = false;

                    foreach ($groupTaskIds as $index => $groupTaskId) {
                        $groupTask = self::getTaskById($pdo, $groupTaskId);
                        if (!$groupTask) {
                            throw new RuntimeException('Tache groupee introuvable.');
                        }

                        $groupAttachments = $index === 0 ? $attachments : self::duplicateStoredAttachments($attachments, $duplicatedStoredPaths);
                        $groupPayload['assignee_user_id'] = (int)($groupTask['assignee_user_id'] ?? 0);
                        $result = self::updateTask($pdo, $groupTaskId, $actorUserId, $groupPayload, $groupAttachments, $canManageAll, $syncSchedule);
                        if (empty($result['ok'])) {
                            throw new RuntimeException((string)($result['error'] ?? 'Mise a jour groupee impossible.'));
                        }
                        foreach ((array)($result['calendar_invites'] ?? []) as $calendarInvite) {
                            if (is_array($calendarInvite)) {
                                $pendingCalendarInvites[] = $calendarInvite;
                            }
                        }
                    }

                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    self::notifyGroupedTaskChange($pdo, $groupTasks, $actorUserId, 'group_updated', [
                        'title' => trim((string)($payload['title'] ?? ($existing['title'] ?? ''))),
                        'deadline' => self::normalizeDeadlineForNotification((string)($payload['deadline'] ?? ($existing['deadline_raw'] ?? ''))),
                    ]);

                    $recipientCount = self::countGroupedRecipientUsers($groupTasks);
                    $calendarInviteResult = self::dispatchTaskCalendarInvites($pdo, $pendingCalendarInvites);

                    return [
                        'ok' => true,
                        'task_id' => $taskId,
                        'updated_count' => count($groupTaskIds),
                        'applied_to_group' => true,
                        'grouped_notification_emitted' => $recipientCount > 0,
                        'grouped_notification_message' => $recipientCount > 0 ? ('Notification groupée de modification envoyée à ' . $recipientCount . ' collaborateur(s).') : '',
                        'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                        'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                        'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
                    ];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    self::cleanupStoredFiles($duplicatedStoredPaths);
                    return ['ok' => false, 'error' => $e->getMessage()];
                }
            }
        }

        $assigneeUserId = (int)($payload['assignee_user_id'] ?? 0);
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $deadline = trim((string)($payload['deadline'] ?? ''));
        $durationMinutes = max(15, (int)($payload['duration_minutes'] ?? ($existing['duration_minutes'] ?? 60)));
        $priority = trim((string)($payload['priority'] ?? 'normal'));
        $status = trim((string)($payload['status'] ?? (string)$existing['status']));

        if ($assigneeUserId <= 0) {
            return ['ok' => false, 'error' => 'Selectionnez un collaborateur.'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre de la tache est obligatoire.'];
        }
        if ($deadline === '') {
            return ['ok' => false, 'error' => 'La deadline est obligatoire.'];
        }
        if (!isset(self::getPriorities()[$priority])) {
            $priority = 'normal';
        }
        if (!isset(self::getStatuses()[$status])) {
            $status = (string)$existing['status'];
        }

        $deadlineSql = str_replace('T', ' ', $deadline);
        if (strlen($deadlineSql) === 16) {
            $deadlineSql .= ':00';
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $stmt = $pdo->prepare(
                "UPDATE comm_tasks
                 SET assignee_user_id = :assignee_user_id,
                     title = :title,
                     description = :description,
                     deadline = :deadline,
                     duration_minutes = :duration_minutes,
                     priority = :priority,
                     status = :status,
                     completed_at = CASE WHEN :is_done = 1 THEN COALESCE(completed_at, NOW()) ELSE NULL END,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'assignee_user_id' => $assigneeUserId,
                'title' => mb_substr($title, 0, 255),
                'description' => $description !== '' ? $description : null,
                'deadline' => $deadlineSql,
                'duration_minutes' => $durationMinutes,
                'priority' => $priority,
                'status' => $status,
                'is_done' => $status === 'done' ? 1 : 0,
                'id' => $taskId,
            ]);

            if ($attachments) {
                self::storeAttachments($pdo, $taskId, $actorUserId, $attachments);
            }

            $changes = [];
            if ((int)$existing['assignee_user_id'] !== $assigneeUserId) {
                $changes[] = 'nouveau collaborateur';
            }
            if ((string)$existing['title'] !== $title) {
                $changes[] = 'titre';
            }
            if (trim((string)($existing['description'] ?? '')) !== $description) {
                $changes[] = 'description';
            }
            if ((string)$existing['deadline_raw'] !== $deadlineSql) {
                $changes[] = 'deadline';
            }
            if ((int)($existing['duration_minutes'] ?? 60) !== $durationMinutes) {
                $changes[] = 'duree estimee';
            }
            if ((string)$existing['priority'] !== $priority) {
                $changes[] = 'priorite';
            }
            if ((string)$existing['status'] !== $status) {
                $changes[] = 'statut';
            }
            if ($attachments) {
                $changes[] = 'pieces jointes ajoutees';
            }
            if ($changes) {
                self::addComment($pdo, $taskId, $actorUserId, 'Tache modifiee : ' . implode(', ', $changes));
            }

            $scheduleWarning = null;
            if ($syncSchedule) {
                $scheduleWarning = self::trySyncTaskSchedule($pdo, $taskId, $actorUserId);
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $updatedTask = self::getTaskById($pdo, $taskId);
            $calendarInvites = [];
            if ((int)$existing['assignee_user_id'] !== $assigneeUserId) {
                $cancelTask = $existing;
                $cancelTask['status'] = 'cancelled';
                $calendarInvites = array_merge($calendarInvites, self::buildTaskCalendarInvites($cancelTask, 'cancel', (int)$existing['assignee_user_id']));
            }
            if ($updatedTask) {
                $calendarInvites = array_merge($calendarInvites, self::buildTaskCalendarInvites($updatedTask, $status === 'cancelled' ? 'cancel' : 'update'));
            }
            $calendarInviteResult = $startedTransaction ? self::dispatchTaskCalendarInvites($pdo, $calendarInvites) : self::emptyCalendarInviteDispatchResult();

            $warningUsers = [];
            if ($scheduleWarning !== null) {
                $warningDisplay = self::resolveUserDisplayName($pdo, $assigneeUserId);
                if ($warningDisplay === '') {
                    $warningDisplay = 'Utilisateur #' . $assigneeUserId;
                }
                $warningUsers[] = $warningDisplay;
            }

            return [
                'ok' => true,
                'task_id' => $taskId,
                'schedule_sync_skipped' => $scheduleWarning !== null,
                'warning_users' => $warningUsers,
                'warning_message' => self::formatScheduleWarningMessage($warningUsers),
                'calendar_invites' => $calendarInvites,
                'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function deleteTask(PDO $pdo, int $taskId, int $actorUserId, bool $canManageAll = false, bool $syncSchedule = true): array
    {
        if ($taskId <= 0) {
            return ['ok' => false, 'error' => 'Tache invalide.'];
        }
        if ($actorUserId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }

        $task = self::getTaskById($pdo, $taskId);
        if (!$task) {
            return ['ok' => false, 'error' => 'Tache introuvable.'];
        }
        if (!$canManageAll && !in_array($actorUserId, [(int)$task['created_by_user_id'], (int)$task['assignee_user_id']], true)) {
            return ['ok' => false, 'error' => 'Suppression non autorisee.'];
        }

        $attachments = self::listAttachments($pdo, ['task_id' => $taskId, 'limit' => 250]);
        $baseStorage = realpath(__DIR__ . '/../storage');

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            if ($syncSchedule) {
                self::deleteTaskSchedule($pdo, $taskId);
            }

            $stmt = $pdo->prepare('DELETE FROM comm_tasks WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $taskId]);
            if ($stmt->rowCount() <= 0) {
                throw new RuntimeException('Suppression impossible.');
            }

            if ($baseStorage !== false) {
                foreach ($attachments as $attachment) {
                    $storedRel = str_replace(['..', '\\'], ['', '/'], (string)($attachment['stored_path'] ?? ''));
                    $resolved = realpath($baseStorage . '/' . $storedRel);
                    if (is_string($resolved) && $resolved !== '' && strpos($resolved, $baseStorage) === 0 && is_file($resolved)) {
                        @unlink($resolved);
                    }
                }
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $deletedTask = $task;
            $deletedTask['status'] = 'cancelled';
            $calendarInvites = self::buildTaskCalendarInvites($deletedTask, 'cancel');
            $calendarInviteResult = $startedTransaction ? self::dispatchTaskCalendarInvites($pdo, $calendarInvites) : self::emptyCalendarInviteDispatchResult();

            return [
                'ok' => true,
                'task_id' => $taskId,
                'calendar_invites' => $calendarInvites,
                'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function updateTaskStatus(PDO $pdo, int $taskId, string $status, int $authorUserId, bool $canManageAll = false, bool $syncSchedule = true, bool $applyToGroup = false): array
    {
        if ($taskId <= 0) {
            return ['ok' => false, 'error' => 'Tache invalide.'];
        }
        if ($authorUserId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }
        if (!isset(self::getStatuses()[$status])) {
            return ['ok' => false, 'error' => 'Statut invalide.'];
        }

        $task = self::getTaskById($pdo, $taskId);
        if (!$task) {
            return ['ok' => false, 'error' => 'Tache introuvable.'];
        }
        if (!$canManageAll && !in_array($authorUserId, [(int)$task['created_by_user_id'], (int)$task['assignee_user_id']], true)) {
            return ['ok' => false, 'error' => 'Modification non autorisee.'];
        }

        if ($applyToGroup && trim((string)($task['event_group_key'] ?? '')) !== '') {
            $groupTaskIds = self::listTaskIdsByGroupKey($pdo, (string)$task['event_group_key']);
            if (count($groupTaskIds) > 1) {
                $groupTasks = self::listTasksByIds($pdo, $groupTaskIds);
                $pendingCalendarInvites = [];
                try {
                    $startedTransaction = !$pdo->inTransaction();
                    if ($startedTransaction) {
                        $pdo->beginTransaction();
                    }

                    foreach ($groupTaskIds as $groupTaskId) {
                        $result = self::updateTaskStatus($pdo, $groupTaskId, $status, $authorUserId, $canManageAll, $syncSchedule, false);
                        if (empty($result['ok'])) {
                            throw new RuntimeException((string)($result['error'] ?? 'Mise a jour groupee impossible.'));
                        }
                        foreach ((array)($result['calendar_invites'] ?? []) as $calendarInvite) {
                            if (is_array($calendarInvite)) {
                                $pendingCalendarInvites[] = $calendarInvite;
                            }
                        }
                    }

                    if ($startedTransaction && $pdo->inTransaction()) {
                        $pdo->commit();
                    }

                    if ($status === 'cancelled') {
                        self::notifyGroupedTaskChange($pdo, $groupTasks, $authorUserId, 'group_cancelled', [
                            'title' => (string)($task['title'] ?? ''),
                            'deadline' => self::normalizeDeadlineForNotification((string)($task['deadline_raw'] ?? '')),
                        ]);
                    }

                    $recipientCount = self::countGroupedRecipientUsers($groupTasks);
                    $calendarInviteResult = self::dispatchTaskCalendarInvites($pdo, $pendingCalendarInvites);

                    return [
                        'ok' => true,
                        'task_id' => $taskId,
                        'updated_count' => count($groupTaskIds),
                        'applied_to_group' => true,
                        'grouped_notification_emitted' => $status === 'cancelled' && $recipientCount > 0,
                        'grouped_notification_message' => $status === 'cancelled' && $recipientCount > 0 ? ('Notification groupée d\'annulation envoyée à ' . $recipientCount . ' collaborateur(s).') : '',
                        'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                        'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                        'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
                    ];
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    return ['ok' => false, 'error' => $e->getMessage()];
                }
            }
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $stmt = $pdo->prepare(
                "UPDATE comm_tasks
                 SET status = :status,
                     completed_at = CASE WHEN :is_done = 1 THEN NOW() ELSE NULL END,
                     updated_at = NOW()
                 WHERE id = :id"
            );
            $stmt->execute([
                'status' => $status,
                'is_done' => $status === 'done' ? 1 : 0,
                'id' => $taskId,
            ]);

            self::addComment($pdo, $taskId, $authorUserId, 'Statut modifie : ' . (self::getStatuses()[$status] ?? $status));
            $scheduleWarning = null;
            if ($syncSchedule) {
                $scheduleWarning = self::trySyncTaskSchedule($pdo, $taskId, $authorUserId);
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            $updatedTask = self::getTaskById($pdo, $taskId);
            $calendarInvites = $updatedTask ? self::buildTaskCalendarInvites($updatedTask, $status === 'cancelled' ? 'cancel' : 'update') : [];
            $calendarInviteResult = $startedTransaction ? self::dispatchTaskCalendarInvites($pdo, $calendarInvites) : self::emptyCalendarInviteDispatchResult();

            $warningUsers = [];
            if ($scheduleWarning !== null) {
                $warningDisplay = self::resolveUserDisplayName($pdo, (int)($task['assignee_user_id'] ?? 0));
                if ($warningDisplay === '') {
                    $warningDisplay = 'Utilisateur #' . (int)($task['assignee_user_id'] ?? 0);
                }
                $warningUsers[] = $warningDisplay;
            }

            return [
                'ok' => true,
                'task_id' => $taskId,
                'schedule_sync_skipped' => $scheduleWarning !== null,
                'warning_users' => $warningUsers,
                'warning_message' => self::formatScheduleWarningMessage($warningUsers),
                'calendar_invites' => $calendarInvites,
                'calendar_invites_sent' => (int)($calendarInviteResult['sent'] ?? 0),
                'calendar_invites_failed' => (int)($calendarInviteResult['failed'] ?? 0),
                'calendar_invite_message' => (string)($calendarInviteResult['message'] ?? ''),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function updateUserTaskPreference(PDO $pdo, int $userId, int $taskId, ?bool $isPinned = null, ?int $sortOrder = null): array
    {
        if ($userId <= 0 || $taskId <= 0) {
            return ['ok' => false, 'error' => 'Préférence invalide.'];
        }

        if ($isPinned === null && $sortOrder === null) {
            return ['ok' => false, 'error' => 'Aucune préférence à enregistrer.'];
        }

        $existingStmt = $pdo->prepare('SELECT is_pinned, sort_order FROM comm_task_user_preferences WHERE user_id = :user_id AND task_id = :task_id LIMIT 1');
        $existingStmt->execute(['user_id' => $userId, 'task_id' => $taskId]);
        $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $stmt = $pdo->prepare(
            'INSERT INTO comm_task_user_preferences (user_id, task_id, is_pinned, sort_order)
             VALUES (:user_id, :task_id, :is_pinned, :sort_order)
             ON DUPLICATE KEY UPDATE is_pinned = VALUES(is_pinned), sort_order = VALUES(sort_order), updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'task_id' => $taskId,
            'is_pinned' => $isPinned !== null ? ($isPinned ? 1 : 0) : (int)($existing['is_pinned'] ?? 0),
            'sort_order' => $sortOrder !== null ? $sortOrder : ($existing['sort_order'] ?? null),
        ]);

        return ['ok' => true, 'task_id' => $taskId];
    }

    private static function defaultGroupScopeLabel(string $groupScope): string
    {
        return match ($groupScope) {
            'team' => 'Tout le personnel',
            'service' => 'Service',
            'selected' => 'Sélection manuelle',
            default => 'Affectation groupée',
        };
    }

    private static function listTaskIdsByGroupKey(PDO $pdo, string $groupKey): array
    {
        $groupKey = trim($groupKey);
        if ($groupKey === '') {
            return [];
        }

        $stmt = $pdo->prepare('SELECT id FROM comm_tasks WHERE event_group_key = :group_key AND archived_at IS NULL ORDER BY id ASC');
        $stmt->execute(['group_key' => $groupKey]);
        return self::normalizePositiveIds($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
    }

    /**
     * @param array<int,int> $taskIds
     * @return array<int,array<string,mixed>>
     */
    private static function listTasksByIds(PDO $pdo, array $taskIds): array
    {
        $taskIds = self::normalizePositiveIds($taskIds);
        if ($taskIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($taskIds as $index => $taskId) {
            $key = ':task_' . $index;
            $placeholders[] = $key;
            $params[$key] = $taskId;
        }

        $stmt = $pdo->prepare(
            'SELECT id, assignee_user_id, title, deadline AS deadline_raw, event_group_key, group_scope, group_scope_label
             FROM comm_tasks
             WHERE archived_at IS NULL AND id IN (' . implode(', ', $placeholders) . ')'
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private static function normalizeDeadlineForNotification(string $deadline): string
    {
        $deadline = trim(str_replace('T', ' ', $deadline));
        if ($deadline !== '' && strlen($deadline) === 16) {
            $deadline .= ':00';
        }
        return $deadline;
    }

    private static function resolveUserDisplayName(PDO $pdo, int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(prenom), ''), '') AS prenom, COALESCE(NULLIF(TRIM(nom), ''), '') AS nom, COALESCE(NULLIF(TRIM(mail_pro), ''), '') AS mail_pro FROM user_devis_banamur WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $display = trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? ''));
        if ($display === '') {
            $display = trim((string)($row['mail_pro'] ?? ''));
        }

        return $display;
    }

    /**
     * @param array<int,array<string,mixed>> $groupTasks
     * @param array<string,mixed> $overrideEvent
     */
    private static function notifyGroupedTaskChange(PDO $pdo, array $groupTasks, int $actorUserId, string $changeType, array $overrideEvent = []): void
    {
        if ($groupTasks === [] || $actorUserId <= 0) {
            return;
        }

        $changeType = strtolower(trim($changeType));
        if (!in_array($changeType, ['group_updated', 'group_cancelled'], true)) {
            return;
        }

        $anchorTask = $groupTasks[0] ?? [];
        $recipientUserIds = [];
        foreach ($groupTasks as $groupTask) {
            $recipientUserId = (int)($groupTask['assignee_user_id'] ?? 0);
            if ($recipientUserId > 0) {
                $recipientUserIds[$recipientUserId] = true;
            }
        }
        if ($recipientUserIds === []) {
            return;
        }

        $notif = new \NotificationController($pdo);
        $scopeLabel = trim((string)($anchorTask['group_scope_label'] ?? '')) !== ''
            ? (string)$anchorTask['group_scope_label']
            : self::defaultGroupScopeLabel((string)($anchorTask['group_scope'] ?? 'selected'));
        $actorName = self::resolveUserDisplayName($pdo, $actorUserId);
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $event = array_merge([
            'task_id' => (int)($anchorTask['id'] ?? 0),
            'title' => (string)($anchorTask['title'] ?? ''),
            'deadline' => self::normalizeDeadlineForNotification((string)($anchorTask['deadline_raw'] ?? '')),
            'event_group_key' => (string)($anchorTask['event_group_key'] ?? ''),
            'group_scope' => (string)($anchorTask['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
            'actor_user_id' => $actorUserId,
            'actor_name' => $actorName,
        ], $overrideEvent);

        foreach (array_keys($recipientUserIds) as $recipientUserId) {
            try {
                $notif->notifyCommunicationTaskChanged((int)$recipientUserId, $changeType, $event);
            } catch (Throwable $e) {
                // Notification best effort.
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $groupTasks
     * @param array<string,mixed> $overrideEvent
     */
    private static function notifyGroupedTaskAssigned(PDO $pdo, array $groupTasks, int $actorUserId, array $overrideEvent = []): void
    {
        if ($groupTasks === [] || $actorUserId <= 0) {
            return;
        }

        $anchorTask = $groupTasks[0] ?? [];
        $recipientUserIds = [];
        foreach ($groupTasks as $groupTask) {
            $recipientUserId = (int)($groupTask['assignee_user_id'] ?? 0);
            if ($recipientUserId > 0) {
                $recipientUserIds[$recipientUserId] = true;
            }
        }
        if ($recipientUserIds === []) {
            return;
        }

        $notif = new \NotificationController($pdo);
        $scopeLabel = trim((string)($anchorTask['group_scope_label'] ?? '')) !== ''
            ? (string)$anchorTask['group_scope_label']
            : self::defaultGroupScopeLabel((string)($anchorTask['group_scope'] ?? 'selected'));
        $actorName = self::resolveUserDisplayName($pdo, $actorUserId);
        if ($actorName === '') {
            $actorName = 'Un collègue';
        }

        $event = array_merge([
            'task_id' => (int)($anchorTask['id'] ?? 0),
            'title' => (string)($anchorTask['title'] ?? ''),
            'deadline' => self::normalizeDeadlineForNotification((string)($anchorTask['deadline_raw'] ?? '')),
            'event_group_key' => (string)($anchorTask['event_group_key'] ?? ''),
            'group_scope' => (string)($anchorTask['group_scope'] ?? ''),
            'group_scope_label' => $scopeLabel,
            'actor_user_id' => $actorUserId,
            'actor_name' => $actorName,
        ], $overrideEvent);

        foreach (array_keys($recipientUserIds) as $recipientUserId) {
            try {
                $notif->notifyCommunicationTaskAssigned((int)$recipientUserId, 'group_created', $event);
            } catch (Throwable $e) {
                // Notification best effort.
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $groupTasks
     */
    private static function countGroupedRecipientUsers(array $groupTasks): int
    {
        $recipientUserIds = [];
        foreach ($groupTasks as $groupTask) {
            $recipientUserId = (int)($groupTask['assignee_user_id'] ?? 0);
            if ($recipientUserId > 0) {
                $recipientUserIds[$recipientUserId] = true;
            }
        }

        return count($recipientUserIds);
    }

    public static function saveUserTaskOrdering(PDO $pdo, int $userId, array $orderedTaskIds): array
    {
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }

        $taskIds = array_values(array_unique(array_filter(array_map('intval', $orderedTaskIds), static function (int $taskId): bool {
            return $taskId > 0;
        })));
        if (!$taskIds) {
            return ['ok' => false, 'error' => 'Aucune tache a ordonner.'];
        }

        try {
            $startedTransaction = !$pdo->inTransaction();
            if ($startedTransaction) {
                $pdo->beginTransaction();
            }

            $order = 10;
            foreach ($taskIds as $taskId) {
                self::updateUserTaskPreference($pdo, $userId, $taskId, null, $order);
                $order += 10;
            }

            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public static function addComment(PDO $pdo, int $taskId, int $authorUserId, string $body): array
    {
        $body = trim($body);
        if ($taskId <= 0 || $authorUserId <= 0) {
            return ['ok' => false, 'error' => 'Commentaire invalide.'];
        }
        if ($body === '') {
            return ['ok' => false, 'error' => 'Le commentaire est vide.'];
        }

        $stmt = $pdo->prepare('INSERT INTO comm_task_comments (task_id, author_user_id, body) VALUES (:task_id, :author_user_id, :body)');
        $stmt->execute([
            'task_id' => $taskId,
            'author_user_id' => $authorUserId,
            'body' => $body,
        ]);

        return ['ok' => true, 'comment_id' => (int)$pdo->lastInsertId()];
    }

    public static function storeAttachments(PDO $pdo, int $taskId, int $uploaderUserId, array $attachments): void
    {
        if ($taskId <= 0 || !$attachments) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO comm_task_attachments (task_id, uploaded_by_user_id, stored_path, original_name, mime_type, file_size) VALUES (:task_id, :uploaded_by_user_id, :stored_path, :original_name, :mime_type, :file_size)'
        );
        foreach ($attachments as $attachment) {
            $stmt->execute([
                'task_id' => $taskId,
                'uploaded_by_user_id' => $uploaderUserId,
                'stored_path' => (string)$attachment['stored_rel'],
                'original_name' => mb_substr((string)$attachment['original_name'], 0, 255),
                'mime_type' => mb_substr((string)($attachment['mime'] ?? ''), 0, 100),
                'file_size' => (int)($attachment['size'] ?? 0),
            ]);
        }
    }

    public static function getAttachment(int $attachmentId, PDO $pdo): ?array
    {
        if ($attachmentId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT a.*, t.title AS task_title, t.assignee_user_id, t.created_by_user_id
             FROM comm_task_attachments a
             INNER JOIN comm_tasks t ON t.id = a.task_id
             WHERE a.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $row ?: null;
    }

    public static function getTaskById(PDO $pdo, int $taskId): ?array
    {
        if ($taskId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT id, assignee_user_id, created_by_user_id, title, description, deadline AS deadline_raw, duration_minutes, priority, status, event_group_key, group_scope, group_scope_label, created_at, updated_at
             FROM comm_tasks
             WHERE id = :id AND archived_at IS NULL
             LIMIT 1"
        );
        $stmt->execute(['id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return $row ?: null;
    }

    public static function deleteAttachment(PDO $pdo, int $attachmentId, int $userId, bool $canManageAll = false): array
    {
        if ($attachmentId <= 0) {
            return ['ok' => false, 'error' => 'Piece jointe invalide.'];
        }
        if ($userId <= 0) {
            return ['ok' => false, 'error' => 'Utilisateur non identifie.'];
        }

        $attachment = self::getAttachment($attachmentId, $pdo);
        if (!$attachment) {
            return ['ok' => false, 'error' => 'Piece jointe introuvable.'];
        }

        $uploadedByUserId = (int)($attachment['uploaded_by_user_id'] ?? 0);
        $createdByUserId = (int)($attachment['created_by_user_id'] ?? 0);
        $assigneeUserId = (int)($attachment['assignee_user_id'] ?? 0);
        if (!$canManageAll && !in_array($userId, [$uploadedByUserId, $createdByUserId, $assigneeUserId], true)) {
            return ['ok' => false, 'error' => 'Suppression non autorisee.'];
        }

        $baseStorage = realpath(__DIR__ . '/../storage');
        $absolutePath = null;
        if ($baseStorage !== false) {
            $storedRel = str_replace(['..', '\\'], ['', '/'], (string)($attachment['stored_path'] ?? ''));
            $resolved = realpath($baseStorage . '/' . $storedRel);
            if (is_string($resolved) && $resolved !== '' && strpos($resolved, $baseStorage) === 0) {
                $absolutePath = $resolved;
            }
        }

        $stmt = $pdo->prepare('DELETE FROM comm_task_attachments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $attachmentId]);
        if ($stmt->rowCount() <= 0) {
            return ['ok' => false, 'error' => 'Suppression impossible.'];
        }

        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return ['ok' => true, 'attachment_id' => $attachmentId];
    }

    private static function mapAttachmentsByTaskIds(PDO $pdo, array $taskIds, int $perTask = 4): array
    {
        $taskIds = array_values(array_filter(array_map('intval', $taskIds), static function (int $taskId): bool {
            return $taskId > 0;
        }));
        if (!$taskIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT
                a.id,
                a.task_id,
                a.original_name,
                a.mime_type,
                a.file_size,
                a.stored_path,
                DATE_FORMAT(a.created_at, '%Y-%m-%dT%H:%i:%s') AS created_at_iso
             FROM comm_task_attachments a
             WHERE a.task_id IN ($placeholders)
             ORDER BY a.task_id ASC, a.created_at DESC, a.id DESC"
        );
        $stmt->execute($taskIds);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $attachment) {
            $taskId = (int)($attachment['task_id'] ?? 0);
            if ($taskId <= 0) {
                continue;
            }
            if (count($grouped[$taskId] ?? []) >= $perTask) {
                continue;
            }
            self::hydrateAttachmentOutput($attachment);
            $grouped[$taskId][] = $attachment;
        }

        return $grouped;
    }

    private static function hydrateAttachmentOutput(array &$attachment): void
    {
        $attachment['assignee_display'] = self::composeUserDisplay((string)($attachment['assignee_prenom'] ?? ''), (string)($attachment['assignee_nom'] ?? ''), (string)($attachment['assignee_mail_pro'] ?? ''));
        $attachment['uploader_display'] = self::composeUserDisplay((string)($attachment['uploader_prenom'] ?? ''), (string)($attachment['uploader_nom'] ?? ''), (string)($attachment['uploader_mail_pro'] ?? ''));
        $attachment['task_status_label'] = self::getStatuses()[$attachment['task_status'] ?? ''] ?? (string)($attachment['task_status'] ?? '');
        $attachmentId = (int)($attachment['id'] ?? 0);
        $attachment['download_url'] = 'api/communication_download.php?attachment_id=' . $attachmentId;
        $attachment['preview_url'] = 'api/communication_download.php?attachment_id=' . $attachmentId . '&inline=1';
        $attachment['is_previewable'] = self::isPreviewableMime((string)($attachment['mime_type'] ?? ''));
        $attachment['extension'] = strtolower((string)pathinfo((string)($attachment['original_name'] ?? ''), PATHINFO_EXTENSION));
    }

    private static function isPreviewableMime(string $mimeType): bool
    {
        $mimeType = strtolower(trim($mimeType));
        if ($mimeType === '') {
            return false;
        }

        return strpos($mimeType, 'image/') === 0
            || strpos($mimeType, 'video/') === 0
            || strpos($mimeType, 'audio/') === 0
            || in_array($mimeType, ['application/pdf', 'text/plain', 'text/csv'], true);
    }

    private static function syncTaskSchedule(PDO $pdo, int $taskId, int $actorUserId): void
    {
        $task = self::getTaskById($pdo, $taskId);
        if (!$task) {
            throw new RuntimeException('Tache introuvable pour la synchronisation planning.');
        }

        $schedule = new \EmployeeSchedule($pdo);
        if (!$schedule->isReady()) {
            throw new RuntimeException($schedule->getInitError() ?? 'Module emploi du temps indisponible.');
        }

        $assignee = self::getUserById($pdo, (int)($task['assignee_user_id'] ?? 0));
        $email = trim((string)($assignee['mail_pro'] ?? ''));
        if ($email === '') {
            throw new RuntimeException('Le collaborateur assigne doit avoir un email professionnel pour etre place dans l\'emploi du temps.');
        }

        $personnelId = $schedule->resolvePersonnelIdByEmail($email);
        if ($personnelId === null) {
            throw new RuntimeException('Aucun profil personnel lie a cet email dans l\'emploi du temps.');
        }

        $existingEvent = $schedule->getEventRowByCommunicationTaskId($taskId);
        $slot = $schedule->planAvailableSlot(
            $personnelId,
            (string)($task['deadline_raw'] ?? ''),
            max(15, (int)($task['duration_minutes'] ?? 60)),
            $existingEvent ? (int)($existingEvent['id'] ?? 0) : null
        );
        if (empty($slot['ok'])) {
            throw new RuntimeException((string)($slot['error'] ?? 'Aucun creneau disponible sans chevauchement.'));
        }

        if ($existingEvent && (int)($existingEvent['personnel_id'] ?? 0) !== $personnelId) {
            $schedule->deleteEventByCommunicationTaskId($taskId);
            $existingEvent = null;
        }

        $title = 'Tache communication: ' . mb_substr((string)($task['title'] ?? ''), 0, 220);
        $description = trim((string)($task['description'] ?? ''));
        $description = $description === ''
            ? 'Tache communication synchronisee automatiquement.'
            : 'Tache communication synchronisee automatiquement. ' . $description;
        $statusCode = self::mapTaskStatusToScheduleStatus((string)($task['status'] ?? 'todo'));

        if ($existingEvent) {
            $updated = $schedule->updateEvent(
                (int)($existingEvent['id'] ?? 0),
                $personnelId,
                $title,
                (string)($slot['start_at'] ?? ''),
                (string)($slot['end_at'] ?? ''),
                $statusCode,
                $description,
                'Communication'
            );
            if (empty($updated['ok'])) {
                throw new RuntimeException((string)($updated['error'] ?? 'Echec de mise a jour du creneau.'));
            }
            return;
        }

        $created = $schedule->createEvent(
            $personnelId,
            $title,
            (string)($slot['start_at'] ?? ''),
            (string)($slot['end_at'] ?? ''),
            $statusCode,
            $actorUserId,
            $description,
            'Communication',
            null,
            $taskId
        );
        if (empty($created['ok'])) {
            throw new RuntimeException((string)($created['error'] ?? 'Echec de creation du creneau.'));
        }
    }

    private static function trySyncTaskSchedule(PDO $pdo, int $taskId, int $actorUserId): ?string
    {
        try {
            self::syncTaskSchedule($pdo, $taskId, $actorUserId);
            return null;
        } catch (Throwable $e) {
            if (!self::isSoftScheduleSyncError($e)) {
                throw $e;
            }

            return 'Planning non synchronisé : aucun profil personnel exploitable dans l\'emploi du temps pour ce collaborateur.';
        }
    }

    private static function isSoftScheduleSyncError(Throwable $e): bool
    {
        return in_array((string)$e->getMessage(), self::SCHEDULE_SYNC_SOFT_ERRORS, true);
    }

    /**
     * @param array<int,string> $warningUsers
     */
    private static function formatScheduleWarningMessage(array $warningUsers): string
    {
        $warningUsers = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string)$value);
        }, $warningUsers), static function (string $value): bool {
            return $value !== '';
        })));

        if ($warningUsers === []) {
            return '';
        }

        return 'Planning non synchronisé pour ' . count($warningUsers) . ' collaborateur(s) : ' . implode(', ', $warningUsers) . '.';
    }

    private static function deleteTaskSchedule(PDO $pdo, int $taskId): void
    {
        $schedule = new \EmployeeSchedule($pdo);
        if ($schedule->isReady()) {
            $schedule->deleteEventByCommunicationTaskId($taskId);
        }
    }

    /**
     * @param array<string,mixed> $task
     * @return array<int,array<string,mixed>>
     */
    private static function buildTaskCalendarInvites(array $task, string $action, ?int $recipientUserId = null): array
    {
        $recipientId = $recipientUserId ?? (int)($task['assignee_user_id'] ?? 0);
        if ($recipientId <= 0) {
            return [];
        }

        return [[
            'task' => $task,
            'action' => $action,
            'recipient_user_id' => $recipientId,
        ]];
    }

    /**
     * @param array<int,array<string,mixed>> $invites
     * @return array{sent:int,failed:int,message:string}
     */
    private static function dispatchTaskCalendarInvites(PDO $pdo, array $invites): array
    {
        $sent = 0;
        $failed = 0;

        foreach ($invites as $invite) {
            if (!is_array($invite)) {
                continue;
            }

            $result = self::dispatchTaskCalendarInvite(
                $pdo,
                is_array($invite['task'] ?? null) ? (array)$invite['task'] : [],
                trim((string)($invite['action'] ?? 'update')),
                (int)($invite['recipient_user_id'] ?? 0)
            );

            if (!empty($result['sent'])) {
                $sent++;
            } elseif (empty($result['skipped'])) {
                $failed++;
            }
        }

        return [
            'sent' => $sent,
            'failed' => $failed,
            'message' => self::formatCalendarInviteDispatchMessage($sent, $failed),
        ];
    }

    /**
     * @param array<string,mixed> $task
     * @return array{sent:bool,skipped:bool,error:string}
     */
    private static function dispatchTaskCalendarInvite(PDO $pdo, array $task, string $action, int $recipientUserId): array
    {
        if ($recipientUserId <= 0 || empty($task['id'])) {
            return ['sent' => false, 'skipped' => true, 'error' => ''];
        }

        try {
            $recipient = self::getUserById($pdo, $recipientUserId);
            $recipientEmail = trim((string)($recipient['mail_pro'] ?? ''));
            if ($recipientEmail === '') {
                return ['sent' => false, 'skipped' => true, 'error' => ''];
            }

            $creator = self::getUserById($pdo, (int)($task['created_by_user_id'] ?? 0));
            $recipientName = self::composeUserDisplay((string)($recipient['prenom'] ?? ''), (string)($recipient['nom'] ?? ''), $recipientEmail);
            $creatorName = self::composeUserDisplay((string)($creator['prenom'] ?? ''), (string)($creator['nom'] ?? ''), (string)($creator['mail_pro'] ?? ''));

            $mailPayload = self::buildTaskCalendarMailPayload($pdo, $task, $action, $recipientName, $recipientEmail, $creatorName);
            $emailChannel = new \NotificationEmailChannelService();
            $sent = $emailChannel->send(
                $recipientEmail,
                $recipientName,
                (string)$mailPayload['subject'],
                (string)$mailPayload['html'],
                (string)$mailPayload['plain'],
                [
                    'disable_tracking' => true,
                    'attachments' => [[
                        'name' => (string)$mailPayload['filename'],
                        'content' => (string)$mailPayload['ics'],
                        'type' => (string)$mailPayload['mime_type'],
                    ]],
                ]
            );

            return ['sent' => $sent, 'skipped' => false, 'error' => $sent ? '' : 'send_failed'];
        } catch (Throwable $e) {
            error_log('Communication ICS invite error: ' . $e->getMessage());
            return ['sent' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string,mixed> $task
     * @return array{subject:string,html:string,plain:string,ics:string,filename:string,mime_type:string}
     */
    private static function buildTaskCalendarMailPayload(PDO $pdo, array $task, string $action, string $recipientName, string $recipientEmail, string $creatorName): array
    {
        $title = trim((string)($task['title'] ?? 'Tâche communication'));
        $description = trim((string)($task['description'] ?? ''));
        $deadlineRaw = trim((string)($task['deadline_raw'] ?? ''));
        $durationMinutes = max(15, (int)($task['duration_minutes'] ?? 60));
        $periodLabel = self::formatTaskCalendarPeriodLabel($deadlineRaw, $durationMinutes);
        $taskLink = self::buildTaskDirectLink($task);

        $actionLabels = [
            'create' => ['subject' => 'Communication - Nouvelle tâche', 'verb' => 'créée', 'method' => 'REQUEST'],
            'update' => ['subject' => 'Communication - Tâche mise à jour', 'verb' => 'mise à jour', 'method' => 'REQUEST'],
            'cancel' => ['subject' => 'Communication - Tâche annulée', 'verb' => 'annulée', 'method' => 'CANCEL'],
        ];
        $actionMeta = $actionLabels[$action] ?? $actionLabels['update'];

        $subject = $actionMeta['subject'] . ' : ' . $title . ' • ' . $periodLabel;
        $creatorLabel = $creatorName !== '' ? $creatorName : 'BANAMUR';
        $message = 'Bonjour ' . ($recipientName !== '' ? $recipientName : $recipientEmail) . '.\n\n'
            . 'La tâche communication "' . $title . '" a été ' . (string)$actionMeta['verb'] . '.\n\n'
            . 'Quand : ' . $periodLabel . '\n'
            . 'Durée estimée : ' . $durationMinutes . ' min\n'
            . 'Créée par : ' . $creatorLabel . '\n\n'
            . 'Détails : ' . ($description !== '' ? $description : 'Aucune description complémentaire.') . '\n\n'
            . 'Utilisez le bouton pour revenir directement sur la tâche dans l\'application. Le fichier calendrier ICS est joint pour votre agenda mail.';

        $notificationManager = new \NotificationController($pdo);
        $emailPackage = $notificationManager->buildEmailRenderingPackage($subject, $message, $taskLink);

        return [
            'subject' => $subject,
            'html' => (string)($emailPackage['html'] ?? ''),
            'plain' => (string)($emailPackage['plain'] ?? ''),
            'ics' => self::buildTaskIcsPayload($task, (string)$actionMeta['method'], $recipientName, $recipientEmail, $creatorLabel, $taskLink),
            'filename' => self::buildTaskCalendarFilename($task, $action),
            'mime_type' => 'text/calendar; charset=UTF-8; method=' . (string)$actionMeta['method'],
        ];
    }

    /**
     * @param array<string,mixed> $task
     */
    private static function buildTaskIcsPayload(array $task, string $method, string $recipientName, string $recipientEmail, string $creatorName, string $taskLink): string
    {
        $taskId = (int)($task['id'] ?? 0);
        $title = trim((string)($task['title'] ?? 'Tâche communication'));
        $description = trim((string)($task['description'] ?? ''));
        $deadlineRaw = trim((string)($task['deadline_raw'] ?? ''));
        $durationMinutes = max(15, (int)($task['duration_minutes'] ?? 60));
        $deadline = self::parseTaskCalendarDate($deadlineRaw);
        $end = $deadline->modify('+' . $durationMinutes . ' minutes');
        $uid = 'comm-task-' . $taskId . '@banamur.local';
        $dtStamp = gmdate('Ymd\THis\Z');
        $sequence = max(0, strtotime((string)($task['updated_at'] ?? $task['created_at'] ?? 'now')) ?: time());
        $icsDescription = 'Tâche communication : ' . $title;
        if ($description !== '') {
            $icsDescription .= "\\n\\n" . $description;
        }
        if ($creatorName !== '') {
            $icsDescription .= "\\n\\nCréée par : " . $creatorName;
        }
        if ($taskLink !== '') {
            $icsDescription .= "\\n\\nLien direct : " . $taskLink;
        }

        $lines = [
            'BEGIN:VCALENDAR',
            'PRODID:-//BANAMUR//Communication Tasks//FR',
            'VERSION:2.0',
            'CALSCALE:GREGORIAN',
            'METHOD:' . strtoupper($method),
            'BEGIN:VEVENT',
            'UID:' . self::escapeIcsText($uid),
            'DTSTAMP:' . $dtStamp,
            'DTSTART:' . $deadline->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'DTEND:' . $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z'),
            'SUMMARY:' . self::escapeIcsText($title),
            'DESCRIPTION:' . self::escapeIcsText($icsDescription),
            'URL:' . self::escapeIcsText($taskLink),
            'STATUS:' . (strtoupper($method) === 'CANCEL' ? 'CANCELLED' : 'CONFIRMED'),
            'SEQUENCE:' . $sequence,
            'ORGANIZER;CN=' . self::escapeIcsParam($creatorName !== '' ? $creatorName : 'BANAMUR Support') . ':mailto:support@fidest.ci',
            'ATTENDEE;CN=' . self::escapeIcsParam($recipientName !== '' ? $recipientName : $recipientEmail) . ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=' . (strtoupper($method) === 'CANCEL' ? 'FALSE' : 'TRUE') . ':mailto:' . self::escapeIcsText($recipientEmail),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<string,mixed> $task
     */
    private static function buildTaskDirectLink(array $task): string
    {
        $taskId = (int)($task['id'] ?? 0);
        if ($taskId <= 0) {
            return '/communication/taches_communication.php';
        }

        return '/communication/taches_communication.php?task_id=' . $taskId . '&mine=1';
    }

    private static function emptyCalendarInviteDispatchResult(): array
    {
        return ['sent' => 0, 'failed' => 0, 'message' => ''];
    }

    private static function formatCalendarInviteDispatchMessage(int $sent, int $failed): string
    {
        if ($sent <= 0 && $failed <= 0) {
            return '';
        }

        $parts = [];
        if ($sent > 0) {
            $parts[] = 'Invitations calendrier envoyées : ' . $sent;
        }
        if ($failed > 0) {
            $parts[] = 'échecs d\'envoi : ' . $failed;
        }

        return implode(' • ', $parts) . '.';
    }

    private static function formatTaskCalendarPeriodLabel(string $deadlineRaw, int $durationMinutes): string
    {
        $deadline = self::parseTaskCalendarDate($deadlineRaw);
        $end = $deadline->modify('+' . $durationMinutes . ' minutes');

        return $deadline->format('d/m/Y H:i') . ' - ' . $end->format('H:i');
    }

    private static function parseTaskCalendarDate(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return new DateTimeImmutable('now');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return new DateTimeImmutable('now');
        }
    }

    /**
     * @param array<string,mixed> $task
     */
    private static function buildTaskCalendarFilename(array $task, string $action): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower(trim((string)($task['title'] ?? 'tache'))));
        $slug = trim((string)$slug, '-');
        if ($slug === '') {
            $slug = 'tache';
        }

        return $slug . '-' . $action . '.ics';
    }

    private static function escapeIcsText(string $value): string
    {
        return str_replace(
            ["\\", ";", ",", "\r\n", "\n", "\r"],
            ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
            $value
        );
    }

    private static function escapeIcsParam(string $value): string
    {
        return str_replace(["\\", ";", ",", '"'], ["\\\\", "\\;", "\\,", "\\'"], $value);
    }

    private static function getUserById(PDO $pdo, int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function getActiveUserIdByPersonnelId(PDO $pdo, int $personnelId): ?int
    {
        if ($personnelId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id
            FROM user_devis_banamur
            WHERE personnel_id = :personnel_id AND active = 1
            ORDER BY id ASC
            LIMIT 1');
        $stmt->execute(['personnel_id' => $personnelId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int)$value : null;
    }

    private static function resolveTargetAssigneeUserIds(PDO $pdo, array $payload): array
    {
        $scope = trim((string)($payload['target_scope'] ?? 'single'));
        if ($scope === '') {
            $scope = 'single';
        }

        if ($scope === 'team') {
            $excludedStatusCodes = self::EXCLUDED_TEAM_SCOPE_STATUS_CODES;
            $placeholders = implode(',', array_fill(0, count($excludedStatusCodes), '?'));
            $stmt = $pdo->prepare(
                'SELECT u.id
                 FROM user_devis_banamur u
                 LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                 WHERE u.active = 1
                   AND (p.id_personnel_soignant IS NULL OR p.statut_pers_soignant_code NOT IN (' . $placeholders . '))
                 ORDER BY u.prenom ASC, u.nom ASC, u.id ASC'
            );
            $stmt->execute($excludedStatusCodes);
            $values = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
            return self::normalizePositiveIds($values);
        }

        if ($scope === 'service') {
            $serviceId = (int)($payload['service_id'] ?? 0);
            if ($serviceId <= 0) {
                return [];
            }

            $stmt = $pdo->prepare(
                'SELECT u.id
                 FROM user_devis_banamur u
                 INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                 WHERE u.active = 1 AND p.service_id = :service_id
                 ORDER BY u.prenom ASC, u.nom ASC, u.id ASC'
            );
            $stmt->execute(['service_id' => $serviceId]);
            return self::normalizePositiveIds($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
        }

        if ($scope === 'selected') {
            return self::normalizePositiveIds($payload['assignee_user_ids'] ?? []);
        }

        return self::normalizePositiveIds([(int)($payload['assignee_user_id'] ?? 0)]);
    }

    private static function normalizePositiveIds(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $id = (int)$value;
            if ($id > 0) {
                $normalized[$id] = $id;
            }
        }

        return array_values($normalized);
    }

    private static function duplicateStoredAttachments(array $attachments, array &$duplicatedStoredPaths): array
    {
        if ($attachments === []) {
            return [];
        }

        $baseStorage = realpath(__DIR__ . '/../storage');
        if ($baseStorage === false) {
            throw new RuntimeException('Dossier de stockage des pièces jointes introuvable.');
        }

        $cloned = [];
        foreach ($attachments as $attachment) {
            $storedRel = str_replace(['..', '\\'], ['', '/'], (string)($attachment['stored_rel'] ?? ''));
            if ($storedRel === '') {
                continue;
            }

            $sourcePath = $baseStorage . '/' . $storedRel;
            if (!is_file($sourcePath)) {
                throw new RuntimeException('Pièce jointe source introuvable pour la duplication groupée.');
            }

            $extension = strtolower((string)pathinfo($storedRel, PATHINFO_EXTENSION));
            $relativeDir = trim((string)dirname($storedRel), './\\');
            $targetDir = $baseStorage . ($relativeDir !== '' ? '/' . $relativeDir : '');
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
                throw new RuntimeException('Impossible de préparer le dossier des pièces jointes groupées.');
            }

            $targetName = bin2hex(random_bytes(12)) . ($extension !== '' ? '.' . $extension : '');
            $targetRelative = ($relativeDir !== '' ? $relativeDir . '/' : '') . $targetName;
            $targetPath = $baseStorage . '/' . $targetRelative;
            if (!@copy($sourcePath, $targetPath)) {
                throw new RuntimeException('Impossible de dupliquer une pièce jointe pour la création groupée.');
            }

            $duplicatedStoredPaths[] = $targetRelative;
            $cloned[] = [
                'stored_rel' => $targetRelative,
                'original_name' => (string)($attachment['original_name'] ?? ''),
                'mime' => (string)($attachment['mime'] ?? 'application/octet-stream'),
                'size' => (int)($attachment['size'] ?? 0),
            ];
        }

        return $cloned;
    }

    private static function cleanupStoredFiles(array $relativePaths): void
    {
        if ($relativePaths === []) {
            return;
        }

        $baseStorage = realpath(__DIR__ . '/../storage');
        if ($baseStorage === false) {
            return;
        }

        foreach ($relativePaths as $relativePath) {
            $clean = str_replace(['..', '\\'], ['', '/'], (string)$relativePath);
            if ($clean === '') {
                continue;
            }

            $absolutePath = $baseStorage . '/' . $clean;
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }
    }

    private static function mapScheduleStatusToTaskStatus(string $statusCode): string
    {
        return match ($statusCode) {
            'en_cours' => 'in_progress',
            'pause' => 'blocked',
            'termine' => 'done',
            'annule' => 'cancelled',
            default => 'todo',
        };
    }

    private static function buildTaskPayloadFromSchedule(array $eventPayload, int $assigneeUserId): array
    {
        $title = trim((string)($eventPayload['title'] ?? ''));
        $description = trim((string)($eventPayload['description'] ?? ''));
        $location = trim((string)($eventPayload['location'] ?? ''));
        $startAt = trim((string)($eventPayload['start_at'] ?? ''));
        $endAt = trim((string)($eventPayload['end_at'] ?? ''));
        $statusCode = trim((string)($eventPayload['status_code'] ?? 'planifie'));

        if ($location !== '') {
            $description = $description !== ''
                ? ($description . "\n\nLieu : " . $location)
                : ('Lieu : ' . $location);
        }

        $durationMinutes = 60;
        if ($startAt !== '' && $endAt !== '') {
            $startTs = strtotime($startAt);
            $endTs = strtotime($endAt);
            if ($startTs !== false && $endTs !== false && $endTs > $startTs) {
                $durationMinutes = max(15, (int)round(($endTs - $startTs) / 60));
            }
        }

        return [
            'assignee_user_id' => $assigneeUserId,
            'title' => $title,
            'description' => $description,
            'deadline' => str_replace(' ', 'T', $endAt !== '' ? $endAt : $startAt),
            'duration_minutes' => $durationMinutes,
            'priority' => 'normal',
            'status' => self::mapScheduleStatusToTaskStatus($statusCode),
        ];
    }

    public static function createTaskFromSchedule(PDO $pdo, int $creatorUserId, int $personnelId, array $eventPayload): array
    {
        $assigneeUserId = self::getActiveUserIdByPersonnelId($pdo, $personnelId);
        if ($assigneeUserId === null || $assigneeUserId <= 0) {
            return ['ok' => false, 'error' => 'Aucun utilisateur actif rattaché à ce collaborateur.'];
        }

        $payload = self::buildTaskPayloadFromSchedule($eventPayload, $assigneeUserId);
        return self::createTask($pdo, $creatorUserId, $payload, [], false);
    }

    public static function updateTaskFromSchedule(PDO $pdo, int $taskId, int $actorUserId, int $personnelId, array $eventPayload): array
    {
        $assigneeUserId = self::getActiveUserIdByPersonnelId($pdo, $personnelId);
        if ($assigneeUserId === null || $assigneeUserId <= 0) {
            return ['ok' => false, 'error' => 'Aucun utilisateur actif rattaché à ce collaborateur.'];
        }

        $payload = self::buildTaskPayloadFromSchedule($eventPayload, $assigneeUserId);
        return self::updateTask($pdo, $taskId, $actorUserId, $payload, [], true, false);
    }

    public static function updateTaskStatusFromSchedule(PDO $pdo, int $taskId, int $actorUserId, string $statusCode): array
    {
        return self::updateTaskStatus($pdo, $taskId, self::mapScheduleStatusToTaskStatus($statusCode), $actorUserId, true, false, false);
    }

    private static function mapTaskStatusToScheduleStatus(string $status): string
    {
        return match ($status) {
            'in_progress' => 'en_cours',
            'done' => 'termine',
            'blocked' => 'pause',
            'cancelled' => 'annule',
            default => 'planifie',
        };
    }

    private static function mapScheduleStatusLabel(string $statusCode): string
    {
        return match ($statusCode) {
            'planifie' => 'Planifiee',
            'en_cours' => 'En cours',
            'pause' => 'En pause',
            'termine' => 'Terminee',
            'annule' => 'Annulee',
            default => $statusCode,
        };
    }

    private static function composeUserDisplay(string $prenom, string $nom, string $mail): string
    {
        $display = trim($prenom . ' ' . $nom);
        if ($display === '') {
            $display = $mail !== '' ? $mail : 'Utilisateur';
        }

        return $display;
    }
}
