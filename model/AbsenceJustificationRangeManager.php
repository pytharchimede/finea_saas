<?php

class AbsenceJustificationRangeManager
{
    private PDO $pdo;
    private array $holidayCache = [];

    private const MAX_ABSENCE_PERIOD_SCAN_DAYS = 45;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public function getAbsencePeriod(int $personnelId, string $anchorDate): ?array
    {
        $anchorDate = $this->normalizeDate($anchorDate);
        if ($personnelId <= 0 || $anchorDate === null || !$this->isAbsenceDate($personnelId, $anchorDate)) {
            return null;
        }

        $anchor = new DateTimeImmutable($anchorDate);

        // Regle metier FINEA : les absences ne se reportent jamais d'un mois a l'autre.
        // La periode justifiable est donc bornee au mois de la date d'ancrage.
        $monthStart = $anchor->modify('first day of this month');
        $monthEnd = $anchor->modify('last day of this month');
        $today = new DateTimeImmutable('today');
        $maxScanDate = $monthEnd > $today ? $today : $monthEnd;

        $start = $anchorDate;
        $cursor = $anchor->modify('-1 day');
        while ($cursor >= $monthStart) {
            $cursorDate = $cursor->format('Y-m-d');
            if (!$this->isAbsencePeriodDate($personnelId, $cursorDate)) {
                break;
            }
            if ($this->isWorkingCalendarDate($cursorDate)) {
                $start = $cursorDate;
            }
            $cursor = $cursor->modify('-1 day');
        }

        $end = $anchorDate;
        $cursor = $anchor->modify('+1 day');
        while ($cursor <= $maxScanDate) {
            $cursorDate = $cursor->format('Y-m-d');
            if (!$this->isAbsencePeriodDate($personnelId, $cursorDate)) {
                break;
            }
            if ($this->isWorkingCalendarDate($cursorDate)) {
                $end = $cursorDate;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return [
            'personnel_id' => $personnelId,
            'date_debut' => $start,
            'date_fin' => $end,
            'nb_jours' => $this->inclusiveDays($start, $end),
        ];
    }

    public function getJustifiedRanges(int $personnelId, string $absenceStart, string $absenceEnd, array $statuses = ['submitted', 'approved']): array
    {
        $absenceStart = $this->normalizeDate($absenceStart) ?? $absenceStart;
        $absenceEnd = $this->normalizeDate($absenceEnd) ?? $absenceEnd;

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM absence_justification_ranges
            WHERE personnel_id = ?
              AND statut_validation IN ($placeholders)
              AND date_debut <= ?
              AND date_fin >= ?
            ORDER BY date_debut ASC, date_fin ASC
        ");
        $params = array_merge([$personnelId], array_values($statuses), [$absenceEnd, $absenceStart]);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function validateJustificationRange(int $personnelId, string $anchorDate, string $dateDebut, string $dateFin, ?int $ignoreRangeId = null): array
    {
        $dateDebut = $this->normalizeDate($dateDebut);
        $dateFin = $this->normalizeDate($dateFin);
        if ($dateDebut === null || $dateFin === null) {
            return ['ok' => false, 'message' => 'La periode du justificatif est invalide.'];
        }
        if ($dateFin < $dateDebut) {
            return ['ok' => false, 'message' => 'La date de fin doit etre superieure ou egale a la date de debut.'];
        }

        $period = $this->getAbsencePeriod($personnelId, $anchorDate);
        if ($period === null) {
            $cursor = new DateTimeImmutable($dateDebut);
            $end = new DateTimeImmutable($dateFin);
            while ($cursor <= $end) {
                $candidate = $cursor->format('Y-m-d');
                if ($this->isAbsenceDate($personnelId, $candidate)) {
                    $period = $this->getAbsencePeriod($personnelId, $candidate);
                    break;
                }
                $cursor = $cursor->modify('+1 day');
            }
        }
        if ($period === null) {
            return ['ok' => false, 'message' => 'Aucune periode d absence valide ne correspond a cette date.'];
        }

        // Important : aucun delai bloquant de 48h n'est applique ici.
        // Les relances mails peuvent continuer a exister, mais elles ne doivent
        // jamais empecher la soumission d'un justificatif d'absence.
        if ($dateDebut < $period['date_debut'] || $dateFin > $period['date_fin']) {
            return ['ok' => false, 'message' => 'La plage du justificatif doit rester dans la periode d absence detectee.'];
        }

        $cursor = new DateTimeImmutable($dateDebut);
        $end = new DateTimeImmutable($dateFin);
        while ($cursor <= $end) {
            $date = $cursor->format('Y-m-d');
            if ($this->isWorkingCalendarDate($date) && !$this->isAbsenceDate($personnelId, $date)) {
                return ['ok' => false, 'message' => 'La plage contient au moins une date qui n est pas une absence a justifier.'];
            }
            $cursor = $cursor->modify('+1 day');
        }

        if ($this->hasOverlap($personnelId, $dateDebut, $dateFin, $ignoreRangeId)) {
            return ['ok' => false, 'message' => 'Cette plage chevauche deja un justificatif soumis ou valide.'];
        }

        return ['ok' => true, 'message' => '', 'period' => $period, 'nb_jours' => $this->inclusiveDays($dateDebut, $dateFin)];
    }

    public function saveJustificationRange(array $data): int
    {
        $dateDebut = $this->normalizeDate((string) ($data['date_debut'] ?? ''));
        $dateFin = $this->normalizeDate((string) ($data['date_fin'] ?? ''));
        if ($dateDebut === null || $dateFin === null) {
            throw new RuntimeException('Plage de justificatif invalide.');
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO absence_justification_ranges (
                absence_id,
                legal_request_id,
                personnel_id,
                absence_date_debut,
                absence_date_fin,
                date_debut,
                date_fin,
                nb_jours,
                fichier_justificatif,
                commentaire,
                statut_validation,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                :absence_id,
                :legal_request_id,
                :personnel_id,
                :absence_date_debut,
                :absence_date_fin,
                :date_debut,
                :date_fin,
                :nb_jours,
                :fichier_justificatif,
                :commentaire,
                :statut_validation,
                :created_by,
                NOW(),
                NOW()
            )
        ');

        $stmt->execute([
            'absence_id' => (int) ($data['absence_id'] ?? 0),
            'legal_request_id' => (int) ($data['legal_request_id'] ?? 0) ?: null,
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'absence_date_debut' => (string) ($data['absence_date_debut'] ?? $dateDebut),
            'absence_date_fin' => (string) ($data['absence_date_fin'] ?? $dateFin),
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
            'nb_jours' => $this->inclusiveDays($dateDebut, $dateFin),
            'fichier_justificatif' => (string) ($data['fichier_justificatif'] ?? ''),
            'commentaire' => (string) ($data['commentaire'] ?? ''),
            'statut_validation' => (string) ($data['statut_validation'] ?? 'submitted'),
            'created_by' => (int) ($data['created_by'] ?? 0) ?: null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function syncStatusFromLegalRequest(int $legalRequestId, string $status): void
    {
        $status = in_array($status, ['submitted', 'approved', 'rejected', 'cancelled'], true) ? $status : 'submitted';
        $stmt = $this->pdo->prepare('
            UPDATE absence_justification_ranges
            SET statut_validation = :status, updated_at = NOW()
            WHERE legal_request_id = :legal_request_id
        ');
        $stmt->execute(['status' => $status, 'legal_request_id' => $legalRequestId]);
    }

    public function calculateCoveredDays(int $personnelId, string $absenceStart, string $absenceEnd): int
    {
        $days = 0;
        foreach ($this->mergeRanges($this->getJustifiedRanges($personnelId, $absenceStart, $absenceEnd)) as $range) {
            $days += $this->inclusiveDays($range['date_debut'], $range['date_fin']);
        }
        return $days;
    }

    public function calculateRemainingRanges(int $personnelId, string $absenceStart, string $absenceEnd): array
    {
        $absenceStart = $this->normalizeDate($absenceStart) ?? $absenceStart;
        $absenceEnd = $this->normalizeDate($absenceEnd) ?? $absenceEnd;
        $covered = $this->mergeRanges($this->getJustifiedRanges($personnelId, $absenceStart, $absenceEnd));

        $remaining = [];
        $cursor = new DateTimeImmutable($absenceStart);
        $end = new DateTimeImmutable($absenceEnd);

        foreach ($covered as $range) {
            $rangeStart = new DateTimeImmutable(max($absenceStart, (string) $range['date_debut']));
            $rangeEnd = new DateTimeImmutable(min($absenceEnd, (string) $range['date_fin']));
            if ($cursor < $rangeStart) {
                $gapEnd = $rangeStart->modify('-1 day');
                $remaining[] = $this->rangeArray($cursor, $gapEnd);
            }
            if ($cursor <= $rangeEnd) {
                $cursor = $rangeEnd->modify('+1 day');
            }
        }

        if ($cursor <= $end) {
            $remaining[] = $this->rangeArray($cursor, $end);
        }

        return $remaining;
    }

    public function updateAbsenceJustificationStatus(int $personnelId, string $absenceStart, string $absenceEnd): string
    {
        $remaining = $this->calculateRemainingRanges($personnelId, $absenceStart, $absenceEnd);
        if (!$remaining) {
            return 'submitted';
        }

        $coveredDays = $this->calculateCoveredDays($personnelId, $absenceStart, $absenceEnd);
        return $coveredDays > 0 ? 'partial' : 'none';
    }

    private function hasOverlap(int $personnelId, string $dateDebut, string $dateFin, ?int $ignoreRangeId = null): bool
    {
        $sql = '
            SELECT COUNT(*)
            FROM absence_justification_ranges
            WHERE personnel_id = :personnel_id
              AND statut_validation IN ("submitted", "approved")
              AND date_debut <= :date_fin
              AND date_fin >= :date_debut
        ';
        $params = [
            'personnel_id' => $personnelId,
            'date_debut' => $dateDebut,
            'date_fin' => $dateFin,
        ];
        if ($ignoreRangeId !== null && $ignoreRangeId > 0) {
            $sql .= ' AND id <> :ignore_id';
            $params['ignore_id'] = $ignoreRangeId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function isAbsenceDate(int $personnelId, string $date): bool
    {
        $date = $this->normalizeDate($date);
        if ($date === null || $date > date('Y-m-d')) {
            return false;
        }

        $holidaySet = $this->holidaySet($date, $date);
        $dt = new DateTimeImmutable($date);
        if ((int) $dt->format('N') >= 6 || isset($holidaySet[$date])) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            SELECT attendance_status
            FROM rh_attendance_daily
            WHERE personnel_id = :personnel_id AND attendance_date = :attendance_date
            LIMIT 1
        ');
        $stmt->execute(['personnel_id' => $personnelId, 'attendance_date' => $date]);
        $status = strtolower(trim((string) ($stmt->fetchColumn() ?: '')));

        // Une ligne inexistante dans rh_attendance_daily ne doit jamais etre consideree
        // comme une absence. Sinon la recherche de periode recule indefiniment
        // dans le passe et sature la page.
        return $status === 'absence';
    }

    private function isAbsencePeriodDate(int $personnelId, string $date): bool
    {
        if (!$this->isWorkingCalendarDate($date)) {
            return true;
        }

        return $this->isAbsenceDate($personnelId, $date);
    }

    private function isWorkingCalendarDate(string $date): bool
    {
        $date = $this->normalizeDate($date);
        if ($date === null || $date > date('Y-m-d')) {
            return false;
        }

        $holidaySet = $this->holidaySet($date, $date);
        $dt = new DateTimeImmutable($date);
        return (int) $dt->format('N') < 6 && !isset($holidaySet[$date]);
    }

    private function mergeRanges(array $ranges): array
    {
        if (!$ranges) {
            return [];
        }

        usort($ranges, static fn(array $a, array $b): int => strcmp((string) $a['date_debut'], (string) $b['date_debut']));
        $merged = [];
        foreach ($ranges as $range) {
            $start = (string) $range['date_debut'];
            $end = (string) $range['date_fin'];
            if (!$merged) {
                $merged[] = ['date_debut' => $start, 'date_fin' => $end];
                continue;
            }

            $lastIndex = count($merged) - 1;
            $lastEndNext = (new DateTimeImmutable((string) $merged[$lastIndex]['date_fin']))->modify('+1 day')->format('Y-m-d');
            if ($start <= $lastEndNext) {
                if ($end > $merged[$lastIndex]['date_fin']) {
                    $merged[$lastIndex]['date_fin'] = $end;
                }
            } else {
                $merged[] = ['date_debut' => $start, 'date_fin' => $end];
            }
        }

        return $merged;
    }

    private function rangeArray(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        return [
            'date_debut' => $start->format('Y-m-d'),
            'date_fin' => $end->format('Y-m-d'),
            'nb_jours' => $this->inclusiveDays($start->format('Y-m-d'), $end->format('Y-m-d')),
        ];
    }

    private function inclusiveDays(string $dateDebut, string $dateFin): int
    {
        $start = new DateTimeImmutable($dateDebut);
        $end = new DateTimeImmutable($dateFin);
        return max(0, $start->diff($end)->days + 1);
    }

    private function holidaySet(string $startDate, string $endDate): array
    {
        $startDate = $this->normalizeDate($startDate) ?? $startDate;
        $endDate = $this->normalizeDate($endDate) ?? $endDate;
        $cacheKey = $startDate . '_' . $endDate;
        if (isset($this->holidayCache[$cacheKey])) {
            return $this->holidayCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date');
        $stmt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
        $set = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $date = (string) ($row['holiday_date'] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $set[$date] = true;
            }
        }

        $this->holidayCache[$cacheKey] = $set;
        return $set;
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00') {
            return null;
        }

        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $dt ? $dt->format('Y-m-d') : null;
    }

    private function ensureTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS absence_justification_ranges (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                absence_id INT UNSIGNED DEFAULT 0,
                legal_request_id INT UNSIGNED DEFAULT NULL,
                personnel_id INT NOT NULL,
                absence_date_debut DATE NOT NULL,
                absence_date_fin DATE NOT NULL,
                date_debut DATE NOT NULL,
                date_fin DATE NOT NULL,
                nb_jours INT NOT NULL DEFAULT 1,
                fichier_justificatif TEXT DEFAULT NULL,
                commentaire TEXT DEFAULT NULL,
                statut_validation VARCHAR(32) NOT NULL DEFAULT "submitted",
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_abs_justif_personnel_period (personnel_id, absence_date_debut, absence_date_fin),
                KEY idx_abs_justif_dates (personnel_id, date_debut, date_fin),
                KEY idx_abs_justif_request (legal_request_id),
                KEY idx_abs_justif_status (statut_validation)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
