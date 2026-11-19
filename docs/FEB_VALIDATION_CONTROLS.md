# Contrôles FEB et décaissements

## Validation

- Les formulaires Finances et détail FEB transmettent un jeton de session CSRF.
- Un verrou MySQL par FEB empêche deux décisions simultanées depuis la validation Finances. Une FEB approuvée, rejetée ou annulée ne peut pas être approuvée à nouveau.
- Les mises à jour finales du modèle comparent aussi le statut lu : une décision concurrente entraîne un refus.
- La réservation des fonds utilise le montant définitif, y compris lorsque les prix initiaux sont retenus. Une facture annulée ou une date calendrier invalide ne peut pas financer la demande.
- L'approbation, la réservation, le BCI et la fiche liée restent dans une transaction. Les préparations de schéma ont lieu avant celle-ci. Les notifications d'approbation sont émises après le commit.
- Une sélection explicite de fournisseur remplace les valeurs historiques, quel que soit son numéro. Le nom officiel d'une personne morale se normalise dans la fiche fournisseur via FNE.
- La synchronisation d'un montant refuse les fiches payées et les familles comportant plusieurs fiches, pour conserver les paiements partiels et les reliquats.

## Paiement et régularisation

Les paiements total et partiel passent par un verrou MySQL commun. Le paiement total utilise désormais une transaction et relit la fiche complète sous verrou, notamment sa date minimale et sa référence pour contrôler le bon de réception. Les préparations SQL du journal et du bon de réception ne doivent jamais provoquer de commit implicite dans une transaction active.

Le contrôle comptable d'une régularisation verrouille le dossier et accepte seulement une demande transmise, non annulée et en attente. Une approbation exige une signature et une preuve de paiement. Une deuxième décision ne remplace pas la première ; une correction passe par une nouvelle transmission des documents.

## Formulaire et FNE

Le titre du devis se renseigne à la première étape et préremplit les détails de la fiche à la dernière. Une personnalisation des détails est conservée, avec un bouton pour reprendre le titre. Le serveur utilise aussi le titre si les détails transmis sont vides. Le thème du formulaire est clair, pleine largeur et adaptatif ; le thème sombre reste lié au choix de thème existant.

Les réponses FNE HTTP 404 `company_not_registered` et `company_not_found` produisent des messages métier distincts d'une panne réseau. Une réponse négative ne permet pas d'enregistrer une identité fiscale comme vérifiée.

## Vérification

```sh
php tests/FebWorkflowGuardTest.php
php tests/FebSimplifiedWorkflowTest.php
php tests/FebPaymentControlTest.php
php tests/SupplierIdentityTest.php
FEB_WORKFLOW_DB_TEST=1 php tests/FebWorkflowDatabaseTest.php
```

Le test MySQL est volontaire et utilise exclusivement des tables TEMPORARY pour les écritures : annulation transactionnelle, protection des montants payés/partiels, contrôle comptable et exclusion entre deux connexions. Il ne crée ni FEB réelle ni paiement réel. Le test de paiement utilise un PDO simulé pour vérifier les blocages et la fermeture des transactions, y compris l'échec SQL.

Ouvrir `tests/FebFormExperienceTest.html` dans un navigateur vérifie le préremplissage, la conservation des détails personnalisés, la recherche dans le même champ et le dépôt de fichier. La recette visuelle peut également utiliser un rendu isolé du template avec des données fictives.

Ces régressions ne remplacent pas une recette métier complète avec les profils et justificatifs propres à l'exploitation. Aucun paiement réel n'est nécessaire pour les exécuter.
