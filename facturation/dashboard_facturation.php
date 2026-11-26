<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/FacturationController.php';
require_once __DIR__ . '/../model/FneClient.php';
require_once __DIR__ . '/../model/FneSalesInvoiceImportManager.php';
require_once __DIR__ . '/../model/FnePurchaseInvoiceImportManager.php';
require_once __DIR__ . '/../model/FnePartyLinkManager.php';

$pageTitle = 'Dashboard Facturation';
$activeMenu = 'facturation';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';

$pdo = Database::getConnection();
$controller = new FacturationController($pdo);
$achatModel = $controller->achats();
$venteModel = $controller->ventes();
$autoReconciliation=null;
if((int)($_SESSION['last_fne_auto_reconciliation_at']??0)<time()-600){
    try{
        $autoReconciliation=[
            'sales'=>(new FneSalesInvoiceImportManager($pdo))->reconcileAll(500),
            'purchases'=>(new FnePurchaseInvoiceImportManager($pdo))->reconcileAll(500),
        ];
        (new FnePartyLinkManager($pdo))->autoApplyAll();
        $_SESSION['last_fne_auto_reconciliation_at']=time();
    }catch(Throwable $ignored){}
}

$fneClient = FneClient::fromConfigFile();
$fneConfigured = $fneClient->isConfigured();
$fneBaseUrl = '';
try {
    $cfgPath = __DIR__ . '/../config/fne.php';
    if (is_file($cfgPath)) {
        $cfg = require $cfgPath;
        if (is_array($cfg)) {
            $fneBaseUrl = (string)($cfg['base_url'] ?? '');
        }
    }
} catch (Throwable $e) {
    $fneBaseUrl = '';
}

function renderFactureBadge(string $label, string $classes): string
{
    $base = 'inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold whitespace-nowrap';
    return '<span class="' . $base . ' ' . $classes . '">' . htmlspecialchars($label) . '</span>';
}

function renderAchatStatutBadge(string $statut): string
{
    $statut = trim($statut);
    switch ($statut) {
        case 'brouillon':
            return renderFactureBadge('Brouillon', 'border-slate-200 bg-slate-50 text-slate-700');
        case 'a_verifier':
            return renderFactureBadge('À vérifier', 'border-amber-200 bg-amber-50 text-amber-900');
        case 'verifiee':
            return renderFactureBadge('Vérifiée', 'border-emerald-200 bg-emerald-50 text-emerald-900');
        case 'rejetee':
            return renderFactureBadge('Rejetée', 'border-rose-200 bg-rose-50 text-rose-900');
        case 'payee':
            return renderFactureBadge('Payée', 'border-indigo-200 bg-indigo-50 text-indigo-900');
        default:
            return renderFactureBadge($statut !== '' ? $statut : '—', 'border-slate-200 bg-white text-slate-700');
    }
}

function renderVenteStatutBadge(string $statut): string
{
    $statut = trim($statut);
    switch ($statut) {
        case 'brouillon':
            return renderFactureBadge('Brouillon', 'border-slate-200 bg-slate-50 text-slate-700');
        case 'a_signer':
            return renderFactureBadge('À signer', 'border-amber-200 bg-amber-50 text-amber-900');
        case 'signe':
            return renderFactureBadge('Signée', 'border-emerald-200 bg-emerald-50 text-emerald-900');
        case 'erreur':
            return renderFactureBadge('Erreur', 'border-rose-200 bg-rose-50 text-rose-900');
        default:
            return renderFactureBadge($statut !== '' ? $statut : '—', 'border-slate-200 bg-white text-slate-700');
    }
}

$dashboardView = $controller->dashboardView($fneConfigured);
$achatStats = $dashboardView['achatStats'];
$venteStats = $dashboardView['venteStats'];
$achatDevises = $dashboardView['achatDevises'];
$achatLatest = $dashboardView['achatLatest'];
$venteLatest = $dashboardView['venteLatest'];
$venteAmounts = $dashboardView['venteAmounts'];
$chantierBillingSummary = $dashboardView['chantierBillingSummary'] ?? [];
$alerts = $dashboardView['alerts'];
$chartAchatStatus = $dashboardView['chartAchatStatus'];
$chartVenteStatus = $dashboardView['chartVenteStatus'];?>

<link rel="stylesheet" href="../assets/css/billing-workspace.css?v=20261126a">
<div class="billing-workspace min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="relative overflow-hidden rounded-[32px] border border-indigo-100 billing-banner px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="absolute -right-16 -top-16 h-48 w-48 rounded-full bg-white/10 blur-2xl"></div>
            <div class="absolute bottom-0 right-24 h-24 w-24 rounded-full bg-indigo-300/20 blur-2xl"></div>
            <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-indigo-200">Facturation</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Pilotage de la facturation</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Suivez les factures clients, les achats fournisseurs, les encaissements et les contrôles FNE.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="../selection_portail.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-th-large mr-2"></i>
                        Portail
                    </a>
                    <a href="factures_achat.php" class="inline-flex items-center rounded-2xl bg-white/10 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/20">
                        <i class="fas fa-file-invoice mr-2"></i>
                        Achats
                    </a>
                    <a href="import_factures_achat_fne.php" class="inline-flex items-center rounded-2xl bg-emerald-400/20 px-5 py-3 text-sm font-semibold text-emerald-50 transition hover:bg-emerald-400/30">
                        <i class="fas fa-file-import mr-2"></i>
                        Import FNE achats
                    </a>
                    <a href="factures_vente.php" class="inline-flex items-center rounded-2xl bg-white/10 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/20">
                        <i class="fas fa-receipt mr-2"></i>
                        Ventes
                    </a>
                    <a href="import_factures_vente_fne.php" class="inline-flex items-center rounded-2xl bg-amber-400/20 px-5 py-3 text-sm font-semibold text-amber-50 transition hover:bg-amber-400/30">
                        <i class="fas fa-file-import mr-2"></i>
                        Import FNE ventes
                    </a>
                    <a href="tresorerie_chantiers.php" class="inline-flex items-center rounded-2xl bg-emerald-500/20 px-5 py-3 text-sm font-semibold text-emerald-50 transition hover:bg-emerald-500/30">
                        <i class="fas fa-vault mr-2"></i>
                        Trésorerie chantiers
                    </a>
                    <a href="recherche_factures.php" class="inline-flex items-center rounded-2xl bg-white/10 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/20">
                        <i class="fas fa-search mr-2"></i>
                        Recherche
                    </a>
                    <a href="parametres_facturation.php" class="inline-flex items-center rounded-2xl border border-white/20 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders mr-2"></i>
                        Paramètres
                    </a>
                </div>
            </div>
        </div>

        <nav class="billing-nav" aria-label="Parcours de facturation">
            <section><h2><i class="fas fa-receipt mr-2"></i>Ventes clients</h2><a href="factures_vente.php">Registre des ventes et encaissements</a><a href="import_factures_vente_fne.php">Importer les factures FNE</a></section>
            <section><h2><i class="fas fa-file-invoice mr-2"></i>Achats fournisseurs</h2><a href="factures_achat.php">Contrôler les achats et paiements</a><a href="import_factures_achat_fne.php">Importer les achats FNE</a></section>
            <section><h2><i class="fas fa-vault mr-2"></i>Trésorerie</h2><a href="tresorerie_chantiers.php">Trésorerie des chantiers et TVA</a></section>
            <section><h2><i class="fas fa-shield-alt mr-2"></i>Contrôle et conformité</h2><a href="verifier_facture_fne.php">Vérifier une facture FNE</a><a href="recherche_factures.php">Rechercher une facture</a><a href="parametres_facturation.php">Paramètres</a></section>
        </nav>
        <?php if (!$achatModel->isReady()): ?>
            <div class="mt-8 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($achatModel->getInitError() ?: 'Le module factures d\'achat n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <?php if (!$venteModel->isReady()): ?>
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <?= htmlspecialchars($venteModel->getInitError() ?: 'Le module factures de vente n\'a pas pu initialiser ses tables.') ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <p class="text-sm font-medium text-slate-500">Achats</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$achatStats['total'] ?></p>
                <p class="mt-2 text-sm text-slate-500">FEB + fiches de decaissement</p>
            </div>
            <div class="rounded-[28px] border border-indigo-100 bg-white p-6 shadow-[0_16px_50px_rgba(99,102,241,0.10)]">
                <p class="text-sm font-medium text-indigo-700">À vérifier</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$achatStats['a_verifier'] ?></p>
                <p class="mt-2 text-sm text-slate-500">FEB approuvees sans fiche creee</p>
            </div>
            <div class="rounded-[28px] border border-emerald-100 bg-white p-6 shadow-[0_16px_50px_rgba(16,185,129,0.10)]">
                <p class="text-sm font-medium text-emerald-700">Vérifiées</p>
                <p class="mt-4 text-3xl font-semibold text-slate-950"><?= (int)$achatStats['verifiee'] ?></p>
                <p class="mt-2 text-sm text-slate-500">Fiches creees, non decaissees</p>
            </div>
            <div class="rounded-[28px] border border-violet-100 bg-white p-6 shadow-[0_16px_50px_rgba(139,92,246,0.12)]">
                <p class="text-sm font-medium text-violet-700">FNE</p>
                <p class="mt-4 text-2xl font-semibold text-slate-950"><?= $fneConfigured ? 'Configuré' : 'À configurer' ?></p>
                <p class="mt-2 text-sm text-slate-500">Base: <?= htmlspecialchars($fneBaseUrl !== '' ? $fneBaseUrl : '—') ?></p>
            </div>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-3">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur lg:col-span-2">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <p class="text-sm font-semibold text-slate-950">Répartition des statuts</p>
                        <p class="mt-1 text-sm text-slate-500">Achats par étape de contrôle et ventes par état d’encaissement.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="factures_achat.php" class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                            <i class="fas fa-file-invoice mr-2"></i>
                            Consulter les achats
                        </a>
                        <a href="factures_vente.php" class="inline-flex items-center rounded-2xl bg-white px-4 py-2 text-sm font-semibold text-slate-900 shadow-sm ring-1 ring-slate-200 hover:bg-slate-50">
                            <i class="fas fa-receipt mr-2 text-slate-500"></i>
                            Consulter les ventes
                        </a>
                    </div>
                </div>

                <div class="mt-6 grid gap-6 md:grid-cols-2">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-950">Achats</p>
                            <p class="text-xs text-slate-500">Dernière activité : <?= htmlspecialchars((string)($achatStats['last_created_at'] ?? '—')) ?></p>
                        </div>
                        <div class="mt-4 h-56">
                            <canvas id="chartAchatStatus"></canvas>
                        </div>
                        <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Brouillon</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['brouillon'] ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">À vérifier</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['a_verifier'] ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Payée</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['payee'] ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-950">Ventes</p>
                            <p class="text-xs text-slate-500">Dernière facture : <?= htmlspecialchars((string)($venteStats['last_created_at'] ?? '—')) ?></p>
                        </div>
                        <div class="mt-4 h-56"><canvas id="chartVenteStatus"></canvas></div>
                        <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Payées / encaissées</div>
                                <div class="font-semibold text-slate-950"><?= (int)($venteStats['payee']??0) ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Émises / à percevoir</div>
                                <div class="font-semibold text-slate-950"><?= (int)($venteStats['emise']??0) ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-sm font-semibold text-slate-950">Montants achats</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars(number_format((float)$achatStats['amount_total_sum'], 2, '.', ' ')) ?></p>
                        <p class="mt-1 text-sm text-slate-500">FEB non transformees + fiches de decaissement.</p>
                        <?php if ($achatDevises): ?>
                            <div class="mt-3 space-y-1 text-xs text-slate-600">
                                <?php foreach ($achatDevises as $row): ?>
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="font-medium"><?= htmlspecialchars((string)($row['devise'] ?? '')) ?></span>
                                        <span><?= (int)($row['c'] ?? 0) ?> • <?= htmlspecialchars(number_format((float)($row['s'] ?? 0), 2, '.', ' ')) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-sm font-semibold text-slate-950">Montant des ventes</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars(number_format((float)$venteAmounts['sum'], 2, '.', ' ')) ?></p>
                        <p class="mt-1 text-sm text-slate-500">Historique complet • conversion devise si taux (XOF).</p>
                        <?php if (!empty($venteAmounts['converted_count']) || !empty($venteAmounts['missing_rate_count'])): ?>
                            <div class="mt-1 text-xs text-slate-500">
                                <?php if (!empty($venteAmounts['converted_count'])): ?>
                                    <span><?= (int)$venteAmounts['converted_count'] ?> convertie(s)</span>
                                <?php endif; ?>
                                <?php if (!empty($venteAmounts['missing_rate_count'])): ?>
                                    <span class="<?= !empty($venteAmounts['converted_count']) ? 'ml-2' : '' ?>"><?= (int)$venteAmounts['missing_rate_count'] ?> sans taux</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="mt-4 h-40">
                            <canvas id="chartVenteAmount"></canvas>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-sm font-semibold text-slate-950">Qualité des données</p>
                        <p class="mt-1 text-sm text-slate-500">Points qui bloquent la vérification / audit.</p>
                        <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Sans pièce</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['missing_piece'] ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Rejetee</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['rejetee'] ?></div>
                            </div>
                            <div class="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2">
                                <div class="text-xs text-slate-500">Verifiee</div>
                                <div class="font-semibold text-slate-950"><?= (int)$achatStats['verifiee'] ?></div>
                            </div>
                        </div>
                        <div class="mt-4 text-xs text-slate-500">Dernière création achats: <?= htmlspecialchars((string)($achatStats['last_created_at'] ?? '—')) ?></div>
                        <div class="mt-1 text-xs text-slate-500">Dernière création ventes: <?= htmlspecialchars((string)($venteStats['last_created_at'] ?? '—')) ?></div>
                    </div>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <p class="text-sm font-semibold text-slate-950">Alertes</p>
                <p class="mt-1 text-sm text-slate-500">À traiter rapidement.</p>

                <div class="mt-4 space-y-3">
                    <?php if (!$alerts): ?>
                        <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                            Aucun point bloquant détecté.
                        </div>
                    <?php endif; ?>

                    <?php foreach ($alerts as $a): ?>
                        <?php
                        $type = (string)($a['type'] ?? 'info');
                        $cls = 'border-slate-200 bg-slate-50 text-slate-800';
                        if ($type === 'warn') {
                            $cls = 'border-amber-200 bg-amber-50 text-amber-900';
                        }
                        if ($type === 'danger') {
                            $cls = 'border-rose-200 bg-rose-50 text-rose-900';
                        }
                        ?>
                        <div class="rounded-2xl border px-4 py-3 text-sm <?= $cls ?>">
                            <div class="flex items-start justify-between gap-3">
                                <div class="font-medium"><?= htmlspecialchars((string)$a['label']) ?></div>
                                <?php if ($a['count'] !== null): ?>
                                    <div class="rounded-xl bg-white/70 px-2 py-1 text-xs font-semibold"><?= (int)$a['count'] ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="mt-6">
                    <p class="text-sm font-semibold text-slate-950">Accès rapide</p>
                    <div class="mt-3 grid gap-3">
                        <a href="factures_achat.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 hover:bg-slate-50">
                            <span class="font-medium"><i class="fas fa-file-invoice mr-2 text-slate-400"></i>Factures d'achat</span>
                            <span class="text-slate-400 group-hover:text-slate-600">→</span>
                        </a>
                        <a href="factures_vente.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 hover:bg-slate-50">
                            <span class="font-medium"><i class="fas fa-receipt mr-2 text-slate-400"></i>Factures de vente</span>
                            <span class="text-slate-400 group-hover:text-slate-600">→</span>
                        </a>
                        <a href="verifier_facture_fne.php" class="group flex items-center justify-between rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-800 hover:bg-slate-50">
                            <span class="font-medium"><i class="fas fa-shield-halved mr-2 text-emerald-600"></i>Vérifier une facture FNE</span>
                            <span class="text-slate-400 group-hover:text-slate-600">→</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-6 rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-slate-950">Suivi chiffre d'affaires par chantier</p>
                    <p class="mt-1 text-sm text-slate-500">Devis total, montant perçu, décaissements effectifs et reste à percevoir.</p>
                </div>
                <a href="factures_vente.php" class="inline-flex items-center rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                    Ouvrir les factures chantier
                </a>
            </div>
            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b text-left text-slate-500">
                            <th class="py-2 pr-4">Chantier</th>
                            <th class="py-2 pr-4 text-right">Devis</th>
                            <th class="py-2 pr-4 text-right">Perçu</th>
                            <th class="py-2 pr-4 text-right">Décaissé</th>
                            <th class="py-2 pr-4 text-right">Reste à percevoir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($chantierBillingSummary as $row): ?>
                            <?php
                            $devisTotal = (float)($row['devis_total'] ?? 0);
                            $percu = (float)($row['montant_percu'] ?? 0);
                            $decaisse = (float)($row['montant_decaisse'] ?? 0);
                            ?>
                            <tr>
                                <td class="py-2 pr-4 font-medium text-slate-900"><?= htmlspecialchars(trim((string)($row['chantier_code'] ?? '') . ' - ' . (string)($row['chantier_titre'] ?? '')), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-2 pr-4 text-right"><?= number_format($devisTotal, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right font-semibold text-emerald-700"><?= number_format($percu, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right font-semibold text-rose-700"><?= number_format($decaisse, 0, ',', ' ') ?></td>
                                <td class="py-2 pr-4 text-right"><?= number_format(max(0, $devisTotal - $percu), 0, ',', ' ') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($chantierBillingSummary)): ?>
                            <tr><td colspan="5" class="py-5 text-center text-slate-500">Aucune donnée chantier.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-slate-950">Derniers achats</p>
                    <a class="text-sm font-semibold text-indigo-700 hover:underline" href="factures_achat.php">Voir tout</a>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b">
                                <th class="py-2 pr-4">Origine</th>
                                <th class="py-2 pr-4">Montant</th>
                                <th class="py-2 pr-4">Statut</th>
                                <th class="py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php if (!$achatLatest): ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-slate-500">Aucune donnée.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($achatLatest as $fa): ?>
                                <tr class="text-slate-800">
                                    <td class="py-2 pr-4 font-medium">
                                        <div><?= htmlspecialchars((string)($fa['label'] ?? '')) ?></div>
                                        <div class="text-xs text-slate-500"><?= htmlspecialchars((string)($fa['reference'] ?? '')) ?><?= !empty($fa['source']) ? ' · ' . htmlspecialchars(strtoupper((string)$fa['source'])) : '' ?></div>
                                    </td>
                                    <td class="py-2 pr-4 text-slate-600"><?= htmlspecialchars(number_format((float)($fa['amount_total'] ?? 0), 0, ',', ' ')) ?> <?= htmlspecialchars((string)($fa['devise'] ?? 'FCFA')) ?></td>
                                    <td class="py-2 pr-4"><?= renderAchatStatutBadge((string)($fa['statut'] ?? '')) ?></td>
                                    <td class="py-2 text-sm">
                                        <?php if (!empty($fa['primary_url'])): ?>
                                            <a class="text-indigo-700 hover:underline" href="<?= htmlspecialchars((string)$fa['primary_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($fa['primary_label'] ?? 'Ouvrir')) ?></a>
                                        <?php endif; ?>
                                        <?php if (!empty($fa['document_url'])): ?>
                                            <a class="ml-2 text-slate-700 hover:underline" target="_blank" rel="noopener" href="<?= htmlspecialchars((string)$fa['document_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($fa['document_label'] ?? 'Piece')) ?></a>
                                        <?php endif; ?>
                                        <?php if (!empty($fa['secondary_url'])): ?>
                                            <a class="ml-2 text-emerald-700 hover:underline" href="<?= htmlspecialchars((string)$fa['secondary_url'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($fa['secondary_label'] ?? 'Suite')) ?></a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rounded-[28px] border border-white/70 bg-white/90 p-6 shadow-[0_16px_50px_rgba(15,23,42,0.06)] backdrop-blur">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-sm font-semibold text-slate-950">Dernières ventes</p>
                    <a class="text-sm font-semibold text-indigo-700 hover:underline" href="factures_vente.php">Voir tout</a>
                </div>
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-slate-500 border-b">
                                <th class="py-2 pr-4">Client</th>
                                <th class="py-2 pr-4">Template</th>
                                <th class="py-2 pr-4">Encaissement</th>
                                <th class="py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php if (!$venteLatest): ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-slate-500">Aucune donnée.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($venteLatest as $fv): ?>
                                <tr class="text-slate-800">
                                    <td class="py-2 pr-4 font-medium"><?= htmlspecialchars((string)($fv['client_company_name'] ?? '')) ?></td>
                                    <td class="py-2 pr-4 text-slate-600"><?= htmlspecialchars((string)($fv['template'] ?? '')) ?></td>
                                    <td class="py-2 pr-4 font-semibold"><?=((string)($fv['paiement_statut']??'emise'))==='payee'?'Payée / encaissée':'Émise / à percevoir'?></td>
                                    <td class="py-2">
                                        <a class="text-indigo-700 hover:underline" href="edit_facture_vente.php?id=<?= (int)($fv['id_facture_vente'] ?? 0) ?>">Éditer</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
    (function() {
        if (typeof Chart === 'undefined') {
            return;
        }

        const achat = <?= json_encode($chartAchatStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const vente = <?= json_encode($chartVenteStatus, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const venteAmount = <?= json_encode($venteAmounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const elA = document.getElementById('chartAchatStatus');
        if (elA) {
            new Chart(elA.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: achat.labels,
                    datasets: [{
                        data: achat.data,
                        backgroundColor: [
                            'rgba(148,163,184,0.28)',
                            'rgba(99,102,241,0.28)',
                            'rgba(16,185,129,0.28)',
                            'rgba(244,63,94,0.22)',
                            'rgba(245,158,11,0.22)'
                        ],
                        borderColor: [
                            'rgba(148,163,184,0.60)',
                            'rgba(99,102,241,0.60)',
                            'rgba(16,185,129,0.60)',
                            'rgba(244,63,94,0.50)',
                            'rgba(245,158,11,0.50)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            enabled: true
                        }
                    }
                }
            });
        }

        const elV = document.getElementById('chartVenteStatus');
        if (elV) {
            const venteHasData = vente.data.some(value => Number(value || 0) > 0);
            new Chart(elV.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: venteHasData ? vente.labels : ['Aucune vente sur chantier actif'],
                    datasets: [{
                        data: venteHasData ? vente.data : [1],
                        backgroundColor: venteHasData ? [
                            'rgba(16,185,129,0.82)',
                            'rgba(99,102,241,0.82)'
                        ] : ['rgba(226,232,240,0.65)'],
                        borderColor: venteHasData ? [
                            'rgb(5,150,105)',
                            'rgb(79,70,229)'
                        ] : ['rgba(148,163,184,0.45)'],
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            enabled: true
                        }
                    }
                }
            });
        }

        const elVA = document.getElementById('chartVenteAmount');
        if (elVA && venteAmount && Array.isArray(venteAmount.labels)) {
            new Chart(elVA.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: venteAmount.labels,
                    datasets: [{
                        label: 'Montant',
                        data: venteAmount.data || [],
                        backgroundColor: 'rgba(99,102,241,0.22)',
                        borderColor: 'rgba(99,102,241,0.55)',
                        borderWidth: 1,
                        borderRadius: 8,
                        maxBarThickness: 32
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            enabled: true
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            grid: {
                                color: 'rgba(148,163,184,0.25)'
                            },
                            ticks: {
                                color: '#64748b'
                            }
                        }
                    }
                }
            });
        }
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
