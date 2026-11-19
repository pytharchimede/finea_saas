<?php

require_once __DIR__ . '/AuditTrail.php';

class DocumentManager
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_folders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NULL,
                name VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                space_type VARCHAR(30) NOT NULL DEFAULT 'general',
                chantier_id INT NULL,
                service_id INT NULL,
                direction_code VARCHAR(60) NULL,
                required_permission VARCHAR(80) NULL,
                description TEXT NULL,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                archived_at DATETIME NULL,
                archived_by INT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_doc_folders_parent (parent_id),
                INDEX idx_doc_folders_space (space_type, chantier_id, service_id, direction_code),
                INDEX idx_doc_folders_permission (required_permission),
                INDEX idx_doc_folders_archived (is_archived),
                INDEX idx_doc_folders_archived_by (archived_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folder_id INT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                document_type VARCHAR(50) NOT NULL DEFAULT 'document',
                chantier_id INT NULL,
                service_id INT NULL,
                direction_code VARCHAR(60) NULL,
                required_permission VARCHAR(80) NULL,
                current_version_id INT NULL,
                is_archived TINYINT(1) NOT NULL DEFAULT 0,
                archived_at DATETIME NULL,
                archived_by INT NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_doc_documents_folder (folder_id),
                INDEX idx_doc_documents_context (chantier_id, service_id, direction_code),
                INDEX idx_doc_documents_permission (required_permission),
                INDEX idx_doc_documents_archived (is_archived),
                INDEX idx_doc_documents_type (document_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_document_versions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                version_number INT NOT NULL DEFAULT 1,
                stored_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                extension VARCHAR(20) NULL,
                mime_type VARCHAR(120) NULL,
                file_size BIGINT NULL,
                checksum_sha256 CHAR(64) NULL,
                uploaded_by INT NULL,
                uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                INDEX idx_doc_versions_document (document_id),
                INDEX idx_doc_versions_current (is_current)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_document_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                document_id INT NOT NULL,
                version_id INT NOT NULL,
                token CHAR(64) NOT NULL,
                allow_download TINYINT(1) NOT NULL DEFAULT 0,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_reshare TINYINT(1) NOT NULL DEFAULT 0,
                recipient_user_id INT NULL,
                recipient_name VARCHAR(190) NULL,
                recipient_email VARCHAR(190) NULL,
                expires_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_accessed_at DATETIME NULL,
                access_count INT NOT NULL DEFAULT 0,
                UNIQUE KEY uk_doc_document_shares_token (token),
                INDEX idx_doc_document_shares_document (document_id),
                INDEX idx_doc_document_shares_version (version_id),
                INDEX idx_doc_document_shares_expires (expires_at, revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_folder_shares (
                id INT AUTO_INCREMENT PRIMARY KEY,
                folder_id INT NOT NULL,
                token CHAR(64) NOT NULL,
                allow_download TINYINT(1) NOT NULL DEFAULT 0,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_reshare TINYINT(1) NOT NULL DEFAULT 0,
                recipient_user_id INT NULL,
                recipient_name VARCHAR(190) NULL,
                recipient_email VARCHAR(190) NULL,
                expires_at DATETIME NULL,
                revoked_at DATETIME NULL,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_accessed_at DATETIME NULL,
                access_count INT NOT NULL DEFAULT 0,
                UNIQUE KEY uk_doc_folder_shares_token (token),
                INDEX idx_doc_folder_shares_folder (folder_id),
                INDEX idx_doc_folder_shares_expires (expires_at, revoked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS doc_access_acl (
                id INT AUTO_INCREMENT PRIMARY KEY,
                entity_type ENUM('document','folder') NOT NULL,
                entity_id INT NOT NULL,
                subject_type ENUM('user','service') NOT NULL,
                subject_id INT NOT NULL,
                can_view TINYINT(1) NOT NULL DEFAULT 1,
                can_edit TINYINT(1) NOT NULL DEFAULT 0,
                can_download TINYINT(1) NOT NULL DEFAULT 0,
                can_delete TINYINT(1) NOT NULL DEFAULT 0,
                can_share TINYINT(1) NOT NULL DEFAULT 0,
                can_archive TINYINT(1) NOT NULL DEFAULT 0,
                created_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_doc_acl_subject (entity_type, entity_id, subject_type, subject_id),
                INDEX idx_doc_acl_subject_lookup (subject_type, subject_id, entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );

        if (!$this->columnExists('doc_folders', 'archived_at')) {
            $this->pdo->exec("ALTER TABLE doc_folders ADD COLUMN archived_at DATETIME NULL AFTER is_archived");
        }
        if (!$this->columnExists('doc_folders', 'archived_by')) {
            $this->pdo->exec("ALTER TABLE doc_folders ADD COLUMN archived_by INT NULL AFTER archived_at");
        }

        if (!$this->columnExists('doc_document_shares', 'can_view')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_download");
        }
        if (!$this->columnExists('doc_document_shares', 'can_edit')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view");
        }
        if (!$this->columnExists('doc_document_shares', 'can_reshare')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_reshare TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
        }
        if (!$this->columnExists('doc_document_shares', 'recipient_user_id')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN recipient_user_id INT NULL AFTER can_reshare");
        }
        if (!$this->columnExists('doc_document_shares', 'recipient_name')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN recipient_name VARCHAR(190) NULL AFTER recipient_user_id");
        }
        if (!$this->columnExists('doc_document_shares', 'recipient_email')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN recipient_email VARCHAR(190) NULL AFTER recipient_name");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_view')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_view TINYINT(1) NOT NULL DEFAULT 1 AFTER allow_download");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_edit')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_reshare')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_reshare TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
        }
        if (!$this->columnExists('doc_folder_shares', 'recipient_user_id')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN recipient_user_id INT NULL AFTER can_reshare");
        }
        if (!$this->columnExists('doc_folder_shares', 'recipient_name')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN recipient_name VARCHAR(190) NULL AFTER recipient_user_id");
        }
        if (!$this->columnExists('doc_folder_shares', 'recipient_email')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN recipient_email VARCHAR(190) NULL AFTER recipient_name");
        }
        if (!$this->columnExists('doc_document_shares', 'can_download')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_download TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
            $this->pdo->exec("UPDATE doc_document_shares SET can_download = allow_download");
        }
        if (!$this->columnExists('doc_document_shares', 'can_delete')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_download");
        }
        if (!$this->columnExists('doc_document_shares', 'can_share')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_share TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete");
            $this->pdo->exec("UPDATE doc_document_shares SET can_share = can_reshare");
        }
        if (!$this->columnExists('doc_document_shares', 'can_archive')) {
            $this->pdo->exec("ALTER TABLE doc_document_shares ADD COLUMN can_archive TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_download')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_download TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
            $this->pdo->exec("UPDATE doc_folder_shares SET can_download = allow_download");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_delete')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_download");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_share')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_share TINYINT(1) NOT NULL DEFAULT 0 AFTER can_delete");
            $this->pdo->exec("UPDATE doc_folder_shares SET can_share = can_reshare");
        }
        if (!$this->columnExists('doc_folder_shares', 'can_archive')) {
            $this->pdo->exec("ALTER TABLE doc_folder_shares ADD COLUMN can_archive TINYINT(1) NOT NULL DEFAULT 0 AFTER can_share");
        }

        $this->ensureStorageRoot();
    }

    public function getDirectionOptions(): array
    {
        return [
            'direction_generale' => 'Direction générale',
            'direction_financiere' => 'Direction financière',
            'direction_technique' => 'Direction technique',
            'direction_commerciale' => 'Direction commerciale',
            'direction_rh' => 'Direction RH',
            'direction_logistique' => 'Direction logistique',
        ];
    }

    public function getAudienceOptions(): array
    {
        return [
            '' => 'Visible à tous les profils autorisés du module',
            'acces_direction' => 'Réservé direction',
            'acces_compta' => 'Réservé comptabilité',
            'acces_point_financier' => 'Réservé trésorerie / point financier',
            'acces_rh' => 'Réservé RH',
            'acces_logistique' => 'Réservé logistique',
            'acces_communication' => 'Réservé communication',
            'acces_admin' => 'Réservé administration',
        ];
    }

    public function getSpaceLabels(): array
    {
        return [
            'chantier' => 'Chantiers',
            'direction' => 'Directions',
            'service' => 'Services',
            'general' => 'Référentiel',
            'archive' => 'Archives',
        ];
    }

    public function listServiceOptions(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT id_service, lib_service FROM service ORDER BY lib_service ASC');
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getUserDocumentationContext(?int $userId = null, array $userPermissions = []): array
    {
        $userId = $userId !== null ? (int)$userId : $this->getCurrentSessionUserId();
        $serviceId = 0;
        $serviceLabel = '';

        if ($userId > 0) {
            try {
                $select = 'SELECT u.id';
                $join = '';
                if ($this->columnExists('user_devis_banamur', 'personnel_id')) {
                    $select .= ', COALESCE(p.service_id, 0) AS service_id, COALESCE(s.lib_service, "") AS service_label';
                    $join = ' LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                              LEFT JOIN service s ON s.id_service = p.service_id';
                } elseif ($this->columnExists('user_devis_banamur', 'service_id')) {
                    $select .= ', COALESCE(u.service_id, 0) AS service_id, COALESCE(s.lib_service, "") AS service_label';
                    $join = ' LEFT JOIN service s ON s.id_service = u.service_id';
                }

                $stmt = $this->pdo->prepare($select . ' FROM user_devis_banamur u' . $join . ' WHERE u.id = :id LIMIT 1');
                $stmt->execute(['id' => $userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                $serviceId = (int)($row['service_id'] ?? 0);
                $serviceLabel = trim((string)($row['service_label'] ?? ''));
            } catch (Throwable $e) {
                $serviceId = 0;
                $serviceLabel = '';
            }
        }

        return [
            'user_id' => $userId,
            'service_id' => $serviceId,
            'service_label' => $serviceLabel,
            'is_global_admin' => !empty($userPermissions['acces_admin']),
            'is_document_admin' => !empty($userPermissions['document_admin']),
            'permissions' => $userPermissions,
        ];
    }

    public function listChantiers(bool $includeArchived = false): array
    {
        $hasArchived = $this->columnExists('chantier_banamur', 'archived_at');
        $sql = 'SELECT id, code, titre';
        if ($hasArchived) {
            $sql .= ', archived_at';
        }
        $sql .= ' FROM chantier_banamur';
        if ($hasArchived) {
            $sql .= ' WHERE archived_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT 500';

        try {
            $stmt = $this->pdo->query($sql);
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function getDashboardStats(array $userPermissions = []): array
    {
        $folders = $this->listFolders(['include_archived' => true], $userPermissions);
        $documents = $this->listDocuments(['include_archived' => true], $userPermissions);

        $totalSize = 0;
        $archivedCount = 0;
        foreach ($documents as $document) {
            $totalSize += (int)($document['file_size'] ?? 0);
            if (!empty($document['is_archived'])) {
                $archivedCount++;
            }
        }

        return [
            'folders_total' => count($folders),
            'documents_total' => count($documents),
            'documents_active' => count(array_filter($documents, static fn(array $row): bool => empty($row['is_archived']))),
            'documents_archived' => $archivedCount,
            'total_size' => $totalSize,
        ];
    }

    public function listFolders(array $filters = [], array $userPermissions = []): array
    {
        $sql = "SELECT f.*, CONCAT_WS(' ', ua.prenom, ua.nom) AS archived_by_name
            FROM doc_folders f
            LEFT JOIN user_devis_banamur ua ON ua.id = f.archived_by
            WHERE 1=1";
        $params = [];

        if (empty($filters['include_archived'])) {
            $sql .= ' AND f.is_archived = 0';
        }
        if (!empty($filters['space_type'])) {
            $sql .= ' AND f.space_type = :space_type';
            $params['space_type'] = (string)$filters['space_type'];
        }
        if (!empty($filters['chantier_id'])) {
            $sql .= ' AND f.chantier_id = :chantier_id';
            $params['chantier_id'] = (int)$filters['chantier_id'];
        }
        if (!empty($filters['service_id'])) {
            $sql .= ' AND f.service_id = :service_id';
            $params['service_id'] = (int)$filters['service_id'];
        }
        if (!empty($filters['direction_code'])) {
            $sql .= ' AND f.direction_code = :direction_code';
            $params['direction_code'] = (string)$filters['direction_code'];
        }
        if (!empty($filters['parent_id'])) {
            $sql .= ' AND f.parent_id = :parent_id';
            $params['parent_id'] = (int)$filters['parent_id'];
        }
        if (!empty($filters['archived_by'])) {
            $sql .= ' AND f.archived_by = :archived_by';
            $params['archived_by'] = (int)$filters['archived_by'];
        }
        if (!empty($filters['archived_from'])) {
            $sql .= ' AND f.archived_at >= :archived_from';
            $params['archived_from'] = (string)$filters['archived_from'] . ' 00:00:00';
        }
        if (!empty($filters['archived_to'])) {
            $sql .= ' AND f.archived_at <= :archived_to';
            $params['archived_to'] = (string)$filters['archived_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY f.space_type ASC, COALESCE(f.parent_id, 0) ASC, f.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userId = isset($filters['user_id']) ? (int)$filters['user_id'] : null;
        return array_values(array_filter($rows, fn(array $row): bool => $this->canAccessFolder($row, 'view', $userId, $userPermissions)));
    }

    public function listDocuments(array $filters = [], array $userPermissions = []): array
    {
        $sql = "SELECT d.*, f.name AS folder_name, f.space_type AS folder_space_type,
                   CONCAT_WS(' ', ua.prenom, ua.nom) AS archived_by_name,
                       v.id AS version_id, v.original_name, v.mime_type, v.file_size, v.uploaded_at, v.extension
                FROM doc_documents d
                LEFT JOIN doc_folders f ON f.id = d.folder_id
            LEFT JOIN user_devis_banamur ua ON ua.id = d.archived_by
                LEFT JOIN doc_document_versions v ON v.id = d.current_version_id
                WHERE 1=1";
        $params = [];

        if (empty($filters['include_archived'])) {
            $sql .= ' AND d.is_archived = 0';
        }
        if (!empty($filters['space_type'])) {
            $sql .= " AND COALESCE(f.space_type, 'general') = :space_type";
            $params['space_type'] = (string)$filters['space_type'];
        }
        if (!empty($filters['folder_id'])) {
            $sql .= ' AND d.folder_id = :folder_id';
            $params['folder_id'] = (int)$filters['folder_id'];
        }
        if (!empty($filters['chantier_id'])) {
            $sql .= ' AND d.chantier_id = :chantier_id';
            $params['chantier_id'] = (int)$filters['chantier_id'];
        }
        if (!empty($filters['service_id'])) {
            $sql .= ' AND d.service_id = :service_id';
            $params['service_id'] = (int)$filters['service_id'];
        }
        if (!empty($filters['direction_code'])) {
            $sql .= ' AND d.direction_code = :direction_code';
            $params['direction_code'] = (string)$filters['direction_code'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (d.title LIKE :search OR d.description LIKE :search OR v.original_name LIKE :search)';
            $params['search'] = '%' . trim((string)$filters['search']) . '%';
        }
        if (!empty($filters['document_type'])) {
            $sql .= ' AND d.document_type = :document_type';
            $params['document_type'] = (string)$filters['document_type'];
        }
        if (!empty($filters['archived_by'])) {
            $sql .= ' AND d.archived_by = :archived_by';
            $params['archived_by'] = (int)$filters['archived_by'];
        }
        if (!empty($filters['archived_from'])) {
            $sql .= ' AND d.archived_at >= :archived_from';
            $params['archived_from'] = (string)$filters['archived_from'] . ' 00:00:00';
        }
        if (!empty($filters['archived_to'])) {
            $sql .= ' AND d.archived_at <= :archived_to';
            $params['archived_to'] = (string)$filters['archived_to'] . ' 23:59:59';
        }

        $sql .= ' ORDER BY d.updated_at DESC, d.id DESC LIMIT 500';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $userId = isset($filters['user_id']) ? (int)$filters['user_id'] : null;
        return array_values(array_filter($rows, fn(array $row): bool => $this->canAccessDocument($row, 'view', $userId, $userPermissions)));
    }

    public function createFolder(array $data, int $userId): int
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Le nom du dossier est obligatoire.');
        }

        $parentId = $this->normalizeNullableInt($data['parent_id'] ?? null);
        $this->assertFolderNameAvailable($name, $parentId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_folders (parent_id, name, slug, space_type, chantier_id, service_id, direction_code, required_permission, description, is_archived, created_by)
             VALUES (:parent_id, :name, :slug, :space_type, :chantier_id, :service_id, :direction_code, :required_permission, :description, 0, :created_by)'
        );
        $serviceId = $this->normalizeNullableInt($data['service_id'] ?? null);
        if ($serviceId === null) {
            $defaultContext = $this->getUserDocumentationContext($userId, []);
            $serviceId = !empty($defaultContext['service_id']) ? (int)$defaultContext['service_id'] : null;
        }

        $stmt->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $this->slugify($name),
            'space_type' => $this->normalizeSpaceType($data['space_type'] ?? 'general'),
            'chantier_id' => $this->normalizeNullableInt($data['chantier_id'] ?? null),
            'service_id' => $serviceId,
            'direction_code' => $this->normalizeNullableString($data['direction_code'] ?? null),
            'required_permission' => $this->normalizeNullableString($data['required_permission'] ?? null),
            'description' => $this->normalizeNullableString($data['description'] ?? null),
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        $folderId = (int)$this->pdo->lastInsertId();
        if ($serviceId !== null) {
            $this->upsertAcl('folder', $folderId, 'service', $serviceId, [
                'can_view' => true,
                'can_edit' => true,
                'can_download' => true,
                'can_delete' => false,
                'can_share' => true,
                'can_archive' => true,
            ], $userId);
        }
        return $folderId;
    }

    public function renameFolder(int $folderId, string $name): void
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            throw new RuntimeException('Le dossier à renommer est introuvable.');
        }

        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Le nouveau nom du dossier est obligatoire.');
        }

        $this->assertFolderNameAvailable($name, $this->normalizeNullableInt($folder['parent_id'] ?? null), $folderId);

        $stmt = $this->pdo->prepare('UPDATE doc_folders SET name = :name, slug = :slug WHERE id = :id');
        $stmt->execute([
            'name' => $name,
            'slug' => $this->slugify($name),
            'id' => $folderId,
        ]);
    }

    public function updateFolderService(int $folderId, ?int $serviceId): void
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            throw new RuntimeException('Le dossier est introuvable.');
        }

        $stmt = $this->pdo->prepare('UPDATE doc_folders SET service_id = :service_id WHERE id = :id');
        $stmt->execute([
            'service_id' => $this->normalizeNullableInt($serviceId),
            'id' => $folderId,
        ]);
    }

    public function moveFolder(int $folderId, ?int $parentId): void
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            throw new RuntimeException('Le dossier à déplacer est introuvable.');
        }

        if ($parentId !== null) {
            if ($parentId === $folderId) {
                throw new RuntimeException('Un dossier ne peut pas être déplacé dans lui-même.');
            }

            $parentFolder = $this->getFolderById($parentId);
            if (!$parentFolder) {
                throw new RuntimeException('Le dossier parent sélectionné est introuvable.');
            }

            if ($this->isDescendantFolder($parentId, $folderId)) {
                throw new RuntimeException('Impossible de déplacer un dossier dans un de ses sous-dossiers.');
            }
        }

        $this->assertFolderNameAvailable((string)($folder['name'] ?? ''), $parentId, $folderId);

        $stmt = $this->pdo->prepare('UPDATE doc_folders SET parent_id = :parent_id WHERE id = :id');
        $stmt->execute([
            'parent_id' => $parentId,
            'id' => $folderId,
        ]);
    }

    public function uploadDocument(array $data, array $file, int $userId): int
    {
        $name = trim((string)($file['name'] ?? ''));
        $tmpName = (string)($file['tmp_name'] ?? '');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($file['size'] ?? 0);

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le fichier n\'a pas pu être reçu correctement.');
        }
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Upload invalide.');
        }
        if ($size <= 0) {
            throw new RuntimeException('Le fichier reçu est vide.');
        }
        if ($size > 314572800) {
            throw new RuntimeException('Le fichier dépasse la limite applicative de 300 MB.');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->getAllowedDocumentExtensions(), true)) {
            throw new RuntimeException('Type de fichier non autorisé pour le moment.');
        }

        $folder = null;
        $folderId = $this->normalizeNullableInt($data['folder_id'] ?? null);
        $overwrite = !empty($data['overwrite']);
        if ($folderId !== null) {
            $folder = $this->getFolderById($folderId);
            if (!$folder) {
                throw new RuntimeException('Le dossier cible est introuvable.');
            }
        }

        $conflict = $this->findDocumentByOriginalNameInFolder($name, $folderId);
        if ($conflict) {
            if (!$overwrite) {
                throw new RuntimeException('Un fichier portant deja ce nom existe deja dans le dossier cible.', 409);
            }
            $this->deleteDocument((int)$conflict['id']);
        }

        $spaceType = $this->normalizeSpaceType($data['space_type'] ?? ($folder['space_type'] ?? 'general'));
        $chantierId = $this->normalizeNullableInt($data['chantier_id'] ?? ($folder['chantier_id'] ?? null));
        $serviceId = $this->normalizeNullableInt($data['service_id'] ?? ($folder['service_id'] ?? null));
        if ($serviceId === null) {
            $defaultContext = $this->getUserDocumentationContext($userId, []);
            $serviceId = !empty($defaultContext['service_id']) ? (int)$defaultContext['service_id'] : null;
        }
        $directionCode = $this->normalizeNullableString($data['direction_code'] ?? ($folder['direction_code'] ?? null));
        $requiredPermission = $this->normalizeNullableString($data['required_permission'] ?? ($folder['required_permission'] ?? null));
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = pathinfo($name, PATHINFO_FILENAME);
        }

        $this->assertDocumentTitleAvailable($title, $folderId);

        $relativeDir = $this->buildStorageRelativeDirectory($spaceType, $chantierId, $serviceId, $directionCode);
        $absoluteDir = $this->ensureStorageDirectory($relativeDir);
        $safeBaseName = $this->slugify(pathinfo($name, PATHINFO_FILENAME));
        if ($safeBaseName === '') {
            $safeBaseName = 'document';
        }
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $safeBaseName . '.' . $extension;
        $relativePath = trim($relativeDir . '/' . $storedName, '/');
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;

        if (!@move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Impossible de stocker le document sur le serveur.');
        }

        $checksum = hash_file('sha256', $absolutePath) ?: null;
        $mimeType = (string)($file['type'] ?? 'application/octet-stream');
        $documentType = trim((string)($data['document_type'] ?? 'document'));
        if ($documentType === '') {
            $documentType = $extension === 'dwg' || $extension === 'dxf' ? 'plan' : 'document';
        }

        $this->pdo->beginTransaction();
        try {
            $stmtDoc = $this->pdo->prepare(
                'INSERT INTO doc_documents (folder_id, title, description, document_type, chantier_id, service_id, direction_code, required_permission, created_by)
                 VALUES (:folder_id, :title, :description, :document_type, :chantier_id, :service_id, :direction_code, :required_permission, :created_by)'
            );
            $stmtDoc->execute([
                'folder_id' => $folderId,
                'title' => $title,
                'description' => $this->normalizeNullableString($data['description'] ?? null),
                'document_type' => $documentType,
                'chantier_id' => $chantierId,
                'service_id' => $serviceId,
                'direction_code' => $directionCode,
                'required_permission' => $requiredPermission,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            $documentId = (int)$this->pdo->lastInsertId();

            $stmtVersion = $this->pdo->prepare(
                'INSERT INTO doc_document_versions (document_id, version_number, stored_path, original_name, extension, mime_type, file_size, checksum_sha256, uploaded_by, is_current)
                 VALUES (:document_id, 1, :stored_path, :original_name, :extension, :mime_type, :file_size, :checksum_sha256, :uploaded_by, 1)'
            );
            $stmtVersion->execute([
                'document_id' => $documentId,
                'stored_path' => $relativePath,
                'original_name' => $name,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'checksum_sha256' => $checksum,
                'uploaded_by' => $userId > 0 ? $userId : null,
            ]);

            $versionId = (int)$this->pdo->lastInsertId();
            $stmtUpdate = $this->pdo->prepare('UPDATE doc_documents SET current_version_id = :version_id WHERE id = :id');
            $stmtUpdate->execute(['version_id' => $versionId, 'id' => $documentId]);
            if ($serviceId !== null) {
                $this->upsertAcl('document', $documentId, 'service', $serviceId, [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_download' => true,
                    'can_delete' => false,
                    'can_share' => true,
                    'can_archive' => true,
                ], $userId);
            }

            $this->pdo->commit();
            return $documentId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            @unlink($absolutePath);
            throw $e;
        }
    }

    public function ensureFolderPath(array $segments, ?int $rootParentId, array $context, int $userId): ?int
    {
        $parentId = $rootParentId;
        $spaceType = $this->normalizeSpaceType($context['space_type'] ?? 'general');
        $chantierId = $this->normalizeNullableInt($context['chantier_id'] ?? null);
        $serviceId = $this->normalizeNullableInt($context['service_id'] ?? null);
        if ($serviceId === null) {
            $defaultContext = $this->getUserDocumentationContext($userId, []);
            $serviceId = !empty($defaultContext['service_id']) ? (int)$defaultContext['service_id'] : null;
        }
        $directionCode = $this->normalizeNullableString($context['direction_code'] ?? null);
        $requiredPermission = $this->normalizeNullableString($context['required_permission'] ?? null);

        foreach ($segments as $rawSegment) {
            $name = trim((string)$rawSegment);
            if ($name === '') {
                continue;
            }

            $existing = $this->findFolderByNameAndParent($name, $parentId);
            if ($existing) {
                $parentId = (int)$existing['id'];
                continue;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO doc_folders (parent_id, name, slug, space_type, chantier_id, service_id, direction_code, required_permission, description, is_archived, created_by)
                 VALUES (:parent_id, :name, :slug, :space_type, :chantier_id, :service_id, :direction_code, :required_permission, NULL, 0, :created_by)'
            );
            $stmt->execute([
                'parent_id' => $parentId,
                'name' => $name,
                'slug' => $this->slugify($name),
                'space_type' => $spaceType,
                'chantier_id' => $chantierId,
                'service_id' => $serviceId,
                'direction_code' => $directionCode,
                'required_permission' => $requiredPermission,
                'created_by' => $userId > 0 ? $userId : null,
            ]);

            $parentId = (int)$this->pdo->lastInsertId();
            if ($serviceId !== null) {
                $this->upsertAcl('folder', $parentId, 'service', $serviceId, [
                    'can_view' => true,
                    'can_edit' => true,
                    'can_download' => true,
                    'can_delete' => false,
                    'can_share' => true,
                    'can_archive' => true,
                ], $userId);
            }
        }

        return $parentId;
    }

    public function setDocumentArchived(int $documentId, bool $archived, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE doc_documents
             SET is_archived = :is_archived,
                 archived_at = CASE WHEN :is_archived = 1 THEN NOW() ELSE NULL END,
                 archived_by = CASE WHEN :is_archived = 1 THEN :archived_by ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'is_archived' => $archived ? 1 : 0,
            'archived_by' => $userId > 0 ? $userId : null,
            'id' => $documentId,
        ]);
    }

    public function setFolderArchived(int $folderId, bool $archived, int $userId): void
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE doc_folders
             SET is_archived = :is_archived,
                 archived_at = CASE WHEN :is_archived = 1 THEN NOW() ELSE NULL END,
                 archived_by = CASE WHEN :is_archived = 1 THEN :archived_by ELSE NULL END
             WHERE id = :id'
        );
        $stmt->execute([
            'is_archived' => $archived ? 1 : 0,
            'archived_by' => $userId > 0 ? $userId : null,
            'id' => $folderId,
        ]);

        foreach ($this->listDocumentsInFolder($folderId, true) as $document) {
            $this->setDocumentArchived((int)($document['id'] ?? 0), $archived, $userId);
        }

        foreach ($this->listChildFolders($folderId, true) as $childFolder) {
            $this->setFolderArchived((int)($childFolder['id'] ?? 0), $archived, $userId);
        }
    }

    public function getDocumentById(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doc_documents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function renameDocument(int $documentId, string $title): void
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Le document à renommer est introuvable.');
        }

        $title = trim($title);
        if ($title === '') {
            throw new RuntimeException('Le nouveau nom du document est obligatoire.');
        }

        $this->assertDocumentTitleAvailable($title, $this->normalizeNullableInt($document['folder_id'] ?? null), $documentId);

        $stmt = $this->pdo->prepare('UPDATE doc_documents SET title = :title WHERE id = :id');
        $stmt->execute([
            'title' => $title,
            'id' => $documentId,
        ]);
    }

    public function listInternalShareRecipients(): array
    {
        try {
            $rows = $this->pdo->query('SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE active = 1 ORDER BY prenom ASC, nom ASC, mail_pro ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $recipientId = (int)($row['id'] ?? 0);
            $email = trim((string)($row['mail_pro'] ?? ''));
            if ($recipientId <= 0 || $email === '') {
                continue;
            }

            $name = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $result[] = [
                'id' => $recipientId,
                'name' => $name !== '' ? $name : $email,
                'email' => $email,
            ];
        }

        return $result;
    }

    public function getInternalShareRecipientById(int $recipientUserId): ?array
    {
        if ($recipientUserId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, prenom, nom, mail_pro FROM user_devis_banamur WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute(['id' => $recipientUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $email = trim((string)($row['mail_pro'] ?? ''));
        if ($email === '') {
            return null;
        }

        $name = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
        return [
            'id' => (int)$row['id'],
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
        ];
    }

    public function moveDocument(int $documentId, ?int $folderId, bool $overwrite = false): void
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Le document à déplacer est introuvable.');
        }

        $targetFolder = null;
        if ($folderId !== null) {
            $targetFolder = $this->getFolderById($folderId);
            if (!$targetFolder) {
                throw new RuntimeException('Le dossier cible du document est introuvable.');
            }
        }

        $currentVersion = $this->getDocumentCurrentVersionRow($documentId);
        if (!$currentVersion) {
            throw new RuntimeException('Le document ne possède aucune version courante exploitable.');
        }

        $conflict = $this->findMoveConflictForDocument($documentId, $folderId);
        if ($conflict) {
            if (!$overwrite) {
                throw new RuntimeException('Un fichier portant deja ce nom existe deja dans le dossier cible.', 409);
            }
            $this->deleteDocument((int)($conflict['existing_document_id'] ?? 0));
        }

        $nextChantierId = $targetFolder['chantier_id'] ?? $document['chantier_id'] ?? null;
        $nextServiceId = $targetFolder['service_id'] ?? $document['service_id'] ?? null;
        $nextDirectionCode = $targetFolder['direction_code'] ?? $document['direction_code'] ?? null;
        $nextRequiredPermission = $targetFolder['required_permission'] ?? $document['required_permission'] ?? null;

        $this->moveDocumentStoredFiles($documentId, [
            'space_type' => $targetFolder['space_type'] ?? 'general',
            'chantier_id' => $nextChantierId,
            'service_id' => $nextServiceId,
            'direction_code' => $nextDirectionCode,
        ]);

        $stmt = $this->pdo->prepare(
            'UPDATE doc_documents
             SET folder_id = :folder_id,
                 chantier_id = :chantier_id,
                 service_id = :service_id,
                 direction_code = :direction_code,
                 required_permission = :required_permission
             WHERE id = :id'
        );
        $stmt->execute([
            'folder_id' => $folderId,
            'chantier_id' => $nextChantierId,
            'service_id' => $nextServiceId,
            'direction_code' => $nextDirectionCode,
            'required_permission' => $nextRequiredPermission,
            'id' => $documentId,
        ]);
    }

    public function deleteDocument(int $documentId): void
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT stored_path FROM doc_document_versions WHERE document_id = :document_id');
        $stmt->execute(['document_id' => $documentId]);
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $storedPath = trim((string)($row['stored_path'] ?? ''));
            if ($storedPath !== '') {
                $paths[$storedPath] = $storedPath;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM doc_document_shares WHERE document_id = :document_id')->execute(['document_id' => $documentId]);
            $this->pdo->prepare('DELETE FROM doc_document_versions WHERE document_id = :document_id')->execute(['document_id' => $documentId]);
            $this->pdo->prepare('DELETE FROM doc_documents WHERE id = :id')->execute(['id' => $documentId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        foreach ($paths as $storedPath) {
            $absolute = $this->resolveStoredAbsolutePath($storedPath);
            if ($absolute !== null && is_file($absolute)) {
                @unlink($absolute);
            }
        }
    }

    public function deleteFolder(int $folderId): void
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            return;
        }

        foreach ($this->listChildFolders($folderId) as $childFolder) {
            $this->deleteFolder((int)$childFolder['id']);
        }

        foreach ($this->listDocumentsInFolder($folderId, true) as $document) {
            $this->deleteDocument((int)$document['id']);
        }

        $this->pdo->prepare('DELETE FROM doc_folder_shares WHERE folder_id = :folder_id')->execute(['folder_id' => $folderId]);
        $this->pdo->prepare('DELETE FROM doc_folders WHERE id = :id')->execute(['id' => $folderId]);
    }

    public function listChildFolders(?int $parentId, bool $includeArchived = true): array
    {
        if ($parentId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE parent_id IS NULL' . ($includeArchived ? '' : ' AND is_archived = 0') . ' ORDER BY name ASC');
            $stmt->execute();
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE parent_id = :parent_id' . ($includeArchived ? '' : ' AND is_archived = 0') . ' ORDER BY name ASC');
            $stmt->execute(['parent_id' => $parentId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listDocumentsInFolder(?int $folderId, bool $includeArchived = true): array
    {
        if ($folderId === null) {
            $sql = 'SELECT d.*, v.original_name, v.stored_path, v.mime_type, v.file_size, v.extension, v.id AS version_id
                    FROM doc_documents d
                    LEFT JOIN doc_document_versions v ON v.id = d.current_version_id
                    WHERE d.folder_id IS NULL';
            if (!$includeArchived) {
                $sql .= ' AND d.is_archived = 0';
            }
            $sql .= ' ORDER BY d.title ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = 'SELECT d.*, v.original_name, v.stored_path, v.mime_type, v.file_size, v.extension, v.id AS version_id
                    FROM doc_documents d
                    LEFT JOIN doc_document_versions v ON v.id = d.current_version_id
                    WHERE d.folder_id = :folder_id';
            if (!$includeArchived) {
                $sql .= ' AND d.is_archived = 0';
            }
            $sql .= ' ORDER BY d.title ASC';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['folder_id' => $folderId]);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMoveConflicts(array $documentIds, array $folderIds, ?int $targetFolderId): array
    {
        $conflicts = [];

        foreach ($folderIds as $folderId) {
            $folder = $this->getFolderById((int)$folderId);
            if (!$folder) {
                continue;
            }
            $existing = $this->findFolderByNameAndParent((string)($folder['name'] ?? ''), $targetFolderId);
            if ($existing && (int)($existing['id'] ?? 0) !== (int)$folderId) {
                $conflicts[] = [
                    'type' => 'folder',
                    'id' => (int)$folderId,
                    'name' => (string)($folder['name'] ?? ''),
                ];
            }
        }

        foreach ($documentIds as $documentId) {
            $conflict = $this->findMoveConflictForDocument((int)$documentId, $targetFolderId);
            if ($conflict) {
                $conflicts[] = $conflict;
            }
        }

        return $conflicts;
    }

    public function getUploadConflict(?int $folderId, string $originalName, ?string $title = null): ?array
    {
        $originalName = trim($originalName);
        $title = trim((string)$title);

        $byFileName = $originalName !== '' ? $this->findDocumentByOriginalNameInFolder($originalName, $folderId) : null;
        if ($byFileName) {
            return [
                'type' => 'document',
                'mode' => 'original_name',
                'id' => (int)($byFileName['id'] ?? 0),
                'name' => (string)($byFileName['original_name'] ?? $originalName),
            ];
        }

        if ($title !== '') {
            $byTitle = $this->findDocumentByTitleInFolder($title, $folderId);
            if ($byTitle) {
                return [
                    'type' => 'document',
                    'mode' => 'title',
                    'id' => (int)($byTitle['id'] ?? 0),
                    'name' => (string)($byTitle['title'] ?? $title),
                ];
            }
        }

        return null;
    }

    public function getDownloadableVersion(int $versionId, array $userPermissions = [], ?int $userId = null, string $action = 'download'): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id AS document_id, d.id, d.title, d.required_permission, d.is_archived,
                    d.service_id, d.chantier_id, d.direction_code, d.created_by,
                    v.id AS version_id, v.stored_path, v.original_name, v.mime_type, v.file_size, v.extension
             FROM doc_document_versions v
             INNER JOIN doc_documents d ON d.id = v.document_id
             WHERE v.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        if (!$this->canAccessDocument($row, $action, $userId, $userPermissions)) {
            return null;
        }

        $absolute = $this->resolveStoredAbsolutePath((string)$row['stored_path']);
        if ($absolute === null || !is_file($absolute) || !is_readable($absolute)) {
            return null;
        }

        $row['absolute_path'] = $absolute;
        return $row;
    }

    public function createShare(int $documentId, int $userId, array $permissions = [], ?string $expiresAt = null, ?array $recipient = null): array
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Le document à partager est introuvable.');
        }

        $currentVersion = $this->getDocumentCurrentVersionRow($documentId);
        $versionId = (int)($document['current_version_id'] ?? 0);
        if ($versionId <= 0 || !$currentVersion) {
            throw new RuntimeException('Aucune version courante disponible pour ce document.');
        }

        $expiresAt = $this->normalizeNullableString($expiresAt);
        if ($expiresAt !== null) {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false) {
                throw new RuntimeException('Date d\'expiration invalide.');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
        }

        $allowDownload = !empty($permissions['allow_download']);
        $canView = array_key_exists('can_view', $permissions) ? !empty($permissions['can_view']) : true;
        $canEdit = !empty($permissions['can_edit']);
        $canDownload = array_key_exists('can_download', $permissions) ? !empty($permissions['can_download']) : $allowDownload;
        $canDelete = !empty($permissions['can_delete']);
        $canShare = array_key_exists('can_share', $permissions) ? !empty($permissions['can_share']) : !empty($permissions['can_reshare']);
        $canArchive = !empty($permissions['can_archive']);
        $canReshare = !empty($permissions['can_reshare']);
        $currentExtension = strtolower((string)($currentVersion['extension'] ?? pathinfo((string)($currentVersion['original_name'] ?? ''), PATHINFO_EXTENSION)));
        if ($this->isExecutableExtension($currentExtension)) {
            if (!$this->isExecutableShareAllowed($currentExtension)) {
                throw new RuntimeException('Ce type d\'exécutable ne peut pas être partagé publiquement. Seuls les fichiers EXE et MSI validés sont autorisés.');
            }
            if (!$allowDownload) {
                throw new RuntimeException('Le partage d\'un exécutable autorisé impose aussi le téléchargement depuis le lien.');
            }
            $canEdit = false;
        }
        $recipientUserId = isset($recipient['id']) ? (int)$recipient['id'] : null;
        $recipientName = $this->normalizeNullableString($recipient['name'] ?? null);
        $recipientEmail = $this->normalizeNullableString($recipient['email'] ?? null);

        $token = hash('sha256', $documentId . '|' . $versionId . '|' . bin2hex(random_bytes(24)) . '|' . microtime(true));
        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_document_shares (document_id, version_id, token, allow_download, can_view, can_edit, can_download, can_delete, can_share, can_archive, can_reshare, recipient_user_id, recipient_name, recipient_email, expires_at, created_by)
             VALUES (:document_id, :version_id, :token, :allow_download, :can_view, :can_edit, :can_download, :can_delete, :can_share, :can_archive, :can_reshare, :recipient_user_id, :recipient_name, :recipient_email, :expires_at, :created_by)'
        );
        $stmt->execute([
            'document_id' => $documentId,
            'version_id' => $versionId,
            'token' => $token,
            'allow_download' => $allowDownload ? 1 : 0,
            'can_view' => $canView ? 1 : 0,
            'can_edit' => $canEdit ? 1 : 0,
            'can_download' => $canDownload ? 1 : 0,
            'can_delete' => $canDelete ? 1 : 0,
            'can_share' => $canShare ? 1 : 0,
            'can_archive' => $canArchive ? 1 : 0,
            'can_reshare' => $canReshare ? 1 : 0,
            'recipient_user_id' => $recipientUserId,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'expires_at' => $expiresAt,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        return [
            'token' => $token,
            'document_id' => $documentId,
            'version_id' => $versionId,
            'allow_download' => $allowDownload,
            'can_view' => $canView,
            'can_edit' => $canEdit,
            'can_download' => $canDownload,
            'can_delete' => $canDelete,
            'can_share' => $canShare,
            'can_archive' => $canArchive,
            'can_reshare' => $canReshare,
            'recipient_user_id' => $recipientUserId,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'expires_at' => $expiresAt,
        ];
    }

    public function getTechnicalExecutableExtensions(): array
    {
        $config = $this->getDocumentationSecurityConfig();
        $configured = array_map('strtolower', (array)($config['technical_executable_extensions'] ?? []));
        $fallback = ['exe', 'msi', 'bat', 'cmd', 'ps1'];
        return array_values(array_unique($configured ?: $fallback));
    }

    public function getShareableExecutableExtensions(): array
    {
        $config = $this->getDocumentationSecurityConfig();
        $configured = array_map('strtolower', (array)($config['shareable_executable_extensions'] ?? []));
        $technical = $this->getTechnicalExecutableExtensions();
        $shareable = array_values(array_intersect($configured ?: ['exe', 'msi'], $technical));
        return $shareable ?: ['exe', 'msi'];
    }

    public function createFolderShare(int $folderId, int $userId, array $permissions = [], ?string $expiresAt = null, ?array $recipient = null): array
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            throw new RuntimeException('Le dossier a partager est introuvable.');
        }

        $expiresAt = $this->normalizeNullableString($expiresAt);
        if ($expiresAt !== null) {
            $timestamp = strtotime($expiresAt);
            if ($timestamp === false) {
                throw new RuntimeException('Date d\'expiration invalide.');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
        }

        $allowDownload = !empty($permissions['allow_download']);
        $canView = array_key_exists('can_view', $permissions) ? !empty($permissions['can_view']) : true;
        $canEdit = !empty($permissions['can_edit']);
        $canDownload = array_key_exists('can_download', $permissions) ? !empty($permissions['can_download']) : $allowDownload;
        $canDelete = !empty($permissions['can_delete']);
        $canShare = array_key_exists('can_share', $permissions) ? !empty($permissions['can_share']) : !empty($permissions['can_reshare']);
        $canArchive = !empty($permissions['can_archive']);
        $canReshare = !empty($permissions['can_reshare']);
        $this->assertFolderSharePolicy($folderId, $allowDownload);
        $recipientUserId = isset($recipient['id']) ? (int)$recipient['id'] : null;
        $recipientName = $this->normalizeNullableString($recipient['name'] ?? null);
        $recipientEmail = $this->normalizeNullableString($recipient['email'] ?? null);

        $token = hash('sha256', 'folder|' . $folderId . '|' . bin2hex(random_bytes(24)) . '|' . microtime(true));
        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_folder_shares (folder_id, token, allow_download, can_view, can_edit, can_download, can_delete, can_share, can_archive, can_reshare, recipient_user_id, recipient_name, recipient_email, expires_at, created_by)
             VALUES (:folder_id, :token, :allow_download, :can_view, :can_edit, :can_download, :can_delete, :can_share, :can_archive, :can_reshare, :recipient_user_id, :recipient_name, :recipient_email, :expires_at, :created_by)'
        );
        $stmt->execute([
            'folder_id' => $folderId,
            'token' => $token,
            'allow_download' => $allowDownload ? 1 : 0,
            'can_view' => $canView ? 1 : 0,
            'can_edit' => $canEdit ? 1 : 0,
            'can_download' => $canDownload ? 1 : 0,
            'can_delete' => $canDelete ? 1 : 0,
            'can_share' => $canShare ? 1 : 0,
            'can_archive' => $canArchive ? 1 : 0,
            'can_reshare' => $canReshare ? 1 : 0,
            'recipient_user_id' => $recipientUserId,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'expires_at' => $expiresAt,
            'created_by' => $userId > 0 ? $userId : null,
        ]);

        return [
            'token' => $token,
            'folder_id' => $folderId,
            'allow_download' => $allowDownload,
            'can_view' => $canView,
            'can_edit' => $canEdit,
            'can_download' => $canDownload,
            'can_delete' => $canDelete,
            'can_share' => $canShare,
            'can_archive' => $canArchive,
            'can_reshare' => $canReshare,
            'recipient_user_id' => $recipientUserId,
            'recipient_name' => $recipientName,
            'recipient_email' => $recipientEmail,
            'expires_at' => $expiresAt,
        ];
    }

    public function updateSharedDocument(int $shareId, array $data, ?array $file, ?int $actorUserId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, d.title, d.folder_id, d.description, d.document_type, d.chantier_id, d.service_id, d.direction_code, d.required_permission, d.current_version_id
             FROM doc_document_shares s
             INNER JOIN doc_documents d ON d.id = s.document_id
             WHERE s.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $shareId]);
        $share = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$share) {
            throw new RuntimeException('Lien de partage introuvable.');
        }
        if (empty($share['can_edit'])) {
            throw new RuntimeException('Ce lien ne permet pas la modification du document.');
        }

        $newTitle = array_key_exists('title', $data) ? trim((string)$data['title']) : '';
        $hasTitleUpdate = $newTitle !== '' && $newTitle !== (string)($share['title'] ?? '');
        $hasFileUpdate = is_array($file) && !empty($file['tmp_name']) && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if (!$hasTitleUpdate && !$hasFileUpdate) {
            throw new RuntimeException('Aucune modification à enregistrer.');
        }

        $uploadedVersionId = null;
        if ($hasFileUpdate) {
            $uploadedVersionId = $this->appendDocumentVersion((int)$share['document_id'], $file, $actorUserId);
        }

        if ($hasTitleUpdate) {
            $this->renameDocument((int)$share['document_id'], $newTitle);
        }

        if ($uploadedVersionId !== null) {
            $stmt = $this->pdo->prepare('UPDATE doc_document_shares SET version_id = :version_id WHERE id = :id');
            $stmt->execute([
                'version_id' => $uploadedVersionId,
                'id' => $shareId,
            ]);
        }

        return [
            'document_id' => (int)$share['document_id'],
            'title' => $hasTitleUpdate ? $newTitle : (string)($share['title'] ?? ''),
            'version_id' => $uploadedVersionId ?? (int)($share['version_id'] ?? 0),
            'updated_title' => $hasTitleUpdate,
            'updated_file' => $uploadedVersionId !== null,
        ];
    }

    public function getShareByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT s.*, d.title, d.is_archived, d.document_type, d.required_permission,
                    v.original_name, v.mime_type, v.file_size, v.extension, v.stored_path
             FROM doc_document_shares s
             INNER JOIN doc_documents d ON d.id = s.document_id
             INNER JOIN doc_document_versions v ON v.id = s.version_id
             WHERE s.token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        if (!empty($row['revoked_at'])) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) !== false && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        if (isset($row['can_view']) && !(bool)$row['can_view']) {
            return null;
        }

        $absolute = $this->resolveStoredAbsolutePath((string)$row['stored_path']);
        if ($absolute === null || !is_file($absolute) || !is_readable($absolute)) {
            return null;
        }

        $row['absolute_path'] = $absolute;
        return $row;
    }

    public function getFolderShareByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT s.*, f.name AS folder_name, f.parent_id, f.required_permission, f.is_archived
             FROM doc_folder_shares s
             INNER JOIN doc_folders f ON f.id = s.folder_id
             WHERE s.token = :token
             LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        if (!empty($row['revoked_at'])) {
            return null;
        }
        if (!empty($row['expires_at']) && strtotime((string)$row['expires_at']) !== false && strtotime((string)$row['expires_at']) < time()) {
            return null;
        }
        if (isset($row['can_view']) && !(bool)$row['can_view']) {
            return null;
        }
        return $row;
    }

    public function markShareAccessed(int $shareId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE doc_document_shares
             SET last_accessed_at = NOW(), access_count = access_count + 1
             WHERE id = :id'
        );
        $stmt->execute(['id' => $shareId]);
    }

    public function markFolderShareAccessed(int $shareId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE doc_folder_shares
             SET last_accessed_at = NOW(), access_count = access_count + 1
             WHERE id = :id'
        );
        $stmt->execute(['id' => $shareId]);
    }

    public function getFolderShareBreadcrumb(int $rootFolderId, int $currentFolderId): array
    {
        $rootFolder = $this->getFolderById($rootFolderId);
        $currentFolder = $this->getFolderById($currentFolderId);
        if (!$rootFolder || !$currentFolder) {
            return [];
        }
        if ($currentFolderId !== $rootFolderId && !$this->isDescendantFolder($currentFolderId, $rootFolderId)) {
            $currentFolder = $rootFolder;
        }

        $stack = [];
        $cursor = $currentFolder;
        $safety = 0;
        while ($cursor && $safety < 200) {
            $stack[] = $cursor;
            $cursorId = (int)($cursor['id'] ?? 0);
            if ($cursorId === $rootFolderId) {
                break;
            }
            $parentId = (int)($cursor['parent_id'] ?? 0);
            $cursor = $parentId > 0 ? $this->getFolderById($parentId) : null;
            $safety++;
        }

        return array_reverse($stack);
    }

    public function listFolderShareContents(int $rootFolderId, int $currentFolderId): array
    {
        $rootFolder = $this->getFolderById($rootFolderId);
        if (!$rootFolder) {
            throw new RuntimeException('Dossier partage introuvable.');
        }

        $targetFolderId = $currentFolderId > 0 ? $currentFolderId : $rootFolderId;
        if ($targetFolderId !== $rootFolderId && !$this->isDescendantFolder($targetFolderId, $rootFolderId)) {
            $targetFolderId = $rootFolderId;
        }

        $folderStmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE parent_id = :parent_id AND is_archived = 0 ORDER BY name ASC');
        $folderStmt->execute(['parent_id' => $targetFolderId]);
        $folders = $folderStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $documentStmt = $this->pdo->prepare(
            'SELECT d.*, f.name AS folder_name,
                    v.id AS version_id, v.original_name, v.mime_type, v.file_size, v.extension, v.uploaded_at
             FROM doc_documents d
             LEFT JOIN doc_folders f ON f.id = d.folder_id
             LEFT JOIN doc_document_versions v ON v.id = d.current_version_id
             WHERE d.folder_id = :folder_id AND d.is_archived = 0
             ORDER BY d.title ASC'
        );
        $documentStmt->execute(['folder_id' => $targetFolderId]);
        $documents = $documentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'root_folder' => $rootFolder,
            'current_folder' => $this->getFolderById($targetFolderId) ?: $rootFolder,
            'folders' => $folders,
            'documents' => $documents,
            'breadcrumb' => $this->getFolderShareBreadcrumb($rootFolderId, $targetFolderId),
        ];
    }

    public function getDocumentForFolderShare(int $rootFolderId, int $documentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, f.name AS folder_name,
                    v.id AS version_id, v.original_name, v.mime_type, v.file_size, v.extension, v.stored_path
             FROM doc_documents d
             LEFT JOIN doc_folders f ON f.id = d.folder_id
             LEFT JOIN doc_document_versions v ON v.id = d.current_version_id
             WHERE d.id = :id AND d.is_archived = 0
             LIMIT 1'
        );
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        $folderId = (int)($row['folder_id'] ?? 0);
        if ($folderId !== $rootFolderId && !$this->isDescendantFolder($folderId, $rootFolderId)) {
            return null;
        }

        if (!empty($row['stored_path'])) {
            $absolute = $this->resolveStoredAbsolutePath((string)$row['stored_path']);
            if ($absolute !== null && is_file($absolute) && is_readable($absolute)) {
                $row['absolute_path'] = $absolute;
            }
        }

        return $row;
    }

    public function listDocumentShares(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.token, s.allow_download, s.can_view, s.can_edit, s.can_download, s.can_delete, s.can_share, s.can_archive, s.can_reshare,
                    s.recipient_user_id, s.recipient_name, s.recipient_email,
                    s.expires_at, s.revoked_at, s.created_at, s.access_count,
                    s.created_by,
                    u.prenom AS creator_first_name,
                    u.nom AS creator_last_name,
                    u.mail_pro AS creator_email
             FROM doc_document_shares s
             LEFT JOIN user_devis_banamur u ON u.id = s.created_by
             WHERE document_id = :document_id
             ORDER BY created_at DESC'
        );
        $stmt->execute(['document_id' => $documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $creatorName = trim((string)(($row['creator_first_name'] ?? '') . ' ' . ($row['creator_last_name'] ?? '')));
            $row['creator_name'] = $creatorName !== '' ? $creatorName : trim((string)($row['creator_email'] ?? ''));
        }
        unset($row);
        return $rows;
    }

    public function listDocumentShareActivity(int $documentId, int $limit = 25): array
    {
        $documentId = (int)$documentId;
        if ($documentId <= 0) {
            return [];
        }

        $limit = max(1, min(100, (int)$limit));
        \AuditTrail::ensureSchema($this->pdo);

        $stmt = $this->pdo->prepare(
            'SELECT id, event_type, action, entity_label, user_name, user_email, result, severity, details_json, created_at
             FROM audit_trail
             WHERE module = :module
               AND entity_type = :entity_type
               AND entity_id = :entity_id
               AND event_type IN (:share_created, :share_edited, :share_download, :share_download_denied, :share_edit_denied, :view, :download, :edit, :delete, :share, :archive)
             ORDER BY created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'module' => 'documentation',
            'entity_type' => 'doc_document',
            'entity_id' => (string)$documentId,
            'share_created' => 'documentation_share_created',
            'share_edited' => 'documentation_share_edited',
            'share_download' => 'documentation_share_download',
            'share_download_denied' => 'documentation_share_download_denied',
            'share_edit_denied' => 'documentation_share_edit_denied',
            'view' => 'documentation_view',
            'download' => 'documentation_download',
            'edit' => 'documentation_edit',
            'delete' => 'documentation_delete',
            'share' => 'documentation_share',
            'archive' => 'documentation_archive',
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $details = json_decode((string)($row['details_json'] ?? ''), true);
            $row['details'] = is_array($details) ? $details : [];
        }
        unset($row);
        return $rows;
    }

    public function getFolderById(int $folderId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $folderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float)max(0, $bytes);
        $index = 0;
        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }
        return number_format($size, $index === 0 ? 0 : 1, ',', ' ') . ' ' . $units[$index];
    }

    public function canAccessPermission(?string $requiredPermission, array $userPermissions = []): bool
    {
        $requiredPermission = trim((string)$requiredPermission);
        if ($requiredPermission === '') {
            return true;
        }
        if (!empty($userPermissions['acces_admin']) || !empty($userPermissions['document_admin'])) {
            return true;
        }
        return !empty($userPermissions[$requiredPermission]);
    }

    public function canAccessDocument(array $document, string $action = 'view', ?int $userId = null, array $userPermissions = []): bool
    {
        return $this->canAccessEntityRow('document', $document, $action, $userId, $userPermissions);
    }

    public function canAccessFolder(array $folder, string $action = 'view', ?int $userId = null, array $userPermissions = []): bool
    {
        return $this->canAccessEntityRow('folder', $folder, $action, $userId, $userPermissions);
    }

    public function assertDocumentAccess(int $documentId, string $action, ?int $userId, array $userPermissions = []): array
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Document introuvable.');
        }
        if (!$this->canAccessDocument($document, $action, $userId, $userPermissions)) {
            $this->logDocumentAccess($action . '_denied', $document, $userId, 'denied');
            throw new RuntimeException('Acces refuse a ce document.');
        }
        return $document;
    }

    public function assertFolderAccess(int $folderId, string $action, ?int $userId, array $userPermissions = []): array
    {
        $folder = $this->getFolderById($folderId);
        if (!$folder) {
            throw new RuntimeException('Dossier introuvable.');
        }
        if (!$this->canAccessFolder($folder, $action, $userId, $userPermissions)) {
            $this->logFolderAccess($action . '_denied', $folder, $userId, 'denied');
            throw new RuntimeException('Acces refuse a ce dossier.');
        }
        return $folder;
    }

    public function logDocumentAccess(string $action, array $document, ?int $userId = null, string $result = 'success', array $details = []): void
    {
        $action = $this->normalizeAclAction($action);
        \AuditTrail::log('documentation_' . $action, [
            'module' => 'documentation',
            'action' => $action,
            'entity_type' => 'doc_document',
            'entity_id' => (string)($document['id'] ?? $document['document_id'] ?? 0),
            'entity_label' => (string)($document['title'] ?? $document['original_name'] ?? 'Document'),
            'user_id' => $userId ?: null,
            'result' => $result,
            'severity' => $result === 'denied' ? 'warning' : 'info',
            'details' => $details,
        ], $this->pdo);
    }

    public function logFolderAccess(string $action, array $folder, ?int $userId = null, string $result = 'success', array $details = []): void
    {
        $action = $this->normalizeAclAction($action);
        \AuditTrail::log('documentation_' . $action, [
            'module' => 'documentation',
            'action' => $action,
            'entity_type' => 'doc_folder',
            'entity_id' => (string)($folder['id'] ?? $folder['folder_id'] ?? 0),
            'entity_label' => (string)($folder['name'] ?? $folder['folder_name'] ?? 'Dossier'),
            'user_id' => $userId ?: null,
            'result' => $result,
            'severity' => $result === 'denied' ? 'warning' : 'info',
            'details' => $details,
        ], $this->pdo);
    }

    public function upsertAcl(string $entityType, int $entityId, string $subjectType, int $subjectId, array $rights, ?int $createdBy = null): void
    {
        $entityType = $entityType === 'folder' ? 'folder' : 'document';
        $subjectType = $subjectType === 'user' ? 'user' : 'service';
        if ($entityId <= 0 || $subjectId <= 0) {
            throw new RuntimeException('Cible ACL invalide.');
        }

        $payload = [
            'can_view' => !empty($rights['can_view']) ? 1 : 0,
            'can_edit' => !empty($rights['can_edit']) ? 1 : 0,
            'can_download' => !empty($rights['can_download']) ? 1 : 0,
            'can_delete' => !empty($rights['can_delete']) ? 1 : 0,
            'can_share' => !empty($rights['can_share']) ? 1 : 0,
            'can_archive' => !empty($rights['can_archive']) ? 1 : 0,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO doc_access_acl (
                entity_type, entity_id, subject_type, subject_id,
                can_view, can_edit, can_download, can_delete, can_share, can_archive, created_by
            ) VALUES (
                :entity_type, :entity_id, :subject_type, :subject_id,
                :can_view, :can_edit, :can_download, :can_delete, :can_share, :can_archive, :created_by
            )
            ON DUPLICATE KEY UPDATE
                can_view = VALUES(can_view),
                can_edit = VALUES(can_edit),
                can_download = VALUES(can_download),
                can_delete = VALUES(can_delete),
                can_share = VALUES(can_share),
                can_archive = VALUES(can_archive),
                updated_at = NOW()'
        );
        $stmt->execute(array_merge($payload, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'created_by' => $createdBy && $createdBy > 0 ? $createdBy : null,
        ]));
    }

    public function listAclEntries(string $entityType, int $entityId): array
    {
        $entityType = $entityType === 'folder' ? 'folder' : 'document';
        if ($entityId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, s.lib_service AS service_label,
                    CONCAT_WS(" ", u.prenom, u.nom) AS user_label, u.mail_pro AS user_email
             FROM doc_access_acl a
             LEFT JOIN service s ON a.subject_type = "service" AND s.id_service = a.subject_id
             LEFT JOIN user_devis_banamur u ON a.subject_type = "user" AND u.id = a.subject_id
             WHERE a.entity_type = :entity_type AND a.entity_id = :entity_id
             ORDER BY a.subject_type ASC, COALESCE(s.lib_service, user_label, a.subject_id) ASC'
        );
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listEffectiveAccessUsers(string $entityType = 'document', int $entityId = 0, ?int $serviceId = null, ?int $targetUserId = null): array
    {
        $entityType = $entityType === 'folder' ? 'folder' : 'document';
        $serviceId = $this->normalizeNullableInt($serviceId);
        $entity = null;
        if ($entityId > 0) {
            $entity = $entityType === 'folder' ? $this->getFolderById($entityId) : $this->getDocumentById($entityId);
            if ($serviceId === null && $entity) {
                $serviceId = $this->normalizeNullableInt($entity['service_id'] ?? null);
            }
        }
        $columns = $this->getTableColumns('user_devis_banamur');
        $hasPersonnel = in_array('personnel_id', $columns, true);
        $hasDocumentAdmin = in_array('document_admin', $columns, true);
        $hasGlobalAdmin = in_array('acces_admin', $columns, true);

        $select = 'SELECT u.id, u.prenom, u.nom, u.mail_pro, u.role_id';
        $select .= $hasGlobalAdmin ? ', COALESCE(u.acces_admin, 0) AS acces_admin' : ', 0 AS acces_admin';
        $select .= $hasDocumentAdmin ? ', COALESCE(u.document_admin, 0) AS document_admin' : ', 0 AS document_admin';
        $join = ' LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id';
        $select .= ', COALESCE(r.lib_role_devis, "") AS role_label';
        if ($hasPersonnel) {
            $select .= ', COALESCE(p.service_id, 0) AS service_id, COALESCE(s.lib_service, "") AS service_label';
            $join .= ' LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id
                       LEFT JOIN service s ON s.id_service = p.service_id';
        } elseif (in_array('service_id', $columns, true)) {
            $select .= ', COALESCE(u.service_id, 0) AS service_id, COALESCE(s.lib_service, "") AS service_label';
            $join .= ' LEFT JOIN service s ON s.id_service = u.service_id';
        } else {
            $select .= ', 0 AS service_id, "" AS service_label';
        }

        $stmt = $this->pdo->query($select . ' FROM user_devis_banamur u' . $join . ' WHERE u.active = 1 ORDER BY u.prenom ASC, u.nom ASC, u.id ASC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        $aclByUser = [];
        $aclDeniedUsers = [];
        $aclServices = [];
        if ($entityId > 0) {
            foreach ($this->listAclEntries($entityType, $entityId) as $acl) {
                $subjectId = (int)($acl['subject_id'] ?? 0);
                if ($subjectId <= 0) {
                    continue;
                }
                if (($acl['subject_type'] ?? '') === 'user') {
                    if (empty($acl['can_view'])) {
                        $aclDeniedUsers[$subjectId] = true;
                    } else {
                        $aclByUser[$subjectId][] = 'ACL explicite';
                    }
                    $aclByUser[$subjectId . '_rights'] = $this->rightsFromAclRow($acl);
                } elseif (($acl['subject_type'] ?? '') === 'service') {
                    if (!empty($acl['can_view'])) {
                        $aclServices[$subjectId] = (string)($acl['service_label'] ?? ('Service #' . $subjectId));
                    }
                    $aclServices[$subjectId . '_rights'] = $this->rightsFromAclRow($acl);
                }
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $userId = (int)($row['id'] ?? 0);
            $userServiceId = (int)($row['service_id'] ?? 0);
            $badges = [];
            $rights = [
                'view' => false,
                'edit' => false,
                'download' => false,
                'delete' => false,
                'share' => false,
                'archive' => false,
            ];

            if (!empty($row['acces_admin'])) {
                $badges[] = ['label' => 'Admin', 'tone' => 'rose'];
                $rights = array_fill_keys(array_keys($rights), true);
            }
            if (!empty($row['document_admin'])) {
                $badges[] = ['label' => 'Admin document', 'tone' => 'amber'];
                $rights = array_fill_keys(array_keys($rights), true);
            }
            $isAdminLike = !empty($row['acces_admin']) || !empty($row['document_admin']);
            $isOwner = $entity && $userId > 0 && (int)($entity['created_by'] ?? 0) === $userId;
            if ($isOwner) {
                $badges[] = ['label' => 'Proprietaire', 'tone' => 'slate'];
                $rights = array_fill_keys(array_keys($rights), true);
            }
            if (!$isAdminLike && !$isOwner && !empty($aclDeniedUsers[$userId])) {
                continue;
            }
            if ($targetUserId !== null && $targetUserId > 0 && $userId === $targetUserId) {
                $badges[] = ['label' => 'Utilisateur choisi', 'tone' => 'violet'];
                $rights['view'] = true;
            }
            if ($serviceId !== null && $serviceId > 0 && $userServiceId === $serviceId) {
                $badges[] = ['label' => 'Service: ' . ((string)($row['service_label'] ?? '') ?: ('#' . $serviceId)), 'tone' => 'emerald'];
                $rights = $this->mergeRights($rights, [
                    'view' => true,
                    'edit' => true,
                    'download' => true,
                    'delete' => false,
                    'share' => true,
                    'archive' => true,
                ]);
            }
            if (isset($aclServices[$userServiceId])) {
                $badges[] = ['label' => 'ACL service: ' . $aclServices[$userServiceId], 'tone' => 'sky'];
                $rights = $this->mergeRights($rights, $aclServices[$userServiceId . '_rights'] ?? []);
            }
            foreach ($aclByUser[$userId] ?? [] as $label) {
                $badges[] = ['label' => $label, 'tone' => 'violet'];
            }
            if (!$isAdminLike && !$isOwner && isset($aclByUser[$userId . '_rights'])) {
                $rights = $this->rightsFromAclRow([
                    'can_view' => $aclByUser[$userId . '_rights']['view'] ?? false,
                    'can_edit' => $aclByUser[$userId . '_rights']['edit'] ?? false,
                    'can_download' => $aclByUser[$userId . '_rights']['download'] ?? false,
                    'can_delete' => $aclByUser[$userId . '_rights']['delete'] ?? false,
                    'can_share' => $aclByUser[$userId . '_rights']['share'] ?? false,
                    'can_archive' => $aclByUser[$userId . '_rights']['archive'] ?? false,
                ]);
            }

            if (!$badges) {
                continue;
            }

            $name = trim((string)(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $result[] = [
                'id' => $userId,
                'name' => $name !== '' ? $name : (string)($row['mail_pro'] ?? ('Utilisateur #' . $userId)),
                'email' => (string)($row['mail_pro'] ?? ''),
                'service_id' => $userServiceId,
                'service_label' => (string)($row['service_label'] ?? ''),
                'role_label' => (string)($row['role_label'] ?? ''),
                'can_remove' => !$isAdminLike && !$isOwner,
                'is_protected' => $isAdminLike || $isOwner,
                'rights' => $rights,
                'badges' => $badges,
            ];
        }

        return $result;
    }

    private function rightsFromAclRow(array $row): array
    {
        return [
            'view' => !empty($row['can_view']),
            'edit' => !empty($row['can_edit']),
            'download' => !empty($row['can_download']),
            'delete' => !empty($row['can_delete']),
            'share' => !empty($row['can_share']),
            'archive' => !empty($row['can_archive']),
        ];
    }

    private function mergeRights(array $base, array $extra): array
    {
        foreach (['view', 'edit', 'download', 'delete', 'share', 'archive'] as $right) {
            $base[$right] = !empty($base[$right]) || !empty($extra[$right]);
        }
        return $base;
    }

    private function canAccessEntityRow(string $entityType, array $row, string $action, ?int $userId, array $userPermissions): bool
    {
        $action = $this->normalizeAclAction($action);
        $context = $this->getUserDocumentationContext($userId, $userPermissions);
        if (!empty($context['is_global_admin']) || !empty($context['is_document_admin'])) {
            return true;
        }

        $entityId = (int)($row['id'] ?? $row[$entityType . '_id'] ?? 0);
        $userId = (int)($context['user_id'] ?? 0);
        $serviceId = (int)($context['service_id'] ?? 0);

        if ($userId > 0 && (int)($row['created_by'] ?? 0) === $userId) {
            return true;
        }

        $explicit = $this->getExplicitAcl($entityType, $entityId, $userId, $serviceId);
        if ($explicit !== null) {
            return !empty($explicit[$this->aclColumnForAction($action)]);
        }

        $shared = $this->getInternalShareAcl($entityType, $entityId, $userId);
        if ($shared !== null) {
            return !empty($shared[$this->aclColumnForAction($action)]);
        }

        if (!$this->canAccessPermission($row['required_permission'] ?? null, $userPermissions)) {
            return false;
        }

        $rowServiceId = (int)($row['service_id'] ?? 0);
        $sameService = $rowServiceId > 0 && $serviceId > 0 && $rowServiceId === $serviceId;
        $inScope = $sameService;
        if (!$inScope) {
            return false;
        }

        if ($action === 'view') {
            return true;
        }

        return match ($action) {
            'edit' => !empty($userPermissions['document_edit']),
            'download' => !empty($userPermissions['document_download']) || !empty($userPermissions['document_export']),
            'delete', 'archive' => !empty($userPermissions['document_archive']),
            'share' => !empty($userPermissions['document_share']) || !empty($userPermissions['document_edit']),
            default => false,
        };
    }

    private function getExplicitAcl(string $entityType, int $entityId, int $userId, int $serviceId): ?array
    {
        if ($entityId <= 0 || ($userId <= 0 && $serviceId <= 0)) {
            return null;
        }

        $where = [];
        $params = ['entity_type' => $entityType, 'entity_id' => $entityId];
        if ($userId > 0) {
            $where[] = '(subject_type = "user" AND subject_id = :user_id)';
            $params['user_id'] = $userId;
        }
        if ($serviceId > 0) {
            $where[] = '(subject_type = "service" AND subject_id = :service_id)';
            $params['service_id'] = $serviceId;
        }
        if (!$where) {
            return null;
        }

        $stmt = $this->pdo->prepare("SELECT * FROM doc_access_acl WHERE entity_type = :entity_type AND entity_id = :entity_id AND (" . implode(' OR ', $where) . ") ORDER BY subject_type = 'user' DESC LIMIT 1");
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    }

    private function getInternalShareAcl(string $entityType, int $entityId, int $userId): ?array
    {
        if ($entityId <= 0 || $userId <= 0) {
            return null;
        }
        $table = $entityType === 'folder' ? 'doc_folder_shares' : 'doc_document_shares';
        $idColumn = $entityType === 'folder' ? 'folder_id' : 'document_id';
        $stmt = $this->pdo->prepare(
            "SELECT can_view, can_edit, can_download, can_delete, can_share, can_archive, allow_download, can_reshare
             FROM {$table}
             WHERE {$idColumn} = :entity_id
               AND recipient_user_id = :user_id
               AND revoked_at IS NULL
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['entity_id' => $entityId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }
        $row['can_download'] = !empty($row['can_download']) || !empty($row['allow_download']);
        $row['can_share'] = !empty($row['can_share']) || !empty($row['can_reshare']);
        return $row;
    }

    private function aclColumnForAction(string $action): string
    {
        return match ($this->normalizeAclAction($action)) {
            'edit' => 'can_edit',
            'download' => 'can_download',
            'delete' => 'can_delete',
            'share' => 'can_share',
            'archive' => 'can_archive',
            default => 'can_view',
        };
    }

    private function normalizeAclAction(string $action): string
    {
        $action = strtolower(trim($action));
        $action = str_replace(['documentation_', '_document', '_folder'], '', $action);
        $action = str_replace(['_denied', 'denied_'], '', $action);
        return match ($action) {
            'read', 'preview', 'visualisation', 'view' => 'view',
            'rename', 'move', 'update', 'edition', 'edit' => 'edit',
            'telechargement', 'download' => 'download',
            'suppression', 'delete' => 'delete',
            'partage', 'share' => 'share',
            'archivage', 'archive', 'restore' => 'archive',
            default => $action !== '' ? $action : 'view',
        };
    }

    private function getCurrentSessionUserId(): int
    {
        return (int)($_SESSION['user_id'] ?? 0);
    }

    private function ensureStorageRoot(): void
    {
        $root = $this->getStorageRoot();
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
        $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Require all denied\n");
        }
    }

    private function getStorageRoot(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'documents';
    }

    private function buildStorageRelativeDirectory(string $spaceType, ?int $chantierId, ?int $serviceId, ?string $directionCode): string
    {
        $parts = [$spaceType];
        if ($chantierId !== null) {
            $parts[] = 'chantier_' . $chantierId;
        } elseif ($serviceId !== null) {
            $parts[] = 'service_' . $serviceId;
        } elseif ($directionCode !== null && $directionCode !== '') {
            $parts[] = $this->slugify($directionCode);
        }
        $parts[] = date('Y');
        $parts[] = date('m');
        return implode('/', array_filter($parts));
    }

    private function ensureStorageDirectory(string $relativeDirectory): string
    {
        $root = $this->getStorageRoot();
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, trim($relativeDirectory, '/'));
        if (!is_dir($absolute)) {
            if (!@mkdir($absolute, 0775, true) && !is_dir($absolute)) {
                throw new RuntimeException('Impossible de créer le dossier de stockage.');
            }
        }
        return $absolute;
    }

    private function resolveStoredAbsolutePath(string $storedPath): ?string
    {
        $storedPath = trim(str_replace(['..\\', '../', '\\'], ['', '', '/'], $storedPath), '/');
        if ($storedPath === '') {
            return null;
        }

        $absolute = realpath($this->getStorageRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
        $root = realpath($this->getStorageRoot());
        if ($absolute === false || $root === false) {
            return null;
        }
        if (strpos(str_replace('\\', '/', $absolute), str_replace('\\', '/', $root)) !== 0) {
            return null;
        }
        return $absolute;
    }

    private function appendDocumentVersion(int $documentId, array $file, ?int $actorUserId = null): int
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            throw new RuntimeException('Le document à mettre à jour est introuvable.');
        }
        $folder = !empty($document['folder_id']) ? $this->getFolderById((int)$document['folder_id']) : null;

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le fichier envoyé est invalide.');
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $name = trim((string)($file['name'] ?? ''));
        $size = (int)($file['size'] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Le fichier temporaire est introuvable.');
        }
        if ($name === '') {
            throw new RuntimeException('Le nom du fichier est invalide.');
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->getAllowedDocumentExtensions(), true)) {
            throw new RuntimeException('Type de fichier non autorisé pour cette nouvelle version.');
        }
        $relativeDir = $this->buildStorageRelativeDirectory(
            $folder['space_type'] ?? 'general',
            $this->normalizeNullableInt($document['chantier_id'] ?? null),
            $this->normalizeNullableInt($document['service_id'] ?? null),
            $this->normalizeNullableString($document['direction_code'] ?? null)
        );
        $absoluteDir = $this->ensureStorageDirectory($relativeDir);
        $safeBaseName = $this->slugify(pathinfo($name, PATHINFO_FILENAME));
        if ($safeBaseName === '') {
            $safeBaseName = 'document';
        }
        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '_' . $safeBaseName . ($extension !== '' ? '.' . $extension : '');
        $relativePath = trim($relativeDir . '/' . $storedName, '/');
        $absolutePath = $absoluteDir . DIRECTORY_SEPARATOR . $storedName;

        if (!@move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Impossible de stocker la nouvelle version du document.');
        }

        $checksum = hash_file('sha256', $absolutePath) ?: null;
        $mimeType = (string)($file['type'] ?? 'application/octet-stream');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM doc_document_versions WHERE document_id = :document_id');
            $stmt->execute(['document_id' => $documentId]);
            $nextVersion = (int)$stmt->fetchColumn();
            if ($nextVersion <= 0) {
                $nextVersion = 1;
            }

            $this->pdo->prepare('UPDATE doc_document_versions SET is_current = 0 WHERE document_id = :document_id')->execute(['document_id' => $documentId]);

            $stmtVersion = $this->pdo->prepare(
                'INSERT INTO doc_document_versions (document_id, version_number, stored_path, original_name, extension, mime_type, file_size, checksum_sha256, uploaded_by, is_current)
                 VALUES (:document_id, :version_number, :stored_path, :original_name, :extension, :mime_type, :file_size, :checksum_sha256, :uploaded_by, 1)'
            );
            $stmtVersion->execute([
                'document_id' => $documentId,
                'version_number' => $nextVersion,
                'stored_path' => $relativePath,
                'original_name' => $name,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'file_size' => $size,
                'checksum_sha256' => $checksum,
                'uploaded_by' => $actorUserId && $actorUserId > 0 ? $actorUserId : null,
            ]);

            $versionId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE doc_documents SET current_version_id = :version_id WHERE id = :id')->execute([
                'version_id' => $versionId,
                'id' => $documentId,
            ]);
            $this->pdo->commit();

            return $versionId;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            @unlink($absolutePath);
            throw $e;
        }
    }

    private function getDocumentCurrentVersionRow(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT v.*
             FROM doc_documents d
             INNER JOIN doc_document_versions v ON v.id = d.current_version_id
             WHERE d.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isExecutableExtension(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getTechnicalExecutableExtensions(), true);
    }

    private function isExecutableShareAllowed(string $extension): bool
    {
        return in_array(strtolower($extension), $this->getShareableExecutableExtensions(), true);
    }

    private function assertFolderSharePolicy(int $folderId, bool $allowDownload): void
    {
        foreach ($this->listDocumentsInFolder($folderId, false) as $document) {
            $extension = strtolower((string)($document['extension'] ?? pathinfo((string)($document['original_name'] ?? ''), PATHINFO_EXTENSION)));
            if (!$this->isExecutableExtension($extension)) {
                continue;
            }
            if (!$this->isExecutableShareAllowed($extension)) {
                throw new RuntimeException('Partage de dossier refusé: ce dossier contient au moins un exécutable non autorisé en lien public (' . strtoupper($extension) . ').');
            }
            if (!$allowDownload) {
                throw new RuntimeException('Partage de dossier refusé: un dossier contenant des exécutables techniques autorisés doit aussi activer le téléchargement.');
            }
        }

        foreach ($this->listChildFolders($folderId, false) as $childFolder) {
            $this->assertFolderSharePolicy((int)($childFolder['id'] ?? 0), $allowDownload);
        }
    }

    private function getDocumentationSecurityConfig(): array
    {
        static $config;
        if ($config !== null) {
            return $config;
        }

        $configPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'documentation_security.php';
        if (is_file($configPath)) {
            $loaded = require $configPath;
            if (is_array($loaded)) {
                $config = $loaded;
                return $config;
            }
        }

        $config = [];
        return $config;
    }

    private function findMoveConflictForDocument(int $documentId, ?int $targetFolderId): ?array
    {
        $document = $this->getDocumentById($documentId);
        if (!$document) {
            return null;
        }

        $version = $this->getDocumentCurrentVersionRow($documentId);
        if ($version) {
            $existingByOriginalName = $this->findDocumentByOriginalNameInFolder((string)($version['original_name'] ?? ''), $targetFolderId, $documentId);
            if ($existingByOriginalName) {
                return [
                    'type' => 'document',
                    'id' => $documentId,
                    'name' => (string)($version['original_name'] ?? ''),
                    'mode' => 'original_name',
                    'existing_document_id' => (int)($existingByOriginalName['id'] ?? 0),
                ];
            }
        }

        $existingByTitle = $this->findDocumentByTitleInFolder((string)($document['title'] ?? ''), $targetFolderId, $documentId);
        if ($existingByTitle) {
            return [
                'type' => 'document',
                'id' => $documentId,
                'name' => (string)($document['title'] ?? ''),
                'mode' => 'title',
                'existing_document_id' => (int)($existingByTitle['id'] ?? 0),
            ];
        }

        return null;
    }

    private function assertFolderNameAvailable(string $name, ?int $parentId, ?int $excludeFolderId = null): void
    {
        $existing = $this->findFolderByNameAndParent($name, $parentId);
        if ($existing && (int)($existing['id'] ?? 0) !== (int)$excludeFolderId) {
            throw new RuntimeException('Un dossier portant deja ce nom existe deja dans ce repertoire.', 409);
        }
    }

    private function assertDocumentTitleAvailable(string $title, ?int $folderId, ?int $excludeDocumentId = null): void
    {
        $title = trim($title);
        if ($title === '') {
            return;
        }

        $existing = $this->findDocumentByTitleInFolder($title, $folderId, $excludeDocumentId);
        if ($existing) {
            throw new RuntimeException('Un document portant deja ce nom existe deja dans ce repertoire.', 409);
        }
    }

    private function findDocumentByTitleInFolder(string $title, ?int $folderId, ?int $excludeDocumentId = null): ?array
    {
        if ($folderId === null) {
            $sql = 'SELECT * FROM doc_documents WHERE folder_id IS NULL AND LOWER(TRIM(title)) = LOWER(TRIM(:title))';
            $params = ['title' => $title];
        } else {
            $sql = 'SELECT * FROM doc_documents WHERE folder_id = :folder_id AND LOWER(TRIM(title)) = LOWER(TRIM(:title))';
            $params = ['folder_id' => $folderId, 'title' => $title];
        }
        if ($excludeDocumentId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params['exclude_id'] = $excludeDocumentId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findDocumentByOriginalNameInFolder(string $originalName, ?int $folderId, ?int $excludeDocumentId = null): ?array
    {
        $originalName = trim($originalName);
        if ($originalName === '') {
            return null;
        }

        if ($folderId === null) {
            $sql = 'SELECT d.*, v.original_name
                    FROM doc_documents d
                    INNER JOIN doc_document_versions v ON v.id = d.current_version_id
                    WHERE d.folder_id IS NULL AND LOWER(TRIM(v.original_name)) = LOWER(TRIM(:original_name))';
            $params = ['original_name' => $originalName];
        } else {
            $sql = 'SELECT d.*, v.original_name
                    FROM doc_documents d
                    INNER JOIN doc_document_versions v ON v.id = d.current_version_id
                    WHERE d.folder_id = :folder_id AND LOWER(TRIM(v.original_name)) = LOWER(TRIM(:original_name))';
            $params = ['folder_id' => $folderId, 'original_name' => $originalName];
        }
        if ($excludeDocumentId !== null) {
            $sql .= ' AND d.id <> :exclude_id';
            $params['exclude_id'] = $excludeDocumentId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function moveDocumentStoredFiles(int $documentId, array $targetContext): void
    {
        $stmt = $this->pdo->prepare('SELECT id, stored_path FROM doc_document_versions WHERE document_id = :document_id ORDER BY version_number ASC');
        $stmt->execute(['document_id' => $documentId]);
        $versions = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$versions) {
            return;
        }

        $relativeDir = $this->buildStorageRelativeDirectory(
            $this->normalizeSpaceType($targetContext['space_type'] ?? 'general'),
            $this->normalizeNullableInt($targetContext['chantier_id'] ?? null),
            $this->normalizeNullableInt($targetContext['service_id'] ?? null),
            $this->normalizeNullableString($targetContext['direction_code'] ?? null)
        );
        $absoluteDir = $this->ensureStorageDirectory($relativeDir);

        foreach ($versions as $version) {
            $storedPath = trim((string)($version['stored_path'] ?? ''));
            $absoluteSource = $this->resolveStoredAbsolutePath($storedPath);
            if ($absoluteSource === null || !is_file($absoluteSource)) {
                continue;
            }

            $basename = basename($absoluteSource);
            $destinationAbsolute = $absoluteDir . DIRECTORY_SEPARATOR . $basename;
            if (strcasecmp($absoluteSource, $destinationAbsolute) !== 0 && file_exists($destinationAbsolute)) {
                $info = pathinfo($basename);
                $base = (string)($info['filename'] ?? 'document');
                $ext = isset($info['extension']) && $info['extension'] !== '' ? '.' . $info['extension'] : '';
                $basename = $base . '_' . bin2hex(random_bytes(4)) . $ext;
                $destinationAbsolute = $absoluteDir . DIRECTORY_SEPARATOR . $basename;
            }

            if (strcasecmp($absoluteSource, $destinationAbsolute) !== 0) {
                if (!@rename($absoluteSource, $destinationAbsolute)) {
                    if (!@copy($absoluteSource, $destinationAbsolute) || !@unlink($absoluteSource)) {
                        throw new RuntimeException('Impossible de deplacer physiquement le fichier sur le disque.');
                    }
                }
            }

            $newRelativePath = trim($relativeDir . '/' . $basename, '/');
            $this->pdo->prepare('UPDATE doc_document_versions SET stored_path = :stored_path WHERE id = :id')->execute([
                'stored_path' => $newRelativePath,
                'id' => (int)$version['id'],
            ]);
        }
    }

    private function normalizeNullableInt($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }

    private function normalizeNullableString($value): ?string
    {
        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    private function findFolderByNameAndParent(string $name, ?int $parentId): ?array
    {
        if ($parentId === null) {
            $stmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE parent_id IS NULL AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1');
            $stmt->execute(['name' => $name]);
        } else {
            $stmt = $this->pdo->prepare('SELECT * FROM doc_folders WHERE parent_id = :parent_id AND LOWER(TRIM(name)) = LOWER(TRIM(:name)) LIMIT 1');
            $stmt->execute([
                'parent_id' => $parentId,
                'name' => $name,
            ]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function normalizeSpaceType($value): string
    {
        $value = trim((string)$value);
        $allowed = ['chantier', 'direction', 'service', 'general', 'archive'];
        return in_array($value, $allowed, true) ? $value : 'general';
    }

    private function getAllowedDocumentExtensions(): array
    {
        return [
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
            'bmp',
            'svg',
            'mp4',
            'webm',
            'mov',
            'avi',
            'mkv',
            'm4v',
            'ogv',
            'dwg',
            'dxf',
            'zip',
            'rar',
            '7z',
            'txt',
            'csv',
            'log',
            'json',
            'xml',
            'exe',
            'msi',
            'bat',
            'cmd',
            'ps1'
        ];
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;
        return trim($value, '-');
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare("SHOW COLUMNS FROM {$table} LIKE :column");
            $stmt->execute(['column' => $column]);
            return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getTableColumns(string $table): array
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM {$table}");
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private function isDescendantFolder(int $candidateChildId, int $folderId): bool
    {
        $cursor = $this->getFolderById($candidateChildId);
        $safety = 0;
        while ($cursor && $safety < 200) {
            if ((int)($cursor['id'] ?? 0) === $folderId) {
                return true;
            }
            $parentId = (int)($cursor['parent_id'] ?? 0);
            $cursor = $parentId > 0 ? $this->getFolderById($parentId) : null;
            $safety++;
        }
        return false;
    }
}
