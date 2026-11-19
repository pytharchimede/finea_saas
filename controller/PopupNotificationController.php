<?php

require_once __DIR__ . '/../repository/PopupNotificationRepository.php';
require_once __DIR__ . '/../service/PopupNotificationService.php';

class PopupNotificationController
{
    private PopupNotificationService $service;
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();

        $this->service = new PopupNotificationService(
            new PopupNotificationRepository($pdo)
        );
    }


    public function report(int $notificationId): array
    {
        return $this->service->getNotificationReport($notificationId);
    }


    public function service(): PopupNotificationService
    {
        return $this->service;
    }

    private function ensureTables(): void
    {
        $queries = [
            "
            CREATE TABLE IF NOT EXISTS finea_popup_notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                type VARCHAR(50) NOT NULL DEFAULT 'information',
                short_message TEXT NULL,
                body LONGTEXT NOT NULL,
                module_key VARCHAR(150) NULL,
                priority VARCHAR(20) NOT NULL DEFAULT 'normal',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                starts_at DATETIME NULL,
                ends_at DATETIME NULL,
                target_scope VARCHAR(50) NOT NULL DEFAULT 'all',
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            "
            CREATE TABLE IF NOT EXISTS finea_popup_notification_targets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                target_type VARCHAR(50) NOT NULL,
                target_value VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_popup_targets_notification (notification_id),
                KEY idx_popup_targets_type (target_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            "
            CREATE TABLE IF NOT EXISTS finea_popup_notification_reads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent TEXT NULL,
                read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_popup_read (notification_id, user_id),
                KEY idx_popup_reads_notification (notification_id),
                KEY idx_popup_reads_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            "
            CREATE TABLE IF NOT EXISTS finea_popup_notification_actions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                label VARCHAR(150) NOT NULL,
                action_type ENUM('link','ack','survey_choice','custom') DEFAULT 'link',
                action_value TEXT NULL,
                sort_order INT DEFAULT 0,
                is_primary TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_popup_actions_notification (notification_id),
                KEY idx_popup_actions_type (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ",

            "
            CREATE TABLE IF NOT EXISTS finea_popup_notification_responses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                notification_id INT NOT NULL,
                user_id INT NOT NULL,
                action_id INT NULL,
                response_value TEXT NULL,
                ip VARCHAR(45) NULL,
                user_agent TEXT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_popup_responses_notification (notification_id),
                KEY idx_popup_responses_user (user_id),
                KEY idx_popup_responses_action (action_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            "
        ];

        foreach ($queries as $sql) {
            $this->pdo->exec($sql);
        }
    }
}
