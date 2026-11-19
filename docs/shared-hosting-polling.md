# Polling sur hébergement mutualisé

Fréquences avant/après :

| Flux | Avant | Après |
|---|---:|---:|
| Notifications navbar | 15 s, jusqu'à 2 requêtes/cycle | 45 s, 1 requête/cycle, suspendu si onglet masqué |
| Chat employés | 5 s | 15 s si ouvert, 60 s si fermé, suspendu si onglet masqué |
| Communication/messages | 6 s | 15 s si visible, suspendu si onglet masqué |
| Communication/typing | 2,5 s en continu | 7 s uniquement avec conversation active et visible |
| `EmployeeChat::ensureTables` | À chaque requête chat | Migration/installation contrôlée uniquement |
| Alertes de complétude | À chaque polling notification | Cron quotidien idempotent |

Toutes les boucles attendent la fin de la requête précédente avant de programmer la suivante. Les sessions PHP des endpoints fréquents sont libérées dès que les données nécessaires ont été copiées.

## Déploiement

1. Exécuter les migrations `database/migrations/20260921_*.sql`.
2. Exécuter `php scripts/install_employee_chat.php` une fois pour synchroniser les groupes de service.
3. Planifier `php cron/daily_completeness_alerts.php` quotidiennement à 06:00.

Le cron de complétude utilise un verrou MySQL et une table de suivi : une seconde exécution le même jour reste sans effet.
