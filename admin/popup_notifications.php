<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controller/PopupNotificationController.php';

if (file_exists(__DIR__ . '/../model/AuditTrail.php')) {
    require_once __DIR__ . '/../model/AuditTrail.php';
}

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

$success = '';
$error = '';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'activate' && $id > 0) {
            $service->setActive($id, true);
            $success = 'Notification activée avec succès.';
        }

        if ($action === 'deactivate' && $id > 0) {
            $service->setActive($id, false);
            $success = 'Notification désactivée avec succès.';
        }

        if ($action === 'delete' && $id > 0) {
            $service->deleteNotification($id);
            $success = 'Notification supprimée avec succès.';
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$filters = [
    'type' => trim((string)($_GET['type'] ?? '')),
    'status' => trim((string)($_GET['status'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];

$notifications = $service->listNotifications($filters);
$options = $service->getFormOptions();

$total = count($notifications);
$activeCount = 0;
$inactiveCount = 0;
$totalReads = 0;
$totalResponses = 0;

foreach ($notifications as $notification) {
    if ((int)($notification['is_active'] ?? 0) === 1) {
        $activeCount++;
    } else {
        $inactiveCount++;
    }

    $totalReads += (int)($notification['reads_count'] ?? 0);
    $totalResponses += (int)($notification['responses_count'] ?? 0);
}

$typeLabels = $options['types'] ?? [];
$priorityLabels = $options['priorities'] ?? [];
$scopeLabels = $options['targetScopes'] ?? [];

$pageTitle = 'Pop-up nouveautés';
$activeMenu = '';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">

        <div class="mb-10">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6">
                <div>
                    <div class="inline-flex items-center px-4 py-2 rounded-full bg-indigo-100 text-indigo-700 text-sm font-semibold mb-4">
                        <i class="fas fa-bullhorn mr-2"></i>
                        Notifications administratives
                    </div>

                    <h1 class="text-4xl lg:text-5xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent drop-shadow-sm">
                        Pop-up nouveautés
                    </h1>

                    <p class="text-gray-600 mt-3 text-lg max-w-3xl">
                        Créer des annonces de nouveaux modules, corrections, maintenances et mises à jour avec accusé de lecture.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3">
                    <a href="popup_notification_form.php"
                        class="inline-flex items-center px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 text-white rounded-xl hover:from-indigo-700 hover:to-purple-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                        <i class="fas fa-plus mr-2"></i>
                        Créer une notification
                    </a>

                    <a href="dashboard_admin.php"
                        class="inline-flex items-center px-5 py-3 bg-white text-slate-700 rounded-xl border border-slate-200 hover:bg-slate-50 transition-all shadow-sm hover:shadow-md">
                        <i class="fas fa-arrow-left mr-2 text-slate-500"></i>
                        Dashboard admin
                    </a>
                </div>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-800">
                <i class="fas fa-check-circle mr-2"></i><?= h($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="mb-6 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-800">
                <i class="fas fa-triangle-exclamation mr-2"></i><?= h($error) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-5 gap-6 mb-10">
            <div class="bg-white rounded-2xl shadow-xl p-6 border border-indigo-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total notifications</p>
                        <p class="text-3xl font-bold text-indigo-600"><?= number_format($total) ?></p>
                    </div>
                    <div class="bg-indigo-100 p-3 rounded-xl">
                        <i class="fas fa-bullhorn text-2xl text-indigo-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-emerald-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Actives</p>
                        <p class="text-3xl font-bold text-emerald-600"><?= number_format($activeCount) ?></p>
                    </div>
                    <div class="bg-emerald-100 p-3 rounded-xl">
                        <i class="fas fa-toggle-on text-2xl text-emerald-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-slate-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Inactives</p>
                        <p class="text-3xl font-bold text-slate-600"><?= number_format($inactiveCount) ?></p>
                    </div>
                    <div class="bg-slate-100 p-3 rounded-xl">
                        <i class="fas fa-toggle-off text-2xl text-slate-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-purple-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Lectures enregistrées</p>
                        <p class="text-3xl font-bold text-purple-600"><?= number_format($totalReads) ?></p>
                    </div>
                    <div class="bg-purple-100 p-3 rounded-xl">
                        <i class="fas fa-clipboard-check text-2xl text-purple-600"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl p-6 border border-cyan-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Réponses enregistrées</p>
                        <p class="text-3xl font-bold text-cyan-600"><?= number_format($totalResponses) ?></p>
                    </div>
                    <div class="bg-cyan-100 p-3 rounded-xl">
                        <i class="fas fa-hand-pointer text-2xl text-cyan-600"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-xl p-6 border border-gray-100 mb-8">
            <form method="get" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Type</label>
                    <select name="type" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        <option value="">Tous les types</option>
                        <?php foreach ($typeLabels as $value => $label): ?>
                            <option value="<?= h($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>>
                                <?= h($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Statut</label>
                    <select name="status" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        <option value="">Tous</option>
                        <option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Actives</option>
                        <option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactives</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Date début</label>
                    <input type="date" name="date_from" value="<?= h($filters['date_from']) ?>"
                        class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Date fin</label>
                    <input type="date" name="date_to" value="<?= h($filters['date_to']) ?>"
                        class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                </div>

                <div class="flex gap-2">
                    <button type="submit"
                        class="inline-flex items-center justify-center w-full px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-black transition text-sm">
                        <i class="fas fa-filter mr-2"></i>
                        Filtrer
                    </button>

                    <a href="popup_notifications.php"
                        class="inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-xl hover:bg-gray-50 transition text-sm">
                        <i class="fas fa-rotate-left"></i>
                    </a>
                </div>
            </form>
        </div>

        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 overflow-hidden">
            <div class="p-6 border-b border-gray-100 flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Liste des notifications</h2>
                    <p class="text-sm text-gray-500 mt-1">Gestion des pop-up visibles à la connexion ou sur le portail FINEA.</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full">
                    <thead>
                        <tr class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="p-4">Notification</th>
                            <th class="p-4">Type</th>
                            <th class="p-4">Ciblage</th>
                            <th class="p-4">Priorité</th>
                            <th class="p-4">Statut</th>
                            <th class="p-4">Suivi</th>
                            <th class="p-4">Créée le</th>
                            <th class="p-4 text-right">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($notifications)): ?>
                            <tr>
                                <td colspan="8" class="p-10 text-center text-gray-500">
                                    <div class="text-4xl text-gray-300 mb-3">
                                        <i class="fas fa-bullhorn"></i>
                                    </div>
                                    Aucune notification trouvée.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                                <?php
                                $id = (int)($notification['id'] ?? 0);
                                $isActive = (int)($notification['is_active'] ?? 0) === 1;
                                $type = (string)($notification['type'] ?? 'information');
                                $priority = (string)($notification['priority'] ?? 'normal');
                                $scope = (string)($notification['target_scope'] ?? 'all');

                                $typeClass = match ($type) {
                                    'nouveau_module' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
                                    'nouvelle_fonctionnalite' => 'bg-purple-50 text-purple-700 border-purple-200',
                                    'correction' => 'bg-amber-50 text-amber-700 border-amber-200',
                                    'maintenance' => 'bg-slate-50 text-slate-700 border-slate-200',
                                    'mise_a_jour' => 'bg-cyan-50 text-cyan-700 border-cyan-200',
                                    default => 'bg-blue-50 text-blue-700 border-blue-200',
                                };

                                $priorityClass = match ($priority) {
                                    'critical' => 'bg-red-50 text-red-700 border-red-200',
                                    'high' => 'bg-orange-50 text-orange-700 border-orange-200',
                                    'low' => 'bg-gray-50 text-gray-600 border-gray-200',
                                    default => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                };
                                ?>

                                <tr class="hover:bg-gray-50 transition">
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-900"><?= h($notification['title'] ?? '') ?></div>
                                        <?php if (!empty($notification['short_message'])): ?>
                                            <div class="text-sm text-gray-500 mt-1 max-w-md">
                                                <?= h($notification['short_message']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($notification['module_key'])): ?>
                                            <div class="text-xs text-gray-400 mt-2">
                                                Module : <span class="font-mono"><?= h($notification['module_key']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-4">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold <?= h($typeClass) ?>">
                                            <?= h($typeLabels[$type] ?? $type) ?>
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        <div class="text-sm font-medium text-gray-700">
                                            <?= h($scopeLabels[$scope] ?? $scope) ?>
                                        </div>
                                    </td>

                                    <td class="p-4">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold <?= h($priorityClass) ?>">
                                            <?= h($priorityLabels[$priority] ?? $priority) ?>
                                        </span>
                                    </td>

                                    <td class="p-4">
                                        <?php if ($isActive): ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 text-xs font-semibold">
                                                <i class="fas fa-circle text-[8px] mr-2"></i>Active
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-3 py-1 rounded-full bg-gray-50 text-gray-600 border border-gray-200 text-xs font-semibold">
                                                <i class="fas fa-circle text-[8px] mr-2"></i>Inactive
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="p-4">
                                        <a href="popup_notification_reads.php?id=<?= $id ?>"
                                            class="inline-flex flex-col items-start gap-1 text-sm hover:opacity-80">
                                            <span class="font-semibold text-indigo-600">
                                                <i class="fas fa-eye mr-2"></i>
                                                <?= number_format((int)($notification['reads_count'] ?? 0)) ?> vue(s)
                                            </span>
                                            <span class="font-semibold text-cyan-700">
                                                <i class="fas fa-hand-pointer mr-2"></i>
                                                <?= number_format((int)($notification['responses_count'] ?? 0)) ?> réponse(s)
                                            </span>
                                        </a>
                                    </td>

                                    <td class="p-4 text-sm text-gray-500">
                                        <?= h($notification['created_at'] ?? '') ?>
                                    </td>

                                    <td class="p-4">
                                        <div class="flex justify-end gap-2">
                                            <a href="popup_notification_form.php?id=<?= $id ?>"
                                                class="inline-flex items-center px-3 py-2 rounded-lg bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 text-sm">
                                                <i class="fas fa-pen"></i>
                                            </a>

                                            <a href="popup_notification_reads.php?id=<?= $id ?>"
                                                class="inline-flex items-center px-3 py-2 rounded-lg bg-white border border-indigo-200 text-indigo-700 hover:bg-indigo-50 text-sm">
                                                <i class="fas fa-clipboard-check"></i>
                                            </a>

                                            <form method="post" class="inline">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="action" value="<?= $isActive ? 'deactivate' : 'activate' ?>">
                                                <button type="submit"
                                                    class="inline-flex items-center px-3 py-2 rounded-lg <?= $isActive ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' ?> text-sm">
                                                    <i class="fas <?= $isActive ? 'fa-toggle-off' : 'fa-toggle-on' ?>"></i>
                                                </button>
                                            </form>

                                            <form method="post" class="inline" onsubmit="return confirm('Supprimer cette notification ?');">
                                                <input type="hidden" name="id" value="<?= $id ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit"
                                                    class="inline-flex items-center px-3 py-2 rounded-lg bg-red-50 text-red-700 hover:bg-red-100 text-sm">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
