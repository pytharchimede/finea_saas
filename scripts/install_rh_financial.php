<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialSchema.php';
try{
    $pdo=Database::getConnection();
    foreach(['20261002_rh_financial_points.sql','20261002_rh_whatsapp_template_monitor.sql'] as $name){
        $path=__DIR__.'/../database/migrations/'.$name;$sql=file_get_contents($path);if($sql===false)throw new RuntimeException('Migration absente : '.$name);
        foreach(RhFinancialSchema::statements($sql) as $statement)$pdo->exec($statement);
        echo 'Migration appliquée : '.$name.PHP_EOL;
    }
    RhFinancialSchema::assertReady($pdo);
    echo 'Installation RH terminée. Compte DG et numéro configurables dans /rh/points_financiers_rh.php.'.PHP_EOL;
    echo 'Aucun modèle Twilio créé ni circuit activé par cette installation.'.PHP_EOL;
}catch(Throwable $e){error_log('RH install: '.$e->getMessage());fwrite(STDERR,"Installation RH incomplète. Consultez le journal PHP du serveur ; aucune activation n’a été effectuée.\n");exit(1);}
