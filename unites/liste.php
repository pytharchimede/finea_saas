<?php
declare(strict_types=1);
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
require_once __DIR__.'/../model/UniteMesure.php';
require_once __DIR__.'/../model/AuditTrail.php';
AccessManager::requirePageAccess('liste_unite.php');
$q=$_GET['q']??'';$usage=$_GET['usage']??'';
if(!is_string($q)||!in_array($usage,['','used','unused'],true)){http_response_code(400);exit('Filtre invalide.');}
$q=trim($q);
AuditTrail::logPageConsultation(['module'=>'core','action'=>'liste_unite_view','entity_type'=>'unite_list','entity_label'=>'Liste des unites','page_name'=>'liste_unite.php','details'=>['q'=>$q,'usage'=>$usage]]);
$rows=(new UniteMesure(Database::getConnection()))->usageReport(['q'=>$q,'usage'=>$usage]);
$activeDevisModule='unites';$moduleTitle='Référentiel des unités';$moduleSubtitle='Rechercher les symboles et contrôler leur utilisation dans les devis.';
$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
ob_start();
?>
<div class="unit-workspace">
<section class="ed-panel"><div class="op-head"><h2>Recherche et filtres</h2><a class="op-link" href="ajouter.php"><i class="fa-solid fa-plus"></i> Ajouter une unité</a></div>
<form id="uniteSearchForm" method="get" class="unit-filter"><label>Symbole ou libellé<input name="q" value="<?=$e($q)?>" placeholder="Ex. m², mètre carré"></label><label>Utilisation<select name="usage"><?php foreach([''=>'Toutes','used'=>'Utilisées','unused'=>'Inutilisées'] as $key=>$label):?><option value="<?=$key?>" <?=$usage===$key?'selected':''?>><?=$label?></option><?php endforeach;?></select></label><button class="op-link" type="submit">Rechercher</button><a href="liste.php">Réinitialiser</a></form>
<div class="ed-export"><?php foreach(['pdf'=>'PDF','xlsx'=>'Excel'] as $format=>$label):?><a href="../exports/finea_module_export.php?<?=$e(http_build_query(['module'=>'unites','format'=>$format,'q'=>$q,'usage'=>$usage]))?>"><i class="fa-solid <?=$format==='pdf'?'fa-file-pdf':'fa-file-excel'?>"></i> Export <?=$label?></a><?php endforeach;?></div></section>
<section class="ed-panel unit-results"><div class="op-head"><h2>Unités disponibles</h2><span class="ed-pill"><?=count($rows)?> résultat(s)</span></div><?php if(($_GET['created']??'')==='1'):?><p role="status">Unité enregistrée avec succès.</p><?php endif;?>
<div class="unit-table-wrap"><table class="unit-table"><thead><tr><th>Symbole</th><th>Libellé</th><th>Lignes de devis</th><th>Utilisation</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $row):?><tr><td><span class="ed-pill"><?=$e($row['symbole'])?></span></td><td><?=$e($row['libelle'])?></td><td><?=(int)$row['usage_count']?></td><td><span class="ed-pill"><?=(int)$row['usage_count']>0?'Utilisée':'Inutilisée'?></span></td><td><div class="unit-row-actions"><button type="button" class="unit-info" data-symbol="<?=$e($row['symbole'])?>" data-label="<?=$e($row['libelle'])?>" data-description="<?=$e($row['description']??'')?>" data-count="<?=(int)$row['usage_count']?>" aria-haspopup="dialog" aria-label="Fiche technique" title="Fiche technique"><i class="fa-solid fa-circle-info"></i></button><a href="<?=$e(devis_module_root_url('unites/modifier.php'))?>?id=<?=(int)$row['id']?>" aria-label="Modifier" title="Modifier"><i class="fa-solid fa-pen-to-square"></i></a></div></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="5">Aucune unité correspondante.</td></tr><?php endif;?></tbody></table></div></section>
</div>
<dialog id="unitInfoDialog" class="unit-info-dialog" aria-labelledby="unitInfoTitle">
<header><div><small id="unitInfoCategory"></small><h2 id="unitInfoTitle"></h2></div><form method="dialog"><button type="submit" aria-label="Fermer">×</button></form></header>
<div class="unit-info-body"><p id="unitInfoDescription"></p><h3>Exemple d'utilisation</h3><p id="unitInfoExample"></p><p id="unitInfoUsage"></p>
<section id="unitConversion"><h3>Équivalences et conversion</h3><div class="unit-filter"><label>Quantité<input id="unitAmount" type="number" step="any" value="1"></label><label>Convertir vers<select id="unitTarget"></select></label></div><output id="unitResult" aria-live="polite"></output><div id="unitEquivalences"></div></section><p id="unitNoConversion">Aucune conversion universelle configurée. Les conditionnements dépendent notamment du produit et du fournisseur.</p></div></dialog>
<script src="<?= $e(devis_module_root_url('assets/js/unit-reference.js')) ?>?v=2" defer></script>
<?php $content=ob_get_clean();require __DIR__.'/../devis/includes/module_layout.php';
