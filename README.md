# FINEA — ERP BANAMUR

![Version](https://img.shields.io/badge/version-v1.3.1-blue)
![Statut](https://img.shields.io/badge/statut-stable-green)
![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php)
![MySQL](https://img.shields.io/badge/MySQL-Database-orange?logo=mysql)

FINEA est un ERP interne développé pour BANAMUR, destiné à la gestion opérationnelle des activités BTP, financières, logistiques et RH.

---

# Présentation

FINEA centralise les processus clés de l’entreprise :

* Gestion des décaissements (FEB)
* Gestion des devis et chantiers
* Gestion des ressources humaines
* Gestion logistique
* Gestion documentaire
* Gestion des notifications intelligentes
* Intégration biométrique (ZKTeco)

L’objectif est d’améliorer :

* la traçabilité,
* la productivité,
* le contrôle opérationnel,
* la supervision financière,
* et la prise de décision.

---

# Version actuelle

## v1.3.1 — Gouvernance & pilotage

Cette version corrective prolonge la version majeure v1.3.0 avec :

* un audit ACL des pages, menus et permissions,
* un design system commun FINEA,
* un audit trail enrichi,
* un tableau de bord Direction Générale transverse,
* une page interne de version et de roadmap.

---

# Modules principaux

## 1. Décaissements (FEB)

* Création de fiches d’expression de besoin
* Workflow de validation
* Gestion des besoins internes et externes
* Décaissements d’urgence
* Régularisation obligatoire
* Blocages automatiques de conformité
* Suivi des statuts

---

## 2. Devis BTP

* Création de devis détaillés
* Gestion des lignes de devis
* Calcul automatique des montants
* Liaison devis ↔ chantier

---

## 3. Chantiers

* Suivi des activités
* Suivi budgétaire
* Liaison avec les décaissements
* Gestion opérationnelle terrain

---

## 4. Ressources humaines

* Gestion des employés
* Gestion des contrats
* Pointage biométrique
* Gestion des présences
* Base congés et absences
* Notifications RH

---

## 5. Module Logistique

* Gestion des engins
* Gestion des chauffeurs
* Gestion carburant
* Gestion maintenance
* Gestion incidents
* Gestion des voyages
* Gestion documentaire logistique
* Dashboard logistique

---

## 6. Gestion documentaire

* Upload et partage de fichiers
* Organisation par service
* Gestion des droits d’accès
* Historique documentaire

---

## 7. Notifications intelligentes

* Notifications popup
* Notifications sonores
* Notifications de sondage
* Paramétrage centralisé admin
* Paramétrage utilisateur
* Suivi des accusés de lecture

---

## 8. API Attendance (ZKTeco)

* Réception des pointages biométriques
* Enregistrement des appareils
* Historisation des présences
* Supervision des badgeuses
* Détection d’anomalies horaires

---

# API Attendance

## Endpoint

Production :

```txt
https://attendance.fidest.ci
```

Consommation :

```txt
https://projet.fidest.ci
```

---

## Fonctionnalités

* Réception automatique des logs des pointeuses
* Interface de test
* Auto-création des tables
* Synchronisation RH

---

## Documentation

```txt
ATTENDANCE_API.md
```

---

# Architecture technique

## Stack technique

* PHP natif orienté objet
* MySQL
* JavaScript
* Bootstrap

---

## Architecture logicielle

```txt
Controller → Service → Repository → Database
```

---

## Déploiement

* cPanel (production)
* GitHub (versioning)
* Déploiement Git automatisé

---

# Installation

## Prérequis

* PHP 7.4+
* MySQL
* Apache / Nginx

---

## Clonage

```bash
git clone <repo>
```

---

## Configuration

Configurer la base de données dans :

```txt
config/database.php
```

Le système peut créer automatiquement certaines tables si elles sont inexistantes.

---

# Workflow Git recommandé

```txt
feature/* → develop → test → main → release
```

* `main` = version stable
* `develop` = intégration
* `feature/*` = développement

---

# Versioning

Format utilisé :

```txt
vMAJEUR.MINEUR.CORRECTIF
```

---

# Historique des versions

## v1.2.0

* Module Logistique
* Notifications intelligentes
* Workflow FEB avancé
* Décaissements d’urgence
* Supervision badgeuses
* Stabilisation pointage RH

## v1.1.0

* Intégration Paie + Contrats

## v1.0.0

* Version stable avec API Attendance

---

# Roadmap

## v1.4.0

* IA décisionnelle
* Prévisions budgétaires avancées
* Assistant FINEA enrichi
* Analyse prédictive des chantiers
* Statistiques multi-sites

---

# Sécurité

* Gestion des accès basée sur les rôles
* Contrôles d’accès par module
* Journalisation des actions
* Blocages automatiques de conformité
* Contrôles financiers renforcés

---

# Auteur

Projet développé par :

## Service Informatique BANAMUR

Responsable :

* RSI / Développement ERP interne

---

# Licence

Projet interne — BANAMUR

Usage strictement professionnel.
