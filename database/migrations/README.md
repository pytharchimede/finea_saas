# Migrations SQL

Ce dossier contient les migrations non destructives ajoutées lors des évolutions récentes de FINEA.

Avant application en production : sauvegarder la base, exécuter les migrations sur staging, vérifier les journaux SQL et les workflows concernés, puis déployer sur production.

Le chat employés est installé avec `20260921_create_employee_chat_tables.sql`. Après la migration, exécuter une fois `php scripts/install_employee_chat.php` pour initialiser/synchroniser les groupes de service. Cette commande peut être rejouée sans risque, mais ne doit pas être appelée depuis un endpoint HTTP.

Les alertes de complétude nécessitent `20260921_create_completeness_alert_runs.sql`, puis `cron/daily_completeness_alerts.php` une fois par jour (fréquence recommandée : 06:00, avant la prise de service).

La validation humaine des justificatifs FEB nécessite `20260921_create_feb_human_document_reviews.sql`. Après migration, choisir le mode et les validateurs dans `admin/feb_ocr_settings.php`. Le mode par défaut reste la vérification IA.

Après la mise en ligne du correctif FEB/fiche, exécuter `php scripts/production_maintenance.php` sur le serveur de production. Cette commande synchronise une seule fois les montants (`montant_fiche` et `montant_initial_fiche`) et le descriptif des fiches non décaissées avec leur FEB approuvée. Elle affiche les fiches corrigées et les cas ignorés ; les fiches décaissées ou liées à plusieurs fiches ne sont pas modifiées automatiquement. Sauvegarder la base avant l'exécution et examiner les cas ignorés dans la sortie et `finea_data_migrations`.
