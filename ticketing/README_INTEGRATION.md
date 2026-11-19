# Module Ticketing FINEA — Centre de Services

Module PHP/MySQL intégré à FINEA avec architecture :

- `models/`
- `controllers/`
- `services/`
- `repositories/`
- `database/`
- `views/`
- `assets/`

## Installation rapide

1. Copier le dossier `ticketing/` dans :

```text
/banamur_project_app/ticketing/
```

2. Vérifier que la classe `Database` existante de FINEA est disponible dans l’un de ces emplacements :

```text
/banamur_project_app/Database.php
/banamur_project_app/model/Database.php
/banamur_project_app/config/Database.php
/banamur_project_app/database/Database.php
```

Le module ne crée plus de connexion indépendante. Il réutilise automatiquement :

```text
.env
EnvConfig.php
Database::getConnection()
```

3. Ouvrir dans le navigateur :

```text
http://localhost/banamur_project_app/ticketing/index.php
```

Au premier chargement, les tables sont créées automatiquement si elles n'existent pas.

## Tables créées automatiquement

- `finea_ticket_categories`
- `finea_ticket_priorities`
- `finea_tickets`
- `finea_ticket_messages`
- `finea_ticket_attachments`
- `finea_ticket_status_history`
- `finea_ticket_watchers`

## Intégration session FINEA

Le module lit automatiquement les variables session suivantes si elles existent :

```php
$_SESSION['user_id']
$_SESSION['nom']
$_SESSION['prenom']
$_SESSION['service']
$_SESSION['role']
```

Sinon, il utilise un utilisateur de démonstration.

## URL principales

- Tableau de bord : `index.php`
- Nouveau ticket : `index.php?action=create`
- Détail ticket : `index.php?action=show&id=1`
- Tickets reçus : `index.php?action=received`
- Tickets du service : `index.php?action=service`
- Administration : `index.php?action=admin`


## Intégration complète FINEA ajoutée

Le dossier `integration_files/` du zip contient les fichiers déjà adaptés pour :

- `navbar.php` : entrée Tickets dans le menu principal.
- `PortalModuleManager.php` : module `module_ticketing` dans la liste des modules du portail.
- `AccessManager.php` : protection `ticketing/index.php` par `acces_ticketing` ou `acces_admin`.
- `edit_user.php` : case à cocher `Centre de services / Tickets`.
- `gestion_acces.php` : badge visuel Tickets dans la liste des utilisateurs.
- `NotificationManager.php` et `NotificationService.php` : équipe `ticketing`, règles et notifications système pour création, affectation, commentaire et changement de statut.

La migration du module ajoute automatiquement la colonne suivante si elle n'existe pas :

```sql
ALTER TABLE user_devis_banamur ADD COLUMN acces_ticketing TINYINT(1) NOT NULL DEFAULT 0;
```

Les comptes admin ou gestionnaires utilisateurs reçoivent automatiquement `acces_ticketing = 1` à la première ouverture du module.


## Connexion BDD

La connexion MySQL est centralisée dans :

```text
ticketing/database/Connection.php
```

Ce fichier est seulement un adaptateur. Il cherche la classe globale `Database`, puis appelle :

```php
Database::getConnection();
```

Donc les paramètres suivants restent uniquement dans ton `.env` existant :

```env
PROJECT_DB_HOST=...
PROJECT_DB_NAME=...
PROJECT_DB_USER=...
PROJECT_DB_PASS=...
```

Le fichier `ticketing/config/database.php` est conservé seulement pour compatibilité et ne doit plus être renseigné.


## Version v4 — Alignement design FINEA / Logistique

Cette version reprend les codes visuels du module Logistique :

- fond dégradé clair type FINEA ;
- hero sombre bleu `#1d2b57` avec accent jaune `#fabd02` ;
- cartes KPI arrondies ;
- tableaux espacés avec lignes en cartes ;
- formulaires arrondis et cohérents ;
- menu interne déplacé dans un `aside` latéral comme le module Logistique ;
- intégration automatique avec `decaissement/includes/header.php`, `navbar.php` et `footer.php` si présents.

Le module conserve la même architecture `Models / Controllers / Services / Repositories / Database` et continue d'utiliser `Database::getConnection()` de FINEA.

### Bordereau PDF, réception papier et délai de rendu

Dans le détail du ticket, « Exporter en PDF » télécharge le bordereau Banamur / FINEA et « Imprimer pour signature » ouvre le même PDF pour impression (le bouton d’impression du lecteur PDF reste utilisable si celui-ci bloque l’ouverture automatique).

Le document comprend les informations du ticket, la signature du profil de l’émetteur et une case manuscrite par exécutant. Une affectation nominative utilise la personne assignée ; une affectation au service utilise ses utilisateurs actifs, par correspondance exacte du service. Les personnes en suivi ne sont pas assimilées aux exécutants. Pour les nouveaux tickets, la signature est enregistrée avec le ticket à sa création. Les anciens tickets utilisent le profil actuel, explicitement indiqué dans le PDF ; aucune signature n’est inventée si le profil n’en contient pas.

Les exécutants ainsi que le responsable direct et le chef de département configurés dans `rh_service_hierarchy` peuvent confirmer la date et l’heure de rendu. Cette confirmation remplace l’échéance indicative SLA et est historisée. Elle est demandée avant la prise en charge, le passage en cours ou la clôture ; une réaffectation impose une nouvelle confirmation. Les responsables peuvent accéder aux tickets de leur service via « Tous mes tickets accessibles ».

Les bordereaux signés sont déposés séparément des pièces ordinaires (PDF/JPG/PNG, 12 Mo maximum), avec l’auteur et la date de dépôt. Ils sont conservés dans `ticketing/private/receptions`, protégé par son `.htaccess`, et servis uniquement après vérification de l’accès au ticket. Sous Nginx, interdire également l’accès HTTP direct à `/ticketing/private/`. Le dépôt ne certifie pas automatiquement l’authenticité ni la présence de chaque signature manuscrite.

La migration s’exécute automatiquement via `Migration::run()` : aucun SQL manuel n’est nécessaire. Le compte SQL de l’application doit disposer des droits de migration habituels et PHP doit pouvoir écrire dans le répertoire des preuves.

Vérification locale : `php tests/TicketReceptionTest.php` (dates, permissions, génération et pagination PDF, signature de test, refus d’un fichier absent). Les PDF de démonstration sont écrits dans `/tmp`.
