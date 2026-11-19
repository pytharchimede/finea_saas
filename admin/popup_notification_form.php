<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controller/PopupNotificationController.php';

$pdo = Database::getConnection();
$controller = new PopupNotificationController($pdo);
$service = $controller->service();
$service->autoCreateTablesIfMissing();

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function currentAdminId(): int
{
    return (int)($_SESSION['user_id'] ?? $_SESSION['id_user'] ?? $_SESSION['admin_id'] ?? 0);
}

function toDatetimeLocal(?string $value): string
{
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d\TH:i', $timestamp) : '';
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $savedId = $service->saveFromPost($_POST, currentAdminId());
        header('Location: popup_notifications.php?saved=1&id=' . $savedId);
        exit;
    }

    $notification = $id > 0 ? $service->getNotification($id) : null;

    if ($id > 0 && !$notification) {
        throw new RuntimeException('Notification introuvable.');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $notification = $_POST;
}

$options = $service->getFormOptions();

$typeLabels = $options['types'] ?? [];
$priorityLabels = $options['priorities'] ?? [];
$scopeLabels = $options['targetScopes'] ?? [];
$users = $options['users'] ?? [];
$services = $options['services'] ?? [];
$modules = $options['modules'] ?? [];

$targets = $notification['targets'] ?? [];

$selectedUsers = $notification['target_users'] ?? [];
$selectedServices = $notification['target_services'] ?? [];
$selectedModules = $notification['target_modules'] ?? [];

if (!$selectedUsers && !$selectedServices && !$selectedModules) {
    foreach ($targets as $target) {
        $targetType = (string)($target['target_type'] ?? '');
        $targetValue = (string)($target['target_value'] ?? '');

        if ($targetType === 'user') {
            $selectedUsers[] = $targetValue;
        }

        if ($targetType === 'service') {
            $selectedServices[] = $targetValue;
        }

        if ($targetType === 'module_access') {
            $selectedModules[] = $targetValue;
        }
    }
}

$title = $notification['title'] ?? '';
$type = $notification['type'] ?? 'information';
$shortMessage = $notification['short_message'] ?? '';
$body = $notification['body'] ?? '';
$moduleKey = $notification['module_key'] ?? '';
$priority = $notification['priority'] ?? 'normal';
$isActive = (int)($notification['is_active'] ?? 0) === 1;
$startsAt = toDatetimeLocal($notification['starts_at'] ?? null);
$endsAt = toDatetimeLocal($notification['ends_at'] ?? null);
$targetScope = $notification['target_scope'] ?? 'all';
$requiresReadValidation = (int)($notification['requires_read_validation'] ?? 0) === 1;
$questions = $notification['questions'] ?? [];


$actions = $notification['actions'] ?? [];

if (empty($actions)) {
    $actions = [
        [
            'label' => 'J’ai compris',
            'action_type' => 'ack',
            'action_value' => '',
            'is_primary' => 1,
        ]
    ];
}

$pageTitle = $id > 0 ? 'Modifier la notification' : 'Créer une notification';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<style>
    .finea-token-select {
        position: relative;
    }

    .finea-token-box {
        min-height: 52px;
        border: 1px solid #d1d5db;
        border-radius: 0.75rem;
        padding: 0.5rem;
        background: #fff;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.4rem;
    }

    .finea-token-box:focus-within {
        border-color: #6366f1;
        box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.25);
    }

    .finea-token-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
    }

    .finea-token-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        background: #eef2ff;
        color: #3730a3;
        border: 1px solid #c7d2fe;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .finea-token-remove {
        border: none;
        background: transparent;
        color: #4338ca;
        cursor: pointer;
        font-size: 0.85rem;
        line-height: 1;
    }

    .finea-token-search {
        flex: 1;
        min-width: 180px;
        border: none;
        outline: none;
        padding: 0.45rem;
        font-size: 0.9rem;
    }

    .finea-token-options {
        display: none;
        position: absolute;
        z-index: 50;
        width: 100%;
        max-height: 260px;
        overflow-y: auto;
        margin-top: 0.35rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 0.9rem;
        box-shadow: 0 20px 45px rgba(15, 23, 42, 0.14);
        padding: 0.35rem;
    }

    .finea-token-select.is-open .finea-token-options {
        display: block;
    }

    .finea-token-option {
        width: 100%;
        text-align: left;
        padding: 0.75rem 0.85rem;
        border: none;
        background: transparent;
        border-radius: 0.65rem;
        cursor: pointer;
        font-size: 0.9rem;
        color: #334155;
    }

    .finea-token-option:hover {
        background: #f8fafc;
    }

    .finea-token-option.is-selected {
        display: none;
    }
</style>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">

        <div class="mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div>
                    <div class="inline-flex items-center px-4 py-2 rounded-full bg-indigo-100 text-indigo-700 text-sm font-semibold mb-4">
                        <i class="fas fa-bullhorn mr-2"></i>
                        Pop-up nouveautés
                    </div>

                    <h1 class="text-4xl lg:text-5xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                        <?= $id > 0 ? 'Modifier la notification' : 'Créer une notification' ?>
                    </h1>

                    <p class="text-gray-600 mt-3 text-lg max-w-3xl">
                        Préparez une annonce ciblée qui s’affichera sous forme de pop-up aux utilisateurs concernés.
                    </p>
                </div>

                <a href="popup_notifications.php"
                    class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm hover:shadow-md">
                    <i class="fas fa-arrow-left mr-2 text-slate-500"></i>
                    Retour à la liste
                </a>
            </div>
        </div>

        <?php if ($error !== ''): ?>
            <div class="mb-6 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= h($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" id="popup-notification-form" class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <input type="hidden" name="id" value="<?= (int)$id ?>">

            <div class="lg:col-span-2 space-y-6">

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-pen-nib text-indigo-600 mr-2"></i>
                        Contenu de la notification
                    </h2>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Titre</label>
                            <input type="text" name="title" value="<?= h($title) ?>" required
                                class="w-full border border-gray-300 rounded-xl px-4 py-3 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                                placeholder="Ex : Nouveau module Assistant IA disponible">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Type</label>
                                <select name="type" class="w-full border border-gray-300 rounded-xl px-4 py-3">
                                    <?php foreach ($typeLabels as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= $type === $value ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-semibold text-gray-700 mb-2">Priorité</label>
                                <select name="priority" class="w-full border border-gray-300 rounded-xl px-4 py-3">
                                    <?php foreach ($priorityLabels as $value => $label): ?>
                                        <option value="<?= h($value) ?>" <?= $priority === $value ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Message court</label>
                            <input type="text" name="short_message" value="<?= h($shortMessage) ?>" maxlength="500"
                                class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                placeholder="Résumé court affiché dans la pop-up">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Description détaillée</label>
                            <textarea name="body" rows="8" required
                                class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                placeholder="Expliquez clairement la nouveauté, la correction ou la mise à jour..."><?= h($body) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-users-viewfinder text-purple-600 mr-2"></i>
                        Ciblage
                    </h2>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Audience</label>
                            <select name="target_scope" id="target_scope"
                                class="w-full border border-gray-300 rounded-xl px-4 py-3">
                                <?php foreach ($scopeLabels as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= $targetScope === $value ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="target-block" data-target-block="users">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Utilisateurs concernés</label>

                            <div class="finea-token-select" data-name="target_users[]" data-single="0">
                                <div class="finea-token-box">
                                    <div class="finea-token-list"></div>
                                    <input type="text" class="finea-token-search" placeholder="Rechercher un utilisateur...">
                                </div>

                                <div class="finea-token-options">
                                    <?php foreach ($users as $user): ?>
                                        <?php $userId = (string)($user['id'] ?? ''); ?>
                                        <button type="button"
                                            class="finea-token-option"
                                            data-value="<?= h($userId) ?>"
                                            data-label="<?= h($user['display_label'] ?? ('Utilisateur #' . $userId)) ?>"
                                            data-selected="<?= in_array($userId, $selectedUsers, true) ? '1' : '0' ?>">
                                            <?= h($user['display_label'] ?? ('Utilisateur #' . $userId)) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="target-block" data-target-block="services">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Services concernés</label>

                            <div class="finea-token-select" data-name="target_services[]" data-single="0">
                                <div class="finea-token-box">
                                    <div class="finea-token-list"></div>
                                    <input type="text" class="finea-token-search" placeholder="Rechercher un service...">
                                </div>

                                <div class="finea-token-options">
                                    <?php foreach ($services as $serviceRow): ?>
                                        <?php $serviceValue = (string)($serviceRow['value'] ?? ''); ?>
                                        <button type="button"
                                            class="finea-token-option"
                                            data-value="<?= h($serviceValue) ?>"
                                            data-label="<?= h($serviceRow['label'] ?? $serviceValue) ?>"
                                            data-selected="<?= in_array($serviceValue, $selectedServices, true) ? '1' : '0' ?>">
                                            <?= h($serviceRow['label'] ?? $serviceValue) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <input type="text" name="target_service_manual"
                                class="w-full border border-gray-300 rounded-xl px-4 py-3 mt-3"
                                placeholder="Ou saisir manuellement un service si absent de la liste">
                        </div>

                        <div class="target-block" data-target-block="module_access">
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Modules concernés</label>

                            <div class="finea-token-select" data-name="target_modules[]" data-single="0">
                                <div class="finea-token-box">
                                    <div class="finea-token-list"></div>
                                    <input type="text" class="finea-token-search" placeholder="Rechercher un module...">
                                </div>

                                <div class="finea-token-options">
                                    <?php foreach ($modules as $module): ?>
                                        <?php
                                        $key = (string)($module['module_key'] ?? $module['key'] ?? '');
                                        $label = (string)($module['title'] ?? $module['label'] ?? $module['name'] ?? $key);
                                        ?>
                                        <?php if ($key !== ''): ?>
                                            <button type="button"
                                                class="finea-token-option"
                                                data-value="<?= h($key) ?>"
                                                data-label="<?= h($label . ' — ' . $key) ?>"
                                                data-selected="<?= in_array($key, $selectedModules, true) ? '1' : '0' ?>">
                                                <?= h($label) ?> — <span><?= h($key) ?></span>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <p class="text-xs text-gray-500 mt-2">
                                Seuls les utilisateurs ayant accès au module choisi verront la pop-up.
                            </p>
                        </div>
                    </div>
                </div>

            </div>

            <div class="space-y-6">

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-5">
                        <i class="fas fa-sliders text-indigo-600 mr-2"></i>
                        Publication
                    </h2>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Module concerné optionnel</label>

                            <div class="finea-token-select" data-name="module_key" data-single="1">
                                <input type="hidden" name="module_key" value="<?= h($moduleKey) ?>">

                                <div class="finea-token-box">
                                    <div class="finea-token-list"></div>
                                    <input type="text" class="finea-token-search" placeholder="Rechercher un module...">
                                </div>

                                <div class="finea-token-options">
                                    <?php foreach ($modules as $module): ?>
                                        <?php
                                        $key = (string)($module['module_key'] ?? $module['key'] ?? '');
                                        $label = (string)($module['title'] ?? $module['label'] ?? $module['name'] ?? $key);
                                        ?>
                                        <?php if ($key !== ''): ?>
                                            <button type="button"
                                                class="finea-token-option"
                                                data-value="<?= h($key) ?>"
                                                data-label="<?= h($label . ' — ' . $key) ?>">
                                                <?= h($label) ?> — <span><?= h($key) ?></span>
                                            </button>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date de début</label>
                            <input type="datetime-local" name="starts_at" value="<?= h($startsAt) ?>"
                                class="w-full border border-gray-300 rounded-xl px-4 py-3">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date de fin</label>
                            <input type="datetime-local" name="ends_at" value="<?= h($endsAt) ?>"
                                class="w-full border border-gray-300 rounded-xl px-4 py-3">
                        </div>

                        <label class="flex items-center gap-3 p-4 rounded-xl bg-indigo-50 border border-indigo-100 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>
                                class="w-5 h-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span>
                                <span class="block font-semibold text-gray-800">Notification active</span>
                                <span class="block text-sm text-gray-500">La pop-up pourra être affichée aux utilisateurs concernés.</span>
                            </span>
                        </label>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <div class="flex items-start justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-clipboard-check text-emerald-600 mr-2"></i>
                                Validation obligatoire de lecture
                            </h2>
                            <p class="text-sm text-gray-500 mt-1">
                                Ajoutez un QCM court pour vérifier que le texte a été lu avant d’afficher les boutons.
                            </p>
                        </div>
                    </div>

                    <label class="flex items-center gap-3 p-4 rounded-xl bg-emerald-50 border border-emerald-100 cursor-pointer mb-5">
                        <input type="checkbox" id="requires_read_validation" name="requires_read_validation" value="1" <?= $requiresReadValidation ? 'checked' : '' ?>
                            class="w-5 h-5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span>
                            <span class="block font-semibold text-gray-800">Exiger une validation de lecture par QCM</span>
                            <span class="block text-sm text-gray-500">Les actions restent masquées tant que l’utilisateur n’a pas répondu correctement.</span>
                        </span>
                    </label>

                    <div id="read-validation-builder" class="<?= $requiresReadValidation ? '' : 'hidden' ?>">
                        <div class="flex items-center justify-between gap-3 mb-4">
                            <p class="text-sm text-gray-600">
                                1 à 3 questions maximum. Chaque question doit avoir au moins 2 choix et une seule bonne réponse.
                            </p>

                            <button type="button" id="add-read-question"
                                class="inline-flex items-center px-4 py-2 rounded-xl bg-emerald-50 text-emerald-700 border border-emerald-100 hover:bg-emerald-100 font-semibold text-sm">
                                <i class="fas fa-plus mr-2"></i>
                                Ajouter une question
                            </button>
                        </div>

                        <div id="read-questions-wrapper" class="space-y-4">
                            <?php if (empty($questions)): ?>
                                <?php $questions = [['question_text' => '', 'choices' => [['choice_text' => '', 'is_correct' => 1], ['choice_text' => '', 'is_correct' => 0]]]]; ?>
                            <?php endif; ?>

                            <?php foreach ($questions as $qIndex => $question): ?>
                                <?php
                                $choices = $question['choices'] ?? [];
                                if (count($choices) < 2) {
                                    $choices = array_pad($choices, 2, ['choice_text' => '', 'is_correct' => 0]);
                                }
                                ?>
                                <div class="read-question-row p-4 rounded-2xl border border-emerald-100 bg-emerald-50/50">
                                    <div class="flex items-start justify-between gap-3 mb-4">
                                        <div class="flex-1">
                                            <label class="block text-sm font-semibold text-gray-700 mb-2">Question</label>
                                            <input type="text"
                                                name="questions[<?= (int)$qIndex ?>][question_text]"
                                                value="<?= h($question['question_text'] ?? '') ?>"
                                                class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                                placeholder="Ex : À quoi sert ce nouveau module ?">
                                        </div>

                                        <button type="button"
                                            class="remove-read-question mt-7 inline-flex items-center justify-center w-11 h-11 rounded-xl bg-red-50 text-red-700 hover:bg-red-100"
                                            title="Supprimer la question">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>

                                    <div class="read-choices-wrapper space-y-3">
                                        <?php foreach ($choices as $cIndex => $choice): ?>
                                            <div class="read-choice-row grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                                                <div class="md:col-span-9">
                                                    <input type="text"
                                                        name="questions[<?= (int)$qIndex ?>][choices][<?= (int)$cIndex ?>][choice_text]"
                                                        value="<?= h($choice['choice_text'] ?? '') ?>"
                                                        class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                                        placeholder="Choix de réponse">
                                                </div>

                                                <div class="md:col-span-2">
                                                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                                                        <input type="radio"
                                                            name="questions[<?= (int)$qIndex ?>][correct_choice]"
                                                            value="<?= (int)$cIndex ?>"
                                                            <?= !empty($choice['is_correct']) ? 'checked' : '' ?>
                                                            class="read-correct-radio text-emerald-600 focus:ring-emerald-500">
                                                        Bonne réponse
                                                    </label>
                                                    <input type="hidden"
                                                        name="questions[<?= (int)$qIndex ?>][choices][<?= (int)$cIndex ?>][is_correct]"
                                                        value="<?= !empty($choice['is_correct']) ? '1' : '0' ?>"
                                                        class="read-choice-correct-hidden">
                                                </div>

                                                <div class="md:col-span-1 flex justify-end">
                                                    <button type="button"
                                                        class="remove-read-choice inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white text-red-600 border border-red-100 hover:bg-red-50"
                                                        title="Supprimer le choix">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <button type="button"
                                        class="add-read-choice mt-3 inline-flex items-center px-3 py-2 rounded-xl bg-white text-emerald-700 border border-emerald-100 hover:bg-emerald-50 font-semibold text-sm">
                                        <i class="fas fa-plus mr-2"></i>
                                        Ajouter un choix
                                    </button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">
                        <i class="fas fa-circle-info text-purple-600 mr-2"></i>
                        Rappel
                    </h2>

                    <p class="text-sm text-gray-600 leading-relaxed">
                        L’ouverture enregistre la lecture, mais la notification reste bloquante tant que
                        l’utilisateur n’a pas cliqué sur une réponse. Aucun bouton de fermeture ne sera affiché.
                    </p>
                </div>

                <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6">
                    <div class="flex items-start justify-between gap-4 mb-6">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">
                                <i class="fas fa-hand-pointer text-indigo-600 mr-2"></i>
                                Boutons / interactions
                            </h2>
                            <p class="text-sm text-gray-500 mt-1">
                                Au moins une action est obligatoire. Un sondage doit proposer au moins deux choix.
                            </p>
                        </div>

                        <button type="button"
                            id="add-popup-action"
                            class="inline-flex items-center px-4 py-2 rounded-xl bg-indigo-50 text-indigo-700 border border-indigo-100 hover:bg-indigo-100 font-semibold text-sm">
                            <i class="fas fa-plus mr-2"></i>
                            Ajouter
                        </button>
                    </div>

                    <div id="popup-actions-wrapper" class="space-y-4">
                        <?php foreach ($actions as $index => $action): ?>
                            <div class="popup-action-row p-4 rounded-2xl border border-gray-200 bg-gray-50">
                                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                                    <div class="md:col-span-4">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Libellé du bouton</label>
                                        <input type="text"
                                            name="actions[<?= (int)$index ?>][label]"
                                            value="<?= h($action['label'] ?? '') ?>"
                                            class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                            placeholder="Ex : J’ai compris">
                                    </div>

                                    <div class="md:col-span-3">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Type d’action</label>
                                        <select name="actions[<?= (int)$index ?>][action_type]"
                                            class="popup-action-type w-full border border-gray-300 rounded-xl px-4 py-3">
                                            <option value="ack" <?= ($action['action_type'] ?? '') === 'ack' ? 'selected' : '' ?>>Accusé simple</option>
                                            <option value="link" <?= ($action['action_type'] ?? '') === 'link' ? 'selected' : '' ?>>Lien / ouvrir une page</option>
                                            <option value="survey_choice" <?= ($action['action_type'] ?? '') === 'survey_choice' ? 'selected' : '' ?>>Choix de sondage</option>
                                            <option value="custom" <?= ($action['action_type'] ?? '') === 'custom' ? 'selected' : '' ?>>Action personnalisée</option>
                                        </select>
                                    </div>

                                    <div class="md:col-span-4">
                                        <label class="block text-sm font-semibold text-gray-700 mb-2">Valeur / lien / réponse</label>
                                        <input type="text"
                                            name="actions[<?= (int)$index ?>][action_value]"
                                            value="<?= h($action['action_value'] ?? '') ?>"
                                            class="w-full border border-gray-300 rounded-xl px-4 py-3"
                                            placeholder="Ex : /banamur_project_app/ai_assistant/index.php ou tres_utile">
                                    </div>

                                    <div class="md:col-span-1 flex items-center justify-end gap-2">
                                        <label class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white border border-gray-300 cursor-pointer"
                                            title="Bouton principal">
                                            <input type="checkbox"
                                                name="actions[<?= (int)$index ?>][is_primary]"
                                                value="1"
                                                <?= !empty($action['is_primary']) ? 'checked' : '' ?>
                                                class="sr-only popup-primary-checkbox">
                                            <i class="fas fa-star text-gray-300 popup-primary-icon"></i>
                                        </label>

                                        <button type="button"
                                            class="remove-popup-action inline-flex items-center justify-center w-11 h-11 rounded-xl bg-red-50 text-red-700 hover:bg-red-100">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <p class="text-xs text-gray-500 mt-4">
                        Pour un sondage, ajoutez plusieurs lignes de type “Choix de sondage”.
                        Exemple : Très utile, Moyennement utile, Pas utile.
                    </p>
                </div>

                <div class="flex flex-col gap-3">
                    <button type="submit"
                        class="inline-flex items-center justify-center px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 transition-all shadow-lg font-semibold">
                        <i class="fas fa-save mr-2"></i>
                        Enregistrer
                    </button>

                    <a href="popup_notifications.php"
                        class="inline-flex items-center justify-center px-6 py-4 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm font-semibold">
                        Annuler
                    </a>
                </div>

            </div>
        </form>

    </div>
</div>

<script>
    const actionsWrapper = document.getElementById('popup-actions-wrapper');
    const addActionBtn = document.getElementById('add-popup-action');
    const popupNotificationForm = document.getElementById('popup-notification-form');
    const readValidationToggle = document.getElementById('requires_read_validation');
    const readValidationBuilder = document.getElementById('read-validation-builder');
    const readQuestionsWrapper = document.getElementById('read-questions-wrapper');
    const addReadQuestionBtn = document.getElementById('add-read-question');


    function refreshReadQuestionIndexes() {
        if (!readQuestionsWrapper) return;

        readQuestionsWrapper.querySelectorAll('.read-question-row').forEach(function(questionRow, qIndex) {
            questionRow.querySelectorAll('input').forEach(function(field) {
                field.name = field.name
                    .replace(/questions\[\d+\]/, 'questions[' + qIndex + ']')
                    .replace(/choices\]\[\d+\]/, function(match) {
                        return match;
                    });
            });

            questionRow.querySelectorAll('.read-choice-row').forEach(function(choiceRow, cIndex) {
                choiceRow.querySelectorAll('input').forEach(function(field) {
                    field.name = field.name
                        .replace(/questions\[\d+\]/, 'questions[' + qIndex + ']')
                        .replace(/choices\]\[\d+\]/, 'choices][' + cIndex + ']');

                    if (field.classList.contains('read-correct-radio')) {
                        field.name = 'questions[' + qIndex + '][correct_choice]';
                        field.value = String(cIndex);
                    }
                });
            });

            refreshQuestionCorrectHidden(questionRow);
        });
    }

    function refreshQuestionCorrectHidden(questionRow) {
        const radios = questionRow.querySelectorAll('.read-correct-radio');
        radios.forEach(function(radio) {
            const row = radio.closest('.read-choice-row');
            const hidden = row ? row.querySelector('.read-choice-correct-hidden') : null;
            if (hidden) {
                hidden.value = radio.checked ? '1' : '0';
            }
        });
    }

    function createReadQuestionRow(index) {
        const row = document.createElement('div');
        row.className = 'read-question-row p-4 rounded-2xl border border-emerald-100 bg-emerald-50/50';
        row.innerHTML = `
            <div class="flex items-start justify-between gap-3 mb-4">
                <div class="flex-1">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Question</label>
                    <input type="text" name="questions[${index}][question_text]"
                           class="w-full border border-gray-300 rounded-xl px-4 py-3"
                           placeholder="Ex : À quoi sert ce nouveau module ?">
                </div>

                <button type="button"
                        class="remove-read-question mt-7 inline-flex items-center justify-center w-11 h-11 rounded-xl bg-red-50 text-red-700 hover:bg-red-100"
                        title="Supprimer la question">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="read-choices-wrapper space-y-3">
                ${createReadChoiceHtml(index, 0, true)}
                ${createReadChoiceHtml(index, 1, false)}
            </div>

            <button type="button"
                    class="add-read-choice mt-3 inline-flex items-center px-3 py-2 rounded-xl bg-white text-emerald-700 border border-emerald-100 hover:bg-emerald-50 font-semibold text-sm">
                <i class="fas fa-plus mr-2"></i>
                Ajouter un choix
            </button>
        `;
        return row;
    }

    function createReadChoiceHtml(qIndex, cIndex, checked) {
        return `
            <div class="read-choice-row grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                <div class="md:col-span-9">
                    <input type="text"
                           name="questions[${qIndex}][choices][${cIndex}][choice_text]"
                           class="w-full border border-gray-300 rounded-xl px-4 py-3"
                           placeholder="Choix de réponse">
                </div>
                <div class="md:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-gray-700">
                        <input type="radio"
                               name="questions[${qIndex}][correct_choice]"
                               value="${cIndex}"
                               ${checked ? 'checked' : ''}
                               class="read-correct-radio text-emerald-600 focus:ring-emerald-500">
                        Bonne réponse
                    </label>
                    <input type="hidden"
                           name="questions[${qIndex}][choices][${cIndex}][is_correct]"
                           value="${checked ? '1' : '0'}"
                           class="read-choice-correct-hidden">
                </div>
                <div class="md:col-span-1 flex justify-end">
                    <button type="button"
                            class="remove-read-choice inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white text-red-600 border border-red-100 hover:bg-red-50"
                            title="Supprimer le choix">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    }

    function refreshReadValidationVisibility() {
        if (!readValidationToggle || !readValidationBuilder) return;
        readValidationBuilder.classList.toggle('hidden', !readValidationToggle.checked);
    }

    function refreshActionIndexes() {
        if (!actionsWrapper) return;

        actionsWrapper.querySelectorAll('.popup-action-row').forEach(function(row, index) {
            row.querySelectorAll('input, select').forEach(function(field) {
                field.name = field.name.replace(/actions\[\d+\]/, 'actions[' + index + ']');
            });
        });
    }

    function refreshPrimaryIcons() {
        document.querySelectorAll('.popup-primary-checkbox').forEach(function(checkbox) {
            const icon = checkbox.closest('label').querySelector('.popup-primary-icon');

            if (checkbox.checked) {
                icon.classList.remove('text-gray-300');
                icon.classList.add('text-amber-500');
            } else {
                icon.classList.remove('text-amber-500');
                icon.classList.add('text-gray-300');
            }
        });
    }


    if (readValidationToggle) {
        readValidationToggle.addEventListener('change', refreshReadValidationVisibility);
        refreshReadValidationVisibility();
    }

    if (addReadQuestionBtn && readQuestionsWrapper) {
        addReadQuestionBtn.addEventListener('click', function() {
            const count = readQuestionsWrapper.querySelectorAll('.read-question-row').length;
            if (count >= 3) {
                alert('Maximum 3 questions de validation.');
                return;
            }
            readQuestionsWrapper.appendChild(createReadQuestionRow(count));
            refreshReadQuestionIndexes();
        });

        readQuestionsWrapper.addEventListener('click', function(event) {
            const removeQuestion = event.target.closest('.remove-read-question');
            if (removeQuestion) {
                const rows = readQuestionsWrapper.querySelectorAll('.read-question-row');
                if (rows.length <= 1) {
                    alert('Gardez au moins une question ou désactivez la validation de lecture.');
                    return;
                }
                removeQuestion.closest('.read-question-row').remove();
                refreshReadQuestionIndexes();
                return;
            }

            const addChoice = event.target.closest('.add-read-choice');
            if (addChoice) {
                const questionRow = addChoice.closest('.read-question-row');
                const choicesWrapper = questionRow.querySelector('.read-choices-wrapper');
                const qIndex = Array.from(readQuestionsWrapper.querySelectorAll('.read-question-row')).indexOf(questionRow);
                const cIndex = choicesWrapper.querySelectorAll('.read-choice-row').length;
                choicesWrapper.insertAdjacentHTML('beforeend', createReadChoiceHtml(qIndex, cIndex, false));
                refreshReadQuestionIndexes();
                return;
            }

            const removeChoice = event.target.closest('.remove-read-choice');
            if (removeChoice) {
                const questionRow = removeChoice.closest('.read-question-row');
                const choices = questionRow.querySelectorAll('.read-choice-row');
                if (choices.length <= 2) {
                    alert('Chaque question doit garder au moins 2 choix.');
                    return;
                }
                removeChoice.closest('.read-choice-row').remove();
                const firstRadio = questionRow.querySelector('.read-correct-radio');
                if (firstRadio && !questionRow.querySelector('.read-correct-radio:checked')) {
                    firstRadio.checked = true;
                }
                refreshReadQuestionIndexes();
            }
        });

        readQuestionsWrapper.addEventListener('change', function(event) {
            if (event.target.classList.contains('read-correct-radio')) {
                const questionRow = event.target.closest('.read-question-row');
                refreshQuestionCorrectHidden(questionRow);
            }
        });

        refreshReadQuestionIndexes();
    }

    if (addActionBtn && actionsWrapper) {
        addActionBtn.addEventListener('click', function() {
            const index = actionsWrapper.querySelectorAll('.popup-action-row').length;

            const row = document.createElement('div');
            row.className = 'popup-action-row p-4 rounded-2xl border border-gray-200 bg-gray-50';
            row.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Libellé du bouton</label>
                    <input type="text" name="actions[${index}][label]"
                           class="w-full border border-gray-300 rounded-xl px-4 py-3"
                           placeholder="Ex : Très utile">
                </div>

                <div class="md:col-span-3">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Type d’action</label>
                    <select name="actions[${index}][action_type]"
                            class="popup-action-type w-full border border-gray-300 rounded-xl px-4 py-3">
                        <option value="ack">Accusé simple</option>
                        <option value="link">Lien / ouvrir une page</option>
                        <option value="survey_choice">Choix de sondage</option>
                        <option value="custom">Action personnalisée</option>
                    </select>
                </div>

                <div class="md:col-span-4">
                    <label class="block text-sm font-semibold text-gray-700 mb-2">Valeur / lien / réponse</label>
                    <input type="text" name="actions[${index}][action_value]"
                           class="w-full border border-gray-300 rounded-xl px-4 py-3"
                           placeholder="Ex : tres_utile">
                </div>

                <div class="md:col-span-1 flex items-center justify-end gap-2">
                    <label class="inline-flex items-center justify-center w-11 h-11 rounded-xl bg-white border border-gray-300 cursor-pointer"
                           title="Bouton principal">
                        <input type="checkbox" name="actions[${index}][is_primary]" value="1"
                               class="sr-only popup-primary-checkbox">
                        <i class="fas fa-star text-gray-300 popup-primary-icon"></i>
                    </label>

                    <button type="button"
                            class="remove-popup-action inline-flex items-center justify-center w-11 h-11 rounded-xl bg-red-50 text-red-700 hover:bg-red-100">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        `;

            actionsWrapper.appendChild(row);
            refreshActionIndexes();
            refreshPrimaryIcons();
        });

        actionsWrapper.addEventListener('click', function(event) {
            const removeBtn = event.target.closest('.remove-popup-action');

            if (removeBtn) {
                const rows = actionsWrapper.querySelectorAll('.popup-action-row');

                if (rows.length <= 1) {
                    alert('Gardez au moins une action.');
                    return;
                }

                removeBtn.closest('.popup-action-row').remove();
                refreshActionIndexes();
            }
        });

        actionsWrapper.addEventListener('change', function(event) {
            if (event.target.classList.contains('popup-primary-checkbox')) {
                refreshPrimaryIcons();
            }
        });

        refreshPrimaryIcons();
    }

    if (popupNotificationForm && actionsWrapper) {
        popupNotificationForm.addEventListener('submit', function(event) {
            if (readValidationToggle && readValidationToggle.checked && readQuestionsWrapper) {
                const questionRows = Array.from(readQuestionsWrapper.querySelectorAll('.read-question-row'));
                if (questionRows.length < 1 || questionRows.length > 3) {
                    event.preventDefault();
                    alert('La validation de lecture doit contenir entre 1 et 3 questions.');
                    return;
                }

                for (const questionRow of questionRows) {
                    const questionInput = questionRow.querySelector('input[name*="[question_text]"]');
                    const filledChoices = Array.from(questionRow.querySelectorAll('.read-choice-row')).filter(function(choiceRow) {
                        const input = choiceRow.querySelector('input[name*="[choice_text]"]');
                        return input && input.value.trim() !== '';
                    });

                    if (!questionInput || questionInput.value.trim() === '') {
                        event.preventDefault();
                        alert('Renseignez le texte de chaque question de validation.');
                        return;
                    }

                    if (filledChoices.length < 2) {
                        event.preventDefault();
                        alert('Chaque question de validation doit avoir au moins 2 choix remplis.');
                        return;
                    }

                    if (!questionRow.querySelector('.read-correct-radio:checked')) {
                        event.preventDefault();
                        alert('Chaque question doit avoir une bonne réponse.');
                        return;
                    }

                    refreshQuestionCorrectHidden(questionRow);
                }
            }

            const validRows = Array.from(actionsWrapper.querySelectorAll('.popup-action-row')).filter(function(row) {
                const label = row.querySelector('input[name*="[label]"]');
                return label && label.value.trim() !== '';
            });

            if (validRows.length === 0) {
                event.preventDefault();
                alert('Ajoutez au moins un bouton de réponse avec un libellé.');
                return;
            }

            const typeSelect = popupNotificationForm.querySelector('select[name="type"]');
            if (typeSelect && typeSelect.value === 'sondage') {
                const surveyChoices = validRows.filter(function(row) {
                    const actionType = row.querySelector('.popup-action-type');
                    return actionType && actionType.value === 'survey_choice';
                });

                if (surveyChoices.length < 2) {
                    event.preventDefault();
                    alert('Un sondage doit contenir au moins deux choix de réponse.');
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const scopeSelect = document.getElementById('target_scope');
        const blocks = document.querySelectorAll('[data-target-block]');

        function refreshTargetBlocks() {
            const scope = scopeSelect.value;

            blocks.forEach(function(block) {
                const key = block.getAttribute('data-target-block');
                block.style.display = key === scope ? 'block' : 'none';
            });
        }

        if (scopeSelect) {
            scopeSelect.addEventListener('change', refreshTargetBlocks);
            refreshTargetBlocks();
        }

        document.querySelectorAll('.finea-token-select').forEach(function(component) {
            const name = component.dataset.name;
            const single = component.dataset.single === '1';
            const search = component.querySelector('.finea-token-search');
            const list = component.querySelector('.finea-token-list');
            const options = component.querySelectorAll('.finea-token-option');
            let selected = [];

            const existingHidden = component.querySelector('input[type="hidden"][name="' + name + '"]');
            if (existingHidden && existingHidden.value !== '') {
                const option = Array.from(options).find(opt => opt.dataset.value === existingHidden.value);
                if (option) {
                    selected.push({
                        value: option.dataset.value,
                        label: option.dataset.label
                    });
                    option.classList.add('is-selected');
                }
            }

            options.forEach(function(option) {
                if (option.dataset.selected === '1') {
                    selected.push({
                        value: option.dataset.value,
                        label: option.dataset.label
                    });
                    option.classList.add('is-selected');
                }
            });

            function render() {
                list.innerHTML = '';

                component.querySelectorAll('input.finea-token-hidden').forEach(function(input) {
                    input.remove();
                });

                if (existingHidden) {
                    existingHidden.value = selected[0] ? selected[0].value : '';
                }

                selected.forEach(function(item) {
                    const badge = document.createElement('span');
                    badge.className = 'finea-token-badge';
                    badge.innerHTML = `
                    <span>${escapeHtml(item.label)}</span>
                    <button type="button" class="finea-token-remove" data-value="${escapeHtml(item.value)}">
                        <i class="fas fa-times"></i>
                    </button>
                `;

                    list.appendChild(badge);

                    if (!single) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = name;
                        hidden.value = item.value;
                        hidden.className = 'finea-token-hidden';
                        component.appendChild(hidden);
                    }
                });
            }

            function add(value, label) {
                if (single) {
                    selected = [];
                    options.forEach(opt => opt.classList.remove('is-selected'));
                }

                if (selected.some(item => item.value === value)) {
                    return;
                }

                selected.push({
                    value,
                    label
                });

                options.forEach(function(option) {
                    if (option.dataset.value === value) {
                        option.classList.add('is-selected');
                    }
                });

                search.value = '';
                filterOptions('');
                render();

                if (single) {
                    component.classList.remove('is-open');
                }
            }

            function remove(value) {
                selected = selected.filter(item => item.value !== value);

                options.forEach(function(option) {
                    if (option.dataset.value === value) {
                        option.classList.remove('is-selected');
                    }
                });

                render();
            }

            function filterOptions(query) {
                const q = query.toLowerCase();

                options.forEach(function(option) {
                    const label = option.dataset.label.toLowerCase();
                    option.style.display = label.includes(q) ? 'block' : 'none';
                });
            }

            options.forEach(function(option) {
                option.addEventListener('click', function() {
                    add(option.dataset.value, option.dataset.label);
                });
            });

            search.addEventListener('focus', function() {
                component.classList.add('is-open');
            });

            search.addEventListener('input', function() {
                component.classList.add('is-open');
                filterOptions(search.value);
            });

            list.addEventListener('click', function(event) {
                const btn = event.target.closest('.finea-token-remove');
                if (!btn) return;

                remove(btn.dataset.value);
            });

            document.addEventListener('click', function(event) {
                if (!component.contains(event.target)) {
                    component.classList.remove('is-open');
                }
            });

            render();
        });

        function escapeHtml(value) {
            return String(value)
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
