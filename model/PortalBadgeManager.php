<?php

require_once __DIR__ . '/EmployeeLegalRequestManager.php';
require_once __DIR__ . '/FebValidationPolicy.php';
require_once __DIR__ . '/RhExplanationRequestManager.php';
require_once __DIR__ . '/AccessRequestManager.php';

class PortalBadgeManager
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_VOLATILE = 'volatile';

    public const VARIANT_CRITICAL = 'critical';
    public const VARIANT_URGENT = 'urgent';
    public const VARIANT_WATCH = 'watch';

    private const MODULE_THRESHOLDS = [
        'module_admin' => [
            'critical_pending_days' => 2,
        ],
        'module_crm' => [
            'urgent_window_days' => 1,
            'watch_window_days' => 3,
        ],
        'module_direction' => [
            'urgent_backlog_total' => 15,
            'critical_backlog_total' => 30,
        ],
        'module_documentation' => [
            'urgent_expiring_within_days' => 2,
            'watch_expiring_within_days' => 7,
        ],
        'module_facturation' => [
            'critical_error_total' => 1,
            'urgent_pending_total' => 5,
        ],
    ];

    private const MODULE_PRESENTATION = [
        'module_employe' => [
            'counted_description' => 'Le badge compte vos demandes employé encore en attente de traitement.',
            'variant_labels' => [
                'watch' => 'demandes employé en attente',
            ],
        ],
        'module_rh' => [
            'counted_description' => 'Le badge compte les dossiers RH ouverts et les explications qui attendent encore une action.',
            'variant_labels' => [
                'watch' => 'dossiers RH à suivre',
                'critical' => 'explications RH en retard',
            ],
        ],
        'module_admin' => [
            'counted_description' => 'Le badge compte les demandes d’accès qui attendent encore une décision.',
            'variant_labels' => [
                'watch' => 'demandes d’accès en attente',
                'critical' => 'demandes d’accès en attente critique',
            ],
        ],
        'module_direction' => [
            'counted_description' => 'Le badge compte les fiches de décaissement encore en attente dans le circuit global de décision.',
            'variant_labels' => [
                'watch' => 'arbitrages direction à suivre',
                'urgent' => 'arbitrages direction en tension',
                'critical' => 'arbitrages direction critiques',
            ],
        ],
        'module_validation_feb' => [
            'counted_description' => 'Le badge compte les FEB qui attendent encore votre validation.',
            'variant_labels' => [
                'watch' => 'FEB à valider',
            ],
        ],
        'module_fournisseur' => [
            'counted_description' => 'Le badge compte les fournisseurs encore en attente de validation.',
            'variant_labels' => [
                'watch' => 'fournisseurs à valider',
            ],
        ],
        'module_documentation' => [
            'counted_description' => 'Le badge compte les partages de documents ou de dossiers à renouveler ou déjà expirés.',
            'variant_labels' => [
                'watch' => 'partages bientôt expirés',
                'urgent' => 'partages à renouveler vite',
                'critical' => 'partages documentation expirés',
            ],
        ],
        'module_facturation' => [
            'counted_description' => 'Le badge compte les factures achat à vérifier, les factures vente à signer et les factures en erreur.',
            'variant_labels' => [
                'watch' => 'factures à vérifier ou signer',
                'urgent' => 'factures à traiter en priorité',
                'critical' => 'factures en erreur à corriger',
            ],
        ],
        'module_crm' => [
            'counted_description' => 'Le badge compte les actions CRM proches, urgentes ou déjà en retard.',
            'variant_labels' => [
                'watch' => 'actions CRM proches',
                'urgent' => 'actions CRM imminentes',
                'critical' => 'actions CRM en retard',
            ],
        ],
        'module_logistique' => [
            'counted_description' => 'Le badge compte les demandes logistiques, seuils de stock et réparations encore ouvertes.',
            'variant_labels' => [
                'watch' => 'points logistiques à suivre',
            ],
        ],
        'module_decaissement' => [
            'counted_description' => 'Le badge compte les fiches qui attendent une approbation.',
            'variant_labels' => [
                'watch' => 'fiches à approuver',
            ],
        ],
        'module_compta' => [
            'counted_description' => 'Le badge compte les fiches qui attendent encore la validation comptable.',
            'variant_labels' => [
                'watch' => 'fiches à valider',
            ],
        ],
        'module_tresorerie' => [
            'counted_description' => 'Le badge compte les fiches prêtes pour le décaissement.',
            'variant_labels' => [
                'watch' => 'fiches à décaisser',
            ],
        ],
        'module_emails' => [
            'counted_description' => 'Le badge compte les emails non lus dans votre boîte professionnelle.',
            'variant_labels' => [
                'watch' => 'emails non lus',
            ],
        ],
    ];

    private const COLOR_GUIDE = [
        'watch' => [
            'label' => 'Orange',
            'meaning' => 'À surveiller',
            'description' => 'Quelque chose vous attend bientôt, mais ce n’est pas encore bloquant.',
        ],
        'urgent' => [
            'label' => 'Rouge',
            'meaning' => 'Urgent',
            'description' => 'Une action doit être faite rapidement.',
        ],
        'critical' => [
            'label' => 'Rouge foncé',
            'meaning' => 'Critique',
            'description' => 'Le sujet est déjà en retard ou demande une réaction immédiate.',
        ],
    ];

    private const VOLATILE_MODULE_KEYS = [
        'module_validation_feb',
        'module_emails',
        'module_crm',
    ];

    private PDO $pdo;
    private ?int $currentUserId;
    private array $permissions;
    private array $session;
    private ?\EmployeeLegalRequestManager $legalManager = null;
    private array $tableExistsCache = [];
    private array $columnsCache = [];
    private ?array $settingsRowsCache = null;

    public function __construct(PDO $pdo, ?int $currentUserId = null, array $permissions = [], array $session = [])
    {
        $this->pdo = $pdo;
        $this->currentUserId = $currentUserId !== null && $currentUserId > 0 ? $currentUserId : null;
        $this->permissions = $permissions;
        $this->session = $session;
    }

    public function getBadges(string $scope = self::SCOPE_ALL): array
    {
        $state = $this->computeBadgeState($scope);
        return $state['badges'];
    }

    public function getThresholds(): array
    {
        $thresholds = [];
        foreach (array_keys(self::MODULE_PRESENTATION) as $moduleKey) {
            $thresholds[$moduleKey] = $this->getModuleThresholds($moduleKey);
        }
        return $thresholds;
    }

    public function getBadgeGuide(?array $moduleKeys = null): array
    {
        $modules = [];
        $selectedKeys = is_array($moduleKeys) ? array_values(array_filter(array_map('strval', $moduleKeys))) : array_keys(self::MODULE_PRESENTATION);

        foreach ($selectedKeys as $moduleKey) {
            $presentation = $this->getModulePresentation($moduleKey);
            if ($presentation === []) {
                continue;
            }

            $modules[$moduleKey] = [
                'module_key' => $moduleKey,
                'counted_description' => (string) ($presentation['counted_description'] ?? ''),
                'variant_labels' => (array) ($presentation['variant_labels'] ?? []),
                'thresholds' => $this->getModuleThresholds($moduleKey),
            ];
        }

        return [
            'colors' => self::COLOR_GUIDE,
            'modules' => $modules,
        ];
    }

    public function getSettingsSnapshot(): array
    {
        return [
            'colors' => self::COLOR_GUIDE,
            'modules' => $this->getBadgeGuide()['modules'],
        ];
    }

    public function getDebugSnapshot(string $scope = self::SCOPE_ALL): array
    {
        $scope = $this->normalizeScope($scope);
        $state = $this->computeBadgeState($scope);

        return [
            'generated_at' => date('c'),
            'scope' => $scope,
            'thresholds' => $this->getThresholds(),
            'colors' => self::COLOR_GUIDE,
            'modules' => array_values($this->applyDebugPresentation($state['debug'])),
        ];
    }

    public static function isVolatileModule(string $moduleKey): bool
    {
        return in_array($moduleKey, self::VOLATILE_MODULE_KEYS, true);
    }

    private function computeBadgeState(string $scope): array
    {
        $scope = $this->normalizeScope($scope);
        $badges = [];
        $debug = [];

        if ($scope === self::SCOPE_VOLATILE) {
            foreach (self::VOLATILE_MODULE_KEYS as $moduleKey) {
                $badges[$moduleKey] = $this->buildBadge(0, '', self::VARIANT_WATCH, true);
            }
        }

        $this->appendEmployeeBadge($badges, $scope, $debug);
        $this->appendRhBadge($badges, $scope, $debug);
        $this->appendAdminBadge($badges, $scope, $debug);
        $this->appendDirectionBadge($badges, $scope, $debug);
        $this->appendValidationFebBadge($badges, $scope, $debug);
        $this->appendSupplierBadge($badges, $scope, $debug);
        $this->appendDocumentationBadge($badges, $scope, $debug);
        $this->appendFacturationBadge($badges, $scope, $debug);
        $this->appendCrmBadge($badges, $scope, $debug);
        $this->appendLogisticsBadge($badges, $scope, $debug);
        $this->appendDecaissementWorkflowBadges($badges, $scope, $debug);
        $this->appendEmailsBadge($badges, $scope, $debug);

        if ($scope === self::SCOPE_ALL) {
            $badges = array_filter($badges, static function (array $badge): bool {
                return (int) ($badge['count'] ?? 0) > 0;
            });
        }

        $badges = $this->applyBadgePresentation($badges);

        return [
            'badges' => $badges,
            'debug' => $debug,
        ];
    }

    private function appendEmployeeBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_employe';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'employee_legal_requests');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_employe', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if ($this->currentUserId === null) {
            $entry['reason'] = 'missing_current_user';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT matricule FROM personnel WHERE email_personnel = :email LIMIT 1');
            $stmt->execute(['email' => (string) ($this->session['mail_pro'] ?? '')]);
            $matricule = strtoupper(trim((string) ($stmt->fetchColumn() ?: '')));
            $counts = $this->getLegalManager()->countFebRequestsForUserOrMatricule($this->currentUserId, $matricule);
            $count = (int) ($counts['submitted'] ?? 0);
            $entry['counts'] = [
                'submitted_count' => $count,
            ];
            if ($count > 0) {
                $badge = $this->buildBadge($count, 'demandes employé en attente', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendRhBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_rh';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'employee_legal_requests,rh_explanation_requests');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_rh', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $counts = $this->getLegalManager()->countRequestsForTeam('rh');
            $legalCount = (int) ($counts['submitted'] ?? 0);

            $explanationsCritical = 0;
            $explanationsOpen = 0;
            if ($this->tableExists('rh_explanation_requests')) {
                $stmt = $this->pdo->query(
                    "SELECT
                        SUM(CASE WHEN status IN ('pending_response', 'complement_requested') AND due_at IS NOT NULL AND due_at < NOW() THEN 1 ELSE 0 END) AS critical_count,
                        SUM(CASE WHEN status IN ('pending_response', 'complement_requested', 'answered') THEN 1 ELSE 0 END) AS open_count
                     FROM rh_explanation_requests"
                );
                $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
                $explanationsCritical = (int) ($row['critical_count'] ?? 0);
                $explanationsOpen = (int) ($row['open_count'] ?? 0);
            }

            $entry['counts'] = [
                'legal_count' => $legalCount,
                'explanations_critical' => $explanationsCritical,
                'explanations_open' => $explanationsOpen,
            ];

            if ($explanationsCritical > 0) {
                $badge = $this->buildBadge($explanationsCritical, 'explications RH en retard', self::VARIANT_CRITICAL, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'critical_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            $watchCount = $legalCount + $explanationsOpen;
            if ($watchCount > 0) {
                $badge = $this->buildBadge($watchCount, 'dossiers RH à suivre', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendAdminBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_admin';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'access_requests');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if (!$this->tableExists('access_requests')) {
            $entry['reason'] = 'table_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $criticalDays = (int) ($this->getModuleThresholds($moduleKey)['critical_pending_days'] ?? 2);
            $stmt = $this->pdo->query(
                "SELECT
                    SUM(CASE WHEN status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL {$criticalDays} DAY) THEN 1 ELSE 0 END) AS critical_count,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
                 FROM access_requests"
            );
            $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            $criticalCount = (int) ($row['critical_count'] ?? 0);
            $pendingCount = (int) ($row['pending_count'] ?? 0);
            $entry['counts'] = [
                'critical_count' => $criticalCount,
                'pending_count' => $pendingCount,
            ];

            if ($criticalCount > 0) {
                $badge = $this->buildBadge($criticalCount, 'demandes d\'accès en attente critique', self::VARIANT_CRITICAL, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'critical_threshold_reached';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            if ($pendingCount > 0) {
                $badge = $this->buildBadge($pendingCount, 'demandes d\'accès en attente', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendDirectionBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_direction';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'fiche_decaissement');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_direction', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $counts = $this->loadDecaissementWorkflowBacklogCounts();
        $total = (int) ($counts['approbation'] ?? 0) + (int) ($counts['achat'] ?? 0) + (int) ($counts['compta'] ?? 0);
        $urgentThreshold = (int) ($this->getModuleThresholds($moduleKey)['urgent_backlog_total'] ?? 15);
        $criticalThreshold = (int) ($this->getModuleThresholds($moduleKey)['critical_backlog_total'] ?? 30);
        $entry['counts'] = [
            'approbation' => (int) ($counts['approbation'] ?? 0),
            'achat' => (int) ($counts['achat'] ?? 0),
            'compta' => (int) ($counts['compta'] ?? 0),
            'total' => $total,
        ];

        if ($total >= $criticalThreshold) {
            $badge = $this->buildBadge($total, 'arbitrages direction critiques', self::VARIANT_CRITICAL, false);
            $badges[$moduleKey] = $badge;
            $entry['reason'] = 'critical_threshold_reached';
            $entry['badge'] = $badge;
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if ($total >= $urgentThreshold) {
            $badge = $this->buildBadge($total, 'arbitrages direction en tension', self::VARIANT_URGENT, false);
            $badges[$moduleKey] = $badge;
            $entry['reason'] = 'urgent_threshold_reached';
            $entry['badge'] = $badge;
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if ($total > 0) {
            $badge = $this->buildBadge($total, 'arbitrages direction à suivre', self::VARIANT_WATCH, false);
            $badges[$moduleKey] = $badge;
            $entry['reason'] = 'watch_signal';
            $entry['badge'] = $badge;
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendValidationFebBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_validation_feb';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'employee_legal_requests');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if ($this->currentUserId === null) {
            $entry['reason'] = 'missing_current_user';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $servicePerm = \FebValidationPolicy::getUserServicePermissions($this->pdo, $this->currentUserId);
            $entry['permission_granted'] = !empty($servicePerm['all']) || (!empty($servicePerm['ids']) && is_array($servicePerm['ids']));
            if (!$entry['permission_granted']) {
                $entry['reason'] = 'feb_scope_missing';
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            $serviceFilter = !empty($servicePerm['all']) ? null : (array) ($servicePerm['ids'] ?? []);
            $counts = $this->getLegalManager()->countRequestsForTeam('finance', 'feb', $serviceFilter);
            $count = (int) ($counts['submitted'] ?? 0);
            $entry['counts'] = [
                'submitted_count' => $count,
                'service_scope' => !empty($servicePerm['all']) ? 'all' : count($serviceFilter ?? []),
            ];
            if ($count > 0) {
                $badge = $this->buildBadge($count, 'FEB à valider', self::VARIANT_WATCH, true);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendSupplierBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_fournisseur';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'fournisseur_banamur');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if (!$this->tableExists('fournisseur_banamur')) {
            $entry['reason'] = 'table_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM fournisseur_banamur WHERE validation_status = 'pending'")->fetchColumn();
            $entry['counts'] = [
                'pending_count' => $count,
            ];
            if ($count > 0) {
                $badge = $this->buildBadge($count, 'fournisseurs à valider', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendDocumentationBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_documentation';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'doc_document_shares,doc_folder_shares');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_documentation', 'document_admin', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $hasDocumentShares = $this->tableExists('doc_document_shares');
        $hasFolderShares = $this->tableExists('doc_folder_shares');
        if (!$hasDocumentShares && !$hasFolderShares) {
            $entry['reason'] = 'table_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $urgentDays = (int) ($this->getModuleThresholds($moduleKey)['urgent_expiring_within_days'] ?? 2);
            $watchDays = (int) ($this->getModuleThresholds($moduleKey)['watch_expiring_within_days'] ?? 7);
            $parts = [];
            if ($hasDocumentShares) {
                $parts[] = 'SELECT expires_at, revoked_at FROM doc_document_shares';
            }
            if ($hasFolderShares) {
                $parts[] = 'SELECT expires_at, revoked_at FROM doc_folder_shares';
            }

            $sql = "SELECT
                        SUM(CASE WHEN revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at < NOW() THEN 1 ELSE 0 END) AS expired_count,
                        SUM(CASE WHEN revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at >= NOW() AND expires_at <= DATE_ADD(NOW(), INTERVAL {$urgentDays} DAY) THEN 1 ELSE 0 END) AS urgent_count,
                        SUM(CASE WHEN revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at > DATE_ADD(NOW(), INTERVAL {$urgentDays} DAY) AND expires_at <= DATE_ADD(NOW(), INTERVAL {$watchDays} DAY) THEN 1 ELSE 0 END) AS watch_count
                    FROM (" . implode(' UNION ALL ', $parts) . ") share_rows";
            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
            $expiredCount = (int) ($row['expired_count'] ?? 0);
            $urgentCount = (int) ($row['urgent_count'] ?? 0);
            $watchCount = (int) ($row['watch_count'] ?? 0);
            $entry['counts'] = [
                'expired_count' => $expiredCount,
                'urgent_count' => $urgentCount,
                'watch_count' => $watchCount,
            ];

            if ($expiredCount > 0) {
                $badge = $this->buildBadge($expiredCount, 'partages documentation expirés', self::VARIANT_CRITICAL, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'critical_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            if ($urgentCount > 0) {
                $badge = $this->buildBadge($urgentCount, 'partages à renouveler vite', self::VARIANT_URGENT, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'urgent_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            if ($watchCount > 0) {
                $badge = $this->buildBadge($watchCount, 'partages bientôt expirés', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendFacturationBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_facturation';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'facture_achat_banamur,facture_vente_banamur');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_facturation', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $hasPurchaseInvoices = $this->tableExists('facture_achat_banamur');
        $hasSalesInvoices = $this->tableExists('facture_vente_banamur');
        if (!$hasPurchaseInvoices && !$hasSalesInvoices) {
            $entry['reason'] = 'table_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $purchaseToVerify = 0;
            $salesToSign = 0;
            $salesError = 0;

            if ($hasPurchaseInvoices) {
                $purchaseToVerify = (int) $this->pdo->query("SELECT COUNT(*) FROM facture_achat_banamur WHERE statut = 'a_verifier'")->fetchColumn();
            }

            if ($hasSalesInvoices) {
                $row = $this->pdo->query(
                    "SELECT
                        SUM(CASE WHEN statut = 'a_signer' THEN 1 ELSE 0 END) AS to_sign_count,
                        SUM(CASE WHEN statut = 'erreur' THEN 1 ELSE 0 END) AS error_count
                     FROM facture_vente_banamur"
                )->fetch(PDO::FETCH_ASSOC) ?: [];
                $salesToSign = (int) ($row['to_sign_count'] ?? 0);
                $salesError = (int) ($row['error_count'] ?? 0);
            }

            $pendingTotal = $purchaseToVerify + $salesToSign;
            $criticalThreshold = (int) ($this->getModuleThresholds($moduleKey)['critical_error_total'] ?? 1);
            $urgentThreshold = (int) ($this->getModuleThresholds($moduleKey)['urgent_pending_total'] ?? 5);
            $entry['counts'] = [
                'purchase_to_verify' => $purchaseToVerify,
                'sales_to_sign' => $salesToSign,
                'sales_error' => $salesError,
                'pending_total' => $pendingTotal,
            ];

            if ($salesError >= $criticalThreshold && $salesError > 0) {
                $badge = $this->buildBadge($salesError, 'factures en erreur à corriger', self::VARIANT_CRITICAL, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'critical_threshold_reached';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            if ($pendingTotal >= $urgentThreshold) {
                $badge = $this->buildBadge($pendingTotal, 'factures à traiter en priorité', self::VARIANT_URGENT, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'urgent_threshold_reached';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            if ($pendingTotal > 0) {
                $badge = $this->buildBadge($pendingTotal, 'factures à vérifier ou signer', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendCrmBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_crm';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'crm_opportunite_banamur');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_crm', 'acces_direction', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        if (!$this->tableExists('crm_opportunite_banamur')) {
            $entry['reason'] = 'table_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $cols = $this->tableColumns('crm_opportunite_banamur');
            if (!in_array('next_action_at', $cols, true) || !in_array('etape', $cols, true)) {
                $entry['reason'] = 'columns_missing';
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            $urgentDays = (int) ($this->getModuleThresholds($moduleKey)['urgent_window_days'] ?? 1);
            $watchDays = (int) ($this->getModuleThresholds($moduleKey)['watch_window_days'] ?? 3);

            $criticalCount = (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM crm_opportunite_banamur
                 WHERE next_action_at IS NOT NULL
                   AND next_action_at < NOW()
                   AND COALESCE(etape, '') NOT IN ('Gagné', 'Perdu')"
            )->fetchColumn();
            $entry['counts']['critical_count'] = $criticalCount;

            if ($criticalCount > 0) {
                $badge = $this->buildBadge($criticalCount, 'actions CRM en retard', self::VARIANT_CRITICAL, true);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'critical_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            $urgentCount = (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM crm_opportunite_banamur
                 WHERE next_action_at IS NOT NULL
                   AND next_action_at >= NOW()
                   AND next_action_at <= DATE_ADD(NOW(), INTERVAL {$urgentDays} DAY)
                   AND COALESCE(etape, '') NOT IN ('Gagné', 'Perdu')"
            )->fetchColumn();
            $entry['counts']['urgent_count'] = $urgentCount;

            if ($urgentCount > 0) {
                $badge = $this->buildBadge($urgentCount, 'actions CRM imminentes', self::VARIANT_URGENT, true);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'urgent_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }

            $watchCount = (int) $this->pdo->query(
                "SELECT COUNT(*)
                 FROM crm_opportunite_banamur
                 WHERE next_action_at IS NOT NULL
                   AND next_action_at > DATE_ADD(NOW(), INTERVAL {$urgentDays} DAY)
                   AND next_action_at <= DATE_ADD(NOW(), INTERVAL {$watchDays} DAY)
                   AND COALESCE(etape, '') NOT IN ('Gagné', 'Perdu')"
            )->fetchColumn();
            $entry['counts']['watch_count'] = $watchCount;

            if ($watchCount > 0) {
                $badge = $this->buildBadge($watchCount, 'actions CRM proches', self::VARIANT_WATCH, true);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendLogisticsBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_logistique';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'demande_essence,logistics_assets,logistics_asset_repairs');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_logistique', 'acces_admin']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        try {
            $count = 0;
            $fuelCount = 0;
            $stockCount = 0;
            $repairCount = 0;

            if ($this->tableExists('demande_essence')) {
                $cols = $this->tableColumns('demande_essence');
                if (in_array('validation_status', $cols, true)) {
                    $sql = "SELECT COUNT(*) FROM demande_essence WHERE validation_status IS NULL OR validation_status = '' OR validation_status IN ('pending', 'submitted')";
                    if (in_array('desactive', $cols, true)) {
                        $sql .= ' AND COALESCE(desactive, 0) = 0';
                    }
                    $fuelCount = (int) $this->pdo->query($sql)->fetchColumn();
                    $count += $fuelCount;
                }
            }

            if ($this->tableExists('logistics_assets')) {
                $stockCount = (int) $this->pdo->query("SELECT COUNT(*) FROM logistics_assets WHERE COALESCE(quantity_available, 0) <= COALESCE(min_threshold, 0) AND COALESCE(min_threshold, 0) > 0")->fetchColumn();
                $count += $stockCount;
            }

            if ($this->tableExists('logistics_asset_repairs')) {
                $repairCount = (int) $this->pdo->query("SELECT COUNT(*) FROM logistics_asset_repairs WHERE COALESCE(repair_status, '') IN ('ouverte', 'open', 'en_cours', 'pending')")->fetchColumn();
                $count += $repairCount;
            }

            $entry['counts'] = [
                'fuel_count' => $fuelCount,
                'stock_count' => $stockCount,
                'repair_count' => $repairCount,
                'total' => $count,
            ];

            if ($count > 0) {
                $badge = $this->buildBadge($count, 'points logistiques à suivre', self::VARIANT_WATCH, false);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = 'watch_signal';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        } catch (Throwable $e) {
            $entry['reason'] = 'query_error';
            $entry['error'] = $e->getMessage();
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['reason'] = 'no_signal';
        $this->storeDebugEntry($debug, $entry);
    }

    private function appendDecaissementWorkflowBadges(array &$badges, string $scope, array &$debug): void
    {
        $counts = $this->loadDecaissementWorkflowBacklogCounts();

        $decaissementEntry = $this->createDebugEntry('module_decaissement', $scope, 'fiche_decaissement');
        $decaissementEntry['permission_granted'] = $this->hasAnyPermission(['acces_decaissement', 'acces_conformite', 'acces_approbation', 'acces_achat', 'acces_depenses_planifiees', 'acces_tresorerie']);
        $decaissementEntry['counts'] = [
            'approbation' => (int) ($counts['approbation'] ?? 0),
        ];

        $comptaEntry = $this->createDebugEntry('module_compta', $scope, 'fiche_decaissement');
        $comptaEntry['permission_granted'] = $this->hasAnyPermission(['acces_achat', 'acces_admin']);
        $comptaEntry['counts'] = [
            'achat' => (int) ($counts['achat'] ?? 0),
        ];

        $tresorerieEntry = $this->createDebugEntry('module_tresorerie', $scope, 'fiche_decaissement');
        $tresorerieEntry['permission_granted'] = $this->hasAnyPermission(['acces_tresorerie', 'acces_admin']);
        $tresorerieEntry['counts'] = [
            'compta' => (int) ($counts['compta'] ?? 0),
        ];

        if ($this->shouldHandleModule('module_decaissement', $scope) && $this->hasAnyPermission(['acces_decaissement', 'acces_conformite', 'acces_approbation', 'acces_achat', 'acces_depenses_planifiees', 'acces_tresorerie']) && ($counts['approbation'] ?? 0) > 0) {
            $badge = $this->buildBadge((int) $counts['approbation'], 'fiches à approuver', self::VARIANT_WATCH, false);
            $badges['module_decaissement'] = $badge;
            $decaissementEntry['reason'] = 'watch_signal';
            $decaissementEntry['badge'] = $badge;
        } else {
            $decaissementEntry['reason'] = !$decaissementEntry['handled'] ? 'scope_filtered' : (!$decaissementEntry['permission_granted'] ? 'permission_missing' : 'no_signal');
        }

        if ($this->shouldHandleModule('module_compta', $scope) && $this->hasAnyPermission(['acces_achat', 'acces_admin']) && ($counts['achat'] ?? 0) > 0) {
            $badge = $this->buildBadge((int) $counts['achat'], 'fiches à valider', self::VARIANT_WATCH, false);
            $badges['module_compta'] = $badge;
            $comptaEntry['reason'] = 'watch_signal';
            $comptaEntry['badge'] = $badge;
        } else {
            $comptaEntry['reason'] = !$comptaEntry['handled'] ? 'scope_filtered' : (!$comptaEntry['permission_granted'] ? 'permission_missing' : 'no_signal');
        }

        if ($this->shouldHandleModule('module_tresorerie', $scope) && $this->hasAnyPermission(['acces_tresorerie', 'acces_admin']) && ($counts['compta'] ?? 0) > 0) {
            $badge = $this->buildBadge((int) $counts['compta'], 'fiches à décaisser', self::VARIANT_WATCH, false);
            $badges['module_tresorerie'] = $badge;
            $tresorerieEntry['reason'] = 'watch_signal';
            $tresorerieEntry['badge'] = $badge;
        } else {
            $tresorerieEntry['reason'] = !$tresorerieEntry['handled'] ? 'scope_filtered' : (!$tresorerieEntry['permission_granted'] ? 'permission_missing' : 'no_signal');
        }

        $this->storeDebugEntry($debug, $decaissementEntry);
        $this->storeDebugEntry($debug, $comptaEntry);
        $this->storeDebugEntry($debug, $tresorerieEntry);
    }

    private function appendEmailsBadge(array &$badges, string $scope, array &$debug): void
    {
        $moduleKey = 'module_emails';
        $entry = $this->createDebugEntry($moduleKey, $scope, 'session.mailbox_unread_cache_*');

        if (!$entry['handled']) {
            $entry['reason'] = 'scope_filtered';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $entry['permission_granted'] = $this->hasAnyPermission(['acces_mail']);
        if (!$entry['permission_granted']) {
            $entry['reason'] = 'permission_missing';
            $this->storeDebugEntry($debug, $entry);
            return;
        }

        $mailCacheTs = (int) ($this->session['mailbox_unread_cache_ts'] ?? 0);
        $mailCacheCount = $this->session['mailbox_unread_cache_count'] ?? null;
        if ($mailCacheTs > 0 && is_numeric($mailCacheCount) && (time() - $mailCacheTs) <= 600) {
            $count = (int) $mailCacheCount;
            $entry['counts'] = [
                'cache_count' => $count,
                'cache_age_seconds' => time() - $mailCacheTs,
            ];
            if ($count > 0 || $scope === self::SCOPE_VOLATILE) {
                $badge = $this->buildBadge($count, 'emails non lus', self::VARIANT_WATCH, true);
                $badges[$moduleKey] = $badge;
                $entry['reason'] = $count > 0 ? 'watch_signal' : 'volatile_zero_seed';
                $entry['badge'] = $badge;
                $this->storeDebugEntry($debug, $entry);
                return;
            }
        }

        $entry['reason'] = 'cache_missing_or_expired';
        $this->storeDebugEntry($debug, $entry);
    }

    private function buildBadge(int $count, string $label, string $variant, bool $volatile): array
    {
        return [
            'count' => max(0, $count),
            'label' => $label,
            'variant' => in_array($variant, [self::VARIANT_CRITICAL, self::VARIANT_URGENT, self::VARIANT_WATCH], true) ? $variant : self::VARIANT_WATCH,
            'volatile' => $volatile,
        ];
    }

    private function createDebugEntry(string $moduleKey, string $scope, string $source): array
    {
        return [
            'module_key' => $moduleKey,
            'scope' => $scope,
            'handled' => $this->shouldHandleModule($moduleKey, $scope),
            'permission_granted' => false,
            'volatile' => self::isVolatileModule($moduleKey),
            'source' => $source,
            'thresholds' => $this->getModuleThresholds($moduleKey),
            'counts' => [],
            'reason' => '',
            'badge' => $this->buildBadge(0, '', self::VARIANT_WATCH, self::isVolatileModule($moduleKey)),
        ];
    }

    private function storeDebugEntry(array &$debug, array $entry): void
    {
        $debug[$entry['module_key']] = $entry;
    }

    private function normalizeScope(string $scope): string
    {
        return $scope === self::SCOPE_VOLATILE ? self::SCOPE_VOLATILE : self::SCOPE_ALL;
    }

    private function getModuleThresholds(string $moduleKey): array
    {
        $defaults = self::MODULE_THRESHOLDS[$moduleKey] ?? [];
        $persisted = (array) ($this->getSettingsRows()[$moduleKey]['thresholds'] ?? []);
        return array_merge($defaults, $persisted);
    }

    private function getModulePresentation(string $moduleKey): array
    {
        $defaults = self::MODULE_PRESENTATION[$moduleKey] ?? [];
        $persisted = $this->getSettingsRows()[$moduleKey] ?? [];
        $variantDefaults = (array) ($defaults['variant_labels'] ?? []);
        $variantPersisted = (array) ($persisted['variant_labels'] ?? []);

        return [
            'counted_description' => trim((string) ($persisted['counted_description'] ?? ($defaults['counted_description'] ?? ''))),
            'variant_labels' => array_merge($variantDefaults, array_filter($variantPersisted, static function ($value): bool {
                return trim((string) $value) !== '';
            })),
        ];
    }

    private function applyBadgePresentation(array $badges): array
    {
        foreach ($badges as $moduleKey => $badge) {
            if (!is_array($badge)) {
                continue;
            }

            $variant = (string) ($badge['variant'] ?? self::VARIANT_WATCH);
            $presentation = $this->getModulePresentation((string) $moduleKey);
            $variantLabels = (array) ($presentation['variant_labels'] ?? []);
            $overrideLabel = trim((string) ($variantLabels[$variant] ?? ''));
            if ($overrideLabel !== '') {
                $badges[$moduleKey]['label'] = $overrideLabel;
            }
        }

        return $badges;
    }

    private function applyDebugPresentation(array $debug): array
    {
        foreach ($debug as $moduleKey => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $presentation = $this->getModulePresentation((string) $moduleKey);
            $debug[$moduleKey]['counted_description'] = (string) ($presentation['counted_description'] ?? '');
            $debug[$moduleKey]['variant_labels'] = (array) ($presentation['variant_labels'] ?? []);
            $debug[$moduleKey]['thresholds'] = $this->getModuleThresholds((string) $moduleKey);

            if (is_array($entry['badge'] ?? null)) {
                $badgeList = $this->applyBadgePresentation([$moduleKey => $entry['badge']]);
                $debug[$moduleKey]['badge'] = $badgeList[$moduleKey] ?? $entry['badge'];
            }
        }

        return $debug;
    }

    private function getSettingsRows(): array
    {
        if ($this->settingsRowsCache !== null) {
            return $this->settingsRowsCache;
        }

        try {
            $this->ensureBadgeSettingsTable();
            $stmt = $this->pdo->query('SELECT module_key, counted_description, variant_labels_json, thresholds_json, updated_by, updated_at FROM portal_badge_settings ORDER BY module_key ASC');
            $rows = $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : [];
            $settings = [];

            foreach ($rows as $row) {
                $moduleKey = trim((string) ($row['module_key'] ?? ''));
                if ($moduleKey === '') {
                    continue;
                }

                $variantLabels = json_decode((string) ($row['variant_labels_json'] ?? ''), true);
                $thresholds = json_decode((string) ($row['thresholds_json'] ?? ''), true);

                $settings[$moduleKey] = [
                    'module_key' => $moduleKey,
                    'counted_description' => trim((string) ($row['counted_description'] ?? '')),
                    'variant_labels' => is_array($variantLabels) ? $variantLabels : [],
                    'thresholds' => is_array($thresholds) ? $thresholds : [],
                    'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
                    'updated_at' => (string) ($row['updated_at'] ?? ''),
                ];
            }

            $this->settingsRowsCache = $settings;
        } catch (Throwable $e) {
            $this->settingsRowsCache = [];
        }

        return $this->settingsRowsCache;
    }

    private function ensureBadgeSettingsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS portal_badge_settings (
                module_key VARCHAR(120) NOT NULL PRIMARY KEY,
                counted_description TEXT NULL,
                variant_labels_json LONGTEXT NULL,
                thresholds_json LONGTEXT NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
    }

    private function shouldHandleModule(string $moduleKey, string $scope): bool
    {
        return $scope === self::SCOPE_ALL || self::isVolatileModule($moduleKey);
    }

    private function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $permissionKey) {
            if (!empty($this->permissions[$permissionKey])) {
                return true;
            }
        }
        return false;
    }

    private function getLegalManager(): \EmployeeLegalRequestManager
    {
        if ($this->legalManager === null) {
            $this->legalManager = new \EmployeeLegalRequestManager($this->pdo);
        }
        return $this->legalManager;
    }

    private function loadDecaissementWorkflowBacklogCounts(): array
    {
        $counts = [
            'approbation' => 0,
            'achat' => 0,
            'compta' => 0,
        ];

        if (!$this->tableExists('fiche_decaissement')) {
            return $counts;
        }

        try {
            $cols = $this->tableColumns('fiche_decaissement');
            $hasDateMin = in_array('date_decaissement_minimum', $cols, true);
            $hasEtat = in_array('etat_fiche', $cols, true);
            $achatDateCond = $hasDateMin ? ' AND (f.date_decaissement_minimum IS NULL OR f.date_decaissement_minimum <= CURDATE())' : '';

            $sql = "SELECT
                        SUM(CASE WHEN f.conforme = 1 AND f.decaisse = 0 AND COALESCE(f.approuve, 0) = 0 THEN 1 ELSE 0 END) AS backlog_approbation,
                        SUM(CASE WHEN f.conforme = 1 AND COALESCE(f.approuve, 0) = 1 AND f.decaisse = 0" . ($hasEtat ? " AND COALESCE(f.etat_fiche, 0) = 0" : '') . $achatDateCond . " THEN 1 ELSE 0 END) AS backlog_achat,
                        SUM(CASE WHEN f.conforme = 1 AND COALESCE(f.approuve, 0) = 1 AND f.decaisse = 0" . ($hasEtat ? " AND COALESCE(f.etat_fiche, 0) = 1" : '') . $achatDateCond . " THEN 1 ELSE 0 END) AS backlog_compta
                    FROM fiche_decaissement f
                    LEFT JOIN chantier_banamur c_scope ON c_scope.id=f.chantier_id
                    WHERE (f.chantier_id IS NULL OR f.chantier_id=0 OR c_scope.archived_at IS NULL)";

            $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
            $counts['approbation'] = (int) ($row['backlog_approbation'] ?? 0);
            $counts['achat'] = (int) ($row['backlog_achat'] ?? 0);
            $counts['compta'] = (int) ($row['backlog_compta'] ?? 0);
        } catch (Throwable $e) {
        }

        return $counts;
    }

    private function tableExists(string $tableName): bool
    {
        if (array_key_exists($tableName, $this->tableExistsCache)) {
            return $this->tableExistsCache[$tableName];
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
        $stmt->execute(['table_name' => $tableName]);
        return $this->tableExistsCache[$tableName] = ((int) $stmt->fetchColumn() > 0);
    }

    private function tableColumns(string $tableName): array
    {
        if (isset($this->columnsCache[$tableName])) {
            return $this->columnsCache[$tableName];
        }

        if (!$this->tableExists($tableName)) {
            return $this->columnsCache[$tableName] = [];
        }

        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM ' . $tableName)->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            return $this->columnsCache[$tableName] = array_values(array_map('strval', $cols));
        } catch (Throwable $e) {
            return $this->columnsCache[$tableName] = [];
        }
    }
}
