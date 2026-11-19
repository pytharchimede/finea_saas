<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/../model/EntityPositioningManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$model = new \FournisseurRepository($pdo);
$positioningManager = new \EntityPositioningManager($pdo);

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$pageTitle = 'Mes fournisseurs';
$layoutRootBase = '../';
$activeEmployeeMenu = 'fournisseurs';
$selectedStatus = trim((string) ($_GET['status'] ?? ''));

$allowedStatuses = [
    '',
    \FournisseurRepository::STATUS_PENDING,
    \FournisseurRepository::STATUS_APPROVED,
    \FournisseurRepository::STATUS_REJECTED,
];

if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = '';
}

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

$allFournisseurs = $model->listLatest(500, false, ['created_by_user_id' => $currentUserId]);
$listOptions = ['created_by_user_id' => $currentUserId];
if ($selectedStatus !== '') {
    $listOptions['validation_status'] = $selectedStatus;
}
$fournisseurs = $model->listLatest(100, false, $listOptions);
$positioningSettings = $positioningManager->getSettings('supplier');
$supplierPositions = $positioningManager->listPositions('supplier', array_map(static fn(array $row): int => (int) ($row['id_fournisseur'] ?? 0), $fournisseurs));
$statusSummary = [
    \FournisseurRepository::STATUS_PENDING => 0,
    \FournisseurRepository::STATUS_APPROVED => 0,
    \FournisseurRepository::STATUS_REJECTED => 0,
];

foreach ($allFournisseurs as $supplier) {
    $status = (string) ($supplier['validation_status'] ?? \FournisseurRepository::STATUS_PENDING);
    if (!array_key_exists($status, $statusSummary)) {
        $statusSummary[$status] = 0;
    }
    $statusSummary[$status]++;
}

function employeeSupplierStatusMeta(string $status): array
{
    switch ($status) {
        case \FournisseurRepository::STATUS_APPROVED:
            return ['label' => 'Validé', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700'];
        case \FournisseurRepository::STATUS_REJECTED:
            return ['label' => 'Rejeté', 'class' => 'border-rose-200 bg-rose-50 text-rose-700'];
        default:
            return ['label' => 'En attente', 'class' => 'border-amber-200 bg-amber-50 text-amber-700'];
    }
}

function employeeSupplierTypeLabel(string $type): string
{
    return $type === \FournisseurRepository::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale';
}

function employeeSupplierDocumentBadge(string $tone): string
{
    if ($tone === 'success') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if ($tone === 'warning') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    return 'border-rose-200 bg-rose-50 text-rose-700';
}

$moraleCount = 0;
$physiqueCount = 0;
$completeCount = 0;
$localizedCount = 0;
$supplierMapItems = [];
foreach ($fournisseurs as $supplier) {
    if (($supplier['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL) {
        $physiqueCount++;
    } else {
        $moraleCount++;
    }
    if (!empty($supplier['document_is_complete'])) {
        $completeCount++;
    }

    $position = $supplierPositions[(int) ($supplier['id_fournisseur'] ?? 0)] ?? null;
    if ($position) {
        $localizedCount++;
    }

    $supplierMapItems[] = [
        'id' => (int) ($supplier['id_fournisseur'] ?? 0),
        'name' => (string) ($supplier['supplier_display_name'] ?? $supplier['nom_commercial'] ?? $supplier['raison_sociale'] ?? 'Fournisseur'),
        'type' => (string) ($supplier['supplier_type'] ?? 'morale'),
        'type_label' => (string) ($supplier['supplier_type_label'] ?? employeeSupplierTypeLabel((string) ($supplier['supplier_type'] ?? 'morale'))),
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
    ];
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <section class="supplier-overview">
            <?php supplierWorkspaceHeader('Mes fournisseurs', 'Retrouvez vos partenaires et suivez la complétude et la validation de leurs fiches.', 0); ?>

            <div class="mt-5 rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-4 text-sm text-cyan-900">
                Depuis cette liste, ouvrez chaque fiche pour la corriger, ou utilisez le lien Nouveau fournisseur pour lancer une création dans un écran séparé.
            </div>

            <?php if ($flashSuccess): ?>
                <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <?= htmlspecialchars($flashSuccess) ?>
                </div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="mt-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <?= htmlspecialchars($flashError) ?>
                </div>
            <?php endif; ?>

            <?php if (!$model->isReady()): ?>
                <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    <?= htmlspecialchars($model->getInitError() ?: 'Le module fournisseurs n\'a pas pu s\'initialiser.') ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= count($allFournisseurs) ?></div>
            </div>
            <div class="rounded-2xl border border-cyan-200 bg-cyan-50/80 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-700">Personnes physiques</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $physiqueCount ?></div>
            </div>
            <div class="rounded-2xl border border-sky-200 bg-sky-50/80 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-sky-700">Personnes morales</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $moraleCount ?></div>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50/80 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Dossiers complets</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $completeCount ?></div>
                <div class="mt-2 text-sm text-slate-600"><span id="supplier-map-count"><?= $localizedCount ?></span> localisés</div>
            </div>
        </section>

        <section class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_0.6fr]">
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-map-shell>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Géomap</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Mes fournisseurs sur la carte</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="supplier-map-fullscreen" title="Afficher en plein ecran" aria-label="Afficher en plein ecran" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white text-sm text-slate-700 hover:bg-slate-50"><i class="fas fa-expand" aria-hidden="true"></i></button>
                        <div id="supplier-map-status" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">Initialisation…</div>
                    </div>
                </div>
                <div id="supplier-map" class="h-[420px] w-full bg-slate-100"></div>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-600">Lecture rapide</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Dossier et terrain</h2>
                <div class="mt-5 space-y-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">API GPS</div>
                        <div class="mt-1"><?= !empty($positioningSettings['api_enabled']) ? 'Disponible' : 'Désactivée par l\'administration' ?>.</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">Temps réel</div>
                        <div class="mt-1"><?= !empty($positioningSettings['realtime_enabled']) ? 'La carte se rafraîchit automatiquement.' : 'Lecture statique, sans polling.' ?></div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">GPS navigateur</div>
                        <div class="mt-1"><?= !empty($positioningSettings['allow_manual_override']) ? 'Vous pouvez pousser votre position actuelle sur un fournisseur.' : 'La mise à jour manuelle est bloquée.' ?></div>
                    </div>
                </div>
            </div>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-600">Historique</p>
                    <h2 class="mt-2 text-xl font-semibold text-slate-950">Mes fournisseurs</h2>
                </div>
                <div class="text-sm text-slate-600"><?= count($fournisseurs) ?> affichés</div>
            </div>

            <form method="get" class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,280px)_auto] md:items-end md:justify-between">
                    <div>
                        <label for="status" class="block text-sm font-medium text-slate-700">Filtrer par statut comptable</label>
                        <select id="status" name="status" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-cyan-200">
                            <option value="" <?= $selectedStatus === '' ? 'selected' : '' ?>>Tous les statuts</option>
                            <option value="pending" <?= $selectedStatus === \FournisseurRepository::STATUS_PENDING ? 'selected' : '' ?>>En attente</option>
                            <option value="approved" <?= $selectedStatus === \FournisseurRepository::STATUS_APPROVED ? 'selected' : '' ?>>Validé</option>
                            <option value="rejected" <?= $selectedStatus === \FournisseurRepository::STATUS_REJECTED ? 'selected' : '' ?>>Rejeté</option>
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="rounded-xl bg-cyan-600 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-700">Appliquer</button>
                        <?php if ($selectedStatus !== ''): ?>
                            <a href="mes_fournisseurs_employe.php" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Réinitialiser</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-slate-500">
                            <th class="py-3 pr-4">Fournisseur</th>
                            <th class="py-3 pr-4">Profil</th>
                            <th class="py-3 pr-4">Catégories</th>
                            <th class="py-3 pr-4">Dossier</th>
                            <th class="py-3 pr-4">Contact</th>
                            <th class="py-3 pr-4">Localisation</th>
                            <th class="py-3 pr-4">Statut comptable</th>
                            <th class="py-3 pr-4">Motif rejet comptable</th>
                            <th class="py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$fournisseurs): ?>
                            <tr>
                                <td colspan="9" class="py-6 text-center text-slate-500">Aucun fournisseur enregistré.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($fournisseurs as $supplier): ?>
                                <?php $statusMeta = employeeSupplierStatusMeta((string) ($supplier['validation_status'] ?? \FournisseurRepository::STATUS_PENDING)); ?>
                                <?php $position = $supplierPositions[(int) ($supplier['id_fournisseur'] ?? 0)] ?? null; ?>
                                <tr class="border-b border-slate-100 text-slate-800 last:border-b-0">
                                    <td class="py-3 pr-4 font-semibold text-slate-950">
                                        <div class="flex items-start gap-3">
                                            <?php if (!empty($supplier['visual_path'])): ?>
                                                <img src="../<?= htmlspecialchars((string) $supplier['visual_path']) ?>" alt="Visuel fournisseur" class="h-12 w-12 rounded-2xl border border-slate-200 object-cover">
                                            <?php else: ?>
                                                <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-[10px] font-semibold text-slate-400">AUCUN</div>
                                            <?php endif; ?>
                                            <div>
                                                <?= htmlspecialchars((string) ($supplier['raison_sociale'] ?? '')) ?>
                                                <?php if (!empty($supplier['nom_commercial'])): ?>
                                                    <div class="text-xs font-normal text-slate-500"><?= htmlspecialchars((string) $supplier['nom_commercial']) ?></div>
                                                <?php endif; ?>
                                                <div class="mt-1 text-xs text-slate-400">Code <?= htmlspecialchars((string) ($supplier['code_fournisseur'] ?? '—')) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <div class="flex flex-col gap-2">
                                            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= ($supplier['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL ? 'border-cyan-200 bg-cyan-50 text-cyan-700' : 'border-sky-200 bg-sky-50 text-sky-700' ?>"><?= htmlspecialchars(employeeSupplierTypeLabel((string) ($supplier['supplier_type'] ?? 'morale'))) ?></span>
                                            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= !empty($supplier['actif']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500' ?>"><?= !empty($supplier['actif']) ? 'Actif' : 'Inactif' ?></span>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <?php if (!empty($supplier['category_labels'])): ?>
                                            <div class="flex flex-wrap gap-2">
                                                <?php foreach (($supplier['category_labels'] ?? []) as $label): ?>
                                                    <span class="inline-flex rounded-full border border-cyan-200 bg-cyan-50 px-2 py-1 text-xs font-semibold text-cyan-800"><?= htmlspecialchars((string) $label) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">Aucune</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <div class="flex flex-col gap-2">
                                            <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= employeeSupplierDocumentBadge((string) ($supplier['document_completion_tone'] ?? 'danger')) ?>"><?= htmlspecialchars((string) ($supplier['document_completion_label'] ?? 'Dossier incomplet')) ?></span>
                                            <div class="h-2 w-40 overflow-hidden rounded-full bg-slate-100">
                                                <div class="h-full rounded-full <?= !empty($supplier['document_is_complete']) ? 'bg-emerald-500' : ((int) ($supplier['document_completion_percent'] ?? 0) > 0 ? 'bg-amber-400' : 'bg-rose-400') ?>" style="width: <?= max(0, min(100, (int) ($supplier['document_completion_percent'] ?? 0))) ?>%;"></div>
                                            </div>
                                            <?php if (!empty($supplier['missing_document_labels'])): ?>
                                                <div class="text-xs text-amber-700">Manque: <?= htmlspecialchars(implode(', ', array_slice((array) $supplier['missing_document_labels'], 0, 3))) ?><?= count((array) $supplier['missing_document_labels']) > 3 ? '…' : '' ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <div class="space-y-1">
                                            <div class="font-medium text-slate-800"><?= htmlspecialchars((string) ($supplier['contact_nom'] ?? '—')) ?></div>
                                            <div><?= htmlspecialchars((string) ($supplier['telephone'] ?? '—')) ?></div>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($supplier['email'] ?? '—')) ?></div>
                                        </div>
                                    </td>
                                    <td class="py-3 pr-4 text-slate-600">
                                        <?php if ($position): ?>
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">GPS <?= htmlspecialchars((string) ($position['source'] ?? 'actif')) ?></span>
                                                <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($position['location_label'] ?? $supplier['adresse'] ?? 'Position disponible')) ?></div>
                                                <button type="button" data-supplier-focus="<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Voir sur carte</button>
                                            </div>
                                        <?php else: ?>
                                            <div class="space-y-2">
                                                <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Non localisé</span>
                                                <div class="text-xs text-slate-500">Adresse non géocodée ou position non remontée.</div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 pr-4"><span class="inline-flex rounded-full border px-3 py-1 text-xs font-semibold <?= $statusMeta['class'] ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                                    <td class="py-3 pr-4">
                                        <?php if (($supplier['validation_status'] ?? '') === \FournisseurRepository::STATUS_REJECTED && !empty($supplier['validation_note'])): ?>
                                            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">
                                                <?= nl2br(htmlspecialchars((string) $supplier['validation_note'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-slate-400">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3">
                                        <div class="flex flex-wrap gap-3">
                                            <a class="text-sm font-semibold text-slate-700 hover:text-slate-800" href="../fournisseur/details_fournisseur.php?id=<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>">Voir la fiche</a>
                                            <a class="text-sm font-semibold text-rose-700 hover:text-rose-800" target="_blank" href="../fournisseur/request/export_fournisseur_pdf.php?id=<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>">Exporter PDF</a>
                                            <a class="text-sm font-semibold text-cyan-700 hover:text-cyan-800" href="edit_fournisseur_employe.php?id=<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>">Modifier</a>
                                            <?php if (($supplier['validation_status'] ?? '') === \FournisseurRepository::STATUS_APPROVED): ?>
                                                <a class="text-sm font-semibold text-emerald-700 hover:text-emerald-800" href="../decaissement/formulaire_demande_decaissement.php?fournisseur_id=<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>">Utiliser dans un décaissement</a>
                                            <?php endif; ?>
                                            <?php if (!empty($positioningSettings['allow_manual_override'])): ?>
                                                <button type="button" data-supplier-live-update="<?= (int) ($supplier['id_fournisseur'] ?? 0) ?>" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">GPS actuel</button>
                                            <?php endif; ?>
                                        </div>
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
        localizedCountId: 'supplier-map-count',
        apiUrl: '../api/entity_positions.php?entity_type=supplier',
        updateUrl: '../api/entity_position_update.php',
        settings: <?= json_encode($positioningSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        items: <?= json_encode($supplierMapItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    });
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
