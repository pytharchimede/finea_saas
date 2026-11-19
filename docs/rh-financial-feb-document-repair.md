# Justificatifs et lignes des FEB RH

Les validations Passkey RH et DG conservent désormais une copie des octets de la signature de profil dans le récapitulatif signé, avec identité, date et empreinte SHA-256. Les signatures dessinées manuellement restent prioritaires. Si aucun profil exploitable n'existe, le PDF le signale ; aucune signature n'est inventée.

Chaque demande autorisée par le DG devient une ligne `besoins` de la FEB : quantité 1, montant final autorisé comme prix unitaire, bénéficiaire et demande dans le libellé, identifiant de source conservé. Les demandes reportées, refusées et annulées ne sont pas payables dans cette FEB. Le point signé constitue le justificatif commun des lignes.

Le téléchargement `request/download_rh_financial_point.php?point_id=ID&revision=VERSION` exige une session et un compte actif. Il autorise les administrateurs, RH, le DG signataire, le demandeur de la FEB ou les validateurs Finance disposant des droits du service concerné. Il vérifie le rattachement et l'empreinte de la version signée, puis lit uniquement les archives dans `uploads/rh_financial_points`.

## Rattrapage des FEB existantes

Après déploiement du code, exécuter depuis le site de production :

```bash
/usr/local/bin/php /home/fidestci/projet.fidest.ci/scripts/repair_rh_financial_feb_documents.php --list-admins
/usr/local/bin/php /home/fidestci/projet.fidest.ci/scripts/repair_rh_financial_feb_documents.php --actor=ID_ADMIN
/usr/local/bin/php /home/fidestci/projet.fidest.ci/scripts/repair_rh_financial_feb_documents.php --actor=ID_ADMIN --apply
```

`--admin-email=EMAIL` peut remplacer `--actor`. `--point=1` limite le traitement à un point. Sans `--apply`, aucune écriture n'est effectuée. Le compte choisi doit être actif et administrateur.

Le rattrapage traite les FEB liées à la version actuelle d'un point signé par le DG, y compris les FEB déjà approuvées/décaissées. Il ajoute les lignes et les liens de justificatif sans modifier montants, décisions, statut FEB, budget, fiches ou paiements. Il sauvegarde l'ancien payload dans l'audit `feb_documents_repaired`. Une réexécution est sans effet pour les dossiers déjà réparés.

Les anciens profils non capturés lors de la validation sont enregistrés séparément dans l'audit `profile_signatures_backfilled`. La copie PDF indique leur ajout ultérieur et sa date. Le snapshot signé, son empreinte et l'archive originale ne sont jamais réécrits. `original=1` sur le téléchargement donne accès à cet original ; la pièce courante peut être une copie de consultation enrichie.

Un original manquant ou un dossier incohérent est signalé et laissé intact. `missing_profiles` indique les signatures de profil indisponibles : les lignes et justificatifs sont quand même réparés. Enregistrer ensuite les signatures de profil et relancer permet de compléter ces dossiers. Les signatures déjà capturées ne sont jamais rechargées depuis un profil modifié.

## Vérification

```bash
php tests/rh_financial_feb_documents_test.php
```

Ce test utilise une base simulée et une arborescence temporaire. Il contrôle les nouvelles signatures Passkey RH/DG, les lignes retenues et montants, la simulation, le rattrapage, la réexécution, l'intégrité, le rollback et les droits Finance de téléchargement. Aucun accès à la base de production et aucune écriture dans ses uploads.
