<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
AccessManager::requirePageAccess();

$emailController = new \EmailController();


// Oublier le mot de passe mail (session + base) pour forcer une nouvelle saisie.
$emailController->clearMailboxPasswordForUser((int)($_SESSION['user_id'] ?? 0));
unset($_SESSION['personnel_email_password']);
unset($_SESSION['mail_password_source'], $_SESSION['mail_password_persisted']);

header('Location: mailbox_emails.php');
exit;




