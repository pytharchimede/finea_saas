<?php
declare(strict_types=1);

final class PlanningTaskRepository
{
    public function __construct(private PDO $pdo) {}

    public function findByProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare("SELECT t.* FROM planning_chantier_tasks t
            INNER JOIN planning_chantier_projects p ON p.id=t.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE t.project_id=:project_id AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL
            ORDER BY t.ordre ASC,t.date_debut_prevue ASC,t.id ASC");
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT t.* FROM planning_chantier_tasks t
            INNER JOIN planning_chantier_projects p ON p.id=t.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE t.id=:id AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL
            LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function allWithProject(): array
    {
        return $this->pdo->query("SELECT t.*, p.libelle project_label, p.code project_code, parent.titre parent_title
            FROM planning_chantier_tasks t
            LEFT JOIN planning_chantier_tasks parent ON parent.id=t.parent_id
            INNER JOIN planning_chantier_projects p ON p.id = t.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE p.statut='actif' AND c.archived_at IS NULL AND c.closed_date IS NULL
            ORDER BY p.libelle ASC, COALESCE(t.parent_id,t.id) ASC, t.parent_id IS NOT NULL ASC, t.ordre ASC, t.id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data, ?int $id = null): int
    {
        $payload = [
            'project_id' => (int)$data['project_id'],
            'parent_id' => !empty($data['parent_id']) ? (int)$data['parent_id'] : null,
            'titre' => trim((string)$data['titre']),
            'description' => trim((string)($data['description'] ?? '')),
            'ordre' => (int)($data['ordre'] ?? 0),
            'date_debut_prevue' => $data['date_debut_prevue'] ?: null,
            'date_fin_prevue' => $data['date_fin_prevue'] ?: null,
            'poids' => max(0.01, (float)($data['poids'] ?? 1)),
            'avancement_prevu' => min(100, max(0, (float)($data['avancement_prevu'] ?? 0))),
            'avancement_reel' => min(100, max(0, (float)($data['avancement_reel'] ?? 0))),
            'statut' => $data['statut'] ?? 'a_faire',
            'responsable_id' => !empty($data['responsable_id']) ? (int)$data['responsable_id'] : null,
        ];

        if ($id) {
            $payload['id'] = $id;
            $stmt = $this->pdo->prepare("UPDATE planning_chantier_tasks SET
                project_id=:project_id, parent_id=:parent_id, titre=:titre, description=:description, ordre=:ordre,
                date_debut_prevue=:date_debut_prevue, date_fin_prevue=:date_fin_prevue, poids=:poids,
                avancement_prevu=:avancement_prevu, avancement_reel=:avancement_reel, statut=:statut,
                responsable_id=:responsable_id, updated_at=NOW()
                WHERE id=:id");
            $stmt->execute($payload);
            return $id;
        }

        $payload['created_by'] = $data['created_by'] ?? null;
        $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_tasks
            (project_id,parent_id,titre,description,ordre,date_debut_prevue,date_fin_prevue,poids,avancement_prevu,avancement_reel,statut,responsable_id,created_by,created_at)
            VALUES (:project_id,:parent_id,:titre,:description,:ordre,:date_debut_prevue,:date_fin_prevue,:poids,:avancement_prevu,:avancement_reel,:statut,:responsable_id,:created_by,NOW())");
        $stmt->execute($payload);
        return (int)$this->pdo->lastInsertId();
    }

    public function updateProgress(int $taskId, float $realProgress, ?string $comment, ?int $userId): void
    {
        $task = $this->find($taskId);
        if (!$task) {
            throw new RuntimeException('Tâche introuvable');
        }

        $realProgress = min(100, max(0, $realProgress));
        $status = $realProgress >= 100 ? 'termine' : ((string)$task['statut'] === 'a_faire' && $realProgress > 0 ? 'en_cours' : (string)$task['statut']);

        $stmt = $this->pdo->prepare("UPDATE planning_chantier_tasks SET avancement_reel=:avancement_reel, statut=:statut, updated_at=NOW() WHERE id=:id");
        $stmt->execute(['avancement_reel' => $realProgress, 'statut' => $status, 'id' => $taskId]);

        $planned = (float)($task['avancement_prevu'] ?? 0);
        $log = $this->pdo->prepare("INSERT INTO planning_chantier_progress_logs
            (task_id, project_id, meeting_date, avancement_reel, avancement_prevu, ecart, commentaire, created_by, created_at)
            VALUES (:task_id, :project_id, CURDATE(), :avancement_reel, :avancement_prevu, :ecart, :commentaire, :created_by, NOW())");
        $log->execute([
            'task_id' => $taskId,
            'project_id' => (int)$task['project_id'],
            'avancement_reel' => $realProgress,
            'avancement_prevu' => $planned,
            'ecart' => $realProgress - $planned,
            'commentaire' => $comment,
            'created_by' => $userId,
        ]);
    }
}
