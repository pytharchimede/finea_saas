<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

if (!\AccessManager::hasPermission('acces_admin')) {
    http_response_code(403);
    header('Location: ../acces_refuse.php?type=admin&page=' . urlencode('admin/logistics_fuel_tools.php'));
    exit;
}

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/FuelLogisticsService.php';

$pdo = Database::getConnection();
$fuelService = new FuelLogisticsService($pdo);
$flashSuccess = '';
$flashError = '';
$backfillResult = null;
$activeStartDate = $fuelService->activeStartDate();

function admin_fuel_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_fuel_money($value): string
{
    return number_format((float)$value, 0, ',', ' ') . ' FCFA';
}

function admin_fuel_chantiers(PDO $pdo): array
{
    try {
        return $pdo->query("SELECT id, code, titre, TRIM(CONCAT(COALESCE(code,''), ' - ', COALESCE(titre,''))) label FROM chantier_banamur WHERE archived_at IS NULL AND devis_id IS NOT NULL AND devis_id > 0 ORDER BY code ASC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string)($_POST['fuel_admin_action'] ?? '');
    try {
        if ($action === 'generate_lines') {
            $result = $fuelService->generateFuelDebourses((int)($_POST['chantier_id'] ?? 0) ?: null);
            $flashSuccess = (int)$result['created'] . ' ligne(s) carburant creee(s), ' . (int)$result['existing'] . ' deja existante(s).';
            AuditTrail::log('admin_logistics_fuel_lines_generated', [
                'module' => 'admin',
                'action' => 'generate_logistics_fuel_lines',
                'entity_type' => 'fuel_tools',
                'entity_label' => 'Generation lignes carburant',
                'user_id' => $_SESSION['user_id'] ?? null,
                'details' => $result + ['chantier_id' => $_POST['chantier_id'] ?? null],
            ], $pdo);
        } elseif ($action === 'backfill_vouchers') {
            $dryRun = (string)($_POST['backfill_mode'] ?? 'preview') !== 'generate';
            $backfillResult = $fuelService->backfillMissingFicheVouchers(
                (string)($_POST['date_from'] ?? date('Y-m-d')),
                (int)($_POST['limit'] ?? 20),
                $dryRun,
                (int)($_SESSION['user_id'] ?? 0)
            );
            $flashSuccess = $dryRun
                ? ((int)$backfillResult['candidate_count'] . ' fiche(s) carburant sans bon trouvee(s) en simulation.')
                : (count((array)$backfillResult['generated']) . ' bon(s) carburant genere(s), ' . count((array)$backfillResult['failed']) . ' echec(s).');
            AuditTrail::log('admin_logistics_fuel_vouchers_backfilled', [
                'module' => 'admin',
                'action' => 'backfill_logistics_fuel_vouchers',
                'entity_type' => 'fuel_tools',
                'entity_label' => 'Rattrapage bons carburant',
                'user_id' => $_SESSION['user_id'] ?? null,
                'details' => [
                    'dry_run' => $dryRun,
                    'date_from' => $backfillResult['date_from'] ?? null,
                    'limit' => $backfillResult['limit'] ?? null,
                    'candidate_count' => $backfillResult['candidate_count'] ?? null,
                    'generated' => count((array)($backfillResult['generated'] ?? [])),
                    'failed' => count((array)($backfillResult['failed'] ?? [])),
                ],
            ], $pdo);
        }
    } catch (Throwable $e) {
        $flashError = $e->getMessage();
    }
}

$chantiers = admin_fuel_chantiers($pdo);
$pageTitle = 'Outils carburant logistique';
$activeMenu = 'admin';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>

<div class="min-h-screen bg-slate-50 px-6 py-8">
    <div class="mx-auto max-w-7xl">
        <section class="mb-8 rounded-[30px] bg-gradient-to-r from-slate-950 via-[#1d2b57] to-slate-900 px-8 py-9 text-white shadow-[0_24px_70px_rgba(15,23,42,0.25)]">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.32em] text-amber-200">Administration</p>
                    <h1 class="mt-3 text-3xl font-semibold md:text-4xl">Outils carburant logistique</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-200">Actions sensibles reservees aux administrateurs : generation des lignes carburant et rattrapage controle des bons.</p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="dashboard_admin.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900"><i class="fas fa-arrow-left mr-2"></i>Dashboard admin</a>
                    <a href="../logi/fuel.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 hover:bg-white/10"><i class="fas fa-gas-pump mr-2"></i>Page carburant</a>
                </div>
            </div>
        </section>

        <?php if ($flashSuccess !== ''): ?>
            <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 font-semibold text-emerald-800"><?= admin_fuel_h($flashSuccess) ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 font-semibold text-rose-800"><?= admin_fuel_h($flashError) ?></div>
        <?php endif; ?>

        <div class="grid gap-6 xl:grid-cols-2">
            <section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-orange-600">Generation technique</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Lignes carburant</h2>
                    <p class="mt-2 text-sm text-slate-500">Cree le debourse Transport logistique et sa ligne Carburant pour les chantiers eligibles.</p>
                </div>
                <form method="post" class="grid gap-5">
                    <input type="hidden" name="fuel_admin_action" value="generate_lines">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Chantier specifique</span>
                        <select name="chantier_id" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-slate-800 outline-none focus:border-orange-300 focus:ring-4 focus:ring-orange-100">
                            <option value="">Tous les chantiers</option>
                            <?php foreach ($chantiers as $chantier): ?>
                                <option value="<?= (int)($chantier['id'] ?? 0) ?>"><?= admin_fuel_h($chantier['label'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">Action admin : a executer uniquement lors du parametrage ou de la reprise de donnees.</div>
                    <button type="submit" class="rounded-2xl bg-slate-900 px-6 py-3 font-semibold text-white hover:bg-slate-800">Generer les lignes carburant</button>
                </form>
            </section>

            <section class="rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-6">
                    <p class="text-xs font-semibold uppercase tracking-[0.22em] text-orange-600">Controle admin</p>
                    <h2 class="mt-2 text-2xl font-semibold text-slate-900">Rattrapage des bons</h2>
                    <p class="mt-2 text-sm text-slate-500">Genere uniquement les bons manquants pour les fiches carburant deja decaissees.</p>
                </div>
                <form method="post" class="grid gap-5 md:grid-cols-2">
                    <input type="hidden" name="fuel_admin_action" value="backfill_vouchers">
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">A partir du</span>
                        <input type="date" name="date_from" value="<?= admin_fuel_h($activeStartDate) ?>" min="<?= admin_fuel_h($activeStartDate) ?>" required class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-slate-800 outline-none focus:border-orange-300 focus:ring-4 focus:ring-orange-100">
                    </label>
                    <label class="block">
                        <span class="text-sm font-semibold text-slate-700">Limite</span>
                        <input type="number" name="limit" value="20" min="1" max="200" required class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-slate-800 outline-none focus:border-orange-300 focus:ring-4 focus:ring-orange-100">
                    </label>
                    <label class="block md:col-span-2">
                        <span class="text-sm font-semibold text-slate-700">Mode</span>
                        <select name="backfill_mode" class="mt-2 h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 text-slate-800 outline-none focus:border-orange-300 focus:ring-4 focus:ring-orange-100">
                            <option value="preview">Simulation seulement</option>
                            <option value="generate">Generer les bons manquants</option>
                        </select>
                    </label>
                    <div class="md:col-span-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900">
                        Point de depart actif : <?= admin_fuel_h($activeStartDate) ?>. Les donnees precedentes restent archivees.
                    </div>
                    <button type="submit" class="md:col-span-2 rounded-2xl bg-orange-600 px-6 py-3 font-semibold text-white hover:bg-orange-700">Executer le controle</button>
                </form>
            </section>
        </div>

        <?php if (is_array($backfillResult)): ?>
            <section class="mt-6 rounded-[28px] border border-slate-200 bg-white p-6 shadow-sm">
                <div class="mb-4 flex flex-wrap items-center gap-3 text-sm font-semibold text-slate-700">
                    <span>Mode: <?= !empty($backfillResult['dry_run']) ? 'simulation' : 'generation' ?></span>
                    <span>Date: <?= admin_fuel_h((string)$backfillResult['date_from']) ?></span>
                    <span>Limite: <?= (int)$backfillResult['limit'] ?></span>
                    <span>Candidats: <?= (int)$backfillResult['candidate_count'] ?></span>
                    <?php if (empty($backfillResult['dry_run'])): ?>
                        <span>Generes: <?= count((array)$backfillResult['generated']) ?></span>
                        <span>Echecs: <?= count((array)$backfillResult['failed']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full border-separate border-spacing-y-2">
                        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th>Fiche</th><th>Date</th><th>Chantier</th><th>Ligne</th><th>Montant</th><th>Bon</th></tr></thead>
                        <tbody>
                            <?php foreach ((array)$backfillResult['candidates'] as $candidate): ?>
                                <?php
                                $generatedCode = '';
                                foreach ((array)$backfillResult['generated'] as $generatedRow) {
                                    if ((string)($generatedRow['num_fiche'] ?? '') === (string)($candidate['num_fiche'] ?? '')) {
                                        $generatedCode = (string)($generatedRow['voucher_code'] ?? '');
                                        break;
                                    }
                                }
                                ?>
                                <tr class="bg-slate-50">
                                    <td class="rounded-l-2xl px-4 py-3 font-semibold"><?= admin_fuel_h($candidate['num_fiche'] ?? '') ?></td>
                                    <td><?= admin_fuel_h(substr((string)($candidate['ref_date'] ?? ''), 0, 10)) ?></td>
                                    <td><?= admin_fuel_h(trim((string)($candidate['chantier_code'] ?? '') . ' ' . (string)($candidate['chantier_titre'] ?? ''))) ?></td>
                                    <td><?= admin_fuel_h($candidate['ligne_designation'] ?? '') ?></td>
                                    <td><?= admin_fuel_money((float)($candidate['montant_fiche'] ?? 0)) ?></td>
                                    <td class="rounded-r-2xl"><?= $generatedCode !== '' ? admin_fuel_h($generatedCode) : '-' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($backfillResult['candidates'])): ?>
                                <tr><td colspan="6" class="rounded-2xl bg-slate-50 px-4 py-5 text-center text-slate-500">Aucun bon manquant dans ce perimetre.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
