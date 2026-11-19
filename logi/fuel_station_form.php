<?php
require_once __DIR__ . '/_logistics_common.php';
require_once __DIR__ . '/../services/FuelLogisticsService.php';

$fuelService = new FuelLogisticsService($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stationId = $fuelService->createStation($_POST);
        logi_audit($pdo, 'create_fuel_station', 'fuel_partner_station', $stationId, trim((string)($_POST['name'] ?? '')), ['phone' => $_POST['phone'] ?? '']);
        logi_redirect('fuel.php?station=1');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

logi_page_start('Nouvelle station carburant', 'Formulaire dedie pour enregistrer une station ou un fournisseur carburant.', 'fa-gas-pump');
?>

<?php if ($error !== ''): ?>
    <div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 px-5 py-4 font-semibold text-rose-800"><?= logi_h($error) ?></div>
<?php endif; ?>

<?php logi_card_open('Station partenaire', 'Les stations creees ici seront disponibles dans les rechargements carburant.'); ?>
<form method="post" class="grid gap-5 md:grid-cols-2">
    <?= logi_input('Nom station', 'name', (string)($_POST['name'] ?? ''), 'text', 'required') ?>
    <?= logi_input('Contact', 'contact_name', (string)($_POST['contact_name'] ?? '')) ?>
    <?= logi_input('Telephone', 'phone', (string)($_POST['phone'] ?? '')) ?>
    <?= logi_input('Adresse', 'address', (string)($_POST['address'] ?? '')) ?>
    <div class="md:col-span-2"><?= logi_submit('Enregistrer la station') ?></div>
</form>
<div class="mt-4"><a href="fuel.php" class="font-semibold text-orange-600">Retour carburant</a></div>
<?php logi_card_close(); ?>

<?php logi_page_end(); ?>
