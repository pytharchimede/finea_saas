<?php
require_once dirname(__DIR__, 3) . '/auth_check.php';
\AccessManager::requirePageAccess();
require_once dirname(__DIR__, 3) . '/model/Database.php';
require_once dirname(__DIR__, 3) . '/decaissement/model/PayrollManager.php';
require_once dirname(__DIR__) . '/bootstrap.php';

$pdo = \Database::getConnection();
$controller = new PayrollController($pdo);
$contractManager = new PayrollManager($pdo);
$message = null;
$errorMessage = null;
$bulkReport = null;
$selectedVersionId = (int) ($_POST['version_id'] ?? $_GET['version_id'] ?? 0);

function payrollAdminChecked($value): string
{
    return (int) $value === 1 ? 'checked' : '';
}

function payrollAdminValue($value): string
{
    return htmlspecialchars((string) ($value ?? ''));
}

function payrollAdminLabel($value): string
{
    $labels = [
        'gain' => 'Gain',
        'allowance' => 'Allocation / prime',
        'benefit' => 'Avantage en nature',
        'deduction' => 'Retenue',
        'employer_contribution' => 'Charge employeur',
        'fixed_amount' => 'Montant fixe',
        'computed' => 'Calcule automatiquement',
        'monthly' => 'Plafond mensuel',
        'hourly_smig_multiple' => 'Multiple du SMIG horaire',
        'percent_total_remuneration' => 'Pourcentage de la remuneration',
        'SBI' => 'Salaire brut imposable',
        'SBS' => 'Salaire brut social',
        'GROSS' => 'Salaire brut',
        'SMIG_HOURLY' => 'SMIG horaire',
        'TAX_ABATEMENT_RATE' => 'Abattement avant impot',
        'RICF_MAX_PARTS' => 'Nombre maximum de parts RICF',
        'RICF_UNIT_AMOUNT' => 'Montant RICF par demi-part',
        'decimal' => 'Nombre',
        'string' => 'Texte',
        'NONE' => 'Aucune',
        'FIXED_AMOUNT' => 'Montant fixe',
        'HOURLY_SMIG_MULTIPLE' => 'Multiple du SMIG horaire',
        'STAGE_ALLOWANCE' => 'Indemnite de stage',
        'APPRENTICESHIP_ALLOWANCE' => 'Indemnite d apprentissage',
        'TOTAL' => 'Exoneration totale',
        'TOTAL_REMUNERATION_PERCENT' => 'Pourcentage de remuneration',
        'generated' => 'Genere',
        'skipped' => 'Ignore',
        'failed' => 'Erreur',
        'draft' => 'Brouillon',
        'cancelled' => 'Annule',
        'rule_version_created' => 'Version creee',
        'period_locked' => 'Periode cloturee',
        'salary_item_saved' => 'Rubrique enregistree',
        'tax_bracket_saved' => 'Tranche d impot enregistree',
        'social_contribution_saved' => 'Cotisation enregistree',
        'transport_rate_saved' => 'Transport enregistre',
        'parameter_saved' => 'Parametre enregistre',
        'success' => 'Reussi',
        'failure' => 'Echec',
    ];

    $key = (string) ($value ?? '');
    return $labels[$key] ?? $key;
}

function payrollAdminOptions(string $type): array
{
    $options = [
        'salary_category' => [
            'gain' => 'Gain imposable ou salaire',
            'allowance' => 'Allocation / prime',
            'benefit' => 'Avantage en nature',
            'deduction' => 'Retenue salariale',
            'employer_contribution' => 'Charge employeur',
        ],
        'exemption_type' => [
            'NONE' => 'Aucune exoneration',
            'FIXED_AMOUNT' => 'Exoneration avec plafond fixe',
            'HOURLY_SMIG_MULTIPLE' => 'Exoneration limitee a un multiple du SMIG horaire',
            'STAGE_ALLOWANCE' => 'Exoneration d indemnite de stage',
            'APPRENTICESHIP_ALLOWANCE' => 'Exoneration d indemnite d apprentissage',
            'TOTAL' => 'Exoneration totale',
            'TOTAL_REMUNERATION_PERCENT' => 'Exoneration limitee a un pourcentage de remuneration',
        ],
        'ceiling_type' => [
            '' => 'Aucun plafond',
            'monthly' => 'Plafond mensuel',
            'hourly_smig_multiple' => 'Multiple du SMIG horaire',
            'percent_total_remuneration' => 'Pourcentage de la remuneration',
        ],
        'calculation_mode' => [
            'fixed_amount' => 'Montant saisi',
            'computed' => 'Calcule automatiquement',
        ],
        'calculation_base' => [
            'SBI' => 'Salaire brut imposable',
            'SBS' => 'Salaire brut social',
            'GROSS' => 'Salaire brut',
        ],
        'value_type' => [
            'decimal' => 'Nombre',
            'string' => 'Texte',
        ],
    ];

    return $options[$type] ?? [];
}

function payrollAdminSelect(string $name, $selectedValue, array $options, string $class = 'rounded-lg border px-3 py-2 text-sm'): string
{
    $selected = (string) ($selectedValue ?? '');
    $html = '<select name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
    $hasSelected = false;
    foreach ($options as $value => $label) {
        $value = (string) $value;
        $isSelected = $value === $selected;
        $hasSelected = $hasSelected || $isSelected;
        $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . ($isSelected ? ' selected' : '') . '>' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    if (!$hasSelected && $selected !== '') {
        $html .= '<option value="' . htmlspecialchars($selected, ENT_QUOTES, 'UTF-8') . '" selected>' . htmlspecialchars(payrollAdminLabel($selected), ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html . '</select>';
}

function payrollAdminPercent($value): string
{
    return number_format((float) $value * 100, 0, ',', ' ') . ' %';
}

function payrollAdminHelp(string $text): string
{
    return '<span class="ml-1 inline-flex h-5 w-5 items-center justify-center rounded-full border border-slate-300 bg-white text-[11px] font-bold text-slate-500" title="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '">?</span>';
}

function payrollAdminImpactFiscal(array $item): string
{
    if (empty($item['taxable_sbi']) || (string) ($item['exemption_type'] ?? '') === 'TOTAL') {
        return 'Non';
    }
    if ((string) ($item['exemption_type'] ?? 'NONE') !== 'NONE') {
        return 'Partiel';
    }

    return 'Oui';
}

function payrollAdminImpactSocial(array $item): string
{
    return !empty($item['taxable_sbs']) || !empty($item['subject_to_cnps']) || !empty($item['subject_to_cmu']) ? 'Oui' : 'Non';
}

function payrollAdminExemptionMode(array $item): string
{
    $type = (string) ($item['exemption_type'] ?? 'NONE');
    $ceilingAmount = $item['ceiling_amount'] ?? null;
    $formattedAmount = $ceilingAmount !== null && $ceilingAmount !== '' ? number_format((float) $ceilingAmount, 0, ',', ' ') : null;

    switch ($type) {
        case 'FIXED_AMOUNT':
            return $formattedAmount !== null ? 'Exonere jusqu a ' . $formattedAmount . ' FCFA' : 'Exonere avec plafond fixe';
        case 'HOURLY_SMIG_MULTIPLE':
            return $formattedAmount !== null ? 'Exonere jusqu a ' . $formattedAmount . ' x SMIG horaire' : 'Exonere selon un multiple du SMIG horaire';
        case 'STAGE_ALLOWANCE':
            return $formattedAmount !== null ? 'Indemnite de stage exoneree jusqu a ' . $formattedAmount . ' FCFA' : 'Indemnite de stage exoneree selon le plafond';
        case 'APPRENTICESHIP_ALLOWANCE':
            return $formattedAmount !== null ? 'Indemnite d apprentissage exoneree jusqu a ' . $formattedAmount . ' FCFA' : 'Indemnite d apprentissage exoneree selon le plafond';
        case 'TOTAL':
            return 'Totalement exonere';
        case 'TOTAL_REMUNERATION_PERCENT':
            return $formattedAmount !== null ? 'Exonere jusqu a ' . $formattedAmount . ' % du salaire' : 'Exonere selon un pourcentage du salaire';
        default:
            return 'Aucune exoneration';
    }
}

function payrollAdminContributionAmount(array $row): string
{
    $employeeRate = (float) ($row['employee_rate'] ?? 0);
    $employerRate = (float) ($row['employer_rate'] ?? 0);
    $employeeFixed = (float) ($row['employee_fixed_amount'] ?? 0);
    $employerFixed = (float) ($row['employer_fixed_amount'] ?? 0);

    $employee = $employeeRate > 0 ? number_format($employeeRate, 2, ',', ' ') . ' %' : number_format($employeeFixed, 0, ',', ' ') . ' FCFA';
    $employer = $employerRate > 0 ? number_format($employerRate, 2, ',', ' ') . ' %' : number_format($employerFixed, 0, ',', ' ') . ' FCFA';

    return $employee . ' / ' . $employer;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clone_version') {
    try {
        $newVersionId = $controller->cloneVersion($_POST, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ]);
        $message = 'Nouvelle version de paie creee (#' . $newVersionId . ').';
        $selectedVersionId = $newVersionId;
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lock_period') {
    try {
        $controller->lockPeriod($_POST, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ]);
        $message = 'Periode de paie cloturee.';
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_bulk_payslips') {
    try {
        $bulkReport = $controller->generateBulk($_POST, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ]);
        $message = 'Generation groupee terminee : ' . (int) ($bulkReport['generated'] ?? 0) . ' genere(s), ' . (int) ($bulkReport['skipped'] ?? 0) . ' ignore(s), ' . (int) ($bulkReport['failed'] ?? 0) . ' erreur(s).';
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}
$settingActions = ['save_salary_item', 'save_tax_bracket', 'save_social_contribution', 'save_transport_rate', 'save_parameter'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string) ($_POST['action'] ?? ''), $settingActions, true)) {
    try {
        $controller->saveSetting($_POST, [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? null,
        ]);
        $message = 'Parametrage enregistre et trace dans l audit.';
        $selectedVersionId = (int) ($_POST['version_id'] ?? $selectedVersionId);
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}

$viewData = $controller->adminViewData(date('Y-m'), $selectedVersionId);
$version = $viewData['version'];
$selectedVersionId = (int) ($version['id'] ?? $selectedVersionId);
$versions = $viewData['versions'];
$versionHasSlips = $viewData['versionHasSlips'];
$salaryItems = $viewData['salaryItems'];
$taxBrackets = $viewData['taxBrackets'];
$socialContributions = $viewData['socialContributions'];
$transportRates = $viewData['transportRates'];
$parameters = $viewData['parameters'];
$slips = $viewData['slips'];
$auditEvents = $viewData['auditEvents'];
$periodLocks = $viewData['periodLocks'];
$contractProfiles = $contractManager->getContractPresets();

$pageTitle = 'Moteur de paie CI';
$activeMenu = 'rh';
require_once dirname(__DIR__, 3) . '/decaissement/includes/header.php';
require_once dirname(__DIR__, 3) . '/decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[28px] bg-slate-950 px-7 py-7 text-white shadow-xl">
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-emerald-200">Moteur de paie</p>
            <h1 class="mt-3 text-3xl font-semibold">Paie Cote d'Ivoire avec versions</h1>
            <p class="mt-3 max-w-4xl text-sm text-slate-200">Espace de controle des versions, rubriques, tranches fiscales, cotisations, plafonds et transports utilises pour calculer la paie. En cas de changement legal ou interne, on cree une nouvelle version au lieu d'ecraser l'ancienne.</p>
        </div>

        <?php if ($message !== null): ?>
            <div class="mt-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($errorMessage !== null): ?>
            <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <section class="mt-6 grid gap-4 md:grid-cols-4">
            <div class="rounded-lg border bg-white p-4"><div class="text-xs uppercase text-slate-500">Version</div><div class="mt-2 font-semibold"><?= htmlspecialchars((string) ($version['code'] ?? 'Non configuree')) ?></div></div>
            <div class="rounded-lg border bg-white p-4"><div class="text-xs uppercase text-slate-500">Rubriques</div><div class="mt-2 font-semibold"><?= count($salaryItems) ?></div></div>
            <div class="rounded-lg border bg-white p-4"><div class="text-xs uppercase text-slate-500">Tranches</div><div class="mt-2 font-semibold"><?= count($taxBrackets) ?></div></div>
            <div class="rounded-lg border bg-white p-4"><div class="text-xs uppercase text-slate-500">Cotisations</div><div class="mt-2 font-semibold"><?= count($socialContributions) ?></div></div>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Version a parametrer</h2>
                    <p class="mt-1 text-sm text-slate-500">Selectionnez la version sur laquelle les reglages doivent etre consultes ou ajustes.</p>
                </div>
                <form method="get" class="flex flex-wrap gap-3">
                    <select name="version_id" class="rounded-lg border px-3 py-2 text-sm">
                        <?php foreach ($versions as $row): ?>
                            <option value="<?= (int) $row['id'] ?>" <?= (int) $row['id'] === $selectedVersionId ? 'selected' : '' ?>><?= htmlspecialchars((string) $row['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Charger</button>
                </form>
            </div>
            <?php if ($versionHasSlips): ?>
                <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800">
                    Cette version a deja servi a produire des bulletins. Les modifications sont bloquees : creez une copie pour preparer une nouvelle version modifiable.
                </div>
            <?php endif; ?>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Generation groupee des bulletins</h2>
                    <p class="mt-1 text-sm text-slate-500">Le moteur prend les contrats actifs, ignore les bulletins deja generes et remonte les erreurs salarie par salarie.</p>
                </div>
                <form method="post" class="flex flex-wrap gap-3 rounded-lg border bg-slate-50 p-4">
                    <input type="hidden" name="action" value="generate_bulk_payslips">
                    <input type="month" name="payroll_period" class="rounded-lg border px-3 py-2 text-sm" required>
                    <button class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white">Generer tous les bulletins</button>
                </form>
            </div>
            <?php if (is_array($bulkReport)): ?>
                <div class="mt-4 overflow-x-auto rounded-lg border">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-[0.16em] text-slate-500">
                            <tr>
                                <th class="p-3">Salarie</th>
                                <th class="p-3">Statut</th>
                                <th class="p-3">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (($bulkReport['details'] ?? []) as $detail): ?>
                            <tr class="border-t">
                                <td class="p-3 font-semibold"><?= (int) ($detail['personnel_id'] ?? 0) ?></td>
                                <td class="p-3"><?= htmlspecialchars(payrollAdminLabel($detail['status'] ?? '')) ?></td>
                                <td class="p-3"><?= htmlspecialchars((string) ($detail['message'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Types de contrats relies au moteur</h2>
                    <p class="mt-1 max-w-4xl text-sm text-slate-500">
                        Ces profils alimentent l'assistant de paie : jours de reference, heures par jour, heures supplementaires, prime de precarite et taux payes. Le moteur applique ensuite la version de paie active, les rubriques, cotisations, impots et plafonds.
                    </p>
                </div>
                <a class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white" href="../../../rh/parametrage_paie_rh.php">Parametrer les contrats</a>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="p-3">Type de contrat</th>
                            <th class="p-3">Jours</th>
                            <th class="p-3">Heures / jour</th>
                            <th class="p-3">Heures supp.</th>
                            <th class="p-3">Precarite</th>
                            <th class="p-3">Mission</th>
                            <th class="p-3">Conge</th>
                            <th class="p-3">Absence</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($contractProfiles as $contractProfile): ?>
                        <tr class="border-t">
                            <td class="p-3 font-semibold"><?= htmlspecialchars((string) ($contractProfile['label'] ?? 'Type de contrat')) ?></td>
                            <td class="p-3"><?= number_format((float) ($contractProfile['reference_working_days'] ?? 0), 2, ',', ' ') ?></td>
                            <td class="p-3"><?= number_format((float) ($contractProfile['hours_per_day'] ?? 0), 2, ',', ' ') ?></td>
                            <td class="p-3"><?= number_format((float) ($contractProfile['overtime_multiplier'] ?? 0), 2, ',', ' ') ?></td>
                            <td class="p-3"><?= payrollAdminPercent($contractProfile['precarity_rate'] ?? 0) ?></td>
                            <td class="p-3"><?= payrollAdminPercent($contractProfile['mission_paid_rate'] ?? 0) ?></td>
                            <td class="p-3"><?= payrollAdminPercent($contractProfile['conge_paid_rate'] ?? 0) ?></td>
                            <td class="p-3"><?= payrollAdminPercent($contractProfile['absence_paid_rate'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Cloture des periodes</h2>
                    <p class="mt-1 text-sm text-slate-500">Une periode cloturee empeche la creation de nouveaux bulletins pour le mois valide.</p>
                </div>
                <form method="post" class="flex flex-wrap gap-3 rounded-lg border bg-slate-50 p-4">
                    <input type="hidden" name="action" value="lock_period">
                    <input type="hidden" name="country_code" value="CI">
                    <input type="month" name="payroll_period" class="rounded-lg border px-3 py-2 text-sm" required>
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white">Cloturer</button>
                </form>
            </div>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <?php foreach ($periodLocks as $lock): ?>
                    <div class="rounded-lg border bg-slate-50 p-4 text-sm"><strong><?= htmlspecialchars((string) $lock['payroll_period']) ?></strong><div class="mt-1 text-slate-500"><?= htmlspecialchars((string) $lock['country_code']) ?> - <?= htmlspecialchars((string) $lock['locked_at']) ?></div></div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">Versions de paie</h2>
                    <p class="mt-1 text-sm text-slate-500">Pour preparer une reforme ou une correction de regles, copiez une version existante, puis ajustez les nouveaux parametrages.</p>
                </div>
                <form method="post" class="grid gap-3 rounded-lg border bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-5">
                    <input type="hidden" name="action" value="clone_version">
                    <select name="source_version_id" class="rounded-lg border px-3 py-2 text-sm" required>
                        <?php foreach ($versions as $row): ?>
                            <option value="<?= (int) $row['id'] ?>"><?= htmlspecialchars((string) $row['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="code" value="">
                    <input name="label" placeholder="Nom de la version" class="rounded-lg border px-3 py-2 text-sm" required>
                    <input type="date" name="effective_date" class="rounded-lg border px-3 py-2 text-sm" required>
                    <label class="inline-flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" name="active" value="1"> Version active</label>
                    <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white xl:col-span-5">Creer une copie</button>
                </form>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Version</th><th class="p-3">Pays</th><th class="p-3">Debut</th><th class="p-3">Fin</th><th class="p-3">Utilisee</th></tr></thead>
                    <tbody>
                    <?php foreach ($versions as $row): ?>
                        <tr class="border-t"><td class="p-3 font-semibold"><?= htmlspecialchars((string) $row['label']) ?></td><td class="p-3"><?= htmlspecialchars((string) $row['country_code']) ?></td><td class="p-3"><?= htmlspecialchars((string) $row['effective_date']) ?></td><td class="p-3"><?= htmlspecialchars((string) ($row['end_date'] ?? '-')) ?></td><td class="p-3"><?= (int) $row['active'] ? 'Oui' : 'Non' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <h2 class="text-lg font-semibold text-slate-950">Rubriques de paie</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                        <tr>
                            <th class="p-3">Rubrique</th>
                            <th class="p-3">Type</th>
                            <th class="p-3">Impact fiscal<?= payrollAdminHelp('Indique si la rubrique est prise en compte dans le Salaire Brut Imposable.') ?></th>
                            <th class="p-3">Impact social<?= payrollAdminHelp('Indique si la rubrique est prise en compte dans le Salaire Brut Social, la CNPS ou la CMU.') ?></th>
                            <th class="p-3">Mode d'exoneration<?= payrollAdminHelp('Explique le plafond ou la limite d exoneration appliquee a cette rubrique.') ?></th>
                            <th class="p-3">Utilisee</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($salaryItems as $item): ?>
                        <tr class="border-t">
                            <td class="p-3 font-semibold"><?= htmlspecialchars((string) $item['label']) ?></td>
                            <td class="p-3"><?= htmlspecialchars(payrollAdminLabel($item['category'])) ?></td>
                            <td class="p-3"><?= htmlspecialchars(payrollAdminImpactFiscal($item)) ?></td>
                            <td class="p-3"><?= htmlspecialchars(payrollAdminImpactSocial($item)) ?></td>
                            <td class="p-3"><?= htmlspecialchars(payrollAdminExemptionMode($item)) ?></td>
                            <td class="p-3"><?= (int) $item['active'] ? 'Oui' : 'Non' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <details class="mt-5 rounded-lg border bg-slate-50 p-4">
                <summary class="cursor-pointer font-semibold text-slate-900">Modifier les rubriques</summary>
                <div class="mt-4 grid gap-4">
                    <?php foreach (array_merge(array_values($salaryItems), [[
                        'id' => 0, 'code' => '', 'label' => '', 'category' => 'gain', 'taxable_sbi' => 1, 'taxable_sbs' => 1,
                        'subject_to_cnps' => 1, 'subject_to_cmu' => 1, 'ceiling_type' => '', 'ceiling_amount' => '',
                        'exemption_type' => 'NONE', 'calculation_mode' => 'fixed_amount', 'active' => 1,
                    ]]) as $item): ?>
                        <form method="post" class="grid gap-3 rounded-lg border bg-white p-4 md:grid-cols-4 xl:grid-cols-6">
                            <input type="hidden" name="action" value="save_salary_item">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                            <input type="hidden" name="code" value="<?= payrollAdminValue($item['code']) ?>">
                            <input name="label" value="<?= payrollAdminValue($item['label']) ?>" placeholder="Nom" class="rounded-lg border px-3 py-2 text-sm" required>
                            <?= payrollAdminSelect('category', $item['category'], payrollAdminOptions('salary_category')) ?>
                            <?= payrollAdminSelect('exemption_type', $item['exemption_type'], payrollAdminOptions('exemption_type')) ?>
                            <?= payrollAdminSelect('ceiling_type', $item['ceiling_type'], payrollAdminOptions('ceiling_type')) ?>
                            <input type="number" step="0.01" name="ceiling_amount" value="<?= payrollAdminValue($item['ceiling_amount']) ?>" placeholder="Plafond" class="rounded-lg border px-3 py-2 text-sm">
                            <?= payrollAdminSelect('calculation_mode', $item['calculation_mode'], payrollAdminOptions('calculation_mode')) ?>
                            <label class="text-sm"><input type="checkbox" name="taxable_sbi" value="1" <?= payrollAdminChecked($item['taxable_sbi']) ?>> Soumis au SBI<?= payrollAdminHelp('Indique si la rubrique est prise en compte dans le Salaire Brut Imposable.') ?></label>
                            <label class="text-sm"><input type="checkbox" name="taxable_sbs" value="1" <?= payrollAdminChecked($item['taxable_sbs']) ?>> Soumis au SBS<?= payrollAdminHelp('Indique si la rubrique est prise en compte dans le Salaire Brut Social.') ?></label>
                            <label class="text-sm"><input type="checkbox" name="subject_to_cnps" value="1" <?= payrollAdminChecked($item['subject_to_cnps']) ?>> Soumis CNPS<?= payrollAdminHelp('Indique si la rubrique entre dans la base de calcul CNPS.') ?></label>
                            <label class="text-sm"><input type="checkbox" name="subject_to_cmu" value="1" <?= payrollAdminChecked($item['subject_to_cmu']) ?>> Soumis CMU<?= payrollAdminHelp('Indique si la rubrique entre dans le traitement CMU.') ?></label>
                            <label class="text-sm"><input type="checkbox" name="active" value="1" <?= payrollAdminChecked($item['active']) ?>> Utilisee</label>
                            <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white xl:col-span-6"><?= (int) $item['id'] > 0 ? 'Enregistrer rubrique' : 'Ajouter rubrique' ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <h2 class="text-lg font-semibold text-slate-950">Historique des bulletins</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">N</th><th class="p-3">Periode</th><th class="p-3">Salarie</th><th class="p-3">Version</th><th class="p-3">SBI</th><th class="p-3">SBS</th><th class="p-3">Net</th><th class="p-3">Etat</th></tr></thead>
                    <tbody>
                    <?php foreach ($slips as $slip): ?>
                        <tr class="border-t"><td class="p-3"><a class="font-semibold text-slate-900 underline" href="../../../rh/bulletin_paie_rh.php?id=<?= (int) $slip['id'] ?>">#<?= (int) $slip['id'] ?></a></td><td class="p-3"><?= htmlspecialchars((string) $slip['payroll_period']) ?></td><td class="p-3"><?= (int) $slip['personnel_id'] ?></td><td class="p-3"><?= htmlspecialchars((string) ($slip['rule_version_code'] ?? '')) ?></td><td class="p-3"><?= payroll_ci_money((float) $slip['sbi']) ?></td><td class="p-3"><?= payroll_ci_money((float) $slip['sbs']) ?></td><td class="p-3 font-semibold"><?= payroll_ci_money((float) $slip['net_pay']) ?></td><td class="p-3"><?= htmlspecialchars(payrollAdminLabel($slip['status'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <h2 class="text-lg font-semibold text-slate-950">Audit paie</h2>
            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Date</th><th class="p-3">Action</th><th class="p-3">Utilisateur</th><th class="p-3">Element concerne</th><th class="p-3">Resultat</th><th class="p-3">Detail</th></tr></thead>
                    <tbody>
                    <?php foreach ($auditEvents as $event): ?>
                        <tr class="border-t align-top"><td class="p-3"><?= htmlspecialchars((string) $event['created_at']) ?></td><td class="p-3 font-semibold"><?= htmlspecialchars(payrollAdminLabel($event['event_type'])) ?></td><td class="p-3"><?= htmlspecialchars((string) ($event['user_name'] ?? '-')) ?></td><td class="p-3"><?= htmlspecialchars((string) ($event['entity_label'] ?? $event['entity_id'] ?? '-')) ?></td><td class="p-3"><?= htmlspecialchars(payrollAdminLabel($event['result'])) ?></td><td class="p-3 max-w-md text-slate-500"><?= !empty($event['details_json']) ? 'Trace conservee' : '-' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <section class="rounded-lg border bg-white p-5">
                <h2 class="text-lg font-semibold text-slate-950">Bareme progressif</h2>
                <div class="mt-4 space-y-2 text-sm">
                    <?php foreach ($taxBrackets as $bracket): ?>
                        <div class="flex justify-between border-b py-2"><span><?= number_format((float) $bracket['min_amount'], 0, ',', ' ') ?> - <?= $bracket['max_amount'] === null ? 'Sans limite' : number_format((float) $bracket['max_amount'], 0, ',', ' ') ?></span><strong><?= number_format((float) $bracket['rate'], 2, ',', ' ') ?>%</strong></div>
                    <?php endforeach; ?>
                </div>
                <details class="mt-5 rounded-lg border bg-slate-50 p-4">
                    <summary class="cursor-pointer font-semibold">Modifier les tranches</summary>
                    <div class="mt-4 grid gap-3">
                        <?php foreach (array_merge($taxBrackets, [['id' => 0, 'min_amount' => '', 'max_amount' => '', 'rate' => '']]) as $bracket): ?>
                            <form method="post" class="grid gap-3 rounded-lg border bg-white p-3 md:grid-cols-4">
                                <input type="hidden" name="action" value="save_tax_bracket">
                                <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                                <input type="hidden" name="id" value="<?= (int) $bracket['id'] ?>">
                                <input type="number" step="0.01" name="min_amount" value="<?= payrollAdminValue($bracket['min_amount']) ?>" placeholder="Minimum" class="rounded-lg border px-3 py-2 text-sm" required>
                                <input type="number" step="0.01" name="max_amount" value="<?= payrollAdminValue($bracket['max_amount']) ?>" placeholder="Maximum vide = sans limite" class="rounded-lg border px-3 py-2 text-sm">
                                <input type="number" step="0.0001" name="rate" value="<?= payrollAdminValue($bracket['rate']) ?>" placeholder="Taux" class="rounded-lg border px-3 py-2 text-sm" required>
                                <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"><?= (int) $bracket['id'] > 0 ? 'Enregistrer' : 'Ajouter' ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </details>
            </section>
            <section class="rounded-lg border bg-white p-5">
                <h2 class="text-lg font-semibold text-slate-950">Cotisations sociales</h2>
                <p class="mt-1 text-sm text-slate-500">
                    Utilisez les taux pour les cotisations proportionnelles et les montants fixes pour les cotisations forfaitaires comme la CMU.
                </p>
                <div class="mt-4 space-y-2 text-sm">
                    <?php foreach ($socialContributions as $row): ?>
                        <div class="border-b py-2">
                            <div class="flex justify-between gap-4">
                                <span><?= htmlspecialchars((string) $row['label']) ?></span>
                                <strong><?= htmlspecialchars(payrollAdminContributionAmount($row)) ?></strong>
                            </div>
                            <div class="text-xs text-slate-500">Part salarie / part employeur. Base <?= htmlspecialchars(payrollAdminLabel($row['calculation_base'])) ?>, plafond <?= $row['ceiling'] === null ? 'aucun' : number_format((float) $row['ceiling'], 0, ',', ' ') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <details class="mt-5 rounded-lg border bg-slate-50 p-4">
                    <summary class="cursor-pointer font-semibold">Modifier les cotisations<?= payrollAdminHelp('Une cotisation peut avoir un taux, un montant fixe, ou les deux. La part salarie est retenue sur le net, la part employeur est une charge patronale.') ?></summary>
                    <div class="mt-4 grid gap-3">
                        <?php foreach (array_merge($socialContributions, [['id' => 0, 'code' => '', 'label' => '', 'employee_rate' => '0', 'employer_rate' => '0', 'employee_fixed_amount' => '0', 'employer_fixed_amount' => '0', 'ceiling' => '', 'calculation_base' => 'SBS', 'active' => 1]]) as $row): ?>
                            <form method="post" class="grid gap-3 rounded-lg border bg-white p-3 md:grid-cols-4">
                                <input type="hidden" name="action" value="save_social_contribution">
                                <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <input type="hidden" name="code" value="<?= payrollAdminValue($row['code']) ?>">
                                <input name="label" value="<?= payrollAdminValue($row['label']) ?>" placeholder="Nom" class="rounded-lg border px-3 py-2 text-sm" required>
                                <input type="number" step="0.0001" name="employee_rate" value="<?= payrollAdminValue($row['employee_rate']) ?>" placeholder="Taux salarie" class="rounded-lg border px-3 py-2 text-sm">
                                <input type="number" step="0.0001" name="employer_rate" value="<?= payrollAdminValue($row['employer_rate']) ?>" placeholder="Taux patronal" class="rounded-lg border px-3 py-2 text-sm">
                                <input type="number" step="0.01" name="employee_fixed_amount" value="<?= payrollAdminValue($row['employee_fixed_amount'] ?? 0) ?>" placeholder="Montant salarie fixe" class="rounded-lg border px-3 py-2 text-sm">
                                <input type="number" step="0.01" name="employer_fixed_amount" value="<?= payrollAdminValue($row['employer_fixed_amount'] ?? 0) ?>" placeholder="Montant employeur fixe" class="rounded-lg border px-3 py-2 text-sm">
                                <input type="number" step="0.01" name="ceiling" value="<?= payrollAdminValue($row['ceiling']) ?>" placeholder="Plafond" class="rounded-lg border px-3 py-2 text-sm">
                                <?= payrollAdminSelect('calculation_base', $row['calculation_base'], payrollAdminOptions('calculation_base')) ?>
                                <label class="text-sm"><input type="checkbox" name="active" value="1" <?= payrollAdminChecked($row['active']) ?>> Utilisee</label>
                                <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"><?= (int) $row['id'] > 0 ? 'Enregistrer' : 'Ajouter' ?></button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </details>
            </section>
        </div>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <h2 class="text-lg font-semibold text-slate-950">Transport minimum</h2>
            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <?php foreach ($transportRates as $rate): ?>
                    <div class="rounded-lg border bg-slate-50 p-4"><div class="text-sm font-semibold"><?= htmlspecialchars((string) $rate['locality_label']) ?></div><div class="mt-1 text-xl font-bold"><?= payroll_ci_money((float) $rate['monthly_amount']) ?></div></div>
                <?php endforeach; ?>
            </div>
            <details class="mt-5 rounded-lg border bg-slate-50 p-4">
                <summary class="cursor-pointer font-semibold">Modifier les transports</summary>
                <div class="mt-4 grid gap-3">
                    <?php foreach (array_merge($transportRates, [['id' => 0, 'locality_code' => '', 'locality_label' => '', 'monthly_amount' => '', 'active' => 1]]) as $rate): ?>
                        <form method="post" class="grid gap-3 rounded-lg border bg-white p-3 md:grid-cols-5">
                            <input type="hidden" name="action" value="save_transport_rate">
                            <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                            <input type="hidden" name="id" value="<?= (int) $rate['id'] ?>">
                            <input type="hidden" name="locality_code" value="<?= payrollAdminValue($rate['locality_code']) ?>">
                            <input name="locality_label" value="<?= payrollAdminValue($rate['locality_label']) ?>" placeholder="Localite" class="rounded-lg border px-3 py-2 text-sm" required>
                            <input type="number" step="0.01" name="monthly_amount" value="<?= payrollAdminValue($rate['monthly_amount']) ?>" placeholder="Montant" class="rounded-lg border px-3 py-2 text-sm" required>
                            <label class="text-sm"><input type="checkbox" name="active" value="1" <?= payrollAdminChecked($rate['active']) ?>> Utilise</label>
                            <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"><?= (int) $rate['id'] > 0 ? 'Enregistrer' : 'Ajouter' ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </details>
        </section>

        <section class="mt-6 rounded-lg border bg-white p-5">
            <h2 class="text-lg font-semibold text-slate-950">Parametres et plafonds generaux</h2>
            <p class="mt-1 text-sm text-slate-500">
                Les parametres existants peuvent etre ajustes par les RH. L'ajout d'un nouveau parametre est reserve aux administrateurs, car il doit correspondre a une regle lue par le moteur.
            </p>
            <div class="mt-4 grid gap-3">
                <?php foreach (array_merge($parameters, [['id' => 0, 'param_key' => '', 'param_value' => '', 'value_type' => 'decimal', 'description' => '']]) as $parameter): ?>
                    <form method="post" class="grid gap-3 rounded-lg border bg-slate-50 p-3 md:grid-cols-5">
                        <input type="hidden" name="action" value="save_parameter">
                        <input type="hidden" name="version_id" value="<?= $selectedVersionId ?>">
                        <input type="hidden" name="id" value="<?= (int) $parameter['id'] ?>">
                        <?php if ((int) $parameter['id'] > 0): ?>
                            <input type="hidden" name="param_key" value="<?= payrollAdminValue($parameter['param_key']) ?>">
                            <div class="rounded-lg border bg-white px-3 py-2 text-sm font-semibold text-slate-700"><?= htmlspecialchars(payrollAdminLabel($parameter['param_key'])) ?></div>
                        <?php else: ?>
                            <input name="param_key" value="<?= payrollAdminValue($parameter['param_key']) ?>" placeholder="Nom technique reserve administrateur" class="rounded-lg border px-3 py-2 text-sm" required>
                        <?php endif; ?>
                        <input name="param_value" value="<?= payrollAdminValue($parameter['param_value']) ?>" placeholder="Valeur" class="rounded-lg border px-3 py-2 text-sm" required>
                        <?= payrollAdminSelect('value_type', $parameter['value_type'], payrollAdminOptions('value_type')) ?>
                        <input name="description" value="<?= payrollAdminValue($parameter['description']) ?>" placeholder="Description" class="rounded-lg border px-3 py-2 text-sm">
                        <button class="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white"><?= (int) $parameter['id'] > 0 ? 'Enregistrer' : 'Ajouter' ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>
<?php require_once dirname(__DIR__, 3) . '/decaissement/includes/footer.php'; ?>
