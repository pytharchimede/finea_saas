<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../controllers/EmailController.php';
\AccessManager::requirePageAccess();

$emailController = new \EmailController();

require_once __DIR__ . '/../decaissement/config/mail_config.php';
require_once __DIR__ . '/../decaissement/model/EmailFetcher.php';
require_once __DIR__ . '/../model/AuditTrail.php';

$pageTitle = 'Emails - Boîte de réception';
$activeMenu = 'Portail';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$username = trim((string)($_SESSION['mail_pro'] ?? ''));
$userId = (int)($_SESSION['user_id'] ?? 0);

$folder = trim((string)($_GET['folder'] ?? 'INBOX'));
if ($folder === '') {
    $folder = 'INBOX';
}
if (strpos($folder, "\0") !== false || strpos($folder, '{') !== false || strpos($folder, '}') !== false) {
    $folder = 'INBOX';
}

// Tente de recharger le mot de passe mail depuis la BDD (stockage chiffré) si la session est vide.
$emailController->hydrateMailboxPasswordFromStorage($userId);

$mailConnectionSuccess = null;
$mailConnectionError = null;
$mailConnectionWarning = null;

// Mot de passe IMAP mémorisé sur le compte après une connexion IMAP valide.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['personnel_email_password'] ?? '');
    if (trim($pw) !== '') {
        $_SESSION['personnel_email_password'] = $pw;
        $emailController->markMailboxPasswordAsManual();

        if ($username === '') {
            unset($_SESSION['personnel_email_password'], $_SESSION['mail_password_source'], $_SESSION['mail_password_persisted']);
            $mailConnectionError = 'Adresse email professionnelle introuvable pour ouvrir la messagerie.';
        } else {
            $fetcher = new \EmailFetcher(IMAP_HOSTNAME, $username, $pw);
            if (!$fetcher->isConnected()) {
                $mailConnectionError = $fetcher->getLastError() ?: 'Connexion IMAP impossible.';
                unset($_SESSION['personnel_email_password'], $_SESSION['mail_password_source'], $_SESSION['mail_password_persisted']);
            } else {
                $persisted = $userId > 0 ? $emailController->saveMailboxPasswordForUser($userId, $pw) : false;
                if ($persisted) {
                    $_SESSION['mail_password_persisted'] = true;
                    $_SESSION['mail_password_source'] = 'db';
                    $mailConnectionSuccess = 'Connexion mémorisée sur votre compte. Vous resterez connecté tant que vous ne cliquez pas sur Déconnecter le mail.';
                } else {
                    $mailConnectionWarning = $emailController->isMailboxPasswordStorageEnabled()
                        ? 'Connexion IMAP active, mais la mémorisation permanente a échoué. La boîte reste utilisable maintenant, mais la reconnexion automatique n’est pas garantie.'
                        : 'Connexion IMAP active, mais la mémorisation permanente n’est pas disponible tant que la clé sécurisée MAIL_PASSWORD_KEY n’est pas exploitable sur le serveur.';
                }

                \AuditTrail::log('mailbox_password_registered', [
                    'module' => 'emails',
                    'action' => 'mailbox_password_submit',
                    'entity_type' => 'mailbox_session',
                    'entity_label' => $username !== '' ? $username : 'mailbox',
                    'details' => [
                        'folder' => $folder,
                        'persisted' => $persisted,
                    ],
                ], null);
            }
            $fetcher->closeConnection();
        }
    }
}

$password = (string)($_SESSION['personnel_email_password'] ?? '');

$tab = (string)($_GET['tab'] ?? 'inbox'); // inbox|unread
$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) {
    $limit = 50;
}
if ($limit > 200) {
    $limit = 200;
}

$needPassword = ($username === '' || trim($password) === '');

$emails = [];
$unreadCount = 0;
$error = null;

\AuditTrail::logPageConsultation([
    'module' => 'emails',
    'action' => 'mailbox_emails_view',
    'entity_type' => 'mailbox',
    'entity_label' => $folder,
    'details' => [
        'folder' => $folder,
        'tab' => $tab,
        'limit' => $limit,
        'need_password' => $needPassword,
    ],
], null);

$tabUrl = static function (string $name) use ($limit, $folder): string {
    return 'mailbox_emails.php?folder=' . urlencode($folder) . '&tab=' . urlencode($name) . '&limit=' . urlencode((string)$limit);
};

?>
<style>
    .mailbox-page {
        background:
            radial-gradient(circle at top right, rgba(59, 130, 246, 0.16), transparent 30%),
            linear-gradient(180deg, #eef3f8 0%, #dfe7ef 100%);
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 28px;
        box-shadow: 0 28px 70px -40px rgba(15, 23, 42, 0.55);
        padding: 20px;
    }

    .mailbox-header {
        background: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(248, 250, 252, 0.96));
        border: 1px solid rgba(203, 213, 225, 0.9);
        border-radius: 24px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
        padding: 18px 20px;
    }

    .mailbox-window {
        background: #e8edf3;
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 26px;
        overflow: hidden;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .mailbox-sidebar {
        background: linear-gradient(180deg, #f5f7fa 0%, #edf2f7 100%);
        border-right: 1px solid rgba(148, 163, 184, 0.28);
        min-height: 72vh;
    }

    .mailbox-main {
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
        min-height: 72vh;
    }

    .mailbox-toolbar {
        background: linear-gradient(180deg, #f8fafc 0%, #eef2f7 100%);
        border-bottom: 1px solid rgba(203, 213, 225, 0.85);
    }

    .mailbox-toolbar-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.9);
        border: 1px solid rgba(203, 213, 225, 0.9);
        color: #475569;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .mailbox-message-list .mailbox-message-row {
        border-bottom: 1px solid #e5e7eb;
        background: rgba(255, 255, 255, 0.92);
    }

    .mailbox-message-link {
        display: block;
        padding: 16px 20px;
        border-left: 4px solid transparent;
        transition: background-color .18s ease, border-color .18s ease;
    }

    .mailbox-message-row:hover .mailbox-message-link {
        background: #f8fbff;
        border-left-color: #4f6fad;
    }

    .mailbox-message-from {
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .mailbox-message-subject {
        margin-top: 4px;
        color: #0f172a;
        font-size: 14px;
    }

    .mailbox-message-preview {
        margin-top: 6px;
        color: #64748b;
        font-size: 12px;
    }

    .mailbox-message-date {
        color: #64748b;
        font-size: 12px;
        font-weight: 600;
    }

    .mailbox-empty-state {
        padding: 46px 20px;
        text-align: center;
        color: #64748b;
        background: linear-gradient(180deg, rgba(248, 250, 252, 0.9), rgba(255, 255, 255, 0.98));
    }

    .mailbox-folder-label {
        font-size: 11px;
        font-weight: 800;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: #64748b;
    }

    @media (max-width: 1024px) {

        .mailbox-sidebar,
        .mailbox-main {
            min-height: auto;
        }
    }
</style>
<link rel="stylesheet" href="mail-theme.css?v=<?= filemtime(__DIR__.'/mail-theme.css') ?>">
<?php $_SESSION['finea_mail_csrf'] ??= bin2hex(random_bytes(32)); ?>
<div class="finea-mail mailbox-page container mx-auto px-4 pb-10">

    <div class="mailbox-header flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
        <div>
            <div class="mailbox-toolbar-chip w-fit">
                <i class="fas fa-envelope-open-text"></i>
                FINEA Mail
            </div>
            <h1 class="mt-3 text-3xl md:text-4xl font-bold text-slate-800">Messagerie professionnelle</h1>
            <p class="mt-2 text-slate-600">Compte : <span class="font-semibold text-slate-800"><?= htmlspecialchars($username !== '' ? $username : '—') ?></span></p>
            <p class="mt-1 text-xs text-slate-500">Dossier actif : <span class="font-mono"><?= htmlspecialchars($folder) ?></span></p>
        </div>

        <div class="flex flex-wrap gap-3">
            <button id="mailThemeToggle" type="button" class="mail-theme px-4 py-2 rounded-xl">Mode sombre</button>
            <a href="../selection_portail.php" class="inline-flex items-center gap-2 px-4 py-2 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                <i class="fas fa-arrow-left"></i>
                Portail
            </a>

            <?php if (!empty($_SESSION['personnel_email_password'] ?? '')): ?>
                <a href="logout_mailbox.php" class="mail-danger inline-flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-xl shadow hover:bg-red-700 transition">
                    <i class="fas fa-lock"></i>
                    Déconnecter le mail
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($username === ''): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700">
            L'email professionnel est introuvable en session. Vérifiez que la colonne <span class="font-mono">user_devis_banamur.mail_pro</span> est bien renseignée.
        </div>
    <?php endif; ?>

    <?php if (!empty($mailConnectionError)): ?>
        <div class="mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700">
            <div class="font-semibold">Connexion mail refusée</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars((string) $mailConnectionError) ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($mailConnectionSuccess)): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800">
            <div class="font-semibold">Connexion mail permanente</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars((string) $mailConnectionSuccess) ?></div>
        </div>
    <?php elseif (!empty($mailConnectionWarning)): ?>
        <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 text-amber-900">
            <div class="font-semibold">Connexion active mais non mémorisée</div>
            <div class="mt-1 text-sm"><?= htmlspecialchars((string) $mailConnectionWarning) ?></div>
        </div>
    <?php elseif (!$needPassword && !empty($_SESSION['mail_password_persisted'])): ?>
        <div class="mb-6 p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800">
            <div class="font-semibold">Connexion mail restaurée automatiquement</div>
            <div class="mt-1 text-sm">Le mot de passe IMAP chiffré a été rechargé depuis la base. La boîte reste connectée tant que vous ne la déconnectez pas manuellement.</div>
        </div>
    <?php endif; ?>

    <?php if ($needPassword): ?>
        <div class="max-w-xl bg-white/95 border border-slate-200 rounded-[28px] shadow-xl p-6">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-700">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <h2 class="text-lg font-semibold text-slate-800">Connexion à la messagerie</h2>
                    <p class="text-sm text-slate-600 mt-1">Saisissez une fois le mot de passe de votre email pro. Après validation IMAP, il sera mémorisé de façon chiffrée pour rester connecté durablement.</p>
                </div>
            </div>

            <form method="post" class="mt-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Mot de passe email</label>
                    <input name="personnel_email_password" type="password" required class="mt-1 block w-full rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" autocomplete="current-password" />
                </div>
                <div class="flex items-center justify-between">
                    <div class="text-xs text-gray-500">Serveur: <span class="font-mono"><?= htmlspecialchars(IMAP_HOSTNAME) ?></span></div>
                    <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-slate-800 text-white rounded-xl shadow hover:bg-slate-900 transition">
                        <i class="fas fa-plug"></i>
                        Se connecter durablement
                    </button>
                </div>
            </form>
        </div>

    <?php else: ?>

        <div id="mailboxError" class="hidden mb-6 p-4 rounded-xl bg-red-50 border border-red-200 text-red-700">
            <div class="font-semibold">Erreur de connexion</div>
            <div id="mailboxErrorText" class="mt-1 text-sm"></div>
            <div class="mt-3">
                <form method="post" class="flex flex-col md:flex-row gap-3">
                    <input name="personnel_email_password" type="password" placeholder="Ressaisir le mot de passe" class="flex-1 rounded-xl border-gray-200 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" />
                    <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition">Réessayer</button>
                </form>
            </div>
        </div>

        <div class="mail-ribbon" role="toolbar" aria-label="Actions de messagerie">
<a class="mail-compose" href="compose_email.php?folder=<?= urlencode($folder) ?>"><i class="fas fa-pen"></i> Nouveau message</a>
<button type="button" data-mail-action="read"><i class="fas fa-envelope-open"></i> Lu</button>
<button type="button" data-mail-action="unread"><i class="fas fa-envelope"></i> Non lu</button>
<button type="button" data-mail-action="flag"><i class="fas fa-flag"></i> Important</button>
<button type="button" data-mail-action="unflag">Retirer le drapeau</button>
<select id="mailMoveTarget" aria-label="Dossier de destination"><option value="">Déplacer vers…</option></select>
<button type="button" data-mail-action="move">Déplacer</button>
<button type="button" data-mail-action="archive"><i class="fas fa-archive"></i> Archiver</button>
<button type="button" data-mail-action="trash"><i class="fas fa-trash"></i> Corbeille</button>
<button type="button" data-mail-action="create"><i class="fas fa-folder-plus"></i> Dossier</button>
<label><input type="checkbox" id="mailSelectAll"> Tout sélectionner</label>
<span id="mailActionStatus" role="status"></span>
</div>
<div class="mailbox-window grid grid-cols-1 lg:grid-cols-12 gap-0">

            <div class="mailbox-sidebar lg:col-span-3 p-4 lg:p-5">
                <div class="rounded-[22px] border border-slate-200 bg-white/80 p-4 shadow-sm">
                    <div class="mailbox-folder-label mb-3">Filtres</div>
                    <div class="space-y-1">
                        <a href="<?= htmlspecialchars($tabUrl('inbox')) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl <?= $tab === 'inbox' ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-gray-50 text-gray-700' ?>">
                            <span class="flex items-center gap-2"><i class="fas fa-inbox"></i> Tous</span>
                            <span id="mailboxCountAll" class="text-xs font-semibold text-gray-500">—</span>
                        </a>
                        <a href="<?= htmlspecialchars($tabUrl('unread')) ?>" class="flex items-center justify-between px-3 py-2 rounded-xl <?= $tab === 'unread' ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-gray-50 text-gray-700' ?>">
                            <span class="flex items-center gap-2"><i class="fas fa-circle"></i> Non lus</span>
                            <span id="mailboxCountUnread" class="text-xs font-semibold text-gray-500">—</span>
                        </a>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-200">
                        <div class="mailbox-folder-label mb-2">Boîtes favorites</div>
                        <div id="smartFolders" class="space-y-1">
                            <div class="text-xs text-gray-500">Chargement des dossiers…</div>
                        </div>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-200">
                        <div class="mailbox-folder-label mb-2">Tous les dossiers</div>
                        <div id="allFolders" class="space-y-1 max-h-64 overflow-auto"></div>
                        <div id="allFoldersError" class="hidden mt-2 text-xs text-red-700"></div>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-200">
                        <div class="mailbox-folder-label mb-2">Affichage</div>
                        <div class="text-xs text-gray-500">Comme sur un webmail classique, limitez l'affichage pour garder un chargement très fluide.</div>
                        <form method="get" class="mt-3 flex items-center gap-2">
                            <input type="hidden" name="folder" value="<?= htmlspecialchars($folder) ?>" />
                            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>" />
                            <select name="limit" class="flex-1 rounded-xl border-gray-200 text-sm">
                                <?php foreach ([25, 50, 100, 200] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $limit === $opt ? 'selected' : '' ?>><?= $opt ?> emails</option>
                                <?php endforeach; ?>
                            </select>
                            <button class="px-4 py-2 bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-sm">OK</button>
                        </form>
                    </div>

                    <div class="mt-5 pt-4 border-t border-slate-200">
                        <div class="mailbox-folder-label mb-2">Cache local</div>
                        <div class="text-xs text-gray-500">Politique active: mails lus 24 h, non lus récemment synchronisés 5 min, dossiers IMAP 15 min.</div>
                        <div class="mt-3 grid gap-2 text-xs text-slate-600">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="font-semibold text-slate-700">Mails lus</div>
                                <div id="mailboxReadCacheMeta" class="mt-1">Aucune donnée de cache.</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="font-semibold text-slate-700">Mails non lus</div>
                                <div id="mailboxUnreadCacheMeta" class="mt-1">Aucune donnée de cache.</div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="font-semibold text-slate-700">Dossiers IMAP</div>
                                <div id="mailboxFolderCacheMeta" class="mt-1">Aucune donnée de cache.</div>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button id="mailboxResyncCache" type="button" class="inline-flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800 transition hover:bg-emerald-100">
                                <i class="fas fa-rotate-right"></i>
                                Resynchroniser le cache
                            </button>
                            <button id="mailboxClearCache" type="button" class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 transition hover:bg-amber-100">
                                <i class="fas fa-broom"></i>
                                Vider le cache
                            </button>
                        </div>
                        <div id="mailboxCacheFeedback" class="hidden mt-3 rounded-xl border px-3 py-2 text-xs"></div>
                    </div>
                </div>
            </div>

            <div class="mailbox-main lg:col-span-9">
                <div class="overflow-hidden">
                    <div class="mailbox-toolbar px-5 py-4 flex flex-col gap-3 border-b md:flex-row md:items-center md:justify-between">
                        <div>
                            <div class="mailbox-folder-label">Vue courante</div>
                            <div class="mt-1 text-sm font-semibold text-slate-800">
                                <?= $tab === 'unread' ? 'Messages non lus' : 'Tous les messages' ?>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="mailbox-toolbar-chip">
                                <i class="fas fa-shield-halved"></i>
                                <?= !empty($_SESSION['mail_password_persisted']) ? 'Connecté en permanence' : 'Connexion active' ?>
                            </span>
                            <a href="compose_email.php?folder=<?= urlencode($folder) ?>" class="mail-compose inline-flex items-center gap-2 px-4 py-2 bg-white border border-slate-300 rounded-xl hover:bg-slate-50 transition text-sm text-slate-700">
                                <i class="fas fa-pen"></i>
                                Nouveau
                            </a>
                            <button id="mailboxRefresh" type="button" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-800 text-white rounded-xl hover:bg-slate-900 transition text-sm">
                                <i class="fas fa-rotate"></i>
                                Actualiser
                            </button>
                        </div>
                    </div>

                    <div class="p-3"><input id="mailSearch" class="mail-search" type="search" placeholder="Rechercher dans les messages chargés" aria-label="Rechercher dans les messages chargés"></div>
                    <div id="mailboxLoading" class="mailbox-empty-state">
                        <div class="text-4xl mb-2">📨</div>
                        Chargement des emails…
                    </div>

                    <ul id="mailboxList" class="mailbox-message-list hidden"></ul>

                    <div id="mailboxLoadingMore" class="hidden px-5 py-4 text-center text-xs text-gray-500 border-t border-slate-200 bg-slate-50/70">
                        Chargement en arrière-plan…
                    </div>

                </div>
            </div>

            <section class="mail-reading" aria-label="Volet de lecture">
                <div id="mailReadingEmpty" class="mail-reading-empty"><i class="fas fa-envelope-open fa-3x"></i><p>Sélectionnez un message pour le lire.</p></div>
                <iframe id="mailReadingFrame" title="Lecture du message" hidden></iframe>
            </section>
        </div>

    <?php endif; ?>

</div>
<script>
(function(){const root=document.documentElement,b=document.getElementById('mailThemeToggle');function sync(){const dark=root.classList.contains('finea-dark-mode');document.body.classList.toggle('finea-dark-mode',dark);b.textContent=dark?'☀ Mode clair':'☾ Mode sombre';b.setAttribute('aria-pressed',String(dark));const frame=document.getElementById('mailReadingFrame');try{frame?.contentDocument?.documentElement.classList.toggle('finea-dark-mode',dark)}catch(e){}}sync();new MutationObserver(sync).observe(root,{attributes:true,attributeFilter:['class']});b.addEventListener('click',()=>{const dark=!root.classList.contains('finea-dark-mode');root.classList.toggle('finea-dark-mode',dark);try{['finea.app.theme','finea.portal.theme'].forEach(k=>localStorage.setItem(k,dark?'dark':'light'))}catch(e){}sync()});})();
</script>

<?php if (!$needPassword): ?>
    <script>
        (function() {
            const tab = <?= json_encode($tab) ?>;
            const maxToLoad = <?= (int)$limit ?>;
            const folder = <?= json_encode($folder) ?>;
            const batchSize = Math.min(25, maxToLoad);

            const elErr = document.getElementById('mailboxError');
            const elErrText = document.getElementById('mailboxErrorText');
            const elCountAll = document.getElementById('mailboxCountAll');
            const elCountUnread = document.getElementById('mailboxCountUnread');
            const elLoading = document.getElementById('mailboxLoading');
            const elList = document.getElementById('mailboxList');
            const elLoadingMore = document.getElementById('mailboxLoadingMore');
            const btnRefresh = document.getElementById('mailboxRefresh');
            const btnResyncCache = document.getElementById('mailboxResyncCache');
            const btnClearCache = document.getElementById('mailboxClearCache');
            const elSmartFolders = document.getElementById('smartFolders');
            const elAllFolders = document.getElementById('allFolders');
            const elAllFoldersError = document.getElementById('allFoldersError');
            const elCacheFeedback = document.getElementById('mailboxCacheFeedback');
            const elReadCacheMeta = document.getElementById('mailboxReadCacheMeta');
            const elUnreadCacheMeta = document.getElementById('mailboxUnreadCacheMeta');
            const elFolderCacheMeta = document.getElementById('mailboxFolderCacheMeta');

            const fetchOptions = {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            };

            async function fetchJsonSafe(url) {
                const res = await fetch(url, fetchOptions);
                const status = res.status;
                const contentType = res.headers.get('content-type') || '';
                const text = await res.text();
                const cleaned = text.replace(/^\uFEFF/, '');
                try {
                    const data = JSON.parse(cleaned);
                    return {
                        ok: true,
                        status,
                        contentType,
                        data,
                        text
                    };
                } catch (e) {
                    return {
                        ok: false,
                        status,
                        contentType,
                        data: null,
                        text
                    };
                }
            }

            let loaded = [];
            let isLoading = false;
            let lastTopUid = null;
            let pollTimer = null;

            function showCacheFeedback(message, tone) {
                if (!elCacheFeedback) return;
                const css = tone === 'success' ?
                    'border-emerald-200 bg-emerald-50 text-emerald-800' :
                    (tone === 'error' ? 'border-red-200 bg-red-50 text-red-700' : 'border-slate-200 bg-slate-50 text-slate-700');
                elCacheFeedback.className = 'mt-3 rounded-xl border px-3 py-2 text-xs ' + css;
                elCacheFeedback.textContent = message;
                elCacheFeedback.classList.remove('hidden');
            }

            function showError(msg) {
                if (!elErr || !elErrText) return;
                elErr.classList.remove('hidden');
                elErrText.textContent = msg || 'Erreur inconnue.';
            }

            function hideError() {
                if (!elErr) return;
                elErr.classList.add('hidden');
            }

            function formatDate(raw) {
                if (!raw) return '';
                const d = new Date(raw);
                if (isNaN(d.getTime())) return raw;
                const pad = (n) => String(n).padStart(2, '0');
                return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
            }

            function formatRemaining(seconds) {
                const value = Number(seconds);
                if (!Number.isFinite(value)) return 'expiration inconnue';
                if (value <= 0) return 'expiré';
                if (value < 60) return `expire dans ${Math.round(value)} s`;
                if (value < 3600) return `expire dans ${Math.ceil(value / 60)} min`;
                if (value < 86400) return `expire dans ${Math.ceil(value / 3600)} h`;
                return `expire dans ${Math.ceil(value / 86400)} j`;
            }

            function updateMessageCacheMeta(cacheStatus) {
                const read = cacheStatus && cacheStatus.read ? cacheStatus.read : null;
                const unread = cacheStatus && cacheStatus.unread ? cacheStatus.unread : null;

                if (elReadCacheMeta) {
                    if (read && read.last_synced_at) {
                        elReadCacheMeta.textContent = `${read.count || 0} mail(s) lus en cache. Dernière synchro ${formatDate(read.last_synced_at)}. ${formatRemaining(read.expires_in_seconds)}.`;
                    } else {
                        elReadCacheMeta.textContent = 'Aucun mail lu en cache pour ce dossier.';
                    }
                }

                if (elUnreadCacheMeta) {
                    if (unread && unread.last_synced_at) {
                        elUnreadCacheMeta.textContent = `${unread.count || 0} mail(s) non lus récemment synchronisés. Dernière synchro ${formatDate(unread.last_synced_at)}. ${formatRemaining(unread.expires_in_seconds)}.`;
                    } else {
                        elUnreadCacheMeta.textContent = 'Aucun cache non lu exploitable actuellement.';
                    }
                }
            }

            function updateFolderCacheMeta(cacheStatus) {
                if (!elFolderCacheMeta) return;
                if (cacheStatus && cacheStatus.last_synced_at) {
                    elFolderCacheMeta.textContent = `${cacheStatus.count || 0} dossier(s) mémorisé(s). Dernière synchro ${formatDate(cacheStatus.last_synced_at)}. ${formatRemaining(cacheStatus.expires_in_seconds)}.`;
                    return;
                }
                elFolderCacheMeta.textContent = 'La liste des dossiers sera mémorisée après le prochain chargement réussi.';
            }

            let selectedUid = null;
            const selected = new Set();
            let knownFolders = [];
            const status = document.getElementById('mailActionStatus');
            const selectAll = document.getElementById('mailSelectAll');
            selectAll.addEventListener('change',()=>{document.querySelectorAll('.mail-check').forEach(c=>{c.checked=selectAll.checked;if(c.checked)selected.add(Number(c.value));else selected.delete(Number(c.value));});});
            document.querySelectorAll('[data-mail-action]').forEach(b=>b.addEventListener('click',async()=>{
                const action=b.dataset.mailAction;
                const uids=selected.size?[...selected]:(selectedUid?[selectedUid]:[]);
                let target=document.getElementById('mailMoveTarget').value;
                if(action==='create'){target=prompt('Nom du dossier');if(!target)return;}
                if(action==='archive'||action==='trash'){
                    const pattern=action==='archive'?/(^|[./])(archive|archives)$/i:/(^|[./])(trash|deleted items|corbeille)$/i;
                    target=knownFolders.find(f=>pattern.test(f.name))?.name||'';
                }
                if(action!=='create'&&!uids.length){status.textContent='Sélectionnez un message.';return;}
                if(['move','archive','trash'].includes(action)&&!target){status.textContent='Dossier de destination introuvable.';return;}
                if(action==='trash'&&!confirm('Déplacer les messages vers la corbeille ?'))return;
                b.disabled=true;
                try{
                    const res=await fetch('api_mail_actions.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({action,folder,target,uids,csrf:<?= json_encode($_SESSION['finea_mail_csrf']) ?>})});
                    const data=await res.json();status.textContent=data.message||'Erreur.';
                    selected.clear();selectAll.checked=false;selectedUid=null;
                    document.getElementById('mailReadingFrame').hidden=true;document.getElementById('mailReadingEmpty').hidden=false;
                    await loadFolders(true);await loadAll(true);
                }catch(e){status.textContent='Erreur réseau.';}finally{b.disabled=false;}
            }));
            const search = document.getElementById('mailSearch');
            search.addEventListener('input',()=>render(loaded));
            function render(list) {
                const query=search.value.trim().toLocaleLowerCase();
                if(query)list=list.filter(m=>[m.subject,m.from,m.teaser].some(v=>String(v||'').toLocaleLowerCase().includes(query)));
                if (!elList) return;
                elList.innerHTML = '';
                const frag = document.createDocumentFragment();

                if (!Array.isArray(list) || list.length === 0) {
                    const li = document.createElement('li');
                    li.className = 'mailbox-empty-state text-sm';
                    li.textContent = 'Aucun email dans ce dossier pour ce filtre.';
                    frag.appendChild(li);
                    elList.appendChild(frag);
                    return;
                }

                list.forEach((mail) => {
                    const uid = Number(mail.uid || 0);
                    const isRead = !!mail.is_read;
                    const subject = (mail.subject || '').toString();
                    const from = (mail.from || '').toString();
                    const date = (mail.date || '').toString();
                    const teaser = (mail.teaser || '').toString();

                    const li = document.createElement('li');
                    li.className = 'mailbox-message-row' + (isRead?'':' is-unread');

                    const a = document.createElement('a');
                    a.className = 'mailbox-message-link';
                    a.href = 'view_email.php?uid=' + encodeURIComponent(String(uid)) + '&folder=' + encodeURIComponent(String(folder));

                    if(String(selectedUid)===String(uid))a.setAttribute('aria-current','true');
                    a.addEventListener('click',e=>{
                        if(e.ctrlKey||e.metaKey||e.shiftKey||e.altKey)return;
                        e.preventDefault();selectedUid=uid;
                        document.querySelectorAll('.mailbox-message-link[aria-current]').forEach(x=>x.removeAttribute('aria-current'));
                        a.setAttribute('aria-current','true');
                        const frame=document.getElementById('mailReadingFrame');
                        document.getElementById('mailReadingEmpty').hidden=true;
                        frame.hidden=false;frame.src=a.href+'&pane=1';
                    });
                    const row = document.createElement('div');
                    row.className = 'flex items-start justify-between gap-4';

                    const left = document.createElement('div');
                    left.className = 'min-w-0';

                    const fromRow = document.createElement('div');
                    fromRow.className = 'flex items-center gap-2';

                    const dot = document.createElement('span');
                    dot.className = 'inline-flex w-2.5 h-2.5 rounded-full ' + (isRead ? 'bg-gray-300' : 'bg-indigo-600');

                    const fromDiv = document.createElement('div');
                    fromDiv.className = 'mailbox-message-from ' + (isRead ? 'text-slate-600' : 'text-slate-900') + ' truncate';
                    fromDiv.textContent = from !== '' ? from : 'Expéditeur inconnu';

                    fromRow.appendChild(dot);
                    fromRow.appendChild(fromDiv);

                    if (!isRead) {
                        const unreadTag = document.createElement('span');
                        unreadTag.className = 'inline-flex items-center rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.18em] text-sky-700';
                        unreadTag.textContent = 'Nouveau';
                        fromRow.appendChild(unreadTag);
                    }

                    const subjDiv = document.createElement('div');
                    subjDiv.className = 'mailbox-message-subject truncate';
                    subjDiv.textContent = subject !== '' ? subject : '(Aucun objet)';

                    left.appendChild(fromRow);
                    left.appendChild(subjDiv);

                    if (teaser) {
                        const t = document.createElement('div');
                        t.className = 'mailbox-message-preview truncate';
                        t.textContent = teaser;
                        left.appendChild(t);
                    }

                    const right = document.createElement('div');
                    right.className = 'mailbox-message-date whitespace-nowrap';
                    right.textContent = formatDate(date);

                    row.appendChild(left);
                    row.appendChild(right);

                    a.appendChild(row);
                    const check=document.createElement('input');check.type='checkbox';check.className='mail-check';check.value=String(uid);check.checked=selected.has(uid);check.setAttribute('aria-label','Sélectionner '+subject);check.addEventListener('change',()=>{if(check.checked)selected.add(uid);else selected.delete(uid);});
                    li.appendChild(check);
                    if(mail.flagged){const flag=document.createElement('span');flag.textContent='⚑';flag.title='Important';li.appendChild(flag);}
                    li.appendChild(a);
                    frag.appendChild(li);
                });

                elList.appendChild(frag);
            }

            async function fetchBatch(offset, limit, forceRefresh = false) {
                const url = `api_mailbox_emails.php?folder=${encodeURIComponent(String(folder))}&tab=${encodeURIComponent(tab)}&limit=${encodeURIComponent(String(limit))}&offset=${encodeURIComponent(String(offset))}&force_refresh=${forceRefresh ? '1' : '0'}&_=${Date.now()}`;
                // Ici on garde res.json() car l’API emails fonctionne déjà; en cas d’erreur, l’UI affiche le message.
                const res = await fetch(url, fetchOptions);
                return await res.json();
            }

            function buildFolderLink(targetFolder, label, iconClass, isActive) {
                const a = document.createElement('a');
                const active = !!isActive;
                a.className = 'flex items-center justify-between px-3 py-2 rounded-xl ' + (active ? 'bg-indigo-50 text-indigo-700' : 'hover:bg-gray-50 text-gray-700');
                a.href = 'mailbox_emails.php?folder=' + encodeURIComponent(String(targetFolder)) + '&tab=' + encodeURIComponent(String(tab)) + '&limit=' + encodeURIComponent(String(maxToLoad));

                const left = document.createElement('span');
                left.className = 'flex items-center gap-2 min-w-0';
                const i = document.createElement('i');
                i.className = iconClass;
                const t = document.createElement('span');
                t.className = 'truncate';
                t.textContent = label;
                left.appendChild(i);
                left.appendChild(t);
                a.appendChild(left);

                return a;
            }

            function normalizeFolderItem(f) {
                if (!f) return null;
                if (typeof f === 'string') {
                    const name = f.trim();
                    if (!name) return null;
                    return {
                        name,
                        label: name,
                        role: (name.toUpperCase() === 'INBOX') ? 'inbox' : 'custom'
                    };
                }
                if (typeof f === 'object') {
                    const name = String(f.name || '').trim();
                    if (!name) return null;
                    const label = String(f.label || name).trim();
                    const role = String(f.role || 'custom').trim();
                    return {
                        name,
                        label,
                        role
                    };
                }
                return null;
            }

            async function loadFolders(forceRefresh = false) {
                if (!elSmartFolders || !elAllFolders) return;
                try {
                    const result = await fetchJsonSafe('api_mailbox_folders.php?force_refresh=' + (forceRefresh ? '1' : '0') + '&_=' + Date.now());
                    if (!result.ok || !result.data) {
                        const snippet = (result.text || '').toString().trim().slice(0, 240);
                        const diag = `HTTP ${result.status} (${result.contentType || 'content-type inconnu'})` + (snippet ? ` — ${snippet}` : '');
                        if (elAllFoldersError) {
                            elAllFoldersError.classList.remove('hidden');
                            elAllFoldersError.textContent = "Impossible de charger les dossiers. " + diag;
                        }
                        elSmartFolders.innerHTML = '';
                        elSmartFolders.appendChild(buildFolderLink('INBOX', 'Boîte de réception', 'fas fa-inbox', String(folder).toUpperCase() === 'INBOX'));
                        return;
                    }

                    const data = result.data;
                    if (!data || !data.ok) {
                        if (elAllFoldersError) {
                            elAllFoldersError.classList.remove('hidden');
                            elAllFoldersError.textContent = (data && data.error) ? data.error : 'Impossible de charger les dossiers.';
                        }
                        elSmartFolders.innerHTML = '';
                        elSmartFolders.appendChild(buildFolderLink('INBOX', 'Boîte de réception', 'fas fa-inbox', String(folder).toUpperCase() === 'INBOX'));
                        return;
                    }

                    updateFolderCacheMeta(data.cacheStatus || null);

                    const rawFolders = Array.isArray(data.folders) ? data.folders : [];
                    const folders = rawFolders.map(normalizeFolderItem).filter(Boolean);

                    knownFolders=folders;
                    const targetSelect=document.getElementById('mailMoveTarget');targetSelect.replaceChildren(new Option('Déplacer vers…',''));folders.filter(f=>f.name!==folder).forEach(f=>targetSelect.add(new Option(f.label,f.name)));
                    const byRole = (role) => folders.find(x => String(x.role || '') === role) || null;
                    const inboxFolder = (byRole('inbox')?.name) || 'INBOX';
                    const sentFolder = (byRole('sent')?.name) || null;
                    const draftsFolder = (byRole('drafts')?.name) || null;

                    elSmartFolders.innerHTML = '';
                    elSmartFolders.appendChild(buildFolderLink(inboxFolder, 'Boîte de réception', 'fas fa-inbox', String(folder) === String(inboxFolder)));
                    if (sentFolder) {
                        elSmartFolders.appendChild(buildFolderLink(sentFolder, 'Envoyés', 'fas fa-paper-plane', String(folder) === String(sentFolder)));
                    } else {
                        const d = document.createElement('div');
                        d.className = 'px-3 py-2 text-xs text-gray-500';
                        d.textContent = "Dossier 'Envoyés' introuvable (utilisez la liste ci-dessous).";
                        elSmartFolders.appendChild(d);
                    }
                    if (draftsFolder) {
                        elSmartFolders.appendChild(buildFolderLink(draftsFolder, 'Brouillons', 'fas fa-file-lines', String(folder) === String(draftsFolder)));
                    } else {
                        const d = document.createElement('div');
                        d.className = 'px-3 py-2 text-xs text-gray-500';
                        d.textContent = "Dossier 'Brouillons' introuvable (utilisez la liste ci-dessous).";
                        elSmartFolders.appendChild(d);
                    }

                    elAllFolders.innerHTML = '';
                    const frag = document.createDocumentFragment();
                    folders.forEach((f) => {
                        // Les boîtes standard sont déjà dans «Boîtes»; on évite les doublons.
                        if (f.role === 'inbox' || f.role === 'sent' || f.role === 'drafts') {
                            return;
                        }
                        const raw = String(f.name || '').trim();
                        if (!raw) return;
                        const label = String(f.label || raw);
                        const icon = (f.role === 'inbox') ? 'fas fa-inbox' : (f.role === 'sent' ? 'fas fa-paper-plane' : (f.role === 'drafts' ? 'fas fa-file-lines' : 'fas fa-folder'));
                        const a = buildFolderLink(raw, label, icon, String(folder) === raw);
                        frag.appendChild(a);
                    });
                    elAllFolders.appendChild(frag);
                } catch (e) {
                    if (elAllFoldersError) {
                        elAllFoldersError.classList.remove('hidden');
                        elAllFoldersError.textContent = 'Impossible de charger les dossiers (erreur réseau).';
                    }
                }
            }

            async function loadAll(forceRefresh = false) {
                if (isLoading) return;
                isLoading = true;

                hideError();
                elLoading?.classList.remove('hidden');
                elList?.classList.add('hidden');
                elLoadingMore?.classList.add('hidden');

                loaded = [];
                lastTopUid = null;

                try {
                    let offset = 0;
                    while (offset < maxToLoad) {
                        if (offset > 0) {
                            elLoadingMore?.classList.remove('hidden');
                        }

                        const data = await fetchBatch(offset, Math.min(batchSize, maxToLoad - offset), forceRefresh && offset === 0);
                        if (!data || !data.ok) {
                            showError((data && data.error) ? data.error : 'Erreur de chargement.');
                            break;
                        }

                        if (offset === 0) {
                            updateMessageCacheMeta(data.cacheStatus || null);
                        }

                        const emails = Array.isArray(data.emails) ? data.emails : [];
                        if (offset === 0) {
                            const uc = Number(data.unreadCount ?? 0);
                            elCountUnread.textContent = isNaN(uc) ? '—' : String(uc);
                            elCountUnread.classList.remove('text-gray-500', 'text-indigo-700');
                            elCountUnread.classList.add((!isNaN(uc) && uc > 0) ? 'text-indigo-700' : 'text-gray-500');
                        }

                        loaded = loaded.concat(emails);
                        render(loaded);
                        elCountAll.textContent = String(loaded.length);

                        if (offset === 0 && loaded.length > 0) {
                            lastTopUid = loaded[0].uid ?? null;
                        }

                        if (emails.length < Math.min(batchSize, maxToLoad - offset)) {
                            break;
                        }

                        offset += emails.length;
                        // Laisser respirer le thread UI entre les lots
                        await new Promise(r => setTimeout(r, 150));
                    }
                } catch (e) {
                    showError('Erreur réseau lors du chargement des emails.');
                } finally {
                    elLoading?.classList.add('hidden');
                    elList?.classList.remove('hidden');
                    elLoadingMore?.classList.add('hidden');
                    isLoading = false;
                }
            }

            async function poll() {
                // Poll léger: juste le 1er lot + compteur non lus
                if (isLoading) return;
                try {
                    const data = await fetchBatch(0, batchSize);
                    if (!data || !data.ok) {
                        return;
                    }
                    const uc = Number(data.unreadCount ?? 0);
                    elCountUnread.textContent = isNaN(uc) ? '—' : String(uc);
                    elCountUnread.classList.remove('text-gray-500', 'text-indigo-700');
                    elCountUnread.classList.add((!isNaN(uc) && uc > 0) ? 'text-indigo-700' : 'text-gray-500');

                    const emails = Array.isArray(data.emails) ? data.emails : [];
                    const topUid = emails.length > 0 ? (emails[0].uid ?? null) : null;
                    if (topUid && lastTopUid && String(topUid) !== String(lastTopUid)) {
                        // Nouveau mail arrivé (ou tri changé): recharger
                        await loadAll();
                    }
                } catch (e) {
                    // silencieux
                }
            }

            btnRefresh?.addEventListener('click', function() {
                loadAll(true);
                loadFolders(true);
            });

            async function manageCache(action) {
                try {
                    const response = await fetch('api_mailbox_cache_manage.php', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action,
                            folder
                        })
                    });
                    const data = await response.json();
                    if (!data || !data.ok) {
                        showCacheFeedback((data && data.error) ? data.error : 'Impossible de gérer le cache mail.', 'error');
                        return;
                    }

                    showCacheFeedback(data.message || 'Cache mail mis à jour.', 'success');
                    updateMessageCacheMeta(data.messageCacheStatus || null);
                    updateFolderCacheMeta(data.folderCacheStatus || null);
                    await loadFolders(true);
                    await loadAll(true);
                } catch (e) {
                    showCacheFeedback('Erreur réseau lors de la gestion du cache mail.', 'error');
                }
            }

            btnResyncCache?.addEventListener('click', function() {
                manageCache('resync');
            });

            btnClearCache?.addEventListener('click', function() {
                manageCache('clear');
            });

            // initial
            loadFolders();
            loadAll().then(() => {
                pollTimer = setInterval(poll, 15000);
            });
        })();
    </script>
<?php endif; ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>



