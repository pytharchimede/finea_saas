# Reprise IT du module Facturation

## Positionnement

Le module Facturation couvre les factures d achat et de vente, avec un ancrage important sur les exigences FNE.

## Fichiers cles

- facturation/dashboard_facturation.php
- facturation/factures_achat.php
- facturation/edit_facture_achat.php
- facturation/download_facture_achat.php
- facturation/factures_vente.php
- facturation/edit_facture_vente.php
- model/FactureAchat.php
- model/FactureVente.php
- model/FneClient.php

## Dashboard

dashboard_facturation.php :

- agrège les stats achat et vente ;
- expose la configuration FNE ;
- calcule divers indicateurs ;
- prepare des vues de volumetrie et de qualite.

## Factures d achat

factures_achat.php gere :

- la creation et edition des factures d achat ;
- l upload de piece ;
- la validation basique des lignes ;
- la gestion des statuts ;
- la journalisation via AuditTrail.

## Dependances fortes

- referentiel fournisseurs ;
- configuration FNE ;
- taxes applicatives ;
- stockage des pieces jointes.

## Risques de reprise

- casser les uploads ;
- desynchroniser les statuts ;
- modifier les structures facture sans verifier les vues dashboard ;
- impacter FNE sans tests complets.

## Reprise recommandee

1. verifier dashboard_facturation ;
2. tester creation facture achat ;
3. tester upload piece ;
4. tester edition facture vente ;
5. verifier la configuration FNE.