# Proposition — Application mobile Flutter (focus indicateurs, validations, push)

## 1) Positionnement produit (version mobile V1)

Objectif: proposer une app **mobile-first, opérationnelle en terrain**, centrée sur:

1. la lecture rapide des **indicateurs** (pipeline financier),
2. la **validation** des demandes FEB,
3. les **alertes push** actionnables.

La V1 ne reprend pas tout l’ERP web; elle agit comme une **tour de contrôle mobile** pour décideurs, finance et validateurs.

## 2) Pourquoi ce périmètre est le plus pertinent

Le code actuel montre déjà:

- un écran de validation finances avec statuts `submitted/approved/rejected/cancelled` et actions approuver/refuser,
- des filtres et compteurs de demandes,
- un endpoint de notifications (`api/notifications_poll.php`) avec `unread_count`, `latest_id`, `notifications`,
- une logique de permissions par équipes/services.

=> Cela permet de livrer vite une app mobile à forte valeur sans recréer toute la logique métier.

## 3) Personas V1

- **Valideur finance**: traite les FEB en attente, ajoute une note, notifie rapidement.
- **Manager transverse**: surveille les montants/volumes et les blocages.
- **Direction**: consulte les KPI consolidés et alertes critiques.

## 4) Structure fonctionnelle recommandée (Flutter)

## Navigation (Bottom bar, 4 onglets)

1. **Accueil**
   - KPI du jour/semaine/mois
   - Carte “Demandes en attente”
   - Alertes prioritaires
2. **Validations**
   - Liste des FEB à valider (mes services)
   - Filtres rapides (statut/service/période)
   - Détail + actions Approuver / Refuser
3. **Indicateurs**
   - Tendances montants (demandé, validé, rejeté)
   - Répartition par service / chantier / bénéficiaire
4. **Notifications**
   - Inbox temps réel
   - Marquer lu / non lu
   - Deep link vers la demande

## 5) Écrans clés (UX)

### A. Home (tableau de bord synthèse)

Composants:
- bandeau “Bonjour + rôle + périmètre service”,
- 4 KPI cards:
  - En attente,
  - Validées (7 jours),
  - Refusées (7 jours),
  - Montant en attente,
- bloc “Actions rapides”:
  - Voir validations,
  - Filtrer urgentes,
- timeline de 5 dernières notifications.

### B. Validations — liste

- tabs chips:
  - `À valider (mes services)`
  - `En attente (toutes)`
  - `Validées`
  - `Refusées`
  - `Annulées`
- chaque ligne:
  - Réf #,
  - demandeur,
  - chantier + description,
  - montant,
  - service,
  - badge statut.

### C. Validation — détail / décision

- en-tête: Référence + statut + date,
- bloc métier:
  - demandeur,
  - service,
  - montant,
  - justificatifs,
- bloc historique (journal événements),
- zone commentaire,
- CTA sticky bas écran:
  - **Refuser** (secondaire danger),
  - **Approuver** (primaire).

### D. Indicateurs

- graph “pipeline par statut” (barres),
- graph “tendance hebdo” (ligne),
- Top 5 services par montant en attente,
- filtre global date + service.

### E. Notifications

- groupées par date,
- badge non lu,
- swipe “Marquer lu”,
- appui ouvre directement la fiche concernée.

## 6) Push notifications (stratégie)

## Types d’alertes V1

1. **Nouvelle demande à valider**
2. **Demande approuvée/refusée**
3. **Seuil critique montant en attente dépassé**
4. **Rappel de backlog** (ex: > N demandes depuis 24h)

## Règles produit

- Priorité haute: demandes urgentes et seuils critiques,
- anti-bruit:
  - regroupement (digest) hors urgences,
  - fenêtre silencieuse configurable,
- deep links:
  - push -> écran validation détail.

## Implémentation Flutter

- package: `firebase_messaging` + `flutter_local_notifications`,
- payload JSON minimal:

```json
{
  "type": "finance_validation_pending",
  "request_id": 1428,
  "title": "Nouvelle FEB à valider",
  "body": "CHANTIER C17 · 1 250 000 FCFA",
  "priority": "high",
  "deeplink": "banamur://validations/1428"
}
```

## 7) Architecture technique Flutter recommandée

- **State management**: Riverpod (simple, testable, scalable),
- **Networking**: Dio + interceptors (token/session + logs),
- **Offline-lite**: cache local (Hive/Isar) pour:
  - notifications récentes,
  - dernières listes validations,
- **Analytics produit**:
  - événements: ouverture push, délai de validation, taux approbation/refus.

Arborescence conseillée:

```text
lib/
  app/
  core/              # thème, constantes, utilitaires
  features/
    dashboard/
    validations/
    indicators/
    notifications/
  shared/
    widgets/
    models/
    services/
```

## 8) Design system mobile (prêt Flutter)

- Style: **pro, clair, data-centric**,
- couleurs:
  - primaire `#4F46E5` (indigo),
  - succès `#059669`,
  - danger `#E11D48`,
  - neutres `#0F172A` -> `#F8FAFC`,
- typographie: Inter,
- rayon:
  - cards: 16,
  - boutons: 14,
  - chips: 999,
- spacing base: 4/8/12/16/24/32.

## 9) Figma — blueprint prêt à construire

Je ne peux pas générer un lien Figma hébergé directement depuis ce dépôt, mais voici un **brief Figma exécutable immédiatement**.

## Pages Figma

1. `00_Foundations`
2. `01_Components`
3. `02_Mobile_Screens`
4. `03_Prototype`

## Frames à créer (Mobile 390x844)

- `Splash`
- `Login`
- `Home`
- `Validations_List`
- `Validation_Detail_ActionSheet`
- `Indicators`
- `Notifications`
- `Profile_Settings_Notifications`

## Composants minimum

- `KPI/Card`
- `Status/Badge` (Pending, Approved, Rejected, Cancelled)
- `Request/ListItem`
- `Button/Primary`
- `Button/Secondary`
- `BottomNav/Item`
- `Toast/Feedback`
- `Notification/Item`

## Variables (Figma Variables)

- `color.primary = #4F46E5`
- `color.success = #059669`
- `color.warning = #D97706`
- `color.error = #E11D48`
- `radius.card = 16`
- `radius.button = 14`
- `space.1 = 4`, `space.2 = 8`, `space.3 = 12`, `space.4 = 16`

## Prototype flow recommandé

`Push Notification` -> `Validation_Detail_ActionSheet` -> `Approve/Reject` -> `Toast` -> retour `Validations_List` (item mis à jour).

## 10) Roadmap mobile (réaliste)

- **Sprint 1 (2 semaines)**: auth + structure app + Home KPI mockés.
- **Sprint 2 (2 semaines)**: liste validations + détail + actions.
- **Sprint 3 (2 semaines)**: notifications push + deep links.
- **Sprint 4 (1-2 semaines)**: indicateurs avancés + durcissement QA.

## 11) Critères de succès V1

- Délai moyen de validation réduit,
- taux de traitement mobile > 40% des validations,
- baisse des demandes “oubliées” (>48h),
- taux d’ouverture des push prioritaires.

---

## Recommandation finale

Le meilleur design mobile pour BANAMUR est une app Flutter **“Command Center”**: peu d’écrans, très lisibles, orientés décision rapide. La priorité n’est pas la saisie complexe mais la **visibilité KPI + validation + push actionnable**.


## 12) Support visuel pour comité DG

Un pack visuel de présentation (wireframes consolidés + message de décision) est disponible ici:

- `docs/mobile_dg_validation_pack.md`
- `docs/mobile_assets/flutter_mobile_v1_dg_wireframes.svg`
