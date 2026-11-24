<?php

require_once __DIR__ . '/AuditTrail.php';

/**
 * Classe de gestion des accès granulaires
 * Gère les permissions d'accès aux différentes pages et modules
 */
class AccessManager
{
    private const DEFAULT_UNIVERSAL_PERMISSIONS = [
        'acces_ticketing',
    ];

    /**
     * Mapping des pages vers les permissions requises
     */
    const PAGE_PERMISSIONS = [
        'comptabilite/index.php' => ['acces_comptabilite', 'acces_admin'],
        'comptabilite/source_document.php' => ['acces_comptabilite', 'acces_admin'],
        'comptabilite/justificatif.php' => ['acces_comptabilite', 'acces_admin'],
        // Module Devis
        'liste_devis.php' => 'acces_devis',
        'generer_devis.php' => 'acces_generer_devis',
        'modifier_devis.php' => 'acces_devis',
        'visualiser_section.php' => 'acces_devis',
        'editer_section.php' => 'acces_devis',
        // La gestion des zones fait le lien entre les devis et le décaissement.
        // Tous les utilisateurs de l'un de ces deux modules doivent pouvoir
        // ouvrir la liste, le Kanban et enregistrer les affectations.
        'gestion_zones.php' => ['acces_devis', 'acces_decaissement'],
        'affecter_zones_kanban.php' => ['acces_devis', 'acces_decaissement'],
        'update_zones.php' => ['acces_devis', 'acces_decaissement'],
        'request/classer_devis_offre.php' => ['modifier_classement_devis', 'acces_admin'],
        'devis/transfert_financier.php' => ['transferer_historique_devis', 'acces_admin'],
        'admin/feb_price_challenge_control.php' => ['suivi_devis_contradictoires', 'acces_admin'],
        'admin/export_feb_price_challenges.php' => ['suivi_devis_contradictoires', 'acces_admin'],

        // Dashboard et tableaux de bord
        'dashboard.php' => 'acces_dashboard',
        'dashboard_dg.php' => ['acces_direction', 'acces_admin'],
        'dashboard_planing.php' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/dashboard.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/index.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/chantier.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/reunion.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/tasks.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/blockages.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
        'planning_chantier/task_form.php' => ['acces_planning_chantier', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],


        // Centre de services / Ticketing
        'ticketing/index.php' => ['acces_ticketing', 'acces_admin'],
        'website/index.php' => ['acces_admin'],
        'parc_informatique/index.php' => ['acces_parc_informatique', 'acces_admin'],
        'parc_informatique/export.php' => ['acces_parc_informatique', 'acces_admin'],
        'parc_informatique/labels.php' => ['acces_parc_informatique', 'acces_admin'],
        'parc_informatique/import_template.php' => ['acces_parc_informatique', 'acces_admin'],
        'veille_ao.php' => ['acces_veille_ao', 'ao_view', 'ao_admin', 'acces_admin'],
        'veille_ao_detail.php' => ['acces_veille_ao', 'ao_view', 'ao_admin', 'acces_admin'],
        'veille_ao_preferences.php' => ['ao_manage_alerts', 'ao_admin', 'acces_admin'],
        'veille_ao_sync.php' => ['ao_admin', 'acces_admin'],
        'veille_ao_diffusion.php' => ['ao_manage_diffusion', 'ao_admin', 'acces_admin'],
        'export_veille_ao.php' => ['ao_export', 'ao_admin', 'acces_admin'],

        // Emploi du temps
        'emploi_du_temps.php' => 'acces_emploi_du_temps',
        'dashboard_statistiques_taches.php' => 'acces_dashboard_stat_taches',
        'update_employee_schedule_event.php' => 'acces_emploi_du_temps',
        'validate_employee_schedule_event_completion.php' => ['acces_emploi_du_temps', 'acces_employe'],

        // FNE (API interne facturation)
        'fne_sign_invoice.php' => 'acces_facturation',
        'fne_refund_invoice.php' => 'acces_facturation',
        'fne_verify_invoice.php' => ['acces_facturation', 'acces_compta', 'acces_achat', 'acces_admin'],
        'fne_verify_decaissement.php' => ['acces_compta', 'acces_facturation', 'acces_admin'],

        // FEB: contrôle justificatifs (OCR local)
        'check_image_justificatif_feb.php' => 'acces_employe',
        'check_feb_document_fidest_ia.php' => 'acces_employe',
        'feb_human_document_review.php' => 'acces_employe',
        'feb_human_review_file.php' => 'acces_employe',
        'export_feb_human_review_quote_pdf.php' => 'acces_employe',

        // ================== Espace Employé (self-service) ==================
        'dashboard_employe.php' => 'acces_employe',
        'pointage_employe.php' => 'acces_employe',
        'actes_legaux_employe.php' => 'acces_employe',
        'nouvelle_demande_employe.php' => 'acces_employe',
        'nouvelle_demande_decaissement_urgent.php' => 'acces_employe',
        'mes_demandes_employe.php' => 'acces_employe',
        'mes_explications_employe.php' => 'acces_employe',
        'mes_decaissements_employe.php' => 'acces_employe',
        'details_decaissement_employe.php' => 'acces_employe',
        'mes_fournisseurs_employe.php' => 'acces_employe',
        'edit_fournisseur_employe.php' => 'acces_employe',

        // Workflows de validation
        'suivi_templates_whatsapp_rh.php' => ['acces_admin'],
        'test_whatsapp_point.php' => ['acces_admin'],
        'whatsapp_templates.php' => ['acces_admin'],
        'points_financiers_rh.php' => ['acces_rh','acces_direction','acces_admin'],
        'historique_points_financiers_rh.php' => ['acces_rh','acces_direction','acces_admin'],
        'validation_point_financier.php' => ['acces_direction','acces_admin'],
        'export_point_financier_pdf.php' => ['acces_rh','acces_direction','acces_admin','acces_feb','acces_comptabilite'],
        'validation_demandes_rh.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'validation_demandes_finances.php' => 'acces_feb',
        'validation_feb_detail.php' => 'acces_feb',
        'validation_justificatifs_feb.php' => 'acces_employe',

        // Contrats de sous-traitance
        'contrats/index.php' => ['acces_contrats', 'acces_admin'],

        // Exports RH (Demandes employé)
        'export_demandes_rh_pdf.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_demandes_rh_excel.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_demande_rh_fiche_pdf.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_mission_orders_rh_excel.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_mission_orders_rh_pdf.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_mission_order_rh_pdf.php' => ['acces_rh', 'acces_direction', 'acces_admin'],

        // Cron (web) — synchro FEB legacy (réservé finances/admin)
        'sync_legacy_feb_to_modern.php' => 'acces_feb',

        // Exports FEB (Excel devis + PDF)
        'export_feb_devis_excel.php' => ['acces_employe', 'acces_feb', 'acces_admin'],
        'export_feb_pdf.php' => ['acces_employe', 'acces_feb', 'acces_admin'],
        'feb_quote_template_csv.php' => ['acces_employe', 'acces_feb', 'acces_admin'],
        'import_feb_quote_csv.php' => ['acces_employe', 'acces_feb', 'acces_admin'],
        'export_feb_submission_pdf.php' => ['acces_employe', 'acces_feb', 'acces_admin'],

        // Exports sélection arbitraire (Mes demandes)
        'export_employee_requests_excel.php' => ['acces_employe', 'acces_admin'],
        'export_employee_requests_pdf.php' => ['acces_employe', 'acces_admin'],
        'export_rh_explanation_pdf.php' => ['acces_employe', 'acces_rh'],

        // Exports Décaissement (Espace employé)
        'export_fiche_decaissement_pdf_employe.php' => 'acces_employe',
        'export_mes_decaissements_employe_pdf.php' => 'acces_employe',
        'export_mes_decaissements_employe_excel.php' => 'acces_employe',

        // Exports Pointage (Espace employé)
        'export_pointage_employe_pdf.php' => 'acces_employe',
        'export_pointage_employe_excel.php' => 'acces_employe',
        'export_pointage_employe_annual_pdf.php' => 'acces_employe',
        'export_pointage_employe_annual_excel.php' => 'acces_employe',
        'download_attendance_statement.php' => ['acces_employe', 'acces_rh', 'acces_direction', 'acces_admin'],
        'regularisation_decaissement.php' => ['acces_employe', 'acces_compta', 'acces_tresorerie', 'acces_direction', 'acces_admin'],
        'regularisations_decaissements.php' => ['acces_employe', 'acces_compta', 'acces_tresorerie', 'acces_direction', 'acces_admin'],
        'export_disbursement_reception_pv.php' => ['acces_employe', 'acces_compta', 'acces_tresorerie', 'acces_direction', 'acces_admin'],
        'view_disbursement_payment_proof.php' => ['acces_employe', 'acces_compta', 'acces_tresorerie', 'acces_direction', 'acces_admin'],

        // Chantiers
        'liste_chantier.php' => 'acces_liste_chantier',
        'details_chantier.php' => ['acces_detail_chantier', 'acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'choisir_devis_pour_chantier.php' => 'acces_liste_chantier',
        'debuter_chantier.php' => 'acces_liste_chantier',

        // Clients
        'liste_client.php' => 'acces_liste_client',
        'assistance_distance.php' => ['acces_assistance_distance', 'acces_admin'],
        'carnet_adresse.php' => ['acces_carnet_adresse', 'acces_admin'],
        'fiche_contact.php' => ['acces_carnet_adresse', 'acces_admin'],

        // Factures
        'liste_facture.php' => 'acces_liste_facture',

        // Offres
        'liste_offre.php' => 'acces_liste_offre',

        // Unites
        'liste_unite.php' => 'acces_liste_unite',

        // Décaissement (permission existante)
        'decaissement' => 'acces_decaissement',

        // Gestion utilisateurs et accès
        'liste_utilisateur.php' => 'acces_gestion_acces',
        'ajouter_utilisateur.php' => 'acces_gestion_acces',
        'modifier_utilisateur.php' => 'acces_gestion_acces',
        'gestion_acces.php' => 'acces_gestion_acces',
        'desactiver_utilisateur.php' => 'acces_gestion_acces',
        'reactiver_utilisateur.php' => 'acces_gestion_acces',
        'supprimer_utilisateur.php' => 'acces_gestion_acces',

        // RH & logistique
        'dashboard_rh.php' => 'acces_rh',
        'liste_personnel_rh.php' => 'acces_rh',
        'dossier_personnel_rh.php' => 'acces_rh',
        'modifier_dossier_personnel_rh.php' => 'acces_rh',
        'mutation_personnel_rh.php' => 'acces_rh',
        'sortie_personnel_rh.php' => 'acces_rh',
        'parametrage_motifs_sortie_rh.php' => 'acces_rh',
        'ordres_mission_rh.php' => 'acces_rh',
        'ordre_mission_rh.php' => 'acces_rh',
        'mes_signatures_mission.php' => ['acces_employe','acces_rh'],
        'pointage_journalier_rh.php' => 'acces_rh',
        'paie_rh.php' => 'acces_rh',
        'bulletin_paie_rh.php' => 'acces_rh',
        'validation_paie_rh.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'export_bulletins_paie_pdf.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'parametrage_paie_rh.php' => 'acces_rh',
        'parametrage_contrat_rh.php' => 'acces_rh',
        'parametrage_hierarchie_rh.php' => 'acces_rh',
        'organigramme_rh.php' => 'acces_rh',
        'export_organigramme_pdf.php' => 'acces_rh',
        'save_organigramme_layout.php' => 'acces_rh',
        'parametrage_horaires_emploi_du_temps.php' => 'acces_rh',
        'export_emploi_du_temps_hebdomadaire.php' => ['acces_rh', 'acces_direction', 'acces_admin'],
        'demandes_explication_rh.php' => 'acces_rh',
        'export_contrat_rh.php' => 'acces_rh',
        'dashboard_logistique.php' => 'acces_logistique',
        'trip_batches.php' => 'acces_logistique',
        'trip_batch_form.php' => 'acces_logistique',
        'trip_rotations.php' => 'acces_logistique',
        'trip_form.php' => 'acces_logistique',
        'fuel.php' => 'acces_logistique',
        'bon_essence.php' => ['acces_logistique', 'acces_decaissement', 'acces_admin'],
        'inventaire_materiel.php' => 'acces_logistique',
        'enregistrer_stock.php' => 'acces_logistique',

        // Fournisseurs & facturation
        'dashboard_fournisseur.php' => ['acces_fournisseur', 'acces_compta', 'acces_admin'],
        'fournisseurs.php' => ['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'],
        'regularisations_fournisseurs.php' => ['acces_fournisseur', 'acces_compta', 'acces_tresorerie', 'regulariser_fournisseur_sans_preuve', 'acces_admin'],
        'categories.php' => ['acces_fournisseur', 'acces_compta', 'acces_admin'],
        'edit_fournisseur.php' => ['acces_fournisseur', 'acces_compta', 'acces_admin', 'acces_employe'],
        'dashboard_facturation.php' => 'acces_facturation',
        'parametres_facturation.php' => 'acces_facturation',
        'recherche_factures.php' => 'acces_facturation',
        'factures_achat.php' => 'acces_facturation',
        'import_factures_achat_fne.php' => 'acces_facturation',
        'fne_purchase_import_search.php' => 'acces_facturation',
        'fne_party_link.php' => 'acces_facturation',
        'edit_facture_achat.php' => 'acces_facturation',
        'download_facture_achat.php' => 'acces_facturation',
        'factures_vente.php' => 'acces_facturation',
        'import_factures_vente_fne.php' => 'acces_facturation',
        'fne_sales_import_search.php' => 'acces_facturation',
        'tresorerie_chantiers.php' => ['acces_tresorerie_lecture', 'acces_direction', 'acces_admin'],
        'edit_facture_vente.php' => 'acces_facturation',

        // ================== Sous‑module Décaissement granulaires ==================
        // Navigation principale (navbar décaissement)
        'decaissement/dashboard.php' => 'acces_decaissement', // tableau de bord module (dashboard racine déjà défini plus haut)
        'details_chantiers.php' => 'acces_dec_chantiers',
        'export_chantiers.php' => 'acces_dec_chantiers',
        'selection_budgets_chantiers.php' => 'acces_budgets_chantiers',
        'detail_travaux_chantier.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'liste_fiches_conformite.php' => 'acces_conformite',
        'liste_fiches_approbation.php' => 'acces_approbation',
        'liste_fiches_achat.php' => 'acces_achat',
        'point_decaissement_jour.php' => ['acces_achat','acces_signataire_point_jour','acces_arreter_point_jour','acces_decaisser_point_jour','acces_admin'],
        'historique_points_decaissement.php' => ['acces_achat','acces_signataire_point_jour','acces_arreter_point_jour','acces_decaisser_point_jour','acces_admin'],
        'paiement_points_decaissement.php' => ['acces_decaisser_point_jour','acces_admin'],
        'download_daily_point_payment_proof.php' => ['acces_achat','acces_tresorerie','acces_decaisser_point_jour','acces_admin'],
        'liste_decaissements_urgents.php' => ['acces_achat', 'acces_tresorerie', 'acces_compta', 'acces_admin'],
        'bon_commande.php' => ['acces_achat', 'acces_compta', 'acces_point_financier', 'acces_direction'],
        'export_bon_commande_pdf.php' => ['acces_achat', 'acces_compta', 'acces_point_financier', 'acces_direction'],
        'bon_reception.php' => ['acces_achat', 'acces_compta', 'acces_point_financier', 'acces_direction'],
        'download_bon_reception.php' => ['acces_achat', 'acces_compta', 'acces_point_financier', 'acces_direction'],
        'liste_fiches_compta.php' => 'acces_tresorerie',
        'decaisser_bulk.php' => 'acces_tresorerie',
        'decaisser_bulk_sse.php' => 'acces_tresorerie',
        'depenses_planifiees.php' => 'acces_depenses_planifiees',
        'depenses_planifiees_detail.php' => 'acces_depenses_planifiees',
        'fiches_depenses_planifiees_list.php' => 'acces_depenses_planifiees',
        'reporter_depenses_planifiees_bulk.php' => 'acces_depenses_planifiees',
        'export_depenses_planifiees_excel.php' => 'acces_depenses_planifiees',
        'export_depenses_planifiees_pdf.php' => 'acces_depenses_planifiees',
        'liste_fiches_decaissement.php' => 'acces_fiches_decaissement',
        'liste_debourse.php' => 'modifier_debourse',
        'gerer_debourse.php' => 'modifier_debourse',
        'voir_debourse.php' => 'modifier_debourse',
        'liste_fiches_otp.php' => 'acces_otp',
        'point_financier.php' => 'acces_point_financier',
        'relink_fiches_debourse.php' => 'acces_relink_fiches_debourse',
        'historique_fiches.php' => 'acces_historique_fiches',
        'project_planning_realtime.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'import_project_planning.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'export_project_reunion_sheet_pdf.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'export_dashboard_planing_excel.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],
        'export_dashboard_planing_pdf.php' => ['acces_planning', 'acces_budgets_chantiers', 'acces_dec_chantiers', 'acces_direction'],

        // Fiches / opérations
        'voir_fiche_details.php' => 'acces_fiche_details',
        // Ajustement depuis l'espace employé : le droit métier dédié suffit,
        // sans imposer l'accès complet au module Décaissement.
        'get_fiche_details.php' => [
            'ajuster_beneficiaire_fournisseur_fiche',
            'acces_fiche_details',
            'acces_decaissement',
            'acces_admin',
        ],
        'modifier_fiche_decaissement.php' => [
            'ajuster_beneficiaire_fournisseur_fiche',
            'acces_fiches_decaissement',
            'acces_decaissement',
            'acces_admin',
        ],
        'formulaire_demande_decaissement.php' => 'acces_creation_fiche_decaissement',
        'success_fiche.php' => 'acces_creation_fiche_decaissement',
        'fiche_pro.php' => 'acces_creation_fiche_decaissement',
        'recap_carburant.php' => 'acces_recap_carburant',
        'export_carburant_excel.php' => 'acces_recap_carburant',
        'export_carburant_pdf.php' => 'acces_recap_carburant',
        'valider_fiche_carburant.php' => 'acces_operations_carburant',
        'refuser_fiche_carburant.php' => 'acces_operations_carburant',

        // Debug / outils (protégés via même permission globale décaissement ou admin si souhaité)
        'debug_chantiers_simple.php' => 'acces_decaissement',
        'debug_direct_chantiers.php' => 'acces_decaissement',
        'debug_javascript.php' => 'acces_decaissement',
        'debug_relations.php' => 'acces_decaissement',
        'debug_sql_error.php' => 'acces_decaissement',
        'diagnostic_javascript.php' => 'acces_decaissement',
        'test_carburant.php' => 'acces_decaissement',
        'test_chantiers_complet.php' => 'acces_decaissement',
        'test_chantiers_securise.php' => 'acces_decaissement',
        'test_formulaire_debug.php' => 'acces_decaissement',
        'test_formulaire_integre.php' => 'acces_decaissement',
        'test_nouveau_systeme.php' => 'acces_decaissement',
        'test_simple.php' => 'acces_decaissement',
        'test_utilisateur_chantiers.php' => 'acces_decaissement',
        'test_workflow_final.php' => 'acces_decaissement',
        'test_workflow_script.js' => 'acces_decaissement',

        // Migration (ancien système -> nouveau)
        'migration_fiches_ancien_systeme.php' => 'acces_admin',
        'migrate_old_fiches.php' => 'acces_admin',

        // Profil (toujours accessible)
        'profil.php' => 'acces_profil',

        // ================= ADMINISTRATION =================
        'dashboard_admin.php' => 'acces_admin',
        'branding_settings.php' => 'acces_admin',
        'audit_erp.php' => 'acces_admin',
        'audit_acl.php' => 'acces_admin',
        'version_finea.php' => 'acces_admin',
        'fne_tools.php' => 'acces_admin',
        'liste_users.php' => 'acces_admin',
        'export_users_pdf.php' => 'acces_admin',
        'edit_user.php' => 'acces_admin',
        'portal_modules.php' => 'acces_admin',
        'search_engine_training.php' => 'acces_admin',
        'update_user_permissions.php' => 'acces_admin',
        'notifications_settings.php' => 'acces_admin',
        'notification_matrix.php' => 'acces_admin',
        'recurring_needs/cron_generate.php' => 'acces_admin',
        'access_matrix.php' => 'acces_admin',
        'export_access_matrix_excel.php' => 'acces_admin',
        'export_access_matrix_pdf.php' => 'acces_admin',
        'access_request_support_settings.php' => 'acces_admin',
        'maintenance_point_financier_indexes.php' => 'acces_admin',
        'db_tools.php' => 'acces_admin',
        'rh_explanation_deleted_history.php' => 'acces_admin',
        'logistics_fuel_tools.php' => 'acces_admin',
        'workflow_fiches_admin.php' => 'acces_admin',
        'workflow_fiches_admin_api.php' => 'acces_admin',
        'notification_email_tester.php' => 'acces_admin',
        'notification_email_tester_api.php' => 'acces_admin',
        'portal_badges_debug.php' => 'acces_admin',
        'portal_badges_settings.php' => 'acces_admin',
        'feb_ocr_settings.php' => 'acces_admin',
        'attendance_control_center.php' => 'acces_admin',
        'attendance_control_center_api.php' => 'acces_admin',
        'remaniement_matricules.php' => 'acces_admin',
        'ai_evolution.php' => 'acces_admin',
        'ai_insights_360.php' => 'acces_admin',

        // ================= EMAILS / MAILING =================
        'mailbox_emails.php' => 'acces_mail',
        'view_email.php' => 'acces_mail',
        'compose_email.php' => 'acces_mail',
        'api_mailbox_emails.php' => 'acces_mail',
        'api_mailbox_unread_count.php' => 'acces_mail',
        'api_mailbox_folders.php' => 'acces_mail',
        'api_mailbox_folder_create.php' => 'acces_mail',
        'api_mailbox_cache_manage.php' => 'acces_mail',
        'download_email_attachment.php' => 'acces_mail',
        'logout_mailbox.php' => 'acces_mail',

        // ================= CRM =================
        'dashboard_crm.php' => ['acces_crm_dashboard', 'acces_crm', 'acces_direction', 'acces_admin'],
        'prospects.php' => ['acces_crm_prospects', 'acces_crm', 'acces_direction', 'acces_admin'],
        'opportunites.php' => ['acces_crm_opportunites', 'acces_crm', 'acces_direction', 'acces_admin'],
        'clients.php' => ['acces_crm_clients', 'acces_crm', 'acces_direction', 'acces_admin'],
        'rdv.php' => ['acces_crm_rdv', 'acces_crm', 'acces_direction', 'acces_admin'],

        // ================= COMMUNICATION =================
        'dashboard_communication.php' => 'acces_communication',
        'mes_taches_communication.php' => 'acces_communication',
        'taches_communication.php' => 'acces_communication',
        'messagerie_communication.php' => 'acces_communication',
        'pieces_jointes_communication.php' => 'acces_communication',
        'communication.php' => 'acces_communication',
        'communication_state.php' => 'acces_communication',
        'communication_tasks.php' => 'acces_communication',
        'communication_attachments.php' => 'acces_communication',
        'communication_attachment_delete.php' => 'acces_communication',
        'communication_comment.php' => 'acces_communication',
        'communication_messages.php' => 'acces_communication',
        'communication_send.php' => 'acces_communication',
        'communication_typing.php' => 'acces_communication',
        'communication_typing_poll.php' => 'acces_communication',

        // ================= DOCUMENTATION =================
        'documentation.php' => 'acces_documentation',
        'documentation_download.php' => 'acces_documentation',

        // ================= FORMATION =================
        'formation.php' => 'acces_formation',
    ];

    /**
     * Types d'accès pour la page d'erreur
     */
    const ACCESS_TYPES = [
        'acces_devis' => 'devis',
        'acces_decaissement' => 'decaissement',
        'acces_dashboard' => 'dashboard',
        'acces_direction' => 'direction',
        'acces_planning' => 'planning',
        'acces_emploi_du_temps' => 'emploi_du_temps',
        'acces_dashboard_stat_taches' => 'emploi_du_temps',
        'acces_employe' => 'employe',
        'acces_detail_chantier' => 'chantier',
        'acces_liste_chantier' => 'chantier',
        'acces_liste_client' => 'client',
        'acces_liste_facture' => 'facture',
        'acces_liste_unite' => 'unite',
        'acces_generer_devis' => 'devis',
        'acces_veille_ao' => 'devis',
        'acces_gestion_acces' => 'gestion_acces',
        'acces_conformite' => 'decaissement',
        'acces_documentation' => 'documentation',
        'acces_formation' => 'formation',
        'acces_carnet_adresse' => 'carnet_adresse',
        'acces_assistance_distance' => 'assistance_distance',
        'acces_parc_informatique' => 'parc_informatique',

        // Décaissement granulaires
        'acces_dec_chantiers' => 'decaissement',
        'acces_budgets_chantiers' => 'decaissement',
        'acces_approbation' => 'decaissement',
        'acces_achat' => 'decaissement',
        'acces_feb' => 'decaissement',
        'acces_depenses_planifiees' => 'decaissement',
        'acces_tresorerie' => 'decaissement',
        'acces_decaisser_point_jour' => 'decaissement',
        'acces_compta' => 'decaissement',
        'acces_fiches_decaissement' => 'decaissement',
        'acces_otp' => 'decaissement',
        'acces_point_financier' => 'decaissement',
        'acces_relink_fiches_debourse' => 'decaissement',
        'acces_historique_fiches' => 'decaissement',
        'acces_fiche_details' => 'decaissement',
        'acces_recap_carburant' => 'decaissement',
        'acces_operations_carburant' => 'decaissement',
        'acces_creation_fiche_decaissement' => 'decaissement',

        'acces_rh' => 'rh',
        'acces_logistique' => 'logistique',
        'acces_hse' => 'hse',

        'acces_fournisseur' => 'fournisseur',
        'acces_facturation' => 'facturation',

        'acces_communication' => 'communication',

        'acces_mail' => 'mail',

        'acces_crm' => 'crm',
        'acces_crm_dashboard' => 'crm',
        'acces_crm_prospects' => 'crm',
        'acces_crm_clients' => 'crm',
        'acces_crm_opportunites' => 'crm',
        'acces_crm_rdv' => 'crm',
        'acces_crm_admin' => 'crm',

        'acces_admin' => 'admin',
    ];

    /**
     * Vérifie si l'utilisateur a accès à une page spécifique
     * 
     * @param string $page Nom de la page (avec ou sans .php)
     * @return bool
     */
    public static function hasPageAccess(string $page): bool
    {
        // Normaliser le nom de la page
        $page = basename($page);

        // Pages toujours accessibles après connexion
        $publicPages = [
            'selection_portail.php',
            'deconnex.php',
            'profil.php'
        ];

        if (in_array($page, $publicPages, true)) {
            return true;
        }

        $role = strtolower(trim((string)($_SESSION['role_norm'] ?? $_SESSION['role_raw'] ?? '')));
        if (in_array($role, ['directeur general', 'directeur général', 'dg'], true)
            && in_array($page, ['point_decaissement_jour.php', 'historique_points_decaissement.php', 'paiement_points_decaissement.php', 'download_daily_point_payment_proof.php'], true)) {
            return true;
        }

        $scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $pathPermission = self::findPermissionByScriptPath($scriptPath);
        if ($pathPermission !== null) {
            foreach (self::normalizePermissions($pathPermission) as $requiredPermission) {
                if (self::hasPermission($requiredPermission)) {
                    return true;
                }
            }
            return false;
        }

        if ($page === 'index.php' && strpos($scriptPath, '/formation/') !== false) {
            return self::hasPermission('acces_formation')
                || self::hasPermission('formation_admin')
                || self::hasPermission('acces_admin');
        }
        if ($page === 'index.php' && strpos($scriptPath, '/emploi_du_temps/') !== false) {
            return self::hasPermission('acces_emploi_du_temps');
        }
        if ($page === 'index.php' && strpos($scriptPath, '/documentation/') !== false) {
            return self::hasPermission('acces_documentation');
        }
        if (strpos($scriptPath, '/planning_chantier/') !== false) {
            foreach (['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'] as $permission) {
                if (self::hasPermission($permission)) {
                    return true;
                }
            }
            return false;
        }
        if (in_array($page, ['dashboard.php', 'parametrage.php'], true)) {
            $modulePermissions = [
                '/devis/' => ['acces_dashboard', 'acces_devis', 'acces_generer_devis'],
                '/clients/' => ['acces_liste_client'],
                '/offres/' => ['acces_liste_offre'],
                '/unites/' => ['acces_liste_unite'],
                '/debourse/' => ['modifier_debourse', 'acces_decaissement'],
                '/chantiers/' => ['acces_liste_chantier'],
                '/planning_chantier/' => ['acces_planning_chantier', 'acces_planning', 'acces_liste_chantier', 'acces_direction', 'acces_admin'],
            ];
            foreach ($modulePermissions as $segment => $permissions) {
                if (strpos($scriptPath, $segment) !== false) {
                    foreach ($permissions as $permission) {
                        if (self::hasPermission($permission)) {
                            return true;
                        }
                    }
                    return false;
                }
            }
        }

        if (in_array($page, ['validation_demandes_finances.php', 'validation_feb_detail.php', 'export_feb_recap_pdf.php', 'export_feb_recap_excel.php', 'sync_legacy_feb_to_modern.php'], true)) {
            return self::hasConfiguredFebValidationAccess();
        }

        // Vérifier si la page nécessite une permission spécifique
        if ($page === 'formulaire_demande_decaissement.php' && self::hasPermission('acces_employe')) {
            $urgentId = (int)($_GET['urgent_id'] ?? 0);
            $code = trim((string)($_GET['code_autorisation_feb'] ?? ''));
            if ($urgentId > 0 && $code !== '' && self::isUserUrgentRegularizationFicheTarget($urgentId, $code)) {
                return true;
            }
        }

        $relativePage = trim(preg_replace('#^.*?/planning_chantier/#', 'planning_chantier/', $scriptPath), '/');
        if ($relativePage !== '' && isset(self::PAGE_PERMISSIONS[$relativePage])) {
            foreach (self::normalizePermissions(self::PAGE_PERMISSIONS[$relativePage]) as $requiredPermission) {
                if (self::hasPermission($requiredPermission)) {
                    return true;
                }
            }
            return false;
        }

        if (isset(self::PAGE_PERMISSIONS[$page])) {
            foreach (self::normalizePermissions(self::PAGE_PERMISSIONS[$page]) as $requiredPermission) {
                if (self::hasPermission($requiredPermission)) {
                    return true;
                }
            }
            return false;
        }

        // Pour les pages du module décaissement, fallback sur acces_decaissement
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/decaissement/') !== false) {
            // Si une permission granulaire existe mais non listée (rare), on peut exiger acces_decaissement
            return self::hasPermission('acces_decaissement');
        }

        // Par défaut (pages non listées hors décaissement) on autorise pour compatibilité
        return true;
    }

    private static function isUserUrgentRegularizationFicheTarget(int $urgentId, string $code): bool
    {
        try {
            require_once __DIR__ . '/Database.php';
            $pdo = \Database::getConnection();
            $stmt = $pdo->prepare('
                SELECT r.payload_json
                FROM urgent_disbursement_requests u
                INNER JOIN employee_legal_requests r ON r.id = u.regularized_feb_request_id
                WHERE u.id = :urgent_id
                  AND u.requester_user_id = :user_id
                  AND u.status IN ("decaissed", "regularization_pending")
                LIMIT 1
            ');
            $stmt->execute([
                'urgent_id' => $urgentId,
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return false;
            }
            $payload = json_decode((string)($row['payload_json'] ?? ''), true);
            if (!is_array($payload)) {
                return false;
            }
            foreach (['numero_bon_commande_interne', 'code_autorisation_feb', 'bci_number', 'numero_bci', 'code_autorisation'] as $key) {
                if (hash_equals(trim((string)($payload[$key] ?? '')), $code)) {
                    return true;
                }
            }
        } catch (Throwable $e) {
        }
        return false;
    }

    /**
     * Autorise la validation FEB si un perimetre explicite est configure pour l'utilisateur.
     */
    private static function hasConfiguredFebValidationAccess(): bool
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            require_once __DIR__ . '/Database.php';
            require_once __DIR__ . '/FebValidationPolicy.php';

            return self::hasPermission('acces_feb') && \FebValidationPolicy::userHasConfiguredValidationAccess(\Database::getConnection(), $userId);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verifie si l'utilisateur a une permission specifique.
     *
     * @param string $permission Nom de la permission
     * @return bool
     */
    public static function hasPermission(string $permission): bool
    {
        if (!isset($_SESSION['permissions']) || !is_array($_SESSION['permissions'])) {
            return false;
        }

        if ($permission !== 'acces_admin' && !empty($_SESSION['permissions']['acces_admin'] ?? 0)) {
            return true;
        }

        return !empty($_SESSION['permissions'][$permission] ?? 0);
    }

    /**
     * Retourne le chemin de base web de l'application, par ex. /banamur_project_app
     */
    public static function getApplicationBasePath(): string
    {
        $projectRoot = str_replace('\\', '/', dirname(__DIR__));
        $documentRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), "\\/"));

        if ($documentRoot !== '' && str_starts_with(strtolower($projectRoot), strtolower($documentRoot))) {
            $relativePath = substr($projectRoot, strlen($documentRoot));
            $relativePath = '/' . ltrim(str_replace('\\', '/', (string)$relativePath), '/');

            return $relativePath === '/' ? '' : rtrim($relativePath, '/');
        }

        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $scriptDir = rtrim(dirname($scriptName), '/');

        if ($scriptDir === '' || $scriptDir === '.' || $scriptDir === '/') {
            return '';
        }

        $segments = explode('/', ltrim($scriptDir, '/'));
        if (count($segments) > 1) {
            array_pop($segments);
        }

        return $segments !== [] ? '/' . implode('/', $segments) : $scriptDir;
    }

    /**
     * Redirige vers la page d'accès refusé avec le bon type
     * 
     * @param string $permission Permission manquante
     * @param string $page Page demandée
     * @param bool $isAjax Si c'est une requête AJAX
     */
    public static function denyAccess(string $permission, string $page = '', bool $isAjax = false): void
    {
        AuditTrail::log('access_denied', [
            'module' => 'security',
            'action' => 'access_denied',
            'entity_type' => 'page',
            'entity_label' => $page,
            'result' => 'denied',
            'severity' => 'warning',
            'details' => [
                'permission' => $permission,
                'is_ajax' => $isAjax,
            ],
        ]);

        $accessType = self::ACCESS_TYPES[$permission] ?? 'general';
        $redirectUrl = self::getApplicationBasePath() . "/acces_refuse.php?type=" . urlencode($accessType) . "&page=" . urlencode($page);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(200); // Éviter les redirections automatiques
            echo json_encode([
                'ok' => false,
                'error' => 'Accès refusé à cette ressource',
                'redirect' => $redirectUrl,
                'access_denied' => true
            ]);
            exit();
        }

        header('Location: ' . $redirectUrl);
        exit();
    }

    /**
     * Middleware de vérification d'accès pour une page
     * À appeler en début de page pour vérifier les droits
     * 
     * @param string|null $page Nom de la page (auto-détecté si null)
     */
    public static function requirePageAccess(?string $page = null): void
    {
        if ($page === null) {
            $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
        }

        if (!self::hasPageAccess($page)) {
            $permission = self::getPrimaryPermissionForPage($page);

            // Détecter si c'est une requête AJAX
            $isAjax = (
                (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false)
                || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                || (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/request/') !== false)
            );

            self::denyAccess($permission, $page, $isAjax);
        }
    }

    public static function getPrimaryPermissionForPage(string $page): string
    {
        $permission = self::findPermissionByScriptPath(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')))
            ?? self::PAGE_PERMISSIONS[$page]
            ?? 'access_general';
        $normalized = self::normalizePermissions($permission);

        return (string)($normalized[0] ?? 'access_general');
    }

    private static function findPermissionByScriptPath(string $scriptPath)
    {
        $scriptPath = '/' . ltrim(str_replace('\\', '/', $scriptPath), '/');
        $matches = [];
        foreach (self::PAGE_PERMISSIONS as $configuredPage => $permission) {
            $configuredPage = ltrim(str_replace('\\', '/', (string) $configuredPage), '/');
            if (strpos($configuredPage, '/') === false) {
                continue;
            }
            if (str_ends_with(strtolower($scriptPath), '/' . strtolower($configuredPage))) {
                $matches[$configuredPage] = $permission;
            }
        }
        if ($matches === []) {
            return null;
        }
        uksort($matches, static fn(string $left, string $right): int => strlen($right) <=> strlen($left));
        return reset($matches);
    }

    /**
     * Charge les permissions utilisateur depuis la base de données
     * 
     * @param int $userId ID de l'utilisateur
     * @return array Permissions de l'utilisateur
     */
    public static function loadUserPermissions(int $userId, bool $ensureSchema = true): array
    {
        try {
            require_once __DIR__ . '/Database.php';
            $pdo = \Database::getConnection();

            if ($ensureSchema) {
                self::ensureOptionalPermissionColumns($pdo);
            }

            $stmt = $pdo->prepare("
            SELECT *
            FROM user_devis_banamur
            WHERE id = :id AND active = 1
            LIMIT 1
        ");

            $stmt->execute(['id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                return [];
            }

            $permissions = [];

            foreach ($user as $column => $value) {
                if (self::isPermissionColumn((string) $column)) {
                    $permissions[$column] = (bool)$value;
                }
            }
            foreach (self::universalPermissions($pdo) as $permission) {
                $permissions[$permission] = true;
            }

            return $permissions;
        } catch (Exception $e) {
            error_log("Erreur chargement permissions: " . $e->getMessage());
            return [];
        }
    }

    public static function ensureOptionalPermissionColumns(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        try {
            $columns = $pdo->query("SHOW COLUMNS FROM user_devis_banamur")
                ->fetchAll(PDO::FETCH_COLUMN, 0);

            $alter = [];
            require_once __DIR__.'/AccountingPermissions.php';
            foreach (array_keys(AccountingPermissions::LABELS) as $permission) {
                if (!in_array($permission,$columns,true)) $alter[] = "ADD COLUMN {$permission} TINYINT(1) NOT NULL DEFAULT 0";
            }


            foreach (['acces_signataire_point_jour', 'acces_arreter_point_jour', 'acces_decaisser_point_jour', 'acces_recommandations_tresorerie', 'acces_tresorerie_lecture'] as $pointPermission) {
                if (!in_array($pointPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$pointPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }

            if (!in_array('acces_rh', $columns, true)) {
                $alter[] = "ADD COLUMN acces_rh TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_logistique', $columns, true)) {
                $alter[] = "ADD COLUMN acces_logistique TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_hse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_hse TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_contrats', $columns, true)) {
                $alter[] = "ADD COLUMN acces_contrats TINYINT(1) NOT NULL DEFAULT 0";
            }
            foreach (['rh', 'juridique', 'technique', 'logistique', 'qhse', 'comptabilite'] as $contractStage) {
                $permission = 'acces_contrat_etape_' . $contractStage;
                if (!in_array($permission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$permission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }
            if (!in_array('acces_direction', $columns, true)) {
                $alter[] = "ADD COLUMN acces_direction TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_planning', $columns, true)) {
                $alter[] = "ADD COLUMN acces_planning TINYINT(1) NOT NULL DEFAULT 0";
            }
            // Historiquement, l'emploi du temps était accessible à tous.
            if (!in_array('acces_emploi_du_temps', $columns, true)) {
                $alter[] = "ADD COLUMN acces_emploi_du_temps TINYINT(1) NOT NULL DEFAULT 1";
            }
            if (!in_array('acces_dashboard_stat_taches', $columns, true)) {
                $alter[] = "ADD COLUMN acces_dashboard_stat_taches TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_ticketing', $columns, true)) {
                $alter[] = "ADD COLUMN acces_ticketing TINYINT(1) NOT NULL DEFAULT 1";
            }
            if (!in_array('acces_planning_chantier', $columns, true)) {
                $alter[] = "ADD COLUMN acces_planning_chantier TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Espace employé : self-service (par défaut accessible à tous les comptes actifs)
            if (!in_array('acces_employe', $columns, true)) {
                $alter[] = "ADD COLUMN acces_employe TINYINT(1) NOT NULL DEFAULT 1";
            }

            // Module Emails / Mailing
            if (!in_array('acces_mail', $columns, true)) {
                $alter[] = "ADD COLUMN acces_mail TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Module Fournisseurs
            if (!in_array('acces_fournisseur', $columns, true)) {
                $alter[] = "ADD COLUMN acces_fournisseur TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Module Facturation
            if (!in_array('acces_facturation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_facturation TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_relink_fiches_debourse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_relink_fiches_debourse TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('ajuster_beneficiaire_fournisseur_fiche', $columns, true)) {
                $alter[] = "ADD COLUMN ajuster_beneficiaire_fournisseur_fiche TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('regulariser_fournisseur_sans_preuve', $columns, true)) {
                $alter[] = "ADD COLUMN regulariser_fournisseur_sans_preuve TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_liste_unite', $columns, true)) {
                $alter[] = "ADD COLUMN acces_liste_unite TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('acces_veille_ao', $columns, true)) {
                $alter[] = "ADD COLUMN acces_veille_ao TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('modifier_classement_devis', $columns, true)) {
                $alter[] = "ADD COLUMN modifier_classement_devis TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('modifier_devis_chantier_actif', $columns, true)) {
                $alter[] = "ADD COLUMN modifier_devis_chantier_actif TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('modifier_dates_travaux', $columns, true)) {
                $alter[] = "ADD COLUMN modifier_dates_travaux TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('transferer_historique_devis', $columns, true)) {
                $alter[] = "ADD COLUMN transferer_historique_devis TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('suivi_devis_contradictoires', $columns, true)) {
                $alter[] = "ADD COLUMN suivi_devis_contradictoires TINYINT(1) NOT NULL DEFAULT 0";
            }
            foreach (['ao_view', 'ao_export', 'ao_manage', 'ao_mark_interesting', 'ao_mark_not_interested', 'ao_create_crm', 'ao_manage_alerts', 'ao_manage_diffusion', 'ao_admin', 'ao_dg_decision'] as $aoPermission) {
                if (!in_array($aoPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$aoPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }

            // Module CRM (Direction commerciale)
            if (!in_array('acces_crm', $columns, true)) {
                $alter[] = "ADD COLUMN acces_crm TINYINT(1) NOT NULL DEFAULT 0";
            }
            foreach (['acces_crm_dashboard', 'acces_crm_prospects', 'acces_crm_clients', 'acces_crm_opportunites', 'acces_crm_rdv', 'acces_crm_admin'] as $crmPermission) {
                if (!in_array($crmPermission, $columns, true)) {
                    $alter[] = "ADD COLUMN {$crmPermission} TINYINT(1) NOT NULL DEFAULT 0";
                }
            }

            // Module Communication (remplace WhatsApp)
            if (!in_array('acces_carnet_adresse', $columns, true)) {
                $alter[] = "ADD COLUMN acces_carnet_adresse TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_assistance_distance', $columns, true)) {
                $alter[] = "ADD COLUMN acces_assistance_distance TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_parc_informatique', $columns, true)) {
                $alter[] = "ADD COLUMN acces_parc_informatique TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_communication', $columns, true)) {
                $alter[] = "ADD COLUMN acces_communication TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_documentation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_documentation TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_upload', $columns, true)) {
                $alter[] = "ADD COLUMN document_upload TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_edit', $columns, true)) {
                $alter[] = "ADD COLUMN document_edit TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_archive', $columns, true)) {
                $alter[] = "ADD COLUMN document_archive TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('document_admin', $columns, true)) {
                $alter[] = "ADD COLUMN document_admin TINYINT(1) NOT NULL DEFAULT 0";
            }

            if (!in_array('acces_formation', $columns, true)) {
                $alter[] = "ADD COLUMN acces_formation TINYINT(1) NOT NULL DEFAULT 0";
            }
            if (!in_array('formation_admin', $columns, true)) {
                $alter[] = "ADD COLUMN formation_admin TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Permission métier: terminer/archiver un chantier
            if (!in_array('valider_archivage_chantier', $columns, true)) {
                $alter[] = "ADD COLUMN valider_archivage_chantier TINYINT(1) NOT NULL DEFAULT 0";
            }

            // Validation FEB par service (liste de services autorisés)
            if (!in_array('feb_validation_services', $columns, true)) {
                $alter[] = "ADD COLUMN feb_validation_services TEXT DEFAULT NULL";
            }

            if ($alter) {
                $pdo->exec('ALTER TABLE user_devis_banamur ' . implode(', ', $alter));
            }
            self::ensureUniversalPermissionTable($pdo);
        } catch (Throwable $e) {
            // Tolérer l'absence de droits ALTER en production.
        }

        $done = true;
    }

    /**
     * Met à jour les permissions en session
     * Utile après modification des droits
     */
    public static function refreshSessionPermissions(): void
    {
        if (isset($_SESSION['user_id'])) {
            $_SESSION['permissions'] = self::loadUserPermissions((int)$_SESSION['user_id']);
        }
    }

    public static function isPermissionColumn(string $column): bool
    {
        foreach ([
            'acces_',
            'compta_',
            'ao_',
            'modifier_',
            'visualiser_',
            'soumettre_',
            'valider_',
            'masquer_',
            'envoyer_',
            'document_',
            'logistique_',
            'ajuster_',
            'regulariser_',
        ] as $prefix) {
            if (str_starts_with($column, $prefix)) {
                return true;
            }
        }

        return in_array($column, ['gestion_utilisateur', 'formation_admin', 'module_logistique'], true);
    }

    public static function permissionColumns(PDO $pdo): array
    {
        $columns = $pdo->query('SHOW COLUMNS FROM user_devis_banamur')->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        return array_values(array_filter($columns, static fn($column): bool => self::isPermissionColumn((string) $column)));
    }

    public static function modulePermissionColumns(PDO $pdo): array
    {
        return array_values(array_filter(
            self::permissionColumns($pdo),
            static fn(string $column): bool => (str_starts_with($column, 'acces_') && $column !== 'acces_comptabilite') || $column === 'module_logistique'
        ));
    }

    public static function universalPermissions(?PDO $pdo = null): array
    {
        try {
            if (!$pdo) {
                require_once __DIR__ . '/Database.php';
                $pdo = \Database::getConnection();
            }
            self::ensureUniversalPermissionTable($pdo);
            return $pdo->query(
                'SELECT permission_key FROM finea_universal_permissions WHERE is_active = 1 ORDER BY permission_key'
            )->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
        } catch (Throwable $e) {
            return self::DEFAULT_UNIVERSAL_PERMISSIONS;
        }
    }

    public static function isUniversalPermission(string $permission, ?PDO $pdo = null): bool
    {
        return in_array($permission, self::universalPermissions($pdo), true);
    }

    public static function setUniversalPermission(PDO $pdo, string $permission, bool $enabled, ?int $actorUserId = null): void
    {
        self::ensureUniversalPermissionTable($pdo);
        if (!in_array($permission, self::modulePermissionColumns($pdo), true)) {
            throw new InvalidArgumentException('Permission de module invalide.');
        }

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $stmt = $pdo->prepare('
                INSERT INTO finea_universal_permissions
                    (permission_key, is_active, activated_by, activated_at, updated_at)
                VALUES
                    (:permission_key, :is_active, :activated_by, IF(:is_active_date = 1, NOW(), NULL), NOW())
                ON DUPLICATE KEY UPDATE
                    is_active = VALUES(is_active),
                    activated_by = VALUES(activated_by),
                    activated_at = IF(VALUES(is_active) = 1, NOW(), activated_at),
                    updated_at = NOW()
            ');
            $stmt->execute([
                ':permission_key' => $permission,
                ':is_active' => $enabled ? 1 : 0,
                ':activated_by' => $actorUserId && $actorUserId > 0 ? $actorUserId : null,
                ':is_active_date' => $enabled ? 1 : 0,
            ]);

            if ($enabled) {
                $pdo->exec('UPDATE user_devis_banamur SET `' . $permission . '` = 1 WHERE `' . $permission . '` <> 1');
            }
            if ($startedTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function universalAccessOverview(PDO $pdo): array
    {
        self::ensureUniversalPermissionTable($pdo);
        $modulePermissions = self::modulePermissionColumns($pdo);
        $universal = self::universalPermissions($pdo);
        $activeUsers = (int) $pdo->query('SELECT COUNT(*) FROM user_devis_banamur WHERE active = 1')->fetchColumn();
        $rows = [];

        foreach ($modulePermissions as $permission) {
            $granted = 0;
            if ($activeUsers > 0) {
                $granted = (int) $pdo->query(
                    'SELECT COUNT(*) FROM user_devis_banamur WHERE active = 1 AND `' . $permission . '` = 1'
                )->fetchColumn();
            }
            $rows[] = [
                'permission' => $permission,
                'label' => self::permissionLabel($permission),
                'is_universal' => in_array($permission, $universal, true),
                'active_users' => $activeUsers,
                'granted_users' => $granted,
                'coverage_rate' => $activeUsers > 0 ? round(($granted / $activeUsers) * 100, 1) : 0.0,
                'is_suggestion' => $activeUsers > 0 && $granted === $activeUsers && !in_array($permission, $universal, true),
            ];
        }

        return $rows;
    }

    public static function permissionLabel(string $permission): string
    {
        require_once __DIR__.'/AccountingPermissions.php';
        if (isset(AccountingPermissions::LABELS[$permission])) return AccountingPermissions::LABELS[$permission];
        $labels = [
            'regulariser_fournisseur_sans_preuve' => 'Régulariser un fournisseur sans preuve (bypass audité)',
            'acces_ticketing' => 'Centre de services / Tickets',
            'acces_emploi_du_temps' => 'Emploi du temps',
            'acces_employe' => 'Espace employé',
            'acces_dashboard' => 'Tableau de bord',
            'acces_profil' => 'Profil',
            'acces_carnet_adresse' => 'Carnet d’adresse',
            'acces_parc_informatique' => 'Gérer le parc informatique',
            'module_logistique' => 'Module logistique',
            'transferer_historique_devis' => 'Fusionner l’historique financier des devis',
            'suivi_devis_contradictoires' => 'Superviser les devis contradictoires FEB',
            'acces_signataire_point_jour' => 'Signer le point de décaissement du jour',
            'acces_arreter_point_jour' => 'Arrêter le point de décaissement du jour',
            'acces_decaisser_point_jour' => 'Effectuer le paiement des points signés',
            'acces_recommandations_tresorerie' => 'Voir les informations et recommandations de trésorerie',
            'acces_tresorerie_lecture' => 'Consulter la trésorerie DG en lecture seule',
        ];
        return $labels[$permission] ?? ucfirst(str_replace('_', ' ', preg_replace('/^(acces_|module_)/', '', $permission)));
    }

    private static function ensureUniversalPermissionTable(PDO $pdo): void
    {
        static $initialized = [];
        $connectionKey = spl_object_id($pdo);
        if (!empty($initialized[$connectionKey])) {
            return;
        }

        $pdo->exec('
            CREATE TABLE IF NOT EXISTS finea_universal_permissions (
                permission_key VARCHAR(120) NOT NULL PRIMARY KEY,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                activated_by INT DEFAULT NULL,
                activated_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_finea_universal_permissions_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
        $seed = $pdo->prepare('
            INSERT IGNORE INTO finea_universal_permissions
                (permission_key, is_active, activated_at)
            VALUES (:permission_key, 1, NOW())
        ');
        foreach (self::DEFAULT_UNIVERSAL_PERMISSIONS as $permission) {
            $seed->execute([':permission_key' => $permission]);
        }
        foreach (self::universalPermissionsWithoutEnsure($pdo) as $permission) {
            if (in_array($permission, self::permissionColumns($pdo), true)) {
                $pdo->exec('UPDATE user_devis_banamur SET `' . $permission . '` = 1 WHERE `' . $permission . '` <> 1');
            }
        }
        $initialized[$connectionKey] = true;
    }

    private static function universalPermissionsWithoutEnsure(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT permission_key FROM finea_universal_permissions WHERE is_active = 1 ORDER BY permission_key'
        )->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    }

    private static function normalizePermissions($permission): array
    {
        if (is_array($permission)) {
            return array_values(array_filter($permission, static function ($item): bool {
                return is_string($item) && $item !== '';
            }));
        }

        return is_string($permission) && $permission !== '' ? [$permission] : [];
    }
}




