<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('formes_juridiques.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Formes juridiques fournisseur';
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
if ($editId > 0 && empty($supplierSettingsFormOnly) && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: forme_juridique_form.php?edit='.$editId); exit; }
$editingLegalForm = $editId > 0 ? $model->getLegalFormById($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $controller->handleLegalFormPost($_POST);
    $_SESSION['fournisseur_flash_' . (!empty($result['ok']) ? 'success' : 'error')] = (string) ($result['message'] ?? '');
    header('Location: formes_juridiques.php');
    exit;
}
$legalForms = $model->listLegalFormsWithUsage(false);

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Formes juridiques', 'Gérez les formes juridiques utilisées dans vos fiches fournisseurs.', 0); ?>

        <?php if ($flashSuccess): ?>
            <div class="mt-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800"><?= htmlspecialchars($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError): ?>
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800"><?= htmlspecialchars($flashError) ?></div>
        <?php endif; ?>

        <?php if (empty($supplierSettingsFormOnly)): ?><div class="supplier-settings-actions"><a href="forme_juridique_form.php" class="directory-primary">+ Ajouter une forme juridique</a></div><?php endif; ?>
        <div class="<?= !empty($supplierSettingsFormOnly) ? 'supplier-settings-form' : 'mt-6' ?>">
            <?php if (!empty($supplierSettingsFormOnly)): ?>
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900"><?= $editingLegalForm ? 'Modifier une forme juridique' : 'Nouvelle forme juridique' ?></h2>
                        <p class="mt-1 text-sm text-gray-500">Exemples: SARL, SA, SAS. Cette liste alimente les formulaires de création fournisseur.</p>
                    </div>
                    <?php if ($editingLegalForm): ?>
                        <a href="formes_juridiques.php" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Annuler l’édition</a>
                    <?php endif; ?>
                </div>

                <form method="post" class="mt-5 space-y-4">
                    <input type="hidden" name="action" value="<?= $editingLegalForm ? 'update' : 'create' ?>">
                    <input type="hidden" name="edit_id" value="<?= (int) ($editingLegalForm['id_legal_form'] ?? 0) ?>">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Libellé *</label>
                        <input name="label" required value="<?= htmlspecialchars((string) ($editingLegalForm['label'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" rows="4" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200"><?= htmlspecialchars((string) ($editingLegalForm['description'] ?? '')) ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Ordre d’affichage</label>
                        <input type="number" min="0" name="sort_order" value="<?= htmlspecialchars((string) ($editingLegalForm['sort_order'] ?? count($legalForms) + 1)) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="actif" value="1" <?= !isset($editingLegalForm['actif']) || !empty($editingLegalForm['actif']) ? 'checked' : '' ?> class="rounded border-gray-300">
                        Forme active
                    </label>
                    <div class="flex justify-end">
                        <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700"><?= $editingLegalForm ? 'Mettre à jour' : 'Créer la forme juridique' ?></button>
                    </div>
                </form>
            </section>
            <?php else: ?>
            <section class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Formes juridiques enregistrées</h2>
                        <p class="mt-1 text-sm text-gray-500"><?= count($legalForms) ?> forme(s) dans le référentiel.</p>
                    </div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 text-left text-gray-500">
                                <th class="py-3 pr-4">Libellé</th>
                                <th class="py-3 pr-4">Description</th>
                                <th class="py-3 pr-4">Ordre</th>
                                <th class="py-3 pr-4">Fournisseurs liés</th>
                                <th class="py-3 pr-4">Statut</th>
                                <th class="py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$legalForms): ?>
                                <tr>
                                    <td colspan="6" class="py-6 text-center text-gray-500">Aucune forme juridique enregistrée.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($legalForms as $legalForm): ?>
                                <tr class="border-b border-gray-100 text-gray-800 last:border-b-0">
                                    <td class="py-3 pr-4 font-semibold text-gray-900"><?= htmlspecialchars((string) ($legalForm['label'] ?? '')) ?></td>
                                    <td class="py-3 pr-4 text-gray-600"><?= htmlspecialchars((string) (($legalForm['description'] ?? '') ?: '—')) ?></td>
                                    <td class="py-3 pr-4 text-gray-600"><?= (int) ($legalForm['sort_order'] ?? 0) ?></td>
                                    <td class="py-3 pr-4 text-gray-600"><?= (int) ($legalForm['supplier_count'] ?? 0) ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= !empty($legalForm['actif']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-700' ?>">
                                            <?= !empty($legalForm['actif']) ? 'Active' : 'Inactive' ?>
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-3">
                                            <a href="forme_juridique_form.php?edit=<?= (int) ($legalForm['id_legal_form'] ?? 0) ?>" class="text-sm font-semibold text-indigo-700 hover:text-indigo-800">Modifier</a>
                                            <form method="post" onsubmit="return confirm('Supprimer cette forme juridique ?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="legal_form_id" value="<?= (int) ($legalForm['id_legal_form'] ?? 0) ?>">
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