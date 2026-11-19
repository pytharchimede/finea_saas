# Patch FINEA — Portefeuille + validation + cron

Objectif : conserver ton design actuel et connecter la liste à la BDD.

## Fichiers à remplacer / ajouter

- `recurring_needs/index.php`
- `recurring_needs/add_form.php`
- `recurring_needs/controllers/RecurringNeedController.php`
- `recurring_needs/services/RecurringNeedService.php`
- `recurring_needs/repositories/RecurringNeedRepository.php`
- `recurring_needs/model/RecurringNeedMigration.php`
- `recurring_needs/db/migrations.sql`
- `recurring_needs/cron_generate.php`

## Ajouts

- Après création, redirection vers le portefeuille.
- Liste réelle depuis `finea_recurring_needs`.
- Filtres recherche + statut.
- Boutons :
  - Valider
  - Suspendre
  - Réactiver
- Cron pour rappels et génération automatique FEB.

## Cron cPanel

```bash
/usr/local/bin/php -q /home/USER/public_html/banamur_project_app/recurring_needs/cron_generate.php >/dev/null 2>&1
```

Fréquence recommandée : 1 fois par jour à 06:00.
