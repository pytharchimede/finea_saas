<?php
declare(strict_types=1);
$dsn=getenv('ACCOUNTING_TEST_DSN');
if (!$dsn) { echo "Set ACCOUNTING_TEST_DSN to an empty disposable database.\n"; exit; }
class Database {
    public static function getConnection(): PDO { return $GLOBALS['pdo']; }
}
require_once __DIR__.'/../model/User.php';
require_once __DIR__.'/../model/AccountingPermissions.php';
$pdo=new PDO($dsn,getenv('ACCOUNTING_TEST_USER')?:'root',getenv('ACCOUNTING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if ($pdo->query('SHOW TABLES')->fetchAll()!==[]) throw new RuntimeException('Empty database required');
$pdo->exec('CREATE TABLE user_devis_banamur(id INT PRIMARY KEY, active TINYINT DEFAULT 1, acces_admin TINYINT DEFAULT 0, acces_compta TINYINT DEFAULT 0, acces_direction TINYINT DEFAULT 0)');
$pdo->exec('INSERT INTO user_devis_banamur(id,acces_compta,acces_direction) VALUES (1,1,1)');
AccessManager::ensureOptionalPermissionColumns($pdo);
$grants=AccessManager::loadUserPermissions(1);
foreach (AccountingPermissions::LABELS as $key=>$label) {
    if (!array_key_exists($key,$grants) || $grants[$key]) throw new RuntimeException('New grant must default to zero: '.$key);
}
$model=new User($pdo);
$selected=['acces_comptabilite'=>1,'compta_saisir'=>1,'compta_consulter_ecritures'=>1,'compta_consulter_justificatifs'=>1];
if (!$model->updateUser(1,$selected)) throw new RuntimeException('Grant save failed');
$_SESSION=['user_id'=>1];
AccessManager::refreshSessionPermissions();
if (!AccountingPermissions::action($_SESSION['permissions'],'save') || AccountingPermissions::action($_SESSION['permissions'],'post')) throw new RuntimeException('Grant not isolated');
$model->updateUser(1,['compta_saisir'=>0]);
AccessManager::refreshSessionPermissions();
if (AccountingPermissions::action($_SESSION['permissions'],'save')) throw new RuntimeException('Revocation not immediate');
$model->updateUser(1,['compta_saisir'=>1,'acces_comptabilite'=>0]);
AccessManager::refreshSessionPermissions();
if (AccountingPermissions::allows($_SESSION['permissions'])) throw new RuntimeException('Module revocation bypassed');
echo "AccountingPermissionPersistenceTest: automatic schema, zero defaults, User updates, session reload and revocation passed.\n";
