# Console WhatsApp centrale

Page réservée aux administrateurs : `/admin/whatsapp_templates.php`, accessible depuis le tableau de bord admin, le suivi RH, le suivi finance et l'écran de test existant.

## Installation

Après fusion feature → develop → main et mise à jour du serveur :

```bash
cd /home/fidestci/projet.fidest.ci || exit 1
php scripts/install_whatsapp_admin.php
```

L'installation applique `database/migrations/20261003_whatsapp_admin_console.sql`. Elle est réexécutable et crée seulement `whatsapp_admin_templates` et `whatsapp_admin_events`. Aucun réglage RH, DG, finance, SID actif ou date d'activation n'est modifié.

Depuis la console, cliquer **Synchroniser depuis Twilio**. Le catalogue est importé par pages de 50 modèles ; utiliser le bouton de page suivante lorsqu'il apparaît. Un modèle peut aussi être rattaché par son Content SID. La synchronisation ne supprime aucune entrée du registre.

Le registre conserve les Content SID, noms, définitions, langues, catégories, statuts Meta, derniers contrôles et diagnostics. Les circuits RH et finance sont lus séparément pour afficher les rattachements actifs et suivis.

## Création et versions

Le formulaire prend en charge `twilio/text` et `twilio/call-to-action` avec un bouton HTTPS. Catégories : Utility et Marketing ; langues : français, anglais, portugais, espagnol. Les modèles Authentication nécessitent un type spécifique et ne sont pas créés par ce formulaire.

Les variables `{{1}}`, `{{2}}`, etc. doivent être consécutives. Chaque variable doit avoir un exemple. Le bouton peut contenir une variable à la fin de son URL. L'aperçu évolue en temps réel. Meta reste l'autorité d'approbation et peut refuser ou reclasser un modèle.

Un suffixe date/heure/aléatoire garantit un nom unique pour chaque nouvelle version. Le SID est sauvegardé avant soumission : si celle-ci échoue, reprendre **Soumettre le modèle existant**. Si la création retourne un résultat incertain, rechercher le nom dans Twilio puis rapprocher le SID ; ne pas recréer aveuglément. Le rapprochement vérifie le nom, la langue, le texte et le bouton.

La création ou l'approbation d'un modèle dans le registre ne l'active dans aucun circuit. Les pages de suivi RH/finance restent responsables des contrôles de compatibilité, de l'activation automatique et des dates historiques.

## Tests et logs

Le test requiert un administrateur actif, un numéro international et une approbation Meta vérifiée au moment de l'envoi. Il transmet uniquement les variables présentes dans le modèle. Les modèles de signature FINEA reçoivent un jeton fictif ; aucune FEB ni invitation personnelle n'est créée.

Les opérations disposent d'un contrôle CSRF à usage unique, d'une redirection après POST et d'un verrou partagé en base. Les tests ont un journal durable enregistré avant l'appel réseau, avec auteur, destinataire, modèle et message SID. Un test resté `sending` après interruption demande une vérification dans Twilio avant de recommencer ; aucune relance automatique n'est effectuée.

**Vérifier la livraison** interroge le Messages API et conserve le statut constaté : queued, sent, delivered, read, failed, etc. `accepted` indique seulement que Twilio a accepté la requête. Aucun webhook supplémentaire n'est nécessaire pour ce contrôle manuel.

L'écran présente les 100 dernières opérations centrales et, séparément, les 60 derniers événements historiques disponibles des anciens suivis et tests finance. Leurs tables restent en place. Les anciennes valeurs de tests en session ne sont pas rétroactivement importées.

Les journaux masquent les secrets et jetons personnels ; les valeurs saisies pour les tests ne sont pas enregistrées. Les exemples de création font partie de la définition du modèle : renseigner des exemples fictifs.

## Contrôle automatique

Le cron existant `cron/monitor_whatsapp_daily_point_template.php` observe aussi le registre central après installation, sans modifier les réglages des circuits. Il traite au plus trois modèles arrivés à échéance par passage. Les validations en cours sont revérifiées après 5 minutes, les statuts décidés après 1 heure, et les erreurs réseau après 15 minutes. L'API en erreur ne remplace pas le dernier statut connu.

Si ce cron n'est pas installé, utiliser :

```cron
*/5 * * * * /usr/bin/php /home/fidestci/projet.fidest.ci/cron/monitor_whatsapp_admin_templates.php
```

Choisir l'un des deux chemins de contrôle central ; le verrou partagé et l'échéance persistante protègent également les appels concurrents. Les statuts de livraison des tests sont actualisés par le bouton dédié.

## Validation

`tests/WhatsAppAdminConsoleTest.php` : API et canal simulés, base SQLite avec adaptation des métadonnées MariaDB ; autorisations, validation des définitions, création/soumission, rapprochement, synchronisation, tests, livraison, redaction, conservation des réglages et réinstallation.

`tests/WhatsAppAdminFormTest.cjs` : formulaire dans jsdom, variables dynamiques, conservation des saisies, aperçu sans injection HTML, préparation d'une nouvelle version et recherche.

Les verrous MariaDB et un envoi réel doivent être vérifiés sur le serveur après installation. Les tests automatisés n'envoient aucun message réel.
