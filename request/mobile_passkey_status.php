<?php

require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();

try {
    $body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    $publicId = (string)($body['public_id'] ?? '');
    $attempt = $mobilePasskeyRepository->desktopStatus($publicId, session_id());
    if (!$attempt) passkeyJson(['status'=>'error','message'=>'Demande de connexion introuvable.'], 404);

    passkeyJson([
        'status'=>'success',
        'login_status'=>(string)$attempt['status'],
    ]);
} catch (Throwable $e) {
    error_log('[mobile_passkey_status] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Impossible de vérifier la connexion.'], 400);
}
