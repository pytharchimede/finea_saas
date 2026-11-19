# Circuit contrats et notifications WhatsApp

Le circuit est RH → Juridique → Technique → Logistique → QHSE → Comptabilité → DG → Prestataire → Actif.

Chaque signataire désigné conserve l’accès à son étape et peut l’ajuster avant la signature DG. Enregistrer les ajustements, contrôler les données sauvegardées, puis signer dans le cadre séparé. Une page devenue obsolète doit être rechargée. La preuve Passkey est liée à l’étape et à son empreinte. Les pré-signatures sont possibles après enregistrement complet des informations ; la transmission attend les visas précédents. Une correction d’une étape validée invalide sa signature et les visas suivants. Une réaffectation supprime la pré-signature du précédent signataire.

La signature DG exige les six visas. Après elle, les informations et l’affectation du DG sont figées. Les RH ou administrateurs recueillent ensuite la signature du prestataire en sa présence et confirment cette collecte avec leur propre Passkey ; cette preuve authentifie l’agent collecteur, pas une identité numérique du prestataire. Le prestataire n’a pas de lien public de signature à distance. Un contrat actif reste soumis aux contrôles de paiement existants.

## Modèles

Dix modèles français Utility : `rh`, `juridique`, `technique`, `logistique`, `qhse`, `comptabilite`, `direction`, `revision`, `prestataire`, `complete`.

Les sept modèles internes, la révision et la finalisation utilisent un bouton URL vers FINEA et cinq variables : destinataire, référence du contrat, chantier, étape, chemin du document. Le prestataire reçoit quatre variables et une invitation textuelle à contacter les RH. Les noms des modèles sont versionnés à la création. Aucune clé d’accès ne figure dans le message. Les liens internes nécessitent la connexion et les autorisations habituelles.

Le catalogue et tous les exemples sont dans `services/ContractWhatsAppCatalog.php`. Les modèles existants des points RH et des décaissements ne sont pas remplacés.

## Installation et soumission depuis le serveur

Après déploiement du code, avec l’ID d’un administrateur actif (la commande vérifie cet ID) :

```bash
cd /home/fidestci/projet.fidest.ci
/usr/local/bin/php scripts/contract_whatsapp.php --install --actor=2
/usr/local/bin/php scripts/contract_whatsapp.php --provision --actor=2
/usr/local/bin/php scripts/contract_whatsapp.php --status
```

`--provision` crée et soumet les modèles à Twilio/Meta avec la configuration chiffrée existante de FINEA, sans afficher ses secrets et sans envoyer de messages. Cette étape doit être exécutée dans l’environnement qui possède ces identifiants ; un code publié ne constitue pas une soumission réalisée.

Suivi : `/contrats/suivi_whatsapp.php`, accessible aux RH et administrateurs. Les administrateurs peuvent également y créer/soumettre les modèles et contrôler leur approbation. Si les dix opérations dépassent le délai HTTP de l’hébergement, préférer la commande CLI et vérifier les résultats avant reprise.

Le mode automatique est autorisé par défaut, y compris pour les installations existantes. Aucun `--enable` n’est nécessaire avant la première approbation. Pour une simulation et un premier cycle :

```bash
/usr/local/bin/php scripts/contract_whatsapp.php --dry-run
/usr/local/bin/php scripts/contract_whatsapp.php --run
```

Le worker contrôle les statuts même quand tous les modèles sont en attente. Chaque modèle est utilisable dès son approbation en Utility ; les autres notifications restent en file. Le rattachement au circuit utilise la clé de modèle déjà persistée, sans recréation ni nouvelle soumission. Le contrôle Twilio est renouvelé toutes les cinq minutes ; le worker peut tourner chaque minute. Une perte d’approbation, une recatégorisation ou une erreur de contrôle bloque les envois du modèle concerné. Une suspension manuelle est persistante et ne peut pas être annulée par le cron. `--enable --actor=2` autorise à nouveau le mode automatique, même si certains modèles restent en attente ; cette commande n’envoie aucun message. Les dossiers ouverts existants sont inclus pour la prochaine étape réellement actionnable. Les anciennes finalisations antérieures à l’activation ne donnent pas lieu à un envoi massif. Les destinataires sont résolus à partir des affectations actuelles du contrat et du téléphone du personnel actif lié à un compte actif ; le prestataire utilise le téléphone du fournisseur du contrat. Un numéro absent ou invalide bloque le message et reste visible dans le suivi.

Ajouter une seule entrée cron (préserver les autres tâches) :

```cron
* * * * * /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/cron/contract_whatsapp_worker.php >> /home/fidestci/contract_whatsapp_worker.log 2>&1
```

Le worker rapproche les actions actuelles, contrôle les approbations, met en file et envoie les messages. Son entrée cron dépend uniquement des services déployés automatiquement, sans charger le dossier scripts protégé. Au premier cycle, l’ajout idempotent de `auto_activation` met à niveau la table de paramètres sous le verrou dédié, hors transaction de signature. Un verrou MariaDB empêche deux workers de fonctionner simultanément. Les messages périmés après signature, changement d’affectation ou annulation sont abandonnés avant envoi. Les envois déjà acceptés sont dédupliqués. Les statuts de livraison sont ensuite lus auprès de Twilio ; acceptation ne signifie pas livraison.

## Incidents et reprise

- Création incertaine : vérifier dans Twilio le modèle portant le nom persisté ; ne pas en créer une autre copie. Après identification du bon SID : `--reconcile=ETAPE:HX... --actor=2`, puis `--provision --actor=2`.
- Soumission incertaine : `--run` ou le bouton de contrôle rapproche le statut existant. Une réponse sans demande d’approbation permet ensuite de relancer `--provision` sur le même SID. Aucun nouveau contenu n’est créé.
- Envoi `sending` interrompu ou `uncertain` : aucune répétition automatique. Vérifier les journaux Twilio et le destinataire avant intervention administrateur sur le dossier concerné. Les envois acceptés mais non livrés ne sont pas répétés automatiquement.
- Suspendre les envois : `--disable --actor=2`. Le cron surveille toujours les modèles mais ne réactive pas les envois. Reprendre : `--enable --actor=2`. Les signatures restent disponibles.
- Modèle refusé : lire le motif ; une nouvelle version doit avoir un nouveau nom. Conserver l’ancien SID pour l’historique. Cette version fournit la création initiale et le rapprochement, pas la suppression des modèles.

## Vérification

`php tests/ContractWorkflowEndToEndTest.php` (extension pdo_sqlite et mbstring) exerce les services réels sur une base temporaire, avec adaptation de syntaxe MySQL vers SQLite et doublures Twilio. `node tests/ContractPasskeyClientTest.js` couvre les validations clientes et les confirmations concurrentes.

La syntaxe PHP et JavaScript est contrôlée. Les tests locaux ne prouvent pas les verrous MariaDB en concurrence, l’approbation Meta, la réception WhatsApp, ni l’usage d’un authentificateur réel. Après installation, tester un contrat avec deux sessions, une modification avant signature, une réaffectation, les visas successifs, la signature DG, la collecte prestataire et l’accès au PDF final. Comparer le statut fournisseur Twilio et la réception réelle.

## Mise à jour de la version à activation manuelle

Déployer le code avec `deploy_finea.php plan main` puis `apply` sur le plan relu. Le dossier scripts reste protégé : recopier uniquement `scripts/contract_whatsapp.php` depuis le dépôt vers le site pour conserver les commandes historiques. Les nouveaux services et le worker sont déployés automatiquement ; aucune nouvelle migration SQL à copier n’est nécessaire pour cette évolution sur une installation existante.

```bash
install -m 0644 /home/fidestci/repositories/banamur_planification/scripts/contract_whatsapp.php /home/fidestci/projet.fidest.ci/scripts/contract_whatsapp.php
/usr/local/bin/php /home/fidestci/projet.fidest.ci/cron/contract_whatsapp_worker.php --status
/usr/local/bin/php /home/fidestci/projet.fidest.ci/cron/contract_whatsapp_worker.php
```

La commande sans options est un cycle réel : elle peut envoyer des notifications si un modèle est déjà approuvé. `--status` initialise uniquement la colonne de configuration et affiche l’état ; `--dry-run` simule les actions sans envoyer. L’installation du cron arme le traitement automatique voulu. Aucune relance de `--provision` n’est nécessaire pour les dix modèles déjà soumis.

`php tests/ContractWhatsAppAutoActivationTest.php` couvre l’attente initiale, une seule approbation parmi dix, le drainage progressif, la suspension manuelle, la reprise, la recatégorisation et les erreurs de contrôle.
