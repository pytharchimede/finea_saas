<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../services/FebPriceChallengeService.php';
require_once __DIR__ . '/../repositories/FournisseurRepository.php';

$pdo = Database::getConnection();
$service = new FebPriceChallengeService($pdo);
$userId = (int) ($_SESSION['user_id'] ?? 0);
$_SESSION['feb_challenge_csrf'] ??= bin2hex(random_bytes(24));
$csrf = (string) $_SESSION['feb_challenge_csrf'];
$message = '';
$error = '';
$uploadedFilePath = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Session expirée. Rechargez la page.');
        }
        $id = (int) ($_POST['challenge_id'] ?? 0);
        $responseMode = (string) ($_POST['response_mode'] ?? 'proposal');
        $proposalNote = trim((string) ($_POST['proposal_note'] ?? ''));
        if ($responseMode === 'none') {
            if (!$service->submitNoProposal($id, $userId, $proposalNote)) {
                throw new RuntimeException('Cette consultation ne vous est plus assignée.');
            }
            $message = 'Votre réponse « aucune proposition » a été transmise aux validateurs FEB.';
        } else {
        $price = (float) ($_POST['proposed_total_amount'] ?? $_POST['proposed_unit_price'] ?? 0);
        $supplierId = (int) ($_POST['supplier_id'] ?? 0);
        $supplierStmt = $pdo->prepare('SELECT COALESCE(NULLIF(nom_commercial,""),raison_sociale) nom,disbursement_selection_blocked blocked,disbursement_block_reason reason,disbursement_block_fiches fiches FROM fournisseur_banamur WHERE id_fournisseur=:id AND actif=1 AND validation_status="approved"');
        $supplierStmt->execute(['id' => $supplierId]);
        $supplierRow=$supplierStmt->fetch(PDO::FETCH_ASSOC)?:[];$supplierName=trim((string)($supplierRow['nom']??''));
        if ($price <= 0 || $supplierName === '') {
            throw new RuntimeException('Le prix et un fournisseur validé sont obligatoires.');
        }
        if (!empty($supplierRow['blocked'])) throw new RuntimeException('Fournisseur temporairement indisponible : '.(string)($supplierRow['reason']??'justificatifs à fournir').' — '.(string)($supplierRow['fiches']??'fiche concernée').'.');

        $file = is_array($_FILES['justification'] ?? null) ? (array) $_FILES['justification'] : [];
        if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) <= 0 || (int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('Un justificatif PDF ou image de 10 Mo maximum est obligatoire.');
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = class_exists('finfo') && is_file($tmp) ? (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
        if (!isset($allowed[$ext]) || ($mime !== '' && $mime !== $allowed[$ext])) {
            throw new RuntimeException('Le format réel du justificatif est invalide.');
        }
        $dir = __DIR__ . '/../uploads/feb_price_challenges';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Le dossier des justificatifs est indisponible.');
        }
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        $uploadedFilePath = $dir . '/' . $name;
        if (!move_uploaded_file($tmp, $uploadedFilePath)) {
            $uploadedFilePath = '';
            throw new RuntimeException('Le justificatif n’a pas pu être enregistré.');
        }
        if (!$service->submit($id, $userId, $price, $supplierId, $supplierName, '/uploads/feb_price_challenges/' . $name, '', '', $proposalNote)) {
            throw new RuntimeException('Cette consultation ne vous est plus assignée.');
        }
        $uploadedFilePath = '';
        $message = 'Votre proposition et son justificatif ont été transmis aux seuls validateurs FEB.';
        }
    } catch (Throwable $e) {
        if ($uploadedFilePath !== '' && is_file($uploadedFilePath)) {
            @unlink($uploadedFilePath);
        }
        $error = $e->getMessage();
    }
}

$allItems = $service->inbox($userId);
$allowedStatuses = ['all','pending','submitted','retained','dismissed'];
$statusFilter = strtolower(trim((string)($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'all';
$searchQuery = trim((string)($_GET['q'] ?? ''));
$statusCounts = array_fill_keys(['pending','submitted','retained','dismissed'], 0);
foreach ($allItems as $challengeItem) {
    $challengeStatus = strtolower(trim((string)($challengeItem['status'] ?? '')));
    if (isset($statusCounts[$challengeStatus])) $statusCounts[$challengeStatus]++;
}
$items = array_values(array_filter($allItems, static function(array $item) use ($statusFilter, $searchQuery): bool {
    if ($statusFilter !== 'all' && strtolower(trim((string)($item['status'] ?? ''))) !== $statusFilter) return false;
    if ($searchQuery === '') return true;
    $haystack = implode(' ', [(string)($item['category']??''),(string)($item['designation']??''),(string)($item['quote_title']??''),(string)($item['chantier_label']??''),(string)($item['proposed_supplier_name']??'')]);
    return mb_stripos($haystack, $searchQuery) !== false;
}));
$suppliers = (new FournisseurRepository($pdo))->listForSelect(true, true);
$pendingCount = $statusCounts['pending'];
$submittedCount = $statusCounts['submitted'];
$filterUrl = static fn(string $status): string => 'contestations_prix_feb.php?' . http_build_query(array_filter(['status'=>$status,'q'=>$searchQuery], static fn($v): bool => $v !== ''));
$exportQuery = http_build_query(['status'=>$statusFilter,'q'=>$searchQuery]);
$pageTitle = 'Contestations de prix';
$layoutRootBase = '../';
$activeEmployeeMenu = 'contestations';
require __DIR__ . '/../decaissement/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main class="mx-auto max-w-7xl px-4 py-8">
    <section class="overflow-hidden rounded-[2rem] border border-indigo-200 bg-gradient-to-br from-indigo-950 via-indigo-900 to-violet-800 p-6 text-white shadow-xl md:p-8">
        <div class="text-xs font-black uppercase tracking-[0.22em] text-indigo-200">Contestations de prix FEB</div>
        <h1 class="mt-3 text-3xl font-black">Proposer un prix en toute confidentialité</h1>
        <p class="mt-3 max-w-3xl text-sm leading-6 text-indigo-100">Vous voyez uniquement le besoin, sa catégorie, sa quantité, le chantier et les dates utiles. Le demandeur, son identité, son fournisseur et son prix initial restent masqués.</p>
        <div class="mt-5 flex flex-wrap gap-2 text-xs font-bold"><span class="rounded-full bg-white/10 px-3 py-2">1. Étudier le besoin</span><span class="rounded-full bg-white/10 px-3 py-2">2. Chiffrer et planifier</span><span class="rounded-full bg-white/10 px-3 py-2">3. Joindre le devis obligatoire</span></div>
        <div class="mt-6 grid max-w-xl grid-cols-3 gap-3"><div class="rounded-2xl bg-white/10 p-4"><div class="text-xs text-indigo-200">Total affiché</div><div class="mt-1 text-2xl font-black"><?= count($items) ?></div></div><div class="rounded-2xl bg-amber-400/15 p-4"><div class="text-xs text-amber-100">À traiter</div><div class="mt-1 text-2xl font-black"><?= $pendingCount ?></div></div><div class="rounded-2xl bg-emerald-400/15 p-4"><div class="text-xs text-emerald-100">Transmises</div><div class="mt-1 text-2xl font-black"><?= $submittedCount ?></div></div></div>
    </section>

    <?php if ($message !== ''): ?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-semibold text-emerald-800"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-semibold text-rose-800"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><form method="get" class="grid gap-3 md:grid-cols-[1fr_auto]"><input type="hidden" name="status" value="<?=htmlspecialchars($statusFilter)?>"><div class="flex gap-2"><input name="q" value="<?=htmlspecialchars($searchQuery)?>" type="search" class="min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-100" placeholder="Rechercher une catégorie, un besoin ou un chantier…"><button class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white">Rechercher</button></div><div class="flex flex-wrap gap-2"><?php foreach(['all'=>['Toutes',count($allItems)],'pending'=>['À traiter',$statusCounts['pending']],'submitted'=>['Transmises',$statusCounts['submitted']],'retained'=>['Retenues',$statusCounts['retained']],'dismissed'=>['Non retenues',$statusCounts['dismissed']]] as $key=>$filterMeta):?><a href="<?=htmlspecialchars($filterUrl($key))?>" class="rounded-xl px-4 py-2 text-sm font-bold <?=$statusFilter===$key?'bg-indigo-600 text-white':'bg-slate-100 text-slate-700 hover:bg-slate-200'?>"><?=htmlspecialchars($filterMeta[0])?> <span class="ml-1 opacity-70"><?= (int)$filterMeta[1] ?></span></a><?php endforeach;?></div></form><div class="mt-3 flex flex-wrap justify-end gap-2"><a href="../request/export_contestations_prix_feb_excel.php?<?=htmlspecialchars($exportQuery)?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-bold text-emerald-800"><i class="fas fa-file-excel mr-2"></i>Excel</a><a href="../request/export_contestations_prix_feb_pdf.php?<?=htmlspecialchars($exportQuery)?>" target="_blank" rel="noopener" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-bold text-rose-800"><i class="fas fa-file-pdf mr-2"></i>PDF</a></div></div>

    <div id="challengeList" class="mt-6 grid gap-5">
        <?php foreach ($items as $item): ?>
            <article class="challenge-item overflow-hidden rounded-[1.75rem] border border-slate-200 bg-white shadow-sm" data-status="<?= htmlspecialchars((string)$item['status']) ?>" data-search="<?= htmlspecialchars(strtolower((string)($item['category'].' '.$item['designation'].' '.$item['quote_title'].' '.$item['chantier_label']))) ?>">
                <div class="border-b border-slate-100 bg-slate-50 px-5 py-4 md:px-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div><div class="text-xs font-black uppercase tracking-[0.16em] text-indigo-600"><?= htmlspecialchars((string) $item['category']) ?></div><h2 class="mt-1 text-xl font-black text-slate-950"><?= htmlspecialchars((string) ($item['designation'] ?: $item['quote_title'])) ?></h2></div>
                        <?php $noProposal=($item['response_type']??'proposal')==='none'; $decisionLabels=['pending'=>'À traiter','submitted'=>$noProposal?'Aucune proposition':'Proposition transmise','retained'=>'Proposition retenue','dismissed'=>'Non retenue']; $decisionClasses=['pending'=>'bg-amber-100 text-amber-800','submitted'=>$noProposal?'bg-slate-200 text-slate-800':'bg-sky-100 text-sky-800','retained'=>'bg-emerald-100 text-emerald-800','dismissed'=>'bg-slate-200 text-slate-700']; ?>
                        <span class="rounded-full <?= $decisionClasses[$item['status']] ?? 'bg-slate-100 text-slate-700' ?> px-3 py-1 text-xs font-bold"><?= $decisionLabels[$item['status']] ?? htmlspecialchars((string)$item['status']) ?></span>
                    </div>
                </div>
                <div class="grid gap-5 p-5 md:grid-cols-[.8fr_1.2fr] md:p-6">
                    <div class="grid content-start gap-3 text-sm">
                        <div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-bold uppercase text-slate-500">Chantier</div><div class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string) $item['chantier_label']) ?></div></div>
                        <div class="grid grid-cols-2 gap-3"><div class="rounded-2xl bg-slate-50 p-4"><div class="text-xs font-bold uppercase text-slate-500">Type / quantité</div><div class="mt-1 text-lg font-black"><?= htmlspecialchars((string) ($item['type'] ?: 'Besoin')) ?> · <?= htmlspecialchars((string) $item['quantity']) ?></div></div><div class="rounded-2xl bg-sky-50 p-4"><div class="text-xs font-bold uppercase text-sky-700">Planning demandé</div><div class="mt-1 font-bold text-slate-900"><?= htmlspecialchars((string) ($item['planned_start_date'] ?: '—')) ?> → <?= htmlspecialchars((string) ($item['planned_end_date'] ?: '—')) ?></div></div></div>
                        <?php if (!empty($item['justification_path'])): ?><a href="<?= htmlspecialchars((string)$item['justification_path']) ?>" target="_blank" rel="noopener" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800">Voir le dernier justificatif transmis ↗</a><?php endif; ?>
                    </div>
                    <?php if (in_array((string)$item['status'], ['pending','submitted'], true)): ?>
                    <form method="post" enctype="multipart/form-data" class="challenge-form grid gap-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 md:grid-cols-2">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"><input type="hidden" name="challenge_id" value="<?= (int) $item['id'] ?>">
                        <?php if($noProposal): ?><div class="rounded-2xl border border-slate-300 bg-white p-4 md:col-span-2"><div class="flex gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-slate-900 text-white"><i class="fas fa-circle-minus"></i></span><div><div class="font-black text-slate-900">Aucune proposition déclarée</div><p class="mt-1 text-sm text-slate-600"><?= nl2br(htmlspecialchars((string)($item['proposal_note']??''))) ?></p><p class="mt-2 text-xs font-bold text-indigo-700">Vous pouvez néanmoins transmettre une proposition si votre situation évolue.</p></div></div></div><?php endif; ?>
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 md:col-span-2"><div class="flex items-start gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-amber-400 text-slate-950"><i class="fas fa-calculator"></i></span><div><div class="font-black text-slate-950">Nous attendons le montant total pour <?= htmlspecialchars(number_format((float)$item['quantity'], 2, ',', ' ')) ?> unité(s)</div><p class="mt-1 text-sm leading-6 text-amber-900">Saisissez le coût complet de toute la quantité demandée, et non le prix d’une seule unité. Le prix unitaire sera calculé automatiquement pour contrôle.</p></div></div></div>
                        <label class="text-sm font-bold text-slate-700">Montant total pour les <?= htmlspecialchars(number_format((float)$item['quantity'], 2, ',', ' ')) ?> unité(s) demandée(s)<span class="ml-1 text-rose-600">*</span><div class="relative mt-2"><input type="number" min="1" step="0.01" name="proposed_total_amount" value="<?= htmlspecialchars((string) ($item['proposed_total_amount']??'')) ?>" class="challenge-price w-full rounded-xl border-2 border-indigo-200 bg-white px-3 py-3 pr-16 text-lg font-black" data-quantity="<?= htmlspecialchars((string)$item['quantity']) ?>" placeholder="Ex. 10 500 000" required><span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-xs font-black text-slate-500">FCFA</span></div><span class="challenge-total mt-2 block rounded-xl bg-white px-3 py-2 text-xs font-bold text-indigo-700">Montant couvrant toute la quantité demandée.</span></label>
                        <div class="relative text-sm font-bold text-slate-700">Fournisseur proposé<input type="hidden" name="supplier_id" class="supplier-id" value="<?= (int)($item['proposed_supplier_id']??0) ?>"><input type="search" class="supplier-search mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-3" value="<?= htmlspecialchars((string)($item['proposed_supplier_name']??'')) ?>" placeholder="Rechercher un fournisseur validé…" autocomplete="off" required><div class="supplier-results absolute inset-x-0 top-full z-40 mt-1 max-h-56 overflow-auto rounded-xl border border-slate-200 bg-white shadow-xl" hidden></div></div>
                        <label class="text-sm font-bold text-slate-700 md:col-span-2">Précisions ou motif d’absence de proposition<textarea name="proposal_note" rows="3" class="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-3" placeholder="Délais, conditions, disponibilité… ou raison pour laquelle vous n’avez aucune proposition."><?= htmlspecialchars((string)($item['proposal_note']??'')) ?></textarea></label>
                        <label class="text-sm font-bold text-slate-700 md:col-span-2">Devis ou justificatif de cette proposition<input type="file" name="justification" accept=".pdf,.jpg,.jpeg,.png,.webp,image/*" class="mt-2 block w-full rounded-xl border border-dashed border-indigo-300 bg-white p-3" required><span class="mt-1 block text-xs font-normal text-slate-500">Obligatoire à chaque soumission. PDF ou image, 10 Mo maximum.</span></label>
                        <div class="grid gap-3 md:col-span-2 md:grid-cols-2"><button type="submit" name="response_mode" value="none" formnovalidate class="no-proposal-button rounded-xl border border-slate-300 bg-white px-4 py-3 font-bold text-slate-800 hover:bg-slate-100"><i class="fas fa-circle-minus mr-2"></i>Je n’ai aucune proposition</button><button type="submit" name="response_mode" value="proposal" class="rounded-xl bg-indigo-600 px-4 py-3 font-bold text-white hover:bg-indigo-700"><i class="fas fa-paper-plane mr-2"></i>Transmettre la proposition</button></div>
                    </form>
                    <?php else: ?>
                    <div class="rounded-2xl border <?= $item['status']==='retained'?'border-emerald-200 bg-emerald-50':'border-slate-200 bg-slate-50' ?> p-5">
                        <div class="text-xs font-black uppercase tracking-wider text-slate-500">Décision finale</div>
                        <div class="mt-2 text-xl font-black <?= $item['status']==='retained'?'text-emerald-800':'text-slate-800' ?>"><?= $item['status']==='retained'?'Proposition retenue':'Proposition non retenue' ?></div>
                        <p class="mt-3 text-sm leading-6 text-slate-600"><?= $item['status']==='retained'?'Préparez les documents originaux et justificatifs nécessaires lors du décaissement.':'Une autre proposition a été sélectionnée par les validateurs pour cette ligne.' ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (!$items): ?><div class="rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center text-slate-500"><?= $allItems ? 'Aucune consultation ne correspond à ce statut ou à cette recherche.' : 'Aucune consultation de prix ne vous est actuellement assignée.' ?></div><?php endif; ?>
    </div>
</main>
<script>
(() => {
    const suppliers = <?= json_encode(array_map(static fn(array $s): array => ['id'=>(int)($s['id_fournisseur']??0),'name'=>(string)($s['supplier_display_name']??$s['nom_commercial']??$s['raison_sociale']??''),'blocked'=>!empty($s['disbursement_selection_blocked']),'reason'=>(string)($s['disbursement_block_reason']??''),'fiches'=>(string)($s['disbursement_block_fiches']??'')], $suppliers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const esc=v=>String(v||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    document.querySelectorAll('.supplier-search').forEach(input=>{const form=input.closest('form'),hidden=form.querySelector('.supplier-id'),box=form.querySelector('.supplier-results');const show=()=>{const q=input.value.trim().toLowerCase();const rows=suppliers.filter(s=>!q||(s.name+' '+s.reason+' '+s.fiches).toLowerCase().includes(q)).slice(0,30);box.innerHTML=rows.map(s=>`<button type="button" ${s.blocked?'disabled':''} class="block w-full px-3 py-2 text-left text-sm ${s.blocked?'cursor-not-allowed bg-slate-100 text-slate-400':'hover:bg-indigo-50'}" data-id="${s.id}" data-name="${esc(s.name)}">${esc(s.name)}${s.blocked?`<small class="block font-bold text-rose-600">${esc(s.reason)} · ${esc(s.fiches)}</small>`:''}</button>`).join('')||'<div class="p-3 text-sm text-slate-500">Aucun fournisseur trouvé.</div>';box.hidden=false;};input.addEventListener('focus',show);input.addEventListener('input',()=>{hidden.value='';show();});box.addEventListener('click',e=>{const b=e.target.closest('[data-id]:not([disabled])');if(!b)return;hidden.value=b.dataset.id;input.value=b.dataset.name;box.hidden=true;});});
    document.querySelectorAll('.challenge-price').forEach(input=>{const update=()=>{const total=Number(input.value)||0,quantity=Math.max(1,Number(input.dataset.quantity)||1),unit=total/quantity,target=input.closest('label')?.querySelector('.challenge-total');if(target)target.textContent=total>0?'Total pour '+quantity.toLocaleString('fr-FR')+' unité(s) : '+total.toLocaleString('fr-FR')+' FCFA · Prix unitaire calculé : '+unit.toLocaleString('fr-FR',{maximumFractionDigits:2})+' FCFA':'Montant couvrant toute la quantité demandée.';};input.addEventListener('input',update);update();});
    document.addEventListener('click',e=>{if(!e.target.closest('.supplier-search,.supplier-results'))document.querySelectorAll('.supplier-results').forEach(box=>box.hidden=true);});
    document.querySelectorAll('.challenge-form').forEach(form=>form.addEventListener('submit',e=>{const mode=e.submitter?.value||'proposal';if(mode==='none'){const note=form.querySelector('[name="proposal_note"]').value.trim();if(!note){e.preventDefault();alert('Indiquez brièvement pourquoi vous n’avez aucune proposition.');return;}if(!confirm('Confirmer que vous ne disposez d’aucune proposition pour ce besoin ?'))e.preventDefault();return;}if(!Number(form.querySelector('.supplier-id').value)){e.preventDefault();alert('Sélectionnez un fournisseur dans la liste de recherche.');}}));
})();
</script>
