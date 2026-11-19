<?php

require_once __DIR__ . '/../model/Database.php';

class ScheduledEmailDispatchRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS scheduled_email_dispatches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_key VARCHAR(120) NOT NULL,
                report_date DATE NOT NULL,
                status ENUM("sent","failed","skipped") NOT NULL DEFAULT "failed",
                subject VARCHAR(255) NULL,
                recipient_email VARCHAR(255) NULL,
                row_count INT NOT NULL DEFAULT 0,
                total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
                error_message TEXT NULL,
                sent_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_scheduled_email_dispatches_job_date (job_key, report_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function get(string $jobKey, string $reportDate): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_email_dispatches WHERE job_key = :job_key AND report_date = :report_date LIMIT 1');
        $stmt->execute(['job_key' => $jobKey, 'report_date' => $reportDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function upsert(string $jobKey, string $reportDate, array $data): void
    {
        $payload = [
            'job_key' => $jobKey,
            'report_date' => $reportDate,
            'status' => (string)($data['status'] ?? 'failed'),
            'subject' => $data['subject'] ?? null,
            'recipient_email' => $data['recipient_email'] ?? null,
            'row_count' => (int)($data['row_count'] ?? 0),
            'total_amount' => (float)($data['total_amount'] ?? 0),
            'error_message' => $data['error_message'] ?? null,
            'sent_at' => $data['sent_at'] ?? null,
        ];

        if ($this->get($jobKey, $reportDate)) {
            $stmt = $this->pdo->prepare('UPDATE scheduled_email_dispatches
                SET status = :status,
                    subject = :subject,
                    recipient_email = :recipient_email,
                    row_count = :row_count,
                    total_amount = :total_amount,
                    error_message = :error_message,
                    sent_at = :sent_at
                WHERE job_key = :job_key AND report_date = :report_date');
            $stmt->execute($payload);
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO scheduled_email_dispatches
            (job_key, report_date, status, subject, recipient_email, row_count, total_amount, error_message, sent_at)
            VALUES
            (:job_key, :report_date, :status, :subject, :recipient_email, :row_count, :total_amount, :error_message, :sent_at)');
        $stmt->execute($payload);
    }
}
