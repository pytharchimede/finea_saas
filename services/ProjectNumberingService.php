<?php
declare(strict_types=1);

final class ProjectNumberingService
{
    private const PREFIX = 'PRJ';
    private const VERSION = 'PRJ_YEAR_SEQUENCE_V1';
    private static bool $ensured = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureAndMigrate(): void
    {
        if (self::$ensured) return;
        $this->ensureSchema();
        $lockName = 'finea_project_number_migration_v1';
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $lock->execute(['lock_name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('La mise à niveau des numéros de projets est déjà en cours. Réessayez dans quelques secondes.');
        }
        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            $this->migrateExistingProjects();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            self::$ensured = true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $release->execute(['lock_name' => $lockName]);
        }
    }

    public function nextNumber(?string $date = null): string
    {
        $year = $this->resolveYear($date);
        $this->pdo->prepare(
            'INSERT IGNORE INTO project_number_sequences(sequence_year,last_number,updated_at)
             VALUES(:year,0,NOW())'
        )->execute(['year'=>$year]);
        $this->pdo->prepare(
            'UPDATE project_number_sequences
             SET last_number=LAST_INSERT_ID(last_number+1),updated_at=NOW()
             WHERE sequence_year=:year'
        )->execute(['year'=>$year]);
        $number=(int)$this->pdo->lastInsertId();
        if($number<=0){
            $stmt=$this->pdo->prepare('SELECT last_number FROM project_number_sequences WHERE sequence_year=:year');
            $stmt->execute(['year'=>$year]);
            $number=(int)$stmt->fetchColumn();
        }
        return sprintf('%s-%04d-%05d',self::PREFIX,$year,$number);
    }

    public function recordCreatedProject(int $projectId,string $number,string $date): void
    {
        if($projectId<=0 || $number==='') return;
        $this->pdo->prepare(
            'INSERT INTO project_number_history
                (project_id,old_number,new_number,nomenclature_version,migrated_at)
             VALUES(:project_id,NULL,:new_number,:version,NOW())
             ON DUPLICATE KEY UPDATE new_number=VALUES(new_number)'
        )->execute([
            'project_id'=>$projectId,
            'new_number'=>$number,
            'version'=>self::VERSION,
        ]);
    }

    private function migrateExistingProjects(): void
    {
        $rows=$this->pdo->query(
            'SELECT o.id_offre,o.num_offre,o.date_offre,o.date_creat_offre
             FROM offre_banamur o
             LEFT JOIN project_number_history h ON h.project_id=o.id_offre
             WHERE h.project_id IS NULL
             ORDER BY COALESCE(NULLIF(o.date_offre,"0000-00-00"),NULLIF(o.date_creat_offre,"0000-00-00")) ASC,o.id_offre ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if(!$rows) return;

        $update=$this->pdo->prepare('UPDATE offre_banamur SET num_offre=:number WHERE id_offre=:id');
        $history=$this->pdo->prepare(
            'INSERT INTO project_number_history
                (project_id,old_number,new_number,nomenclature_version,migrated_at)
             VALUES(:project_id,:old_number,:new_number,:version,NOW())'
        );
        foreach($rows as $row){
            $date=(string)(($row['date_offre']??'') ?: ($row['date_creat_offre']??''));
            $newNumber=$this->nextNumber($date);
            $update->execute(['number'=>$newNumber,'id'=>(int)$row['id_offre']]);
            $history->execute([
                'project_id'=>(int)$row['id_offre'],
                'old_number'=>trim((string)($row['num_offre']??'')) ?: null,
                'new_number'=>$newNumber,
                'version'=>self::VERSION,
            ]);
        }
    }

    private function resolveYear(?string $date): int
    {
        $date=trim((string)$date);
        if(preg_match('/^((?:19|20)\\d{2})-\\d{2}-\\d{2}$/',$date,$match)){
            return (int)$match[1];
        }
        return (int)date('Y');
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS project_number_sequences (
                sequence_year SMALLINT UNSIGNED PRIMARY KEY,
                last_number INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS project_number_history (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                old_number VARCHAR(190) NULL,
                new_number VARCHAR(40) NOT NULL,
                nomenclature_version VARCHAR(40) NOT NULL,
                migrated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_project_number_history_project(project_id),
                UNIQUE KEY uq_project_number_history_new(new_number),
                KEY idx_project_number_history_old(old_number)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }
}
