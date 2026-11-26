<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';

$pageTitle = 'Emails - Lecture';
$activeMenu = 'Portail';
$embedded = ($_GET['pane'] ?? '') === '1';

$username = trim((string)($_SESSION['mail_pro'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

$emailController->hydrateMailboxPasswordFromStorage($userId);
$password = (string)($_SESSION['personnel_email_password'] ?? '');

$folder = trim((string)($_GET['folder'] ?? 'INBOX'));
if ($folder === '') {
    $folder = 'INBOX';
}
if (strpos($folder, "\0") !== false || strpos($folder, '{') !== false || strpos($folder, '}') !== false) {
    $folder = 'INBOX';
}

$uid = (int)($_GET['uid'] ?? 0);

if ($username === '' || trim($password) === '') {
    header('Location: mailbox_emails.php?folder=' . urlencode($folder));
    exit;
}

$error = null;
$email = null;
$cacheManager = $emailController->cache();

$fetcher = new \EmailFetcher(IMAP_HOSTNAME, $username, $password);
if (!$fetcher->isConnected()) {
    $error = $fetcher->getLastError() ?: 'Connexion IMAP impossible.';
} else {
    $emailController->persistMailboxPasswordIfNeeded($userId);
    if ($folder !== '') {
        if (!$fetcher->reopenMailbox($folder)) {
            $error = $fetcher->getLastError() ?: 'Impossible d\'ouvrir le dossier.';
        }
    }
    if ($uid > 0) {
        // Marquer comme lu quand on ouvre
        if (empty($error)) {
            $fetcher->markAsReadByUid($uid);
            $cacheManager->markAsRead($userId, $folder, $uid);
            $email = $fetcher->getEmailByUid($uid, $cacheManager, $userId, $folder);
        }
    }
}
$fetcher->closeConnection();

?>
<?php if (!$embedded) {
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
} else { ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__.'/../model/CompanyBranding.php'; $mailPalette=CompanyBranding::palette(); ?>
<style>:root{--brand-primary:<?= htmlspecialchars($mailPalette['primary']) ?>;--brand-on-primary:<?= CompanyBranding::contrastColor($mailPalette['primary']) ?>}body{margin:0;font:14px system-ui}a{color:inherit} .finea-mail a{display:inline-block;margin:4px;padding:5px} .finea-mail>div{margin-bottom:12px} .finea-mail .border-b{border-bottom:1px solid var(--mail-border);padding:12px}</style>
<script>try{document.documentElement.classList.toggle('finea-dark-mode',(localStorage.getItem('finea.app.theme')||localStorage.getItem('finea.portal.theme'))==='dark')}catch(e){}</script></head><body>
<?php } ?>
<link rel="stylesheet" href="mail-theme.css?v=<?= filemtime(__DIR__.'/mail-theme.css') ?>">
<style>
    /* Rendu email: éviter débordements sur contenus HTML */
    .email-body img {
        max-width: 100%;
        height: auto;
    }

    .email-body table {
        max-width: 100%;
    }

    .email-body a {
        color: #4f46e5;
        text-decoration: underline;
    }
</style>
<div class="finea-mail container mx-auto px-4 pb-10">

    <?php if (!$embedded): ?><div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <h1 class="text-3xl md:text-4xl font-bold text-gray-800">Lecture email</h1>
            <p class="mt-2 text-gray-600"><a href="mailbox_emails.php?folder=<?= urlencode($folder) ?>" class="text-indigo-700 hover:underline">← Retour à la boîte</a></p>
        </div>

        <div class="flex flex-wrap gap-3">
            <a href="../selection_portail.php" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                <i class="fas fa-grid-2"></i>
                Portail
            </a>
        </div>
    </div>

    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700">
            <div class="font-semibold">Erreur</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($uid <= 0): ?>
        <div class="p-6 bg-white border border-gray-200 rounded-2xl shadow-sm text-gray-600">
            Email introuvable.
        </div>
    <?php elseif (!$email): ?>
        <div class="p-6 bg-white border border-gray-200 rounded-2xl shadow-sm text-gray-600">
            Impossible de charger cet email.
        </div>
    <?php else: ?>

        <div class="bg-white border border-gray-200 rounded-2xl shadow-sm overflow-hidden">
            <div class="px-6 py-5 border-b">
                <div class="text-xl font-bold text-gray-900">
                    <?= htmlspecialchars((string)($email['subject'] ?? '(Aucun objet)')) ?>
                </div>
                <div class="mt-2 flex flex-col md:flex-row md:items-center md:justify-between gap-2 text-sm text-gray-600">
                    <div>
                        <span class="font-semibold text-gray-800">De:</span>
                        <?= htmlspecialchars((string)($email['from'] ?? '')) ?>
                    </div>
                    <div>
                        <?= htmlspecialchars((string)($email['date'] ?? '')) ?>
                    </div>
                </div>

                <?php
                $toLine = trim((string)($email['to'] ?? ''));
                $ccLine = trim((string)($email['cc'] ?? ''));
                $bccLine = trim((string)($email['bcc'] ?? ''));
                $replyToLine = trim((string)($email['reply_to'] ?? ''));
                ?>

                <?php if ($toLine !== '' || $ccLine !== '' || $bccLine !== '' || $replyToLine !== ''): ?>
                    <div class="mt-3 grid grid-cols-1 gap-1 text-sm text-gray-700">
                        <?php if ($toLine !== ''): ?>
                            <div>
                                <span class="font-semibold text-gray-800">À:</span>
                                <?= htmlspecialchars($toLine) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($ccLine !== ''): ?>
                            <div>
                                <span class="font-semibold text-gray-800">Cc:</span>
                                <?= htmlspecialchars($ccLine) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($bccLine !== ''): ?>
                            <div>
                                <span class="font-semibold text-gray-800">Cci:</span>
                                <?= htmlspecialchars($bccLine) ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($replyToLine !== ''): ?>
                            <div>
                                <span class="font-semibold text-gray-800">Répondre à:</span>
                                <?= htmlspecialchars($replyToLine) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-4 flex flex-wrap gap-2">
                    <a target="_top" href="compose_email.php?folder=<?= urlencode($folder) ?>" class="inline-flex items-center gap-2 px-3 py-2 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition text-sm">
                        <i class="fas fa-pen"></i>
                        Nouveau
                    </a>
                    <a target="_top" href="compose_email.php?action=reply&uid=<?= urlencode((string)$uid) ?>&folder=<?= urlencode($folder) ?>" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-sm text-gray-700">
                        <i class="fas fa-reply"></i>
                        Répondre
                    </a>
                    <a target="_top" href="compose_email.php?action=replyall&uid=<?= urlencode((string)$uid) ?>&folder=<?= urlencode($folder) ?>" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-sm text-gray-700">
                        <i class="fas fa-reply-all"></i>
                        Répondre à tous
                    </a>
                    <a target="_top" href="compose_email.php?action=forward&uid=<?= urlencode((string)$uid) ?>&folder=<?= urlencode($folder) ?>" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-sm text-gray-700">
                        <i class="fas fa-share"></i>
                        Transférer
                    </a>
                </div>
            </div>

            <?php $attachments = (array)($email['attachments'] ?? []); ?>
            <?php if (!empty($attachments)): ?>
                <div class="px-6 py-4 border-b bg-gray-50">
                    <div class="text-sm font-semibold text-gray-800 mb-2">Pièces jointes</div>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($attachments as $att): ?>
                            <?php
                            $idx = (int)($att['index'] ?? 0);
                            $fn = (string)($att['filename'] ?? ('piece_jointe_' . $idx));
                            $href = 'download_email_attachment.php?uid=' . urlencode((string)$uid) . '&i=' . urlencode((string)$idx) . '&folder=' . urlencode($folder);
                            ?>
                            <a href="<?= htmlspecialchars($href) ?>" class="inline-flex items-center gap-2 px-3 py-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-sm">
                                <i class="fas fa-paperclip text-gray-500"></i>
                                <span class="max-w-[240px] truncate"><?= htmlspecialchars($fn) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="px-6 py-6">
                <div class="prose max-w-none">
                    <div class="text-sm font-semibold text-gray-700 mb-2">Message</div>
                    <?php
                    $rawHtml = (string)($email['message_html'] ?? '');
                    $rawText = (string)($email['message'] ?? '');

                    $safeHtml = '';
                    if (trim($rawHtml) !== '') {
                        // Purifier le HTML pour un affichage sécurisé
                        $autoload = __DIR__ . '/../vendor/autoload.php';
                        if (is_file($autoload)) {
                            require_once $autoload;
                        }

                        if (class_exists('HTMLPurifier') && class_exists('HTMLPurifier_Config')) {
                            $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'htmlpurifier';
                            @mkdir($cacheDir, 0777, true);

                            $config = \HTMLPurifier_Config::createDefault();
                            $config->set('Cache.SerializerPath', $cacheDir);
                            $config->set('HTML.Nofollow', true);
                            $config->set('Attr.AllowedFrameTargets', ['_blank']);
                            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
                            $config->set('HTML.Allowed', implode(',', [
                                'a[href|title|target]',
                                'p',
                                'br',
                                'div[style]',
                                'span[style]',
                                'strong',
                                'b',
                                'em',
                                'i',
                                'u',
                                'ul',
                                'ol',
                                'li',
                                'table[style|border|cellpadding|cellspacing|width]',
                                'thead',
                                'tbody',
                                'tr',
                                'td[style|colspan|rowspan|align|valign|width]',
                                'th[style|colspan|rowspan|align|valign|width]',
                                'h1',
                                'h2',
                                'h3',
                                'h4',
                                'h5',
                                'h6',
                                'blockquote',
                                'hr',
                                'img[src|alt|title|width|height|style]',
                            ]));

                            $purifier = new \HTMLPurifier($config);
                            $safeHtml = (string)$purifier->purify($rawHtml);
                        } else {
                            // Fallback minimal: afficher en texte sans balises
                            $safeHtml = nl2br(htmlspecialchars(strip_tags($rawHtml), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                        }
                    }
                    ?>

                    <?php if (trim($safeHtml) !== '' && trim($rawHtml) !== ''): ?>
                        <iframe title="Contenu du message" class="mail-body-frame" sandbox="" referrerpolicy="no-referrer" srcdoc="<?= htmlspecialchars('<meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; img-src data:; style-src &apos;unsafe-inline&apos;"><style>body{font:14px system-ui;color:#172033;background:white;overflow-wrap:anywhere}img,table{max-width:100%}</style>'.$safeHtml, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></iframe>
                    <?php else: ?>
                        <div class="p-4 rounded-xl bg-gray-50 border border-gray-200 whitespace-pre-wrap text-gray-800">
                            <?= nl2br(htmlspecialchars($rawText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>
<?php if (!$embedded) require_once __DIR__ . '/../decaissement/includes/footer.php'; else echo '</body></html>'; ?>



