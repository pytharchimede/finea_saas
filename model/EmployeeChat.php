<?php

class EmployeeChat
{
    private static bool $schemaEnsured = false;
    private const TYPING_TTL_SECONDS = 12;
    private const THREAD_TYPE_PEER = 'peer';
    private const THREAD_TYPE_GROUP = 'group';
    private const GROUP_TYPE_CUSTOM = 'custom';
    private const GROUP_TYPE_SERVICE = 'service';

    public static function ensureTables(PDO $pdo): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        self::$schemaEnsured = true;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_user_id INT NOT NULL,
                recipient_user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                INDEX idx_employee_chat_pair (sender_user_id, recipient_user_id, id),
                INDEX idx_employee_chat_recipient_read (recipient_user_id, read_at, id),
                INDEX idx_employee_chat_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                uploaded_by_user_id INT NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_employee_chat_attachments_message (message_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_typing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sender_user_id INT NOT NULL,
                recipient_user_id INT NOT NULL,
                is_typing TINYINT(1) NOT NULL DEFAULT 1,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_employee_chat_typing_pair (sender_user_id, recipient_user_id),
                INDEX idx_employee_chat_typing_recipient (recipient_user_id, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_groups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                group_type VARCHAR(20) NOT NULL DEFAULT 'custom',
                service_id INT NULL,
                created_by_user_id INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_employee_chat_groups_service (service_id),
                INDEX idx_employee_chat_groups_type (group_type, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_group_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                user_id INT NOT NULL,
                added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_employee_chat_group_members_pair (group_id, user_id),
                INDEX idx_employee_chat_group_members_user (user_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_group_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                sender_user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_employee_chat_group_messages_group (group_id, id),
                INDEX idx_employee_chat_group_messages_sender (sender_user_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_group_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                uploaded_by_user_id INT NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_employee_chat_group_attachments_message (message_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS employee_chat_group_reads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                group_id INT NOT NULL,
                user_id INT NOT NULL,
                last_read_message_id INT NOT NULL DEFAULT 0,
                last_read_at DATETIME NULL,
                UNIQUE KEY uq_employee_chat_group_reads_pair (group_id, user_id),
                INDEX idx_employee_chat_group_reads_user (user_id, group_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        self::ensureServiceGroups($pdo);
    }

    private static function ensureServiceGroups(PDO $pdo): void
    {
        $stmt = $pdo->query(
            "SELECT DISTINCT
                    p.service_id,
                    COALESCE(NULLIF(TRIM(s.lib_service), ''), CONCAT('Service #', p.service_id)) AS service_label
             FROM user_devis_banamur u
             INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
             LEFT JOIN service s ON s.id_service = p.service_id
             WHERE u.active = 1 AND p.service_id IS NOT NULL AND p.service_id > 0"
        );
        $serviceRows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if (!$serviceRows) {
            return;
        }

        $groupByService = [];
        $existingStmt = $pdo->query("SELECT id, service_id FROM employee_chat_groups WHERE group_type = 'service' AND service_id IS NOT NULL");
        foreach (($existingStmt ? $existingStmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
            $groupByService[(int) ($row['service_id'] ?? 0)] = (int) ($row['id'] ?? 0);
        }

        $insertGroupStmt = $pdo->prepare(
            "INSERT INTO employee_chat_groups (name, group_type, service_id, created_by_user_id, created_at, updated_at)
             VALUES (:name, 'service', :service_id, NULL, NOW(), NOW())"
        );
        $updateGroupStmt = $pdo->prepare(
            'UPDATE employee_chat_groups SET name = :name, updated_at = NOW() WHERE id = :group_id'
        );
        $insertMemberStmt = $pdo->prepare(
            'INSERT IGNORE INTO employee_chat_group_members (group_id, user_id, added_at) VALUES (:group_id, :user_id, NOW())'
        );
        $deleteMemberStmt = $pdo->prepare(
            'DELETE FROM employee_chat_group_members WHERE group_id = :group_id AND user_id = :user_id'
        );
        $serviceUserStmt = $pdo->prepare(
            "SELECT u.id
             FROM user_devis_banamur u
             INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
             WHERE u.active = 1 AND p.service_id = :service_id"
        );
        $memberListStmt = $pdo->prepare('SELECT user_id FROM employee_chat_group_members WHERE group_id = :group_id');

        foreach ($serviceRows as $serviceRow) {
            $serviceId = (int) ($serviceRow['service_id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $name = trim((string) ($serviceRow['service_label'] ?? ''));
            if ($name === '') {
                $name = 'Service #' . $serviceId;
            }

            $groupId = $groupByService[$serviceId] ?? 0;
            if ($groupId <= 0) {
                $insertGroupStmt->execute([
                    'name' => $name,
                    'service_id' => $serviceId,
                ]);
                $groupId = (int) $pdo->lastInsertId();
                $groupByService[$serviceId] = $groupId;
            } else {
                $updateGroupStmt->execute([
                    'name' => $name,
                    'group_id' => $groupId,
                ]);
            }

            $serviceUserStmt->execute(['service_id' => $serviceId]);
            $memberIds = array_values(array_unique(array_map('intval', $serviceUserStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            foreach ($memberIds as $memberId) {
                if ($memberId > 0) {
                    $insertMemberStmt->execute([
                        'group_id' => $groupId,
                        'user_id' => $memberId,
                    ]);
                }
            }

            $memberListStmt->execute(['group_id' => $groupId]);
            $existingMembers = array_values(array_unique(array_map('intval', $memberListStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
            foreach ($existingMembers as $existingMemberId) {
                if (!in_array($existingMemberId, $memberIds, true)) {
                    $deleteMemberStmt->execute([
                        'group_id' => $groupId,
                        'user_id' => $existingMemberId,
                    ]);
                }
            }
        }
    }

    public static function listAvailableUsers(PDO $pdo, int $currentUserId): array
    {
        $stmt = $pdo->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                    COALESCE(NULLIF(TRIM(u.photo), ''), '') AS photo,
                    COALESCE(p.service_id, 0) AS service_id,
                    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS service_label
             FROM user_devis_banamur u
             LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
             LEFT JOIN service s ON s.id_service = p.service_id
             WHERE u.active = 1 AND u.id <> :current_user_id
             ORDER BY u.prenom ASC, u.nom ASC, u.id ASC"
        );
        $stmt->execute(['current_user_id' => $currentUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row = self::normalizeUserRow($row, 'id');
        }
        unset($row);

        return $rows;
    }

    public static function getUserIdentity(PDO $pdo, int $userId): array
    {
        if ($userId <= 0) {
            return [
                'id' => 0,
                'display' => 'Utilisateur',
                'initials' => 'BA',
                'mail_pro' => '',
                'photo' => '',
                'photo_url' => '',
                'service_id' => 0,
                'service_label' => '',
            ];
        }

        $stmt = $pdo->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                    COALESCE(NULLIF(TRIM(u.photo), ''), '') AS photo,
                    COALESCE(p.service_id, 0) AS service_id,
                    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS service_label
             FROM user_devis_banamur u
             LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
             LEFT JOIN service s ON s.id_service = p.service_id
             WHERE u.id = :user_id
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['id' => $userId];

        return self::normalizeUserRow($row, 'id');
    }

    private static function normalizeUserRow(array $row, string $idField): array
    {
        $userId = (int) ($row[$idField] ?? 0);
        $display = trim((string) ($row['prenom'] ?? '') . ' ' . (string) ($row['nom'] ?? ''));
        if ($display === '') {
            $display = trim((string) ($row['mail_pro'] ?? ''));
        }
        if ($display === '') {
            $display = 'Utilisateur #' . $userId;
        }

        return [
            'id' => $userId,
            'display' => $display,
            'initials' => self::makeInitials($display),
            'mail_pro' => trim((string) ($row['mail_pro'] ?? '')),
            'photo' => trim((string) ($row['photo'] ?? '')),
            'photo_url' => self::resolveUserPhotoPath((string) ($row['photo'] ?? '')),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'service_label' => trim((string) ($row['service_label'] ?? '')),
        ];
    }

    private static function makeInitials(string $label): string
    {
        $parts = preg_split('/\s+/', trim($label)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) >= 2) {
                break;
            }
        }

        return $initials !== '' ? $initials : 'BA';
    }

    private static function resolveUserPhotoPath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('~^(https?:|data:|/)~i', $raw)) {
            return $raw;
        }

        $projectRoot = realpath(__DIR__ . '/..');
        if (!$projectRoot) {
            return $raw;
        }

        $normalized = ltrim(str_replace('\\', '/', $raw), '/');
        $candidates = [
            $normalized,
            'photo/' . $normalized,
            'uploads/' . $normalized,
            'uploads/rh/' . $normalized,
            'uploads/photo/' . $normalized,
            'uploads/rh/photo/' . $normalized,
            'storage/' . $normalized,
        ];
        foreach ($candidates as $candidate) {
            $absolute = $projectRoot . '/' . ltrim($candidate, '/');
            if (is_file($absolute)) {
                return ltrim(str_replace('\\', '/', $candidate), '/');
            }
        }

        $found = self::findStoredMedia($normalized, $projectRoot);
        return $found ?? '';
    }

    private static function findStoredMedia(string $storedName, string $projectRoot): ?string
    {
        static $indexedFiles = null;

        if ($indexedFiles === null) {
            $indexedFiles = [];
            $searchRoots = [
                $projectRoot . '/uploads/rh',
                $projectRoot . '/uploads',
                $projectRoot . '/photo',
                $projectRoot . '/img',
                $projectRoot . '/storage',
            ];

            foreach ($searchRoots as $root) {
                if (!is_dir($root)) {
                    continue;
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
                );

                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile()) {
                        continue;
                    }

                    $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
                    $relativePath = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(str_replace('\\', '/', $projectRoot)))), '/');
                    $fileName = strtolower($fileInfo->getFilename());
                    $baseName = strtolower(pathinfo($fileName, PATHINFO_FILENAME));

                    $indexedFiles['file:' . $fileName] = $relativePath;
                    if (!isset($indexedFiles['base:' . $baseName])) {
                        $indexedFiles['base:' . $baseName] = $relativePath;
                    }
                }
            }
        }

        $storedName = strtolower(trim(basename(str_replace('\\', '/', $storedName))));
        if ($storedName === '') {
            return null;
        }
        if (isset($indexedFiles['file:' . $storedName])) {
            return $indexedFiles['file:' . $storedName];
        }

        $storedBase = strtolower(pathinfo($storedName, PATHINFO_FILENAME));
        if (isset($indexedFiles['base:' . $storedBase])) {
            return $indexedFiles['base:' . $storedBase];
        }

        return null;
    }

    public static function listConversations(PDO $pdo, int $currentUserId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));

        $stmt = $pdo->prepare(
            "SELECT
                convo.peer_user_id,
                convo.last_message_id,
                convo.last_message_body,
                convo.last_message_at,
                convo.last_sender_user_id,
                convo.unread_count,
                COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                COALESCE(NULLIF(TRIM(u.photo), ''), '') AS photo,
                COALESCE(p.service_id, 0) AS service_id,
                COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS service_label
            FROM (
                SELECT
                    CASE
                        WHEN m.sender_user_id = :current_user_id THEN m.recipient_user_id
                        ELSE m.sender_user_id
                    END AS peer_user_id,
                    MAX(m.id) AS last_message_id,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(m.body ORDER BY m.id DESC SEPARATOR '\n---\n'),
                        '\n---\n',
                        1
                    ) AS last_message_body,
                    MAX(m.created_at) AS last_message_at,
                    SUBSTRING_INDEX(
                        GROUP_CONCAT(m.sender_user_id ORDER BY m.id DESC SEPARATOR ','),
                        ',',
                        1
                    ) AS last_sender_user_id,
                    SUM(CASE WHEN m.recipient_user_id = :current_user_id AND m.read_at IS NULL THEN 1 ELSE 0 END) AS unread_count
                FROM employee_chat_messages m
                WHERE m.sender_user_id = :current_user_id OR m.recipient_user_id = :current_user_id
                GROUP BY peer_user_id
            ) convo
            INNER JOIN user_devis_banamur u ON u.id = convo.peer_user_id
            LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            LEFT JOIN service s ON s.id_service = p.service_id
            ORDER BY convo.last_message_id DESC
            LIMIT " . (int) $limit
        );
        $stmt->execute(['current_user_id' => $currentUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $user = self::normalizeUserRow($row, 'peer_user_id');
            $row = [
                'thread_key' => self::THREAD_TYPE_PEER . ':' . $user['id'],
                'thread_type' => self::THREAD_TYPE_PEER,
                'thread_id' => $user['id'],
                'peer_user_id' => $user['id'],
                'display' => $user['display'],
                'initials' => $user['initials'],
                'mail_pro' => $user['mail_pro'],
                'photo_url' => $user['photo_url'],
                'service_id' => $user['service_id'],
                'service_label' => $user['service_label'],
                'member_count' => 2,
                'last_message_id' => (int) ($row['last_message_id'] ?? 0),
                'last_message_body' => (string) ($row['last_message_body'] ?? ''),
                'last_message_at' => (string) ($row['last_message_at'] ?? ''),
                'last_sender_user_id' => (int) ($row['last_sender_user_id'] ?? 0),
                'unread_count' => (int) ($row['unread_count'] ?? 0),
            ];
        }
        unset($row);

        return $rows;
    }

    public static function listGroups(PDO $pdo, int $currentUserId): array
    {
        $stmt = $pdo->prepare(
            "SELECT g.id,
                    g.name,
                    g.group_type,
                    g.service_id,
                    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS service_label,
                    COALESCE(msg.last_message_id, 0) AS last_message_id,
                    COALESCE(msg.last_message_body, '') AS last_message_body,
                    msg.last_message_at,
                    COALESCE(msg.last_sender_user_id, 0) AS last_sender_user_id,
                    COALESCE(member_stats.member_count, 0) AS member_count,
                    CASE
                        WHEN COALESCE(msg.last_message_id, 0) > COALESCE(gr.last_read_message_id, 0) THEN COALESCE(msg.last_message_id, 0) - COALESCE(gr.last_read_message_id, 0)
                        ELSE 0
                    END AS unread_hint
             FROM employee_chat_groups g
             INNER JOIN employee_chat_group_members gm_current
                 ON gm_current.group_id = g.id AND gm_current.user_id = :current_user_id
             LEFT JOIN service s ON s.id_service = g.service_id
             LEFT JOIN (
                 SELECT gm.group_id,
                        COUNT(*) AS member_count
                 FROM employee_chat_group_members gm
                 GROUP BY gm.group_id
             ) member_stats ON member_stats.group_id = g.id
             LEFT JOIN employee_chat_group_reads gr
                 ON gr.group_id = g.id AND gr.user_id = :current_user_id
             LEFT JOIN (
                 SELECT x.group_id,
                        x.id AS last_message_id,
                        x.body AS last_message_body,
                        x.created_at AS last_message_at,
                        x.sender_user_id AS last_sender_user_id
                 FROM employee_chat_group_messages x
                 INNER JOIN (
                     SELECT group_id, MAX(id) AS last_message_id
                     FROM employee_chat_group_messages
                     GROUP BY group_id
                 ) last_msg
                     ON last_msg.group_id = x.group_id AND last_msg.last_message_id = x.id
             ) msg ON msg.group_id = g.id
             ORDER BY CASE WHEN msg.last_message_id IS NULL THEN 1 ELSE 0 END ASC, msg.last_message_id DESC, g.name ASC"
        );
        $stmt->execute(['current_user_id' => $currentUserId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $display = trim((string) ($row['name'] ?? ''));
            if ($display === '') {
                $display = 'Groupe #' . (int) ($row['id'] ?? 0);
            }

            $row = [
                'thread_key' => self::THREAD_TYPE_GROUP . ':' . (int) ($row['id'] ?? 0),
                'thread_type' => self::THREAD_TYPE_GROUP,
                'thread_id' => (int) ($row['id'] ?? 0),
                'group_id' => (int) ($row['id'] ?? 0),
                'display' => $display,
                'initials' => self::makeInitials($display),
                'photo_url' => '',
                'group_type' => (string) ($row['group_type'] ?? self::GROUP_TYPE_CUSTOM),
                'service_id' => (int) ($row['service_id'] ?? 0),
                'service_label' => trim((string) ($row['service_label'] ?? '')),
                'member_count' => (int) ($row['member_count'] ?? 0),
                'last_message_id' => (int) ($row['last_message_id'] ?? 0),
                'last_message_body' => (string) ($row['last_message_body'] ?? ''),
                'last_message_at' => (string) ($row['last_message_at'] ?? ''),
                'last_sender_user_id' => (int) ($row['last_sender_user_id'] ?? 0),
                'unread_count' => max(0, (int) ($row['unread_hint'] ?? 0)),
            ];
        }
        unset($row);

        return $rows;
    }

    public static function listMessages(PDO $pdo, int $currentUserId, string $threadType, int $threadId, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array
    {
        if ($threadType === self::THREAD_TYPE_GROUP) {
            return self::listGroupMessages($pdo, $currentUserId, $threadId, $limit, $beforeId, $afterId);
        }

        return self::listDirectMessages($pdo, $currentUserId, $threadId, $limit, $beforeId, $afterId);
    }

    private static function listDirectMessages(PDO $pdo, int $currentUserId, int $peerUserId, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array
    {
        if ($currentUserId <= 0 || $peerUserId <= 0 || $currentUserId === $peerUserId) {
            return ['messages' => [], 'has_more' => false];
        }

        $limit = max(1, min(100, $limit));
        $params = [
            'current_user_id' => $currentUserId,
            'peer_user_id' => $peerUserId,
        ];
        $cursorSql = '';
        if ($beforeId !== null && $beforeId > 0) {
            $cursorSql = ' AND m.id < :before_id';
            $params['before_id'] = $beforeId;
        } elseif ($afterId !== null && $afterId > 0) {
            $cursorSql = ' AND m.id > :after_id';
            $params['after_id'] = $afterId;
        }

        $stmt = $pdo->prepare(
            "SELECT m.id, m.sender_user_id, m.recipient_user_id, m.body, m.created_at, m.read_at,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS sender_prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS sender_nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS sender_mail_pro,
                    COALESCE(NULLIF(TRIM(u.photo), ''), '') AS sender_photo
             FROM employee_chat_messages m
             INNER JOIN user_devis_banamur u ON u.id = m.sender_user_id
             WHERE ((m.sender_user_id = :current_user_id AND m.recipient_user_id = :peer_user_id)
                 OR (m.sender_user_id = :peer_user_id AND m.recipient_user_id = :current_user_id))
             $cursorSql
             ORDER BY m.id DESC
             LIMIT " . (int) ($limit + 1)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $rows = array_reverse($rows);

        foreach ($rows as &$row) {
            $sender = self::normalizeUserRow([
                'id' => (int) ($row['sender_user_id'] ?? 0),
                'prenom' => (string) ($row['sender_prenom'] ?? ''),
                'nom' => (string) ($row['sender_nom'] ?? ''),
                'mail_pro' => (string) ($row['sender_mail_pro'] ?? ''),
                'photo' => (string) ($row['sender_photo'] ?? ''),
            ], 'id');
            $row = [
                'id' => (int) ($row['id'] ?? 0),
                'thread_type' => self::THREAD_TYPE_PEER,
                'thread_id' => $peerUserId,
                'sender_user_id' => (int) ($row['sender_user_id'] ?? 0),
                'recipient_user_id' => (int) ($row['recipient_user_id'] ?? 0),
                'body' => (string) ($row['body'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'read_at' => (string) ($row['read_at'] ?? ''),
                'is_mine' => (int) ($row['sender_user_id'] ?? 0) === $currentUserId,
                'is_read' => !empty($row['read_at']),
                'sender_display' => $sender['display'],
                'sender_initials' => $sender['initials'],
                'sender_photo_url' => $sender['photo_url'],
                'attachments' => [],
                'attachment_scope' => self::THREAD_TYPE_PEER,
            ];
        }
        unset($row);

        self::hydrateAttachments($pdo, $rows, self::THREAD_TYPE_PEER);

        return ['messages' => $rows, 'has_more' => $hasMore];
    }

    private static function listGroupMessages(PDO $pdo, int $currentUserId, int $groupId, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): array
    {
        if (!self::userHasGroupAccess($pdo, $currentUserId, $groupId)) {
            return ['messages' => [], 'has_more' => false];
        }

        $limit = max(1, min(100, $limit));
        $params = [
            'current_user_id' => $currentUserId,
            'group_id' => $groupId,
        ];
        $cursorSql = '';
        if ($beforeId !== null && $beforeId > 0) {
            $cursorSql = ' AND gm.id < :before_id';
            $params['before_id'] = $beforeId;
        } elseif ($afterId !== null && $afterId > 0) {
            $cursorSql = ' AND gm.id > :after_id';
            $params['after_id'] = $afterId;
        }

        $stmt = $pdo->prepare(
            "SELECT gm.id, gm.group_id, gm.sender_user_id, gm.body, gm.created_at,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS sender_prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS sender_nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS sender_mail_pro,
                    COALESCE(NULLIF(TRIM(u.photo), ''), '') AS sender_photo
             FROM employee_chat_group_messages gm
             INNER JOIN employee_chat_group_members membership
                 ON membership.group_id = gm.group_id AND membership.user_id = :current_user_id
             INNER JOIN user_devis_banamur u ON u.id = gm.sender_user_id
             WHERE gm.group_id = :group_id
             $cursorSql
             ORDER BY gm.id DESC
             LIMIT " . (int) ($limit + 1)
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $rows = array_reverse($rows);

        foreach ($rows as &$row) {
            $sender = self::normalizeUserRow([
                'id' => (int) ($row['sender_user_id'] ?? 0),
                'prenom' => (string) ($row['sender_prenom'] ?? ''),
                'nom' => (string) ($row['sender_nom'] ?? ''),
                'mail_pro' => (string) ($row['sender_mail_pro'] ?? ''),
                'photo' => (string) ($row['sender_photo'] ?? ''),
            ], 'id');
            $row = [
                'id' => (int) ($row['id'] ?? 0),
                'thread_type' => self::THREAD_TYPE_GROUP,
                'thread_id' => $groupId,
                'sender_user_id' => (int) ($row['sender_user_id'] ?? 0),
                'body' => (string) ($row['body'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'is_mine' => (int) ($row['sender_user_id'] ?? 0) === $currentUserId,
                'is_read' => true,
                'sender_display' => $sender['display'],
                'sender_initials' => $sender['initials'],
                'sender_photo_url' => $sender['photo_url'],
                'attachments' => [],
                'attachment_scope' => self::THREAD_TYPE_GROUP,
            ];
        }
        unset($row);

        self::hydrateAttachments($pdo, $rows, self::THREAD_TYPE_GROUP);

        return ['messages' => $rows, 'has_more' => $hasMore];
    }

    private static function hydrateAttachments(PDO $pdo, array &$messages, string $scope): void
    {
        if (!$messages) {
            return;
        }

        $messageIds = array_values(array_unique(array_filter(array_map(static function (array $message): int {
            return (int) ($message['id'] ?? 0);
        }, $messages), static function (int $id): bool {
            return $id > 0;
        })));
        if (!$messageIds) {
            return;
        }

        $table = $scope === self::THREAD_TYPE_GROUP ? 'employee_chat_group_attachments' : 'employee_chat_attachments';
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $pdo->prepare(
            'SELECT id, message_id, stored_path, original_name, mime_type, file_size, created_at
             FROM ' . $table . '
             WHERE message_id IN (' . $placeholders . ')
             ORDER BY id ASC'
        );
        $stmt->execute($messageIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byMessage = [];
        foreach ($rows as $row) {
            $messageId = (int) ($row['message_id'] ?? 0);
            if ($messageId <= 0) {
                continue;
            }

            $byMessage[$messageId][] = [
                'id' => (int) ($row['id'] ?? 0),
                'message_id' => $messageId,
                'stored_path' => (string) ($row['stored_path'] ?? ''),
                'original_name' => (string) ($row['original_name'] ?? ''),
                'mime_type' => (string) ($row['mime_type'] ?? ''),
                'file_size' => (int) ($row['file_size'] ?? 0),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'scope' => $scope,
            ];
        }

        foreach ($messages as &$message) {
            $messageId = (int) ($message['id'] ?? 0);
            $message['attachments'] = $byMessage[$messageId] ?? [];
        }
        unset($message);
    }

    public static function sendMessage(PDO $pdo, string $threadType, int $senderUserId, int $threadId, string $body, array $attachments = []): array
    {
        if ($threadType === self::THREAD_TYPE_GROUP) {
            return self::sendGroupMessage($pdo, $senderUserId, $threadId, $body, $attachments);
        }

        return self::sendDirectMessage($pdo, $senderUserId, $threadId, $body, $attachments);
    }

    private static function sendDirectMessage(PDO $pdo, int $senderUserId, int $recipientUserId, string $body, array $attachments = []): array
    {
        $body = trim($body);
        if ($senderUserId <= 0 || $recipientUserId <= 0 || $senderUserId === $recipientUserId) {
            return ['ok' => false, 'error' => 'Conversation invalide.'];
        }
        if ($body === '' && !$attachments) {
            return ['ok' => false, 'error' => 'Le message est vide.'];
        }

        $body = mb_substr($body, 0, 4000);
        $stmtUser = $pdo->prepare('SELECT COUNT(*) FROM user_devis_banamur WHERE id = :id AND active = 1');
        $stmtUser->execute(['id' => $recipientUserId]);
        if ((int) $stmtUser->fetchColumn() <= 0) {
            return ['ok' => false, 'error' => 'Destinataire introuvable.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO employee_chat_messages (sender_user_id, recipient_user_id, body, created_at)
             VALUES (:sender_user_id, :recipient_user_id, :body, NOW())'
        );
        $stmt->execute([
            'sender_user_id' => $senderUserId,
            'recipient_user_id' => $recipientUserId,
            'body' => $body !== '' ? $body : '[Pièce jointe]',
        ]);

        $messageId = (int) $pdo->lastInsertId();
        if ($attachments) {
            self::storeAttachments($pdo, $messageId, $senderUserId, $attachments, self::THREAD_TYPE_PEER);
        }

        self::setTypingState($pdo, $senderUserId, $recipientUserId, false);
        $thread = self::listDirectMessages($pdo, $senderUserId, $recipientUserId, 1, null, $messageId - 1);

        return [
            'ok' => true,
            'message' => $thread['messages'][0] ?? null,
        ];
    }

    private static function sendGroupMessage(PDO $pdo, int $senderUserId, int $groupId, string $body, array $attachments = []): array
    {
        $body = trim($body);
        if ($senderUserId <= 0 || $groupId <= 0) {
            return ['ok' => false, 'error' => 'Groupe invalide.'];
        }
        if ($body === '' && !$attachments) {
            return ['ok' => false, 'error' => 'Le message est vide.'];
        }
        if (!self::userHasGroupAccess($pdo, $senderUserId, $groupId)) {
            return ['ok' => false, 'error' => 'Accès refusé à ce groupe.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO employee_chat_group_messages (group_id, sender_user_id, body, created_at)
             VALUES (:group_id, :sender_user_id, :body, NOW())'
        );
        $stmt->execute([
            'group_id' => $groupId,
            'sender_user_id' => $senderUserId,
            'body' => $body !== '' ? mb_substr($body, 0, 4000) : '[Pièce jointe]',
        ]);

        $messageId = (int) $pdo->lastInsertId();
        if ($attachments) {
            self::storeAttachments($pdo, $messageId, $senderUserId, $attachments, self::THREAD_TYPE_GROUP);
        }
        self::markGroupRead($pdo, $senderUserId, $groupId, $messageId);
        $thread = self::listGroupMessages($pdo, $senderUserId, $groupId, 1, null, $messageId - 1);

        return [
            'ok' => true,
            'message' => $thread['messages'][0] ?? null,
        ];
    }

    private static function storeAttachments(PDO $pdo, int $messageId, int $userId, array $attachments, string $scope): void
    {
        if ($messageId <= 0 || $userId <= 0 || !$attachments) {
            return;
        }

        $table = $scope === self::THREAD_TYPE_GROUP ? 'employee_chat_group_attachments' : 'employee_chat_attachments';
        $stmt = $pdo->prepare(
            'INSERT INTO ' . $table . ' (message_id, uploaded_by_user_id, stored_path, original_name, mime_type, file_size, created_at)
             VALUES (:message_id, :uploaded_by_user_id, :stored_path, :original_name, :mime_type, :file_size, NOW())'
        );

        foreach ($attachments as $attachment) {
            $stmt->execute([
                'message_id' => $messageId,
                'uploaded_by_user_id' => $userId,
                'stored_path' => (string) ($attachment['stored_rel'] ?? ''),
                'original_name' => mb_substr((string) ($attachment['original_name'] ?? 'fichier'), 0, 255),
                'mime_type' => mb_substr((string) ($attachment['mime'] ?? 'application/octet-stream'), 0, 100),
                'file_size' => (int) ($attachment['size'] ?? 0),
            ]);
        }
    }

    public static function markThreadRead(PDO $pdo, int $currentUserId, string $threadType, int $threadId, int $lastSeenMessageId = 0): int
    {
        if ($threadType === self::THREAD_TYPE_GROUP) {
            self::markGroupRead($pdo, $currentUserId, $threadId, $lastSeenMessageId);
            return 0;
        }

        return self::markConversationRead($pdo, $currentUserId, $threadId);
    }

    public static function markConversationRead(PDO $pdo, int $currentUserId, int $peerUserId): int
    {
        if ($currentUserId <= 0 || $peerUserId <= 0) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'UPDATE employee_chat_messages
             SET read_at = NOW()
             WHERE recipient_user_id = :current_user_id
               AND sender_user_id = :peer_user_id
               AND read_at IS NULL'
        );
        $stmt->execute([
            'current_user_id' => $currentUserId,
            'peer_user_id' => $peerUserId,
        ]);

        return (int) $stmt->rowCount();
    }

    public static function markGroupRead(PDO $pdo, int $currentUserId, int $groupId, int $lastSeenMessageId = 0): void
    {
        if ($currentUserId <= 0 || $groupId <= 0 || !self::userHasGroupAccess($pdo, $currentUserId, $groupId)) {
            return;
        }

        if ($lastSeenMessageId <= 0) {
            $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(id), 0) FROM employee_chat_group_messages WHERE group_id = :group_id');
            $stmtMax->execute(['group_id' => $groupId]);
            $lastSeenMessageId = (int) $stmtMax->fetchColumn();
        }

        $stmt = $pdo->prepare(
            'INSERT INTO employee_chat_group_reads (group_id, user_id, last_read_message_id, last_read_at)
             VALUES (:group_id, :user_id, :last_read_message_id, NOW())
             ON DUPLICATE KEY UPDATE
                 last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                 last_read_at = NOW()'
        );
        $stmt->execute([
            'group_id' => $groupId,
            'user_id' => $currentUserId,
            'last_read_message_id' => $lastSeenMessageId,
        ]);
    }

    private static function userHasGroupAccess(PDO $pdo, int $userId, int $groupId): bool
    {
        if ($userId <= 0 || $groupId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM employee_chat_group_members
             WHERE group_id = :group_id AND user_id = :user_id'
        );
        $stmt->execute([
            'group_id' => $groupId,
            'user_id' => $userId,
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public static function countUnread(PDO $pdo, int $currentUserId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM employee_chat_messages
             WHERE recipient_user_id = :current_user_id AND read_at IS NULL'
        );
        $stmt->execute(['current_user_id' => $currentUserId]);
        $directCount = (int) $stmt->fetchColumn();

        $groupStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM employee_chat_groups g
             INNER JOIN employee_chat_group_members gm
                 ON gm.group_id = g.id AND gm.user_id = :current_user_id
             INNER JOIN (
                 SELECT group_id, MAX(id) AS last_message_id
                 FROM employee_chat_group_messages
                 GROUP BY group_id
             ) last_msg ON last_msg.group_id = g.id
             LEFT JOIN employee_chat_group_reads gr
                 ON gr.group_id = g.id AND gr.user_id = :current_user_id
             WHERE last_msg.last_message_id > COALESCE(gr.last_read_message_id, 0)"
        );
        $groupStmt->execute(['current_user_id' => $currentUserId]);

        return $directCount + (int) $groupStmt->fetchColumn();
    }

    public static function createCustomGroup(PDO $pdo, int $creatorUserId, string $name, array $memberIds): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Le nom du groupe est obligatoire.'];
        }
        $memberIds = array_values(array_unique(array_filter(array_map('intval', $memberIds), static function (int $value): bool {
            return $value > 0;
        })));
        if ($creatorUserId > 0 && !in_array($creatorUserId, $memberIds, true)) {
            $memberIds[] = $creatorUserId;
        }
        if (count($memberIds) < 2) {
            return ['ok' => false, 'error' => 'Sélectionnez au moins deux participants.'];
        }

        $placeholders = implode(',', array_fill(0, count($memberIds), '?'));
        $activeStmt = $pdo->prepare('SELECT id FROM user_devis_banamur WHERE active = 1 AND id IN (' . $placeholders . ')');
        $activeStmt->execute($memberIds);
        $activeIds = array_values(array_unique(array_map('intval', $activeStmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        if (count($activeIds) < 2 || !in_array($creatorUserId, $activeIds, true)) {
            return ['ok' => false, 'error' => 'Impossible de créer ce groupe avec cette sélection.'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO employee_chat_groups (name, group_type, service_id, created_by_user_id, created_at, updated_at)
                 VALUES (:name, 'custom', NULL, :created_by_user_id, NOW(), NOW())"
            );
            $stmt->execute([
                'name' => mb_substr($name, 0, 255),
                'created_by_user_id' => $creatorUserId,
            ]);
            $groupId = (int) $pdo->lastInsertId();

            $memberStmt = $pdo->prepare(
                'INSERT INTO employee_chat_group_members (group_id, user_id, added_at)
                 VALUES (:group_id, :user_id, NOW())'
            );
            foreach ($activeIds as $memberId) {
                $memberStmt->execute([
                    'group_id' => $groupId,
                    'user_id' => $memberId,
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $groups = self::listGroups($pdo, $creatorUserId);
        foreach ($groups as $group) {
            if ((int) ($group['group_id'] ?? 0) === $groupId) {
                return ['ok' => true, 'group' => $group];
            }
        }

        return ['ok' => true, 'group' => null];
    }

    public static function listGroupMembers(PDO $pdo, int $currentUserId, int $groupId): array
    {
        if ($currentUserId <= 0 || $groupId <= 0 || !self::userHasGroupAccess($pdo, $currentUserId, $groupId)) {
            return [];
        }

        $stmt = $pdo->prepare(
            "SELECT u.id,
                    COALESCE(NULLIF(TRIM(u.prenom), ''), '') AS prenom,
                    COALESCE(NULLIF(TRIM(u.nom), ''), '') AS nom,
                    COALESCE(NULLIF(TRIM(u.mail_pro), ''), '') AS mail_pro,
                    COALESCE(NULLIF(TRIM(u.photo), ''), '') AS photo,
                    COALESCE(p.service_id, 0) AS service_id,
                    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS service_label
             FROM employee_chat_group_members gm
             INNER JOIN user_devis_banamur u ON u.id = gm.user_id
             LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
             LEFT JOIN service s ON s.id_service = p.service_id
             WHERE gm.group_id = :group_id
             ORDER BY u.prenom ASC, u.nom ASC, u.id ASC"
        );
        $stmt->execute(['group_id' => $groupId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as &$row) {
            $row = self::normalizeUserRow($row, 'id');
        }
        unset($row);

        return $rows;
    }

    public static function setTypingState(PDO $pdo, int $senderUserId, int $recipientUserId, bool $isTyping): void
    {
        if ($senderUserId <= 0 || $recipientUserId <= 0 || $senderUserId === $recipientUserId) {
            return;
        }

        if (!$isTyping) {
            $stmt = $pdo->prepare('DELETE FROM employee_chat_typing WHERE sender_user_id = :sender_user_id AND recipient_user_id = :recipient_user_id');
            $stmt->execute([
                'sender_user_id' => $senderUserId,
                'recipient_user_id' => $recipientUserId,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO employee_chat_typing (sender_user_id, recipient_user_id, is_typing, updated_at)
             VALUES (:sender_user_id, :recipient_user_id, 1, NOW())
             ON DUPLICATE KEY UPDATE is_typing = 1, updated_at = NOW()'
        );
        $stmt->execute([
            'sender_user_id' => $senderUserId,
            'recipient_user_id' => $recipientUserId,
        ]);
    }

    public static function listTypingPeers(PDO $pdo, int $currentUserId, array $peerUserIds): array
    {
        $peerUserIds = array_values(array_unique(array_filter(array_map('intval', $peerUserIds), static function (int $value): bool {
            return $value > 0;
        })));
        if ($currentUserId <= 0 || !$peerUserIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($peerUserIds), '?'));
        $params = array_merge([$currentUserId, date('Y-m-d H:i:s', time() - self::TYPING_TTL_SECONDS)], $peerUserIds);
        $stmt = $pdo->prepare(
            'SELECT sender_user_id
             FROM employee_chat_typing
             WHERE recipient_user_id = ?
               AND is_typing = 1
               AND updated_at >= ?
               AND sender_user_id IN (' . $placeholders . ')'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $map = [];
        foreach ($peerUserIds as $peerUserId) {
            $map[$peerUserId] = false;
        }
        foreach ($rows as $senderUserId) {
            $map[(int) $senderUserId] = true;
        }

        return $map;
    }

    public static function getAttachmentForUser(PDO $pdo, int $currentUserId, string $scope, int $attachmentId): ?array
    {
        if ($currentUserId <= 0 || $attachmentId <= 0) {
            return null;
        }

        if ($scope === self::THREAD_TYPE_GROUP) {
            $stmt = $pdo->prepare(
                'SELECT a.id, a.stored_path, a.original_name, a.mime_type, a.file_size, m.group_id
                 FROM employee_chat_group_attachments a
                 INNER JOIN employee_chat_group_messages m ON m.id = a.message_id
                 INNER JOIN employee_chat_group_members gm ON gm.group_id = m.group_id AND gm.user_id = :user_id
                 WHERE a.id = :attachment_id
                 LIMIT 1'
            );
            $stmt->execute([
                'user_id' => $currentUserId,
                'attachment_id' => $attachmentId,
            ]);

            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmt = $pdo->prepare(
            'SELECT a.id, a.stored_path, a.original_name, a.mime_type, a.file_size,
                    m.sender_user_id, m.recipient_user_id
             FROM employee_chat_attachments a
             INNER JOIN employee_chat_messages m ON m.id = a.message_id
             WHERE a.id = :attachment_id
             LIMIT 1'
        );
        $stmt->execute(['attachment_id' => $attachmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $senderUserId = (int) ($row['sender_user_id'] ?? 0);
        $recipientUserId = (int) ($row['recipient_user_id'] ?? 0);
        if ($currentUserId !== $senderUserId && $currentUserId !== $recipientUserId) {
            return null;
        }

        return $row;
    }

    public static function getState(PDO $pdo, int $currentUserId, array $openPeerIds = []): array
    {
        self::ensureServiceGroups($pdo);

        return [
            'users' => self::listAvailableUsers($pdo, $currentUserId),
            'conversations' => self::listConversations($pdo, $currentUserId),
            'groups' => self::listGroups($pdo, $currentUserId),
            'unread_total' => self::countUnread($pdo, $currentUserId),
            'typing_by_peer' => self::listTypingPeers($pdo, $currentUserId, $openPeerIds),
        ];
    }
}
