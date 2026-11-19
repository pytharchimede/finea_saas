# Point financier quotidien par email

## Fichiers concernés
- `config/point_financier_digest.php`
- `cron/send_point_financier_daily_digest.php`
- `decaissement/request/point_financier_pdf_report.php`
- `decaissement/request/export_point_financier_pdf.php`

## Paramétrage
1. Ouvrir `config/point_financier_digest.php`.
2. Passer `enabled` à `true` quand les adresses sont validées.
3. Renseigner l'adresse du DG dans `to`.
4. Renseigner les adresses en copie dans `cc`.
5. Ajuster `send_when_empty`:
   - `false`: aucun email si aucun décaissement la veille
   - `true`: un email est quand même envoyé avec PDF joint

## Anti-spam
- Un verrou fichier empêche les exécutions simultanées.
- La table `scheduled_email_dispatches` mémorise l'état d'envoi par date de reporting.
- Si un envoi réussi existe déjà pour la date, le script s'arrête.
- Les jours sans décaissement sont marqués `skipped` si `send_when_empty=false`.
- `--force` permet une relance volontaire.

## Tests manuels
```powershell
php cron/send_point_financier_daily_digest.php --dry-run
php cron/send_point_financier_daily_digest.php --force --date=2026-04-06 --dry-run
```

## Mise en place Windows Task Scheduler
1. Créer une tâche planifiée.
2. Déclencheur: tous les jours à 08:00.
3. Action:
```text
Programme/script: C:\wamp\bin\php\php8.x.x\php.exe
Arguments: C:\wamp\www\banamur_project_app\cron\send_point_financier_daily_digest.php
Démarrer dans: C:\wamp\www\banamur_project_app
```
4. Ajouter la variable d'environnement système ou utilisateur:
```text
APP_BASE_URL=http://localhost/banamur_project_app
```
5. Faire un premier essai avec `--dry-run`, puis activer `enabled=true`.

## Mise en place Linux cron
```bash
0 8 * * * cd /var/www/banamur_project_app && APP_BASE_URL=https://votre-domaine.tld /usr/bin/php cron/send_point_financier_daily_digest.php >> storage/logs/point_financier_digest.log 2>&1
```

## Relance exceptionnelle
```powershell
php cron/send_point_financier_daily_digest.php --force --date=2026-04-06
```
