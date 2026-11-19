<?php

require_once __DIR__ . '/../model/Database.php';

class EmailLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function ensureLogTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                recipient_email VARCHAR(255),
                recipient_name VARCHAR(255),
                subject VARCHAR(255),
                body_html MEDIUMTEXT,
                body_plain MEDIUMTEXT,
                status ENUM('sent','failed') DEFAULT 'failed',
                error_message TEXT,
                sent_at DATETIME,
                opened_at DATETIME NULL,
                ip_address VARCHAR(45),
                user_agent TEXT,
                tracking_token VARCHAR(100),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO email_logs
            (recipient_email, recipient_name, subject, body_html, body_plain, status, error_message, sent_at, ip_address, user_agent, tracking_token)
            VALUES
            (:recipient_email, :recipient_name, :subject, :body_html, :body_plain, :status, :error_message, :sent_at, :ip_address, :user_agent, :tracking_token)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }
}
