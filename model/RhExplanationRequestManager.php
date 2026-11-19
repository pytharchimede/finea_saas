<?php

require_once __DIR__ . '/EnvConfig.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/../services/NotificationEmailChannelService.php';
require_once __DIR__ . '/AuditTrail.php';
require_once __DIR__ . '/RhExplanationPdfBuilder.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';
require_once __DIR__ . '/../vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';

\EnvConfig::loadFromDirectory(__DIR__);

class RhExplanationRequestManager
{
    public const STATUS_PENDING = 'pending_response';
    public const STATUS_COMPLEMENT = 'complement_requested';
    public const STATUS_ANSWERED = 'answered';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    private PDO $pdo;
    private \PersonnelDirectory $directory;
    private static ?\HTMLPurifier $purifier = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->directory = new \PersonnelDirectory($pdo);
        $this->ensureTables();
    }

    public function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'En attente de réponse',
            self::STATUS_COMPLEMENT => 'Complément demandé',
            self::STATUS_ANSWERED => 'Réponse reçue',
            self::STATUS_CLOSED => 'Clôturée',
            self::STATUS_CANCELLED => 'Annulée',
            'overdue' => 'En retard',
        ];
    }

    public function getActiveUsers(): array
    {
        $rows = $this->pdo->query('SELECT id, prenom, nom, mail_pro, acces_rh FROM user_devis_banamur WHERE active = 1 ORDER BY prenom ASC, nom ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $email = trim((string) ($row['mail_pro'] ?? ''));
            $name = trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
            $out[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : ('Utilisateur #' . $id),
                'email' => $email,
                'is_rh' => !empty($row['acces_rh']),
            ];
        }

        return $out;
    }

    public function getRhUsers(): array
    {
        return array_values(array_filter($this->getActiveUsers(), static function (array $user): bool {
            return !empty($user['is_rh']);
        }));
    }

    public function getSettings(): array
    {
        $defaults = [
            'dg_user_id' => 0,
            'response_due_days' => 3,
            'template_intro' => 'Dans le cadre du suivi RH, nous vous prions de bien vouloir fournir vos explications sur les éléments ci-dessous.',
            'template_context_label' => 'Contexte de la demande',
            'template_facts_label' => 'Faits constatés',
            'template_expectations_label' => 'Explications attendues',
            'template_supporting_label' => 'Éléments complémentaires',
            'template_closing' => 'Nous vous demandons de transmettre une réponse claire et complète dans le délai indiqué.',
            'employee_response_hint' => 'Expliquez les faits de manière précise, chronologique et factuelle.',
        ];

        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM rh_explanation_settings');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['setting_key'] ?? ''));
            if ($key === '' || !array_key_exists($key, $defaults)) {
                continue;
            }
            if ($key === 'response_due_days' || $key === 'dg_user_id') {
                $defaults[$key] = (int) ($row['setting_value'] ?? 0);
            } else {
                $defaults[$key] = (string) ($row['setting_value'] ?? '');
            }
        }

        if ($defaults['response_due_days'] <= 0) {
            $defaults['response_due_days'] = 3;
        }

        return $defaults;
    }

    public function saveSettings(array $settings): void
    {
        $normalized = $this->getSettings();
        $normalized['dg_user_id'] = max(0, (int) ($settings['dg_user_id'] ?? 0));
        $normalized['response_due_days'] = max(1, min(15, (int) ($settings['response_due_days'] ?? 3)));
        $normalized['template_intro'] = $this->normalizeSettingText((string) ($settings['template_intro'] ?? $normalized['template_intro']));
        $normalized['template_context_label'] = $this->normalizeSettingLabel((string) ($settings['template_context_label'] ?? $normalized['template_context_label']), 'Contexte de la demande');
        $normalized['template_facts_label'] = $this->normalizeSettingLabel((string) ($settings['template_facts_label'] ?? $normalized['template_facts_label']), 'Faits constatés');
        $normalized['template_expectations_label'] = $this->normalizeSettingLabel((string) ($settings['template_expectations_label'] ?? $normalized['template_expectations_label']), 'Explications attendues');
        $normalized['template_supporting_label'] = $this->normalizeSettingLabel((string) ($settings['template_supporting_label'] ?? $normalized['template_supporting_label']), 'Éléments complémentaires');
        $normalized['template_closing'] = $this->normalizeSettingText((string) ($settings['template_closing'] ?? $normalized['template_closing']));
        $normalized['employee_response_hint'] = $this->normalizeSettingText((string) ($settings['employee_response_hint'] ?? $normalized['employee_response_hint']));

        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()');
        foreach ($normalized as $key => $value) {
            $stmt->execute([
                'k' => $key,
                'v' => (string) $value,
            ]);
        }
    }

    public function getServiceHierarchyRows(): array
    {
        $services = $this->directory->getServices();
        $stmt = $this->pdo->query('SELECT * FROM rh_service_hierarchy ORDER BY service_id ASC');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) ($row['service_id'] ?? 0)] = $row;
        }

        $result = [];
        foreach ($services as $service) {
            $serviceId = (int) ($service['id'] ?? 0);
            $row = $indexed[$serviceId] ?? [];
            $result[] = [
                'service_id' => $serviceId,
                'service_label' => (string) ($service['label'] ?? ''),
                'direction_label' => (string) ($row['direction_label'] ?? ''),
                'direct_manager_user_id' => (int) ($row['direct_manager_user_id'] ?? 0),
                'department_head_user_id' => (int) ($row['department_head_user_id'] ?? 0),
                'contract_signer_user_id' => (int) ($row['contract_signer_user_id'] ?? 0),
            ];
        }

        return $result;
    }

    public function saveServiceHierarchy(int $serviceId, array $data): void
    {
        if ($serviceId <= 0) {
            throw new RuntimeException('Service invalide.');
        }

        $directionLabel = trim((string) ($data['direction_label'] ?? ''));
        $directManagerUserId = max(0, (int) ($data['direct_manager_user_id'] ?? 0));
        $departmentHeadUserId = max(0, (int) ($data['department_head_user_id'] ?? 0));
        $contractSignerUserId = max(0, (int) ($data['contract_signer_user_id'] ?? 0));

        $stmt = $this->pdo->prepare('INSERT INTO rh_service_hierarchy (service_id, direction_label, direct_manager_user_id, department_head_user_id, contract_signer_user_id, updated_at) VALUES (:service_id, :direction_label, :direct_manager_user_id, :department_head_user_id, :contract_signer_user_id, NOW()) ON DUPLICATE KEY UPDATE direction_label = VALUES(direction_label), direct_manager_user_id = VALUES(direct_manager_user_id), department_head_user_id = VALUES(department_head_user_id), contract_signer_user_id = VALUES(contract_signer_user_id), updated_at = NOW()');
        $stmt->execute([
            'service_id' => $serviceId,
            'direction_label' => $directionLabel !== '' ? $directionLabel : null,
            'direct_manager_user_id' => $directManagerUserId > 0 ? $directManagerUserId : null,
            'department_head_user_id' => $departmentHeadUserId > 0 ? $departmentHeadUserId : null,
            'contract_signer_user_id' => $contractSignerUserId > 0 ? $contractSignerUserId : null,
        ]);
    }

    public function listFaultLevels(bool $activeOnly = false): array
    {
        $sql = 'SELECT fl.*, sl.code AS default_sanction_code, sl.label AS default_sanction_label, sl.category AS default_sanction_category
                FROM rh_explanation_fault_levels fl
                LEFT JOIN rh_explanation_sanction_levels sl ON sl.id = fl.default_sanction_level_id';
        $params = [];
        if ($activeOnly) {
            $sql .= ' WHERE fl.is_active = 1';
        }
        $sql .= ' ORDER BY fl.severity_rank ASC, fl.sort_order ASC, fl.label ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateFaultLevel'], $rows);
    }

    public function listSanctionLevels(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM rh_explanation_sanction_levels';
        $params = [];
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY severity_rank ASC, sort_order ASC, label ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateSanctionLevel'], $rows);
    }

    public function getFaultLevelById(int $faultLevelId): ?array
    {
        if ($faultLevelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT fl.*, sl.code AS default_sanction_code, sl.label AS default_sanction_label, sl.category AS default_sanction_category
            FROM rh_explanation_fault_levels fl
            LEFT JOIN rh_explanation_sanction_levels sl ON sl.id = fl.default_sanction_level_id
            WHERE fl.id = :id
            LIMIT 1');
        $stmt->execute(['id' => $faultLevelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateFaultLevel($row) : null;
    }

    public function getSanctionLevelById(int $sanctionLevelId): ?array
    {
        if ($sanctionLevelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM rh_explanation_sanction_levels WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $sanctionLevelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateSanctionLevel($row) : null;
    }

    public function saveFaultLevel(int $faultLevelId, array $data): int
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Le libellé du niveau de faute est obligatoire.');
        }

        $code = $this->normalizeDisciplineCode((string) ($data['code'] ?? ''), 'fault');
        $severityRank = max(1, min(9, (int) ($data['severity_rank'] ?? 1)));
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $defaultSanctionLevelId = max(0, (int) ($data['default_sanction_level_id'] ?? 0));
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($defaultSanctionLevelId > 0 && !$this->getSanctionLevelById($defaultSanctionLevelId)) {
            throw new RuntimeException('Le niveau de sanction associé est introuvable.');
        }

        if ($faultLevelId > 0) {
            $stmt = $this->pdo->prepare('UPDATE rh_explanation_fault_levels SET code = :code, label = :label, severity_rank = :severity_rank, description = :description, default_sanction_level_id = :default_sanction_level_id, is_active = :is_active, sort_order = :sort_order, updated_at = NOW() WHERE id = :id LIMIT 1');
            $stmt->execute([
                'code' => $code,
                'label' => $label,
                'severity_rank' => $severityRank,
                'description' => $description !== '' ? $description : null,
                'default_sanction_level_id' => $defaultSanctionLevelId > 0 ? $defaultSanctionLevelId : null,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'id' => $faultLevelId,
            ]);
            return $faultLevelId;
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_fault_levels (code, label, severity_rank, description, default_sanction_level_id, is_active, sort_order, created_at, updated_at) VALUES (:code, :label, :severity_rank, :description, :default_sanction_level_id, :is_active, :sort_order, NOW(), NOW())');
        $stmt->execute([
            'code' => $code,
            'label' => $label,
            'severity_rank' => $severityRank,
            'description' => $description !== '' ? $description : null,
            'default_sanction_level_id' => $defaultSanctionLevelId > 0 ? $defaultSanctionLevelId : null,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function saveSanctionLevel(int $sanctionLevelId, array $data): int
    {
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            throw new RuntimeException('Le libellé du niveau de sanction est obligatoire.');
        }

        $code = $this->normalizeDisciplineCode((string) ($data['code'] ?? ''), 'sanction');
        $severityRank = max(1, min(9, (int) ($data['severity_rank'] ?? 1)));
        $sortOrder = (int) ($data['sort_order'] ?? 0);
        $description = trim((string) ($data['description'] ?? ''));
        $category = strtolower(trim((string) ($data['category'] ?? 'disciplinary')));
        if (!in_array($category, ['warning', 'disciplinary'], true)) {
            $category = 'disciplinary';
        }
        $disablesPlatformAccess = !empty($data['disables_platform_access']) && $category === 'disciplinary' ? 1 : 0;
        $isActive = !empty($data['is_active']) ? 1 : 0;

        if ($sanctionLevelId > 0) {
            $stmt = $this->pdo->prepare('UPDATE rh_explanation_sanction_levels SET code = :code, label = :label, severity_rank = :severity_rank, description = :description, category = :category, disables_platform_access = :disables_platform_access, is_active = :is_active, sort_order = :sort_order, updated_at = NOW() WHERE id = :id LIMIT 1');
            $stmt->execute([
                'code' => $code,
                'label' => $label,
                'severity_rank' => $severityRank,
                'description' => $description !== '' ? $description : null,
                'category' => $category,
                'disables_platform_access' => $disablesPlatformAccess,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
                'id' => $sanctionLevelId,
            ]);
            return $sanctionLevelId;
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_sanction_levels (code, label, severity_rank, description, category, disables_platform_access, is_active, sort_order, created_at, updated_at) VALUES (:code, :label, :severity_rank, :description, :category, :disables_platform_access, :is_active, :sort_order, NOW(), NOW())');
        $stmt->execute([
            'code' => $code,
            'label' => $label,
            'severity_rank' => $severityRank,
            'description' => $description !== '' ? $description : null,
            'category' => $category,
            'disables_platform_access' => $disablesPlatformAccess,
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteFaultLevel(int $faultLevelId): bool
    {
        if ($faultLevelId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('DELETE FROM rh_explanation_fault_levels WHERE id = :id LIMIT 1');
        return $stmt->execute(['id' => $faultLevelId]);
    }

    public function deleteSanctionLevel(int $sanctionLevelId): bool
    {
        if ($sanctionLevelId <= 0) {
            return false;
        }

        $this->pdo->prepare('UPDATE rh_explanation_fault_levels SET default_sanction_level_id = NULL WHERE default_sanction_level_id = :id')->execute(['id' => $sanctionLevelId]);
        $stmt = $this->pdo->prepare('DELETE FROM rh_explanation_sanction_levels WHERE id = :id LIMIT 1');
        return $stmt->execute(['id' => $sanctionLevelId]);
    }

    public function getPersonnelChoices(string $search = '', int $limit = 2000): array
    {
        return $this->directory->getPersonnelChoices($search, $limit);
    }

    public function getEmployeeSenderScope(array $actor): array
    {
        $userId = max(0, (int) ($actor['user_id'] ?? 0));
        $email = strtolower(trim((string) ($actor['email'] ?? '')));
        $profile = $this->findPersonnelByEmail($email);
        $serviceId = max(0, (int) ($profile['service_id'] ?? 0));
        $fonctionLabel = trim((string) ($profile['fonction_label'] ?? ''));
        $serviceLabel = trim((string) ($profile['service_label'] ?? ''));
        $normalizedFunction = $this->normalizeComparableText($fonctionLabel);
        $normalizedService = $this->normalizeComparableText($serviceLabel);
        $settings = $this->getSettings();
        $hierarchy = $this->getHierarchyForService($serviceId);

        $isRh = $this->isRhPersonnelProfile($normalizedService, $normalizedFunction);

        $isDg = $userId > 0 && $userId === max(0, (int) ($settings['dg_user_id'] ?? 0));
        if (!$isDg && $normalizedFunction !== '') {
            $isDg = strpos($normalizedFunction, 'directeur general') !== false
                || preg_match('/(^|\s)dg(\s|$)/', $normalizedFunction) === 1;
        }

        $isResponsableByTitle = $normalizedFunction !== '' && (
            strpos($normalizedFunction, 'responsable') !== false
            || strpos($normalizedFunction, 'chef de departement') !== false
            || strpos($normalizedFunction, 'chef departement') !== false
        );

        $isHierarchyManager = $userId > 0 && in_array($userId, [
            (int) ($hierarchy['direct_manager_user_id'] ?? 0),
            (int) ($hierarchy['department_head_user_id'] ?? 0),
        ], true);

        $isGlobalScope = $isRh || $isDg;
        $canSend = $isGlobalScope || $isResponsableByTitle || $isHierarchyManager;

        $reason = '';
        if (!$canSend) {
            $reason = 'Seuls les responsables, chefs de departement, le DG et les RH peuvent envoyer une demande d\'explications depuis cet espace.';
        } elseif (!$isGlobalScope && $serviceId <= 0) {
            $canSend = false;
            $reason = 'Votre service n\'est pas renseigne. Impossible de limiter les destinataires autorises.';
        }

        return [
            'can_send' => $canSend,
            'reason' => $reason,
            'is_global_scope' => $canSend && $isGlobalScope,
            'is_rh' => $isRh,
            'is_dg' => $isDg,
            'service_id' => $serviceId,
            'service_label' => $serviceLabel,
            'fonction_label' => $fonctionLabel,
            'personnel_id' => max(0, (int) ($profile['id'] ?? 0)),
            'personnel_profile' => $profile,
        ];
    }

    public function getPersonnelChoicesForEmployeeSender(array $actor, string $search = '', int $limit = 2000): array
    {
        $scope = $this->getEmployeeSenderScope($actor);
        if (empty($scope['can_send'])) {
            return [];
        }

        $choices = $this->directory->getPersonnelChoices($search, $limit);
        $actorEmail = strtolower(trim((string) ($actor['email'] ?? '')));
        $actorPersonnelId = max(0, (int) ($scope['personnel_id'] ?? 0));
        $activeUsersByEmail = [];
        foreach ($this->getActiveUsers() as $user) {
            $userEmail = strtolower(trim((string) ($user['email'] ?? '')));
            if ($userEmail !== '') {
                $activeUsersByEmail[$userEmail] = $user;
            }
        }

        $filtered = [];
        foreach ($choices as $choice) {
            $personnelId = (int) ($choice['id'] ?? 0);
            $email = strtolower(trim((string) ($choice['email'] ?? '')));
            if ($personnelId <= 0 || $email === '') {
                continue;
            }
            if ($actorPersonnelId > 0 && $personnelId === $actorPersonnelId) {
                continue;
            }
            if ($actorEmail !== '' && $email === $actorEmail) {
                continue;
            }
            $targetServiceId = (int) ($choice['service_id'] ?? 0);
            if ($targetServiceId <= 0) {
                $targetProfile = $this->findPersonnelById($personnelId);
                $targetServiceId = (int) ($targetProfile['service_id'] ?? 0);
            }
            if (empty($scope['is_global_scope']) && $targetServiceId !== (int) ($scope['service_id'] ?? 0)) {
                continue;
            }
            if (!isset($activeUsersByEmail[$email])) {
                continue;
            }
            $filtered[] = $choice;
        }

        return array_values($filtered);
    }

    public function listRequestsForRh(string $statusFilter = 'open', int $limit = 300): array
    {
        $this->runDisciplineMaintenance();
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT * FROM rh_explanation_requests';
        $params = [];

        if ($statusFilter === 'open') {
            $sql .= ' WHERE status IN (:pending, :complement, :answered)';
            $params = [
                'pending' => self::STATUS_PENDING,
                'complement' => self::STATUS_COMPLEMENT,
                'answered' => self::STATUS_ANSWERED,
            ];
        } elseif ($statusFilter === 'closed') {
            $sql .= ' WHERE status = :status';
            $params = ['status' => self::STATUS_CLOSED];
        } elseif ($statusFilter === 'cancelled') {
            $sql .= ' WHERE status = :status';
            $params = ['status' => self::STATUS_CANCELLED];
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateRequest'], $rows);
    }

    public function listRequestsForEmployee(int $userId, string $email, int $limit = 200): array
    {
        $this->runDisciplineMaintenance();
        $limit = max(1, min(500, $limit));
        $email = strtolower(trim($email));
        $stmt = $this->pdo->prepare('SELECT * FROM rh_explanation_requests WHERE (target_user_id = :uid OR LOWER(target_email) = :email) ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute([
            'uid' => $userId,
            'email' => $email,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRequest'], $rows);
    }

    public function listRequestsCreatedByUser(int $userId, int $limit = 200): array
    {
        $this->runDisciplineMaintenance();
        $userId = max(0, $userId);
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare('SELECT * FROM rh_explanation_requests WHERE created_by_user_id = :uid ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit);
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRequest'], $rows);
    }

    public function listDeletedRequestArchives(int $limit = 200, string $search = ''): array
    {
        $limit = max(1, min(500, $limit));
        $search = trim($search);
        $sql = 'SELECT * FROM rh_explanation_deleted_archive';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE request_ref LIKE :search OR deleted_by_name LIKE :search OR delete_reason LIKE :search OR request_snapshot_json LIKE :search';
            $params['search'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY deleted_at DESC, id DESC LIMIT ' . (int) $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $row): array {
            $requestSnapshot = json_decode((string) ($row['request_snapshot_json'] ?? ''), true);
            $messagesSnapshot = json_decode((string) ($row['messages_snapshot_json'] ?? ''), true);
            $row['request_id'] = (int) ($row['request_id'] ?? 0);
            $row['request_snapshot'] = is_array($requestSnapshot) ? $requestSnapshot : [];
            $row['messages_snapshot'] = is_array($messagesSnapshot) ? $messagesSnapshot : [];
            $row['messages_count'] = count($row['messages_snapshot']);
            $row['subject'] = (string) ($row['request_snapshot']['subject'] ?? '');
            $row['target_name'] = (string) ($row['request_snapshot']['target_name'] ?? '');
            return $row;
        }, $rows);
    }

    public function getRequestById(int $requestId): ?array
    {
        $this->runDisciplineMaintenance();
        if ($requestId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM rh_explanation_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRequest($row) : null;
    }

    public function listMessages(int $requestId, bool $includeInternal = true): array
    {
        $sql = 'SELECT * FROM rh_explanation_messages WHERE request_id = :id';
        if (!$includeInternal) {
            $sql .= ' AND is_internal = 0';
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function acknowledgeEmployeeRead(int $requestId, array $actor): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        $employeeUserId = max(0, (int) ($actor['user_id'] ?? 0));
        $employeeEmail = strtolower(trim((string) ($actor['email'] ?? '')));
        $matchesUser = $employeeUserId > 0 && (int) ($request['target_user_id'] ?? 0) === $employeeUserId;
        $matchesEmail = $employeeEmail !== '' && strtolower(trim((string) ($request['target_email'] ?? ''))) === $employeeEmail;
        if (!$matchesUser && !$matchesEmail) {
            return false;
        }

        if (trim((string) ($request['employee_read_ack_at'] ?? '')) !== '') {
            return true;
        }

        $readAt = date('Y-m-d H:i:s');
        $sanctionAvailableAt = $this->addBusinessHours(new DateTimeImmutable($readAt), 72)->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE rh_explanation_requests
            SET employee_first_read_at = COALESCE(employee_first_read_at, :read_at),
                employee_read_ack_at = COALESCE(employee_read_ack_at, :read_at),
                employee_read_ack_user_id = COALESCE(employee_read_ack_user_id, :user_id),
                employee_read_ack_ip = COALESCE(employee_read_ack_ip, :ip),
                sanction_available_at = COALESCE(sanction_available_at, :sanction_available_at),
                updated_at = NOW()
            WHERE id = :id LIMIT 1');
        $ok = $stmt->execute([
            'read_at' => $readAt,
            'user_id' => $employeeUserId > 0 ? $employeeUserId : null,
            'ip' => $this->resolveActorIp($actor),
            'sanction_available_at' => $sanctionAvailableAt,
            'id' => $requestId,
        ]);

        if ($ok) {
            $updated = $this->getRequestById($requestId);
            $this->logDisciplineEvent('read_acknowledged', $updated ?: $request, $actor, [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => (string) (($updated['status'] ?? $request['status']) ?? ''),
                'read_ack_at' => $readAt,
                'sanction_available_at' => $sanctionAvailableAt,
            ]);
        }

        return $ok;
    }

    public function getRequestPdfDocument(int $requestId): ?array
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return null;
        }

        $request['pdf_url'] = $this->absoluteUrl('/request/export_rh_explanation_pdf.php?id=' . $requestId);
        $request['employee_view_url'] = $this->absoluteUrl('/employe/mes_explications_employe.php?id=' . $requestId);
        $request['rh_view_url'] = $this->absoluteUrl('/rh/demandes_explication_rh.php?id=' . $requestId);
        $request['rh_signature_abs_path'] = $this->resolveStorageAbsolutePath((string) ($request['rh_signature_path'] ?? ''));
        $request['employee_signature_abs_path'] = $this->resolveStorageAbsolutePath((string) ($request['employee_signature_path'] ?? ''));

        return rh_explanation_generate_pdf_document($request, $this->listMessages($requestId, true));
    }

    public function getRequestSignatureAbsolutePath(int $requestId, string $role): ?string
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return null;
        }

        $role = $role === 'employee' ? 'employee' : 'rh';
        $relativePath = trim((string) ($role === 'employee' ? ($request['employee_signature_path'] ?? '') : ($request['rh_signature_path'] ?? '')));
        if ($relativePath === '') {
            return null;
        }

        return $this->resolveStorageAbsolutePath($relativePath);
    }

    public function saveRequestSignature(int $requestId, string $role, array $actor, string $signatureDataUrl): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        $role = $role === 'employee' ? 'employee' : 'rh';
        if ($role === 'employee') {
            $employeeUserId = max(0, (int) ($actor['user_id'] ?? 0));
            $employeeEmail = strtolower(trim((string) ($actor['email'] ?? '')));
            $matchesUser = $employeeUserId > 0 && (int) ($request['target_user_id'] ?? 0) === $employeeUserId;
            $matchesEmail = $employeeEmail !== '' && strtolower(trim((string) ($request['target_email'] ?? ''))) === $employeeEmail;
            if (!$matchesUser && !$matchesEmail) {
                throw new RuntimeException('Vous ne pouvez pas signer ce dossier.');
            }

            $this->acknowledgeEmployeeRead($requestId, $actor);
        }

        if ((string) ($request['status'] ?? '') === self::STATUS_CANCELLED) {
            throw new RuntimeException('Impossible de signer un dossier annulé.');
        }

        $storedSignature = $this->storeSignatureDataUrl($requestId, $role, $signatureDataUrl);
        $pathColumn = $role === 'employee' ? 'employee_signature_path' : 'rh_signature_path';
        $signedAtColumn = $role === 'employee' ? 'employee_signed_at' : 'rh_signed_at';
        $signedByUserColumn = $role === 'employee' ? 'employee_signed_by_user_id' : 'rh_signed_by_user_id';
        $signedByNameColumn = $role === 'employee' ? 'employee_signed_by_name' : 'rh_signed_by_name';

        $existingPath = trim((string) ($request[$pathColumn] ?? ''));
        if ($existingPath !== '' && $existingPath !== $storedSignature['relative_path']) {
            $existingAbsolutePath = $this->resolveStorageAbsolutePath($existingPath);
            if ($existingAbsolutePath !== null && is_file($existingAbsolutePath)) {
                @unlink($existingAbsolutePath);
            }
        }

        $sql = 'UPDATE rh_explanation_requests SET '
            . $pathColumn . ' = :signature_path, '
            . $signedAtColumn . ' = NOW(), '
            . $signedByUserColumn . ' = :signed_by_user_id, '
            . $signedByNameColumn . ' = :signed_by_name, '
            . 'updated_at = NOW() WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $ok = $stmt->execute([
            'signature_path' => $storedSignature['relative_path'],
            'signed_by_user_id' => max(0, (int) ($actor['user_id'] ?? 0)) ?: null,
            'signed_by_name' => trim((string) ($actor['name'] ?? '')),
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->saveSignatureProfile($role, max(0, (int) ($actor['user_id'] ?? 0)), trim((string) ($actor['name'] ?? '')), $storedSignature['absolute_path']);

            \AuditTrail::log('rh_explanation_signature_saved', [
                'module' => $role === 'employee' ? 'employe' : 'rh',
                'action' => 'save_handwritten_signature',
                'entity_type' => 'rh_explanation_request',
                'entity_id' => (string) $requestId,
                'entity_label' => (string) ($request['request_ref'] ?? ''),
                'details' => [
                    'role' => $role,
                    'file' => $storedSignature['relative_path'],
                ],
            ], $this->pdo);
        }

        return $ok;
    }

    public function resendRequestPdf(int $requestId, array $actor): void
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            throw new RuntimeException('Dossier introuvable.');
        }

        $targetEmail = trim((string) ($request['target_email'] ?? ''));
        if ($targetEmail === '') {
            throw new RuntimeException('Aucun email employé n’est associé à ce dossier.');
        }

        $pdfAttachment = $this->getPdfAttachment($requestId);
        if (!$pdfAttachment) {
            throw new RuntimeException('Le PDF n’a pas pu être généré pour le renvoi.');
        }

        $employeeUrl = $this->absoluteUrl('/employe/mes_explications_employe.php?id=' . $requestId);
        $resenderName = trim((string) ($actor['name'] ?? '')) ?: 'RH';
        $signatureSummary = [];
        $signatureSummary[] = !empty($request['rh_signed_at']) ? 'Signature RH enregistrée' : 'Signature RH en attente';
        $signatureSummary[] = !empty($request['employee_signed_at']) ? 'Signature employé enregistrée' : 'Signature employé en attente';
        $subject = '[Renvoi PDF ' . (string) ($request['request_ref'] ?? ('#' . $requestId)) . '] ' . (string) ($request['subject'] ?? 'Demande d’explications');
        $html = $this->buildEmailLayout(
            'Renvoi du document RH',
            'Le dossier a été renvoyé sans recréation de demande. Le PDF joint reprend l’état actuel du dossier, y compris les signatures déjà enregistrées.',
            [
                ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                ['label' => 'Objet', 'value' => (string) ($request['subject'] ?? '')],
                ['label' => 'Statut', 'value' => (string) ($request['status_label'] ?? '')],
                ['label' => 'Signatures', 'value' => implode(' • ', $signatureSummary)],
            ],
            '<p>Bonjour ' . htmlspecialchars((string) ($request['target_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Le document PDF de votre dossier RH vous est renvoyé par <strong>' . htmlspecialchars($resenderName, ENT_QUOTES, 'UTF-8') . '</strong>. Il s’agit du même dossier, mis à jour avec son état courant.</p>'
                . '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">'
                . '<p style="margin:0 0 8px 0;"><strong>Réponse attendue :</strong> ' . htmlspecialchars($this->formatDateTimeFr((string) ($request['due_at'] ?? '')), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p style="margin:0;">Le QR code et les visas RH / DG sont intégrés au document pour faciliter le suivi officiel.</p>'
                . '</div>',
            'Accéder au dossier',
            $employeeUrl,
            'Conservez le PDF joint comme version officielle du dossier. Vous pouvez le rouvrir ultérieurement depuis votre espace.',
            'Direction des ressources humaines BANAMUR'
        );
        $plain = $this->buildPlainEmailText('Renvoi du document RH', [
            'Référence: ' . (string) ($request['request_ref'] ?? ''),
            'Objet: ' . (string) ($request['subject'] ?? ''),
            'Statut: ' . (string) ($request['status_label'] ?? ''),
            'Accès au dossier: ' . $employeeUrl,
        ]);

        (new \NotificationEmailChannelService())->send($targetEmail, (string) ($request['target_name'] ?? ''), $subject, $html, $plain, [
            'from_name' => 'Direction des ressources humaines BANAMUR',
            'cc' => $this->buildCcUsersFromRequest($request),
            'attachments' => [$pdfAttachment],
        ]);

        $targetUserId = (int) ($request['target_user_id'] ?? 0);
        if ($targetUserId > 0) {
            $this->createSystemNotification('rh_explanation_pdf_resent', 'target', $subject, 'Le document PDF de votre dossier vous a été renvoyé.', '/employe/mes_explications_employe.php?id=' . $requestId, null, $targetUserId, [
                'request_id' => $requestId,
                'request_ref' => (string) ($request['request_ref'] ?? ''),
            ]);
        }

        \AuditTrail::log('rh_explanation_pdf_resent', [
            'module' => 'rh',
            'action' => 'resend_rh_explanation_pdf',
            'entity_type' => 'rh_explanation_request',
            'entity_id' => (string) $requestId,
            'entity_label' => (string) ($request['request_ref'] ?? ''),
            'details' => [
                'target_email' => $targetEmail,
                'resent_by' => $resenderName,
            ],
        ], $this->pdo);
    }

    public function createRequest(array $actor, array $data): int
    {
        $targetPersonnelId = max(0, (int) ($data['target_personnel_id'] ?? 0));
        $subject = trim((string) ($data['subject'] ?? ''));
        $isCadreDirect = !empty($data['is_cadre_direct']) ? 1 : 0;
        $dueDays = max(1, min(15, (int) ($data['due_days'] ?? $this->getSettings()['response_due_days'])));

        if ($targetPersonnelId <= 0) {
            throw new RuntimeException('Le collaborateur concerné est obligatoire.');
        }
        if ($subject === '') {
            throw new RuntimeException('L’objet de la demande est obligatoire.');
        }

        $target = $this->findPersonnelById($targetPersonnelId);
        if (!$target) {
            throw new RuntimeException('Le collaborateur ciblé est introuvable.');
        }
        if (trim((string) ($target['email'] ?? '')) === '') {
            throw new RuntimeException('Le collaborateur ciblé ne dispose pas d’un email professionnel.');
        }

        $requestBody = $this->buildRequestBodyFromForm($data, $target, $dueDays);
        if ($requestBody['text'] === '') {
            throw new RuntimeException('Le contenu de la demande d’explications est obligatoire.');
        }

        $targetUser = $this->findActiveUserByEmail((string) ($target['email'] ?? ''));
        if (!$targetUser) {
            throw new RuntimeException('Aucun compte utilisateur actif n’est lié à cet email. La réponse dans l’espace employé serait impossible.');
        }

        $this->assertActorCanCreateRequestForTarget($actor, $target, $targetUser);

        $recipients = $this->resolveRecipients((int) ($target['service_id'] ?? 0), (bool) $isCadreDirect, $targetUser);
        $dueAt = $this->addBusinessDays(new DateTimeImmutable('now'), $dueDays)->setTime(18, 0, 0);
        $readAlertDueAt = $this->addBusinessHours(new DateTimeImmutable('now'), 24)->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_requests (request_ref, created_by_user_id, created_by_name, created_by_email, target_personnel_id, target_user_id, target_name, target_email, target_service_id, target_service_label, target_function_label, direct_manager_user_id, department_head_user_id, dg_user_id, subject, request_html, request_text, status, due_at, read_alert_due_at, is_cadre_direct, cc_user_ids_json, cc_emails_json, created_at, updated_at) VALUES ("", :created_by_user_id, :created_by_name, :created_by_email, :target_personnel_id, :target_user_id, :target_name, :target_email, :target_service_id, :target_service_label, :target_function_label, :direct_manager_user_id, :department_head_user_id, :dg_user_id, :subject, :request_html, :request_text, :status, :due_at, :read_alert_due_at, :is_cadre_direct, :cc_user_ids_json, :cc_emails_json, NOW(), NOW())');
        $stmt->execute([
            'created_by_user_id' => max(0, (int) ($actor['user_id'] ?? 0)),
            'created_by_name' => trim((string) ($actor['name'] ?? '')),
            'created_by_email' => trim((string) ($actor['email'] ?? '')),
            'target_personnel_id' => $targetPersonnelId,
            'target_user_id' => (int) ($targetUser['id'] ?? 0),
            'target_name' => (string) ($target['full_name'] ?? ''),
            'target_email' => (string) ($target['email'] ?? ''),
            'target_service_id' => max(0, (int) ($target['service_id'] ?? 0)) ?: null,
            'target_service_label' => (string) ($target['service_label'] ?? ''),
            'target_function_label' => (string) ($target['fonction_label'] ?? ''),
            'direct_manager_user_id' => $recipients['direct_manager_user_id'] ?: null,
            'department_head_user_id' => $recipients['department_head_user_id'] ?: null,
            'dg_user_id' => $recipients['dg_user_id'] ?: null,
            'subject' => $subject,
            'request_html' => $requestBody['html'],
            'request_text' => $requestBody['text'],
            'status' => self::STATUS_PENDING,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'read_alert_due_at' => $readAlertDueAt,
            'is_cadre_direct' => $isCadreDirect,
            'cc_user_ids_json' => json_encode($recipients['watcher_user_ids'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cc_emails_json' => json_encode($recipients['cc_emails'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $requestId = (int) $this->pdo->lastInsertId();
        $requestRef = $this->buildRequestRef($requestId);
        $this->pdo->prepare('UPDATE rh_explanation_requests SET request_ref = :ref WHERE id = :id')->execute([
            'ref' => $requestRef,
            'id' => $requestId,
        ]);

        $this->applySignatureProfileToRequest($requestId, 'employee', (int) ($targetUser['id'] ?? 0));

        $this->addMessage($requestId, 'rh', max(0, (int) ($actor['user_id'] ?? 0)), trim((string) ($actor['name'] ?? '')), 'request', $requestBody['html'], $requestBody['text']);

        $request = $this->getRequestById($requestId);
        if ($request) {
            $this->sendCreationCommunications($request, $targetUser, $recipients);
            $this->logDisciplineEvent('request_sent', $request, $actor, [
                'status_before' => '',
                'status_after' => (string) ($request['status'] ?? self::STATUS_PENDING),
                'due_at' => (string) ($request['due_at'] ?? ''),
                'read_alert_due_at' => $readAlertDueAt,
            ]);
        }

        return $requestId;
    }

    public function addEmployeeResponse(int $requestId, array $employee, string $html): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        $employeeUserId = max(0, (int) ($employee['user_id'] ?? 0));
        $employeeEmail = strtolower(trim((string) ($employee['email'] ?? '')));
        if ($employeeUserId <= 0 && $employeeEmail === '') {
            return false;
        }

        if (!in_array((string) ($request['status'] ?? ''), [self::STATUS_PENDING, self::STATUS_COMPLEMENT], true)) {
            return false;
        }

        if ($employeeUserId > 0 && (int) ($request['target_user_id'] ?? 0) !== $employeeUserId) {
            if ($employeeEmail === '' || strtolower((string) ($request['target_email'] ?? '')) !== $employeeEmail) {
                return false;
            }
        }

        $this->acknowledgeEmployeeRead($requestId, $employee);

        $response = $this->sanitizeRichHtml($html);
        if ($response['text'] === '') {
            throw new RuntimeException('La réponse ne peut pas être vide.');
        }

        $stmt = $this->pdo->prepare('UPDATE rh_explanation_requests SET status = :status, answered_at = NOW(), updated_at = NOW() WHERE id = :id LIMIT 1');
        $ok = $stmt->execute([
            'status' => self::STATUS_ANSWERED,
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->addMessage($requestId, 'employee', $employeeUserId, trim((string) ($employee['name'] ?? '')), 'response', $response['html'], $response['text']);
            $updated = $this->getRequestById($requestId);
            if ($updated) {
                $this->sendResponseCommunications($updated, trim((string) ($employee['name'] ?? '')));
                $this->logDisciplineEvent('employee_response_sent', $updated, $employee, [
                    'status_before' => (string) ($request['status'] ?? ''),
                    'status_after' => (string) ($updated['status'] ?? ''),
                    'answered_at' => (string) ($updated['answered_at'] ?? ''),
                ]);
            }
        }

        return $ok;
    }

    public function requestComplement(int $requestId, array $actor, string $html, ?int $dueDays = null): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }
        if (!in_array((string) ($request['status'] ?? ''), [self::STATUS_ANSWERED, self::STATUS_PENDING, self::STATUS_COMPLEMENT], true)) {
            return false;
        }

        $message = $this->sanitizeRichHtml($html);
        if ($message['text'] === '') {
            throw new RuntimeException('Le complément demandé doit contenir un message.');
        }

        $days = max(1, min(15, (int) ($dueDays ?? $this->getSettings()['response_due_days'])));
        $dueAt = $this->addBusinessDays(new DateTimeImmutable('now'), $days)->setTime(18, 0, 0);

        $stmt = $this->pdo->prepare('UPDATE rh_explanation_requests SET status = :status, due_at = :due_at, updated_at = NOW() WHERE id = :id LIMIT 1');
        $ok = $stmt->execute([
            'status' => self::STATUS_COMPLEMENT,
            'due_at' => $dueAt->format('Y-m-d H:i:s'),
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->addMessage($requestId, 'rh', max(0, (int) ($actor['user_id'] ?? 0)), trim((string) ($actor['name'] ?? '')), 'followup', $message['html'], $message['text']);
            $updated = $this->getRequestById($requestId);
            if ($updated) {
                $this->sendFollowUpCommunications($updated, trim((string) ($actor['name'] ?? '')), $message['html']);
                $this->logDisciplineEvent('followup_requested', $updated, $actor, [
                    'status_before' => (string) ($request['status'] ?? ''),
                    'status_after' => (string) ($updated['status'] ?? ''),
                    'due_at' => (string) ($updated['due_at'] ?? ''),
                ]);
            }
        }

        return $ok;
    }

    public function requestEmployeeRectification(int $requestId, array $employee, string $html): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        $employeeUserId = max(0, (int) ($employee['user_id'] ?? 0));
        $employeeEmail = strtolower(trim((string) ($employee['email'] ?? '')));
        $matchesUser = $employeeUserId > 0 && (int) ($request['target_user_id'] ?? 0) === $employeeUserId;
        $matchesEmail = $employeeEmail !== '' && strtolower(trim((string) ($request['target_email'] ?? ''))) === $employeeEmail;
        if (!$matchesUser && !$matchesEmail) {
            return false;
        }

        if ((string) ($request['status'] ?? '') === self::STATUS_CANCELLED) {
            return false;
        }

        $this->acknowledgeEmployeeRead($requestId, $employee);
        $message = $this->sanitizeRichHtml($html);
        if ($message['text'] === '') {
            throw new RuntimeException('La demande de rectification doit contenir une précision sur l’erreur à corriger.');
        }

        $this->addMessage($requestId, 'employee', $employeeUserId, trim((string) ($employee['name'] ?? '')), 'rectification_request', $message['html'], $message['text']);

        $updated = $this->getRequestById($requestId);
        if ($updated) {
            $this->sendRectificationCommunications($updated, trim((string) ($employee['name'] ?? '')), $message['html']);
            $this->logDisciplineEvent('rectification_requested', $updated, $employee, [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => (string) ($updated['status'] ?? ''),
                'message_kind' => 'rectification_request',
            ]);
        }

        return true;
    }

    public function closeRequest(int $requestId, array $actor, string $html = '', array $decision = []): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request || in_array((string) ($request['status'] ?? ''), [self::STATUS_CLOSED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        if (trim((string) ($request['employee_read_ack_at'] ?? '')) === '') {
            throw new RuntimeException('La sanction ne peut pas être prononcée tant que l’accusé de lecture numérique n’est pas enregistré.');
        }

        if (empty($request['can_apply_sanction'])) {
            throw new RuntimeException('Le verrou 72h ouvrables n’est pas encore levé pour ce dossier.');
        }

        $message = $this->sanitizeRichHtml($html);
        $decisionData = $this->normalizeClosureDecision($decision);
        $retentionUntil = $this->computeDisciplineRetentionUntil(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare('UPDATE rh_explanation_requests SET status = :status, closed_at = NOW(), closed_by_user_id = :closed_by_user_id, closed_by_name = :closed_by_name, fault_level_id = :fault_level_id, fault_level_code = :fault_level_code, fault_level_label = :fault_level_label, fault_severity_rank = :fault_severity_rank, sanction_level_id = :sanction_level_id, sanction_level_code = :sanction_level_code, sanction_level_label = :sanction_level_label, sanction_severity_rank = :sanction_severity_rank, sanction_category = :sanction_category, sanction_disables_platform_access = :sanction_disables_platform_access, platform_access_disabled_at = :platform_access_disabled_at, discipline_retention_until = :discipline_retention_until, updated_at = NOW() WHERE id = :id LIMIT 1');
            $ok = $stmt->execute([
                'status' => self::STATUS_CLOSED,
                'closed_by_user_id' => max(0, (int) ($actor['user_id'] ?? 0)) ?: null,
                'closed_by_name' => trim((string) ($actor['name'] ?? '')),
                'fault_level_id' => $decisionData['fault_level_id'],
                'fault_level_code' => $decisionData['fault_level_code'],
                'fault_level_label' => $decisionData['fault_level_label'],
                'fault_severity_rank' => $decisionData['fault_severity_rank'],
                'sanction_level_id' => $decisionData['sanction_level_id'],
                'sanction_level_code' => $decisionData['sanction_level_code'],
                'sanction_level_label' => $decisionData['sanction_level_label'],
                'sanction_severity_rank' => $decisionData['sanction_severity_rank'],
                'sanction_category' => $decisionData['sanction_category'],
                'sanction_disables_platform_access' => $decisionData['sanction_disables_platform_access'],
                'platform_access_disabled_at' => $decisionData['sanction_disables_platform_access'] ? date('Y-m-d H:i:s') : null,
                'discipline_retention_until' => $retentionUntil,
                'id' => $requestId,
            ]);

            if ($ok) {
                $closureMessage = $this->buildClosureDecisionMessage($decisionData, $message);
                $this->addMessage($requestId, 'rh', max(0, (int) ($actor['user_id'] ?? 0)), trim((string) ($actor['name'] ?? '')), 'closure', $closureMessage['html'], $closureMessage['text']);

                if ($decisionData['sanction_disables_platform_access']) {
                    $this->deactivateUserPlatformAccess((int) ($request['target_user_id'] ?? 0), $request, $decisionData);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($ok) {
            $updated = $this->getRequestById($requestId);
            if ($updated) {
                $this->sendClosureCommunications($updated, trim((string) ($actor['name'] ?? '')), $message['html']);
                $this->logDisciplineEvent('sanction_closed', $updated, $actor, [
                    'status_before' => (string) ($request['status'] ?? ''),
                    'status_after' => (string) ($updated['status'] ?? ''),
                    'fault_level' => (string) ($updated['fault_level_label'] ?? ''),
                    'sanction_level' => (string) ($updated['sanction_level_label'] ?? ''),
                    'discipline_retention_until' => $retentionUntil,
                ]);
            }
        }

        return $ok;
    }

    public function cancelRequest(int $requestId, array $actor, string $html = ''): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request || in_array((string) ($request['status'] ?? ''), [self::STATUS_CLOSED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        $message = $this->sanitizeRichHtml($html);
        $stmt = $this->pdo->prepare('UPDATE rh_explanation_requests SET status = :status, updated_at = NOW() WHERE id = :id LIMIT 1');
        $ok = $stmt->execute([
            'status' => self::STATUS_CANCELLED,
            'id' => $requestId,
        ]);

        if ($ok && $message['text'] !== '') {
            $this->addMessage($requestId, 'rh', max(0, (int) ($actor['user_id'] ?? 0)), trim((string) ($actor['name'] ?? '')), 'cancel', $message['html'], $message['text']);
        }

        if ($ok) {
            $updated = $this->getRequestById($requestId);
            $this->logDisciplineEvent('request_cancelled', $updated ?: $request, $actor, [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => self::STATUS_CANCELLED,
            ]);
        }

        return $ok;
    }

    public function deleteRequest(int $requestId, array $actor, string $reason): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        if (empty($actor['is_admin'])) {
            throw new RuntimeException('Seul un administrateur peut supprimer définitivement une demande d’explications.');
        }

        if (!$this->isTestRequest($request)) {
            throw new RuntimeException('La suppression admin est limitée aux dossiers de test. Règle appliquée : sujet ou contenu contenant un marqueur tel que TEST, ESSAI, DEMO, DÉMO, DEMONSTRATION, SANDBOX ou BROUILLON.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Le motif de suppression admin est obligatoire afin de conserver une trace exploitable.');
        }

        $messages = $this->listMessages($requestId, true);
        $requestSnapshot = $request;
        $signaturePaths = array_values(array_filter([
            trim((string) ($request['rh_signature_abs_path'] ?? '')),
            trim((string) ($request['employee_signature_abs_path'] ?? '')),
        ], static function (string $value): bool {
            return $value !== '';
        }));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_deleted_archive (request_id, request_ref, deleted_by_user_id, deleted_by_name, deleted_by_role, deleted_by_ip, delete_reason, request_snapshot_json, messages_snapshot_json, deleted_at) VALUES (:request_id, :request_ref, :deleted_by_user_id, :deleted_by_name, :deleted_by_role, :deleted_by_ip, :delete_reason, :request_snapshot_json, :messages_snapshot_json, NOW())');
            $stmt->execute([
                'request_id' => (int) ($request['id'] ?? 0),
                'request_ref' => (string) ($request['request_ref'] ?? ''),
                'deleted_by_user_id' => max(0, (int) ($actor['user_id'] ?? 0)) ?: null,
                'deleted_by_name' => trim((string) ($actor['name'] ?? '')),
                'deleted_by_role' => trim((string) ($actor['role'] ?? 'admin')),
                'deleted_by_ip' => $this->resolveActorIp($actor),
                'delete_reason' => $reason,
                'request_snapshot_json' => json_encode($requestSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'messages_snapshot_json' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $this->logDisciplineEvent('admin_deleted', $request, $actor, [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => 'deleted',
                'delete_reason' => $reason,
                'messages_count' => count($messages),
            ]);

            $this->pdo->prepare('DELETE FROM rh_explanation_messages WHERE request_id = :id')->execute([
                'id' => $requestId,
            ]);
            $ok = $this->pdo->prepare('DELETE FROM rh_explanation_requests WHERE id = :id LIMIT 1')->execute([
                'id' => $requestId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        foreach ($signaturePaths as $signaturePath) {
            if (is_file($signaturePath)) {
                @unlink($signaturePath);
            }
        }

        \AuditTrail::log('rh_explanation_request_deleted', [
            'module' => 'rh',
            'action' => 'delete_request_admin',
            'entity_type' => 'rh_explanation_request',
            'entity_id' => (string) $requestId,
            'entity_label' => (string) ($request['request_ref'] ?? ''),
            'result' => $ok ? 'success' : 'failed',
            'severity' => 'warning',
            'details' => [
                'subject' => (string) ($request['subject'] ?? ''),
                'target_name' => (string) ($request['target_name'] ?? ''),
                'delete_reason' => $reason,
                'messages_count' => count($messages),
            ],
        ], $this->pdo);

        return $ok;
    }

    private function hydrateRequest(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['target_user_id'] = (int) ($row['target_user_id'] ?? 0);
        $row['target_personnel_id'] = (int) ($row['target_personnel_id'] ?? 0);
        $row['target_service_id'] = (int) ($row['target_service_id'] ?? 0);
        $row['direct_manager_user_id'] = (int) ($row['direct_manager_user_id'] ?? 0);
        $row['department_head_user_id'] = (int) ($row['department_head_user_id'] ?? 0);
        $row['dg_user_id'] = (int) ($row['dg_user_id'] ?? 0);
        $row['rh_signed_by_user_id'] = (int) ($row['rh_signed_by_user_id'] ?? 0);
        $row['employee_signed_by_user_id'] = (int) ($row['employee_signed_by_user_id'] ?? 0);
        $row['fault_level_id'] = (int) ($row['fault_level_id'] ?? 0);
        $row['fault_severity_rank'] = (int) ($row['fault_severity_rank'] ?? 0);
        $row['sanction_level_id'] = (int) ($row['sanction_level_id'] ?? 0);
        $row['sanction_severity_rank'] = (int) ($row['sanction_severity_rank'] ?? 0);
        $row['sanction_disables_platform_access'] = !empty($row['sanction_disables_platform_access']);
        $row['is_cadre_direct'] = !empty($row['is_cadre_direct']);
        $row['employee_read_ack_user_id'] = (int) ($row['employee_read_ack_user_id'] ?? 0);
        $row['employee_read_ack_ip'] = trim((string) ($row['employee_read_ack_ip'] ?? ''));
        $row['cc_user_ids'] = $this->decodeJsonList($row['cc_user_ids_json'] ?? '[]');
        $row['cc_emails'] = $this->decodeJsonList($row['cc_emails_json'] ?? '[]');
        $row['computed_status'] = $this->computeStatus($row);
        $row['status_label'] = $this->getStatusLabels()[$row['computed_status']] ?? ($this->getStatusLabels()[$row['status'] ?? ''] ?? (string) ($row['status'] ?? ''));
        $row['employee_acknowledged'] = trim((string) ($row['employee_read_ack_at'] ?? '')) !== '';
        $row['sanction_locked_reason'] = $this->buildSanctionLockReason($row);
        $row['can_apply_sanction'] = $row['sanction_locked_reason'] === '';
        $row['discipline_is_archived'] = !empty($row['discipline_archived_at']);
        $row['discipline_summary'] = $this->buildDisciplineSummaryLabel($row);
        $row['is_test_request'] = $this->isTestRequest($row);
        $row['admin_delete_rule_label'] = $row['is_test_request']
            ? 'Dossier de test reconnu par le contenu.'
            : 'Suppression admin réservée aux dossiers portant un marqueur de test dans le sujet ou le contenu.';
        return $row;
    }

    private function computeStatus(array $row): string
    {
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, [self::STATUS_PENDING, self::STATUS_COMPLEMENT], true)) {
            $dueAt = trim((string) ($row['due_at'] ?? ''));
            if ($dueAt !== '' && strtotime($dueAt) !== false && strtotime($dueAt) < time()) {
                return 'overdue';
            }
        }
        return $status;
    }

    private function decodeJsonList($value): array
    {
        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $decoded), static function (string $item): bool {
            return trim($item) !== '';
        }));
    }

    private function normalizeComparableText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }

        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($ascii) && $ascii !== '') {
                $value = $ascii;
            }
        }

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function isRhPersonnelProfile(string $normalizedService, string $normalizedFunction): bool
    {
        $haystack = trim($normalizedService . ' ' . $normalizedFunction);
        if ($haystack === '') {
            return false;
        }

        return strpos($haystack, 'ressources humaines') !== false
            || strpos($haystack, 'ressource humaine') !== false
            || strpos($haystack, 'resource humaine') !== false
            || strpos($haystack, 'human resources') !== false
            || preg_match('/(^|\s)rh(\s|$)/', $haystack) === 1;
    }

    private function findPersonnelByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        foreach ($this->directory->getPersonnelChoices('', 5000) as $choice) {
            if (strtolower(trim((string) ($choice['email'] ?? ''))) !== $email) {
                continue;
            }
            return $this->directory->getPersonnelDetail((int) ($choice['id'] ?? 0));
        }

        return null;
    }

    private function findPersonnelById(int $personnelId): ?array
    {
        return $this->directory->getPersonnelDetail($personnelId);
    }

    private function findActiveUserByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, prenom, nom, mail_pro, acces_rh FROM user_devis_banamur WHERE active = 1 AND LOWER(TRIM(mail_pro)) = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''))),
            'email' => trim((string) ($row['mail_pro'] ?? '')),
            'is_rh' => !empty($row['acces_rh']),
        ];
    }

    private function getUserById(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT id, prenom, nom, mail_pro, acces_rh FROM user_devis_banamur WHERE active = 1 AND id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'name' => trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''))),
            'email' => trim((string) ($row['mail_pro'] ?? '')),
            'is_rh' => !empty($row['acces_rh']),
        ];
    }

    private function hydrateFaultLevel(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['severity_rank'] = (int) ($row['severity_rank'] ?? 0);
        $row['default_sanction_level_id'] = (int) ($row['default_sanction_level_id'] ?? 0);
        $row['default_sanction_code'] = trim((string) ($row['default_sanction_code'] ?? ''));
        $row['default_sanction_label'] = trim((string) ($row['default_sanction_label'] ?? ''));
        $row['default_sanction_category'] = in_array((string) ($row['default_sanction_category'] ?? ''), ['warning', 'disciplinary'], true)
            ? (string) $row['default_sanction_category']
            : '';
        $row['is_active'] = !empty($row['is_active']);
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
        return $row;
    }

    private function hydrateSanctionLevel(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['severity_rank'] = (int) ($row['severity_rank'] ?? 0);
        $row['disables_platform_access'] = !empty($row['disables_platform_access']);
        $row['is_active'] = !empty($row['is_active']);
        $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
        $row['category'] = in_array((string) ($row['category'] ?? ''), ['warning', 'disciplinary'], true) ? (string) $row['category'] : 'disciplinary';
        return $row;
    }

    private function normalizeDisciplineCode(string $code, string $prefix): string
    {
        $code = strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = trim($code, '_');
        if ($code === '') {
            $code = $prefix . '_' . strtolower(bin2hex(random_bytes(3)));
        }
        return substr($code, 0, 64);
    }

    private function normalizeClosureDecision(array $decision): array
    {
        $faultLevelId = max(0, (int) ($decision['fault_level_id'] ?? 0));
        $sanctionLevelId = max(0, (int) ($decision['sanction_level_id'] ?? 0));

        if ($faultLevelId <= 0) {
            throw new RuntimeException('Le niveau de faute est obligatoire pour clôturer le dossier.');
        }
        if ($sanctionLevelId <= 0) {
            throw new RuntimeException('Le niveau de sanction est obligatoire pour clôturer le dossier.');
        }

        $faultLevel = $this->getFaultLevelById($faultLevelId);
        if (!$faultLevel) {
            throw new RuntimeException('Le niveau de faute sélectionné est introuvable.');
        }

        $sanctionLevel = $this->getSanctionLevelById($sanctionLevelId);
        if (!$sanctionLevel) {
            throw new RuntimeException('Le niveau de sanction sélectionné est introuvable.');
        }

        return [
            'fault_level_id' => (int) ($faultLevel['id'] ?? 0),
            'fault_level_code' => (string) ($faultLevel['code'] ?? ''),
            'fault_level_label' => (string) ($faultLevel['label'] ?? ''),
            'fault_severity_rank' => (int) ($faultLevel['severity_rank'] ?? 0),
            'sanction_level_id' => (int) ($sanctionLevel['id'] ?? 0),
            'sanction_level_code' => (string) ($sanctionLevel['code'] ?? ''),
            'sanction_level_label' => (string) ($sanctionLevel['label'] ?? ''),
            'sanction_severity_rank' => (int) ($sanctionLevel['severity_rank'] ?? 0),
            'sanction_category' => (string) ($sanctionLevel['category'] ?? 'disciplinary'),
            'sanction_disables_platform_access' => !empty($sanctionLevel['disables_platform_access']),
        ];
    }

    private function buildClosureDecisionMessage(array $decisionData, array $message): array
    {
        $lines = [
            '<p><strong>Décision disciplinaire retenue</strong></p>',
            '<ul>',
            '<li><strong>Niveau de faute :</strong> ' . htmlspecialchars((string) ($decisionData['fault_level_label'] ?? 'Non renseigné'), ENT_QUOTES, 'UTF-8') . '</li>',
            '<li><strong>Niveau de sanction :</strong> ' . htmlspecialchars((string) ($decisionData['sanction_level_label'] ?? 'Non renseigné'), ENT_QUOTES, 'UTF-8') . '</li>',
            '<li><strong>Accès plateforme :</strong> ' . (!empty($decisionData['sanction_disables_platform_access']) ? 'désactivés automatiquement' : 'maintenus') . '</li>',
            '</ul>',
        ];

        if (trim((string) ($message['html'] ?? '')) !== '') {
            $lines[] = '<div style="margin-top:14px;">' . (string) $message['html'] . '</div>';
        }

        $textParts = [
            'Décision disciplinaire retenue',
            'Niveau de faute : ' . (string) ($decisionData['fault_level_label'] ?? 'Non renseigné'),
            'Niveau de sanction : ' . (string) ($decisionData['sanction_level_label'] ?? 'Non renseigné'),
            'Accès plateforme : ' . (!empty($decisionData['sanction_disables_platform_access']) ? 'désactivés automatiquement' : 'maintenus'),
        ];
        if (trim((string) ($message['text'] ?? '')) !== '') {
            $textParts[] = trim((string) $message['text']);
        }

        return [
            'html' => implode('', $lines),
            'text' => implode("\n", $textParts),
        ];
    }

    private function deactivateUserPlatformAccess(int $userId, array $request, array $decisionData): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare('UPDATE user_devis_banamur SET active = 0 WHERE id = :id');
        $stmt->execute(['id' => $userId]);

        \AuditTrail::log('rh_explanation_access_disabled', [
            'module' => 'rh',
            'action' => 'disable_platform_access_after_sanction',
            'entity_type' => 'rh_explanation_request',
            'entity_id' => (string) ($request['id'] ?? 0),
            'entity_label' => (string) ($request['request_ref'] ?? ''),
            'details' => [
                'target_user_id' => $userId,
                'sanction_level_label' => (string) ($decisionData['sanction_level_label'] ?? ''),
            ],
        ], $this->pdo);
    }

    private function buildDisciplineSummaryLabel(array $request): string
    {
        if (!empty($request['discipline_is_archived'])) {
            return 'Sanction archivée hors dossier actif (rétention de 6 mois expirée).';
        }

        $faultLabel = trim((string) ($request['fault_level_label'] ?? ''));
        $sanctionLabel = trim((string) ($request['sanction_level_label'] ?? ''));
        if ($faultLabel === '' && $sanctionLabel === '') {
            return '';
        }

        if ($faultLabel !== '' && $sanctionLabel !== '') {
            return $faultLabel . ' • ' . $sanctionLabel;
        }

        return $faultLabel !== '' ? $faultLabel : $sanctionLabel;
    }

    private function getHierarchyForService(int $serviceId): ?array
    {
        if ($serviceId <= 0) {
            return null;
        }

        foreach ($this->getServiceHierarchyRows() as $row) {
            if ((int) ($row['service_id'] ?? 0) === $serviceId) {
                return $row;
            }
        }

        return null;
    }

    private function assertActorCanCreateRequestForTarget(array $actor, array $target, array $targetUser): void
    {
        if ((string) ($actor['role'] ?? '') !== 'employee') {
            return;
        }

        $scope = $this->getEmployeeSenderScope($actor);
        if (empty($scope['can_send'])) {
            throw new RuntimeException((string) ($scope['reason'] ?? 'Vous n’êtes pas autorisé à envoyer une demande d’explications depuis l’espace employé.'));
        }

        $actorUserId = max(0, (int) ($actor['user_id'] ?? 0));
        if ($actorUserId > 0 && $actorUserId === (int) ($targetUser['id'] ?? 0)) {
            throw new RuntimeException('Vous ne pouvez pas vous adresser une demande d’explications à vous-même.');
        }

        if (empty($scope['is_global_scope'])) {
            $actorServiceId = max(0, (int) ($scope['service_id'] ?? 0));
            $targetServiceId = max(0, (int) ($target['service_id'] ?? 0));
            if ($actorServiceId <= 0 || $targetServiceId <= 0 || $actorServiceId !== $targetServiceId) {
                throw new RuntimeException('Vous ne pouvez envoyer une demande d’explications qu’aux collaborateurs de votre service.');
            }
        }
    }

    private function buildRequestRelativeUrlForUser(int $userId, array $request): string
    {
        $userId = max(0, $userId);
        if ($userId > 0) {
            $user = $this->getUserById($userId);
            if ($user && !empty($user['is_rh'])) {
                return '/rh/demandes_explication_rh.php?id=' . (int) ($request['id'] ?? 0);
            }
            if ($userId === (int) ($request['target_user_id'] ?? 0) || $userId === (int) ($request['created_by_user_id'] ?? 0)) {
                return '/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0);
            }
        }

        return '/rh/demandes_explication_rh.php?id=' . (int) ($request['id'] ?? 0);
    }

    private function resolveRecipients(int $serviceId, bool $isCadreDirect, array $targetUser): array
    {
        $settings = $this->getSettings();
        $hierarchyRows = $this->getServiceHierarchyRows();
        $hierarchy = null;
        foreach ($hierarchyRows as $row) {
            if ((int) ($row['service_id'] ?? 0) === $serviceId) {
                $hierarchy = $row;
                break;
            }
        }

        $dgUserId = max(0, (int) ($settings['dg_user_id'] ?? 0));
        $directManagerUserId = max(0, (int) ($hierarchy['direct_manager_user_id'] ?? 0));
        $departmentHeadUserId = max(0, (int) ($hierarchy['department_head_user_id'] ?? 0));

        $ccUsers = [];
        if ($dgUserId > 0) {
            $dgUser = $this->getUserById($dgUserId);
            if ($dgUser) {
                $ccUsers[$dgUser['id']] = $dgUser;
            }
        }

        if (!$isCadreDirect) {
            foreach ($this->getRhUsers() as $rhUser) {
                $ccUsers[(int) $rhUser['id']] = $rhUser;
            }
            if ($directManagerUserId > 0) {
                $directUser = $this->getUserById($directManagerUserId);
                if ($directUser) {
                    $ccUsers[$directUser['id']] = $directUser;
                }
            }
            if ($departmentHeadUserId > 0) {
                $headUser = $this->getUserById($departmentHeadUserId);
                if ($headUser) {
                    $ccUsers[$headUser['id']] = $headUser;
                }
            }
        }

        unset($ccUsers[(int) ($targetUser['id'] ?? 0)]);

        $ccEmails = [];
        foreach ($ccUsers as $ccUser) {
            $email = trim((string) ($ccUser['email'] ?? ''));
            if ($email !== '') {
                $ccEmails[] = $email;
            }
        }

        return [
            'dg_user_id' => $dgUserId,
            'direct_manager_user_id' => $directManagerUserId,
            'department_head_user_id' => $departmentHeadUserId,
            'cc_users' => array_values($ccUsers),
            'cc_emails' => array_values(array_unique($ccEmails)),
            'watcher_user_ids' => array_values(array_map(static function (array $user): int {
                return (int) ($user['id'] ?? 0);
            }, array_values($ccUsers))),
        ];
    }

    private function sendCreationCommunications(array $request, array $targetUser, array $recipients): void
    {
        $subject = '[Demande d’explications ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '] ' . (string) ($request['subject'] ?? '');
        $employeeUrl = $this->absoluteUrl('/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0));
        $rhUrl = $this->absoluteUrl('/rh/demandes_explication_rh.php?id=' . (int) ($request['id'] ?? 0));
        $dueLabel = $this->formatDateTimeFr((string) ($request['due_at'] ?? ''));

        $pdfAttachment = $this->getPdfAttachment((int) ($request['id'] ?? 0));
        $html = $this->buildEmailLayout(
            'Demande d’explications',
            'Un document RH officiel vous a été adressé. Le PDF professionnel est joint à ce message pour impression, signature et archivage.',
            [
                ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                ['label' => 'Objet', 'value' => (string) ($request['subject'] ?? '')],
                ['label' => 'Réponse attendue', 'value' => $dueLabel],
                ['label' => 'Espace RH', 'value' => 'Suivi actif'],
            ],
            '<p>Bonjour ' . htmlspecialchars((string) ($request['target_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Veuillez prendre connaissance de la demande d’explications ci-dessous. Le document PDF joint reprend la version formalisée de la demande avec zones de signature RH et employé.</p>'
                . '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">' . (string) ($request['request_html'] ?? '') . '</div>',
            'Accéder à mon dossier',
            $employeeUrl,
            'Le PDF joint peut être imprimé, signé puis archivé. Une copie du dossier est également disponible côté RH.',
            'Direction des ressources humaines BANAMUR'
        );
        $plain = $this->buildPlainEmailText('Demande d’explications', [
            'Référence: ' . (string) ($request['request_ref'] ?? ''),
            'Objet: ' . (string) ($request['subject'] ?? ''),
            'Réponse attendue avant le ' . $dueLabel,
            'Accès au dossier: ' . $employeeUrl,
        ]);

        (new \NotificationEmailChannelService())->send(
            (string) ($request['target_email'] ?? ''),
            (string) ($request['target_name'] ?? ''),
            $subject,
            $html,
            $plain,
            [
                'from_name' => 'Direction des ressources humaines BANAMUR',
                'cc' => $recipients['cc_users'],
                'attachments' => $pdfAttachment ? [$pdfAttachment] : [],
            ]
        );

        $message = 'Une demande d’explications vous a été adressée. Réponse attendue avant le ' . $dueLabel . '.';
        $this->createSystemNotification('rh_explanation_created', 'target', $subject, $message, '/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0), null, (int) ($targetUser['id'] ?? 0), [
            'request_id' => (int) ($request['id'] ?? 0),
            'request_ref' => (string) ($request['request_ref'] ?? ''),
        ]);

        foreach ((array) ($recipients['watcher_user_ids'] ?? []) as $watcherUserId) {
            $watcherUserId = (int) $watcherUserId;
            if ($watcherUserId <= 0) {
                continue;
            }
            $this->createSystemNotification('rh_explanation_created', 'watcher', $subject, 'Demande adressée à ' . (string) ($request['target_name'] ?? '') . '.', '/rh/demandes_explication_rh.php?id=' . (int) ($request['id'] ?? 0), null, $watcherUserId, [
                'request_id' => (int) ($request['id'] ?? 0),
                'request_ref' => (string) ($request['request_ref'] ?? ''),
            ]);
        }

        $creatorUserId = max(0, (int) ($request['created_by_user_id'] ?? 0));
        if ($creatorUserId > 0) {
            $this->createSystemNotification('rh_explanation_created', 'creator', $subject, 'La demande d’explications a été envoyée.', $this->buildRequestRelativeUrlForUser($creatorUserId, $request), null, $creatorUserId, [
                'request_id' => (int) ($request['id'] ?? 0),
                'request_ref' => (string) ($request['request_ref'] ?? ''),
            ]);
        }

        \AuditTrail::log('rh_explanation_request_created', [
            'module' => 'rh',
            'action' => 'create_explanation_request',
            'entity_type' => 'rh_explanation_request',
            'entity_id' => (string) ($request['id'] ?? 0),
            'entity_label' => (string) ($request['request_ref'] ?? ''),
            'details' => [
                'target_email' => (string) ($request['target_email'] ?? ''),
                'rh_url' => $rhUrl,
            ],
        ], $this->pdo);
    }

    private function sendResponseCommunications(array $request, string $employeeName): void
    {
        $subject = '[Réponse ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '] ' . (string) ($request['subject'] ?? '');
        $creatorUserId = max(0, (int) ($request['created_by_user_id'] ?? 0));
        $creatorUrl = $this->absoluteUrl($this->buildRequestRelativeUrlForUser($creatorUserId, $request));
        $latestMessage = $this->getLatestMessage((int) ($request['id'] ?? 0), false);
        $pdfAttachment = $this->getPdfAttachment((int) ($request['id'] ?? 0));
        $responder = $employeeName !== '' ? $employeeName : (string) ($request['target_name'] ?? 'Collaborateur');
        $html = $this->buildEmailLayout(
            'Réponse reçue',
            'Le collaborateur a transmis ses explications. La version consolidée du dossier est jointe au format PDF.',
            [
                ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                ['label' => 'Objet', 'value' => (string) ($request['subject'] ?? '')],
                ['label' => 'Auteur réponse', 'value' => $responder],
                ['label' => 'Statut', 'value' => (string) ($request['status_label'] ?? '')],
            ],
            '<p>Une réponse a été déposée par <strong>' . htmlspecialchars($responder, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">' . (string) ($latestMessage['body_html'] ?? '') . '</div>',
            'Ouvrir le dossier',
            $creatorUrl,
            'Le PDF joint contient le dossier à jour avec la réponse employé et les zones de signature.',
            'Direction des ressources humaines BANAMUR'
        );
        $plain = $this->buildPlainEmailText('Réponse reçue', [
            'Référence: ' . (string) ($request['request_ref'] ?? ''),
            'Objet: ' . (string) ($request['subject'] ?? ''),
            'Réponse transmise par: ' . $responder,
            'Accès au dossier: ' . $creatorUrl,
        ]);

        $creator = $this->getUserById($creatorUserId);
        if ($creator && trim((string) ($creator['email'] ?? '')) !== '') {
            (new \NotificationEmailChannelService())->send((string) $creator['email'], (string) $creator['name'], $subject, $html, $plain, [
                'from_name' => 'Direction des ressources humaines BANAMUR',
                'cc' => $this->buildCcUsersFromRequest($request),
                'attachments' => $pdfAttachment ? [$pdfAttachment] : [],
            ]);
        }

        $notifyUserIds = array_unique(array_merge(
            [(int) ($request['created_by_user_id'] ?? 0)],
            array_map('intval', (array) ($request['cc_user_ids'] ?? []))
        ));
        foreach ($notifyUserIds as $userId) {
            if ($userId <= 0) {
                continue;
            }
            $this->createSystemNotification('rh_explanation_answered', 'watcher', $subject, 'Une réponse employé a été reçue.', $this->buildRequestRelativeUrlForUser($userId, $request), null, $userId, [
                'request_id' => (int) ($request['id'] ?? 0),
                'request_ref' => (string) ($request['request_ref'] ?? ''),
            ]);
        }
    }

    private function sendFollowUpCommunications(array $request, string $actorName, string $messageHtml): void
    {
        $subject = '[Complément demandé ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '] ' . (string) ($request['subject'] ?? '');
        $employeeUrl = $this->absoluteUrl('/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0));
        $dueLabel = $this->formatDateTimeFr((string) ($request['due_at'] ?? ''));
        $pdfAttachment = $this->getPdfAttachment((int) ($request['id'] ?? 0));
        $html = $this->buildEmailLayout(
            'Complément de réponse demandé',
            'Le dossier RH reste ouvert. Un complément formel est demandé et le PDF joint reprend l’ensemble du dossier à ce stade.',
            [
                ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                ['label' => 'Objet', 'value' => (string) ($request['subject'] ?? '')],
                ['label' => 'Nouvelle échéance', 'value' => $dueLabel],
                ['label' => 'Émis par', 'value' => $actorName],
            ],
            '<p>Un complément de réponse est demandé par <strong>' . htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>'
                . '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">' . $messageHtml . '</div>',
            'Répondre au complément',
            $employeeUrl,
            'Merci d’utiliser l’espace employé pour déposer votre complément dans les délais.',
            'Direction des ressources humaines BANAMUR'
        );
        $plain = $this->buildPlainEmailText('Complément de réponse demandé', [
            'Référence: ' . (string) ($request['request_ref'] ?? ''),
            'Nouvelle échéance: ' . $dueLabel,
            'Accès au dossier: ' . $employeeUrl,
        ]);

        (new \NotificationEmailChannelService())->send((string) ($request['target_email'] ?? ''), (string) ($request['target_name'] ?? ''), $subject, $html, $plain, [
            'from_name' => 'Direction des ressources humaines BANAMUR',
            'cc' => $this->buildCcUsersFromRequest($request),
            'attachments' => $pdfAttachment ? [$pdfAttachment] : [],
        ]);

        $this->createSystemNotification('rh_explanation_followup', 'target', $subject, 'Un complément de réponse vous est demandé.', '/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0), null, (int) ($request['target_user_id'] ?? 0), [
            'request_id' => (int) ($request['id'] ?? 0),
        ]);
    }

    private function sendRectificationCommunications(array $request, string $employeeName, string $messageHtml): void
    {
        $subject = '[Rectification demandée ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '] ' . (string) ($request['subject'] ?? '');
        $requester = $employeeName !== '' ? $employeeName : (string) ($request['target_name'] ?? 'Collaborateur');
        $pdfAttachment = $this->getPdfAttachment((int) ($request['id'] ?? 0));

        $notifyUserIds = array_unique(array_merge(
            [(int) ($request['created_by_user_id'] ?? 0)],
            array_map('intval', (array) ($request['cc_user_ids'] ?? []))
        ));

        foreach ($notifyUserIds as $userId) {
            if ($userId <= 0) {
                continue;
            }

            $userUrl = $this->absoluteUrl($this->buildRequestRelativeUrlForUser($userId, $request));
            $html = $this->buildEmailLayout(
                'Rectification demandée',
                'Le collaborateur signale une erreur matérielle sur son dossier et sollicite une correction.',
                [
                    ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                    ['label' => 'Objet', 'value' => (string) ($request['subject'] ?? '')],
                    ['label' => 'Demandeur', 'value' => $requester],
                    ['label' => 'Statut', 'value' => (string) ($request['status_label'] ?? '')],
                ],
                '<p><strong>' . htmlspecialchars($requester, ENT_QUOTES, 'UTF-8') . '</strong> demande une rectification des données du dossier.</p>'
                    . '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">' . $messageHtml . '</div>',
                'Ouvrir le dossier',
                $userUrl,
                'Vérifiez l’erreur signalée, corrigez si nécessaire et tracez votre réponse dans le dossier.',
                'Direction des ressources humaines BANAMUR'
            );
            $plain = $this->buildPlainEmailText('Rectification demandée', [
                'Référence: ' . (string) ($request['request_ref'] ?? ''),
                'Objet: ' . (string) ($request['subject'] ?? ''),
                'Demandeur: ' . $requester,
                'Accès au dossier: ' . $userUrl,
            ]);

            $user = $this->getUserById($userId);
            if ($user && trim((string) ($user['email'] ?? '')) !== '') {
                (new \NotificationEmailChannelService())->send((string) $user['email'], (string) $user['name'], $subject, $html, $plain, [
                    'from_name' => 'Direction des ressources humaines BANAMUR',
                    'attachments' => $pdfAttachment ? [$pdfAttachment] : [],
                ]);
            }

            $this->createSystemNotification('rh_explanation_rectification', 'watcher', $subject, 'Le salarié a demandé une rectification de son dossier.', $this->buildRequestRelativeUrlForUser($userId, $request), null, $userId, [
                'request_id' => (int) ($request['id'] ?? 0),
                'request_ref' => (string) ($request['request_ref'] ?? ''),
            ]);
        }
    }

    private function sendClosureCommunications(array $request, string $actorName, string $messageHtml): void
    {
        $subject = '[Clôture ' . (string) ($request['request_ref'] ?? ('#' . (int) ($request['id'] ?? 0))) . '] ' . (string) ($request['subject'] ?? '');
        $employeeUrl = $this->absoluteUrl('/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0));
        $pdfAttachment = $this->getPdfAttachment((int) ($request['id'] ?? 0));
        $body = '<p>La demande d’explications a été clôturée par <strong>' . htmlspecialchars($actorName, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
        if (trim((string) ($request['discipline_summary'] ?? '')) !== '') {
            $body .= '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">'
                . '<p style="margin:0 0 8px 0;"><strong>Qualification retenue :</strong> ' . htmlspecialchars((string) ($request['fault_level_label'] ?? 'Non renseigné'), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p style="margin:0 0 8px 0;"><strong>Sanction retenue :</strong> ' . htmlspecialchars((string) ($request['sanction_level_label'] ?? 'Non renseigné'), ENT_QUOTES, 'UTF-8') . '</p>'
                . '<p style="margin:0;"><strong>Accès à la plateforme :</strong> ' . (!empty($request['sanction_disables_platform_access']) ? 'désactivés automatiquement' : 'maintenus') . '</p>'
                . '</div>';
        }
        if (trim($messageHtml) !== '') {
            $body .= '<div style="margin-top:18px;padding:18px;border:1px solid #e2e8f0;border-radius:18px;background:#f8fafc;">' . $messageHtml . '</div>';
        }
        $html = $this->buildEmailLayout(
            'Clôture du dossier',
            'Le traitement RH du dossier est terminé. Le PDF joint constitue la version de référence pour impression et archivage.',
            [
                ['label' => 'Référence', 'value' => (string) ($request['request_ref'] ?? '')],
                ['label' => 'Faute retenue', 'value' => (string) ($request['fault_level_label'] ?? 'Non renseignée')],
                ['label' => 'Sanction', 'value' => (string) ($request['sanction_level_label'] ?? 'Non renseignée')],
                ['label' => 'Accès plateforme', 'value' => !empty($request['sanction_disables_platform_access']) ? 'Désactivés' : 'Maintenus'],
            ],
            $body,
            'Consulter le dossier',
            $employeeUrl,
            !empty($request['sanction_disables_platform_access']) ? 'Conservez le PDF joint comme pièce de référence. Vos accès à la plateforme ont été suspendus par la décision RH.' : 'Conservez le PDF joint comme pièce de référence du dossier.',
            'Direction des ressources humaines BANAMUR'
        );
        $plain = $this->buildPlainEmailText('Clôture du dossier', [
            'Référence: ' . (string) ($request['request_ref'] ?? ''),
            'Objet: ' . (string) ($request['subject'] ?? ''),
            'Niveau de faute: ' . (string) ($request['fault_level_label'] ?? 'Non renseigné'),
            'Niveau de sanction: ' . (string) ($request['sanction_level_label'] ?? 'Non renseigné'),
            'Accès plateforme: ' . (!empty($request['sanction_disables_platform_access']) ? 'désactivés automatiquement' : 'maintenus'),
            'Consulter le dossier: ' . $employeeUrl,
        ]);

        (new \NotificationEmailChannelService())->send((string) ($request['target_email'] ?? ''), (string) ($request['target_name'] ?? ''), $subject, $html, $plain, [
            'from_name' => 'Direction des ressources humaines BANAMUR',
            'cc' => $this->buildCcUsersFromRequest($request),
            'attachments' => $pdfAttachment ? [$pdfAttachment] : [],
        ]);

        $this->createSystemNotification('rh_explanation_closed', 'target', $subject, 'La demande d’explications a été clôturée.', '/employe/mes_explications_employe.php?id=' . (int) ($request['id'] ?? 0), null, (int) ($request['target_user_id'] ?? 0), [
            'request_id' => (int) ($request['id'] ?? 0),
        ]);
    }

    private function buildCcUsersFromRequest(array $request): array
    {
        $out = [];
        foreach ((array) ($request['cc_user_ids'] ?? []) as $userId) {
            $user = $this->getUserById((int) $userId);
            if ($user && trim((string) ($user['email'] ?? '')) !== '') {
                $out[] = $user;
            }
        }
        return $out;
    }

    private function getPdfAttachment(int $requestId): ?array
    {
        $pdf = $this->getRequestPdfDocument($requestId);
        if (!$pdf || empty($pdf['content']) || empty($pdf['fileName'])) {
            return null;
        }

        return [
            'name' => (string) $pdf['fileName'],
            'content' => (string) $pdf['content'],
            'type' => 'application/pdf',
        ];
    }

    private function buildEmailLayout(string $eyebrow, string $subtitle, array $cards, string $bodyHtml, string $actionLabel, string $actionUrl, string $footerNote, string $senderLabel): string
    {
        $cardsHtml = '';
        foreach ($cards as $card) {
            $cardsHtml .= '<td width="25%" style="padding:0 6px 12px 6px;vertical-align:top;">'
                . '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:16px;padding:16px;height:100%;">'
                . '<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.14em;color:#64748b;font-weight:700;">' . htmlspecialchars((string) ($card['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="margin-top:8px;font-size:16px;line-height:1.45;font-weight:700;color:#0f172a;">' . htmlspecialchars((string) ($card['value'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>'
                . '</div>'
                . '</td>';
        }

        $cardsBlock = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 12px;"><tr>' . $cardsHtml . '</tr></table>';
        $fullBodyHtml = '<div style="margin:0 0 18px; font-size:13px; line-height:1.7; color:#475569;"><strong style="color:#0f172a;">' . htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8') . '</strong></div>'
            . '<div style="border:1px solid #e2e8f0;border-radius:18px;background:#ffffff;padding:22px;">' . $bodyHtml . '</div>';

        return \Mailer::buildFineaNotificationEmail($eyebrow, $fullBodyHtml, $actionUrl, [
            'repere_title' => 'Repere',
            'repere_text' => $subtitle,
            'cards_html' => $cardsBlock,
            'button_label' => $actionLabel,
            'footer_text' => $footerNote,
        ]);
    }

    private function buildPlainEmailText(string $title, array $lines): string
    {
        return $title . "\n\n" . implode("\n", $lines) . "\n";
    }

    private function getLatestMessage(int $requestId, bool $includeInternal = false): ?array
    {
        $messages = $this->listMessages($requestId, $includeInternal);
        if (!$messages) {
            return null;
        }
        return $messages[count($messages) - 1];
    }

    private function addMessage(int $requestId, string $authorRole, int $authorUserId, string $authorName, string $messageKind, string $bodyHtml, string $bodyText, bool $isInternal = false): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_messages (request_id, author_role, author_user_id, author_name, message_kind, body_html, body_text, is_internal, created_at) VALUES (:request_id, :author_role, :author_user_id, :author_name, :message_kind, :body_html, :body_text, :is_internal, NOW())');
        $stmt->execute([
            'request_id' => $requestId,
            'author_role' => $authorRole,
            'author_user_id' => $authorUserId > 0 ? $authorUserId : null,
            'author_name' => $authorName,
            'message_kind' => $messageKind,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'is_internal' => $isInternal ? 1 : 0,
        ]);
    }

    private function sanitizeRichHtml(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'text' => ''];
        }

        $purifier = self::getPurifier();
        $cleanHtml = trim((string) $purifier->purify($html));
        $text = trim(html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES, 'UTF-8'));

        return [
            'html' => $cleanHtml,
            'text' => preg_replace('/\s+/u', ' ', $text) ?? $text,
        ];
    }

    private function buildRequestBodyFromForm(array $data, array $target, int $dueDays): array
    {
        $settings = $this->getSettings();

        $contextSummary = $this->normalizeTextareaText((string) ($data['context_summary'] ?? ''));
        $observedFacts = $this->normalizeTextareaText((string) ($data['observed_facts'] ?? ''));
        $expectedResponse = $this->normalizeTextareaText((string) ($data['expected_response'] ?? ''));
        $supportingElements = $this->normalizeTextareaText((string) ($data['supporting_elements'] ?? ''));
        $incidentDate = trim((string) ($data['incident_date'] ?? ''));
        $incidentPlace = trim((string) ($data['incident_place'] ?? ''));

        if ($observedFacts === '' && trim((string) ($data['request_html'] ?? '')) !== '') {
            return $this->sanitizeRichHtml((string) ($data['request_html'] ?? ''));
        }

        if ($observedFacts === '') {
            return ['html' => '', 'text' => ''];
        }

        $parts = [];

        $intro = $this->replaceTemplateVariables((string) ($settings['template_intro'] ?? ''), $target, $dueDays);
        if ($intro !== '') {
            $parts[] = '<p>' . nl2br(htmlspecialchars($intro, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        $metaLines = [];
        if ($incidentDate !== '') {
            $metaLines[] = '<strong>Date ou période concernée :</strong> ' . htmlspecialchars($incidentDate, ENT_QUOTES, 'UTF-8');
        }
        if ($incidentPlace !== '') {
            $metaLines[] = '<strong>Lieu / cadre concerné :</strong> ' . htmlspecialchars($incidentPlace, ENT_QUOTES, 'UTF-8');
        }
        if ($metaLines) {
            $parts[] = '<p>' . implode('<br>', $metaLines) . '</p>';
        }

        $parts[] = $this->buildGeneratedSectionHtml((string) ($settings['template_context_label'] ?? ''), $contextSummary);
        $parts[] = $this->buildGeneratedSectionHtml((string) ($settings['template_facts_label'] ?? ''), $observedFacts, true);
        $parts[] = $this->buildGeneratedSectionHtml((string) ($settings['template_expectations_label'] ?? ''), $expectedResponse, true);
        $parts[] = $this->buildGeneratedSectionHtml((string) ($settings['template_supporting_label'] ?? ''), $supportingElements);

        $closing = $this->replaceTemplateVariables((string) ($settings['template_closing'] ?? ''), $target, $dueDays);
        if ($closing !== '') {
            $parts[] = '<p>' . nl2br(htmlspecialchars($closing, ENT_QUOTES, 'UTF-8')) . '</p>';
        }

        return $this->sanitizeRichHtml(implode("\n", array_filter($parts)));
    }

    private function buildGeneratedSectionHtml(string $title, string $content, bool $required = false): string
    {
        $title = trim($title);
        $content = trim($content);

        if ($content === '') {
            return $required ? '' : '';
        }

        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
        $items = [];
        $paragraphs = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(?:[-*]|\d+[.)])\s+(.+)$/u', $line, $matches)) {
                $items[] = '<li>' . htmlspecialchars(trim((string) $matches[1]), ENT_QUOTES, 'UTF-8') . '</li>';
            } else {
                $paragraphs[] = '<p>' . nl2br(htmlspecialchars($line, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
        }

        $body = '';
        if ($paragraphs) {
            $body .= implode('', $paragraphs);
        }
        if ($items) {
            $body .= '<ul>' . implode('', $items) . '</ul>';
        }

        if ($body === '') {
            return '';
        }

        return '<section><p><strong>' . htmlspecialchars($title !== '' ? $title : 'Détail', ENT_QUOTES, 'UTF-8') . '</strong></p>' . $body . '</section>';
    }

    private function normalizeTextareaText(string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
        return preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;
    }

    private function normalizeSettingText(string $value): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $value));
    }

    private function normalizeSettingLabel(string $value, string $fallback): string
    {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }

    private function replaceTemplateVariables(string $value, array $target, int $dueDays): string
    {
        $replacements = [
            '{collaborateur}' => (string) ($target['full_name'] ?? $target['target_name'] ?? ''),
            '{service}' => (string) ($target['service_label'] ?? ''),
            '{fonction}' => (string) ($target['fonction_label'] ?? ''),
            '{delai_jours}' => (string) $dueDays,
        ];

        return strtr(trim($value), $replacements);
    }

    private static function getPurifier(): \HTMLPurifier
    {
        if (self::$purifier instanceof \HTMLPurifier) {
            return self::$purifier;
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,strong,b,em,i,u,ul,ol,li,blockquote,a[href|target|rel]');
        $config->set('AutoFormat.AutoParagraph', true);
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('HTML.TargetBlank', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        self::$purifier = new \HTMLPurifier($config);
        return self::$purifier;
    }

    private function addBusinessDays(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        $days = max(1, $days);
        $current = $start;
        $added = 0;
        while ($added < $days) {
            $current = $current->modify('+1 day');
            $dayOfWeek = (int) $current->format('N');
            if ($dayOfWeek >= 6) {
                continue;
            }
            $added++;
        }
        return $current;
    }

    private function addBusinessHours(DateTimeImmutable $start, int $hours): DateTimeImmutable
    {
        $hours = max(1, $hours);
        $current = $start;
        $holidaySet = $this->loadHolidayDateSet($start, $start->modify('+21 days'));
        $added = 0;
        while ($added < $hours) {
            $current = $current->modify('+1 hour');
            if (!$this->isBusinessDateTime($current, $holidaySet)) {
                continue;
            }
            $added++;
        }
        return $current;
    }

    private function isBusinessDateTime(DateTimeImmutable $dateTime, array $holidaySet = []): bool
    {
        $dayOfWeek = (int) $dateTime->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        $ymd = $dateTime->format('Y-m-d');
        return !isset($holidaySet[$ymd]);
    }

    private function loadHolidayDateSet(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $startDate = $start->format('Y-m-d');
        $endDate = $end->format('Y-m-d');
        if ($endDate < $startDate) {
            $tmp = $startDate;
            $startDate = $endDate;
            $endDate = $tmp;
        }

        $stmt = $this->pdo->prepare('SELECT holiday_date FROM rh_holidays WHERE holiday_date BETWEEN :start_date AND :end_date');
        $stmt->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $holidaySet = [];
        foreach ($rows as $row) {
            $date = trim((string) ($row['holiday_date'] ?? ''));
            if ($date !== '') {
                $holidaySet[$date] = true;
            }
        }

        return $holidaySet;
    }

    private function computeDisciplineRetentionUntil(DateTimeImmutable $closedAt): DateTimeImmutable
    {
        return $closedAt->modify('+6 months');
    }

    private function buildSanctionLockReason(array $request): string
    {
        $ackAt = trim((string) ($request['employee_read_ack_at'] ?? ''));
        if ($ackAt === '') {
            return 'Accusé de lecture numérique non encore enregistré.';
        }

        $availableAt = trim((string) ($request['sanction_available_at'] ?? ''));
        if ($availableAt === '') {
            return 'Date d’ouverture de la fenêtre disciplinaire indisponible.';
        }

        $availableTs = strtotime($availableAt);
        if ($availableTs === false) {
            return 'Date d’ouverture de la fenêtre disciplinaire invalide.';
        }

        if ($availableTs > time()) {
            return 'Sanction autorisée à partir du ' . $this->formatDateTimeFr($availableAt) . ' après 72h ouvrables hors week-ends et jours fériés RH.';
        }

        return '';
    }

    private function resolveActorIp(array $actor): string
    {
        $ip = trim((string) ($actor['ip'] ?? ''));
        if ($ip !== '') {
            return substr($ip, 0, 64);
        }

        $serverKeys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($serverKeys as $serverKey) {
            $value = trim((string) ($_SERVER[$serverKey] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($serverKey === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_filter(array_map('trim', explode(',', $value)));
                $value = (string) ($parts[0] ?? '');
            }
            if ($value !== '') {
                return substr($value, 0, 64);
            }
        }

        return '';
    }

    private function logDisciplineEvent(string $action, array $request, array $actor, array $details = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO logs_discipline (request_id, request_ref, action_key, status_before, status_after, actor_user_id, actor_name, actor_role, actor_ip, details_json, created_at) VALUES (:request_id, :request_ref, :action_key, :status_before, :status_after, :actor_user_id, :actor_name, :actor_role, :actor_ip, :details_json, NOW())');
        $stmt->execute([
            'request_id' => (int) ($request['id'] ?? 0) ?: null,
            'request_ref' => (string) ($request['request_ref'] ?? ''),
            'action_key' => $action,
            'status_before' => (string) ($details['status_before'] ?? ''),
            'status_after' => (string) ($details['status_after'] ?? ''),
            'actor_user_id' => max(0, (int) ($actor['user_id'] ?? 0)) ?: null,
            'actor_name' => trim((string) ($actor['name'] ?? '')),
            'actor_role' => trim((string) ($actor['role'] ?? 'user')),
            'actor_ip' => $this->resolveActorIp($actor),
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function runDisciplineMaintenance(): void
    {
        $this->backfillEmployeeReadAcknowledgements();
        $this->sendUnreadAcknowledgementAlerts();
        $this->archiveExpiredDisciplineRecords();
    }

    private function backfillEmployeeReadAcknowledgements(): void
    {
        $stmt = $this->pdo->query("SELECT * FROM rh_explanation_requests WHERE employee_read_ack_at IS NULL AND (employee_signed_at IS NOT NULL OR answered_at IS NOT NULL OR employee_first_read_at IS NOT NULL OR EXISTS (SELECT 1 FROM rh_explanation_messages m WHERE m.request_id = rh_explanation_requests.id AND m.author_role = 'employee' AND m.message_kind IN ('response','rectification_request')))");
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $request = $this->hydrateRequest($row);
            $employeeActivityDates = $this->getEmployeeActivityDates((int) ($request['id'] ?? 0));
            $candidateDates = array_values(array_filter([
                trim((string) ($request['employee_first_read_at'] ?? '')),
                trim((string) ($request['employee_signed_at'] ?? '')),
                trim((string) ($request['answered_at'] ?? '')),
                ...$employeeActivityDates,
            ], static function (string $value): bool {
                return $value !== '' && strtotime($value) !== false;
            }));

            if ($candidateDates === []) {
                continue;
            }

            usort($candidateDates, static function (string $left, string $right): int {
                return strtotime($left) <=> strtotime($right);
            });

            $readAt = $candidateDates[0];
            $sanctionAvailableAt = $this->addBusinessHours(new DateTimeImmutable($readAt), 72)->format('Y-m-d H:i:s');
            $this->pdo->prepare('UPDATE rh_explanation_requests
                SET employee_first_read_at = COALESCE(employee_first_read_at, :read_at),
                    employee_read_ack_at = COALESCE(employee_read_ack_at, :read_at),
                    employee_read_ack_user_id = COALESCE(employee_read_ack_user_id, :user_id),
                    sanction_available_at = COALESCE(sanction_available_at, :sanction_available_at),
                    updated_at = NOW()
                WHERE id = :id LIMIT 1')->execute([
                'read_at' => $readAt,
                'user_id' => (int) ($request['target_user_id'] ?? 0) > 0 ? (int) ($request['target_user_id'] ?? 0) : null,
                'sanction_available_at' => $sanctionAvailableAt,
                'id' => (int) ($request['id'] ?? 0),
            ]);

            $request['employee_first_read_at'] = $request['employee_first_read_at'] ?: $readAt;
            $request['employee_read_ack_at'] = $readAt;
            $request['employee_read_ack_user_id'] = (int) ($request['target_user_id'] ?? 0);
            $request['sanction_available_at'] = $sanctionAvailableAt;

            $this->logDisciplineEvent('read_ack_backfilled', $request, [
                'user_id' => (int) ($request['target_user_id'] ?? 0),
                'name' => (string) ($request['target_name'] ?? 'SYSTEM'),
                'role' => 'system',
                'ip' => '',
            ], [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => (string) ($request['status'] ?? ''),
                'read_ack_at' => $readAt,
                'sanction_available_at' => $sanctionAvailableAt,
                'source' => 'legacy_employee_activity',
            ]);
        }
    }

    private function getEmployeeActivityDates(int $requestId): array
    {
        if ($requestId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT created_at FROM rh_explanation_messages WHERE request_id = :request_id AND author_role = 'employee' AND message_kind IN ('response', 'rectification_request') ORDER BY created_at ASC, id ASC");
        $stmt->execute(['request_id' => $requestId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_values(array_filter(array_map(static function (array $row): string {
            return trim((string) ($row['created_at'] ?? ''));
        }, $rows), static function (string $value): bool {
            return $value !== '';
        }));
    }

    private function isTestRequest(array $request): bool
    {
        $haystacks = [
            trim((string) ($request['subject'] ?? '')),
            trim((string) ($request['request_text'] ?? '')),
            trim((string) ($request['request_html'] ?? '')),
        ];
        $needles = ['test', 'essai', 'demo', 'démo', 'demonstration', 'démonstration', 'sandbox', 'brouillon'];

        foreach ($haystacks as $haystack) {
            if ($haystack === '') {
                continue;
            }
            $normalized = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            foreach ($needles as $needle) {
                if (strpos($normalized, $needle) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function sendUnreadAcknowledgementAlerts(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM rh_explanation_requests WHERE employee_read_ack_at IS NULL AND read_alert_due_at IS NOT NULL AND read_alert_due_at <= NOW() AND read_alert_sent_at IS NULL');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $request = $this->hydrateRequest($row);
            $recipientIds = array_values(array_unique(array_filter(array_merge([
                (int) ($request['created_by_user_id'] ?? 0),
                (int) ($request['direct_manager_user_id'] ?? 0),
                (int) ($request['department_head_user_id'] ?? 0),
                (int) ($request['dg_user_id'] ?? 0),
            ], array_map('intval', (array) ($request['cc_user_ids'] ?? []))))));

            foreach ($recipientIds as $recipientUserId) {
                if ($recipientUserId <= 0) {
                    continue;
                }
                $this->createSystemNotification(
                    'rh_explanation_unread_alert',
                    'watcher',
                    'Accusé de lecture manquant - ' . (string) ($request['request_ref'] ?? ''),
                    'Le salarié n’a pas encore ouvert sa demande d’explications. Prévoir une remise en main propre physique.',
                    '/rh/demandes_explication_rh.php?id=' . (int) ($request['id'] ?? 0),
                    null,
                    $recipientUserId,
                    [
                        'request_id' => (int) ($request['id'] ?? 0),
                        'request_ref' => (string) ($request['request_ref'] ?? ''),
                        'alert_kind' => 'manual_delivery_required',
                    ]
                );
            }

            $this->pdo->prepare('UPDATE rh_explanation_requests SET read_alert_sent_at = NOW(), updated_at = NOW() WHERE id = :id LIMIT 1')->execute([
                'id' => (int) ($request['id'] ?? 0),
            ]);
            $this->logDisciplineEvent('read_alert_sent', $request, [
                'user_id' => 0,
                'name' => 'SYSTEM',
                'role' => 'system',
                'ip' => '127.0.0.1',
            ], [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => (string) ($request['status'] ?? ''),
                'read_alert_due_at' => (string) ($request['read_alert_due_at'] ?? ''),
            ]);
        }
    }

    private function archiveExpiredDisciplineRecords(): void
    {
        $stmt = $this->pdo->query('SELECT * FROM rh_explanation_requests WHERE status = "closed" AND discipline_retention_until IS NOT NULL AND discipline_retention_until <= NOW() AND discipline_archived_at IS NULL');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        foreach ($rows as $row) {
            $request = $this->hydrateRequest($row);
            $this->pdo->prepare('UPDATE rh_explanation_requests SET discipline_archived_at = NOW(), discipline_archive_reason = :reason, updated_at = NOW() WHERE id = :id LIMIT 1')->execute([
                'reason' => 'Archivage automatique après 6 mois de conservation disciplinaire.',
                'id' => (int) ($request['id'] ?? 0),
            ]);
            $this->logDisciplineEvent('discipline_archived', $request, [
                'user_id' => 0,
                'name' => 'SYSTEM',
                'role' => 'system',
                'ip' => '127.0.0.1',
            ], [
                'status_before' => (string) ($request['status'] ?? ''),
                'status_after' => (string) ($request['status'] ?? ''),
                'discipline_retention_until' => (string) ($request['discipline_retention_until'] ?? ''),
            ]);
        }
    }

    private function buildRequestRef(int $requestId): string
    {
        return 'DEXP-' . date('ym') . '-' . str_pad((string) $requestId, 4, '0', STR_PAD_LEFT);
    }

    private function formatDateTimeFr(string $dateTime): string
    {
        if ($dateTime === '' || strtotime($dateTime) === false) {
            return 'Non renseignée';
        }
        return date('d/m/Y H:i', strtotime($dateTime));
    }

    private function absoluteUrl(string $path): string
    {
        $baseUrl = trim((string) \EnvConfig::get('APP_BASE_URL', ''));
        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }

    private function createSystemNotification(string $eventKey, ?string $subKey, string $title, string $message, string $linkUrl, ?string $recipientTeam, ?int $recipientUserId, array $payload): void
    {
        if ($recipientUserId === null || $recipientUserId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO system_notifications (created_at, event_key, sub_key, title, message, link_url, recipient_team, recipient_user_id, channels_json, payload_json) VALUES (NOW(), :event_key, :sub_key, :title, :message, :link_url, :recipient_team, :recipient_user_id, :channels_json, :payload_json)');
        $stmt->execute([
            'event_key' => $eventKey,
            'sub_key' => $subKey,
            'title' => $title,
            'message' => $message,
            'link_url' => $linkUrl,
            'recipient_team' => $recipientTeam,
            'recipient_user_id' => $recipientUserId,
            'channels_json' => json_encode(['system'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function storeSignatureDataUrl(int $requestId, string $role, string $signatureDataUrl): array
    {
        $signatureDataUrl = trim($signatureDataUrl);
        if ($signatureDataUrl === '') {
            throw new RuntimeException('Aucune signature n’a été transmise.');
        }

        if (!preg_match('#^data:image/(?P<ext>png|jpe?g);base64,(?P<data>[A-Za-z0-9+/=\r\n]+)$#i', $signatureDataUrl, $matches)) {
            throw new RuntimeException('Format de signature invalide.');
        }

        $binary = base64_decode((string) ($matches['data'] ?? ''), true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('La signature transmise est illisible.');
        }
        if (strlen($binary) > 2_500_000) {
            throw new RuntimeException('La signature est trop volumineuse.');
        }
        if (@getimagesizefromstring($binary) === false) {
            throw new RuntimeException('Le fichier de signature n’est pas une image valide.');
        }

        $extension = strtolower((string) ($matches['ext'] ?? 'png'));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $relativeDir = 'storage/documents/rh_explanation_signatures';
        $absoluteDir = __DIR__ . '/../' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Impossible de créer le répertoire de signatures.');
        }

        $fileName = sprintf('%s_%d_%s_%s.%s', $role, $requestId, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
        $absolutePath = $absoluteDir . '/' . $fileName;
        if (@file_put_contents($absolutePath, $binary) === false) {
            throw new RuntimeException('Impossible d’enregistrer la signature.');
        }

        return [
            'relative_path' => $relativeDir . '/' . $fileName,
            'absolute_path' => $absolutePath,
        ];
    }

    private function resolveStorageAbsolutePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '') {
            return null;
        }

        $fullPath = realpath(__DIR__ . '/../' . ltrim($relativePath, '/'));
        return $fullPath !== false ? $fullPath : null;
    }

    private function saveSignatureProfile(string $role, int $userId, string $signedByName, string $sourceAbsolutePath): void
    {
        $role = $role === 'employee' ? 'employee' : 'rh';
        if ($userId <= 0 || !is_file($sourceAbsolutePath)) {
            return;
        }

        $existing = $this->getSignatureProfile($role, $userId);
        $extension = strtolower((string) pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'png';
        }

        $relativeDir = 'storage/documents/rh_explanation_signature_profiles';
        $absoluteDir = __DIR__ . '/../' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return;
        }

        $fileName = sprintf('%s_profile_%d_%s_%s.%s', $role, $userId, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
        $destinationAbsolutePath = $absoluteDir . '/' . $fileName;
        if (!@copy($sourceAbsolutePath, $destinationAbsolutePath)) {
            return;
        }

        if ($existing && !empty($existing['signature_path']) && $existing['signature_path'] !== ($relativeDir . '/' . $fileName)) {
            $existingAbsolutePath = $this->resolveStorageAbsolutePath((string) $existing['signature_path']);
            if ($existingAbsolutePath !== null && is_file($existingAbsolutePath)) {
                @unlink($existingAbsolutePath);
            }
        }

        $stmt = $this->pdo->prepare('INSERT INTO rh_explanation_signature_profiles (user_id, role, signature_path, signed_at, signed_by_name, updated_at) VALUES (:user_id, :role, :signature_path, NOW(), :signed_by_name, NOW()) ON DUPLICATE KEY UPDATE signature_path = VALUES(signature_path), signed_at = VALUES(signed_at), signed_by_name = VALUES(signed_by_name), updated_at = NOW()');
        $stmt->execute([
            'user_id' => $userId,
            'role' => $role,
            'signature_path' => $relativeDir . '/' . $fileName,
            'signed_by_name' => $signedByName !== '' ? $signedByName : null,
        ]);
    }

    private function getSignatureProfile(string $role, int $userId): ?array
    {
        $role = $role === 'employee' ? 'employee' : 'rh';
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM rh_explanation_signature_profiles WHERE user_id = :user_id AND role = :role LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'role' => $role,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function applySignatureProfileToRequest(int $requestId, string $role, int $userId): void
    {
        $role = $role === 'employee' ? 'employee' : 'rh';
        if ($requestId <= 0 || $userId <= 0) {
            return;
        }

        $profile = $this->getSignatureProfile($role, $userId);
        if (!$profile) {
            return;
        }

        $sourceAbsolutePath = $this->resolveStorageAbsolutePath((string) ($profile['signature_path'] ?? ''));
        if ($sourceAbsolutePath === null || !is_file($sourceAbsolutePath)) {
            return;
        }

        $request = $this->getRequestById($requestId);
        if (!$request) {
            return;
        }

        $pathColumn = $role === 'employee' ? 'employee_signature_path' : 'rh_signature_path';
        if (trim((string) ($request[$pathColumn] ?? '')) !== '') {
            return;
        }

        $extension = strtolower((string) pathinfo($sourceAbsolutePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'png';
        }

        $relativeDir = 'storage/documents/rh_explanation_signatures';
        $absoluteDir = __DIR__ . '/../' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            return;
        }

        $fileName = sprintf('%s_%d_%s_profile_%s.%s', $role, $requestId, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
        $destinationAbsolutePath = $absoluteDir . '/' . $fileName;
        if (!@copy($sourceAbsolutePath, $destinationAbsolutePath)) {
            return;
        }

        $signedAtColumn = $role === 'employee' ? 'employee_signed_at' : 'rh_signed_at';
        $signedByUserColumn = $role === 'employee' ? 'employee_signed_by_user_id' : 'rh_signed_by_user_id';
        $signedByNameColumn = $role === 'employee' ? 'employee_signed_by_name' : 'rh_signed_by_name';
        $signedAtValue = trim((string) ($profile['signed_at'] ?? ''));
        if ($signedAtValue === '' || strtotime($signedAtValue) === false) {
            $signedAtValue = date('Y-m-d H:i:s');
        }

        $sql = 'UPDATE rh_explanation_requests SET '
            . $pathColumn . ' = :signature_path, '
            . $signedAtColumn . ' = :signed_at, '
            . $signedByUserColumn . ' = :signed_by_user_id, '
            . $signedByNameColumn . ' = :signed_by_name, '
            . 'updated_at = NOW() WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'signature_path' => $relativeDir . '/' . $fileName,
            'signed_at' => $signedAtValue,
            'signed_by_user_id' => $userId,
            'signed_by_name' => trim((string) ($profile['signed_by_name'] ?? '')) !== '' ? trim((string) ($profile['signed_by_name'] ?? '')) : null,
            'id' => $requestId,
        ]);
    }

    private function ensureTables(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_ref VARCHAR(32) NOT NULL,
            created_by_user_id INT DEFAULT NULL,
            created_by_name VARCHAR(150) DEFAULT NULL,
            created_by_email VARCHAR(150) DEFAULT NULL,
            target_personnel_id INT NOT NULL,
            target_user_id INT DEFAULT NULL,
            target_name VARCHAR(180) NOT NULL,
            target_email VARCHAR(180) NOT NULL,
            target_service_id INT DEFAULT NULL,
            target_service_label VARCHAR(180) DEFAULT NULL,
            target_function_label VARCHAR(180) DEFAULT NULL,
            direct_manager_user_id INT DEFAULT NULL,
            department_head_user_id INT DEFAULT NULL,
            dg_user_id INT DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            request_html LONGTEXT NOT NULL,
            request_text LONGTEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT "pending_response",
            due_at DATETIME DEFAULT NULL,
            answered_at DATETIME DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            closed_by_user_id INT DEFAULT NULL,
            closed_by_name VARCHAR(150) DEFAULT NULL,
            rh_signature_path VARCHAR(255) DEFAULT NULL,
            rh_signed_at DATETIME DEFAULT NULL,
            rh_signed_by_user_id INT DEFAULT NULL,
            rh_signed_by_name VARCHAR(150) DEFAULT NULL,
            employee_signature_path VARCHAR(255) DEFAULT NULL,
            employee_signed_at DATETIME DEFAULT NULL,
            employee_signed_by_user_id INT DEFAULT NULL,
            employee_signed_by_name VARCHAR(150) DEFAULT NULL,
            is_cadre_direct TINYINT(1) NOT NULL DEFAULT 0,
            cc_user_ids_json LONGTEXT DEFAULT NULL,
            cc_emails_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_rer_target_user (target_user_id),
            KEY idx_rer_status (status),
            KEY idx_rer_due (due_at),
            KEY idx_rer_service (target_service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_messages (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT UNSIGNED NOT NULL,
            author_role VARCHAR(16) NOT NULL,
            author_user_id INT DEFAULT NULL,
            author_name VARCHAR(150) DEFAULT NULL,
            message_kind VARCHAR(32) NOT NULL,
            body_html LONGTEXT NOT NULL,
            body_text LONGTEXT NOT NULL,
            is_internal TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_rem_request (request_id),
            KEY idx_rem_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_settings (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_service_hierarchy (
            service_id INT NOT NULL PRIMARY KEY,
            direction_label VARCHAR(180) DEFAULT NULL,
            direct_manager_user_id INT DEFAULT NULL,
            department_head_user_id INT DEFAULT NULL,
            contract_signer_user_id INT DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        try{$this->pdo->exec('ALTER TABLE rh_service_hierarchy ADD COLUMN contract_signer_user_id INT DEFAULT NULL AFTER department_head_user_id');}catch(Throwable $e){}
        try{$this->pdo->exec('UPDATE rh_service_hierarchy SET contract_signer_user_id=department_head_user_id WHERE contract_signer_user_id IS NULL AND department_head_user_id IS NOT NULL');}catch(Throwable $e){}

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_sanction_levels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            label VARCHAR(180) NOT NULL,
            severity_rank INT NOT NULL DEFAULT 1,
            description TEXT DEFAULT NULL,
            category VARCHAR(32) NOT NULL DEFAULT "disciplinary",
            disables_platform_access TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_resl_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_fault_levels (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(64) NOT NULL,
            label VARCHAR(180) NOT NULL,
            severity_rank INT NOT NULL DEFAULT 1,
            description TEXT DEFAULT NULL,
            default_sanction_level_id INT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_refl_code (code),
            KEY idx_refl_default_sanction (default_sanction_level_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_signature_profiles (
            user_id INT NOT NULL,
            role VARCHAR(16) NOT NULL,
            signature_path VARCHAR(255) DEFAULT NULL,
            signed_at DATETIME DEFAULT NULL,
            signed_by_name VARCHAR(150) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, role),
            KEY idx_resp_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS logs_discipline (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT DEFAULT NULL,
            request_ref VARCHAR(64) DEFAULT NULL,
            action_key VARCHAR(64) NOT NULL,
            status_before VARCHAR(32) DEFAULT NULL,
            status_after VARCHAR(32) DEFAULT NULL,
            actor_user_id INT DEFAULT NULL,
            actor_name VARCHAR(150) DEFAULT NULL,
            actor_role VARCHAR(32) DEFAULT NULL,
            actor_ip VARCHAR(64) DEFAULT NULL,
            details_json LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ld_request (request_id),
            KEY idx_ld_action (action_key),
            KEY idx_ld_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS rh_explanation_deleted_archive (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id INT DEFAULT NULL,
            request_ref VARCHAR(64) DEFAULT NULL,
            deleted_by_user_id INT DEFAULT NULL,
            deleted_by_name VARCHAR(150) DEFAULT NULL,
            deleted_by_role VARCHAR(32) DEFAULT NULL,
            deleted_by_ip VARCHAR(64) DEFAULT NULL,
            delete_reason TEXT DEFAULT NULL,
            request_snapshot_json LONGTEXT NOT NULL,
            messages_snapshot_json LONGTEXT DEFAULT NULL,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_reda_request (request_id),
            KEY idx_reda_deleted_at (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $this->ensureRequestColumn('rh_signature_path', 'ALTER TABLE rh_explanation_requests ADD COLUMN rh_signature_path VARCHAR(255) DEFAULT NULL AFTER closed_by_name');
        $this->ensureRequestColumn('rh_signed_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN rh_signed_at DATETIME DEFAULT NULL AFTER rh_signature_path');
        $this->ensureRequestColumn('rh_signed_by_user_id', 'ALTER TABLE rh_explanation_requests ADD COLUMN rh_signed_by_user_id INT DEFAULT NULL AFTER rh_signed_at');
        $this->ensureRequestColumn('rh_signed_by_name', 'ALTER TABLE rh_explanation_requests ADD COLUMN rh_signed_by_name VARCHAR(150) DEFAULT NULL AFTER rh_signed_by_user_id');
        $this->ensureRequestColumn('employee_signature_path', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_signature_path VARCHAR(255) DEFAULT NULL AFTER rh_signed_by_name');
        $this->ensureRequestColumn('employee_signed_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_signed_at DATETIME DEFAULT NULL AFTER employee_signature_path');
        $this->ensureRequestColumn('employee_signed_by_user_id', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_signed_by_user_id INT DEFAULT NULL AFTER employee_signed_at');
        $this->ensureRequestColumn('employee_signed_by_name', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_signed_by_name VARCHAR(150) DEFAULT NULL AFTER employee_signed_by_user_id');
        $this->ensureRequestColumn('fault_level_id', 'ALTER TABLE rh_explanation_requests ADD COLUMN fault_level_id INT DEFAULT NULL AFTER employee_signed_by_name');
        $this->ensureRequestColumn('fault_level_code', 'ALTER TABLE rh_explanation_requests ADD COLUMN fault_level_code VARCHAR(64) DEFAULT NULL AFTER fault_level_id');
        $this->ensureRequestColumn('fault_level_label', 'ALTER TABLE rh_explanation_requests ADD COLUMN fault_level_label VARCHAR(180) DEFAULT NULL AFTER fault_level_code');
        $this->ensureRequestColumn('fault_severity_rank', 'ALTER TABLE rh_explanation_requests ADD COLUMN fault_severity_rank INT DEFAULT NULL AFTER fault_level_label');
        $this->ensureRequestColumn('sanction_level_id', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_level_id INT DEFAULT NULL AFTER fault_severity_rank');
        $this->ensureRequestColumn('sanction_level_code', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_level_code VARCHAR(64) DEFAULT NULL AFTER sanction_level_id');
        $this->ensureRequestColumn('sanction_level_label', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_level_label VARCHAR(180) DEFAULT NULL AFTER sanction_level_code');
        $this->ensureRequestColumn('sanction_severity_rank', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_severity_rank INT DEFAULT NULL AFTER sanction_level_label');
        $this->ensureRequestColumn('sanction_category', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_category VARCHAR(32) DEFAULT NULL AFTER sanction_severity_rank');
        $this->ensureRequestColumn('sanction_disables_platform_access', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_disables_platform_access TINYINT(1) NOT NULL DEFAULT 0 AFTER sanction_category');
        $this->ensureRequestColumn('platform_access_disabled_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN platform_access_disabled_at DATETIME DEFAULT NULL AFTER sanction_disables_platform_access');
        $this->ensureRequestColumn('employee_first_read_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_first_read_at DATETIME DEFAULT NULL AFTER employee_signed_by_name');
        $this->ensureRequestColumn('employee_read_ack_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_read_ack_at DATETIME DEFAULT NULL AFTER employee_first_read_at');
        $this->ensureRequestColumn('employee_read_ack_user_id', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_read_ack_user_id INT DEFAULT NULL AFTER employee_read_ack_at');
        $this->ensureRequestColumn('employee_read_ack_ip', 'ALTER TABLE rh_explanation_requests ADD COLUMN employee_read_ack_ip VARCHAR(64) DEFAULT NULL AFTER employee_read_ack_user_id');
        $this->ensureRequestColumn('read_alert_due_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN read_alert_due_at DATETIME DEFAULT NULL AFTER employee_read_ack_ip');
        $this->ensureRequestColumn('read_alert_sent_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN read_alert_sent_at DATETIME DEFAULT NULL AFTER read_alert_due_at');
        $this->ensureRequestColumn('sanction_available_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN sanction_available_at DATETIME DEFAULT NULL AFTER read_alert_sent_at');
        $this->ensureRequestColumn('discipline_retention_until', 'ALTER TABLE rh_explanation_requests ADD COLUMN discipline_retention_until DATETIME DEFAULT NULL AFTER platform_access_disabled_at');
        $this->ensureRequestColumn('discipline_archived_at', 'ALTER TABLE rh_explanation_requests ADD COLUMN discipline_archived_at DATETIME DEFAULT NULL AFTER discipline_retention_until');
        $this->ensureRequestColumn('discipline_archive_reason', 'ALTER TABLE rh_explanation_requests ADD COLUMN discipline_archive_reason VARCHAR(255) DEFAULT NULL AFTER discipline_archived_at');

        $this->seedDisciplineDefaults();

        $settings = $this->getSettings();
        if ((int) ($settings['response_due_days'] ?? 0) <= 0 || trim((string) ($settings['template_facts_label'] ?? '')) === '') {
            $this->saveSettings($settings);
        }
    }

    private function seedDisciplineDefaults(): void
    {
        $sanctionCount = (int) ($this->pdo->query('SELECT COUNT(*) FROM rh_explanation_sanction_levels')->fetchColumn() ?: 0);
        if ($sanctionCount === 0) {
            $defaults = [
                ['code' => 'warning_simple', 'label' => 'Avertissement simple (aucune sanction)', 'severity_rank' => 1, 'description' => 'Rappel formel sans autre sanction disciplinaire.', 'category' => 'warning', 'disables_platform_access' => 0, 'sort_order' => 10],
                ['code' => 'blame', 'label' => 'Blâme', 'severity_rank' => 2, 'description' => 'Sanction disciplinaire écrite sans suspension des accès.', 'category' => 'disciplinary', 'disables_platform_access' => 0, 'sort_order' => 20],
                ['code' => 'mise_a_pied', 'label' => 'Mise à pied', 'severity_rank' => 3, 'description' => 'Suspension disciplinaire avec coupure des accès plateforme.', 'category' => 'disciplinary', 'disables_platform_access' => 1, 'sort_order' => 30],
                ['code' => 'licenciement', 'label' => 'Licenciement', 'severity_rank' => 4, 'description' => 'Rupture disciplinaire avec retrait immédiat des accès plateforme.', 'category' => 'disciplinary', 'disables_platform_access' => 1, 'sort_order' => 40],
            ];

            foreach ($defaults as $row) {
                $this->saveSanctionLevel(0, $row + ['is_active' => 1]);
            }
        }

        $faultCount = (int) ($this->pdo->query('SELECT COUNT(*) FROM rh_explanation_fault_levels')->fetchColumn() ?: 0);
        if ($faultCount === 0) {
            $sanctions = [];
            foreach ($this->listSanctionLevels(false) as $sanctionLevel) {
                $sanctions[(string) ($sanctionLevel['code'] ?? '')] = (int) ($sanctionLevel['id'] ?? 0);
            }

            $defaults = [
                ['code' => 'faute_legere', 'label' => 'Faute légère', 'severity_rank' => 1, 'description' => 'Écart mineur ou manquement ponctuel.', 'default_sanction_level_id' => (int) ($sanctions['warning_simple'] ?? 0), 'sort_order' => 10],
                ['code' => 'faute_moyenne', 'label' => 'Faute moyenne', 'severity_rank' => 2, 'description' => 'Manquement répété ou impact modéré.', 'default_sanction_level_id' => (int) ($sanctions['blame'] ?? 0), 'sort_order' => 20],
                ['code' => 'faute_grave', 'label' => 'Faute grave', 'severity_rank' => 3, 'description' => 'Manquement sérieux nécessitant une sanction forte.', 'default_sanction_level_id' => (int) ($sanctions['mise_a_pied'] ?? 0), 'sort_order' => 30],
                ['code' => 'faute_tres_grave', 'label' => 'Faute très grave', 'severity_rank' => 4, 'description' => 'Manquement critique incompatible avec le maintien en poste.', 'default_sanction_level_id' => (int) ($sanctions['licenciement'] ?? 0), 'sort_order' => 40],
            ];

            foreach ($defaults as $row) {
                $this->saveFaultLevel(0, $row + ['is_active' => 1]);
            }
        }
    }

    private function ensureRequestColumn(string $columnName, string $alterSql): void
    {
        $stmt = $this->pdo->prepare('SHOW COLUMNS FROM rh_explanation_requests LIKE :column_name');
        $stmt->execute(['column_name' => $columnName]);
        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->pdo->exec($alterSql);
        }
    }
}
