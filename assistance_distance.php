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
$flashSuccess = null;
$flashError = null;
$isAdmin = \AccessManager::hasPermission('acces_admin');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && isset($_POST['save_remote_assistance_settings']) && $isAdmin) {
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
    date('YmdHis')
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
    'brandLabel' => (string) ($settings['brand_label'] ?? 'Finéa Assistance'),
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

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(14,165,233,0.16),_transparent_24%),radial-gradient(circle_at_bottom_right,_rgba(34,197,94,0.12),_transparent_26%),linear-gradient(180deg,_#f8fbff_0%,_#eef4ff_45%,_#f8fafc_100%)]">
    <div class="mx-auto max-w-7xl px-4 py-8 md:px-6 lg:px-8">
        <section class="relative overflow-hidden rounded-[34px] border border-white/60 bg-white/80 shadow-[0_30px_90px_rgba(15,23,42,0.12)] backdrop-blur">
            <div class="absolute inset-0 bg-[linear-gradient(135deg,rgba(15,23,42,0.97),rgba(30,64,175,0.92)_42%,rgba(8,145,178,0.86))]"></div>
            <div class="absolute -left-16 top-10 h-44 w-44 rounded-full bg-white/10 blur-3xl"></div>
            <div class="absolute right-10 top-6 h-40 w-40 rounded-full bg-cyan-300/20 blur-3xl"></div>
            <div class="relative grid gap-8 px-6 py-8 lg:grid-cols-[1.05fr_0.95fr] lg:px-10 lg:py-10">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2 text-xs font-semibold uppercase tracking-[0.26em] text-sky-100">Assistance à distance intégrée</div>
                    <h1 class="mt-5 max-w-3xl text-3xl font-semibold leading-tight text-white md:text-5xl">Visio Jitsi directement dans Finéa</h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-200 md:text-base">Ce module garde l’utilisateur dans Finéa, lance une salle d’assistance en quelques secondes, et fonctionne immédiatement sans VPS avec Jitsi public.</p>
                    <div class="mt-7 flex flex-wrap gap-3 text-sm text-white/90">
                        <div class="rounded-2xl border border-emerald-300/30 bg-emerald-400/10 px-4 py-3"><span class="font-semibold">Mode recommandé sans VPS:</span> Jitsi public</div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-3"><span class="font-semibold">Domaine:</span> <?= htmlspecialchars((string) ($settings['jitsi_domain'] ?? 'meet.jit.si'), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-3"><span class="font-semibold">Préfixe salle:</span> <?= htmlspecialchars((string) ($settings['room_prefix'] ?? 'FINAEA'), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Reste sur Finéa</div>
                        <div class="mt-3 text-lg font-semibold">Interface embarquée</div>
                        <div class="mt-2 text-sm text-slate-200">La salle s’ouvre dans cette page avec branding et navigation Finéa autour.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Démarrage rapide</div>
                        <div class="mt-3 text-lg font-semibold">meet.jit.si</div>
                        <div class="mt-2 text-sm text-slate-200">Tu peux être opérationnel immédiatement sans quitter la plateforme.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Sans VPS</div>
                        <div class="mt-3 text-lg font-semibold">Aucune infra à gérer</div>
                        <div class="mt-2 text-sm text-slate-200">Audio, vidéo, partage d’écran et multi-utilisateurs fonctionnent sans serveur privé.</div>
                    </article>
                    <article class="rounded-[28px] border border-white/15 bg-white/10 p-5 text-white backdrop-blur-sm">
                        <div class="text-xs uppercase tracking-[0.22em] text-sky-100/80">Guide inclus</div>
                        <div class="mt-3 text-lg font-semibold">Démarrage maintenant, évolution plus tard</div>
                        <div class="mt-2 text-sm text-slate-200">Tu peux lancer la visio tout de suite, puis préparer un domaine privé seulement si un jour tu prends un VPS.</div>
                    </article>
                </div>
            </div>
        </section>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.15fr_0.85fr]">
            <section class="rounded-[30px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Salle d'assistance</div>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Lancer une réunion Jitsi intégrée</h2>
                    </div>
                    <div id="remote-assist-status" class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-600">
                        <span class="h-2.5 w-2.5 rounded-full bg-slate-300"></span>
                        En attente
                    </div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Nom de la salle</span>
                        <input id="remote-assist-room" type="text" value="<?= htmlspecialchars($defaultRoom, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Nom affiché</span>
                        <input id="remote-assist-display-name" type="text" value="<?= htmlspecialchars($userDisplayName, ENT_QUOTES, 'UTF-8') ?>" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-sm font-semibold text-slate-700">Sujet de l'appel</span>
                        <input id="remote-assist-subject" type="text" value="Assistance à distance Finéa" class="mt-2 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800 outline-none focus:border-sky-300 focus:bg-white focus:ring-4 focus:ring-sky-100" />
                    </label>
                </div>

                <div class="mt-5 flex flex-wrap gap-3">
                    <button id="remote-assist-generate" type="button" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:border-slate-300 hover:bg-slate-100"><i class="fas fa-wand-magic-sparkles"></i> Générer une salle</button>
                    <button id="remote-assist-launch" type="button" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800"><i class="fas fa-video"></i> Rejoindre l'appel</button>
                    <button id="remote-assist-share-screen" type="button" class="inline-flex items-center gap-2 rounded-2xl border border-fuchsia-200 bg-fuchsia-50 px-4 py-3 text-sm font-semibold text-fuchsia-700 transition hover:bg-fuchsia-100"><i class="fas fa-display"></i> Partager l'écran</button>
                    <button id="remote-assist-copy" type="button" class="inline-flex items-center gap-2 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-700 transition hover:bg-sky-100"><i class="fas fa-link"></i> Copier le lien</button>
                    <a id="remote-assist-open-external" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100"><i class="fas fa-arrow-up-right-from-square"></i> Ouvrir dehors</a>
                </div>

                <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Lien actif: <span id="remote-assist-link" class="font-semibold text-slate-900"></span>
                </div>

                <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    Sur la version publique meet.jit.si, Jitsi peut afficher un message indiquant que l'appel se terminera dans 5 minutes si vous restez seul dans la salle. Ce n’est pas une limite générale de durée, c’est surtout un avertissement de session solo sur l’instance publique.
                </div>

                <div class="mt-6 overflow-hidden rounded-[28px] border border-slate-200 bg-slate-950">
                    <div class="flex items-center justify-between border-b border-slate-800 px-4 py-3 text-xs font-semibold uppercase tracking-[0.22em] text-slate-300">
                        <span>Console visio</span>
                        <span id="remote-assist-domain-badge" class="rounded-full bg-slate-800 px-3 py-1 text-[11px] text-slate-200"><?= htmlspecialchars((string) ($settings['jitsi_domain'] ?? 'meet.jit.si'), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div id="remote-assist-container" class="flex min-h-[560px] items-center justify-center bg-[radial-gradient(circle_at_top,_rgba(59,130,246,0.18),_transparent_35%),linear-gradient(180deg,_#020617_0%,_#0f172a_100%)] p-6 text-center text-slate-300">
                        <div class="max-w-lg">
                            <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 text-2xl text-white"><i class="fas fa-headset"></i></div>
                            <h3 class="mt-5 text-2xl font-semibold text-white">Assistance prête</h3>
                            <p class="mt-3 text-sm leading-7 text-slate-300">Clique sur Rejoindre l'appel pour lancer Jitsi dans ce panneau. Si le SDK externe ne se charge pas, le module repassera automatiquement sur un iframe interne.</p>
                        </div>
                    </div>
                </div>
            </section>

            <aside class="space-y-6">
                <section class="rounded-[30px] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Guide de mise en place</div>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Ce que tu peux faire toi-même sans VPS</h2>
                    <?php if ($guideNotes !== ''): ?>
                        <p class="mt-3 text-sm leading-7 text-slate-600"><?= nl2br(htmlspecialchars($guideNotes, ENT_QUOTES, 'UTF-8')) ?></p>
                    <?php endif; ?>

                    <div class="mt-5 space-y-4 text-sm text-slate-700">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="font-semibold text-slate-900">1. Tu n’es pas bloqué</div>
                            <p class="mt-2 leading-7">Sans VPS, tu peux quand même utiliser la visio dans Finéa. Laisse simplement le domaine sur <?= htmlspecialchars((string) ($settings['jitsi_domain'] ?? 'meet.jit.si'), ENT_QUOTES, 'UTF-8') ?> et utilise ce module tel quel.</p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="font-semibold text-slate-900">2. Utiliser une salle liée à ton échange</div>
                            <ol class="mt-2 list-decimal space-y-2 pl-5 leading-7">
                                <li>Génère une salle depuis ce module ou saisis un nom explicite, par exemple <span class="font-semibold">FINAEA-CHAT-123</span>.</li>
                                <li>Copie le lien avec le bouton prévu dans l’interface.</li>
                                <li>Envoie ce lien aux autres personnes concernées depuis Finéa.</li>
                                <li>Toutes les personnes qui ouvrent la même salle rejoignent la même réunion.</li>
                            </ol>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="font-semibold text-slate-900">3. Ce que tu obtiens dès maintenant</div>
                            <p class="mt-2 leading-7">Partage d’écran, audio, vidéo et multi-utilisateurs sont disponibles immédiatement. La réunion reste visuellement embarquée dans Finéa, même si le moteur est public.</p>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="font-semibold text-slate-900">4. Plus tard seulement si tu prends un VPS</div>
                            <p class="mt-2 leading-7">Le sous-domaine et le dépôt GitHub ne deviennent utiles que lorsque tu veux ton propre serveur Jitsi. Ce n’est pas nécessaire pour utiliser ce module aujourd’hui.</p>
                            <pre class="mt-3 overflow-x-auto rounded-2xl bg-slate-950 p-4 text-xs text-slate-100">git clone https://github.com/<?= htmlspecialchars((string) ($settings['github_repo'] ?? 'jitsi/docker-jitsi-meet'), ENT_QUOTES, 'UTF-8') ?>.git
cd docker-jitsi-meet
cp env.example .env
./gen-passwords.sh
docker compose up -d</pre>
                        </div>

                        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                            <div class="font-semibold text-slate-900">5. Ce qui ne change pas dans Finéa</div>
                            <p class="mt-2 leading-7">Tu gardes exactement la même interface. Si un jour tu passes sur un serveur privé, tu changeras surtout le domaine Jitsi, pas l’expérience utilisateur.</p>
                        </div>
                    </div>
                </section>

                <?php if ($isAdmin): ?>
                    <section class="rounded-[30px] border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Configuration admin</div>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Paramètres du module</h2>
                        <form method="post" class="mt-5 space-y-4">
                            <input type="hidden" name="save_remote_assistance_settings" value="1" />
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
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>

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
                container.innerHTML = '<iframe src="' + meetingUrl + '#config.prejoinPageEnabled=false" style="width:100%;height:560px;border:0;background:#020617"></iframe>';
            }
            setStatus('Visio embarquée (iframe)', 'bg-amber-400');
        }

        async function launchMeeting() {
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
                        prejoinPageEnabled: false,
                        toolbarButtons: ['microphone', 'camera', 'desktop', 'fullscreen', 'fodeviceselection', 'hangup', 'chat', 'participants-pane', 'tileview', 'settings', 'raisehand', 'videoquality', 'filmstrip'],
                        startWithAudioMuted: !!config.startAudioMuted,
                        startWithVideoMuted: !!config.startVideoMuted
                    }
                });

                currentApi.addEventListener('videoConferenceJoined', function() {
                    remoteParticipantsCount = 0;
                    setStatus('Salle ouverte', 'bg-emerald-400');
                });
                currentApi.addEventListener('readyToClose', function() {
                    setStatus('Appel terminé', 'bg-slate-400');
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
                renderIframeFallback(meetingUrl);
            }
        }

        document.getElementById('remote-assist-generate').addEventListener('click', function() {
            var prefix = slugifyRoom(String(config.roomPrefix || 'FINAEA'));
            var now = new Date();
            var stamp = [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('') + '-' + [String(now.getHours()).padStart(2, '0'), String(now.getMinutes()).padStart(2, '0'), String(now.getSeconds()).padStart(2, '0')].join('');
            if (roomInput) {
                roomInput.value = prefix + '-SUPPORT-' + stamp;
            }
            refreshLinks();
        });

        document.getElementById('remote-assist-launch').addEventListener('click', function() {
            launchMeeting();
        });

        document.getElementById('remote-assist-share-screen').addEventListener('click', async function() {
            if (!currentApi) {
                await launchMeeting();
            }

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