<?php
require_once __DIR__ . '/_logistics_common.php';
$rh = logi_rh_personnel($pdo);
$services = logi_services($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driverType = $_POST['driver_type'] ?? 'internal';
    $personnelId = $driverType === 'internal' && ($_POST['personnel_id'] ?? '') !== '' ? (int) $_POST['personnel_id'] : null;
    $name = $driverType === 'internal' ? '' : trim((string) ($_POST['full_name'] ?? ''));
    if ($personnelId) {
        foreach ($rh as $person) {
            if ((int) $person['id'] === $personnelId) {
                $name = (string) $person['full_name'];
                $_POST['phone'] = $person['phone'] ?? null;
                $_POST['service_label'] = $person['service_label'] ?? null;
                break;
            }
        }
    }
    $pdo->prepare('INSERT INTO logistics_drivers(personnel_id,driver_type,full_name,phone,license_number,license_category,license_expires_at,service_label,availability_status) VALUES(?,?,?,?,?,?,?,?,?)')
        ->execute([$personnelId, $driverType, $name, $_POST['phone'] ?? null, $_POST['license_number'] ?? null, $_POST['license_category'] ?? null, $_POST['license_expires_at'] ?: null, $_POST['service_label'] ?? null, $_POST['availability_status'] ?? 'available']);
    $driverId = (int) $pdo->lastInsertId();
    logi_notify_asset_event($pdo, 'driver_created', ['entity_type' => 'driver', 'entity_id' => $driverId, 'entity_label' => $name !== '' ? $name : 'Chauffeur logistique']);
    logi_audit($pdo, 'create_driver', 'logistics_driver', $driverId, $name, ['driver_type' => $driverType, 'personnel_id' => $personnelId]);
    logi_redirect('drivers.php?ok=1');
}

logi_page_start('Ajouter un chauffeur', 'Formulaire dedie pour rattacher un personnel RH ou creer un chauffeur externe.');
logi_card_open('Nouveau chauffeur', 'Creation chauffeur externe ou rattachement RH'); ?>
<form method="post" class="grid md:grid-cols-3 gap-5">
<?= logi_select('Type chauffeur', 'driver_type', ['internal' => 'Interne RH', 'external' => 'Externe'], '', 'id="driverTypeField"') ?>
<?= logi_search_select('Personnel RH disponible', 'personnel_id', array_map(fn($person) => ['value' => (string) $person['id'], 'label' => trim((string) $person['full_name'] . (!empty($person['service_label']) ? ' - ' . $person['service_label'] : '') . (!empty($person['phone']) ? ' - ' . $person['phone'] : ''))], $rh), '', 'id="personnelIdField"', 'Rechercher un nom, service ou telephone') ?>
<?= logi_input('Nom complet', 'full_name', '', 'text', 'id="fullNameField"') ?>
<?= logi_input('Telephone', 'phone', '', 'text', 'id="phoneField"') ?>
<?= logi_input('N permis', 'license_number') ?>
<?= logi_input('Categorie permis', 'license_category') ?>
<?= logi_input('Expiration permis', 'license_expires_at', '', 'date') ?>
<?= logi_service_search_input('Service', 'service_label', $services, '', 'id="serviceField"') ?>
<?= logi_select('Disponibilite', 'availability_status', ['available' => 'Disponible', 'busy' => 'En mission', 'inactive' => 'Inactif']) ?>
<div class="md:col-span-3"><?= logi_submit('Enregistrer le chauffeur') ?></div>
</form>
<div class="mt-4"><a href="drivers.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
