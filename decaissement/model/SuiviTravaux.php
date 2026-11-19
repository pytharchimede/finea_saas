<?php

class SuiviTravaux
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTableExists();
        $this->ensureAssignmentTablesExist();
    }

    public static function statusOptions(): array
    {
        return [
            'pas_commencee' => [
                'label' => 'Pas commencée',
                'color' => '#64748b',
                'bg' => 'rgba(100,116,139,0.12)',
                'badge' => 'bg-slate-100 text-slate-700',
            ],
            'en_cours' => [
                'label' => 'En cours',
                'color' => '#2563eb',
                'bg' => 'rgba(37,99,235,0.12)',
                'badge' => 'bg-blue-100 text-blue-800',
            ],
            'terminee' => [
                'label' => 'Terminée',
                'color' => '#059669',
                'bg' => 'rgba(5,150,105,0.12)',
                'badge' => 'bg-emerald-100 text-emerald-800',
            ],
            'attente_decaissement' => [
                'label' => 'Attente décaissement',
                'color' => '#f59e0b',
                'bg' => 'rgba(245,158,11,0.14)',
                'badge' => 'bg-amber-100 text-amber-800',
            ],
            'attente_dependance' => [
                'label' => 'Attente autre tâche',
                'color' => '#8b5cf6',
                'bg' => 'rgba(139,92,246,0.14)',
                'badge' => 'bg-violet-100 text-violet-800',
            ],
            'bloquee' => [
                'label' => 'Bloquée',
                'color' => '#dc2626',
                'bg' => 'rgba(220,38,38,0.14)',
                'badge' => 'bg-red-100 text-red-800',
            ],
            'suspendue' => [
                'label' => 'Suspendue',
                'color' => '#0f172a',
                'bg' => 'rgba(15,23,42,0.12)',
                'badge' => 'bg-slate-900 text-white',
            ],
        ];
    }

    public function getLatestUpdatesByChantier(int $chantierId): array
    {
        $sql = "
            SELECT s.*
            FROM suivi_travaux_journalier s
            INNER JOIN (
                SELECT ligne_debourse_id, MAX(date_suivi) AS max_date
                FROM suivi_travaux_journalier
                WHERE chantier_id = :chantier_id
                GROUP BY ligne_debourse_id
            ) latest
                ON latest.ligne_debourse_id = s.ligne_debourse_id
               AND latest.max_date = s.date_suivi
            WHERE s.chantier_id = :chantier_id
            ORDER BY s.date_suivi DESC, s.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['chantier_id' => $chantierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $mapped = [];
        foreach ($rows as $row) {
            $lineId = (int)($row['ligne_debourse_id'] ?? 0);
            if ($lineId <= 0) {
                continue;
            }
            if (!isset($mapped[$lineId])) {
                $mapped[$lineId] = $row;
            }
        }

        return $mapped;
    }

    public function getHistoryByChantier(int $chantierId, int $limit = 150): array
    {
        $limit = max(1, min($limit, 500));
        $sql = "
            SELECT *
            FROM suivi_travaux_journalier
            WHERE chantier_id = :chantier_id
            ORDER BY date_suivi DESC, updated_at DESC, id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['chantier_id' => $chantierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getHistoryByDate(string $dateSuivi, int $limit = 2000): array
    {
        if (!$this->isValidDate($dateSuivi)) {
            return [];
        }

        $limit = max(1, min($limit, 5000));
        $sql = "
            SELECT *
            FROM suivi_travaux_journalier
            WHERE date_suivi = :date_suivi
            ORDER BY chantier_id ASC, updated_at DESC, id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['date_suivi' => $dateSuivi]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getHistoryByTask(int $chantierId, int $ligneDebourseId, int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $sql = "
            SELECT *
            FROM suivi_travaux_journalier
            WHERE chantier_id = :chantier_id AND ligne_debourse_id = :ligne_debourse_id
            ORDER BY date_suivi DESC, updated_at DESC, id DESC
            LIMIT {$limit}
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getUpdateForTaskDate(int $chantierId, int $ligneDebourseId, string $dateSuivi): ?array
    {
        if (!$this->isValidDate($dateSuivi)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM suivi_travaux_journalier WHERE chantier_id = :chantier_id AND ligne_debourse_id = :ligne_debourse_id AND date_suivi = :date_suivi LIMIT 1');
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'date_suivi' => $dateSuivi,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getLatestUpdateForTask(int $chantierId, int $ligneDebourseId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suivi_travaux_journalier WHERE chantier_id = :chantier_id AND ligne_debourse_id = :ligne_debourse_id ORDER BY date_suivi DESC, updated_at DESC, id DESC LIMIT 1');
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAssignmentsByChantier(int $chantierId): array
    {
        $assignments = $this->fetchAssignments('a.chantier_id = :chantier_id', ['chantier_id' => $chantierId]);
        $mapped = [];
        foreach ($assignments as $assignment) {
            $lineId = (int)($assignment['ligne_debourse_id'] ?? 0);
            if ($lineId <= 0) {
                continue;
            }
            $mapped[$lineId] = $assignment;
        }
        return $mapped;
    }

    public function getAssignmentByTask(int $chantierId, int $ligneDebourseId): ?array
    {
        $assignments = $this->fetchAssignments(
            'a.chantier_id = :chantier_id AND a.ligne_debourse_id = :ligne_debourse_id',
            [
                'chantier_id' => $chantierId,
                'ligne_debourse_id' => $ligneDebourseId,
            ]
        );

        return $assignments[0] ?? null;
    }

    public function saveDailyUpdate(array $data): array
    {
        $chantierId = (int)($data['chantier_id'] ?? 0);
        $ligneDebourseId = (int)($data['ligne_debourse_id'] ?? 0);
        $dateSuivi = trim((string)($data['date_suivi'] ?? ''));
        $statutReel = trim((string)($data['statut_reel'] ?? ''));
        $progression = round(max(0, min(100, (float)($data['progression_reelle'] ?? 0))), 1);
        $commentaire = trim((string)($data['commentaire'] ?? ''));
        $detailSupplementaire = trim((string)($data['detail_ligne_debourse'] ?? ''));
        $blocage = trim((string)($data['blocage_detail'] ?? ''));
        $prochaineAction = trim((string)($data['prochaine_action'] ?? ''));
        $updatedBy = trim((string)($data['updated_by'] ?? 'SYSTEM'));

        if ($chantierId <= 0) {
            throw new InvalidArgumentException('chantier_id invalide');
        }
        if ($ligneDebourseId <= 0) {
            throw new InvalidArgumentException('ligne_debourse_id invalide');
        }
        if (!$this->isValidDate($dateSuivi)) {
            throw new InvalidArgumentException('date_suivi invalide');
        }
        if (!isset(self::statusOptions()[$statutReel])) {
            throw new InvalidArgumentException('statut_reel invalide');
        }

        $sql = "
            INSERT INTO suivi_travaux_journalier (
                chantier_id,
                ligne_debourse_id,
                date_suivi,
                progression_reelle,
                statut_reel,
                commentaire,
                detail_ligne_debourse,
                blocage_detail,
                prochaine_action,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :chantier_id,
                :ligne_debourse_id,
                :date_suivi,
                :progression_reelle,
                :statut_reel,
                :commentaire,
                :detail_ligne_debourse,
                :blocage_detail,
                :prochaine_action,
                :updated_by,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                progression_reelle = VALUES(progression_reelle),
                statut_reel = VALUES(statut_reel),
                commentaire = VALUES(commentaire),
                detail_ligne_debourse = VALUES(detail_ligne_debourse),
                blocage_detail = VALUES(blocage_detail),
                prochaine_action = VALUES(prochaine_action),
                updated_by = VALUES(updated_by),
                updated_at = NOW()
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'date_suivi' => $dateSuivi,
            'progression_reelle' => $progression,
            'statut_reel' => $statutReel,
            'commentaire' => $commentaire,
            'detail_ligne_debourse' => $detailSupplementaire,
            'blocage_detail' => $blocage,
            'prochaine_action' => $prochaineAction,
            'updated_by' => $updatedBy,
        ]);

        return $this->getUpdate($chantierId, $ligneDebourseId, $dateSuivi);
    }

    public function saveTaskAssignment(array $data): array
    {
        $chantierId = (int)($data['chantier_id'] ?? 0);
        $ligneDebourseId = (int)($data['ligne_debourse_id'] ?? 0);
        $serviceId = isset($data['service_id']) && $data['service_id'] !== '' ? (int)$data['service_id'] : null;
        $pilotIds = $this->normalizePilotIds((array)($data['pilot_ids'] ?? []));
        $updatedBy = trim((string)($data['updated_by'] ?? 'SYSTEM'));

        if ($chantierId <= 0) {
            throw new InvalidArgumentException('chantier_id invalide pour l\'affectation');
        }
        if ($ligneDebourseId <= 0) {
            throw new InvalidArgumentException('ligne_debourse_id invalide pour l\'affectation');
        }
        if ($serviceId !== null) {
            $this->validateServiceExists($serviceId);
        }
        $this->validatePilotIds($pilotIds);

        $this->pdo->beginTransaction();

        try {
            $sql = "
                INSERT INTO suivi_travaux_affectation (
                    chantier_id,
                    ligne_debourse_id,
                    service_id,
                    updated_by,
                    created_at,
                    updated_at
                ) VALUES (
                    :chantier_id,
                    :ligne_debourse_id,
                    :service_id,
                    :updated_by,
                    NOW(),
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    service_id = VALUES(service_id),
                    updated_by = VALUES(updated_by),
                    updated_at = NOW()
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':chantier_id', $chantierId, PDO::PARAM_INT);
            $stmt->bindValue(':ligne_debourse_id', $ligneDebourseId, PDO::PARAM_INT);
            if ($serviceId === null) {
                $stmt->bindValue(':service_id', null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue(':service_id', $serviceId, PDO::PARAM_INT);
            }
            $stmt->bindValue(':updated_by', $updatedBy, PDO::PARAM_STR);
            $stmt->execute();

            $affectationId = $this->getAffectationId($chantierId, $ligneDebourseId);
            if ($affectationId <= 0) {
                throw new RuntimeException('Impossible de retrouver l\'affectation de la tâche');
            }

            $deleteStmt = $this->pdo->prepare('DELETE FROM suivi_travaux_affectation_pilote WHERE affectation_id = :affectation_id');
            $deleteStmt->execute(['affectation_id' => $affectationId]);

            if (!empty($pilotIds)) {
                $insertPilot = $this->pdo->prepare('INSERT INTO suivi_travaux_affectation_pilote (affectation_id, personnel_id, created_at) VALUES (:affectation_id, :personnel_id, NOW())');
                foreach ($pilotIds as $pilotId) {
                    $insertPilot->execute([
                        'affectation_id' => $affectationId,
                        'personnel_id' => $pilotId,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->getAssignmentByTask($chantierId, $ligneDebourseId) ?? [
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'service_id' => $serviceId,
            'service_label' => '',
            'pilots' => [],
            'updated_by' => $updatedBy,
        ];
    }

    public function getUpdate(int $chantierId, int $ligneDebourseId, string $dateSuivi): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM suivi_travaux_journalier WHERE chantier_id = :chantier_id AND ligne_debourse_id = :ligne_debourse_id AND date_suivi = :date_suivi LIMIT 1');
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
            'date_suivi' => $dateSuivi,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Point de suivi introuvable après sauvegarde');
        }
        return $row;
    }

    private function fetchAssignments(string $whereClause, array $params): array
    {
        $sql = "
            SELECT
                a.id,
                a.chantier_id,
                a.ligne_debourse_id,
                a.service_id,
                a.updated_by,
                a.created_at,
                a.updated_at,
                s.lib_service AS service_label
            FROM suivi_travaux_affectation a
            LEFT JOIN service s ON s.id_service = a.service_id
            WHERE {$whereClause}
            ORDER BY a.updated_at DESC, a.id DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (empty($rows)) {
            return [];
        }

        $affectationIds = array_values(array_filter(array_map(static function ($row) {
            return (int)($row['id'] ?? 0);
        }, $rows)));

        $pilotsByAffectation = [];
        if (!empty($affectationIds)) {
            $placeholders = implode(',', array_fill(0, count($affectationIds), '?'));
            $pilotSql = "
                SELECT
                    ap.affectation_id,
                    p.id_personnel_soignant AS id,
                    p.matricule_personnel_soignant AS matricule,
                    p.nom_personnel_soignant AS name,
                    p.email_personnel_soignant AS email,
                    p.service_id,
                    s.lib_service AS service_label
                FROM suivi_travaux_affectation_pilote ap
                INNER JOIN personnel p ON p.id_personnel_soignant = ap.personnel_id
                LEFT JOIN service s ON s.id_service = p.service_id
                WHERE ap.affectation_id IN ({$placeholders})
                ORDER BY p.nom_personnel_soignant ASC
            ";
            $pilotStmt = $this->pdo->prepare($pilotSql);
            $pilotStmt->execute($affectationIds);
            $pilotRows = $pilotStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($pilotRows as $pilotRow) {
                $affectationId = (int)($pilotRow['affectation_id'] ?? 0);
                if ($affectationId <= 0) {
                    continue;
                }
                if (!isset($pilotsByAffectation[$affectationId])) {
                    $pilotsByAffectation[$affectationId] = [];
                }
                $pilotsByAffectation[$affectationId][] = [
                    'id' => (int)($pilotRow['id'] ?? 0),
                    'matricule' => (string)($pilotRow['matricule'] ?? ''),
                    'name' => (string)($pilotRow['name'] ?? ''),
                    'email' => (string)($pilotRow['email'] ?? ''),
                    'service_id' => (int)($pilotRow['service_id'] ?? 0),
                    'service_label' => (string)($pilotRow['service_label'] ?? ''),
                ];
            }
        }

        foreach ($rows as &$row) {
            $affectationId = (int)($row['id'] ?? 0);
            $row['service_id'] = isset($row['service_id']) && $row['service_id'] !== null ? (int)$row['service_id'] : null;
            $row['service_label'] = (string)($row['service_label'] ?? '');
            $row['pilots'] = $pilotsByAffectation[$affectationId] ?? [];
            $row['pilot_ids'] = array_values(array_map(static function ($pilot) {
                return (int)($pilot['id'] ?? 0);
            }, $row['pilots']));
        }
        unset($row);

        return $rows;
    }

    private function getAffectationId(int $chantierId, int $ligneDebourseId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM suivi_travaux_affectation WHERE chantier_id = :chantier_id AND ligne_debourse_id = :ligne_debourse_id LIMIT 1');
        $stmt->execute([
            'chantier_id' => $chantierId,
            'ligne_debourse_id' => $ligneDebourseId,
        ]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function normalizePilotIds(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pilotId = (int)$value;
            if ($pilotId > 0) {
                $normalized[$pilotId] = $pilotId;
            }
        }
        return array_values($normalized);
    }

    private function validateServiceExists(int $serviceId): void
    {
        $stmt = $this->pdo->prepare('SELECT id_service FROM service WHERE id_service = :id LIMIT 1');
        $stmt->execute(['id' => $serviceId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('service_id invalide');
        }
    }

    private function validatePilotIds(array $pilotIds): void
    {
        if (empty($pilotIds)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($pilotIds), '?'));
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM personnel WHERE id_personnel_soignant IN ({$placeholders})");
        $stmt->execute($pilotIds);
        $count = (int)$stmt->fetchColumn();

        if ($count !== count($pilotIds)) {
            throw new InvalidArgumentException('Un ou plusieurs pilotes sont invalides');
        }
    }

    private function ensureTableExists(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS suivi_travaux_journalier (
                id INT NOT NULL AUTO_INCREMENT,
                chantier_id INT NOT NULL,
                ligne_debourse_id INT NOT NULL,
                date_suivi DATE NOT NULL,
                progression_reelle DECIMAL(5,2) NOT NULL DEFAULT 0,
                statut_reel VARCHAR(50) NOT NULL,
                commentaire TEXT NULL,
                detail_ligne_debourse TEXT NULL,
                blocage_detail TEXT NULL,
                prochaine_action TEXT NULL,
                updated_by VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_task_day (chantier_id, ligne_debourse_id, date_suivi),
                KEY idx_chantier_date (chantier_id, date_suivi),
                KEY idx_ligne_date (ligne_debourse_id, date_suivi)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $this->pdo->exec($sql);
        $this->ensureColumnExists('suivi_travaux_journalier', 'detail_ligne_debourse', 'ALTER TABLE suivi_travaux_journalier ADD COLUMN detail_ligne_debourse TEXT NULL AFTER commentaire');
    }

    private function ensureAssignmentTablesExist(): void
    {
        $mainTableSql = "
            CREATE TABLE IF NOT EXISTS suivi_travaux_affectation (
                id INT NOT NULL AUTO_INCREMENT,
                chantier_id INT NOT NULL,
                ligne_debourse_id INT NOT NULL,
                service_id INT NULL,
                updated_by VARCHAR(255) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_task_assignment (chantier_id, ligne_debourse_id),
                KEY idx_assignment_service (service_id),
                KEY idx_assignment_task (ligne_debourse_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $pilotTableSql = "
            CREATE TABLE IF NOT EXISTS suivi_travaux_affectation_pilote (
                id INT NOT NULL AUTO_INCREMENT,
                affectation_id INT NOT NULL,
                personnel_id INT NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_affectation_personnel (affectation_id, personnel_id),
                KEY idx_affectation_id (affectation_id),
                KEY idx_personnel_id (personnel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ";

        $this->pdo->exec($mainTableSql);
        $this->pdo->exec($pilotTableSql);
    }

    private function ensureColumnExists(string $tableName, string $columnName, string $alterSql): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([
            'table_name' => $tableName,
            'column_name' => $columnName,
        ]);

        if ((int)$stmt->fetchColumn() === 0) {
            $this->pdo->exec($alterSql);
        }
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
