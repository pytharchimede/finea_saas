# Reprise IT du module Documentation & Archives

## Positionnement

Le module est un référentiel documentaire transverse. Il gère dossiers, documents, versions, partages, téléchargements, archivage et prévisualisation.

Le point d entrée principal est documentation.php, avec une logique centrale dans model/DocumentManager.php.

## Fichiers clés

- documentation.php
- documentation_upload.php
- documentation_download.php
- documentation_action_api.php
- documentation_conflict_api.php
- documentation_share_api.php
- documentation_share.php
- documentation_folder_share.php
- documentation_archive_api.php
- documentation_bulk_download.php
- model/DocumentManager.php

## Tables principales

Le modèle assure son propre schéma via ensureTables.

Tables principales :

- doc_folders
- doc_documents
- doc_document_versions
- doc_document_shares
- doc_folder_shares

## Capacités clés

- arborescence documentaire ;
- classement par espace et contexte ;
- version courante par document ;
- contrôle d audience ;
- partage par jeton ;
- archivage ;
- prévisualisation de plusieurs formats ;
- téléchargements groupés.

## Permissions

Le module s appuie au minimum sur :

- acces_documentation ;
- document_upload ;
- document_edit ;
- document_archive ;
- document_admin.

## Flux d import

documentation_upload.php :

- contrôle le droit d import ;
- prépare le payload ;
- peut créer automatiquement la hiérarchie de dossiers ;
- appelle DocumentManager pour enregistrer le document et sa version.

## Flux de partage

documentation_share_api.php :

- contrôle le droit de partage ;
- crée un partage document ou dossier ;
- applique les permissions de partage ;
- génère un lien par jeton ;
- notifie éventuellement un utilisateur interne ;
- journalise l action.

## Risques de reprise

- casser la structure des tables gérées automatiquement ;
- modifier les règles d audience sans vérifier les filtres d affichage ;
- oublier les implications de partage externe ;
- casser la cohérence entre document courant et version courante.

## Reprise recommandée

1. lire DocumentManager en priorité ;
2. tester création dossier, upload, renommage, déplacement, archivage ;
3. tester partage document et partage dossier ;
4. tester téléchargement et prévisualisation ;
5. vérifier les restrictions par permission.