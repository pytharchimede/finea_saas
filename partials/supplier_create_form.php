<?php
$supplierCreateTheme = array_merge([
    'accent_label_class' => 'text-cyan-600',
    'accent_panel_class' => 'border-cyan-200 bg-cyan-50 text-cyan-900',
    'accent_ring_class' => 'focus:ring-cyan-200',
    'accent_button_class' => 'bg-cyan-600 hover:bg-cyan-700 text-white',
    'muted_button_class' => 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50',
    'accent_border_class' => 'border-cyan-200',
    'accent_surface_class' => 'bg-cyan-50',
], $supplierCreateTheme ?? []);

$supplierCreateHeading = (string) ($supplierCreateHeading ?? 'Nouveau fournisseur');
$supplierCreateEyebrow = (string) ($supplierCreateEyebrow ?? 'Création');
$supplierCreateDescription = (string) ($supplierCreateDescription ?? 'Préparez un fournisseur complet avant validation comptable.');
$supplierCreateIntro = (string) ($supplierCreateIntro ?? 'Chaque pièce obligatoire dispose désormais de sa propre zone de dépôt avec aperçu.');
$supplierCreateLegend = (string) ($supplierCreateLegend ?? 'X rouge = champ obligatoire');
$supplierCreateSubmitLabel = (string) ($supplierCreateSubmitLabel ?? 'Créer le fournisseur');
$supplierCreateCancelHref = (string) ($supplierCreateCancelHref ?? '');
$supplierCreateCancelLabel = (string) ($supplierCreateCancelLabel ?? 'Retour à la liste');
$supplierCreateCodeValue = (string) ($supplierCreateCodeValue ?? '');
$supplierCreateCodePlaceholder = (string) ($supplierCreateCodePlaceholder ?? 'Auto');
$supplierCreateCodeHint = (string) ($supplierCreateCodeHint ?? 'Généré automatiquement.');
$supplierCreateHiddenFields = is_array($supplierCreateHiddenFields ?? null) ? $supplierCreateHiddenFields : [];
$supplierCreateExistingFiles = is_array($supplierCreateExistingFiles ?? null) ? $supplierCreateExistingFiles : [];
$supplierCreateCategoryNotice = (string) ($supplierCreateCategoryNotice ?? '');
$supplierCreateAssetHrefPrefix = (string) ($supplierCreateAssetHrefPrefix ?? '../');
$supplierLocationPickerSettings = $supplierLocationPickerSettings ?? [
    'api_enabled' => true,
    'tile_url' => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    'tile_attribution' => '&copy; OpenStreetMap contributors',
    'map_center_lat' => 5.348,
    'map_center_lng' => -4.03,
    'map_zoom' => 6,
];
$supplierLocationPickerInitialPosition = $supplierLocationPickerInitialPosition ?? null;
$supplierCreateOld = is_array($supplierCreateOld ?? null) ? $supplierCreateOld : [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $supplierCreateOld = array_replace($supplierCreateOld, $_POST);
}
$legalFormOptionLabels = array_map(static fn(array $row): string => (string) ($row['label'] ?? ''), $legalFormOptions ?? []);

$oldValue = static function (string $key, $default = '') use ($supplierCreateOld): string {
    if (array_key_exists($key, $supplierCreateOld)) {
        return htmlspecialchars((string) $supplierCreateOld[$key]);
    }

    return htmlspecialchars((string) $default);
};

$oldType = (string) ($supplierCreateOld['supplier_type'] ?? 'morale');
$selectedCategories = array_map('intval', is_array($supplierCreateOld['category_ids'] ?? null) ? $supplierCreateOld['category_ids'] : []);

$renderRequiredBadge = static function (string $requiredOn): void {
?>
    <span class="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-100 px-1.5 text-[11px] font-bold leading-none text-rose-700" data-required-indicator data-required-on="<?= htmlspecialchars($requiredOn) ?>" title="Champ obligatoire">X</span>
<?php
};

$renderFieldLabel = static function (string $text, ?string $requiredOn = null, array $attributes = []) use ($renderRequiredBadge): void {
    $attributeString = '';
    foreach ($attributes as $attributeName => $attributeValue) {
        $attributeString .= ' ' . htmlspecialchars((string) $attributeName) . '="' . htmlspecialchars((string) $attributeValue) . '"';
    }
?>
    <label class="block text-sm font-medium text-slate-700" <?= $attributeString ?>>
        <span><?= htmlspecialchars($text) ?></span>
        <?php if ($requiredOn !== null && $requiredOn !== ''): ?>
            <?php $renderRequiredBadge($requiredOn); ?>
        <?php endif; ?>
    </label>
<?php
};

$renderExistingFile = static function (string $path, string $label) use ($supplierCreateAssetHrefPrefix): void {
    $cleanPath = ltrim($path, '/');
    $href = $supplierCreateAssetHrefPrefix . $cleanPath;
    $isImage = (bool) preg_match('/\.(png|jpe?g|webp)$/i', $cleanPath);
?>
    <div class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 p-3">
        <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Fichier actuel</div>
        <?php if ($isImage): ?>
            <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="mt-3 block">
                <img src="<?= htmlspecialchars($href) ?>" alt="<?= htmlspecialchars($label) ?>" class="h-24 w-24 rounded-2xl border border-slate-200 object-cover">
            </a>
        <?php else: ?>
            <a href="<?= htmlspecialchars($href) ?>" target="_blank" class="mt-3 inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-cyan-700 hover:text-cyan-800">
                Voir le document actuel
            </a>
        <?php endif; ?>
    </div>
<?php
};

$renderDropzone = static function (string $name, string $label, string $hint, string $accept, string $icon, bool $requiredForCompany, bool $requiredForIndividual) use ($supplierCreateExistingFiles, $renderRequiredBadge, $renderExistingFile) {
    $existingPath = (string) (($supplierCreateExistingFiles[$name]['path'] ?? '') ?: '');
    $requiredOn = [];
    if ($requiredForCompany) {
        $requiredOn[] = 'morale';
    }
    if ($requiredForIndividual) {
        $requiredOn[] = 'physique';
    }
    $requiredOnValue = implode(',', $requiredOn);
?>
    <div class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm" data-dropzone-card>
        <div class="flex items-start justify-between gap-3">
            <div>
                <div class="flex items-center text-sm font-semibold text-slate-900">
                    <span><?= htmlspecialchars($label) ?></span>
                    <?php if ($requiredOnValue !== ''): ?>
                        <?php $renderRequiredBadge($requiredOnValue); ?>
                    <?php endif; ?>
                </div>
                <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($hint) ?></div>
            </div>
            <span class="inline-flex h-9 w-9 items-center justify-center rounded-2xl bg-slate-100 text-slate-500">
                <i class="<?= htmlspecialchars($icon) ?>"></i>
            </span>
        </div>
        <?php if ($existingPath !== ''): ?>
            <?php $renderExistingFile($existingPath, $label); ?>
        <?php endif; ?>
        <label class="mt-3 flex min-h-[132px] cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-center transition hover:border-slate-400 hover:bg-slate-100"
            data-dropzone
            data-required-company="<?= $requiredForCompany ? '1' : '0' ?>"
            data-required-individual="<?= $requiredForIndividual ? '1' : '0' ?>">
            <input type="file"
                name="<?= htmlspecialchars($name) ?>"
                data-existing-file="<?= $existingPath!==''?'1':'' ?>"
                <?= $name==='supplier_cni' && $existingPath===''?'data-required-on="physique"':'' ?>
                accept="<?= htmlspecialchars($accept) ?>"
                class="hidden"
                data-dropzone-input>
            <span class="text-sm font-semibold text-slate-700" data-dropzone-title>Glisser ou sélectionner un fichier</span>
            <span class="mt-1 text-xs text-slate-500" data-dropzone-subtitle>PNG, JPG, WEBP ou PDF</span>
            <span class="mt-3 hidden text-xs font-semibold text-emerald-700" data-dropzone-status></span>
            <div class="mt-4 hidden w-full rounded-2xl border border-slate-200 bg-white p-3" data-dropzone-preview></div>
        </label>
    </div>
<?php
};
?>

<section class="rounded-[28px] border border-slate-200 bg-white/90 p-6 shadow-sm" data-supplier-form-root>
    <?php if (empty($supplierCreateHideHeader)): ?>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] <?= htmlspecialchars($supplierCreateTheme['accent_label_class']) ?>"><?= htmlspecialchars($supplierCreateEyebrow) ?></p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars($supplierCreateHeading) ?></h1>
            <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars($supplierCreateDescription) ?></p>
        </div>
        <?php if ($supplierCreateCancelHref !== ''): ?>
            <div>
                <a href="<?= htmlspecialchars($supplierCreateCancelHref) ?>" class="inline-flex items-center rounded-2xl px-4 py-2 text-sm font-semibold <?= htmlspecialchars($supplierCreateTheme['muted_button_class']) ?>">
                    <i class="fas fa-arrow-left mr-2 text-slate-500"></i>
                    <?= htmlspecialchars($supplierCreateCancelLabel) ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="mt-5 rounded-2xl border px-4 py-4 text-sm <?= htmlspecialchars($supplierCreateTheme['accent_panel_class']) ?>">
        <div><?= htmlspecialchars($supplierCreateIntro) ?></div>
        <div class="mt-3 inline-flex items-center rounded-full border border-rose-200 bg-white px-3 py-1 text-xs font-semibold text-rose-700">
            <span class="mr-2 inline-flex h-5 w-5 items-center justify-center rounded-full bg-rose-100 text-[11px] font-bold leading-none text-rose-700">X</span>
            <?= htmlspecialchars($supplierCreateLegend) ?>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="mt-5 space-y-5">
        <?php $businessClientForm=false;$supplierFiscalIdentity=$supplierCreateOld;require __DIR__.'/business_identity_entry.php'; ?>
        <?php foreach ($supplierCreateHiddenFields as $hiddenFieldName => $hiddenFieldValue): ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $hiddenFieldName) ?>" value="<?= htmlspecialchars((string) $hiddenFieldValue) ?>">
        <?php endforeach; ?>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Identité du fournisseur</h3>
                <div class="text-xs text-slate-500">Fiche stricte par type</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="md:col-span-2">
                    <?php $renderFieldLabel('Type de fournisseur', 'morale,physique'); ?>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">
                            <input type="radio" name="supplier_type" value="morale" <?= $oldType === 'morale' ? 'checked' : '' ?> class="border-slate-300" data-supplier-type-control>
                            Personne morale
                        </label>
                        <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">
                            <input type="radio" name="supplier_type" value="physique" <?= $oldType === 'physique' ? 'checked' : '' ?> class="border-slate-300" data-supplier-type-control>
                            Personne physique
                        </label>
                    </div>
                </div>

                <div data-supplier-type-section="morale">
                    <?php $renderFieldLabel('Forme juridique', 'morale'); ?>
                    <select name="raison_sociale" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" data-required-on="morale">
                        <option value="">Sélectionner une forme juridique</option>
                        <?php foreach ($legalFormOptions as $legalForm): ?>
                            <option value="<?= htmlspecialchars((string) ($legalForm['label'] ?? '')) ?>" <?= ($supplierCreateOld['raison_sociale'] ?? '') === ($legalForm['label'] ?? '') ? 'selected' : '' ?>><?= htmlspecialchars((string) ($legalForm['label'] ?? '')) ?></option>
                        <?php endforeach; ?>
                        <?php if (!empty($supplierCreateOld['raison_sociale']) && !in_array((string) $supplierCreateOld['raison_sociale'], $legalFormOptionLabels, true)): ?>
                            <option value="<?= htmlspecialchars((string) $supplierCreateOld['raison_sociale']) ?>" selected><?= htmlspecialchars((string) $supplierCreateOld['raison_sociale']) ?> (valeur historique)</option>
                        <?php endif; ?>
                    </select>
                </div>

                <div>
                    <?php $renderFieldLabel('Code fournisseur'); ?>
                    <input name="code_fournisseur" readonly value="<?= htmlspecialchars($supplierCreateCodeValue) ?>" placeholder="<?= htmlspecialchars($supplierCreateCodePlaceholder) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-slate-700 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>">
                    <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($supplierCreateCodeHint) ?></div>
                </div>

                <div class="md:col-span-2" data-supplier-type-section="morale">
                    <?php $renderFieldLabel('Dénomination / nom affiché', 'morale'); ?>
                    <input name="nom_commercial" value="<?= $oldValue('nom_commercial') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" placeholder="Nom visible dans le décaissement" data-required-on="morale">
                </div>

                <div data-supplier-type-section="physique">
                    <label class="text-sm font-semibold">Numéro de la pièce d’identité *</label>
                    <input name="identity_document_number" maxlength="110" value="<?= $oldValue('identity_document_number') ?>" data-required-on="physique" class="mt-1 w-full rounded-xl border border-slate-200 px-3 py-2" autocomplete="off">
                    <p data-person-identity-message role="status" class="mt-2 text-sm text-red-700">Le personnel ne peut pas être enregistré comme fournisseur.</p>
                </div>
                <div data-supplier-type-section="physique">
                    <?php $renderFieldLabel('Nom', 'physique'); ?>
                    <input name="person_last_name" value="<?= $oldValue('person_last_name') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" data-required-on="physique">
                </div>

                <div data-supplier-type-section="physique">
                    <?php $renderFieldLabel('Prénom', 'physique'); ?>
                    <input name="person_first_name" value="<?= $oldValue('person_first_name') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" data-required-on="physique">
                </div>

                <div class="md:col-span-2" data-supplier-type-section="morale">
                    <?php $renderFieldLabel('Gérant / représentant légal', 'morale'); ?>
                    <input name="contact_nom" value="<?= $oldValue('contact_nom') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" placeholder="Nom du gérant" data-required-on="morale">
                </div>

                <div class="md:col-span-2 rounded-2xl border <?= htmlspecialchars($supplierCreateTheme['accent_border_class']) ?> <?= htmlspecialchars($supplierCreateTheme['accent_surface_class']) ?> px-4 py-3 text-sm text-slate-700" data-supplier-type-section="physique">
                    Le nom affiché et le contact principal seront générés automatiquement à partir du nom et du prénom.
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Catégories métier</h3>
                <div class="text-xs text-slate-500">Multi-catégories</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($categoryOptions as $category): ?>
                    <?php $categoryId = (int) ($category['id_category'] ?? 0); ?>
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700">
                        <input type="checkbox" name="category_ids[]" value="<?= $categoryId ?>" <?= in_array($categoryId, $selectedCategories, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                        <?= htmlspecialchars((string) ($category['label'] ?? '')) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?php if ($supplierCreateCategoryNotice !== ''): ?>
                <div class="mt-4 rounded-2xl border border-dashed border-cyan-200 bg-cyan-50 px-4 py-3 text-sm text-cyan-800">
                    <?= htmlspecialchars($supplierCreateCategoryNotice) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Identification du fournisseur</h3>
                <div class="text-xs text-slate-500">NCC, SIRET, TVA, CIK ou identifiant local selon le pays</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <?php $renderFieldLabel('NCC'); ?>
                    <input name="ncc" value="<?= $oldValue('ncc') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>">
                </div>
                <div>
                    <?php $renderFieldLabel('RNE'); ?>
                    <input name="rne" value="<?= $oldValue('rne') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>">
                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="is_rne" value="1" <?= !empty($supplierCreateOld['is_rne']) ? 'checked' : '' ?> class="rounded border-slate-300">
                        RNE complémentaire (ne remplace pas le NCC)
                    </label>
                </div>
            </div>
            <?php $supplierFiscalIdentity = $supplierCreateOld; require __DIR__ . '/../partials/supplier_french_identity.php'; ?>
            <div class="supplier-fne-box mt-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-4" data-supplier-fne <?= $oldType === 'physique' ? 'hidden' : '' ?>>
                <strong class="block text-sm font-semibold text-emerald-900"><i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>Identité officielle · FNE / DGI</strong>
                <button type="button" data-fne-verify disabled class="mt-3 inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">
                    <i class="fas fa-search" aria-hidden="true"></i> Vérifier le NCC et remplir la fiche
                </button>
                <p data-fne-status aria-live="polite" class="mt-2 text-sm text-emerald-800">NCC obligatoire. La dénomination et les informations fiscales proviennent exclusivement de la FNE. La vérification sera renouvelée à l’enregistrement.</p>
                <span data-fne-loading class="mt-1 block text-xs text-gray-600">Chargement de la vérification…</span>
                <noscript><p class="mt-2 text-sm text-amber-800">Activez JavaScript pour vérifier le NCC auprès de la FNE.</p></noscript>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Coordonnées</h3>
                <div class="text-xs text-slate-500">Contact et localisation</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                    <?php $renderFieldLabel('Mails / email', 'morale,physique', ['data-supplier-type-label' => '1', 'data-company-label' => 'Mails / email', 'data-individual-label' => 'Email']); ?>
                    <input name="email" type="text" value="<?= $oldValue('email') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" placeholder="contact@entreprise.ci ou plusieurs mails séparés par virgule" data-required-on="morale,physique">
                </div>
                <div>
                    <?php $renderFieldLabel('Téléphone', 'morale,physique'); ?>
                    <input name="telephone" value="<?= $oldValue('telephone') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" data-required-on="morale,physique">
                </div>
                <div>
                    <?php $renderFieldLabel('Ville'); ?>
                    <input name="ville" value="<?= $oldValue('ville') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>">
                </div>
                <div>
                    <?php $renderFieldLabel('Pays'); ?>
                    <input name="pays" value="<?= $oldValue('pays', 'CI') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>">
                </div>
                <div class="md:col-span-2 rounded-3xl border border-slate-200 bg-white p-4 shadow-sm" data-supplier-location-root>
                    <div class="grid gap-5 lg:grid-cols-[0.95fr_1.05fr]">
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900">Recherche intelligente de localisation</div>
                                    <div class="mt-1 text-xs text-slate-500">Commencez par une rue, un quartier, une commune ou un point connu.</div>
                                </div>
                                <button type="button" data-location-current class="inline-flex items-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                    <i class="fas fa-location-crosshairs mr-2 text-cyan-600"></i>
                                    Position actuelle
                                </button>
                            </div>
                            <div class="mt-4">
                                <?php $renderFieldLabel('Recherche / autocomplétion'); ?>
                                <input type="text" data-location-search autocomplete="off" spellcheck="false" value="<?= $oldValue('adresse') ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" placeholder="Ex: Cocody Riviera, Abidjan, Côte d'Ivoire">
                            </div>
                            <div class="mt-3 space-y-2" data-location-results></div>
                            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Sélection confirmée</div>
                                    <span data-location-source-badge class="inline-flex rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">Aucune source</span>
                                </div>
                                <div class="mt-2 font-medium text-slate-900" data-location-selection-label><?= htmlspecialchars((string) ($supplierCreateOld['adresse'] ?? 'Aucune localisation confirmée.')) ?></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900">Prévisualisation carte</div>
                                    <div class="mt-1 text-xs text-slate-500">Leaflet + OpenStreetMap pour valider le point choisi avant enregistrement, puis l'ajuster sur le bâtiment exact.</div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" data-location-fullscreen title="Afficher en plein ecran" aria-label="Afficher en plein ecran" class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-sm text-slate-700 hover:bg-slate-50"><i class="fas fa-expand" aria-hidden="true"></i></button>
                                    <button type="button" data-location-recenter class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Recentrer</button>
                                    <button type="button" data-location-clear class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50">Réinitialiser</button>
                                </div>
                            </div>
                            <div data-location-map class="mt-4 h-[290px] overflow-hidden rounded-3xl border border-slate-200 bg-slate-100"></div>
                            <div data-location-coordinates class="mt-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs font-medium text-slate-700">Coordonnées non fixées</div>
                            <div data-location-status class="mt-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-600">Recherchez une localisation pour afficher la carte fournisseur.</div>
                        </div>
                    </div>
                </div>
                <div class="md:col-span-2">
                    <?php $renderFieldLabel('Adresse / localisation', 'morale,physique'); ?>
                    <textarea name="adresse" rows="2" autocomplete="off" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 <?= htmlspecialchars($supplierCreateTheme['accent_ring_class']) ?>" data-required-on="morale,physique"><?= htmlspecialchars((string) ($supplierCreateOld['adresse'] ?? '')) ?></textarea>
                    <input type="hidden" name="location_latitude" value="<?= htmlspecialchars((string) ($supplierCreateOld['location_latitude'] ?? '')) ?>">
                    <input type="hidden" name="location_longitude" value="<?= htmlspecialchars((string) ($supplierCreateOld['location_longitude'] ?? '')) ?>">
                    <input type="hidden" name="location_source" value="<?= htmlspecialchars((string) ($supplierCreateOld['location_source'] ?? '')) ?>">
                    <input type="hidden" name="location_label" value="<?= htmlspecialchars((string) ($supplierCreateOld['location_label'] ?? '')) ?>">
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-supplier-type-section="morale">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Pièces personne morale</h3>
                <div class="text-xs text-slate-500">Logo obligatoire, autres pièces ajoutables plus tard</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <?php $renderDropzone('supplier_trade_register', 'Registre de commerce', 'Facultatif à la création, peut être ajouté plus tard.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-building', false, false); ?>
                <?php $renderDropzone('supplier_tax_existence', 'Déclaration fiscale d\'existence', 'Facultatif à la création, peut être ajouté plus tard.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-file-signature', false, false); ?>
                <?php $renderDropzone('supplier_tax_clearance', 'Attestation de régularité fiscale', 'Facultatif à la création, peut être ajouté plus tard.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-receipt', false, false); ?>
                <?php $renderDropzone('supplier_social_clearance', 'Attestation de régularité sociale', 'Facultatif à la création, peut être ajouté plus tard.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-users', false, false); ?>
                <?php $renderDropzone('supplier_manager_cni', 'CNI du gérant', 'Facultatif à la création, peut être ajouté plus tard.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-id-card', false, false); ?>
                <?php $renderDropzone('supplier_logo', 'Logo société', 'Obligatoire à la création pour une personne morale.', '.png,.jpg,.jpeg,.webp', 'fas fa-image', true, false); ?>
            </div>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-supplier-type-section="physique">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-slate-900">Pièces obligatoires personne physique</h3>
                <div class="text-xs text-slate-500">Aperçu unitaire immédiat</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <?php $renderDropzone('supplier_photo', 'Photo', 'Portrait ou visuel d\'identification.', '.png,.jpg,.jpeg,.webp', 'fas fa-camera', false, true); ?>
                <?php $renderDropzone('supplier_cni', 'CNI', 'Recto, verso ou PDF fusionné.', '.png,.jpg,.jpeg,.webp,.pdf', 'fas fa-id-badge', false, true); ?>
            </div>
        </div>

        <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-4">
            <label class="inline-flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="actif" value="1" <?= array_key_exists('actif', $supplierCreateOld) ? (!empty($supplierCreateOld['actif']) ? 'checked' : '') : 'checked' ?> class="rounded border-slate-300">
                Actif
            </label>
            <button class="rounded-xl px-4 py-2 text-sm font-semibold <?= htmlspecialchars($supplierCreateTheme['accent_button_class']) ?>"><?= htmlspecialchars($supplierCreateSubmitLabel) ?></button>
        </div>
    </form>
</section>

<script src="../js/supplier-location-picker.js?v=<?= filemtime(__DIR__ . '/../js/supplier-location-picker.js') ?>"></script>
<script>
    window.initSupplierLocationPicker({
        rootSelector: '[data-supplier-location-root]',
        apiUrl: '../api/location_lookup.php',
        settings: <?= json_encode($supplierLocationPickerSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        initialAddress: <?= json_encode((string) ($supplierCreateOld['adresse'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        initialPosition: <?= json_encode($supplierLocationPickerInitialPosition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    });
</script>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('[data-supplier-form-root]').forEach(function(root) {
            const typeControls = root.querySelectorAll('[data-supplier-type-control]');
            const sections = root.querySelectorAll('[data-supplier-type-section]');
            const conditionalFields = root.querySelectorAll('[data-required-on]');
            const dynamicLabels = root.querySelectorAll('[data-supplier-type-label]');

            function currentType() {
                const checked = root.querySelector('[data-supplier-type-control]:checked');
                return checked ? checked.value : 'morale';
            }

            function applyTypeState() {
                const type = currentType();

                sections.forEach(function(section) {
                    const visible = section.getAttribute('data-supplier-type-section') === type;
                    section.style.display = visible ? '' : 'none';
                });

                conditionalFields.forEach(function(field) {
                    const allowed = (field.getAttribute('data-required-on') || '').split(',').map(function(value) {
                        return value.trim();
                    }).filter(Boolean);
                    field.required = allowed.includes(type);
                });

                root.querySelectorAll('[data-required-indicator]').forEach(function(indicator) {
                    const allowed = (indicator.getAttribute('data-required-on') || '').split(',').map(function(value) {
                        return value.trim();
                    }).filter(Boolean);
                    indicator.classList.toggle('hidden', !allowed.includes(type));
                });

                dynamicLabels.forEach(function(label) {
                    label.textContent = type === 'physique' ?
                        label.getAttribute('data-individual-label') :
                        label.getAttribute('data-company-label');
                });

                root.querySelectorAll('[data-dropzone]').forEach(function(dropzone) {
                    const card = dropzone.closest('[data-dropzone-card]');
                    if (!card) {
                        return;
                    }
                    const required = type === 'morale' ?
                        dropzone.getAttribute('data-required-company') === '1' :
                        dropzone.getAttribute('data-required-individual') === '1';
                    card.classList.toggle('border-rose-200', required);
                    card.classList.toggle('bg-rose-50/40', required);
                });
            }

            function renderFilePreview(container, file) {
                const preview = container.querySelector('[data-dropzone-preview]');
                const status = container.querySelector('[data-dropzone-status]');
                const title = container.querySelector('[data-dropzone-title]');
                const subtitle = container.querySelector('[data-dropzone-subtitle]');
                if (!preview || !status || !title || !subtitle || !file) {
                    return;
                }

                preview.classList.remove('hidden');
                status.classList.remove('hidden');
                status.textContent = file.name;
                title.textContent = 'Fichier prêt';
                subtitle.textContent = (file.type || 'document').toUpperCase();

                if (file.type && file.type.startsWith('image/')) {
                    const url = URL.createObjectURL(file);
                    preview.innerHTML = '<img src="' + url + '" alt="Aperçu" class="mx-auto max-h-44 rounded-xl object-contain">';
                    return;
                }

                preview.innerHTML = '<div class="flex items-center gap-3 rounded-xl bg-slate-50 px-3 py-3 text-left"><span class="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-rose-50 text-rose-600"><i class="fas fa-file-pdf"></i></span><div><div class="text-sm font-semibold text-slate-800">' + file.name + '</div><div class="text-xs text-slate-500">Document prêt à l\'envoi</div></div></div>';
            }

            root.querySelectorAll('[data-dropzone]').forEach(function(dropzone) {
                const input = dropzone.querySelector('[data-dropzone-input]');
                if (!input) {
                    return;
                }

                ['dragenter', 'dragover'].forEach(function(eventName) {
                    dropzone.addEventListener(eventName, function(event) {
                        event.preventDefault();
                        dropzone.classList.add('border-sky-400', 'bg-sky-50');
                    });
                });

                ['dragleave', 'dragend', 'drop'].forEach(function(eventName) {
                    dropzone.addEventListener(eventName, function(event) {
                        event.preventDefault();
                        dropzone.classList.remove('border-sky-400', 'bg-sky-50');
                    });
                });

                dropzone.addEventListener('drop', function(event) {
                    if (!event.dataTransfer || !event.dataTransfer.files || !event.dataTransfer.files.length) {
                        return;
                    }
                    input.files = event.dataTransfer.files;
                    renderFilePreview(dropzone, event.dataTransfer.files[0]);
                });

                input.addEventListener('change', function() {
                    if (input.files && input.files.length) {
                        renderFilePreview(dropzone, input.files[0]);
                    }
                });
            });

            typeControls.forEach(function(control) {
                control.addEventListener('change', applyTypeState);
            });

            applyTypeState();
        });
    });
</script>
<script src="<?= htmlspecialchars($supplierCreateAssetHrefPrefix, ENT_QUOTES, 'UTF-8') ?>assets/js/supplier-fne.js?v=<?= filemtime(__DIR__ . '/../assets/js/supplier-fne.js') ?>" defer></script>
<?php $supplierFneScriptLoaded = true; ?>



