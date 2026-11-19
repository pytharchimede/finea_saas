# Fournisseurs français

Les formulaires partagés de création et la modification fournisseur proposent un pays fiscal : Côte d’Ivoire (par défaut pour les anciennes fiches) ou France.

- Côte d’Ivoire : le contrôle NCC/FNE reste obligatoire pour les personnes morales.
- France : saisir un SIRET (14 chiffres) ou un SIREN (9 chiffres), puis cliquer sur « Vérifier le SIRET / SIREN et remplir la fiche ». Un SIREN sélectionne le siège ; un SIRET sélectionne exactement cet établissement.
- La dénomination, l’adresse et la ville officielles sont récupérées. La forme juridique, le représentant, les contacts et les documents demandés par le circuit existant restent à compléter. La validation comptable est conservée.
- L’enregistrement renouvelle le contrôle côté serveur. Une réponse absente, incohérente, un établissement fermé ou une panne ne produisent pas de fiche vérifiée. Le NCC n’est pas requis pour une société française.
- La TVA française est facultative, normalisée et contrôlée sur son format et son SIREN, mais n’est pas validée auprès de VIES. Elle est affichée comme déclarative.
- Le SIRET est unique, y compris parmi les fiches archivées ; plusieurs établissements d’un même SIREN sont possibles.

API : https://recherche-entreprises.api.gouv.fr/docs/ (DINUM, accès public sans clé). Les sociétés non diffusibles peuvent être absentes. HTTPS, délais 5/15 secondes, pas de redirection, gestion HTTP 429. Aucune donnée de contact n’est inventée.

Le repository crée automatiquement les colonnes manquantes via son mécanisme existant : fiscal_country, siret (index unique), siren, vat_number, registry_verified_at, registry_response_json. Déployer les fichiers ensemble avec les droits ALTER habituels du module.

## Exemple Adobe

Utiliser France et `35038196800089` pour rechercher ADOBE SYSTEMS FRANCE SAS. Lors de la vérification du 17 octobre 2026, le registre retourne un établissement actif et l’adresse `94-96 94 RUE LAURISTON 75016 PARIS` (code postal 75016, distinct du code commune 75116). Aucune fiche Adobe n’est insérée automatiquement : retenir comme fournisseur comptable l’entité de la facture réelle et compléter les contacts vérifiés.

## Vérification

```sh
php tests/FrenchSupplierIdentityTest.php
node --check assets/js/supplier-fne.js
```

Le test français inclut les tests NCC existants, le choix précis d’établissement, les doublons, les identités falsifiées, les établissements fermés, les pannes et le changement de pays fiscal. Le contrôle réseau réel du SIRET Adobe est distinct des fixtures de test.

## Extension internationale — clients et fournisseurs

Le même panneau est disponible dans les créations/modifications clients et fournisseurs. Un code pays ISO à deux lettres accepte les pays étrangers. Méthodes :

- CI : FNE obligatoire, circuit existant conservé.
- France : registre DINUM par SIRET/SIREN.
- UE : VIES par TVA préfixée (DE pour Allemagne, IE pour Irlande, EL pour Grèce). Les coordonnées sont récupérées lorsqu’elles sont retournées. Une TVA valide reste distincte d’une identité approuvée : un justificatif est contrôlé par la comptabilité.
- US : SEC par CIK exact pour ses déclarants ; l’API retourne l’entité de ce CIK. Ne pas utiliser le CIK d’une maison mère pour une filiale facturante. Un EIN ne dispose pas ici d’un service public universel de vérification : il est contrôlé sur son format et validé sur justificatifs.
- Autres cas, dont immatriculations allemandes HRB/HRA : saisie du type et du numéro local, référence documentaire et approbation. Aucune API de registre non intégrée n’est présentée comme un contrôle automatique.

Les statuts séparent registry_verified, documentary_pending, documentary_verified et la TVA valid/unchecked. Pour les fournisseurs, le bouton de validation existant approuve aussi l’identité documentaire si sa référence est enregistrée. Pour les clients, seuls les droits acces_admin/acces_compta autorisent la case d’approbation documentaire. Une modification du nom, de l’adresse, des identifiants ou de la référence révoque cette approbation.

Les identifiants sont uniques par pays et type au sein de chaque référentiel ; un tiers peut exister comme client et fournisseur. Les créations CRM et les écritures via le modèle Client passent par le même service. Le formulaire CRM redirige vers le formulaire client unifié et exige donc l’accès au référentiel clients.

La migration clients passe en version 3 : nouvelles colonnes, index d’identité, remplacement des triggers d’identité et des gardes chantiers. Les clients étrangers en attente peuvent être sauvegardés, mais les opérations chantier restent bloquées jusqu’à leur validation. Les anciennes fiches conservent le pays fiscal CI jusqu’à correction explicite. Avant production, tester la migration et les triggers sur MariaDB avec une sauvegarde ; les tests SQL locaux couvrent le service sous SQLite et ne remplacent pas ce contrôle MariaDB.

Tests complémentaires : `php tests/InternationalBusinessIdentityTest.php` (extensions pdo_sqlite et mbstring), syntaxe des deux scripts JS. Les appels externes des tests sont simulés ; des appels réseau séparés ont confirmé Adobe, Google Ireland (VIES) et Alphabet (SEC), sans créer de fiche en production.


## Recherche adaptée et fournisseurs dérogatoires

Le formulaire fournisseur propose un seul champ « identifiant à rechercher ». Il est associé côté serveur au NCC (CI), SIRET/SIREN (France), numéro de TVA (VIES), CIK (SEC) ou identifiant local (contrôle documentaire). Le pays suggère la méthode ; les méthodes incompatibles sont désactivées. Le mode américain standard est documentaire, avec EIN proposé ; la recherche SEC reste un choix explicite.

Le répertoire utilise une interprétation commune (`SupplierIdentity::summary`) pour les badges, les compteurs, la recherche et les filtres. Une TVA confirmée n’équivaut pas à une identité validée. Les dérogations ont leur compteur distinct et ne sont jamais comptées comme vérifiées par une API.

« Fournisseur dérogatoire » est réservé aux comptes ayant acces_compta ou acces_admin, ou dont le rôle de session est exactement DG / directeur général. Ni acces_fournisseur, ni acces_direction seul, ni directeur général adjoint ne suffisent. Un jeton CSRF dédié est obligatoire. La création/modification nécessite le nom et un motif de 10 à 500 caractères ; coordonnées, identifiants et pièces habituels sont facultatifs. L’identité est marquée exception_approved, la fiche est approuvée par cet acteur, et la dérogation reste visible dans le dossier et le journal d’audit. Aucun appel au registre n’est effectué ; les marqueurs FNE/registre et identifiants précédents sont supprimés. Les contrôles sur les factures et preuves de paiement des décaissements restent applicables.

La protection est appliquée côté serveur, y compris en cas de POST forgé, changement de mode d’une fiche dérogatoire, validation, archivage ou activation en masse. Les utilisateurs ordinaires peuvent consulter et sélectionner une fiche déjà autorisée, mais ne peuvent pas la modifier.

Tests : `php tests/SupplierExceptionPolicyTest.php` et `node tests/SupplierIdentityLookupTest.cjs`. Le second vérifie le choix du champ sans dépendance externe. Le changement ne crée aucune colonne supplémentaire : il réutilise les champs d’identité de la version internationale.



## Pièces des personnes physiques et rapprochement RH

- Toute personne physique fournisseur doit avoir `identity_document_number` et une pièce jointe `cni_path`, y compris en dérogation comptabilité/DG/admin.
- Le numéro est canonisé (majuscules, sans séparateurs, zéros initiaux conservés), comparé à `personnel.num_cni` et à l'historique `personnel_identity_registry`. Une correspondance bloque l'enregistrement et l'approbation, sans communiquer de nom ni de dossier RH.
- Les anciennes fiches sans numéro doivent être régularisées ; aucun numéro n'est inventé à partir du fichier joint. Elles sont exclues de la sélection opérationnelle. Une inscription ou modification RH suspend les fournisseurs qui portent le même numéro.
- Les écritures fournisseurs et RH utilisent le même verrou par numéro afin de sérialiser les contrôles. `FournisseurMaster` est une façade du dépôt commun ; ses anciennes routes ne disposent plus d'une implémentation d'écriture distincte.
- Les créations et modifications du dossier RH exigent le numéro et un fichier existant dans le dossier de l'application. Un changement de numéro exige une nouvelle pièce. La suppression isolée de la pièce obligatoire est refusée. Le listing RH signale les dossiers incomplets et permet leur correction ; les lectures et la consultation des anciens dossiers restent disponibles.
- Le contrôle interne détecte une correspondance de numéro ; il ne certifie pas l'authenticité ou la validité administrative de la pièce. Les imports SQL externes à l'application doivent respecter les mêmes règles.

## Clients étrangers dans les opérations

`ClientBusinessPolicy` sert de règle commune aux filtres clients, au passage vers le chantier et à la facturation : NCC/FNE vérifié pour CI ; identité de registre ou documentaire validée pour les pays étrangers. Une identité en attente ou révoquée interdit les opérations de projet et la facturation.

La facture est rattachée au client du chantier côté serveur. Le nom et les contacts viennent de cette fiche ; un identifiant client contradictoire est refusé. Les nouveaux champs `client_id`, `client_fiscal_country`, `client_identity_type`, `client_identity_number` conservent l'identité de facturation. Les clients étrangers utilisent B2F sans détourner le champ NCC ; les clients professionnels CI utilisent B2B (ou B2G si applicable). Les contacts absents sont acceptés en B2F, les formats renseignés restent contrôlés. Les taux de taxe et de change ne sont pas déduits automatiquement du pays.

La migration client version 4 bloque aussi un statut international NULL ; les cartes, le tableau de bord, la recherche et le CRM affichent les identifiants étrangers. Les routes historiques de facturation partagent le traitement principal.

## Livraison autonome et reprise du déploiement

Exécuter depuis le dépôt serveur : `php scripts/deploy_identity_release.php <commit-main-attendu>`.

Le script ne dépend d'aucune variable d'un précédent sous-shell. Il vérifie le commit et le dépôt propre, lance les tests PHP, établit un nouveau plan, puis sauvegarde la base avec `mariadb-dump` ou `mysqldump` en dehors du site (données, vues, déclencheurs, routines et événements). Il exige une configuration PROJECT_DB_* existante ; aucun secret n'est placé dans les arguments du processus. Un échec de sauvegarde interdit la suite.

Après sauvegarde terminée et empreinte enregistrée, il prépare les colonnes et contrôles, reprend les numéros RH existants, compte les dossiers à compléter, applique le code et traite l'export protégé séparément avec sauvegarde. Il contrôle les empreintes après copie. Seule la ligne `DÉPLOIEMENT TERMINÉ ET VÉRIFIÉ` confirme la fin. Un échec tardif peut laisser des migrations additives appliquées : le journal précise l'étape ; il ne faut pas interpréter le démarrage d'une sauvegarde comme une fin de livraison. La restauration de la base n'est jamais automatique.


## Couverture des formulaires et fiches spécialisées

Les ajouts et modifications du module fournisseur et de l’espace employé utilisent les mêmes champs et contrôles. Le formulaire partagé conserve les valeurs chargées lors d’une modification et réaffiche les valeurs soumises après une erreur. L’ancienne page `clients/modifier_legacy.php` utilise le formulaire client commun ; les anciennes clés `id_client` restent acceptées. Le CRM ouvre la création ou la modification du formulaire commun selon l’identifiant transmis.

Les formulaires logistique et transport ne créent plus une identité fournisseur autonome. Une référence explicite `master_supplier_id` désigne une fiche active, validée et complète du référentiel commun. Le serveur reprend son nom, ses contacts et son identifiant fiscal ; les valeurs saisies à la place ne sont pas utilisées. Les pays étrangers et les personnes physiques suivent ainsi les mêmes exigences que le référentiel, et une fiche liée au personnel reste interdite. Les paramètres spécialisés (type d’intervention, conditions de transport) demeurent dans leurs tables.

Les anciennes lignes logistique et transport sont conservées sans rapprochement automatique par nom. Elles doivent être rattachées depuis leur formulaire de modification à une fiche validée ; les sélections opérationnelles les excluent tant que ce rattachement est absent. Les pages proposent les liens vers la création ou la correction de la fiche commune. Les soumissions spécialisées sont protégées par un jeton de session.

Le script de livraison prépare les colonnes de liaison pour les tables spécialisées existantes. `SupplierFormsCoverageTest.php` vérifie la conservation des valeurs du formulaire employé, l’identité étrangère, l’interdiction de substituer un nom/identifiant et le refus des fiches non validées ou rattachées au personnel.


Le raccourci de création d’un tiers depuis une facture FNE ouvre également le formulaire commun ; il ne peut plus insérer une fiche automatiquement approuvée. L’association requiert une fiche client ou fournisseur exploitable, et la recherche prend en charge les identifiants étrangers. L’enrichissement d’une fiche à partir de la facture ne modifie plus ses identifiants fiscaux. Sans NCC, le rapprochement est limité à l’identifiant de la facture importée : aucun rapprochement global par seul nom n’est effectué.


## Entrée par profil et particulier client

Le premier champ est maintenant le profil (entreprise CI, France, UE, États-Unis, autre pays ou personne physique), suivi du pays fiscal en toutes lettres. Le choix masque les champs non pertinents : NCC/FNE pour l’entreprise CI, SIRET/SIREN pour la France, TVA/VIES pour l’UE, CIK/SEC ou justificatif pour les États-Unis. Les anciens identifiants et valeurs issues du registre sont effacés lorsqu’un profil incompatible est choisi. Le mode DGI non disponible n’est plus proposé comme alternative de recherche. Aucun registre d’entreprise n’est appelé pour une personne physique.

Les clients ont un `client_type` (morale par défaut, physique pour particulier). Un particulier se saisit sans NCC, SIRET ou TVA. Son nom, adresse et contact sont conservés avec un état documentaire ; l’approbation est réservée aux permissions déjà habilitées. Toute modification d’identité révoque la validation. La référence interne PERSON n’est jamais présentée comme un identifiant fiscal et n’est pas transmise dans clientNcc. La facturation utilise B2C pour un particulier CI et B2F pour l’étranger ; les coordonnées exigées par la FNE restent contrôlées au moment de la validation/facturation.

La migration client passe à la version 5 et adapte les gardes SQL. Les filtres, alertes et sélections projet utilisent la même règle d’exploitabilité. Les tests PHP vérifient la saisie sans API/NCC, les permissions, les filtres SQL et la révocation ; `BusinessIdentityEntryDomTest.cjs` utilise le HTML PHP réel et linkedom pour vérifier le choix initial, l’affichage, l’autoremplissage et les changements de profil (dépendance de développement : linkedom, PHP_BIN configurable).

## Diagnostic de l’échec d’initialisation fournisseur

L’erreur générique ne présume plus de droits SQL insuffisants : le dépôt conserve l’opération, le SQLSTATE et le code natif, avec valeurs citées masquées. Les colonnes de fiche_decaissement sont ajoutées sans dépendre de la présence de personnel_id, qui diffère selon les bases anciennes.

`scripts/check_identity_schema.php --backup=/home/fidestci/finea-deployments/identity-.../before.sql` vérifie l’empreinte de la sauvegarde existante et son rattachement à la base configurée, prend le même verrou que la livraison, puis prépare le schéma fournisseur et affiche le diagnostic. Il ne remplace aucun fichier du site. En cas d’échec, le rapport ne contient que l’erreur masquée et des métadonnées de structure ; aucun numéro de pièce ou identifiant fiscal n’est affiché. Cette commande prépare le schéma (elle n’est pas une simulation) ; une sauvegarde complète terminée est obligatoire. La livraison complète reste à exécuter ensuite, avec une nouvelle sauvegarde à jour.


## Revue des fiches existantes et maintien du circuit financier

`php scripts/review_existing_business_identities.php` utilise une transaction SQL READ ONLY. Le rapport JSON est enregistré hors du site, dans `/home/fidestci/finea-deployments`, avec droits privés. Il liste les identifiants internes et motifs à examiner sans afficher les numéros de pièces ni les identifiants fiscaux. Il ne fait aucun appel API et ne modifie aucune fiche.

La revue couvre types/pays, identités non validées, anciens NCC incompatibles avec le profil, pièces correspondant au personnel, doublons possibles d’identifiants et références manquantes des fiches, chantiers, devis, contrats, logistique et transport. Une référence absente n’est pas automatiquement une anomalie (fiche RH ou autre circuit sans fournisseur). Une table/colonne absente est signalée explicitement. Les tableaux JSON de FEB et les rapprochements bancaires ne sont pas rapprochés automatiquement par cet audit.

Régulariser sur les formulaires communs, sur le même ID : confirmer type et pays, rechercher via l’API disponible ou documenter l’identité, puis faire valider par le rôle autorisé. Ne jamais déduire un pays d’un nom, transformer un ancien NCC en SIRET, fusionner des doublons possibles ou approuver une fiche automatiquement. Les opérations comptables historiques gardent leurs références et montants.

La complétion fournisseur lors d’une FEB applique désormais `SupplierIdentity::missing` pour la partie identité : NCC/FNE CI, registre français, validation internationale ou dérogation documentée. Les contacts restent gérés par le formulaire existant. Le point d’entrée historique `services/validation_demandes_finances.php` délègue au circuit principal (CSRF, verrous, points RH signés compris). Aucun changement des montants, étapes d’approbation, réservations de trésorerie ou règles de paiement.

Validation : `SupplierFinancialIdentityPolicyTest.php`, `FebWorkflowGuardTest.php`, `FebSimplifiedWorkflowTest.php`, `FebPaymentControlTest.php` et `ClientBusinessPipelineTest.php`. Ces tests locaux ne constituent pas une validation de chaque cas métier sur les données de production.
