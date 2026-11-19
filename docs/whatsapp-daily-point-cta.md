# Invitation WhatsApp à signer le point journalier

## Modèle créé dans Twilio le 30 septembre 2026

- Nom : `finea_point_signature_20260930`
- Content SID : `HX026611c439c24ebb7dc7121b5ac878b9`
- Langue : français (`fr`), catégorie : `UTILITY`, bouton : « Consulter et signer ».
- Statut observé après soumission : `Pending` (en attente de décision Meta).

Ce modèle existe déjà : ne pas le recréer lors du déploiement. Le suivi serveur utilise ce SID dès son premier lancement. La route du bouton doit être déployée et vérifiée avant mise en service.

Le rejet `2388024` de `send_daily_recap` indique un doublon de contenu français sous ce nom. Créer un nouveau modèle avec un nom unique, par exemple `finea_point_signature_20260930_180000`, en catégorie `UTILITY`, langue `fr` et type `twilio/call-to-action`.

La définition est centralisée dans `DailyPointWhatsAppTemplateService`. Le corps contient six variables : nom du signataire, numéro du point, date, nombre de fiches, montant avec devise et expiration. La septième variable est uniquement le jeton du bouton « Consulter et signer » :

`https://projet.fidest.ci/finance/signature-point/{{7}}`

## Mise en service

1. Déployer le code et le `.htaccess` racine. Vérifier que `/finance/signature-point/` suivi de 64 lettres `a` affiche « Lien indisponible », sans erreur Apache. Ce jeton d’exemple ne donne accès à aucun point.
2. Dans `decaissement/parametres_signature_point_jour.php`, enregistrer les identifiants Twilio existants. Seul un administrateur peut créer ou changer le modèle.
3. Utiliser le modèle existant `HX026611c439c24ebb7dc7121b5ac878b9`. Le bouton « Créer le template Utility et demander l’approbation » reste disponible si un nouveau modèle devient nécessaire. Ne pas l’utiliser pour recréer ce modèle.
4. Ouvrir `decaissement/suivi_template_whatsapp_point.php` avec un compte administrateur et cliquer sur « Contrôler maintenant ». Installer ensuite le cron ci-dessous. L’activation automatique est activée par défaut : le modèle devient actif seulement après statut `approved`, catégorie Utility, conformité du contenu et disponibilité de la route. Un autre CTA activé manuellement suspend ce remplacement.
5. Utiliser `decaissement/test_whatsapp_point.php` avec un numéro de test saisi explicitement. Le modèle dynamique reçoit des données fictives et un jeton sans droit de signature.
6. Arrêter un vrai point pour que le DG reçoive les détails et son bouton personnel. « Accepté par Twilio » signifie mis en file d’envoi, pas livré ; contrôler la livraison dans les logs Twilio.

Sans nouveau SID activé, la notification historique reste utilisée. Vider le champ permet d’y revenir. La colonne `point_stopped_cta_sid` est ajoutée automatiquement suivant le mécanisme de configuration existant. Les jetons restent soumis aux contrôles existants d’expiration, de révocation et d’usage unique. Ils ne sont plus inscrits dans les détails du journal d’audit.

## Vérification

`php tests/daily_point_whatsapp_template_test.php`

Le test utilise une API simulée et ne crée aucun modèle ni message réel. Vérifier également la syntaxe des fichiers PHP modifiés. Les tests de bout en bout nécessitent la base FINEA, Apache et un compte Twilio actif ; l’approbation ne peut pas être garantie par le code.

## Suivi autonome sur le serveur

Après déploiement de la PR, configurer une tâche cron cPanel toutes les cinq minutes :

```cron
*/5 * * * * /usr/local/bin/php -q /home/fidestci/projet.fidest.ci/cron/monitor_whatsapp_daily_point_template.php
```

Adapter le chemin si le répertoire de déploiement diffère. Exécuter la commande une première fois et vérifier le résultat dans la page de suivi. PHP CLI doit disposer de PDO MySQL, cURL et OpenSSL, du même environnement et de la même clé de chiffrement que FINEA. Les identifiants restent dans la configuration chiffrée existante ; aucun secret ne doit être ajouté à la commande cron. Le script refuse les requêtes HTTP.

La page ne lance aucun contrôle en arrière-plan : le cron est indispensable. La présence du code dans GitHub ne déploie pas le serveur et n’installe pas cette tâche.

Les demandes Pending/Received sont interrogées toutes les cinq minutes ; les statuts terminaux restent contrôlés chaque heure. Une panne API conserve le statut connu et applique un délai progressif plafonné à six heures. Le verrou MySQL empêche deux contrôles ou créations simultanés. Les changements produisent un événement et une notification système pour l’équipe administratrice ; un résultat inchangé ne produit pas de nouvelle alerte. Le diagnostic Meta, et non un message WhatsApp envoyé au DG, fournit le motif de rejet.

Si le modèle actif suivi devient Rejected/Paused/Disabled, FINEA revient au modèle historique. Une erreur réseau ne provoque pas ce retour. Les mises à jour de configuration, du suivi et des notifications sont enregistrées dans une transaction.

Après rejet, la page autorise la correction du texte (variables 1 à 6 conservées) et la soumission sous un nouveau nom unique. La variable 7 reste réservée au bouton. Le corps exact de cette version est conservé pour vérifier l’approbation et afficher le bon aperçu de test. Le cron ne recrée jamais de modèle automatiquement. Si la création réussit mais la soumission échoue, le SID est conservé : terminer la soumission dans Twilio avec ce SID puis relancer un contrôle.

Les tables `daily_point_template_monitor` et `daily_point_template_monitor_events`, ainsi que la colonne `point_stopped_cta_body`, sont ajoutées au premier lancement. Les trente derniers événements sont visibles dans l’administration ; les événements précédents restent en base pour l’audit. Désactiver l’activation automatique depuis cette page laisse le suivi et les alertes actifs.

Vérification complémentaire : `php tests/daily_point_whatsapp_monitor_test.php` (API simulée ; aucun envoi réel). Les vérifications locales couvrent le calcul des délais, les diagnostics et l’échec partiel de soumission. Le verrou, les transactions MySQL, les notifications, le cron et le routage Apache doivent être vérifiés après déploiement sur l’environnement FINEA.
