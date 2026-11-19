# Guide de developpement du module Point financier

## Principe général

Le module Point financier est un agrégateur de lecture. Il ne faut pas le traiter comme un écran isolé : ses chiffres dépendent d un ensemble de règles de filtrage et de jointures métier.

## Répartition des responsabilités

- point_financier.php : interface, filtres, appels fetch, rendu visuel, exports ;
- point_financier_stats.php : KPI et agrégats ;
- point_financier_detail.php : listes détaillées et synthèses secondaires ;
- point_financier_filters.php : socle de règles partagées.

## Règle de développement clé

Toute nouvelle règle de filtrage ou de statut doit être centralisée dans la couche partagée de filtres, pas recopiée dans plusieurs endpoints.

## Zones sensibles

- mapping des états métier ;
- calcul des montants ;
- rattachement chantier / déboursé ;
- cohérence entre détail, stats et export ;
- performance SQL.

## Bonnes pratiques

- réutiliser les mêmes conditions SQL entre endpoints quand le sens métier est identique ;
- tester les cas limites sans chantier direct ;
- préserver le mode lists_only ;
- ne pas recalculer côté frontend ce qui doit venir du backend.

## Tests minimaux

1. changer l état du filtre et comparer stats / détail ;
2. tester un chantier connu ;
3. tester un déboursé précis ;
4. tester un cas refusé ;
5. tester un cas décaissé ;
6. tester un export PDF ;
7. tester la synthèse visible dans la zone de filtres.