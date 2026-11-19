<?php
declare(strict_types=1);

final class PlanningMeetingController
{
    public function __construct(private PlanningMeetingService $service, private PlanningMeetingRepository $meetings) {}

    public function daily(): array
    {
        return $this->service->dailyBoard();
    }

    public function save(array $data): int
    {
        return $this->meetings->save($data);
    }
}
