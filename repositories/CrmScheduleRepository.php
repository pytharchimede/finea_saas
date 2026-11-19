<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';

class CrmScheduleRepository
{
    private EmployeeSchedule $schedule;

    public function __construct(?PDO $pdo = null)
    {
        $this->schedule = new EmployeeSchedule($pdo ?: \Database::getConnection());
    }

    public function isReady(): bool
    {
        return $this->schedule->isReady();
    }

    public function getInitError(): ?string
    {
        return $this->schedule->getInitError();
    }

    public function resolvePersonnelIdByEmail(string $email): ?int
    {
        $id = $this->schedule->resolvePersonnelIdByEmail($email);
        return $id ? (int) $id : null;
    }

    public function createEvent(int $personnelId, string $title, string $startAt, string $endAt, string $statusCode, ?int $createdBy = null): array
    {
        return $this->schedule->createEvent($personnelId, $title, $startAt, $endAt, $statusCode, $createdBy);
    }

    public function updateEvent(int $eventId, int $personnelId, string $title, string $startAt, string $endAt, string $statusCode): array
    {
        return $this->schedule->updateEvent($eventId, $personnelId, $title, $startAt, $endAt, $statusCode);
    }

    public function deleteEvent(int $eventId, int $personnelId): bool
    {
        return $this->schedule->deleteEvent($eventId, $personnelId);
    }
}
