<?php

require_once __DIR__ . '/../controllers/NotificationController.php';

class EmployeeSchedule
{
    private const WORKDAY_START_HOUR = 7;
    private const WORKDAY_START_MINUTE = 0;
    private const WORKDAY_END_HOUR = 18;
    private const WORKDAY_END_MINUTE = 0;
    private const SLOT_STEP_MINUTES = 15;
    private const MAX_WINDOW_LOOKUP_DAYS = 21;

    private PDO $pdo;
    private bool $ready = true;
    private ?string $initError = null;
    private array $workWindowCache = [];
    private array $loadThresholdCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS employee_schedule_event (
            id INT NOT NULL AUTO_INCREMENT,
            personnel_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            assignment_type VARCHAR(20) NOT NULL DEFAULT 'bureau',
            assignment_chantier_id INT NULL,
            start_at DATETIME NOT NULL,
            end_at DATETIME NOT NULL,
            status_code VARCHAR(30) NOT NULL DEFAULT 'planifie',
            planning_task_id INT NULL,
            recurrence_rule_id INT NULL,
            created_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_employee_schedule_personnel_start (personnel_id, start_at),
            KEY idx_employee_schedule_personnel_end (personnel_id, end_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
            $this->ensureColumns();
            $this->ensureWorkWindowTables();
            $this->ensureQuickTaskTable();
        } catch (Throwable $e) {
            $this->ready = false;
            $this->initError = 'Initialisation SQL impossible (droits insuffisants ?)';
        }
    }

    private function ensureQuickTaskTable(): void
    {
        if (!$this->ready) {
            return;
        }

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS employee_schedule_quick_task (
                id INT NOT NULL AUTO_INCREMENT,
                personnel_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                status_code VARCHAR(30) NOT NULL DEFAULT 'planifie',
                scheduled_event_id INT NULL,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                created_by_user_id INT NULL,
                scheduled_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_employee_schedule_quick_personnel (personnel_id, is_archived, created_at),
                KEY idx_employee_schedule_quick_event (scheduled_event_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            // Le bac rapide est optionnel : ne pas bloquer l'emploi du temps principal.
        }
    }

    private function ensureWorkWindowTables(): void
    {
        if (!$this->ready) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS employee_schedule_work_window (
            id INT NOT NULL AUTO_INCREMENT,
            scope_type VARCHAR(20) NOT NULL,
            scope_id INT NOT NULL,
            weekday TINYINT NOT NULL,
            is_working_day TINYINT(1) NOT NULL DEFAULT 1,
            start_minute SMALLINT DEFAULT NULL,
            end_minute SMALLINT DEFAULT NULL,
            updated_by_user_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_employee_schedule_work_window_scope_day (scope_type, scope_id, weekday),
            KEY idx_employee_schedule_work_window_scope (scope_type, scope_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS employee_schedule_load_threshold (
                id INT NOT NULL AUTO_INCREMENT,
                scope_type VARCHAR(20) NOT NULL,
                scope_id INT NOT NULL,
                daily_threshold_hours DECIMAL(5,2) NOT NULL,
                updated_by_user_id INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_employee_schedule_load_threshold_scope (scope_type, scope_id),
                KEY idx_employee_schedule_load_threshold_scope (scope_type, scope_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        } catch (Throwable $e) {
            // silencieux pour conserver l'usage du module principal
        }
    }

    private function ensureColumns(): void
    {
        if (!$this->ready) {
            return;
        }

        try {
            $cols = [];
            $stmt = $this->pdo->query('SHOW COLUMNS FROM employee_schedule_event');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (!empty($row['Field'])) {
                        $cols[strtolower((string)$row['Field'])] = true;
                    }
                }
            }

            $alter = [];
            if (!isset($cols['description'])) {
                $alter[] = 'ADD COLUMN description TEXT NULL AFTER title';
            }
            if (!isset($cols['location'])) {
                $alter[] = 'ADD COLUMN location VARCHAR(255) NULL AFTER description';
            }
            if (!isset($cols['assignment_type'])) {
                $alter[] = "ADD COLUMN assignment_type VARCHAR(20) NOT NULL DEFAULT 'bureau' AFTER location";
            }
            if (!isset($cols['assignment_chantier_id'])) {
                $alter[] = 'ADD COLUMN assignment_chantier_id INT NULL AFTER assignment_type';
            }
            if (!isset($cols['recurrence_rule_id'])) {
                $alter[] = 'ADD COLUMN recurrence_rule_id INT NULL AFTER planning_task_id';
            }
            if (!isset($cols['comm_task_id'])) {
                $alter[] = 'ADD COLUMN comm_task_id INT NULL AFTER recurrence_rule_id';
            }
            if (!isset($cols['event_group_key'])) {
                $alter[] = 'ADD COLUMN event_group_key VARCHAR(64) NULL AFTER comm_task_id';
            }
            if (!isset($cols['group_scope'])) {
                $alter[] = 'ADD COLUMN group_scope VARCHAR(20) NULL AFTER event_group_key';
            }
            if (!isset($cols['group_scope_label'])) {
                $alter[] = 'ADD COLUMN group_scope_label VARCHAR(255) NULL AFTER group_scope';
            }
            if (!isset($cols['completion_requested_by_user_id'])) {
                $alter[] = 'ADD COLUMN completion_requested_by_user_id INT NULL AFTER group_scope_label';
            }
            if (!isset($cols['completion_requested_at'])) {
                $alter[] = 'ADD COLUMN completion_requested_at DATETIME NULL AFTER completion_requested_by_user_id';
            }
            if (!isset($cols['completion_validated_by_user_id'])) {
                $alter[] = 'ADD COLUMN completion_validated_by_user_id INT NULL AFTER completion_requested_at';
            }
            if (!isset($cols['completion_validated_at'])) {
                $alter[] = 'ADD COLUMN completion_validated_at DATETIME NULL AFTER completion_validated_by_user_id';
            }

            if (!empty($alter)) {
                $this->pdo->exec('ALTER TABLE employee_schedule_event ' . implode(', ', $alter));
            }

            $this->ensureIndexes();
        } catch (Throwable $e) {
            // On reste en mode dégradé silencieux : ne pas bloquer toute la page pour une colonne optionnelle.
        }
    }

    private function ensureIndexes(): void
    {
        try {
            $indexes = [];
            $stmt = $this->pdo->query('SHOW INDEX FROM employee_schedule_event');
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = strtolower((string) ($row['Key_name'] ?? ''));
                    if ($key !== '') {
                        $indexes[$key] = true;
                    }
                }
            }

            $alterParts = [];
            if (!isset($indexes['idx_employee_schedule_recur'])) {
                $alterParts[] = 'ADD KEY idx_employee_schedule_recur (recurrence_rule_id, start_at)';
            }
            if (!isset($indexes['uq_employee_schedule_recur_start'])) {
                $alterParts[] = 'ADD UNIQUE KEY uq_employee_schedule_recur_start (recurrence_rule_id, start_at)';
            }
            if (!isset($indexes['idx_employee_schedule_comm_task'])) {
                $alterParts[] = 'ADD KEY idx_employee_schedule_comm_task (comm_task_id)';
            }
            if (!isset($indexes['uq_employee_schedule_comm_task'])) {
                $alterParts[] = 'ADD UNIQUE KEY uq_employee_schedule_comm_task (comm_task_id)';
            }
            if (!isset($indexes['idx_employee_schedule_group'])) {
                $alterParts[] = 'ADD KEY idx_employee_schedule_group (event_group_key)';
            }
            if (!isset($indexes['idx_employee_schedule_assignment'])) {
                $alterParts[] = 'ADD KEY idx_employee_schedule_assignment (assignment_type, assignment_chantier_id)';
            }

            if ($alterParts) {
                $this->pdo->exec('ALTER TABLE employee_schedule_event ' . implode(', ', $alterParts));
            }
        } catch (Throwable $e) {
            // silencieux
        }
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function getInitError(): ?string
    {
        return $this->initError;
    }

    public function resolvePersonnelIdByEmail(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id_personnel_soignant FROM personnel WHERE LOWER(TRIM(email_personnel_soignant)) = LOWER(TRIM(:email)) LIMIT 1');
        $stmt->execute(['email' => $email]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function resolvePersonnelIdByUserId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT p.id_personnel_soignant
            FROM user_devis_banamur u
            INNER JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
            WHERE u.id = :user_id
            LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function resolvePersonnelByMatricule(string $matricule): ?array
    {
        $matricule = trim($matricule);
        if ($matricule === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT p.id_personnel_soignant AS id, p.matricule_personnel_soignant AS matricule, p.nom_personnel_soignant AS nom,
                p.service_id AS service_id, COALESCE(s.lib_service, "") AS service_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            WHERE TRIM(p.matricule_personnel_soignant) = TRIM(:matricule)
            LIMIT 1');
        $stmt->execute(['matricule' => $matricule]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listSelectablePersonnel(): array
    {
        $stmt = $this->pdo->query('SELECT p.id_personnel_soignant AS id, p.matricule_personnel_soignant AS matricule, p.nom_personnel_soignant AS nom,
                p.service_id AS service_id, COALESCE(s.lib_service, "") AS service_label,
                MAX(CASE WHEN u.active = 1 THEN u.id ELSE NULL END) AS linked_user_id
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN user_devis_banamur u ON u.personnel_id = p.id_personnel_soignant
            WHERE TRIM(COALESCE(p.nom_personnel_soignant, "")) <> ""
            GROUP BY p.id_personnel_soignant, p.matricule_personnel_soignant, p.nom_personnel_soignant, p.service_id, s.lib_service
            ORDER BY p.nom_personnel_soignant ASC, p.matricule_personnel_soignant ASC');

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function listSelectableServices(): array
    {
        $stmt = $this->pdo->query('SELECT s.id_service AS id, s.lib_service AS label,
                COUNT(DISTINCT p.id_personnel_soignant) AS personnel_count
            FROM service s
            LEFT JOIN personnel p ON p.service_id = s.id_service
            GROUP BY s.id_service, s.lib_service
            ORDER BY s.lib_service ASC');

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    public function resolveActiveUserIdByPersonnelId(int $personnelId): ?int
    {
        if ($personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id
            FROM user_devis_banamur
            WHERE personnel_id = :personnel_id AND active = 1
            ORDER BY id ASC
            LIMIT 1');
        $stmt->execute(['personnel_id' => $personnelId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function listAssignablePersonnelIdsByService(int $serviceId): array
    {
        if ($serviceId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT p.id_personnel_soignant
            FROM personnel p
            WHERE p.service_id = :service_id
              AND TRIM(COALESCE(p.nom_personnel_soignant, "")) <> ""
            ORDER BY p.nom_personnel_soignant ASC, p.matricule_personnel_soignant ASC');
        $stmt->execute(['service_id' => $serviceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_values(array_filter(array_map('intval', is_array($rows) ? $rows : []), static fn(int $id): bool => $id > 0));
    }

    public function listAssignablePersonnelIdsForWholeTeam(): array
    {
        $rows = $this->pdo->query('SELECT p.id_personnel_soignant
            FROM personnel p
            WHERE TRIM(COALESCE(p.nom_personnel_soignant, "")) <> ""
            ORDER BY p.nom_personnel_soignant ASC, p.matricule_personnel_soignant ASC')->fetchAll(PDO::FETCH_COLUMN, 0);

        return array_values(array_filter(array_map('intval', is_array($rows) ? $rows : []), static fn(int $id): bool => $id > 0));
    }

    public function normalizeTargetPersonnelIds(array $targetPersonnelIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $targetPersonnelIds), static fn(int $id): bool => $id > 0)));
    }

    public function getPersonnelById(int $personnelId): ?array
    {
        if ($personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT p.id_personnel_soignant AS id, p.matricule_personnel_soignant AS matricule, p.nom_personnel_soignant AS nom,
                p.service_id AS service_id, COALESCE(s.lib_service, "") AS service_label,
                COALESCE(f.lib_fonction, "") AS fonction_label
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            WHERE p.id_personnel_soignant = :id
            LIMIT 1');
        $stmt->execute(['id' => $personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getPersonnelScheduleContext(int $personnelId): ?array
    {
        return $this->getPersonnelById($personnelId);
    }

    public function listQuickTasks(int $personnelId, int $limit = 30): array
    {
        if (!$this->ready || $personnelId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));
        try {
            $stmt = $this->pdo->prepare('SELECT qt.id, qt.personnel_id, qt.title, qt.description, qt.status_code, qt.created_at, qt.created_by_user_id,
                    p.nom_personnel_soignant AS personnel_name,
                    p.matricule_personnel_soignant AS personnel_matricule,
                    COALESCE(s.lib_service, "") AS service_label
                FROM employee_schedule_quick_task qt
                LEFT JOIN personnel p ON p.id_personnel_soignant = qt.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE qt.personnel_id = :personnel_id
                  AND qt.is_archived = 0
                  AND qt.scheduled_event_id IS NULL
                ORDER BY qt.created_at DESC, qt.id DESC
                LIMIT ' . (int)$limit);
            $stmt->execute(['personnel_id' => $personnelId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getQuickTaskRow(int $quickTaskId, int $personnelId): ?array
    {
        if (!$this->ready || $quickTaskId <= 0 || $personnelId <= 0) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT qt.id, qt.personnel_id, qt.title, qt.description, qt.status_code, qt.created_at, qt.created_by_user_id,
                    p.nom_personnel_soignant AS personnel_name,
                    p.matricule_personnel_soignant AS personnel_matricule,
                    COALESCE(s.lib_service, "") AS service_label
                FROM employee_schedule_quick_task qt
                LEFT JOIN personnel p ON p.id_personnel_soignant = qt.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE qt.id = :id
                  AND qt.personnel_id = :personnel_id
                  AND qt.is_archived = 0
                  AND qt.scheduled_event_id IS NULL
                LIMIT 1');
            $stmt->execute([
                'id' => $quickTaskId,
                'personnel_id' => $personnelId,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function createQuickTask(int $personnelId, string $title, ?int $createdByUserId = null, ?string $description = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $title = trim($title);
        $description = $description !== null ? trim($description) : null;

        if ($personnelId <= 0 || !$this->getPersonnelById($personnelId)) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre de la tache est requis.'];
        }
        if (mb_strlen($title) > 255) {
            $title = mb_substr($title, 0, 255);
        }
        if ($description === '') {
            $description = null;
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO employee_schedule_quick_task (personnel_id, title, description, status_code, created_by_user_id)
                VALUES (:personnel_id, :title, :description, :status_code, :created_by_user_id)');
            $stmt->execute([
                'personnel_id' => $personnelId,
                'title' => $title,
                'description' => $description,
                'status_code' => 'planifie',
                'created_by_user_id' => $createdByUserId,
            ]);

            return ['ok' => true, 'id' => (int)$this->pdo->lastInsertId()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Creation rapide impossible.'];
        }
    }

    public function markQuickTaskScheduled(int $quickTaskId, int $personnelId, int $eventId): bool
    {
        if (!$this->ready || $quickTaskId <= 0 || $personnelId <= 0 || $eventId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('UPDATE employee_schedule_quick_task
                SET scheduled_event_id = :event_id, scheduled_at = NOW(), is_archived = 1
                WHERE id = :id
                  AND personnel_id = :personnel_id
                  AND scheduled_event_id IS NULL');
            return (bool)$stmt->execute([
                'event_id' => $eventId,
                'id' => $quickTaskId,
                'personnel_id' => $personnelId,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function archiveQuickTask(int $quickTaskId, int $personnelId): bool
    {
        if (!$this->ready || $quickTaskId <= 0 || $personnelId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('UPDATE employee_schedule_quick_task
                SET is_archived = 1
                WHERE id = :id AND personnel_id = :personnel_id');
            return (bool)$stmt->execute([
                'id' => $quickTaskId,
                'personnel_id' => $personnelId,
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function getWeekdayLabels(): array
    {
        return [
            1 => 'Lundi',
            2 => 'Mardi',
            3 => 'Mercredi',
            4 => 'Jeudi',
            5 => 'Vendredi',
            6 => 'Samedi',
            7 => 'Dimanche',
        ];
    }

    public function getDefaultWorkWindows(): array
    {
        $windows = [];
        $startMinute = (self::WORKDAY_START_HOUR * 60) + self::WORKDAY_START_MINUTE;
        $endMinute = (self::WORKDAY_END_HOUR * 60) + self::WORKDAY_END_MINUTE;

        foreach ($this->getWeekdayLabels() as $weekday => $label) {
            $windows[$weekday] = $this->buildWindowRow($weekday, $label, true, $startMinute, $endMinute, 'default');
        }

        return $windows;
    }

    public function getServiceWorkWindows(int $serviceId): array
    {
        $windows = $this->getDefaultWorkWindows();
        if ($serviceId <= 0) {
            return $windows;
        }

        foreach ($this->loadWorkWindowRows('service', $serviceId) as $weekday => $row) {
            $label = $this->getWeekdayLabels()[$weekday] ?? ('Jour ' . $weekday);
            $windows[$weekday] = $this->buildWindowRow(
                $weekday,
                $label,
                !empty($row['is_working_day']),
                isset($row['start_minute']) ? (int) $row['start_minute'] : null,
                isset($row['end_minute']) ? (int) $row['end_minute'] : null,
                'service'
            );
        }

        return $windows;
    }

    public function getPersonnelWorkWindows(int $personnelId): array
    {
        $context = $this->getPersonnelScheduleContext($personnelId);
        $serviceId = (int) ($context['service_id'] ?? 0);
        $windows = $this->getServiceWorkWindows($serviceId);

        foreach ($this->loadWorkWindowRows('personnel', $personnelId) as $weekday => $row) {
            $label = $this->getWeekdayLabels()[$weekday] ?? ('Jour ' . $weekday);
            $windows[$weekday] = $this->buildWindowRow(
                $weekday,
                $label,
                !empty($row['is_working_day']),
                isset($row['start_minute']) ? (int) $row['start_minute'] : null,
                isset($row['end_minute']) ? (int) $row['end_minute'] : null,
                'personnel'
            );
        }

        return $windows;
    }

    public function saveServiceWorkWindows(int $serviceId, array $input, ?int $updatedByUserId = null): array
    {
        if ($serviceId <= 0) {
            return ['ok' => false, 'error' => 'Service invalide.'];
        }

        return $this->saveWorkWindows('service', $serviceId, $input, $updatedByUserId);
    }

    public function savePersonnelWorkWindows(int $personnelId, array $input, ?int $updatedByUserId = null): array
    {
        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }

        return $this->saveWorkWindows('personnel', $personnelId, $input, $updatedByUserId);
    }

    public function resetServiceWorkWindows(int $serviceId): bool
    {
        return $this->resetWorkWindows('service', $serviceId);
    }

    public function resetPersonnelWorkWindows(int $personnelId): bool
    {
        return $this->resetWorkWindows('personnel', $personnelId);
    }

    public function getDefaultDailyLoadThresholdHours(): float
    {
        return 9.0;
    }

    public function getServiceDailyLoadThresholdHours(int $serviceId): float
    {
        if ($serviceId <= 0) {
            return $this->getDefaultDailyLoadThresholdHours();
        }

        $value = $this->loadDailyLoadThresholdHours('service', $serviceId);
        return $value !== null ? $value : $this->getDefaultDailyLoadThresholdHours();
    }

    public function getPersonnelDailyLoadThresholdHours(int $personnelId): float
    {
        if ($personnelId <= 0) {
            return $this->getDefaultDailyLoadThresholdHours();
        }

        $value = $this->loadDailyLoadThresholdHours('personnel', $personnelId);
        if ($value !== null) {
            return $value;
        }

        $context = $this->getPersonnelScheduleContext($personnelId);
        $serviceId = (int)($context['service_id'] ?? 0);
        return $this->getServiceDailyLoadThresholdHours($serviceId);
    }

    public function saveServiceDailyLoadThresholdHours(int $serviceId, $hours, ?int $updatedByUserId = null): array
    {
        if ($serviceId <= 0) {
            return ['ok' => false, 'error' => 'Service invalide.'];
        }

        return $this->saveDailyLoadThresholdHours('service', $serviceId, $hours, $updatedByUserId);
    }

    public function savePersonnelDailyLoadThresholdHours(int $personnelId, $hours, ?int $updatedByUserId = null): array
    {
        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }

        return $this->saveDailyLoadThresholdHours('personnel', $personnelId, $hours, $updatedByUserId);
    }

    public function resetServiceDailyLoadThresholdHours(int $serviceId): bool
    {
        return $this->resetDailyLoadThresholdHours('service', $serviceId);
    }

    public function resetPersonnelDailyLoadThresholdHours(int $personnelId): bool
    {
        return $this->resetDailyLoadThresholdHours('personnel', $personnelId);
    }

    public function listEvents(int $personnelId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        if (!$this->ready) {
            return [];
        }
        if ($personnelId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, planning_task_id, comm_task_id,
            event_group_key, group_scope, group_scope_label
            FROM employee_schedule_event
            WHERE personnel_id = :personnel_id
              AND start_at < :end_at
              AND end_at > :start_at
            ORDER BY start_at ASC, end_at ASC');
        $stmt->execute([
            'personnel_id' => $personnelId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createEvent(int $personnelId, string $title, string $startAt, string $endAt, string $statusCode, ?int $createdByUserId = null, ?string $description = null, ?string $location = null, ?int $recurrenceRuleId = null, ?int $commTaskId = null, array $groupMeta = [], ?string $assignmentType = null, ?int $assignmentChantierId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }
        $title = trim($title);
        $statusCode = trim($statusCode);

        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre est requis.'];
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAt);
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endAt);

        if (!$start || !$end) {
            return ['ok' => false, 'error' => 'Dates/heures invalides.'];
        }
        if ($end <= $start) {
            return ['ok' => false, 'error' => 'La fin doit être après le début.'];
        }

        if ($this->hasOverlap($personnelId, $start, $end, null)) {
            return ['ok' => false, 'error' => 'Chevauchement détecté avec un autre créneau.'];
        }

        $allowedStatuses = $this->allowedStatusCodes();
        if (!isset($allowedStatuses[$statusCode])) {
            $statusCode = 'planifie';
        }

        $description = $description !== null ? trim($description) : null;
        $location = $location !== null ? trim($location) : null;
        if ($description === '') {
            $description = null;
        }
        if ($location === '') {
            $location = null;
        }
        $assignment = $this->normalizeAssignment($assignmentType, $assignmentChantierId);

        $groupKey = trim((string)($groupMeta['event_group_key'] ?? ''));
        $groupScope = trim((string)($groupMeta['group_scope'] ?? ''));
        $groupScopeLabel = trim((string)($groupMeta['group_scope_label'] ?? ''));
        if ($groupKey === '') {
            $groupKey = null;
        }
        if ($groupScope === '') {
            $groupScope = null;
        }
        if ($groupScopeLabel === '') {
            $groupScopeLabel = null;
        }

        $stmt = $this->pdo->prepare('INSERT INTO employee_schedule_event (personnel_id, title, description, location, assignment_type, assignment_chantier_id, start_at, end_at, status_code, created_by_user_id, recurrence_rule_id, comm_task_id, event_group_key, group_scope, group_scope_label)
            VALUES (:personnel_id, :title, :description, :location, :assignment_type, :assignment_chantier_id, :start_at, :end_at, :status_code, :created_by_user_id, :recurrence_rule_id, :comm_task_id, :event_group_key, :group_scope, :group_scope_label)');
        $stmt->execute([
            'personnel_id' => $personnelId,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'assignment_type' => $assignment['type'],
            'assignment_chantier_id' => $assignment['chantier_id'],
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
            'status_code' => $statusCode,
            'created_by_user_id' => $createdByUserId,
            'recurrence_rule_id' => $recurrenceRuleId !== null && $recurrenceRuleId > 0 ? (int) $recurrenceRuleId : null,
            'comm_task_id' => $commTaskId !== null && $commTaskId > 0 ? (int) $commTaskId : null,
            'event_group_key' => $groupKey,
            'group_scope' => $groupScope,
            'group_scope_label' => $groupScopeLabel,
        ]);

        return ['ok' => true, 'id' => (int) $this->pdo->lastInsertId()];
    }

    public function createGroupedEvents(array $targetPersonnelIds, string $title, string $startAt, string $endAt, string $statusCode, ?int $createdByUserId = null, ?string $description = null, ?string $location = null, array $groupMeta = [], ?string $assignmentType = null, ?int $assignmentChantierId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $targetPersonnelIds = $this->normalizeTargetPersonnelIds($targetPersonnelIds);
        if ($targetPersonnelIds === []) {
            return ['ok' => false, 'error' => 'Aucun personnel cible sélectionné.'];
        }

        $groupKey = trim((string)($groupMeta['event_group_key'] ?? ''));
        if ($groupKey === '') {
            $groupKey = 'grp_' . bin2hex(random_bytes(8));
        }
        $groupMeta['event_group_key'] = $groupKey;

        $created = [];
        $skipped = [];

        foreach ($targetPersonnelIds as $targetPersonnelId) {
            $res = $this->createEvent($targetPersonnelId, $title, $startAt, $endAt, $statusCode, $createdByUserId, $description, $location, null, null, $groupMeta, $assignmentType, $assignmentChantierId);
            if (!empty($res['ok'])) {
                $created[] = [
                    'personnel_id' => $targetPersonnelId,
                    'event_id' => (int)($res['id'] ?? 0),
                ];
                continue;
            }

            $skipped[] = [
                'personnel_id' => $targetPersonnelId,
                'error' => (string)($res['error'] ?? 'Création impossible.'),
            ];
        }

        return [
            'ok' => $created !== [],
            'created' => $created,
            'skipped' => $skipped,
            'event_group_key' => $groupKey,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'error' => $created === [] ? 'Aucun créneau n\'a pu être créé.' : null,
        ];
    }

    public function updateEvent(int $eventId, int $personnelId, string $title, string $startAt, string $endAt, string $statusCode, ?string $description = null, ?string $location = null, ?string $assignmentType = null, ?int $assignmentChantierId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }
        $title = trim($title);
        $statusCode = trim($statusCode);

        if ($eventId <= 0 || $personnelId <= 0) {
            return ['ok' => false, 'error' => 'Évènement invalide.'];
        }
        if ($title === '') {
            return ['ok' => false, 'error' => 'Le titre est requis.'];
        }

        $start = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $startAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $startAt);
        $end = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $endAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $endAt);
        if (!$start || !$end) {
            return ['ok' => false, 'error' => 'Dates/heures invalides.'];
        }
        if ($end <= $start) {
            return ['ok' => false, 'error' => 'La fin doit être après le début.'];
        }

        if ($this->hasOverlap($personnelId, $start, $end, $eventId)) {
            return ['ok' => false, 'error' => 'Chevauchement détecté avec un autre créneau.'];
        }

        $allowedStatuses = $this->allowedStatusCodes();
        if (!isset($allowedStatuses[$statusCode])) {
            $statusCode = 'planifie';
        }

        $description = $description !== null ? trim($description) : null;
        $location = $location !== null ? trim($location) : null;
        if ($description === '') {
            $description = null;
        }
        if ($location === '') {
            $location = null;
        }
        $assignment = $this->normalizeAssignment($assignmentType, $assignmentChantierId);

        $actorUserId = $this->currentUserId();
        $stmt = $this->pdo->prepare('UPDATE employee_schedule_event
            SET title = :title, description = :description, location = :location, assignment_type = :assignment_type, assignment_chantier_id = :assignment_chantier_id, start_at = :start_at, end_at = :end_at, status_code = :status_code,
                completion_requested_by_user_id = CASE WHEN :status_code_request = "validation_requise" THEN COALESCE(completion_requested_by_user_id, :actor_request_user_id) ELSE completion_requested_by_user_id END,
                completion_requested_at = CASE WHEN :status_code_request_at = "validation_requise" THEN COALESCE(completion_requested_at, NOW()) ELSE completion_requested_at END,
                completion_validated_by_user_id = CASE WHEN :status_code_validate = "termine" THEN :actor_validate_user_id ELSE completion_validated_by_user_id END,
                completion_validated_at = CASE WHEN :status_code_validate_at = "termine" THEN NOW() ELSE completion_validated_at END
            WHERE id = :id AND personnel_id = :personnel_id');
        $stmt->execute([
            'id' => $eventId,
            'personnel_id' => $personnelId,
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'assignment_type' => $assignment['type'],
            'assignment_chantier_id' => $assignment['chantier_id'],
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
            'status_code' => $statusCode,
            'status_code_request' => $statusCode,
            'status_code_request_at' => $statusCode,
            'status_code_validate' => $statusCode,
            'status_code_validate_at' => $statusCode,
            'actor_request_user_id' => $actorUserId,
            'actor_validate_user_id' => $actorUserId,
        ]);

        return ['ok' => true];
    }

    public function getEventRow(int $eventId, int $personnelId): ?array
    {
        if (!$this->ready) {
            return null;
        }
        if ($eventId <= 0 || $personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, personnel_id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, comm_task_id, event_group_key, group_scope, group_scope_label, completion_requested_by_user_id, completion_requested_at, completion_validated_by_user_id, completion_validated_at FROM employee_schedule_event WHERE id = :id AND personnel_id = :personnel_id LIMIT 1');
        $stmt->execute([
            'id' => $eventId,
            'personnel_id' => $personnelId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getEventRowById(int $eventId): ?array
    {
        if (!$this->ready || $eventId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, personnel_id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, comm_task_id, event_group_key, group_scope, group_scope_label, completion_requested_by_user_id, completion_requested_at, completion_validated_by_user_id, completion_validated_at FROM employee_schedule_event WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function attachCommunicationTaskId(int $eventId, int $personnelId, int $commTaskId): bool
    {
        if (!$this->ready || $eventId <= 0 || $personnelId <= 0 || $commTaskId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('UPDATE employee_schedule_event
            SET comm_task_id = :comm_task_id
            WHERE id = :id AND personnel_id = :personnel_id');

        return (bool)$stmt->execute([
            'comm_task_id' => $commTaskId,
            'id' => $eventId,
            'personnel_id' => $personnelId,
        ]);
    }

    public function getEventRowByCommunicationTaskId(int $commTaskId): ?array
    {
        if (!$this->ready || $commTaskId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, personnel_id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, comm_task_id, event_group_key, group_scope, group_scope_label, completion_requested_by_user_id, completion_requested_at, completion_validated_by_user_id, completion_validated_at FROM employee_schedule_event WHERE comm_task_id = :comm_task_id LIMIT 1');
        $stmt->execute(['comm_task_id' => $commTaskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listEventGroupRowsByKey(string $groupKey): array
    {
        if (!$this->ready) {
            return [];
        }

        $groupKey = trim($groupKey);
        if ($groupKey === '') {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id, personnel_id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, comm_task_id, event_group_key, group_scope, group_scope_label, completion_requested_by_user_id, completion_requested_at, completion_validated_by_user_id, completion_validated_at
            FROM employee_schedule_event
            WHERE event_group_key = :group_key
            ORDER BY personnel_id ASC, id ASC');
        $stmt->execute(['group_key' => $groupKey]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateEventDates(int $eventId, int $personnelId, string $startAt, string $endAt): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $row = $this->getEventRow($eventId, $personnelId);
        if (!$row) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        return $this->updateEvent(
            $eventId,
            $personnelId,
            (string)($row['title'] ?? ''),
            $startAt,
            $endAt,
            (string)($row['status_code'] ?? 'planifie'),
            array_key_exists('description', $row) ? (string)($row['description'] ?? '') : null,
            array_key_exists('location', $row) ? (string)($row['location'] ?? '') : null,
            (string)($row['assignment_type'] ?? 'bureau'),
            isset($row['assignment_chantier_id']) ? (int)$row['assignment_chantier_id'] : null
        );
    }

    public function updateEventGroup(int $eventId, int $personnelId, string $title, string $startAt, string $endAt, string $statusCode, ?string $description = null, ?string $location = null, ?string $assignmentType = null, ?int $assignmentChantierId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $anchor = $this->getEventRow($eventId, $personnelId);
        if (!$anchor) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        $groupKey = trim((string)($anchor['event_group_key'] ?? ''));
        if ($groupKey === '') {
            return ['ok' => false, 'error' => 'Ce créneau ne fait pas partie d\'un groupe.'];
        }

        $rows = $this->listEventGroupRowsByKey($groupKey);
        if ($rows === []) {
            return ['ok' => false, 'error' => 'Groupe introuvable.'];
        }

        $updated = [];
        $skipped = [];
        foreach ($rows as $row) {
            $memberId = (int)($row['id'] ?? 0);
            $memberPersonnelId = (int)($row['personnel_id'] ?? 0);
            if ($memberId <= 0 || $memberPersonnelId <= 0) {
                continue;
            }

            $result = $this->updateEvent($memberId, $memberPersonnelId, $title, $startAt, $endAt, $statusCode, $description, $location, $assignmentType, $assignmentChantierId);
            if (!empty($result['ok'])) {
                $updated[] = [
                    'event_id' => $memberId,
                    'personnel_id' => $memberPersonnelId,
                ];
                continue;
            }

            $skipped[] = [
                'event_id' => $memberId,
                'personnel_id' => $memberPersonnelId,
                'error' => (string)($result['error'] ?? 'Mise à jour impossible.'),
            ];
        }

        return [
            'ok' => $updated !== [],
            'updated' => $updated,
            'skipped' => $skipped,
            'updated_count' => count($updated),
            'skipped_count' => count($skipped),
            'event_group_key' => $groupKey,
            'error' => $updated === [] ? 'Aucun créneau du groupe n\'a pu être mis à jour.' : null,
        ];
    }

    public function updateEventStatus(int $eventId, int $personnelId, string $statusCode): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        if ($eventId <= 0 || $personnelId <= 0) {
            return ['ok' => false, 'error' => 'Évènement invalide.'];
        }

        $statusCode = trim($statusCode);
        $allowedStatuses = $this->allowedStatusCodes();
        if (!isset($allowedStatuses[$statusCode])) {
            return ['ok' => false, 'error' => 'Statut invalide.'];
        }

        $actorUserId = $this->currentUserId();
        $stmt = $this->pdo->prepare('UPDATE employee_schedule_event
            SET status_code = :status_code,
                completion_requested_by_user_id = CASE WHEN :status_code_request = "validation_requise" THEN COALESCE(completion_requested_by_user_id, :actor_request_user_id) ELSE completion_requested_by_user_id END,
                completion_requested_at = CASE WHEN :status_code_request_at = "validation_requise" THEN COALESCE(completion_requested_at, NOW()) ELSE completion_requested_at END,
                completion_validated_by_user_id = CASE WHEN :status_code_validate = "termine" THEN :actor_validate_user_id ELSE completion_validated_by_user_id END,
                completion_validated_at = CASE WHEN :status_code_validate_at = "termine" THEN NOW() ELSE completion_validated_at END
            WHERE id = :id AND personnel_id = :personnel_id');
        $stmt->execute([
            'id' => $eventId,
            'personnel_id' => $personnelId,
            'status_code' => $statusCode,
            'status_code_request' => $statusCode,
            'status_code_request_at' => $statusCode,
            'status_code_validate' => $statusCode,
            'status_code_validate_at' => $statusCode,
            'actor_request_user_id' => $actorUserId,
            'actor_validate_user_id' => $actorUserId,
        ]);

        return ['ok' => true];
    }

    public function updateEventStatusGroup(int $eventId, int $personnelId, string $statusCode): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $anchor = $this->getEventRow($eventId, $personnelId);
        if (!$anchor) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        $groupKey = trim((string)($anchor['event_group_key'] ?? ''));
        if ($groupKey === '') {
            return ['ok' => false, 'error' => 'Ce créneau ne fait pas partie d\'un groupe.'];
        }

        $statusCode = trim($statusCode);
        $allowedStatuses = $this->allowedStatusCodes();
        if (!isset($allowedStatuses[$statusCode])) {
            return ['ok' => false, 'error' => 'Statut invalide.'];
        }

        $actorUserId = $this->currentUserId();
        $stmt = $this->pdo->prepare('UPDATE employee_schedule_event
            SET status_code = :status_code,
                completion_requested_by_user_id = CASE WHEN :status_code_request = "validation_requise" THEN COALESCE(completion_requested_by_user_id, :actor_request_user_id) ELSE completion_requested_by_user_id END,
                completion_requested_at = CASE WHEN :status_code_request_at = "validation_requise" THEN COALESCE(completion_requested_at, NOW()) ELSE completion_requested_at END,
                completion_validated_by_user_id = CASE WHEN :status_code_validate = "termine" THEN :actor_validate_user_id ELSE completion_validated_by_user_id END,
                completion_validated_at = CASE WHEN :status_code_validate_at = "termine" THEN NOW() ELSE completion_validated_at END
            WHERE event_group_key = :group_key');
        $stmt->execute([
            'status_code' => $statusCode,
            'group_key' => $groupKey,
            'status_code_request' => $statusCode,
            'status_code_request_at' => $statusCode,
            'status_code_validate' => $statusCode,
            'status_code_validate_at' => $statusCode,
            'actor_request_user_id' => $actorUserId,
            'actor_validate_user_id' => $actorUserId,
        ]);

        return [
            'ok' => true,
            'updated_count' => (int)$stmt->rowCount(),
            'event_group_key' => $groupKey,
        ];
    }

    public function deleteEvent(int $eventId, int $personnelId): bool
    {
        if (!$this->ready) {
            return false;
        }
        if ($eventId <= 0 || $personnelId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_event WHERE id = :id AND personnel_id = :personnel_id');
        return (bool) $stmt->execute(['id' => $eventId, 'personnel_id' => $personnelId]);
    }

    public function deleteEventGroup(int $eventId, int $personnelId): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $anchor = $this->getEventRow($eventId, $personnelId);
        if (!$anchor) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        $groupKey = trim((string)($anchor['event_group_key'] ?? ''));
        if ($groupKey === '') {
            return ['ok' => false, 'error' => 'Ce créneau ne fait pas partie d\'un groupe.'];
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_event WHERE event_group_key = :group_key');
        $stmt->execute(['group_key' => $groupKey]);

        return [
            'ok' => true,
            'deleted_count' => (int)$stmt->rowCount(),
            'event_group_key' => $groupKey,
        ];
    }

    public function deleteRecurringSeries(int $eventId, int $personnelId): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $anchor = $this->getEventRow($eventId, $personnelId);
        if (!$anchor) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        $ruleId = (int)($anchor['recurrence_rule_id'] ?? 0);
        if ($ruleId <= 0) {
            return ['ok' => false, 'error' => 'Cette tâche ne fait pas partie d\'une répétition.'];
        }

        try {
            $this->pdo->prepare('UPDATE employee_schedule_recurrence_rule SET is_enabled = 0 WHERE id = :id')->execute(['id' => $ruleId]);
        } catch (Throwable $e) {
            // Si la table règle n'existe pas ou n'est pas accessible, on supprime au moins les occurrences existantes.
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_event WHERE recurrence_rule_id = :rule_id AND personnel_id = :personnel_id');
        $stmt->execute(['rule_id' => $ruleId, 'personnel_id' => $personnelId]);

        return [
            'ok' => true,
            'deleted_count' => (int)$stmt->rowCount(),
            'recurrence_rule_id' => $ruleId,
        ];
    }

    public function listRecurringRowsByRuleId(int $ruleId, int $personnelId): array
    {
        if (!$this->ready || $ruleId <= 0 || $personnelId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id, personnel_id, title, description, location, assignment_type, assignment_chantier_id, recurrence_rule_id, start_at, end_at, status_code, comm_task_id, event_group_key, group_scope, group_scope_label
            FROM employee_schedule_event
            WHERE recurrence_rule_id = :rule_id AND personnel_id = :personnel_id
            ORDER BY start_at ASC, id ASC');
        $stmt->execute(['rule_id' => $ruleId, 'personnel_id' => $personnelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function transferEvent(int $eventId, int $fromPersonnelId, int $toPersonnelId): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }
        if ($eventId <= 0 || $fromPersonnelId <= 0 || $toPersonnelId <= 0) {
            return ['ok' => false, 'error' => 'Transfert invalide.'];
        }
        if ($fromPersonnelId === $toPersonnelId) {
            return ['ok' => true, 'transferred' => false, 'personnel_id' => $fromPersonnelId];
        }

        $row = $this->getEventRow($eventId, $fromPersonnelId);
        if (!$row) {
            return ['ok' => false, 'error' => 'Créneau introuvable.'];
        }

        try {
            $start = new DateTimeImmutable((string)$row['start_at']);
            $end = new DateTimeImmutable((string)$row['end_at']);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Dates/heures invalides.'];
        }

        if ($this->hasOverlap($toPersonnelId, $start, $end, null)) {
            return ['ok' => false, 'error' => 'Transfert impossible : la personne cible a déjà une tâche sur ce créneau.'];
        }

        $exists = $this->getPersonnelById($toPersonnelId);
        if (!$exists) {
            return ['ok' => false, 'error' => 'Personne cible introuvable.'];
        }

        $stmt = $this->pdo->prepare('UPDATE employee_schedule_event SET personnel_id = :to_personnel_id WHERE id = :id AND personnel_id = :from_personnel_id');
        $stmt->execute([
            'to_personnel_id' => $toPersonnelId,
            'id' => $eventId,
            'from_personnel_id' => $fromPersonnelId,
        ]);

        return [
            'ok' => true,
            'transferred' => true,
            'personnel_id' => $toPersonnelId,
            'previous_personnel_id' => $fromPersonnelId,
        ];
    }

    public function deleteEventByCommunicationTaskId(int $commTaskId): bool
    {
        if (!$this->ready || $commTaskId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_event WHERE comm_task_id = :comm_task_id');
        return (bool) $stmt->execute(['comm_task_id' => $commTaskId]);
    }

    public function planAvailableSlot(int $personnelId, string $deadlineAt, int $durationMinutes, ?int $ignoreId = null): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }
        if ($personnelId <= 0) {
            return ['ok' => false, 'error' => 'Personnel invalide.'];
        }

        $durationMinutes = max(15, min(12 * 60, $durationMinutes));
        $deadline = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $deadlineAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $deadlineAt) ?: DateTimeImmutable::createFromFormat('Y-m-d H:i', $deadlineAt);
        if (!$deadline) {
            return ['ok' => false, 'error' => 'Deadline invalide pour le planning.'];
        }

        $interval = new DateInterval('PT' . $durationMinutes . 'M');
        $preferredStart = $this->alignSlotStart($deadline->sub($interval));
        $cursor = $preferredStart;
        $attempts = 0;
        while ($attempts < 96) {
            $candidateEnd = $cursor->add($interval);
            if ($this->isInsideWorkWindow($personnelId, $cursor, $candidateEnd) && !$this->hasOverlap($personnelId, $cursor, $candidateEnd, $ignoreId)) {
                return [
                    'ok' => true,
                    'start_at' => $cursor->format('Y-m-d H:i:s'),
                    'end_at' => $candidateEnd->format('Y-m-d H:i:s'),
                    'shifted' => $cursor != $preferredStart,
                ];
            }
            $cursor = $this->previousWorkSlot($personnelId, $cursor);
            $attempts++;
        }

        $forward = $this->nextWorkSlot($personnelId, $this->alignSlotStart($deadline));
        $maxEnd = $deadline->add(new DateInterval('P7D'));
        while ($forward < $maxEnd) {
            $candidateEnd = $forward->add($interval);
            if ($this->isInsideWorkWindow($personnelId, $forward, $candidateEnd) && !$this->hasOverlap($personnelId, $forward, $candidateEnd, $ignoreId)) {
                return [
                    'ok' => true,
                    'start_at' => $forward->format('Y-m-d H:i:s'),
                    'end_at' => $candidateEnd->format('Y-m-d H:i:s'),
                    'shifted' => true,
                ];
            }
            $forward = $this->nextWorkSlot($personnelId, $forward);
        }

        return ['ok' => false, 'error' => 'Aucun créneau disponible sans chevauchement dans l\'emploi du temps.'];
    }

    private function alignSlotStart(DateTimeImmutable $dateTime): DateTimeImmutable
    {
        $minute = (int) $dateTime->format('i');
        $roundedMinute = (int) (floor($minute / self::SLOT_STEP_MINUTES) * self::SLOT_STEP_MINUTES);
        return $dateTime->setTime((int) $dateTime->format('H'), $roundedMinute, 0);
    }

    private function previousWorkSlot(int $personnelId, DateTimeImmutable $dateTime): DateTimeImmutable
    {
        $candidate = $dateTime->sub(new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M'));

        for ($i = 0; $i < self::MAX_WINDOW_LOOKUP_DAYS; $i++) {
            $window = $this->getWorkWindowForDate($personnelId, $candidate);
            if ($window['enabled']) {
                $dayStart = $candidate->setTime($window['start_hour'], $window['start_minute_of_hour'], 0);
                $lastSlot = $candidate->setTime($window['end_hour'], $window['end_minute_of_hour'], 0)
                    ->sub(new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M'));

                if ($candidate > $lastSlot) {
                    return $lastSlot;
                }
                if ($candidate >= $dayStart) {
                    return $candidate;
                }
            }

            $candidate = $candidate->sub(new DateInterval('P1D'))->setTime(23, 45, 0);
        }

        return $candidate;
    }

    private function nextWorkSlot(int $personnelId, DateTimeImmutable $dateTime): DateTimeImmutable
    {
        $candidate = $dateTime;

        for ($i = 0; $i < self::MAX_WINDOW_LOOKUP_DAYS; $i++) {
            $window = $this->getWorkWindowForDate($personnelId, $candidate);
            if ($window['enabled']) {
                $dayStart = $candidate->setTime($window['start_hour'], $window['start_minute_of_hour'], 0);
                $dayEnd = $candidate->setTime($window['end_hour'], $window['end_minute_of_hour'], 0);

                if ($candidate < $dayStart) {
                    return $dayStart;
                }

                $nextCandidate = $candidate->add(new DateInterval('PT' . self::SLOT_STEP_MINUTES . 'M'));
                if ($nextCandidate < $dayEnd) {
                    return $nextCandidate;
                }
            }

            $candidate = $candidate->add(new DateInterval('P1D'))->setTime(0, 0, 0);
        }

        return $candidate;
    }

    private function isInsideWorkWindow(int $personnelId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $window = $this->getWorkWindowForDate($personnelId, $start);
        if (!$window['enabled']) {
            return false;
        }

        $dayStart = $start->setTime($window['start_hour'], $window['start_minute_of_hour'], 0);
        $dayEnd = $start->setTime($window['end_hour'], $window['end_minute_of_hour'], 0);
        return $start >= $dayStart
            && $end <= $dayEnd
            && $start->format('Y-m-d') === $end->format('Y-m-d');
    }

    private function saveWorkWindows(string $scopeType, int $scopeId, array $input, ?int $updatedByUserId): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        try {
            $normalized = $this->normalizeWorkWindowInput($input);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage() !== '' ? $e->getMessage() : 'Horaires invalides.'];
        }

        try {
            $this->pdo->beginTransaction();
            $delete = $this->pdo->prepare('DELETE FROM employee_schedule_work_window WHERE scope_type = :scope_type AND scope_id = :scope_id');
            $delete->execute([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ]);

            $insert = $this->pdo->prepare('INSERT INTO employee_schedule_work_window (scope_type, scope_id, weekday, is_working_day, start_minute, end_minute, updated_by_user_id)
                VALUES (:scope_type, :scope_id, :weekday, :is_working_day, :start_minute, :end_minute, :updated_by_user_id)');

            foreach ($normalized as $weekday => $row) {
                $insert->execute([
                    'scope_type' => $scopeType,
                    'scope_id' => $scopeId,
                    'weekday' => $weekday,
                    'is_working_day' => $row['enabled'] ? 1 : 0,
                    'start_minute' => $row['enabled'] ? $row['start_minute'] : null,
                    'end_minute' => $row['enabled'] ? $row['end_minute'] : null,
                    'updated_by_user_id' => $updatedByUserId,
                ]);
            }

            $this->pdo->commit();
            $this->resetWorkWindowCache();
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'Enregistrement des horaires impossible.'];
        }
    }

    private function saveDailyLoadThresholdHours(string $scopeType, int $scopeId, $hours, ?int $updatedByUserId): array
    {
        if (!$this->ready) {
            return ['ok' => false, 'error' => $this->initError ?? 'Module indisponible.'];
        }

        $normalized = $this->normalizeDailyLoadThresholdHours($hours);
        if ($normalized === null) {
            return ['ok' => false, 'error' => 'Seuil de surcharge invalide.'];
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO employee_schedule_load_threshold (scope_type, scope_id, daily_threshold_hours, updated_by_user_id)
                VALUES (:scope_type, :scope_id, :daily_threshold_hours, :updated_by_user_id)
                ON DUPLICATE KEY UPDATE daily_threshold_hours = VALUES(daily_threshold_hours), updated_by_user_id = VALUES(updated_by_user_id)');
            $stmt->execute([
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'daily_threshold_hours' => $normalized,
                'updated_by_user_id' => $updatedByUserId,
            ]);

            $this->resetLoadThresholdCache();
            return ['ok' => true, 'value' => $normalized];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Enregistrement du seuil de surcharge impossible.'];
        }
    }

    private function resetDailyLoadThresholdHours(string $scopeType, int $scopeId): bool
    {
        if (!$this->ready || $scopeId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_load_threshold WHERE scope_type = :scope_type AND scope_id = :scope_id');
        $ok = (bool)$stmt->execute([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        if ($ok) {
            $this->resetLoadThresholdCache();
        }

        return $ok;
    }

    private function loadDailyLoadThresholdHours(string $scopeType, int $scopeId): ?float
    {
        if ($scopeId <= 0) {
            return null;
        }

        $cacheKey = $scopeType . ':' . $scopeId;
        if (array_key_exists($cacheKey, $this->loadThresholdCache)) {
            return $this->loadThresholdCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare('SELECT daily_threshold_hours
            FROM employee_schedule_load_threshold
            WHERE scope_type = :scope_type AND scope_id = :scope_id
            LIMIT 1');
        $stmt->execute([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        $value = $stmt->fetchColumn();
        $normalized = $value !== false ? round((float)$value, 2) : null;
        $this->loadThresholdCache[$cacheKey] = $normalized;
        return $normalized;
    }

    private function resetWorkWindows(string $scopeType, int $scopeId): bool
    {
        if (!$this->ready || $scopeId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM employee_schedule_work_window WHERE scope_type = :scope_type AND scope_id = :scope_id');
        $ok = (bool) $stmt->execute([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        if ($ok) {
            $this->resetWorkWindowCache();
        }

        return $ok;
    }

    private function loadWorkWindowRows(string $scopeType, int $scopeId): array
    {
        if ($scopeId <= 0) {
            return [];
        }

        $cacheKey = $scopeType . ':' . $scopeId;
        if (isset($this->workWindowCache[$cacheKey])) {
            return $this->workWindowCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare('SELECT weekday, is_working_day, start_minute, end_minute
            FROM employee_schedule_work_window
            WHERE scope_type = :scope_type AND scope_id = :scope_id');
        $stmt->execute([
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
        ]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $weekday = (int) ($row['weekday'] ?? 0);
            if ($weekday < 1 || $weekday > 7) {
                continue;
            }
            $rows[$weekday] = $row;
        }

        $this->workWindowCache[$cacheKey] = $rows;
        return $rows;
    }

    private function getWorkWindowForDate(int $personnelId, DateTimeImmutable $date): array
    {
        $windows = $this->getPersonnelWorkWindows($personnelId);
        $weekday = (int) $date->format('N');
        $row = $windows[$weekday] ?? $this->getDefaultWorkWindows()[$weekday];

        $startMinute = (int) ($row['start_minute'] ?? ((self::WORKDAY_START_HOUR * 60) + self::WORKDAY_START_MINUTE));
        $endMinute = (int) ($row['end_minute'] ?? ((self::WORKDAY_END_HOUR * 60) + self::WORKDAY_END_MINUTE));

        return [
            'enabled' => !empty($row['enabled']),
            'start_minute' => $startMinute,
            'end_minute' => $endMinute,
            'start_hour' => intdiv($startMinute, 60),
            'start_minute_of_hour' => $startMinute % 60,
            'end_hour' => intdiv($endMinute, 60),
            'end_minute_of_hour' => $endMinute % 60,
        ];
    }

    private function normalizeWorkWindowInput(array $input): array
    {
        $normalized = [];
        foreach ($this->getWeekdayLabels() as $weekday => $label) {
            $row = $input[$weekday] ?? [];
            $enabled = !empty($row['enabled']);
            if (!$enabled) {
                $normalized[$weekday] = [
                    'enabled' => false,
                    'start_minute' => null,
                    'end_minute' => null,
                ];
                continue;
            }

            $startMinute = $this->parseTimeToMinute((string) ($row['start_time'] ?? '07:00'));
            $endMinute = $this->parseTimeToMinute((string) ($row['end_time'] ?? '18:00'));
            if ($startMinute === null || $endMinute === null || $endMinute <= $startMinute) {
                throw new RuntimeException('Horaire invalide pour ' . strtolower($label) . '.');
            }

            $normalized[$weekday] = [
                'enabled' => true,
                'start_minute' => $startMinute,
                'end_minute' => $endMinute,
            ];
        }

        return $normalized;
    }

    private function parseTimeToMinute(string $value): ?int
    {
        $value = trim($value);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }

    private function normalizeDailyLoadThresholdHours($value): ?float
    {
        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        if ($value === '' || $value === null) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $hours = round((float)$value, 2);
        if ($hours < 1 || $hours > 24) {
            return null;
        }

        return $hours;
    }

    private function formatMinuteAsTime(?int $minute): string
    {
        if ($minute === null) {
            return '';
        }

        $hour = intdiv($minute, 60);
        $minuteOfHour = $minute % 60;
        return sprintf('%02d:%02d', $hour, $minuteOfHour);
    }

    private function buildWindowRow(int $weekday, string $label, bool $enabled, ?int $startMinute, ?int $endMinute, string $source): array
    {
        return [
            'weekday' => $weekday,
            'label' => $label,
            'enabled' => $enabled,
            'start_minute' => $startMinute,
            'end_minute' => $endMinute,
            'start_time' => $this->formatMinuteAsTime($startMinute),
            'end_time' => $this->formatMinuteAsTime($endMinute),
            'source' => $source,
        ];
    }

    private function resetWorkWindowCache(): void
    {
        $this->workWindowCache = [];
    }

    private function resetLoadThresholdCache(): void
    {
        $this->loadThresholdCache = [];
    }

    private function hasOverlap(int $personnelId, DateTimeInterface $start, DateTimeInterface $end, ?int $ignoreId): bool
    {
        if (!$this->ready) {
            return false;
        }
        $sql = 'SELECT COUNT(*)
            FROM employee_schedule_event
            WHERE personnel_id = :personnel_id
              AND start_at < :end_at
              AND end_at > :start_at';

        $params = [
            'personnel_id' => $personnelId,
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];

        if ($ignoreId) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function normalizeAssignment(?string $assignmentType, ?int $chantierId): array
    {
        $type = strtolower(trim((string)$assignmentType));
        if (!in_array($type, ['bureau', 'chantier'], true)) {
            $type = 'bureau';
        }

        $cid = $type === 'chantier' ? max(0, (int)$chantierId) : 0;
        return [
            'type' => $type,
            'chantier_id' => $cid > 0 ? $cid : null,
        ];
    }

    public function allowedStatusCodes(): array
    {
        return [
            'planifie' => 'Planifiée',
            'en_cours' => 'En cours',
            'pause' => 'En pause',
            'validation_requise' => 'Validation requise',
            'termine' => 'Terminée',
            'annule' => 'Annulée',
        ];
    }

    public function resolveHierarchicalCompletionStatus(string $requestedStatus, int $actorUserId, int $targetPersonnelId): string
    {
        $requestedStatus = trim($requestedStatus);
        if ($requestedStatus !== 'termine') {
            return $requestedStatus;
        }

        return $this->canValidateTaskCompletion($actorUserId, $targetPersonnelId) ? 'termine' : 'validation_requise';
    }

    public function listPendingCompletionValidations(int $actorUserId, DateTimeInterface $start, DateTimeInterface $end, int $serviceId = 0): array
    {
        if (!$this->ready || $actorUserId <= 0) {
            return [];
        }

        $where = [
            'e.status_code = "validation_requise"',
            'e.start_at < :end_at',
            'e.end_at > :start_at',
        ];
        $params = [
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];
        if ($serviceId > 0) {
            $where[] = 'p.service_id = :service_id';
            $params['service_id'] = $serviceId;
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT e.id, e.personnel_id, e.title, e.description, e.location, e.start_at, e.end_at, e.status_code,
                       e.completion_requested_by_user_id, e.completion_requested_at,
                       p.nom_personnel_soignant AS personnel_name,
                       p.matricule_personnel_soignant AS personnel_matricule,
                       p.service_id,
                       COALESCE(s.lib_service, "") AS service_label
                FROM employee_schedule_event e
                INNER JOIN personnel p ON p.id_personnel_soignant = e.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY e.completion_requested_at ASC, e.end_at ASC, p.nom_personnel_soignant ASC
            ');
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        return array_values(array_filter($rows, function (array $row) use ($actorUserId): bool {
            return $this->canValidateTaskCompletion($actorUserId, (int)($row['personnel_id'] ?? 0));
        }));
    }

    public function userHasConfiguredCompletionValidationScope(int $actorUserId): bool
    {
        if ($actorUserId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('
                SELECT 1
                FROM rh_service_hierarchy
                WHERE direct_manager_user_id = :user_id
                   OR department_head_user_id = :user_id
                LIMIT 1
            ');
            $stmt->execute(['user_id' => $actorUserId]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    public function canValidateTaskCompletion(int $actorUserId, int $targetPersonnelId): bool
    {
        if ($actorUserId <= 0 || $targetPersonnelId <= 0) {
            return false;
        }

        $profile = $this->getUserPersonnelProfile($actorUserId);
        if (!$profile) {
            return false;
        }

        $function = $this->normalizeComparableText((string)($profile['fonction_label'] ?? ''));
        $role = $this->normalizeComparableText((string)($profile['role_label'] ?? ''));
        if (strpos($function, 'responsable') !== false
            || strpos($function, 'chef de departement') !== false
            || strpos($function, 'directeur') !== false
            || strpos($role, 'directeur') !== false) {
            return true;
        }

        $target = $this->getPersonnelById($targetPersonnelId);
        $serviceId = (int)($target['service_id'] ?? 0);
        if ($serviceId <= 0) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT direct_manager_user_id, department_head_user_id FROM rh_service_hierarchy WHERE service_id = :service_id LIMIT 1');
            $stmt->execute(['service_id' => $serviceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return in_array($actorUserId, [
                (int)($row['direct_manager_user_id'] ?? 0),
                (int)($row['department_head_user_id'] ?? 0),
            ], true);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getUserPersonnelProfile(int $userId): ?array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT u.id AS user_id, COALESCE(r.lib_role_devis, "") AS role_label,
                    p.id_personnel_soignant AS personnel_id, p.service_id,
                    COALESCE(f.lib_fonction, "") AS fonction_label
                FROM user_devis_banamur u
                LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id
                LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
                WHERE u.id = :user_id AND u.active = 1
                LIMIT 1');
            $stmt->execute(['user_id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function currentUserId(): ?int
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        return $userId > 0 ? $userId : null;
    }

    private function normalizeComparableText(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return is_string($converted) ? strtolower($converted) : $value;
    }

    public function listAssignedPlanningTasks(int $personnelId, DateTimeInterface $start, DateTimeInterface $end): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $startDate = (new DateTimeImmutable($start->format('Y-m-d')))->format('Y-m-d');
        $endDate = (new DateTimeImmutable($end->format('Y-m-d')))->format('Y-m-d');

        $sql = "
            SELECT
                t.id,
                t.task_name,
                t.start_date,
                t.end_date,
                latest.status_code,
                latest.progress_percent
            FROM project_planning_task t
            INNER JOIN project_planning_task_assignment a ON a.task_id = t.id
            INNER JOIN project_planning_task_assignment_personnel ap ON ap.assignment_id = a.id AND ap.personnel_id = :personnel_id
            LEFT JOIN (
                SELECT tu.task_id, tu.status_code, tu.progress_percent
                FROM project_planning_task_update tu
                INNER JOIN (
                    SELECT task_id, MAX(id) AS max_id
                    FROM project_planning_task_update
                    GROUP BY task_id
                ) mx ON mx.task_id = tu.task_id AND mx.max_id = tu.id
            ) latest ON latest.task_id = t.id
            WHERE
                (
                    (t.start_date IS NULL AND t.end_date IS NULL)
                    OR (t.start_date IS NULL AND t.end_date >= :start_date)
                    OR (t.end_date IS NULL AND t.start_date <= :end_date)
                    OR (t.start_date <= :end_date AND t.end_date >= :start_date)
                )
            ORDER BY COALESCE(t.start_date, '9999-12-31') ASC, t.task_name ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'personnel_id' => $personnelId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
