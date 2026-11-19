<?php
declare(strict_types=1);

final class DevisOfferProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function classifications(array $devisIds): array
    {
        if (!$devisIds) {
            return [];
        }
        $ids = implode(',', array_map('intval', $devisIds));
        $rows = $this->pdo->query(
            "SELECT c.*, s.principal_devis_id
             FROM devis_offer_classifications c
             LEFT JOIN devis_offer_project_settings s ON s.offre_id = c.offre_id
             WHERE c.devis_id IN ($ids)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $classifications = [];
        foreach ($rows as $row) {
            $classifications[(int) $row['devis_id']] = $row;
        }
        return $classifications;
    }

    public function offerLabels(array $offerIds): array
    {
        $offerIds = array_filter(array_unique(array_map('intval', $offerIds)));
        if (!$offerIds) {
            return [];
        }
        $ids = implode(',', $offerIds);
        $rows = $this->pdo->query(
            "SELECT id_offre, num_offre, reference_offre FROM offre_banamur WHERE id_offre IN ($ids)"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $offers = [];
        foreach ($rows as $row) {
            $offers[(int) $row['id_offre']] = $row;
        }
        return $offers;
    }

    public function lockDevis(int $id): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, offre_id, numero_devis FROM devis_banamur WHERE id = :id'
        );
        $statement->execute(['id' => $id]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function lockSettings(int $offerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM devis_offer_project_settings WHERE offre_id = :offer_id FOR UPDATE'
        );
        $statement->execute(['offer_id' => $offerId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function principalForOffer(int $offerId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT principal_devis_id FROM devis_offer_project_settings WHERE offre_id = :offer_id'
        );
        $statement->execute(['offer_id' => $offerId]);
        return (int) ($statement->fetchColumn() ?: 0);
    }

    public function clientForOffer(int $offerId): int
    {
        $statement=$this->pdo->prepare('SELECT client_id FROM offre_banamur WHERE id_offre=:offer_id LIMIT 1');
        $statement->execute(['offer_id'=>$offerId]);
        return (int)($statement->fetchColumn() ?: 0);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function persistenceSnapshot(int $devisId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT d.id, d.numero_devis, d.offre_id, d.client_id, d.total_ttc,
                    COUNT(ld.id) AS line_count
             FROM devis_banamur d
             LEFT JOIN ligne_devis_banamur ld ON ld.devis_id=d.id
             WHERE d.id=:devis_id
             GROUP BY d.id, d.numero_devis, d.offre_id, d.client_id, d.total_ttc'
        );
        $statement->execute(['devis_id' => $devisId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function ensureSettings(int $offerId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT IGNORE INTO devis_offer_project_settings (offre_id, updated_by_user_id)
             VALUES (:offer_id, :user_id)'
        );
        $statement->execute(['offer_id' => $offerId, 'user_id' => $userId]);
    }

    public function upsertClassification(int $devisId, int $offerId, string $type, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO devis_offer_classifications
                (devis_id, offre_id, devis_type, classified_by_user_id)
             VALUES (:devis_id, :offer_id, :devis_type, :user_id)
             ON DUPLICATE KEY UPDATE
                offre_id = VALUES(offre_id),
                devis_type = VALUES(devis_type),
                classified_by_user_id = VALUES(classified_by_user_id)'
        );
        $statement->execute([
            'devis_id' => $devisId,
            'offer_id' => $offerId,
            'devis_type' => $type,
            'user_id' => $userId,
        ]);
    }

    public function setPrincipal(int $offerId, ?int $devisId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE devis_offer_project_settings
             SET principal_devis_id = :devis_id, updated_by_user_id = :user_id
             WHERE offre_id = :offer_id'
        );
        $statement->execute([
            'devis_id' => $devisId,
            'user_id' => $userId,
            'offer_id' => $offerId,
        ]);
    }

    public function demoteOtherPrincipal(int $offerId, int $exceptDevisId, int $userId): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE devis_offer_classifications
             SET devis_type = 'TS', classified_by_user_id = :user_id
             WHERE offre_id = :offer_id
               AND devis_id <> :except_devis_id
               AND devis_type = 'PRINCIPAL'"
        );
        $statement->execute([
            'user_id' => $userId,
            'offer_id' => $offerId,
            'except_devis_id' => $exceptDevisId,
        ]);
    }

    public function linkTsToPrincipalChantier(int $devisId,int $offerId): ?int
    {
        $stmt=$this->pdo->prepare('SELECT c.id FROM devis_offer_project_settings s INNER JOIN chantier_banamur c ON c.devis_id=s.principal_devis_id AND c.archived_at IS NULL WHERE s.offre_id=:offer_id ORDER BY c.id DESC LIMIT 1');
        $stmt->execute(['offer_id'=>$offerId]);$chantierId=(int)$stmt->fetchColumn();
        if($chantierId<1)return null;
        $this->pdo->prepare("INSERT INTO devis_chantier_links(devis_id,chantier_id,link_type) VALUES(:devis,:chantier,'TS') ON DUPLICATE KEY UPDATE chantier_id=VALUES(chantier_id),link_type='TS'")->execute(['devis'=>$devisId,'chantier'=>$chantierId]);
        return $chantierId;
    }

    public function begin(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
