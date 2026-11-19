<?php

class AiContextRepository
{
    private PDO $pdo;
    private array $columnsCache = [];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getCurrentUserProfile(int $userId): array
    {
        $defaults = [
            'nom_employe' => '',
            'poste' => '',
            'service' => '',
            'date' => date('d/m/Y'),
            'motif' => '',
            'periode' => '',
            'responsable' => '',
            'entreprise' => 'FINEA',
        ];

        if ($userId <= 0 || !$this->tableExists('user_devis_banamur')) {
            return $defaults;
        }

        $userColumns = $this->getTableColumns('user_devis_banamur');
        $select = [
            'u.id',
            'COALESCE(u.prenom, "") AS prenom',
            'COALESCE(u.nom, "") AS nom',
            'COALESCE(u.mail_pro, "") AS mail_pro',
        ];
        $joins = [];

        if (in_array('role_id', $userColumns, true) && $this->tableExists('role_devis_banamur')) {
            $select[] = 'COALESCE(r.lib_role_devis, "") AS role_label';
            $joins[] = 'LEFT JOIN role_devis_banamur r ON r.id_role_devis = u.role_id';
        } else {
            $select[] = '"" AS role_label';
        }

        $serviceIdExpression = '0';
        if (in_array('personnel_id', $userColumns, true) && $this->tableExists('personnel')) {
            $personnelColumns = $this->getTableColumns('personnel');
            $joins[] = 'LEFT JOIN personnel p ON p.id_personnel_soignant = u.personnel_id';

            if (in_array('service_id', $personnelColumns, true) && $this->tableExists('service')) {
                $joins[] = 'LEFT JOIN service s ON s.id_service = p.service_id';
                $serviceIdExpression = 'COALESCE(p.service_id, 0)';
                $select[] = $serviceIdExpression . ' AS service_id';
                $select[] = 'COALESCE(s.lib_service, "") AS service_label';
            } else {
                $select[] = '0 AS service_id';
                $select[] = '"" AS service_label';
            }

            $posteColumn = $this->firstExistingColumn($personnelColumns, [
                'fonction_label',
                'fonction',
                'poste',
                'lib_fonction',
                'fonction_pers_soignant',
                'qualification',
                'emploi',
            ]);
            if ($posteColumn !== '') {
                $select[] = 'COALESCE(p.`' . $posteColumn . '`, "") AS poste_label';
            } else {
                $select[] = '"" AS poste_label';
            }
        } elseif (in_array('service_id', $userColumns, true) && $this->tableExists('service')) {
            $joins[] = 'LEFT JOIN service s ON s.id_service = u.service_id';
            $serviceIdExpression = 'COALESCE(u.service_id, 0)';
            $select[] = $serviceIdExpression . ' AS service_id';
            $select[] = 'COALESCE(s.lib_service, "") AS service_label';
            $select[] = '"" AS poste_label';
        } else {
            $select[] = '0 AS service_id';
            $select[] = '"" AS service_label';
            $select[] = '"" AS poste_label';
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . implode(', ', $select) . '
             FROM user_devis_banamur u
             ' . implode("\n", $joins) . '
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        $fullName = trim((string)($row['prenom'] ?? '') . ' ' . (string)($row['nom'] ?? ''));
        $poste = trim((string)($row['poste_label'] ?? ''));
        if ($poste === '') {
            $poste = trim((string)($row['role_label'] ?? ''));
        }

        $serviceId = (int)($row['service_id'] ?? 0);

        return array_merge($defaults, [
            'nom_employe' => $fullName,
            'poste' => $poste,
            'service' => trim((string)($row['service_label'] ?? '')),
            'responsable' => $this->findServiceResponsibleName($serviceId),
        ]);
    }

    public function listSelectableRequests(int $userId, array $permissions, int $limit = 40): array
    {
        $limit = max(1, min(80, $limit));
        $items = [];

        foreach ($this->listRhExplanationRequests($userId, $permissions, $limit) as $row) {
            $items[] = $this->mapRhExplanationRequest($row, $userId);
        }

        foreach ($this->listEmployeeLegalRequests($userId, $permissions, $limit) as $row) {
            $items[] = $this->mapEmployeeLegalRequest($row, $userId);
        }

        usort($items, static function (array $left, array $right): int {
            return strcmp((string)($right['sort_date'] ?? ''), (string)($left['sort_date'] ?? ''));
        });

        return array_slice($items, 0, $limit);
    }

    private function listRhExplanationRequests(int $userId, array $permissions, int $limit): array
    {
        if (!$this->tableExists('rh_explanation_requests')) {
            return [];
        }

        $isRh = $this->hasAnyPermission($permissions, ['acces_rh', 'rh_admin', 'gestion_rh', 'acces_admin'])
            || $this->currentUserHasAnyFlag($userId, ['acces_rh', 'rh_admin', 'gestion_rh', 'acces_admin']);
        if ($isRh) {
            $stmt = $this->pdo->query(
                'SELECT *
                 FROM rh_explanation_requests
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . (int)$limit
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM rh_explanation_requests
             WHERE target_user_id = :uid OR created_by_user_id = :uid
             ORDER BY created_at DESC, id DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function listEmployeeLegalRequests(int $userId, array $permissions, int $limit): array
    {
        if (!$this->tableExists('employee_legal_requests')) {
            return [];
        }

        $isRh = $this->hasAnyPermission($permissions, ['acces_rh', 'rh_admin', 'gestion_rh', 'acces_admin'])
            || $this->currentUserHasAnyFlag($userId, ['acces_rh', 'rh_admin', 'gestion_rh', 'acces_admin']);
        if ($isRh) {
            $stmt = $this->pdo->query(
                'SELECT *
                 FROM employee_legal_requests
                 WHERE assigned_team = "rh"
                 ORDER BY submitted_at DESC, id DESC
                 LIMIT ' . (int)$limit
            );
            return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM employee_legal_requests
             WHERE requester_user_id = :uid
             ORDER BY submitted_at DESC, id DESC
             LIMIT ' . (int)$limit
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function mapRhExplanationRequest(array $row, int $userId): array
    {
        $ref = trim((string)($row['request_ref'] ?? ''));
        $subject = trim((string)($row['subject'] ?? 'Demande d\'explication'));
        $targetName = trim((string)($row['target_name'] ?? ''));
        $createdAt = (string)($row['created_at'] ?? '');
        $dueAt = (string)($row['due_at'] ?? '');
        $role = ((int)($row['target_user_id'] ?? 0) === $userId) ? 'self' : 'tiers';
        $dateLabel = $this->formatDate($createdAt);

        $contextText = trim(
            "Demande d'explication RH\n" .
            ($ref !== '' ? "Reference : {$ref}\n" : '') .
            "Objet : {$subject}\n" .
            ($targetName !== '' ? "Employe concerne : {$targetName}\n" : '') .
            "Service : " . trim((string)($row['target_service_label'] ?? '')) . "\n" .
            "Poste : " . trim((string)($row['target_function_label'] ?? '')) . "\n" .
            ($dueAt !== '' ? "Date limite de reponse : " . $this->formatDate($dueAt) . "\n" : '') .
            "\nTexte de la demande :\n" . trim((string)($row['request_text'] ?? ''))
        );

        return [
            'source' => 'rh_explanation',
            'id' => (int)($row['id'] ?? 0),
            'sort_date' => $createdAt,
            'label' => trim('[RH] ' . ($ref !== '' ? $ref . ' - ' : '') . $subject . ($targetName !== '' ? ' - ' . $targetName : '')),
            'context' => [
                'module' => 'RH',
                'task_type' => 'repondre_demande_explication',
                'request_type' => 'explication',
                'tone' => 'professionnel',
                'role_demandeur' => $role,
                'nom_employe' => $targetName,
                'poste' => trim((string)($row['target_function_label'] ?? '')),
                'service' => trim((string)($row['target_service_label'] ?? '')),
                'date' => $dateLabel,
                'motif' => $subject,
                'periode' => $dueAt !== '' ? $this->formatDate($dueAt) : '',
                'responsable' => trim((string)($row['created_by_name'] ?? '')),
                'entreprise' => 'FINEA',
                'montant' => '',
                'matricule' => '',
                'request_title' => $subject,
                'input_text' => $contextText,
            ],
        ];
    }

    private function mapEmployeeLegalRequest(array $row, int $userId): array
    {
        $payload = $this->decodeJson((string)($row['payload_json'] ?? ''));
        $title = trim((string)($row['title'] ?? 'Demande employe'));
        $requestType = $this->normalizeRequestType(trim((string)($row['request_type'] ?? 'rh')));
        $requester = trim((string)($row['requester_name'] ?? ''));
        $submittedAt = (string)($row['submitted_at'] ?? $row['created_at'] ?? '');
        $role = ((int)($row['requester_user_id'] ?? 0) === $userId) ? 'self' : 'tiers';
        $service = $this->valueFromPayload($payload, ['service', 'service_label', 'lib_service']);
        $poste = $this->valueFromPayload($payload, ['poste', 'fonction', 'fonction_label']);
        $motif = $this->valueFromPayload($payload, ['motif', 'reason', 'justification', 'objet']);
        if ($motif === '') {
            $motif = $title;
        }
        $amount = $this->formatAmount($this->valueFromPayload($payload, ['montant', 'amount', 'somme', 'montant_demande', 'requested_amount']));
        $matricule = $this->valueFromPayload($payload, ['matricule', 'employee_code', 'code_employe']);
        $hours = $this->valueFromPayload($payload, ['heures', 'hours', 'nombre_heures', 'nb_heures']);
        $effectiveDate = $this->valueFromPayload($payload, ['date_effective', 'effective_date', 'date_realisation', 'date']);
        $periode = $this->valueFromPayload($payload, ['periode', 'period', 'date_range']);
        if ($periode === '') {
            $periode = trim($this->valueFromPayload($payload, ['date_debut', 'start_date']) . ' ' . $this->valueFromPayload($payload, ['date_fin', 'end_date']));
        }

        $draftContextText = trim(
            "Demande existante\n" .
            "Type : {$requestType}\n" .
            "Titre : {$title}\n" .
            ($requester !== '' ? "Demandeur : {$requester}\n" : '') .
            ($amount !== '' ? "Montant : {$amount} FCFA\n" : '') .
            ($matricule !== '' ? "Matricule : {$matricule}\n" : '') .
            ($hours !== '' ? "Heures : {$hours}\n" : '') .
            ($effectiveDate !== '' ? "Date effective : {$effectiveDate}\n" : '') .
            ($motif !== '' ? "Motif : {$motif}\n" : '') .
            ($periode !== '' ? "Periode : {$periode}\n" : '') .
            "\nDonnees de la demande :\n" . $this->payloadToReadableText($payload)
        );
        $requestType = $this->inferBusinessRequestType($requestType, $title . "\n" . $draftContextText);
        $contextText = trim(
            "Demande existante\n" .
            "Type : {$requestType}\n" .
            "Titre : {$title}\n" .
            ($requester !== '' ? "Demandeur : {$requester}\n" : '') .
            ($amount !== '' ? "Montant : {$amount} FCFA\n" : '') .
            ($matricule !== '' ? "Matricule : {$matricule}\n" : '') .
            ($hours !== '' ? "Heures : {$hours}\n" : '') .
            ($effectiveDate !== '' ? "Date effective : {$effectiveDate}\n" : '') .
            ($motif !== '' ? "Motif : {$motif}\n" : '') .
            ($periode !== '' ? "Periode : {$periode}\n" : '') .
            "\nDonnees de la demande :\n" . $this->payloadToReadableText($payload)
        );

        return [
            'source' => 'employee_legal',
            'id' => (int)($row['id'] ?? 0),
            'sort_date' => $submittedAt,
            'label' => trim('[Demande] #' . (int)($row['id'] ?? 0) . ' - ' . $title . ($requester !== '' ? ' - ' . $requester : '')),
            'context' => [
                'module' => $requestType === 'feb' ? 'FEB' : 'RH',
                'task_type' => 'repondre_demande_explication',
                'request_type' => $requestType,
                'tone' => 'professionnel',
                'role_demandeur' => $role,
                'nom_employe' => $requester,
                'poste' => $poste,
                'service' => $service,
                'date' => $this->formatDate($submittedAt),
                'motif' => $motif,
                'periode' => trim($periode),
                'responsable' => '',
                'entreprise' => 'FINEA',
                'montant' => $amount,
                'matricule' => $matricule,
                'request_title' => $title,
                'heures' => $hours,
                'date_effective' => $effectiveDate,
                'input_text' => $contextText,
            ],
        ];
    }

    private function findServiceResponsibleName(int $serviceId): string
    {
        if ($serviceId <= 0 || !$this->tableExists('rh_service_hierarchy')) {
            return '';
        }

        $stmt = $this->pdo->prepare(
            'SELECT direct_manager_user_id, department_head_user_id
             FROM rh_service_hierarchy
             WHERE service_id = :service_id
             LIMIT 1'
        );
        $stmt->execute(['service_id' => $serviceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return '';
        }

        $responsibleId = (int)($row['direct_manager_user_id'] ?? 0);
        if ($responsibleId <= 0) {
            $responsibleId = (int)($row['department_head_user_id'] ?? 0);
        }
        if ($responsibleId <= 0) {
            return '';
        }

        $stmt = $this->pdo->prepare(
            'SELECT prenom, nom, mail_pro
             FROM user_devis_banamur
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $responsibleId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return '';
        }

        $name = trim((string)($user['prenom'] ?? '') . ' ' . (string)($user['nom'] ?? ''));
        return $name !== '' ? $name : trim((string)($user['mail_pro'] ?? ''));
    }

    private function valueFromPayload(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && !is_array($payload[$key])) {
                $value = trim((string)$payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    private function payloadToReadableText(array $payload): string
    {
        $lines = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $value = trim((string)$value);
            if ($value !== '') {
                $lines[] = str_replace('_', ' ', (string)$key) . ' : ' . $value;
            }
        }
        return $lines ? implode("\n", $lines) : 'Aucune donnee detaillee disponible.';
    }

    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y', $timestamp) : '';
    }

    private function normalizeRequestType(string $requestType): string
    {
        $requestType = mb_strtolower(trim($requestType));
        $requestType = str_replace([' ', '-', '/', '\\'], '_', $requestType);
        $requestType = preg_replace('/[^a-z0-9_]/', '', $requestType) ?: '';

        $aliases = [
            'avance' => 'avance_salaire',
            'avance_sur_salaire' => 'avance_salaire',
            'salary_advance' => 'avance_salaire',
            'conge' => 'absence',
            'permission' => 'absence',
        ];

        return $aliases[$requestType] ?? $requestType;
    }

    private function inferBusinessRequestType(string $requestType, string $text): string
    {
        if (!in_array($requestType, ['', 'rh', 'general', 'formulaire', 'demande'], true)) {
            return $requestType;
        }

        $text = mb_strtolower($text);
        if (str_contains($text, 'avance') && str_contains($text, 'salaire')) {
            return 'avance_salaire';
        }
        if (str_contains($text, 'absence') || str_contains($text, 'conge') || str_contains($text, 'permission')) {
            return 'absence';
        }
        if (str_contains($text, 'mission')) {
            return 'mission';
        }
        if (str_contains($text, 'feb') || str_contains($text, 'achat')) {
            return 'feb';
        }
        if (str_contains($text, 'clarification') || str_contains($text, 'complement')) {
            return 'clarification';
        }

        return $requestType;
    }

    private function formatAmount(string $amount): string
    {
        $amount = trim($amount);
        if ($amount === '') {
            return '';
        }
        $digits = preg_replace('/[^\d]/', '', $amount);
        if ($digits !== '' && strlen($digits) >= 4) {
            return number_format((int)$digits, 0, ',', ' ');
        }
        return $amount;
    }

    private function hasAnyPermission(array $permissions, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!empty($permissions[$key])) {
                return true;
            }
        }
        return false;
    }

    private function currentUserHasAnyFlag(int $userId, array $keys): bool
    {
        if ($userId <= 0 || !$this->tableExists('user_devis_banamur')) {
            return false;
        }

        $columns = $this->getTableColumns('user_devis_banamur');
        $available = array_values(array_filter($keys, static function (string $key) use ($columns): bool {
            return in_array($key, $columns, true);
        }));
        if (!$available) {
            return false;
        }

        $checks = array_map(static function (string $key): string {
            return 'COALESCE(`' . str_replace('`', '``', $key) . '`, 0) = 1';
        }, $available);

        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM user_devis_banamur
             WHERE id = :id AND (' . implode(' OR ', $checks) . ')
             LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    private function firstExistingColumn(array $columns, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }
        return '';
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE :table_name');
            $stmt->execute(['table_name' => $table]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }

    private function getTableColumns(string $table): array
    {
        if (array_key_exists($table, $this->columnsCache)) {
            return $this->columnsCache[$table];
        }

        try {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $columns = $stmt ? ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: []) : [];
        } catch (Throwable $e) {
            $columns = [];
        }

        $this->columnsCache[$table] = $columns;
        return $columns;
    }
}
