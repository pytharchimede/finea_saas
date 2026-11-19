<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/model/FicheDecaissement.php';
require_once __DIR__ . '/model/BonCommande.php';
require_once __DIR__ . '/model/BonReception.php';

$pageTitle = 'Bon de commande - DécaissApp';
$activeMenu = 'achat';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';

$pdo = Database::getConnection();
$ficheModel = new FicheDecaissement($pdo);
$fournisseurModel = new FournisseurRepository($pdo);
$bcModel = new BonCommande($pdo);
$brModel = new BonReception($pdo);

$numFiche = trim((string)($_GET['num_fiche'] ?? ''));
if ($numFiche === '') {
    http_response_code(400);
    echo '<div class="container mx-auto p-6">Numéro de fiche manquant.</div>';
    exit;
}

$fiche = $ficheModel->getDetailsByNum($numFiche);
if (!$fiche) {
    http_response_code(404);
    echo '<div class="container mx-auto p-6">Fiche introuvable.</div>';
    exit;
}

$flashError = '';
$flashSuccess = '';

$existing = $bcModel->getByNumFiche($numFiche);
$existingReceipt = $existing ? $brModel->getByNumFiche($numFiche) : null;

$supplierContext = $fournisseurModel->getSupplierDisplayContext(
    isset($fiche['fournisseur_id']) ? (int) $fiche['fournisseur_id'] : null,
    array_merge($fiche, $existing ?: [])
);

$isLocked = (bool)$existing;

$defaults = [
    'bc_number' => $existing['bc_number'] ?? $bcModel->buildDefaultBcNumber($numFiche),
    'bc_date' => $existing['bc_date'] ?? date('Y-m-d'),

    'fournisseur_nom' => $existing['fournisseur_nom'] ?? (string)($supplierContext['supplier_name'] ?? $fiche['fournisseur_nom'] ?? $fiche['beficiaire_fiche'] ?? ''),
    'fournisseur_tel' => $existing['fournisseur_tel'] ?? (string)($supplierContext['supplier_phone'] ?? $fiche['fournisseur_telephone'] ?? $fiche['tel_beneficiaire_fiche'] ?? ''),
    'fournisseur_email' => $existing['fournisseur_email'] ?? (string)($supplierContext['supplier_email'] ?? ''),
    'fournisseur_adresse' => $existing['fournisseur_adresse'] ?? (string)($supplierContext['supplier_address'] ?? ''),

    'objet' => $existing['objet'] ?? (string)($fiche['designation_fiche'] ?? ''),

    'article_description' => $existing['article_description']
        ?? trim(((string)($fiche['designation_fiche'] ?? '')) . (trim((string)($fiche['precision_fiche'] ?? '')) !== '' ? (' - ' . (string)$fiche['precision_fiche']) : '')),
    'article_quantite' => $existing['article_quantite'] ?? 1,
    'article_unite' => $existing['article_unite'] ?? 'U',
    'article_prix_unitaire' => $existing['article_prix_unitaire'] ?? (float)($fiche['montant_fiche'] ?? 0),
    'devise' => $existing['devise'] ?? 'FCFA',

    'lieu_livraison' => $existing['lieu_livraison'] ?? (string)($fiche['chantier_titre'] ?? ''),
    'delai_livraison' => $existing['delai_livraison'] ?? '',
    'conditions_paiement' => $existing['conditions_paiement'] ?? '',
    'notes' => $existing['notes'] ?? '',

    'sign_acheteur_nom' => $existing['sign_acheteur_nom'] ?? (string)($_SESSION['user_name'] ?? ''),
    'sign_fournisseur_nom' => $existing['sign_fournisseur_nom'] ?? (string)($supplierContext['supplier_contact'] ?? ''),
];

if (!$bcModel->isReady()) {
    $flashError = $bcModel->getInitError() ?: 'Le bon de commande n\'est pas disponible.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $bcModel->isReady()) {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        if ($isLocked) {
            $flashError = 'Bon de commande déjà généré : modification interdite. Seule l\'exportation PDF est autorisée.';
        } else {
            try {
                $result = $bcModel->upsertByNumFiche($numFiche, [
                    'bc_number' => $_POST['bc_number'] ?? '',
                    'bc_date' => $_POST['bc_date'] ?? '',

                    'fournisseur_nom' => $_POST['fournisseur_nom'] ?? '',
                    'fournisseur_tel' => $_POST['fournisseur_tel'] ?? '',
                    'fournisseur_email' => $_POST['fournisseur_email'] ?? '',
                    'fournisseur_adresse' => $_POST['fournisseur_adresse'] ?? '',

                    'objet' => $_POST['objet'] ?? '',

                    'article_description' => $_POST['article_description'] ?? '',
                    'article_quantite' => $_POST['article_quantite'] ?? 1,
                    'article_unite' => $_POST['article_unite'] ?? 'U',
                    'article_prix_unitaire' => $_POST['article_prix_unitaire'] ?? 0,
                    'devise' => $_POST['devise'] ?? 'FCFA',

                    'lieu_livraison' => $_POST['lieu_livraison'] ?? '',
                    'delai_livraison' => $_POST['delai_livraison'] ?? '',
                    'conditions_paiement' => $_POST['conditions_paiement'] ?? '',
                    'notes' => $_POST['notes'] ?? '',

                    'sign_acheteur_nom' => $_POST['sign_acheteur_nom'] ?? '',
                    'sign_fournisseur_nom' => $_POST['sign_fournisseur_nom'] ?? '',
                ], isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);

                if (empty($result['ok'])) {
                    AuditTrail::log('purchase_order_save_failed', [
                        'module' => 'decaissement',
                        'action' => 'bon_commande_save',
                        'entity_type' => 'bon_commande',
                        'entity_id' => $numFiche,
                        'entity_label' => 'Bon de commande ' . $numFiche,
                        'result' => 'failed',
                        'severity' => 'warning',
                        'details' => [
                            'bc_number' => $_POST['bc_number'] ?? '',
                            'error_message' => (string)($result['error'] ?? 'Enregistrement impossible.'),
                            'view_mode' => 'form_submit',
                        ],
                    ], $pdo);
                    $flashError = (string)($result['error'] ?? 'Enregistrement impossible.');
                } else {
                    AuditTrail::log('purchase_order_saved', [
                        'module' => 'decaissement',
                        'action' => 'bon_commande_save',
                        'entity_type' => 'bon_commande',
                        'entity_id' => $numFiche,
                        'entity_label' => 'Bon de commande ' . $numFiche,
                        'result' => 'success',
                        'details' => [
                            'bc_number' => $_POST['bc_number'] ?? '',
                            'fournisseur_nom' => $_POST['fournisseur_nom'] ?? '',
                            'article_description' => $_POST['article_description'] ?? '',
                            'view_mode' => 'form_submit',
                        ],
                    ], $pdo);
                    $flashSuccess = 'Bon de commande enregistré.';
                    $existing = $bcModel->getByNumFiche($numFiche);
                    $isLocked = (bool)$existing;
                    // Recharger valeurs affichées
                    if ($existing) {
                        foreach (array_keys($defaults) as $k) {
                            if (array_key_exists($k, $existing)) {
                                $defaults[$k] = $existing[$k];
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('[bon_commande] save error num_fiche=' . $numFiche . ' : ' . $e->getMessage());
                AuditTrail::log('purchase_order_save_failed', [
                    'module' => 'decaissement',
                    'action' => 'bon_commande_save',
                    'entity_type' => 'bon_commande',
                    'entity_id' => $numFiche,
                    'entity_label' => 'Bon de commande ' . $numFiche,
                    'result' => 'failed',
                    'severity' => 'warning',
                    'details' => [
                        'bc_number' => $_POST['bc_number'] ?? '',
                        'error_message' => $e->getMessage(),
                        'view_mode' => 'form_submit',
                    ],
                ], $pdo);
                $flashError = 'Erreur lors de l\'enregistrement.';
            }
        }
    }
}

$montantTotal = (float)($defaults['article_quantite'] ?? 1) * (float)($defaults['article_prix_unitaire'] ?? 0);

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function money_fcfa($v): string
{
    return number_format((float)$v, 0, ',', ' ') . ' CFA';
}

$supplierCategories = array_values(array_filter((array) ($supplierContext['supplier_categories'] ?? [])));
$supplierHasContext = !empty($supplierContext['supplier_name']) || !empty($supplierContext['supplier_contact']) || !empty($supplierContext['supplier_email']) || !empty($supplierCategories);
?>

<div class="container mx-auto py-10 px-4 max-w-6xl">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-6">
        <div>
            <h1 class="text-3xl font-bold text-indigo-700 drop-shadow">Bon de commande</h1>
            <p class="text-sm text-gray-600 mt-1">Lié à la fiche N° <span class="font-semibold text-gray-800"><?= h($numFiche) ?></span></p>
        </div>
        <div class="flex items-center gap-2">
            <a href="liste_fiches_achat.php" class="px-3 py-2 rounded-xl bg-white border text-sm hover:bg-gray-50">Retour</a>
            <?php if ($existing): ?>
                <a href="request/export_bon_commande_pdf.php?num_fiche=<?= urlencode($numFiche) ?>" class="px-3 py-2 rounded-xl bg-gray-900 text-white text-sm hover:bg-gray-800">Télécharger PDF</a>
                <a href="bon_reception.php?num_fiche=<?= urlencode($numFiche) ?>" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm hover:bg-indigo-700">
                    <?= $existingReceipt ? 'Voir BR signé' : 'Uploader BR signé' ?>
                </a>
            <?php else: ?>
                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-500 text-sm" title="Enregistrez d'abord le bon de commande">PDF indisponible</span>
                <span class="px-3 py-2 rounded-xl bg-gray-100 text-gray-500 text-sm" title="Enregistrez d'abord le bon de commande">BR indisponible</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($flashError): ?>
        <div class="mb-4 p-3 rounded-xl bg-rose-50 text-rose-800 border border-rose-100 text-sm"><?= h($flashError) ?></div>
    <?php elseif ($flashSuccess): ?>
        <div class="mb-4 p-3 rounded-xl bg-emerald-50 text-emerald-800 border border-emerald-100 text-sm"><?= h($flashSuccess) ?></div>
    <?php endif; ?>

    <?php if ($isLocked): ?>
        <div class="mb-4 p-3 rounded-xl bg-gray-50 text-gray-700 border border-gray-200 text-sm">
            Bon de commande déjà généré : le formulaire est en lecture seule.
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-1 bg-white rounded-2xl border border-gray-200 shadow-sm p-5">
            <div class="font-semibold text-gray-900">Récap fiche</div>
            <div class="mt-3 text-sm text-gray-700 space-y-2">
                <div><span class="text-gray-500">Bénéficiaire :</span> <span class="font-medium"><?= h($fiche['beficiaire_fiche'] ?? '') ?></span></div>
                <div><span class="text-gray-500">Montant :</span> <span class="font-semibold text-emerald-700"><?= money_fcfa($fiche['montant_fiche'] ?? 0) ?></span></div>
                <div><span class="text-gray-500">Chantier :</span> <span class="font-medium"><?= h($fiche['chantier_code'] ?? '') ?> <?= h($fiche['chantier_titre'] ?? '') ?></span></div>
                <div><span class="text-gray-500">Désignation :</span> <span class="font-medium"><?= h($fiche['designation_fiche'] ?? '') ?></span></div>
                <div><span class="text-gray-500">Précision :</span> <span class="font-medium"><?= h($fiche['precision_fiche'] ?? '') ?></span></div>
                <div><a class="text-indigo-700 hover:underline" target="_blank" href="voir_fiche_details.php?num_fiche=<?= urlencode($numFiche) ?>">Ouvrir la fiche</a></div>
            </div>

            <?php if ($supplierHasContext): ?>
                <div class="mt-5 rounded-2xl border border-sky-100 bg-sky-50/80 p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="font-semibold text-slate-900">Référentiel fournisseur</div>
                        <?php if (!empty($supplierContext['supplier_type_label'])): ?>
                            <span class="inline-flex rounded-full border border-sky-200 bg-white px-3 py-1 text-xs font-semibold text-sky-700"><?= h($supplierContext['supplier_type_label']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 space-y-2 text-sm text-slate-700">
                        <div><span class="text-slate-500">Nom :</span> <span class="font-medium"><?= h($supplierContext['supplier_name'] ?? '—') ?></span></div>
                        <?php if (!empty($supplierContext['supplier_contact'])): ?>
                            <div><span class="text-slate-500">Contact :</span> <span class="font-medium"><?= h($supplierContext['supplier_contact']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($supplierContext['supplier_phone'])): ?>
                            <div><span class="text-slate-500">Téléphone :</span> <span class="font-medium"><?= h($supplierContext['supplier_phone']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($supplierContext['supplier_email'])): ?>
                            <div><span class="text-slate-500">Email :</span> <span class="font-medium break-all"><?= h($supplierContext['supplier_email']) ?></span></div>
                        <?php endif; ?>
                        <?php if (!empty($supplierContext['supplier_address'])): ?>
                            <div><span class="text-slate-500">Adresse :</span> <span class="font-medium"><?= h($supplierContext['supplier_address']) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($supplierCategories): ?>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <?php foreach ($supplierCategories as $categoryLabel): ?>
                                <span class="inline-flex rounded-full border border-sky-200 bg-white px-3 py-1 text-xs font-semibold text-sky-800"><?= h($categoryLabel) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="lg:col-span-2 bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
            <form method="post" class="space-y-6">
                <input type="hidden" name="action" value="save" />

                <fieldset <?= $isLocked ? 'disabled' : '' ?> class="space-y-6">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">N° Bon de commande</label>
                            <input name="bc_number" value="<?= h($defaults['bc_number']) ?>" class="w-full form-input rounded border-gray-300" />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-600 mb-1">Date</label>
                            <input type="date" name="bc_date" value="<?= h($defaults['bc_date']) ?>" class="w-full form-input rounded border-gray-300" />
                        </div>
                    </div>

                    <div class="border-t pt-5">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <div>
                                <div class="font-semibold text-gray-900">Fournisseur</div>
                                <p class="mt-1 text-xs text-gray-500 mb-0">Le bon de commande reprend d'abord le référentiel fournisseur validé, avec conservation du snapshot BC une fois enregistré.</p>
                            </div>
                            <?php if (!empty($supplierContext['supplier_type_label']) || $supplierCategories): ?>
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <?php if (!empty($supplierContext['supplier_type_label'])): ?>
                                        <span class="inline-flex rounded-full border border-gray-200 bg-gray-50 px-3 py-1 font-semibold text-gray-700"><?= h($supplierContext['supplier_type_label']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($supplierCategories): ?>
                                        <span class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-3 py-1 font-semibold text-sky-800"><?= h(implode(', ', $supplierCategories)) ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Nom</label>
                                <input required name="fournisseur_nom" value="<?= h($defaults['fournisseur_nom']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Téléphone</label>
                                <input name="fournisseur_tel" value="<?= h($defaults['fournisseur_tel']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Email</label>
                                <input type="email" name="fournisseur_email" value="<?= h($defaults['fournisseur_email']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Adresse</label>
                                <textarea name="fournisseur_adresse" rows="3" class="w-full form-textarea rounded border-gray-300"><?= h($defaults['fournisseur_adresse']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="border-t pt-5">
                        <div class="font-semibold text-gray-900">Commande</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Objet</label>
                                <input name="objet" value="<?= h($defaults['objet']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Description / Article</label>
                                <textarea required name="article_description" rows="3" class="w-full form-textarea rounded border-gray-300"><?= h($defaults['article_description']) ?></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Quantité</label>
                                <input type="number" step="0.01" min="0" name="article_quantite" value="<?= h($defaults['article_quantite']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Unité</label>
                                <input name="article_unite" value="<?= h($defaults['article_unite']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Prix unitaire (FCFA)</label>
                                <input type="number" step="0.01" min="0" name="article_prix_unitaire" value="<?= h($defaults['article_prix_unitaire']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Devise</label>
                                <input name="devise" value="<?= h($defaults['devise']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>

                            <div class="md:col-span-2">
                                <div class="rounded-xl bg-emerald-50 border border-emerald-100 p-4">
                                    <div class="text-sm text-emerald-800"><span class="font-semibold">Total estimé :</span> <?= money_fcfa($montantTotal) ?></div>
                                    <div class="text-xs text-emerald-700 mt-1">Le total est calculé : quantité × prix unitaire.</div>
                                </div>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Lieu de livraison</label>
                                <input name="lieu_livraison" value="<?= h($defaults['lieu_livraison']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Délai de livraison</label>
                                <input name="delai_livraison" value="<?= h($defaults['delai_livraison']) ?>" class="w-full form-input rounded border-gray-300" placeholder="Ex: 48h, 7 jours…" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Conditions de paiement</label>
                                <input name="conditions_paiement" value="<?= h($defaults['conditions_paiement']) ?>" class="w-full form-input rounded border-gray-300" placeholder="Ex: À la livraison, 30 jours fin de mois…" />
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Notes</label>
                                <textarea name="notes" rows="3" class="w-full form-textarea rounded border-gray-300"><?= h($defaults['notes']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="border-t pt-5">
                        <div class="font-semibold text-gray-900">Signatures</div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Nom signataire (Banamur)</label>
                                <input name="sign_acheteur_nom" value="<?= h($defaults['sign_acheteur_nom']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-600 mb-1">Nom signataire (Fournisseur)</label>
                                <input name="sign_fournisseur_nom" value="<?= h($defaults['sign_fournisseur_nom']) ?>" class="w-full form-input rounded border-gray-300" />
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 h-24 flex items-center justify-center text-xs text-gray-500">
                                Zone signature Banamur
                            </div>
                            <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 h-24 flex items-center justify-center text-xs text-gray-500">
                                Zone signature Fournisseur
                            </div>
                        </div>
                    </div>

                </fieldset>

                <div class="flex items-center justify-end gap-3">
                    <?php if (!$isLocked): ?>
                        <button class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-semibold shadow">Enregistrer</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>