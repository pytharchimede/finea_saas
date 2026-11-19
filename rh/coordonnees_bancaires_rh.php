<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../controllers/RhPersonnelController.php';
require_once __DIR__.'/../services/RhPersonnelBankService.php';
require_once __DIR__.'/../model/AuditTrail.php';

$pdo=Database::getConnection();
$directory=(new RhPersonnelController($pdo))->service();
$service=new RhPersonnelBankService($pdo);
$_SESSION['rh_bank_csrf']=$_SESSION['rh_bank_csrf']??bin2hex(random_bytes(24));
$selectedId=max(0,(int)($_GET['personnel_id']??$_POST['personnel_id']??0));
$message=$error=null;
$eligible=[];
try{
    $q=$pdo->query("SELECT id_personnel_soignant FROM personnel WHERE (date_sortie IS NULL OR date_sortie='0000-00-00') AND COALESCE(statut_pers_soignant_code,0) NOT IN (2,3) AND COALESCE(NULLIF(personnel_category,''),'interne')<>'prestataire'");
    foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$eligible[(int)$id]=true;
}catch(Throwable $e){$error='Impossible de charger le périmètre des salariés.';}
if($selectedId>0&&!isset($eligible[$selectedId])){$selectedId=0;$error='Les prestataires et consultants ne sont pas concernés par les coordonnées bancaires de paie salarié.';}
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals((string)$_SESSION['rh_bank_csrf'],(string)($_POST['_csrf']??'')))throw new RuntimeException('Session expirée.');
        if($selectedId<1||!isset($eligible[$selectedId]))throw new RuntimeException('Sélectionnez un salarié interne.');
        $service->save($_POST,(int)($_SESSION['user_id']??0));
        AuditTrail::log('RH_BANK_ACCOUNT_SAVED',['module'=>'rh','action'=>'bank_account_save','entity_type'=>'personnel','entity_id'=>(string)$selectedId,'user_id'=>(int)($_SESSION['user_id']??0),'user_name'=>(string)($_SESSION['user_name']??'')]);
        $message='Coordonnées bancaires enregistrées.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$choices=array_values(array_filter($directory->getPersonnelChoices('',3000),static fn(array $r):bool=>isset($eligible[(int)($r['id']??0)])));
$person=$selectedId>0?$directory->getPersonnelDetail($selectedId):null;
$bank=$selectedId>0?$service->find($selectedId):[];
$pageTitle='Coordonnées bancaires salariés';$activeMenu='rh';
require_once __DIR__.'/../decaissement/includes/header.php';
require_once __DIR__.'/../decaissement/includes/navbar.php';
?>
<div class="min-h-screen bg-slate-50 pb-12"><main class="mx-auto max-w-6xl px-4 py-8">
<header class="rounded-[32px] bg-gradient-to-r from-slate-950 via-indigo-950 to-cyan-900 p-8 text-white shadow-xl"><p class="text-xs font-black uppercase tracking-[.28em] text-amber-300">Ressources humaines · Paie</p><h1 class="mt-2 text-4xl font-black">Coordonnées bancaires des salariés</h1><p class="mt-3 max-w-3xl text-slate-200">Ces données alimentent le contrôle de paie et l’ordre de virement. Les prestataires et consultants restent gérés dans leur circuit de sous-traitance.</p></header>
<?php if($message):?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800"><?=htmlspecialchars($message)?></div><?php endif?><?php if($error):?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><?=htmlspecialchars($error)?></div><?php endif?>
<section class="mt-6 rounded-3xl bg-white p-6 shadow-sm"><form method="get" class="grid gap-3 md:grid-cols-[1fr_auto]"><select name="personnel_id" class="bank-search-select w-full" data-placeholder="Rechercher par nom, matricule ou service"><option value=""></option><?php foreach($choices as $r):?><option value="<?=(int)$r['id']?>" <?=$selectedId===(int)$r['id']?'selected':''?>><?=htmlspecialchars((string)$r['full_name'].' · '.($r['service_label']??''))?></option><?php endforeach?></select><button class="rounded-2xl bg-slate-950 px-6 py-3 font-bold text-white">Ouvrir</button></form></section>
<?php if($person):?><section class="mt-6 rounded-3xl bg-white p-7 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-black uppercase tracking-[.2em] text-indigo-600">Dossier salarié</p><h2 class="mt-2 text-2xl font-black"><?=htmlspecialchars((string)$person['full_name'])?></h2><p class="text-sm text-slate-500"><?=htmlspecialchars((string)($person['matricule']??''))?> · <?=htmlspecialchars((string)($person['fonction_label']??''))?></p></div><a href="validation_paie_rh.php" class="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 font-bold text-indigo-700">Retour à la paie</a></div>
<form method="post" class="mt-7 grid gap-5 md:grid-cols-2"><input type="hidden" name="_csrf" value="<?=htmlspecialchars($_SESSION['rh_bank_csrf'])?>"><input type="hidden" name="personnel_id" value="<?=$selectedId?>">
<label class="font-bold text-slate-700">Banque<input required name="bank_name" value="<?=htmlspecialchars((string)($bank['bank_name']??''))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3"></label>
<label class="font-bold text-slate-700">Titulaire du compte<input name="account_holder" value="<?=htmlspecialchars((string)($bank['account_holder']??$person['full_name']))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3"></label>
<label class="font-bold text-slate-700">Numéro de compte<input name="account_number" value="<?=htmlspecialchars((string)($bank['account_number']??''))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3"></label>
<label class="font-bold text-slate-700">IBAN<input name="iban" value="<?=htmlspecialchars((string)($bank['iban']??''))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3 uppercase"></label>
<label class="font-bold text-slate-700">SWIFT / BIC<input name="swift_bic" value="<?=htmlspecialchars((string)($bank['swift_bic']??''))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3 uppercase"></label>
<label class="font-bold text-slate-700">Référence de paiement<input name="payment_reference" value="<?=htmlspecialchars((string)($bank['payment_reference']??''))?>" class="mt-2 w-full rounded-2xl border border-slate-200 p-3"></label>
<label class="md:col-span-2 flex items-center gap-3 rounded-2xl bg-slate-50 p-4"><input type="checkbox" name="active" value="1" <?=!isset($bank['active'])||!empty($bank['active'])?'checked':''?>><span class="font-bold">Compte actif pour les virements de salaire</span></label>
<div class="md:col-span-2 flex justify-end"><button class="rounded-2xl bg-indigo-700 px-6 py-3 font-black text-white">Enregistrer les coordonnées bancaires</button></div></form></section><?php endif?>
</main></div>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"><script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script><script>document.addEventListener('DOMContentLoaded',()=>{if(window.jQuery?.fn?.select2)window.jQuery('.bank-search-select').select2({width:'100%',placeholder:'Rechercher un salarié',allowClear:true})});</script>
<?php require_once __DIR__.'/../decaissement/includes/footer.php';?>
