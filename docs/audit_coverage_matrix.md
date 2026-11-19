# Matrice de couverture audit ERP

Date de référence: 28/03/2026

## Convention retenue

- Standard principal: `auth_check.php` journalise automatiquement les consultations via `page_view` et les appels AJAX via `request_access`.
- Standard complémentaire: `AuditTrail::logPageConsultation()` est utilisé pour les pages atypiques qui ne passent pas naturellement par `auth_check.php` ou qui nécessitent un contexte métier enrichi.
- Compléments spécialisés:
  - `AuditTrail::logVisualEvent()` pour aperçu navigateur et impression
  - `AuditTrail::logFileEvent()` pour export et téléchargement

## Synthèse par module

| Module | Statut | Mode de couverture dominant | Notes |
| --- | --- | --- | --- |
| root/core | Couvert | auth_check + logPageConsultation | Pages racine et endpoints utilisateurs couverts |
| admin | Couvert | auth_check | Audit ERP, outils admin, exports admin |
| api | Couvert | auth_check | Endpoints applicatifs et polling |
| crm | Couvert | auth_check | Pages de consultation CRM |
| decaissement | Couvert | auth_check + logVisualEvent + logFileEvent | Pages métier, impressions, exports, téléchargements |
| employe | Couvert | auth_check | Espace employé |
| emails | Couvert | auth_check | Boîte mail et pièces jointes |
| facturation | Couvert | auth_check + logFileEvent | Consultation et téléchargement |
| fournisseur | Couvert | auth_check | Pages fournisseurs |
| logi | Couvert | auth_check | Pages logistique |
| rh | Couvert | auth_check + page_view enrichi | Dashboard et pages RH |
| request | Couvert | auth_check + logFileEvent | Endpoints exports et actions |

## Matrice par page

### Root / Core

| Page | Couverture | Détail |
| --- | --- | --- |
| acces_refuse.php | Explicite | `logPageConsultation()` |
| ajouter_utilisateur.php | auth_check | `page_view` central |
| choisir_devis_pour_chantier.php | auth_check | corrigé lors du passage systématique |
| communication.php | auth_check | `page_view` central |
| dashboard.php | auth_check | `page_view` central |
| dashboard_planing.php | auth_check | `page_view` central |
| debuter_chantier.php | auth_check | corrigé lors du passage systématique |
| deconnex.php | Explicite | `logout` |
| details_chantier.php | auth_check | via flux standard |
| editer_section.php | auth_check | `page_view` central |
| emploi_du_temps.php | auth_check | `page_view` central |
| generer_devis.php | auth_check | `page_view` central |
| gerer_debourse.php | auth_check | `page_view` central |
| liste_chantier.php | auth_check | `page_view` central |
| liste_client.php | auth_check | `page_view` central |
| liste_debourse.php | auth_check | `page_view` central |
| liste_devis.php | auth_check | `page_view` central |
| liste_facture.php | auth_check | `page_view` central |
| liste_offre.php | auth_check | `page_view` central |
| liste_unite.php | auth_check | `page_view` central |
| liste_utilisateur.php | auth_check | `page_view` central |
| modifier_client.php | auth_check | `page_view` central |
| modifier_devis.php | auth_check | `page_view` central |
| modifier_offre.php | auth_check | `page_view` central |
| modifier_utilisateur.php | auth_check | `page_view` central |
| profil.php | auth_check | corrigé lors du passage systématique |
| reactiver_utilisateur.php | auth_check + action | `user_reactivated` |
| selection_portail.php | auth_check | `page_view` central |
| supprimer_utilisateur.php | auth_check + action | `user_deleted` |
| validation_demandes_finances.php | auth_check | `page_view` central |
| visualiser_section.php | auth_check | `page_view` central |
| voir_debourse.php | auth_check | `page_view` central |

### Admin

| Page | Couverture | Détail |
| --- | --- | --- |
| admin/add_user.php | auth_check | standard |
| admin/audit_erp.php | auth_check + exports | CSV/PDF filtrés tracés |
| admin/dashboard_admin.php | auth_check + export | historique login exporté |
| admin/db_tools.php | auth_check + export | exports SQL tracés |
| admin/edit_user.php | auth_check | standard |
| admin/email_logs.php | auth_check | standard |
| admin/fne_tools.php | auth_check | standard |
| admin/gestion_acces.php | auth_check | standard |
| admin/liste_users.php | auth_check | standard |
| admin/maintenance_point_financier_indexes.php | auth_check | standard |
| admin/notifications_settings.php | auth_check | standard |
| admin/update_user_permissions.php | auth_check + action | permissions tracées |

### CRM

| Page | Couverture | Détail |
| --- | --- | --- |
| crm/clients.php | auth_check | standard |
| crm/dashboard_crm.php | auth_check | standard |
| crm/opportunites.php | auth_check | standard |
| crm/prospects.php | auth_check | standard |
| crm/rdv.php | auth_check | standard |

### RH

| Page | Couverture | Détail |
| --- | --- | --- |
| rh/dashboard_rh.php | auth_check + page_view enrichi | consultation RH détaillée |
| rh/integration_personnel_rh.php | auth_check + action | création personnel tracée |
| rh/liste_personnel_rh.php | auth_check | standard |
| rh/parametrage_rh.php | auth_check + action | CRUD paramétrage tracé |
| rh/paie_rh.php | auth_check | standard |
| rh/pointage_journalier_rh.php | auth_check | standard |
| rh/pointage_mensuel_personnel_rh.php | auth_check | standard |
| rh/validation_demandes_rh.php | auth_check | standard |
| rh/export_contrat_rh.php | auth_check | standard |
| rh/export_pointage_excel.php | auth_check | standard |
| rh/export_pointage_pdf.php | auth_check | standard |

### Décaissement

| Page | Couverture | Détail |
| --- | --- | --- |
| decaissement/bon_commande.php | auth_check | corrigé lors du passage systématique |
| decaissement/bon_essence.php | preview explicite | `logVisualEvent('preview')` |
| decaissement/detail_travaux_chantier.php | preview explicite | `logVisualEvent('preview')` |
| decaissement/fiche_pro.php | preview explicite | `logVisualEvent('preview')` |
| decaissement/historique_fiches.php | auth_check | standard |
| decaissement/liste_fiches_achat.php | auth_check | standard |
| decaissement/liste_fiches_approbation.php | auth_check + page_view enrichi | accès standard + contexte métier |
| decaissement/liste_fiches_compta.php | auth_check + page_view enrichi | accès standard + contexte métier |
| decaissement/liste_fiches_conformite.php | auth_check + page_view enrichi | accès standard + contexte métier |
| decaissement/point_financier.php | auth_check + page_view enrichi | accès standard + contexte métier |
| decaissement/selection_budgets_chantiers.php | preview explicite | `logVisualEvent('preview')` |
| decaissement/success_fiche.php | auth_check + page_view enrichi | écran de succès tracé |
| decaissement/voir_fiche_details.php | preview explicite | `logVisualEvent('preview')` |

### Exports / téléchargements décaissement et planning

| Page | Couverture | Détail |
| --- | --- | --- |
| request/export_debourse_excel.php | logFileEvent | export tracé |
| request/export_debourse_pdf.php | logFileEvent | export tracé |
| request/export_debourses_excel.php | logFileEvent | export tracé |
| request/export_debourses_pdf.php | logFileEvent | export tracé |
| request/export_detail_chantier.php | logFileEvent | export tracé |
| request/export_planning_gantt.php | logFileEvent | export tracé |
| decaissement/request/download_bon_reception.php | logFileEvent | téléchargement tracé |
| decaissement/request/export_bon_commande_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_dashboard.php | logFileEvent | export tracé |
| decaissement/request/export_dashboard_planing_excel.php | logFileEvent | export tracé |
| decaissement/request/export_dashboard_planing_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_fiche_decaissement_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_achat_excel.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_achat_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_approbation_excel.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_approbation_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_conformite_excel.php | logFileEvent | export tracé |
| decaissement/request/export_fiches_conformite_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_point_financier_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_point_reunion_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_project_reunion_sheet_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_recap_reunion_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_reunion_multi_chantiers_pdf.php | logFileEvent | export tracé |
| decaissement/request/export_tous_budgets.php | logFileEvent | export tracé |
| decaissement/request/fiches_compta_list.php | logFileEvent | export CSV tracé |

### Facturation

| Page | Couverture | Détail |
| --- | --- | --- |
| facturation/dashboard_facturation.php | auth_check | standard |
| facturation/download_facture_achat.php | auth_check + logFileEvent | téléchargement tracé |
| facturation/edit_facture_achat.php | auth_check | standard |
| facturation/edit_facture_vente.php | auth_check | standard |
| facturation/factures_achat.php | auth_check | standard |
| facturation/factures_vente.php | auth_check | standard |

## Pages publiques / intentionnellement hors consultation authentifiée

| Page | Raison |
| --- | --- |
| index.php | redirection d’entrée |
| login.php | page publique d’authentification |
| login_unlock.php | flux de déverrouillage |
| reset_password.php | flux public de réinitialisation |
| reset_password_set.php | flux public de réinitialisation |
| tracking_pixel.php | pixel email technique |
| check_columns.php | utilitaire de contrôle |
| temp_tbody.php | fragment technique |
| tmp_compare_point_financier.php | utilitaire temporaire |

## Conclusion

- Les consultations standards sont désormais homogénéisées par `auth_check.php`.
- Les écrans atypiques et les pages visuelles utilisent `AuditTrail::logPageConsultation()` ou `logVisualEvent()`.
- Les exports et téléchargements sensibles utilisent `logFileEvent()`.
- Après ce passage systématique, la couverture des pages applicatives authentifiées est considérée comme complète sur le périmètre exploré.