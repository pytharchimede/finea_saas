<?php
require __DIR__ . '/../model/Database.php';
require __DIR__ . '/../decaissement/model/DatabaseApp.php';

function describeTables(PDO $pdo, string $label, array $tables): void
{
    echo "== {$label} ==" . PHP_EOL;
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo 'connected_db:' . $dbName . PHP_EOL;

    foreach ($tables as $t) {
        echo PHP_EOL . "-- {$t} --" . PHP_EOL;
        try {
            $stmt = $pdo->query('DESCRIBE ' . $t);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $null = $r['Null'] ?? '';
                $key = $r['Key'] ?? '';
                $def = array_key_exists('Default', $r) ? $r['Default'] : '';
                $extra = $r['Extra'] ?? '';
                echo sprintf(
                    "%s\t%s\tNULL:%s\tKEY:%s\tDEFAULT:%s\tEXTRA:%s\n",
                    $r['Field'] ?? '',
                    $r['Type'] ?? '',
                    $null,
                    $key,
                    (string) ($def === null ? 'NULL' : $def),
                    $extra
                );
            }
        } catch (Throwable $e) {
            echo 'ERROR:' . $e->getMessage() . PHP_EOL;
        }
    }
    echo PHP_EOL;
}

$tables = ['fiche_expression_besoin', 'besoin_expression', 'expression_besoin_files'];

describeTables(Database::getConnection(), 'Database (projet)', $tables);
describeTables(DatabaseApp::getConnection(), 'DatabaseApp (app)', $tables);
