<?php
declare(strict_types=1);

final class PlanningMeetingService
{
    public function __construct(
        private PlanningProjectRepository $projects,
        private PlanningTaskRepository $tasks,
        private PlanningBlockageRepository $blockages,
        private PlanningMeetingRepository $meetings
    ) {}

    public function dailyBoard(): array
    {
        $projects = $this->projects->active();
        $critical = [];
        foreach ($projects as $project) {
            $tasks = $this->tasks->findByProject((int)$project['id']);
            foreach ($tasks as $task) {
                $planned = (float)($task['avancement_prevu'] ?? 0);
                $real = (float)($task['avancement_reel'] ?? 0);
                if (($planned - $real) >= 5 || (string)$task['statut'] === 'bloque') {
                    $critical[] = [
                        'project' => $project,
                        'task' => $task,
                        'ecart' => $real - $planned,
                    ];
                }
            }
        }

        usort($critical, static fn($a, $b) => $a['ecart'] <=> $b['ecart']);

        return [
            'projects' => $projects,
            'critical_tasks' => $critical,
            'open_blockages' => $this->blockages->open(),
            'recent_meetings' => $this->meetings->recent(8),
            'participant_options' => $this->meetings->participantOptions(),
        ];
    }
}
