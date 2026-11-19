<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../repositories/FactureAchatRepository.php';
require_once __DIR__ . '/../repositories/FactureVenteRepository.php';

$pageTitle = 'Recherche Factures';
$activeMenu = 'facturation';

$pdo = Database::getConnection();
try {
    new FactureAchatRepository($pdo);
    new FactureVenteRepository($pdo);
} catch (Throwable $e) {
}

function factSearchTableExists(PDO $pdo, string $table): bool
{
    try {
        $st = $pdo->prepare('SHOW TABLES LIKE :table');
        $st->execute(['table' => $table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function factSearchColumns(PDO $pdo, string $table): array
{
    try {
        return $pdo->query('SHOW COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function factSearchMoney($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' CFA';
}

function factSearchDate($value): string
{
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function factSearchBadge(string $status): string
{
    $map = [
        'brouillon' => 'bg-slate-100 text-slate-700 border-slate-200',
        'a_verifier' => 'bg-amber-100 text-amber-800 border-amber-200',
        'verifiee' => 'bg-sky-100 text-sky-800 border-sky-200',
        'payee' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'rejetee' => 'bg-rose-100 text-rose-800 border-rose-200',
        'emise' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'a_signer' => 'bg-purple-100 text-purple-800 border-purple-200',
        'signe' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'erreur' => 'bg-rose-100 text-rose-800 border-rose-200',
        'submitted' => 'bg-slate-100 text-slate-700 border-slate-200',
        'approved' => 'bg-sky-100 text-sky-800 border-sky-200',
        'rejected' => 'bg-rose-100 text-rose-800 border-rose-200',
    ];
    $class = $map[$status] ?? 'bg-slate-100 text-slate-700 border-slate-200';
    $label = [
        'a_verifier' => 'A verifier',
        'verifiee' => 'Verifiee',
        'payee' => 'Payee',
        'rejetee' => 'Rejetee',
        'emise' => 'Emise',
        'a_signer' => 'A signer',
        'signe' => 'Signee',
        'submitted' => 'Soumise',
        'approved' => 'Approuvee',
        'rejected' => 'Rejetee',
    ][$status] ?? ucfirst(str_replace('_', ' ', $status));
    return '<span class="inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-semibold ' . $class . '">' . htmlspecialchars($label) . '</span>';
}

function factSearchFebStatus(array $feb): string
{
    $status = strtolower((string)($feb['status'] ?? ''));
    if ($status === 'rejected') {
        return 'rejetee';
    }
    if ($status === 'approved') {
        return 'a_verifier';
    }
    return 'brouillon';
}

function factSearchFicheStatus(array $fiche): string
{
    if ((int)($fiche['etat_fiche'] ?? 0) === 2) {
        return 'rejetee';
    }
    if (!empty($fiche['decaisse'])) {
        return 'payee';
    }
    if (!empty($fiche['approuve'])) {
        return 'verifiee';
    }
    if (!empty($fiche['conforme'])) {
        return 'a_verifier';
    }
    return 'brouillon';
}

function factSearchPayload(array $row): array
{
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

$filters = [
    'type' => (string)($_GET['type'] ?? 'all'),
    'q' => trim((string)($_GET['q'] ?? '')),
    'demandeur' => trim((string)($_GET['demandeur'] ?? '')),
    'client' => trim((string)($_GET['client'] ?? '')),
    'statut' => trim((string)($_GET['statut'] ?? '')),
    'date_debut' => trim((string)($_GET['date_debut'] ?? '')),
    'date_fin' => trim((string)($_GET['date_fin'] ?? '')),
];
$allowedTypes = ['all', 'vente', 'achat', 'feb', 'fiche'];
if (!in_array($filters['type'], $allowedTypes, true)) {
    $filters['type'] = 'all';
}

$clients = [];
if (factSearchTableExists($pdo, 'client_banamur')) {
    try {
        $clients = $pdo->query("SELECT * FROM client_banamur ORDER BY nom_client ASC LIMIT 400")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $clients = [];
    }
}

$personnels = [];
if (factSearchTableExists($pdo, 'personnel')) {
    try {
        $personnels = $pdo->query("SELECT * FROM personnel ORDER BY nom_personnel_soignant ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $personnels = [];
    }
}

$rows = [];
$addDateFilters = static function (array &$where, array &$params, string $column) use ($filters): void {
    if ($filters['date_debut'] !== '') {
        $where[] = $column . ' >= :date_debut';
        $params['date_debut'] = $filters['date_debut'] . ' 00:00:00';
    }
    if ($filters['date_fin'] !== '') {
        $where[] = $column . ' <= :date_fin';
        $params['date_fin'] = $filters['date_fin'] . ' 23:59:59';
    }
};

if (($filters['type'] === 'all' || $filters['type'] === 'vente') && factSearchTableExists($pdo, 'facture_vente_banamur')) {
    $where = ['c.archived_at IS NULL'];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(fv.reference_interne LIKE :q OR fv.client_company_name LIKE :q OR fv.client_phone LIKE :q OR fv.client_email LIKE :q OR fv.items_json LIKE :q OR fv.fne_invoice_number LIKE :q OR fv.fne_invoice_id LIKE :q OR c.code LIKE :q OR c.titre LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['client'] !== '') {
        $where[] = 'fv.client_company_name LIKE :client';
        $params['client'] = '%' . $filters['client'] . '%';
    }
    if ($filters['statut'] !== '') {
        $where[] = '(fv.statut = :statut OR fv.paiement_statut = :statut)';
        $params['statut'] = $filters['statut'];
    }
    $addDateFilters($where, $params, 'fv.created_at');
    $sql = 'SELECT fv.*, c.code AS chantier_code, c.titre AS chantier_titre
            FROM facture_vente_banamur fv
            LEFT JOIN chantier_banamur c ON c.id = fv.chantier_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY fv.created_at DESC LIMIT 250';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rows[] = [
                'type' => 'Vente',
                'reference' => (string)($row['reference_interne'] ?? ''),
                'libelle' => trim((string)($row['chantier_titre'] ?? 'Facture de vente')),
                'tiers' => (string)($row['client_company_name'] ?? ''),
                'demandeur' => '-',
                'chantier' => trim((string)($row['chantier_code'] ?? '') . ' ' . (string)($row['chantier_titre'] ?? '')),
                'montant' => (float)($row['total_ttc'] ?? 0),
                'statut' => (string)($row['paiement_statut'] ?? $row['statut'] ?? ''),
                'date' => (string)($row['created_at'] ?? ''),
                'url' => 'edit_facture_vente.php?id=' . (int)($row['id_facture_vente'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
}

if (($filters['type'] === 'all' || $filters['type'] === 'achat') && factSearchTableExists($pdo, 'facture_achat_banamur')) {
    $where = ['1=1'];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(fa.reference_interne LIKE :q OR fa.invoice_number LIKE :q OR fa.verification_code LIKE :q OR fa.items_json LIKE :q OR fa.file_original_name LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['statut'] !== '') {
        $where[] = 'fa.statut = :statut';
        $params['statut'] = $filters['statut'];
    }
    $addDateFilters($where, $params, 'fa.created_at');
    $sql = 'SELECT fa.*
            FROM facture_achat_banamur fa
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY fa.created_at DESC LIMIT 250';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $rows[] = [
                'type' => 'Achat manuel',
                'reference' => (string)($row['reference_interne'] ?? $row['invoice_number'] ?? ''),
                'libelle' => (string)($row['file_original_name'] ?? 'Facture achat'),
                'tiers' => 'Fournisseur',
                'demandeur' => '-',
                'chantier' => '-',
                'montant' => (float)($row['amount_total'] ?? 0),
                'statut' => (string)($row['statut'] ?? ''),
                'date' => (string)($row['created_at'] ?? ''),
                'url' => 'edit_facture_achat.php?id=' . (int)($row['id_facture_achat'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
}

if (($filters['type'] === 'all' || $filters['type'] === 'feb') && factSearchTableExists($pdo, 'employee_legal_requests')) {
    $where = ['r.request_type = "feb"', "NOT EXISTS(SELECT 1 FROM chantier_banamur c_arch WHERE c_arch.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(r.payload_json,'$.chantier_id')) AS UNSIGNED) AND c_arch.archived_at IS NOT NULL)"];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(r.title LIKE :q OR r.requester_name LIKE :q OR r.requester_email LIKE :q OR r.payload_json LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['demandeur'] !== '') {
        $where[] = '(r.requester_name LIKE :demandeur OR r.requester_email LIKE :demandeur OR r.payload_json LIKE :demandeur)';
        $params['demandeur'] = '%' . $filters['demandeur'] . '%';
    }
    if ($filters['client'] !== '') {
        $where[] = 'r.payload_json LIKE :client';
        $params['client'] = '%' . $filters['client'] . '%';
    }
    if ($filters['statut'] !== '') {
        if ($filters['statut'] === 'a_verifier') {
            $where[] = 'r.status = "approved"';
        } elseif ($filters['statut'] === 'brouillon') {
            $where[] = 'r.status IN ("submitted", "details_requested")';
        } elseif ($filters['statut'] === 'rejetee') {
            $where[] = 'r.status = "rejected"';
        } else {
            $where[] = 'r.status = :status';
            $params['status'] = $filters['statut'];
        }
    }
    $addDateFilters($where, $params, 'r.submitted_at');
    $sql = 'SELECT r.*
            FROM employee_legal_requests r
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.submitted_at DESC LIMIT 300';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $payload = factSearchPayload($row);
            $reference = trim((string)($payload['numero_bon_commande_interne'] ?? $payload['code_autorisation_feb'] ?? ('FEB-' . (int)$row['id'])));
            $rows[] = [
                'type' => 'FEB',
                'reference' => $reference,
                'libelle' => (string)($row['title'] ?? $payload['objet'] ?? $payload['description'] ?? 'FEB'),
                'tiers' => (string)($payload['invoice_supplier']['nom'] ?? $payload['fournisseur_nom'] ?? '-'),
                'demandeur' => (string)($row['requester_name'] ?? ''),
                'chantier' => (string)($payload['chantier_label'] ?? $payload['chantier'] ?? $payload['chantier_code'] ?? '-'),
                'montant' => (float)($payload['montant'] ?? $payload['amount'] ?? 0),
                'statut' => factSearchFebStatus($row),
                'date' => (string)($row['submitted_at'] ?? ''),
                'url' => '../validation_feb_detail.php?id=' . (int)($row['id'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
    }
}

if (($filters['type'] === 'all' || $filters['type'] === 'fiche') && factSearchTableExists($pdo, 'fiche_decaissement')) {
    $where = ['(f.chantier_id IS NULL OR c.archived_at IS NULL)'];
    $params = [];
    if ($filters['q'] !== '') {
        $where[] = '(f.num_fiche LIKE :q OR f.beficiaire_fiche LIKE :q OR f.designation_fiche LIKE :q OR f.precision_fiche LIKE :q OR f.details_demande LIKE :q OR f.code_autorisation_feb LIKE :q OR c.code LIKE :q OR c.titre LIKE :q)';
        $params['q'] = '%' . $filters['q'] . '%';
    }
    if ($filters['demandeur'] !== '') {
        $where[] = '(f.beficiaire_fiche LIKE :demandeur OR f.tel_beneficiaire_fiche LIKE :demandeur)';
        $params['demandeur'] = '%' . $filters['demandeur'] . '%';
    }
    if ($filters['client'] !== '') {
        $where[] = '(c.code LIKE :client OR c.titre LIKE :client)';
        $params['client'] = '%' . $filters['client'] . '%';
    }
    $addDateFilters($where, $params, 'f.date_creat_fiche');
    $sql = 'SELECT f.*, c.code AS chantier_code, c.titre AS chantier_titre
            FROM fiche_decaissement f
            LEFT JOIN chantier_banamur c ON c.id = f.chantier_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY f.date_creat_fiche DESC LIMIT 300';
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $status = factSearchFicheStatus($row);
            if ($filters['statut'] !== '' && $filters['statut'] !== $status) {
                continue;
            }
            $rows[] = [
                'type' => 'Fiche decaissement',
                'reference' => (string)($row['num_fiche'] ?? ''),
                'libelle' => trim((string)($row['designation_fiche'] ?? '') . ' ' . (string)($row['precision_fiche'] ?? '')),
                'tiers' => (string)($row['fournisseur_nom'] ?? '-'),
                'demandeur' => (string)($row['beficiaire_fiche'] ?? ''),
                'chantier' => trim((string)($row['chantier_code'] ?? '') . ' ' . (string)($row['chantier_titre'] ?? '')),
                'montant' => (float)($row['montant_fiche'] ?? 0),
                'statut' => $status,
                'date' => (string)($row['date_creat_fiche'] ?? ''),
                'url' => '../decaissement/voir_fiche_details.php?num_fiche=' . rawurlencode((string)($row['num_fiche'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
    }
}

usort($rows, static fn(array $a, array $b): int => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));
$totalAmount = array_sum(array_map(static fn(array $r): float => (float)($r['montant'] ?? 0), $rows));

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-8">
        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Facturation</p>
                    <h1 class="mt-2 text-3xl font-semibold text-slate-950">Recherche avancee des factures</h1>
                    <p class="mt-2 max-w-3xl text-sm text-slate-500">Retrouvez une facture par type, libelle, demandeur, client, chantier, numero FEB ou numero de fiche.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="dashboard_facturation.php" class="inline-flex items-center rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-arrow-left mr-2"></i> Dashboard
                    </a>
                    <a href="factures_vente.php" class="inline-flex items-center rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">
                        <i class="fas fa-plus mr-2"></i> Vente
                    </a>
                </div>
            </div>

            <form method="get" class="mt-6 grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 lg:grid-cols-6">
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Type</label>
                    <select name="type" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                        <?php foreach (['all' => 'Toutes', 'vente' => 'Ventes', 'achat' => 'Achats manuels', 'feb' => 'FEB', 'fiche' => 'Fiches'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= $filters['type'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="lg:col-span-2">
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Libelle / numero</label>
                    <input name="q" value="<?= htmlspecialchars($filters['q']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Ex: FV-202605, police, BCI, chantier...">
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Demandeur</label>
                    <input name="demandeur" list="personnelOptions" data-finea-search value="<?= htmlspecialchars($filters['demandeur']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Nom ou email">
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Client</label>
                    <input name="client" list="clientOptions" data-finea-search value="<?= htmlspecialchars($filters['client']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Client ou chantier">
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Statut</label>
                    <input name="statut" value="<?= htmlspecialchars($filters['statut']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="payee, a_verifier...">
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Debut</label>
                    <input type="date" name="date_debut" value="<?= htmlspecialchars($filters['date_debut']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Fin</label>
                    <input type="date" name="date_fin" value="<?= htmlspecialchars($filters['date_fin']) ?>" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm">
                </div>
                <div class="flex items-end gap-2 lg:col-span-4">
                    <button class="inline-flex items-center rounded-xl bg-slate-950 px-5 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">
                        <i class="fas fa-search mr-2"></i> Rechercher
                    </button>
                    <a href="recherche_factures.php" class="inline-flex items-center rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Reinitialiser</a>
                </div>
            </form>

            <datalist id="personnelOptions">
                <?php foreach ($personnels as $p): ?>
                    <option value="<?= htmlspecialchars(trim((string)($p['nom_personnel_soignant'] ?? '') . ' ' . (string)($p['email_personnel_soignant'] ?? ''))) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <datalist id="clientOptions">
                <?php foreach ($clients as $c): ?>
                    <option value="<?= htmlspecialchars(trim((string)($c['nom_client'] ?? '') . ' ' . (string)($c['prenom_client'] ?? ''))) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </div>

        <div class="mt-6 grid gap-4 md:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Resultats</p>
                <p class="mt-2 text-3xl font-semibold text-slate-950"><?= count($rows) ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Montant total affiche</p>
                <p class="mt-2 text-3xl font-semibold text-slate-950"><?= htmlspecialchars(factSearchMoney($totalAmount)) ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-slate-500">Perimetre</p>
                <p class="mt-2 text-lg font-semibold text-slate-950"><?= htmlspecialchars($filters['type'] === 'all' ? 'Tous les flux' : ucfirst($filters['type'])) ?></p>
            </div>
        </div>

        <div class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Libelle</th>
                            <th class="px-4 py-3">Tiers / client</th>
                            <th class="px-4 py-3">Demandeur</th>
                            <th class="px-4 py-3">Chantier</th>
                            <th class="px-4 py-3 text-right">Montant</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php if (!$rows): ?>
                            <tr><td colspan="10" class="px-4 py-10 text-center text-slate-500">Aucun resultat pour ces criteres.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $row): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-3 font-semibold text-slate-900"><?= htmlspecialchars((string)$row['type']) ?></td>
                                <td class="px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$row['reference']) ?></td>
                                <td class="max-w-xs px-4 py-3 text-slate-700"><?= htmlspecialchars((string)$row['libelle']) ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$row['tiers']) ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$row['demandeur']) ?></td>
                                <td class="max-w-xs px-4 py-3 text-slate-600"><?= htmlspecialchars((string)$row['chantier']) ?></td>
                                <td class="px-4 py-3 text-right font-semibold text-slate-900"><?= htmlspecialchars(factSearchMoney($row['montant'])) ?></td>
                                <td class="px-4 py-3"><?= factSearchBadge((string)$row['statut']) ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(factSearchDate($row['date'])) ?></td>
                                <td class="px-4 py-3 text-right">
                                    <a href="<?= htmlspecialchars((string)$row['url']) ?>" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-50">Ouvrir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script src="assets/finea-search-combobox.js"></script>
<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
