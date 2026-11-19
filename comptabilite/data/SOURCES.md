# Nomenclature intégrée

Le référentiel contient exactement les **1 332 codes et libellés** du dernier fichier fourni par l’utilisateur, conservé dans `plan_fourni.csv`. Aucun ajout, suppression de code fourni ou correction de libellé n’est effectué. Les libellés identiques de codes différents sont conservés, notamment 2131 et 2193.

La migration automatique remplace le plan une seule fois : les comptes absents du fichier sont supprimés s’ils ne sont pas utilisés, sinon archivés et exclus du plan et des nouvelles saisies. Les écritures et leurs libellés historiques restent conservés. Un brouillon utilisant un compte retiré doit être corrigé avant validation. Les contrepassations historiques restent possibles.

Les comptes parents sont des regroupements ; les subdivisions terminales de plus de deux chiffres sont saisissables. Le contexte du compte parent affiché dans les listes ne modifie pas le libellé fourni.

La classe 9 reste réservée aux journaux hors bilan / analytique.
