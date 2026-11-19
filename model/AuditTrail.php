<?php

require_once __DIR__ . '/Database.php';

class AuditTrail
{
    private static bool $bootstrapped = false;
    private static bool $maintenanceChecked = false;

    private const ACTIVE_RETENTION_DAYS = 180;
    private const ARCHIVE_RETENTION_DAYS = 730;

    public static function ensureSchema(?PDO $pdo = null): void
    {
        $pdo = $pdo ?: Database::getConnection();
        self::ensureTable($pdo);
    }

    public static function getRetentionConfig(): array
    {
        return [
            'active_retention_days' => self::ACTIVE_RETENTION_DAYS,
            'archive_retention_days' => self::ARCHIVE_RETENTION_DAYS,
        ];
    }

    public static function runMaintenance(?PDO $pdo = null): void
    {
        if (self::$maintenanceChecked) {
            return;
        }

        try {
            $pdo = $pdo ?: Database::getConnection();
            self::ensureTable($pdo);

            if (!self::shouldRunMaintenance($pdo)) {
                self::$maintenanceChecked = true;
                return;
            }

            self::applyRetentionPolicy($pdo);
            self::markMaintenanceRun($pdo);
        } catch (Throwable $e) {
            error_log('AuditTrail maintenance error: ' . $e->getMessage());
        }

        self::$maintenanceChecked = true;
    }

    public static function logFileEvent(string $kind, array $context = [], ?PDO $pdo = null): void
    {
        $kind = strtolower(trim($kind));
        $eventType = $kind === 'download' ? 'file_download' : 'file_export';

        $details = $context['details'] ?? [];
        if (!is_array($details)) {
            $details = ['message' => (string)$details];
        }

        if (isset($context['format']) && !array_key_exists('format', $details)) {
            $details['format'] = $context['format'];
        }
        if (isset($context['scope']) && !array_key_exists('scope', $details)) {
            $details['scope'] = $context['scope'];
        }

        unset($context['format'], $context['scope']);
        $context['details'] = $details;

        self::log($eventType, $context, $pdo);
    }

    public static function logVisualEvent(string $kind, array $context = [], ?PDO $pdo = null): void
    {
        $kind = strtolower(trim($kind));
        $eventType = $kind === 'print' ? 'print_view' : 'browser_preview';

        $details = $context['details'] ?? [];
        if (!is_array($details)) {
            $details = ['message' => (string)$details];
        }

        if (!array_key_exists('view_kind', $details)) {
            $details['view_kind'] = $kind;
        }

        $context['details'] = $details;
        self::log($eventType, $context, $pdo);
    }

    public static function logPageConsultation(array $context = [], ?PDO $pdo = null): void
    {
        $defaultPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = str_replace('\\', '/', (string)($context['request_path'] ?? $defaultPath));
        $pageName = (string)($context['page_name'] ?? basename($path));
        $details = $context['details'] ?? [];

        if (!is_array($details)) {
            $details = ['message' => (string)$details];
        }

        if (!array_key_exists('view_mode', $details)) {
            $details['view_mode'] = 'page';
        }

        self::log('page_view', [
            'module' => (string)($context['module'] ?? self::guessModule($path)),
            'action' => (string)($context['action'] ?? 'page_view'),
            'entity_type' => (string)($context['entity_type'] ?? 'page'),
            'entity_id' => $context['entity_id'] ?? null,
            'entity_label' => (string)($context['entity_label'] ?? ($pageName !== '' ? $pageName : $path)),
            'request_path' => $path,
            'page_name' => $pageName,
            'request_method' => (string)($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            'result' => (string)($context['result'] ?? 'success'),
            'severity' => (string)($context['severity'] ?? 'info'),
            'details' => $details,
        ], $pdo);
    }

    public static function log(string $eventType, array $context = [], ?PDO $pdo = null): void
    {
        try {
            $pdo = $pdo ?: Database::getConnection();
            self::ensureTable($pdo);
            self::runMaintenance($pdo);
            self::insertEvent($pdo, $eventType, $context);
        } catch (Throwable $e) {
            error_log('AuditTrail log error: ' . $e->getMessage());
        }
    }

    /**
     * Audit obligatoire pour une opération transactionnelle sensible.
     *
     * Le schéma doit être préparé avant l'ouverture de la transaction.
     * Toute erreur d'insertion est propagée à l'appelant.
     */
    public static function logStrict(string $eventType, array $context, PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException(
                'Un audit strict doit être enregistré dans une transaction active.'
            );
        }

        self::insertEvent($pdo, $eventType, $context);
    }

    private static function insertEvent(PDO $pdo, string $eventType, array $context): void
    {
        $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $sessionUserName = trim((string)($_SESSION['user_name'] ?? ''));
        $sessionUserEmail = trim((string)($_SESSION['mail_pro'] ?? ''));
        $defaultPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = str_replace('\\', '/', (string)($context['request_path'] ?? $defaultPath));
        $pageName = (string)($context['page_name'] ?? basename($path));
        $requestMethod = strtoupper((string)($context['request_method'] ?? ($_SERVER['REQUEST_METHOD'] ?? 'CLI')));
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        $isAjax = array_key_exists('is_ajax', $context)
            ? (bool)$context['is_ajax']
            : ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || strpos($contentType, 'application/json') !== false
                || strpos($accept, 'application/json') !== false
                || strpos($path, '/request/') !== false
                || strpos($path, '/api/') !== false);

        $details = $context['details'] ?? [];
        if (!is_array($details)) {
            $details = ['message' => (string)$details];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO audit_trail (
                event_type, module, action, entity_type, entity_id, entity_label,
                user_id, user_name, user_email, session_id,
                request_method, request_path, page_name, ip_address, user_agent,
                is_ajax, result, severity, old_value, new_value, comment, details_json, created_at
            ) VALUES (
                :event_type, :module, :action, :entity_type, :entity_id, :entity_label,
                :user_id, :user_name, :user_email, :session_id,
                :request_method, :request_path, :page_name, :ip_address, :user_agent,
                :is_ajax, :result, :severity, :old_value, :new_value, :comment, :details_json, NOW()
            )'
        );

        $stmt->execute([
            'event_type' => $eventType,
            'module' => (string)($context['module'] ?? self::guessModule($path)),
            'action' => (string)($context['action'] ?? $eventType),
            'entity_type' => self::nullableString($context['entity_type'] ?? null),
            'entity_id' => self::nullableString($context['entity_id'] ?? null),
            'entity_label' => self::nullableString($context['entity_label'] ?? null),
            'user_id' => (int)($context['user_id'] ?? $sessionUserId ?: 0) ?: null,
            'user_name' => self::nullableString($context['user_name'] ?? ($sessionUserName !== '' ? $sessionUserName : null)),
            'user_email' => self::nullableString($context['user_email'] ?? ($sessionUserEmail !== '' ? $sessionUserEmail : null)),
            'session_id' => self::nullableString(session_id() ?: null),
            'request_method' => $requestMethod,
            'request_path' => self::nullableString($path !== '' ? $path : null),
            'page_name' => self::nullableString($pageName !== '' ? $pageName : null),
            'ip_address' => self::nullableString($context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null)),
            'user_agent' => self::nullableString($context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)),
            'is_ajax' => $isAjax ? 1 : 0,
            'result' => (string)($context['result'] ?? 'success'),
            'severity' => (string)($context['severity'] ?? 'info'),
            'old_value' => self::encodeAuditValue($context['old_value'] ?? ($details['old_value'] ?? null)),
            'new_value' => self::encodeAuditValue($context['new_value'] ?? ($details['new_value'] ?? null)),
            'comment' => self::nullableString($context['comment'] ?? ($details['comment'] ?? null)),
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function ensureTable(PDO $pdo): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_trail (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                event_type VARCHAR(100) NOT NULL,
                module VARCHAR(100) NULL,
                action VARCHAR(120) NULL,
                entity_type VARCHAR(100) NULL,
                entity_id VARCHAR(120) NULL,
                entity_label VARCHAR(255) NULL,
                user_id INT NULL,
                user_name VARCHAR(190) NULL,
                user_email VARCHAR(190) NULL,
                session_id VARCHAR(128) NULL,
                request_method VARCHAR(10) NULL,
                request_path VARCHAR(255) NULL,
                page_name VARCHAR(180) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                is_ajax TINYINT(1) NOT NULL DEFAULT 0,
                result VARCHAR(40) NOT NULL DEFAULT "success",
                severity VARCHAR(20) NOT NULL DEFAULT "info",
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                comment TEXT NULL,
                details_json LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_type (event_type),
                INDEX idx_module (module),
                INDEX idx_user_id (user_id),
                INDEX idx_page_name (page_name),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_trail_archive (
                archive_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                source_id BIGINT NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                module VARCHAR(100) NULL,
                action VARCHAR(120) NULL,
                entity_type VARCHAR(100) NULL,
                entity_id VARCHAR(120) NULL,
                entity_label VARCHAR(255) NULL,
                user_id INT NULL,
                user_name VARCHAR(190) NULL,
                user_email VARCHAR(190) NULL,
                session_id VARCHAR(128) NULL,
                request_method VARCHAR(10) NULL,
                request_path VARCHAR(255) NULL,
                page_name VARCHAR(180) NULL,
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                is_ajax TINYINT(1) NOT NULL DEFAULT 0,
                result VARCHAR(40) NOT NULL DEFAULT "success",
                severity VARCHAR(20) NOT NULL DEFAULT "info",
                old_value LONGTEXT NULL,
                new_value LONGTEXT NULL,
                comment TEXT NULL,
                details_json LONGTEXT NULL,
                created_at DATETIME NOT NULL,
                archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                archived_reason VARCHAR(50) NOT NULL DEFAULT "retention_policy",
                UNIQUE KEY uq_source_id (source_id),
                INDEX idx_event_type (event_type),
                INDEX idx_module (module),
                INDEX idx_user_id (user_id),
                INDEX idx_page_name (page_name),
                INDEX idx_created_at (created_at),
                INDEX idx_archived_at (archived_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS audit_trail_maintenance (
                maintenance_key VARCHAR(50) PRIMARY KEY,
                last_run_at DATETIME NULL,
                details_json TEXT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::ensureColumns($pdo, 'audit_trail');
        self::ensureColumns($pdo, 'audit_trail_archive');
        self::$bootstrapped = true;
    }

    private static function shouldRunMaintenance(PDO $pdo): bool
    {
        $stmt = $pdo->prepare('SELECT last_run_at FROM audit_trail_maintenance WHERE maintenance_key = :maintenance_key LIMIT 1');
        $stmt->execute([':maintenance_key' => 'retention_policy']);
        $lastRun = $stmt->fetchColumn();

        if (!is_string($lastRun) || trim($lastRun) === '') {
            return true;
        }

        try {
            $lastRunAt = new DateTimeImmutable($lastRun);
        } catch (Throwable $e) {
            return true;
        }

        return $lastRunAt < new DateTimeImmutable('-1 day');
    }

    private static function markMaintenanceRun(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO audit_trail_maintenance (maintenance_key, last_run_at, details_json)
             VALUES (:maintenance_key, NOW(), :details_json)
             ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), details_json = VALUES(details_json)'
        );

        $stmt->execute([
            ':maintenance_key' => 'retention_policy',
            ':details_json' => json_encode(self::getRetentionConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private static function applyRetentionPolicy(PDO $pdo): void
    {
        $pdo->beginTransaction();

        try {
            $pdo->exec(
                'INSERT IGNORE INTO audit_trail_archive (
                    source_id, event_type, module, action, entity_type, entity_id, entity_label,
                    user_id, user_name, user_email, session_id,
                    request_method, request_path, page_name, ip_address, user_agent,
                    is_ajax, result, severity, old_value, new_value, comment, details_json, created_at, archived_at, archived_reason
                )
                SELECT
                    id, event_type, module, action, entity_type, entity_id, entity_label,
                    user_id, user_name, user_email, session_id,
                    request_method, request_path, page_name, ip_address, user_agent,
                    is_ajax, result, severity, old_value, new_value, comment, details_json, created_at, NOW(), "retention_policy"
                FROM audit_trail
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::ACTIVE_RETENTION_DAYS . ' DAY)'
            );

            $pdo->exec(
                'DELETE FROM audit_trail
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::ACTIVE_RETENTION_DAYS . ' DAY)'
            );

            $pdo->exec(
                'DELETE FROM audit_trail_archive
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . self::ARCHIVE_RETENTION_DAYS . ' DAY)'
            );

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function ensureColumns(PDO $pdo, string $table): void
    {
        try {
            $columns = $pdo->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $missing = [
                'old_value' => 'LONGTEXT NULL',
                'new_value' => 'LONGTEXT NULL',
                'comment' => 'TEXT NULL',
            ];
            foreach ($missing as $column => $definition) {
                if (!in_array($column, $columns, true)) {
                    $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
                }
            }
        } catch (Throwable $e) {
            error_log('AuditTrail schema extension error: ' . $e->getMessage());
        }
    }

    private static function encodeAuditValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : null;
    }

    private static function guessModule(string $path): string
    {
        $normalized = trim($path, '/');
        if ($normalized === '') {
            return 'global';
        }

        $segments = explode('/', $normalized);
        if (count($segments) <= 1) {
            return 'core';
        }

        return (string)$segments[0];
    }

    private static function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string)$value);
        return $string === '' ? null : $string;
    }
}
