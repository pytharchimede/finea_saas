<?php

class PayrollAdjustmentRepository
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
            CREATE TABLE IF NOT EXISTS payroll_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                payroll_salary_item_id INT NULL,
                payroll_period CHAR(7) NOT NULL,
                label VARCHAR(150) NOT NULL,
                adjustment_type VARCHAR(50) NOT NULL DEFAULT 'earning',
                amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                quantity DECIMAL(15,2) NOT NULL DEFAULT 1.00,
                affects_sbi TINYINT(1) NOT NULL DEFAULT 1,
                affects_sbs TINYINT(1) NOT NULL DEFAULT 1,
                affects_cnps TINYINT(1) NOT NULL DEFAULT 1,
                affects_cmu TINYINT(1) NOT NULL DEFAULT 1,
                status VARCHAR(30) NOT NULL DEFAULT 'pending',
                approved_by INT NULL,
                approved_at DATETIME NULL,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_payroll_adjustments_personnel (personnel_id),
                INDEX idx_payroll_adjustments_period (payroll_period),
                INDEX idx_payroll_adjustments_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->ensureColumns([
            'payroll_salary_item_id' => 'ALTER TABLE payroll_adjustments ADD COLUMN payroll_salary_item_id INT NULL AFTER personnel_id',
            'payroll_period' => "ALTER TABLE payroll_adjustments ADD COLUMN payroll_period CHAR(7) NOT NULL DEFAULT '1970-01' AFTER payroll_salary_item_id",
            'label' => "ALTER TABLE payroll_adjustments ADD COLUMN label VARCHAR(150) NOT NULL DEFAULT '' AFTER payroll_period",
            'adjustment_type' => "ALTER TABLE payroll_adjustments ADD COLUMN adjustment_type VARCHAR(50) NOT NULL DEFAULT 'earning' AFTER label",
            'amount' => 'ALTER TABLE payroll_adjustments ADD COLUMN amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER adjustment_type',
            'quantity' => 'ALTER TABLE payroll_adjustments ADD COLUMN quantity DECIMAL(15,2) NOT NULL DEFAULT 1.00 AFTER amount',
            'affects_sbi' => 'ALTER TABLE payroll_adjustments ADD COLUMN affects_sbi TINYINT(1) NOT NULL DEFAULT 1 AFTER quantity',
            'affects_sbs' => 'ALTER TABLE payroll_adjustments ADD COLUMN affects_sbs TINYINT(1) NOT NULL DEFAULT 1 AFTER affects_sbi',
            'affects_cnps' => 'ALTER TABLE payroll_adjustments ADD COLUMN affects_cnps TINYINT(1) NOT NULL DEFAULT 1 AFTER affects_sbs',
            'affects_cmu' => 'ALTER TABLE payroll_adjustments ADD COLUMN affects_cmu TINYINT(1) NOT NULL DEFAULT 1 AFTER affects_cnps',
            'status' => "ALTER TABLE payroll_adjustments ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'pending' AFTER affects_cmu",
            'approved_by' => 'ALTER TABLE payroll_adjustments ADD COLUMN approved_by INT NULL AFTER status',
            'approved_at' => 'ALTER TABLE payroll_adjustments ADD COLUMN approved_at DATETIME NULL AFTER approved_by',
            'notes' => 'ALTER TABLE payroll_adjustments ADD COLUMN notes TEXT NULL AFTER approved_at',
            'created_by' => 'ALTER TABLE payroll_adjustments ADD COLUMN created_by INT NULL AFTER notes',
            'created_at' => 'ALTER TABLE payroll_adjustments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER created_by',
            'updated_at' => 'ALTER TABLE payroll_adjustments ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
        ]);
    }

    public function findApprovedByPersonnelAndPeriod(int $personnelId, string $period): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pa.*, psi.code AS salary_item_code, psi.label AS salary_item_label, psi.category
            FROM payroll_adjustments pa
            LEFT JOIN payroll_salary_items psi ON psi.id = pa.payroll_salary_item_id
            WHERE pa.personnel_id = :personnel_id
              AND pa.payroll_period = :period
              AND pa.status = 'approved'
            ORDER BY pa.id ASC
        ");
        $stmt->execute(['personnel_id' => $personnelId, 'period' => $period]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findByPersonnelAndPeriod(int $personnelId, string $period): array
    {
        $stmt = $this->pdo->prepare("
            SELECT pa.*, psi.code AS salary_item_code, psi.label AS salary_item_label, psi.category
            FROM payroll_adjustments pa
            LEFT JOIN payroll_salary_items psi ON psi.id = pa.payroll_salary_item_id
            WHERE pa.personnel_id = :personnel_id
              AND pa.payroll_period = :period
            ORDER BY pa.created_at DESC, pa.id DESC
        ");
        $stmt->execute(['personnel_id' => $personnelId, 'period' => $period]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveAdjustment(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $type = $this->validType((string) ($data['adjustment_type'] ?? 'earning'));
        $payload = [
            'personnel_id' => (int) ($data['personnel_id'] ?? 0),
            'payroll_salary_item_id' => $this->nullableInt($data['payroll_salary_item_id'] ?? null),
            'payroll_period' => $this->validPeriod((string) ($data['payroll_period'] ?? '')),
            'label' => trim((string) ($data['label'] ?? '')),
            'adjustment_type' => $type,
            'amount' => $this->validAmount($data['amount'] ?? 0, $type),
            'quantity' => $this->positiveDecimal($data['quantity'] ?? 1),
            'affects_sbi' => !empty($data['affects_sbi']) ? 1 : 0,
            'affects_sbs' => !empty($data['affects_sbs']) ? 1 : 0,
            'affects_cnps' => !empty($data['affects_cnps']) ? 1 : 0,
            'affects_cmu' => !empty($data['affects_cmu']) ? 1 : 0,
            'status' => $this->validStatus((string) ($data['status'] ?? 'pending')),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'created_by' => $this->nullableInt($data['created_by'] ?? null),
        ];
        if ($payload['personnel_id'] <= 0 || $payload['label'] === '') {
            throw new RuntimeException('Employe et libelle obligatoires.');
        }

        if ($id > 0) {
            $updatePayload = $payload;
            unset($updatePayload['created_by']);
            $stmt = $this->pdo->prepare("
                UPDATE payroll_adjustments
                SET personnel_id = :personnel_id,
                    payroll_salary_item_id = :payroll_salary_item_id,
                    payroll_period = :payroll_period,
                    label = :label,
                    adjustment_type = :adjustment_type,
                    amount = :amount,
                    quantity = :quantity,
                    affects_sbi = :affects_sbi,
                    affects_sbs = :affects_sbs,
                    affects_cnps = :affects_cnps,
                    affects_cmu = :affects_cmu,
                    status = :status,
                    notes = :notes
                WHERE id = :id
            ");
            $stmt->execute($updatePayload + ['id' => $id]);
            return $id;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO payroll_adjustments (
                personnel_id, payroll_salary_item_id, payroll_period, label, adjustment_type,
                amount, quantity, affects_sbi, affects_sbs, affects_cnps, affects_cmu,
                status, notes, created_by
            ) VALUES (
                :personnel_id, :payroll_salary_item_id, :payroll_period, :label, :adjustment_type,
                :amount, :quantity, :affects_sbi, :affects_sbs, :affects_cnps, :affects_cmu,
                :status, :notes, :created_by
            )
        ");
        $stmt->execute($payload);

        return (int) $this->pdo->lastInsertId();
    }

    public function approveAdjustment(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE payroll_adjustments SET status = 'approved', approved_by = :user_id, approved_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function rejectAdjustment(int $id, int $userId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE payroll_adjustments SET status = 'rejected', approved_by = :user_id, approved_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $id, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function deleteAdjustment(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM payroll_adjustments WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    private function validPeriod(string $period): string
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new RuntimeException('Periode invalide.');
        }
        return $period;
    }

    private function validType(string $type): string
    {
        return in_array($type, ['earning', 'deduction', 'reminder', 'regularization', 'advance'], true) ? $type : 'earning';
    }

    private function validStatus(string $status): string
    {
        return in_array($status, ['pending', 'approved', 'rejected'], true) ? $status : 'pending';
    }

    private function validAmount($value, string $type): float
    {
        $amount = (float) str_replace([' ', ','], ['', '.'], (string) $value);
        if ($amount < 0 && $type !== 'deduction') {
            throw new RuntimeException('Montant negatif autorise uniquement pour une retenue.');
        }
        return round(abs($amount), 2);
    }

    private function positiveDecimal($value): float
    {
        $amount = (float) str_replace([' ', ','], ['', '.'], (string) $value);
        if ($amount < 0) {
            throw new RuntimeException('Quantite invalide.');
        }
        return round($amount, 2);
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
            $stmt = $this->pdo->prepare('SHOW COLUMNS FROM payroll_adjustments LIKE :column_name');
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
