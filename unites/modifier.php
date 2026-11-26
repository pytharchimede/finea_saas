<?php
declare(strict_types=1);
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
require_once __DIR__.'/../model/UniteMesure.php';
require_once __DIR__.'/../model/AuditTrail.php';
AccessManager::requirePageAccess('liste_unite.php');
$pdo=Database::getConnection();$model=new UniteMesure($pdo);
$id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$row=$id?$model->getById($id):false;
if(!$row){http_response_code(404);exit('Unité introuvable.');}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$_SESSION['unit_edit_csrf']??=bin2hex(random_bytes(32));$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!is_string($_POST['_csrf']??null)||!hash_equals($_SESSION['unit_edit_csrf'],$_POST['_csrf']))throw new DomainException('Session expirée.');
        foreach(['libelle','description'] as $key)if(!is_string($_POST[$key]??null))throw new DomainException('Champ invalide.');
        $label=trim($_POST['libelle']);$description=trim($_POST['description']);
        if($label===''||mb_strlen($label)>120||mb_strlen($description)>4000)throw new DomainException('Libellé ou description invalide.');
        $pdo->beginTransaction();
        $model->update($id,$row['symbole'],$label,$description);
        AuditTrail::log('unite_updated',['module'=>'unites','action'=>'update','entity_type'=>'unite_mesure','entity_label'=>$row['symbole']],$pdo);
        $pdo->commit();header('Location: '.devis_module_root_url('unites/liste.php').'?updated=1');exit;
    }catch(Throwable $ex){if($pdo->inTransaction())$pdo->rollBack();$error=$ex instanceof DomainException?$ex->getMessage():'Enregistrement impossible.';}
}
$activeDevisModule='unites';$moduleTitle='Modifier une unité';$moduleSubtitle='Personnaliser le libellé et la documentation technique.';ob_start();
?>
<?php if($error):?><div class="uf-error"><?=$e($error)?></div><?php endif;?>
<section class="uf-card"><div class="uf-head"><div><h2>Définition de l’unité</h2><p>Le symbole est conservé pour protéger les conversions et les devis existants.</p></div></div>
<form method="post" action="<?=$e(devis_module_root_url('unites/modifier.php'))?>?id=<?=$id?>"><input type="hidden" name="_csrf" value="<?=$e($_SESSION['unit_edit_csrf'])?>"><div class="uf-grid"><div class="uf-field"><label>Symbole</label><input value="<?=$e($row['symbole'])?>" readonly></div><div class="uf-field"><label for="unitLabel">Libellé *</label><input id="unitLabel" name="libelle" required maxlength="120" value="<?=$e(is_string($_POST['libelle']??null)?$_POST['libelle']:$row['libelle'])?>"></div></div><div class="uf-field unit-description-field"><label for="unitDescription">Description et applications</label><textarea id="unitDescription" name="description" rows="6" maxlength="4000"><?=$e(is_string($_POST['description']??null)?$_POST['description']:($row['description']??''))?></textarea></div><div class="uf-actions"><button type="submit">Enregistrer les modifications</button><a href="<?=$e(devis_module_root_url('unites/liste.php'))?>">Annuler</a></div></form></section>
<?php $content=ob_get_clean();require __DIR__.'/../devis/includes/module_layout.php';
