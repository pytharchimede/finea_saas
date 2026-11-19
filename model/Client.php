<?php
require_once __DIR__.'/../services/ClientIdentityMigration.php';
require_once __DIR__.'/../services/ClientBusinessPolicy.php';
class Client
{
    private $pdo;
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        (new ClientIdentityMigration($pdo))->install();
    }
    public function getAllClients()
    {
        $stmt = $this->pdo->prepare('SELECT * FROM client_banamur');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche avancée pour la liste des clients.
     * Filtres supportés:
    * - q (texte): NCC/nom/code/téléphone/localisation/commune/bp/pays
     * - pays (string exact)
     * - commune (string exact)
     * - created_from / created_to (YYYY-MM-DD) sur date_creat_client si présente
     * - status: verified ou pending (statut de vérification FNE)
     */
    public function searchClients(array $filtres = []): array
    {
        $sql = 'SELECT * FROM client_banamur WHERE 1=1';
        $params = [];

        if (isset($filtres['pays']) && $filtres['pays'] !== null && trim((string)$filtres['pays']) !== '') {
            $sql .= ' AND pays_client = :pays';
            $params['pays'] = (string)$filtres['pays'];
        }

        if (isset($filtres['commune']) && $filtres['commune'] !== null && trim((string)$filtres['commune']) !== '') {
            $sql .= ' AND commune_client = :commune';
            $params['commune'] = (string)$filtres['commune'];
        }

        // Date de création (si champ existant dans la table)
        if (!empty($filtres['created_from'])) {
            $sql .= ' AND date_creat_client >= :created_from';
            $params['created_from'] = (string)$filtres['created_from'];
        }
        if (!empty($filtres['created_to'])) {
            $sql .= ' AND date_creat_client <= :created_to';
            $params['created_to'] = (string)$filtres['created_to'];
        }

        if (($filtres['status'] ?? '') === 'verified') {
            $sql .= ' AND '.ClientBusinessPolicy::sqlUsable();
        } elseif (($filtres['status'] ?? '') === 'pending') {
            $sql .= ' AND NOT '.ClientBusinessPolicy::sqlUsable();
        }

        if (!empty($filtres['q'])) {
            $sql .= ' AND ('
                . 'nom_client LIKE :q '
                . 'OR code_client LIKE :q '
                . 'OR ncc LIKE :q OR registration_number LIKE :q OR vat_number LIKE :q OR siret LIKE :q '
                . 'OR telephone_client LIKE :q '
                . 'OR localisation_client LIKE :q '
                . 'OR commune_client LIKE :q '
                . 'OR bp_client LIKE :q '
                . 'OR pays_client LIKE :q'
                . ')';
            $params['q'] = '%' . (string)$filtres['q'] . '%';
        }

        $sql .= ' ORDER BY id_client DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getDistinctPays(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT TRIM(pays_client) AS v FROM client_banamur WHERE pays_client IS NOT NULL AND TRIM(pays_client) <> '' ORDER BY v ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $rows = array_values(array_filter(array_map('trim', array_map('strval', $rows)), static fn($v) => $v !== ''));
        return $rows;
    }

    public function getDistinctCommunes(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT TRIM(commune_client) AS v FROM client_banamur WHERE commune_client IS NOT NULL AND TRIM(commune_client) <> '' ORDER BY v ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $rows = array_values(array_filter(array_map('trim', array_map('strval', $rows)), static fn($v) => $v !== ''));
        return $rows;
    }

    public function ajouterClient($data)
    {
        require_once __DIR__.'/../services/ClientIdentityService.php';
        (new ClientIdentityService($this->pdo))->save($data,null,(int)($_SESSION['user_id']??0));
        return true;
    }

    public function updateClientById(int $id, array $data): bool
    {
        require_once __DIR__.'/../services/ClientIdentityService.php';
        (new ClientIdentityService($this->pdo))->save($data,$id,(int)($_SESSION['user_id']??0));
        return true;
    }

    public function getClientById($id)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM client_banamur WHERE id_client = :id");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}


