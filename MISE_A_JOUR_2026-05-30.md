# Mise a jour du 2026-05-30

## Objectif

Cette mise a jour corrige l'affichage limite a 50 pointages, ajoute un suivi de l'heure observee sur les badgeuses par rapport a GMT+0, et ajoute un export Excel/CSV des pointages sur des periodes definies en GMT+0.

## Changements appliques

- L'API `logs` n'est plus limitee en permanence a 50 lignes.
- Les pointages peuvent maintenant etre filtres par periode GMT+0 avec `from` et `to`.
- Les pointages peuvent etre filtres par numero de serie de badgeuse avec `device_sn`.
- L'API peut retourner tous les pointages avec `all=1`.
- L'interface affiche maintenant les pointages avec deux niveaux d'heure :
  - `received_at` : heure GMT+0 de reception serveur.
  - `punch_time` / `device_punch_time` : heure brute transmise par la badgeuse, conservee pour audit.
- A partir de cette mise a jour, FINEA utilise `received_at` comme heure-date officielle du pointage RH quand cette valeur est disponible.
- L'heure de la badgeuse n'est plus utilisee comme heure officielle de presence, ce qui evite les pertes ou classements errones quand la badgeuse n'est pas a l'heure.
- Ajout d'un export direct `logs-export` en CSV compatible Excel.
- Ajout d'un tableau de suivi "Heure badgeuse vs GMT+0".
- Ajout d'un statut de connexion badgeuse :
  - `online` si la badgeuse a contacte le serveur recemment.
  - `offline` si aucun contact recent n'est recu.
  - `never_seen` si aucune date de contact n'est connue.
- Ajout d'un statut d'horloge badgeuse :
  - `observed` si un pointage badgeuse a ete recu.
  - `unknown` si aucune heure badgeuse n'a encore ete observee.
- Correction : l'application ne declare plus une badgeuse "pas a jour" uniquement a partir de l'heure d'un pointage, car un pointage peut etre collecte plus tard que son heure reelle.
- Le tableau affiche maintenant un delai de collecte indicatif entre le dernier pointage badgeuse et sa reception GMT+0, sans le traiter comme une preuve de derive de l'horloge.
- Correction de la source horaire serveur : l'API et `/iclock` utilisent maintenant l'heure GMT+0 PHP applicative pour les nouvelles receptions, afin de ne plus dependre de `UTC_TIMESTAMP()` MySQL.
- La route `health` retourne maintenant `time_sources.php_gmt`, `time_sources.mysql_utc` et leur ecart en secondes pour diagnostiquer un decalage serveur/base.
- Les dates de reception futures deja presentes en base sont neutralisees dans le tableau des badgeuses : l'affichage ne les laisse plus depasser l'heure GMT+0 applicative actuelle.
- Les dernieres valeurs d'heure badgeuse observee et de delai de collecte sont visibles dans la gestion des terminaux.
- Correction de la detection des badgeuses : la route `devices` ne depend plus uniquement de `attendance_devices`.
- Une badgeuse est maintenant affichee si elle existe dans les pointages, les evenements bruts ou les commandes, meme si la table `attendance_devices` n'a pas encore de ligne pour elle.

## Nouveaux champs base de donnees

Table `attendance_devices` :

- `last_device_time_at` : derniere heure transmise par la badgeuse.
- `last_device_time_seen_at` : heure GMT+0 serveur a laquelle cette heure badgeuse a ete observee.
- `clock_drift_seconds` : reserve pour une future lecture directe de l'horloge interne de la badgeuse. Non utilise pour alerter a partir des pointages.

Ces colonnes sont ajoutees automatiquement par `config/ensure_tables.php`.

Table `attendance_logs` :

- `received_at` : heure GMT+0 serveur au moment de la reception.
- `punch_time` : heure brute envoyee par la badgeuse dans la ligne `ATTLOG`.
- `device_punch_time` : alias optionnel de l'heure brute badgeuse quand l'API conserve les deux noms.
- FINEA transforme ces lignes en evenements internes avec `occurred_at = received_at` pour le pointage RH.

## Nouveaux parametres `.env`

```env
ATTENDANCE_DEVICE_OFFLINE_AFTER_MINUTES=5
ATTENDANCE_DEVICE_CLOCK_DRIFT_ALERT_SECONDS=300
ATTENDANCE_SERVER_TIME_DRIFT_ALERT_SECONDS=30
```

- `ATTENDANCE_DEVICE_OFFLINE_AFTER_MINUTES` definit apres combien de minutes sans contact une badgeuse est consideree deconnectee.
- `ATTENDANCE_DEVICE_CLOCK_DRIFT_ALERT_SECONDS` est reserve a une future lecture directe fiable de l'horloge interne badgeuse.
- `ATTENDANCE_SERVER_TIME_DRIFT_ALERT_SECONDS` definit l'ecart maximum autorise entre l'heure PHP GMT+0 applicative et l'heure UTC MySQL avant alerte.

## API

### Detection des badgeuses

La route suivante reconstruit maintenant la liste des badgeuses depuis plusieurs sources :

```http
GET /api/attendance/index.php?route=devices
```

Sources utilisees :

- `attendance_devices`
- `attendance_logs`
- `attendance_raw_events`
- `attendance_device_commands`

### Lire les pointages avec filtres GMT+0

```http
GET /api/attendance/index.php?route=logs&from=2026-05-01T00:00&to=2026-05-30T23:59&limit=500
```

### Lire tous les pointages collectes

```http
GET /api/attendance/index.php?route=logs&all=1
```

### Export Excel/CSV sur periode GMT+0

```http
GET /api/attendance/index.php?route=logs-export&from=2026-05-01T00:00&to=2026-05-30T23:59
```

### Export complet depuis la mise en route

```http
GET /api/attendance/index.php?route=logs-export&all=1
```

## Note importante sur l'heure "temps reel" de la badgeuse

Le protocole ADMS actuellement implemente ne renvoie pas automatiquement l'heure affichee par la badgeuse a chaque heartbeat `/iclock/getrequest`. L'application conserve donc la meilleure information disponible : la derniere heure transmise dans les pointages `ATTLOG` et l'heure GMT+0 du serveur au moment de la reception.

Cela permet de detecter une badgeuse deconnectee et d'auditer les delais de collecte. La derive horaire ne doit pas etre declaree automatiquement a partir d'un pointage seul. Si un modele ZKTeco expose une commande permettant de lire l'horloge interne en direct, elle pourra etre ajoutee ensuite pour produire une alerte horaire fiable.
