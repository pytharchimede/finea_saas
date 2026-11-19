<?php
require_once __DIR__ . '/../services/ProjectNumberingService.php';
class Offre
{
    private $pdo;
    private ProjectNumberingService $numbering;
    private int $lastCreatedId = 0;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->numbering = new ProjectNumberingService($pdo);
        $this->numbering->ensureAndMigrate();
    }

    public function getAllOffres()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM offre_banamur');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche avancée pour la liste des offres.
     * Filtres supportés:
     * - q (texte): num_offre / reference_offre / commercial_dedie
     * - commercial_dedie (string exact)
     * - date_offre_from / date_offre_to (YYYY-MM-DD)
     * - created_from / created_to (YYYY-MM-DD) sur date_creat_offre
     */
    public function searchOffres(array $filtres = []): array
    {
        $sql = 'SELECT * FROM offre_banamur WHERE 1=1';
        $params = [];

        if (isset($filtres['commercial_dedie']) && $filtres['commercial_dedie'] !== null && trim((string)$filtres['commercial_dedie']) !== '') {
            $sql .= ' AND commercial_dedie = :commercial_dedie';
            $params['commercial_dedie'] = (string)$filtres['commercial_dedie'];
        }

        if (!empty($filtres['date_offre_from'])) {
            $sql .= ' AND date_offre >= :date_offre_from';
            $params['date_offre_from'] = (string)$filtres['date_offre_from'];
        }
        if (!empty($filtres['date_offre_to'])) {
            $sql .= ' AND date_offre <= :date_offre_to';
            $params['date_offre_to'] = (string)$filtres['date_offre_to'];
        }

        if (!empty($filtres['created_from'])) {
            $sql .= ' AND date_creat_offre >= :created_from';
            $params['created_from'] = (string)$filtres['created_from'];
        }
        if (!empty($filtres['created_to'])) {
            $sql .= ' AND date_creat_offre <= :created_to';
            $params['created_to'] = (string)$filtres['created_to'];
        }

        if (!empty($filtres['q'])) {
            $sql .= ' AND ('
                . 'num_offre LIKE :q '
                . 'OR reference_offre LIKE :q '
                . 'OR commercial_dedie LIKE :q '
                . 'OR EXISTS (SELECT 1 FROM project_number_history pnh '
                . 'WHERE pnh.project_id = offre_banamur.id_offre AND pnh.old_number LIKE :legacy_q)'
                . ')';
            $params['q'] = '%' . (string)$filtres['q'] . '%';
            $params['legacy_q'] = '%' . (string)$filtres['q'] . '%';
        }

        $sql .= ' ORDER BY id_offre DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDistinctCommercials(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT TRIM(commercial_dedie) AS v FROM offre_banamur WHERE commercial_dedie IS NOT NULL AND TRIM(commercial_dedie) <> '' ORDER BY v ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $rows = array_values(array_filter(array_map('trim', array_map('strval', $rows)), static fn($v) => $v !== ''));
        return $rows;
    }

    public function ajouterOffre($data)
    {
        $data['client_id'] = (int)($data['client_id'] ?? 0) ?: null;
        $data['num_offre'] = $this->numbering->nextNumber((string)($data['date_offre'] ?? ''));
        $sql = "INSERT INTO offre_banamur (num_offre, date_offre, reference_offre, commercial_dedie, date_creat_offre, client_id)
            VALUES (:num_offre, :date_offre, :reference_offre, :commercial_dedie, :date_creat_offre, :client_id)";
        $stmt = $this->pdo->prepare($sql);
        $ok=(bool)$stmt->execute($data);
        if($ok){
            $this->lastCreatedId=(int)$this->pdo->lastInsertId();
            $this->numbering->recordCreatedProject($this->lastCreatedId,(string)$data['num_offre'],(string)($data['date_offre']??''));
        }
        return $ok;
    }

    public function getLastCreatedId(): int
    {
        return $this->lastCreatedId;
    }

    public function updateOffreById(int $id, array $data): bool
    {
        $data['client_id'] = (int)($data['client_id'] ?? 0) ?: null;
        $current=$this->getOffreById($id);
        if(!$current) return false;
        $data['num_offre']=(string)$current['num_offre'];
        $sql = "UPDATE offre_banamur
            SET num_offre = :num_offre,
                date_offre = :date_offre,
                reference_offre = :reference_offre,
                commercial_dedie = :commercial_dedie,
                date_creat_offre = :date_creat_offre,
                client_id = :client_id
            WHERE id_offre = :id_offre";

        $stmt = $this->pdo->prepare($sql);
        $data['id_offre'] = $id;
        return (bool)$stmt->execute($data);
    }

    public function getOffreById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM offre_banamur WHERE id_offre = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function ajouterFichiersOffre($offreId, $files)
    {
        $uploadDir = '../uploads/offres/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

        foreach ($files['tmp_name'] as $key => $tmpName) {
            $fileName = basename($files['name'][$key]);
            $targetFile = $uploadDir . uniqid() . '_' . $fileName;
            if (move_uploaded_file($tmpName, $targetFile)) {
                $stmt = $this->pdo->prepare("INSERT INTO offre_fichiers_banamur (offre_id, file_path, file_name) VALUES (:offre_id, :file_path, :file_name)");
                $stmt->execute([
                    'offre_id' => $offreId,
                    'file_path' => $targetFile,
                    'file_name' => $fileName
                ]);
            }
        }
    }

    public function getFichiersByOffre($offreId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM offre_fichiers_banamur WHERE offre_id = :offre_id");
        $stmt->execute(['offre_id' => $offreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
