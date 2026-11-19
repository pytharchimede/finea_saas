<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/CrmSchema.php';
require_once __DIR__ . '/../model/AttendanceSyncService.php';
require_once __DIR__ . '/../model/EmployeeScheduleRecurrence.php';
require_once __DIR__ . '/../services/CrmReminderService.php';
require_once __DIR__ . '/../services/CrmProspectFollowupService.php';

class CronController
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function sendCrmRdvReminders(DateTimeImmutable $now): array
    {
        \CrmSchema::ensureTables($this->pdo);
        return $this->crmReminderService()->sendRdvReminders($now);
    }

    public function sendCrmOpportunityNextActionReminders(DateTimeImmutable $now): array
    {
        \CrmSchema::ensureTables($this->pdo);
        return $this->crmReminderService()->sendOpportunityNextActionReminders($now);
    }

    public function sendInactiveProspectFollowups(DateTimeImmutable $now, int $daysThreshold, int $durationMin): array
    {
        \CrmSchema::ensureTables($this->pdo);
        $service = new CrmProspectFollowupService(
            new CrmProspectFollowupRepository($this->pdo),
            new NotificationController($this->pdo),
            new EmployeeSchedule($this->pdo)
        );

        return $service->sendInactiveProspectFollowups($now, $daysThreshold, $durationMin);
    }

    public function syncAttendanceToday(?DateTimeImmutable $today, int $limit): array
    {
        return (new AttendanceSyncService($this->pdo))->syncToday($today, $limit);
    }

    public function generateRecurringScheduleEvents(DateTimeImmutable $now, int $lookAheadDays = 30, int $limit = 60): array
    {
        $recurrence = new EmployeeScheduleRecurrence($this->pdo);
        if (!$recurrence->isReady()) {
            return ['ok' => false, 'error' => $recurrence->getInitError() ?: 'Module recurrence indisponible.'];
        }

        return $recurrence->generateDueOccurrences($now, $lookAheadDays, $limit);
    }

    private function crmReminderService(): CrmReminderService
    {
        return new CrmReminderService(
            new CrmReminderRepository($this->pdo),
            new NotificationController($this->pdo)
        );
    }
}
