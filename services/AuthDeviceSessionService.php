<?php

final class AuthDeviceSessionService
{
    public function __construct(private PDO $pdo){$this->ensureSchema();}

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_device_sessions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            auth_method VARCHAR(20) NOT NULL,
            device_label VARCHAR(120) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            revoked_at DATETIME NULL,
            KEY idx_device_sessions_user (user_id,revoked_at),
            KEY idx_device_sessions_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function register(int $userId,string $method): string
    {
        $token=bin2hex(random_bytes(32));$ua=(string)($_SERVER['HTTP_USER_AGENT']??'');
        $stmt=$this->pdo->prepare('INSERT INTO user_device_sessions(user_id,token_hash,auth_method,device_label,ip_address,user_agent) VALUES(:user_id,:hash,:method,:label,:ip,:ua)');
        $stmt->execute(['user_id'=>$userId,'hash'=>hash('sha256',$token),'method'=>$method,'label'=>$this->deviceLabel($ua),'ip'=>$_SERVER['REMOTE_ADDR']??null,'ua'=>mb_substr($ua,0,500)]);
        return $token;
    }

    public function validate(int $userId,string $token): bool
    {
        if($token==='')return false;
        $stmt=$this->pdo->prepare('SELECT id FROM user_device_sessions WHERE user_id=:user_id AND token_hash=:hash AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(['user_id'=>$userId,'hash'=>hash('sha256',$token)]);$id=(int)$stmt->fetchColumn();
        if($id<1)return false;
        $this->pdo->prepare('UPDATE user_device_sessions SET last_seen_at=NOW() WHERE id=:id AND last_seen_at<DATE_SUB(NOW(),INTERVAL 1 MINUTE)')->execute(['id'=>$id]);
        return true;
    }

    public function listForUser(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT id,auth_method,device_label,ip_address,user_agent,created_at,last_seen_at FROM user_device_sessions WHERE user_id=:user_id AND revoked_at IS NULL ORDER BY last_seen_at DESC,id DESC');
        $stmt->execute(['user_id'=>$userId]);
        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row)unset($row['user_agent']);
        unset($row);
        return $rows;
    }

    public function revoke(int $id,int $userId): bool
    {
        $stmt=$this->pdo->prepare('UPDATE user_device_sessions SET revoked_at=NOW() WHERE id=:id AND user_id=:user_id AND revoked_at IS NULL');
        $stmt->execute(['id'=>$id,'user_id'=>$userId]);return $stmt->rowCount()===1;
    }

    public function revokeAllExcept(int $userId,int $currentId): int
    {
        $stmt=$this->pdo->prepare('UPDATE user_device_sessions SET revoked_at=NOW() WHERE user_id=:user_id AND id<>:current_id AND revoked_at IS NULL');
        $stmt->execute(['user_id'=>$userId,'current_id'=>$currentId]);
        return $stmt->rowCount();
    }

    public function idForToken(int $userId,string $token): int
    {
        $stmt=$this->pdo->prepare('SELECT id FROM user_device_sessions WHERE user_id=:user_id AND token_hash=:hash LIMIT 1');
        $stmt->execute(['user_id'=>$userId,'hash'=>hash('sha256',$token)]);return (int)$stmt->fetchColumn();
    }

    private function deviceLabel(string $ua): string
    {
        $device=str_contains($ua,'iPhone')?'iPhone':(str_contains($ua,'iPad')?'iPad':(str_contains($ua,'Android')?'Téléphone Android':(str_contains($ua,'Windows')?'PC Windows':(str_contains($ua,'Macintosh')?'Mac':'Appareil'))));
        $browser=str_contains($ua,'Edg/')?'Edge':(str_contains($ua,'Firefox/')?'Firefox':(str_contains($ua,'Chrome/')?'Chrome':(str_contains($ua,'Safari/')?'Safari':'Navigateur')));
        return $device.' · '.$browser;
    }
}
