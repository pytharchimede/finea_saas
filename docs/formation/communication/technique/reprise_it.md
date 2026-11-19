# Reprise IT du module Communication

## Positionnement reel du module

Le nom historique du module est Communication, mais son usage actuel est celui d un gestionnaire de taches interne.

Dans l interface, le module est maintenant presente comme Mes tâches. Cette evolution est surtout une requalification fonctionnelle de l existant, pas une reecriture complete.

Le module couvre aujourd hui :

- creation et suivi de taches ;
- commentaires associes a une tache ;
- pieces jointes associees a une tache ;
- tableau de bord de synthese ;
- espace personnel de taches ;
- synchronisation partielle avec l emploi du temps.

## Fichiers principaux

### Ecrans

- communication/dashboard_communication.php
- communication/mes_taches_communication.php
- communication/taches_communication.php
- communication/messagerie_communication.php
- communication/pieces_jointes_communication.php

### APIs

- api/communication_state.php
- api/communication_tasks.php
- api/communication_send.php
- api/communication_messages.php
- api/communication_comment.php
- api/communication_attachments.php
- api/communication_attachment_delete.php
- api/communication_download.php

### Modele metier

- model/Communication.php

### Integration planning

- model/EmployeeSchedule.php
- emploi_du_temps.php

## Tables principales

Le module cree et maintient son schema via le modele Communication.

Tables principales :

- comm_tasks
- comm_task_comments
- comm_task_attachments
- comm_task_user_preferences

Le modele contient aussi une logique de nettoyage d anciens objets legacy devenus obsoletes.

## Table comm_tasks

Table centrale du module. Elle porte notamment :

- l identifiant de la tache ;
- l auteur de creation ;
- le destinataire principal ;
- le titre ;
- la description ;
- l echeance ;
- la duree estimee en minutes ;
- la priorite ;
- le statut ;
- les dates de completion et d archivage ;
- les horodatages standards.

Cette table est la source de verite fonctionnelle.

## Table comm_task_comments

Elle contient les commentaires lies a une tache.

Usages :

- conversation autour de la tache ;
- retour d avancement ;
- justification ;
- historique lisible par les utilisateurs.

Dans la pratique, elle joue aussi un role proche d un journal d activite metier.

## Table comm_task_attachments

Elle porte les metadonnees des fichiers joints :

- nom logique ;
- nom physique ;
- type mime ;
- taille ;
- chemin de stockage ;
- auteur du depot ;
- date de depot.

Le stockage reel se fait sur disque, sous :

```text
storage/communication_tasks/YYYYMM/
```

Le chemin en base n est donc qu une reference de localisation, pas le binaire lui-meme.

## Table comm_task_user_preferences

Elle sert aux preferences par utilisateur, notamment pour l organisation personnelle des taches et certains choix d affichage.

## Lien avec le planning

Le pont technique avec l emploi du temps se fait via employee_schedule_event.comm_task_id.

Cela permet de rattacher un evenement de planning a une tache issue du module Communication.

Le cycle general est le suivant :

1. creation ou mise a jour de tache ;
2. tentative de synchronisation avec le planning ;
3. creation, mise a jour ou suppression de l evenement associe ;
4. affichage eventuel dans emploi_du_temps.

## Condition critique de synchronisation

La synchronisation planning depend d une resolution correcte du destinataire vers un personnel planifiable.

Le mecanisme s appuie sur :

- l utilisateur dans user_devis_banamur ;
- son mail professionnel ;
- la resolution vers un enregistrement personnel ;
- la disponibilite d un creneau dans EmployeeSchedule.

Si le rattachement utilisateur vers personnel est incomplet, la tache peut exister sans etre correctement placee au planning.

## Flux API

### communication_state

Retourne l etat d initialisation du module, notamment :

- utilisateur courant ;
- liste des utilisateurs ;
- compteurs de dashboard ;
- statuts ;
- priorites.

### communication_tasks

Retourne la liste des taches et un resume associe.

### communication_messages

Retourne les commentaires d une tache.

### communication_send

Point d entree principal pour les actions metier. Ce fichier est central et gere plusieurs branches d action, par exemple :

- creation de tache ;
- update_task ;
- update_status ;
- toggle_pin ;
- save_task_order ;
- delete_task ;
- upload_attachments.

Ce point est donc le plus sensible en maintenance car il concentre beaucoup de comportements.

## Comportements importants a connaitre

### ensureTables

Le module initialise son schema a l execution. Il faut donc verifier cette logique avant toute migration manuelle pour eviter de se battre avec une auto-initialisation non prise en compte.

### cleanupLegacySchema

Une logique de nettoyage legacy est embarquee. Avant d intervenir sur d anciennes tables, verifier qu elles ne sont pas deja candidates au nettoyage automatique.

### syncTaskSchedule

Methode critique. Toute modification de la structure d une tache, du format de date ou de la resolution du destinataire peut casser la synchronisation planning.

### deleteTaskSchedule

Utilisee lors des suppressions ou de certains changements d etat. A surveiller en cas d incoherence entre taches et planning.

## Risques de maintenance

- modifier communication_send.php sans cartographier toutes les actions ;
- changer le schema comm_tasks sans verifier syncTaskSchedule ;
- toucher au mapping utilisateur vers personnel sans tester emploi_du_temps ;
- deplacer physiquement les fichiers joints sans migrer les chemins de stockage ;
- renommer le module en surface sans tenir compte du nom technique historique Communication.

## Strategie de reprise recommandee

1. lire model/Communication.php en entier ;
2. lire api/communication_send.php et relever toutes les actions ;
3. verifier les tables en base sur l environnement reel ;
4. tester un cycle complet : creer, commenter, joindre un fichier, changer le statut, verifier le planning ;
5. verifier les permissions et l affichage portail ;
6. documenter toute divergence entre base, code et usage utilisateur.

## Point de vigilance fonctionnel

Le module n est plus percu comme un simple outil de communication. Pour les utilisateurs, il sert de gestionnaire de taches. Les futures evolutions doivent donc suivre cette realite metier pour eviter un ecart entre nommage, UX et comportement.