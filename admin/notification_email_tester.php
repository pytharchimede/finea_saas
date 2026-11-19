<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/NotificationController.php';

$pdo = Database::getConnection();
$users = $pdo->query("SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE active = 1 AND mail_pro IS NOT NULL AND mail_pro <> '' ORDER BY prenom ASC, nom ASC")->fetchAll(PDO::FETCH_ASSOC);
$usersForJs = array_map(static function (array $user): array {
    $name = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? ''));
    $email = trim((string)($user['mail_pro'] ?? ''));
    return [
        'id' => (int)($user['id'] ?? 0),
        'name' => $name !== '' ? $name : $email,
        'email' => $email,
    ];
}, $users);
$scenarios = NotificationService::getAdminEmailTestScenarios();

$pageTitle = 'Test Notifications Email';
$activeMenu = '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(2,132,199,0.13),_transparent_32%),radial-gradient(circle_at_bottom_right,_rgba(217,70,239,0.10),_transparent_28%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)]">
    <div class="container mx-auto px-4 py-8 lg:py-10">
        <section class="relative overflow-hidden rounded-[30px] border border-slate-200/70 bg-white/92 shadow-[0_30px_80px_-38px_rgba(15,23,42,0.55)] backdrop-blur">
            <div class="absolute inset-y-0 right-0 w-1/3 bg-[linear-gradient(135deg,rgba(14,165,233,0.10),rgba(79,70,229,0.12))]"></div>
            <div class="relative grid gap-8 px-6 py-8 lg:grid-cols-[1.35fr_0.95fr] lg:px-10">
                <div>
                    <div class="inline-flex items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-3 py-1 text-xs font-semibold uppercase tracking-[0.22em] text-sky-700">
                        Contrôle délivrabilité email
                    </div>
                    <h1 class="mt-4 max-w-3xl text-3xl font-black tracking-tight text-slate-900 lg:text-5xl">
                        Tester exactement les notifications email envoyées par le système.
                    </h1>
                    <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-600 lg:text-base">
                        Choisissez un type de notification, renseignez les champs dynamiques, prévisualisez le sujet et le HTML réellement produits par NotificationController, puis envoyez un mail de test à l’adresse de votre choix pour contrôler le spam et la délivrabilité.
                    </p>
                    <div class="mt-6 flex flex-wrap gap-3">
                        <a href="dashboard_admin.php" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            <i class="fas fa-arrow-left"></i>
                            Retour dashboard admin
                        </a>
                        <a href="email_logs.php" class="inline-flex items-center gap-2 rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-sky-300 hover:text-sky-700">
                            <i class="fas fa-envelope-open-text"></i>
                            Voir les logs email
                        </a>
                    </div>
                </div>
                <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                    <div class="rounded-3xl border border-slate-200 bg-slate-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Pipeline réel</div>
                        <p class="mt-3 text-sm leading-6 text-slate-700">Le test reprend les vraies règles, les templates stockés et le rendu HTML utilisé en production.</p>
                    </div>
                    <div class="rounded-3xl border border-emerald-200 bg-emerald-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Spam check</div>
                        <p class="mt-3 text-sm leading-6 text-emerald-900">Envoyez vers une boîte de test et comparez immédiatement l’aperçu rendu avec les enregistrements du journal SMTP.</p>
                    </div>
                    <div class="rounded-3xl border border-amber-200 bg-amber-50/90 p-5">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-700">Paramètres dynamiques</div>
                        <p class="mt-3 text-sm leading-6 text-amber-900">Chaque scénario expose ses champs utiles, avec possibilité d’injecter des surcharges JSON pour les cas limites.</p>
                    </div>
                </div>
            </div>
        </section>

        <div class="mt-8 grid gap-6 xl:grid-cols-[0.95fr_1.05fr]">
            <section class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                <form id="testerForm" class="space-y-6">
                    <div>
                        <label for="scenarioSelect" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Type de mail</label>
                        <select id="scenarioSelect" name="scenario" class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100"></select>
                        <p id="scenarioDescription" class="mt-2 text-sm text-slate-500"></p>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-slate-50/80 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Destinataire de test</div>
                        <div class="mt-4 flex flex-wrap gap-3 text-sm font-semibold text-slate-700">
                            <label class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 shadow-sm">
                                <input type="radio" name="recipient_mode" value="manual" checked>
                                Adresse libre
                            </label>
                            <label class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 shadow-sm">
                                <input type="radio" name="recipient_mode" value="user">
                                Utilisateur interne
                            </label>
                        </div>
                        <div id="manualRecipientBlock" class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="recipientEmail" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Email de test</label>
                                <input id="recipientEmail" name="recipient_email" type="email" placeholder="test@exemple.com" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            </div>
                            <div>
                                <label for="recipientName" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Nom affiché</label>
                                <input id="recipientName" name="recipient_name" type="text" placeholder="Boîte de test" class="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            </div>
                        </div>
                        <div id="userRecipientBlock" class="mt-4 hidden">
                            <input type="hidden" id="recipientUserId" name="recipient_user_id" value="">
                            <label for="recipientUserSearch" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Utilisateur actif</label>
                            <div class="relative mt-2">
                                <div class="relative">
                                    <i class="fas fa-search pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input id="recipientUserSearch" type="text" autocomplete="off" placeholder="Rechercher un personnel par nom, prénom ou email…" class="w-full rounded-2xl border border-slate-300 bg-white py-3 pl-11 pr-4 text-sm text-slate-900 outline-none transition focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                                </div>
                                <div id="recipientUserResults" class="absolute z-30 mt-2 hidden max-h-72 w-full overflow-y-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_18px_50px_rgba(15,23,42,0.18)]"></div>
                            </div>
                            <div id="recipientUserSelected" class="mt-3 hidden rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">Personnel sélectionné</div>
                                        <div id="recipientUserSelectedName" class="mt-1 truncate text-sm font-bold text-slate-900"></div>
                                        <div id="recipientUserSelectedEmail" class="mt-0.5 truncate text-xs text-slate-600"></div>
                                    </div>
                                    <button type="button" id="recipientUserClear" class="inline-flex items-center justify-center rounded-xl border border-sky-200 bg-white px-3 py-2 text-xs font-semibold text-sky-700 transition hover:bg-sky-100">Effacer</button>
                                </div>
                            </div>
                            <?php /*
                            <select id="recipientUserLegacySelect" class="hidden">
                                <option value="">Choisir un utilisateur</option>
                                <?php foreach ($users as $user): ?>
                                    <?php $name = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? '')); ?>
                                    <option value="<?= (int)($user['id'] ?? 0) ?>" data-email="<?= htmlspecialchars((string)($user['mail_pro'] ?? '')) ?>" data-name="<?= htmlspecialchars($name !== '' ? $name : (string)($user['mail_pro'] ?? '')) ?>">
                                        <?= htmlspecialchars(($name !== '' ? $name : 'Utilisateur #' . (int)($user['id'] ?? 0)) . ' — ' . (string)($user['mail_pro'] ?? '')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            */ ?>
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-4">
                            <label class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Champs dynamiques</label>
                            <button id="resetFieldsButton" type="button" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-sky-300 hover:text-sky-700">
                                <i class="fas fa-rotate-left"></i>
                                Réinitialiser
                            </button>
                        </div>
                        <div id="dynamicFields" class="mt-3 grid gap-4"></div>
                    </div>

                    <div>
                        <label for="rawJsonOverrides" class="block text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Surcharges JSON optionnelles</label>
                        <textarea id="rawJsonOverrides" name="raw_json" rows="6" placeholder='{"motif":"Texte de test", "precision":"Valeur forcée"}' class="mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100"></textarea>
                        <p class="mt-2 text-xs text-slate-500">Les valeurs JSON écrasent les champs saisis ci-dessus avant génération du mail.</p>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button id="previewButton" type="submit" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            <i class="fas fa-eye"></i>
                            Prévisualiser
                        </button>
                        <button id="sendButton" type="button" class="inline-flex items-center gap-2 rounded-2xl bg-gradient-to-r from-emerald-600 to-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:translate-y-[-1px] hover:from-emerald-700 hover:to-sky-700">
                            <i class="fas fa-paper-plane"></i>
                            Envoyer le mail de test
                        </button>
                    </div>
                </form>

                <div id="statusBox" class="mt-5 hidden rounded-2xl border px-4 py-3 text-sm font-medium"></div>
            </section>

            <section class="space-y-6">
                <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Aperçu généré</h2>
                            <p class="mt-1 text-sm text-slate-500">Sujet, message, lien et règles réellement résolus par NotificationController.</p>
                        </div>
                        <div id="ruleBadge" class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">Aucun aperçu</div>
                    </div>
                    <div class="mt-5 grid gap-4 md:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Sujet</div>
                            <div id="previewSubject" class="mt-2 text-sm font-semibold text-slate-900">—</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Destinataire réel de la règle</div>
                            <div id="previewRuleRecipient" class="mt-2 text-sm font-semibold text-slate-900">—</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Message</div>
                            <div id="previewMessage" class="mt-2 whitespace-pre-line text-sm leading-6 text-slate-700">—</div>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 md:col-span-2">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Lien résolu</div>
                            <div id="previewLink" class="mt-2 break-all text-sm text-slate-700">—</div>
                        </div>
                    </div>
                </div>

                <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">HTML email</h2>
                            <p class="mt-1 text-sm text-slate-500">Rendu tel qu’il sera injecté dans Mailer.</p>
                        </div>
                        <div id="previewChannels" class="flex flex-wrap gap-2"></div>
                    </div>
                    <iframe id="previewFrame" title="Aperçu email" sandbox="allow-same-origin" class="mt-5 h-[420px] w-full rounded-3xl border border-slate-200 bg-white"></iframe>
                </div>

                <div class="rounded-[28px] border border-slate-200/70 bg-white/95 p-6 shadow-[0_24px_55px_-35px_rgba(15,23,42,0.42)]">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-bold text-slate-900">Contexte injecté</h2>
                            <p class="mt-1 text-sm text-slate-500">Variables réellement utilisées dans les templates.</p>
                        </div>
                    </div>
                    <pre id="previewContext" class="mt-5 overflow-x-auto rounded-3xl border border-slate-200 bg-slate-950 p-4 text-xs leading-6 text-slate-100">{}</pre>
                </div>
            </section>
        </div>
    </div>
</div>

<script>
    (() => {
        const scenarios = <?= json_encode(array_values($scenarios), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const users = <?= json_encode(array_values($usersForJs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const scenarioMap = new Map(scenarios.map(item => [item.key, item]));

        const scenarioSelect = document.getElementById('scenarioSelect');
        const scenarioDescription = document.getElementById('scenarioDescription');
        const dynamicFields = document.getElementById('dynamicFields');
        const testerForm = document.getElementById('testerForm');
        const previewButton = document.getElementById('previewButton');
        const sendButton = document.getElementById('sendButton');
        const resetFieldsButton = document.getElementById('resetFieldsButton');
        const statusBox = document.getElementById('statusBox');
        const rawJsonOverrides = document.getElementById('rawJsonOverrides');
        const recipientUserId = document.getElementById('recipientUserId');
        const recipientUserSearch = document.getElementById('recipientUserSearch');
        const recipientUserResults = document.getElementById('recipientUserResults');
        const recipientUserSelected = document.getElementById('recipientUserSelected');
        const recipientUserSelectedName = document.getElementById('recipientUserSelectedName');
        const recipientUserSelectedEmail = document.getElementById('recipientUserSelectedEmail');
        const recipientUserClear = document.getElementById('recipientUserClear');
        const manualRecipientBlock = document.getElementById('manualRecipientBlock');
        const userRecipientBlock = document.getElementById('userRecipientBlock');
        const previewSubject = document.getElementById('previewSubject');
        const previewRuleRecipient = document.getElementById('previewRuleRecipient');
        const previewMessage = document.getElementById('previewMessage');
        const previewLink = document.getElementById('previewLink');
        const previewFrame = document.getElementById('previewFrame');
        const previewContext = document.getElementById('previewContext');
        const previewChannels = document.getElementById('previewChannels');
        const ruleBadge = document.getElementById('ruleBadge');

        let lastPreview = null;
        let selectedUser = null;
        let userSearchResults = [];
        let highlightedUserIndex = -1;

        function escapeHtml(value) {
            return String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function setStatus(message, tone = 'info') {
            const tones = {
                info: 'border-sky-200 bg-sky-50 text-sky-800',
                success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
                error: 'border-rose-200 bg-rose-50 text-rose-800'
            };
            statusBox.className = `mt-5 rounded-2xl border px-4 py-3 text-sm font-medium ${tones[tone] || tones.info}`;
            statusBox.textContent = message;
            statusBox.classList.remove('hidden');
        }

        function normalizeSearch(value) {
            return String(value || '')
                .toLowerCase()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '');
        }

        function closeUserResults() {
            if (recipientUserResults) {
                recipientUserResults.classList.add('hidden');
            }
            highlightedUserIndex = -1;
        }

        function updateUserHighlight() {
            if (!recipientUserResults) return;
            recipientUserResults.querySelectorAll('[data-user-index]').forEach((button, index) => {
                button.classList.toggle('bg-sky-50', index === highlightedUserIndex);
                button.classList.toggle('border-sky-200', index === highlightedUserIndex);
                button.classList.toggle('border-transparent', index !== highlightedUserIndex);
            });
        }

        function selectUser(user) {
            selectedUser = user || null;
            recipientUserId.value = selectedUser ? String(selectedUser.id) : '';
            if (recipientUserSearch) {
                recipientUserSearch.value = selectedUser ? `${selectedUser.name} — ${selectedUser.email}` : '';
            }
            if (recipientUserSelected) {
                recipientUserSelected.classList.toggle('hidden', !selectedUser);
            }
            if (recipientUserSelectedName) {
                recipientUserSelectedName.textContent = selectedUser ? selectedUser.name : '';
            }
            if (recipientUserSelectedEmail) {
                recipientUserSelectedEmail.textContent = selectedUser ? selectedUser.email : '';
            }
            closeUserResults();
        }

        function renderUserResults(query = '') {
            if (!recipientUserResults) return;
            const needle = normalizeSearch(query.trim());
            userSearchResults = users.filter(user => {
                if (!needle) return true;
                return normalizeSearch(`${user.name} ${user.email}`).includes(needle);
            }).slice(0, 30);

            if (!userSearchResults.length) {
                recipientUserResults.innerHTML = '<div class="rounded-xl px-3 py-3 text-sm text-slate-500">Aucun personnel trouvé</div>';
                recipientUserResults.classList.remove('hidden');
                highlightedUserIndex = -1;
                return;
            }

            recipientUserResults.innerHTML = userSearchResults.map((user, index) => `
                <button type="button" data-user-index="${index}" class="block w-full rounded-xl border border-transparent px-3 py-2 text-left transition hover:border-sky-200 hover:bg-sky-50">
                    <span class="block truncate text-sm font-semibold text-slate-900">${escapeHtml(user.name)}</span>
                    <span class="block truncate text-xs text-slate-500">${escapeHtml(user.email)}</span>
                </button>
            `).join('');
            recipientUserResults.classList.remove('hidden');
            highlightedUserIndex = userSearchResults.length ? 0 : -1;
            updateUserHighlight();
        }

        function currentScenario() {
            return scenarioMap.get(scenarioSelect.value) || scenarios[0] || null;
        }

        function renderScenarioOptions() {
            scenarioSelect.innerHTML = scenarios.map(item => `<option value="${escapeHtml(item.key)}">${escapeHtml(item.label)}</option>`).join('');
        }

        function renderFields() {
            const scenario = currentScenario();
            if (!scenario) {
                dynamicFields.innerHTML = '<div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucun scénario disponible.</div>';
                return;
            }
            scenarioDescription.textContent = scenario.description || '';
            dynamicFields.innerHTML = (scenario.fields || []).map(field => {
                const isTextarea = field.type === 'textarea';
                const inputClass = 'mt-2 w-full rounded-2xl border border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100';
                if (isTextarea) {
                    return `
                    <div>
                        <label for="field_${escapeHtml(field.name)}" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">${escapeHtml(field.label)}</label>
                        <textarea id="field_${escapeHtml(field.name)}" data-dynamic-field="${escapeHtml(field.name)}" rows="4" class="${inputClass}" ${field.required ? 'required' : ''}>${escapeHtml(field.default || '')}</textarea>
                    </div>
                `;
                }
                return `
                <div>
                    <label for="field_${escapeHtml(field.name)}" class="block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">${escapeHtml(field.label)}</label>
                    <input id="field_${escapeHtml(field.name)}" data-dynamic-field="${escapeHtml(field.name)}" type="${escapeHtml(field.type || 'text')}" value="${escapeHtml(field.default || '')}" class="${inputClass}" ${field.required ? 'required' : ''}>
                </div>
            `;
            }).join('');
        }

        function toggleRecipientMode() {
            const mode = testerForm.querySelector('input[name="recipient_mode"]:checked')?.value || 'manual';
            manualRecipientBlock.classList.toggle('hidden', mode !== 'manual');
            userRecipientBlock.classList.toggle('hidden', mode !== 'user');
        }

        function resetFields() {
            renderFields();
            rawJsonOverrides.value = '';
        }

        function collectPayload() {
            const scenario = currentScenario();
            if (!scenario) {
                throw new Error('Aucun scénario sélectionné');
            }
            const payload = {};
            dynamicFields.querySelectorAll('[data-dynamic-field]').forEach(field => {
                payload[field.getAttribute('data-dynamic-field')] = field.value;
            });
            const raw = rawJsonOverrides.value.trim();
            if (raw !== '') {
                let parsed;
                try {
                    parsed = JSON.parse(raw);
                } catch (error) {
                    throw new Error('Le JSON de surcharge est invalide');
                }
                if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                    throw new Error('Le JSON de surcharge doit être un objet');
                }
                Object.assign(payload, parsed);
            }
            return payload;
        }

        function collectRecipient() {
            const mode = testerForm.querySelector('input[name="recipient_mode"]:checked')?.value || 'manual';
            if (mode === 'user') {
                if (!selectedUser || !recipientUserId.value) {
                    throw new Error('Choisissez un utilisateur destinataire');
                }
                return {
                    recipient_mode: 'user',
                    recipient_user_id: selectedUser.id,
                    recipient_email: selectedUser.email,
                    recipient_name: selectedUser.name
                };
            }

            const email = document.getElementById('recipientEmail').value.trim();
            const name = document.getElementById('recipientName').value.trim();
            if (!email) {
                throw new Error('Saisissez une adresse email de test');
            }
            return {
                recipient_mode: 'manual',
                recipient_email: email,
                recipient_name: name
            };
        }

        async function callApi(action, data) {
            const body = new URLSearchParams();
            body.append('action', action);
            body.append('scenario', scenarioSelect.value);
            Object.entries(data).forEach(([key, value]) => {
                if (value == null) {
                    return;
                }
                if (typeof value === 'object') {
                    body.append(key, JSON.stringify(value));
                } else {
                    body.append(key, String(value));
                }
            });
            const response = await fetch('notification_email_tester_api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok || !json.ok) {
                throw new Error(json.error || 'Erreur serveur');
            }
            return json;
        }

        function renderPreview(preview) {
            lastPreview = preview;
            previewSubject.textContent = preview.subject || '—';
            previewRuleRecipient.textContent = preview.rule?.recipient_team || 'requester';
            previewMessage.textContent = preview.message || '—';
            previewLink.textContent = preview.resolved_url || '—';
            previewFrame.srcdoc = preview.html || '<p style="font-family:Arial,sans-serif;padding:16px;">Aucun aperçu.</p>';
            previewContext.textContent = JSON.stringify(preview.context || {}, null, 2);
            previewChannels.innerHTML = (preview.rule?.channels || []).map(channel => `<span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700">${escapeHtml(channel)}</span>`).join('');
            ruleBadge.textContent = preview.rule?.label || 'Règle active';
        }

        testerForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                setStatus('Prévisualisation en cours...', 'info');
                const payload = collectPayload();
                const json = await callApi('preview', {
                    payload
                });
                renderPreview(json.preview);
                setStatus('Aperçu généré avec le pipeline réel.', 'success');
            } catch (error) {
                setStatus(error.message || 'Prévisualisation impossible.', 'error');
            }
        });

        sendButton.addEventListener('click', async () => {
            try {
                setStatus('Envoi du mail de test...', 'info');
                const payload = collectPayload();
                const recipient = collectRecipient();
                const json = await callApi('send', {
                    payload,
                    recipient
                });
                renderPreview(json.preview);
                setStatus(`Mail de test envoyé vers ${json.recipient_email}.`, 'success');
            } catch (error) {
                setStatus(error.message || 'Envoi impossible.', 'error');
            }
        });

        scenarioSelect.addEventListener('change', resetFields);
        resetFieldsButton.addEventListener('click', resetFields);
        testerForm.querySelectorAll('input[name="recipient_mode"]').forEach(radio => radio.addEventListener('change', toggleRecipientMode));
        if (recipientUserSearch) {
            recipientUserSearch.addEventListener('focus', () => renderUserResults(recipientUserSearch.value));
            recipientUserSearch.addEventListener('input', () => {
                if (selectedUser && recipientUserSearch.value !== `${selectedUser.name} — ${selectedUser.email}`) {
                    selectedUser = null;
                    recipientUserId.value = '';
                    recipientUserSelected.classList.add('hidden');
                }
                renderUserResults(recipientUserSearch.value);
            });
            recipientUserSearch.addEventListener('keydown', event => {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    if (recipientUserResults.classList.contains('hidden')) {
                        renderUserResults(recipientUserSearch.value);
                        return;
                    }
                    highlightedUserIndex = Math.min(userSearchResults.length - 1, highlightedUserIndex + 1);
                    updateUserHighlight();
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    highlightedUserIndex = Math.max(0, highlightedUserIndex - 1);
                    updateUserHighlight();
                } else if (event.key === 'Enter') {
                    if (!recipientUserResults.classList.contains('hidden') && userSearchResults[highlightedUserIndex]) {
                        event.preventDefault();
                        selectUser(userSearchResults[highlightedUserIndex]);
                    }
                } else if (event.key === 'Escape') {
                    closeUserResults();
                }
            });
        }
        if (recipientUserResults) {
            recipientUserResults.addEventListener('mousedown', event => {
                const button = event.target.closest('[data-user-index]');
                if (!button) return;
                event.preventDefault();
                const user = userSearchResults[Number(button.dataset.userIndex || -1)];
                if (user) {
                    selectUser(user);
                }
            });
        }
        if (recipientUserClear) {
            recipientUserClear.addEventListener('click', () => {
                selectUser(null);
                if (recipientUserSearch) {
                    recipientUserSearch.focus();
                    renderUserResults('');
                }
            });
        }
        document.addEventListener('click', event => {
            if (!userRecipientBlock.contains(event.target)) {
                closeUserResults();
            }
        });

        renderScenarioOptions();
        resetFields();
        toggleRecipientMode();
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
