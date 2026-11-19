<?php
declare(strict_types=1);

final class PlanningDashboardController
{
    public function __construct(private PlanningDashboardService $service) {}

    public function index(): array
    {
        return $this->service->dashboard();
    }
}
