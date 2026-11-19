# Guide néophyte — comprendre la base de code BANAMUR Planification

Ce document sert d’**onboarding rapide** pour une personne qui découvre le projet.

## 1) Vue d’ensemble

L’application est un **monolithe PHP** orienté métier (ERP interne) avec plusieurs sous-domaines :

- devis,
- décaissement,
- RH,
- fournisseurs,
- CRM,
- emails,
- contrôle d’accès et administration.

Le point d’entrée est `index.php`, qui redirige vers `login.php` (non connecté) ou `selection_portail.php` (connecté).

## 2) Architecture fonctionnelle (comment naviguer)

Le projet suit une structure « pages + modèles + endpoints » :

- `*.php` à la racine et dans les sous-dossiers : pages UI/serveur.
- `model/` : logique métier réutilisable (accès DB, permissions, exports, audit, etc.).
- `request/` : endpoints d’actions (AJAX/formulaires, exports, création/modification).
- `admin/`, `employe/`, `fournisseur/`, `crm/`, `emails/` : modules métier.
- `docs/` : documentation technique/produit.
- `attendance_api/` : API dédiée à la présence/pointage.
- `decaissement/` : gros sous-module historique avec ses propres includes/assets.

## 3) Flux principal de connexion et session

1. `index.php` oriente l’utilisateur.
2. `login.php` affiche le formulaire.
3. `request/login_handler.php` authentifie, charge la session, initialise les permissions, gère les tentatives ratées et le verrouillage.
4. `auth_check.php` protège ensuite toutes les pages sensibles.

À retenir : la sécurité est pensée autour de **session + permissions + audit**.

## 4) Contrôle d’accès (point crucial)

Le contrôle d’accès est à 3 niveaux :

1. Vérifier qu’on est connecté (`Auth::isLoggedIn`).
2. Résoudre les permissions utilisateur (`AccessManager::loadUserPermissions`).
3. Vérifier page par page via un mapping permission → ressource (`AccessManager::PAGE_PERMISSIONS`).

`auth_check.php` centralise ces règles et distingue les réponses HTML vs JSON (utile pour AJAX).

## 5) Base de données et configuration

- La connexion DB passe par `model/Database.php` (singleton PDO).
- La configuration peut venir de variables d’environnement via `EnvConfig`.
- Plusieurs tables sont créées « à la volée » si absentes (ex. sécurité login, historique de connexion, accès pages).

Conséquence pratique : le code est robuste en environnement partiellement initialisé, mais il faut surveiller la cohérence des schémas entre environnements.

## 6) Organisation des modules métier

### a) Portail modules

`selection_portail.php` sert de hub après connexion :

- charge les modules actifs,
- trie selon usage,
- gère favoris utilisateur,
- affiche badges (éléments en attente),
- journalise les clics (audit).

### b) Back-office admin

`admin/` regroupe administration utilisateurs, permissions, audit ERP, notifications, configuration des badges/portail, etc.

### c) Espace employé

`employe/` contient demandes RH, pointage, suivi personnel, fournisseurs employés, etc.

### d) Décaissement

`decaissement/` est un sous-ensemble riche (workflows, fiches, achats, compta, OTP, planning) avec son propre écosystème de fichiers.

### e) Attendance API

`attendance_api/` expose une API de lecture des employés/pointages (historique, today, supervision collecteurs).

## 7) Dépendances et génération de documents

Le `composer.json` montre des bibliothèques orientées documents/exports :

- `phpoffice/phpspreadsheet` (Excel),
- `phpoffice/phpword` (Word),
- `tecnickcom/tcpdf` et `setasign/fpdi` (PDF),
- `endroid/qr-code` (QR code).

Donc une grande partie de la valeur métier passe par la **production de livrables (PDF/Excel/Word)**.

## 8) Ce qu’un débutant doit savoir tout de suite

1. **Toujours identifier le module** avant de modifier un fichier (root, `request/`, `decaissement/`, etc.).
2. **Vérifier `auth_check.php` et `AccessManager`** pour éviter de casser la sécurité.
3. **Regarder les modèles dans `model/`** avant d’ajouter du code dupliqué.
4. **Ne pas sous-estimer les exports** : beaucoup de pages métiers y sont reliées.
5. **Tracer les impacts session** quand on touche login/permissions.

## 9) Points de repère pour la suite de l’apprentissage

### Semaine 1 (lecture)

- Lire : `index.php`, `login.php`, `request/login_handler.php`, `auth_check.php`.
- Lire : `model/Database.php`, `model/Auth.php`, `model/AccessManager.php`, `model/PageAccess.php`.
- Lire : `selection_portail.php` pour comprendre la navigation globale.

### Semaine 2 (exploration orientée métier)

- Choisir un module (ex. `employe/` ou `fournisseur/`) et tracer un flux complet : page → endpoint `request/` → modèle → DB.
- Identifier les exports associés (PDF/Excel).

### Semaine 3 (mise en pratique)

- Implémenter une petite amélioration non critique (texte UI, filtre simple, log audit additionnel).
- Vérifier permissions + session + non-régression des exports.

## 10) Anti-patterns à éviter

- Ajouter des accès directs DB dans les pages sans passer par une logique factorisée.
- Créer une nouvelle page sans mapping de permission.
- Modifier la session « au hasard » sans vérifier l’impact sur `auth_check.php` et le portail.
- Dupliquer un endpoint dans `request/` au lieu de réutiliser un modèle existant.

## 11) Check-list rapide avant toute modification

- [ ] Le flux est protégé par `auth_check.php`.
- [ ] La permission de la page/action est définie dans `AccessManager`.
- [ ] Les données sensibles sont loggées de façon pertinente (audit).
- [ ] Les exports impactés ont été retestés.
- [ ] Le comportement AJAX/JSON reste cohérent.

---

Si tu débutes complètement en PHP : commence par lire les fichiers dans cet ordre exact :
`index.php` → `login.php` → `request/login_handler.php` → `auth_check.php` → `model/AccessManager.php` → `selection_portail.php`.
