# Guide de developpement de l Espace tresorerie

## Nature du module

L Espace tresorerie est un ecran metier d investigation et de traitement. Il faut privilegier la lisibilite operative et la fiabilite des filtres.

## Regles de prudence

- ne pas confondre ce module avec Point financier ;
- garder la compatibilite avec les recherches existantes ;
- tester les statuts visibles ;
- verifier les interactions JS apres toute modification UI.

## Zones sensibles

- formulaires de filtre ;
- logique de statut ;
- modales ;
- pagination et chargements dynamiques eventuels ;
- audit de consultation.

## Tests minimaux

1. filtrer par numero de fiche ;
2. filtrer par beneficiaire ;
3. filtrer par chantier ;
4. changer le filtre statut ;
5. verifier l ouverture des actions detaillees ;
6. verifier la consultation en production-like avec un volume realiste.