# Reprise IT de la presentation generale {{APP_NAME}}

## Positionnement

Ce module de presentation n est pas un module metier. C est le point d entree pedagogique qui explique :

- ce qu est {{APP_NAME}} ;
- pourquoi l application est strategique ;
- comment lire son ecosysteme de modules ;
- pourquoi la maitrise utilisateur est un enjeu de performance.

## Objectif technique du module

Dans le centre Formation, ce module doit rester :

- lisible ;
- stable ;
- simple a maintenir ;
- pertinent pour un onboarding rapide.

## Usage attendu

Ce module doit etre le premier lu par un nouvel arrivant ou par un collaborateur qui decouvre l ERP.

Il fournit le contexte avant les guides detaillés par domaine.

## Contraintes de reprise

- garder un discours transversal ;
- ne pas dupliquer toute la documentation detaillee ici ;
- conserver un ton d introduction et non de specification ;
- aligner le nom applicatif sur {{APP_NAME}}.

## Branding a conserver

La narration de marque associe {{APP_NAME}} a la finance, a l innovation et a la fluidite.

Slogan principal retenu : {{APP_SLOGAN}}

Le discours logo doit rester stable autour de quatre idees :

- pilotage par la donnee ;
- validation et fiabilite ;
- fluidite des processus ;
- image moderne et premium.

## Preparation a l enrichissement capture

Le module Formation supporte maintenant un mode capture reserve aux profils techniques, avec un contexte collaborateur persistant dans la navigation.

## Capacites a reprendre dans l etat actuel

La reprise IT doit tenir compte du fait que le centre Formation n est plus une simple page de lecture.

Le module gere maintenant :

- un catalogue de modules construit depuis les dossiers docs/formation ;
- deux niveaux d audience, utilisateur et technique ;
- un document courant avec rendu Markdown integre ;
- un export PDF du document courant ;
- un export PDF de tout le module courant ;
- un acces direct a la source Markdown du guide ;
- un mode capture avec collaborateur de reference persistant ;
- un upload image vers le dossier captures du guide ;
- une insertion automatique du bloc image dans le Markdown ;
- des notes de capture contextuelles selon module, audience et document.

## Points sensibles de maintenance

- ne pas rompre les chemins relatifs des images ;
- ne pas casser la resolution des placeholders applicatifs ;
- verifier les droits entre acces formation simple et acces technique ;
- conserver un rendu stable entre apercu HTML, Markdown source et export PDF ;
- surveiller les droits d ecriture sur les fichiers .md et sur les dossiers captures.

## Verifications de reprise recommandees

1. verifier la decouverte automatique des modules et documents ;
2. verifier la lecture utilisateur et la lecture technique ;
3. verifier Voir le Markdown sur le document courant ;
4. verifier l export PDF d un guide ;
5. verifier l export PDF de tout un module ;
6. verifier l upload d une image valide ;
7. verifier l insertion du snippet en fin de guide ;
8. verifier l insertion autour d un titre Markdown ;
9. verifier l insertion apres un repere texte ;
10. verifier la conservation du contexte collaborateur dans les URLs.

## Resultat attendu

Apres reprise, le centre Formation doit rester un espace autonome de transmission de connaissance, capable a la fois de diffuser des guides utilisateurs et de supporter un enrichissement technique progressif directement depuis l interface.