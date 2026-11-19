<?php
require_once __DIR__.'/../auth_check.php';\AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';require_once __DIR__.'/../vendor/autoload.php';require_once __DIR__.'/../model/FnePurchaseInvoiceImportManager.php';
$manager=new FnePurchaseInvoiceImportManager(Database::getConnection());$_SESSION['fne_purchase_import_csrf']??=bin2hex(random_bytes(24));$report=null;$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){try{if(!hash_equals((string)$_SESSION['fne_purchase_import_csrf'],(string)($_POST['csrf']??'')))throw new RuntimeException('Session expirée.');$report=$manager->importFile($_FILES['fne_file']??[],(int)($_SESSION['user_id']??0));}catch(Throwable $e){$error=$e->getMessage();}}
$rows=$manager->latest();
foreach($rows as &$row){
    $raw=json_decode((string)($row['raw_json']??''),true);
    $raw=is_array($raw)?$raw:[];
    $normalizedRaw=[];
    foreach($raw as$key=>$value){
        $normalizedKey=mb_strtolower(trim((string)$key),'UTF-8');
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$normalizedKey);
        $normalizedKey=trim((string)preg_replace('/[^a-z0-9]+/','_',strtolower($ascii!==false?$ascii:$normalizedKey)),'_');
        $normalizedRaw[$normalizedKey]=$value;
    }
    if(trim((string)($row['fne_reference']??''))===''){
        $number=trim((string)($normalizedRaw['reference']??''));
        if($number===''){
            foreach($normalizedRaw as$key=>$value){
                $candidate=trim((string)$value);
                if(str_contains($key,'reference')&&preg_match('/^[A-Z0-9]{8,40}$/i',$candidate)&&!str_contains($candidate,'-')){$number=$candidate;break;}
            }
        }
        $row['fne_reference']=$number;
    }
    $row['fne_created_at']=$row['fne_created_at']?:($normalizedRaw['cree_a']??null);
    $row['fne_updated_at']=$row['fne_updated_at']?:($normalizedRaw['mise_a_jour_a']??null);
    $row['supplier_name']=trim((string)($row['supplier_name']?:($normalizedRaw['entreprise']??'')));
    $row['supplier_ncc']=trim((string)($row['supplier_ncc']?:($normalizedRaw['ncc_de_l_entreprise']??'')));
    $row['client_name']=$row['supplier_name'];
    $row['client_ncc']=$row['supplier_ncc'];
}
unset($row);
$pageTitle='Import FNE des factures d’achat';$activeMenu='facturation';require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
?>
<main class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#ecfdf5,_transparent_38%),linear-gradient(180deg,_#f8fafc,_#eef2ff)] px-4 py-8"><div class="mx-auto max-w-7xl space-y-6">
<section class="rounded-[32px] bg-gradient-to-br from-slate-950 via-emerald-950 to-teal-900 p-7 text-white shadow-2xl"><div class="flex flex-wrap items-end justify-between gap-5"><div><p class="text-xs font-black uppercase tracking-[.25em] text-emerald-300"><i class="fas fa-cart-shopping mr-2"></i>FNE · factures fournisseurs</p><h1 class="mt-3 text-3xl font-black">Import des factures d’achat</h1><p class="mt-2 text-sm text-slate-300">Contrôle Token/Référence, ventilation HT/TVA, statut de paiement et rapprochement Finéa.</p></div><div class="flex gap-2"><a href="factures_achat.php#nouvelle-facture-achat" class="rounded-xl bg-white px-4 py-3 text-sm font-black text-slate-950"><i class="fas fa-link mr-2 text-emerald-600"></i>Rapprocher</a><a href="dashboard_facturation.php" class="rounded-xl bg-white/10 px-4 py-3 text-sm font-bold">Dashboard</a></div></div></section>
<?php if($error):?><div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800"><i class="fas fa-circle-xmark mr-2"></i><b>Échec :</b> <?=htmlspecialchars($error)?></div><?php endif?>
<?php if($report):?><section class="rounded-3xl border bg-white p-5 shadow-sm"><div class="flex items-center gap-3"><i class="fas fa-circle-check text-2xl text-emerald-600"></i><div><b>Lot traité</b><p class="text-xs text-slate-500"><?=htmlspecialchars((string)$report['batch'])?></p></div></div><div class="mt-4 grid gap-3 sm:grid-cols-5"><?php foreach(['read'=>'Lues','inserted'=>'Ajoutées','updated'=>'Actualisées','duplicates'=>'Doublons bloqués','linked'=>'Déjà rapprochées']as$key=>$label):?><div class="rounded-2xl bg-slate-50 p-4"><b class="text-2xl text-emerald-700"><?=(int)$report[$key]?></b><p class="text-xs text-slate-500"><?=htmlspecialchars($label)?></p></div><?php endforeach?></div><div class="mt-4 rounded-2xl bg-slate-950 p-4 font-mono text-xs text-emerald-300"><div><i class="fas fa-check mr-2"></i>Contrôle des identifiants terminé</div><div class="mt-2"><i class="fas fa-check mr-2"></i>Analyse TVA et rapprochement terminés</div><?php foreach($report['errors']as$log):?><div class="mt-2 text-rose-300"><i class="fas fa-xmark mr-2"></i><?=htmlspecialchars((string)$log)?></div><?php endforeach?></div></section><?php endif?>
<section class="rounded-3xl border bg-white p-6 shadow-sm"><form id="purchaseImportForm" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['fne_purchase_import_csrf'])?>"><input id="purchaseFile" class="sr-only" type="file" name="fne_file" accept=".csv,.xlsx,.xls" required><div id="purchaseDrop" tabindex="0" role="button" class="cursor-pointer rounded-[28px] border-2 border-dashed border-emerald-300 bg-emerald-50/60 p-9 text-center transition hover:border-emerald-600"><span class="mx-auto grid h-16 w-16 place-items-center rounded-3xl bg-emerald-600 text-2xl text-white shadow-lg"><i class="fas fa-cloud-arrow-up"></i></span><h2 class="mt-4 text-xl font-black">Déposez le fichier d’achats FNE</h2><p class="mt-1 text-sm text-slate-500">CSV, XLSX ou XLS · 20 Mo maximum</p></div><div id="purchasePreview" class="mt-4 hidden items-center justify-between rounded-2xl border border-emerald-200 bg-emerald-50 p-4"><div><i class="fas fa-file-excel mr-3 text-2xl text-emerald-600"></i><b id="purchaseName"></b><span id="purchaseSize" class="ml-2 text-xs text-slate-500"></span></div><button type="button" id="purchaseRemove" class="grid h-10 w-10 place-items-center rounded-full bg-rose-100 text-rose-700"><i class="fas fa-xmark"></i></button></div><button id="purchaseSubmit" class="mt-4 w-full rounded-2xl bg-emerald-600 px-6 py-4 font-black text-white"><i class="fas fa-file-import mr-2"></i>Contrôler et importer</button><div id="purchaseProgress" class="mt-4 hidden rounded-2xl bg-slate-950 p-4 text-white"><div class="flex justify-between text-sm"><span><i class="fas fa-spinner fa-spin mr-2"></i>Contrôle et rapprochement en cours…</span><b id="purchasePercent">12%</b></div><div class="mt-3 h-2 rounded-full bg-slate-700"><div id="purchaseBar" class="h-2 rounded-full bg-emerald-400 transition-all" style="width:12%"></div></div></div></form></section>
<section class="rounded-3xl border bg-white p-5 shadow-sm"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-black">Factures d’achat importées</h2><p class="text-sm text-slate-500"><?=count($rows)?> ligne(s), avec état TVA et rapprochement</p></div><input id="purchaseSearch" type="search" placeholder="Tout rechercher…" class="w-full max-w-md rounded-2xl border px-4 py-3"></div><div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3"><?php foreach($rows as$row):$linked=!empty($row['finea_invoice_id']);$search=mb_strtolower(implode(' ',array_map('strval',$row)));$importedCompany=(string)($row['client_name']?:$row['establishment']?:$row['seller_name']?:'');?><article data-search="<?=htmlspecialchars($search)?>" class="purchase-card overflow-hidden rounded-3xl border bg-white shadow-sm"><div class="h-1.5 <?=$linked?'bg-emerald-500':'bg-amber-400'?>"></div><div class="p-5"><div class="flex justify-between gap-2"><div class="min-w-0"><p class="text-[10px] font-black uppercase text-slate-400">Référence FNE</p><h3 class="truncate font-black"><?=htmlspecialchars((string)($row['fne_reference']?:'—'))?></h3></div><span class="h-fit rounded-full px-2 py-1 text-[10px] font-black <?=$linked?'bg-emerald-100 text-emerald-800':'bg-amber-100 text-amber-800'?>"><?=$linked?'LIÉE':'À RAPPROCHER'?></span></div><p class="mt-4 font-bold"><?=htmlspecialchars($importedCompany?:'Fournisseur à identifier')?></p><p class="text-xs text-slate-500">NCC <?=htmlspecialchars((string)($row['client_ncc']?:'—'))?> · <?=htmlspecialchars(substr((string)$row['invoice_date'],0,10))?></p><?php if(!empty($row['finea_supplier_id'])):?><div class="mt-3 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-900"><i class="fas fa-building-circle-check mr-2"></i>Fournisseur Finéa : <b><?=htmlspecialchars((string)$row['finea_party_name'])?></b></div><?php else:?><div data-fne-party-card data-context="purchase" data-import-id="<?=(int)$row['id']?>" data-query="<?=htmlspecialchars((string)($importedCompany?:$row['client_ncc']))?>" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3"><b class="text-xs text-amber-950">Fournisseur à identifier <span class="font-medium">(entreprise FNE : <?=htmlspecialchars($importedCompany?:'nom absent de l’import')?> · NCC : <?=htmlspecialchars((string)($row['client_ncc']?:'absent'))?>)</span></b><input data-party-search type="search" placeholder="Nom, NCC, téléphone ou email…" class="mt-2 w-full rounded-lg border bg-white px-3 py-2 text-xs"><div data-party-results class="mt-1 hidden max-h-44 overflow-auto rounded-lg border bg-white p-1"></div><div class="mt-2 flex gap-2"><button data-party-link type="button" disabled class="flex-1 rounded-lg bg-indigo-700 px-2 py-2 text-[11px] font-black text-white disabled:opacity-40">Associer</button><button data-party-create type="button" class="flex-1 rounded-lg bg-emerald-700 px-2 py-2 text-[11px] font-black text-white">Nouveau fournisseur</button></div><p data-party-status class="mt-2 text-[10px] text-amber-800">Toutes les factures du même NCC seront associées.</p></div><?php endif?><div class="mt-4 grid grid-cols-2 gap-2 text-xs"><div class="rounded-xl bg-slate-50 p-3">HT<b class="block"><?=number_format((float)$row['total_ht'],0,',',' ')?> F</b></div><div class="rounded-xl <?=$row['total_vat']>0?'bg-indigo-50 text-indigo-900':'bg-amber-50 text-amber-900'?> p-3">TVA<b class="block"><?=number_format((float)$row['total_vat'],0,',',' ')?> F</b></div><div class="col-span-2 rounded-xl bg-slate-950 p-3 text-white">Net à payer <b class="float-right"><?=number_format((float)$row['net_payable'],0,',',' ')?> F</b></div></div><div class="mt-3 rounded-xl border p-3 text-xs"><p><i class="fas fa-money-check mr-2"></i><?=htmlspecialchars((string)($row['payment_method']?:'Paiement non renseigné'))?></p><p class="mt-2"><i class="fas fa-helmet-safety mr-2"></i><?=htmlspecialchars($row['chantier_id']?trim((string)$row['chantier_code'].' · '.(string)$row['chantier_title']):'Chantier non lié')?></p></div><div class="mt-4"><?php if($linked):?><a href="edit_facture_achat.php?id=<?=(int)$row['finea_invoice_id']?>" class="block rounded-xl bg-slate-950 px-3 py-2 text-center text-xs font-black text-white"><i class="fas fa-eye mr-2"></i>Ouvrir dans Finéa</a><?php else:?><a href="factures_achat.php#nouvelle-facture-achat" class="block rounded-xl bg-emerald-600 px-3 py-2 text-center text-xs font-black text-white"><i class="fas fa-link mr-2"></i>Rapprocher</a><?php endif?></div></div></article><?php endforeach?></div></section>
<?php $linkedSupplierRows=array_values(array_filter($rows,static fn(array $r):bool=>!empty($r['finea_supplier_id'])));if($linkedSupplierRows):?>
<section class="rounded-3xl border border-indigo-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-black text-slate-950"><i class="fas fa-people-arrows mr-2 text-indigo-700"></i>Corriger une association fournisseur</h2><p class="mt-1 text-sm text-slate-500">Une correction est mémorisée par NCC et réaffecte toutes les factures correspondantes.</p><div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach($linkedSupplierRows as$row):$company=(string)($row['client_name']?:$row['establishment']?:$row['seller_name']?:'Nom absent');?>
<div class="rounded-2xl border p-4"><p class="text-xs text-slate-500">FNE : <?=htmlspecialchars($company)?> · NCC <?=htmlspecialchars((string)($row['client_ncc']?:'absent'))?></p><p class="mt-1 text-sm">Association actuelle : <b><?=htmlspecialchars((string)$row['finea_party_name'])?></b></p><details class="mt-3"><summary class="cursor-pointer text-xs font-black text-indigo-700"><i class="fas fa-pen mr-1"></i>Choisir un autre fournisseur</summary><div data-fne-party-card data-context="purchase" data-import-id="<?=(int)$row['id']?>" data-query="<?=htmlspecialchars($company)?>" class="mt-2 rounded-xl bg-indigo-50 p-3"><input data-party-search type="search" placeholder="Rechercher le bon fournisseur…" class="w-full rounded-lg border bg-white px-3 py-2 text-xs"><div data-party-results class="mt-1 hidden max-h-44 overflow-auto rounded-lg border bg-white p-1"></div><button data-party-link type="button" disabled class="mt-2 w-full rounded-lg bg-indigo-700 px-2 py-2 text-[11px] font-black text-white disabled:opacity-40">Remplacer l’association</button><button data-party-create type="button" class="hidden">Créer</button><p data-party-status class="mt-2 text-[10px] text-rose-700">Toutes les factures du même NCC seront corrigées après confirmation.</p></div></details></div>
<?php endforeach?></div></section>
<?php endif?>
</div></main>
<script>(()=>{const form=document.getElementById('purchaseImportForm'),file=document.getElementById('purchaseFile'),drop=document.getElementById('purchaseDrop'),preview=document.getElementById('purchasePreview');const show=()=>{const f=file.files?.[0];preview.classList.toggle('hidden',!f);preview.classList.toggle('flex',!!f);if(f){document.getElementById('purchaseName').textContent=f.name;document.getElementById('purchaseSize').textContent=(f.size/1048576).toLocaleString('fr-FR',{maximumFractionDigits:2})+' Mo'}};drop.onclick=()=>file.click();drop.onkeydown=e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();file.click()}};['dragover','dragenter'].forEach(t=>drop.addEventListener(t,e=>{e.preventDefault();drop.classList.add('ring-4','ring-emerald-100')}));['dragleave','drop'].forEach(t=>drop.addEventListener(t,e=>{e.preventDefault();drop.classList.remove('ring-4','ring-emerald-100')}));drop.addEventListener('drop',e=>{file.files=e.dataTransfer.files;show()});file.onchange=show;document.getElementById('purchaseRemove').onclick=()=>{file.value='';show()};form.onsubmit=()=>{document.getElementById('purchaseProgress').classList.remove('hidden');document.getElementById('purchaseSubmit').disabled=true;let p=12;setInterval(()=>{p=Math.min(94,p+11);document.getElementById('purchasePercent').textContent=p+'%';document.getElementById('purchaseBar').style.width=p+'%'},400)};document.getElementById('purchaseSearch').oninput=e=>{const q=e.target.value.toLocaleLowerCase('fr').trim();document.querySelectorAll('.purchase-card').forEach(c=>c.classList.toggle('hidden',q&&!c.dataset.search.includes(q)))}})();</script>
<script>
(() => {
    document.querySelectorAll('section').forEach(section => {
        const title = section.querySelector('h2');
        if (title?.textContent.trim().includes('Corriger une association fournisseur')) section.remove();
    });
    const invoiceDates = <?=json_encode(array_map(static fn(array $row):array => [
        'invoice_date' => $row['invoice_date'] ?? null,
        'fne_created_at' => $row['fne_created_at'] ?? null,
        'fne_updated_at' => $row['fne_updated_at'] ?? null,
    ], $rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)?>;
    const formatDate = value => {
        if (!value) return 'Non renseignée';
        const normalized = String(value).replace(' ', 'T');
        const parsed = new Date(normalized);
        if (Number.isNaN(parsed.getTime())) return String(value);
        const formatted = new Intl.DateTimeFormat('fr-FR', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        }).format(parsed);
        return formatted.charAt(0).toLocaleUpperCase('fr-FR') + formatted.slice(1);
    };
    const dateRow = (icon, color, label, value) =>
        `<p class="flex items-center justify-between gap-3"><span class="text-slate-500"><i class="${icon} mr-2 ${color}"></i>${label}</span><b class="text-right text-slate-900">${formatDate(value)}</b></p>`;

    document.querySelectorAll('.purchase-card').forEach((card, index) => {
        const data = invoiceDates[index] || {};
        const referenceLabel = [...card.querySelectorAll('p')].find(node => node.textContent.trim() === 'Référence FNE');
        if (referenceLabel) {
            referenceLabel.textContent = 'N° Facture';
            referenceLabel.classList.remove('text-slate-400');
            referenceLabel.classList.add('text-indigo-500', 'tracking-wide');
            const reference = referenceLabel.nextElementSibling;
            reference?.classList.add('text-lg', 'text-slate-950');
        }

        const nccLine = [...card.querySelectorAll('p')].find(node => node.textContent.trim().startsWith('NCC '));
        if (!nccLine) return;
        nccLine.textContent = nccLine.textContent.split(' · ')[0];
        const dates = document.createElement('div');
        dates.className = 'mt-3 grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs';
        dates.innerHTML =
            dateRow('far fa-calendar', 'text-indigo-500', 'Date de facture', data.invoice_date) +
            dateRow('far fa-clock', 'text-emerald-500', 'Créée sur FNE', data.fne_created_at) +
            dateRow('fas fa-rotate', 'text-amber-500', 'Mise à jour FNE', data.fne_updated_at);
        nccLine.insertAdjacentElement('afterend', dates);
    });
})();
</script>
<script src="assets/fne-party-linker.js"></script>
<?php require __DIR__.'/../decaissement/includes/footer.php';?>
