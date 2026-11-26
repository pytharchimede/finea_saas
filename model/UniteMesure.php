<?php
class UniteMesure
{
    private $pdo;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public function getAll()
    {
        $stmt = $this->pdo->query("SELECT * FROM unites_mesure ORDER BY libelle ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche d'unités (liste_unite).
     * Filtres supportés:
     * - q (texte): match sur symbole ou libellé
     */
    public function search(array $filtres = []): array
    {
        $sql = 'SELECT * FROM unites_mesure WHERE 1=1';
        $params = [];

        if (!empty($filtres['q'])) {
            $sql .= ' AND (symbole LIKE :q OR libelle LIKE :q)';
            $params['q'] = '%' . (string)$filtres['q'] . '%';
        }

        $sql .= ' ORDER BY libelle ASC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function usageReport(array $filters = []): array
    {
        $sql = 'SELECT u.id,u.symbole,u.libelle,u.description,COALESCE(l.usage_count,0) usage_count FROM unites_mesure u LEFT JOIN (SELECT unite_id,COUNT(*) usage_count FROM ligne_devis_banamur WHERE unite_id IS NOT NULL GROUP BY unite_id) l ON l.unite_id=u.id WHERE 1=1';
        $params=[];
        if (($filters['q']??'') !== '') {
            $sql .= ' AND (u.symbole LIKE :symbol OR u.libelle LIKE :label)';
            $params=['symbol'=>'%'.$filters['q'].'%','label'=>'%'.$filters['q'].'%'];
        }
        if (($filters['usage']??'')==='used') $sql.=' AND COALESCE(l.usage_count,0)>0';
        if (($filters['usage']??'')==='unused') $sql.=' AND COALESCE(l.usage_count,0)=0';
        $stmt=$this->pdo->prepare($sql.' ORDER BY u.libelle,u.id');
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function add($symbole, $libelle, ?string $description = null)
    {
        if ($description !== null) {
            return $this->pdo->prepare('INSERT INTO unites_mesure (symbole,libelle,description) VALUES (?,?,?)')->execute([$symbole,$libelle,$description]);
        }
        $stmt = $this->pdo->prepare("INSERT INTO unites_mesure (symbole, libelle) VALUES (:symbole, :libelle)");
        return $stmt->execute(['symbole' => $symbole, 'libelle' => $libelle]);
    }

    public function getById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM unites_mesure WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBySymbole($symbole)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM unites_mesure WHERE symbole = :symbole");
        $stmt->execute(['symbole' => $symbole]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function update($id, $symbole, $libelle, ?string $description = null)
    {
        if ($description !== null) {
            return $this->pdo->prepare('UPDATE unites_mesure SET symbole=?,libelle=?,description=? WHERE id=?')->execute([$symbole,$libelle,$description,$id]);
        }
        $stmt = $this->pdo->prepare("UPDATE unites_mesure SET symbole = :symbole, libelle = :libelle WHERE id = :id");
        return $stmt->execute(['id' => $id, 'symbole' => $symbole, 'libelle' => $libelle]);
    }
}
