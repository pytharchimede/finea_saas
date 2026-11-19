<?php
require_once __DIR__ . '/../auth_check.php';
\AccessManager::requirePageAccess();

require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../controllers/RhPersonnelController.php';
require_once __DIR__ . '/includes/personnel_helpers.php';
require_once __DIR__ . '/../modules/payroll/bootstrap.php';

$pdo = \Database::getConnection();
$controller = new \RhPersonnelController($pdo);
$directory = $controller->service();
$rhPersonnelCategory=$rhPersonnelCategory??'interne';$requestFilters=$_GET;$requestFilters['personnel_category']=$rhPersonnelCategory;
$_SESSION['rh_category_csrf']=$_SESSION['rh_category_csrf']??bin2hex(random_bytes(24));
if($_SERVER['REQUEST_METHOD']==='POST'&&($_POST['form_action']??'')==='change_personnel_category'){
 try{if(!hash_equals((string)$_SESSION['rh_category_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');$personnelId=(int)($_POST['personnel_id']??0);$target=(string)($_POST['target_category']??'');if($personnelId<1||!in_array($target,['interne','prestataire'],true))throw new RuntimeException('Transfert invalide.');$employee=$directory->getPersonnelDetail($personnelId);if(!$employee)throw new RuntimeException('Dossier du personnel introuvable.');$directory->updatePersonnel($personnelId,['personnel_category'=>$target],['user_id'=>(int)($_SESSION['user_id']??0),'user_name'=>(string)($_SESSION['user_name']??'')]);$_SESSION['rh_category_flash']=$employee['full_name'].' a été transféré vers '.($target==='prestataire'?'les prestataires':'le personnel interne').'.';header('Location: '.($target==='prestataire'?'prestataires_rh.php':'liste_personnel_rh.php'));exit;}catch(Throwable $e){$_SESSION['rh_category_error']=$e->getMessage();header('Location: '.($rhPersonnelCategory==='prestataire'?'prestataires_rh.php':'liste_personnel_rh.php'));exit;}
}
$categoryFlash=(string)($_SESSION['rh_category_flash']??'');$categoryError=(string)($_SESSION['rh_category_error']??'');unset($_SESSION['rh_category_flash'],$_SESSION['rh_category_error']);
$identityPending=PersonIdentityGuard::incompletePersonnel($pdo);
$viewData = $controller->listView($requestFilters);
$filters = $viewData['filters'];
$page = $viewData['page'];
$filterOptions = $viewData['filterOptions'];
$quickStats = $viewData['quickStats'];
$result = $viewData['result'];
$contractPeriod = date('Y-m');
$contractRepository = new \EmployeeContractRepository($pdo);
$contractStatusByPersonnel = $contractRepository->findActiveContractsForPersonnelIds(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $result['items'] ?? []), $contractPeriod);
$jobDescriptionByFunction=[];$functionIds=array_values(array_unique(array_filter(array_map(static fn(array $row):int=>(int)($row['fonction_id']??0),$result['items']??[]))));if($functionIds){$placeholders=implode(',',array_fill(0,count($functionIds),'?'));$q=$pdo->prepare("SELECT fp.id,fp.fonction_id,fp.intitule,fp.version,fp.date_effet FROM rh_fiches_poste fp WHERE fp.statut='active' AND fp.fonction_id IN ($placeholders) ORDER BY fp.date_effet DESC,fp.id DESC");$q->execute($functionIds);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $job)if(!isset($jobDescriptionByFunction[(int)$job['fonction_id']]))$jobDescriptionByFunction[(int)$job['fonction_id']]=$job;}

function rhQuery(array $filters, array $overrides = []): string
{
    $params = array_merge($filters, $overrides);
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            unset($params[$key]);
        }
    }

    return http_build_query($params);
}

$exportQuery = rhQuery($filters, ['page' => null]);

$isProviderDirectory=$rhPersonnelCategory==='prestataire';
$listPage=$isProviderDirectory?'prestataires_rh.php':'liste_personnel_rh.php';
$pageTitle = $isProviderDirectory?'Prestataires RH':'Personnel interne RH';
$activeMenu = 'rh';

require_once __DIR__ . '/../decaissement/includes/header.php';
require_once __DIR__ . '/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-[radial-gradient(circle_at_top,_rgba(240,253,244,0.95),_transparent_35%),radial-gradient(circle_at_right,_rgba(219,234,254,0.85),_transparent_30%),linear-gradient(180deg,_#f8fafc_0%,_#eef2ff_100%)] pb-12">
    <div class="container mx-auto px-4 py-8">
<?php if ($identityPending): ?><div role="alert" class="my-4 rounded-xl border border-amber-300 bg-amber-50 p-4"><strong>Pièces d’identité RH à régulariser : <?= count($identityPending) ?> dossiers.</strong><p>Chaque salarié doit avoir un numéro de pièce valide et sa copie jointe. Complétez ces dossiers pour fiabiliser le blocage des salariés dans le référentiel fournisseur. Les paiements RH suivent leur circuit habituel.</p><details class="mt-3"><summary class="cursor-pointer font-semibold">Afficher tous les dossiers à compléter</summary><?php foreach($identityPending as $pending): ?><a class="mt-2 block underline" href="modifier_dossier_personnel_rh.php?id=<?= (int)$pending['id_personnel_soignant'] ?>"><?= htmlspecialchars($pending['nom_personnel_soignant']) ?></a><small><?= htmlspecialchars($pending['identity_missing_reason']??'Numéro et pièce jointe à vérifier.') ?></small><?php endforeach; ?></details></div><?php endif; ?>

        <?php if($categoryFlash):?><div class="mb-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800"><?=htmlspecialchars($categoryFlash)?></div><?php endif;?><?php if($categoryError):?><div class="mb-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><?=htmlspecialchars($categoryError)?></div><?php endif;?>
        <div class="overflow-hidden rounded-[32px] border border-slate-200 bg-white/95 shadow-[0_18px_60px_rgba(15,23,42,0.09)] backdrop-blur">
            <div class="relative flex flex-col gap-6 overflow-hidden bg-black p-8 text-white lg:flex-row lg:items-end lg:justify-between">
                <div class="absolute -right-20 -top-28 h-72 w-72 rounded-full bg-[#ffcc20]/20 blur-3xl"></div>
                <div class="absolute bottom-0 right-[34%] h-1 w-48 bg-[#ffcc20]"></div>
                <div class="relative flex items-center gap-5">
                    <div class="hidden h-24 w-24 shrink-0 items-center justify-center rounded-[26px] bg-white p-2 shadow-xl sm:flex">
                        <img src="../logo/default_logo.jpg" alt="BANAMUR" class="h-full w-full rounded-2xl object-contain">
                    </div>
                    <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.32em] text-[#ffcc20]">Finea · Ressources humaines</p>
                    <h1 class="mt-3 text-3xl font-semibold tracking-tight text-white md:text-4xl"><?=$isProviderDirectory?'Prestataires et consultants':'Personnel interne'?></h1>
                    <p class="mt-3 max-w-3xl text-sm text-slate-300 md:text-base">
                        <?=$isProviderDirectory?'Annuaire séparé des intervenants externes gérés par les RH.':'Salariés, stagiaires et collaborateurs internes, hors prestataires et consultants.'?>
                    </p>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <a href="dashboard_rh.php" class="inline-flex items-center rounded-xl bg-[#ffcc20] px-4 py-2 text-sm font-bold text-black transition hover:bg-yellow-300"><i class="fas fa-arrow-left mr-2"></i>Dashboard RH</a>
                        <a href="integration_personnel_rh.php?category=<?=htmlspecialchars($rhPersonnelCategory)?>" class="inline-flex items-center rounded-xl border border-white/20 bg-white/10 px-4 py-2 text-sm font-semibold text-white transition hover:bg-white/15"><i class="fas fa-user-plus mr-2 text-[#ffcc20]"></i><?=$isProviderDirectory?'Ajouter un prestataire':'Ajouter un collaborateur'?></a>
                    </div>
                    </div>
                </div>
                <div class="relative grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-4 backdrop-blur">
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-[#ffcc20]">Effectif total</div>
                        <div class="mt-2 text-3xl font-semibold text-white"><?= number_format((int) $quickStats['total'], 0, ',', ' ') ?></div>
                    </div>
                    <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-4 backdrop-blur">
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-emerald-300">En poste</div>
                        <div class="mt-2 text-3xl font-semibold text-white"><?= number_format((int) $quickStats['active'], 0, ',', ' ') ?></div>
                    </div>
                    <div class="rounded-2xl border border-white/15 bg-white/10 px-4 py-4 backdrop-blur">
                        <div class="text-xs font-semibold uppercase tracking-[0.22em] text-slate-300">Sorties</div>
                        <div class="mt-2 text-3xl font-semibold text-white"><?= number_format((int) $quickStats['inactive'], 0, ',', ' ') ?></div>
                    </div>
                </div>
            </div>

            <form method="get" class="grid gap-4 p-8 md:grid-cols-2 xl:grid-cols-4">
                <div class="xl:col-span-2">
                    <label class="mb-2 block text-sm font-medium text-slate-700">Recherche</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Nom, matricule ou e-mail" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Mode de recherche</label>
                    <select name="search_mode" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="include" <?= ($filters['search_mode'] ?? 'include') === 'include' ? 'selected' : '' ?>>Afficher ce qui correspond</option>
                        <option value="exclude" <?= ($filters['search_mode'] ?? 'include') === 'exclude' ? 'selected' : '' ?>>Recherche inversee</option>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Service</label>
                    <select name="service_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="">Tous les services</option>
                        <?php foreach ($filterOptions['services'] as $service): ?>
                            <option value="<?= (int) $service['id'] ?>" <?= $filters['service_id'] !== '' && (int) $filters['service_id'] === (int) $service['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $service['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Fonction</label>
                    <select name="fonction_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="">Toutes les fonctions</option>
                        <?php foreach ($filterOptions['fonctions'] as $fonction): ?>
                            <option value="<?= (int) $fonction['id'] ?>" <?= $filters['fonction_id'] !== '' && (int) $filters['fonction_id'] === (int) $fonction['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $fonction['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Statut</label>
                    <select name="status_code" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="">Tous les statuts</option>
                        <?php foreach ($filterOptions['statuts'] as $statut): ?>
                            <option value="<?= (int) $statut['id'] ?>" <?= $filters['status_code'] !== '' && (int) $filters['status_code'] === (int) $statut['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $statut['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Sexe</label>
                    <select name="gender_code" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="">Tous</option>
                        <?php foreach ($filterOptions['sexes'] as $sexe): ?>
                            <option value="<?= (int) $sexe['id'] ?>" <?= $filters['gender_code'] !== '' && (int) $filters['gender_code'] === (int) $sexe['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $sexe['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Site</label>
                    <select name="site_id" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="">Tous les sites</option>
                        <?php foreach ($filterOptions['sites'] as $site): ?>
                            <option value="<?= (int) $site['id'] ?>" <?= $filters['site_id'] !== '' && (int) $filters['site_id'] === (int) $site['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $site['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="mb-2 block text-sm font-medium text-slate-700">Perimetre</label>
                    <select name="scope" class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-emerald-400 focus:bg-white">
                        <option value="active" <?= $filters['scope'] === 'active' ? 'selected' : '' ?>>En poste</option>
                        <option value="all" <?= $filters['scope'] === 'all' ? 'selected' : '' ?>>Tous les dossiers</option>
                        <option value="inactive" <?= $filters['scope'] === 'inactive' ? 'selected' : '' ?>>Sortis</option>
                    </select>
                </div>
                <div class="flex items-end gap-3 xl:col-span-4">
                    <button type="submit" class="inline-flex items-center rounded-2xl bg-slate-950 px-5 py-3 text-sm font-semibold text-white transition hover:bg-slate-800">
                        <i class="fas fa-search mr-2"></i>
                        Rechercher
                    </button>
                    <a href="export_liste_personnel_pdf.php?<?= htmlspecialchars($exportQuery) ?>" target="_blank" rel="noopener" class="inline-flex items-center rounded-2xl border border-rose-200 bg-rose-50 px-5 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                        <i class="fas fa-file-pdf mr-2"></i>
                        Export PDF
                    </a>
                    <a href="export_liste_personnel_excel.php?<?= htmlspecialchars($exportQuery) ?>" class="inline-flex items-center rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100">
                        <i class="fas fa-file-excel mr-2"></i>
                        Export Excel
                    </a>
                    <a href="organigramme_rh.php" class="inline-flex items-center rounded-2xl border border-sky-200 bg-sky-50 px-5 py-3 text-sm font-semibold text-sky-700 transition hover:bg-sky-100">
                        <i class="fas fa-sitemap mr-2"></i>
                        Organigramme
                    </a>
                    <button type="button" id="open-card-export" class="inline-flex items-center rounded-2xl border border-yellow-300 bg-[#ffcc20] px-5 py-3 text-sm font-semibold text-black transition hover:bg-yellow-300">
                        <i class="fas fa-address-card mr-2"></i>
                        Cartes pro A4
                    </button>
                    <a href="<?=$listPage?>" class="inline-flex items-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                        Reinitialiser
                    </a>
                </div>
            </form>
        </div>

        <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-sm text-slate-600">
                <span class="font-semibold text-slate-900"><?= number_format((int) $result['total'], 0, ',', ' ') ?></span>
                resultat(s) trouves, page <?= (int) $result['page'] ?> sur <?= (int) $result['totalPages'] ?>.
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['scope' => 'active', 'page' => null])) ?>" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filters['scope'] === 'active' ? 'bg-emerald-600 text-white' : 'bg-white text-slate-700 border border-slate-200' ?>">Actifs</a>
                <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['scope' => 'all', 'page' => null])) ?>" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filters['scope'] === 'all' ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 border border-slate-200' ?>">Tous</a>
                <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['scope' => 'inactive', 'page' => null])) ?>" class="rounded-full px-4 py-2 text-sm font-semibold <?= $filters['scope'] === 'inactive' ? 'bg-amber-500 text-white' : 'bg-white text-slate-700 border border-slate-200' ?>">Sortis</a>
            </div>
        </div>

        <?php if (empty($result['items'])): ?>
            <div class="mt-8 rounded-[28px] border border-dashed border-slate-300 bg-white/80 px-8 py-16 text-center shadow-[0_18px_60px_rgba(15,23,42,0.05)]">
                <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-3xl bg-slate-100 text-slate-500">
                    <i class="fas fa-users text-2xl"></i>
                </div>
                <h2 class="mt-5 text-2xl font-semibold text-slate-900">Aucun dossier correspondant</h2>
                <p class="mt-3 text-sm text-slate-500">Ajustez les filtres ou relancez la recherche avec un perimetre plus large.</p>
            </div>
        <?php else: ?>
            <div class="mt-8 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <?php foreach ($result['items'] as $employee): ?>
                    <?php $photoUrl = rhResolveMediaUrl($employee['photo'] ?? null); ?>
                    <?php $detailsId = 'employee-details-' . (int) $employee['id']; ?>
                    <?php $employeeContract = $contractStatusByPersonnel[(int) $employee['id']] ?? null; ?>
                    <?php $employeeJobDescription=$jobDescriptionByFunction[(int)($employee['fonction_id']??0)]??null; ?>
                    <article class="relative min-w-0 overflow-hidden rounded-[30px] border border-white/80 bg-white/92 [overflow-wrap:anywhere] shadow-[0_18px_60px_rgba(15,23,42,0.08)] backdrop-blur transition hover:-translate-y-1 hover:shadow-[0_26px_80px_rgba(15,23,42,0.12)]">
                        <label class="absolute left-4 top-4 z-10 inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white/95 px-3 py-2 text-xs font-semibold text-slate-800 shadow-lg">
                            <input type="checkbox" class="professional-card-select h-4 w-4 accent-[#ffcc20]" value="<?= (int) $employee['id'] ?>">
                            Sélectionner
                        </label>
                        <div class="h-24 bg-gradient-to-r from-emerald-500 via-cyan-500 to-sky-500"></div>
                        <div class="relative px-6 pb-6">
                            <div class="-mt-10 flex items-end justify-between gap-4">
                                <?php if ($photoUrl !== null): ?>
                                    <img src="<?= htmlspecialchars($photoUrl) ?>" alt="<?= htmlspecialchars((string) $employee['full_name']) ?>" class="h-20 w-20 rounded-3xl border-4 border-white object-cover shadow-lg">
                                <?php else: ?>
                                    <div class="flex h-20 w-20 items-center justify-center rounded-3xl border-4 border-white bg-slate-900 text-2xl font-semibold text-white shadow-lg">
                                        <?= htmlspecialchars(rhInitials((string) $employee['full_name'])) ?>
                                    </div>
                                <?php endif; ?>
                                <span class="rounded-full px-3 py-1 text-xs font-semibold <?= (int) $employee['is_active'] === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' ?>">
                                    <?= (int) $employee['is_active'] === 1 ? 'En poste' : 'Sorti' ?>
                                </span>
                            </div>

                            <div class="mt-4 min-w-0">
                                <h2 class="break-words text-xl font-semibold text-slate-950"><?= htmlspecialchars((string) $employee['full_name']) ?></h2>
                                <p class="mt-1 text-sm font-medium text-slate-500"><?= htmlspecialchars((string) ($employee['matricule'] ?: 'Matricule non renseigne')) ?></p>
                            </div>

                            <div class="mt-5 space-y-3 text-sm text-slate-600">
                                <div class="flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                                    <i class="fas fa-briefcase text-emerald-600"></i>
                                    <div class="min-w-0">
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Fonction</div>
                                        <div class="break-words font-medium text-slate-800"><?= htmlspecialchars((string) $employee['fonction_label']) ?></div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3 rounded-2xl bg-slate-50 px-4 py-3">
                                    <i class="fas fa-building text-cyan-600"></i>
                                    <div class="min-w-0">
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Service</div>
                                        <div class="break-words font-medium text-slate-800"><?= htmlspecialchars((string) $employee['service_label']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mt-5 flex flex-wrap gap-2">
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700"><?= htmlspecialchars((string) $employee['statut_label']) ?></span>
                                <span class="rounded-full bg-sky-100 px-3 py-1 text-xs font-semibold text-sky-700"><?= htmlspecialchars((string) $employee['site_label']) ?></span>
                                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700"><?= htmlspecialchars((string) $employee['gender_label']) ?></span>
                                <?php if ($employeeContract !== null): ?>
                                    <span class="rounded-full bg-cyan-100 px-3 py-1 text-xs font-semibold text-cyan-700">Contrat paie pret</span>
                                <?php else: ?>
                                    <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800">Contrat a parametrer</span>
                                <?php endif; ?>
                            </div>

                            <div class="mt-4 rounded-2xl border p-4 <?=$employeeJobDescription?'border-emerald-200 bg-emerald-50':'border-amber-300 bg-amber-50'?>"><div class="flex min-w-0 items-start gap-3"><i class="fas <?=$employeeJobDescription?'fa-clipboard-check text-emerald-700':'fa-triangle-exclamation text-amber-700'?> mt-1 shrink-0"></i><div class="min-w-0"><b class="block break-words <?=$employeeJobDescription?'text-emerald-900':'text-amber-950'?>"><?=$employeeJobDescription?'Fiche de poste disponible':'Fiche de poste à éditer'?></b><p class="mt-1 break-words text-xs <?=$employeeJobDescription?'text-emerald-700':'text-amber-800'?>"><?=$employeeJobDescription?htmlspecialchars((string)$employeeJobDescription['intitule']).' · version '.htmlspecialchars((string)$employeeJobDescription['version']):'Aucune fiche active ne couvre actuellement cette fonction.'?></p></div></div><a href="fiches_poste_rh.php<?=$employeeJobDescription?'?edit='.(int)$employeeJobDescription['id']:'?fonction_id='.(int)($employee['fonction_id']??0)?>" class="mt-3 flex w-full items-center justify-center rounded-xl <?=$employeeJobDescription?'bg-emerald-700 text-white':'bg-[#ffcc20] text-black'?> px-4 py-2 text-sm font-black"><?=$employeeJobDescription?'Consulter / modifier':'Créer maintenant'?></a></div>

                            <div class="mt-6 grid min-w-0 gap-3 sm:grid-cols-2">
                                <button type="button" class="toggle-details inline-flex items-center justify-center rounded-2xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white transition hover:bg-slate-800" data-target="<?= htmlspecialchars($detailsId) ?>">
                                    <i class="fas fa-circle-info mr-2"></i>
                                    Infos rapides
                                </button>
                                <a href="dossier_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700">
                                    <i class="fas fa-folder-open mr-2"></i>
                                    Dossier complet
                                </a>
                                <a href="modifier_dossier_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                    <i class="fas fa-pen mr-2"></i>
                                    Modifier
                                </a>
                                <a href="mutation_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="inline-flex items-center justify-center rounded-2xl border border-fuchsia-200 bg-fuchsia-50 px-4 py-3 text-sm font-semibold text-fuchsia-700 transition hover:bg-fuchsia-100">
                                    <i class="fas fa-people-arrows mr-2"></i>
                                    Mutation
                                </a>
                                <a href="pointage_journalier_rh.php" class="inline-flex items-center justify-center rounded-2xl border border-cyan-200 bg-cyan-50 px-4 py-3 text-sm font-semibold text-cyan-700 transition hover:bg-cyan-100">
                                    <i class="fas fa-user-check mr-2"></i>
                                    Pointage
                                </a>
                                <a href="paie_rh.php?personnel_id=<?= (int) $employee['id'] ?>" class="inline-flex items-center justify-center rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 transition hover:bg-rose-100">
                                    <i class="fas fa-file-invoice-dollar mr-2"></i>
                                    Paie
                                </a>
                                <a href="contrats_employes_rh.php?personnel_id=<?= (int) $employee['id'] ?>&period=<?= htmlspecialchars($contractPeriod) ?>" class="inline-flex items-center justify-center rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">
                                    <i class="fas fa-file-signature mr-2"></i>
                                    Contrat paie
                                </a>
                                <a href="export_cartes_professionnelles.php?personnel_id=<?= (int) $employee['id'] ?>&photo_mode=auto" target="_blank" rel="noopener" class="inline-flex items-center justify-center rounded-2xl border border-yellow-300 bg-[#ffcc20] px-4 py-3 text-sm font-semibold text-black transition hover:bg-yellow-300">
                                    <i class="fas fa-address-card mr-2"></i>
                                    Carte pro
                                </a>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                <a href="sortie_personnel_rh.php?id=<?= (int) $employee['id'] ?>" class="inline-flex items-center justify-center rounded-2xl border px-4 py-3 text-sm font-semibold transition <?= (int) $employee['is_active'] === 1 ? 'border-amber-200 bg-amber-50 text-amber-800 hover:bg-amber-100' : 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100' ?>">
                                    <i class="fas <?= (int) $employee['is_active'] === 1 ? 'fa-sign-out-alt text-amber-600' : 'fa-undo text-emerald-600' ?> mr-2"></i>
                                    <?= (int) $employee['is_active'] === 1 ? 'Declarer sortie' : 'Reintegrer' ?>
                                </a>
                                <a href="export_contrat_rh.php?personnel_id=<?= (int) $employee['id'] ?>&download=1&signature_mode=prepositioned&document_state=draft&save_to_dossier=1" class="inline-flex items-center justify-center rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700 transition hover:bg-amber-100">
                                    <i class="fas fa-file-signature mr-2"></i>
                                    Contrat brouillon
                                </a>
                            </div>

                            <div class="mt-3 grid gap-3 sm:grid-cols-1">
                                <?php if (!empty($employee['email'])): ?>
                                    <a href="mailto:<?= htmlspecialchars((string) $employee['email']) ?>" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                                        <i class="fas fa-envelope mr-2 text-cyan-600"></i>
                                        E-mail
                                    </a>
                                <?php else: ?>
                                    <span class="inline-flex items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-400">
                                        E-mail indisponible
                                    </span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="mt-3"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['rh_category_csrf'])?>"><input type="hidden" name="form_action" value="change_personnel_category"><input type="hidden" name="personnel_id" value="<?=(int)$employee['id']?>"><input type="hidden" name="target_category" value="<?=$isProviderDirectory?'interne':'prestataire'?>"><button onclick="return confirm('Confirmer le transfert de ce dossier vers <?=$isProviderDirectory?'le personnel interne':'les prestataires'?> ?');" class="flex w-full min-w-0 items-center justify-center rounded-2xl border <?=$isProviderDirectory?'border-emerald-200 bg-emerald-50 text-emerald-800':'border-indigo-200 bg-indigo-50 text-indigo-800'?> px-4 py-3 text-center text-sm font-bold"><i class="fas <?=$isProviderDirectory?'fa-user-check':'fa-user-tie'?> mr-2 shrink-0"></i><span class="break-words"><?=$isProviderDirectory?'Renvoyer vers le personnel interne':'Envoyer vers les prestataires'?></span></button></form>

                            <div id="<?= htmlspecialchars($detailsId) ?>" class="mt-5 hidden rounded-2xl border border-slate-100 bg-slate-50 px-4 py-4 text-sm text-slate-600">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Recrutement</div>
                                        <div class="mt-1 font-medium text-slate-800"><?= htmlspecialchars(rhFormatDate($employee['hire_date'] ?? null)) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Prise de service</div>
                                        <div class="mt-1 font-medium text-slate-800"><?= htmlspecialchars(rhFormatDate($employee['start_date'] ?? null)) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">Date de sortie</div>
                                        <div class="mt-1 font-medium text-slate-800"><?= htmlspecialchars(rhFormatDate($employee['end_date'] ?? null)) ?></div>
                                    </div>
                                    <div>
                                        <div class="text-xs uppercase tracking-[0.22em] text-slate-400">E-mail</div>
                                        <div class="mt-1 font-medium text-slate-800 break-all"><?= htmlspecialchars((string) ($employee['email'] ?: 'Non renseigne')) ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ((int) $result['totalPages'] > 1): ?>
                <div class="mt-10 flex flex-wrap items-center justify-center gap-2">
                    <?php if ((int) $result['page'] > 1): ?>
                        <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['page' => (int) $result['page'] - 1])) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Precedent</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= (int) $result['totalPages']; $i++): ?>
                        <?php if ($i === 1 || $i === (int) $result['totalPages'] || abs($i - (int) $result['page']) <= 1): ?>
                            <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['page' => $i])) ?>" class="rounded-2xl px-4 py-3 text-sm font-semibold <?= $i === (int) $result['page'] ? 'bg-slate-950 text-white' : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50' ?>">
                                <?= $i ?>
                            </a>
                        <?php elseif ($i === 2 || $i === (int) $result['totalPages'] - 1): ?>
                            <span class="px-2 text-slate-400">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ((int) $result['page'] < (int) $result['totalPages']): ?>
                        <a href="<?=$listPage?>?<?= htmlspecialchars(rhQuery($filters, ['page' => (int) $result['page'] + 1])) ?>" class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50">Suivant</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div id="card-export-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="card-export-title">
    <div class="w-full max-w-lg overflow-hidden rounded-[28px] bg-white shadow-2xl">
        <div class="bg-black px-7 py-6 text-white">
            <div class="flex items-start justify-between gap-5">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.28em] text-[#ffcc20]">BANAMUR · Impression</p>
                    <h2 id="card-export-title" class="mt-2 text-2xl font-semibold">Exporter les cartes pro</h2>
                </div>
                <button type="button" id="close-card-export" class="rounded-xl border border-white/20 px-3 py-2 text-white hover:bg-white/10" aria-label="Fermer"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="space-y-5 p-7">
            <fieldset>
                <legend class="text-sm font-semibold text-slate-900">Collaborateurs à inclure</legend>
                <label class="mt-3 flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 p-4">
                    <input type="radio" name="card_export_scope" value="selected" checked class="mt-1 accent-[#ffcc20]">
                    <span><strong class="block text-sm text-slate-900">Sélection sur cette page</strong><small id="selected-card-count" class="text-slate-500">0 collaborateur sélectionné</small></span>
                </label>
                <label class="mt-2 flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 p-4">
                    <input type="radio" name="card_export_scope" value="filtered" class="mt-1 accent-[#ffcc20]">
                    <span><strong class="block text-sm text-slate-900">Tous les résultats filtrés</strong><small class="text-slate-500"><?= number_format((int) $result['total'], 0, ',', ' ') ?> collaborateur(s), toutes pages confondues</small></span>
                </label>
            </fieldset>
            <fieldset>
                <legend class="text-sm font-semibold text-slate-900">Présentation du recto</legend>
                <select id="card-photo-mode" class="mt-3 w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    <option value="auto">Avec photo lorsqu’elle existe</option>
                    <option value="without">Toujours sans photo (logo uniquement)</option>
                </select>
            </fieldset>
            <label class="flex items-center gap-3 text-sm text-slate-700">
                <input id="card-cut-marks" type="checkbox" checked class="h-4 w-4 accent-[#ffcc20]">
                Lignes de découpe et repère ciseaux
            </label>
            <p class="rounded-2xl bg-yellow-50 px-4 py-3 text-xs leading-5 text-yellow-900">PDF A4 de 10 cartes par feuille. Imprimez en taille réelle, recto-verso, retournement sur le bord long.</p>
            <div id="card-export-error" class="hidden rounded-2xl bg-rose-50 px-4 py-3 text-sm text-rose-700"></div>
            <button type="button" id="run-card-export" class="flex w-full items-center justify-center rounded-2xl bg-[#ffcc20] px-5 py-3.5 text-sm font-bold text-black hover:bg-yellow-300">
                <i class="fas fa-file-pdf mr-2"></i>Générer le PDF recto-verso
            </button>
        </div>
    </div>
</div>

<script>
    document.querySelectorAll('.toggle-details').forEach(function(button) {
        button.addEventListener('click', function() {
            var target = document.getElementById(button.getAttribute('data-target'));
            if (target) {
                target.classList.toggle('hidden');
            }
        });
    });

    (function() {
        var modal = document.getElementById('card-export-modal');
        var selectedCount = document.getElementById('selected-card-count');
        var error = document.getElementById('card-export-error');
        var filteredQuery = <?= json_encode($exportQuery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        function selectedIds() {
            return Array.from(document.querySelectorAll('.professional-card-select:checked')).map(function(input) { return input.value; });
        }
        function refreshCount() {
            var count = selectedIds().length;
            selectedCount.textContent = count + ' collaborateur' + (count > 1 ? 's' : '') + ' sélectionné' + (count > 1 ? 's' : '');
        }
        document.querySelectorAll('.professional-card-select').forEach(function(input) { input.addEventListener('change', refreshCount); });
        document.getElementById('open-card-export').addEventListener('click', function() {
            refreshCount(); error.classList.add('hidden'); modal.classList.remove('hidden'); modal.classList.add('flex');
        });
        function closeModal() { modal.classList.add('hidden'); modal.classList.remove('flex'); }
        document.getElementById('close-card-export').addEventListener('click', closeModal);
        modal.addEventListener('click', function(event) { if (event.target === modal) closeModal(); });
        document.getElementById('run-card-export').addEventListener('click', function() {
            var scope = document.querySelector('input[name="card_export_scope"]:checked').value;
            var params = new URLSearchParams(scope === 'filtered' ? filteredQuery : '');
            if (scope === 'selected') {
                var ids = selectedIds();
                if (!ids.length) { error.textContent = 'Sélectionnez au moins une carte du personnel.'; error.classList.remove('hidden'); return; }
                params.set('ids', ids.join(','));
            }
            params.set('photo_mode', document.getElementById('card-photo-mode').value);
            params.set('cut_marks', document.getElementById('card-cut-marks').checked ? '1' : '0');
            window.open('export_cartes_professionnelles.php?' + params.toString(), '_blank', 'noopener');
            closeModal();
        });
    })();
</script>

<?php require_once __DIR__ . '/../decaissement/includes/footer.php'; ?>


