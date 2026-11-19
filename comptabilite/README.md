# Gestion comptable

Module accessible depuis `selection_portail.php` → **Gestion comptable**. L’ancien module de décaissement est présenté sous le libellé **Traitement des paiements** ; ses clés et ses liens restent compatibles avec les accès existants.

## Circuit des décaissements

1. Ouvrir « Décaissements à comptabiliser », puis un point payé.
2. Sélectionner une fiche. Le module vérifie le point payé, la certification et la signature DG, le paiement effectif des fiches, ainsi que la correspondance des montants avec les lots de paiement lorsqu’ils existent.
3. Consulter les signatures, la preuve du paiement, la facture et le PV disponible. Les références BCI/FNE/PV sont affichées ; une simple référence textuelle ne vaut pas pièce jointe.
4. Choisir exercice, journal, date, compte débité, compte de trésorerie crédité, référence et libellé. Le montant est repris du décaissement. Fournir les pièces de paiement et de dépense manquantes, puis confirmer les trois contrôles documentaires.
5. Enregistrer le brouillon. Les pièces consultables et les compléments sont copiés dans la base comptable, avec empreinte SHA-256, source, contrôleur et date. Le brouillon peut être réparti entre plusieurs comptes, en conservant le montant total et le crédit net de trésorerie du paiement.
6. Valider. Le module revérifie l’éligibilité, l’empreinte du dossier, l’équilibre et la période. L’écriture reçoit un numéro et alimente les livres.

Une fiche déjà réservée par un brouillon ou une écriture validée ne peut être comptabilisée une seconde fois, même depuis un autre point. L’annulation du brouillon ou la contrepassation libère sa source pour une correction ; l’historique est conservé. Les points rapprochés par l’ancien circuit peuvent être utilisés si la certification, la signature DG, le paiement effectif et les justificatifs requis sont disponibles. Un statut `paid` seul ne suffit pas.

Les écritures hors décaissement (ventes, inventaire, à-nouveaux, engagements…) restent disponibles via « Écritures » → « Autre écriture ». Aucune imputation métier n’est devinée automatiquement.

## Champs et pièces jointes

Tous les champs de sélection proposent une saisie avec recherche dans le même champ, par numéro ou libellé, sans distinction d’accents. Les touches fléchées et Entrée permettent de choisir ; Échap ferme la liste. Une recherche libre ne peut pas être envoyée à la place d’un compte sélectionné. Les champs ajoutés avec une nouvelle ligne comptable disposent du même comportement.

Les justificatifs et l’import CSV acceptent le glisser-déposer ou le choix classique de fichier. Le nom, la taille et l’aperçu des images sont affichés, avec retrait ou remplacement avant l’envoi. Les limites restent de 8 Mo par justificatif et 1 Mo pour le CSV, un fichier par zone ; les vérifications serveur sont conservées. Ces composants sont inclus localement, sans dépendance à un CDN. Sans JavaScript, les champs natifs restent utilisables.

## Installation et droits

- PHP 8+, PDO MySQL, mbstring et fileinfo ; tables InnoDB.
- Création additive automatique des tables `accounting_*` dès le premier accès au portail après mise en ligne (avec nouvelle tentative à l’ouverture directe du module), selon `Database/add_accounting_module.sql`. Le service charge ensuite le plan et les journaux. Le fichier SQL seul ne charge pas le plan.
- Les tables existantes des décaissements sont lues ; le module ne déclenche ni signature, ni paiement, ni notification et ne modifie pas leur statut.
- Accès explicite `acces_comptabilite`, puis droits détaillés définis dans `model/AccountingPermissions.php`. Les anciens droits `acces_compta` (paiements) et `acces_direction` n’accordent plus d’accès au module. L’administrateur conserve tous les droits. Les nouveaux droits sont désactivés par défaut et attribuables dans Ajouter / Modifier un utilisateur.
- Consultation des écritures, livres, sources, justificatifs et historique séparées ; saisie, validation, annulation, contrepassation, plan, paramétrage, clôture et export indépendants. Chaque action vérifie aussi ses prérequis de consultation. Le droit d’export masque l’impression applicative ; il ne peut empêcher la copie ou l’impression par le navigateur de données que l’utilisateur est autorisé à consulter.
- Contrôles serveur sur chaque vue, action POST, téléchargement et export. Les permissions sont rechargées depuis la base à chaque requête, y compris après révocation. Le portail affiche le module uniquement aux personnes autorisées.
- Les colonnes sont créées automatiquement à zéro au chargement des droits, du portail ou des formulaires utilisateurs ; aucune attribution automatique de privilèges comptables aux anciens profils.
- Stockage des justificatifs en BLOB, maximum 8 Mo par fichier ; régler `max_allowed_packet` MySQL à au moins 16 Mo et les limites PHP d’upload/post en conséquence. Sauvegarder toutes les tables `accounting_*`, documents inclus.
- L’exercice de l’année courante (1er janvier–31 décembre) est créé automatiquement, y compris au changement d’année. Les exercices existants, clôturés ou décalés sont conservés. Si un calendrier personnalisé chevauche l’année sans couvrir la date courante, aucun exercice concurrent n’est inventé : compléter ce calendrier dans les paramètres. Aucun mouvement fictif ni validation d’écriture n’est déclenché.
- Aucun import SQL manuel n’est nécessaire : déployer les fichiers suffit ; l’initialisation se lance au premier accès web. Elle est répétable, protégée contre les premières connexions simultanées et retentée après une interruption. En cas de problème, les autres portails restent accessibles et le module comptable propose de réessayer.

## Règles et états

Montants en centièmes de XOF entiers, sans calcul flottant pour les écritures. Journaux ACH, VEN, BQ, CAI, OD, AN et HB, et création de journaux supplémentaires. Plan intégral fourni : voir `data/SOURCES.md`.

Validation atomique, numérotation séquentielle par exercice et journal, contrôle de concurrence et des doubles soumissions, chronologie des dates validées, écritures définitives corrigées par contrepassation. Le verrou global du livre sérialise les mutations comptables. L’audit des modifications de brouillon conserve les états avant/après ; validation, annulation, contrepassation, comptes et clôtures sont tracés.

Journal, grand livre et balance sont calculés sur les écritures validées avec filtres exercice/période/journal/compte, export CSV et impression/PDF via navigateur. Les ouvertures incluent les mouvements antérieurs de l’exercice. Les à-nouveaux inter-exercices et les écritures d’inventaire restent à saisir et justifier. La clôture verrouille définitivement une période achevée ; elle ne produit ni bilan réglementaire, ni liasse fiscale, ni rapprochement bancaire automatique.

## Vérification

`php tests/AccountingServiceTest.php` exécute les contrôles unitaires sans base. Pour les tests d’intégration, utiliser **une base MySQL vide et jetable différente par script** :

```sh
ACCOUNTING_TEST_DSN='mysql:host=localhost;dbname=compta_test;charset=utf8mb4' ACCOUNTING_TEST_USER=test ACCOUNTING_TEST_PASSWORD=... php tests/AccountingServiceTest.php
ACCOUNTING_TEST_DSN='mysql:host=localhost;dbname=compta_source_test;charset=utf8mb4' ACCOUNTING_TEST_USER=test ACCOUNTING_TEST_PASSWORD=... php tests/AccountingDisbursementTest.php
```

`AccountingAutoInstallTest.php`, avec sa propre base vide, vérifie une première installation simultanée, le chargement du plan, la création de l’exercice courant, le changement d’année, le respect des périodes existantes et la reprise d’une installation interrompue.

`php tests/AccountingAccessTest.php` vérifie les droits des routes et le catalogue du portail sans base. `AccountingConcurrencyTest.php`, avec sa propre base vide et le même DSN, vérifie deux validations simultanées et l’absence de doublon de numérotation.

Les tests refusent une base contenant déjà des tables. Le second crée des points/fiches fictifs uniquement dans la base de test et des pièces temporaires, sans lecture de la base métier. Ils couvrent pièces manquantes, déséquilibre d’un centime, double validation, période fermée, contrepassation, modification de pièces après contrôle, montant de paiement et doublons de source, ainsi que tous les codes du plan fourni.

Le remplacement du plan (migration 5) reprend exactement les 1 332 codes et libellés de `data/plan_fourni.csv`. Il s’exécute automatiquement au premier accès après déploiement. Les comptes hors liste non utilisés sont supprimés ; ceux liés à des écritures sont archivés et retirés des choix de saisie. Les écritures et justificatifs sont conservés. Une empreinte du fichier empêche de répéter cette remise à zéro lors des réparations ultérieures du schéma. Test dédié : `tests/AccountingChartReplacementTest.php` avec une base jetable.
