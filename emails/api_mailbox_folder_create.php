<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';
require_once __DIR__ . '/../model/AuditTrail.php';

header('Content-Type: application/json; charset=UTF-8');

$username = trim((string)($_SESSION['mail_pro'] ?? ''));

$emailController->hydrateMailboxPasswordFromStorage((int)($_SESSION['user_id'] ?? 0));
$password = (string)($_SESSION['personnel_email_password'] ?? '');

if ($username === '' || trim($password) === '') {
    \AuditTrail::log('mailbox_folder_create_failed', [
        'module' => 'emails',
        'action' => 'mailbox_folder_create',
        'entity_type' => 'mailbox_folder',
        'entity_label' => 'Creation dossier messagerie',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'mail_session_not_ready',
        ],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Non connecté à la messagerie.']);
    exit;
}

$name = trim((string)($_POST['name'] ?? $_GET['name'] ?? ''));
if ($name === '') {
    \AuditTrail::log('mailbox_folder_create_failed', [
        'module' => 'emails',
        'action' => 'mailbox_folder_create',
        'entity_type' => 'mailbox_folder',
        'entity_label' => 'Creation dossier messagerie',
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'missing_folder_name',
        ],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Nom de dossier requis.']);
    exit;
}

// Basic hardening: prevent server-spec injection and null bytes
if (strpos($name, "\0") !== false || strpos($name, '{') !== false || strpos($name, '}') !== false) {
    \AuditTrail::log('mailbox_folder_create_failed', [
        'module' => 'emails',
        'action' => 'mailbox_folder_create',
        'entity_type' => 'mailbox_folder',
        'entity_label' => $name,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'invalid_folder_name',
        ],
    ]);
    echo json_encode(['ok' => false, 'error' => 'Nom de dossier invalide.']);
    exit;
}

$fetcher = new EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    \AuditTrail::log('mailbox_folder_create_failed', [
        'module' => 'emails',
        'action' => 'mailbox_folder_create',
        'entity_type' => 'mailbox_folder',
        'entity_label' => $name,
        'result' => 'failed',
        'severity' => 'warning',
        'details' => [
            'reason' => 'imap_connection_failed',
            'error_message' => $fetcher->getLastError() ?: null,
        ],
    ]);
    echo json_encode(['ok' => false, 'error' => $fetcher->getLastError() ?: 'Connexion IMAP impossible.']);
    $fetcher->closeConnection();
    exit;
}

$emailController->persistMailboxPasswordIfNeeded((int)($_SESSION['user_id'] ?? 0));

$ok = $fetcher->createMailbox($name);
$error = $ok ? null : ($fetcher->getLastError() ?: 'Création du dossier impossible.');
$fetcher->closeConnection();

\AuditTrail::log($ok ? 'mailbox_folder_created' : 'mailbox_folder_create_failed', [
    'module' => 'emails',
    'action' => 'mailbox_folder_create',
    'entity_type' => 'mailbox_folder',
    'entity_label' => $name,
    'result' => $ok ? 'success' : 'failed',
    'severity' => $ok ? 'info' : 'warning',
    'details' => [
        'error_message' => $error,
        'mail_user' => $username,
    ],
]);

echo json_encode(['ok' => (bool)$ok, 'error' => $error]);




