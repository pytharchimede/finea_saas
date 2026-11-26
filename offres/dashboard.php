<?php
require_once __DIR__ . '/../auth_check.php';
AccessManager::requirePageAccess('liste_offre.php');
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/Offre.php';
$pdo = Database::getConnection();
require_once __DIR__ . '/../services/DevisOfferProjectMigrationService.php';
(new DevisOfferProjectMigrationService($pdo))->ensureSchema();

$stats = ['projects'=>0,'quotes'=>0,'principal'=>0,'ts'=>0,'sites'=>0,'value'=>0.0,'documents'=>0];
$recent = [];
foreach ([
    'projects'=>'SELECT COUNT(*) FROM offre_banamur',
    'quotes'=>'SELECT COUNT(*) FROM devis_banamur d WHERE COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))',
    'principal'=>'SELECT COUNT(*) FROM devis_offer_classifications WHERE devis_type="PRINCIPAL"',
    'ts'=>'SELECT COUNT(*) FROM devis_offer_classifications WHERE devis_type="TS"',
    'sites'=>'SELECT COUNT(DISTINCT ch.id) FROM chantier_banamur ch INNER JOIN devis_banamur d ON d.id=ch.devis_id WHERE ch.archived_at IS NULL',
    'value'=>'SELECT COALESCE(SUM(d.total_ttc),0) FROM devis_banamur d WHERE COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))',
    'documents'=>'SELECT COUNT(*) FROM offre_fichiers_banamur',
] as $key=>$sql) { try { $stats[$key] = $key==='value' ? (float)$pdo->query($sql)->fetchColumn() : (int)$pdo->query($sql)->fetchColumn(); } catch(Throwable $e) {} }
try {
    $recent = $pdo->query('SELECT o.*,cl.nom_client,cl.ncc,(SELECT COUNT(*) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur ch WHERE ch.devis_id=d.id AND ch.archived_at IS NULL))) quote_count,(SELECT COUNT(*) FROM chantier_banamur ch INNER JOIN devis_banamur d ON d.id=ch.devis_id WHERE d.offre_id=o.id_offre AND ch.archived_at IS NULL) site_count,(SELECT COALESCE(SUM(d.total_ttc),0) FROM devis_banamur d WHERE d.offre_id=o.id_offre AND COALESCE(d.masque,0)=0 AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur ch WHERE ch.devis_id=d.id AND ch.archived_at IS NULL))) project_value FROM offre_banamur o LEFT JOIN client_banamur cl ON cl.id_client=o.client_id ORDER BY o.id_offre DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch(Throwable $e) {
    try { $recent=$pdo->query('SELECT o.*,cl.nom_client,cl.ncc,0 quote_count,0 site_count,0 project_value FROM offre_banamur o LEFT JOIN client_banamur cl ON cl.id_client=o.client_id ORDER BY o.id_offre DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC)?:[]; } catch(Throwable $ignored) {}
}
if (!$recent) {
    try {
        $fallbackProjects = array_reverse((new Offre($pdo))->getAllOffres());
        foreach (array_slice($fallbackProjects, 0, 6) as $project) $recent[] = $project + ['quote_count'=>0,'site_count'=>0,'project_value'=>0];
    } catch(Throwable $ignored) {}
}
require_once __DIR__.'/../devis/includes/module_bootstrap.php';
$activeDevisModule='offres';
$moduleTitle='Pilotage des offres et projets';
$moduleSubtitle='Suivre le portefeuille commercial, les devis principaux, les travaux supplémentaires et les chantiers actifs.';
$e=static fn($v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$money=static fn($v):string=>number_format((float)$v,0,',',' ').' FCFA';
ob_start();
?>
<div class="op-workspace">
<section class="ed-kpis">
<?php foreach([['Projets enregistrés',$stats['projects'],'fa-folder-open'],['Devis actifs',$stats['quotes'],'fa-file-invoice'],['Chantiers actifs',$stats['sites'],'fa-helmet-safety'],['Valeur TTC des devis actifs',$money($stats['value']),'fa-chart-line']] as $k):?>
<article class="ed-kpi"><div class="ed-kpi-icon"><i class="fa-solid <?=$k[2]?>"></i></div><span><?=$e($k[0])?></span><strong><?=$e($k[1])?></strong></article>
<?php endforeach;?>
</section>
<section class="ed-grid">
<article class="ed-panel"><h2>Accès rapides</h2><p>Gérer le portefeuille et les dossiers commerciaux.</p><div class="ed-actions">
<a class="primary" href="liste.php?create=1"><i class="fa-solid fa-plus"></i> Nouveau projet</a>
<a href="liste.php"><i class="fa-solid fa-list"></i> Tous les projets</a>
<?php if(devis_module_has_access('acces_generer_devis')):?><a href="../devis/generer.php"><i class="fa-solid fa-file-circle-plus"></i> Nouveau devis</a><?php endif;?>
<a href="parametrage.php"><i class="fa-solid fa-sliders"></i> Paramétrage</a>
</div><div class="ed-export"><a href="../exports/finea_module_export.php?module=offres&amp;format=pdf"><i class="fa-solid fa-file-pdf"></i> Export PDF</a><a href="../exports/finea_module_export.php?module=offres&amp;format=xlsx"><i class="fa-solid fa-file-excel"></i> Export Excel</a></div></article>
<article class="ed-panel"><h2>Structure du portefeuille</h2><p>Classification des devis et pièces commerciales.</p><div class="ed-list"><?php foreach(['Devis principaux'=>'principal','Travaux supplémentaires'=>'ts','Documents joints'=>'documents'] as $label=>$key):?><div class="ed-list-item"><strong><?=$e($label)?></strong><span class="ed-pill"><?=$e($stats[$key])?></span></div><?php endforeach;?></div></article>
</section>
<section class="ed-panel op-recent"><div class="op-head"><div><h2>Derniers projets enregistrés</h2><p>Consulter les dossiers et leur activité.</p></div><a class="op-link" href="liste.php">Afficher tout <i class="fa-solid fa-arrow-right"></i></a></div>
<div class="op-grid"><?php foreach($recent as $project):?><article class="op-card"><div class="op-head"><span class="ed-pill"><?=$e($project['num_offre']??'')?></span><small><?=$e($project['date_offre']??'')?></small></div><h3><?=$e($project['reference_offre']??'')?></h3><p><i class="fa-solid fa-building"></i> <?=$e($project['nom_client']??'Client non rattaché')?></p><p><i class="fa-solid fa-user-tie"></i> <?=$e($project['commercial_dedie']??'')?></p><div class="op-metrics"><div><strong><?=(int)$project['quote_count']?></strong><small>Devis actifs</small></div><div><strong><?=(int)$project['site_count']?></strong><small>Chantiers actifs</small></div></div><div class="op-value"><small>Valeur TTC active</small><strong><?=$money($project['project_value'])?></strong></div><a class="op-link" href="modifier.php?id=<?=(int)$project['id_offre']?>">Ouvrir le projet <i class="fa-solid fa-arrow-right"></i></a></article><?php endforeach;?><?php if(!$recent):?><p>Aucun projet enregistré.</p><?php endif;?></div></section>
</div>
<?php $content=ob_get_clean();require __DIR__.'/../devis/includes/module_layout.php';
