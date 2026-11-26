<?php
declare(strict_types=1);
require_once __DIR__.'/../model/StandardUnitSeeder.php';
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE unites_mesure(id INTEGER PRIMARY KEY,symbole TEXT UNIQUE,libelle TEXT)');
$pdo->exec("INSERT INTO unites_mesure(symbole,libelle) VALUES ('m','Libellé personnalisé')");
$pdo->beginTransaction();
$a=StandardUnitSeeder::seed($pdo);$b=StandardUnitSeeder::seed($pdo);
if($a['added']!==count(StandardUnitSeeder::UNITS)-1||$b['added']!==0||$pdo->query("SELECT libelle FROM unites_mesure WHERE symbole='m'")->fetchColumn()!=='Libellé personnalisé')throw new RuntimeException('Échec du seed idempotent.');
$pdo->rollBack();
if((int)$pdo->query('SELECT COUNT(*) FROM unites_mesure')->fetchColumn()!==1)throw new RuntimeException('Échec rollback.');
echo "StandardUnitSeederTest OK\n";
