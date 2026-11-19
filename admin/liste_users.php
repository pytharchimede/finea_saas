<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/User.php';

$pdo = Database::getConnection();

$userModel = new User($pdo);
$userModel->ensurePersonnelLinkSupport();

// ── Vérification colonne feb ─────────────────────────────────────────────────
$hasFebColumn = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM user_devis_banamur LIKE 'feb_validation_services'")->fetch(PDO::FETCH_ASSOC);
    $hasFebColumn = !empty($col);
} catch (Throwable $e) {
    $hasFebColumn = false;
}

// ── Libellés des services ────────────────────────────────────────────────────
$serviceLabelById = [];
$servicesForSelect = [];
try {
    $svcStmt = $pdo->query("SELECT id_service, lib_service FROM service ORDER BY lib_service ASC");
    while ($row = $svcStmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)($row['id_service'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $label = trim((string)($row['lib_service'] ?? ''));
        $serviceLabelById[$id] = $label !== '' ? $label : ('#' . $id);
        $servicesForSelect[] = ['id' => $id, 'label' => $serviceLabelById[$id]];
    }
} catch (Throwable $e) {
    // Si la table service n'est pas accessible, on affichera des IDs.
}

// ── Rôles disponibles ────────────────────────────────────────────────────────
$rolesForSelect = [];
try {
    $roleStmt = $pdo->query("SELECT id_role_devis, lib_role_devis FROM role_devis_banamur ORDER BY lib_role_devis ASC");
    while ($row = $roleStmt->fetch(PDO::FETCH_ASSOC)) {
        $rolesForSelect[] = [
            'id' => (int)$row['id_role_devis'],
            'label' => trim((string)$row['lib_role_devis']),
        ];
    }
} catch (Throwable $e) {
    // Table rôles optionnelle
}

// ── Filtres GET ──────────────────────────────────────────────────────────────
$fQ = trim((string)($_GET['q'] ?? ''));
$fStatus = $_GET['status'] ?? 'all';
$fLinked = $_GET['linked_personnel'] ?? 'all';
$fAdmin = $_GET['admin'] ?? 'all';
$fFebMode = $_GET['feb_mode'] ?? 'all';
$fRoleId = isset($_GET['role_id']) && is_numeric($_GET['role_id']) ? (int)$_GET['role_id'] : 0;

// Valeurs valides
if (!in_array($fStatus, ['all', 'active', 'inactive'], true)) {
    $fStatus = 'all';
}
if (!in_array($fLinked, ['all', 'yes', 'no'], true)) {
    $fLinked = 'all';
}
if (!in_array($fAdmin, ['all', 'yes', 'no'], true)) {
    $fAdmin = 'all';
}
if (!in_array($fFebMode, ['all', 'configured', 'global', 'specific', 'empty'], true)) {
    $fFebMode = 'all';
}

$hasActiveFilter = $fQ !== ''
    || $fStatus !== 'all'
    || $fLinked !== 'all'
    || $fAdmin !== 'all'
    || $fFebMode !== 'all'
    || $fRoleId > 0;

// ── Construction de la requête avec filtres ──────────────────────────────────
$where = [];
$params = [];

if ($fQ !== '') {
    $where[] = "(u.prenom LIKE :q OR u.nom LIKE :q OR u.mail_pro LIKE :q
                 OR p.nom_personnel_soignant LIKE :q
                 OR s.lib_service LIKE :q
                 OR r.lib_role_devis LIKE :q)";
    $params['q'] = '%' . $fQ . '%';
}

if ($fStatus === 'active') {
    $where[] = "u.active = 1";
} elseif ($fStatus === 'inactive') {
    $where[] = "u.active = 0";
}

if ($fLinked === 'yes') {
    $where[] = "u.personnel_id IS NOT NULL";
} elseif ($fLinked === 'no') {
    $where[] = "(u.personnel_id IS NULL OR u.personnel_id = 0)";
}

if ($fAdmin === 'yes') {
    $where[] = "u.acces_admin = 1";
} elseif ($fAdmin === 'no') {
    $where[] = "(u.acces_admin IS NULL OR u.acces_admin = 0)";
}

if ($hasFebColumn && $fFebMode !== 'all') {
    if ($fFebMode === 'empty') {
        $where[] = "(u.feb_validation_services IS NULL OR TRIM(u.feb_validation_services) = '')";
    } elseif ($fFebMode === 'global') {
        $where[] = "LOWER(TRIM(u.feb_validation_services)) IN ('*','all','tous')";
    } elseif ($fFebMode === 'specific') {
        $where[] = "(u.feb_validation_services IS NOT NULL AND TRIM(u.feb_validation_services) != ''
                     AND LOWER(TRIM(u.feb_validation_services)) NOT IN ('*','all','tous'))";
    } elseif ($fFebMode === 'configured') {
        $where[] = "(u.feb_validation_services IS NOT NULL AND TRIM(u.feb_validation_services) != '')";
    }
}

if ($fRoleId > 0) {
    $where[] = "u.role_id = :role_id";
    $params['role_id'] = $fRoleId;
}

$whereClause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$febSelect = $hasFebColumn ? "u.feb_validation_services" : "NULL";

$sql = "SELECT
    u.id, u.prenom, u.nom, u.mail_pro, u.active, u.acces_admin,
    {$febSelect} AS feb_validation_services,
    u.personnel_id,
    u.role_id,
    r.lib_role_devis AS role_label,
    p.nom_personnel_soignant AS personnel_full_name,
    COALESCE(NULLIF(TRIM(s.lib_service), ''), '') AS personnel_service_label
FROM user_devis_banamur u
LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
LEFT JOIN service s ON s.id_service = p.service_id
LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id
{$whereClause}
ORDER BY u.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs globaux (toujours sur toute la table) ───────────────────────────────
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM user_devis_banamur")->fetchColumn();
$activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM user_devis_banamur WHERE active = 1")->fetchColumn();
$inactiveUsers = $totalUsers - $activeUsers;
$filteredCount = count($users);

// ── Helper FEB ───────────────────────────────────────────────────────────────
$formatFebSummary = static function (?string $raw, array $serviceLabelById): array {
    $value = trim((string)$raw);
    $lower = strtolower($value);

    if ($value === '') {
        return [
            'badge' => 'Non configuré',
            'classes' => 'bg-amber-100 text-amber-800',
            'title' => 'Accès global par défaut (compatibilité) — affiche un avertissement dans Finances.',
        ];
    }

    if (in_array($lower, ['*', 'all', 'tous'], true)) {
        return [
            'badge' => 'Tous',
            'classes' => 'bg-emerald-100 text-emerald-700',
            'title' => 'Tous les services autorisés.',
        ];
    }

    $parts = preg_split('/\s*,\s*/', $value, -1, PREG_SPLIT_NO_EMPTY);
    $ids = [];
    foreach ($parts as $p) {
        if (!is_numeric($p)) {
            continue;
        }
        $id = (int)$p;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    sort($ids);

    if (!$ids) {
        return [
            'badge' => 'Liste vide',
            'classes' => 'bg-amber-100 text-amber-800',
            'title' => $value,
        ];
    }

    $labels = [];
    foreach ($ids as $id) {
        $labels[] = (string)($serviceLabelById[$id] ?? ('#' . $id));
    }

    $display = $labels;
    if (count($labels) > 3) {
        $display = array_slice($labels, 0, 3);
        $display[] = '+' . (count($labels) - 3) . ' autres';
    }

    return [
        'badge' => implode(', ', $display),
        'classes' => 'bg-blue-100 text-blue-700',
        'title' => implode(', ', $labels),
    ];
};

// ── Résumé des filtres actifs (pour affichage et PDF) ────────────────────────
$filterSummaryParts = [];
if ($fQ !== '') {
    $filterSummaryParts[] = 'Recherche : "' . $fQ . '"';
}
if ($fStatus !== 'all') {
    $filterSummaryParts[] = 'Statut : ' . ($fStatus === 'active' ? 'Actif' : 'Inactif');
}
if ($fLinked !== 'all') {
    $filterSummaryParts[] = 'Lié au personnel : ' . ($fLinked === 'yes' ? 'Oui' : 'Non');
}
if ($fAdmin !== 'all') {
    $filterSummaryParts[] = 'Admin : ' . ($fAdmin === 'yes' ? 'Oui' : 'Non');
}
if ($fFebMode !== 'all') {
    $febModeLabels = ['configured' => 'Configuré', 'global' => 'Global (tous)', 'specific' => 'Services spécifiques', 'empty' => 'Non configuré'];
    $filterSummaryParts[] = 'FEB : ' . ($febModeLabels[$fFebMode] ?? $fFebMode);
}
if ($fRoleId > 0) {
    $roleName = '';
    foreach ($rolesForSelect as $r) {
        if ($r['id'] === $fRoleId) {
            $roleName = $r['label'];
            break;
        }
    }
    $filterSummaryParts[] = 'Rôle : ' . ($roleName ?: '#' . $fRoleId);
}

// ── Construction de la query string pour l'export PDF ───────────────────────
$exportParams = http_build_query(array_filter([
    'q' => $fQ,
    'status' => $fStatus !== 'all' ? $fStatus : '',
    'linked_personnel' => $fLinked !== 'all' ? $fLinked : '',
    'admin' => $fAdmin !== 'all' ? $fAdmin : '',
    'feb_mode' => $fFebMode !== 'all' ? $fFebMode : '',
    'role_id' => $fRoleId > 0 ? (string)$fRoleId : '',
], static fn($v) => $v !== '' && $v !== null));

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">

        <!-- ── En-tête ──────────────────────────────────────────────────── -->
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-800">Gestion des Utilisateurs</h1>
                <p class="mt-2 text-gray-600">Rechercher, filtrer et exporter la liste des comptes.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="add_user.php" class="inline-flex items-center gap-2 px-5 py-3 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-xl shadow hover:shadow-xl hover:from-emerald-600 hover:to-teal-700 transition">
                    <i class="fas fa-user-plus"></i>
                    Ajouter un utilisateur
                </a>
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                    <i class="fas fa-arrow-left"></i>
                    Retour au dashboard
                </a>
            </div>
        </div>

        <!-- ── KPI cards ────────────────────────────────────────────────── -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-2xl shadow border border-indigo-100 p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total</p>
                    <p class="text-3xl font-bold text-indigo-600"><?= $totalUsers ?></p>
                </div>
                <div class="bg-indigo-100 p-3 rounded-xl"><i class="fas fa-users text-indigo-600 text-xl"></i></div>
            </div>
            <div class="bg-white rounded-2xl shadow border border-emerald-100 p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Actifs</p>
                    <p class="text-3xl font-bold text-emerald-600"><?= $activeUsers ?></p>
                </div>
                <div class="bg-emerald-100 p-3 rounded-xl"><i class="fas fa-user-check text-emerald-600 text-xl"></i></div>
            </div>
            <div class="bg-white rounded-2xl shadow border border-red-100 p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Inactifs</p>
                    <p class="text-3xl font-bold text-red-500"><?= $inactiveUsers ?></p>
                </div>
                <div class="bg-red-100 p-3 rounded-xl"><i class="fas fa-user-slash text-red-500 text-xl"></i></div>
            </div>
            <div class="bg-white rounded-2xl shadow border border-violet-100 p-5 flex items-center justify-between">
                <div>
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Résultats filtrés</p>
                    <p class="text-3xl font-bold text-violet-600"><?= $filteredCount ?></p>
                </div>
                <div class="bg-violet-100 p-3 rounded-xl"><i class="fas fa-filter text-violet-600 text-xl"></i></div>
            </div>
        </div>

        <!-- ── Panneau de filtres avancés ───────────────────────────────── -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6 mb-6">
            <form method="GET" action="" id="filterForm">
                <div class="flex flex-col md:flex-row md:items-end gap-4 flex-wrap">

                    <!-- Recherche texte libre -->
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">
                            <i class="fas fa-search mr-1"></i> Recherche libre
                        </label>
                        <input type="text" name="q" value="<?= htmlspecialchars($fQ, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Nom, email, personnel, service, rôle…"
                            class="w-full border border-gray-200 rounded-xl py-2.5 px-4 text-sm shadow-sm focus:outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-100" />
                    </div>

                    <!-- Statut -->
                    <div class="min-w-[140px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Statut</label>
                        <select name="status" class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm shadow-sm focus:outline-none focus:border-indigo-300">
                            <option value="all" <?= $fStatus === 'all' ? 'selected' : '' ?>>Tous</option>
                            <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Actif</option>
                            <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                    </div>

                    <!-- Lié au personnel -->
                    <div class="min-w-[160px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Lié au personnel</label>
                        <select name="linked_personnel" class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm shadow-sm focus:outline-none focus:border-indigo-300">
                            <option value="all" <?= $fLinked === 'all' ? 'selected' : '' ?>>Tous</option>
                            <option value="yes" <?= $fLinked === 'yes' ? 'selected' : '' ?>>Oui</option>
                            <option value="no" <?= $fLinked === 'no' ? 'selected' : '' ?>>Non</option>
                        </select>
                    </div>

                    <!-- Admin -->
                    <div class="min-w-[140px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Admin</label>
                        <select name="admin" class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm shadow-sm focus:outline-none focus:border-indigo-300">
                            <option value="all" <?= $fAdmin === 'all' ? 'selected' : '' ?>>Tous</option>
                            <option value="yes" <?= $fAdmin === 'yes' ? 'selected' : '' ?>>Oui</option>
                            <option value="no" <?= $fAdmin === 'no' ? 'selected' : '' ?>>Non</option>
                        </select>
                    </div>

                    <!-- FEB -->
                    <div class="min-w-[180px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Validation FEB</label>
                        <select name="feb_mode" class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm shadow-sm focus:outline-none focus:border-indigo-300">
                            <option value="all" <?= $fFebMode === 'all' ? 'selected' : '' ?>>Tous</option>
                            <option value="configured" <?= $fFebMode === 'configured' ? 'selected' : '' ?>>Configuré</option>
                            <option value="global" <?= $fFebMode === 'global' ? 'selected' : '' ?>>Global (tous)</option>
                            <option value="specific" <?= $fFebMode === 'specific' ? 'selected' : '' ?>>Services spécifiques</option>
                            <option value="empty" <?= $fFebMode === 'empty' ? 'selected' : '' ?>>Non configuré</option>
                        </select>
                    </div>

                    <!-- Rôle -->
                    <?php if ($rolesForSelect): ?>
                    <div class="min-w-[160px]">
                        <label class="block text-xs font-semibold text-gray-600 mb-1 uppercase tracking-wide">Rôle</label>
                        <select name="role_id" class="w-full border border-gray-200 rounded-xl py-2.5 px-3 text-sm shadow-sm focus:outline-none focus:border-indigo-300">
                            <option value="">Tous</option>
                            <?php foreach ($rolesForSelect as $rOption): ?>
                                <option value="<?= $rOption['id'] ?>" <?= $fRoleId === $rOption['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($rOption['label'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <!-- Boutons -->
                    <div class="flex gap-2 items-end">
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition text-sm font-semibold shadow">
                            <i class="fas fa-search"></i> Filtrer
                        </button>
                        <?php if ($hasActiveFilter): ?>
                            <a href="liste_users.php" class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-100 text-gray-700 border border-gray-200 rounded-xl hover:bg-gray-200 transition text-sm font-semibold">
                                <i class="fas fa-times"></i> Réinitialiser
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Badges de filtres actifs -->
            <?php if ($hasActiveFilter && $filterSummaryParts): ?>
                <div class="mt-4 flex flex-wrap gap-2 items-center">
                    <span class="text-xs text-gray-500 font-medium">Filtres actifs :</span>
                    <?php foreach ($filterSummaryParts as $fPart): ?>
                        <span class="inline-flex items-center px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 text-xs font-semibold">
                            <?= htmlspecialchars($fPart, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── Tableau des utilisateurs ─────────────────────────────────── -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">Liste des comptes</h2>
                    <p class="text-sm text-gray-500 mt-1">
                        <?= $filteredCount ?> résultat<?= $filteredCount > 1 ? 's' : '' ?>
                        <?= $hasActiveFilter ? ' (filtrés)' : '' ?>
                    </p>
                </div>

                <!-- Export PDF -->
                <div class="flex flex-wrap gap-2">
                    <a href="export_users_pdf.php" target="_blank"
                       class="inline-flex items-center gap-2 px-4 py-2.5 bg-rose-600 text-white rounded-xl hover:bg-rose-700 transition text-sm font-semibold shadow">
                        <i class="fas fa-file-pdf"></i>
                        Exporter tout (PDF)
                    </a>
                    <a href="export_users_pdf.php<?= $exportParams ? ('?' . htmlspecialchars($exportParams, ENT_QUOTES, 'UTF-8')) : '' ?>" target="_blank"
                       class="inline-flex items-center gap-2 px-4 py-2.5 bg-violet-600 text-white rounded-xl hover:bg-violet-700 transition text-sm font-semibold shadow <?= !$hasActiveFilter ? 'opacity-50 pointer-events-none' : '' ?>"
                       <?= !$hasActiveFilter ? 'aria-disabled="true"' : '' ?>>
                        <i class="fas fa-file-pdf"></i>
                        Exporter filtrés (PDF)
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-gray-100 text-gray-600 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">Utilisateur</th>
                            <th class="px-4 py-3">Rôle</th>
                            <th class="px-4 py-3">Personnel / Service</th>
                            <th class="px-4 py-3">Email</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Admin</th>
                            <th class="px-4 py-3">FEB</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm text-gray-700">
                        <?php if (!$users): ?>
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-gray-400">
                                    <i class="fas fa-search text-3xl mb-3 block opacity-30"></i>
                                    Aucun utilisateur ne correspond à vos critères.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($users as $u): ?>
                            <tr class="border-t hover:bg-gray-50">
                                <td class="px-4 py-4">
                                    <div class="font-semibold text-gray-800"><?= htmlspecialchars($u['prenom'] . ' ' . $u['nom'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($u['role_label'])): ?>
                                        <span class="inline-flex px-2 py-1 rounded-lg bg-slate-100 text-slate-600 text-xs font-medium">
                                            <?= htmlspecialchars($u['role_label'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($u['personnel_id'])): ?>
                                        <div class="font-medium text-gray-700"><?= htmlspecialchars($u['personnel_full_name'] ?: '-', ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($u['personnel_service_label'])): ?>
                                            <div class="text-xs text-gray-400"><?= htmlspecialchars($u['personnel_service_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="text-gray-600 break-all text-xs"><?= htmlspecialchars($u['mail_pro'], ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if ($u['active']): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span> Actif
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-red-100 text-red-600 text-xs font-semibold">
                                            <span class="w-1.5 h-1.5 rounded-full bg-red-400 inline-block"></span> Inactif
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if ($u['acces_admin']): ?>
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-indigo-100 text-indigo-700 text-xs font-semibold">
                                            <i class="fas fa-shield-alt text-xs"></i> Oui
                                        </span>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <?php $febInfo = $formatFebSummary($u['feb_validation_services'] ?? null, $serviceLabelById); ?>
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold <?= $febInfo['classes'] ?>"
                                          title="<?= htmlspecialchars((string)($febInfo['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)($febInfo['badge'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="edit_user.php?id=<?= (int)$u['id'] ?>"
                                           class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition text-xs font-semibold">
                                            <i class="fas fa-user-cog"></i> Modifier
                                        </a>
                                        <?php if ($u['active']): ?>
                                            <a href="../desactiver_utilisateur.php?id=<?= (int)$u['id'] ?>"
                                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-lg transition text-xs font-semibold">
                                                <i class="fas fa-user-slash"></i> Désactiver
                                            </a>
                                        <?php else: ?>
                                            <a href="../reactiver_utilisateur.php?id=<?= (int)$u['id'] ?>"
                                               class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition text-xs font-semibold">
                                                <i class="fas fa-user-check"></i> Activer
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>