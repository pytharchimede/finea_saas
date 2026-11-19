<?php

require_once __DIR__ . '/../services/RhAttendanceService.php';

class RhAttendanceController
{
    private RhAttendanceService $service;

    public function __construct(PDO $pdo)
    {
        $this->service = new RhAttendanceService(new RhAttendanceRepository($pdo));
    }

    public function sync(): AttendanceSyncService
    {
        return $this->service->sync();
    }

    public function absenceNotifier(): RhAbsenceNotifier
    {
        return $this->service->absenceNotifier();
    }

    public function listPointageEligiblePersonnel(): array
    {
        return $this->service->listPointageEligiblePersonnel();
    }
}
