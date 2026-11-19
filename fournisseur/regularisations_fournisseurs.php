<?php
declare(strict_types=1);
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../model/DisbursementRegularizationManager.php';
require_once __DIR__.'/../services/SupplierDisbursementUnblockService.php';

$pdo=Database::getConnection();
$regularizationManager=new DisbursementRegularizationManager($pdo);
AccessManager::refreshSessionPermissions();
$canAttach=AccessManager::hasPermission('regulariser_fournisseur_sans_preuve')||AccessManager::hasPermission('acces_compta')||AccessManager::hasPermission('acces_tresorerie')||AccessManager::hasPermission('acces_admin');
$canBypass=AccessManager::hasPermission('regulariser_fournisseur_sans_preuve');
if(!$canAttach&&!$canBypass&&!AccessManager::hasPermission('acces_fournisseur')){http_response_code(403);require __DIR__.'/../acces_refuse.php';exit;}
if(empty($_SESSION['supplier_regularizations_csrf']))$_SESSION['supplier_regularizations_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['supplier_regularizations_csrf'];$success='';$error='';
$actorId=(int)($_SESSION['user_id']??0);$actorName=trim((string)($_SESSION['user_name']??''))?:trim((string)(($_SESSION['prenom']??'').' '.($_SESSION['nom']??'')));

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée. Rechargez la page.');
        AccessManager::refreshSessionPermissions();
        $action=(string)($_POST['action']??'');$num=trim((string)($_POST['num_fiche']??''));
        if($action==='attach_proof'){
            if(!(AccessManager::hasPermission('regulariser_fournisseur_sans_preuve')||AccessManager::hasPermission('acces_compta')||AccessManager::hasPermission('acces_tresorerie')||AccessManager::hasPermission('acces_admin')))throw new RuntimeException('Permission comptable requise pour associer un justificatif.');
            $regularizationManager->attachPaymentProof($num,$actorId,$actorName,(array)($_FILES['payment_proof']??[]),(string)($_POST['document_type']??'payment_proof'));
            $success='Justificatif associé à la fiche '.$num.'. Les autres régularisations restent indépendantes.';
        }elseif($action==='bypass'){
            if(!AccessManager::hasPermission('regulariser_fournisseur_sans_preuve'))throw new RuntimeException('Permission de bypass requise.');
            (new SupplierDisbursementUnblockService($pdo))->bypassOne($num,$actorId,$actorName,(string)($_POST['bypass_reason']??''));
            $success='Bypass individuel enregistré. Les autres dossiers restent soumis à régularisation.';
        }else throw new RuntimeException('Action inconnue.');
    }catch(Throwable $e){
        error_log('[Supplier regularization] '.get_class($e).': '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
        $error=$e->getMessage();
    }
}

$sql="SELECT s.id_fournisseur,COALESCE(NULLIF(TRIM(s.nom_commercial),''),NULLIF(TRIM(s.raison_sociale),''),CONCAT('Fournisseur #',s.id_fournisseur)) supplier_name,
 s.code_fournisseur,s.raison_sociale,s.contact_nom,s.telephone,s.email,s.adresse,s.ville,s.logo_path,s.photo_path,s.supplier_type,s.disbursement_block_reason,s.disbursement_block_fiches,r.id regularization_id,r.num_fiche,r.project_name,r.validated_amount,r.created_at,r.submitted_at,r.payment_proof_path,r.payment_proof_name,r.access_blocked_at,f.facture_reference,f.id_fiche,f.details_demande,f.precision_fiche,f.chantier_id,f.ligne_debourse_id,
 f.designation_fiche,COALESCE(f.date_decaisse,f.date_decaissement,f.date_creat_fiche) payment_date,ldb.debourse_id,ldb.designation ligne_debourse_designation,ldb.categorie ligne_debourse_categorie,ldb.montant ligne_debourse_montant,db.devis_id,db.ligne_devis_id,db.montant_debourse,ld.designation ligne_devis_designation,
 COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))),''),NULLIF(TRIM(CONCAT(COALESCE(ub.prenom,''),' ',COALESCE(ub.nom,''))),''),'Utilisateur non identifié') requester_name
 FROM fournisseur_banamur s JOIN fiche_decaissement f ON f.fournisseur_id=s.id_fournisseur
 JOIN disbursement_regularizations r ON BINARY r.num_fiche=BINARY f.num_fiche
 LEFT JOIN ligne_debourse_banamur ldb ON ldb.id=f.ligne_debourse_id LEFT JOIN debourse_banamur db ON db.id=ldb.debourse_id LEFT JOIN ligne_devis_banamur ld ON ld.id=db.ligne_devis_id
 LEFT JOIN user_devis_banamur u ON u.id=r.requester_user_id LEFT JOIN user_devis_banamur ub ON ub.id=r.beneficiary_user_id
 WHERE r.cancelled_at IS NULL AND (COALESCE(r.payment_proof_path,'')='' OR COALESCE(f.facture_reference,'')='') ORDER BY supplier_name,r.created_at ASC";
$rows=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
$groups=[];
foreach($rows as $row)$groups[(int)$row['id_fournisseur']][]=$row;

// Un fournisseur bloqué doit rester visible même si aucune régularisation
// active ne correspond à une FEB (ancien dossier, rattachement manquant, etc.).
$blockedSql="SELECT s.id_fournisseur,
 COALESCE(NULLIF(TRIM(s.nom_commercial),''),NULLIF(TRIM(s.raison_sociale),''),CONCAT('Fournisseur #',s.id_fournisseur)) supplier_name,
 s.code_fournisseur,s.raison_sociale,s.contact_nom,s.telephone,s.email,s.adresse,s.ville,
 s.logo_path,s.photo_path,s.supplier_type,s.disbursement_selection_blocked,
 s.disbursement_block_reason,s.disbursement_block_fiches
 FROM fournisseur_banamur s
 WHERE COALESCE(s.disbursement_selection_blocked,0)=1
 ORDER BY supplier_name";
$blockedSuppliers=$pdo->query($blockedSql)->fetchAll(PDO::FETCH_ASSOC)?:[];
$blockedOnly=[];
foreach($blockedSuppliers as $blockedSupplier){
    $supplierId=(int)$blockedSupplier['id_fournisseur'];
    if(!isset($groups[$supplierId]))$blockedOnly[$supplierId]=$blockedSupplier;
}
$blockedCount=count($blockedSuppliers);

$supplierSearchData=[];foreach($groups as $supplierId=>$supplierRows){$s=$supplierRows[0];$visual=trim((string)($s['photo_path']?:$s['logo_path']));$supplierSearchData[]=['id'=>$supplierId,'name'=>(string)$s['supplier_name'],'code'=>(string)$s['code_fournisseur'],'contact'=>(string)$s['contact_nom'],'phone'=>(string)$s['telephone'],'email'=>(string)$s['email'],'address'=>trim((string)(($s['adresse']??'').' '.($s['ville']??''))),'visual'=>$visual!==''?'../'.ltrim($visual,'/'):'' ,'count'=>count($supplierRows)];}
foreach($blockedOnly as $supplierId=>$s){
    $visual=trim((string)($s['photo_path']?:$s['logo_path']));
    $supplierSearchData[]=['id'=>$supplierId,'name'=>(string)$s['supplier_name'],'code'=>(string)$s['code_fournisseur'],'contact'=>(string)$s['contact_nom'],'phone'=>(string)$s['telephone'],'email'=>(string)$s['email'],'address'=>trim((string)(($s['adresse']??'').' '.($s['ville']??''))),'visual'=>$visual!==''?'../'.ltrim($visual,'/'):'' ,'count'=>0];
}
$pageTitle='Régularisations fournisseurs';$activeMenu='fournisseur';require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
?>
<div class="supplier-page-bg"><div class="supplier-directory">
 <?php supplierWorkspaceHeader('Régularisations fournisseurs', 'Retrouvez les pièces attendues et les décaissements qui nécessitent une régularisation.', 0); ?>
 <?php if($success):?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-bold text-emerald-800"><?=htmlspecialchars($success)?></div><?php endif?>
 <?php if($error):?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-bold text-rose-800"><?=htmlspecialchars($error)?></div><?php endif?>
 <div class="relative z-30 mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
  <label for="supplierRegularizationSearch" class="text-xs font-black uppercase tracking-[.16em] text-slate-500">Rechercher un fournisseur</label>
  <div id="supplierSearchWrap" class="relative mt-2"><div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400"><i class="fas fa-magnifying-glass"></i></div><input id="supplierRegularizationSearch" type="search" autocomplete="off" placeholder="Saisir le nom, le code, le contact, le téléphone…" class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-24 text-sm font-semibold outline-none transition focus:border-indigo-300 focus:bg-white focus:ring-4 focus:ring-indigo-100"><button id="supplierSearchClear" type="button" class="absolute right-2 top-2 hidden rounded-xl px-3 py-1.5 text-xs font-black text-slate-500 hover:bg-slate-100">Tout voir</button><div id="supplierSearchResults" class="absolute inset-x-0 top-full z-50 mt-2 hidden max-h-80 overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl"></div></div>
 </div>
 <div class="mt-6 grid gap-4 md:grid-cols-3"><div class="rounded-2xl border bg-white p-5"><div class="text-xs font-bold uppercase text-slate-500">Fournisseurs bloqués</div><div class="mt-2 text-3xl font-black"><?=$blockedCount?></div></div><div class="rounded-2xl border bg-white p-5"><div class="text-xs font-bold uppercase text-slate-500">Fiches sans preuve</div><div class="mt-2 text-3xl font-black"><?=count($rows)?></div></div><div class="rounded-2xl border bg-white p-5"><div class="text-xs font-bold uppercase text-slate-500">Vos actions</div><div class="mt-2 text-sm font-bold text-slate-700"><?=$canAttach?'Justificatifs':''?><?=$canAttach&&$canBypass?' · ':''?><?=$canBypass?'Bypass audité':''?></div></div></div>
 <div class="mt-6 space-y-5">
 <?php if(!$groups&&!$blockedOnly):?><div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-10 text-center text-emerald-800"><i class="fas fa-circle-check text-3xl"></i><div class="mt-3 font-black">Aucune régularisation fournisseur en attente</div></div><?php endif?>
 <?php foreach($blockedOnly as $supplier):?>
 <section data-supplier-card="<?=(int)$supplier['id_fournisseur']?>" class="overflow-hidden rounded-3xl border border-amber-200 bg-white shadow-sm">
  <div class="flex flex-wrap items-start justify-between gap-3 border-b border-amber-100 bg-amber-50 p-5">
   <div>
    <div class="text-xs font-bold uppercase tracking-widest text-amber-700"><?=htmlspecialchars((string)$supplier['code_fournisseur'])?></div>
    <h2 class="mt-1 text-lg font-black text-slate-900"><?=htmlspecialchars((string)$supplier['supplier_name'])?></h2>
   </div>
   <span class="rounded-full bg-rose-100 px-3 py-1 text-xs font-black text-rose-700">Fournisseur bloqué · aucune FEB régularisable trouvée</span>
  </div>
  <div class="space-y-3 p-5 text-sm text-slate-700">
   <p><strong>Motif du blocage :</strong> <?=nl2br(htmlspecialchars(trim((string)($supplier['disbursement_block_reason']??''))?:'Non renseigné'))?></p>
   <p><strong>Fiches signalées :</strong> <?=htmlspecialchars(trim((string)($supplier['disbursement_block_fiches']??''))?:'Non renseignées')?></p>
   <p class="text-xs text-amber-800">Aucun bouton de validation automatique : vérifier le rattachement des FEB et le motif du blocage avant toute régularisation.</p>
  </div>
 </section>
 <?php endforeach?>
 <?php foreach($groups as $supplierRows):$supplier=$supplierRows[0];?>
 <section data-supplier-card="<?=(int)$supplier['id_fournisseur']?>" class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
  <header class="flex flex-wrap items-center justify-between gap-4 border-b bg-slate-900 px-6 py-5 text-white"><div class="flex min-w-0 items-center gap-4"><?php $supplierVisual=trim((string)($supplier['photo_path']?:$supplier['logo_path']));?><div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-white/20 bg-white/10 text-xl font-black"><?php if($supplierVisual!==''):?><img src="../<?=htmlspecialchars(ltrim($supplierVisual,'/'))?>" alt="" class="h-full w-full object-cover" onerror="this.remove();this.parentElement.textContent='<?=htmlspecialchars(mb_substr((string)$supplier['supplier_name'],0,1))?>'"><?php else:?><?=htmlspecialchars(mb_substr((string)$supplier['supplier_name'],0,1))?><?php endif?></div><div class="min-w-0"><div class="text-xs font-bold uppercase tracking-widest text-emerald-300"><?=htmlspecialchars((string)$supplier['code_fournisseur'])?> · <?=($supplier['supplier_type']??'morale')==='physique'?'Personne physique':'Personne morale'?></div><h2 class="mt-1 truncate text-xl font-black"><?=htmlspecialchars((string)$supplier['supplier_name'])?></h2><div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-300"><?php if($supplier['contact_nom']):?><span><i class="fas fa-user mr-1"></i><?=htmlspecialchars((string)$supplier['contact_nom'])?></span><?php endif?><?php if($supplier['telephone']):?><span><i class="fas fa-phone mr-1"></i><?=htmlspecialchars((string)$supplier['telephone'])?></span><?php endif?><?php if($supplier['email']):?><span><i class="fas fa-envelope mr-1"></i><?=htmlspecialchars((string)$supplier['email'])?></span><?php endif?><?php if($supplier['ville']):?><span><i class="fas fa-location-dot mr-1"></i><?=htmlspecialchars((string)$supplier['ville'])?></span><?php endif?></div></div></div><span class="rounded-full bg-rose-500/20 px-3 py-1 text-xs font-black text-rose-200"><?=count($supplierRows)?> fiche(s) bloquante(s)</span></header>
  <div class="divide-y divide-slate-100">
  <?php foreach($supplierRows as $row):?>
   <article class="grid gap-5 p-6 xl:grid-cols-[1fr_320px]">
    <div><div class="flex flex-wrap items-center gap-2"><span class="rounded-lg bg-indigo-50 px-3 py-1 text-sm font-black text-indigo-700"><?=htmlspecialchars((string)$row['num_fiche'])?></span><?php if($row['access_blocked_at']):?><span class="rounded-full bg-rose-50 px-2 py-1 text-xs font-bold text-rose-700">Accès demandeur bloqué</span><?php endif?></div><h3 class="mt-3 font-black text-slate-900"><?=htmlspecialchars((string)($row['project_name']?:$row['designation_fiche']))?></h3><div class="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-2"><div><span class="font-bold text-slate-800">Demande faite par :</span> <?=htmlspecialchars((string)$row['requester_name'])?></div><div><span class="font-bold text-slate-800">Montant :</span> <?=number_format((float)$row['validated_amount'],0,',',' ')?> FCFA</div><div><span class="font-bold text-slate-800">Décaissement :</span> <?=htmlspecialchars((string)($row['payment_date']?:'—'))?></div><div><span class="font-bold text-slate-800">Manquant :</span> <?=empty($row['payment_proof_path'])?'Preuve de paiement':''?><?=empty($row['payment_proof_path'])&&empty($row['facture_reference'])?' + ':''?><?=empty($row['facture_reference'])?'Facture/FNE':''?></div></div>
     <?php if(trim((string)($row['details_demande']??''))!==''||trim((string)($row['precision_fiche']??''))!==''):?><div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4"><div class="text-[10px] font-black uppercase tracking-widest text-slate-500">Détail de la fiche</div><p class="mt-2 text-sm font-semibold leading-6 text-slate-700"><?=nl2br(htmlspecialchars(trim((string)(($row['details_demande']??'').' '.($row['precision_fiche']??'')))))?></p></div><?php endif?>
     <div class="mt-4 rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4"><div class="text-[10px] font-black uppercase tracking-widest text-indigo-600">Détails et traçabilité</div><div class="mt-3 grid gap-2 text-xs sm:grid-cols-3"><div class="rounded-xl bg-white p-3"><span class="block font-black text-slate-500">Déboursé #<?= (int)($row['debourse_id']??0) ?></span><strong class="mt-1 block text-slate-900"><?=number_format((float)($row['montant_debourse']??0),0,',',' ')?> FCFA</strong></div><div class="rounded-xl bg-white p-3"><span class="block font-black text-slate-500">Ligne déboursé #<?= (int)($row['ligne_debourse_id']??0) ?></span><strong class="mt-1 block text-slate-900"><?=htmlspecialchars((string)($row['ligne_debourse_designation']?:'Non renseignée'))?></strong><small class="text-slate-500"><?=htmlspecialchars((string)($row['ligne_debourse_categorie']??''))?> · <?=number_format((float)($row['ligne_debourse_montant']??0),0,',',' ')?> FCFA</small></div><div class="rounded-xl bg-white p-3"><span class="block font-black text-slate-500">Ligne devis #<?= (int)($row['ligne_devis_id']??0) ?></span><strong class="mt-1 block text-slate-900"><?=htmlspecialchars((string)($row['ligne_devis_designation']?:'Non renseignée'))?></strong></div></div>
      <div class="mt-3 flex flex-wrap gap-2"><a target="_blank" href="../decaissement/voir_fiche_details.php?num_fiche=<?=rawurlencode((string)$row['num_fiche'])?>" class="rounded-xl bg-indigo-700 px-3 py-2 text-xs font-black text-white"><i class="fas fa-eye mr-1"></i>Détail fiche</a><a target="_blank" href="../decaissement/regularisation_decaissement.php?num_fiche=<?=rawurlencode((string)$row['num_fiche'])?>" class="rounded-xl border border-indigo-200 bg-white px-3 py-2 text-xs font-black text-indigo-700">Dossier de régularisation</a><?php if((int)($row['devis_id']??0)>0):?><a target="_blank" href="../debourse/voir.php?devisId=<?=(int)$row['devis_id']?>" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700">Voir le déboursé</a><a target="_blank" href="../devis/modifier.php?devisId=<?=(int)$row['devis_id']?>#devis-lines" class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700">Voir la ligne de devis</a><?php endif?><?php if(!empty($row['payment_proof_path'])):?><a target="_blank" href="../request/view_disbursement_payment_proof.php?num_fiche=<?=rawurlencode((string)$row['num_fiche'])?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700"><i class="fas fa-file-shield mr-1"></i>Preuve de paiement</a><?php endif?><?php if(!empty($row['facture_reference'])):?><a target="_blank" href="../decaissement/request/view_fiche_document.php?type=fne&amp;num_fiche=<?=rawurlencode((string)$row['num_fiche'])?>" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700"><i class="fas fa-file-invoice mr-1"></i>Facture / FNE</a><?php endif?></div>
     </div></div>
    <div class="space-y-3">
     <?php if($canBypass):?><p class="text-xs text-slate-600">Le bypass s'applique uniquement à la fiche sélectionnée. Les autres dossiers restent indépendants.</p><?php endif?>
     <?php if($canAttach):?><form method="post" enctype="multipart/form-data" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="attach_proof"><input type="hidden" name="num_fiche" value="<?=htmlspecialchars((string)$row['num_fiche'])?>"><label class="block text-xs font-black uppercase text-emerald-800">Associer un justificatif</label><select name="document_type" class="mt-2 w-full rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-bold"><?php if(empty($row['payment_proof_path'])):?><option value="payment_proof">Preuve de paiement</option><?php endif?><?php if(empty($row['facture_reference'])):?><option value="fne">Facture / FNE</option><?php endif?></select><input type="file" name="payment_proof" accept=".pdf,.jpg,.jpeg,.png,.webp,.heic" required class="mt-2 block w-full text-xs"><button class="mt-3 w-full rounded-xl bg-emerald-700 px-3 py-2 text-xs font-black text-white">Associer cette pièce</button></form><?php endif?>
     <?php if($canBypass):?><form method="post" class="rounded-2xl border border-rose-200 bg-rose-50 p-4" onsubmit="return confirm('Confirmer ce bypass nominatif et audité ?')"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($csrf)?>"><input type="hidden" name="action" value="bypass"><input type="hidden" name="num_fiche" value="<?=htmlspecialchars((string)$row['num_fiche'])?>"><label class="block text-xs font-black uppercase text-rose-800">Bypass sans preuve</label><textarea name="bypass_reason" minlength="8" required rows="2" class="mt-2 w-full rounded-xl border border-rose-200 px-3 py-2 text-xs" placeholder="Motif précis obligatoire…"></textarea><button class="mt-2 w-full rounded-xl bg-rose-700 px-3 py-2 text-xs font-black text-white">Bypass de cette fiche uniquement</button></form><?php endif?>
    </div>
   </article>
  <?php endforeach?></div>
 </section>
 <?php endforeach?>
 </div>
</div></div>
<script>
(()=>{const suppliers=<?=json_encode($supplierSearchData,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>,input=document.getElementById('supplierRegularizationSearch'),results=document.getElementById('supplierSearchResults'),clear=document.getElementById('supplierSearchClear'),wrap=document.getElementById('supplierSearchWrap');const norm=v=>String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''),esc=v=>String(v||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));function initials(s){return String(s.name||'?').split(/\s+/).slice(0,2).map(x=>x[0]||'').join('').toUpperCase()}function avatar(s){return s.visual?`<img src="${esc(s.visual)}" class="h-11 w-11 rounded-xl border border-slate-200 object-cover" onerror="this.outerHTML='<span class=&quot;flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-100 font-black text-indigo-700&quot;>${esc(initials(s))}</span>'">`:`<span class="flex h-11 w-11 items-center justify-center rounded-xl bg-indigo-100 font-black text-indigo-700">${esc(initials(s))}</span>`}function show(){const q=norm(input.value),items=suppliers.filter(s=>!q||norm([s.name,s.code,s.contact,s.phone,s.email,s.address].join(' ')).includes(q));results.innerHTML=items.length?items.map(s=>`<button type="button" data-supplier-id="${s.id}" class="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-left hover:bg-indigo-50">${avatar(s)}<span class="min-w-0 flex-1"><strong class="block truncate text-sm text-slate-900">${esc(s.name)}</strong><small class="block truncate text-xs text-slate-500">${esc([s.code,s.contact,s.phone].filter(Boolean).join(' · '))}</small></span><span class="rounded-full bg-rose-50 px-2 py-1 text-[10px] font-black text-rose-700">${s.count}</span></button>`).join(''):'<div class="p-4 text-center text-sm text-slate-500">Aucun fournisseur trouvé</div>';results.classList.remove('hidden');results.querySelectorAll('[data-supplier-id]').forEach(b=>b.onclick=()=>select(Number(b.dataset.supplierId)))}function select(id){const s=suppliers.find(x=>Number(x.id)===id);document.querySelectorAll('[data-supplier-card]').forEach(card=>card.classList.toggle('hidden',Number(card.dataset.supplierCard)!==id));input.value=s?s.name:'';clear.classList.remove('hidden');results.classList.add('hidden');document.querySelector(`[data-supplier-card="${id}"]`)?.scrollIntoView({behavior:'smooth',block:'start'})}input.addEventListener('focus',show);input.addEventListener('input',show);input.addEventListener('keydown',e=>{if(e.key==='Escape'){results.classList.add('hidden');input.blur()}});clear.onclick=()=>{input.value='';clear.classList.add('hidden');document.querySelectorAll('[data-supplier-card]').forEach(card=>card.classList.remove('hidden'));show();input.focus()};document.addEventListener('click',e=>{if(!wrap.contains(e.target))results.classList.add('hidden')})})();
</script>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>
