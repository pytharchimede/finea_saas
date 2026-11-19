<?php
require_once __DIR__ . '/includes/module_bootstrap.php';
\AccessManager::requirePageAccess('veille_ao_preferences.php');
require_once dirname(__DIR__) . '/controllers/AoController.php';

$controller = new AoController(\Database::getConnection());
$userId = (int) ($_SESSION['user_id'] ?? 0);
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $controller->savePreferences($userId, $_POST);
    $saved = true;
}
$preferences = $controller->getPreferences($userId);
$activeDevisModule = 'ao';
$moduleTitle = 'Préférences de veille BTP';
$moduleSubtitle = 'Réglez les alertes FINEA et email selon les métiers, ouvrages et marchés recherchés par BANAMUR.';
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$presets = [
    'Bâtiment' => 'bâtiment, construction, réhabilitation, rénovation, gros œuvre, second œuvre, école, centre de santé, bâtiment administratif, clôture',
    'Routes & VRD' => 'route, voirie, VRD, bitumage, reprofilage, terrassement, chaussée, trottoir, pavage, pont, dalot',
    'Hydraulique' => 'assainissement, drainage, caniveau, adduction d eau, hydraulique, château d eau, forage, réseau d eau',
    'Électricité' => 'électrification, éclairage public, réseau électrique, poste électrique, installation électrique',
    'Maintenance' => 'maintenance bâtiment, entretien bâtiment, réparation, réfection, remise en état',
];
$recommendedKeywords = implode(', ', array_values($presets));
$recommendedExcluded = 'logiciel, licence informatique, cybersécurité, audit comptable, assurance, gardiennage, restauration, denrées alimentaires, fournitures de bureau, consommables informatiques, billet d avion, formation seule, étude sans travaux';

ob_start();
?>
<style>
    .ao-pref-shell { display:grid; gap:18px; }
    .ao-pref-notice { padding:14px 16px; border:1px solid #a7e1c8; border-radius:10px; background:#effcf6; color:#176847; font-size:13px; font-weight:750; }
    .ao-pref-panel { overflow:hidden; border:1px solid var(--devis-border); border-radius:15px; background:#fff; box-shadow:0 14px 34px rgba(23,35,72,.07); }
    .ao-pref-head { display:flex; align-items:center; justify-content:space-between; gap:18px; padding:22px 24px; border-bottom:1px solid var(--devis-border); background:#f8fafc; }
    .ao-pref-head h2 { margin:0; color:#172348; font-size:18px; font-weight:850; }
    .ao-pref-head p { margin:5px 0 0; color:#68758b; font-size:12px; }
    .ao-recommended { display:inline-flex; align-items:center; gap:7px; padding:7px 10px; border-radius:7px; background:#fff3c4; color:#6d5100; font-size:10px; font-weight:850; text-transform:uppercase; }
    .ao-pref-body { padding:24px; }
    .ao-channel-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .ao-channel { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:17px; border:1px solid #dce4ef; border-radius:10px; background:#fbfcfe; cursor:pointer; }
    .ao-channel-copy { display:flex; align-items:center; gap:12px; }
    .ao-channel-icon { display:inline-flex; width:40px; height:40px; align-items:center; justify-content:center; border-radius:9px; background:#eaf0fa; color:#31589c; }
    .ao-channel strong,.ao-channel small { display:block; }
    .ao-channel strong { color:#26334a; font-size:13px; }
    .ao-channel small { margin-top:3px; color:#77839a; font-size:10px; }
    .ao-toggle { position:relative; width:46px; height:25px; flex:0 0 auto; }
    .ao-toggle input { position:absolute; opacity:0; }
    .ao-toggle span { position:absolute; inset:0; border-radius:25px; background:#cbd5e1; transition:.2s; }
    .ao-toggle span::before { content:""; position:absolute; width:19px; height:19px; left:3px; top:3px; border-radius:50%; background:#fff; transition:.2s; box-shadow:0 2px 5px rgba(0,0,0,.18); }
    .ao-toggle input:checked + span { background:#172348; }
    .ao-toggle input:checked + span::before { transform:translateX(21px); }
    .ao-settings-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:18px; margin-top:20px; }
    .ao-field label { display:block; margin-bottom:7px; color:#34425a; font-size:11px; font-weight:850; }
    .ao-field-hint { margin-top:7px; color:#7b8799; font-size:10px; line-height:1.5; }
    .ao-score-control { display:grid; grid-template-columns:minmax(0,1fr) 76px; gap:12px; align-items:center; min-height:45px; padding:0 12px; border:1px solid #ccd6e4; border-radius:8px; background:#fff; }
    .ao-score-control input[type=range] { width:100%; accent-color:#172348; }
    .ao-score-control input[type=number] { width:64px; min-height:34px; border:0!important; border-left:1px solid #e2e8f0!important; border-radius:0!important; text-align:center; font-weight:850; }
    .ao-keyword-panel { margin-top:20px; padding:18px; border:1px solid #dce4ef; border-radius:11px; background:#f8fafc; }
    .ao-keyword-panel h3 { margin:0; color:#172348; font-size:14px; font-weight:850; }
    .ao-keyword-panel > p { margin:5px 0 14px; color:#758196; font-size:10px; }
    .ao-presets { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:13px; }
    .ao-preset { display:inline-flex; align-items:center; gap:6px; min-height:34px; padding:7px 10px; border:1px solid #d5deea; border-radius:7px; background:#fff; color:#34425a; font-size:10px; font-weight:800; }
    .ao-preset:hover { border-color:#31589c; color:#244b91; }
    .ao-keyword-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
    .ao-tag-editor { min-height:132px; padding:10px; border:1px solid #ccd6e4; border-radius:9px; background:#fff; cursor:text; transition:border-color .18s ease,box-shadow .18s ease; }
    .ao-tag-editor:focus-within { border-color:#31589c; box-shadow:0 0 0 3px rgba(49,88,156,.12); }
    .ao-tag-list { display:flex; flex-wrap:wrap; gap:7px; }
    .ao-tag { display:inline-flex; max-width:100%; align-items:center; gap:7px; min-height:30px; padding:5px 7px 5px 10px; border:1px solid #c9d7ec; border-radius:7px; background:#edf3fc; color:#244b91; font-size:10px; font-weight:800; }
    .ao-tag.is-excluded { border-color:#f0c8c8; background:#fff0f0; color:#9b2828; }
    .ao-tag-label { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .ao-tag-remove { display:inline-flex; width:20px; height:20px; align-items:center; justify-content:center; border:0; border-radius:5px; background:rgba(36,75,145,.1); color:inherit; font-size:9px; }
    .ao-tag-remove:hover { background:#244b91; color:#fff; }
    .ao-tag.is-excluded .ao-tag-remove { background:rgba(155,40,40,.1); }
    .ao-tag.is-excluded .ao-tag-remove:hover { background:#9b2828; color:#fff; }
    .ao-tag-input { width:100%; min-height:38px!important; margin-top:7px; padding:6px 4px!important; border:0!important; border-radius:0!important; background:transparent!important; box-shadow:none!important; color:#34425a; font-size:12px; outline:none; }
    .ao-tag-empty { display:none; padding:6px 2px 2px; color:#98a2b3; font-size:10px; }
    .ao-tag-list:empty + .ao-tag-empty { display:block; }
    .ao-pref-actions { display:flex; align-items:center; justify-content:space-between; gap:15px; padding:18px 24px; border-top:1px solid var(--devis-border); background:#f8fafc; }
    .ao-pref-actions p { margin:0; color:#758196; font-size:10px; }
    .ao-save { display:inline-flex; min-height:43px; align-items:center; gap:8px; padding:9px 16px; border:0; border-radius:8px; background:#fabd02; color:#172348; font-size:12px; font-weight:850; }
    body.finea-dark-mode .ao-pref-panel,body.finea-dark-mode .ao-pref-head,body.finea-dark-mode .ao-pref-actions { border-color:#2a3550; background:#172036; }
    body.finea-dark-mode .ao-pref-head h2,body.finea-dark-mode .ao-keyword-panel h3,body.finea-dark-mode .ao-channel strong { color:#fff; }
    body.finea-dark-mode .ao-channel,body.finea-dark-mode .ao-keyword-panel { border-color:#303b56; background:#111a2e; }
    body.finea-dark-mode .ao-tag-editor { border-color:#3a4763; background:#172036; }
    body.finea-dark-mode .ao-tag-input { color:#fff; }
    body.finea-dark-mode .ao-tag { border-color:#3d5680; background:#202f4c; color:#c8d9f5; }
    body.finea-dark-mode .ao-tag.is-excluded { border-color:#754444; background:#3a2429; color:#ffc9c9; }
    @media(max-width:720px){.ao-channel-grid,.ao-settings-grid,.ao-keyword-grid{grid-template-columns:1fr}.ao-pref-head,.ao-pref-actions{align-items:flex-start;flex-direction:column}.ao-save{width:100%;justify-content:center}}
</style>

<div class="ao-pref-shell">
    <?php if ($saved): ?><div class="ao-pref-notice"><i class="fa-solid fa-circle-check mr-2"></i>Préférences de veille enregistrées.</div><?php endif; ?>

    <form method="post" class="ao-pref-panel" id="aoPreferenceForm">
        <header class="ao-pref-head">
            <div>
                <h2>Profil de surveillance des marchés</h2>
                <p>Le score BTP général reste actif; vos mots-clés renforcent la priorité des métiers sélectionnés.</p>
            </div>
            <span class="ao-recommended"><i class="fa-solid fa-helmet-safety"></i> Profil BTP recommandé</span>
        </header>

        <div class="ao-pref-body">
            <div class="ao-channel-grid">
                <label class="ao-channel">
                    <span class="ao-channel-copy"><span class="ao-channel-icon"><i class="fa-solid fa-bell"></i></span><span><strong>Notifications FINEA</strong><small>Alerte visible directement dans l’application</small></span></span>
                    <span class="ao-toggle"><input type="checkbox" name="alerts_system_enabled" value="1" <?= $preferences['alerts_system_enabled'] ? 'checked' : '' ?>><span></span></span>
                </label>
                <label class="ao-channel">
                    <span class="ao-channel-copy"><span class="ao-channel-icon"><i class="fa-solid fa-envelope"></i></span><span><strong>Alertes email</strong><small>Canal indépendant des notifications FINEA</small></span></span>
                    <span class="ao-toggle"><input type="checkbox" name="alerts_email_enabled" value="1" <?= $preferences['alerts_email_enabled'] ? 'checked' : '' ?>><span></span></span>
                </label>
            </div>

            <div class="ao-settings-grid">
                <div class="ao-field">
                    <label for="minScore">Score minimum de pertinence</label>
                    <div class="ao-score-control">
                        <input type="range" id="minScoreRange" min="0" max="100" step="5" value="<?= (int) $preferences['min_score'] ?>">
                        <input type="number" id="minScore" min="0" max="100" name="min_score" value="<?= (int) $preferences['min_score'] ?>">
                    </div>
                    <div class="ao-field-hint"><strong>60 recommandé :</strong> bon équilibre entre opportunités BTP et bruit. Utilisez 75 pour une sélection plus stricte.</div>
                </div>
                <div class="ao-field">
                    <label for="frequency">Fréquence des emails</label>
                    <select id="frequency" name="frequency">
                        <?php foreach (['instant'=>'À chaque nouvelle opportunité','daily_digest'=>'Résumé quotidien','weekly_digest'=>'Résumé hebdomadaire','disabled'=>'Aucun email'] as $value=>$label): ?>
                            <option value="<?= $value ?>" <?= $preferences['frequency']===$value?'selected':'' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="ao-field-hint">Les notifications FINEA restent instantanées, quelle que soit la fréquence email.</div>
                </div>
            </div>

            <section class="ao-keyword-panel">
                <h3>Métiers et ouvrages recherchés</h3>
                <p>Ajoutez les familles qui correspondent à vos capacités. Les doublons sont automatiquement nettoyés avant enregistrement.</p>
                <div class="ao-presets">
                    <?php foreach ($presets as $label => $keywords): ?>
                        <button type="button" class="ao-preset" data-keywords="<?= $h($keywords) ?>"><i class="fa-solid fa-plus"></i><?= $h($label) ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="ao-preset" id="recommendedProfile" data-keywords="<?= $h($recommendedKeywords) ?>"><i class="fa-solid fa-wand-magic-sparkles"></i>Profil BTP complet</button>
                </div>
                <div class="ao-keyword-grid">
                    <div class="ao-field">
                        <label for="keywords">Mots-clés prioritaires</label>
                        <input type="hidden" id="keywords" name="keywords" value="<?= $h($preferences['keywords']) ?>">
                        <div class="ao-tag-editor" data-tag-editor="keywords" data-variant="priority">
                            <div class="ao-tag-list" aria-live="polite"></div>
                            <div class="ao-tag-empty">Aucun mot-clé prioritaire ajouté.</div>
                            <input type="text" class="ao-tag-input" placeholder="Saisir un mot-clé puis appuyer sur Entrée" autocomplete="off">
                        </div>
                        <div class="ao-field-hint">Chaque terme trouvé augmente le score personnel de l’appel d’offres.</div>
                    </div>
                    <div class="ao-field">
                        <label for="excludedKeywords">Exclusions</label>
                        <input type="hidden" id="excludedKeywords" name="excluded_keywords" value="<?= $h($preferences['excluded_keywords']) ?>">
                        <div class="ao-tag-editor" data-tag-editor="excludedKeywords" data-variant="excluded">
                            <div class="ao-tag-list" aria-live="polite"></div>
                            <div class="ao-tag-empty">Aucune exclusion ajoutée.</div>
                            <input type="text" class="ao-tag-input" placeholder="Saisir une exclusion puis appuyer sur Entrée" autocomplete="off">
                        </div>
                        <div class="ao-field-hint">Attention : un mot exclu écarte totalement l’avis. Évitez les termes trop généraux comme « fourniture ».</div>
                    </div>
                </div>
            </section>
        </div>

        <footer class="ao-pref-actions">
            <p><i class="fa-solid fa-circle-info mr-1"></i>Les changements s’appliqueront aux prochains appels d’offres détectés.</p>
            <button class="ao-save"><i class="fa-solid fa-floppy-disk"></i>Enregistrer mes préférences</button>
        </footer>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const keywordField = document.getElementById('keywords');
    const excludedField = document.getElementById('excludedKeywords');
    const range = document.getElementById('minScoreRange');
    const number = document.getElementById('minScore');
    const recommendedExcluded = <?= json_encode($recommendedExcluded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    function parseKeywords(value) {
        const seen = new Set();
        return String(value || '').split(/[,;\n]+/).map(v => v.trim()).filter(v => {
            const key = v.toLocaleLowerCase('fr');
            if (!key || seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function createTagEditor(hiddenField) {
        const editor = document.querySelector('[data-tag-editor="' + hiddenField.id + '"]');
        const list = editor.querySelector('.ao-tag-list');
        const input = editor.querySelector('.ao-tag-input');
        const excluded = editor.dataset.variant === 'excluded';
        let values = parseKeywords(hiddenField.value);

        function sync() {
            hiddenField.value = values.join(', ');
        }

        function render() {
            list.innerHTML = '';
            values.forEach((value, index) => {
                const badge = document.createElement('span');
                badge.className = 'ao-tag' + (excluded ? ' is-excluded' : '');

                const label = document.createElement('span');
                label.className = 'ao-tag-label';
                label.textContent = value;
                badge.appendChild(label);

                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'ao-tag-remove';
                remove.title = 'Supprimer ' + value;
                remove.setAttribute('aria-label', 'Supprimer ' + value);
                remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                remove.addEventListener('click', function () {
                    values.splice(index, 1);
                    sync();
                    render();
                    input.focus();
                });
                badge.appendChild(remove);
                list.appendChild(badge);
            });
            sync();
        }

        function add(rawValue) {
            parseKeywords(rawValue).forEach(value => {
                if (!values.some(current => current.toLocaleLowerCase('fr') === value.toLocaleLowerCase('fr'))) {
                    values.push(value);
                }
            });
            input.value = '';
            render();
        }

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
                event.preventDefault();
                add(input.value);
            } else if (event.key === 'Backspace' && input.value === '' && values.length) {
                values.pop();
                render();
            }
        });
        input.addEventListener('blur', function () {
            if (input.value.trim()) add(input.value);
        });
        editor.addEventListener('click', function (event) {
            if (!event.target.closest('.ao-tag-remove')) input.focus();
        });

        render();
        return { add, sync };
    }

    const priorityEditor = createTagEditor(keywordField);
    const excludedEditor = createTagEditor(excludedField);

    document.querySelectorAll('.ao-preset').forEach(button => {
        button.addEventListener('click', function () {
            priorityEditor.add(this.dataset.keywords);
            if (this.id === 'recommendedProfile' && !excludedField.value.trim()) {
                excludedEditor.add(recommendedExcluded);
            }
        });
    });
    range.addEventListener('input', () => number.value = range.value);
    number.addEventListener('input', () => range.value = Math.max(0, Math.min(100, number.value || 0)));
    document.getElementById('aoPreferenceForm').addEventListener('submit', function () {
        priorityEditor.sync();
        excludedEditor.sync();
    });
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/module_layout.php';
