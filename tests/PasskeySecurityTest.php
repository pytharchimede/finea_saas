<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/PasskeyService.php';

use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

function assertPasskey(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = ['rp_id'=>'localhost','rp_name'=>'FINEA','origin'=>'http://localhost','challenge_ttl'=>300,'timeout_ms'=>120000];
$service = new PasskeyService($config);
$user = ['mail_pro'=>'test@example.test','prenom'=>'Test','nom'=>'FINEA'];
$handle = random_bytes(32);

$first = $service->registrationOptions($user, $handle, []);
$second = $service->registrationOptions($user, $handle, []);
assertPasskey($first->challenge !== $second->challenge, 'Chaque challenge doit être unique.');
assertPasskey(strlen($first->challenge) === 32, 'Le challenge doit contenir 32 octets aléatoires.');
assertPasskey($first->rp->id === 'localhost', 'Le RP ID configuré doit être utilisé.');
assertPasskey($first->authenticatorSelection?->residentKey === 'required', 'Les credentials doivent être découvrables.');
assertPasskey($first->authenticatorSelection?->userVerification === 'required', 'La vérification utilisateur doit être requise.');
assertPasskey($first->authenticatorSelection?->authenticatorAttachment === null, 'Aucun authenticatorAttachment ne doit bloquer le cross-device.');
assertPasskey($first->hints[0] === 'client-device', 'L’enregistrement doit proposer en priorité le verrouillage local, sans dépendre d’un compte Gmail.');

$login = $service->authenticationOptions();
assertPasskey($login->rpId === 'localhost', 'Le RP ID doit être présent dans les options de connexion.');
assertPasskey($login->userVerification === 'required', 'La connexion doit exiger userVerification.');
assertPasskey($login->allowCredentials === [], 'La connexion doit accepter les credentials découvrables.');
assertPasskey($login->hints[0] === 'client-device', 'La connexion doit proposer en priorité la Passkey locale à l’appareil.');

$restored = $service->restoreAuthenticationOptions($service->optionsJson($login));
assertPasskey(hash_equals($login->challenge, $restored->challenge), 'Le challenge sérialisé doit être restauré sans perte.');

$record = CredentialRecord::create('credential-id', 'public-key', ['hybrid'], 'none', EmptyTrustPath::create(), Uuid::v4(), 'public-key-bytes', $handle, 0, null, true, true, true);
$recordRestored = $service->serializer()->deserialize($service->serializer()->serialize($record, 'json'), CredentialRecord::class, 'json');
assertPasskey(hash_equals($record->publicKeyCredentialId, $recordRestored->publicKeyCredentialId), 'Le credential persistant doit être restauré sans perte.');
assertPasskey(hash_equals($record->userHandle, $recordRestored->userHandle), 'Le userHandle opaque doit être restauré sans perte.');

$bootstrap = file_get_contents(__DIR__ . '/../request/passkey_bootstrap.php');
assertPasskey(str_contains($bootstrap, "unset(\$_SESSION['webauthn_challenge'])"), 'Le challenge doit être consommé avant vérification.');
assertPasskey(str_contains($bootstrap, "expires_at"), 'Le challenge doit avoir une expiration.');

$loginScript = file_get_contents(__DIR__ . '/../js/passkeys.js');
assertPasskey(str_contains($loginScript, 'activatePasskeyOnlyLogin'), 'L’annulation doit basculer vers l’interface de connexion exclusivement Passkey.');
assertPasskey(str_contains($loginScript, 'Votre compte accepte uniquement une Passkey'), 'Le mode sécurisé doit expliquer clairement la restriction de connexion.');
assertPasskey(str_contains($loginScript, 'use-another-account'), 'Le mode Passkey doit permettre de choisir un autre compte.');
assertPasskey(str_contains($loginScript, 'Ce navigateur ne prend pas en charge'), 'Le fallback navigateur doit être présent.');
assertPasskey(!str_contains($loginScript, 'window.prompt'), 'La création ne doit pas imposer la saisie manuelle du nom de l’appareil.');
assertPasskey(str_contains($loginScript, 'dataset.endpointBase'), 'Le composant Passkey doit fonctionner depuis les profils racine et decaissement.');

$activeProfile = file_get_contents(__DIR__ . '/../decaissement/profil.php');
assertPasskey(str_contains($activeProfile, 'id="passkey-register-button"'), 'Le profil decaissement actif doit afficher la création de Passkey.');
assertPasskey(str_contains($activeProfile, 'data-endpoint-base="../request"'), 'Le profil decaissement doit appeler les endpoints WebAuthn partagés.');
assertPasskey(str_contains($activeProfile, 'account-security-wide'), 'La sécurité du compte doit disposer de sa ligne pleine largeur avant les préférences.');
assertPasskey(str_contains($activeProfile, 'passkey-only-toggle'), 'L’utilisateur doit pouvoir activer le mode Passkey uniquement.');
assertPasskey(str_contains($activeProfile, 'Identifiant :'), 'Les détails des appareils doivent afficher un identifiant sûr.');
assertPasskey(str_contains($activeProfile, 'Mes appareils approuvés'), 'Le profil doit lister clairement les appareils approuvés et révocables.');

$repository = file_get_contents(__DIR__ . '/../repositories/PasskeyRepository.php');
assertPasskey(str_contains($repository, 'CREATE TABLE IF NOT EXISTS user_passkeys'), 'La migration Passkey doit être automatique et idempotente.');
assertPasskey(str_contains($repository, 'revoked_at IS NULL'), 'Un credential révoqué ne doit plus être utilisable.');
assertPasskey(str_contains($repository, 'user_id=:user_id'), 'Les Passkeys doivent rester rattachées à leur utilisateur.');

$registerEndpoint = file_get_contents(__DIR__ . '/../request/passkey_register_options.php');
assertPasskey(str_contains($registerEndpoint, "empty(\$_SESSION['user_id'])"), 'L’enregistrement doit être interdit sans session.');
assertPasskey(str_contains($registerEndpoint, 'passkey_enrollment_authorization'), 'Une nouvelle Passkey doit exiger une autorisation step-up quand une Passkey existe déjà.');
assertPasskey(str_contains($registerEndpoint, 'authorization_nonce'), 'Le challenge d’enregistrement doit être lié à la preuve step-up qui l’a autorisé.');

$enrollmentOptions = file_get_contents(__DIR__ . '/../request/passkey_enrollment_authorize_options.php');
assertPasskey(str_contains($enrollmentOptions, "'purpose' => 'enrollment_authorize'"), 'L’autorisation d’enrôlement doit avoir un challenge WebAuthn dédié.');
assertPasskey(str_contains($enrollmentOptions, 'activeRecordsForUser'), 'La confirmation doit être limitée aux Passkeys actives du compte.');
$enrollmentVerify = file_get_contents(__DIR__ . '/../request/passkey_enrollment_authorize_verify.php');
assertPasskey(str_contains($enrollmentVerify, 'verifyAuthentication'), 'La preuve d’enrôlement doit être vérifiée cryptographiquement côté serveur.');
assertPasskey(str_contains($enrollmentVerify, "'expires_at' => time() + 180"), 'La preuve step-up doit expirer rapidement.');
$registerVerify = file_get_contents(__DIR__ . '/../request/passkey_register_verify.php');
assertPasskey(str_contains($registerVerify, "unset(\$_SESSION['passkey_enrollment_authorization'])"), 'La preuve step-up doit être consommée après l’enregistrement.');
assertPasskey(str_contains($registerVerify, 'hash_equals'), 'Le nonce du challenge doit correspondre à la preuve step-up courante.');

$secureEnrollmentClient = file_get_contents(__DIR__ . '/../js/passkey-secure-enrollment.js');
assertPasskey(str_contains($secureEnrollmentClient, 'passkey_enrollment_authorize_options.php'), 'Le navigateur doit demander la validation d’une Passkey existante avant le nouvel enrôlement.');
assertPasskey(str_contains($secureEnrollmentClient, 'navigator.credentials.get'), 'Le step-up doit utiliser une assertion WebAuthn.');
assertPasskey(str_contains($secureEnrollmentClient, 'stopImmediatePropagation'), 'Le flux sécurisé doit neutraliser l’ancien enrôlement direct.');

$loginEndpoint = file_get_contents(__DIR__ . '/../request/passkey_login_verify.php');
assertPasskey(str_contains($loginEndpoint, 'findActiveUserById'), 'Un utilisateur inactif ou sorti doit être rejeté.');
assertPasskey(str_contains($loginEndpoint, "AuthSessionService::login"), 'Une assertion valide doit utiliser la session officielle commune.');

$passwordLogin = file_get_contents(__DIR__ . '/../request/login_handler.php');
assertPasskey(str_contains($passwordLogin, "'passkey_required'"), 'Le mot de passe doit être refusé lorsqu’une politique Passkey-only est active.');
assertPasskey(str_contains($passwordLogin, 'passkey_setup_required'), 'La première connexion doit imposer la création de la Passkey absente.');

$signatureGuard = file_get_contents(__DIR__ . '/../services/PasskeySignatureGuard.php');
assertPasskey(str_contains($signatureGuard, "unset(\$_SESSION['passkey_signature_proof'])"), 'Une preuve Passkey de signature doit être consommée une seule fois.');
assertPasskey(str_contains($signatureGuard, 'revoked_at IS NULL'), 'Une preuve de signature déjà délivrée doit être refusée si sa Passkey est ensuite révoquée.');
$signatureClient = file_get_contents(__DIR__ . '/../js/passkeys.js');
assertPasskey(str_contains($signatureClient, 'passkey_signature_options.php'), 'Le navigateur doit demander une assertion dédiée avant signature.');
$contractSignature = file_get_contents(__DIR__ . '/../contrats/workflow.php');
assertPasskey(str_contains($contractSignature, 'PasskeySignatureGuard::requireVerified'), 'Les signatures de contrats doivent être protégées côté serveur.');
$missionSignature = file_get_contents(__DIR__ . '/../rh/ordre_mission_rh.php');
assertPasskey(str_contains($missionSignature, 'PasskeySignatureGuard::requireVerified'), 'Les signatures des ordres de mission doivent être protégées côté serveur.');

$authCheck = file_get_contents(__DIR__ . '/../auth_check.php');
assertPasskey(str_contains($authCheck, 'auth_policy_version'), 'Chaque page doit invalider les sessions antérieures à une activation Passkey-only.');
assertPasskey(str_contains($authCheck, 'passkey_id'), 'Chaque page doit invalider une session ouverte avec un appareil révoqué.');
$footer = file_get_contents(__DIR__ . '/../decaissement/includes/footer.php');
assertPasskey(str_contains($footer, 'auth_session_status.php'), 'Une page déjà ouverte doit contrôler périodiquement si sa session a été révoquée.');
assertPasskey(str_contains($footer, 'setInterval(check,15000)'), 'Un appareil révoqué doit être redirigé sous 15 secondes même sans activité.');
assertPasskey(str_contains($footer, 'passkey-secure-enrollment.js'), 'Le garde d’enrôlement sécurisé doit être chargé après le client Passkey historique.');
$activeProfile = file_get_contents(__DIR__ . '/../decaissement/profil.php');
assertPasskey(str_contains($activeProfile, 'Appareils actuellement connectés'), 'Le profil doit distinguer chaque appareil connecté des Passkeys synchronisées.');
$deviceSessionService = file_get_contents(__DIR__ . '/../services/AuthDeviceSessionService.php');
assertPasskey(str_contains($deviceSessionService, 'user_device_sessions'), 'Les connexions par appareil doivent être enregistrées côté serveur.');
assertPasskey(str_contains($deviceSessionService, 'revokeAllExcept'), 'Toutes les autres sessions doivent pouvoir être révoquées en une seule action.');
$deviceSessionEndpoint = file_get_contents(__DIR__ . '/../request/device_session_revoke.php');
assertPasskey(str_contains($deviceSessionEndpoint, "'revoke_others'"), 'L’endpoint doit prendre en charge la déconnexion de tous les autres appareils.');
assertPasskey(str_contains($activeProfile, 'device-sessions-revoke-others'), 'Le profil doit proposer une action de déconnexion globale des autres appareils.');
assertPasskey(str_contains($activeProfile, 'Téléphones autorisés à scanner'), 'Le profil doit distinguer les téléphones de validation QR des Passkeys et des sessions.');
$mobileScannerEndpoint = file_get_contents(__DIR__ . '/../request/mobile_scanner_revoke.php');
assertPasskey(str_contains($mobileScannerEndpoint, 'revokeScanner'), 'Un téléphone de validation QR doit pouvoir être révoqué depuis le profil.');

echo "PasskeySecurityTest: OK\n";
