<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Fournisseurs';
$activeMenu = 'fournisseur';

$pdo = \Database::getConnection();
$controller = new \FournisseurController($pdo);
$model = $controller->model();

$isValidator = \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;
$canManagePositioning = \AccessManager::hasPermission('acces_admin');
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

if ($isEmployeeOnly) {
    header('Location: ../employe/mes_fournisseurs_employe.php');
    exit;
}


$_SESSION['supplier_archive_csrf'] = $_SESSION['supplier_archive_csrf'] ?? bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_action'])) {
    if (!$isValidator || $currentUserId <= 0) {
        http_response_code(403);
        exit('Archivage réservé aux gestionnaires fournisseurs.');
    }
    if (!is_string($_POST['_csrf'] ?? null) || !hash_equals($_SESSION['supplier_archive_csrf'], $_POST['_csrf'])) {
        http_response_code(403);
        exit('Session expirée. Rechargez la page.');
    }
    $action = (string) $_POST['archive_action'];
    if (!in_array($action, ['archive', 'restore'], true)) {
        http_response_code(400);
        exit('Action invalide.');
    }
    $result = $model->setArchived((int) ($_POST['supplier_id'] ?? 0), $action === 'archive', $currentUserId);
    $_SESSION['fournisseur_flash_' . (!empty($result['ok']) ? 'success' : 'error')] = !empty($result['ok'])
        ? ($action === 'archive' ? 'Fournisseur archivé. Son historique est conservé.' : 'Fournisseur réactivé.')
        : ($result['error'] ?? 'Archivage impossible.');
    header('Location: fournisseurs.php?archive=' . ($action === 'archive' ? 'active' : 'archived'));
    exit;
}

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['fournisseur_flash_success'])) {
    $flashSuccess = (string)$_SESSION['fournisseur_flash_success'];
    unset($_SESSION['fournisseur_flash_success']);
}
if (!empty($_SESSION['fournisseur_flash_error'])) {
    $flashError = (string)$_SESSION['fournisseur_flash_error'];
    unset($_SESSION['fournisseur_flash_error']);
}

$viewData = $controller->listView($_GET, $currentUserId, $isEmployeeOnly);
$fournisseurs = $viewData['fournisseurs'];
$positioningSettings = $viewData['positioningSettings'];
$supplierPositions = $viewData['supplierPositions'];
$activeFilters = $viewData['activeFilters'];

function fournisseurStatusMeta(string $status): array
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

function fournisseurTypeLabel(string $type): string
{
    return $type === \FournisseurRepository::TYPE_INDIVIDUAL ? 'Personne physique' : 'Personne morale';
}

function fournisseurDocumentBadge(string $tone): string
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
foreach ($fournisseurs as $row) {
    if (($row['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL) {
        $physiqueCount++;
    } else {
        $moraleCount++;
    }
    if (!empty($row['document_is_complete'])) {
        $completeCount++;
    }

    $supplierId = (int) ($row['id_fournisseur'] ?? 0);
    $position = $supplierPositions[$supplierId] ?? null;
    if ($position) {
        $localizedCount++;
    }

    $supplierMapItems[] = [
        'id' => $supplierId,
        'name' => (string) ($row['supplier_display_name'] ?? $row['nom_commercial'] ?? $row['raison_sociale'] ?? 'Fournisseur'),
        'type' => (string) ($row['supplier_type'] ?? 'morale'),
        'type_label' => (string) ($row['supplier_type_label'] ?? fournisseurTypeLabel((string) ($row['supplier_type'] ?? 'morale'))),
        'legal_name' => (string) ($row['raison_sociale'] ?? ''),
        'contact' => (string) ($row['contact_nom'] ?? ''),
        'telephone' => (string) ($row['telephone'] ?? ''),
        'email' => (string) ($row['email'] ?? ''),
        'address' => (string) ($row['adresse'] ?? ''),
        'categories' => array_values($row['category_labels'] ?? []),
        'document_completion_percent' => (int) ($row['document_completion_percent'] ?? 0),
        'document_completion_label' => (string) ($row['document_completion_label'] ?? ''),
        'document_is_complete' => !empty($row['document_is_complete']),
        'missing_document_labels' => array_values($row['missing_document_labels'] ?? []),
        'position' => $position,
    ];
}

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Répartition GPS et tableau', 'Localisez vos partenaires et consultez leur dossier dans la vue détaillée.', 0); ?>

        <?php if ($flashSuccess): ?>
            <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                <?= htmlspecialchars($flashSuccess) ?>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                <?= htmlspecialchars($flashError) ?>
            </div>
        <?php endif; ?>

        <?php if (!$model->isReady()): ?>
            <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($model->getInitError() ?: 'Le module fournisseurs n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <div class="mb-6 rounded-2xl border border-sky-200 bg-sky-50 px-5 py-4 text-sm text-sky-900">
            <?php if ($isEmployeeOnly): ?>
                Vous êtes dans l'espace employé: ajoutez ici vos fournisseurs, puis la comptabilité validera la fiche avant qu'elle puisse être sélectionnée dans un décaissement.
            <?php else: ?>
                Utilisez le lien Nouveau fournisseur pour ouvrir l'écran de création, puis revenez ici pour suivre le dossier documentaire et la couverture GPS de chaque fournisseur.
            <?php endif; ?>
        </div>

        <section class="mb-6 grid gap-4 md:grid-cols-4">
            <article class="rounded-3xl border border-slate-200 bg-white/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= count($fournisseurs) ?></div>
            </article>
            <article class="rounded-3xl border border-indigo-200 bg-indigo-50/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-700">Personnes morales</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $moraleCount ?></div>
            </article>
            <article class="rounded-3xl border border-cyan-200 bg-cyan-50/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-cyan-700">Personnes physiques</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $physiqueCount ?></div>
            </article>
            <article class="rounded-3xl border border-emerald-200 bg-emerald-50/90 p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Dossiers complets</div>
                <div class="mt-2 text-3xl font-semibold text-slate-950"><?= $completeCount ?></div>
                <div class="mt-2 text-sm text-slate-600"><span id="supplier-map-count"><?= $localizedCount ?></span> localisés</div>
            </article>
        </section>

        <div class="bg-white border border-gray-200 rounded-2xl p-6 shadow-sm">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-gray-900">Répertoire fournisseurs</h2>
                <div class="text-xs text-gray-500"><?= count($fournisseurs) ?> affichés</div>
            </div>
            <form id="supplierSearchForm" method="get" class="mt-4 grid gap-3 rounded-2xl bg-slate-50 p-4 md:grid-cols-[minmax(220px,1fr)_190px_auto]">
                <label class="text-sm font-semibold text-slate-700">Affichage
                    <select name="archive" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
                        <?php foreach (['active' => 'Actifs (non archivés)', 'archived' => 'Archivés', 'all' => 'Tous'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= ($activeFilters['archive'] ?? 'active') === $value ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <input name="q" value="<?=htmlspecialchars((string)($activeFilters['q']??''),ENT_QUOTES,'UTF-8')?>" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm" placeholder="Nom, code, contact, téléphone ou e-mail…">
                <select name="validation_status" class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm"><option value="">Tous les statuts</option><?php foreach(['pending'=>'En attente','approved'=>'Validés','rejected'=>'Rejetés'] as $statusValue=>$statusLabel):?><option value="<?=$statusValue?>" <?=($activeFilters['validation_status']??'')===$statusValue?'selected':''?>><?=$statusLabel?></option><?php endforeach;?></select>
                <button class="rounded-xl bg-slate-950 px-5 py-3 text-sm font-black text-white"><i class="fas fa-search mr-2"></i>Rechercher</button>
            </form>
            <div class="mt-3 flex flex-wrap justify-end gap-2"><a data-finea-export data-filter-form="supplierSearchForm" target="_blank" href="../exports/finea_module_export.php?module=fournisseurs&amp;format=pdf" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-black text-rose-700"><i class="fas fa-file-pdf mr-2"></i>PDF ISO</a><a data-finea-export data-filter-form="supplierSearchForm" href="../exports/finea_module_export.php?module=fournisseurs&amp;format=xlsx" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-black text-emerald-700"><i class="fas fa-file-excel mr-2"></i>Excel ISO</a></div>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-2 pr-4">Fournisseur</th>
                            <th class="py-2 pr-4">Profil</th>
                            <th class="py-2 pr-4">Catégories</th>
                            <th class="py-2 pr-4">Dossier</th>
                            <th class="py-2 pr-4">Contact</th>
                            <th class="py-2 pr-4">Localisation</th>
                            <th class="py-2 pr-4">Validation</th>
                            <th class="py-2">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (!$fournisseurs): ?>
                            <tr>
                                <td colspan="8" class="py-6 text-center text-gray-500">Aucun fournisseur (ou table indisponible).</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($fournisseurs as $f): ?>
                            <?php $statusMeta = fournisseurStatusMeta((string)($f['validation_status'] ?? \FournisseurRepository::STATUS_PENDING)); ?>
                            <?php $position = $supplierPositions[(int) ($f['id_fournisseur'] ?? 0)] ?? null; ?>
                            <tr class="text-gray-800">
                                <td class="py-2 pr-4 font-medium">
                                    <div class="flex items-start gap-3">
                                        <?php if (!empty($f['visual_path'])): ?>
                                            <img src="../<?= htmlspecialchars((string) $f['visual_path']) ?>" alt="Visuel fournisseur" class="h-12 w-12 rounded-xl border border-gray-200 object-cover" />
                                        <?php else: ?>
                                            <div class="flex h-12 w-12 items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 text-[10px] font-semibold text-gray-400">AUCUN</div>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-semibold text-slate-950"><?= htmlspecialchars((string)($f['supplier_display_name'] ?? $f['nom_commercial'] ?? $f['raison_sociale'])) ?></div>
                                            <?php if (!empty($f['raison_sociale'])): ?>
                                                <div class="text-xs text-gray-500"><?= htmlspecialchars((string)$f['raison_sociale']) ?></div>
                                            <?php endif; ?>
                                            <div class="mt-1 text-xs text-slate-400">Code <?= htmlspecialchars((string)($f['code_fournisseur'] ?? '—')) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <div class="flex flex-col gap-2">
                                        <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= ($f['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL ? 'border-cyan-200 bg-cyan-50 text-cyan-700' : 'border-indigo-200 bg-indigo-50 text-indigo-700' ?>"><?= htmlspecialchars(fournisseurTypeLabel((string) ($f['supplier_type'] ?? 'morale'))) ?></span>
                                        <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= !empty($f['actif']) ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-50 text-slate-500' ?>"><?= !empty($f['archived_at']) ? 'Archivé' : (!empty($f['actif']) ? 'Actif' : 'Inactif') ?></span>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <?php if (!empty($f['category_labels'])): ?>
                                        <div class="flex flex-wrap gap-2">
                                            <?php foreach (($f['category_labels'] ?? []) as $label): ?>
                                                <span class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-2 py-1 text-xs font-semibold text-sky-700"><?= htmlspecialchars((string) $label) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-gray-400">Aucune</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <div class="flex flex-col gap-2">
                                        <span class="inline-flex w-fit rounded-full border px-3 py-1 text-xs font-semibold <?= fournisseurDocumentBadge((string) ($f['document_completion_tone'] ?? 'danger')) ?>"><?= htmlspecialchars((string) ($f['document_completion_label'] ?? 'Dossier incomplet')) ?></span>
                                        <div class="h-2 w-40 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full <?= !empty($f['document_is_complete']) ? 'bg-emerald-500' : ((int) ($f['document_completion_percent'] ?? 0) > 0 ? 'bg-amber-400' : 'bg-rose-400') ?>" style="width: <?= max(0, min(100, (int) ($f['document_completion_percent'] ?? 0))) ?>%;"></div>
                                        </div>
                                        <?php if (!empty($f['missing_document_labels'])): ?>
                                            <div class="text-xs text-amber-700">Manque: <?= htmlspecialchars(implode(', ', array_slice((array) $f['missing_document_labels'], 0, 3))) ?><?= count((array) $f['missing_document_labels']) > 3 ? '…' : '' ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <div class="space-y-1">
                                        <div class="font-medium text-slate-800"><?= htmlspecialchars((string)($f['contact_nom'] ?? '—')) ?></div>
                                        <div><?= htmlspecialchars((string)($f['telephone'] ?? '—')) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($f['email'] ?? '—')) ?></div>
                                    </div>
                                </td>
                                <td class="py-2 pr-4 text-gray-600">
                                    <?php if ($position): ?>
                                        <div class="space-y-2">
                                            <span class="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700">GPS <?= htmlspecialchars((string) ($position['source'] ?? 'actif')) ?></span>
                                            <div class="text-xs text-slate-500"><?= htmlspecialchars((string) ($position['location_label'] ?? $f['adresse'] ?? 'Position disponible')) ?></div>
                                            <button type="button" data-supplier-focus="<?= (int) ($f['id_fournisseur'] ?? 0) ?>" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Voir sur carte</button>
                                        </div>
                                    <?php else: ?>
                                        <div class="space-y-2">
                                            <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">Non localisé</span>
                                            <div class="text-xs text-slate-500">Adresse non géocodée ou position non remontée.</div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2 pr-4 text-gray-600"><span class="inline-flex rounded-full border px-2 py-1 text-xs font-semibold <?= $statusMeta['class'] ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                                <td class="py-2">
                                    <div class="flex flex-col items-start gap-2">
                                        <?php if (\AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_point_financier')): ?>
                                        <a class="text-indigo-700 hover:underline" href="decaissements.php?id=<?= (int)$f['id_fournisseur'] ?>">Décaissements</a>
                                        <?php endif; ?>

                                        <?php if ($isValidator): ?>
                                        <form method="post" action="fournisseurs.php" onsubmit="return confirm('Confirmer cette action sur le fournisseur ? Son historique sera conservé.');">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['supplier_archive_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="supplier_id" value="<?= (int)$f['id_fournisseur'] ?>">
                                            <input type="hidden" name="archive_action" value="<?= empty($f['archived_at']) ? 'archive' : 'restore' ?>">
                                            <button class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700"><?= empty($f['archived_at']) ? 'Archiver' : 'Réactiver' ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <a class="text-slate-700 hover:underline" href="details_fournisseur.php?id=<?= (int)$f['id_fournisseur'] ?>">Voir la fiche</a>
                                        <a class="text-rose-700 hover:underline" target="_blank" href="request/export_fournisseur_pdf.php?id=<?= (int)$f['id_fournisseur'] ?>">Exporter PDF</a>
                                        <a class="text-indigo-700 hover:underline" href="edit_fournisseur.php?id=<?= (int)$f['id_fournisseur'] ?>"><?= empty($f['document_is_complete']) ? 'Normaliser' : 'Modifier' ?></a>
                                        <a class="text-slate-700 hover:underline" href="edit_fournisseur.php?id=<?= (int)$f['id_fournisseur'] ?>#incidents">Incidents</a>
                                        <?php if (!empty($positioningSettings['allow_manual_override'])): ?>
                                            <button type="button" data-supplier-live-update="<?= (int) ($f['id_fournisseur'] ?? 0) ?>" class="inline-flex rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">GPS actuel</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <section id="repartition-gps" class="mt-6 mb-6 grid gap-6 xl:grid-cols-[1.25fr_0.75fr]">
            <div class="overflow-hidden rounded-[28px] border border-slate-200 bg-white/95 shadow-sm" data-map-shell>
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Géomap fournisseurs</p>
                        <h2 class="mt-2 text-xl font-semibold text-slate-950">Couverture GPS en temps réel</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="supplier-map-fullscreen" title="Afficher en plein ecran" aria-label="Afficher en plein ecran" class="inline-flex h-8 w-8 items-center justify-center rounded-full border border-slate-200 bg-white text-sm text-slate-700 hover:bg-slate-50"><i class="fas fa-expand" aria-hidden="true"></i></button>
                        <div id="supplier-map-status" class="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-600">Initialisation…</div>
                    </div>
                </div>
                <div id="supplier-map" class="h-[420px] w-full bg-slate-100"></div>
            </div>
            <div class="rounded-[28px] border border-slate-200 bg-white/95 p-6 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-indigo-600">Lecture rapide</p>
                <h2 class="mt-2 text-xl font-semibold text-slate-950">Repères de terrain</h2>
                <div class="mt-5 space-y-3">
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">API GPS</div>
                        <div class="mt-1"><?= !empty($positioningSettings['api_enabled']) ? 'Active' : 'Désactivée' ?> avec polling toutes les <?= (int) ($positioningSettings['poll_interval_seconds'] ?? 30) ?> secondes.</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">Géocodage gratuit</div>
                        <div class="mt-1">Provider configuré: Nominatim. Les adresses sont traduites en coordonnées puis stockées localement.</div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                        <div class="font-semibold text-slate-900">Position navigateur</div>
                        <div class="mt-1"><?= !empty($positioningSettings['allow_manual_override']) ? 'Autorisée' : 'Bloquée' ?>. Utilisez “GPS actuel” sur une ligne pour remonter votre position courante vers le fournisseur concerné.</div>
                    </div>
                </div>
            </div>
        </section>
        <script src="../assets/js/finea-filtered-export.js"></script>


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

