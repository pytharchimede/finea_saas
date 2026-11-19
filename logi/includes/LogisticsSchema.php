<?php

declare(strict_types=1);

/**
 * Schéma auto-évolutif du module Logistique FINEA.
 * À terme, déplacer l'appel ensure() dans un bootstrap/migration global.
 */
final class LogisticsSchema
{
    public static function ensure(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        self::ensureAssets($pdo);
        self::ensureDrivers($pdo);
        self::ensureRequests($pdo);
        self::ensureAssignments($pdo);
        self::ensureStock($pdo);
        self::ensureFuel($pdo);
        self::ensureMaintenance($pdo);
        self::ensureIncidents($pdo);
        self::ensureDocuments($pdo);
        self::ensureSuppliers($pdo);
        self::ensureTrips($pdo);
        self::ensureSettings($pdo);
        self::ensurePermissionsColumns($pdo);
    }

    private static function ensureTable(PDO $pdo, string $table, string $ddl): void
    {
        $pdo->exec($ddl);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
        $stmt->execute(['column' => $column]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    private static function ensureIndex(PDO $pdo, string $table, string $index, string $definition): void
    {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :index_name");
        $stmt->execute(['index_name' => $index]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $pdo->exec("ALTER TABLE `$table` ADD INDEX `$index` ($definition)");
        }
    }

    private static function ensureAssets(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_assets', "CREATE TABLE IF NOT EXISTS logistics_assets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_code VARCHAR(80) NOT NULL,
            asset_type VARCHAR(40) NOT NULL DEFAULT 'engin',
            category VARCHAR(120) NULL,
            designation VARCHAR(180) NOT NULL,
            brand VARCHAR(120) NULL,
            model VARCHAR(120) NULL,
            registration_number VARCHAR(80) NULL,
            serial_number VARCHAR(120) NULL,
            acquisition_date DATE NULL,
            current_site_id INT NULL,
            current_driver_id INT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'disponible',
            condition_state VARCHAR(50) NOT NULL DEFAULT 'bon',
            odometer_km DECIMAL(12,2) NOT NULL DEFAULT 0,
            hour_meter DECIMAL(12,2) NOT NULL DEFAULT 0,
            fuel_capacity DECIMAL(12,2) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach ([
            'asset_code'=>"VARCHAR(80) NOT NULL DEFAULT ''", 'asset_type'=>"VARCHAR(40) NOT NULL DEFAULT 'engin'", 'category'=>"VARCHAR(120) NULL", 'designation'=>"VARCHAR(180) NOT NULL DEFAULT ''", 'brand'=>"VARCHAR(120) NULL", 'model'=>"VARCHAR(120) NULL", 'registration_number'=>"VARCHAR(80) NULL", 'serial_number'=>"VARCHAR(120) NULL", 'acquisition_date'=>"DATE NULL", 'current_site_id'=>"INT NULL", 'current_driver_id'=>"INT NULL", 'status'=>"VARCHAR(50) NOT NULL DEFAULT 'disponible'", 'condition_state'=>"VARCHAR(50) NOT NULL DEFAULT 'bon'", 'odometer_km'=>"DECIMAL(12,2) NOT NULL DEFAULT 0", 'hour_meter'=>"DECIMAL(12,2) NOT NULL DEFAULT 0", 'fuel_capacity'=>"DECIMAL(12,2) NOT NULL DEFAULT 0", 'notes'=>"TEXT NULL", 'created_by'=>"INT NULL", 'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP", 'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"
        ] as $c=>$d) self::ensureColumn($pdo,'logistics_assets',$c,$d);
        self::ensureIndex($pdo,'logistics_assets','idx_logistics_assets_status','status');
        self::ensureIndex($pdo,'logistics_assets','idx_logistics_assets_type','asset_type');
    }

    private static function ensureDrivers(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_drivers', "CREATE TABLE IF NOT EXISTS logistics_drivers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            personnel_id INT NULL,
            driver_type VARCHAR(40) NOT NULL DEFAULT 'interne',
            full_name VARCHAR(180) NOT NULL,
            phone VARCHAR(40) NULL,
            permit_number VARCHAR(100) NULL,
            permit_category VARCHAR(80) NULL,
            permit_expires_at DATE NULL,
            service_id INT NULL,
            availability_status VARCHAR(50) NOT NULL DEFAULT 'disponible',
            status VARCHAR(50) NOT NULL DEFAULT 'actif',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['personnel_id'=>"INT NULL",'driver_type'=>"VARCHAR(40) NOT NULL DEFAULT 'interne'",'full_name'=>"VARCHAR(180) NOT NULL DEFAULT ''",'phone'=>"VARCHAR(40) NULL",'permit_number'=>"VARCHAR(100) NULL",'permit_category'=>"VARCHAR(80) NULL",'permit_expires_at'=>"DATE NULL",'service_id'=>"INT NULL",'availability_status'=>"VARCHAR(50) NOT NULL DEFAULT 'disponible'",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'actif'",'notes'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_drivers',$c,$d);
    }

    private static function ensureRequests(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_requests', "CREATE TABLE IF NOT EXISTS logistics_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_code VARCHAR(80) NOT NULL,
            requester_user_id INT NULL,
            requester_personnel_id INT NULL,
            requester_service_id INT NULL,
            site_id INT NULL,
            need_type VARCHAR(80) NOT NULL DEFAULT 'engin',
            priority VARCHAR(40) NOT NULL DEFAULT 'normale',
            desired_date DATETIME NULL,
            title VARCHAR(180) NOT NULL,
            description TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'soumise',
            decision_note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['request_code'=>"VARCHAR(80) NOT NULL DEFAULT ''",'requester_user_id'=>"INT NULL",'requester_personnel_id'=>"INT NULL",'requester_service_id'=>"INT NULL",'site_id'=>"INT NULL",'need_type'=>"VARCHAR(80) NOT NULL DEFAULT 'engin'",'priority'=>"VARCHAR(40) NOT NULL DEFAULT 'normale'",'desired_date'=>"DATETIME NULL",'title'=>"VARCHAR(180) NOT NULL DEFAULT ''",'description'=>"TEXT NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'soumise'",'decision_note'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_requests',$c,$d);
    }

    private static function ensureAssignments(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_assignments', "CREATE TABLE IF NOT EXISTS logistics_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NULL,
            asset_id INT NOT NULL,
            driver_id INT NULL,
            site_id INT NULL,
            mission_title VARCHAR(180) NOT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'planifiee',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['request_id'=>"INT NULL",'asset_id'=>"INT NOT NULL DEFAULT 0",'driver_id'=>"INT NULL",'site_id'=>"INT NULL",'mission_title'=>"VARCHAR(180) NOT NULL DEFAULT ''",'start_at'=>"DATETIME NULL",'end_at'=>"DATETIME NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'planifiee'",'notes'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_assignments',$c,$d);
    }

    private static function ensureStock(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_stock_items', "CREATE TABLE IF NOT EXISTS logistics_stock_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_code VARCHAR(80) NOT NULL,
            category VARCHAR(120) NOT NULL DEFAULT 'consommable',
            designation VARCHAR(180) NOT NULL,
            unit VARCHAR(30) NOT NULL DEFAULT 'unite',
            current_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            minimum_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            location VARCHAR(160) NULL,
            preferred_supplier_id INT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'actif',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['item_code'=>"VARCHAR(80) NOT NULL DEFAULT ''",'category'=>"VARCHAR(120) NOT NULL DEFAULT 'consommable'",'designation'=>"VARCHAR(180) NOT NULL DEFAULT ''",'unit'=>"VARCHAR(30) NOT NULL DEFAULT 'unite'",'current_quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'minimum_quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'location'=>"VARCHAR(160) NULL",'preferred_supplier_id'=>"INT NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'actif'",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_stock_items',$c,$d);
        self::ensureTable($pdo, 'logistics_stock_movements', "CREATE TABLE IF NOT EXISTS logistics_stock_movements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_id INT NOT NULL,
            movement_type VARCHAR(40) NOT NULL,
            quantity DECIMAL(14,2) NOT NULL,
            unit_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            site_id INT NULL,
            asset_id INT NULL,
            maintenance_id INT NULL,
            reference VARCHAR(120) NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['item_id'=>"INT NOT NULL DEFAULT 0",'movement_type'=>"VARCHAR(40) NOT NULL DEFAULT 'entree'",'quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'unit_cost'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'site_id'=>"INT NULL",'asset_id'=>"INT NULL",'maintenance_id'=>"INT NULL",'reference'=>"VARCHAR(120) NULL",'notes'=>"TEXT NULL",'created_by'=>"INT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_stock_movements',$c,$d);
    }

    private static function ensureFuel(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_fuel_logs', "CREATE TABLE IF NOT EXISTS logistics_fuel_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_id INT NOT NULL,
            driver_id INT NULL,
            supplier_id INT NULL,
            site_id INT NULL,
            quantity DECIMAL(12,2) NOT NULL,
            unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            total_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            odometer_km DECIMAL(12,2) NULL,
            hour_meter DECIMAL(12,2) NULL,
            fuel_date DATETIME NOT NULL,
            voucher_reference VARCHAR(120) NULL,
            is_anomaly TINYINT(1) NOT NULL DEFAULT 0,
            anomaly_reason VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['asset_id'=>"INT NOT NULL DEFAULT 0",'driver_id'=>"INT NULL",'supplier_id'=>"INT NULL",'site_id'=>"INT NULL",'quantity'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'unit_price'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'total_cost'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'odometer_km'=>"DECIMAL(12,2) NULL",'hour_meter'=>"DECIMAL(12,2) NULL",'fuel_date'=>"DATETIME NULL",'voucher_reference'=>"VARCHAR(120) NULL",'is_anomaly'=>"TINYINT(1) NOT NULL DEFAULT 0",'anomaly_reason'=>"VARCHAR(255) NULL",'notes'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_fuel_logs',$c,$d);
    }

    private static function ensureMaintenance(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_maintenances', "CREATE TABLE IF NOT EXISTS logistics_maintenances (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_id INT NOT NULL,
            maintenance_type VARCHAR(60) NOT NULL DEFAULT 'preventive',
            title VARCHAR(180) NOT NULL,
            planned_at DATETIME NULL,
            performed_at DATETIME NULL,
            supplier_id INT NULL,
            technician_name VARCHAR(180) NULL,
            cost DECIMAL(14,2) NOT NULL DEFAULT 0,
            immobilization_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'planifiee',
            report TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['asset_id'=>"INT NOT NULL DEFAULT 0",'maintenance_type'=>"VARCHAR(60) NOT NULL DEFAULT 'preventive'",'title'=>"VARCHAR(180) NOT NULL DEFAULT ''",'planned_at'=>"DATETIME NULL",'performed_at'=>"DATETIME NULL",'supplier_id'=>"INT NULL",'technician_name'=>"VARCHAR(180) NULL",'cost'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'immobilization_hours'=>"DECIMAL(10,2) NOT NULL DEFAULT 0",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'planifiee'",'report'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_maintenances',$c,$d);
    }

    private static function ensureIncidents(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_incidents', "CREATE TABLE IF NOT EXISTS logistics_incidents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_id INT NULL,
            driver_id INT NULL,
            site_id INT NULL,
            incident_type VARCHAR(80) NOT NULL DEFAULT 'anomalie',
            severity VARCHAR(40) NOT NULL DEFAULT 'moyenne',
            description TEXT NOT NULL,
            corrective_action TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'ouvert',
            incident_at DATETIME NOT NULL,
            resolved_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['asset_id'=>"INT NULL",'driver_id'=>"INT NULL",'site_id'=>"INT NULL",'incident_type'=>"VARCHAR(80) NOT NULL DEFAULT 'anomalie'",'severity'=>"VARCHAR(40) NOT NULL DEFAULT 'moyenne'",'description'=>"TEXT NULL",'corrective_action'=>"TEXT NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'ouvert'",'incident_at'=>"DATETIME NULL",'resolved_at'=>"DATETIME NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_incidents',$c,$d);
    }

    private static function ensureDocuments(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_documents', "CREATE TABLE IF NOT EXISTS logistics_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            asset_id INT NULL,
            driver_id INT NULL,
            document_type VARCHAR(80) NOT NULL,
            reference VARCHAR(120) NULL,
            issued_at DATE NULL,
            expires_at DATE NULL,
            alert_days_before INT NOT NULL DEFAULT 30,
            file_path VARCHAR(255) NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'valide',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['asset_id'=>"INT NULL",'driver_id'=>"INT NULL",'document_type'=>"VARCHAR(80) NOT NULL DEFAULT ''",'reference'=>"VARCHAR(120) NULL",'issued_at'=>"DATE NULL",'expires_at'=>"DATE NULL",'alert_days_before'=>"INT NOT NULL DEFAULT 30",'file_path'=>"VARCHAR(255) NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'valide'",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_documents',$c,$d);
    }

    private static function ensureSuppliers(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_suppliers', "CREATE TABLE IF NOT EXISTS logistics_suppliers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_type VARCHAR(80) NOT NULL DEFAULT 'prestataire',
            name VARCHAR(180) NOT NULL,
            contact_name VARCHAR(180) NULL,
            phone VARCHAR(60) NULL,
            email VARCHAR(160) NULL,
            address TEXT NULL,
            rccm VARCHAR(120) NULL,
            ncc VARCHAR(120) NULL,
            conditions_text TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'actif',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['supplier_type'=>"VARCHAR(80) NOT NULL DEFAULT 'prestataire'",'name'=>"VARCHAR(180) NOT NULL DEFAULT ''",'contact_name'=>"VARCHAR(180) NULL",'phone'=>"VARCHAR(60) NULL",'email'=>"VARCHAR(160) NULL",'address'=>"TEXT NULL",'rccm'=>"VARCHAR(120) NULL",'ncc'=>"VARCHAR(120) NULL",'conditions_text'=>"TEXT NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'actif'",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_suppliers',$c,$d);
    }

    private static function ensureTrips(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_trip_batches', "CREATE TABLE IF NOT EXISTS logistics_trip_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_code VARCHAR(80) NOT NULL,
            site_id INT NULL,
            material VARCHAR(160) NOT NULL,
            planned_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            delivered_quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            unit VARCHAR(30) NOT NULL DEFAULT 'tonne',
            loading_point VARCHAR(180) NULL,
            delivery_point VARCHAR(180) NULL,
            start_date DATE NULL,
            expected_end_date DATE NULL,
            responsible_user_id INT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'planifiee',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['batch_code'=>"VARCHAR(80) NOT NULL DEFAULT ''",'site_id'=>"INT NULL",'material'=>"VARCHAR(160) NOT NULL DEFAULT ''",'planned_quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'delivered_quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'unit'=>"VARCHAR(30) NOT NULL DEFAULT 'tonne'",'loading_point'=>"VARCHAR(180) NULL",'delivery_point'=>"VARCHAR(180) NULL",'start_date'=>"DATE NULL",'expected_end_date'=>"DATE NULL",'responsible_user_id'=>"INT NULL",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'planifiee'",'notes'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",'updated_at'=>"DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_trip_batches',$c,$d);
        self::ensureTable($pdo, 'logistics_trips', "CREATE TABLE IF NOT EXISTS logistics_trips (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            asset_id INT NULL,
            driver_id INT NULL,
            quantity DECIMAL(14,2) NOT NULL DEFAULT 0,
            departure_at DATETIME NULL,
            arrival_at DATETIME NULL,
            delivery_note VARCHAR(120) NULL,
            fuel_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            status VARCHAR(50) NOT NULL DEFAULT 'planifie',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach(['batch_id'=>"INT NOT NULL DEFAULT 0",'asset_id'=>"INT NULL",'driver_id'=>"INT NULL",'quantity'=>"DECIMAL(14,2) NOT NULL DEFAULT 0",'departure_at'=>"DATETIME NULL",'arrival_at'=>"DATETIME NULL",'delivery_note'=>"VARCHAR(120) NULL",'fuel_quantity'=>"DECIMAL(12,2) NOT NULL DEFAULT 0",'status'=>"VARCHAR(50) NOT NULL DEFAULT 'planifie'",'notes'=>"TEXT NULL",'created_at'=>"DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"] as $c=>$d) self::ensureColumn($pdo,'logistics_trips',$c,$d);
    }

    private static function ensureSettings(PDO $pdo): void
    {
        self::ensureTable($pdo, 'logistics_settings', "CREATE TABLE IF NOT EXISTS logistics_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(120) NOT NULL UNIQUE,
            setting_value TEXT NULL,
            description VARCHAR(255) NULL,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach([
            'stock_critical_threshold' => ['30', 'Seuil stock critique global en pourcentage'],
            'fuel_overconsumption_percent' => ['25', 'Pourcentage de surconsommation carburant toléré'],
            'document_alert_days' => ['30', 'Délai alerte expiration documents'],
            'maintenance_alert_days' => ['7', 'Délai alerte maintenance proche']
        ] as $key => $row) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO logistics_settings(setting_key, setting_value, description) VALUES(:k,:v,:d)");
            $stmt->execute(['k'=>$key,'v'=>$row[0],'d'=>$row[1]]);
        }
    }

    private static function ensurePermissionsColumns(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'user_devis_banamur'");
        if (!$stmt || !$stmt->fetchColumn()) return;
        foreach (['module_logistique','logistique_dashboard','logistique_assets','logistique_drivers','logistique_stock','logistique_fuel','logistique_maintenance','logistique_incidents','logistique_documents','logistique_suppliers','logistique_requests','logistique_trips','logistique_settings'] as $column) {
            self::ensureColumn($pdo, 'user_devis_banamur', $column, "TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}
