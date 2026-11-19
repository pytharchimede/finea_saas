<?php

require_once __DIR__ . '/../model/RhExplanationRequestManager.php';
require_once __DIR__ . '/../decaissement/model/PersonnelDirectory.php';

class RhOrganizationChartService
{
    private PDO $pdo;
    private PersonnelDirectory $directory;
    private RhExplanationRequestManager $hierarchyManager;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->directory = new PersonnelDirectory($pdo);
        $this->hierarchyManager = new RhExplanationRequestManager($pdo);
    }

    public function getSnapshot(): array
    {
        $personnelRows = $this->directory->getPersonnelRowsForExport([
            'scope' => 'active',
            'search_mode' => 'include',
        ], 10000);

        $people = [];
        $personnelByEmail = [];
        foreach ($personnelRows as $row) {
            $person = $this->normalizePersonnel($row);
            $people[$person['id']] = $person;
            if ($person['email'] !== '') {
                $personnelByEmail[strtolower($person['email'])] = $person['id'];
            }
        }

        $users = $this->loadUsers($people, $personnelByEmail);
        $settings = $this->hierarchyManager->getSettings();
        $hierarchyRows = $this->hierarchyManager->getServiceHierarchyRows();

        $dgUserId = (int) ($settings['dg_user_id'] ?? 0);
        $dgPerson = $this->personForUser($dgUserId, $users, $people, 'Direction generale');
        $reservedPersonnelIds = [];
        if (($dgPerson['personnel_id'] ?? 0) > 0) {
            $reservedPersonnelIds[(int) $dgPerson['personnel_id']] = true;
        }

        $departments = [];
        foreach ($hierarchyRows as $row) {
            $serviceId = (int) ($row['service_id'] ?? 0);
            if ($serviceId <= 0) {
                continue;
            }

            $departmentHeadUserId = (int) ($row['department_head_user_id'] ?? 0);
            $directManagerUserId = (int) ($row['direct_manager_user_id'] ?? 0);
            $departmentKey = $departmentHeadUserId > 0 ? 'user-' . $departmentHeadUserId : 'unassigned';
            $departmentHead = $this->personForUser($departmentHeadUserId, $users, $people, 'Chef de departement');
            $directManager = $this->personForUser($directManagerUserId, $users, $people, 'Responsable direct');

            foreach ([$departmentHead, $directManager] as $leader) {
                if (($leader['personnel_id'] ?? 0) > 0) {
                    $reservedPersonnelIds[(int) $leader['personnel_id']] = true;
                }
            }

            if (!isset($departments[$departmentKey])) {
                $departments[$departmentKey] = [
                    'id' => $departmentKey,
                    'label' => $departmentHead !== null ? $departmentHead['name'] : 'Services a structurer',
                    'direction_label' => trim((string) ($row['direction_label'] ?? '')),
                    'head' => $departmentHead,
                    'services' => [],
                ];
            }

            $departments[$departmentKey]['services'][] = [
                'id' => $serviceId,
                'label' => trim((string) ($row['service_label'] ?? '')) ?: ('Service ' . $serviceId),
                'manager' => $directManager,
                'employees' => [],
            ];
        }

        $serviceLookup = [];
        foreach ($departments as $departmentKey => $department) {
            foreach ($department['services'] as $serviceIndex => $service) {
                $serviceLookup[(int) $service['id']] = [$departmentKey, $serviceIndex];
            }
        }

        foreach ($people as $personnelId => $person) {
            if (isset($reservedPersonnelIds[$personnelId])) {
                continue;
            }

            $serviceId = (int) ($person['service_id'] ?? 0);
            if (!isset($serviceLookup[$serviceId])) {
                $departmentKey = 'unassigned';
                if (!isset($departments[$departmentKey])) {
                    $departments[$departmentKey] = [
                        'id' => $departmentKey,
                        'label' => 'Services a structurer',
                        'direction_label' => '',
                        'head' => null,
                        'services' => [],
                    ];
                }
                $serviceIndex = count($departments[$departmentKey]['services']);
                $departments[$departmentKey]['services'][] = [
                    'id' => $serviceId,
                    'label' => $person['service_label'] ?: 'Service non renseigne',
                    'manager' => null,
                    'employees' => [],
                ];
                $serviceLookup[$serviceId] = [$departmentKey, $serviceIndex];
            }

            [$departmentKey, $serviceIndex] = $serviceLookup[$serviceId];
            $departments[$departmentKey]['services'][$serviceIndex]['employees'][] = $person;
        }

        foreach ($departments as &$department) {
            foreach ($department['services'] as &$service) {
                usort($service['employees'], static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
                $service['employee_count'] = count($service['employees']);
            }
            unset($service);
            usort($department['services'], static fn(array $left, array $right): int => strcasecmp($left['label'], $right['label']));
            $department['employee_count'] = array_sum(array_column($department['services'], 'employee_count'));
        }
        unset($department);

        uasort($departments, static function (array $left, array $right): int {
            if ($left['id'] === 'unassigned') {
                return 1;
            }
            if ($right['id'] === 'unassigned') {
                return -1;
            }
            return strcasecmp($left['label'], $right['label']);
        });

        $tree = $this->buildTree($dgPerson, array_values($departments));

        return [
            'company_name' => 'BANAMUR',
            'generated_at' => date('Y-m-d H:i:s'),
            'dg' => $dgPerson,
            'departments' => array_values($departments),
            'tree' => $tree,
            'stats' => [
                'personnel' => count($people),
                'departments' => count(array_filter($departments, static fn(array $row): bool => $row['id'] !== 'unassigned')),
                'services' => count($hierarchyRows),
                'configured_services' => count(array_filter($hierarchyRows, static fn(array $row): bool => (int) ($row['direct_manager_user_id'] ?? 0) > 0 || (int) ($row['department_head_user_id'] ?? 0) > 0)),
            ],
        ];
    }

    private function buildTree(?array $dgPerson, array $departments): array
    {
        $unitNodes = [];
        foreach ($departments as $department) {
            if ($department['head'] !== null && !$this->samePerson($department['head'], $dgPerson)) {
                $directionNode = $this->personNode($department['head'], 'direction');
                $directionNode['subtitle'] = $department['direction_label'] !== ''
                    ? $department['direction_label']
                    : 'Direction / departement';
                $directionNode['meta'] = 'Responsable de direction';
                $unitNodes[] = $directionNode;
            }

            foreach ($department['services'] as $service) {
                $employeeNodes = array_map(fn(array $person): array => $this->personNode($person, 'employee'), $service['employees']);
                $manager = $service['manager'];
                $serviceChildren = $employeeNodes;

                if ($manager !== null && !$this->samePerson($manager, $department['head']) && !$this->samePerson($manager, $dgPerson)) {
                    $managerNode = $this->personNode($manager, 'manager');
                    $managerNode['children'] = $employeeNodes;
                    $serviceChildren = [$managerNode];
                }

                $unitNodes[] = [
                    'id' => 'service-' . (int) $service['id'],
                    'type' => 'service',
                    'title' => $service['label'],
                    'subtitle' => $manager !== null ? 'Responsable : ' . $manager['name'] : 'Responsable non defini',
                    'meta' => $service['employee_count'] . ' collaborateur(s)',
                    'children' => $serviceChildren,
                ];
            }
        }

        if ($dgPerson !== null) {
            $dgNode = $this->personNode($dgPerson, 'dg');
            $dgNode['subtitle'] = 'Direction generale';
        } else {
            $dgNode = [
                'id' => 'dg-unassigned',
                'type' => 'dg-empty',
                'title' => 'Directeur general',
                'subtitle' => 'Non defini dans le parametrage RH',
                'meta' => '',
                'personnel_id' => 0,
                'initials' => 'DG',
                'children' => [],
            ];
        }
        $dgNode['children'] = $unitNodes;
        $children = [$dgNode];

        return [
            'id' => 'company-root',
            'type' => 'company',
            'title' => 'BANAMUR',
            'subtitle' => 'Organigramme general',
            'meta' => '',
            'children' => $children,
        ];
    }

    private function personNode(array $person, string $type): array
    {
        return [
            'id' => $type . '-' . ($person['personnel_id'] ?: ('user-' . $person['user_id'])),
            'type' => $type,
            'title' => $person['name'],
            'subtitle' => $person['function_label'] ?: ucfirst($type),
            'meta' => $person['service_label'],
            'personnel_id' => (int) ($person['personnel_id'] ?? 0),
            'initials' => $this->initials($person['name']),
            'children' => [],
        ];
    }

    private function normalizePersonnel(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'personnel_id' => (int) ($row['id'] ?? 0),
            'user_id' => 0,
            'name' => trim((string) ($row['full_name'] ?? '')) ?: 'Collaborateur',
            'email' => trim((string) ($row['email'] ?? '')),
            'matricule' => trim((string) ($row['matricule'] ?? '')),
            'service_id' => (int) ($row['service_id'] ?? 0),
            'service_label' => trim((string) ($row['service_label'] ?? '')),
            'function_label' => trim((string) ($row['fonction_label'] ?? '')),
            'photo' => trim((string) ($row['photo'] ?? '')),
        ];
    }

    private function loadUsers(array &$people, array $personnelByEmail): array
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $hasPersonnelId = in_array('personnel_id', $columns, true);
        $personnelSelect = $hasPersonnelId ? 'personnel_id' : 'NULL AS personnel_id';
        $rows = $this->pdo->query("SELECT id, prenom, nom, mail_pro, photo, {$personnelSelect} FROM user_devis_banamur WHERE active = 1 ORDER BY prenom, nom")->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $users = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $email = strtolower(trim((string) ($row['mail_pro'] ?? '')));
            $personnelId = (int) ($row['personnel_id'] ?? 0);
            if ($personnelId <= 0 && $email !== '' && isset($personnelByEmail[$email])) {
                $personnelId = (int) $personnelByEmail[$email];
            }
            if ($personnelId > 0 && isset($people[$personnelId])) {
                $people[$personnelId]['user_id'] = $userId;
            }
            $users[$userId] = [
                'id' => $userId,
                'personnel_id' => $personnelId,
                'name' => trim((string) (($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''))) ?: ('Utilisateur #' . $userId),
                'email' => trim((string) ($row['mail_pro'] ?? '')),
                'photo' => trim((string) ($row['photo'] ?? '')),
            ];
        }

        return $users;
    }

    private function personForUser(int $userId, array $users, array $people, string $fallbackFunction): ?array
    {
        if ($userId <= 0 || !isset($users[$userId])) {
            return null;
        }

        $user = $users[$userId];
        $personnelId = (int) ($user['personnel_id'] ?? 0);
        if ($personnelId > 0 && isset($people[$personnelId])) {
            $person = $people[$personnelId];
            $person['user_id'] = $userId;
            return $person;
        }

        return [
            'id' => 0,
            'personnel_id' => 0,
            'user_id' => $userId,
            'name' => $user['name'],
            'email' => $user['email'],
            'matricule' => '',
            'service_id' => 0,
            'service_label' => '',
            'function_label' => $fallbackFunction,
            'photo' => $user['photo'],
        ];
    }

    private function samePerson(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return false;
        }
        $leftPersonnel = (int) ($left['personnel_id'] ?? 0);
        $rightPersonnel = (int) ($right['personnel_id'] ?? 0);
        if ($leftPersonnel > 0 && $rightPersonnel > 0) {
            return $leftPersonnel === $rightPersonnel;
        }
        return (int) ($left['user_id'] ?? 0) > 0 && (int) ($left['user_id'] ?? 0) === (int) ($right['user_id'] ?? 0);
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= strtoupper(substr($part, 0, 1));
            if (strlen($initials) >= 2) {
                break;
            }
        }
        return $initials !== '' ? $initials : 'RH';
    }
}
