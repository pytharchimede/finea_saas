<?php

class PayrollRuleRepository
{
    private PDO $pdo;
    private static bool $schemaEnsured = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSchema();
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function getActiveVersion(string $countryCode, string $period, ?string $code = null): ?array
    {
        $date = $period . '-01';
        $sql = 'SELECT * FROM payroll_rule_versions
            WHERE country_code = :country_code
              AND effective_date <= :date
              AND (end_date IS NULL OR end_date >= :date)';
        if ($code !== null) {
            $sql .= ' AND code = :code';
        } else {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY effective_date DESC, id DESC LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':country_code', $countryCode);
        $stmt->bindValue(':date', $date);
        if ($code !== null) {
            $stmt->bindValue(':code', $code);
        }
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getSalaryItems(int $versionId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM payroll_salary_items WHERE version_id = :version_id';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY category ASC, code ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $items[(string) $row['code']] = $row;
        }

        return $items;
    }

    public function getTaxBrackets(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_tax_brackets WHERE version_id = :version_id ORDER BY min_amount ASC');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getSocialContributions(int $versionId, bool $activeOnly = true): array
    {
        $this->ensureSocialContributionFixedColumns();
        $sql = 'SELECT * FROM payroll_social_contributions WHERE version_id = :version_id';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY code ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getFamilyParts(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_family_parts WHERE version_id = :version_id AND active = 1 ORDER BY situation_code ASC');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getParameters(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT param_key, param_value, value_type FROM payroll_parameters WHERE version_id = :version_id');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        $params = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $value = (string) $row['param_value'];
            $params[(string) $row['param_key']] = $row['value_type'] === 'decimal' ? (float) $value : $value;
        }

        return $params;
    }

    public function getParameterRows(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_parameters WHERE version_id = :version_id ORDER BY param_key ASC');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getTransportRates(int $versionId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM payroll_transport_rates WHERE version_id = :version_id';
        if ($activeOnly) {
            $sql .= ' AND active = 1';
        }
        $sql .= ' ORDER BY locality_code ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listVersions(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM payroll_rule_versions ORDER BY effective_date DESC, id DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getVersionById(int $versionId): ?array
    {
        return $this->findVersion($versionId);
    }

    public function versionHasSlips(int $versionId): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payroll_slips WHERE rule_version_id = :version_id');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    public function saveSalaryItem(array $data): array
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $this->assertEditableVersion($versionId);
        $id = (int) ($data['id'] ?? 0);
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Nom de rubrique obligatoire.');
        }
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateSalaryItemCode($versionId, $label);
        }
        $payload = [
            'version_id' => $versionId,
            'code' => $this->normalizeCode($code),
            'label' => $label,
            'category' => trim((string) ($data['category'] ?? 'gain')),
            'taxable_sbi' => !empty($data['taxable_sbi']) ? 1 : 0,
            'taxable_sbs' => !empty($data['taxable_sbs']) ? 1 : 0,
            'subject_to_cnps' => !empty($data['subject_to_cnps']) ? 1 : 0,
            'subject_to_cmu' => !empty($data['subject_to_cmu']) ? 1 : 0,
            'ceiling_type' => $this->nullableString($data['ceiling_type'] ?? null),
            'ceiling_amount' => $this->nullableDecimal($data['ceiling_amount'] ?? null),
            'exemption_type' => strtoupper(trim((string) ($data['exemption_type'] ?? 'NONE'))),
            'calculation_mode' => trim((string) ($data['calculation_mode'] ?? 'fixed_amount')),
            'active' => !empty($data['active']) ? 1 : 0,
        ];
        if ($id > 0) {
            $before = $this->fetchRow('payroll_salary_items', $id, $versionId);
            $stmt = $this->pdo->prepare('UPDATE payroll_salary_items SET code = :code, label = :label, category = :category, taxable_sbi = :taxable_sbi, taxable_sbs = :taxable_sbs, subject_to_cnps = :subject_to_cnps, subject_to_cmu = :subject_to_cmu, ceiling_type = :ceiling_type, ceiling_amount = :ceiling_amount, exemption_type = :exemption_type, calculation_mode = :calculation_mode, active = :active WHERE id = :id AND version_id = :version_id');
            $stmt->execute($payload + ['id' => $id]);
        } else {
            $before = null;
            $stmt = $this->pdo->prepare('INSERT INTO payroll_salary_items (version_id, code, label, category, taxable_sbi, taxable_sbs, subject_to_cnps, subject_to_cmu, ceiling_type, ceiling_amount, exemption_type, calculation_mode, active) VALUES (:version_id, :code, :label, :category, :taxable_sbi, :taxable_sbs, :subject_to_cnps, :subject_to_cmu, :ceiling_type, :ceiling_amount, :exemption_type, :calculation_mode, :active)');
            $stmt->execute($payload);
            $id = (int) $this->pdo->lastInsertId();
        }

        return ['before' => $before, 'after' => $this->fetchRow('payroll_salary_items', $id, $versionId)];
    }

    public function saveTaxBracket(array $data): array
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $this->assertEditableVersion($versionId);
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            'version_id' => $versionId,
            'min_amount' => $this->positiveDecimal($data['min_amount'] ?? 0),
            'max_amount' => $this->nullableDecimal($data['max_amount'] ?? null),
            'rate' => $this->positiveDecimal($data['rate'] ?? 0),
        ];
        if ($payload['max_amount'] !== null && $payload['max_amount'] <= $payload['min_amount']) {
            throw new RuntimeException('Le maximum de tranche doit etre superieur au minimum.');
        }

        if ($id > 0) {
            $before = $this->fetchRow('payroll_tax_brackets', $id, $versionId);
            $stmt = $this->pdo->prepare('UPDATE payroll_tax_brackets SET min_amount = :min_amount, max_amount = :max_amount, rate = :rate WHERE id = :id AND version_id = :version_id');
            $stmt->execute($payload + ['id' => $id]);
        } else {
            $before = null;
            $stmt = $this->pdo->prepare('INSERT INTO payroll_tax_brackets (version_id, min_amount, max_amount, rate) VALUES (:version_id, :min_amount, :max_amount, :rate)');
            $stmt->execute($payload);
            $id = (int) $this->pdo->lastInsertId();
        }

        return ['before' => $before, 'after' => $this->fetchRow('payroll_tax_brackets', $id, $versionId)];
    }

    public function saveSocialContribution(array $data): array
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $this->assertEditableVersion($versionId);
        $id = (int) ($data['id'] ?? 0);
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Libelle cotisation obligatoire.');
        }
        $code = trim((string) ($data['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateUniqueCode($versionId, 'payroll_social_contributions', 'code', $label, 'COTISATION');
        }
        $payload = [
            'version_id' => $versionId,
            'code' => $this->normalizeCode($code),
            'label' => $label,
            'employee_rate' => $this->positiveDecimal($data['employee_rate'] ?? 0),
            'employer_rate' => $this->positiveDecimal($data['employer_rate'] ?? 0),
            'employee_fixed_amount' => $this->positiveDecimal($data['employee_fixed_amount'] ?? 0),
            'employer_fixed_amount' => $this->positiveDecimal($data['employer_fixed_amount'] ?? 0),
            'ceiling' => $this->nullableDecimal($data['ceiling'] ?? null),
            'calculation_base' => strtoupper(trim((string) ($data['calculation_base'] ?? 'SBS'))),
            'active' => !empty($data['active']) ? 1 : 0,
        ];

        if ($id > 0) {
            $this->ensureSocialContributionFixedColumns();
            $before = $this->fetchRow('payroll_social_contributions', $id, $versionId);
            $stmt = $this->pdo->prepare('UPDATE payroll_social_contributions SET code = :code, label = :label, employee_rate = :employee_rate, employer_rate = :employer_rate, employee_fixed_amount = :employee_fixed_amount, employer_fixed_amount = :employer_fixed_amount, ceiling = :ceiling, calculation_base = :calculation_base, active = :active WHERE id = :id AND version_id = :version_id');
            $stmt->execute($payload + ['id' => $id]);
        } else {
            $this->ensureSocialContributionFixedColumns();
            $before = null;
            $stmt = $this->pdo->prepare('INSERT INTO payroll_social_contributions (version_id, code, label, employee_rate, employer_rate, employee_fixed_amount, employer_fixed_amount, ceiling, calculation_base, active) VALUES (:version_id, :code, :label, :employee_rate, :employer_rate, :employee_fixed_amount, :employer_fixed_amount, :ceiling, :calculation_base, :active)');
            $stmt->execute($payload);
            $id = (int) $this->pdo->lastInsertId();
        }

        return ['before' => $before, 'after' => $this->fetchRow('payroll_social_contributions', $id, $versionId)];
    }

    public function saveTransportRate(array $data): array
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $this->assertEditableVersion($versionId);
        $id = (int) ($data['id'] ?? 0);
        $label = trim((string) ($data['locality_label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Libelle localite obligatoire.');
        }
        $localityCode = trim((string) ($data['locality_code'] ?? ''));
        if ($localityCode === '') {
            $localityCode = $this->generateUniqueCode($versionId, 'payroll_transport_rates', 'locality_code', $label, 'LOCALITE');
        }
        $payload = [
            'version_id' => $versionId,
            'locality_code' => $this->normalizeCode($localityCode),
            'locality_label' => $label,
            'monthly_amount' => $this->positiveDecimal($data['monthly_amount'] ?? 0),
            'active' => !empty($data['active']) ? 1 : 0,
        ];

        if ($id > 0) {
            $before = $this->fetchRow('payroll_transport_rates', $id, $versionId);
            $stmt = $this->pdo->prepare('UPDATE payroll_transport_rates SET locality_code = :locality_code, locality_label = :locality_label, monthly_amount = :monthly_amount, active = :active WHERE id = :id AND version_id = :version_id');
            $stmt->execute($payload + ['id' => $id]);
        } else {
            $before = null;
            $stmt = $this->pdo->prepare('INSERT INTO payroll_transport_rates (version_id, locality_code, locality_label, monthly_amount, active) VALUES (:version_id, :locality_code, :locality_label, :monthly_amount, :active)');
            $stmt->execute($payload);
            $id = (int) $this->pdo->lastInsertId();
        }

        return ['before' => $before, 'after' => $this->fetchRow('payroll_transport_rates', $id, $versionId)];
    }

    public function saveParameter(array $data): array
    {
        $versionId = (int) ($data['version_id'] ?? 0);
        $this->assertEditableVersion($versionId);
        $id = (int) ($data['id'] ?? 0);
        $payload = [
            'version_id' => $versionId,
            'param_key' => $this->normalizeCode($data['param_key'] ?? ''),
            'param_value' => trim((string) ($data['param_value'] ?? '')),
            'value_type' => trim((string) ($data['value_type'] ?? 'decimal')),
            'description' => $this->nullableString($data['description'] ?? null),
        ];
        if ($payload['param_value'] === '') {
            throw new RuntimeException('Valeur de parametre obligatoire.');
        }

        if ($id > 0) {
            $before = $this->fetchRow('payroll_parameters', $id, $versionId);
            $stmt = $this->pdo->prepare('UPDATE payroll_parameters SET param_key = :param_key, param_value = :param_value, value_type = :value_type, description = :description WHERE id = :id AND version_id = :version_id');
            $stmt->execute($payload + ['id' => $id]);
        } else {
            $before = null;
            $stmt = $this->pdo->prepare('INSERT INTO payroll_parameters (version_id, param_key, param_value, value_type, description) VALUES (:version_id, :param_key, :param_value, :value_type, :description)');
            $stmt->execute($payload);
            $id = (int) $this->pdo->lastInsertId();
        }

        return ['before' => $before, 'after' => $this->fetchRow('payroll_parameters', $id, $versionId)];
    }

    public function cloneVersion(int $sourceVersionId, string $code, string $label, string $effectiveDate, ?string $endDate, bool $active): int
    {
        if ($sourceVersionId <= 0) {
            throw new RuntimeException('Version source introuvable.');
        }
        if (!preg_match('/^[A-Z0-9_\\-]{4,80}$/', $code)) {
            throw new RuntimeException('Code version invalide.');
        }
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $effectiveDate)) {
            throw new RuntimeException('Date d effet invalide.');
        }
        if ($endDate !== null && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $endDate)) {
            throw new RuntimeException('Date de fin invalide.');
        }

        $source = $this->findVersion($sourceVersionId);
        if ($source === null) {
            throw new RuntimeException('Version source introuvable.');
        }

        $started = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $started = true;
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO payroll_rule_versions (code, label, country_code, effective_date, end_date, active, created_at) VALUES (:code, :label, :country_code, :effective_date, :end_date, :active, NOW())');
            $stmt->execute([
                'code' => $code,
                'label' => $label,
                'country_code' => $source['country_code'],
                'effective_date' => $effectiveDate,
                'end_date' => $endDate,
                'active' => $active ? 1 : 0,
            ]);
            $newVersionId = (int) $this->pdo->lastInsertId();

            $this->ensureSocialContributionFixedColumns();
            foreach ([
                'payroll_salary_items' => 'code, label, category, taxable_sbi, taxable_sbs, subject_to_cnps, subject_to_cmu, ceiling_type, ceiling_amount, exemption_type, calculation_mode, active',
                'payroll_tax_brackets' => 'min_amount, max_amount, rate',
                'payroll_family_parts' => 'situation_code, label, base_parts, child_part, disabled_child_extra_part, max_parts, active',
                'payroll_social_contributions' => 'code, label, employee_rate, employer_rate, employee_fixed_amount, employer_fixed_amount, ceiling, calculation_base, active',
                'payroll_transport_rates' => 'locality_code, locality_label, monthly_amount, active',
                'payroll_parameters' => 'param_key, param_value, value_type, description',
            ] as $table => $columns) {
                $this->pdo->prepare("INSERT INTO {$table} (version_id, {$columns}) SELECT :new_version_id, {$columns} FROM {$table} WHERE version_id = :source_version_id")
                    ->execute(['new_version_id' => $newVersionId, 'source_version_id' => $sourceVersionId]);
            }

            if ($started) {
                $this->pdo->commit();
            }

            return $newVersionId;
        } catch (Throwable $exception) {
            if ($started && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function findVersion(int $versionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payroll_rule_versions WHERE id = :id');
        $stmt->bindValue(':id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }

        if ($this->schemaTablesExist()) {
            $this->ensureSchemaColumns();
            self::$schemaEnsured = true;
            return;
        }

        $migrationPath = dirname(__DIR__) . '/migrations/2026_05_05_create_payroll_engine_ci.sql';
        if (!is_file($migrationPath)) {
            throw new RuntimeException('Migration du moteur de paie introuvable.');
        }

        $sql = (string) file_get_contents($migrationPath);
        foreach ($this->splitSqlStatements($sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }
            try {
                $this->executeSchemaStatement($statement);
            } catch (PDOException $exception) {
                $this->ensureSchemaColumns();
                $this->executeSchemaStatement($statement);
            }
        }

        $this->ensureSchemaColumns();
        self::$schemaEnsured = true;
    }

    private function splitSqlStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';
            $buffer .= $char;

            if ($quote !== null) {
                if ($char === '\\') {
                    if ($next !== '') {
                        $buffer .= $next;
                        $index++;
                    }
                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ';') {
                $statements[] = substr($buffer, 0, -1);
                $buffer = '';
            }
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function executeSchemaStatement(string $statement): void
    {
        $stmt = $this->pdo->query($statement);
        if ($stmt instanceof PDOStatement) {
            $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        }
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
        $stmt->bindValue(':table_name', $table);
        $stmt->execute();
        $exists = (bool) $stmt->fetch(PDO::FETCH_NUM);
        $stmt->closeCursor();

        return $exists;
    }

    private function schemaTablesExist(): bool
    {
        foreach ([
            'payroll_rule_versions',
            'payroll_salary_items',
            'payroll_tax_brackets',
            'payroll_family_parts',
            'payroll_social_contributions',
            'payroll_transport_rates',
            'payroll_parameters',
            'payroll_slips',
            'payroll_calculation_snapshots',
            'payroll_period_locks',
        ] as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    private function ensureSchemaColumns(): void
    {
        $definitions = [
            'payroll_salary_items' => [
                'subject_to_cmu' => 'ALTER TABLE payroll_salary_items ADD COLUMN subject_to_cmu TINYINT(1) NOT NULL DEFAULT 0 AFTER subject_to_cnps',
                'ceiling_type' => 'ALTER TABLE payroll_salary_items ADD COLUMN ceiling_type VARCHAR(60) DEFAULT NULL AFTER subject_to_cmu',
                'ceiling_amount' => 'ALTER TABLE payroll_salary_items ADD COLUMN ceiling_amount DECIMAL(14,2) DEFAULT NULL AFTER ceiling_type',
                'exemption_type' => "ALTER TABLE payroll_salary_items ADD COLUMN exemption_type VARCHAR(80) NOT NULL DEFAULT 'NONE' AFTER ceiling_amount",
                'calculation_mode' => "ALTER TABLE payroll_salary_items ADD COLUMN calculation_mode VARCHAR(60) NOT NULL DEFAULT 'fixed_amount' AFTER exemption_type",
                'updated_at' => 'ALTER TABLE payroll_salary_items ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            ],
            'payroll_social_contributions' => [
                'employee_fixed_amount' => 'ALTER TABLE payroll_social_contributions ADD COLUMN employee_fixed_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employer_rate',
                'employer_fixed_amount' => 'ALTER TABLE payroll_social_contributions ADD COLUMN employer_fixed_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employee_fixed_amount',
            ],
            'payroll_transport_rates' => [
                'updated_at' => 'ALTER TABLE payroll_transport_rates ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            ],
        ];

        foreach ($definitions as $table => $columns) {
            if (!$this->tableExists($table)) {
                continue;
            }
            foreach ($columns as $column => $alterSql) {
                $this->ensureColumn($table, $column, $alterSql);
            }
        }

        $this->ensureSchemaIndexes();
        $this->backfillSchemaDefaults();
    }

    private function ensureColumn(string $table, string $column, string $alterSql): void
    {
        $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column_name");
        $stmt->bindValue(':column_name', $column);
        $stmt->execute();
        $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$exists) {
            $this->pdo->exec($alterSql);
        }
    }

    private function ensureSchemaIndexes(): void
    {
        $indexes = [
            ['payroll_tax_brackets', 'uniq_payroll_tax_bracket_version_min', 'ALTER TABLE payroll_tax_brackets ADD UNIQUE KEY uniq_payroll_tax_bracket_version_min (version_id, min_amount)'],
            ['payroll_period_locks', 'uniq_payroll_period_lock', 'ALTER TABLE payroll_period_locks ADD UNIQUE KEY uniq_payroll_period_lock (country_code, payroll_period)'],
        ];

        foreach ($indexes as [$table, $index, $alterSql]) {
            if (!$this->tableExists($table) || $this->indexExists($table, $index)) {
                continue;
            }
            $this->pdo->exec($alterSql);
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table_name AND index_name = :index_name');
        $stmt->bindValue(':table_name', $table);
        $stmt->bindValue(':index_name', $index);
        $stmt->execute();
        $exists = (int) $stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $exists;
    }

    private function backfillSchemaDefaults(): void
    {
        $this->ensureSocialContributionFixedColumns();
    }

    private function assertEditableVersion(int $versionId): void
    {
        if ($versionId <= 0 || $this->findVersion($versionId) === null) {
            throw new RuntimeException('Version reglementaire introuvable.');
        }
        if ($this->versionHasSlips($versionId)) {
            throw new RuntimeException('Cette version a deja servi a generer des bulletins. Clonez-la avant modification.');
        }
    }

    private function fetchRow(string $table, int $id, int $versionId): ?array
    {
        if ($table === 'payroll_social_contributions') {
            $this->ensureSocialContributionFixedColumns();
        }
        $allowed = [
            'payroll_salary_items',
            'payroll_tax_brackets',
            'payroll_social_contributions',
            'payroll_transport_rates',
            'payroll_parameters',
        ];
        if (!in_array($table, $allowed, true)) {
            throw new RuntimeException('Table de parametrage invalide.');
        }

        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE id = :id AND version_id = :version_id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function ensureSocialContributionFixedColumns(): void
    {
        $stmt = $this->pdo->query("SHOW TABLES LIKE 'payroll_social_contributions'");
        $tableExists = $stmt instanceof PDOStatement && (bool) $stmt->fetch(PDO::FETCH_NUM);
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
        }
        if (!$tableExists) {
            return;
        }

        foreach ([
            'employee_fixed_amount' => 'ALTER TABLE payroll_social_contributions ADD COLUMN employee_fixed_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employer_rate',
            'employer_fixed_amount' => 'ALTER TABLE payroll_social_contributions ADD COLUMN employer_fixed_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER employee_fixed_amount',
        ] as $column => $alterSql) {
            $columnStmt = $this->pdo->prepare('SHOW COLUMNS FROM payroll_social_contributions LIKE :column_name');
            $columnStmt->bindValue(':column_name', $column);
            $columnStmt->execute();
            $exists = (bool) $columnStmt->fetch(PDO::FETCH_ASSOC);
            $columnStmt->closeCursor();
            if (!$exists) {
                $this->pdo->exec($alterSql);
            }
        }

        $this->pdo->exec("
            UPDATE payroll_social_contributions
            SET employee_fixed_amount = 500.00,
                employer_fixed_amount = 500.00
            WHERE code = 'CMU'
              AND employee_rate = 0.00
              AND employer_rate = 0.00
              AND employee_fixed_amount = 0.00
              AND employer_fixed_amount = 0.00
        ");
    }

    private function normalizeCode($value): string
    {
        $code = strtoupper(trim((string) $value));
        if (!preg_match('/^[A-Z0-9_\\-]{2,100}$/', $code)) {
            throw new RuntimeException('Code invalide: ' . $code);
        }

        return $code;
    }

    private function generateSalaryItemCode(int $versionId, string $label): string
    {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
        $base = strtoupper($base);
        $base = preg_replace('/[^A-Z0-9]+/', '_', $base) ?: 'RUBRIQUE';
        $base = trim($base, '_');
        if ($base === '') {
            $base = 'RUBRIQUE';
        }
        if (!preg_match('/^[A-Z]/', $base)) {
            $base = 'RUBRIQUE_' . $base;
        }
        $base = substr($base, 0, 80);

        $candidate = $base;
        $suffix = 2;
        while ($this->salaryItemCodeExists($versionId, $candidate)) {
            $suffixText = '_' . $suffix;
            $candidate = substr($base, 0, 100 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function salaryItemCodeExists(int $versionId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM payroll_salary_items WHERE version_id = :version_id AND code = :code');
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->bindValue(':code', $code);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function generateUniqueCode(int $versionId, string $table, string $column, string $label, string $fallback): string
    {
        $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label;
        $base = strtoupper($base);
        $base = preg_replace('/[^A-Z0-9]+/', '_', $base) ?: $fallback;
        $base = trim($base, '_');
        if ($base === '') {
            $base = $fallback;
        }
        if (!preg_match('/^[A-Z]/', $base)) {
            $base = $fallback . '_' . $base;
        }
        $base = substr($base, 0, 80);

        $candidate = $base;
        $suffix = 2;
        while ($this->codeExists($versionId, $table, $column, $candidate)) {
            $suffixText = '_' . $suffix;
            $candidate = substr($base, 0, 100 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function codeExists(int $versionId, string $table, string $column, string $code): bool
    {
        $allowed = [
            'payroll_social_contributions' => ['code'],
            'payroll_transport_rates' => ['locality_code'],
        ];
        if (!isset($allowed[$table]) || !in_array($column, $allowed[$table], true)) {
            throw new RuntimeException('Reference interne invalide.');
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE version_id = :version_id AND {$column} = :code");
        $stmt->bindValue(':version_id', $versionId, PDO::PARAM_INT);
        $stmt->bindValue(':code', $code);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    private function nullableString($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function nullableDecimal($value): ?float
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        return $this->positiveDecimal($value);
    }

    private function positiveDecimal($value): float
    {
        $value = str_replace([' ', ','], ['', '.'], (string) $value);
        $amount = (float) $value;
        if ($amount < 0) {
            throw new RuntimeException('Les montants et taux doivent etre positifs.');
        }

        return round($amount, 4);
    }
}
