<?php

final class FneInvoiceDirectionCleaner
{
    public function __construct(private PDO $pdo)
    {
    }

    public function clean(): array
    {
        $report=['purchase_to_sale'=>0,'sale_to_purchase'=>0,'linked_to_review'=>0,'errors'=>[]];
        $this->move(
            'fne_purchase_invoice_imports',
            'fne_sales_invoice_imports',
            fn(array $row):bool=>$this->containsBanamur((string)($row['establishment']??'')),
            'purchase_to_sale',
            $report
        );
        $this->move(
            'fne_sales_invoice_imports',
            'fne_purchase_invoice_imports',
            fn(array $row):bool=>$this->containsBanamur((string)($row['client_name']??'')),
            'sale_to_purchase',
            $report
        );
        return $report;
    }

    private function move(string $source,string $destination,callable $isWrong,string $counter,array &$report):void
    {
        try{$rows=$this->pdo->query("SELECT * FROM {$source} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable $e){$report['errors'][]=$source.' : '.$e->getMessage();return;}

        $sourceColumns=$this->columns($source);
        $destinationColumns=$this->columns($destination);
        $excluded=['id','finea_invoice_id','link_method','linked_at','finea_client_id','finea_supplier_id'];
        $columns=array_values(array_diff(array_intersect($sourceColumns,$destinationColumns),$excluded));
        if(!$columns)return;

        $quoted=implode(',',array_map(static fn(string $column):string=>"`{$column}`",$columns));
        $values=implode(',',array_map(static fn(string $column):string=>":{$column}",$columns));
        $insert=$this->pdo->prepare("INSERT IGNORE INTO {$destination} ({$quoted}) VALUES ({$values})");
        $destinationExists=$this->pdo->prepare("SELECT id FROM {$destination} WHERE (token IS NOT NULL AND token<>'' AND token=:token) OR (fne_reference IS NOT NULL AND fne_reference<>'' AND fne_reference=:reference) LIMIT 1");
        $delete=$this->pdo->prepare("DELETE FROM {$source} WHERE id=:id");

        foreach($rows as $row){
            if(!$isWrong($row))continue;
            if(!empty($row['finea_invoice_id'])){$report['linked_to_review']++;continue;}
            try{
                $this->pdo->beginTransaction();
                $params=[];foreach($columns as $column)$params[$column]=$row[$column]??null;
                $insert->execute($params);
                $destinationExists->execute(['token'=>(string)($row['token']??''),'reference'=>(string)($row['fne_reference']??'')]);
                if(!$destinationExists->fetchColumn())throw new RuntimeException('La copie vers le bon catalogue n’a pas été confirmée.');
                $delete->execute(['id'=>(int)$row['id']]);
                $this->pdo->commit();
                $report[$counter]++;
            }catch(Throwable $e){
                if($this->pdo->inTransaction())$this->pdo->rollBack();
                $report['errors'][]=$source.' #'.(int)$row['id'].' : '.$e->getMessage();
            }
        }
    }

    private function columns(string $table):array
    {
        return array_column($this->pdo->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_ASSOC)?:[],'Field');
    }

    private function containsBanamur(string $value):bool
    {
        $value=mb_strtolower(trim($value),'UTF-8');
        $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
        return str_contains(strtolower($ascii!==false?$ascii:$value),'banamur');
    }
}
