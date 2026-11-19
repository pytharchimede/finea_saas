<?php

require_once __DIR__ . '/../repositories/RhAttendanceRepository.php';

class RhAttendanceService
{
    private RhAttendanceRepository $repository;

    public function __construct(RhAttendanceRepository $repository)
    {
        $this->repository = $repository;
    }

    public function sync(): AttendanceSyncService
    {
        return $this->repository->sync();
    }

    public function absenceNotifier(): RhAbsenceNotifier
    {
        return $this->repository->absenceNotifier();
    }

    public function listPointageEligiblePersonnel(): array
    {
        return $this->repository->listPointageEligiblePersonnel();
    }
}
