<?php

declare(strict_types=1);

require_once __DIR__ . '/../repositories/LogisticsDashboardRepository.php';

final class LogisticsDashboardService
{
    public function __construct(private LogisticsDashboardRepository $repository) {}

    public function viewData(): array
    {
        return [
            'kpis' => $this->repository->kpis(),
            'alerts' => $this->repository->alerts(),
            'requests' => $this->repository->recent('logistics_requests'),
            'maintenances' => $this->repository->recent('logistics_maintenances'),
            'incidents' => $this->repository->recent('logistics_incidents'),
            'trips' => $this->repository->recent('logistics_trip_batches'),
        ];
    }
}
