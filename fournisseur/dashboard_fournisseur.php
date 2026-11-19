<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/SupplierExceptionPolicy.php';
if (!SupplierExceptionPolicy::allowed()) AccessManager::requirePageAccess();
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../repositories/FournisseurRepository.php';
$pdo=Database::getConnection();$model=new FournisseurRepository($pdo);
$canFinance=SupplierExceptionPolicy::allowed()||AccessManager::hasPermission('acces_compta')||AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_point_financier');
if(AccessManager::hasPermission('acces_employe')&&!$canFinance&&!AccessManager::hasPermission('acces_fournisseur')){header('Location: ../employe/mes_fournisseurs_employe.php');exit;}
$canManage=SupplierExceptionPolicy::allowed()||AccessManager::hasPermission('acces_compta')||AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_fournisseur');
$_SESSION['supplier_archive_csrf']=$_SESSION['supplier_archive_csrf']??bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$canManage || (int)($_SESSION['user_id']??0)<=0) { http_response_code(403); exit('Gestion des fournisseurs non autorisée.'); }
    if (!is_string($_POST['_csrf']??null) || !hash_equals($_SESSION['supplier_archive_csrf'],$_POST['_csrf'])) { http_response_code(403); exit('Session expirée. Rechargez la page.'); }
    $result=$model->bulkChangeState(is_array($_POST['supplier_ids']??null)?$_POST['supplier_ids']:[],(string)($_POST['bulk_action']??''),(int)$_SESSION['user_id']);
    $labels=['archive'=>'archivé(s)','restore'=>'restauré(s)','activate'=>'activé(s)','deactivate'=>'désactivé(s)'];
    $message=!empty($result['ok']) ? (int)$result['changed'].' fournisseur(s) '.($labels[$_POST['bulk_action']]??'modifié(s)').' sur '.(int)$result['selected'].' sélectionné(s). Les autres sont déjà dans cet état ou non concernés.' : ($result['error']??'Action impossible.');
    $_SESSION['supplier_bulk_flash']=['ok'=>!empty($result['ok']),'message'=>$message];
    $return=[];
    foreach (['q','quality','supplier_type','archive','page'] as $key) if (isset($_GET[$key]) && is_scalar($_GET[$key])) $return[$key]=(string)$_GET[$key];
    header('Location: dashboard_fournisseur.php'.($return?'?'.http_build_query($return):'')); exit;
}
$flash=$_SESSION['supplier_bulk_flash']??null; unset($_SESSION['supplier_bulk_flash']);
$archive=(string)($_GET['archive']??'active'); if(!in_array($archive,['active','archived','all'],true))$archive='active';
$all=$model->directoryRows($archive);$q=trim((string)($_GET['q']??''));$quality=(string)($_GET['quality']??'');$type=(string)($_GET['supplier_type']??'');
$incomplete=count(array_filter($all,fn($r)=>empty($r['document_is_complete'])));
$verified=count(array_filter($all,fn($r)=>SupplierIdentity::summary($r)['verified']));
$exceptions=count(array_filter($all,fn($r)=>SupplierIdentity::summary($r)['exception']));
$rows=array_values(array_filter($all,static function($r)use($q,$quality,$type){
 $normalized=preg_replace('/[\s.\-]+/u','',$q);
 $identifiers=implode(' ',array_map('strval',array_intersect_key($r,array_flip(['ncc','siret','siren','vat_number','registration_number']))));
 $identifierMatch=$normalized!==''&&mb_stripos(preg_replace('/[\s.\-]+/u','',$identifiers),$normalized)!==false;
 return ($q===''||$identifierMatch||mb_stripos(implode(' ',array_map('strval',array_intersect_key($r,array_flip(['nom_commercial','raison_sociale','code_fournisseur','ncc','siret','siren','vat_number','registration_number','registration_type','fiscal_country','telephone','contact_nom','email','ville'])))),$q)!==false)
 &&($quality!=='incomplete'||empty($r['document_is_complete']))&&($quality!=='complete'||!empty($r['document_is_complete']))&&($quality!=='verified'||SupplierIdentity::summary($r)['verified'])&&($quality!=='exceptions'||SupplierIdentity::summary($r)['exception'])&&($type===''||$r['supplier_type']===$type);
}));$total=count($rows);$pages=max(1,(int)ceil($total/24));$page=max(1,min($pages,(int)($_GET['page']??1)));$rows=array_slice($rows,($page-1)*24,24);
$pageTitle='Répertoire fournisseurs';$activeMenu='fournisseur';$layoutRootBase='../';$layoutModuleBase='../decaissement/';
require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<link rel="stylesheet" href="../assets/css/supplier-directory.css?v=2">
<main class="supplier-directory">
<?php supplierWorkspaceHeader('Vos fournisseurs, tout simplement.', 'Retrouvez vos partenaires, vérifiez leurs dossiers et accédez à leur activité.', 0); ?>

<?php if($flash):?><div role="status" class="directory-empty"><?=$h($flash['message'])?></div><?php endif;?>
<section class="directory-metrics"><a href="dashboard_fournisseur.php"><span>Fournisseurs</span><strong><?=count($all)?></strong><small>Dans votre répertoire</small></a><a href="?quality=incomplete" class="metric-warning"><span>À normaliser</span><strong><?=$incomplete?></strong><small>Identité, coordonnées ou pièces manquantes</small></a><a href="?quality=complete"><span>Dossiers complets</span><strong><?=count($all)-$incomplete?></strong><small>Informations et documents réunis</small></a><a href="?quality=verified"><span>Identités contrôlées</span><strong><?=$verified?></strong><small>FNE, registre ou justificatifs validés</small></a><a href="?quality=exceptions"><span>Dérogations</span><strong><?=$exceptions?></strong><small>Autorisations internes distinctes des vérifications</small></a></section>
<section class="directory-listing"><div class="directory-section-title"><div><h2>Répertoire fournisseurs</h2><p><?=$total?> résultat(s) · consultez une fiche ou normalisez son dossier</p></div><a href="formes_juridiques.php">Formes juridiques ↗</a></div>
<form method="get" class="directory-filters"><label class="directory-search"><span>Rechercher un partenaire</span><input type="search" name="q" value="<?=$h($q)?>" placeholder="Nom, NCC, SIRET, TVA, CIK, identifiant local…"></label><label><span>Type de fournisseur</span><select name="supplier_type"><option value="">Tous les profils</option value="morale" <?=$type==='morale'?'selected':''?>>Personne morale</option><option value="physique" <?=$type==='physique'?'selected':''?>>Personne physique</option></select></label><label><span>Qualité de la fiche</span><select name="quality"><option value="">Tous les dossiers</option><option value="incomplete" <?=$quality==='incomplete'?'selected':''?>>À normaliser</option><option value="complete" <?=$quality==='complete'?'selected':''?>>Complets</option><option value="verified" <?=$quality==='verified'?'selected':''?>>Identité contrôlée</option><option value="exceptions" <?=$quality==='exceptions'?'selected':''?>>Fiches dérogatoires</option></select></label><label><span>État du répertoire</span><select name="archive"><?php foreach(['active'=>'Non archivés','archived'=>'Archivés','all'=>'Tous'] as $value=>$label):?><option value="<?=$value?>" <?=$archive===$value?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="directory-primary">Filtrer</button></form>
<?php if(!$model->isReady()):?><p role="alert"><?=$h($model->getInitError())?></p><?php endif;?>
<?php if($canManage):?>
<form id="supplierBulkForm" method="post" class="directory-filters" onsubmit="return confirmSupplierBulk(this);">
<input type="hidden" name="_csrf" value="<?=$h($_SESSION['supplier_archive_csrf'])?>">
<label><span>Sélection de cette page</span><span><input type="checkbox" id="supplierSelectAll"> Tout sélectionner (<?=count($rows)?>)</span></label>
<label><span id="supplierSelectedCount" aria-live="polite">0 sélectionné(s)</span><select name="bulk_action" required><option value="">Choisir une action</option value="archive">Archiver</option><option value="restore">Restaurer les archives</option><option value="activate">Activer</option><option value="deactivate">Désactiver</option></select></label>
<button type="submit" class="directory-primary" id="supplierBulkApply" disabled>Appliquer à la sélection</button>
<p>La sélection concerne uniquement cette page. Désactiver empêche les nouvelles utilisations ; archiver retire aussi du répertoire courant. Restaurer conserve l’état actif antérieur.</p>
</form>
<?php endif;?>
<div class="supplier-card-grid">
<?php foreach($rows as $r):$identity=SupplierIdentity::summary($r);$mayEdit=!$identity['exception']||SupplierExceptionPolicy::allowed();$id=(int)$r['id_fournisseur'];$name=$r['supplier_display_name']?:'Fournisseur #'.$id;$complete=!empty($r['document_is_complete']);$missing=$r['missing_document_labels']??[];$status=['approved'=>'Validé','pending'=>'En attente','rejected'=>'Rejeté'][$r['validation_status']]??'En attente';?>
<article class="supplier-card">
<?php if($canManage && $mayEdit):?><label style="display:flex;align-items:center;gap:8px;margin-bottom:12px"><input type="checkbox" class="supplier-selection" form="supplierBulkForm" name="supplier_ids[]" value="<?=$id?>" aria-label="Sélectionner <?=$h($name)?>"> Sélectionner</label><?php endif;?>
<p class="supplier-reference"><?=!empty($r['archived_at'])?'Archivé':(!empty($r['actif'])?'Actif':'Désactivé')?></p>
<div class="supplier-card-top"><div class="supplier-avatar"><?=$h(mb_strtoupper(mb_substr($name,0,2)))?></div><div><span class="supplier-reference"><?=$h($r['code_fournisseur']?:'FOU #'.$id)?></span><span class="supplier-kind"><?=$h($r['supplier_type_label'])?></span></div><span class="supplier-state <?=$complete?'is-complete':'is-incomplete'?>"><?=$identity['exception']?'Dérogatoire':($complete?'Complet':'À normaliser')?></span></div>
<h3><a href="details_fournisseur.php?id=<?=$id?>"><?=$h($name)?></a></h3><p class="supplier-legal"><?=$h($r['raison_sociale'])?> · <?=$h($status)?></p>
<div class="supplier-fiscal"><i class="fas <?=$identity['verified']?'fa-shield-check':'fa-id-card'?>"></i><span><?=$h($identity['label'])?> <?=$h($identity['value']?:'non renseigné')?></span><small><?=$h($identity['status'])?></small></div>
<dl class="supplier-contact"><div><dt><i class="fas fa-phone"></i> Téléphone</dt><dd><?=$h($r['telephone']?:'À renseigner')?></dd></div><div><dt><i class="fas fa-location-dot"></i> Localisation</dt><dd><?=$h($r['ville']?:$r['adresse']?:'À renseigner')?></dd></div></dl>
<div class="supplier-completion"><div><span>Complétude du dossier</span><strong><?=(int)$r['document_completion_percent']?> %</strong></div><progress max="100" value="<?=(int)$r['document_completion_percent']?>"><?=(int)$r['document_completion_percent']?> %</progress><p title="<?=$h(implode(', ',$missing))?>"><?=$identity['exception']?$h($identity['status']):($complete?'Votre dossier est complet.':$h(implode(' · ',array_slice($missing,0,2))).(count($missing)>2?' · +'.(count($missing)-2):''))?></p></div>
<div class="supplier-card-actions"><?php if($mayEdit):?><a class="<?=$complete?'directory-secondary':'directory-normalize'?>" href="edit_fournisseur.php?id=<?=$id?>"><?=$complete?'Modifier la fiche':'Normaliser la fiche'?> <i class="fas fa-arrow-right"></i></a><?php endif;?><a class="directory-icon-link" href="details_fournisseur.php?id=<?=$id?>" aria-label="Ouvrir la fiche de <?=$h($name)?>"><i class="fas fa-arrow-up-right-from-square"></i></a></div>
<?php if($canManage && $mayEdit):?><form method="post" onsubmit="return confirm('Confirmer cette action sur le fournisseur ? Son historique sera conservé.');" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
<input type="hidden" name="_csrf" value="<?=$h($_SESSION['supplier_archive_csrf'])?>"><input type="hidden" name="supplier_ids[]" value="<?=$id?>">
<?php if(!empty($r['archived_at'])):?><button name="bulk_action" value="restore" class="directory-secondary">Restaurer</button><?php else:?><button name="bulk_action" value="archive" class="directory-secondary">Archiver</button><button name="bulk_action" value="<?=!empty($r['actif'])?'deactivate':'activate'?>" class="directory-secondary"><?=!empty($r['actif'])?'Désactiver':'Activer'?></button><?php endif;?></form><?php endif;?>
<footer><a href="details_fournisseur.php?id=<?=$id?>#supplier-map">GPS</a><a href="edit_fournisseur.php?id=<?=$id?>#incidents">Incidents</a><?php if($canFinance):?><a href="decaissements.php?id=<?=$id?>">Décaissements</a><?php endif;?></footer>
</article><?php endforeach;?></div>
<?php if(!$rows):?><div class="directory-empty"><i class="fas fa-magnifying-glass"></i><h3>Aucun fournisseur trouvé</h3><p>Modifiez vos filtres ou ajoutez un nouveau partenaire.</p><a href="dashboard_fournisseur.php">Réinitialiser les filtres</a></div><?php endif;?>
<?php if($pages>1):?><nav class="directory-pagination" aria-label="Pagination"><?php for($i=1;$i<=$pages;$i++):?><a <?=$i===$page?'aria-current="page"':''?> href="?<?=$h(http_build_query(['q'=>$q,'quality'=>$quality,'supplier_type'=>$type,'archive'=>$archive,'page'=>$i]))?>"><?=$i?></a><?php endfor;?></nav><?php endif;?></section>
</main>
<script>
const supplierBoxes=[...document.querySelectorAll('.supplier-selection')];
const supplierSelectAll=document.getElementById('supplierSelectAll');
function updateSupplierSelection(){
 const n=supplierBoxes.filter(x=>x.checked).length;
 const count=document.getElementById('supplierSelectedCount'),apply=document.getElementById('supplierBulkApply');
 if(count)count.textContent=n+' sélectionné(s)'; if(apply)apply.disabled=n===0;
 if(supplierSelectAll){supplierSelectAll.checked=n>0&&n===supplierBoxes.length;supplierSelectAll.indeterminate=n>0&&n<supplierBoxes.length;}
}
if(supplierSelectAll)supplierSelectAll.addEventListener('change',()=>{supplierBoxes.forEach(x=>x.checked=supplierSelectAll.checked);updateSupplierSelection();});
supplierBoxes.forEach(x=>x.addEventListener('change',updateSupplierSelection));
window.addEventListener('pageshow',updateSupplierSelection);
function confirmSupplierBulk(form){const n=supplierBoxes.filter(x=>x.checked).length;if(!n)return false;const action=form.elements.bulk_action;return confirm(action.selectedOptions[0].textContent+' : '+n+' fournisseur(s) sélectionné(s) ? L’historique sera conservé.');}
updateSupplierSelection();
</script>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>


