<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require_once dirname(__DIR__,2).'/model/Database.php';
require_once dirname(__DIR__,2).'/model/StandardUnitSeeder.php';
if($argc!==2||$argv[1]!=='--confirm=finea_saas_dev'){fwrite(STDERR,"Confirmation --confirm=finea_saas_dev requise.\n");exit(1);}
try{
    $pdo=Database::getConnection();
    if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='finea_saas_dev')throw new RuntimeException('Base non autorisée.');
    $pdo->beginTransaction();
    $result=StandardUnitSeeder::seed($pdo);
    $pdo->commit();
    printf("Unités ajoutées : %d ; déjà présentes : %d.\n",$result['added'],$result['existing']);
}catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
