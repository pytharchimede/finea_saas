# Module Logistique FINEA — Pack complet

## 1. Dossiers à copier
Copier les dossiers suivants à la racine du projet FINEA :

- `logi/`
- `controllers/`
- `services/`
- `repositories/`
- `model/entities/`

Les fichiers utilisent `Database::getConnection()` et `auth_check.php` comme les autres modules FINEA.

## 2. Auto-création SQL
Le fichier `logi/includes/LogisticsSchema.php` crée automatiquement les tables et colonnes manquantes au chargement du module.

Tables principales :

- `logistics_assets`
- `logistics_drivers`
- `logistics_requests`
- `logistics_assignments`
- `logistics_stock_items`
- `logistics_stock_movements`
- `logistics_fuel_logs`
- `logistics_maintenances`
- `logistics_incidents`
- `logistics_documents`
- `logistics_suppliers`
- `logistics_trip_batches`
- `logistics_trips`
- `logistics_settings`

## 3. Permissions ajoutées automatiquement
Si la table `user_devis_banamur` existe, les colonnes suivantes sont ajoutées :

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

Il reste à les mapper dans `AccessManager::PAGE_PERMISSIONS` selon ta logique existante.

## 4. Pages fournies

- `logi/dashboard_logistique.php`
- `logi/assets.php`
- `logi/drivers.php`
- `logi/requests.php`
- `logi/planning.php`
- `logi/fuel.php`
- `logi/stock.php`
- `logi/maintenance.php`
- `logi/incidents.php`
- `logi/documents.php`
- `logi/suppliers.php`
- `logi/trips.php`
- `logi/settings.php`

## 5. Prochaine amélioration
Remplacer les champs ID simples par des `<select>` alimentés depuis :

- personnel RH ;
- services RH ;
- chantiers ;
- fournisseurs ;
- parc engins/véhicules.

Le socle est prêt pour cette connexion.
