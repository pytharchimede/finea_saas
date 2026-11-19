<?php

declare(strict_types=1);

function assertMobileQr(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$repo = file_get_contents(__DIR__.'/../repositories/MobilePasskeyLoginRepository.php');
$start = file_get_contents(__DIR__.'/../request/mobile_passkey_start.php');
$options = file_get_contents(__DIR__.'/../request/mobile_passkey_options.php');
$verify = file_get_contents(__DIR__.'/../request/mobile_passkey_verify.php');
$consume = file_get_contents(__DIR__.'/../request/mobile_passkey_consume.php');
$desktop = file_get_contents(__DIR__.'/../js/mobile-qr-login.js');
$mobile = file_get_contents(__DIR__.'/../js/mobile-passkey-auth.js');
$login = file_get_contents(__DIR__.'/../login.php');
$service = file_get_contents(__DIR__.'/../services/PasskeyService.php');

assertMobileQr(str_contains($repo, 'random_bytes(32)'), 'Le jeton QR doit contenir 256 bits aléatoires.');
assertMobileQr(str_contains($repo, "hash('sha256',\$token,true)"), 'Le jeton QR ne doit pas être stocké en clair.');
assertMobileQr(str_contains($repo, "hash('sha256',\$desktopSessionId,true)"), 'La demande doit être liée à la session du PC.');
assertMobileQr(str_contains($repo, 'FOR UPDATE'), 'La consommation doit être atomique.');
assertMobileQr(str_contains($repo, "status='consumed'"), 'Une validation doit être consommée une seule fois.');
assertMobileQr(str_contains($repo, 'expires_at'), 'Le QR code doit expirer.');
assertMobileQr(str_contains($start, 'SvgWriter'), 'Le QR code doit être généré côté serveur sans dépendance GD.');
assertMobileQr(str_contains($options, 'storeChallenge'), 'Le challenge mobile doit être conservé côté serveur.');
assertMobileQr(str_contains($verify, 'verifyAuthentication'), 'Le téléphone doit produire une vraie assertion WebAuthn.');
assertMobileQr(str_contains($verify, 'approve'), 'Le téléphone ne doit qu’approuver la demande du PC.');
assertMobileQr(str_contains($consume, 'AuthSessionService::login'), 'Seul le PC demandeur doit créer sa session FINEA.');
assertMobileQr(str_contains($desktop, 'mobile_passkey_status.php'), 'Le PC doit attendre l’approbation du téléphone.');
assertMobileQr(str_contains($mobile, 'navigator.credentials.get'), 'La biométrie doit être déclenchée localement sur le téléphone.');
assertMobileQr(str_contains($mobile, 'finea_mobile_scanner_id'), 'Le téléphone doit disposer d’un identifiant local opaque et révocable.');
assertMobileQr(str_contains($repo, 'user_mobile_scanners'), 'Les téléphones autorisés doivent être conservés séparément des tentatives QR temporaires.');
assertMobileQr(str_contains($login, 'sans Bluetooth'), 'Le fallback sans Bluetooth doit être explicite.');
assertMobileQr(str_contains($service, 'getPublicKeyCredentialDescriptor'), 'Les transports enregistrés doivent être réutilisables dans allowCredentials.');

echo "MobilePasskeyQrSecurityTest: OK\n";
