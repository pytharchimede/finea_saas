# Guide de developpement du module Facturation

## Nature du module

Le module Facturation combine UI, regles metier et dependances externes. Il faut donc eviter les modifications partielles non testees.

## Zones sensibles

- gestion des statuts achat et vente ;
- uploads de pieces ;
- calcul des lignes et montants ;
- devise et conversion ;
- integration FNE ;
- dependance fournisseur.

## Bonnes pratiques

- garder la logique metier dans les modeles ;
- tester achat et vente separement ;
- verifier l affichage dashboard apres toute modification de statut ;
- proteger le stockage des pieces jointes.

## Tests minimaux

1. creer une facture achat ;
2. uploader une piece ;
3. changer un statut ;
4. verifier le dashboard ;
5. creer ou editer une facture vente ;
6. verifier les informations FNE.