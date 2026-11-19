<?php

require_once __DIR__ . '/../model/AoOpportunity.php';

class AoOpportunityRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTables();
    }

    public function upsert(array $data): array
    {
        $row = AoOpportunity::prepare($data);
        if ($row['titre'] === '') {
            throw new InvalidArgumentException('Titre AO obligatoire.');
        }

        $existing = $this->findDuplicate($row);
        if ($existing) {
            if ($this->sameBusinessData($existing, $row)) {
                $stmt = $this->pdo->prepare('UPDATE ao_opportunities SET last_seen_at = NOW() WHERE id = :id');
                $stmt->execute(['id' => (int) $existing['id']]);
                return ['id' => (int) $existing['id'], 'is_new' => false, 'is_updated' => false, 'row' => $existing];
            }
            $stmt = $this->pdo->prepare(
                'UPDATE ao_opportunities SET
                    source = :source, numero_ao = COALESCE(NULLIF(:numero_ao, ""), numero_ao),
                    reference = COALESCE(NULLIF(:reference, ""), reference),
                    reference_normalized = COALESCE(NULLIF(:reference_normalized, ""), reference_normalized),
                    titre = :titre, autorite_contractante = :autorite_contractante,
                    type_marche = :type_marche, secteur = :secteur,
                    date_publication = COALESCE(:date_publication, date_publication),
                    date_limite = COALESCE(:date_limite, date_limite),
                    lieu_execution = :lieu_execution, resume = :resume,
                    url_source = COALESCE(NULLIF(:url_source, ""), url_source),
                    url_canonical = COALESCE(NULLIF(:url_canonical, ""), url_canonical),
                    details_source_json = :details_source_json,
                    documents_source_json = :documents_source_json,
                    score_pertinence = :score_pertinence, last_seen_at = NOW(), updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'source' => $row['source'],
                'numero_ao' => $row['numero_ao'],
                'reference' => $row['reference'],
                'reference_normalized' => $row['reference_normalized'],
                'titre' => $row['titre'],
                'autorite_contractante' => $row['autorite_contractante'],
                'type_marche' => $row['type_marche'],
                'secteur' => $row['secteur'],
                'date_publication' => $row['date_publication'],
                'date_limite' => $row['date_limite'],
                'lieu_execution' => $row['lieu_execution'],
                'resume' => $row['resume'],
                'url_source' => $row['url_source'],
                'url_canonical' => $row['url_canonical'],
                'details_source_json' => $row['details_source_json'],
                'documents_source_json' => $row['documents_source_json'],
                'score_pertinence' => $row['score_pertinence'],
                'id' => (int) $existing['id'],
            ]);
            return ['id' => (int) $existing['id'], 'is_new' => false, 'is_updated' => true, 'row' => $this->findById((int) $existing['id'])];
        }

        try {
            $insertRow = $row;
            $insertRow['reference_normalized'] = $row['reference_normalized'] !== '' ? $row['reference_normalized'] : null;
            $insertRow['url_canonical'] = $row['url_canonical'] !== '' ? $row['url_canonical'] : null;
            $stmt = $this->pdo->prepare(
                'INSERT INTO ao_opportunities (
                    source, numero_ao, reference, reference_normalized, titre, autorite_contractante,
                    type_marche, secteur, date_publication, date_limite, lieu_execution,
                    resume, url_source, url_canonical, score_pertinence, statut_interne,
                    details_source_json, documents_source_json,
                    hash_unique, signature_hash, last_seen_at
                 ) VALUES (
                    :source, :numero_ao, :reference, :reference_normalized, :titre, :autorite_contractante,
                    :type_marche, :secteur, :date_publication, :date_limite, :lieu_execution,
                    :resume, :url_source, :url_canonical, :score_pertinence, :statut_interne,
                    :details_source_json, :documents_source_json,
                    :hash_unique, :signature_hash, NOW()
                 )'
            );
            $stmt->execute($insertRow);
            $id = (int) $this->pdo->lastInsertId();
            return ['id' => $id, 'is_new' => true, 'is_updated' => false, 'row' => $this->findById($id)];
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->findDuplicate($row);
            if (!$existing) {
                throw $exception;
            }
            return ['id' => (int) $existing['id'], 'is_new' => false, 'is_updated' => false, 'row' => $existing];
        }
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT ao.*, NULLIF(TRIM(CONCAT(COALESCE(u.prenom, ''), ' ', COALESCE(u.nom, ''))), '') AS dg_decision_by_name
             FROM ao_opportunities ao
             LEFT JOIN user_devis_banamur u ON u.id = ao.dg_decision_by
             WHERE ao.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function search(array $filters = [], int $limit = 100): array
    {
        $where = ['1=1'];
        $params = [];
        $deadlineScope = (string) ($filters['deadline_scope'] ?? 'active');
        if ($deadlineScope === 'expired') {
            $where[] = 'date_limite IS NOT NULL AND date_limite < CURDATE()';
        } elseif ($deadlineScope === 'undated') {
            $where[] = 'date_limite IS NULL';
        } elseif ($deadlineScope !== 'all') {
            $where[] = 'date_limite IS NOT NULL AND date_limite >= CURDATE()';
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(titre LIKE :q OR reference LIKE :q OR numero_ao LIKE :q OR autorite_contractante LIKE :q OR resume LIKE :q)';
            $params['q'] = '%' . trim((string) $filters['q']) . '%';
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'statut_interne = :status';
            $params['status'] = trim((string) $filters['status']);
        }
        if (($filters['min_score'] ?? '') !== '') {
            $where[] = 'score_pertinence >= :min_score';
            $params['min_score'] = max(0, (int) $filters['min_score']);
        }
        if (!empty($filters['deadline_soon'])) {
            $where[] = 'date_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)';
        }
        $limit = max(1, min(1000, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM ao_opportunities WHERE ' . implode(' AND ', $where) .
                ' ORDER BY (date_limite IS NULL), date_limite ASC, score_pertinence DESC, id DESC LIMIT ' . $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateStatus(int $id, string $status): void
    {
        $allowed = ['nouveau', 'a_analyser', 'interessant', 'non_interessant', 'rejete', 'dossier_en_preparation', 'soumission', 'soumissionne', 'converti_crm', 'gagne', 'perdu', 'expire'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Statut AO invalide.');
        }
        $current = $this->findById($id);
        if (!$current) {
            throw new RuntimeException('Appel d offres introuvable.');
        }
        if (($current['dg_decision'] ?? 'pending') === 'not_interested' && $status !== 'non_interessant') {
            throw new RuntimeException('La decision DG "Pas interesse" est prioritaire. Elle doit etre reouverte par un administrateur AO.');
        }
        $stmt = $this->pdo->prepare('UPDATE ao_opportunities SET statut_interne = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function recordDgDecision(int $id, string $decision, int $userId, string $note = ''): void
    {
        if (!in_array($decision, ['interested', 'not_interested'], true)) {
            throw new InvalidArgumentException('Decision DG invalide.');
        }
        $status = $decision === 'interested' ? 'interessant' : 'non_interessant';
        $stmt = $this->pdo->prepare(
            'UPDATE ao_opportunities
             SET dg_decision = :decision, dg_decision_by = :user_id, dg_decision_at = NOW(),
                 dg_decision_note = NULLIF(:note, ""), statut_interne = :status,
                 alerts_disabled = :alerts_disabled, updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'decision' => $decision,
            'user_id' => $userId,
            'note' => trim($note),
            'status' => $status,
            'alerts_disabled' => $decision === 'not_interested' ? 1 : 0,
            'id' => $id,
        ]);
        if ($stmt->rowCount() === 0 && !$this->findById($id)) {
            throw new RuntimeException('Appel d offres introuvable.');
        }
    }

    public function linkCrmOpportunity(int $id, int $crmId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE ao_opportunities
             SET crm_opportunity_id = :crm_id, statut_interne = 'converti_crm', updated_at = NOW()
             WHERE id = :id"
        );
        $stmt->execute(['crm_id' => $crmId, 'id' => $id]);
    }

    public function pendingForAlerts(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        return $this->pdo->query(
            "SELECT *
             FROM ao_opportunities
             WHERE statut_interne = 'nouveau'
               AND dg_decision = 'pending'
               AND alerts_disabled = 0
               AND date_limite IS NOT NULL
               AND date_limite >= CURDATE()
             ORDER BY date_limite ASC, score_pertinence DESC, id ASC
             LIMIT " . $limit
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function stats(string $deadlineScope = 'active'): array
    {
        $scopeSql = "date_limite IS NOT NULL AND date_limite >= CURDATE()";
        if ($deadlineScope === 'expired') {
            $scopeSql = "date_limite IS NOT NULL AND date_limite < CURDATE()";
        } elseif ($deadlineScope === 'undated') {
            $scopeSql = 'date_limite IS NULL';
        } elseif ($deadlineScope === 'all') {
            $scopeSql = '1=1';
        }
        $row = $this->pdo->query(
            "SELECT COUNT(*) total,
                    SUM(score_pertinence >= 60) pertinents,
                    SUM(date_limite BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)) proches,
                    SUM(statut_interne = 'interessant') interessants,
                    SUM(dg_decision = 'pending') attente_dg
             FROM ao_opportunities
             WHERE $scopeSql"
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        return array_map('intval', $row);
    }

    private function findDuplicate(array $row): ?array
    {
        if ($row['reference_normalized'] !== '') {
            $stmt = $this->pdo->prepare('SELECT * FROM ao_opportunities WHERE reference_normalized = :reference_normalized ORDER BY id LIMIT 1');
            $stmt->execute(['reference_normalized' => $row['reference_normalized']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existing) {
                return $existing;
            }
        }

        if ($row['url_canonical'] !== '' && !$this->isListingUrl($row['url_canonical'])) {
            $stmt = $this->pdo->prepare('SELECT * FROM ao_opportunities WHERE url_canonical = :url_canonical ORDER BY id LIMIT 1');
            $stmt->execute(['url_canonical' => $row['url_canonical']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existing) {
                return $existing;
            }
        }

        $sql = 'SELECT * FROM ao_opportunities
                WHERE (hash_unique = :hash_unique OR signature_hash = :signature_hash)';
        $params = ['hash_unique' => $row['hash_unique'], 'signature_hash' => $row['signature_hash']];
        if ($row['reference_normalized'] !== '') {
            $sql .= " AND (reference_normalized IS NULL OR reference_normalized = '')";
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY id LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function sameBusinessData(array $existing, array $row): bool
    {
        foreach (
            [
                'source',
                'numero_ao',
                'reference',
                'reference_normalized',
                'titre',
                'autorite_contractante',
                'type_marche',
                'secteur',
                'date_publication',
                'date_limite',
                'lieu_execution',
                'resume',
                'url_source',
                'details_source_json',
                'documents_source_json',
                'score_pertinence'
            ] as $field
        ) {
            $incoming = $row[$field] ?? '';
            if (
                in_array($field, ['reference', 'reference_normalized', 'date_publication', 'date_limite', 'url_source'], true)
                && (string) $incoming === ''
            ) {
                $incoming = $existing[$field] ?? '';
            }
            if ((string) ($existing[$field] ?? '') !== (string) $incoming) {
                return false;
            }
        }
        return true;
    }

    private function isListingUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        return in_array(rtrim($path, '/'), ['/appel_offre', '/appeloffre', '/avis-appels-offres'], true);
    }

    private function ensureTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS ao_opportunities (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(80) NOT NULL,
                numero_ao VARCHAR(160) NULL,
                reference VARCHAR(160) NULL,
                reference_normalized VARCHAR(190) NULL,
                titre VARCHAR(700) NOT NULL,
                autorite_contractante VARCHAR(500) NULL,
                type_marche VARCHAR(160) NULL,
                secteur VARCHAR(255) NULL,
                date_publication DATE NULL,
                date_limite DATE NULL,
                lieu_execution VARCHAR(255) NULL,
                resume TEXT NULL,
                url_source TEXT NULL,
                url_canonical VARCHAR(700) NULL,
                score_pertinence TINYINT UNSIGNED NOT NULL DEFAULT 0,
                statut_interne VARCHAR(40) NOT NULL DEFAULT 'nouveau',
                dg_decision ENUM('pending','interested','not_interested') NOT NULL DEFAULT 'pending',
                dg_decision_by INT NULL,
                dg_decision_at DATETIME NULL,
                dg_decision_note TEXT NULL,
                crm_opportunity_id INT NULL,
                alerts_disabled TINYINT(1) NOT NULL DEFAULT 0,
                details_source_json LONGTEXT NULL,
                documents_source_json LONGTEXT NULL,
                hash_unique CHAR(64) NOT NULL,
                signature_hash CHAR(64) NOT NULL,
                last_seen_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_ao_hash (hash_unique),
                UNIQUE KEY uq_ao_signature (signature_hash),
                UNIQUE KEY uq_ao_reference (reference_normalized),
                KEY idx_ao_deadline (date_limite),
                KEY idx_ao_score (score_pertinence),
                KEY idx_ao_status (statut_interne)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->ensureColumn('numero_ao', 'VARCHAR(160) NULL AFTER source');
        $this->ensureColumn('details_source_json', 'LONGTEXT NULL AFTER statut_interne');
        $this->ensureColumn('documents_source_json', 'LONGTEXT NULL AFTER details_source_json');
        $this->ensureColumn('dg_decision', "ENUM('pending','interested','not_interested') NOT NULL DEFAULT 'pending' AFTER statut_interne");
        $this->ensureColumn('dg_decision_by', 'INT NULL AFTER dg_decision');
        $this->ensureColumn('dg_decision_at', 'DATETIME NULL AFTER dg_decision_by');
        $this->ensureColumn('dg_decision_note', 'TEXT NULL AFTER dg_decision_at');
        $this->ensureColumn('crm_opportunity_id', 'INT NULL AFTER dg_decision_note');
        $this->ensureColumn('alerts_disabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER crm_opportunity_id');
        $this->relaxContentDedupIndexes();
    }

    private function ensureColumn(string $column, string $definition): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
        );
        $stmt->execute(['table_name' => 'ao_opportunities', 'column_name' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $this->pdo->exec('ALTER TABLE ao_opportunities ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    private function relaxContentDedupIndexes(): void
    {
        $indexes = $this->pdo->query('SHOW INDEX FROM ao_opportunities')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $names = [];
        foreach ($indexes as $index) {
            $names[(string) ($index['Key_name'] ?? '')] = (int) ($index['Non_unique'] ?? 1);
        }

        foreach (['uq_ao_hash' => 'hash_unique', 'uq_ao_signature' => 'signature_hash'] as $uniqueName => $column) {
            if (($names[$uniqueName] ?? 1) === 0) {
                $this->pdo->exec('ALTER TABLE ao_opportunities DROP INDEX ' . $uniqueName);
            }
            $regularName = 'idx_' . $column;
            if (!isset($names[$regularName])) {
                $this->pdo->exec('ALTER TABLE ao_opportunities ADD INDEX ' . $regularName . ' (' . $column . ')');
            }
        }
    }
}
