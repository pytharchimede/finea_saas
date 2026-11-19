<?php
require_once __DIR__.'/RhCompanyProfileService.php';

final class RhEmployeeContractCompanyService
{
    private RhCompanyProfileService $companies;

    public function __construct(private PDO $pdo)
    {
        $this->companies=new RhCompanyProfileService($pdo);
        $this->ensureColumns();
    }

    private function ensureColumns(): void
    {
        foreach([
            'company_profile_id'=>'ALTER TABLE employee_contracts ADD COLUMN company_profile_id INT UNSIGNED NULL AFTER personnel_id',
            'company_snapshot_json'=>'ALTER TABLE employee_contracts ADD COLUMN company_snapshot_json LONGTEXT NULL AFTER company_profile_id',
        ] as $column=>$sql){
            $q=$this->pdo->prepare('SHOW COLUMNS FROM employee_contracts LIKE ?');$q->execute([$column]);
            if(!$q->fetch(PDO::FETCH_ASSOC))$this->pdo->exec($sql);
        }
    }

    public function companies(): array { return $this->companies->all(true); }
    public function defaultCompany(): array { return $this->companies->defaultCompany(); }

    public function attach(int $contractId,int $companyId): array
    {
        if($contractId<1||$companyId<1)throw new RuntimeException('Contrat ou entreprise invalide.');
        $company=$this->companies->find($companyId);
        if(!$company||empty($company['active']))throw new RuntimeException('Entreprise contractante introuvable ou inactive.');
        $snapshot=$this->snapshot($company);
        $q=$this->pdo->prepare('UPDATE employee_contracts SET company_profile_id=?,company_snapshot_json=? WHERE id=?');
        $q->execute([$companyId,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$contractId]);
        return $snapshot;
    }

    public function resolve(array $contract): array
    {
        $snapshot=json_decode((string)($contract['company_snapshot_json']??''),true);
        if(is_array($snapshot)&&!empty($snapshot['legal_name']))return $snapshot;
        $id=(int)($contract['company_profile_id']??0);
        if($id>0){$company=$this->companies->find($id);if($company)return $this->snapshot($company);}
        return $this->snapshot($this->companies->defaultCompany());
    }

    private function snapshot(array $company): array
    {
        $keys=['id','legal_name','trade_name','legal_form','share_capital','address_line','postal_box','city','country','phone','email','website','rccm','taxpayer_account','representative_name','representative_title','bank_name','bank_account','iban','swift_bic','logo_path'];$out=[];
        foreach($keys as $key)$out[$key]=$company[$key]??null;
        $out['snapshot_at']=date('c');return $out;
    }
}
