<?php

require_once __DIR__ . '/../model/EmployeeSchedule.php';

class RhScheduleRepository
{
    private EmployeeSchedule $schedule;

    public function __construct(PDO $pdo)
    {
        $this->schedule = new EmployeeSchedule($pdo);
    }

    public function model(): EmployeeSchedule
    {
        return $this->schedule;
    }

    public function __call(string $name, array $arguments)
    {
        return $this->schedule->{$name}(...$arguments);
    }
}
