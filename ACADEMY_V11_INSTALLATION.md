# FINEA Academy v1.1 — MOOC + Administration séparée

## Installation

1. Copier le dossier `formation/` dans le projet FINEA.
2. Ouvrir `/formation/index.php`.
3. Les tables Academy sont créées automatiquement si inexistantes.
4. L'administration est accessible via `/formation/admin/index.php` pour les utilisateurs ayant `formation_admin` ou `acces_admin`.

## Ce qui change

- Interface apprenant plus proche d'un MOOC.
- Page parcours avec sommaire, leçons, blocs pédagogiques et QCM final.
- QCM avec validation obligatoire question par question.
- Interface admin séparée.
- Création/modification rapide des parcours.
- Association des parcours aux permissions.
- Ajout rapide de leçons.
- Configuration des obligations progressives.
- Dashboard admin : parcours, tentatives, certificats, score moyen.

## Aucun SQL manuel

La méthode `FormationAcademyRepository::ensureAcademyTables()` reste appelée automatiquement au chargement du contrôleur.

