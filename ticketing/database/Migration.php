<?php
namespace Ticketing\Database;

use PDO;

class Migration
{
    public static function run(PDO $pdo): void
    {
        self::ensureFineaIntegration($pdo);
        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL UNIQUE,
            color VARCHAR(30) DEFAULT '#1d2b57',
            icon VARCHAR(60) DEFAULT 'fa-ticket-alt',
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_priorities (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            level INT NOT NULL DEFAULT 1,
            sla_hours INT NOT NULL DEFAULT 24,
            color VARCHAR(30) DEFAULT '#1d2b57',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_no VARCHAR(40) NOT NULL UNIQUE,
            title VARCHAR(220) NOT NULL,
            description TEXT NOT NULL,
            category_id INT NULL,
            priority_id INT NULL,
            requester_id INT NULL,
            requester_name VARCHAR(160) NOT NULL,
            requester_service VARCHAR(120) NULL,
            assigned_service VARCHAR(120) NULL,
            assigned_user_id INT NULL,
            assigned_user_name VARCHAR(160) NULL,
            related_module VARCHAR(80) NULL,
            related_reference VARCHAR(120) NULL,
            related_reference_type VARCHAR(40) NULL,
            related_reference_id VARCHAR(80) NULL,
            chantier VARCHAR(160) NULL,
            chantier_id INT NULL,
            status ENUM('nouveau','pris_en_charge','en_cours','en_attente','resolu','cloture','rejete') DEFAULT 'nouveau',
            due_at DATETIME NULL,
            resolved_at DATETIME NULL,
            closed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status(status),
            INDEX idx_assigned_service(assigned_service),
            INDEX idx_assigned_user_id(assigned_user_id),
            INDEX idx_requester_id(requester_id),
            CONSTRAINT fk_ticket_category FOREIGN KEY (category_id) REFERENCES finea_ticket_categories(id) ON DELETE SET NULL,
            CONSTRAINT fk_ticket_priority FOREIGN KEY (priority_id) REFERENCES finea_ticket_priorities(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureTicketColumns($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            author_id INT NULL,
            author_name VARCHAR(160) NOT NULL,
            author_service VARCHAR(120) NULL,
            message TEXT NOT NULL,
            is_internal TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ticket_message_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            message_id INT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NULL,
            size_bytes INT DEFAULT 0,
            uploaded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ticket_attachment_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE,
            CONSTRAINT fk_ticket_attachment_message FOREIGN KEY (message_id) REFERENCES finea_ticket_messages(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_status_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            old_status VARCHAR(40) NULL,
            new_status VARCHAR(40) NOT NULL,
            changed_by INT NULL,
            changed_by_name VARCHAR(160) NOT NULL,
            comment VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ticket_history_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_watchers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            user_id INT NULL,
            user_name VARCHAR(160) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ticket_watcher_user(ticket_id, user_id),
            CONSTRAINT fk_ticket_watcher_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            recipient_user_id INT NOT NULL,
            recipient_name VARCHAR(160) NOT NULL,
            recipient_email VARCHAR(190) NULL,
            channel ENUM('system','email') NOT NULL,
            notification_id INT NULL,
            email_log_id INT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'sent',
            error_message TEXT NULL,
            sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resent_count INT NOT NULL DEFAULT 0,
            read_at DATETIME NULL,
            created_by INT NULL,
            UNIQUE KEY uniq_ticket_notif_channel(ticket_id, recipient_user_id, channel),
            KEY idx_ticket_notif_ticket(ticket_id),
            KEY idx_ticket_notif_user(recipient_user_id),
            CONSTRAINT fk_ticket_notif_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $watcherIndexes=array_column($pdo->query('SHOW INDEX FROM finea_ticket_watchers')->fetchAll(PDO::FETCH_ASSOC),'Key_name');
        if(!in_array('uniq_ticket_watcher_user',$watcherIndexes,true)) {
            $pdo->exec('DELETE newer FROM finea_ticket_watchers newer JOIN finea_ticket_watchers older ON newer.ticket_id=older.ticket_id AND newer.user_id=older.user_id AND newer.id>older.id');
            $pdo->exec('ALTER TABLE finea_ticket_watchers ADD UNIQUE INDEX uniq_ticket_watcher_user(ticket_id,user_id)');
        }
        if(in_array('uniq_ticket_watcher',$watcherIndexes,true)) $pdo->exec('ALTER TABLE finea_ticket_watchers DROP INDEX uniq_ticket_watcher');
        self::ensureNotificationTracking($pdo);

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_reminder_acknowledgements (
            user_id INT NOT NULL,
            last_acknowledged_at DATETIME NULL,
            last_notified_at DATETIME NULL,
            acknowledged_ticket_count INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(user_id),
            KEY idx_ticket_reminder_due(last_acknowledged_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::ensureReceptionWorkflow($pdo);
        self::seed($pdo);
    }

    private static function ensureReceptionWorkflow(PDO $pdo): void
    {
        $columns=$pdo->query('SHOW COLUMNS FROM finea_tickets')->fetchAll(PDO::FETCH_COLUMN,0);
        foreach (['requester_signature_data'=>'MEDIUMTEXT NULL','requester_signed_at'=>'DATETIME NULL','deadline_set_by'=>'INT NULL','deadline_set_by_name'=>'VARCHAR(160) NULL','deadline_set_at'=>'DATETIME NULL','request_key'=>'CHAR(64) NULL'] as $name=>$type) {
            if (!in_array($name,$columns,true)) $pdo->exec("ALTER TABLE finea_tickets ADD COLUMN $name $type");
        }
        $indexes=$pdo->query('SHOW INDEX FROM finea_tickets')->fetchAll(PDO::FETCH_ASSOC);
        if(!in_array('uniq_ticket_request',array_column($indexes,'Key_name'),true)) $pdo->exec('ALTER TABLE finea_tickets ADD UNIQUE INDEX uniq_ticket_request(request_key)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_reception_proofs (
            id INT AUTO_INCREMENT PRIMARY KEY, ticket_id INT NOT NULL, stored_name VARCHAR(80) NOT NULL,
            original_name VARCHAR(255) NOT NULL, mime_type VARCHAR(80) NOT NULL, size_bytes INT NOT NULL,
            uploaded_by INT NOT NULL, uploaded_by_name VARCHAR(160) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_reception_ticket(ticket_id),
            FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureNotificationTracking(PDO $pdo): void
    {
        try {
            $columns = array_flip($pdo->query("SHOW COLUMNS FROM finea_ticket_notification_logs")->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
            if (!isset($columns['email_log_id'])) {
                $pdo->exec("ALTER TABLE finea_ticket_notification_logs ADD COLUMN email_log_id INT NULL AFTER notification_id");
            }
            if (!isset($columns['error_message'])) {
                $pdo->exec("ALTER TABLE finea_ticket_notification_logs ADD COLUMN error_message TEXT NULL AFTER status");
            }
            try {
                $pdo->exec("ALTER TABLE finea_ticket_notification_logs ADD INDEX idx_ticket_notif_email_log (email_log_id)");
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS finea_ticket_views (
            ticket_id INT NOT NULL,
            user_id INT NOT NULL,
            first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            view_count INT NOT NULL DEFAULT 1,
            PRIMARY KEY(ticket_id, user_id),
            KEY idx_ticket_views_user(user_id),
            CONSTRAINT fk_ticket_view_ticket FOREIGN KEY (ticket_id) REFERENCES finea_tickets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureFineaIntegration(PDO $pdo): void
    {
        try {
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user_devis_banamur' AND COLUMN_NAME = 'acces_ticketing'");
            $stmt->execute([$dbName]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE user_devis_banamur ADD COLUMN acces_ticketing TINYINT(1) NOT NULL DEFAULT 0");
            }
            $pdo->exec("UPDATE user_devis_banamur SET acces_ticketing = 1 WHERE acces_admin = 1 OR gestion_utilisateur = 1");
        } catch (\Throwable $e) {
            // Le module reste utilisable même si l'installation n'a pas les mêmes colonnes utilisateur.
        }
    }

    private static function ensureTicketColumns(PDO $pdo): void
    {
        try {
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'finea_tickets'");
            $stmt->execute([$dbName]);
            $columns = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
            $alters = [];
            if (!isset($columns['related_reference_type'])) {
                $alters[] = "ADD COLUMN related_reference_type VARCHAR(40) NULL AFTER related_reference";
            }
            if (!isset($columns['related_reference_id'])) {
                $alters[] = "ADD COLUMN related_reference_id VARCHAR(80) NULL AFTER related_reference_type";
            }
            if (!isset($columns['chantier_id'])) {
                $alters[] = "ADD COLUMN chantier_id INT NULL AFTER chantier";
            }
            foreach ($alters as $alter) {
                try {
                    $pdo->exec('ALTER TABLE finea_tickets ' . $alter);
                } catch (\Throwable $e) {
                    // Continuer avec les autres colonnes si l'une existe déjà sous une installation modifiée.
                }
            }
        } catch (\Throwable $e) {
            // Les champs additionnels enrichissent le ticket sans bloquer le module.
        }
    }

    private static function seed(PDO $pdo): void
    {
        $categories = [
            ['Informatique', '#1d2b57', 'fa-computer'], ['RH', '#5b5fc7', 'fa-users'],
            ['Finance', '#0984e3', 'fa-coins'], ['Décaissement', '#fabd02', 'fa-file-invoice-dollar'],
            ['Logistique', '#00a884', 'fa-truck'], ['Chantier', '#d35400', 'fa-helmet-safety'],
            ['Achat', '#6c5ce7', 'fa-cart-shopping'], ['QHSE', '#2ecc71', 'fa-shield-halved'],
            ['Direction', '#22254b', 'fa-building-user'], ['Divers', '#636e72', 'fa-circle-question']
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO finea_ticket_categories(name,color,icon) VALUES(?,?,?)");
        foreach ($categories as $c) { $stmt->execute($c); }

        $priorities = [
            ['Faible', 1, 72, '#636e72'], ['Normale', 2, 24, '#1d2b57'],
            ['Haute', 3, 8, '#e67e22'], ['Critique', 4, 2, '#c0392b']
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO finea_ticket_priorities(name,level,sla_hours,color) VALUES(?,?,?,?)");
        foreach ($priorities as $p) { $stmt->execute($p); }
    }
}
