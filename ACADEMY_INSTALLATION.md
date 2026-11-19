# FINEA Academy — Patch intégré avec auto-migration BDD

Ce patch transforme le module Formation en base MOOC interne **FINEA Academy** sans casser les guides Markdown existants.

## Installation

1. Remplacer/copier le dossier `formation/` dans ton projet FINEA.
2. Ouvrir `/formation/index.php`.
3. Les tables Academy sont créées automatiquement si elles n’existent pas.

Aucune exécution SQL manuelle n’est obligatoire.

## Auto-migration

L’auto-création est déclenchée dans :

```php
formation/controllers/FormationAcademyController.php
```

via :

```php
$repository = new FormationAcademyRepository($pdo);
$repository->ensureAcademyTables();
```

La méthode `ensureAcademyTables()` se trouve dans :

```php
formation/repositories/FormationAcademyRepository.php
```

Elle crée automatiquement :

- formation_courses
- formation_course_permissions
- formation_lessons
- formation_lesson_blocks
- formation_quizzes
- formation_quiz_questions
- formation_quiz_answers
- formation_attempts
- formation_attempt_answers
- formation_user_progress
- formation_certificates
- formation_requirements

Les parcours de démonstration ne sont insérés que si les 4 parcours de base n’existent pas encore.

## Ce qui doit apparaître

Dans Formation, tu dois voir :

- un bloc **FINEA Academy** ;
- une page **Ma maîtrise FINEA** ;
- les parcours visibles selon permissions ;
- une page parcours ;
- un QCM avec validation obligatoire avant soumission.

## Important

Le fichier SQL dans `Database/` est conservé seulement comme référence technique/documentation. Il n’est pas à exécuter manuellement.
