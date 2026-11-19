<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhMissionController.php';
require_once __DIR__ . '/../model/CompanyBranding.php';

$pdo = \Database::getConnection();
$controller = new \RhMissionController($pdo);
$missionManager = $controller->missions();
$viewData = $controller->listView($_GET);
$statusLabels = $viewData['statusLabels'];
$filters = $viewData['filters'];
$page = $viewData['page'];
$result = $viewData['result'];
$branding=CompanyBranding::get($pdo);
$filterChantiers=$pdo->query("SELECT id,code,titre FROM chantier_banamur WHERE archived_at IS NULL ORDER BY code,titre")->fetchAll(PDO::FETCH_ASSOC)?:[];
$filterPersonnel=$pdo->query("SELECT id_personnel_soignant id,nom_personnel_soignant label,matricule_personnel_soignant matricule FROM personnel WHERE date_sortie IS NULL OR date_sortie='0000-00-00' ORDER BY nom_personnel_soignant")->fetchAll(PDO::FETCH_ASSOC)?:[];
$filterValidators=$pdo->query("SELECT id,TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) label,mail_pro FROM user_devis_banamur WHERE active=1 ORDER BY prenom,nom")->fetchAll(PDO::FETCH_ASSOC)?:[];

function missionStatusBadge(string $status): array
{
    $status = trim($status);

    if ($status === 'approved' || $status === 'closed') {
        return ['bg-emerald-100 text-emerald-700', 'fas fa-circle-check'];
    }

    if ($status === 'departed') {
        return ['bg-sky-100 text-sky-700', 'fas fa-plane-departure'];
    }

    if ($status === 'returned') {
        return ['bg-indigo-100 text-indigo-700', 'fas fa-plane-arrival'];
    }

    if ($status === 'submitted') {
        return ['bg-amber-100 text-amber-700', 'fas fa-paper-plane'];
    }

    if ($status === 'cancelled') {
        return ['bg-rose-100 text-rose-700', 'fas fa-ban'];
    }

    return ['bg-slate-100 text-slate-700', 'fas fa-pen'];
}

$pageTitle = 'Ordres de mission';
$activeMenu = 'rh_missions';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>:root{--om-primary:<?=htmlspecialchars((string)$branding['primary_color'])?>;--om-accent:<?=htmlspecialchars((string)$branding['accent_color'])?>}.om-list{background:radial-gradient(circle at top right,color-mix(in srgb,var(--om-accent) 17%,transparent),transparent 32%),#f8fafc}.om-primary{background:var(--om-primary);color:var(--om-accent)}.om-card-head{background:linear-gradient(120deg,var(--om-primary) 0 72%,var(--om-accent) 72%)}</style>
<div class="om-list min-h-screen pb-12">
    <div class="container mx-auto px-4 py-8">
        <div class="rounded-[32px] border border-slate-200 bg-white/90 p-8 shadow-[0_18px_60px_rgba(15,23,42,0.07)] backdrop-blur">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
                <div>
                    <p class="text-xs font-black uppercase tracking-[0.32em] text-amber-600">FINÉA · RH · ISO 9001</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-slate-950 md:text-4xl">Ordres de mission</h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-600 md:text-base">
                        Brouillons, validation, depart et retour de mission — avec saisie des frais en lignes type devis.
                    </p>
                </div>
                <div class="flex flex-wrap gap-3">
                    <a href="../request/export_mission_orders_rh_excel.php?<?= htmlspecialchars(http_build_query($filters)) ?>" class="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                        <i class="fas fa-file-excel mr-2"></i>
                        Exporter le recap Excel
                    </a>
                    <a href="../request/export_mission_orders_rh_pdf.php?<?= htmlspecialchars(http_build_query($filters)) ?>" target="_blank" rel="noopener" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                        <i class="fas fa-file-pdf mr-2 text-rose-600"></i>
                        Exporter le recap PDF
                    </a>
                    <a href="dashboard_rh.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Tableau de bord RH</a>
                    <a href="ordre_mission_rh.php" class="om-primary rounded-2xl px-5 py-3 text-sm font-black">
                        <i class="fas fa-plus mr-2"></i>
                        Nouvel ordre
                    </a>
                </div>
            </div>

            <form method="get" class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div class="md:col-span-2 xl:col-span-3">
                    <label class="mb-2 block text-sm font-medium text-slate-700">Recherche</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Code, titre, destination, nom..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:bg-white">
                </div>
                <div><label class="mb-2 block text-sm font-bold text-slate-700">Chantiers</label><select name="chantier_ids[]" multiple data-modern-select data-placeholder="Tous les chantiers">
                    <?php foreach($filterChantiers as $item): ?><option value="<?=(int)$item['id']?>" <?=in_array((int)$item['id'],$filters['chantier_ids'],true)?'selected':''?>><?=htmlspecialchars(trim(($item['code']??'').' · '.($item['titre']??''),' ·'))?></option><?php endforeach ?>
                </select></div>
                <div><label class="mb-2 block text-sm font-bold text-slate-700">Personnel</label><select name="personnel_ids[]" multiple data-modern-select data-placeholder="Tous les collaborateurs"><?php foreach($filterPersonnel as $item): ?><option value="<?=(int)$item['id']?>" <?=in_array((int)$item['id'],$filters['personnel_ids'],true)?'selected':''?>><?=htmlspecialchars(trim(($item['label']??'').' · '.($item['matricule']??''),' ·'))?></option><?php endforeach ?></select></div>
                <div><label class="mb-2 block text-sm font-bold text-slate-700">Validateurs</label><select name="validator_ids[]" multiple data-modern-select data-placeholder="Tous les validateurs"><?php foreach($filterValidators as $item): ?><option value="<?=(int)$item['id']?>" <?=in_array((int)$item['id'],$filters['validator_ids'],true)?'selected':''?>><?=htmlspecialchars(trim(($item['label']??'').' · '.($item['mail_pro']??''),' ·'))?></option><?php endforeach ?></select></div>
                <div>
                    <label class="mb-2 block text-sm font-bold text-slate-700">Statuts</label>
                    <select name="statuses[]" multiple data-modern-select data-placeholder="Tous les statuts">
                        <?php foreach ($statusLabels as $code => $label): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= in_array($code,$filters['statuses'],true) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end xl:col-span-2">
                    <button type="submit" class="w-full rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                        <i class="fas fa-magnifying-glass mr-2"></i>
                        Filtrer
                    </button>
                </div>
            </form>

            <?php if (empty($result['items'])): ?>
                <div class="mt-10 rounded-2xl border border-slate-200 bg-slate-50 px-6 py-10 text-center">
                    <div class="text-lg font-semibold text-slate-800">Aucun ordre de mission</div>
                    <div class="mt-2 text-sm text-slate-600">Creez un premier ordre pour demarrer.</div>
                    <div class="mt-6">
                        <a href="ordre_mission_rh.php" class="inline-flex items-center rounded-2xl bg-indigo-600 px-6 py-3 text-sm font-semibold text-white hover:bg-indigo-700">
                            <i class="fas fa-plus mr-2"></i>
                            Nouvel ordre
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-10 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                    <?php foreach ($result['items'] as $mission): ?>
                        <?php
                        [$badgeClass, $badgeIcon] = missionStatusBadge((string) ($mission['status'] ?? 'draft'));
                        $title = (string) ($mission['mission_title'] ?? '');
                        $code = (string) ($mission['mission_code'] ?? '');
                        $personnelName = (string) ($mission['personnel_name'] ?? '');
                        $dates = (string) ($mission['start_date'] ?? '') . ' → ' . (string) ($mission['end_date'] ?? '');
                        ?>
                        <article class="overflow-hidden rounded-[30px] border border-white/80 bg-white/92 shadow-[0_18px_60px_rgba(15,23,42,0.08)] backdrop-blur transition hover:-translate-y-1 hover:shadow-[0_26px_80px_rgba(15,23,42,0.12)]">
                            <div class="om-card-head h-24"></div>
                            <div class="px-6 pb-6">
                                <div class="-mt-6 flex items-center justify-between gap-3">
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700 shadow">
                                        <?= htmlspecialchars($code !== '' ? $code : 'OM') ?>
                                    </span>
                                    <span class="rounded-full px-3 py-1 text-xs font-semibold <?= $badgeClass ?>">
                                        <i class="<?= htmlspecialchars($badgeIcon) ?> mr-1"></i>
                                        <?= htmlspecialchars($statusLabels[(string) ($mission['status'] ?? 'draft')] ?? (string) ($mission['status'] ?? '')) ?>
                                    </span>
                                </div>

                                <div class="mt-4">
                                    <h2 class="text-lg font-semibold text-slate-950 line-clamp-2"><?= htmlspecialchars($title) ?></h2>
                                    <p class="mt-1 text-sm text-slate-600 line-clamp-1"><?= htmlspecialchars($personnelName) ?></p>
                                </div>

                                <div class="mt-5 space-y-3 text-sm text-slate-600">
                                    <div class="flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                                        <i class="fas fa-calendar-days text-indigo-600"></i>
                                        <div>
                                            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Periode</div>
                                            <div class="font-medium text-slate-800"><?= htmlspecialchars($dates) ?></div>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                                        <i class="fas fa-location-dot text-sky-600"></i>
                                        <div>
                                            <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Destination</div>
                                            <div class="font-medium text-slate-800"><?= htmlspecialchars((string) ($mission['destination'] ?? '')) ?></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-6 grid gap-2">
                                    <a href="ordre_mission_rh.php?id=<?= (int) $mission['id'] ?>" class="inline-flex w-full items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                                        <i class="fas fa-arrow-right mr-2"></i>
                                        Ouvrir
                                    </a>
                                    <a href="../request/export_mission_order_rh_pdf.php?id=<?= (int) $mission['id'] ?>" target="_blank" rel="noopener" class="inline-flex w-full items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                        <i class="fas fa-file-pdf mr-2 text-rose-600"></i>
                                        Exporter l'ordre
                                    </a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <?php if ((int) $result['totalPages'] > 1): ?>
                    <div class="mt-10 flex flex-wrap items-center justify-center gap-2">
                        <?php if ((int) $result['page'] > 1): ?>
                            <a href="ordres_mission_rh.php?<?= htmlspecialchars(http_build_query(array_merge($filters, ['page' => (int) $result['page'] - 1]))) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Precedent</a>
                        <?php endif; ?>

                        <?php for ($i = 1; $i <= (int) $result['totalPages']; $i++): ?>
                            <?php if ($i === 1 || $i === (int) $result['totalPages'] || abs($i - (int) $result['page']) <= 1): ?>
                                <a href="ordres_mission_rh.php?<?= htmlspecialchars(http_build_query(array_merge($filters, ['page' => $i]))) ?>" class="rounded-2xl px-4 py-3 text-sm font-semibold <?= $i === (int) $result['page'] ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>">
                                    <?= $i ?>
                                </a>
                            <?php elseif ($i === 2 || $i === (int) $result['totalPages'] - 1): ?>
                                <span class="px-2 text-slate-400">...</span>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ((int) $result['page'] < (int) $result['totalPages']): ?>
                            <a href="ordres_mission_rh.php?<?= htmlspecialchars(http_build_query(array_merge($filters, ['page' => (int) $result['page'] + 1]))) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Suivant</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/mission-select-search.js?v=<?=filemtime(__DIR__.'/assets/mission-select-search.js')?>"></script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>
