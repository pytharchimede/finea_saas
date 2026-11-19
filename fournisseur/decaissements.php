<?php
require_once __DIR__.'/../partials/supplier_workspace.php';
require_once __DIR__.'/../auth_check.php';
if (!AccessManager::hasPermission('acces_compta') && !AccessManager::hasPermission('acces_admin') && !AccessManager::hasPermission('acces_point_financier')) { http_response_code(403); exit('Accès refusé.'); }
require_once __DIR__.'/../model/Database.php';
$pdo=Database::getConnection(); $id=(int)($_GET['id']??0);
$s=$pdo->prepare('SELECT * FROM fournisseur_banamur WHERE id_fournisseur=?');$s->execute([$id]);$supplier=$s->fetch(PDO::FETCH_ASSOC);
if(!$supplier){http_response_code(404);exit('Fournisseur introuvable.');}
$s=$pdo->prepare('SELECT num_fiche, designation_fiche, montant_fiche, decaisse, date_creat_fiche FROM fiche_decaissement WHERE fournisseur_id=? ORDER BY id_fiche DESC');$s->execute([$id]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Décaissements du fournisseur';$layoutRootBase='../';$layoutModuleBase='../decaissement/';
require __DIR__.'/../decaissement/includes/header.php';require __DIR__.'/../decaissement/includes/navbar.php';
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<main class="supplier-directory"><?php supplierWorkspaceHeader('Décaissements rattachés', (string)($supplier['nom_commercial'] ?? 'Activité financière du fournisseur'), $id); ?><a class="inline-block my-5 text-indigo-700" href="details_fournisseur.php?id=<?=$id?>">← Fiche fournisseur</a><div class="overflow-x-auto rounded-2xl border bg-white"><table class="w-full text-left"><thead><tr><th class="p-4">Fiche</th><th>Désignation</th><th>Montant</th><th>État</th></tr></thead><tbody><?php foreach($rows as $row):?><tr class="border-t"><td class="p-4"><a class="text-indigo-700" href="../decaissement/voir_fiche_details.php?num_fiche=<?=urlencode($row['num_fiche'])?>"><?=$h($row['num_fiche'])?></a></td><td><?=$h($row['designation_fiche'])?></td><td><?=number_format((float)$row['montant_fiche'],0,',',' ')?> FCFA</td><td><?=!empty($row['decaisse'])?'Décaissé':'En attente'?></td></tr><?php endforeach;?></tbody></table><?php if(!$rows):?><p class="p-8 text-slate-500">Aucun décaissement rattaché.</p><?php endif;?></div></main>
<?php require __DIR__.'/../decaissement/includes/footer.php'; ?>
