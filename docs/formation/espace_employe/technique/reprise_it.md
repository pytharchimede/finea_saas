# Reprise IT de l Espace employe

## Positionnement

L Espace employe est un module transversal centre sur l utilisateur connecte. Il ne porte pas une seule logique metier mais agrège plusieurs services :

- liaison au personnel RH ;
- demandes legales ou administratives ;
- pointage ;
- explications RH ;
- suivi FEB ;
- ouverture vers decaissement.

## Fichiers cles

- employe/dashboard_employe.php
- employe/actes_legaux_employe.php
- employe/nouvelle_demande_employe.php
- employe/mes_demandes_employe.php
- employe/mes_explications_employe.php
- employe/pointage_employe.php
- model/EmployeeLegalRequestManager.php
- model/FebValidationPolicy.php

## Fonctionnement visible

dashboard_employe.php :

- recolle l utilisateur connecte a un profil personnel via email ;
- affiche alertes d absences ;
- remonte les demandes recentes ;
- expose des KPI FEB ;
- ouvre des raccourcis vers RH et Decaissement.

## Actes legaux

actes_legaux_employe.php s appuie sur EmployeeLegalRequestManager pour lister les types de demandes disponibles et leur equipe de validation, typiquement RH ou Finance.

## Dependances critiques

- presence d un mail professionnel en session ;
- qualite du rattachement user / personnel ;
- disponibilite des tables EmployeeLegalRequestManager ;
- regles de validation finance dans FebValidationPolicy.

## Risques de reprise

- casser la liaison entre user et personnel ;
- changer les types de demandes sans aligner les validateurs ;
- perdre la coherence entre dashboard, demandes et suivi ;
- traiter le module comme un simple frontend alors qu il depend de plusieurs services transverses.

## Reprise recommandee

1. tester un utilisateur bien rattache et un non rattache ;
2. tester la creation d une demande RH ;
3. tester une FEB ;
4. verifier le suivi des statuts ;
5. verifier les acces rapides depuis le dashboard.