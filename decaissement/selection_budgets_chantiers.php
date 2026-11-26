<?php
session_start();
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/model/Database.php';
require_once __DIR__ . '/model/Dashboard.php';
require_once __DIR__ . '/../model/Chantier.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/includes/budget_summary_helper.php';

$pdo = Database::getConnection();
$dashboardModel = new Dashboard($pdo);

$roleNorm = strtolower(trim((string)($_SESSION['role_norm'] ?? '')));
$canArchiveChantier = function_exists('hasPermission')
    ? (hasPermission('valider_archivage_chantier') || hasPermission('acces_direction') || hasPermission('acces_planning'))
    : false;

if (!$canArchiveChantier) {
    $canArchiveChantier = in_array($roleNorm, ['administrateur', 'admin', 'directeur general', 'directeur général'], true);
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['archive_action'], $_POST['chantier_id'])) {
    $redirectParams = $_POST['redirect_filters'] ?? ($_GET ?? []);
    if (!is_array($redirectParams)) {
        $redirectParams = [];
    }
    $redirectUrl = 'selection_budgets_chantiers.php';
    $redirectQs = http_build_query($redirectParams);
    if ($redirectQs !== '') {
        $redirectUrl .= '?' . $redirectQs;
    }

    if (!$canArchiveChantier) {
        $_SESSION['flash_error'] = "Vous n'avez pas l'autorisation de terminer/archiver ce chantier.";
        header('Location: ' . $redirectUrl);
        exit;
    }

    try {
        Chantier::ensureArchiveColumns($pdo);
        $chantierIdPost = (int)$_POST['chantier_id'];
        $action = (string)$_POST['archive_action'];

        if ($action === 'archive') {
            $note = trim((string)($_POST['archived_note'] ?? ''));
            $closedDate = trim((string)($_POST['closed_date'] ?? ''));
            $closedDateSql = null;
            if ($closedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $closedDate)) {
                $closedDateSql = $closedDate;
            }

            $st = $pdo->prepare('UPDATE chantier_banamur SET archived_at = NOW(), archived_by = :by, archived_note = :note, closed_date = :closed_date WHERE id = :id');
            $st->execute([
                'by' => (int)($_SESSION['user_id'] ?? 0),
                'note' => ($note !== '' ? $note : null),
                'closed_date' => $closedDateSql,
                'id' => $chantierIdPost,
            ]);
            $_SESSION['flash_success'] = 'Chantier terminé et archivé.';
        } elseif ($action === 'restore') {
            $st = $pdo->prepare('UPDATE chantier_banamur SET archived_at = NULL, archived_by = NULL, archived_note = NULL, closed_date = NULL WHERE id = :id');
            $st->execute(['id' => $chantierIdPost]);
            $_SESSION['flash_success'] = 'Chantier restauré (réactivé).';
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = 'Erreur archivage chantier: ' . $e->getMessage();
    }

    header('Location: ' . $redirectUrl);
    exit;
}

// Filtres (recherche)
$filters = [
    'chantier_id' => isset($_GET['chantier_id']) && trim((string)$_GET['chantier_id']) !== '' ? (int)$_GET['chantier_id'] : null,
    'chantier' => isset($_GET['chantier']) ? trim((string)$_GET['chantier']) : '',
    'client' => isset($_GET['client']) ? trim((string)$_GET['client']) : '',
    'entreprise' => isset($_GET['entreprise']) ? trim((string)$_GET['entreprise']) : '',
    'devis' => isset($_GET['devis']) ? trim((string)$_GET['devis']) : '',
    'statut' => isset($_GET['statut']) ? trim((string)$_GET['statut']) : '',
    'archives_scope' => isset($_GET['archives_scope']) ? trim((string)$_GET['archives_scope']) : 'active',
];

// Récupérer les chantiers (avec filtres)
$chantiers = $dashboardModel->getTousLesChantiersBudgets($filters);
$summary = budget_summary_compute($chantiers);
$summaryGlobal = $summary['global'];
$summaryActive = $summary['active'];
$summaryArchive = $summary['archive'];
$summaryStatusColor = budget_summary_status_color((float)$summaryGlobal['progression_reel']);
$summaryRestantColor = (float)$summaryGlobal['restant_reel'] < 0 ? 'red' : 'orange';
$summaryMargeColor = (float)$summaryGlobal['marge_devis'] < 0 ? 'red' : ((float)$summaryGlobal['marge_devis'] > 0 ? 'green' : 'slate');
$metricLabel = static function (string $label, string $tooltip, string $classes = ''): string {
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $safeTooltip = htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8');
    $safeClasses = htmlspecialchars($classes, ENT_QUOTES, 'UTF-8');

    return '<span class="' . $safeClasses . '" title="' . $safeTooltip . '">' . $safeLabel . ' <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>';
};

// Query string réutilisable pour exports (sans format)
$exportParams = $_GET;
unset($exportParams['format']);
unset($exportParams['report']);
$exportQSExcel = http_build_query(array_merge($exportParams, ['format' => 'excel']));
$exportQSPdf = http_build_query(array_merge($exportParams, ['format' => 'pdf']));
$exportQSSummaryExcel = http_build_query(array_merge($exportParams, ['format' => 'excel', 'report' => 'summary']));
$exportQSSummaryPdf = http_build_query(array_merge($exportParams, ['format' => 'pdf', 'report' => 'summary']));

AuditTrail::logVisualEvent('preview', [
    'module' => 'decaissement',
    'action' => 'budgets_selection_preview',
    'entity_type' => 'budget_chantiers',
    'entity_label' => 'Budgets détaillés des chantiers',
    'details' => [
        'view_mode' => 'browser_dashboard',
        'row_count' => is_countable($chantiers ?? null) ? count($chantiers) : null,
        'filters' => $filters,
    ],
], $pdo);

$pageTitle = 'Sélection Chantier - Budgets Détaillés';
$activeMenu = 'budgets';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<link rel="stylesheet" href="../assets/css/budget-workspace.css?v=20261126a">
<div class="budget-workspace min-h-screen">
    <script>
        function auditVisualPrintBudgets() {
            const payload = new URLSearchParams({
                kind: 'print',
                module: 'decaissement',
                action: 'budgets_selection_print',
                entity_type: 'budget_chantiers',
                entity_label: 'Budgets détaillés des chantiers',
                source_path: 'decaissement/selection_budgets_chantiers.php',
                details_json: JSON.stringify({
                    filters: <?= json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
                }),
                client_timestamp: new Date().toISOString()
            });

            if (navigator.sendBeacon) {
                navigator.sendBeacon('../request/audit_visual_event.php', payload);
                return;
            }

            fetch('../request/audit_visual_event.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: payload.toString(),
                keepalive: true,
                credentials: 'same-origin'
            }).catch(function() {});
        }
    </script>
    <div class="container mx-auto py-10 px-4">
        <?php if (!empty($_SESSION['flash_success'])): ?>
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-5 py-4 text-green-800">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_success']) ?>
            </div>
            <?php unset($_SESSION['flash_success']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-red-800">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars((string)$_SESSION['flash_error']) ?>
            </div>
            <?php unset($_SESSION['flash_error']); ?>
        <?php endif; ?>
        <!-- En-tête -->
        <div class="budget-hero mb-8">
            <h1 class="budget-heading">
                <i class="fas fa-chart-pie mr-2"></i> Budgets détaillés des chantiers
            </h1>
            <p class="text-xl text-gray-600 mt-4">Sélectionnez un chantier pour voir son détail budgétaire ligne par ligne</p>
            <div class="mt-4 inline-flex max-w-3xl items-start gap-3 rounded-2xl border border-indigo-100 bg-indigo-50 px-5 py-4 text-left text-sm text-indigo-900">
                <i class="fas fa-circle-info mt-0.5 text-indigo-500"></i>
                <div>
                    Les chantiers <strong>actifs</strong> s'affichent par défaut. Quand un chantier est terminé, utilisez le bloc <strong>Terminer / Archiver ce chantier</strong> sur sa carte : il disparaîtra des points actifs et restera consultable plus tard via le filtre <strong>Portée → Archives</strong>.
                </div>
            </div>

            <div class="mt-6">
                <a href="dashboard.php" class="inline-flex items-center text-indigo-600 hover:text-indigo-800">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Retour au tableau de bord
                </a>
            </div>
        </div>

        <!-- Recherche + Exports -->
        <div class="bg-white rounded-2xl shadow-xl border border-gray-100 p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                <form method="get" class="flex-1">
                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                        <!-- Chantier (select-search) -->
                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Chantier</label>
                            <input id="ss-chantier" name="chantier" value="<?= htmlspecialchars($filters['chantier'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                                class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Saisir pour rechercher…" />
                            <input id="ss-chantier-id" type="hidden" name="chantier_id" value="<?= htmlspecialchars((string)($filters['chantier_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" />
                            <div id="ss-chantier-dd" class="hidden absolute z-40 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden"></div>
                            <button type="button" id="ss-chantier-clear" class="hidden absolute right-2 top-8 text-gray-400 hover:text-gray-600" aria-label="Effacer">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Client (select-search) -->
                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                            <input id="ss-client" name="client" value="<?= htmlspecialchars($filters['client'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                                class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Saisir pour rechercher…" />
                            <div id="ss-client-dd" class="hidden absolute z-40 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden"></div>
                            <button type="button" id="ss-client-clear" class="hidden absolute right-2 top-8 text-gray-400 hover:text-gray-600" aria-label="Effacer">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Entreprise (select-search) -->
                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Entreprise</label>
                            <input id="ss-entreprise" name="entreprise" value="<?= htmlspecialchars($filters['entreprise'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                                class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Saisir pour rechercher…" />
                            <div id="ss-entreprise-dd" class="hidden absolute z-40 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden"></div>
                            <button type="button" id="ss-entreprise-clear" class="hidden absolute right-2 top-8 text-gray-400 hover:text-gray-600" aria-label="Effacer">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Devis (select-search) -->
                        <div class="relative">
                            <label class="block text-sm font-medium text-gray-700 mb-1">N° Devis</label>
                            <input id="ss-devis" name="devis" value="<?= htmlspecialchars($filters['devis'] ?? '', ENT_QUOTES, 'UTF-8') ?>" autocomplete="off"
                                class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                placeholder="Saisir pour rechercher…" />
                            <div id="ss-devis-dd" class="hidden absolute z-40 mt-2 w-full rounded-xl border border-gray-200 bg-white shadow-lg overflow-hidden"></div>
                            <button type="button" id="ss-devis-clear" class="hidden absolute right-2 top-8 text-gray-400 hover:text-gray-600" aria-label="Effacer">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                        <!-- Statut -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                            <select name="statut" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                                <?php $statutVal = (string)($filters['statut'] ?? ''); ?>
                                <option value="" <?= $statutVal === '' ? 'selected' : '' ?>>Tous</option>
                                <option value="normal" <?= $statutVal === 'normal' ? 'selected' : '' ?>>Normal (≤ 70%)</option>
                                <option value="attention" <?= $statutVal === 'attention' ? 'selected' : '' ?>>Attention (70–90%)</option>
                                <option value="critique" <?= $statutVal === 'critique' ? 'selected' : '' ?>>Critique (&gt; 90%)</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Portée</label>
                            <?php $archivesScopeVal = (string)($filters['archives_scope'] ?? 'active'); ?>
                            <select name="archives_scope" class="w-full rounded-xl border-gray-200 focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="active" <?= $archivesScopeVal === 'active' ? 'selected' : '' ?>>Actifs</option>
                                <option value="archive" <?= $archivesScopeVal === 'archive' ? 'selected' : '' ?>>Archives</option>
                                <option value="all" <?= $archivesScopeVal === 'all' ? 'selected' : '' ?>>Actifs + archives</option>
                            </select>
                        </div>

                        <!-- Actions recherche -->
                        <div class="flex items-end gap-3">
                            <button type="submit" class="inline-flex items-center justify-center w-full px-6 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-all shadow-lg">
                                <i class="fas fa-search mr-2"></i>
                                Rechercher
                            </button>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-3">
                        <a href="selection_budgets_chantiers.php" class="inline-flex items-center px-4 py-2 rounded-xl border border-gray-200 text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-rotate-left mr-2"></i>
                            Réinitialiser
                        </a>
                        <div class="text-sm text-gray-500 flex items-center">
                            <i class="fas fa-info-circle mr-2 text-gray-400"></i>
                            Astuce : sélectionnez une suggestion pour un filtre exact.
                        </div>
                    </div>
                </form>

                <!-- Exports (réagissent aux filtres via querystring) -->
                <div class="flex flex-wrap gap-3 justify-start lg:justify-end">
                    <a href="request/export_tous_budgets.php?<?= htmlspecialchars($exportQSSummaryExcel, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center px-5 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-all shadow-lg">
                        <i class="fas fa-chart-pie mr-2"></i>
                        Synthèse Excel
                    </a>
                    <a href="request/export_tous_budgets.php?<?= htmlspecialchars($exportQSSummaryPdf, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center px-5 py-3 bg-fuchsia-600 text-white rounded-xl hover:bg-fuchsia-700 transition-all shadow-lg">
                        <i class="fas fa-file-lines mr-2"></i>
                        Synthèse PDF
                    </a>
                    <a href="request/export_tous_budgets.php?<?= htmlspecialchars($exportQSExcel, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center px-5 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all shadow-lg">
                        <i class="fas fa-file-excel mr-2"></i>
                        Export Excel
                    </a>
                    <a href="request/export_tous_budgets.php?<?= htmlspecialchars($exportQSPdf, ENT_QUOTES, 'UTF-8') ?>" class="inline-flex items-center px-5 py-3 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-all shadow-lg">
                        <i class="fas fa-file-pdf mr-2"></i>
                        Export PDF
                    </a>
                    <button onclick="auditVisualPrintBudgets(); window.print();" class="inline-flex items-center px-5 py-3 bg-gray-600 text-white rounded-xl hover:bg-gray-700 transition-all shadow-lg">
                        <i class="fas fa-print mr-2"></i>
                        Imprimer
                    </button>
                </div>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-8 gap-4">
            <div class="rounded-2xl border border-indigo-100 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Chantiers trouvés</div>
                <div class="mt-2 text-3xl font-bold text-indigo-900"><?= number_format((float)$summaryGlobal['count'], 0, ',', ' ') ?></div>
                <div class="mt-2 text-xs text-gray-500">Selon les filtres actuels</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">Chantiers archivés</div>
                <div class="mt-2 text-3xl font-bold text-slate-900"><?= number_format((float)$summary['archived_count'], 0, ',', ' ') ?></div>
                <div class="mt-2 text-xs text-gray-500">Dans le résultat courant</div>
            </div>

            <div class="rounded-2xl border border-<?= $summaryStatusColor ?>-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Consommation interne', 'Part des dépenses réelles par rapport au budget réel.', 'text-xs font-semibold uppercase tracking-wide text-' . $summaryStatusColor . '-600') ?></div>
                <div class="mt-2 text-3xl font-bold text-<?= $summaryStatusColor ?>-900"><?= number_format((float)$summaryGlobal['progression_reel'], 1, ',', ' ') ?>%</div>
                <div class="mt-3 h-2 w-full rounded-full bg-gray-200">
                    <div class="h-2 rounded-full bg-<?= $summaryStatusColor ?>-500" style="width: <?= min(max((float)$summaryGlobal['progression_reel'], 0), 100) ?>%"></div>
                </div>
                <div class="mt-2 text-xs text-gray-500">Base budget réel</div>
            </div>

            <div class="rounded-2xl border border-cyan-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Consommation sur devis', 'Part des dépenses réelles par rapport au montant du devis client.', 'text-xs font-semibold uppercase tracking-wide text-cyan-600') ?></div>
                <div class="mt-2 text-3xl font-bold text-cyan-900"><?= number_format((float)$summaryGlobal['progression_devis'], 1, ',', ' ') ?>%</div>
                <div class="mt-3 h-2 w-full rounded-full bg-gray-200">
                    <div class="h-2 rounded-full bg-cyan-500" style="width: <?= min(max((float)$summaryGlobal['progression_devis'], 0), 100) ?>%"></div>
                </div>
                <div class="mt-2 text-xs text-gray-500">Base devis client</div>
            </div>

            <div class="rounded-2xl border border-indigo-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Budget réel total', 'Référence de coût interne consolidée des chantiers affichés.', 'text-xs font-semibold uppercase tracking-wide text-indigo-600') ?></div>
                <div class="mt-2 text-2xl font-bold text-indigo-900"><?= number_format((float)$summaryGlobal['budget_reel'], 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-xs text-gray-500">Budget interne consolidé</div>
            </div>

            <div class="rounded-2xl border border-sky-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Total devis', 'Montant contractuel total vendu au client.', 'text-xs font-semibold uppercase tracking-wide text-sky-600') ?></div>
                <div class="mt-2 text-2xl font-bold text-sky-900"><?= number_format((float)$summaryGlobal['budget_devis'], 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-xs text-gray-500">Montant contractuel cumulé</div>
            </div>

            <div class="rounded-2xl border border-green-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Dépensé réel', 'Somme effectivement décaissée via les fiches.', 'text-xs font-semibold uppercase tracking-wide text-green-600') ?></div>
                <div class="mt-2 text-2xl font-bold text-green-900"><?= number_format((float)$summaryGlobal['depenses'], 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-xs text-gray-500"><?= number_format((float)$summaryGlobal['fiches'], 0, ',', ' ') ?> fiche(s) cumulées</div>
            </div>

            <div class="rounded-2xl border border-<?= $summaryRestantColor ?>-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Restant sur budget réel', 'Budget réel moins dépenses réelles. Négatif = dépassement.', 'text-xs font-semibold uppercase tracking-wide text-' . $summaryRestantColor . '-600') ?></div>
                <div class="mt-2 text-2xl font-bold text-<?= $summaryRestantColor ?>-900"><?= number_format((float)$summaryGlobal['restant_reel'], 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-xs text-gray-500">Budget réel moins dépensé réel</div>
            </div>

            <div class="rounded-2xl border border-<?= $summaryMargeColor ?>-100 bg-white p-5 shadow-sm">
                <div><?= $metricLabel('Marge sur devis', 'Devis client moins dépenses réelles. Négatif = perte par rapport au devis.', 'text-xs font-semibold uppercase tracking-wide text-' . $summaryMargeColor . '-600') ?></div>
                <div class="mt-2 text-2xl font-bold text-<?= $summaryMargeColor ?>-900"><?= number_format((float)$summaryGlobal['marge_devis'], 0, ',', ' ') ?> F</div>
                <div class="mt-2 text-xs text-gray-500">Devis moins dépensé réel</div>
            </div>
        </div>

        <div class="mb-8 grid grid-cols-1 xl:grid-cols-2 gap-4">
            <div class="rounded-2xl border border-emerald-100 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-emerald-600">Totaux actifs</div>
                        <div class="mt-2 text-2xl font-bold text-emerald-900"><?= number_format((float)$summaryActive['count'], 0, ',', ' ') ?> chantier(s)</div>
                    </div>
                    <div class="rounded-full bg-emerald-50 px-3 py-1 text-sm font-semibold text-emerald-700">
                        Interne: <?= number_format((float)$summaryActive['progression_reel'], 1, ',', ' ') ?>%
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div><?= $metricLabel('Budget réel', 'Référence de coût interne pour les chantiers actifs.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryActive['budget_reel'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Devis', 'Montant contractuel cumulé des chantiers actifs.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryActive['budget_devis'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Dépensé réel', 'Somme déjà sortie sur les chantiers actifs.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryActive['depenses'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Restant budget réel', 'Budget réel actif moins dépenses réelles. Négatif = dépassement.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-<?= (float)$summaryActive['restant_reel'] < 0 ? 'red' : 'orange' ?>-700"><?= number_format((float)$summaryActive['restant_reel'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Marge sur devis', 'Devis actif moins dépenses réelles. Négatif = perte.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-<?= (float)$summaryActive['marge_devis'] < 0 ? 'red' : ((float)$summaryActive['marge_devis'] > 0 ? 'green' : 'slate') ?>-700"><?= number_format((float)$summaryActive['marge_devis'], 0, ',', ' ') ?> F</div>
                    </div>
                </div>
                <div class="mt-3 text-xs text-gray-500"><?= number_format((float)$summaryActive['fiches'], 0, ',', ' ') ?> fiche(s) | Consommation sur devis: <?= number_format((float)$summaryActive['progression_devis'], 1, ',', ' ') ?>%</div>
            </div>

            <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-600">Totaux archives</div>
                        <div class="mt-2 text-2xl font-bold text-slate-900"><?= number_format((float)$summaryArchive['count'], 0, ',', ' ') ?> chantier(s)</div>
                    </div>
                    <div class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold text-slate-700">
                        Interne: <?= number_format((float)$summaryArchive['progression_reel'], 1, ',', ' ') ?>%
                    </div>
                </div>
                <div class="mt-4 grid grid-cols-2 md:grid-cols-3 gap-3 text-sm">
                    <div>
                        <div><?= $metricLabel('Budget réel', 'Référence de coût interne pour les chantiers archivés.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryArchive['budget_reel'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Devis', 'Montant contractuel cumulé des chantiers archivés.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryArchive['budget_devis'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Dépensé réel', 'Somme déjà sortie sur les chantiers archivés.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-gray-900"><?= number_format((float)$summaryArchive['depenses'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Restant budget réel', 'Budget réel archivé moins dépenses réelles. Négatif = dépassement.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-<?= (float)$summaryArchive['restant_reel'] < 0 ? 'red' : 'orange' ?>-700"><?= number_format((float)$summaryArchive['restant_reel'], 0, ',', ' ') ?> F</div>
                    </div>
                    <div>
                        <div><?= $metricLabel('Marge sur devis', 'Devis archivé moins dépenses réelles. Négatif = perte.', 'text-gray-500') ?></div>
                        <div class="font-semibold text-<?= (float)$summaryArchive['marge_devis'] < 0 ? 'red' : ((float)$summaryArchive['marge_devis'] > 0 ? 'green' : 'slate') ?>-700"><?= number_format((float)$summaryArchive['marge_devis'], 0, ',', ' ') ?> F</div>
                    </div>
                </div>
                <div class="mt-3 text-xs text-gray-500"><?= number_format((float)$summaryArchive['fiches'], 0, ',', ' ') ?> fiche(s) | Consommation sur devis: <?= number_format((float)$summaryArchive['progression_devis'], 1, ',', ' ') ?>%</div>
            </div>
        </div>

        <?php if ((float)$summaryGlobal['depassement'] > 0 || (int)$summaryGlobal['no_budget_with_depense_count'] > 0): ?>
            <div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 text-amber-900">
                <div class="font-semibold">Lecture des pourcentages globaux</div>
                <div class="mt-1 text-sm">
                    La consommation globale dépasse 100% quand les dépenses cumulées sont supérieures au budget réel consolidé.
                    <?php if ((float)$summaryGlobal['depassement'] > 0): ?>
                        Dépassement cumulé : <strong><?= number_format((float)$summaryGlobal['depassement'], 0, ',', ' ') ?> F</strong>.
                    <?php endif; ?>
                    <?php if ((int)$summaryGlobal['no_budget_with_depense_count'] > 0): ?>
                        <strong><?= number_format((float)$summaryGlobal['no_budget_with_depense_count'], 0, ',', ' ') ?> chantier(s)</strong> ont des dépenses malgré un budget réel nul, pour <strong><?= number_format((float)$summaryGlobal['no_budget_with_depense_total'], 0, ',', ' ') ?> F</strong>.
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Grille des chantiers -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($chantiers as $chantier):
                $budgetTotal = (float)($chantier['budget_interne_calc'] ?? $chantier['budget_total'] ?? 0); // utilise le budget réel consolidé si disponible
                $budgetDevis = (float)($chantier['budget_devis_total'] ?? $chantier['budget_total'] ?? 0);
                $totalDepenses = (float)($chantier['total_depenses'] ?? 0);
                $resteReel = $budgetTotal - $totalDepenses;
                $margeDevis = $budgetDevis - $totalDepenses;
                $pourcentageInterne = $budgetTotal > 0 ? ($totalDepenses / max($budgetTotal, 1)) * 100 : 0;
                $pourcentageDevis = $budgetDevis > 0 ? ($totalDepenses / max($budgetDevis, 1)) * 100 : 0;
                $statusColor = $pourcentageInterne > 90 ? 'red' : ($pourcentageInterne > 70 ? 'yellow' : 'green');
                $resteReelColor = $resteReel < 0 ? 'red' : 'orange';
                $margeDevisColor = $margeDevis < 0 ? 'red' : ($margeDevis > 0 ? 'green' : 'slate');
                $isArchived = !empty($chantier['archived_at'] ?? null);
                $libChantier = htmlspecialchars($chantier['lib_chantier'] ?? '', ENT_QUOTES, 'UTF-8');
                $numChantier = htmlspecialchars($chantier['num_chantier'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
                $badgeReel = (isset($chantier['budget_interne_calc']) && (float)$chantier['budget_interne_calc'] > 0 && (float)($chantier['budget_interne_calc']) != (float)($chantier['budget_total'] ?? 0));
            ?>
                <div class="bg-white rounded-2xl shadow-xl hover:shadow-2xl transition-all p-6 border border-gray-100 cursor-pointer transform hover:-translate-y-1"
                    onclick="window.location.href='detail_budgetaire_chantier.php?chantier_id=<?= $chantier['id_chantier'] ?>'">

                    <!-- En-tête du chantier -->
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <h3 class="text-lg font-bold text-gray-900 mb-1">
                                <?= $libChantier ?>
                            </h3>
                            <p class="text-sm text-gray-600">
                                N° <?= $numChantier ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-<?= $statusColor ?>-100 text-<?= $statusColor ?>-800">
                                <?= $pourcentageInterne > 90 ? '🚨 Critique' : ($pourcentageInterne > 70 ? '⚠️ Attention' : '✅ Normal') ?>
                            </span>
                            <?php if ($isArchived): ?>
                                <span class="mt-2 inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-slate-200 text-slate-800">
                                    Archivé
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Informations budgétaires -->
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600" title="Référence de coût interne du chantier.">Budget réel <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                            <span class="text-sm font-bold text-indigo-600">
                                <?= number_format($budgetTotal, 0, ',', ' ') ?> F<?= $badgeReel ? '<span class="ml-1 text-xs text-indigo-500">(réel)</span>' : '' ?>
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600" title="Montant contractuel vendu au client.">Devis <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                            <span class="text-sm font-bold text-sky-600">
                                <?= number_format($budgetDevis, 0, ',', ' ') ?> F
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600" title="Somme effectivement décaissée sur ce chantier.">Dépensé réel <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                            <span class="text-sm font-bold text-green-600">
                                <?= number_format($totalDepenses, 0, ',', ' ') ?> F
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600" title="Budget réel moins dépenses réelles. Négatif = dépassement.">Restant budget réel <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                            <span class="text-sm font-bold text-<?= $resteReelColor ?>-600">
                                <?= number_format($resteReel, 0, ',', ' ') ?> F
                            </span>
                        </div>

                        <div class="flex justify-between items-center">
                            <span class="text-sm font-medium text-gray-600" title="Devis moins dépenses réelles. Négatif = perte par rapport au devis.">Marge sur devis <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                            <span class="text-sm font-bold text-<?= $margeDevisColor ?>-600">
                                <?= number_format($margeDevis, 0, ',', ' ') ?> F
                            </span>
                        </div>

                        <!-- Barre de progression -->
                        <div class="mt-4">
                            <div class="flex justify-between text-sm mb-1">
                                <span class="text-gray-600" title="Part des dépenses réelles par rapport au budget réel.">Consommation interne <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                                <span class="font-medium"><?= number_format($pourcentageInterne, 1) ?>%</span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-3">
                                <div class="bg-<?= $statusColor ?>-600 h-3 rounded-full transition-all duration-500"
                                    style="width: <?= min($pourcentageInterne, 100) ?>%"></div>
                            </div>
                            <div class="mt-2 flex justify-between text-xs text-gray-500">
                                <span title="Part des dépenses réelles par rapport au devis client.">Consommation sur devis <i class="fas fa-circle-info text-[11px] opacity-60"></i></span>
                                <span><?= number_format($pourcentageDevis, 1) ?>%</span>
                            </div>
                        </div>

                        <!-- Nombre de fiches -->
                        <div class="mt-4 text-xs text-gray-500 flex items-center justify-between">
                            <span>
                                <i class="fas fa-file-invoice mr-1"></i>
                                <?= $chantier['nombre_fiches'] ?> fiche(s)
                            </span>
                            <span class="text-indigo-600 font-medium">
                                Voir détail →
                            </span>
                        </div>

                        <?php if ($isArchived): ?>
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-700">
                                <div><strong>Archivé le :</strong> <?= htmlspecialchars((string)($chantier['archived_at'] ?? '')) ?></div>
                                <?php if (!empty($chantier['closed_date'])): ?>
                                    <div class="mt-1"><strong>Fin réelle :</strong> <?= htmlspecialchars((string)$chantier['closed_date']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($chantier['archived_note'])): ?>
                                    <div class="mt-1"><strong>Bilan :</strong> <?= nl2br(htmlspecialchars((string)$chantier['archived_note'])) ?></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="mt-4 border-t border-gray-100 pt-4" onclick="event.stopPropagation();">
                            <?php if (!empty($canArchiveChantier)): ?>
                                <?php if (!$isArchived): ?>
                                    <div class="mb-3 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                                        <div class="flex items-center gap-2 font-bold">
                                            <i class="fas fa-box-archive"></i>
                                            Terminer / Archiver ce chantier
                                        </div>
                                        <div class="mt-1 text-xs text-red-800">
                                            Dès validation, ce chantier ne sera plus disponible pour de nouvelles fiches et sera consultable dans <strong>Portée → Archives</strong>. L'archivage reste possible même si la progression est inférieure à 100%.
                                        </div>
                                    </div>
                                    <form method="post" class="grid grid-cols-1 gap-3 rounded-2xl border border-red-100 bg-white p-4 shadow-sm">
                                        <input type="hidden" name="archive_action" value="archive">
                                        <input type="hidden" name="chantier_id" value="<?= (int)$chantier['id_chantier'] ?>">
                                        <?php foreach ($filters as $filterKey => $filterValue): ?>
                                            <input type="hidden" name="redirect_filters[<?= htmlspecialchars((string)$filterKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string)$filterValue, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endforeach; ?>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Date de fin réelle</label>
                                            <input type="date" name="closed_date" class="w-full rounded-xl border-gray-200 text-sm focus:border-red-500 focus:ring-red-500">
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-700 mb-1">Bilan de fin</label>
                                            <textarea name="archived_note" rows="2" class="w-full rounded-xl border-gray-200 text-sm focus:border-red-500 focus:ring-red-500" placeholder="Ex: difficultés, points clés, remarques client, leçons apprises..."></textarea>
                                        </div>
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-700" onclick="return confirm('Terminer et archiver ce chantier ? Il ne sera plus sélectionnable pour de nouvelles fiches.');">
                                            <i class="fas fa-box-archive mr-2"></i>
                                            Terminer & archiver
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="mb-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-800">
                                        <div class="flex items-center gap-2 font-bold">
                                            <i class="fas fa-box-archive"></i>
                                            Chantier archivé
                                        </div>
                                        <div class="mt-1 text-xs text-slate-700">
                                            Ce chantier est déjà sorti des points actifs. Utilisez le bouton ci-dessous seulement si vous voulez le réactiver.
                                        </div>
                                    </div>
                                    <form method="post" class="flex items-center justify-end rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">
                                        <input type="hidden" name="archive_action" value="restore">
                                        <input type="hidden" name="chantier_id" value="<?= (int)$chantier['id_chantier'] ?>">
                                        <?php foreach ($filters as $filterKey => $filterValue): ?>
                                            <input type="hidden" name="redirect_filters[<?= htmlspecialchars((string)$filterKey, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars((string)$filterValue, ENT_QUOTES, 'UTF-8') ?>">
                                        <?php endforeach; ?>
                                        <button type="submit" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50" onclick="return confirm('Restaurer ce chantier ?');">
                                            <i class="fas fa-rotate-left mr-2"></i>
                                            Restaurer
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                                    <div class="flex items-center gap-2 font-bold">
                                        <i class="fas fa-lock"></i>
                                        Archivage indisponible pour votre profil
                                    </div>
                                    <div class="mt-1 text-xs text-amber-800">
                                        Vous pouvez consulter ce chantier, mais vous n'avez pas le droit de le terminer / archiver depuis cet écran.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Message si aucun chantier -->
        <?php if (empty($chantiers)): ?>
            <div class="text-center py-12">
                <div class="bg-gray-100 rounded-full w-24 h-24 mx-auto flex items-center justify-center mb-4">
                    <i class="fas fa-building text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-medium text-gray-900 mb-2">Aucun chantier trouvé</h3>
                <p class="text-gray-600">Il n'y a actuellement aucun chantier avec des données budgétaires disponibles.</p>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    function createSelectSearch(opts) {
        const input = document.getElementById(opts.inputId);
        const hidden = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
        const dropdown = document.getElementById(opts.dropdownId);
        const clearBtn = document.getElementById(opts.clearId);

        let abortController = null;
        let lastQuery = '';
        let hasSelection = false;

        function setClearVisible() {
            const hasValue = (input.value || '').trim() !== '';
            if (clearBtn) {
                clearBtn.classList.toggle('hidden', !hasValue);
            }
        }

        function closeDropdown() {
            dropdown.classList.add('hidden');
            dropdown.innerHTML = '';
        }

        function openDropdown() {
            dropdown.classList.remove('hidden');
        }

        function renderItems(items) {
            if (!items || !items.length) {
                dropdown.innerHTML = '<div class="px-4 py-3 text-sm text-gray-500">Aucun résultat</div>';
                openDropdown();
                return;
            }

            dropdown.innerHTML = '';
            for (const it of items) {
                const label = String(it.label ?? '');
                const value = String(it.value ?? '');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'w-full text-left px-4 py-2 text-sm hover:bg-indigo-50 focus:bg-indigo-50 focus:outline-none';
                btn.setAttribute('data-ss-value', value);
                btn.setAttribute('data-ss-label', label);
                btn.textContent = label;
                dropdown.appendChild(btn);
            }
            openDropdown();
        }

        async function fetchItems(q) {
            const query = (q || '').trim();
            if (query.length < (opts.minChars ?? 1)) {
                closeDropdown();
                return;
            }

            if (query === lastQuery) {
                return;
            }
            lastQuery = query;

            if (abortController) {
                abortController.abort();
            }
            abortController = new AbortController();

            try {
                const url = `${opts.endpoint}?type=${encodeURIComponent(opts.type)}&q=${encodeURIComponent(query)}`;
                const resp = await fetch(url, {
                    signal: abortController.signal,
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const data = await resp.json();
                if (!data || data.ok !== true) {
                    renderItems([]);
                    return;
                }
                renderItems(data.items || []);
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                renderItems([]);
            }
        }

        input.addEventListener('input', () => {
            setClearVisible();
            hasSelection = false;
            if (hidden) hidden.value = '';
            fetchItems(input.value);
        });

        input.addEventListener('focus', () => {
            setClearVisible();
            fetchItems(input.value);
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDropdown();
                return;
            }
        });

        dropdown.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-ss-value]');
            if (!btn) return;
            const value = btn.getAttribute('data-ss-value') || '';
            const label = btn.getAttribute('data-ss-label') || '';
            input.value = label;
            if (hidden) hidden.value = value;
            hasSelection = true;
            setClearVisible();
            closeDropdown();
        });

        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                input.value = '';
                if (hidden) hidden.value = '';
                hasSelection = false;
                lastQuery = '';
                setClearVisible();
                closeDropdown();
                input.focus();
            });
        }

        document.addEventListener('click', (e) => {
            if (e.target === input) return;
            if (dropdown.contains(e.target)) return;
            if (clearBtn && clearBtn.contains(e.target)) return;
            closeDropdown();
        });

        // Etat initial
        setClearVisible();
        if (hidden && (hidden.value || '').trim() !== '') {
            hasSelection = true;
        }
    }

    // Animation des barres de progression au chargement
    document.addEventListener('DOMContentLoaded', function() {
        // Select-search
        const endpoint = '../api/suggest_budget_filters.php';
        createSelectSearch({
            inputId: 'ss-chantier',
            hiddenId: 'ss-chantier-id',
            dropdownId: 'ss-chantier-dd',
            clearId: 'ss-chantier-clear',
            endpoint,
            type: 'chantier',
            minChars: 1
        });
        createSelectSearch({
            inputId: 'ss-client',
            dropdownId: 'ss-client-dd',
            clearId: 'ss-client-clear',
            endpoint,
            type: 'client',
            minChars: 1
        });
        createSelectSearch({
            inputId: 'ss-entreprise',
            dropdownId: 'ss-entreprise-dd',
            clearId: 'ss-entreprise-clear',
            endpoint,
            type: 'entreprise',
            minChars: 1
        });
        createSelectSearch({
            inputId: 'ss-devis',
            dropdownId: 'ss-devis-dd',
            clearId: 'ss-devis-clear',
            endpoint,
            type: 'devis',
            minChars: 1
        });

        const progressBars = document.querySelectorAll('.bg-green-600, .bg-yellow-600, .bg-red-600');
        progressBars.forEach((bar, index) => {
            const width = bar.style.width;
            bar.style.width = '0%';
            setTimeout(() => {
                bar.style.width = width;
            }, 100 + (index * 50));
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>