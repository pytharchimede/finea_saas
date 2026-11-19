<?php
require_once __DIR__ . '/_logistics_common.php';

$assets = logi_options_assets($pdo);
$drivers = logi_options_drivers($pdo);
$chantiers = logi_chantiers($pdo);
$q = logi_query_value();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $siteLabel = trim((string)($_POST['site_label'] ?? ''));
    $chantierId = 0;
    foreach ($chantiers as $chantier) {
        if (trim((string)($chantier['label'] ?? '')) === $siteLabel) {
            $chantierId = (int)($chantier['id'] ?? 0);
            break;
        }
    }

    $pdo->prepare('INSERT INTO logistics_asset_daily_logs(activity_date,asset_id,driver_id,chantier_id,site_label,activity_type,work_summary,start_hour_meter,end_hour_meter,start_odometer,end_odometer,fuel_liters,fuel_amount,repair_summary,repair_amount,downtime_hours,status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        $_POST['activity_date'] ?: date('Y-m-d'),
        $_POST['asset_id'] ?: null,
        $_POST['driver_id'] ?: null,
        $chantierId > 0 ? $chantierId : null,
        $siteLabel !== '' ? $siteLabel : null,
        $_POST['activity_type'] ?? 'operation',
        $_POST['work_summary'] ?? null,
        (float)($_POST['start_hour_meter'] ?? 0),
        (float)($_POST['end_hour_meter'] ?? 0),
        (float)($_POST['start_odometer'] ?? 0),
        (float)($_POST['end_odometer'] ?? 0),
        (float)($_POST['fuel_liters'] ?? 0),
        (float)($_POST['fuel_amount'] ?? 0),
        $_POST['repair_summary'] ?? null,
        (float)($_POST['repair_amount'] ?? 0),
        (float)($_POST['downtime_hours'] ?? 0),
        $_POST['status'] ?? 'active',
    ]);
    $logId = (int)$pdo->lastInsertId();
    logi_audit($pdo, 'create_daily_asset_log', 'logistics_asset_daily_log', $logId, 'Suivi journalier', [
        'asset_id' => $_POST['asset_id'] ?? null,
        'driver_id' => $_POST['driver_id'] ?? null,
        'chantier_id' => $chantierId,
    ]);
    logi_redirect('asset_daily_activity.php?ok=1');
}

$allRows = $pdo->query("SELECT l.*, a.designation asset_name, a.asset_code, d.full_name driver_name
    FROM logistics_asset_daily_logs l
    LEFT JOIN logistics_assets a ON a.id = l.asset_id
    LEFT JOIN logistics_drivers d ON d.id = l.driver_id
    ORDER BY l.activity_date DESC, l.id DESC
    LIMIT 600")->fetchAll(PDO::FETCH_ASSOC);
$rows = logi_filter_rows($allRows, ['activity_date','asset_name','asset_code','driver_name','site_label','activity_type','work_summary','repair_summary','status'], $q);

$totals = ['fuel' => 0.0, 'repairs' => 0.0, 'downtime' => 0.0, 'hours' => 0.0, 'km' => 0.0];
foreach ($rows as $row) {
    $totals['fuel'] += (float)($row['fuel_liters'] ?? 0);
    $totals['repairs'] += (float)($row['repair_amount'] ?? 0);
    $totals['downtime'] += (float)($row['downtime_hours'] ?? 0);
    $totals['hours'] += max(0, (float)($row['end_hour_meter'] ?? 0) - (float)($row['start_hour_meter'] ?? 0));
    $totals['km'] += max(0, (float)($row['end_odometer'] ?? 0) - (float)($row['start_odometer'] ?? 0));
}

logi_page_start('Suivi journalier parc', 'Activite quotidienne, consommation, reparations et etat complet des engins et vehicules.');
logi_listing_toolbar('asset_daily_activity.php', 'daily_activity', $q, 'Rechercher date, engin, chauffeur, chantier, activite...');
?>

<section class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-5">
    <div class="rounded-2xl bg-white p-4 shadow-sm"><div class="text-xs uppercase text-slate-500">Lignes</div><div class="mt-2 text-2xl font-semibold"><?= logi_num(count($rows)) ?></div></div>
    <div class="rounded-2xl bg-white p-4 shadow-sm"><div class="text-xs uppercase text-slate-500">Heures</div><div class="mt-2 text-2xl font-semibold"><?= logi_num($totals['hours']) ?></div></div>
    <div class="rounded-2xl bg-white p-4 shadow-sm"><div class="text-xs uppercase text-slate-500">Kilometres</div><div class="mt-2 text-2xl font-semibold"><?= logi_num($totals['km']) ?></div></div>
    <div class="rounded-2xl bg-white p-4 shadow-sm"><div class="text-xs uppercase text-slate-500">Carburant</div><div class="mt-2 text-2xl font-semibold"><?= logi_num($totals['fuel']) ?> L</div></div>
    <div class="rounded-2xl bg-white p-4 shadow-sm"><div class="text-xs uppercase text-slate-500">Reparations</div><div class="mt-2 text-2xl font-semibold"><?= logi_money($totals['repairs']) ?></div></div>
</section>

<?php logi_card_open('Ajouter une activite journaliere', 'Un enregistrement par engin ou vehicule et par jour.'); ?>
<form method="post" class="grid md:grid-cols-3 gap-5">
    <?= logi_input('Date', 'activity_date', date('Y-m-d'), 'date', 'required') ?>
    <?= logi_search_select('Engin / vehicule', 'asset_id', logi_search_options($assets, 'id', 'label', 'Selectionner'), '', 'required', 'Rechercher une ressource') ?>
    <?= logi_search_select('Chauffeur', 'driver_id', logi_search_options($drivers, 'id', 'label', 'Non renseigne'), '', '', 'Rechercher un chauffeur') ?>
    <?= logi_chantier_search_input('Chantier / localisation', 'site_label', $chantiers) ?>
    <?= logi_select('Activite', 'activity_type', ['operation'=>'Operation','transport'=>'Transport','chargement'=>'Chargement','maintenance'=>'Maintenance','reparation'=>'Reparation','arret'=>'Arret','controle'=>'Controle']) ?>
    <?= logi_select('Statut', 'status', ['active'=>'Actif','available'=>'Disponible','busy'=>'En mission','maintenance'=>'Maintenance','breakdown'=>'Panne','inactive'=>'Inactif']) ?>
    <?= logi_input('Compteur heure debut', 'start_hour_meter', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Compteur heure fin', 'end_hour_meter', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Immobilisation (h)', 'downtime_hours', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Kilometrage debut', 'start_odometer', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Kilometrage fin', 'end_odometer', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Carburant (L)', 'fuel_liters', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Montant carburant', 'fuel_amount', '0', 'number', 'step="0.01"') ?>
    <?= logi_input('Montant reparation', 'repair_amount', '0', 'number', 'step="0.01"') ?>
    <div class="md:col-span-3"><?= logi_textarea('Activite realisee', 'work_summary') ?></div>
    <div class="md:col-span-3"><?= logi_textarea('Reparation / panne / remarque technique', 'repair_summary') ?></div>
    <div class="md:col-span-3"><?= logi_submit('Enregistrer le suivi') ?></div>
</form>
<?php logi_card_close(); ?>

<?php logi_card_open('Journal du parc', 'Historique quotidien filtre par recherche'); ?>
<div class="overflow-x-auto">
    <table class="w-full border-separate border-spacing-y-3">
        <thead><tr class="text-left text-xs uppercase tracking-[0.18em] text-slate-500"><th class="px-4">Date</th><th>Ressource</th><th>Chauffeur</th><th>Chantier</th><th>Activite</th><th>Compteurs</th><th>Conso</th><th>Reparation</th><th>Statut</th></tr></thead>
        <tbody>
        <?php foreach($rows as $r): $hours=max(0,(float)$r['end_hour_meter']-(float)$r['start_hour_meter']); $km=max(0,(float)$r['end_odometer']-(float)$r['start_odometer']); ?>
            <tr class="bg-white shadow-sm align-top">
                <td class="px-4 py-4 rounded-l-2xl font-semibold"><?= logi_h($r['activity_date']) ?></td>
                <td><div class="font-semibold"><?= logi_h($r['asset_name']) ?></div><div class="text-xs text-slate-500"><?= logi_h($r['asset_code']) ?></div></td>
                <td><?= logi_h($r['driver_name']) ?></td>
                <td><?= logi_h($r['site_label']) ?></td>
                <td><div class="font-semibold"><?= logi_h($r['activity_type']) ?></div><div class="max-w-xs text-sm text-slate-500"><?= logi_h($r['work_summary']) ?></div></td>
                <td><div><?= logi_num($hours) ?> h</div><div class="text-sm text-slate-500"><?= logi_num($km) ?> km</div></td>
                <td><div><?= logi_num($r['fuel_liters']) ?> L</div><div class="text-sm text-slate-500"><?= logi_money($r['fuel_amount']) ?></div></td>
                <td><div><?= logi_money($r['repair_amount']) ?></div><div class="max-w-xs text-sm text-slate-500"><?= logi_h($r['repair_summary']) ?></div></td>
                <td class="rounded-r-2xl"><?= logi_badge($r['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="py-8 text-center text-slate-400">Aucun suivi trouve.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php logi_card_close(); logi_page_end();
