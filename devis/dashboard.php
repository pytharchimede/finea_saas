<?php
require_once __DIR__.'/includes/module_bootstrap.php';
AccessManager::requirePageAccess('dashboard.php');
require_once dirname(__DIR__).'/services/CommercialProjectFinanceMigrationService.php';
$dashboardPdo = Database::getConnection();
(new CommercialProjectFinanceMigrationService($dashboardPdo))->ensureSchema();
$quoteStats = $dashboardPdo->query("SELECT COUNT(*) total, SUM(NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL) AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))) active_quotes, SUM(EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL)) active_sites, SUM(NOT EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL) AND NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)) without_site, SUM(EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)) archived, COALESCE(SUM(CASE WHEN NOT EXISTS(SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL) THEN d.total_ttc ELSE 0 END),0) active_value FROM devis_banamur d WHERE COALESCE(d.masque,0)=0")->fetch(PDO::FETCH_ASSOC) ?: [];

$activeDevisModule='devis';
$moduleTitle='Pilotage des devis';
$moduleSubtitle='Rédiger, suivre et piloter les propositions commerciales et leur transformation en chantiers.';
$root=static fn(string $path):string=>devis_module_root_url($path);
ob_start();
?>
<section class="ed-kpis">
<?php foreach ([['Devis actifs',(int)($quoteStats['active_quotes']??0),'fa-file-circle-check'],['Avec chantier actif',(int)($quoteStats['active_sites']??0),'fa-helmet-safety'],['Sans chantier',(int)($quoteStats['without_site']??0),'fa-hourglass-half'],['Archivés',(int)($quoteStats['archived']??0),'fa-box-archive'],['Valeur active',number_format((float)($quoteStats['active_value']??0),0,',',' ').' FCFA','fa-chart-line']] as $metric): ?>
<article class="ed-kpi"><div class="ed-kpi-icon"><i class="fa-solid <?=$metric[2]?>"></i></div><span><?=$metric[0]?></span><strong><?=$metric[1]?></strong></article>
<?php endforeach;?>
</section>
<section class="ed-grid">
<article class="ed-panel"><h2>Accès rapides</h2><div class="ed-actions">
<?php foreach([['Nouveau devis','devis/generer.php','acces_generer_devis'],['Liste des devis','devis/liste.php','acces_devis']] as $i=>$action): if(!devis_module_has_access($action[2]))continue;?>
<a class="<?=$i===0?'primary':''?>" href="<?=htmlspecialchars($root($action[1]),ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars($action[0])?></a>
<?php endforeach;?>
</div></article>
<article class="ed-panel"><h2>Espaces de travail</h2><div class="ed-actions">
<?php foreach($devisModuleController->modules() as $key=>$item): if($key==='devis'||!devis_module_has_access($item['permission']??null))continue;?>
<a href="<?=htmlspecialchars($item['dashboard_href']??$item['href'],ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars($item['label'])?></a>
<?php endforeach;?>
</div></article>
</section>
<section class="ed-panel" style="margin-top:16px"><h2>Nombre de devis édités par jour</h2><div id="devis-daily-series" class="ed-bars" data-url="<?=htmlspecialchars($root('request/get_devis_data.php'),ENT_QUOTES,'UTF-8')?>">Chargement…</div></section>
<script src="<?=htmlspecialchars($root('assets/js/devis-daily-series.js'),ENT_QUOTES,'UTF-8')?>"></script>
<?php $content=ob_get_clean();require __DIR__.'/includes/module_layout.php';
