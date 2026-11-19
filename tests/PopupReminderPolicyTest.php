<?php
declare(strict_types=1);

function popupPolicyCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$root = dirname(__DIR__);
$popupRepository = file_get_contents($root.'/repository/PopupNotificationRepository.php');
popupPolicyCheck(str_contains($popupRepository, 'is_success = GREATEST(is_success, VALUES(is_success))'), 'A successful QCM attempt must never be overwritten by a later failure');

$footer = file_get_contents($root.'/decaissement/includes/footer.php');
popupPolicyCheck(!str_contains($footer, 'meeting-daily-followup.js'), 'The global meeting follow-up popup must be disabled');

$completeness = file_get_contents($root.'/services/ClientProjectCompletenessAlertService.php');
popupPolicyCheck(!str_contains($completeness, 'finea_popup_notifications'), 'Regularization alerts must not create popups');
popupPolicyCheck(str_contains($completeness, 'system_notifications'), 'Regularization alerts must remain available as standard notifications');

$ticketReminder = file_get_contents($root.'/api/ticket_reminder.php');
popupPolicyCheck(str_contains($ticketReminder, 'assigned_user_id\']??0)===$userId'), 'Ticket popup reminders must require a direct assignment');

$ticketRepository = file_get_contents($root.'/ticketing/repositories/TicketRepository.php');
$recipientMethod = substr($ticketRepository, strpos($ticketRepository, 'public function notificationRecipients'), 1400);
popupPolicyCheck(!str_contains($recipientMethod, 'findUsersByService'), 'Service-wide ticket assignments must not notify every service member');

$pendingPopup = file_get_contents($root.'/ajax/get_pending_popup_notifications.php');
popupPolicyCheck(!str_contains($pendingPopup, 'ClientProjectCompletenessAlertService'), 'Popup polling must never dispatch regularization alerts');

echo "PopupReminderPolicyTest: OK\n";
