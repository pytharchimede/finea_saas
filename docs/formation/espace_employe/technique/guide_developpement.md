# Guide de developpement de l Espace employe

## Nature du module

L Espace employe est un orchestrateur de parcours utilisateur. Toute modification visible peut avoir des impacts RH, Finance ou Pointage.

## Regle principale

Ne pas faire d evolution locale sans verifier l ensemble du parcours employe :

- dashboard ;
- creation de demande ;
- suivi ;
- pointage ;
- explications.

## Zones sensibles

- resolution de l identite employee ;
- statuts des demandes ;
- types de demandes et equipes cibles ;
- KPI FEB ;
- liens vers les autres modules.

## Bonnes pratiques

- centraliser la logique de demande dans le manager metier ;
- garder le dashboard leger en traitements ;
- tester les etats de session absents ou incomplets ;
- ne pas dupliquer la logique de rattachement personnel.

## Tests minimaux

1. ouvrir le dashboard avec un compte standard ;
2. ouvrir le dashboard avec un compte sans profil RH ;
3. creer une demande RH ;
4. creer une demande FEB ;
5. verifier la liste Mes demandes ;
6. verifier Pointage et Mes explications.