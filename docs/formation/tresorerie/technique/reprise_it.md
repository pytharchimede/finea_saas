# Reprise IT de l Espace tresorerie

## Positionnement

Le module portail Espace tresorerie pointe vers decaissement/liste_fiches_compta.php avec la permission acces_point_financier.

Il s agit d un ecran de travail operateur pour les fiches cote compta / tresorerie, distinct du tableau analytique point_financier.php.

## Fichier cle

- decaissement/liste_fiches_compta.php

## Comportement visible

La page propose :

- recherche multi-criteres ;
- filtres de statut ;
- vues immediat, reporte, parent partiel, decaissee ;
- UI riche avec modales et feuille de bas de page.

## Difference avec Point financier

- liste_fiches_compta.php : outil de traitement et recherche detaillee ;
- point_financier.php : outil de synthese, KPI et analyse globale.

## Audit

La consultation est tracee via AuditTrail avec l action liste_fiches_compta_view.

## Risques de reprise

- melanger les regles de ce module avec celles du Point financier ;
- casser les filtres de recherche sans tester les cas courants ;
- oublier la distinction entre vues statut et vues analytiques.

## Reprise recommandee

1. tester les recherches par numero, beneficiaire, chantier ;
2. tester le filtre status_filter ;
3. verifier l audit ;
4. verifier les modales et interactions front.