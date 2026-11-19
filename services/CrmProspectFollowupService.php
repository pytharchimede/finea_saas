<?php

require_once __DIR__ . '/../repositories/CrmProspectFollowupRepository.php';
require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../model/EmployeeSchedule.php';

class CrmProspectFollowupService
{
    private CrmProspectFollowupRepository $repository;
    private NotificationController $notifications;
    private EmployeeSchedule $schedule;

    public function __construct(CrmProspectFollowupRepository $repository, NotificationController $notifications, EmployeeSchedule $schedule)
    {
        $this->repository = $repository;
        $this->notifications = $notifications;
        $this->schedule = $schedule;
    }

    public function sendInactiveProspectFollowups(DateTimeImmutable $now, int $daysThreshold, int $durationMin): array
    {
        if (!$this->repository->hasProspectFollowupColumns()) {
            return ['alerts' => 0, 'followups' => 0, 'skipped' => 0, 'errors' => 0, 'skipped_reason' => 'missing_columns'];
        }

        $this->notifications->ensureDefaultRulesForCrmProspectInactive();

        $daysThreshold = $daysThreshold > 0 ? $daysThreshold : 14;
        $durationMin = max(10, min(180, $durationMin > 0 ? $durationMin : 30));
        $stats = ['alerts' => 0, 'followups' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->repository->listProspectCandidates() as $row) {
            $prospectId = (int) ($row['id'] ?? 0);
            $recipientUserId = (int) ($row['created_by'] ?? 0);
            if ($prospectId <= 0 || $recipientUserId <= 0) {
                $stats['skipped']++;
                continue;
            }

            $lastActivity = $this->latestActivity($row);
            if ($lastActivity === null) {
                $stats['skipped']++;
                continue;
            }

            $daysInactive = (int) floor(($now->getTimestamp() - $lastActivity->getTimestamp()) / 86400);
            if ($daysInactive < $daysThreshold) {
                continue;
            }

            $lastAlertAt = $this->dateOrNull($row['inactivity_last_alert_sent_at'] ?? null);
            $lastFollowupAt = $this->dateOrNull($row['inactivity_last_followup_scheduled_at'] ?? null);
            $shouldAlert = ($lastAlertAt === null || $lastAlertAt < $lastActivity);
            $shouldScheduleFollowup = ($lastFollowupAt === null || $lastFollowupAt < $lastActivity);

            if (!$shouldAlert && !$shouldScheduleFollowup) {
                continue;
            }

            try {
                if ($shouldAlert) {
                    $this->sendAlert($recipientUserId, $prospectId, $row, $daysInactive, $lastActivity);
                    $stats['alerts']++;
                }

                if ($shouldScheduleFollowup && $this->schedule->isReady()) {
                    if ($this->scheduleFollowup($recipientUserId, $prospectId, $row, $daysInactive, $lastActivity, $now, $durationMin)) {
                        $stats['followups']++;
                    }
                }
            } catch (Throwable $e) {
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function sendAlert(int $recipientUserId, int $prospectId, array $row, int $daysInactive, DateTimeImmutable $lastActivity): void
    {
        $this->notifications->notifyCrmProspectInactive($recipientUserId, [
            'id' => $prospectId,
            'nom' => (string) ($row['nom'] ?? ''),
            'societe' => (string) ($row['societe'] ?? ''),
            'days_inactive' => $daysInactive,
            'last_activity' => $lastActivity->format('Y-m-d H:i'),
        ]);

        $this->repository->markAlertSent($prospectId);
    }

    private function scheduleFollowup(int $recipientUserId, int $prospectId, array $row, int $daysInactive, DateTimeImmutable $lastActivity, DateTimeImmutable $now, int $durationMin): bool
    {
        $personnelId = $this->resolvePersonnelId($recipientUserId);
        if ($personnelId <= 0) {
            $this->repository->markFollowupScheduled($prospectId);
            return false;
        }

        $start = $this->nextWorkdayAt($now, 9, 0);
        $end = $start->modify('+' . $durationMin . ' minutes');
        $prospectNom = (string) ($row['nom'] ?? '');
        $societe = (string) ($row['societe'] ?? '');
        $title = 'CRM - Relance prospect : ' . $prospectNom;
        $desc = 'Prospect inactif depuis ' . $daysInactive . ' jours. Derniere activite : ' . $lastActivity->format('Y-m-d H:i');
        if (trim($societe) !== '') {
            $desc .= ' - Societe : ' . $societe;
        }

        $eventId = $this->upsertScheduleEvent((int) ($row['inactivity_followup_event_id'] ?? 0), $personnelId, $recipientUserId, $title, $desc, $start, $end);
        $this->repository->markFollowupScheduled($prospectId, $eventId);

        return $eventId !== null;
    }

    private function resolvePersonnelId(int $recipientUserId): int
    {
        $user = $this->repository->getUserMailAndPersonnel($recipientUserId);
        if (!$user) {
            return 0;
        }

        $personnelId = (int) ($user['personnel_id'] ?? 0);
        if ($personnelId > 0) {
            return $personnelId;
        }

        $mail = trim((string) ($user['mail_pro'] ?? ''));
        if ($mail === '') {
            return 0;
        }

        $resolved = $this->schedule->resolvePersonnelIdByEmail($mail);
        return $resolved ? (int) $resolved : 0;
    }

    private function upsertScheduleEvent(int $existingEventId, int $personnelId, int $recipientUserId, string $title, string $desc, DateTimeImmutable $start, DateTimeImmutable $end): ?int
    {
        if ($existingEventId > 0) {
            $res = $this->schedule->updateEvent(
                $existingEventId,
                $personnelId,
                $title,
                $start->format('Y-m-d H:i:s'),
                $end->format('Y-m-d H:i:s'),
                'planifie',
                $desc,
                null
            );
            if (!empty($res['ok'])) {
                return $existingEventId;
            }
        }

        $res = $this->schedule->createEvent(
            $personnelId,
            $title,
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
            'planifie',
            $recipientUserId,
            $desc,
            null,
            null
        );

        return (!empty($res['ok']) && !empty($res['id'])) ? (int) $res['id'] : null;
    }

    private function latestActivity(array $row): ?DateTimeImmutable
    {
        $latest = null;
        foreach (['prospect_activity', 'last_rdv_at', 'last_opp_at'] as $key) {
            $date = $this->dateOrNull($row[$key] ?? null);
            if ($date !== null && ($latest === null || $date > $latest)) {
                $latest = $date;
            }
        }
        return $latest;
    }

    private function dateOrNull($raw): ?DateTimeImmutable
    {
        $value = trim((string) ($raw ?? ''));
        if ($value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function nextWorkdayAt(DateTimeImmutable $from, int $hour, int $minute): DateTimeImmutable
    {
        $candidate = $from->setTime($hour, $minute, 0);
        if ($candidate <= $from) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        while ((int) $candidate->format('N') >= 6) {
            $candidate = $candidate->modify('+1 day')->setTime($hour, $minute, 0);
        }

        return $candidate;
    }
}
