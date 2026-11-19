<?php

require_once __DIR__ . '/../repositories/DgDashboardRepository.php';
require_once __DIR__ . '/../model/AccessManager.php';
require_once __DIR__ . '/ChantierFinancialComplianceService.php';

class DgDashboardService
{
    private DgDashboardRepository $repository;

    public function __construct(PDO $pdo)
    {
        $this->repository = new DgDashboardRepository($pdo);
        new ChantierFinancialComplianceService($pdo);
    }

    public function build(int $userId, string $period, ?string $dateFrom=null, ?string $dateTo=null, string $trendMode='month'): array
    {
        $period = preg_match('/^\d{4}-\d{2}$/', $period) ? $period : date('Y-m');
        $start = $period . '-01';
        $end = date('Y-m-t', strtotime($start));
        if($dateFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$start=$dateFrom;
        if($dateTo && preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$end=$dateTo;
        if($end<$start){[$start,$end]=[$end,$start];}
        $permissions = [
            'finance' => $this->can(['acces_decaissement', 'acces_feb', 'acces_compta', 'acces_direction']),
            'chantiers' => $this->can(['acces_liste_chantier', 'acces_detail_chantier', 'acces_direction']),
            'rh' => $this->can(['acces_rh', 'acces_direction']),
            'tickets' => $this->can(['acces_ticketing', 'acces_direction']),
            'crm' => $this->can(['acces_crm', 'acces_crm_dashboard', 'acces_direction']),
            'ao' => $this->can(['acces_veille_ao', 'ao_view', 'acces_direction']),
            'documentation' => $this->can(['acces_documentation', 'document_admin', 'acces_direction']),
            'logistics' => $this->can(['acces_logistique', 'acces_direction']),
        ];
        $trendMode=in_array($trendMode,['day','week','month','quarter'],true)?$trendMode:'month';
        $data = ['period' => $period, 'start' => $start, 'end' => $end, 'trend_mode'=>$trendMode, 'permissions' => $permissions];
        foreach ($permissions as $module => $allowed) {
            if (!$allowed) {
                $data[$module] = null;
                continue;
            }
            $data[$module] = $module === 'finance'
                ? $this->repository->finance($start, $end, $trendMode)
                : ($module === 'tickets' ? $this->repository->tickets($userId) : $this->repository->{$module}());
        }
        $data['treasury_decision'] = $permissions['finance']
            ? $this->repository->projectFinanceDecision($start,$end)
            : null;
        $complianceService = new ChantierFinancialComplianceService($this->repository->connection());
        $data['financial_compliance'] = $complianceService->summary();
        if (($data['financial_compliance']['unchecked'] ?? 0) > 0) {
            $complianceService->auditActive();
            $data['financial_compliance'] = $complianceService->summary();
        }
        $data['critical_count'] =
            (int) ($data['finance']['urgent_feb'] ?? 0)
            + (int) ($data['tickets']['overdue'] ?? 0)
            + (int) ($data['chantiers']['late'] ?? 0)
            + (int) ($data['ao']['due_soon'] ?? 0)
            + (int) ($data['documentation']['expiring'] ?? 0)
            + (int) ($data['logistics']['unavailable_assets'] ?? 0);
        $data['statistics'] = $this->buildStatistics($data);
        $data['analysis'] = $this->buildAnalysis($data);
        return $data;
    }

    private function buildStatistics(array $data): array
    {
        $activeChantiers = (int) ($data['chantiers']['active'] ?? 0);
        $lateChantiers = (int) ($data['chantiers']['late'] ?? 0);
        $openTickets = (int) ($data['tickets']['open'] ?? 0);
        $overdueTickets = (int) ($data['tickets']['overdue'] ?? 0);
        $attendanceTotal = (int) ($data['rh']['attendance_headcount'] ?? 0);
        $availableToday = (int) ($data['rh']['present'] ?? 0)
            + (int) ($data['rh']['mission'] ?? 0)
            + (int) ($data['rh']['half_day'] ?? 0);
        $pendingAmount = (float) ($data['finance']['pending_amount'] ?? 0);
        $paidAmount = (float) ($data['finance']['month_paid'] ?? 0);
        $activeAo = (int) ($data['ao']['active'] ?? 0);
        $preparingAo = (int) ($data['ao']['preparing'] ?? 0);
        $treasury = (array) ($data['treasury_decision'] ?? []);
        $realInflows = (float) ($treasury['real_inflows'] ?? 0);
        $realOutflows = (float) ($treasury['real_outflows'] ?? 0);
        $forecastInflows = (float) ($treasury['forecast_inflows'] ?? 0);
        $turnoverHt = (float) ($treasury['turnover_ht'] ?? 0);
        $realizedProfit = (float) ($treasury['realized_profit'] ?? 0);

        return [
            'chantier_late_rate' => $this->rate($lateChantiers, $activeChantiers),
            'ticket_overdue_rate' => $this->rate($overdueTickets, $openTickets),
            'attendance_rate' => $this->rate($availableToday, $attendanceTotal),
            'pending_cash_rate' => $this->rate($pendingAmount, $pendingAmount + $paidAmount),
            'pending_cash_program_total' => $pendingAmount + $paidAmount,
            'cash_coverage_rate' => $this->rate($realInflows, $realOutflows),
            'collection_rate' => $this->rate($realInflows, $realInflows + $forecastInflows),
            'profit_margin_rate' => $this->rate($realizedProfit, $turnoverHt),
            'ao_preparation_rate' => $this->rate($preparingAo, $activeAo),
            'available_today' => $availableToday,
        ];
    }

    private function buildAnalysis(array $data): array
    {
        $stats = $data['statistics'] ?? [];
        $insights = [];

        $lateChantiers = (int) ($data['chantiers']['late'] ?? 0);
        $activeChantiers = (int) ($data['chantiers']['active'] ?? 0);
        $insights[] = $lateChantiers > 0
            ? [
                'severity' => 'danger',
                'title' => 'Planning des chantiers sous surveillance',
                'statement' => sprintf('%d chantier(s) sur %d ont une échéance dépassée, soit %.1f %% du portefeuille actif.', $lateChantiers, $activeChantiers, (float) ($stats['chantier_late_rate'] ?? 0)),
                'recommendation' => 'Demander un point d’avancement aux responsables concernés et valider un plan de rattrapage daté.',
                'icon' => 'fa-helmet-safety',
            ]
            : [
                'severity' => 'success',
                'title' => 'Planning des chantiers maîtrisé',
                'statement' => sprintf('Aucune échéance dépassée n’est détectée sur les %d chantier(s) actifs.', $activeChantiers),
                'recommendation' => 'Maintenir la revue hebdomadaire des jalons et des approvisionnements critiques.',
                'icon' => 'fa-helmet-safety',
            ];

        $pendingAmount = (float) ($data['finance']['pending_amount'] ?? 0);
        $pendingCount = (int) ($data['finance']['pending_count'] ?? 0);
        $insights[] = $pendingAmount > 0
            ? [
                'severity' => (float) ($stats['pending_cash_rate'] ?? 0) >= 50 ? 'warning' : 'info',
                'title' => 'Décaissements à arbitrer',
                'statement' => sprintf('%d fiche(s), pour un total de %s FCFA, attendent encore une décision.', $pendingCount, number_format($pendingAmount, 0, ',', ' ')),
                'recommendation' => 'Prioriser les demandes qui conditionnent la continuité des chantiers et sécuriser leur justification.',
                'icon' => 'fa-money-bill-transfer',
            ]
            : [
                'severity' => 'success',
                'title' => 'Aucun décaissement en attente',
                'statement' => 'Le circuit de validation financier ne présente actuellement aucun montant en attente.',
                'recommendation' => 'Conserver le rythme de validation et la traçabilité des pièces.',
                'icon' => 'fa-money-bill-transfer',
            ];

        $treasury = (array) ($data['treasury_decision'] ?? []);
        if ($treasury !== []) {
            $realBalance = (float) ($treasury['real_balance'] ?? 0);
            $projectedBalance = (float) ($treasury['projected_balance'] ?? 0);
            $forecastInflows = (float) ($treasury['forecast_inflows'] ?? 0);
            $insights[] = [
                'severity' => $realBalance < 0 ? 'danger' : ($projectedBalance < 0 ? 'warning' : 'success'),
                'title' => 'Capacité de trésorerie des chantiers',
                'statement' => sprintf(
                    'Le solde réel est de %s FCFA. Les factures attendues à 90 jours représentent %s FCFA, pour un solde projeté de %s FCFA.',
                    number_format($realBalance, 0, ',', ' '),
                    number_format($forecastInflows, 0, ',', ' '),
                    number_format($projectedBalance, 0, ',', ' ')
                ),
                'recommendation' => $projectedBalance < 0
                    ? 'Décaler les dépenses non critiques, accélérer les encaissements clients et vérifier le solde d’ouverture.'
                    : 'Aligner les prochaines dépenses sur les dates d’encaissement affichées et préserver une marge de sécurité.',
                'icon' => 'fa-vault',
            ];
        }

        $overdueTickets = (int) ($data['tickets']['overdue'] ?? 0);
        $openTickets = (int) ($data['tickets']['open'] ?? 0);
        $insights[] = [
            'severity' => $overdueTickets > 0 ? 'warning' : 'success',
            'title' => $overdueTickets > 0 ? 'Demandes internes hors délai' : 'Délais de traitement respectés',
            'statement' => $overdueTickets > 0
                ? sprintf('%d ticket(s) sur %d ouverts ont dépassé leur délai de traitement.', $overdueTickets, $openTickets)
                : sprintf('Aucun des %d ticket(s) ouverts n’est signalé hors délai.', $openTickets),
            'recommendation' => $overdueTickets > 0
                ? 'Identifier les blocages, nommer un responsable et fixer une date de résolution pour chaque ticket en retard.'
                : 'Poursuivre le suivi des engagements de service.',
            'icon' => 'fa-headset',
        ];

        $attendanceTotal = (int) ($data['rh']['attendance_headcount'] ?? 0);
        $availableToday = (int) ($stats['available_today'] ?? 0);
        $absent = (int) ($data['rh']['absent'] ?? 0);
        $insights[] = [
            'severity' => $attendanceTotal > 0 && (float) ($stats['attendance_rate'] ?? 0) < 80 ? 'warning' : 'success',
            'title' => 'Capacité humaine disponible aujourd’hui',
            'statement' => sprintf('%d collaborateur(s) sur %d sont présents, en mission ou en demi-journée; %d sont absents.', $availableToday, $attendanceTotal, $absent),
            'recommendation' => $absent > 0
                ? 'Vérifier que les équipes critiques de chantier et de support restent suffisamment couvertes.'
                : 'La capacité déclarée permet de maintenir l’activité planifiée.',
            'icon' => 'fa-users',
        ];

        $activeAo = (int) ($data['ao']['active'] ?? 0);
        $dueSoon = (int) ($data['ao']['due_soon'] ?? 0);
        $pipeline = (float) ($data['crm']['pipeline'] ?? 0);
        $insights[] = [
            'severity' => $dueSoon > 0 ? 'info' : 'success',
            'title' => 'Développement commercial',
            'statement' => sprintf('Le pipeline CRM représente %s FCFA et %d appel(s) d’offres restent actifs, dont %d proche(s) de leur date limite.', number_format($pipeline, 0, ',', ' '), $activeAo, $dueSoon),
            'recommendation' => $dueSoon > 0
                ? 'Confirmer immédiatement les responsables, pièces manquantes et dates de bouclage des offres proches de l’échéance.'
                : 'Concentrer les efforts sur les opportunités à meilleure probabilité et meilleure marge attendue.',
            'icon' => 'fa-chart-line',
        ];

        $dangerCount = count(array_filter($insights, static fn(array $row): bool => $row['severity'] === 'danger'));
        $warningCount = count(array_filter($insights, static fn(array $row): bool => $row['severity'] === 'warning'));
        $headline = $dangerCount > 0
            ? 'La situation exige un arbitrage de direction sur les chantiers et les engagements critiques.'
            : ($warningCount > 0
                ? 'L’activité reste pilotable, avec quelques points à traiter rapidement pour éviter une dégradation.'
                : 'Les principaux indicateurs sont maîtrisés; la priorité peut rester sur l’exécution et le développement commercial.');

        return [
            'headline' => $headline,
            'danger_count' => $dangerCount,
            'warning_count' => $warningCount,
            'insights' => $insights,
        ];
    }

    private function rate(float $value, float $total): float
    {
        return $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
    }

    private function can(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (AccessManager::hasPermission($permission)) {
                return true;
            }
        }
        return false;
    }
}
