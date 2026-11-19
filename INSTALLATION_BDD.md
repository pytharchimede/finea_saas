# Module Besoins récurrents — version enregistrement BDD fonctionnel

## Ce qui est fonctionnel immédiatement

- À l'ouverture du module, `RecurringNeedRepository` lance `RecurringNeedMigration::migrate($pdo)`.
- Les tables sont créées automatiquement si elles n'existent pas :
  - `finea_recurring_needs`
  - `finea_recurring_need_generations`
  - `finea_recurring_need_notifications`
- Le formulaire `add_form.php` envoie ses champs en POST.
- `RecurringNeedController::store()` appelle `RecurringNeedService::createFromRequest()`.
- `RecurringNeedService` valide/nettoie les données.
- `RecurringNeedRepository::create()` fait l'INSERT réel dans `finea_recurring_needs`.
- Le dashboard relit ensuite les enregistrements depuis la BDD.

## Circuit d'enregistrement

```txt
add_form.php
  POST title, amount_estimated, priority, need_type, financial_mode, frequency_type, etc.
    ↓
controllers/RecurringNeedController.php
  store()
    ↓
services/RecurringNeedService.php
  createFromRequest()
    ↓
repositories/RecurringNeedRepository.php
  create()
    ↓
finea_recurring_needs
```

## Ce qui reste à brancher pour la FEB automatique réelle

La création du modèle récurrent est opérationnelle.
Pour que le cron crée une vraie FEB dans tes tables existantes, il faut remplacer :

```php
private function createFebDraftFromNeed(array $need, string $dueDate): ?int
```

dans `recurring_needs/services/RecurringNeedService.php`.

J'ai besoin des fichiers/tables suivants :

1. Le fichier qui crée actuellement une FEB depuis l'espace employé, probablement `nouvelle_demande_employe.php`.
2. Le manager/service utilisé pour enregistrer une FEB, par exemple `EmployeeLegalRequestManager.php` ou équivalent.
3. La structure SQL de la table principale FEB.
4. La structure SQL de la fiche de décaissement si elle est créée en même temps.
5. `NotificationManager.php` si tu veux envoyer la notification système/mail au moment de la génération.

## Cron

```bash
php /home/USER/public_html/banamur_project_app/recurring_needs/cron_generate.php
```

