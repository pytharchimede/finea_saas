<?php

require_once __DIR__ . '/../model/PopupNotification.php';
require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';

class PopupNotificationRepository
{
    private PDO $pdo;
    private ?array $userColumns = null;
    private ?array $personnelColumns = null;
    private ?array $serviceColumns = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT "information",
                short_message VARCHAR(500) NULL,
                body TEXT NOT NULL,
                module_key VARCHAR(100) NULL,
                priority ENUM("low","normal","high","critical") NOT NULL DEFAULT "normal",
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                requires_read_validation TINYINT(1) NOT NULL DEFAULT 0,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                target_scope ENUM("all","users","services","module_access") NOT NULL DEFAULT "all",
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_popup_notifications_active (is_active, starts_at, ends_at),
                KEY idx_popup_notifications_type (type),
                KEY idx_popup_notifications_scope (target_scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notification_targets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                target_type ENUM("user","service","module_access") NOT NULL,
                target_value VARCHAR(150) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_popup_target_notification (notification_id),
                KEY idx_popup_target_type_value (target_type, target_value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notification_reads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip VARCHAR(45) NULL,
                user_agent TEXT NULL,
                UNIQUE KEY uq_popup_notification_user (notification_id, user_id),
                KEY idx_popup_reads_notification (notification_id),
                KEY idx_popup_reads_user (user_id),
                KEY idx_popup_reads_date (read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->addColumnIfMissing('finea_popup_notifications', 'requires_read_validation', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notification_questions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                question_text VARCHAR(500) NOT NULL,
                position INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_popup_questions_notification (notification_id),
                KEY idx_popup_questions_position (notification_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notification_question_choices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                question_id INT NOT NULL,
                choice_text VARCHAR(500) NOT NULL,
                is_correct TINYINT(1) NOT NULL DEFAULT 0,
                position INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_popup_choices_question (question_id),
                KEY idx_popup_choices_position (question_id, position)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_popup_notification_read_validations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                score INT NOT NULL DEFAULT 0,
                total_questions INT NOT NULL DEFAULT 0,
                attempts_count INT NOT NULL DEFAULT 0,
                validated_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_popup_validation_user (notification_id, user_id),
                KEY idx_popup_validation_notification (notification_id),
                KEY idx_popup_validation_user (user_id),
                KEY idx_popup_validation_success (is_success)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }


    private function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = :table
                  AND COLUMN_NAME = :column
            ");
            $stmt->execute([':table' => $table, ':column' => $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            }
        } catch (Throwable $e) {
            try {
                $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
            } catch (Throwable $ignored) {
            }
        }
    }

    public function listNotifications(array $filters = []): array
    {
        $where = [];
        $params = [];

        $type = PopupNotification::normalizeType((string) ($filters['type'] ?? ''));
        if (trim((string) ($filters['type'] ?? '')) !== '' && $type !== '') {
            $where[] = 'n.type = :type';
            $params[':type'] = $type;
        }

        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status === 'active') {
            $where[] = 'n.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'n.is_active = 0';
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 'n.created_at >= :date_from';
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 'n.created_at <= :date_to';
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->pdo->prepare('
            SELECT
                n.*,
                COUNT(DISTINCT r.id) AS reads_count,
                COUNT(DISTINCT resp.user_id) AS responses_count
            FROM finea_popup_notifications n
            LEFT JOIN finea_popup_notification_reads r ON r.notification_id = n.id
            LEFT JOIN finea_popup_notification_responses resp ON resp.notification_id = n.id
            ' . $whereSql . '
            GROUP BY n.id
            ORDER BY n.created_at DESC, n.id DESC
        ');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $targetsByNotification = $this->getTargetsByNotificationIds(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));

        foreach ($rows as &$row) {
            $row['targets'] = $targetsByNotification[(int) ($row['id'] ?? 0)] ?? [];
        }
        unset($row);

        return $rows;
    }

    public function getNotificationById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare("
        SELECT *
        FROM finea_popup_notifications
        WHERE id = :id
        LIMIT 1
    ");

        $stmt->execute([
            ':id' => $id
        ]);

        $notification = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$notification) {
            return null;
        }

        $notificationId = (int)$notification['id'];

        /*
    |--------------------------------------------------------------------------
    | Targets
    |--------------------------------------------------------------------------
    */

        $targetsStmt = $this->pdo->prepare("
        SELECT 
            id,
            notification_id,
            target_type,
            target_value
        FROM finea_popup_notification_targets
        WHERE notification_id = :notification_id
        ORDER BY id ASC
    ");

        $targetsStmt->execute([
            ':notification_id' => $notificationId
        ]);

        $targets = $targetsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $notification['targets'] = $targets;

        /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

        $actionsStmt = $this->pdo->prepare("
        SELECT 
            id,
            notification_id,
            label,
            action_type,
            action_value,
            sort_order,
            is_primary
        FROM finea_popup_notification_actions
        WHERE notification_id = :notification_id
        ORDER BY sort_order ASC, id ASC
    ");

        $actionsStmt->execute([
            ':notification_id' => $notificationId
        ]);

        $actions = $actionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $notification['actions'] = $actions;

        /*
    |--------------------------------------------------------------------------
    | Pré-remplissage formulaire
    |--------------------------------------------------------------------------
    */

        $notification['target_users'] = [];
        $notification['target_services'] = [];
        $notification['target_modules'] = [];

        foreach ($targets as $target) {

            $targetType = (string)($target['target_type'] ?? '');
            $targetValue = (string)($target['target_value'] ?? '');

            if ($targetType === 'user') {
                $notification['target_users'][] = $targetValue;
            }

            if ($targetType === 'service') {
                $notification['target_services'][] = $targetValue;
            }

            if ($targetType === 'module_access') {
                $notification['target_modules'][] = $targetValue;
            }
        }

        $notification['questions'] = $this->getQuestionsForNotification($notificationId, true);

        return $notification;
    }


    public function saveNotification(array $data, array $targets): int
    {
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            ':title' => trim((string) ($data['title'] ?? '')),
            ':type' => PopupNotification::normalizeType((string) ($data['type'] ?? 'information')),
            ':short_message' => $this->nullableString($data['short_message'] ?? null),
            ':body' => trim((string) ($data['body'] ?? '')),
            ':module_key' => $this->nullableString($data['module_key'] ?? null),
            ':priority' => PopupNotification::normalizePriority((string) ($data['priority'] ?? 'normal')),
            ':is_active' => !empty($data['is_active']) ? 1 : 0,
            ':requires_read_validation' => !empty($data['requires_read_validation']) ? 1 : 0,
            ':starts_at' => $this->normalizeDatetime($data['starts_at'] ?? null),
            ':ends_at' => $this->normalizeDatetime($data['ends_at'] ?? null),
            ':target_scope' => PopupNotification::normalizeTargetScope((string) ($data['target_scope'] ?? 'all')),
            ':created_by' => isset($data['created_by']) && (int) $data['created_by'] > 0 ? (int) $data['created_by'] : null,
        ];

        if ($payload[':title'] === '') {
            throw new RuntimeException('Le titre est obligatoire.');
        }
        if ($payload[':body'] === '') {
            throw new RuntimeException('La description détaillée est obligatoire.');
        }

        $this->pdo->beginTransaction();
        try {
            if ($id > 0) {
                $stmt = $this->pdo->prepare('
                    UPDATE finea_popup_notifications
                    SET
                        title = :title,
                        type = :type,
                        short_message = :short_message,
                        body = :body,
                        module_key = :module_key,
                        priority = :priority,
                        is_active = :is_active,
                        requires_read_validation = :requires_read_validation,
                        starts_at = :starts_at,
                        ends_at = :ends_at,
                        target_scope = :target_scope
                    WHERE id = :id
                ');
                $stmt->execute($payload + [':id' => $id]);
            } else {
                $stmt = $this->pdo->prepare('
                    INSERT INTO finea_popup_notifications (
                        title, type, short_message, body, module_key, priority,
                        is_active, requires_read_validation, starts_at, ends_at, target_scope, created_by
                    ) VALUES (
                        :title, :type, :short_message, :body, :module_key, :priority,
                        :is_active, :requires_read_validation, :starts_at, :ends_at, :target_scope, :created_by
                    )
                ');
                $stmt->execute($payload);
                $id = (int) $this->pdo->lastInsertId();
            }

            $deleteStmt = $this->pdo->prepare('DELETE FROM finea_popup_notification_targets WHERE notification_id = :notification_id');
            $deleteStmt->execute([':notification_id' => $id]);

            if ($targets) {
                $insertStmt = $this->pdo->prepare('
                    INSERT INTO finea_popup_notification_targets (notification_id, target_type, target_value)
                    VALUES (:notification_id, :target_type, :target_value)
                ');
                foreach ($targets as $target) {
                    $insertStmt->execute([
                        ':notification_id' => $id,
                        ':target_type' => (string) ($target['target_type'] ?? ''),
                        ':target_value' => (string) ($target['target_value'] ?? ''),
                    ]);
                }
            }
            $deleteActionsStmt = $this->pdo->prepare('
                DELETE FROM finea_popup_notification_actions
                WHERE notification_id = :notification_id
            ');
            $deleteActionsStmt->execute([':notification_id' => $id]);

            $actions = is_array($data['actions'] ?? null) ? $data['actions'] : [];

            if ($actions) {
                $insertActionStmt = $this->pdo->prepare('
                    INSERT INTO finea_popup_notification_actions (
                        notification_id,
                        label,
                        action_type,
                        action_value,
                        sort_order,
                        is_primary
                    ) VALUES (
                        :notification_id,
                        :label,
                        :action_type,
                        :action_value,
                        :sort_order,
                        :is_primary
                    )
                ');

                $sortOrder = 0;

                foreach ($actions as $action) {
                    $label = trim((string)($action['label'] ?? ''));

                    if ($label === '') {
                        continue;
                    }

                    $actionType = (string)($action['action_type'] ?? 'ack');

                    if (!in_array($actionType, ['link', 'ack', 'survey_choice', 'custom'], true)) {
                        $actionType = 'ack';
                    }

                    $insertActionStmt->execute([
                        ':notification_id' => $id,
                        ':label' => $label,
                        ':action_type' => $actionType,
                        ':action_value' => $this->nullableString($action['action_value'] ?? null),
                        ':sort_order' => $sortOrder++,
                        ':is_primary' => !empty($action['is_primary']) ? 1 : 0,
                    ]);
                }
            }
            $this->replaceQuestionsForNotification($id, is_array($data['questions'] ?? null) ? $data['questions'] : []);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $id;
    }

    public function deleteNotification(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM finea_popup_notification_targets WHERE notification_id = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM finea_popup_notification_reads WHERE notification_id = :id')->execute([':id' => $id]);
            $this->pdo->prepare('DELETE FROM finea_popup_notifications WHERE id = :id')->execute([':id' => $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setActive(int $id, bool $isActive): void
    {
        $stmt = $this->pdo->prepare('UPDATE finea_popup_notifications SET is_active = :is_active WHERE id = :id');
        $stmt->execute([':is_active' => $isActive ? 1 : 0, ':id' => $id]);
    }

    public function getTargets(int $notificationId): array
    {
        if ($notificationId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare('
            SELECT id, notification_id, target_type, target_value, created_at
            FROM finea_popup_notification_targets
            WHERE notification_id = :notification_id
            ORDER BY id ASC
        ');
        $stmt->execute([':notification_id' => $notificationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getTargetsByNotificationIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('
            SELECT id, notification_id, target_type, target_value, created_at
            FROM finea_popup_notification_targets
            WHERE notification_id IN (' . $placeholders . ')
            ORDER BY id ASC
        ');
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) ($row['notification_id'] ?? 0)][] = $row;
        }
        return $grouped;
    }

    public function countReads(int $notificationId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM finea_popup_notification_reads WHERE notification_id = :notification_id');
        $stmt->execute([':notification_id' => $notificationId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function listReadReceipts(int $notificationId): array
    {
        $sql = '
            SELECT
                r.*,
                u.prenom,
                u.nom,
                u.mail_pro,
                ' . $this->userServiceIdSelect('u') . ' AS service_id,
                ' . $this->userServiceLabelSelect('u') . ' AS service_label
            FROM finea_popup_notification_reads r
            INNER JOIN user_devis_banamur u ON u.id = r.user_id
            ' . $this->userServiceJoins('u') . '
            WHERE r.notification_id = :notification_id
            ORDER BY r.read_at DESC, r.id DESC
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':notification_id' => $notificationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPendingNotificationForUser(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $ctx = $this->getUserContext($userId);
        if (!$ctx || empty($ctx['active'])) {
            return null;
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM finea_popup_notifications n
            WHERE n.is_active = 1
              AND (n.starts_at IS NULL OR n.starts_at <= NOW())
              AND (n.ends_at IS NULL OR n.ends_at >= NOW())
              AND NOT EXISTS (
                    SELECT 1
                    FROM finea_popup_notification_responses resp
                    WHERE resp.notification_id = n.id
                      AND resp.user_id = :user_id
              )
            ORDER BY FIELD(n.priority, "critical", "high", "normal", "low"), COALESCE(n.starts_at, n.created_at) DESC, n.id DESC
            LIMIT 100
        ');
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            return null;
        }

        $targets = $this->getTargetsByNotificationIds(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows));
        foreach ($rows as $row) {
            $row['targets'] = $targets[(int) ($row['id'] ?? 0)] ?? [];
            if ($this->notificationTargetsUser($row, $ctx)) {
                $notificationId = (int)$row['id'];
                $row['actions'] = $this->getActionsForNotification($notificationId);
                $row['read_validation'] = $this->getReadValidationForUser($notificationId, $userId);
                $row['questions'] = ((int)($row['requires_read_validation'] ?? 0) === 1 && empty($row['read_validation']['is_success']))
                    ? $this->getQuestionsForNotification($notificationId, false)
                    : [];
                return $row;
            }
        }

        return null;
    }

    public function getActionsForNotification(int $notificationId): array
    {
        $stmt = $this->pdo->prepare('
        SELECT 
            id,
            notification_id,
            label,
            action_type,
            action_value,
            sort_order,
            is_primary
        FROM finea_popup_notification_actions
        WHERE notification_id = :notification_id
        ORDER BY sort_order ASC, id ASC
    ');

        $stmt->execute([
            ':notification_id' => $notificationId
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function markAsRead(int $notificationId, int $userId, ?string $ip, ?string $userAgent): bool
    {
        if ($notificationId <= 0 || $userId <= 0) {
            return false;
        }
        $stmt = $this->pdo->prepare('
            INSERT INTO finea_popup_notification_reads (notification_id, user_id, ip, user_agent)
            VALUES (:notification_id, :user_id, :ip, :user_agent)
            ON DUPLICATE KEY UPDATE
                read_at = COALESCE(read_at, CURRENT_TIMESTAMP),
                ip = VALUES(ip),
                user_agent = VALUES(user_agent)
        ');
        return $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':ip' => $this->nullableString($ip),
            ':user_agent' => $this->nullableString($userAgent),
        ]);
    }

    public function saveResponse(
        int $notificationId,
        int $userId,
        ?int $actionId,
        ?string $responseValue,
        ?string $ip,
        ?string $userAgent
    ): bool {
        if ($notificationId <= 0 || $userId <= 0) {
            return false;
        }

        $notification = $this->getNotificationById($notificationId);
        if ($notification && (int)($notification['requires_read_validation'] ?? 0) === 1) {
            $validation = $this->getReadValidationForUser($notificationId, $userId);
            if (!$validation || (int)($validation['is_success'] ?? 0) !== 1) {
                throw new RuntimeException('Vous devez valider la lecture par QCM avant de répondre.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $existingStmt = $this->pdo->prepare(
                'SELECT id
                 FROM finea_popup_notification_responses
                 WHERE notification_id = :notification_id AND user_id = :user_id
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $existingStmt->execute([
                ':notification_id' => $notificationId,
                ':user_id' => $userId,
            ]);
            $existingId = (int) ($existingStmt->fetchColumn() ?: 0);

            $params = [
                ':notification_id' => $notificationId,
                ':user_id' => $userId,
                ':action_id' => $actionId && $actionId > 0 ? $actionId : null,
                ':response_value' => $this->nullableString($responseValue),
                ':ip' => $this->nullableString($ip),
                ':user_agent' => $this->nullableString($userAgent),
            ];

            if ($existingId > 0) {
                $stmt = $this->pdo->prepare(
                    'UPDATE finea_popup_notification_responses
                     SET action_id = :action_id,
                         response_value = :response_value,
                         ip = :ip,
                         user_agent = :user_agent,
                         created_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':action_id' => $params[':action_id'],
                    ':response_value' => $params[':response_value'],
                    ':ip' => $params[':ip'],
                    ':user_agent' => $params[':user_agent'],
                    ':id' => $existingId,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO finea_popup_notification_responses
                        (notification_id, user_id, action_id, response_value, ip, user_agent, created_at)
                     VALUES
                        (:notification_id, :user_id, :action_id, :response_value, :ip, :user_agent, NOW())'
                );
                $stmt->execute($params);
            }

            $this->markAsRead($notificationId, $userId, $ip, $userAgent);
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }


    public function getQuestionsForNotification(int $notificationId, bool $includeCorrect = false): array
    {
        if ($notificationId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT id, notification_id, question_text, position
            FROM finea_popup_notification_questions
            WHERE notification_id = :notification_id
            ORDER BY position ASC, id ASC
        ');
        $stmt->execute([':notification_id' => $notificationId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$questions) {
            return [];
        }

        $questionIds = array_map(static fn(array $q): int => (int)$q['id'], $questions);
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $choiceStmt = $this->pdo->prepare('
            SELECT id, question_id, choice_text, is_correct, position
            FROM finea_popup_notification_question_choices
            WHERE question_id IN (' . $placeholders . ')
            ORDER BY position ASC, id ASC
        ');
        $choiceStmt->execute($questionIds);
        $choicesByQuestion = [];

        foreach (($choiceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $choice) {
            if (!$includeCorrect) {
                unset($choice['is_correct']);
            }
            $choicesByQuestion[(int)$choice['question_id']][] = $choice;
        }

        foreach ($questions as &$question) {
            $question['choices'] = $choicesByQuestion[(int)$question['id']] ?? [];
        }
        unset($question);

        return $questions;
    }

    private function replaceQuestionsForNotification(int $notificationId, array $questions): void
    {
        $existing = $this->getQuestionsForNotification($notificationId, true);
        $existingQuestionIds = array_map(static fn(array $q): int => (int)$q['id'], $existing);

        if ($existingQuestionIds) {
            $placeholders = implode(',', array_fill(0, count($existingQuestionIds), '?'));
            $choiceDelete = $this->pdo->prepare('DELETE FROM finea_popup_notification_question_choices WHERE question_id IN (' . $placeholders . ')');
            $choiceDelete->execute($existingQuestionIds);
        }

        $deleteQuestions = $this->pdo->prepare('DELETE FROM finea_popup_notification_questions WHERE notification_id = :notification_id');
        $deleteQuestions->execute([':notification_id' => $notificationId]);

        if (!$questions) {
            return;
        }

        $insertQuestion = $this->pdo->prepare('
            INSERT INTO finea_popup_notification_questions (notification_id, question_text, position)
            VALUES (:notification_id, :question_text, :position)
        ');
        $insertChoice = $this->pdo->prepare('
            INSERT INTO finea_popup_notification_question_choices (question_id, choice_text, is_correct, position)
            VALUES (:question_id, :choice_text, :is_correct, :position)
        ');

        foreach ($questions as $qIndex => $question) {
            $questionText = trim((string)($question['question_text'] ?? ''));
            if ($questionText === '') {
                continue;
            }

            $insertQuestion->execute([
                ':notification_id' => $notificationId,
                ':question_text' => $questionText,
                ':position' => $qIndex + 1,
            ]);
            $questionId = (int)$this->pdo->lastInsertId();

            $choices = is_array($question['choices'] ?? null) ? $question['choices'] : [];
            foreach ($choices as $cIndex => $choice) {
                $choiceText = trim((string)($choice['choice_text'] ?? ''));
                if ($choiceText === '') {
                    continue;
                }
                $insertChoice->execute([
                    ':question_id' => $questionId,
                    ':choice_text' => $choiceText,
                    ':is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                    ':position' => $cIndex + 1,
                ]);
            }
        }
    }

    public function getReadValidationForUser(int $notificationId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM finea_popup_notification_read_validations
            WHERE notification_id = :notification_id
              AND user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute([':notification_id' => $notificationId, ':user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function saveReadValidationAttempt(int $notificationId, int $userId, int $score, int $totalQuestions, bool $isSuccess): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO finea_popup_notification_read_validations
                (notification_id, user_id, is_success, score, total_questions, attempts_count, validated_at)
            VALUES
                (:notification_id, :user_id, :is_success, :score, :total_questions, 1, :validated_at)
            ON DUPLICATE KEY UPDATE
                is_success = GREATEST(is_success, VALUES(is_success)),
                score = GREATEST(score, VALUES(score)),
                total_questions = VALUES(total_questions),
                attempts_count = attempts_count + 1,
                validated_at = CASE
                    WHEN is_success = 1 THEN COALESCE(validated_at, NOW())
                    WHEN VALUES(is_success) = 1 THEN NOW()
                    ELSE validated_at
                END,
                updated_at = NOW()
        ');
        return $stmt->execute([
            ':notification_id' => $notificationId,
            ':user_id' => $userId,
            ':is_success' => $isSuccess ? 1 : 0,
            ':score' => $score,
            ':total_questions' => $totalQuestions,
            ':validated_at' => $isSuccess ? date('Y-m-d H:i:s') : null,
        ]);
    }

    public function getReadValidationStats(int $notificationId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                v.*,
                u.nom,
                u.prenom,
                u.mail_pro
            
            FROM finea_popup_notification_read_validations v
            LEFT JOIN user_devis_banamur u ON u.id = v.user_id
            WHERE v.notification_id = :notification_id
            ORDER BY v.updated_at DESC, v.created_at DESC
        ');
        $stmt->execute([':notification_id' => $notificationId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function userCanReadNotification(int $notificationId, int $userId): bool
    {
        $notification = $this->getNotificationById($notificationId);
        if (!$notification) {
            return false;
        }
        $ctx = $this->getUserContext($userId);
        if (!$ctx || empty($ctx['active'])) {
            return false;
        }
        return $this->notificationTargetsUser($notification, $ctx);
    }

    public function getAudienceUsersForNotification(array $notification): array
    {
        $users = $this->getActiveUsers();
        $out = [];
        foreach ($users as $user) {
            if ($this->notificationTargetsUser($notification, $user)) {
                $out[] = $user;
            }
        }
        return $out;
    }

    public function getActiveUsers(): array
    {
        $sql = '
            SELECT
                u.*,
                ' . $this->userServiceIdSelect('u') . ' AS service_id,
                ' . $this->userServiceLabelSelect('u') . ' AS service_label
            FROM user_devis_banamur u
            ' . $this->userServiceJoins('u') . '
            WHERE u.active = 1
            ORDER BY u.prenom ASC, u.nom ASC, u.mail_pro ASC, u.id ASC
        ';
        $stmt = $this->pdo->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function getActiveUsersForSelection(): array
    {
        $rows = $this->getActiveUsers();
        foreach ($rows as &$row) {
            $fullName = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? ''));
            $serviceLabel = trim((string) ($row['service_label'] ?? ''));
            $parts = [$fullName !== '' ? $fullName : ('Utilisateur #' . (int) ($row['id'] ?? 0))];
            $email = trim((string) ($row['mail_pro'] ?? ''));
            if ($email !== '') {
                $parts[] = $email;
            }
            if ($serviceLabel !== '') {
                $parts[] = $serviceLabel;
            }
            $row['display_label'] = implode(' - ', $parts);
        }
        unset($row);
        return $rows;
    }

    public function getAvailableServices(): array
    {
        if ($this->tableExists('service') && $this->columnExists('service', 'id_service') && $this->columnExists('service', 'lib_service')) {
            $stmt = $this->pdo->query('SELECT id_service, lib_service FROM service ORDER BY lib_service ASC');
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $out = [];
            foreach ($rows as $row) {
                $id = (int) ($row['id_service'] ?? 0);
                $label = trim((string) ($row['lib_service'] ?? ''));
                if ($id > 0 && $label !== '') {
                    $out[] = ['value' => (string) $id, 'label' => $label];
                }
            }
            if ($out) {
                return $out;
            }
        }

        if ($this->columnExists('user_devis_banamur', 'service')) {
            $stmt = $this->pdo->query('SELECT DISTINCT TRIM(service) AS label FROM user_devis_banamur WHERE service IS NOT NULL AND TRIM(service) <> "" ORDER BY service ASC');
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            $out = [];
            foreach ($rows as $row) {
                $label = trim((string) ($row['label'] ?? ''));
                if ($label !== '') {
                    $out[] = ['value' => $label, 'label' => $label];
                }
            }
            return $out;
        }

        $distinct = [];
        foreach ($this->getActiveUsers() as $user) {
            $id = (int) ($user['service_id'] ?? 0);
            $label = trim((string) ($user['service_label'] ?? ''));
            if ($id > 0) {
                $distinct[$id] = ['value' => (string) $id, 'label' => $label !== '' ? $label : ('Service #' . $id)];
            } elseif ($label !== '') {
                $distinct[strtolower($label)] = ['value' => $label, 'label' => $label];
            }
        }
        return array_values($distinct);
    }

    public function getAvailableModules(): array
    {
        $manager = new PortalModuleManager($this->pdo);
        return $manager->listActive();
    }

    private function getUserContext(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $sql = '
            SELECT
                u.*,
                ' . $this->userServiceIdSelect('u') . ' AS service_id,
                ' . $this->userServiceLabelSelect('u') . ' AS service_label
            FROM user_devis_banamur u
            ' . $this->userServiceJoins('u') . '
            WHERE u.id = :id
            LIMIT 1
        ';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function notificationTargetsUser(array $notification, array $user): bool
    {
        if ((int) ($user['active'] ?? 0) !== 1) {
            return false;
        }

        $scope = PopupNotification::normalizeTargetScope((string) ($notification['target_scope'] ?? 'all'));
        $targets = is_array($notification['targets'] ?? null) ? (array) $notification['targets'] : [];

        if ($scope === 'all') {
            return true;
        }

        if ($scope === 'users') {
            foreach ($targets as $target) {
                if ((string) ($target['target_type'] ?? '') !== 'user') {
                    continue;
                }
                if ((int) ($target['target_value'] ?? 0) === (int) ($user['id'] ?? 0)) {
                    return true;
                }
            }
            return false;
        }

        if ($scope === 'services') {
            $tokens = $this->buildUserServiceTokens($user);
            foreach ($targets as $target) {
                if ((string) ($target['target_type'] ?? '') !== 'service') {
                    continue;
                }
                $raw = trim((string) ($target['target_value'] ?? ''));
                if ($raw === '') {
                    continue;
                }
                if (in_array($raw, $tokens, true) || in_array(mb_strtolower($raw, 'UTF-8'), $tokens, true)) {
                    return true;
                }
            }
            return false;
        }

        if ($scope === 'module_access') {
            foreach ($targets as $target) {
                if ((string) ($target['target_type'] ?? '') !== 'module_access') {
                    continue;
                }
                if ($this->userHasModuleAccess($user, (string) ($target['target_value'] ?? ''))) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    private function userHasModuleAccess(array $user, string $moduleKey): bool
    {
        $moduleKey = trim($moduleKey);
        if ($moduleKey === '') {
            return false;
        }

        $manager = new PortalModuleManager($this->pdo);
        $modules = $manager->listAll();
        foreach ($modules as $module) {
            if ((string) ($module['module_key'] ?? '') !== $moduleKey || empty($module['is_active'])) {
                continue;
            }
            $permissions = (array) ($module['required_permissions'] ?? []);
            if (!$permissions) {
                return true;
            }
            foreach ($permissions as $permission) {
                if (!empty($user[$permission])) {
                    return true;
                }
            }
            return false;
        }

        if (!empty($user[$moduleKey])) {
            return true;
        }

        return false;
    }

    private function buildUserServiceTokens(array $user): array
    {
        $tokens = [];
        $serviceId = (int) ($user['service_id'] ?? 0);
        if ($serviceId > 0) {
            $tokens[] = (string) $serviceId;
            $tokens[] = 'id:' . $serviceId;
        }

        $serviceLabel = trim((string) ($user['service_label'] ?? ''));
        if ($serviceLabel !== '') {
            $tokens[] = $serviceLabel;
            if (function_exists('mb_strtolower')) {
                $tokens[] = mb_strtolower($serviceLabel, 'UTF-8');
            } else {
                $tokens[] = strtolower($serviceLabel);
            }
        }

        if ($this->columnExists('user_devis_banamur', 'service')) {
            $raw = trim((string) ($user['service'] ?? ''));
            if ($raw !== '') {
                $tokens[] = $raw;
                $tokens[] = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
            }
        }

        return array_values(array_unique(array_filter($tokens, static fn(string $value): bool => $value !== '')));
    }

    private function userServiceJoins(string $alias): string
    {
        if (
            $this->columnExists('user_devis_banamur', 'personnel_id')
            && $this->tableExists('personnel')
            && $this->columnExists('personnel', 'id_personnel_soignant')
        ) {
            $join = ' LEFT JOIN personnel p_popup ON p_popup.id_personnel_soignant = ' . $alias . '.personnel_id';
            if ($this->tableExists('service') && $this->columnExists('personnel', 'service_id') && $this->columnExists('service', 'id_service')) {
                $join .= ' LEFT JOIN service s_popup ON s_popup.id_service = p_popup.service_id';
            }
            return $join;
        }
        return '';
    }

    private function userServiceIdSelect(string $alias): string
    {
        if (
            $this->columnExists('user_devis_banamur', 'personnel_id')
            && $this->tableExists('personnel')
            && $this->columnExists('personnel', 'service_id')
        ) {
            return 'COALESCE(p_popup.service_id, 0)';
        }
        if ($this->columnExists('user_devis_banamur', 'service_id')) {
            return 'COALESCE(' . $alias . '.service_id, 0)';
        }
        return '0';
    }

    private function userServiceLabelSelect(string $alias): string
    {
        if ($this->tableExists('service') && $this->columnExists('service', 'lib_service') && $this->columnExists('user_devis_banamur', 'personnel_id')) {
            return 'COALESCE(NULLIF(TRIM(s_popup.lib_service), ""), "")';
        }
        if ($this->columnExists('user_devis_banamur', 'service')) {
            return 'COALESCE(NULLIF(TRIM(' . $alias . '.service), ""), "")';
        }
        return '""';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table');
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheProperty = match ($table) {
            'user_devis_banamur' => 'userColumns',
            'personnel' => 'personnelColumns',
            'service' => 'serviceColumns',
            default => null,
        };

        if ($cacheProperty !== null && is_array($this->{$cacheProperty})) {
            return in_array($column, $this->{$cacheProperty}, true);
        }

        try {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
            $columns = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
            if ($cacheProperty !== null) {
                $this->{$cacheProperty} = $columns;
            }
            return in_array($column, $columns, true);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    private function normalizeDatetime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            return $value . ':00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        return null;
    }

    public function getNotificationReads(int $notificationId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT 
            r.*,
            u.nom,
            u.prenom,
            u.mail_pro AS email,
            u.personnel_id,
            p.service_id
        FROM finea_popup_notification_reads r
        LEFT JOIN user_devis_banamur u ON u.id = r.user_id
        LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
        WHERE r.notification_id = :id
        ORDER BY r.read_at DESC
    ");

        $stmt->execute([':id' => $notificationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNotificationResponses(int $notificationId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT 
            resp.*,
            a.label AS action_label,
            a.action_type,
            u.nom,
            u.prenom,
            u.mail_pro AS email,
            u.personnel_id,
            p.service_id
        FROM finea_popup_notification_responses resp
        LEFT JOIN finea_popup_notification_actions a ON a.id = resp.action_id
        LEFT JOIN user_devis_banamur u ON u.id = resp.user_id
        LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
        WHERE resp.notification_id = :id
          AND NOT EXISTS (
              SELECT 1
              FROM finea_popup_notification_responses newer
              WHERE newer.notification_id = resp.notification_id
                AND newer.user_id = resp.user_id
                AND newer.id > resp.id
          )
        ORDER BY resp.created_at DESC
    ");

        $stmt->execute([':id' => $notificationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNotificationActionStats(int $notificationId): array
    {
        $stmt = $this->pdo->prepare("
        SELECT 
            a.id,
            a.label,
            a.action_type,
            a.action_value,
            COUNT(resp.id) AS total_responses
        FROM finea_popup_notification_actions a
        LEFT JOIN finea_popup_notification_responses resp
          ON resp.action_id = a.id
         AND NOT EXISTS (
              SELECT 1
              FROM finea_popup_notification_responses newer
              WHERE newer.notification_id = resp.notification_id
                AND newer.user_id = resp.user_id
                AND newer.id > resp.id
         )
        WHERE a.notification_id = :id
        GROUP BY a.id, a.label, a.action_type, a.action_value
        ORDER BY a.sort_order ASC, a.id ASC
    ");

        $stmt->execute([':id' => $notificationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countTargetedUsers(array $notification): int
    {
        $scope = (string)($notification['target_scope'] ?? 'all');

        if ($scope === 'all') {
            return (int)$this->pdo
                ->query("SELECT COUNT(*) FROM user_devis_banamur WHERE active = 1")
                ->fetchColumn();
        }

        if ($scope === 'users') {
            $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT target_value)
            FROM finea_popup_notification_targets
            WHERE notification_id = :id
            AND target_type = 'user'
        ");

            $stmt->execute([':id' => (int)$notification['id']]);
            return (int)$stmt->fetchColumn();
        }

        return count($this->getAudienceUsersForNotification($notification));
    }
}
