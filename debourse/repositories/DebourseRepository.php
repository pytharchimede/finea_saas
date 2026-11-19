<?php

declare(strict_types=1);

namespace Debourse\Repositories;

use PDO;

require_once dirname(__DIR__, 2) . '/services/FinancialPlanningSeparationService.php';
require_once dirname(__DIR__, 2) . '/services/DisbursementPlanningIntegrityService.php';
require_once dirname(__DIR__, 2) . '/services/DevisOfferProjectMigrationService.php';

class DebourseRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        \DisbursementPlanningIntegrityService::ensure($pdo);
        (new \DevisOfferProjectMigrationService($pdo))->ensureSchema();
    }

    public function dashboardStats(): array
    {
        $official = \FinancialPlanningSeparationService::officialDeboursePredicate('d');
        $stats = [
            'debourses' => 0,
            'devis' => 0,
            'montant' => 0.0,
            'sous_lignes' => 0,
            'linked' => 0,
            'overruns' => 0,
            'orphans' => 0,
        ];

        $row = $this->pdo->query("
            SELECT COUNT(*) AS debourses,
                   COUNT(DISTINCT devis_id) AS devis,
                   COALESCE(SUM(montant_debourse), 0) AS montant
            FROM debourse_banamur d
            WHERE (
                NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id)
                OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL)
            )
              AND $official
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['debourses'] = (int)($row['debourses'] ?? 0);
        $stats['devis'] = (int)($row['devis'] ?? 0);
        $stats['montant'] = (float)($row['montant'] ?? 0);

        $row = $this->pdo->query('SELECT COUNT(DISTINCT l.id) AS c FROM ligne_debourse_banamur l INNER JOIN debourse_banamur d ON d.id=l.debourse_id WHERE (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL)) AND ' . $official)->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['sous_lignes'] = (int)($row['c'] ?? 0);

        $row = $this->pdo->query("
            SELECT COUNT(DISTINCT l.id) AS c
            FROM ligne_debourse_banamur l
            INNER JOIN fiche_decaissement f ON f.ligne_debourse_id = l.id
            LEFT JOIN chantier_banamur c ON c.id=f.chantier_id
            WHERE f.chantier_id IS NULL OR f.chantier_id=0 OR c.archived_at IS NULL
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['linked'] = (int)($row['c'] ?? 0);

        $row = $this->pdo->query("
            SELECT COUNT(*) AS c
            FROM debourse_banamur d
            INNER JOIN ligne_devis_banamur ld ON ld.id = d.ligne_devis_id
            WHERE d.montant_debourse > COALESCE(ld.total, ld.prix * ld.quantite, ld.prix, 0)
              AND COALESCE(ld.total, ld.prix * ld.quantite, ld.prix, 0) > 0
              AND (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['overruns'] = (int)($row['c'] ?? 0);

        $row = $this->pdo->query("
            SELECT COUNT(*) AS c
            FROM debourse_banamur d
            LEFT JOIN ligne_devis_banamur ld ON ld.id = d.ligne_devis_id
            WHERE ld.id IS NULL
              AND (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['orphans'] = (int)($row['c'] ?? 0);

        return $stats;
    }

    public function recentDebourses(int $limit = 8): array
    {
        $official = \FinancialPlanningSeparationService::officialDeboursePredicate('d');
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.devis_id, d.ligne_devis_id, d.montant_debourse, d.date_debut, d.date_fin,
                   COALESCE(d.updated_at, d.date_modif, d.created_at) AS last_modified_at,
                   dv.numero_devis, dv.destine_a,
                   ld.designation,
                   COUNT(l.id) AS sous_lignes
            FROM debourse_banamur d
            LEFT JOIN devis_banamur dv ON dv.id = d.devis_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = d.ligne_devis_id
            LEFT JOIN ligne_debourse_banamur l ON l.debourse_id = d.id
            WHERE (
                NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id)
                OR EXISTS (SELECT 1 FROM chantier_banamur c_active WHERE c_active.devis_id=d.devis_id AND c_active.archived_at IS NULL)
            )
              AND $official
            GROUP BY d.id, d.devis_id, d.ligne_devis_id, d.montant_debourse, d.date_debut, d.date_fin,
                     d.updated_at, d.date_modif, d.created_at,
                     dv.numero_devis, dv.destine_a, ld.designation
            ORDER BY d.updated_at DESC, d.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function orphanDebourses(int $limit = 6): array
    {
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.devis_id, d.ligne_devis_id, d.montant_debourse, dv.numero_devis
            FROM debourse_banamur d
            LEFT JOIN ligne_devis_banamur ld ON ld.id = d.ligne_devis_id
            LEFT JOIN devis_banamur dv ON dv.id = d.devis_id
            WHERE ld.id IS NULL
              AND (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL))
            ORDER BY d.id DESC
            LIMIT :limit
        ");
        $stmt->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listDebourses(?int $chantierId, string $search, string $client): array
    {
        $official = \FinancialPlanningSeparationService::officialDeboursePredicate('d');
        $sql = "
            SELECT d.*, dv.numero_devis, dv.destine_a, c.titre, ld.designation, cl.nom_client,
                   COUNT(ldb.id) AS sous_lignes,
                   COALESCE(SUM(ldb.montant), 0) AS sous_lignes_total
            FROM debourse_banamur d
            INNER JOIN devis_banamur dv ON dv.id = d.devis_id
            LEFT JOIN ligne_devis_banamur ld ON ld.id = d.ligne_devis_id
            LEFT JOIN client_banamur cl ON cl.id_client = dv.client_id
            LEFT JOIN devis_chantier_links dcl ON dcl.devis_id=d.devis_id
            LEFT JOIN chantier_banamur c ON (c.devis_id = d.devis_id OR c.id=dcl.chantier_id)
            LEFT JOIN ligne_debourse_banamur ldb ON ldb.debourse_id = d.id
            WHERE c.archived_at IS NULL
              AND $official
        ";
        $params = [];

        if ($chantierId !== null && $chantierId > 0) {
            $sql .= ' AND c.id = :chantier_id';
            $params['chantier_id'] = $chantierId;
        }
        if ($search !== '') {
            $sql .= ' AND (ld.designation LIKE :search OR dv.numero_devis LIKE :search OR d.id LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($client !== '') {
            $sql .= ' AND (cl.nom_client LIKE :client OR dv.destine_a LIKE :client)';
            $params['client'] = '%' . $client . '%';
        }

        $sql .= "
            GROUP BY d.id, d.devis_id, d.ligne_devis_id, d.montant_debourse, d.responsable_id,
                     d.date_debut, d.date_fin, d.motif_modif, d.date_modif, d.created_at, d.updated_at,
                     dv.numero_devis, dv.destine_a, c.titre, ld.designation, cl.nom_client
            ORDER BY d.id DESC
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function activeChantiers(): array
    {
        try {
            $hasArchivedAt = (bool) $this->pdo->query("SHOW COLUMNS FROM chantier_banamur LIKE 'archived_at'")->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $hasArchivedAt = false;
        }

        $sql = 'SELECT id, titre FROM chantier_banamur';
        if ($hasArchivedAt) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY titre';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function activeClients(): array
    {
        $stmt = $this->pdo->query("
            SELECT DISTINCT cl.id_client, cl.nom_client
            FROM client_banamur cl
            INNER JOIN devis_banamur dv ON dv.client_id = cl.id_client
            INNER JOIN debourse_banamur d ON d.devis_id = dv.id
            WHERE cl.nom_client IS NOT NULL AND TRIM(cl.nom_client) <> ''
            ORDER BY cl.nom_client
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
