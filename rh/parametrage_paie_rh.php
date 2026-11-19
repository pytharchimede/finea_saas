<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$controller = new \RhPayrollController($pdo);
$manager = $controller->service();

$message = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $controller->handleContractRulesPost($_POST, [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ]);
    $message = $postResult['message'];
    $errorMessage = $postResult['errorMessage'];
}

$contractPresets = $manager->getContractPresets();
$contractDescriptions = [
    'CDD' => 'Contrat a duree determinee avec prime de precarite automatique selon le parametrage.',
    'CDI' => 'Contrat permanent avec repos paye et valorisation plus forte des heures supplementaires.',
    'STAGE' => 'Convention de stage avec rythme reduit et heures supplementaires neutralisees par defaut.',
    'VACATAIRE' => 'Paiement a la vacation, conge non remunere par defaut et base flexible.',
    'AUTRE' => 'Profil libre pour les cas speciaux ou contrats internes non standards.',
];

function payrollSettingValue($value, int $decimals = 2): string
{
    return number_format((float) $value, $decimals, '.', '');
}

function payrollContractDescription(string $contractCode, array $contractDescriptions): string
{
    return $contractDescriptions[$contractCode] ?? 'Type de contrat cree par les RH. Ses coefficients sont appliques par l assistant de paie.';
}

$pageTitle = 'Parametrage paie RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(255,247,237,0.92),_transparent_35%),radial-gradient(circle_at_top_right,_rgba(224,242,254,0.90),_transparent_32%),linear-gradient(180deg,_#f8fafc_0%,_#fffaf5_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-amber-100 bg-gradient-to-r from-slate-950 via-slate-900 to-amber-900 px-8 py-8 text-white shadow-[0_24px_80px_rgba(15,23,42,0.28)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-amber-200">Parametrage central RH</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">Regles de paie par type de contrat</h1>
                    <p class="mt-4 text-sm text-amber-50 md:text-base">
                        Cet ecran pilote les coefficients utilises automatiquement par l'assistant de paie et les bulletins. Une fois valides ici, les reglages s'appliquent a tous les calculs RH.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="paie_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-amber-50">
                        <i class="fas fa-file-invoice-dollar mr-2 text-amber-600"></i>
                        Retour paie
                    </a>
                    <a href="../modules/payroll/views/admin.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-scale-balanced mr-2"></i>
                        Moteur de paie CI
                    </a>
                    <a href="dashboard_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-chart-line mr-2"></i>
                        Tableau de bord RH
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

        <div class="mt-8 rounded-[28px] border border-white/80 bg-white/92 px-6 py-5 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-600">Lecture</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-950">Comment interprete le moteur de paie</h2>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Les taux mission, conge, demi-journee, absence, maladie et repos vont de 0 a 1. Exemple : 0,5 = 50% de la journee payee.
                </div>
            </div>
        </div>

        <form method="post" class="mt-8 space-y-6">
            <?php foreach ($contractPresets as $contractCode => $preset): ?>
                <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <div class="flex items-center gap-3">
                                <h2 class="text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) $preset['label']) ?></h2>
                            </div>
                            <p class="mt-3 max-w-3xl text-sm text-slate-500">
                                <?= htmlspecialchars(payrollContractDescription((string) $contractCode, $contractDescriptions)) ?>
                            </p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            Ces valeurs sont utilisees automatiquement dans <a href="paie_rh.php" class="font-semibold text-amber-700 hover:text-amber-800">l'assistant de paie</a>.
                        </div>
                    </div>

                    <input type="hidden" name="rules[<?= htmlspecialchars($contractCode) ?>][contract_code]" value="<?= htmlspecialchars($contractCode) ?>">
                    <input type="hidden" name="rules[<?= htmlspecialchars($contractCode) ?>][is_active]" value="1">

                    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Nom du type de contrat</label>
                            <input type="text" name="rules[<?= htmlspecialchars($contractCode) ?>][label]" value="<?= htmlspecialchars((string) $preset['label']) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Jours de reference</label>
                            <input type="number" step="0.25" min="1" name="rules[<?= htmlspecialchars($contractCode) ?>][reference_working_days]" value="<?= htmlspecialchars(payrollSettingValue($preset['reference_working_days'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Heures par jour</label>
                            <input type="number" step="0.25" min="1" name="rules[<?= htmlspecialchars($contractCode) ?>][hours_per_day]" value="<?= htmlspecialchars(payrollSettingValue($preset['hours_per_day'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Majoration HS</label>
                            <input type="number" step="0.01" min="0" name="rules[<?= htmlspecialchars($contractCode) ?>][overtime_multiplier]" value="<?= htmlspecialchars(payrollSettingValue($preset['overtime_multiplier'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Prime precarite %</label>
                            <input type="number" step="0.01" min="0" name="rules[<?= htmlspecialchars($contractCode) ?>][precarity_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['precarity_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div class="rounded-2xl border border-amber-100 bg-amber-50 px-4 py-4 text-sm text-amber-800">
                            0 = non remunere, 1 = totalement remunere. Vous pouvez utiliser des valeurs intermediaires pour des demi-droits.
                        </div>
                    </div>

                    <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Mission payee</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][mission_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['mission_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Conge paye</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][conge_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['conge_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Demi-journee payee</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][half_day_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['half_day_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Absence payee</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][absence_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['absence_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Maladie payee</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][maladie_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['maladie_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Repos paye</label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[<?= htmlspecialchars($contractCode) ?>][repos_paid_rate]" value="<?= htmlspecialchars(payrollSettingValue($preset['repos_paid_rate'])) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-amber-400 focus:bg-white">
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

            <section class="rounded-[30px] border border-dashed border-amber-300 bg-amber-50/70 p-7">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-700">Nouveau</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Creer un type de contrat</h2>
                        <p class="mt-2 max-w-3xl text-sm text-slate-600">
                            Renseignez un nom et les coefficients. L application creera la reference interne automatiquement et le contrat apparaitra dans l assistant de paie.
                        </p>
                    </div>
                </div>
                <input type="hidden" name="rules[__NEW__][contract_code]" value="__NEW__">
                <input type="hidden" name="rules[__NEW__][is_active]" value="1">
                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Nom du type de contrat</label>
                        <input type="text" name="rules[__NEW__][label]" placeholder="Ex : Consultant, Apprenti, Journalier" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Jours de reference</label>
                        <input type="number" step="0.25" min="1" name="rules[__NEW__][reference_working_days]" value="30.00" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Heures par jour</label>
                        <input type="number" step="0.25" min="1" name="rules[__NEW__][hours_per_day]" value="8.00" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Majoration HS</label>
                        <input type="number" step="0.01" min="0" name="rules[__NEW__][overtime_multiplier]" value="1.00" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium text-slate-700">Prime precarite %</label>
                        <input type="number" step="0.01" min="0" name="rules[__NEW__][precarity_rate]" value="0.00" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                    </div>
                </div>
                <div class="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ([
                        'mission_paid_rate' => 'Mission payee',
                        'conge_paid_rate' => 'Conge paye',
                        'half_day_paid_rate' => 'Demi-journee payee',
                        'absence_paid_rate' => 'Absence payee',
                        'maladie_paid_rate' => 'Maladie payee',
                        'repos_paid_rate' => 'Repos paye',
                    ] as $field => $label): ?>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700"><?= htmlspecialchars($label) ?></label>
                            <input type="number" step="0.05" min="0" max="1" name="rules[__NEW__][<?= htmlspecialchars($field) ?>]" value="<?= $field === 'half_day_paid_rate' ? '0.50' : ($field === 'absence_paid_rate' || $field === 'maladie_paid_rate' || $field === 'repos_paid_rate' ? '0.00' : '1.00') ?>" class="w-full rounded-2xl border border-amber-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-amber-400">
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <div class="sticky bottom-4 z-10 flex flex-col gap-3 rounded-[28px] border border-slate-200 bg-white/95 px-6 py-5 shadow-[0_18px_50px_rgba(15,23,42,0.10)] backdrop-blur md:flex-row md:items-center md:justify-between">
                <p class="text-sm text-slate-500">
                    Les prochaines fiches de paie utiliseront immediatement ces coefficients par type de contrat.
                </p>
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-6 py-3 text-sm font-semibold text-white transition hover:-translate-y-0.5 hover:bg-slate-900">
                    <i class="fas fa-save mr-2"></i>
                    Enregistrer les regles RH
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
