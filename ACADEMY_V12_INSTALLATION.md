# FINEA Academy v1.2 — Back-office ergonomique

## Installation
Copier le dossier `formation/` dans le projet FINEA en remplacement des fichiers Academy v1.1.

Aucune requête SQL manuelle n'est nécessaire : les tables restent auto-créées par `FormationAcademyRepository::ensureAcademyTables()`.

## Nouveautés
- Administration séparée conservée : `/formation/admin/index.php`
- Restauration du header/navbar FINEA via `decaissement/includes/header.php` et `navbar.php` quand ils existent
- Aside d'administration dédié FINEA Academy
- Pages séparées : dashboard, catalogue, formulaire parcours, exports
- Select-search sur les listes
- Champs module/permission avec saisie assistée
- Drag & drop image avec aperçu local
- Aperçu automatique vidéo YouTube/MP4
- Exports CSV : parcours, tentatives, certificats
- Upload sécurisé images JPG/PNG/WEBP max 5 Mo vers `formation/uploads/academy/YYYY/MM/`

## Points à vérifier
- Droits admin acceptés : `formation_admin`, `acces_admin`, `administration_systeme`
- Si le menu global ne s'affiche pas, vérifier que ces fichiers existent :
  - `decaissement/includes/header.php`
  - `decaissement/includes/navbar.php`
  - `decaissement/includes/footer.php`
