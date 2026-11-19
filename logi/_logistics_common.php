<?php
require_once __DIR__ . '/../auth_check.php';
require_once __DIR__ . '/../model/Database.php';
if (file_exists(__DIR__ . '/../model/AuditTrail.php')) {
    require_once __DIR__ . '/../model/AuditTrail.php';
}
if (file_exists(__DIR__ . '/../model/NotificationManager.php')) {
    require_once __DIR__ . '/../model/NotificationManager.php';
}
\AccessManager::requirePageAccess();
$pdo = \Database::getConnection();

// Création auto de la table des demandes logistiques si besoin
if (method_exists($pdo, 'exec')) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS logistics_assets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        asset_type VARCHAR(40) NOT NULL DEFAULT 'engin',
        designation VARCHAR(190) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'available',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logistics_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        type VARCHAR(32) NOT NULL,
        asset_id INT NOT NULL,
        date_start DATETIME NOT NULL,
        date_end DATETIME NULL,
        motif TEXT,
        statut VARCHAR(32) NOT NULL DEFAULT 'en attente',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ajout automatique des colonnes manquantes pour compatibilité formulaire
    $cols = [
        // Pour logistics_requests
        ['table' => 'logistics_requests', 'col' => 'type', 'def' => "VARCHAR(32) NOT NULL DEFAULT ''"],
        ['table' => 'logistics_requests', 'col' => 'asset_id', 'def' => "INT NOT NULL DEFAULT 0"],
        ['table' => 'logistics_requests', 'col' => 'date_start', 'def' => "DATETIME NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'date_end', 'def' => "DATETIME NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'motif', 'def' => "TEXT NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'statut', 'def' => "VARCHAR(32) NOT NULL DEFAULT 'en attente'"],
        ['table' => 'logistics_requests', 'col' => 'request_code', 'def' => "VARCHAR(80) NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'requesting_service', 'def' => "VARCHAR(150) NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'requester_name', 'def' => "VARCHAR(190) NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'site_label', 'def' => "VARCHAR(190) NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'need_type', 'def' => "VARCHAR(60) NOT NULL DEFAULT 'engin'"],
        ['table' => 'logistics_requests', 'col' => 'priority', 'def' => "VARCHAR(40) NOT NULL DEFAULT 'normal'"],
        ['table' => 'logistics_requests', 'col' => 'desired_at', 'def' => "DATETIME NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'description', 'def' => "TEXT NULL DEFAULT NULL"],
        ['table' => 'logistics_requests', 'col' => 'status', 'def' => "VARCHAR(40) NOT NULL DEFAULT 'submitted'"],
        // Pour logistics_assets (pour affichage formulaire)
        ['table' => 'logistics_assets', 'col' => 'type', 'def' => "VARCHAR(32) NULL DEFAULT NULL"],
        ['table' => 'logistics_assets', 'col' => 'statut', 'def' => "VARCHAR(32) NULL DEFAULT NULL"],
        ['table' => 'logistics_assets', 'col' => 'localisation', 'def' => "VARCHAR(190) NULL DEFAULT NULL"],
        ['table' => 'logistics_assets', 'col' => 'current_holder', 'def' => "VARCHAR(190) NULL DEFAULT NULL"],
        ['table' => 'logistics_assets', 'col' => 'designation', 'def' => "VARCHAR(190) NULL DEFAULT NULL"],
    ];
    foreach ($cols as $c) {
        logi_ensure_col($pdo, $c['table'], $c['col'], $c['def']);
    }
}

function logi_db(PDO $pdo): string
{
    static $db = null;
    if ($db === null) $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    return $db;
}
function logi_table_exists(PDO $pdo, string $table): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t');
    $s->execute(['db' => logi_db($pdo), 't' => $table]);
    return (int)$s->fetchColumn() > 0;
}
function logi_col_exists(PDO $pdo, string $table, string $col): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND COLUMN_NAME=:c');
    $s->execute(['db' => logi_db($pdo), 't' => $table, 'c' => $col]);
    return (int)$s->fetchColumn() > 0;
}
function logi_ensure_table(PDO $pdo, string $table, string $sql): void
{
    if (!logi_table_exists($pdo, $table)) $pdo->exec($sql);
}
function logi_ensure_col(PDO $pdo, string $table, string $col, string $def): void
{
    if (!logi_col_exists($pdo, $table, $col)) $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $col) . '` ' . $def);
}
function logi_col_type(PDO $pdo, string $table, string $col): ?string
{
    $s = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t AND COLUMN_NAME=:c');
    $s->execute(['db' => logi_db($pdo), 't' => $table, 'c' => $col]);
    $v = $s->fetchColumn();
    return $v !== false ? strtolower((string)$v) : null;
}
function logi_ensure_varchar_col(PDO $pdo, string $table, string $col, string $def): void
{
    if (strpos((string) logi_col_type($pdo, $table, $col), 'enum(') === 0) $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` MODIFY COLUMN `' . str_replace('`', '', $col) . '` ' . $def);
}
function logi_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function logi_money($n): string
{
    return number_format((float)$n, 0, ',', ' ') . ' F CFA';
}
function logi_num($n): string
{
    return number_format((float)$n, 0, ',', ' ');
}
function logi_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
function logi_actor_context(): array
{
    $name = trim((string) ($_SESSION['prenom'] ?? '') . ' ' . (string) ($_SESSION['nom'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($_SESSION['user_name'] ?? $_SESSION['username'] ?? ''));
    }
    return [
        'user_id' => (int) ($_SESSION['user_id'] ?? 0),
        'actor_name' => $name !== '' ? $name : 'Logistique',
    ];
}
function logi_audit(PDO $pdo, string $action, string $entityType, $entityId = null, string $entityLabel = '', array $details = [], string $eventType = 'logistics_action'): void
{
    if (!class_exists('AuditTrail')) {
        return;
    }
    try {
        AuditTrail::log($eventType, [
            'module' => 'logistique',
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_label' => $entityLabel,
            'result' => 'success',
            'severity' => 'info',
            'details' => $details,
        ], $pdo);
    } catch (Throwable $e) {
        error_log('Logistics audit error: ' . $e->getMessage());
    }
}
function logi_notify_request(PDO $pdo, string $subKey, array $request, array $actor = []): void
{
    if (!class_exists('NotificationManager')) {
        return;
    }
    try {
        (new NotificationManager($pdo))->notifyLogisticsRequest($subKey, $request, $actor ?: logi_actor_context());
    } catch (Throwable $e) {
        error_log('Logistics notification error: ' . $e->getMessage());
    }
}
function logi_notify_asset_event(PDO $pdo, string $subKey, array $payload, array $actor = []): void
{
    if (!class_exists('NotificationManager')) {
        return;
    }
    try {
        (new NotificationManager($pdo))->notifyLogisticsAssetEvent($subKey, $payload, $actor ?: logi_actor_context());
    } catch (Throwable $e) {
        error_log('Logistics asset notification error: ' . $e->getMessage());
    }
}
function logi_badge(string $status): string
{
    $labels = [
        'available' => 'Disponible',
        'busy' => 'En mission',
        'maintenance' => 'Maintenance',
        'breakdown' => 'En panne',
        'inactive' => 'Inactif',
        'submitted' => 'Soumise',
        'details_requested' => 'Details demandes',
        'approved' => 'Accordee',
        'study' => 'En étude',
        'accepted' => 'Acceptée',
        'planned' => 'Planifiée',
        'in_progress' => 'En cours',
        'completed' => 'Terminée',
        'rejected' => 'Rejetée',
        'cancelled' => 'Annulée',
        'critical' => 'Critique',
        'normal' => 'Normal',
        'urgent' => 'Urgent',
        'low' => 'Bas',
        'validated' => 'Validé',
        'open' => 'Ouvert',
        'resolved' => 'Résolu',
        'expired' => 'Expiré',
        'active' => 'Actif'
    ];
    $s = strtolower(trim($status));
    $tone = 'bg-slate-100 text-slate-700 border-slate-200';
    if (in_array($s, ['available', 'active', 'validated', 'completed', 'resolved'], true)) $tone = 'bg-emerald-50 text-emerald-700 border-emerald-100';
    elseif (in_array($s, ['busy', 'planned', 'submitted', 'study', 'details_requested', 'normal'], true)) $tone = 'bg-amber-50 text-amber-700 border-amber-100';
    elseif (in_array($s, ['maintenance', 'in_progress', 'accepted'], true)) $tone = 'bg-sky-50 text-sky-700 border-sky-100';
    elseif (in_array($s, ['breakdown', 'blocked', 'critical', 'urgent', 'expired', 'rejected'], true)) $tone = 'bg-rose-50 text-rose-700 border-rose-100';
    return '<span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold ' . $tone . '">' . logi_h($labels[$s] ?? $status) . '</span>';
}

function logi_ensure_schema(PDO $pdo): void
{
    logi_ensure_table($pdo, 'logistics_assets', "CREATE TABLE `logistics_assets`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`asset_type` VARCHAR(40) NOT NULL DEFAULT 'engin',`asset_code` VARCHAR(80) NULL,`designation` VARCHAR(190) NULL,`registration_number` VARCHAR(80) NULL,`brand` VARCHAR(120) NULL,`model` VARCHAR(120) NULL,`current_site` VARCHAR(190) NULL,`assigned_driver_id` INT UNSIGNED NULL,`hour_meter` DECIMAL(12,2) NOT NULL DEFAULT 0,`odometer` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` VARCHAR(40) NOT NULL DEFAULT 'available',`condition_state` VARCHAR(40) NOT NULL DEFAULT 'operational',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,`updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['asset_type' => "VARCHAR(40) NOT NULL DEFAULT 'engin'", 'asset_code' => 'VARCHAR(80) NULL', 'designation' => 'VARCHAR(190) NULL', 'registration_number' => 'VARCHAR(80) NULL', 'brand' => 'VARCHAR(120) NULL', 'model' => 'VARCHAR(120) NULL', 'current_site' => 'VARCHAR(190) NULL', 'assigned_driver_id' => 'INT UNSIGNED NULL', 'hour_meter' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'odometer' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'available'", 'condition_state' => "VARCHAR(40) NOT NULL DEFAULT 'operational'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'updated_at' => 'DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_assets', $c, $d);
    logi_ensure_varchar_col($pdo, 'logistics_assets', 'asset_type', "VARCHAR(40) NOT NULL DEFAULT 'engin'");
    logi_ensure_varchar_col($pdo, 'logistics_assets', 'condition_state', "VARCHAR(40) NOT NULL DEFAULT 'operational'");

    logi_ensure_table($pdo, 'logistics_drivers', "CREATE TABLE `logistics_drivers`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`personnel_id` INT UNSIGNED NULL,`driver_type` VARCHAR(40) NOT NULL DEFAULT 'internal',`full_name` VARCHAR(190) NULL,`phone` VARCHAR(80) NULL,`license_number` VARCHAR(120) NULL,`license_category` VARCHAR(80) NULL,`license_expires_at` DATE NULL,`service_label` VARCHAR(150) NULL,`availability_status` VARCHAR(40) NOT NULL DEFAULT 'available',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['personnel_id' => 'INT UNSIGNED NULL', 'driver_type' => "VARCHAR(40) NOT NULL DEFAULT 'internal'", 'full_name' => 'VARCHAR(190) NULL', 'phone' => 'VARCHAR(80) NULL', 'license_number' => 'VARCHAR(120) NULL', 'license_category' => 'VARCHAR(80) NULL', 'license_expires_at' => 'DATE NULL', 'service_label' => 'VARCHAR(150) NULL', 'availability_status' => "VARCHAR(40) NOT NULL DEFAULT 'available'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_drivers', $c, $d);

    logi_ensure_table($pdo, 'logistics_requests', "CREATE TABLE `logistics_requests`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`request_code` VARCHAR(80) NULL,`requesting_service` VARCHAR(150) NULL,`requester_name` VARCHAR(190) NULL,`site_label` VARCHAR(190) NULL,`need_type` VARCHAR(60) NOT NULL DEFAULT 'engin',`priority` VARCHAR(40) NOT NULL DEFAULT 'normal',`desired_at` DATETIME NULL,`description` TEXT NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'submitted',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['user_id' => 'INT NULL', 'type' => "VARCHAR(32) NOT NULL DEFAULT ''", 'asset_id' => 'INT NULL', 'stock_item_id' => 'INT UNSIGNED NULL', 'requested_quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'date_start' => 'DATETIME NULL', 'date_end' => 'DATETIME NULL', 'motif' => 'TEXT NULL', 'statut' => "VARCHAR(32) NOT NULL DEFAULT 'en attente'", 'request_code' => 'VARCHAR(80) NULL', 'requesting_service' => 'VARCHAR(150) NULL', 'requester_name' => 'VARCHAR(190) NULL', 'holder_type' => 'VARCHAR(40) NULL', 'holder_label' => 'VARCHAR(190) NULL', 'chantier_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'debourse_id' => 'INT UNSIGNED NULL', 'ligne_debourse_id' => 'INT UNSIGNED NULL', 'debourse_label' => 'VARCHAR(255) NULL', 'need_type' => "VARCHAR(60) NOT NULL DEFAULT 'engin'", 'priority' => "VARCHAR(40) NOT NULL DEFAULT 'normal'", 'desired_at' => 'DATETIME NULL', 'description' => 'TEXT NULL', 'decision_note' => 'TEXT NULL', 'details_requested_note' => 'TEXT NULL', 'processed_by' => 'INT NULL', 'processed_at' => 'DATETIME NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'submitted'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'updated_at' => 'DATETIME NULL DEFAULT NULL'] as $c => $d) logi_ensure_col($pdo, 'logistics_requests', $c, $d);

    logi_ensure_table($pdo, 'logistics_fuel_logs', "CREATE TABLE `logistics_fuel_logs`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`site_label` VARCHAR(190) NULL,`supplier_label` VARCHAR(190) NULL,`quantity_liters` DECIMAL(12,2) NOT NULL DEFAULT 0,`unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,`total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0,`odometer` DECIMAL(12,2) NOT NULL DEFAULT 0,`hour_meter` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` VARCHAR(40) NOT NULL DEFAULT 'validated',`fuel_date` DATE NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'supplier_label' => 'VARCHAR(190) NULL', 'quantity_liters' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'unit_cost' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'total_cost' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'odometer' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'hour_meter' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'validated'", 'fuel_date' => 'DATE NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_fuel_logs', $c, $d);

    logi_ensure_table($pdo, 'logistics_maintenance', "CREATE TABLE `logistics_maintenance`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`asset_id` INT UNSIGNED NULL,`maintenance_type` VARCHAR(60) NOT NULL DEFAULT 'preventive',`title` VARCHAR(190) NULL,`planned_at` DATETIME NULL,`intervention_at` DATETIME NULL,`intervenant_name` VARCHAR(190) NULL,`cost_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`immobilization_hours` DECIMAL(10,2) NOT NULL DEFAULT 0,`status` VARCHAR(40) NOT NULL DEFAULT 'planned',`report` TEXT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['asset_id' => 'INT UNSIGNED NULL', 'maintenance_type' => "VARCHAR(60) NOT NULL DEFAULT 'preventive'", 'title' => 'VARCHAR(190) NULL', 'planned_at' => 'DATETIME NULL', 'intervention_at' => 'DATETIME NULL', 'intervenant_name' => 'VARCHAR(190) NULL', 'cost_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'immobilization_hours' => 'DECIMAL(10,2) NOT NULL DEFAULT 0', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'planned'", 'report' => 'TEXT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_maintenance', $c, $d);

    logi_ensure_table($pdo, 'logistics_incidents', "CREATE TABLE `logistics_incidents`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`site_label` VARCHAR(190) NULL,`incident_type` VARCHAR(80) NULL,`severity` VARCHAR(40) NOT NULL DEFAULT 'normal',`description` TEXT NULL,`corrective_action` TEXT NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'open',`incident_at` DATETIME NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'incident_type' => 'VARCHAR(80) NULL', 'severity' => "VARCHAR(40) NOT NULL DEFAULT 'normal'", 'description' => 'TEXT NULL', 'corrective_action' => 'TEXT NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'open'", 'incident_at' => 'DATETIME NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_incidents', $c, $d);

    logi_ensure_table($pdo, 'logistics_documents', "CREATE TABLE `logistics_documents`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`document_type` VARCHAR(80) NULL,`reference_number` VARCHAR(120) NULL,`issued_at` DATE NULL,`expires_at` DATE NULL,`file_path` VARCHAR(255) NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'document_type' => 'VARCHAR(80) NULL', 'reference_number' => 'VARCHAR(120) NULL', 'issued_at' => 'DATE NULL', 'expires_at' => 'DATE NULL', 'file_path' => 'VARCHAR(255) NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'active'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_documents', $c, $d);

    logi_ensure_table($pdo, 'logistics_suppliers', "CREATE TABLE `logistics_suppliers`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`supplier_type` VARCHAR(80) NULL,`name` VARCHAR(190) NULL,`contact_name` VARCHAR(190) NULL,`phone` VARCHAR(80) NULL,`email` VARCHAR(190) NULL,`address` VARCHAR(255) NULL,`tax_number` VARCHAR(120) NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'active',`notes` TEXT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['supplier_type' => 'VARCHAR(80) NULL', 'name' => 'VARCHAR(190) NULL', 'contact_name' => 'VARCHAR(190) NULL', 'phone' => 'VARCHAR(80) NULL', 'email' => 'VARCHAR(190) NULL', 'address' => 'VARCHAR(255) NULL', 'tax_number' => 'VARCHAR(120) NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'active'", 'notes' => 'TEXT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_suppliers', $c, $d);

    logi_ensure_table($pdo, 'logistics_stock_items', "CREATE TABLE `logistics_stock_items`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`category` VARCHAR(80) NULL,`designation` VARCHAR(190) NULL,`reference_code` VARCHAR(120) NULL,`unit_label` VARCHAR(40) NULL,`current_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,`minimum_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,`location_label` VARCHAR(150) NULL,`preferred_supplier_id` INT UNSIGNED NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['category' => 'VARCHAR(80) NULL', 'designation' => 'VARCHAR(190) NULL', 'reference_code' => 'VARCHAR(120) NULL', 'unit_label' => 'VARCHAR(40) NULL', 'current_quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'minimum_quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'current_stock' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'minimum_stock' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'location_label' => 'VARCHAR(150) NULL', 'preferred_supplier_id' => 'INT UNSIGNED NULL', 'preferred_supplier' => 'VARCHAR(190) NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'active'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_stock_items', $c, $d);
    logi_ensure_table($pdo, 'logistics_stock_movements', "CREATE TABLE `logistics_stock_movements`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`item_id` INT UNSIGNED NULL,`movement_type` VARCHAR(40) NOT NULL DEFAULT 'in',`quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,`site_label` VARCHAR(190) NULL,`reason` VARCHAR(255) NULL,`movement_at` DATETIME NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['item_id' => 'INT UNSIGNED NULL', 'movement_type' => "VARCHAR(40) NOT NULL DEFAULT 'in'", 'quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'site_label' => 'VARCHAR(190) NULL', 'holder_label' => 'VARCHAR(190) NULL', 'request_id' => 'INT UNSIGNED NULL', 'reason' => 'VARCHAR(255) NULL', 'movement_at' => 'DATETIME NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_stock_movements', $c, $d);

    logi_ensure_table($pdo, 'logistics_trip_batches', "CREATE TABLE `logistics_trip_batches`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`batch_code` VARCHAR(80) NULL,`chantier_id` INT UNSIGNED NULL,`site_label` VARCHAR(190) NULL,`debourse_id` INT UNSIGNED NULL,`ligne_debourse_id` INT UNSIGNED NULL,`debourse_label` VARCHAR(255) NULL,`material_label` VARCHAR(150) NULL,`planned_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,`unit_label` VARCHAR(40) NULL,`loading_point` VARCHAR(190) NULL,`delivery_point` VARCHAR(190) NULL,`planned_start_at` DATETIME NULL,`planned_end_at` DATETIME NULL,`responsible_personnel_id` INT UNSIGNED NULL,`responsible_name` VARCHAR(190) NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'planned',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['batch_code' => 'VARCHAR(80) NULL', 'chantier_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'debourse_id' => 'INT UNSIGNED NULL', 'ligne_debourse_id' => 'INT UNSIGNED NULL', 'debourse_label' => 'VARCHAR(255) NULL', 'material_label' => 'VARCHAR(150) NULL', 'planned_quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'unit_label' => 'VARCHAR(40) NULL', 'loading_point' => 'VARCHAR(190) NULL', 'delivery_point' => 'VARCHAR(190) NULL', 'planned_start_at' => 'DATETIME NULL', 'planned_end_at' => 'DATETIME NULL', 'responsible_personnel_id' => 'INT UNSIGNED NULL', 'responsible_name' => 'VARCHAR(190) NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'planned'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_trip_batches', $c, $d);
    logi_ensure_table($pdo, 'logistics_trips', "CREATE TABLE `logistics_trips`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`batch_id` INT UNSIGNED NULL,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`trip_count` INT UNSIGNED NOT NULL DEFAULT 1,`transported_quantity` DECIMAL(12,2) NOT NULL DEFAULT 0,`fuel_liters` DECIMAL(12,2) NOT NULL DEFAULT 0,`fuel_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`road_fees_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`departure_at` DATETIME NULL,`arrival_at` DATETIME NULL,`delivery_note` VARCHAR(150) NULL,`delivery_note_file_path` VARCHAR(255) NULL,`status` VARCHAR(40) NOT NULL DEFAULT 'planned',`observation` TEXT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['batch_id' => 'INT UNSIGNED NULL', 'asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'trip_count' => 'INT UNSIGNED NOT NULL DEFAULT 1', 'transported_quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'fuel_liters' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'fuel_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'road_fees_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'departure_at' => 'DATETIME NULL', 'arrival_at' => 'DATETIME NULL', 'delivery_note' => 'VARCHAR(150) NULL', 'delivery_note_file_path' => 'VARCHAR(255) NULL', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'planned'", 'observation' => 'TEXT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_trips', $c, $d);

    logi_ensure_table($pdo, 'logistics_assignments', "CREATE TABLE `logistics_assignments`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`request_id` INT UNSIGNED NULL,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`site_id` INT UNSIGNED NULL,`site_label` VARCHAR(190) NULL,`mission_title` VARCHAR(190) NOT NULL DEFAULT '',`start_at` DATETIME NULL,`end_at` DATETIME NULL,`status` VARCHAR(50) NOT NULL DEFAULT 'planned',`notes` TEXT NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,`updated_at` DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['request_id' => 'INT UNSIGNED NULL', 'asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'site_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'debourse_id' => 'INT UNSIGNED NULL', 'ligne_debourse_id' => 'INT UNSIGNED NULL', 'debourse_label' => 'VARCHAR(255) NULL', 'executor_personnel_id' => 'INT UNSIGNED NULL', 'executor_name' => 'VARCHAR(190) NULL', 'assistant_personnel_id' => 'INT UNSIGNED NULL', 'assistant_name' => 'VARCHAR(190) NULL', 'mission_title' => "VARCHAR(190) NOT NULL DEFAULT ''", 'start_at' => 'DATETIME NULL', 'end_at' => 'DATETIME NULL', 'actual_started_at' => 'DATETIME NULL', 'actual_completed_at' => 'DATETIME NULL', 'progress_percent' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0', 'blocked_reason' => 'VARCHAR(190) NULL', 'postponed_from' => 'DATETIME NULL', 'status' => "VARCHAR(50) NOT NULL DEFAULT 'planned'", 'notes' => 'TEXT NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', 'updated_at' => 'DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_assignments', $c, $d);
    logi_ensure_table($pdo, 'logistics_assignment_attachments', "CREATE TABLE `logistics_assignment_attachments`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`assignment_id` INT UNSIGNED NOT NULL,`original_name` VARCHAR(255) NULL,`file_path` VARCHAR(255) NOT NULL,`file_size` INT UNSIGNED NOT NULL DEFAULT 0,`mime_type` VARCHAR(120) NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`),KEY `idx_assignment`(`assignment_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['assignment_id' => 'INT UNSIGNED NOT NULL', 'original_name' => 'VARCHAR(255) NULL', 'file_path' => 'VARCHAR(255) NOT NULL', 'file_size' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'mime_type' => 'VARCHAR(120) NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_assignment_attachments', $c, $d);

    logi_ensure_table($pdo, 'logistics_asset_daily_logs', "CREATE TABLE `logistics_asset_daily_logs`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`activity_date` DATE NOT NULL,`asset_id` INT UNSIGNED NULL,`driver_id` INT UNSIGNED NULL,`chantier_id` INT UNSIGNED NULL,`site_label` VARCHAR(190) NULL,`activity_type` VARCHAR(60) NOT NULL DEFAULT 'operation',`work_summary` TEXT NULL,`start_hour_meter` DECIMAL(12,2) NOT NULL DEFAULT 0,`end_hour_meter` DECIMAL(12,2) NOT NULL DEFAULT 0,`start_odometer` DECIMAL(12,2) NOT NULL DEFAULT 0,`end_odometer` DECIMAL(12,2) NOT NULL DEFAULT 0,`fuel_liters` DECIMAL(12,2) NOT NULL DEFAULT 0,`fuel_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`repair_summary` TEXT NULL,`repair_amount` DECIMAL(14,2) NOT NULL DEFAULT 0,`downtime_hours` DECIMAL(10,2) NOT NULL DEFAULT 0,`status` VARCHAR(40) NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['activity_date' => 'DATE NULL', 'asset_id' => 'INT UNSIGNED NULL', 'driver_id' => 'INT UNSIGNED NULL', 'chantier_id' => 'INT UNSIGNED NULL', 'site_label' => 'VARCHAR(190) NULL', 'activity_type' => "VARCHAR(60) NOT NULL DEFAULT 'operation'", 'work_summary' => 'TEXT NULL', 'start_hour_meter' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'end_hour_meter' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'start_odometer' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'end_odometer' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'fuel_liters' => 'DECIMAL(12,2) NOT NULL DEFAULT 0', 'fuel_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'repair_summary' => 'TEXT NULL', 'repair_amount' => 'DECIMAL(14,2) NOT NULL DEFAULT 0', 'downtime_hours' => 'DECIMAL(10,2) NOT NULL DEFAULT 0', 'status' => "VARCHAR(40) NOT NULL DEFAULT 'active'", 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_asset_daily_logs', $c, $d);

    logi_ensure_table($pdo, 'logistics_settings', "CREATE TABLE `logistics_settings`(`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,`setting_key` VARCHAR(120) NOT NULL,`setting_value` TEXT NULL,`setting_group` VARCHAR(80) NULL,`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(`id`),UNIQUE KEY `uk_setting_key`(`setting_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach (['setting_key' => 'VARCHAR(120) NOT NULL', 'setting_value' => 'TEXT NULL', 'setting_group' => 'VARCHAR(80) NULL', 'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $c => $d) logi_ensure_col($pdo, 'logistics_settings', $c, $d);
}

function logi_assets(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM logistics_assets ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
}
function logi_drivers(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM logistics_drivers ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
}
function logi_suppliers(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM logistics_suppliers ORDER BY id DESC LIMIT 300')->fetchAll(PDO::FETCH_ASSOC);
}
function logi_options_assets(PDO $pdo): array
{
    return $pdo->query("SELECT id, CONCAT(COALESCE(asset_code,''),' ',COALESCE(designation,''),' ',COALESCE(registration_number,'')) label FROM logistics_assets ORDER BY designation ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function logi_options_drivers(PDO $pdo): array
{
    return $pdo->query("SELECT id, COALESCE(NULLIF(TRIM(full_name),''), CONCAT('Chauffeur #', id)) label FROM logistics_drivers ORDER BY label ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function logi_options_stock(PDO $pdo): array
{
    return $pdo->query("SELECT id, designation label, unit_label FROM logistics_stock_items ORDER BY designation ASC")->fetchAll(PDO::FETCH_ASSOC);
}
function logi_options_suppliers(PDO $pdo): array
{
    require_once __DIR__.'/../services/SupplierReferenceService.php';
    SupplierReferenceService::ensureLink($pdo,'logistics_suppliers');
    $reference=new SupplierReferenceService($pdo);$rows=[];
    foreach($pdo->query("SELECT id,name label,master_supplier_id FROM logistics_suppliers WHERE status='active' ORDER BY name") as $row){try{$reference->resolve((int)($row['master_supplier_id']??0));$rows[]=$row;}catch(DomainException $e){}}
    return $rows;
}
function logi_setting_value(PDO $pdo, string $key, string $default = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT setting_value FROM logistics_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    } catch (Throwable $e) {
        return $default;
    }
}
function logi_stock_category_options(PDO $pdo): array
{
    $presets = [
        'Pieces detachees',
        'Pneumatiques',
        'Huiles et lubrifiants',
        'Filtres',
        'Batteries',
        'Consommables atelier',
        'Materiaux chantier',
        'EPI et securite',
        'Outillage',
        'Carburant',
        'Autres',
    ];
    $settings = preg_split('/[\r\n,;]+/', logi_setting_value($pdo, 'stock_categories', implode("\n", $presets))) ?: [];
    $existing = [];
    try {
        $existing = $pdo->query("SELECT DISTINCT category FROM logistics_stock_items WHERE TRIM(COALESCE(category,'')) <> '' ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $existing = [];
    }
    $categories = array_values(array_unique(array_filter(array_map('trim', array_merge($presets, $settings, $existing)))));
    sort($categories, SORT_NATURAL | SORT_FLAG_CASE);
    return array_map(static fn(string $category): array => ['value' => $category, 'label' => $category], $categories);
}
function logi_stock_location_options(PDO $pdo): array
{
    $presets = [
        'Magasin central',
        'Atelier mecanique',
        'Base vie',
        'Depot carburant',
        'Zone pieces detachees',
        'Zone pneumatiques',
        'Chantier',
        'Bureau logistique',
        'Conteneur stock',
        'Autres',
    ];
    $settings = preg_split('/[\r\n,;]+/', logi_setting_value($pdo, 'stock_locations', implode("\n", $presets))) ?: [];
    $existing = [];
    try {
        $existing = $pdo->query("SELECT DISTINCT location_label FROM logistics_stock_items WHERE TRIM(COALESCE(location_label,'')) <> '' ORDER BY location_label ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        $existing = [];
    }
    $locations = array_values(array_unique(array_filter(array_map('trim', array_merge($presets, $settings, $existing)))));
    sort($locations, SORT_NATURAL | SORT_FLAG_CASE);
    return array_map(static fn(string $location): array => ['value' => $location, 'label' => $location], $locations);
}
function logi_chantiers(PDO $pdo): array
{
    if (!logi_table_exists($pdo, 'chantier_banamur')) return [];
    $cols = [];
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t');
    $stmt->execute(['db' => logi_db($pdo), 't' => 'chantier_banamur']);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $cols[] = $col;
    $id = in_array('id', $cols, true) ? 'id' : ($cols[0] ?? null);
    if (!$id) return [];
    $code = in_array('code', $cols, true) ? 'code' : (in_array('num_chantier', $cols, true) ? 'num_chantier' : $id);
    $title = in_array('titre', $cols, true) ? 'titre' : (in_array('libelle', $cols, true) ? 'libelle' : (in_array('nom', $cols, true) ? 'nom' : $code));
    $where = in_array('archived_at', $cols, true) ? ' WHERE archived_at IS NULL' : '';
    return $pdo->query("SELECT `$id` id, `$code` code, `$title` titre, TRIM(CONCAT(COALESCE(`$code`,''), ' - ', COALESCE(`$title`,''))) label FROM chantier_banamur$where ORDER BY `$code` ASC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC);
}
function logi_chantier_search_input(string $label, string $name, array $chantiers, string $value = '', string $attrs = ''): string
{
    $options = [];
    foreach ($chantiers as $chantier) {
        $option = trim((string)($chantier['label'] ?? ''));
        if ($option !== '') $options[] = ['value' => $option, 'label' => $option, 'chantier_id' => (int)($chantier['id'] ?? 0)];
    }
    return logi_search_select($label, $name, $options, $value, $attrs, 'Rechercher un chantier');
}
function logi_debourse_options(PDO $pdo): array
{
    if (!logi_table_exists($pdo, 'chantier_banamur') || !logi_table_exists($pdo, 'operation_banamur') || !logi_table_exists($pdo, 'debourse_banamur') || !logi_table_exists($pdo, 'ligne_debourse_banamur')) return [];
    $sql = "SELECT DISTINCT c.id chantier_id,
                   TRIM(CONCAT(COALESCE(c.code,''), ' - ', COALESCE(c.titre,''))) chantier_label,
                   db.id debourse_id,
                   ldb.id ligne_debourse_id,
                   CONCAT('#', ldb.id, ' - ', COALESCE(ldb.categorie,'Déboursé'), ' / ', COALESCE(NULLIF(ldb.designation,''), NULLIF(op.designation,''), 'Sans designation'), ' - ', FORMAT(COALESCE(ldb.montant, db.montant_debourse, 0), 0), ' F CFA') label
            FROM chantier_banamur c
            INNER JOIN operation_banamur op ON op.chantier_id = c.id
            INNER JOIN debourse_banamur db ON db.ligne_devis_id = op.ligne_devis_id
            INNER JOIN ligne_debourse_banamur ldb ON ldb.debourse_id = db.id
            ORDER BY c.code ASC, ldb.designation ASC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $options = [];
    foreach ($rows as $row) {
        $options[] = [
            'value' => (string)$row['ligne_debourse_id'],
            'label' => (string)$row['label'],
            'chantier_id' => (int)$row['chantier_id'],
            'chantier_label' => (string)$row['chantier_label'],
            'debourse_id' => (int)$row['debourse_id'],
        ];
    }
    return $options;
}
function logi_services(PDO $pdo): array
{
    $candidates = ['service', 'serv_bureau_banamur', 'services'];
    foreach ($candidates as $table) {
        if (!logi_table_exists($pdo, $table)) continue;
        $cols = [];
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t');
        $stmt->execute(['db' => logi_db($pdo), 't' => $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $cols[] = $col;
        $id = in_array('id_service', $cols, true) ? 'id_service' : (in_array('id', $cols, true) ? 'id' : ($cols[0] ?? null));
        if (!$id) continue;
        $label = in_array('lib_service', $cols, true) ? 'lib_service' : (in_array('nom_service', $cols, true) ? 'nom_service' : (in_array('service', $cols, true) ? 'service' : (in_array('libelle', $cols, true) ? 'libelle' : $id)));
        return $pdo->query("SELECT `$id` id, `$label` label FROM `$table` WHERE TRIM(COALESCE(`$label`,'')) <> '' ORDER BY `$label` ASC LIMIT 800")->fetchAll(PDO::FETCH_ASSOC);
    }
    return [];
}
function logi_service_search_input(string $label, string $name, array $services, string $value = '', string $attrs = ''): string
{
    $options = [];
    foreach ($services as $service) {
        $option = trim((string)($service['label'] ?? ''));
        if ($option !== '') $options[] = ['value' => $option, 'label' => $option];
    }
    return logi_search_select($label, $name, $options, $value, $attrs, 'Rechercher un service');
}

function logi_search_select(string $label, string $name, array $options, string $value = '', string $attrs = '', string $placeholder = 'Rechercher'): string
{
    static $i = 0;
    $i++;
    $id = 'logi_search_select_' . $i;
    $selectedLabel = '';
    foreach ($options as $option) {
        if ((string)($option['value'] ?? '') === $value) {
            $selectedLabel = (string)($option['label'] ?? '');
            break;
        }
    }
    if ($selectedLabel === '' && $value !== '') $selectedLabel = $value;
    $json = logi_h(json_encode(array_values($options), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]');
    return '<div class="block logi-search-select" id="' . $id . '" data-options="' . $json . '">'
        . '<span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span>'
        . '<input type="hidden" name="' . logi_h($name) . '" value="' . logi_h($value) . '" ' . $attrs . ' data-search-value>'
        . '<div class="relative mt-2">'
        . '<input type="text" value="' . logi_h($selectedLabel) . '" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="' . logi_h($placeholder) . '" autocomplete="off" data-search-input>'
        . '<button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-search-toggle aria-label="Ouvrir la liste"><i class="fas fa-chevron-down text-xs"></i></button>'
        . '<div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-search-menu></div>'
        . '</div></div>';
}
function logi_creatable_search_select(string $label, string $name, array $options, string $value = '', string $attrs = '', string $placeholder = 'Rechercher ou saisir'): string
{
    return str_replace(
        '<div class="block logi-search-select"',
        '<div class="block logi-search-select" data-allow-create="1"',
        logi_search_select($label, $name, $options, $value, $attrs, $placeholder)
    );
}

function logi_multi_search_select(string $label, string $name, array $options, array $values = [], string $placeholder = 'Rechercher'): string
{
    static $i = 0;
    $i++;
    $id = 'logi_multi_search_' . $i;
    $values = array_values(array_filter(array_map('strval', $values), static fn(string $v): bool => $v !== ''));
    $json = logi_h(json_encode(array_values($options), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]');
    $selectedJson = logi_h(json_encode($values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '[]');
    return '<div class="block logi-multi-search-select" id="' . $id . '" data-name="' . logi_h($name) . '" data-options="' . $json . '" data-selected="' . $selectedJson . '">'
        . '<span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span>'
        . '<div class="relative mt-2">'
        . '<div class="min-h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-3 py-2 focus-within:border-orange-300 focus-within:bg-white focus-within:ring-4 focus-within:ring-orange-100">'
        . '<div class="mb-2 flex flex-wrap gap-2" data-multi-badges></div>'
        . '<input type="text" class="h-9 w-full bg-transparent text-slate-800 outline-none" placeholder="' . logi_h($placeholder) . '" autocomplete="off" data-multi-input>'
        . '</div>'
        . '<div class="hidden absolute z-50 mt-2 max-h-72 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-multi-menu></div>'
        . '<div data-multi-hidden></div>'
        . '</div></div>';
}

function logi_search_options(array $rows, string $valueKey = 'id', string $labelKey = 'label', string $emptyLabel = ''): array
{
    $options = [];
    if ($emptyLabel !== '') {
        $options[] = ['value' => '', 'label' => $emptyLabel];
    }
    foreach ($rows as $row) {
        $value = (string) ($row[$valueKey] ?? '');
        $label = trim((string) ($row[$labelKey] ?? $value));
        if ($label !== '') {
            $options[] = ['value' => $value, 'label' => $label];
        }
    }
    return $options;
}

function logi_file_dropzone(string $label, string $name, string $accept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx', string $hint = 'Glisser-deposer un fichier ou cliquer pour choisir'): string
{
    return '<div class="block logi-file-drop">'
        . '<span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span>'
        . '<label class="relative mt-2 flex min-h-[170px] cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center transition hover:border-orange-300 hover:bg-orange-50/70" data-logi-drop-label>'
        . '<input type="file" name="' . logi_h($name) . '" accept="' . logi_h($accept) . '" class="sr-only" data-logi-drop-input>'
        . '<span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-orange-600 shadow-sm"><i class="fas fa-cloud-arrow-up text-xl"></i></span>'
        . '<span class="mt-3 text-sm font-semibold text-slate-800">' . logi_h($hint) . '</span>'
        . '<span class="mt-1 text-xs text-slate-500">PDF, image ou document bureautique</span>'
        . '</label>'
        . '<div class="relative mt-3 hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-sm" data-logi-drop-preview>'
        . '<button type="button" class="absolute -right-2 -top-2 flex h-7 w-7 items-center justify-center rounded-full bg-red-600 text-white shadow-lg hover:bg-red-700" data-logi-drop-remove aria-label="Supprimer le fichier"><i class="fas fa-times text-xs"></i></button>'
        . '<div class="flex items-center gap-3">'
        . '<div class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-100 text-slate-400" data-logi-drop-thumb></div>'
        . '<div class="min-w-0"><div class="truncate text-sm font-semibold text-slate-900" data-logi-drop-name></div><div class="mt-1 text-xs text-slate-500" data-logi-drop-size></div></div>'
        . '</div></div>'
        . '</div>';
}

function logi_file_dropzone_multiple(string $label, string $name, string $accept = 'image/*,.pdf,.doc,.docx,.xls,.xlsx', string $hint = 'Glisser-deposer les fichiers ou cliquer pour choisir'): string
{
    return '<div class="block logi-file-drop logi-file-drop-multiple" data-logi-drop-multiple="1">'
        . '<span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span>'
        . '<label class="relative mt-2 flex min-h-[190px] cursor-pointer flex-col items-center justify-center rounded-3xl border-2 border-dashed border-slate-300 bg-slate-50 px-5 py-7 text-center transition hover:border-orange-300 hover:bg-orange-50/70" data-logi-drop-label>'
        . '<input type="file" name="' . logi_h($name) . '[]" accept="' . logi_h($accept) . '" multiple class="sr-only" data-logi-drop-input>'
        . '<span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-orange-600 shadow-sm"><i class="fas fa-cloud-arrow-up text-2xl"></i></span>'
        . '<span class="mt-3 text-sm font-semibold text-slate-800">' . logi_h($hint) . '</span>'
        . '<span class="mt-1 text-xs text-slate-500">Plusieurs PDF, images ou documents bureautiques acceptes</span>'
        . '</label>'
        . '<div class="mt-4 grid hidden gap-3 sm:grid-cols-2 xl:grid-cols-3" data-logi-drop-list></div>'
        . '</div>';
}

function logi_store_uploaded_file(string $field, string $subdir = 'documents'): ?string
{
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        return null;
    }
    $baseDir = __DIR__ . '/../uploads/logistique/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subdir);
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }
    $original = (string) ($file['name'] ?? 'document');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($original, PATHINFO_FILENAME));
    $safeName = trim((string) $safeName, '_') ?: 'document';
    $filename = $safeName . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
    $target = $baseDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        return null;
    }
    return '../uploads/logistique/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subdir) . '/' . $filename;
}

function logi_store_uploaded_files(string $field, string $subdir = 'documents'): array
{
    $files = $_FILES[$field] ?? null;
    if (!is_array($files) || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }
    $stored = [];
    $count = count($files['name']);
    $baseDir = __DIR__ . '/../uploads/logistique/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subdir);
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0775, true);
    }
    for ($i = 0; $i < $count; $i++) {
        $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $tmp = (string)($files['tmp_name'][$i] ?? '');
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }
        $original = (string)($files['name'][$i] ?? 'document');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($original, PATHINFO_FILENAME));
        $safeName = trim((string)$safeName, '_') ?: 'document';
        $filename = $safeName . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . ($ext !== '' ? '.' . $ext : '');
        $target = $baseDir . '/' . $filename;
        if (!move_uploaded_file($tmp, $target)) {
            continue;
        }
        $stored[] = [
            'original_name' => $original,
            'file_path' => '../uploads/logistique/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $subdir) . '/' . $filename,
            'file_size' => (int)($files['size'][$i] ?? 0),
            'mime_type' => (string)($files['type'][$i] ?? ''),
        ];
    }
    return $stored;
}

function logi_request_status_label(string $status): string
{
    $status = strtolower(trim($status));
    return [
        'submitted' => 'En attente',
        'details_requested' => 'Details demandes',
        'approved' => 'Accordee',
        'rejected' => 'Refusee',
        'in_progress' => 'Remise / en cours',
        'completed' => 'Terminee / recuperee',
        'cancelled' => 'Annulee',
    ][$status] ?? ($status !== '' ? $status : 'En attente');
}

function logi_planning_block_reasons(PDO $pdo): array
{
    $defaults = [
        'Ressource indisponible',
        'Chauffeur indisponible',
        'Panne ou maintenance',
        'Carburant indisponible',
        'Acces chantier bloque',
        'Attente validation',
        'Meteo defavorable',
        'Debourse non confirme',
        'Autre',
    ];
    $raw = logi_setting_value($pdo, 'planning_block_reasons', implode("\n", $defaults));
    $items = preg_split('/[\r\n,;]+/', $raw) ?: [];
    $items = array_values(array_unique(array_filter(array_map('trim', $items))));
    return $items ?: $defaults;
}

function logi_process_planning_action(PDO $pdo, int $assignmentId, string $action, array $payload = []): string
{
    if ($assignmentId <= 0) return '';
    $stmt = $pdo->prepare('SELECT * FROM logistics_assignments WHERE id = ? LIMIT 1');
    $stmt->execute([$assignmentId]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$assignment) return '';
    $action = strtolower(trim($action));
    $progress = max(0, min(100, (int)($payload['progress_percent'] ?? $assignment['progress_percent'] ?? 0)));
    $note = trim((string)($payload['meeting_note'] ?? ''));
    $reason = trim((string)($payload['blocked_reason'] ?? ''));

    if ($action === 'start') {
        $pdo->prepare("UPDATE logistics_assignments SET status='in_progress', actual_started_at=COALESCE(actual_started_at,NOW()), progress_percent=GREATEST(progress_percent,1), notes=TRIM(CONCAT(COALESCE(notes,''), ?)), updated_at=NOW() WHERE id=?")
            ->execute([$note !== '' ? "\n" . date('d/m/Y H:i') . ' - Demarrage: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_start', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), ['progress' => max(1, $progress)]);
        return 'Tache demarree.';
    }
    if ($action === 'progress') {
        $status = $progress >= 100 ? 'completed' : 'in_progress';
        $completed = $progress >= 100 ? ', actual_completed_at=COALESCE(actual_completed_at,NOW())' : '';
        $pdo->prepare("UPDATE logistics_assignments SET status=?, progress_percent=?, notes=TRIM(CONCAT(COALESCE(notes,''), ?)) $completed, updated_at=NOW() WHERE id=?")
            ->execute([$status, $progress, $note !== '' ? "\n" . date('d/m/Y H:i') . ' - Avancement ' . $progress . '%: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_progress', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), ['progress' => $progress]);
        return 'Avancement mis a jour.';
    }
    if ($action === 'complete') {
        $pdo->prepare("UPDATE logistics_assignments SET status='completed', progress_percent=100, actual_completed_at=NOW(), notes=TRIM(CONCAT(COALESCE(notes,''), ?)), updated_at=NOW() WHERE id=?")
            ->execute([$note !== '' ? "\n" . date('d/m/Y H:i') . ' - Terminee: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_complete', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), []);
        return 'Tache terminee.';
    }
    if ($action === 'cancel') {
        $pdo->prepare("UPDATE logistics_assignments SET status='cancelled', notes=TRIM(CONCAT(COALESCE(notes,''), ?)), updated_at=NOW() WHERE id=?")
            ->execute([$note !== '' ? "\n" . date('d/m/Y H:i') . ' - Annulee: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_cancel', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), ['note' => $note]);
        return 'Tache annulee.';
    }
    if ($action === 'block') {
        $pdo->prepare("UPDATE logistics_assignments SET status='blocked', blocked_reason=?, notes=TRIM(CONCAT(COALESCE(notes,''), ?)), updated_at=NOW() WHERE id=?")
            ->execute([$reason !== '' ? $reason : 'Blocage non precise', $note !== '' ? "\n" . date('d/m/Y H:i') . ' - Blocage: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_block', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), ['reason' => $reason]);
        return 'Tache marquee bloquee.';
    }
    if ($action === 'postpone') {
        $newStart = str_replace('T', ' ', trim((string)($payload['postpone_start_at'] ?? '')));
        if ($newStart === '') return 'Date de report obligatoire.';
        $oldStart = (string)($assignment['start_at'] ?? '');
        $oldEnd = (string)($assignment['end_at'] ?? '');
        $newEnd = null;
        if ($oldEnd !== '' && $oldStart !== '') {
            $duration = max(0, strtotime($oldEnd) - strtotime($oldStart));
            $newEnd = date('Y-m-d H:i:s', strtotime($newStart) + $duration);
        }
        $pdo->prepare("UPDATE logistics_assignments SET status='planned', start_at=?, end_at=?, postponed_from=?, progress_percent=0, blocked_reason=NULL, notes=TRIM(CONCAT(COALESCE(notes,''), ?)), updated_at=NOW() WHERE id=?")
            ->execute([$newStart, $newEnd, $oldStart ?: null, $note !== '' ? "\n" . date('d/m/Y H:i') . ' - Report: ' . $note : '', $assignmentId]);
        logi_audit($pdo, 'planning_postpone', 'logistics_assignment', $assignmentId, (string)($assignment['mission_title'] ?? ''), ['from' => $oldStart, 'to' => $newStart]);
        return 'Tache reportee a la nouvelle date.';
    }
    return '';
}

function logi_request_payload(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare("SELECT r.*, a.designation asset_label, a.asset_code, a.registration_number, s.designation stock_label, s.reference_code, s.unit_label
        FROM logistics_requests r
        LEFT JOIN logistics_assets a ON a.id = r.asset_id
        LEFT JOIN logistics_stock_items s ON s.id = r.stock_item_id
        WHERE r.id = ? LIMIT 1");
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    $assetLabel = trim((string)($row['asset_label'] ?? ''));
    if ($assetLabel === '') $assetLabel = trim((string)($row['stock_label'] ?? ''));
    if ($assetLabel === '') $assetLabel = trim((string)($row['description'] ?? 'Besoin logistique'));
    $row['asset_label'] = $assetLabel;
    return $row;
}

function logi_process_request_action(PDO $pdo, int $requestId, string $action, array $payload = []): string
{
    $request = logi_request_payload($pdo, $requestId);
    if (!$request) return '';
    $action = strtolower(trim($action));
    if (!in_array($action, ['approve', 'reject', 'details', 'handover', 'recover'], true)) return '';

    $assetId = (int)($request['asset_id'] ?? 0);
    $stockItemId = (int)($request['stock_item_id'] ?? 0);
    $quantity = max(0, (float)($request['requested_quantity'] ?? 0));
    $note = trim((string)($payload['decision_note'] ?? ''));
    $returnLocation = trim((string)($payload['return_location'] ?? ''));
    $holderLabel = trim((string)($payload['holder_label'] ?? ''));
    if ($holderLabel === '') $holderLabel = trim((string)($request['holder_label'] ?? ''));
    if ($holderLabel === '') $holderLabel = trim((string)($request['requester_name'] ?? ''));
    if ($holderLabel === '') $holderLabel = 'Demande #' . $requestId;
    $siteLabel = trim((string)($request['site_label'] ?? ''));
    $debourseLabel = trim((string)($request['debourse_label'] ?? ''));

    if ($action === 'details') {
        $pdo->prepare("UPDATE logistics_requests SET status='details_requested', statut='details demandes', details_requested_note=?, decision_note=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$note, $note, (int)($_SESSION['user_id'] ?? 0), $requestId]);
        logi_notify_request($pdo, 'details_requested', array_merge($request, ['status' => 'details_requested']), logi_actor_context());
        logi_audit($pdo, 'request_details', 'logistics_request', $requestId, (string)($request['request_code'] ?? ''), ['note' => $note]);
        return 'Details supplementaires demandes.';
    }

    if ($action === 'reject') {
        $pdo->prepare("UPDATE logistics_requests SET status='rejected', statut='refusee', decision_note=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$note, (int)($_SESSION['user_id'] ?? 0), $requestId]);
        logi_notify_request($pdo, 'rejected', array_merge($request, ['status' => 'rejected']), logi_actor_context());
        logi_audit($pdo, 'reject_request', 'logistics_request', $requestId, (string)($request['request_code'] ?? ''), ['note' => $note]);
        return 'Demande refusee.';
    }

    if ($action === 'approve') {
        $pdo->prepare("UPDATE logistics_requests SET status='approved', statut='accordee', holder_label=?, decision_note=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$holderLabel, $note, (int)($_SESSION['user_id'] ?? 0), $requestId]);
        if ($assetId > 0) {
            $pdo->prepare("UPDATE logistics_assets SET status='busy', statut='occupe', current_holder=?, current_site=COALESCE(NULLIF(?,''), current_site), updated_at=NOW() WHERE id=?")
                ->execute([$holderLabel, $siteLabel, $assetId]);
        }
        logi_notify_request($pdo, 'approved', array_merge($request, ['status' => 'approved', 'holder_label' => $holderLabel]), logi_actor_context());
        logi_audit($pdo, 'approve_request', 'logistics_request', $requestId, (string)($request['request_code'] ?? ''), ['asset_id' => $assetId, 'stock_item_id' => $stockItemId, 'quantity' => $quantity]);
        return 'Demande accordee.';
    }

    if ($action === 'handover') {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE logistics_requests SET status='in_progress', statut='remis', holder_label=?, processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
                ->execute([$holderLabel, (int)($_SESSION['user_id'] ?? 0), $requestId]);
            if ($assetId > 0) {
                $pdo->prepare("UPDATE logistics_assets SET status='busy', statut='occupe', current_holder=?, current_site=COALESCE(NULLIF(?,''), current_site), updated_at=NOW() WHERE id=?")
                    ->execute([$holderLabel, $siteLabel, $assetId]);
            }
            if ($stockItemId > 0 && $quantity > 0) {
                $current = (float)$pdo->query("SELECT COALESCE(current_stock,current_quantity,0) FROM logistics_stock_items WHERE id=" . (int)$stockItemId)->fetchColumn();
                if ($current < $quantity) {
                    throw new RuntimeException('Stock insuffisant pour remettre cette demande.');
                }
                $reason = 'Demande logistique ' . (string)($request['request_code'] ?? $requestId) . ($debourseLabel !== '' ? ' - ' . $debourseLabel : '');
                $pdo->prepare("INSERT INTO logistics_stock_movements(item_id,movement_type,quantity,site_label,holder_label,request_id,reason,movement_at,created_at) VALUES(?,?,?,?,?,?,?,NOW(),NOW())")
                    ->execute([$stockItemId, 'affectation', $quantity, $siteLabel ?: null, $holderLabel, $requestId, $reason]);
                $pdo->prepare('UPDATE logistics_stock_items SET current_quantity=current_quantity-?, current_stock=current_stock-? WHERE id=?')->execute([$quantity, $quantity, $stockItemId]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return $e->getMessage();
        }
        logi_notify_request($pdo, 'handover', array_merge($request, ['status' => 'in_progress', 'holder_label' => $holderLabel]), logi_actor_context());
        logi_audit($pdo, 'handover_request', 'logistics_request', $requestId, (string)($request['request_code'] ?? ''), ['asset_id' => $assetId, 'stock_item_id' => $stockItemId, 'quantity' => $quantity, 'holder_label' => $holderLabel]);
        return $stockItemId > 0 ? 'Consommable sorti du stock et trace.' : 'Element remis et position mise a jour.';
    }

    $pdo->prepare("UPDATE logistics_requests SET status='completed', statut='recuperee', processed_by=?, processed_at=NOW(), updated_at=NOW() WHERE id=?")
        ->execute([(int)($_SESSION['user_id'] ?? 0), $requestId]);
    if ($assetId > 0) {
        if ($returnLocation !== '') {
            $pdo->prepare("UPDATE logistics_assets SET status='available', statut='disponible', current_holder=NULL, current_site=?, localisation=?, updated_at=NOW() WHERE id=?")
                ->execute([$returnLocation, $returnLocation, $assetId]);
        } else {
            $pdo->prepare("UPDATE logistics_assets SET status='available', statut='disponible', current_holder=NULL, updated_at=NOW() WHERE id=?")->execute([$assetId]);
        }
    }
    logi_notify_request($pdo, 'recovered', array_merge($request, ['status' => 'completed', 'return_location' => $returnLocation]), logi_actor_context());
    logi_audit($pdo, 'recover_request', 'logistics_request', $requestId, (string)($request['request_code'] ?? ''), ['asset_id' => $assetId, 'return_location' => $returnLocation]);
    return 'Demande terminee et ressource recuperee.';
}

function logi_search_select_assets(): void
{
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.logi-multi-search-select').forEach(function(root) {
        if (root.dataset.ready === '1') return;
        root.dataset.ready = '1';
        const name = root.dataset.name || 'values[]';
        const options = JSON.parse(root.dataset.options || '[]');
        let selected = JSON.parse(root.dataset.selected || '[]').map(String);
        const input = root.querySelector('[data-multi-input]');
        const menu = root.querySelector('[data-multi-menu]');
        const badges = root.querySelector('[data-multi-badges]');
        const hidden = root.querySelector('[data-multi-hidden]');
        const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        function sync() {
            hidden.innerHTML = '';
            badges.innerHTML = '';
            selected.forEach(function(value) {
                const option = options.find(function(item) { return String(item.value) === String(value); }) || {value: value, label: value};
                const h = document.createElement('input');
                h.type = 'hidden';
                h.name = name;
                h.value = option.value;
                hidden.appendChild(h);
                const badge = document.createElement('span');
                badge.className = 'inline-flex items-center gap-2 rounded-full bg-slate-900 px-3 py-1 text-xs font-semibold text-white';
                badge.textContent = option.label;
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'ml-1 text-white/80 hover:text-white';
                remove.innerHTML = '<i class="fas fa-times"></i>';
                remove.addEventListener('click', function() {
                    selected = selected.filter(function(item) { return String(item) !== String(value); });
                    sync();
                    render();
                });
                badge.appendChild(remove);
                badges.appendChild(badge);
            });
        }
        function render() {
            const query = normalize(input.value);
            const matches = options.filter(function(option) {
                return !selected.includes(String(option.value)) && normalize(option.label + ' ' + (option.meta || '')).includes(query);
            }).slice(0, 80);
            menu.innerHTML = '';
            if (!matches.length) {
                menu.innerHTML = '<div class="px-3 py-2 text-sm text-slate-400">Aucun resultat</div>';
            }
            matches.forEach(function(option) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-orange-50 hover:text-orange-700';
                button.textContent = option.label;
                button.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    selected.push(String(option.value));
                    input.value = '';
                    sync();
                    render();
                });
                menu.appendChild(button);
            });
            menu.classList.remove('hidden');
        }
        input.addEventListener('focus', render);
        input.addEventListener('input', render);
        input.addEventListener('blur', function() { window.setTimeout(function() { menu.classList.add('hidden'); }, 150); });
        sync();
    });
    document.querySelectorAll('.logi-search-select').forEach(function(root) {
        if (root.dataset.ready === '1') return;
        root.dataset.ready = '1';
        let options = JSON.parse(root.dataset.options || '[]');
        const hidden = root.querySelector('[data-search-value]');
        const input = root.querySelector('[data-search-input]');
        const menu = root.querySelector('[data-search-menu]');
        const toggle = root.querySelector('[data-search-toggle]');
        const allowCreate = root.dataset.allowCreate === '1';
        let open = false;
        if (hidden && hidden.hasAttribute('required')) {
            input.required = true;
        }

        function normalize(value) {
            return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        }
        function closeMenu() {
            open = false;
            menu.classList.add('hidden');
        }
        function selectOption(option) {
            hidden.value = option.value;
            input.value = option.label;
            input.setCustomValidity('');
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
            input.dispatchEvent(new CustomEvent('logi:selected', { detail: option, bubbles: true }));
            closeMenu();
        }
        function createFromInput() {
            if (!allowCreate) return false;
            const label = String(input.value || '').trim();
            if (!label) return false;
            selectOption({value: label, label: label});
            return true;
        }
        root.addEventListener('logi:update-options', function(event) {
            options = Array.isArray(event.detail && event.detail.options) ? event.detail.options : [];
            root.dataset.options = JSON.stringify(options);
            hidden.value = '';
            input.value = '';
            if (open) render();
        });
        function render() {
            const query = normalize(input.value);
            const matches = options.filter(function(option) {
                return normalize(option.label).includes(query);
            }).slice(0, 80);
            menu.innerHTML = '';
            if (!matches.length) {
                const empty = document.createElement('div');
                empty.className = 'px-3 py-2 text-sm text-slate-400';
                empty.textContent = 'Aucun résultat';
                menu.appendChild(empty);
            }
            matches.forEach(function(option) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'block w-full rounded-xl px-3 py-2 text-left text-sm text-slate-700 hover:bg-orange-50 hover:text-orange-700';
                button.textContent = option.label;
                button.addEventListener('mousedown', function(event) {
                    event.preventDefault();
                    selectOption(option);
                });
                menu.appendChild(button);
            });
            open = true;
            menu.classList.remove('hidden');
        }
        input.addEventListener('focus', render);
        input.addEventListener('input', function() {
            hidden.value = '';
            input.setCustomValidity('');
            render();
        });
        input.addEventListener('keydown', function(event) {
            const first = menu.querySelector('button');
            if (event.key === 'Enter' && open && first) {
                event.preventDefault();
                first.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            }
            if (event.key === 'Escape') closeMenu();
        });
        input.addEventListener('blur', function() {
            window.setTimeout(function() {
                if (!hidden.value) input.value = '';
                closeMenu();
            }, 120);
        });
        toggle.addEventListener('click', function() {
            input.focus();
            render();
        });
        const form = root.closest('form');
        if (form) {
            form.addEventListener('submit', function(event) {
                if (hidden.hasAttribute('required') && !hidden.value) {
                    input.setCustomValidity('Veuillez selectionner une valeur dans la liste.');
                    input.reportValidity();
                    event.preventDefault();
                }
            });
        }
    });
});
</script>
HTML;
}

function logi_file_dropzone_assets(): void
{
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.logi-file-drop').forEach(function(root) {
        if (root.dataset.ready === '1') return;
        root.dataset.ready = '1';
        const input = root.querySelector('[data-logi-drop-input]');
        const label = root.querySelector('[data-logi-drop-label]');
        const isMultiple = root.dataset.logiDropMultiple === '1';
        const list = root.querySelector('[data-logi-drop-list]');
        const preview = root.querySelector('[data-logi-drop-preview]');
        const thumb = root.querySelector('[data-logi-drop-thumb]');
        const nameEl = root.querySelector('[data-logi-drop-name]');
        const sizeEl = root.querySelector('[data-logi-drop-size]');
        const remove = root.querySelector('[data-logi-drop-remove]');

        function sizeLabel(size) {
            if (!size) return '';
            if (size < 1024 * 1024) return Math.round(size / 1024) + ' Ko';
            return (size / 1024 / 1024).toFixed(1) + ' Mo';
        }
        function fileIcon(file) {
            if (file.type && file.type.indexOf('image/') === 0) return 'image';
            if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name || '')) return 'pdf';
            return 'doc';
        }
        function syncMultipleFiles(files) {
            const transfer = new DataTransfer();
            files.forEach(function(file) { transfer.items.add(file); });
            input.files = transfer.files;
        }
        function renderList() {
            if (!isMultiple || !list) return;
            const files = Array.from(input.files || []);
            list.innerHTML = '';
            list.classList.toggle('hidden', files.length === 0);
            files.forEach(function(file, index) {
                const card = document.createElement('div');
                card.className = 'relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-3 shadow-sm';
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'absolute right-2 top-2 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-red-600 text-white shadow-lg hover:bg-red-700';
                removeBtn.innerHTML = '<i class="fas fa-times text-xs"></i>';
                removeBtn.setAttribute('aria-label', 'Supprimer le fichier');
                removeBtn.addEventListener('click', function() {
                    const kept = Array.from(input.files || []).filter(function(_, i) { return i !== index; });
                    syncMultipleFiles(kept);
                    renderList();
                });
                const media = document.createElement('div');
                media.className = 'mb-3 flex h-28 items-center justify-center overflow-hidden rounded-xl bg-slate-100 text-slate-400';
                const kind = fileIcon(file);
                if (kind === 'image') {
                    const img = document.createElement('img');
                    img.className = 'h-full w-full object-cover';
                    img.alt = file.name;
                    img.src = URL.createObjectURL(file);
                    media.appendChild(img);
                } else {
                    const icon = document.createElement('i');
                    icon.className = kind === 'pdf' ? 'fas fa-file-pdf text-4xl text-red-500' : 'fas fa-file-lines text-4xl text-slate-500';
                    media.appendChild(icon);
                }
                const name = document.createElement('div');
                name.className = 'truncate text-sm font-semibold text-slate-900';
                name.textContent = file.name;
                const size = document.createElement('div');
                size.className = 'mt-1 text-xs text-slate-500';
                size.textContent = sizeLabel(file.size);
                card.appendChild(removeBtn);
                card.appendChild(media);
                card.appendChild(name);
                card.appendChild(size);
                list.appendChild(card);
            });
        }
        function render(file) {
            if (isMultiple) {
                renderList();
                return;
            }
            if (!file) {
                preview.classList.add('hidden');
                thumb.innerHTML = '';
                nameEl.textContent = '';
                sizeEl.textContent = '';
                return;
            }
            preview.classList.remove('hidden');
            nameEl.textContent = file.name;
            sizeEl.textContent = sizeLabel(file.size);
            thumb.innerHTML = '';
            if (file.type && file.type.indexOf('image/') === 0) {
                const img = document.createElement('img');
                img.className = 'h-full w-full object-cover';
                img.alt = file.name;
                img.src = URL.createObjectURL(file);
                thumb.appendChild(img);
                return;
            }
            const icon = document.createElement('i');
            icon.className = file.type === 'application/pdf' ? 'fas fa-file-pdf text-3xl text-red-500' : 'fas fa-file-lines text-3xl text-slate-500';
            thumb.appendChild(icon);
        }
        input.addEventListener('change', function() {
            if (isMultiple) renderList();
            else render(input.files && input.files[0]);
        });
        ['dragenter', 'dragover'].forEach(function(eventName) {
            label.addEventListener(eventName, function(event) {
                event.preventDefault();
                label.classList.add('border-orange-400', 'bg-orange-50');
            });
        });
        ['dragleave', 'drop'].forEach(function(eventName) {
            label.addEventListener(eventName, function(event) {
                event.preventDefault();
                label.classList.remove('border-orange-400', 'bg-orange-50');
            });
        });
        label.addEventListener('drop', function(event) {
            const dropped = event.dataTransfer && event.dataTransfer.files ? Array.from(event.dataTransfer.files) : [];
            if (!dropped.length) return;
            const transfer = new DataTransfer();
            if (isMultiple) {
                Array.from(input.files || []).concat(dropped).forEach(function(file) { transfer.items.add(file); });
            } else {
                transfer.items.add(dropped[0]);
            }
            input.files = transfer.files;
            if (isMultiple) renderList();
            else render(dropped[0]);
        });
        if (remove) {
            remove.addEventListener('click', function() {
                input.value = '';
                render(null);
            });
        }
    });
});
</script>
HTML;
}

function logi_native_select_search_assets(): void
{
    static $printed = false;
    if ($printed) return;
    $printed = true;
    echo <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('form select:not([multiple])').forEach(function(select, index) {
        if (select.dataset.searchEnhanced === '1' || select.closest('.logi-search-select')) return;
        select.dataset.searchEnhanced = '1';
        select.classList.add('hidden');
        const root = document.createElement('div');
        root.className = 'logi-search-select';
        const labelText = (select.closest('label') && select.closest('label').querySelector('span')) ? select.closest('label').querySelector('span').textContent : 'Selection';
        const options = Array.from(select.options).map(function(option) {
            return { value: option.value, label: option.textContent || option.value };
        });
        root.dataset.options = JSON.stringify(options);
        root.innerHTML = '<span class="text-sm font-semibold text-slate-700">' + labelText + '</span><input type="hidden" data-search-value><div class="relative mt-2"><input type="text" class="w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 pr-10 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition" placeholder="Rechercher ou selectionner" autocomplete="off" data-search-input><button type="button" class="absolute right-2 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl text-slate-500 hover:bg-slate-100" data-search-toggle aria-label="Ouvrir la liste"><i class="fas fa-chevron-down text-xs"></i></button><div class="hidden absolute z-50 mt-2 max-h-64 w-full overflow-auto rounded-2xl border border-slate-200 bg-white p-2 shadow-xl" data-search-menu></div></div>';
        select.parentNode.insertBefore(root, select);
        const hidden = root.querySelector('[data-search-value]');
        const input = root.querySelector('[data-search-input]');
        hidden.value = select.value || '';
        const selected = options.find(function(option) { return option.value === select.value; });
        input.value = selected ? selected.label : '';
        hidden.addEventListener('change', function() {
            select.value = hidden.value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        });
    });
});
</script>
HTML;
}

function logi_rh_personnel(PDO $pdo): array
{
    $candidates = ['personnel', 'employes', 'employees', 'employe', 'personnels'];
    foreach ($candidates as $table) {
        if (!logi_table_exists($pdo, $table)) continue;
        $cols = [];
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=:db AND TABLE_NAME=:t');
        $stmt->execute(['db' => logi_db($pdo), 't' => $table]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $col) $cols[] = $col;
        $id = in_array('id', $cols, true) ? 'id' : (in_array('id_personnel_soignant', $cols, true) ? 'id_personnel_soignant' : ($cols[0] ?? null));
        if (!$id) continue;
        $name = in_array('nom_prenoms', $cols, true) ? 'nom_prenoms' : (in_array('nom_personnel_soignant', $cols, true) ? 'nom_personnel_soignant' : (in_array('full_name', $cols, true) ? 'full_name' : (in_array('nom', $cols, true) ? 'nom' : $id)));
        $phone = in_array('telephone', $cols, true) ? 'telephone' : (in_array('tel_personnel_soignant', $cols, true) ? 'tel_personnel_soignant' : (in_array('telephone_personnel_soignant', $cols, true) ? 'telephone_personnel_soignant' : (in_array('phone', $cols, true) ? 'phone' : 'NULL')));
        $directService = in_array('service', $cols, true) ? 'service' : (in_array('lib_service', $cols, true) ? 'lib_service' : 'NULL');
        if (in_array('service_id', $cols, true) && logi_table_exists($pdo, 'service')) {
            return $pdo->query("SELECT p.`$id` id, p.`$name` full_name, " . ($phone === 'NULL' ? 'NULL phone' : "p.`$phone` phone") . ", COALESCE(s.lib_service,'') service_label FROM `$table` p LEFT JOIN service s ON s.id_service = p.service_id ORDER BY p.`$name` ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
        }
        return $pdo->query("SELECT `$id` id, `$name` full_name, " . ($phone === 'NULL' ? 'NULL phone' : "`$phone` phone") . ", " . ($directService === 'NULL' ? 'NULL service_label' : "`$directService` service_label") . " FROM `$table` ORDER BY `$name` ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
    }
    return [];
}

function logi_quick_access_links(): array
{
    return [
        ['href' => 'dashboard_logistique.php', 'icon' => 'fa-chart-line', 'label' => 'Dashboard'],
        ['href' => 'requests.php', 'icon' => 'fa-list-check', 'label' => 'Demandes'],
        ['href' => 'assets.php', 'icon' => 'fa-truck-monster', 'label' => 'Parc'],
        ['href' => 'drivers.php', 'icon' => 'fa-id-card', 'label' => 'Chauffeurs'],
        ['href' => 'planning.php', 'icon' => 'fa-calendar-days', 'label' => 'Planning'],
        ['href' => 'trip_batches.php', 'icon' => 'fa-route', 'label' => 'Vagues'],
        ['href' => 'trip_rotations.php', 'icon' => 'fa-repeat', 'label' => 'Rotations'],
        ['href' => 'asset_daily_activity.php', 'icon' => 'fa-calendar-day', 'label' => 'Suivi jour'],
        ['href' => 'fuel.php', 'icon' => 'fa-gas-pump', 'label' => 'Carburant'],
        ['href' => 'stock.php', 'icon' => 'fa-boxes-stacked', 'label' => 'Stock'],
        ['href' => 'maintenance.php', 'icon' => 'fa-screwdriver-wrench', 'label' => 'Maintenance'],
        ['href' => 'incidents.php', 'icon' => 'fa-triangle-exclamation', 'label' => 'Incidents'],
        ['href' => 'documents.php', 'icon' => 'fa-file-shield', 'label' => 'Documents'],
        ['href' => 'suppliers.php', 'icon' => 'fa-warehouse', 'label' => 'Fournisseurs'],
        ['href' => 'settings.php', 'icon' => 'fa-sliders', 'label' => 'Parametres'],
    ];
}

function logi_quick_access_nav(): string
{
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $html = '<nav class="mb-8 overflow-x-auto rounded-2xl border border-white bg-white/90 p-2 shadow-sm shadow-slate-200/70"><div class="flex min-w-max gap-2">';
    foreach (logi_quick_access_links() as $link) {
        $active = $current === $link['href'];
        $class = $active
            ? 'bg-slate-900 text-white shadow-sm'
            : 'bg-slate-50 text-slate-700 hover:bg-orange-50 hover:text-orange-700';
        $html .= '<a href="' . logi_h($link['href']) . '" class="inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold transition ' . $class . '"><i class="fas ' . logi_h($link['icon']) . ' text-xs"></i><span>' . logi_h($link['label']) . '</span></a>';
    }
    return $html . '</div></nav>';
}

function logi_quick_access_sidebar(): string
{
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $html = '<aside class="rounded-[30px] border border-slate-200 bg-slate-950 p-6 text-white shadow-[0_22px_70px_rgba(15,23,42,0.24)] xl:sticky xl:top-6 xl:self-start">';
    $html .= '<div class="mb-5"><p class="text-xs font-semibold uppercase tracking-[0.28em] text-amber-200">Acces rapides</p><h2 class="mt-2 text-2xl font-semibold">Logistique</h2><p class="mt-2 text-sm text-slate-300">Navigation directe sans surcharger la zone de travail.</p></div>';
    $html .= '<div class="space-y-3">';
    foreach (logi_quick_access_links() as $index => $link) {
        $active = $current === $link['href'];
        $class = $active
            ? 'border-amber-400/40 bg-amber-500/15'
            : ($index < 2 ? 'border-amber-400/25 bg-amber-500/10 hover:bg-amber-500/15' : 'border-white/10 bg-white/5 hover:bg-white/10');
        $html .= '<a href="' . logi_h($link['href']) . '" class="block rounded-2xl border px-4 py-4 transition ' . $class . '">';
        $html .= '<div class="flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-white/10 text-[#fabd02]"><i class="fas ' . logi_h($link['icon']) . '"></i></span>';
        $html .= '<span class="min-w-0"><span class="block truncate font-semibold">' . logi_h($link['label']) . '</span><span class="text-sm text-slate-400">' . ($active ? 'Page courante' : 'Ouvrir') . '</span></span></div>';
        $html .= '</a>';
    }
    return $html . '</div></aside>';
}

function logi_attention_count(PDO $pdo): int
{
    $total = 0;
    try {
        $total += (int) $pdo->query("SELECT COUNT(*) FROM logistics_requests WHERE priority IN ('urgent','high','critique','critical') AND COALESCE(NULLIF(status,''), NULLIF(statut,''), '') NOT IN ('completed','recuperee','rejected','refusee','cancelled')")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $total += (int) $pdo->query("SELECT COUNT(*) FROM logistics_incidents WHERE severity IN ('critical','high','critique') AND status IN ('open','in_progress')")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $total += (int) $pdo->query("SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(status, statut) IN ('breakdown','panne','unavailable')")->fetchColumn();
    } catch (Throwable $e) {
    }
    try {
        $total += (int) $pdo->query("SELECT COUNT(*) FROM logistics_documents WHERE expires_at IS NOT NULL AND expires_at < CURDATE()")->fetchColumn();
    } catch (Throwable $e) {
    }
    return $total;
}

function logi_page_start(string $title, string $subtitle, string $icon = 'fa-truck-ramp-box'): void
{
    global $pageTitle, $activeMenu, $pdo;
    $pageTitle = $title;
    $activeMenu = 'logistique';
    require_once __DIR__ . '/../decaissement/includes/header.php';
    require_once __DIR__ . '/../decaissement/includes/navbar.php';
    $attention = isset($pdo) && $pdo instanceof PDO ? logi_attention_count($pdo) : 0;
    echo '<div class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(255,247,237,0.95),_transparent_42%),radial-gradient(circle_at_top_right,_rgba(219,234,254,0.75),_transparent_35%),linear-gradient(180deg,_#f8fafc_0%,_#fff7ed_100%)] px-6 pb-14"><div class="mx-auto max-w-[1500px] pt-6">';
    echo '<section class="relative mb-7 overflow-hidden rounded-[30px] border border-slate-900/10 bg-gradient-to-r from-slate-900 via-[#1d2b57] to-slate-800 px-7 py-8 text-white shadow-[0_18px_60px_rgba(15,23,42,0.24)]">';
    echo '<div class="absolute -right-16 -top-20 h-48 w-48 rounded-full bg-[#fabd02]/18 blur-2xl"></div><div class="absolute bottom-0 right-28 h-24 w-24 rounded-full bg-sky-300/15 blur-2xl"></div>';
    echo '<div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">';
    echo '<div class="max-w-3xl"><div class="mb-3 flex flex-wrap items-center gap-3"><p class="text-xs font-semibold uppercase tracking-[0.32em] text-amber-200">Module logistique</p><span class="inline-flex items-center rounded-full border border-white/15 bg-white/10 px-3 py-1 text-xs font-semibold text-white"><i class="fas fa-bell mr-2 text-[#fabd02]"></i>' . logi_h(logi_num($attention)) . ' point' . ($attention > 1 ? 's' : '') . ' a surveiller</span></div>';
    echo '<h1 class="text-3xl font-semibold tracking-tight md:text-4xl">' . logi_h($title) . '</h1><p class="mt-3 max-w-2xl text-sm leading-6 text-slate-200">' . logi_h($subtitle) . '</p></div>';
    echo '<div class="flex flex-wrap gap-3"><a href="requests.php" class="inline-flex items-center rounded-2xl bg-white px-5 py-3 text-sm font-semibold text-slate-900 shadow-lg shadow-slate-950/20 transition hover:-translate-y-0.5 hover:bg-amber-50"><i class="fas fa-list-check mr-2 text-orange-600"></i>Validations</a><a href="planning_form.php" class="inline-flex items-center rounded-2xl bg-[#fabd02] px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-amber-500/20 transition hover:-translate-y-0.5 hover:bg-amber-300"><i class="fas fa-plus mr-2"></i>Affecter</a><a href="asset_daily_activity.php" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-calendar-day mr-2"></i>Suivi journalier</a><a href="export_logistique.php?dataset=summary&format=pdf" class="inline-flex items-center rounded-2xl border border-white/15 px-5 py-3 text-sm font-semibold text-white/90 transition hover:bg-white/10"><i class="fas fa-file-pdf mr-2"></i>Rapport PDF</a></div>';
    echo '</div></section>';
    echo '<div class="grid gap-6 xl:grid-cols-[1fr_320px]"><main class="min-w-0">';
}
function logi_page_end(): void
{
    echo '</main>' . logi_quick_access_sidebar() . '</div></div></div>';
    logi_native_select_search_assets();
    logi_search_select_assets();
    logi_file_dropzone_assets();
    require_once __DIR__ . '/../decaissement/includes/footer.php';
}
function logi_card_open(string $title, string $subtitle = '', string $right = ''): void
{
    echo '<section class="rounded-[1.75rem] border border-white/60 bg-white/90 p-6 shadow-sm mb-6"><div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-6"><div><p class="text-xs uppercase tracking-[0.22em] text-orange-600 font-semibold">' . logi_h($title) . '</p>' . ($subtitle ? '<h2 class="mt-2 text-2xl font-semibold text-slate-900">' . logi_h($subtitle) . '</h2>' : '') . '</div>' . $right . '</div>';
}
function logi_card_close(): void
{
    echo '</section>';
}
function logi_input($label, $name, $value = '', $type = 'text', $attrs = ''): string
{
    return '<label class="block"><span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span><input type="' . $type . '" name="' . logi_h($name) . '" value="' . logi_h($value) . '" ' . $attrs . ' class="mt-2 w-full h-12 rounded-2xl border border-slate-200 bg-slate-50/80 px-4 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition"></label>';
}
function logi_select($label, $name, array $options, $value = '', $attrs = ''): string
{
    $searchOptions = [];
    foreach ($options as $k => $v) {
        $searchOptions[] = ['value' => (string) $k, 'label' => (string) $v];
    }
    return logi_search_select($label, $name, $searchOptions, (string) $value, $attrs, 'Rechercher ou selectionner');
}
function logi_textarea($label, $name, $value = '', $attrs = ''): string
{
    return '<label class="block"><span class="text-sm font-semibold text-slate-700">' . logi_h($label) . '</span><textarea name="' . logi_h($name) . '" ' . $attrs . ' class="mt-2 w-full min-h-[120px] rounded-2xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-slate-800 outline-none focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100 transition">' . logi_h($value) . '</textarea></label>';
}
function logi_submit($label = 'Enregistrer'): string
{
    return '<div class="flex justify-end gap-3 pt-2"><button type="reset" class="px-5 py-3 rounded-2xl border border-slate-200 bg-white text-slate-700 font-semibold hover:shadow-md transition">Réinitialiser</button><button type="submit" class="px-6 py-3 rounded-2xl bg-orange-600 text-white font-semibold shadow-lg shadow-orange-600/20 hover:bg-orange-700 transition">' . logi_h($label) . '</button></div>';
}
function logi_query_value(string $key = 'q'): string
{
    return trim((string)($_GET[$key] ?? ''));
}

function logi_filter_rows(array $rows, array $fields, string $query): array
{
    $lower = static fn(string $value): string => function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $needle = $lower(trim($query));
    if ($needle === '') return $rows;
    return array_values(array_filter($rows, static function (array $row) use ($fields, $needle, $lower): bool {
        $haystack = '';
        foreach ($fields as $field) $haystack .= ' ' . (string)($row[$field] ?? '');
        return str_contains($lower($haystack), $needle);
    }));
}

function logi_listing_toolbar(string $addHref, string $dataset, string $q = '', string $placeholder = 'Rechercher'): void
{
    $params = $q !== '' ? '&q=' . rawurlencode($q) : '';
    echo '<section class="mb-6 rounded-[1.5rem] border border-white/70 bg-white/95 p-4 shadow-sm">';
    echo '<form method="get" class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_auto_auto_auto] lg:items-center">';
    echo '<div class="relative"><i class="fas fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i><input name="q" value="' . logi_h($q) . '" placeholder="' . logi_h($placeholder) . '" class="h-12 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-slate-800 outline-none transition focus:border-orange-300 focus:bg-white focus:ring-4 focus:ring-orange-100"></div>';
    echo '<button class="h-12 rounded-2xl bg-slate-900 px-5 text-sm font-semibold text-white hover:bg-slate-800" type="submit"><i class="fas fa-filter mr-2"></i>Rechercher</button>';
    echo '<a class="inline-flex h-12 items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:shadow-sm" href="' . logi_h(basename((string)($_SERVER['PHP_SELF'] ?? ''))) . '">Reinitialiser</a>';
    echo '<a class="inline-flex h-12 items-center justify-center rounded-2xl bg-orange-600 px-5 text-sm font-semibold text-white hover:bg-orange-700" href="' . logi_h($addHref) . '"><i class="fas fa-plus mr-2"></i>Ajouter</a>';
    echo '</form><div class="mt-3 flex flex-wrap justify-end gap-2">';
    echo '<a class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700" href="export_logistique.php?dataset=' . logi_h($dataset) . '&format=xlsx' . logi_h($params) . '"><i class="fas fa-file-excel mr-2"></i>Excel ' . ($q !== '' ? 'recherche' : 'global') . '</a>';
    echo '<a class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700" href="export_logistique.php?dataset=' . logi_h($dataset) . '&format=pdf' . logi_h($params) . '"><i class="fas fa-file-pdf mr-2"></i>PDF ' . ($q !== '' ? 'recherche' : 'global') . '</a>';
    echo '</div></section>';
}

logi_ensure_schema($pdo);

