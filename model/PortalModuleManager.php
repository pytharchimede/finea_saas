<?php

class PortalModuleManager
{
    private const TABLE = 'portal_modules';

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
        $this->seedDefaultsIfEmpty();
        $this->pdo->exec("DELETE FROM portal_modules WHERE module_key = 'module_communication' OR target LIKE 'communication/%' OR target = 'communication.php'");
        $this->migrateFormationModule();
        $this->migrateSupplierModule();
        $this->migrateAddressBookModule();
        $this->migrateRemoteAssistanceModule();
        $this->migrateDivertissementsModule();
        $this->migrateAiAssistantModule();
        $this->migrateDevisModules();
        $this->migrateDocumentationModule();
        $this->migrateProceduresModule();
        $this->migrateEmployeeScheduleModule();
        $this->migrateRecurringNeedsModule();
        $this->migratePlanningChantierModule();
        $this->migrateTicketingModule();
        $this->migrateDirectionModule();
        $this->migrateSearchEngineModule();
        $this->migrateTreasuryModules();
        $this->migrateItAssetsModule();
        $this->hideLegacyAccountingModule();
        $this->initializeAccountingModule();
        $this->registerWebsiteModule();
    }

    private function registerWebsiteModule(): void
    {
        $stmt=$this->pdo->prepare("INSERT IGNORE INTO portal_modules (module_key,label,icon,color,target,slug,subtitle,required_permissions_json,sort_order,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)");
        $stmt->execute(['module_website','Site web','fa-globe','#081c65','website/index.php','website','Gestion des contenus du site institutionnel','["acces_admin"]',180]);
    }

    private function initializeAccountingModule(): void
    {
        try {
            require_once __DIR__.'/AccessManager.php';
            AccessManager::ensureOptionalPermissionColumns($this->pdo);
            require_once __DIR__.'/../services/AccountingService.php';
            (new AccountingService($this->pdo))->prepareForUse();
        } catch (Throwable $e) {
            // Keep other portals available; the accounting entry point retries and reports the failure.
            error_log('[accounting_auto_install] '.$e->getMessage());
        }
    }

    private function migrateProceduresModule(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle,
                required_permissions_json, sort_order, is_active
            ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle,
                :permissions, :sort_order, 1
            ) ON DUPLICATE KEY UPDATE
                label=VALUES(label), icon=VALUES(icon), color=VALUES(color),
                target=VALUES(target), slug=VALUES(slug), subtitle=VALUES(subtitle),
                required_permissions_json=VALUES(required_permissions_json),
                sort_order=VALUES(sort_order), is_active=1'
        );
        $stmt->execute([
            'module_key' => 'module_procedures',
            'label' => 'Procédures',
            'icon' => 'fa-diagram-project',
            'color' => '#4169e1',
            'target' => 'procedures/index.php',
            'slug' => 'procedures',
            'subtitle' => 'Créer, organiser et exporter des procédures sous forme de schémas simples',
            'permissions' => '[]',
            'sort_order' => 142,
        ]);
    }

    private function migrateItAssetsModule(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle,
                required_permissions_json, sort_order, is_active
            ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle,
                :permissions, :sort_order, 1
            ) ON DUPLICATE KEY UPDATE
                label=VALUES(label), icon=VALUES(icon), color=VALUES(color),
                target=VALUES(target), slug=VALUES(slug), subtitle=VALUES(subtitle),
                required_permissions_json=VALUES(required_permissions_json),
                sort_order=VALUES(sort_order), is_active=1'
        );
        $stmt->execute([
            'module_key' => 'module_parc_informatique',
            'label' => 'Parc informatique',
            'icon' => 'fa-laptop-code',
            'color' => '#4f46e5',
            'target' => 'parc_informatique/index.php',
            'slug' => 'parc_informatique',
            'subtitle' => 'Inventaire, affectations, incidents, photos et étiquettes QR standardisées',
            'permissions' => json_encode(['acces_parc_informatique', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 29,
        ]);
    }

    private function hideLegacyAccountingModule(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET is_active=0
             WHERE module_key=:module_key'
        );
        $stmt->execute(['module_key' => 'module_compta']);
    }

    private function migrateTreasuryModules(): void
    {
        $modules = [
            [
                'module_key' => 'module_tresorerie',
                'label' => 'Trésorerie en temps réel',
                'icon' => 'fa-building-columns',
                'color' => '#0d9488',
                'target' => 'facturation/tresorerie_chantiers.php',
                'slug' => 'tresorerie_chantiers.php',
                'subtitle' => 'Suivre les entrées, sorties, prévisions et le solde réellement disponible',
                'permissions' => json_encode(['acces_facturation', 'acces_direction', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 58,
            ],
            [
                'module_key' => 'module_gestion_comptable',
                'label' => 'Gestion comptable',
                'icon' => 'fa-book',
                'color' => '#4338ca',
                'target' => 'comptabilite/index.php',
                'slug' => 'comptabilite',
                'subtitle' => 'Écritures avec justificatifs, journaux, grand livre et balance SYSCOHADA',
                'permissions' => json_encode(['acces_comptabilite', 'acces_admin'], JSON_UNESCAPED_UNICODE),
                'sort_order' => 60,
            ],
            [
                'module_key' => 'module_traitement_comptable',
                'label' => 'Traitement des paiements',
                'icon' => 'fa-cash-register',
                'color' => '#059669',
                'target' => 'decaissement/liste_fiches_compta.php',
                'slug' => 'liste_fiches_compta.php',
                'subtitle' => 'Traiter les paiements des fiches de décaissement validées',
                'permissions' => json_encode(['acces_tresorerie'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 59,
            ],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle,
                required_permissions_json, sort_order, is_active
            ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle,
                :permissions, :sort_order, 1
            ) ON DUPLICATE KEY UPDATE
                label=VALUES(label), icon=VALUES(icon), color=VALUES(color),
                target=VALUES(target), slug=VALUES(slug), subtitle=VALUES(subtitle),
                required_permissions_json=VALUES(required_permissions_json),
                sort_order=VALUES(sort_order), is_active=1'
        );
        foreach ($modules as $module) {
            $stmt->execute($module);
        }
    }

    private function migrateSearchEngineModule(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle,
                required_permissions_json, sort_order, is_active
            ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle,
                :permissions, :sort_order, 1
            ) ON DUPLICATE KEY UPDATE
                label=VALUES(label), icon=VALUES(icon), color=VALUES(color),
                target=VALUES(target), slug=VALUES(slug), subtitle=VALUES(subtitle),
                required_permissions_json=VALUES(required_permissions_json), is_active=1'
        );
        $stmt->execute([
            'module_key' => 'module_moteur_finea',
            'label' => 'Moteur Finéa',
            'icon' => 'fa-magnifying-glass',
            'color' => '#fabd02',
            'target' => 'moteur.php',
            'slug' => 'moteur',
            'subtitle' => 'Recherche transversale sécurisée dans les modules, demandes, documents, personnes et partenaires',
            'permissions' => '[]',
            'sort_order' => 1,
        ]);
    }

    private function migrateDirectionModule(): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . self::TABLE . '
             SET label=:label, icon=:icon, color=:color, target=:target, slug=:slug,
                 subtitle=:subtitle, required_permissions_json=:permissions
             WHERE module_key=:module_key'
        );
        $stmt->execute([
            'label' => 'Pilotage Direction Generale',
            'icon' => 'fa-chart-pie',
            'color' => '#1d2b57',
            'target' => 'dashboard_dg.php',
            'slug' => 'dashboard_dg',
            'subtitle' => 'Vision transverse Finance, Chantiers, RH, Tickets, CRM, AO et Documentation',
            'permissions' => json_encode(['acces_direction', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'module_key' => 'module_direction',
        ]);
    }


    private function migrateTicketingModule(): void
    {
        $module = [
            'module_key' => 'module_ticketing',
            'label' => 'Centre de Services',
            'icon' => 'fa-headset',
            'color' => '#1d2b57',
            'target' => 'ticketing/index.php',
            'slug' => 'ticketing',
            'subtitle' => 'Tickets internes, affectation par service ou personne, suivi SLA et conversations',
            'required_permissions_json' => json_encode(
                ['acces_ticketing', 'acces_admin'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'sort_order' => 28,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle,
                required_permissions_json, sort_order, is_active
            ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle,
                :required_permissions_json, :sort_order, :is_active
            ) ON DUPLICATE KEY UPDATE
                label = VALUES(label), icon = VALUES(icon), color = VALUES(color),
                target = VALUES(target), slug = VALUES(slug), subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order), is_active = VALUES(is_active)'
        );
        $stmt->execute($module);
    }

    private function migratePlanningChantierModule(): void
    {
        $module = [
            'module_key' => 'module_planning_chantier',
            'label' => 'Planning Chantier',
            'icon' => 'fa-calendar-check',
            'color' => '#1d2b57',
            'target' => 'planning_chantier/dashboard.php',
            'slug' => 'planning_chantier',
            'subtitle' => 'Suivi des avancements, écarts, blocages et réunions quotidiennes de chantier',
            'required_permissions_json' => json_encode(
                [
                    'acces_planning_chantier',
                    'acces_liste_chantier',
                    'acces_dashboard',
                    'acces_admin'
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'sort_order' => 27,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
            module_key,
            label,
            icon,
            color,
            target,
            slug,
            subtitle,
            required_permissions_json,
            sort_order,
            is_active
        )
        VALUES (
            :module_key,
            :label,
            :icon,
            :color,
            :target,
            :slug,
            :subtitle,
            :required_permissions_json,
            :sort_order,
            :is_active
        )
        ON DUPLICATE KEY UPDATE
            label = VALUES(label),
            icon = VALUES(icon),
            color = VALUES(color),
            target = VALUES(target),
            slug = VALUES(slug),
            subtitle = VALUES(subtitle),
            required_permissions_json = VALUES(required_permissions_json),
            sort_order = VALUES(sort_order),
            is_active = VALUES(is_active)'
        );

        $stmt->execute($module);

        // Retain the historical key for audit references, but never recreate its card.
        $retire = $this->pdo->prepare('UPDATE ' . self::TABLE . ' SET is_active = 0 WHERE module_key = :key AND is_active = 1');
        $retire->execute(['key' => 'module_planning']);
    }


    private function migrateRecurringNeedsModule(): void
    {
        $module = [
            'module_key' => 'module_besoins_recurrents',
            'label' => 'Besoins récurrents',
            'icon' => 'fa-arrows-rotate',
            'color' => '#1d2b57',
            'target' => 'recurring_needs/index.php',
            'slug' => 'recurring_needs',
            'subtitle' => 'Programmer les FEB répétitives avec rappels et génération automatique',
            'required_permissions_json' => json_encode(['acces_besoins_recurrents', 'acces_employe', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 175,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );

        $stmt->execute($module);
    }

    private function migrateEmployeeScheduleModule(): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . '
            SET target = :target,
                slug = :slug
            WHERE module_key = :module_key');
        $stmt->execute([
            'target' => 'emploi_du_temps/index.php',
            'slug' => 'emploi_du_temps',
            'module_key' => 'module_emploi_du_temps',
        ]);
    }

    private function migrateDocumentationModule(): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . '
            SET target = :target,
                slug = :slug
            WHERE module_key = :module_key');
        $stmt->execute([
            'target' => 'documentation/index.php',
            'slug' => 'documentation',
            'module_key' => 'module_documentation',
        ]);
    }

    private function migrateDevisModules(): void
    {
        $modules = [
            [
                'module_key' => 'module_devis',
                'label' => 'Centre devis et chantiers',
                'icon' => 'fa-drafting-compass',
                'color' => '#1d4ed8',
                'target' => 'devis/index.php',
                'slug' => 'devis',
                'subtitle' => 'Devis, clients, projets, unités, déboursés et génération chantier',
                'required_permissions_json' => json_encode(['acces_dashboard', 'acces_devis', 'acces_generer_devis', 'acces_liste_chantier', 'acces_liste_client', 'acces_liste_offre', 'acces_liste_unite', 'acces_veille_ao'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 20,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_generation',
                'label' => 'Generation devis',
                'icon' => 'fa-file-signature',
                'color' => '#2563eb',
                'target' => 'devis/generer.php',
                'slug' => 'devis/generer',
                'subtitle' => 'Creer un devis, ses lignes et son circuit de validation',
                'required_permissions_json' => json_encode(['acces_generer_devis', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 21,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_liste',
                'label' => 'Devis',
                'icon' => 'fa-file-contract',
                'color' => '#1d4ed8',
                'target' => 'devis/liste.php',
                'slug' => 'devis/liste',
                'subtitle' => 'Consulter, modifier, valider et transformer les devis',
                'required_permissions_json' => json_encode(['acces_devis', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 22,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_clients',
                'label' => 'Clients',
                'icon' => 'fa-users',
                'color' => '#0f766e',
                'target' => 'clients/dashboard.php',
                'slug' => 'clients',
                'subtitle' => 'Gerer les clients utilises dans les devis',
                'required_permissions_json' => json_encode(['acces_liste_client', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 23,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_offres',
                'label' => 'Projets',
                'icon' => 'fa-tags',
                'color' => '#7c3aed',
                'target' => 'offres/dashboard.php',
                'slug' => 'offres',
                'subtitle' => 'Administrer le portefeuille des projets et pièces associées',
                'required_permissions_json' => json_encode(['acces_liste_offre', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 24,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_unites',
                'label' => 'Unites',
                'icon' => 'fa-ruler-combined',
                'color' => '#0369a1',
                'target' => 'unites/dashboard.php',
                'slug' => 'unites',
                'subtitle' => 'Maintenir le referentiel des unites de mesure',
                'required_permissions_json' => json_encode(['acces_liste_unite', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 25,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis_chantiers',
                'label' => 'Chantiers',
                'icon' => 'fa-helmet-safety',
                'color' => '#475569',
                'target' => 'chantiers/dashboard.php',
                'slug' => 'chantiers',
                'subtitle' => 'Generer, demarrer et suivre les chantiers issus des devis',
                'required_permissions_json' => json_encode(['acces_liste_chantier', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 26,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_debours',
                'label' => 'Gestion debourses',
                'icon' => 'fa-wallet',
                'color' => '#b45309',
                'target' => 'debourse/dashboard.php',
                'slug' => 'debourse',
                'subtitle' => 'Gerer les debourses associes aux devis et chantiers',
                'required_permissions_json' => json_encode(['modifier_debourse', 'acces_decaissement', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => 40,
                'is_active' => 1,
            ],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );

        foreach ($modules as $module) {
            $stmt->execute($module);
        }
    }

    private function migrateAiAssistantModule(): void
    {
        $module = [
            'module_key' => 'module_ai_assistant',
            'label' => 'Assistant IA FINEA',
            'icon' => 'fa-robot',
            'color' => '#4f46e5',
            'target' => 'ai_assistant/index.php',
            'slug' => 'ai_assistant/index.php',
            'subtitle' => 'Assistant redactionnel interne avec amelioration IA externe optionnelle',
            'required_permissions_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 146,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );

        $stmt->execute($module);
    }

    private function migrateDivertissementsModule(): void
    {
        $module = [
            'module_key' => 'module_divertissements',
            'label' => 'FINEA Life',
            'icon' => 'fa-users',
            'color' => '#4F46E5',
            'target' => 'divertissements/divertissements.php',
            'slug' => 'divertissements',
            'subtitle' => 'Vie d’entreprise, événements, reconnaissance et cohésion',
            'required_permissions_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 210,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );

        $stmt->execute($module);
    }

    private function migrateRemoteAssistanceModule(): void
    {
        $module = [
            'module_key' => 'module_assistance_distance',
            'label' => 'Assistance à distance',
            'icon' => 'fa-headset',
            'color' => '#0ea5e9',
            'target' => 'assistance_distance.php',
            'slug' => 'assistance_distance.php',
            'subtitle' => 'Visio Jitsi intégrée à Finéa avec guide de déploiement vers un domaine privé',
            'required_permissions_json' => json_encode(['acces_assistance_distance', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 135,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );
        $stmt->execute($module);
    }

    private function migrateAddressBookModule(): void
    {
        $module = [
            'module_key' => 'module_carnet_adresse',
            'label' => 'Carnet d\'adresse',
            'icon' => 'fa-address-book',
            'color' => '#0284c7',
            'target' => 'carnet_adresse.php',
            'slug' => 'carnet_adresse.php',
            'subtitle' => 'Répertoire premium des contacts internes, fournisseurs et prestataires avec actions directes',
            'required_permissions_json' => json_encode(['acces_carnet_adresse', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 122,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );
        $stmt->execute($module);
    }

    private function migrateSupplierModule(): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . self::TABLE . '
            SET target = :target,
                slug = :slug,
                subtitle = :subtitle,
                required_permissions_json = :required_permissions_json
            WHERE module_key = :module_key');
        $stmt->execute([
            'target' => 'fournisseur/fournisseurs.php',
            'slug' => 'fournisseurs.php',
            'subtitle' => 'Créer vos fournisseurs, suivre leur validation et consigner les incidents',
            'required_permissions_json' => json_encode(['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'module_key' => 'module_fournisseur',
        ]);
    }

    private function migrateFormationModule(): void
    {
        $module = [
            'module_key' => 'module_formation',
            'label' => 'Formation',
            'icon' => 'fa-graduation-cap',
            'color' => '#1d4ed8',
            'target' => 'formation/index.php',
            'slug' => 'formation',
            'subtitle' => 'Guides utilisateurs et documentation technique de chaque module de l application',
            'required_permissions_json' => json_encode(['acces_formation', 'formation_admin', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => 145,
            'is_active' => 1,
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
             VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                icon = VALUES(icon),
                color = VALUES(color),
                target = VALUES(target),
                slug = VALUES(slug),
                subtitle = VALUES(subtitle),
                required_permissions_json = VALUES(required_permissions_json),
                sort_order = VALUES(sort_order),
                is_active = VALUES(is_active)'
        );
        $stmt->execute($module);
    }

    public static function defaultModules(): array
    {
        $module_list = [
            [
                'module_key' => 'module_direction',
                'label' => 'Dashboard Direction Générale',
                'icon' => 'fa-sitemap',
                'color' => '#1e40af',
                'target' => 'dashboard_dg.php',
                'slug' => 'dashboard_dg',
                'required_permissions' => ['acces_direction', 'acces_admin'],
                'subtitle' => 'Vue globale des activités, indicateurs clés et suivi stratégique',
                'sort_order' => 10,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_devis',
                'label' => 'Centre devis et chantiers',
                'icon' => 'fa-drafting-compass',
                'color' => '#2563eb',
                'target' => 'devis/index.php',
                'slug' => 'devis',
                'required_permissions' => ['acces_dashboard', 'acces_devis', 'acces_generer_devis', 'acces_liste_chantier', 'acces_liste_client', 'acces_liste_offre', 'acces_liste_unite', 'acces_veille_ao'],
                'subtitle' => 'Gérer les devis, chantiers et clients pour un suivi commercial efficace',
                'sort_order' => 20,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_decaissement',
                'label' => 'Approbation Fiches de Décaissement',
                'icon' => 'fa-coins',
                'color' => '#7e22ce',
                'target' => 'decaissement/liste_fiches_approbation.php',
                'slug' => 'liste_fiches_approbation.php',
                'required_permissions' => ['acces_decaissement', 'acces_conformite', 'acces_approbation', 'acces_achat', 'acces_depenses_planifiees', 'acces_tresorerie'],
                'subtitle' => 'Gérer les fiches de décaissement et suivre les approbations',
                'sort_order' => 30,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_debours',
                'label' => 'Gestion Déboursé',
                'icon' => 'fa-wallet',
                'color' => '#f59e0b',
                'target' => 'debourse/dashboard.php',
                'slug' => 'debourse',
                'required_permissions' => ['modifier_debourse', 'acces_decaissement', 'acces_admin'],
                'subtitle' => 'Gérer les déboursés associés aux devis et chantiers',
                'sort_order' => 40,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_compta',
                'label' => 'Espace Comptabilité',
                'icon' => 'fa-file-invoice-dollar',
                'color' => '#059669',
                'target' => 'decaissement/liste_fiches_achat.php',
                'slug' => 'liste_fiches_achat.php',
                'required_permissions' => ['acces_achat'],
                'subtitle' => 'Gérer les fiches d\'achat et suivre les validations comptables',
                'sort_order' => 50,
                'is_active' => 0,
            ],
            [
                'module_key' => 'module_tresorerie',
                'label' => 'Trésorerie en temps réel',
                'icon' => 'fa-building-columns',
                'color' => '#0d9488',
                'target' => 'facturation/tresorerie_chantiers.php',
                'slug' => 'tresorerie_chantiers.php',
                'required_permissions' => ['acces_facturation', 'acces_direction', 'acces_admin'],
                'subtitle' => 'Suivre les entrées, sorties, prévisions et le solde réellement disponible',
                'sort_order' => 58,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_gestion_comptable',
                'label' => 'Gestion comptable',
                'icon' => 'fa-book',
                'color' => '#4338ca',
                'target' => 'comptabilite/index.php',
                'slug' => 'comptabilite',
                'subtitle' => 'Écritures avec justificatifs, journaux, grand livre et balance SYSCOHADA',
                'required_permissions' => ['acces_comptabilite', 'acces_admin'],
                'sort_order' => 60,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_traitement_comptable',
                'label' => 'Traitement des paiements',
                'icon' => 'fa-cash-register',
                'color' => '#059669',
                'target' => 'decaissement/liste_fiches_compta.php',
                'slug' => 'liste_fiches_compta.php',
                'required_permissions' => ['acces_tresorerie'],
                'subtitle' => 'Traiter les paiements des fiches de décaissement validées',
                'sort_order' => 59,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_budget',
                'label' => 'Suivi Budgétaire Chantier',
                'icon' => 'fa-chart-line',
                'color' => '#c026d3',
                'target' => 'decaissement/selection_budgets_chantiers.php',
                'slug' => 'suivi_budgetaire',
                'required_permissions' => ['acces_budgets_chantiers', 'acces_direction', 'acces_admin'],
                'subtitle' => 'Visualiser les budgets et dépenses par chantier',
                'sort_order' => 70,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_planning_chantier',
                'label' => 'Planning Chantier',
                'icon' => 'fa-calendar-check',
                'color' => '#0f766e',
                'target' => 'planning_chantier/dashboard.php',
                'slug' => 'planning_chantier',
                'required_permissions' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
                'subtitle' => 'Voir l avancement global de tous les chantiers et ouvrir le suivi detaille',
                'sort_order' => 80,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_rh',
                'label' => 'Gestion des Ressources Humaines',
                'icon' => 'fa-user-group',
                'color' => '#db2777',
                'target' => 'rh/dashboard_rh.php',
                'slug' => 'dashboard_rh.php',
                'required_permissions' => ['acces_rh', 'acces_admin'],
                'subtitle' => 'Centraliser les équipes, dossiers du personnel et suivi RH',
                'sort_order' => 90,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_fournisseur',
                'label' => 'Gestion des fournisseurs',
                'icon' => 'fa-industry',
                'color' => '#0d9488',
                'target' => 'fournisseur/fournisseurs.php',
                'slug' => 'fournisseurs.php',
                'required_permissions' => ['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'],
                'subtitle' => 'Créer vos fournisseurs, suivre leur validation et consigner les incidents',
                'sort_order' => 100,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_facturation',
                'label' => 'Facturation',
                'icon' => 'fa-receipt',
                'color' => '#7c3aed',
                'target' => 'facturation/dashboard_facturation.php',
                'slug' => 'dashboard_facturation.php',
                'required_permissions' => ['acces_facturation', 'acces_admin'],
                'subtitle' => 'Préparer et suivre les factures avec exigences FNE',
                'sort_order' => 110,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_emails',
                'label' => 'Emails / Mailing',
                'icon' => 'fa-envelope',
                'color' => '#2563eb',
                'target' => 'emails/mailbox_emails.php',
                'slug' => 'mailbox_emails.php',
                'required_permissions' => ['acces_mail', 'acces_admin'],
                'subtitle' => 'Consulter votre boîte email professionnelle depuis l\'ERP',
                'sort_order' => 120,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_carnet_adresse',
                'label' => 'Carnet d\'adresse',
                'icon' => 'fa-address-book',
                'color' => '#0284c7',
                'target' => 'carnet_adresse.php',
                'slug' => 'carnet_adresse.php',
                'required_permissions' => ['acces_carnet_adresse', 'acces_admin'],
                'subtitle' => 'Répertoire premium des contacts internes, fournisseurs et prestataires avec actions directes',
                'sort_order' => 122,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_assistance_distance',
                'label' => 'Assistance à distance',
                'icon' => 'fa-headset',
                'color' => '#0ea5e9',
                'target' => 'assistance_distance.php',
                'slug' => 'assistance_distance.php',
                'required_permissions' => ['acces_assistance_distance', 'acces_admin'],
                'subtitle' => 'Visio Jitsi intégrée à Finéa avec guide de déploiement vers un domaine privé',
                'sort_order' => 135,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_documentation',
                'label' => 'Documentation & Archives',
                'icon' => 'fa-folder-tree',
                'color' => '#0f766e',
                'target' => 'documentation/index.php',
                'slug' => 'documentation',
                'required_permissions' => ['acces_documentation', 'document_admin', 'acces_admin'],
                'subtitle' => 'Centre documentaire centralisé par chantier, service et direction',
                'sort_order' => 140,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_formation',
                'label' => 'Formation',
                'icon' => 'fa-graduation-cap',
                'color' => '#1d4ed8',
                'target' => 'formation/index.php',
                'slug' => 'formation',
                'required_permissions' => ['acces_formation', 'formation_admin', 'acces_admin'],
                'subtitle' => 'Guides utilisateurs et documentation technique de chaque module de l application',
                'sort_order' => 145,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_procedures',
                'label' => 'Procédures',
                'icon' => 'fa-diagram-project',
                'color' => '#4169e1',
                'target' => 'procedures/index.php',
                'slug' => 'procedures',
                'required_permissions' => [],
                'subtitle' => 'Créer, organiser et exporter des procédures sous forme de schémas simples',
                'sort_order' => 142,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_ai_assistant',
                'label' => 'Assistant IA FINEA',
                'icon' => 'fa-robot',
                'color' => '#4f46e5',
                'target' => 'ai_assistant/index.php',
                'slug' => 'ai_assistant/index.php',
                'required_permissions' => [],
                'subtitle' => 'Assistant redactionnel interne avec amelioration IA externe optionnelle',
                'sort_order' => 146,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_crm',
                'label' => 'CRM (Direction Commerciale)',
                'icon' => 'fa-handshake',
                'color' => '#16a34a',
                'target' => 'crm/dashboard_crm.php',
                'slug' => 'dashboard_crm.php',
                'required_permissions' => ['acces_crm', 'acces_direction', 'acces_admin'],
                'subtitle' => 'Suivi prospects, opportunités et relances commerciales',
                'sort_order' => 150,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_emploi_du_temps',
                'label' => 'Emploi du temps',
                'icon' => 'fa-calendar-days',
                'color' => '#0891b2',
                'target' => 'emploi_du_temps/index.php',
                'slug' => 'emploi_du_temps',
                'required_permissions' => ['acces_emploi_du_temps', 'acces_admin'],
                'subtitle' => 'Votre feuille de temps : tâches, créneaux, déplacement et export',
                'sort_order' => 160,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_employe',
                'label' => 'Espace Employé',
                'icon' => 'fa-user',
                'color' => '#4f46e5',
                'target' => 'employe/dashboard_employe.php',
                'slug' => 'dashboard_employe.php',
                'required_permissions' => ['acces_employe', 'acces_admin'],
                'subtitle' => 'Pointage personnel, alertes et demandes administratives (actes légaux)',
                'sort_order' => 170,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_besoins_recurrents',
                'label' => 'Besoins récurrents',
                'icon' => 'fa-arrows-rotate',
                'color' => '#1d2b57',
                'target' => 'recurring_needs/index.php',
                'slug' => 'recurring_needs',
                'required_permissions' => ['acces_besoins_recurrents', 'acces_employe', 'acces_admin'],
                'subtitle' => 'Programmer les FEB répétitives avec rappels et génération automatique',
                'sort_order' => 175,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_validation_feb',
                'label' => 'Validation FEB (Finances)',
                'icon' => 'fa-clipboard-check',
                'color' => '#059669',
                'target' => 'validation_demandes_finances.php',
                'slug' => 'validation_demandes_finances.php',
                'required_permissions' => ['acces_feb'],
                'subtitle' => 'Traiter les FEB soumises par les employés',
                'sort_order' => 180,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_logistique',
                'label' => 'Gestion Logistique',
                'icon' => 'fa-truck-fast',
                'color' => '#ea580c',
                'target' => 'logi/dashboard_logistique.php',
                'slug' => 'dashboard_logistique.php',
                'required_permissions' => ['acces_logistique', 'acces_admin'],
                'subtitle' => 'Piloter les moyens logistiques, équipements et opérations terrain',
                'sort_order' => 190,
                'is_active' => 1,
            ],
            [
                'module_key' => 'module_admin',
                'label' => 'Administration Système',
                'icon' => 'fa-user-shield',
                'color' => '#dc2626',
                'target' => 'admin/dashboard_admin.php',
                'slug' => 'dashboard_admin.php',
                'required_permissions' => ['acces_admin'],
                'subtitle' => 'Gestion des utilisateurs, rôles et permissions',
                'sort_order' => 200,
                'is_active' => 1,
            ]
        ];

        return $module_list;
    }

    public function listActive(): array
    {
        return $this->listByStatus(true);
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active, created_at, updated_at
             FROM ' . self::TABLE . '
             ORDER BY sort_order ASC, label ASC'
        );

        return array_map([$this, 'hydrateModuleRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active, created_at, updated_at
             FROM ' . self::TABLE . '
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateModuleRow($row) : null;
    }

    public function save(array $data): int
    {
        $id = (int)($data['id'] ?? 0);
        $moduleKey = $this->normalizeModuleKey((string)($data['module_key'] ?? ''));
        $label = trim($this->normalizeText((string)($data['label'] ?? '')));
        $icon = trim($this->normalizeText((string)($data['icon'] ?? 'fa-cube')));
        $color = strtoupper(trim((string)($data['color'] ?? '#2563EB')));
        $target = trim($this->normalizeText((string)($data['target'] ?? '')));
        $slug = trim($this->normalizeText((string)($data['slug'] ?? '')));
        $subtitle = trim($this->normalizeText((string)($data['subtitle'] ?? '')));
        $sortOrder = (int)($data['sort_order'] ?? 0);
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $requiredPermissions = $this->normalizePermissions($data['required_permissions'] ?? []);

        if ($label === '') {
            throw new InvalidArgumentException('Le libellé du module est obligatoire.');
        }
        if ($target === '') {
            throw new InvalidArgumentException('La cible du module est obligatoire.');
        }
        if ($moduleKey === '') {
            $moduleKey = $this->normalizeModuleKey($label);
        }
        if ($moduleKey === '') {
            throw new InvalidArgumentException('L\'identifiant interne du module est invalide.');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new InvalidArgumentException('La couleur doit être au format hexadécimal, par exemple #2563EB.');
        }
        if ($sortOrder <= 0) {
            $sortOrder = $this->getNextSortOrder();
        }

        $payload = [
            'module_key' => $moduleKey,
            'label' => $label,
            'icon' => $icon !== '' ? $icon : 'fa-cube',
            'color' => $color,
            'target' => $target,
            'slug' => $slug,
            'subtitle' => $subtitle,
            'required_permissions_json' => json_encode($requiredPermissions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => $sortOrder,
            'is_active' => $isActive,
        ];

        try {
            if ($id > 0) {
                $payload['id'] = $id;
                $stmt = $this->pdo->prepare(
                    'UPDATE ' . self::TABLE . '
                     SET module_key = :module_key,
                         label = :label,
                         icon = :icon,
                         color = :color,
                         target = :target,
                         slug = :slug,
                         subtitle = :subtitle,
                         required_permissions_json = :required_permissions_json,
                         sort_order = :sort_order,
                         is_active = :is_active,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id'
                );
                $stmt->execute($payload);
                return $id;
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (
                    module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active
                 ) VALUES (
                    :module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active
                 )'
            );
            $stmt->execute($payload);
            return (int)$this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new RuntimeException('Un module avec le même identifiant interne existe déjà.');
            }
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function getPermissionOptions(): array
    {
        $columns = $this->pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        $allowedPrefixes = ['acces_', 'ao_', 'modifier_', 'visualiser_', 'soumettre_', 'valider_', 'masquer_', 'envoyer_', 'document_'];
        $permissions = [];

        foreach ($columns as $column) {
            foreach ($allowedPrefixes as $prefix) {
                if (strpos((string)$column, $prefix) === 0) {
                    $permissions[(string)$column] = $this->humanizePermissionKey((string)$column);
                    break;
                }
            }
        }

        asort($permissions, SORT_NATURAL | SORT_FLAG_CASE);
        return $permissions;
    }

    public function getNextSortOrder(): int
    {
        $next = (int)$this->pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM ' . self::TABLE)->fetchColumn();
        return $next > 0 ? $next : 10;
    }

    private function listByStatus(bool $activeOnly): array
    {
        if ($activeOnly) {
            $stmt = $this->pdo->query(
                'SELECT id, module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active, created_at, updated_at
                 FROM ' . self::TABLE . '
                 WHERE is_active = 1
                 ORDER BY sort_order ASC, label ASC'
            );
        } else {
            $stmt = $this->pdo->query(
                'SELECT id, module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active, created_at, updated_at
                 FROM ' . self::TABLE . '
                 ORDER BY sort_order ASC, label ASC'
            );
        }

        return array_map([$this, 'hydrateModuleRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function hydrateModuleRow(array $row): array
    {
        foreach (['module_key', 'label', 'icon', 'color', 'target', 'slug', 'subtitle'] as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null) {
                $row[$field] = $this->normalizeText((string)$row[$field]);
            }
        }

        $permissions = json_decode((string)($row['required_permissions_json'] ?? '[]'), true);
        if (!is_array($permissions)) {
            $permissions = [];
        }

        $row['required_permissions'] = $this->normalizePermissions($permissions);
        unset($row['required_permissions_json']);

        return $row;
    }

    private function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                module_key VARCHAR(120) NOT NULL,
                label VARCHAR(190) NOT NULL,
                icon VARCHAR(120) NOT NULL DEFAULT "fa-cube",
                color CHAR(7) NOT NULL DEFAULT "#2563EB",
                target VARCHAR(255) NOT NULL,
                slug VARCHAR(120) DEFAULT NULL,
                subtitle VARCHAR(255) DEFAULT NULL,
                required_permissions_json LONGTEXT NULL,
                sort_order INT NOT NULL DEFAULT 10,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_portal_modules_module_key (module_key),
                KEY idx_portal_modules_active_order (is_active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $done = true;
    }

    private function seedDefaultsIfEmpty(): void
    {
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)->fetchColumn();
        if ($count > 0) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::TABLE . ' (
                module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active
             ) VALUES (
                :module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active
             )'
        );

        foreach (self::defaultModules() as $module) {
            $stmt->execute([
                'module_key' => $module['module_key'],
                'label' => $module['label'],
                'icon' => $module['icon'],
                'color' => $module['color'],
                'target' => $module['target'],
                'slug' => $module['slug'],
                'subtitle' => $module['subtitle'],
                'required_permissions_json' => json_encode($module['required_permissions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort_order' => (int)$module['sort_order'],
                'is_active' => (int)$module['is_active'],
            ]);
        }
    }

    private function normalizePermissions($permissions): array
    {
        if (!is_array($permissions)) {
            return [];
        }

        $clean = [];
        foreach ($permissions as $permission) {
            $permission = trim((string)$permission);
            if ($permission !== '') {
                $clean[$permission] = $permission;
            }
        }

        return array_values($clean);
    }

    private function normalizeText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('//u', $value)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
            if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
                return $converted;
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
            if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
            if (is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
                return $converted;
            }
        }

        return $value;
    }

    private function normalizeModuleKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        return $value;
    }

    private function humanizePermissionKey(string $key): string
    {
        $label = preg_replace('/^(acces_|modifier_|visualiser_|soumettre_|valider_|masquer_|envoyer_|document_)/', '', $key) ?? $key;
        $label = str_replace('_', ' ', $label);
        $label = trim($label);
        if ($label === '') {
            return $key;
        }

        return ucfirst($label);
    }
}
