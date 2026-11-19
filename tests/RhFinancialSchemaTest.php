<?php
declare(strict_types=1);
require_once __DIR__.'/../services/RhFinancialSchema.php';
require_once __DIR__.'/../services/RhFinancialPointService.php';
class RhSchemaTestPdo extends PDO
{
    public function __construct(){parent::__construct('sqlite::memory:');$this->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$this->sqliteCreateFunction('NOW',static fn()=>date('Y-m-d H:i:s'));}
    public function prepare(string $query,array $options=[]):PDOStatement|false
    {
        $query=str_replace('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN','SELECT name FROM sqlite_master WHERE type=\'table\' AND name IN',$query);
        return parent::prepare($query,$options);
    }
}
function schemaCheck(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
function installFixture(PDO $pdo):void{
    foreach(['20261002_rh_financial_points.sql','20261002_rh_whatsapp_template_monitor.sql'] as $name){
        foreach(RhFinancialSchema::statements(file_get_contents(__DIR__.'/../database/migrations/'.$name)) as $sql){
            $sql=preg_replace('/\) ENGINE=.+$/s',')',$sql);$sql=preg_replace('/(?:BIGINT|INT) UNSIGNED AUTO_INCREMENT PRIMARY KEY/','INTEGER PRIMARY KEY AUTOINCREMENT',$sql);$sql=str_replace([' UNSIGNED',' ON UPDATE CURRENT_TIMESTAMP','INSERT IGNORE INTO'],['','','INSERT OR IGNORE INTO'],$sql);$sql=preg_replace('/UNIQUE KEY \w+\(/','UNIQUE(',$sql);$sql=preg_replace('/,?\s*KEY \w+\([^)]*\)/','',$sql);$sql=preg_replace('/,\s*\)/',')',$sql);$pdo->exec($sql);
        }
    }
}
$pdo=new RhSchemaTestPdo();schemaCheck(count(RhFinancialSchema::missing($pdo))===10,'Empty database exposes all ten missing RH tables');
try{RhFinancialSchema::assertReady($pdo);throw new RuntimeException('Readiness must fail');}catch(DomainException $e){schemaCheck(!str_contains($e->getMessage(),'SQLSTATE')&&str_contains($e->getMessage(),'install_rh_financial.php'),'Missing schema produces actionable installation message');}
installFixture($pdo);RhFinancialSchema::assertReady($pdo);schemaCheck(RhFinancialSchema::missing($pdo)===[],'Installer statements create the complete RH schema');
$pdo->exec("UPDATE rh_financial_settings SET dg_user_id=2,dg_phone='+2250700000000',advance_active_at='2026-10-02 10:00:00',advance_sid='HX00000000000000000000000000000001' WHERE id=1");installFixture($pdo);
schemaCheck($pdo->query('SELECT advance_active_at FROM rh_financial_settings')->fetchColumn()==='2026-10-02 10:00:00','Reinstallation preserves historical activation cutoff');schemaCheck($pdo->query('SELECT dg_phone FROM rh_financial_settings')->fetchColumn()==='+2250700000000','Reinstallation preserves DG configuration');
schemaCheck((int)$pdo->query('SELECT COUNT(*) FROM rh_financial_template_monitor')->fetchColumn()===3,'Repeated installation does not duplicate template monitor rows');
$pdo->exec("CREATE TABLE user_devis_banamur(id INT,mail_pro TEXT,active INT,acces_direction INT,acces_rh INT,acces_admin INT);INSERT INTO user_devis_banamur VALUES(1,'admin@example.test',1,0,0,1),(2,'dg@example.test',1,1,0,0)");
$pdo->exec("ALTER TABLE user_devis_banamur ADD COLUMN nom TEXT;ALTER TABLE user_devis_banamur ADD COLUMN prenom TEXT;UPDATE user_devis_banamur SET nom='BRAUD',prenom='Paul-Alex' WHERE id=2;INSERT INTO user_devis_banamur(id,mail_pro,active,acces_direction,acces_rh,acces_admin) VALUES(3,'inactive@example.test',0,1,0,0)");
$directions=(new RhFinancialPointService($pdo))->directionCandidates(1);schemaCheck(count($directions)===1 && $directions[0]['email']==='dg@example.test' && $directions[0]['nom']==='BRAUD','DG options use real mail_pro column and named active Direction accounts');
(new RhFinancialPointService($pdo))->configure(1,2,'+225 07 11 22 33 44');schemaCheck($pdo->query('SELECT dg_phone FROM rh_financial_settings')->fetchColumn()==='+2250711223344','DG selector configuration stores normalized international phone after installation');
$pdo->exec('DROP TABLE rh_financial_template_events');schemaCheck(RhFinancialSchema::missing($pdo,false)===[]&&RhFinancialSchema::missing($pdo)===['rh_financial_template_events'],'Monitor installation is checked separately from financial workflow');
echo "All RH schema checks passed.\n";
