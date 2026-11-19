<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$controller = new \RhPayrollController($pdo);
$manager = $controller->service();

$holidayView = $controller->holidayView(array_merge($_GET, $_POST));
$period = $holidayView['period'];
$startDate = $holidayView['startDate'];
$endDate = $holidayView['endDate'];
$message = $holidayView['message'];
$errorMessage = $holidayView['errorMessage'];

$auditHoliday = static function (string $eventType, array $context = []) use ($period): void {
    \AuditTrail::log($eventType, array_merge([
        'module' => 'rh',
        'entity_type' => 'holiday',
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ], $context));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'add') {
            $holidayDate = (string) ($_POST['holiday_date'] ?? '');
            $label = trim((string) ($_POST['label'] ?? ''));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
                throw new RuntimeException('La date du férié est invalide.');
            }

            $controller->saveHoliday($holidayDate, $label);

            $auditHoliday('rh_holiday_saved', [
                'action' => 'add',
                'entity_label' => $label !== '' ? $label : $holidayDate,
                'details' => [
                    'period' => $period,
                    'holiday_date' => $holidayDate,
                    'label' => $label !== '' ? $label : null,
                ],
            ]);

            header('Location: feries_rh.php?period=' . urlencode($period) . '&msg=' . urlencode('Férié enregistré.'));
            exit();
        }

        if ($action === 'delete') {
            $holidayId = (int) ($_POST['holiday_id'] ?? 0);
            if ($holidayId <= 0) {
                throw new RuntimeException('Férié introuvable.');
            }

            $controller->deleteHoliday($holidayId);

            $auditHoliday('rh_holiday_deleted', [
                'action' => 'delete',
                'entity_id' => (string) $holidayId,
                'entity_label' => 'holiday_' . $holidayId,
                'details' => [
                    'period' => $period,
                    'holiday_id' => $holidayId,
                ],
            ]);

            header('Location: feries_rh.php?period=' . urlencode($period) . '&msg=' . urlencode('Férié supprimé.'));
            exit();
        }

        throw new RuntimeException('Action non supportée.');
    } catch (Throwable $exception) {
        $auditHoliday('rh_holiday_failed', [
            'action' => (string) ($_POST['action'] ?? ''),
            'result' => 'failure',
            'severity' => 'warning',
            'details' => [
                'period' => $period,
                'error' => $exception->getMessage(),
            ],
        ]);
        header('Location: feries_rh.php?period=' . urlencode($period) . '&err=' . urlencode($exception->getMessage()));
        exit();
    }
}

$holidays = $holidayView['holidays'];

$referenceWorkingDays = $manager->getReferenceWorkingDaysForPeriod($period);

$pageTitle = 'Fériés RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(236,253,245,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(191,219,254,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-emerald-100 bg-gradient-to-r from-slate-950 via-slate-900 to-emerald-900 px-8 py-10 text-white shadow-[0_24px_80px_rgba(15,23,42,0.32)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-[0.35em] text-emerald-200">Ressources humaines</p>
                    <h1 class="text-3xl font-semibold tracking-tight md:text-5xl">Gestion des fériés</h1>
                    <p class="mt-4 max-w-2xl text-sm text-slate-200 md:text-base">
                        Les dates enregistrées ici sont exclues des jours ouvrés (lun→ven) pour le calcul de la paie et pour le bouton “Pointer tout le mois (jours ouvrés)”.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-emerald-50">
                        <i class="fas fa-chart-line mr-2 text-emerald-600"></i>
                        Tableau de bord RH
                    </a>
                    <a href="pointage_journalier_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-user-check mr-2"></i>
                        Pointage
                    </a>
                </div>
            </div>
        </div>

        <?php if ($message !== null): ?>
            <div class="mt-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm font-medium text-emerald-700">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1fr_1fr]">
            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Période</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Sélection du mois</h2>
                        <p class="mt-3 text-sm text-slate-500">Référence jours ouvrés (mois) : <span class="font-semibold text-slate-900"><?= (int) $referenceWorkingDays ?></span></p>
                    </div>
                    <form method="get" class="flex items-end gap-3">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Mois</label>
                            <input type="month" name="period" value="<?= htmlspecialchars($period) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white" required>
                        </div>
                        <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            <i class="fas fa-search mr-2"></i>
                            Charger
                        </button>
                    </form>
                </div>

                <div class="mt-6 rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm text-emerald-800">
                    Astuce : si un férié tombe un samedi/dimanche, il n’impacte pas les jours ouvrés (déjà exclus).
                </div>
            </section>

            <section class="rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Ajout</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Enregistrer un férié</h2>
                    <p class="mt-3 text-sm text-slate-500">Date + libellé (optionnel). Le même jour peut être mis à jour.</p>
                </div>

                <form method="post" class="mt-6 grid gap-4 sm:grid-cols-3">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">

                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Date</label>
                        <input type="date" name="holiday_date" value="<?= htmlspecialchars($startDate) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:bg-white" required>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="mb-2 block text-sm font-medium text-slate-700">Libellé</label>
                        <input type="text" name="label" placeholder="Ex: Fête du travail" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:bg-white">
                    </div>

                    <div class="sm:col-span-3">
                        <button type="submit" class="inline-flex items-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                            <i class="fas fa-plus mr-2"></i>
                            Ajouter / Mettre à jour
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <section class="mt-8 rounded-[30px] border border-white/80 bg-white/90 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-400">Liste</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Fériés du mois</h2>
                    <p class="mt-3 text-sm text-slate-500"><?= htmlspecialchars($startDate) ?> → <?= htmlspecialchars($endDate) ?></p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                    Total : <span class="font-semibold"><?= (int) count($holidays) ?></span>
                </div>
            </div>

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-slate-500">
                            <th class="px-3 py-3 font-medium">Date</th>
                            <th class="px-3 py-3 font-medium">Libellé</th>
                            <th class="px-3 py-3 font-medium">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($holidays) === 0): ?>
                            <tr>
                                <td colspan="3" class="px-3 py-6 text-sm text-slate-500">Aucun férié enregistré pour cette période.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($holidays as $holiday): ?>
                                <tr class="border-b border-slate-100 last:border-b-0">
                                    <td class="px-3 py-3 font-semibold text-slate-900"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $holiday['holiday_date']))) ?></td>
                                    <td class="px-3 py-3 text-slate-700"><?= htmlspecialchars((string) ($holiday['label'] ?? '')) ?></td>
                                    <td class="px-3 py-3">
                                        <form method="post" onsubmit="return confirm('Supprimer ce férié ?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
                                            <input type="hidden" name="holiday_id" value="<?= (int) $holiday['id'] ?>">
                                            <button type="submit" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                                                <i class="fas fa-trash mr-2"></i>
                                                Supprimer
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
