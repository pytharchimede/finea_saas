# Intégration FINEA Academy

## 1. Exécuter la migration
Importer :

`database/migrations/20260610_create_finea_academy.sql`

## 2. Copier les nouveaux fichiers
Copier les dossiers dans le projet :

- `formation/controllers/FormationAcademyController.php`
- `formation/services/FormationAcademyService.php`
- `formation/repositories/FormationAcademyRepository.php`
- `formation/views/academy_dashboard.php`
- `formation/assets/academy.js`

## 3. Brancher le contrôleur dans `formation/index.php`
Ajouter après les require existants :

```php
require_once __DIR__ . '/controllers/FormationAcademyController.php';
```

Puis, après la récupération des permissions :

```php
$academyController = null;
$academyDashboard = null;
if (isset($pdo) && $pdo instanceof PDO) {
    $academyController = new \Formation\Controllers\FormationAcademyController($pdo);
    $currentUserId = (int) ($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    $academyDashboard = $academyController->dashboard(
        $currentUserId,
        $permissions,
        !empty($permissions['formation_admin']) || !empty($permissions['acces_admin'])
    );
}
```

Si FINEA n’utilise pas `$pdo`, remplacer `$pdo` par l’objet PDO réel utilisé dans ton projet.

## 4. Afficher le dashboard Academy
Dans la zone principale de `formation/index.php`, juste avant ou après l’affichage du guide Markdown :

```php
<?php if (is_array($academyDashboard ?? null)): ?>
    <?php require __DIR__ . '/views/academy_dashboard.php'; ?>
<?php endif; ?>
```

## 5. Charger le JS
Avant `</body>` :

```php
<script src="<?= htmlspecialchars(($appBasePath === '' ? '' : $appBasePath) . '/formation/assets/academy.js', ENT_QUOTES, 'UTF-8') ?>"></script>
```

## 6. Règle de blocage ciblé
Dans un module sensible, par exemple création FEB, appeler un guard avant l’action :

```php
// Exemple conceptuel : à placer avant create_feb
// $canCreateFeb = $formationAcademyGuard->canPerform($userId, 'decaissement', 'create_feb');
// if (!$canCreateFeb) { rediriger vers Formation Academy avec message obligatoire; }
```

La table `formation_requirements` contient la configuration : `module_slug`, `permission_key`, `requirement_type`, `blocking_action`, `required_score`.

## 7. Prochaine étape recommandée
Ajouter une méthode `canPerformTrainingProtectedAction()` dans le service, qui vérifie :

- règle active dans `formation_requirements`
- score de l’utilisateur dans `formation_user_progress`
- certificat éventuel dans `formation_certificates`

