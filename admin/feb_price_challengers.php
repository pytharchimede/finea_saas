<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
if (!hasPermission('acces_admin') && !hasPermission('gestion_utilisateur')) {
    http_response_code(403);
    exit('Accès refusé');
}
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/FebPriceChallengeService.php';
require_once __DIR__ . '/../services/FebCategoryMigrationService.php';

$pdo = Database::getConnection();
$challengeService = new FebPriceChallengeService($pdo);
$categories = FebCategoryMigrationService::CATEGORIES;
$message = '';
$error = '';
$_SESSION['feb_challenger_settings_csrf'] ??= bin2hex(random_bytes(24));
$csrf = (string) $_SESSION['feb_challenger_settings_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expirée. Rechargez la page.');
        }
        if (isset($_POST['delete_id'])) {
            $pdo->prepare('UPDATE feb_price_challenger_settings SET active=0 WHERE id=:id')->execute(['id' => (int) $_POST['delete_id']]);
            $message = 'Soumissionnaire retiré du routage actif.';
        } elseif (isset($_POST['delete_category'])) {
            $category = FebCategoryMigrationService::normalize((string) $_POST['delete_category']);
            $pdo->prepare('UPDATE feb_price_challenger_settings SET active=0 WHERE category=:category')->execute(['category' => $category]);
            $message = 'Le routage complet de la catégorie ' . $category . ' a été supprimé.';
        } else {
            $category = trim((string) ($_POST['category'] ?? ''));
            $serviceIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['service_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            $userIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
            if (!in_array($category, $categories, true) || !$serviceIds || !$userIds) {
                throw new RuntimeException('Choisissez une catégorie, au moins un service et une personne.');
            }
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
            $check = $pdo->prepare('SELECT u.id,p.service_id FROM user_devis_banamur u INNER JOIN personnel p ON p.id_personnel_soignant=u.personnel_id WHERE u.id IN (' . $placeholders . ') AND p.service_id IN (' . $servicePlaceholders . ') AND u.active=1');
            $check->execute([...$userIds, ...$serviceIds]);
            $validRows = $check->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $validIds = array_map('intval', array_column($validRows, 'id')); sort($validIds);
            $expectedIds = $userIds;
            sort($expectedIds);
            if ($validIds !== $expectedIds) {
                throw new RuntimeException('Une personne sélectionnée n’est plus active ou n’appartient pas aux services choisis.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE feb_price_challenger_settings SET active=0 WHERE category=:category')->execute(['category' => $category]);
            $insert = $pdo->prepare('INSERT INTO feb_price_challenger_settings(category,service_id,user_id,created_by) VALUES(:category,:service_id,:user_id,:actor) ON DUPLICATE KEY UPDATE active=1,created_by=VALUES(created_by)');
            foreach ($validRows as $validRow) {
                $insert->execute(['category' => $category, 'service_id' => (int) $validRow['service_id'], 'user_id' => (int) $validRow['id'], 'actor' => (int) ($_SESSION['user_id'] ?? 0)]);
            }
            $pdo->commit();
            $createdChallenges=$challengeService->syncConfiguredUsersForExistingFebs();
            $message = 'Routage ' . $category . ' remplacé : ' . count($validIds) . ' personne(s) dans ' . count($serviceIds) . ' service(s).'
                .($createdChallenges>0?' '.$createdChallenges.' consultation(s) existante(s) ont été attribuées et notifiées automatiquement.':'');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

$services = $pdo->query('SELECT id_service id,lib_service label FROM service ORDER BY lib_service')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$users = $pdo->query('SELECT u.id,TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) label,p.service_id,COALESCE(NULLIF(f.lib_fonction,""),"Collaborateur") fonction FROM user_devis_banamur u INNER JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE u.active=1 ORDER BY u.nom,u.prenom')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$rows = $pdo->query('SELECT s.*,sv.lib_service,TRIM(CONCAT(COALESCE(u.prenom,"")," ",COALESCE(u.nom,""))) user_name,COALESCE(NULLIF(f.lib_fonction,""),"Collaborateur") fonction FROM feb_price_challenger_settings s INNER JOIN service sv ON sv.id_service=s.service_id INNER JOIN user_devis_banamur u ON u.id=s.user_id LEFT JOIN personnel p ON p.id_personnel_soignant=u.personnel_id LEFT JOIN fonction f ON f.id_fonction=p.fonction_id WHERE s.active=1 ORDER BY s.category,sv.lib_service,user_name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$groupedRows = [];
foreach ($rows as $row) {
    $key = (string) $row['category'];
    if (!isset($groupedRows[$key])) $groupedRows[$key] = ['category' => (string) $row['category'], 'services' => [], 'people' => []];
    $groupedRows[$key]['services'][(int) $row['service_id']] = (string) $row['lib_service'];
    $groupedRows[$key]['people'][] = $row;
}

$pageTitle = 'Soumissionnaires FEB';
$activeMenu = 'feb_price_challengers';
require __DIR__ . '/../decaissement/includes/header.php';
require __DIR__ . '/../decaissement/includes/navbar.php';
?>
<style>
    .fpc-shell{background:radial-gradient(circle at 8% 0,rgba(79,70,229,.12),transparent 30%),radial-gradient(circle at 92% 8%,rgba(16,185,129,.1),transparent 26%),#f8fafc;min-height:100vh}.fpc-card{border:1px solid rgba(203,213,225,.8);border-radius:28px;background:rgba(255,255,255,.96);box-shadow:0 18px 45px rgba(15,23,42,.07)}.fpc-label{display:block;margin-bottom:8px;font-size:11px;font-weight:900;letter-spacing:.14em;text-transform:uppercase;color:#64748b}.fpc-search{width:100%;border:1px solid #dbe3ee;border-radius:16px;background:white;padding:13px 14px;font-size:14px;outline:none}.fpc-search:focus{border-color:#6366f1;box-shadow:0 0 0 4px rgba(99,102,241,.12)}.fpc-results{position:absolute;z-index:50;top:calc(100% + 7px);left:0;right:0;max-height:260px;overflow:auto;border:1px solid #dbe3ee;border-radius:18px;background:#fff;box-shadow:0 22px 50px rgba(15,23,42,.18)}.fpc-option{display:block;width:100%;border:0;background:#fff;padding:12px 14px;text-align:left}.fpc-option:hover{background:#eef2ff}.fpc-chip{display:inline-flex;align-items:center;gap:8px;border:1px solid #c7d2fe;border-radius:999px;background:#eef2ff;padding:7px 10px;color:#3730a3;font-size:12px;font-weight:800}.fpc-chip button{display:grid;width:20px;height:20px;place-items:center;border:0;border-radius:999px;background:#c7d2fe;color:#312e81}.fpc-active-card{border:1px solid #e2e8f0;border-radius:22px;background:linear-gradient(180deg,#fff,#f8fafc);padding:18px}
</style>
<main class="fpc-shell px-4 py-8 lg:px-8">
    <div class="mx-auto max-w-7xl">
        <section class="overflow-hidden rounded-[2rem] bg-gradient-to-br from-slate-950 via-indigo-950 to-indigo-800 p-7 text-white shadow-xl md:p-9">
            <div class="text-xs font-black uppercase tracking-[0.24em] text-indigo-200">Administration FINEA · FEB</div>
            <div class="mt-3 flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"><div><h1 class="text-3xl font-black md:text-4xl">Routage des devis contradictoires</h1><p class="mt-3 max-w-3xl text-sm leading-6 text-indigo-100">Définissez plusieurs personnes par catégorie et département. Chaque ligne FEB sera automatiquement envoyée à tous les soumissionnaires actifs correspondants.</p><?php if (AccessManager::hasPermission('suivi_devis_contradictoires')): ?><a href="feb_price_challenge_control.php" class="mt-5 inline-flex items-center rounded-xl bg-white px-4 py-3 font-black text-indigo-950 shadow-lg"><i class="fas fa-tower-observation mr-2"></i>Ouvrir le centre de contrôle</a><?php endif; ?></div><div class="grid grid-cols-2 gap-3"><div class="rounded-2xl bg-white/10 px-5 py-4"><div class="text-xs text-indigo-200">Règles actives</div><div class="mt-1 text-2xl font-black"><?= count($groupedRows) ?></div></div><div class="rounded-2xl bg-white/10 px-5 py-4"><div class="text-xs text-indigo-200">Personnes</div><div class="mt-1 text-2xl font-black"><?= count($rows) ?></div></div></div></div>
        </section>

        <?php if ($message !== ''): ?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800"><?= htmlspecialchars($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <section class="fpc-card mt-6 p-6 md:p-8">
            <div class="flex flex-col gap-2 md:flex-row md:items-end md:justify-between"><div><div class="text-xs font-black uppercase tracking-[.18em] text-indigo-600">Nouvelle règle</div><h2 class="mt-2 text-2xl font-black text-slate-950">Qui peut proposer pour cette catégorie ?</h2></div><div class="text-sm text-slate-500">Sélection multiple disponible</div></div>
            <form method="post" id="challengerSettingsForm" class="mt-7 grid gap-6">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <div class="grid gap-5 lg:grid-cols-2">
                    <label><span class="fpc-label">1. Catégorie de la ligne FEB</span><select name="category" class="fpc-search" required><option value="">Sélectionner une catégorie</option><?php foreach ($categories as $category): ?><option value="<?= htmlspecialchars($category) ?>"><?= htmlspecialchars($category) ?></option><?php endforeach; ?></select></label>
                    <div><div class="relative"><span class="fpc-label">2. Services concernés · sélection multiple</span><input type="search" id="serviceSearch" class="fpc-search" placeholder="Rechercher puis ajouter un ou plusieurs services…" autocomplete="off"><div id="serviceResults" class="fpc-results" hidden></div></div><div id="selectedServices" class="mt-3 flex min-h-[44px] flex-wrap gap-2 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-3"><span id="emptyServicesHint" class="text-sm text-slate-400">Les services sélectionnés apparaîtront ici.</span></div></div>
                </div>
                <div class="rounded-3xl border border-indigo-100 bg-indigo-50/60 p-5 md:p-6">
                    <span class="fpc-label text-indigo-700">3. Personnes autorisées à soumettre</span>
                    <div class="relative"><input type="search" id="userSearch" class="fpc-search" placeholder="Rechercher dans tout le personnel des services sélectionnés…" autocomplete="off" disabled><div id="userResults" class="fpc-results" hidden></div></div>
                    <div id="selectedUsers" class="mt-4 flex min-h-[46px] flex-wrap gap-2 rounded-2xl border border-dashed border-indigo-200 bg-white/70 p-3"><span id="emptyUsersHint" class="text-sm text-slate-400">Les personnes sélectionnées apparaîtront ici sous forme de badges.</span></div>
                </div>
                <div class="flex items-center justify-between gap-3"><p class="text-sm text-slate-500">Si la catégorie possède déjà une règle, son routage sera remplacé par cette sélection.</p><button class="rounded-2xl bg-indigo-600 px-7 py-3.5 font-black text-white shadow-lg shadow-indigo-200 transition hover:-translate-y-0.5 hover:bg-indigo-700">Enregistrer / remplacer le routage</button></div>
            </form>
        </section>

        <section class="fpc-card mt-6 p-6 md:p-8">
            <div><div class="text-xs font-black uppercase tracking-[.18em] text-emerald-600">Configuration actuelle</div><h2 class="mt-2 text-2xl font-black text-slate-950">Routages actifs</h2><p class="mt-2 text-sm text-slate-500">Les badges représentent toutes les personnes qui recevront une notification pour la catégorie.</p></div>
            <?php if ($groupedRows): ?><div class="mt-6 grid gap-4 lg:grid-cols-2"><?php foreach ($groupedRows as $group): ?><article class="fpc-active-card"><div class="flex items-start justify-between gap-3"><div><span class="inline-flex rounded-full bg-indigo-100 px-3 py-1 text-xs font-black text-indigo-800"><?= htmlspecialchars($group['category']) ?></span><div class="mt-3 flex flex-wrap gap-2"><?php foreach($group['services'] as $serviceLabel):?><span class="rounded-full bg-slate-200 px-3 py-1 text-xs font-bold text-slate-700"><?=htmlspecialchars($serviceLabel)?></span><?php endforeach;?></div></div><span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800"><?= count($group['people']) ?> actif(s)</span></div><div class="mt-4 flex flex-wrap gap-2"><?php foreach ($group['people'] as $person): ?><form method="post" class="fpc-chip"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><?= htmlspecialchars((string) $person['user_name']) ?><small class="font-normal text-indigo-600"><?= htmlspecialchars((string) $person['fonction']) ?> · <?=htmlspecialchars((string)$person['lib_service'])?></small><button name="delete_id" value="<?= (int) $person['id'] ?>" title="Retirer <?= htmlspecialchars((string) $person['user_name']) ?>" aria-label="Retirer" onclick="return confirm('Retirer cette personne du routage ?')">×</button></form><?php endforeach; ?></div><div class="mt-5 flex gap-2"><button type="button" class="edit-routing rounded-xl border border-indigo-200 px-4 py-2 text-sm font-black text-indigo-700" data-category="<?=htmlspecialchars($group['category'])?>" data-services="<?=htmlspecialchars(json_encode(array_keys($group['services'])))?>" data-users="<?=htmlspecialchars(json_encode(array_map('intval',array_column($group['people'],'user_id'))))?>"><i class="fas fa-pen mr-1"></i>Modifier</button><form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf)?>"><button name="delete_category" value="<?=htmlspecialchars($group['category'])?>" class="rounded-xl bg-rose-50 px-4 py-2 text-sm font-black text-rose-700" onclick="return confirm('Supprimer tout le routage de cette catégorie ?')"><i class="fas fa-trash mr-1"></i>Supprimer la règle</button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center text-slate-500">Aucun routage actif. Créez la première règle ci-dessus.</div><?php endif; ?>
        </section>
    </div>
</main>
<script>
(() => {
    const services = <?= json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const users = <?= json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const serviceSearch = document.getElementById('serviceSearch'), serviceResults = document.getElementById('serviceResults'), selectedServicesBox=document.getElementById('selectedServices');
    const userSearch = document.getElementById('userSearch'), userResults = document.getElementById('userResults'), selectedBox = document.getElementById('selectedUsers'), categorySelect=document.querySelector('[name="category"]');
    const selectedServices=new Map(),selected = new Map();
    const norm = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    const esc = value => String(value || '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    function showServices() { const q=norm(serviceSearch.value); const rows=services.filter(s=>!selectedServices.has(Number(s.id))&&(!q||norm(s.label).includes(q))).slice(0,30); serviceResults.innerHTML=rows.map(s=>`<button type="button" class="fpc-option" data-service-id="${Number(s.id)}"><strong>${esc(s.label)}</strong></button>`).join('')||'<div class="p-4 text-sm text-slate-500">Aucun autre service trouvé.</div>'; serviceResults.hidden=false; }
    function renderServices(){selectedServicesBox.querySelectorAll('.fpc-chip,input').forEach(node=>node.remove());document.getElementById('emptyServicesHint').hidden=selectedServices.size>0;selectedServices.forEach(service=>{const chip=document.createElement('span');chip.className='fpc-chip';chip.innerHTML=`${esc(service.label)}<button type="button" data-remove-service="${Number(service.id)}">×</button><input type="hidden" name="service_ids[]" value="${Number(service.id)}">`;selectedServicesBox.appendChild(chip)});userSearch.disabled=selectedServices.size===0;for(const [id,person] of selected)if(!selectedServices.has(Number(person.service_id)))selected.delete(id);renderSelected();}
    function renderSelected() { selectedBox.querySelectorAll('.fpc-chip,input').forEach(node=>node.remove()); document.getElementById('emptyUsersHint').hidden=selected.size>0; selected.forEach(person=>{const chip=document.createElement('span');chip.className='fpc-chip';chip.innerHTML=`${esc(person.label)} <small class="font-normal">${esc(person.fonction)}</small><button type="button" data-remove-user="${Number(person.id)}">×</button><input type="hidden" name="user_ids[]" value="${Number(person.id)}">`;selectedBox.appendChild(chip);}); }
    function showUsers(){const q=norm(userSearch.value);if(!selectedServices.size)return;const rows=users.filter(u=>selectedServices.has(Number(u.service_id))&&!selected.has(Number(u.id))&&(!q||norm(`${u.label} ${u.fonction}`).includes(q))).slice(0,60);userResults.innerHTML=rows.map(u=>`<button type="button" class="fpc-option" data-user-id="${Number(u.id)}"><strong>${esc(u.label)}</strong><span class="mt-1 block text-xs text-slate-500">${esc(u.fonction)} · ${esc((services.find(s=>Number(s.id)===Number(u.service_id))||{}).label||'')}</span></button>`).join('')||'<div class="p-4 text-sm text-slate-500">Aucune autre personne disponible.</div>';userResults.hidden=false;}
    serviceSearch.addEventListener('focus',showServices);serviceSearch.addEventListener('input',showServices);
    serviceResults.addEventListener('click',event=>{const button=event.target.closest('[data-service-id]');if(!button)return;const service=services.find(s=>Number(s.id)===Number(button.dataset.serviceId));if(!service)return;selectedServices.set(Number(service.id),service);serviceSearch.value='';serviceResults.hidden=true;renderServices();userSearch.focus();});
    selectedServicesBox.addEventListener('click',event=>{const button=event.target.closest('[data-remove-service]');if(!button)return;selectedServices.delete(Number(button.dataset.removeService));renderServices();});
    userSearch.addEventListener('focus',showUsers);userSearch.addEventListener('input',showUsers);userResults.addEventListener('click',event=>{const button=event.target.closest('[data-user-id]');if(!button)return;const person=users.find(u=>Number(u.id)===Number(button.dataset.userId));if(!person)return;selected.set(Number(person.id),person);userSearch.value='';userResults.hidden=true;renderSelected();userSearch.focus();});
    selectedBox.addEventListener('click',event=>{const button=event.target.closest('[data-remove-user]');if(!button)return;selected.delete(Number(button.dataset.removeUser));renderSelected();});
    document.addEventListener('click',event=>{if(!event.target.closest('#serviceSearch,#serviceResults'))serviceResults.hidden=true;if(!event.target.closest('#userSearch,#userResults'))userResults.hidden=true;});
    document.querySelectorAll('.edit-routing').forEach(button=>button.addEventListener('click',()=>{categorySelect.value=button.dataset.category;selectedServices.clear();selected.clear();JSON.parse(button.dataset.services||'[]').forEach(id=>{const service=services.find(s=>Number(s.id)===Number(id));if(service)selectedServices.set(Number(id),service)});JSON.parse(button.dataset.users||'[]').forEach(id=>{const person=users.find(u=>Number(u.id)===Number(id));if(person)selected.set(Number(id),person)});renderServices();renderSelected();document.getElementById('challengerSettingsForm').scrollIntoView({behavior:'smooth',block:'start'});}));
    document.getElementById('challengerSettingsForm').addEventListener('submit',event=>{if(selectedServices.size===0||selected.size===0){event.preventDefault();alert('Choisissez au moins un service et une personne autorisée.');}});
})();
</script>
