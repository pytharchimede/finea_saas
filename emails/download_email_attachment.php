<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';

$username = trim((string)($_SESSION['mail_pro'] ?? ''));

$emailController->hydrateMailboxPasswordFromStorage((int)($_SESSION['user_id'] ?? 0));
$password = (string)($_SESSION['personnel_email_password'] ?? '');

$folder = trim((string)($_GET['folder'] ?? 'INBOX'));
if ($folder === '') {
    $folder = 'INBOX';
}
if (strpos($folder, "\0") !== false || strpos($folder, '{') !== false || strpos($folder, '}') !== false) {
    $folder = 'INBOX';
}

$uid = (int)($_GET['uid'] ?? 0);
$attachmentIndex = (int)($_GET['i'] ?? -1);

if ($username === '' || trim($password) === '' || $uid <= 0 || $attachmentIndex < 0) {
    header('Location: mailbox_emails.php?folder=' . urlencode($folder));
    exit;
}

$fetcher = new EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    header('Location: mailbox_emails.php?folder=' . urlencode($folder));
    exit;
}

$emailController->persistMailboxPasswordIfNeeded((int)($_SESSION['user_id'] ?? 0));

if ($folder !== '') {
    if (!$fetcher->reopenMailbox($folder)) {
        $fetcher->closeConnection();
        header('Location: mailbox_emails.php?folder=' . urlencode($folder));
        exit;
    }
}

$payload = $fetcher->getAttachmentContentByUid($uid, $attachmentIndex);
$fetcher->closeConnection();

if (!$payload || empty($payload['filename'])) {
    header('Location: view_email.php?uid=' . urlencode((string)$uid) . '&folder=' . urlencode($folder));
    exit;
}

$filename = (string)$payload['filename'];
$content = (string)($payload['content'] ?? '');

// Sécuriser le nom de fichier
$filename = preg_replace('/[^a-zA-Z0-9._\-\s]/', '_', $filename);
$filename = trim($filename);
if ($filename === '') {
    $filename = 'piece_jointe';
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));

echo $content;
exit;




