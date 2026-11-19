<?php

final class PasskeyPolicyService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_auth_policies (
            user_id INT NOT NULL PRIMARY KEY,
            passkey_only TINYINT(1) NOT NULL DEFAULT 0,
            imposed_by_admin TINYINT(1) NOT NULL DEFAULT 0,
            updated_by INT NULL,
            session_version INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_auth_policies_passkey (passkey_only)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $column=$this->pdo->query("SHOW COLUMNS FROM user_auth_policies LIKE 'session_version'")->fetch(PDO::FETCH_ASSOC);
        if(!$column)$this->pdo->exec('ALTER TABLE user_auth_policies ADD COLUMN session_version INT NOT NULL DEFAULT 0 AFTER updated_by');
    }

    public function get(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT passkey_only,imposed_by_admin,session_version,updated_at FROM user_auth_policies WHERE user_id=:id');
        $stmt->execute(['id'=>$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['passkey_only'=>0,'imposed_by_admin'=>0,'session_version'=>0,'updated_at'=>null];
    }

    public function setByUser(int $userId, bool $enabled): void
    {
        $current=$this->get($userId);
        if (!empty($current['imposed_by_admin']) && !$enabled) throw new RuntimeException('Cette obligation est imposée par un administrateur.');
        $this->save($userId,$enabled,!empty($current['imposed_by_admin']),$userId);
    }

    public function setByAdmin(int $userId, bool $enabled, int $adminId): void
    {
        $this->save($userId,$enabled,$enabled,$adminId);
    }

    private function save(int $userId,bool $enabled,bool $admin,int $actor): void
    {
        $current=$this->get($userId);
        $invalidate=$enabled&&!$current['passkey_only'];
        $stmt=$this->pdo->prepare('INSERT INTO user_auth_policies(user_id,passkey_only,imposed_by_admin,updated_by,session_version) VALUES(:id,:enabled,:admin,:actor,:version) ON DUPLICATE KEY UPDATE passkey_only=VALUES(passkey_only),imposed_by_admin=VALUES(imposed_by_admin),updated_by=VALUES(updated_by),session_version=VALUES(session_version)');
        $stmt->execute(['id'=>$userId,'enabled'=>$enabled?1:0,'admin'=>$admin?1:0,'actor'=>$actor,'version'=>(int)$current['session_version']+($invalidate?1:0)]);
    }

    public function sessionVersion(int $userId): int
    {
        return (int)$this->get($userId)['session_version'];
    }

    public function hasPasskey(int $userId): bool
    {
        try {
            $stmt=$this->pdo->prepare('SELECT COUNT(*) FROM user_passkeys WHERE user_id=:id AND revoked_at IS NULL');
            $stmt->execute(['id'=>$userId]);
            return (int)$stmt->fetchColumn()>0;
        } catch (PDOException $e) {
            if (($e->errorInfo[1]??null)===1146) return false;
            throw $e;
        }
    }
}
