<?php
declare(strict_types=1);

final class PlanningChantierController
{
    public function __construct(
        private PlanningProjectRepository $projects,
        private PlanningTaskRepository $tasks,
        private PlanningBlockageRepository $blockages,
        private PlanningDashboardService $dashboardService
    ) {}

    public function show(int $id): array
    {
        $project = $this->projects->find($id);
        if (!$project) {
            throw new RuntimeException('Chantier introuvable');
        }
        $tasks = $this->tasks->findByProject($id);
        return [
            'project' => $project,
            'tasks' => $tasks,
            'blockages' => $this->blockages->byProject($id),
            'stats' => $this->dashboardService->computeProjectStats($tasks),
        ];
    }
}
