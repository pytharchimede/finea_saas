# Reprise IT du module Point financier

## Positionnement

Le Point financier est le tableau de bord analytique du workflow des fiches de décaissement. Il consolide les indicateurs, les listes détaillées et les exports autour d un jeu de filtres commun.

## Fichiers clés

- decaissement/point_financier.php
- decaissement/request/point_financier_stats.php
- decaissement/request/point_financier_detail.php
- decaissement/request/point_financier_filters.php
- decaissement/request/point_financier_pdf_report.php
- decaissement/request/export_point_financier_pdf.php
- Database/add_point_financier_indexes.sql

## Contrat frontend / backend

L écran point_financier.php charge maintenant les données en séquence :

- stats ;
- détail ;
- synthèse visuelle.

Le comportement a été renforcé avec AbortController et une progression plus lisible, ce qui réduit les charges concurrentes lors des changements de filtres.

## Endpoints principaux

### point_financier_stats.php

Retourne les agrégats hauts niveau :

- prévisions ;
- décaissements ;
- demandes totales ;
- attente ;
- refus ;
- workflow overview ;
- séries par jour ;
- regroupements par chantier.

### point_financier_detail.php

Retourne :

- la synthèse détaillée ;
- les bénéficiaires ;
- les catégories ;
- la liste des fiches ;
- les chantiers et déboursés ;
- un mode léger lists_only pour ne charger que les listes de sélecteurs.

## Filtrage métier

Le module s appuie sur point_financier_filters.php pour centraliser :

- la détection du chantier ;
- les conditions SQL par état ;
- les filtres communs ;
- certaines réparations de liens chantier / déboursé.

## Règles importantes

- les fiches sans chantier direct peuvent être rattachées au chantier via le déboursé ;
- le budget chantier ne doit pas être recalculé localement hors du modèle prévu ;
- les états affichés doivent rester cohérents entre stats, détail et export.

## Performance

Le module est sensible aux index base de données. Un script recommandé existe dans Database/add_point_financier_indexes.sql et une maintenance admin dédiée peut alerter sur les index manquants.

## Risques de reprise

- dupliquer localement les règles de calcul ;
- casser l alignement entre stats et détail ;
- oublier le mode lists_only ;
- perdre la correspondance entre état métier et condition SQL ;
- ignorer les erreurs DB retournées en HTTP 500 JSON.

## Reprise recommandée

1. relire point_financier_filters.php ;
2. tester stats et détail avec les mêmes filtres ;
3. vérifier les exports PDF ;
4. vérifier les performances sur un volume réaliste ;
5. vérifier les états renommés visibles dans l UI.