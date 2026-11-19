<?php
declare(strict_types=1);

require_once __DIR__ . '/FebPlanningSynchronizationService.php';

final class ChantierScheduleReplanningService
{
    public function __construct(private PDO $pdo) {}

    public function replan(int $chantierId, string $newStart, ?string $newEnd, int $userId = 0, string $userName = ''): array
    {
        $this->assertDate($newStart);
        if ($newEnd) {
            $this->assertDate($newEnd);
            if ($newEnd < $newStart) {
                throw new DomainException('La date de fin doit être postérieure ou égale à la date de début.');
            }
        }

        $current = $this->currentSchedule($chantierId);
        $oldStart = $current['date_debut'];
        if (!$oldStart) {
            throw new RuntimeException('Le chantier ne possède pas de date de début de référence.');
        }

        $delta = $this->daysBetween($oldStart, $newStart);
        $effectiveEnd = $newEnd ?: ($current['date_fin_prevue'] ? $this->shift($current['date_fin_prevue'], $delta) : null);
        $reason = sprintf('Replanification chantier #%d : %s -> %s (%+d jour(s))', $chantierId, $oldStart, $newStart, $delta);

        $started = !$this->pdo->inTransaction();
        if ($started) {
            $this->pdo->beginTransaction();
        }

        try {
            $this->updateChantier($chantierId, $newStart, $effectiveEnd);
            $this->updatePlanningProject($chantierId, $newStart, $effectiveEnd);
            $this->synchronizeQuotePlanningPeriod($chantierId, $newStart, $effectiveEnd);

            $debourseIds = $this->debourseIds($chantierId);
            $lineIds = $this->shiftDisbursements($debourseIds, $delta, $reason);
            $ficheCount = $this->shiftFiches($chantierId, $lineIds, $delta);
            $febCount = $this->shiftFebs($chantierId, $lineIds, $delta, $reason, $userId, $userName);
            $this->refreshDebourseBounds($debourseIds, $reason);

            if ($started) {
                $this->pdo->commit();
            }

            return [
                'old_start' => $oldStart,
                'new_start' => $newStart,
                'new_end' => $effectiveEnd,
                'delta_days' => $delta,
                'debourses' => count($debourseIds),
                'lines' => count($lineIds),
                'fiches' => $ficheCount,
                'febs' => $febCount,
            ];
        } catch (Throwable $e) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function currentSchedule(int $chantierId): array
    {
        if ($chantierId <= 0) {
            throw new DomainException('Chantier invalide.');
        }

        $start = null;
        $end = null;
        if ($this->tableExists('planning_chantier_projects')) {
            $stmt = $this->pdo->prepare('SELECT date_debut,date_fin_prevue FROM planning_chantier_projects WHERE chantier_id=:id ORDER BY id DESC LIMIT 1');
            $stmt->execute(['id' => $chantierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $start = $this->nullableDate($row['date_debut'] ?? null);
            $end = $this->nullableDate($row['date_fin_prevue'] ?? null);
        }

        $columns = $this->columns('chantier_banamur');
        if (!$start || !$end) {
            $select = [
                in_array('date_debut', $columns, true) ? 'date_debut' : 'NULL AS date_debut',
                in_array('date_fin_prevue', $columns, true) ? 'date_fin_prevue' : 'NULL AS date_fin_prevue',
            ];
            $stmt = $this->pdo->prepare('SELECT ' . implode(',', $select) . ' FROM chantier_banamur WHERE id=:id LIMIT 1');
            $stmt->execute(['id' => $chantierId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $start ??= $this->nullableDate($row['date_debut'] ?? null);
            $end ??= $this->nullableDate($row['date_fin_prevue'] ?? null);
        }

        return ['date_debut' => $start, 'date_fin_prevue' => $end];
    }

    private function updateChantier(int $id, string $start, ?string $end): void
    {
        $columns = $this->columns('chantier_banamur');
        $set = [];
        $params = ['id' => $id];
        if (in_array('date_debut', $columns, true)) {
            $set[] = 'date_debut=:start';
            $params['start'] = $start;
        }
        if (in_array('date_fin_prevue', $columns, true)) {
            $set[] = 'date_fin_prevue=:end';
            $params['end'] = $end;
        }
        if ($set) {
            $stmt = $this->pdo->prepare('UPDATE chantier_banamur SET ' . implode(',', $set) . ' WHERE id=:id');
            $stmt->execute($params);
        }
    }

    private function updatePlanningProject(int $id, string $start, ?string $end): void
    {
        if (!$this->tableExists('planning_chantier_projects')) {
            return;
        }
        $columns = $this->columns('planning_chantier_projects');
        $set = ['date_debut=:start', 'date_fin_prevue=:end'];
        if (in_array('updated_at', $columns, true)) {
            $set[] = 'updated_at=NOW()';
        }
        $stmt = $this->pdo->prepare('UPDATE planning_chantier_projects SET ' . implode(',', $set) . ' WHERE chantier_id=:id');
        $stmt->execute(['start' => $start, 'end' => $end, 'id' => $id]);
    }

    /**
     * Le modèle Devis valide encore les dates de déboursé à partir de
     * devis_banamur.project_start_date/project_end_date lorsqu'elles existent.
     * Ces colonnes doivent donc rester synchronisées avec la période chantier.
     */
    private function synchronizeQuotePlanningPeriod(int $chantierId, string $start, ?string $end): void
    {
        if (!$end || !$this->tableExists('devis_banamur')) {
            return;
        }

        $devisColumns = $this->columns('devis_banamur');
        if (!in_array('project_start_date', $devisColumns, true) || !in_array('project_end_date', $devisColumns, true)) {
            return;
        }

        $chantierColumns = $this->columns('chantier_banamur');
        if (!in_array('devis_id', $chantierColumns, true)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE devis_banamur d
             INNER JOIN chantier_banamur c ON c.devis_id = d.id
             SET d.project_start_date=:start, d.project_end_date=:end
             WHERE c.id=:chantier_id'
        );
        $stmt->execute(['start' => $start, 'end' => $end, 'chantier_id' => $chantierId]);
    }

    private function debourseIds(int $chantierId): array
    {
        $ids = [];
        $queries = [
            'SELECT id FROM debourse_banamur WHERE chantier_id=:id',
            'SELECT DISTINCT d.id FROM debourse_banamur d JOIN ligne_devis_banamur ld ON ld.id=d.ligne_devis_id JOIN devis_banamur dv ON dv.id_devis=ld.devis_id WHERE dv.chantier_id=:id',
        ];
        foreach ($queries as $sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute(['id' => $chantierId]);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                    if ((int)$id > 0) {
                        $ids[(int)$id] = (int)$id;
                    }
                }
            } catch (Throwable) {
            }
        }
        return array_values($ids);
    }

    private function shiftDisbursements(array $ids, int $delta, string $reason): array
    {
        if (!$ids) return [];
        $in = implode(',', array_map('intval', $ids));
        $stmt = $this->pdo->query("SELECT id,date_debut,date_fin FROM ligne_debourse_banamur WHERE debourse_id IN ($in) FOR UPDATE");
        $update = $this->pdo->prepare('UPDATE ligne_debourse_banamur SET date_debut=:start,date_fin=:end WHERE id=:id');
        $lineIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $id = (int)$row['id'];
            $lineIds[] = $id;
            $a = $this->nullableDate($row['date_debut'] ?? null);
            $b = $this->nullableDate($row['date_fin'] ?? null);
            $update->execute(['start' => $a ? $this->shift($a, $delta) : null, 'end' => $b ? $this->shift($b, $delta) : null, 'id' => $id]);
        }

        $stmt = $this->pdo->query("SELECT id,date_debut,date_fin FROM debourse_banamur WHERE id IN ($in) FOR UPDATE");
        $update = $this->pdo->prepare('UPDATE debourse_banamur SET date_debut=:start,date_fin=:end,motif_modif=:reason WHERE id=:id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $a = $this->nullableDate($row['date_debut'] ?? null);
            $b = $this->nullableDate($row['date_fin'] ?? null);
            $update->execute(['start' => $a ? $this->shift($a, $delta) : null, 'end' => $b ? $this->shift($b, $delta) : null, 'reason' => $reason, 'id' => (int)$row['id']]);
        }
        return array_values(array_unique($lineIds));
    }

    private function shiftFiches(int $chantierId, array $lineIds, int $delta): int
    {
        $conditions = ['chantier_id=:chantier'];
        if ($lineIds) {
            $conditions[] = 'ligne_debourse_id IN (' . implode(',', array_map('intval', $lineIds)) . ')';
        }
        $stmt = $this->pdo->prepare('SELECT id_fiche,planned_start_date,planned_end_date,date_decaissement_minimum FROM fiche_decaissement WHERE ' . implode(' OR ', $conditions) . ' FOR UPDATE');
        $stmt->execute(['chantier' => $chantierId]);
        $update = $this->pdo->prepare('UPDATE fiche_decaissement SET planned_start_date=:start,planned_end_date=:end,date_decaissement_minimum=:scheduled WHERE id_fiche=:id');
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $a = $this->nullableDate($row['planned_start_date'] ?? null);
            $b = $this->nullableDate($row['planned_end_date'] ?? null);
            $c = $this->nullableDate($row['date_decaissement_minimum'] ?? null);
            $update->execute(['start' => $a ? $this->shift($a, $delta) : null, 'end' => $b ? $this->shift($b, $delta) : null, 'scheduled' => $c ? $this->shift($c, $delta) : null, 'id' => (int)$row['id_fiche']]);
            $count++;
        }
        return $count;
    }

    private function shiftFebs(int $chantierId, array $lineIds, int $delta, string $reason, int $userId, string $userName): int
    {
        if (!$this->tableExists('employee_legal_requests')) return 0;
        $ids = [];
        if ($this->tableExists('feb_disbursement_links') && $lineIds) {
            $in = implode(',', array_map('intval', $lineIds));
            $query = $this->pdo->query("SELECT DISTINCT feb_request_id FROM feb_disbursement_links WHERE ligne_debourse_id IN ($in)");
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) if ((int)$id > 0) $ids[(int)$id] = (int)$id;
        }
        try {
            $query = $this->pdo->prepare('SELECT DISTINCT feb_request_id FROM fiche_decaissement WHERE chantier_id=:id AND feb_request_id IS NOT NULL');
            $query->execute(['id' => $chantierId]);
            foreach ($query->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) if ((int)$id > 0) $ids[(int)$id] = (int)$id;
        } catch (Throwable) {
        }
        if (!$ids) return 0;

        $sync = new FebPlanningSynchronizationService($this->pdo);
        $select = $this->pdo->prepare("SELECT payload_json FROM employee_legal_requests WHERE id=:id AND request_type='feb' LIMIT 1");
        $count = 0;
        foreach ($ids as $febId) {
            $select->execute(['id' => $febId]);
            $raw = $select->fetchColumn();
            if ($raw === false) continue;
            $payload = json_decode((string)$raw, true);
            if (!is_array($payload)) $payload = [];
            $disbursement = is_array($payload['provisional_decaissement'] ?? null) ? $payload['provisional_decaissement'] : [];
            $anchor = trim((string)($disbursement['date_decaissement_minimum'] ?? $payload['feb_position_date'] ?? $payload['planned_start_date'] ?? ''));
            if (!$this->nullableDate($anchor)) continue;
            $sync->synchronizeDate($febId, $this->shift($anchor, $delta), $reason, $userId, $userName);
            $count++;
        }
        return $count;
    }

    private function refreshDebourseBounds(array $ids, string $reason): void
    {
        if (!$ids) return;
        $bounds = $this->pdo->prepare('SELECT MIN(date_debut),MAX(date_fin) FROM ligne_debourse_banamur WHERE debourse_id=:id AND date_debut IS NOT NULL AND date_fin IS NOT NULL');
        $update = $this->pdo->prepare('UPDATE debourse_banamur SET date_debut=:start,date_fin=:end,motif_modif=:reason WHERE id=:id');
        foreach ($ids as $id) {
            $bounds->execute(['id' => $id]);
            [$min, $max] = $bounds->fetch(PDO::FETCH_NUM) ?: [null, null];
            if ($min && $max) {
                $update->execute(['start' => $min, 'end' => $max, 'reason' => $reason, 'id' => $id]);
            }
        }
    }

    private function daysBetween(string $a, string $b): int { return (int)(new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a'); }
    private function shift(string $date, int $days): string { return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d'); }
    private function nullableDate(mixed $date): ?string { $date = trim((string)$date); if ($date === '') return null; $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date); return $parsed && $parsed->format('Y-m-d') === $date ? $date : null; }
    private function assertDate(string $date): void { if (!$this->nullableDate($date)) throw new DomainException('Date invalide.'); }
    private function tableExists(string $table): bool { $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table'); $stmt->execute(['table' => $table]); return (bool)$stmt->fetchColumn(); }
    private function columns(string $table): array { try { return $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`')->fetchAll(PDO::FETCH_COLUMN, 0) ?: []; } catch (Throwable) { return []; } }
}
