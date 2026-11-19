# Points financiers RH

Le dashboard sépare les demandes administratives des demandes financières. Les points d’avances, prêts et salaires sont arrêtés et signés par le collaborateur RH, puis transmis au DG désigné. Le DG peut approuver, refuser, annuler, reporter ou ajuster chaque montant avant de signer par Passkey. La signature génère une FEB avec le PDF de cette version et le collaborateur RH comme demandeur et bénéficiaire du décaissement collectif.

Une réouverture RH annule la FEB et la fiche précédentes et impose deux nouvelles signatures. Elle est interdite après paiement. Les reports ne redeviennent candidats qu’à leur date. Les points salaires utilisent les bulletins figés du processus de paie existant ; ils ne remplacent pas ses validations.

## Déploiement

1. Sauvegarder la base et appliquer `database/migrations/20261002_rh_financial_points.sql` sur MariaDB. Aucun changement automatique de schéma dans les transactions financières.
2. Déployer via feature → develop → main, puis mettre à jour la production depuis main. Ne pas copier les fichiers de configuration, secrets, uploads ou données locales entre environnements.
3. Dans `/rh/points_financiers_rh.php`, un administrateur choisit le compte DG actif, disposant d’un Passkey, et son numéro WhatsApp international. Les collaborateurs RH et les bénéficiaires doivent avoir un compte actif lié à leur personnel par email.
4. Utiliser la configuration Twilio existante pour créer un modèle Utility pour les avances et un autre pour les prêts. Un troisième est prévu pour les salaires. Le suivi `/rh/suivi_templates_whatsapp_rh.php` vérifie automatiquement leur approbation Meta Utility et active le circuit avances/prêts dès approbation. La première activation fixe définitivement sa date de bascule ; les anciennes demandes restent dans le processus historique. Pour les salaires, la bascule est mensuelle.
5. Ajouter toutes les cinq minutes une tâche CLI exécutant `php /chemin/application/cron/sync_rh_financial_settlements.php`. Elle répare les notifications manquées sans doublons.

Le lien WhatsApp est à usage unique, expire en 24 heures et requiert une connexion avec le compte DG désigné. Un envoi d’état incertain nécessite une réouverture et de nouvelles signatures. Vérifier le domaine du bouton dans `RhFinancialWhatsAppTemplateService.php` pour chaque environnement avant création du modèle.

La validation Finances utilise la couverture de trésorerie et le BCI existants, puis crée une fiche pour le montant signé. Les fiches RH ne permettent pas le paiement partiel : les montants individuels doivent rester exacts. L’alerte d’autorisation n’est pas une preuve de paiement ; le paiement et les dettes d’avances/prêts sont enregistrés lorsque la fiche porte `decaisse=1`. Les alertes internes donnent le montant final et le libellé du détail de la fiche.

## Vérification

`php tests/RhFinancialSettlementTest.php` couvre le service réel et un PDF TCPDF réel, avec SQLite et des doubles de notification/dette. Les tests vérifient signatures contextuelles, droits DG, versions invalidées, bascule, reports, génération FEB, identité RH, montants finaux, blocage après paiement et absence de doublons.

Avant production, valider sur MariaDB les migrations et verrouillages concurrents, le parcours Finances/BCI, les signatures Passkey dans le navigateur et un envoi réel par chacun des modèles Twilio approuvés. La création et l’activation des modèles n’ont pas été exécutées sur un compte Twilio pendant le développement.

## Branches

Base de travail : main et develop alignés sur `b7f7513de3b470a1a780088859e5488c785d40e5`. Branche issue de develop : `feature/rh-financial-points-feb`. Intégration attendue : feature → develop → main. Les anciennes branches distantes sont conservées : leur suppression requiert un inventaire des travaux encore utiles, et le connecteur utilisé ne propose pas de suppression de références.

## Suivi des modèles WhatsApp RH

Appliquer ensuite `database/migrations/20261002_rh_whatsapp_template_monitor.sql`. Depuis le suivi WhatsApp du point journalier en Finances, le lien « Suivi WhatsApp RH » ouvre le nouvel écran administrateur. Le bouton « Créer les deux modèles avances et prêts » utilise les identifiants Twilio déjà configurés dans FINEA, crée deux modèles Utility français avec bouton de signature, enregistre leurs SID puis les soumet à Meta. Les salaires sont disponibles séparément, avec activation automatique désactivée par défaut.

Le cron existant `cron/monitor_whatsapp_daily_point_template.php` surveille désormais aussi les modèles RH lorsque la migration est présente. S’il n’est pas installé, utiliser toutes les cinq minutes `php /chemin/application/cron/monitor_whatsapp_rh_templates.php`. Inutile d’installer les deux crons ; les contrôles restent néanmoins idempotents et partagent un verrou.

Contrôle Meta toutes les cinq minutes en attente, puis toutes les heures après décision. Les erreurs API entraînent un délai de reprise progressif, plafonné à six heures, en conservant le modèle actif. Le suivi affiche motif Meta, erreurs, SID, date de bascule et historique, avec alertes internes aux administrateurs uniquement lors d’un changement.

Une version de remplacement est conservée comme candidate tant qu’elle n’est pas approuvée ; le SID actif reste inchangé. La date de première activation reste immuable. Les résultats de création incertains interdisent une nouvelle création automatique ; le SID peut être rattaché depuis l’écran après vérification du contenu. Une soumission Meta ayant échoué peut être relancée sur le même SID.

Création depuis le terminal du serveur (optionnelle, sans secret dans la commande) : `php scripts/create_rh_whatsapp_templates.php ID_COMPTE_ADMIN`. Cette action est explicite et ne s’exécute jamais automatiquement dans un cron. Le nom du compte DG et son numéro se configurent dans les points financiers RH. Le suivi ne garantit pas l’approbation Meta.

Test : `php tests/RhFinancialWhatsAppMonitorTest.php`. SQLite et API Twilio simulée vérifient séparation avances/prêts, autorisation admin, activation Utility, erreurs API, remplacement conservant le modèle actif, SID après échec, absence de doublons, concurrence de configuration et date de bascule immuable. Les appels live doivent être exécutés depuis le serveur disposant de sa configuration Twilio ; aucun modèle réel n’a été créé depuis l’environnement de développement.

## Installation sans recopier les identifiants SQL

Depuis le dossier de FINEA sur le serveur : `php scripts/install_rh_financial.php`. Le script CLI utilise `Database::getConnection()` de l’application pour appliquer les deux migrations dans leur ordre, puis vérifie les dix tables attendues. Il est relançable : les données, le DG configuré et les dates de première activation sont conservés. Il ne crée aucun modèle Twilio et n’active aucun circuit. Un échec partiel nécessite de consulter le journal PHP et de relancer après correction ; les DDL MariaDB ne sont pas présentées comme une transaction atomique.

Les écrans RH vérifient l’installation avant de charger les données et affichent une instruction claire en cas de table manquante. Le paramétrage DG est placé en tête des points financiers et la liste des comptes Direction actifs est chargée indépendamment des candidats/budgets. Un compte sans accès Direction ou inactif n’apparaît pas ; aucune permission n’est accordée automatiquement.


La table utilisateurs FINEA utilise `mail_pro`. Les services RH exposent ce champ sous l’alias `email` pour leurs snapshots internes, sans exiger une colonne SQL `email`. La recherche DG utilise le nom, prénom et mail professionnel des comptes Direction actifs, dans un champ unique avec liste et navigation au clavier. Le sélecteur natif reste disponible si JavaScript est désactivé.
