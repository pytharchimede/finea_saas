# Guide de developpement du module Communication

## But de ce guide

Ce document sert de base de travail pour un developpeur qui doit faire evoluer le module Communication, devenu fonctionnellement un module Mes tâches.

Il explique :

- la structure generale ;
- les points d entree ;
- les tables ;
- les dependances critiques ;
- les zones de modification les plus sures ;
- les pieges a eviter.

## Architecture generale

Le module suit une organisation PHP classique :

- pages d interface dans le dossier communication ;
- logique metier centrale dans model/Communication.php ;
- endpoints AJAX dans api ;
- stockage des fichiers sur disque ;
- synchronisation planning via model/EmployeeSchedule.php.

## Responsabilites par couche

### Pages communication

Les pages servent surtout a :

- charger le contexte utilisateur ;
- preparer la structure HTML ;
- injecter les donnees initiales ;
- connecter les appels API ;
- afficher les vues dashboard, liste, commentaires et pieces jointes.

Ces pages ne doivent pas devenir le lieu principal des regles metier.

### model/Communication.php

C est le coeur metier du module. Il gere notamment :

- ensureTables ;
- getStatuses ;
- getPriorities ;
- listUsers ;
- getTaskDashboard ;
- listTasks ;
- listComments ;
- listAttachments ;
- createTask ;
- updateTask ;
- deleteTask ;
- updateTaskStatus ;
- updateUserTaskPreference ;
- saveUserTaskOrdering ;
- addComment ;
- storeAttachments ;
- getAttachment ;
- deleteAttachment ;
- syncTaskSchedule ;
- deleteTaskSchedule.

Si une regle touche au cycle de vie d une tache, elle doit idealement vivre ici.

### APIs

Les APIs servent de couche d orchestration entre frontend et modele.

Le fichier le plus important est :

```text
api/communication_send.php
```

Il concentre les mutations. Avant toute modification, il faut lister toutes les actions supportees pour verifier qu une regression ne casse pas un flux secondaire.

## Schema metier

### comm_tasks

Table maitre.

Champs metier importants :

- assignee_user_id ;
- created_by_user_id ;
- title ;
- description ;
- deadline ;
- duration_minutes ;
- priority ;
- status ;
- completed_at ;
- archived_at.

Implications :

- toute evolution du statut impacte le dashboard, la liste et potentiellement le planning ;
- toute evolution des dates impacte la synchronisation EmployeeSchedule ;
- toute evolution du destinataire impacte la resolution personnel.

### comm_task_comments

Table simple, mais importante pour la traçabilite utilisateur.

Si vous ajoutez des types de commentaires systeme, faites-le de maniere explicite pour distinguer :

- commentaire utilisateur ;
- evenement automatique ;
- journal systeme.

### comm_task_attachments

Table de metadonnees de fichiers.

Le binaire est stocke sur disque. Toute refonte doit prendre en compte :

- la securite du chemin ;
- le controle des droits de telechargement ;
- la suppression propre du fichier physique ;
- la compatibilite avec l historique existant.

### comm_task_user_preferences

Table utile pour les preferences personnelles et l ordre d affichage. A ne pas confondre avec la logique metier des taches elles-memes.

## Synchronisation planning

La partie la plus delicate du module est sa jonction avec l emploi du temps.

### Pont technique

Le lien se fait par :

```text
employee_schedule_event.comm_task_id
```

### Resolution du personnel

Pour planifier une tache, le systeme doit retrouver le personnel reel correspondant a l utilisateur destinataire.

Cette resolution passe par :

- le compte utilisateur ;
- le mail professionnel ;
- la correspondance vers le personnel ;
- la recherche d un creneau disponible.

### Consequence

Une tache peut etre fonctionnelle dans le module sans etre planifiable correctement. Il faut donc toujours distinguer :

- succes de creation de la tache ;
- succes de synchronisation planning.

## Workflow de modification recommande

### Ajouter un champ de tache

1. ajouter le champ dans ensureTables ou via migration maitrisee ;
2. le prendre en compte dans createTask et updateTask ;
3. l exposer dans communication_tasks si necessaire ;
4. l afficher dans les pages ;
5. tester le comportement du dashboard ;
6. verifier l impact sur syncTaskSchedule.

### Ajouter une nouvelle action utilisateur

1. choisir si l action est purement frontend ou metier ;
2. si mutation, l ajouter dans communication_send.php ;
3. deleguer le traitement au modele Communication ;
4. journaliser si l action est importante ;
5. tester les droits et les cas d erreur.

### Modifier les statuts

Avant toute modification :

- relever les valeurs actuelles renvoyees par getStatuses ;
- verifier les filtres frontend ;
- verifier updateTaskStatus ;
- verifier les effets sur le dashboard et le planning ;
- verifier la compatibilite des donnees existantes en base.

## Stockage des pieces jointes

Le module stocke les fichiers sous un dossier date :

```text
storage/communication_tasks/YYYYMM/
```

Bonnes pratiques :

- ne jamais supposer que le nom original est le nom physique ;
- conserver les metadonnees de taille et type mime ;
- tester suppression logique et suppression physique ;
- verifier les droits de telechargement dans l endpoint dedie.

## Permissions et presentation

Le nom visible du module a ete migre vers Mes tâches, mais le socle technique reste base sur Communication.

Cela implique que :

- les permissions restent autour de acces_communication ;
- les routes gardent le prefixe communication ;
- le portail utilisateur est pilote par PortalModuleManager ;
- l acces reel aux pages reste controle par AccessManager.

Si vous renommez davantage, faites-le de facon coherente sur les trois plans :

- presentation portail ;
- navigation ;
- noms techniques internes.

## Zones sures pour evoluer

Les evolutions les moins risquées sont en general :

- le wording des ecrans ;
- la presentation des cartes et listes ;
- les compteurs du dashboard si les sources SQL restent identiques ;
- l ajout de documentation ;
- l ajout de validations frontend non bloquantes.

## Zones sensibles

- communication_send.php ;
- syncTaskSchedule ;
- deleteTaskSchedule ;
- schema comm_tasks ;
- telechargement et suppression des pieces jointes ;
- resolution utilisateur vers personnel.

## Tests minimaux avant livraison

Pour toute modification significative, tester au minimum :

1. creation d une tache ;
2. edition de la tache ;
3. ajout d un commentaire ;
4. ajout d une piece jointe ;
5. telechargement de la piece jointe ;
6. changement de statut ;
7. verification de l effet dans emploi_du_temps ;
8. suppression ou annulation si la fonctionnalite touche ce flux.

## Ligne directrice

La meilleure maniere de faire evoluer ce module est de garder la logique dans model/Communication.php, de laisser les endpoints API minces, et de tester systematiquement la branche planning a chaque modification qui touche au destinataire, au temps ou au statut.