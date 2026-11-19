<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../model/Database.php';
require_once __DIR__.'/../services/RhFinancialSchema.php';
try{
 $pdo=Database::getConnection();$sql=file_get_contents(__DIR__.'/../database/migrations/20261003_whatsapp_admin_console.sql');
 if($sql===false)throw new RuntimeException('Migration absente.');
 foreach(RhFinancialSchema::statements($sql) as $statement)$pdo->exec($statement);
 echo "Console WhatsApp installée. Les réglages RH et finance ont été conservés.\n";
}catch(Throwable $e){error_log('WhatsApp installation: '.$e->getMessage());fwrite(STDERR,"Installation impossible. Consultez le journal PHP.\n");exit(1);}
