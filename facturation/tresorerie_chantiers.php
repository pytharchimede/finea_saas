<?php

declare(strict_types=1);
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/ManualTreasuryService.php';
require_once __DIR__ . '/../services/TreasuryFundingReservationService.php';
$canManageTreasury = AccessManager::hasPermission('acces_direction') || AccessManager::hasPermission('acces_admin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$canManageTreasury) {
    http_response_code(403);
    exit('Accès en lecture seule : opération de trésorerie interdite.');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$pdo = Database::getConnection();
$service = new ManualTreasuryService($pdo);
$today = new DateTimeImmutable('today');
$preset = (string)($_GET['period'] ?? 'month');
$ranges = ['day' => [$today, $today], 'week' => [$today->modify('monday this week'), $today->modify('sunday this week')], 'month' => [$today->modify('first day of this month'), $today->modify('last day of this month')], 'year' => [$today->modify('first day of january'), $today->modify('last day of december')]];
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_from'] ?? '')) ? (string)$_GET['date_from'] : ($ranges[$preset][0] ?? $ranges['month'][0])->format('Y-m-d');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date_to'] ?? '')) ? (string)$_GET['date_to'] : ($ranges[$preset][1] ?? $ranges['month'][1])->format('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
if (empty($_SESSION['manual_treasury_csrf'])) $_SESSION['manual_treasury_csrf'] = bin2hex(random_bytes(32));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'return_real_entries') {
    try {
        if (!hash_equals((string)$_SESSION['manual_treasury_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        $service->returnToRealEntries((float)($_POST['amount'] ?? 0), (string)($_POST['transfer_date'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), (int)($_SESSION['user_id'] ?? 0));
        $_SESSION['treasury_flash'] = 'Retour exécuté : le disponible réel a été débité et les entrées réelles recréditées.';
    } catch (Throwable $e) {
        $_SESSION['treasury_flash'] = $e->getMessage();
    }
    header('Location: tresorerie_chantiers.php?date_from=' . urlencode($from) . '&date_to=' . urlencode($to));
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['manual_treasury_csrf'], (string)($_POST['_csrf'] ?? ''))) throw new RuntimeException('Session expirée.');
        $action = (string)($_POST['action'] ?? '');
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($action === 'opening') {
            $service->saveOpening((string)($_POST['effective_date'] ?? ''), (float)($_POST['amount'] ?? 0), (string)($_POST['note'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'Solde initial de trésorerie enregistré dans les entrées réelles.';
        } elseif ($action === 'movement') {
            $service->saveMovement($_POST, $uid);
            $_SESSION['treasury_flash'] = 'Mouvement de trésorerie enregistré.';
        } elseif ($action === 'transfer_available') {
            $service->transferToAvailable((float)($_POST['amount'] ?? 0), (string)($_POST['transfer_date'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'Transfert exécuté : les entrées réelles ont été débitées et le disponible réel crédité.';
        } elseif ($action === 'confirm_invoice') {
            $received = ($_POST['received'] ?? '') === 'yes';
            $service->confirmInvoiceCollection((int)($_POST['invoice_id'] ?? 0), $received, (string)($_POST['new_date'] ?? '') ?: null, $uid);
            $_SESSION['treasury_flash'] = $received ? 'Encaissement confirmé : la facture est passée des prévisions aux entrées réelles.' : 'Encaissement reporté sans mouvement de trésorerie.';
        } elseif ($action === 'cancel_invoice') {
            $service->cancelInvoiceCollection((int)($_POST['invoice_id'] ?? 0), (string)($_POST['cancel_reason'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'Le suivi d’encaissement de la facture a été annulé et la décision enregistrée.';
        } elseif ($action === 'authorize_repayment') {
            $service->authorizeRepayment((int)($_POST['movement_id'] ?? 0), $uid);
            $_SESSION['treasury_flash'] = 'Remboursement autorisé et inscrit en sortie réelle.';
        } elseif ($action === 'remit_vat') {
            $service->remitVat((float)($_POST['amount'] ?? 0), (string)($_POST['payment_date'] ?? ''), (string)($_POST['period_start'] ?? ''), (string)($_POST['period_end'] ?? ''), (string)($_POST['reference'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'Reversement TVA enregistré comme sortie fiscale réelle.';
        } elseif ($action === 'borrow_vat') {
            $service->borrowVat((float)($_POST['amount'] ?? 0), (string)($_POST['borrow_date'] ?? ''), (string)($_POST['due_date'] ?? ''), (string)($_POST['reason'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'TVA mobilisée temporairement : le montant est désormais utilisable et devra être restitué à l’échéance.';
        } elseif ($action === 'repay_vat') {
            $service->repayVatLoan((int)($_POST['loan_id'] ?? 0), $uid);
            $_SESSION['treasury_flash'] = 'Restitution TVA effectuée : la somme a été replacée dans la réserve fiscale.';
        } elseif ($action === 'defer_vat') {
            $service->deferVatLoan((int)($_POST['loan_id'] ?? 0), (string)($_POST['new_due_date'] ?? ''), (string)($_POST['reason'] ?? ''), $uid);
            $_SESSION['treasury_flash'] = 'Échéance de restitution TVA reportée et historique conservé.';
        } else throw new RuntimeException('Action inconnue.');
    } catch (Throwable $e) {
        $_SESSION['treasury_flash'] = $e->getMessage();
    }
    header('Location: tresorerie_chantiers.php?date_from=' . urlencode($from) . '&date_to=' . urlencode($to));
    exit;
}
$data = $service->dashboard($from, $to);
$catalogs = $service->catalogs();
$t = $data['totals'];
$vat = $data['vat'] ?? ['vat_collected'=>0,'vat_remitted'=>0,'vat_reserve_gross'=>0,'vat_borrowed_total'=>0,'vat_outstanding'=>0,'vat_protected'=>0,'vat_free_to_borrow'=>0,'snapshots'=>[],'loans'=>[],'due_loans'=>[]];
$flash = (string)($_SESSION['treasury_flash'] ?? '');
unset($_SESSION['treasury_flash']);
$money = static fn($v) => number_format((float)$v, 0, ',', ' ') . ' FCFA';
$treasuryAccessRows = [];
$treasuryAccessError = '';
try {
    $accessColumns = $pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    if (!in_array('acces_tresorerie_lecture', $accessColumns, true)) {
        $pdo->exec('ALTER TABLE user_devis_banamur ADD COLUMN acces_tresorerie_lecture TINYINT(1) NOT NULL DEFAULT 0');
    }
    $treasuryAccessRows = $pdo->query("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) nom,mail_pro,acces_admin,acces_direction,acces_tresorerie_lecture,acces_recommandations_tresorerie FROM user_devis_banamur WHERE active=1 AND (acces_admin=1 OR acces_direction=1 OR acces_tresorerie_lecture=1 OR acces_recommandations_tresorerie=1) ORDER BY acces_admin DESC,acces_direction DESC,acces_tresorerie_lecture DESC,acces_recommandations_tresorerie DESC,nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $accessError) {
    $treasuryAccessError = 'Le registre des accès est momentanément indisponible.';
    error_log('Treasury access register: ' . $accessError->getMessage());
}
$pageTitle = 'Trésorerie';
$activeMenu = 'tresorerie_pilotage';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
$fundingService = new TreasuryFundingReservationService($pdo);
$data['fundingReservationTotals'] = $fundingService->totals();
$data['fundingReservations'] = $fundingService->activeReservations();
$unreservedRealEntries = (float)$data['realEntriesBalance'] - (float)$data['fundingReservationTotals']['real'];
$cardGroups = [
    ['in', 'Entrées', 'Ce qui alimente la trésorerie', [
        ['real-in', 'Solde des entrées réelles', $data['realEntriesBalance'], 'Fonds exploitables hors TVA collectée', 'real', $data['fundingReservationTotals']['real']],
        ['forecast-in', 'Entrées prévisionnelles', $t['forecast_in'], 'Échéances de la période + toutes les factures en retard', 'forecast', $data['fundingReservationTotals']['forecast']],
        ['unreserved-in', 'Solde non réservé', $unreservedRealEntries, 'Solde des entrées réelles après déduction des fonds réservés aux FEB', 'real', null],
    ]],
    ['balance', 'Soldes', 'L’équilibre entre les entrées et les sorties', [
        ['available', 'Disponible réel du jour', $data['available'], 'Transferts reçus − sorties réellement exécutées', 'real', null],
        ['validation-purchase', 'En validation achat', $t['validation_purchase'], 'FEB exigibles actuellement prêtes pour la validation achat', 'forecast', null],
    ]],
    ['out', 'Sorties', 'Ce qui réduit la trésorerie', [
        ['real-out', 'FEB à traiter', $t['real_out'], 'FEB en attente de traitement prévues au plus tard à la fin de période', 'forecast', null],
        ['forecast-out', 'FEB à venir', $t['forecast_out'], 'FEB en attente de traitement prévues après la fin de période', 'forecast', null],
        ['projected-out', 'Projection déboursé', $t['projected_debourse'], 'Reste à payer de tous les chantiers actifs, après engagements FEB/fiches non refusés', 'forecast', null],
    ]],
];
?>
<link rel="stylesheet" href="../assets/css/billing-workspace.css?v=20261126a">
<div class="billing-workspace min-h-screen pb-14">
    <main class="<?= $canManageTreasury ? '' : 'treasury-readonly ' ?>mx-auto max-w-7xl px-4 py-8">
        <header class="overflow-hidden rounded-[30px] bg-gradient-to-br from-indigo-800 via-blue-700 to-cyan-700 p-7 text-white shadow-xl">
            <div class="flex flex-wrap items-end justify-between gap-5">
                <div>
                    <p class="text-xs font-black uppercase tracking-[.25em] text-cyan-100">Situation financière en temps réel</p>
                    <h1 class="mt-2 text-3xl font-black">Trésorerie</h1>
                    <p class="mt-2 max-w-3xl text-sm text-blue-50">Les fonds reçus restent dans les entrées réelles jusqu’à leur transfert explicite vers le disponible. Un même montant ne figure jamais simultanément dans les deux soldes.</p><?php if (!$canManageTreasury): ?><span class="mt-3 inline-flex rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[10px] font-black uppercase text-cyan-100">Consultation uniquement</span><?php endif; ?>
                </div><?php if ($canManageTreasury): ?><div class="flex flex-wrap gap-2"><button data-open="openingModal" class="rounded-xl bg-violet-500 px-4 py-3 text-sm font-black text-white"><i class="fas fa-wallet mr-2"></i>Initialiser le solde</button><button data-open="transferModal" class="rounded-xl bg-amber-400 px-4 py-3 text-sm font-black text-amber-950"><i class="fas fa-right-left mr-2"></i>Alimenter le disponible</button><button data-open="movementModal" data-direction="in" class="rounded-xl bg-emerald-500 px-4 py-3 text-sm font-black text-white"><i class="fas fa-arrow-down mr-2"></i>Nouvelle entrée</button><button data-open="movementModal" data-direction="out" class="rounded-xl bg-rose-500 px-4 py-3 text-sm font-black text-white"><i class="fas fa-arrow-up mr-2"></i>Nouvelle sortie</button></div><?php endif; ?>
            </div>
        </header>
        <nav class="mt-5 flex w-full gap-1 rounded-2xl border border-slate-200 bg-white p-1.5 shadow-sm" aria-label="Vues trésorerie">
            <button type="button" data-treasury-tab="treasury" class="flex-1 rounded-xl bg-indigo-700 px-4 py-3 text-sm font-black text-white shadow-sm transition"><i class="fas fa-wallet mr-2"></i>Trésorerie</button>
            <button type="button" data-treasury-tab="vat" class="flex-1 rounded-xl px-4 py-3 text-sm font-black text-slate-600 transition hover:bg-slate-50"><i class="fas fa-receipt mr-2"></i>Fiscalité TVA</button>
        </nav>
        <div data-treasury-panel="treasury">
        <?php if ($canManageTreasury): ?><div class="mt-3 flex justify-end"><button type="button" data-open="returnModal" class="rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-black text-amber-800 shadow-sm"><i class="fas fa-rotate-left mr-2"></i>Retourner le disponible aux entrées</button></div><?php endif; ?>
        <?php if ($flash !== ''): ?><div class="mt-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 font-bold text-indigo-900"><?= htmlspecialchars($flash) ?></div><?php endif; ?>
        <form method="get" class="mt-5 flex flex-wrap items-end gap-3 rounded-2xl border bg-white p-4 shadow-sm">
            <div class="flex flex-wrap gap-2"><?php foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'year' => 'Année'] as $key => $label): ?><a href="?period=<?= $key ?>" class="rounded-full px-4 py-2 text-sm font-bold <?= $preset === $key ? 'bg-indigo-700 text-white' : 'bg-slate-100 text-slate-700' ?>"><?= $label ?></a><?php endforeach; ?></div><label class="ml-auto text-xs font-bold text-slate-500">Du<input type="date" name="date_from" value="<?= htmlspecialchars($from) ?>" class="ml-2 rounded-xl border-slate-200"></label><label class="text-xs font-bold text-slate-500">Au<input type="date" name="date_to" value="<?= htmlspecialchars($to) ?>" class="ml-2 rounded-xl border-slate-200"></label><button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700">Afficher</button>
        </form>
        <div class="mt-3 flex justify-end"><a target="_blank" href="request/export_treasury_pdf.php?view=global&amp;from=<?= urlencode($from) ?>&amp;to=<?= urlencode($to) ?>" class="inline-flex items-center rounded-xl bg-rose-700 px-5 py-3 text-sm font-black text-white shadow-lg transition hover:bg-rose-800"><i class="fas fa-file-pdf mr-2"></i>Exporter l’état de trésorerie · du <?= date('d/m/Y', strtotime($from)) ?> au <?= date('d/m/Y', strtotime($to)) ?></a></div>
        <section class="treasury-columns mt-6" aria-label="Synthèse de la trésorerie"><?php foreach ($cardGroups as [$groupType, $groupLabel, $groupHint, $groupCards]): ?><div class="treasury-column treasury-column--<?= $groupType ?>">
                    <div class="treasury-column-head"><span class="treasury-column-icon"><i class="fas <?= $groupType === 'in' ? 'fa-arrow-down' : ($groupType === 'out' ? 'fa-arrow-up' : 'fa-scale-balanced') ?>"></i></span>
                        <div>
                            <h2><?= $groupLabel ?></h2>
                            <p><?= $groupHint ?></p>
                        </div>
                    </div>
                    <div class="treasury-column-body"><?php foreach ($groupCards as [$detail, $label, $amount, $hint, $status, $reserved]): ?><button type="button" data-detail="<?= $detail ?>" class="treasury-card treasury-card--<?= $status ?>">
                                <div class="treasury-card-top">
                                    <div><span class="treasury-status"><?= $status === 'real' ? 'Réel' : 'Prévisionnel' ?></span>
                                        <h3><?= $label ?></h3>
                                    </div><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i>
                                </div><strong><?= $money($amount) ?></strong><?php if ($reserved !== null): ?><span class="treasury-reserved"><i class="fas fa-lock" aria-hidden="true"></i><span>Réservé sans débit</span><b><?= $money($reserved) ?></b></span><?php endif; ?><p><?= $hint ?></p><span class="treasury-card-link">Voir le récapitulatif <i class="fas fa-chevron-right"></i></span>
                            </button><?php endforeach; ?></div>
                </div><?php endforeach; ?></section>
        </div>
        <div data-treasury-panel="vat" class="hidden">
        <section class="mt-6 overflow-hidden rounded-[32px] border border-cyan-100 billing-banner text-white shadow-2xl">
            <div class="grid gap-0 xl:grid-cols-[1.15fr_.85fr]">
                <div class="p-6 md:p-8">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <span class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[.22em] text-cyan-200"><i class="fas fa-shield-halved"></i> Réserve fiscale TVA</span>
                            <h2 class="mt-4 text-2xl font-black md:text-3xl">TVA collectée</h2>
                        </div>
                        <?php if ($canManageTreasury): ?><div class="flex flex-wrap gap-2"><?php if ((float)$vat['vat_protected'] > 0): ?><button type="button" data-open="vatRemitModal" class="rounded-2xl border border-white/20 bg-white/10 px-5 py-3 text-sm font-black text-white transition hover:bg-white/15"><i class="fas fa-building-columns mr-2"></i>Reverser la TVA</button><?php endif; ?><?php if ((float)$vat['vat_free_to_borrow'] > 0): ?><button type="button" data-open="vatBorrowModal" class="rounded-2xl bg-cyan-300 px-5 py-3 text-sm font-black text-slate-950 shadow-lg shadow-cyan-950/30 transition hover:-translate-y-0.5 hover:bg-cyan-200"><i class="fas fa-hand-holding-dollar mr-2"></i>Emprunter sur la TVA</button><?php endif; ?></div><?php endif; ?>
                    </div>
                    <div class="mt-7 grid gap-4 sm:grid-cols-2">
                        <button type="button" data-detail="vat-collected" class="rounded-2xl border border-white/10 bg-white/5 p-5 text-left backdrop-blur transition hover:bg-white/10"><small class="font-bold uppercase tracking-wide text-cyan-200">TVA collectée</small><strong class="mt-2 block text-2xl"><?= $money($vat['vat_collected']) ?></strong><span class="mt-2 block text-xs leading-5 text-slate-400">Total de la TVA issue des factures clients réellement encaissées.</span></button>
                        <article class="rounded-2xl border border-white/10 bg-white/5 p-5"><small class="font-bold uppercase tracking-wide text-emerald-200">Réserve protégée</small><strong class="mt-2 block text-2xl"><?= $money($vat['vat_protected']) ?></strong><span class="mt-2 block text-xs leading-5 text-slate-400">Part de la TVA conservée en réserve et exclue de la trésorerie disponible.</span></article>
                        <article class="rounded-2xl border border-amber-300/20 bg-amber-300/10 p-5"><small class="font-bold uppercase tracking-wide text-amber-200">TVA empruntée</small><strong class="mt-2 block text-2xl"><?= $money($vat['vat_outstanding']) ?></strong><span class="mt-2 block text-xs leading-5 text-amber-100/70">Montant prélevé temporairement sur la réserve TVA et restant à restituer.</span></article>
                        <article class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 p-5"><small class="font-bold uppercase tracking-wide text-cyan-200">Mobilisable</small><strong class="mt-2 block text-2xl"><?= $money($vat['vat_free_to_borrow']) ?></strong><span class="mt-2 block text-xs leading-5 text-cyan-100/70">Montant de TVA encore disponible pour un emprunt temporaire avec date de restitution.</span></article>
                    </div>
                </div>
                <div class="border-t border-white/10 bg-white/[.035] p-6 xl:border-l xl:border-t-0 md:p-8">
                    <div class="flex items-center justify-between gap-3"><div><small class="font-black uppercase tracking-[.2em] text-slate-400">Synthèse</small><h3 class="mt-1 text-lg font-black">TVA & disponible</h3></div><span class="grid h-12 w-12 place-items-center rounded-2xl bg-cyan-300 text-slate-950"><i class="fas fa-scale-balanced"></i></span></div>
                    <div class="mt-5 space-y-3 text-sm">
                        <div class="flex items-center justify-between rounded-xl bg-white/5 px-4 py-3"><span class="text-slate-300">TVA déjà reversée</span><b><?= $money($vat['vat_remitted']) ?></b></div>
                        <div class="flex items-center justify-between rounded-xl bg-white/5 px-4 py-3"><span class="text-slate-300">TVA collectée non reversée</span><b><?= $money($vat['vat_reserve_gross']) ?></b></div>
                        <div class="flex items-center justify-between rounded-xl bg-white/5 px-4 py-3"><span class="text-slate-300">Solde des entrées réelles hors TVA</span><b><?= $money($data['realEntriesBalance']) ?></b></div>
                        <div class="flex items-center justify-between rounded-xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3"><span class="font-bold text-cyan-100">Réserve TVA séparée</span><b class="text-cyan-200"><?= $money($vat['vat_protected']) ?></b></div>
                    </div>
                </div>
            </div>
        </section>
        </div>
        <?php if ($data['dueInvoiceAlerts'] || $data['repaymentAlerts'] || !empty($vat['due_loans'])): ?><section class="treasury-alerts mt-6 grid gap-4 lg:grid-cols-2">
                <?php foreach ($data['dueInvoiceAlerts'] as $alert): $late = (int)($alert['overdue_days'] ?? 0); ?><article class="treasury-collection-alert rounded-2xl border p-5 <?= $late > 0 ? 'is-late border-rose-200 bg-rose-50' : 'border-amber-200 bg-amber-50' ?>">
                        <div class="treasury-collection-alert-head flex flex-wrap items-center justify-between gap-2"><small class="font-black uppercase <?= $late > 0 ? 'text-rose-700' : 'text-amber-700' ?>"><i class="fas fa-file-invoice-dollar mr-2"></i>Encaissement à confirmer</small><span class="rounded-full px-3 py-1 text-[10px] font-black uppercase <?= $late > 0 ? 'bg-rose-100 text-rose-700' : 'bg-amber-100 text-amber-700' ?>"><?= $late > 0 ? 'Date dépassée · ' . $late . ' jour' . ($late > 1 ? 's' : '') : 'Échéance aujourd’hui' ?></span></div>
                        <h3 class="treasury-collection-reference mt-4 font-black"><?= htmlspecialchars((string)$alert['reference']) ?> <span>· <?= $money($alert['amount']) ?></span></h3>
                        <p class="treasury-collection-copy mt-2 text-sm"><?= htmlspecialchars((string)$alert['label']) ?> · réception prévue le <b><?= date('d/m/Y', strtotime((string)$alert['movement_date'])) ?></b></p>
                        <form method="post" class="treasury-collection-confirm mt-5 grid gap-3 sm:grid-cols-[auto_1fr_auto]"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="confirm_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$alert['invoice_id'] ?>"><button name="received" value="yes" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white hover:bg-emerald-700"><i class="fas fa-circle-check mr-2"></i>Fonds perçus</button><input type="date" name="new_date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" aria-label="Nouvelle date prévue" class="rounded-xl border border-amber-300 px-3 py-3"><button name="received" value="no" class="rounded-xl border border-amber-300 bg-amber-100 px-4 py-3 text-sm font-black text-amber-900 hover:bg-amber-200"><i class="fas fa-calendar-days mr-2"></i>Reporter</button></form>
                        <form method="post" class="treasury-collection-cancel mt-3 flex gap-3" onsubmit="return confirm('Annuler le suivi d’encaissement de cette facture ?')"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$alert['invoice_id'] ?>"><input name="cancel_reason" required placeholder="Motif obligatoire de l’annulation" class="min-w-0 flex-1 rounded-xl border bg-white px-3 py-3 text-sm"><button class="rounded-xl bg-rose-600 px-4 py-3 text-sm font-black text-white hover:bg-rose-700"><i class="fas fa-ban mr-2"></i>Annuler le suivi</button></form>
                    </article><?php endforeach; ?>
                <?php foreach ($vat['due_loans'] as $loan): $vatDue=(string)($loan['vat_repayment_due_date'] ?: $loan['due_date']); ?><article class="rounded-2xl border border-amber-300 bg-gradient-to-br from-amber-50 to-orange-50 p-5 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2"><small class="font-black uppercase text-amber-800"><i class="fas fa-rotate-left mr-2"></i>Restitution TVA à valider</small><span class="rounded-full bg-amber-200 px-3 py-1 text-[10px] font-black uppercase text-amber-900">Échéance <?= date('d/m/Y', strtotime($vatDue)) ?></span></div>
                        <h3 class="mt-3 text-xl font-black text-slate-950"><?= $money(max(0,(float)$loan['principal_amount']-(float)$loan['repaid_amount'])) ?></h3>
                        <p class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string)($loan['note'] ?: 'Mobilisation temporaire de TVA')) ?></p>
                        <?php if ($canManageTreasury): ?><div class="mt-4 grid gap-3 md:grid-cols-2">
                            <form method="post"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="repay_vat"><input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>"><button class="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white hover:bg-emerald-700"><i class="fas fa-circle-check mr-2"></i>Restituer maintenant</button></form>
                            <form method="post" class="grid gap-2"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="defer_vat"><input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>"><input type="date" name="new_due_date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required class="rounded-xl border border-amber-300 px-3 py-2 text-sm"><input name="reason" required placeholder="Motif du report" class="rounded-xl border border-amber-300 px-3 py-2 text-sm"><button class="rounded-xl bg-amber-600 px-4 py-3 text-sm font-black text-white hover:bg-amber-700"><i class="fas fa-calendar-days mr-2"></i>Reporter</button></form>
                        </div><?php endif; ?>
                    </article><?php endforeach; ?>
                <?php foreach ($data['repaymentAlerts'] as $alert): ?><article class="rounded-2xl border border-rose-200 bg-rose-50 p-5"><small class="font-black uppercase text-rose-700">Remboursement à autoriser</small>
                        <h3 class="mt-1 font-black"><?= htmlspecialchars((string)$alert['label']) ?> · <?= $money($alert['amount']) ?></h3>
                        <p class="mt-1 text-sm text-rose-900">Échéance : <?= date('d/m/Y', strtotime((string)$alert['repayment_date'])) ?></p>
                        <form method="post" class="mt-4"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="authorize_repayment"><input type="hidden" name="movement_id" value="<?= (int)$alert['id'] ?>"><button class="rounded-xl bg-rose-700 px-4 py-2 text-sm font-black text-white">Autoriser et inscrire la sortie</button></form>
                    </article><?php endforeach; ?></section><?php endif; ?>
        <details class="treasury-access-registry mt-12 rounded-2xl border border-slate-200 bg-white p-4 text-xs text-slate-500">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-slate-600"><span><i class="fas fa-user-shield mr-2 text-indigo-600"></i>Registre des accès</span><span class="rounded-full bg-indigo-50 px-3 py-1 text-indigo-700"><?= count($treasuryAccessRows) ?> personne(s) autorisée(s)</span></summary><?php if ($treasuryAccessError !== ''): ?><p class="mt-3 text-rose-600"><?= htmlspecialchars($treasuryAccessError) ?></p><?php else: ?><div class="treasury-access-list mt-3 divide-y divide-slate-100 overflow-hidden rounded-xl border border-slate-100 bg-white"><?php foreach ($treasuryAccessRows as $accessUser): $level = (!empty($accessUser['acces_admin']) || !empty($accessUser['acces_direction'])) ? 'Administration DG' : (!empty($accessUser['acces_tresorerie_lecture']) ? 'Lecture seule' : 'Indicateurs uniquement'); ?><div class="flex flex-wrap items-center justify-between gap-2 px-3 py-3"><span><b class="text-slate-700"><?= htmlspecialchars(trim((string)$accessUser['nom']) ?: ('Utilisateur #' . (int)$accessUser['id'])) ?></b><small class="ml-2"><?= htmlspecialchars((string)$accessUser['mail_pro']) ?></small></span><span class="rounded-full bg-slate-100 px-3 py-1 font-semibold text-slate-600"><?= htmlspecialchars($level) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        </details>
    </main>
</div>
<style>
    .treasury-readonly form[method="post"] {
        display: none !important
    }

    .treasury-readonly .treasury-card[data-transfer-source],
    .treasury-readonly .treasury-card[data-output-source] {
        cursor: pointer !important
    }

    main .bg-slate-900,
    main .bg-slate-950 {
        background-color: #4f46e5 !important
    }

    .treasury-columns {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem
    }

    .treasury-column {
        overflow: hidden;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #f8fafc
    }

    .treasury-column--in {
        border-top: 4px solid #16a34a
    }

    .treasury-column--balance {
        border-top: 4px solid #f59e0b
    }

    .treasury-column--out {
        border-top: 4px solid #dc2626
    }

    .treasury-column-head {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: 1rem 1.1rem;
        border-bottom: 1px solid #e2e8f0;
        background: #fff
    }

    .treasury-column-head h2 {
        font-size: 1rem;
        font-weight: 900;
        color: #0f172a
    }

    .treasury-column-head p {
        margin-top: .1rem;
        font-size: .7rem;
        color: #64748b
    }

    .treasury-column-icon {
        display: grid;
        width: 2.25rem;
        height: 2.25rem;
        flex: none;
        place-items: center;
        border-radius: 50%
    }

    .treasury-column--in .treasury-column-icon {
        background: #dcfce7;
        color: #15803d
    }

    .treasury-column--balance .treasury-column-icon {
        background: #fef3c7;
        color: #b45309
    }

    .treasury-column--out .treasury-column-icon {
        background: #fee2e2;
        color: #b91c1c
    }

    .treasury-column-body {
        display: grid;
        gap: .75rem;
        padding: .75rem
    }

    .treasury-card {
        width: 100%;
        min-height: 185px;
        padding: 1rem;
        border: 1px solid #cbd5e1;
        border-radius: 12px;
        background: #fff;
        text-align: left;
        transition: transform .2s, box-shadow .2s
    }

    .treasury-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 22px rgba(15, 23, 42, .1)
    }

    .treasury-card-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: .75rem
    }

    .treasury-card-top>i {
        font-size: .8rem;
        color: #94a3b8
    }

    .treasury-status {
        display: inline-block;
        margin-bottom: .35rem;
        font-size: .62rem;
        font-weight: 900;
        text-transform: uppercase;
        color: #475569
    }

    .treasury-card h3 {
        font-size: .85rem;
        font-weight: 900;
        color: #334155
    }

    .treasury-card>strong {
        display: block;
        margin-top: .8rem;
        font-size: 1.65rem;
        font-weight: 900
    }

    .treasury-column--in .treasury-card>strong {
        color: #15803d
    }

    .treasury-column--balance .treasury-card>strong {
        color: #b45309
    }

    .treasury-column--out .treasury-card>strong {
        color: #b91c1c
    }

    .treasury-card>p {
        min-height: 2rem;
        margin-top: .55rem;
        font-size: .7rem;
        line-height: 1.45;
        color: #64748b
    }

    .treasury-card-link {
        display: block;
        margin-top: .7rem;
        font-size: .68rem;
        font-weight: 800;
        color: #475569
    }

    .treasury-card-link i {
        margin-left: .2rem;
        font-size: .55rem
    }

    .treasury-card--forecast {
        border-style: dashed;
        background: rgba(248, 250, 252, .75);
        box-shadow: none;
        opacity: .68
    }

    .treasury-card--forecast strong {
        font-weight: 700
    }

    .treasury-card--forecast:hover {
        opacity: .9
    }

    .treasury-secondary {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 2fr);
        gap: 1rem
    }

    .treasury-secondary-group {
        padding: .75rem;
        border-top: 3px solid;
        border-radius: 12px;
        background: #f8fafc
    }

    .treasury-secondary-group--in {
        border-color: #16a34a
    }

    .treasury-secondary-group--balance {
        border-color: #f59e0b
    }

    .treasury-secondary-group--out {
        border-color: #dc2626
    }

    .treasury-secondary-title {
        margin-bottom: .55rem;
        font-size: .68rem;
        font-weight: 900;
        text-transform: uppercase;
        color: #64748b
    }

    .treasury-secondary-title i {
        margin-right: .25rem
    }

    .treasury-secondary-out-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .55rem
    }

    .treasury-mini {
        height: 100%;
        padding: .85rem;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff
    }

    .treasury-mini small,
    .treasury-mini strong,
    .treasury-mini span {
        display: block
    }

    .treasury-mini small {
        font-size: .7rem;
        font-weight: 900;
        color: #475569
    }

    .treasury-mini strong {
        margin-top: .35rem;
        font-size: 1.05rem;
        font-weight: 900
    }

    .treasury-mini span {
        margin-top: .3rem;
        font-size: .62rem;
        line-height: 1.4;
        color: #64748b
    }

    .treasury-secondary-group--in strong {
        color: #15803d
    }

    .treasury-secondary-group--balance strong {
        color: #b45309
    }

    .treasury-secondary-group--out strong {
        color: #b91c1c
    }

    .treasury-mini--forecast {
        border-style: dashed;
        background: #f8fafc;
        opacity: .62
    }

    .treasury-mini--forecast strong {
        font-weight: 700
    }

    .treasury-reserved {
        display: grid;
        grid-template-columns: auto 1fr auto;
        align-items: center;
        gap: .35rem;
        margin-top: .45rem;
        padding: .4rem .5rem;
        border: 1px solid #fde68a;
        border-radius: 7px;
        background: #fffbeb;
        color: #92400e;
        font-size: .61rem
    }

    .treasury-reserved b {
        font-size: .68rem
    }

    .treasury-card[data-transfer-source],
    .treasury-card[data-output-source] {
        cursor: grab
    }

    .treasury-card[data-transfer-source]:active,
    .treasury-card[data-output-source]:active {
        cursor: grabbing
    }

    .treasury-card.is-dragging {
        opacity: .55;
        transform: scale(.98)
    }

    .treasury-card.is-transfer-target,
    .treasury-card.is-output-target {
        transform: translateY(-3px)
    }

    .treasury-card.is-transfer-target {
        border-color: #f59e0b;
        background: #fffbeb;
        box-shadow: 0 0 0 4px rgba(245, 158, 11, .18), 0 16px 30px rgba(15, 23, 42, .12)
    }

    .treasury-card.is-output-target {
        border-color: #dc2626;
        background: #fff1f2;
        box-shadow: 0 0 0 4px rgba(220, 38, 38, .14), 0 16px 30px rgba(15, 23, 42, .12)
    }

    .treasury-card.is-transfer-target:after,
    .treasury-card.is-output-target:after {
        display: block;
        margin-top: .65rem;
        padding: .5rem;
        border-radius: 8px;
        color: #fff;
        font-size: .68rem;
        font-weight: 900;
        text-align: center
    }

    .treasury-card.is-transfer-target:after {
        content: 'Déposer pour alimenter le disponible';
        background: #f59e0b;
        color: #111827
    }

    .treasury-card.is-output-target:after {
        content: 'Déposer pour préparer une sortie manuelle';
        background: #dc2626
    }

    .treasury-card.is-return-target {
        border-color: #16a34a;
        background: #f0fdf4;
        box-shadow: 0 0 0 4px rgba(22, 163, 74, .15), 0 16px 30px rgba(15, 23, 42, .12);
        transform: translateY(-3px)
    }

    .treasury-card.is-return-target:after {
        content: 'Déposer pour retourner aux entrées réelles';
        display: block;
        margin-top: .65rem;
        padding: .5rem;
        border-radius: 8px;
        background: #16a34a;
        color: #fff;
        font-size: .68rem;
        font-weight: 900;
        text-align: center
    }

    @media(max-width:1024px) {

        .treasury-columns,
        .treasury-secondary {
            grid-template-columns: 1fr
        }

        .treasury-secondary-out-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr))
        }
    }

    @media(max-width:640px) {
        .treasury-secondary-out-grid {
            grid-template-columns: 1fr
        }

        .treasury-card {
            min-height: 0
        }

        .treasury-card>p {
            min-height: 0
        }
    }

    #openingModal,
    #transferModal,
    #returnModal,
    #movementModal,
    #detailModal {
        z-index: 1000 !important;
        overflow-y: auto;
        padding: clamp(.5rem, 2vw, 1rem) !important
    }

    #openingModal>div,
    #transferModal>div,
    #returnModal>div,
    #movementModal>div,
    #detailModal>div {
        max-height: calc(100dvh - 1rem);
        overflow-y: auto
    }

    #openingModal>div,
    #transferModal>div,
    #returnModal>div {
        max-width: 48rem !important
    }

    #movementModal>div {
        max-width: 64rem !important
    }

    #detailModal>div {
        max-width: 88rem !important;
        width: min(96vw, 88rem) !important
    }

    #detailContent {
        background: linear-gradient(180deg, #f8fafc, #eef2ff 55%, #f8fafc)
    }

    .treasury-flow-row {
        display: grid;
        grid-template-columns: 48px minmax(0, 1fr) 145px;
        gap: 14px;
        align-items: center;
        padding: 14px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 2px 7px rgba(15, 23, 42, .04)
    }

    .treasury-flow-icon {
        display: grid;
        width: 42px;
        height: 42px;
        place-items: center;
        border-radius: 14px;
        font-size: 16px
    }

    .treasury-flow-icon.in {
        background: #dcfce7;
        color: #15803d
    }

    .treasury-flow-icon.out {
        background: #fee2e2;
        color: #dc2626
    }

    .treasury-movement-details {
        margin-top: .6rem;
        border-top: 1px solid #e2e8f0;
        padding-top: .45rem
    }

    .treasury-movement-details summary {
        display: inline-flex;
        cursor: pointer;
        list-style: none;
        align-items: center;
        gap: .4rem;
        color: #475569;
        font-size: .7rem;
        font-weight: 800
    }

    .treasury-movement-details summary::-webkit-details-marker {
        display: none
    }

    .treasury-movement-details summary i {
        transition: transform .18s
    }

    .treasury-movement-details[open] summary i {
        transform: rotate(180deg)
    }

    .treasury-detail-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .5rem;
        margin-top: .65rem
    }

    .treasury-detail-item {
        min-width: 0;
        border-radius: 9px;
        background: #f8fafc;
        padding: .55rem .65rem
    }

    .treasury-detail-item small {
        display: block;
        color: #64748b;
        font-size: .62rem;
        font-weight: 700
    }

    .treasury-detail-item strong {
        display: block;
        margin-top: .12rem;
        overflow-wrap: anywhere;
        color: #1e293b;
        font-size: .72rem
    }

    .treasury-detail-people {
        display: flex;
        flex-wrap: wrap;
        gap: .45rem;
        margin-top: .6rem
    }

    .treasury-group {
        overflow: hidden;
        border: 1px solid #dbeafe;
        border-radius: 22px;
        background: rgba(255, 255, 255, .78)
    }

    .treasury-group-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 14px 18px;
        background: #fff
    }

    .treasury-group-body {
        display: grid;
        gap: 9px;
        padding: 12px
    }

    body.finea-dark-mode .treasury-column {
        border-color: #34445c;
        background: #0f1c2d
    }

    body.finea-dark-mode .treasury-column-head {
        border-color: #34445c;
        background: #17263a
    }

    body.finea-dark-mode .treasury-column-head h2,
    body.finea-dark-mode .treasury-card h3 {
        color: #f8fafc
    }

    body.finea-dark-mode .treasury-column-head p,
    body.finea-dark-mode .treasury-card>p,
    body.finea-dark-mode .treasury-card-link,
    body.finea-dark-mode .treasury-status {
        color: #c6d0de
    }

    body.finea-dark-mode .treasury-card {
        border-color: #40516a;
        background: #172337;
        box-shadow: 0 12px 28px rgba(0, 0, 0, .25)
    }

    body.finea-dark-mode .treasury-column--in .treasury-card {
        background: linear-gradient(145deg, #172337, #12352f)
    }

    body.finea-dark-mode .treasury-column--balance .treasury-card {
        background: linear-gradient(145deg, #172337, #3b3019)
    }

    body.finea-dark-mode .treasury-column--out .treasury-card {
        background: linear-gradient(145deg, #172337, #3b202c)
    }

    body.finea-dark-mode .treasury-column--in .treasury-card>strong,
    body.finea-dark-mode .treasury-secondary-group--in strong {
        color: #6ee7b7
    }

    body.finea-dark-mode .treasury-column--balance .treasury-card>strong,
    body.finea-dark-mode .treasury-secondary-group--balance strong {
        color: #fcd34d
    }

    body.finea-dark-mode .treasury-column--out .treasury-card>strong,
    body.finea-dark-mode .treasury-secondary-group--out strong {
        color: #fda4af
    }

    body.finea-dark-mode .treasury-card--forecast,
    body.finea-dark-mode .treasury-mini--forecast {
        opacity: .9
    }

    body.finea-dark-mode .treasury-secondary-group {
        background: #0f1c2d
    }

    body.finea-dark-mode .treasury-mini {
        border-color: #40516a;
        background: #172337
    }

    body.finea-dark-mode .treasury-mini small {
        color: #d4dce8
    }

    body.finea-dark-mode .treasury-mini span {
        color: #b8c4d4
    }

    body.finea-dark-mode #detailContent {
        background: linear-gradient(180deg, #101b2b, #14243a 55%, #101b2b)
    }

    body.finea-dark-mode .treasury-flow-row {
        border-color: #3b4d67;
        background: #172337;
        box-shadow: 0 5px 16px rgba(0, 0, 0, .25)
    }

    body.finea-dark-mode .treasury-movement-details {
        border-color: #3b4d67
    }

    body.finea-dark-mode .treasury-movement-details summary {
        color: #cbd5e1
    }

    body.finea-dark-mode .treasury-detail-item {
        background: #0e1a2b
    }

    body.finea-dark-mode .treasury-detail-item small {
        color: #aebbd0
    }

    body.finea-dark-mode .treasury-detail-item strong {
        color: #f1f5f9
    }

    body.finea-dark-mode .treasury-group {
        border-color: #3b4d67;
        background: #101c2d
    }

    body.finea-dark-mode .treasury-group-head {
        background: #1a2940;
        color: #f8fafc
    }

    body.finea-dark-mode .treasury-group-body {
        background: #101c2d
    }

    body.finea-dark-mode .treasury-reserved {
        border-color: #a16207;
        background: #3c2e12;
        color: #fde68a
    }

    .treasury-collection-alert {
        position: relative;
        overflow: hidden;
        box-shadow: 0 14px 32px rgba(190, 24, 93, .08)
    }

    .treasury-collection-alert:before {
        content: '';
        position: absolute;
        inset: 0 auto 0 0;
        width: 5px;
        background: #f59e0b
    }

    .treasury-collection-alert.is-late:before {
        background: #e11d48
    }

    .treasury-collection-reference {
        font-size: 1.05rem;
        color: #172033
    }

    .treasury-collection-reference span {
        color: #be123c
    }

    .treasury-collection-copy {
        line-height: 1.6;
        color: #5b687c
    }

    .treasury-access-registry {
        box-shadow: 0 10px 25px rgba(15, 23, 42, .06)
    }

    body.finea-dark-mode .treasury-collection-alert {
        border-color: #85415c !important;
        background: linear-gradient(145deg, #243b5d, #45283d) !important;
        box-shadow: 0 16px 35px rgba(4, 15, 32, .30) !important
    }

    body.finea-dark-mode .treasury-collection-alert:not(.is-late) {
        border-color: #8b6b2f !important;
        background: linear-gradient(145deg, #243b5d, #443a22) !important
    }

    body.finea-dark-mode .treasury-collection-reference {
        color: #fff !important
    }

    body.finea-dark-mode .treasury-collection-reference span {
        color: #fda4af !important
    }

    body.finea-dark-mode .treasury-collection-copy {
        color: #dbe6f5 !important
    }

    body.finea-dark-mode .treasury-collection-confirm input,
    body.finea-dark-mode .treasury-collection-cancel input {
        border-color: #6882a5 !important;
        background: #17304f !important;
        color: #fff !important
    }

    body.finea-dark-mode .treasury-collection-confirm button[value="no"] {
        border-color: #d49a36 !important;
        background: #6d4c18 !important;
        color: #fef3c7 !important
    }

    body.finea-dark-mode .treasury-access-registry {
        border-color: #496789 !important;
        background: #203d63 !important;
        color: #dbeafe !important;
        box-shadow: 0 14px 30px rgba(4, 15, 32, .24) !important
    }

    body.finea-dark-mode .treasury-access-registry summary {
        color: #f8fafc !important
    }

    body.finea-dark-mode .treasury-access-registry summary>span:last-child {
        background: #344f84 !important;
        color: #dbeafe !important
    }

    body.finea-dark-mode .treasury-access-list {
        border-color: #496789 !important;
        background: #1a3558 !important
    }

    body.finea-dark-mode .treasury-access-list>div {
        border-color: #496789 !important
    }

    body.finea-dark-mode .treasury-access-list b {
        color: #fff !important
    }

    body.finea-dark-mode .treasury-access-list small {
        color: #bfcee2 !important
    }

    body.finea-dark-mode .treasury-access-list>div>span:last-child {
        background: #2c4b72 !important;
        color: #dbeafe !important
    }

    @media(max-width:640px) {
        .treasury-collection-confirm {
            grid-template-columns: 1fr !important
        }

        .treasury-collection-cancel {
            flex-direction: column
        }

        .treasury-collection-cancel button {
            width: 100%
        }
    }

    @media(max-width:640px) {
        .treasury-flow-row {
            grid-template-columns: 42px minmax(0, 1fr)
        }

        .treasury-flow-amount {
            grid-column: 2;
            text-align: left !important
        }

        .treasury-detail-grid {
            grid-template-columns: 1fr
        }
    }

    @media(max-height:760px) {

        #openingModal>div,
        #movementModal>div {
            padding: 1rem !important
        }

        #openingModal form,
        #movementModal form {
            margin-top: .75rem !important;
            gap: .65rem !important
        }

        #openingModal input,
        #openingModal textarea,
        #movementModal input,
        #movementModal select,
        #movementModal textarea {
            padding-top: .5rem !important;
            padding-bottom: .5rem !important
        }

        #movementModal textarea,
        #openingModal textarea {
            height: 3.5rem
        }
    }

    @media(max-width:640px) {

        #openingModal,
        #transferModal,
        #returnModal,
        #movementModal,
        #detailModal {
            align-items: start !important
        }

        #openingModal>div,
        #transferModal>div,
        #returnModal>div,
        #movementModal>div,
        #detailModal>div {
            border-radius: 1.25rem !important
        }
    }
</style>

<div id="openingModal" class="fixed inset-0 z-[80] hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex justify-between">
            <div>
                <h2 class="text-xl font-black">Initialisation du solde</h2>
                <p class="text-sm text-slate-500">Ce montant constitue la base durable des entrées réelles.</p>
            </div><button data-close class="h-9 w-9 rounded-full bg-slate-100">×</button>
        </div>
        <form method="post" class="mt-5 grid gap-4"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="opening"><label class="text-sm font-bold">Date d’effet<input type="date" name="effective_date" value="<?= htmlspecialchars((string)($data['opening']['effective_date'] ?? date('Y-m-d'))) ?>" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold">Solde constaté sur le compte<input type="number" step=".01" name="amount" value="<?= htmlspecialchars((string)($data['opening']['amount'] ?? 0)) ?>" required class="mt-1 w-full rounded-xl border px-3 py-3"></label>
            <div class="rounded-xl bg-indigo-50 px-4 py-3 text-sm text-indigo-900">Ce solde crédite les entrées réelles. Il ne rejoint le disponible qu’après un transfert explicite.</div><label class="text-sm font-bold">Note<textarea name="note" class="mt-1 w-full rounded-xl border px-3 py-3"><?= htmlspecialchars((string)($data['opening']['note'] ?? '')) ?></textarea></label><button class="rounded-xl bg-indigo-700 px-5 py-3 font-black text-white">Enregistrer le solde initial</button>
        </form>
    </div>
</div>
<div id="transferModal" class="fixed inset-0 z-[80] hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex justify-between gap-4">
            <div>
                <h2 class="text-xl font-black">Alimenter le disponible</h2>
                <p class="text-sm text-slate-500">Transfert interne sans double comptage.</p>
            </div><button data-close class="h-9 w-9 shrink-0 rounded-full bg-slate-100">×</button>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3">
            <div class="rounded-xl bg-emerald-50 p-3"><small class="text-emerald-700">Entrées réelles disponibles</small><b class="block text-emerald-900"><?= number_format((float)$data['realEntriesBalance'], 0, ',', ' ') ?> FCFA</b></div>
            <div class="rounded-xl bg-amber-50 p-3"><small class="text-amber-700">Disponible actuel</small><b class="block text-amber-900"><?= number_format((float)$data['available'], 0, ',', ' ') ?> FCFA</b></div>
        </div>
        <form method="post" class="mt-5 grid gap-4 md:grid-cols-2"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="transfer_available"><label class="text-sm font-bold">Montant à transférer<input type="number" name="amount" min="1" max="<?= max(0, (float)$data['realEntriesBalance']) ?>" step=".01" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold">Date du mouvement<input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold md:col-span-2">Référence<input name="reference" placeholder="Ex. VIR-DISP-001" class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold md:col-span-2">Note<textarea name="note" placeholder="Motif ou affectation du transfert" class="mt-1 w-full rounded-xl border px-3 py-3"></textarea></label>
            <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 md:col-span-2"><b>Effet immédiat :</b> débit des entrées réelles et crédit du disponible pour le même montant.</div><button class="rounded-xl bg-amber-500 px-5 py-3 font-black text-slate-950 md:col-span-2">Confirmer le transfert</button>
        </form>
    </div>
</div>
<div id="returnModal" class="fixed inset-0 z-[80] hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex justify-between gap-4">
            <div>
                <h2 class="text-xl font-black">Retourner aux entrées réelles</h2>
                <p class="text-sm text-slate-500">Le disponible sera débité du montant retourné.</p>
            </div><button data-close class="h-9 w-9 shrink-0 rounded-full bg-slate-100">×</button>
        </div>
        <div class="mt-4 rounded-xl bg-amber-50 p-4"><small class="text-amber-700">Disponible réel pouvant être retourné</small><b class="block text-xl text-amber-900"><?= number_format(max(0, (float)$data['available']), 0, ',', ' ') ?> FCFA</b></div>
        <form id="returnRealForm" method="post" class="mt-5 grid gap-4 md:grid-cols-2"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="return_real_entries">
            <div class="md:col-span-2 grid grid-cols-2 gap-2"><label class="cursor-pointer rounded-xl border p-3 text-sm font-bold"><input type="radio" name="return_mode" value="all" checked class="mr-2">Tout retourner</label><label class="cursor-pointer rounded-xl border p-3 text-sm font-bold"><input type="radio" name="return_mode" value="partial" class="mr-2">Retourner une partie</label></div><label id="returnAmountLabel" class="hidden text-sm font-bold md:col-span-2">Montant à retourner<input id="returnAmount" type="number" name="amount" min="1" max="<?= max(0, (float)$data['available']) ?>" step=".01" disabled class="mt-1 w-full rounded-xl border px-3 py-3"></label><input id="returnAllAmount" type="hidden" name="amount" value="<?= max(0, (float)$data['available']) ?>"><label class="text-sm font-bold">Date du mouvement<input type="date" name="transfer_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold">Référence<input name="reference" placeholder="Ex. RET-ENT-001" class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold md:col-span-2">Motif du retour<textarea name="note" required placeholder="Pourquoi ces fonds retournent-ils aux entrées réelles ?" class="mt-1 w-full rounded-xl border px-3 py-3"></textarea></label>
            <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 md:col-span-2"><b>Effet immédiat :</b> débit du disponible réel et crédit des entrées réelles.</div><button class="rounded-xl bg-amber-500 px-5 py-3 font-black text-slate-950 md:col-span-2">Confirmer le retour</button>
        </form>
    </div>
</div>
<div id="movementModal" class="fixed inset-0 z-[80] hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="w-full max-w-xl rounded-3xl bg-white p-6 shadow-2xl">
        <div class="flex justify-between">
            <div>
                <h2 id="movementTitle" class="text-xl font-black">Mouvement de trésorerie</h2>
                <p class="text-sm text-slate-500">Les entrées manuelles sont toujours enregistrées comme fonds réellement reçus.</p>
            </div><button data-close class="h-9 w-9 rounded-full bg-slate-100">×</button>
        </div>
        <form method="post" class="mt-5 grid gap-4 md:grid-cols-2"><input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="movement"><input type="hidden" id="movementDirection" name="direction" value="in"><label class="text-sm font-bold md:col-span-2">Libellé<input name="label" required placeholder="Ex. Prêt reçu de…" class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold">Montant<input type="number" name="amount" min="1" step=".01" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label id="movementStatusLabel" class="text-sm font-bold">Situation<select name="status" class="mt-1 w-full rounded-xl border px-3 py-3">
                    <option value="real">Réalisée</option>
                    <option value="forecast">Prévisionnelle</option>
                </select></label><label class="text-sm font-bold">Date réelle / prévue<input type="date" name="movement_date" value="<?= date('Y-m-d') ?>" required class="mt-1 w-full rounded-xl border px-3 py-3"></label><label class="text-sm font-bold">Référence<input name="reference" placeholder="Contrat, reçu, pièce…" class="mt-1 w-full rounded-xl border px-3 py-3"></label>
            <section id="repaymentFields" class="hidden md:col-span-2 grid gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4"><label class="flex items-center gap-3 text-sm font-bold"><input type="checkbox" name="repayment_required" value="1"> Un remboursement sortant est prévu</label><label class="text-sm font-bold">Date prévue du remboursement<input type="date" name="repayment_date" class="mt-1 w-full rounded-xl border border-amber-300 px-3 py-3"></label></section><label class="text-sm font-bold md:col-span-2">Note<textarea name="note" class="mt-1 w-full rounded-xl border px-3 py-3"></textarea></label><button id="movementSubmit" class="rounded-xl bg-emerald-600 px-5 py-3 font-black text-white md:col-span-2">Enregistrer l’entrée</button>
        </form>
    </div>
</div>
<div id="detailModal" class="fixed inset-0 z-[80] hidden place-items-center bg-slate-950/60 p-4 backdrop-blur-sm">
    <div class="flex max-h-[88vh] w-full max-w-4xl flex-col rounded-3xl bg-white shadow-2xl">
        <div class="flex items-center justify-between border-b p-5">
            <div>
                <h2 id="detailTitle" class="text-xl font-black">Récapitulatif</h2>
                <p class="text-sm text-slate-500">Composition exacte du montant affiché.</p>
            </div><button data-close class="h-9 w-9 rounded-full bg-slate-100">×</button>
        </div>
        <div id="detailContent" class="overflow-y-auto p-5"></div>
    </div>
</div>
<script>
    const canManageTreasury = <?= $canManageTreasury ? 'true' : 'false' ?>,
        treasuryData = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        money = v => new Intl.NumberFormat('fr-FR', {
            maximumFractionDigits: 0
        }).format(Number(v || 0)) + ' FCFA',
        esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        } [c]));
    // Les besoins récurrents ne sont plus agrégés aux cartes FEB.
    treasuryData.recurringForecastRows = [];
    const treasuryCatalogs = <?= json_encode($catalogs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        movementForm = document.querySelector('#movementModal form'),
        directionField = document.getElementById('movementDirection');
    const allocation = document.createElement('section');
    allocation.id = 'movementAllocation';
    allocation.className = 'md:col-span-2 grid gap-3 md:grid-cols-2';
    directionField.insertAdjacentElement('afterend', allocation);

    function searchPicker(label, name, items, onPick) {
        const wrap = document.createElement('label');
        wrap.className = 'relative text-sm font-bold';
        wrap.innerHTML = `${label}<input type="hidden" name="${name}"><input type="search" autocomplete="off" placeholder="Rechercher…" class="mt-1 w-full rounded-xl border px-3 py-3"><div class="absolute left-0 right-0 top-full z-20 mt-1 hidden max-h-48 overflow-y-auto rounded-xl border bg-white p-1 shadow-xl"></div>`;
        const hidden = wrap.querySelector('input[type=hidden]'),
            input = wrap.querySelector('input[type=search]'),
            list = wrap.querySelector('div');
        let current = items;
        const render = () => {
            const q = input.value.toLowerCase();
            list.innerHTML = current.filter(x => !q || x.label.toLowerCase().includes(q)).slice(0, 40).map(x => `<button type="button" data-id="${x.id}" class="block w-full rounded-lg px-3 py-2 text-left font-medium hover:bg-indigo-50">${esc(x.label)}</button>`).join('') || '<span class="block p-3 text-xs text-slate-500">Aucun résultat</span>';
            list.classList.remove('hidden');
            list.querySelectorAll('button').forEach(b => b.onclick = () => {
                const item = current.find(x => String(x.id) === b.dataset.id);
                hidden.value = b.dataset.id;
                input.value = item?.label || '';
                list.classList.add('hidden');
                onPick?.(item)
            });
        };
        input.onfocus = render;
        input.oninput = () => {
            hidden.value = '';
            render()
        };
        wrap.addEventListener('focusout', () => setTimeout(() => {
            if (!wrap.contains(document.activeElement)) list.classList.add('hidden')
        }, 120));
        wrap.setItems = next => {
            current = next;
            hidden.value = '';
            input.value = ''
        };
        return wrap
    }

    function renderMovementAllocation(out) {
        allocation.innerHTML = '';
        const statusLabel = document.getElementById('movementStatusLabel'),
            repayment = document.getElementById('repaymentFields');
        statusLabel.classList.toggle('hidden', !out);
        statusLabel.querySelector('select').value = 'real';
        repayment.classList.add('hidden');
        repayment.querySelector('input[type=checkbox]').checked = false;
        repayment.querySelector('input[type=date]').required = false;
        if (!out) {
            const label = document.createElement('label');
            label.className = 'text-sm font-bold md:col-span-2';
            label.innerHTML = 'Type d’entrée réelle<select name="entry_category" required class="mt-1 w-full rounded-xl border px-3 py-3"><option value="">Sélectionner…</option>' + Object.entries(treasuryCatalogs.entry_categories).filter(([v]) => v !== 'encaissement_facture').map(([v, l]) => `<option value="${esc(v)}">${esc(l)}</option>`).join('') + '</select>';
            allocation.appendChild(label);
            const select = label.querySelector('select');
            select.onchange = () => {
                const canRepay = ['pret', 'apport'].includes(select.value);
                repayment.classList.toggle('hidden', !canRepay);
                if (select.value === 'pret') {
                    repayment.querySelector('input[type=checkbox]').checked = true;
                    repayment.querySelector('input[type=date]').required = true
                }
            };
            repayment.querySelector('input[type=checkbox]').onchange = e => repayment.querySelector('input[type=date]').required = e.target.checked;
            return
        }
        const toggle = document.createElement('label');
        toggle.className = 'md:col-span-2 flex cursor-pointer items-center gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-950';
        toggle.innerHTML = '<input type="checkbox" name="free_assignment" value="1" class="h-5 w-5 rounded border-amber-300 text-amber-600"> Ni chantier ni bureau existant — projet / chantier à venir';
        const pickers = document.createElement('div');
        pickers.className = 'contents';
        let linePicker;
        const chantierPicker = searchPicker('Chantier / Bureau', 'chantier_id', treasuryCatalogs.chantiers, item => linePicker.setItems(treasuryCatalogs.lines.filter(line => String(line.devis_id) === String(item?.devis_id))));
        linePicker = searchPicker('Ligne de devis', 'ligne_devis_id', []);
        pickers.append(chantierPicker, linePicker);
        const free = document.createElement('label');
        free.className = 'hidden text-sm font-bold md:col-span-2';
        free.innerHTML = 'Affectation libre du projet à venir<input name="assignment_label" placeholder="Ex. Futur chantier extension dépôt de Yopougon" class="mt-1 w-full rounded-xl border border-amber-300 bg-amber-50 px-3 py-3">';
        toggle.querySelector('input').onchange = e => {
            const active = e.target.checked;
            pickers.classList.toggle('hidden', active);
            pickers.classList.toggle('contents', !active);
            free.classList.toggle('hidden', !active);
            free.querySelector('input').required = active;
            pickers.querySelectorAll('input').forEach(input => input.disabled = active)
        };
        allocation.append(toggle, pickers, free)
    }
    document.querySelectorAll('[data-direction]').forEach(button => button.addEventListener('click', () => renderMovementAllocation(button.dataset.direction === 'out'), true));
    const open = id => {
            const el = document.getElementById(id);
            el.classList.remove('hidden');
            el.classList.add('grid')
        },
        close = el => {
            el.classList.add('hidden');
            el.classList.remove('grid')
        };
    document.querySelectorAll('[data-open]').forEach(b => b.onclick = () => {
        if (b.dataset.direction) {
            const out = b.dataset.direction === 'out';
            document.getElementById('movementDirection').value = out ? 'out' : 'in';
            document.getElementById('movementTitle').textContent = out ? 'Nouvelle sortie manuelle' : 'Nouvelle entrée / facture';
            const submit = document.getElementById('movementSubmit');
            submit.textContent = out ? 'Enregistrer la sortie' : 'Enregistrer l’entrée';
            submit.className = 'rounded-xl px-5 py-3 font-black text-white md:col-span-2 ' + (out ? 'bg-rose-600' : 'bg-emerald-600')
        }
        open(b.dataset.open)
    });
    document.querySelectorAll('[data-close]').forEach(b => b.onclick = event => {
        event.preventDefault();
        const targetId = b.dataset.close;
        const modal = targetId ? document.getElementById(targetId) : b.closest('.fixed');
        if (modal) close(modal)
    });
    document.querySelectorAll('.fixed').forEach(m => m.addEventListener('mousedown', e => {
        if (e.target === m) close(m)
    }));
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        const opened = [...document.querySelectorAll('.fixed.grid')].pop();
        if (opened) close(opened)
    });
    const treasuryTabs = document.querySelectorAll('[data-treasury-tab]');
    const treasuryPanels = document.querySelectorAll('[data-treasury-panel]');
    const setTreasuryTab = tab => {
        treasuryPanels.forEach(panel => panel.classList.toggle('hidden', panel.dataset.treasuryPanel !== tab));
        treasuryTabs.forEach(button => {
            const active = button.dataset.treasuryTab === tab;
            button.classList.toggle('bg-indigo-700', active);
            button.classList.toggle('text-white', active);
            button.classList.toggle('shadow-sm', active);
            button.classList.toggle('text-slate-600', !active);
        });
        try { sessionStorage.setItem('finea-treasury-tab', tab); } catch (_) {}
    };
    treasuryTabs.forEach(button => button.addEventListener('click', () => setTreasuryTab(button.dataset.treasuryTab)));
    let initialTreasuryTab = 'treasury';
    try { initialTreasuryTab = sessionStorage.getItem('finea-treasury-tab') || 'treasury'; } catch (_) {}
    if (!['treasury', 'vat'].includes(initialTreasuryTab)) initialTreasuryTab = 'treasury';
    setTreasuryTab(initialTreasuryTab);

    document.querySelectorAll('[data-vat-amount]').forEach(input => {
        const mode = input.dataset.vatAmount;
        const maximum = Number(input.dataset.vatMax || 0);
        const remaining = document.querySelector('[data-vat-remaining="' + mode + '"]');
        const error = document.querySelector('[data-vat-error="' + mode + '"]');
        const submit = document.querySelector('[data-vat-submit="' + mode + '"]');
        const refresh = () => {
            const raw = input.value.trim();
            const amount = raw === '' ? 0 : Number(raw);
            const invalidNumber = raw !== '' && (!Number.isFinite(amount) || amount < 0);
            const exceeded = Number.isFinite(amount) && amount > maximum;
            const left = Math.max(0, maximum - (Number.isFinite(amount) ? Math.max(0, amount) : 0));
            if (remaining) remaining.textContent = money(left);
            input.classList.toggle('border-rose-400', invalidNumber || exceeded);
            input.classList.toggle('ring-2', exceeded);
            input.classList.toggle('ring-rose-100', exceeded);
            if (error) {
                error.classList.toggle('hidden', !(invalidNumber || exceeded));
                error.textContent = exceeded
                    ? 'Montant supérieur au maximum disponible de ' + money(maximum) + '.'
                    : (invalidNumber ? 'Saisissez un montant valide.' : '');
            }
            if (submit) submit.disabled = invalidNumber || exceeded || (raw !== '' && amount <= 0);
            input.setCustomValidity(exceeded ? 'Le montant dépasse le solde TVA disponible.' : '');
        };
        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
        refresh();
    });

    let activeTreasuryDrag = '';
    const transferSource = document.querySelector('[data-detail="real-in"]'),
        transferTarget = document.querySelector('[data-detail="available"]');
    if (canManageTreasury && transferSource && transferTarget) {
        transferSource.draggable = true;
        transferSource.dataset.transferSource = 'true';
        transferSource.title = 'Glisser vers le disponible réel pour préparer un transfert';
        transferTarget.dataset.transferTarget = 'true';
        transferSource.addEventListener('dragstart', event => {
            activeTreasuryDrag = 'real-to-available';
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', activeTreasuryDrag);
            transferSource.classList.add('is-dragging')
        });
        transferSource.addEventListener('dragend', () => {
            activeTreasuryDrag = '';
            transferSource.classList.remove('is-dragging');
            transferTarget.classList.remove('is-transfer-target')
        });
        transferTarget.addEventListener('dragenter', event => {
            if (activeTreasuryDrag === 'real-to-available') {
                event.preventDefault();
                transferTarget.classList.add('is-transfer-target')
            }
        });
        transferTarget.addEventListener('dragover', event => {
            if (activeTreasuryDrag === 'real-to-available') {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move'
            }
        });
        transferTarget.addEventListener('dragleave', event => {
            if (!transferTarget.contains(event.relatedTarget)) transferTarget.classList.remove('is-transfer-target')
        });
        transferTarget.addEventListener('drop', event => {
            event.preventDefault();
            event.stopPropagation();
            transferTarget.classList.remove('is-transfer-target');
            if (activeTreasuryDrag === 'real-to-available') open('transferModal');
            activeTreasuryDrag = ''
        })
    }
    const outputSource = document.querySelector('[data-detail="available"]'),
        outputTarget = document.querySelector('[data-detail="real-out"]');
    if (canManageTreasury && outputSource && outputTarget && transferSource) {
        outputSource.draggable = true;
        outputSource.dataset.outputSource = 'true';
        outputSource.title = 'Glisser vers les sorties réelles ou retourner vers les entrées réelles';
        outputTarget.dataset.outputTarget = 'true';
        outputSource.addEventListener('dragstart', event => {
            activeTreasuryDrag = 'available-source';
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', activeTreasuryDrag);
            outputSource.classList.add('is-dragging')
        });
        outputSource.addEventListener('dragend', () => {
            activeTreasuryDrag = '';
            outputSource.classList.remove('is-dragging');
            outputTarget.classList.remove('is-output-target');
            transferSource.classList.remove('is-return-target')
        });
        outputTarget.addEventListener('dragenter', event => {
            if (activeTreasuryDrag === 'available-source') {
                event.preventDefault();
                outputTarget.classList.add('is-output-target')
            }
        });
        outputTarget.addEventListener('dragover', event => {
            if (activeTreasuryDrag === 'available-source') {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move'
            }
        });
        outputTarget.addEventListener('dragleave', event => {
            if (!outputTarget.contains(event.relatedTarget)) outputTarget.classList.remove('is-output-target')
        });
        outputTarget.addEventListener('drop', event => {
            event.preventDefault();
            event.stopPropagation();
            outputTarget.classList.remove('is-output-target');
            if (activeTreasuryDrag === 'available-source') {
                renderMovementAllocation(true);
                document.getElementById('movementDirection').value = 'out';
                document.getElementById('movementTitle').textContent = 'Nouvelle sortie manuelle';
                const submit = document.getElementById('movementSubmit');
                submit.textContent = 'Enregistrer la sortie';
                submit.className = 'rounded-xl bg-rose-600 px-5 py-3 font-black text-white md:col-span-2';
                open('movementModal')
            }
            activeTreasuryDrag = ''
        });
        transferSource.addEventListener('dragenter', event => {
            if (activeTreasuryDrag === 'available-source') {
                event.preventDefault();
                transferSource.classList.add('is-return-target')
            }
        });
        transferSource.addEventListener('dragover', event => {
            if (activeTreasuryDrag === 'available-source') {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move'
            }
        });
        transferSource.addEventListener('dragleave', event => {
            if (!transferSource.contains(event.relatedTarget)) transferSource.classList.remove('is-return-target')
        });
        transferSource.addEventListener('drop', event => {
            event.preventDefault();
            event.stopPropagation();
            transferSource.classList.remove('is-return-target');
            if (activeTreasuryDrag === 'available-source') open('returnModal');
            activeTreasuryDrag = ''
        })
    }
    document.querySelectorAll('#returnRealForm [name="return_mode"]').forEach(radio => radio.onchange = () => {
        const partial = radio.value === 'partial' && radio.checked,
            label = document.getElementById('returnAmountLabel'),
            amount = document.getElementById('returnAmount'),
            all = document.getElementById('returnAllAmount');
        label.classList.toggle('hidden', !partial);
        amount.disabled = !partial;
        amount.required = partial;
        all.disabled = partial
    });
    const transferReason = document.querySelector('#transferModal textarea[name="note"]');
    if (transferReason && !transferReason.value.trim()) transferReason.value = 'Alimentation du disponible réel pour couvrir les charges et décaissements du jour.';
    const returnReason = document.querySelector('#returnRealForm textarea[name="note"]');
    if (returnReason && !returnReason.value.trim()) returnReason.value = 'Retour du solde restant après les décaissements vers les entrées réelles.';
    const manual = (direction, status) => treasuryData.manual.filter(r => r.direction === direction && r.status === status),
        row = (r, type) => {
            const category = treasuryCatalogs.entry_categories?.[r.entry_category] || '',
                allocation = [r.chantier_code, r.chantier_title, r.ligne_devis_label].filter(Boolean).join(' · '),
                meta = [r.reference || type, category, allocation].filter(Boolean).join(' · ');
            return `<div class="grid gap-2 border-b px-2 py-4 text-sm md:grid-cols-[120px_1fr_160px]"><span class="text-slate-500">${esc(new Date((r.movement_date||'')+'T00:00:00').toLocaleDateString('fr-FR'))}</span><div><strong>${esc(r.label)}</strong><small class="block text-slate-500">${esc(meta)}</small></div><strong class="text-right">${money(r.amount)}</strong></div>`
        };
    document.querySelectorAll('[data-detail]').forEach(b => b.onclick = () => {
        const key = b.dataset.detail,
            t = treasuryData.totals;
        let title = '',
            rows = [],
            intro = '';
        if (key === 'available') {
            const a = treasuryData.availableBreakdown;
            title = 'Disponible du jour';
            intro = `Situation initiale : <b>${money(a.opening)}</b><br>+ Entrées manuelles réalisées : <b>${money(a.manual_real_in)}</b><br>− Sorties manuelles réalisées : <b>${money(a.manual_real_out)}</b><br>− Fiches décaissées : <b>${money(a.fiche_real_out)}</b><hr class="my-2 border-indigo-200">Disponible : <b>${money(treasuryData.available)}</b>`;
            rows = treasuryData.paidFichesPeriod.map(r => row(r, 'Fiche décaissée'));
        } else if (key === 'projected') {
            title = 'Disponible prévisionnel';
            intro = `Disponible actuel ${money(treasuryData.available)} + entrées prévues ${money(t.forecast_in)} − sorties prévues ${money(t.forecast_out)}`;
            rows = [...manual('in', 'forecast').map(r => row(r, 'Entrée prévue')), ...manual('out', 'forecast').map(r => row(r, 'Sortie prévue')), ...treasuryData.plannedFichesPeriod.map(r => row(r, 'Fiche prévue'))];
        } else {
            const map = {
                    'real-in': ['Entrées réelles', 'in', 'real'],
                    'forecast-in': ['Entrées prévisionnelles', 'in', 'forecast'],
                    'real-out': ['Sorties réelles', 'out', 'real'],
                    'forecast-out': ['Sorties prévisionnelles', 'out', 'forecast']
                },
                m = map[key];
            title = m[0];
            rows = manual(m[1], m[2]).map(r => row(r, m[0]));
            if (key === 'real-out') rows.push(...treasuryData.paidFichesPeriod.map(r => row(r, 'Fiche décaissée')));
            if (key === 'forecast-out') rows.push(...treasuryData.plannedFichesPeriod.map(r => row(r, 'Fiche prévue')))
        }
        document.getElementById('detailTitle').textContent = title;
        document.getElementById('detailContent').innerHTML = (intro ? `<div class="mb-4 rounded-2xl bg-indigo-50 p-4 text-sm text-indigo-950">${intro}</div>` : '') + (rows.join('') || '<div class="py-12 text-center text-slate-500">Aucun mouvement dans cette rubrique.</div>');
        open('detailModal')
    });
    const flowLabel = r => r.assignment_label || [r.chantier_code, r.chantier_title, r.ligne_devis_label].filter(Boolean).join(' · ') || 'Sans affectation',
        beneficiaryLabel = r => r.beneficiary || 'Bénéficiaire non renseigné',
        requesterLabel = r => r.requester_name || 'Fiche historique sans demandeur identifié';
    const profileUrl = path => {
            path = String(path || '').trim();
            return !path ? '' : /^https?:\/\//.test(path) || path.startsWith('/') ? path : '../' + path.replace(/^\.\//, '')
        },
        initials = name => String(name || '?').trim().split(/\s+/).slice(0, 2).map(x => x[0] || '').join('').toUpperCase(),
        avatar = (path, name, size = 'h-7 w-7') => path ? `<img src="${esc(profileUrl(path))}" alt="" class="${size} rounded-full border-2 border-white object-cover shadow">` : `<span class="${size} grid place-items-center rounded-full bg-slate-200 text-[10px] font-black text-slate-600">${esc(initials(name))}</span>`,
        personBadge = (role, name, photo, phone, color) => {
            if (!name) return '';
            const palette = {
                emerald: ['#ecfdf5', '#a7f3d0', '#047857'],
                indigo: ['#eef2ff', '#c7d2fe', '#4338ca'],
                amber: ['#fffbeb', '#fde68a', '#b45309']
            } [color] || ['#f8fafc', '#e2e8f0', '#475569'];
            return `<span class="inline-flex items-center gap-2 rounded-full border py-1 pl-1 pr-3" style="background:${palette[0]};border-color:${palette[1]}">${avatar(photo,name)}<span><b class="block text-[9px] uppercase tracking-wide" style="color:${palette[2]}">${esc(role)}</b><span class="block text-[11px] font-bold text-slate-800">${esc(name)}</span>${phone?`<small class="block text-[9px] text-slate-500">${esc(phone)}</small>`:''}</span></span>`
        };

    function movementDetails(r, items = []) {
        const date = r.movement_date ? new Date(r.movement_date + 'T00:00:00').toLocaleDateString('fr-FR') : '';
        const isFiche = r.movement_source === 'fiche_decaissement' || Boolean(r.ligne_debourse_id || r.debourse_id || r.authorization_code);
        const chantier = [r.chantier_code, r.chantier_title].filter(Boolean).join(' · ');
        const ficheDetails = isFiche ? [
            ['Chantier', chantier],
            ['Ligne de devis', r.ligne_devis_label],
            ['Déboursé', r.debourse_label],
            ['Montant du déboursé', r.debourse_amount != null ? money(r.debourse_amount) : ''],
            ['Ligne de déboursé', [r.ligne_debourse_label, r.ligne_debourse_category].filter(Boolean).join(' · ')],
            ['Montant de la ligne de déboursé', r.ligne_debourse_amount != null ? money(r.ligne_debourse_amount) : ''],
            ['Détail de la demande', r.request_details],
            ['Précision de la fiche', r.fiche_precision],
            ['Mode de paiement', r.payment_mode],
            ['Code d’autorisation', r.authorization_code]
        ] : [
            ['Affectation', flowLabel(r)]
        ];
        const details = [
            ['Référence', r.reference],
            ['Date du mouvement', date], ...ficheDetails, ['Nature', treasuryCatalogs.entry_categories?.[r.entry_category]],
            ['Note / motif', r.note], ...items
        ].filter(([, value]) => value !== null && value !== undefined && String(value).trim() !== '' && value !== 'Sans affectation');
        const people = [personBadge('Bénéficiaire du paiement · ' + (r.beneficiary_type || 'Non typé'), r.beneficiary, r.beneficiary_photo, r.beneficiary_phone, 'emerald'), personBadge('Demandeur de la FEB', r.requester_name, r.requester_photo, r.requester_phone, 'indigo'), personBadge('Validateur de la FEB', r.validator_name, r.validator_photo, r.validator_phone, 'amber'), personBadge('Contestataire retenu', r.challenger_name, r.challenger_photo, r.challenger_phone, 'slate')].filter(Boolean).join('');
        const actions = [];
        if (isFiche && r.reference) actions.push(`<a target="_blank" rel="noopener" href="../decaissement/voir_fiche_details.php?num_fiche=${encodeURIComponent(r.reference)}" class="inline-flex items-center gap-2 rounded-lg bg-indigo-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-arrow-up-right-from-square"></i>Ouvrir la fiche</a>`);
        if (r.invoice_id) actions.push(`<a target="_blank" rel="noopener" href="visualiser_facture_vente.php?id=${encodeURIComponent(r.invoice_id)}" class="inline-flex items-center gap-2 rounded-lg border border-indigo-200 bg-white px-3 py-2 text-xs font-black text-indigo-700"><i class="fas fa-file-invoice"></i>Voir la facture</a>`);
        if (r.movement_source === 'manual' && r.direction === 'out' && r.id) actions.push(`<a target="_blank" rel="noopener" href="request/export_manual_cash_out_pdf.php?id=${encodeURIComponent(r.id)}" class="inline-flex items-center gap-2 rounded-lg bg-rose-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-file-pdf text-white"></i>Fiche de sortie de caisse</a>`);
        if (!details.length && !people && !actions.length) return '';
        const detailItems = details.map(([label, value]) => {
            const bciLink = label === 'Code d’autorisation' && isFiche && r.reference ? `<a target="_blank" rel="noopener" href="../decaissement/request/view_fiche_document.php?num_fiche=${encodeURIComponent(r.reference)}&type=bci" class="ml-2 inline-grid h-6 w-6 place-items-center rounded-md border border-indigo-200 bg-white text-indigo-700 hover:bg-indigo-50" title="Visualiser le BCI ISO 9001" aria-label="Visualiser le BCI ISO 9001"><i class="fas fa-eye text-[10px]"></i></a>` : '';
            return `<div class="treasury-detail-item"><small>${esc(label)}</small><span class="flex items-center"><strong>${esc(value)}</strong>${bciLink}</span></div>`
        }).join('');
        return `<details class="treasury-movement-details"><summary><i class="fas fa-chevron-down text-[9px]"></i>Voir les détails</summary>${details.length?`<div class="treasury-detail-grid">${detailItems}</div>`:''}${people?`<div class="treasury-detail-people">${people}</div>`:''}${actions.length?`<div class="mt-3 flex flex-wrap gap-2">${actions.join('')}</div>`:''}</details>`;
    }

    function flowRow(r) {
        const incoming = r.direction === 'in',
            category = treasuryCatalogs.entry_categories?.[r.entry_category] || '',
            meta = [r.reference, category, flowLabel(r)].filter(Boolean).join(' · ');
        return `<article class="treasury-flow-row"><span class="treasury-flow-icon ${incoming?'in':'out'}"><i class="fas ${incoming?'fa-arrow-down':'fa-arrow-up'}"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="truncate text-slate-950">${esc(r.label||'Mouvement')}</strong><span class="rounded-full px-2 py-1 text-[10px] font-black uppercase ${incoming?'bg-emerald-50 text-emerald-700':'bg-rose-50 text-rose-700'}">${incoming?'Entrée':'Sortie'}</span></div><small class="mt-1 block truncate text-slate-500">${esc(meta)} · ${esc(new Date((r.movement_date||'')+'T00:00:00').toLocaleDateString('fr-FR'))}</small>${movementDetails(r)}</div><strong class="treasury-flow-amount text-right text-base ${incoming?'text-emerald-700':'text-rose-700'}">${incoming?'+':'−'} ${money(r.amount)}</strong></article>`
    }

    function detailDataset(key) {
        const opening = treasuryData.opening ? {
            direction: 'in',
            label: 'Solde initial des entrées réelles',
            reference: 'INITIALISATION',
            amount: treasuryData.opening.amount,
            movement_date: treasuryData.opening.effective_date,
            note: treasuryData.opening.note
        } : null;
        const openingInPeriod = opening && opening.movement_date >= '<?= $from ?>' && opening.movement_date <= '<?= $to ?>';
        const transfersOut = (treasuryData.transferRows || []).map(row => ({
            ...row,
            direction: 'out',
            label: row.label || 'Transfert vers le disponible'
        }));
        const transfersIn = (treasuryData.transfersSinceOpening || []).map(row => ({
            ...row,
            direction: 'in',
            label: row.label || 'Transfert reçu des entrées réelles'
        }));

        if (key === 'available') {
            const rows = [...transfersIn, ...manual('out', 'real'), ...treasuryData.paidFichesPeriod.map(row => ({
                ...row,
                direction: 'out'
            }))];
            return {
                title: 'Disponible réel du jour',
                rows,
                context: `Transferts reçus des entrées réelles − sorties réellement exécutées = ${money(treasuryData.available)}`
            };
        }

        const definitions = {
            'real-in': ['Solde des entrées réelles', 'in', 'real'],
            'unreserved-in': ['Solde non réservé', 'in', 'real'],
            'forecast-in': ['Entrées prévisionnelles', 'in', 'forecast'],
            'real-out': ['FEB à traiter', 'out', 'real'],
            'forecast-out': ['FEB à venir', 'out', 'forecast'],
            'projected-out': ['Projection déboursé', 'out', 'forecast'],
            'validation-purchase': ['Décaissements en validation achat', 'out', 'forecast']
        };
        const definition = definitions[key];
        const rows = [];

        if (key === 'real-in') {
            if (openingInPeriod) rows.push(opening);
            rows.push(...manual('in', 'real'), ...treasuryData.invoiceRealRows, ...transfersOut);
        }
        if (key === 'unreserved-in') {
            if (openingInPeriod) rows.push(opening);
            rows.push(...manual('in', 'real'), ...treasuryData.invoiceRealRows, ...transfersOut);
        }
        if (key === 'forecast-in') {
            rows.push(
                ...treasuryData.invoiceForecastRows.map(row => ({
                    ...row,
                    direction: 'in',
                    forecast_state: 'pending'
                })),
                ...treasuryData.invoiceRealRows.map(row => ({
                    ...row,
                    direction: 'out',
                    forecast_state: 'collected',
                    label: (row.label || 'Facture') + ' · encaissée'
                }))
            );
        }
        if (key === 'real-out') rows.push(...(treasuryData.febToDateRows || []).map(row => ({
            ...row,
            direction: 'out'
        })));
        if (key === 'forecast-out') rows.push(...(treasuryData.futureFebRows || []).map(row => ({
            ...row,
            direction: 'out'
        })));
        if (key === 'validation-purchase') rows.push(...(treasuryData.validationPurchaseRows || []).map(row => ({
            ...row,
            direction: 'out'
        })));
        if (key === 'projected-out') rows.push(...(treasuryData.projectedDebourseRows || []).map(row => ({
            ...row,
            direction: 'out'
        })));

        let context = 'FEB encore en attente de traitement dont la date prévue est postérieure au <?= date('d/m/Y', strtotime($to)) ?>.';
        if (key === 'real-in') context = `Fonds réellement reçus − transferts vers le disponible = ${money(treasuryData.realEntriesBalance)}`;
        if (key === 'unreserved-in') context = `Solde des entrées réelles ${money(treasuryData.realEntriesBalance)} − réservations FEB ${money(treasuryData.fundingReservationTotals?.real||0)} = ${money(Number(treasuryData.realEntriesBalance||0)-Number(treasuryData.fundingReservationTotals?.real||0))}`;
        if (key === 'forecast-in') context = 'Suivi des factures positionnées sur la période : celles encaissées quittent les prévisions et créditent les entrées réelles.';
        if (key === 'validation-purchase') context = 'FEB exigibles, conformes et approuvées, actuellement en attente de validation achat.';
        if (key === 'real-out') context = 'FEB encore en attente de traitement dont la date prévue est au plus tard le <?= date('d/m/Y', strtotime($to)) ?>, reports compris.';
        if (key === 'projected-out') context = 'Reste à payer de toutes les lignes de déboursé des chantiers actifs : les FEB soumises, y compris reportées, et les fiches non refusées diminuent la prévision ; les FEB/fiches refusées ne la diminuent pas.';

        return {
            title: definition[0],
            rows,
            context
        };
    }

    function renderFlowAnalysis(dataset, mode = 'list') {
        const rows = dataset.rows || [],
            incoming = rows.filter(r => r.direction === 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
            outgoing = rows.filter(r => r.direction !== 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
            net = incoming - outgoing;
        let groups;
        if (mode === 'list') groups = new Map([
            ['Tous les mouvements', rows]
        ]);
        else {
            const getter = mode === 'allocation' ? flowLabel : mode === 'beneficiary' ? beneficiaryLabel : requesterLabel;
            groups = new Map;
            rows.forEach(r => {
                const key = getter(r);
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(r)
            })
        }
        const bodies = [...groups].map(([label, items]) => {
            const subtotal = items.reduce((s, r) => s + (r.direction === 'in' ? 1 : -1) * Number(r.amount || 0), 0);
            return `<section class="treasury-group"><header class="treasury-group-head"><div><strong class="text-slate-950">${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} mouvement${items.length>1?'s':''}</small></div><strong class="${subtotal>=0?'text-emerald-700':'text-rose-700'}">${subtotal>=0?'+':'−'} ${money(Math.abs(subtotal))}</strong></header><div class="treasury-group-body">${items.map(flowRow).join('')}</div></section>`
        }).join('');
        document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="grid gap-3 lg:grid-cols-[1fr_auto]"><div><p class="text-sm text-slate-500">${esc(dataset.context)}</p><div class="mt-3 flex flex-wrap gap-2" data-group-tabs>${[['list','Affichage liste'],['allocation','Par affectation'],['beneficiary','Par bénéficiaire'],['requester','Par demandeur']].map(([v,l])=>`<button data-mode="${v}" class="rounded-full px-3 py-2 text-xs font-black ${v===mode?'bg-indigo-700 text-white':'bg-slate-100 text-slate-600'}">${l}</button>`).join('')}</div></div><div class="grid grid-cols-3 gap-2 text-right"><div class="rounded-xl bg-emerald-50 p-3"><small class="text-emerald-700">Entrées</small><strong class="block text-emerald-800">${money(incoming)}</strong></div><div class="rounded-xl bg-rose-50 p-3"><small class="text-rose-700">Sorties</small><strong class="block text-rose-800">${money(outgoing)}</strong></div><div class="rounded-xl bg-indigo-50 p-3"><small class="text-indigo-700">Total général net</small><strong class="block ${net>=0?'text-emerald-800':'text-rose-800'}">${money(net)}</strong></div></div></div></div><div class="grid gap-4">${bodies||'<div class="rounded-2xl bg-white py-14 text-center text-slate-500">Aucun mouvement dans cette rubrique.</div>'}</div>`;
        document.querySelectorAll('[data-group-tabs] button').forEach(button => button.onclick = () => renderFlowAnalysis(dataset, button.dataset.mode))
    }

    function exportTreasuryCsv(dataset, rows) {
        const fields = ['Date', 'Sens', 'Libellé', 'Référence', 'Affectation', 'Bénéficiaire du paiement', 'Demandeur de la FEB', 'Validateur de la FEB', 'Montant'];
        const values = rows.map(r => [r.movement_date, r.direction === 'in' ? 'Entrée' : 'Sortie', r.label || '', r.reference || '', flowLabel(r), beneficiaryLabel(r), requesterLabel(r), r.validator_name || '', Number(r.amount || 0)]),
            quote = v => '"' + String(v ?? '').replace(/"/g, '""') + '"',
            csv = '\uFEFF' + [fields, ...values].map(line => line.map(quote).join(';')).join('\r\n'),
            blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8'
            }),
            link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'tresorerie_' + dataset.title.toLowerCase().replace(/[^a-z0-9]+/gi, '_') + '_<?= date('Ymd') ?>.csv';
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000)
    }

    function renderTreasuryAnalysis(dataset, mode = 'list', filters = {
        allocation: '',
        beneficiary: ''
    }) {
        const allocations = [...new Set(dataset.rows.map(flowLabel))].sort(),
            beneficiaries = [...new Set(dataset.rows.map(beneficiaryLabel))].sort(),
            rows = dataset.rows.filter(r => (!filters.allocation || flowLabel(r) === filters.allocation) && (!filters.beneficiary || beneficiaryLabel(r) === filters.beneficiary)),
            incoming = rows.filter(r => r.direction === 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
            outgoing = rows.filter(r => r.direction !== 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
            net = incoming - outgoing,
            getter = mode === 'allocation' ? flowLabel : mode === 'beneficiary' ? beneficiaryLabel : mode === 'requester' ? requesterLabel : null,
            groups = new Map;
        if (!getter) groups.set('Tous les mouvements', rows);
        else rows.forEach(r => {
            const key = getter(r);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(r)
        });
        const bodies = [...groups].map(([label, items]) => {
            const subtotal = items.reduce((s, r) => s + (r.direction === 'in' ? 1 : -1) * Number(r.amount || 0), 0),
                sample = items[0] || {},
                identity = mode === 'beneficiary' ? `<div class="flex items-center gap-3">${avatar(sample.beneficiary_photo,label,'h-12 w-12')}<div><span class="rounded-full bg-emerald-100 px-2 py-1 text-[9px] font-black uppercase text-emerald-800">Bénéficiaire · ${esc(sample.beneficiary_type||'Non typé')}</span><strong class="mt-1 block">${esc(label)}</strong>${sample.beneficiary_phone?`<small class="text-slate-500"><i class="fas fa-phone mr-1"></i>${esc(sample.beneficiary_phone)}</small>`:''}</div></div>` : mode === 'requester' ? `<div class="flex items-center gap-3">${avatar(sample.requester_photo,label,'h-12 w-12')}<div><span class="rounded-full bg-indigo-100 px-2 py-1 text-[9px] font-black uppercase text-indigo-800">Demandeur</span><strong class="mt-1 block">${esc(label)}</strong>${sample.requester_phone?`<small class="text-slate-500"><i class="fas fa-phone mr-1"></i>${esc(sample.requester_phone)}</small>`:''}</div></div>` : `<div><strong>${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} mouvement${items.length>1?'s':''}</small></div>`;
            return `<section class="treasury-group"><header class="treasury-group-head">${identity}<div class="text-right"><small class="block text-slate-400">${items.length} mouvement${items.length>1?'s':''}</small><strong class="${subtotal>=0?'text-emerald-700':'text-rose-700'}">${subtotal>=0?'+':'−'} ${money(Math.abs(subtotal))}</strong></div></header><div class="treasury-group-body">${items.map(flowRow).join('')}</div></section>`
        }).join('');
        document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm text-slate-500">${esc(dataset.context)}</p><div class="mt-3 flex flex-wrap gap-2" data-group-tabs>${[['list','Liste'],['allocation','Par affectation'],['beneficiary','Par bénéficiaire'],['requester','Par demandeur']].map(([v,l])=>`<button data-mode="${v}" class="rounded-full px-3 py-2 text-xs font-black ${v===mode?'bg-indigo-700 text-white':'bg-slate-100 text-slate-600'}">${l}</button>`).join('')}</div></div><button data-export-view class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white"><i class="fas fa-file-excel mr-2 text-white"></i>Exporter la vue</button></div><div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_150px_150px_170px]"><label class="text-xs font-bold text-slate-500">Affectation<select data-filter-allocation class="mt-1 w-full rounded-xl border px-3 py-2"><option value="">Toutes les affectations</option>${allocations.map(v=>`<option ${v===filters.allocation?'selected':''}>${esc(v)}</option>`).join('')}</select></label><label class="text-xs font-bold text-slate-500">Bénéficiaire<select data-filter-beneficiary class="mt-1 w-full rounded-xl border px-3 py-2"><option value="">Tous les bénéficiaires</option>${beneficiaries.map(v=>`<option ${v===filters.beneficiary?'selected':''}>${esc(v)}</option>`).join('')}</select></label><div class="rounded-xl bg-emerald-50 p-3 text-right"><small>Entrées</small><strong class="block text-emerald-800">${money(incoming)}</strong></div><div class="rounded-xl bg-rose-50 p-3 text-right"><small>Sorties</small><strong class="block text-rose-800">${money(outgoing)}</strong></div><div class="rounded-xl bg-indigo-50 p-3 text-right"><small>Total général net</small><strong class="block ${net>=0?'text-emerald-800':'text-rose-800'}">${money(net)}</strong></div></div></div><div class="grid gap-4">${bodies||'<div class="rounded-2xl bg-white py-14 text-center text-slate-500">Aucun résultat pour ces filtres.</div>'}</div>`;
        document.querySelectorAll('[data-group-tabs] button').forEach(b => b.onclick = () => renderTreasuryAnalysis(dataset, b.dataset.mode, filters));
        document.querySelector('[data-filter-allocation]').onchange = e => renderTreasuryAnalysis(dataset, mode, {
            ...filters,
            allocation: e.target.value
        });
        document.querySelector('[data-filter-beneficiary]').onchange = e => renderTreasuryAnalysis(dataset, mode, {
            ...filters,
            beneficiary: e.target.value
        });
        document.querySelector('[data-export-view]').onclick = () => exportTreasuryCsv(dataset, rows)
    }
    document.querySelectorAll('[data-detail]').forEach(button => button.onclick = () => {
        const dataset = detailDataset(button.dataset.detail);
        document.getElementById('detailTitle').textContent = dataset.title;
        renderTreasuryAnalysis(dataset);
        open('detailModal')
    });
</script>
<script>
    (() => {
        const uniq = (rows, getter) => [...new Set(rows.map(getter))].sort(),
            selected = (filters, key) => filters[key] || [],
            matches = (value, values) => !values.length || values.includes(value);

        function multiPicker(label, key, options, values) {
            return `<div class="rounded-2xl border bg-white p-2" data-multi-filter="${key}"><span class="text-[10px] font-black uppercase tracking-wide text-slate-500">${label}</span><div class="mt-1 flex min-h-9 flex-wrap items-center gap-1">${values.map(v=>`<button type="button" data-remove="${encodeURIComponent(v)}" class="rounded-full bg-indigo-100 px-2 py-1 text-[10px] font-bold text-indigo-800">${esc(v)} ×</button>`).join('')}<input type="search" list="filter-${key}" placeholder="Rechercher…" class="min-w-[130px] flex-1 border-0 p-1 text-xs outline-none"><datalist id="filter-${key}">${options.filter(v=>!values.includes(v)).map(v=>`<option value="${esc(v)}">`).join('')}</datalist></div></div>`
        }

        function renderMulti(dataset, mode = 'list', filters = {
            allocation: [],
            beneficiary: [],
            requester: []
        }) {
            const all = dataset.rows || [],
                options = {
                    allocation: uniq(all, flowLabel),
                    beneficiary: uniq(all, beneficiaryLabel),
                    requester: uniq(all, requesterLabel)
                },
                rows = all.filter(r => matches(flowLabel(r), selected(filters, 'allocation')) && matches(beneficiaryLabel(r), selected(filters, 'beneficiary')) && matches(requesterLabel(r), selected(filters, 'requester'))),
                incoming = rows.filter(r => r.direction === 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
                outgoing = rows.filter(r => r.direction !== 'in').reduce((s, r) => s + Number(r.amount || 0), 0),
                getter = mode === 'allocation' ? flowLabel : mode === 'beneficiary' ? beneficiaryLabel : mode === 'requester' ? requesterLabel : () => 'Tous les mouvements',
                groups = new Map;
            rows.forEach(r => {
                const k = getter(r);
                if (!groups.has(k)) groups.set(k, []);
                groups.get(k).push(r)
            });
            const sections = [...groups].map(([label, items]) => {
                const subtotal = items.reduce((s, r) => s + (r.direction === 'in' ? 1 : -1) * Number(r.amount || 0), 0),
                    sample = items[0] || {},
                    identity = mode === 'beneficiary' ? `${avatar(sample.beneficiary_photo,label,'h-12 w-12')}<div><span class="rounded-full bg-emerald-100 px-2 py-1 text-[9px] font-black uppercase text-emerald-800">Bénéficiaire · ${esc(sample.beneficiary_type||'Non typé')}</span><strong class="mt-1 block">${esc(label)}</strong><small class="text-slate-500">${esc(sample.beneficiary_phone||'Téléphone non renseigné')}</small></div>` : mode === 'requester' ? `${avatar(sample.requester_photo,label,'h-12 w-12')}<div><span class="rounded-full bg-indigo-100 px-2 py-1 text-[9px] font-black uppercase text-indigo-800">Demandeur</span><strong class="mt-1 block">${esc(label)}</strong><small class="text-slate-500">${esc(sample.requester_phone||'Téléphone non renseigné')}</small></div>` : `<div><strong>${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} mouvement${items.length>1?'s':''}</small></div>`;
                return `<section class="treasury-group"><header class="treasury-group-head"><div class="flex items-center gap-3">${identity}</div><div class="text-right"><small class="block text-slate-400">Sous-total</small><strong class="${subtotal>=0?'text-emerald-700':'text-rose-700'}">${subtotal>=0?'+':'−'} ${money(Math.abs(subtotal))}</strong></div></header><div class="treasury-group-body">${items.map(flowRow).join('')}</div></section>`
            }).join('');
            document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap justify-between gap-3"><div><p class="text-sm text-slate-500">${esc(dataset.context)}</p><div class="mt-2 flex flex-wrap gap-2" data-modes>${[['list','Liste'],['allocation','Par affectation'],['beneficiary','Par bénéficiaire'],['requester','Par demandeur']].map(([v,l])=>`<button data-mode="${v}" class="rounded-full px-3 py-2 text-xs font-black ${v===mode?'bg-indigo-700 text-white':'bg-slate-100 text-slate-600'}">${l}</button>`).join('')}</div></div><div class="flex gap-2"><button data-csv class="rounded-xl border bg-white px-3 py-2 text-xs font-black"><i class="fas fa-file-excel mr-1 text-emerald-600"></i>Excel</button><button data-pdf class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-file-pdf mr-1"></i>PDF ISO signé</button></div></div><div class="mt-3 grid gap-2 lg:grid-cols-3">${multiPicker('Affectations','allocation',options.allocation,filters.allocation)}${multiPicker('Bénéficiaires','beneficiary',options.beneficiary,filters.beneficiary)}${multiPicker('Demandeurs','requester',options.requester,filters.requester)}</div><div class="mt-3 grid grid-cols-3 gap-2 text-right"><div class="rounded-xl bg-emerald-50 p-2"><small>Entrées</small><b class="block text-emerald-800">${money(incoming)}</b></div><div class="rounded-xl bg-rose-50 p-2"><small>Sorties</small><b class="block text-rose-800">${money(outgoing)}</b></div><div class="rounded-xl bg-indigo-50 p-2"><small>Total net</small><b class="block">${money(incoming-outgoing)}</b></div></div></div><div class="grid gap-4">${sections||'<div class="rounded-2xl bg-white py-14 text-center text-slate-500">Aucun résultat pour ces filtres.</div>'}</div>`;
            document.querySelectorAll('[data-modes] button').forEach(b => b.onclick = () => renderMulti(dataset, b.dataset.mode, filters));
            document.querySelectorAll('[data-multi-filter]').forEach(root => {
                const key = root.dataset.multiFilter,
                    input = root.querySelector('input');
                input.onchange = () => {
                    const value = input.value.trim();
                    if (value && options[key].includes(value) && !filters[key].includes(value)) renderMulti(dataset, mode, {
                        ...filters,
                        [key]: [...filters[key], value]
                    });
                    else input.value = ''
                };
                root.querySelectorAll('[data-remove]').forEach(b => b.onclick = () => renderMulti(dataset, mode, {
                    ...filters,
                    [key]: filters[key].filter(v => v !== decodeURIComponent(b.dataset.remove))
                }))
            });
            document.querySelector('[data-csv]').onclick = () => exportTreasuryCsv(dataset, rows);
            document.querySelector('[data-pdf]').onclick = () => {
                const q = new URLSearchParams({
                    view: dataset.view,
                    from: '<?= $from ?>',
                    to: '<?= $to ?>',
                    group: mode,
                    allocations: JSON.stringify(filters.allocation),
                    beneficiaries: JSON.stringify(filters.beneficiary),
                    requesters: JSON.stringify(filters.requester)
                });
                window.open('request/export_treasury_pdf.php?' + q.toString(), '_blank')
            }
        }

        function renderInvoiceForecast(dataset) {
            const rows = dataset.rows || [],
                pending = rows.filter(r => r.forecast_state === 'pending'),
                collected = rows.filter(r => r.forecast_state === 'collected'),
                pendingTotal = pending.reduce((s, r) => s + Number(r.amount || 0), 0),
                collectedTotal = collected.reduce((s, r) => s + Number(r.amount || 0), 0),
                invoiceRow = (r, collectedRow) => `<article class="treasury-flow-row"><span class="treasury-flow-icon ${collectedRow?'out':'in'}"><i class="fas ${collectedRow?'fa-check':'fa-file-invoice-dollar'}"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong class="text-slate-950">${esc(r.reference||'Facture')}</strong><span class="rounded-full px-2 py-1 text-[10px] font-black uppercase ${collectedRow?'bg-slate-100 text-slate-600':'bg-emerald-50 text-emerald-700'}">${collectedRow?'Sortie des prévisions':'À percevoir'}</span></div><small class="mt-1 block text-slate-500">${esc(r.beneficiary||'Client non renseigné')} · ${collectedRow?'Encaissée le':'Encaissement prévu le'} ${esc(new Date((r.movement_date||'')+'T00:00:00').toLocaleDateString('fr-FR'))}</small></div><strong class="treasury-flow-amount text-right text-base ${collectedRow?'text-slate-500':'text-emerald-700'}">${money(r.amount)}</strong></article>`,
                section = (title, items, collectedRows) => `<section class="treasury-group"><header class="treasury-group-head"><div><strong>${title}</strong><small class="ml-2 text-slate-400">${items.length} facture${items.length>1?'s':''}</small></div><strong class="${collectedRows?'text-slate-600':'text-emerald-700'}">${money(items.reduce((s,r)=>s+Number(r.amount||0),0))}</strong></header><div class="treasury-group-body">${items.map(r=>invoiceRow(r,collectedRows)).join('')}</div></section>`;
            document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap items-start justify-between gap-3"><p class="max-w-3xl text-sm text-slate-500">${esc(dataset.context)}</p><div class="flex gap-2"><button data-csv class="rounded-xl border bg-white px-3 py-2 text-xs font-black"><i class="fas fa-file-excel mr-1 text-emerald-600"></i>Excel</button><button data-pdf class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-file-pdf mr-1"></i>PDF ISO signé</button></div></div><div class="mt-4 grid gap-2 sm:grid-cols-3"><div class="rounded-xl bg-slate-50 p-3"><small class="text-slate-500">Portefeuille suivi</small><b class="block text-slate-900">${money(pendingTotal+collectedTotal)}</b></div><div class="rounded-xl bg-slate-100 p-3"><small class="text-slate-500">Déjà perçu</small><b class="block text-slate-700">${money(collectedTotal)}</b></div><div class="rounded-xl bg-emerald-50 p-3"><small class="text-emerald-700">Reste à percevoir</small><b class="block text-emerald-800">${money(pendingTotal)}</b></div></div></div><div class="grid gap-4">${pending.length?section('Factures à percevoir',pending,false):'<div class="rounded-2xl bg-emerald-50 p-5 text-emerald-800">Aucune facture ne reste à percevoir sur cette période.</div>'}${collected.length?section('Factures sorties des prévisions après encaissement',collected,true):''}</div>`;
            document.querySelector('[data-csv]').onclick = () => exportTreasuryCsv(dataset, rows);
            document.querySelector('[data-pdf]').onclick = () => {
                const q = new URLSearchParams({
                    view: 'forecast-in',
                    from: '<?= $from ?>',
                    to: '<?= $to ?>'
                });
                window.open('request/export_treasury_pdf.php?' + q.toString(), '_blank')
            }
        }

        function renderRealEntries(dataset, mode = 'grouped') {
            const rows = dataset.rows || [];
            const movementType = r => {
                if (r.reference === 'INITIALISATION') return 'Solde initial';
                if (r.invoice_id) return 'Factures encaissées';
                if (r.entry_category === 'pret') return 'Prêts reçus';
                if (r.entry_category === 'remboursement') return 'Remboursements reçus';
                if (r.transfer_type === 'to_real_entries') return 'Retours du disponible';
                if (r.direction === 'out' || r.transfer_type === 'to_available') return 'Transferts vers le disponible';
                if (r.entry_category === 'apport') return 'Apports de trésorerie';
                if (r.entry_category === 'subvention') return 'Subventions';
                return 'Autres entrées réelles';
            };
            const received = rows.filter(r => r.direction === 'in').reduce((s, r) => s + Number(r.amount || 0), 0);
            const transferred = rows.filter(r => r.direction === 'out').reduce((s, r) => s + Number(r.amount || 0), 0);
            const entryRow = r => `<article class="treasury-flow-row"><span class="treasury-flow-icon ${r.direction==='in'?'in':'out'}"><i class="fas ${r.direction==='in'?'fa-arrow-down':'fa-arrow-right'}"></i></span><div class="min-w-0"><strong class="block text-slate-950">${esc(r.label||r.reference||'Mouvement')}</strong><small class="mt-1 block text-slate-500">${esc(movementType(r))} · ${esc(r.reference||'Sans référence')} · ${esc(new Date((r.movement_date||'')+'T00:00:00').toLocaleDateString('fr-FR'))}</small>${movementDetails(r,[['Type de mouvement',movementType(r)],['Remboursement prévu le',r.repayment_date],['État du remboursement',r.repayment_status]])}</div><strong class="treasury-flow-amount text-right ${r.direction==='in'?'text-emerald-700':'text-amber-700'}">${r.direction==='in'?'+':'−'} ${money(r.amount)}</strong></article>`;
            const grouped = [...rows.reduce((groups, row) => {
                const type = movementType(row);
                if (!groups.has(type)) groups.set(type, []);
                groups.get(type).push(row);
                return groups
            }, new Map())];
            const content = mode === 'list' ?
                `<section class="treasury-group"><div class="treasury-group-body">${rows.map(entryRow).join('')}</div></section>` :
                grouped.map(([label, items]) => `<section class="treasury-group"><header class="treasury-group-head"><div><strong>${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} mouvement${items.length>1?'s':''}</small></div><strong>${money(items.reduce((s,r)=>s+Number(r.amount||0),0))}</strong></header><div class="treasury-group-body">${items.map(entryRow).join('')}</div></section>`).join('');
            document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="max-w-3xl text-sm text-slate-500">Origine des fonds réellement reçus et mouvements effectués vers le disponible réel.</p><div data-real-entry-modes class="mt-3 inline-flex rounded-lg border border-slate-200 bg-slate-100 p-1"><button data-mode="list" class="rounded-md px-3 py-1.5 text-xs font-bold ${mode==='list'?'bg-white text-slate-950 shadow-sm':'text-slate-500'}"><i class="fas fa-list mr-1"></i>Liste</button><button data-mode="grouped" class="rounded-md px-3 py-1.5 text-xs font-bold ${mode==='grouped'?'bg-white text-slate-950 shadow-sm':'text-slate-500'}"><i class="fas fa-layer-group mr-1"></i>Par type de mouvement</button></div></div><div class="flex gap-2"><button data-csv class="rounded-xl border bg-white px-3 py-2 text-xs font-black"><i class="fas fa-file-excel mr-1 text-emerald-600"></i>Excel</button><button data-pdf class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-file-pdf mr-1"></i>PDF ISO signé</button></div></div><div class="mt-4 grid gap-2 sm:grid-cols-3"><div class="rounded-xl bg-emerald-50 p-3"><small class="text-emerald-700">Fonds réellement reçus</small><b class="block text-emerald-900">${money(received)}</b></div><div class="rounded-xl bg-amber-50 p-3"><small class="text-amber-700">Transféré au disponible</small><b class="block text-amber-900">${money(transferred)}</b></div><div class="rounded-xl bg-slate-950 p-3 text-white"><small class="text-slate-300">Solde des entrées réelles</small><b class="block">${money(treasuryData.realEntriesBalance)}</b></div></div></div><div class="grid gap-4">${content||'<div class="py-12 text-center text-slate-500">Aucun mouvement sur cette période.</div>'}</div>`;
            document.querySelectorAll('[data-real-entry-modes] [data-mode]').forEach(button => button.onclick = () => renderRealEntries(dataset, button.dataset.mode));
            document.querySelector('[data-csv]').onclick = () => exportTreasuryCsv(dataset, rows);
            document.querySelector('[data-pdf]').onclick = () => {
                const q = new URLSearchParams({
                    view: 'real-in',
                    from: '<?= $from ?>',
                    to: '<?= $to ?>'
                });
                window.open('request/export_treasury_pdf.php?' + q.toString(), '_blank')
            };
        }

        function renderInvoiceRegister(dataset, group = 'status', filters = {
            client: '',
            chantier: '',
            type: '',
            state: ''
        }) {
            const labels = {
                    avance: 'Acompte',
                    situation: 'Avancement',
                    finale: 'Solde / finale',
                    complement: 'Complément'
                },
                client = r => r.beneficiary || 'Client non renseigné',
                site = r => [r.chantier_code, r.chantier_title].filter(Boolean).join(' · ') || 'Sans chantier lié',
                kind = r => labels[r.invoice_work_type] || 'Autre facture',
                all = dataset.rows || [];
            const clients = [...new Set(all.map(client))].sort(),
                sites = [...new Set(all.map(site))].sort(),
                types = [...new Set(all.map(kind))].sort(),
                rows = all.filter(r => (!filters.client || client(r).toLowerCase().includes(filters.client.toLowerCase())) && (!filters.chantier || site(r).toLowerCase().includes(filters.chantier.toLowerCase())) && (!filters.type || kind(r) === filters.type) && (!filters.state || r.forecast_state === filters.state)),
                pending = rows.filter(r => r.forecast_state === 'pending'),
                collected = rows.filter(r => r.forecast_state === 'collected');
            const getter = group === 'client' ? client : group === 'chantier' ? site : group === 'type' ? kind : r => r.forecast_state === 'collected' ? 'Factures encaissées' : 'Factures restant à percevoir',
                groups = new Map;
            rows.forEach(r => {
                const k = getter(r);
                if (!groups.has(k)) groups.set(k, []);
                groups.get(k).push(r)
            });
            const row = r => {
                    const paid = r.forecast_state === 'collected',
                        late = !paid && Number(r.is_overdue) > 0,
                        status = paid ? 'Sortie des prévisions' : late ? `Date dépassée · ${Number(r.overdue_days||0)} j` : 'À percevoir';
                    return `<article class="treasury-flow-row ${late?'border-rose-200':''}"><span class="treasury-flow-icon ${paid?'out':'in'}"><i class="fas ${paid?'fa-check':'fa-file-invoice-dollar'}"></i></span><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><strong>${esc(r.reference||'Facture')}</strong><span class="rounded-full px-2 py-1 text-[10px] font-black uppercase ${paid?'bg-slate-100 text-slate-600':late?'bg-rose-100 text-rose-700':'bg-emerald-50 text-emerald-700'}">${status}</span><span class="rounded-full bg-indigo-50 px-2 py-1 text-[10px] font-bold text-indigo-700">${esc(kind(r))}</span></div><small class="mt-1 block text-slate-500">${esc(client(r))} · ${esc(site(r))}</small><small class="block ${late?'font-bold text-rose-700':'text-slate-500'}">${paid?'Encaissée le':'Réception prévue le'} ${new Date((r.movement_date||'')+'T00:00:00').toLocaleDateString('fr-FR')}${r.avancement_percent!=null?' · '+Number(r.avancement_percent).toLocaleString('fr-FR')+' %':''}</small>${movementDetails(r,[['Client',client(r)],['Chantier',site(r)],['Type de facture',kind(r)],['Situation',status],['Avancement',r.avancement_percent!=null?Number(r.avancement_percent).toLocaleString('fr-FR')+' %':'']])}</div><strong class="treasury-flow-amount text-right ${paid?'text-slate-500':late?'text-rose-700':'text-emerald-700'}">${money(r.amount)}</strong></article>`
                },
                sections = [...groups].map(([label, items]) => `<section class="treasury-group"><header class="treasury-group-head"><div><strong>${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} facture${items.length>1?'s':''}</small></div><strong>${money(items.reduce((s,r)=>s+Number(r.amount||0),0))}</strong></header><div class="treasury-group-body">${items.map(row).join('')}</div></section>`).join('');
            document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap justify-between gap-3"><p class="max-w-2xl text-sm text-slate-500">Recherchez et regroupez les factures selon leur réalité commerciale.</p><div class="flex gap-2"><button data-csv class="rounded-xl border bg-white px-3 py-2 text-xs font-black">Excel</button><button data-pdf class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white">PDF ISO signé</button></div></div><div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-4"><input data-client list="invoice-clients" value="${esc(filters.client)}" placeholder="Rechercher un client…" class="rounded-xl border px-3 py-2 text-sm"><datalist id="invoice-clients">${clients.map(v=>`<option value="${esc(v)}">`).join('')}</datalist><input data-site list="invoice-sites" value="${esc(filters.chantier)}" placeholder="Rechercher un chantier…" class="rounded-xl border px-3 py-2 text-sm"><datalist id="invoice-sites">${sites.map(v=>`<option value="${esc(v)}">`).join('')}</datalist><select data-type class="rounded-xl border px-3 py-2 text-sm"><option value="">Tous les types de facture</option>${types.map(v=>`<option ${v===filters.type?'selected':''}>${esc(v)}</option>`).join('')}</select><select data-state class="rounded-xl border px-3 py-2 text-sm"><option value="">Tous les états</option><option value="pending" ${filters.state==='pending'?'selected':''}>À percevoir</option><option value="collected" ${filters.state==='collected'?'selected':''}>Déjà encaissées</option></select></div><div class="mt-3 flex flex-wrap gap-2">${[['status','Par situation'],['client','Par client'],['chantier','Par chantier'],['type','Par type de facture']].map(([v,l])=>`<button data-invoice-group="${v}" class="rounded-full px-3 py-2 text-xs font-black ${v===group?'bg-indigo-700 text-white':'bg-slate-100 text-slate-600'}">${l}</button>`).join('')}</div><div class="mt-3 grid grid-cols-2 gap-2"><div class="rounded-xl bg-slate-100 p-3"><small>Déjà perçu</small><b class="block">${money(collected.reduce((s,r)=>s+Number(r.amount||0),0))}</b></div><div class="rounded-xl bg-emerald-50 p-3"><small class="text-emerald-700">Reste à percevoir</small><b class="block text-emerald-800">${money(pending.reduce((s,r)=>s+Number(r.amount||0),0))}</b></div></div></div><div class="grid gap-4">${sections||'<div class="py-12 text-center text-slate-500">Aucune facture ne correspond à la recherche.</div>'}</div>`;
            document.querySelectorAll('[data-invoice-group]').forEach(b => b.onclick = () => renderInvoiceRegister(dataset, b.dataset.invoiceGroup, filters));
            let timer;
            const update = () => {
                clearTimeout(timer);
                timer = setTimeout(() => renderInvoiceRegister(dataset, group, {
                    client: document.querySelector('[data-client]').value.trim(),
                    chantier: document.querySelector('[data-site]').value.trim(),
                    type: document.querySelector('[data-type]').value,
                    state: document.querySelector('[data-state]').value
                }), 180)
            };
            document.querySelector('[data-client]').oninput = update;
            document.querySelector('[data-site]').oninput = update;
            document.querySelector('[data-type]').onchange = update;
            document.querySelector('[data-state]').onchange = update;
            document.querySelector('[data-csv]').onclick = () => exportTreasuryCsv(dataset, rows);
            document.querySelector('[data-pdf]').onclick = () => {
                const q = new URLSearchParams({
                    view: 'forecast-in',
                    from: '<?= $from ?>',
                    to: '<?= $to ?>',
                    state: filters.state || ''
                });
                window.open('request/export_treasury_pdf.php?' + q.toString(), '_blank')
            };
        }
        const debourseProjectLabel = row => [row.chantier_code, row.chantier_title].filter(Boolean).join(' · ') || 'Chantier non renseigné';

        function renderProjectedDebourseRegister(dataset, mode = 'chantier', filters = {
            chantier: ''
        }) {
            const all = dataset.rows || [],
                projects = [...new Set(all.map(debourseProjectLabel))].sort(),
                rows = all.filter(row => !filters.chantier || debourseProjectLabel(row) === filters.chantier),
                total = rows.reduce((sum, row) => sum + Number(row.amount || 0), 0),
                getter = mode === 'ligne' ? row => row.ligne_debourse_label || row.label || 'Ligne de déboursé' : mode === 'categorie' ? row => row.ligne_debourse_category || 'Sans catégorie' : debourseProjectLabel,
                groups = new Map;
            rows.forEach(row => {
                const key = getter(row);
                if (!groups.has(key)) groups.set(key, []);
                groups.get(key).push(row)
            });
            const line = row => `<details class="border-b px-4 py-4 last:border-0"><summary class="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3"><div><strong class="block text-slate-950">${esc(row.ligne_debourse_label||row.label||'Ligne de déboursé')}</strong><small class="mt-1 block text-slate-500">${esc([row.ligne_devis_label,row.ligne_debourse_category,row.reference].filter(Boolean).join(' · '))}</small></div><span class="flex items-center gap-2"><strong class="text-rose-700">Reste : ${money(row.amount)}</strong><i class="fas fa-chevron-down text-slate-400"></i></span></summary><div class="mt-3 grid gap-2 text-xs sm:grid-cols-3"><span class="rounded-lg bg-slate-100 px-3 py-2">Prévision initiale <b class="block text-slate-900">${money(row.budget_amount)}</b></span><span class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-800">Déjà décaissé <b class="block">${money(row.paid_amount)}</b></span><span class="rounded-lg bg-indigo-50 px-3 py-2 text-indigo-800">FEB/fiches engagées <b class="block">${money(row.committed_amount)}</b></span></div></details>`,
                sections = [...groups].map(([label, items]) => {
                    const budget = items.reduce((sum, row) => sum + Number(row.budget_amount || 0), 0),
                        paid = items.reduce((sum, row) => sum + Number(row.paid_amount || 0), 0),
                        committed = items.reduce((sum, row) => sum + Number(row.committed_amount || 0), 0),
                        remaining = items.reduce((sum, row) => sum + Number(row.amount || 0), 0);
                    return `<details class="treasury-group" open><summary class="treasury-group-head cursor-pointer list-none"><div><strong>${esc(label)}</strong><small class="ml-2 text-slate-400">${items.length} ligne${items.length>1?'s':''} · cliquez pour afficher les déboursés</small><div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-400"><span>Déboursé global : <b class="font-semibold text-slate-500">${money(budget)}</b></span><span>Déjà payé : <b class="font-semibold text-emerald-700/70">${money(paid)}</b></span><span>Engagé : <b class="font-semibold text-indigo-700/70">${money(committed)}</b></span></div></div><div class="flex items-center gap-3"><div class="rounded-xl bg-rose-50 px-4 py-3 text-right shadow-sm"><small class="block text-[10px] font-black uppercase tracking-wide text-rose-700">Reste à payer</small><strong class="mt-1 block text-xl text-rose-800">${money(remaining)}</strong></div><i class="fas fa-chevron-down text-slate-400"></i></div></summary><div class="treasury-group-body">${items.map(line).join('')}</div></details>`
                }).join('');
            document.getElementById('detailContent').innerHTML = `<div class="sticky top-0 z-10 -mx-5 -mt-5 mb-5 border-b bg-white/95 p-5 backdrop-blur"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="max-w-3xl text-sm text-slate-500">Chaque chantier est un accordéon ; chaque déboursé peut ensuite être ouvert pour voir ses lignes financières. Une FEB soumise, même reportée, est engagée ; une FEB ou une fiche refusée ne réduit pas le reste à payer.</p><div data-debourse-modes class="mt-3 flex flex-wrap gap-2">${[['chantier','Par chantier'],['ligne','Par ligne de déboursé'],['categorie','Par catégorie']].map(([value,label])=>`<button data-mode="${value}" class="rounded-full px-3 py-2 text-xs font-black ${value===mode?'bg-indigo-700 text-white':'bg-slate-100 text-slate-600'}">${label}</button>`).join('')}</div></div><div class="flex gap-2"><button data-excel class="rounded-xl border bg-white px-3 py-2 text-xs font-black"><i class="fas fa-file-excel mr-1 text-emerald-600"></i>Excel</button><button data-pdf class="rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-file-pdf mr-1"></i>PDF</button></div></div><div class="mt-4 grid gap-3 md:grid-cols-[minmax(0,1fr)_220px]"><label class="text-xs font-bold text-slate-500">Filtrer un chantier<select data-debourse-chantier class="mt-1 w-full rounded-xl border px-3 py-2"><option value="">Tous les chantiers actifs</option>${projects.map(value=>`<option value="${esc(value)}" ${value===filters.chantier?'selected':''}>${esc(value)}</option>`).join('')}</select></label><div class="rounded-xl bg-rose-50 p-3 text-right"><small class="text-rose-700">Reste global à payer</small><b class="block text-lg text-rose-900">${money(total)}</b></div></div></div><div class="grid gap-4">${sections||'<div class="rounded-2xl bg-white py-14 text-center text-slate-500">Aucun reste à payer pour ces chantiers.</div>'}</div>`;
            document.querySelectorAll('[data-debourse-modes] [data-mode]').forEach(button => button.onclick = () => renderProjectedDebourseRegister(dataset, button.dataset.mode, filters));
            document.querySelector('[data-debourse-chantier]').onchange = event => renderProjectedDebourseRegister(dataset, mode, {
                chantier: event.target.value
            });
            const exportParams = () => new URLSearchParams({
                view: 'projected-out',
                from: '<?= $from ?>',
                to: '<?= $to ?>',
                group: mode,
                chantiers: JSON.stringify(filters.chantier ? [filters.chantier] : [])
            });
            document.querySelector('[data-excel]').onclick = () => window.open('request/export_treasury_debourse_excel.php?' + exportParams(), '_blank');
            document.querySelector('[data-pdf]').onclick = () => window.open('request/export_treasury_pdf.php?' + exportParams(), '_blank')
        }
        document.addEventListener('click', event => {
            const button = event.target.closest('[data-detail]');
            if (!button) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (button.dataset.detail === 'vat-collected') {
                const rows = <?= json_encode($vat['snapshots'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
                document.getElementById('detailTitle').textContent = 'TVA collectée par facture encaissée';
                const totalVat = rows.reduce((s,r)=>s+Number(r.amount_vat||0),0);
                const totalHt = rows.reduce((s,r)=>s+Number(r.amount_ht||0),0);
                const totalTtc = rows.reduce((s,r)=>s+Number(r.amount_ttc||0),0);
                document.getElementById('detailContent').innerHTML = '<div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-white p-4 shadow-sm"><div><small class="font-black uppercase tracking-wide text-slate-400">Récapitulatif TVA</small><p class="mt-1 text-sm text-slate-600">Période du <?= date('d/m/Y', strtotime($from)) ?> au <?= date('d/m/Y', strtotime($to)) ?></p></div><div class="flex gap-2"><a target="_blank" href="request/export_treasury_vat_excel.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white hover:bg-emerald-800"><i class="fas fa-file-excel mr-2"></i>Excel</a><a target="_blank" href="request/export_treasury_vat_pdf.php?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>" class="rounded-xl bg-rose-700 px-4 py-3 text-sm font-black text-white hover:bg-rose-800"><i class="fas fa-file-pdf mr-2"></i>PDF</a></div></div><div class="grid gap-3 md:grid-cols-3 mb-5"><div class="rounded-2xl bg-slate-100 p-4"><small>HT encaissé</small><b class="block text-xl">'+money(totalHt)+'</b></div><div class="rounded-2xl bg-cyan-50 p-4"><small class="text-cyan-700">TVA collectée</small><b class="block text-xl text-cyan-900">'+money(totalVat)+'</b></div><div class="rounded-2xl bg-slate-900 p-4 text-white"><small>TTC encaissé</small><b class="block text-xl">'+money(totalTtc)+'</b></div></div><div class="grid gap-3">'+(rows.map(r=>'<article class="rounded-2xl border bg-white p-4 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-3"><div><strong class="text-slate-950">'+esc(r.reference_interne||('Facture #'+r.invoice_id))+'</strong><small class="block text-slate-500">'+esc(r.client_company_name||'Client')+' · '+esc([r.chantier_code,r.chantier_title].filter(Boolean).join(' · ')||'Sans chantier')+'</small><small class="block text-slate-400">Encaissée le '+new Date(r.snapshot_date+'T00:00:00').toLocaleDateString('fr-FR')+'</small></div><div class="grid grid-cols-3 gap-2 text-right"><span class="rounded-xl bg-slate-50 px-3 py-2"><small class="block text-slate-400">HT</small><b>'+money(r.amount_ht)+'</b></span><span class="rounded-xl bg-cyan-50 px-3 py-2"><small class="block text-cyan-600">TVA</small><b class="text-cyan-800">'+money(r.amount_vat)+'</b></span><span class="rounded-xl bg-slate-900 px-3 py-2 text-white"><small class="block text-slate-300">TTC</small><b>'+money(r.amount_ttc)+'</b></span></div></div></article>').join('')||'<div class="py-10 text-center text-slate-500">Aucune TVA encaissée.</div>')+'</div>';
                open('detailModal');
                return;
            }
            const dataset = detailDataset(button.dataset.detail);
            dataset.view = button.dataset.detail;
            if (dataset.view === 'forecast-out') dataset.rows.push(...(treasuryData.recurringForecastRows || []).map(r => ({
                ...r,
                direction: 'out'
            })));
            if (dataset.view === 'real-in') dataset.rows.forEach(r => {
                if (r.transfer_type === 'to_real_entries') r.direction = 'in'
            });
            if (dataset.view === 'available') dataset.rows.forEach(r => {
                if (r.transfer_type === 'to_real_entries') r.direction = 'out'
            });
            document.getElementById('detailTitle').textContent = dataset.view === 'real-in' ? 'Registre des entrées réelles' : dataset.title;
            if (dataset.view === 'forecast-in') renderInvoiceRegister(dataset);
            else if (dataset.view === 'real-in') renderRealEntries(dataset);
            else if (dataset.view === 'projected-out') renderProjectedDebourseRegister(dataset);
            else renderMulti(dataset);
            if (['real-in', 'forecast-in'].includes(dataset.view)) {
                const type = dataset.view === 'real-in' ? 'real_entries' : 'forecast_invoice',
                    reservations = (treasuryData.fundingReservations || []).filter(r => r.source_type === type),
                    total = reservations.reduce((sum, row) => sum + Number(row.remaining_reserved || 0), 0),
                    rows = reservations.map(row => {
                        const fiches = Array.isArray(row.linked_fiches) ? row.linked_fiches : [],
                            source = row.source_type === 'forecast_invoice' ? `Facture prévisionnelle · ${row.invoice_reference||('Facture #'+row.invoice_id)}` : 'Entrées réelles',
                            ficheButtons = fiches.length ? fiches.map(num => `<a target="_blank" rel="noopener" href="../decaissement/voir_fiche_details.php?num_fiche=${encodeURIComponent(num)}" class="inline-flex items-center gap-1 rounded-lg bg-indigo-700 px-2.5 py-1.5 text-[11px] font-black text-white hover:bg-indigo-800"><i class="fas fa-eye"></i> Visualiser ${esc(num)}</a>`).join(' ') : '<span class="text-xs text-slate-400">Fiche en cours de génération</span>';
                        return `<article class="grid gap-3 border-b px-4 py-4 text-sm md:grid-cols-[minmax(0,1fr)_125px_125px_145px]"><div class="min-w-0"><strong>${esc(row.reference||('FEB #'+row.feb_request_id))}</strong><small class="block text-slate-500">${esc(row.title||'FEB')}${row.invoice_id?' · '+esc(row.invoice_reference||('Facture #'+row.invoice_id))+' · '+esc(row.invoice_client||''):''}</small><small class="block text-slate-400">BCI prévu le ${new Date(row.scheduled_date+'T00:00:00').toLocaleDateString('fr-FR')}</small><details class="mt-2 text-xs"><summary class="cursor-pointer font-bold text-indigo-700">Détail et affectation</summary><div class="mt-1 rounded-lg bg-slate-50 p-2 text-slate-600"><b>Source :</b> ${esc(source)}<br><b>FEB :</b> ${esc(row.reference||('FEB #'+row.feb_request_id))}<br><b>Fiches liées :</b> ${fiches.length?esc(fiches.join(' · ')): 'En cours de création'}</div></details><div class="mt-3 flex flex-wrap gap-2">${ficheButtons}</div></div><span><small class="block text-slate-400">Réserve initiale</small><b>${money(row.reserved_amount)}</b></span><span><small class="block text-slate-400">Déjà décaissé</small><b>${money(row.paid_amount)}</b></span><span class="md:text-right"><small class="block text-slate-400">Encore réservé</small><b class="text-amber-700">${money(row.remaining_reserved)}</b></span></article>`;
                    }).join(''),
                    html = `<section class="treasury-group mt-4"><header class="treasury-group-head"><div><strong>Réservations affectées aux FEB</strong><small class="ml-2 text-slate-400">Information uniquement · aucun débit du solde</small></div><strong class="text-amber-700">${money(total)}</strong></header><div class="treasury-group-body">${rows||'<div class="px-4 py-8 text-center text-sm text-slate-500">Aucune réservation active sur cette source.</div>'}</div></section>`;
                document.getElementById('detailContent')?.insertAdjacentHTML('beforeend', html);
            }
            open('detailModal')
        }, true);
    })();
</script>

<?php if ($canManageTreasury): ?><div id="vatRemitModal" class="treasury-modal hidden fixed inset-0 z-[80] items-center justify-center overflow-y-auto bg-slate-950/70 p-3 backdrop-blur-sm sm:p-4">
    <div class="relative my-auto flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-[26px] border border-emerald-200 bg-white shadow-2xl sm:max-h-[calc(100dvh-2rem)]">
        <div class="billing-banner p-6 text-white">
            <div class="flex items-start justify-between gap-4"><div><span class="rounded-full bg-emerald-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[.18em] text-emerald-200">Reversement fiscal</span><h3 class="mt-3 text-2xl font-black">Reverser la TVA collectée</h3><p class="mt-2 text-sm text-slate-300">Le reversement sort réellement les fonds de la trésorerie et diminue la dette fiscale TVA.</p></div><button type="button" data-close="vatRemitModal" onclick="document.getElementById('vatRemitModal').classList.add('hidden');document.getElementById('vatRemitModal').classList.remove('grid');return false;" aria-label="Fermer" class="relative z-10 grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-xl bg-white/10 transition hover:bg-white/20"><i class="fas fa-xmark pointer-events-none"></i></button></div>
            <div class="mt-5 rounded-2xl border border-emerald-300/20 bg-emerald-300/10 p-4"><small class="font-bold uppercase text-emerald-200">TVA actuellement reversable</small><strong class="mt-1 block text-3xl"><?= $money($vat['vat_protected']) ?></strong></div>
        </div>
        <form method="post" class="grid min-h-0 gap-4 overflow-y-auto p-5 md:grid-cols-2 md:p-6">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="remit_vat">
            <label class="text-xs font-black uppercase text-slate-500">Montant à reverser<input type="number" name="amount" data-vat-amount="remit" data-vat-max="<?= (float)$vat['vat_protected'] ?>" min="1" max="<?= (int)$vat['vat_protected'] ?>" step="1" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-base font-black"><span data-vat-error="remit" class="mt-2 hidden text-xs font-bold normal-case text-rose-600"></span><span class="mt-2 block text-xs font-bold normal-case text-emerald-700">Restera dans la réserve : <b data-vat-remaining="remit"><?= $money($vat['vat_protected']) ?></b></span></label>
            <label class="text-xs font-black uppercase text-slate-500">Date de paiement<input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3"></label>
            <label class="text-xs font-black uppercase text-slate-500">Période fiscale du<input type="date" name="period_start" value="<?= date('Y-m-01') ?>" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3"></label>
            <label class="text-xs font-black uppercase text-slate-500">Au<input type="date" name="period_end" value="<?= date('Y-m-t') ?>" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3"></label>
            <label class="text-xs font-black uppercase text-slate-500">Référence de paiement<input name="reference" placeholder="Référence / quittance / ordre de virement" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 normal-case"></label>
            <label class="text-xs font-black uppercase text-slate-500">Note<input name="note" placeholder="Observation facultative" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 normal-case"></label>
            <div class="md:col-span-2 flex justify-end gap-2"><button type="button" data-close="vatRemitModal" onclick="document.getElementById('vatRemitModal').classList.add('hidden');document.getElementById('vatRemitModal').classList.remove('grid');return false;" class="rounded-xl border px-4 py-3 text-sm font-black text-slate-600">Annuler</button><button data-vat-submit="remit" class="rounded-xl bg-emerald-700 px-5 py-3 text-sm font-black text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50"><i class="fas fa-building-columns mr-2"></i>Confirmer le reversement</button></div>
        </form>
    </div>
</div><?php endif; ?>

<?php if ($canManageTreasury): ?><div id="vatBorrowModal" class="treasury-modal hidden fixed inset-0 z-[80] items-center justify-center overflow-y-auto bg-slate-950/70 p-3 backdrop-blur-sm sm:p-4">
    <div class="relative my-auto flex max-h-[calc(100dvh-1.5rem)] w-full max-w-2xl flex-col overflow-hidden rounded-[26px] border border-cyan-200 bg-white shadow-2xl sm:max-h-[calc(100dvh-2rem)]">
        <div class="billing-banner p-6 text-white">
            <div class="flex items-start justify-between gap-4"><div><span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-black uppercase tracking-[.18em] text-cyan-200">Mobilisation temporaire</span><h3 class="mt-3 text-2xl font-black">Emprunter sur la réserve TVA</h3><p class="mt-2 text-sm text-slate-300">Le montant devient disponible immédiatement mais reste une dette interne envers la réserve fiscale.</p></div><button type="button" data-close="vatBorrowModal" onclick="document.getElementById('vatBorrowModal').classList.add('hidden');document.getElementById('vatBorrowModal').classList.remove('grid');return false;" aria-label="Fermer" class="relative z-10 grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-xl bg-white/10 transition hover:bg-white/20"><i class="fas fa-xmark pointer-events-none"></i></button></div>
            <div class="mt-5 rounded-2xl border border-cyan-300/20 bg-cyan-300/10 p-4"><small class="font-bold uppercase text-cyan-200">Maximum mobilisable aujourd’hui</small><strong class="mt-1 block text-3xl"><?= $money($vat['vat_free_to_borrow']) ?></strong></div>
        </div>
        <form method="post" class="grid gap-4 p-6 md:grid-cols-2">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string)$_SESSION['manual_treasury_csrf']) ?>"><input type="hidden" name="action" value="borrow_vat">
            <label class="text-xs font-black uppercase text-slate-500">Montant<input type="number" name="amount" data-vat-amount="borrow" data-vat-max="<?= (float)$vat['vat_free_to_borrow'] ?>" min="1" max="<?= (int)$vat['vat_free_to_borrow'] ?>" step="1" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 text-base font-black"><span data-vat-error="borrow" class="mt-2 hidden text-xs font-bold normal-case text-rose-600"></span><span class="mt-2 block text-xs font-bold normal-case text-cyan-700">Restera mobilisable : <b data-vat-remaining="borrow"><?= $money($vat['vat_free_to_borrow']) ?></b></span></label>
            <label class="text-xs font-black uppercase text-slate-500">Date d’emprunt<input type="date" name="borrow_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3"></label>
            <label class="text-xs font-black uppercase text-slate-500">Date de restitution<input type="date" name="due_date" min="<?= date('Y-m-d') ?>" required class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3"></label>
            <label class="text-xs font-black uppercase text-slate-500 md:col-span-2">Motif<textarea name="reason" required rows="2" placeholder="Ex. Financement temporaire d’une FEB urgente…" class="mt-2 w-full rounded-2xl border-slate-200 px-4 py-3 normal-case"></textarea></label>
            <div class="md:col-span-2 flex justify-end gap-2"><button type="button" data-close="vatBorrowModal" onclick="document.getElementById('vatBorrowModal').classList.add('hidden');document.getElementById('vatBorrowModal').classList.remove('grid');return false;" class="rounded-xl border px-4 py-3 text-sm font-black text-slate-600">Annuler</button><button data-vat-submit="borrow" class="rounded-xl bg-cyan-600 px-5 py-3 text-sm font-black text-white hover:bg-cyan-700 disabled:cursor-not-allowed disabled:opacity-50">Confirmer la mobilisation TVA</button></div>
        </form>
    </div>
</div><?php endif; ?>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>