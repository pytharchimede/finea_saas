# FINEA Academy v1.3 — Vue apprenant alignée portail

## Corrections incluses

- `/formation/index.php` utilise maintenant un conteneur large `formation-page-container` aligné sur la largeur standard FINEA au lieu du `max-w-7xl` trop étroit.
- Les modules du panneau gauche sont enrichis avec `PortalModuleManager` : icône, couleur, libellé et accès.
- Les modules hors droits utilisateur sont masqués lorsque `PortalModuleManager` est disponible.
- Les cartes Academy reprennent couleur et icône du module portail correspondant.
- La vue apprenant est allégée : KPI compacts, cartes moins chargées, progression plus lisible.
- Correction mapping Tickets : `centre-tickets` utilise `ticketing` et `acces_ticketing`, avec auto-correction BDD au chargement.

## Installation

Copier le dossier `formation/` dans le projet FINEA en remplaçant les fichiers existants de la v1.2.

Aucune requête SQL manuelle n'est nécessaire.
