<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../model/RhMatriculeManager.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';

$pdo = \Database::getConnection();
new \PersonnelDirectory($pdo);
$manager = new \RhMatriculeManager($pdo);

$actor = [
    'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
    'user_name' => isset($_SESSION['user_name']) ? trim((string) $_SESSION['user_name']) : null,
];

$statusType = null;
$statusMessage = null;
$applyResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['confirm_remaniement'])) {
            throw new RuntimeException('Cochez la confirmation avant de valider le remaniement.');
        }

        $applyResult = $manager->applyRemaniement($actor);
        \AuditTrail::log('rh_matricules_remaniement_applied', [
            'module' => 'admin',
            'action' => 'remaniement_matricules',
            'entity_type' => 'personnel',
            'entity_label' => 'Remaniement matricules RH',
            'details' => [
                'run_id' => $applyResult['run_id'] ?? null,
                'updated' => $applyResult['updated'] ?? 0,
                'email_sent' => $applyResult['email_sent'] ?? 0,
                'email_failed' => $applyResult['email_failed'] ?? 0,
            ],
        ], $pdo);

        $statusType = 'success';
        $statusMessage = ((int) ($applyResult['updated'] ?? 0)) . ' matricule(s) mis a jour. Emails RH envoyes: '
            . ((int) ($applyResult['email_sent'] ?? 0)) . ', echecs: ' . ((int) ($applyResult['email_failed'] ?? 0)) . '.';
    } catch (Throwable $e) {
        \AuditTrail::log('rh_matricules_remaniement_failed', [
            'module' => 'admin',
            'action' => 'remaniement_matricules',
            'entity_type' => 'personnel',
            'entity_label' => 'Remaniement matricules RH',
            'result' => 'failed',
            'severity' => 'error',
            'details' => ['message' => $e->getMessage()],
        ], $pdo);

        $statusType = 'error';
        $statusMessage = $e->getMessage();
    }
}

$preview = $manager->buildPreview();
$rows = $preview['rows'];

function rh_matricule_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$pageTitle = 'Remaniement matricules RH';
$activeMenu = '';
require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50">
    <div class="mx-auto max-w-7xl px-4 py-8">
        <div class="rounded-[28px] bg-slate-950 px-7 py-8 text-white shadow-xl">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-300">Administration RH</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-4xl">Remaniement des matricules</h1>
                    <p class="mt-3 max-w-3xl text-sm leading-7 text-slate-300">
                        Regle appliquee : matricule par type de contrat, puis index d'arrivee selon la date d'integration.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white hover:bg-white/10">
                        <i class="fas fa-arrow-left mr-2"></i>Dashboard admin
                    </a>
                    <a href="../rh/liste_personnel_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-950 hover:bg-amber-50">
                        <i class="fas fa-id-badge mr-2 text-amber-600"></i>Liste du personnel
                    </a>
                </div>
            </div>
        </div>

        <?php if ($statusType !== null): ?>
            <div class="mt-6 rounded-2xl border px-5 py-4 text-sm font-semibold <?= $statusType === 'success' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-rose-200 bg-rose-50 text-rose-800' ?>">
                <i class="fas <?= $statusType === 'success' ? 'fa-check-circle text-emerald-500' : 'fa-exclamation-circle text-rose-500' ?> mr-2"></i>
                <?= rh_matricule_h($statusMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-6 grid gap-4 md:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Personnel</p>
                <p class="mt-2 text-3xl font-bold text-slate-950"><?= number_format((int) $preview['total']) ?></p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-amber-700">A modifier</p>
                <p class="mt-2 text-3xl font-bold text-amber-800"><?= number_format((int) $preview['changed']) ?></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-700">Deja corrects</p>
                <p class="mt-2 text-3xl font-bold text-emerald-800"><?= number_format((int) $preview['unchanged']) ?></p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Format</p>
                <p class="mt-2 text-lg font-bold text-slate-950"><?= rh_matricule_h($preview['rules']['format'] ?? '') ?></p>
            </div>
        </div>

        <section class="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-xl font-semibold text-slate-950">Previsualisation avant validation</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Tri : <?= rh_matricule_h($preview['rules']['order'] ?? '') ?>.
                    </p>
                </div>
                <form method="post" class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                        <input type="checkbox" name="confirm_remaniement" value="1" class="h-4 w-4 rounded border-slate-300 text-slate-900">
                        Je confirme cette numerotation
                    </label>
                    <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                        <i class="fas fa-check mr-2"></i>Valider et notifier RH
                    </button>
                </form>
            </div>
        </section>

        <section class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-100 text-xs uppercase tracking-[0.12em] text-slate-600">
                        <tr>
                            <th class="px-4 py-3 text-left">Collaborateur</th>
                            <th class="px-4 py-3 text-left">Contrat</th>
                            <th class="px-4 py-3 text-left">Integration</th>
                            <th class="px-4 py-3 text-left">Ancien</th>
                            <th class="px-4 py-3 text-left">Nouveau</th>
                            <th class="px-4 py-3 text-left">Etat</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php foreach ($rows as $row): ?>
                            <tr class="<?= !empty($row['changed']) ? 'bg-amber-50/60' : '' ?>">
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-950"><?= rh_matricule_h($row['full_name']) ?></div>
                                    <div class="mt-1 text-xs text-slate-500"><?= rh_matricule_h($row['service_label']) ?> · <?= rh_matricule_h($row['fonction_label']) ?></div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?= rh_matricule_h($row['status_label']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-slate-700"><?= rh_matricule_h($row['integration_date']) ?></td>
                                <td class="px-4 py-3 font-mono text-slate-500"><?= rh_matricule_h($row['old_matricule'] ?: '-') ?></td>
                                <td class="px-4 py-3 font-mono font-bold text-slate-950"><?= rh_matricule_h($row['new_matricule']) ?></td>
                                <td class="px-4 py-3">
                                    <?php if (!empty($row['changed'])): ?>
                                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">A appliquer</span>
                                    <?php else: ?>
                                        <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?>
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-slate-500">Aucun collaborateur trouve.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
