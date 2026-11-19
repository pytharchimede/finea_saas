<?php

declare(strict_types=1);

require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/AccessMatrixService.php';

$pdo = Database::getConnection();
$csrfToken = $_SESSION['access_matrix_csrf'] ?? bin2hex(random_bytes(24));
$_SESSION['access_matrix_csrf'] = $csrfToken;

function accessMatrixRespond(bool $ok, string $message, array $payload = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string) ($_SESSION['access_matrix_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expirée. Rechargez la page.');
        }
        if (($_POST['action'] ?? '') !== 'toggle_permission') {
            throw new RuntimeException('Action inconnue.');
        }

        $userId = (int) ($_POST['user_id'] ?? 0);
        $permission = trim((string) ($_POST['permission'] ?? ''));
        $enabled = (string) ($_POST['enabled'] ?? '0') === '1';
        $meta = AccessMatrixService::setPermission($pdo, $userId, $permission, $enabled);

        AuditTrail::log('admin_access_matrix_toggle', [
            'module' => 'admin',
            'action' => 'toggle_user_permission',
            'entity_type' => 'user_account',
            'entity_id' => (string) $userId,
            'entity_label' => $permission,
            'result' => 'success',
            'new_value' => ['enabled' => $enabled ? 1 : 0],
        ], $pdo);

        accessMatrixRespond(true, $enabled ? 'Accès accordé.' : 'Accès retiré.', [
            'user_id' => $userId,
            'permission' => $permission,
            'enabled' => $enabled,
            'label' => $meta['label'],
        ]);
    } catch (Throwable $e) {
        accessMatrixRespond(false, $e->getMessage());
    }
}

$query = trim((string) ($_GET['q'] ?? ''));
$statusInput = (string) ($_GET['status'] ?? 'all');
$status = in_array($statusInput, ['all', 'active', 'inactive'], true) ? $statusInput : 'all';
$group = trim((string) ($_GET['group'] ?? ''));
$catalogAll = AccessMatrixService::catalog($pdo);
$groups = array_values(array_unique(array_column($catalogAll, 'group')));
sort($groups);
$catalog = $group !== ''
    ? array_filter($catalogAll, static fn(array $permission): bool => $permission['group'] === $group)
    : $catalogAll;
$users = AccessMatrixService::users($pdo, $query, $status);
$stats = AccessMatrixService::stats($users, $catalogAll);
$universalCount = count(array_filter($catalogAll, static fn(array $permission): bool => $permission['universal']));

$exportParams = http_build_query(array_filter([
    'q' => $query,
    'status' => $status !== 'all' ? $status : null,
    'group' => $group !== '' ? $group : null,
]));

AuditTrail::logPageConsultation([
    'module' => 'admin',
    'action' => 'access_matrix_view',
    'entity_type' => 'access_matrix',
    'entity_label' => 'Matrice globale des accès',
    'details' => ['users' => count($users), 'permissions' => count($catalog)],
], $pdo);

$pageTitle = 'Matrice globale des accès';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50">
    <main class="mx-auto max-w-[1900px] px-4 py-8">
        <section class="overflow-hidden rounded-[2rem] bg-slate-950 text-white shadow-2xl">
            <div class="grid gap-8 p-7 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <span class="inline-flex items-center gap-2 rounded-full border border-cyan-300/30 bg-cyan-300/10 px-3 py-1 text-xs font-bold uppercase tracking-[0.18em] text-cyan-100">
                        <i class="fas fa-table-cells-large"></i> Gouvernance FINEA
                    </span>
                    <h1 class="mt-4 text-3xl font-black md:text-4xl">Matrice globale des accès</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-300">Visualisez et pilotez tous les droits de tous les comptes FINEA. Chaque modification est appliquée immédiatement et tracée.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="export_access_matrix_excel.php<?= $exportParams ? '?' . htmlspecialchars($exportParams) : '' ?>" class="inline-flex h-12 items-center gap-2 rounded-xl bg-emerald-500 px-5 text-sm font-black text-white hover:bg-emerald-400">
                        <i class="fas fa-file-excel"></i> Excel premium
                    </a>
                    <a target="_blank" href="export_access_matrix_pdf.php<?= $exportParams ? '?' . htmlspecialchars($exportParams) : '' ?>" class="inline-flex h-12 items-center gap-2 rounded-xl bg-rose-500 px-5 text-sm font-black text-white hover:bg-rose-400">
                        <i class="fas fa-file-pdf"></i> PDF premium
                    </a>
                    <a href="gestion_acces.php" class="inline-flex h-12 items-center gap-2 rounded-xl border border-white/20 px-5 text-sm font-bold hover:bg-white/10">
                        <i class="fas fa-shield-halved"></i> Accès communs
                    </a>
                </div>
            </div>
            <div class="h-1.5 bg-gradient-to-r from-cyan-400 via-emerald-400 to-amber-300"></div>
        </section>

        <section class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            <?php
            $cards = [
                ['Comptes affichés', $stats['users'], 'fa-users', 'text-indigo-700', 'bg-indigo-50'],
                ['Comptes actifs', $stats['active_users'], 'fa-user-check', 'text-emerald-700', 'bg-emerald-50'],
                ['Droits recensés', $stats['permissions'], 'fa-key', 'text-cyan-700', 'bg-cyan-50'],
                ['Accès obligatoires', $universalCount, 'fa-lock', 'text-amber-700', 'bg-amber-50'],
                ['Couverture globale', number_format($stats['coverage'], 1, ',', ' ') . ' %', 'fa-chart-pie', 'text-violet-700', 'bg-violet-50'],
            ];
            foreach ($cards as [$label, $value, $icon, $color, $background]):
            ?>
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500"><?= htmlspecialchars((string) $label) ?></p><p class="mt-2 text-3xl font-black text-slate-950"><?= htmlspecialchars((string) $value) ?></p></div>
                        <span class="inline-flex h-11 w-11 items-center justify-center rounded-xl <?= $background ?> <?= $color ?>"><i class="fas <?= $icon ?>"></i></span>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <form method="get" class="grid gap-3 lg:grid-cols-[minmax(260px,1fr)_220px_260px_auto_auto] lg:items-end">
                <label><span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Utilisateur</span><input name="q" value="<?= htmlspecialchars($query) ?>" placeholder="Nom, email ou rôle..." class="mt-1 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100"></label>
                <label><span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Statut</span><select name="status" class="mt-1 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm"><option value="all">Tous</option><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Actifs</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactifs</option></select></label>
                <label><span class="text-xs font-bold uppercase tracking-[0.14em] text-slate-500">Famille de droits</span><select name="group" class="mt-1 h-12 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm"><option value="">Toutes les familles</option><?php foreach ($groups as $groupOption): ?><option value="<?= htmlspecialchars($groupOption) ?>" <?= $group === $groupOption ? 'selected' : '' ?>><?= htmlspecialchars($groupOption) ?></option><?php endforeach; ?></select></label>
                <button class="h-12 rounded-xl bg-slate-900 px-5 text-sm font-black text-white hover:bg-slate-800"><i class="fas fa-filter mr-2"></i>Appliquer</button>
                <a href="access_matrix.php" class="inline-flex h-12 items-center justify-center rounded-xl border border-slate-200 px-5 text-sm font-bold text-slate-600 hover:bg-slate-50"><i class="fas fa-rotate-left mr-2"></i>Réinitialiser</a>
            </form>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header class="flex flex-col gap-3 border-b border-slate-200 px-5 py-4 md:flex-row md:items-center md:justify-between">
                <div><h2 class="text-lg font-black text-slate-950">Droits par utilisateur</h2><p class="mt-1 text-sm text-slate-500">Cliquez sur une cellule pour accorder ou retirer un droit. Les cadenas signalent les accès communs obligatoires.</p></div>
                <div class="flex items-center gap-4 text-xs font-bold text-slate-600"><span><i class="fas fa-circle-check mr-1 text-emerald-500"></i>Accordé</span><span><i class="fas fa-circle-xmark mr-1 text-slate-300"></i>Non accordé</span><span><i class="fas fa-lock mr-1 text-amber-500"></i>Obligatoire</span></div>
            </header>
            <div class="max-h-[72vh] overflow-auto">
                <table class="border-separate border-spacing-0 text-sm">
                    <thead class="sticky top-0 z-30 bg-slate-100">
                        <tr>
                            <th class="sticky left-0 z-40 min-w-[280px] border-b border-r border-slate-200 bg-slate-100 px-4 py-3 text-left text-xs font-black uppercase tracking-[0.12em] text-slate-600">Utilisateur</th>
                            <?php foreach ($catalog as $permission): ?>
                                <th class="min-w-[128px] border-b border-r border-slate-200 px-2 py-3 text-center align-bottom">
                                    <span class="block text-[9px] font-black uppercase text-slate-400"><?= htmlspecialchars($permission['group']) ?></span>
                                    <span class="mt-1 block text-[11px] font-bold leading-4 text-slate-800"><?= htmlspecialchars($permission['label']) ?></span>
                                    <?php if ($permission['universal']): ?><i class="fas fa-lock mt-1 text-[10px] text-amber-500" title="Accès commun obligatoire"></i><?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$users): ?><tr><td colspan="<?= count($catalog) + 1 ?>" class="p-10 text-center text-slate-500">Aucun utilisateur ne correspond aux filtres.</td></tr><?php endif; ?>
                        <?php foreach ($users as $user): $userId = (int) $user['id']; ?>
                            <tr class="group hover:bg-indigo-50/30">
                                <td class="sticky left-0 z-20 border-b border-r border-slate-200 bg-white px-4 py-3 group-hover:bg-indigo-50">
                                    <div class="flex items-center gap-3">
                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-900 text-xs font-black text-white"><?= htmlspecialchars(strtoupper(substr((string) ($user['prenom'] ?? ''), 0, 1) . substr((string) ($user['nom'] ?? ''), 0, 1))) ?></span>
                                        <div class="min-w-0"><div class="truncate font-black text-slate-950"><?= htmlspecialchars(trim((string) ($user['prenom'] ?? '') . ' ' . (string) ($user['nom'] ?? ''))) ?></div><div class="truncate text-xs text-slate-500"><?= htmlspecialchars((string) ($user['mail_pro'] ?? '')) ?></div><div class="mt-1 text-[10px] font-bold <?= !empty($user['active']) ? 'text-emerald-600' : 'text-rose-500' ?>"><?= !empty($user['active']) ? 'ACTIF' : 'INACTIF' ?><?= !empty($user['role_label']) ? ' · ' . htmlspecialchars((string) $user['role_label']) : '' ?></div></div>
                                    </div>
                                </td>
                                <?php foreach ($catalog as $permission): $enabled = !empty($user[$permission['key']]); ?>
                                    <td class="border-b border-r border-slate-100 p-2 text-center">
                                        <button type="button"
                                            data-access-toggle
                                            data-user-id="<?= $userId ?>"
                                            data-permission="<?= htmlspecialchars($permission['key']) ?>"
                                            data-enabled="<?= $enabled ? '1' : '0' ?>"
                                            <?= $permission['universal'] ? 'disabled' : '' ?>
                                            title="<?= htmlspecialchars($permission['label'] . ($permission['universal'] ? ' — obligatoire' : '')) ?>"
                                            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border transition <?= $permission['universal'] ? 'cursor-not-allowed border-amber-200 bg-amber-50 text-amber-600' : ($enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'border-slate-200 bg-slate-50 text-slate-300 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-500') ?>">
                                            <i class="fas <?= $permission['universal'] ? 'fa-lock' : ($enabled ? 'fa-check' : 'fa-minus') ?>"></i>
                                        </button>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrfToken = <?= json_encode($csrfToken) ?>;
    function toast(message, error) {
        var old = document.querySelector('[data-access-toast]');
        if (old) old.remove();
        var node = document.createElement('div');
        node.dataset.accessToast = '1';
        node.className = 'fixed bottom-5 right-5 z-[9999] max-w-sm rounded-xl px-5 py-4 text-sm font-black text-white shadow-2xl ' + (error ? 'bg-rose-600' : 'bg-slate-950');
        node.textContent = message;
        document.body.appendChild(node);
        setTimeout(function () { node.remove(); }, 2600);
    }
    document.querySelectorAll('[data-access-toggle]').forEach(function (button) {
        button.addEventListener('click', async function () {
            if (button.disabled || button.dataset.busy === '1') return;
            var current = button.dataset.enabled === '1';
            button.dataset.busy = '1';
            button.classList.add('opacity-50');
            var body = new URLSearchParams({
                action: 'toggle_permission',
                csrf_token: csrfToken,
                user_id: button.dataset.userId,
                permission: button.dataset.permission,
                enabled: current ? '0' : '1'
            });
            try {
                var response = await fetch(window.location.href, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body});
                var data = await response.json();
                if (!data.ok) throw new Error(data.message || 'Action impossible.');
                var enabled = !!data.enabled;
                button.dataset.enabled = enabled ? '1' : '0';
                button.className = 'inline-flex h-9 w-9 items-center justify-center rounded-lg border transition ' + (enabled ? 'border-emerald-200 bg-emerald-50 text-emerald-600 hover:bg-emerald-100' : 'border-slate-200 bg-slate-50 text-slate-300 hover:border-indigo-200 hover:bg-indigo-50 hover:text-indigo-500');
                button.innerHTML = '<i class="fas ' + (enabled ? 'fa-check' : 'fa-minus') + '"></i>';
                toast(data.message, false);
            } catch (error) {
                toast(error.message || 'Action impossible.', true);
            } finally {
                button.dataset.busy = '0';
                button.classList.remove('opacity-50');
            }
        });
    });
});
</script>
