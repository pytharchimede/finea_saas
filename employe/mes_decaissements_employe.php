<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();

function employeeLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return (string) mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function format_fcfa(float $value): string
{
    if (!is_finite($value) || $value <= 0) {
        return '0 FCFA';
    }
    return number_format($value, 0, ',', ' ') . ' FCFA';
}

function parseDateYmd(?string $raw): string
{
    $raw = trim((string) $raw);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '';
}

function decaissementStatus(array $row, string $todayYmd): array
{
    $approuve = (int) ($row['approuve'] ?? 0);
    $etat = (int) ($row['etat_fiche'] ?? 0);
    $decaisse = (int) ($row['decaisse'] ?? 0);
    $otp = (int) ($row['otp_valide'] ?? 0);
    $conforme = (int) ($row['conforme'] ?? 0);

    $dateMin = trim((string) ($row['date_decaissement_minimum'] ?? ''));
    $dateReport = '';
    if ($dateMin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $dateMin)) {
        $dateReport = substr($dateMin, 0, 10);
    }

    // Priorité des statuts (comme dans les écrans compta)
    if ($decaisse === 1) {
        return ['key' => 'decaissee', 'label' => 'Décaissée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'date_report' => ''];
    }

    if ($dateReport !== '' && $dateReport > $todayYmd) {
        return ['key' => 'reportee', 'label' => 'Reportée', 'class' => 'bg-amber-100 text-amber-800 border-amber-200', 'date_report' => $dateReport];
    }

    if ($approuve === 2 || $etat === 2) {
        return ['key' => 'refusee', 'label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200', 'date_report' => ''];
    }

    if ($otp === 1) {
        return ['key' => 'otp', 'label' => 'OTP validé', 'class' => 'bg-sky-100 text-sky-800 border-sky-200', 'date_report' => ''];
    }

    if ($approuve === 1) {
        return ['key' => 'acceptee', 'label' => 'Acceptée', 'class' => 'bg-indigo-100 text-indigo-800 border-indigo-200', 'date_report' => ''];
    }

    if ($conforme === 1) {
        return ['key' => 'conforme', 'label' => 'Conforme', 'class' => 'bg-blue-100 text-blue-800 border-blue-200', 'date_report' => ''];
    }

    return ['key' => 'attente', 'label' => 'En attente', 'class' => 'bg-slate-100 text-slate-700 border-slate-200', 'date_report' => ''];
}

$todayYmd = date('Y-m-d');

$connectedName = employeeCurrentUserName();
$connectedNameLower = employeeLower($connectedName);
$connectedUserId = (int) ($_SESSION['user_id'] ?? 0);

$filterQuery = trim((string) ($_GET['q'] ?? ''));
$filterStatusRaw = strtolower(trim((string) ($_GET['status'] ?? '')));
$filterDateFrom = parseDateYmd($_GET['date_from'] ?? null);
$filterDateTo = parseDateYmd($_GET['date_to'] ?? null);

$allowedStatuses = ['attente', 'acceptee', 'refusee', 'reportee', 'decaissee', 'conforme', 'otp'];
$filterStatus = in_array($filterStatusRaw, $allowedStatuses, true) ? $filterStatusRaw : '';

$statusLabels = [
    'attente' => 'En attente',
    'acceptee' => 'Acceptée',
    'refusee' => 'Refusée',
    'reportee' => 'Reportée',
    'decaissee' => 'Décaissée',
    'conforme' => 'Conforme',
    'otp' => 'OTP validé',
];

$statusSearchDisplay = $filterStatus !== '' ? ($filterStatus . ' — ' . ($statusLabels[$filterStatus] ?? $filterStatus)) : '';

// Requête (on filtre surtout SQL: bénéficiaire + dates + texte) et on finalise statut en PHP
$where = [];
$params = [];

// Sécurité/portée: on limite STRICTEMENT aux fiches du bénéficiaire connecté.
// On utilise une logique "tokens" pour être robuste (ordre des noms, espaces multiples, etc.).
$tokens = preg_split('/\s+/u', $connectedNameLower) ?: [];
$tokens = array_values(array_filter(array_unique(array_map('trim', $tokens)), static function (string $t): bool {
    return $t !== '' && (function_exists('mb_strlen') ? mb_strlen($t, 'UTF-8') : strlen($t)) >= 3;
}));

$beneficiaryScope = [];
if (!$tokens) {
    $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE :benef_all';
    $params['benef_all'] = '%' . $connectedNameLower . '%';
} else {
    foreach ($tokens as $idx => $token) {
        $key = ':benef_t' . $idx;
        $beneficiaryScope[] = 'LOWER(TRIM(f.beficiaire_fiche)) LIKE ' . $key;
        $params[ltrim($key, ':')] = '%' . $token . '%';
    }
}
$scopeSql = '(' . implode(' AND ', $beneficiaryScope) . ')';
if ($connectedUserId > 0) {
    // Le demandeur conserve la visibilité sur les fiches de ses FEB même lorsque
    // le fournisseur a été désigné comme bénéficiaire final.
    $scopeSql = '(' . $scopeSql . ' OR EXISTS (
        SELECT 1 FROM employee_legal_requests elr
        WHERE elr.id = f.feb_request_id
          AND elr.requester_user_id = :connected_user_id
          AND elr.request_type = "feb"
    ))';
    $params['connected_user_id'] = $connectedUserId;
}
$where[] = $scopeSql;

if ($filterDateFrom !== '') {
    $where[] = 'f.date_creat_fiche >= :date_from';
    $params['date_from'] = $filterDateFrom . ' 00:00:00';
}
if ($filterDateTo !== '') {
    $where[] = 'f.date_creat_fiche <= :date_to';
    $params['date_to'] = $filterDateTo . ' 23:59:59';
}

if ($filterQuery !== '') {
    $where[] = '(
        f.num_fiche LIKE :q
        OR f.beficiaire_fiche LIKE :q
        OR f.designation_fiche LIKE :q
        OR f.precision_fiche LIKE :q
        OR f.details_demande LIKE :q
        OR f.mode_paiement LIKE :q
        OR f.num_piece LIKE :q
        OR f.entreprise LIKE :q
        OR f.code_autorisation_feb LIKE :q
        OR c.titre LIKE :q
        OR c.code LIKE :q
    )';
    $params['q'] = '%' . $filterQuery . '%';
}

$sql = '
    SELECT
        f.num_fiche,
        f.beficiaire_fiche,
        f.montant_fiche,
        f.tel_beneficiaire_fiche,
        f.date_creat_fiche,
        f.designation_fiche,
        f.precision_fiche,
        f.details_demande,
        f.mode_paiement,
        f.num_piece,
        f.entreprise,
        f.code_autorisation_feb,
        f.approuve,
        f.conforme,
        f.decaisse,
        f.otp_valide,
        f.etat_fiche,
        f.date_approbation,
        f.date_decaissement,
        f.date_decaissement_minimum,
        c.code AS chantier_code,
        c.titre AS chantier_titre
    FROM fiche_decaissement f
    LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
    WHERE ' . implode(' AND ', $where) . '
    ORDER BY f.date_creat_fiche DESC
    LIMIT 300
';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Filtre statut + enrichissement
$filtered = [];
foreach ($rows as $r) {
    $status = decaissementStatus($r, $todayYmd);
    $r['_status'] = $status;
    if ($filterStatus !== '' && ($status['key'] ?? '') !== $filterStatus) {
        continue;
    }
    $filtered[] = $r;
}
$rows = $filtered;

// Indicateurs (sur les résultats affichés)
$metrics = [
    'count' => 0,
    'total' => 0.0,
    'acceptee_count' => 0,
    'acceptee_total' => 0.0,
    'refusee_count' => 0,
    'refusee_total' => 0.0,
    'reportee_count' => 0,
    'reportee_total' => 0.0,
    'decaissee_count' => 0,
    'decaissee_total' => 0.0,
];

foreach ($rows as $r) {
    $amount = (float) ($r['montant_fiche'] ?? 0);
    $metrics['count']++;
    $metrics['total'] += max(0.0, $amount);

    $k = (string) (($r['_status']['key'] ?? '') ?: '');
    if ($k === 'acceptee') {
        $metrics['acceptee_count']++;
        $metrics['acceptee_total'] += max(0.0, $amount);
    } elseif ($k === 'refusee') {
        $metrics['refusee_count']++;
        $metrics['refusee_total'] += max(0.0, $amount);
    } elseif ($k === 'reportee') {
        $metrics['reportee_count']++;
        $metrics['reportee_total'] += max(0.0, $amount);
    } elseif ($k === 'decaissee') {
        $metrics['decaissee_count']++;
        $metrics['decaissee_total'] += max(0.0, $amount);
    }
}

$pageTitle = 'Mes décaissements';
$layoutRootBase = '../';
$activeEmployeeMenu = 'decaissement';

$exportQuery = http_build_query([
    'q' => $filterQuery,
    'status' => $filterStatus,
    'date_from' => $filterDateFrom,
    'date_to' => $filterDateTo,
]);

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-600 via-slate-900 to-emerald-500"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Décaissement</p>
                    <h1 class="mt-2 text-2xl font-semibold text-slate-950">Récap financier</h1>
                    <p class="mt-1 text-sm text-slate-600">Consultez vos fiches (acceptées, refusées, reportées) et exportez en PDF (modèle comptabilité).</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_mes_decaissements_employe_pdf.php<?= $exportQuery ? ('?' . htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8')) : '' ?>" target="_blank" rel="noopener" class="rounded-2xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                        <i class="fas fa-file-pdf mr-2"></i>
                        Export PDF
                    </a>
                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>request/export_mes_decaissements_employe_excel.php<?= $exportQuery ? ('?' . htmlspecialchars($exportQuery, ENT_QUOTES, 'UTF-8')) : '' ?>" class="rounded-2xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        <i class="fas fa-file-excel mr-2"></i>
                        Export Excel
                    </a>
                    <a href="<?= htmlspecialchars($layoutRootBase, ENT_QUOTES, 'UTF-8') ?>decaissement/formulaire_demande_decaissement.php" class="rounded-2xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-plus mr-2"></i>
                        Nouvelle demande
                    </a>
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-2xl border border-slate-200 bg-white px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Total</div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-slate-700 to-slate-950 text-white shadow-sm">
                            <i class="fas fa-coins" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa($metrics['total'])) ?></div>
                    <div class="mt-1 text-xs text-slate-500"><?= (int) $metrics['count'] ?> fiche(s)</div>
                </div>
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-emerald-700">Décaissées</div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-emerald-600 to-slate-950 text-white shadow-sm">
                            <i class="fas fa-money-bill-wave" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa($metrics['decaissee_total'])) ?></div>
                    <div class="mt-1 text-xs text-slate-600"><?= (int) $metrics['decaissee_count'] ?> fiche(s)</div>
                </div>
                <div class="rounded-2xl border border-indigo-200 bg-indigo-50/60 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-700">Acceptées</div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-slate-950 text-white shadow-sm">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa($metrics['acceptee_total'])) ?></div>
                    <div class="mt-1 text-xs text-slate-600"><?= (int) $metrics['acceptee_count'] ?> fiche(s)</div>
                </div>
                <div class="rounded-2xl border border-rose-200 bg-rose-50/60 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-rose-700">Refusées</div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-rose-600 to-slate-950 text-white shadow-sm">
                            <i class="fas fa-circle-xmark" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa($metrics['refusee_total'])) ?></div>
                    <div class="mt-1 text-xs text-slate-600"><?= (int) $metrics['refusee_count'] ?> fiche(s)</div>
                </div>
                <div class="rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-amber-700">Reportées</div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-2xl bg-gradient-to-br from-amber-500 to-slate-950 text-white shadow-sm">
                            <i class="fas fa-clock" aria-hidden="true"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900"><?= htmlspecialchars(format_fcfa($metrics['reportee_total'])) ?></div>
                    <div class="mt-1 text-xs text-slate-600"><?= (int) $metrics['reportee_count'] ?> fiche(s)</div>
                </div>
            </div>

            <form method="get" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-4">
                <div class="grid grid-cols-1 gap-3 md:grid-cols-12">
                    <div class="md:col-span-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Recherche</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-magnifying-glass text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="search" name="q" value="<?= htmlspecialchars($filterQuery) ?>" placeholder="N° fiche, chantier, désignation, précision…">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Bénéficiaire (connecté)</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-user text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="text" value="<?= htmlspecialchars($connectedName) ?>" readonly>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Statut</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-flag text-slate-400"></i>
                            <input id="statusSearch" class="w-full bg-transparent text-sm text-slate-900 outline-none" type="text" list="statusOptions" value="<?= htmlspecialchars($statusSearchDisplay) ?>" placeholder="Tous">
                            <input type="hidden" name="status" id="statusValue" value="<?= htmlspecialchars($filterStatus) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Du</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-calendar text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-3">
                        <div class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Au</div>
                        <div class="mt-2 flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-3 py-2">
                            <i class="fas fa-calendar-check text-slate-400"></i>
                            <input class="w-full bg-transparent text-sm text-slate-900 outline-none" type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                        </div>
                    </div>

                    <div class="md:col-span-12 flex flex-wrap items-center gap-2 md:justify-end">
                        <button class="inline-flex items-center rounded-2xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-800" type="submit">
                            <i class="fas fa-filter mr-2 text-xs"></i>
                            Filtrer
                        </button>
                        <a class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-50" href="mes_decaissements_employe.php">
                            <i class="fas fa-rotate-left mr-2 text-xs text-slate-500"></i>
                            Réinitialiser
                        </a>
                    </div>
                </div>

                <datalist id="statusOptions">
                    <option value="attente — En attente"></option>
                    <option value="acceptee — Acceptée"></option>
                    <option value="refusee — Refusée"></option>
                    <option value="reportee — Reportée"></option>
                    <option value="decaissee — Décaissée"></option>
                    <option value="conforme — Conforme"></option>
                    <option value="otp — OTP validé"></option>
                </datalist>

                <?php if ($filterQuery || $filterStatus || $filterDateFrom || $filterDateTo): ?>
                    <div class="mt-3 text-xs text-slate-500">
                        Filtres actifs:
                        <?php if ($filterQuery): ?><span class="font-semibold">q=<?= htmlspecialchars($filterQuery) ?></span><?php endif; ?>
                        <?php if ($filterStatus): ?><span class="font-semibold">statut=<?= htmlspecialchars($statusLabels[$filterStatus] ?? $filterStatus) ?></span><?php endif; ?>
                        <?php if ($filterDateFrom): ?><span class="font-semibold">du=<?= htmlspecialchars($filterDateFrom) ?></span><?php endif; ?>
                        <?php if ($filterDateTo): ?><span class="font-semibold">au=<?= htmlspecialchars($filterDateTo) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </form>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-500">
                            <th class="px-3 py-3 font-medium">N° fiche</th>
                            <th class="px-3 py-3 font-medium">Bénéficiaire</th>
                            <th class="px-3 py-3 font-medium">Chantier / Désignation</th>
                            <th class="px-3 py-3 font-medium">Montant</th>
                            <th class="px-3 py-3 font-medium">Statut</th>
                            <th class="px-3 py-3 font-medium">Date</th>
                            <th class="px-3 py-3 font-medium">Date report</th>
                            <th class="px-3 py-3 font-medium"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                            <tr>
                                <td class="px-3 py-4 text-slate-600" colspan="8">Aucune fiche trouvée.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $status = (array) ($row['_status'] ?? []);
                                $dateReport = (string) ($status['date_report'] ?? '');
                                $numFiche = (string) ($row['num_fiche'] ?? '');
                                $amount = (float) ($row['montant_fiche'] ?? 0);
                                $viewUrl = 'details_decaissement_employe.php?num_fiche=' . rawurlencode($numFiche);
                                $pdfUrl = $layoutRootBase . 'request/export_fiche_decaissement_pdf_employe.php?num_fiche=' . rawurlencode($numFiche);
                                ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-4 font-semibold text-slate-800"><?= htmlspecialchars($numFiche) ?></td>
                                    <td class="px-3 py-4 text-slate-700">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) ($row['beficiaire_fiche'] ?? '')) ?></div>
                                        <?php if (!empty($row['tel_beneficiaire_fiche'])): ?>
                                            <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars((string) $row['tel_beneficiaire_fiche']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-slate-700">
                                        <div class="font-semibold text-slate-900">
                                            <?= htmlspecialchars((string) (($row['chantier_code'] ?? '') ?: '—')) ?>
                                            <?php if (!empty($row['chantier_titre'])): ?>
                                                <span class="font-normal text-slate-600">— <?= htmlspecialchars((string) $row['chantier_titre']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mt-1 text-xs text-slate-600">
                                            <span class="font-semibold">Désignation :</span>
                                            <?= htmlspecialchars((string) ($row['designation_fiche'] ?? '—')) ?>
                                        </div>
                                        <?php if (!empty($row['precision_fiche'])): ?>
                                            <div class="mt-1 text-xs text-slate-600"><span class="font-semibold">Précision :</span> <?= htmlspecialchars((string) $row['precision_fiche']) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['details_demande'])): ?>
                                            <div class="mt-1 text-xs text-slate-500"><span class="font-semibold">Détails :</span> <?= htmlspecialchars((string) $row['details_demande']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-3 py-4 text-slate-700"><?= htmlspecialchars(format_fcfa($amount)) ?></td>
                                    <td class="px-3 py-4">
                                        <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold <?= htmlspecialchars((string) ($status['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200')) ?>">
                                            <?= htmlspecialchars((string) ($status['label'] ?? '—')) ?>
                                        </span>
                                    </td>
                                    <td class="px-3 py-4 text-slate-600"><?= htmlspecialchars(employeeFormatDateFr((string) ($row['date_creat_fiche'] ?? ''))) ?></td>
                                    <td class="px-3 py-4 text-slate-600"><?= $dateReport ? htmlspecialchars(employeeFormatDateFr($dateReport)) : '—' ?></td>
                                    <td class="px-3 py-4 text-right">
                                        <a class="mr-3 text-sm font-semibold text-slate-700 hover:text-slate-900" href="<?= htmlspecialchars($viewUrl) ?>">Voir</a>
                                        <a class="text-sm font-semibold text-indigo-700 hover:text-indigo-800" href="<?= htmlspecialchars($pdfUrl) ?>" target="_blank" rel="noopener">PDF</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    (function() {
        const statusSearch = document.getElementById('statusSearch');
        const statusValue = document.getElementById('statusValue');

        function parseKey(raw) {
            const value = String(raw || '').trim();
            if (!value) return '';
            const parts = value.split('—');
            return String(parts[0] || '').trim().toLowerCase();
        }

        function syncHidden() {
            if (statusSearch && statusValue) {
                statusValue.value = parseKey(statusSearch.value);
            }
        }

        if (statusSearch) {
            statusSearch.addEventListener('input', syncHidden);
            statusSearch.addEventListener('change', syncHidden);
        }
        syncHidden();
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
