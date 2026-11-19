<?php
declare(strict_types=1);

final class ChantierPauseService
{
    private static ?bool $storageExists = null;
    public function __construct(private PDO $pdo) {}

    public static function activePredicate(PDO $pdo, string $chantierIdSql): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.(?:id|chantier_id)$|^[A-Za-z_][A-Za-z0-9_]*$/', $chantierIdSql)) throw new InvalidArgumentException('Expression de chantier invalide.');
        if (!self::storageExists($pdo)) return '1=1';
        return "NOT EXISTS (SELECT 1 FROM finea_chantier_pauses finea_pause WHERE finea_pause.chantier_id={$chantierIdSql} AND finea_pause.resumed_at IS NULL AND (finea_pause.resume_at IS NULL OR finea_pause.resume_at>NOW()))";
    }

    public static function storageExists(PDO $pdo): bool
    {
        if (self::$storageExists!==null) return self::$storageExists;
        return self::$storageExists=(bool)$pdo->query("SHOW TABLES LIKE 'finea_chantier_pauses'")->fetchColumn();
    }

    public function ensureStorage(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS finea_chantier_pauses (chantier_id INT NOT NULL PRIMARY KEY, paused_at DATETIME NOT NULL, resume_at DATETIME NULL, resumed_at DATETIME NULL, paused_by_user_id INT NULL, resumed_by_user_id INT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX idx_finea_chantier_pauses_active (resumed_at,resume_at), INDEX idx_finea_chantier_pauses_notice (paused_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        self::$storageExists=true;
    }

    public function pause(int $chantierId, ?string $resumeDate, int $actorId): void
    {
        if ($chantierId < 1) throw new DomainException('Chantier invalide.');
        if ($resumeDate !== null && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resumeDate) || !checkdate((int)substr($resumeDate,5,2),(int)substr($resumeDate,8,2),(int)substr($resumeDate,0,4)) || $resumeDate <= date('Y-m-d'))) throw new DomainException('Choisissez une date de réactivation future.');
        $check=$this->pdo->prepare('SELECT id FROM chantier_banamur WHERE id=? AND archived_at IS NULL LIMIT 1');
        $check->execute([$chantierId]);
        if (!$check->fetchColumn()) throw new DomainException('Chantier introuvable ou archivé.');
        $this->ensureStorage();
        $stmt=$this->pdo->prepare('INSERT INTO finea_chantier_pauses(chantier_id,paused_at,resume_at,resumed_at,paused_by_user_id,resumed_by_user_id) VALUES(?,NOW(),?,NULL,?,NULL) ON DUPLICATE KEY UPDATE paused_at=NOW(),resume_at=VALUES(resume_at),resumed_at=NULL,paused_by_user_id=VALUES(paused_by_user_id),resumed_by_user_id=NULL');
        $stmt->execute([$chantierId,$resumeDate===null?null:$resumeDate.' 00:00:00',$actorId]);
    }

    public function resume(int $chantierId, int $actorId): void
    {
        $stmt=$this->pdo->prepare('UPDATE finea_chantier_pauses SET resumed_at=NOW(),resumed_by_user_id=? WHERE chantier_id=? AND resumed_at IS NULL');
        $stmt->execute([$actorId,$chantierId]);
    }

    public function states(): array
    {
        if (!self::storageExists($this->pdo)) return [];
        try {
            $rows=$this->pdo->query('SELECT p.chantier_id,p.paused_at,p.resume_at,p.resumed_at,(p.resumed_at IS NULL AND (p.resume_at IS NULL OR p.resume_at>NOW())) is_paused FROM finea_chantier_pauses p')->fetchAll(PDO::FETCH_ASSOC)?:[];
            return array_column($rows,null,'chantier_id');
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(),'1146')) return [];
            throw $e;
        }
    }

    public function recentNotices(): array
    {
        if (!self::storageExists($this->pdo)) return [];
        try {
            return $this->pdo->query("SELECT c.id,c.code,c.titre,p.paused_at,p.resume_at FROM finea_chantier_pauses p JOIN chantier_banamur c ON c.id=p.chantier_id WHERE p.resumed_at IS NULL AND (p.resume_at IS NULL OR p.resume_at>NOW()) AND p.paused_at>=DATE_SUB(NOW(),INTERVAL 3 DAY) AND c.archived_at IS NULL ORDER BY p.paused_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC)?:[];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(),'1146')) return [];
            throw $e;
        }
    }
}
