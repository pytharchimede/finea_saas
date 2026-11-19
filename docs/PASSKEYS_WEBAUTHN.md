# Passkeys WebAuthn dans FINEA

## Architecture

Le login historique reste `login.php` → `request/login_handler.php`. Les connexions par mot de passe et par Passkey appellent toutes deux `AuthSessionService::login()`, qui régénère l'identifiant de session, charge les colonnes utilisateur, les permissions, le compte mail et l'historique de connexion, puis conserve la redirection officielle.

Les endpoints WebAuthn procéduraux sont dans `request/passkey_*`. `PasskeyService` délègue le CBOR, COSE, les signatures, l'attestation et l'assertion à `web-auth/webauthn-lib`. `PasskeyRepository` persiste plusieurs credentials par utilisateur dans `user_passkeys`. Aucune donnée biométrique n'est reçue ni stockée.

Les challenges de 32 octets sont stockés en session, expirent après cinq minutes et sont supprimés avant toute tentative de vérification. L'Origin complet et le RP ID sont validés par la bibliothèque. La vérification utilisateur et les credentials découvrables sont obligatoires. Aucun `authenticatorAttachment` n'est fixé. Le hint WebAuthn `hybrid` est placé en première position afin que les navigateurs compatibles privilégient le smartphone et le QR code système, tout en conservant Windows Hello, les Passkeys locales et les clés de sécurité comme solutions de repli.

## Prérequis et déploiement

PHP 8.2 ou plus récent avec JSON et OpenSSL est requis.

```bash
composer install --no-dev --optimize-autoloader
```

La table `user_passkeys` est créée automatiquement et de façon idempotente avec la connexion PDO déjà configurée de FINEA dès le premier accès au profil ou à un endpoint Passkey. Aucune commande MySQL et aucune saisie de mot de passe ne sont nécessaires. Le fichier SQL versionné reste une référence et un secours facultatif.

Configurer en production :

```dotenv
WEBAUTHN_RP_ID=projet.fidest.ci
WEBAUTHN_RP_NAME=FINEA
WEBAUTHN_ORIGIN=https://projet.fidest.ci
```

`WEBAUTHN_ORIGIN` est une origine exacte (schéma + hôte + port éventuel). `WEBAUTHN_RP_ID` est uniquement le nom de domaine. En dehors de `localhost`, HTTP est refusé.

## Test local

Utiliser par exemple `http://localhost` avec `WEBAUTHN_RP_ID=localhost` et `WEBAUTHN_ORIGIN=http://localhost`. Se connecter par mot de passe et ouvrir `profil.php` : la table est créée automatiquement. Ajouter ensuite une Passkey, se déconnecter, puis utiliser le bouton Passkey de `login.php`.

```bash
php tests/PasskeySecurityTest.php
find config services repositories request tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Test production et cross-device

Sur `https://projet.fidest.ci`, un utilisateur connecté par mot de passe ouvre son profil et ajoute une Passkey. Après déconnexion, sur le PC il choisit « Se connecter avec une Passkey », puis l'option proposée par Chrome, Edge ou Safari pour utiliser un autre appareil. Le navigateur affiche son propre QR code. Le smartphone le scanne et effectue sa vérification locale (biométrie, PIN ou verrouillage). Le navigateur remet ensuite l'assertion signée à FINEA; FINEA retrouve le credential et l'utilisateur actif, valide cryptographiquement l'assertion, puis crée la session normale.

Le libellé et la disponibilité de l'option cross-device varient selon le navigateur, le système, Bluetooth et la synchronisation de Passkeys. FINEA ne génère jamais ce QR code. Windows Hello et les authenticators locaux restent utilisables. Le mot de passe demeure le mécanisme de récupération en cas de perte ou suppression d'une Passkey.
