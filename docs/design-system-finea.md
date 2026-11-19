# Design system FINEA

Le fichier commun est `assets/css/finea-ui.css`. Les composants sont mobiles d'abord, sobres et compatibles avec le mode sombre global.

| Classe | Usage | Statut conseille |
|---|---|---|
| `.finea-shell` | Fond principal d'une page FINEA | Toutes les nouvelles pages |
| `.finea-container` | Largeur et marges de contenu | Toutes les nouvelles pages |
| `.finea-page-header` | Banniere de titre et actions | Une fois par page |
| `.finea-kpi-grid` | Grille responsive d'indicateurs | Dashboards |
| `.finea-kpi-card` | Carte d'un indicateur | Dashboards |
| `.finea-section-card` | Bloc fonctionnel principal | Pages et dashboards |
| `.finea-filter-card` | Zone de recherche et filtres | Listings |
| `.finea-action-btn` | Base commune des boutons | Toujours avec une variante |
| `.finea-table` | Tableau lisible et survolable | Listings |
| `.finea-status-badge` | Etat court | Toujours avec une variante |
| `.finea-alert` | Alerte ou information importante | Usage ponctuel |
| `.finea-empty-state` | Etat sans donnee | Obligatoire pour les modules optionnels |
| `.finea-timeline` | Historique chronologique | Audit et suivi |

## Entete

```html
<header class="finea-page-header">
  <div>
    <h1>Titre de la page</h1>
    <p>Contexte court et utile.</p>
  </div>
  <div class="finea-header-actions">
    <a class="finea-action-btn finea-action-btn--accent" href="#">Action</a>
  </div>
</header>
```

## KPI

```html
<div class="finea-kpi-card">
  <span class="finea-kpi-label">Tickets ouverts</span>
  <strong class="finea-kpi-value">12</strong>
  <span class="finea-kpi-meta">2 en retard SLA</span>
</div>
```

## Boutons et statuts

Variantes bouton : `--primary`, `--accent`, `--secondary`, `--danger`.

Variantes badge : `--ok`, `--warning`, `--danger`, `--info`.
