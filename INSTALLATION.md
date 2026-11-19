# Installation du module FINEA — Besoins récurrents

## 1. Copier le dossier
Copier le dossier `recurring_needs` à la racine de FINEA :

```txt
banamur_project_app/
└── recurring_needs/
    ├── index.php
    ├── cron_generate.php
    ├── controllers/
    ├── services/
    ├── repositories/
    ├── model/
    ├── db/
    └── assets/
```

## 2. Ajouter le module au portail
Dans `model/PortalModuleManager.php`, ajouter dans le constructeur :

```php
$this->migrateRecurringNeedsModule();
```

Puis ajouter cette méthode dans la classe :

```php
private function migrateRecurringNeedsModule(): void
{
    $module = [
        'module_key' => 'module_besoins_recurrents',
        'label' => 'Besoins récurrents',
        'icon' => 'fa-rotate',
        'color' => '#1d2b57',
        'target' => 'recurring_needs/index.php',
        'slug' => 'recurring_needs',
        'subtitle' => 'Planifier les FEB automatiques, rappels et besoins périodiques',
        'required_permissions_json' => json_encode(['acces_besoins_recurrents', 'acces_admin'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'sort_order' => 42,
        'is_active' => 1,
    ];

    $stmt = $this->pdo->prepare(
        'INSERT INTO portal_modules (module_key, label, icon, color, target, slug, subtitle, required_permissions_json, sort_order, is_active)
         VALUES (:module_key, :label, :icon, :color, :target, :slug, :subtitle, :required_permissions_json, :sort_order, :is_active)
         ON DUPLICATE KEY UPDATE
            label = VALUES(label), icon = VALUES(icon), color = VALUES(color), target = VALUES(target),
            slug = VALUES(slug), subtitle = VALUES(subtitle), required_permissions_json = VALUES(required_permissions_json),
            sort_order = VALUES(sort_order), is_active = VALUES(is_active)'
    );
    $stmt->execute($module);
}
```

## 3. Permissions à prévoir
Ajouter ces droits dans ton système de permissions :

```txt
acces_besoins_recurrents
creer_besoin_recurrent
valider_besoin_recurrent
modifier_besoin_recurrent
suspendre_besoin_recurrent
voir_tous_besoins_recurrents
```

## 4. Ajouter la page autorisée
Si ton contrôle d'accès utilise une liste de pages, ajouter :

```txt
recurring_needs/index.php
```

## 5. Cron journalier
Dans cPanel, créer une tâche cron quotidienne :

```bash
php /home/USER/public_html/banamur_project_app/recurring_needs/cron_generate.php
```

## 6. Point à connecter à ta vraie FEB
Dans `recurring_needs/services/RecurringNeedService.php`, remplacer la méthode :

```php
private function createFebDraftFromNeed(array $need, string $dueDate): ?int
```

par l'INSERT réel vers ta table FEB. Le module est prêt, mais ce point doit être branché sur le nom exact de ta table FEB actuelle.
