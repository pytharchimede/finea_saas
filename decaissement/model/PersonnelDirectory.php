<?php

require_once dirname(__DIR__, 2) . '/model/RhMatriculeManager.php';
require_once dirname(__DIR__, 2) . '/services/PersonIdentityGuard.php';

class PersonnelDirectory
{
    private PDO $pdo;
    private ?array $availableColumns = null;
    private ?array $availableTables = null;

    private array $statusLabels = [
        1 => 'CDD',
        2 => 'Contrat de prestation',
        3 => 'Consultant',
        4 => 'Stage',
        5 => 'CDI',
        6 => 'Majestic',
    ];

    private array $statusDefaultAbbreviations = [
        1 => 'CDD',
        2 => 'PREST',
        3 => 'CONS',
        4 => 'STGE',
        5 => 'CDI',
        6 => 'MAJ',
    ];

    private array $siteLabels = [
        1 => 'Abidjan',
    ];

    private array $genderLabels = [
        1 => 'Masculin',
        2 => 'Feminin',
    ];

    private array $auditFieldLabels = [
        'matricule' => 'Matricule',
        'full_name' => 'Nom complet',
        'gender_code' => 'Sexe',
        'birth_date' => 'Date de naissance',
        'phone' => 'Telephone',
        'email' => 'E-mail',
        'service_id' => 'Service',
        'fonction_id' => 'Fonction',
        'status_code' => 'Statut',
        'personnel_category' => 'Catégorie RH',
        'site_id' => 'Site',
        'hire_date' => 'Date de recrutement',
        'start_date' => 'Date de prise de service',
        'end_date' => 'Date de sortie',
        'exit_reason_id' => 'Motif de sortie',
        'marital_status' => 'Situation matrimoniale',
        'father_name' => 'Nom du pere',
        'father_phone' => 'Telephone du pere',
        'mother_name' => 'Nom de la mere',
        'mother_phone' => 'Telephone de la mere',
        'cni_number' => 'CNI',
        'cnps_number' => 'CNPS',
        'emergency_contact_name' => 'Contact urgence',
        'emergency_contact_phone' => 'Telephone urgence',
        'contract_duration_months' => 'Duree contrat (mois)',
        'has_children' => 'Presence enfants',
        'children_count' => 'Nombre d\'enfants',
        'photo' => 'Photo de profil',
        'birth_certificate_path' => 'Extrait de naissance',
        'identity_document_path' => 'Piece d\'identite / CNI',
        'last_degree_path' => 'Dernier diplome',
        'employment_contract_path' => 'Contrat d\'embauche',
    ];

    private array $documentMap = [
        'birth_certificate_path' => 'extrait_naissance_path',
        'identity_document_path' => 'piece_identite_path',
        'last_degree_path' => 'dernier_diplome_path',
        'employment_contract_path' => 'contrat_embauche_path',
        'photo' => 'photo_personnel_soignant',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureRhSupportTables();
        PersonIdentityGuard::ensureSchema($this->pdo);
    }

    public function getDashboardData(): array
    {
        return [
            'stats' => $this->getStats(),
            'services' => $this->getDistribution('service_label'),
            'fonctions' => $this->getDistribution('fonction_label'),
            'statuts' => $this->getDistribution('statut_label'),
            'recentHires' => $this->getRecentHires(),
        ];
    }

    public function getPersonnelList(array $filters = [], int $page = 1, int $perPage = 12): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        [$whereSql, $params] = $this->buildWhereClause($filters);

        $countSql = "SELECT COUNT(*) FROM personnel p {$whereSql}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $sql = $this->getBaseSelectSql() . " {$whereSql}
            ORDER BY is_active DESC, full_name ASC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function getPersonnelRowsForExport(array $filters = [], int $limit = 5000): array
    {
        $limit = max(1, min(10000, $limit));
        [$whereSql, $params] = $this->buildWhereClause($filters);

        $sql = $this->getBaseSelectSql() . " {$whereSql}
            ORDER BY is_active DESC, service_label ASC, full_name ASC
            LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getFilters(): array
    {
        $statutPairs = [];
        foreach ($this->getRhStatuts(false) as $row) {
            $statutPairs[] = [
                'id' => (int) $row['code'],
                'label' => (string) $row['label'],
                'abbreviation' => (string) ($row['abbreviation'] ?? ''),
            ];
        }
        if (empty($statutPairs)) {
            $statutPairs = $this->buildMappedPairs(
                $this->getDistinctValues('SELECT DISTINCT statut_pers_soignant_code FROM personnel WHERE statut_pers_soignant_code IS NOT NULL ORDER BY statut_pers_soignant_code ASC'),
                $this->statusLabels,
                'Statut'
            );
        }

        $sitePairs = [];
        foreach ($this->getRhSites(false) as $row) {
            $sitePairs[] = ['id' => (int) $row['code'], 'label' => (string) $row['label']];
        }
        if (empty($sitePairs)) {
            $sitePairs = $this->buildMappedPairs(
                $this->getDistinctValues('SELECT DISTINCT site_id FROM personnel WHERE site_id IS NOT NULL ORDER BY site_id ASC'),
                $this->siteLabels,
                'Site'
            );
        }

        return [
            'services' => $this->fetchPairs('SELECT id_service AS id, lib_service AS label FROM service ORDER BY lib_service ASC'),
            'fonctions' => $this->fetchPairs('SELECT id_fonction AS id, lib_fonction AS label FROM fonction ORDER BY lib_fonction ASC'),
            'statuts' => $statutPairs,
            'sexes' => $this->buildMappedPairs($this->getDistinctValues('SELECT DISTINCT sexe_personnel_soignant FROM personnel WHERE sexe_personnel_soignant IS NOT NULL ORDER BY sexe_personnel_soignant ASC'), $this->genderLabels, 'Sexe'),
            'sites' => $sitePairs,
        ];
    }

    public function getQuickStats(): array
    {
        return $this->getStats();
    }

    public function getCategoryStats(string $category): array
    {
        $category=in_array($category,['interne','prestataire'],true)?$category:'interne';
        $q=$this->pdo->prepare("SELECT COUNT(*) total,SUM(CASE WHEN date_sortie IS NULL OR date_sortie='0000-00-00' THEN 1 ELSE 0 END) active,SUM(CASE WHEN date_sortie IS NOT NULL AND date_sortie<>'0000-00-00' THEN 1 ELSE 0 END) inactive FROM personnel WHERE COALESCE(NULLIF(personnel_category,''),'interne')=?");$q->execute([$category]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];return ['total'=>(int)($row['total']??0),'active'=>(int)($row['active']??0),'inactive'=>(int)($row['inactive']??0)];
    }

    public function getPersonnelChoices(string $search = '', int $limit = 2000): array
    {
        $limit = max(1, min(5000, $limit));
        $search = trim($search);

        $sql = 'SELECT id, full_name, email, service_label, is_active FROM (' . $this->getBaseSelectSql() . ') directory';
        $params = [];

        if ($search !== '') {
            $sql .= ' WHERE full_name LIKE :q OR email LIKE :q OR service_label LIKE :q OR matricule LIKE :q';
            $params[':q'] = '%' . $search . '%';
        }

        $sql .= ' ORDER BY is_active DESC, full_name ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        if (isset($params[':q'])) {
            $stmt->bindValue(':q', $params[':q']);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPersonnelDetail(int $personnelId): ?array
    {
        if ($personnelId <= 0) {
            return null;
        }

        $sql = $this->getBaseSelectSql() . ' WHERE p.id_personnel_soignant = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $personnelId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getExitReasons(bool $includeInactive = false): array
    {
        $sql = 'SELECT id, label, is_active, sort_order FROM rh_exit_reasons';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, label ASC, id ASC';

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createExitReason(string $label, bool $isActive = true, int $sortOrder = 0): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du motif est obligatoire.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_exit_reasons (label, is_active, sort_order) VALUES (:label, :is_active, :sort_order)');
        $stmt->execute([
            ':label' => $label,
            ':is_active' => $isActive ? 1 : 0,
            ':sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateExitReason(int $id, string $label, bool $isActive = true, int $sortOrder = 0): bool
    {
        if ($id <= 0) {
            throw new RuntimeException('Motif invalide.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du motif est obligatoire.');
        }

        $stmt = $this->pdo->prepare('UPDATE rh_exit_reasons SET label = :label, is_active = :is_active, sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([
            ':id' => $id,
            ':label' => $label,
            ':is_active' => $isActive ? 1 : 0,
            ':sort_order' => $sortOrder,
        ]);
    }

    public function deleteExitReason(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        // Empêcher suppression si utilisé dans la table personnel.
        $availableColumns = $this->getAvailablePersonnelColumns();
        if (isset($availableColumns['motif_sortie_pers_soign_id'])) {
            $check = $this->pdo->prepare('SELECT COUNT(*) FROM personnel WHERE motif_sortie_pers_soign_id = :id');
            $check->execute([':id' => $id]);
            if ((int) $check->fetchColumn() > 0) {
                throw new RuntimeException('Suppression impossible: ce motif est déjà utilisé dans des dossiers du personnel. Désactivez-le plutôt.');
            }
        }

        $stmt = $this->pdo->prepare('DELETE FROM rh_exit_reasons WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    public function createPersonnel(array $data, array $actor = []): int
    {
        $data = PersonIdentityGuard::requirePersonnelIdentity($data);
        return PersonIdentityGuard::locked($this->pdo, [$data['cni_number']], fn() => $this->createPersonnelRecord($data, $actor));
    }

    private function createPersonnelRecord(array $data, array $actor): int
    {
        $availableColumns = $this->getAvailablePersonnelColumns();
        if(in_array((int)($data['status_code']??0),[2,3],true))$data['personnel_category']='prestataire';

        $fullName = trim((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            throw new RuntimeException('Le nom complet est obligatoire pour creer un dossier RH.');
        }

        if (!isset($availableColumns['nom_personnel_soignant'])) {
            throw new RuntimeException('La structure de la table personnel est incompatible (colonne nom manquante).');
        }

        if (isset($availableColumns['matricule_personnel_soignant'])) {
            $matriculeManager = new \RhMatriculeManager($this->pdo);
            $data['matricule'] = $matriculeManager->generateNextMatricule((int) ($data['status_code'] ?? 0));
        } else {
            unset($data['matricule']);
        }

        $fieldMap = [
            'matricule' => 'matricule_personnel_soignant',
            'full_name' => 'nom_personnel_soignant',
            'gender_code' => 'sexe_personnel_soignant',
            'birth_date' => 'date_nais_personnel_soignant',
            'phone' => 'tel_personnel_soignant',
            'email' => 'email_personnel_soignant',
            'service_id' => 'service_id',
            'fonction_id' => 'fonction_id',
            'status_code' => 'statut_pers_soignant_code',
            'personnel_category' => 'personnel_category',
            'site_id' => 'site_id',
            'hire_date' => 'date_recrutement',
            'start_date' => 'date_prise_service',
            'marital_status' => 'situation_matrimoniale',
            'father_name' => 'nom_pere',
            'father_phone' => 'tel_pere',
            'mother_name' => 'nom_mere',
            'mother_phone' => 'tel_mere',
            'cni_number' => 'num_cni',
            'identity_document_path' => 'piece_identite_path',
            'cnps_number' => 'num_cnps',
            'emergency_contact_name' => 'nom_personne_urgence',
            'emergency_contact_phone' => 'tel_personne_urgence',
            'contract_duration_months' => 'duree_contrat_mois',
            'has_children' => 'has_enfants',
            'children_count' => 'nb_enfants',
        ];

        $columns = [];
        $params = [];

        foreach ($fieldMap as $key => $column) {
            if (!isset($availableColumns[$column]) || !array_key_exists($key, $data)) {
                continue;
            }

            $normalized = $this->normalizePersonnelValue($key, $data[$key]);
            if ($normalized === null) {
                continue;
            }

            $paramName = ':ins_' . $key;
            $columns[] = $column;
            $params[$paramName] = $normalized;
        }

        if (array_key_exists('children_count', $data) && isset($availableColumns['nbre_enfant'])) {
            $legacyVal = $this->normalizePersonnelValue('children_count', $data['children_count']);
            if ($legacyVal !== null) {
                $columns[] = 'nbre_enfant';
                $params[':ins_children_count_legacy'] = $legacyVal;
            }
        }

        if (empty($columns)) {
            throw new RuntimeException('Aucune donnee valide a enregistrer pour le nouveau dossier RH.');
        }

        $placeholders = array_map(
            static fn(string $paramName): string => $paramName,
            array_keys($params)
        );

        $sql = 'INSERT INTO personnel (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        try {
            $this->beginTransaction();

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $personnelId = (int) $this->pdo->lastInsertId();
            if ($personnelId <= 0) {
                $this->rollbackTransaction();
                throw new RuntimeException('Impossible de recuperer l\'identifiant du nouveau dossier RH.');
            }

            $this->insertChangeLogRow($personnelId, [
                'change_group' => $this->buildChangeGroupId('integration'),
                'field_key' => 'integration',
                'field_label' => 'Integration du personnel',
                'old_value' => null,
                'new_value' => 'Dossier cree : ' . $fullName,
            ], $actor);

            $eventDate = !empty($data['hire_date']) && $data['hire_date'] !== '0000-00-00'
                ? $data['hire_date']
                : (!empty($data['start_date']) && $data['start_date'] !== '0000-00-00' ? $data['start_date'] : null);

            if ($eventDate !== null) {
                $this->addPersonnelHistoryEvent($personnelId, [
                    'event_type' => 'recrutement',
                    'event_date' => $eventDate,
                    'title' => 'Integration initiale',
                    'description' => 'Dossier cree via le module Integration RH.',
                    'metadata' => ['source' => 'integration_form'],
                ], $actor);
            }

            PersonIdentityGuard::recordPersonnel($this->pdo, $personnelId, [$data['cni_number']]);
            $this->commitTransaction();
            return $personnelId;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    public function updatePersonnel(int $personnelId, array $data, array $actor = []): bool
    {
        $before = $this->getPersonnelDetail($personnelId);
        if (!$before) return false;
        $data = PersonIdentityGuard::requirePersonnelIdentity($data, $before);
        $numbers = [$data['cni_number']];
        try { $numbers[] = PersonIdentityGuard::normalize((string)($before['cni_number']??'')); } catch (DomainException $e) {}
        return PersonIdentityGuard::locked($this->pdo, $numbers, fn() => $this->updatePersonnelRecord($personnelId, $data, $actor));
    }

    private function updatePersonnelRecord(int $personnelId, array $data, array $actor): bool
    {
        if ($personnelId <= 0) {
            return false;
        }

        $before = $this->getPersonnelDetail($personnelId);
        if ($before === null) {
            return false;
        }

        $availableColumns = $this->getAvailablePersonnelColumns();
        if(array_key_exists('status_code',$data)&&in_array((int)$data['status_code'],[2,3],true))$data['personnel_category']='prestataire';

        unset($data['matricule']);
        if (isset($availableColumns['matricule_personnel_soignant']) && array_key_exists('status_code', $data)) {
            $matriculeManager = new \RhMatriculeManager($this->pdo);
            $oldStatusCode = isset($before['status_code']) ? (int) $before['status_code'] : null;
            $newStatusCode = $data['status_code'] !== '' ? (int) $data['status_code'] : null;
            if ($matriculeManager->shouldChangeForStatus($oldStatusCode, $newStatusCode, (string) ($before['statut_label'] ?? ''))) {
                $data['matricule'] = $matriculeManager->generateNextMatricule((int) $newStatusCode, $personnelId);
            }
        }

        $fieldMap = [
            'matricule' => 'matricule_personnel_soignant',
            'full_name' => 'nom_personnel_soignant',
            'gender_code' => 'sexe_personnel_soignant',
            'birth_date' => 'date_nais_personnel_soignant',
            'phone' => 'tel_personnel_soignant',
            'email' => 'email_personnel_soignant',
            'service_id' => 'service_id',
            'fonction_id' => 'fonction_id',
            'status_code' => 'statut_pers_soignant_code',
            'personnel_category' => 'personnel_category',
            'site_id' => 'site_id',
            'hire_date' => 'date_recrutement',
            'start_date' => 'date_prise_service',
            'end_date' => 'date_sortie',
            'exit_reason_id' => 'motif_sortie_pers_soign_id',
            'marital_status' => 'situation_matrimoniale',
            'father_name' => 'nom_pere',
            'father_phone' => 'tel_pere',
            'mother_name' => 'nom_mere',
            'mother_phone' => 'tel_mere',
            'cni_number' => 'num_cni',
            'identity_document_path' => 'piece_identite_path',
            'cnps_number' => 'num_cnps',
            'emergency_contact_name' => 'nom_personne_urgence',
            'emergency_contact_phone' => 'tel_personne_urgence',
            'contract_duration_months' => 'duree_contrat_mois',
            'has_children' => 'has_enfants',
            'children_count' => 'nb_enfants',
        ];

        $updates = [];
        $params = [':personnel_id' => $personnelId];
        foreach ($fieldMap as $key => $column) {
            if (!array_key_exists($key, $data) || !isset($availableColumns[$column])) {
                continue;
            }

            $paramName = ':' . $key;
            $updates[] = $column . ' = ' . $paramName;
            $params[$paramName] = $this->normalizePersonnelValue($key, $data[$key]);
        }

        if (array_key_exists('children_count', $data) && isset($availableColumns['nbre_enfant'])) {
            $updates[] = 'nbre_enfant = :children_count_legacy';
            $params[':children_count_legacy'] = $this->normalizePersonnelValue('children_count', $data['children_count']);
        }

        if (empty($updates)) {
            return true;
        }

        $changeGroup = $this->buildChangeGroupId('dossier');

        try {
            $this->beginTransaction();

            $sql = 'UPDATE personnel SET ' . implode(', ', $updates) . ' WHERE id_personnel_soignant = :personnel_id';
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            if (!$result) {
                $this->rollbackTransaction();
                return false;
            }

            $after = $this->getPersonnelDetail($personnelId) ?? $before;
            $this->recordFieldChanges($personnelId, $before, $after, array_keys($fieldMap), $changeGroup, $actor);

            PersonIdentityGuard::recordPersonnel($this->pdo, $personnelId, [$before['cni_number']??'', $data['cni_number']]);
            $this->commitTransaction();
            return true;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    public function updatePersonnelDocuments(int $personnelId, array $documents, array $actor = []): bool
    {
        if ($personnelId <= 0) {
            return false;
        }

        $before = $this->getPersonnelDetail($personnelId);
        if ($before === null) {
            return false;
        }

        if (array_key_exists('identity_document_path', $documents)) PersonIdentityGuard::requirePersonnelIdentity($documents, $before);
        $availableColumns = $this->getAvailablePersonnelColumns();
        $updates = [];
        $params = [':personnel_id' => $personnelId];
        $documentColumns = [];

        foreach ($this->documentMap as $key => $column) {
            if (!array_key_exists($key, $documents) || !isset($availableColumns[$column])) {
                continue;
            }

            $paramName = ':' . $key;
            $updates[] = $column . ' = ' . $paramName;
            $params[$paramName] = trim((string) $documents[$key]);
            $documentColumns[$key] = $column;
        }

        if (empty($updates)) {
            return true;
        }

        $changeGroup = $this->buildChangeGroupId('document');

        try {
            $this->beginTransaction();

            foreach ($documentColumns as $documentKey => $column) {
                $oldPath = trim((string) ($before[$documentKey] ?? ''));
                $newPath = trim((string) ($documents[$documentKey] ?? ''));
                if ($oldPath !== '' && $oldPath !== $newPath) {
                    $archivedPath = $this->archiveStoredDocumentFile($oldPath, $documentKey, $personnelId);
                    $this->insertDocumentArchiveRow($personnelId, $documentKey, 'replaced', $oldPath, $archivedPath, [
                        'new_path' => $newPath,
                    ], $actor);
                }
            }

            $sql = 'UPDATE personnel SET ' . implode(', ', $updates) . ' WHERE id_personnel_soignant = :personnel_id';
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);
            if (!$result) {
                $this->rollbackTransaction();
                return false;
            }

            $after = $this->getPersonnelDetail($personnelId) ?? $before;
            $this->recordFieldChanges($personnelId, $before, $after, array_keys($documentColumns), $changeGroup, $actor);

            $this->commitTransaction();
            return true;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    public function replacePersonnelChildren(int $personnelId, array $children, array $actor = []): void
    {
        if ($personnelId <= 0) {
            return;
        }

        $this->pdo->prepare('DELETE FROM personnel_rh_children WHERE personnel_id = :personnel_id')
            ->execute([':personnel_id' => $personnelId]);

        if ($children === []) {
            return;
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO personnel_rh_children (
                personnel_id,
                child_index,
                child_birth_date,
                birth_certificate_path,
                created_by_user_id,
                created_by_name
            ) VALUES (
                :personnel_id,
                :child_index,
                :child_birth_date,
                :birth_certificate_path,
                :created_by_user_id,
                :created_by_name
            )
        ');

        $actorName = $this->resolveActorName($actor);
        $index = 1;
        foreach ($children as $child) {
            $birthDate = $this->normalizePersonnelValue('birth_date', $child['birth_date'] ?? null);
            $certificatePath = trim((string) ($child['birth_certificate_path'] ?? ''));
            if ($birthDate === null && $certificatePath === '') {
                continue;
            }

            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $stmt->bindValue(':child_index', $index, PDO::PARAM_INT);
            $stmt->bindValue(':child_birth_date', $birthDate, $birthDate !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':birth_certificate_path', $certificatePath !== '' ? $certificatePath : null, $certificatePath !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':created_by_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->execute();
            $index++;
        }
    }

    public function getPersonnelChildren(int $personnelId): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM personnel_rh_children
            WHERE personnel_id = :personnel_id
            ORDER BY child_index ASC, id ASC
        ');
        $stmt->execute([':personnel_id' => $personnelId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function archivePersonnelDocument(int $personnelId, string $documentKey, array $actor = []): bool
    {
        return $this->clearPersonnelDocument($personnelId, $documentKey, 'archived', $actor);
    }

    public function deletePersonnelDocument(int $personnelId, string $documentKey, array $actor = []): bool
    {
        return $this->clearPersonnelDocument($personnelId, $documentKey, 'deleted', $actor);
    }

    public function restorePersonnelDocumentArchive(int $personnelId, int $archiveId, array $actor = []): bool
    {
        if ($personnelId <= 0 || $archiveId <= 0) {
            return false;
        }

        $archive = $this->getPersonnelDocumentArchiveRow($personnelId, $archiveId);
        if ($archive === null) {
            throw new RuntimeException('L\'archive demandee est introuvable.');
        }

        $documentKey = (string) ($archive['document_key'] ?? '');
        if (!isset($this->documentMap[$documentKey])) {
            throw new RuntimeException('Le type de piece archivee est invalide.');
        }

        $archivedRelativePath = trim((string) ($archive['archived_path'] ?? ''));
        $archivedAbsolutePath = $this->resolveStoredAbsolutePath($archivedRelativePath);
        if ($archivedRelativePath === '' || $archivedAbsolutePath === null || !is_file($archivedAbsolutePath)) {
            throw new RuntimeException('Le fichier archive n\'est plus disponible pour restauration.');
        }

        $employee = $this->getPersonnelDetail($personnelId);
        if ($employee === null) {
            throw new RuntimeException('Le dossier RH cible est introuvable.');
        }

        $column = $this->documentMap[$documentKey];
        if (!isset($this->getAvailablePersonnelColumns()[$column])) {
            throw new RuntimeException('La colonne de destination pour cette piece est indisponible.');
        }

        $currentPath = trim((string) ($employee[$documentKey] ?? ''));

        try {
            $this->beginTransaction();

            if ($currentPath !== '') {
                $replacedArchivePath = $this->archiveStoredDocumentFile($currentPath, $documentKey, $personnelId);
                $this->insertDocumentArchiveRow($personnelId, $documentKey, 'replaced', $currentPath, $replacedArchivePath, [
                    'restored_from_archive_id' => $archiveId,
                ], $actor);
            }

            [$restoredAbsolutePath, $restoredDbPath] = $this->buildRestoredDocumentTarget($documentKey, $personnelId, $archivedAbsolutePath);
            if (!$this->moveStoredFile($archivedAbsolutePath, $restoredAbsolutePath)) {
                throw new RuntimeException('Impossible de restaurer le fichier archive dans le dossier actif.');
            }

            $stmt = $this->pdo->prepare('UPDATE personnel SET ' . $column . ' = :document_path WHERE id_personnel_soignant = :personnel_id');
            $stmt->bindValue(':document_path', $restoredDbPath);
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            if (!$result) {
                $this->rollbackTransaction();
                return false;
            }

            $this->insertDocumentArchiveRow($personnelId, $documentKey, 'restored', $archivedRelativePath, null, [
                'source_archive_id' => $archiveId,
                'restored_to_path' => $restoredDbPath,
                'replaced_active_path' => $currentPath !== '' ? $currentPath : null,
            ], $actor);
            $this->insertChangeLogRow($personnelId, [
                'change_group' => $this->buildChangeGroupId('document-restore'),
                'field_key' => $documentKey,
                'field_label' => $this->auditFieldLabels[$documentKey] ?? $documentKey,
                'old_value' => $this->humanizePath($currentPath),
                'new_value' => $this->humanizePath($restoredDbPath),
            ], $actor);

            $this->commitTransaction();
            return true;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    public function addPersonnelHistoryEvent(int $personnelId, array $data, array $actor = []): bool
    {
        if ($personnelId <= 0) {
            return false;
        }

        $employee = $this->getPersonnelDetail($personnelId);
        if ($employee === null) {
            return false;
        }

        $eventDate = $this->normalizeHistoryDate($data['event_date'] ?? null);
        if ($eventDate === null) {
            throw new RuntimeException('La date de l\'evenement RH est obligatoire.');
        }

        $eventType = $this->normalizeHistoryEventType((string) ($data['event_type'] ?? 'note'));
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $this->buildDefaultHistoryTitle($eventType);
        }

        $description = trim((string) ($data['description'] ?? ''));
        $metadata = $data['metadata'] ?? null;

        $sql = '
            INSERT INTO personnel_rh_history (
                personnel_id,
                event_type,
                source_kind,
                event_date,
                title,
                description,
                metadata_json,
                created_by_user_id,
                created_by_name
            ) VALUES (
                :personnel_id,
                :event_type,
                :source_kind,
                :event_date,
                :title,
                :description,
                :metadata_json,
                :created_by_user_id,
                :created_by_name
            )';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':event_type', $eventType);
        $stmt->bindValue(':source_kind', 'real');
        $stmt->bindValue(':event_date', $eventDate);
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':description', $description !== '' ? $description : null, $description !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $metadataJson = $this->encodeMetadata($metadata);
        $stmt->bindValue(':metadata_json', $metadataJson, $metadataJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':created_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $actorName = $this->resolveActorName($actor);
        $stmt->bindValue(':created_by_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);

        $result = $stmt->execute();
        if ($result) {
            $this->insertChangeLogRow($personnelId, [
                'change_group' => $this->buildChangeGroupId('history'),
                'field_key' => 'history_event',
                'field_label' => 'Historique RH',
                'old_value' => null,
                'new_value' => $title . ' (' . $eventDate . ')',
            ], $actor);
        }

        return $result;
    }

    public function applyPersonnelMutation(int $personnelId, array $data, array $actor = []): bool
    {
        if ($personnelId <= 0) {
            return false;
        }

        $before = $this->getPersonnelDetail($personnelId);
        if ($before === null) {
            return false;
        }

        $updates = [];
        foreach (['service_id', 'fonction_id', 'site_id', 'status_code', 'start_date'] as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $updates[$field] = $data[$field];
        }

        if ($updates === []) {
            throw new RuntimeException('Aucune mutation à appliquer.');
        }

        $hasRealChange = false;
        foreach ($updates as $field => $value) {
            $normalizedValue = $this->normalizePersonnelValue($field, $value);
            $beforeValue = $before[$field] ?? null;
            if ((string) ($normalizedValue ?? '') !== (string) ($beforeValue ?? '')) {
                $hasRealChange = true;
                break;
            }
        }

        if (!$hasRealChange) {
            throw new RuntimeException('Les nouvelles valeurs sont identiques à la situation actuelle du collaborateur.');
        }

        $effectiveDate = $this->normalizeHistoryDate($data['event_date'] ?? ($updates['start_date'] ?? null));
        if ($effectiveDate === null) {
            throw new RuntimeException('La date de prise d\'effet est obligatoire pour enregistrer une mutation.');
        }

        $result = $this->updatePersonnel($personnelId, $updates, $actor);
        if (!$result) {
            return false;
        }

        $after = $this->getPersonnelDetail($personnelId) ?? $before;
        $descriptionParts = [];
        foreach (['service_id', 'fonction_id', 'site_id', 'status_code', 'start_date'] as $field) {
            $beforeComparable = $this->getComparableAuditValue($field, $before);
            $afterComparable = $this->getComparableAuditValue($field, $after);
            if ($beforeComparable === $afterComparable) {
                continue;
            }

            $descriptionParts[] = ($this->auditFieldLabels[$field] ?? $field)
                . ' : '
                . ($this->formatAuditFieldValue($field, $before) ?? 'Vide')
                . ' -> '
                . ($this->formatAuditFieldValue($field, $after) ?? 'Vide');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason !== '') {
            $descriptionParts[] = 'Motif : ' . $reason;
        }

        $this->addPersonnelHistoryEvent($personnelId, [
            'event_type' => 'mutation',
            'event_date' => $effectiveDate,
            'title' => trim((string) ($data['title'] ?? '')) !== '' ? trim((string) $data['title']) : 'Mutation / affectation RH',
            'description' => implode("\n", $descriptionParts),
            'metadata' => [
                'source' => 'dossier_personnel_rh',
                'reason' => $reason,
                'changes' => $descriptionParts,
            ],
        ], $actor);

        return true;
    }

    public function getPersonnelAssignments(int $personnelId): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $availableTables = $this->getAvailableTables();
        if (!isset($availableTables['suivi_travaux_affectation'], $availableTables['suivi_travaux_affectation_pilote'])) {
            return [];
        }

        $joins = [
            'LEFT JOIN service s ON s.id_service = a.service_id',
        ];
        $selects = [
            'a.id',
            'a.chantier_id',
            'a.ligne_debourse_id',
            'a.service_id',
            'a.updated_by',
            'a.created_at',
            'a.updated_at',
            'COALESCE(s.lib_service, "") AS service_label',
            'NULL AS chantier_title',
            'NULL AS chantier_code',
            'NULL AS task_designation',
            'NULL AS task_category',
        ];

        if (isset($availableTables['chantier_banamur'])) {
            $joins[] = 'LEFT JOIN chantier_banamur c ON c.id = a.chantier_id';
            $selects[8] = 'c.titre AS chantier_title';
            $selects[9] = 'c.code AS chantier_code';
        }

        if (isset($availableTables['ligne_debourse_banamur'])) {
            $joins[] = 'LEFT JOIN ligne_debourse_banamur ldb ON ldb.id = a.ligne_debourse_id';
            $selects[10] = 'ldb.designation AS task_designation';
            $selects[11] = 'ldb.categorie AS task_category';
        }

        $sql = '
            SELECT ' . implode(', ', $selects) . '
            FROM suivi_travaux_affectation_pilote ap
            INNER JOIN suivi_travaux_affectation a ON a.id = ap.affectation_id
            ' . implode("\n            ", $joins) . '
            WHERE ap.personnel_id = :personnel_id
            ORDER BY a.updated_at DESC, a.id DESC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPersonnelHistory(int $personnelId): array
    {
        $employee = $this->getPersonnelDetail($personnelId);
        if ($employee === null) {
            return [];
        }

        $history = [];

        if (!empty($employee['hire_date']) && $employee['hire_date'] !== '0000-00-00') {
            $history[] = [
                'date' => $employee['hire_date'],
                'type' => 'recrutement',
                'title' => 'Recrutement',
                'description' => 'Integration initiale dans l\'effectif RH.',
                'source_kind' => 'record',
                'source_label' => 'Donnee dossier',
            ];
        }

        if (!empty($employee['start_date']) && $employee['start_date'] !== '0000-00-00') {
            $history[] = [
                'date' => $employee['start_date'],
                'type' => 'prise_service',
                'title' => 'Prise de service',
                'description' => 'Debut officiel sur le poste ' . ($employee['fonction_label'] ?: 'attribue') . '.',
                'source_kind' => 'record',
                'source_label' => 'Donnee dossier',
            ];
        }

        foreach ($this->fetchStoredHistoryEvents($personnelId) as $event) {
            $history[] = $event;
        }

        foreach ($this->buildContractRenewalEvents($employee) as $event) {
            $history[] = $event;
        }

        foreach ($this->getPersonnelAssignments($personnelId) as $assignment) {
            $descriptionParts = [];
            if (!empty($assignment['service_label'])) {
                $descriptionParts[] = 'Service ' . $assignment['service_label'];
            }
            if (!empty($assignment['chantier_title'])) {
                $descriptionParts[] = 'Chantier ' . $assignment['chantier_title'];
            } elseif (!empty($assignment['chantier_id'])) {
                $descriptionParts[] = 'Chantier #' . $assignment['chantier_id'];
            }
            if (!empty($assignment['task_designation'])) {
                $descriptionParts[] = 'Tache ' . $assignment['task_designation'];
            } elseif (!empty($assignment['ligne_debourse_id'])) {
                $descriptionParts[] = 'Ligne #' . $assignment['ligne_debourse_id'];
            }
            if (!empty($assignment['updated_by'])) {
                $descriptionParts[] = 'Maj par ' . $assignment['updated_by'];
            }

            $history[] = [
                'date' => $assignment['updated_at'] ?? $assignment['created_at'] ?? null,
                'type' => 'affectation_operationnelle',
                'title' => 'Affectation operationnelle',
                'description' => implode(' | ', array_filter($descriptionParts)),
                'source_kind' => 'operation',
                'source_label' => 'Suivi travaux',
            ];
        }

        if (!empty($employee['end_date']) && $employee['end_date'] !== '0000-00-00') {
            $history[] = [
                'date' => $employee['end_date'],
                'type' => 'sortie',
                'title' => 'Sortie du personnel',
                'description' => !empty($employee['exit_reason_id'])
                    ? 'Motif/identifiant de sortie: ' . $employee['exit_reason_id']
                    : 'Dossier marque comme sorti.',
                'source_kind' => 'record',
                'source_label' => 'Donnee dossier',
            ];
        }

        usort($history, static function (array $left, array $right): int {
            $rightDate = (string) ($right['date'] ?? '');
            $leftDate = (string) ($left['date'] ?? '');
            if ($rightDate === $leftDate) {
                return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            }
            return strcmp($rightDate, $leftDate);
        });

        return $history;
    }

    public function getPersonnelChangeLog(int $personnelId, int $limit = 40): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $sql = '
            SELECT
                id,
                change_group,
                field_key,
                field_label,
                old_value,
                new_value,
                changed_by_user_id,
                changed_by_name,
                changed_at
            FROM personnel_rh_change_log
            WHERE personnel_id = :personnel_id
            ORDER BY changed_at DESC, id DESC
            LIMIT :limit
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getPersonnelDocumentArchives(int $personnelId, int $limit = 20): array
    {
        if ($personnelId <= 0) {
            return [];
        }

        $sql = '
            SELECT
                id,
                document_key,
                action_type,
                original_path,
                archived_path,
                metadata_json,
                acted_by_user_id,
                acted_by_name,
                acted_at
            FROM personnel_rh_document_archive
            WHERE personnel_id = :personnel_id
            ORDER BY acted_at DESC, id DESC
            LIMIT :limit
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row['metadata'] = $this->decodeMetadata($row['metadata_json'] ?? null);
        }
        unset($row);

        return $rows;
    }

    // ─── Services ────────────────────────────────────────────────────────────────

    public function getServices(): array
    {
        return $this->fetchPairs('SELECT id_service AS id, lib_service AS label FROM service ORDER BY lib_service ASC');
    }

    public function createService(string $label): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du service est obligatoire.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO service (lib_service, date_creat) VALUES (:label, NOW())');
        $stmt->execute([':label' => $label]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateService(int $id, string $label): bool
    {
        if ($id <= 0) {
            throw new RuntimeException('Service invalide.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du service est obligatoire.');
        }

        $stmt = $this->pdo->prepare('UPDATE service SET lib_service = :label, date_mod = NOW() WHERE id_service = :id');
        return $stmt->execute([':label' => $label, ':id' => $id]);
    }

    public function deleteService(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM personnel WHERE service_id = :id');
        $check->execute([':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Suppression impossible : ce service est affecté à des collaborateurs. Réaffectez-les d\'abord.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM service WHERE id_service = :id');
        return $stmt->execute([':id' => $id]);
    }

    // ─── Fonctions ───────────────────────────────────────────────────────────────

    public function getFonctionsList(): array
    {
        return $this->fetchPairs('SELECT id_fonction AS id, lib_fonction AS label FROM fonction ORDER BY lib_fonction ASC');
    }

    public function createFonction(string $label): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé de la fonction est obligatoire.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO fonction (lib_fonction, date_creat) VALUES (:label, NOW())');
        $stmt->execute([':label' => $label]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateFonction(int $id, string $label): bool
    {
        if ($id <= 0) {
            throw new RuntimeException('Fonction invalide.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé de la fonction est obligatoire.');
        }

        $stmt = $this->pdo->prepare('UPDATE fonction SET lib_fonction = :label WHERE id_fonction = :id');
        return $stmt->execute([':label' => $label, ':id' => $id]);
    }

    public function deleteFonction(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM personnel WHERE fonction_id = :id');
        $check->execute([':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Suppression impossible : cette fonction est affectée à des collaborateurs. Réaffectez-les d\'abord.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM fonction WHERE id_fonction = :id');
        return $stmt->execute([':id' => $id]);
    }

    // ─── Statuts (rh_statuts) ────────────────────────────────────────────────────

    public function getRhStatuts(bool $includeInactive = false): array
    {
        $this->ensureRhStatutAbbreviationColumn();
        $sql = 'SELECT id, code, label, matricule_abbreviation AS abbreviation, is_active, sort_order FROM rh_statuts';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, code ASC, label ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createRhStatut(int $code, string $label, bool $isActive = true, int $sortOrder = 0, string $abbreviation = ''): int
    {
        $this->ensureRhStatutAbbreviationColumn();
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du statut est obligatoire.');
        }
        $abbreviation = $this->normalizeRhStatutAbbreviation($abbreviation, $label, $code);

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM rh_statuts WHERE code = :code');
        $check->execute([':code' => $code]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Un statut avec le code ' . $code . ' existe déjà.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_statuts (code, label, matricule_abbreviation, is_active, sort_order) VALUES (:code, :label, :abbreviation, :is_active, :sort_order)');
        $stmt->execute([':code' => $code, ':label' => $label, ':abbreviation' => $abbreviation, ':is_active' => $isActive ? 1 : 0, ':sort_order' => $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateRhStatut(int $id, int $code, string $label, bool $isActive = true, int $sortOrder = 0, string $abbreviation = ''): bool
    {
        $this->ensureRhStatutAbbreviationColumn();
        if ($id <= 0) {
            throw new RuntimeException('Statut invalide.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du statut est obligatoire.');
        }
        $abbreviation = $this->normalizeRhStatutAbbreviation($abbreviation, $label, $code);

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM rh_statuts WHERE code = :code AND id != :id');
        $check->execute([':code' => $code, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Un autre statut avec le code ' . $code . ' existe déjà.');
        }

        $stmt = $this->pdo->prepare('UPDATE rh_statuts SET code = :code, label = :label, matricule_abbreviation = :abbreviation, is_active = :is_active, sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':id' => $id, ':code' => $code, ':label' => $label, ':abbreviation' => $abbreviation, ':is_active' => $isActive ? 1 : 0, ':sort_order' => $sortOrder]);
    }

    public function deleteRhStatut(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $row = $this->pdo->prepare('SELECT code FROM rh_statuts WHERE id = :id');
        $row->execute([':id' => $id]);
        $statut = $row->fetch(PDO::FETCH_ASSOC);
        if (!$statut) {
            return false;
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM personnel WHERE statut_pers_soignant_code = :code');
        $check->execute([':code' => $statut['code']]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Suppression impossible : ce statut est utilisé dans des dossiers. Désactivez-le plutôt.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM rh_statuts WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    // ─── Sites (rh_sites) ────────────────────────────────────────────────────────

    public function getRhSites(bool $includeInactive = false): array
    {
        $sql = 'SELECT id, code, label, is_active, sort_order FROM rh_sites';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, code ASC, label ASC';
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createRhSite(int $code, string $label, bool $isActive = true, int $sortOrder = 0): int
    {
        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du site est obligatoire.');
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM rh_sites WHERE code = :code');
        $check->execute([':code' => $code]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Un site avec le code ' . $code . ' existe déjà.');
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_sites (code, label, is_active, sort_order) VALUES (:code, :label, :is_active, :sort_order)');
        $stmt->execute([':code' => $code, ':label' => $label, ':is_active' => $isActive ? 1 : 0, ':sort_order' => $sortOrder]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateRhSite(int $id, int $code, string $label, bool $isActive = true, int $sortOrder = 0): bool
    {
        if ($id <= 0) {
            throw new RuntimeException('Site invalide.');
        }

        $label = trim($label);
        if ($label === '') {
            throw new RuntimeException('Le libellé du site est obligatoire.');
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM rh_sites WHERE code = :code AND id != :id');
        $check->execute([':code' => $code, ':id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Un autre site avec le code ' . $code . ' existe déjà.');
        }

        $stmt = $this->pdo->prepare('UPDATE rh_sites SET code = :code, label = :label, is_active = :is_active, sort_order = :sort_order, updated_at = NOW() WHERE id = :id');
        return $stmt->execute([':id' => $id, ':code' => $code, ':label' => $label, ':is_active' => $isActive ? 1 : 0, ':sort_order' => $sortOrder]);
    }

    public function deleteRhSite(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $row = $this->pdo->prepare('SELECT code FROM rh_sites WHERE id = :id');
        $row->execute([':id' => $id]);
        $site = $row->fetch(PDO::FETCH_ASSOC);
        if (!$site) {
            return false;
        }

        $check = $this->pdo->prepare('SELECT COUNT(*) FROM personnel WHERE site_id = :code');
        $check->execute([':code' => $site['code']]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Suppression impossible : ce site est affecté à des collaborateurs. Désactivez-le plutôt.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM rh_sites WHERE id = :id');
        return $stmt->execute([':id' => $id]);
    }

    private function getStats(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN p.date_sortie IS NULL OR p.date_sortie = '0000-00-00' THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN p.date_sortie IS NOT NULL AND p.date_sortie <> '0000-00-00' THEN 1 ELSE 0 END) AS inactive_count,
                SUM(CASE WHEN p.date_recrutement >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN 1 ELSE 0 END) AS current_year_hires,
                COUNT(DISTINCT p.service_id) AS services_count
            FROM personnel p
            WHERE COALESCE(NULLIF(p.personnel_category,''),'interne')='interne'
        ";

        $stmt = $this->pdo->query($sql);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active_count'] ?? 0),
            'inactive' => (int) ($row['inactive_count'] ?? 0),
            'currentYearHires' => (int) ($row['current_year_hires'] ?? 0),
            'services' => (int) ($row['services_count'] ?? 0),
        ];
    }

    private function getDistribution(string $column): array
    {
        $allowed = ['service_label', 'fonction_label', 'statut_label'];
        if (!in_array($column, $allowed, true)) {
            return [];
        }

        $sql = "
            SELECT {$column} AS label, COUNT(*) AS total
            FROM (" . $this->getBaseSelectSql() . ") directory
            WHERE personnel_category='interne'
            GROUP BY {$column}
            ORDER BY total DESC, label ASC
            LIMIT 8
        ";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getRecentHires(): array
    {
        $sql = $this->getBaseSelectSql() . " WHERE COALESCE(NULLIF(p.personnel_category,''),'interne')='interne'
            ORDER BY hire_date IS NULL ASC, hire_date DESC, full_name ASC
            LIMIT 6";

        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getBaseSelectSql(): string
    {
        $availableColumns = $this->getAvailablePersonnelColumns();

        $hasExitReasonColumn = isset($availableColumns['motif_sortie_pers_soign_id']);
        $exitReasonSelect = $hasExitReasonColumn ? 'er.label AS exit_reason_label' : 'NULL AS exit_reason_label';
        $exitReasonJoin = $hasExitReasonColumn ? 'LEFT JOIN rh_exit_reasons er ON er.id = p.motif_sortie_pers_soign_id' : '';

        $optionalColumns = [
            'date_nais_personnel_soignant' => 'birth_date',
            'tel_personnel_soignant' => 'phone',
            'id_fonction' => 'legacy_fonction_id',
            'date_creat_pers_soign' => 'created_at',
            'secur_ajout' => 'created_by',
            'valide' => 'is_validated',
            'motif_sortie_pers_soign_id' => 'exit_reason_id',
            'date_creat_sortie' => 'exit_created_at',
            'situation_matrimoniale' => 'marital_status',
            'nom_pere' => 'father_name',
            'tel_pere' => 'father_phone',
            'num_cni' => 'cni_number',
            'nbre_enfant' => 'children_count_legacy',
            'num_cnps' => 'cnps_number',
            'nom_mere' => 'mother_name',
            'tel_mere' => 'mother_phone',
            'nom_personne_urgence' => 'emergency_contact_name',
            'tel_personne_urgence' => 'emergency_contact_phone',
            'extrait_naissance_path' => 'birth_certificate_path',
            'piece_identite_path' => 'identity_document_path',
            'dernier_diplome_path' => 'last_degree_path',
            'contrat_embauche_path' => 'employment_contract_path',
            'duree_contrat_mois' => 'contract_duration_months',
            'has_enfants' => 'has_children',
            'nb_enfants' => 'children_count',
            'actif' => 'legacy_active_flag',
        ];

        $optionalSelects = [];
        foreach ($optionalColumns as $column => $alias) {
            if (isset($availableColumns[$column])) {
                $optionalSelects[] = 'p.' . $column . ' AS ' . $alias;
            } else {
                $optionalSelects[] = 'NULL AS ' . $alias;
            }
        }

        return "
            SELECT
                p.id_personnel_soignant AS id,
                p.matricule_personnel_soignant AS matricule,
                p.nom_personnel_soignant AS full_name,
                p.email_personnel_soignant AS email,
                p.photo_personnel_soignant AS photo,
                p.sexe_personnel_soignant AS gender_code,
                CASE p.sexe_personnel_soignant
                    WHEN 1 THEN 'Masculin'
                    WHEN 2 THEN 'Feminin'
                    ELSE 'Non renseigne'
                END AS gender_label,
                p.service_id AS service_id,
                COALESCE(NULLIF(TRIM(s.lib_service), ''), 'Service non renseigne') AS service_label,
                p.fonction_id AS fonction_id,
                COALESCE(NULLIF(TRIM(f.lib_fonction), ''), 'Fonction non renseignee') AS fonction_label,
                p.statut_pers_soignant_code AS status_code,
                COALESCE(NULLIF(p.personnel_category, ''), 'interne') AS personnel_category,
                COALESCE(rst.label, CASE
                    WHEN p.statut_pers_soignant_code IS NULL THEN 'Statut non renseigne'
                    WHEN p.statut_pers_soignant_code = 1 THEN 'CDD'
                    WHEN p.statut_pers_soignant_code = 2 THEN 'Contrat de prestation'
                    WHEN p.statut_pers_soignant_code = 3 THEN 'Consultant'
                    WHEN p.statut_pers_soignant_code = 4 THEN 'Stage'
                    WHEN p.statut_pers_soignant_code = 5 THEN 'CDI'
                    ELSE CONCAT('Statut ', p.statut_pers_soignant_code)
                END) AS statut_label,
                p.site_id AS site_id,
                COALESCE(rsi.label, CASE
                    WHEN p.site_id IS NULL THEN 'Site non renseigne'
                    WHEN p.site_id = 1 THEN 'Abidjan'
                    ELSE CONCAT('Site ', p.site_id)
                END) AS site_label,
                p.date_recrutement AS hire_date,
                p.date_prise_service AS start_date,
                p.date_sortie AS end_date,
                {$exitReasonSelect},
                " . implode(",\n                ", $optionalSelects) . ",
                CASE
                    WHEN p.date_sortie IS NULL OR p.date_sortie = '0000-00-00' THEN 1
                    ELSE 0
                END AS is_active
            FROM personnel p
            LEFT JOIN service s ON s.id_service = p.service_id
            LEFT JOIN fonction f ON f.id_fonction = p.fonction_id
            LEFT JOIN rh_statuts rst ON rst.code = p.statut_pers_soignant_code
            LEFT JOIN rh_sites rsi ON rsi.code = p.site_id
            {$exitReasonJoin}
        ";
    }

    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        $inverse = (string) ($filters['search_mode'] ?? 'include') === 'exclude';
        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $searchCondition = '(p.nom_personnel_soignant LIKE :query OR p.matricule_personnel_soignant LIKE :query OR p.email_personnel_soignant LIKE :query)';
            $conditions[] = $inverse ? ('NOT ' . $searchCondition) : $searchCondition;
            $params[':query'] = '%' . $query . '%';
        }

        if (isset($filters['personnel_id']) && is_numeric($filters['personnel_id']) && (int) $filters['personnel_id'] > 0) {
            $conditions[] = $inverse ? 'p.id_personnel_soignant <> :personnel_id' : 'p.id_personnel_soignant = :personnel_id';
            $params[':personnel_id'] = (int) $filters['personnel_id'];
        }

        foreach (['service_id', 'fonction_id', 'status_code', 'gender_code', 'site_id'] as $filterKey) {
            if (isset($filters[$filterKey]) && $filters[$filterKey] !== '' && is_numeric($filters[$filterKey])) {
                $columnMap = [
                    'service_id' => 'p.service_id',
                    'fonction_id' => 'p.fonction_id',
                    'status_code' => 'p.statut_pers_soignant_code',
                    'gender_code' => 'p.sexe_personnel_soignant',
                    'site_id' => 'p.site_id',
                ];
                $column = $columnMap[$filterKey] ?? 'p.site_id';
                $paramName = ':' . $filterKey;
                $conditions[] = $column . ($inverse ? ' <> ' : ' = ') . $paramName;
                $params[$paramName] = (int) $filters[$filterKey];
            }
        }

        $category=(string)($filters['personnel_category']??'');
        if(in_array($category,['interne','prestataire'],true)){
            $conditions[]='COALESCE(NULLIF(p.personnel_category,\'\'),\'interne\') = :personnel_category';
            $params[':personnel_category']=$category;
        }

        $scope = $filters['scope'] ?? 'active';
        if ($scope === 'active') {
            $conditions[] = "(p.date_sortie IS NULL OR p.date_sortie = '0000-00-00')";
        } elseif ($scope === 'inactive') {
            $conditions[] = "(p.date_sortie IS NOT NULL AND p.date_sortie <> '0000-00-00')";
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        return [$whereSql, $params];
    }

    private function fetchPairs(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getDistinctValues(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function buildMappedPairs(array $values, array $labels, string $fallbackPrefix): array
    {
        $pairs = [];
        foreach ($values as $value) {
            $pairs[] = [
                'id' => (int) $value,
                'label' => $labels[(int) $value] ?? ($fallbackPrefix . ' ' . $value),
            ];
        }

        return $pairs;
    }

    private function fetchStoredHistoryEvents(int $personnelId): array
    {
        $sql = '
            SELECT
                id,
                event_type,
                source_kind,
                event_date,
                title,
                description,
                metadata_json,
                created_by_name,
                created_at
            FROM personnel_rh_history
            WHERE personnel_id = :personnel_id
            ORDER BY event_date DESC, created_at DESC, id DESC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $events = [];
        foreach ($rows as $row) {
            $events[] = [
                'id' => (int) ($row['id'] ?? 0),
                'date' => $row['event_date'] ?? null,
                'type' => $row['event_type'] ?? 'note',
                'title' => $row['title'] ?? 'Evenement RH',
                'description' => $row['description'] ?? '',
                'source_kind' => $row['source_kind'] ?? 'real',
                'source_label' => ($row['source_kind'] ?? 'real') === 'real' ? 'Historique reel' : 'Historique RH',
                'author_name' => $row['created_by_name'] ?? null,
                'created_at' => $row['created_at'] ?? null,
                'metadata' => $this->decodeMetadata($row['metadata_json'] ?? null),
            ];
        }

        return $events;
    }

    private function buildContractRenewalEvents(array $employee): array
    {
        $duration = isset($employee['contract_duration_months']) ? (int) $employee['contract_duration_months'] : 0;
        if ($duration <= 0) {
            return [];
        }

        $baseDate = null;
        if (!empty($employee['start_date']) && $employee['start_date'] !== '0000-00-00') {
            $baseDate = $employee['start_date'];
        } elseif (!empty($employee['hire_date']) && $employee['hire_date'] !== '0000-00-00') {
            $baseDate = $employee['hire_date'];
        }

        if ($baseDate === null) {
            return [];
        }

        try {
            $current = new DateTimeImmutable($baseDate);
        } catch (Throwable $e) {
            return [];
        }

        $limitDate = null;
        if (!empty($employee['end_date']) && $employee['end_date'] !== '0000-00-00') {
            try {
                $limitDate = new DateTimeImmutable($employee['end_date']);
            } catch (Throwable $e) {
                $limitDate = null;
            }
        }
        if ($limitDate === null) {
            $limitDate = new DateTimeImmutable('today');
        }

        $events = [];
        $intervalSpec = 'P' . $duration . 'M';
        for ($step = 1; $step <= 6; $step++) {
            $renewalDate = $current->add(new DateInterval($intervalSpec));
            if ($renewalDate > $limitDate) {
                break;
            }

            $events[] = [
                'date' => $renewalDate->format('Y-m-d'),
                'type' => 'echeance_calculee',
                'title' => 'Echeance contractuelle calculee',
                'description' => 'Cycle contractuel de ' . $duration . ' mois, echeance #' . $step . '.',
                'source_kind' => 'computed',
                'source_label' => 'Echeance calculee',
            ];
            $current = $renewalDate;
        }

        return $events;
    }

    private function clearPersonnelDocument(int $personnelId, string $documentKey, string $actionType, array $actor = []): bool
    {
        if ($documentKey==='identity_document_path') throw new DomainException('La pièce d’identité est obligatoire. Remplacez-la depuis la modification du dossier RH.');
        if ($personnelId <= 0 || !isset($this->documentMap[$documentKey])) {
            return false;
        }

        $employee = $this->getPersonnelDetail($personnelId);
        if ($employee === null) {
            return false;
        }

        $currentPath = trim((string) ($employee[$documentKey] ?? ''));
        if ($currentPath === '') {
            return true;
        }

        $column = $this->documentMap[$documentKey];
        if (!isset($this->getAvailablePersonnelColumns()[$column])) {
            return false;
        }

        $archivedPath = null;
        $metadata = [
            'status' => 'ok',
        ];

        try {
            $this->beginTransaction();

            if ($actionType === 'archived') {
                $archivedPath = $this->archiveStoredDocumentFile($currentPath, $documentKey, $personnelId);
                if ($archivedPath === null) {
                    $metadata['status'] = 'file_missing';
                }
            } else {
                $deleted = $this->deleteStoredDocumentFile($currentPath);
                if (!$deleted) {
                    $metadata['status'] = 'file_missing';
                }
            }

            $stmt = $this->pdo->prepare('UPDATE personnel SET ' . $column . ' = NULL WHERE id_personnel_soignant = :personnel_id');
            $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
            $result = $stmt->execute();
            if (!$result) {
                $this->rollbackTransaction();
                return false;
            }

            $this->insertDocumentArchiveRow($personnelId, $documentKey, $actionType, $currentPath, $archivedPath, $metadata, $actor);
            $this->insertChangeLogRow($personnelId, [
                'change_group' => $this->buildChangeGroupId('document-action'),
                'field_key' => $documentKey,
                'field_label' => $this->auditFieldLabels[$documentKey] ?? $documentKey,
                'old_value' => $this->humanizePath($currentPath),
                'new_value' => $actionType === 'archived' ? 'Piece archivee' : 'Piece supprimee',
            ], $actor);

            $this->commitTransaction();
            return true;
        } catch (Throwable $e) {
            $this->rollbackTransaction();
            throw $e;
        }
    }

    private function recordFieldChanges(int $personnelId, array $before, array $after, array $fieldKeys, string $changeGroup, array $actor = []): void
    {
        foreach ($fieldKeys as $fieldKey) {
            $beforeComparable = $this->getComparableAuditValue($fieldKey, $before);
            $afterComparable = $this->getComparableAuditValue($fieldKey, $after);
            if ($beforeComparable === $afterComparable) {
                continue;
            }

            $this->insertChangeLogRow($personnelId, [
                'change_group' => $changeGroup,
                'field_key' => $fieldKey,
                'field_label' => $this->auditFieldLabels[$fieldKey] ?? $fieldKey,
                'old_value' => $this->formatAuditFieldValue($fieldKey, $before),
                'new_value' => $this->formatAuditFieldValue($fieldKey, $after),
            ], $actor);
        }
    }

    private function insertChangeLogRow(int $personnelId, array $data, array $actor = []): void
    {
        $sql = '
            INSERT INTO personnel_rh_change_log (
                personnel_id,
                change_group,
                field_key,
                field_label,
                old_value,
                new_value,
                changed_by_user_id,
                changed_by_name
            ) VALUES (
                :personnel_id,
                :change_group,
                :field_key,
                :field_label,
                :old_value,
                :new_value,
                :changed_by_user_id,
                :changed_by_name
            )
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':change_group', (string) ($data['change_group'] ?? $this->buildChangeGroupId('change')));
        $stmt->bindValue(':field_key', (string) ($data['field_key'] ?? 'unknown'));
        $stmt->bindValue(':field_label', (string) ($data['field_label'] ?? 'Champ'));

        $oldValue = $data['old_value'] ?? null;
        $newValue = $data['new_value'] ?? null;
        $stmt->bindValue(':old_value', $oldValue, $oldValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':new_value', $newValue, $newValue !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':changed_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $actorName = $this->resolveActorName($actor);
        $stmt->bindValue(':changed_by_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }

    private function insertDocumentArchiveRow(int $personnelId, string $documentKey, string $actionType, string $originalPath, ?string $archivedPath, array $metadata, array $actor = []): void
    {
        $sql = '
            INSERT INTO personnel_rh_document_archive (
                personnel_id,
                document_key,
                action_type,
                original_path,
                archived_path,
                metadata_json,
                acted_by_user_id,
                acted_by_name
            ) VALUES (
                :personnel_id,
                :document_key,
                :action_type,
                :original_path,
                :archived_path,
                :metadata_json,
                :acted_by_user_id,
                :acted_by_name
            )
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':document_key', $documentKey);
        $stmt->bindValue(':action_type', $actionType);
        $stmt->bindValue(':original_path', $originalPath);
        $stmt->bindValue(':archived_path', $archivedPath, $archivedPath !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $metadataJson = $this->encodeMetadata($metadata);
        $stmt->bindValue(':metadata_json', $metadataJson, $metadataJson !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->bindValue(':acted_by_user_id', $actor['user_id'] ?? null, isset($actor['user_id']) ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $actorName = $this->resolveActorName($actor);
        $stmt->bindValue(':acted_by_name', $actorName, $actorName !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmt->execute();
    }

    private function formatAuditFieldValue(string $fieldKey, array $employee): ?string
    {
        switch ($fieldKey) {
            case 'service_id':
                return $this->stringOrNull($employee['service_label'] ?? null);

            case 'fonction_id':
                return $this->stringOrNull($employee['fonction_label'] ?? null);

            case 'status_code':
                return $this->stringOrNull($employee['statut_label'] ?? null);

            case 'site_id':
                return $this->stringOrNull($employee['site_label'] ?? null);

            case 'gender_code':
                return $this->stringOrNull($employee['gender_label'] ?? null);

            case 'birth_date':
            case 'hire_date':
            case 'start_date':
            case 'end_date':
                return $this->formatAuditDate($employee[$fieldKey] ?? null);

            case 'has_children':
                if (!isset($employee['has_children']) || $employee['has_children'] === null || $employee['has_children'] === '') {
                    return null;
                }
                return (int) $employee['has_children'] === 1 ? 'Oui' : 'Non';

            case 'children_count':
                if (isset($employee['children_count']) && $employee['children_count'] !== null && $employee['children_count'] !== '') {
                    return (string) $employee['children_count'];
                }
                if (isset($employee['children_count_legacy']) && $employee['children_count_legacy'] !== null && $employee['children_count_legacy'] !== '') {
                    return (string) $employee['children_count_legacy'];
                }
                return null;

            case 'photo':
            case 'birth_certificate_path':
            case 'identity_document_path':
            case 'last_degree_path':
            case 'employment_contract_path':
                return $this->humanizePath($employee[$fieldKey] ?? null);

            default:
                return $this->stringOrNull($employee[$fieldKey] ?? null);
        }
    }

    private function getComparableAuditValue(string $fieldKey, array $employee): string
    {
        switch ($fieldKey) {
            case 'children_count':
                return trim((string) ($employee['children_count'] ?? $employee['children_count_legacy'] ?? ''));

            case 'has_children':
                return isset($employee['has_children']) && $employee['has_children'] !== '' && $employee['has_children'] !== null
                    ? (string) ((int) $employee['has_children'])
                    : '';

            default:
                $value = $employee[$fieldKey] ?? null;
                return $value === null ? '' : trim((string) $value);
        }
    }

    private function normalizePersonnelValue(string $field, $value)
    {
        $trimmed = is_string($value) ? trim($value) : $value;
        $nullableIntegerFields = ['gender_code', 'service_id', 'fonction_id', 'status_code', 'site_id', 'contract_duration_months', 'has_children', 'children_count', 'exit_reason_id'];
        $dateFields = ['birth_date', 'hire_date', 'start_date', 'end_date'];

        if (in_array($field, $nullableIntegerFields, true)) {
            return ($trimmed === '' || $trimmed === null) ? null : (int) $trimmed;
        }

        if (in_array($field, $dateFields, true)) {
            return ($trimmed === '' || $trimmed === null || $trimmed === '0000-00-00') ? null : $trimmed;
        }

        if ($trimmed === '') {
            return null;
        }

        return $trimmed;
    }

    private function normalizeHistoryEventType(string $type): string
    {
        $normalized = strtolower(trim($type));
        $allowed = [
            'recrutement',
            'prise_service',
            'renouvellement',
            'affectation',
            'mutation',
            'sortie',
            'promotion',
            'sanction',
            'note',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : 'note';
    }

    private function buildDefaultHistoryTitle(string $type): string
    {
        $map = [
            'recrutement' => 'Recrutement',
            'prise_service' => 'Prise de service',
            'renouvellement' => 'Renouvellement reel',
            'affectation' => 'Affectation RH',
            'mutation' => 'Mutation RH',
            'sortie' => 'Sortie RH',
            'promotion' => 'Promotion',
            'sanction' => 'Mesure disciplinaire',
            'note' => 'Note RH',
        ];

        return $map[$type] ?? 'Evenement RH';
    }

    private function normalizeHistoryDate($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;
        if ($value === '' || $value === null || $value === '0000-00-00') {
            return null;
        }

        return (string) $value;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function formatAuditDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '' || $value === '0000-00-00') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y', $timestamp) : $value;
    }

    private function humanizePath(?string $path): ?string
    {
        $path = $this->stringOrNull($path);
        if ($path === null) {
            return null;
        }

        return basename(str_replace('\\', '/', $path));
    }

    private function encodeMetadata($metadata): ?string
    {
        if ($metadata === null || $metadata === [] || $metadata === '') {
            return null;
        }

        $json = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }

    private function decodeMetadata($metadata): array
    {
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolveActorName(array $actor): ?string
    {
        if (!empty($actor['user_name'])) {
            return trim((string) $actor['user_name']);
        }

        return null;
    }

    private function buildChangeGroupId(string $prefix): string
    {
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable $e) {
            $suffix = substr(md5(uniqid('', true)), 0, 8);
        }

        return $prefix . '_' . date('YmdHis') . '_' . $suffix;
    }

    private function resolveStoredAbsolutePath(?string $path): ?string
    {
        $path = $this->stringOrNull($path);
        if ($path === null || preg_match('/^https?:\/\//i', $path)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);
        if (preg_match('/^[A-Za-z]:\//', $normalized) && file_exists($normalized)) {
            return $normalized;
        }

        $projectRoot = dirname(__DIR__, 2);
        $relativePath = ltrim($normalized, '/');
        $candidates = [
            $projectRoot . '/' . $relativePath,
            $projectRoot . '/photo/' . $relativePath,
            $projectRoot . '/uploads/' . $relativePath,
            $projectRoot . '/uploads/photo/' . $relativePath,
            $projectRoot . '/uploads/rh/photo/' . $relativePath,
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function archiveStoredDocumentFile(string $path, string $documentKey, int $personnelId): ?string
    {
        $absolutePath = $this->resolveStoredAbsolutePath($path);
        if ($absolutePath === null || !is_file($absolutePath)) {
            return null;
        }

        $projectRoot = dirname(__DIR__, 2);
        $archiveDirectory = 'uploads/rh/archive/' . preg_replace('/[^a-z0-9_-]/i', '-', strtolower($documentKey));
        $targetDirectory = $projectRoot . '/' . $archiveDirectory;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Impossible de preparer l\'archive des pieces RH.');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $baseName = pathinfo($absolutePath, PATHINFO_FILENAME);
        $safeBaseName = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($baseName));
        $targetFileName = sprintf(
            'personnel_%d_%s_%s_%s%s',
            $personnelId,
            preg_replace('/[^a-z0-9_-]/i', '-', strtolower($documentKey)),
            $safeBaseName !== '' ? $safeBaseName : 'document',
            date('YmdHis'),
            $extension !== '' ? '.' . $extension : ''
        );

        $targetAbsolutePath = $targetDirectory . '/' . $targetFileName;
        if (!@rename($absolutePath, $targetAbsolutePath)) {
            throw new RuntimeException('Impossible d\'archiver la piece RH existante.');
        }

        return $archiveDirectory . '/' . $targetFileName;
    }

    private function getPersonnelDocumentArchiveRow(int $personnelId, int $archiveId): ?array
    {
        $sql = '
            SELECT id, document_key, action_type, original_path, archived_path, metadata_json, acted_at
            FROM personnel_rh_document_archive
            WHERE personnel_id = :personnel_id AND id = :archive_id
            LIMIT 1
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':personnel_id', $personnelId, PDO::PARAM_INT);
        $stmt->bindValue(':archive_id', $archiveId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function buildRestoredDocumentTarget(string $documentKey, int $personnelId, string $sourceAbsolutePath): array
    {
        $normalizedDocumentKey = preg_replace('/[^a-z0-9_-]/i', '-', strtolower($documentKey));
        $projectRoot = dirname(__DIR__, 2);
        $targetDirectory = $projectRoot . '/uploads/rh/' . $normalizedDocumentKey;
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0777, true) && !is_dir($targetDirectory)) {
            throw new RuntimeException('Impossible de preparer le dossier de restauration RH.');
        }

        $extension = strtolower(pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
        if ($normalizedDocumentKey === 'photo') {
            $fileName = sprintf('p%d_r%s%s', $personnelId, date('ymdHis'), $extension !== '' ? '.' . $extension : '');
            return [$targetDirectory . '/' . $fileName, $fileName];
        }

        $fileName = sprintf(
            'personnel_%d_%s_restore_%s%s',
            $personnelId,
            $normalizedDocumentKey,
            date('YmdHis'),
            $extension !== '' ? '.' . $extension : ''
        );

        return [$targetDirectory . '/' . $fileName, 'uploads/rh/' . $normalizedDocumentKey . '/' . $fileName];
    }

    private function moveStoredFile(string $sourceAbsolutePath, string $targetAbsolutePath): bool
    {
        if (!is_file($sourceAbsolutePath)) {
            return false;
        }

        if (@rename($sourceAbsolutePath, $targetAbsolutePath)) {
            return true;
        }

        if (@copy($sourceAbsolutePath, $targetAbsolutePath)) {
            @unlink($sourceAbsolutePath);
            return true;
        }

        return false;
    }

    private function deleteStoredDocumentFile(string $path): bool
    {
        $absolutePath = $this->resolveStoredAbsolutePath($path);
        if ($absolutePath === null || !file_exists($absolutePath)) {
            return false;
        }

        return @unlink($absolutePath);
    }

    private function seedDefaultStatuts(): void
    {
        $this->ensureRhStatutAbbreviationColumn();
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM rh_statuts')->fetchColumn();
        $defaults = [
            [1, 'CDD', 'CDD', 1, 1],
            [2, 'Contrat de prestation', 'PREST', 1, 2],
            [3, 'Consultant', 'CONS', 1, 3],
            [4, 'Stage', 'STGE', 1, 4],
            [5, 'CDI', 'CDI', 1, 5],
            [6, 'Majestic', 'MAJ', 1, 6],
        ];

        $stmt = $this->pdo->prepare('INSERT IGNORE INTO rh_statuts (code, label, matricule_abbreviation, is_active, sort_order) VALUES (:code, :label, :abbreviation, :is_active, :sort_order)');
        foreach ($defaults as [$code, $label, $abbreviation, $isActive, $sortOrder]) {
            $stmt->execute([':code' => $code, ':label' => $label, ':abbreviation' => $abbreviation, ':is_active' => $isActive, ':sort_order' => $sortOrder]);
        }

        if ($count > 0) {
            $this->backfillRhStatutAbbreviations();
        }
    }

    private function ensureRhStatutAbbreviationColumn(): void
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM rh_statuts LIKE 'matricule_abbreviation'");
            if ($stmt && $stmt->fetch(PDO::FETCH_ASSOC)) {
                return;
            }

            $this->pdo->exec('ALTER TABLE rh_statuts ADD COLUMN matricule_abbreviation VARCHAR(12) NULL AFTER label');
        } catch (Throwable) {
            // La table peut etre en creation plus bas dans ensureRhSupportTables.
        }
    }

    private function backfillRhStatutAbbreviations(): void
    {
        $stmt = $this->pdo->query('SELECT id, code, label, matricule_abbreviation FROM rh_statuts');
        $update = $this->pdo->prepare('UPDATE rh_statuts SET matricule_abbreviation = :abbreviation WHERE id = :id');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $current = trim((string) ($row['matricule_abbreviation'] ?? ''));
            if ($current !== '') {
                continue;
            }

            $update->execute([
                ':id' => (int) $row['id'],
                ':abbreviation' => $this->normalizeRhStatutAbbreviation('', (string) ($row['label'] ?? ''), (int) ($row['code'] ?? 0)),
            ]);
        }
    }

    private function normalizeRhStatutAbbreviation(string $abbreviation, string $label, int $code): string
    {
        $value = strtoupper(trim($abbreviation));
        if ($value === '') {
            $value = $this->statusDefaultAbbreviations[$code] ?? '';
        }
        if ($value === '') {
            $normalizedLabel = strtoupper(trim($label));
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalizedLabel);
                if (is_string($converted) && $converted !== '') {
                    $normalizedLabel = $converted;
                }
            }
            $value = preg_replace('/[^A-Z0-9]+/', '', $normalizedLabel) ?: 'AUT';
            $value = substr($value, 0, 6);
        }

        $value = preg_replace('/[^A-Z0-9]/', '', $value) ?: 'AUT';
        return substr($value, 0, 12);
    }

    private function seedDefaultSites(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM rh_sites')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT IGNORE INTO rh_sites (code, label, is_active, sort_order) VALUES (:code, :label, :is_active, :sort_order)');
        $stmt->execute([':code' => 1, ':label' => 'Abidjan', ':is_active' => 1, ':sort_order' => 1]);
    }

    private function ensureRhSupportTables(): void
    {
        try{$categoryExists=(bool)$this->pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='personnel' AND COLUMN_NAME='personnel_category'")->fetchColumn();if(!$categoryExists){$this->pdo->exec("ALTER TABLE personnel ADD COLUMN personnel_category ENUM('interne','prestataire') NOT NULL DEFAULT 'interne'");$this->pdo->exec("UPDATE personnel SET personnel_category='prestataire' WHERE statut_pers_soignant_code IN (2,3)");}}catch(Throwable $e){}
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_fiches_poste (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fonction_id INT NOT NULL,
                intitule VARCHAR(190) NOT NULL,
                mission_principale TEXT NULL,
                responsabilites LONGTEXT NULL,
                activites LONGTEXT NULL,
                competences LONGTEXT NULL,
                qualifications LONGTEXT NULL,
                indicateurs_performance LONGTEXT NULL,
                rattachement_hierarchique VARCHAR(190) NULL,
                conditions_exercice TEXT NULL,
                version VARCHAR(30) NOT NULL DEFAULT "1.0",
                statut ENUM("brouillon","active","archivee") NOT NULL DEFAULT "brouillon",
                date_effet DATE NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_rh_fiche_poste_fonction (fonction_id),
                KEY idx_rh_fiche_poste_statut (statut)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS personnel_rh_history (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                event_type VARCHAR(64) NOT NULL,
                source_kind VARCHAR(32) NOT NULL DEFAULT "real",
                event_date DATE DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_personnel_rh_history_personnel (personnel_id),
                KEY idx_personnel_rh_history_date (event_date),
                KEY idx_personnel_rh_history_type (event_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS personnel_rh_document_archive (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                document_key VARCHAR(64) NOT NULL,
                action_type VARCHAR(32) NOT NULL,
                original_path VARCHAR(255) NOT NULL,
                archived_path VARCHAR(255) DEFAULT NULL,
                metadata_json LONGTEXT DEFAULT NULL,
                acted_by_user_id INT DEFAULT NULL,
                acted_by_name VARCHAR(150) DEFAULT NULL,
                acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_personnel_rh_doc_archive_personnel (personnel_id),
                KEY idx_personnel_rh_doc_archive_document (document_key),
                KEY idx_personnel_rh_doc_archive_action (action_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS personnel_rh_children (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                child_index INT UNSIGNED NOT NULL DEFAULT 1,
                child_birth_date DATE DEFAULT NULL,
                birth_certificate_path VARCHAR(500) DEFAULT NULL,
                created_by_user_id INT DEFAULT NULL,
                created_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_personnel_rh_children_personnel (personnel_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS personnel_rh_change_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                change_group VARCHAR(80) NOT NULL,
                field_key VARCHAR(80) NOT NULL,
                field_label VARCHAR(150) NOT NULL,
                old_value LONGTEXT DEFAULT NULL,
                new_value LONGTEXT DEFAULT NULL,
                changed_by_user_id INT DEFAULT NULL,
                changed_by_name VARCHAR(150) DEFAULT NULL,
                changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_personnel_rh_change_log_personnel (personnel_id),
                KEY idx_personnel_rh_change_log_group (change_group),
                KEY idx_personnel_rh_change_log_date (changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_exit_reasons (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                label VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                KEY idx_rh_exit_reasons_active (is_active),
                KEY idx_rh_exit_reasons_sort (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_statuts (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code INT NOT NULL,
                label VARCHAR(150) NOT NULL,
                matricule_abbreviation VARCHAR(12) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_rh_statuts_code (code),
                KEY idx_rh_statuts_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_sites (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code INT NOT NULL,
                label VARCHAR(150) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL DEFAULT NULL,
                UNIQUE KEY uq_rh_sites_code (code),
                KEY idx_rh_sites_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->seedDefaultStatuts();
        $this->seedDefaultSites();

        $this->availableTables = null;
    }

    private function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    private function commitTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    private function rollbackTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function getAvailablePersonnelColumns(): array
    {
        if ($this->availableColumns !== null) {
            return $this->availableColumns;
        }

        $this->availableColumns = [];
        $stmt = $this->pdo->query('SHOW COLUMNS FROM personnel');
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($columns as $column) {
            if (!empty($column['Field'])) {
                $this->availableColumns[$column['Field']] = true;
            }
        }

        return $this->availableColumns;
    }

    private function getAvailableTables(): array
    {
        if ($this->availableTables !== null) {
            return $this->availableTables;
        }

        $this->availableTables = [];
        foreach ($this->pdo->query('SHOW TABLES') as $row) {
            $tableName = (string) array_values($row)[0];
            $this->availableTables[$tableName] = true;
        }

        return $this->availableTables;
    }
}

