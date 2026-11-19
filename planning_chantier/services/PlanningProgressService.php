<?php
declare(strict_types=1);

final class PlanningProgressService
{
    public function __construct(private PlanningTaskRepository $tasks) {}

    public function updateTaskProgress(int $taskId, float $progress, ?string $comment, ?int $userId): void
    {
        $this->tasks->updateProgress($taskId, $progress, $comment, $userId);
    }
}
