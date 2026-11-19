<?php require_once __DIR__ . '/_logistics_common.php';
$assets=logi_options_assets($pdo); $drivers=logi_options_drivers($pdo); $chantiers=logi_chantiers($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $pdo->prepare('INSERT INTO logistics_incidents(asset_id,driver_id,site_label,incident_type,severity,description,corrective_action,status,incident_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$_POST['asset_id']?:null,$_POST['driver_id']?:null,$_POST['site_label']??null,$_POST['incident_type']??null,$_POST['severity']??'normal',$_POST['description']??null,$_POST['corrective_action']??null,$_POST['status']??'open',$_POST['incident_at']?:date('Y-m-d H:i:s')]);
 $incidentId=(int)$pdo->lastInsertId(); $label=trim((string)($_POST['incident_type']??'Incident').' - '.(string)($_POST['site_label']??''));
 logi_notify_asset_event($pdo,'incident_created',['entity_type'=>'incident','entity_id'=>$incidentId,'entity_label'=>$label,'site_label'=>$_POST['site_label']??null]);
 logi_audit($pdo,'create_incident','logistics_incident',$incidentId,$label,['severity'=>$_POST['severity']??'normal','asset_id'=>$_POST['asset_id']??null]);
 logi_redirect('incidents.php?ok=1');
}
logi_page_start('Declarer un incident','Formulaire dedie anomalie terrain ou probleme mecanique.');
logi_card_open('Declarer un incident','Anomalie terrain ou probleme mecanique'); ?>
<form method="post" class="grid md:grid-cols-3 gap-5"><?= logi_search_select('Engin / vehicule','asset_id',logi_search_options($assets,'id','label','Selectionner'),'','','Rechercher une ressource') ?><?= logi_search_select('Chauffeur','driver_id',logi_search_options($drivers,'id','label','Selectionner'),'','','Rechercher un chauffeur') ?><?= logi_chantier_search_input('Chantier','site_label',$chantiers) ?><?= logi_input('Type incident','incident_type') ?><?= logi_select('Gravite','severity',['low'=>'Faible','normal'=>'Normale','urgent'=>'Urgente','critical'=>'Critique']) ?><?= logi_input('Date incident','incident_at','','datetime-local') ?><div class="md:col-span-3"><?= logi_textarea('Description','description') ?></div><div class="md:col-span-3"><?= logi_textarea('Action corrective','corrective_action') ?></div><?= logi_select('Statut','status',['open'=>'Ouvert','in_progress'=>'En cours','resolved'=>'Resolu','cancelled'=>'Annule']) ?><div class="md:col-span-3"><?= logi_submit('Enregistrer incident') ?></div></form>
<div class="mt-4"><a href="incidents.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
