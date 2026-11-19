<?php
declare(strict_types=1);

require_once __DIR__ . '/CommercialProjectFinanceMigrationService.php';
require_once __DIR__ . '/../repositories/CommercialProjectFinanceRepository.php';

final class ChantierLifecycleService
{
    public function __construct(private PDO $pdo)
    {
        (new CommercialProjectFinanceMigrationService($pdo))->ensureSchema();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS chantier_archive_scopes (
            chantier_id INT NOT NULL, archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_by INT NULL, scope_json LONGTEXT NOT NULL, restored_at DATETIME NULL,
            PRIMARY KEY(chantier_id), KEY idx_chantier_archive_scope_active(restored_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach (['paused_at' => 'DATETIME NULL', 'paused_by' => 'INT NULL', 'pause_note' => 'TEXT NULL'] as $column => $definition) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="chantier_banamur" AND COLUMN_NAME=:column');
            $check->execute(['column' => $column]);
            if (!(int) $check->fetchColumn()) $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN `$column` $definition");
        }
    }

    public function archive(int $chantierId, int $userId, ?string $closedDate, string $note): array
    {
        $chantier = $this->chantier($chantierId);
        if (!empty($chantier['archived_at'])) {
            throw new DomainException('Ce chantier est déjà archivé.');
        }
        $scope = $this->scope($chantierId, (int) ($chantier['devis_id'] ?? 0));
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare('UPDATE chantier_banamur SET archived_at=NOW(),archived_by=:user,archived_note=:note,closed_date=:closed,paused_at=NULL,paused_by=NULL,pause_note=NULL WHERE id=:id AND archived_at IS NULL');
            $statement->execute(['user' => $userId ?: null, 'note' => $note ?: null, 'closed' => $closedDate, 'id' => $chantierId]);
            if ((int) $chantier['devis_id'] > 0) {
                $repository = new CommercialProjectFinanceRepository($this->pdo);
                $repository->archive((int) $chantier['devis_id'], $userId, 'Archivage automatique avec le chantier ' . (string) $chantier['code'], ['cascade_chantier_id' => $chantierId] + $scope);
            }
            $save = $this->pdo->prepare('INSERT INTO chantier_archive_scopes(chantier_id,archived_by,scope_json) VALUES(:id,:user,:scope) ON DUPLICATE KEY UPDATE archived_at=NOW(),archived_by=VALUES(archived_by),scope_json=VALUES(scope_json),restored_at=NULL');
            $save->execute(['id' => $chantierId, 'user' => $userId ?: null, 'scope' => json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            if ($ownTransaction) $this->pdo->commit();
            return $scope;
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function restore(int $chantierId, int $userId): void
    {
        $chantier = $this->chantier($chantierId);
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE chantier_banamur SET archived_at=NULL,archived_by=NULL,archived_note=NULL,closed_date=NULL WHERE id=:id')->execute(['id' => $chantierId]);
            $this->pdo->prepare('UPDATE chantier_archive_scopes SET restored_at=NOW() WHERE chantier_id=:id AND restored_at IS NULL')->execute(['id' => $chantierId]);
            if ((int) ($chantier['devis_id'] ?? 0) > 0) {
                $this->pdo->prepare("UPDATE devis_archives SET restored_at=NOW(),restored_by_user_id=:user,restore_note='Restauration automatique avec le chantier' WHERE devis_id=:devis AND restored_at IS NULL AND JSON_EXTRACT(eligibility_snapshot_json,'$.cascade_chantier_id')=:chantier")->execute(['user' => $userId ?: null, 'devis' => (int) $chantier['devis_id'], 'chantier' => $chantierId]);
            }
            if ($ownTransaction) $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($ownTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function pause(int $chantierId, int $userId, string $note): void
    {
        $chantier = $this->chantier($chantierId);
        if (!empty($chantier['archived_at'])) throw new DomainException('Un chantier terminé ne peut pas être mis en pause.');
        if (!empty($chantier['paused_at'])) throw new DomainException('Ce chantier est déjà en pause.');
        $this->pdo->prepare('UPDATE chantier_banamur SET paused_at=NOW(),paused_by=:user,pause_note=:note WHERE id=:id AND archived_at IS NULL AND paused_at IS NULL')
            ->execute(['user' => $userId ?: null, 'note' => trim($note) ?: null, 'id' => $chantierId]);
    }

    public function resume(int $chantierId): void
    {
        $chantier = $this->chantier($chantierId);
        if (!empty($chantier['archived_at'])) throw new DomainException('Restaurez d’abord ce chantier terminé.');
        $this->pdo->prepare('UPDATE chantier_banamur SET paused_at=NULL,paused_by=NULL,pause_note=NULL WHERE id=:id AND archived_at IS NULL')->execute(['id' => $chantierId]);
    }

    private function chantier(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT id,code,titre,devis_id,archived_at,paused_at FROM chantier_banamur WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('Chantier introuvable.');
        return $row;
    }

    private function scope(int $chantierId, int $devisId): array
    {
        $counts = ['devis' => $devisId > 0 ? 1 : 0, 'debourses' => 0, 'lignes_debourse' => 0, 'fiches_decaissement' => 0, 'feb' => 0, 'planning' => 0];
        $queries = [
            'debourses' => ['SELECT COUNT(*) FROM debourse_banamur WHERE devis_id=:id', $devisId],
            'lignes_debourse' => ['SELECT COUNT(*) FROM ligne_debourse_banamur l JOIN debourse_banamur d ON d.id=l.debourse_id WHERE d.devis_id=:id', $devisId],
            'fiches_decaissement' => ['SELECT COUNT(*) FROM fiche_decaissement WHERE chantier_id=:id', $chantierId],
            'feb' => ["SELECT COUNT(*) FROM employee_legal_requests WHERE request_type='feb' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.chantier_id')) AS UNSIGNED)=:id", $chantierId],
            'planning' => ['SELECT COUNT(*) FROM planning_chantier_projects WHERE chantier_id=:id', $chantierId],
        ];
        foreach ($queries as $key => [$sql, $id]) {
            try { $statement = $this->pdo->prepare($sql); $statement->execute(['id' => $id]); $counts[$key] = (int) $statement->fetchColumn(); } catch (Throwable $e) {}
        }
        return $counts;
    }
}
