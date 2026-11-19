<?php

require_once __DIR__ . '/../model/Database.php';

class MailboxCacheRepository
{
    private PDO $pdo;
    private static bool $tableEnsured = false;
    private const READ_CACHE_TTL_SECONDS = 86400;
    private const UNREAD_CACHE_TTL_SECONDS = 300;
    private const FOLDER_CACHE_TTL_SECONDS = 900;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        if (self::$tableEnsured) {
            return;
        }

        self::$tableEnsured = true;

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS mailbox_email_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                mailbox_folder VARCHAR(255) NOT NULL,
                mailbox_uid BIGINT NOT NULL,
                message_number INT NULL,
                subject VARCHAR(255) NULL,
                sender_text VARCHAR(255) NULL,
                sent_at_text VARCHAR(255) NULL,
                teaser TEXT NULL,
                is_read TINYINT(1) NOT NULL DEFAULT 0,
                full_payload_json MEDIUMTEXT NULL,
                has_full_payload TINYINT(1) NOT NULL DEFAULT 0,
                last_opened_at DATETIME NULL,
                last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_folder_uid (user_id, mailbox_folder, mailbox_uid),
                KEY idx_user_folder_synced (user_id, mailbox_folder, last_synced_at),
                KEY idx_user_folder_read (user_id, mailbox_folder, is_read)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS mailbox_folder_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                cache_key VARCHAR(120) NOT NULL,
                folders_json MEDIUMTEXT NOT NULL,
                delimiter_value VARCHAR(10) NULL,
                last_synced_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_cache_key (user_id, cache_key),
                KEY idx_user_folder_cache_synced (user_id, last_synced_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function isFresh(?string $timestamp, int $ttlSeconds): bool
    {
        if (!is_string($timestamp) || trim($timestamp) === '') {
            return false;
        }

        $ts = strtotime($timestamp);
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) <= $ttlSeconds;
    }

    private function buildCacheWindow(?string $lastSyncedAt, int $ttlSeconds): array
    {
        $lastSyncedAt = is_string($lastSyncedAt) ? trim($lastSyncedAt) : '';
        $lastTs = $lastSyncedAt !== '' ? strtotime($lastSyncedAt) : false;
        $expiresTs = $lastTs !== false ? ($lastTs + $ttlSeconds) : false;
        $expiresIn = $expiresTs !== false ? max(0, $expiresTs - time()) : null;

        return [
            'last_synced_at' => $lastTs !== false ? date('c', $lastTs) : null,
            'expires_at' => $expiresTs !== false ? date('c', $expiresTs) : null,
            'expires_in_seconds' => $expiresIn,
            'ttl_seconds' => $ttlSeconds,
            'is_fresh' => $lastTs !== false ? ($expiresTs >= time()) : false,
        ];
    }

    public function getSummary(int $userId, string $folder, int $uid): ?array
    {
        if ($userId <= 0 || $uid <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT mailbox_uid, message_number, subject, sender_text, sent_at_text, teaser, is_read, last_synced_at
             FROM mailbox_email_cache
             WHERE user_id = :user_id AND mailbox_folder = :mailbox_folder AND mailbox_uid = :mailbox_uid
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
            'mailbox_uid' => $uid,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$row) {
            return null;
        }

        if (empty($row['is_read']) || !$this->isFresh((string) ($row['last_synced_at'] ?? ''), self::READ_CACHE_TTL_SECONDS)) {
            return null;
        }

        return [
            'uid' => (int) ($row['mailbox_uid'] ?? 0),
            'id' => (int) ($row['message_number'] ?? 0),
            'from' => (string) ($row['sender_text'] ?? ''),
            'subject' => (string) ($row['subject'] ?? ''),
            'date' => (string) ($row['sent_at_text'] ?? ''),
            'is_read' => !empty($row['is_read']),
            'teaser' => (string) ($row['teaser'] ?? ''),
        ];
    }

    public function saveSummary(int $userId, string $folder, array $email): void
    {
        $uid = (int) ($email['uid'] ?? 0);
        if ($userId <= 0 || $uid <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mailbox_email_cache (
                user_id, mailbox_folder, mailbox_uid, message_number, subject, sender_text, sent_at_text, teaser, is_read, last_synced_at
             ) VALUES (
                :user_id, :mailbox_folder, :mailbox_uid, :message_number, :subject, :sender_text, :sent_at_text, :teaser, :is_read, NOW()
             )
             ON DUPLICATE KEY UPDATE
                message_number = VALUES(message_number),
                subject = VALUES(subject),
                sender_text = VALUES(sender_text),
                sent_at_text = VALUES(sent_at_text),
                teaser = VALUES(teaser),
                is_read = VALUES(is_read),
                last_synced_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
            'mailbox_uid' => $uid,
            'message_number' => (int) ($email['id'] ?? 0) ?: null,
            'subject' => mb_substr((string) ($email['subject'] ?? ''), 0, 255),
            'sender_text' => mb_substr((string) ($email['from'] ?? ''), 0, 255),
            'sent_at_text' => mb_substr((string) ($email['date'] ?? ''), 0, 255),
            'teaser' => (string) ($email['teaser'] ?? ''),
            'is_read' => !empty($email['is_read']) ? 1 : 0,
        ]);
    }

    public function getFullMessage(int $userId, string $folder, int $uid): ?array
    {
        if ($userId <= 0 || $uid <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT full_payload_json, is_read, last_synced_at
             FROM mailbox_email_cache
             WHERE user_id = :user_id AND mailbox_folder = :mailbox_folder AND mailbox_uid = :mailbox_uid AND has_full_payload = 1
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
            'mailbox_uid' => $uid,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        if (empty($row['is_read']) || !$this->isFresh((string) ($row['last_synced_at'] ?? ''), self::READ_CACHE_TTL_SECONDS)) {
            return null;
        }

        $payload = (string) ($row['full_payload_json'] ?? '');
        if (trim($payload) === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function saveFullMessage(int $userId, string $folder, array $message): void
    {
        $uid = (int) ($message['uid'] ?? 0);
        if ($userId <= 0 || $uid <= 0) {
            return;
        }

        $payloadJson = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mailbox_email_cache (
                user_id, mailbox_folder, mailbox_uid, message_number, subject, sender_text, sent_at_text, teaser, is_read, full_payload_json, has_full_payload, last_opened_at, last_synced_at
             ) VALUES (
                :user_id, :mailbox_folder, :mailbox_uid, :message_number, :subject, :sender_text, :sent_at_text, :teaser, :is_read, :full_payload_json, 1, NOW(), NOW()
             )
             ON DUPLICATE KEY UPDATE
                message_number = VALUES(message_number),
                subject = VALUES(subject),
                sender_text = VALUES(sender_text),
                sent_at_text = VALUES(sent_at_text),
                teaser = VALUES(teaser),
                is_read = VALUES(is_read),
                full_payload_json = VALUES(full_payload_json),
                has_full_payload = 1,
                last_opened_at = NOW(),
                last_synced_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
            'mailbox_uid' => $uid,
            'message_number' => (int) ($message['id'] ?? 0) ?: null,
            'subject' => mb_substr((string) ($message['subject'] ?? ''), 0, 255),
            'sender_text' => mb_substr((string) ($message['from'] ?? ''), 0, 255),
            'sent_at_text' => mb_substr((string) ($message['date'] ?? ''), 0, 255),
            'teaser' => mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($message['message'] ?? '')))), 0, 120),
            'is_read' => 1,
            'full_payload_json' => $payloadJson,
        ]);
    }

    public function markAsRead(int $userId, string $folder, int $uid): void
    {
        if ($userId <= 0 || $uid <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE mailbox_email_cache
             SET is_read = 1, last_opened_at = NOW(), last_synced_at = NOW()
             WHERE user_id = :user_id AND mailbox_folder = :mailbox_folder AND mailbox_uid = :mailbox_uid'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
            'mailbox_uid' => $uid,
        ]);
    }

    public function getFolderList(int $userId, string $cacheKey = 'default'): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT folders_json, delimiter_value, last_synced_at
             FROM mailbox_folder_cache
             WHERE user_id = :user_id AND cache_key = :cache_key
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'cache_key' => $cacheKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row || !$this->isFresh((string) ($row['last_synced_at'] ?? ''), self::FOLDER_CACHE_TTL_SECONDS)) {
            return null;
        }

        $folders = json_decode((string) ($row['folders_json'] ?? ''), true);
        if (!is_array($folders)) {
            return null;
        }

        return [
            'folders' => $folders,
            'delimiter' => (string) ($row['delimiter_value'] ?? '.'),
            'cached' => true,
            'ttl_seconds' => self::FOLDER_CACHE_TTL_SECONDS,
        ];
    }

    public function saveFolderList(int $userId, array $folders, string $delimiter = '.', string $cacheKey = 'default'): void
    {
        if ($userId <= 0) {
            return;
        }

        $json = json_encode($folders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO mailbox_folder_cache (user_id, cache_key, folders_json, delimiter_value, last_synced_at)
             VALUES (:user_id, :cache_key, :folders_json, :delimiter_value, NOW())
             ON DUPLICATE KEY UPDATE
                folders_json = VALUES(folders_json),
                delimiter_value = VALUES(delimiter_value),
                last_synced_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'cache_key' => $cacheKey,
            'folders_json' => $json,
            'delimiter_value' => mb_substr($delimiter !== '' ? $delimiter : '.', 0, 10),
        ]);
    }

    public function clearUserCache(int $userId, ?string $folder = null): void
    {
        if ($userId <= 0) {
            return;
        }

        $this->clearMessageCache($userId, $folder);
        $this->clearFolderCache($userId);
    }

    public function clearMessageCache(int $userId, ?string $folder = null): void
    {
        if ($userId <= 0) {
            return;
        }

        if ($folder !== null && trim($folder) !== '') {
            $stmt = $this->pdo->prepare('DELETE FROM mailbox_email_cache WHERE user_id = :user_id AND mailbox_folder = :mailbox_folder');
            $stmt->execute([
                'user_id' => $userId,
                'mailbox_folder' => $folder,
            ]);
        } else {
            $stmt = $this->pdo->prepare('DELETE FROM mailbox_email_cache WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $userId]);
        }
    }

    public function clearFolderCache(int $userId, ?string $cacheKey = null): void
    {
        if ($userId <= 0) {
            return;
        }

        if ($cacheKey !== null && trim($cacheKey) !== '') {
            $stmt = $this->pdo->prepare('DELETE FROM mailbox_folder_cache WHERE user_id = :user_id AND cache_key = :cache_key');
            $stmt->execute([
                'user_id' => $userId,
                'cache_key' => $cacheKey,
            ]);
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM mailbox_folder_cache WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }

    public function getMessageCacheStatus(int $userId, string $folder): array
    {
        if ($userId <= 0 || trim($folder) === '') {
            return [
                'folder' => $folder,
                'total_cached' => 0,
                'read' => $this->buildCacheWindow(null, self::READ_CACHE_TTL_SECONDS),
                'unread' => $this->buildCacheWindow(null, self::UNREAD_CACHE_TTL_SECONDS),
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                COUNT(*) AS total_cached,
                SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) AS read_cached_count,
                SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_cached_count,
                MAX(CASE WHEN is_read = 1 THEN last_synced_at END) AS last_read_synced_at,
                MAX(CASE WHEN is_read = 0 THEN last_synced_at END) AS last_unread_synced_at
             FROM mailbox_email_cache
             WHERE user_id = :user_id AND mailbox_folder = :mailbox_folder'
        );
        $stmt->execute([
            'user_id' => $userId,
            'mailbox_folder' => $folder,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $readWindow = $this->buildCacheWindow((string) ($row['last_read_synced_at'] ?? ''), self::READ_CACHE_TTL_SECONDS);
        $unreadWindow = $this->buildCacheWindow((string) ($row['last_unread_synced_at'] ?? ''), self::UNREAD_CACHE_TTL_SECONDS);
        $readWindow['count'] = (int) ($row['read_cached_count'] ?? 0);
        $unreadWindow['count'] = (int) ($row['unread_cached_count'] ?? 0);

        return [
            'folder' => $folder,
            'total_cached' => (int) ($row['total_cached'] ?? 0),
            'read' => $readWindow,
            'unread' => $unreadWindow,
        ];
    }

    public function getFolderCacheStatus(int $userId, string $cacheKey = 'default'): array
    {
        if ($userId <= 0) {
            return $this->buildEmptyFolderCacheStatus($cacheKey);
        }

        $stmt = $this->pdo->prepare(
            'SELECT folders_json, delimiter_value, last_synced_at
             FROM mailbox_folder_cache
             WHERE user_id = :user_id AND cache_key = :cache_key
             LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'cache_key' => $cacheKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return $this->buildEmptyFolderCacheStatus($cacheKey);
        }

        $folders = json_decode((string) ($row['folders_json'] ?? ''), true);
        $window = $this->buildCacheWindow((string) ($row['last_synced_at'] ?? ''), self::FOLDER_CACHE_TTL_SECONDS);

        return [
            'cache_key' => $cacheKey,
            'delimiter' => (string) ($row['delimiter_value'] ?? '.'),
            'count' => is_array($folders) ? count($folders) : 0,
            'last_synced_at' => $window['last_synced_at'],
            'expires_at' => $window['expires_at'],
            'expires_in_seconds' => $window['expires_in_seconds'],
            'ttl_seconds' => $window['ttl_seconds'],
            'is_fresh' => $window['is_fresh'],
        ];
    }

    private function buildEmptyFolderCacheStatus(string $cacheKey): array
    {
        $window = $this->buildCacheWindow(null, self::FOLDER_CACHE_TTL_SECONDS);

        return [
            'cache_key' => $cacheKey,
            'delimiter' => '.',
            'count' => 0,
            'last_synced_at' => $window['last_synced_at'],
            'expires_at' => $window['expires_at'],
            'expires_in_seconds' => $window['expires_in_seconds'],
            'ttl_seconds' => $window['ttl_seconds'],
            'is_fresh' => $window['is_fresh'],
        ];
    }

    public function cleanupExpiredCache(?int $userId = null): array
    {
        $result = [
            'message_deleted' => 0,
            'folder_deleted' => 0,
        ];
        $readBefore = date('Y-m-d H:i:s', time() - self::READ_CACHE_TTL_SECONDS);
        $unreadBefore = date('Y-m-d H:i:s', time() - self::UNREAD_CACHE_TTL_SECONDS);
        $folderBefore = date('Y-m-d H:i:s', time() - self::FOLDER_CACHE_TTL_SECONDS);

        $conditions = [];
        $params = [];
        if ($userId !== null && $userId > 0) {
            $conditions[] = 'user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $messageWhere = $conditions;
        $messageWhere[] = '((is_read = 1 AND last_synced_at < :read_before) OR (is_read = 0 AND last_synced_at < :unread_before))';
        $messageSql = 'DELETE FROM mailbox_email_cache WHERE ' . implode(' AND ', $messageWhere);
        $messageStmt = $this->pdo->prepare($messageSql);
        foreach ($params as $key => $value) {
            $messageStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $messageStmt->bindValue(':read_before', $readBefore);
        $messageStmt->bindValue(':unread_before', $unreadBefore);
        $messageStmt->execute();
        $result['message_deleted'] = $messageStmt->rowCount();

        $folderWhere = $conditions;
        $folderWhere[] = 'last_synced_at < :folder_before';
        $folderSql = 'DELETE FROM mailbox_folder_cache WHERE ' . implode(' AND ', $folderWhere);
        $folderStmt = $this->pdo->prepare($folderSql);
        foreach ($params as $key => $value) {
            $folderStmt->bindValue(':' . $key, $value, PDO::PARAM_INT);
        }
        $folderStmt->bindValue(':folder_before', $folderBefore);
        $folderStmt->execute();
        $result['folder_deleted'] = $folderStmt->rowCount();

        return $result;
    }

    public function getReadCacheTtlSeconds(): int
    {
        return self::READ_CACHE_TTL_SECONDS;
    }

    public function getUnreadCacheTtlSeconds(): int
    {
        return self::UNREAD_CACHE_TTL_SECONDS;
    }

    public function getFolderCacheTtlSeconds(): int
    {
        return self::FOLDER_CACHE_TTL_SECONDS;
    }
}
