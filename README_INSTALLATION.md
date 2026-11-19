# Module Logistique FINEA — Pages secondaires + formulaires

Ce pack ne remplace pas `dashboard_logistique.php`.

Il ajoute uniquement les pages secondaires et formulaires dans le même esprit visuel : fond clair radial, cartes arrondies, KPI doux, tableaux séparés, boutons orange/bleu sombre.

## Fichiers

À copier dans le dossier `logi/` de FINEA :

- `_logistics_common.php`
- `assets.php`
- `drivers.php`
- `requests.php`
- `fuel.php`
- `stock.php`
- `maintenance.php`
- `incidents.php`
- `documents.php`
- `suppliers.php`
- `trip_batches.php`
- `settings.php`

## Important

Chaque page appelle automatiquement :

- `auth_check.php`
- `model/Database.php`
- `AccessManager::requirePageAccess()`
- `logi_ensure_schema($pdo)`

Donc les tables et colonnes manquantes sont créées automatiquement au chargement.

## Tables gérées

- `logistics_assets`
- `logistics_drivers`
- `logistics_requests`
- `logistics_fuel_logs`
- `logistics_maintenance`
- `logistics_incidents`
- `logistics_documents`
- `logistics_suppliers`
- `logistics_stock_items`
- `logistics_stock_movements`
- `logistics_trip_batches`
- `logistics_trips`
- `logistics_settings`

## Pages

- Parc engins & véhicules : `logi/assets.php`
- Chauffeurs : `logi/drivers.php`
- Demandes logistiques : `logi/requests.php`
- Carburant : `logi/fuel.php`
- Stock : `logi/stock.php`
- Maintenance : `logi/maintenance.php`
- Incidents : `logi/incidents.php`
- Documents administratifs : `logi/documents.php`
- Fournisseurs / intervenants : `logi/suppliers.php`
- Vagues de voyages : `logi/trip_batches.php`
- Paramètres : `logi/settings.php`

## Permissions à ajouter ensuite

À intégrer dans `user_devis_banamur` puis dans `AccessManager::PAGE_PERMISSIONS` :

- `module_logistique`
- `logistique_dashboard`
- `logistique_assets`
- `logistique_drivers`
- `logistique_stock`
- `logistique_fuel`
- `logistique_maintenance`
- `logistique_incidents`
- `logistique_documents`
- `logistique_suppliers`
- `logistique_requests`
- `logistique_trips`
- `logistique_settings`

## Note

Le design est volontairement basé sur le dashboard transmis, sans refonte radicale.
