<?php

final class RhCompanyProfileService
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureSchema();
        $this->seedRequiredCompanies();
    }

    private function ensureSchema(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rh_company_profiles (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            legal_name VARCHAR(255) NOT NULL,
            trade_name VARCHAR(255) NULL,
            legal_form VARCHAR(190) NULL,
            share_capital VARCHAR(190) NULL,
            address_line VARCHAR(255) NULL,
            postal_box VARCHAR(120) NULL,
            city VARCHAR(120) NULL,
            country VARCHAR(120) NULL DEFAULT 'Côte d’Ivoire',
            phone VARCHAR(80) NULL,
            email VARCHAR(190) NULL,
            website VARCHAR(190) NULL,
            rccm VARCHAR(150) NULL,
            taxpayer_account VARCHAR(150) NULL,
            representative_name VARCHAR(190) NULL,
            representative_title VARCHAR(190) NULL,
            bank_name VARCHAR(190) NULL,
            bank_account VARCHAR(190) NULL,
            iban VARCHAR(190) NULL,
            swift_bic VARCHAR(80) NULL,
            logo_path VARCHAR(500) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rh_company_active (active,is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function seedRequiredCompanies(): void
    {
        $required = [
            [
                'legal_name' => 'BANAMUR INDUSTRIES ET TECHNOLOGIES',
                'trade_name' => 'BANAMUR',
                'legal_form' => 'SARL',
                'address_line' => 'Koumassi, Boulevard du Gabon prolongé',
                'postal_box' => null,
                'city' => 'Abidjan',
                'country' => 'Côte d’Ivoire',
                'phone' => '+225 27 21 36 27 27',
                'email' => 'info@fidest.org',
                'rccm' => 'CI-ABJ-03-2022-B13-02828',
                'representative_name' => 'À compléter',
                'representative_title' => 'Directeur Général',
                'logo_path' => 'logo/logo_banamur.jpg',
                'is_default' => 1,
            ],
            [
                'legal_name' => 'FIDEST',
                'trade_name' => 'FIDEST',
                'legal_form' => 'SARL',
                'address_line' => 'Abidjan Koumassi, Boulevard du Gabon, Zone Industrielle',
                'postal_box' => '01 BP 20163 Abidjan 01',
                'city' => 'Abidjan',
                'country' => 'Côte d’Ivoire',
                'phone' => null,
                'email' => null,
                'rccm' => 'CI-ABJ-2017-B-20163',
                'representative_name' => 'BRAUD MENSAH PAUL-ALEX',
                'representative_title' => 'Gérant',
                'logo_path' => null,
                'is_default' => 0,
            ],
        ];

        $find = $this->pdo->prepare('SELECT id FROM rh_company_profiles WHERE legal_name = :legal_name OR (rccm IS NOT NULL AND rccm <> \'\' AND rccm = :rccm) LIMIT 1');
        $insert = $this->pdo->prepare('INSERT INTO rh_company_profiles
            (legal_name,trade_name,legal_form,address_line,postal_box,city,country,phone,email,rccm,representative_name,representative_title,logo_path,is_default,active)
            VALUES(:legal_name,:trade_name,:legal_form,:address_line,:postal_box,:city,:country,:phone,:email,:rccm,:representative_name,:representative_title,:logo_path,:is_default,1)');

        foreach ($required as $company) {
            $find->execute(['legal_name' => $company['legal_name'], 'rccm' => $company['rccm']]);
            if ($find->fetchColumn()) {
                continue;
            }
            $insert->execute($company);
        }

        $defaultCount = (int)($this->pdo->query('SELECT COUNT(*) FROM rh_company_profiles WHERE active=1 AND is_default=1')->fetchColumn() ?: 0);
        if ($defaultCount === 0) {
            $this->pdo->exec("UPDATE rh_company_profiles SET is_default=1 WHERE legal_name='BANAMUR INDUSTRIES ET TECHNOLOGIES' LIMIT 1");
        }
    }

    public function all(bool $activeOnly=true): array
    {
        $sql='SELECT * FROM rh_company_profiles'.($activeOnly?' WHERE active=1':'').' ORDER BY is_default DESC,legal_name ASC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    public function find(int $id): ?array
    {
        if($id<1) return null;
        $q=$this->pdo->prepare('SELECT * FROM rh_company_profiles WHERE id=? LIMIT 1');$q->execute([$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        return $row?:null;
    }

    public function defaultCompany(): array
    {
        $row=$this->pdo->query('SELECT * FROM rh_company_profiles WHERE active=1 ORDER BY is_default DESC,id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        return $row?:[];
    }

    public function save(array $data,int $actorId=0): int
    {
        $id=max(0,(int)($data['id']??0));
        $legalName=trim((string)($data['legal_name']??''));
        if($legalName==='') throw new RuntimeException('La raison sociale est obligatoire.');
        $fields=['trade_name','legal_form','share_capital','address_line','postal_box','city','country','phone','email','website','rccm','taxpayer_account','representative_name','representative_title','bank_name','bank_account','iban','swift_bic','logo_path'];
        $payload=[];foreach($fields as $field)$payload[$field]=trim((string)($data[$field]??''))?:null;
        $isDefault=!empty($data['is_default'])?1:0;$active=array_key_exists('active',$data)?(!empty($data['active'])?1:0):1;
        if($isDefault) $this->pdo->exec('UPDATE rh_company_profiles SET is_default=0');
        if($id>0){
            $set=['legal_name=:legal_name'];foreach($fields as $field)$set[]="$field=:$field";$set[]='is_default=:is_default';$set[]='active=:active';$set[]='updated_by=:updated_by';
            $q=$this->pdo->prepare('UPDATE rh_company_profiles SET '.implode(',',$set).' WHERE id=:id');
            $q->execute($payload+['legal_name'=>$legalName,'is_default'=>$isDefault,'active'=>$active,'updated_by'=>$actorId?:null,'id'=>$id]);
            return $id;
        }
        $columns=array_merge(['legal_name'],$fields,['is_default','active','created_by']);$marks=array_map(fn($f)=>':'.$f,$columns);
        $q=$this->pdo->prepare('INSERT INTO rh_company_profiles('.implode(',',$columns).') VALUES('.implode(',',$marks).')');
        $q->execute($payload+['legal_name'=>$legalName,'is_default'=>$isDefault,'active'=>$active,'created_by'=>$actorId?:null]);
        return (int)$this->pdo->lastInsertId();
    }
}
