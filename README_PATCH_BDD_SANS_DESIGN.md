# Patch BDD — Besoins récurrents FINEA

Ce patch ne remplace pas le design de `index.php`, `add_form.php` ou `_recurring_needs_common.php`.
Il ajoute uniquement la couche fonctionnelle BDD du module :

- `controllers/RecurringNeedController.php`
- `services/RecurringNeedService.php`
- `repositories/RecurringNeedRepository.php`
- `model/RecurringNeedMigration.php`
- `db/migrations.sql`
- `cron_generate.php`

## Fonctionnement

Le formulaire actuel `add_form.php` envoie le POST vers :

```txt
RecurringNeedController::store()
→ RecurringNeedService::createFromRequest()
→ RecurringNeedRepository::create()
→ finea_recurring_needs
```

La génération automatique fait :

```txt
cron_generate.php
→ RecurringNeedService::runAutomaticGeneration()
→ EmployeeLegalRequestManager::createRequest()
→ employee_legal_requests
```

## Important

Le fichier `add_form.php` doit conserver :

```php
require_once __DIR__ . '/controllers/RecurringNeedController.php';
```

et ton formulaire doit rester en :

```html
<form method="post">
```

Les champs déjà présents dans ton formulaire sont pris en charge :

- `title`
- `amount_estimated`
- `priority`
- `need_type`
- `financial_mode`
- `frequency_type`
- `frequency_interval`
- `start_date`
- `next_due_date`
- `generate_days_before`
- `fournisseur_name`
- `description`
- `save_as_draft`

## Tables créées automatiquement

La migration est lancée automatiquement par `RecurringNeedRepository`.
Les tables créées sont :

- `finea_recurring_needs`
- `finea_recurring_need_generations`
- `finea_recurring_need_notifications`

Tu peux aussi exécuter manuellement `db/migrations.sql` si tu préfères contrôler la création en phpMyAdmin.
