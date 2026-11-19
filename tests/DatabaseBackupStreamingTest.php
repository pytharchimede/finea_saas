<?php
declare(strict_types=1);
require_once __DIR__.'/../services/DatabaseBackupService.php';
function backupCheck(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
final class BackupTestPDO extends PDO {
    public bool $buffered=true,$active=false,$transaction=false,$fail=false;
    public int $rows=40000;
    public array $commands=[];
    public function __construct(){}
    public function getAttribute(int $attribute):mixed {return $attribute===PDO::ATTR_DRIVER_NAME?'mysql':$this->buffered;}
    public function setAttribute(int $attribute,mixed $value):bool {$this->buffered=(bool)$value;return true;}
    public function inTransaction():bool {return $this->transaction;}
    public function rollBack():bool {$this->transaction=false;return true;}
    public function exec(string $statement):int|false {
        backupCheck(!$this->active,'SQL attempted while unbuffered cursor remains open');
        $this->commands[]=$statement;
        if(str_starts_with($statement,'START TRANSACTION'))$this->transaction=true;
        return 0;
    }
    public function quote(string $string,int $type=PDO::PARAM_STR):string|false {return "'".str_replace("'","''",$string)."'";}
    public function query(string $query,?int $fetchMode=null,mixed ...$args):PDOStatement|false {
        backupCheck(!$this->active,'Cursor not closed before next query');
        if(str_starts_with($query,'SELECT *')) {
            backupCheck(!$this->buffered,'SELECT still buffers the whole table');
            $this->active=true;return new BackupTestStatement($this,[],true);
        }
        $rows=match(true){
            str_contains($query,'VERSION()')=>[['10.11.19-MariaDB']],
            str_contains($query,'@@SESSION')=>[['2']],
            str_contains($query,'DATABASE()')=>[['fixture']],
            str_contains($query,'SHOW FULL TABLES')=>[['large_table','BASE TABLE']],
            str_contains($query,'SHOW CREATE TABLE')=>[['Table'=>'large_table','Create Table'=>'CREATE TABLE large_table (id INT, data TEXT)']],
            default=>throw new LogicException('Unexpected SQL: '.$query)
        };
        return new BackupTestStatement($this,$rows);
    }
}
final class BackupTestStatement extends PDOStatement {
    private int $offset=0;
    public function __construct(private BackupTestPDO $db,private array $rows=[],private bool $stream=false){}
    public function fetch(int $mode=PDO::FETCH_DEFAULT,int $orientation=PDO::FETCH_ORI_NEXT,int $offset=0):mixed {
        if(!$this->stream)return $this->rows[$this->offset++]??false;
        if($this->db->fail && $this->offset===2)throw new RuntimeException('Simulated SQL interruption');
        if($this->offset>=$this->db->rows)return false;
        return ['id'=>++$this->offset,'data'=>str_repeat('abcdefgh',1024)];
    }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {return $this->rows;}
    public function fetchColumn(int $column=0):mixed {return array_values($this->rows[0]??[])[$column]??false;}
    public function closeCursor():bool {if($this->stream)$this->db->active=false;return true;}
}
$root=sys_get_temp_dir().'/finea-backup-test-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
$db=new BackupTestPDO();
$repo=new DatabaseBackupRepository($db);
$service=new DatabaseBackupService($repo);
try {
    $dir=$root.'/large';mkdir($dir);
    $old=$dir.'/backup_fixture_20200101_000000_full.sql.gz';
    file_put_contents($old,'historical');touch($old,1577836800);
    $backup=$service->createBackup('full',$dir,0,'test','2026-10-26');
    backupCheck(is_file($backup['path']) && is_file($old),'Completed export or historical backup lost');
    backupCheck(!$db->active && $db->buffered && !$db->transaction,'Connection not restored');
    backupCheck(in_array('SET SESSION max_statement_time=0',$db->commands,true) && in_array('SET SESSION max_statement_time=2',$db->commands,true),'SQL timeout not disabled/restored');
    $gz=gzopen($backup['path'],'rb');$tail='';$bytes=0;
    while(!gzeof($gz)){$chunk=gzread($gz,8192);backupCheck($chunk!==false,'Unreadable gzip');$bytes+=strlen($chunk);$tail=substr($tail.$chunk,-2048);}
    gzclose($gz);
    backupCheck($bytes>300*1024*1024 && str_contains($tail,'40000 lignes exportees') && str_contains($tail,'SET FOREIGN_KEY_CHECKS=1;'),'Export incomplete');
    backupCheck(memory_get_peak_usage(true)<32*1024*1024,'Export exceeded bounded memory');
    $count=count(glob($dir.'/*.sql.gz'));
    backupCheck($service->createBackup('full',$dir,0,'test','2026-10-26')['skipped']===true,'Scheduled slot executed twice');
    backupCheck(count(glob($dir.'/*.sql.gz'))===$count,'Duplicate archive created');
    $rows=$repo->fetchTableRows('large_table');foreach($rows as $row){break;}unset($rows);
    backupCheck(!$db->active && $db->buffered,'Early generator exit leaks cursor');
    $db->fail=true;
    $failed=$root.'/failure';
    try{$service->createBackup('full',$failed,0,'test','2026-10-26');throw new LogicException('Failed export reported success');}catch(RuntimeException $expected){backupCheck(str_contains($expected->getMessage(),'Simulated'),'Unexpected failure');}
    backupCheck(!glob($failed.'/*.sql.gz') && !glob($failed.'/.*.tmp') && !is_file($failed.'/.schedule-success.json'),'Partial export published or slot marked successful');
    backupCheck($db->buffered && !$db->active && !$db->transaction,'Failure leaks cursor/transaction');
    $db->fail=false;$db->rows=3;
    backupCheck(isset($service->createBackup('full',$failed,0,'test','2026-10-26')['name']),'Failed slot could not retry');
    foreach([
        '2026-10-26 01:59:59'=>'2026-10-23',
        '2026-10-26 02:00:00'=>'2026-10-26',
        '2026-10-27 12:00:00'=>'2026-10-26',
        '2026-10-28 02:00:00'=>'2026-10-28',
        '2026-10-30 02:00:00'=>'2026-10-30',
        '2026-11-01 12:00:00'=>'2026-10-30',
        '2026-11-02 02:00:00'=>'2026-11-02'
    ] as $date=>$expected) backupCheck(DatabaseBackupService::scheduledSlot(new DateTimeImmutable($date,new DateTimeZone('Africa/Abidjan')))===$expected,'Wrong weekly/catch-up slot: '.$date);
    backupCheck(DatabaseBackupService::scheduledSlot(new DateTimeImmutable('2026-10-26T03:00:00+01:00'))==='2026-10-26','Server timezone leaked into schedule');
    echo "DatabaseBackupStreamingTest: OK (".round($bytes/1048576)." MiB SQL, ".round(memory_get_peak_usage(true)/1048576)." MiB peak, streaming, SQL timeout, gzip, failures, retry, weekly schedule, retention)\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($root);
}
