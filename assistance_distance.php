<?php
require_once __DIR__ . '/auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/AuditTrail.php';
require_once __DIR__ . '/model/RemoteAssistanceSettings.php';

$pageTitle = 'Assistance à distance';
$activeMenu = '';
$pdo = \Database::getConnection();
$settingsModel = new RemoteAssistanceSettings();
$_SESSION['assist_csrf'] ??= bin2hex(random_bytes(32));
$flashSuccess = null;
$flashError = null;
$isAdmin = \AccessManager::hasPermission('acces_admin');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['save_remote_assistance_settings']) && $isAdmin) {
    if (!is_string($_POST['assist_csrf'] ?? null) || !hash_equals($_SESSION['assist_csrf'], $_POST['assist_csrf'])) { http_response_code(403); exit('Requête non autorisée'); }
    $saved = $settingsModel->save([
        'deployment_mode' => (string) ($_POST['deployment_mode'] ?? 'public'),
        'jitsi_domain' => (string) ($_POST['jitsi_domain'] ?? ''),
        'room_prefix' => (string) ($_POST['room_prefix'] ?? ''),
        'brand_label' => (string) ($_POST['brand_label'] ?? ''),
        'subdomain' => (string) ($_POST['subdomain'] ?? ''),
        'github_repo' => (string) ($_POST['github_repo'] ?? ''),
        'guide_notes' => (string) ($_POST['guide_notes'] ?? ''),
        'start_audio_muted' => !empty($_POST['start_audio_muted']),
        'start_video_muted' => !empty($_POST['start_video_muted']),
    ]);

    if ($saved) {
        $flashSuccess = 'Paramètres Jitsi enregistrés.';
    } else {
        $flashError = 'Impossible d’enregistrer la configuration Jitsi.';
    }
}

$settings = $settingsModel->load();
$userDisplayName = trim((string) ($_SESSION['user_name'] ?? trim((string) (($_SESSION['prenom'] ?? '') . ' ' . ($_SESSION['nom'] ?? '')))));
if ($userDisplayName === '') {
    $userDisplayName = trim((string) ($_SESSION['mail_pro'] ?? 'Utilisateur Finéa'));
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$defaultRoom = sprintf(
    '%s-SUPPORT-%d-%s',
    (string) ($settings['room_prefix'] ?? 'FINAEA'),
    $userId > 0 ? $userId : 1,
    bin2hex(random_bytes(16))
);

\AuditTrail::logPageConsultation([
    'module' => 'assistance_distance',
    'action' => 'page_view',
    'entity_type' => 'page',
    'entity_label' => 'assistance_distance.php',
    'page_name' => 'assistance_distance.php',
    'details' => [
        'view_mode' => 'jitsi_module',
        'domain' => (string) ($settings['jitsi_domain'] ?? 'meet.jit.si'),
    ],
], $pdo);

$pageConfig = [
    'domain' => (string) ($settings['jitsi_domain'] ?? 'meet.jit.si'),
    'roomPrefix' => (string) ($settings['room_prefix'] ?? 'FINAEA'),
    'defaultRoom' => $defaultRoom,
    'brandLabel' => 'FINEA Assist',
    'displayName' => $userDisplayName,
    'scriptUrl' => $settingsModel->getPublicScriptUrl((string) ($settings['jitsi_domain'] ?? 'meet.jit.si')),
    'meetingBaseUrl' => $settingsModel->getPublicMeetingBaseUrl((string) ($settings['jitsi_domain'] ?? 'meet.jit.si')),
    'startAudioMuted' => !empty($settings['start_audio_muted']),
    'startVideoMuted' => !empty($settings['start_video_muted']),
];

$pageConfigJson = json_encode($pageConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$guideNotes = trim((string) ($settings['guide_notes'] ?? ''));

require_once __DIR__ . '/decaissement/includes/header.php';
require_once __DIR__ . '/decaissement/includes/navbar.php';
?>

<link rel="stylesheet" href="assets/css/assist.css?v=<?= filemtime(__DIR__.'/assets/css/assist.css') ?>">
<div class="assist-app">
<aside class="assist-sidebar">
<div class="assist-brand"><i class="fas fa-headset"></i><div><strong>FINEA Assist</strong><small><?= htmlspecialchars((string)($layoutCompanyBranding['company_name'] ?? 'FINEA'), ENT_QUOTES, 'UTF-8') ?></small></div></div>
<button type="button" id="remote-assist-generate" class="assist-primary"><i class="fas fa-plus"></i> Nouvelle session</button>
<h2>Sessions récentes</h2><div id="assist-history"></div>
<button type="button" id="assist-clear">Effacer l'historique local</button>
<details><summary>Comment ça marche ?</summary><p>Créez une salle, copiez son lien et transmettez-le à votre interlocuteur. Rejoignez ensuite l'appel et autorisez le partage d'écran si nécessaire.</p><p>Le service ne permet pas de contrôler un ordinateur à distance.</p></details>
<a href="selection_portail.php"><i class="fas fa-arrow-left"></i> Retour au portail</a>
</aside>
<main class="assist-main">
<header class="assist-top"><div><strong>Assistance à distance</strong><small>Visioconférence et partage d'écran</small></div><div id="remote-assist-status" role="status">En attente</div><button id="assist-theme" type="button">Mode sombre</button></header>
<?php if ($flashSuccess || $flashError): ?><p role="status"><?= htmlspecialchars($flashSuccess ?? $flashError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
<div class="assist-center">
<div id="remote-assist-container" class="assist-video"><div class="assist-welcome"><i class="fas fa-headset"></i><h1>Comment pouvons-nous vous aider ?</h1><p>Créez une session d'assistance ou rejoignez une salle existante.</p><div class="assist-choices"><button type="button" data-subject="Problème informatique"><i class="fas fa-desktop"></i> Problème informatique</button><button type="button" data-subject="Aide sur FINEA"><i class="fas fa-circle-question"></i> Aide sur FINEA</button><button type="button" data-subject="Partage d'écran"><i class="fas fa-display"></i> Partage d'écran</button></div></div></div>
<div class="assist-composer">
<label for="remote-assist-subject">Sujet de votre assistance</label><input id="remote-assist-subject" placeholder="Décrivez brièvement votre besoin…" maxlength="160">
<details><summary>Rejoindre une salle existante / options</summary><label>Code de salle<input id="remote-assist-room" value="<?= htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8') ?>"></label><label>Nom affiché<input id="remote-assist-display-name" value="<?= htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') ?>"></label></details>
<div class="assist-actions"><button id="remote-assist-copy" type="button"><i class="fas fa-link"></i> Copier l'invitation</button><button id="remote-assist-share-screen" type="button"><i class="fas fa-display"></i> Partager l'écran</button><a id="remote-assist-open-external" target="_blank" rel="noopener noreferrer" href="#">Ouvrir dans un onglet</a><button id="remote-assist-launch" class="assist-primary" type="button"><i class="fas fa-video"></i> Rejoindre l'appel</button><button id="assist-end" type="button" hidden>Terminer</button></div>
<small>Invitation : <span id="remote-assist-link"></span></small>
</div>
<p class="assist-note">Service vidéo : <span id="remote-assist-domain-badge"></span>. Ne partagez le lien qu'avec les participants autorisés. Le service public peut demander une authentification.</p>
                <?php if ($isAdmin): ?>
                    <details class="assist-settings"><summary>Paramètres administrateur</summary>
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Configuration admin</div>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Paramètres du module</h2>
                        <form method="post" class="mt-5 space-y-4">
                            <input type="hidden" name="save_remote_assistance_settings" value="1" /><input type="hidden" name="assist_csrf" value="<?= htmlspecialchars($_SESSION['assist_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Mode</span>
                                    <select name="deployment_mode" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100">
                                        <option value="public" <?= (string) ($settings['deployment_mode'] ?? 'public') === 'public' ? ' selected' : '' ?>>Public (meet.jit.si ou équivalent)</option>
                                        <option value="private" <?= (string) ($settings['deployment_mode'] ?? 'public') === 'private' ? ' selected' : '' ?>>Privé plus tard si vous avez un VPS</option>
                                    </select>
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Domaine Jitsi</span>
                                    <input type="text" name="jitsi_domain" value="<?= htmlspecialchars((string) ($settings['jitsi_domain'] ?? 'meet.jit.si'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Préfixe des salles</span>
                                    <input type="text" name="room_prefix" value="<?= htmlspecialchars((string) ($settings['room_prefix'] ?? 'FINAEA'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Libellé marque</span>
                                    <input type="text" name="brand_label" value="<?= htmlspecialchars((string) ($settings['brand_label'] ?? 'Finéa Assistance'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Sous-domaine cible</span>
                                    <input type="text" name="subdomain" value="<?= htmlspecialchars((string) ($settings['subdomain'] ?? 'meet.finea.ci'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                                </label>
                                <label class="block">
                                    <span class="text-sm font-semibold text-slate-700">Dépôt GitHub</span>
                                    <input type="text" name="github_repo" value="<?= htmlspecialchars((string) ($settings['github_repo'] ?? 'jitsi/docker-jitsi-meet'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                                </label>
                            </div>

                            <label class="block">
                                <span class="text-sm font-semibold text-slate-700">Notes de guide</span>
                                <textarea name="guide_notes" rows="4" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100"><?= htmlspecialchars((string) ($settings['guide_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>

                            <div class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800">
                                Sans VPS, gardez simplement <?= htmlspecialchars((string) ($settings['jitsi_domain'] ?? 'meet.jit.si'), ENT_QUOTES, 'UTF-8') ?>. Les champs sous-domaine et dépôt GitHub servent uniquement pour une évolution future vers un hébergement privé.
                            </div>

                            <div class="flex flex-wrap gap-4 text-sm text-slate-700">
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="start_audio_muted" value="1" <?= !empty($settings['start_audio_muted']) ? ' checked' : '' ?> /> Démarrer micro coupé</label>
                                <label class="inline-flex items-center gap-2"><input type="checkbox" name="start_video_muted" value="1" <?= !empty($settings['start_video_muted']) ? ' checked' : '' ?> /> Démarrer caméra coupée</label>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"><i class="fas fa-save"></i> Enregistrer</button>
                            </div>
                        </form>
                    </details>
                <?php endif; ?>

</div></main></div>
<script>
    (function() {
        var config = <?= $pageConfigJson !== false ? $pageConfigJson : '{}' ?>;
        var currentApi = null;
        var loadedScriptUrl = '';
        var roomInput = document.getElementById('remote-assist-room');
        var displayNameInput = document.getElementById('remote-assist-display-name');
        var subjectInput = document.getElementById('remote-assist-subject');
        var statusEl = document.getElementById('remote-assist-status');
        var linkEl = document.getElementById('remote-assist-link');
        var container = document.getElementById('remote-assist-container');
        var externalLink = document.getElementById('remote-assist-open-external');
        var domainBadge = document.getElementById('remote-assist-domain-badge');
        var remoteParticipantsCount = 0;
        var busy=false;
        var welcome=container.innerHTML;
        var endButton=document.getElementById('assist-end');
        var historyKey='finea.assist.history.'+<?= json_encode(hash('sha256', (string)($layoutCompanyBranding['company_name'] ?? 'FINEA').'|'.$userId.'|'.($settings['jitsi_domain'] ?? ''))) ?>;
        var history=[];try{history=JSON.parse(localStorage.getItem(historyKey)||'[]');if(!Array.isArray(history))history=[];}catch(e){}
        function renderHistory(){var el=document.getElementById('assist-history');el.replaceChildren();history.slice(0,12).forEach(function(item){var b=document.createElement('button');b.type='button';b.textContent=item.subject||'Session';b.onclick=function(){if(currentApi||!endButton.hidden)return;roomInput.value=item.room;subjectInput.value=item.subject;refreshLinks();};el.appendChild(b);});if(!history.length)el.textContent='Aucune session récente';}
        function remember(room){history=[{room:room,subject:subjectInput.value.trim()||'Assistance'},...history.filter(x=>x.room!==room)].slice(0,12);try{localStorage.setItem(historyKey,JSON.stringify(history));}catch(e){}renderHistory();}
        document.getElementById('assist-clear').onclick=function(){history=[];try{localStorage.removeItem(historyKey);}catch(e){}renderHistory();};renderHistory();
        function bindChoices(){container.querySelectorAll('[data-subject]').forEach(b=>b.onclick=()=>{subjectInput.value=b.dataset.subject;subjectInput.focus();});}bindChoices();
        function endSession(){destroyCurrentApi();container.innerHTML=welcome;container.classList.remove('assist-live');endButton.hidden=true;bindChoices();setStatus('Appel terminé','bg-slate-400');}
        endButton.onclick=endSession;
        var theme=document.getElementById('assist-theme');function syncTheme(){var dark=document.documentElement.classList.contains('finea-dark-mode');document.body.classList.toggle('finea-dark-mode',dark);theme.textContent=dark?'Mode clair':'Mode sombre';}theme.onclick=function(){var dark=!document.documentElement.classList.contains('finea-dark-mode');document.documentElement.classList.toggle('finea-dark-mode',dark);try{localStorage.setItem('finea.app.theme',dark?'dark':'light');localStorage.setItem('finea.portal.theme',dark?'dark':'light');}catch(e){}syncTheme();};new MutationObserver(syncTheme).observe(document.documentElement,{attributes:true,attributeFilter:['class']});syncTheme();

        function slugifyRoom(value) {
            value = String(value || '').trim().toUpperCase();
            value = value.replace(/[^A-Z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
            return value || String(config.defaultRoom || 'FINAEA-SUPPORT');
        }

        function sanitizeDomain(value) {
            value = String(value || config.domain || 'meet.jit.si').trim().toLowerCase();
            value = value.replace(/^https?:\/\//, '').replace(/\/+$/, '');
            return value || 'meet.jit.si';
        }

        function getMeetingLink() {
            var domain = sanitizeDomain(config.domain);
            var room = slugifyRoom(roomInput ? roomInput.value : config.defaultRoom);
            return 'https://' + domain + '/' + encodeURIComponent(room);
        }

        function refreshLinks() {
            var link = getMeetingLink();
            if (linkEl) {
                linkEl.textContent = link;
            }
            if (externalLink) {
                externalLink.href = link;
            }
            if (domainBadge) {
                domainBadge.textContent = sanitizeDomain(config.domain);
            }
        }

        function setStatus(label, colorClass) {
            if (!statusEl) {
                return;
            }
            statusEl.innerHTML = '<span class="h-2.5 w-2.5 rounded-full ' + colorClass + '"></span>' + label;
        }

        function destroyCurrentApi() {
            if (currentApi && typeof currentApi.dispose === 'function') {
                currentApi.dispose();
            }
            currentApi = null;
            remoteParticipantsCount = 0;
        }

        function loadExternalScript(scriptUrl) {
            return new Promise(function(resolve, reject) {
                if (window.JitsiMeetExternalAPI && loadedScriptUrl === scriptUrl) {
                    resolve();
                    return;
                }

                var existing = document.querySelector('script[data-jitsi-external="1"][src="' + scriptUrl + '"]');
                if (existing) {
                    existing.addEventListener('load', function() {
                        resolve();
                    }, {
                        once: true
                    });
                    existing.addEventListener('error', function() {
                        reject(new Error('Script Jitsi inaccessible.'));
                    }, {
                        once: true
                    });
                    return;
                }

                var script = document.createElement('script');
                script.src = scriptUrl;
                script.async = true;
                script.dataset.jitsiExternal = '1';
                script.onload = function() {
                    loadedScriptUrl = scriptUrl;
                    resolve();
                };
                script.onerror = function() {
                    reject(new Error('Script Jitsi inaccessible.'));
                };
                document.head.appendChild(script);
            });
        }

        function renderIframeFallback(meetingUrl) {
            destroyCurrentApi();
            if (container) {
                var frame=document.createElement('iframe');frame.src=meetingUrl;frame.title='Salle Jitsi';frame.allow='camera; microphone; display-capture; fullscreen; autoplay';frame.allowFullscreen=true;frame.style.cssText='width:100%;height:560px;border:0';container.replaceChildren(frame);
            }
            setStatus('Visio embarquée (iframe)', 'bg-amber-400');
        }

        async function launchMeeting() {
            if(busy || !endButton.hidden)return;
            busy=true;
            container.classList.add('assist-live');
            var room = slugifyRoom(roomInput ? roomInput.value : config.defaultRoom);
            if (roomInput) {
                roomInput.value = room;
            }
            var meetingUrl = getMeetingLink();
            refreshLinks();

            if (!container) {
                return;
            }

            setStatus('Connexion en cours', 'bg-sky-400');
            container.innerHTML = '<div class="text-center text-slate-300"><div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white/10 text-white"><i class="fas fa-spinner fa-spin"></i></div><div class="mt-4 text-sm">Chargement de Jitsi...</div></div>';

            try {
                await loadExternalScript(String(config.scriptUrl || 'https://meet.jit.si/external_api.js'));
                destroyCurrentApi();
                container.innerHTML = '';
                currentApi = new window.JitsiMeetExternalAPI(sanitizeDomain(config.domain), {
                    roomName: room,
                    parentNode: container,
                    width: '100%',
                    height: 560,
                    configOverwrite: {
                        prejoinPageEnabled: true,
                        toolbarButtons: ['microphone', 'camera', 'desktop', 'fullscreen', 'fodeviceselection', 'hangup', 'chat', 'participants-pane', 'tileview', 'settings', 'raisehand', 'videoquality', 'filmstrip'],
                        startWithAudioMuted: !!config.startAudioMuted,
                        startWithVideoMuted: !!config.startVideoMuted
                    }
                });

                endButton.hidden=false;
                remember(room);
                currentApi.addEventListener('videoConferenceJoined', function() {
                    remoteParticipantsCount = 0;
                    setStatus('Salle ouverte', 'bg-emerald-400');
                });
                currentApi.addEventListener('readyToClose', function() {
                    endSession();
                });
                currentApi.addEventListener('videoConferenceLeft', function() {
                    setStatus('Appel terminé', 'bg-slate-400');
                });
                currentApi.addEventListener('participantJoined', function() {
                    remoteParticipantsCount += 1;
                    setStatus('Appel en cours', 'bg-emerald-400');
                });
                currentApi.addEventListener('participantLeft', function() {
                    remoteParticipantsCount = Math.max(0, remoteParticipantsCount - 1);
                    if (remoteParticipantsCount === 0) {
                        setStatus('Seul dans la salle', 'bg-amber-400');
                    }
                });
                currentApi.addEventListener('screenSharingStatusChanged', function(event) {
                    if (event && event.on) {
                        setStatus('Partage d\'écran actif', 'bg-fuchsia-400');
                        return;
                    }
                    if (remoteParticipantsCount > 0) {
                        setStatus('Appel en cours', 'bg-emerald-400');
                        return;
                    }
                    setStatus('Salle ouverte', 'bg-emerald-400');
                });

                var displayName = String(displayNameInput ? displayNameInput.value : config.displayName || '').trim();
                if (displayName !== '') {
                    currentApi.executeCommand('displayName', displayName);
                }

                var subject = String(subjectInput ? subjectInput.value : '').trim();
                if (subject !== '') {
                    currentApi.executeCommand('subject', subject);
                }
            } catch (error) {
                renderIframeFallback(meetingUrl);endButton.hidden=false;remember(room);
            } finally {busy=false;}
        }

        document.getElementById('remote-assist-generate').addEventListener('click', function() {
            var prefix = slugifyRoom(String(config.roomPrefix || 'FINAEA'));
            var now = new Date();
            var stamp = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('') + '-' + [String(now.getHours()).padStart(2, '0'), String(now.getMinutes()).padStart(2, '0'), String(now.getSeconds()).padStart(2, '0')].join('');
            if (roomInput) {
                if(busy||!endButton.hidden)return;
                roomInput.value = prefix + '-SUPPORT-' + Array.from(crypto.getRandomValues(new Uint8Array(16)),x=>x.toString(16).padStart(2,'0')).join('').toUpperCase();
                subjectInput.value='';subjectInput.focus();
            }
            refreshLinks();
        });

        document.getElementById('remote-assist-launch').addEventListener('click', function() {
            launchMeeting();
        });

        document.getElementById('remote-assist-share-screen').addEventListener('click', async function() {
            if (!currentApi) {setStatus('Rejoignez d’abord l’appel','bg-amber-400');return;}

            if (!currentApi) {
                setStatus('Lancez d\'abord l\'appel', 'bg-amber-400');
                return;
            }

            try {
                currentApi.executeCommand('toggleShareScreen');
            } catch (error) {
                setStatus('Partage d\'écran indisponible', 'bg-rose-400');
            }
        });

        document.getElementById('remote-assist-copy').addEventListener('click', async function() {
            var link = getMeetingLink();
            try {
                await navigator.clipboard.writeText(link);
                setStatus('Lien copié', 'bg-sky-400');
            } catch (error) {
                setStatus('Copie manuelle requise', 'bg-amber-400');
            }
        });

        if (roomInput) {
            roomInput.addEventListener('input', refreshLinks);
        }

        refreshLinks();
    })();
</script>

<?php require_once __DIR__ . '/decaissement/includes/footer.php'; ?>