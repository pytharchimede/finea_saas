<?php

require_once __DIR__ . '/../services/RhScheduleService.php';

class RhScheduleController
{
    private RhScheduleService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhScheduleService(new RhScheduleRepository($pdo));
    }

    public function service(): RhScheduleService
    {
        return $this->service;
    }

    public function model(): EmployeeSchedule
    {
        return $this->service->model();
    }
}
