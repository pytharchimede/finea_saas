<?php

require_once __DIR__ . '/../repositories/DevisModuleRepository.php';

class DevisModuleService
{
    private DevisModuleRepository $repository;

    public function __construct(DevisModuleRepository $repository)
    {
        $this->repository = $repository;
    }

    public function dashboard(): array
    {
        $modules = $this->modules();

        return [
            'modules' => $modules,
            'stats' => [
                'devis' => $this->repository->countRows('devis_banamur'),
                'clients' => $this->repository->countRows('client_banamur'),
                'offres' => $this->repository->countRows('offre_banamur'),
                'unites' => $this->repository->countRows('unites_mesure'),
                'debourses' => $this->repository->countRows('debourse_banamur'),
                'chantiers' => $this->repository->countRows('chantier_banamur'),
                'montant_devis' => $this->repository->sumColumn('devis_banamur', 'total_ttc'),
            ],
            'recent' => [
                'devis' => $this->repository->latestRows('devis_banamur', 5),
                'chantiers' => $this->repository->latestRows('chantier_banamur', 5),
            ],
        ];
    }

    public function modules(): array
    {
        return [
            'devis' => [
                'label' => 'Devis',
                'description' => 'Creation, modification, validation technique et validation DG.',
                'href' => '../devis/liste.php',
                'dashboard_href' => '../devis/index.php',
                'settings_href' => '../devis/parametrage.php',
                'create_href' => '../devis/generer.php',
                'permission' => 'acces_devis',
                'icon' => 'fa-file-contract',
                'accent' => '#1d4ed8',
            ],
            'fusion_devis' => [
                'label' => 'Fusion financière',
                'description' => 'Déplacer de façon contrôlée tout l’historique d’un devis en doublon.',
                'href' => '../devis/transfert_financier.php',
                'dashboard_href' => '../devis/transfert_financier.php',
                'permission' => 'transferer_historique_devis',
                'icon' => 'fa-code-merge',
                'accent' => '#0f766e',
            ],
            'debourse' => [
                'label' => 'Debourse',
                'description' => 'Pilotage des debourses issus des devis valides.',
                'href' => '../debourse/liste.php',
                'dashboard_href' => '../debourse/dashboard.php',
                'settings_href' => '../debourse/parametrage.php',
                'create_href' => '../debourse/gerer.php',
                'permission' => 'modifier_debourse',
                'icon' => 'fa-wallet',
                'accent' => '#b45309',
            ],
            'clients' => [
                'label' => 'Clients',
                'description' => 'Base clients, contacts et rattachements aux devis.',
                'href' => '../clients/liste.php',
                'dashboard_href' => '../clients/dashboard.php',
                'settings_href' => '../clients/parametrage.php',
                'create_href' => '../clients/ajouter.php',
                'permission' => 'acces_liste_client',
                'icon' => 'fa-users',
                'accent' => '#0f766e',
            ],
            'offres' => [
                'label' => 'Projets',
                'description' => 'Portefeuille des projets commerciaux et pièces associées.',
                'href' => '../offres/liste.php',
                'dashboard_href' => '../offres/dashboard.php',
                'settings_href' => '../offres/parametrage.php',
                'permission' => 'acces_liste_offre',
                'icon' => 'fa-tags',
                'accent' => '#7c3aed',
            ],
            'unites' => [
                'label' => 'Unites',
                'description' => 'Referentiel des unites de mesure utilisees dans les lignes.',
                'href' => '../unites/liste.php',
                'dashboard_href' => '../unites/dashboard.php',
                'settings_href' => '../unites/parametrage.php',
                'create_href' => '../unites/ajouter.php',
                'permission' => 'acces_liste_unite',
                'icon' => 'fa-ruler-combined',
                'accent' => '#0369a1',
            ],
            'chantiers' => [
                'label' => 'Chantiers',
                'description' => 'Generation depuis devis, demarrage et suivi des activites.',
                'href' => '../chantiers/liste.php',
                'dashboard_href' => '../chantiers/dashboard.php',
                'settings_href' => '../chantiers/parametrage.php',
                'create_href' => '../chantiers/choisir_devis.php',
                'permission' => 'acces_liste_chantier',
                'icon' => 'fa-helmet-safety',
                'accent' => '#475569',
            ],
            'ao' => [
                'label' => 'Veille Appels d Offres',
                'description' => 'Avis publics DGMP, scoring BTP, echeances et alertes sans doublons.',
                'href' => '../devis/veille_ao.php',
                'dashboard_href' => '../devis/veille_ao.php',
                'settings_href' => '../devis/veille_ao_preferences.php',
                'permission' => 'acces_veille_ao',
                'icon' => 'fa-binoculars',
                'accent' => '#fabd02',
            ],
        ];
    }
}
