# Reprise IT du module Emploi du temps

## Positionnement fonctionnel

Le module Emploi du temps est le calendrier opérationnel de l application. Il gère les créneaux personnels, certaines liaisons avec le planning de projet, et les synchronisations avec le module Mes tâches.

Il sert aussi de point de contact avec Microsoft To Do pour afficher et mettre à jour des tâches externes dans une vue unifiée.

## Fichiers principaux

### Ecran principal

- emploi_du_temps.php

### Modèles métier

- model/EmployeeSchedule.php
- model/EmployeeScheduleRecurrence.php
- model/MicrosoftToDo.php

### APIs locales de planning

- api/create_employee_schedule_event.php
- api/update_employee_schedule_event.php
- api/set_employee_schedule_event_status.php

### Paramétrage RH lié

- rh/parametrage_horaires_emploi_du_temps.php

## Table centrale de planning

Le stockage principal repose sur :

```text
employee_schedule_event
```

Champs importants :

- id ;
- personnel_id ;
- title ;
- description ;
- location ;
- start_at ;
- end_at ;
- status_code ;
- planning_task_id ;
- recurrence_rule_id ;
- comm_task_id ;
- created_by_user_id ;
- created_at ;
- updated_at.

## Table des fenêtres horaires

Le module introduit aussi :

```text
employee_schedule_work_window
```

Elle sert a stocker les fenêtres horaires de planification selon deux portées :

- service ;
- personnel.

Le système applique un héritage :

1. personnel ;
2. service ;
3. défaut global codé dans EmployeeSchedule.

## Initialisation automatique

EmployeeSchedule crée et adapte son schéma automatiquement via :

- ensureTables ;
- ensureColumns ;
- ensureIndexes ;
- ensureWorkWindowTables.

Avant toute migration manuelle, il faut donc tenir compte de cette auto-initialisation pour éviter des écarts entre code et base.

## Résolution du personnel

Le module ne travaille pas directement sur l utilisateur applicatif pour les créneaux. Il a besoin d un personnel réel.

La résolution se fait par :

- session emploi_du_temps_personnel_id si déjà fixée ;
- resolvePersonnelIdByUserId ;
- fallback resolvePersonnelIdByEmail.

Cette chaîne est importante car elle conditionne l ouverture correcte du planning, la création d événements et la synchronisation des tâches.

## Flux de création d un créneau

Le point d entrée principal est :

```text
api/create_employee_schedule_event.php
```

Le flux est le suivant :

1. auth_check et résolution du personnel ;
2. lecture du payload JSON ou POST ;
3. validation titre, dates et statut ;
4. création éventuelle d une règle de récurrence ;
5. appel a EmployeeSchedule::createEvent ;
6. audit ;
7. retour d un item compatible avec le calendrier frontend.

## Flux de mise à jour

Le point d entrée principal est :

```text
api/update_employee_schedule_event.php
```

Deux cas sont distingués :

- mise à jour complète avec titre/statut/description/lieu ;
- déplacement simple de dates avec conservation du reste.

Le contrôle de chevauchement est assuré côté modèle.

## Mise à jour du statut

Le point d entrée dédié est :

```text
api/set_employee_schedule_event_status.php
```

Il délègue à EmployeeSchedule::updateEventStatus après résolution du personnel et validation du code de statut.

## Logique de chevauchement

Le contrôle des conflits repose sur hasOverlap dans EmployeeSchedule.

Règle : un créneau ne peut pas recouvrir un autre créneau pour le même personnel, sauf si l identifiant courant est explicitement ignoré lors d une mise à jour.

## Fenêtres horaires et placement automatique

Le moteur de placement automatique s appuie sur :

- planAvailableSlot ;
- previousWorkSlot ;
- nextWorkSlot ;
- isInsideWorkWindow ;
- getWorkWindowForDate.

Il cherche d abord un créneau avant l échéance demandée, puis tente un repositionnement ultérieur si nécessaire.

## Lien avec le module Communication

Le lien se fait via :

```text
employee_schedule_event.comm_task_id
```

Cela permet a une tâche de Communication de devenir un événement de planning.

Implication importante : une modification du modèle Emploi du temps peut casser la planification automatique des tâches, même si le calendrier manuel continue de fonctionner.

## Lien avec le planning projet

Le module expose aussi des tâches de planning projet via listAssignedPlanningTasks.

Le calendrier n est donc pas strictement limité aux événements saisis à la main. Il peut aussi refléter des informations dérivées d autres briques de planification.

## Intégration Microsoft To Do

Le composant externe est géré par model/MicrosoftToDo.php.

Stockage local :

```text
user_microsoft_todo_token
```

Capacités :

- échange du code OAuth contre un token ;
- sauvegarde et purge des tokens ;
- refresh token ;
- lecture des tâches ;
- mise à jour du statut Microsoft.

Dépendances :

- config/microsoft_todo.php ;
- Microsoft Graph ;
- scopes offline_access, Tasks.Read, Tasks.ReadWrite, User.Read.

## Ecran principal

emploi_du_temps.php concentre plusieurs responsabilités :

- résolution du collaborateur courant ;
- calcul de la période affichée ;
- exports CSV et PDF ;
- callback OAuth Microsoft ;
- chargement des événements ;
- affichage du calendrier ;
- modal de tâches To Do ;
- lien vers le paramétrage RH des horaires.

Le fichier est donc fonctionnel mais dense. Toute maintenance doit être prudente.

## Risques principaux de reprise

- casser la résolution utilisateur vers personnel ;
- modifier le format des dates attendues par les APIs ;
- oublier l impact de comm_task_id sur Communication ;
- casser le moteur de work windows ;
- introduire des régressions sur les exports ;
- modifier le flux Microsoft sans prévoir les cas de token expiré.

## Stratégie de reprise recommandée

1. vérifier le schéma réel de employee_schedule_event et employee_schedule_work_window ;
2. tester création, déplacement, suppression et changement de statut ;
3. vérifier les fenêtres horaires RH ;
4. tester un cas Communication vers Emploi du temps ;
5. vérifier les exports PDF et CSV ;
6. tester le scénario Microsoft connecté et non connecté.