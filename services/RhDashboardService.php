<?php

require_once __DIR__ . '/../repositories/RhDashboardRepository.php';

class RhDashboardService
{
    private RhDashboardRepository $repository;

    public function __construct(RhDashboardRepository $repository)
    {
        $this->repository = $repository;
    }

    public function buildDashboard(): array
    {
        $dashboardData = $this->repository->getPersonnelDashboardData();
        $stats = $dashboardData['stats'] ?? [];
        $legal = $this->repository->getPendingLegalRequestCounts();
        $explanations = $this->repository->getExplanationSummary();
        $leaveOpening = $this->repository->getLeaveOpeningSummary();
        $contractSetup = $this->repository->getContractSetupSummary();
        $jobDescriptions = $this->repository->getJobDescriptionSummary();
        $recentPending = $this->repository->getRecentPendingLegalRequests(5);
        $analytics = $this->repository->getOperationalAnalytics();

        $alerts = [
            [
                'key' => 'legal_requests',
                'label' => 'Demandes employes',
                'description' => 'Absences, conges, prets, avances, heures sup et regularisations a traiter.',
                'count' => (int) ($legal['total'] ?? 0),
                'href' => 'validation_demandes_rh.php',
                'icon' => 'fa-clipboard-check',
                'accent' => 'emerald',
            ],
            [
                'key' => 'contracts_missing',
                'label' => 'Contrats a parametrer',
                'description' => 'Employes actifs sans contrat RH exploitable par la paie.',
                'count' => (int) ($contractSetup['missing'] ?? 0),
                'href' => 'contrats_employes_rh.php',
                'icon' => 'fa-file-signature',
                'accent' => 'amber',
            ],
            [
                'key' => 'salary_claims',
                'label' => 'Reclamations salariales',
                'description' => 'Dossiers en attente de validation RH dans le circuit de paie.',
                'count' => (int) ($legal['salary_claims'] ?? 0),
                'href' => 'validation_demandes_rh.php?type=reclamation_salariale',
                'icon' => 'fa-file-invoice-dollar',
                'accent' => 'amber',
            ],
            [
                'key' => 'explanations_pending',
                'label' => 'Explications a suivre',
                'description' => 'Demandes ouvertes ou en attente de reponse employe.',
                'count' => (int) ($explanations['pending_response'] ?? 0),
                'href' => 'demandes_explication_rh.php?status=open',
                'icon' => 'fa-comment-dots',
                'accent' => 'rose',
            ],
            [
                'key' => 'leave_opening',
                'label' => 'Soldes conges a initialiser',
                'description' => 'Collaborateurs actifs sans solde initial renseigne.',
                'count' => (int) ($leaveOpening['missing'] ?? 0),
                'href' => 'conges_initialisation_rh.php',
                'icon' => 'fa-calendar-check',
                'accent' => 'cyan',
            ],
            [
                'key' => 'job_descriptions_missing',
                'label' => 'Fiches de poste a editer',
                'description' => 'Collaborateurs internes dont la fonction ne possède aucune fiche de poste active.',
                'count' => (int) ($jobDescriptions['missing'] ?? 0),
                'href' => 'fiches_poste_rh.php?scope=missing',
                'icon' => 'fa-clipboard-list',
                'accent' => 'rose',
            ],
        ];

        return [
            'dashboardData' => $dashboardData,
            'stats' => $stats,
            'alerts' => $alerts,
            'legal' => $legal,
            'explanations' => $explanations,
            'leaveOpening' => $leaveOpening,
            'contractSetup' => $contractSetup,
            'jobDescriptions' => $jobDescriptions,
            'recentPending' => $recentPending,
            'analytics' => $analytics,
            'pending_total' => (int) ($legal['total'] ?? 0) + (int) ($explanations['pending_response'] ?? 0) + (int) ($leaveOpening['missing'] ?? 0) + (int) ($contractSetup['missing'] ?? 0) + (int) ($jobDescriptions['missing'] ?? 0),
        ];
    }
}
