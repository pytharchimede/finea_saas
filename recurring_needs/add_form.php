<?php

/**
 * Module FINEA — Besoins récurrents
 * Dashboard + création + suivi des FEB automatiques.
 */
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';
require_once __DIR__ . '/controllers/RecurringNeedController.php';
require_once __DIR__ . '/_recurring_needs_common.php';

$pdo = \Database::getConnection();

$fournisseurs = [];
try {
    if (class_exists('FournisseurRepository')) {
        $fournisseurRepo = new FournisseurRepository($pdo);
        $fournisseurs = $fournisseurRepo->listForSelect(true, true);
    }
} catch (Throwable $e) {
    $fournisseurs = [];
}

if (!function_exists('rn_supplier_display_name')) {
    function rn_supplier_display_name(array $supplier): string
    {
        $type = (string)($supplier['supplier_type'] ?? 'morale');
        if ($type === 'physique') {
            $fullName = trim((string)($supplier['person_first_name'] ?? '') . ' ' . (string)($supplier['person_last_name'] ?? ''));
            if ($fullName !== '') {
                return $fullName;
            }
        }

        return trim((string)($supplier['nom_commercial'] ?? ''))
            ?: trim((string)($supplier['raison_sociale'] ?? ''))
            ?: ('Fournisseur #' . (int)($supplier['id_fournisseur'] ?? 0));
    }
}
$controller = new RecurringNeedController($pdo);
$flash = null;
$editingNeed = $editingNeed ?? [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $flash = isset($_POST['action']) ? $controller->action() : $controller->store();

    if (!isset($_POST['action']) && !empty($flash['ok'])) {
        header('Location: index.php?created=1');
        exit;
    }
}

$view = $controller->index();
$stats = $view['stats'] ?? [];
$items = $view['items'] ?? [];
$editId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($editId > 0) {
    $editingNeed = $controller->find($editId) ?? [];
}
$computed = rn_build_stats($items);
$canValidate = !empty($_SESSION['permissions']['valider_besoin_recurrent']) || !empty($_SESSION['permissions']['acces_admin']);
$currentStatus = trim((string)($_GET['status'] ?? ''));
$activeTab = $currentStatus === 'pending_validation' ? 'pending' : ($currentStatus === 'active' ? 'active' : 'new');
$nextItems = array_values(array_filter($items, fn($i) => in_array(($i['status'] ?? ''), ['active', 'pending_validation'], true)));
usort($nextItems, fn($a, $b) => strcmp((string)($a['next_due_date'] ?? ''), (string)($b['next_due_date'] ?? '')));
$nextItems = array_slice($nextItems, 0, 5);

$alerts = [];
if ($computed['pending'] > 0) {
    $alerts[] = ['title' => 'Validation requise', 'message' => $computed['pending'] . ' besoin(s) récurrent(s) attendent une décision.', 'icon' => 'fa-user-check'];
}
if ($computed['upcoming'] > 0) {
    $alerts[] = ['title' => 'Échéances proches', 'message' => $computed['upcoming'] . ' génération(s) prévues dans les 14 prochains jours.', 'icon' => 'fa-calendar-day'];
}
if ($computed['suspended'] > 0) {
    $alerts[] = ['title' => 'Récurrences suspendues', 'message' => $computed['suspended'] . ' modèle(s) sont suspendus.', 'icon' => 'fa-pause'];
}
if (!$alerts) {
    $alerts[] = ['title' => 'Situation maîtrisée', 'message' => 'Aucune alerte critique sur les besoins récurrents.', 'icon' => 'fa-shield-check'];
}

$kpis = [
    ['label' => 'Besoins configurés', 'value' => rn_number($computed['total']), 'hint' => 'modèles enregistrés', 'icon' => 'fa-layer-group', 'href' => 'index.php'],
    ['label' => 'Actifs', 'value' => rn_number($computed['active']), 'hint' => 'génération automatique prête', 'icon' => 'fa-arrows-rotate', 'href' => 'index.php?status=active'],
    ['label' => 'À valider', 'value' => rn_number($computed['pending']), 'hint' => 'en attente de validation', 'icon' => 'fa-user-check', 'href' => 'index.php?status=pending_validation'],
    ['label' => 'Exposition mensuelle', 'value' => rn_money($computed['monthlyExposure']), 'hint' => 'estimation des charges récurrentes', 'icon' => 'fa-sack-dollar', 'href' => '#pilotage-financier'],
];

$sideLinks = [
    ['href' => 'add_form.php', 'icon' => 'fa-plus', 'label' => 'Créer un besoin', 'hint' => 'Programmer une FEB automatique'],
    ['href' => 'index.php?status=pending_validation', 'icon' => 'fa-user-check', 'label' => 'Validations', 'hint' => $computed['pending'] . ' élément(s) à contrôler'],
    ['href' => 'index.php?status=active', 'icon' => 'fa-arrows-rotate', 'label' => 'Récurrences actives', 'hint' => 'Suivre les prochaines générations'],
    ['href' => '../employe/dashboard_employe.php', 'icon' => 'fa-user-tie', 'label' => 'Espace employé', 'hint' => 'Retour aux demandes internes'],
    ['href' => '../fiches_decaissement_list.php', 'icon' => 'fa-file-invoice-dollar', 'label' => 'Fiches décaissement', 'hint' => 'Consulter les fiches générées'],
    ['href' => 'cron_generate.php', 'icon' => 'fa-gears', 'label' => 'Tester la génération', 'hint' => 'Lancer le contrôle manuel'],
];

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.css" rel="stylesheet">
<style>
    .rn-select-card {
        position: relative;
    }

    .rn-select-card .ts-wrapper {
        width: 100%;
    }

    .rn-select-card .ts-control {
        min-height: 46px;
        border-radius: 1rem;
        border: 1px solid #e2e8f0;
        padding: 0.5rem 0.85rem 0.5rem 2.65rem;
        font-size: 0.875rem;
        background: #fff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    }

    .rn-select-card .ts-control.focus {
        border-color: var(--brand-accent);
        box-shadow: 0 0 0 4px rgba(29, 43, 87, 0.10);
    }

    .rn-select-card .ts-dropdown {
        border-radius: 1rem;
        border-color: #e2e8f0;
        overflow: hidden;
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.14);
        font-size: 0.875rem;
    }

    .rn-select-card .ts-dropdown .option {
        padding: 0.75rem 0.85rem;
    }

    .rn-select-card .item {
        color: #0f172a;
        font-weight: 650;
    }

    .rn-field-help {
        margin-top: .35rem;
        font-size: 11px;
        line-height: 1.35;
        color: #94a3b8;
    }
</style>

<link rel="stylesheet" href="recurring-theme.css?v=<?= filemtime(__DIR__.'/recurring-theme.css') ?>">
<div class="rn-workspace min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8">
        <?php if ($flash): ?>
            <div class="mb-5 rounded-2xl border <?= !empty($flash['ok']) ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?> px-5 py-4 font-semibold shadow-sm">
                <i class="fas <?= !empty($flash['ok']) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mr-2"></i><?= rn_h($flash['message'] ?? '') ?>
            </div>
        <?php endif; ?>

        <section class="rn-hero relative overflow-hidden rounded-[34px] border border-slate-900/10 bg-gradient-to-r from-slate-950 via-[var(--brand-primary)] to-slate-900 px-7 py-9 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)] md:px-9 md:py-11">
            <div class="absolute -right-16 -top-16 h-56 w-56 rounded-full bg-[var(--brand-accent)]/25 blur-2xl"></div>
            <div class="absolute -bottom-20 left-20 h-48 w-48 rounded-full bg-white/10 blur-3xl"></div>
            <div class="relative grid gap-8 lg:grid-cols-[1.3fr_0.7fr] lg:items-end">
                <div>
                    <div class="mb-4 flex flex-wrap items-center gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200"><?= rn_h($layoutCompanyBranding['company_name'] ?? 'FINEA') ?> · Automatisation FEB</p>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-bell mr-2 text-[var(--brand-on-primary)]"></i><?= rn_number(count($alerts)) ?> point(s) à surveiller
                        </span>
                        <span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white">
                            <i class="fas fa-calendar-check mr-2 text-[var(--brand-on-primary)]"></i>Création automatique J-7
                        </span>
                    </div>
                    <h1 class="max-w-4xl text-3xl font-semibold tracking-tight md:text-5xl">Formulaire d'ajout de besoins récurrents</h1>
                    <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-200 md:text-base">
                        Centralisez les besoins périodiques, validez les modèles, déclenchez les rappels et préparez automatiquement les FEB avant l’échéance.
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <a href="index.php?status=pending_validation" class="inline-flex items-center justify-center rounded-2xl bg-white px-5 py-3 text-sm font-bold text-slate-950 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-amber-50">
                        <i class="fas fa-chart-line mr-2"></i>Tableau de bord besoins récurrents
                    </a>
                    <a href="../selection_portail.php" class="inline-flex items-center justify-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-table-cells-large mr-2"></i>Retour portail
                    </a>
                </div>
            </div>
        </section>

        <?= rn_dashboard_tabs($activeTab) ?>


        <div class="mt-8 grid gap-6 xl:grid-cols-[1.45fr_0.55fr]">
            <main class="space-y-6">

                <section id="nouveau-besoin" class="rounded-[30px] border border-white/80 bg-white p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-orange-600">Enregistrement</p>
                            <h2 class="mt-2 text-2xl font-bold text-slate-950">Créer un besoin récurrent</h2>
                            <p class="mt-2 text-sm text-slate-600">Le modèle sera soumis à validation avant génération automatique des FEB.</p>
                        </div>
                    </div>
                    <form method="post" class="mt-6 grid gap-4 lg:grid-cols-4">
                        <?php if ($editId > 0): ?><input type="hidden" name="id" value="<?= $editId ?>"><?php endif; ?>
                        <label class="lg:col-span-2">
                            <span class="text-sm font-semibold text-slate-700">Libellé du besoin</span>
                            <input name="title" value="<?= rn_h($editingNeed['title'] ?? '') ?>" required class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100" placeholder="Ex : Carburant hebdomadaire chantier A">
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Montant estimatif</span>
                            <input name="amount_estimated" value="<?= rn_h($editingNeed['amount_estimated'] ?? '') ?>" type="number" min="0" step="1" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100" placeholder="0">
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Priorité</span>
                            <select name="priority" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                                <?php foreach (['normale' => 'Normale', 'haute' => 'Haute', 'critique' => 'Critique', 'basse' => 'Basse'] as $value => $label): ?><option value="<?= $value ?>" <?= ($editingNeed['priority'] ?? 'normale') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Type</span>
                            <select name="need_type" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                                <option value="interne" <?= ($editingNeed['need_type'] ?? 'interne') === 'interne' ? 'selected' : '' ?>>Besoin interne</option>
                                <option value="externe" <?= ($editingNeed['need_type'] ?? '') === 'externe' ? 'selected' : '' ?>>Demande externe</option>
                            </select>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Mode financier</span>
                            <select name="financial_mode" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                                <?php foreach (['avance_provisoire' => 'Avance provisoire', 'decaissement_unique' => 'Décaissement unique', 'assignation_logistique' => 'Assignation logistique', 'sans_decaissement' => 'Sans décaissement'] as $value => $label): ?><option value="<?= $value ?>" <?= ($editingNeed['financial_mode'] ?? 'avance_provisoire') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Fréquence</span>
                            <select name="frequency_type" id="frequency_type" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                                <?php foreach (['weekly' => 'Hebdomadaire', 'monthly' => 'Mensuel', 'quarterly' => 'Trimestriel', 'semesterly' => 'Semestriel', 'yearly' => 'Annuel', 'daily' => 'Quotidien'] as $value => $label): ?><option value="<?= $value ?>" <?= ($editingNeed['frequency_type'] ?? 'monthly') === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span class="text-sm font-semibold text-slate-700">Intervalle</span>
                            <input name="frequency_interval" id="frequency_interval" type="number" min="1" value="<?= rn_h($editingNeed['frequency_interval'] ?? 1) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                        </label>
                        <div class="lg:col-span-4 grid gap-4 lg:grid-cols-3">
                            <label>
                                <span class="text-sm font-semibold text-slate-700">Date du premier paiement</span>
                                <input name="start_date" id="start_date" type="date" value="<?= rn_h($editingNeed['start_date'] ?? date('Y-m-d')) ?>" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                            </label>

                            <label>
                                <span class="text-sm font-semibold text-slate-700">Première échéance</span>
                                <div class="relative mt-2">
                                    <input name="next_due_date" id="next_due_date" type="date" value="<?= rn_h($editingNeed['next_due_date'] ?? (new DateTimeImmutable('+7 days'))->format('Y-m-d')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 pr-11 text-sm font-semibold text-slate-800 outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100">
                                    <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400">
                                        <i class="fas fa-calculator"></i>
                                    </span>
                                </div>
                                <p class="rn-field-help">Calculée automatiquement selon la fréquence et la date du premier paiement.</p>
                            </label>

                            <label>
                                <span class="text-sm font-semibold text-slate-700">Créer la FEB avant</span>

                                <div class="mt-2 flex h-[46px] overflow-hidden rounded-2xl border border-slate-200 bg-white focus-within:border-[var(--brand-accent)] focus-within:ring-4 focus-within:ring-blue-100">
                                    <input
                                        name="generate_days_before"
                                        type="number"
                                        min="0"
                                        value="<?= rn_h($editingNeed['generate_days_before'] ?? 7) ?>"
                                        class="h-full w-24 border-0 px-4 text-sm font-semibold text-slate-900 outline-none focus:ring-0"
                                        aria-describedby="generate_days_before_unit">
                                    <span id="generate_days_before_unit" class="flex flex-1 items-center border-l border-slate-200 bg-slate-50 px-4 text-xs font-semibold text-slate-600">
                                        jour(s) avant
                                    </span>
                                </div>

                                <p class="mt-1 text-[11px] leading-snug text-slate-400">
                                    Exemple : 7 = création automatique 7 jours avant l’échéance.
                                </p>
                            </label>
                        </div>

                        <div class="lg:col-span-4 space-y-2">
                            <label class="text-sm font-semibold text-slate-700" for="fournisseur_id">
                                Fournisseur
                            </label>

                            <div class="rn-select-card">
                                <span class="pointer-events-none absolute left-4 top-1/2 z-10 -translate-y-1/2 text-slate-400">
                                    <i class="fas fa-magnifying-glass"></i>
                                </span>

                                <select
                                    name="fournisseur_id"
                                    id="fournisseur_id"
                                    autocomplete="off"
                                    class="w-full">
                                    <option value=""></option>

                                    <?php foreach ($fournisseurs as $fournisseur): ?>
                                        <?php
                                        $supplierId = (int)($fournisseur['id_fournisseur'] ?? $fournisseur['id'] ?? 0);
                                        $supplierName = rn_supplier_display_name($fournisseur);
                                        $supplierCode = trim((string)($fournisseur['code_fournisseur'] ?? ''));
                                        $supplierPhone = trim((string)($fournisseur['telephone'] ?? ''));
                                        $supplierCity = trim((string)($fournisseur['ville'] ?? ''));
                                        $selectedSupplierId = (int)($editingNeed['fournisseur_id'] ?? 0);
                                        $supplierBlocked = !empty($fournisseur['disbursement_selection_blocked']);
                                        $supplierBlockLabel = trim((string)($fournisseur['disbursement_block_reason'] ?? '') . (!empty($fournisseur['disbursement_block_fiches']) ? ' · '.$fournisseur['disbursement_block_fiches'] : ''));
                                        $searchText = trim($supplierName . ' ' . $supplierCode . ' ' . $supplierPhone . ' ' . $supplierCity);
                                        ?>
                                        <option
                                            value="<?= $supplierId ?>"
                                            data-name="<?= rn_h($supplierName) ?>"
                                            data-code="<?= rn_h($supplierCode) ?>"
                                            data-phone="<?= rn_h($supplierPhone) ?>"
                                            data-city="<?= rn_h($supplierCity) ?>"
                                            data-search="<?= rn_h($searchText) ?>"
                                            data-block-reason="<?= rn_h($supplierBlockLabel) ?>"
                                            <?= $supplierBlocked ? 'disabled' : '' ?>
                                            <?= $selectedSupplierId === $supplierId ? 'selected' : '' ?>>
                                            <?= rn_h($supplierName) ?><?= $supplierCode !== '' ? ' · ' . rn_h($supplierCode) : '' ?><?= $supplierBlocked ? ' — INDISPONIBLE : '.rn_h($supplierBlockLabel) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="hidden" name="fournisseur_name" id="fournisseur_name" value="<?= rn_h($editingNeed['fournisseur_name'] ?? '') ?>">
                            </div>

                            <p class="rn-field-help">
                                Recherche par nom, code, téléphone ou ville. Le besoin reste lié à l’ID fournisseur en BDD.
                            </p>
                        </div>

                        <label class="lg:col-span-4">
                            <span class="text-sm font-semibold text-slate-700">Description / justification</span>
                            <textarea name="description" rows="3" class="mt-2 w-full rounded-2xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-[var(--brand-accent)] focus:ring-4 focus:ring-blue-100" placeholder="Préciser le contexte, les documents attendus et la logique de génération."><?= rn_h($editingNeed['description'] ?? '') ?></textarea>
                        </label>
                        <div class="lg:col-span-4 flex flex-wrap items-center justify-end gap-3 border-t border-slate-100 pt-4">
                            <button type="submit" name="save_as_draft" value="1" class="inline-flex items-center rounded-2xl border border-slate-200 px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"><i class="fas fa-file-pen mr-2"></i>Brouillon</button>
                            <button type="submit" class="inline-flex items-center rounded-2xl bg-[var(--brand-primary)] px-5 py-3 text-sm font-semibold text-white shadow-lg shadow-slate-900/15 transition hover:-translate-y-0.5"><i class="fas fa-paper-plane mr-2 text-[var(--brand-on-primary)]"></i><?= $editId > 0 ? 'Enregistrer les modifications' : 'Soumettre' ?></button>
                        </div>
                    </form>
                </section>


            </main>

            <aside class="space-y-6 xl:sticky xl:top-6 xl:self-start">
                <section class="rn-quick rounded-[30px] border border-slate-200 bg-slate-950 p-7 text-white shadow-[0_22px_70px_rgba(15,23,42,0.28)]">
                    <div class="mb-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-200">Accès rapides</p>
                        <h2 class="mt-2 text-2xl font-semibold">Besoins récurrents</h2>
                        <p class="mt-2 text-sm text-slate-300">Les actions fréquentes du module.</p>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($sideLinks as $index => $link): ?>
                            <a href="<?= rn_h($link['href']) ?>" class="relative block rounded-2xl border <?= $index < 2 ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10' ?> px-4 py-4 transition">
                                <div class="flex items-center gap-3">
                                    <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-[var(--brand-on-primary)]"><i class="fas <?= rn_h($link['icon']) ?>"></i></span>
                                    <span><span class="block font-semibold"><?= rn_h($link['label']) ?></span><span class="text-sm text-slate-400"><?= rn_h($link['hint']) ?></span></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

            </aside>


        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const supplierSelect = document.getElementById('fournisseur_id');
        const supplierNameInput = document.getElementById('fournisseur_name');

        if (supplierSelect && window.TomSelect) {
            const tom = new TomSelect(supplierSelect, {
                create: false,
                maxOptions: 500,
                allowEmptyOption: true,
                placeholder: 'Rechercher un fournisseur...',
                searchField: ['text', 'search', 'code', 'phone', 'city'],
                plugins: {
                    clear_button: {
                        title: 'Retirer le fournisseur'
                    }
                },
                render: {
                    option: function(data, escape) {
                        const code = data.code ? `<span class="text-xs text-slate-400">${escape(data.code)}</span>` : '';
                        const meta = [data.phone, data.city].filter(Boolean).map(escape).join(' · ');

                        return `
                            <div class="flex items-start gap-3">
                                <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-[var(--brand-primary)]">
                                    <i class="fas fa-building"></i>
                                </div>
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 font-semibold text-slate-900">
                                        <span>${escape(data.text)}</span>
                                        ${code}
                                    </div>
                                    ${meta ? `<div class="mt-1 text-xs text-slate-500">${meta}</div>` : ''}
                                </div>
                            </div>
                        `;
                    },
                    item: function(data, escape) {
                        return `<div class="font-semibold">${escape(data.text)}</div>`;
                    },
                    no_results: function() {
                        return '<div class="px-3 py-3 text-sm text-slate-500">Aucun fournisseur trouvé</div>';
                    }
                },
                onChange: function(value) {
                    const option = supplierSelect.querySelector(`option[value="${CSS.escape(value)}"]`);
                    supplierNameInput.value = option ? (option.dataset.name || option.textContent.trim()) : '';
                }
            });

            const selected = supplierSelect.options[supplierSelect.selectedIndex];
            supplierNameInput.value = selected ? (selected.dataset.name || selected.textContent.trim()) : '';

            const placeholderText = 'Rechercher un fournisseur...';
            if (tom.control_input) {
                tom.control_input.placeholder = placeholderText;
            }

            tom.on('focus', function() {
                if (!tom.getValue() && tom.control_input) {
                    tom.control_input.placeholder = '';
                }
            });

            tom.on('blur', function() {
                if (!tom.getValue() && tom.control_input) {
                    tom.control_input.placeholder = placeholderText;
                }
            });
        }

        const frequencyInput = document.getElementById('frequency_type');
        const intervalInput = document.getElementById('frequency_interval');
        const startDateInput = document.getElementById('start_date');
        const nextDueInput = document.getElementById('next_due_date');

        function pad(value) {
            return String(value).padStart(2, '0');
        }

        function formatDate(date) {
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        }

        function addMonthsKeepingDay(date, months) {
            const originalDay = date.getDate();
            const result = new Date(date.getTime());

            result.setDate(1);
            result.setMonth(result.getMonth() + months);

            const lastDay = new Date(result.getFullYear(), result.getMonth() + 1, 0).getDate();
            result.setDate(Math.min(originalDay, lastDay));

            return result;
        }

        function calculateNextDueDate() {
            if (!frequencyInput || !intervalInput || !startDateInput || !nextDueInput || !startDateInput.value) {
                return;
            }

            const interval = Math.max(1, parseInt(intervalInput.value || '1', 10));
            const base = new Date(`${startDateInput.value}T00:00:00`);

            if (Number.isNaN(base.getTime())) {
                return;
            }

            let result = new Date(base.getTime());

            switch (frequencyInput.value) {
                case 'daily':
                    result.setDate(result.getDate() + interval);
                    break;
                case 'weekly':
                    result.setDate(result.getDate() + (7 * interval));
                    break;
                case 'monthly':
                    result = addMonthsKeepingDay(result, interval);
                    break;
                case 'quarterly':
                    result = addMonthsKeepingDay(result, 3 * interval);
                    break;
                case 'semesterly':
                    result = addMonthsKeepingDay(result, 6 * interval);
                    break;
                case 'yearly':
                    result = addMonthsKeepingDay(result, 12 * interval);
                    break;
                default:
                    result = addMonthsKeepingDay(result, interval);
            }

            nextDueInput.value = formatDate(result);
        }

        [frequencyInput, intervalInput, startDateInput].forEach(function(input) {
            if (input) {
                input.addEventListener('change', calculateNextDueDate);
                input.addEventListener('input', calculateNextDueDate);
            }
        });

        calculateNextDueDate();
    });
</script>
