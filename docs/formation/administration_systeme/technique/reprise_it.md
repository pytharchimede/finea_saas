# Reprise IT de l Administration systeme

## Positionnement

Le module Administration systeme regroupe les ecrans d administration centrale du projet. Il est piloté par acces_admin sur la plupart des pages.

## Fichiers cles

- admin/dashboard_admin.php
- admin/liste_users.php
- admin/edit_user.php
- admin/add_user.php
- admin/gestion_acces.php
- admin/portal_modules.php
- admin/email_logs.php
- admin/audit_erp.php
- admin/db_tools.php
- admin/fne_tools.php
- admin/notifications_settings.php
- admin/maintenance_point_financier_indexes.php
- admin/relink_fiches_debourse.php
- admin/update_user_permissions.php

## Sous-ensembles majeurs

### Utilisateurs et droits

- liste_users.php
- add_user.php
- edit_user.php
- gestion_acces.php
- update_user_permissions.php

### Pilotage portail

- portal_modules.php
- model/PortalModuleManager.php

### Journaux et supervision

- audit_erp.php
- email_logs.php
- notifications_settings.php

### Outils techniques

- db_tools.php
- fne_tools.php
- maintenance_point_financier_indexes.php
- relink_fiches_debourse.php

## Distinction importante

admin/email_logs.php ne correspond pas a la boite mail utilisateur. C est le journal des emails envoyes par le systeme BANAMUR, avec statuts et taux d ouverture.

## Risques de reprise

- modifier les droits sans mesurer les effets croises ;
- casser la configuration du portail ;
- exposer des outils sensibles ;
- affaiblir la tracabilite admin.

## Reprise recommandee

1. relire dashboard_admin.php ;
2. cartographier les ecrans admin par famille ;
3. tester la gestion d un utilisateur ;
4. tester gestion_acces et portal_modules ;
5. verifier audit et email_logs ;
6. verifier les outils techniques reserves.