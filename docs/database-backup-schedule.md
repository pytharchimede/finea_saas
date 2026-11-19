# Sauvegardes FINEA : lundi, mercredi, vendredi

Le cron historique continuait à démarrer chaque heure mais échouait avant finalisation : SELECT PDO entièrement mis en mémoire (limite hébergeur de 256 Mo), ou max_statement_time MariaDB dépassé. Les fichiers SQL gzip finalisés ne sont donc plus récents.

## Export

La lecture des tables utilise désormais un curseur PDO non bufferisé, fermé avant toute autre requête. Une transaction avec instantané cohérent couvre les tables transactionnelles ; les tables non transactionnelles ne bénéficient pas de cette garantie. Le délai SQL de session est désactivé uniquement pendant l'export puis restauré, ainsi que le mode de lecture PDO. Les lots INSERT sont bornés en nombre de lignes et en volume (environ 1 Mio, hors taille d'une ligne individuelle).

Toute erreur SQL, absence de structure ou écriture gzip incomplète fait échouer l'export. Le temporaire est supprimé et aucun nouveau fichier final ni créneau réussi n'est publié. Les sauvegardes antérieures restent disponibles. Les anciens temporaires abandonnés ne sont pas purgés par cette livraison.

## Planning et conservation

La commande `cron/backup_database.php --scheduled --retention_days=0` calcule en heure d'Abidjan le dernier créneau arrivé à échéance : lundi, mercredi ou vendredi à 02h00. Un verrou empêche les exports simultanés. Le fichier privé `.schedule-success.json` n'est enregistré qu'après un export complet. Les passages horaires suivants ne recréent pas le même créneau ; un échec sera retenté et un créneau manqué sera rattrapé au prochain passage. Il n'est pas possible de reconstituer les états historiques pendant une interruption.

La vérification horaire n'est donc pas une sauvegarde horaire : trois créneaux par semaine, sans date de fin. Une sauvegarde manuelle supplémentaire reste possible. La valeur 0 signifie conservation sans suppression automatique ; les sauvegardes manuelles prennent également 0 par défaut. Une durée positive reste une option explicite de rotation pour l'administrateur.

Cron recommandé :

```cron
0 * * * * /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/cron/backup_database.php --scheduled --retention_days=0 >> /home/fidestci/backup_database.log 2>&1
```

Remplacer uniquement la ou les lignes de ce script de sauvegarde FINEA, en gardant les autres tâches et en sauvegardant préalablement le crontab. L'interface décrit la commande mais ne modifie pas le crontab du serveur.

## Vérification

- `php -d memory_limit=32M tests/DatabaseBackupStreamingTest.php` : export de plus de 300 Mio de SQL avec mémoire bornée, délai SQL, fermeture des curseurs, gzip complet, échec sans publication, reprise, calendrier Abidjan, absence de doublon et conservation des archives.
- Ce test simule PDO MySQL ; il ne remplace pas l'exécution d'une sauvegarde réelle chez l'hébergeur et une restauration sur une base de test.
- Après déploiement, lancer la commande planifiée immédiatement, vérifier « Backup termine », le code de sortie 0 et la présence du fichier dans db_tools. Ne pas déclarer la reprise effective avant ce succès.
