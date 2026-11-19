# Veille AO : diffusion collective et Contenu local

Le bilan est envoyé chaque lundi et vendredi à 8 h, heure d’Abidjan, avec un seuil commun de 60/100 par défaut. Un seul mail contient le responsable commercial en destinataire principal et les autres destinataires en copie, avec PDF et Excel joints.

## Paramétrage et droits

Depuis **Veille AO → Diffusion et destinataires** (`devis/veille_ao_diffusion.php`) :

1. Vérifier les départements commercial et technique (présélectionnés d’après les libellés RH ; sélection par identifiants sauvegardée ensuite).
2. Vérifier le responsable commercial issu de `rh_service_hierarchy`, ou sélectionner explicitement un responsable parmi les comptes actifs du département commercial.
3. Examiner les adresses, les doublons et les emails manquants ; télécharger l’aperçu PDF et Excel.
4. Activer la diffusion. Elle est désactivée par défaut jusqu’à cette vérification.

`ao_manage_diffusion` est un droit dédié, modifiable dans l’édition d’un utilisateur et présent dans la matrice des accès. `ao_manage_alerts` reste réservé aux préférences personnelles. `ao_admin` et `acces_admin` peuvent gérer la diffusion. Aucun droit existant n’est automatiquement accordé aux destinataires.

Les collaborateurs actifs des départements sélectionnés et les comptes actifs de direction (`acces_direction` ou `ao_dg_decision`) reçoivent obligatoirement ce bilan départemental. Il est distinct des alertes AO individuelles du profil. Les administrateurs hors de ce périmètre sont ajoutés par défaut, mais peuvent désactiver leur réception sur cet écran. Un administrateur appartenant au commercial, à la technique ou à la direction reste destinataire au titre de son département.

Les collaborateurs sans compte FINEA sont inclus via leur fiche RH. Email professionnel du compte prioritaire, puis email RH en secours. Les adresses absentes ou invalides apparaissent dans la liste mais ne sont pas envoyées ; l’absence d’un destinataire principal valide bloque tout le bilan. Les doublons d’adresse sont éliminés, sans supprimer leur visibilité dans la liste.

## Sélection et historique

Toutes les offres avec une date limite connue au moins égale à la date du bilan et un score au moins égal au seuil sont incluses. Les offres désactivées, refusées par le DG ou marquées non intéressantes sont exclues. Une offre intéressante ou convertie en CRM reste dans le bilan tant qu’elle est ouverte. Les offres déjà signalées sont reprises dans les bilans suivants. Un bilan vide est envoyé avec des pièces jointes indiquant qu’aucune offre ne satisfait les critères.

Le PDF et l’Excel utilisent le même générateur que les exports manuels, avec charte BANAMUR, en-tête, pied de page, indicateurs et QR de traçabilité. Les textes provenant des sources sont enregistrés en cellules texte, jamais en formules Excel.

`ao_digest_runs` enregistre la date, la sélection, les adresses à l’envoi, le statut et l’identifiant du journal email. Verrou MySQL et clé unique par date : un seul bilan par jour. En cas d’interruption pendant l’envoi, le résultat reste indéterminé et aucun renvoi automatique n’est effectué ; vérifier le journal SMTP avant une intervention manuelle.

Les résumés personnels existants restent disponibles pour les autres utilisateurs. Quand la diffusion collective est activée, les destinataires de ce circuit sont exclus des résumés individuels quotidiens/hebdomadaires pour éviter des doubles bilans. Les alertes instantanées explicitement activées restent distinctes.

## Source Contenu local DGH

La page `/tenders` concerne les appels d’offres du compte. La veille doit utiliser **Publication → Offres**, `/offers`.

L’adaptateur utilise les endpoints observés dans le frontend public de la plateforme :

- Authentification Keycloak : `https://keycloak.contenulocal.dgh.energie.gouv.ci/realms/ic-live-realm/protocol/openid-connect/token`, client `hydro-client`.
- Offres : `https://api-intermediation.contenulocal.dgh.energie.gouv.ci/users-ms/api/v1/tender-calls/search`.
- Pagination `page` à partir de zéro, `limit=100`, réponse `content` et `totalPages`.
- Types `OPEN_AO,AMI_AO,AMI,AO`, statuts `PUBLISHED,LAUNCHED`.
- Date limite : `launchEndAt`, fin de lancement ; ne pas utiliser la clôture de procédure.
- Les routes `#/publish/details/{id}` sont conservées dans les URL canoniques pour identifier chaque offre distinctement.

Ces endpoints n’ont pas été présentés comme une API contractuelle publique ; un changement côté plateforme peut nécessiter une adaptation. La connexion et les pages ont été vérifiées dans le navigateur, mais l’exécution du connecteur PHP doit encore être vérifiée avec le compte configuré sur le serveur. Les identifiants de la connexion navigateur ne sont jamais extraits.

Le compte est configuré dans `/home/fidestci/.finea/contenu-local.php` (permissions 0600, hors racine web). La configuration n’entre jamais dans Git, les logs ou les rapports. TLS reste vérifié ; aucune redirection des requêtes d’authentification n’est suivie. Variables alternatives : `FINEA_CONTENU_LOCAL_USERNAME` et `FINEA_CONTENU_LOCAL_PASSWORD`, ou chemin personnalisé `FINEA_CONTENU_LOCAL_CONFIG`.

Après fusion dans develop puis main et déploiement de main, extraire les outils et tests de la version main, puis configurer le compte sans modifier le checkout existant :

```bash
(
  set -e
  umask 077
  finea_repo=/home/fidestci/repositories/banamur_planification
  git -C "$finea_repo" fetch origin main
  finea_tools=$(mktemp -d /home/fidestci/finea-ao-tools.XXXXXX)
  git -C "$finea_repo" archive origin/main | tar -x -C "$finea_tools"
  /opt/alt/php85/usr/bin/php "$finea_tools/tests/ao_collective_digest.php"
  /opt/alt/php85/usr/bin/php "$finea_tools/tests/ao_digest_recipients.php"
  /opt/alt/php85/usr/bin/php "$finea_tools/tests/ao_contenu_local_pagination.php"
  read -r -p 'Email Contenu local : ' finea_cl_email
  read -r -s -p 'Mot de passe Contenu local : ' finea_cl_password
  printf '\n'
  printf '%s\n%s\n' "$finea_cl_email" "$finea_cl_password" |
    FINEA_CONTENU_LOCAL_CONFIG=/home/fidestci/.finea/contenu-local.php \
    /opt/alt/php85/usr/bin/php "$finea_tools/scripts/configure_contenu_local.php"
  unset finea_cl_email finea_cl_password
)
```

Les scripts et tests restent hors du site ; le déployeur les protège par défaut. Les identifiants sont lus depuis l’entrée standard, jamais depuis des arguments de commande.

La configuration n’est enregistrée qu’après une lecture réussie de la source. Après cela :

```bash
/opt/alt/php85/usr/bin/php /home/fidestci/projet.fidest.ci/cron/sync_ao_opportunities.php
```

Vérifier l’historique de synchronisation et les offres avant l’activation des bilans. Une erreur d’une source n’empêche pas l’autre source de se synchroniser ; les erreurs et résultats de chaque source sont affichés dans les statistiques.

## Cron et déploiement

Créer une seule entrée pour le bilan collectif :

```cron
0 8 * * 1,5 /opt/alt/php85/usr/bin/php /home/fidestci/projet.fidest.ci/cron/send_ao_collective_digest.php >> /home/fidestci/finea-ao-digests.log 2>&1
```

Le service vérifie explicitement le fuseau Africa/Abidjan. La ligne suppose que le cron serveur est à l’heure d’Abidjan/UTC ; adapter si le panneau utilise un autre fuseau. Le script dédié n’envoie que le bilan collectif. L’ancien `send_ao_digests.php` peut rester pour les résumés personnels : le verrou commun empêche les doublons collectifs.

Après synchronisation, vérification des destinataires et activation de la diffusion, envoyer immédiatement le bilan des offres actuellement ouvertes (y compris celles déjà présentes cette semaine) :

```bash
/opt/alt/php85/usr/bin/php /home/fidestci/projet.fidest.ci/cron/send_ao_collective_digest.php --send-now
```

`--send-now` permet l’envoi initial le mercredi sans attendre vendredi. Il conserve le seuil, les exclusions, les destinataires configurés et la protection contre un deuxième envoi le même jour. La commande ne configure pas les destinataires et n’active pas une diffusion désactivée. Vérifier `status: sent`, `emails: 1` et l’historique FINEA. Ne pas relancer un résultat `uncertain` sans contrôler les journaux SMTP.

Ne pas exécuter de migration financière ou RH. Les trois tables AO de diffusion sont créées à la première ouverture/service, selon la convention du module existant. La permission dédiée est ajoutée par les migrations de permissions existantes de `AccessManager` et `User`. Vérifier que l’utilisateur SQL a les droits de création/alter nécessaires.

Prévoir `vendor/autoload.php`, PhpSpreadsheet, TCPDF, PHP cURL, GD, mbstring, XML et ZIP (déjà utilisés par les exports). Ne pas mettre à jour Composer ou les dépendances en production pour cette seule fonctionnalité.

## Validation

- `php tests/ao_collective_digest.php` : jours, fuseau, échéances, seuil, exclusions, reprises, copies et routes SPA.
- `php tests/ao_digest_recipients.php` : résolution des départements, hiérarchie, DG, administrateurs, adresses manquantes et doublons, employés sans compte ; PDO simulé.
- `php tests/ao_contenu_local_pagination.php` : pagination complète, déduplication, source vide, réponse incompatible et authentification ; transport simulé.
- `php tests/ao_digest_reports.php` : PDF puis XLSX dans le même processus, sélection vide, texte externe non interprété comme formule, buffers de sortie ; nécessite les dépendances vendor.
- Lint PHP de tous les fichiers modifiés.
- PDF et Excel sur une sélection de plusieurs pages et une sélection vide ; vérifier l’ouverture des fichiers et la cellule commençant par `=`.
- À vérifier sur staging : données RH réelles, API authentifiée, schéma MySQL réel et délivrabilité d’un unique mail avec les deux pièces jointes. Aucun mail de test envoyé aux collaborateurs durant le développement.
