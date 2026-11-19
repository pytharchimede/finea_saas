# Module Gestion des Ressources Humaines (RH) — ERP Banamur

Ce document décrit le module RH de l'ERP Banamur : accès, fonctionnalités, flux de travail et paramétrage.

---

## Accès à l'application

### Étape 1 — Page de connexion

1. Ouvrir un navigateur et saisir l'adresse **https://projet.fidest.ci**
2. La page de connexion s'affiche avec les logos BANAMUR et FIDEST.
3. Renseigner l'**adresse e-mail professionnelle** et le **mot de passe**, puis cliquer sur **Se connecter**.

> En cas d'oubli du mot de passe, cliquer sur **Mot de passe oublié ?** sous le formulaire pour déclencher la procédure de réinitialisation.

### Étape 2 — Portail de sélection des modules

Après authentification, l'ERP affiche le **Portail de sélection** : une grille de cartes représentant chaque espace de travail auquel l'utilisateur a accès.

Les modules visibles dépendent des permissions accordées. Les modules grisés (verrouillés) ne sont pas accessibles avec le compte actuel.

| Module | Permission requise |
|---|---|
| Dashboard Direction Générale | `acces_direction` ou `acces_admin` |
| Module Devis | `acces_dashboard`, `acces_devis`, etc. |
| Approbation Fiches de Décaissement | `acces_decaissement`, `acces_approbation`, etc. |
| Gestion Déboursé | `modifier_debourse`, `acces_decaissement` |
| Espace Comptabilité | `acces_compta` |
| Espace Trésorerie | `acces_point_financier` |
| Suivi Budgétaire Chantier | `acces_budgets_chantiers`, `acces_direction` |
| Suivi Planning Chantier | `acces_planning`, `acces_budgets_chantiers` |
| **Gestion des Ressources Humaines** | **`acces_rh`** ou `acces_admin` |
| Gestion des fournisseurs | `acces_fournisseur`, `acces_admin` |
| Facturation | `acces_facturation`, `acces_admin` |
| Emails / Mailing | `acces_mail`, `acces_admin` |
| Communication | `acces_communication`, `acces_admin` |
| CRM | `acces_crm`, `acces_direction` |

### Étape 3 — Accéder au module RH

Cliquer sur la carte **Gestion des Ressources Humaines** (icône groupe, fond rose). L'ERP redirige vers le Tableau de bord RH (`rh/dashboard_rh.php`).

> L'accès à chaque page du module est protégé individuellement. Si une page n'est pas accessible, contacter l'administrateur pour obtenir la permission correspondante (`acces_rh`).

---

## 1. Tableau de bord RH

**URL :** `rh/dashboard_rh.php`

Point d'entrée du module. Il affiche une synthèse de l'effectif :

| Indicateur | Description |
|---|---|
| Total personnel | Nombre total de collaborateurs enregistrés |
| Personnel actif | Nombre de collaborateurs avec un statut actif |
| Services | Nombre de services distincts occupés |

### Raccourcis disponibles en haut de page

| Bouton | Destination |
|---|---|
| Liste du personnel | `liste_personnel_rh.php` |
| Intégrer un collaborateur | `integration_personnel_rh.php` |
| Ordres de mission | `ordres_mission_rh.php` |
| Pointage journalier | `pointage_journalier_rh.php` |
| Paie | `paie_rh.php` |
| Paramétrage RH | `parametrage_rh.php` |

---

## 2. Liste du personnel (Annuaire RH)

**URL :** `rh/liste_personnel_rh.php`

Affichage paginé (12 par page) de tous les collaborateurs avec recherche multi-critères.

### Filtres disponibles

| Filtre | Description |
|---|---|
| Recherche libre (`q`) | Recherche sur le nom, le matricule ou le numéro de téléphone |
| Service | Filtrer par service/département |
| Fonction | Filtrer par poste/fonction |
| Statut | Filtrer par type de contrat (CDI, CDD, Stage, Vacataire, Autre) |
| Genre | Masculin / Féminin |
| Site | Filtrer par site d'affectation |
| Périmètre (`scope`) | **Actifs** (défaut) · **Inactifs** · **Tous** |

### Depuis la liste, il est possible de :

- Consulter le dossier complet d'un collaborateur (clic sur la fiche).
- Accéder directement à la sortie, au pointage mensuel ou à la paie d'un collaborateur.

---

## 3. Intégration d'un collaborateur

**URL :** `rh/integration_personnel_rh.php`

Formulaire de création d'un nouveau dossier RH.

### Champs du formulaire

**Identité professionnelle**

| Champ | Obligatoire | Remarque |
|---|---|---|
| Matricule | Non | Identifiant interne (laissé vide si généré automatiquement) |
| Nom complet | **Oui** | Prénom et nom |
| Genre | Non | Masculin / Féminin |
| Service | Non | Sélectionner dans la liste (paramétrable) |
| Fonction | Non | Sélectionner dans la liste (paramétrable) |
| Statut contractuel | Non | CDI, CDD, Stage, Vacataire, Autre |
| Site d'affectation | Non | Sélectionner dans la liste (paramétrable) |
| Date d'embauche | Non | Date de signature du contrat |
| Date de prise de poste | Non | Date de début effectif |
| Durée du contrat (mois) | Non | Applicable aux CDD et stages |

**Coordonnées**

| Champ | Obligatoire |
|---|---|
| Téléphone | Non |
| Email | Non |

**État civil & famille**

| Champ | Obligatoire |
|---|---|
| Date de naissance | Non |
| Situation matrimoniale | Non |
| A des enfants | Non |
| Nombre d'enfants | Non |
| Nom du père | Non |
| Téléphone du père | Non |
| Nom de la mère | Non |
| Téléphone de la mère | Non |

**Documents et numéros légaux**

| Champ | Obligatoire |
|---|---|
| Numéro CNI | Non |
| Numéro CNPS | Non |

**Contact d'urgence**

| Champ | Obligatoire |
|---|---|
| Nom du contact d'urgence | Non |
| Téléphone du contact d'urgence | Non |

Après remplissage, cliquer sur **Enregistrer**. Un message de confirmation apparaît et l'utilisateur est redirigé vers le dossier du nouveau collaborateur.

---

## 4. Dossier personnel

**URL :** `rh/dossier_personnel_rh.php?id={id}`

Fiche complète d'un collaborateur. Accessible depuis la liste du personnel.

Permet de :
- **Consulter** toutes les informations RH (identité, contrat, numéros légaux, contacts).
- **Modifier** n'importe quel champ — les mêmes champs que l'intégration sont disponibles, avec en plus `Date de fin de contrat` et `Motif de sortie`.
- **Accéder aux actions rapides** : sortie, pointage mensuel, paie, export contrat.

Après modification, cliquer sur **Enregistrer le dossier**. Toute modification est tracée dans le journal d'audit.

---

## 5. Sortie de personnel

**URL :** `rh/sortie_personnel_rh.php?id={id}`

Enregistrement de la fin de collaboration avec un employé. Accessible depuis le dossier ou la liste.

### Procédure

1. Sélectionner un **motif de sortie** dans la liste déroulante (motifs configurés dans le paramétrage).
2. Renseigner la **date de fin**.
3. Cliquer sur **Confirmer la sortie**.

L'employé passe en statut **inactif** et n'apparaît plus dans la vue « Actifs » de l'annuaire.

> Il est possible de le retrouver en filtrant par périmètre **Inactifs** ou **Tous** dans la liste du personnel.

---

## 6. Ordres de mission

### 6.1 Liste des ordres de mission

**URL :** `rh/ordres_mission_rh.php`

Affiche tous les ordres de mission avec filtres :

| Filtre | Description |
|---|---|
| Recherche libre | Nom du collaborateur, destination, objet |
| Statut | Brouillon, Soumis, Approuvé, En déplacement, Retourné, Clôturé, Annulé |

### Statuts possibles

| Code | Libellé | Signification |
|---|---|---|
| `draft` | Brouillon | En cours de rédaction |
| `submitted` | Soumis | En attente de validation |
| `approved` | Approuvé | Validé, mission autorisée |
| `departed` | En déplacement | Collaborateur parti en mission |
| `returned` | Retourné | Collaborateur de retour |
| `closed` | Clôturé | Mission terminée et soldée |
| `cancelled` | Annulé | Mission annulée |

### 6.2 Créer / Gérer un ordre de mission

**URL :** `rh/ordre_mission_rh.php` (création) · `rh/ordre_mission_rh.php?id={id}` (détail)

Un ordre de mission contient :
- Le **collaborateur** concerné
- La **destination** et l'**objet** de la mission
- Les **dates** de départ et retour prévues
- Le **chantier** associé (optionnel)
- Les **lignes de dépenses** prévisionnelles (transport, hébergement, perdiems, etc.)

Le cycle de vie suit les statuts décrits ci-dessus. Chaque changement de statut est tracé (historique des événements visible sur la fiche).

---

## 7. Pointage

### 7.1 Pointage journalier

**URL :** `rh/pointage_journalier_rh.php`

Saisie des présences pour **une date donnée**. Sélectionner la date en haut de page (par défaut : aujourd'hui).

Pour chaque collaborateur, renseigner :

| Colonne | Description |
|---|---|
| Statut | Présent, Absent, Congé, Férié, Mission, etc. |
| Jours travaillés | Nombre de jours travaillés (0 à 1, décimal accepté) |
| Heures supplémentaires | Nombre d'heures sup ce jour |
| Heure d'arrivée | Format HH:MM |
| Heure de départ | Format HH:MM |
| Note | Commentaire libre |

**Application groupée :** Le bouton **Appliquer à tous les jours ouvrés du mois** reproduit les statuts saisis sur tous les jours ouvrés de la période. Particulièrement utile pour les absences ou congés d'un mois entier.

Cliquer sur **Enregistrer** pour valider la saisie de la journée.

### 7.2 Pointage mensuel

**URL :** `rh/pointage_mensuel_personnel_rh.php`

Grille calendrier d'un collaborateur sur un mois complet. Permet de :
- Consulter jour par jour les statuts déjà saisis.
- Vérifier les récapitulatifs (total jours travaillés, total heures sup).

Sélectionner le **collaborateur** et la **période** (mois/année) en haut de page.

### 7.3 Exports du pointage

| Export | URL | Format |
|---|---|---|
| PDF | `rh/export_pointage_pdf.php` | PDF imprimable |
| Excel | `rh/export_pointage_excel.php` | Classeur Excel (.xlsx) |

Les deux exports sont filtrables par période et collaborateur.

---

## 8. Jours fériés

**URL :** `rh/feries_rh.php`

Gestion du calendrier des jours fériés utilisés dans les calculs de pointage et de paie.

### Ajouter un férié

1. Sélectionner la **période** (mois/année).
2. Saisir la **date** du férié et un **libellé** descriptif.
3. Cliquer sur **Ajouter**.

### Supprimer un férié

Cliquer sur l'icône de suppression en face du férié concerné.

> Les jours fériés en cours de période impactent directement le récapitulatif de pointage mensuel et le calcul de la paie.

---

## 9. Gestion de la paie

### 9.1 Calcul de la paie

**URL :** `rh/paie_rh.php`

Permet de calculer et d'enregistrer la paie d'un collaborateur pour une période donnée.

#### Procédure

1. Sélectionner le **collaborateur** dans la liste déroulante.
2. Sélectionner la **période** (mois/année).
3. Le système charge automatiquement :
   - Les **valeurs par défaut** issues du type de contrat (salaire de base, taux heures sup, congés payés…).
   - Le **récapitulatif de pointage** du mois (jours travaillés, heures sup).
4. Vérifier et ajuster si nécessaire :
   - Montant des **avances sur salaire**.
   - **Autres retenues** diverses.
5. Cliquer sur **Calculer** pour voir le détail du salaire net.
6. Cliquer sur **Enregistrer le bulletin** pour sauvegarder et générer le bulletin.

### 9.2 Bulletin de paie

**URL :** `rh/bulletin_paie_rh.php?id={id}`

Affiche le bulletin de paie mis en page pour impression. Contient :
- Identité de l'employeur et du collaborateur.
- Période concernée.
- Détail des éléments bruts, retenues et salaire net.
- Récapitulatif du pointage du mois.

Utiliser la **fonction d'impression du navigateur** (Ctrl+P / Cmd+P) pour produire un PDF ou imprimer physiquement.

---

## 10. Validation des demandes RH

**URL :** `rh/validation_demandes_rh.php`

Espace de traitement des demandes légales soumises par les collaborateurs (demandes d'attestation, demandes de congé, etc.).

### Statuts possibles

| Code | Libellé |
|---|---|
| `submitted` | En attente |
| `approved` | Approuvée |
| `rejected` | Refusée |
| `cancelled` | Annulée |

### Actions disponibles

- **Approuver** une demande en attente.
- **Rejeter** une demande avec un motif.
- **Consulter** les pièces jointes (justificatifs uploadés).

---

## 11. Paramétrage RH

### 11.1 Référentiels de base

**URL :** `rh/parametrage_rh.php`

Gestion des listes de référence utilisées dans les formulaires du module. Quatre onglets :

| Onglet | Contenu | Actions |
|---|---|---|
| **Services** | Liste des services/départements | Ajouter, renommer, supprimer |
| **Fonctions** | Liste des postes/fonctions | Ajouter, renommer, supprimer |
| **Statuts** | Types de contrats | Ajouter, renommer, supprimer |
| **Sites** | Sites/agences d'affectation | Ajouter, renommer, supprimer |

> La suppression d'un référentiel est bloquée s'il est utilisé par au moins un dossier personnel.

### 11.2 Identité de l'employeur et signataires des contrats

**URL :** `rh/parametrage_contrat_rh.php`

Paramètres documentaires utilisés sur les contrats PDF générés :

| Paramètre | Description |
|---|---|
| Nom de l'employeur | Raison sociale qui apparaît sur le contrat |
| Signataire DG | Nom et titre du Directeur Général |
| Signataire RH | Nom et titre du Responsable RH |
| Numérotation | Format du numéro de contrat (préfixe CTRH + année + séquence) |

Après modification, cliquer sur **Enregistrer**. Les nouveaux paramètres s'appliquent immédiatement aux prochains contrats générés.

### 11.3 Règles de paie par type de contrat

**URL :** `rh/parametrage_paie_rh.php`

Coefficients et règles de calcul appliqués automatiquement lors du calcul de la paie selon le type de contrat.

| Type de contrat | Description |
|---|---|
| **CDD** | Contrat chantier avec prime de précarité automatique |
| **CDI** | Contrat permanent avec repos payé et valorisation des heures sup |
| **STAGE** | Convention de stage (rythme réduit, heures sup neutralisées par défaut) |
| **VACATAIRE** | Paiement à la vacation, congé non rémunéré par défaut |
| **AUTRE** | Profil libre pour les cas non standards |

Pour chaque type, les paramètres configurables sont notamment : salaire de base, taux heures supplémentaires, part de congés payés, prime de précarité, CNPS patronal/salarial.

Cliquer sur **Enregistrer** pour valider les modifications.

### 11.4 Motifs de sortie

**URL :** `rh/parametrage_motifs_sortie_rh.php`

Liste des motifs utilisés lors de la sortie d'un collaborateur (démission, fin de contrat, licenciement, décès, etc.).

Actions disponibles : ajouter un motif, l'activer/désactiver, le renommer.

---

## 12. Export de contrat

**URL :** `rh/export_contrat_rh.php?id={id}`

Génère le contrat PDF d'un collaborateur à partir des informations du dossier personnel et des paramètres documentaires.
Le document inclut un filigrane **Brouillon** ou **Original signé** selon l'état du dossier, ainsi que le numéro de contrat unique au format CTRH.

---

## Résumé des URLs du module

| Page | URL |
|---|---|
| Tableau de bord RH | `rh/dashboard_rh.php` |
| Liste du personnel | `rh/liste_personnel_rh.php` |
| Intégrer un collaborateur | `rh/integration_personnel_rh.php` |
| Dossier personnel | `rh/dossier_personnel_rh.php?id={id}` |
| Sortie de personnel | `rh/sortie_personnel_rh.php?id={id}` |
| Liste des ordres de mission | `rh/ordres_mission_rh.php` |
| Ordre de mission (détail) | `rh/ordre_mission_rh.php?id={id}` |
| Pointage journalier | `rh/pointage_journalier_rh.php` |
| Pointage mensuel | `rh/pointage_mensuel_personnel_rh.php` |
| Export pointage PDF | `rh/export_pointage_pdf.php` |
| Export pointage Excel | `rh/export_pointage_excel.php` |
| Jours fériés | `rh/feries_rh.php` |
| Paie | `rh/paie_rh.php` |
| Bulletin de paie | `rh/bulletin_paie_rh.php?id={id}` |
| Validation des demandes | `rh/validation_demandes_rh.php` |
| Paramétrage RH | `rh/parametrage_rh.php` |
| Paramétrage contrat | `rh/parametrage_contrat_rh.php` |
| Paramétrage paie | `rh/parametrage_paie_rh.php` |
| Paramétrage motifs sortie | `rh/parametrage_motifs_sortie_rh.php` |
| Export contrat PDF | `rh/export_contrat_rh.php?id={id}` |
