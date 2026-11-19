<?php
declare(strict_types=1);
require_once __DIR__.'/../services/ClientProjectCompletenessAlertService.php';
final class CompletenessStatement extends PDOStatement {
    public function __construct(private mixed $value) {}
    public function execute(?array $params=null):bool{return true;}
    public function fetchColumn(int $column=0):mixed{return $this->value;}
}
final class CompletenessPdo extends PDO {
    public array $queries=[];
    public function __construct(private bool $busy=false,private bool $fail=false){}
    public function exec(string $statement):int|false{return 0;}
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false{$this->queries[]=$query;return new CompletenessStatement($this->busy?0:1);}
    public function prepare(string $query,array $options=[]):PDOStatement|false{if($this->fail)throw new RuntimeException('read failure');return new CompletenessStatement(123);}
    public function inTransaction():bool{return false;}
}
$pdo=new CompletenessPdo();$result=(new ClientProjectCompletenessAlertService($pdo))->dispatch('2026-09-06');
if(empty($result['duplicate']) || count($pdo->queries)!==2 || !str_contains($pdo->queries[1],'RELEASE_LOCK'))throw new RuntimeException('Duplicate day or lock release failed');
$pdo=new CompletenessPdo(true);$result=(new ClientProjectCompletenessAlertService($pdo))->dispatch();
if(empty($result['busy']))throw new RuntimeException('Concurrent dispatch not skipped');
$pdo=new CompletenessPdo(false,true);try{(new ClientProjectCompletenessAlertService($pdo))->dispatch();throw new LogicException('Expected failure');}catch(RuntimeException $e){if(!str_contains(end($pdo->queries),'RELEASE_LOCK'))throw new RuntimeException('Lock leaked on failure');}
echo "CompletenessDispatchTest: OK (duplicate day, concurrency, failure cleanup)\n";
