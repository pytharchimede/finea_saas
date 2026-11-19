<?php

require_once __DIR__ . '/../model/Database.php';

class MailPasswordRepository
{
    private PDO $pdo;
    private static bool $schemaEnsured = false;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function ensurePasswordColumns(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        self::$schemaEnsured = true;

        $columns = [
            'mail_pro_password_enc' => 'ALTER TABLE user_devis_banamur ADD COLUMN mail_pro_password_enc MEDIUMTEXT NULL',
            'mail_pro_password_updated_at' => 'ALTER TABLE user_devis_banamur ADD COLUMN mail_pro_password_updated_at DATETIME NULL',
        ];

        $checkStmt = $this->pdo->prepare('SHOW COLUMNS FROM user_devis_banamur LIKE :column_name');
        foreach ($columns as $columnName => $sql) {
            $checkStmt->execute(['column_name' => $columnName]);
            if (!$checkStmt->fetch()) {
                $this->pdo->exec($sql);
            }
            $checkStmt->closeCursor();
        }
    }

    public function loadEncryptedPasswordForUser(int $userId): ?string
    {
        if ($userId <= 0) {
            return null;
        }

        $this->ensurePasswordColumns();
        $stmt = $this->pdo->prepare('SELECT mail_pro_password_enc FROM user_devis_banamur WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $enc = $stmt->fetchColumn();
        $enc = is_string($enc) ? trim($enc) : '';

        return $enc !== '' ? $enc : null;
    }

    public function saveEncryptedPasswordForUser(int $userId, string $encryptedPassword): bool
    {
        if ($userId <= 0 || trim($encryptedPassword) === '') {
            return false;
        }

        $this->ensurePasswordColumns();
        $stmt = $this->pdo->prepare('UPDATE user_devis_banamur SET mail_pro_password_enc = :enc, mail_pro_password_updated_at = NOW() WHERE id = :id');
        return $stmt->execute(['enc' => $encryptedPassword, 'id' => $userId]);
    }

    public function clearPasswordForUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $this->ensurePasswordColumns();
        $stmt = $this->pdo->prepare('UPDATE user_devis_banamur SET mail_pro_password_enc = NULL, mail_pro_password_updated_at = NOW() WHERE id = :id');
        return $stmt->execute(['id' => $userId]);
    }
}
