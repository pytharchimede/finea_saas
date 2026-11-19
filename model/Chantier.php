<?php
class Chantier
{
    private $pdo;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    public static function ensureArchiveColumns(PDO $pdo): void
    {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM chantier_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
            if (!$cols) return;

            $has = function (string $name) use ($cols): bool {
                return in_array($name, $cols, true);
            };

            if (!$has('archived_at')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
            }
            if (!$has('archived_by')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN archived_by INT NULL DEFAULT NULL");
            }
            if (!$has('archived_note')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN archived_note TEXT NULL");
            }
            if (!$has('closed_date')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN closed_date DATE NULL DEFAULT NULL");
            }
            if (!$has('paused_at')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN paused_at DATETIME NULL DEFAULT NULL");
            }
            if (!$has('paused_by')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN paused_by INT NULL DEFAULT NULL");
            }
            if (!$has('pause_note')) {
                $pdo->exec("ALTER TABLE chantier_banamur ADD COLUMN pause_note TEXT NULL");
            }

            // Index utile pour filtrer rapidement les chantiers actifs
            try {
                $pdo->exec("CREATE INDEX idx_chantier_archived_at ON chantier_banamur (archived_at)");
            } catch (Throwable $e) {
                // ignore (index déjà présent ou non supporté)
            }
        } catch (Throwable $e) {
            // Ne pas bloquer l'app si le compte SQL ne peut pas ALTER
            error_log('[chantier] ensureArchiveColumns failed: ' . $e->getMessage());
        }
    }

    public function getLastIndex()
    {
        $stmt = $this->pdo->query("SELECT MAX(id) as max_id FROM chantier_banamur");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? intval($row['max_id']) : 0;
    }

    public function add($data)
    {
        require_once __DIR__ . '/../services/ChantierCreationGuardService.php';
        (new ChantierCreationGuardService($this->pdo))->validate((int) ($data['devis_id'] ?? 0));
        $stmt = $this->pdo->prepare("INSERT INTO chantier_banamur (titre, code, devis_id, client_id) VALUES (:titre, :code, :devis_id, :client_id)");
        $stmt->execute([
            'titre' => $data['titre'],
            'code' => $data['code'],
            'devis_id' => $data['devis_id'],
            'client_id' => $data['client_id']
        ]);
        return $this->pdo->lastInsertId();
    }

    public function getById($id)
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->prepare("SELECT * FROM chantier_banamur WHERE id = ? AND archived_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getByIdIncludingArchived($id)
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->prepare("SELECT * FROM chantier_banamur WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll()
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->query("SELECT * FROM chantier_banamur WHERE archived_at IS NULL ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllActive(): array
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->query("SELECT * FROM chantier_banamur WHERE archived_at IS NULL ORDER BY id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAllArchived(): array
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->query("SELECT * FROM chantier_banamur WHERE archived_at IS NOT NULL ORDER BY archived_at DESC, id DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Recherche/listing des chantiers (utilisée par liste_chantier + recherche temps réel).
     * Filtres supportés:
     * - q (texte): titre / code / client / devis / offre
     * - client_id (int)
     * - archives (bool/int): 0=actifs, 1=archives
     */
    public function searchChantiers(array $filtres = []): array
    {
        self::ensureArchiveColumns($this->pdo);

        $hasArchivedAt = false;
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasArchivedAt = $cols && in_array('archived_at', $cols, true);
        } catch (Throwable $e) {
            $hasArchivedAt = false;
        }

        $archives = !empty($filtres['archives']);

        $sql = "SELECT c.*,\n"
            . "       cl.nom_client AS client_nom,\n"
            . "       d.numero_devis AS devis_numero,\n"
            . "       o.reference_offre AS offre_ref\n"
            . "FROM chantier_banamur c\n"
            . "LEFT JOIN client_banamur cl ON cl.id_client = c.client_id\n"
            . "LEFT JOIN devis_banamur d ON d.id = c.devis_id\n"
            . "LEFT JOIN offre_banamur o ON o.id_offre = d.offre_id\n"
            . "WHERE 1=1";

        $params = [];

        if ($hasArchivedAt) {
            $sql .= $archives ? " AND c.archived_at IS NOT NULL" : " AND c.archived_at IS NULL";
        }

        if (!empty($filtres['client_id'])) {
            $sql .= " AND c.client_id = :client_id";
            $params['client_id'] = (int)$filtres['client_id'];
        }

        if (!empty($filtres['q'])) {
            $sql .= " AND (c.titre LIKE :q"
                . " OR c.code LIKE :q"
                . " OR cl.nom_client LIKE :q"
                . " OR d.numero_devis LIKE :q"
                . " OR o.reference_offre LIKE :q"
                . ")";
            $params['q'] = '%' . (string)$filtres['q'] . '%';
        }

        if ($hasArchivedAt && $archives) {
            $sql .= " ORDER BY c.archived_at DESC, c.id DESC";
        } else {
            $sql .= " ORDER BY c.id DESC";
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liste des clients (distincts) présents dans les chantiers actifs/archivés.
     */
    public function getDistinctClients(?bool $archives = null): array
    {
        self::ensureArchiveColumns($this->pdo);

        $hasArchivedAt = false;
        try {
            $cols = $this->pdo->query("SHOW COLUMNS FROM chantier_banamur")->fetchAll(PDO::FETCH_COLUMN, 0);
            $hasArchivedAt = $cols && in_array('archived_at', $cols, true);
        } catch (Throwable $e) {
            $hasArchivedAt = false;
        }

        $sql = "SELECT DISTINCT cl.id_client, cl.nom_client\n"
            . "FROM chantier_banamur c\n"
            . "JOIN client_banamur cl ON cl.id_client = c.client_id\n";

        if ($hasArchivedAt && $archives !== null) {
            $sql .= "WHERE " . ($archives ? "c.archived_at IS NOT NULL" : "c.archived_at IS NULL") . "\n";
        }

        $sql .= "ORDER BY cl.nom_client ASC";

        $stmt = $this->pdo->query($sql);
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function existePourDevis($devisId)
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM chantier_banamur WHERE devis_id = ? AND archived_at IS NULL");
        $stmt->execute([$devisId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['count'] > 0;
    }

    public function getByDevisId($devisId)
    {
        self::ensureArchiveColumns($this->pdo);
        $stmt = $this->pdo->prepare("SELECT * FROM chantier_banamur WHERE devis_id = ? AND archived_at IS NULL");
        $stmt->execute([$devisId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
