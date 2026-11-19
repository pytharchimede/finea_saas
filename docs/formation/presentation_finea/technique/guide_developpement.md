# Guide de developpement du module Presentation {{APP_NAME}}

## Role du module

Ce module sert d introduction applicative dans Formation. Il doit rester en tete de lecture et fournir une base commune de compréhension.

## Regles de maintenance

- garder ce module en premier dans l ordre d affichage ;
- conserver un contenu transversal ;
- mettre a jour le nom applicatif s il evolue ;
- eviter d en faire une copie des guides metiers.

## Identite de marque

Ce module porte la couche narrative de marque de {{APP_NAME}}. Toute modification doit respecter trois axes :

- clarte metier ;
- image moderne et premium ;
- lien direct entre maitrise de l outil et performance operationnelle.

## Parametrage capture

Formation supporte maintenant un contexte collaborateur et un mode enrichissement captures.

Cela permet de preparer une documentation visuelle contextualisee sans casser la navigation entre modules et documents.

## Fonctionnalites techniques maintenant disponibles

Le centre Formation couvre desormais plusieurs capacites qui doivent etre connues avant toute evolution :

- chargement automatique des modules a partir de docs/formation ;
- separation des documents par audience utilisateur et technique ;
- conservation du contexte collaborateur dans la navigation du mode capture ;
- export PDF du guide courant ;
- export PDF de tout le module courant ;
- ouverture du Markdown source depuis l interface ;
- rechargement rapide de l apercu du guide ;
- upload direct d une image dans le dossier captures du guide ;
- generation automatique d un bloc Markdown image ;
- insertion automatique de ce bloc en fin de guide, avant un titre, apres un titre ou apres un repere texte.

## Placeholders supportes dans les guides

Les guides Markdown peuvent maintenant utiliser des placeholders qui sont resolves avant affichage et avant export.

Placeholders disponibles :

- {{APP_NAME}} ;
- {{COLLABORATEUR}} ;
- {{COLLABORATEUR_EMAIL}} ;
- {{APP_SLOGAN}}.

Cela permet d avoir une documentation unique qui s adapte au contexte de capture sans dupliquer plusieurs versions du meme guide.

## Cycle de travail capture actuel

Quand le mode capture est actif pour un profil technique autorise, le flux standard est le suivant :

1. choisir le module, l audience et le document ;
2. renseigner le collaborateur et son email de reference ;
3. uploader l image dans le dossier captures du guide ;
4. verifier l apercu immediat ;
5. generer ou ajuster le bloc Markdown ;
6. l inserer automatiquement au bon endroit dans le guide.

Le systeme conserve aussi un message flash de retour pour confirmer l upload, l insertion ou signaler un probleme d ecriture.

## Tests minimaux

1. verifier que Presentation {{APP_NAME}} apparait en premier ;
2. verifier l ouverture des 4 documents ;
3. verifier la conservation du parametre collaborateur ;
4. verifier le mode capture sur la navigation et le bouton Voir le Markdown ;
5. verifier l export PDF du guide courant ;
6. verifier l export PDF du module courant ;
7. verifier l upload d image dans le dossier captures ;
8. verifier l insertion automatique du bloc Markdown dans le guide cible.

## Regles de maintenance importantes

- ne pas casser la decouverte automatique des dossiers modules et audiences ;
- conserver des noms de fichiers .md simples et stables ;
- ne pas introduire de chemins image absolus hors projet ;
- s assurer qu un guide reste exportable en PDF apres toute modification ;
- verifier les permissions avant d etendre des actions reservees au mode capture.

## Convention image recommandee

Pour garantir un rendu propre dans le PDF Formation, utiliser une convention unique et simple.

1. inserer une capture seule sur sa ligne ;
2. laisser une ligne vide avant et apres l image ;
3. utiliser une legende courte et utile ;
4. privilegier des captures nettes, sans zoom navigateur excessif ni informations sensibles.

Format recommande :

```md
![Vue du portail Finéa](captures/presentation/portail-finea.png "Vue d ensemble du portail Finéa apres connexion")
```

Regles pratiques :

- utiliser un nom de fichier stable et explicite ;
- eviter plusieurs captures collees sans texte entre elles ;
- viser de preference des images d au moins 1400 px de large pour les vues d ensemble ;
- si une capture illustre une action, la phrase juste au dessus doit expliquer ce que le lecteur doit observer.

Cette convention permet au moteur PDF de centrer correctement la capture, de lui donner une taille raisonnable et d afficher une legende propre sous l image.

## Insertion assistee dans les guides

Le centre Formation ne se limite plus a afficher une convention.

Il permet maintenant d inserer le bloc image directement dans le fichier Markdown cible, avec quatre modes :

- fin du guide ;
- avant un titre Markdown detecte ;
- apres un titre Markdown detecte ;
- apres un repere texte libre.

Cette capacite doit rester reservee au contexte technique et ecraser le moins possible le contenu existant.

## Demonstration visuelle temporaire

Pour valider le rendu PDF de bout en bout sans attendre une vraie capture metier, un visuel de demonstration peut etre integre temporairement dans un guide technique.

![Repere visuel Finéa](../../../../img/app_logo.png "Validation temporaire du rendu image et legende dans le PDF Formation")

Ce visuel n a pas vocation a rester dans la documentation finale. Il sert uniquement a verifier la chaine complete : lecture Markdown, centrage, cartouche capture et legende dans le PDF exporte.
