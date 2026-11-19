<?php

class PointFinancierSchema
{
    /**
     * @return array<int, array{table:string,index:string,columns:string[]}>
     */
    private static function buildTargets(PDO $pdo): array
    {
        $chantierTable = self::detectChantierTable($pdo);

        $targets = [
            // fiche_decaissement
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_chantier_decaisse', 'columns' => ['chantier_id', 'decaisse']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_ligne_debourse', 'columns' => ['ligne_debourse_id']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_decaisse_date', 'columns' => ['decaisse', 'date_decaisse']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_approuve_conforme', 'columns' => ['approuve', 'conforme', 'etat_fiche']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_date_creat', 'columns' => ['date_creat_fiche']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_date_refuse', 'columns' => ['date_refuse']],
            ['table' => 'fiche_decaissement', 'index' => 'idx_fd_date_valid', 'columns' => ['date_valid']],

            // ligne_debourse_banamur
            ['table' => 'ligne_debourse_banamur', 'index' => 'idx_ldb_debourse', 'columns' => ['debourse_id']],

            // debourse_banamur
            ['table' => 'debourse_banamur', 'index' => 'idx_db_devis', 'columns' => ['devis_id']],
        ];

        if ($chantierTable) {
            $targets[] = ['table' => $chantierTable, 'index' => 'idx_chantier_devis', 'columns' => ['devis_id']];
            $targets[] = ['table' => $chantierTable, 'index' => 'idx_chantier_code', 'columns' => ['code']];
        }

        return $targets;
    }

    /**
     * @return array<int, array{table:string,index:string,columns:string[],status:string,message?:string}>
     */
    private static function audit(PDO $pdo, bool $apply): array
    {
        $targets = self::buildTargets($pdo);

        $perTableCols = [];
        $perTableIndexes = [];

        $results = [];
        foreach ($targets as $t) {
            $table = (string)$t['table'];
            $index = (string)$t['index'];
            $cols = $t['columns'];

            try {
                if (!isset($perTableCols[$table])) {
                    $perTableCols[$table] = self::getTableColumns($pdo, $table);
                }
                $existingColsLower = $perTableCols[$table];
                $missing = [];
                foreach ($cols as $c) {
                    if (!in_array(strtolower($c), $existingColsLower, true)) {
                        $missing[] = $c;
                    }
                }
                if ($missing) {
                    $results[] = [
                        'table' => $table,
                        'index' => $index,
                        'columns' => $cols,
                        'status' => 'skipped',
                        'message' => 'Colonnes manquantes: ' . implode(', ', $missing),
                    ];
                    continue;
                }

                if (!isset($perTableIndexes[$table])) {
                    $perTableIndexes[$table] = self::getIndexes($pdo, $table);
                }

                // Si l'index existe déjà (par nom) ou si un index existant couvre les colonnes
                $indexes = $perTableIndexes[$table];
                $already = false;
                $coveredBy = null;
                foreach ($indexes as $idxName => $idxCols) {
                    if (strcasecmp($idxName, $index) === 0) {
                        $already = true;
                        $coveredBy = $idxName;
                        break;
                    }
                    if (self::indexCovers($idxCols, $cols)) {
                        $already = true;
                        $coveredBy = $idxName;
                        break;
                    }
                }

                if ($already) {
                    $results[] = [
                        'table' => $table,
                        'index' => $index,
                        'columns' => $cols,
                        'status' => 'exists',
                        'message' => $coveredBy && $coveredBy !== $index ? ("Couvert par index existant: {$coveredBy}") : 'Déjà présent',
                    ];
                    continue;
                }

                if (!$apply) {
                    $results[] = [
                        'table' => $table,
                        'index' => $index,
                        'columns' => $cols,
                        'status' => 'missing',
                        'message' => 'Index manquant',
                    ];
                    continue;
                }

                $colsSql = implode(', ', array_map(static fn($c) => "`{$c}`", $cols));
                $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` ({$colsSql})");

                // Refresh cache pour cette table (évite ré-adding)
                unset($perTableIndexes[$table]);

                $results[] = [
                    'table' => $table,
                    'index' => $index,
                    'columns' => $cols,
                    'status' => 'added',
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'table' => $table,
                    'index' => $index,
                    'columns' => $cols,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    private static function detectChantierTable(PDO $pdo): ?string
    {
        foreach (['chantier_banamur', 'chantioer_banamur'] as $table) {
            try {
                $pdo->query("SELECT 1 FROM {$table} LIMIT 1");
                return $table;
            } catch (Throwable $e) {
                // ignore
            }
        }
        return null;
    }

    private static function getTableColumns(PDO $pdo, string $table): array
    {
        $st = $pdo->query("SHOW COLUMNS FROM {$table}");
        $cols = $st->fetchAll(PDO::FETCH_COLUMN, 0);
        return array_map('strtolower', $cols ?: []);
    }

    /**
     * @return array<string, string[]> indexName => [col1, col2, ...]
     */
    private static function getIndexes(PDO $pdo, string $table): array
    {
        $st = $pdo->prepare(
            "SELECT INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
             ORDER BY INDEX_NAME ASC, SEQ_IN_INDEX ASC"
        );
        $st->execute(['t' => $table]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $indexes = [];
        foreach ($rows as $row) {
            $name = (string)($row['INDEX_NAME'] ?? '');
            $col = (string)($row['COLUMN_NAME'] ?? '');
            if ($name === '' || $col === '') {
                continue;
            }
            if (!isset($indexes[$name])) {
                $indexes[$name] = [];
            }
            $indexes[$name][] = strtolower($col);
        }
        return $indexes;
    }

    private static function indexCovers(array $existingCols, array $desiredCols): bool
    {
        $desiredLower = array_map('strtolower', $desiredCols);
        if (count($existingCols) < count($desiredLower)) {
            return false;
        }
        return array_slice($existingCols, 0, count($desiredLower)) === $desiredLower;
    }

    /**
     * Ajoute (best-effort) les index nécessaires au Point financier.
     * Ne casse pas si l'index existe déjà : ignore / journalise.
     *
     * @return array<int, array{table:string,index:string,columns:string[],status:string,message?:string}>
     */
    public static function ensureIndexes(PDO $pdo): array
    {
        return self::audit($pdo, true);
    }

    /**
     * Vérifie (sans modifier) si les index recommandés sont en place.
     *
     * @return array<int, array{table:string,index:string,columns:string[],status:string,message?:string}>
     */
    public static function checkIndexes(PDO $pdo): array
    {
        return self::audit($pdo, false);
    }
}
