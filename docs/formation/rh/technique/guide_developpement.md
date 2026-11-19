# Guide de developpement du module RH

## Logique générale

Le module RH n est pas un seul sous-système. C est un ensemble de pages métier spécialisées reliées par des référentiels communs et par la table du personnel.

Les changements RH doivent donc être pensés comme des changements transverses.

## Zones majeures

- dossier collaborateur ;
- annuaire et filtres ;
- sorties ;
- missions ;
- pointage ;
- paie ;
- validations ;
- paramétrages.

## Règle de maintenance importante

Avant de modifier un champ ou un référentiel RH, vérifier où il est consommé :

- formulaire d intégration ;
- fiche dossier ;
- filtres de liste ;
- exports ;
- pointage ou paie ;
- écrans d emploi du temps ;
- notifications.

## Paramétrages comme socle

parametrage_rh.php est l un des meilleurs points d entrée pour comprendre la structure métier. Une grande partie des listes et règles affichées dans le module en dépend.

## Lien personnel / utilisateur

Le rapprochement entre user_devis_banamur et personnel est critique pour les modules transverses, notamment Emploi du temps. Toute évolution de ce lien doit être testée au-delà du module RH seul.

## Zones sûres

- amélioration du wording ;
- amélioration des tableaux de bord ;
- documentation ;
- ajustement visuel des fiches.

## Zones sensibles

- structure des données du personnel ;
- référentiels RH ;
- pointage et paie ;
- sorties ;
- validations ;
- horaires liés à l emploi du temps.

## Tests minimaux

1. créer un collaborateur ;
2. modifier sa fiche ;
3. sortir le collaborateur ;
4. saisir un pointage ;
5. ouvrir la paie ;
6. vérifier un écran de validation RH ;
7. vérifier un paramétrage RH ;
8. vérifier le lien avec l emploi du temps si la modification touche le personnel.