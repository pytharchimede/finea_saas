<?php
require_once __DIR__.'/../services/FinancialWorkflowProgress.php';

require_once __DIR__ . '/../controllers/NotificationController.php';
require_once __DIR__ . '/../services/UrgentDisbursementService.php';
require_once __DIR__ . '/../services/FebCategoryMigrationService.php';
require_once __DIR__ . '/../services/ChantierPauseService.php';

require_once __DIR__.'/../services/RhFinancialPointService.php';

class EmployeeLegalRequestManager
{
    private static function fitTitle(string $title): string
    {
        $title=trim($title);
        return function_exists('mb_substr')?mb_substr($title,0,255,'UTF-8'):substr($title,0,255);
    }

    private function activeFebScopeSql(): string
    {
        $paused = ChantierPauseService::storageExists($this->pdo) ? " AND NOT EXISTS(SELECT 1 FROM finea_chantier_pauses p_active_scope WHERE p_active_scope.chantier_id=CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.chantier_id')) AS UNSIGNED) AND p_active_scope.resumed_at IS NULL AND (p_active_scope.resume_at IS NULL OR p_active_scope.resume_at>NOW()))" : '';
        return "(request_type <> 'feb' OR (NOT EXISTS(SELECT 1 FROM chantier_banamur c_active_scope WHERE c_active_scope.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.chantier_id')) AS UNSIGNED) AND c_active_scope.archived_at IS NOT NULL)$paused))";
    }
    private PDO $pdo;

    private static bool $notifDefaultsEnsured = false;
    private static bool $approvedFebCodesBackfilled = false;

    private const STATUS_SUBMITTED = 'submitted';
    private const STATUS_DETAILS_REQUESTED = 'details_requested';
    private const STATUS_APPROVED = 'approved';
    private const STATUS_REJECTED = 'rejected';
    private const STATUS_CANCELLED = 'cancelled';

    private const CLAIM_STAGE_RH_PENDING = 'rh_pending';
    private const CLAIM_STAGE_DRH_PENDING = 'drh_pending';
    private const CLAIM_STAGE_DG_PENDING = 'dg_pending';
    private const CLAIM_STAGE_APPROVED = 'approved';
    private const CLAIM_STAGE_REJECTED = 'rejected';
    private const CLAIM_STAGE_CANCELLED = 'cancelled';

    private const TEAM_RH = 'rh';
    private const TEAM_FINANCE = 'finance';

    private static function usesApprovalCircuit(string $type): bool
    {
        return in_array($type, ['reclamation_salariale', 'recrutement'], true);
    }

    private const REQUEST_TYPES = [
        'permission_absence' => ['label' => 'Permission d\'absence', 'team' => self::TEAM_RH],
        'conge' => ['label' => 'Demande de conges', 'team' => self::TEAM_RH],
        'retard' => ['label' => 'Declaration de retard', 'team' => self::TEAM_RH],
        'pret' => ['label' => 'Prêt', 'team' => self::TEAM_RH],
        'avance_salaire' => ['label' => 'Avance sur salaire', 'team' => self::TEAM_RH],
        'heures_supp' => ['label' => 'Heures supplémentaires', 'team' => self::TEAM_RH],
        'reclamation_salariale' => ['label' => 'Réclamation salariale', 'team' => self::TEAM_RH],
        'regularisation_pointage' => ['label' => 'Régularisation pointage', 'team' => self::TEAM_RH],
        'recrutement' => ['label' => 'Demande de recrutement', 'team' => self::TEAM_RH],
        'feb' => ['label' => 'FEB (Fiche d\'expression de besoins)', 'team' => self::TEAM_FINANCE],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureSupportTables();
    }

    public function getRequestTypes(): array
    {
        return self::REQUEST_TYPES;
    }

    public function getTypeDefinition(string $type): ?array
    {
        return self::REQUEST_TYPES[$type] ?? null;
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return array_key_exists($type, self::REQUEST_TYPES) ? $type : '';
    }

    public static function getSalaryClaimObjectOptions(): array
    {
        return [
            'salaire_non_percu' => 'Salaire non perçu',
            'ecart_montant' => 'Écart sur le montant',
            'heures_supp_non_payees' => 'Heures supplémentaires non payées',
            'primes_non_versees' => 'Primes non versées',
        ];
    }

    public static function getSalaryClaimStageLabels(): array
    {
        return [
            self::CLAIM_STAGE_RH_PENDING => 'En attente RH',
            self::CLAIM_STAGE_DRH_PENDING => 'En attente DRH',
            self::CLAIM_STAGE_DG_PENDING => 'En attente DG',
            self::CLAIM_STAGE_APPROVED => 'Approuvée',
            self::CLAIM_STAGE_REJECTED => 'Refusée',
            self::CLAIM_STAGE_CANCELLED => 'Annulée',
        ];
    }

    public static function getRequestStatusMeta(array $request): array
    {
        $status = strtolower(trim((string) ($request['status'] ?? '')));
        $type = strtolower(trim((string) ($request['request_type'] ?? '')));

        if (self::usesApprovalCircuit($type)) {
            $stage = self::normalizeSalaryClaimStage((string) ($request['current_approval_stage'] ?? ''), $status);
            $labels = self::getSalaryClaimStageLabels();
            $map = [
                self::CLAIM_STAGE_RH_PENDING => ['class' => 'bg-amber-100 text-amber-800 border-amber-200', 'pending_role' => 'rh'],
                self::CLAIM_STAGE_DRH_PENDING => ['class' => 'bg-sky-100 text-sky-800 border-sky-200', 'pending_role' => 'drh'],
                self::CLAIM_STAGE_DG_PENDING => ['class' => 'bg-violet-100 text-violet-800 border-violet-200', 'pending_role' => 'dg'],
                self::CLAIM_STAGE_APPROVED => ['class' => 'bg-emerald-100 text-emerald-800 border-emerald-200', 'pending_role' => ''],
                self::CLAIM_STAGE_REJECTED => ['class' => 'bg-rose-100 text-rose-800 border-rose-200', 'pending_role' => ''],
                self::CLAIM_STAGE_CANCELLED => ['class' => 'bg-slate-100 text-slate-700 border-slate-200', 'pending_role' => ''],
            ];
            $meta = $map[$stage] ?? $map[self::CLAIM_STAGE_RH_PENDING];

            return [
                'status' => $status,
                'stage' => $stage,
                'label' => (string) ($labels[$stage] ?? ($labels[self::CLAIM_STAGE_RH_PENDING] ?? 'En attente RH')),
                'class' => (string) ($meta['class'] ?? 'bg-slate-100 text-slate-700 border-slate-200'),
                'pending_role' => (string) ($meta['pending_role'] ?? ''),
                'is_pending' => in_array($stage, [self::CLAIM_STAGE_RH_PENDING, self::CLAIM_STAGE_DRH_PENDING, self::CLAIM_STAGE_DG_PENDING], true),
            ];
        }

        $map = [
            self::STATUS_SUBMITTED => ['label' => 'En attente', 'class' => 'bg-amber-100 text-amber-800 border-amber-200'],
            self::STATUS_DETAILS_REQUESTED => ['label' => 'Compléments demandés', 'class' => 'bg-sky-100 text-sky-800 border-sky-200'],
            self::STATUS_APPROVED => ['label' => 'Approuvée', 'class' => 'bg-emerald-100 text-emerald-800 border-emerald-200'],
            self::STATUS_REJECTED => ['label' => 'Refusée', 'class' => 'bg-rose-100 text-rose-800 border-rose-200'],
            self::STATUS_CANCELLED => ['label' => 'Annulée', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'],
        ];
        $meta = $map[$status] ?? ['label' => $status !== '' ? $status : '—', 'class' => 'bg-slate-100 text-slate-700 border-slate-200'];

        return [
            'status' => $status,
            'stage' => $status,
            'label' => (string) $meta['label'],
            'class' => (string) $meta['class'],
            'pending_role' => '',
            'is_pending' => in_array($status, [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED], true),
        ];
    }

    public function canRequesterEditRequest(array $request, int $requesterUserId): bool
    {
        if (!empty($request['payload']['rh_financial_point_id'])) return false;
        if (RhFinancialPointService::managedRequest($this->pdo,$request)) {
            $q=$this->pdo->prepare('SELECT 1 FROM rh_financial_source_claims WHERE source_id=? AND source_kind IN (\'advance\',\'loan\')');
            $q->execute([$request['id']]); if ($q->fetchColumn()) return false;
        }
        if ((int) ($request['requester_user_id'] ?? 0) !== $requesterUserId) {
            return false;
        }

        if (self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            $statusMeta = self::getRequestStatusMeta($request);
            return (string) ($request['status'] ?? '') === self::STATUS_SUBMITTED
                && (string) ($statusMeta['stage'] ?? '') === self::CLAIM_STAGE_RH_PENDING;
        }

        $status = (string) ($request['status'] ?? '');
        if (($request['request_type'] ?? '') === 'feb') {
            return in_array($status, [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED], true);
        }

        return $status === self::STATUS_SUBMITTED;
    }

    public function canActorApproveRequest(array $request, array $actor): bool
    {
        if (self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            if ((string) ($request['status'] ?? '') !== self::STATUS_SUBMITTED) {
                return false;
            }

            $role = $this->normalizeApprovalRole((string) ($actor['approval_role'] ?? ''));
            $expectedRole = $this->getExpectedApprovalRole($request);
            if (($request['request_type'] ?? '') === 'recrutement' && $expectedRole === 'rh' && $role === 'drh') {
                $role = 'rh';
            }
            if ($role === '' || $expectedRole === '' || $role !== $expectedRole) {
                return false;
            }

            return trim((string) ($request[$role . '_signature_path'] ?? '')) !== '';
        }

        return (string) ($request['status'] ?? '') === self::STATUS_SUBMITTED && $this->normalizeApprovalRole((string) ($actor['approval_role'] ?? 'rh')) === 'rh';
    }

    public function canActorRejectRequest(array $request, array $actor): bool
    {
        if (self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            if ((string) ($request['status'] ?? '') !== self::STATUS_SUBMITTED) {
                return false;
            }

            $role = $this->normalizeApprovalRole((string) ($actor['approval_role'] ?? ''));
            $expectedRole = $this->getExpectedApprovalRole($request);
            if (($request['request_type'] ?? '') === 'recrutement' && $expectedRole === 'rh' && $role === 'drh') {
                $role = 'rh';
            }
            return $role !== '' && $role === $expectedRole;
        }

        return (string) ($request['status'] ?? '') === self::STATUS_SUBMITTED && $this->normalizeApprovalRole((string) ($actor['approval_role'] ?? 'rh')) === 'rh';
    }

    public function canActorSaveSignature(array $request, string $role, array $actor): bool
    {
        $role = $this->normalizeSignatureRole($role);
        if ($role === '') {
            return false;
        }

        if (!self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            return false;
        }

        if (in_array((string) ($request['status'] ?? ''), [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        if ($role === 'employee') {
            return (int) ($request['requester_user_id'] ?? 0) === (int) ($actor['user_id'] ?? 0);
        }

        $actorRole = $this->normalizeApprovalRole((string) ($actor['approval_role'] ?? ''));
        if (($request['request_type'] ?? '') === 'recrutement' && $role === 'rh' && $actorRole === 'drh') {
            $actorRole = 'rh';
        }
        return $role === $this->getExpectedApprovalRole($request) && $role === $actorRole;
    }

    public function getExpectedApprovalRole(array $request): string
    {
        if (!self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            return (string) (($request['status'] ?? '') === self::STATUS_SUBMITTED ? 'rh' : '');
        }

        $stage = self::getRequestStatusMeta($request)['stage'] ?? '';
        if (($request['request_type'] ?? '') === 'recrutement') {
            return match ($stage) {
                self::CLAIM_STAGE_RH_PENDING => 'rh',
                self::CLAIM_STAGE_DG_PENDING => 'dg',
                default => '',
            };
        }
        return match ($stage) {
            self::CLAIM_STAGE_RH_PENDING => 'rh',
            self::CLAIM_STAGE_DRH_PENDING => 'drh',
            self::CLAIM_STAGE_DG_PENDING => 'dg',
            default => '',
        };
    }

    public function getLeaveSettings(): array
    {
        $defaults = [
            'require_opening_balance_for_existing_staff' => '1',
            'leave_module_start_date' => date('Y-m-d'),
            'default_existing_staff_policy' => 'block_until_initialized',
            'deduct_pending_leave_requests' => '0',
            'monthly_accrual_days' => '2.2',
            'minimum_seniority_months' => '12',
        ];

        try {
            $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM rh_leave_settings');
            foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
                $key = (string) ($row['setting_key'] ?? '');
                if ($key !== '' && array_key_exists($key, $defaults)) {
                    $defaults[$key] = (string) ($row['setting_value'] ?? $defaults[$key]);
                }
            }
        } catch (Throwable $e) {
        }

        if (!in_array($defaults['default_existing_staff_policy'], ['block_until_initialized', 'allow_with_hr_validation', 'calculate_from_hire_date'], true)) {
            $defaults['default_existing_staff_policy'] = 'block_until_initialized';
        }

        return $defaults;
    }

    public function saveLeaveSettings(array $settings, int $userId): void
    {
        $current = $this->getLeaveSettings();
        $next = [
            'require_opening_balance_for_existing_staff' => !empty($settings['require_opening_balance_for_existing_staff']) ? '1' : '0',
            'leave_module_start_date' => $this->normalizeDateValue((string) ($settings['leave_module_start_date'] ?? $current['leave_module_start_date']), date('Y-m-d')),
            'default_existing_staff_policy' => in_array((string) ($settings['default_existing_staff_policy'] ?? ''), ['block_until_initialized', 'allow_with_hr_validation', 'calculate_from_hire_date'], true)
                ? (string) $settings['default_existing_staff_policy']
                : $current['default_existing_staff_policy'],
            'deduct_pending_leave_requests' => !empty($settings['deduct_pending_leave_requests']) ? '1' : '0',
            'monthly_accrual_days' => (string) max(0.0, (float) str_replace(',', '.', (string) ($settings['monthly_accrual_days'] ?? $current['monthly_accrual_days']))),
            'minimum_seniority_months' => (string) max(0, (int) ($settings['minimum_seniority_months'] ?? $current['minimum_seniority_months'])),
        ];

        $stmt = $this->pdo->prepare('
            INSERT INTO rh_leave_settings (setting_key, setting_value, updated_by, updated_at)
            VALUES (:setting_key, :setting_value, :updated_by, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = NOW()
        ');
        foreach ($next as $key => $value) {
            $stmt->execute([
                ':setting_key' => $key,
                ':setting_value' => $value,
                ':updated_by' => $userId > 0 ? $userId : null,
            ]);
        }
    }

    public function getLeaveOpeningBalance(int $personnelId): ?array
    {
        if ($personnelId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM rh_leave_opening_balance WHERE personnel_id = :personnel_id LIMIT 1');
        $stmt->execute([':personnel_id' => $personnelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listLeaveOpeningBalances(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query('
            SELECT b.*, p.nom_personnel_soignant AS personnel_name, p.matricule_personnel_soignant AS matricule
            FROM rh_leave_opening_balance b
            LEFT JOIN personnel p ON p.id_personnel_soignant = b.personnel_id
            ORDER BY b.updated_at DESC, b.id DESC
            LIMIT ' . (int) $limit
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function saveLeaveOpeningBalance(array $data, int $userId): int
    {
        $personnelId = (int) ($data['personnel_id'] ?? 0);
        if ($personnelId <= 0) {
            throw new RuntimeException('Employe invalide.');
        }

        $referenceDate = $this->normalizeDateValue((string) ($data['reference_date'] ?? ''), '');
        if ($referenceDate === '') {
            throw new RuntimeException('La date de reference est obligatoire.');
        }

        $payload = [
            ':personnel_id' => $personnelId,
            ':reference_date' => $referenceDate,
            ':last_leave_start_date' => $this->nullableDateValue((string) ($data['last_leave_start_date'] ?? '')),
            ':last_leave_end_date' => $this->nullableDateValue((string) ($data['last_leave_end_date'] ?? '')),
            ':last_return_date' => $this->nullableDateValue((string) ($data['last_return_date'] ?? '')),
            ':accrued_days_opening' => (float) str_replace(',', '.', (string) ($data['accrued_days_opening'] ?? 0)),
            ':consumed_days_opening' => (float) str_replace(',', '.', (string) ($data['consumed_days_opening'] ?? 0)),
            ':balance_days_opening' => (float) str_replace(',', '.', (string) ($data['balance_days_opening'] ?? 0)),
            ':comment' => trim((string) ($data['comment'] ?? '')),
            ':created_by' => $userId > 0 ? $userId : null,
        ];

        $stmt = $this->pdo->prepare('
            INSERT INTO rh_leave_opening_balance (
                personnel_id, reference_date, last_leave_start_date, last_leave_end_date, last_return_date,
                accrued_days_opening, consumed_days_opening, balance_days_opening, comment, created_by,
                created_at, updated_at
            ) VALUES (
                :personnel_id, :reference_date, :last_leave_start_date, :last_leave_end_date, :last_return_date,
                :accrued_days_opening, :consumed_days_opening, :balance_days_opening, :comment, :created_by,
                NOW(), NOW()
            )
            ON DUPLICATE KEY UPDATE
                reference_date = VALUES(reference_date),
                last_leave_start_date = VALUES(last_leave_start_date),
                last_leave_end_date = VALUES(last_leave_end_date),
                last_return_date = VALUES(last_return_date),
                accrued_days_opening = VALUES(accrued_days_opening),
                consumed_days_opening = VALUES(consumed_days_opening),
                balance_days_opening = VALUES(balance_days_opening),
                comment = VALUES(comment),
                updated_at = NOW()
        ');
        $stmt->execute($payload);

        $existing = $this->getLeaveOpeningBalance($personnelId);
        return (int) ($existing['id'] ?? 0);
    }

    public function calculateLeaveSituation(int $personnelId, ?string $hireDate, int $excludeRequestId = 0, ?int $requesterUserId = null): array
    {
        $settings = $this->getLeaveSettings();
        $moduleStart = $this->normalizeDateValue((string) ($settings['leave_module_start_date'] ?? ''), date('Y-m-d'));
        $hireDate = $this->normalizeDateValue((string) $hireDate, '');
        $opening = $this->getLeaveOpeningBalance($personnelId);
        $today = date('Y-m-d');
        $source = 'missing_opening_balance';
        $referenceDate = $moduleStart;
        $initialBalance = 0.0;
        $canRequest = true;
        $requiresSpecialValidation = false;
        $message = '';
        $minimumMonths = max(0, (int) ($settings['minimum_seniority_months'] ?? 12));
        $monthlyAccrual = max(0.0, (float) str_replace(',', '.', (string) ($settings['monthly_accrual_days'] ?? '2.2')));

        if ($opening) {
            $source = 'opening_balance';
            $referenceDate = $this->normalizeDateValue((string) ($opening['reference_date'] ?? ''), $moduleStart);
            $initialBalance = (float) ($opening['balance_days_opening'] ?? 0);
            $message = 'Calcul base sur le solde initial valide par les RH.';
        } else {
            $isExistingStaff = $hireDate === '' || $hireDate < $moduleStart;
            $policy = (string) ($settings['default_existing_staff_policy'] ?? 'block_until_initialized');
            if ($isExistingStaff && !empty($settings['require_opening_balance_for_existing_staff']) && $policy !== 'calculate_from_hire_date') {
                $canRequest = $policy === 'allow_with_hr_validation';
                $requiresSpecialValidation = $policy === 'allow_with_hr_validation';
                $message = $canRequest
                    ? 'Situation conge non initialisee: la demande sera signalee pour verification RH speciale.'
                    : 'Votre situation de conge doit etre initialisee par les RH avant toute demande. Veuillez contacter le service RH.';
            } elseif ($isExistingStaff && $policy === 'block_until_initialized') {
                $canRequest = false;
                $message = 'Votre situation de conge doit etre initialisee par les RH avant toute demande. Veuillez contacter le service RH.';
            } elseif ($isExistingStaff && $policy === 'allow_with_hr_validation') {
                $requiresSpecialValidation = true;
                $message = 'Situation conge non initialisee: la demande sera signalee pour verification RH speciale.';
            } else {
                $source = 'hire_date';
                $referenceDate = $hireDate !== '' ? max($hireDate, $moduleStart) : $moduleStart;
                $message = 'Calcul base sur la date de recrutement/prise de service.';
            }
        }

        $seniorityMonths = $hireDate !== '' ? $this->monthDiff($hireDate, $today) : 0;
        if ($source === 'hire_date' && $seniorityMonths < $minimumMonths) {
            $canRequest = false;
            $message = 'Anciennete insuffisante pour une demande de conge (' . $minimumMonths . ' mois minimum).';
        }

        $accruedDays = $canRequest || $source !== 'missing_opening_balance'
            ? round($this->monthDiff($referenceDate, $today) * $monthlyAccrual, 2)
            : 0.0;

        $deductStatuses = ['approved'];
        if (!empty($settings['deduct_pending_leave_requests'])) {
            $deductStatuses[] = 'submitted';
        }
        $consumed = $this->sumLeaveRequestDays($personnelId, $referenceDate, $deductStatuses, $excludeRequestId, $requesterUserId);
        $balance = round($initialBalance + $accruedDays - $consumed, 2);

        return [
            'source' => $source,
            'can_request' => $canRequest,
            'requires_special_validation' => $requiresSpecialValidation,
            'message' => $message,
            'reference_date' => $referenceDate,
            'hire_date' => $hireDate,
            'module_start_date' => $moduleStart,
            'initial_balance_days' => round($initialBalance, 2),
            'accrued_days' => $accruedDays,
            'consumed_days' => round($consumed, 2),
            'balance_days' => $balance,
            'opening_balance_id' => (int) ($opening['id'] ?? 0),
            'settings' => $settings,
        ];
    }

    public function createRequest(
        int $requesterUserId,
        array $requester,
        string $type,
        array $payload,
        ?string $title = null
    ): int {
        $type = $this->normalizeType($type);
        if ($type === '') {
            throw new InvalidArgumentException('Type de demande invalide');
        }

        $definition = self::REQUEST_TYPES[$type];
        $team = (string) ($definition['team'] ?? self::TEAM_RH);
        $label = (string) ($definition['label'] ?? $type);

        if ($type === 'feb') {
            $payload = FebCategoryMigrationService::normalizePayload($payload);
            (new \UrgentDisbursementService($this->pdo))->assertChantierAvailable(
                (int)($payload['chantier_id'] ?? 0),
                (int)($payload['urgent_decaissement_id'] ?? 0)
            );
        }

        $title = trim((string) $title);
        if ($title === '') {
            $title = $label;
        }
        $title=self::fitTitle($title);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new RuntimeException('Impossible de sérialiser la demande');
        }

        $metaServiceId = null;
        if ($type === 'feb') {
            $candidate = (int) ($payload['departement_id'] ?? 0);
            if ($candidate > 0) {
                $metaServiceId = $candidate;
            }
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO employee_legal_requests (
                requester_user_id,
                requester_name,
                requester_email,
                request_type,
                assigned_team,
                title,
                payload_json,
                meta_service_id,
                current_approval_stage,
                status,
                submitted_at
            ) VALUES (
                :requester_user_id,
                :requester_name,
                :requester_email,
                :request_type,
                :assigned_team,
                :title,
                :payload_json,
                :meta_service_id,
                :current_approval_stage,
                :status,
                NOW()
            )
        ');

        $stmt->execute([
            'requester_user_id' => $requesterUserId,
            'requester_name' => (string) ($requester['name'] ?? ''),
            'requester_email' => (string) ($requester['email'] ?? ''),
            'request_type' => $type,
            'assigned_team' => $team,
            'title' => $title,
            'payload_json' => $payloadJson,
            'meta_service_id' => $metaServiceId,
            'current_approval_stage' => self::usesApprovalCircuit($type) ? self::CLAIM_STAGE_RH_PENDING : null,
            'status' => self::STATUS_SUBMITTED,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->deleteStaleLegacyEventsForFreshRequest($id);
        $this->addEvent($id, 'submitted', 'Soumise');

        try {
            $notif = $this->getNotificationController();
            $notif->notifyLegalRequestSubmitted(
                $id,
                $type,
                $label,
                $team,
                (string) ($requester['name'] ?? ''),
                [
                    'title' => $title,
                    'payload' => $payload,
                ]
            );
        } catch (Throwable $e) {
            // Ne pas bloquer la création de la demande si les notifications échouent.
        }

        return $id;
    }

    public function updateSubmittedRequest(
        int $requestId,
        int $requesterUserId,
        array $requester,
        string $type,
        array $payload,
        ?string $title = null
    ): bool {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        if ((int) ($request['requester_user_id'] ?? 0) !== $requesterUserId) {
            return false;
        }

        if (!$this->canRequesterEditRequest($request, $requesterUserId)) {
            return false;
        }

        $type = $this->normalizeType($type);
        if ($type === '' || $type !== (string) ($request['request_type'] ?? '')) {
            return false;
        }

        $definition = self::REQUEST_TYPES[$type] ?? null;
        if (!is_array($definition)) {
            return false;
        }
        if ($type === 'feb') {
            $payload = FebCategoryMigrationService::normalizePayload($payload);
            (new \UrgentDisbursementService($this->pdo))->assertChantierAvailable(
                (int)($payload['chantier_id'] ?? 0),
                (int)($payload['urgent_decaissement_id'] ?? 0)
            );
        }

        $label = (string) ($definition['label'] ?? $type);
        $team = (string) ($definition['team'] ?? self::TEAM_RH);

        $title = trim((string) $title);
        if ($title === '') {
            $title = (string) ($request['title'] ?? $label);
        }
        $title=self::fitTitle($title);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new RuntimeException('Impossible de sérialiser la demande');
        }

        $metaServiceId = null;
        if ($type === 'feb') {
            $candidate = (int) ($payload['departement_id'] ?? 0);
            if ($candidate > 0) {
                $metaServiceId = $candidate;
            }
        } else {
            $metaServiceId = isset($request['meta_service_id']) ? (int) $request['meta_service_id'] : null;
        }

        $resetToSubmitted = ($type === 'feb' && (string) ($request['status'] ?? '') === self::STATUS_DETAILS_REQUESTED);
        $sql = '
            UPDATE employee_legal_requests
            SET title = :title,
                payload_json = :payload_json,
                meta_service_id = :meta_service_id,
                updated_at = NOW()';
        if ($resetToSubmitted) {
            $sql .= ',
                status = :status,
                submitted_at = NOW(),
                decided_at = NULL,
                decided_by_user_id = NULL,
                decided_by_name = NULL,
                decision_note = ""';
        }
        $sql .= '
            WHERE id = :id
            LIMIT 1
        ';
        if (RhFinancialPointService::managedRequest($this->pdo,$request)) {
            $sql=str_replace('LIMIT 1', "AND NOT EXISTS(SELECT 1 FROM rh_financial_source_claims fc WHERE fc.source_id=employee_legal_requests.id AND fc.source_kind IN ('advance','loan')) LIMIT 1",$sql);
        }
        $stmt = $this->pdo->prepare($sql);

        $updateParameters = [
            'title' => $title,
            'payload_json' => $payloadJson,
            'meta_service_id' => $metaServiceId,
            'id' => $requestId,
        ];
        if ($resetToSubmitted) $updateParameters['status'] = self::STATUS_SUBMITTED;
        $ok = $stmt->execute($updateParameters);

        if (!$ok || (RhFinancialPointService::managedRequest($this->pdo,$request) && $stmt->rowCount()!==1)) {
            return false;
        }

        $this->addEvent($requestId, 'updated', 'Demande modifiée puis renvoyée pour validation.', [
            'user_id' => $requesterUserId,
            'name' => (string) ($requester['name'] ?? ''),
        ]);

        try {
            $notif = $this->getNotificationController();
            $notif->notifyLegalRequestSubmitted(
                $requestId,
                $type,
                $label,
                $team,
                (string) ($requester['name'] ?? ''),
                [
                    'title' => $title,
                    'payload' => $payload,
                ],
                true
            );
        } catch (Throwable $e) {
            // Ne pas bloquer la modification si les notifications échouent.
        }

        return true;
    }

    public function listRequestsForUser(int $userId, int $limit = 50, bool $includeLegacyFeb = false): array
    {
        $limit = max(1, min(200, $limit));

        $legacyFilter = $includeLegacyFeb ? '' : ' AND (request_type <> "feb" OR COALESCE(meta_legacy_source, "") <> "feb_legacy")';
        $stmt = $this->pdo->prepare('
            SELECT *
            FROM employee_legal_requests
            WHERE requester_user_id = :uid
              AND ' . $this->activeFebScopeSql() . '
              ' . $legacyFilter . '
            ORDER BY submitted_at DESC, id DESC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->execute(['uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateRequestRow'], $rows);
    }

    public function updateFebBeforeDecision(int $requestId, array $payload, array $actor, string $note = '', bool $allowReconciliation = false): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request || (string) ($request['request_type'] ?? '') !== 'feb') {
            return false;
        }
        if (!empty($request['payload']['rh_financial_point_id'])) throw new DomainException('Ce point RH est signé. Demandez sa réouverture aux RH pour le modifier.');
        if (!$allowReconciliation && (string) ($request['status'] ?? '') !== self::STATUS_SUBMITTED) {
            return false;
        }
        if ($allowReconciliation && !in_array((string) ($request['status'] ?? ''), [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) return false;

        $payload = FebCategoryMigrationService::normalizePayload($payload);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            throw new RuntimeException('Impossible de serialiser la FEB');
        }

        $title = trim((string) ($payload['description'] ?? ''));
        if ($title === '') {
            $title = (string) ($request['title'] ?? 'FEB');
        }
        $title=self::fitTitle($title);

        $metaServiceId = (int) ($payload['departement_id'] ?? ($request['meta_service_id'] ?? 0));
        $stmt = $this->pdo->prepare('
            UPDATE employee_legal_requests
            SET title = :title,
                payload_json = :payload_json,
                meta_service_id = :meta_service_id,
                updated_at = NOW()
            WHERE id = :id
              AND request_type = "feb"
              ' . ($allowReconciliation ? '' : 'AND status = "submitted"') . '
            LIMIT 1
        ');
        $ownsTransaction = !$this->pdo->inTransaction();
        try {
            if ($ownsTransaction) $this->pdo->beginTransaction();
            $ok = $stmt->execute([
                'title' => $title,
                'payload_json' => $payloadJson,
                'meta_service_id' => $metaServiceId > 0 ? $metaServiceId : null,
                'id' => $requestId,
            ]);
            if (!$ok) {
                if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
                return false;
            }
            $oldPayloadJson = json_encode((array)($request['payload'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $revision = $this->pdo->prepare('INSERT INTO feb_payload_revision_history
                (feb_request_id,old_payload_json,new_payload_json,change_summary,changed_by_user_id,changed_by_name)
                VALUES(:feb,:old_payload,:new_payload,:summary,:user_id,:user_name)');
            $revision->execute([
                'feb'=>$requestId,'old_payload'=>$oldPayloadJson,'new_payload'=>$payloadJson,
                'summary'=>trim($note),'user_id'=>(int)($actor['user_id']??0)?:null,
                'user_name'=>trim((string)($actor['name']??''))?:null,
            ]);
            $this->addEvent($requestId, 'feb_updated_by_validator', trim($note) !== '' ? trim($note) : 'FEB ajustee avant decision.', $actor);
            if ($ownsTransaction) $this->pdo->commit();
            return true;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function getRequestById(int $requestId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM employee_legal_requests WHERE id = :id AND ' . $this->activeFebScopeSql() . ' LIMIT 1');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrateRequestRow($row) : null;
    }

    public function listRequestsByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $v): bool {
            return $v > 0;
        })));

        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare('SELECT * FROM employee_legal_requests WHERE id IN (' . $placeholders . ') AND ' . $this->activeFebScopeSql());
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byId = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id'])) {
                $byId[(int) $row['id']] = $this->hydrateRequestRow($row);
            }
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    public function listRequestsForTeam(string $team, array $statuses = [self::STATUS_SUBMITTED], int $limit = 200): array
    {
        $team = strtolower(trim($team));
        if (!in_array($team, [self::TEAM_RH, self::TEAM_FINANCE], true)) {
            throw new InvalidArgumentException('Équipe invalide');
        }

        $limit = max(1, min(500, $limit));
        $statuses = array_values(array_filter(array_map('strtolower', $statuses), static function ($s): bool {
            return in_array($s, [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
        }));

        if (!$statuses) {
            $statuses = [self::STATUS_SUBMITTED];
        }

        $placeholders = [];
        $params = ['team' => $team];
        foreach ($statuses as $idx => $status) {
            $key = ':s' . $idx;
            $placeholders[] = $key;
            $params[$key] = $status;
        }

        $sql = '
            SELECT *
            FROM employee_legal_requests
            WHERE assigned_team = :team
              AND status IN (' . implode(', ', $placeholders) . ')
              AND ' . $this->activeFebScopeSql() . '
            ORDER BY submitted_at DESC, id DESC
            LIMIT ' . (int) $limit . '
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateRequestRow'], $rows);
    }

    /**
     * Iterate the complete authorized team queue in bounded SQL batches.
     * Consumers must apply their tab-specific filters before paginating results.
     * The iterator never changes request state.
     *
     * @return Generator<int, array>
     */
    public function iterateRequestsForTeamAdvanced(
        string $team,
        array $statuses = [self::STATUS_SUBMITTED],
        ?string $type = null,
        ?array $serviceIds = null,
        bool $includeLegacyFeb = false
    ): Generator {
        $offset = 0;
        $batchSize = 500;
        do {
            $batch = $this->listRequestsForTeamAdvancedPage(
                $team, $statuses, $type, $serviceIds, $batchSize, $offset, $includeLegacyFeb
            );
            foreach ($batch as $row) {
                yield $row;
            }
            $offset += count($batch);
        } while (count($batch) === $batchSize);
    }

    /** @return array<int, array> */
    public function listRequestsForTeamAdvancedPage(
        string $team,
        array $statuses,
        ?string $type,
        ?array $serviceIds,
        int $limit,
        int $offset,
        bool $includeLegacyFeb = false
    ): array {
        $team = strtolower(trim($team));
        if (!in_array($team, [self::TEAM_RH, self::TEAM_FINANCE], true)) {
            throw new InvalidArgumentException('Équipe invalide');
        }
        $type = $type !== null ? $this->normalizeType($type) : null;
        if ($type === '') $type = null;
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $allowed = [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED];
        $statuses = array_values(array_unique(array_intersect(array_map('strtolower', $statuses), $allowed)));
        if (!$statuses) $statuses = [self::STATUS_SUBMITTED];
        $params = ['team' => $team];
        $where = ['assigned_team = :team'];
        $statusKeys = [];
        foreach ($statuses as $i => $status) {
            $key = ':s' . $i;
            $statusKeys[] = $key;
            $params[$key] = $status;
        }
        $where[] = 'status IN (' . implode(', ', $statusKeys) . ')';
        if ($type !== null) {
            $where[] = 'request_type = :rtype';
            $params['rtype'] = $type;
        }
        if (!$includeLegacyFeb) {
            $where[] = '(request_type <> "feb" OR COALESCE(meta_legacy_source, "") <> "feb_legacy")';
        }
        if (is_array($serviceIds)) {
            $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static fn(int $id): bool => $id > 0)));
            if (!$serviceIds) {
                $where[] = '1 = 0';
            } else {
                $keys = [];
                foreach ($serviceIds as $i => $id) {
                    $key = ':ms' . $i;
                    $keys[] = $key;
                    $params[$key] = $id;
                }
                $where[] = 'meta_service_id IN (' . implode(', ', $keys) . ')';
            }
        }
        $where[] = $this->activeFebScopeSql();
        $sql = 'SELECT * FROM employee_legal_requests WHERE ' . implode(' AND ', $where)
            . ' ORDER BY submitted_at DESC, id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrateRequestRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function listRequestsForTeamAdvanced(
        string $team,
        array $statuses = [self::STATUS_SUBMITTED],
        ?string $type = null,
        ?array $serviceIds = null,
        int $limit = 200,
        bool $includeLegacyFeb = false
    ): array {
        $team = strtolower(trim($team));
        if (!in_array($team, [self::TEAM_RH, self::TEAM_FINANCE], true)) {
            throw new InvalidArgumentException('Équipe invalide');
        }

        $type = $type !== null ? $this->normalizeType($type) : null;
        if ($type !== null && $type === '') {
            $type = null;
        }

        $limit = max(1, min(500, $limit));

        $statuses = array_values(array_filter(array_map('strtolower', $statuses), static function ($s): bool {
            return in_array($s, [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
        }));
        if (!$statuses) {
            $statuses = [self::STATUS_SUBMITTED];
        }

        $params = ['team' => $team];
        $where = ['assigned_team = :team'];

        $placeholders = [];
        foreach ($statuses as $idx => $status) {
            $key = ':s' . $idx;
            $placeholders[] = $key;
            $params[$key] = $status;
        }
        $where[] = 'status IN (' . implode(', ', $placeholders) . ')';

        if ($type !== null) {
            $where[] = 'request_type = :rtype';
            $params['rtype'] = $type;
        }

        if (!$includeLegacyFeb) {
            $where[] = '(request_type <> "feb" OR COALESCE(meta_legacy_source, "") <> "feb_legacy")';
        }

        if (is_array($serviceIds)) {
            $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static function (int $v): bool {
                return $v > 0;
            })));
            if ($serviceIds) {
                $in = [];
                foreach ($serviceIds as $idx => $sid) {
                    $k = ':ms' . $idx;
                    $in[] = $k;
                    $params[$k] = $sid;
                }
                $where[] = 'meta_service_id IN (' . implode(', ', $in) . ')';
            } else {
                $where[] = '1 = 0';
            }
        }

        $where[]=$this->activeFebScopeSql();
        $sql = '
            SELECT *
            FROM employee_legal_requests
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY submitted_at DESC, id DESC
            LIMIT ' . (int) $limit . '
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'hydrateRequestRow'], $rows);
    }

    public function countRequestsForTeam(
        string $team,
        ?string $type = null,
        ?array $serviceIds = null,
        bool $includeLegacyFeb = false
    ): array {
        $team = strtolower(trim($team));
        if (!in_array($team, [self::TEAM_RH, self::TEAM_FINANCE], true)) {
            throw new InvalidArgumentException('Équipe invalide');
        }

        $type = $type !== null ? $this->normalizeType($type) : null;
        if ($type !== null && $type === '') {
            $type = null;
        }

        $params = ['team' => $team];
        $where = ['assigned_team = :team',$this->activeFebScopeSql()];

        if ($type !== null) {
            $where[] = 'request_type = :rtype';
            $params['rtype'] = $type;
        }

        if (!$includeLegacyFeb) {
            $where[] = '(request_type <> "feb" OR COALESCE(meta_legacy_source, "") <> "feb_legacy")';
        }

        if (is_array($serviceIds)) {
            $serviceIds = array_values(array_unique(array_filter(array_map('intval', $serviceIds), static function (int $v): bool {
                return $v > 0;
            })));
            if ($serviceIds) {
                $in = [];
                foreach ($serviceIds as $idx => $sid) {
                    $k = ':ms' . $idx;
                    $in[] = $k;
                    $params[$k] = $sid;
                }
                $where[] = 'meta_service_id IN (' . implode(', ', $in) . ')';
            } else {
                $where[] = '1 = 0';
            }
        }

        $sql = '
            SELECT status, COUNT(*) AS c
            FROM employee_legal_requests
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY status
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $k = strtolower(trim((string) ($r['status'] ?? '')));
            if ($k !== '') {
                $out[$k] = (int) ($r['c'] ?? 0);
            }
        }

        return $out;
    }

    public function approveRequest(int $requestId, array $actor, string $note = '', bool $sendNotifications = true, bool $allowFebReconciliation = false): bool
    {
        FinancialWorkflowProgress::step('Contrôle et enregistrement de la décision de validation.');
        $request = $this->getRequestById($requestId);
        if ($request && RhFinancialPointService::managedRequest($this->pdo,$request)) throw new DomainException('Cette demande doit être validée dans un point financier RH signé par le DG.');
        if (!empty($request['payload']['rh_financial_point_id'])) (new RhFinancialPointService($this->pdo))->assertSignedFeb($request,$this->pdo->inTransaction());
        if (!$request) {
            return false;
        }

        if (($request['request_type'] ?? '') === 'feb') {
            if (!in_array((string)($request['status'] ?? ''), [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED], true)) return false;
            $requestPayload = (array)($request['payload'] ?? []);
            (new \UrgentDisbursementService($this->pdo))->assertChantierAvailable(
                (int)($requestPayload['chantier_id'] ?? 0),
                (int)($requestPayload['urgent_decaissement_id'] ?? 0)
            );
        }

        if (self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            if (!$this->canActorApproveRequest($request, $actor)) {
                return false;
            }

            $role = $this->getExpectedApprovalRole($request);
            $note = trim($note);
            $params = [
                'approved_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
                'approved_by_name' => (string) ($actor['name'] ?? ''),
                'approval_note' => $note,
                'id' => $requestId,
            ];

            if ($role === 'rh') {
                $sql = '
                    UPDATE employee_legal_requests
                    SET current_approval_stage = :next_stage,
                        rh_approved_at = NOW(),
                        rh_approved_by_user_id = :approved_by_user_id,
                        rh_approved_by_name = :approved_by_name,
                        rh_approval_note = :approval_note,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ';
                $params['next_stage'] = ($request['request_type'] ?? '') === 'recrutement'
                    ? self::CLAIM_STAGE_DG_PENDING
                    : self::CLAIM_STAGE_DRH_PENDING;
            } elseif ($role === 'drh') {
                $sql = '
                    UPDATE employee_legal_requests
                    SET current_approval_stage = :next_stage,
                        drh_approved_at = NOW(),
                        drh_approved_by_user_id = :approved_by_user_id,
                        drh_approved_by_name = :approved_by_name,
                        drh_approval_note = :approval_note,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ';
                $params['next_stage'] = self::CLAIM_STAGE_DG_PENDING;
            } else {
                $sql = '
                    UPDATE employee_legal_requests
                    SET status = :status,
                        current_approval_stage = :current_approval_stage,
                        dg_approved_at = NOW(),
                        dg_approved_by_user_id = :approved_by_user_id,
                        dg_approved_by_name = :approved_by_name,
                        dg_approval_note = :approval_note,
                        decided_at = NOW(),
                        decided_by_user_id = :approved_by_user_id,
                        decided_by_name = :approved_by_name,
                        decision_note = :approval_note,
                        updated_at = NOW()
                    WHERE id = :id
                    LIMIT 1
                ';
                $params['status'] = self::STATUS_APPROVED;
                $params['current_approval_stage'] = self::CLAIM_STAGE_APPROVED;
            }

            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute($params);
            if ($ok) {
                $eventType = $role === 'dg' ? 'approved' : ($role . '_approved');
                $eventNote = $note !== '' ? $note : ('Validation ' . strtoupper($role));
                $this->addEvent($requestId, $eventType, $eventNote, $actor);

                if ($sendNotifications && $role === 'dg') {
                    $updatedRequest = $this->getRequestById($requestId) ?: $request;
                    try {
                        $notif = new \NotificationController($this->pdo);
                        $notif->notifyLegalRequestProcessed($updatedRequest, 'approved', $actor, $note);
                    } catch (Throwable $e) {
                    }
                }
            }

            return $ok;
        }

        $isFebReconciliation = $allowFebReconciliation
            && ($request['request_type'] ?? '') === 'feb'
            && in_array((string) ($request['status'] ?? ''), [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
        if (!$isFebReconciliation && !in_array($request['status'], [self::STATUS_SUBMITTED], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            UPDATE employee_legal_requests
            SET status = :status,
                decided_at = NOW(),
                decided_by_user_id = :decided_by_user_id,
                decided_by_name = :decided_by_name,
                decision_note = :decision_note
            WHERE id = :id AND status = :previous_status
            LIMIT 1
        ');

        $ok = $stmt->execute([
            'previous_status' => (string)$request['status'],
            'status' => self::STATUS_APPROVED,
            'decided_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            'decided_by_name' => (string) ($actor['name'] ?? ''),
            'decision_note' => trim($note),
            'id' => $requestId,
        ]);

        $ok = $ok && $stmt->rowCount() === 1;
        if ($ok) {
            $eventType = $isFebReconciliation && (string) ($request['status'] ?? '') !== self::STATUS_SUBMITTED ? 'feb_reconciled_and_approved' : 'approved';
            $this->addEvent($requestId, $eventType, trim($note) !== '' ? trim($note) : ($eventType === 'approved' ? 'Approuvée' : 'FEB régularisée, intégrée au déboursé et approuvée'), $actor);

            if ($sendNotifications) {
            try {
                $notif = new \NotificationController($this->pdo);
                $notif->notifyLegalRequestProcessed($request, 'approved', $actor, $note);
            } catch (Throwable $e) {
                // Ne pas bloquer la validation si les notifications échouent.
            }
            }
        }

        return $ok;
    }

    public function rejectRequest(int $requestId, array $actor, string $note = '', bool $sendNotifications = true): bool
    {
        FinancialWorkflowProgress::step('Enregistrement du refus et de son motif.');
        $request = $this->getRequestById($requestId);
        if ($request && (RhFinancialPointService::managedRequest($this->pdo,$request) || !empty($request['payload']['rh_financial_point_id']))) throw new DomainException('Les décisions doivent être portées dans le point RH puis signées par le DG.');
        if (!$request) {
            return false;
        }

        if (self::usesApprovalCircuit((string)($request['request_type'] ?? ''))) {
            if (!$this->canActorRejectRequest($request, $actor)) {
                return false;
            }

            $role = $this->getExpectedApprovalRole($request);
            $note = trim($note);
            $stageColumn = $role . '_approval_note';
            $sql = '
                UPDATE employee_legal_requests
                SET status = :status,
                    current_approval_stage = :current_approval_stage,
                    decided_at = NOW(),
                    decided_by_user_id = :decided_by_user_id,
                    decided_by_name = :decided_by_name,
                    decision_note = :decision_note,
                    ' . $stageColumn . ' = :stage_note,
                    updated_at = NOW()
                WHERE id = :id
                LIMIT 1
            ';

            $stmt = $this->pdo->prepare($sql);
            $ok = $stmt->execute([
                'status' => self::STATUS_REJECTED,
                'current_approval_stage' => self::CLAIM_STAGE_REJECTED,
                'decided_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
                'decided_by_name' => (string) ($actor['name'] ?? ''),
                'decision_note' => $note,
                'stage_note' => $note,
                'id' => $requestId,
            ]);

            if ($ok) {
                $eventType = $role . '_rejected';
                $eventNote = $note !== '' ? $note : ('Refus ' . strtoupper($role));
                $this->addEvent($requestId, $eventType, $eventNote, $actor);

                $updatedRequest = $this->getRequestById($requestId) ?: $request;
                if ($sendNotifications) {
                    try {
                        $notif = new \NotificationController($this->pdo);
                        $notif->notifyLegalRequestProcessed($updatedRequest, 'rejected', $actor, $note);
                    } catch (Throwable $e) {
                    }
                }
            }

            return $ok;
        }

        if (!in_array($request['status'], ($request['request_type'] ?? '') === 'feb' ? [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED] : [self::STATUS_SUBMITTED], true)) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            UPDATE employee_legal_requests
            SET status = :status,
                decided_at = NOW(),
                decided_by_user_id = :decided_by_user_id,
                decided_by_name = :decided_by_name,
                decision_note = :decision_note
            WHERE id = :id AND status = :previous_status
            LIMIT 1
        ');

        $ok = $stmt->execute([
            'previous_status' => (string)$request['status'],
            'status' => self::STATUS_REJECTED,
            'decided_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            'decided_by_name' => (string) ($actor['name'] ?? ''),
            'decision_note' => trim($note),
            'id' => $requestId,
        ]);

        $ok = $ok && $stmt->rowCount() === 1;
        if ($ok) {
            $this->addEvent($requestId, 'rejected', trim($note) !== '' ? trim($note) : 'Refusée', $actor);

            if ($sendNotifications) {
                try {
                    $notif = new \NotificationController($this->pdo);
                    $notif->notifyLegalRequestProcessed($request, 'rejected', $actor, $note);
                } catch (Throwable $e) {
                    // Ne pas bloquer le refus si les notifications échouent.
                }
            }
        }

        return $ok;
    }

    public function cancelRequest(int $requestId, int $requesterUserId, string $note = ''): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request || (int) $request['requester_user_id'] !== $requesterUserId) {
            return false;
        }

        if (!$this->canRequesterEditRequest($request, $requesterUserId)) {
            return false;
        }

        $stmt = $this->pdo->prepare('
            UPDATE employee_legal_requests
            SET status = :status,
                current_approval_stage = :current_approval_stage,
                cancelled_at = NOW(),
                decision_note = :decision_note
            WHERE id = :id
            LIMIT 1
        ');

        $ok = $stmt->execute([
            'status' => self::STATUS_CANCELLED,
            'current_approval_stage' => self::usesApprovalCircuit((string)($request['request_type'] ?? '')) ? self::CLAIM_STAGE_CANCELLED : null,
            'decision_note' => trim($note),
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->addEvent($requestId, 'cancelled', trim($note) !== '' ? trim($note) : 'Annulée', [
                'user_id' => $requesterUserId,
                'name' => (string) ($request['requester_name'] ?? ''),
            ]);
        }

        return $ok;
    }

    public function listEventsForRequest(int $requestId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $stmt = $this->pdo->prepare('
            SELECT e.*
            FROM employee_legal_request_events e
            INNER JOIN employee_legal_requests r ON r.id = e.request_id
            WHERE e.request_id = :id
              AND NOT (
                  COALESCE(r.meta_legacy_source, "") <> "feb_legacy"
                  AND COALESCE(e.event_by_name, "") = "migration"
                  AND (
                      e.event_type = "imported"
                      OR e.event_note LIKE "Import FEB legacy%"
                      OR e.event_note LIKE "Statut legacy:%"
                  )
              )
            ORDER BY e.created_at DESC, e.id DESC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function addRequestEvent(int $requestId, string $type, string $note = '', array $actor = []): bool
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return false;
        }

        $this->addEvent($requestId, $type, $note, $actor);
        return true;
    }

    private function deleteStaleLegacyEventsForFreshRequest(int $requestId): void
    {
        if ($requestId <= 0) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare('
                DELETE FROM employee_legal_request_events
                WHERE request_id = :id
                  AND COALESCE(event_by_name, "") = "migration"
                  AND (
                      event_type = "imported"
                      OR event_note LIKE "Import FEB legacy%"
                      OR event_note LIKE "Statut legacy:%"
                  )
            ');
            $stmt->execute(['id' => $requestId]);
        } catch (Throwable $e) {
            // Best-effort: un ancien journal ne doit pas bloquer la creation de la demande.
        }
    }

    private function addEvent(int $requestId, string $type, string $note = '', array $actor = []): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO employee_legal_request_events (
                request_id,
                event_type,
                event_note,
                event_by_user_id,
                event_by_name,
                created_at
            ) VALUES (
                :request_id,
                :event_type,
                :event_note,
                :event_by_user_id,
                :event_by_name,
                NOW()
            )
        ');

        $userId = (int) ($actor['user_id'] ?? 0);

        $stmt->execute([
            'request_id' => $requestId,
            'event_type' => trim($type),
            'event_note' => trim($note),
            'event_by_user_id' => $userId > 0 ? $userId : null,
            'event_by_name' => (string) ($actor['name'] ?? ''),
        ]);
    }

    private static function extractFebAuthorizationCodeFromPayload(array $payload): string
    {
        foreach (['numero_bon_commande_interne', 'bci_number', 'numero_bci', 'code_autorisation_feb', 'code_autorisation', 'autorisation_feb', 'code_feb'] as $key) {
            $candidate = trim((string) ($payload[$key] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        if (!empty($payload['legacy']) && is_array($payload['legacy'])) {
            $legacy = (array) $payload['legacy'];
            foreach (['numero_bon_commande_interne', 'bci_number', 'numero_bci', 'code_autorisation_feb', 'code_autorisation', 'autorisation_feb', 'code_feb'] as $key) {
                $candidate = trim((string) ($legacy[$key] ?? ''));
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function generateFebAuthorizationCode(int $requestId, array $request = []): string
    {
        $dateSource = trim((string) (($request['decided_at'] ?? '') ?: ($request['submitted_at'] ?? '') ?: ($request['created_at'] ?? '')));
        $timestamp = $dateSource !== '' ? strtotime($dateSource) : false;
        if (!$timestamp) {
            $timestamp = time();
        }

        return sprintf('BCI-FEB-%s-%05d', date('ym', $timestamp), $requestId);
    }

    private function febAuthorizationCodeExists(string $code, int $exceptRequestId = 0): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('
            SELECT id, payload_json
            FROM employee_legal_requests
            WHERE request_type = "feb"
              AND payload_json LIKE :needle
            LIMIT 50
        ');
        $stmt->execute(['needle' => '%' . str_replace(['%', '_'], ['\%', '\_'], $code) . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $exceptRequestId) {
                continue;
            }

            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
            if (strcasecmp($code, self::extractFebAuthorizationCodeFromPayload($payload)) === 0) {
                return true;
            }
        }

        return false;
    }

    public function generateFebBciForScheduledDisbursement(int $requestId, string $scheduledDate, array $actor = []): array
    {
        if ($requestId <= 0) {
            return ['code' => '', 'created' => false];
        }

        $request = $this->getRequestById($requestId);
        if (!is_array($request) || (string) ($request['request_type'] ?? '') !== 'feb') {
            return ['code' => '', 'created' => false];
        }
        if ((string) ($request['status'] ?? '') !== self::STATUS_APPROVED || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate)) {
            return ['code' => '', 'created' => false];
        }

        $payload = [];
        if (isset($request['payload']) && is_array($request['payload'])) {
            $payload = (array) $request['payload'];
        } elseif (!empty($request['payload_json'])) {
            $decoded = json_decode((string) $request['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $existingCode = self::extractFebAuthorizationCodeFromPayload($payload);
        if ($existingCode !== '') {
            if (($payload['scheduled_disbursement_date'] ?? '') !== $scheduledDate) {
                $payload['scheduled_disbursement_date'] = $scheduledDate;
                $this->updateFebPayloadJson($requestId, $payload);
            }
            return ['code' => $existingCode, 'created' => false];
        }

        $scheduledTimestamp = strtotime($scheduledDate) ?: time();
        $code = sprintf('BCI-FEB-%s-%05d', date('ymd', $scheduledTimestamp), $requestId);

        $payload['numero_bon_commande_interne'] = $code;
        $payload['bon_commande_interne_type'] = 'BCI_FEB';
        $payload['bon_commande_interne_label'] = 'Bon de commande interne FEB';
        FinancialWorkflowProgress::step('Préparation et enregistrement de la référence BCI.');
        $payload['code_autorisation_feb'] = $code;
        $payload['scheduled_disbursement_date'] = $scheduledDate;
        $payload['bci_generated_at'] = date('c');
        // Snapshot immuable des éléments financiers présentés sur le BCI.
        $payload['bci_details'] = [
            'description' => trim((string) ($payload['description'] ?? '')),
            'chantier_id' => (int) ($payload['chantier_id'] ?? 0),
            'initial_amount' => (float) ($payload['initial_requested_amount'] ?? $payload['montant_initial'] ?? $payload['montant'] ?? 0),
            'approved_amount' => (float) ($payload['montant'] ?? 0),
            'scheduled_disbursement_date' => $scheduledDate,
            'generated_by_user_id' => (int) ($actor['user_id'] ?? 0),
            'generated_by_name' => trim((string) ($actor['name'] ?? '')),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {
            return ['code' => '', 'created' => false];
        }

        $stmt = $this->pdo->prepare('
            UPDATE employee_legal_requests
            SET payload_json = :payload_json,
                updated_at = NOW()
            WHERE id = :id
              AND request_type = "feb"
            LIMIT 1
        ');
        $ok = $stmt->execute([
            'payload_json' => $payloadJson,
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->addEvent($requestId, 'feb_internal_purchase_order_generated', 'BCI généré lors du positionnement au ' . $scheduledDate . ' : ' . $code, $actor);
        }
        return ['code' => $code, 'created' => (bool) $ok];
    }

    private function updateFebPayloadJson(int $requestId, array $payload): bool
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;
        $stmt = $this->pdo->prepare('UPDATE employee_legal_requests SET payload_json=:payload, updated_at=NOW() WHERE id=:id AND request_type="feb"');
        return $stmt->execute(['payload' => $json, 'id' => $requestId]);
    }

    private function backfillApprovedFebAuthorizationCodes(): void
    {
        // Le BCI est désormais créé uniquement au positionnement de la fiche dans
        // les dépenses planifiées. Aucun rattrapage à l'approbation de la FEB.
        return;
        if (self::$approvedFebCodesBackfilled) {
            return;
        }
        self::$approvedFebCodesBackfilled = true;

        try {
            $stmt = $this->pdo->prepare('
                SELECT id, request_type, status, payload_json, submitted_at, decided_at, created_at
                FROM employee_legal_requests
                WHERE request_type = "feb"
                  AND status = "approved"
                  AND payload_json NOT LIKE :code_autorisation_feb
                  AND payload_json NOT LIKE :code_autorisation
                  AND payload_json NOT LIKE :autorisation_feb
                  AND payload_json NOT LIKE :code_feb
                ORDER BY decided_at ASC, submitted_at ASC, id ASC
                LIMIT 1000
            ');
            $stmt->execute([
                'code_autorisation_feb' => '%"code_autorisation_feb"%',
                'code_autorisation' => '%"code_autorisation"%',
                'autorisation_feb' => '%"autorisation_feb"%',
                'code_feb' => '%"code_feb"%',
            ]);
            $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        } catch (Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($decoded) ? $decoded : [];
            if (self::extractFebAuthorizationCodeFromPayload($payload) !== '') {
                continue;
            }

            $row['payload'] = $payload;
            $this->ensureFebAuthorizationCode((int) ($row['id'] ?? 0), $row);
        }
    }

    private function getNotificationController(): \NotificationController
    {
        $notif = new \NotificationController($this->pdo);
        if (!self::$notifDefaultsEnsured) {
            $notif->ensureDefaultRulesForLegalRequestTypes(self::REQUEST_TYPES);
            self::$notifDefaultsEnsured = true;
        }
        return $notif;
    }

    private function hydrateRequestRow(array $row): array
    {
        $payload = [];
        if (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $row['payload'] = $payload;
        $row['type_label'] = (string) (self::REQUEST_TYPES[$row['request_type']]['label'] ?? $row['request_type']);
        $row['status_meta'] = self::getRequestStatusMeta($row);

        if (($row['request_type'] ?? '') === 'feb') {
            $row['feb_display'] = self::extractFebDisplay($row);
        } elseif (($row['request_type'] ?? '') === 'reclamation_salariale') {
            $row['salary_claim_display'] = self::extractSalaryClaimDisplay($row);
        }

        return $row;
    }

    /**
     * Extrait un set de détails FEB lisibles (anti-ambiguïté) à afficher partout.
     *
     * @return array{chantier_no:string,chantier_label:string,demandeur:string,description:string,montant:float,montant_label:string,created_at:string,created_date:string}
     */
    public static function extractFebDisplay(array $row): array
    {
        $payload = [];
        if (isset($row['payload']) && is_array($row['payload'])) {
            $payload = (array) $row['payload'];
        } elseif (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $codeAutorisationFeb = '';

        foreach (
            [
                'numero_bon_commande_interne',
                'bci_number',
                'numero_bci',
                'code_autorisation_feb',
                'code_autorisation',
                'autorisation_feb',
                'code_feb'
            ] as $k
        ) {
            $candidate = trim((string) ($payload[$k] ?? ''));
            if ($candidate !== '') {
                $codeAutorisationFeb = $candidate;
                break;
            }
        }

        if ($codeAutorisationFeb === '' && !empty($payload['legacy']) && is_array($payload['legacy'])) {
            $legacy = (array) $payload['legacy'];
            foreach (
                [
                    'numero_bon_commande_interne',
                    'bci_number',
                    'numero_bci',
                    'code_autorisation_feb',
                    'code_autorisation',
                    'autorisation_feb',
                    'code_feb'
                ] as $k
            ) {
                $candidate = trim((string) ($legacy[$k] ?? ''));
                if ($candidate !== '') {
                    $codeAutorisationFeb = $candidate;
                    break;
                }
            }
        }

        $id = (int) ($row['id'] ?? 0);

        $ref = '';
        foreach (['chantier_no', 'chantier', 'reference', 'ref', 'ref_feb', 'feb_ref', 'code_feb', 'numero_feb', 'numero_chantier'] as $k) {
            $candidate = trim((string) ($payload[$k] ?? ''));
            if ($candidate !== '') {
                $ref = $candidate;
                break;
            }
        }
        if ($ref === '' && !empty($payload['legacy']) && is_array($payload['legacy'])) {
            $legacy = (array) $payload['legacy'];
            foreach (['chantier_no', 'chantier', 'reference', 'ref', 'ref_feb', 'feb_ref', 'code_feb', 'numero_feb', 'numero_chantier'] as $k) {
                $candidate = trim((string) ($legacy[$k] ?? ''));
                if ($candidate !== '') {
                    $ref = $candidate;
                    break;
                }
            }
        }

        $ref = trim($ref);
        if ($ref === '') {
            $ref = $id > 0 ? ('FEB-' . $id) : '';
        } else {
            // Normalisation légère: si c'est juste un numéro, on le préfixe.
            if (preg_match('/^\d+$/', $ref)) {
                $ref = 'FEB-' . $ref;
            }
            // Si on a déjà FEB-xxx, on garde tel quel (mais on force l'upper pour cohérence).
            if (stripos($ref, 'feb-') === 0) {
                $ref = 'FEB-' . substr($ref, 4);
            }
        }

        $demandeur = trim((string) ($row['requester_name'] ?? ''));
        if ($demandeur === '') {
            $demandeur = trim((string) ($payload['nom'] ?? $payload['requester_name'] ?? $payload['demandeur'] ?? ''));
        }

        $description = trim((string) ($payload['description'] ?? ''));
        if ($description === '' && !empty($payload['legacy']) && is_array($payload['legacy'])) {
            $description = trim((string) (((array) $payload['legacy'])['description'] ?? ''));
        }
        if ($description === '') {
            $description = trim((string) ($row['title'] ?? ''));
        }

        $montant = 0.0;
        $rawMontant = $payload['montant'] ?? $payload['amount'] ?? null;
        if (is_string($rawMontant)) {
            // Supporte "3 280 000" ou "3,280,000.00".
            $clean = str_replace([" ", "\xC2\xA0"], ['', ''], $rawMontant);
            $hasDot = strpos($clean, '.') !== false;
            $hasComma = strpos($clean, ',') !== false;
            if ($hasComma && !$hasDot) {
                // Probable virgule décimale (ex: 1234,56)
                $clean = str_replace(',', '.', $clean);
            } else {
                // Virgules = séparateurs de milliers
                $clean = str_replace(',', '', $clean);
            }
            $montant = (float) $clean;
        } elseif (is_numeric($rawMontant)) {
            $montant = (float) $rawMontant;
        }

        $createdAt = trim((string) ($row['submitted_at'] ?? ''));
        if ($createdAt === '' && !empty($payload['created_at'])) {
            $createdAt = trim((string) $payload['created_at']);
        }
        if ($createdAt === '' && !empty($payload['legacy']) && is_array($payload['legacy'])) {
            $legacy = (array) $payload['legacy'];
            $createdAt = trim((string) ($legacy['created_at'] ?? $legacy['date_creation'] ?? $legacy['date'] ?? ''));
        }

        $createdDate = '';
        if ($createdAt !== '') {
            // ISO court YYYY-MM-DD si possible
            $createdDate = substr($createdAt, 0, 10);
        }

        $montantLabel = $montant > 0 ? (number_format($montant, 0, ',', ' ') . ' FCFA') : '—';

        $status = strtolower(trim((string) ($row['status'] ?? '')));
        $publicBciNumber = $status === self::STATUS_APPROVED ? self::publicFebBciNumber($codeAutorisationFeb, $createdAt, $id) : '';
        $cashflowNeed = trim((string) ($payload['cashflow_need'] ?? ''));
        $cashflowLabel = trim((string) ($payload['cashflow_need_label'] ?? ''));
        if ($cashflowLabel === '') {
            $cashflowLabel = $cashflowNeed === 'provisional_disbursement'
                ? 'Decaissement provisoire demande'
                : ($cashflowNeed === 'internal_purchase_order' ? 'Decaissement unique sur facture' : '');
        }

        return [
            'chantier_no' => (string) $ref,
            'chantier_label' => $ref !== '' ? ('CHANTIER N° ' . $ref) : 'CHANTIER N° —',
            'demandeur' => $demandeur,
            'description' => $description,
            'montant' => $montant,
            'montant_label' => $montantLabel,
            'created_at' => $createdAt,
            'created_date' => $createdDate,
            'code_autorisation_feb' => $status === self::STATUS_APPROVED ? $codeAutorisationFeb : '',
            'numero_bon_commande_interne' => $publicBciNumber,
            'bon_commande_interne_label' => $publicBciNumber !== '' ? ('Bon de commande interne FEB ' . $publicBciNumber) : '',
            'cashflow_need' => $cashflowNeed,
            'cashflow_need_label' => $cashflowLabel,
            'provisional_disbursement_amount' => (float) ($payload['provisional_disbursement_amount'] ?? 0),
            'cashflow_comment' => trim((string) ($payload['cashflow_comment'] ?? '')),
        ];
    }

    private static function publicFebBciNumber(string $storedCode, string $createdAt, int $id): string
    {
        $storedCode = trim($storedCode);
        if ($storedCode !== '' && stripos($storedCode, 'AUTH-FEB') !== 0) {
            return $storedCode;
        }

        $timestamp = $createdAt !== '' ? strtotime($createdAt) : false;
        if (!$timestamp) {
            $timestamp = time();
        }

        return $id > 0 ? sprintf('BCI-FEB-%s-%05d', date('ym', $timestamp), $id) : '';
    }

    public static function extractSalaryClaimDisplay(array $row): array
    {
        $payload = [];
        if (isset($row['payload']) && is_array($row['payload'])) {
            $payload = (array) $row['payload'];
        } elseif (!empty($row['payload_json'])) {
            $decoded = json_decode((string) $row['payload_json'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $objects = self::getSalaryClaimObjectOptions();
        $objectKey = strtolower(trim((string) ($payload['objet_reclamation'] ?? '')));
        $monthValue = trim((string) ($payload['mois_concerne'] ?? ''));
        $monthLabel = $monthValue;
        if (preg_match('/^(\d{4})-(\d{2})$/', $monthValue, $m)) {
            $months = [
                '01' => 'Janvier',
                '02' => 'Février',
                '03' => 'Mars',
                '04' => 'Avril',
                '05' => 'Mai',
                '06' => 'Juin',
                '07' => 'Juillet',
                '08' => 'Août',
                '09' => 'Septembre',
                '10' => 'Octobre',
                '11' => 'Novembre',
                '12' => 'Décembre',
            ];
            $monthLabel = ($months[$m[2]] ?? $m[2]) . ' ' . $m[1];
        }

        $amount = 0.0;
        $rawAmount = $payload['montant_manquant'] ?? null;
        if (is_string($rawAmount)) {
            $clean = str_replace([' ', ','], ['', '.'], $rawAmount);
            $amount = (float) $clean;
        } elseif (is_numeric($rawAmount)) {
            $amount = (float) $rawAmount;
        }

        $demandeur = trim((string) ($row['requester_name'] ?? ''));
        if ($demandeur === '') {
            $demandeur = trim((string) (($payload['prenoms'] ?? '') . ' ' . ($payload['nom'] ?? '')));
        }

        return [
            'date_demande' => trim((string) ($payload['date_demande'] ?? '')),
            'nom' => trim((string) ($payload['nom'] ?? '')),
            'prenoms' => trim((string) ($payload['prenoms'] ?? '')),
            'matricule' => trim((string) ($payload['matricule'] ?? '')),
            'service' => trim((string) ($payload['service'] ?? '')),
            'fonction' => trim((string) ($payload['fonction'] ?? '')),
            'objet_key' => $objectKey,
            'objet_label' => (string) ($objects[$objectKey] ?? ($payload['objet_reclamation_label'] ?? $objectKey)),
            'mois_concerne' => $monthValue,
            'mois_label' => $monthLabel !== '' ? $monthLabel : '—',
            'montant_manquant' => $amount,
            'montant_label' => $amount > 0 ? (number_format($amount, 0, ',', ' ') . ' FCFA') : '—',
            'details_differend' => trim((string) ($payload['details_differend'] ?? '')),
            'demandeur' => $demandeur,
            'approval_stage' => (string) (self::getRequestStatusMeta($row)['stage'] ?? self::CLAIM_STAGE_RH_PENDING),
            'approval_stage_label' => (string) (self::getRequestStatusMeta($row)['label'] ?? 'En attente RH'),
        ];
    }

    public function getRequestSignatureAbsolutePath(int $requestId, string $role): ?string
    {
        $request = $this->getRequestById($requestId);
        if (!$request) {
            return null;
        }

        $role = $this->normalizeSignatureRole($role);
        if ($role === '') {
            return null;
        }

        $relativePath = trim((string) ($request[$role . '_signature_path'] ?? ''));
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

        $role = $this->normalizeSignatureRole($role);
        if ($role === '' || !$this->canActorSaveSignature($request, $role, $actor)) {
            return false;
        }

        $storedSignature = $this->storeSignatureDataUrl($requestId, $role, $signatureDataUrl);
        $pathColumn = $role . '_signature_path';
        $signedAtColumn = $role . '_signed_at';
        $signedByUserColumn = $role . '_signed_by_user_id';
        $signedByNameColumn = $role . '_signed_by_name';

        $existingPath = trim((string) ($request[$pathColumn] ?? ''));
        if ($existingPath !== '' && $existingPath !== $storedSignature['relative_path']) {
            $existingAbsolutePath = $this->resolveStorageAbsolutePath($existingPath);
            if ($existingAbsolutePath !== null && is_file($existingAbsolutePath)) {
                @unlink($existingAbsolutePath);
            }
        }

        $sql = 'UPDATE employee_legal_requests SET '
            . $pathColumn . ' = :signature_path, '
            . $signedAtColumn . ' = NOW(), '
            . $signedByUserColumn . ' = :signed_by_user_id, '
            . $signedByNameColumn . ' = :signed_by_name, '
            . 'updated_at = NOW() WHERE id = :id LIMIT 1';
        $stmt = $this->pdo->prepare($sql);

        $ok = $stmt->execute([
            'signature_path' => $storedSignature['relative_path'],
            'signed_by_user_id' => (int) ($actor['user_id'] ?? 0) ?: null,
            'signed_by_name' => trim((string) ($actor['name'] ?? '')),
            'id' => $requestId,
        ]);

        if ($ok) {
            $this->addEvent($requestId, $role . '_signature_saved', 'Signature enregistrée', $actor);
        }

        return $ok;
    }

    private function normalizeApprovalRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, ['rh', 'drh', 'dg'], true) ? $role : '';
    }

    private function normalizeSignatureRole(string $role): string
    {
        $role = strtolower(trim($role));
        return in_array($role, ['employee', 'rh', 'drh', 'dg'], true) ? $role : '';
    }

    private static function normalizeSalaryClaimStage(string $stage, string $status): string
    {
        $stage = strtolower(trim($stage));
        $status = strtolower(trim($status));
        if (in_array($stage, [
            self::CLAIM_STAGE_RH_PENDING,
            self::CLAIM_STAGE_DRH_PENDING,
            self::CLAIM_STAGE_DG_PENDING,
            self::CLAIM_STAGE_APPROVED,
            self::CLAIM_STAGE_REJECTED,
            self::CLAIM_STAGE_CANCELLED,
        ], true)) {
            return $stage;
        }

        return match ($status) {
            self::STATUS_APPROVED => self::CLAIM_STAGE_APPROVED,
            self::STATUS_REJECTED => self::CLAIM_STAGE_REJECTED,
            self::STATUS_CANCELLED => self::CLAIM_STAGE_CANCELLED,
            default => self::CLAIM_STAGE_RH_PENDING,
        };
    }

    private function storeSignatureDataUrl(int $requestId, string $role, string $signatureDataUrl): array
    {
        $signatureDataUrl = trim($signatureDataUrl);
        if ($signatureDataUrl === '' || !preg_match('/^data:image\/(png|jpeg);base64,/', $signatureDataUrl, $matches)) {
            throw new RuntimeException('Signature invalide.');
        }

        $extension = strtolower((string) ($matches[1] ?? 'png')) === 'jpeg' ? 'jpg' : 'png';
        $base64 = substr($signatureDataUrl, strpos($signatureDataUrl, ',') + 1);
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Signature invalide.');
        }

        $relativeDir = 'storage/documents/employee_legal_signatures';
        $absoluteDir = __DIR__ . '/../' . $relativeDir;
        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Impossible de préparer le dossier de signature.');
        }

        $fileName = sprintf('%s_%d_%s_%s.%s', $role, $requestId, date('YmdHis'), bin2hex(random_bytes(4)), $extension);
        $absolutePath = $absoluteDir . '/' . $fileName;
        if (@file_put_contents($absolutePath, $binary) === false) {
            throw new RuntimeException('Impossible d’enregistrer la signature.');
        }

        return [
            'absolute_path' => $absolutePath,
            'relative_path' => $relativeDir . '/' . $fileName,
        ];
    }

    private function resolveStorageAbsolutePath(string $relativePath): ?string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '') {
            return null;
        }

        return __DIR__ . '/../' . ltrim($relativePath, '/');
    }

    private function normalizeDateValue(string $date, string $fallback): string
    {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00') {
            return $fallback;
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        return $dt instanceof DateTimeImmutable && $dt->format('Y-m-d') === $date ? $date : $fallback;
    }

    private function nullableDateValue(string $date): ?string
    {
        $date = $this->normalizeDateValue($date, '');
        return $date !== '' ? $date : null;
    }

    private function monthDiff(string $from, string $to): int
    {
        $from = $this->normalizeDateValue($from, '');
        $to = $this->normalizeDateValue($to, '');
        if ($from === '' || $to === '' || $to < $from) {
            return 0;
        }

        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $diff = $start->diff($end);
        return max(0, ((int) $diff->y * 12) + (int) $diff->m);
    }

    private function sumLeaveRequestDays(int $personnelId, string $referenceDate, array $statuses, int $excludeRequestId = 0, ?int $requesterUserId = null): float
    {
        if ($personnelId <= 0 || !$statuses) {
            return 0.0;
        }

        $placeholders = [];
        $params = [
            ':exclude_id' => $excludeRequestId,
        ];
        foreach (array_values($statuses) as $idx => $status) {
            $key = ':status_' . $idx;
            $placeholders[] = $key;
            $params[$key] = (string) $status;
        }

        $stmt = $this->pdo->prepare('
            SELECT id, requester_user_id, status, payload_json
            FROM employee_legal_requests
            WHERE request_type = "conge"
              AND id <> :exclude_id
              AND status IN (' . implode(',', $placeholders) . ')
        ');
        $stmt->execute($params);

        $total = 0.0;
        foreach (($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $payloadPersonnelId = (int) ($payload['personnel_id'] ?? 0);
            $rowRequesterId = (int) ($row['requester_user_id'] ?? 0);
            if ($payloadPersonnelId !== $personnelId && ($requesterUserId === null || $rowRequesterId !== $requesterUserId)) {
                continue;
            }
            $dateDebut = $this->normalizeDateValue((string) ($payload['date_debut'] ?? ''), '');
            if ($dateDebut !== '' && $dateDebut < $referenceDate) {
                continue;
            }
            $total += (float) ($payload['nombre_jours'] ?? 0);
        }

        return $total;
    }

    private function ensureSupportTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS employee_legal_requests (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                requester_user_id INT NOT NULL,
                requester_name VARCHAR(150) DEFAULT NULL,
                requester_email VARCHAR(150) DEFAULT NULL,
                request_type VARCHAR(32) NOT NULL,
                assigned_team VARCHAR(16) NOT NULL DEFAULT "rh",
                title VARCHAR(255) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                meta_service_id INT DEFAULT NULL,
                meta_legacy_source VARCHAR(32) DEFAULT NULL,
                meta_legacy_id INT DEFAULT NULL,
                meta_legacy_key VARCHAR(64) DEFAULT NULL,
                current_approval_stage VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT "submitted",
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                employee_signature_path VARCHAR(255) DEFAULT NULL,
                employee_signed_at DATETIME DEFAULT NULL,
                employee_signed_by_user_id INT DEFAULT NULL,
                employee_signed_by_name VARCHAR(150) DEFAULT NULL,
                rh_signature_path VARCHAR(255) DEFAULT NULL,
                rh_signed_at DATETIME DEFAULT NULL,
                rh_signed_by_user_id INT DEFAULT NULL,
                rh_signed_by_name VARCHAR(150) DEFAULT NULL,
                rh_approved_at DATETIME DEFAULT NULL,
                rh_approved_by_user_id INT DEFAULT NULL,
                rh_approved_by_name VARCHAR(150) DEFAULT NULL,
                rh_approval_note TEXT DEFAULT NULL,
                drh_signature_path VARCHAR(255) DEFAULT NULL,
                drh_signed_at DATETIME DEFAULT NULL,
                drh_signed_by_user_id INT DEFAULT NULL,
                drh_signed_by_name VARCHAR(150) DEFAULT NULL,
                drh_approved_at DATETIME DEFAULT NULL,
                drh_approved_by_user_id INT DEFAULT NULL,
                drh_approved_by_name VARCHAR(150) DEFAULT NULL,
                drh_approval_note TEXT DEFAULT NULL,
                dg_signature_path VARCHAR(255) DEFAULT NULL,
                dg_signed_at DATETIME DEFAULT NULL,
                dg_signed_by_user_id INT DEFAULT NULL,
                dg_signed_by_name VARCHAR(150) DEFAULT NULL,
                dg_approved_at DATETIME DEFAULT NULL,
                dg_approved_by_user_id INT DEFAULT NULL,
                dg_approved_by_name VARCHAR(150) DEFAULT NULL,
                dg_approval_note TEXT DEFAULT NULL,
                decided_at DATETIME DEFAULT NULL,
                decided_by_user_id INT DEFAULT NULL,
                decided_by_name VARCHAR(150) DEFAULT NULL,
                decision_note TEXT DEFAULT NULL,
                cancelled_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_elr_requester (requester_user_id),
                KEY idx_elr_team_status (assigned_team, status),
                KEY idx_elr_meta_service (meta_service_id),
                KEY idx_elr_legacy (meta_legacy_source, meta_legacy_id),
                KEY idx_elr_legacy_key (meta_legacy_source, meta_legacy_key),
                KEY idx_elr_submitted (submitted_at),
                KEY idx_elr_type (request_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->ensureSchemaUpgrades();

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_leave_opening_balance (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                personnel_id INT NOT NULL,
                reference_date DATE NOT NULL,
                last_leave_start_date DATE DEFAULT NULL,
                last_leave_end_date DATE DEFAULT NULL,
                last_return_date DATE DEFAULT NULL,
                accrued_days_opening DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                consumed_days_opening DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                balance_days_opening DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                comment TEXT DEFAULT NULL,
                created_by INT DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_rh_leave_opening_personnel (personnel_id),
                KEY idx_rh_leave_opening_reference (reference_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS rh_leave_settings (
                setting_key VARCHAR(80) NOT NULL PRIMARY KEY,
                setting_value VARCHAR(255) NOT NULL,
                updated_by INT DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $defaultStart = date('Y-m-d');
        $this->pdo->exec("
            INSERT IGNORE INTO rh_leave_settings (setting_key, setting_value) VALUES
            ('require_opening_balance_for_existing_staff', '1'),
            ('leave_module_start_date', '" . addslashes($defaultStart) . "'),
            ('default_existing_staff_policy', 'block_until_initialized'),
            ('deduct_pending_leave_requests', '0'),
            ('monthly_accrual_days', '2.2'),
            ('minimum_seniority_months', '12')
        ");

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS employee_legal_request_events (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                request_id INT UNSIGNED NOT NULL,
                event_type VARCHAR(32) NOT NULL,
                event_note TEXT DEFAULT NULL,
                event_by_user_id INT DEFAULT NULL,
                event_by_name VARCHAR(150) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_elre_request (request_id),
                KEY idx_elre_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');

        $this->backfillApprovedFebAuthorizationCodes();
    }

    private function ensureSchemaUpgrades(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM employee_legal_requests')->fetchAll(PDO::FETCH_COLUMN, 0);
            $cols = is_array($cols) ? $cols : [];
            if (!in_array('meta_service_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN meta_service_id INT DEFAULT NULL');
            }
            if (!in_array('meta_legacy_source', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN meta_legacy_source VARCHAR(32) DEFAULT NULL');
            }
            if (!in_array('meta_legacy_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN meta_legacy_id INT DEFAULT NULL');
            }
            if (!in_array('meta_legacy_key', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN meta_legacy_key VARCHAR(64) DEFAULT NULL');
            }
            if (!in_array('current_approval_stage', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN current_approval_stage VARCHAR(32) DEFAULT NULL');
            }
            if (!in_array('employee_signature_path', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN employee_signature_path VARCHAR(255) DEFAULT NULL');
            }
            if (!in_array('employee_signed_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN employee_signed_at DATETIME DEFAULT NULL');
            }
            if (!in_array('employee_signed_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN employee_signed_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('employee_signed_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN employee_signed_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('rh_signature_path', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_signature_path VARCHAR(255) DEFAULT NULL');
            }
            if (!in_array('rh_signed_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_signed_at DATETIME DEFAULT NULL');
            }
            if (!in_array('rh_signed_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_signed_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('rh_signed_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_signed_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('rh_approved_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_approved_at DATETIME DEFAULT NULL');
            }
            if (!in_array('rh_approved_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_approved_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('rh_approved_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_approved_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('rh_approval_note', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN rh_approval_note TEXT DEFAULT NULL');
            }
            if (!in_array('drh_signature_path', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_signature_path VARCHAR(255) DEFAULT NULL');
            }
            if (!in_array('drh_signed_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_signed_at DATETIME DEFAULT NULL');
            }
            if (!in_array('drh_signed_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_signed_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('drh_signed_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_signed_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('drh_approved_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_approved_at DATETIME DEFAULT NULL');
            }
            if (!in_array('drh_approved_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_approved_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('drh_approved_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_approved_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('drh_approval_note', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN drh_approval_note TEXT DEFAULT NULL');
            }
            if (!in_array('dg_signature_path', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_signature_path VARCHAR(255) DEFAULT NULL');
            }
            if (!in_array('dg_signed_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_signed_at DATETIME DEFAULT NULL');
            }
            if (!in_array('dg_signed_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_signed_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('dg_signed_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_signed_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('dg_approved_at', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_approved_at DATETIME DEFAULT NULL');
            }
            if (!in_array('dg_approved_by_user_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_approved_by_user_id INT DEFAULT NULL');
            }
            if (!in_array('dg_approved_by_name', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_approved_by_name VARCHAR(150) DEFAULT NULL');
            }
            if (!in_array('dg_approval_note', $cols, true)) {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD COLUMN dg_approval_note TEXT DEFAULT NULL');
            }
            // Index best-effort (tolère l'erreur si déjà présent)
            try {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD KEY idx_elr_meta_service (meta_service_id)');
            } catch (Throwable $e) {
            }
            try {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD KEY idx_elr_legacy (meta_legacy_source, meta_legacy_id)');
            } catch (Throwable $e) {
            }
            try {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD KEY idx_elr_legacy_key (meta_legacy_source, meta_legacy_key)');
            } catch (Throwable $e) {
            }

            // Contrainte d'unicité best-effort pour empêcher les doublons en cas d'exécutions concurrentes.
            // Si des doublons historiques existent déjà, l'ALTER échouera et on tolère.
            try {
                $this->pdo->exec('ALTER TABLE employee_legal_requests ADD UNIQUE KEY uq_elr_legacy_key (meta_legacy_source, meta_legacy_key)');
            } catch (Throwable $e) {
            }
        } catch (Throwable $e) {
            // Tolérer les environnements où ALTER n'est pas autorisé.
        }

        $done = true;
    }

    public function listFebRequestsForUserOrMatricule(
        int $userId,
        ?string $matricule,
        ?array $statuses = null,
        int $limit = 100,
        bool $includeLegacyFeb = false
    ): array {
        $limit = max(1, min(500, $limit));

        $matricule = strtoupper(trim((string) $matricule));

        $where = ['request_type = "feb"'];
        if (!$includeLegacyFeb) {
            $where[] = 'COALESCE(meta_legacy_source, "") <> "feb_legacy"';
        }
        $params = ['uid' => $userId];
        $ownerClause = 'requester_user_id = :uid';

        if ($matricule !== '') {
            // On cherche "matricule":"XXX" dans le JSON (best-effort)
            $ownerClause .= ' OR payload_json LIKE :matricule_like';
            $params['matricule_like'] = '%"matricule":"' . str_replace('"', '\\"', $matricule) . '"%';
        }
        $where[] = '(' . $ownerClause . ')';

        if (is_array($statuses) && $statuses) {
            $norm = array_values(array_filter(array_map('strtolower', $statuses), static function ($s): bool {
                return in_array($s, [self::STATUS_SUBMITTED, self::STATUS_DETAILS_REQUESTED, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED], true);
            }));
            if ($norm) {
                $in = [];
                foreach ($norm as $idx => $s) {
                    $k = ':st' . $idx;
                    $in[] = $k;
                    $params[$k] = $s;
                }
                $where[] = 'status IN (' . implode(', ', $in) . ')';
            }
        }

        $where[]=$this->activeFebScopeSql();
        $sql = 'SELECT * FROM employee_legal_requests WHERE ' . implode(' AND ', $where) . ' ORDER BY submitted_at DESC, id DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'hydrateRequestRow'], $rows);
    }

    public function countFebRequestsForUserOrMatricule(int $userId, ?string $matricule, bool $includeLegacyFeb = false): array
    {
        $matricule = strtoupper(trim((string) $matricule));

        $params = ['uid' => $userId];
        $ownerClause = 'requester_user_id = :uid';
        if ($matricule !== '') {
            $ownerClause .= ' OR payload_json LIKE :matricule_like';
            $params['matricule_like'] = '%"matricule":"' . str_replace('"', '\\"', $matricule) . '"%';
        }

        $legacyFilter = $includeLegacyFeb ? '' : ' AND COALESCE(meta_legacy_source, "") <> "feb_legacy"';
        $stmt = $this->pdo->prepare('SELECT status, COUNT(*) AS c FROM employee_legal_requests WHERE request_type = "feb" AND (' . $ownerClause . ')' . $legacyFilter . ' AND ' . $this->activeFebScopeSql() . ' GROUP BY status');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [
            self::STATUS_SUBMITTED => 0,
            self::STATUS_DETAILS_REQUESTED => 0,
            self::STATUS_APPROVED => 0,
            self::STATUS_REJECTED => 0,
            self::STATUS_CANCELLED => 0,
        ];
        foreach ($rows as $r) {
            $s = (string) ($r['status'] ?? '');
            if (array_key_exists($s, $out)) {
                $out[$s] = (int) ($r['c'] ?? 0);
            }
        }
        return $out;
    }

    public function findApprovedFebByAuthorizationCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        if (preg_match('/^BCI-FEB-\d{4}-(\d+)$/i', $code, $matches)) {
            $candidate = $this->getRequestById((int) $matches[1]);
            if (is_array($candidate)
                && (string) ($candidate['request_type'] ?? '') === 'feb'
                && (string) ($candidate['status'] ?? '') === self::STATUS_APPROVED
            ) {
                $display = (array) ($candidate['feb_display'] ?? self::extractFebDisplay($candidate));
                $displayCode = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
                if (strcasecmp($code, $displayCode) === 0) {
                    return $candidate;
                }
            }
        }

        $stmt = $this->pdo->prepare('
            SELECT *
            FROM employee_legal_requests
            WHERE request_type = "feb"
              AND status = "approved"
              AND ' . $this->activeFebScopeSql() . '
              AND payload_json LIKE :needle
            ORDER BY decided_at DESC, submitted_at DESC, id DESC
            LIMIT 50
        ');
        $stmt->execute(['needle' => '%' . str_replace(['%', '_'], ['\%', '\_'], $code) . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $hydrated = $this->hydrateRequestRow($row);
            $display = (array) ($hydrated['feb_display'] ?? self::extractFebDisplay($hydrated));
            $displayCode = trim((string) ($display['numero_bon_commande_interne'] ?? $display['code_autorisation_feb'] ?? ''));
            if (strcasecmp($code, $displayCode) === 0) {
                return $hydrated;
            }
        }

        return null;
    }
}


