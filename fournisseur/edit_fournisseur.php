<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__.'/../services/SupplierExceptionPolicy.php';
if (!SupplierExceptionPolicy::allowed()) \AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FournisseurController.php';

$pageTitle = 'Modifier fournisseur';
$activeMenu = 'fournisseur';

$pdo = \Database::getConnection();
$controller = new \FournisseurController($pdo);
$model = $controller->model();

$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$isValidator = SupplierExceptionPolicy::allowed() || \AccessManager::hasPermission('acces_compta') || \AccessManager::hasPermission('acces_admin') || \AccessManager::hasPermission('acces_fournisseur');
$isEmployeeOnly = \AccessManager::hasPermission('acces_employe') && !$isValidator;

$id = (int)(($_GET['id'] ?? $_POST['id'] ?? 0));

if ($isEmployeeOnly) {
    header('Location: ../employe/edit_fournisseur_employe.php' . ($id > 0 ? ('?id=' . $id) : ''));
    exit;
}

$listRedirect = 'fournisseurs.php';
$editRedirect = 'edit_fournisseur.php?id=' . $id;

$viewData = $controller->editView($id);
$fournisseur = $viewData['fournisseur'];
$incidents = $viewData['incidents'];
$supplierLocationPickerSettings = $viewData['supplierLocationPickerSettings'];
$supplierLocationPickerInitialPosition = $viewData['supplierLocationPickerInitialPosition'];

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

if (!$model->isReady()) {
    $flashError = $flashError ?: ($model->getInitError() ?: 'Module indisponible.');
}

if ($fournisseur && SupplierExceptionPolicy::isException($fournisseur) && !SupplierExceptionPolicy::allowed()) {
    http_response_code(403); exit('La modification de ce fournisseur dérogatoire est réservée à la comptabilité, au DG et aux administrateurs.');
}

if ($fournisseur && !$isValidator && (int)($fournisseur['created_by_user_id'] ?? 0) !== $currentUserId) {
    $_SESSION['fournisseur_flash_error'] = 'Vous ne pouvez modifier que vos propres fournisseurs.';
    header('Location: ' . $listRedirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($id <= 0 || !$fournisseur) {
        $_SESSION['fournisseur_flash_error'] = 'Fournisseur introuvable.';
        header('Location: ' . $listRedirect);
        exit;
    }

    $postResult = $controller->handleEditPost($id, $fournisseur, $_POST, $_FILES, $currentUserId, $isValidator);
    if (!empty($postResult['flash'])) {
        [$flashType, $flashMessage] = $postResult['flash'];
        $_SESSION['fournisseur_flash_' . $flashType] = $flashMessage;
    }
    header('Location: ' . (string) ($postResult['redirect'] ?? $editRedirect));
    exit;
}
$categoryOptions = $viewData['categoryOptions'];
$legalFormOptions = $viewData['legalFormOptions'];
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="supplier-page-bg">
    <div class="supplier-directory">
        <?php supplierWorkspaceHeader('Normaliser la fiche fournisseur', 'Complétez l’identité officielle, les coordonnées et les documents du partenaire.', (int)($id ?? 0)); ?>

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

        <?php if (!$fournisseur): ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-6 text-sm text-gray-700">Fournisseur introuvable.</div>
        <?php else: ?>
            <?php
            $status = (string)($fournisseur['validation_status'] ?? \FournisseurRepository::STATUS_PENDING);
            $statusMap = [
                \FournisseurRepository::STATUS_PENDING => ['label' => 'En attente', 'class' => 'border-amber-200 bg-amber-50 text-amber-700'],
                \FournisseurRepository::STATUS_APPROVED => ['label' => 'Validé', 'class' => 'border-emerald-200 bg-emerald-50 text-emerald-700'],
                \FournisseurRepository::STATUS_REJECTED => ['label' => 'Rejeté', 'class' => 'border-rose-200 bg-rose-50 text-rose-700'],
            ];
            $statusBadge = $statusMap[$status] ?? $statusMap[\FournisseurRepository::STATUS_PENDING];
            ?>
            <div class="bg-white border border-gray-200 rounded-2xl p-6">
                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <h2 class="text-lg font-semibold text-gray-900">Fiche</h2>
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="inline-flex rounded-full border px-3 py-1 font-semibold <?= $statusBadge['class'] ?>"><?= htmlspecialchars($statusBadge['label']) ?></span>
                        <?php if (!empty($fournisseur['validated_at'])): ?>
                            <span class="text-gray-500">Dernière décision: <?= htmlspecialchars((string)$fournisseur['validated_at']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" class="mt-4 space-y-5">
                    <?php $businessClientForm=false;$supplierFiscalIdentity=$fournisseur;require __DIR__.'/../partials/business_identity_entry.php'; ?>
                    <input type="hidden" name="action" value="update" />
                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Identité</h3>
                            <div class="text-xs text-gray-500">Fournisseur #<?= (int)$fournisseur['id_fournisseur'] ?></div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4" data-supplier-edit-root>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Type de fournisseur *</label>
                                <div class="mt-2 flex flex-wrap gap-3">
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700">
                                        <input type="radio" name="supplier_type" value="morale" <?= ($fournisseur['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_COMPANY ? 'checked' : '' ?> class="rounded border-gray-300" data-supplier-type-control />
                                        Personne morale
                                    </label>
                                    <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2 text-sm text-gray-700">
                                        <input type="radio" name="supplier_type" value="physique" <?= ($fournisseur['supplier_type'] ?? 'morale') === \FournisseurRepository::TYPE_INDIVIDUAL ? 'checked' : '' ?> class="rounded border-gray-300" data-supplier-type-control />
                                        Personne physique
                                    </label>
                                </div>
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Forme juridique *</label>
                                <select name="raison_sociale" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" data-required-on="morale">
                                    <option value="">Sélectionner une forme juridique</option>
                                    <?php foreach ($legalFormOptions as $legalForm): ?>
                                        <option value="<?= htmlspecialchars((string) ($legalForm['label'] ?? '')) ?>" <?= (string)($fournisseur['raison_sociale'] ?? '') === (string)($legalForm['label'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($legalForm['label'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!empty($fournisseur['raison_sociale']) && !in_array((string) $fournisseur['raison_sociale'], array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), $legalFormOptions), true)): ?>
                                        <option value="<?= htmlspecialchars((string) $fournisseur['raison_sociale']) ?>" selected><?= htmlspecialchars((string) $fournisseur['raison_sociale']) ?> (valeur historique)</option>
                                    <?php endif; ?>
                                </select>
                                <div class="mt-1 text-xs text-gray-500">Liste paramétrable depuis <a href="formes_juridiques.php" class="font-semibold underline">le référentiel comptable</a>.</div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Code fournisseur</label>
                                <input name="code_fournisseur" readonly value="<?= htmlspecialchars((string)($fournisseur['code_fournisseur'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-gray-100 px-3 py-2 text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <div class="mt-1 text-xs text-gray-500">Champ non modifiable.</div>
                            </div>
                            <div class="md:col-span-2" data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Nom commercial / nom affiché *</label>
                                <input name="nom_commercial" value="<?= htmlspecialchars((string)($fournisseur['nom_commercial'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" data-required-on="morale" />
                            </div>
                            <div data-supplier-type-section="physique">
                    <label class="text-sm font-semibold">Numéro de la pièce d’identité *</label>
                    <input name="identity_document_number" maxlength="110" value="<?= htmlspecialchars((string)($fournisseur['identity_document_number']??'')) ?>" data-required-on="physique" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" autocomplete="off">
                    <p data-person-identity-message role="status" class="mt-2 text-sm text-red-700">Le personnel ne peut pas être enregistré comme fournisseur.</p>
                </div>
                <div data-supplier-type-section="physique">
                                <label class="block text-sm font-medium text-gray-700">Nom *</label>
                                <input name="person_last_name" value="<?= htmlspecialchars((string)($fournisseur['person_last_name'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" data-required-on="physique" />
                            </div>
                            <div data-supplier-type-section="physique">
                                <label class="block text-sm font-medium text-gray-700">Prénom *</label>
                                <input name="person_first_name" value="<?= htmlspecialchars((string)($fournisseur['person_first_name'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" data-required-on="physique" />
                            </div>
                            <div class="md:col-span-2" data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Gérant / représentant légal *</label>
                                <input name="contact_nom" value="<?= htmlspecialchars((string)($fournisseur['contact_nom'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" data-required-on="morale" />
                            </div>
                            <div class="md:col-span-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800" data-supplier-type-section="physique">
                                Le nom affiché est généré automatiquement à partir du nom et du prénom pour une personne physique.
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Catégories métier</h3>
                            <div class="text-xs text-gray-500">Alternatives et regroupements</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <?php foreach ($categoryOptions as $category): ?>
                                <?php $checked = in_array((int) ($category['id_category'] ?? 0), array_map('intval', $fournisseur['category_ids'] ?? []), true); ?>
                                <label class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700">
                                    <input type="checkbox" name="category_ids[]" value="<?= (int) ($category['id_category'] ?? 0) ?>" <?= $checked ? 'checked' : '' ?> class="rounded border-gray-300" />
                                    <?= htmlspecialchars((string) ($category['label'] ?? '')) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-4 rounded-xl border border-dashed border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                            Pour créer ou désactiver une catégorie, utilisez <a href="categories.php" class="font-semibold underline">le paramétrage dédié</a>. Les formes juridiques se gèrent dans <a href="formes_juridiques.php" class="font-semibold underline">le référentiel dédié</a>.
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Identifiants fiscaux</h3>
                            <div class="text-xs text-gray-500">Côte d’Ivoire : NCC · France : SIRET / SIREN</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">NCC</label>
                                <input name="ncc" value="<?= htmlspecialchars((string)($fournisseur['ncc'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">RNE</label>
                                <input name="rne" value="<?= htmlspecialchars((string)($fournisseur['rne'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                                <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                    <input type="checkbox" name="is_rne" value="1" <?= !empty($fournisseur['is_rne']) ? 'checked' : '' ?> class="rounded border-gray-300" />
                                    RNE complémentaire (ne remplace pas le NCC)
                                </label>
                            </div>
                        </div>
                        <?php $supplierFiscalIdentity = $fournisseur; require __DIR__ . '/../partials/supplier_french_identity.php'; ?>
            <div class="supplier-fne-box mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4" data-supplier-fne <?= ($fournisseur['supplier_type'] ?? 'morale') === 'physique' ? 'hidden' : '' ?>>
                            <strong class="block text-sm font-semibold text-emerald-900"><i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>Identité officielle · FNE / DGI</strong>
                            <button type="button" data-fne-verify disabled class="mt-3 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">
                                <i class="fas fa-search" aria-hidden="true"></i> Vérifier le NCC et remplir la fiche
                            </button>
                            <p data-fne-status aria-live="polite" class="mt-2 text-sm text-emerald-800">NCC obligatoire. La dénomination et les informations fiscales proviennent exclusivement de la FNE. La vérification sera renouvelée à l’enregistrement.</p>
                            <span data-fne-loading class="mt-1 block text-xs text-gray-600">Chargement de la vérification…</span>
                            <noscript><p class="mt-2 text-sm text-amber-800">Activez JavaScript pour vérifier le NCC auprès de la FNE.</p></noscript>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Contact & adresse</h3>
                            <div class="text-xs text-gray-500">Coordonnées</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Mails / email *</label>
                                <input name="email" type="text" value="<?= htmlspecialchars((string)($fournisseur['email'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Téléphone *</label>
                                <input name="telephone" value="<?= htmlspecialchars((string)($fournisseur['telephone'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" required />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Ville</label>
                                <input name="ville" value="<?= htmlspecialchars((string)($fournisseur['ville'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Pays</label>
                                <input name="pays" value="<?= htmlspecialchars((string)($fournisseur['pays'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                            </div>
                            <div class="md:col-span-2 rounded-3xl border border-gray-200 bg-white p-4 shadow-sm" data-supplier-location-root>
                                <div class="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
                                    <div>
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">Recherche intelligente de localisation</div>
                                                <div class="mt-1 text-xs text-gray-500">Recherchez puis sélectionnez une adresse pour la valider immédiatement sur la carte.</div>
                                            </div>
                                            <button type="button" data-location-current class="inline-flex items-center rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-100">
                                                <i class="fas fa-location-crosshairs mr-2 text-indigo-600"></i>
                                                Position actuelle
                                            </button>
                                        </div>
                                        <div class="mt-4">
                                            <label class="block text-sm font-medium text-gray-700">Recherche / autocomplétion</label>
                                            <input type="text" data-location-search autocomplete="off" spellcheck="false" value="<?= htmlspecialchars((string)($fournisseur['adresse'] ?? '')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" placeholder="Ex: Marcory Zone 4, Abidjan, Côte d'Ivoire" />
                                        </div>
                                        <div class="mt-3 space-y-2" data-location-results></div>
                                        <div class="mt-4 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="text-xs font-semibold uppercase tracking-[0.2em] text-gray-500">Sélection confirmée</div>
                                                <span data-location-source-badge class="inline-flex rounded-full border border-gray-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-gray-500">Aucune source</span>
                                            </div>
                                            <div class="mt-2 font-medium text-gray-900" data-location-selection-label><?= htmlspecialchars((string)($fournisseur['adresse'] ?? 'Aucune localisation confirmée.')) ?></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex items-center justify-between gap-3">
                                            <div>
                                                <div class="text-sm font-semibold text-gray-900">Prévisualisation carte</div>
                                                <div class="mt-1 text-xs text-gray-500">La carte est centrée sur la position enregistrée ou la suggestion choisie, puis ajustable bâtiment par bâtiment.</div>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" data-location-fullscreen title="Afficher en plein ecran" aria-label="Afficher en plein ecran" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-gray-200 bg-white text-sm text-gray-700 hover:bg-gray-50"><i class="fas fa-expand" aria-hidden="true"></i></button>
                                                <button type="button" data-location-recenter class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Recentrer</button>
                                                <button type="button" data-location-clear class="inline-flex items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50">Réinitialiser</button>
                                            </div>
                                        </div>
                                        <div data-location-map class="mt-4 h-[290px] overflow-hidden rounded-3xl border border-gray-200 bg-gray-100"></div>
                                        <div data-location-coordinates class="mt-3 rounded-2xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs font-medium text-gray-700">Coordonnées non fixées</div>
                                        <div data-location-status class="mt-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 text-xs text-gray-600">Saisissez ou sélectionnez une localisation pour afficher la carte fournisseur.</div>
                                    </div>
                                </div>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Adresse / localisation *</label>
                                <textarea name="adresse" rows="2" autocomplete="off" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" required><?= htmlspecialchars((string)($fournisseur['adresse'] ?? '')) ?></textarea>
                                <input type="hidden" name="location_latitude" value="<?= htmlspecialchars((string) ($supplierLocationPickerInitialPosition['latitude'] ?? '')) ?>">
                                <input type="hidden" name="location_longitude" value="<?= htmlspecialchars((string) ($supplierLocationPickerInitialPosition['longitude'] ?? '')) ?>">
                                <input type="hidden" name="location_source" value="<?= htmlspecialchars((string) ($supplierLocationPickerInitialPosition['source'] ?? '')) ?>">
                                <input type="hidden" name="location_label" value="<?= htmlspecialchars((string) ($supplierLocationPickerInitialPosition['location_label'] ?? '')) ?>">
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Pièces justificatives</h3>
                            <div class="text-xs text-gray-500">Une pièce par document</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2" data-supplier-edit-root>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Logo société</label>
                                <?php if (!empty($fournisseur['logo_path'])): ?>
                                    <img src="../<?= htmlspecialchars((string) $fournisseur['logo_path']) ?>" alt="Logo fournisseur" class="mt-2 h-24 w-24 rounded-xl border border-gray-200 object-cover" />
                                <?php endif; ?>
                                <input type="file" name="supplier_logo" accept=".png,.jpg,.jpeg,.webp" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="physique">
                                <label class="block text-sm font-medium text-gray-700">Photo fournisseur</label>
                                <?php if (!empty($fournisseur['photo_path'])): ?>
                                    <img src="../<?= htmlspecialchars((string) $fournisseur['photo_path']) ?>" alt="Photo fournisseur" class="mt-2 h-24 w-24 rounded-xl border border-gray-200 object-cover" />
                                <?php endif; ?>
                                <input type="file" name="supplier_photo" accept=".png,.jpg,.jpeg,.webp" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="physique">
                                <label class="block text-sm font-medium text-gray-700">CNI / pièce d'identité</label>
                                <?php if (!empty($fournisseur['cni_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700">
                                        <a href="../<?= htmlspecialchars((string) $fournisseur['cni_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir la pièce jointe actuelle</a>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="supplier_cni" data-existing-file="<?= empty($fournisseur['cni_path'])?'':'1' ?>" <?= empty($fournisseur['cni_path'])?'data-required-on="physique"':'' ?> accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Registre de commerce — facultatif</label>
                                <?php if (!empty($fournisseur['trade_register_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700"><a href="../<?= htmlspecialchars((string) $fournisseur['trade_register_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir le document actuel</a></div>
                                <?php endif; ?>
                                <input type="file" name="supplier_trade_register" accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Déclaration fiscale d'existence — facultative</label>
                                <?php if (!empty($fournisseur['tax_existence_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700"><a href="../<?= htmlspecialchars((string) $fournisseur['tax_existence_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir le document actuel</a></div>
                                <?php endif; ?>
                                <input type="file" name="supplier_tax_existence" accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Attestation de régularité fiscale — facultative</label>
                                <?php if (!empty($fournisseur['tax_clearance_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700"><a href="../<?= htmlspecialchars((string) $fournisseur['tax_clearance_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir le document actuel</a></div>
                                <?php endif; ?>
                                <input type="file" name="supplier_tax_clearance" accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Attestation de régularité sociale — facultative</label>
                                <?php if (!empty($fournisseur['social_clearance_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700"><a href="../<?= htmlspecialchars((string) $fournisseur['social_clearance_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir le document actuel</a></div>
                                <?php endif; ?>
                                <input type="file" name="supplier_social_clearance" accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                            <div data-supplier-type-section="morale">
                                <label class="block text-sm font-medium text-gray-700">Pièce du représentant légal — facultative</label>
                                <?php if (!empty($fournisseur['manager_cni_path'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-3 text-sm text-gray-700"><a href="../<?= htmlspecialchars((string) $fournisseur['manager_cni_path']) ?>" target="_blank" class="font-semibold text-indigo-700 hover:text-indigo-800">Voir le document actuel</a></div>
                                <?php endif; ?>
                                <input type="file" name="supplier_manager_cni" accept=".png,.jpg,.jpeg,.webp,.pdf" class="mt-3 block w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700" />
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-between gap-3">
                            <h3 class="text-sm font-semibold text-gray-900">Validation workflow</h3>
                            <div class="text-xs text-gray-500">Comptabilité / admin</div>
                        </div>
                        <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <div class="text-sm text-gray-600">Statut actuel</div>
                                <script src="../js/supplier-location-picker.js?v=<?= filemtime(__DIR__ . '/../js/supplier-location-picker.js') ?>"></script>
                                <script>
                                    window.initSupplierLocationPicker({
                                        rootSelector: '[data-supplier-location-root]',
                                        apiUrl: '../api/location_lookup.php',
                                        settings: <?= json_encode($supplierLocationPickerSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                                        initialAddress: <?= json_encode((string) ($fournisseur['adresse'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                                        initialPosition: <?= json_encode($supplierLocationPickerInitialPosition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
                                    });
                                </script>

                                <div class="mt-2 text-base font-semibold text-gray-900"><?= htmlspecialchars($statusBadge['label']) ?></div>
                                <?php if (!empty($fournisseur['validation_note'])): ?>
                                    <div class="mt-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700"><?= nl2br(htmlspecialchars((string)$fournisseur['validation_note'])) ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Note de validation</label>
                                <textarea name="validation_note" rows="3" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" <?= $isValidator ? '' : 'readonly' ?>><?= htmlspecialchars((string)($fournisseur['validation_note'] ?? '')) ?></textarea>
                                <div class="mt-2 text-xs text-gray-500">Le fournisseur doit être validé avant sélection dans une fiche de décaissement.</div>
                            </div>
                        </div>
                        <?php if ($isValidator): ?>
                            <div class="mt-4 flex flex-wrap gap-3">
                                <button type="submit" name="action" value="approve" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">Valider</button>
                                <button type="submit" name="action" value="reject" class="px-4 py-2 rounded-xl bg-rose-600 text-white hover:bg-rose-700">Rejeter</button>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center justify-between rounded-2xl border border-gray-200 bg-white p-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" name="actif" value="1" <?= !empty($fournisseur['actif']) ? 'checked' : '' ?> class="rounded border-gray-300" />
                            Actif
                        </label>
                        <button class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-700">Enregistrer</button>
                    </div>
                </form>
            </div>

            <div id="incidents" class="mt-6 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
                <div class="rounded-2xl border border-gray-200 bg-white p-6">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold text-gray-900">Incidents fournisseur</h2>
                        <span class="text-sm text-gray-500"><?= count($incidents) ?> incident(s)</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        <?php if (!$incidents): ?>
                            <div class="rounded-xl border border-dashed border-gray-300 px-4 py-4 text-sm text-gray-500">Aucun incident enregistré.</div>
                        <?php endif; ?>
                        <?php foreach ($incidents as $incident): ?>
                            <div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-4">
                                <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <div class="font-semibold text-gray-900"><?= htmlspecialchars((string)$incident['titre']) ?></div>
                                        <div class="mt-1 text-xs uppercase tracking-wide text-gray-500"><?= htmlspecialchars((string)$incident['incident_date']) ?> · <?= htmlspecialchars(strtoupper((string)$incident['gravite'])) ?></div>
                                    </div>
                                    <span class="inline-flex rounded-full border border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-700"><?= htmlspecialchars((string)$incident['statut']) ?></span>
                                </div>
                                <div class="mt-3 text-sm text-gray-700"><?= nl2br(htmlspecialchars((string)$incident['description'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rounded-2xl border border-gray-200 bg-white p-6">
                    <h2 class="text-lg font-semibold text-gray-900">Déclarer un incident</h2>
                    <form method="post" class="mt-4 space-y-4">
                        <input type="hidden" name="action" value="add_incident" />
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date</label>
                            <input type="date" name="incident_date" value="<?= htmlspecialchars(date('Y-m-d')) ?>" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Titre</label>
                            <input name="titre" required class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Gravité</label>
                            <select name="gravite" class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200">
                                <option value="low">Faible</option>
                                <option value="medium" selected>Moyenne</option>
                                <option value="high">Élevée</option>
                                <option value="critical">Critique</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Description</label>
                            <textarea name="description" rows="5" required class="mt-1 w-full rounded-xl border border-gray-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-200"></textarea>
                        </div>
                        <button class="w-full px-4 py-2 rounded-xl bg-slate-900 text-white hover:bg-slate-800">Enregistrer l'incident</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-supplier-edit-root]').forEach(function(root) {
            const controls = document.querySelectorAll('[data-supplier-type-control]');
            const sections = document.querySelectorAll('[data-supplier-type-section]');
            const fields = document.querySelectorAll('[data-required-on]');

            function currentType() {
                const checked = document.querySelector('[data-supplier-type-control]:checked');
                return checked ? checked.value : 'morale';
            }

            function sync() {
                const type = currentType();
                sections.forEach(function(section) {
                    section.style.display = section.getAttribute('data-supplier-type-section') === type ? '' : 'none';
                });
                fields.forEach(function(field) {
                    const allowed = (field.getAttribute('data-required-on') || '').split(',').map(function(value) {
                        return value.trim();
                    }).filter(Boolean);
                    field.required = allowed.includes(type);
                });
            }

            controls.forEach(function(control) {
                control.addEventListener('change', sync);
            });

            sync();
        });
    });
</script>
<script src="../assets/js/supplier-fne.js?v=<?= filemtime(__DIR__ . '/../assets/js/supplier-fne.js') ?>" defer></script>
<?php $supplierFneScriptLoaded = true; ?>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>


