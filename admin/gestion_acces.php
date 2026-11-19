<?php
require_once '../auth_check.php';
require_once '../model/Database.php';
require_once '../model/AuditTrail.php';

// Vérifier que l'utilisateur a les droits de gestion des accès
AccessManager::requirePageAccess('gestion_acces.php');

// Récupérer la liste des utilisateurs
try {
    $pdo = Database::getConnection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_universal_access') {
        $permissionKey = trim((string) ($_POST['permission_key'] ?? ''));
        $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
        $beforeUniversal = AccessManager::isUniversalPermission($permissionKey, $pdo);

        AccessManager::setUniversalPermission(
            $pdo,
            $permissionKey,
            $enabled,
            (int) ($_SESSION['user_id'] ?? 0)
        );

        AuditTrail::log('universal_access_updated', [
            'module' => 'admin',
            'action' => $enabled ? 'universal_access_enabled' : 'universal_access_disabled',
            'entity_type' => 'permission',
            'entity_label' => $permissionKey,
            'result' => 'success',
            'old_value' => ['is_universal' => $beforeUniversal ? 1 : 0],
            'new_value' => ['is_universal' => $enabled ? 1 : 0],
            'comment' => $enabled
                ? 'Le module devient obligatoire pour tous les utilisateurs.'
                : 'Le module redevient configurable individuellement.',
        ], $pdo);

        $messageType = 'success';
        $message = $enabled
            ? 'Accès commun activé et appliqué à tous les comptes.'
            : 'Le module n’est plus obligatoire. Les droits individuels existants sont conservés.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock_user') {
        $unlockUserId = (int)($_POST['user_id'] ?? 0);

        if ($unlockUserId > 0) {
            $stmtUnlock = $pdo->prepare("
                UPDATE login_security
                SET failed_attempts = 0,
                    locked = 0,
                    unlock_token_hash = NULL,
                    unlock_expires_at = NULL,
                    unlock_email_sent_at = NULL
                WHERE user_id = :user_id
            ");
            $stmtUnlock->execute(['user_id' => $unlockUserId]);

            AuditTrail::log('user_unlocked', [
                'module' => 'admin',
                'action' => 'unlock_user',
                'entity_type' => 'user',
                'entity_id' => (string)$unlockUserId,
                'result' => 'success',
            ], $pdo);

            $messageType = 'success';
            $message = 'Compte débloqué avec succès.';
        }
    }

    $stmt = $pdo->query("
        SELECT 
            u.*,
            COALESCE(ls.failed_attempts, 0) AS failed_attempts,
            COALESCE(ls.locked, 0) AS is_locked
        FROM user_devis_banamur u
        LEFT JOIN login_security ls ON ls.user_id = u.id
        ORDER BY u.nom, u.prenom
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $universalOverview = AccessManager::universalAccessOverview($pdo);
    $universalAccesses = array_values(array_filter(
        $universalOverview,
        static fn(array $row): bool => !empty($row['is_universal'])
    ));
    $universalSuggestions = array_values(array_filter(
        $universalOverview,
        static fn(array $row): bool => !empty($row['is_suggestion'])
    ));
    $availableUniversalOptions = array_values(array_filter(
        $universalOverview,
        static fn(array $row): bool => empty($row['is_universal'])
    ));
} catch (Throwable $e) {
    $users = [];
    $universalAccesses = [];
    $universalSuggestions = [];
    $availableUniversalOptions = [];
    $message = "Erreur lors du chargement des utilisateurs : " . $e->getMessage();
    $messageType = 'error';
}


AuditTrail::logPageConsultation([
    'module' => 'admin',
    'action' => 'gestion_acces_view',
    'entity_type' => 'access_management_page',
    'entity_label' => 'gestion_acces',
    'details' => [
        'user_count' => isset($users) && is_array($users) ? count($users) : 0,
        'load_status' => isset($messageType) && $messageType === 'error' ? 'error' : 'success',
    ],
], isset($pdo) && $pdo instanceof PDO ? $pdo : null);

AuditTrail::log('access_management_consulted', [
    'module' => 'admin',
    'action' => 'gestion_acces_consult',
    'entity_type' => 'access_management_page',
    'entity_label' => 'gestion_acces',
    'details' => [
        'user_count' => isset($users) && is_array($users) ? count($users) : 0,
        'load_status' => isset($messageType) && $messageType === 'error' ? 'error' : 'success',
    ],
], isset($pdo) && $pdo instanceof PDO ? $pdo : null);

$pageTitle = 'Gestion des Accès Utilisateurs';

require_once '../decaissement/includes/header.php';
require_once '../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-white to-purple-50">
    <div class="container mx-auto py-10 px-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-8">
            <div>
                <h1 class="text-4xl font-bold text-gray-800">Gestion des accès</h1>
                <p class="mt-2 text-gray-600">Attribuez et révisez les permissions des comptes.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="dashboard_admin.php" class="inline-flex items-center gap-2 px-5 py-3 bg-indigo-600 text-white rounded-xl shadow hover:bg-indigo-700 transition">
                    <i class="fas fa-tachometer-alt"></i>
                    Dashboard Admin
                </a>
                <a href="liste_users.php" class="inline-flex items-center gap-2 px-5 py-3 bg-white border border-gray-200 rounded-xl shadow-sm hover:shadow-md text-gray-700 transition">
                    <i class="fas fa-users"></i>
                    Liste des utilisateurs
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-6 p-4 rounded-xl <?= $messageType === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-700' : 'bg-red-50 border border-red-200 text-red-700' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <section class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-8">
            <div class="xl:col-span-2 bg-white rounded-2xl shadow-xl border border-indigo-100 p-6">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-indigo-50 text-indigo-700 text-xs font-bold uppercase tracking-wide">
                            <i class="fas fa-shield-halved"></i>
                            Gouvernance des modules
                        </div>
                        <h2 class="mt-3 text-2xl font-bold text-slate-900">Accès communs obligatoires</h2>
                        <p class="mt-2 text-sm text-slate-500 max-w-3xl">
                            Un module obligatoire est immédiatement activé pour tous les comptes et ne peut plus être retiré depuis une fiche utilisateur.
                        </p>
                    </div>

                    <form method="post" class="flex flex-col sm:flex-row gap-2 min-w-0 lg:min-w-[430px]">
                        <input type="hidden" name="action" value="set_universal_access">
                        <input type="hidden" name="enabled" value="1">
                        <select name="permission_key" required class="min-w-0 flex-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100">
                            <option value="">Choisir un module à rendre commun...</option>
                            <?php foreach ($availableUniversalOptions as $option): ?>
                                <option value="<?= htmlspecialchars((string) $option['permission']) ?>">
                                    <?= htmlspecialchars((string) $option['label']) ?> — <?= htmlspecialchars((string) $option['permission']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                            <i class="fas fa-lock"></i>
                            Rendre obligatoire
                        </button>
                    </form>
                </div>

                <div class="mt-6 grid gap-3 md:grid-cols-2">
                    <?php if (!$universalAccesses): ?>
                        <div class="md:col-span-2 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-5 text-sm text-slate-500">
                            Aucun module commun obligatoire n’est configuré.
                        </div>
                    <?php endif; ?>
                    <?php foreach ($universalAccesses as $access): ?>
                        <article class="flex items-center justify-between gap-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-4">
                            <div class="min-w-0">
                                <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) $access['label']) ?></div>
                                <div class="mt-1 text-xs text-slate-500">
                                    <?= htmlspecialchars((string) $access['permission']) ?> ·
                                    <?= (int) $access['granted_users'] ?>/<?= (int) $access['active_users'] ?> comptes actifs
                                </div>
                            </div>
                            <form method="post" onsubmit="return confirm('Ce module redeviendra configurable individuellement. Continuer ?');">
                                <input type="hidden" name="action" value="set_universal_access">
                                <input type="hidden" name="enabled" value="0">
                                <input type="hidden" name="permission_key" value="<?= htmlspecialchars((string) $access['permission']) ?>">
                                <button type="submit" class="inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700" title="Retirer le caractère obligatoire">
                                    <i class="fas fa-lock-open"></i>
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-xl border border-emerald-100 p-6">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Détection automatique</p>
                        <h2 class="mt-2 text-xl font-bold text-slate-900">Suggestions</h2>
                    </div>
                    <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl bg-emerald-100 text-emerald-700">
                        <i class="fas fa-wand-magic-sparkles"></i>
                    </span>
                </div>
                <p class="mt-3 text-sm text-slate-500">
                    Propositions uniquement lorsque tous les comptes actifs possèdent déjà le module.
                </p>

                <div class="mt-5 space-y-3">
                    <?php if (!$universalSuggestions): ?>
                        <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-sm text-slate-500">
                            Aucun autre module n’atteint actuellement 100 % de couverture.
                        </div>
                    <?php endif; ?>
                    <?php foreach ($universalSuggestions as $suggestion): ?>
                        <form method="post" class="rounded-xl border border-emerald-100 bg-emerald-50/70 p-4">
                            <input type="hidden" name="action" value="set_universal_access">
                            <input type="hidden" name="enabled" value="1">
                            <input type="hidden" name="permission_key" value="<?= htmlspecialchars((string) $suggestion['permission']) ?>">
                            <div class="font-semibold text-slate-900"><?= htmlspecialchars((string) $suggestion['label']) ?></div>
                            <div class="mt-1 text-xs text-emerald-700">
                                100 % · <?= (int) $suggestion['granted_users'] ?> compte(s) actif(s)
                            </div>
                            <button type="submit" class="mt-3 inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">
                                <i class="fas fa-check"></i>
                                Activer comme accès commun
                            </button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <h2 class="text-2xl font-bold text-gray-800">Liste des permissions</h2>
                <div class="relative w-full md:w-1/3">
                    <input id="permissionSearch" type="text" placeholder="Rechercher par nom ou email" class="w-full border border-gray-200 rounded-xl py-3 px-4 shadow-sm focus:outline-none focus:border-indigo-300 focus:ring-2 focus:ring-indigo-200" />
                    <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400"><i class="fas fa-search"></i></span>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-left">
                    <thead class="bg-gray-100 text-gray-600 text-sm uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">Utilisateur</th>
                            <th class="px-4 py-3">Statut</th>
                            <th class="px-4 py-3">Modules</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="permissionsTable" class="text-sm text-gray-700">
                        <?php foreach ($users as $user): ?>
                            <tr class="border-t hover:bg-gray-50">
                                <td class="px-4 py-4">
                                    <div class="font-semibold"><?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></div>
                                    <div class="text-xs text-gray-500"><?= htmlspecialchars($user['mail_pro']) ?></div>
                                </td>
                                <td class="px-4 py-4">
                                    <?php if (!empty($user['is_locked'])): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-orange-100 text-orange-700 text-xs font-semibold">
                                            Compte bloqué
                                        </span>
                                        <div class="text-xs text-gray-500 mt-1">
                                            Tentatives : <?= (int)$user['failed_attempts'] ?>/3
                                        </div>
                                    <?php elseif ($user['active']): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold">
                                            Actif
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-semibold">
                                            Inactif
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <?php if ($user['acces_dashboard']): ?>
                                            <span class="badge bg-indigo-100 text-indigo-700">Dashboard</span>
                                        <?php endif; ?>
                                        <?php if ($user['acces_devis']): ?>
                                            <span class="badge bg-blue-100 text-blue-700">Devis</span>
                                        <?php endif; ?>
                                        <?php if ($user['acces_decaissement']): ?>
                                            <span class="badge bg-green-100 text-green-700">Décaissement</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_feb'] ?? 0)): ?>
                                            <span class="badge bg-emerald-100 text-emerald-700">FEB</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_conformite'] ?? 0)): ?>
                                            <span class="badge bg-cyan-100 text-cyan-700">Conformite</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_approbation'] ?? 0)): ?>
                                            <span class="badge bg-purple-100 text-purple-700">Approbation Directeur</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_achat'] ?? 0)): ?>
                                            <span class="badge bg-amber-100 text-amber-700">Validation Achat</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_depenses_planifiees'] ?? 0)): ?>
                                            <span class="badge bg-sky-100 text-sky-700">Depenses Planifiees</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_tresorerie'] ?? 0)): ?>
                                            <span class="badge bg-lime-100 text-lime-700">Tresorerie</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_point_financier'] ?? 0)): ?>
                                            <span class="badge bg-indigo-100 text-indigo-700">Point financier</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_dashboard_stat_taches'] ?? 0)): ?>
                                            <span class="badge bg-amber-100 text-amber-700">Stats tâches</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_rh'])): ?>
                                            <span class="badge bg-pink-100 text-pink-700">RH</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_logistique'])): ?>
                                            <span class="badge bg-orange-100 text-orange-700">Logistique</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_ticketing'] ?? 0)): ?>
                                            <span class="badge bg-cyan-100 text-cyan-700">Tickets</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_planning_chantier'] ?? 0) || !empty($user['acces_planning'] ?? 0)): ?>
                                            <span class="badge bg-yellow-100 text-yellow-800">Planning Chantier</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_fournisseur'] ?? 0)): ?>
                                            <span class="badge bg-teal-100 text-teal-700">Fournisseurs</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_facturation'] ?? 0)): ?>
                                            <span class="badge bg-violet-100 text-violet-700">Facturation</span>
                                        <?php endif; ?>
                                        <?php if (!empty($user['acces_communication'] ?? 0)): ?>
                                            <span class="badge bg-gray-100 text-gray-700">Communication</span>
                                        <?php endif; ?>
                                        <?php if ($user['acces_gestion_acces']): ?>
                                            <span class="badge bg-red-100 text-red-700">Admin</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <a href="edit_user.php?id=<?= (int)$user['id'] ?>"
                                            class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition">
                                            <i class="fas fa-user-shield"></i>
                                            Gérer droits
                                        </a>

                                        <?php if (!empty($user['is_locked'])): ?>
                                            <form method="POST" onsubmit="return confirm('Voulez-vous vraiment lever le blocage de ce compte ?');">
                                                <input type="hidden" name="action" value="unlock_user">
                                                <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

                                                <button type="submit"
                                                    class="inline-flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-xl hover:bg-orange-700 transition">
                                                    <i class="fas fa-unlock"></i>
                                                    Lever blocage
                                                </button>
                                            </form>
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

<script>
    document.getElementById('permissionSearch').addEventListener('input', function() {
        const term = this.value.toLowerCase();
        document.querySelectorAll('#permissionsTable tr').forEach(row => {
            const name = row.querySelector('td:nth-child(1)').innerText.toLowerCase();
            const email = row.querySelector('td:nth-child(1) div:last-child').innerText.toLowerCase();
            row.style.display = name.includes(term) || email.includes(term) ? '' : 'none';
        });
    });
</script>
