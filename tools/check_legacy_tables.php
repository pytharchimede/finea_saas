<?php
require __DIR__ . '/../model/EnvConfig.php';
require __DIR__ . '/../model/Database.php';
require __DIR__ . '/../decaissement/model/DatabaseApp.php';

\EnvConfig::loadFromDirectory(__DIR__);

$tables = ['fiche_expression_besoin', 'besoin_expression', 'expression_besoin_files'];
$projectDbName = \EnvConfig::get('PROJECT_DB_NAME', 'fidestci_projet_db');
$appDbName = \EnvConfig::get('APP_DB_NAME', 'fidestci_app_db');

echo '== Database (expected: ' . $projectDbName . ') ==' . PHP_EOL;
$pdo = \Database::getConnection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
echo 'connected_db:' . $dbName . PHP_EOL;
foreach ($tables as $t) {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$t]);
    $exists = (bool) $stmt->fetchColumn();
    echo $t . ':' . ($exists ? 'YES' : 'NO') . PHP_EOL;
}

echo PHP_EOL . '== DatabaseApp (expected: ' . $appDbName . ') ==' . PHP_EOL;
$pdoApp = \DatabaseApp::getConnection();
$dbName = $pdoApp->query('SELECT DATABASE()')->fetchColumn();
echo 'connected_db:' . $dbName . PHP_EOL;
foreach ($tables as $t) {
    $stmt = $pdoApp->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$t]);
    $exists = (bool) $stmt->fetchColumn();
    echo $t . ':' . ($exists ? 'YES' : 'NO') . PHP_EOL;
}
