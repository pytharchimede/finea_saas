<?php
declare(strict_types=1);
require_once __DIR__.'/../repositories/FournisseurRepository.php';

/** Specialist records select an approved master supplier; they never create a second identity. */
final class SupplierReferenceService
{
    private FournisseurRepository $suppliers;
    public function __construct(private PDO $pdo)
    {
        $this->suppliers=new FournisseurRepository($pdo);
        if(!$this->suppliers->isReady())throw new RuntimeException('Le référentiel fournisseur est indisponible.');
    }
    public static function validate(array $row): array
    {
        if(!empty($row['personnel_identity_conflict']))throw new DomainException(PersonIdentityGuard::EMPLOYEE_MESSAGE);
        if(empty($row['id_fournisseur'])||!empty($row['archived_at'])||(int)($row['actif']??0)!==1||($row['validation_status']??'')!=='approved'||empty($row['document_is_complete'])||SupplierIdentity::missing($row))throw new DomainException('Sélectionnez une fiche fournisseur active, validée et complète dans le référentiel commun.');

        return $row;
    }
    public function options(): array
    {
        return array_values(array_filter($this->suppliers->listForSelect(),static function(array $row):bool{try{self::validate($row);return true;}catch(DomainException $e){return false;}}));
    }
    public function resolve(int $id): array
    {
        return self::validate($id>0?($this->suppliers->getById($id)??[]):[]);
    }
    public function bind(array $data,array $old=[]): array
    {
        $row=$this->resolve((int)($data['master_supplier_id']??$old['master_supplier_id']??0));
        $name=(string)$row['supplier_display_name'];
        $number=($row['supplier_type']??'morale')==='physique'?($row['identity_document_number']??''):(($row['fiscal_country']??'CI')==='CI'?($row['ncc']??''):($row['registration_number']??$row['siret']??$row['vat_number']??''));
        return array_replace($data,['master_supplier_id'=>(int)$row['id_fournisseur'],'name'=>$name,'nom'=>$name,'contact_name'=>$row['contact_nom']??'','phone'=>$row['telephone']??'','telephone'=>$row['telephone']??'','email'=>$row['email']??'','address'=>$row['adresse']??'','tax_number'=>$number,'ncc'=>($row['fiscal_country']??'CI')==='CI'?($row['ncc']??null):null]);
    }
    public static function ensureLink(PDO $pdo,string $table): void
    {
        if(!in_array($table,['logistics_suppliers','fournisseurs_transport'],true))throw new InvalidArgumentException('Table fournisseur inconnue.');
        $columns=$pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        if(!in_array('master_supplier_id',$columns,true))$pdo->exec('ALTER TABLE '.$table.' ADD master_supplier_id INT NULL, ADD KEY idx_master_supplier(master_supplier_id)');
    }
}
