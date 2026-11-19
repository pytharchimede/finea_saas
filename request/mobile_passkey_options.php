<?php

require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();

try {
    $body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    $token = (string)($body['token'] ?? '');
    $attempt = $mobilePasskeyRepository->byMobileToken($token);
    if (!$attempt || $attempt['status'] !== 'pending') {
        passkeyJson(['status'=>'error','message'=>'Ce QR code a expiré ou a déjà été utilisé.'], 410);
    }

    $options = $passkeyService->authenticationOptions();
    $json = $passkeyService->optionsJson($options);
    if (!$mobilePasskeyRepository->storeChallenge((int)$attempt['id'], $json)) {
        passkeyJson(['status'=>'error','message'=>'Cette demande de connexion n’est plus disponible.'], 409);
    }

    echo $json;
} catch (Throwable $e) {
    error_log('[mobile_passkey_options] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Impossible de préparer la vérification sur ce téléphone.'], 400);
}
