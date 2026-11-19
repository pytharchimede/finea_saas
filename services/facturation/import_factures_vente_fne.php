<?php
require_once __DIR__.'/../auth_check.php';
\AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../model/FneSalesInvoiceImportManager.php';

$pdo=Database::getConnection();
$manager=new FneSalesInvoiceImportManager($pdo);
$_SESSION['fne_sales_import_csrf']??=bin2hex(random_bytes(24));
$report=null;$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!hash_equals((string)$_SESSION['fne_sales_import_csrf'],(string)($_POST['csrf']??''))) throw new RuntimeException('Session expirée. Rechargez la page.');
        $report=$manager->importFile($_FILES['fne_file']??[],(int)($_SESSION['user_id']??0));
    } catch(Throwable $e){$error=$e->getMessage();}
}
$rows=$manager->latest(300);
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
    if(trim((string)($row['client_name']??''))===''){
        $row['client_name']=trim((string)($normalizedRaw['nom_de_la_societe_du_client']??$normalizedRaw['nom_de_la_societe_client']??$row['finea_party_name']??''));
    }
}
unset($row);
$pageTitle='Import des factures de vente FNE';$activeMenu='facturation';
require __DIR__.'/../decaissement/includes/header.php';
require __DIR__.'/../decaissement/includes/navbar.php';
?>
<main class="min-h-screen bg-[radial-gradient(circle_at_top_left,_#eef2ff,_transparent_38%),linear-gradient(180deg,_#f8fafc,_#eef2ff)] px-4 py-8">
<div class="mx-auto max-w-7xl space-y-6">
    <section class="overflow-hidden rounded-[32px] bg-gradient-to-br from-slate-950 via-indigo-950 to-violet-900 p-7 text-white shadow-2xl">
        <div class="flex flex-wrap items-end justify-between gap-5">
            <div><p class="text-xs font-black uppercase tracking-[.25em] text-indigo-300"><i class="fas fa-file-invoice mr-2"></i>DGI Côte d’Ivoire · ventes</p><h1 class="mt-3 text-3xl font-black">Centre d’import FNE</h1><p class="mt-2 max-w-2xl text-sm text-slate-300">Importez le récapitulatif complet, contrôlez chaque ligne et rapprochez-la d’une facture et d’un chantier Finéa sans créer de doublon.</p></div>
            <div class="flex gap-2"><a href="factures_vente.php#nouvelle-facture" class="rounded-xl bg-white px-4 py-3 text-sm font-black text-slate-950"><i class="fas fa-link mr-2 text-indigo-600"></i>Rapprocher une facture</a><a href="dashboard_facturation.php" class="rounded-xl border border-white/20 bg-white/10 px-4 py-3 text-sm font-bold"><i class="fas fa-chart-pie mr-2"></i>Dashboard</a></div>
        </div>
    </section>

    <?php if($error):?><div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-rose-800"><i class="fas fa-circle-xmark mr-2"></i><b>Import refusé :</b> <?=htmlspecialchars($error)?></div><?php endif?>

    <?php if($report):?>
    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-2xl bg-emerald-100 text-emerald-700"><i class="fas fa-check"></i></span><div><h2 class="font-black text-slate-900">Traitement terminé</h2><p class="text-xs text-slate-500">Lot <?=htmlspecialchars((string)$report['batch'])?></p></div></div>
        <div class="mt-5 grid gap-3 sm:grid-cols-5"><?php foreach(['read'=>['Lues','fa-list-check'],'inserted'=>['Ajoutées','fa-circle-plus'],'updated'=>['Actualisées','fa-rotate'],'duplicates'=>['Doublons neutralisés','fa-shield-halved'],'linked'=>['Liées à Finéa','fa-link']] as $key=>$meta):?><div class="rounded-2xl border bg-slate-50 p-4"><i class="fas <?=$meta[1]?> text-indigo-600"></i><b class="ml-2 text-2xl text-slate-950"><?=(int)$report[$key]?></b><div class="mt-1 text-xs text-slate-500"><?=htmlspecialchars($meta[0])?></div></div><?php endforeach?></div>
        <div class="mt-4 rounded-2xl bg-slate-950 p-4 font-mono text-xs text-slate-200"><div class="text-emerald-400"><i class="fas fa-check-circle mr-2"></i>Lecture du fichier terminée.</div><div class="mt-2 text-emerald-400"><i class="fas fa-check-circle mr-2"></i>Contrôle Token + Référence terminé.</div><div class="mt-2 text-emerald-400"><i class="fas fa-check-circle mr-2"></i>Rapprochement Finéa terminé : <?=(int)$report['linked']?> liaison(s).</div><?php foreach($report['errors'] as $rowError):?><div class="mt-2 text-rose-300"><i class="fas fa-circle-xmark mr-2"></i><?=htmlspecialchars((string)$rowError)?></div><?php endforeach?></div>
    </section>
    <?php endif?>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
        <form id="fneImportForm" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['fne_sales_import_csrf'])?>">
            <input id="fneFile" type="file" name="fne_file" accept=".csv,.xlsx,.xls" required class="sr-only">
            <div id="fneDropzone" role="button" tabindex="0" class="group cursor-pointer rounded-[28px] border-2 border-dashed border-indigo-300 bg-gradient-to-br from-indigo-50 to-white p-8 text-center transition hover:border-indigo-500 hover:bg-indigo-50">
                <div class="mx-auto grid h-16 w-16 place-items-center rounded-3xl bg-indigo-600 text-2xl text-white shadow-lg shadow-indigo-200"><i class="fas fa-cloud-arrow-up"></i></div>
                <h2 class="mt-4 text-xl font-black text-slate-900">Déposez le récapitulatif FNE ici</h2><p class="mt-1 text-sm text-slate-500">ou cliquez pour parcourir · CSV, XLSX ou XLS · maximum 20 Mo</p>
            </div>
            <div id="fnePreview" class="mt-4 hidden items-center justify-between gap-4 rounded-2xl border border-indigo-200 bg-indigo-50 p-4">
                <div class="flex min-w-0 items-center gap-3"><span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-white text-xl text-emerald-600"><i class="fas fa-file-excel"></i></span><div class="min-w-0"><b id="fneFileName" class="block truncate text-slate-900"></b><span id="fneFileMeta" class="text-xs text-slate-500"></span></div></div>
                <button id="fneRemove" type="button" class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-rose-100 text-rose-700" title="Retirer le fichier"><i class="fas fa-xmark"></i></button>
            </div>
            <button id="fneSubmit" class="mt-4 w-full rounded-2xl bg-indigo-600 px-6 py-4 font-black text-white shadow-lg shadow-indigo-200 disabled:cursor-not-allowed disabled:opacity-50"><i class="fas fa-wand-magic-sparkles mr-2"></i>Contrôler et importer le lot</button>
            <div id="fneProgress" class="mt-4 hidden rounded-2xl bg-slate-950 p-4 text-sm text-white"><div class="flex items-center justify-between"><span id="fneProgressText"><i class="fas fa-spinner fa-spin mr-2 text-indigo-300"></i>Préparation du fichier…</span><b id="fneProgressValue">10%</b></div><div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-700"><div id="fneProgressBar" class="h-full rounded-full bg-gradient-to-r from-indigo-400 to-emerald-400 transition-all duration-500" style="width:10%"></div></div></div>
        </form>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-xl font-black text-slate-950">Catalogue des factures importées</h2><p class="text-sm text-slate-500"><?=count($rows)?> facture(s) affichée(s) · détail de la liaison Finéa et chantier</p></div><label class="relative w-full max-w-md"><i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i><input id="fneCardSearch" type="search" placeholder="Client, NCC, référence, montant, chantier…" class="w-full rounded-2xl border border-slate-300 py-3 pl-11 pr-4"></label></div>
        <div id="fneCards" class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        <?php foreach($rows as $row):$search=mb_strtolower(implode(' ',array_map('strval',$row)));$linked=!empty($row['finea_invoice_id']);?>
            <article data-search="<?=htmlspecialchars($search)?>" class="fne-card overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-xl">
                <div class="h-1.5 <?=$linked?'bg-emerald-500':'bg-amber-400'?>"></div>
                <div class="p-5">
                    <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-wider text-slate-400">N° Facture</p><h3 class="truncate font-black text-slate-950"><?=htmlspecialchars((string)($row['fne_reference']?:'Numéro à récupérer'))?></h3></div><span class="shrink-0 rounded-full px-3 py-1 text-[10px] font-black <?=$linked?'bg-emerald-100 text-emerald-800':'bg-amber-100 text-amber-800'?>"><i class="fas <?=$linked?'fa-link':'fa-link-slash'?> mr-1"></i><?=$linked?'LIÉE':'À RAPPROCHER'?></span></div>
                    <div class="mt-4"><p class="font-bold text-slate-800"><?=htmlspecialchars((string)($row['client_name']?:'Client non renseigné'))?></p><p class="mt-1 text-xs text-slate-500">NCC <?=htmlspecialchars((string)($row['client_ncc']?:'—'))?> · <?=htmlspecialchars(substr((string)$row['invoice_date'],0,10))?></p></div>
                    <?php if(!empty($row['finea_client_id'])):?><div class="mt-3 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-900"><i class="fas fa-user-check mr-2"></i>Client Finéa : <b><?=htmlspecialchars((string)$row['finea_party_name'])?></b></div><?php else:?><div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-950"><i class="fas fa-clock-rotate-left mr-2"></i>Identification automatique en attente pour le NCC <b><?=htmlspecialchars((string)($row['client_ncc']?:'non renseigné'))?></b>.</div><?php endif?>
                    <div class="mt-4 grid grid-cols-2 gap-2 text-xs"><div class="rounded-xl bg-slate-50 p-3"><span class="text-slate-400">HT</span><b class="mt-1 block"><?=number_format((float)$row['total_ht'],0,',',' ')?> F</b></div><div class="rounded-xl bg-indigo-50 p-3"><span class="text-indigo-500">TVA</span><b class="mt-1 block text-indigo-900"><?=number_format((float)$row['total_vat'],0,',',' ')?> F</b></div><div class="col-span-2 rounded-xl bg-slate-950 p-3 text-white"><span class="text-slate-400">Net à payer</span><b class="float-right text-base"><?=number_format((float)$row['net_payable'],0,',',' ')?> FCFA</b></div></div>
                    <div class="mt-4 rounded-xl border p-3 text-xs"><p><i class="fas fa-money-check-dollar mr-2 text-slate-400"></i><?=htmlspecialchars((string)($row['payment_method']?:'Mode non renseigné'))?></p><p class="mt-2"><i class="fas fa-helmet-safety mr-2 text-slate-400"></i><?php if($row['chantier_id']):?><b><?=htmlspecialchars(trim((string)$row['chantier_code'].' · '.(string)$row['chantier_title']))?></b><?php elseif($linked):?><span class="text-amber-700">Facture liée, chantier non renseigné</span><?php else:?><span class="text-slate-500">Chantier à sélectionner lors du rapprochement</span><?php endif?></p></div>
                    <div class="mt-4 flex gap-2"><?php if($linked):?><a href="visualiser_facture_vente.php?id=<?=(int)$row['finea_invoice_id']?>" target="_blank" class="flex-1 rounded-xl bg-slate-950 px-3 py-2 text-center text-xs font-black text-white"><i class="fas fa-eye mr-2"></i>Visualiser</a><a href="edit_facture_vente.php?id=<?=(int)$row['finea_invoice_id']?>" class="rounded-xl border px-3 py-2 text-xs font-black text-indigo-700"><i class="fas fa-pen"></i></a><?php else:?><a href="factures_vente.php#nouvelle-facture" class="flex-1 rounded-xl bg-indigo-600 px-3 py-2 text-center text-xs font-black text-white"><i class="fas fa-link mr-2"></i>Lier dans Finéa</a><?php endif?></div>
                </div>
            </article>
        <?php endforeach?>
        </div>
    </section>
</div>
</main>
<script>
(()=>{
 const form=document.getElementById('fneImportForm'),file=document.getElementById('fneFile'),zone=document.getElementById('fneDropzone'),preview=document.getElementById('fnePreview'),name=document.getElementById('fneFileName'),meta=document.getElementById('fneFileMeta'),remove=document.getElementById('fneRemove'),progress=document.getElementById('fneProgress'),bar=document.getElementById('fneProgressBar'),value=document.getElementById('fneProgressValue'),text=document.getElementById('fneProgressText');
 const show=()=>{const f=file.files?.[0];if(!f){preview.classList.add('hidden');preview.classList.remove('flex');return}name.textContent=f.name;meta.textContent=(f.size/1024/1024).toLocaleString('fr-FR',{maximumFractionDigits:2})+' Mo · '+(f.type||'fichier Excel/CSV');preview.classList.remove('hidden');preview.classList.add('flex')};
 zone.addEventListener('click',()=>file.click());zone.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();file.click()}});
 ['dragenter','dragover'].forEach(type=>zone.addEventListener(type,e=>{e.preventDefault();zone.classList.add('border-indigo-600','ring-4','ring-indigo-100')}));
 ['dragleave','drop'].forEach(type=>zone.addEventListener(type,e=>{e.preventDefault();zone.classList.remove('border-indigo-600','ring-4','ring-indigo-100')}));
 zone.addEventListener('drop',e=>{if(e.dataTransfer.files.length){file.files=e.dataTransfer.files;show()}});
 file.addEventListener('change',show);remove.addEventListener('click',()=>{file.value='';show()});
 form.addEventListener('submit',()=>{progress.classList.remove('hidden');document.getElementById('fneSubmit').disabled=true;let step=10;const labels={35:'Téléversement du récapitulatif…',60:'Contrôle des Token et Références…',82:'Recherche des liaisons Finéa…',94:'Enregistrement sécurisé…'};const timer=setInterval(()=>{step=Math.min(94,step+12);bar.style.width=step+'%';value.textContent=step+'%';if(labels[step])text.innerHTML='<i class="fas fa-spinner fa-spin mr-2 text-indigo-300"></i>'+labels[step];if(step>=94)clearInterval(timer)},350)});
 document.getElementById('fneCardSearch')?.addEventListener('input',e=>{const q=e.target.value.trim().toLocaleLowerCase('fr');document.querySelectorAll('.fne-card').forEach(card=>card.classList.toggle('hidden',q&&!card.dataset.search.includes(q)))});
})();
</script>
<script src="assets/fne-party-linker.js"></script>
<?php require __DIR__.'/../decaissement/includes/footer.php';?>
