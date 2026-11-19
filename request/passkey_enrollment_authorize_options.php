<?php
require_once __DIR__ . '/passkey_bootstrap.php';
require_once __DIR__ . '/../auth_check.php';

passkeyRequirePost();

if (empty($_SESSION['user_id'])) {
    passkeyJson(['status' => 'error', 'message' => 'Authentification requise.'], 401);
}

try {
    $payload = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    $csrf = (string) ($payload['csrf_token'] ?? '');
    if (empty($_SESSION['passkey_csrf']) || !hash_equals((string) $_SESSION['passkey_csrf'], $csrf)) {
        passkeyJson(['status' => 'error', 'message' => 'Session expirée. Rechargez la page.'], 403);
    }

    $userId = (int) $_SESSION['user_id'];
    $records = $passkeyRepository->activeRecordsForUser($userId);

    // Le tout premier enrôlement est le bootstrap du compte : aucune Passkey
    // existante ne peut encore servir de preuve de possession.
    if (!$records) {
        unset($_SESSION['passkey_enrollment_authorization']);
        passkeyJson([
            'status' => 'success',
            'authorization_required' => false,
        ]);
    }

    $options = $passkeyService->authenticationOptions($records);
    $_SESSION['webauthn_challenge'] = [
        'purpose' => 'enrollment_authorize',
        'user_id' => $userId,
        'options' => $passkeyService->optionsJson($options),
        'expires_at' => time() + $passkeyConfig['challenge_ttl'],
    ];

    passkeyJson([
        'status' => 'success',
        'authorization_required' => true,
        'publicKey' => json_decode($passkeyService->optionsJson($options), true, 512, JSON_THROW_ON_ERROR),
        'message' => 'Confirmez votre identité avec une Passkey déjà enregistrée.',
    ]);
} catch (Throwable $e) {
    error_log('[passkey_enrollment_authorize_options] ' . $e->getMessage());
    passkeyJson(['status' => 'error', 'message' => 'Impossible de préparer la confirmation Passkey.'], 400);
}
