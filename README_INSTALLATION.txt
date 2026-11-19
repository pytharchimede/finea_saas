Patch FINEA - Popup notification avec validation de lecture QCM

Fichiers à remplacer/ajouter :
- repository/PopupNotificationRepository.php -> votre dossier repository
- service/PopupNotificationService.php -> votre dossier service actuel
- views/popup_notification_form.php -> dossier de vos pages notifications
- views/popup_notification_reads.php -> dossier de vos pages notifications
- assets/js/finea-popup-notifications.js -> assets/js/
- ajax/submit_popup_notification_read_validation.php -> ajax/ (nouveau fichier)

La migration est auto-gérée par PopupNotificationRepository::ensureTables().
