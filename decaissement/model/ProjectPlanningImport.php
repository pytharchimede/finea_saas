<?php

class ProjectPlanningImport
{
    private PDO $pdo;

    private bool $hasArchiveColumns = false;

    private const REALTIME_STATUSES = ['non_demarre', 'en_cours', 'bloque', 'termine'];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTablesExist();

        // Auto-migration (best-effort): colonnes d'archivage chantier
        // Utilisé pour exclure les chantiers terminés des vues "actives" (DG / planning chantier).
        $this->ensureArchiveColumns();
    }

    private function ensureArchiveColumns(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $columns = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $this->hasArchiveColumns = in_array('archived_at', $columns, true);

            $alter = [];
            if (!in_array('archived_at', $columns, true)) {
                $alter[] = "ADD COLUMN archived_at DATETIME NULL";
            }
            if (!in_array('archived_by', $columns, true)) {
                $alter[] = "ADD COLUMN archived_by INT NULL";
            }
            if (!in_array('archived_note', $columns, true)) {
                $alter[] = "ADD COLUMN archived_note TEXT NULL";
            }
            if (!in_array('closed_date', $columns, true)) {
                $alter[] = "ADD COLUMN closed_date DATE NULL";
            }

            if ($alter) {
                $this->pdo->exec('ALTER TABLE chantier_banamur ' . implode(', ', $alter));
            }

            // Index (best-effort)
            try {
                $idx = $this->pdo->query("SHOW INDEX FROM chantier_banamur WHERE Key_name = 'idx_chantier_archived_at'")
                    ->fetch(PDO::FETCH_ASSOC);
                if (!$idx) {
                    $this->pdo->exec('CREATE INDEX idx_chantier_archived_at ON chantier_banamur (archived_at)');
                }
            } catch (Throwable $e) {
                // ignore
            }

            $columns2 = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);
            $this->hasArchiveColumns = in_array('archived_at', $columns2, true);
        } catch (Throwable $e) {
            // Si pas de droits ALTER ou table indisponible: on ne filtre pas.
            $this->hasArchiveColumns = false;
        }

        $done = true;
    }

    public function getActiveImportByChantier(int $chantierId): ?array
    {
        if ($chantierId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_planning_import WHERE chantier_id = :chantier_id AND is_active = 1 ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['chantier_id' => $chantierId]);
        $import = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$import) {
            return null;
        }

        $taskStmt = $this->pdo->prepare(
            'SELECT * FROM project_planning_task WHERE import_id = :import_id ORDER BY sort_index ASC, id ASC'
        );
        $taskStmt->execute(['import_id' => (int)$import['id']]);
        $tasks = $taskStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($tasks as $index => $task) {
            $tasks[$index] = $this->normalizeTaskDateFields($task);
        }

        $import['tasks'] = $tasks;
        return $import;
    }

    public function replaceImportForChantier(int $chantierId, array $meta, array $tasks): array
    {
        if ($chantierId <= 0) {
            throw new InvalidArgumentException('chantier_id invalide');
        }
        if (empty($tasks)) {
            throw new InvalidArgumentException('Aucune tache importable detectee');
        }

        $sourceLabel = trim((string)($meta['source_label'] ?? 'Import Project'));
        $sourceType = trim((string)($meta['source_type'] ?? 'project'));
        $importedBy = trim((string)($meta['imported_by'] ?? 'SYSTEM'));
        $rawPayload = (string)($meta['raw_payload'] ?? '');

        $this->pdo->beginTransaction();

        try {
            $disableStmt = $this->pdo->prepare('UPDATE project_planning_import SET is_active = 0 WHERE chantier_id = :chantier_id');
            $disableStmt->execute(['chantier_id' => $chantierId]);

            $insertImport = $this->pdo->prepare(
                'INSERT INTO project_planning_import (
                    chantier_id,
                    source_label,
                    source_type,
                    task_count,
                    imported_by,
                    raw_payload,
                    is_active,
                    created_at,
                    updated_at
                ) VALUES (
                    :chantier_id,
                    :source_label,
                    :source_type,
                    :task_count,
                    :imported_by,
                    :raw_payload,
                    1,
                    NOW(),
                    NOW()
                )'
            );
            $insertImport->execute([
                'chantier_id' => $chantierId,
                'source_label' => $sourceLabel,
                'source_type' => $sourceType,
                'task_count' => count($tasks),
                'imported_by' => $importedBy,
                'raw_payload' => $rawPayload !== '' ? $rawPayload : null,
            ]);

            $importId = (int)$this->pdo->lastInsertId();
            if ($importId <= 0) {
                throw new RuntimeException('Impossible de creer l import du planning');
            }

            $insertTask = $this->pdo->prepare(
                'INSERT INTO project_planning_task (
                    import_id,
                    sort_index,
                    task_uid,
                    parent_uid,
                    outline_level,
                    task_name,
                    start_date,
                    end_date,
                    duration_label,
                    predecessors_label,
                    is_summary,
                    group_label,
                    created_at,
                    updated_at
                ) VALUES (
                    :import_id,
                    :sort_index,
                    :task_uid,
                    :parent_uid,
                    :outline_level,
                    :task_name,
                    :start_date,
                    :end_date,
                    :duration_label,
                    :predecessors_label,
                    :is_summary,
                    :group_label,
                    NOW(),
                    NOW()
                )'
            );

            foreach ($tasks as $index => $task) {
                $insertTask->execute([
                    'import_id' => $importId,
                    'sort_index' => (int)($task['sort_index'] ?? ($index + 1)),
                    'task_uid' => trim((string)($task['task_uid'] ?? ('task_' . ($index + 1)))),
                    'parent_uid' => ($task['parent_uid'] ?? null) !== null && trim((string)$task['parent_uid']) !== '' ? trim((string)$task['parent_uid']) : null,
                    'outline_level' => max(1, (int)($task['outline_level'] ?? 1)),
                    'task_name' => trim((string)($task['task_name'] ?? 'Tache importee')),
                    'start_date' => ($task['start_date'] ?? null) ?: null,
                    'end_date' => ($task['end_date'] ?? null) ?: null,
                    'duration_label' => trim((string)($task['duration_label'] ?? '')),
                    'predecessors_label' => trim((string)($task['predecessors_label'] ?? '')),
                    'is_summary' => !empty($task['is_summary']) ? 1 : 0,
                    'group_label' => trim((string)($task['group_label'] ?? '')),
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $result = $this->getActiveImportByChantier($chantierId);
        if ($result === null) {
            throw new RuntimeException('Import enregistre mais introuvable ensuite');
        }

        return $result;
    }

    public function saveTaskRealtimeUpdate(int $taskId, array $payload): array
    {
        if ($taskId <= 0) {
            throw new InvalidArgumentException('task_id invalide');
        }

        $task = $this->getTaskById($taskId);
        if ($task === null) {
            throw new RuntimeException('Tache Project introuvable');
        }
        if (!empty($task['is_summary'])) {
            throw new RuntimeException('Le suivi manuel est reserve aux taches feuilles');
        }

        $status = trim((string)($payload['status'] ?? 'en_cours'));
        if (!in_array($status, self::REALTIME_STATUSES, true)) {
            $status = 'en_cours';
        }

        $progress = max(0, min(100, (float)($payload['progress'] ?? 0)));
        $comment = trim((string)($payload['comment'] ?? ''));
        if (function_exists('mb_substr')) {
            $comment = mb_substr($comment, 0, 1000, 'UTF-8');
        } else {
            $comment = substr($comment, 0, 1000);
        }

        $updatedBy = trim((string)($payload['updated_by'] ?? 'SYSTEM'));
        if ($updatedBy === '') {
            $updatedBy = 'SYSTEM';
        }

        $meetingDate = trim((string)($payload['meeting_date'] ?? ''));
        if ($meetingDate === '') {
            $meetingDate = date('Y-m-d');
        }
        $meetingDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $meetingDate);
        if (!$meetingDateObject instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Date de reunion invalide');
        }
        $meetingDate = $meetingDateObject->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_planning_task_update (
                import_id,
                task_id,
                meeting_date,
                progress_percent,
                status_code,
                comment_text,
                updated_by,
                created_at
            ) VALUES (
                :import_id,
                :task_id,
                :meeting_date,
                :progress_percent,
                :status_code,
                :comment_text,
                :updated_by,
                NOW()
            )'
        );
        $stmt->execute([
            'import_id' => (int)$task['import_id'],
            'task_id' => $taskId,
            'meeting_date' => $meetingDate,
            'progress_percent' => $progress,
            'status_code' => $status,
            'comment_text' => $comment !== '' ? $comment : null,
            'updated_by' => $updatedBy,
        ]);

        $latestMap = $this->getLatestRealtimeUpdatesByImport((int)$task['import_id']);
        return $latestMap[$taskId] ?? [];
    }

    public function getRealtimeSnapshotByImport(int $importId, int $recentLimit = 15): array
    {
        if ($importId <= 0) {
            return [
                'latest_by_task' => [],
                'recent_updates' => [],
            ];
        }

        return [
            'latest_by_task' => $this->getLatestRealtimeUpdatesByImport($importId),
            'recent_updates' => $this->getRecentRealtimeUpdatesByImport($importId, $recentLimit),
        ];
    }

    public function getRealtimeUpdatesByMeetingDate(int $importId, string $meetingDate): array
    {
        if ($importId <= 0) {
            return [];
        }

        $meetingDate = trim($meetingDate);
        $meetingDateObject = DateTimeImmutable::createFromFormat('Y-m-d', $meetingDate);
        if (!$meetingDateObject instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Date de reunion invalide');
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                ptu.id,
                ptu.import_id,
                ptu.task_id,
                ptu.meeting_date,
                ptu.progress_percent,
                ptu.status_code,
                ptu.comment_text,
                ptu.updated_by,
                ptu.created_at,
                ptask.task_uid,
                ptask.task_name,
                ptask.outline_level,
                ptask.start_date,
                ptask.end_date,
                ptask.duration_label,
                ptask.predecessors_label,
                ptask.group_label,
                ptask.is_summary
             FROM project_planning_task_update ptu
             INNER JOIN project_planning_task ptask ON ptask.id = ptu.task_id
             WHERE ptu.import_id = :import_id
               AND ptu.meeting_date = :meeting_date
             ORDER BY ptu.created_at DESC, ptu.id DESC'
        );
        $stmt->execute([
            'import_id' => $importId,
            'meeting_date' => $meetingDateObject->format('Y-m-d'),
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $index => $row) {
            $rows[$index] = $this->normalizeTaskDateFields($row);
        }

        return $rows;
    }

    public function getPlanningDashboardSummaries(array $filters = []): array
    {
        $meetingDateFilter = $this->normalizeDashboardMeetingDate($filters['meeting_date'] ?? null);
        $conductorIdFilter = max(0, (int)($filters['conductor_id'] ?? 0));

        $whereActive = $this->hasArchiveColumns ? 'WHERE archived_at IS NULL' : '';

        $chantierStmt = $this->pdo->query(
            "SELECT
                id,
                code,
                titre,
                COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id)) AS libelle
             FROM chantier_banamur
             $whereActive
             ORDER BY COALESCE(NULLIF(TRIM(titre), ''), NULLIF(TRIM(code), ''), CONCAT('Chantier #', id)) ASC"
        );
        $chantiers = $chantierStmt ? ($chantierStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        if (empty($chantiers)) {
            return [];
        }

        $importStmt = $this->pdo->query(
            'SELECT * FROM project_planning_import WHERE is_active = 1 ORDER BY chantier_id ASC, created_at DESC, id DESC'
        );
        $importRows = $importStmt ? ($importStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $importsByChantier = [];
        $importIds = [];
        foreach ($importRows as $importRow) {
            $chantierId = (int)($importRow['chantier_id'] ?? 0);
            if ($chantierId <= 0 || isset($importsByChantier[$chantierId])) {
                continue;
            }

            $importsByChantier[$chantierId] = $importRow;
            $importIds[] = (int)($importRow['id'] ?? 0);
        }

        $tasksByImport = [];
        $latestByImport = [];
        $snapshotByImport = [];
        $updateStatsByImport = [];
        $meetingDateMatchByImport = [];
        $budgetsByChantier = $this->getPlanningDashboardBudgetMap();
        $conductorsByChantier = $this->getPlanningDashboardConductorsMap();

        if (!empty($importIds)) {
            $placeholders = implode(',', array_fill(0, count($importIds), '?'));

            $taskStmt = $this->pdo->prepare(
                'SELECT * FROM project_planning_task WHERE import_id IN (' . $placeholders . ') ORDER BY import_id ASC, sort_index ASC, id ASC'
            );
            $taskStmt->execute($importIds);
            $taskRows = $taskStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($taskRows as $taskRow) {
                $taskRow = $this->normalizeTaskDateFields($taskRow);
                $tasksByImport[(int)($taskRow['import_id'] ?? 0)][] = $taskRow;
            }

            $updateStmt = $this->pdo->prepare(
                'SELECT
                    id,
                    import_id,
                    task_id,
                    meeting_date,
                    progress_percent,
                    status_code,
                    comment_text,
                    updated_by,
                    created_at
                 FROM project_planning_task_update
                 WHERE import_id IN (' . $placeholders . ')
                 ORDER BY import_id ASC, id DESC'
            );
            $updateStmt->execute($importIds);
            $updateRows = $updateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($updateRows as $updateRow) {
                $importId = (int)($updateRow['import_id'] ?? 0);
                $taskId = (int)($updateRow['task_id'] ?? 0);
                $meetingDate = trim((string)($updateRow['meeting_date'] ?? ''));

                if ($importId <= 0 || $taskId <= 0) {
                    continue;
                }

                if (!isset($updateStatsByImport[$importId])) {
                    $updateStatsByImport[$importId] = [
                        'recent_update_count' => 0,
                        'last_meeting_date' => null,
                        'last_update_at' => null,
                    ];
                }

                $updateStatsByImport[$importId]['recent_update_count']++;

                if (
                    $meetingDate !== ''
                    && (
                        $updateStatsByImport[$importId]['last_meeting_date'] === null
                        || $meetingDate > (string)$updateStatsByImport[$importId]['last_meeting_date']
                    )
                ) {
                    $updateStatsByImport[$importId]['last_meeting_date'] = $meetingDate;
                }
                if (
                    !empty($updateRow['created_at'])
                    && (
                        $updateStatsByImport[$importId]['last_update_at'] === null
                        || (string)$updateRow['created_at'] > (string)$updateStatsByImport[$importId]['last_update_at']
                    )
                ) {
                    $updateStatsByImport[$importId]['last_update_at'] = (string)$updateRow['created_at'];
                }

                if (!isset($latestByImport[$importId][$taskId])) {
                    $latestByImport[$importId][$taskId] = $updateRow;
                }

                if ($meetingDateFilter !== null) {
                    if ($meetingDate !== '' && $meetingDate <= $meetingDateFilter && !isset($snapshotByImport[$importId][$taskId])) {
                        $snapshotByImport[$importId][$taskId] = $updateRow;
                    }

                    if ($meetingDate === $meetingDateFilter) {
                        $meetingDateMatchByImport[$importId] = true;

                        if (!isset($updateStatsByImport[$importId]['filtered_update_count'])) {
                            $updateStatsByImport[$importId]['filtered_update_count'] = 0;
                        }

                        $updateStatsByImport[$importId]['filtered_update_count']++;
                    }
                }
            }
        }

        $today = new DateTimeImmutable('today');
        $summaries = [];

        foreach ($chantiers as $chantierRow) {
            $chantierId = (int)($chantierRow['id'] ?? 0);
            $import = $importsByChantier[$chantierId] ?? null;
            $importId = $import ? (int)($import['id'] ?? 0) : 0;

            $summary = $this->buildPlanningDashboardSummary(
                $chantierRow,
                $import,
                $tasksByImport[$importId] ?? [],
                $meetingDateFilter !== null ? ($snapshotByImport[$importId] ?? []) : ($latestByImport[$importId] ?? []),
                $updateStatsByImport[$importId] ?? [],
                $budgetsByChantier[$chantierId] ?? [],
                $conductorsByChantier[$chantierId] ?? [],
                $meetingDateFilter,
                !empty($meetingDateMatchByImport[$importId]),
                $today
            );

            if ($meetingDateFilter !== null && empty($summary['meeting_filter_match'])) {
                continue;
            }

            if ($conductorIdFilter > 0 && !in_array($conductorIdFilter, $summary['conductor_ids'], true)) {
                continue;
            }

            $summaries[] = $summary;
        }

        return $summaries;
    }

    public function getPlanningDashboardFilterOptions(): array
    {
        $meetingDates = [];
        $conductors = [];

        $meetingStmt = $this->pdo->query(
            'SELECT DISTINCT ptu.meeting_date
             FROM project_planning_task_update ptu
             INNER JOIN project_planning_import ppi ON ppi.id = ptu.import_id
             WHERE ppi.is_active = 1 AND ptu.meeting_date IS NOT NULL
             ORDER BY ptu.meeting_date DESC'
        );
        $meetingRows = $meetingStmt ? ($meetingStmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        foreach ($meetingRows as $meetingDate) {
            $meetingDate = $this->normalizeDashboardMeetingDate($meetingDate);
            if ($meetingDate !== null) {
                $meetingDates[] = $meetingDate;
            }
        }

        try {
            $conductorsStmt = $this->pdo->query(
                'SELECT DISTINCT
                    p.id_personnel_soignant AS id,
                    p.nom_personnel_soignant AS name,
                    p.matricule_personnel_soignant AS matricule,
                    s.lib_service AS service_label
                 FROM suivi_travaux_affectation_pilote ap
                 INNER JOIN suivi_travaux_affectation a ON a.id = ap.affectation_id
                 INNER JOIN personnel p ON p.id_personnel_soignant = ap.personnel_id
                 LEFT JOIN service s ON s.id_service = p.service_id
                 ORDER BY p.nom_personnel_soignant ASC'
            );
            $conductors = $conductorsStmt ? ($conductorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $conductors = [];
        }

        return [
            'meeting_dates' => array_values(array_unique($meetingDates)),
            'conductors' => array_values(array_map(static function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => trim((string)($row['name'] ?? '')),
                    'matricule' => trim((string)($row['matricule'] ?? '')),
                    'service_label' => trim((string)($row['service_label'] ?? '')),
                ];
            }, $conductors)),
        ];
    }

    public function getAssignmentDirectory(): array
    {
        $services = [];
        $personnel = [];

        try {
            $serviceStmt = $this->pdo->query(
                'SELECT s.id_service AS id, s.lib_service AS label FROM service s ORDER BY s.lib_service ASC'
            );
            $services = $serviceStmt ? ($serviceStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $services = [];
        }

        try {
            $personnelStmt = $this->pdo->query(
                'SELECT
                    p.id_personnel_soignant AS id,
                    p.nom_personnel_soignant AS name,
                    p.matricule_personnel_soignant AS matricule,
                    p.service_id AS service_id,
                    s.lib_service AS service_label
                 FROM personnel p
                 LEFT JOIN service s ON s.id_service = p.service_id
                 ORDER BY p.nom_personnel_soignant ASC'
            );
            $personnel = $personnelStmt ? ($personnelStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            $personnel = [];
        }

        return [
            'services' => array_values(array_map(static function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'label' => trim((string)($row['label'] ?? '')),
                ];
            }, $services)),
            'personnel' => array_values(array_map(static function (array $row): array {
                return [
                    'id' => (int)($row['id'] ?? 0),
                    'name' => trim((string)($row['name'] ?? '')),
                    'matricule' => trim((string)($row['matricule'] ?? '')),
                    'service_id' => (int)($row['service_id'] ?? 0),
                    'service_label' => trim((string)($row['service_label'] ?? '')),
                ];
            }, $personnel)),
        ];
    }

    public function getTaskAssignmentIds(int $taskId): array
    {
        if ($taskId <= 0) {
            return [
                'task_id' => 0,
                'service_ids' => [],
                'personnel_ids' => [],
            ];
        }

        $assignmentId = $this->getOrCreateAssignmentId($taskId, null, false);
        if ($assignmentId <= 0) {
            return [
                'task_id' => $taskId,
                'service_ids' => [],
                'personnel_ids' => [],
            ];
        }

        $serviceIds = [];
        $personnelIds = [];

        try {
            $stmt = $this->pdo->prepare('SELECT service_id FROM project_planning_task_assignment_service WHERE assignment_id = :id');
            $stmt->execute(['id' => $assignmentId]);
            $serviceIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable $e) {
            $serviceIds = [];
        }

        try {
            $stmt = $this->pdo->prepare('SELECT personnel_id FROM project_planning_task_assignment_personnel WHERE assignment_id = :id');
            $stmt->execute(['id' => $assignmentId]);
            $personnelIds = array_values(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [])));
        } catch (Throwable $e) {
            $personnelIds = [];
        }

        return [
            'task_id' => $taskId,
            'service_ids' => array_values(array_unique($serviceIds)),
            'personnel_ids' => array_values(array_unique($personnelIds)),
        ];
    }

    public function saveTaskAssignment(int $taskId, array $payload): array
    {
        if ($taskId <= 0) {
            throw new InvalidArgumentException('task_id invalide');
        }

        $updatedBy = trim((string)($payload['updated_by'] ?? 'SYSTEM'));
        if ($updatedBy === '') {
            $updatedBy = 'SYSTEM';
        }

        $serviceIds = $this->normalizeIdArray($payload['service_ids'] ?? []);
        $personnelIds = $this->normalizeIdArray($payload['personnel_ids'] ?? []);

        $task = $this->getTaskById($taskId);
        if ($task === null) {
            throw new RuntimeException('Tache Project introuvable');
        }

        $assignmentId = $this->getOrCreateAssignmentId($taskId, $updatedBy, true);
        if ($assignmentId <= 0) {
            throw new RuntimeException('Impossible de sauvegarder l affectation');
        }

        $this->pdo->beginTransaction();

        try {
            $deleteServices = $this->pdo->prepare('DELETE FROM project_planning_task_assignment_service WHERE assignment_id = :id');
            $deleteServices->execute(['id' => $assignmentId]);

            $deletePersonnel = $this->pdo->prepare('DELETE FROM project_planning_task_assignment_personnel WHERE assignment_id = :id');
            $deletePersonnel->execute(['id' => $assignmentId]);

            if (!empty($serviceIds)) {
                $insertService = $this->pdo->prepare(
                    'INSERT INTO project_planning_task_assignment_service (assignment_id, service_id, created_at) VALUES (:assignment_id, :service_id, NOW())'
                );
                foreach ($serviceIds as $serviceId) {
                    $insertService->execute([
                        'assignment_id' => $assignmentId,
                        'service_id' => $serviceId,
                    ]);
                }
            }

            if (!empty($personnelIds)) {
                $insertPersonnel = $this->pdo->prepare(
                    'INSERT INTO project_planning_task_assignment_personnel (assignment_id, personnel_id, created_at) VALUES (:assignment_id, :personnel_id, NOW())'
                );
                foreach ($personnelIds as $personnelId) {
                    $insertPersonnel->execute([
                        'assignment_id' => $assignmentId,
                        'personnel_id' => $personnelId,
                    ]);
                }
            }

            $touch = $this->pdo->prepare('UPDATE project_planning_task_assignment SET updated_by = :updated_by, updated_at = NOW() WHERE id = :id');
            $touch->execute([
                'updated_by' => $updatedBy,
                'id' => $assignmentId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getTaskAssignmentIds($taskId);
    }

    public function getAssignmentsByImportId(int $importId): array
    {
        if ($importId <= 0) {
            return [];
        }

        $result = [];

        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    pta.id AS assignment_id,
                    pt.id AS task_id,
                    ap.personnel_id,
                    p.nom_personnel_soignant AS personnel_name,
                    p.matricule_personnel_soignant AS personnel_matricule,
                    p.service_id AS personnel_service_id,
                    s.lib_service AS personnel_service_label
                 FROM project_planning_task pt
                 LEFT JOIN project_planning_task_assignment pta ON pta.task_id = pt.id
                 LEFT JOIN project_planning_task_assignment_personnel ap ON ap.assignment_id = pta.id
                 LEFT JOIN personnel p ON p.id_personnel_soignant = ap.personnel_id
                 LEFT JOIN service s ON s.id_service = p.service_id
                 WHERE pt.import_id = :import_id'
            );
            $stmt->execute(['import_id' => $importId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $taskId = (int)($row['task_id'] ?? 0);
                if ($taskId <= 0) {
                    continue;
                }

                if (!isset($result[$taskId])) {
                    $result[$taskId] = [
                        'task_id' => $taskId,
                        'service_ids' => [],
                        'personnel_ids' => [],
                        'services' => [],
                        'personnel' => [],
                    ];
                }

                $personnelId = (int)($row['personnel_id'] ?? 0);
                if ($personnelId > 0 && !isset($result[$taskId]['personnel'][$personnelId])) {
                    $result[$taskId]['personnel'][$personnelId] = [
                        'id' => $personnelId,
                        'name' => trim((string)($row['personnel_name'] ?? '')),
                        'matricule' => trim((string)($row['personnel_matricule'] ?? '')),
                        'service_id' => (int)($row['personnel_service_id'] ?? 0),
                        'service_label' => trim((string)($row['personnel_service_label'] ?? '')),
                    ];
                    $result[$taskId]['personnel_ids'][] = $personnelId;
                }
            }

            foreach ($result as $taskId => $payload) {
                $result[$taskId]['personnel'] = array_values($payload['personnel']);
                $result[$taskId]['personnel_ids'] = array_values(array_unique($payload['personnel_ids']));
            }
        } catch (Throwable $e) {
            return [];
        }

        try {
            $stmt = $this->pdo->prepare(
                'SELECT
                    pt.id AS task_id,
                    aps.service_id,
                    s.lib_service AS service_label
                 FROM project_planning_task pt
                 INNER JOIN project_planning_task_assignment pta ON pta.task_id = pt.id
                 INNER JOIN project_planning_task_assignment_service aps ON aps.assignment_id = pta.id
                 LEFT JOIN service s ON s.id_service = aps.service_id
                 WHERE pt.import_id = :import_id'
            );
            $stmt->execute(['import_id' => $importId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $taskId = (int)($row['task_id'] ?? 0);
                $serviceId = (int)($row['service_id'] ?? 0);
                if ($taskId <= 0 || $serviceId <= 0) {
                    continue;
                }

                if (!isset($result[$taskId])) {
                    $result[$taskId] = [
                        'task_id' => $taskId,
                        'service_ids' => [],
                        'personnel_ids' => [],
                        'services' => [],
                        'personnel' => [],
                    ];
                }

                if (!isset($result[$taskId]['services'][$serviceId])) {
                    $result[$taskId]['services'][$serviceId] = [
                        'id' => $serviceId,
                        'label' => trim((string)($row['service_label'] ?? '')),
                    ];
                    $result[$taskId]['service_ids'][] = $serviceId;
                }
            }

            foreach ($result as $taskId => $payload) {
                $result[$taskId]['services'] = array_values($payload['services']);
                $result[$taskId]['service_ids'] = array_values(array_unique($payload['service_ids']));
            }
        } catch (Throwable $e) {
            // noop
        }

        return $result;
    }

    private function getOrCreateAssignmentId(int $taskId, ?string $updatedBy, bool $create): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM project_planning_task_assignment WHERE task_id = :task_id LIMIT 1');
        $stmt->execute(['task_id' => $taskId]);
        $existingId = (int)($stmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            if ($updatedBy !== null) {
                $touch = $this->pdo->prepare('UPDATE project_planning_task_assignment SET updated_by = :updated_by, updated_at = NOW() WHERE id = :id');
                $touch->execute(['updated_by' => $updatedBy, 'id' => $existingId]);
            }
            return $existingId;
        }

        if (!$create) {
            return 0;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO project_planning_task_assignment (task_id, updated_by, created_at, updated_at) VALUES (:task_id, :updated_by, NOW(), NOW())'
        );
        $insert->execute([
            'task_id' => $taskId,
            'updated_by' => $updatedBy,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    private function normalizeIdArray($value): array
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $raw) {
            $id = (int)$raw;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeTaskDateFields(array $row): array
    {
        foreach (['start_date', 'end_date'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = $this->normalizeStoredDateValue($row[$field] ?? null);
            }
        }

        return $row;
    }

    private function normalizeStoredDateValue($value): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $text) ?: null;
        if (!$date instanceof DateTimeImmutable) {
            try {
                $date = new DateTimeImmutable($text);
            } catch (Throwable $e) {
                return $text;
            }
        }

        $year = (int)$date->format('Y');
        if ($year >= 0 && $year < 1000) {
            $correctedDate = $date->setDate(
                2000 + $year,
                (int)$date->format('m'),
                (int)$date->format('d')
            );
            if ($correctedDate instanceof DateTimeImmutable) {
                $date = $correctedDate;
            }
        } elseif ($year >= 1900 && $year < 2000) {
            $shiftedDate = $date->modify('+100 years');
            if ($shiftedDate instanceof DateTimeImmutable) {
                $date = $shiftedDate;
            }
        }

        return $date->format('Y-m-d');
    }

    private function getTaskById(int $taskId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM project_planning_task WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        return $task ?: null;
    }

    private function getLatestRealtimeUpdatesByImport(int $importId): array
    {
        $sql = '
            SELECT
                ptu.id,
                ptu.import_id,
                ptu.task_id,
                ptu.meeting_date,
                ptu.progress_percent,
                ptu.status_code,
                ptu.comment_text,
                ptu.updated_by,
                ptu.created_at,
                ptask.task_name,
                ptask.task_uid
            FROM project_planning_task_update ptu
            INNER JOIN (
                SELECT task_id, MAX(id) AS last_id
                FROM project_planning_task_update
                WHERE import_id = :import_id
                GROUP BY task_id
            ) latest ON latest.last_id = ptu.id
            INNER JOIN project_planning_task ptask ON ptask.id = ptu.task_id
            WHERE ptu.import_id = :import_id
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['import_id' => $importId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int)$row['task_id']] = $row;
        }

        return $mapped;
    }

    private function getPlanningDashboardBudgetMap(): array
    {
        $whereActive = $this->hasArchiveColumns ? 'WHERE c.archived_at IS NULL' : '';

        $stmt = $this->pdo->query(
            "SELECT
                c.id AS chantier_id,
                c.montant_devis AS budget_chantier,
                d.total_ttc AS budget_devis_total,
                d.numero_devis,
                COALESCE(db.total_debourse, 0) AS budget_debourse,
                COALESCE(SUM(CASE WHEN f.decaisse = 1 THEN f.montant_fiche ELSE 0 END), 0) AS total_depenses
             FROM chantier_banamur c
             LEFT JOIN devis_banamur d ON d.id = c.devis_id
             LEFT JOIN (
                SELECT devis_id, SUM(montant_debourse) AS total_debourse
                FROM debourse_banamur
                GROUP BY devis_id
             ) db ON db.devis_id = c.devis_id
             LEFT JOIN fiche_decaissement f ON f.chantier_id = c.id
             $whereActive
             GROUP BY c.id, c.montant_devis, d.total_ttc, d.numero_devis, db.total_debourse"
        );

        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $mapped = [];

        foreach ($rows as $row) {
            $chantierId = (int)($row['chantier_id'] ?? 0);
            if ($chantierId <= 0) {
                continue;
            }

            $budgetDebourse = (float)($row['budget_debourse'] ?? 0);
            $budgetChantier = (float)($row['budget_chantier'] ?? 0);
            $budgetDevis = (float)($row['budget_devis_total'] ?? 0);
            $budgetReference = $budgetDebourse > 0 ? $budgetDebourse : ($budgetChantier > 0 ? $budgetChantier : $budgetDevis);
            $totalDepenses = (float)($row['total_depenses'] ?? 0);

            $mapped[$chantierId] = [
                'budget_reference' => $budgetReference,
                'budget_debourse' => $budgetDebourse,
                'budget_chantier' => $budgetChantier,
                'budget_devis_total' => $budgetDevis,
                'numero_devis' => trim((string)($row['numero_devis'] ?? '')),
                'total_depenses' => $totalDepenses,
                'budget_remaining' => $budgetReference - $totalDepenses,
                'budget_consumption_percent' => $budgetReference > 0 ? round(($totalDepenses / $budgetReference) * 100, 1) : 0.0,
            ];
        }

        return $mapped;
    }

    private function getPlanningDashboardConductorsMap(): array
    {
        try {
            $stmt = $this->pdo->query(
                'SELECT
                    a.chantier_id,
                    p.id_personnel_soignant AS id,
                    p.nom_personnel_soignant AS name,
                    p.matricule_personnel_soignant AS matricule,
                    s.lib_service AS service_label
                 FROM suivi_travaux_affectation a
                 INNER JOIN suivi_travaux_affectation_pilote ap ON ap.affectation_id = a.id
                 INNER JOIN personnel p ON p.id_personnel_soignant = ap.personnel_id
                 LEFT JOIN service s ON s.id_service = p.service_id
                 ORDER BY a.chantier_id ASC, p.nom_personnel_soignant ASC'
            );
        } catch (Throwable $e) {
            return [];
        }

        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $mapped = [];

        foreach ($rows as $row) {
            $chantierId = (int)($row['chantier_id'] ?? 0);
            $conductorId = (int)($row['id'] ?? 0);
            if ($chantierId <= 0 || $conductorId <= 0) {
                continue;
            }

            if (!isset($mapped[$chantierId])) {
                $mapped[$chantierId] = [];
            }

            $mapped[$chantierId][$conductorId] = [
                'id' => $conductorId,
                'name' => trim((string)($row['name'] ?? '')),
                'matricule' => trim((string)($row['matricule'] ?? '')),
                'service_label' => trim((string)($row['service_label'] ?? '')),
            ];
        }

        foreach ($mapped as $chantierId => $conductors) {
            $mapped[$chantierId] = array_values($conductors);
        }

        return $mapped;
    }

    private function normalizeDashboardMeetingDate($value): ?string
    {
        $meetingDate = trim((string)$value);
        if ($meetingDate === '') {
            return null;
        }

        $dateObject = DateTimeImmutable::createFromFormat('Y-m-d', $meetingDate);
        if (!$dateObject instanceof DateTimeImmutable) {
            return null;
        }

        return $dateObject->format('Y-m-d');
    }

    private function buildPlanningDashboardSummary(
        array $chantier,
        ?array $import,
        array $tasks,
        array $latestByTask,
        array $updateStats,
        array $budgetData,
        array $conductors,
        ?string $meetingDateFilter,
        bool $meetingDateMatch,
        DateTimeImmutable $today
    ): array {
        $planningStart = null;
        $planningEnd = null;
        $taskCount = count($tasks);
        $summaryCount = 0;
        $leafCount = 0;
        $weightedProgress = 0.0;
        $weightedDuration = 0;
        $actualWeightedProgress = 0.0;
        $actualWeightedDuration = 0;
        $trackedLeafCount = 0;
        $blockedCount = 0;
        $delayedCount = 0;

        foreach ($tasks as $taskRow) {
            $startRaw = trim((string)($taskRow['start_date'] ?? ''));
            $endRaw = trim((string)($taskRow['end_date'] ?? ''));
            $startDate = $startRaw !== '' ? (DateTimeImmutable::createFromFormat('Y-m-d', $startRaw) ?: null) : null;
            $endDate = $endRaw !== '' ? (DateTimeImmutable::createFromFormat('Y-m-d', $endRaw) ?: null) : null;

            if ($startDate && $endDate && $endDate < $startDate) {
                $endDate = $startDate;
            }

            if ($startDate && ($planningStart === null || $startDate < $planningStart)) {
                $planningStart = $startDate;
            }
            if ($endDate && ($planningEnd === null || $endDate > $planningEnd)) {
                $planningEnd = $endDate;
            }

            $plannedStatus = 'non_planifie';
            $plannedProgress = 0.0;
            $durationDays = 0;

            if ($startDate && $endDate) {
                $durationDays = max(1, (int)$startDate->diff($endDate)->days + 1);
                if ($today < $startDate) {
                    $plannedStatus = 'a_venir';
                    $plannedProgress = 0.0;
                } elseif ($today > $endDate) {
                    $plannedStatus = 'termine_theorique';
                    $plannedProgress = 100.0;
                } else {
                    $plannedStatus = 'en_cours';
                    $elapsedDays = max(1, (int)$startDate->diff($today)->days + 1);
                    $plannedProgress = min(100.0, round(($elapsedDays / $durationDays) * 100, 1));
                }
            }

            $isSummary = !empty($taskRow['is_summary']);
            $taskId = (int)($taskRow['id'] ?? 0);
            $latestRealtime = $latestByTask[$taskId] ?? null;
            $actualProgress = $latestRealtime !== null ? max(0.0, min(100.0, (float)($latestRealtime['progress_percent'] ?? 0))) : null;
            $actualStatusKey = $latestRealtime !== null ? trim((string)($latestRealtime['status_code'] ?? '')) : '';

            if ($isSummary) {
                $summaryCount++;
                continue;
            }

            $leafCount++;

            if ($durationDays > 0) {
                $weightedProgress += $plannedProgress * $durationDays;
                $weightedDuration += $durationDays;

                if ($actualProgress !== null) {
                    $actualWeightedProgress += $actualProgress * $durationDays;
                    $actualWeightedDuration += $durationDays;
                }
            }

            if ($actualProgress !== null) {
                $trackedLeafCount++;
                if ($actualStatusKey === 'bloque') {
                    $blockedCount++;
                }
                if ($plannedStatus !== 'a_venir' && $actualProgress + 5 < $plannedProgress) {
                    $delayedCount++;
                }
            }
        }

        $plannedGlobalProgress = $weightedDuration > 0 ? round($weightedProgress / $weightedDuration, 1) : 0.0;
        $actualGlobalProgress = $actualWeightedDuration > 0 ? round($actualWeightedProgress / $actualWeightedDuration, 1) : 0.0;
        $coveragePercent = $leafCount > 0 ? round(($trackedLeafCount / $leafCount) * 100, 1) : 0.0;
        $lastMeetingDate = trim((string)($updateStats['last_meeting_date'] ?? ''));
        $lastUpdateAt = trim((string)($updateStats['last_update_at'] ?? ''));
        $filteredUpdateCount = (int)($updateStats['filtered_update_count'] ?? 0);
        $budgetReference = (float)($budgetData['budget_reference'] ?? 0);
        $budgetDepense = (float)($budgetData['total_depenses'] ?? 0);
        $budgetRemaining = (float)($budgetData['budget_remaining'] ?? 0);
        $budgetConsumptionPercent = (float)($budgetData['budget_consumption_percent'] ?? 0);
        $conductorIds = array_values(array_map(static function (array $conductor): int {
            return (int)($conductor['id'] ?? 0);
        }, $conductors));
        $conductorNames = array_values(array_filter(array_map(static function (array $conductor): string {
            return trim((string)($conductor['name'] ?? ''));
        }, $conductors)));

        $healthStatus = 'sans_planning';
        $healthLabel = 'Sans planning';

        if ($import !== null) {
            if ($blockedCount > 0) {
                $healthStatus = 'bloque';
                $healthLabel = 'Bloque';
            } elseif ($delayedCount > 0 || ($trackedLeafCount > 0 && $actualGlobalProgress + 5 < $plannedGlobalProgress)) {
                $healthStatus = 'retard';
                $healthLabel = 'En retard';
            } elseif ($trackedLeafCount === 0) {
                $healthStatus = 'a_demarrer';
                $healthLabel = 'A demarrer';
            } elseif ($actualGlobalProgress >= 99.9 || ($plannedGlobalProgress >= 99.9 && $actualGlobalProgress >= 95)) {
                $healthStatus = 'termine';
                $healthLabel = 'Presque termine';
            } else {
                $healthStatus = 'maitrise';
                $healthLabel = 'Sous controle';
            }
        }

        return [
            'chantier_id' => (int)($chantier['id'] ?? 0),
            'code' => (string)($chantier['code'] ?? ''),
            'titre' => (string)($chantier['titre'] ?? ''),
            'libelle' => (string)($chantier['libelle'] ?? ''),
            'has_import' => $import !== null,
            'import_id' => $import !== null ? (int)($import['id'] ?? 0) : 0,
            'source_label' => $import !== null ? (string)($import['source_label'] ?? '') : '',
            'source_type' => $import !== null ? (string)($import['source_type'] ?? '') : '',
            'imported_by' => $import !== null ? (string)($import['imported_by'] ?? '') : '',
            'imported_at' => $import !== null ? (string)($import['created_at'] ?? '') : '',
            'task_count' => $taskCount,
            'summary_count' => $summaryCount,
            'leaf_count' => $leafCount,
            'tracked_leaf_count' => $trackedLeafCount,
            'planned_progress' => $plannedGlobalProgress,
            'actual_progress' => $actualGlobalProgress,
            'progress_budget_gap' => round($actualGlobalProgress - $budgetConsumptionPercent, 1),
            'coverage_percent' => $coveragePercent,
            'blocked_count' => $blockedCount,
            'delayed_count' => $delayedCount,
            'recent_update_count' => $meetingDateFilter !== null ? $filteredUpdateCount : (int)($updateStats['recent_update_count'] ?? 0),
            'last_meeting_date' => $lastMeetingDate !== '' ? $lastMeetingDate : null,
            'last_update_at' => $lastUpdateAt !== '' ? $lastUpdateAt : null,
            'planning_start' => $planningStart ? $planningStart->format('Y-m-d') : null,
            'planning_end' => $planningEnd ? $planningEnd->format('Y-m-d') : null,
            'meeting_filter_match' => $meetingDateFilter !== null ? $meetingDateMatch : true,
            'selected_meeting_date' => $meetingDateFilter,
            'budget_reference' => $budgetReference,
            'budget_debourse' => (float)($budgetData['budget_debourse'] ?? 0),
            'budget_chantier' => (float)($budgetData['budget_chantier'] ?? 0),
            'budget_devis_total' => (float)($budgetData['budget_devis_total'] ?? 0),
            'numero_devis' => (string)($budgetData['numero_devis'] ?? ''),
            'total_depenses' => $budgetDepense,
            'budget_remaining' => $budgetRemaining,
            'budget_consumption_percent' => $budgetConsumptionPercent,
            'conductors' => $conductors,
            'conductor_ids' => $conductorIds,
            'conductor_names' => $conductorNames,
            'conductor_label' => !empty($conductorNames) ? implode(', ', $conductorNames) : 'Non affecte',
            'health_status' => $healthStatus,
            'health_label' => $healthLabel,
        ];
    }

    private function getRecentRealtimeUpdatesByImport(int $importId, int $recentLimit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                ptu.id,
                ptu.import_id,
                ptu.task_id,
                ptu.meeting_date,
                ptu.progress_percent,
                ptu.status_code,
                ptu.comment_text,
                ptu.updated_by,
                ptu.created_at,
                ptask.task_name,
                ptask.task_uid
             FROM project_planning_task_update ptu
             INNER JOIN project_planning_task ptask ON ptask.id = ptu.task_id
             WHERE ptu.import_id = :import_id
             ORDER BY ptu.id DESC
             LIMIT ' . max(1, min(100, $recentLimit))
        );
        $stmt->execute(['import_id' => $importId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function ensureTablesExist(): void
    {
        $importSql = "
            CREATE TABLE IF NOT EXISTS project_planning_import (
                id INT NOT NULL AUTO_INCREMENT,
                chantier_id INT NOT NULL,
                source_label VARCHAR(255) NOT NULL,
                source_type VARCHAR(50) NOT NULL DEFAULT 'project',
                task_count INT NOT NULL DEFAULT 0,
                imported_by VARCHAR(255) NULL,
                raw_payload LONGTEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_project_planning_import_chantier (chantier_id),
                KEY idx_project_planning_import_active (chantier_id, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $taskSql = "
            CREATE TABLE IF NOT EXISTS project_planning_task (
                id INT NOT NULL AUTO_INCREMENT,
                import_id INT NOT NULL,
                sort_index INT NOT NULL DEFAULT 0,
                task_uid VARCHAR(80) NOT NULL,
                parent_uid VARCHAR(80) NULL,
                outline_level INT NOT NULL DEFAULT 1,
                task_name VARCHAR(255) NOT NULL,
                start_date DATE NULL,
                end_date DATE NULL,
                duration_label VARCHAR(80) NULL,
                predecessors_label VARCHAR(255) NULL,
                is_summary TINYINT(1) NOT NULL DEFAULT 0,
                group_label VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_project_planning_task_import (import_id),
                KEY idx_project_planning_task_order (import_id, sort_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $trackingSql = "
            CREATE TABLE IF NOT EXISTS project_planning_task_update (
                id INT NOT NULL AUTO_INCREMENT,
                import_id INT NOT NULL,
                task_id INT NOT NULL,
                meeting_date DATE NULL,
                progress_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
                status_code VARCHAR(30) NOT NULL DEFAULT 'en_cours',
                comment_text TEXT NULL,
                updated_by VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_project_planning_task_update_import (import_id),
                KEY idx_project_planning_task_update_task (task_id),
                KEY idx_project_planning_task_update_latest (import_id, task_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $assignmentSql = "
            CREATE TABLE IF NOT EXISTS project_planning_task_assignment (
                id INT NOT NULL AUTO_INCREMENT,
                task_id INT NOT NULL,
                updated_by VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_project_task_assignment (task_id),
                KEY idx_project_task_assignment_task (task_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $assignmentPersonnelSql = "
            CREATE TABLE IF NOT EXISTS project_planning_task_assignment_personnel (
                id INT NOT NULL AUTO_INCREMENT,
                assignment_id INT NOT NULL,
                personnel_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_project_task_assignment_personnel (assignment_id, personnel_id),
                KEY idx_project_task_assignment_personnel_assignment (assignment_id),
                KEY idx_project_task_assignment_personnel_personnel (personnel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $assignmentServiceSql = "
            CREATE TABLE IF NOT EXISTS project_planning_task_assignment_service (
                id INT NOT NULL AUTO_INCREMENT,
                assignment_id INT NOT NULL,
                service_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_project_task_assignment_service (assignment_id, service_id),
                KEY idx_project_task_assignment_service_assignment (assignment_id),
                KEY idx_project_task_assignment_service_service (service_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $this->pdo->exec($importSql);
        $this->pdo->exec($taskSql);
        $this->pdo->exec($trackingSql);
        $this->pdo->exec($assignmentSql);
        $this->pdo->exec($assignmentPersonnelSql);
        $this->pdo->exec($assignmentServiceSql);
        $this->ensureColumnExists('project_planning_task_update', 'meeting_date', 'DATE NULL AFTER task_id');
    }

    private function ensureColumnExists(string $tableName, string $columnName, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        $exists = (int)$stmt->fetchColumn() > 0;
        if ($exists) {
            return;
        }

        $this->pdo->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $tableName, $columnName, $definition));
    }
}
