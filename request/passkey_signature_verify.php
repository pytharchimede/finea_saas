<?php
require_once __DIR__.'/passkey_bootstrap.php';
passkeyRequirePost();
require_once __DIR__.'/../auth_check.php';
require_once __DIR__.'/../services/PasskeySignatureGuard.php';

try {
    if (empty($_SESSION['user_id'])) {
        passkeyJson(['status'=>'error','message'=>'Authentification requise.'],401);
    }

    $body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($body)) {
        throw new RuntimeException('Requête invalide.');
    }
    $entry = passkeyConsumeChallenge('signature');
    if ((int)($entry['user_id'] ?? 0) !== (int)$_SESSION['user_id']) {
        throw new RuntimeException('Session invalide.');
    }

    $requestedContext = \PasskeySignatureGuard::normalizeContext((string)($body['context'] ?? ''));
    $challengeContext = \PasskeySignatureGuard::normalizeContext((string)($entry['context'] ?? ''));
    if (!hash_equals($challengeContext, $requestedContext)) {
        throw new RuntimeException('Le contexte de signature a changé. Recommencez la confirmation.');
    }

    $credentialPayload = isset($body['credential']) && is_array($body['credential']) ? $body['credential'] : $body;
    $credential = $passkeyService->parseCredential(json_encode($credentialPayload, JSON_THROW_ON_ERROR));
    $row = $passkeyRepository->findActiveByCredentialId($credential->rawId);
    if (!$row || (int)$row['user_id'] !== (int)$_SESSION['user_id']) {
        throw new RuntimeException('Passkey non autorisée.');
    }

    $verified = $passkeyService->verifyAuthentication(
        $credential,
        $passkeyRepository->deserialize((string)$row['credential_record']),
        $passkeyService->restoreAuthenticationOptions($entry['options'])
    );
    $passkeyRepository->updateAfterUse((int)$row['id'],$verified);

    $_SESSION['passkey_signature_proof'] = [
        'user_id' => (int)$_SESSION['user_id'],
        'context' => $challengeContext,
        'credential_id' => (int)$row['id'],
        'verified_at' => time(),
        'expires_at' => time()+120,
    ];

    AuditTrail::log('PASSKEY_SIGNATURE_VERIFIED',[
        'module'=>'security',
        'action'=>'passkey_signature_verified',
        'user_id'=>(int)$_SESSION['user_id'],
        'result'=>'success',
        'details'=>['context'=>$challengeContext,'credential_id'=>(int)$row['id']],
    ]);
    passkeyJson(['status'=>'success','message'=>'Identité confirmée. La signature peut être enregistrée.']);
} catch (Throwable $e) {
    error_log('[passkey_signature] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'La confirmation Passkey a échoué.'],400);
}

