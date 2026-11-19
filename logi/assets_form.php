<?php
require_once __DIR__ . '/_logistics_common.php';

function logi_asset_code_prefix(string $assetType, string $designation, string $registration = ''): string {
    $source = trim($designation) !== '' ? $designation : (trim($registration) !== '' ? $registration : $assetType);
    $normalized = $source;
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source);
        if ($converted !== false) $normalized = $converted;
    }
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($normalized)) ?: '';
    $fallback = preg_replace('/[^A-Z]/', '', strtoupper($assetType)) ?: 'ENG';
    return substr(str_pad($letters . $fallback, 3, 'X'), 0, 3);
}
function logi_next_asset_code(PDO $pdo, string $assetType = 'engin', string $designation = '', string $registration = ''): string {
    $prefix = logi_asset_code_prefix($assetType, $designation, $registration);
    $stmt = $pdo->prepare('SELECT asset_code FROM logistics_assets WHERE asset_code LIKE :prefix');
    $stmt->execute(['prefix' => $prefix . '-%']);
    $max = 0;
    foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $code) {
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', (string) $code, $m)) $max = max($max, (int)$m[1]);
    }
    return sprintf('%s-%03d', $prefix, $max + 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetType = trim((string)($_POST['asset_type'] ?? 'engin'));
    $designation = trim((string)($_POST['designation'] ?? ''));
    $registration = trim((string)($_POST['registration_number'] ?? ''));
    $assetCode = logi_next_asset_code($pdo, $assetType, $designation, $registration);
    $sql = 'INSERT INTO logistics_assets(asset_type,asset_code,designation,registration_number,brand,model,current_site,assigned_driver_id,hour_meter,odometer,status,condition_state) VALUES(:asset_type,:asset_code,:designation,:registration_number,:brand,:model,:current_site,:assigned_driver_id,:hour_meter,:odometer,:status,:condition_state)';
    $pdo->prepare($sql)->execute([
        'asset_type'=>$assetType,'asset_code'=>$assetCode,'designation'=>$designation,'registration_number'=>$registration,'brand'=>$_POST['brand']??null,'model'=>$_POST['model']??null,'current_site'=>$_POST['current_site']??null,'assigned_driver_id'=>($_POST['assigned_driver_id']??'')!==''?(int)$_POST['assigned_driver_id']:null,'hour_meter'=>(float)($_POST['hour_meter']??0),'odometer'=>(float)($_POST['odometer']??0),'status'=>$_POST['status']??'available','condition_state'=>$_POST['condition_state']??'operational'
    ]);
    $assetId = (int) $pdo->lastInsertId();
    $label = trim($assetCode . ' - ' . $designation);
    logi_notify_asset_event($pdo, 'asset_created', ['entity_type' => 'asset', 'entity_id' => $assetId, 'entity_label' => $label, 'current_site' => $_POST['current_site'] ?? null]);
    logi_audit($pdo, 'create_asset', 'logistics_asset', $assetId, $label, ['asset_type' => $assetType, 'asset_code' => $assetCode]);
    logi_redirect('assets.php?ok=1');
}

$rows = logi_assets($pdo);
$drivers = logi_options_drivers($pdo);
$chantiers = logi_chantiers($pdo);
$suggestedAssetCode = logi_next_asset_code($pdo);
logi_page_start('Ajouter au parc', 'Formulaire dedie pour enregistrer un engin, vehicule ou materiel.');
logi_card_open('Nouvel element du parc', 'Les champs principaux suffisent pour rendre la ressource visible dans les listings.'); ?>
<form method="post" class="grid grid-cols-1 md:grid-cols-3 gap-5">
<?= logi_select('Type','asset_type',['engin'=>'Engin','vehicule'=>'Vehicule','materiel'=>'Materiel'],'','id="assetTypeField"') ?>
<?= logi_input('Code interne','asset_code',$suggestedAssetCode,'text','id="assetCodeField" readonly') ?>
<?= logi_input('Designation','designation','','text','required id="assetDesignationField"') ?>
<?= logi_input('Immatriculation','registration_number','','text','id="assetRegistrationField"') ?>
<?= logi_input('Marque','brand') ?>
<?= logi_input('Modele','model') ?>
<?= logi_chantier_search_input('Chantier actuel','current_site',$chantiers) ?>
<?= logi_search_select('Chauffeur affecte','assigned_driver_id',logi_search_options($drivers,'id','label','Non affecte'),'','','Rechercher un chauffeur') ?>
<?= logi_select('Statut','status',['available'=>'Disponible','busy'=>'En mission','maintenance'=>'Maintenance','breakdown'=>'En panne','inactive'=>'Inactif']) ?>
<?= logi_input('Compteur horaire','hour_meter','0','number','step="0.01"') ?>
<?= logi_input('Kilometrage','odometer','0','number','step="0.01"') ?>
<?= logi_select('Etat technique','condition_state',['operational'=>'Operationnel','to_monitor'=>'A surveiller','critical'=>'Critique']) ?>
<div class="md:col-span-3"><?= logi_submit('Ajouter au parc') ?></div>
</form>
<div class="mt-4"><a href="assets.php" class="font-semibold text-orange-600">Retour au listing</a></div>
<?php logi_card_close(); logi_page_end();
