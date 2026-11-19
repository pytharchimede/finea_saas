<?php
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhCompanyProfileService.php';
require_once __DIR__.'/../model/AuditTrail.php';

$pdo=Database::getConnection();
$service=new RhCompanyProfileService($pdo);
$message=$error=null;
$actorId=(int)($_SESSION['user_id']??0);

/**
 * Prépare un répertoire d'upload RH exploitable par PHP/Apache.
 *
 * Les installations locales et certains hébergements cPanel ne créent pas
 * toujours uploads/rh à l'avance. On crée donc chaque niveau si nécessaire,
 * puis on applique des droits d'écriture. 0777 n'est utilisé qu'en dernier
 * recours lorsque 0775 ne suffit pas avec le propriétaire/groupe du serveur.
 */
function rhCompanyEnsureWritableDirectory(string $dir): void
{
    $oldUmask=umask(0002);
    try{
        if(!is_dir($dir)){
            if(!@mkdir($dir,0775,true)&&!is_dir($dir)){
                throw new RuntimeException('Impossible de créer le dossier des logos entreprise : '.$dir);
            }
        }

        @chmod($dir,0775);
        clearstatcache(true,$dir);

        if(!is_writable($dir)){
            @chmod($dir,0777);
            clearstatcache(true,$dir);
        }

        if(!is_writable($dir)){
            throw new RuntimeException('Le dossier des logos entreprise existe mais PHP ne peut pas y écrire. Vérifiez le propriétaire du dossier uploads/rh et les droits du serveur web.');
        }
    }finally{
        umask($oldUmask);
    }
}

function rhCompanyStoreLogo(array $file,int $companyId): string
{
    if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return'';
    if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK)throw new RuntimeException('Le téléversement du logo a échoué.');
    if((int)($file['size']??0)<=0||(int)$file['size']>5*1024*1024)throw new RuntimeException('Le logo doit peser moins de 5 Mo.');
    $tmp=(string)($file['tmp_name']??'');if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Fichier de logo invalide.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
    if(!isset($allowed[$mime]))throw new RuntimeException('Formats autorisés pour le logo : PNG, JPG ou WEBP.');

    $root=dirname(__DIR__);
    $rhDir=$root.'/uploads/rh';
    $dir=$rhDir.'/company-logos';
    rhCompanyEnsureWritableDirectory($rhDir);
    rhCompanyEnsureWritableDirectory($dir);

    $name='company_'.$companyId.'_'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.'.$allowed[$mime];
    $destination=$dir.'/'.$name;
    if(!move_uploaded_file($tmp,$destination))throw new RuntimeException('Impossible d’enregistrer le logo de l’entreprise.');
    @chmod($destination,0664);
    return'uploads/rh/company-logos/'.$name;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $existingId=max(0,(int)($_POST['id']??0));
        $existing=$existingId>0?$service->find($existingId):null;
        $_POST['logo_path']=trim((string)($existing['logo_path']??''));
        if(!empty($_POST['remove_logo']))$_POST['logo_path']='';
        $id=$service->save($_POST,$actorId);
        $newLogo=rhCompanyStoreLogo($_FILES['logo_file']??[],$id);
        if($newLogo!==''){
            $oldLogo=trim((string)($_POST['logo_path']??''));
            $_POST['id']=$id;$_POST['logo_path']=$newLogo;
            $service->save($_POST,$actorId);
            if($oldLogo!==''&&str_starts_with($oldLogo,'uploads/rh/company-logos/')){
                $oldAbsolute=dirname(__DIR__).'/'.ltrim($oldLogo,'/');if(is_file($oldAbsolute))@unlink($oldAbsolute);
            }
        }elseif(!empty($_POST['remove_logo'])&&$existing&&str_starts_with((string)($existing['logo_path']??''),'uploads/rh/company-logos/')){
            $oldAbsolute=dirname(__DIR__).'/'.ltrim((string)$existing['logo_path'],'/');if(is_file($oldAbsolute))@unlink($oldAbsolute);
        }
        AuditTrail::log('RH_COMPANY_PROFILE_SAVED',['module'=>'rh','action'=>'save_company_profile','user_id'=>$actorId,'entity_type'=>'rh_company_profile','entity_label'=>(string)$id,'details'=>['logo_uploaded'=>$newLogo!==''?'yes':'no']]);
        header('Location: parametrage_entreprises_rh.php?id='.$id.'&saved=1');exit;
    }catch(Throwable $e){$error=$e->getMessage();}
}

$companies=$service->all(false);
$editId=max(0,(int)($_GET['id']??0));
$editing=$editId>0?$service->find($editId):null;
if(isset($_GET['saved']))$message='Fiche entreprise enregistrée.';
$pageTitle='Fiches entreprises RH';$activeMenu='rh';
require_once __DIR__.'/../decaissement/includes/header.php';
require_once __DIR__.'/../decaissement/includes/navbar.php';
$v=static fn($key)=>htmlspecialchars((string)($editing[$key]??''),ENT_QUOTES,'UTF-8');
$currentLogo=trim((string)($editing['logo_path']??''));
$currentLogoUrl=$currentLogo!==''?'../'.ltrim($currentLogo,'/'):'';
?>
<div class="min-h-screen bg-slate-50 pb-12"><main class="container mx-auto px-4 py-8">
<header class="rounded-[34px] bg-gradient-to-br from-slate-950 via-indigo-950 to-cyan-900 p-8 text-white shadow-xl"><p class="text-xs font-black uppercase tracking-[.3em] text-cyan-200">Paramétrage RH</p><h1 class="mt-3 text-4xl font-black">Fiches entreprises</h1><p class="mt-3 max-w-3xl text-slate-200">Centralisez l'identité légale, le logo, le représentant et les informations bancaires de chaque entité pouvant porter un contrat salarié ou un document de paie.</p></header>
<?php if($message):?><div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 font-bold text-emerald-800"><?=htmlspecialchars($message)?></div><?php endif?>
<?php if($error):?><div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-4 font-bold text-rose-800"><?=htmlspecialchars($error)?></div><?php endif?>
<div class="mt-7 grid gap-6 xl:grid-cols-[.8fr_1.4fr]">
<section class="rounded-3xl bg-white p-6 shadow-sm"><div class="flex items-center justify-between"><h2 class="text-xl font-black">Entités enregistrées</h2><a href="parametrage_entreprises_rh.php" class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-bold text-white">Nouvelle</a></div><div class="mt-5 space-y-3"><?php foreach($companies as $c):?><a href="?id=<?=(int)$c['id']?>" class="block rounded-2xl border <?=!empty($c['is_default'])?'border-indigo-300 bg-indigo-50':'border-slate-200 bg-white'?> p-4 transition hover:border-indigo-300"><div class="flex items-start gap-3"><?php if(!empty($c['logo_path'])):?><img src="../<?=htmlspecialchars(ltrim((string)$c['logo_path'],'/'))?>" alt="" class="h-12 w-12 rounded-xl border bg-white object-contain p-1"><?php else:?><div class="grid h-12 w-12 place-items-center rounded-xl bg-slate-100 text-slate-400"><i class="fas fa-building"></i></div><?php endif?><div class="min-w-0 flex-1"><div class="flex items-start justify-between gap-3"><div><b class="text-slate-950"><?=htmlspecialchars((string)$c['legal_name'])?></b><p class="mt-1 text-sm text-slate-500"><?=htmlspecialchars(trim((string)$c['city'].' · '.(string)$c['country'],' ·'))?></p></div><?php if(!empty($c['is_default'])):?><span class="rounded-full bg-indigo-100 px-3 py-1 text-xs font-black text-indigo-700">Par défaut</span><?php endif?></div></div></div></a><?php endforeach?></div></section>
<section class="rounded-3xl bg-white p-7 shadow-sm"><p class="text-xs font-black uppercase tracking-[.2em] text-slate-400"><?=$editing?'Modifier l’entité':'Nouvelle entité'?></p><h2 class="mt-2 text-2xl font-black"><?=$editing?htmlspecialchars((string)$editing['legal_name']):'Fiche entreprise'?></h2>
<form method="post" enctype="multipart/form-data" class="mt-6 space-y-7"><input type="hidden" name="id" value="<?=(int)($editing['id']??0)?>">
<div><h3 class="font-black">Identité visuelle</h3><div class="mt-3"><label id="company-logo-dropzone" class="group relative flex min-h-[220px] cursor-pointer flex-col items-center justify-center overflow-hidden rounded-[28px] border-2 border-dashed border-slate-300 bg-gradient-to-br from-slate-50 to-white p-6 text-center transition hover:border-indigo-400 hover:bg-indigo-50/40"><input id="company-logo-input" type="file" name="logo_file" accept="image/png,image/jpeg,image/webp" class="sr-only"><div id="company-logo-empty" class="<?=$currentLogoUrl!==''?'hidden':''?>"><div class="mx-auto grid h-16 w-16 place-items-center rounded-2xl bg-indigo-100 text-2xl text-indigo-700"><i class="fas fa-cloud-arrow-up"></i></div><h4 class="mt-4 text-lg font-black text-slate-950">Déposez le logo ici</h4><p class="mt-2 text-sm text-slate-500">ou cliquez pour parcourir vos fichiers</p><span class="mt-3 inline-flex rounded-full bg-white px-3 py-1 text-xs font-bold text-slate-500 shadow-sm">PNG, JPG ou WEBP · 5 Mo max</span></div><div id="company-logo-preview-wrap" class="<?=$currentLogoUrl===''?'hidden':''?>"><img id="company-logo-preview" src="<?=htmlspecialchars($currentLogoUrl)?>" alt="Aperçu du logo" class="mx-auto max-h-32 max-w-[260px] object-contain"><p id="company-logo-name" class="mt-4 text-sm font-bold text-slate-700"><?=$currentLogoUrl!==''?'Logo actuel · déposez un nouveau fichier pour le remplacer':''?></p><p class="mt-1 text-xs text-slate-500">Cliquez ou déposez un autre fichier pour remplacer ce logo</p></div></label><?php if($currentLogoUrl!==''):?><label class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-rose-700"><input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300"> Supprimer le logo actuel</label><?php endif?></div></div>
<div><h3 class="font-black">Identité légale</h3><div class="mt-3 grid gap-4 md:grid-cols-2"><label class="text-sm font-semibold">Raison sociale<input required name="legal_name" value="<?=$v('legal_name')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Nom commercial<input name="trade_name" value="<?=$v('trade_name')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Forme juridique<input name="legal_form" value="<?=$v('legal_form')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Capital social<input name="share_capital" value="<?=$v('share_capital')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">RCCM<input name="rccm" value="<?=$v('rccm')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Compte contribuable<input name="taxpayer_account" value="<?=$v('taxpayer_account')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label></div></div>
<div><h3 class="font-black">Coordonnées</h3><div class="mt-3 grid gap-4 md:grid-cols-2"><label class="text-sm font-semibold md:col-span-2">Adresse<input name="address_line" value="<?=$v('address_line')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Boîte postale<input name="postal_box" value="<?=$v('postal_box')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Ville<input name="city" value="<?=$v('city')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Pays<input name="country" value="<?=$v('country')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Téléphone<input name="phone" value="<?=$v('phone')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">E-mail<input type="email" name="email" value="<?=$v('email')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Site web<input name="website" value="<?=$v('website')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label></div></div>
<div><h3 class="font-black">Représentation et banque</h3><div class="mt-3 grid gap-4 md:grid-cols-2"><label class="text-sm font-semibold">Représentant légal<input name="representative_name" value="<?=$v('representative_name')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Fonction du représentant<input name="representative_title" value="<?=$v('representative_title')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Banque<input name="bank_name" value="<?=$v('bank_name')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">Compte bancaire<input name="bank_account" value="<?=$v('bank_account')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">IBAN<input name="iban" value="<?=$v('iban')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label><label class="text-sm font-semibold">SWIFT / BIC<input name="swift_bic" value="<?=$v('swift_bic')?>" class="mt-2 w-full rounded-xl border border-slate-200 p-3"></label></div></div>
<div class="flex flex-wrap gap-5 rounded-2xl bg-slate-50 p-4"><label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_default" value="1" <?=!empty($editing['is_default'])?'checked':''?>> Entreprise par défaut</label><label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="active" value="1" <?=!$editing||!empty($editing['active'])?'checked':''?>> Active</label></div>
<button class="rounded-2xl bg-indigo-700 px-6 py-3 font-black text-white shadow-lg shadow-indigo-200">Enregistrer la fiche entreprise</button></form></section>
</div></main></div>
<script>
(()=>{const zone=document.getElementById('company-logo-dropzone'),input=document.getElementById('company-logo-input'),preview=document.getElementById('company-logo-preview'),wrap=document.getElementById('company-logo-preview-wrap'),empty=document.getElementById('company-logo-empty'),name=document.getElementById('company-logo-name');if(!zone||!input)return;const show=file=>{if(!file)return;if(!['image/png','image/jpeg','image/webp'].includes(file.type)){alert('Sélectionnez un fichier PNG, JPG ou WEBP.');input.value='';return;}if(file.size>5*1024*1024){alert('Le logo doit peser moins de 5 Mo.');input.value='';return;}const reader=new FileReader();reader.onload=e=>{preview.src=e.target.result;empty.classList.add('hidden');wrap.classList.remove('hidden');name.textContent=file.name+' · '+Math.max(1,Math.round(file.size/1024))+' Ko';};reader.readAsDataURL(file);};input.addEventListener('change',()=>show(input.files[0]));['dragenter','dragover'].forEach(evt=>zone.addEventListener(evt,e=>{e.preventDefault();zone.classList.add('border-indigo-500','bg-indigo-50');}));['dragleave','drop'].forEach(evt=>zone.addEventListener(evt,e=>{e.preventDefault();zone.classList.remove('border-indigo-500','bg-indigo-50');}));zone.addEventListener('drop',e=>{const files=e.dataTransfer.files;if(!files.length)return;const dt=new DataTransfer();dt.items.add(files[0]);input.files=dt.files;show(files[0]);});})();
</script>
<?php require_once __DIR__.'/../decaissement/includes/footer.php';?>