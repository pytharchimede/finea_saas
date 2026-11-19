<?php

class PayrollSlipRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createSlip(array $slip, array $snapshot): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO payroll_slips (
            personnel_id, payroll_period, rule_version_id, currency_code, gross_salary, sbi, sbs,
            taxable_base, gross_tax, ricf, net_tax, employee_contributions, employer_contributions,
            net_pay, status, payload_json, created_by_user_id, created_by_name, created_at
        ) VALUES (
            :personnel_id, :payroll_period, :rule_version_id, :currency_code, :gross_salary, :sbi, :sbs,
            :taxable_base, :gross_tax, :ricf, :net_tax, :employee_contributions, :employer_contributions,
            :net_pay, :status, :payload_json, :created_by_user_id, :created_by_name, NOW()
        )');

        $stmt->execute([
            'personnel_id' => $slip['personnel_id'],
            'payroll_period' => $slip['payroll_period'],
            'rule_version_id' => $slip['rule_version_id'],
            'currency_code' => $slip['currency_code'],
            'gross_salary' => $slip['gross_salary'],
            'sbi' => $slip['sbi'],
            'sbs' => $slip['sbs'],
            'taxable_base' => $slip['taxable_base'],
            'gross_tax' => $slip['gross_tax'],
            'ricf' => $slip['ricf'],
            'net_tax' => $slip['net_tax'],
            'employee_contributions' => $slip['employee_contributions'],
            'employer_contributions' => $slip['employer_contributions'],
            'net_pay' => $slip['net_pay'],
            'status' => $slip['status'] ?? 'draft',
            'payload_json' => payroll_ci_json($snapshot),
            'created_by_user_id' => $slip['created_by_user_id'] ?? null,
            'created_by_name' => $slip['created_by_name'] ?? null,
        ]);

        $slipId = (int) $this->pdo->lastInsertId();
        $snapshotStmt = $this->pdo->prepare('INSERT INTO payroll_calculation_snapshots (payroll_slip_id, payload_json, created_at) VALUES (:payroll_slip_id, :payload_json, NOW())');
        $snapshotStmt->execute([
            'payroll_slip_id' => $slipId,
            'payload_json' => payroll_ci_json($snapshot),
        ]);

        return $slipId;
    }

    public function isPeriodLocked(string $countryCode, string $period): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payroll_period_locks WHERE country_code = :country_code AND payroll_period = :period');
        $stmt->execute(['country_code' => $countryCode, 'period' => $period]);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function lockPeriod(string $countryCode, string $period, array $actor = []): void
    {
        if (!preg_match('/^\\d{4}-\\d{2}$/', $period)) {
            throw new RuntimeException('Periode invalide.');
        }

        $stmt = $this->pdo->prepare('INSERT IGNORE INTO payroll_period_locks (country_code, payroll_period, locked_by_user_id, locked_by_name, locked_at) VALUES (:country_code, :period, :user_id, :user_name, NOW())');
        $stmt->execute([
            'country_code' => strtoupper($countryCode),
            'period' => $period,
            'user_id' => $actor['user_id'] ?? null,
            'user_name' => $actor['user_name'] ?? null,
        ]);
    }

    public function listPeriodLocks(int $limit = 24): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_period_locks ORDER BY payroll_period DESC, locked_at DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findSlip(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ps.*, prv.code AS rule_version_code, prv.label AS rule_version_label FROM payroll_slips ps LEFT JOIN payroll_rule_versions prv ON prv.id = ps.rule_version_id WHERE ps.id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
        return $row;
    }

    public function findByPersonnelAndPeriod(int $personnelId, string $period): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_slips WHERE personnel_id = :personnel_id AND payroll_period = :period ORDER BY id DESC LIMIT 1');
        $stmt->execute(['personnel_id' => $personnelId, 'period' => $period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row['payload'] = json_decode((string) ($row['payload_json'] ?? ''), true) ?: [];
        return $row;
    }

    public function listRecentSlips(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT ps.*, prv.code AS rule_version_code
            FROM payroll_slips ps
            LEFT JOIN payroll_rule_versions prv ON prv.id = ps.rule_version_id
            ORDER BY ps.created_at DESC, ps.id DESC
            LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listRecentAuditEvents(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare('SELECT id, event_type, action, entity_id, entity_label, user_name, result, severity, details_json, created_at
            FROM audit_trail
            WHERE module = "payroll" OR event_type LIKE "payroll_%"
            ORDER BY created_at DESC, id DESC
            LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
