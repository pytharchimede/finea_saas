<?php require_once __DIR__ . '/_logistics_common.php';
$assets=logi_options_assets($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $pdo->prepare('INSERT INTO logistics_maintenance(asset_id,maintenance_type,title,planned_at,intervention_at,intervenant_name,cost_amount,immobilization_hours,status,report) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$_POST['asset_id']?:null,$_POST['maintenance_type']??'preventive',$_POST['title']??null,$_POST['planned_at']?:null,$_POST['intervention_at']?:null,$_POST['intervenant_name']??null,(float)($_POST['cost_amount']??0),(float)($_POST['immobilization_hours']??0),$_POST['status']??'planned',$_POST['report']??null]);
 $maintenanceId=(int)$pdo->lastInsertId(); $label=trim((string)($_POST['title']??'Maintenance'));
 logi_notify_asset_event($pdo,'maintenance_created',['entity_type'=>'maintenance','entity_id'=>$maintenanceId,'entity_label'=>$label]);
 logi_audit($pdo,'create_maintenance','logistics_maintenance',$maintenanceId,$label,['asset_id'=>$_POST['asset_id']??null,'status'=>$_POST['status']??'planned']);
 logi_redirect('maintenance.php?ok=1');
}
logi_page_start('Ajouter une maintenance','Formulaire dedie intervention preventive ou curative.');
logi_card_open('Nouvelle fiche maintenance','Planifier ou enregistrer une intervention'); ?>
<form method="post" class="grid md:grid-cols-3 gap-5"><?= logi_search_select('Engin / vehicule','asset_id',logi_search_options($assets,'id','label','Selectionner'),'','','Rechercher une ressource') ?><?= logi_select('Type','maintenance_type',['preventive'=>'Preventive','curative'=>'Curative','breakdown'=>'Panne','control'=>'Controle']) ?><?= logi_input('Titre','title','','text','required') ?><?= logi_input('Date prevue','planned_at','','datetime-local') ?><?= logi_input('Date intervention','intervention_at','','datetime-local') ?><?= logi_input('Intervenant','intervenant_name') ?><?= logi_input('Cout','cost_amount','0','number','step="0.01"') ?><?= logi_input('Heures immobilisation','immobilization_hours','0','number','step="0.01"') ?><?= logi_select('Statut','status',['planned'=>'Planifiee','in_progress'=>'En cours','completed'=>'Terminee','cancelled'=>'Annulee']) ?><div class="md:col-span-3"><?= logi_textarea('Rapport technique','report') ?></div><div class="md:col-span-3"><?= logi_submit('Enregistrer la maintenance') ?></div></form>
<div class="mt-4"><a href="maintenance.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
