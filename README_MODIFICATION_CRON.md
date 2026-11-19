# Patch modification + cron — Besoins récurrents FINEA

Ce patch ajoute :

- modification d’un besoin récurrent depuis le même formulaire `add_form.php?id=ID` ;
- bouton **Modifier** dans le portefeuille ;
- mise à jour BDD via Controller → Service → Repository ;
- conservation du cron `cron_generate.php` pour rappels + génération automatique des FEB.

## Cron cPanel recommandé en production

À ajouter dans cPanel > Cron Jobs :

```bash
0	6	*	*	*	sleep $((RANDOM \% 60)) && /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/recurring_needs/cron_generate.php >> /home/fidestci/recurring_needs_cron.log 2>&1
```

Cette planification lance le traitement tous les jours à 06:00.

## Test manuel

```bash
/usr/local/bin/php -q /home/fidestci/projet.fidest.ci/recurring_needs/cron_generate.php
```

Depuis le navigateur, tu peux aussi tester :

```txt
https://projet.fidest.ci/recurring_needs/cron_generate.php?dry_run=1
```
