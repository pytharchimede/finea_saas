# Guide de developpement du module Documentation & Archives

## Structure générale

Le module repose sur un modèle DocumentManager très central. C est lui qui porte l essentiel des opérations documentaires.

La règle principale est donc : éviter de disperser de la logique documentaire métier dans plusieurs endpoints si elle peut rester dans DocumentManager.

## Responsabilités principales du modèle

- assurer les tables ;
- gérer dossiers et sous-dossiers ;
- gérer documents et versions ;
- gérer audiences et restrictions ;
- gérer partages ;
- gérer historique et archivage ;
- préparer les métadonnées de consultation.

## Zones sensibles

- versionnement ;
- suppression / archivage ;
- partage par token ;
- téléchargement sécurisé ;
- restrictions d audience ;
- création automatique de hiérarchie.

## Bonnes pratiques

- conserver un seul point de vérité pour la version courante ;
- tester systématiquement un document archivé et non archivé ;
- vérifier les droits avant de simplifier une route ;
- ne pas contourner DocumentManager depuis un endpoint.

## Tests minimaux

1. créer un dossier ;
2. importer un document ;
3. importer une nouvelle version ;
4. renommer le document ;
5. déplacer le document ;
6. archiver puis restaurer ;
7. créer un partage ;
8. tester l ouverture du lien partagé ;
9. tester le téléchargement.