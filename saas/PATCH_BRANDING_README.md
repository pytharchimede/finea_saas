# FINEA SaaS — correctif branding

Le correctif est destiné à être fusionné dans `/var/www/html/finea_saas/saas/`. Il n’effectue aucune migration SQL.

- `company.php` : capital, cinq couleurs, upload logo/favicon PNG/JPEG/WebP, aperçu.
- `dashboard.php` : affichage du logo du tenant.
- `TenantBrandingService` : lecture du branding depuis le code métier, avec un identifiant de tenant **déjà résolu et vérifié** par le middleware. Ne pas choisir le tenant sur la base d’un paramètre GET libre.
- Les médias sont servis depuis `saas/admin/assets/tenant-media/` ; pour un ERP sur domaine séparé, prévoir une URL média dédiée ou la copie contrôlée des fichiers.

**Limites :** pas de provisioning de la base ERP ni de mapping d'URL à ce stade ; pas d’intégration automatique aux vues/PDF métier sans connaître leurs points d’entrée.

**Sécurité :** vérifier la prise en compte des `.htaccess` par Apache ; l’upload est limité à 2 Mo et aux images PNG/JPEG/WebP. Pour la production, prévoir un stockage média hors répertoire admin, un scan et un service dédié.
