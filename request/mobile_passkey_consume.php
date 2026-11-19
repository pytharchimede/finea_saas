<?php

require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();

try {
    $body = json_decode(passkeyBody(), true, 512, JSON_THROW_ON_ERROR);
    $publicId = (string)($body['public_id'] ?? '');
    $attempt = $mobilePasskeyRepository->desktopStatus($publicId, session_id());
    if (!$attempt || $attempt['status'] !== 'approved' || empty($attempt['user_id'])) {
        passkeyJson(['status'=>'error','message'=>'La validation mobile n’est pas disponible.'], 409);
    }

    $user = (new User($passkeyPdo))->findActiveUserById((int)$attempt['user_id']);
    if (!$user) throw new RuntimeException('Compte indisponible.');

    try {
        $security = $passkeyPdo->prepare('SELECT locked,failed_attempts FROM login_security WHERE user_id=:id LIMIT 1');
        $security->execute(['id'=>(int)$attempt['user_id']]);
        $lock = $security->fetch(PDO::FETCH_ASSOC);
        if ($lock && (!empty($lock['locked']) || (int)$lock['failed_attempts'] >= 3)) throw new RuntimeException('Compte verrouillé.');
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) !== 1146) throw $e;
    }

    $consumed = $mobilePasskeyRepository->consume($publicId, session_id());
    if (!$consumed) passkeyJson(['status'=>'error','message'=>'Cette validation a déjà été utilisée ou a expiré.'], 409);

    $result = AuthSessionService::login($user, $passkeyPdo, 'passkey');
    $_SESSION['passkey_id'] = (int)$consumed['passkey_id'];
    passkeyJson(['status'=>'success','message'=>'Identité validée depuis votre téléphone.','redirect'=>$result['redirect']]);
} catch (Throwable $e) {
    error_log('[mobile_passkey_consume] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Connexion mobile impossible.'], 400);
}
