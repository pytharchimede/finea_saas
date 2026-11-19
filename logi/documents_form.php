<?php require_once __DIR__ . '/_logistics_common.php';
$assets=logi_options_assets($pdo); $drivers=logi_options_drivers($pdo);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $uploadedPath=logi_store_uploaded_file('document_file','documents');
 $filePath=$uploadedPath ?: trim((string)($_POST['file_path']??''));
 $pdo->prepare('INSERT INTO logistics_documents(asset_id,driver_id,document_type,reference_number,issued_at,expires_at,file_path,status) VALUES(?,?,?,?,?,?,?,?)')->execute([$_POST['asset_id']?:null,$_POST['driver_id']?:null,$_POST['document_type']??null,$_POST['reference_number']??null,$_POST['issued_at']?:null,$_POST['expires_at']?:null,$filePath!==''?$filePath:null,$_POST['status']??'active']);
 $documentId=(int)$pdo->lastInsertId(); $label=trim((string)($_POST['document_type']??'Document').' '.(string)($_POST['reference_number']??''));
 logi_notify_asset_event($pdo,'document_created',['entity_type'=>'document','entity_id'=>$documentId,'entity_label'=>$label]);
 logi_audit($pdo,'create_document','logistics_document',$documentId,$label,['asset_id'=>$_POST['asset_id']??null,'driver_id'=>$_POST['driver_id']??null]);
 logi_redirect('documents.php?ok=1');
}
logi_page_start('Ajouter un document','Formulaire dedie piece administrative.');
logi_card_open('Nouveau document','Archiver une piece administrative et suivre son expiration'); ?>
<form method="post" enctype="multipart/form-data" class="grid md:grid-cols-3 gap-5">
<?= logi_search_select('Engin / vehicule','asset_id',logi_search_options($assets,'id','label','Non concerne'),'','','Rechercher une ressource') ?>
<?= logi_search_select('Chauffeur','driver_id',logi_search_options($drivers,'id','label','Non concerne'),'','','Rechercher un chauffeur') ?>
<?= logi_select('Type document','document_type',['assurance'=>'Assurance','carte_grise'=>'Carte grise','visite_technique'=>'Visite technique','vignette'=>'Vignette','licence'=>'Licence','permis'=>'Permis chauffeur','autre'=>'Autre']) ?>
<?= logi_input('Reference','reference_number') ?>
<?= logi_input('Date emission','issued_at','','date') ?>
<?= logi_input('Date expiration','expires_at','','date') ?>
<div class="md:col-span-2"><?= logi_file_dropzone('Fichier administratif','document_file') ?></div>
<?= logi_input('URL externe optionnelle','file_path') ?>
<?= logi_select('Statut','status',['active'=>'Actif','expired'=>'Expire','inactive'=>'Inactif']) ?>
<div class="md:col-span-3"><?= logi_submit('Archiver document') ?></div>
</form>
<div class="mt-4"><a href="documents.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
