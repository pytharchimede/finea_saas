<?php
declare(strict_types=1);

/** Rattrapage post-déploiement des fiches créées avant la synchronisation FEB/fiche. */
final class FebFicheAmountMigrationService
{
    private const KEY = 'feb_approved_fiche_values_v1_20260922';

    public static function apply(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS finea_data_migrations (migration_key VARCHAR(190) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, details_json LONGTEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->beginTransaction();
        try {
            $check = $pdo->prepare('SELECT details_json FROM finea_data_migrations WHERE migration_key=:key FOR UPDATE');
            $check->execute(['key' => self::KEY]);
            $previous = $check->fetchColumn();
            if ($previous !== false) {
                $pdo->commit();
                return ['already_applied' => true] + (json_decode((string)$previous, true) ?: []);
            }

            $rows = $pdo->query("SELECT f.id_fiche,f.num_fiche,f.feb_request_id,f.decaisse,f.montant_fiche,f.montant_initial_fiche,f.details_demande,r.title,r.payload_json
                FROM fiche_decaissement f
                JOIN employee_legal_requests r ON r.id=f.feb_request_id
                WHERE r.request_type='feb' AND r.status='approved' AND f.decaisse=0
                ORDER BY f.feb_request_id,f.id_fiche FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC);
            $updated = [];
            $skipped = [];
            $update = $pdo->prepare('UPDATE fiche_decaissement SET montant_fiche=:amount,montant_initial_fiche=:initial,details_demande=:details WHERE id_fiche=:id AND feb_request_id=:feb AND decaisse=0');
            $familyCount = $pdo->prepare('SELECT COUNT(*) FROM fiche_decaissement WHERE feb_request_id=:feb');
            $children = $pdo->prepare('SELECT 1 FROM fiche_decaissement WHERE id_fiche<>:id AND (parent_num_fiche=:parent_num OR code_parentale=:parent_code) LIMIT 1');
            foreach ($rows as $row) {
                $id = (int)$row['id_fiche'];
                $febId = (int)$row['feb_request_id'];
                $payload = json_decode((string)$row['payload_json'], true);
                $amount = is_array($payload) && isset($payload['montant']) && is_numeric($payload['montant'])
                    ? round((float)$payload['montant'], 2) : 0.0;
                if ($amount <= 0) {
                    $skipped[$id] = 'invalid_feb_amount';
                    continue;
                }
                $description = trim((string)($payload['description'] ?? ''));
                if ($description === '') $description = trim((string)$row['title']);
                if (abs((float)$row['montant_fiche'] - $amount) < 0.005
                    && $row['montant_initial_fiche'] !== null
                    && abs((float)$row['montant_initial_fiche'] - $amount) < 0.005
                    && trim((string)$row['details_demande']) === $description) continue;

                $familyCount->execute(['feb' => $febId]);
                if ((int)$familyCount->fetchColumn() !== 1) {
                    $skipped[$id] = 'multiple_linked_fiches';
                    continue;
                }
                $children->execute(['id' => $id, 'parent_num' => $row['num_fiche'], 'parent_code' => $row['num_fiche']]);
                if ($children->fetchColumn()) {
                    $skipped[$id] = 'child_fiches_exist';
                    continue;
                }
                if ($row['montant_initial_fiche'] !== null && (float)$row['montant_initial_fiche'] > (float)$row['montant_fiche'] + 0.005) {
                    $skipped[$id] = 'partial_payment';
                    continue;
                }

                $update->execute(['amount' => $amount, 'initial' => $amount, 'details' => $description, 'id' => $id, 'feb' => $febId]);
                if ($update->rowCount() !== 1) throw new RuntimeException('Échec de synchronisation de la fiche #' . $id);
                $updated[] = ['fiche_id' => $id, 'feb_id' => $febId, 'old_amount' => (float)$row['montant_fiche'], 'new_amount' => $amount];
            }
            $result = ['checked' => count($rows), 'updated' => $updated, 'skipped' => $skipped];
            $pdo->prepare('INSERT INTO finea_data_migrations(migration_key,details_json) VALUES(:key,:details)')
                ->execute(['key' => self::KEY, 'details' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
