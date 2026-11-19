<?php

class EmployeeContractSalaryComponentRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    public function ensureTable(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS employee_contract_salary_components (
                id INT AUTO_INCREMENT PRIMARY KEY,
                contract_id INT NOT NULL,
                personnel_id INT NOT NULL,
                payroll_salary_item_id INT NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                quantity DECIMAL(15,2) NOT NULL DEFAULT 1.00,
                effective_date DATE NULL,
                end_date DATE NULL,
                is_recurring TINYINT(1) NOT NULL DEFAULT 1,
                active TINYINT(1) NOT NULL DEFAULT 1,
                source VARCHAR(50) NOT NULL DEFAULT 'contract',
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ecsc_contract (contract_id),
                INDEX idx_ecsc_personnel (personnel_id),
                INDEX idx_ecsc_item (payroll_salary_item_id),
                INDEX idx_ecsc_dates (effective_date, end_date),
                INDEX idx_ecsc_active (active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumns([
            'personnel_id' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN personnel_id INT NOT NULL DEFAULT 0 AFTER contract_id',
            'payroll_salary_item_id' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN payroll_salary_item_id INT NOT NULL DEFAULT 0 AFTER personnel_id',
            'amount' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER payroll_salary_item_id',
            'quantity' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN quantity DECIMAL(15,2) NOT NULL DEFAULT 1.00 AFTER amount',
            'effective_date' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN effective_date DATE NULL AFTER quantity',
            'end_date' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN end_date DATE NULL AFTER effective_date',
            'is_recurring' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN is_recurring TINYINT(1) NOT NULL DEFAULT 1 AFTER end_date',
            'active' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_recurring',
            'source' => "ALTER TABLE employee_contract_salary_components ADD COLUMN source VARCHAR(50) NOT NULL DEFAULT 'contract' AFTER active",
            'notes' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN notes TEXT NULL AFTER source',
            'created_by' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN created_by INT NULL AFTER notes',
            'created_at' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by',
            'updated_at' => 'ALTER TABLE employee_contract_salary_components ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ]);
    }

    public function findByContractForPeriod(int $contractId, string $period): array
    {
        [$periodStart, $periodEnd] = $this->periodBounds($period);
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM employee_contract_salary_components
            WHERE contract_id = :contract_id
              AND active = 1
              AND is_recurring = 1
              AND (effective_date IS NULL OR effective_date <= :period_end)
              AND (end_date IS NULL OR end_date >= :period_start)
            ORDER BY id ASC
        ");
        $stmt->execute([
            'contract_id' => $contractId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findMergedWithPayrollItems(int $contractId, int $personnelId, int $versionId, string $period): array
    {
        [$periodStart, $periodEnd] = $this->periodBounds($period);
        $stmt = $this->pdo->prepare("
            SELECT
                psi.*,
                ecsc.id AS component_id,
                ecsc.amount AS contract_amount,
                ecsc.quantity AS contract_quantity,
                ecsc.effective_date AS component_effective_date,
                ecsc.end_date AS component_end_date,
                ecsc.notes AS component_notes
            FROM payroll_salary_items psi
            LEFT JOIN employee_contract_salary_components ecsc
              ON ecsc.payroll_salary_item_id = psi.id
             AND ecsc.contract_id = :contract_id
             AND ecsc.personnel_id = :personnel_id
             AND ecsc.active = 1
             AND ecsc.is_recurring = 1
             AND (ecsc.effective_date IS NULL OR ecsc.effective_date <= :period_end)
             AND (ecsc.end_date IS NULL OR ecsc.end_date >= :period_start)
            WHERE psi.version_id = :version_id
              AND psi.active = 1
            ORDER BY psi.category ASC, psi.code ASC
        ");
        $stmt->execute([
            'contract_id' => $contractId,
            'personnel_id' => $personnelId,
            'version_id' => $versionId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveComponent(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            'contract_id' => (int) ($data['contract_id'] ?? 0),
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'payroll_salary_item_id' => (int) ($data['payroll_salary_item_id'] ?? 0),
            'amount' => $this->positiveDecimal($data['amount'] ?? 0),
            'quantity' => $this->positiveDecimal($data['quantity'] ?? 1),
            'effective_date' => $this->nullableDate($data['effective_date'] ?? null),
            'end_date' => $this->nullableDate($data['end_date'] ?? null),
            'is_recurring' => !empty($data['is_recurring']) ? 1 : 0,
            'active' => !empty($data['active']) ? 1 : 0,
            'source' => $this->nullableString($data['source'] ?? null) ?? 'contract',
            'notes' => $this->nullableString($data['notes'] ?? null),
            'created_by' => $this->nullableInt($data['created_by'] ?? null),
        ];
        if ($payload['contract_id'] <= 0 || $payload['personnel_id'] <= 0 || $payload['payroll_salary_item_id'] <= 0) {
            throw new RuntimeException('Contrat, employe et rubrique obligatoires.');
        }

        if ($id <= 0) {
            $existingStmt = $this->pdo->prepare('
                SELECT id
                FROM employee_contract_salary_components
                WHERE contract_id = :contract_id
                  AND personnel_id = :personnel_id
                  AND payroll_salary_item_id = :payroll_salary_item_id
                  AND active = 1
                ORDER BY id DESC
                LIMIT 1
            ');
            $existingStmt->execute([
                'contract_id' => $payload['contract_id'],
                'personnel_id' => $payload['personnel_id'],
                'payroll_salary_item_id' => $payload['payroll_salary_item_id'],
            ]);
            $id = (int) ($existingStmt->fetchColumn() ?: 0);
            $existingStmt->closeCursor();
        }

        if ($id > 0) {
            $updatePayload = $payload;
            unset($updatePayload['created_by']);
            $stmt = $this->pdo->prepare("
                UPDATE employee_contract_salary_components
                SET contract_id = :contract_id,
                    personnel_id = :personnel_id,
                    payroll_salary_item_id = :payroll_salary_item_id,
                    amount = :amount,
                    quantity = :quantity,
                    effective_date = :effective_date,
                    end_date = :end_date,
                    is_recurring = :is_recurring,
                    active = :active,
                    source = :source,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute($updatePayload + ['id' => $id]);
            return $id;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO employee_contract_salary_components (
                contract_id, personnel_id, payroll_salary_item_id, amount, quantity,
                effective_date, end_date, is_recurring, active, source, notes, created_by
            ) VALUES (
                :contract_id, :personnel_id, :payroll_salary_item_id, :amount, :quantity,
                :effective_date, :end_date, :is_recurring, :active, :source, :notes, :created_by
            )
        ");
        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    public function deactivateComponent(int $id): bool
    {
        $stmt = $this->pdo->prepare('UPDATE employee_contract_salary_components SET active = 0 WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    private function periodBounds(string $period): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Periode invalide.');
        }
        $start = $period . '-01';
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        return [$start, $end];
    }

    private function positiveDecimal($value): float
    {
        $amount = (float) str_replace([' ', ','], ['', '.'], (string) $value);
        if ($amount < 0) {
            throw new RuntimeException('Les montants recurrentiels doivent etre positifs.');
        }
        return round($amount, 2);
    }

    private function nullableDate($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new RuntimeException('Date de composant invalide.');
        }
        return $value;
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableInt($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function ensureColumns(array $columns): void
    {
        foreach ($columns as $column => $alterSql) {
            $stmt = $this->pdo->prepare('SHOW COLUMNS FROM employee_contract_salary_components LIKE :column_name');
            $stmt->bindValue(':column_name', $column);
            $stmt->execute();
            $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if (!$exists) {
                $this->pdo->exec($alterSql);
            }
        }
    }
}
