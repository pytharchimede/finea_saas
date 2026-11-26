<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__,2).'/model/Database.php';
require_once dirname(__DIR__,2).'/model/UnitDescriptionMigration.php';
if($argc!==2||$argv[1]!=='--confirm=finea_saas_dev')exit("Confirmation requise.\n");
$pdo=Database::getConnection();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='finea_saas_dev')throw new RuntimeException('Base non autorisée.');
UnitDescriptionMigration::install($pdo);
echo "Descriptions des unités initialisées.\n";
