# Guide d'utilisation - Nouveau système de décaissement BANAMUR

## 📋 Vue d'ensemble

Le nouveau système de décaissement BANAMUR a été entièrement repensé pour utiliser l'architecture moderne basée sur les tables `_banamur`. Voici les principales améliorations :

### ✨ Nouvelles fonctionnalités

1. **Formulaire moderne et intuitif** (`formulaire_demande_decaissement_new.php`)
2. **Système de déboursé précis** basé sur `ligne_debourse_banamur`
3. **Calculs automatiques** des montants prévu/décaissé/restant
4. **Visualisation détaillée** des fiches avec historique
5. **Interface responsive** avec animations

### 🏗️ Architecture

```
chantier_banamur (chantier principal)
    ↓
devis_banamur (devis du chantier)
    ↓
ligne_devis_banamur (lignes du devis)
    ↓
debourse_banamur (déboursés par ligne de devis)
    ↓
ligne_debourse_banamur (détail des dépenses)
    ↓
fiche_decaissement (demandes de décaissement)
```

## 🚀 Utilisation

### 1. Création d'une nouvelle demande

1. **Accès** : Dashboard → "Nouveau Formulaire" ou directement via `/formulaire_demande_decaissement_new.php`

2. **Étapes du formulaire** :

   - **Étape 1** : Sélection de l'entreprise (FIDEST, BANAMUR, BUREAU)
   - **Étape 2** : Informations personnelles (nom, téléphone)
   - **Étape 3** : Sélection du chantier et déboursé précis
   - **Étape 4** : Montant et mode de paiement
   - **Étape 5** : Justificatifs (photo, CNI)
   - **Étape 6** : Récapitulatif et soumission

3. **Sélection intelligente** :
   - Chantiers chargés dynamiquement depuis `chantier_banamur`
   - Déboursés filtrés par chantier sélectionné
   - Lignes de déboursé détaillées avec catégories
   - Calcul automatique des montants disponibles

### 2. Visualisation des fiches

- **Page de détails** : `voir_fiche_details.php?num_fiche=XXX`
- **Informations affichées** :
  - Détails du bénéficiaire et du chantier
  - Informations budgétaires (prévu/décaissé/restant)
  - Photos et justificatifs
  - Historique des actions
  - Statut de validation

### 3. Gestion des montants

Le système calcule automatiquement :

- **Montant prévu** : Montant de la ligne de déboursé
- **Montant déjà décaissé** : Somme des fiches décaissées pour cette ligne
- **Montant restant** : Prévu - Décaissé
- **Alerte de dépassement** : Si demande > restant

## 🛠️ Fichiers et composants

### Scripts PHP principaux

| Fichier                                   | Description                     |
| ----------------------------------------- | ------------------------------- |
| `formulaire_demande_decaissement_new.php` | Nouveau formulaire multi-étapes |
| `voir_fiche_details.php`                  | Page de visualisation détaillée |
| `test_nouveau_systeme.php`                | Page de tests et validation     |

### API Endpoints

| Endpoint                                 | Description                     |
| ---------------------------------------- | ------------------------------- |
| `request/get_chantiers.php`              | Liste des chantiers disponibles |
| `request/get_debourses_by_chantier.php`  | Déboursés d'un chantier         |
| `request/get_lignes_debourse.php`        | Lignes d'un déboursé            |
| `request/get_ligne_debourse_details.php` | Détails + calculs de montants   |
| `request/submit_new_fiche.php`           | Soumission du formulaire        |
| `request/get_fiche_details.php`          | Détails d'une fiche existante   |

### Base de données

- **Table principale** : `fiche_decaissement`
- **Table de traçabilité** : `trace_fiche`
- **Script de création** : `database/create_fiche_decaissement.sql`

## 🔧 Configuration et maintenance

### 1. Installation

1. Exécuter le script SQL : `database/create_fiche_decaissement.sql`
2. Vérifier les permissions sur les dossiers :
   - `uploads/photo/` (photos des bénéficiaires)
   - `uploads/cni/` (pièces d'identité)
3. Tester avec : `test_nouveau_systeme.php`

### 2. Personnalisation

#### Modes de paiement

Modifier dans `formulaire_demande_decaissement_new.php` :

```html
<div class="payment-card">
  <input
    type="radio"
    id="nouveau_mode"
    name="mode_paiement"
    value="Nouveau Mode"
  />
  <label for="nouveau_mode">
    <img src="payment_icon/logo_nouveau.png" alt="Nouveau Mode" />
    <span>Nouveau Mode</span>
  </label>
</div>
```

#### Entreprises

Modifier les cartes d'entreprise dans l'étape 1 du formulaire.

### 3. Intégration avec l'existant

Le nouveau système est compatible avec l'ancien via :

- Champ `affectation_id` pour compatibilité
- Support des anciens champs si nécessaire
- Coexistence possible des deux systèmes

## 🎯 Bonnes pratiques

### Pour les utilisateurs

1. **Toujours remplir tous les champs obligatoires**
2. **Vérifier les montants** avant soumission
3. **Utiliser des photos de qualité** pour les justificatifs
4. **Compléter les détails** pour faciliter la validation

### Pour les administrateurs

1. **Surveiller les alertes de dépassement**
2. **Vérifier régulièrement l'historique** des actions
3. **Maintenir les données de référence** (chantiers, déboursés)
4. **Sauvegarder la base de données** régulièrement

## 🐛 Dépannage

### Problèmes courants

1. **Chantiers non chargés**

   - Vérifier la table `chantier_banamur`
   - Contrôler les permissions de base de données

2. **Erreur de soumission**

   - Vérifier les permissions des dossiers d'upload
   - Contrôler la taille max des fichiers PHP

3. **Calculs incorrects**
   - Vérifier l'intégrité des données `ligne_debourse_banamur`
   - Contrôler les jointures SQL

### Tests de validation

Utiliser `test_nouveau_systeme.php` pour :

- Vérifier la connectivité des tables
- Tester les endpoints API
- Valider les calculs de montants
- Contrôler le fonctionnement général

## 📞 Support

Pour toute question ou problème :

1. Consulter d'abord ce guide
2. Utiliser la page de test pour diagnostiquer
3. Vérifier les logs d'erreurs PHP
4. Contacter l'équipe de développement avec les détails de l'erreur

---

_Guide mis à jour le $(date) pour la version 2.0 du système de décaissement BANAMUR_
