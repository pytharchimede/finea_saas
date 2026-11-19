<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
require_once __DIR__ . '/../model/AuditTrail.php';
require_once __DIR__ . '/../services/FuelLogisticsService.php';

$pdo = \Database::getConnection();
new FuelLogisticsService($pdo);

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare('
    SELECT v.*, s.name AS station_name, s.address AS station_address, c.code AS chantier_code, c.titre AS chantier_titre,
           a.designation AS asset_name, a.registration_number, d.full_name AS driver_name
    FROM fuel_vouchers v
    LEFT JOIN fuel_partner_stations s ON s.id = v.station_id
    LEFT JOIN chantier_banamur c ON c.id = v.chantier_id
    LEFT JOIN logistics_assets a ON a.id = v.asset_id
    LEFT JOIN logistics_drivers d ON d.id = v.driver_id
    WHERE v.id = :id
    LIMIT 1
');
$stmt->execute(['id' => $id]);
$bon = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$bon) {
    echo 'Bon carburant introuvable.';
    exit;
}

AuditTrail::logVisualEvent('preview', [
    'module' => 'logistique',
    'action' => 'bon_essence_preview',
    'entity_type' => 'fuel_voucher',
    'entity_id' => (string)$id,
    'entity_label' => (string)($bon['voucher_code'] ?? $id),
    'details' => ['amount' => (float)($bon['amount'] ?? 0)],
], $pdo);

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$vehicle = trim((string)($bon['vehicle_label'] ?? ''));
if ($vehicle === '') {
    $vehicle = trim((string)($bon['asset_name'] ?? '') . ' ' . (string)($bon['registration_number'] ?? ''));
}
$driver = trim((string)($bon['driver_name'] ?? ''));
$beneficiary = trim((string)($bon['beneficiary_name'] ?? ''));
if ($beneficiary === '') {
    $beneficiary = $driver;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bon carburant <?= h($bon['voucher_code']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .sheet { box-shadow: none !important; border-color: #111827 !important; }
        }
    </style>
</head>
<body class="bg-slate-100 p-6">
    <div class="mx-auto max-w-3xl">
        <div class="no-print mb-4 flex justify-end">
            <button onclick="window.print()" class="rounded-xl bg-amber-500 px-5 py-3 font-bold text-slate-950 shadow hover:bg-amber-400">Imprimer</button>
        </div>
        <section class="sheet rounded-2xl border-2 border-amber-400 bg-white p-8 shadow-xl">
            <header class="flex items-start justify-between gap-6 border-b border-slate-200 pb-5">
                <div>
                    <div class="text-xs font-bold uppercase tracking-[0.24em] text-amber-600">BANAMUR INDUSTRIES</div>
                    <h1 class="mt-2 text-3xl font-black uppercase text-slate-950">Bon carburant</h1>
                    <p class="mt-1 text-sm text-slate-500">A presenter a la station partenaire avec cachet et signature physique.</p>
                </div>
                <div class="text-right">
                    <div class="text-xs uppercase tracking-[0.2em] text-slate-400">Numero</div>
                    <div class="mt-1 text-2xl font-black text-slate-950"><?= h($bon['voucher_code']) ?></div>
                    <div class="mt-2 text-sm text-slate-500"><?= date('d/m/Y H:i', strtotime((string)($bon['issued_at'] ?? 'now'))) ?></div>
                </div>
            </header>

            <div class="mt-6 grid grid-cols-2 gap-4 text-sm">
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Station</div>
                    <div class="mt-2 font-bold text-slate-900"><?= h($bon['station_name'] ?? '') ?></div>
                    <div class="text-slate-500"><?= h($bon['station_address'] ?? '') ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Chantier</div>
                    <div class="mt-2 font-bold text-slate-900"><?= h(trim((string)($bon['chantier_code'] ?? '') . ' - ' . (string)($bon['chantier_titre'] ?? ''))) ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Beneficiaire</div>
                    <div class="mt-2 font-bold text-slate-900"><?= h($beneficiary) ?></div>
                    <div class="text-slate-500">Demandeur : <?= h($bon['requester_name'] ?? '') ?></div>
                </div>
                <div class="rounded-xl border border-slate-200 p-4">
                    <div class="text-xs font-bold uppercase tracking-[0.16em] text-slate-400">Engin / vehicule</div>
                    <div class="mt-2 font-bold text-slate-900"><?= h($vehicle) ?></div>
                </div>
            </div>

            <div class="mt-6 rounded-2xl bg-amber-50 p-5">
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Montant</div>
                        <div class="mt-2 text-2xl font-black text-slate-950"><?= number_format((float)$bon['amount'], 0, ',', ' ') ?> FCFA</div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Litres</div>
                        <div class="mt-2 text-2xl font-black text-slate-950"><?= number_format((float)$bon['quantity_liters'], 2, ',', ' ') ?></div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-[0.16em] text-amber-700">Prix/L</div>
                        <div class="mt-2 text-2xl font-black text-slate-950"><?= number_format((float)$bon['unit_price'], 0, ',', ' ') ?></div>
                    </div>
                </div>
                <div class="mt-4 text-sm text-slate-700"><span class="font-bold">Motif :</span> <?= h($bon['purpose'] ?? '') ?></div>
            </div>

            <div class="mt-10 grid grid-cols-3 gap-6 text-center text-sm">
                <div>
                    <div class="h-20 border-b border-slate-400"></div>
                    <div class="mt-2 font-bold">Logistique</div>
                </div>
                <div>
                    <div class="h-20 border-b border-slate-400"></div>
                    <div class="mt-2 font-bold">Station</div>
                </div>
                <div>
                    <div class="h-20 border-b border-slate-400"></div>
                    <div class="mt-2 font-bold">Cachet</div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
