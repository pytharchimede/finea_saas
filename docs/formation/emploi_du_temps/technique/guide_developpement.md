# Guide de developpement du module Emploi du temps

## But du guide

Ce guide aide a modifier le module Emploi du temps sans casser les dépendances avec RH, Communication, le planning projet et Microsoft To Do.

## Vue d ensemble

Le module est centré autour de trois blocs :

- le calendrier interne de l employé ;
- les règles horaires de planification ;
- les intégrations externes ou transverses.

Les intégrations transverses principales sont :

- Communication via comm_task_id ;
- RH via les fenêtres horaires ;
- Microsoft To Do via Graph ;
- planning projet via les tâches affectées.

## Modèle central : EmployeeSchedule

Le fichier model/EmployeeSchedule.php concentre la logique métier la plus critique.

Responsabilités majeures :

- assurer le schéma ;
- résoudre le personnel ;
- charger le contexte de planning ;
- lister les événements ;
- créer et modifier les créneaux ;
- détecter les chevauchements ;
- gérer les statuts ;
- gérer les fenêtres horaires ;
- calculer un créneau disponible ;
- exposer les tâches de planning projet.

Règle de maintenance : si la logique touche aux dates, aux fenêtres ou aux collisions, elle doit vivre ici plutôt que dans l écran ou dans les APIs.

## APIs à surveiller

### create_employee_schedule_event

Utilisée pour la création. Gère aussi la branche récurrence.

### update_employee_schedule_event

Utilisée pour les modifications générales et les déplacements du calendrier.

### set_employee_schedule_event_status

Utilisée pour les changements de statut rapides.

Conseil : garder ces APIs minces et déléguer les validations complexes au modèle.

## Schéma et colonnes sensibles

### employee_schedule_event

Colonnes sensibles :

- start_at ;
- end_at ;
- status_code ;
- recurrence_rule_id ;
- comm_task_id ;
- personnel_id.

Toute modification de ces colonnes peut affecter plusieurs comportements cachés.

### employee_schedule_work_window

Colonnes sensibles :

- scope_type ;
- scope_id ;
- weekday ;
- is_working_day ;
- start_minute ;
- end_minute.

Ces données sont utilisées par le moteur de placement et donc indirectement par Communication.

## Cycle de création d un créneau

Le flux recommandé reste :

1. résoudre le personnel ;
2. valider titre, début, fin ;
3. valider le statut ;
4. vérifier les collisions ;
5. enregistrer ;
6. journaliser.

En cas de nouvelle fonctionnalité, garder ce cycle inchangé autant que possible.

## Récurrence

Le support de récurrence existe via EmployeeScheduleRecurrence.

Si vous modifiez :

- le format des dates ;
- les règles de création ;
- la duplication des événements,

il faut tester à la fois les événements simples et les événements récurrents.

## Fenêtres horaires

Les work windows sont un point d architecture important.

Le moteur fait hériter les horaires selon :

1. personnel ;
2. service ;
3. défaut global codé.

Si vous changez cette logique, vous impactez :

- le placement automatique des tâches ;
- la cohérence RH ;
- la perception utilisateur du calendrier.

## Méthodes critiques

### planAvailableSlot

Calcule le meilleur créneau disponible pour une durée donnée avant une deadline.

### hasOverlap

Empêche les créneaux incompatibles.

### getWorkWindowForDate

Retourne la fenêtre active sur une date donnée.

### previousWorkSlot et nextWorkSlot

Utilisées pour reculer ou avancer dans la recherche de créneau.

Ces méthodes doivent être considérées comme un bloc cohérent. Les modifier séparément est risqué.

## Intégration Communication

La présence de comm_task_id implique un contrat implicite entre Emploi du temps et Communication.

Quand vous modifiez le cycle de création ou de suppression d événement :

- vérifiez le comportement de syncTaskSchedule dans Communication ;
- vérifiez deleteTaskSchedule ;
- vérifiez qu un événement lié à une tâche reste unique.

Le schéma possède d ailleurs une contrainte d unicité sur comm_task_id.

## Intégration Microsoft To Do

L écran principal charge et met à jour les tâches Microsoft.

Points techniques :

- stockage local des tokens ;
- refresh automatique si le token est expiré ;
- recherche de la liste par défaut ;
- changement de statut via Graph.

Si vous modifiez ce flux :

- testez le cas sans configuration ;
- testez le cas token expiré ;
- testez le cas refresh échoué ;
- testez le cas mise à jour du statut échouée.

## Ecran principal emploi_du_temps.php

Le fichier est volumineux et mélange :

- contrôles d action ;
- calculs de période ;
- exports ;
- chargements de données ;
- rendu HTML ;
- JavaScript de calendrier ;
- logique To Do.

Pour des évolutions significatives, il vaut mieux extraire progressivement des blocs plutôt que d ajouter encore de la logique inline.

## Modifications sûres

Les évolutions les moins risquées sont généralement :

- le wording des labels ;
- l amélioration du rendu visuel ;
- l ajout de documentation ;
- l enrichissement d exports sans toucher aux règles de calcul.

## Modifications sensibles

- changement du format attendu des dates dans les APIs ;
- modifications de hasOverlap ;
- modification de la hiérarchie des work windows ;
- changement des statuts autorisés ;
- modification de la résolution du personnel ;
- évolution du lien Communication ou Microsoft.

## Plan de test minimal avant livraison

1. ouvrir le planning d un utilisateur lié au personnel ;
2. créer un événement simple ;
3. déplacer l événement ;
4. modifier son statut ;
5. tester un chevauchement refusé ;
6. tester un export CSV ;
7. tester un export PDF ;
8. tester une tâche Communication planifiée ;
9. tester les horaires RH en cas de fenêtre spécifique ;
10. tester Microsoft To Do si activé.

## Ligne directrice

La règle principale est simple : toute évolution qui touche au temps, au personnel ou aux fenêtres horaires doit être pensée comme une évolution transverse, pas comme un simple changement local de calendrier.