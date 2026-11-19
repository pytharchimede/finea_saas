# Module de gestion documentaire et archivage

## Objectif

Mettre en place un centre documentaire unique pour les chantiers, directions et services, afin de :

- centraliser les documents du projet ;
- éviter la dispersion entre messages, téléchargements manuels et partages informels ;
- structurer les archives documentaires par chantier ;
- gérer les droits d'accès par service, direction, chantier et type de document ;
- supporter des fichiers lourds, y compris des plans et fichiers DWG.

## Constat sur l'existant

Le projet dispose déjà de briques utiles :

- module Communication avec pièces jointes et téléchargement sécurisé ;
- stockage serveur existant dans `storage/comm_attachments/` et `uploads/` ;
- permissions déjà gérées côté administration ;
- logique d'archivage déjà présente pour les chantiers ;
- traçabilité via `AuditTrail`.

Le besoin n'est donc pas de refaire un simple système d'upload, mais de construire un vrai référentiel documentaire transverse.

## Périmètre métier

Le module doit permettre :

- création de dossiers et sous-dossiers ;
- classement par chantier ;
- classement par direction ou service ;
- affectation d'un document à un ou plusieurs contextes : chantier, direction, service, communication, archive ;
- dépôt de documents bureautiques, images, PDF, DWG et gros fichiers ;
- consultation, téléchargement, versionnement léger et archivage ;
- recherche par chantier, dossier, type, date, auteur, tags et mot-clé ;
- gestion de droits fins : voir, déposer, modifier, archiver, supprimer, administrer ;
- historique d'activité documentaire.

## Cas d'usage cibles

### 1. Documentation chantier

- plans, DWG, devis validés, marchés, ordres de service, comptes rendus, photos, courriers ;
- arborescence cohérente connue de tous ;
- conservation même après archivage du chantier.

### 2. Documentation de coordination

- échanges projet du type KT ;
- documentation partagée entre direction, technique, financier et terrain ;
- séparation claire entre discussion et document de référence.

### 3. Archivage documentaire

- fermeture logique d'un dossier ou chantier ;
- conservation des documents sans possibilité de modification libre ;
- consultation selon droits ;
- historique des mouvements vers archive.

## Positionnement fonctionnel

Le module doit être distinct du module Communication, mais connecté à lui.

Principe :

- Communication = discussion, messages, échanges rapides, tâches, pièces jointes contextuelles ;
- Gestion documentaire = stockage de référence, classement, recherche, archivage, droits structurés.

Une pièce jointe de communication doit pouvoir être :

- laissée comme simple pièce jointe de message ;
- promue en document officiel du référentiel documentaire.

## Structure fonctionnelle recommandée

### 1. Espaces documentaires

Créer plusieurs espaces logiques :

- Chantier ;
- Direction ;
- Service ;
- Référentiel général ;
- Archives.

### 2. Arborescence type par chantier

Exemple :

- 01_Administratif
- 02_Contrats_et_devis
- 03_Plans_et_DWG
- 04_Execution_chantier
- 05_Comptes_rendus
- 06_Financier
- 07_Qualite_securite
- 08_Photos
- 09_Correspondances
- 10_Archives

Cette arborescence doit rester personnalisable, mais il faut un modèle par défaut.

### 3. Types de documents

Prévoir un typage normalisé :

- contrat ;
- devis ;
- facture ;
- plan ;
- dwg ;
- photo ;
- compte-rendu ;
- courrier ;
- justificatif ;
- document technique ;
- document administratif ;
- archive.

## Modèle de données recommandé

### Table `doc_folders`

Rôle : arborescence documentaire.

Champs recommandés :

- `id`
- `parent_id`
- `name`
- `slug`
- `space_type` : `chantier`, `direction`, `service`, `general`, `archive`
- `chantier_id` nullable
- `service_code` nullable
- `direction_code` nullable
- `is_archived`
- `created_by`
- `created_at`
- `updated_at`

### Table `doc_documents`

Rôle : fiche documentaire principale.

Champs recommandés :

- `id`
- `folder_id`
- `title`
- `description`
- `document_type`
- `chantier_id` nullable
- `service_code` nullable
- `direction_code` nullable
- `source_module` nullable : `communication`, `upload_direct`, `scan`, `import`
- `source_ref_id` nullable
- `current_version_id`
- `is_archived`
- `archived_at` nullable
- `archived_by` nullable
- `created_by`
- `created_at`
- `updated_at`

### Table `doc_document_versions`

Rôle : version physique d'un document.

Champs recommandés :

- `id`
- `document_id`
- `version_number`
- `stored_path`
- `storage_disk` ou `storage_bucket`
- `original_name`
- `extension`
- `mime_type`
- `file_size`
- `checksum_sha256`
- `preview_path` nullable
- `uploaded_by`
- `uploaded_at`
- `is_current`

### Table `doc_permissions`

Rôle : droits fins par dossier ou document.

Champs recommandés :

- `id`
- `scope_type` : `folder`, `document`
- `scope_id`
- `principal_type` : `user`, `service`, `direction`, `permission`
- `principal_value`
- `can_view`
- `can_upload`
- `can_edit`
- `can_archive`
- `can_delete`
- `can_manage`

### Table `doc_links`

Rôle : lier un document à plusieurs contextes.

Champs recommandés :

- `id`
- `document_id`
- `entity_type` : `chantier`, `devis`, `fiche`, `communication_message`, `client`, `offre`
- `entity_id`

### Table `doc_activity_logs`

Rôle : historique documentaire.

Champs recommandés :

- `id`
- `document_id` nullable
- `folder_id` nullable
- `action`
- `user_id`
- `details_json`
- `created_at`

## Gestion des droits d'accès

### Niveau 1. Permission module

Ajouter au minimum :

- `acces_documentation`
- `document_upload`
- `document_edit`
- `document_archive`
- `document_admin`

### Niveau 2. Règles contextuelles

Exemples :

- un utilisateur chantier voit les documents de son chantier ;
- une direction voit ses dossiers directionnels ;
- un service peut déposer dans ses dossiers mais pas forcément supprimer ;
- les archives sont visibles en lecture seule selon droits.

### Niveau 3. Exceptions ciblées

Exemples :

- un dossier sensible réservé à la direction ;
- un document visible uniquement par finance et direction ;
- un plan visible par technique mais non modifiable.

## Stockage des fichiers lourds

Pour les DWG et gros documents, il faut éviter de tout mélanger dans `uploads/`.

### Recommandation

Créer un stockage dédié du type :

- `storage/documents/`
- `storage/documents/chantiers/{chantier_id}/...`
- `storage/documents/directions/{direction}/...`
- `storage/documents/services/{service}/...`
- `storage/documents/archives/...`

### Règles techniques

- jamais servir les fichiers sensibles en accès direct public ;
- toujours passer par un endpoint contrôlé ;
- stocker le chemin réel uniquement en base ;
- vérifier extension, mime type, taille, hash ;
- prévoir limitation par type de fichier ;
- prévoir configuration spéciale pour gros fichiers.

### Support DWG

Le module doit :

- accepter `.dwg` parmi les types autorisés ;
- conserver le fichier source original ;
- permettre téléchargement sans corruption ;
- éventuellement générer une miniature ou un aperçu secondaire si plus tard un convertisseur est disponible.

## Archivage documentaire

Il faut distinguer deux notions :

- document archivé ;
- chantier archivé.

### Règles proposées

- quand un chantier passe en archive, ses documents restent consultables ;
- les dépôts/modifications peuvent être restreints ;
- un document peut aussi être archivé individuellement ;
- un dossier peut être basculé en lecture seule ;
- conserver l'historique : qui a archivé, quand, pourquoi.

## Intégration avec les modules existants

### Communication

Réutiliser l'existant :

- pièces jointes déjà stockées ;
- téléchargement déjà sécurisé ;
- audit déjà présent.

Évolution recommandée :

- bouton `Envoyer vers documentation` sur une pièce jointe ;
- création automatique d'un document référencé dans le dossier chantier cible.

### Chantiers

Ajouter un onglet ou bouton `Documents` dans les pages chantier.

Depuis un chantier, on doit pouvoir :

- voir l'arborescence documentaire ;
- déposer un document ;
- filtrer par type/date/auteur ;
- accéder aux archives documentaires du chantier.

### Portail

Ajouter un nouveau module portail :

- `Documentation / Archives documentaires`

Description suggérée :

- `Référentiel documentaire centralisé, classement, partage, versions et archives par chantier / service / direction.`

## Interfaces à prévoir

### 1. Tableau de bord documentaire

- accès rapide à mes dossiers ;
- documents récents ;
- documents les plus consultés ;
- documents en attente de classement ;
- archives récentes.

### 2. Explorateur documentaire

- arborescence à gauche ;
- liste des documents à droite ;
- recherche globale ;
- filtres par chantier, type, service, date, auteur, archive.

### 3. Fiche document

- métadonnées ;
- version courante ;
- historique ;
- liens métier ;
- droits ;
- actions : télécharger, remplacer version, déplacer, archiver.

### 4. Administration documentaire

- modèles d'arborescence ;
- règles de droits ;
- quotas ;
- types MIME autorisés ;
- seuils de taille.

## Plan de mise en oeuvre recommandé

### Phase 1. Socle documentaire minimal

- tables documentaires ;
- stockage dédié ;
- upload sécurisé ;
- arborescence chantier ;
- consultation/téléchargement ;
- permissions module de base.

### Phase 2. Intégration chantier et communication

- onglet Documents dans les chantiers ;
- promotion d'une pièce jointe Communication vers Documentation ;
- recherche par chantier/type/date/auteur.

### Phase 3. Archivage et gouvernance

- lecture seule sur archives ;
- journal d'activité documentaire ;
- archivage automatique à la clôture chantier ;
- règles par service/direction.

### Phase 4. Fonctions avancées

- versionning amélioré ;
- aperçu PDF/image ;
- quotas ;
- import massif ;
- indexation texte sur PDF et documents bureautiques ;
- alertes sur documents manquants.

## Recommandation pragmatique pour ce projet

La meilleure approche ici est de construire ce module en s'appuyant sur les patterns déjà présents :

- mêmes contrôles d'accès que les autres modules ;
- même logique d'audit ;
- même principe de téléchargement sécurisé que Communication ;
- même rattachement métier aux chantiers déjà archivables.

Il ne faut pas commencer par une GED trop complexe. Le bon point de départ est :

- un explorateur documentaire chantier ;
- droits simples mais robustes ;
- dépôt sécurisé ;
- archives lisibles ;
- promotion depuis Communication.

## Première tranche de développement conseillée

Si on lance le développement maintenant, l'ordre recommandé est :

1. permissions + tables `doc_folders`, `doc_documents`, `doc_document_versions` ;
2. stockage dédié `storage/documents/` ;
3. page `documentation.php` avec liste des espaces ;
4. page chantier `documents_chantier.php?chantier_id=...` ;
5. upload/téléchargement sécurisé ;
6. archivage documentaire simple ;
7. pont avec Communication.
