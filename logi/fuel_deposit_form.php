<?php
require_once __DIR__ . '/_logistics_common.php';
require_once __DIR__ . '/../services/FuelLogisticsService.php';

$fuelService = new FuelLogisticsService($pdo);
$stations = $fuelService->listStations();
$stationOptions = [];
foreach ($stations as $station) {
    $label = trim((string)($station['name'] ?? ''));
    if ($label !== '') {
        $stationOptions[] = ['value' => (string)(int)($station['id'] ?? 0), 'label' => $label];
    }
}
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $depositId = $fuelService->createDeposit($_POST, $_FILES['receipt'] ?? [], (int)($_SESSION['user_id'] ?? 0));
        logi_audit($pdo, 'create_fuel_deposit', 'fuel_station_deposit', $depositId, trim((string)($_POST['reference'] ?? 'Rechargement carburant')), ['station_id' => $_POST['station_id'] ?? null, 'amount' => $_POST['amount'] ?? null]);
        logi_redirect('fuel.php?deposit=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

logi_page_start('Nouveau rechargement carburant', 'Formulaire dedie pour crediter le solde carburant general.', 'fa-money-bill-transfer');
?>

<?php if ($error !== ''): ?>
    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 font-semibold text-rose-800"><?= logi_h($error) ?></div>
<?php endif; ?>

<?php logi_card_open('Rechargement station', 'Ajoutez le depot effectue en station avec recu ou photo justificative.'); ?>
<form method="post" enctype="multipart/form-data" class="grid gap-5 md:grid-cols-2">
    <?= logi_search_select('Station', 'station_id', $stationOptions, (string)($_POST['station_id'] ?? ''), 'required', 'Rechercher une station') ?>
    <?= logi_input('Montant depot', 'amount', (string)($_POST['amount'] ?? ''), 'number', 'step="0.01" min="1" required') ?>
    <?= logi_input('Date depot', 'deposit_date', (string)($_POST['deposit_date'] ?? date('Y-m-d')), 'date', 'required') ?>
    <?= logi_input('Reference recu', 'reference', (string)($_POST['reference'] ?? '')) ?>
    <div class="md:col-span-2"><?= logi_file_dropzone('Recu scanne / photo', 'receipt', 'image/*,.pdf', 'Glisser le recu ici ou cliquer') ?></div>
    <div class="md:col-span-2"><?= logi_textarea('Notes', 'notes', (string)($_POST['notes'] ?? '')) ?></div>
    <div class="md:col-span-2"><?= logi_submit('Crediter le solde') ?></div>
</form>
<div class="mt-4 flex flex-wrap gap-3">
    <a href="fuel.php" class="font-semibold text-orange-600">Retour carburant</a>
    <a href="fuel_station_form.php" class="font-semibold text-slate-700">Ajouter une station</a>
</div>
<?php logi_card_close(); ?>

<?php logi_page_end(); ?>
