<?php
declare(strict_types=1);

final class PlanningBlockageRepository
{
    public function __construct(private PDO $pdo) {}

    public function open(): array
    {
        return $this->pdo->query("SELECT b.*, t.titre task_title, p.libelle project_label
            FROM planning_chantier_blockages b
            INNER JOIN planning_chantier_tasks t ON t.id = b.task_id
            INNER JOIN planning_chantier_projects p ON p.id = b.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE b.statut IN ('ouvert','en_traitement') AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL
            ORDER BY FIELD(b.priorite,'critique','haute','normale','basse'), b.date_detection DESC, b.id DESC")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function byProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare("SELECT b.*, t.titre task_title
            FROM planning_chantier_blockages b
            LEFT JOIN planning_chantier_tasks t ON t.id = b.task_id
            INNER JOIN planning_chantier_projects p ON p.id=b.project_id
            INNER JOIN chantier_banamur c ON c.id=p.chantier_id
            WHERE b.project_id=:project_id AND p.statut='actif'
              AND c.archived_at IS NULL AND c.closed_date IS NULL
            ORDER BY FIELD(b.statut,'ouvert','en_traitement','resolu','abandonne'), b.id DESC");
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(array $data): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO planning_chantier_blockages
            (task_id, project_id, titre, cause, impact, action_corrective, priorite, statut, responsable_correction_id, date_detection, created_by, created_at)
            VALUES (:task_id, :project_id, :titre, :cause, :impact, :action_corrective, :priorite, :statut, :responsable_correction_id, :date_detection, :created_by, NOW())");
        $stmt->execute([
            'task_id' => (int)$data['task_id'],
            'project_id' => (int)$data['project_id'],
            'titre' => trim((string)$data['titre']),
            'cause' => trim((string)$data['cause']),
            'impact' => trim((string)($data['impact'] ?? '')),
            'action_corrective' => trim((string)($data['action_corrective'] ?? '')),
            'priorite' => $data['priorite'] ?? 'normale',
            'statut' => $data['statut'] ?? 'ouvert',
            'responsable_correction_id' => !empty($data['responsable_correction_id']) ? (int)$data['responsable_correction_id'] : null,
            'date_detection' => $data['date_detection'] ?: date('Y-m-d'),
            'created_by' => $data['created_by'] ?? null,
        ]);

        $this->pdo->prepare("UPDATE planning_chantier_tasks SET statut='bloque', updated_at=NOW() WHERE id=:id")
            ->execute(['id' => (int)$data['task_id']]);

        return (int)$this->pdo->lastInsertId();
    }

    public function close(int $id): void
    {
        $stmt = $this->pdo->prepare("UPDATE planning_chantier_blockages SET statut='resolu', date_resolution=CURDATE(), updated_at=NOW() WHERE id=:id");
        $stmt->execute(['id' => $id]);
    }
}
