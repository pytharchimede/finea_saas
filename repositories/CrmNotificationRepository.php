<?php

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

class CrmNotificationRepository
{
    private NotificationController $notifications;

    public function __construct(?PDO $pdo = null)
    {
        $this->notifications = new NotificationController($pdo ?: \Database::getConnection());
    }

    public function notifyRdvCreated(int $recipientUserId, array $rdv): void
    {
        if ($recipientUserId <= 0) {
            return;
        }
        $this->notifications->ensureDefaultRulesForCrmRdvCreated();
        $this->notifications->notifyCrmRdvCreated($recipientUserId, $rdv);
    }
}
