<?php
// model/Devis.php
require_once __DIR__ . '/AuditTrail.php';
require_once __DIR__ . '/../services/FebCategoryMigrationService.php';
require_once __DIR__ . '/../services/FinancialPlanningSeparationService.php';

class Devis
{
    private $pdo;
    private static bool $maskMetadataReady = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        // Les migrations de schéma/données ne doivent jamais s'exécuter dans
        // le chemin chaud d'une requête HTTP. Elles sont lancées explicitement
        // par scripts/production_maintenance.php après déploiement.
    }

    // Validation générale (directeur général)
    public function validerGenerale($devisId)
    {
        $snapshot = $this->getDevisAuditSnapshot((int) $devisId);
        $sql = "UPDATE devis_banamur SET validation_generale = 1 WHERE id = :devisId";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':devisId', $devisId, PDO::PARAM_INT);
        $result = $stmt->execute();
        if ($result) {
            $this->logDevisEvent('devis_validation_generale', 'validation_generale', $snapshot, [
                'validation_generale' => true,
            ]);
        }
        return $result;
    }

    // Validation technique (directeur technique)
    public function validerTechnique($devisId)
    {
        $snapshot = $this->getDevisAuditSnapshot((int) $devisId);
        $sql = "UPDATE devis_banamur SET validation_technique = 1 WHERE id = :devisId";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':devisId', $devisId, PDO::PARAM_INT);
        $result = $stmt->execute();
        if ($result) {
            $this->logDevisEvent('devis_validation_technique', 'validation_technique', $snapshot, [
                'validation_technique' => true,
            ]);
        }
        return $result;
    }

    // Vérifie si la validation générale a été effectuée
    public function isValidGenerale($devisId)
    {
        $sql = "SELECT validation_generale FROM devis_banamur WHERE id = :devisId";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':devisId', $devisId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($result['validation_generale']) && $result['validation_generale'] == 1;
    }

    // Vérifie si la validation technique a été effectuée
    public function isValidTechnique($devisId)
    {
        $sql = "SELECT validation_technique FROM devis_banamur WHERE id = :devisId";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindParam(':devisId', $devisId, PDO::PARAM_INT);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return isset($result['validation_technique']) && $result['validation_technique'] == 1;
    }

    public function getAllDevis()
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*
             FROM devis_banamur d
             WHERE COALESCE(d.masque, 0) = 0
               AND NOT EXISTS (
                   SELECT 1 FROM devis_archives a
                   WHERE a.devis_id = d.id AND a.restored_at IS NULL
               )
               AND (
                   NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id = d.id)
                   OR EXISTS (
                       SELECT 1 FROM chantier_banamur c
                       WHERE c.devis_id = d.id AND c.archived_at IS NULL
                   )
               )'
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNextCode()
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) as nb FROM devis_banamur');
        $stmt->execute();
        $nb_devis = $stmt->fetch(PDO::FETCH_ASSOC)['nb'];
        $index_actuel = $nb_devis + 1;
        return 'BAN-DEV-PAB-' . $index_actuel;
    }

    public function getDevisFiltres($filtres = [])
    {
        $sql = 'SELECT d.* FROM devis_banamur d
                WHERE COALESCE(d.masque, 0) = 0
                  AND NOT EXISTS (SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)
                  AND (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))';
        $params = [];

        if (!empty($filtres['date_debut'])) {
            $sql .= ' AND date_emission >= :date_debut';
            $params['date_debut'] = $filtres['date_debut'];
        }
        if (!empty($filtres['date_fin'])) {
            $sql .= ' AND date_emission <= :date_fin';
            $params['date_fin'] = $filtres['date_fin'];
        }
        if (!empty($filtres['emis_par'])) {
            $sql .= ' AND emis_par LIKE :emis_par';
            $params['emis_par'] = '%' . $filtres['emis_par'] . '%';
        }
        if (!empty($filtres['destine_a'])) {
            $sql .= ' AND destine_a LIKE :destine_a';
            $params['destine_a'] = '%' . $filtres['destine_a'] . '%';
        }

        $sql .= ' ORDER BY id DESC';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Recherche avancée pour la liste des devis (utilisée par le filtrage temps réel).
     * Filtres supportés:
     * - q (texte): numéro devis / correspondant / client / offre
     * - client_id (int)
     * - correspondant (string exact)
     * - validation: ''|'non'|'tech'|'dg'|'valid'
     * - chantier: ''|'0'|'1' (0 = non débuté, 1 = débuté)
     * - created_from / created_to (YYYY-MM-DD)
     */
    public function searchDevis(array $filtres = []): array
    {
        $sql = 'SELECT d.* FROM devis_banamur d WHERE 1=1';
        $params = [];

        $billingStatus=(string)($filtres['billing_status']??'');
        $archiveStatus = (string) ($filtres['archive_status'] ?? 'active');
        if ($billingStatus==='pending' && $archiveStatus==='active') $archiveStatus='all';
        if ($archiveStatus === 'masked') {
            $sql .= ' AND COALESCE(d.masque, 0) = 1';
        } elseif ($archiveStatus === 'archived') {
            $sql .= ' AND EXISTS (SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)';
        } elseif ($archiveStatus === 'all') {
            // Vue de contrôle : inclut les actifs, masqués et archivés.
        } else {
            $sql .= ' AND COALESCE(d.masque, 0) = 0';
            $sql .= ' AND NOT EXISTS (SELECT 1 FROM devis_archives a WHERE a.devis_id=d.id AND a.restored_at IS NULL)';
            $sql .= ' AND (NOT EXISTS (SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS (SELECT 1 FROM chantier_banamur c_active WHERE c_active.devis_id=d.id AND c_active.archived_at IS NULL))';
        }

        if ($billingStatus === 'pending') {
            $tableCheck=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="treasury_invoice_collection_cancellations"');
            $tableCheck->execute();
            $cancelFilter=(int)$tableCheck->fetchColumn()>0
                ? ' AND NOT EXISTS (SELECT 1 FROM treasury_invoice_collection_cancellations tic WHERE tic.invoice_id=fv_pending.id_facture_vente)'
                : '';
            $sql .= " AND EXISTS (
                SELECT 1 FROM facture_vente_banamur fv_pending
                JOIN chantier_banamur c_pending ON c_pending.id=fv_pending.chantier_id
                WHERE c_pending.devis_id=d.id
                  AND LOWER(TRIM(COALESCE(fv_pending.paiement_statut,'emise')))='emise'
                  $cancelFilter
            )";
        }

        // Client
        if (!empty($filtres['client_id'])) {
            $sql .= ' AND d.client_id = :client_id';
            $params['client_id'] = (int)$filtres['client_id'];
        }

        // Correspondant (sélection)
        if (isset($filtres['correspondant']) && $filtres['correspondant'] !== '' && $filtres['correspondant'] !== null) {
            $sql .= ' AND d.correspondant = :correspondant';
            $params['correspondant'] = (string)$filtres['correspondant'];
        }

        // Validation
        if (!empty($filtres['validation'])) {
            $v = (string)$filtres['validation'];
            if ($v === 'non') {
                $sql .= ' AND (d.validation_technique = 0 AND d.validation_generale = 0)';
            } elseif ($v === 'tech') {
                $sql .= ' AND (d.validation_technique = 1 AND d.validation_generale = 0)';
            } elseif ($v === 'dg') {
                $sql .= ' AND (d.validation_generale = 1 AND d.validation_technique = 0)';
            } elseif ($v === 'valid') {
                $sql .= ' AND (d.validation_technique = 1 AND d.validation_generale = 1)';
            }
        }

        // Chantier débuté ? (existence chantier)
        if (array_key_exists('chantier', $filtres) && $filtres['chantier'] !== '' && $filtres['chantier'] !== null) {
            $want = (string)$filtres['chantier'];
            if ($want === '1') {
                $sql .= ' AND EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id = d.id AND c.archived_at IS NULL)';
            } elseif ($want === '0') {
                $sql .= ' AND NOT EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id = d.id AND c.archived_at IS NULL)';
            }
        }

        // Date de création (intervalle)
        if (!empty($filtres['created_from'])) {
            $sql .= ' AND d.created_at >= :created_from_dt';
            $params['created_from_dt'] = (string)$filtres['created_from'] . ' 00:00:00';
        }
        if (!empty($filtres['created_to'])) {
            // strictement avant le lendemain (inclut toute la journée)
            $sql .= ' AND d.created_at < DATE_ADD(:created_to_date, INTERVAL 1 DAY)';
            $params['created_to_date'] = (string)$filtres['created_to'];
        }

        // Recherche texte
        if (!empty($filtres['q'])) {
            $sql .= ' AND ('
                . 'd.numero_devis LIKE :q '
                . 'OR d.correspondant LIKE :q '
                . 'OR EXISTS (SELECT 1 FROM client_banamur cl WHERE cl.id_client = d.client_id AND cl.nom_client LIKE :q) '
                . 'OR EXISTS (SELECT 1 FROM offre_banamur o WHERE o.id_offre = d.offre_id AND (o.reference_offre LIKE :q OR o.num_offre LIKE :q))'
                . ')';
            $params['q'] = '%' . (string)$filtres['q'] . '%';
        }

        $sort = (string) ($filtres['sort'] ?? 'recent');
        $orderBy = [
            'oldest' => 'd.created_at ASC, d.id ASC',
            'amount_desc' => 'd.total_ttc DESC, d.id DESC',
            'amount_asc' => 'd.total_ttc ASC, d.id DESC',
            'number_asc' => 'd.numero_devis ASC, d.id DESC',
            'number_desc' => 'd.numero_devis DESC, d.id DESC',
            'recent' => 'd.created_at DESC, d.id DESC',
        ][$sort] ?? 'd.created_at DESC, d.id DESC';
        $sql .= ' ORDER BY ' . $orderBy;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Liste des correspondants distincts (pour select-search "Rédacteur").
     */
    public function getDistinctCorrespondants(): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT TRIM(correspondant) AS correspondant FROM devis_banamur WHERE masque = 0 AND correspondant IS NOT NULL AND TRIM(correspondant) <> '' ORDER BY correspondant ASC");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) : [];
        $rows = array_map('strval', $rows);
        $rows = array_values(array_filter(array_map('trim', $rows), static fn($v) => $v !== ''));
        return $rows;
    }

    public function creerDevis($data, $lignes)
    {
        $sql = "INSERT INTO devis_banamur (numero_devis, delai_livraison, date_emission, date_expiration, billing_date, emis_par, destine_a, termes_conditions, pied_de_page, total_ht, total_ttc, commercial_discount, logo, client_id, offre_id, tva_facturable, publier_devis, tva, correspondant)
                VALUES (:numero_devis, :delai_livraison, :date_emission, :date_expiration, :billing_date, :emis_par, :destine_a, :termes_conditions, :pied_de_page, :total_ht, :total_ttc, :commercial_discount, :logo, :client_id, :offre_id, :tva_facturable, :publier_devis, :tva, :correspondant)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        $devisId = $this->pdo->lastInsertId();

        // Enregistrer les lignes de devis
        foreach ($lignes as $ligne) {
            $sqlLigne = "INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe)
                         VALUES (:devis_id, :designation, :prix, :quantite, :unite_id, :total, :groupe)";
            $stmtLigne = $this->pdo->prepare($sqlLigne);
            $stmtLigne->execute([
                'devis_id'   => $devisId,
                'designation' => $ligne['designation'],
                'prix'       => $ligne['prix'],
                'quantite'   => $ligne['quantite'],
                'unite_id'   => $ligne['unite_id'],
                'total'      => $ligne['total'],
                'groupe'     => $ligne['groupe'], // <-- ajout ici
            ]);
        }

        $snapshot = $this->getDevisAuditSnapshot((int) $devisId);
        $this->logDevisEvent('devis_created', 'creation', $snapshot, [
            'line_count' => count($lignes),
            'client_id' => $data['client_id'] ?? null,
            'offre_id' => $data['offre_id'] ?? null,
            'total_ht' => $data['total_ht'] ?? null,
            'total_ttc' => $data['total_ttc'] ?? null,
            'publier_devis' => $data['publier_devis'] ?? null,
        ]);

        return $devisId;
    }

    public function getNombreDevisParJour()
    {
        $sql = "SELECT DATE(date_emission) AS date, COUNT(*) AS count
            FROM devis_banamur
            WHERE masque = 0
            GROUP BY DATE(date_emission)
            ORDER BY DATE(date_emission) ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function masquerDevis($id)
    {
        $snapshot = $this->getDevisAuditSnapshot((int) $id);
        try {
            $stmt = $this->pdo->prepare('UPDATE devis_banamur SET masque=1,masked_at=NOW(),masked_by_user_id=:user WHERE id=:id');
            $result = $stmt->execute(['id'=>$id,'user'=>(int)($_SESSION['user_id']??0)?:null]);
        } catch (Throwable $e) {
            // Compatibilité immédiate si la base en ligne n'a pas encore accepté les colonnes de traçabilité.
            $stmt=$this->pdo->prepare('UPDATE devis_banamur SET masque=1 WHERE id=:id');
            $result=$stmt->execute(['id'=>$id]);
        }
        if ($result) {
            $this->logDevisEvent('devis_masked', 'masquage', $snapshot, [
                'masque' => true,
            ]);
        }
        return $result;
    }

    public function restaurerDevisMasque(int $id): bool
    {
        $snapshot = $this->getDevisAuditSnapshot($id);
        try {
            $stmt=$this->pdo->prepare('UPDATE devis_banamur SET masque=0,masked_at=NULL,masked_by_user_id=NULL WHERE id=:id AND COALESCE(masque,0)=1');
            $stmt->execute(['id'=>$id]);
        } catch (Throwable $e) {
            $stmt=$this->pdo->prepare('UPDATE devis_banamur SET masque=0 WHERE id=:id AND COALESCE(masque,0)=1');
            $stmt->execute(['id'=>$id]);
        }
        if ($stmt->rowCount() > 0) {
            $this->logDevisEvent('devis_unmasked', 'restauration_affichage', $snapshot, ['masque' => false]);
            return true;
        }
        return false;
    }

    private function ensureMaskMetadata(): void
    {
        if (self::$maskMetadataReady) return;
        try {
            foreach (['masked_at'=>'DATETIME NULL AFTER masque','masked_by_user_id'=>'INT NULL AFTER masked_at'] as $column=>$definition) {
                $check=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="devis_banamur" AND COLUMN_NAME=:column');
                $check->execute(['column'=>$column]);
                if (!(int)$check->fetchColumn()) $this->pdo->exec("ALTER TABLE devis_banamur ADD COLUMN `$column` $definition");
            }
            $audit=$this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="audit_trail"');
            $audit->execute();
            if ((int)$audit->fetchColumn()>0) {
                $this->pdo->exec("UPDATE devis_banamur d
                    JOIN (SELECT entity_id,MAX(created_at) masked_at FROM audit_trail WHERE event_type='devis_masked' AND entity_type='devis' GROUP BY entity_id) a ON CAST(a.entity_id AS UNSIGNED)=d.id
                    SET d.masked_at=a.masked_at
                    WHERE COALESCE(d.masque,0)=1 AND d.masked_at IS NULL");
            }
        } catch (Throwable $e) {
            error_log('Initialisation non bloquante des dates de masquage : '.$e->getMessage());
        }
        self::$maskMetadataReady=true;
    }

    public function modifierDevis($devisId, $data, $lignes)
    {
        $snapshotBefore = $this->getDevisAuditSnapshot((int) $devisId);
        $sql = "UPDATE devis_banamur SET emis_par = :emis_par, destine_a = :destine_a, delai_livraison = :delai_livraison, date_emission = :date_emission, date_expiration = :date_expiration, termes_conditions = :termes_conditions, pied_de_page = :pied_de_page, total_ht = :total_ht, total_ttc = :total_ttc, logo = :logo, client_id = :client_id, offre_id = :offre_id, tva_facturable = :tva_facturable, publier_devis = :publier_devis, tva = :tva, correspondant = :correspondant WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $data['id'] = $devisId;
        $stmt->execute($data);

        // Supprimer les anciennes lignes
        $deleteStmt = $this->pdo->prepare("DELETE FROM ligne_devis_banamur WHERE devis_id = :devis_id");
        $deleteStmt->execute(['devis_id' => $devisId]);

        // Ajouter les nouvelles lignes
        foreach ($lignes as $ligne) {
            $sqlLigne = "INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe)
             VALUES (:devis_id, :designation, :prix, :quantite, :unite_id, :total, :groupe)";
            $stmtLigne = $this->pdo->prepare($sqlLigne);
            $stmtLigne->execute([
                'devis_id'   => $devisId,
                'designation' => $ligne['designation'],
                'prix'       => $ligne['prix'],
                'quantite'   => $ligne['quantite'],
                'unite_id'   => $ligne['unite_id'] ?? null,
                'total'      => $ligne['total'],
                'groupe'     => $ligne['groupe'],
            ]);
        }

        $snapshotAfter = $this->getDevisAuditSnapshot((int) $devisId) ?? $snapshotBefore;
        $this->logDevisEvent('devis_updated', 'modification', $snapshotAfter, [
            'mode' => 'legacy_replace',
            'line_count' => count($lignes),
            'before' => $snapshotBefore,
            'after' => $snapshotAfter,
        ]);
    }

    public function updateDevis($devisId, $data, $lignes)
    {
        try {
            $snapshotBefore = $this->getDevisAuditSnapshot((int) $devisId);
            $this->pdo->beginTransaction();

            // Met à jour le devis principal
            $sql = "UPDATE devis_banamur SET 
                        numero_devis = :numero_devis,
                        delai_livraison = :delai_livraison,
                        date_emission = :date_emission,
                        date_expiration = :date_expiration,
                        emis_par = :emis_par,
                        destine_a = :destine_a,
                        termes_conditions = :termes_conditions,
                        pied_de_page = :pied_de_page,
                        total_ht = :total_ht,
                        total_ttc = :total_ttc,
                        logo = :logo,
                        client_id = :client_id,
                        offre_id = :offre_id,
                        tva_facturable = :tva_facturable,
                        publier_devis = :publier_devis,
                        tva = :tva,
                        correspondant = :correspondant
                    WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $data['id'] = $devisId;
            $stmt->execute($data);

            // Lignes existantes (pour suppression ciblée)
            $existingStmt = $this->pdo->prepare("SELECT id FROM ligne_devis_banamur WHERE devis_id = :devis_id");
            $existingStmt->execute(['devis_id' => $devisId]);
            $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));
            $existingSet = array_fill_keys($existingIds, true);

            $incomingIds = [];

            foreach ($lignes as $ligne) {
                $ligneId = isset($ligne['id']) ? (int)$ligne['id'] : 0;

                $designation = $ligne['designation'] ?? '';
                $prix = $ligne['prix_unitaire'] ?? ($ligne['prix'] ?? 0);
                $quantite = $ligne['quantite'] ?? 0;
                $uniteId = $ligne['unite_id'] ?? null;
                $total = $ligne['prix_total'] ?? ($ligne['total'] ?? null);
                if ($total === null) {
                    $total = (float)$prix * (float)$quantite;
                }
                $groupe = $ligne['groupe'] ?? null;

                if ($ligneId > 0 && isset($existingSet[$ligneId])) {
                    $updateLine = $this->pdo->prepare(
                        "UPDATE ligne_devis_banamur
                         SET designation = :designation, prix = :prix, quantite = :quantite, unite_id = :unite_id, total = :total, groupe = :groupe
                         WHERE id = :id AND devis_id = :devis_id"
                    );
                    $updateLine->execute([
                        'designation' => $designation,
                        'prix' => $prix,
                        'quantite' => $quantite,
                        'unite_id' => $uniteId,
                        'total' => $total,
                        'groupe' => $groupe,
                        'id' => $ligneId,
                        'devis_id' => $devisId,
                    ]);
                    $incomingIds[] = $ligneId;
                } else {
                    $insertLine = $this->pdo->prepare(
                        "INSERT INTO ligne_devis_banamur (devis_id, designation, prix, quantite, unite_id, total, groupe)
                         VALUES (:devis_id, :designation, :prix, :quantite, :unite_id, :total, :groupe)"
                    );
                    $insertLine->execute([
                        'devis_id' => $devisId,
                        'designation' => $designation,
                        'prix' => $prix,
                        'quantite' => $quantite,
                        'unite_id' => $uniteId,
                        'total' => $total,
                        'groupe' => $groupe,
                    ]);
                }
            }

            // Supprime uniquement les anciennes lignes retirées (ne touche pas aux nouvelles lignes insérées)
            $incomingIds = array_values(array_unique(array_map('intval', $incomingIds)));
            $toDelete = array_values(array_diff($existingIds, $incomingIds));
            if (count($toDelete) > 0) {
                $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
                $deleteSql = "DELETE FROM ligne_devis_banamur WHERE devis_id = ? AND id IN ($placeholders)";
                $deleteStmt = $this->pdo->prepare($deleteSql);
                $deleteStmt->execute(array_merge([(int)$devisId], $toDelete));
            }

            $this->pdo->commit();
            $snapshotAfter = $this->getDevisAuditSnapshot((int) $devisId) ?? $snapshotBefore;
            $this->logDevisEvent('devis_updated', 'modification', $snapshotAfter, [
                'mode' => 'targeted_update',
                'line_count' => count($lignes),
                'before' => $snapshotBefore,
                'after' => $snapshotAfter,
            ]);
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            \AuditTrail::log('devis_update_failed', [
                'module' => 'devis',
                'action' => 'modification',
                'entity_type' => 'devis',
                'entity_id' => (string) $devisId,
                'result' => 'failed',
                'severity' => 'error',
                'details' => [
                    'message' => $e->getMessage(),
                    'line_count' => count($lignes),
                ],
            ], $this->pdo);
            error_log('updateDevis error: ' . $e->getMessage());
            return false;
        }
    }

    private function getDevisAuditSnapshot(int $devisId): ?array
    {
        if ($devisId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, numero_devis, client_id, offre_id, total_ht, total_ttc, validation_technique, validation_generale, masque, emis_par, destine_a, correspondant
             FROM devis_banamur
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $devisId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function logDevisEvent(string $eventType, string $action, ?array $snapshot, array $details = []): void
    {
        $payload = $details;
        if ($snapshot !== null) {
            $payload['snapshot'] = [
                'numero_devis' => $snapshot['numero_devis'] ?? null,
                'client_id' => $snapshot['client_id'] ?? null,
                'offre_id' => $snapshot['offre_id'] ?? null,
                'total_ht' => $snapshot['total_ht'] ?? null,
                'total_ttc' => $snapshot['total_ttc'] ?? null,
                'validation_technique' => $snapshot['validation_technique'] ?? null,
                'validation_generale' => $snapshot['validation_generale'] ?? null,
                'masque' => $snapshot['masque'] ?? null,
            ];
        }

        \AuditTrail::log($eventType, [
            'module' => 'devis',
            'action' => $action,
            'entity_type' => 'devis',
            'entity_id' => isset($snapshot['id']) ? (string) $snapshot['id'] : null,
            'entity_label' => isset($snapshot['numero_devis']) ? (string) $snapshot['numero_devis'] : null,
            'details' => $payload,
        ], $this->pdo);
    }

    public function getDevisById($id)
    {
        $stmt = $this->pdo->prepare("SELECT d.* FROM devis_banamur d WHERE d.id = :id AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.id AND c.archived_at IS NULL))");
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getLignesDevis($devisId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ligne_devis_banamur WHERE devis_id = :devis_id");
        $stmt->execute(['devis_id' => $devisId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Récupère les déboursés pour un devis, indexés par ligne_devis_id
    public function getDeboursesByDevis($devisId)
    {
        // Important: historiquement, on indexe par ligne_devis_id.
        // Si la table contient des doublons (même devis_id + même ligne_devis_id),
        // on choisit un déboursé "canonique" (priorité à celui qui a des sous-lignes).
        $stmt = $this->pdo->prepare("SELECT d.* FROM debourse_banamur d WHERE d.devis_id = :devis_id AND (NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id) OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL)) AND " . FinancialPlanningSeparationService::officialDeboursePredicate('d') . " ORDER BY d.id DESC");
        $stmt->execute(['devis_id' => $devisId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) return [];

        $ids = array_map(static fn($r) => (int)($r['id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids));
        $lineCounts = $this->getSousLigneCountsForDebourses($ids);

        $bestByLigne = [];
        foreach ($rows as $row) {
            $ligneKey = (string)($row['ligne_devis_id'] ?? '');
            if ($ligneKey === '') $ligneKey = '0';

            $id = (int)($row['id'] ?? 0);
            $sousCount = (int)($lineCounts[$id] ?? 0);
            $hasDates = !empty($row['date_debut']) && !empty($row['date_fin']);
            $hasMontant = (float)($row['montant_debourse'] ?? 0) > 0;

            $candidate = [
                'row' => $row,
                'id' => $id,
                'sousCount' => $sousCount,
                'hasDates' => $hasDates ? 1 : 0,
                'hasMontant' => $hasMontant ? 1 : 0,
            ];

            if (!isset($bestByLigne[$ligneKey])) {
                $bestByLigne[$ligneKey] = $candidate;
                continue;
            }

            $current = $bestByLigne[$ligneKey];
            $replace = false;
            if ($candidate['sousCount'] !== $current['sousCount']) {
                $replace = $candidate['sousCount'] > $current['sousCount'];
            } elseif ($candidate['hasDates'] !== $current['hasDates']) {
                $replace = $candidate['hasDates'] > $current['hasDates'];
            } elseif ($candidate['hasMontant'] !== $current['hasMontant']) {
                $replace = $candidate['hasMontant'] > $current['hasMontant'];
            } else {
                $replace = $candidate['id'] > $current['id'];
            }

            if ($replace) {
                $bestByLigne[$ligneKey] = $candidate;
            }
        }

        $result = [];
        foreach ($bestByLigne as $ligneKey => $wrap) {
            $result[$ligneKey] = $wrap['row'];
        }
        return $result;
    }

    // Récupère les sous-lignes de déboursé pour une ligne de devis
    public function getLignesDebourse($debourseId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ligne_debourse_banamur WHERE debourse_id = :debourse_id");
        $stmt->execute(['debourse_id' => $debourseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveDebourseLigne($devisId, $ligneId, $montant, $responsable, $date_debut, $date_fin)
    {
        require_once __DIR__ . '/../services/DebourseFebPositionService.php';
        $lineCheck = $this->pdo->prepare('SELECT 1 FROM ligne_devis_banamur WHERE id=:line AND devis_id=:devis LIMIT 1');
        $lineCheck->execute(['line' => (int) $ligneId, 'devis' => (int) $devisId]);
        if (!$lineCheck->fetchColumn()) throw new DomainException('La ligne de devis ne correspond pas au devis sélectionné.');
        $this->assertWorkDatesWithinQuote((int)$devisId, (string)$date_debut, (string)$date_fin, 'Le déboursé');
        // Ne pas utiliser REPLACE: sans contrainte UNIQUE, cela crée des doublons
        // (même devis + même ligne_devis) et casse l'export planning / affichage.
        $debourseId = $this->getCanonicalDebourseIdForDevisLine((int)$devisId, (int)$ligneId);
        $ownTransaction = !$this->pdo->inTransaction();
        if ($ownTransaction) $this->pdo->beginTransaction();
        try {
        $oldStart = '';
        if ($debourseId) {
            $previous = $this->pdo->prepare('SELECT date_debut FROM debourse_banamur WHERE id=:id LIMIT 1 FOR UPDATE');
            $previous->execute(['id' => $debourseId]);
            $oldStart = (string) $previous->fetchColumn();
        }
        if ($debourseId) {
            $sql = "UPDATE debourse_banamur
                    SET montant_debourse = :montant,
                        responsable_id = :responsable,
                        date_debut = :date_debut,
                        date_fin = :date_fin,
                        updated_at = NOW()
                    WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'montant' => $montant,
                'responsable' => $responsable,
                'date_debut' => $date_debut,
                'date_fin' => $date_fin,
                'id' => $debourseId,
            ]);
        } else {
            $sql = "INSERT INTO debourse_banamur (devis_id, ligne_devis_id, montant_debourse, responsable_id, date_debut, date_fin)
                    VALUES (:devis_id, :ligne_devis_id, :montant, :responsable, :date_debut, :date_fin)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'devis_id' => $devisId,
                'ligne_devis_id' => $ligneId,
                'montant' => $montant,
                'responsable' => $responsable,
                'date_debut' => $date_debut,
                'date_fin' => $date_fin
            ]);
            $debourseId = (int)$this->pdo->lastInsertId();
        }

        $this->pdo->prepare('UPDATE ligne_debourse_banamur SET date_debut=:start,date_fin=:end WHERE debourse_id=:id')
            ->execute(['start' => $date_debut, 'end' => $date_fin, 'id' => $debourseId]);

        DebourseFebPositionService::shiftSubmitted($this->pdo, (int) $debourseId, $oldStart, (string) $date_debut);
        if ($ownTransaction) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($ownTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }

        return $debourseId;
    }

    public function deleteSousLignesDebourse($ligneId)
    {
        // En présence de doublons, on supprime sur le déboursé canonique.
        $debourseId = $this->getCanonicalDebourseIdForLine((int)$ligneId);
        if (!$debourseId) return;
        $existingIds = array_map('intval', array_column($this->getLignesDebourse($debourseId), 'id'));
        $this->assertSousLignesCanBeDeleted($existingIds);
        $sql = "DELETE FROM ligne_debourse_banamur WHERE debourse_id = :debourse_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['debourse_id' => $debourseId]);
    }

    public function addSousLigneDebourse($ligneId, $categorie, $designation, $montant, $date_debut, $date_fin)
    {
        // Récupérer l'id du déboursé principal (canonique si doublons)
        $debourse_id = $this->getCanonicalDebourseIdForLine((int)$ligneId);
        if (!$debourse_id) return;
        $period = $this->deboursePeriod((int)$debourse_id);
        $date_debut = $period['start'];
        $date_fin = $period['end'];

        $categorie = FebCategoryMigrationService::normalize((string)$categorie);
        $sql = "INSERT INTO ligne_debourse_banamur (debourse_id, categorie, designation, montant, date_debut, date_fin)
                VALUES (:debourse_id, :categorie, :designation, :montant, :date_debut, :date_fin)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'debourse_id' => $debourse_id,
            'categorie' => $categorie,
            'designation' => $designation,
            'montant' => $montant,
            'date_debut' => $date_debut,
            'date_fin' => $date_fin
        ]);
    }

    public function syncSousLignesDebourse($devisId, $ligneId, array $sousLignes): array
    {
        $debourseId = $this->getCanonicalDebourseIdForDevisLine((int)$devisId, (int)$ligneId);
        if (!$debourseId) {
            if (!$this->filterSousLignesPayload($sousLignes)) {
                return ['updated' => 0, 'inserted' => 0, 'deleted' => 0];
            }

            throw new RuntimeException('Impossible de retrouver le déboursé à mettre à jour.');
        }

        $existingRows = $this->getLignesDebourse($debourseId);
        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[(int)$row['id']] = $row;
        }

        $normalizedItems = $this->filterSousLignesPayload($sousLignes);
        $period = $this->deboursePeriod($debourseId);
        $keptIds = [];
        $updated = 0;
        $inserted = 0;

        foreach ($normalizedItems as $item) {
            $sousLigneId = (int)($item['id'] ?? 0);
            if ($sousLigneId > 0) {
                if (!isset($existingById[$sousLigneId])) {
                    throw new RuntimeException('La sous-ligne #'.$sousLigneId.' ne fait plus partie de la ligne de devis #'.(int)$ligneId.'. Rechargez uniquement cette ligne, vérifiez ses postes, puis enregistrez-la à nouveau.');
                }
                if (isset($keptIds[$sousLigneId])) {
                    throw new RuntimeException('Doublon détecté sur une sous-ligne de déboursé.');
                }

                $this->updateLigneDebourse($sousLigneId, [
                    'categorie' => $item['categorie'],
                    'designation' => $item['designation'],
                    'montant' => $item['montant'],
                    'date_debut' => $period['start'],
                    'date_fin' => $period['end'],
                ]);
                $keptIds[$sousLigneId] = true;
                $updated++;
                continue;
            }

            $this->addSousLigneDebourseByDebourseId(
                $debourseId,
                $item['categorie'],
                $item['designation'],
                $item['montant'],
                $period['start'],
                $period['end']
            );
            $inserted++;
        }

        $deleteIds = [];
        foreach (array_keys($existingById) as $existingId) {
            if (!isset($keptIds[$existingId])) {
                $deleteIds[] = (int)$existingId;
            }
        }

        $deleted = $this->deleteSousLignesByIds($deleteIds);

        return [
            'updated' => $updated,
            'inserted' => $inserted,
            'deleted' => $deleted,
        ];
    }

    private function filterSousLignesPayload(array $sousLignes): array
    {
        $normalized = [];
        foreach ($sousLignes as $item) {
            if (!is_array($item)) {
                continue;
            }

            $categorieRaw = trim((string)($item['categorie'] ?? ''));
            $designation = trim((string)($item['designation'] ?? ''));
            $montantRaw = (string)($item['montant'] ?? '');
            $dateDebut = trim((string)($item['date_debut'] ?? ''));
            $dateFin = trim((string)($item['date_fin'] ?? ''));
            $sousLigneId = (int)($item['id'] ?? 0);

            if ($categorieRaw === '' && $designation === '' && trim($montantRaw) === '' && $dateDebut === '' && $dateFin === '') {
                continue;
            }
            $categorie = FebCategoryMigrationService::normalize($categorieRaw);

            $normalized[] = [
                'id' => $sousLigneId,
                'categorie' => $categorie,
                'designation' => $designation,
                'montant' => is_numeric($montantRaw) ? (float)$montantRaw : 0.0,
                'date_debut' => $dateDebut !== '' ? $dateDebut : null,
                'date_fin' => $dateFin !== '' ? $dateFin : null,
            ];
        }

        return $normalized;
    }

    private function deleteSousLignesByIds(array $ids): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }

        $this->assertSousLignesCanBeDeleted($ids);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM ligne_debourse_banamur WHERE id IN ($placeholders)");
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    private function assertSousLignesCanBeDeleted(array $ids): void
    {
        $linkedRows = $this->getLinkedSousLignesInfo($ids);
        if (!$linkedRows) {
            return;
        }

        $first = $linkedRows[0];
        $designation = trim((string)($first['designation'] ?? ''));
        $message = 'Impossible de supprimer cette ligne de déboursé car elle est déjà liée à une fiche de décaissement';
        if ($designation !== '') {
            $message .= ' : ' . $designation;
        }
        if (count($linkedRows) > 1) {
            $message .= ' (et d\'autres lignes liées)';
        }

        throw new RuntimeException($message . '.');
    }

    private function getLinkedSousLignesInfo(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT l.id, l.designation, COUNT(fd.id_fiche) AS fiche_count
                FROM ligne_debourse_banamur l
                INNER JOIN fiche_decaissement fd ON fd.ligne_debourse_id = l.id
                WHERE l.id IN ($placeholders)
                GROUP BY l.id, l.designation
                ORDER BY l.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($ids);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retourne le déboursé "canonique" pour une ligne de devis (ligne_devis_id).
     * Priorité: celui qui a le plus de sous-lignes, sinon le plus récent (id max).
     */
    private function getCanonicalDebourseIdForLine(int $ligneId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM debourse_banamur WHERE ligne_devis_id = :ligne_id ORDER BY id DESC');
        $stmt->execute(['ligne_id' => $ligneId]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return null;
        if (count($ids) === 1) return (int)$ids[0];

        $counts = $this->getSousLigneCountsForDebourses($ids);
        $bestId = (int)$ids[0];
        $bestCount = (int)($counts[$bestId] ?? 0);
        foreach ($ids as $id) {
            $c = (int)($counts[(int)$id] ?? 0);
            if ($c > $bestCount) {
                $bestCount = $c;
                $bestId = (int)$id;
            }
        }
        return $bestId;
    }

    /**
     * Retourne le déboursé "canonique" pour un couple (devis_id, ligne_devis_id).
     */
    private function getCanonicalDebourseIdForDevisLine(int $devisId, int $ligneId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT d.id,d.date_debut,d.date_fin,d.montant_debourse FROM debourse_banamur d WHERE d.devis_id = :devis_id AND d.ligne_devis_id = :ligne_id AND ' . FinancialPlanningSeparationService::officialDeboursePredicate('d') . ' ORDER BY d.id DESC');
        $stmt->execute(['devis_id' => $devisId, 'ligne_id' => $ligneId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ids = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
        if (!$ids) return null;
        if (count($ids) === 1) return (int)$ids[0];

        $counts = $this->getSousLigneCountsForDebourses($ids);
        $bestId = 0;
        $bestRank = [-1,-1,-1,-1];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $rank = [(int)($counts[$id] ?? 0),!empty($row['date_debut']) && !empty($row['date_fin']) ? 1 : 0,(float)($row['montant_debourse'] ?? 0)>0 ? 1 : 0,$id];
            if ($rank > $bestRank) {$bestRank=$rank;$bestId=$id;}
        }
        return $bestId;
    }

    /**
     * Retourne un mapping debourse_id => nb de sous-lignes.
     */
    private function getSousLigneCountsForDebourses(array $debourseIds): array
    {
        $debourseIds = array_values(array_filter(array_map('intval', $debourseIds)));
        if (!$debourseIds) return [];
        $placeholders = implode(',', array_fill(0, count($debourseIds), '?'));
        $stmt = $this->pdo->prepare("SELECT debourse_id, COUNT(*) AS c FROM ligne_debourse_banamur WHERE debourse_id IN ($placeholders) GROUP BY debourse_id");
        $stmt->execute($debourseIds);
        $out = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $out[(int)$r['debourse_id']] = (int)$r['c'];
        }
        return $out;
    }

    /**
     * Ajoute une sous-ligne à un déboursé existant (par son id)
     */
    public function addSousLigneDebourseByDebourseId($debourseId, $categorie, $designation, $montant, $date_debut, $date_fin)
    {
        $period = $this->deboursePeriod((int)$debourseId);
        $categorie = FebCategoryMigrationService::normalize((string)$categorie);
        $sql = "INSERT INTO ligne_debourse_banamur (debourse_id, categorie, designation, montant, date_debut, date_fin)
                VALUES (:debourse_id, :categorie, :designation, :montant, :date_debut, :date_fin)";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'debourse_id' => $debourseId,
            'categorie' => $categorie,
            'designation' => $designation,
            'montant' => $montant,
            'date_debut' => $period['start'],
            'date_fin' => $period['end']
        ]);
    }

    public function debourseExistePourDevis($devisId)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM debourse_banamur WHERE devis_id = :devis_id");
        $stmt->execute(['devis_id' => $devisId]);
        return $stmt->fetchColumn() > 0;
    }

    public function getSectionContent($devisId, $section)
    {
        $allowed = ['page_garde', 'description', 'delai', 'conditions', 'garantie'];
        if (!in_array($section, $allowed)) return '';
        $stmt = $this->pdo->prepare("SELECT `$section` FROM devis_banamur WHERE id = :id");
        $stmt->execute(['id' => $devisId]);
        return $stmt->fetchColumn();
    }

    public function saveSectionContent($devisId, $section, $contenu)
    {
        $allowed = ['page_garde', 'description', 'delai', 'conditions', 'garantie'];
        if (!in_array($section, $allowed)) return false;
        $stmt = $this->pdo->prepare("UPDATE devis_banamur SET `$section` = :contenu WHERE id = :id");
        return $stmt->execute(['contenu' => $contenu, 'id' => $devisId]);
    }

    // Met à jour une sous-ligne de déboursé
    public function updateLigneDebourse($id, $data)
    {
        $parent = $this->getDebourseByLigneDebourse((int)$id);
        if (!$parent) throw new DomainException('Déboursé parent introuvable pour cette ligne.');
        $period = $this->deboursePeriod((int)$parent['id']);
        $sql = "UPDATE ligne_debourse_banamur 
                SET designation = :designation, montant = :montant, date_debut = :date_debut, date_fin = :date_fin, categorie = :categorie
                WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'designation' => $data['designation'],
            'montant' => $data['montant'],
            'date_debut' => $period['start'],
            'date_fin' => $period['end'],
            'categorie' => FebCategoryMigrationService::normalize((string)($data['categorie'] ?? '')),
            'id' => $id
        ]);
    }

    // Récupère le déboursé parent d'une sous-ligne
    public function getDebourseByLigneDebourse($ligneDebourseId)
    {
        $sql = "SELECT d.* FROM debourse_banamur d
                JOIN ligne_debourse_banamur l ON l.debourse_id = d.id
                WHERE l.id = :ligne_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['ligne_id' => $ligneDebourseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // Calcule le montant total et la période d'un déboursé
    public function getTotauxDebourse($debourseId)
    {
        $sql = "SELECT (SELECT SUM(montant) FROM ligne_debourse_banamur WHERE debourse_id=d.id) AS montant_debourse,
                       d.date_debut,d.date_fin FROM debourse_banamur d WHERE d.id=:debourse_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['debourse_id' => $debourseId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateDebourseResume($debourseId)
    {
        $sql = "SELECT SUM(montant) AS montant_debourse FROM ligne_debourse_banamur WHERE debourse_id = :debourse_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['debourse_id' => $debourseId]);
        $resume = $stmt->fetch(PDO::FETCH_ASSOC);

        $sql2 = "UPDATE debourse_banamur SET montant_debourse = :montant_debourse WHERE id = :debourse_id";
        $stmt2 = $this->pdo->prepare($sql2);
        $stmt2->execute([
            'montant_debourse' => $resume['montant_debourse'],
            'debourse_id' => $debourseId
        ]);
    }

    public function addDebourse($devisId, $date_debut, $date_fin)
    {
        $this->assertWorkDatesWithinQuote((int)$devisId, (string)$date_debut, (string)$date_fin, 'Le déboursé');
        $sql = "INSERT INTO debourse_banamur (devis_id, montant_debourse, date_debut, date_fin)
            VALUES (:devis_id, 0, :date_debut, :date_fin)";
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            'devis_id' => $devisId,
            'date_debut' => $date_debut,
            'date_fin' => $date_fin
        ]);
        return $ok ? $this->pdo->lastInsertId() : false;
    }

    private function assertWorkDatesWithinQuote(int $devisId, string $start, string $end, string $label): void
    {
        if (!$this->validBusinessDate($start) || !$this->validBusinessDate($end)) throw new DomainException($label . ' doit avoir une date de début et une date de fin valides.');
        if ($end < $start) throw new DomainException($label . ' ne peut pas finir avant sa date de début.');
        $period = $this->getQuotePlanningPeriod($devisId);
        // Les données historiques comportent déjà des déboursés sans période
        // générale sur le devis. Dans ce cas, valider les dates saisies sans
        // bloquer leur enregistrement. Dès qu'un planning général existe, ses
        // bornes restent strictement appliquées.
        if (!$period) return;
        if ($start < $period['start'] || $end > $period['end']) throw new DomainException($label . ' doit être compris entre le ' . date('d/m/Y', strtotime($period['start'])) . ' et le ' . date('d/m/Y', strtotime($period['end'])) . '.');
    }

    /**
     * Retourne la période générale réellement disponible pour un devis.
     *
     * Les anciens écrans enregistrent la période sur le devis, tandis que le
     * module chantier récent la conserve dans son projet de planning. Accepter
     * les deux évite de réclamer à tort une seconde saisie du même planning.
     */
    private function getQuotePlanningPeriod(int $devisId): ?array
    {
        $statement = $this->pdo->prepare('SELECT project_start_date,project_end_date FROM devis_banamur WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $devisId]);
        $quote = $statement->fetch(PDO::FETCH_ASSOC);
        if ($quote
            && $this->validBusinessDate((string)($quote['project_start_date'] ?? ''))
            && $this->validBusinessDate((string)($quote['project_end_date'] ?? ''))
            && $quote['project_end_date'] >= $quote['project_start_date']) {
            return ['start' => $quote['project_start_date'], 'end' => $quote['project_end_date']];
        }

        // Compatibilité avec le planning général du module chantier.
        try {
            $planning = $this->pdo->prepare("SELECT p.date_debut,p.date_fin_prevue
                FROM planning_chantier_projects p
                INNER JOIN chantier_banamur c ON c.id=p.chantier_id
                WHERE c.devis_id=:id
                  AND (c.archived_at IS NULL)
                  AND p.date_debut IS NOT NULL
                  AND p.date_fin_prevue IS NOT NULL
                ORDER BY p.id DESC LIMIT 1");
            $planning->execute(['id' => $devisId]);
            $row = $planning->fetch(PDO::FETCH_ASSOC);
            if ($row
                && $this->validBusinessDate((string)($row['date_debut'] ?? ''))
                && $this->validBusinessDate((string)($row['date_fin_prevue'] ?? ''))
                && $row['date_fin_prevue'] >= $row['date_debut']) {
                return ['start' => $row['date_debut'], 'end' => $row['date_fin_prevue']];
            }
        } catch (PDOException $e) {
            // Installation antérieure au module planning chantier : la source
            // historique ci-dessous reste disponible.
        }

        // Certains chantiers historiques portent directement leur période.
        try {
            $chantier = $this->pdo->prepare("SELECT date_debut,date_fin_prevue
                FROM chantier_banamur
                WHERE devis_id=:id AND archived_at IS NULL
                  AND date_debut IS NOT NULL AND date_fin_prevue IS NOT NULL
                ORDER BY id DESC LIMIT 1");
            $chantier->execute(['id' => $devisId]);
            $row = $chantier->fetch(PDO::FETCH_ASSOC);
            if ($row
                && $this->validBusinessDate((string)($row['date_debut'] ?? ''))
                && $this->validBusinessDate((string)($row['date_fin_prevue'] ?? ''))
                && $row['date_fin_prevue'] >= $row['date_debut']) {
                return ['start' => $row['date_debut'], 'end' => $row['date_fin_prevue']];
            }
        } catch (PDOException $e) {
            // Colonnes absentes sur un schéma historique.
        }

        return null;
    }

    private function deboursePeriod(int $debourseId): array
    {
        $statement = $this->pdo->prepare('SELECT date_debut,date_fin FROM debourse_banamur WHERE id=:id LIMIT 1');
        $statement->execute(['id' => $debourseId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$this->validBusinessDate((string) $row['date_debut']) || !$this->validBusinessDate((string) $row['date_fin']) || $row['date_fin'] < $row['date_debut']) {
            throw new DomainException('Renseignez d’abord les dates de début et de fin du déboursé.');
        }
        return ['start' => $row['date_debut'], 'end' => $row['date_fin']];
    }

    private function assertDetailDatesWithinDebourse(int $debourseId, string $start, string $end, string $label): void
    {
        $statement = $this->pdo->prepare('SELECT d.date_debut,d.date_fin,d.devis_id FROM debourse_banamur d WHERE d.id=:id LIMIT 1');
        $statement->execute(['id' => $debourseId]);
        $parent = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$parent) throw new DomainException('Déboursé parent introuvable.');
        $this->assertWorkDatesWithinQuote((int)$parent['devis_id'], $start, $end, 'La ligne « ' . ($label ?: ('#'.$debourseId)) . ' »');
        if (!$this->validBusinessDate((string)$parent['date_debut']) || !$this->validBusinessDate((string)$parent['date_fin'])) throw new DomainException('Planifiez d’abord le déboursé parent.');
        if ($start < $parent['date_debut'] || $end > $parent['date_fin']) throw new DomainException('La ligne « ' . ($label ?: ('#'.$debourseId)) . ' » doit rester comprise dans son déboursé parent, du ' . date('d/m/Y', strtotime($parent['date_debut'])) . ' au ' . date('d/m/Y', strtotime($parent['date_fin'])) . '.');
    }

    private function validBusinessDate(string $date): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date === '0000-00-00') return false;
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    public function getDeboursesByDevisId($devisId)
    {
        $stmt = $this->pdo->prepare("
            SELECT d.*, l.designation AS designation_ligne
            FROM debourse_banamur d
            JOIN ligne_devis_banamur l ON l.id = d.ligne_devis_id AND l.devis_id = d.devis_id
            WHERE d.devis_id = :devis_id
        ");
        $stmt->execute(['devis_id' => $devisId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLignesDebourseByDebourseId($debourseId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM ligne_debourse_banamur WHERE debourse_id = :debourse_id");
        $stmt->execute(['debourse_id' => $debourseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTotalDebourseByDevisId($devisId)
    {
        $total = 0;
        $debourses = $this->getDeboursesByDevisId($devisId);
        foreach ($debourses as $debourse) {
            $sousLignes = $this->getLignesDebourseByDebourseId($debourse['id']);
            foreach ($sousLignes as $ligne) {
                $total += $ligne['montant'];
            }
        }
        return $total;
    }

    public function syncAllDebourseMontants($devisId)
    {
        $debourses = $this->getDeboursesByDevisId($devisId);
        foreach ($debourses as $debourse) {
            $this->updateDebourseResume($debourse['id']);
        }
    }

    public function getLibelleLigneDevisByDebourseId($debourseId)
    {
        $stmt = $this->pdo->prepare("
        SELECT ld.designation AS libelle
        FROM ligne_devis_banamur ld
        JOIN debourse_banamur d ON d.ligne_devis_id = ld.id
        WHERE d.id = :debourse_id
        LIMIT 1
    ");
        $stmt->execute(['debourse_id' => $debourseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['libelle'] : '';
    }

    public function getAllDeboursesWithDevis()
    {
        $sql = "SELECT d.*, dv.numero_devis, dv.destine_a
            FROM debourse_banamur d
            JOIN devis_banamur dv ON d.devis_id = dv.id
            WHERE NOT EXISTS(SELECT 1 FROM chantier_banamur ca WHERE ca.devis_id=d.devis_id)
               OR EXISTS(SELECT 1 FROM chantier_banamur c WHERE c.devis_id=d.devis_id AND c.archived_at IS NULL)
            ORDER BY d.id DESC";
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDevisSansChantier()
    {
        $sql = "SELECT * FROM devis_banamur d
        WHERE NOT EXISTS (SELECT 1 FROM chantier_banamur c WHERE c.devis_id = d.id)
        AND d.total_ttc > 0
        AND d.client_id > 0
        AND d.offre_id > 0
        AND d.numero_devis IS NOT NULL
        AND d.numero_devis != ''
        ORDER BY d.created_at DESC";
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLignesByDevisId($devisId)
    {
        $stmt = $this->pdo->prepare("
            SELECT ld.*, u.libelle as unite_libelle
            FROM ligne_devis_banamur ld
            LEFT JOIN unites_mesure u ON u.id = ld.unite_id
            WHERE ld.devis_id = :devis_id
            ORDER BY ld.zone, ld.groupe, ld.designation
        ");
        $stmt->execute(['devis_id' => $devisId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDeboursesFiltres($chantierId = null, $search = null, $client = null)
    {

        $sql = "

SELECT 
d.*,
dv.numero_devis,
dv.destine_a,
c.titre,
l.designation,
cl.nom_client 

FROM debourse_banamur d

JOIN devis_banamur dv 
ON dv.id=d.devis_id

LEFT JOIN ligne_devis_banamur l
ON l.id=d.ligne_devis_id

LEFT JOIN client_banamur cl
ON cl.id_client=dv.client_id

LEFT JOIN devis_chantier_links dcl
ON dcl.devis_id=d.devis_id

LEFT JOIN chantier_banamur c
ON (c.devis_id=d.devis_id OR c.id=dcl.chantier_id) AND c.archived_at IS NULL

WHERE 1=1
  AND " . FinancialPlanningSeparationService::officialDeboursePredicate('d') . "

";

        $params = [];

        if ($chantierId) {

            $sql .= " AND c.id=?";
            $params[] = $chantierId;
        }

        if ($search) {

            $sql .= " AND l.designation LIKE ?";
            $params[] = "%$search%";
        }

        if ($client) {
            $sql .= " AND cl.nom_client LIKE ?";
            $params[] = "%$client%";
        }

        $sql .= " ORDER BY d.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
