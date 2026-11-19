<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/config/smtp_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';
require_once __DIR__ . '/../model/AuditTrail.php';

require_once __DIR__ . '/../decaissement/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../decaissement/phpmailer/src/Exception.php';
require_once __DIR__ . '/../decaissement/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$pageTitle = 'Emails - Composer';
$activeMenu = 'Portail';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$username = trim((string)($_SESSION['mail_pro'] ?? ''));

$emailController->hydrateMailboxPasswordFromStorage((int)($_SESSION['user_id'] ?? 0));
$password = (string)($_SESSION['personnel_email_password'] ?? '');
$fromName = trim((string)($_SESSION['user_name'] ?? ($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
$smtpAuthUser = defined('SMTP_USER') ? trim((string) SMTP_USER) : '';
$smtpAuthPass = defined('SMTP_PASS') ? (string) SMTP_PASS : '';
$smtpFromAddress = defined('SMTP_FROM') ? trim((string) SMTP_FROM) : '';
$smtpFromName = defined('SMTP_DEFAULT_FROM_NAME') ? trim((string) SMTP_DEFAULT_FROM_NAME) : '';

$folder = trim((string)($_GET['folder'] ?? ($_POST['folder'] ?? 'INBOX')));
if ($folder === '') {
    $folder = 'INBOX';
}
if (strpos($folder, "\0") !== false || strpos($folder, '{') !== false || strpos($folder, '}') !== false) {
    $folder = 'INBOX';
}

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'new')); // new|reply|replyall|forward
$uid = (int)($_GET['uid'] ?? ($_POST['uid'] ?? 0));

if ($username === '' || trim($password) === '') {
    header('Location: mailbox_emails.php?folder=' . urlencode($folder));
    exit;
}

$normalizeEmail = static function (string $email): string {
    return strtolower(trim($email));
};

$uniqueEmails = static function (array $items) use ($normalizeEmail): array {
    $seen = [];
    $out = [];
    foreach ($items as $it) {
        $email = $normalizeEmail((string)($it['email'] ?? ''));
        if ($email === '' || isset($seen[$email])) {
            continue;
        }
        $seen[$email] = true;
        $out[] = ['email' => $email, 'name' => (string)($it['name'] ?? '')];
    }
    return $out;
};

$parseAddressString = static function (string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }

    if (function_exists('imap_rfc822_parse_adrlist')) {
        $parsed = @imap_rfc822_parse_adrlist($raw, '');
        if (is_array($parsed)) {
            $out = [];
            foreach ($parsed as $p) {
                if (!is_object($p)) {
                    continue;
                }
                $mailbox = isset($p->mailbox) ? (string)$p->mailbox : '';
                $host = isset($p->host) ? (string)$p->host : '';
                if ($mailbox === '' || $host === '') {
                    continue;
                }
                $email = $mailbox . '@' . $host;
                $name = isset($p->personal) ? (string)$p->personal : '';
                $out[] = ['email' => $email, 'name' => $name];
            }
            return $out;
        }
    }

    // Fallback simple: extraire toutes les adresses email
    preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m);
    $out = [];
    foreach (($m[0] ?? []) as $email) {
        $out[] = ['email' => $email, 'name' => ''];
    }
    return $out;
};

$parseRecipientsInput = static function (string $input) use ($parseAddressString): array {
    $input = trim($input);
    if ($input === '') {
        return [];
    }

    // split par virgule/point-virgule, en gardant les formes "Nom <mail>"
    $chunks = preg_split('/\s*[;,]\s*/', $input);
    $out = [];
    foreach (($chunks ?: []) as $chunk) {
        $chunk = trim((string)$chunk);
        if ($chunk === '') {
            continue;
        }
        $out = array_merge($out, $parseAddressString($chunk));
    }
    return $out;
};

$joinRecipientsForInput = static function (array $items): string {
    $out = [];
    foreach ($items as $it) {
        $email = trim((string)($it['email'] ?? ''));
        $name = trim((string)($it['name'] ?? ''));
        if ($email === '') {
            continue;
        }
        $out[] = $name !== '' ? ($name . ' <' . $email . '>') : $email;
    }
    return implode(', ', $out);
};

$prefill = [
    'to' => '',
    'cc' => '',
    'bcc' => '',
    'subject' => '',
    'body' => '',
    'action' => $action,
    'uid' => $uid,
];

$original = null;

if (in_array($action, ['reply', 'replyall', 'forward'], true) && $uid > 0) {
    $fetcher = new \EmailFetcher(IMAP_HOSTNAME, $username, $password);
    if ($fetcher->isConnected()) {
        if ($folder !== '') {
            $fetcher->reopenMailbox($folder);
        }
        $original = $fetcher->getEmailByUid($uid);
    }
    $fetcher->closeConnection();
}

$makeQuotedBlock = static function (?array $email): string {
    if (!$email) {
        return '';
    }

    $from = trim((string)($email['from'] ?? ''));
    $date = trim((string)($email['date'] ?? ''));
    $subject = trim((string)($email['subject'] ?? ''));
    $to = trim((string)($email['to'] ?? ''));
    $cc = trim((string)($email['cc'] ?? ''));

    $text = trim((string)($email['message'] ?? ''));

    $headerLines = [];
    if ($from !== '') $headerLines[] = '<div><strong>De :</strong> ' . htmlspecialchars($from, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    if ($date !== '') $headerLines[] = '<div><strong>Date :</strong> ' . htmlspecialchars($date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    if ($subject !== '') $headerLines[] = '<div><strong>Objet :</strong> ' . htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    if ($to !== '') $headerLines[] = '<div><strong>À :</strong> ' . htmlspecialchars($to, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';
    if ($cc !== '') $headerLines[] = '<div><strong>Cc :</strong> ' . htmlspecialchars($cc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>';

    $quotedText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));

    return '<div style="margin-top:16px">'
        . '<div style="font-size:13px;color:#374151">' . implode('', $headerLines) . '</div>'
        . '<div style="margin-top:10px;border-left:4px solid #e5e7eb;padding-left:12px;color:#111827">' . $quotedText . '</div>'
        . '</div>';
};

$startsWith = static function (string $haystack, string $needle): bool {
    return strncasecmp($haystack, $needle, strlen($needle)) === 0;
};

if ($original) {
    $origSubject = trim((string)($original['subject'] ?? ''));
    if ($action === 'reply' || $action === 'replyall') {
        $prefill['subject'] = $startsWith($origSubject, 'Re:') ? $origSubject : ('Re: ' . $origSubject);
    } elseif ($action === 'forward') {
        $prefill['subject'] = $startsWith($origSubject, 'Fwd:') ? $origSubject : ('Fwd: ' . $origSubject);
    }

    $myEmail = $normalizeEmail($username);

    $replyToCandidates = (array)($original['reply_to_addresses'] ?? []);
    if (empty($replyToCandidates)) {
        $replyToCandidates = $parseAddressString((string)($original['from'] ?? ''));
    }

    $fromRecipients = $uniqueEmails($replyToCandidates);

    $origTo = $uniqueEmails((array)($original['to_addresses'] ?? []));
    $origCc = $uniqueEmails((array)($original['cc_addresses'] ?? []));

    // Exclure soi-même
    $filterOutSelf = static function (array $items) use ($myEmail, $normalizeEmail): array {
        $out = [];
        foreach ($items as $it) {
            $email = $normalizeEmail((string)($it['email'] ?? ''));
            if ($email === '' || $email === $myEmail) {
                continue;
            }
            $out[] = $it;
        }
        return $out;
    };

    $origToNoSelf = $filterOutSelf($origTo);
    $origCcNoSelf = $filterOutSelf($origCc);

    if ($action === 'reply') {
        $prefill['to'] = $joinRecipientsForInput($fromRecipients);
        $prefill['cc'] = '';
        $prefill['body'] = $makeQuotedBlock($original);
    } elseif ($action === 'replyall') {
        $toCombined = $uniqueEmails(array_merge($fromRecipients, $origToNoSelf));

        // Retirer tout ce qui est déjà en To du CC
        $toEmails = [];
        foreach ($toCombined as $it) {
            $toEmails[$normalizeEmail((string)($it['email'] ?? ''))] = true;
        }

        $ccFiltered = [];
        foreach ($origCcNoSelf as $it) {
            $e = $normalizeEmail((string)($it['email'] ?? ''));
            if ($e === '' || isset($toEmails[$e])) {
                continue;
            }
            $ccFiltered[] = $it;
        }

        $prefill['to'] = $joinRecipientsForInput($toCombined);
        $prefill['cc'] = $joinRecipientsForInput($ccFiltered);
        $prefill['body'] = $makeQuotedBlock($original);
    } elseif ($action === 'forward') {
        $prefill['to'] = '';
        $prefill['cc'] = '';
        $prefill['body'] = $makeQuotedBlock($original);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    foreach (['to', 'cc', 'bcc', 'subject', 'body'] as $prefillField) {
        if (!array_key_exists($prefillField, $_GET)) {
            continue;
        }
        $prefill[$prefillField] = $prefillField === 'body'
            ? (string) ($_GET[$prefillField] ?? '')
            : trim((string) ($_GET[$prefillField] ?? ''));
    }
}

\AuditTrail::logPageConsultation([
    'module' => 'emails',
    'action' => 'compose_email_view',
    'entity_type' => 'email_compose',
    'entity_label' => $action,
    'details' => [
        'folder' => $folder,
        'compose_action' => $action,
        'original_uid' => $uid > 0 ? $uid : null,
        'has_original' => $original !== null,
    ],
], null);

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prefill['to'] = trim((string)($_POST['to'] ?? ''));
    $prefill['cc'] = trim((string)($_POST['cc'] ?? ''));
    $prefill['bcc'] = trim((string)($_POST['bcc'] ?? ''));
    $prefill['subject'] = trim((string)($_POST['subject'] ?? ''));
    $prefill['body'] = (string)($_POST['body'] ?? '');

    $toParsed = $uniqueEmails($parseRecipientsInput($prefill['to']));
    $ccParsed = $uniqueEmails($parseRecipientsInput($prefill['cc']));
    $bccParsed = $uniqueEmails($parseRecipientsInput($prefill['bcc']));
    $attachmentCount = 0;
    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        foreach ((array) $_FILES['attachments']['name'] as $attachmentName) {
            if (trim((string) $attachmentName) !== '') {
                $attachmentCount++;
            }
        }
    }

    if (empty($toParsed)) {
        $error = "Veuillez renseigner au moins un destinataire (À).";
        \AuditTrail::log('email_send_failed', [
            'module' => 'emails',
            'action' => 'compose_email_send',
            'entity_type' => 'email_message',
            'entity_label' => $prefill['subject'] !== '' ? $prefill['subject'] : '(Sans objet)',
            'result' => 'failure',
            'severity' => 'warning',
            'details' => [
                'reason' => 'missing_to_recipient',
                'folder' => $folder,
                'compose_action' => $action,
                'cc_count' => count($ccParsed),
                'bcc_count' => count($bccParsed),
                'attachment_count' => $attachmentCount,
            ],
        ], null);
    } else {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = PHPMailer::ENCODING_BASE64;
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = SMTP_AUTH;

            if ((bool) SMTP_AUTH && ($smtpAuthUser === '' || $smtpAuthPass === '')) {
                throw new Exception('Configuration SMTP incomplète: SMTP_USER ou SMTP_PASS manquant.');
            }

            if ((bool) SMTP_AUTH) {
                $mail->Username = $smtpAuthUser;
                $mail->Password = $smtpAuthPass;
            }

            $mail->SMTPSecure = SMTP_SECURE !== '' ? SMTP_SECURE : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = SMTP_PORT;

            $fromAddressFinal = $smtpFromAddress !== '' ? $smtpFromAddress : ($smtpAuthUser !== '' ? $smtpAuthUser : $username);
            $fromNameFinal = $fromName !== '' ? $fromName : ($smtpFromName !== '' ? $smtpFromName : SMTP_DEFAULT_FROM_NAME);
            if ($fromNameFinal !== '') {
                $mail->setFrom($fromAddressFinal, $fromNameFinal);
            } else {
                $mail->setFrom($fromAddressFinal);
            }

            if ($username !== '' && strcasecmp($username, $fromAddressFinal) !== 0) {
                $mail->addReplyTo($username, $fromName !== '' ? $fromName : $fromAddressFinal);
            }

            foreach ($toParsed as $it) {
                $mail->addAddress((string)$it['email'], (string)($it['name'] ?? ''));
            }
            foreach ($ccParsed as $it) {
                $mail->addCC((string)$it['email'], (string)($it['name'] ?? ''));
            }
            foreach ($bccParsed as $it) {
                $mail->addBCC((string)$it['email'], (string)($it['name'] ?? ''));
            }

            $mail->Subject = $prefill['subject'] !== '' ? $prefill['subject'] : '(Sans objet)';

            $bodyInput = trim($prefill['body']);
            $isLikelyHtml = $bodyInput !== strip_tags($bodyInput);
            $bodyHtml = $isLikelyHtml ? $bodyInput : nl2br(htmlspecialchars($bodyInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $mail->isHTML(true);
            $mail->Body = $bodyHtml;
            $mail->AltBody = trim(strip_tags($bodyInput));

            // Threading si réponse
            $origMessageId = trim((string)($_POST['orig_message_id'] ?? ''));
            $origReferences = trim((string)($_POST['orig_references'] ?? ''));
            if (in_array($action, ['reply', 'replyall'], true) && $origMessageId !== '') {
                $mail->addCustomHeader('In-Reply-To', $origMessageId);
                $refs = trim($origReferences . ' ' . $origMessageId);
                $mail->addCustomHeader('References', $refs);
            }

            // Pièces jointes (optionnel)
            if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
                $names = (array)$_FILES['attachments']['name'];
                $tmps = (array)$_FILES['attachments']['tmp_name'];
                $errs = (array)$_FILES['attachments']['error'];

                for ($i = 0; $i < count($names); $i++) {
                    if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                        continue;
                    }
                    $tmp = (string)($tmps[$i] ?? '');
                    $name = (string)($names[$i] ?? '');
                    if ($tmp === '' || !is_file($tmp)) {
                        continue;
                    }
                    $safeName = preg_replace('/[^a-zA-Z0-9._\-\s]/', '_', $name);
                    $mail->addAttachment($tmp, $safeName);
                }
            }

            $mail->send();
            $success = 'Email envoyé avec succès.';
            \AuditTrail::log('email_sent', [
                'module' => 'emails',
                'action' => 'compose_email_send',
                'entity_type' => 'email_message',
                'entity_label' => $prefill['subject'] !== '' ? $prefill['subject'] : '(Sans objet)',
                'details' => [
                    'folder' => $folder,
                    'compose_action' => $action,
                    'to_count' => count($toParsed),
                    'cc_count' => count($ccParsed),
                    'bcc_count' => count($bccParsed),
                    'attachment_count' => $attachmentCount,
                    'original_uid' => $uid > 0 ? $uid : null,
                ],
            ], null);

            // Reset si nouveau message
            if ($action === 'new') {
                $prefill['to'] = '';
                $prefill['cc'] = '';
                $prefill['bcc'] = '';
                $prefill['subject'] = '';
                $prefill['body'] = '';
            }
        } catch (Exception $e) {
            $error = 'Échec de l\'envoi. ' . $e->getMessage();
            \AuditTrail::log('email_send_failed', [
                'module' => 'emails',
                'action' => 'compose_email_send',
                'entity_type' => 'email_message',
                'entity_label' => $prefill['subject'] !== '' ? $prefill['subject'] : '(Sans objet)',
                'result' => 'failure',
                'severity' => 'warning',
                'details' => [
                    'reason' => 'mailer_exception',
                    'error' => $e->getMessage(),
                    'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : null,
                    'smtp_port' => defined('SMTP_PORT') ? SMTP_PORT : null,
                    'smtp_secure' => defined('SMTP_SECURE') ? SMTP_SECURE : null,
                    'smtp_auth_enabled' => defined('SMTP_AUTH') ? (bool) SMTP_AUTH : null,
                    'smtp_auth_user' => $smtpAuthUser !== '' ? $smtpAuthUser : null,
                    'folder' => $folder,
                    'compose_action' => $action,
                    'to_count' => count($toParsed),
                    'cc_count' => count($ccParsed),
                    'bcc_count' => count($bccParsed),
                    'attachment_count' => $attachmentCount,
                ],
            ], null);
        }
    }
}

$origMessageIdHidden = $original ? trim((string)($original['message_id'] ?? '')) : '';
$origReferencesHidden = $original ? trim((string)($original['references'] ?? '')) : '';

?>
<div class="container mx-auto px-4 pb-10">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-800">Composer</h1>
            <p class="mt-2 text-gray-600"><a href="mailbox_emails.php?folder=<?= urlencode($folder) ?>" class="text-indigo-700 hover:underline">← Retour à la boîte</a></p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="../selection_portail.php" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                <i class="fas fa-grid-2"></i>
                Portail
            </a>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="mb-6 p-4 rounded-xl bg-green-50 border border-green-200 text-green-800">
            <div class="font-semibold">Succès</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars($success) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700">
            <div class="font-semibold">Erreur</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
        <div class="px-6 py-5 border-b">
            <div class="text-sm text-gray-600">
                Expéditeur : <span class="font-semibold text-gray-800"><?= htmlspecialchars($username) ?></span>
            </div>
            <div class="mt-2 text-xs text-gray-500">
                SMTP : <span class="font-mono"><?= htmlspecialchars(SMTP_HOST . ':' . SMTP_PORT) ?></span>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="px-6 py-6 space-y-4">
            <input type="hidden" name="action" value="<?= htmlspecialchars($action) ?>" />
            <input type="hidden" name="uid" value="<?= htmlspecialchars((string)$uid) ?>" />
            <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>" />
            <input type="hidden" name="orig_message_id" value="<?= htmlspecialchars($origMessageIdHidden) ?>" />
            <input type="hidden" name="orig_references" value="<?= htmlspecialchars($origReferencesHidden) ?>" />

            <div class="grid grid-cols-1 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">À</label>
                    <input name="to" value="<?= htmlspecialchars($prefill['to']) ?>" placeholder="destinataire@domaine.com" class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cc</label>
                        <input name="cc" value="<?= htmlspecialchars($prefill['cc']) ?>" placeholder="(optionnel)" class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Cci</label>
                        <input name="bcc" value="<?= htmlspecialchars($prefill['bcc']) ?>" placeholder="(optionnel)" class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Objet</label>
                    <input name="subject" value="<?= htmlspecialchars($prefill['subject']) ?>" placeholder="Objet" class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Message</label>
                    <textarea name="body" rows="10" class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Votre message... (HTML possible)"><?= htmlspecialchars($prefill['body']) ?></textarea>
                    <div class="mt-2 text-xs text-gray-500">Astuce: vous pouvez coller du HTML (emails marketing) ou écrire du texte simple.</div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Pièces jointes</label>
                    <input type="file" name="attachments[]" multiple class="mt-1 block w-full text-sm text-gray-600" />
                </div>
            </div>

            <div class="pt-2 flex flex-wrap gap-3">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">
                    <i class="fas fa-paper-plane"></i>
                    Envoyer
                </button>
                <a href="mailbox_emails.php?folder=<?= urlencode($folder) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-gray-700 transition">
                    <i class="fas fa-xmark"></i>
                    Annuler
                </a>
            </div>
        </form>
    </div>

</div>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>



