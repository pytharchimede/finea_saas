<?php
/** Shared supplier page identity, navigation and actions. */
function supplierWorkspaceHeader(string $title, string $description, int $supplierId = 0): void
{
    $h=static fn($v)=>htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    static $assetsLoaded = false;
    if (!$assetsLoaded) {
        $version = (string)filemtime(__DIR__.'/../assets/css/supplier-module.css');
        echo '<link rel="stylesheet" href="../assets/css/supplier-directory.css?v='.$version.'"><link rel="stylesheet" href="../assets/css/supplier-module.css?v='.$version.'">';
        $assetsLoaded = true;
    }
    $page=basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $activePage = ['categorie_form.php'=>'categories.php','forme_juridique_form.php'=>'formes_juridiques.php'][$page] ?? $page;
    $employee=str_contains($page,'_employe') || (isset($GLOBALS['isEmployeeOnly']) && $GLOBALS['isEmployeeOnly']);
    $prefix='../fournisseur/';
    $directory=$employee?'../employe/mes_fournisseurs_employe.php':$prefix.'dashboard_fournisseur.php';
    $create=$employee?'../employe/ajouter_fournisseur_employe.php':$prefix.'ajouter_fournisseur.php';
    $nav=$employee?[
        ['fa-address-card','Mes fournisseurs',$directory,'mes_fournisseurs_employe.php'],
        ['fa-plus','Nouveau fournisseur',$create,'ajouter_fournisseur_employe.php'],
    ]:[
        ['fa-address-card','Répertoire',$directory,'dashboard_fournisseur.php'],
        ['fa-map-location-dot','Répartition GPS',$prefix.'fournisseurs.php#repartition-gps','fournisseurs.php'],
        ['fa-chart-pie','Statistiques',$prefix.'statistiques.php','statistiques.php'],
        ['fa-triangle-exclamation','Incidents',$prefix.'incidents.php','incidents.php'],
        ['fa-file-circle-check','Régularisations',$prefix.'regularisations_fournisseurs.php','regularisations_fournisseurs.php'],
        ['fa-tags','Catégories',$prefix.'categories.php','categories.php'],
        ['fa-building','Formes juridiques',$prefix.'formes_juridiques.php','formes_juridiques.php'],
    ];
    ?>
    <header class="directory-heading">
        <div><p class="directory-eyebrow">PARTENAIRES & ACHATS</p><h1><?= $h($title) ?></h1><p><?= $h($description) ?></p></div>
        <div class="directory-header-actions">
            <?php if ($page!=='dashboard_fournisseur.php' && $page!=='mes_fournisseurs_employe.php'): ?><a class="directory-secondary" href="<?= $h($directory) ?>">← Répertoire</a><?php endif; ?>
            <?php if (!str_starts_with($page,'ajouter_')): ?><a class="directory-primary" href="<?= $h($create) ?>"><i class="fas fa-plus"></i> Nouveau fournisseur</a><?php endif; ?>
        </div>
    </header>
    <nav class="directory-shortcuts" aria-label="Navigation fournisseurs">
        <?php foreach($nav as [$icon,$label,$url,$active]): ?><a href="<?= $h($url) ?>" <?= $activePage===$active?'aria-current="page"':'' ?>><i class="fas <?= $h($icon) ?>"></i><?= $h($label) ?></a><?php endforeach; ?>
    </nav>
    <?php if($supplierId>0): ?>
    <nav class="directory-record-nav" aria-label="Actions de la fiche fournisseur">
        <span>Fournisseur #<?= $supplierId ?></span>
        <a href="<?= $prefix ?>details_fournisseur.php?id=<?= $supplierId ?>">Fiche</a>
        <a href="<?= $employee?'../employe/edit_fournisseur_employe.php':$prefix.'edit_fournisseur.php' ?>?id=<?= $supplierId ?>">Modifier / normaliser</a>
        <a href="<?= $prefix ?>details_fournisseur.php?id=<?= $supplierId ?>#supplier-map">GPS</a>
        <a href="<?= $employee?'../employe/edit_fournisseur_employe.php':$prefix.'edit_fournisseur.php' ?>?id=<?= $supplierId ?>#incidents">Incidents</a>
        <?php if(AccessManager::hasPermission('acces_compta')||AccessManager::hasPermission('acces_admin')||AccessManager::hasPermission('acces_point_financier')): ?><a href="<?= $prefix ?>decaissements.php?id=<?= $supplierId ?>">Décaissements</a><?php endif; ?>
        <a href="<?= $prefix ?>request/export_fournisseur_pdf.php?id=<?= $supplierId ?>" target="_blank" rel="noopener">PDF</a>
    </nav>
    <?php endif;
}
