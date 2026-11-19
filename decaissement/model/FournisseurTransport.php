<?php
require_once __DIR__.'/../../services/SupplierReferenceService.php';

class FournisseurTransport
{
    private PDO $pdo;

    public function __construct(PDO $pdo, private ?SupplierReferenceService $reference = null)
    {
        $this->pdo = $pdo;
        SupplierReferenceService::ensureLink($pdo,'fournisseurs_transport');
    }

    private function reference(): SupplierReferenceService
    {
        return $this->reference??=new SupplierReferenceService($this->pdo);
    }

    public function getAllActifs(): array
    {
        $st = $this->pdo->query("SELECT * FROM fournisseurs_transport WHERE actif=1 ORDER BY nom");
        $reference=$this->reference();
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC),static function(array $row)use($reference):bool{try{$reference->resolve((int)($row['master_supplier_id']??0));return true;}catch(DomainException $e){return false;}}));
    }

    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM fournisseurs_transport WHERE id=?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $data=$this->reference()->bind($data);
        $sql = "INSERT INTO fournisseurs_transport (master_supplier_id, nom, telephone, whatsapp, actif, carburant_mode, carburant_valeur, frais_mode, frais_valeur, solde_mode, solde_valeur, notes) VALUES (:master_supplier_id,:nom,:telephone,:whatsapp,:actif,:c_mode,:c_val,:f_mode,:f_val,:s_mode,:s_val,:notes)";
        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':master_supplier_id'=>$data['master_supplier_id'],
            ':nom' => $data['nom'],
            ':telephone' => $data['telephone'] ?? null,
            ':whatsapp' => $data['whatsapp'] ?? null,
            ':actif' => isset($data['actif']) ? (int)$data['actif'] : 1,
            ':c_mode' => $data['carburant_mode'] ?? 'percent',
            ':c_val' => $data['carburant_valeur'] ?? 0,
            ':f_mode' => $data['frais_mode'] ?? 'fixed',
            ':f_val' => $data['frais_valeur'] ?? 0,
            ':s_mode' => $data['solde_mode'] ?? 'percent',
            ':s_val' => $data['solde_valeur'] ?? 0,
            ':notes' => $data['notes'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $current=$this->getById($id);
        if(!$current)return false;
        $data=$this->reference()->bind(array_replace($current,$data),$current);
        $sql = "UPDATE fournisseurs_transport SET master_supplier_id=:master_supplier_id, nom=:nom, telephone=:telephone, whatsapp=:whatsapp, actif=:actif, carburant_mode=:c_mode, carburant_valeur=:c_val, frais_mode=:f_mode, frais_valeur=:f_val, solde_mode=:s_mode, solde_valeur=:s_val, notes=:notes WHERE id=:id";
        $st = $this->pdo->prepare($sql);
        return $st->execute([
            ':id' => $id,
            ':master_supplier_id'=>$data['master_supplier_id'],
            ':nom' => $data['nom'],
            ':telephone' => $data['telephone'] ?? null,
            ':whatsapp' => $data['whatsapp'] ?? null,
            ':actif' => isset($data['actif']) ? (int)$data['actif'] : 1,
            ':c_mode' => $data['carburant_mode'] ?? 'percent',
            ':c_val' => $data['carburant_valeur'] ?? 0,
            ':f_mode' => $data['frais_mode'] ?? 'fixed',
            ':f_val' => $data['frais_valeur'] ?? 0,
            ':s_mode' => $data['solde_mode'] ?? 'percent',
            ':s_val' => $data['solde_valeur'] ?? 0,
            ':notes' => $data['notes'] ?? null,
        ]);
    }
}

