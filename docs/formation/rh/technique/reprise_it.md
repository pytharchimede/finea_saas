# Reprise IT du module RH

## Positionnement

Le module RH est un ensemble large qui couvre plusieurs sous-domaines :

- annuaire du personnel ;
- intégration et dossier collaborateur ;
- sorties ;
- missions ;
- pointage ;
- paie ;
- demandes d explication et validations ;
- paramétrages métiers RH.

Le portail pointe vers rh/dashboard_rh.php et l accès est piloté par acces_rh.

## Pages principales

Les entrées les plus visibles sont :

- rh/dashboard_rh.php
- rh/liste_personnel_rh.php
- rh/integration_personnel_rh.php
- rh/dossier_personnel_rh.php
- rh/sortie_personnel_rh.php
- rh/ordres_mission_rh.php
- rh/ordre_mission_rh.php
- rh/pointage_journalier_rh.php
- rh/pointage_mensuel_personnel_rh.php
- rh/paie_rh.php
- rh/validation_demandes_rh.php
- rh/parametrage_rh.php
- rh/parametrage_horaires_emploi_du_temps.php

## Caractéristiques techniques

Le module est fortement découpé par pages métier, avec une permission homogène acces_rh sur la majorité des écrans. Certaines fonctions RH interagissent avec :

- le module Employé ;
- l audit ERP ;
- le module Emploi du temps ;
- les exports PDF/Excel ;
- la notification interne.

## Paramétrage RH

parametrage_rh.php centralise plusieurs référentiels. C est l un des écrans structurants du module car il conditionne les listes déroulantes et règles utilisées ailleurs.

Le paramétrage couvre notamment :

- services ;
- fonctions ;
- statuts ;
- sites ;
- discipline ;
- hiérarchie ;
- horaires emploi du temps.

## Lien avec Emploi du temps

Le fichier rh/parametrage_horaires_emploi_du_temps.php est désormais la page dédiée aux fenêtres horaires de planification.

Elle exploite EmployeeSchedule pour :

- charger le contexte personnel/service ;
- sauver les work windows par service ;
- sauver les work windows par employé ;
- réinitialiser les surcharges ;
- tracer les modifications.

## Audit et traçabilité

Le module RH est déjà branché à AuditTrail sur plusieurs actions. Les écrans sensibles de type dashboard, paramétrage, sortie ou validation doivent être considérés comme des surfaces auditées.

## Permissions

Le socle d accès repose principalement sur acces_rh, avec ponctuellement des interactions vers d autres permissions selon les exports ou modules liés.

## Risques de reprise

- modifier un référentiel RH sans mesurer l impact sur toutes les pages ;
- changer la structure du personnel sans vérifier les dépendances Emploi du temps ;
- faire évoluer le pointage ou la paie sans campagne de test métier ;
- casser les liens de navigation internes depuis le dashboard RH.

## Reprise recommandée

1. partir de rh/dashboard_rh.php pour cartographier les flux ;
2. relever les tables/référentiels utilisés par paramétrage_rh.php ;
3. tester intégration, modification et sortie d un collaborateur ;
4. tester pointage, mission et validation RH ;
5. vérifier les exports et l audit.