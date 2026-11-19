<?php

class RhAttendancePeriodManager
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS rh_attendance_period_closures (
                period_key VARCHAR(7) PRIMARY KEY,
                cutoff_date DATE NOT NULL,
                updated_by INT NULL,
                updated_by_name VARCHAR(150) NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public function save(string $period, string $cutoffDate, array $actor = []): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cutoffDate)) {
            throw new InvalidArgumentException('Période ou date de clôture invalide.');
        }
        if (substr($cutoffDate, 0, 7) !== $period) {
            throw new InvalidArgumentException('La clôture doit être située dans le mois sélectionné.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO rh_attendance_period_closures(period_key, cutoff_date, updated_by, updated_by_name)
             VALUES(:period, :cutoff, :user_id, :user_name)
             ON DUPLICATE KEY UPDATE cutoff_date=VALUES(cutoff_date), updated_by=VALUES(updated_by),
                updated_by_name=VALUES(updated_by_name), updated_at=CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'period' => $period,
            'cutoff' => $cutoffDate,
            'user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            'user_name' => trim((string) ($actor['user_name'] ?? '')) ?: null,
        ]);
    }

    public function delete(string $period): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rh_attendance_period_closures WHERE period_key=:period');
        $stmt->execute(['period' => $period]);
    }

    public function get(string $period): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM rh_attendance_period_closures WHERE period_key=:period LIMIT 1');
        $stmt->execute(['period' => $period]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function resolve(string $period, ?string $requestedStart = null, ?string $requestedEnd = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            $period = date('Y-m');
        }
        $monthStart = $period . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        if ($this->validDate($requestedStart) && $this->validDate($requestedEnd) && $requestedEnd >= $requestedStart) {
            return ['period' => $period, 'start_date' => $requestedStart, 'end_date' => $requestedEnd, 'custom' => true, 'cutoff_date' => null];
        }
        if ($this->validDate($requestedStart) && !$this->validDate($requestedEnd)) {
            return ['period' => $period, 'start_date' => $requestedStart, 'end_date' => $requestedStart, 'custom' => true, 'cutoff_date' => null];
        }

        $current = $this->get($period);
        $previousPeriod = date('Y-m', strtotime($monthStart . ' -1 month'));
        $previous = $this->get($previousPeriod);
        $startDate = $previous ? date('Y-m-d', strtotime((string) $previous['cutoff_date'] . ' +1 day')) : $monthStart;
        $endDate = $current ? (string) $current['cutoff_date'] : $monthEnd;

        return [
            'period' => $period,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'custom' => false,
            'cutoff_date' => $current['cutoff_date'] ?? null,
        ];
    }

    private function validDate(?string $date): bool
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }
}
