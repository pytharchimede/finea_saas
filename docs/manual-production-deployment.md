# Déploiement manuel FINEA

Le dépôt reste dans /home/fidestci/repositories/banamur_planification. La production reste dans /home/fidestci/projet.fidest.ci. Installer scripts/deploy_finea.php hors du site, par exemple /home/fidestci/bin/deploy_finea.php, avec des permissions 0700.

## Usage
- php /home/fidestci/bin/deploy_finea.php plan main : branche habituelle (main par défaut).
- php /home/fidestci/bin/deploy_finea.php plan develop : préparation d'un test.
- Lire le preview.txt et le protected.txt indiqués par la commande.
- php /home/fidestci/bin/deploy_finea.php apply CHEMIN_ABSOLU_DU_PLAN_JSON : appliquer exactement le plan relu.

La préparation récupère la branche, archive un commit précis dans un dossier privé et calcule les différences par empreintes SHA-256. Elle ne modifie pas la production. Le script ne charge pas l'application et ne se connecte pas à la BDD.

L'application vérifie tous les fichiers et leur syntaxe PHP, puis sauvegarde tous les fichiers existants concernés avant la première copie. Un fichier modifié depuis la simulation bloque le plan. Un verrou empêche deux opérations simultanées avec ce script. Un plan exécuté ne peut pas être réutilisé. Les nouveaux fichiers reçoivent 0644, les nouveaux dossiers 0755 ; les fichiers existants conservent leurs permissions.

## Périmètre et limites
Seuls les fichiers PHP, JS, MJS, CSS, HTML, HTM et webmanifest admissibles sont copiés. Les configurations locales, AppConfig.php, Database.php, DatabaseApp.php, fichiers cachés, pièces jointes, photos, signatures, dépendances, SQL, migrations, médias, JSON et autres fichiers exclus restent à traiter séparément. Consulter protected.txt ; une liste d'exclusion n'est pas une validation des dépendances fonctionnelles.

Aucune suppression automatique : un fichier supprimé ou renommé dans Git peut subsister sur le site. Comparer ces cas avant chaque changement de branche. La présence du suivi WhatsApp en production bloque une branche qui ne contient pas son cron ; cette protection ne garantit pas la compatibilité de toutes les fonctionnalités.

La copie est atomique par fichier, pas pour l'ensemble du site. Prévoir une fenêtre adaptée pour les changements interdépendants. Les contrôles de syntaxe ne remplacent pas les tests fonctionnels et le contrôle des migrations requises.

## Sauvegarde et incident
Chaque plan possède backup/ (anciens fichiers), plan.json (empreintes et liste des ajouts), execution.json (progression). Le fichier last-deployment.json est écrit uniquement après succès. En cas d'interruption, ne pas considérer le site comme intégralement mis à jour : examiner le journal et les empreintes, puis restaurer les anciens fichiers concernés. Des ajouts peuvent être présents même si le journal a été interrompu juste après leur copie.

Pas de restauration automatique ni de restauration BDD. Sauvegarder séparément la BDD avant toute migration. Les dossiers privés de préparation et de sauvegarde sont conservés ; surveiller leur occupation et nettoyer seulement les versions devenues inutiles.

## Vérification
php tests/deploy_finea_test.php

Le test utilise un dépôt et un site temporaires. Il vérifie la simulation, la copie, les sauvegardes, les exclusions, les permissions, les changements concurrents, les liens symboliques, le blocage WhatsApp et l'échec de syntaxe avant toute copie.
