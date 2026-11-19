<?php

require_once __DIR__ . '/../services/RhDashboardService.php';

class RhDashboardController
{
    private RhDashboardService $service;

    public function __construct(PDO $pdo)
    {
        $repository = new RhDashboardRepository($pdo);
        $this->service = new RhDashboardService($repository);
    }

    public function handle(): array
    {
        return $this->service->buildDashboard();
    }
}
