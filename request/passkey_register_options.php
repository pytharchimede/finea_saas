<?php
require_once __DIR__ . '/passkey_bootstrap.php';
require_once __DIR__ . '/../auth_check.php';
passkeyRequirePost();
if (empty($_SESSION['user_id'])) passkeyJson(['status'=>'error','message'=>'Authentification requise.'], 401);

$payload = json_decode(passkeyBody(), true);
$csrf = (string) ($payload['csrf_token'] ?? '');
if (empty($_SESSION['passkey_csrf']) || !hash_equals((string) $_SESSION['passkey_csrf'], $csrf)) {
    passkeyJson(['status'=>'error','message'=>'Session expirée. Rechargez la page.'], 403);
}
$user = (new User($passkeyPdo))->getUserById((int) $_SESSION['user_id']);
if (!$user || empty($user['active'])) passkeyJson(['status'=>'error','message'=>'Compte indisponible.'], 403);

$userId = (int) $user['id'];
$existing = $passkeyRepository->activeRecordsForUser($userId);
$authorizationNonce = null;

// Dès qu'une première Passkey existe, une simple session PHP ne suffit plus
// pour en enrôler une autre. Une Passkey existante doit avoir autorisé l'opération.
if ($existing) {
    $authorization = $_SESSION['passkey_enrollment_authorization'] ?? null;
    $validAuthorization = is_array($authorization)
        && (int) ($authorization['user_id'] ?? 0) === $userId
        && (int) ($authorization['expires_at'] ?? 0) >= time()
        && !empty($authorization['nonce']);

    if (!$validAuthorization) {
        unset($_SESSION['passkey_enrollment_authorization']);
        passkeyJson([
            'status' => 'error',
            'code' => 'passkey_reauth_required',
            'message' => 'Confirmez d’abord votre identité avec une Passkey déjà enregistrée.',
        ], 403);
    }
    $authorizationNonce = (string) $authorization['nonce'];
}

$handle = $passkeyRepository->getOrCreateUserHandle($userId);
$options = $passkeyService->registrationOptions($user, $handle, $existing);
$_SESSION['webauthn_challenge'] = [
    'purpose' => 'register',
    'user_id' => $userId,
    'options' => $passkeyService->optionsJson($options),
    'authorization_required' => (bool) $existing,
    'authorization_nonce' => $authorizationNonce,
    'expires_at' => time() + $passkeyConfig['challenge_ttl'],
];
echo $passkeyService->optionsJson($options);
