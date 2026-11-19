# Correctifs intégration Planning Chantier

Fichiers corrigés :
- AccessManager.php : droits planning_chantier + auto-création colonne acces_planning_chantier + compatibilité ancien dashboard_planing.php.
- PortalModuleManager.php : lien portail vers planning_chantier/dashboard.php + correction ancien module_planning.
- navbar.php : lien direct Planning Chantier + activation automatique du menu.
- gestion_acces.php : badge Planning Chantier dans la liste des droits.
- dashboard.php : contrôle d'accès explicite du module.
- dashboard_planing.php : redirection de compatibilité vers le nouveau module.

Action :
1. Remplacer les fichiers dans leurs emplacements respectifs.
2. Mettre dashboard_planing.php à la racine du projet temporairement.
3. Une fois tous les liens corrigés et vérifiés, supprimer définitivement dashboard_planing.php.
