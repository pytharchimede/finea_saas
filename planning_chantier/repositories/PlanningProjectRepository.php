<?php
declare(strict_types=1);

final class PlanningProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function findAll(array $filters = []): array
    {
        $where = [
            "p.statut = 'actif'",
            'p.chantier_id IS NOT NULL',
            'c.archived_at IS NULL',
            'c.closed_date IS NULL',
        ];
        $params = [];

        $sql = "SELECT p.* FROM planning_chantier_projects p LEFT JOIN chantier_banamur c ON c.id=p.chantier_id";
        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY FIELD(p.statut,'actif','suspendu','termine','archive'), p.created_at DESC, p.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function active(): array
    {
        return $this->findAll(['statut' => 'actif']);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT p.*
            FROM planning_chantier_projects p
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE p.id=:id AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL
            LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function syncFromChantiers(): int
    {
        if (!$this->tableExists('chantier_banamur')) {
            return 0;
        }

        $columns = $this->columns('chantier_banamur');
        $titleExpr = in_array('titre', $columns, true)
            ? "COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id))"
            : "COALESCE(NULLIF(TRIM(code), ''), CONCAT('Chantier #', id))";
        $codeExpr = in_array('code', $columns, true) ? "code" : "CONCAT('CH-', id)";
        $dateDebutExpr = in_array('date_debut', $columns, true) ? "date_debut" : "NULL";
        $dateFinExpr = in_array('date_fin_prevue', $columns, true) ? "date_fin_prevue" : "NULL";
        $active = [];
        if (in_array('archived_at', $columns, true)) $active[] = 'archived_at IS NULL';
        if (in_array('closed_date', $columns, true)) $active[] = 'closed_date IS NULL';
        $archiveWhere = $active ? ' WHERE ' . implode(' AND ', $active) : '';

        $rows = $this->pdo->query("SELECT id, $codeExpr code, $titleExpr libelle, $dateDebutExpr date_debut, $dateFinExpr date_fin_prevue FROM chantier_banamur$archiveWhere ORDER BY id DESC LIMIT 500")
            ->fetchAll(PDO::FETCH_ASSOC);

        $count = 0;
        foreach ($rows as $row) {
            $exists = $this->pdo->prepare("SELECT id FROM planning_chantier_projects WHERE chantier_id = :chantier_id LIMIT 1");
            $exists->execute(['chantier_id' => (int)$row['id']]);
            if ($exists->fetchColumn()) {
                continue;
            }

            $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_projects (chantier_id, code, libelle, date_debut, date_fin_prevue, statut, created_at)
                VALUES (:chantier_id, :code, :libelle, :date_debut, :date_fin_prevue, 'actif', NOW())");
            $stmt->execute([
                'chantier_id' => (int)$row['id'],
                'code' => (string)($row['code'] ?? ''),
                'libelle' => (string)($row['libelle'] ?? ('Chantier #' . $row['id'])),
                'date_debut' => $row['date_debut'] ?: null,
                'date_fin_prevue' => $row['date_fin_prevue'] ?: null,
            ]);
            $count++;
        }

        return $count;
    }

    public function save(array $data, ?int $id = null): int
    {
        if ($id) {
            $stmt = $this->pdo->prepare("UPDATE planning_chantier_projects SET
                code = :code, libelle = :libelle, description = :description, responsable_id = :responsable_id,
                date_debut = :date_debut, date_fin_prevue = :date_fin_prevue, statut = :statut, updated_at = NOW()
                WHERE id = :id");
            $stmt->execute([
                'id' => $id,
                'code' => $data['code'] ?? null,
                'libelle' => $data['libelle'],
                'description' => $data['description'] ?? null,
                'responsable_id' => $data['responsable_id'] ?: null,
                'date_debut' => $data['date_debut'] ?: null,
                'date_fin_prevue' => $data['date_fin_prevue'] ?: null,
                'statut' => $data['statut'] ?? 'actif',
            ]);
            return $id;
        }

        $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_projects
            (code, libelle, description, responsable_id, date_debut, date_fin_prevue, statut, created_by, created_at)
            VALUES (:code, :libelle, :description, :responsable_id, :date_debut, :date_fin_prevue, :statut, :created_by, NOW())");
        $stmt->execute([
            'code' => $data['code'] ?? null,
            'libelle' => $data['libelle'],
            'description' => $data['description'] ?? null,
            'responsable_id' => $data['responsable_id'] ?: null,
            'date_debut' => $data['date_debut'] ?: null,
            'date_fin_prevue' => $data['date_fin_prevue'] ?: null,
            'statut' => $data['statut'] ?? 'actif',
            'created_by' => $data['created_by'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW TABLES LIKE :table");
            $stmt->execute(['table' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function columns(string $table): array
    {
        try {
            return $this->pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN, 0);
        } catch (Throwable) {
            return [];
        }
    }
}
