<?php

require_once __DIR__ . '/../repositories/CrmReminderRepository.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

class CrmReminderService
{
    private CrmReminderRepository $repository;
    private NotificationController $notifications;

    public function __construct(CrmReminderRepository $repository, NotificationController $notifications)
    {
        $this->repository = $repository;
        $this->notifications = $notifications;
    }

    public function sendRdvReminders(DateTimeImmutable $now): array
    {
        $this->notifications->ensureDefaultRulesForCrmRdvReminder();
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->windows($now) as $subKey => $cfg) {
            foreach ($this->repository->listRdvReminderCandidates($cfg['from'], $cfg['to'], $cfg['column']) as $row) {
                $id = (int)($row['id'] ?? 0);
                $recipientUserId = (int)($row['created_by'] ?? 0);
                if ($id <= 0 || $recipientUserId <= 0) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    $this->notifications->notifyCrmRdvReminder($recipientUserId, $subKey, $row);
                    $this->repository->markRdvReminderSent($id, $cfg['column']);
                    $stats['sent']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    public function sendOpportunityNextActionReminders(DateTimeImmutable $now): array
    {
        if (!$this->repository->hasTableColumns('crm_opportunite_banamur', ['id', 'titre', 'next_action_at', 'created_by', 'reminder_24h_sent_at', 'reminder_1h_sent_at'])) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'skipped_reason' => 'missing_columns'];
        }

        $this->notifications->ensureDefaultRulesForCrmOpportunityNextActionReminder();
        $stats = ['sent' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->windows($now) as $subKey => $cfg) {
            foreach ($this->repository->listOpportunityReminderCandidates($cfg['from'], $cfg['to'], $cfg['column']) as $row) {
                $id = (int)($row['id'] ?? 0);
                $recipientUserId = (int)($row['created_by'] ?? 0);
                if ($id <= 0 || $recipientUserId <= 0) {
                    $stats['skipped']++;
                    continue;
                }
                try {
                    $this->notifications->notifyCrmOpportunityNextActionReminder($recipientUserId, $subKey, $row);
                    $this->repository->markOpportunityReminderSent($id, $cfg['column']);
                    $stats['sent']++;
                } catch (Throwable $e) {
                    $stats['errors']++;
                }
            }
        }

        return $stats;
    }

    private function windows(DateTimeImmutable $now): array
    {
        return [
            '24h' => [
                'from' => $now->modify('+23 hours 45 minutes')->format('Y-m-d H:i:s'),
                'to' => $now->modify('+24 hours 20 minutes')->format('Y-m-d H:i:s'),
                'column' => 'reminder_24h_sent_at',
            ],
            '1h' => [
                'from' => $now->modify('+50 minutes')->format('Y-m-d H:i:s'),
                'to' => $now->modify('+1 hour 20 minutes')->format('Y-m-d H:i:s'),
                'column' => 'reminder_1h_sent_at',
            ],
        ];
    }
}
