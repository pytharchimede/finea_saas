<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess('liste_debourse.php');

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/controllers/DebourseDashboardController.php';

$pdo = \Database::getConnection();
$controller = new \Debourse\Controllers\DebourseDashboardController($pdo);

$chantierId = isset($_GET['chantier_id']) && $_GET['chantier_id'] !== '' ? (int)$_GET['chantier_id'] : null;
$search = trim((string)($_GET['search'] ?? ''));
$client = trim((string)($_GET['client'] ?? ''));
$view = $controller->listing($chantierId, $search, $client);
$rows = $view['rows'] ?? [];
$chantiers = $view['chantiers'] ?? [];
$clients = $view['clients'] ?? [];
$total = (float)($view['total'] ?? 0);
$linkedCount = (int)($view['linked_count'] ?? 0);

$appBase = rtrim(\AccessManager::getApplicationBasePath(), '/');
$rootUrl = static fn(string $path): string => ($appBase === '' ? '' : $appBase) . '/' . ltrim($path, '/');
$fmt = static fn($value): string => number_format((float)$value, 0, ',', ' ');
$query = http_build_query(array_filter([
    'chantier_id' => $chantierId,
    'search' => $search,
    'client' => $client,
], static fn($value): bool => $value !== null && $value !== ''));

$pageTitle = 'Listing Déboursés';
$activeMenu = 'module_debourse';
$activeDevisModule='debourse';
$moduleTitle='Liste des déboursés';
$moduleSubtitle='Suivi des déboursés, des devis et des chantiers.';
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
ob_start();

$selectedChantierLabel = 'Tous les chantiers';
foreach ($chantiers as $chantier) {
    if ($chantierId === (int)($chantier['id'] ?? 0)) {
        $selectedChantierLabel = (string)($chantier['titre'] ?? 'Chantier #' . $chantierId);
        break;
    }
}
$selectedClientLabel = $client !== '' ? $client : 'Tous les clients';
?>
<style>
    .debourse-combobox {
        position: relative;
    }

    .debourse-combobox-panel {
        display: none;
        max-height: 260px;
        overflow-y: auto;
    }

    .debourse-combobox.is-open .debourse-combobox-panel {
        display: block;
    }

    .debourse-combobox-option[aria-selected="true"] {
        background: #ecfeff;
        color: #155e75;
    }
</style>
<div class="devis-debourse-content">
<section class="mt-6 grid gap-4 md:grid-cols-3">
            <article class="rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Déboursés filtrés</p>
                <div class="mt-3 text-3xl font-bold text-slate-950"><?= count($rows) ?></div>
            </article>
            <article class="rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Montant total</p>
                <div class="mt-3 text-3xl font-bold text-slate-950"><?= $fmt($total) ?></div>
                <p class="mt-2 text-sm text-slate-600">FCFA</p>
            </article>
            <article class="rounded-[26px] border border-white/80 bg-white/95 p-5 shadow-[0_16px_45px_rgba(15,23,42,0.05)]">
                <p class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-500">Avec sous-lignes</p>
                <div class="mt-3 text-3xl font-bold text-cyan-700"><?= $linkedCount ?></div>
            </article>
        </section>

        <div class="mt-8 grid gap-6 grid-cols-1">
            <main class="space-y-6">
                <section class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <form method="get" class="grid gap-4 lg:grid-cols-[1fr_1fr_1fr_auto] lg:items-end">
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Chantier</label>
                            <div class="debourse-combobox mt-2" data-combobox>
                                <input type="hidden" name="chantier_id" value="<?= htmlspecialchars((string)($chantierId ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-combobox-value>
                                <button type="button" class="flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left text-sm text-slate-700 shadow-sm transition hover:border-cyan-200 focus:border-cyan-400 focus:outline-none focus:ring-4 focus:ring-cyan-100" data-combobox-toggle>
                                    <span class="truncate" data-combobox-label><?= htmlspecialchars($selectedChantierLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <i class="fas fa-chevron-down text-xs text-slate-400"></i>
                                </button>
                                <div class="debourse-combobox-panel absolute left-0 right-0 z-30 mt-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_22px_55px_rgba(15,23,42,0.16)]">
                                    <div class="relative">
                                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input type="search" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm outline-none focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100" placeholder="Rechercher un chantier..." data-combobox-search>
                                    </div>
                                    <div class="mt-2 space-y-1" data-combobox-options>
                                        <button type="button" class="debourse-combobox-option flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-cyan-50" data-value="" data-label="Tous les chantiers" aria-selected="<?= $chantierId === null ? 'true' : 'false' ?>">
                                            <span>Tous les chantiers</span>
                                        </button>
                                        <?php foreach ($chantiers as $chantier): ?>
                                            <button type="button" class="debourse-combobox-option flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-cyan-50" data-value="<?= (int)$chantier['id'] ?>" data-label="<?= htmlspecialchars((string)$chantier['titre'], ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= $chantierId === (int)$chantier['id'] ? 'true' : 'false' ?>">
                                                <span class="truncate"><?= htmlspecialchars((string)$chantier['titre'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="hidden px-3 py-3 text-sm text-slate-500" data-combobox-empty>Aucun chantier trouve.</p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Recherche</label>
                            <input name="search" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Désignation, devis..." class="mt-2 w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm">
                        </div>
                        <div>
                            <label class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Client</label>
                            <div class="debourse-combobox mt-2" data-combobox>
                                <input type="hidden" name="client" value="<?= htmlspecialchars($client, ENT_QUOTES, 'UTF-8') ?>" data-combobox-value>
                                <button type="button" class="flex w-full items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-left text-sm text-slate-700 shadow-sm transition hover:border-cyan-200 focus:border-cyan-400 focus:outline-none focus:ring-4 focus:ring-cyan-100" data-combobox-toggle>
                                    <span class="truncate" data-combobox-label><?= htmlspecialchars($selectedClientLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                    <i class="fas fa-chevron-down text-xs text-slate-400"></i>
                                </button>
                                <div class="debourse-combobox-panel absolute left-0 right-0 z-30 mt-2 rounded-2xl border border-slate-200 bg-white p-2 shadow-[0_22px_55px_rgba(15,23,42,0.16)]">
                                    <div class="relative">
                                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-slate-400"></i>
                                        <input type="search" class="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm outline-none focus:border-cyan-400 focus:bg-white focus:ring-4 focus:ring-cyan-100" placeholder="Rechercher un client..." data-combobox-search>
                                    </div>
                                    <div class="mt-2 space-y-1" data-combobox-options>
                                        <button type="button" class="debourse-combobox-option flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-cyan-50" data-value="" data-label="Tous les clients" aria-selected="<?= $client === '' ? 'true' : 'false' ?>">
                                            <span>Tous les clients</span>
                                        </button>
                                        <?php foreach ($clients as $clientRow): ?>
                                            <?php $clientName = (string)($clientRow['nom_client'] ?? ''); ?>
                                            <button type="button" class="debourse-combobox-option flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-cyan-50" data-value="<?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>" data-label="<?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?>" aria-selected="<?= $client !== '' && strcasecmp($client, $clientName) === 0 ? 'true' : 'false' ?>">
                                                <span class="truncate"><?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="hidden px-3 py-3 text-sm text-slate-500" data-combobox-empty>Aucun client trouve.</p>
                                </div>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <button class="inline-flex items-center justify-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-search mr-2"></i>Filtrer</button>
                            <a href="<?= htmlspecialchars($rootUrl('debourse/liste.php'), ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50"><i class="fas fa-rotate-left"></i></a>
                        </div>
                    </form>
                </section>

                <section class="rounded-[30px] border border-white/80 bg-white/95 p-6 shadow-[0_18px_55px_rgba(15,23,42,0.06)]">
                    <div class="mb-5 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.28em] text-cyan-700">Résultats</p>
                            <h2 class="mt-2 text-2xl font-semibold text-slate-950"><?= count($rows) ?> déboursé<?= count($rows) > 1 ? 's' : '' ?></h2>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="<?= htmlspecialchars($rootUrl('request/export_debourses_pdf.php' . ($query ? '?' . $query : '')), ENT_QUOTES, 'UTF-8') ?>" class="rounded-full bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100"><i class="fas fa-file-pdf mr-2"></i>PDF</a>
                            <a href="<?= htmlspecialchars($rootUrl('request/export_debourses_excel.php' . ($query ? '?' . $query : '')), ENT_QUOTES, 'UTF-8') ?>" class="rounded-full bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-100"><i class="fas fa-file-excel mr-2"></i>Excel</a>
                        </div>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <?php foreach ($rows as $row): ?>
                            <article class="group rounded-[24px] border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-cyan-200 hover:shadow-[0_20px_55px_rgba(15,23,42,0.10)]">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="rounded-full bg-cyan-50 px-3 py-1 text-xs font-bold text-cyan-800">Devis <?= htmlspecialchars((string)($row['numero_devis'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="text-xs font-semibold text-slate-400">#<?= (int)($row['id'] ?? 0) ?></span>
                                        </div>
                                        <h3 class="mt-3 line-clamp-2 text-lg font-semibold text-slate-950"><?= htmlspecialchars((string)($row['designation'] ?? 'Ligne sans désignation'), ENT_QUOTES, 'UTF-8') ?></h3>
                                        <p class="mt-2 text-sm text-slate-500"><?= htmlspecialchars((string)($row['nom_client'] ?? $row['destine_a'] ?? 'Client non renseigné'), ENT_QUOTES, 'UTF-8') ?></p>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-lg font-bold text-slate-950"><?= $fmt($row['montant_debourse'] ?? 0) ?></div>
                                        <div class="text-xs font-semibold text-slate-400">FCFA</div>
                                    </div>
                                </div>
                                <div class="mt-4 grid gap-3 text-sm text-slate-600 md:grid-cols-3">
                                    <span><i class="fas fa-building mr-2 text-cyan-600"></i><?= htmlspecialchars((string)($row['titre'] ?? 'Sans chantier'), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><i class="fas fa-stream mr-2 text-cyan-600"></i><?= (int)($row['sous_lignes'] ?? 0) ?> sous-ligne(s)</span>
                                    <span><i class="fas fa-calendar mr-2 text-cyan-600"></i><?= htmlspecialchars((string)($row['date_debut'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="mt-5 flex flex-wrap gap-2">
                                    <a href="<?= htmlspecialchars($rootUrl('debourse/gerer.php?devisId=' . (int)($row['devis_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="rounded-2xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"><i class="fas fa-pen-to-square mr-2"></i>Gérer</a>
                                    <a href="<?= htmlspecialchars($rootUrl('debourse/voir.php?devisId=' . (int)($row['devis_id'] ?? 0)), ENT_QUOTES, 'UTF-8') ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"><i class="fas fa-eye mr-2"></i>Voir</a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                        <?php if ($rows === []): ?>
                            <div class="rounded-[26px] border border-dashed border-slate-300 bg-slate-50 p-10 text-center text-slate-500 lg:col-span-2">Aucun déboursé ne correspond aux filtres.</div>
                        <?php endif; ?>
                    </div>
                </section>
            </main>


        </div>
</div>
<script>
    document.querySelectorAll('[data-combobox]').forEach((combo) => {
        const toggle = combo.querySelector('[data-combobox-toggle]');
        const search = combo.querySelector('[data-combobox-search]');
        const hidden = combo.querySelector('[data-combobox-value]');
        const label = combo.querySelector('[data-combobox-label]');
        const options = Array.from(combo.querySelectorAll('.debourse-combobox-option'));
        const empty = combo.querySelector('[data-combobox-empty]');

        const closeOthers = () => {
            document.querySelectorAll('[data-combobox].is-open').forEach((openCombo) => {
                if (openCombo !== combo) {
                    openCombo.classList.remove('is-open');
                }
            });
        };

        const filterOptions = () => {
            const term = (search.value || '').trim().toLowerCase();
            let visibleCount = 0;

            options.forEach((option) => {
                const text = (option.dataset.label || option.textContent || '').toLowerCase();
                const visible = text.includes(term);
                option.classList.toggle('hidden', !visible);
                if (visible) {
                    visibleCount += 1;
                }
            });

            if (empty) {
                empty.classList.toggle('hidden', visibleCount > 0);
            }
        };

        toggle.addEventListener('click', () => {
            closeOthers();
            combo.classList.toggle('is-open');
            if (combo.classList.contains('is-open')) {
                search.value = '';
                filterOptions();
                window.setTimeout(() => search.focus(), 0);
            }
        });

        search.addEventListener('input', filterOptions);
        search.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                combo.classList.remove('is-open');
                toggle.focus();
            }
        });

        options.forEach((option) => {
            option.addEventListener('click', () => {
                hidden.value = option.dataset.value || '';
                label.textContent = option.dataset.label || option.textContent.trim();
                options.forEach((item) => item.setAttribute('aria-selected', 'false'));
                option.setAttribute('aria-selected', 'true');
                combo.classList.remove('is-open');
                toggle.focus();
            });
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('[data-combobox]')) {
            document.querySelectorAll('[data-combobox].is-open').forEach((combo) => combo.classList.remove('is-open'));
        }
    });
</script>
<?php $content=ob_get_clean(); require __DIR__.'/../devis/includes/module_layout.php'; ?>
