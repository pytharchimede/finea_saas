# Guide de developpement du module Fournisseurs

## Principe general

Le module Fournisseurs est un referentiel. Toute evolution doit proteger la coherence et la reutilisabilite des fiches en aval.

## Regles importantes

- ne pas contourner le modele fournisseur ;
- proteger les champs structurants ;
- tester les impacts sur la facturation ;
- eviter toute logique metier dupliquee entre dashboard et CRUD.

## Zones sensibles

- unicite fonctionnelle des fournisseurs ;
- logique NCC / RNE ;
- statut actif ;
- affichage des alertes de qualite ;
- audit des creations/modifications.

## Tests minimaux

1. creer un fournisseur valide ;
2. creer un fournisseur avec donnees partielles ;
3. modifier un fournisseur ;
4. verifier le dashboard ;
5. verifier le lien avec factures_achat.php.