<?php

require_once __DIR__ . '/../model/Database.php';

class CrmRdvRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: \Database::getConnection();
    }

    public function getById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT r.id, r.linked_type, r.prospect_id, r.client_id, r.titre, r.date_debut, r.date_fin, r.lieu, r.statut, r.description, r.created_by, r.created_at,
                    COALESCE(p.nom, c.nom_client) AS linked_name
             FROM crm_rdv_banamur r
             LEFT JOIN crm_prospect_banamur p ON p.id = r.prospect_id
             LEFT JOIN client_banamur c ON c.id_client = r.client_id
             WHERE r.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO crm_rdv_banamur
                (linked_type, prospect_id, client_id, titre, date_debut, date_fin, lieu, statut, description, created_by)
             VALUES
                (:linked_type, :prospect_id, :client_id, :titre, :date_debut, :date_fin, :lieu, :statut, :description, :created_by)"
        );
        $stmt->execute([
            'linked_type' => $data['linked_type'],
            'prospect_id' => $data['prospect_id'],
            'client_id' => $data['client_id'],
            'titre' => $data['titre'],
            'date_debut' => $data['date_debut'],
            'date_fin' => $data['date_fin'],
            'lieu' => $data['lieu'],
            'statut' => $data['statut'],
            'description' => $data['description'],
            'created_by' => $data['created_by'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countToday(): int
    {
        return (int) $this->pdo->query(
            "SELECT COUNT(*) FROM crm_rdv_banamur WHERE DATE(date_debut) = CURDATE() AND statut NOT IN ('Annule', 'Annulé')"
        )->fetchColumn();
    }

    public function countNextDays(int $days = 7): int
    {
        $days = max(1, min(365, $days));
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*)
             FROM crm_rdv_banamur
             WHERE date_debut >= NOW()
               AND date_debut < DATE_ADD(NOW(), INTERVAL :days DAY)
               AND statut NOT IN ('Annule', 'Annulé')"
        );
        $stmt->execute(['days' => $days]);
        return (int) $stmt->fetchColumn();
    }

    public function listUpcoming(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->query(
            "SELECT r.id, r.linked_type, r.prospect_id, r.client_id, r.titre, r.date_debut, r.date_fin, r.lieu, r.statut,
                    COALESCE(p.nom, c.nom_client) AS linked_name
             FROM crm_rdv_banamur r
             LEFT JOIN crm_prospect_banamur p ON p.id = r.prospect_id
             LEFT JOIN client_banamur c ON c.id_client = r.client_id
             WHERE r.date_debut >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             ORDER BY r.date_debut ASC
             LIMIT {$limit}"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
