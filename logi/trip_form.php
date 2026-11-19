<?php
require_once __DIR__ . '/_logistics_common.php';

$assets = logi_options_assets($pdo);
$drivers = logi_options_drivers($pdo);
$batches = $pdo->query("SELECT id,batch_code,site_label,debourse_label FROM logistics_trip_batches ORDER BY id DESC LIMIT 600")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deliveryNoteFilePath = logi_store_uploaded_file('delivery_note_file', 'trip_delivery_notes');
    $pdo->prepare('INSERT INTO logistics_trips(batch_id,asset_id,driver_id,trip_count,transported_quantity,fuel_liters,fuel_amount,road_fees_amount,departure_at,arrival_at,delivery_note,delivery_note_file_path,status,observation) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $_POST['batch_id'] ?: null,
        $_POST['asset_id'] ?: null,
        $_POST['driver_id'] ?: null,
        max(1, (int)($_POST['trip_count'] ?? 1)),
        (float)($_POST['transported_quantity'] ?? 0),
        (float)($_POST['fuel_liters'] ?? 0),
        (float)($_POST['fuel_amount'] ?? 0),
        (float)($_POST['road_fees_amount'] ?? 0),
        $_POST['departure_at'] ?: null,
        $_POST['arrival_at'] ?: null,
        $_POST['delivery_note'] ?? null,
        $deliveryNoteFilePath,
        $_POST['status'] ?? 'planned',
        $_POST['observation'] ?? null,
    ]);
    logi_audit($pdo, 'create_trip_rotation', 'logistics_trip', (int)$pdo->lastInsertId(), 'Rotation voyage', ['batch_id' => $_POST['batch_id'] ?? null, 'asset_id' => $_POST['asset_id'] ?? null, 'driver_id' => $_POST['driver_id'] ?? null, 'delivery_note' => $_POST['delivery_note'] ?? null, 'delivery_note_file_path' => $deliveryNoteFilePath]);
    logi_redirect('trip_rotations.php?ok=1');
}

logi_page_start('Nouvelle rotation', 'Formulaire dedie pour enregistrer une rotation sur une vague.');
logi_card_open('Ajouter une rotation', 'Suivi par vehicule, chauffeur, carburant et frais de route.');
?>
<form method="post" enctype="multipart/form-data" class="grid md:grid-cols-2 gap-5">
    <?= logi_search_select('Vague', 'batch_id', array_map(fn($b) => ['value' => (string)$b['id'], 'label' => $b['batch_code'] . ' - ' . $b['site_label'] . ' / ' . ($b['debourse_label'] ?? '')], $batches), '', 'required', 'Rechercher une vague') ?>
    <?= logi_search_select('Camion / engin', 'asset_id', logi_search_options($assets, 'id', 'label', 'Selectionner'), '', 'required', 'Rechercher un vehicule') ?>
    <?= logi_search_select('Chauffeur', 'driver_id', logi_search_options($drivers, 'id', 'label', 'Selectionner'), '', 'required', 'Rechercher un chauffeur') ?>
    <?= logi_input('Nombre de voyages', 'trip_count', '1', 'number', 'min="1" step="1"') ?>
    <?= logi_input('Quantite transportee', 'transported_quantity', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Carburant consomme (L)', 'fuel_liters', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Montant carburant', 'fuel_amount', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Frais de route', 'road_fees_amount', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Depart', 'departure_at', '', 'datetime-local') ?>
    <?= logi_input('Arrivee', 'arrival_at', '', 'datetime-local') ?>
    <?= logi_input('Numero de bon', 'delivery_note') ?>
    <?= logi_select('Statut', 'status', ['planned'=>'Planifie','in_progress'=>'En cours','completed'=>'Termine','cancelled'=>'Annule']) ?>
    <div class="md:col-span-2"><?= logi_file_dropzone('Document du bon', 'delivery_note_file', 'image/*,.pdf', 'Glisser le bon ici ou cliquer pour joindre le document') ?></div>
    <div class="md:col-span-2"><?= logi_textarea('Observation', 'observation') ?></div>
    <div class="md:col-span-2 flex justify-end gap-3"><a href="trip_rotations.php" class="rounded-2xl border border-slate-200 bg-white px-5 py-3 font-semibold text-slate-700">Annuler</a><?= logi_submit('Ajouter rotation') ?></div>
</form>
<?php logi_card_close(); logi_page_end(); ?>
