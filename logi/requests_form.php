<?php
require_once __DIR__ . '/_logistics_common.php';
$chantiers = logi_chantiers($pdo);
$services = logi_services($pdo);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = 'REQ-LOG-' . date('ymd-His');
    $pdo->prepare('INSERT INTO logistics_requests(request_code,requesting_service,requester_name,site_label,need_type,priority,desired_at,description,status) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$code, $_POST['requesting_service'] ?? null, $_POST['requester_name'] ?? null, $_POST['site_label'] ?? null, $_POST['need_type'] ?? 'engin', $_POST['priority'] ?? 'normal', $_POST['desired_at'] ?: null, $_POST['description'] ?? null, $_POST['status'] ?? 'submitted']);
    $requestId = (int) $pdo->lastInsertId();
    $payload = ['id' => $requestId, 'request_code' => $code, 'requester_name' => $_POST['requester_name'] ?? '', 'need_type' => $_POST['need_type'] ?? 'engin', 'asset_label' => $_POST['description'] ?? 'Besoin logistique', 'status' => $_POST['status'] ?? 'submitted'];
    logi_notify_request($pdo, 'submitted', $payload, logi_actor_context());
    logi_audit($pdo, 'create_request', 'logistics_request', $requestId, $code, ['need_type' => $_POST['need_type'] ?? 'engin', 'priority' => $_POST['priority'] ?? 'normal']);
    logi_redirect('requests.php?ok=1');
}
logi_page_start('Nouvelle demande logistique', 'Formulaire dedie pour enregistrer une sollicitation.');
logi_card_open('Expression du besoin', 'Demande provenant d un service ou chantier'); ?>
<form method="post" class="grid md:grid-cols-3 gap-5">
<?= logi_service_search_input('Service demandeur','requesting_service',$services) ?>
<?= logi_input('Demandeur','requester_name') ?>
<?= logi_chantier_search_input('Chantier / zone','site_label',$chantiers) ?>
<?= logi_select('Type de besoin','need_type',['engin'=>'Engin','vehicule'=>'Vehicule','chauffeur'=>'Chauffeur','carburant'=>'Carburant','materiel'=>'Materiel','materiaux'=>'Materiaux','piece'=>'Piece','intervention'=>'Intervention']) ?>
<?= logi_select('Priorite','priority',['low'=>'Basse','normal'=>'Normale','urgent'=>'Urgente','critical'=>'Critique']) ?>
<?= logi_input('Date souhaitee','desired_at','','datetime-local') ?>
<div class="md:col-span-3"><?= logi_textarea('Description detaillee','description') ?></div>
<?= logi_select('Statut','status',['submitted'=>'Soumise','study'=>'En etude','accepted'=>'Acceptee','planned'=>'Planifiee','in_progress'=>'En cours','completed'=>'Terminee','rejected'=>'Rejetee','cancelled'=>'Annulee']) ?>
<div class="md:col-span-3"><?= logi_submit('Soumettre la demande') ?></div>
</form>
<div class="mt-4"><a href="requests.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
