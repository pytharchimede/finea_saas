<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

if (!AccessManager::hasPermission('acces_admin')) {
    http_response_code(403);
    exit('Accès refusé');
}

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/SearchEngineKnowledgeManager.php';

$pdo = Database::getConnection();
$manager = new SearchEngineKnowledgeManager($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);

if (empty($_SESSION['search_engine_training_csrf'])) {
    $_SESSION['search_engine_training_csrf'] = bin2hex(random_bytes(24));
}
$csrfToken = (string) $_SESSION['search_engine_training_csrf'];
$flash = null;
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $submittedToken = (string) ($_POST['_csrf'] ?? '');
        if (!hash_equals($csrfToken, $submittedToken)) {
            throw new RuntimeException('Jeton de sécurité invalide. Rechargez la page.');
        }

        $action = (string) ($_POST['action'] ?? 'save');
        if ($action === 'sync') {
            $stats = $manager->sync();
            $flash = sprintf('%d page(s) et %d module(s) synchronisés.', $stats['pages'], $stats['modules']);
            AuditTrail::log('search_engine_knowledge_synced', [
                'module' => 'admin',
                'action' => 'search_engine_knowledge_synced',
                'entity_type' => 'search_engine',
                'details' => $stats,
            ], $pdo);
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            $manager->delete($id);
            $flash = 'La correspondance a été retirée du moteur.';
            AuditTrail::log('search_engine_knowledge_deleted', [
                'module' => 'admin',
                'action' => 'search_engine_knowledge_deleted',
                'entity_type' => 'search_engine_knowledge',
                'entity_id' => $id,
            ], $pdo);
        } else {
            $id = $manager->save([
                'id' => (int) ($_POST['id'] ?? 0),
                'label' => $_POST['label'] ?? '',
                'target' => $_POST['target'] ?? '',
                'result_type' => $_POST['result_type'] ?? 'page',
                'description' => $_POST['description'] ?? '',
                'keywords' => $_POST['keywords'] ?? '',
                'synonyms' => $_POST['synonyms'] ?? '',
                'required_permissions' => $_POST['required_permissions'] ?? [],
                'is_active' => isset($_POST['is_active']),
            ], $userId);
            $_GET['id'] = (string) $id;
            $flash = 'La connaissance du moteur a été enregistrée.';
            AuditTrail::log('search_engine_knowledge_saved', [
                'module' => 'admin',
                'action' => 'search_engine_knowledge_saved',
                'entity_type' => 'search_engine_knowledge',
                'entity_id' => $id,
            ], $pdo);
        }
    } catch (Throwable $e) {
        $flash = $e->getMessage();
        $flashType = 'error';
    }
}

$manager->syncIfStale(300);
$query = trim((string) ($_GET['q'] ?? ''));
$filterType = trim((string) ($_GET['type'] ?? ''));
$entries = $manager->list(['q' => $query, 'type' => $filterType]);
$editingId = (int) ($_GET['id'] ?? 0);
$editing = $editingId > 0 ? $manager->get($editingId) : null;
$editing ??= [
    'id' => 0,
    'label' => '',
    'target' => '',
    'result_type' => 'page',
    'description' => '',
    'keywords' => '',
    'synonyms' => '',
    'required_permissions' => [],
    'is_active' => 1,
    'is_system' => 0,
];
$permissionOptions = $manager->permissionOptions();
$resultTypes = [
    'module' => 'Module', 'page' => 'Page', 'form' => 'Formulaire',
    'document' => 'Document', 'person' => 'Personne', 'client' => 'Client',
    'supplier' => 'Fournisseur', 'chantier' => 'Chantier', 'devis' => 'Devis',
    'invoice' => 'Facture', 'ticket' => 'Ticket', 'asset' => 'Véhicule / engin',
    'stock' => 'Matériel / stock', 'contract' => 'Contrat', 'location' => 'Localisation',
];

$pageTitle = 'Entraînement du moteur';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-amber-50 px-4 py-8">
    <div class="mx-auto max-w-[1600px]">
        <div class="mb-7 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.25em] text-indigo-600">Moteur Finéa</p>
                <h1 class="mt-2 text-3xl font-black text-slate-950">Entraînement et correspondances</h1>
                <p class="mt-2 max-w-3xl text-sm text-slate-600">Associez les pages et modules à des mots-clés, synonymes, types et permissions. La synchronisation détecte les nouveaux fichiers sans écraser vos enrichissements.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <form method="post">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="sync">
                    <button class="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700" type="submit">
                        <i class="fas fa-arrows-rotate mr-2"></i>Synchroniser maintenant
                    </button>
                </form>
                <a href="../moteur.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-bold text-slate-800 hover:bg-slate-50">Tester le moteur</a>
                <a href="dashboard_admin.php" class="rounded-2xl bg-slate-950 px-5 py-3 text-sm font-bold text-white">Administration</a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="mb-6 rounded-2xl border px-5 py-4 text-sm font-semibold <?= $flashType === 'error' ? 'border-rose-200 bg-rose-50 text-rose-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800' ?>">
                <?= htmlspecialchars($flash) ?>
            </div>
        <?php endif; ?>

        <div class="grid gap-7 xl:grid-cols-[430px_minmax(0,1fr)]">
            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-black text-slate-950"><?= !empty($editing['id']) ? 'Modifier la correspondance' : 'Ajouter une connaissance' ?></h2>
                        <?php if (!empty($editing['is_system'])): ?>
                            <p class="mt-1 text-xs text-indigo-600">Élément synchronisé automatiquement</p>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($editing['id'])): ?><a class="text-sm font-bold text-indigo-700" href="search_engine_training.php">Nouveau</a><?php endif; ?>
                </div>

                <form method="post" class="space-y-4">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">

                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Libellé affiché</label>
                        <input name="label" required value="<?= htmlspecialchars((string) ($editing['label'] ?? '')) ?>" class="w-full rounded-xl border border-slate-200 px-4 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Cible</label>
                        <input name="target" required value="<?= htmlspecialchars((string) ($editing['target'] ?? '')) ?>" placeholder="module/page.php?onglet=..." class="w-full rounded-xl border border-slate-200 px-4 py-3">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Type de résultat</label>
                        <select name="result_type" class="w-full rounded-xl border border-slate-200 px-4 py-3">
                            <?php foreach ($resultTypes as $key => $label): ?>
                                <option value="<?= htmlspecialchars($key) ?>" <?= ($editing['result_type'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Description</label>
                        <textarea name="description" rows="2" class="w-full rounded-xl border border-slate-200 px-4 py-3"><?= htmlspecialchars((string) ($editing['description'] ?? '')) ?></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Mots-clés</label>
                        <textarea name="keywords" rows="3" placeholder="paiement, caisse, dépense, règlement" class="w-full rounded-xl border border-slate-200 px-4 py-3"><?= htmlspecialchars((string) ($editing['keywords'] ?? '')) ?></textarea>
                        <p class="mt-1 text-xs text-slate-500">Séparez les termes par une virgule ou un retour à la ligne.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-bold text-slate-700">Synonymes et langage utilisateur</label>
                        <textarea name="synonyms" rows="3" placeholder="argent, cash, liquidités..." class="w-full rounded-xl border border-slate-200 px-4 py-3"><?= htmlspecialchars((string) ($editing['synonyms'] ?? '')) ?></textarea>
                    </div>
                    <details class="rounded-xl border border-slate-200 p-3">
                        <summary class="cursor-pointer text-sm font-bold text-slate-700">Permissions autorisées</summary>
                        <div class="mt-3 max-h-52 space-y-2 overflow-auto">
                            <?php foreach ($permissionOptions as $permission): ?>
                                <label class="flex items-center gap-2 text-xs text-slate-700">
                                    <input type="checkbox" name="required_permissions[]" value="<?= htmlspecialchars($permission) ?>" <?= in_array($permission, (array) ($editing['required_permissions'] ?? []), true) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($permission) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Au moins une permission cochée suffit. Aucune signifie accessible à tout utilisateur connecté.</p>
                    </details>
                    <label class="flex items-center gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm font-bold text-slate-700">
                        <input type="checkbox" name="is_active" value="1" <?= !empty($editing['is_active']) ? 'checked' : '' ?>>
                        Actif dans le moteur
                    </label>
                    <button type="submit" class="w-full rounded-xl bg-slate-950 px-5 py-3 font-bold text-white hover:bg-slate-800">Enregistrer l’apprentissage</button>
                </form>
            </section>

            <section class="min-w-0">
                <form method="get" class="mb-4 grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-[1fr_220px_auto]">
                    <input name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Rechercher une page, cible ou un mot-clé..." class="rounded-xl border border-slate-200 px-4 py-3">
                    <select name="type" class="rounded-xl border border-slate-200 px-4 py-3">
                        <option value="">Tous les types</option>
                        <?php foreach ($resultTypes as $key => $label): ?><option value="<?= htmlspecialchars($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?>
                    </select>
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 font-bold text-white">Filtrer</button>
                </form>

                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="border-b border-slate-100 px-5 py-4 text-sm font-bold text-slate-700"><?= count($entries) ?> correspondance(s)</div>
                    <div class="max-h-[1050px] divide-y divide-slate-100 overflow-auto">
                        <?php foreach ($entries as $entry): ?>
                            <article class="p-5 hover:bg-slate-50">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-black text-slate-950"><?= htmlspecialchars((string) $entry['label']) ?></h3>
                                            <span class="rounded-full bg-indigo-50 px-2 py-1 text-[11px] font-bold text-indigo-700"><?= htmlspecialchars((string) $entry['result_type']) ?></span>
                                            <?php if (!empty($entry['is_system'])): ?><span class="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-bold text-slate-600">Auto</span><?php endif; ?>
                                            <?php if (empty($entry['is_active'])): ?><span class="rounded-full bg-rose-50 px-2 py-1 text-[11px] font-bold text-rose-700">Inactif</span><?php endif; ?>
                                        </div>
                                        <p class="mt-1 break-all text-xs text-slate-500"><?= htmlspecialchars((string) $entry['target']) ?></p>
                                        <?php if (trim((string) ($entry['keywords'] ?? '')) !== '' || trim((string) ($entry['synonyms'] ?? '')) !== ''): ?>
                                            <p class="mt-2 text-xs text-slate-700"><strong>Apprentissage :</strong> <?= htmlspecialchars(trim((string) ($entry['keywords'] ?? '') . ' · ' . (string) ($entry['synonyms'] ?? ''), ' ·')) ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <a href="?id=<?= (int) $entry['id'] ?>&q=<?= urlencode($query) ?>&type=<?= urlencode($filterType) ?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700">Modifier</a>
                                        <form method="post" onsubmit="return confirm('Retirer cet élément du moteur ?');">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                                            <button class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700" type="submit"><?= !empty($entry['is_system']) ? 'Désactiver' : 'Supprimer' ?></button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
