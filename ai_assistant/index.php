<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/AiAssistantController.php';

$pdo = \Database::getConnection();
$controller = new AiAssistantController($pdo);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        echo json_encode($controller->handlePost($_POST, $userId, $_SERVER), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

$pageTitle = 'Assistant IA FINEA';
$activeMenu = 'ai_assistant';
$permissions = is_array($_SESSION['permissions'] ?? null) ? $_SESSION['permissions'] : [];
$viewData = $controller->viewData($userId, $permissions);
$options = $viewData['options'];
$history = $viewData['history'];
$userProfile = is_array($viewData['user_profile'] ?? null) ? $viewData['user_profile'] : [];
$existingRequests = is_array($viewData['existing_requests'] ?? null) ? $viewData['existing_requests'] : [];
$defaultVariables = array_merge([
    'nom_employe' => '',
    'poste' => '',
    'service' => '',
    'date' => date('d/m/Y'),
    'motif' => '',
    'periode' => '',
    'responsable' => '',
    'entreprise' => 'FINEA',
    'montant' => '',
    'matricule' => '',
    'request_type' => '',
    'request_title' => '',
    'heures' => '',
    'date_effective' => '',
], $userProfile);
$jsonAttribute = static function (array $payload): string {
    return htmlspecialchars(
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG),
        ENT_QUOTES,
        'UTF-8'
    );
};

$taskLabels = [
    'rediger_demande' => 'Rédiger une demande',
    'repondre_demande_explication' => 'Répondre à une demande d’explication',
    'reformuler' => 'Reformuler',
    'resumer' => 'Résumer',
    'generer_note' => 'Générer une note',
    'generer_formulaire' => 'Générer un formulaire',
];
$toneLabels = [
    'professionnel' => 'Professionnel',
    'direct' => 'Direct',
    'administratif' => 'Administratif',
    'cordial' => 'Cordial',
];

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<link rel="stylesheet" href="../assets/css/ai_assistant.css">

<main class="ai-assistant-page mx-auto max-w-[1500px] px-4 py-6">
    <div class="mb-5 rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-400">ERP FINEA</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Assistant IA FINEA</h1>
                <p class="mt-1 text-sm text-slate-500">Génération interne par modèles validés, avec amélioration IA externe optionnelle.</p>
            </div>
            <div class="rounded-2xl border <?= !empty($options['external_ai_configured']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-800' ?> px-4 py-3 text-sm font-semibold">
                <?= !empty($options['external_ai_configured']) ? 'IA externe configurée' : 'Mode interne actif' ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-5 xl:grid-cols-[minmax(0,1fr)_380px]">
        <section class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm">
            <form id="ai-assistant-form" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($viewData['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="request_id" id="ai-request-id" value="">

                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Demande existante</label>
                    <select id="ai-existing-request-select" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                        <option value="">Aucune - saisie libre</option>
                        <?php foreach ($existingRequests as $requestOption): ?>
                            <option value="<?= htmlspecialchars((string)($requestOption['source'] ?? '') . ':' . (string)($requestOption['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-context="<?= $jsonAttribute(is_array($requestOption['context'] ?? null) ? $requestOption['context'] : []) ?>">
                                <?= htmlspecialchars((string)($requestOption['label'] ?? 'Demande'), ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="ai-request-type-detected" class="mt-3 hidden rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800"></div>
                    <p class="mt-2 text-xs text-slate-500">Optionnel : choisissez une demande RH/FEB existante pour pre-remplir le contexte, puis demandez une reponse a l'assistant.</p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Module</label>
                        <select name="module" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <?php foreach ($options['modules'] as $module): ?>
                                <option value="<?= htmlspecialchars((string)$module, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$module, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Type de tâche</label>
                        <select name="task_type" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <?php foreach ($options['task_types'] as $taskType): ?>
                                <option value="<?= htmlspecialchars((string)$taskType, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($taskLabels[$taskType] ?? $taskType, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Ton</label>
                        <select name="tone" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <?php foreach ($options['tones'] as $tone): ?>
                                <option value="<?= htmlspecialchars((string)$tone, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($toneLabels[$tone] ?? $tone, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Demandeur</label>
                        <select name="role_demandeur" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            <option value="self" selected>L'employe lui-meme</option>
                            <option value="tiers">Pour une autre personne</option>
                        </select>
                    </div>
                </div>

                <details class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-slate-800">Variables dynamiques</summary>
                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <?php foreach (['nom_employe' => 'Nom employé', 'poste' => 'Poste', 'service' => 'Service', 'date' => 'Date', 'motif' => 'Motif', 'periode' => 'Période', 'responsable' => 'Responsable', 'entreprise' => 'Entreprise', 'montant' => 'Montant', 'matricule' => 'Matricule', 'request_type' => 'Type métier', 'request_title' => 'Titre demande', 'heures' => 'Heures', 'date_effective' => 'Date effective'] as $name => $label): ?>
                            <label class="block">
                                <span class="mb-1 block text-xs font-semibold text-slate-500"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                <input type="text" name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)($defaultVariables[$name] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400">
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Texte ou contexte utilisateur</label>
                    <textarea name="input_text" id="ai-input-text" required maxlength="8000" rows="7" class="w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Décrivez le besoin, collez un texte à reformuler, ou ajoutez les éléments utiles."></textarea>
                </div>

                <div class="flex flex-wrap gap-3">
                    <button type="button" id="ai-generate-button" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                        <i class="fas fa-wand-magic-sparkles mr-2"></i>Générer
                    </button>
                    <?php if (!empty($options['external_ai_configured'])): ?>
                        <button type="button" id="ai-improve-button" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                            <i class="fas fa-brain mr-2"></i>Améliorer avec IA
                        </button>
                    <?php endif; ?>
                    <button type="button" id="ai-copy-button" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-copy mr-2"></i>Copier
                    </button>
                </div>

                <div id="ai-message" class="hidden rounded-2xl border px-4 py-3 text-sm"></div>

                <div>
                    <label class="mb-1 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Résultat éditable</label>
                    <textarea id="ai-result-text" name="current_text" rows="12" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm leading-6 focus:outline-none focus:ring-2 focus:ring-emerald-400"></textarea>
                </div>

                <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_220px_160px]">
                    <input type="text" id="ai-template-title" class="rounded-2xl border border-slate-200 px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-400" placeholder="Titre du modèle validé">
                    <button type="button" id="ai-save-template-button" class="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-800 hover:bg-sky-100">Enregistrer comme modèle</button>
                    <button type="button" id="ai-feedback-button" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Feedback</button>
                </div>

                <div id="ai-feedback-panel" class="hidden rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-[160px_160px_1fr]">
                        <select id="ai-feedback-rating" class="rounded-xl border border-slate-200 px-3 py-2 text-sm">
                            <option value="">Note</option>
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Bon</option>
                            <option value="3">3 - Moyen</option>
                            <option value="2">2 - Faible</option>
                            <option value="1">1 - Mauvais</option>
                        </select>
                        <label class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                            <input type="checkbox" id="ai-feedback-accepted" class="h-4 w-4 rounded border-slate-300 text-emerald-600">
                            Accepté
                        </label>
                        <input type="text" id="ai-feedback-comment" class="rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Commentaire">
                    </div>
                    <button type="button" id="ai-submit-feedback-button" class="mt-3 rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">Soumettre feedback</button>
                </div>
            </form>
        </section>

        <aside class="space-y-5">
            <div class="rounded-[28px] border border-slate-200 bg-white p-5 shadow-sm">
                <h2 class="text-sm font-bold uppercase tracking-[0.18em] text-slate-500">Historique récent</h2>
                <div class="mt-4 space-y-3">
                    <?php if ($history): ?>
                        <?php foreach ($history as $entry): ?>
                            <button type="button" class="ai-history-item block w-full rounded-2xl border border-slate-200 bg-slate-50 p-3 text-left hover:bg-slate-100" data-text="<?= htmlspecialchars((string)($entry['final_text'] ?: $entry['generated_text']), ENT_QUOTES, 'UTF-8') ?>" data-request-id="<?= (int)$entry['id'] ?>">
                                <div class="text-xs font-semibold text-slate-500"><?= htmlspecialchars((string)$entry['module'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string)$entry['task_type'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="mt-1 line-clamp-3 text-sm text-slate-800"><?= htmlspecialchars(mb_substr((string)($entry['final_text'] ?: $entry['generated_text']), 0, 160), ENT_QUOTES, 'UTF-8') ?></div>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">Aucune génération enregistrée.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="rounded-[28px] border border-slate-200 bg-white p-5 text-sm text-slate-600 shadow-sm">
                <h2 class="font-bold text-slate-900">Confidentialité</h2>
                <p class="mt-2">Le mode interne ne sort aucune donnée de l’ERP. Le mode IA externe, s’il est activé, anonymise le texte avant appel API et ne transmet jamais la clé au navigateur.</p>
            </div>
        </aside>
    </div>
</main>

<script src="../assets/js/ai_assistant.js"></script>

</body>

</html>