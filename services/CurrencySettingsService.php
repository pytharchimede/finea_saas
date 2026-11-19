<?php
declare(strict_types=1);

final class CurrencySettingsService
{
    public function __construct(private PDO $pdo){$this->ensureSchema();}

    public function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS facturation_currencies (
            code CHAR(3) NOT NULL,label VARCHAR(100) NOT NULL,symbol VARCHAR(12) NULL,
            rate_to_xof DECIMAL(18,6) NOT NULL DEFAULT 1,is_active TINYINT(1) NOT NULL DEFAULT 1,
            is_default TINYINT(1) NOT NULL DEFAULT 0,updated_by_user_id INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(code),KEY idx_currency_active_default(is_active,is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $stmt=$this->pdo->prepare("INSERT IGNORE INTO facturation_currencies(code,label,symbol,rate_to_xof,is_active,is_default) VALUES
          ('XOF','Franc CFA BCEAO','FCFA',1,1,1),('EUR','Euro','€',655.957000,1,0),('USD','Dollar américain','$',1,0,0)");
        $stmt->execute();
        $this->pdo->exec("UPDATE facturation_currencies SET rate_to_xof=1,is_active=1 WHERE code='XOF'");
        if(!(int)$this->pdo->query('SELECT COUNT(*) FROM facturation_currencies WHERE is_active=1 AND is_default=1')->fetchColumn()){
            $this->pdo->exec("UPDATE facturation_currencies SET is_default=(code='XOF')");
        }
    }

    public function all(bool $activeOnly=false): array
    {
        $where=$activeOnly?' WHERE is_active=1':'';
        return $this->pdo->query("SELECT * FROM facturation_currencies$where ORDER BY is_default DESC,code ASC")->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function default(): array
    {
        $row=$this->pdo->query("SELECT * FROM facturation_currencies WHERE is_active=1 ORDER BY is_default DESC,(code='XOF') DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row?:['code'=>'XOF','label'=>'Franc CFA BCEAO','symbol'=>'FCFA','rate_to_xof'=>1];
    }

    public function rates(): array
    {
        $out=[];foreach($this->all(true) as $row)$out[(string)$row['code']]=(float)$row['rate_to_xof'];return $out;
    }

    public function save(array $rows,string $defaultCode,?int $userId): void
    {
        $defaultCode=strtoupper(trim($defaultCode));$this->pdo->beginTransaction();
        try{
            $up=$this->pdo->prepare("INSERT INTO facturation_currencies(code,label,symbol,rate_to_xof,is_active,is_default,updated_by_user_id)
              VALUES(:code,:label,:symbol,:rate,:active,0,:user) ON DUPLICATE KEY UPDATE label=VALUES(label),symbol=VALUES(symbol),rate_to_xof=VALUES(rate_to_xof),is_active=VALUES(is_active),updated_by_user_id=VALUES(updated_by_user_id)");
            foreach($rows as $row){
                $code=strtoupper(trim((string)($row['code']??'')));if(!preg_match('/^[A-Z]{3}$/',$code))throw new DomainException('Chaque devise doit avoir un code ISO de 3 lettres.');
                $label=trim((string)($row['label']??''));$rate=(float)str_replace(',','.',(string)($row['rate_to_xof']??0));
                if($label===''||$rate<=0)throw new DomainException("Libellé et taux positif obligatoires pour $code.");
                if($code==='XOF')$rate=1.0;
                $up->execute(['code'=>$code,'label'=>$label,'symbol'=>trim((string)($row['symbol']??''))?:null,'rate'=>$rate,'active'=>$code==='XOF'?1:(!empty($row['is_active'])?1:0),'user'=>$userId?:null]);
            }
            $check=$this->pdo->prepare('SELECT COUNT(*) FROM facturation_currencies WHERE code=:code AND is_active=1');$check->execute(['code'=>$defaultCode]);
            if(!(int)$check->fetchColumn())throw new DomainException('La devise par défaut doit être active.');
            $this->pdo->exec('UPDATE facturation_currencies SET is_default=0');
            $this->pdo->prepare('UPDATE facturation_currencies SET is_default=1 WHERE code=:code')->execute(['code'=>$defaultCode]);
            $this->pdo->commit();
        }catch(Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }
}
