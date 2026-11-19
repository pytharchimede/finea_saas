# Cron — Besoins récurrents FINEA

Ce patch ajoute le cron complet sans modifier le design.

## Fichier principal

```txt
recurring_needs/cron_generate.php
```

## Commande cPanel conseillée

À exécuter chaque jour, par exemple à 06:00 :

```bash
/usr/local/bin/php -q /home/USER/public_html/banamur_project_app/recurring_needs/cron_generate.php >/dev/null 2>&1
```

Si ton hébergeur utilise un autre chemin PHP, tester aussi :

```bash
php -q /home/USER/public_html/banamur_project_app/recurring_needs/cron_generate.php
```

## Ce que fait le cron

1. Cherche les besoins actifs dont le rappel doit être envoyé.
2. Crée une notification dans `finea_recurring_need_notifications`.
3. Cherche les besoins actifs à générer selon `generate_days_before`.
4. Crée la FEB dans `employee_legal_requests` via `EmployeeLegalRequestManager::createRequest()`.
5. Journalise dans `finea_recurring_need_generations`.
6. Avance automatiquement `next_due_date` à la prochaine échéance.

## Protection anti-doublon

- Une FEB n’est générée qu’une seule fois par besoin + date d’échéance.
- Un rappel n’est créé qu’une seule fois par jour pour la même échéance.
