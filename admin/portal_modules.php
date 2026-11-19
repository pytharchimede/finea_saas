<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/PortalModuleManager.php';

$pdo = Database::getConnection();
$moduleManager = new PortalModuleManager($pdo);

$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'save'));

    try {
        if ($action === 'delete') {
            $moduleId = (int)($_POST['id'] ?? 0);
            $moduleToDelete = $moduleId > 0 ? $moduleManager->getById($moduleId) : null;
            if ($moduleId <= 0 || !$moduleToDelete) {
                throw new InvalidArgumentException('Module introuvable pour suppression.');
            }

            $moduleManager->delete($moduleId);
            AuditTrail::log('portal_module_deleted', [
                'module' => 'admin',
                'action' => 'portal_module_deleted',
                'entity_type' => 'portal_module',
                'entity_id' => $moduleId,
                'entity_label' => $moduleToDelete['label'] ?? ('Module #' . $moduleId),
            ], $pdo);
            $message = 'Le module a été supprimé.';
        } else {
            $payload = [
                'id' => (int)($_POST['id'] ?? 0),
                'module_key' => $_POST['module_key'] ?? '',
                'label' => $_POST['label'] ?? '',
                'icon' => $_POST['icon'] ?? '',
                'color' => $_POST['color'] ?? '',
                'target' => $_POST['target'] ?? '',
                'slug' => $_POST['slug'] ?? '',
                'subtitle' => $_POST['subtitle'] ?? '',
                'required_permissions' => $_POST['required_permissions'] ?? [],
                'sort_order' => (int)($_POST['sort_order'] ?? 0),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];

            $savedId = $moduleManager->save($payload);
            $savedModule = $moduleManager->getById($savedId);

            AuditTrail::log('portal_module_saved', [
                'module' => 'admin',
                'action' => $payload['id'] > 0 ? 'portal_module_updated' : 'portal_module_created',
                'entity_type' => 'portal_module',
                'entity_id' => $savedId,
                'entity_label' => $savedModule['label'] ?? ('Module #' . $savedId),
                'details' => [
                    'module_key' => $savedModule['module_key'] ?? null,
                    'target' => $savedModule['target'] ?? null,
                    'permissions' => $savedModule['required_permissions'] ?? [],
                    'is_active' => !empty($savedModule['is_active']),
                ],
            ], $pdo);

            $message = $payload['id'] > 0 ? 'Le module a été mis à jour.' : 'Le module a été créé.';
            $_GET['id'] = (string)$savedId;
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$moduleId = (int)($_GET['id'] ?? 0);
$editingModule = $moduleId > 0 ? $moduleManager->getById($moduleId) : null;

if (!$editingModule) {
    $editingModule = [
        'id' => 0,
        'module_key' => '',
        'label' => '',
        'icon' => 'fa-cube',
        'color' => '#2563EB',
        'target' => '',
        'slug' => '',
        'subtitle' => '',
        'required_permissions' => [],
        'sort_order' => $moduleManager->getNextSortOrder(),
        'is_active' => 1,
    ];
}

$modules = $moduleManager->listAll();
$permissionOptions = $moduleManager->getPermissionOptions();

AuditTrail::logPageConsultation([
    'module' => 'admin',
    'action' => 'portal_modules_view',
    'entity_type' => 'portal_module_page',
    'entity_label' => 'portal_modules',
    'details' => [
        'module_count' => count($modules),
        'editing_module_id' => (int)($editingModule['id'] ?? 0),
    ],
], $pdo);

$pageTitle = 'Modules du portail';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-cyan-50 via-white to-sky-50">
    <div class="container mx-auto py-10 px-4">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6 mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-800">Modules du portail</h1>
                <p class="mt-2 text-gray-600">Gérez les cartes visibles dans le portail et les permissions qui les débloquent.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                    <i class="fas fa-arrow-left"></i>
                    Dashboard Admin
                </a>
                <a href="../selection_portail.php" class="inline-flex items-center gap-2 px-5 py-3 bg-gray-900 text-white rounded-xl shadow hover:bg-gray-800 transition">
                    <i class="fas fa-th-large"></i>
                    Voir le portail
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-2xl border <?= $messageType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 xl:grid-cols-5 gap-8">
            <div class="xl:col-span-2 bg-white rounded-3xl shadow-xl border border-gray-100 p-6">
                <div class="flex items-center justify-between gap-4 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800"><?= !empty($editingModule['id']) ? 'Modifier un module' : 'Créer un module' ?></h2>
                        <p class="text-sm text-gray-500 mt-1">Les permissions cochées sont évaluées en mode "au moins une".</p>
                    </div>
                    <?php if (!empty($editingModule['id'])): ?>
                        <a href="portal_modules.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                            <i class="fas fa-plus"></i>
                            Nouveau
                        </a>
                    <?php endif; ?>
                </div>

                <form method="post" class="space-y-5">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int)($editingModule['id'] ?? 0) ?>">

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Identifiant interne</label>
                        <input type="text" name="module_key" value="<?= htmlspecialchars((string)($editingModule['module_key'] ?? '')) ?>" placeholder="module_commercial" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Libellé</label>
                        <input type="text" name="label" value="<?= htmlspecialchars((string)($editingModule['label'] ?? '')) ?>" required class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Icône Font Awesome</label>
                            <input type="text" name="icon" value="<?= htmlspecialchars((string)($editingModule['icon'] ?? 'fa-cube')) ?>" placeholder="fa-chart-line" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Couleur</label>
                            <input type="text" name="color" value="<?= htmlspecialchars((string)($editingModule['color'] ?? '#2563EB')) ?>" placeholder="#2563EB" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Cible</label>
                        <input type="text" name="target" value="<?= htmlspecialchars((string)($editingModule['target'] ?? '')) ?>" required placeholder="crm/dashboard_crm.php" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Slug page</label>
                            <input type="text" name="slug" value="<?= htmlspecialchars((string)($editingModule['slug'] ?? '')) ?>" placeholder="dashboard_crm.php" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Ordre d'affichage</label>
                            <input type="number" name="sort_order" min="1" value="<?= (int)($editingModule['sort_order'] ?? 10) ?>" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Sous-titre</label>
                        <textarea name="subtitle" rows="3" class="w-full rounded-xl border border-gray-200 px-4 py-3 focus:outline-none focus:ring-2 focus:ring-cyan-200 focus:border-cyan-300"><?= htmlspecialchars((string)($editingModule['subtitle'] ?? '')) ?></textarea>
                    </div>

                    <div>
                        <div class="flex items-center justify-between gap-4 mb-3">
                            <label class="block text-sm font-semibold text-gray-700">Permissions qui débloquent le module</label>
                            <span class="text-xs text-gray-500"><?= count($permissionOptions) ?> permissions détectées</span>
                        </div>
                        <div class="max-h-72 overflow-y-auto rounded-2xl border border-gray-200 p-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php foreach ($permissionOptions as $permissionKey => $permissionLabel): ?>
                                <?php $checked = in_array($permissionKey, $editingModule['required_permissions'] ?? [], true); ?>
                                <label class="flex items-center gap-3 rounded-xl border px-3 py-3 cursor-pointer transition <?= $checked ? 'bg-cyan-50 border-cyan-300 text-cyan-900' : 'bg-gray-50 border-gray-200 text-gray-700 hover:border-cyan-200' ?>">
                                    <input type="checkbox" name="required_permissions[]" value="<?= htmlspecialchars($permissionKey) ?>" <?= $checked ? 'checked' : '' ?> class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                                    <span>
                                        <span class="block text-sm font-semibold"><?= htmlspecialchars($permissionLabel) ?></span>
                                        <span class="block text-xs text-gray-500"><?= htmlspecialchars($permissionKey) ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <label class="inline-flex items-center gap-3 text-sm font-semibold text-gray-700">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($editingModule['is_active']) ? 'checked' : '' ?> class="rounded border-gray-300 text-cyan-600 focus:ring-cyan-500">
                        Module actif sur le portail
                    </label>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-cyan-600 text-white hover:bg-cyan-700 transition shadow-lg shadow-cyan-200/50">
                            <i class="fas fa-save"></i>
                            <?= !empty($editingModule['id']) ? 'Mettre à jour' : 'Créer le module' ?>
                        </button>

                        <?php if (!empty($editingModule['id'])): ?>
                            <button type="submit" name="action" value="delete" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-red-600 text-white hover:bg-red-700 transition" onclick="return confirm('Supprimer ce module du portail ?');">
                                <i class="fas fa-trash"></i>
                                Supprimer
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="xl:col-span-3 bg-white rounded-3xl shadow-xl border border-gray-100 p-6">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3 mb-6">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-800">Modules enregistrés</h2>
                        <p class="text-sm text-gray-500 mt-1">L'ordre affiché ici est celui utilisé par le portail.</p>
                    </div>
                    <div class="text-sm text-gray-500">Total : <?= count($modules) ?></div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead class="bg-gray-100 text-gray-600 text-sm uppercase tracking-wider">
                            <tr>
                                <th class="px-4 py-3">Ordre</th>
                                <th class="px-4 py-3">Module</th>
                                <th class="px-4 py-3">Cible</th>
                                <th class="px-4 py-3">Permissions</th>
                                <th class="px-4 py-3">Statut</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm text-gray-700">
                            <?php foreach ($modules as $module): ?>
                                <tr class="border-t hover:bg-gray-50 align-top">
                                    <td class="px-4 py-4 font-semibold text-gray-600"><?= (int)($module['sort_order'] ?? 0) ?></td>
                                    <td class="px-4 py-4">
                                        <div class="flex items-start gap-3">
                                            <div class="w-12 h-12 rounded-2xl text-white flex items-center justify-center shadow" style="background: linear-gradient(135deg, <?= htmlspecialchars((string)($module['color'] ?? '#2563EB')) ?>, #111827);">
                                                <i class="fas <?= htmlspecialchars((string)($module['icon'] ?? 'fa-cube')) ?>"></i>
                                            </div>
                                            <div>
                                                <div class="font-semibold text-gray-900"><?= htmlspecialchars((string)($module['label'] ?? '')) ?></div>
                                                <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars((string)($module['module_key'] ?? '')) ?></div>
                                                <div class="text-xs text-gray-500 mt-2"><?= htmlspecialchars((string)($module['subtitle'] ?? '')) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="font-medium text-gray-800"><?= htmlspecialchars((string)($module['target'] ?? '')) ?></div>
                                        <div class="text-xs text-gray-500 mt-1">Slug : <?= htmlspecialchars((string)($module['slug'] ?? '')) ?></div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <div class="flex flex-wrap gap-2 max-w-md">
                                            <?php foreach (($module['required_permissions'] ?? []) as $permission): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full bg-cyan-50 text-cyan-800 border border-cyan-200 text-xs font-semibold"><?= htmlspecialchars((string)$permission) ?></span>
                                            <?php endforeach; ?>
                                            <?php if (empty($module['required_permissions'])): ?>
                                                <span class="inline-flex items-center px-3 py-1 rounded-full bg-gray-100 text-gray-700 border border-gray-200 text-xs font-semibold">Aucune</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-4 py-4">
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold <?= !empty($module['is_active']) ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-700' ?>">
                                            <?= !empty($module['is_active']) ? 'Actif' : 'Inactif' ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-4">
                                        <a href="portal_modules.php?id=<?= (int)$module['id'] ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-gray-900 text-white rounded-xl hover:bg-gray-800 transition">
                                            <i class="fas fa-pen"></i>
                                            Modifier
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>