<?php
declare(strict_types=1);

final class PlanningDashboardService
{
    public function __construct(
        private PlanningProjectRepository $projects,
        private PlanningTaskRepository $tasks,
        private PlanningBlockageRepository $blockages
    ) {}

    public function dashboard(): array
    {
        $this->projects->syncFromChantiers();

        $projects = $this->projects->active();
        $rows = [];
        $totalPlanned = 0.0;
        $totalReal = 0.0;
        $lateProjects = 0;
        $blockedProjects = 0;

        foreach ($projects as $project) {
            $projectTasks = $this->tasks->findByProject((int)$project['id']);
            $stats = $this->computeProjectStats($projectTasks);
            $projectBlockages = $this->blockages->byProject((int)$project['id']);
            $openBlockages = array_values(array_filter($projectBlockages, static fn($b) => in_array((string)$b['statut'], ['ouvert','en_traitement'], true)));

            $status = 'maitrise';
            if (!$projectTasks) {
                $status = 'sans_planning';
            } elseif ($openBlockages) {
                $status = 'bloque';
                $blockedProjects++;
            } elseif ($stats['ecart'] < -5) {
                $status = 'retard';
                $lateProjects++;
            } elseif ($stats['real'] >= 95) {
                $status = 'termine';
            }

            $totalPlanned += $stats['planned'];
            $totalReal += $stats['real'];

            $rows[] = [
                'project' => $project,
                'tasks_count' => count($projectTasks),
                'planned' => $stats['planned'],
                'real' => $stats['real'],
                'ecart' => $stats['ecart'],
                'status' => $status,
                'open_blockages' => count($openBlockages),
            ];
        }

        $count = max(1, count($projects));

        return [
            'overview' => [
                'projects' => count($projects),
                'planned' => $totalPlanned / $count,
                'real' => $totalReal / $count,
                'ecart' => ($totalReal / $count) - ($totalPlanned / $count),
                'late_projects' => $lateProjects,
                'blocked_projects' => $blockedProjects,
            ],
            'rows' => $rows,
            'open_blockages' => $this->blockages->open(),
        ];
    }

    public function computeProjectStats(array $tasks): array
    {
        if (!$tasks) {
            return ['planned' => 0.0, 'real' => 0.0, 'ecart' => 0.0];
        }

        $totalWeight = 0.0;
        $planned = 0.0;
        $real = 0.0;

        foreach ($tasks as $task) {
            $weight = max(0.01, (float)($task['poids'] ?? 1));
            $totalWeight += $weight;
            $planned += ((float)($task['avancement_prevu'] ?? 0)) * $weight;
            $real += ((float)($task['avancement_reel'] ?? 0)) * $weight;
        }

        $planned = $planned / max(0.01, $totalWeight);
        $real = $real / max(0.01, $totalWeight);

        return [
            'planned' => $planned,
            'real' => $real,
            'ecart' => $real - $planned,
        ];
    }
}
