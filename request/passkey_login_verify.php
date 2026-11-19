<?php
require_once __DIR__ . '/passkey_bootstrap.php';
passkeyRequirePost();
try {
    $entry = passkeyConsumeChallenge('login');
    $credential = $passkeyService->parseCredential(passkeyBody());
    $row = $passkeyRepository->findActiveByCredentialId($credential->rawId);
    if (!$row) throw new RuntimeException('Credential inconnu.');
    $record = $passkeyRepository->deserialize((string)$row['credential_record']);
    $verified = $passkeyService->verifyAuthentication($credential, $record, $passkeyService->restoreAuthenticationOptions($entry['options']));
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
    $passkeyRepository->updateAfterUse((int)$row['id'], $verified);
    $result = AuthSessionService::login($user, $passkeyPdo, 'passkey');
    $_SESSION['passkey_id']=(int)$row['id'];
    passkeyJson(['status'=>'success','message'=>'Identité vérifiée.','redirect'=>$result['redirect']]);
} catch (Throwable $e) {
    error_log('[passkey_login] '.$e->getMessage());
    passkeyJson(['status'=>'error','message'=>'Connexion Passkey impossible.'], 400);
}
