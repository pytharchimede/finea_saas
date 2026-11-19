<?php
// Desactiver les bons d'essence sans recu (img_recu_station vide / NULL) et encore actifs.

date_default_timezone_set('Africa/Abidjan');
require_once __DIR__ . '/../services/FuelVoucherMaintenanceService.php';

try {
    // Pour un delai de grace (ex: 6 heures), utiliser:
    // $conditionsExtra = " AND date_demande <= (NOW() - INTERVAL 6 HOUR)";
    $conditionsExtra = '';

    $service = new FuelVoucherMaintenanceService(new FuelVoucherMaintenanceRepository());
    $result = $service->deactivateWithoutReceipt($conditionsExtra);
    $toDisable = (int)($result['to_disable'] ?? 0);

    if ($toDisable === 0) {
        echo "[INFO] Aucun bon a desactiver." . PHP_EOL;
        exit(0);
    }

    $affected = (int)($result['affected'] ?? 0);
    echo "[OK] $affected / $toDisable bon(s) desactive(s)." . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    echo '[ERREUR] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
