# Reprise IT du module Emails

## Positionnement

Le module Emails est une integration IMAP/boite mail personnelle dans l ERP. Il ne s agit pas d un simple journal d envoi systeme, mais d une consultation de messagerie utilisateur.

## Fichiers cles

- emails/mailbox_emails.php
- emails/view_email.php
- emails/compose_email.php
- emails/api_mailbox_emails.php
- emails/api_mailbox_folders.php
- emails/api_mailbox_folder_create.php
- emails/api_mailbox_unread_count.php
- emails/download_email_attachment.php
- emails/logout_mailbox.php
- model/MailPasswordStore.php
- decaissement/model/EmailFetcher.php

## Fonctionnement general

mailbox_emails.php :

- utilise mail_pro en session ;
- recharge le mot de passe de messagerie depuis le stockage dedie si possible ;
- permet la saisie manuelle pour la session ;
- interroge les dossiers et les emails via API internes ;
- journalise la consultation.

## Distinction importante

- emails/mailbox_* : messagerie utilisateur ;
- admin/email_logs.php : historique des emails envoyes par le systeme.

## Risques de reprise

- confondre authentification ERP et authentification mail ;
- casser le stockage du mot de passe de boite ;
- mal gerer les dossiers IMAP ;
- ne pas tester les pieces jointes.

## Reprise recommandee

1. tester connexion mail ;
2. tester lecture INBOX ;
3. tester non lus ;
4. tester liste des dossiers ;
5. tester ouverture d un email et d une piece jointe ;
6. tester deconnexion.