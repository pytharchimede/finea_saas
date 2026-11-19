<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pdo = \Database::getConnection();
$controller = new \FournisseurController($pdo);
$model = $controller->model();

$supplierId = (int) ($_GET['id'] ?? 0);
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$isValidator = \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;

if ($supplierId <= 0) {
    http_response_code(404);
    exit('Fournisseur introuvable.');
}

$viewData = $controller->detailsView($supplierId);
$supplier = $viewData['supplier'];
if (!$supplier) {
    http_response_code(404);
    exit('Fournisseur introuvable.');
}

if ($isEmployeeOnly && (int) ($supplier['created_by_user_id'] ?? 0) !== $currentUserId) {
    http_response_code(403);
    exit('Acces refuse.');
}

$_SESSION['supplier_archive_csrf'] = $_SESSION['supplier_archive_csrf'] ?? bin2hex(random_bytes(32));
$context = $viewData['context'];
$positioningSettings = $viewData['positioningSettings'];
$position = $viewData['position'];

$pageTitle = 'Detail fournisseur';
$layoutRootBase = '../';
$activeMenu = 'fournisseur';
$activeEmployeeMenu = 'fournisseurs';

$requiredDocuments = array_values($context['supplier_required_documents'] ?? []);
$missingDocuments = array_values($context['supplier_missing_documents'] ?? []);
$availableDocuments = array_values($context['supplier_documents'] ?? []);
$availableDocumentLabels = array_map(static fn(array $document): string => (string) ($document['label'] ?? ''), $availableDocuments);

$documentRows = [];
foreach ($requiredDocuments as $label) {
    $documentRows[] = [
        'label' => (string) $label,
        'status' => in_array((string) $label, $availableDocumentLabels, true) ? 'Fournie' : 'Manquante',
        'path' => null,
    ];
}
foreach ($availableDocuments as $document) {
    $label = (string) ($document['label'] ?? '');
    if ($label === '' || in_array($label, $requiredDocuments, true)) {
        continue;
    }
    $documentRows[] = [
        'label' => $label,
        'status' => 'Disponible',
        'path' => (string) ($document['path'] ?? ''),
    ];
}
foreach ($documentRows as &$documentRow) {
    if (!empty($documentRow['path'])) {
        continue;
    }
    foreach ($availableDocuments as $document) {
        if ((string) ($document['label'] ?? '') === $documentRow['label']) {
            $documentRow['path'] = (string) ($document['path'] ?? '');
            break;
        }
    }
}
unset($documentRow);

require_once __DIR__ . '/../decaissement/includes/header.php';
if ($isEmployeeOnly) {
    require_once __DIR__ . '/../employe/includes/navbar.php';
} else {
    require_once __DIR__ . '/../decaissement/includes/navbar.php';
}
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <section class="supplier-overview">
            <?php supplierWorkspaceHeader((string)($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? 'Fiche fournisseur'), 'Identité, conformité et activité du fournisseur réunies dans une seule fiche.', $supplierId); ?>
        </section>


                                        <?php if ($isValidator): ?>
                                        <form method="post" action="fournisseurs.php" onsubmit="return confirm('Confirmer cette action sur le fournisseur ? Son historique sera conservé.');">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['supplier_archive_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="supplier_id" value="<?= (int)$supplier['id_fournisseur'] ?>">
                                            <input type="hidden" name="archive_action" value="<?= empty($supplier['archived_at']) ? 'archive' : 'restore' ?>">
                                            <button class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700"><?= empty($supplier['archived_at']) ? 'Archiver' : 'Réactiver' ?></button>
                                        </form>
                                        <?php endif; ?>

        <?php if (!empty($supplier['archived_at'])): ?>
            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">Archivé le <?= htmlspecialchars((string)$supplier['archived_at']) ?> par l’utilisateur #<?= (int)$supplier['archived_by_user_id'] ?>. Les documents existants restent accessibles.</div>
        <?php endif; ?>
        <section class="mt-6 grid gap-6 lg:grid-cols-[1.15fr_0.85fr]">
            <div class="rounded-[28px] border border-slate-200 bg-white/95 p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Identite</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Referentiel fournisseur</h2>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Type</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($supplier['supplier_type_label'] ?? '—')) ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Code</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($supplier['code_fournisseur'] ?? '—')) ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Forme juridique</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($context['supplier_legal_form'] ?? '—')) ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Contact</div>
                        <div class="mt-2 text-sm font-semibold text-slate-900"><?= htmlspecialchars((string) ($context['supplier_contact'] ?? '—')) ?></div>
                        <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($context['supplier_phone'] ?? '—')) ?></div>
                        <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) ($context['supplier_email'] ?? '—')) ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Adresse</div>
                        <div class="mt-2 text-sm text-slate-800"><?= nl2br(htmlspecialchars((string) ($context['supplier_address'] ?? '—'))) ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 md:col-span-2">
                        <?php $businessIdentity=$supplier; require __DIR__.'/../partials/business_identity_summary.php'; ?>
                        <?php if (($supplier['fiscal_country'] ?? 'CI') === 'FR'): ?>
                        <div class="mb-4 text-sm text-slate-700">
                            <strong>Identité française</strong><br>
                            SIRET : <?= htmlspecialchars((string)($supplier['siret'] ?? '—')) ?><br>
                            SIREN : <?= htmlspecialchars((string)($supplier['siren'] ?? '—')) ?><br>
                            TVA (déclarée) : <?= htmlspecialchars((string)($supplier['vat_number'] ?? '—')) ?><br>
                            Vérification : <?= htmlspecialchars((string)($supplier['registry_verified_at'] ?? 'Non vérifiée')) ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Categories</div>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php if (!empty($context['supplier_categories'])): ?>
                                <?php foreach (($context['supplier_categories'] ?? []) as $category): ?>
                                    <span class="inline-flex rounded-full border border-cyan-200 bg-cyan-50 px-3 py-1 text-xs font-semibold text-cyan-800"><?= htmlspecialchars((string) $category) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-sm text-slate-500">Aucune categorie</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-6">
                <section class="rounded-[28px] border border-slate-200 bg-white/95 p-6 shadow-sm">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Dossier</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Completude documentaire</h2>
                    <div class="mt-4 inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= !empty($context['supplier_document_is_complete']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' ?>">
                        <?= htmlspecialchars((string) ($context['supplier_document_completion_label'] ?? 'Dossier incomplet')) ?>
                    </div>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full <?= !empty($context['supplier_document_is_complete']) ? 'bg-emerald-500' : 'bg-amber-400' ?>" style="width: <?= max(0, min(100, (int) ($context['supplier_document_completion_percent'] ?? 0))) ?>%;"></div>
                    </div>
                    <?php if ($missingDocuments): ?>
                        <div class="mt-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                            Manque: <?= htmlspecialchars(implode(', ', $missingDocuments)) ?>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="rounded-[28px] border border-slate-200 bg-white/95 p-6 shadow-sm" data-map-shell>
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Position</p>
                            <h2 class="mt-2 text-xl font-semibold text-slate-950">Localisation</h2>
                        </div>
                        <button type="button" id="supplier-map-fullscreen" title="Afficher en plein ecran" aria-label="Afficher en plein ecran" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white text-sm text-slate-700 hover:bg-slate-50"><i class="fas fa-expand" aria-hidden="true"></i></button>
                    </div>
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                        <?= $position ? htmlspecialchars((string) ($position['location_label'] ?? $context['supplier_address'] ?? 'Position disponible')) : 'Aucune position enregistree.' ?>
                    </div>
                    <div id="supplier-map-status" class="mt-3 inline-flex rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">Initialisation...</div>
                    <div id="supplier-map" class="mt-4 h-[360px] overflow-hidden rounded-3xl border border-slate-200 bg-slate-100"></div>
                </section>
            </div>
        </section>

        <section class="mt-6 rounded-[28px] border border-slate-200 bg-white/95 p-6 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Pieces</p>
            <h2 class="mt-2 text-xl font-semibold text-slate-950">Liste detaillee complete</h2>
            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-slate-500">
                            <th class="py-3 pr-4">Piece</th>
                            <th class="py-3 pr-4">Statut</th>
                            <th class="py-3">Document</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$documentRows): ?>
                            <tr>
                                <td colspan="3" class="py-6 text-center text-slate-500">Aucune piece disponible.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documentRows as $document): ?>
                                <tr class="border-b border-slate-100 text-slate-800 last:border-b-0">
                                    <td class="py-3 pr-4 font-medium text-slate-950"><?= htmlspecialchars((string) ($document['label'] ?? '')) ?></td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= (($document['status'] ?? '') === 'Fournie' || ($document['status'] ?? '') === 'Disponible') ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-700' ?>">
                                            <?= htmlspecialchars((string) ($document['status'] ?? '—')) ?>
                                        </span>
                                    </td>
                                    <td class="py-3">
                                        <?php if (!empty($document['path'])): ?>
                                            <a href="../<?= htmlspecialchars((string) $document['path']) ?>" target="_blank" class="text-sm font-semibold text-cyan-700 hover:text-cyan-800">Ouvrir</a>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script src="../js/supplier-map-listing.js"></script>
<script>
    window.initSupplierListingMap({
        containerId: 'supplier-map',
        statusId: 'supplier-map-status',
        fullscreenButtonId: 'supplier-map-fullscreen',
        apiUrl: '../api/entity_positions.php?entity_type=supplier',
        updateUrl: '../api/entity_position_update.php',
        settings: <?= json_encode($positioningSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        items: <?= json_encode([[
                    'id' => (int) $supplierId,
                    'name' => (string) ($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? $supplier['raison_sociale'] ?? 'Fournisseur'),
                    'type' => (string) ($supplier['supplier_type'] ?? 'morale'),
                    'type_label' => (string) ($supplier['supplier_type_label'] ?? ''),
                    'legal_name' => (string) ($supplier['raison_sociale'] ?? ''),
                    'contact' => (string) ($supplier['contact_nom'] ?? ''),
                    'telephone' => (string) ($supplier['telephone'] ?? ''),
                    'email' => (string) ($supplier['email'] ?? ''),
                    'address' => (string) ($supplier['adresse'] ?? ''),
                    'categories' => array_values($supplier['category_labels'] ?? []),
                    'document_completion_percent' => (int) ($supplier['document_completion_percent'] ?? 0),
                    'document_completion_label' => (string) ($supplier['document_completion_label'] ?? ''),
                    'document_is_complete' => !empty($supplier['document_is_complete']),
                    'missing_document_labels' => array_values($supplier['missing_document_labels'] ?? []),
                    'position' => $position,
                ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>


