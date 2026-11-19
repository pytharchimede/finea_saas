# Module Planning Chantier - FINEA

Module autonome à déposer dans :

```txt
/banamur_project_app/planning_chantier/
```

## Objectif

Suivre plusieurs chantiers en parallèle avec :

- avancement prévu ;
- avancement réel ;
- taux d'écart ;
- tâches en retard ;
- blocages ;
- actions correctives ;
- réunion quotidienne de 12h ;
- auto-création des tables et champs si inexistants.

## Installation

1. Copier le dossier `planning_chantier` à la racine du projet FINEA.
2. Ouvrir :

```txt
http://localhost/banamur_project_app/planning_chantier/dashboard.php
```

3. Les tables seront créées automatiquement.

## Entrées principales

```txt
dashboard.php      Vue DG globale multi-chantiers
reunion.php        Page réunion quotidienne de 12h
chantier.php?id=1  Détail d'un chantier
tasks.php          Liste des tâches
blockages.php      Liste des blocages
task_form.php      Création / modification tâche
```

## Architecture

```txt
model -> controllers -> services -> repositories -> database
```

## Intégration menu

Ajouter dans la navbar :

```php
<a href="/banamur_project_app/planning_chantier/dashboard.php">
    <i class="fas fa-calendar-check"></i>
    Planning Chantier
</a>
```

## Note importante

Le module essaie de se connecter à la base via plusieurs chemins FINEA connus :

- `../decaissement/model/Database.php`
- `../model/Database.php`
- `../config/database.php`

Si ton projet utilise un autre chemin, modifier uniquement `bootstrap.php`.


## V3 Réunion complète

Cette version ajoute :

- formulaire de points de réunion mieux réparti ;
- liste des réunions en accordéon dans une colonne compacte à droite ;
- correction des menus select-search qui passaient derrière les champs ;
- clôture de réunion avec résumé final ;
- participants de réunion ;
- génération du point de réunion en PDF/HTML ;
- tentative de notification système ;
- tentative d'envoi email aux participants avec pièce jointe si Mailer accepte les options d'attachement ;
- suppression directe d'une réunion depuis la liste compacte.



## V5 Correctif clôture

- Les participants sont facultatifs et ne bloquent plus la clôture.
- Les informations Nom/Email sont synchronisées depuis l'utilisateur sélectionné avant envoi.
- La clôture utilise une validation contrôlée sur le résumé et les décisions uniquement.


## V6 Export réunion

Cette version remplace l’ancien export simple par un point de réunion officiel au style proche de l’export logistique : en-tête BANAMUR, bandeau document, QR code, KPI cards, tableaux designés, participants et pied de page officiel.
