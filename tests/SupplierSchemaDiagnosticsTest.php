<?php
declare(strict_types=1);
require_once __DIR__.'/../repositories/FournisseurRepository.php';
function schemaCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
final class BrokenSchemaPDO extends PDO
{
    public function __construct(){}
    public function exec(string $statement):int|false
    {
        $e=new PDOException("SQLSTATE[HY000]: General error: 1005 Can't create table 'private_database_table' (errno: 150)");$e->errorInfo=['HY000',1005,'test'];throw $e;
    }
}
$repo=new FournisseurRepository(new BrokenSchemaPDO());$diagnostic=$repo->getInitDiagnostics();
schemaCheck(!$repo->isReady()&&$diagnostic['sqlstate']==='HY000'&&$diagnostic['driver_code']===1005,'Native SQL failure preserved instead of guessing permissions');
schemaCheck(str_starts_with($diagnostic['operation'],'CREATE TABLE'),'Failing operation captured');
schemaCheck(!str_contains($diagnostic['message'],'private_database_table'),'Quoted database values masked');
final class LegacyColumnsStatement extends PDOStatement
{
    public function __construct(){}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array{return ['id'];}
}
final class LegacyDisbursementPDO extends PDO
{
    public array $changes=[];
    public function __construct(){}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false{return new LegacyColumnsStatement();}
    public function exec(string $statement):int|false{if(str_contains($statement,'AFTER personnel_id'))throw new PDOException('Unknown column personnel_id');$this->changes[]=$statement;return 0;}
}
$pdo=new LegacyDisbursementPDO();$legacy=(new ReflectionClass(FournisseurRepository::class))->newInstanceWithoutConstructor();(new ReflectionProperty(FournisseurRepository::class,'pdo'))->setValue($legacy,$pdo);
(new ReflectionMethod(FournisseurRepository::class,'ensureDecaissementColumns'))->invoke($legacy);
schemaCheck(count($pdo->changes)===4,'Supplier fields can be prepared on a legacy disbursement table without personnel_id');
echo "SupplierSchemaDiagnosticsTest: OK (native error, masking and legacy column compatibility)\n";
