<?php
require_once __DIR__.'/_logistics_common.php';
require_once __DIR__.'/../repositories/LogisticsSupplierRepository.php';
require_once __DIR__.'/../services/SupplierReferenceService.php';
SupplierReferenceService::ensureLink($pdo,'logistics_suppliers');
$repository=new LogisticsSupplierRepository($pdo);
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$current=$id>0?$repository->find($id):[];
if($id>0&&!$current){http_response_code(404);exit('Fournisseur logistique introuvable.');}
$_SESSION['logi_supplier_identity_csrf']=$_SESSION['logi_supplier_identity_csrf']??bin2hex(random_bytes(24));
$error='';$values=$current??[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $values=array_replace($values,$_POST);
    try{
        if(!hash_equals((string)$_SESSION['logi_supplier_identity_csrf'],(string)($_POST['_csrf']??'')))throw new DomainException('Session expirée. Rechargez le formulaire.');
        $types=['carburant','garage','mecanicien','loueur','pieces','chauffeur_externe','transporteur','autre'];
        if(!in_array($_POST['supplier_type']??'', $types,true))throw new DomainException('Choisissez un type d’intervention.');
        $supplierId=$repository->save(['id'=>$id,'master_supplier_id'=>(int)($_POST['master_supplier_id']??0),'supplier_type'=>$_POST['supplier_type'],'status'=>($_POST['status']??'active')==='inactive'?'inactive':'active','notes'=>trim((string)($_POST['notes']??''))]);
        logi_audit($pdo,$id>0?'update_supplier':'create_supplier','logistics_supplier',$supplierId,'Fournisseur du référentiel commun',['master_supplier_id'=>(int)$_POST['master_supplier_id']]);
        logi_redirect('suppliers.php?ok=1');
    }catch(DomainException $e){$error=$e->getMessage();}
}
$options=[];foreach((new SupplierReferenceService($pdo))->options() as $supplier)$options[(int)$supplier['id_fournisseur']]=$supplier['code_fournisseur'].' · '.$supplier['supplier_display_name'].' · '.SupplierIdentity::summary($supplier)['label'];
logi_page_start($id>0?'Modifier un fournisseur':'Ajouter un fournisseur','Identité et pièces gérées dans le référentiel fournisseur commun.');
logi_card_open('Fournisseur / intervenant logistique','Sélectionnez une fiche active, validée et complète.');
?>
<?php if($error!==''): ?><p role="alert" class="mb-4 rounded-xl bg-red-50 p-4 text-red-800"><?= logi_h($error) ?></p><?php endif; ?>
<p class="mb-4">Pour une nouvelle entreprise ou personne physique, <a class="underline" href="../fournisseur/ajouter_fournisseur.php">créer une fiche fournisseur</a> puis revenir après validation. <a class="underline" href="../fournisseur/fournisseurs.php">Consulter ou modifier les identités et pièces</a>.</p>
<form method="post" class="grid md:grid-cols-3 gap-5">
<input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="_csrf" value="<?= logi_h($_SESSION['logi_supplier_identity_csrf']) ?>">
<label>Fiche fournisseur *<select name="master_supplier_id" required class="w-full rounded-xl border p-3"><option value="">Sélectionnez une fiche validée</option><?php foreach($options as $key=>$label): ?><option value="<?= $key ?>" <?= (int)($values['master_supplier_id']??0)===$key?'selected':'' ?>><?= logi_h($label) ?></option><?php endforeach; ?></select></label>
<?= logi_select('Type d’intervention','supplier_type',['carburant'=>'Carburant','garage'=>'Garage','mecanicien'=>'Mécanicien externe','loueur'=>'Loueur engin','pieces'=>'Pièces','chauffeur_externe'=>'Chauffeur externe','transporteur'=>'Transporteur','autre'=>'Autre'],(string)($values['supplier_type']??'autre')) ?>
<?= logi_select('Statut','status',['active'=>'Actif','inactive'=>'Inactif'],(string)($values['status']??'active')) ?>
<div class="md:col-span-3"><?= logi_textarea('Notes / conditions','notes',(string)($values['notes']??'')) ?></div>
<div class="md:col-span-3"><?= logi_submit('Enregistrer') ?></div>
</form>
<div class="mt-4"><a href="suppliers.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close();logi_page_end(); ?>
