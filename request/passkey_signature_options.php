<?php
require_once __DIR__.'/passkey_bootstrap.php';
passkeyRequirePost();
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/PasskeySignatureGuard.php';

if (empty($_SESSION['user_id'])) {
    passkeyJson(['status'=>'error','message'=>'Authentification requise.'],401);
}
$records = $passkeyRepository->activeRecordsForUser((int)$_SESSION['user_id']);
if (!$records) {
    passkeyJson(['status'=>'error','code'=>'passkey_setup_required','message'=>'Créez d’abord une Passkey dans votre profil.'],409);
}

$body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
$context = \PasskeySignatureGuard::normalizeContext((string)($body['context'] ?? ''));
$options = $passkeyService->authenticationOptions($records);
$_SESSION['webauthn_challenge'] = [
    'purpose' => 'signature',
    'user_id' => (int)$_SESSION['user_id'],
    'context' => $context,
    'options' => $passkeyService->optionsJson($options),
    'expires_at' => time() + $passkeyConfig['challenge_ttl'],
];
echo $passkeyService->optionsJson($options);
