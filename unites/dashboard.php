<?php
declare(strict_types=1);
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
require_once __DIR__.'/../model/UniteMesure.php';
AccessManager::requirePageAccess('liste_unite.php');
$rows=(new UniteMesure(Database::getConnection()))->usageReport();
$total=count($rows);
$used=count(array_filter($rows,static fn($r)=>(int)$r['usage_count']>0));
$lines=array_sum(array_column($rows,'usage_count'));
$top=$rows;usort($top,static fn($a,$b)=>(int)$b['usage_count']<=>(int)$a['usage_count']);
$top=array_slice(array_filter($top,static fn($r)=>(int)$r['usage_count']>0),0,8);
$recent=$rows;usort($recent,static fn($a,$b)=>(int)$b['id']<=>(int)$a['id']);$recent=array_slice($recent,0,6);
$activeDevisModule='unites';$moduleTitle='Pilotage des unités de mesure';$moduleSubtitle='Administrer le référentiel et suivre son utilisation dans les lignes de devis.';
$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
ob_start();
?>
<div class="unit-workspace">
<section class="ed-kpis">
<?php foreach([['Unités disponibles',$total,'fa-ruler-combined'],['Unités utilisées',$used,'fa-circle-check'],['Lignes de devis',$lines,'fa-list-check'],['Unités inutilisées',$total-$used,'fa-circle-info']] as $k):?>
<article class="ed-kpi"><div class="ed-kpi-icon"><i class="fa-solid <?=$k[2]?>"></i></div><span><?=$e($k[0])?></span><strong><?=number_format($k[1],0,',',' ')?></strong></article><?php endforeach;?>
</section>
<section class="ed-grid"><article class="ed-panel"><h2>Accès rapides</h2><p>Créer, consulter et contrôler le référentiel.</p><div class="ed-actions"><a class="primary" href="ajouter.php"><i class="fa-solid fa-plus"></i> Nouvelle unité</a><a href="liste.php"><i class="fa-solid fa-list"></i> Toutes les unités</a><a href="liste.php?usage=unused"><i class="fa-solid fa-filter"></i> Unités inutilisées</a><a href="parametrage.php"><i class="fa-solid fa-sliders"></i> Paramétrage</a></div><div class="ed-export"><a href="../exports/finea_module_export.php?module=unites&amp;format=pdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</a><a href="../exports/finea_module_export.php?module=unites&amp;format=xlsx"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></article>
<article class="ed-panel"><h2>Utilisation du référentiel</h2><p>Unités présentes dans au moins une ligne de devis, historique compris.</p><div class="unit-rate"><strong><?=$total?round($used/$total*100):0?> %</strong><div class="ed-track"><div class="ed-fill" style="width:<?=$total?$used/$total*100:0?>%"></div></div></div><div class="ed-note"><?=$used?> unité(s) utilisée(s) sur <?=$total?>. Une unité inutilisée ne doit pas être supprimée automatiquement.</div></article></section>
<section class="ed-grid"><article class="ed-panel"><h2>Unités les plus utilisées</h2><p>Nombre de lignes de devis rattachées.</p><div class="ed-bars"><?php foreach($top as $row):?><div><div class="ed-bar-head"><span><?=$e($row['libelle'])?> (<?=$e($row['symbole'])?>)</span><strong><?=(int)$row['usage_count']?></strong></div><div class="ed-track"><div class="ed-fill" style="width:<?=100*(int)$row['usage_count']/max(1,(int)$top[0]['usage_count'])?>%"></div></div></div><?php endforeach;?><?php if(!$top):?><p>Aucune utilisation enregistrée.</p><?php endif;?></div></article><article class="ed-panel"><h2>Dernières unités ajoutées</h2><p>Ordre décroissant des identifiants.</p><div class="ed-list"><?php foreach($recent as $row):?><div class="ed-list-item"><div><strong><?=$e($row['libelle'])?></strong><small><?=(int)$row['usage_count']?> ligne(s) de devis</small></div><span class="ed-pill"><?=$e($row['symbole'])?></span></div><?php endforeach;?><?php if(!$recent):?><p>Aucune unité enregistrée.</p><?php endif;?></div></article></section>
</div>
<?php $content=ob_get_clean();require __DIR__.'/../devis/includes/module_layout.php';
