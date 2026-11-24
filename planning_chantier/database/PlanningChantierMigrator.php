<?php
declare(strict_types=1);

final class PlanningChantierMigrator
{
    public function __construct(private PDO $pdo)
    {
    }

    public function migrate(): void
    {
        $this->createProjectsTable();
        $this->createTasksTable();
        $this->createProgressLogsTable();
        $this->createBlockagesTable();
        $this->createMeetingsTable();
        $this->createMeetingItemsTable();
        $this->createMeetingParticipantsTable();
        $this->createMeetingPreparationsTable();
        $this->createMeetingActionPointsTable();
        $this->createWorkProgressTables();
        $this->createMeetingActionPointChecklistTable();
        $this->createOfficeSubcategoryAttachmentsTable();
        $this->createMeetingHiddenDeboursesTable();
        $this->createMeetingHiddenLinesTable();
        $this->createMeetingLineOverridesTable();
        $this->createMeetingCustomSectionsTable();
        $this->createMeetingCustomSectionSeedsTable();
        $this->createMeetingActionPointSeedsTable();
        $this->createFinancialPilotFields();
        $this->restoreAutomaticallyMaterializedMeetingPoints();
        $this->createIndexesBestEffort();
    }

    /**
     * Répare uniquement la transformation automatique introduite par la
     * première version de la séparation. Les identifiants `legacy-ap-*`
     * n'ont jamais été produits par l'interface utilisateur : ils permettent
     * donc une restauration ciblée, sans toucher aux vrais points libres.
     */
    private function restoreAutomaticallyMaterializedMeetingPoints():void
    {
        try{
            $sections=$this->pdo->query("SELECT id,ligne_devis_id,title,payload_json
                FROM planning_chantier_meeting_custom_sections
                WHERE archived_at IS NULL AND payload_json LIKE '%legacy-ap-%'
                ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(!$sections)return;

            $ownsTransaction=!$this->pdo->inTransaction();
            if($ownsTransaction)$this->pdo->beginTransaction();
            $source=$this->pdo->prepare("SELECT db.id debourse_id,detail.id detail_id
                FROM debourse_banamur db
                INNER JOIN ligne_debourse_banamur detail ON detail.debourse_id=db.id
                WHERE db.ligne_devis_id=:line
                  AND TRIM(db.motif_modif)=:category
                  AND TRIM(detail.designation)=:subcategory
                ORDER BY db.id,detail.id
                LIMIT 2");
            $restorePoint=$this->pdo->prepare("UPDATE planning_chantier_meeting_action_points SET
                    debourse_id=:debourse,ligne_debourse_id=:detail,meeting_section_id=NULL,
                    status=:status,archived_at=NULL,archived_by=NULL,updated_at=NOW()
                WHERE id=:id AND meeting_section_id=:section");
            $restoreFiles=$this->pdo->prepare("UPDATE planning_chantier_office_attachments SET
                    ligne_debourse_id=:detail,meeting_section_id=NULL
                WHERE meeting_section_id=:section AND archived_at IS NULL");
            $archiveSection=$this->pdo->prepare("UPDATE planning_chantier_meeting_custom_sections SET
                    archived_at=NOW(),updated_at=NOW()
                WHERE id=:section AND archived_at IS NULL");

            foreach($sections as $section){
                $parts=preg_split('/\s+—\s+/u',(string)$section['title'],2);
                if(count($parts)!==2)continue;
                $source->execute([
                    'line'=>(int)$section['ligne_devis_id'],
                    'category'=>trim($parts[0]),
                    'subcategory'=>trim($parts[1]),
                ]);
                $matches=$source->fetchAll(PDO::FETCH_ASSOC)?:[];
                if(count($matches)!==1)continue;

                $tasks=json_decode((string)$section['payload_json'],true);
                if(!is_array($tasks)||!$tasks)continue;
                $restored=0;
                foreach($tasks as $task){
                    if(!preg_match('/^legacy-ap-(\d+)$/',(string)($task['id']??''),$match))continue;
                    $restorePoint->execute([
                        'debourse'=>(int)$matches[0]['debourse_id'],
                        'detail'=>(int)$matches[0]['detail_id'],
                        'status'=>(string)($task['status']??'open'),
                        'id'=>(int)$match[1],
                        'section'=>(int)$section['id'],
                    ]);
                    $restored+=$restorePoint->rowCount();
                }
                if($restored<=0)continue;
                $restoreFiles->execute([
                    'detail'=>(int)$matches[0]['detail_id'],
                    'section'=>(int)$section['id'],
                ]);
                $archiveSection->execute(['section'=>(int)$section['id']]);
            }
            if($ownsTransaction)$this->pdo->commit();
        }catch(Throwable $e){
            if(isset($ownsTransaction)&&$ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
            error_log('[planning_chantier:restore_materialized_points] '.$e->getMessage());
        }
    }

    private function createFinancialPilotFields(): void
    {
        $this->ensureColumn('planning_chantier_tasks', 'source_type', "ENUM('manual','ligne_devis','debourse','ligne_debourse') NOT NULL DEFAULT 'manual'");
        $this->ensureColumn('planning_chantier_tasks', 'source_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_tasks', 'snapshot_category', 'VARCHAR(80) NULL');
        $this->ensureColumn('planning_chantier_tasks', 'planning_comment', 'TEXT NULL');
        $this->ensureColumn('planning_chantier_tasks', 'planning_comment_updated_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_tasks', 'planning_comment_updated_by', 'INT NULL');
        try{$this->pdo->exec("ALTER TABLE planning_chantier_tasks MODIFY source_type ENUM('manual','ligne_devis','debourse','ligne_debourse') NOT NULL DEFAULT 'manual'");}catch(Throwable $e){}
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_structure_snapshots (
            project_id INT PRIMARY KEY,
            chantier_id INT NOT NULL,
            source_devis_id INT NULL,
            captured_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            captured_by INT NULL,
            source_counts_json LONGTEXT NULL,
            INDEX idx_pc_structure_snapshot_chantier (chantier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensureColumn('chantier_banamur', 'planning_is_office_hub', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->ensureColumn('planning_chantier_meeting_preparations', 'office_chantier_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_preparations', 'office_ligne_devis_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'pilot_user_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'copilot_user_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'ligne_debourse_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'archived_by', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_point_checklist', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meeting_action_point_checklist', 'archived_by', 'INT NULL');
        $this->ensureColumn('planning_chantier_meeting_action_point_checklist', 'progress_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
        $this->ensureColumn('planning_chantier_office_attachments', 'preparation_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meetings', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meetings', 'archived_by', 'INT NULL');
        $this->ensureColumn('planning_chantier_meetings', 'report_code', 'VARCHAR(80) NULL');
        $this->ensureColumn('planning_chantier_meetings', 'office_service_line_id', 'INT NULL');
        $this->ensureColumn('planning_chantier_meetings', 'performance_json', 'LONGTEXT NULL');
        $this->ensureColumn('planning_chantier_meeting_preparations', 'archived_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meeting_preparations', 'archived_by', 'INT NULL');

        $this->ensureFinancialWeightCapacity();

        try {
            $this->pdo->exec("UPDATE planning_chantier_meetings m
                LEFT JOIN planning_chantier_meeting_preparations p ON p.id=m.preparation_id
                SET m.office_service_line_id=COALESCE(m.office_service_line_id,p.office_ligne_devis_id),
                    m.report_code=COALESCE(NULLIF(m.report_code,''),
                        CONCAT(IF(p.office_ligne_devis_id IS NULL,'CR-CH-','CR-BUR-'),
                            DATE_FORMAT(m.meeting_date,'%Y%m%d'),'-',
                            IF(p.office_ligne_devis_id IS NULL,'',CONCAT('S',p.office_ligne_devis_id,'-')),
                            LPAD(m.id,6,'0')))");
            $this->pdo->exec("UPDATE planning_chantier_meetings older
                JOIN planning_chantier_meetings newer
                  ON newer.office_service_line_id=older.office_service_line_id
                 AND newer.status='closed'
                 AND (newer.meeting_date>older.meeting_date
                      OR (newer.meeting_date=older.meeting_date AND newer.id>older.id))
                SET older.archived_at=COALESCE(older.archived_at,NOW())
                WHERE older.office_service_line_id IS NOT NULL
                  AND older.status='closed' AND older.archived_at IS NULL");
        } catch(Throwable $e) {}

    }

    private function createProjectsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_projects (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chantier_id INT NULL,
                code VARCHAR(100) NULL,
                libelle VARCHAR(255) NOT NULL,
                description TEXT NULL,
                responsable_id INT NULL,
                date_debut DATE NULL,
                date_fin_prevue DATE NULL,
                statut ENUM('actif','suspendu','termine','archive') DEFAULT 'actif',
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('planning_chantier_projects', 'chantier_id', "INT NULL");
        $this->ensureColumn('planning_chantier_projects', 'code', "VARCHAR(100) NULL");
        $this->ensureColumn('planning_chantier_projects', 'libelle', "VARCHAR(255) NOT NULL");
        $this->ensureColumn('planning_chantier_projects', 'description', "TEXT NULL");
        $this->ensureColumn('planning_chantier_projects', 'responsable_id', "INT NULL");
        $this->ensureColumn('planning_chantier_projects', 'date_debut', "DATE NULL");
        $this->ensureColumn('planning_chantier_projects', 'date_fin_prevue', "DATE NULL");
        $this->ensureColumn('planning_chantier_projects', 'statut', "ENUM('actif','suspendu','termine','archive') DEFAULT 'actif'");
    }

    private function createTasksTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NOT NULL,
                parent_id INT NULL,
                titre VARCHAR(255) NOT NULL,
                description TEXT NULL,
                ordre INT DEFAULT 0,
                date_debut_prevue DATE NULL,
                date_fin_prevue DATE NULL,
                poids DECIMAL(20,2) NOT NULL DEFAULT 1,
                avancement_prevu DECIMAL(5,2) DEFAULT 0,
                avancement_reel DECIMAL(5,2) DEFAULT 0,
                statut ENUM('a_faire','en_cours','bloque','termine','annule') DEFAULT 'a_faire',
                responsable_id INT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('planning_chantier_tasks', 'parent_id', "INT NULL");
        $this->ensureColumn('planning_chantier_tasks', 'poids', "DECIMAL(20,2) NOT NULL DEFAULT 1");
        $this->ensureColumn('planning_chantier_tasks', 'avancement_prevu', "DECIMAL(5,2) DEFAULT 0");
        $this->ensureColumn('planning_chantier_tasks', 'avancement_reel', "DECIMAL(5,2) DEFAULT 0");
        $this->ensureColumn('planning_chantier_tasks', 'statut', "ENUM('a_faire','en_cours','bloque','termine','annule') DEFAULT 'a_faire'");
    }

    private function createProgressLogsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_progress_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                project_id INT NOT NULL,
                meeting_date DATE NOT NULL,
                avancement_reel DECIMAL(5,2) NOT NULL,
                avancement_prevu DECIMAL(5,2) NULL,
                ecart DECIMAL(6,2) NULL,
                commentaire TEXT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('planning_chantier_progress_logs', 'avancement_prevu', "DECIMAL(5,2) NULL");
        $this->ensureColumn('planning_chantier_progress_logs', 'ecart', "DECIMAL(6,2) NULL");
    }

    private function createBlockagesTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_blockages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                project_id INT NOT NULL,
                titre VARCHAR(255) NOT NULL,
                cause TEXT NOT NULL,
                impact TEXT NULL,
                action_corrective TEXT NULL,
                priorite ENUM('basse','normale','haute','critique') DEFAULT 'normale',
                statut ENUM('ouvert','en_traitement','resolu','abandonne') DEFAULT 'ouvert',
                responsable_correction_id INT NULL,
                date_detection DATE NOT NULL,
                date_resolution DATE NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createMeetingsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meetings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                project_id INT NULL,
                meeting_date DATE NOT NULL,
                titre VARCHAR(255) DEFAULT 'Réunion quotidienne de 12h',
                resume TEXT NULL,
                decisions TEXT NULL,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('planning_chantier_meetings', 'status', "ENUM('draft','closed') DEFAULT 'draft'");
        $this->ensureColumn('planning_chantier_meetings', 'closed_at', "DATETIME NULL");
        $this->ensureColumn('planning_chantier_meetings', 'closed_by', "INT NULL");
        $this->ensureColumn('planning_chantier_meetings', 'participants_text', "TEXT NULL");
        $this->ensureColumn('planning_chantier_meetings', 'pdf_path', "VARCHAR(255) NULL");
        $this->ensureColumn('planning_chantier_meetings', 'updated_at', "DATETIME NULL");
        $this->ensureColumn('planning_chantier_meetings', 'report_code', "VARCHAR(80) NULL");
        $this->ensureColumn('planning_chantier_meetings', 'office_service_line_id', "INT NULL");
        $this->ensureColumn('planning_chantier_meetings', 'performance_json', "LONGTEXT NULL");
    }

    private function createMeetingItemsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meeting_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                meeting_id INT NOT NULL,
                project_id INT NOT NULL,
                task_id INT NULL,
                sujet VARCHAR(255) NOT NULL,
                constat TEXT NULL,
                decision_text TEXT NULL,
                responsable_id INT NULL,
                echeance DATE NULL,
                statut ENUM('ouvert','en_cours','traite') DEFAULT 'ouvert',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }


    private function createMeetingParticipantsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meeting_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                meeting_id INT NOT NULL,
                user_id INT NULL,
                participant_name VARCHAR(255) NOT NULL,
                participant_email VARCHAR(190) NULL,
                role_label VARCHAR(120) NULL,
                attendance_status ENUM('present','absent','copie') DEFAULT 'present',
                notify_email TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumn('planning_chantier_meeting_participants', 'participant_email', "VARCHAR(190) NULL");
        $this->ensureColumn('planning_chantier_meeting_participants', 'role_label', "VARCHAR(120) NULL");
        $this->ensureColumn('planning_chantier_meeting_participants', 'attendance_status', "ENUM('present','absent','copie') DEFAULT 'present'");
        $this->ensureColumn('planning_chantier_meeting_participants', 'notify_email', "TINYINT(1) DEFAULT 1");
    }

    private function createMeetingPreparationsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meeting_preparations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                meeting_date DATE NOT NULL,
                titre VARCHAR(255) NOT NULL,
                agenda TEXT NOT NULL,
                chantier_ids_json LONGTEXT NOT NULL,
                participants_json LONGTEXT NOT NULL,
                office_chantier_id INT NULL,
                office_ligne_devis_id INT NULL,
                status ENUM('draft','ready','used','cancelled') NOT NULL DEFAULT 'draft',
                created_by INT NULL,
                meeting_id INT NULL,
                ready_at DATETIME NULL,
                started_at DATETIME NULL,
                start_notified_at DATETIME NULL,
                used_at DATETIME NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->ensureColumn('planning_chantier_meeting_preparations', 'started_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meeting_preparations', 'start_notified_at', 'DATETIME NULL');
        $this->ensureColumn('planning_chantier_meetings', 'preparation_id', 'INT NULL');
    }

    private function createMeetingActionPointsTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meeting_action_points (
                id INT AUTO_INCREMENT PRIMARY KEY,
                preparation_id INT NOT NULL,
                chantier_id INT NOT NULL,
                ligne_devis_id INT NOT NULL,
                debourse_id INT NOT NULL,
                ligne_debourse_id INT NULL,
                section_title VARCHAR(255) NULL,
                task_title TEXT NOT NULL,
                pilot_user_id INT NULL,
                pilot_name VARCHAR(255) NULL,
                copilot_user_id INT NULL,
                copilot_name VARCHAR(255) NULL,
                old_deadline DATE NULL,
                new_deadline DATE NULL,
                progress DECIMAL(5,2) NOT NULL DEFAULT 0,
                status ENUM('open','in_progress','done','cancelled') NOT NULL DEFAULT 'open',
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $this->ensureColumn('planning_chantier_meeting_action_points', 'section_title', 'VARCHAR(255) NULL');
        $this->ensureColumn('planning_chantier_meeting_action_points', 'ligne_debourse_id', 'INT NULL');
    }

    private function createMeetingActionPointChecklistTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS planning_chantier_meeting_action_point_checklist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                action_point_id INT NOT NULL,
                label VARCHAR(500) NOT NULL,
                completed_at DATETIME NULL,
                completed_by INT NULL,
                progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX idx_pc_action_checklist_point (action_point_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    private function createWorkProgressTables():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_work_progress(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,action_point_id INT NOT NULL UNIQUE,
            unit_label VARCHAR(40) NULL,planned_quantity DECIMAL(18,3) NOT NULL DEFAULT 1,
            executed_quantity DECIMAL(18,3) NOT NULL DEFAULT 0,client_billed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            executed_billed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,observation VARCHAR(500) NULL,
            updated_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,
            INDEX idx_pc_work_progress_point(action_point_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_work_progress_groups(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,preparation_id INT NOT NULL,chantier_id INT NOT NULL,
            section_title VARCHAR(255) NOT NULL,client_billed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            observation VARCHAR(500) NULL,updated_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,
            UNIQUE KEY uq_pc_work_group(preparation_id,chantier_id,section_title),INDEX idx_pc_work_group_context(preparation_id,chantier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_work_progress_checklist(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,checklist_id INT NOT NULL UNIQUE,unit_label VARCHAR(40) NULL,
            planned_quantity DECIMAL(18,3) NOT NULL DEFAULT 1,client_billed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
            observation VARCHAR(500) NULL,updated_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,
            INDEX idx_pc_work_checklist(checklist_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_work_progress_custom(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,preparation_id INT NOT NULL,chantier_id INT NOT NULL,
            item_key VARCHAR(100) NOT NULL,unit_label VARCHAR(40) NULL,planned_quantity DECIMAL(18,3) NOT NULL DEFAULT 1,
            client_billed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,observation VARCHAR(500) NULL,updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NULL,
            UNIQUE KEY uq_pc_work_custom(preparation_id,chantier_id,item_key),INDEX idx_pc_work_custom_context(preparation_id,chantier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createOfficeSubcategoryAttachmentsTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_office_attachments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            preparation_id INT NULL,
            ligne_debourse_id INT NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(150) NULL,
            file_size BIGINT NOT NULL DEFAULT 0,
            uploaded_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            archived_by INT NULL,
            INDEX idx_pc_office_attachment_detail (ligne_debourse_id,archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensureColumn('planning_chantier_office_attachments', 'preparation_id', 'INT NULL');
    }

    private function createMeetingHiddenDeboursesTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_hidden_debourses (
            preparation_id INT NOT NULL,
            debourse_id INT NOT NULL,
            hidden_by INT NULL,
            hidden_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (preparation_id,debourse_id),
            INDEX idx_pc_hidden_debourse (debourse_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createMeetingHiddenLinesTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_hidden_lines (
            preparation_id INT NOT NULL,
            ligne_devis_id INT NOT NULL,
            hidden_by INT NULL,
            hidden_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (preparation_id,ligne_devis_id),
            INDEX idx_pc_hidden_line (ligne_devis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createMeetingLineOverridesTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_line_overrides (
            preparation_id INT NOT NULL,
            ligne_devis_id INT NOT NULL,
            meeting_label VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (preparation_id,ligne_devis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createMeetingCustomSectionsTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_custom_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            preparation_id INT NOT NULL,
            chantier_id INT NULL,
            ligne_devis_id INT NULL,
            title VARCHAR(255) NOT NULL,
            responsible_user_id INT NULL,
            start_date DATE NULL,
            end_date DATE NULL,
            payload_json LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            archived_at DATETIME NULL,
            archived_by INT NULL,
            INDEX idx_pc_custom_section_meeting (preparation_id,archived_at,sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensureColumn('planning_chantier_meeting_action_points','meeting_section_id','INT NULL');
        $this->ensureColumn('planning_chantier_meeting_custom_sections','payload_json','LONGTEXT NULL');
        $this->ensureColumn('planning_chantier_meeting_custom_sections','responsible_user_id','INT NULL');
        $this->ensureColumn('planning_chantier_meeting_custom_sections','start_date','DATE NULL');
        $this->ensureColumn('planning_chantier_meeting_custom_sections','end_date','DATE NULL');
        $this->ensureColumn('planning_chantier_office_attachments','action_point_id','INT NULL');
        $this->ensureColumn('planning_chantier_office_attachments','meeting_section_id','INT NULL');
        try{$this->pdo->exec("ALTER TABLE planning_chantier_office_attachments MODIFY ligne_debourse_id INT NULL");}catch(Throwable $e){}
        try{
            $this->pdo->exec("ALTER TABLE planning_chantier_meeting_action_points
                MODIFY chantier_id INT NULL,MODIFY ligne_devis_id INT NULL,MODIFY debourse_id INT NULL");
        }catch(Throwable $e){}
    }

    private function createMeetingCustomSectionSeedsTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_custom_section_seeds (
            preparation_id INT NOT NULL,
            chantier_id INT NOT NULL,
            source_preparation_id INT NULL,
            seeded_by INT NULL,
            seeded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (preparation_id,chantier_id),
            INDEX idx_pc_custom_seed_source (source_preparation_id,chantier_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function createMeetingActionPointSeedsTable():void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS planning_chantier_meeting_action_point_seeds (
            preparation_id INT NOT NULL,
            ligne_devis_id INT NOT NULL,
            source_preparation_id INT NULL,
            seeded_by INT NULL,
            seeded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (preparation_id,ligne_devis_id),
            INDEX idx_pc_action_seed_source (source_preparation_id,ligne_devis_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function ensureColumn(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM `$table` LIKE :column");
            $stmt->execute(['column' => $column]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Throwable $e) {
            // Best effort pour hébergement mutualisé.
        }
    }

    private function ensureFinancialWeightCapacity(): void
    {
        try {
            $stmt = $this->pdo->query("SELECT NUMERIC_PRECISION,NUMERIC_SCALE
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE()
                  AND TABLE_NAME='planning_chantier_tasks'
                  AND COLUMN_NAME='poids'
                LIMIT 1");
            $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($column && (int)$column['NUMERIC_PRECISION'] >= 20 && (int)$column['NUMERIC_SCALE'] >= 2) {
                return;
            }

            // Idempotent : exécuté uniquement sur une base encore dans l'ancien
            // format après la mise en ligne du nouveau code.
            $this->pdo->exec("ALTER TABLE planning_chantier_tasks
                MODIFY poids DECIMAL(20,2) NOT NULL DEFAULT 1");
        } catch (Throwable $e) {
            // Le service borne aussi la valeur à l'ancien schéma pour que le
            // module reste utilisable si l'hébergeur refuse le droit ALTER.
            error_log('[planning_chantier:migration] Extension de planning_chantier_tasks.poids impossible: ' . $e->getMessage());
        }
    }

    private function createIndexesBestEffort(): void
    {
        $indexes = [
            "CREATE INDEX idx_pc_projects_chantier ON planning_chantier_projects (chantier_id)",
            "CREATE INDEX idx_pc_projects_statut ON planning_chantier_projects (statut)",
            "CREATE INDEX idx_pc_tasks_project ON planning_chantier_tasks (project_id)",
            "CREATE INDEX idx_pc_tasks_statut ON planning_chantier_tasks (statut)",
            "CREATE UNIQUE INDEX uq_pc_tasks_source ON planning_chantier_tasks (source_type, source_id)",
            "CREATE INDEX idx_pc_logs_task ON planning_chantier_progress_logs (task_id)",
            "CREATE INDEX idx_pc_logs_project_date ON planning_chantier_progress_logs (project_id, meeting_date)",
            "CREATE INDEX idx_pc_block_project_statut ON planning_chantier_blockages (project_id, statut)",
            "CREATE INDEX idx_pc_meetings_date ON planning_chantier_meetings (meeting_date)",
            "CREATE INDEX idx_pc_meeting_participants ON planning_chantier_meeting_participants (meeting_id)",
        ];

        foreach ($indexes as $sql) {
            try {
                $this->pdo->exec($sql);
            } catch (Throwable $e) {
                // Index déjà existant ou limitation serveur : ignorer.
            }
        }
    }
}
