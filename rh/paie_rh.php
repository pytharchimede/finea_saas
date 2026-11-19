<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../controllers/RhPayrollController.php';

$pdo = \Database::getConnection();
$controller = new \RhPayrollController($pdo);
$manager = $controller->service();
$viewData = $controller->payrollFormView(array_merge($_GET, $_POST));

$period = $viewData['period'];
$selectedPersonnelId = $viewData['selectedPersonnelId'];
$employees = $viewData['employees'];
$contractPresets = $viewData['contractPresets'];
$payrollSalaryItems = $viewData['payrollSalaryItems'] ?? [];
$contractPayrollView = $viewData['contractPayrollView'] ?? ['contract' => null, 'components' => [], 'adjustments' => [], 'version' => null];
$context = $viewData['context'];
$formData = $viewData['formData'];
$calculation = null;
$savedRecordId = null;
$message = null;
$errorMessage = $viewData['errorMessage'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postResult = $controller->handlePayrollPost($_POST, $formData, $period, $selectedPersonnelId, [
        'user_id' => $_SESSION['user_id'] ?? null,
        'user_name' => $_SESSION['user_name'] ?? null,
    ]);
    $period = $postResult['period'];
    $selectedPersonnelId = $postResult['selectedPersonnelId'];
    $context = $postResult['context'];
    $formData = $postResult['formData'];
    $payrollSalaryItems = $postResult['payrollSalaryItems'] ?? $payrollSalaryItems;
    $contractPayrollView = $postResult['contractPayrollView'] ?? $contractPayrollView;
    $calculation = $postResult['calculation'];
    $savedRecordId = $postResult['savedRecordId'];
    $message = $postResult['message'];
    $errorMessage = $postResult['errorMessage'] ?? $errorMessage;
}
$selectedContractCode = strtoupper((string) ($formData['contract_code'] ?? ($context['defaults']['contract_code'] ?? 'AUTRE')));
if (!isset($contractPresets[$selectedContractCode])) {
    $selectedContractCode = 'AUTRE';
}
$formData['contract_code'] = $selectedContractCode;
$selectedContractRules = $manager->getContractRules($selectedContractCode);

function payrollMoney($value): string
{
    return number_format((float) $value, 0, ',', ' ') . ' FCFA';
}

function payrollPercentLabel($value): string
{
    return number_format((float) $value * 100, 0, ',', ' ') . '%';
}

function payrollMonthLabel(string $period): string
{
    $date = DateTimeImmutable::createFromFormat('Y-m', $period);
    if (!$date) {
        return $period;
    }

    $months = [1 => 'janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
    return ucfirst($months[(int) $date->format('n')] ?? $period) . ' ' . $date->format('Y');
}

function payrollStepBadge(bool $active): string
{
    return $active
        ? 'border-slate-950 bg-slate-950 text-white shadow-lg shadow-slate-950/15'
        : 'border-slate-200 bg-white text-slate-500';
}

function payrollItemTypeLabel($value): string
{
    $labels = [
        'gain' => 'Gain',
        'allowance' => 'Allocation / prime',
        'benefit' => 'Avantage en nature',
        'deduction' => 'Retenue',
        'employer_contribution' => 'Charge employeur',
    ];

    $key = (string) ($value ?? '');
    return $labels[$key] ?? $key;
}

function payrollAdjustmentTypeLabel($value): string
{
    $labels = [
        'earning' => 'Gain exceptionnel',
        'deduction' => 'Retenue',
        'reminder' => 'Rappel',
        'regularization' => 'Regularisation',
        'advance' => 'Acompte',
    ];

    $key = (string) ($value ?? '');
    return $labels[$key] ?? $key;
}

function payrollAdjustmentStatusLabel($value): string
{
    $labels = [
        'pending' => 'A valider',
        'approved' => 'Valide',
        'rejected' => 'Refuse',
    ];

    $key = (string) ($value ?? '');
    return $labels[$key] ?? $key;
}

function payrollRhHelp(string $text): string
{
    return '<span class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-300 bg-white text-[11px] font-bold text-slate-500" title="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '">?</span>';
}

$attendanceView = $calculation['attendance_breakdown'] ?? ($context['attendance']['computed'] ?? null);
$attendanceSummary = $calculation['attendance_summary'] ?? ($context['attendance'] ?? null);

$pageTitle = 'Paie RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(254,242,242,0.92),_transparent_35%),radial-gradient(circle_at_top_right,_rgba(220,252,231,0.88),_transparent_28%),linear-gradient(180deg,_#f8fafc_0%,_#eef6ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-rose-100 bg-gradient-to-r from-slate-950 via-slate-900 to-rose-900 px-8 py-8 text-white shadow-[0_24px_80px_rgba(15,23,42,0.28)]">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.35em] text-rose-200">Paie RH</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight md:text-5xl">Nouvelle fiche de paie</h1>
                    <p class="mt-4 text-sm text-rose-50 md:text-base">
                        L'assistant vous guide du choix du salarie jusqu'au bulletin final, avec un prorata automatique sur les jours pointes, une valorisation automatique des heures supplementaires et des regles distinctes par statut de presence.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="validation_paie_rh.php?period=<?= urlencode($period) ?>" class="inline-flex items-center rounded-2xl bg-amber-300 px-5 py-3 text-sm font-semibold text-slate-950 transition hover:bg-amber-200"><i class="fas fa-file-signature mr-2"></i>Point de paie et signatures</a>
                    <a href="pointage_journalier_rh.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 transition hover:bg-rose-50">
                        <i class="fas fa-user-check mr-2 text-rose-600"></i>
                        Pointage journalier
                    </a>
                    <a href="parametrage_paie_rh.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10">
                        <i class="fas fa-sliders-h mr-2"></i>
                        Regles contrats
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
                <?php if ($savedRecordId !== null): ?>
                    <a href="bulletin_paie_rh.php?id=<?= (int) $savedRecordId ?>" class="ml-2 font-semibold underline">Ouvrir le bulletin</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-medium text-rose-700">
                <?= htmlspecialchars($errorMessage) ?>
            </div>
        <?php endif; ?>

        <div class="mt-8 grid gap-6 xl:grid-cols-[1.28fr_0.72fr]">
            <form method="post" id="payroll-wizard-form" class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                <div class="mb-8 flex flex-wrap gap-3">
                    <button type="button" class="wizard-pill rounded-full border px-4 py-2 text-sm font-semibold transition <?= payrollStepBadge(true) ?>" data-step-pill="1">1. Salarie</button>
                    <button type="button" class="wizard-pill rounded-full border px-4 py-2 text-sm font-semibold transition <?= payrollStepBadge(false) ?>" data-step-pill="2">2. Contrat</button>
                    <button type="button" class="wizard-pill rounded-full border px-4 py-2 text-sm font-semibold transition <?= payrollStepBadge(false) ?>" data-step-pill="3">3. Gains</button>
                    <button type="button" class="wizard-pill rounded-full border px-4 py-2 text-sm font-semibold transition <?= payrollStepBadge(false) ?>" data-step-pill="4">4. Retenues</button>
                </div>

                <section data-step-panel="1" class="wizard-panel space-y-6">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <div class="xl:col-span-2">
                            <label class="mb-2 block text-sm font-medium text-slate-700">Salarie</label>
                            <select name="personnel_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:bg-white" required>
                                <option value="">Selectionner un collaborateur</option>
                                <?php foreach ($employees as $employee): ?>
                                    <option value="<?= (int) $employee['id'] ?>" <?= (int) ($formData['personnel_id'] ?? 0) === (int) $employee['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $employee['full_name']) ?> - <?= htmlspecialchars((string) ($employee['matricule'] ?: 'Sans matricule')) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-slate-700">Periode</label>
                            <input type="month" name="period" value="<?= htmlspecialchars((string) ($formData['period'] ?? $period)) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-rose-400 focus:bg-white" required>
                        </div>
                    </div>

                    <?php if ($context !== null): ?>
                        <div class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <div class="flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Profil</p>
                                    <h2 class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) $context['employee']['full_name']) ?></h2>
                                    <p class="mt-2 text-sm text-slate-500">
                                        <?= htmlspecialchars((string) $context['employee']['matricule']) ?>
                                        ·
                                        <?= htmlspecialchars((string) $context['employee']['fonction_label']) ?>
                                        ·
                                        <?= htmlspecialchars((string) $context['employee']['service_label']) ?>
                                    </p>
                                </div>
                                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4 text-sm text-emerald-800">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-700">Pointage du mois</div>
                                    <div class="mt-2 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) ($attendanceView['paid_days'] ?? 0), 2, ',', ' ')) ?> jours payes</div>
                                    <div class="mt-1 text-xs text-slate-600">Reference mois : <?= htmlspecialchars((string) number_format((float) ($attendanceSummary['reference_working_days'] ?? $formData['reference_working_days'] ?? 0), 0, ',', ' ')) ?> jours ouvres</div>
                                    <div class="mt-1 text-sm text-slate-600"><?= htmlspecialchars((string) number_format((float) ($attendanceSummary['overtime_hours'] ?? 0), 2, ',', ' ')) ?> h supplementaires</div>
                                </div>
                            </div>

                            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div class="rounded-2xl border border-white bg-white px-4 py-4">
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Present</div>
                                    <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) (($attendanceSummary['status_days']['present'] ?? 0)), 2, ',', ' ')) ?></div>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4">
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Mission</div>
                                    <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) (($attendanceSummary['status_days']['mission'] ?? 0)), 2, ',', ' ')) ?></div>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4">
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Conge</div>
                                    <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) (($attendanceSummary['status_days']['conge'] ?? 0)), 2, ',', ' ')) ?></div>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4">
                                    <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Demi-journees</div>
                                    <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) ($attendanceSummary['status_counts']['half_day'] ?? 0)) ?></div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="rounded-[26px] border border-dashed border-slate-300 bg-slate-50 px-5 py-8 text-sm text-slate-500">
                            Selectionnez un collaborateur et une periode. L'assistant chargera ensuite le pointage mensuel, les heures supplementaires et les regles de paie associees.
                        </div>
                    <?php endif; ?>
                </section>

                <section data-step-panel="2" class="wizard-panel hidden space-y-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-rose-600">Contrat</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Choisissez le type de contrat et appliquez les regles centrales</h2>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        <?php foreach ($contractPresets as $code => $preset): ?>
                            <button type="button" class="contract-preset-card rounded-[24px] border border-slate-200 bg-slate-50 px-5 py-5 text-left transition hover:border-rose-300 hover:bg-rose-50" data-contract-code="<?= htmlspecialchars($code) ?>">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($preset['label']) ?></span>
                                </div>
                                <div class="mt-4 grid gap-2 text-xs text-slate-500">
                                    <div><?= htmlspecialchars((string) number_format((float) ($formData['reference_working_days'] ?? $preset['reference_working_days'] ?? 0), 0, ',', ' ')) ?> jours ouvres (periode)</div>
                                    <div>HS x <?= htmlspecialchars((string) number_format((float) $preset['overtime_multiplier'], 2, ',', ' ')) ?></div>
                                    <div>Prime de precarite automatique <?= htmlspecialchars((string) number_format((float) $preset['precarity_rate'], 0, ',', ' ')) ?>%</div>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Contrat actif</p>
                                    <h3 class="mt-2 text-xl font-semibold text-slate-950" data-contract-display="label"><?= htmlspecialchars((string) $selectedContractRules['label']) ?></h3>
                                    <p class="mt-2 max-w-xl text-sm text-slate-500">
                                        Les coefficients de paie sont desormais centralises. L'assistant les affiche mais ne les modifie plus localement.
                                    </p>
                                </div>
                                <a href="parametrage_paie_rh.php" class="inline-flex items-center rounded-2xl border border-rose-200 bg-white px-4 py-3 text-sm font-semibold text-rose-700 transition hover:border-rose-300 hover:bg-rose-50">
                                    <i class="fas fa-pen-ruler mr-2"></i>
                                    Parametrer
                                </a>
                            </div>

                            <div class="mt-6 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Contrat</label>
                                    <select name="contract_code" id="contract-code-select" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-rose-400">
                                        <?php foreach ($contractPresets as $contractCode => $contractPreset): ?>
                                            <option value="<?= htmlspecialchars($contractCode) ?>" <?= $selectedContractCode === $contractCode ? 'selected' : '' ?>><?= htmlspecialchars((string) ($contractPreset['label'] ?? $contractCode)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <div class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-4 text-sm text-rose-700">
                                        La paie du mois utilisera exactement les coefficients affiches a droite. Pour les modifier, passez par l'ecran de parametrage RH.
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Regles actives</p>
                            <div class="mt-5 grid gap-4 md:grid-cols-2">
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Jours ouvres (periode)</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string) number_format((float) ($formData['reference_working_days'] ?? $selectedContractRules['reference_working_days'] ?? 0), 0, ',', ' ')) ?> jours</p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Heures / jour</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="hours_per_day"><?= htmlspecialchars((string) number_format((float) $selectedContractRules['hours_per_day'], 2, ',', ' ')) ?> h</p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Majoration HS</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="overtime_multiplier">x <?= htmlspecialchars((string) number_format((float) $selectedContractRules['overtime_multiplier'], 2, ',', ' ')) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Prime de precarite automatique</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="precarity_rate"><?= htmlspecialchars((string) number_format((float) $selectedContractRules['precarity_rate'], 0, ',', ' ')) ?>%</p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Mission</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="mission_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['mission_paid_rate'])) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Conge</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="conge_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['conge_paid_rate'])) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Demi-journee</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="half_day_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['half_day_paid_rate'])) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Absence</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="absence_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['absence_paid_rate'])) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Maladie</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="maladie_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['maladie_paid_rate'])) ?></p>
                                </div>
                                <div class="rounded-2xl border border-white bg-white px-4 py-4 shadow-sm">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Repos</p>
                                    <p class="mt-2 text-lg font-semibold text-slate-950" data-contract-display="repos_paid_rate"><?= htmlspecialchars(payrollPercentLabel($selectedContractRules['repos_paid_rate'])) ?></p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <section class="rounded-[26px] border border-indigo-100 bg-white px-5 py-5">
                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Ajustements du mois</p>
                                <h3 class="mt-2 text-xl font-semibold text-slate-950">Ajustements exceptionnels du mois</h3>
                                <p class="mt-2 text-sm text-slate-500">Prime exceptionnelle, retenue, acompte, rappel ou regularisation. Seuls les ajustements valides entrent dans le bulletin.</p>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-4 md:grid-cols-[1.2fr_0.8fr_0.7fr_auto] md:items-end">
                            <input type="hidden" name="payroll_period" value="<?= htmlspecialchars((string) ($formData['period'] ?? $period)) ?>">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Libelle</label>
                                <input type="text" name="adjustment_label" value="" placeholder="Ex: Prime exceptionnelle" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Nature</label>
                                <select name="adjustment_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                    <option value="earning">Gain exceptionnel</option>
                                    <option value="deduction">Retenue</option>
                                    <option value="advance">Acompte</option>
                                    <option value="reminder">Rappel</option>
                                    <option value="regularization">Regularisation</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-2 block text-sm font-medium text-slate-700">Montant</label>
                                <input type="number" step="0.01" min="0" name="adjustment_amount" value="" placeholder="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                            </div>
                            <button type="submit" name="submit_action" value="save_payroll_adjustment" class="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700">
                                <i class="fas fa-plus mr-2"></i>
                                Ajouter
                            </button>
                        </div>

                        <?php $payrollAdjustments = is_array($contractPayrollView['adjustments'] ?? null) ? $contractPayrollView['adjustments'] : []; ?>
                        <?php if (!empty($payrollAdjustments)): ?>
                            <div class="mt-5 overflow-hidden rounded-2xl border border-slate-100">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase tracking-[0.16em] text-slate-500">
                                        <tr>
                                            <th class="px-4 py-3">Element</th>
                                            <th class="px-4 py-3">Nature</th>
                                            <th class="px-4 py-3">Montant</th>
                                            <th class="px-4 py-3">Statut</th>
                                            <th class="px-4 py-3 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($payrollAdjustments as $adjustment): ?>
                                            <tr>
                                                <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars((string) ($adjustment['label'] ?? 'Ajustement')) ?></td>
                                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(payrollAdjustmentTypeLabel($adjustment['adjustment_type'] ?? '')) ?></td>
                                                <td class="px-4 py-3 font-semibold text-slate-800"><?= htmlspecialchars(payrollMoney((float) ($adjustment['amount'] ?? 0))) ?></td>
                                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars(payrollAdjustmentStatusLabel($adjustment['status'] ?? '')) ?></td>
                                                <td class="px-4 py-3 text-right">
                                                    <?php if ((string) ($adjustment['status'] ?? '') === 'pending'): ?>
                                                        <button type="submit" name="submit_action" value="approve_payroll_adjustment" formaction="paie_rh.php" onclick="this.form.adjustment_id.value='<?= (int) ($adjustment['id'] ?? 0) ?>'" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Valider</button>
                                                        <button type="submit" name="submit_action" value="reject_payroll_adjustment" formaction="paie_rh.php" onclick="this.form.adjustment_id.value='<?= (int) ($adjustment['id'] ?? 0) ?>'" class="ml-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">Refuser</button>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400">Traite</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        <input type="hidden" name="adjustment_id" value="">
                    </section>
                </section>

                <section data-step-panel="3" class="wizard-panel hidden space-y-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Gains</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Remuneration contractuelle</h2>
                        <p class="mt-2 text-sm text-slate-500">Les montants renseignes ici constituent la remuneration contractuelle du salarie. Ils seront automatiquement repris dans les bulletins de paie de la periode concernee.</p>
                    </div>

                    <?php $activeContract = is_array($contractPayrollView['contract'] ?? null) ? $contractPayrollView['contract'] : null; ?>
                    <?php if ($activeContract === null): ?>
                        <div class="rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 text-sm font-semibold text-rose-700">
                            Aucun contrat actif trouve pour cet employe sur la periode. Le bulletin ne peut pas etre genere sans contrat RH actif.
                        </div>
                        <section class="rounded-[26px] border border-emerald-100 bg-white px-5 py-5">
                            <h3 class="text-xl font-semibold text-slate-950">Creer le contrat RH</h3>
                            <p class="mt-2 text-sm text-slate-500">Le salaire et les avantages recurrents doivent etre poses ici avant le calcul du bulletin.</p>
                            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Type de contrat</label>
                                    <select name="contract_reference" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                        <?php foreach ($contractPresets as $presetCode => $preset): ?>
                                            <option value="<?= htmlspecialchars((string) $presetCode) ?>" <?= strtoupper((string) ($formData['contract_code'] ?? '')) === strtoupper((string) $presetCode) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars((string) ($preset['label'] ?? $presetCode)) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Debut</label>
                                    <input type="date" name="start_date" value="<?= htmlspecialchars((string) (($formData['period'] ?? $period) . '-01')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Fin</label>
                                    <input type="date" name="end_date" value="" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Salaire categoriel</label>
                                    <input type="number" step="0.01" min="0" name="contract_base_salary" value="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Sursalaire</label>
                                    <input type="number" step="0.01" min="0" name="contract_over_salary" value="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Localite transport</label>
                                    <input type="text" name="transport_locality_code" value="" placeholder="Ex: ABIDJAN" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm uppercase outline-none transition focus:border-emerald-400">
                                </div>
                                <div class="md:col-span-2 xl:col-span-3">
                                    <button type="submit" name="submit_action" value="save_employee_contract" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                        <i class="fas fa-save mr-2"></i>
                                        Enregistrer le contrat RH
                                    </button>
                                </div>
                            </div>
                        </section>
                    <?php else: ?>
                        <section class="rounded-[26px] border border-emerald-100 bg-white px-5 py-5">
                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Contrat actif</p>
                                    <p class="mt-2 font-semibold text-slate-950"><?= htmlspecialchars((string) ($activeContract['contract_label'] ?? $activeContract['contract_reference'] ?? 'Contrat RH')) ?></p>
                                </div>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Validite</p>
                                    <p class="mt-2 font-semibold text-slate-950"><?= htmlspecialchars((string) ($activeContract['start_date'] ?? '-')) ?> - <?= htmlspecialchars((string) ($activeContract['end_date'] ?? 'En cours')) ?></p>
                                </div>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Salaire categoriel</p>
                                    <p class="mt-2 font-semibold text-slate-950"><?= payrollMoney((float) ($activeContract['base_salary'] ?? 0)) ?></p>
                                </div>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Sursalaire</p>
                                    <p class="mt-2 font-semibold text-slate-950"><?= payrollMoney((float) ($activeContract['over_salary'] ?? 0)) ?></p>
                                </div>
                                <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-slate-400">Localite transport</p>
                                    <p class="mt-2 font-semibold text-slate-950"><?= htmlspecialchars((string) ($activeContract['transport_locality_code'] ?? 'Non renseignee')) ?></p>
                                </div>
                            </div>
                            <details class="mt-5 rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                <summary class="cursor-pointer text-sm font-semibold text-slate-800">Modifier la remuneration contractuelle</summary>
                                <div class="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <input type="hidden" name="contract_id" value="<?= (int) ($activeContract['id'] ?? 0) ?>">
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Type de contrat</label>
                                        <select name="contract_reference" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                            <?php foreach ($contractPresets as $presetCode => $preset): ?>
                                                <option value="<?= htmlspecialchars((string) $presetCode) ?>" <?= strtoupper((string) ($activeContract['contract_reference'] ?? '')) === strtoupper((string) $presetCode) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars((string) ($preset['label'] ?? $presetCode)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Libelle contrat</label>
                                        <input type="text" name="contract_label" value="<?= htmlspecialchars((string) ($activeContract['contract_label'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Debut</label>
                                        <input type="date" name="start_date" value="<?= htmlspecialchars((string) ($activeContract['start_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Fin</label>
                                        <input type="date" name="end_date" value="<?= htmlspecialchars((string) ($activeContract['end_date'] ?? '')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Salaire categoriel</label>
                                        <input type="number" step="0.01" min="0" name="contract_base_salary" value="<?= htmlspecialchars((string) ($activeContract['base_salary'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Sursalaire</label>
                                        <input type="number" step="0.01" min="0" name="contract_over_salary" value="<?= htmlspecialchars((string) ($activeContract['over_salary'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div>
                                        <label class="mb-2 block text-sm font-medium text-slate-700">Localite transport</label>
                                        <input type="text" name="transport_locality_code" value="<?= htmlspecialchars((string) ($activeContract['transport_locality_code'] ?? '')) ?>" placeholder="Ex: ABIDJAN" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm uppercase outline-none transition focus:border-emerald-400">
                                    </div>
                                    <div class="md:col-span-2 xl:col-span-3">
                                        <button type="submit" name="submit_action" value="save_employee_contract" class="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                            <i class="fas fa-save mr-2"></i>
                                            Enregistrer le contrat RH
                                        </button>
                                    </div>
                                </div>
                            </details>
                        </section>
                    <?php endif; ?>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Categorie</label>
                                    <input type="text" name="category_code" value="<?= htmlspecialchars((string) ($formData['category_code'] ?? 'M2')) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Salaire categoriel</label>
                                    <input type="number" step="0.01" name="salary_category" value="<?= htmlspecialchars((string) ($formData['salary_category'] ?? 0)) ?>" readonly class="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 outline-none">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Sursalaire</label>
                                    <input type="number" step="0.01" name="over_salary" value="<?= htmlspecialchars((string) ($formData['over_salary'] ?? 0)) ?>" readonly class="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 outline-none">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Prime anciennete</label>
                                    <input type="number" step="0.01" name="seniority_bonus" value="<?= htmlspecialchars((string) ($formData['seniority_bonus'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Autres primes</label>
                                    <input type="number" step="0.01" name="other_bonus" value="<?= htmlspecialchars((string) ($formData['other_bonus'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Gratification</label>
                                    <input type="number" step="0.01" name="gratification" value="<?= htmlspecialchars((string) ($formData['gratification'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Prime conges payes</label>
                                    <input type="number" step="0.01" name="paid_leave_bonus" value="<?= htmlspecialchars((string) ($formData['paid_leave_bonus'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Precarite</label>
                                    <input type="number" step="0.01" name="precarity_bonus" value="<?= htmlspecialchars((string) ($formData['precarity_bonus'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                            </div>
                        </section>

                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Jours payes calcules</label>
                                    <input type="text" readonly value="<?= htmlspecialchars((string) number_format((float) ($formData['attendance_days'] ?? ($attendanceView['paid_days'] ?? 0)), 2, ',', ' ')) ?>" class="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 outline-none">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Heures supplementaires du mois</label>
                                    <input type="number" step="0.25" min="0" name="overtime_hours" value="<?= htmlspecialchars((string) ($formData['overtime_hours'] ?? ($attendanceSummary['overtime_hours'] ?? 0))) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div class="md:col-span-2 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4 text-sm text-emerald-800">
                                    Le pointage alimente automatiquement le prorata de salaire et les heures supplementaires. Vous pouvez ajuster les heures supplementaires ici si un correctif RH est necessaire avant edition du bulletin.
                                </div>
                            </div>
                        </section>
                    </div>

                    <?php
                    $standardPayrollItemCodes = [
                        'SAL_BASE' => true,
                        'SURSALAIRE' => true,
                        'PRIME_ANCIENNETE' => true,
                        'HEURES_SUP' => true,
                        'PRIME_RENDEMENT' => true,
                        'PRIME_CONGES_PAYES' => true,
                        'PRIME_FIN_ANNEE' => true,
                        'PRIME_PRECARITE' => true,
                        'PRIME_TRANSPORT' => true,
                    ];
                    $customPayrollItems = [];
                    foreach ($payrollSalaryItems as $code => $item) {
                        if (isset($standardPayrollItemCodes[(string) $code])) {
                            continue;
                        }
                        if (!in_array((string) ($item['category'] ?? ''), ['gain', 'allowance', 'benefit'], true)) {
                            continue;
                        }
                        $customPayrollItems[(string) $code] = $item;
                    }
                    $contractComponentItemOptions = [];
                    foreach ($payrollSalaryItems as $code => $item) {
                        if (in_array((string) ($item['category'] ?? ''), ['gain', 'allowance', 'benefit'], true)
                            && !in_array((string) $code, ['SAL_BASE', 'SURSALAIRE'], true)) {
                            $contractComponentItemOptions[(string) $code] = $item;
                        }
                    }
                    ?>
                    <?php if ($activeContract !== null && !empty($contractComponentItemOptions)): ?>
                        <section class="rounded-[26px] border border-slate-100 bg-white px-5 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Contrat RH</p>
                                    <h3 class="mt-2 text-xl font-semibold text-slate-950">Ajouter ou modifier une prime recurrente</h3>
                                    <p class="mt-2 text-sm text-slate-500">Le montant est rattache au contrat actif et sera repris automatiquement par le moteur de paie.</p>
                                </div>
                            </div>
                            <div class="mt-5 grid gap-4 md:grid-cols-[1.2fr_0.8fr_0.6fr_auto] md:items-end">
                                <input type="hidden" name="contract_id" value="<?= (int) ($activeContract['id'] ?? 0) ?>">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Rubrique</label>
                                    <select name="payroll_salary_item_id" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                        <option value="">Choisir une rubrique</option>
                                        <?php foreach ($contractComponentItemOptions as $code => $item): ?>
                                            <option value="<?= (int) ($item['id'] ?? 0) ?>"><?= htmlspecialchars((string) ($item['label'] ?? $code)) ?> - <?= htmlspecialchars(payrollItemTypeLabel($item['category'] ?? '')) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Montant mensuel</label>
                                    <input type="number" step="0.01" min="0" name="contract_component_amount" value="" placeholder="0" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Quantite</label>
                                    <input type="number" step="0.01" min="0" name="contract_component_quantity" value="1" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-emerald-400">
                                </div>
                                <button type="submit" name="submit_action" value="save_contract_salary_component" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    <i class="fas fa-plus mr-2"></i>
                                    Enregistrer
                                </button>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if (!empty($customPayrollItems)): ?>
                        <section class="rounded-[26px] border border-emerald-100 bg-white px-5 py-5">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Rubriques ajoutees</p>
                                    <h3 class="mt-2 text-xl font-semibold text-slate-950">Primes, indemnites et avantages recurrentiels</h3>
                                    <p class="mt-2 text-sm text-slate-500">
                                        Ces montants viennent du contrat RH du salarie. Ils ne sont pas une saisie ponctuelle de bulletin : modifiez-les dans la remuneration contractuelle.
                                    </p>
                                </div>
                                <a href="../modules/payroll/views/admin.php" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100">
                                    <i class="fas fa-plus-circle mr-2"></i>
                                    Gerer le contrat RH
                                </a>
                            </div>
                            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <?php foreach ($customPayrollItems as $code => $item): ?>
                                    <?php $customAmount = is_array($formData['custom_salary_items'] ?? null) ? ($formData['custom_salary_items'][$code] ?? '') : ''; ?>
                                    <div class="rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4">
                                        <label class="mb-2 block text-sm font-semibold text-slate-800"><?= htmlspecialchars((string) ($item['label'] ?? $code)) ?></label>
                                        <div class="mb-3 text-xs text-slate-500"><?= htmlspecialchars(payrollItemTypeLabel($item['category'] ?? '')) ?></div>
                                        <input type="number" step="0.01" min="0" name="custom_salary_items[<?= htmlspecialchars((string) $code) ?>]" value="<?= htmlspecialchars((string) $customAmount) ?>" readonly placeholder="Montant contractuel" class="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 outline-none">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </section>

                <section data-step-panel="4" class="wizard-panel hidden space-y-6">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Retenues</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Charges, retenues et validation finale</h2>
                    </div>

                    <div class="grid gap-6 xl:grid-cols-2">
                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <div class="mb-4">
                                <h3 class="text-base font-semibold text-slate-950">Parametres sociaux et fiscaux</h3>
                                <p class="mt-1 text-sm text-slate-500">Ces valeurs sont proposees par le parametrage de paie et peuvent etre controlees avant calcul.</p>
                            </div>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Taux IS salarial (%)<?= payrollRhHelp('Taux utilise pour la part salariale de l impot sur salaire dans ce calcul.') ?></label>
                                    <input type="number" step="0.01" name="tax_is_rate" value="<?= htmlspecialchars((string) ($formData['tax_is_rate'] ?? 1.2)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">CNPS salariale (%)<?= payrollRhHelp('Part CNPS retenue sur le salaire du collaborateur.') ?></label>
                                    <input type="number" step="0.01" name="cnps_employee_rate" value="<?= htmlspecialchars((string) ($formData['cnps_employee_rate'] ?? 6.3)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">CNPS patronale (%)<?= payrollRhHelp('Charge CNPS supportee par l employeur.') ?></label>
                                    <input type="number" step="0.01" name="cnps_employer_rate" value="<?= htmlspecialchars((string) ($formData['cnps_employer_rate'] ?? 7.7)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Prestations familiales employeur (%)<?= payrollRhHelp('Charge patronale de prestations familiales. Ce n est pas une allocation versee directement au salarie dans ce champ.') ?></label>
                                    <input type="number" step="0.01" name="family_benefit_rate" value="<?= htmlspecialchars((string) ($formData['family_benefit_rate'] ?? 5.75)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Accident travail (%)<?= payrollRhHelp('Charge employeur liee au risque accident du travail.') ?></label>
                                    <input type="number" step="0.01" name="accident_rate" value="<?= htmlspecialchars((string) ($formData['accident_rate'] ?? 5)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Taxe apprentissage (%)<?= payrollRhHelp('Contribution employeur liee a l apprentissage.') ?></label>
                                    <input type="number" step="0.01" name="apprenticeship_tax_rate" value="<?= htmlspecialchars((string) ($formData['apprenticeship_tax_rate'] ?? 0.4)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Formation professionnelle (%)<?= payrollRhHelp('Contribution employeur dediee a la formation professionnelle.') ?></label>
                                    <input type="number" step="0.01" name="professional_training_tax_rate" value="<?= htmlspecialchars((string) ($formData['professional_training_tax_rate'] ?? 0.6)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">FDFP (%)<?= payrollRhHelp('Contribution au Fonds de developpement de la formation professionnelle.') ?></label>
                                    <input type="number" step="0.01" name="formation_tax_rate" value="<?= htmlspecialchars((string) ($formData['formation_tax_rate'] ?? 0.6)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Parts fiscales<?= payrollRhHelp('Nombre de parts utilise pour le calcul de la reduction familiale.') ?></label>
                                    <input type="number" step="0.5" min="1" name="fiscal_parts" value="<?= htmlspecialchars((string) ($formData['fiscal_parts'] ?? 1)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">IGR manuel<?= payrollRhHelp('Montant manuel a utiliser seulement si une validation RH impose de remplacer le calcul automatique.') ?></label>
                                    <input type="number" step="0.01" name="manual_igr" value="<?= htmlspecialchars((string) ($formData['manual_igr'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                            </div>
                        </section>

                        <section class="rounded-[26px] border border-slate-100 bg-slate-50 px-5 py-5">
                            <?php
                            $debtSummary = null;
                            if (is_array($context ?? null)) {
                                $debtSummary = $context['debt_summary'] ?? null;
                            }
                            $hasDebtBox = is_array($debtSummary)
                                && (
                                    (bool) (($debtSummary['advance']['exists'] ?? false))
                                    || (bool) (($debtSummary['loan']['exists'] ?? false))
                                );
                            ?>

                            <input type="hidden" name="advance_amount_touched" id="advance_amount_touched" value="<?= htmlspecialchars((string) ($formData['advance_amount_touched'] ?? '0')) ?>">
                            <input type="hidden" name="other_deductions_touched" id="other_deductions_touched" value="<?= htmlspecialchars((string) ($formData['other_deductions_touched'] ?? '0')) ?>">

                            <?php if ($hasDebtBox): ?>
                                <div class="mb-5 rounded-2xl border border-slate-200 bg-white px-4 py-4">
                                    <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Dettes en cours</div>
                                    <div class="mt-3 grid gap-3 md:grid-cols-2">
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Solde avance</div>
                                            <div class="mt-1 text-lg font-semibold text-slate-950">
                                                <?php if (($debtSummary['advance']['exists'] ?? false) && (($debtSummary['advance']['remaining_amount'] ?? 0) > 0)): ?>
                                                    <?= htmlspecialchars(payrollMoney((float) ($debtSummary['advance']['remaining_amount'] ?? 0))) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </div>
                                            <?php if (($debtSummary['advance']['exists'] ?? false) && (($debtSummary['advance']['recurring_amount'] ?? 0) > 0)): ?>
                                                <div class="mt-1 text-xs text-slate-600">Mensualité: <?= htmlspecialchars(payrollMoney((float) ($debtSummary['advance']['recurring_amount'] ?? 0))) ?></div>
                                            <?php endif; ?>
                                            <?php if (($debtSummary['advance']['exists'] ?? false) && !empty($debtSummary['advance']['start_period'])): ?>
                                                <div class="mt-1 text-xs text-slate-500 debt-start-row" data-debt-key="advance" data-start-period="<?= htmlspecialchars((string) $debtSummary['advance']['start_period']) ?>">Débute en <?= htmlspecialchars(payrollMonthLabel((string) $debtSummary['advance']['start_period'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Solde pret</div>
                                            <div class="mt-1 text-lg font-semibold text-slate-950">
                                                <?php if (($debtSummary['loan']['exists'] ?? false) && (($debtSummary['loan']['remaining_amount'] ?? 0) > 0)): ?>
                                                    <?= htmlspecialchars(payrollMoney((float) ($debtSummary['loan']['remaining_amount'] ?? 0))) ?>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </div>
                                            <?php if (($debtSummary['loan']['exists'] ?? false) && (($debtSummary['loan']['recurring_amount'] ?? 0) > 0)): ?>
                                                <div class="mt-1 text-xs text-slate-600">Mensualité: <?= htmlspecialchars(payrollMoney((float) ($debtSummary['loan']['recurring_amount'] ?? 0))) ?></div>
                                            <?php endif; ?>
                                            <?php if (($debtSummary['loan']['exists'] ?? false) && !empty($debtSummary['loan']['start_period'])): ?>
                                                <div class="mt-1 text-xs text-slate-500 debt-start-row" data-debt-key="loan" data-start-period="<?= htmlspecialchars((string) $debtSummary['loan']['start_period']) ?>">Débute en <?= htmlspecialchars(payrollMonthLabel((string) $debtSummary['loan']['start_period'])) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Prime transport</label>
                                    <input type="text" readonly value="Reprise du contrat RH / localite" class="w-full rounded-2xl border border-slate-200 bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 outline-none">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Assurance maladie</label>
                                    <input type="number" step="0.01" name="health_insurance" value="<?= htmlspecialchars((string) ($formData['health_insurance'] ?? 1000)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Avance</label>
                                    <input type="number" step="0.01" name="advance_amount" id="advance_amount" value="<?= htmlspecialchars((string) ($formData['advance_amount'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Autres retenues</label>
                                    <input type="number" step="0.01" name="other_deductions" id="other_deductions" value="<?= htmlspecialchars((string) ($formData['other_deductions'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-slate-700">Arrondi</label>
                                    <input type="number" step="0.01" name="rounding_amount" value="<?= htmlspecialchars((string) ($formData['rounding_amount'] ?? 0)) ?>" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400">
                                </div>
                            </div>

                            <div class="mt-6">
                                <label class="mb-2 block text-sm font-medium text-slate-700">Observations</label>
                                <textarea name="observations" rows="4" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400"><?= htmlspecialchars((string) ($formData['observations'] ?? '')) ?></textarea>
                            </div>
                        </section>
                    </div>
                </section>

                <div class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 pt-6">
                    <button type="button" id="wizard-prev" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Etape precedente
                    </button>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" id="wizard-next" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            Etape suivante
                            <i class="fas fa-arrow-right ml-2"></i>
                        </button>
                        <button type="submit" name="submit_action" value="calculate" id="wizard-calculate" class="hidden inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                            <i class="fas fa-calculator mr-2"></i>
                            Calculer le bulletin
                        </button>
                        <button type="submit" name="submit_action" value="save" id="wizard-save" class="hidden inline-flex items-center rounded-2xl bg-rose-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-rose-700">
                            <i class="fas fa-save mr-2"></i>
                            Enregistrer la fiche
                        </button>
                    </div>
                </div>
            </form>

            <aside class="space-y-6">
                <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-slate-500">Apercu de paie</p>
                    <?php if ($calculation !== null && $context !== null): ?>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars((string) $context['employee']['full_name']) ?></h2>
                        <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars(payrollMonthLabel((string) $calculation['period'])) ?></p>
                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-4">
                                <div class="text-xs uppercase tracking-[0.22em] text-emerald-700">Brut imposable</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars(payrollMoney($calculation['gross_taxable'])) ?></div>
                            </div>
                            <div class="rounded-2xl border border-rose-100 bg-rose-50 px-4 py-4">
                                <div class="text-xs uppercase tracking-[0.22em] text-rose-700">Net a payer</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-950"><?= htmlspecialchars(payrollMoney($calculation['net_salary'])) ?></div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4">
                                <div class="text-xs uppercase tracking-[0.22em] text-slate-500">Retenues salariales</div>
                                <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars(payrollMoney($calculation['total_employee_deductions'])) ?></div>
                            </div>
                            <div class="rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-4">
                                <div class="text-xs uppercase tracking-[0.22em] text-indigo-700">Charges employeur</div>
                                <div class="mt-2 text-xl font-semibold text-slate-950"><?= htmlspecialchars(payrollMoney($calculation['total_employer_contributions'])) ?></div>
                            </div>
                        </div>

                        <div class="mt-6 space-y-3 text-sm text-slate-600">
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Jours payes retenus</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) $calculation['attendance_days'], 2, ',', ' ')) ?></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Jours non payes</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) ($calculation['attendance_breakdown']['unpaid_days'] ?? 0), 2, ',', ' ')) ?></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Heures supplementaires</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) $calculation['overtime_hours'], 2, ',', ' ')) ?> h</span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Valorisation HS</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars(payrollMoney($calculation['overtime_amount'] ?? 0)) ?></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Impact pointage</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars(payrollMoney($calculation['attendance_deduction'] ?? 0)) ?></span>
                            </div>
                        </div>

                        <div class="mt-6 rounded-[24px] border border-amber-100 bg-amber-50 px-5 py-5">
                            <div class="text-xs font-semibold uppercase tracking-[0.24em] text-amber-700">Regles appliquees</div>
                            <div class="mt-4 grid gap-3 text-sm text-slate-700">
                                <div class="flex items-center justify-between"><span>Mission</span><strong><?= htmlspecialchars((string) number_format((float) ($calculation['mission_paid_rate'] * 100), 0, ',', ' ')) ?>%</strong></div>
                                <div class="flex items-center justify-between"><span>Conge</span><strong><?= htmlspecialchars((string) number_format((float) ($calculation['conge_paid_rate'] * 100), 0, ',', ' ')) ?>%</strong></div>
                                <div class="flex items-center justify-between"><span>Demi-journee</span><strong><?= htmlspecialchars((string) number_format((float) ($calculation['half_day_paid_rate'] * 100), 0, ',', ' ')) ?>%</strong></div>
                                <div class="flex items-center justify-between"><span>Absence</span><strong><?= htmlspecialchars((string) number_format((float) ($calculation['absence_paid_rate'] * 100), 0, ',', ' ')) ?>%</strong></div>
                            </div>
                        </div>

                        <?php if ($savedRecordId !== null): ?>
                            <a href="bulletin_paie_rh.php?id=<?= (int) $savedRecordId ?>" class="mt-6 inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                <i class="fas fa-print mr-2"></i>
                                Imprimer le bulletin
                            </a>
                        <?php endif; ?>
                    <?php elseif ($context !== null): ?>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Apercu du pointage</h2>
                        <p class="mt-4 text-sm text-slate-500">L'assistant est precharge avec les jours payes et les heures supplementaires du pointage. Finalisez les etapes pour obtenir le bulletin.</p>
                        <div class="mt-6 space-y-3 text-sm text-slate-600">
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Jours payes estimes</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) ($attendanceView['paid_days'] ?? 0), 2, ',', ' ')) ?></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Jours non payes</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) ($attendanceView['unpaid_days'] ?? 0), 2, ',', ' ')) ?></span>
                            </div>
                            <div class="flex items-center justify-between rounded-2xl bg-slate-50 px-4 py-3">
                                <span>Heures supplementaires</span>
                                <span class="font-semibold text-slate-900"><?= htmlspecialchars((string) number_format((float) ($attendanceSummary['overtime_hours'] ?? 0), 2, ',', ' ')) ?> h</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Apercu du bulletin</h2>
                        <p class="mt-4 text-sm text-slate-500">Choisissez un collaborateur, laissez l'assistant appliquer le profil contrat, puis calculez la fiche de paie avec le pointage mensuel et les heures supplementaires.</p>
                    <?php endif; ?>
                </section>

                <?php if ($context !== null && !empty($context['history'])): ?>
                    <section class="rounded-[30px] border border-white/80 bg-white/92 p-7 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
                        <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-600">Historique</p>
                        <h2 class="mt-2 text-2xl font-semibold text-slate-950">Dernieres paies</h2>
                        <div class="mt-5 space-y-3">
                            <?php foreach ($context['history'] as $historyRow): ?>
                                <a href="bulletin_paie_rh.php?id=<?= (int) $historyRow['id'] ?>" class="flex items-center justify-between rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4 transition hover:border-emerald-200 hover:bg-emerald-50/70">
                                    <div>
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars(payrollMonthLabel((string) $historyRow['payroll_period'])) ?></div>
                                        <div class="mt-1 text-xs text-slate-500">Mis a jour le <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $historyRow['updated_at']))) ?></div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-semibold text-slate-900"><?= htmlspecialchars(payrollMoney($historyRow['net_salary'])) ?></div>
                                        <div class="mt-1 text-xs text-emerald-700">Ouvrir</div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
            </aside>
        </div>
    </div>
</div>

<script>
    (function() {
        var currentStep = 1;
        var maxStep = 4;
        var panels = document.querySelectorAll('[data-step-panel]');
        var pills = document.querySelectorAll('[data-step-pill]');
        var prevButton = document.getElementById('wizard-prev');
        var nextButton = document.getElementById('wizard-next');
        var calculateButton = document.getElementById('wizard-calculate');
        var saveButton = document.getElementById('wizard-save');

        var debtSummary = <?php echo json_encode((is_array($context ?? null) ? ($context['debt_summary'] ?? null) : null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        var periodInput = document.querySelector('input[name="period"]');

        function updateWizard() {
            panels.forEach(function(panel) {
                panel.classList.toggle('hidden', Number(panel.getAttribute('data-step-panel')) !== currentStep);
            });

            pills.forEach(function(pill) {
                var isActive = Number(pill.getAttribute('data-step-pill')) === currentStep;
                pill.className = 'wizard-pill rounded-full border px-4 py-2 text-sm font-semibold transition ' + (isActive ?
                    'border-slate-950 bg-slate-950 text-white shadow-lg shadow-slate-950/15' :
                    'border-slate-200 bg-white text-slate-500');
            });

            prevButton.disabled = currentStep === 1;
            prevButton.classList.toggle('opacity-50', currentStep === 1);
            nextButton.classList.toggle('hidden', currentStep === maxStep);
            calculateButton.classList.toggle('hidden', currentStep !== maxStep);
            saveButton.classList.toggle('hidden', currentStep !== maxStep);

            if (currentStep === 4) {
                applyDebtDefaultsFromSummary();
            }
        }

        function toNumber(value) {
            var n = Number(value || 0);
            return isFinite(n) ? n : 0;
        }

        function isPeriodActive(selectedPeriod, startPeriod) {
            if (!startPeriod) {
                return true;
            }
            if (!selectedPeriod) {
                return false;
            }
            return String(selectedPeriod) >= String(startPeriod);
        }

        function computeSuggested(debtRow) {
            if (!debtRow) {
                return 0;
            }
            var remaining = toNumber(debtRow.remaining_amount);
            var recurring = toNumber(debtRow.recurring_amount);
            if (remaining <= 0) {
                return 0;
            }
            if (recurring <= 0) {
                return 0;
            }
            return Math.min(remaining, recurring);
        }

        function applyDebtDefaultsFromSummary() {
            if (!debtSummary || !periodInput) {
                return;
            }

            var selectedPeriod = String(periodInput.value || '');

            var advanceRow = debtSummary.advance || null;
            var loanRow = debtSummary.loan || null;

            document.querySelectorAll('.debt-start-row').forEach(function(node) {
                var key = node.getAttribute('data-debt-key');
                var start = node.getAttribute('data-start-period') || '';
                var active = isPeriodActive(selectedPeriod, start);
                node.classList.toggle('hidden', active);
            });

            var advanceInput = document.getElementById('advance_amount');
            var otherInput = document.getElementById('other_deductions');
            var advanceTouchedEl = document.getElementById('advance_amount_touched');
            var otherTouchedEl = document.getElementById('other_deductions_touched');

            if (advanceInput && advanceTouchedEl && String(advanceTouchedEl.value || '0') !== '1') {
                var startA = String((advanceRow && advanceRow.start_period) || '');
                if (isPeriodActive(selectedPeriod, startA)) {
                    var suggestedA = computeSuggested(advanceRow);
                    if (suggestedA > 0 && toNumber(advanceInput.value) <= 0) {
                        advanceInput.value = String(suggestedA);
                    }
                }
            }

            if (otherInput && otherTouchedEl && String(otherTouchedEl.value || '0') !== '1') {
                var startL = String((loanRow && loanRow.start_period) || '');
                if (isPeriodActive(selectedPeriod, startL)) {
                    var suggestedL = computeSuggested(loanRow);
                    if (suggestedL > 0 && toNumber(otherInput.value) <= 0) {
                        otherInput.value = String(suggestedL);
                    }
                }
            }
        }

        prevButton.addEventListener('click', function() {
            if (currentStep > 1) {
                currentStep -= 1;
                updateWizard();
            }
        });

        nextButton.addEventListener('click', function() {
            if (currentStep < maxStep) {
                currentStep += 1;
                updateWizard();
            }
        });

        pills.forEach(function(pill) {
            pill.addEventListener('click', function() {
                currentStep = Number(pill.getAttribute('data-step-pill'));
                updateWizard();
            });
        });

        var presets = <?= json_encode($contractPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var contractSelect = document.getElementById('contract-code-select');
        var presetCards = document.querySelectorAll('.contract-preset-card');
        var ruleDisplays = document.querySelectorAll('[data-contract-display]');

        function highlightPreset(contractCode) {
            presetCards.forEach(function(card) {
                var active = card.getAttribute('data-contract-code') === contractCode;
                card.classList.toggle('border-rose-400', active);
                card.classList.toggle('bg-rose-50', active);
                card.classList.toggle('border-slate-200', !active);
                card.classList.toggle('bg-slate-50', !active);
            });
        }

        function formatNumber(value, digits, suffix, prefix) {
            var number = Number(value || 0);
            return (prefix || '') + number.toLocaleString('fr-FR', {
                minimumFractionDigits: digits,
                maximumFractionDigits: digits
            }) + (suffix || '');
        }

        function updateRuleSummary(contractCode) {
            if (!presets[contractCode]) {
                return;
            }

            var selected = presets[contractCode];
            ruleDisplays.forEach(function(node) {
                var key = node.getAttribute('data-contract-display');
                switch (key) {
                    case 'label':
                        node.textContent = selected.label;
                        break;
                    case 'hours_per_day':
                        node.textContent = formatNumber(selected.hours_per_day, 2, ' h');
                        break;
                    case 'overtime_multiplier':
                        node.textContent = formatNumber(selected.overtime_multiplier, 2, '', 'x ');
                        break;
                    case 'precarity_rate':
                        node.textContent = formatNumber(selected.precarity_rate, 0, '%');
                        break;
                    default:
                        node.textContent = formatNumber((selected[key] || 0) * 100, 0, '%');
                        break;
                }
            });
        }

        function applyPreset(contractCode) {
            if (!presets[contractCode]) {
                return;
            }

            if (contractSelect) {
                contractSelect.value = contractCode;
            }

            updateRuleSummary(contractCode);
            highlightPreset(contractCode);
        }

        var advanceInput = document.getElementById('advance_amount');
        var otherInput = document.getElementById('other_deductions');
        var advanceTouchedEl = document.getElementById('advance_amount_touched');
        var otherTouchedEl = document.getElementById('other_deductions_touched');

        if (advanceInput && advanceTouchedEl) {
            advanceInput.addEventListener('input', function() {
                advanceTouchedEl.value = '1';
            });
        }
        if (otherInput && otherTouchedEl) {
            otherInput.addEventListener('input', function() {
                otherTouchedEl.value = '1';
            });
        }

        if (periodInput) {
            periodInput.addEventListener('change', function() {
                applyDebtDefaultsFromSummary();
            });
        }

        applyDebtDefaultsFromSummary();

        presetCards.forEach(function(card) {
            card.addEventListener('click', function() {
                applyPreset(card.getAttribute('data-contract-code'));
            });
        });

        contractSelect?.addEventListener('change', function() {
            applyPreset(contractSelect.value);
        });

        applyPreset(contractSelect?.value || 'AUTRE');
        updateWizard();
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
