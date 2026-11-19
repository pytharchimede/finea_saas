# Guide de developpement du module Emails

## Regle principale

Le module Emails manipule des credentiels, des appels IMAP et des contenus potentiellement sensibles. Toute evolution doit privilegier la prudence.

## Zones sensibles

- gestion du mot de passe de boite ;
- session utilisateur ;
- requetes IMAP ;
- telechargement des pieces jointes ;
- creation de dossier ;
- journalisation.

## Bonnes pratiques

- conserver une separation claire entre boite utilisateur et journaux systeme ;
- tester les erreurs de connexion ;
- nettoyer les entrees dossier ;
- limiter les chargements massifs ;
- proteger les pieces jointes telechargees.

## Tests minimaux

1. connexion reussie ;
2. connexion echouee ;
3. lecture inbox ;
4. filtre non lus ;
5. ouverture d un message ;
6. telechargement d une piece jointe ;
7. redaction ou ouverture de compose.