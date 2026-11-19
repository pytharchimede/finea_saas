<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__.'/../auth_check.php';
AccessManager::requirePageAccess('dashboard_fournisseur.php');
require_once __DIR__.'/../model/Database.php';
$pdo=Database::getConnection();
$rows=$pdo->query("SELECT i.*, COALESCE(NULLIF(f.nom_commercial,''),f.raison_sociale) supplier_name, f.code_fournisseur FROM fournisseur_incident_banamur i JOIN fournisseur_banamur f ON f.id_fournisseur=i.fournisseur_id ORDER BY i.incident_date DESC,i.id_incident DESC")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Incidents fournisseurs';$layoutRootBase='../';$layoutModuleBase='../decaissement/';
require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<link rel="stylesheet" href="../assets/css/supplier-directory.css?v=2">
<main class="supplier-directory"><?php supplierWorkspaceHeader('Incidents fournisseurs', 'Consultez les incidents, les litiges et les actions à mener avec vos partenaires.', 0); ?><div class="supplier-card-grid"><?php foreach($rows as $r):?><article class="supplier-card"><div class="supplier-card-top"><span class="supplier-reference"><?=$h($r['code_fournisseur'])?></span><span class="supplier-state is-incomplete"><?=$h($r['gravite'])?></span></div><h3><?=$h($r['titre'])?></h3><p class="supplier-legal"><?=$h($r['supplier_name'])?> · <?=$h($r['incident_date'])?></p><p class="text-sm text-slate-600 whitespace-pre-line"><?=$h($r['description'])?></p><div class="supplier-card-actions"><a class="directory-secondary" href="edit_fournisseur.php?id=<?=(int)$r['fournisseur_id']?>#incidents">Consulter / ajouter un incident</a></div></article><?php endforeach;?></div><?php if(!$rows):?><div class="directory-empty">Aucun incident enregistré. Pour en déclarer un, ouvrez la fiche du fournisseur concerné.</div><?php endif;?></main>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>
