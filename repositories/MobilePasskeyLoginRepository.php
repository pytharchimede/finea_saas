<?php

declare(strict_types=1);

final class MobilePasskeyLoginRepository
{
    public function __construct(private PDO $pdo) { $this->ensureSchema(); }

    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS mobile_passkey_logins (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            public_id CHAR(32) NOT NULL,
            token_hash BINARY(32) NOT NULL,
            desktop_session_hash BINARY(32) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            challenge_options LONGTEXT NULL,
            user_id INT NULL,
            passkey_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            approved_at DATETIME NULL,
            consumed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mobile_passkey_public_id (public_id),
            UNIQUE KEY uq_mobile_passkey_token_hash (token_hash),
            KEY idx_mobile_passkey_status_expires (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS user_mobile_scanners (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT NOT NULL,
            device_token_hash BINARY(32) NOT NULL,
            device_label VARCHAR(120) NOT NULL,
            user_agent VARCHAR(500) NULL,
            first_ip_address VARCHAR(45) NULL,
            last_ip_address VARCHAR(45) NULL,
            passkey_id BIGINT UNSIGNED NULL,
            first_authorized_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            use_count INT UNSIGNED NOT NULL DEFAULT 1,
            revoked_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mobile_scanner_user_device (user_id,device_token_hash),
            KEY idx_mobile_scanner_user_active (user_id,revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function create(string $desktopSessionId, int $ttl): array
    {
        $ttl=max(60,$ttl);
        $publicId=bin2hex(random_bytes(16));
        $token=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $expiresAt=gmdate('Y-m-d H:i:s',time()+$ttl);
        $stmt=$this->pdo->prepare('INSERT INTO mobile_passkey_logins (public_id,token_hash,desktop_session_hash,expires_at) VALUES (:public_id,:token_hash,:desktop_session_hash,:expires_at)');
        $stmt->execute([
            'public_id'=>$publicId,
            'token_hash'=>hash('sha256',$token,true),
            'desktop_session_hash'=>hash('sha256',$desktopSessionId,true),
            'expires_at'=>$expiresAt,
        ]);
        return ['public_id'=>$publicId,'token'=>$token,'expires_in'=>$ttl];
    }

    public function byMobileToken(string $token): ?array
    {
        if (!$this->validToken($token)) return null;
        $stmt=$this->pdo->prepare('SELECT * FROM mobile_passkey_logins WHERE token_hash=:hash LIMIT 1');
        $stmt->execute(['hash'=>hash('sha256',$token,true)]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        if ($row['status']==='pending' && strtotime((string)$row['expires_at'].' UTC')<time()) {
            $this->pdo->prepare("UPDATE mobile_passkey_logins SET status='expired',challenge_options=NULL WHERE id=:id AND status='pending'")->execute(['id'=>(int)$row['id']]);
            $row['status']='expired';
        }
        return $row;
    }

    public function storeChallenge(int $id,string $options): bool
    {
        $stmt=$this->pdo->prepare("UPDATE mobile_passkey_logins SET challenge_options=:options WHERE id=:id AND status='pending' AND expires_at>UTC_TIMESTAMP()");
        $stmt->execute(['options'=>$options,'id'=>$id]);
        return $stmt->rowCount()===1;
    }

    public function takeChallenge(string $token): ?array
    {
        if (!$this->validToken($token)) return null;
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('SELECT * FROM mobile_passkey_logins WHERE token_hash=:hash FOR UPDATE');
            $stmt->execute(['hash'=>hash('sha256',$token,true)]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status']!=='pending' || empty($row['challenge_options']) || strtotime((string)$row['expires_at'].' UTC')<time()) {
                $this->pdo->rollBack(); return null;
            }
            $this->pdo->prepare('UPDATE mobile_passkey_logins SET challenge_options=NULL WHERE id=:id')->execute(['id'=>(int)$row['id']]);
            $this->pdo->commit(); return $row;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function approve(int $id,int $userId,int $passkeyId): bool
    {
        $stmt=$this->pdo->prepare("UPDATE mobile_passkey_logins SET status='approved',user_id=:uid,passkey_id=:pid,approved_at=UTC_TIMESTAMP() WHERE id=:id AND status='pending' AND expires_at>UTC_TIMESTAMP()");
        $stmt->execute(['uid'=>$userId,'pid'=>$passkeyId,'id'=>$id]);
        return $stmt->rowCount()===1;
    }

    public function desktopStatus(string $publicId,string $desktopSessionId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return null;
        $stmt=$this->pdo->prepare('SELECT * FROM mobile_passkey_logins WHERE public_id=:id LIMIT 1');
        $stmt->execute(['id'=>$publicId]);
        $row=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals((string)$row['desktop_session_hash'],hash('sha256',$desktopSessionId,true))) return null;
        if ($row['status']==='pending' && strtotime((string)$row['expires_at'].' UTC')<time()) {
            $this->pdo->prepare("UPDATE mobile_passkey_logins SET status='expired',challenge_options=NULL WHERE id=:id AND status='pending'")->execute(['id'=>(int)$row['id']]);
            $row['status']='expired';
        }
        return $row;
    }

    public function consume(string $publicId,string $desktopSessionId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/',$publicId)) return null;
        $this->pdo->beginTransaction();
        try {
            $stmt=$this->pdo->prepare('SELECT * FROM mobile_passkey_logins WHERE public_id=:id FOR UPDATE');
            $stmt->execute(['id'=>$publicId]);
            $row=$stmt->fetch(PDO::FETCH_ASSOC);
            $sessionHash=hash('sha256',$desktopSessionId,true);
            if (!$row || !hash_equals((string)$row['desktop_session_hash'],$sessionHash) || $row['status']!=='approved' || strtotime((string)$row['expires_at'].' UTC')<time()) {
                $this->pdo->rollBack(); return null;
            }
            $update=$this->pdo->prepare("UPDATE mobile_passkey_logins SET status='consumed',consumed_at=UTC_TIMESTAMP() WHERE id=:id AND status='approved'");
            $update->execute(['id'=>(int)$row['id']]);
            if ($update->rowCount()!==1) { $this->pdo->rollBack(); return null; }
            $this->pdo->commit(); return $row;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function authorizeScanner(int $userId,int $passkeyId,string $deviceToken,string $deviceLabel,string $userAgent,?string $ipAddress): bool
    {
        if (!$this->validToken($deviceToken)) return false;
        $hash=hash('sha256',$deviceToken,true);
        $check=$this->pdo->prepare('SELECT revoked_at FROM user_mobile_scanners WHERE user_id=:user_id AND device_token_hash=:hash LIMIT 1');
        $check->execute(['user_id'=>$userId,'hash'=>$hash]);
        $existing=$check->fetch(PDO::FETCH_ASSOC);
        if($existing && !empty($existing['revoked_at']))return false;
        $stmt=$this->pdo->prepare("INSERT INTO user_mobile_scanners(user_id,device_token_hash,device_label,user_agent,first_ip_address,last_ip_address,passkey_id) VALUES(:user_id,:hash,:label,:ua,:ip,:ip,:passkey_id) ON DUPLICATE KEY UPDATE device_label=VALUES(device_label),user_agent=VALUES(user_agent),last_ip_address=VALUES(last_ip_address),passkey_id=VALUES(passkey_id),last_used_at=NOW(),use_count=use_count+1");
        $stmt->execute(['user_id'=>$userId,'hash'=>$hash,'label'=>mb_substr(trim($deviceLabel)?:'Téléphone',0,120),'ua'=>mb_substr($userAgent,0,500),'ip'=>$ipAddress,'passkey_id'=>$passkeyId]);
        return true;
    }

    public function listScannersForUser(int $userId): array
    {
        $stmt=$this->pdo->prepare('SELECT s.id,s.device_label,s.user_agent,s.first_ip_address,s.last_ip_address,s.passkey_id,s.first_authorized_at,s.last_used_at,s.use_count,p.device_name AS passkey_name FROM user_mobile_scanners s LEFT JOIN user_passkeys p ON p.id=s.passkey_id WHERE s.user_id=:user_id AND s.revoked_at IS NULL ORDER BY s.last_used_at DESC,s.id DESC');
        $stmt->execute(['user_id'=>$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function revokeScanner(int $id,int $userId): bool
    {
        $stmt=$this->pdo->prepare('UPDATE user_mobile_scanners SET revoked_at=NOW() WHERE id=:id AND user_id=:user_id AND revoked_at IS NULL');
        $stmt->execute(['id'=>$id,'user_id'=>$userId]);
        return $stmt->rowCount()===1;
    }

    private function validToken(string $token): bool { return (bool)preg_match('/^[A-Za-z0-9_-]{43}$/',$token); }
}
