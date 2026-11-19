<?php

final class FebCategoryMigrationService
{
    public const CATEGORIES = ['RH', 'Logistique', 'HSE', 'ACHAT', 'Bureau'];
    private const VERSION = 'feb_categories_20260716_v1';

    public static function normalize(string $value): string
    {
        $raw = trim($value);
        if (in_array($raw, self::CATEGORIES, true)) {
            return $raw;
        }
        $key = mb_strtolower($raw, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key);
        $key = preg_replace('/[^a-z0-9]+/', ' ', strtolower($ascii !== false ? $ascii : $key)) ?? '';

        if (preg_match('/\b(hse|hygiene|securite|environnement|epi|protection)\b/', $key)) return 'HSE';
        if (preg_match('/\b(rh|ressources? humaines?|personnel|salaire|paie|recrutement|formation)\b/', $key)) return 'RH';
        if (preg_match('/\b(logistique|transport|carburant|vehicule|livraison|magasin|stock|manutention|main d oeuvre|travaux|maintenance|prestation)\b/', $key)) return 'Logistique';
        if (preg_match('/\b(achat|materiel|materiaux|fourniture|equipement|outillage|consommable|approvisionnement)\b/', $key)) return 'ACHAT';
        if (preg_match('/\b(bureau|administration|administratif|informatique|communication|commercial|comptabilite|facturation)\b/', $key)) return 'Bureau';
        return 'Bureau';
    }

    public static function normalizePayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = self::normalizePayload($value);
            } elseif (is_string($value) && in_array(mb_strtolower((string)$key, 'UTF-8'), ['categorie', 'category'], true)) {
                $payload[$key] = self::normalize($value);
            }
        }
        // Les besoins ordinaires héritent du planning global. Les lignes proposées
        // pour une intégration budgétaire peuvent toutefois porter un sous-planning
        // propre, contrôlé comme inclus dans la période globale du déboursé.
        foreach ((array)($payload['besoins'] ?? []) as $index => $line) {
            if (!is_array($line)) continue;
            unset($line['date_debut'], $line['date_fin'], $line['planned_start_date'], $line['planned_end_date']);
            $payload['besoins'][$index] = $line;
        }
        if (isset($payload['provisional_decaissement']) && is_array($payload['provisional_decaissement'])) {
            unset($payload['provisional_decaissement']['planned_start_date'], $payload['provisional_decaissement']['planned_end_date']);
        }
        return $payload;
    }

    public static function ensure(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS app_migration_versions (migration_key VARCHAR(120) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $check = $pdo->prepare('SELECT 1 FROM app_migration_versions WHERE migration_key=:migration LIMIT 1');
        $check->execute(['migration' => self::VERSION]);
        if ($check->fetchColumn()) return;

        self::ensureCategoryColumns($pdo);

        $pdo->beginTransaction();
        try {
            self::normalizeColumn($pdo, 'ligne_debourse_banamur', 'id', 'categorie');
            self::normalizeColumn($pdo, 'feb_quote_lines', 'id', 'category');
            self::normalizeColumn($pdo, 'feb_price_challenges', 'id', 'category');
            self::normalizeSettings($pdo);
            self::normalizeFebPayloads($pdo);
            $pdo->commit();
            self::finalizeCategoryColumns($pdo);
            $pdo->prepare('INSERT IGNORE INTO app_migration_versions(migration_key) VALUES(:migration)')->execute(['migration' => self::VERSION]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function ensureCategoryColumns(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'ligne_debourse_banamur')) return;
        $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ligne_debourse_banamur" AND COLUMN_NAME="categorie" LIMIT 1');
        $stmt->execute();
        $type = strtolower((string)($stmt->fetchColumn() ?: ''));
        if (!str_starts_with($type, 'varchar')) {
            $pdo->exec("ALTER TABLE ligne_debourse_banamur MODIFY categorie VARCHAR(40) NOT NULL DEFAULT 'Bureau'");
        }
    }

    private static function finalizeCategoryColumns(PDO $pdo): void
    {
        if (self::tableExists($pdo, 'ligne_debourse_banamur')) {
            $pdo->exec("ALTER TABLE ligne_debourse_banamur MODIFY categorie ENUM('RH','Logistique','HSE','ACHAT','Bureau') NOT NULL DEFAULT 'Bureau'");
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table');
        $stmt->execute(['table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function normalizeColumn(PDO $pdo, string $table, string $idColumn, string $categoryColumn): void
    {
        if (!self::tableExists($pdo, $table)) return;
        $rows = $pdo->query("SELECT `$idColumn` id, `$categoryColumn` category FROM `$table`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $update = $pdo->prepare("UPDATE `$table` SET `$categoryColumn`=:category WHERE `$idColumn`=:id");
        foreach ($rows as $row) {
            $normalized = self::normalize((string)($row['category'] ?? ''));
            if ($normalized !== (string)($row['category'] ?? '')) $update->execute(['category' => $normalized, 'id' => $row['id']]);
        }
    }

    private static function normalizeSettings(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'feb_price_challenger_settings')) return;
        $rows = $pdo->query('SELECT * FROM feb_price_challenger_settings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $find = $pdo->prepare('SELECT id,active FROM feb_price_challenger_settings WHERE category=:category AND service_id=:service AND user_id=:user LIMIT 1');
        $update = $pdo->prepare('UPDATE feb_price_challenger_settings SET category=:category WHERE id=:id');
        $merge = $pdo->prepare('UPDATE feb_price_challenger_settings SET active=GREATEST(active,:active) WHERE id=:id');
        $delete = $pdo->prepare('DELETE FROM feb_price_challenger_settings WHERE id=:id');
        foreach ($rows as $row) {
            $category = self::normalize((string)($row['category'] ?? ''));
            if ($category === (string)$row['category']) continue;
            $find->execute(['category' => $category, 'service' => (int)$row['service_id'], 'user' => (int)$row['user_id']]);
            $existing = $find->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existing && (int)$existing['id'] !== (int)$row['id']) {
                $merge->execute(['active' => (int)$row['active'], 'id' => (int)$existing['id']]);
                $delete->execute(['id' => (int)$row['id']]);
            } else {
                $update->execute(['category' => $category, 'id' => (int)$row['id']]);
            }
        }
    }

    private static function normalizeFebPayloads(PDO $pdo): void
    {
        if (!self::tableExists($pdo, 'employee_legal_requests')) return;
        $rows = $pdo->query('SELECT id,payload_json FROM employee_legal_requests WHERE request_type="feb"')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $update = $pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload WHERE id=:id');
        foreach ($rows as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) continue;
            $normalized = self::normalizePayload($payload);
            if ($normalized !== $payload) $update->execute(['payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => (int)$row['id']]);
        }
    }
}
