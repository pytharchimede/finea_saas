<?php

require_once __DIR__ . '/../../auth_check.php';
require_once __DIR__ . '/../../model/NotificationSoundSettingsManager.php';

if (!function_exists('resolveLayoutRelativeRoot')) {
    function resolveLayoutRelativeRoot(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;

        if (!$projectRoot || !$scriptFilename) {
            return $cached = '../';
        }

        $scriptDirectory = realpath(dirname($scriptFilename));
        if (!$scriptDirectory) {
            return $cached = '../';
        }

        $projectRoot = str_replace('\\', '/', $projectRoot);
        $scriptDirectory = str_replace('\\', '/', $scriptDirectory);

        if (strpos($scriptDirectory, $projectRoot) !== 0) {
            return $cached = '../';
        }

        $relativeDirectory = trim(substr($scriptDirectory, strlen($projectRoot)), '/');
        if ($relativeDirectory === '') {
            return $cached = '';
        }

        $depth = substr_count($relativeDirectory, '/') + 1;
        return $cached = str_repeat('../', $depth);
    }
}

$layoutRootBase = $layoutRootBase ?? resolveLayoutRelativeRoot();
$activeEmployeeMenu = $activeEmployeeMenu ?? '';

if (!function_exists('resolveUserPhotoUrl')) {
    function resolveUserPhotoUrl(string $raw, string $layoutRootBase): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            $mail = trim((string) ($_SESSION['mail_pro'] ?? ''));
            if ($mail !== '') {
                return 'https://www.gravatar.com/avatar/' . md5(strtolower($mail)) . '?d=mp&s=200';
            }
            return $layoutRootBase . 'photo/ma_photo.jpg';
        }

        if (preg_match('~^(https?:|data:)~i', $raw)) {
            return $raw;
        }

        if (strpos($raw, '/') === 0) {
            return $raw;
        }

        return $layoutRootBase . ltrim($raw, '/');
    }
}

$photoSrc = resolveUserPhotoUrl((string) ($_SESSION['photo'] ?? ''), (string) $layoutRootBase);
$fallbackName = trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')));
$displayName = (string) ($_SESSION['user_name'] ?? ($fallbackName !== '' ? $fallbackName : 'Utilisateur'));
$userRoleLabel = (string) ($_SESSION['role_raw'] ?? 'Employe');
$userMail = trim((string) ($_SESSION['mail_pro'] ?? ''));

$mailboxPageUrl = $layoutRootBase . 'emails/mailbox_emails.php';
$mailboxUnreadUrl = $layoutRootBase . 'emails/api_mailbox_unread_count.php';
$notifPollUrl = $layoutRootBase . 'api/notifications_poll.php';
$notifMarkReadUrl = $layoutRootBase . 'api/notifications_mark_read.php';
$notificationSoundUrl = '';
try {
    $notificationSoundUrl = (new \NotificationSoundSettingsManager(\Database::getConnection()))->getPublicUrl($layoutRootBase);
} catch (\Throwable $e) {
    $notificationSoundUrl = '';
}
$profileUrl = $layoutRootBase . 'decaissement/profil.php';
$employeeChallengePendingCount=0;
try{
    $employeeNavPdo=\Database::getConnection();
    $employeeNavStmt=$employeeNavPdo->prepare('SELECT COUNT(*) FROM feb_price_challenges c JOIN employee_legal_requests r ON r.id=c.feb_request_id WHERE c.challenger_user_id=:user AND c.archived_at IS NULL AND c.status="pending" AND r.status="submitted" AND c.challenger_user_id<>COALESCE(r.requester_user_id,0)');
    $employeeNavStmt->execute(['user'=>(int)($_SESSION['user_id']??0)]);$employeeChallengePendingCount=(int)$employeeNavStmt->fetchColumn();
}catch(\Throwable $e){$employeeChallengePendingCount=0;}

function employeeNavClass(bool $isActive): string
{
    return $isActive
        ? 'inline-flex items-center gap-2 rounded-2xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-800'
        : 'inline-flex items-center gap-2 rounded-2xl border border-transparent bg-white/0 px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-200 hover:bg-slate-50';
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>assets/css/finea-theme.css?v=20260827">
<script>
    (() => {
        document.body?.classList.add('employee-space');
        try {
            const theme=localStorage.getItem('finea.app.theme')||localStorage.getItem('finea.portal.theme')||'light';
            document.documentElement.classList.toggle('finea-dark-mode',theme==='dark');
            document.body?.classList.toggle('finea-dark-mode',theme==='dark');
        } catch (e) {}
    })();
</script>
<nav class="employee-navbar sticky top-0 z-40 border-b border-slate-200 bg-white/90 shadow-sm backdrop-blur-xl">
    <div class="mx-auto max-w-[1600px] px-4 py-3 2xl:px-8">
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 via-violet-600 to-emerald-500 text-white shadow-sm">
                    <i class="fas fa-user"></i>
                </span>
                <div class="leading-tight">
                    <div class="text-sm font-semibold text-slate-900">Espace Employ&eacute;</div>
                    <div class="text-xs text-slate-500">Self-service &amp; demandes administratives</div>
                </div>
            </div>

            <div class="relative ml-auto flex items-center gap-2">
            <a id="employeeMailBtn" href="<?= htmlspecialchars($mailboxPageUrl, ENT_QUOTES, 'UTF-8') ?>" class="employee-nav-action employee-nav-action--mail relative inline-flex h-10 w-10 items-center justify-center rounded-xl text-blue-700 transition" aria-label="Messagerie pro" title="Messagerie pro">
                <span class="relative inline-flex items-center justify-center">
                    <i id="employeeMailIcon" class="fas fa-envelope text-lg"></i>
                </span>
                <span id="employeeMailBadge" class="hidden absolute -right-1 -top-1 h-[18px] min-w-[18px] rounded-full bg-indigo-600 px-1 text-center text-[11px] leading-[18px] text-white">0</span>
            </a>

            <div class="relative">
                <button type="button" data-employee-notif-toggle class="employee-nav-action employee-nav-action--notification relative inline-flex h-10 w-10 items-center justify-center rounded-xl text-amber-700 transition" aria-label="Notifications" title="Notifications">
                    <i class="fas fa-bell text-lg"></i>
                    <span id="employeeNotifBadge" class="hidden absolute -right-1 -top-1 h-[18px] min-w-[18px] rounded-full bg-indigo-600 px-1 text-center text-[11px] leading-[18px] text-white">0</span>
                </button>

                <div id="employeeNotifMenu" class="hidden absolute right-0 top-12 z-[10000] w-80 overflow-hidden rounded-xl border border-slate-100 bg-white shadow-lg">
                    <div class="flex items-center justify-between bg-slate-50 px-4 py-3">
                        <div class="text-sm font-semibold text-slate-800">Notifications</div>
                        <button id="employeeNotifMarkAllBtn" type="button" class="text-xs font-semibold text-indigo-700 hover:underline">Tout marquer lu</button>
                    </div>
                    <div id="employeeNotifList" class="max-h-80 overflow-auto">
                        <div class="px-4 py-4 text-sm text-slate-500">Aucune notification.</div>
                    </div>
                    <a href="<?= htmlspecialchars($layoutRootBase . 'notifications.php', ENT_QUOTES, 'UTF-8') ?>" class="flex items-center justify-center border-t bg-white px-4 py-3 text-sm font-black text-indigo-700 hover:bg-indigo-50"><i class="fas fa-list-check mr-2"></i>Voir toutes les notifications</a>
                </div>
            </div>

            <button id="helpBtn" type="button" class="employee-nav-action employee-nav-action--help inline-flex h-10 w-10 items-center justify-center rounded-xl text-violet-700 transition" aria-label="Aide" title="Aide">
                <i class="fas fa-circle-question text-lg"></i>
            </button>

            <button id="employeeThemeToggle" type="button" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-sm font-black text-slate-700 shadow-sm transition hover:bg-slate-100" aria-label="Activer le mode sombre" aria-pressed="false">
                <i class="fas fa-sun" data-theme-icon></i><span data-theme-label>Mode clair</span>
            </button>

            <button id="employeeUserMenuBtn" type="button" class="employee-user-button flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-2 py-1.5 transition" aria-haspopup="true" aria-expanded="false">
                <div class="hidden text-right sm:block">
                    <div class="max-w-[180px] truncate text-sm font-semibold text-slate-900"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="max-w-[180px] truncate text-xs text-slate-500"><?= htmlspecialchars($userMail, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" class="h-10 w-10 rounded-full border border-slate-200 object-cover" alt="Utilisateur">
                <i class="fas fa-chevron-down text-xs text-slate-400"></i>
            </button>

            <div id="employeeUserMenu" class="hidden absolute right-0 top-12 z-[10000] w-64 overflow-hidden rounded-xl border border-slate-100 bg-white shadow-lg">
                <div class="bg-slate-50 px-4 py-4">
                    <div class="flex items-center gap-3">
                        <img src="<?= htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8') ?>" class="h-11 w-11 rounded-2xl border bg-white object-cover" alt="Photo de profil">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-slate-800"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="truncate text-xs text-slate-500"><?= htmlspecialchars($userMail, ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
                <a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fas fa-user"></i>
                    Mon profil
                </a>
                <a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>#securite" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fas fa-shield-halved"></i>
                    S&eacute;curit&eacute; du compte
                </a>
                <a href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>#parametres" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fas fa-gear"></i>
                    Param&egrave;tres
                </a>
                <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>selection_portail.php" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-700 hover:bg-slate-50">
                    <i class="fas fa-table-cells"></i>
                    Changer de portail
                </a>
                <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>deconnex.php" class="flex items-center gap-2 px-4 py-3 text-sm text-red-600 hover:bg-red-50">
                    <i class="fas fa-right-from-bracket"></i>
                    D&eacute;connexion
                </a>
            </div>
            </div>
        </div>

        <div class="mt-3 hidden flex-wrap items-center gap-2 lg:flex">
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'dashboard') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/dashboard_employe.php"><i class="fas fa-house text-slate-500"></i>Accueil</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'pointage') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/pointage_employe.php"><i class="fas fa-clock text-slate-500"></i>Pointage</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'actes') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/actes_legaux_employe.php"><i class="fas fa-file-signature text-slate-500"></i>Actes l&eacute;gaux</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'demandes') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_demandes_employe.php"><i class="fas fa-clipboard-list text-slate-500"></i>Mes demandes</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'contestations') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/contestations_prix_feb.php"><i class="fas fa-scale-balanced text-slate-500"></i>Contestations de prix<?php if($employeeChallengePendingCount>0):?><span class="rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white"><?=$employeeChallengePendingCount>99?'99+':$employeeChallengePendingCount?></span><?php endif;?></a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'explications') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_explications_employe.php"><i class="fas fa-comments text-slate-500"></i>Explications</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'decaissement') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_decaissements_employe.php"><i class="fas fa-money-check-dollar text-slate-500"></i>D&eacute;caissement</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'regularisation_decaissements') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>decaissement/regularisations_decaissements.php"><i class="fas fa-file-circle-check text-slate-500"></i>R&eacute;gularisation</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'fournisseurs') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_fournisseurs_employe.php"><i class="fas fa-industry text-slate-500"></i>Fournisseurs</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'procedures') ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>procedures/index.php"><i class="fas fa-diagram-project text-slate-500"></i>Procédures</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'mails') ?>" href="<?= htmlspecialchars($mailboxPageUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-envelope text-slate-500"></i>Mails</a>
            <button type="button" data-employee-notif-toggle class="<?= employeeNavClass($activeEmployeeMenu === 'notifications') ?>"><i class="fas fa-bell text-slate-500"></i>Notifications</button>
            <a class="<?= employeeNavClass(false) ?>" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>selection_portail.php"><i class="fas fa-table-cells text-slate-500"></i>Portail</a>
        </div>

        <div class="mt-3 flex gap-2 overflow-x-auto lg:hidden">
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'dashboard') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/dashboard_employe.php">Accueil</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'pointage') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/pointage_employe.php">Pointage</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'actes') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/actes_legaux_employe.php">Actes</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'demandes') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_demandes_employe.php">Demandes</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'contestations') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/contestations_prix_feb.php">Contestations de prix<?php if($employeeChallengePendingCount>0):?><span class="rounded-full bg-rose-600 px-2 py-0.5 text-[10px] font-black text-white"><?=$employeeChallengePendingCount>99?'99+':$employeeChallengePendingCount?></span><?php endif;?></a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'decaissement') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_decaissements_employe.php">Decaissement</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'regularisation_decaissements') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>decaissement/regularisations_decaissements.php">R&eacute;gularisation</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'fournisseurs') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>employe/mes_fournisseurs_employe.php">Fournisseurs</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'procedures') ?> shrink-0" href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>procedures/index.php">Procédures</a>
            <a class="<?= employeeNavClass($activeEmployeeMenu === 'mails') ?> shrink-0" href="<?= htmlspecialchars($mailboxPageUrl, ENT_QUOTES, 'UTF-8') ?>">Mails</a>
            <button type="button" data-employee-notif-toggle class="<?= employeeNavClass($activeEmployeeMenu === 'notifications') ?> shrink-0">Notifications</button>
        </div>
    </div>
</nav>
<script>
    (() => {
        const button=document.getElementById('employeeThemeToggle');
        if(!button)return;
        const keys=['finea.app.theme','finea.portal.theme'];
        const apply=theme=>{
            const dark=theme==='dark';
            document.documentElement.classList.toggle('finea-dark-mode',dark);
            document.body.classList.toggle('finea-dark-mode',dark);
            document.body.classList.toggle('portal-theme-dark',dark);
            button.setAttribute('aria-pressed',dark?'true':'false');
            button.setAttribute('aria-label',dark?'Activer le mode clair':'Activer le mode sombre');
            button.querySelector('[data-theme-label]').textContent=dark?'Mode sombre':'Mode clair';
            button.querySelector('[data-theme-icon]').className=dark?'fas fa-moon':'fas fa-sun';
        };
        let theme='light';
        try{theme=localStorage.getItem(keys[0])||localStorage.getItem(keys[1])||'light'}catch(e){}
        apply(theme);
        button.addEventListener('click',()=>{
            theme=document.body.classList.contains('finea-dark-mode')?'light':'dark';
            try{keys.forEach(key=>localStorage.setItem(key,theme))}catch(e){}
            apply(theme);
        });
        window.addEventListener('storage',event=>{if(keys.includes(event.key)&&['light','dark'].includes(event.newValue))apply(event.newValue)});
    })();
</script>

<div id="employeeNotifToastContainer" class="pointer-events-none fixed left-1/2 top-4 z-[2147483644] w-[95%] max-w-xl -translate-x-1/2"></div>

<?php
if (empty($GLOBALS['__finea_help_tour_assets_included'])):
    $GLOBALS['__finea_help_tour_assets_included'] = true;
?>
    <script defer src="<?= htmlspecialchars($layoutRootBase . 'js/help_tour.js', ENT_QUOTES, 'UTF-8') ?>"></script>
    <script defer src="<?= htmlspecialchars($layoutRootBase . 'js/help_tours_app.js', ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>

<script>
    (() => {
        const NOTIF_POLL_URL = <?= json_encode($notifPollUrl, JSON_UNESCAPED_SLASHES) ?>;
        const NOTIF_MARK_READ_URL = <?= json_encode($notifMarkReadUrl, JSON_UNESCAPED_SLASHES) ?>;
        const NOTIF_SOUND_URL = <?= json_encode($notificationSoundUrl, JSON_UNESCAPED_SLASHES) ?>;
        const MAILBOX_PAGE_URL = <?= json_encode($mailboxPageUrl, JSON_UNESCAPED_SLASHES) ?>;
        const MAILBOX_UNREAD_URL = <?= json_encode($mailboxUnreadUrl, JSON_UNESCAPED_SLASHES) ?>;
        const LAYOUT_ROOT_BASE = <?= json_encode($layoutRootBase, JSON_UNESCAPED_SLASHES) ?>;

        function resolveNotifHref(raw) {
            if (!raw) return '';
            if (/^(https?:|data:|#)/i.test(raw)) return raw;
            if (raw.startsWith('/')) return LAYOUT_ROOT_BASE + raw.slice(1);
            return raw;
        }

        function escapeHtml(value) {
            return String(value || '').replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        function formatNotifTime(value) {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) return '';
            return date.toLocaleString('fr-FR', {
                day: '2-digit',
                month: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function setBadge(id, count) {
            const badge = document.getElementById(id);
            if (!badge) return;
            const n = Number(count || 0);
            badge.textContent = String(n);
            badge.classList.toggle('hidden', n <= 0);
        }

        function setMailConnected(connected) {
            const btn = document.getElementById('employeeMailBtn');
            const icon = document.getElementById('employeeMailIcon');
            if (!btn) return;
            if (icon) {
                icon.classList.toggle('text-amber-700', !connected);
                icon.classList.toggle('text-slate-700', connected);
            }
            btn.title = connected ? 'Messagerie pro' : 'Messagerie pro non connectee - cliquer pour connecter';
            btn.setAttribute('aria-label', btn.title);
            if (!connected) {
                setBadge('employeeMailBadge', 0);
                btn.href = MAILBOX_PAGE_URL;
            }
        }

        async function pollMailboxUnreadOnce() {
            const btn = document.getElementById('employeeMailBtn');
            if (!btn) return;
            try {
                const url = MAILBOX_UNREAD_URL + (MAILBOX_UNREAD_URL.includes('?') ? '&' : '?') + '_=' + Date.now();
                const response = await fetch(url, { cache: 'no-store' });
                const data = await response.json();
                if (!data || !data.ok) {
                    setMailConnected(false);
                    return;
                }
                setMailConnected(!!data.connected);
                if (data.connected) {
                    setBadge('employeeMailBadge', Number(data.unreadCount || 0));
                }
            } catch (error) {
                setMailConnected(false);
            }
        }

        function renderNotifList(items) {
            const list = document.getElementById('employeeNotifList');
            if (!list) return;
            if (!Array.isArray(items) || items.length === 0) {
                list.innerHTML = '<div class="px-4 py-4 text-sm text-slate-500">Aucune notification.</div>';
                return;
            }

            list.innerHTML = items.map((notif) => {
                const unread = !!notif.is_unread;
                const href = escapeHtml(resolveNotifHref(notif.link_url || ''));
                const time = formatNotifTime(notif.created_at);
                return `
                    <button type="button" class="w-full px-4 py-3 text-left hover:bg-slate-50 ${unread ? 'bg-indigo-50' : ''}" data-notif-id="${Number(notif.id || 0)}" data-notif-href="${href}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-800">${escapeHtml(notif.title || 'Notification')}</div>
                                ${notif.message ? `<div class="mt-1 text-xs text-slate-600">${escapeHtml(notif.message)}</div>` : ''}
                                ${time ? `<div class="mt-1 text-[11px] text-slate-500">${escapeHtml(time)}</div>` : ''}
                            </div>
                            ${unread ? '<span class="mt-1 rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">NOUVEAU</span>' : ''}
                        </div>
                    </button>
                `;
            }).join('');

            list.querySelectorAll('button[data-notif-id]').forEach((button) => {
                button.addEventListener('click', async () => {
                    const id = Number(button.getAttribute('data-notif-id') || 0);
                    const href = button.getAttribute('data-notif-href') || '';
                    try {
                        await fetch(NOTIF_MARK_READ_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ids: [id] })
                        });
                    } catch (error) {
                    }
                    if (href) window.location.href = href;
                });
            });
        }

        let notifLatestId = 0;
        const shownToastIds = new Set();
        let notifAudioReady = false;
        let notifAudioElement = null;

        function unlockNotifAudio() {
            notifAudioReady = true;
            if (NOTIF_SOUND_URL && !notifAudioElement) {
                notifAudioElement = new Audio(NOTIF_SOUND_URL);
                notifAudioElement.preload = 'auto';
            }
            document.removeEventListener('click', unlockNotifAudio, true);
            document.removeEventListener('keydown', unlockNotifAudio, true);
            document.removeEventListener('touchstart', unlockNotifAudio, true);
        }

        function playDefaultNotifSound() {
            if (!notifAudioReady) return;
            try {
                const AudioCtx = window.AudioContext || window.webkitAudioContext;
                if (!AudioCtx) return;
                const audioContext = new AudioCtx();
                const oscillator = audioContext.createOscillator();
                const gain = audioContext.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(820, audioContext.currentTime);
                oscillator.frequency.exponentialRampToValueAtTime(640, audioContext.currentTime + 0.16);
                gain.gain.setValueAtTime(0.001, audioContext.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.08, audioContext.currentTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.001, audioContext.currentTime + 0.2);
                oscillator.connect(gain);
                gain.connect(audioContext.destination);
                oscillator.start();
                oscillator.stop(audioContext.currentTime + 0.2);
                oscillator.onended = () => {
                    if (typeof audioContext.close === 'function') {
                        audioContext.close().catch(() => {});
                    }
                };
            } catch (error) {
            }
        }

        function playNotifSound() {
            if (!notifAudioReady) return;
            if (NOTIF_SOUND_URL) {
                try {
                    if (!notifAudioElement) {
                        notifAudioElement = new Audio(NOTIF_SOUND_URL);
                        notifAudioElement.preload = 'auto';
                    }
                    notifAudioElement.pause();
                    notifAudioElement.currentTime = 0;
                    const playPromise = notifAudioElement.play();
                    if (playPromise && typeof playPromise.catch === 'function') {
                        playPromise.catch(() => {
                            playDefaultNotifSound();
                        });
                    }
                    return;
                } catch (error) {
                }
            }
            playDefaultNotifSound();
        }

        function showNotifToast(notif) {
            const container = document.getElementById('employeeNotifToastContainer');
            if (!container) return;
            const href = resolveNotifHref(notif.link_url || '');
            const toast = document.createElement('div');
            toast.className = 'pointer-events-auto mb-2 rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-lg';
            toast.innerHTML = `
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-semibold text-slate-900">${escapeHtml(notif.title || 'Notification')}</div>
                        ${notif.message ? `<div class="mt-1 text-xs text-slate-700">${escapeHtml(notif.message)}</div>` : ''}
                    </div>
                    <button type="button" class="text-slate-500 hover:text-slate-700" aria-label="Fermer">&times;</button>
                </div>
            `;
            const closeBtn = toast.querySelector('button');
            const remove = () => toast.parentNode && toast.parentNode.removeChild(toast);
            closeBtn.addEventListener('click', remove);
            if (href) {
                toast.addEventListener('click', (event) => {
                    if (event.target === closeBtn) return;
                    window.location.href = href;
                });
            }
            container.prepend(toast);
            setTimeout(remove, 6000);
        }

        async function pollNotifications(initial = false) {
            if (window.__fineaNotificationPollInFlight) return;
            window.__fineaNotificationPollInFlight = true;
            try {
                const url = new URL(NOTIF_POLL_URL, window.location.href);
                url.searchParams.set('limit', '10');
                const response = await fetch(url.toString(), { cache: 'no-store' });
                const data = await response.json();
                if (!data || !data.ok) return;

                setBadge('employeeNotifBadge', data.unread_count || 0);
                const notifications = Array.isArray(data.notifications) ? data.notifications : [];
                if (initial) {
                    renderNotifList(notifications);
                    notifications.forEach((notif) => shownToastIds.add(Number(notif.id || 0)));
                    notifLatestId = Math.max(notifLatestId, Number(data.latest_id || 0));
                    return;
                }

                let hasNewUnreadNotification = false;
                notifications.slice().sort((a, b) => Number(a.id || 0) - Number(b.id || 0)).forEach((notif) => {
                    const id = Number(notif.id || 0);
                    notifLatestId = Math.max(notifLatestId, id);
                    if (id > 0 && notif.is_unread && !shownToastIds.has(id)) {
                        shownToastIds.add(id);
                        hasNewUnreadNotification = true;
                        showNotifToast(notif);
                    }
                });

                if (hasNewUnreadNotification) {
                    playNotifSound();
                }
                renderNotifList(notifications);
            } catch (error) {
            } finally {
                window.__fineaNotificationPollInFlight = false;
            }
        }

        document.addEventListener('click', unlockNotifAudio, true);
        document.addEventListener('keydown', unlockNotifAudio, true);
        document.addEventListener('touchstart', unlockNotifAudio, true);

        document.querySelectorAll('[data-employee-notif-toggle]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                document.getElementById('employeeNotifMenu')?.classList.toggle('hidden');
                document.getElementById('employeeUserMenu')?.classList.add('hidden');
            });
        });

        document.getElementById('employeeNotifMarkAllBtn')?.addEventListener('click', async () => {
            try {
                await fetch(NOTIF_MARK_READ_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mark_all: 1 })
                });
            } catch (error) {
            }
            await pollNotifications(true);
        });

        document.getElementById('employeeUserMenuBtn')?.addEventListener('click', (event) => {
            event.stopPropagation();
            const menu = document.getElementById('employeeUserMenu');
            menu?.classList.toggle('hidden');
            document.getElementById('employeeUserMenuBtn')?.setAttribute('aria-expanded', menu && !menu.classList.contains('hidden') ? 'true' : 'false');
            document.getElementById('employeeNotifMenu')?.classList.add('hidden');
        });

        document.addEventListener('click', (event) => {
            const notifMenu = document.getElementById('employeeNotifMenu');
            const userMenu = document.getElementById('employeeUserMenu');
            if (notifMenu && !notifMenu.contains(event.target)) notifMenu.classList.add('hidden');
            if (userMenu && !userMenu.contains(event.target) && !document.getElementById('employeeUserMenuBtn')?.contains(event.target)) {
                userMenu.classList.add('hidden');
                document.getElementById('employeeUserMenuBtn')?.setAttribute('aria-expanded', 'false');
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                document.getElementById('employeeNotifMenu')?.classList.add('hidden');
                document.getElementById('employeeUserMenu')?.classList.add('hidden');
                document.getElementById('employeeUserMenuBtn')?.setAttribute('aria-expanded', 'false');
            }
        });

        if (window.__fineaNotificationPollTimer) clearInterval(window.__fineaNotificationPollTimer);
        pollNotifications(true);
        window.__fineaNotificationPollTimer = setInterval(() => {
            if (!document.hidden) pollNotifications(false);
        }, 45000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) pollNotifications(false);
        });
        setTimeout(() => {
            pollMailboxUnreadOnce();
            setInterval(pollMailboxUnreadOnce, 90000);
        }, 1200);
    })();
</script>
