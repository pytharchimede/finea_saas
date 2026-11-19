<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/includes/helpers.php';

$pdo = \Database::getConnection();
$manager = new \EmployeeLegalRequestManager($pdo);
$types = $manager->getRequestTypes();

$typeIcons = [
    'permission_absence' => 'fa-calendar-minus',
    'conge' => 'fa-umbrella-beach',
    'retard' => 'fa-clock-rotate-left',
    'pret' => 'fa-hand-holding-usd',
    'avance_salaire' => 'fa-wallet',
    'heures_supp' => 'fa-clock',
    'reclamation_salariale' => 'fa-file-invoice-dollar',
    'recrutement' => 'fa-user-plus',
    'feb' => 'fa-clipboard-list',
];

$typeThemes = [
    'permission_absence' => [
        'iconBg' => 'bg-sky-600',
        'iconBgHover' => 'group-hover:bg-sky-700',
        'ringHover' => 'hover:ring-sky-200',
        'arrowText' => 'text-sky-700',
        'arrowBgHover' => 'group-hover:bg-sky-50',
        'arrowBorderHover' => 'group-hover:border-sky-200',
    ],
    'conge' => [
        'iconBg' => 'bg-cyan-600',
        'iconBgHover' => 'group-hover:bg-cyan-700',
        'ringHover' => 'hover:ring-cyan-200',
        'arrowText' => 'text-cyan-700',
        'arrowBgHover' => 'group-hover:bg-cyan-50',
        'arrowBorderHover' => 'group-hover:border-cyan-200',
    ],
    'retard' => [
        'iconBg' => 'bg-orange-600',
        'iconBgHover' => 'group-hover:bg-orange-700',
        'ringHover' => 'hover:ring-orange-200',
        'arrowText' => 'text-orange-700',
        'arrowBgHover' => 'group-hover:bg-orange-50',
        'arrowBorderHover' => 'group-hover:border-orange-200',
    ],
    'pret' => [
        'iconBg' => 'bg-amber-600',
        'iconBgHover' => 'group-hover:bg-amber-700',
        'ringHover' => 'hover:ring-amber-200',
        'arrowText' => 'text-amber-700',
        'arrowBgHover' => 'group-hover:bg-amber-50',
        'arrowBorderHover' => 'group-hover:border-amber-200',
    ],
    'avance_salaire' => [
        'iconBg' => 'bg-emerald-600',
        'iconBgHover' => 'group-hover:bg-emerald-700',
        'ringHover' => 'hover:ring-emerald-200',
        'arrowText' => 'text-emerald-700',
        'arrowBgHover' => 'group-hover:bg-emerald-50',
        'arrowBorderHover' => 'group-hover:border-emerald-200',
    ],
    'heures_supp' => [
        'iconBg' => 'bg-violet-600',
        'iconBgHover' => 'group-hover:bg-violet-700',
        'ringHover' => 'hover:ring-violet-200',
        'arrowText' => 'text-violet-700',
        'arrowBgHover' => 'group-hover:bg-violet-50',
        'arrowBorderHover' => 'group-hover:border-violet-200',
    ],
    'reclamation_salariale' => [
        'iconBg' => 'bg-rose-600',
        'iconBgHover' => 'group-hover:bg-rose-700',
        'ringHover' => 'hover:ring-rose-200',
        'arrowText' => 'text-rose-700',
        'arrowBgHover' => 'group-hover:bg-rose-50',
        'arrowBorderHover' => 'group-hover:border-rose-200',
    ],
    'feb' => [
        'iconBg' => 'bg-indigo-600',
        'iconBgHover' => 'group-hover:bg-indigo-700',
        'ringHover' => 'hover:ring-indigo-200',
        'arrowText' => 'text-indigo-700',
        'arrowBgHover' => 'group-hover:bg-indigo-50',
        'arrowBorderHover' => 'group-hover:border-indigo-200',
    ],
];

$defaultTheme = [
    'iconBg' => 'bg-blue-600',
    'iconBgHover' => 'group-hover:bg-blue-700',
    'ringHover' => 'hover:ring-blue-200',
    'arrowText' => 'text-blue-700',
    'arrowBgHover' => 'group-hover:bg-blue-50',
    'arrowBorderHover' => 'group-hover:border-blue-300',
];

$pageTitle = 'Actes légaux';
$layoutRootBase = '../';
$activeEmployeeMenu = 'actes';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(240,249,255,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(224,231,255,0.85),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="mx-auto max-w-[1600px] px-4 py-6 2xl:px-8">
        <div class="relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-indigo-500 via-violet-500 to-emerald-500"></div>
            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-indigo-600">Actes légaux</p>
            <h1 class="mt-2 text-2xl font-semibold text-slate-950">Créer une demande</h1>
            <p class="mt-2 text-sm text-slate-600">Choisissez le type de demande. Les demandes RH seront validées par le service RH, et les FEB par la Finance.</p>

            <div class="mt-6 grid gap-4 md:grid-cols-2">
                <a href="nouvelle_demande_decaissement_urgent.php"
                    class="group block rounded-3xl border border-rose-200 bg-rose-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:ring-2 hover:ring-offset-1 hover:ring-rose-200">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-rose-600 text-white group-hover:bg-rose-700">
                            <i class="fas fa-bolt"></i>
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <div class="text-base font-semibold text-slate-900">Decaissement en urgence</div>
                                <span class="inline-flex items-center rounded-full border border-rose-200 bg-white px-2 py-0.5 text-xs font-semibold text-rose-700">Comptabilite</span>
                            </div>
                            <div class="mt-1 text-sm text-slate-600">Demande simple hors fiche classique, a regulariser ensuite par FEB.</div>
                        </div>
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-rose-200 bg-white text-rose-700 transition">
                            <i class="fas fa-arrow-right"></i>
                        </span>
                    </div>
                </a>
                <?php foreach ($types as $typeKey => $def): ?>
                    <?php
                    $teamKey = (($def['team'] ?? '') === 'finance') ? 'finance' : 'rh';
                    $teamLabel = ($teamKey === 'finance') ? 'Finances' : 'RH';
                    $teamChipClass = ($teamKey === 'finance')
                        ? 'border-indigo-200 bg-indigo-50 text-indigo-700'
                        : 'border-slate-200 bg-slate-50 text-slate-700';
                    $icon = $typeIcons[$typeKey] ?? 'fa-file-alt';
                    $theme = $typeThemes[$typeKey] ?? $defaultTheme;
                    ?>
                    <a href="nouvelle_demande_employe.php?type=<?= urlencode((string) $typeKey) ?>"
                        class="group block rounded-3xl border border-slate-200 bg-white p-5 transition hover:-translate-y-0.5 hover:bg-slate-50 hover:ring-2 hover:ring-offset-1 <?= htmlspecialchars((string) $theme['ringHover']) ?>">
                        <div class="flex items-start gap-4">
                            <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white <?= htmlspecialchars((string) $theme['iconBg']) ?> <?= htmlspecialchars((string) $theme['iconBgHover']) ?>">
                                <i class="fas <?= htmlspecialchars((string) $icon) ?>"></i>
                            </span>

                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <div class="text-base font-semibold text-slate-900"><?= htmlspecialchars((string) ($def['label'] ?? $typeKey)) ?></div>
                                    <span class="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-semibold <?= $teamChipClass ?>">
                                        <?= htmlspecialchars($teamLabel) ?>
                                    </span>
                                </div>
                                <div class="mt-1 text-sm text-slate-600">Validation par <span class="font-semibold"><?= htmlspecialchars($teamLabel) ?></span></div>
                            </div>

                            <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 transition group-hover:bg-white <?= htmlspecialchars((string) $theme['arrowBgHover']) ?> <?= htmlspecialchars((string) $theme['arrowBorderHover']) ?> <?= htmlspecialchars((string) $theme['arrowText']) ?>">
                                <i class="fas fa-arrow-right"></i>
                            </span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700">
                <div class="font-semibold">Bon à savoir</div>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    <li>Vous pouvez suivre l'état de vos demandes dans <a class="font-semibold text-indigo-700 hover:text-indigo-800" href="mes_demandes_employe.php">Mes demandes</a>.</li>
                    <li>Pour une sortie de caisse (paiement), le formulaire existant de <a class="font-semibold text-indigo-700 hover:text-indigo-800" href="../decaissement/formulaire_demande_decaissement.php">Décaissement</a> reste disponible.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
