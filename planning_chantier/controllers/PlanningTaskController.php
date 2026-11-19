<?php
declare(strict_types=1);

final class PlanningTaskController
{
    public function __construct(private PlanningTaskRepository $tasks) {}

    public function save(array $data, ?int $id = null): int
    {
        if (trim((string)($data['titre'] ?? '')) === '') {
            throw new InvalidArgumentException('Le titre de la tâche est obligatoire.');
        }
        if ((int)($data['project_id'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Le chantier est obligatoire.');
        }
        $parentId = (int)($data['parent_id'] ?? 0);
        if ($parentId > 0) {
            $parent = $this->tasks->find($parentId);
            if (!$parent || (int)$parent['project_id'] !== (int)$data['project_id'] || ($id && $parentId === $id)) {
                throw new InvalidArgumentException('La tâche parente doit appartenir au même chantier.');
            }
        }
        return $this->tasks->save($data, $id);
    }
}
