<?php

require_once __DIR__ . '/../repositories/RhScheduleRepository.php';

class RhScheduleService
{
    private RhScheduleRepository $repository;

    public function __construct(RhScheduleRepository $repository)
    {
        $this->repository = $repository;
    }

    public function model(): EmployeeSchedule
    {
        return $this->repository->model();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->repository->{$name}(...$arguments);
    }
}
