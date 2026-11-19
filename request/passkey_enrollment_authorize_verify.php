<?php
require_once __DIR__ . '/passkey_bootstrap.php';
require_once __DIR__ . '/../auth_check.php';

passkeyRequirePost();

try {
    if (empty($_SESSION['user_id'])) {
        passkeyJson(['status' => 'error', 'message' => 'Authentification requise.'], 401);
    }

    $payload = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!hash_equals((string) ($_SESSION['passkey_csrf'] ?? ''), (string) ($payload['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session expirée.');
    }

    $entry = passkeyConsumeChallenge('enrollment_authorize');
    $userId = (int) $_SESSION['user_id'];
    if ((int) ($entry['user_id'] ?? 0) !== $userId) {
        throw new RuntimeException('Session invalide.');
    }

    if (empty($payload['credential']) || !is_array($payload['credential'])) {
        throw new RuntimeException('Réponse Passkey manquante.');
    }

    $credential = $passkeyService->parseCredential(json_encode($payload['credential'], JSON_THROW_ON_ERROR));
    $row = $passkeyRepository->findActiveByCredentialId($credential->rawId);
    if (!$row || (int) $row['user_id'] !== $userId) {
        throw new RuntimeException('Cette Passkey ne fait pas partie de votre compte.');
    }

    $verified = $passkeyService->verifyAuthentication(
        $credential,
        $passkeyRepository->deserialize((string) $row['credential_record']),
        $passkeyService->restoreAuthenticationOptions((string) $entry['options'])
    );
    $passkeyRepository->updateAfterUse((int) $row['id'], $verified);

    $nonce = bin2hex(random_bytes(24));
    $_SESSION['passkey_enrollment_authorization'] = [
        'user_id' => $userId,
        'credential_id' => (int) $row['id'],
        'nonce' => $nonce,
        'verified_at' => time(),
        'expires_at' => time() + 180,
    ];

    AuditTrail::log('PASSKEY_ENROLLMENT_AUTHORIZED', [
        'module' => 'security',
        'action' => 'passkey_enrollment_authorized',
        'user_id' => $userId,
        'entity_type' => 'passkey',
        'result' => 'success',
        'details' => ['credential_id' => (int) $row['id']],
    ]);

    passkeyJson([
        'status' => 'success',
        'message' => 'Identité confirmée. Vous pouvez ajouter la nouvelle Passkey.',
        'expires_in' => 180,
    ]);
} catch (Throwable $e) {
    unset($_SESSION['passkey_enrollment_authorization']);
    error_log('[passkey_enrollment_authorize_verify] ' . $e->getMessage());
    passkeyJson(['status' => 'error', 'message' => 'La confirmation avec votre Passkey existante a échoué.'], 400);
}
