<?php

require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();

try {
    $body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    $token = (string)($body['token'] ?? '');
    $credentialPayload = $body['credential'] ?? null;
    if (!is_array($credentialPayload)) throw new RuntimeException('Credential absent.');

    $attempt = $mobilePasskeyRepository->takeChallenge($token);
    if (!$attempt) throw new RuntimeException('La demande a expiré. Veuillez rescanner le QR code.');

    $credential = $passkeyService->parseCredential(json_encode($credentialPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $row = $passkeyRepository->findActiveByCredentialId($credential->rawId);
    if (!$row) throw new RuntimeException('Passkey inconnue.');

    $record = $passkeyRepository->deserialize((string)$row['credential_record']);
    $options = $passkeyService->restoreAuthenticationOptions((string)$attempt['challenge_options']);
    $verified = $passkeyService->verifyAuthentication($credential, $record, $options);

    $user = (new User($passkeyPdo))->findActiveUserById((int)$row['user_id']);
    if (!$user) throw new RuntimeException('Compte indisponible.');

    try {
        $security = $passkeyPdo->prepare('SELECT locked,failed_attempts FROM login_security WHERE user_id=:id LIMIT 1');
        $security->execute(['id'=>(int)$row['user_id']]);
        $lock = $security->fetch(PDO::FETCH_ASSOC);
        if ($lock && (!empty($lock['locked']) || (int)$lock['failed_attempts'] >= 3)) throw new RuntimeException('Compte verrouillé.');
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1146) throw $e;
    }

    $deviceToken=(string)($body['device_token']??'');
    $deviceLabel=(string)($body['device_label']??'Téléphone');
    if(!$mobilePasskeyRepository->authorizeScanner((int)$row['user_id'],(int)$row['id'],$deviceToken,$deviceLabel,(string)($_SERVER['HTTP_USER_AGENT']??''),$_SERVER['REMOTE_ADDR']??null)){
        throw new RuntimeException('Ce téléphone a été révoqué. Utilisez un autre appareil autorisé.');
    }
    $passkeyRepository->updateAfterUse((int)$row['id'], $verified);
    if (!$mobilePasskeyRepository->approve((int)$attempt['id'], (int)$row['user_id'], (int)$row['id'])) {
        throw new RuntimeException('Cette demande a expiré avant validation.');
    }

    passkeyJson(['status'=>'success','message'=>'Identité confirmée. Vous pouvez revenir sur l’ordinateur.']);
} catch (Throwable $e) {
    error_log('[mobile_passkey_verify] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'La vérification Passkey a échoué.'], 400);
}
