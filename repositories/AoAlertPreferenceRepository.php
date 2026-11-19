<?php

class AoAlertPreferenceRepository
{
    private const DEFAULT_BTP_KEYWORDS = 'bâtiment, construction, réhabilitation, rénovation, génie civil, route, voirie, VRD, assainissement, drainage, terrassement, ouvrage, pont, dalot, caniveau, école, centre de santé, clôture, aménagement, adduction d eau, réseau hydraulique, électrification, maintenance bâtiment';
    private const DEFAULT_EXCLUDED_KEYWORDS = 'logiciel, licence informatique, cybersécurité, audit comptable, assurance, gardiennage, restauration, denrées alimentaires, fournitures de bureau, consommables informatiques, billet d avion, formation seule, étude sans travaux';

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public function getForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ao_alert_preferences WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'user_id' => $userId,
            'alerts_system_enabled' => !isset($row['alerts_system_enabled']) || (int) $row['alerts_system_enabled'] === 1,
            'alerts_email_enabled' => isset($row['alerts_email_enabled']) && (int) $row['alerts_email_enabled'] === 1,
            'min_score' => isset($row['min_score']) ? (int) $row['min_score'] : 60,
            'keywords' => (string) ($row['keywords'] ?? self::DEFAULT_BTP_KEYWORDS),
            'excluded_keywords' => (string) ($row['excluded_keywords'] ?? self::DEFAULT_EXCLUDED_KEYWORDS),
            'frequency' => (string) ($row['frequency'] ?? 'daily_digest'),
            'last_email_sent_at' => $row['last_email_sent_at'] ?? null,
        ];
    }

    public function save(int $userId, array $data): void
    {
        $frequency = (string) ($data['frequency'] ?? 'daily_digest');
        if (!in_array($frequency, ['instant', 'daily_digest', 'weekly_digest', 'disabled'], true)) {
            $frequency = 'daily_digest';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_alert_preferences (
                user_id, alerts_system_enabled, alerts_email_enabled, min_score,
                keywords, excluded_keywords, frequency
             ) VALUES (:user_id, :system, :email, :min_score, :keywords, :excluded, :frequency)
             ON DUPLICATE KEY UPDATE
                alerts_system_enabled = VALUES(alerts_system_enabled),
                alerts_email_enabled = VALUES(alerts_email_enabled),
                min_score = VALUES(min_score), keywords = VALUES(keywords),
                excluded_keywords = VALUES(excluded_keywords), frequency = VALUES(frequency),
                updated_at = NOW()'
        );
        $stmt->execute([
            'user_id' => $userId,
            'system' => !empty($data['alerts_system_enabled']) ? 1 : 0,
            'email' => !empty($data['alerts_email_enabled']) ? 1 : 0,
            'min_score' => max(0, min(100, (int) ($data['min_score'] ?? 60))),
            'keywords' => trim((string) ($data['keywords'] ?? '')),
            'excluded' => trim((string) ($data['excluded_keywords'] ?? '')),
            'frequency' => $frequency,
        ]);
    }

    public function listSubscribedUsers(): array
    {
        $rows = $this->pdo->query(
            "SELECT u.id user_id, u.prenom, u.nom, u.mail_pro,
                    COALESCE(p.alerts_system_enabled, 1) alerts_system_enabled,
                    COALESCE(p.alerts_email_enabled, 0) alerts_email_enabled,
                    COALESCE(p.min_score, 60) min_score,
                    COALESCE(p.keywords, '') keywords,
                    COALESCE(p.excluded_keywords, '') excluded_keywords,
                    COALESCE(p.frequency, 'daily_digest') frequency,
                    p.last_email_sent_at
             FROM user_devis_banamur u
             LEFT JOIN ao_alert_preferences p ON p.user_id = u.id
             WHERE u.active = 1
               AND (
                    COALESCE(u.acces_veille_ao, 0) = 1
                    OR COALESCE(u.ao_view, 0) = 1
                    OR COALESCE(u.ao_admin, 0) = 1
                    OR COALESCE(u.acces_admin, 0) = 1
               )"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    }

    public function markEmailSent(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ao_alert_preferences (user_id, last_email_sent_at)
             VALUES (:user_id, NOW())
             ON DUPLICATE KEY UPDATE last_email_sent_at = NOW(), updated_at = NOW()'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private function ensureTables(): void
    {
        try {
            $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            if (!in_array('acces_veille_ao', $columns, true)) {
                $this->pdo->exec('ALTER TABLE user_devis_banamur ADD COLUMN acces_veille_ao TINYINT(1) NOT NULL DEFAULT 0');
            }
        } catch (Throwable $exception) {
            // AccessManager tentera aussi cette migration; ne pas bloquer la veille.
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ao_alert_preferences (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                alerts_system_enabled TINYINT(1) NOT NULL DEFAULT 1,
                alerts_email_enabled TINYINT(1) NOT NULL DEFAULT 0,
                min_score TINYINT UNSIGNED NOT NULL DEFAULT 60,
                keywords TEXT NULL,
                excluded_keywords TEXT NULL,
                frequency ENUM('instant','daily_digest','weekly_digest','disabled') NOT NULL DEFAULT 'daily_digest',
                last_email_sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ao_alert_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ao_alert_logs (
                id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ao_opportunity_id INT NOT NULL,
                user_id INT NOT NULL,
                channel VARCHAR(30) NOT NULL,
                status VARCHAR(30) NOT NULL,
                error_message TEXT NULL,
                attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
                last_attempt_at DATETIME NULL,
                sent_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ao_alert_once (ao_opportunity_id, user_id, channel)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $columns = $this->pdo->query('SHOW COLUMNS FROM ao_alert_logs')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        if (!in_array('attempt_count', $columns, true)) {
            $this->pdo->exec('ALTER TABLE ao_alert_logs ADD COLUMN attempt_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER error_message');
        }
        if (!in_array('last_attempt_at', $columns, true)) {
            $this->pdo->exec('ALTER TABLE ao_alert_logs ADD COLUMN last_attempt_at DATETIME NULL AFTER attempt_count');
        }
    }
}
