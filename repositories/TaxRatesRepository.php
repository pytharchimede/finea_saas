<?php

require_once __DIR__ . '/../model/Database.php';

class TaxRatesRepository
{
    public const TABLE = 'tax_rates_banamur';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function ensureTable(array $defaults = []): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS " . self::TABLE . " (\n"
            . "  tax_code VARCHAR(16) NOT NULL,\n"
            . "  rate DECIMAL(10,6) NOT NULL DEFAULT 0,\n"
            . "  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
            . "  PRIMARY KEY (tax_code)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        if (!$defaults) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (tax_code, rate) VALUES (:code, :rate)\n"
            . "ON DUPLICATE KEY UPDATE tax_code = tax_code"
        );

        foreach ($defaults as $code => $rate) {
            $code = strtoupper(trim((string)$code));
            if ($code === '') {
                continue;
            }
            $stmt->execute(['code' => $code, 'rate' => (float)$rate]);
        }
    }

    public function getRates(array $defaults = []): array
    {
        $this->ensureTable($defaults);

        $rows = $this->pdo->query("SELECT tax_code, rate FROM " . self::TABLE)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $dbRates = [];
        foreach ($rows as $row) {
            $code = strtoupper(trim((string)($row['tax_code'] ?? '')));
            if ($code !== '') {
                $dbRates[$code] = (float)($row['rate'] ?? 0);
            }
        }

        if (!$defaults) {
            return $dbRates;
        }

        return array_merge(array_map(static fn($value) => (float)$value, $defaults), $dbRates);
    }

    public function setRates(array $rates): void
    {
        $this->ensureTable();

        $stmt = $this->pdo->prepare(
            "INSERT INTO " . self::TABLE . " (tax_code, rate) VALUES (:code, :rate)\n"
            . "ON DUPLICATE KEY UPDATE rate = VALUES(rate)"
        );

        foreach ($rates as $code => $rate) {
            $code = strtoupper(trim((string)$code));
            if ($code === '') {
                continue;
            }
            $stmt->execute(['code' => $code, 'rate' => (float)$rate]);
        }
    }
}
