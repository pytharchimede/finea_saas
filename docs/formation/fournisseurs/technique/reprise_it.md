# Reprise IT du module Fournisseurs

## Positionnement

Le module Fournisseurs constitue le referentiel maitre des fournisseurs pour l application. Il est relie a la facturation, notamment dans les usages FNE.

## Fichiers cles

- fournisseur/dashboard_fournisseur.php
- fournisseur/fournisseurs.php
- fournisseur/edit_fournisseur.php
- controllers/FournisseurController.php
- services/FournisseurService.php
- repositories/FournisseurRepository.php

## Fonctions visibles

dashboard_fournisseur.php :

- calcule des statistiques du referentiel ;
- affiche des alertes de qualite ;
- donne acces a la gestion des fiches.

fournisseurs.php :

- gere la creation de nouveaux fournisseurs ;
- liste les derniers enregistrements ;
- journalise la creation via AuditTrail.

## Donnees metier importantes

- raison sociale ;
- code fournisseur ;
- nom commercial ;
- NCC ;
- RNE ;
- indicateur is_rne ;
- contact et adresse ;
- statut actif.

## Dependances

- usage direct par la facturation ;
- logique de conformite FNE ;
- portail et navbar decaissement.

## Risques de reprise

- casser le modele fournisseur utilise par facturation ;
- modifier les regles NCC / RNE sans coordination ;
- autoriser des doublons sans garde-fou metier.

## Reprise recommandee

1. relire FournisseurRepository, FournisseurService et FournisseurController ;
2. tester creation ;
3. tester edition ;
4. verifier les stats dashboard ;
5. verifier l usage par facturation achat.
