<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('categories.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Catégories fournisseur';
$activeMenu = 'fournisseur';

$pdo = Database::getConnection();
$controller = new FournisseurController($pdo);
$model = $controller->model();

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['fournisseur_flash_success'])) {
    $flashSuccess = (string) $_SESSION['fournisseur_flash_success'];
    unset($_SESSION['fournisseur_flash_success']);
}
if (!empty($_SESSION['fournisseur_flash_error'])) {
    $flashError = (string) $_SESSION['fournisseur_flash_error'];
    unset($_SESSION['fournisseur_flash_error']);
}

$editId = (int) ($_GET['edit'] ?? $_POST['edit_id'] ?? 0);
if ($editId > 0 && empty($supplierSettingsFormOnly) && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: categorie_form.php?edit='.$editId); exit; }
$editingCategory = $editId > 0 ? $model->getCategoryById($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $controller->handleCategoryPost($_POST);
    $_SESSION['fournisseur_flash_' . (!empty($result['ok']) ? 'success' : 'error')] = (string) ($result['message'] ?? '');
    header('Location: categories.php');
    exit;
}
$categories = $model->listCategoriesWithUsage(false);

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Catégories fournisseurs', 'Organisez les partenaires par métier et domaine d’intervention.', 0); ?>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <?php if (empty($supplierSettingsFormOnly)): ?><div class="supplier-settings-actions"><a href="categorie_form.php" class="directory-primary">+ Ajouter une catégorie</a></div><?php endif; ?>
        <div class="<?= !empty($supplierSettingsFormOnly) ? 'supplier-settings-form' : 'mt-6' ?>">
            <?php if (!empty($supplierSettingsFormOnly)): ?>
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?= $editingCategory ? 'Modifier une catégorie' : 'Nouvelle catégorie' ?></h2>
                        <p class="mt-1 text-sm text-gray-500">Les catégories servent au regroupement fournisseur et aux alternatives rapides en décaissement.</p>
                    </div>
                    <?php if ($editingCategory): ?>
                        <a href="categories.php" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Annuler l’édition</a>
                    <?php endif; ?>
                </div>

                <form method="post" class="mt-5 space-y-4">
                    <input type="hidden" name="action" value="<?= $editingCategory ? 'update' : 'create' ?>">
                    <input type="hidden" name="edit_id" value="<?= (int) ($editingCategory['id_category'] ?? 0) ?>">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Libellé *</label>
                        <input name="label" required value="<?= htmlspecialchars((string) ($editingCategory['label'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200"><?= htmlspecialchars((string) ($editingCategory['description'] ?? '')) ?></textarea>
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="actif" value="1" <?= !isset($editingCategory['actif']) || !empty($editingCategory['actif']) ? 'checked' : '' ?> class="rounded border-gray-300">
                        Catégorie active
                    </label>
                    <div class="flex justify-end">
                        <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700"><?= $editingCategory ? 'Mettre à jour' : 'Créer la catégorie' ?></button>
                    </div>
                </form>
            </section>
            <?php else: ?>
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Catégories enregistrées</h2>
                        <p class="mt-1 text-sm text-gray-500"><?= count($categories) ?> catégorie(s) dans le référentiel.</p>
                    </div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-gray-500">
                                <th class="py-3 pr-4">Catégorie</th>
                                <th class="py-3 pr-4">Description</th>
                                <th class="py-3 pr-4">Fournisseurs liés</th>
                                <th class="py-3 pr-4">Statut</th>
                                <th class="py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$categories): ?>
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-gray-500">Aucune catégorie enregistrée.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($categories as $category): ?>
                                <tr class="border-b border-gray-100 text-gray-800 last:border-b-0">
                                    <td class="py-3 pr-4 font-semibold text-gray-900"><?= htmlspecialchars((string) ($category['label'] ?? '')) ?></td>
                                    <td class="py-3 pr-4 text-gray-600"><?= htmlspecialchars((string) (($category['description'] ?? '') ?: '—')) ?></td>
                                    <td class="py-3 pr-4 text-gray-600"><?= (int) ($category['supplier_count'] ?? 0) ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= !empty($category['actif']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700' ?>">
                                            <?= !empty($category['actif']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-3">
                                            <a href="categorie_form.php?edit=<?= (int) ($category['id_category'] ?? 0) ?>" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Modifier</a>
                                            <form method="post" onsubmit="return confirm('Supprimer cette catégorie ?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="category_id" value="<?= (int) ($category['id_category'] ?? 0) ?>">
                                                <button class="text-sm font-semibold text-rose-700 hover:text-rose-800">Supprimer</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>